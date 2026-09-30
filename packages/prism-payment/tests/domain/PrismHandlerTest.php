<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PrismHandlerTest extends TestCase {

    private const GW = 'https://gateway.test';

    protected function setUp(): void {
        $GLOBALS['fd_test_transients'] = array();
        $GLOBALS['fd_test_options']    = array();
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

    private function handler( ?array $fetched = null ): FD_Prism_Handler {
        $handler = new FD_Prism_Handler( self::GW, 'key' );
        $client  = new class( $fetched ) extends FD_Prism_Client {
            private ?array $fetched;

            public function __construct( ?array $fetched ) {
                parent::__construct( 'https://gateway.test', 'key' );
                $this->fetched = $fetched;
            }

            public function fetch_ucp_handlers(): ?array {
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
        $this->assertTrue( $this->call( $this->handler(), 'contract_entry_ok', self::contract() ) );
    }

    public function test_guard_rejects_missing_schema(): void {
        $handlers = self::contract();
        unset( $handlers['xyz.fd.prism_payment'][0]['schema'] );
        $this->assertFalse( $this->call( $this->handler(), 'contract_entry_ok', $handlers ) );
    }

    public function test_guard_rejects_legacy_id(): void {
        $handlers = self::contract();
        $handlers['xyz.fd.prism_payment'][0]['id'] = 'x402';
        $this->assertFalse( $this->call( $this->handler(), 'contract_entry_ok', $handlers ) );
    }

    public function test_as_wire_encodes_empty_config_as_object(): void {
        $wire = $this->call( $this->handler(), 'as_wire', self::contract() );
        $this->assertStringContainsString( '"config":{}', json_encode( $wire ) );
    }

    public function test_discovery_passes_contract_entry_through_and_caches_it(): void {
        $wire = $this->handler( self::contract() )->get_ucp_discovery_handlers();

        $this->assertSame(
            array( 'id', 'version', 'spec', 'schema', 'available_instruments', 'config' ),
            array_keys( $wire['xyz.fd.prism_payment'][0] )
        );
        $this->assertSame( self::contract(), $GLOBALS['fd_test_transients']['fd_prism_discovery_cache'] );
        $this->assertSame( self::contract(), $GLOBALS['fd_test_options']['_fd_prism_discovery_stale'] );
    }

    public function test_discovery_rejects_malformed_cache_fetch_and_stale(): void {
        $bad = self::contract();
        unset( $bad['xyz.fd.prism_payment'][0]['schema'] );
        $GLOBALS['fd_test_transients']['fd_prism_discovery_cache'] = $bad;
        $GLOBALS['fd_test_options']['_fd_prism_discovery_stale']   = $bad;

        $this->assertSame( array(), $this->handler( $bad )->get_ucp_discovery_handlers() );
        $this->assertSame( $bad, $GLOBALS['fd_test_options']['_fd_prism_discovery_stale'] );
    }

    public function test_discovery_falls_back_to_valid_stale_entry(): void {
        $GLOBALS['fd_test_options']['_fd_prism_discovery_stale'] = self::contract();

        $wire = $this->handler( null )->get_ucp_discovery_handlers();

        $this->assertSame( 'xyz.fd.prism_payment', $wire['xyz.fd.prism_payment'][0]['id'] );
    }

    public function test_validate_instrument_accepts_x402(): void {
        $this->assertNull( $this->handler()->validate_instrument( self::instrument() ) );
    }

    public function test_validate_instrument_rejects_tokenized_type(): void {
        $this->assertNotNull( $this->handler()->validate_instrument( self::instrument( array( 'type' => 'tokenized' ) ) ) );
    }

    public function test_validate_instrument_rejects_credential_without_type(): void {
        $instrument = self::instrument( array( 'credential' => array( 'x402Version' => 2 ) ) );
        $this->assertNotNull( $this->handler()->validate_instrument( $instrument ) );
    }

    public function test_validate_instrument_rejects_base64_string_credential(): void {
        $credential = base64_encode( json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) ) );
        $this->assertNotNull( $this->handler()->validate_instrument( self::instrument( array( 'credential' => $credential ) ) ) );
    }

    public function test_validate_instrument_rejects_json_string_credential(): void {
        $credential = json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) );
        $this->assertNotNull( $this->handler()->validate_instrument( self::instrument( array( 'credential' => $credential ) ) ) );
    }

    public function test_settle_payment_rejects_string_credential(): void {
        $credential = base64_encode( json_encode( array( 'type' => 'x402', 'x402Version' => 2 ) ) );
        $result     = $this->handler()->settle_payment( array( 'checkout_id' => 'c1', 'credential' => $credential ) );
        $this->assertFalse( $result['success'] );
    }
}
