<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class RegistryConfigTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public function test_defaults_serve_the_latest_version_and_enable_all_three(): void {
        $versions = FD_UCP_Version_Registry::from_options();

        $this->assertSame( '2026-08-25', $versions->current() );
        $this->assertSame( array( '2026-08-25', '2026-04-08', '2026-01-23' ), $versions->enabled() );
        $this->assertSame( 'lenient', $versions->negotiation() );
        $this->assertNull( $versions->assert_valid() );
    }

    public function test_latest_is_the_newest_known_version(): void {
        $known = FD_UCP_Version_Registry::known();
        rsort( $known );

        $this->assertSame( $known[0], FD_UCP_Version_Registry::LATEST );
        $this->assertSame( FD_UCP_Version_Registry::LATEST, FD_UCP_Version_Registry::DEFAULT_CURRENT );
    }

    public function test_default_supported_plus_latest_covers_every_known_version(): void {
        $enabled = array_merge( FD_UCP_Version_Registry::DEFAULT_SUPPORTED, array( FD_UCP_Version_Registry::LATEST ) );
        $known   = FD_UCP_Version_Registry::known();
        sort( $enabled );
        sort( $known );

        $this->assertSame( $known, $enabled );
        $this->assertNotContains( FD_UCP_Version_Registry::LATEST, FD_UCP_Version_Registry::DEFAULT_SUPPORTED );
    }

    public function test_every_known_version_is_an_iso_date(): void {
        foreach ( FD_UCP_Version_Registry::known() as $version ) {
            $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $version );
        }
    }

    public function test_current_version_is_removed_from_the_supported_list(): void {
        $versions = new FD_UCP_Version_Registry( '2026-08-25', array( '2026-08-25', '2026-04-08' ) );

        $this->assertSame( array( '2026-04-08' ), $versions->supported() );
        $this->assertNull( $versions->assert_valid() );
    }

    public function test_unknown_values_are_configuration_errors(): void {
        $this->assertNotNull( ( new FD_UCP_Version_Registry( '2026-01-11' ) )->assert_valid() );
        $this->assertNotNull( ( new FD_UCP_Version_Registry( '2026-04-08', array( '2025-12-01' ) ) )->assert_valid() );
        $this->assertNotNull( ( new FD_UCP_Version_Registry( '2026-04-08', array(), 'relaxed' ) )->assert_valid() );
    }

    public function test_unknown_stored_option_makes_ucp_routes_answer_configuration_invalid(): void {
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] = '2026-01-11';

        $response = FD_UCP_Plugin::instance()->gate_request( null, null, new WP_REST_Request( '/fd-ucp/v1/checkout-sessions' ) );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'configuration_invalid', $response->get_data()['messages'][0]['code'] );
    }

    public function test_unknown_stored_option_breaks_discovery_but_not_other_routes(): void {
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_NEGOTIATION ] = 'relaxed';

        $this->assertSame( 500, FD_UCP_Discovery::render( FD_UCP_Version_Registry::from_options(), '', new FD_Payment_Registry() )['status'] );
        $this->assertNull( FD_UCP_Plugin::instance()->gate_request( null, null, new WP_REST_Request( '/wc/v3/orders' ) ) );
    }

    public function test_settings_save_rejects_unknown_values(): void {
        $this->assertSame( '2026-08-25', FD_UCP_Settings::sanitize_current( '2026-01-11', FD_UCP_Version_Registry::OPTION_CURRENT ) );
        $this->assertSame( '2026-04-08', FD_UCP_Settings::sanitize_current( '2026-04-08', FD_UCP_Version_Registry::OPTION_CURRENT ) );
        $this->assertSame( FD_UCP_Version_Registry::DEFAULT_SUPPORTED, FD_UCP_Settings::sanitize_supported( array( '2026-04-08', 'x' ), FD_UCP_Version_Registry::OPTION_SUPPORTED ) );
        $this->assertSame( array( '2026-01-23' ), FD_UCP_Settings::sanitize_supported( array( '2026-01-23' ), FD_UCP_Version_Registry::OPTION_SUPPORTED ) );
        $this->assertSame( 'lenient', FD_UCP_Settings::sanitize_negotiation( 'relaxed', FD_UCP_Version_Registry::OPTION_NEGOTIATION ) );
        $this->assertSame( 'strict', FD_UCP_Settings::sanitize_negotiation( 'strict', FD_UCP_Version_Registry::OPTION_NEGOTIATION ) );
    }

    public function test_disabled_version_request_is_rejected_by_the_route_gate(): void {
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_SUPPORTED ] = array();
        $plugin   = FD_UCP_Plugin::instance();
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( $plugin, new FD_UCP_Version_Resolver(
            FD_UCP_Version_Registry::from_options(),
            new FD_Test_Fixture_Profile_Fetcher( array( 'https://agent.example/p' => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ) ) )
        ) );

        $response = $plugin->gate_request( null, null, new WP_REST_Request( '/fd-ucp/v1/checkout-sessions', array( 'UCP-Agent' => 'profile="https://agent.example/p"' ) ) );
        $resolver->setValue( $plugin, null );

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( 'version_unsupported', $response->get_data()['messages'][0]['code'] );
    }

    public function test_cart_routes_are_not_available_in_2026_01_23(): void {
        $plugin   = FD_UCP_Plugin::instance();
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( $plugin, new FD_UCP_Version_Resolver(
            new FD_UCP_Version_Registry(),
            new FD_Test_Fixture_Profile_Fetcher( array( 'https://agent.example/p' => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-01-23' ) ) )
        ) );

        $response = $plugin->gate_request( null, null, new WP_REST_Request( '/fd-ucp/v1/carts', array( 'UCP-Agent' => 'profile="https://agent.example/p"' ) ) );
        $resolver->setValue( $plugin, null );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'capabilities_incompatible', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( '2026-01-23', $response->get_data()['ucp']['version'] );
    }
}
