<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Checkout_Controller {

    private const NAMESPACE = 'fd-ucp/v1';
    private FD_Payment_Registry $registry;
    private FD_Rate_Limiter $rate_limiter;

    public function __construct( FD_Payment_Registry $registry ) {
        $this->registry     = $registry;
        $this->rate_limiter = new FD_Rate_Limiter( 30, 60 );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/checkout-sessions', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_session' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( $this, 'update_session' ),
                'permission_callback' => '__return_true',
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)/complete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'complete_session' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)/cancel', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'cancel_session' ),
            'permission_callback' => '__return_true',
        ) );
    }

    // =========================================================================
    // Create
    // =========================================================================

    public function create_session( WP_REST_Request $request ): WP_REST_Response {
        $rl = $this->rate_limiter->check( 'checkout_create' );
        if ( is_wp_error( $rl ) ) {
            return FD_UCP_Error::response( $rl->get_error_code(), $rl->get_error_message(), 429 );
        }

        $body       = $request->get_json_params();
        $line_items = $body['line_items'] ?? null;

        if ( ! is_array( $line_items ) || empty( $line_items ) ) {
            return FD_UCP_Error::response( 'missing_line_items', 'line_items array is required', 400 );
        }

        $session_id = wp_generate_uuid4();
        $currency   = get_woocommerce_currency();

        // Validate products and build formatted line items
        $formatted_items = array();
        $cart_subtotal    = 0;

        foreach ( $line_items as $item ) {
            $product_id = (int) ( $item['item']['id'] ?? 0 );
            $quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $product    = wc_get_product( $product_id );

            if ( ! $product || ! $product->is_purchasable() ) {
                return FD_UCP_Error::response( 'invalid_product', "Product $product_id not found or not purchasable", 422 );
            }

            $price     = FD_UCP_Formatter::to_minor( (float) $product->get_price() );
            $item_total = $price * $quantity;
            $cart_subtotal += $item_total;

            $formatted_items[] = array(
                'id'       => 'li_' . ( count( $formatted_items ) + 1 ),
                'item'     => array(
                    'id'    => (string) $product_id,
                    'title' => $product->get_name(),
                    'price' => $price,
                ),
                'quantity' => $quantity,
                'totals'   => array(
                    array( 'type' => 'subtotal', 'amount' => $item_total ),
                    array( 'type' => 'total', 'amount' => $item_total ),
                ),
            );
        }

        $totals = array(
            array( 'type' => 'subtotal', 'amount' => $cart_subtotal ),
            array( 'type' => 'total', 'amount' => $cart_subtotal ),
        );

        // Extract buyer if provided
        $buyer = null;
        if ( ! empty( $body['buyer'] ) ) {
            $buyer = array(
                'email'      => sanitize_email( $body['buyer']['email'] ?? '' ),
                'first_name' => sanitize_text_field( $body['buyer']['first_name'] ?? '' ),
                'last_name'  => sanitize_text_field( $body['buyer']['last_name'] ?? '' ),
            );
        }

        // Extract and process fulfillment if provided
        $fulfillment = null;
        if ( ! empty( $body['fulfillment'] ) ) {
            $fulfillment = $this->process_fulfillment(
                $body['fulfillment'],
                $formatted_items,
                $currency
            );

            $shipping_cost = $this->extract_selected_shipping_cost( $fulfillment );
            if ( $shipping_cost > 0 ) {
                $totals = array(
                    array( 'type' => 'subtotal', 'amount' => $cart_subtotal ),
                    array( 'type' => 'fulfillment', 'amount' => $shipping_cost ),
                    array( 'type' => 'total', 'amount' => $cart_subtotal + $shipping_cost ),
                );
            }
        }

        // Create a pending WC order early so we have an order number for payment descriptions
        $order = $this->create_pending_wc_order( $formatted_items, $buyer, $fulfillment );
        $order_number = is_wp_error( $order ) ? null : $order->get_order_number();
        $order_id     = is_wp_error( $order ) ? null : $order->get_id();

        // Prepare payment handlers
        $checkout_base_url = home_url( '/wp-json/' . self::NAMESPACE );
        $checkout_total    = $this->extract_total( $totals );
        $store_name        = FD_UCP_Plugin::instance()->store_name();

        $prepare_input = array(
            'checkout_id'      => $session_id,
            'total'            => $checkout_total,
            'currency'         => $currency,
            'checkout_base_url' => $checkout_base_url,
            'store_name'       => $store_name,
            'order_label'      => $order_number ? "Order #$order_number" : 'Checkout',
            'checkout_meta'    => null,
        );

        $payment_meta = $this->registry->prepare_all( $prepare_input );

        // Persist session
        $now = current_time( 'mysql', true );
        $this->insert_session( array(
            'id'               => $session_id,
            'status'           => 'incomplete',
            'currency'         => $currency,
            'line_items'       => wp_json_encode( $formatted_items ),
            'totals'           => wp_json_encode( $totals ),
            'buyer'            => $buyer ? wp_json_encode( $buyer ) : null,
            'fulfillment'      => $fulfillment ? wp_json_encode( $fulfillment ) : null,
            'payment_meta'     => wp_json_encode( $payment_meta ),
            'wc_order_id'      => $order_id,
            'agent_fingerprint' => $this->compute_fingerprint( $request ),
            'created_at'       => $now,
            'updated_at'       => $now,
            'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ),
        ) );

        $session = $this->load_session( $session_id );

        return new WP_REST_Response(
            FD_UCP_Formatter::format_checkout_session( $session, $this->registry ),
            201
        );
    }

    // =========================================================================
    // Get
    // =========================================================================

    public function get_session( WP_REST_Request $request ): WP_REST_Response {
        $session = $this->load_session( $request->get_param( 'id' ) );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        return new WP_REST_Response(
            FD_UCP_Formatter::format_checkout_session( $session, $this->registry ),
            200
        );
    }

    // =========================================================================
    // Update
    // =========================================================================

    public function update_session( WP_REST_Request $request ): WP_REST_Response {
        $session = $this->load_session( $request->get_param( 'id' ) );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
        }

        if ( in_array( $session['status'], array( 'canceled', 'completed' ), true ) ) {
            return FD_UCP_Error::response( 'session_' . $session['status'], 'Session has been ' . $session['status'], 409 );
        }

        $body    = $request->get_json_params();
        $updates = array();

        // Update buyer
        if ( ! empty( $body['buyer'] ) ) {
            $existing_buyer = json_decode( $session['buyer'] ?? '{}', true ) ?: array();
            if ( ! empty( $body['buyer']['email'] ) ) {
                $existing_buyer['email'] = sanitize_email( $body['buyer']['email'] );
            }
            if ( ! empty( $body['buyer']['first_name'] ) ) {
                $existing_buyer['first_name'] = sanitize_text_field( $body['buyer']['first_name'] );
            }
            if ( ! empty( $body['buyer']['last_name'] ) ) {
                $existing_buyer['last_name'] = sanitize_text_field( $body['buyer']['last_name'] );
            }
            $updates['buyer'] = wp_json_encode( $existing_buyer );
        }

        // Update fulfillment
        if ( ! empty( $body['fulfillment'] ) ) {
            $line_items_for_shipping = isset( $updates['line_items'] )
                ? json_decode( $updates['line_items'], true )
                : json_decode( $session['line_items'], true );

            $fulfillment = $this->process_fulfillment(
                $body['fulfillment'],
                $line_items_for_shipping,
                $session['currency']
            );
            $updates['fulfillment'] = wp_json_encode( $fulfillment );
        }

        // Update line items
        if ( ! empty( $body['line_items'] ) && is_array( $body['line_items'] ) ) {
            $formatted_items = array();
            $cart_subtotal   = 0;

            foreach ( $body['line_items'] as $item ) {
                $product_id = (int) ( $item['item']['id'] ?? 0 );
                $quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
                $product    = wc_get_product( $product_id );

                if ( ! $product || ! $product->is_purchasable() ) {
                    return FD_UCP_Error::response( 'invalid_product', "Product $product_id not found", 422 );
                }

                $price      = FD_UCP_Formatter::to_minor( (float) $product->get_price() );
                $item_total = $price * $quantity;
                $cart_subtotal += $item_total;

                $formatted_items[] = array(
                    'id'       => $item['id'] ?? ( 'li_' . ( count( $formatted_items ) + 1 ) ),
                    'item'     => array(
                        'id'    => (string) $product_id,
                        'title' => $product->get_name(),
                        'price' => $price,
                    ),
                    'quantity' => $quantity,
                    'totals'   => array(
                        array( 'type' => 'subtotal', 'amount' => $item_total ),
                        array( 'type' => 'total', 'amount' => $item_total ),
                    ),
                );
            }

            $totals = array(
                array( 'type' => 'subtotal', 'amount' => $cart_subtotal ),
                array( 'type' => 'total', 'amount' => $cart_subtotal ),
            );

            $updates['line_items'] = wp_json_encode( $formatted_items );
            $updates['totals']     = wp_json_encode( $totals );
        }

        // Recalculate totals with shipping if a fulfillment option is selected
        $fulfillment_data = isset( $updates['fulfillment'] )
            ? json_decode( $updates['fulfillment'], true )
            : json_decode( $session['fulfillment'] ?? 'null', true );

        $shipping_cost    = $this->extract_selected_shipping_cost( $fulfillment_data );
        $items_for_totals = isset( $updates['line_items'] )
            ? json_decode( $updates['line_items'], true )
            : json_decode( $session['line_items'], true );

        $cart_subtotal = 0;
        foreach ( $items_for_totals as $li ) {
            $cart_subtotal += $this->extract_total( $li['totals'] ?? array() );
        }

        $new_totals_array = array(
            array( 'type' => 'subtotal', 'amount' => $cart_subtotal ),
        );
        if ( $shipping_cost > 0 ) {
            $new_totals_array[] = array( 'type' => 'fulfillment', 'amount' => $shipping_cost );
        }
        $new_totals_array[] = array( 'type' => 'total', 'amount' => $cart_subtotal + $shipping_cost );
        $updates['totals'] = wp_json_encode( $new_totals_array );

        // Re-prepare payment if total changed
        $current_totals = json_decode( $session['totals'], true );
        $current_total  = $this->extract_total( $current_totals );
        $new_total      = $cart_subtotal + $shipping_cost;

        if ( $current_total !== $new_total || empty( $session['payment_meta'] ) || $session['payment_meta'] === 'null' ) {
            $order_label = 'Checkout';
            if ( ! empty( $session['wc_order_id'] ) ) {
                $wc_order = wc_get_order( (int) $session['wc_order_id'] );
                if ( $wc_order ) {
                    $order_label = 'Order #' . $wc_order->get_order_number();
                }
            }
            $prepare_input = array(
                'checkout_id'       => $session['id'],
                'total'             => $new_total,
                'currency'          => $session['currency'],
                'checkout_base_url' => home_url( '/wp-json/' . self::NAMESPACE ),
                'store_name'        => FD_UCP_Plugin::instance()->store_name(),
                'order_label'       => $order_label,
                'checkout_meta'     => json_decode( $session['payment_meta'] ?? 'null', true ),
            );
            $updates['payment_meta'] = wp_json_encode( $this->registry->prepare_all( $prepare_input ) );
        }

        $updates['updated_at'] = current_time( 'mysql', true );
        $this->update_session_row( $session['id'], $updates );

        $session = $this->load_session( $session['id'] );

        return new WP_REST_Response(
            FD_UCP_Formatter::format_checkout_session( $session, $this->registry ),
            200
        );
    }

    // =========================================================================
    // Complete
    // =========================================================================

    public function complete_session( WP_REST_Request $request ): WP_REST_Response {
        $rl = $this->rate_limiter->check( 'checkout_complete' );
        if ( is_wp_error( $rl ) ) {
            return FD_UCP_Error::response( $rl->get_error_code(), $rl->get_error_message(), 429 );
        }

        $session = $this->load_session( $request->get_param( 'id' ) );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
        }

        if ( in_array( $session['status'], array( 'canceled', 'completed' ), true ) ) {
            return FD_UCP_Error::response( 'session_' . $session['status'], 'Session has been ' . $session['status'], 409 );
        }

        // Guard against settling when the WC order has been cancelled by an admin
        if ( ! empty( $session['wc_order_id'] ) ) {
            $existing_order = wc_get_order( (int) $session['wc_order_id'] );
            if ( $existing_order && $existing_order->get_status() === 'cancelled' ) {
                $this->update_session_row( $session['id'], array(
                    'status'     => 'canceled',
                    'updated_at' => current_time( 'mysql', true ),
                ) );
                return FD_UCP_Error::response( 'order_cancelled', 'Order has been cancelled', 409 );
            }
        }

        $body    = $request->get_json_params();
        $payment = $body['payment'] ?? null;

        if ( ! $payment || ! is_array( $payment['instruments'] ?? null ) || empty( $payment['instruments'] ) ) {
            return FD_UCP_Error::response( 'missing_payment', 'payment.instruments array is required', 400 );
        }

        $instrument = $payment['instruments'][0];
        $handler_id = $instrument['handler_id'] ?? '';
        $credential = $instrument['credential'] ?? null;

        if ( ! $handler_id || ! $credential ) {
            return FD_UCP_Error::response( 'invalid_instrument', 'handler_id and credential are required', 400 );
        }

        $payment_meta = json_decode( $session['payment_meta'] ?? '{}', true );

        // Mark as in-progress
        $this->update_session_row( $session['id'], array( 'status' => 'complete_in_progress' ) );

        // Ensure the WC order exists before settling — never settle without a guaranteed order record
        $order = ! empty( $session['wc_order_id'] )
            ? wc_get_order( (int) $session['wc_order_id'] )
            : null;

        if ( ! $order ) {
            $order = $this->create_pending_wc_order(
                json_decode( $session['line_items'], true ),
                json_decode( $session['buyer'] ?? '{}', true ),
                json_decode( $session['fulfillment'] ?? 'null', true )
            );
        }

        if ( is_wp_error( $order ) ) {
            $this->update_session_row( $session['id'], array( 'status' => 'incomplete' ) );
            return FD_UCP_Error::response( 'order_creation_failed', $order->get_error_message(), 422 );
        }

        // Settle payment — order is guaranteed to exist at this point
        $settle_input = array(
            'checkout_id'   => $session['id'],
            'handler_id'    => $handler_id,
            'credential'    => $credential,
            'checkout_meta' => $payment_meta,
        );

        $result = $this->registry->settle( $handler_id, $settle_input );

        if ( empty( $result['success'] ) ) {
            $this->update_session_row( $session['id'], array( 'status' => 'incomplete' ) );
            return FD_UCP_Error::response( 'payment_failed', $result['error'] ?? 'Payment settlement failed', 422 );
        }

        $this->finalize_wc_order( $order, $session, $result, $handler_id );

        // Update session
        $this->update_session_row( $session['id'], array(
            'status'     => 'completed',
            'wc_order_id' => $order->get_id(),
            'updated_at' => current_time( 'mysql', true ),
        ) );

        $session = $this->load_session( $session['id'] );

        return new WP_REST_Response(
            FD_UCP_Formatter::format_complete_response( $session, $order, $this->registry ),
            200
        );
    }

    // =========================================================================
    // Cancel
    // =========================================================================

    public function cancel_session( WP_REST_Request $request ): WP_REST_Response {
        $session = $this->load_session( $request->get_param( 'id' ) );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
        }

        if ( 'completed' === $session['status'] ) {
            return FD_UCP_Error::response( 'session_completed', 'Cannot cancel a completed session', 409 );
        }

        if ( 'canceled' === $session['status'] ) {
            return FD_UCP_Error::response( 'session_canceled', 'Session is already canceled', 409 );
        }

        // Cancel the pending WC order if one exists
        if ( ! empty( $session['wc_order_id'] ) ) {
            $wc_order = wc_get_order( (int) $session['wc_order_id'] );
            if ( $wc_order && $wc_order->get_status() === 'pending' ) {
                $wc_order->update_status( 'cancelled', 'UCP checkout session cancelled.' );
            }
        }

        $this->update_session_row( $session['id'], array(
            'status'     => 'canceled',
            'updated_at' => current_time( 'mysql', true ),
        ) );

        $session['status'] = 'canceled';

        return new WP_REST_Response(
            FD_UCP_Formatter::format_checkout_session( $session, $this->registry ),
            200
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function create_pending_wc_order( array $line_items, ?array $buyer, ?array $fulfillment ): WC_Order|WP_Error {
        $order = wc_create_order( array(
            'status'      => 'pending',
            'customer_id' => 0,
            'created_via' => 'fd-ucp',
        ) );

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        foreach ( $line_items as $li ) {
            $product = wc_get_product( (int) $li['item']['id'] );
            if ( $product ) {
                $order->add_product( $product, $li['quantity'] );
            }
        }

        if ( ! empty( $buyer['email'] ) ) {
            $order->set_billing_email( $buyer['email'] );
        }
        if ( ! empty( $buyer['first_name'] ) ) {
            $order->set_billing_first_name( $buyer['first_name'] );
            $order->set_shipping_first_name( $buyer['first_name'] );
        }
        if ( ! empty( $buyer['last_name'] ) ) {
            $order->set_billing_last_name( $buyer['last_name'] );
            $order->set_shipping_last_name( $buyer['last_name'] );
        }

        $order->calculate_totals();
        $order->save();

        $order->add_meta_data( '_wc_order_attribution_source_type', 'fd-ucp', true );
        $order->add_meta_data( '_wc_order_attribution_utm_source', 'fd-ucp', true );
        $order->save();

        return $order;
    }

    private function finalize_wc_order( WC_Order $order, array $session, array $settle_result, string $handler_id ): void {
        $buyer       = json_decode( $session['buyer'] ?? '{}', true );
        $fulfillment = json_decode( $session['fulfillment'] ?? 'null', true );
        $line_items  = json_decode( $session['line_items'], true );

        // Update buyer info (may have been added/changed after order creation)
        if ( ! empty( $buyer['email'] ) ) {
            $order->set_billing_email( $buyer['email'] );
        }
        if ( ! empty( $buyer['first_name'] ) ) {
            $order->set_billing_first_name( $buyer['first_name'] );
            $order->set_shipping_first_name( $buyer['first_name'] );
        }
        if ( ! empty( $buyer['last_name'] ) ) {
            $order->set_billing_last_name( $buyer['last_name'] );
            $order->set_shipping_last_name( $buyer['last_name'] );
        }

        $order->remove_order_items( 'line_item' );
        foreach ( $line_items as $li ) {
            $product = wc_get_product( (int) $li['item']['id'] );
            if ( $product ) {
                $order->add_product( $product, $li['quantity'] );
            } else {
                $order->add_order_note( "Product #{$li['item']['id']} no longer exists — line item preserved from session." );
                $item = new WC_Order_Item_Product();
                $item->set_name( $li['item']['title'] ?? "Product #{$li['item']['id']}" );
                $item->set_quantity( $li['quantity'] );
                $item->set_total( ( $li['item']['price'] ?? 0 ) * $li['quantity'] / 100 );
                $order->add_item( $item );
            }
        }

        // Shipping address
        if ( $fulfillment ) {
            $dest = $fulfillment['methods'][0]['destinations'][0] ?? null;
            if ( $dest ) {
                $wc_addr = FD_UCP_Address::ucp_to_wc( $dest );
                $order->set_shipping_address_1( $wc_addr['address_1'] );
                $order->set_shipping_address_2( $wc_addr['address_2'] );
                $order->set_shipping_city( $wc_addr['city'] );
                $order->set_shipping_state( $wc_addr['state'] );
                $order->set_shipping_postcode( $wc_addr['postcode'] );
                $order->set_shipping_country( $wc_addr['country'] );

                $order->set_billing_address_1( $wc_addr['address_1'] );
                $order->set_billing_address_2( $wc_addr['address_2'] );
                $order->set_billing_city( $wc_addr['city'] );
                $order->set_billing_state( $wc_addr['state'] );
                $order->set_billing_postcode( $wc_addr['postcode'] );
                $order->set_billing_country( $wc_addr['country'] );
            }

            // Shipping method
            $order->remove_order_items( 'shipping' );
            $selected_group = $fulfillment['methods'][0]['groups'][0] ?? null;
            if ( $selected_group ) {
                $selected_id = $selected_group['selected_option_id'] ?? null;
                foreach ( $selected_group['options'] ?? array() as $option ) {
                    if ( $option['id'] === $selected_id ) {
                        $shipping_cost = 0;
                        foreach ( $option['totals'] ?? array() as $t ) {
                            if ( 'total' === $t['type'] ) {
                                $shipping_cost = (float) $t['amount'] / 100;
                            }
                        }
                        $shipping_item = new WC_Order_Item_Shipping();
                        $shipping_item->set_method_title( $option['title'] ?? 'Shipping' );
                        $shipping_item->set_method_id( $selected_id );
                        $shipping_item->set_total( $shipping_cost );
                        $order->add_item( $shipping_item );
                        break;
                    }
                }
            }
        }

        // Payment method
        $payment_method = $settle_result['payment_method'] ?? ( 'fd_ucp_' . $handler_id );
        $payment_title  = $settle_result['payment_method_title'] ?? 'UCP Payment';
        $order->set_payment_method( $payment_method );
        $order->set_payment_method_title( $payment_title );

        // Settlement metadata
        $tx_ref = $settle_result['transaction_reference'] ?? '';
        if ( $tx_ref ) {
            $order->update_meta_data( '_fd_ucp_tx_reference', $tx_ref );
        }
        $network = $settle_result['network'] ?? '';
        if ( $network ) {
            $order->update_meta_data( '_fd_ucp_network', $network );
        }
        if ( ! empty( $handler_id ) ) {
            $order->update_meta_data( '_fd_ucp_handler_id', $handler_id );
        }
        if ( ! empty( $session['agent_fingerprint'] ) ) {
            $order->update_meta_data( '_fd_ucp_agent_fingerprint', $session['agent_fingerprint'] );
        }

        foreach ( $settle_result['order_meta'] ?? array() as $meta_key => $meta_value ) {
            $order->update_meta_data( $meta_key, $meta_value );
        }

        $order->calculate_totals();
        $order->save();
        $order->payment_complete( $tx_ref );
    }

    private function verify_ownership( WP_REST_Request $request, array $session ): true|WP_Error {
        $stored = $session['agent_fingerprint'] ?? '';
        if ( empty( $stored ) ) {
            return true;
        }
        $current = $this->compute_fingerprint( $request );
        if ( ! hash_equals( $stored, $current ) ) {
            return new WP_Error( 'session_ownership', 'Session belongs to a different agent' );
        }
        return true;
    }

    private function compute_fingerprint( WP_REST_Request $request ): string {
        $agent = $request->get_header( 'ucp-agent' ) ?? '';
        return hash( 'sha256', $agent );
    }

    private function extract_total( ?array $totals ): int {
        if ( ! $totals ) {
            return 0;
        }
        foreach ( $totals as $t ) {
            if ( 'total' === ( $t['type'] ?? '' ) ) {
                return (int) $t['amount'];
            }
        }
        return 0;
    }

    // =========================================================================
    // Fulfillment / Shipping
    // =========================================================================

    private function process_fulfillment( array $input, array $line_items, string $currency ): array {
        $methods = $input['methods'] ?? array();
        if ( empty( $methods ) ) {
            return $input;
        }

        $method = $methods[0];
        $dest   = $method['destinations'][0] ?? null;

        if ( ! $dest ) {
            return $input;
        }

        $dest = FD_UCP_Address::normalize( $dest );

        if ( empty( $dest['address_country'] ) ) {
            return $input;
        }

        $wc_dest = FD_UCP_Address::ucp_to_wc( $dest );

        $selected_option_id = $method['groups'][0]['selected_option_id'] ?? null;

        $package = $this->build_shipping_package( $line_items, $wc_dest );
        $rates   = $this->calculate_shipping_rates( $package );

        if ( empty( $dest['id'] ) ) {
            $dest['id'] = 'dest_1';
        }

        $options = array();
        $first_rate_id = null;
        foreach ( $rates as $rate ) {
            $rate_id = sanitize_title( $rate->get_id() );
            if ( null === $first_rate_id ) {
                $first_rate_id = $rate_id;
            }

            $cost = FD_UCP_Formatter::to_minor( (float) $rate->get_cost() );
            $options[] = array(
                'id'     => $rate_id,
                'title'  => $rate->get_label(),
                'totals' => array(
                    array( 'type' => 'total', 'amount' => $cost ),
                ),
            );
        }

        if ( empty( $options ) ) {
            $options[] = array(
                'id'     => 'free_shipping',
                'title'  => 'Free Shipping',
                'totals' => array(
                    array( 'type' => 'total', 'amount' => 0 ),
                ),
            );
            $first_rate_id = 'free_shipping';
        }

        $effective_selected = $selected_option_id;
        $valid_ids = array_column( $options, 'id' );
        if ( ! $effective_selected || ! in_array( $effective_selected, $valid_ids, true ) ) {
            $effective_selected = $first_rate_id;
        }

        $line_item_ids = array_column( $line_items, 'id' );

        return array(
            'methods' => array(
                array(
                    'id'                      => $method['id'] ?? 'shipping_1',
                    'type'                    => $method['type'] ?? 'shipping',
                    'line_item_ids'           => $line_item_ids,
                    'selected_destination_id' => $dest['id'],
                    'destinations'            => array( $dest ),
                    'groups'                  => array(
                        array(
                            'id'                 => 'package_1',
                            'line_item_ids'      => $line_item_ids,
                            'selected_option_id' => $effective_selected,
                            'options'            => $options,
                        ),
                    ),
                ),
            ),
        );
    }

    private function build_shipping_package( array $line_items, array $wc_dest ): array {
        $contents      = array();
        $contents_cost = 0;

        foreach ( $line_items as $i => $li ) {
            $product = wc_get_product( (int) $li['item']['id'] );
            if ( ! $product ) {
                continue;
            }

            $qty   = (int) ( $li['quantity'] ?? 1 );
            $price = (float) $product->get_price();

            $contents[ $i ] = array(
                'key'               => 'ucp_' . $i,
                'product_id'        => $product->get_id(),
                'variation_id'      => 0,
                'variation'         => array(),
                'quantity'          => $qty,
                'data'              => $product,
                'data_hash'         => '',
                'line_total'        => $price * $qty,
                'line_tax'          => 0,
                'line_subtotal'     => $price * $qty,
                'line_subtotal_tax' => 0,
            );
            $contents_cost += $price * $qty;
        }

        return array(
            'contents'        => $contents,
            'contents_cost'   => $contents_cost,
            'applied_coupons' => array(),
            'user'            => array( 'ID' => 0 ),
            'destination'     => array(
                'country'  => $wc_dest['country'] ?? '',
                'state'    => $wc_dest['state'] ?? '',
                'postcode' => $wc_dest['postcode'] ?? '',
                'city'     => $wc_dest['city'] ?? '',
                'address'  => $wc_dest['address_1'] ?? '',
                'address_2' => $wc_dest['address_2'] ?? '',
            ),
        );
    }

    private function calculate_shipping_rates( array $package ): array {
        if ( ! WC()->session ) {
            WC()->session = new \WC_Session_Handler();
            WC()->session->init();
        }
        if ( null === WC()->customer ) {
            WC()->customer = new \WC_Customer( 0, true );
        }

        $shipping = WC()->shipping();
        if ( ! $shipping ) {
            return array();
        }

        $shipping->load_shipping_methods();
        $result = $shipping->calculate_shipping_for_package( $package );

        return $result['rates'] ?? array();
    }

    private function extract_selected_shipping_cost( ?array $fulfillment ): int {
        if ( ! $fulfillment ) {
            return 0;
        }

        $group = $fulfillment['methods'][0]['groups'][0] ?? null;
        if ( ! $group ) {
            return 0;
        }

        $selected_id = $group['selected_option_id'] ?? null;
        if ( ! $selected_id ) {
            return 0;
        }

        foreach ( $group['options'] ?? array() as $option ) {
            if ( $option['id'] === $selected_id ) {
                foreach ( $option['totals'] ?? array() as $total ) {
                    if ( 'total' === $total['type'] ) {
                        return (int) $total['amount'];
                    }
                }
            }
        }

        return 0;
    }

    // =========================================================================
    // Database
    // =========================================================================

    private function insert_session( array $data ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
        $wpdb->insert( "{$wpdb->prefix}fd_ucp_checkout_sessions", $data );
    }

    private function load_session( string $id ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, not cacheable
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_checkout_sessions WHERE id = %s", $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    private function update_session_row( string $id, array $data ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
        $wpdb->update( "{$wpdb->prefix}fd_ucp_checkout_sessions", $data, array( 'id' => $id ) );
    }
}
