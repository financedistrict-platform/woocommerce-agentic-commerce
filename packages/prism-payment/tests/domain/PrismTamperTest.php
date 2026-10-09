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

    private FD_Test_Claims_Wpdb $db;

    protected function setUp(): void {
        $this->db        = new FD_Test_Claims_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
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

    private function settle( $credential, string $checkout_id = 'c1' ): array {
        return ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => $checkout_id,
            'credential'    => $credential,
            'checkout_meta' => self::quoted_meta(),
        ) );
    }

    private static function quoted_meta(): array {
        $meta = self::meta();
        $meta['xyz.fd.prism_payment']['prepared_amount'] = 10001;
        return $meta;
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

    public function test_storefront_checkout_never_offers_the_prism_gateway(): void {
        $GLOBALS['fd_test_options']['woocommerce_fd_prism_x402_settings'] = array(
            'enabled' => 'yes',
            'api_url' => self::GW,
            'api_key' => 'key',
        );

        $gateway = new FD_Prism_Gateway();

        $this->assertSame( 'yes', $gateway->enabled );
        $this->assertFalse( $gateway->is_available() );
    }

    public function test_storefront_checkout_never_reports_a_payment_as_taken(): void {
        $GLOBALS['fd_test_notices'] = array();

        $result = ( new FD_Prism_Gateway() )->process_payment( 123 );

        $this->assertSame( 'failure', $result['result'] );
        $this->assertArrayNotHasKey( 'redirect', $result );
        $this->assertCount( 1, $GLOBALS['fd_test_notices'] );
        $this->assertSame( 'error', $GLOBALS['fd_test_notices'][0]['type'] );
    }

    public function test_one_signed_authorization_settles_only_one_checkout_session(): void {
        $credential = self::credential();

        $first  = $this->settle( $credential, 'c1' );
        $second = $this->settle( $credential, 'c2' );

        $this->assertTrue( $first['success'] );
        $this->assertFalse( $second['success'] );
        $this->assertCount( 1, $GLOBALS['fd_test_requests'] );
    }

    public function test_same_session_may_retry_its_own_authorization(): void {
        $credential = self::credential();

        $first  = $this->settle( $credential );
        $second = $this->settle( $credential );

        $this->assertTrue( $first['success'] );
        $this->assertTrue( $second['success'] );
        $this->assertCount( 2, $GLOBALS['fd_test_requests'] );
    }

    public function test_authorization_is_claimed_before_prism_is_called(): void {
        $GLOBALS['fd_test_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
        $credential                       = self::credential();

        $failed = $this->settle( $credential, 'c1' );
        $GLOBALS['fd_test_requests'] = array();
        $other  = $this->settle( $credential, 'c2' );

        $this->assertFalse( $failed['success'] );
        $this->assertFalse( $other['success'] );
        $this->assertSame( array(), $GLOBALS['fd_test_requests'] );
    }

    public function test_claim_ignores_the_case_of_payer_and_nonce(): void {
        $first                                                       = self::credential();
        $second                                                      = self::credential();
        $second['paymentPayload']['payload']['authorization']['from']  = strtolower( self::PAYER );
        $second['paymentPayload']['payload']['authorization']['nonce'] = '0x' . substr( strtoupper( self::NONCE ), 2 );

        $this->settle( $first, 'c1' );
        $GLOBALS['fd_test_requests'] = array();

        $result = $this->settle( $second, 'c2' );

        $this->assertRejectedBeforeSettle( $result );
    }

    public function test_a_fresh_nonce_settles_on_another_session(): void {
        $other                                                         = self::credential();
        $other['paymentPayload']['payload']['authorization']['nonce'] = '0x' . str_repeat( '12', 32 );

        $this->settle( self::credential(), 'c1' );
        $result = $this->settle( $other, 'c2' );

        $this->assertTrue( $result['success'] );
        $this->assertCount( 2, $GLOBALS['fd_test_requests'] );
    }

    private function respond( array $body ): void {
        $GLOBALS['fd_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $body ) );
    }

    private static function settled_response( array $override = array() ): array {
        return $override + array( 'success' => true, 'payer' => self::PAYER, 'transaction' => '0xabc', 'network' => self::NETWORK );
    }

    public static function unsettled_responses(): array {
        return array(
            'success is false'                    => array( self::settled_response( array( 'success' => false ) ) ),
            'success is false without hash'       => array( self::settled_response( array( 'success' => false, 'transaction' => '' ) ) ),
            'success flag absent, no hash'        => array( array( 'payer' => self::PAYER, 'network' => self::NETWORK ) ),
            'success is the string true, no hash' => array( array( 'success' => 'true', 'network' => self::NETWORK ) ),
            'empty object'                        => array( array() ),
        );
    }

    #[DataProvider( 'unsettled_responses' )]
    public function test_settlement_without_an_explicit_success_is_not_accepted( array $body ): void {
        $this->respond( $body );

        $result = $this->settle( self::credential() );

        $this->assertFalse( $result['success'] );
    }

    public static function inconsistent_responses(): array {
        return array(
            'success flag absent, hash present'   => array( array( 'payer' => self::PAYER, 'transaction' => '0xabc', 'network' => self::NETWORK ) ),
            'success is the string true'          => array( self::settled_response( array( 'success' => 'true' ) ) ),
            'success is the number one'           => array( self::settled_response( array( 'success' => 1 ) ) ),
            'network differs from the quote'   => array( self::settled_response( array( 'network' => 'eip155:1' ) ) ),
            'network missing'                  => array( array( 'success' => true, 'payer' => self::PAYER, 'transaction' => '0xabc' ) ),
            'payer differs from the signer'    => array( self::settled_response( array( 'payer' => '0x2222222222222222222222222222222222222222' ) ) ),
            'payer is not a string'            => array( self::settled_response( array( 'payer' => array( self::PAYER ) ) ) ),
            'amount differs from the quote'    => array( self::settled_response( array( 'amount' => '1' ) ) ),
            'amount is not an integer string'  => array( self::settled_response( array( 'amount' => '100010.5' ) ) ),
            'transaction hash missing'         => array( array( 'success' => true, 'payer' => self::PAYER, 'network' => self::NETWORK ) ),
            'transaction hash empty'           => array( self::settled_response( array( 'transaction' => '' ) ) ),
            'transaction hash is not a string' => array( self::settled_response( array( 'transaction' => array( '0xabc' ) ) ) ),
        );
    }

    #[DataProvider( 'inconsistent_responses' )]
    public function test_settlement_that_disagrees_with_the_quote_is_held_for_review( array $body ): void {
        $this->respond( $body );

        $result = $this->settle( self::credential() );

        $this->assertTrue( $result['success'] );
        $this->assertNull( $result['settled_amount'] );
        $this->assertNotSame( '', (string) ( $result['hold_reason'] ?? '' ) );
    }

    public function test_settlement_matching_the_quote_reports_the_quoted_amount_and_no_hold(): void {
        $meta = self::meta();
        $meta['xyz.fd.prism_payment']['prepared_amount'] = 10001;
        $this->respond( self::settled_response( array( 'amount' => self::AMOUNT, 'payer' => strtolower( self::PAYER ) ) ) );

        $result = ( new FD_Prism_Handler( self::GW, 'key' ) )->settle_payment( array(
            'checkout_id'   => 'c1',
            'credential'    => self::credential(),
            'checkout_meta' => $meta,
        ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( 10001, $result['settled_amount'] );
        $this->assertArrayNotHasKey( 'hold_reason', $result );
        $this->assertSame( '0xabc', $result['transaction_reference'] );
    }

    public function test_settlement_amount_sent_as_a_json_integer_matches_the_quote(): void {
        $this->respond( self::settled_response( array( 'amount' => (int) self::AMOUNT ) ) );

        $result = $this->settle( self::credential() );

        $this->assertTrue( $result['success'] );
        $this->assertArrayNotHasKey( 'hold_reason', $result );
    }

    public function test_hold_reason_names_the_observed_and_the_stored_value(): void {
        $this->respond( self::settled_response( array( 'network' => 'eip155:1' ) ) );

        $result = $this->settle( self::credential() );

        $this->assertStringContainsString( 'eip155:1', $result['hold_reason'] );
        $this->assertStringContainsString( self::NETWORK, $result['hold_reason'] );
    }

    public function test_inconsistent_settlement_still_records_the_transaction_for_the_merchant(): void {
        $this->respond( self::settled_response( array( 'network' => 'eip155:1' ) ) );

        $result = $this->settle( self::credential() );

        $this->assertSame( '0xabc', $result['transaction_reference'] );
        $this->assertSame( '0xabc', $result['order_meta']['_fd_prism_tx_hash'] );
    }

    public static function token_amounts(): array {
        return array(
            'usdc six decimals'        => array( '100010', '0x036CbD53842c5426634e7929541eC2318f3dCF7e', '0.100010 USDC' ),
            'fdusd eighteen decimals'  => array( '1500000000000000000', '0xaB27f55dB008704eD8098F0dFBcF5E1aa387B9D9', '1.500000000000000000 FDUSD' ),
            'fdusd sub-unit'           => array( '1', '0xab27f55db008704ed8098f0dfbcf5e1aa387b9d9', '0.000000000000000001 FDUSD' ),
            'usdc whole units'         => array( '25000000', '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', '25.000000 USDC' ),
            'unknown token'            => array( '1500000', '0x9999999999999999999999999999999999999999', '1500000 atomic units of 0x9999999999999999999999999999999999999999' ),
            'unknown token no address' => array( '1500000', '', '1500000 atomic units' ),
            'amount is not digits'     => array( '1e6', '0x036cbd53842c5426634e7929541ec2318f3dcf7e', '' ),
        );
    }

    #[DataProvider( 'token_amounts' )]
    public function test_admin_amount_uses_the_decimals_of_the_settled_token( string $atomic, string $asset, string $expected ): void {
        $this->assertSame( $expected, FD_Prism_Tokens::amount_label( $atomic, $asset ) );
    }

    public function test_unavailable_claim_store_rejects_before_settle(): void {
        $this->db->broken = true;

        $result = $this->settle( self::credential() );

        $this->assertRejectedBeforeSettle( $result );
    }
}
