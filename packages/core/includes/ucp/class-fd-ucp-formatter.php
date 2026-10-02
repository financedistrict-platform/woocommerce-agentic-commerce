<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Formatter {

    public const UCP_VERSION = FD_UCP_Version_Registry::DEFAULT_CURRENT;

    public static function to_minor( float $amount ): int {
        return (int) round( $amount * 100 );
    }

    public static function format_profile( string $endpoint_base_url, FD_Payment_Registry $registry ): array {
        $context = FD_UCP_Request_Context::current();
        return $context->wire()->profile( $endpoint_base_url, $registry, $context->supported_versions_map() );
    }

    public static function format_checkout_session( array $session, FD_Payment_Registry $registry ): array {
        return FD_UCP_Request_Context::current()->wire()->checkout_session( $session, $registry );
    }

    public static function format_complete_response( array $session, WC_Order $order, FD_Payment_Registry $registry ): array {
        return FD_UCP_Request_Context::current()->wire()->complete_response( $session, $order, $registry );
    }

    /**
     * Format a WC product for UCP catalog response.
     */
    public static function format_product( WC_Product $product ): array {
        $price = (float) $product->get_price();

        $formatted = array(
            'id'          => (string) $product->get_id(),
            'handle'      => $product->get_slug(),
            'title'       => $product->get_name(),
            'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'url'         => $product->get_permalink(),
            'price_range' => array(
                'min' => array( 'amount' => $price, 'currency' => get_woocommerce_currency() ),
                'max' => array( 'amount' => $price, 'currency' => get_woocommerce_currency() ),
            ),
            'variants'    => array(),
            'media'       => array(),
        );

        if ( $product->is_type( 'variable' ) ) {
            $min = (float) $product->get_variation_price( 'min' );
            $max = (float) $product->get_variation_price( 'max' );
            $formatted['price_range']['min']['amount'] = $min;
            $formatted['price_range']['max']['amount'] = $max;

            foreach ( $product->get_available_variations() as $var ) {
                $var_product = wc_get_product( $var['variation_id'] );
                if ( ! $var_product ) {
                    continue;
                }
                $formatted['variants'][] = array(
                    'id'           => (string) $var['variation_id'],
                    'title'        => $var_product->get_name(),
                    'price'        => array(
                        'amount'   => (float) $var_product->get_price(),
                        'currency' => get_woocommerce_currency(),
                    ),
                    'availability' => $var_product->is_in_stock() ? 'in_stock' : 'out_of_stock',
                    'options'      => self::format_variation_attributes( $var['attributes'] ),
                );
            }
        } else {
            $formatted['variants'][] = array(
                'id'           => (string) $product->get_id(),
                'title'        => $product->get_name(),
                'price'        => array( 'amount' => $price, 'currency' => get_woocommerce_currency() ),
                'availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
            );
        }

        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $url = wp_get_attachment_url( $image_id );
            if ( $url ) {
                $formatted['media'][] = array(
                    'type' => 'image',
                    'url'  => $url,
                    'alt'  => get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ?: $product->get_name(),
                );
            }
        }

        return $formatted;
    }

    /**
     * Format a WC order for UCP order response.
     */
    public static function format_order( WC_Order $order ): array {
        $line_items = array();
        foreach ( $order->get_items() as $item ) {
            $line_items[] = array(
                'id'       => (string) $item->get_id(),
                'item'     => array(
                    'id'    => (string) $item->get_product_id(),
                    'title' => $item->get_name(),
                    'price' => self::to_minor( (float) ( $item->get_total() / max( 1, $item->get_quantity() ) ) ),
                ),
                'quantity' => array(
                    'original'  => $item->get_quantity(),
                    'total'     => $item->get_quantity(),
                    'fulfilled' => 0,
                ),
                'totals'   => array(
                    array( 'type' => 'subtotal', 'amount' => self::to_minor( (float) $item->get_subtotal() ) ),
                    array( 'type' => 'total', 'amount' => self::to_minor( (float) $item->get_total() ) ),
                ),
            );
        }

        $shipping_addr = array(
            'address_1' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2(),
            'city'      => $order->get_shipping_city(),
            'state'     => $order->get_shipping_state(),
            'postcode'  => $order->get_shipping_postcode(),
            'country'   => $order->get_shipping_country(),
        );

        $response = array(
            'ucp'        => array(
                'version' => FD_UCP_Request_Context::current()->version(),
                'status'  => 'success',
            ),
            'id'         => (string) $order->get_id(),
            'label'      => $order->get_order_number(),
            'status'     => self::wc_order_status_to_ucp( $order->get_status() ),
            'currency'   => $order->get_currency(),
            'line_items' => $line_items,
            'totals'     => array(
                array( 'type' => 'subtotal', 'amount' => self::to_minor( (float) $order->get_subtotal() ) ),
                array( 'type' => 'tax', 'amount' => self::to_minor( (float) $order->get_total_tax() ) ),
                array( 'type' => 'shipping', 'amount' => self::to_minor( (float) $order->get_shipping_total() ) ),
                array( 'type' => 'total', 'amount' => self::to_minor( (float) $order->get_total() ) ),
            ),
            'buyer'      => array(
                'email'      => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
            ),
            'shipping_address'    => FD_UCP_Address::wc_to_ucp( $shipping_addr ),
            'fulfillment_events'  => array(),
        );

        $tx_ref = $order->get_meta( '_fd_ucp_tx_reference' );
        if ( $tx_ref ) {
            $response['transaction_reference'] = $tx_ref;
            $network = $order->get_meta( '_fd_ucp_network' );
            if ( $network ) {
                $response['network'] = $network;
            }
        }

        return $response;
    }

    private static function wc_order_status_to_ucp( string $wc_status ): string {
        return match ( $wc_status ) {
            'pending'    => 'pending',
            'processing' => 'confirmed',
            'on-hold'    => 'pending',
            'completed'  => 'delivered',
            'cancelled'  => 'canceled',
            'refunded'   => 'refunded',
            'failed'     => 'failed',
            default      => $wc_status,
        };
    }

    private static function format_variation_attributes( array $attributes ): array {
        $options = array();
        foreach ( $attributes as $key => $value ) {
            $name = str_replace( 'attribute_', '', $key );
            $name = str_replace( 'pa_', '', $name );
            $options[] = array( 'name' => ucfirst( $name ), 'value' => $value );
        }
        return $options;
    }
}
