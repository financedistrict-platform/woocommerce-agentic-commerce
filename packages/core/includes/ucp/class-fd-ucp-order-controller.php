<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Order_Controller {

    private const NAMESPACE = 'fd-ucp/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/orders', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'list_orders' ),
            'permission_callback' => array( 'FD_UCP_Plugin', 'require_platform' ),
        ) );

        register_rest_route( self::NAMESPACE, '/orders/(?P<id>[\d]+)', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_order' ),
            'permission_callback' => array( 'FD_UCP_Plugin', 'require_platform' ),
        ) );
    }

    public function list_orders( WP_REST_Request $request ): WP_REST_Response {
        $platform_id = FD_UCP_Request_Context::current()->platform_id();
        if ( '' === $platform_id ) {
            return FD_UCP_Error::response( 'signature_missing', 'A verified platform is required to list orders', 401 );
        }
        $limit  = min( (int) ( $request->get_param( 'limit' ) ?? 20 ), 50 );
        $offset = max( (int) ( $request->get_param( 'offset' ) ?? 0 ), 0 );

        $orders = wc_get_orders( array(
            'limit'      => $limit,
            'offset'     => $offset,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => array(
                array(
                    'key'     => '_fd_ucp_handler_id',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key'   => FD_UCP_Ownership::ORDER_META,
                    'value' => $platform_id,
                ),
            ),
        ) );

        $formatted = array_map(
            function ( $order ) {
                return array(
                    'id'         => (string) $order->get_id(),
                    'label'      => $order->get_order_number(),
                    'status'     => $order->get_status(),
                    'total'      => FD_UCP_Formatter::to_minor( (float) $order->get_total() ),
                    'currency'   => $order->get_currency(),
                    'created_at' => $order->get_date_created()?->format( 'c' ) ?? '',
                    'transaction_reference' => $order->get_meta( '_fd_ucp_tx_reference' ),
                );
            },
            $orders
        );

        return new WP_REST_Response( array(
            'ucp' => FD_UCP_Request_Context::current()->wire()->envelope( array( 'order' ) ),
            'orders'     => $formatted,
            'pagination' => array(
                'limit'  => $limit,
                'offset' => $offset,
            ),
        ), 200 );
    }

    public function get_order( WP_REST_Request $request ): WP_REST_Response {
        $order = wc_get_order( (int) $request->get_param( 'id' ) );
        if ( ! $order ) {
            return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
        }

        if ( ! $order->get_meta( '_fd_ucp_handler_id' ) ) {
            return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
        }

        if ( ! FD_UCP_Ownership::owns_order( $order ) ) {
            return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
        }

        return new WP_REST_Response(
            FD_UCP_Formatter::format_order( $order ),
            200
        );
    }
}
