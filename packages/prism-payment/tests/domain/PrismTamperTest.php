<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrismTamperTest extends TestCase {

    private const GW           = 'https://gateway.test';
    private const RESOURCE_URL = 'https://store.test/wp-json/fd-ucp/v1/checkout-sessions/c1';
    private const NETWORK      = 'eip155:84532';
    private const ASSET        = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
    private const PAY_TO       = '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd';
    private const PAYER        = '0xb5004598bBf235A30494339500601D0cD8E5367A';
    private const AMOUNT       = '100010';
    private const NONCE        = '0x' . 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';

    protected function setUp(): void {
        $GLOBALS['fd_test_requests']      = array();
        $GLOBALS['fd_test_http_response'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => json_encode( array( 'success' => true, 'payer' => self::PAYER, 'transaction' => '0xabc', 'network' => self::NETWORK ) ),
        );
    }

    private static function stored_entry(): array {
        return array(
            'scheme'            => 'exact',
            'network'           => self::NETWORK,
            'asset'             => self::ASSET,
            'payTo'             => self::PAY_TO,
            'amount'            => self::AMOUNT,
            'maxTimeoutSeconds' => 300,
            'extra'             => array( 'name' => 'USDC', 'version' => '2' ),
        );
    }

    private static function meta(): array {
        return array( 'xyz.fd.prism_payment' => array( 'ucp' => array( 'xyz.fd.prism_payment' => array( array(
            'id'      => 'xyz.fd.prism_payment',
            'version' => '2026-10-07',
            'config'  => array(
                'x402Version' => 2,
                'resource'    => array( 'url' => self::RESOURCE_URL, 'description' => 'Order at Store' ),
                'accepts'     => array( self::stored_entry() ),
            ),
        ) ) ) ) );
    }

    private static function authorization(): array {
        return array(
            'from'        => self::PAYER,
            'to'          => self::PAY_TO,
            'value'       => self::AMOUNT,
            'validAfter'  => (string) ( time() - 60 ),
            'validBefore' => (string) ( time() + 300 ),
            'nonce'       => self::NONCE,
        );
    }

    private static function credential(): array {
        return array(
            'x402Version'    => 2,
            'paymentPayload' => array(
                'x402Version' => 2,
                'resource'    => array( 'url' => self::RESOURCE_URL ),
                'accepted'    => self::stored_entry(),
                'payload'     => array(
                    'signature'     => '0x' . str_repeat( 'cd', 65 ),
                    'authorization' => self::authorization(),
                ),
            ),
        );
    }

    private function settle( $credential ): array {
        return ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => 'c1',
            'credential'    => $credential,
            'checkout_meta' => self::meta(),
        ) );
    }

    private static function settle_body(): array {
        return json_decode( $GLOBALS['fd_test_requests'][0]['args']['body'], true );
    }

    private function assertRejectedBeforeSettle( array $result ): void {
        $this->assertFalse( $result['success'] );
        $this->assertSame( array(), $GLOBALS['fd_test_requests'] );
    }

    public function test_untampered_credential_settles_against_the_stored_requirements(): void {
        $credential = self::credential();

        $result = $this->settle( $credential );

        $this->assertTrue( $result['success'] );
        $this->assertCount( 1, $GLOBALS['fd_test_requests'] );
        $this->assertSame( self::GW . '/api/v2/payment/settle', $GLOBALS['fd_test_requests'][0]['url'] );
        $body = self::settle_body();
        $this->assertSame( self::stored_entry(), $body['paymentRequirements'] );
        $this->assertSame( self::stored_entry(), $body['paymentPayload']['accepted'] );
        $this->assertSame( $credential['paymentPayload']['payload'], $body['paymentPayload']['payload'] );
        $this->assertSame( self::RESOURCE_URL, $body['paymentPayload']['resource']['url'] );
        $this->assertSame( self::NETWORK, $result['order_meta']['_fd_prism_network'] );
        $this->assertSame( self::ASSET, $result['order_meta']['_fd_prism_asset'] );
        $this->assertSame( self::AMOUNT, $result['order_meta']['_fd_prism_amount'] );
    }

    public function test_encoded_credential_settles_with_the_same_body(): void {
        $credential = self::credential();
        $this->settle( base64_encode( json_encode( $credential ) ) );
        $encoded = self::settle_body();
        $GLOBALS['fd_test_requests'] = array();

        $this->settle( $credential );

        $this->assertSame( self::settle_body(), $encoded );
    }

    public function test_forwarded_requirements_are_rebuilt_from_the_stored_entry(): void {
        $credential                        = self::credential();
        $credential['paymentRequirements'] = array_merge( self::stored_entry(), array(
            'network' => 'eip155:1',
            'asset'   => '0x9999999999999999999999999999999999999999',
            'amount'  => '1',
        ) );

        $result = $this->settle( $credential );

        $this->assertTrue( $result['success'] );
        $this->assertSame( self::stored_entry(), self::settle_body()['paymentRequirements'] );
    }

    public function test_only_the_signed_payload_fields_are_forwarded(): void {
        $credential                                                      = self::credential();
        $expected                                                        = $credential['paymentPayload']['payload'];
        $credential['paymentPayload']['extensions']                      = array( 'x' => 1 );
        $credential['paymentPayload']['payload']['asset']                = '0x9999999999999999999999999999999999999999';
        $credential['paymentPayload']['payload']['authorization']['to2'] = '0x2222222222222222222222222222222222222222';

        $this->settle( $credential );

        $this->assertSame( $expected, self::settle_body()['paymentPayload']['payload'] );
        $this->assertArrayNotHasKey( 'extensions', self::settle_body()['paymentPayload'] );
    }

    public function test_bare_payment_payload_settles_like_the_wrapped_one(): void {
        $credential = self::credential();
        $this->settle( $credential['paymentPayload'] );
        $bare = self::settle_body();
        $GLOBALS['fd_test_requests'] = array();

        $this->settle( $credential );

        $this->assertSame( self::settle_body(), $bare );
    }

    public function test_small_clock_skew_on_valid_after_is_tolerated(): void {
        $credential = self::credential();
        $credential['paymentPayload']['payload']['authorization']['validAfter'] = (string) ( time() + 5 );

        $this->assertTrue( $this->settle( $credential )['success'] );
    }

    public function test_client_x402_version_does_not_pick_the_settle_route(): void {
        $credential                                  = self::credential();
        $credential['x402Version']                   = 1;
        $credential['paymentPayload']['x402Version'] = 1;

        $this->assertRejectedBeforeSettle( $this->settle( $credential ) );
    }

    public static function tampered_credentials(): array {
        $cases = array();

        $c = self::credential();
        $c['paymentPayload']['network']             = self::NETWORK;
        $c['paymentPayload']['accepted']['network'] = 'eip155:1';
        $cases['payload network matches quote, accepted network differs'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['network'] = 'eip155:56';
        $cases['payload network differs from accepted network'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['accepted']['asset'] = '0x9999999999999999999999999999999999999999';
        $c['paymentRequirements']                 = self::stored_entry();
        $cases['accepted asset differs, forwarded requirements copy the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['accepted']['payTo'] = '0x2222222222222222222222222222222222222222';
        $cases['accepted payTo differs from the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['accepted']['amount'] = '1';
        $cases['accepted amount differs from the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['payload']['authorization']['value'] = '100000000';
        $cases['signed value above the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['payload']['authorization']['value'] = '100009';
        $cases['signed value below the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['network'] = self::NETWORK;
        unset( $c['paymentPayload']['accepted']['network'] );
        $cases['accepted without network'] = array( $c );

        $c             = self::credential();
        $c['accepted'] = self::stored_entry();
        $cases['wrapped and bare payload in one credential'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['accepted']['scheme'] = 'upto';
        $cases['accepted scheme differs from the quote'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['payload']['authorization']['validBefore'] = (string) ( time() - 3600 );
        $cases['authorization already expired'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['payload']['authorization']['validAfter'] = (string) ( time() + 3600 );
        $cases['authorization not yet valid'] = array( $c );

        $c = self::credential();
        $c['paymentPayload']['resource']['url'] = 'https://store.test/wp-json/fd-ucp/v1/checkout-sessions/other';
        $cases['resource url names another session'] = array( $c );

        foreach ( array( 'from', 'nonce', 'validBefore', 'validAfter' ) as $field ) {
            $c = self::credential();
            unset( $c['paymentPayload']['payload']['authorization'][ $field ] );
            $cases[ "authorization without $field" ] = array( $c );
        }

        $c = self::credential();
        unset( $c['paymentPayload']['payload']['signature'] );
        $cases['payload without signature'] = array( $c );

        $c = self::credential();
        unset( $c['paymentPayload']['accepted'] );
        $cases['payload without accepted'] = array( $c );

        $c                  = self::credential();
        $c['authorization'] = array( 'paymentPayload' => self::credential()['paymentPayload'] );
        $c['authorization']['paymentPayload']['payload']['authorization']['value'] = '1';
        $cases['credential carrying both authorization and paymentPayload'] = array( $c );

        $cases['flat credential shape'] = array( array(
            'network' => self::NETWORK,
            'asset'   => self::ASSET,
            'value'   => self::AMOUNT,
            'to'      => self::PAY_TO,
            'from'    => self::PAYER,
        ) );

        return $cases;
    }

    #[DataProvider( 'tampered_credentials' )]
    public function test_tampered_credential_is_rejected_before_settle( array $credential ): void {
        $this->assertRejectedBeforeSettle( $this->settle( $credential ) );
    }

    public function test_stored_quote_without_resource_url_is_rejected(): void {
        $meta = self::meta();
        unset( $meta['xyz.fd.prism_payment']['ucp']['xyz.fd.prism_payment'][0]['config']['resource'] );

        $result = ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => 'c1',
            'credential'    => self::credential(),
            'checkout_meta' => $meta,
        ) );

        $this->assertRejectedBeforeSettle( $result );
    }

    public function test_stored_amount_that_is_not_an_integer_never_matches(): void {
        $meta  = self::meta();
        $entry = array_merge( self::stored_entry(), array( 'amount' => '1000.10' ) );
        $meta['xyz.fd.prism_payment']['ucp']['xyz.fd.prism_payment'][0]['config']['accepts'] = array( $entry );
        $credential = self::credential();
        $credential['paymentPayload']['accepted']                          = $entry;
        $credential['paymentPayload']['payload']['authorization']['value'] = '1000.10';

        $result = ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => 'c1',
            'credential'    => $credential,
            'checkout_meta' => $meta,
        ) );

        $this->assertRejectedBeforeSettle( $result );
    }

    public function test_stored_quote_without_x402_version_is_rejected(): void {
        $meta = self::meta();
        unset( $meta['xyz.fd.prism_payment']['ucp']['xyz.fd.prism_payment'][0]['config']['x402Version'] );

        $result = ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => 'c1',
            'credential'    => self::credential(),
            'checkout_meta' => $meta,
        ) );

        $this->assertRejectedBeforeSettle( $result );
    }
}
