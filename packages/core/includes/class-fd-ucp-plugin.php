<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Plugin {

    private static ?FD_UCP_Plugin $instance = null;
    private const REST_PREFIX = '/fd-ucp/v1';

    private const PUBLIC_ROUTES = array(
        '/fd-ucp/v1/catalog/search',
        '/fd-ucp/v1/catalog/lookup',
    );

    private const VERSIONED_ROUTES = array(
        '/fd-ucp/v1/carts'   => 'cart',
        '/fd-ucp/v1/catalog' => 'catalog.search',
    );

    private FD_Payment_Registry $payment_registry;
    private string $store_name;
    private ?FD_UCP_Version_Resolver $resolver = null;
    private ?FD_UCP_Agent_Profile_Fetcher $fetcher = null;
    private ?FD_UCP_Platform_Auth $platform_auth = null;

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
        add_filter( 'rest_pre_dispatch', array( $this, 'gate_request' ), 10, 3 );
        add_action( 'admin_notices', array( $this, 'configuration_notice' ) );

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
        $promotions     = new FD_UCP_Promotions_Controller( $checkout );
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

    public function gate_request( $result, $server, WP_REST_Request $request ) {
        $route = self::canonical_route( $request->get_route() );
        if ( null !== $result || 0 !== strpos( $route, self::REST_PREFIX ) ) {
            return $result;
        }

        $versions = FD_UCP_Version_Registry::from_options();
        FD_UCP_Request_Context::set( new FD_UCP_Request_Context( $versions, $versions->current_wire()->version() ) );

        $invalid = $versions->assert_valid();
        if ( null !== $invalid ) {
            return FD_UCP_Error::response( 'configuration_invalid', 'UCP configuration is invalid', 500 );
        }

        $platform_id = '';
        if ( ! in_array( $route, self::PUBLIC_ROUTES, true ) ) {
            $auth = $this->platform_auth()->authenticate( $request );
            if ( isset( $auth['error'] ) ) {
                return FD_UCP_Error::response( $auth['error']['code'], $auth['error']['message'], $auth['error']['status'] );
            }
            $platform_id = $auth['platform_id'];
        }

        $context = $this->resolver()->resolve( $request->get_header( 'ucp-agent' ) );
        if ( null !== $context->rejection() ) {
            return $context->rejection_response();
        }
        $context = $context->with_platform( $platform_id );
        FD_UCP_Request_Context::set( $context );

        foreach ( self::VERSIONED_ROUTES as $prefix => $capability ) {
            if ( 0 === strpos( $route, $prefix ) && ! $context->wire()->supports( $capability ) ) {
                return FD_UCP_Error::response( 'capabilities_incompatible', 'This capability is not available in UCP version ' . $context->version(), 404 );
            }
        }

        return $result;
    }

    public static function canonical_route( string $route ): string {
        return '/' . trim( strtolower( $route ), '/' );
    }

    public static function require_platform() {
        if ( '' === FD_UCP_Request_Context::current()->platform_id() ) {
            return new WP_Error( 'signature_missing', 'A verified platform is required', array( 'status' => 401 ) );
        }
        return true;
    }

    public function pin_session( WP_REST_Request $request, ?string $pinned ): ?WP_REST_Response {
        $context = $this->resolver()->resolve( $request->get_header( 'ucp-agent' ), $pinned );
        if ( null !== $context->rejection() ) {
            return $context->rejection_response();
        }
        FD_UCP_Request_Context::set( $context->with_platform( FD_UCP_Request_Context::current()->platform_id() ) );
        return null;
    }

    public function resolver(): FD_UCP_Version_Resolver {
        if ( null === $this->resolver ) {
            $this->resolver = new FD_UCP_Version_Resolver( FD_UCP_Version_Registry::from_options(), $this->fetcher() );
        }
        return $this->resolver;
    }

    public function platform_auth(): FD_UCP_Platform_Auth {
        if ( null === $this->platform_auth ) {
            $this->platform_auth = new FD_UCP_Platform_Auth( $this->fetcher() );
        }
        return $this->platform_auth;
    }

    private function fetcher(): FD_UCP_Agent_Profile_Fetcher {
        if ( null === $this->fetcher ) {
            $this->fetcher = new FD_UCP_Agent_Profile_Fetcher();
        }
        return $this->fetcher;
    }

    public function configuration_notice(): void {
        $invalid = FD_UCP_Version_Registry::from_options()->assert_valid();
        if ( null === $invalid ) {
            return;
        }
        echo '<div class="error"><p><strong>' . esc_html__( 'Finance District UCP', 'fd-ucp-for-woocommerce' ) . '</strong> ' . esc_html( $invalid ) . '</p></div>';
    }

    public function payment_registry(): FD_Payment_Registry {
        return $this->payment_registry;
    }

    public function store_name(): string {
        return $this->store_name;
    }
}
