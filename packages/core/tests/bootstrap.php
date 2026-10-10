<?php
/**
 * Domain test bootstrap — stubs WordPress/WooCommerce functions
 * so pure-logic classes can be tested without a running WP instance.
 */

define( 'ABSPATH', __DIR__ . '/support/wp/' );

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
        public $data;
        public int $status;
        public array $headers = array();

        public function __construct( $data = null, int $status = 200 ) {
            $this->data   = $data ?? array();
            $this->status = $status;
        }

        public function header( string $key, string $value ): void {
            $this->headers[ $key ] = $value;
        }

        public function get_headers(): array {
            return $this->headers;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

require_once __DIR__ . '/support/wp-test-stubs.php';
require_once __DIR__ . '/golden/capture-stubs.php';

if ( ! defined( 'FD_UCP_VERSION' ) ) {
    preg_match( "/define\( 'FD_UCP_VERSION', '([^']+)' \)/", file_get_contents( dirname( __DIR__ ) . '/fd-woocommerce-ucp.php' ), $fd_ucp_version );
    define( 'FD_UCP_VERSION', $fd_ucp_version[1] );
}

if ( ! defined( 'FD_UCP_DB_VERSION' ) ) {
    preg_match( "/define\( 'FD_UCP_DB_VERSION', '([^']+)' \)/", file_get_contents( dirname( __DIR__ ) . '/fd-woocommerce-ucp.php' ), $fd_ucp_db_version );
    define( 'FD_UCP_DB_VERSION', $fd_ucp_db_version[1] );
}

// Load testable domain classes
$base = dirname( __DIR__ ) . '/includes';

require_once $base . '/class-fd-ucp-installer.php';
require_once $base . '/ucp/class-fd-ucp-address.php';
require_once $base . '/ucp/class-fd-ucp-status.php';
require_once $base . '/payment/interface-fd-payment-handler.php';
require_once $base . '/payment/interface-fd-versioned-payment-handler.php';
require_once $base . '/payment/class-fd-payment-claims.php';
require_once $base . '/payment/class-fd-payment-registry.php';
require_once $base . '/ucp/interface-fd-ucp-wire-format.php';
require_once $base . '/ucp/wire/class-fd-ucp-wire-base.php';
require_once $base . '/ucp/wire/class-fd-ucp-wire-20260825.php';
require_once $base . '/ucp/wire/class-fd-ucp-wire-20260408.php';
require_once $base . '/ucp/wire/class-fd-ucp-wire-20260123.php';
require_once $base . '/ucp/class-fd-ucp-version-registry.php';
require_once $base . '/ucp/class-fd-ucp-request-context.php';
require_once $base . '/ucp/class-fd-ucp-agent-profile-fetcher.php';
require_once $base . '/ucp/class-fd-ucp-version-resolver.php';
require_once $base . '/ucp/class-fd-ucp-platform-auth.php';
require_once $base . '/ucp/class-fd-ucp-error.php';
require_once $base . '/ucp/class-fd-ucp-ownership.php';
require_once $base . '/ucp/class-fd-ucp-formatter.php';
require_once $base . '/ucp/class-fd-ucp-discovery.php';
require_once $base . '/ucp/class-fd-ucp-cart-controller.php';
require_once $base . '/class-fd-rate-limiter.php';
require_once $base . '/ucp/class-fd-ucp-checkout-pricing.php';
require_once $base . '/ucp/class-fd-ucp-coupon-rules.php';
require_once $base . '/ucp/class-fd-ucp-catalog-controller.php';
require_once $base . '/ucp/class-fd-ucp-checkout-controller.php';
require_once $base . '/ucp/class-fd-ucp-order-controller.php';
require_once $base . '/ucp/class-fd-ucp-returns-controller.php';
require_once $base . '/ucp/class-fd-ucp-buyer-identity-controller.php';
require_once $base . '/ucp/class-fd-ucp-promotions-controller.php';
require_once $base . '/class-fd-ucp-plugin.php';
require_once $base . '/admin/class-fd-ucp-key-vault.php';
require_once $base . '/admin/class-fd-ucp-settings.php';

$prism = dirname( __DIR__, 2 ) . '/prism-payment';
if ( ! defined( 'FD_PRISM_VERSION' ) ) {
    preg_match( "/define\( 'FD_PRISM_VERSION', '([^']+)' \)/", file_get_contents( $prism . '/fd-woocommerce-prism.php' ), $fd_prism_version );
    define( 'FD_PRISM_VERSION', $fd_prism_version[1] );
}
require_once $prism . '/includes/prism/class-fd-prism-client.php';
require_once $prism . '/includes/prism/class-fd-prism-validator.php';
require_once $prism . '/includes/prism/class-fd-prism-handler.php';

require_once dirname( __DIR__, 2 ) . '/dummy-payment/fd-woocommerce-dummy-payment.php';

require_once __DIR__ . '/support/golden-renderer.php';
require_once __DIR__ . '/support/fixture-profile-fetcher.php';
require_once __DIR__ . '/support/platform-vectors.php';
