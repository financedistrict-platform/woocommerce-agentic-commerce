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
}
