<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Order_Controller {

    private const NAMESPACE = 'fd-ucp/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/orders/(?P<id>[A-Za-z0-9_\-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_order' ),
            'permission_callback' => array( 'FD_UCP_Plugin', 'require_platform' ),
        ) );
    }

    public function get_order( WP_REST_Request $request ): WP_REST_Response {
        $order = self::resolve( (string) $request->get_param( 'id' ) );
        if ( $order instanceof WP_REST_Response ) {
            return $order;
        }

        return new WP_REST_Response(
            FD_UCP_Formatter::format_order( $order ),
            200
        );
    }

    public static function resolve( string $order_key ): WC_Order|WP_REST_Response {
        $order = '' === $order_key ? false : wc_get_order( wc_get_order_id_by_order_key( $order_key ) );

        if ( ! $order || ! $order->get_meta( '_fd_ucp_handler_id' ) || ! FD_UCP_Ownership::owns_order( $order ) ) {
            return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
        }

        return $order;
    }
}
