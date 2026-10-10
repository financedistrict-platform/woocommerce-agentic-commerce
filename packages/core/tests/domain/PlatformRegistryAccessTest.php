<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PlatformRegistryAccessTest extends TestCase {

    private const KEY     = 'registered-test-key';
    private const OLD_KEY = 'retired-test-key';
    private const MODE    = 'fd_ucp_signed_access';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    protected function tearDown(): void {
        FD_Test_Platform_Vectors::reset_server();
    }

    private function mode( $value ): void {
        FD_Test_WP::$options[ self::MODE ] = $value;
    }

    private function with_key( string $key ): WP_REST_Request {
        return FD_Test_Platform_Vectors::request(
            array( 'ucp-agent' => 'profile="' . FD_Test_Platform_Vectors::PROFILE . '"', 'x-api-key' => $key )
        );
    }

    private function assert_error( array $result, string $code, int $status ): void {
        $this->assertArrayNotHasKey( 'platform_id', $result );
        $this->assertSame( $code, $result['error']['code'] );
        $this->assertSame( $status, $result['error']['status'] );
    }

    private function assert_accepted( array $result ): void {
        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    private function signed_attempt( ?array &$requested = null ): array {
        $fetcher   = FD_Test_Platform_Vectors::fetcher();
        $result    = FD_Test_Platform_Vectors::auth( $fetcher )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) );
        $requested = $fetcher->requested;
        return $result;
    }

    public function test_signed_request_from_a_disabled_platform_is_not_trusted_and_fetches_nothing(): void {
        FD_Test_Platform_Vectors::register( self::KEY, FD_Test_Platform_Vectors::PROFILE, false );

        $result = $this->signed_attempt( $requested );

        $this->assert_error( $result, 'profile_not_trusted', 403 );
        $this->assertSame( array(), $requested );
    }

    public function test_disabled_platform_is_refused_in_registered_mode_too(): void {
        $this->mode( 'registered' );
        FD_Test_Platform_Vectors::register( self::KEY, FD_Test_Platform_Vectors::PROFILE, false );

        $this->assert_error( $this->signed_attempt(), 'profile_not_trusted', 403 );
    }

    public function test_revocation_matches_the_normalised_profile(): void {
        FD_Test_Platform_Vectors::register( self::KEY, 'HTTPS://Platform.example/.well-known/ucp/', false );

        $this->assert_error( $this->signed_attempt(), 'profile_not_trusted', 403 );
    }

    public function test_api_key_of_a_fully_disabled_platform_is_not_trusted(): void {
        FD_Test_Platform_Vectors::register( self::KEY, FD_Test_Platform_Vectors::PROFILE, false );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ), 'profile_not_trusted', 403 );
        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( 'another-key' ) ), 'profile_not_trusted', 403 );
    }

    public function test_every_credential_shape_is_refused_for_a_revoked_platform(): void {
        FD_Test_Platform_Vectors::register( self::KEY, FD_Test_Platform_Vectors::PROFILE, false );
        $both = FD_Test_Platform_Vectors::signed( 'es256_post', array( 'x-api-key' => self::KEY ) );

        foreach ( array( $this->with_key( self::KEY ), $both, FD_Test_Platform_Vectors::signed( 'es256_get' ) ) as $request ) {
            $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'profile_not_trusted', 403 );
        }
    }

    public function test_rotating_a_key_keeps_the_platform_trusted(): void {
        FD_Test_Platform_Vectors::register( self::OLD_KEY, FD_Test_Platform_Vectors::PROFILE, false );
        FD_Test_Platform_Vectors::register( self::KEY );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::OLD_KEY ) ), 'key_not_found', 401 );
        $this->assert_accepted( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ) );
        $this->assert_accepted( $this->signed_attempt() );
    }

    public function test_another_platform_being_disabled_does_not_affect_this_one(): void {
        FD_Test_Platform_Vectors::register( self::OLD_KEY, 'https://other.example/.well-known/ucp', false );

        $this->assert_accepted( $this->signed_attempt() );
    }

    public function test_open_mode_is_the_default_and_accepts_an_unregistered_signing_platform(): void {
        $this->assert_accepted( $this->signed_attempt() );

        $this->mode( 'open' );
        $this->assert_accepted( $this->signed_attempt() );
    }

    public function test_registered_mode_refuses_an_unregistered_signing_platform_before_fetching(): void {
        $this->mode( 'registered' );

        $result = $this->signed_attempt( $requested );

        $this->assert_error( $result, 'profile_not_trusted', 403 );
        $this->assertSame( array(), $requested );
    }

    public function test_registered_mode_accepts_a_registered_and_enabled_platform(): void {
        $this->mode( 'registered' );
        FD_Test_Platform_Vectors::register( self::KEY );

        $this->assert_accepted( $this->signed_attempt() );
        $this->assert_accepted( FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) ) );
    }

    public function test_open_mode_accepts_a_registered_and_enabled_platform(): void {
        $this->mode( 'open' );
        FD_Test_Platform_Vectors::register( self::KEY );

        $this->assert_accepted( $this->signed_attempt() );
    }

    public function test_registered_mode_still_verifies_the_signature(): void {
        $this->mode( 'registered' );
        FD_Test_Platform_Vectors::register( self::KEY );
        $request = FD_Test_Platform_Vectors::signed( 'es256_post', array(), '{"line_items":[{"item":{"id":"1"},"quantity":99}]}' );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'digest_mismatch', 400 );
    }

    public function test_unknown_mode_values_fail_closed_as_registered(): void {
        foreach ( array( '', 'closed', 'OPEN', ' open', 'registered ', 0, 1, true, false, null, array(), array( 'open' ) ) as $value ) {
            $this->mode( $value );

            $this->assert_error( $this->signed_attempt(), 'profile_not_trusted', 403 );
        }
    }

    public function test_unknown_mode_values_still_accept_a_registered_platform(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( array( '', 'closed', null, array() ) as $value ) {
            $this->mode( $value );

            $this->assert_accepted( $this->signed_attempt() );
        }
    }

    public function test_api_key_path_does_not_depend_on_the_mode(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( array( 'open', 'registered', 'garbage' ) as $value ) {
            $this->mode( $value );

            $this->assert_accepted( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ) );
            $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( 'another-key' ) ), 'key_not_found', 401 );
        }
    }

    public function test_api_key_bound_to_another_profile_is_still_not_trusted(): void {
        FD_Test_Platform_Vectors::register( self::KEY, 'https://other.example/.well-known/ucp' );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ), 'profile_not_trusted', 403 );
    }

    public function test_malformed_registry_entries_do_not_grant_or_revoke_anything(): void {
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] = array(
            'junk',
            null,
            array( 'profile' => FD_Test_Platform_Vectors::PROFILE, 'enabled' => false ),
            array( 'profile' => 'http://insecure.example', 'key_hash' => str_repeat( 'a', 64 ), 'enabled' => false ),
        );

        $this->assert_accepted( $this->signed_attempt() );

        $this->mode( 'registered' );
        $this->assert_error( $this->signed_attempt(), 'profile_not_trusted', 403 );
    }

    public function test_non_array_registry_is_treated_as_empty(): void {
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] = 'broken';

        $this->assert_accepted( $this->signed_attempt() );
    }
}
