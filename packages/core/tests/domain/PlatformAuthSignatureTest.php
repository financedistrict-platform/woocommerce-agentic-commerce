<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PlatformAuthSignatureTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    protected function tearDown(): void {
        FD_Test_Platform_Vectors::reset_server();
    }

    private function assert_error( array $result, string $code, int $status ): void {
        $this->assertArrayNotHasKey( 'platform_id', $result );
        $this->assertSame( $code, $result['error']['code'] );
        $this->assertSame( $status, $result['error']['status'] );
    }

    private function vector_input( string $name ): string {
        return FD_Test_Platform_Vectors::data()[ $name ]['signature_input'];
    }

    public function test_every_randomised_es256_signature_of_the_same_request_verifies(): void {
        $signatures = FD_Test_Platform_Vectors::data()['es256_post_signatures'];
        $this->assertGreaterThan( 8, count( array_unique( $signatures ) ) );

        foreach ( $signatures as $signature ) {
            $request = FD_Test_Platform_Vectors::signed( 'es256_post', array( 'signature' => $signature ) );
            $this->assertSame(
                array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ),
                FD_Test_Platform_Vectors::auth()->authenticate( $request )
            );
        }
    }

    public function test_ed25519_vector_authenticates(): void {
        $result = FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'ed25519_post' ) );

        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    public function test_bodiless_get_vector_authenticates(): void {
        $result = FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) );

        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    public function test_signature_without_created_is_accepted(): void {
        $result = FD_Test_Platform_Vectors::auth( null, 100000 )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_nocreated_get' ) );

        $this->assertSame( array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ), $result );
    }

    public function test_signature_created_in_the_future_is_rejected(): void {
        $this->assert_error( FD_Test_Platform_Vectors::auth( null, -301 )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) ), 'signature_invalid', 401 );
    }

    public function test_signature_created_exactly_300_seconds_ago_is_accepted(): void {
        $result = FD_Test_Platform_Vectors::auth( null, 300 )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post' ) );

        $this->assertArrayHasKey( 'platform_id', $result );
    }

    public function test_expired_signature_is_rejected(): void {
        $created = FD_Test_Platform_Vectors::data()['created'];
        $input   = str_replace( ';created=' . $created, ';created=' . $created . ';expires=' . ( $created + 5 ), $this->vector_input( 'es256_get' ) );

        $this->assert_error(
            FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature-input' => $input ) ) ),
            'signature_invalid',
            401
        );
    }

    public function test_signature_over_a_different_request_does_not_verify(): void {
        $request = FD_Test_Platform_Vectors::signed( 'es256_get', array(), null, null, '/wp-json/fd-ucp/v1/carts/another-cart' );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_changed_signature_parameters_do_not_verify(): void {
        $created = FD_Test_Platform_Vectors::data()['created'];
        $input   = str_replace( 'created=' . $created, 'created=' . ( $created + 1 ), $this->vector_input( 'es256_get' ) );

        $this->assert_error(
            FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature-input' => $input ) ) ),
            'signature_invalid',
            401
        );
    }

    public function test_replaying_a_signed_read_with_a_method_override_is_rejected(): void {
        $request                   = FD_Test_Platform_Vectors::signed( 'es256_get', array(), null, 'DELETE' );
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_signature_must_cover_the_required_components(): void {
        $input = preg_replace( '/ "ucp-agent"/', '', $this->vector_input( 'es256_get' ) );

        $this->assert_error(
            FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature-input' => $input ) ) ),
            'signature_invalid',
            401
        );
    }

    public function test_a_body_requires_a_signed_content_digest(): void {
        $input = str_replace( ' "content-digest"', '', $this->vector_input( 'es256_post' ) );

        $this->assert_error(
            FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_post', array( 'signature-input' => $input ) ) ),
            'signature_invalid',
            401
        );
    }

    public function test_a_body_without_a_digest_header_is_rejected(): void {
        $vector  = FD_Test_Platform_Vectors::data()['es256_post'];
        $headers = $vector['headers'];
        unset( $headers['content-digest'] );
        $request = FD_Test_Platform_Vectors::request(
            $headers + array( 'signature-input' => $vector['signature_input'], 'signature' => $vector['signature'] ),
            'POST',
            $vector['uri'],
            $vector['body']
        );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_replacing_body_and_digest_together_breaks_the_signature(): void {
        $body   = '{"line_items":[{"item":{"id":"1"},"quantity":99}]}';
        $digest = 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':';

        $request = FD_Test_Platform_Vectors::signed( 'es256_post', array( 'content-digest' => $digest ), $body );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_a_signature_from_another_key_does_not_verify(): void {
        $input = str_replace( 'platform-es256', 'platform-ed25519', $this->vector_input( 'es256_get' ) );

        $this->assert_error(
            FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature-input' => $input ) ) ),
            'signature_invalid',
            401
        );
    }

    public function test_a_broken_signature_never_falls_back_to_the_api_key(): void {
        FD_Test_Platform_Vectors::register( 'registered-test-key' );

        $request = FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature' => 'sig1=:AAAA:', 'x-api-key' => 'registered-test-key' ) );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_signature_input_without_a_matching_signature_member_is_rejected(): void {
        $request = FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature' => 'other=:AAAA:' ) );

        $this->assert_error( FD_Test_Platform_Vectors::auth()->authenticate( $request ), 'signature_invalid', 401 );
    }

    public function test_second_signature_member_is_tried_when_the_first_fails(): void {
        $vector = FD_Test_Platform_Vectors::data()['es256_get'];
        $second = str_replace( 'sig1=', 'sig2=', $vector['signature'] );
        $input  = 'sig1=("@method");keyid="platform-es256", ' . str_replace( 'sig1=', 'sig2=', $vector['signature_input'] );

        $request = FD_Test_Platform_Vectors::signed( 'es256_get', array(
            'signature-input' => $input,
            'signature'       => 'sig1=:AAAA:, ' . $second,
        ) );

        $this->assertArrayHasKey( 'platform_id', FD_Test_Platform_Vectors::auth()->authenticate( $request ) );
    }

    public function test_unsupported_curve_is_algorithm_unsupported(): void {
        $keys = array( array( 'kid' => 'platform-es256', 'kty' => 'EC', 'crv' => 'P-384', 'x' => 'AAAA', 'y' => 'AAAA' ) );
        $auth = FD_Test_Platform_Vectors::auth( FD_Test_Platform_Vectors::fetcher( $keys ) );

        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) ), 'algorithm_unsupported', 400 );
    }

    public function test_declared_algorithm_must_be_supported_and_match_the_key(): void {
        $unsupported = str_replace( ';keyid=', ';alg="rsa-pss-sha512";keyid=', $this->vector_input( 'es256_get' ) );
        $mismatched  = str_replace( ';keyid=', ';alg="ed25519";keyid=', $this->vector_input( 'es256_get' ) );

        foreach ( array( $unsupported, $mismatched ) as $input ) {
            $this->assert_error(
                FD_Test_Platform_Vectors::auth()->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get', array( 'signature-input' => $input ) ) ),
                'algorithm_unsupported',
                400
            );
        }
    }

    public function test_key_not_meant_for_signing_is_not_used(): void {
        $keys = FD_Test_Platform_Vectors::data()['keys'];
        $keys[0]['use'] = 'enc';
        $auth = FD_Test_Platform_Vectors::auth( FD_Test_Platform_Vectors::fetcher( $keys ) );

        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) ), 'key_not_found', 401 );
    }

    public function test_rotated_key_is_found_after_one_refresh_of_a_cached_profile(): void {
        $stale = array( array( 'kid' => 'older', 'kty' => 'EC', 'crv' => 'P-256', 'x' => 'AAAA', 'y' => 'AAAA' ) );
        FD_Test_Platform_Vectors::fetcher( $stale )->lookup( FD_Test_Platform_Vectors::PROFILE );

        $fetcher = FD_Test_Platform_Vectors::fetcher();
        $result  = FD_Test_Platform_Vectors::auth( $fetcher )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) );

        $this->assertArrayHasKey( 'platform_id', $result );
        $this->assertCount( 1, $fetcher->requested );
    }

    public function test_unknown_kid_costs_at_most_one_profile_fetch(): void {
        $fetcher = FD_Test_Platform_Vectors::fetcher( array() );

        FD_Test_Platform_Vectors::auth( $fetcher )->authenticate( FD_Test_Platform_Vectors::signed( 'es256_get' ) );

        $this->assertCount( 1, $fetcher->requested );
    }

    public function test_missing_or_insecure_agent_profile_is_an_invalid_profile_url(): void {
        $auth = FD_Test_Platform_Vectors::auth();

        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::request( array() ) ), 'invalid_profile_url', 400 );
        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="http://platform.example/ucp"' ) ) ), 'invalid_profile_url', 400 );
        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="https://user:pw@platform.example/ucp"' ) ) ), 'invalid_profile_url', 400 );
        $this->assert_error( $auth->authenticate( FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="https://platform.example/' . str_repeat( 'a', 200 ) . '"' ) ) ), 'invalid_profile_url', 400 );
    }

    public function test_normalisation_drops_fragment_default_port_and_trailing_slash(): void {
        $this->assertSame( 'https://a.example', FD_UCP_Platform_Auth::normalise_profile_url( 'https://A.example:443/#frag' ) );
        $this->assertSame( 'https://a.example:8443/ucp', FD_UCP_Platform_Auth::normalise_profile_url( 'https://a.example:8443/ucp/' ) );
        $this->assertNull( FD_UCP_Platform_Auth::normalise_profile_url( 'ftp://a.example/ucp' ) );
        $this->assertNull( FD_UCP_Platform_Auth::normalise_profile_url( "https://a.example/u cp" ) );
    }

    public function test_api_key_entry_profile_is_normalised_at_comparison_time(): void {
        FD_Test_Platform_Vectors::register( 'registered-test-key', 'HTTPS://Platform.example/.well-known/ucp/' );
        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . FD_Test_Platform_Vectors::PROFILE . '"', 'x-api-key' => 'registered-test-key' ) );

        $this->assertSame(
            array( 'platform_id' => FD_Test_Platform_Vectors::PROFILE ),
            FD_Test_Platform_Vectors::auth()->authenticate( $request )
        );
    }
}
