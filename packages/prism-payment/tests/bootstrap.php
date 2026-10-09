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

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return 'Test Store';
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $tag, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
        $GLOBALS['fd_test_actions'][ $tag ][] = $callback;
        return true;
    }
}

if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( string $message, string $type = 'success' ): void {
        $GLOBALS['fd_test_notices'][] = array( 'message' => $message, 'type' => $type );
    }
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
    abstract class WC_Payment_Gateway {
        public $id                 = '';
        public $has_fields         = false;
        public $method_title       = '';
        public $method_description = '';
        public $supports           = array();
        public $form_fields        = array();
        public $title              = '';
        public $description        = '';
        public $enabled            = 'yes';
        protected $settings        = array();

        public function init_settings(): void {
            $this->settings = $GLOBALS['fd_test_options'][ 'woocommerce_' . $this->id . '_settings' ] ?? array();
        }

        public function get_option( $key, $empty_value = null ) {
            return $this->settings[ $key ] ?? ( $this->form_fields[ $key ]['default'] ?? $empty_value );
        }

        public function is_available() {
            return 'yes' === $this->enabled;
        }

        public function process_admin_options() {
        }
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type, $gmt = 0 ): string {
        return '2026-04-20 10:07:00';
    }
}

if ( ! class_exists( 'FD_Test_Claims_Wpdb' ) ) {
    final class FD_Test_Claims_Wpdb {
        public string $prefix = 'wp_';
        public array $claims  = array();
        public bool $broken   = false;

        public function prepare( string $query, ...$args ): string {
            return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
        }

        public string $last_error = '';

        public function query( string $query ): int|false {
            if ( $this->broken ) {
                $this->last_error = 'store unavailable';
                return false;
            }
            preg_match( "/VALUES \('([0-9a-f]+)', '[^']*', '([^']*)'/", $query, $m );
            if ( isset( $this->claims[ $m[1] ] ) ) {
                return 0;
            }
            $this->claims[ $m[1] ] = $m[2];
            return 1;
        }

        public function get_var( string $query ) {
            preg_match( "/claim_key = '([0-9a-f]+)'/", $query, $m );
            return $this->broken ? null : ( $this->claims[ $m[1] ?? '' ] ?? null );
        }
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
require_once $core . '/payment/class-fd-payment-claims.php';
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
require_once $base . '/prism/class-fd-prism-gateway.php';
