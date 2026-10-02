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

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = array() ) {
        $GLOBALS['fd_test_requests'][] = array( 'url' => $url, 'args' => $args );
        return $GLOBALS['fd_test_http_response'] ?? array( 'body' => '', 'response' => array( 'code' => 500 ) );
    }
}

if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( string $url, array $args = array() ) {
        $GLOBALS['fd_test_requests'][] = array( 'url' => $url, 'args' => $args );
        return $GLOBALS['fd_test_http_response'] ?? array( 'body' => '', 'response' => array( 'code' => 500 ) );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string {
        return $response['body'];
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ): int {
        return $response['response']['code'];
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $flags = 0 ) {
        return json_encode( $data, $flags );
    }
}

if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger(): object {
        return new class() {
            public function __call( string $name, array $args ): void {
            }
        };
    }
}

if ( ! defined( 'FD_PRISM_VERSION' ) ) {
    preg_match( "/define\( 'FD_PRISM_VERSION', '([^']+)' \)/", file_get_contents( dirname( __DIR__ ) . '/fd-woocommerce-prism.php' ), $fd_prism_version );
    define( 'FD_PRISM_VERSION', $fd_prism_version[1] );
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';
$core = dirname( __DIR__, 2 ) . '/core/includes';

require_once $base . '/prism/class-fd-prism-validator.php';
require_once $core . '/payment/interface-fd-payment-handler.php';
require_once $core . '/payment/interface-fd-versioned-payment-handler.php';
require_once $core . '/payment/class-fd-payment-registry.php';
require_once $core . '/ucp/interface-fd-ucp-wire-format.php';
require_once $core . '/ucp/wire/class-fd-ucp-wire-base.php';
require_once $core . '/ucp/wire/class-fd-ucp-wire-20260825.php';
require_once $core . '/ucp/wire/class-fd-ucp-wire-20260408.php';
require_once $core . '/ucp/wire/class-fd-ucp-wire-20260123.php';
require_once $core . '/ucp/class-fd-ucp-version-registry.php';
require_once $core . '/ucp/class-fd-ucp-request-context.php';
require_once $base . '/prism/class-fd-prism-client.php';
require_once $base . '/prism/class-fd-prism-handler.php';
