<?php
/**
 * Plugin Name: Finance District UCP
 * Plugin URI: https://developers.fd.xyz
 * Description: Universal Commerce Protocol (UCP) endpoints for WooCommerce. Makes your store discoverable and purchasable by AI agents. Payment handlers are registered by separate plugins.
 * Version: 0.1.0
 * Author: Finance District (1st Digital)
 * Author URI: https://fd.xyz
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.6
 * Text Domain: fd-ucp-for-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FD_UCP_VERSION', '0.1.0' );
define( 'FD_UCP_PLUGIN_FILE', __FILE__ );
define( 'FD_UCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FD_UCP_DB_VERSION', '1.0.0' );

require_once FD_UCP_PLUGIN_DIR . 'includes/class-fd-ucp-installer.php';

register_activation_hook( __FILE__, 'fd_ucp_activate' );
register_deactivation_hook( __FILE__, array( 'FD_UCP_Installer', 'deactivate' ) );

function fd_ucp_activate() {
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-discovery.php';
    FD_UCP_Installer::install();
}

add_action( 'plugins_loaded', 'fd_ucp_init', 20 );

function fd_ucp_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>' . esc_html__( 'Finance District UCP', 'fd-ucp-for-woocommerce' ) . '</strong> ' . esc_html__( 'requires WooCommerce to be installed and active.', 'fd-ucp-for-woocommerce' ) . '</p></div>';
        } );
        return;
    }

    require_once FD_UCP_PLUGIN_DIR . 'includes/payment/interface-fd-payment-handler.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/payment/class-fd-payment-registry.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-error.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-address.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-status.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-formatter.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-discovery.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-catalog-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-checkout-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-order-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-returns-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-promotions-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-buyer-identity-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/ucp/class-fd-ucp-cart-controller.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/class-fd-rate-limiter.php';
    require_once FD_UCP_PLUGIN_DIR . 'includes/class-fd-ucp-plugin.php';

    FD_UCP_Plugin::instance();

    // Map fd-ucp source type to a readable label in the WC Orders "Origin" column.
    add_filter( 'wc_order_attribution_origin_formatted_source', function ( string $formatted, string $source ): string {
        return 'fd-ucp' === $source ? 'AI Agent (UCP)' : $formatted;
    }, 10, 2 );
    add_filter( 'wc_order_attribution_origin_label', function ( string $label, string $source_type ): string {
        return 'fd-ucp' === $source_type ? '' : $label;
    }, 10, 2 );
}
