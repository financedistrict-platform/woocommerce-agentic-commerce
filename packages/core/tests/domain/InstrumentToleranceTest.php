<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class InstrumentToleranceTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    private function registry(): FD_Payment_Registry {
        $registry = new FD_Payment_Registry();
        $registry->register( new FD_Prism_Handler( 'https://gw.example', 'test-key' ) );
        return $registry;
    }

    public function test_guard_requires_only_handler_id_and_credential(): void {
        $this->assertNull( FD_UCP_Checkout_Controller::instrument_error( array( 'handler_id' => 'x402', 'credential' => array( 'a' => 1 ) ) ) );
        $this->assertNull( FD_UCP_Checkout_Controller::instrument_error( array( 'handler_id' => 'x402', 'credential' => 'eyJhIjoxfQ==' ) ) );
        $this->assertNotNull( FD_UCP_Checkout_Controller::instrument_error( array( 'credential' => array( 'a' => 1 ) ) ) );
        $this->assertNotNull( FD_UCP_Checkout_Controller::instrument_error( array( 'handler_id' => 'x402' ) ) );
        $this->assertNotNull( FD_UCP_Checkout_Controller::instrument_error( array( 'handler_id' => array( 'x402' ), 'credential' => 'a' ) ) );
        $this->assertNotNull( FD_UCP_Checkout_Controller::instrument_error( array( 'handler_id' => 'x402', 'credential' => array( 1, 2 ) ) ) );
    }

    public function test_legacy_alias_resolves_to_the_prism_handler(): void {
        $registry = $this->registry();

        $this->assertSame( 'xyz.fd.prism_payment', $registry->canonical_id( 'x402' ) );
        $this->assertSame( 'xyz.fd.prism_payment', $registry->get( 'x402' )->id() );
        $this->assertSame( 'other', $registry->canonical_id( 'other' ) );
        $this->assertNull( $registry->get( 'other' ) );
    }

    public function test_alias_is_not_applied_without_the_prism_handler(): void {
        $registry = new FD_Payment_Registry();

        $this->assertSame( 'x402', $registry->canonical_id( 'x402' ) );
        $this->assertNull( $registry->get( 'x402' ) );
    }

    public function test_unknown_handler_stays_loud(): void {
        $this->assertSame( 'Unknown payment handler: other', $this->registry()->validate_instrument( 'other', array() ) );
        $this->assertFalse( $this->registry()->settle( 'other', array() )['success'] );
    }

    public function test_no_public_alias_interface_exists(): void {
        $this->assertSame( array(), glob( dirname( __DIR__, 2 ) . '/includes/payment/*alias*' ) );
    }
}
