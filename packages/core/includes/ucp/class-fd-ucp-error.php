<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Error {

    public static function response( string $code, string $message, int $http_status = 400 ): WP_REST_Response {
        return new WP_REST_Response(
            FD_UCP_Request_Context::current()->wire()->error( $code, $message ),
            $http_status
        );
    }
}
