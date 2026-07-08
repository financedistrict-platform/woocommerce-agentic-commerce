<?php
defined( 'ABSPATH' ) || exit;

class FD_Rate_Limiter {

    private int $max_requests;
    private int $window_seconds;

    public function __construct( int $max_requests = 60, int $window_seconds = 60 ) {
        $this->max_requests   = $max_requests;
        $this->window_seconds = $window_seconds;
    }

    public function check( string $action = 'default' ): true|WP_Error {
        global $wpdb;

        $ip    = self::get_client_ip();
        $key   = 'fd_rl_' . md5( $action . '|' . $ip );
        $lock  = 'fd_rl_lock_' . md5( $key );
        $now   = time();

        // Acquire a MySQL advisory lock (1s timeout) to make read-check-write atomic.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $got_lock = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock ) );

        if ( '1' !== (string) $got_lock ) {
            return new WP_Error( 'rate_limited', 'Too many requests. Try again later.' );
        }

        try {
            $data = get_transient( $key );

            if ( false === $data ) {
                set_transient( $key, array( 'count' => 1, 'start' => $now ), $this->window_seconds );
                return true;
            }

            if ( $data['count'] >= $this->max_requests ) {
                $retry_after = $this->window_seconds - ( $now - $data['start'] );
                return new WP_Error(
                    'rate_limited',
                    'Too many requests. Try again in ' . max( 1, $retry_after ) . ' seconds.'
                );
            }

            $data['count']++;
            $remaining_ttl = $this->window_seconds - ( $now - $data['start'] );
            if ( $remaining_ttl > 0 ) {
                set_transient( $key, $data, $remaining_ttl );
            }

            return true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function get_client_ip(): string {
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        return '0.0.0.0';
    }
}
