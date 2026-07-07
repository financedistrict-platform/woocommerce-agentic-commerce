<?php
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Payment Gateway for Prism x402 stablecoin payments.
 *
 * This gateway is minimal — it exists so WooCommerce recognizes Prism as a payment
 * method and provides admin settings. The actual payment flow is handled by the UCP
 * checkout controller, not by WooCommerce's standard checkout.
 */
class FD_Prism_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'fd_prism_x402';
        $this->has_fields         = false;
        $this->method_title       = __( 'Prism Stablecoin', 'fd-prism-for-woocommerce' );
        $this->method_description = __( 'Accept AI agent payments with stablecoins through Finance District Prism.', 'fd-prism-for-woocommerce' );
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Prism Stablecoin', 'fd-prism-for-woocommerce' ) );
        $this->description = $this->get_option( 'description', __( 'Pay with stablecoins via AI agent wallet.', 'fd-prism-for-woocommerce' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'     => array(
                'title'   => __( 'Enable/Disable', 'fd-prism-for-woocommerce' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable Prism stablecoin payments', 'fd-prism-for-woocommerce' ),
                'default' => 'yes',
            ),
            'title'       => array(
                'title'   => __( 'Title', 'fd-prism-for-woocommerce' ),
                'type'    => 'text',
                'default' => __( 'Prism Stablecoin', 'fd-prism-for-woocommerce' ),
            ),
            'description' => array(
                'title'   => __( 'Description', 'fd-prism-for-woocommerce' ),
                'type'    => 'textarea',
                'default' => __( 'Pay with stablecoins via AI agent wallet.', 'fd-prism-for-woocommerce' ),
            ),
            'store_name'  => array(
                'title'       => __( 'Store Name', 'fd-prism-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Name shown to AI agents in UCP discovery.', 'fd-prism-for-woocommerce' ),
                'default'     => get_bloginfo( 'name' ),
            ),
            'api_url'     => array(
                'title'       => __( 'Prism Gateway URL', 'fd-prism-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Prism Gateway API base URL (e.g. https://prism-gw.fd.xyz).', 'fd-prism-for-woocommerce' ),
                'default'     => '',
                'placeholder' => 'https://prism-gw.fd.xyz',
            ),
            'api_key'     => array(
                'title'       => __( 'Prism API Key', 'fd-prism-for-woocommerce' ),
                'type'        => 'password',
                'description' => __( 'Your API key from the Prism Console.', 'fd-prism-for-woocommerce' ),
                'default'     => '',
            ),
        );
    }

    /**
     * No-op — settlement happens via UCP checkout-sessions/complete, not WC checkout.
     */
    public function process_payment( $order_id ): array {
        return array(
            'result'   => 'success',
            'redirect' => '',
        );
    }

    public function api_url(): string {
        return $this->get_option( 'api_url', '' );
    }

    public function api_key(): string {
        return $this->get_option( 'api_key', '' );
    }

    public function store_name(): string {
        return $this->get_option( 'store_name', get_bloginfo( 'name' ) );
    }
}
