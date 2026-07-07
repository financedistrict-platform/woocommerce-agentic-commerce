<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Plugin {

    private static ?FD_UCP_Plugin $instance = null;
    private FD_Payment_Registry $payment_registry;
    private string $store_name;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->payment_registry = new FD_Payment_Registry();
        $this->store_name       = get_bloginfo( 'name' );

        new FD_UCP_Discovery( $this->payment_registry );

        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        // Fire after all plugins_loaded callbacks have run, so payment handler
        // plugins (loaded at priority 25+) can hook in before this fires.
        add_action( 'init', array( $this, 'register_payment_handlers' ), 0 );
    }

    /**
     * Fires when UCP is ready for payment handlers to register.
     *
     * External plugins (e.g. fd-woocommerce-prism-payment) hook into
     * 'fd_ucp_register_payment_handlers' to call $registry->register().
     */
    public function register_payment_handlers(): void {
        do_action( 'fd_ucp_register_payment_handlers', $this->payment_registry );
    }

    public function register_rest_routes(): void {
        $catalog        = new FD_UCP_Catalog_Controller();
        $checkout       = new FD_UCP_Checkout_Controller( $this->payment_registry );
        $order          = new FD_UCP_Order_Controller();
        $returns        = new FD_UCP_Returns_Controller();
        $promotions     = new FD_UCP_Promotions_Controller();
        $buyer_identity = new FD_UCP_Buyer_Identity_Controller();
        $cart           = new FD_UCP_Cart_Controller();

        $catalog->register_routes();
        $checkout->register_routes();
        $order->register_routes();
        $returns->register_routes();
        $promotions->register_routes();
        $buyer_identity->register_routes();
        $cart->register_routes();
    }

    public function payment_registry(): FD_Payment_Registry {
        return $this->payment_registry;
    }

    public function store_name(): string {
        return $this->store_name;
    }
}
