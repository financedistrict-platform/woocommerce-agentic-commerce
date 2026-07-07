<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Error {

    public static function response( string $code, string $message, int $http_status = 400 ): WP_REST_Response {
        $body = array(
            'ucp'      => array(
                'version' => '2026-04-08',
                'status'  => 'error',
            ),
            'messages' => array(
                array(
                    'type'     => 'error',
                    'code'     => $code,
                    'content'  => $message,
                    'severity' => 'fatal',
                ),
            ),
        );
        return new WP_REST_Response( $body, $http_status );
    }
}
