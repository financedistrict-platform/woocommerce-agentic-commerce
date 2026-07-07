<?php
/**
 * Domain test bootstrap — stubs WordPress functions
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

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';

require_once $base . '/prism/class-fd-prism-validator.php';
