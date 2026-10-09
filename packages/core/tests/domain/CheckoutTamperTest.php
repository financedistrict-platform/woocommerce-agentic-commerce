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
        FD_Test_Order_Store::$created    = 0;
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

    private function create( array $body ): WP_REST_Response {
        return $this->controller()->create_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions',
            array(),
            array(),
            $body
        ) );
    }

    private function stored_totals( string $id = self::SESSION_ID ): array {
        return array_column( json_decode( $this->db->sessions[ $id ]['totals'], true ), 'amount', 'type' );
    }

    private function stored_group(): array {
        return json_decode( $this->db->sessions[ self::SESSION_ID ]['fulfillment'], true )['methods'][0]['groups'][0];
    }

    private function call_index( string $needle ): int {
        foreach ( $this->db->calls as $i => $call ) {
            if ( false !== strpos( $call, $needle ) ) {
                return $i;
            }
        }
        return -1;
    }

    private static function lock_name(): string {
        return 'fd_ucp_session_' . md5( self::SESSION_ID );
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
        $response = $this->create( array(
            'line_items'  => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ),
            'fulfillment' => self::shipping( null, array( self::option( 'cheap', -3599 ) ), 'cheap' ),
        ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_fulfillment', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array( self::SESSION_ID ), array_keys( $this->db->sessions ) );
        $this->assertSame( array(), $this->handler->prepared );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_unserved_destination_for_shippable_cart_is_rejected(): void {
        FD_Test_WC::$rates = array();

        $this->assert_rejected( $this->update( array(
            'fulfillment' => self::shipping( self::berlin(), array( self::option( 'free_shipping', 0 ) ), 'free_shipping' ),
        ) ) );
    }

    public function test_create_rejects_unserved_destination_for_shippable_cart(): void {
        FD_Test_WC::$rates = array();

        $response = $this->create( array(
            'line_items'  => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ),
            'fulfillment' => self::shipping( array( 'address_country' => 'ZZ' ), array(), 'free_shipping' ),
        ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_fulfillment', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->prepared );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_digital_cart_without_rates_prices_subtotal_only(): void {
        FD_Test_Product_Store::$products = array(
            101 => new FD_Test_Product( 101, '18.00', 'Ebook', false ),
            205 => new FD_Test_Product( 205, '6.00', 'Audiobook', false ),
        );
        FD_Test_WC::$rates = array();

        $response = $this->update( array( 'fulfillment' => self::shipping( self::berlin(), array(), 'free_shipping' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 4200, 'total' => 4200 ), $this->stored_totals() );
        $this->assertSame( array( 4200 ), $this->handler->prepared );
    }

    public function test_line_item_change_reprices_stored_fulfillment(): void {
        FD_Test_WC::$rates = array( new FD_Test_Shipping_Rate( 'express', '9.95', 'Express' ) );

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'express' ), array_column( $this->stored_group()['options'], 'id' ) );
        $this->assertSame( array( 'subtotal' => 1800, 'fulfillment' => 995, 'total' => 2795 ), $this->stored_totals() );
        $this->assertSame( array( 2795 ), $this->handler->prepared );
    }

    public function test_line_item_change_rejects_when_destination_is_no_longer_served(): void {
        FD_Test_WC::$rates = array();

        $this->assert_rejected( $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 5 ) ) ) ) );
    }

    public function test_update_writes_inside_the_session_lock(): void {
        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $lock = $this->call_index( "GET_LOCK('" . self::lock_name() . "'" );
        $this->assertGreaterThanOrEqual( 0, $lock );
        $this->assertLessThan( $this->call_index( 'update' ), $lock );
        $this->assertGreaterThan( $this->call_index( 'update' ), $this->call_index( "RELEASE_LOCK('" . self::lock_name() . "'" ) );
    }

    public function test_update_is_refused_while_the_session_is_locked(): void {
        $this->db->held = array( self::lock_name() );

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 9 ) ) ) );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( -1, $this->call_index( 'update' ) );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
    }

    public function test_complete_takes_the_same_session_lock_as_update(): void {
        $this->db->held = array( self::lock_name() );

        $response = $this->controller()->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            array(),
            array( 'id' => self::SESSION_ID ),
            array()
        ) );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'settlement_in_progress', $response->get_data()['messages'][0]['code'] );
        $this->assertGreaterThanOrEqual( 0, $this->call_index( "GET_LOCK('" . self::lock_name() . "'" ) );
    }

    public function test_zero_value_cart_is_rejected_as_invalid_total(): void {
        FD_Test_Product_Store::$products[300] = new FD_Test_Product( 300, '0.00', 'Sample', false );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '300' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_total', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_create_without_fulfillment_prices_subtotal_only(): void {
        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ) ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 3600, 'total' => 3600 ), $this->stored_totals( wp_generate_uuid4() ) );
        $this->assertSame( array( 3600 ), $this->handler->prepared );
        $this->assertSame( 1, FD_Test_Order_Store::$created );
    }

    public function test_create_with_served_destination_adds_the_server_rate(): void {
        $response = $this->create( array(
            'line_items'  => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ),
            'fulfillment' => self::shipping( self::berlin(), array( self::option( 'flat_rate1', 1 ) ), 'flat_rate1' ),
        ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 3600, 'fulfillment' => 495, 'total' => 4095 ), $this->stored_totals( wp_generate_uuid4() ) );
        $this->assertSame( array( 4095 ), $this->handler->prepared );
    }

    public function test_update_without_fulfillment_prices_subtotal_only(): void {
        $this->db->sessions[ self::SESSION_ID ]['fulfillment'] = null;

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '205' ), 'quantity' => 2 ) ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 1200, 'total' => 1200 ), $this->stored_totals() );
        $this->assertSame( array( 1200 ), $this->handler->prepared );
    }

    public function test_real_free_rate_prices_subtotal_only(): void {
        FD_Test_WC::$rates = array( new FD_Test_Shipping_Rate( 'free', '0.00', 'Free shipping' ) );

        $response = $this->update( array( 'fulfillment' => self::shipping( self::berlin(), array(), 'free' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 4200, 'total' => 4200 ), $this->stored_totals() );
        $this->assertSame( array( 4200 ), $this->handler->prepared );
    }

    public function test_unknown_selected_option_falls_back_to_first_server_rate(): void {
        FD_Test_WC::$rates = array(
            new FD_Test_Shipping_Rate( 'flat_rate1', '4.95', 'Flat rate' ),
            new FD_Test_Shipping_Rate( 'express', '9.95', 'Express' ),
        );

        $response = $this->update( array( 'fulfillment' => self::shipping( self::berlin(), array( self::option( 'bogus', 0 ) ), 'bogus' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'flat_rate1', $this->stored_group()['selected_option_id'] );
        $this->assertSame( 4695, $this->stored_total() );
    }
}
