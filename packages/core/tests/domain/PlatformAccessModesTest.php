<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PlatformAccessModesTest extends TestCase {

    private const PROFILE = FD_Test_Platform_Vectors::PROFILE;
    private const KEY     = 'registered-test-key';
    private const OTHER   = 'https://other.example/.well-known/ucp';
    private const MODES   = array( 'open', 'authenticated', 'registered' );
    private const OPTION  = 'fd_ucp_platform_access';

    protected function setUp(): void {
        FD_Test_WP::reset();
        $_POST = array();
    }

    protected function tearDown(): void {
        $_POST = array();
        FD_Test_Platform_Vectors::reset_server();
    }

    private function mode( $value ): void {
        FD_Test_WP::$options[ self::OPTION ] = $value;
    }

    private function bare( string $profile = self::PROFILE ): WP_REST_Request {
        return FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . $profile . '"' ) );
    }

    private function with_key( string $key, string $profile = self::PROFILE ): WP_REST_Request {
        return FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . $profile . '"', 'x-api-key' => $key ) );
    }

    private function auth( WP_REST_Request $request ): array {
        return FD_Test_Platform_Vectors::auth()->authenticate( $request );
    }

    private function assert_error( array $result, string $code, int $status ): void {
        $this->assertArrayNotHasKey( 'platform_id', $result );
        $this->assertSame( $code, $result['error']['code'] );
        $this->assertSame( $status, $result['error']['status'] );
    }

    private function assert_principal( array $result, string $expected ): void {
        $this->assertSame( array( 'platform_id' => $expected ), $result );
    }

    public function test_open_is_the_default_when_no_mode_is_stored(): void {
        $this->assert_principal( $this->auth( $this->bare() ), 'unverified:' . self::PROFILE );
    }

    public function test_open_gives_an_unregistered_platform_without_credentials_its_own_unverified_principal(): void {
        $this->mode( 'open' );

        $this->assert_principal( $this->auth( $this->bare() ), 'unverified:' . self::PROFILE );
    }

    public function test_the_unverified_principal_uses_the_normalised_profile(): void {
        $this->assert_principal( $this->auth( $this->bare( 'HTTPS://Platform.example/.well-known/ucp/' ) ), 'unverified:' . self::PROFILE );
    }

    public function test_authenticated_refuses_an_unregistered_platform_without_credentials(): void {
        $this->mode( 'authenticated' );

        $this->assert_error( $this->auth( $this->bare() ), 'signature_missing', 401 );
    }

    public function test_registered_refuses_an_unlisted_platform_without_credentials(): void {
        $this->mode( 'registered' );

        $this->assert_error( $this->auth( $this->bare() ), 'profile_not_trusted', 403 );
    }

    public function test_a_registered_platform_without_credentials_is_refused_in_every_mode_and_named(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );

            $result = $this->auth( $this->bare() );

            $this->assert_error( $result, 'signature_missing', 401 );
            $this->assertStringContainsString( self::PROFILE, $result['error']['message'], $mode );
            $this->assertStringContainsString( 'X-API-Key', $result['error']['message'], $mode );
        }
    }

    public function test_a_registered_platform_with_its_key_is_accepted_in_every_mode(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );

            $this->assert_principal( $this->auth( $this->with_key( self::KEY ) ), self::PROFILE );
        }
    }

    public function test_a_wrong_key_never_falls_through_to_open(): void {
        foreach ( array( false, true ) as $registered ) {
            FD_Test_WP::$options = array();
            if ( $registered ) {
                FD_Test_Platform_Vectors::register( self::KEY );
            }
            foreach ( self::MODES as $mode ) {
                $this->mode( $mode );

                $this->assert_error( $this->auth( $this->with_key( 'wrong-key' ) ), 'key_not_found', 401 );
            }
        }
    }

    public function test_a_whitespace_only_key_counts_as_no_credential(): void {
        $this->assert_principal( $this->auth( $this->with_key( '   ' ) ), 'unverified:' . self::PROFILE );
    }

    public function test_a_key_registered_for_another_platform_is_not_trusted_in_every_mode(): void {
        FD_Test_Platform_Vectors::register( self::KEY, self::OTHER );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );

            $this->assert_error( $this->auth( $this->with_key( self::KEY ) ), 'profile_not_trusted', 403 );
        }
    }

    public function test_a_disabled_platform_is_refused_in_every_mode_whatever_it_presents(): void {
        FD_Test_Platform_Vectors::register( self::KEY, self::PROFILE, false );
        $requests = array(
            'bare'      => $this->bare(),
            'valid key' => $this->with_key( self::KEY ),
            'bad key'   => $this->with_key( 'wrong-key' ),
            'signed'    => FD_Test_Platform_Vectors::signed( 'es256_post' ),
        );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );
            foreach ( $requests as $request ) {
                $this->assert_error( $this->auth( $request ), 'profile_not_trusted', 403 );
            }
        }
    }

    public function test_a_valid_signature_from_an_unregistered_platform_keeps_its_declared_profile(): void {
        foreach ( array( 'open', 'authenticated' ) as $mode ) {
            $this->mode( $mode );

            $this->assert_principal( $this->auth( FD_Test_Platform_Vectors::signed( 'es256_post' ) ), self::PROFILE );
        }
    }

    public function test_an_invalid_signature_never_falls_through_to_open(): void {
        $tampered = '{"line_items":[{"item":{"id":"1"},"quantity":99}]}';

        foreach ( array( 'open', 'authenticated' ) as $mode ) {
            $this->mode( $mode );

            $this->assert_error( $this->auth( FD_Test_Platform_Vectors::signed( 'es256_post', array(), $tampered ) ), 'digest_mismatch', 400 );
        }
        $this->mode( 'registered' );
        $this->assert_error( $this->auth( FD_Test_Platform_Vectors::signed( 'es256_post' ) ), 'profile_not_trusted', 403 );
    }

    public function test_an_unreachable_profile_signature_never_falls_through_to_open(): void {
        $auth = FD_Test_Platform_Vectors::auth( new FD_Test_Fixture_Profile_Fetcher( array() ) );

        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) ), 'profile_unreachable', 424 );
    }

    public function test_a_missing_or_non_https_profile_is_invalid_in_every_mode(): void {
        $headers = array(
            array(),
            array( 'ucp-agent' => '' ),
            array( 'ucp-agent' => 'profile="http://platform.example/.well-known/ucp"' ),
            array( 'ucp-agent' => 'profile="ftp://platform.example/ucp"' ),
            array( 'ucp-agent' => 'platform.example' ),
        );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );
            foreach ( $headers as $set ) {
                $this->assert_error( $this->auth( FD_Test_Platform_Vectors::request( $set ) ), 'invalid_profile_url', 400 );
            }
        }
    }

    public function test_an_unknown_stored_mode_fails_closed_as_registered(): void {
        foreach ( array( '', 'closed', 'OPEN', ' open', 'registered ', 0, 1, true, false, null, array(), array( 'open' ) ) as $value ) {
            $this->mode( $value );

            $this->assert_error( $this->auth( $this->bare() ), 'profile_not_trusted', 403 );
        }
    }

    public function test_an_unknown_stored_mode_still_serves_a_registered_platform_with_its_key(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( array( '', 'closed', null, array() ) as $value ) {
            $this->mode( $value );

            $this->assert_principal( $this->auth( $this->with_key( self::KEY ) ), self::PROFILE );
        }
    }

    public function test_the_old_option_is_no_longer_read(): void {
        FD_Test_WP::$options['fd_ucp_signed_access'] = 'registered';

        $this->assert_principal( $this->auth( $this->bare() ), 'unverified:' . self::PROFILE );
    }

    public function test_the_longest_accepted_profile_fits_the_platform_column(): void {
        $base    = 'https://long.example/';
        $profile = $base . str_repeat( 'a', 180 - strlen( $base ) );

        $this->assertSame( 180, strlen( $profile ) );
        $this->assertSame( $profile, FD_UCP_Platform_Auth::normalise_profile_url( $profile ) );

        $result = $this->auth( $this->bare( $profile ) );

        $this->assert_principal( $result, 'unverified:' . $profile );
        $this->assertSame( 191, strlen( $result['platform_id'] ) );
    }

    public function test_a_profile_one_character_too_long_is_refused_everywhere(): void {
        $base    = 'https://long.example/';
        $profile = $base . str_repeat( 'a', 181 - strlen( $base ) );

        $this->assertSame( 181, strlen( $profile ) );
        $this->assertSame( 180, FD_UCP_Platform_Auth::MAX_PROFILE_LENGTH );
        $this->assertNull( FD_UCP_Platform_Auth::normalise_profile_url( $profile ) );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );

            $this->assert_error( $this->auth( $this->bare( $profile ) ), 'invalid_profile_url', 400 );
            $this->assert_error( $this->auth( $this->with_key( self::KEY, $profile ) ), 'invalid_profile_url', 400 );
        }
    }

    public function test_the_registry_form_refuses_a_profile_over_the_limit(): void {
        $_POST = array( 'fd_ucp_platform_new_profile' => 'https://long.example/' . str_repeat( 'a', 160 ) );

        FD_UCP_Settings::save_platforms();

        $this->assertSame( array(), FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] ?? array() );
        $this->assertCount( 1, WC_Admin_Settings::$errors );
        $this->assertStringContainsString( '180', WC_Admin_Settings::$errors[0] );
    }

    public function test_no_profile_can_be_crafted_to_collide_with_the_unverified_prefix(): void {
        $crafted = array(
            'unverified:https://platform.example/.well-known/ucp',
            'UNVERIFIED:https://platform.example/.well-known/ucp',
            'https://unverified:@platform.example/ucp',
            'unverified://platform.example/ucp',
            ' unverified:https://platform.example/ucp',
            "https://platform.example/\x00unverified:",
        );

        foreach ( $crafted as $url ) {
            $normalised = FD_UCP_Platform_Auth::normalise_profile_url( $url );

            $this->assertTrue( null === $normalised || str_starts_with( $normalised, 'https://' ), $url );
        }
    }

    public function test_an_unverified_principal_can_never_equal_a_registered_principal(): void {
        FD_Test_Platform_Vectors::register( self::KEY );
        $registered = $this->auth( $this->with_key( self::KEY ) )['platform_id'];
        $unverified = $this->auth( $this->bare( self::OTHER ) )['platform_id'];

        $this->assertStringStartsWith( 'https://', $registered );
        $this->assertStringStartsWith( 'unverified:', $unverified );
        $this->assertFalse( FD_UCP_Ownership::assert( $unverified, $registered ) );
        $this->assertFalse( FD_UCP_Ownership::assert( 'unverified:' . self::PROFILE, self::PROFILE ) );
        $this->assertFalse( FD_UCP_Ownership::assert( self::PROFILE, 'unverified:' . self::PROFILE ) );
    }

    public function test_distinct_profiles_get_distinct_unverified_principals(): void {
        $this->assertNotSame(
            $this->auth( $this->bare( self::PROFILE ) )['platform_id'],
            $this->auth( $this->bare( self::OTHER ) )['platform_id']
        );
    }

    public function test_a_lone_signature_header_from_an_unregistered_platform_is_a_failed_credential(): void {
        foreach ( array( 'signature' => 'sig1=:AAAA:', 'signature-input' => 'sig1=("@method");keyid="k"' ) as $header => $value ) {
            foreach ( array( 'open', 'authenticated' ) as $mode ) {
                $this->mode( $mode );
                $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', $header => $value ) );

                $this->assert_error( $this->auth( $request ), 'signature_invalid', 401 );
            }
            $this->mode( 'registered' );
            $this->assert_error( $this->auth( FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', $header => $value ) ) ), 'profile_not_trusted', 403 );
        }
    }

    public function test_a_lone_signature_header_from_a_registered_platform_is_a_failed_credential_in_every_mode(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );
            foreach ( array( 'signature' => 'sig1=:AAAA:', 'signature-input' => 'sig1=("@method");keyid="k"' ) as $header => $value ) {
                $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', $header => $value ) );

                $this->assert_error( $this->auth( $request ), 'signature_invalid', 401 );
            }
        }
    }

    public function test_a_lone_signature_header_beats_a_valid_key_in_every_mode(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        foreach ( self::MODES as $mode ) {
            $this->mode( $mode );
            $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'signature' => 'sig1=:AAAA:', 'x-api-key' => self::KEY ) );

            $this->assert_error( $this->auth( $request ), 'signature_invalid', 401 );
        }
    }

    public function test_a_blank_signature_header_alone_counts_as_no_credential(): void {
        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'signature' => '   ' ) );

        $this->assert_principal( $this->auth( $request ), 'unverified:' . self::PROFILE );
    }
}
