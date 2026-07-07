<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Catalog_Controller {

    private const NAMESPACE = 'fd-ucp/v1';

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/catalog/search', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'search' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, '/catalog/lookup', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'lookup' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function search( WP_REST_Request $request ): WP_REST_Response {
        $body  = $request->get_json_params();
        $query = sanitize_text_field( $body['query'] ?? $body['q'] ?? '' );
        $limit = min( (int) ( $body['limit'] ?? 10 ), 50 );
        $offset = max( (int) ( $body['offset'] ?? 0 ), 0 );

        $args = array(
            'status'  => 'publish',
            'limit'   => $limit,
            'offset'  => $offset,
        );

        if ( $query ) {
            $args['s'] = $query;
        } else {
            $args['orderby'] = 'date';
            $args['order']   = 'DESC';
        }

        $products = wc_get_products( $args );

        $formatted = array_map(
            array( 'FD_UCP_Formatter', 'format_product' ),
            $products
        );

        $count_args            = $args;
        $count_args['limit']   = -1;
        $count_args['return']  = 'ids';
        $total                 = count( wc_get_products( $count_args ) );

        return new WP_REST_Response( array(
            'ucp'        => array(
                'version'      => '2026-04-08',
                'status'       => 'success',
                'capabilities' => array(
                    'dev.ucp.shopping.catalog.search' => array( array( 'version' => '2026-04-08' ) ),
                ),
            ),
            'products'   => $formatted,
            'pagination' => array(
                'total_count'   => $total,
                'has_next_page' => ( $offset + $limit ) < $total,
            ),
            'messages'   => array(),
        ), 200 );
    }

    public function lookup( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();
        $ids  = $body['ids'] ?? array();

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return FD_UCP_Error::response( 'missing_ids', 'ids array is required', 400 );
        }

        $products = array();
        foreach ( $ids as $id ) {
            $product = wc_get_product( (int) $id );
            if ( $product && $product->is_visible() ) {
                $products[] = FD_UCP_Formatter::format_product( $product );
            }
        }

        return new WP_REST_Response( array(
            'ucp'      => array(
                'version'      => '2026-04-08',
                'status'       => 'success',
                'capabilities' => array(
                    'dev.ucp.shopping.catalog.lookup' => array( array( 'version' => '2026-04-08' ) ),
                ),
            ),
            'products' => $products,
            'messages' => array(),
        ), 200 );
    }
}
