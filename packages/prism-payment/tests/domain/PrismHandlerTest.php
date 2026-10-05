<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PrismHandlerTest extends TestCase {

    private const GW = 'https://gateway.test';

    private const CURRENT_VERSION = FD_UCP_Version_Registry::DEFAULT_CURRENT;

    protected function setUp(): void {
        $GLOBALS['fd_test_transients']    = array();
        $GLOBALS['fd_test_options']       = array();
        $GLOBALS['fd_test_requests']      = array();
        $GLOBALS['fd_test_http_response'] = null;
        FD_UCP_Request_Context::set( null );
    }

    private static function contract(): array {
        return array(
            'xyz.fd.prism_payment' => array(
                array(
                    'id'                    => 'xyz.fd.prism_payment',
                    'version'               => '2026-10-07',
                    'spec'                  => self::GW . '/ucp/prism.md',
                    'schema'                => self::GW . '/ucp/schema.json',
                    'available_instruments' => array( array( 'type' => 'x402' ) ),
                    'config'                => array(),
                ),
            ),
        );
    }

    private static function legacy(): array {
        return json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/core/tests/fixtures/prism/legacy-handlers.json' ), true );
    }

    private function handler( ?array $fetched = null ): FD_Prism_Handler {
        $handler = new FD_Prism_Handler( self::GW, 'key' );
        $client  = new class( $fetched ) extends FD_Prism_Client {
            private ?array $fetched;
            public array $versions = array();

            public function __construct( ?array $fetched ) {
                parent::__construct( 'https://gateway.test', 'key' );
                $this->fetched = $fetched;
            }

            public function fetch_ucp_handlers( string $ucp_version ): ?array {
                $this->versions[] = $ucp_version;
                return $this->fetched;
            }
        };
        ( new ReflectionProperty( FD_Prism_Handler::class, 'client' ) )->setValue( $handler, $client );
        return $handler;
    }

    private function call( FD_Prism_Handler $handler, string $method, ...$args ) {
        return ( new ReflectionMethod( FD_Prism_Handler::class, $method ) )->invoke( $handler, ...$args );
    }

    private static function instrument( array $overrides = array() ): array {
        return array_merge(
            array(
                'id'         => 'inst_1',
                'handler_id' => 'xyz.fd.prism_payment',
                'type'       => 'x402',
                'credential' => array( 'type' => 'x402', 'x402Version' => 2 ),
            ),
            $overrides
        );
    }

    public function test_guard_accepts_contract_entry(): void {
        $this->assertNotNull( $this->call( $this->handler(), 'canonical_handlers', self::contract() ) );
    }

    public function test_guard_rejects_missing_schema(): void {
        $handlers = self::contract();
        unset( $handlers['xyz.fd.prism_payment'][0]['schema'] );
        $this->assertNull( $this->call( $this->handler(), 'canonical_handlers', $handlers ) );
    }

    public function test_guard_rejects_unknown_id(): void {
        $handlers = self::contract();
        $handlers['xyz.fd.prism_payment'][0]['id'] = 'other';
        $this->assertNull( $this->call( $this->handler(), 'canonical_handlers', $handlers ) );
    }

    public function test_legacy_entry_yields_one_canonical_entry(): void {
        $wire = $this->handler( self::legacy() )->get_ucp_discovery_handlers_for_version( '2026-08-25' );

        $this->assertCount( 1, $wire['xyz.fd.prism_payment'] );
        $entry = $wire['xyz.fd.prism_payment'][0];
        $this->assertSame( 'xyz.fd.prism_payment', $entry['id'] );
        $this->assertSame( 'https://gw.example/ucp/schema.json', $entry['schema'] );
        $this->assertSame( array( 'https://gw.example/ucp/instrument_schema.json' ), $entry['instrument_schemas'] );
        $this->assertSame( 'xyz.fd.prism_payment', $entry['name'] );
    }

    public function test_entry_without_available_instruments_is_accepted(): void {
        $handlers = self::contract();
        unset( $handlers['xyz.fd.prism_payment'][0]['available_instruments'] );

        $wire = $this->handler( $handlers )->get_ucp_discovery_handlers_for_version( '2026-01-23' );

        $this->assertSame( 'xyz.fd.prism_payment', $wire['xyz.fd.prism_payment'][0]['id'] );
    }

    public function test_discovery_emits_canonical_entry_and_caches_raw_response_per_version(): void {
        $handler = $this->handler( self::contract() );
        $wire    = $handler->get_ucp_discovery_handlers_for_version( '2026-08-25' );

        $this->assertSame(
            array( 'id', 'version', 'spec', 'schema', 'available_instruments', 'config', 'instrument_schemas', 'name' ),
            array_keys( $wire['xyz.fd.prism_payment'][0] )
        );
        $this->assertSame( self::contract(), $GLOBALS['fd_test_transients'][ FD_Prism_Handler::cache_key( self::GW, '2026-08-25' ) ] );
        $this->assertSame( self::contract(), $GLOBALS['fd_test_options'][ FD_Prism_Handler::stale_key( self::GW, '2026-08-25' ) ] );
    }

    public function test_cache_and_stale_keys_differ_per_version(): void {
        $keys = array();
        foreach ( FD_UCP_Version_Registry::known() as $version ) {
            $keys[] = FD_Prism_Handler::cache_key( self::GW, $version );
            $keys[] = FD_Prism_Handler::stale_key( self::GW, $version );
        }
        $this->assertCount( 6, array_unique( $keys ) );
        $this->assertNotSame( FD_Prism_Handler::cache_key( self::GW, '2026-08-25' ), FD_Prism_Handler::cache_key( 'https://other.test', '2026-08-25' ) );
    }

    public function test_discovery_asks_prism_for_the_requested_version(): void {
        $handler = $this->handler( self::contract() );
        $handler->get_ucp_discovery_handlers_for_version( '2026-01-23' );

        $client = ( new ReflectionProperty( FD_Prism_Handler::class, 'client' ) )->getValue( $handler );
        $this->assertSame( array( '2026-01-23' ), $client->versions );
    }

    public function test_unversioned_discovery_uses_the_current_version(): void {
        $handler = $this->handler( self::contract() );
        $handler->get_ucp_discovery_handlers();

        $client = ( new ReflectionProperty( FD_Prism_Handler::class, 'client' ) )->getValue( $handler );
        $this->assertSame( array( self::CURRENT_VERSION ), $client->versions );
    }

    public function test_discovery_rejects_malformed_cache_fetch_and_stale(): void {
        $bad = self::contract();
        unset( $bad['xyz.fd.prism_payment'][0]['schema'] );
        $GLOBALS['fd_test_transients'][ FD_Prism_Handler::cache_key( self::GW, '2026-08-25' ) ] = $bad;
        $GLOBALS['fd_test_options'][ FD_Prism_Handler::stale_key( self::GW, '2026-08-25' ) ]    = $bad;

        $this->assertSame( array(), $this->handler( $bad )->get_ucp_discovery_handlers_for_version( '2026-08-25' ) );
        $this->assertSame( $bad, $GLOBALS['fd_test_options'][ FD_Prism_Handler::stale_key( self::GW, '2026-08-25' ) ] );
    }

    public function test_discovery_falls_back_to_valid_stale_entry_of_the_same_version(): void {
        $GLOBALS['fd_test_options'][ FD_Prism_Handler::stale_key( self::GW, '2026-08-25' ) ] = self::contract();

        $wire = $this->handler( null )->get_ucp_discovery_handlers_for_version( '2026-08-25' );
        $this->assertSame( 'xyz.fd.prism_payment', $wire['xyz.fd.prism_payment'][0]['id'] );

        $this->assertSame( array(), $this->handler( null )->get_ucp_discovery_handlers_for_version( '2026-01-23' ) );
    }

    public function test_global_stale_option_is_ignored(): void {
        $GLOBALS['fd_test_options']['_fd_prism_discovery_stale'] = self::contract();

        $this->assertSame( array(), $this->handler( null )->get_ucp_discovery_handlers_for_version( '2026-08-25' ) );
    }

    public function test_client_sends_the_ucp_version_in_the_path_and_no_query(): void {
        $client = new FD_Prism_Client( self::GW, 'key' );
        $client->fetch_ucp_handlers( '2026-08-25' );
        $client->prepare_ucp_payment( '10.00', 'EUR', 'https://store.test/c/1', 'Order', '2026-04-08' );
        $client->settle( array( 'x402Version' => 2, 'paymentPayload' => array() ) );

        $requests = $GLOBALS['fd_test_requests'];
        $this->assertCount( 3, $requests );
        foreach ( $requests as $request ) {
            $this->assertStringNotContainsString( 'ucp_version', $request['url'] );
        }
        $this->assertSame( self::GW . '/api/v2/merchant/ucp/2026-08-25/handlers', $requests[0]['url'] );
        $this->assertSame( self::GW . '/api/v2/merchant/ucp/2026-04-08/payment-requirements', $requests[1]['url'] );
        $this->assertSame( self::GW . '/api/v2/payment/settle', $requests[2]['url'] );
    }

    public function test_handler_sends_the_pinned_version_on_prepare_and_the_discovery_version_on_discovery(): void {
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::for_version( '2026-04-08' ) );
        $GLOBALS['fd_test_http_response'] = array( 'body' => '{}', 'response' => array( 'code' => 200 ) );
        $handler = new FD_Prism_Handler( self::GW, 'key' );

        $handler->prepare_checkout_payment( array(
            'total'             => 1000,
            'currency'          => 'EUR',
            'checkout_id'       => 'c1',
            'checkout_base_url' => 'https://store.test',
            'store_name'        => 'Store',
        ) );
        $handler->get_ucp_discovery_handlers_for_version( '2026-01-23' );

        $requests = $GLOBALS['fd_test_requests'];
        $this->assertSame( self::GW . '/api/v2/merchant/ucp/2026-04-08/payment-requirements', $requests[0]['url'] );
        $this->assertSame( self::GW . '/api/v2/merchant/ucp/2026-01-23/handlers', $requests[1]['url'] );
    }

    public function test_prepare_reuses_the_quote_for_the_same_version_and_reprepares_for_another(): void {
        $GLOBALS['fd_test_http_response'] = array( 'body' => '{"accepts":[{"scheme":"exact"}]}', 'response' => array( 'code' => 200 ) );
        $handler = new FD_Prism_Handler( self::GW, 'key' );
        $input   = array(
            'total'             => 1000,
            'currency'          => 'EUR',
            'checkout_id'       => 'c1',
            'checkout_base_url' => 'https://store.test',
            'store_name'        => 'Store',
        );

        FD_UCP_Request_Context::set( FD_UCP_Request_Context::for_version( '2026-04-08' ) );
        $first = $handler->prepare_checkout_payment( $input );
        $this->assertSame( '2026-04-08', $first['prepared_version'] );
        $this->assertCount( 1, $GLOBALS['fd_test_requests'] );

        $input['checkout_meta'] = array( 'xyz.fd.prism_payment' => $first );
        $handler->prepare_checkout_payment( $input );
        $this->assertCount( 1, $GLOBALS['fd_test_requests'] );

        FD_UCP_Request_Context::set( FD_UCP_Request_Context::for_version( '2026-08-25' ) );
        $second = $handler->prepare_checkout_payment( $input );
        $this->assertCount( 2, $GLOBALS['fd_test_requests'] );
        $this->assertSame( '2026-08-25', $second['prepared_version'] );
        $this->assertSame( self::GW . '/api/v2/merchant/ucp/2026-08-25/payment-requirements', $GLOBALS['fd_test_requests'][1]['url'] );
    }

    public function test_third_party_handler_without_versioned_interface_is_still_listed(): void {
        $third_party = new class() implements FD_Payment_Handler {
            public function id(): string { return 'com.example.pay'; }
            public function name(): string { return 'Example'; }
            public function get_ucp_discovery_handlers(): array { return array( 'com.example.pay' => array( array( 'id' => 'com.example.pay', 'version' => '1' ) ) ); }
            public function prepare_checkout_payment( array $input ): ?array { return null; }
            public function settle_payment( array $input ): array { return array( 'success' => false ); }
            public function validate_instrument( array $instrument ): ?string { return null; }
            public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array { return array(); }
        };
        $registry = new FD_Payment_Registry();
        $registry->register( $third_party );
        $registry->register( $this->handler( self::contract() ) );

        $handlers = $registry->get_ucp_discovery_handlers( '2026-08-25' );

        $this->assertSame( array( 'com.example.pay', 'xyz.fd.prism_payment' ), array_keys( $handlers ) );
    }

    public function test_validate_instrument_accepts_x402(): void {
        $this->assertNull( $this->handler()->validate_instrument( self::instrument() ) );
    }

    public function test_validate_instrument_accepts_original_era_types(): void {
        foreach ( array( 'tokenized', 'default' ) as $type ) {
            $this->assertNull( $this->handler()->validate_instrument( self::instrument( array( 'type' => $type ) ) ), $type );
        }
        $instrument = self::instrument();
        unset( $instrument['type'] );
        $this->assertNull( $this->handler()->validate_instrument( $instrument ) );
    }

    public function test_validate_instrument_rejects_foreign_type(): void {
        $this->assertNotNull( $this->handler()->validate_instrument( self::instrument( array( 'type' => 'card' ) ) ) );
    }

    public function test_validate_instrument_accepts_credential_without_type(): void {
        $instrument = self::instrument( array( 'credential' => array( 'x402Version' => 2 ) ) );
        $this->assertNull( $this->handler()->validate_instrument( $instrument ) );
    }

    public function test_validate_instrument_rejects_foreign_credential_type(): void {
        $instrument = self::instrument( array( 'credential' => array( 'type' => 'card' ) ) );
        $this->assertNotNull( $this->handler()->validate_instrument( $instrument ) );
    }

    public function test_validate_instrument_accepts_base64_string_credential(): void {
        $credential = base64_encode( json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) ) );
        $this->assertNull( $this->handler()->validate_instrument( self::instrument( array( 'credential' => $credential ) ) ) );
    }

    public function test_validate_instrument_accepts_json_string_credential(): void {
        $credential = json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) );
        $this->assertNull( $this->handler()->validate_instrument( self::instrument( array( 'credential' => $credential ) ) ) );
    }

    public function test_settle_payment_still_rejects_string_credential_without_stored_requirements(): void {
        $credential = base64_encode( json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) ) );
        $result     = $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => $credential ) );
        $this->assertFalse( $result['success'] );
        $this->assertSame( array(), $GLOBALS['fd_test_requests'] );
    }

    public function test_string_credential_reaches_settle_with_the_same_payload_as_the_object(): void {
        $authorization = array(
            'x402Version'    => 2,
            'paymentPayload' => array(
                'x402Version' => 2,
                'network'     => 'eip155:84532',
                'accepted'    => array( 'network' => 'eip155:84532', 'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e' ),
                'payload'     => array( 'authorization' => array( 'to' => '0x1111111111111111111111111111111111111111', 'value' => '1000000' ) ),
            ),
        );
        $meta = array( 'xyz.fd.prism_payment' => array( 'ucp' => array( 'xyz.fd.prism_payment' => array( array( 'config' => array( 'accepts' => array( array(
            'network' => 'eip155:84532',
            'asset'   => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'payTo'   => '0x1111111111111111111111111111111111111111',
            'amount'  => '1000000',
        ) ) ) ) ) ) ) );

        $bodies = array();
        foreach ( array( $authorization, base64_encode( json_encode( $authorization ) ), json_encode( $authorization ) ) as $credential ) {
            $GLOBALS['fd_test_requests'] = array();
            $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => $credential, 'checkout_meta' => $meta ) );
            $this->assertCount( 1, $GLOBALS['fd_test_requests'] );
            $bodies[] = $GLOBALS['fd_test_requests'][0]['args']['body'];
        }

        $this->assertSame( $bodies[0], $bodies[1] );
        $this->assertSame( $bodies[0], $bodies[2] );
    }

    public function test_tampered_string_credential_is_rejected_before_settle(): void {
        $authorization = array(
            'paymentPayload' => array(
                'network' => 'eip155:84532',
                'payload' => array( 'authorization' => array( 'to' => '0x2222222222222222222222222222222222222222', 'value' => '1000000' ) ),
            ),
        );
        $meta = array( 'xyz.fd.prism_payment' => array( 'ucp' => array( 'xyz.fd.prism_payment' => array( array( 'config' => array( 'accepts' => array( array(
            'network' => 'eip155:84532',
            'payTo'   => '0x1111111111111111111111111111111111111111',
            'amount'  => '1000000',
        ) ) ) ) ) ) ) );

        $result = $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => base64_encode( json_encode( $authorization ) ), 'checkout_meta' => $meta ) );

        $this->assertFalse( $result['success'] );
        $this->assertSame( array(), $GLOBALS['fd_test_requests'] );
    }

    public function test_settled_order_meta_records_the_payer_from_the_settle_response(): void {
        $payer         = '0xb5004598bBf235A30494339500601D0cD8E5367A';
        $pay_to        = '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd';
        $authorization = array(
            'x402Version'    => 2,
            'paymentPayload' => array(
                'x402Version' => 2,
                'accepted'    => array( 'network' => 'eip155:84532', 'asset' => '0x036cbd53842c5426634e7929541ec2318f3dcf7e' ),
                'payload'     => array( 'authorization' => array( 'from' => $payer, 'to' => $pay_to, 'value' => '100010' ) ),
            ),
        );
        $meta = array( 'xyz.fd.prism_payment' => array( 'ucp' => array( 'xyz.fd.prism_payment' => array( array( 'config' => array( 'accepts' => array( array(
            'network' => 'eip155:84532',
            'asset'   => '0x036cbd53842c5426634e7929541ec2318f3dcf7e',
            'payTo'   => $pay_to,
            'amount'  => '100010',
        ) ) ) ) ) ) ) );
        $GLOBALS['fd_test_http_response'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => json_encode( array( 'success' => true, 'payer' => $payer, 'transaction' => '0xabc', 'network' => 'eip155:84532' ) ),
        );

        $result = $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => $authorization, 'checkout_meta' => $meta ) );

        $this->assertTrue( $result['success'] );
        $this->assertSame( $payer, $result['order_meta']['_fd_prism_payer'] );
        $this->assertSame( '0xabc', $result['order_meta']['_fd_prism_tx_hash'] );
        $this->assertArrayNotHasKey( '_fd_prism_payment_id', $result['order_meta'] );
    }

    public function test_settled_order_meta_falls_back_to_the_signed_payer_when_prism_sends_none(): void {
        $payer         = '0xb5004598bBf235A30494339500601D0cD8E5367A';
        $pay_to        = '0x40a01003f7543a3a3ee64ffb05504173bdb1c4fd';
        $authorization = array(
            'paymentPayload' => array(
                'accepted' => array( 'network' => 'eip155:84532' ),
                'payload'  => array( 'authorization' => array( 'from' => $payer, 'to' => $pay_to, 'value' => '100010' ) ),
            ),
        );
        $meta = array( 'xyz.fd.prism_payment' => array( 'ucp' => array( 'xyz.fd.prism_payment' => array( array( 'config' => array( 'accepts' => array( array(
            'network' => 'eip155:84532',
            'payTo'   => $pay_to,
            'amount'  => '100010',
        ) ) ) ) ) ) ) );
        $GLOBALS['fd_test_http_response'] = array(
            'response' => array( 'code' => 200 ),
            'body'     => json_encode( array( 'success' => true, 'payer' => '', 'transaction' => '0xabc' ) ),
        );

        $result = $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => $authorization, 'checkout_meta' => $meta ) );

        $this->assertSame( $payer, $result['order_meta']['_fd_prism_payer'] );
    }
}
