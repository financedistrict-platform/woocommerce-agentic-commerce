<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VersionResolverTest extends TestCase {

    private const PROFILE = 'https://agent.example/.well-known/ucp';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function resolve_with( ?string $body, string $negotiation = 'lenient', array $supported = FD_UCP_Version_Registry::DEFAULT_SUPPORTED, ?string $pinned = null, ?string $header = null ): FD_UCP_Request_Context {
        $versions = new FD_UCP_Version_Registry( FD_UCP_Version_Registry::DEFAULT_CURRENT, $supported, $negotiation );
        $fetcher  = new FD_Test_Fixture_Profile_Fetcher( null === $body ? array() : array( self::PROFILE => $body ) );
        $resolver = new FD_UCP_Version_Resolver( $versions, $fetcher );
        return $resolver->resolve( $header ?? 'profile="' . self::PROFILE . '"', $pinned );
    }

    public static function table(): array {
        $declares = static fn( ?string $v ): string => FD_Test_Fixture_Profile_Fetcher::declaring( $v );
        return array(
            'unreachable lenient'  => array( null, 'lenient', null, 'unreachable', FD_UCP_Version_Registry::LATEST ),
            'unreachable strict'   => array( null, 'strict', 424, 'unreachable', null ),
            'undeclared lenient'   => array( $declares( null ), 'lenient', null, 'undeclared', FD_UCP_Version_Registry::LATEST ),
            'undeclared strict'    => array( $declares( null ), 'strict', 422, 'undeclared', null ),
            'malformed lenient'    => array( $declares( 'April 2026' ), 'lenient', null, 'undeclared', FD_UCP_Version_Registry::LATEST ),
            'not json lenient'     => array( '<html>', 'lenient', null, 'unreachable', FD_UCP_Version_Registry::LATEST ),
            'unknown lenient'      => array( $declares( '2026-01-11' ), 'lenient', null, 'unknown', FD_UCP_Version_Registry::LATEST ),
            'unknown strict'       => array( $declares( '2026-01-11' ), 'strict', 422, 'unknown', null ),
            'matched lenient'      => array( $declares( '2026-08-25' ), 'lenient', null, 'matched', '2026-08-25' ),
            'matched strict'       => array( $declares( '2026-01-23' ), 'strict', null, 'matched', '2026-01-23' ),
            'matched current'      => array( $declares( '2026-04-08' ), 'lenient', null, 'matched', '2026-04-08' ),
        );
    }

    #[DataProvider( 'table' )]
    public function test_resolution_table( ?string $body, string $negotiation, ?int $status, string $outcome, ?string $served ): void {
        $context = self::resolve_with( $body, $negotiation );

        $this->assertSame( $outcome, $context->outcome() );
        if ( null === $status ) {
            $this->assertNull( $context->rejection() );
            $this->assertSame( $served, $context->version() );
            return;
        }
        $this->assertSame( $status, $context->rejection()['status'] );
        $this->assertSame( 424 === $status ? 'agent_profile_unavailable' : 'version_unsupported', $context->rejection()['code'] );
    }

    public function test_missing_header_keeps_todays_behaviour(): void {
        $versions = new FD_UCP_Version_Registry();
        $fetcher  = new FD_Test_Fixture_Profile_Fetcher( array() );
        $context  = ( new FD_UCP_Version_Resolver( $versions, $fetcher ) )->resolve( null );

        $this->assertSame( 'none', $context->outcome() );
        $this->assertSame( FD_UCP_Version_Registry::LATEST, $context->version() );
        $this->assertSame( array(), $fetcher->requested );
    }

    public function test_store_on_an_older_version_serves_it_to_agents_without_a_usable_profile(): void {
        $versions = new FD_UCP_Version_Registry( '2026-04-08', array( '2026-08-25', '2026-01-23' ) );
        $resolver = new FD_UCP_Version_Resolver( $versions, new FD_Test_Fixture_Profile_Fetcher( array() ) );

        $this->assertSame( '2026-04-08', $resolver->resolve( null )->version() );
        $this->assertSame( '2026-04-08', $resolver->resolve( 'profile="' . self::PROFILE . '"' )->version() );
    }

    public function test_header_without_profile_keeps_todays_behaviour(): void {
        $context = self::resolve_with( null, 'strict', FD_UCP_Version_Registry::DEFAULT_SUPPORTED, null, 'agent-a/1.0' );

        $this->assertSame( 'none', $context->outcome() );
        $this->assertNull( $context->rejection() );
    }

    public function test_disabled_version_is_rejected_in_both_modes(): void {
        foreach ( array( 'lenient', 'strict' ) as $mode ) {
            $context = self::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ), $mode, array() );

            $this->assertSame( 'disabled', $context->outcome(), $mode );
            $this->assertSame( 422, $context->rejection()['status'], $mode );
            $this->assertSame( 'version_unsupported', $context->rejection()['code'], $mode );
        }
    }

    public function test_rejection_body_lists_enabled_versions_in_current_wire_shape(): void {
        $response = self::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ), 'lenient', array( '2026-01-23' ) )->rejection_response();
        $body     = $response->get_data();

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( '2026-08-25', $body['ucp']['version'] );
        $this->assertSame(
            'Version 2026-04-08 is not supported. This business implements versions 2026-08-25, 2026-01-23.',
            $body['messages'][0]['content']
        );
    }

    public function test_fallback_logs_a_warning_with_the_outcome_label(): void {
        self::resolve_with( null );

        $this->assertSame( 'warning', FD_Test_WP::$logs[0]['level'] );
        $this->assertSame( 'unreachable', FD_Test_WP::$logs[0]['context']['ucp_profile_resolution'] );
    }

    public function test_resolution_fires_the_profile_resolution_hook(): void {
        self::resolve_with( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ) );

        $this->assertContains(
            array( 'fd_ucp_profile_resolution', array( 'matched', '2026-08-25', 'agent.example' ) ),
            FD_Test_WP::$actions
        );
    }
}
