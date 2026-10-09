<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class DiscountSignTest extends TestCase {

    private static function amounts( array $totals ): array {
        return array_column( $totals, 'amount', 'type' );
    }

    public function test_discount_line_is_negative_and_the_total_identity_holds(): void {
        $totals = FD_UCP_Checkout_Pricing::priced_totals( 4200, 495, 892, -420 );

        $amounts = self::amounts( $totals );
        $this->assertSame( -420, $amounts['discount'] );
        $this->assertSame( 5167, $amounts['total'] );
        $this->assertSame( $amounts['subtotal'] + $amounts['fulfillment'] + $amounts['tax'] + $amounts['discount'], $amounts['total'] );
    }

    public function test_total_is_unchanged_by_the_sign_convention(): void {
        $this->assertSame( 4275, FD_UCP_Checkout_Pricing::total_of( FD_UCP_Checkout_Pricing::priced_totals( 4200, 495, 0, -420 ) ) );
    }

    public function test_no_discount_line_without_a_discount(): void {
        $this->assertArrayNotHasKey( 'discount', self::amounts( FD_UCP_Checkout_Pricing::priced_totals( 4200, 495 ) ) );
    }

    public function test_positive_discount_is_refused(): void {
        $this->assertInstanceOf( WP_Error::class, FD_UCP_Checkout_Pricing::priced_totals( 4200, 495, 0, 420 ) );
    }

    public function test_discount_beyond_the_subtotal_is_refused(): void {
        $this->assertInstanceOf( WP_Error::class, FD_UCP_Checkout_Pricing::priced_totals( 4200, 495, 0, -4201 ) );
    }

    public function test_order_totals_report_the_coupon_as_a_negative_line(): void {
        $order = new WC_Order();
        $order->subtotal = 42.0;
        $order->discount = 4.2;
        $order->total    = '37.80';

        $totals = FD_UCP_Checkout_Pricing::order_totals( $order );

        $this->assertIsArray( $totals );
        $this->assertSame( -420, self::amounts( $totals )['discount'] );
    }
}
