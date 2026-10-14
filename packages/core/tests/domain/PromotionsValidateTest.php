<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class PromotionsValidateTest extends TestCase {

    private const PLATFORM = 'https://platform.example/.well-known/ucp';
    private const NOTE     = 'staff only: supplier margin 40%';

    protected function setUp(): void {
        FD_Test_WP::reset();
        FD_Test_Coupon_Store::reset();
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::current()->with_platform( self::PLATFORM ) );
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10.0, array( 'description' => self::NOTE ) );
    }

    private function validate( string $code ): WP_REST_Response {
        $controller = new FD_UCP_Promotions_Controller( new FD_UCP_Checkout_Controller( new FD_Payment_Registry() ) );
        return $controller->validate( new WP_REST_Request( '/fd-ucp/v1/promotions/validate', array(), array(), array( 'code' => $code ) ) );
    }

    public function test_validate_reports_the_discount_without_the_internal_coupon_note(): void {
        $response = $this->validate( 'SAVE10' );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'SAVE10', $data['code'] );
        $this->assertSame( 'percent', $data['discount_type'] );
        $this->assertSame( '10', $data['amount'] );
        $this->assertTrue( $data['valid'] );
        $this->assertArrayNotHasKey( 'description', $data );
        $this->assertStringNotContainsString( self::NOTE, json_encode( $data ) );
    }

    public function test_validate_still_answers_for_an_unknown_code(): void {
        $this->assertSame( 404, $this->validate( 'NOPE' )->get_status() );
    }
}
