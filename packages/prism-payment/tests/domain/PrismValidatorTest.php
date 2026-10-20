<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PrismValidatorTest extends TestCase {

    private const RESOURCE_URL = 'https://store.test/checkout-sessions/c1';

    private static function requirement( array $overrides = array() ): array {
        return array_merge(
            array(
                'scheme'  => 'exact',
                'network' => 'eip155:84532',
                'asset'   => '0xUsdc',
                'payTo'   => '0xPayTo',
                'amount'  => '1500000',
            ),
            $overrides
        );
    }

    private static function credential( array $accepted, array $authorization = array() ): array {
        return array(
            'paymentPayload' => array(
                'x402Version' => 2,
                'accepted'    => $accepted,
                'payload'     => array(
                    'signature'     => '0xsig',
                    'authorization' => array_merge(
                        array(
                            'from'        => '0xPayer',
                            'to'          => $accepted['payTo'],
                            'value'       => $accepted['amount'],
                            'validAfter'  => '0',
                            'validBefore' => (string) ( time() + 300 ),
                            'nonce'       => '0x' . str_repeat( '0a', 32 ),
                        ),
                        $authorization
                    ),
                ),
            ),
        );
    }

    private static function meta( array $accepts ): array {
        return array(
            'x402Version' => 2,
            'resource'    => array( 'url' => self::RESOURCE_URL ),
            'accepts'     => $accepts,
        );
    }

    private static function assertErrorCode( string $code, $result ): void {
        self::assertInstanceOf( WP_Error::class, $result );
        self::assertSame( $code, $result->get_error_code() );
    }

    public function test_valid_credential_returns_the_stored_requirement(): void {
        $verified = FD_Prism_Validator::verify( self::credential( self::requirement() ), self::meta( array( self::requirement() ) ) );

        $this->assertSame( self::requirement(), $verified['payment_requirements'] );
        $this->assertSame( 2, $verified['x402_version'] );
        $this->assertSame( '0xPayer', $verified['payer'] );
        $this->assertSame( '1500000', $verified['value'] );
    }

    public function test_base64_credential_is_decoded(): void {
        $credential = base64_encode( json_encode( self::credential( self::requirement() ) ) );

        $this->assertIsArray( FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
    }

    public function test_overpayment_fails(): void {
        $credential = self::credential( self::requirement(), array( 'value' => '2000000' ) );

        self::assertErrorCode( 'amount_mismatch', FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
    }

    public function test_underpayment_fails(): void {
        $credential = self::credential( self::requirement(), array( 'value' => '500000' ) );

        self::assertErrorCode( 'amount_mismatch', FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
    }

    public function test_wrong_network_fails(): void {
        $credential = self::credential( self::requirement( array( 'network' => 'eip155:1' ) ) );

        self::assertErrorCode( 'no_matching_accept', FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
    }

    public function test_recipient_mismatch_fails(): void {
        $credential = self::credential( self::requirement(), array( 'to' => '0xWrongAddr' ) );

        self::assertErrorCode( 'recipient_mismatch', FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
    }

    public function test_case_insensitive_address_matching(): void {
        $credential = self::credential( self::requirement( array( 'asset' => '0xUSDC', 'payTo' => '0xPAYTO' ) ) );

        $verified = FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) );

        $this->assertSame( self::requirement(), $verified['payment_requirements'] );
    }

    public function test_missing_payment_meta_fails(): void {
        self::assertErrorCode( 'missing_payment_requirements', FD_Prism_Validator::verify( self::credential( self::requirement() ), null ) );
    }

    public function test_quote_keeps_only_settleable_entries(): void {
        $quote = FD_Prism_Validator::parse_quote( self::meta( array(
            self::requirement( array( 'scheme' => 'upto' ) ),
            self::requirement( array( 'network' => 'solana:mainnet' ) ),
            self::requirement( array( 'amount' => 1500000 ) ),
            self::requirement(),
        ) ) );

        $this->assertSame( array( self::requirement() ), $quote['accepts'] );
    }

    public function test_quote_with_another_x402_version_is_refused(): void {
        $this->assertNull( FD_Prism_Validator::parse_quote( array( 'x402Version' => 1 ) + self::meta( array( self::requirement() ) ) ) );
    }

    public function test_stored_entry_with_missing_field_never_matches(): void {
        $stored = self::requirement();
        unset( $stored['payTo'] );

        self::assertErrorCode( 'missing_payment_requirements', FD_Prism_Validator::verify( self::credential( self::requirement() ), self::meta( array( $stored ) ) ) );
    }

    public function test_invalid_credential_format_fails(): void {
        foreach ( array( 'garbage', 42, array( 'foo' => 'bar' ) ) as $credential ) {
            self::assertErrorCode( 'invalid_credential', FD_Prism_Validator::verify( $credential, self::meta( array( self::requirement() ) ) ) );
        }
    }

    public function test_large_amounts_compared_correctly(): void {
        $stored     = self::requirement( array( 'amount' => '1000000000000000000' ) );
        $credential = self::credential( $stored, array( 'value' => '999999999999999999' ) );

        self::assertErrorCode( 'amount_mismatch', FD_Prism_Validator::verify( $credential, self::meta( array( $stored ) ) ) );
    }

    public function test_multi_network_accepts_matches_correct_one(): void {
        $base     = self::requirement();
        $mainnet  = self::requirement( array( 'network' => 'eip155:1' ) );
        $verified = FD_Prism_Validator::verify( self::credential( $mainnet ), self::meta( array( $base, $mainnet ) ) );

        $this->assertSame( $mainnet, $verified['payment_requirements'] );
    }
}
