<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Session_Token {

    public const HEADER     = 'UCP-Session-Token';
    public const COLUMN     = 'session_token_hash';
    public const ORDER_META = '_fd_ucp_session_token_hash';

    public static function issue(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    public static function hash( string $token ): string {
        return hash( 'sha256', $token );
    }

    public static function presented_hash( WP_REST_Request $request ): ?string {
        $token = (string) $request->get_header( self::HEADER );
        return '' === $token ? null : self::hash( $token );
    }

    public static function matches( WP_REST_Request $request, mixed $stored_hash ): bool {
        $presented = self::presented_hash( $request );
        if ( null === $presented || ! is_string( $stored_hash ) || '' === $stored_hash ) {
            return false;
        }
        return hash_equals( $stored_hash, $presented );
    }

    public static function owns_row( WP_REST_Request $request, array $row ): bool {
        return self::matches( $request, $row[ self::COLUMN ] ?? null );
    }

    public static function owns_order( WP_REST_Request $request, WC_Order $order ): bool {
        return self::matches( $request, $order->get_meta( self::ORDER_META ) );
    }

    public static function hand_over( WP_REST_Response $response, string $token ): WP_REST_Response {
        $response->header( self::HEADER, $token );
        return $response;
    }
}
