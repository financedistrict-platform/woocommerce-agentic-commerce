<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoldResponseShapeTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function wire_versions(): array {
        return array_map( static fn( string $version ) => array( $version ), FD_UCP_Version_Registry::known() );
    }

    #[DataProvider( 'wire_versions' )]
    public function test_hold_response_is_a_checkout_that_requires_escalation( string $version ): void {
        $order = new WC_Order();
        $order->order_key = 'wc_order_held';

        $response = ( new FD_UCP_Version_Registry() )->wire( $version )->hold_response(
            FD_Test_Golden_Renderer::input( 'checkout-session.json' ),
            $order,
            new FD_Payment_Registry()
        );

        $this->assertSame( 'requires_escalation', $response['status'] );
        $this->assertStringStartsWith( 'https://', $response['continue_url'] );
        $this->assertStringContainsString( 'wc_order_held', $response['continue_url'] );
        $this->assertArrayHasKey( 'totals', $response );
        $this->assertArrayHasKey( 'line_items', $response );

        $hold = array_values( array_filter( $response['messages'], static fn( $m ) => 'payment_on_hold' === $m['code'] ) );
        $this->assertCount( 1, $hold );
        $this->assertSame( 'info', $hold[0]['type'] );
        $this->assertSame( 'requires_buyer_review', $hold[0]['severity'] );
        $this->assertSame( 'Payment received but held for merchant review', $hold[0]['content'] );
    }

    public function test_hold_response_ignores_the_stored_status(): void {
        $session           = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['status'] = 'complete_in_progress';

        $response = ( new FD_UCP_Version_Registry() )->wire( '2026-04-08' )->hold_response( $session, new WC_Order(), new FD_Payment_Registry() );

        $this->assertSame( 'requires_escalation', $response['status'] );
    }
}
