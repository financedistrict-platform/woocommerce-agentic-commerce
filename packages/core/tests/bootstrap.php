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

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return 'Test Store';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ): bool {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ): bool {
        return true;
    }
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';

require_once $base . '/ucp/class-fd-ucp-address.php';
require_once $base . '/ucp/class-fd-ucp-status.php';
require_once $base . '/ucp/class-fd-ucp-error.php';
require_once $base . '/ucp/class-fd-ucp-formatter.php';
require_once $base . '/ucp/class-fd-ucp-discovery.php';
require_once $base . '/payment/interface-fd-payment-handler.php';
require_once $base . '/payment/class-fd-payment-registry.php';
require_once $base . '/class-fd-ucp-plugin.php';
