<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class CheckoutTamperTest extends TestCase {

    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SUBTOTAL   = 4200;

    private FD_Test_Wpdb $db;
    private object $handler;

    protected function setUp(): void {
        FD_Test_WP::reset();

        $session                      = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['agent_fingerprint'] = hash( 'sha256', '' );
        $session['ucp_version']       = null;
        $session['expires_at']        = gmdate( 'Y-m-d H:i:s', time() + 3600 );

        $this->db                               = new FD_Test_Wpdb();
        $this->db->sessions[ self::SESSION_ID ] = $session;
        $GLOBALS['wpdb']                        = $this->db;

        FD_Test_Order_Store::$orders     = array( 1001 => new WC_Order() );
        FD_Test_Product_Store::$products = array(
            101 => new FD_Test_Product( 101, '18.00' ),
            205 => new FD_Test_Product( 205, '6.00' ),
        );
        FD_Test_WC::$rates = array( new FD_Test_Shipping_Rate( 'flat_rate1', '4.95', 'Flat rate' ) );

        $this->handler = new class() extends FD_Prism_Handler {
            public array $prepared = array();

            public function __construct() {
                parent::__construct( 'https://gw.example', 'test-key' );
            }

            public function prepare_checkout_payment( array $input ): ?array {
                $this->prepared[] = $input['total'];
                return array( 'prepared_amount' => $input['total'] );
            }
        };
    }

    protected function tearDown(): void {
        FD_Test_Product_Store::$products = array();
        FD_Test_WC::$rates               = array();
    }

    private function controller(): FD_UCP_Checkout_Controller {
        $registry = new FD_Payment_Registry();
        $registry->register( $this->handler );
        return new FD_UCP_Checkout_Controller( $registry );
    }

    private function update( array $body ): WP_REST_Response {
        return $this->controller()->update_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID,
            array(),
            array( 'id' => self::SESSION_ID ),
            $body
        ) );
    }

    private static function shipping( ?array $destination, array $options, string $selected ): array {
        $method = array(
            'type'   => 'shipping',
            'groups' => array( array( 'selected_option_id' => $selected, 'options' => $options ) ),
        );
        if ( null !== $destination ) {
            $method['destinations'] = array( $destination );
        }
        return array( 'methods' => array( $method ) );
    }

    private static function option( string $id, int $amount ): array {
        return array( 'id' => $id, 'title' => 'Shipping', 'totals' => array( array( 'type' => 'total', 'amount' => $amount ) ) );
    }

    private static function berlin(): array {
        return array( 'street_address' => 'Hauptstrasse 12', 'address_locality' => 'Berlin', 'postal_code' => '10115', 'address_country' => 'DE' );
    }

    private function stored_total(): int {
        foreach ( json_decode( $this->db->sessions[ self::SESSION_ID ]['totals'], true ) as $t ) {
            if ( 'total' === $t['type'] ) {
                return $t['amount'];
            }
        }
        return 0;
    }

    private function assert_rejected( WP_REST_Response $response ): void {
        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_fulfillment', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
    }

    public function test_negative_shipping_without_destination_is_rejected(): void {
        $this->assert_rejected( $this->update( array(
            'fulfillment' => self::shipping( null, array( self::option( 'cheap', -( self::SUBTOTAL - 1 ) ) ), 'cheap' ),
        ) ) );
    }

    public function test_negative_shipping_without_country_is_rejected(): void {
        $destination = self::berlin();
        unset( $destination['address_country'] );

        $this->assert_rejected( $this->update( array(
            'fulfillment' => self::shipping( $destination, array( self::option( 'cheap', -( self::SUBTOTAL - 1 ) ) ), 'cheap' ),
        ) ) );
    }

    public function test_fulfillment_without_methods_is_rejected(): void {
        $this->assert_rejected( $this->update( array(
            'fulfillment' => array( 'methods' => array(), 'groups' => array( self::option( 'cheap', -( self::SUBTOTAL - 1 ) ) ) ),
        ) ) );
    }

    public function test_client_written_option_is_replaced_by_server_rates(): void {
        $response = $this->update( array(
            'fulfillment' => self::shipping( self::berlin(), array( self::option( 'cheap', -( self::SUBTOTAL - 1 ) ) ), 'cheap' ),
        ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array( 4695 ), $this->handler->prepared );
        $stored = json_decode( $this->db->sessions[ self::SESSION_ID ]['fulfillment'], true );
        $this->assertSame( array( 'flat_rate1' ), array_column( $stored['methods'][0]['groups'][0]['options'], 'id' ) );
    }

    public function test_client_price_for_a_real_rate_id_is_ignored(): void {
        $response = $this->update( array(
            'fulfillment' => self::shipping( self::berlin(), array( self::option( 'flat_rate1', -( self::SUBTOTAL - 1 ) ) ), 'flat_rate1' ),
        ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array( 4695 ), $this->handler->prepared );
    }

    public function test_negative_server_rate_is_rejected(): void {
        FD_Test_WC::$rates = array( new FD_Test_Shipping_Rate( 'promo', '-41.99', 'Promo' ) );

        $this->assert_rejected( $this->update( array(
            'fulfillment' => self::shipping( self::berlin(), array(), 'promo' ),
        ) ) );
    }

    public function test_stored_negative_shipping_is_rejected_on_any_update(): void {
        $tampered = self::shipping( self::berlin(), array( self::option( 'cheap', -( self::SUBTOTAL - 1 ) ) ), 'cheap' );
        $this->db->sessions[ self::SESSION_ID ]['fulfillment'] = json_encode( $tampered );

        $this->assert_rejected( $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) ) );
    }

    public function test_create_rejects_negative_shipping_without_destination(): void {
        $response = $this->controller()->create_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions',
            array(),
            array(),
            array(
                'line_items'  => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ),
                'fulfillment' => self::shipping( null, array( self::option( 'cheap', -3599 ) ), 'cheap' ),
            )
        ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_fulfillment', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array( self::SESSION_ID ), array_keys( $this->db->sessions ) );
        $this->assertSame( array(), $this->handler->prepared );
    }
}
