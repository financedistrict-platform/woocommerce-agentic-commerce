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

        // Idempotency-Key: return existing session if key was already used.
        $idempotency_key = $request->get_header( 'idempotency-key' );
        if ( $idempotency_key ) {
            $existing = $this->load_session_by_idempotency_key( $idempotency_key );
            if ( $existing ) {
                if ( ! FD_UCP_Session_Token::owns_row( $request, $existing ) ) {
                    return FD_UCP_Error::response( 'idempotency_key_conflict', 'Idempotency-Key was already used', 409 );
                }
                $pin = FD_UCP_Plugin::instance()->pin_session( $request, $existing['ucp_version'] ?? null );
                if ( null !== $pin ) {
                    return $pin;
                }
                return new WP_REST_Response(
                    FD_UCP_Formatter::format_checkout_session( $existing, $this->registry ),
                    200
                );
            }
        }

        $body       = $request->get_json_params();
        $line_items = $body['line_items'] ?? null;

        if ( ! is_array( $line_items ) || empty( $line_items ) ) {
            return FD_UCP_Error::response( 'missing_line_items', 'line_items array is required', 400 );
        }

        $session_id = wp_generate_uuid4();
        $currency   = get_woocommerce_currency();

        $formatted_items = FD_UCP_Checkout_Pricing::catalog_line_items( $line_items, false );
        if ( is_wp_error( $formatted_items ) ) {
            return FD_UCP_Error::response( $formatted_items->get_error_code(), $formatted_items->get_error_message(), 422 );
        }

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
            $fulfillment = $this->process_fulfillment( $body['fulfillment'], $formatted_items );
            if ( is_wp_error( $fulfillment ) ) {
                return FD_UCP_Error::response( $fulfillment->get_error_code(), $fulfillment->get_error_message(), 422 );
            }
        }

        $quote = $this->quote( $formatted_items, $buyer, $fulfillment, null );
        if ( is_wp_error( $quote ) ) {
            return FD_UCP_Error::response( $quote->get_error_code(), $quote->get_error_message(), 422 );
        }
        $totals       = $quote['totals'];
        $order_number = $quote['order']->get_order_number();
        $order_id     = $quote['order']->get_id();

        // Prepare payment handlers
        $checkout_base_url = home_url( '/wp-json/' . self::NAMESPACE );
        $checkout_total    = FD_UCP_Checkout_Pricing::total_of( $totals );
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
        $token = FD_UCP_Session_Token::issue();
        $now   = current_time( 'mysql', true );
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
            'session_token_hash' => FD_UCP_Session_Token::hash( $token ),
            'idempotency_key'  => $idempotency_key,
            'ucp_version'      => FD_UCP_Request_Context::current()->session_pin(),
            'created_at'       => $now,
            'updated_at'       => $now,
            'expires_at'       => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ),
        ) );

        $session = $this->load_session( $session_id );

        return FD_UCP_Session_Token::hand_over(
            new WP_REST_Response( FD_UCP_Formatter::format_checkout_session( $session, $this->registry ), 201 ),
            $token
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

        $pin = FD_UCP_Plugin::instance()->pin_session( $request, $session['ucp_version'] ?? null );
        if ( null !== $pin ) {
            return $pin;
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
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
        return $this->with_session_lock(
            (string) $request->get_param( 'id' ),
            'session_busy',
            'Checkout session is being changed by another request',
            fn() => $this->do_update_session( $request )
        );
    }

    private function do_update_session( WP_REST_Request $request ): WP_REST_Response {
        $session = $this->load_session( (string) $request->get_param( 'id' ) );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        $pin = FD_UCP_Plugin::instance()->pin_session( $request, $session['ucp_version'] ?? null );
        if ( null !== $pin ) {
            return $pin;
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
        }

        if ( in_array( $session['status'], array( 'canceled', 'completed', 'requires_escalation', 'expired' ), true ) ) {
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

        $items_changed = ! empty( $body['line_items'] ) && is_array( $body['line_items'] );
        $line_items    = FD_UCP_Checkout_Pricing::catalog_line_items(
            $items_changed ? $body['line_items'] : ( json_decode( $session['line_items'] ?? '[]', true ) ?: array() ),
            true
        );
        if ( is_wp_error( $line_items ) ) {
            return FD_UCP_Error::response( $line_items->get_error_code(), $line_items->get_error_message(), 422 );
        }
        $updates['line_items'] = wp_json_encode( $line_items );

        $fulfillment       = json_decode( $session['fulfillment'] ?? 'null', true );
        $fulfillment_input = ! empty( $body['fulfillment'] )
            ? $body['fulfillment']
            : ( $items_changed ? $fulfillment : null );

        if ( null !== $fulfillment_input ) {
            $fulfillment = $this->process_fulfillment(
                is_array( $fulfillment_input ) ? $fulfillment_input : array(),
                $line_items
            );
            if ( is_wp_error( $fulfillment ) ) {
                return FD_UCP_Error::response( $fulfillment->get_error_code(), $fulfillment->get_error_message(), 422 );
            }
            $updates['fulfillment'] = wp_json_encode( $fulfillment );
        }

        $quote = $this->quote(
            $line_items,
            json_decode( $updates['buyer'] ?? $session['buyer'] ?? 'null', true ),
            $fulfillment,
            empty( $session['wc_order_id'] ) ? null : ( wc_get_order( (int) $session['wc_order_id'] ) ?: null )
        );
        if ( is_wp_error( $quote ) ) {
            return FD_UCP_Error::response( $quote->get_error_code(), $quote->get_error_message(), 422 );
        }
        $new_totals_array       = $quote['totals'];
        $updates['totals']      = wp_json_encode( $new_totals_array );
        $updates['wc_order_id'] = $quote['order']->get_id();

        // Re-prepare payment if total changed
        $current_totals = json_decode( $session['totals'], true );
        $current_total  = FD_UCP_Checkout_Pricing::total_of( $current_totals );
        $new_total      = FD_UCP_Checkout_Pricing::total_of( $new_totals_array );

        $stored_meta = json_decode( $session['payment_meta'] ?? 'null', true );
        $live_meta   = is_array( $stored_meta ) ? $this->registry->without_stale_quotes( $stored_meta ) : null;

        if ( $current_total !== $new_total || empty( $stored_meta ) || $live_meta !== $stored_meta ) {
            $prepare_input = array(
                'checkout_id'       => $session['id'],
                'total'             => $new_total,
                'currency'          => $session['currency'],
                'checkout_base_url' => home_url( '/wp-json/' . self::NAMESPACE ),
                'store_name'        => FD_UCP_Plugin::instance()->store_name(),
                'order_label'       => 'Order #' . $quote['order']->get_order_number(),
                'checkout_meta'     => $live_meta,
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

        $session_id = (string) $request->get_param( 'id' );

        return $this->with_session_lock(
            $session_id,
            'settlement_in_progress',
            'Another settlement attempt is in progress',
            fn() => $this->do_complete_session( $request, $session_id )
        );
    }

    private function with_session_lock( string $session_id, string $busy_code, string $busy_message, callable $fn ): WP_REST_Response {
        global $wpdb;
        $lock_name = 'fd_ucp_session_' . md5( $session_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock_name ) );
        if ( '1' !== (string) $got_lock ) {
            return FD_UCP_Error::response( $busy_code, $busy_message, 409 );
        }

        try {
            return $fn();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    private function do_complete_session( WP_REST_Request $request, string $session_id ): WP_REST_Response {
        $session = $this->load_session( $session_id );
        if ( ! $session ) {
            return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
        }

        $pin = FD_UCP_Plugin::instance()->pin_session( $request, $session['ucp_version'] ?? null );
        if ( null !== $pin ) {
            return $pin;
        }

        $ownership = $this->verify_ownership( $request, $session );
        if ( is_wp_error( $ownership ) ) {
            return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
        }

        if ( in_array( $session['status'], array( 'canceled', 'completed', 'complete_in_progress', 'requires_escalation', 'expired' ), true ) ) {
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
        $guard      = self::instrument_error( is_array( $instrument ) ? $instrument : array() );
        if ( null !== $guard ) {
            return FD_UCP_Error::response( 'invalid_instrument', $guard, 400 );
        }

        $handler_id = $this->registry->canonical_id( $instrument['handler_id'] );
        $credential = $instrument['credential'];

        $instrument_error = $this->registry->validate_instrument( $handler_id, $instrument );
        if ( null !== $instrument_error ) {
            return FD_UCP_Error::response( 'invalid_instrument', $instrument_error, 400 );
        }

        $payment_meta = json_decode( $session['payment_meta'] ?? '{}', true );

        $line_items = FD_UCP_Checkout_Pricing::catalog_line_items( json_decode( $session['line_items'] ?? '[]', true ) ?: array(), true );
        if ( is_wp_error( $line_items ) ) {
            return FD_UCP_Error::response( $line_items->get_error_code(), $line_items->get_error_message(), 422 );
        }

        if ( ! FD_Payment_Registry::quote_is_fresh( $payment_meta[ $handler_id ] ?? null ) ) {
            return FD_UCP_Error::response( 'quote_expired', 'Payment quote expired, update the session to get a new quote', 409 );
        }

        // Ensure the WC order exists before settling — never settle without a guaranteed order record
        $order = ! empty( $session['wc_order_id'] )
            ? wc_get_order( (int) $session['wc_order_id'] )
            : null;

        if ( ! $order ) {
            $order = $this->create_pending_wc_order();
        }

        if ( is_wp_error( $order ) ) {
            return FD_UCP_Error::response( 'order_creation_failed', $order->get_error_message(), 422 );
        }

        if ( ! $order->needs_payment() ) {
            return FD_UCP_Error::response( 'order_not_payable', 'The order for this checkout no longer accepts payment', 409 );
        }

        $this->sync_wc_order(
            $order,
            $line_items,
            json_decode( $session['buyer'] ?? 'null', true ),
            json_decode( $session['fulfillment'] ?? 'null', true )
        );

        $quoted_total = FD_UCP_Checkout_Pricing::total_of( json_decode( $session['totals'] ?? 'null', true ) );
        $mismatch     = FD_UCP_Checkout_Pricing::amount_mismatch( array(
            'quote'    => $quoted_total,
            'prepared' => $payment_meta[ $handler_id ]['prepared_amount'] ?? null,
            'order'    => FD_UCP_Checkout_Pricing::order_total_minor( $order ),
        ) );
        if ( null !== $mismatch ) {
            $this->update_session_row( $session['id'], array( 'wc_order_id' => $order->get_id() ) );
            return FD_UCP_Error::response( 'total_changed', "Checkout total changed, update the session to get a new quote. $mismatch", 409 );
        }

        // Mark as in-progress
        $this->update_session_row( $session['id'], array( 'status' => 'complete_in_progress' ) );

        // Settle payment — order is guaranteed to exist at this point
        $settle_input = array(
            'checkout_id'   => $session['id'],
            'handler_id'    => $handler_id,
            'credential'    => $credential,
            'checkout_meta' => $payment_meta,
        );

        try {
            $result = $this->registry->settle( $handler_id, $settle_input );
        } catch ( Throwable $e ) {
            $this->update_session_row( $session['id'], array( 'status' => 'incomplete' ) );
            return FD_UCP_Error::response( 'payment_failed', 'Payment settlement failed', 422 );
        }

        if ( empty( $result['success'] ) ) {
            $this->update_session_row( $session['id'], array( 'status' => 'incomplete' ) );
            return FD_UCP_Error::response( 'payment_failed', $result['error'] ?? 'Payment settlement failed', 422 );
        }

        $this->record_settlement( $order, $session, $result, $handler_id );

        $conflict = $this->transaction_conflict( $session['id'], $result );
        $mismatch = FD_UCP_Checkout_Pricing::amount_mismatch( array(
            'quote'   => $quoted_total,
            'order'   => FD_UCP_Checkout_Pricing::order_total_minor( $order ),
            'settled' => $result['settled_amount'] ?? null,
        ) ) ?? $conflict;
        if ( null !== $mismatch ) {
            $order->update_status( 'on-hold', "Payment held for review. $mismatch" );
            $this->update_session_row( $session['id'], array(
                'status'      => 'requires_escalation',
                'wc_order_id' => $order->get_id(),
                'updated_at'  => current_time( 'mysql', true ),
            ) );
            return FD_UCP_Error::response( 'payment_on_hold', 'Payment received but held for merchant review', 409 );
        }

        $order->payment_complete( $result['transaction_reference'] ?? '' );

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

        $pin = FD_UCP_Plugin::instance()->pin_session( $request, $session['ucp_version'] ?? null );
        if ( null !== $pin ) {
            return $pin;
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

    private function quote( array $line_items, ?array $buyer, ?array $fulfillment, ?WC_Order $order ): array|WP_Error {
        $untaxed = FD_UCP_Checkout_Pricing::priced_totals(
            FD_UCP_Checkout_Pricing::subtotal_of( $line_items ),
            FD_UCP_Checkout_Pricing::selected_shipping_cost( $fulfillment )
        );
        if ( is_wp_error( $untaxed ) ) {
            return $untaxed;
        }

        if ( ! $order || ! $order->needs_payment() ) {
            $order = $this->create_pending_wc_order();
        }
        if ( is_wp_error( $order ) ) {
            return new WP_Error( 'order_creation_failed', $order->get_error_message() );
        }

        $this->sync_wc_order( $order, $line_items, $buyer, $fulfillment );

        $totals = FD_UCP_Checkout_Pricing::order_totals( $order );
        if ( is_wp_error( $totals ) ) {
            return $totals;
        }

        return array(
            'order'  => $order,
            'totals' => $totals,
        );
    }

    private function create_pending_wc_order(): WC_Order|WP_Error {
        $order = wc_create_order( array(
            'status'      => 'pending',
            'customer_id' => 0,
            'created_via' => 'fd-ucp',
        ) );

        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $order->add_meta_data( '_wc_order_attribution_source_type', 'fd-ucp', true );
        $order->add_meta_data( '_wc_order_attribution_utm_source', 'fd-ucp', true );
        $order->save();

        return $order;
    }

    private function sync_wc_order( WC_Order $order, array $line_items, ?array $buyer, ?array $fulfillment ): void {
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
            $order->add_product( wc_get_product( (int) $li['item']['id'] ), $li['quantity'] );
        }

        $order->remove_order_items( 'shipping' );
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

        $selected_group = $fulfillment['methods'][0]['groups'][0] ?? null;
        $selected_id    = $selected_group['selected_option_id'] ?? null;
        foreach ( $selected_group['options'] ?? array() as $option ) {
            if ( ( $option['id'] ?? null ) === $selected_id ) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( $option['title'] ?? 'Shipping' );
                $shipping_item->set_method_id( $selected_id );
                $shipping_item->set_total( FD_UCP_Checkout_Pricing::total_of( $option['totals'] ?? array() ) / 100 );
                $order->add_item( $shipping_item );
                break;
            }
        }

        $order->calculate_totals();
        $order->save();
    }

    private function transaction_conflict( string $checkout_id, array $settle_result ): ?string {
        $tx_ref = $settle_result['transaction_reference'] ?? null;
        if ( ! is_string( $tx_ref ) || '' === $tx_ref ) {
            return 'The settlement has no transaction reference';
        }
        if ( ! FD_Payment_Claims::claim( FD_Payment_Claims::KIND_TRANSACTION, $tx_ref, $checkout_id ) ) {
            return 'The transaction reference is already used by another checkout';
        }
        return null;
    }

    private function record_settlement( WC_Order $order, array $session, array $settle_result, string $handler_id ): void {
        $order->set_payment_method( $settle_result['payment_method'] ?? ( 'fd_ucp_' . $handler_id ) );
        $order->set_payment_method_title( $settle_result['payment_method_title'] ?? 'UCP Payment' );

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
        $order->update_meta_data( FD_UCP_Session_Token::ORDER_META, $session[ FD_UCP_Session_Token::COLUMN ] ?? '' );

        foreach ( $settle_result['order_meta'] ?? array() as $meta_key => $meta_value ) {
            $order->update_meta_data( $meta_key, $meta_value );
        }

        $order->save();
    }

    public static function instrument_error( array $instrument ): ?string {
        $handler_id = $instrument['handler_id'] ?? '';
        $credential = $instrument['credential'] ?? null;

        if ( ! is_string( $handler_id ) || '' === $handler_id || ! $credential ) {
            return 'handler_id and credential are required';
        }
        if ( ! is_string( $credential ) && ( ! is_array( $credential ) || array_is_list( $credential ) ) ) {
            return 'credential must be an object or an encoded string';
        }
        return null;
    }

    private function verify_ownership( WP_REST_Request $request, array $session ): true|WP_Error {
        if ( ! FD_UCP_Session_Token::owns_row( $request, $session ) ) {
            return new WP_Error( 'session_ownership', 'A valid UCP-Session-Token is required for this checkout session' );
        }
        return true;
    }

    // =========================================================================
    // Fulfillment / Shipping
    // =========================================================================

    private function process_fulfillment( array $input, array $line_items ): array|WP_Error {
        $method = $input['methods'][0] ?? null;
        $dest   = is_array( $method ) ? ( $method['destinations'][0] ?? null ) : null;

        if ( ! is_array( $dest ) ) {
            return new WP_Error( 'invalid_fulfillment', 'fulfillment.methods[0].destinations[0] is required' );
        }

        $dest = FD_UCP_Address::normalize( $dest );

        if ( empty( $dest['address_country'] ) ) {
            return new WP_Error( 'invalid_fulfillment', 'Shipping destination needs address_country' );
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
            if ( $cost < 0 ) {
                return new WP_Error( 'invalid_fulfillment', 'Shipping rate cannot be priced' );
            }
            $options[] = array(
                'id'     => $rate_id,
                'title'  => $rate->get_label(),
                'totals' => array(
                    array( 'type' => 'total', 'amount' => $cost ),
                ),
            );
        }

        if ( empty( $options ) ) {
            if ( $this->needs_shipping( $line_items ) ) {
                return new WP_Error( 'invalid_fulfillment', 'No shipping method serves this destination' );
            }
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

    private function needs_shipping( array $line_items ): bool {
        foreach ( $line_items as $li ) {
            $product = wc_get_product( (int) ( $li['item']['id'] ?? 0 ) );
            if ( ! $product || $product->needs_shipping() ) {
                return true;
            }
        }
        return false;
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
        if ( $row && ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
            if ( ! in_array( $row['status'], array( 'completed', 'canceled' ), true ) ) {
                $this->update_session_row( $id, array(
                    'status'     => 'expired',
                    'updated_at' => current_time( 'mysql', true ),
                ) );
                $row['status'] = 'expired';
            }
        }
        return $row ?: null;
    }

    private function load_session_by_idempotency_key( string $key ): ?array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_checkout_sessions WHERE idempotency_key = %s LIMIT 1", $key ),
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
