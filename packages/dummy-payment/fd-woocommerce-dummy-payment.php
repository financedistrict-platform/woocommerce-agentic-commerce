<?php
/**
 * Plugin Name: Finance District Dummy Payment
 * Plugin URI: https://developers.fd.xyz
 * Description: Dummy payment handler for testing UCP checkout flows without real settlement.
 * Version: 0.1.0
 * Author: Finance District (1st Digital)
 * Author URI: https://fd.xyz
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: fd-dummy-for-woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /languages
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', 'fd_dummy_payment_init', 25);

function fd_dummy_payment_init() {
    if (!class_exists('FD_UCP_Plugin')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>' . esc_html__( 'Finance District Dummy Payment', 'fd-dummy-for-woocommerce' ) . '</strong> ' . esc_html__( 'requires the UCP plugin (fd-ucp-for-woocommerce) to be installed and active.', 'fd-dummy-for-woocommerce' ) . '</p></div>';
        });
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'includes/class-fd-dummy-handler.php';

    add_action('fd_ucp_register_payment_handlers', function(FD_Payment_Registry $registry) {
        $registry->register(new FD_Dummy_Handler());
    });
}
