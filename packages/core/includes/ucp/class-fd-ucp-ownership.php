<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Ownership {

    public const ORDER_META = '_fd_ucp_platform_id';

    public static function assert( string $row_platform, string $ctx_platform ): bool {
        return '' !== $row_platform && '' !== $ctx_platform && hash_equals( $row_platform, $ctx_platform );
    }

    public static function owns_row( array $row ): bool {
        return self::assert( (string) ( $row['platform_id'] ?? '' ), FD_UCP_Request_Context::current()->platform_id() );
    }

    public static function owns_order( WC_Order $order ): bool {
        return self::assert( (string) $order->get_meta( self::ORDER_META ), FD_UCP_Request_Context::current()->platform_id() );
    }
}
