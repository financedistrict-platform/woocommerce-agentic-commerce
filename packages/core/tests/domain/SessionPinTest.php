<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class SessionPinTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public function test_pinned_session_with_unreachable_profile_keeps_its_version(): void {
        $context = VersionResolverTest::resolve_with( null, 'lenient', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

        $this->assertNull( $context->rejection() );
        $this->assertSame( '2026-08-25', $context->version() );
        $this->assertSame( 'unreachable', $context->outcome() );
    }

    public function test_pinned_session_with_undeclared_profile_keeps_its_version(): void {
        foreach ( array( null ) as $declared ) {
            $context = VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( $declared ), 'lenient', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

            $this->assertNull( $context->rejection() );
            $this->assertSame( '2026-08-25', $context->version() );
        }
    }

    public function test_pinned_session_with_matched_different_version_is_rejected(): void {
        $context = VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ), 'lenient', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

        $this->assertSame( 422, $context->rejection()['status'] );
        $this->assertSame( 'version_unsupported', $context->rejection()['code'] );
        $this->assertSame( 'This session is bound to UCP version 2026-08-25; the agent profile now declares 2026-04-08.', $context->rejection()['message'] );
    }

    public function test_pinned_session_with_unknown_profile_is_rejected(): void {
        $context = VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-01-11' ), 'lenient', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

        $this->assertSame( 422, $context->rejection()['status'] );
        $this->assertSame( 'version_unsupported', $context->rejection()['code'] );
    }

    public function test_pinned_session_with_matched_same_version_is_served(): void {
        $context = VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ), 'lenient', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

        $this->assertNull( $context->rejection() );
        $this->assertSame( '2026-08-25', $context->version() );
    }

    public function test_pinned_session_in_strict_mode_follows_the_strict_rows(): void {
        $context = VersionResolverTest::resolve_with( null, 'strict', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, '2026-08-25' );

        $this->assertSame( 424, $context->rejection()['status'] );
    }

    public function test_only_a_matched_resolution_pins_a_new_session(): void {
        $this->assertSame( '2026-08-25', VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ) )->session_pin() );
        FD_Test_WP::reset();
        $this->assertNull( VersionResolverTest::resolve_with( null )->session_pin() );
        FD_Test_WP::reset();
        $this->assertNull( VersionResolverTest::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-01-11' ) )->session_pin() );
        $versions = new FD_UCP_Version_Registry();
        $this->assertNull( ( new FD_UCP_Version_Resolver( $versions, new FD_Test_Fixture_Profile_Fetcher( array() ) ) )->resolve( null )->session_pin() );
    }

    public function test_pinned_session_without_agent_header_keeps_its_version(): void {
        $versions = new FD_UCP_Version_Registry();
        $context  = ( new FD_UCP_Version_Resolver( $versions, new FD_Test_Fixture_Profile_Fetcher( array() ) ) )->resolve( null, '2026-01-23' );

        $this->assertSame( '2026-01-23', $context->version() );
    }

    public function test_session_rows_without_a_version_serve_current_or_the_matched_version(): void {
        $plugin   = FD_UCP_Plugin::instance();
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( $plugin, new FD_UCP_Version_Resolver(
            new FD_UCP_Version_Registry(),
            new FD_Test_Fixture_Profile_Fetcher( array( 'https://agent.example/p' => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ) ) )
        ) );

        $this->assertNull( $plugin->pin_session( new WP_REST_Request(), null ) );
        $this->assertSame( FD_UCP_Version_Registry::LATEST, FD_UCP_Request_Context::current()->version() );

        $this->assertNull( $plugin->pin_session( new WP_REST_Request( '/fd-ucp/v1', array( 'UCP-Agent' => 'profile="https://agent.example/p"' ) ), null ) );
        $this->assertSame( '2026-08-25', FD_UCP_Request_Context::current()->version() );

        $resolver->setValue( $plugin, null );
    }

    private const ORDER_KEY = 'wc_order_Zk3q9XvT1aBcD';
    private const AGENT     = 'https://agent.example/p';

    private function order_route_world( string $declared, ?string $pinned, bool $with_session = true ): FD_Test_Fixture_Profile_Fetcher {
        $order       = new WC_Order();
        $order->meta = array(
            '_fd_ucp_handler_id'          => 'xyz.fd.prism_payment',
            '_fd_ucp_session_secret_hash' => FD_Test_Session_Secret::hash(),
        );
        FD_Test_Order_Store::$orders = array( 1001 => $order );

        $db = new FD_Test_Wpdb();
        if ( $with_session ) {
            $db->sessions['s1'] = array( 'id' => 's1', 'status' => 'completed', 'wc_order_id' => '1001', 'ucp_version' => $pinned, 'platform_id' => 'https://platform.example/.well-known/ucp' );
        }
        $GLOBALS['wpdb'] = $db;

        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::AGENT => FD_Test_Fixture_Profile_Fetcher::declaring( $declared ) ) );
        ( new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' ) )->setValue( FD_UCP_Plugin::instance(), new FD_UCP_Version_Resolver( new FD_UCP_Version_Registry(), $fetcher ) );
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::current()->with_platform( 'https://platform.example/.well-known/ucp' ) );
        return $fetcher;
    }

    private static function order_request( string $secret = FD_Test_Session_Secret::SECRET ): WP_REST_Request {
        return new WP_REST_Request(
            '/fd-ucp/v1/orders/' . self::ORDER_KEY,
            array( 'UCP-Session-Secret' => $secret, 'UCP-Agent' => 'profile="' . self::AGENT . '"' ),
            array( 'id' => self::ORDER_KEY )
        );
    }

    protected function tearDown(): void {
        ( new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' ) )->setValue( FD_UCP_Plugin::instance(), null );
        FD_UCP_Request_Context::set( null );
    }

    public function test_order_route_rejects_a_pin_mismatch_like_the_session_routes(): void {
        $this->order_route_world( '2026-04-08', '2026-08-25' );
        $expected = FD_UCP_Plugin::instance()->pin_session( self::order_request(), '2026-08-25' );
        $this->order_route_world( '2026-04-08', '2026-08-25' );

        $order   = ( new FD_UCP_Order_Controller() )->get_order( self::order_request() );
        $returns = ( new FD_UCP_Returns_Controller() )->list_returns( self::order_request() );

        $this->assertNotNull( $expected );
        foreach ( array( 'order' => $order, 'returns' => $returns ) as $name => $response ) {
            $this->assertSame( $expected->get_status(), $response->get_status(), $name );
            $this->assertSame( $expected->get_data(), $response->get_data(), $name );
        }
        $this->assertSame( 'version_unsupported', $order->get_data()['messages'][0]['code'] ?? $order->get_data()['code'] ?? null );
    }

    public function test_order_route_serves_the_pinned_version_when_the_agent_matches(): void {
        $this->order_route_world( '2026-08-25', '2026-08-25' );

        $response = ( new FD_UCP_Order_Controller() )->get_order( self::order_request() );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( '2026-08-25', FD_UCP_Request_Context::current()->version() );
    }

    public function test_order_route_checks_platform_and_secret_before_the_pin(): void {
        $this->order_route_world( '2026-04-08', '2026-08-25' );
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::current()->with_platform( '' ) );

        $this->assertInstanceOf( WP_Error::class, FD_UCP_Plugin::require_platform() );

        $fetcher  = $this->order_route_world( '2026-04-08', '2026-08-25' );
        $response = ( new FD_UCP_Order_Controller() )->get_order( self::order_request( 'wrong-secret' ) );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'order_not_found', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $fetcher->requested );
    }

    public function test_order_without_a_session_row_is_not_found(): void {
        $fetcher = $this->order_route_world( '2026-08-25', '2026-08-25', false );

        $response = ( new FD_UCP_Order_Controller() )->get_order( self::order_request() );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'order_not_found', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $fetcher->requested );
    }
}
