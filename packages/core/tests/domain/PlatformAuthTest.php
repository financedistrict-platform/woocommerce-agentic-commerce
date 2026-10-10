<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PlatformAuthTest extends TestCase {

    private const KEY = 'registered-test-key';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    protected function tearDown(): void {
        FD_Test_Platform_Vectors::reset_server();
    }

    private function with_key( string $key, array $extra = array() ): WP_REST_Request {
        return FD_Test_Platform_Vectors::request(
            array_merge( array( 'ucp-agent' => 'profile="' . FD_Test_Platform_Vectors::PROFILE . '"', 'x-api-key' => $key ), $extra )
        );
    }

    private function assert_error( array $result, string $code, int $status ): void {
        $this->assertArrayNotHasKey( 'platform_id', $result );
        $this->assertSame( $code, $result['error']['code'] );
        $this->assertSame( $status, $result['error']['status'] );
    }

    public function test_registered_api_key_for_the_declared_profile_authenticates(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        $result = FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) );

        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    public function test_unknown_api_key_is_rejected(): void {
        FD_Test_Platform_Vectors::register( self::KEY );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( 'another-key' ) ), 'key_not_found', 401 );
    }

    public function test_api_key_bound_to_a_different_profile_is_not_trusted(): void {
        FD_Test_Platform_Vectors::register( self::KEY, 'https://other.example/.well-known/ucp' );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ), 'profile_not_trusted', 403 );
    }

    public function test_disabled_api_key_is_rejected(): void {
        FD_Test_Platform_Vectors::register( self::KEY, FD_Test_Platform_Vectors::PROFILE, false );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $this->with_key( self::KEY ) ), 'profile_not_trusted', 403 );
    }

    public function test_request_without_credentials_is_signature_missing(): void {
        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . FD_Test_Platform_Vectors::PROFILE . '"' ) );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_missing', 401 );
    }

    public function test_es256_static_vector_authenticates(): void {
        $result = FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) );

        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    public function test_tampered_body_is_a_digest_mismatch(): void {
        $request = FD_Test_Platform_Vectors::signed( 'es256_post', array(), '{"line_items":[{"item":{"id":"1"},"quantity":99}]}' );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'digest_mismatch', 400 );
    }

    public function test_signature_created_301_seconds_ago_is_rejected(): void {
        $result = FD_Test_Platform_Vectors::auth( null, 301 )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) );

        $this->assert_error( $result, 'signature_invalid', 401 );
    }

    public function test_unknown_kid_is_key_not_found(): void {
        $request = FD_Test_Platform_Vectors::signed( 'es256_post', array(
            'signature-input' => str_replace( 'platform-es256', 'rotated-away', FD_Test_Platform_Vectors::data()['es256_post']['signature_input'] ),
        ) );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'key_not_found', 401 );
    }

    public function test_unreachable_profile_is_a_424(): void {
        $auth = FD_Test_Platform_Vectors::auth( new FD_Test_Fixture_Profile_Fetcher( array() ) );

        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) ), 'profile_unreachable', 424 );
    }

    public function test_profile_urls_normalise_to_one_identity(): void {
        $this->assertSame(
            FD_UCP_Platform_Auth::normalise_profile_url( 'https://a.example/ucp' ),
            FD_UCP_Platform_Auth::normalise_profile_url( 'HTTPS://A.example/ucp/' )
        );
    }
}
