<?php
/**
 * Plugin Name: Finance District Prism Payment
 * Plugin URI: https://developers.fd.xyz
 * Description: Stablecoin payment handler for WooCommerce UCP via Finance District Prism.
 * Version: 0.1.0
 * Author: Finance District (1st Digital)
 * Author URI: https://fd.xyz
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: fd-prism-for-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', 'fd_prism_init', 25); // after UCP plugin at priority 20

function fd_prism_init() {
    if (!class_exists('FD_UCP_Plugin')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>' . esc_html__( 'Finance District Prism', 'fd-prism-for-woocommerce' ) . '</strong> ' . esc_html__( 'requires the UCP plugin (fd-ucp-for-woocommerce) to be installed and active.', 'fd-prism-for-woocommerce' ) . '</p></div>';
        });
        return;
    }

    // Require files
    $dir = plugin_dir_path(__FILE__);
    require_once $dir . 'includes/prism/class-fd-prism-client.php';
    require_once $dir . 'includes/prism/class-fd-prism-validator.php';
    require_once $dir . 'includes/prism/class-fd-prism-handler.php';
    require_once $dir . 'includes/prism/class-fd-prism-gateway.php';
    require_once $dir . 'includes/admin/class-fd-prism-order-meta-box.php';

    // Register payment handler into UCP registry
    add_action('fd_ucp_register_payment_handlers', function(FD_Payment_Registry $registry) {
        $gateway = new FD_Prism_Gateway();
        $api_url = $gateway->api_url();
        $api_key = $gateway->api_key();
        if (!empty($api_url) && !empty($api_key)) {
            $handler = new FD_Prism_Handler($api_url, $api_key);
            $registry->register($handler);
        }
    });

    // Register WC gateway
    add_filter('woocommerce_payment_gateways', function(array $gateways) {
        $gateways[] = 'FD_Prism_Gateway';
        return $gateways;
    });

    // Admin meta box
    if (is_admin()) {
        FD_Prism_Order_Meta_Box::init();
    }
}
