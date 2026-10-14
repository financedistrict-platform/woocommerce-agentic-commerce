<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Session_Lock {

    public static function run( string $session_id, string $busy_code, string $busy_message, callable $fn ): WP_REST_Response {
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
}
