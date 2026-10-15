<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Ownership {

    public const ORDER_META        = '_fd_ucp_platform_id';
    public const ORDER_SECRET_META = '_fd_ucp_session_secret_hash';

    public static function presented( WP_REST_Request $request ): string {
        return trim( (string) $request->get_header( 'ucp-session-secret' ) );
    }

    public static function secret_required(): WP_REST_Response {
        return FD_UCP_Error::response( 'session_secret_required', 'Send the UCP-Session-Secret header returned when this resource was created', 401 );
    }

    public static function owns( array $row, string $column, string $presented ): bool {
        return FD_UCP_Session_Secret::matches( (string) ( $row[ $column ] ?? '' ), $presented );
    }

    public static function owns_order( WC_Order $order, string $presented ): bool {
        return FD_UCP_Session_Secret::matches( (string) $order->get_meta( self::ORDER_SECRET_META ), $presented );
    }
}
