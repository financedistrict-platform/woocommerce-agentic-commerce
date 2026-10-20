<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoldResponseShapeTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
        $order            = new WC_Order();
        $order->order_key = 'wc_order_held';
        FD_Test_Order_Store::$orders = array( 1001 => $order );
    }

    public static function wire_versions(): array {
        return array_map( static fn( string $version ) => array( $version ), FD_UCP_Version_Registry::known() );
    }

    private static function held_session(): array {
        $session           = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['status'] = 'requires_escalation';
        return $session;
    }

    #[DataProvider( 'wire_versions' )]
    public function test_held_session_is_a_checkout_that_requires_escalation( string $version ): void {
        $response = ( new FD_UCP_Version_Registry() )->wire( $version )->checkout_session( self::held_session(), new FD_Payment_Registry() );

        $this->assertSame( 'requires_escalation', $response['status'] );
        $this->assertStringStartsWith( 'https://', $response['continue_url'] );
        $this->assertStringContainsString( 'wc_order_held', $response['continue_url'] );
        $this->assertArrayHasKey( 'totals', $response );
        $this->assertArrayHasKey( 'line_items', $response );

        $hold = array_values( array_filter( $response['messages'], static fn( $m ) => 'payment_on_hold' === $m['code'] ) );
        $this->assertCount( 1, $hold );
        $this->assertSame( 'error', $hold[0]['type'] );
        $this->assertSame( 'requires_buyer_review', $hold[0]['severity'] );
        $this->assertSame( 'Payment received but held for merchant review', $hold[0]['content'] );
    }

    #[DataProvider( 'wire_versions' )]
    public function test_open_session_carries_no_hold_message_or_continue_url( string $version ): void {
        $session           = self::held_session();
        $session['status'] = 'incomplete';

        $response = ( new FD_UCP_Version_Registry() )->wire( $version )->checkout_session( $session, new FD_Payment_Registry() );

        $this->assertArrayNotHasKey( 'continue_url', $response );
        $this->assertSame( array(), array_filter( $response['messages'], static fn( $m ) => 'payment_on_hold' === $m['code'] ) );
    }
}
