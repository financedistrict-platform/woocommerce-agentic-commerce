<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PrismValidatorTest extends TestCase {

    // ── extract_signed_summary() ─────────────────────────────

    public function test_extract_flat_credential(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '1500000',
            'to'      => '0xRecipient',
        );

        $summary = FD_Prism_Validator::extract_signed_summary( $cred );

        $this->assertSame( 'eip155:84532', $summary['network'] );
        $this->assertSame( '0xUsdc', $summary['asset'] );
        $this->assertSame( '1500000', $summary['value'] );
        $this->assertSame( '0xRecipient', $summary['to'] );
    }

    public function test_extract_nested_payment_payload(): void {
        $cred = array(
            'paymentPayload' => array(
                'network' => 'eip155:84532',
                'payload' => array(
                    'asset'         => '0xAsset',
                    'authorization' => array(
                        'value' => '2000000',
                        'to'    => '0xPayTo',
                    ),
                ),
                'accepted' => array(
                    'asset' => '0xAcceptedAsset',
                ),
            ),
            'paymentRequirements' => array(
                'asset' => '0xReqAsset',
            ),
        );

        $summary = FD_Prism_Validator::extract_signed_summary( $cred );

        $this->assertSame( 'eip155:84532', $summary['network'] );
        $this->assertSame( '0xReqAsset', $summary['asset'] );
        $this->assertSame( '2000000', $summary['value'] );
        $this->assertSame( '0xPayTo', $summary['to'] );
    }

    public function test_extract_base64_credential(): void {
        $data = json_encode( array(
            'network' => 'eip155:1',
            'value'   => '5000000',
            'to'      => '0xAddr',
            'asset'   => '0xToken',
        ) );
        $b64 = base64_encode( $data );

        $summary = FD_Prism_Validator::extract_signed_summary( $b64 );

        $this->assertSame( 'eip155:1', $summary['network'] );
        $this->assertSame( '5000000', $summary['value'] );
    }

    public function test_extract_returns_null_for_garbage(): void {
        $this->assertNull( FD_Prism_Validator::extract_signed_summary( 'not-valid' ) );
        $this->assertNull( FD_Prism_Validator::extract_signed_summary( 42 ) );
        $this->assertNull( FD_Prism_Validator::extract_signed_summary( array( 'foo' => 'bar' ) ) );
    }

    // ── validate_credential() ────────────────────────────────

    private function make_payment_meta( array $accepts ): array {
        return array(
            'xyz.fd.prism_payment' => array(
                'ucp' => array(
                    array(
                        array(
                            'config' => array( 'accepts' => $accepts ),
                        ),
                    ),
                ),
            ),
        );
    }

    public function test_valid_credential_passes(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '1500000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertTrue( $result );
    }

    public function test_overpayment_passes(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '2000000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertTrue( $result );
    }

    public function test_underpayment_fails(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '500000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'amount_too_low', $result->get_error_code() );
    }

    public function test_wrong_network_fails(): void {
        $cred = array(
            'network' => 'eip155:1',
            'asset'   => '0xUsdc',
            'value'   => '1500000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'no_matching_accept', $result->get_error_code() );
    }

    public function test_recipient_mismatch_fails(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '1500000',
            'to'      => '0xWrongAddr',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'recipient_mismatch', $result->get_error_code() );
    }

    public function test_case_insensitive_asset_matching(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUSDC',
            'value'   => '1500000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xusdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $this->assertTrue( FD_Prism_Validator::validate_credential( $cred, $meta ) );
    }

    public function test_missing_payment_meta_fails(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'value'   => '1500000',
        );

        $result = FD_Prism_Validator::validate_credential( $cred, null );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'missing_payment_requirements', $result->get_error_code() );
    }

    public function test_invalid_credential_format_fails(): void {
        $result = FD_Prism_Validator::validate_credential( 'garbage', $this->make_payment_meta( array() ) );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'invalid_credential', $result->get_error_code() );
    }

    // ── big number comparison ────────────────────────────────

    public function test_large_amounts_compared_correctly(): void {
        $cred = array(
            'network' => 'eip155:84532',
            'asset'   => '0xUsdc',
            'value'   => '999999999999999999',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1000000000000000000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $result = FD_Prism_Validator::validate_credential( $cred, $meta );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'amount_too_low', $result->get_error_code() );
    }

    public function test_multi_network_accepts_matches_correct_one(): void {
        $cred = array(
            'network' => 'eip155:1',
            'asset'   => '0xUsdc',
            'value'   => '2000000',
            'to'      => '0xPayTo',
        );
        $meta = $this->make_payment_meta( array(
            array(
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
            array(
                'network' => 'eip155:1',
                'asset'   => '0xUsdc',
                'amount'  => '1500000',
                'payTo'   => '0xPayTo',
            ),
        ) );

        $this->assertTrue( FD_Prism_Validator::validate_credential( $cred, $meta ) );
    }
}
