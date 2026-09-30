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

$GLOBALS['fd_test_transients'] = array();
$GLOBALS['fd_test_options']    = array();

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) {
        return $GLOBALS['fd_test_transients'][ $key ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $ttl = 0 ): bool {
        $GLOBALS['fd_test_transients'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) {
        return $GLOBALS['fd_test_options'][ $key ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, $value, $autoload = null ): bool {
        $GLOBALS['fd_test_options'][ $key ] = $value;
        return true;
    }
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';

require_once $base . '/prism/class-fd-prism-validator.php';
require_once dirname( __DIR__, 2 ) . '/core/includes/payment/interface-fd-payment-handler.php';
require_once $base . '/prism/class-fd-prism-client.php';
require_once $base . '/prism/class-fd-prism-handler.php';
