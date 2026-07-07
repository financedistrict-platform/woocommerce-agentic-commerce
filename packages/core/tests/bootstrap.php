<?php
/**
 * Domain test bootstrap — stubs WordPress/WooCommerce functions
 * so pure-logic classes can be tested without a running WP instance.
 */

define( 'ABSPATH', '/tmp/wp/' );

// Minimal WP_Error stub
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( wp_strip_all_tags( $str ) );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $str ): string {
        return preg_replace( '/<[^>]*>/', '', $str );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

// Minimal WP_REST_Response stub
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public array $data;
        public int $status;

        public function __construct( $data = null, int $status = 200 ) {
            $this->data   = $data ?? array();
            $this->status = $status;
        }

        public function get_data(): array {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';

require_once $base . '/ucp/class-fd-ucp-address.php';
require_once $base . '/ucp/class-fd-ucp-status.php';
require_once $base . '/ucp/class-fd-ucp-error.php';
