<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PlatformOwnershipTest extends TestCase {

    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const ALPHA      = 'https://alpha.example/ucp';
    private const BETA       = 'https://beta.example/ucp';
    private const ALPHA_KEY  = 'alpha-registered-key';
    private const BETA_KEY   = 'beta-registered-key';

    private const PUBLIC_ROUTES = array( 'POST /catalog/search', 'POST /catalog/lookup' );

    private FD_Test_Wpdb $db;

    protected function setUp(): void {
        FD_Test_WP::reset();
        $this->db        = new FD_Test_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';

        FD_Test_Platform_Vectors::register( self::ALPHA_KEY, self::ALPHA );
        FD_Test_Platform_Vectors::register( self::BETA_KEY, self::BETA );

        $plugin   = FD_UCP_Plugin::instance();
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( $plugin, new FD_UCP_Version_Resolver(
            new FD_UCP_Version_Registry(),
            new FD_Test_Fixture_Profile_Fetcher( array(
                self::ALPHA => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ),
                self::BETA  => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ),
            ) )
        ) );

        FD_Test_Product_Store::$products = array( 101 => new FD_Test_Product( 101, '18.00' ) );
        FD_Test_WC::$rates               = array( new FD_Test_Shipping_Rate( 'flat_rate1', '4.95', 'Flat rate' ) );
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10.0 );
        $this->seed();
        FD_UCP_Plugin::instance()->register_rest_routes();
    }

    protected function tearDown(): void {
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( FD_UCP_Plugin::instance(), null );
        FD_Test_Product_Store::$products = array();
        FD_Test_Coupon_Store::reset();
        FD_Test_WC::$rates               = array();
        unset( $_SERVER['REMOTE_ADDR'] );
    }

    private function seed( string $platform = self::ALPHA ): void {
        $session                = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['platform_id'] = $platform;
        $session['ucp_version'] = null;
        $session['expires_at']  = gmdate( 'Y-m-d H:i:s', time() + 3600 );

        $this->db->sessions = array( self::SESSION_ID => $session );
        $this->db->carts    = array( self::SESSION_ID => array(
            'id'          => self::SESSION_ID,
            'line_items'  => json_encode( array() ),
            'platform_id' => $platform,
            'ucp_version' => null,
            'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
        ) );

        $order       = new WC_Order();
        $order->meta = array(
            '_fd_ucp_tx_reference' => '0xmine',
            '_fd_ucp_handler_id'   => 'xyz.fd.prism_payment',
            '_fd_ucp_platform_id'  => $platform,
        );
        $order->status                = 'processing';
        FD_Test_Order_Store::$orders  = array( 1001 => $order );
        FD_Test_Order_Store::$refunds = array();
        FD_Test_Order_Store::$created = 0;
    }

    private function dispatch( string $method, string $path, array $headers, array $params = array(), array $body = array() ): WP_REST_Response {
        $request = new WP_REST_Request( '/fd-ucp/v1' . $path, $headers, $params, $body, $method );
        $gated   = FD_UCP_Plugin::instance()->gate_request( null, null, $request );
        if ( $gated instanceof WP_REST_Response ) {
            return $gated;
        }
        foreach ( FD_Test_WP::$routes as $route ) {
            if ( preg_match( '#^' . $route['route'] . '$#', $path ) && in_array( $method, array_map( 'trim', explode( ',', $route['methods'] ) ), true ) ) {
                return call_user_func( $route['callback'], $request );
            }
        }
        $this->fail( "no route for $method $path" );
    }

    private static function as_alpha(): array {
        return array( 'UCP-Agent' => 'profile="' . self::ALPHA . '"', 'X-API-Key' => self::ALPHA_KEY );
    }

    private static function as_beta(): array {
        return array( 'UCP-Agent' => 'profile="' . self::BETA . '"', 'X-API-Key' => self::BETA_KEY );
    }

    private static function item_body(): array {
        return array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) );
    }

    private function registered_routes(): array {
        FD_Test_WP::$routes = array();
        FD_UCP_Plugin::instance()->register_rest_routes();

        $routes = array();
        foreach ( FD_Test_WP::$routes as $route ) {
            foreach ( array_map( 'trim', explode( ',', $route['methods'] ) ) as $method ) {
                $name = $method . ' ' . $route['route'];
                if ( ! in_array( $name, self::PUBLIC_ROUTES, true ) ) {
                    $routes[ $name ] = array( $method, $route['route'] );
                }
            }
        }
        return $routes;
    }

    private static function concrete( string $route ): array {
        $id = str_starts_with( $route, '/orders' ) ? 1001 : self::SESSION_ID;
        return array( preg_replace( '/\(\?P<id>[^)]+\)/', (string) $id, $route ), $id );
    }

    public function test_every_protected_route_refuses_a_request_without_credentials(): void {
        $routes = $this->registered_routes();
        $this->assertGreaterThanOrEqual( 15, count( $routes ), implode( ', ', array_keys( $routes ) ) );

        foreach ( $routes as $name => [ $method, $route ] ) {
            [ $path, $id ] = self::concrete( $route );

            $anonymous = $this->dispatch( $method, $path, array( 'UCP-Agent' => 'profile="' . self::ALPHA . '"' ), array( 'id' => $id ) );
            $bare      = $this->dispatch( $method, $path, array(), array( 'id' => $id ) );

            $this->assertSame( 401, $anonymous->get_status(), $name );
            $this->assertSame( 'signature_missing', $anonymous->get_data()['messages'][0]['code'] ?? null, $name );
            $this->assertSame( 400, $bare->get_status(), $name );
        }
        $this->assertSame( array(), $this->db->updates );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertArrayHasKey( self::SESSION_ID, $this->db->carts );
    }

    public function test_every_route_that_loads_a_row_hides_it_from_another_platform(): void {
        $checked = array();
        foreach ( $this->registered_routes() as $name => [ $method, $route ] ) {
            if ( ! str_contains( $route, '(?P<id>' ) ) {
                continue;
            }
            $this->seed();
            [ $path, $id ] = self::concrete( $route );

            $foreign = $this->dispatch( $method, $path, self::as_beta(), array( 'id' => $id ), array( 'code' => 'SAVE10', 'buyer' => array( 'first_name' => 'Eve' ), 'items' => array() ) );

            $this->assertSame( 404, $foreign->get_status(), $name . ' ' . json_encode( $foreign->get_data() ) );
            $this->assertSame( array(), $this->db->updates, $name );
            $this->assertSame( array(), FD_Test_Order_Store::$refunds, $name );
            $this->assertArrayHasKey( self::SESSION_ID, $this->db->carts, $name );
            $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'], $name );
            $checked[] = $name;
        }
        $this->assertGreaterThanOrEqual( 11, count( $checked ), implode( ', ', $checked ) );
    }

    public function test_the_owning_platform_reaches_its_rows_on_every_route_that_loads_one(): void {
        foreach ( $this->registered_routes() as $name => [ $method, $route ] ) {
            if ( ! str_contains( $route, '(?P<id>' ) ) {
                continue;
            }
            $this->seed();
            [ $path, $id ] = self::concrete( $route );

            $owner = $this->dispatch( $method, $path, self::as_alpha(), array( 'id' => $id ), array( 'code' => 'SAVE10', 'items' => array() ) );

            $this->assertNotSame( 404, $owner->get_status(), $name . ' ' . json_encode( $owner->get_data() ) );
            $this->assertNotSame( 401, $owner->get_status(), $name );
        }
    }

    public function test_rows_without_a_platform_are_unreachable_on_every_route(): void {
        foreach ( $this->registered_routes() as $name => [ $method, $route ] ) {
            if ( ! str_contains( $route, '(?P<id>' ) ) {
                continue;
            }
            foreach ( array( null, '' ) as $stored ) {
                $this->seed( '' );
                $this->db->sessions[ self::SESSION_ID ]['platform_id'] = $stored;
                $this->db->carts[ self::SESSION_ID ]['platform_id']    = $stored;
                FD_Test_Order_Store::$orders[1001]->meta = array_diff_key( FD_Test_Order_Store::$orders[1001]->meta, array( '_fd_ucp_platform_id' => 1 ) );
                [ $path, $id ] = self::concrete( $route );

                $response = $this->dispatch( $method, $path, self::as_alpha(), array( 'id' => $id ), array( 'code' => 'SAVE10', 'items' => array() ) );

                $this->assertSame( 404, $response->get_status(), $name );
            }
        }
    }

    public function test_the_same_idempotency_key_from_two_platforms_creates_two_sessions(): void {
        $headers = array( 'Idempotency-Key' => 'shared-key-1' );
        FD_Test_WP::$uuids = array( '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222' );

        $first  = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha() + $headers, array(), self::item_body() );
        $second = $this->dispatch( 'POST', '/checkout-sessions', self::as_beta() + $headers, array(), self::item_body() );
        $replay = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha() + $headers, array(), self::item_body() );

        $this->assertSame( 201, $first->get_status(), json_encode( $first->get_data() ) );
        $this->assertSame( 201, $second->get_status(), json_encode( $second->get_data() ) );
        $this->assertSame( 200, $replay->get_status(), json_encode( $replay->get_data() ) );
        $this->assertNotSame( $first->get_data()['id'], $second->get_data()['id'] );
        $this->assertSame( $first->get_data()['id'], $replay->get_data()['id'] );
    }

    public function test_a_replay_after_the_schema_upgrade_returns_the_stored_result(): void {
        $first = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha() + array( 'Idempotency-Key' => 'upgrade-key' ), array(), self::item_body() );
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.5.0';

        FD_UCP_Installer::maybe_upgrade();
        $replay = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha() + array( 'Idempotency-Key' => 'upgrade-key' ), array(), self::item_body() );

        $this->assertSame( 200, $replay->get_status(), json_encode( $replay->get_data() ) );
        $this->assertSame( $first->get_data()['id'], $replay->get_data()['id'] );
    }

    public function test_a_created_cart_session_and_order_carry_the_creating_platform(): void {
        $cart = $this->dispatch( 'POST', '/carts', self::as_alpha(), array(), self::item_body() );
        $this->assertSame( 201, $cart->get_status(), json_encode( $cart->get_data() ) );
        $cart_id = $cart->get_data()['id'];
        $this->assertSame( self::ALPHA, $this->db->carts[ $cart_id ]['platform_id'] );

        $foreign = $this->dispatch( 'POST', '/carts/' . $cart_id . '/checkout', self::as_beta(), array( 'id' => $cart_id ) );
        $checkout = $this->dispatch( 'POST', '/carts/' . $cart_id . '/checkout', self::as_alpha(), array( 'id' => $cart_id ) );

        $this->assertSame( 404, $foreign->get_status() );
        $this->assertSame( 201, $checkout->get_status(), json_encode( $checkout->get_data() ) );
        $this->assertSame( self::ALPHA, $this->db->sessions[ $checkout->get_data()['checkout_session_id'] ]['platform_id'] );
    }

    public function test_returns_still_answer_accepted_for_the_owning_platform(): void {
        $response = $this->dispatch( 'POST', '/orders/1001/returns', self::as_alpha(), array( 'id' => 1001 ), array( 'items' => array() ) );

        $this->assertSame( 202, $response->get_status(), json_encode( $response->get_data() ) );
    }

    public function test_the_return_rate_limit_still_applies(): void {
        $statuses = array();
        for ( $i = 0; $i < 11; $i++ ) {
            $statuses[] = $this->dispatch( 'POST', '/orders/1001/returns', self::as_alpha(), array( 'id' => 1001 ), array( 'items' => array() ) )->get_status();
        }

        $this->assertSame( 429, end( $statuses ) );
        $this->assertSame( 202, $statuses[0] );
    }

    public function test_catalog_routes_stay_public(): void {
        $routes = array();
        FD_Test_WP::$routes = array();
        FD_UCP_Plugin::instance()->register_rest_routes();
        foreach ( FD_Test_WP::$routes as $route ) {
            $routes[] = $route['methods'] . ' ' . $route['route'];
        }
        $this->assertContains( 'POST /catalog/search', $routes );
        $this->assertContains( 'POST /catalog/lookup', $routes );

        foreach ( array( '/catalog/search', '/catalog/lookup' ) as $path ) {
            $gated = FD_UCP_Plugin::instance()->gate_request( null, null, new WP_REST_Request( '/fd-ucp/v1' . $path, array(), array(), array(), 'POST' ) );
            $this->assertNull( $gated, $path );
        }
    }

    public function test_route_case_and_trailing_slash_cannot_skip_the_gate(): void {
        $agent = array( 'UCP-Agent' => 'profile="' . self::ALPHA . '"' );
        foreach ( array( '/FD-UCP/V1/checkout-sessions', '/Fd-Ucp/v1/carts', '/fd-ucp/V1/orders/1001', '/fd-ucp/v1/carts/', 'fd-ucp/v1/carts' ) as $route ) {
            $gated = FD_UCP_Plugin::instance()->gate_request( null, null, new WP_REST_Request( $route, $agent, array(), array(), 'POST' ) );

            $this->assertInstanceOf( WP_REST_Response::class, $gated, $route );
            $this->assertSame( 401, $gated->get_status(), $route );
        }
    }

    public function test_mixed_case_catalog_routes_stay_public(): void {
        foreach ( array( '/Fd-Ucp/V1/Catalog/SEARCH', '/FD-UCP/v1/catalog/lookup/' ) as $route ) {
            $this->assertNull( FD_UCP_Plugin::instance()->gate_request( null, null, new WP_REST_Request( $route, array(), array(), array(), 'POST' ) ), $route );
        }
    }

    public function test_every_protected_route_fails_closed_even_if_the_gate_is_skipped(): void {
        FD_UCP_Request_Context::set( null );
        $checked = 0;

        foreach ( FD_Test_WP::$routes as $route ) {
            if ( in_array( 'POST ' . $route['route'], self::PUBLIC_ROUTES, true ) ) {
                continue;
            }
            $this->assertNotNull( $route['permission_callback'], $route['route'] );

            $denied = call_user_func( $route['permission_callback'] );
            $this->assertInstanceOf( WP_Error::class, $denied, $route['route'] );
            $this->assertSame( 'signature_missing', $denied->get_error_code(), $route['route'] );

            FD_UCP_Request_Context::set( FD_UCP_Request_Context::current()->with_platform( self::ALPHA ) );
            $this->assertTrue( call_user_func( $route['permission_callback'] ), $route['route'] );
            FD_UCP_Request_Context::set( null );
            ++$checked;
        }

        $this->assertGreaterThanOrEqual( 15, $checked );
    }

    public function test_an_oversized_idempotency_key_is_refused_before_anything_is_created(): void {
        $response = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha() + array( 'Idempotency-Key' => str_repeat( 'k', 129 ) ), array(), self::item_body() );

        $this->assertSame( 400, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_a_session_that_cannot_be_stored_does_not_leave_its_quote_order_open(): void {
        FD_Test_Order_Store::$made = array();
        $this->db->fail_inserts    = true;

        $response = $this->dispatch( 'POST', '/checkout-sessions', self::as_alpha(), array(), self::item_body() );

        $this->assertSame( 503, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertCount( 1, FD_Test_Order_Store::$made );
        $this->assertSame( 'cancelled', FD_Test_Order_Store::$made[0]->status );
    }
}
