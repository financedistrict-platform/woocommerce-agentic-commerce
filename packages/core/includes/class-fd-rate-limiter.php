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
        $ip  = self::get_client_ip();
        $key = 'fd_rl_' . md5( $action . '|' . $ip );

        $data = get_transient( $key );
        $now  = time();

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
    }

    private static function get_client_ip(): string {
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        return '0.0.0.0';
    }
}
