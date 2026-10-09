<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CheckoutTamperTest extends TestCase {

    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SUBTOTAL   = 4200;
    private const PLATFORM = 'https://platform.example/.well-known/ucp';
    private const OTHER_PLATFORM = 'https://other.example/.well-known/ucp';

    private FD_Test_Wpdb $db;
    private object $handler;

    protected function setUp(): void {
        FD_Test_WP::reset();

        $session                       = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['platform_id'] = self::PLATFORM;
        $session['ucp_version'] = null;
        $session['expires_at']  = gmdate( 'Y-m-d H:i:s', time() + 3600 );

        $this->db                               = new FD_Test_Wpdb();
        $this->db->sessions[ self::SESSION_ID ] = $session;
        $GLOBALS['wpdb']                        = $this->db;

        self::as_platform( self::PLATFORM );

        FD_Test_Order_Store::$orders     = array( 1001 => self::owned_order( self::PLATFORM, '0xmine' ) );
        FD_Test_Order_Store::$created    = 0;
        FD_Test_Order_Store::$refunds    = array();
        FD_Test_Product_Store::$products = array(
            101 => new FD_Test_Product( 101, '18.00' ),
            205 => new FD_Test_Product( 205, '6.00' ),
        );
        FD_Test_WC::$rates = array( new FD_Test_Shipping_Rate( 'flat_rate1', '4.95', 'Flat rate' ) );

        $this->handler = new class() extends FD_Prism_Handler {
            public array $prepared = array();
            public array $settled  = array();
            public array $result   = array();

            public function __construct() {
                parent::__construct( 'https://gw.example', 'test-key' );
            }

            public function prepare_checkout_payment( array $input ): ?array {
                $this->prepared[] = $input['total'];
                return array( 'prepared_amount' => $input['total'] );
            }

            public function validate_instrument( array $instrument ): ?string {
                return null;
            }

            public function settle_payment( array $input ): array {
                $this->settled[] = $input['checkout_meta'][ $this->id() ]['prepared_amount'] ?? null;
                return $this->result;
            }
        };
    }

    protected function tearDown(): void {
        FD_Test_Product_Store::$products = array();
        FD_Test_Coupon_Store::reset();
        FD_Test_WC::$rates               = array();
        FD_Test_WC::$tax_rate            = 0.0;
        FD_Test_WC::$included_rate       = 0.0;
    }

    private function controller(): FD_UCP_Checkout_Controller {
        $registry = new FD_Payment_Registry();
        $registry->register( $this->handler );
        return new FD_UCP_Checkout_Controller( $registry );
    }

    private static function owned_order( string $platform, string $tx_reference ): WC_Order {
        $order       = new WC_Order();
        $order->meta = array(
            '_fd_ucp_tx_reference' => $tx_reference,
            '_fd_ucp_handler_id'   => 'xyz.fd.prism_payment',
            '_fd_ucp_platform_id'  => $platform,
        );
        return $order;
    }

    private static function as_platform( ?string $platform ): void {
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::current()->with_platform( $platform ?? '' ) );
    }

    private static function request( string $route, ?string $platform, array $params = array(), array $body = array() ): WP_REST_Request {
        self::as_platform( $platform );
        return new WP_REST_Request( $route, array(), $params, $body );
    }

    private function update( array $body, ?string $platform = self::PLATFORM ): WP_REST_Response {
        self::as_platform( $platform );
        return $this->controller()->update_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID,
            array(),
            array( 'id' => self::SESSION_ID ),
            $body
        ) );
    }

    private function create( array $body, array $headers = array() ): WP_REST_Response {
        return $this->controller()->create_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions',
            $headers,
            array(),
            $body
        ) );
    }

    private function complete( ?string $platform = self::PLATFORM ): WP_REST_Response {
        self::as_platform( $platform );
        return $this->controller()->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            array(),
            array( 'id' => self::SESSION_ID ),
            array( 'payment' => array( 'instruments' => array( array(
                'handler_id' => $this->handler->id(),
                'credential' => array( 'payload' => 'signed' ),
            ) ) ) )
        ) );
    }

    private function quoted_session( int $settled, ?int $prepared, array $totals, ?int $prepared_at = null, bool $stamped = true ): void {
        $entry = null === $prepared ? array() : array( 'prepared_amount' => $prepared );
        if ( $stamped ) {
            $entry['prepared_at'] = $prepared_at ?? time();
        }
        $this->db->sessions[ self::SESSION_ID ]['totals']       = json_encode( $totals );
        $this->db->sessions[ self::SESSION_ID ]['payment_meta'] = json_encode( array( $this->handler->id() => $entry ) );
        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => $settled );
    }

    private static function untaxed_totals(): array {
        return json_decode( FD_Test_Golden_Renderer::input( 'checkout-session.json' )['totals'], true );
    }

    private static function taxed_totals(): array {
        return array(
            array( 'type' => 'subtotal', 'amount' => 4200 ),
            array( 'type' => 'fulfillment', 'amount' => 495 ),
            array( 'type' => 'tax', 'amount' => 892 ),
            array( 'type' => 'total', 'amount' => 5587 ),
        );
    }

    private function order(): WC_Order {
        return FD_Test_Order_Store::$orders[1001];
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

    public function test_update_quotes_tax_on_a_tax_exclusive_store(): void {
        FD_Test_WC::$tax_rate = 0.19;

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 4200, 'fulfillment' => 495, 'tax' => 892, 'total' => 5587 ), $this->stored_totals() );
        $this->assertSame( array( 5587 ), $this->handler->prepared );
        $this->assertSame( '55.87', $this->order()->get_total() );
    }

    public function test_create_quotes_tax_on_a_tax_exclusive_store(): void {
        FD_Test_WC::$tax_rate = 0.19;

        $response = $this->create( array(
            'line_items'  => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 2 ) ),
            'fulfillment' => self::shipping( self::berlin(), array(), 'flat_rate1' ),
        ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 3600, 'fulfillment' => 495, 'tax' => 778, 'total' => 4873 ), $this->stored_totals( wp_generate_uuid4() ) );
        $this->assertSame( array( 4873 ), $this->handler->prepared );
    }

    public function test_update_reprices_stored_items_at_the_current_price(): void {
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 5600, 'fulfillment' => 495, 'total' => 6095 ), $this->stored_totals() );
        $this->assertSame( array( 6095 ), $this->handler->prepared );
    }

    public function test_complete_refuses_to_settle_a_quote_without_tax(): void {
        FD_Test_WC::$tax_rate = 0.19;
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_refuses_to_settle_when_product_price_changed_after_quote(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_complete_refuses_to_settle_without_a_prepared_amount(): void {
        $this->quoted_session( 4695, null, self::untaxed_totals() );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_complete_refuses_to_settle_when_prepared_amount_differs_from_quote(): void {
        FD_Test_WC::$tax_rate = 0.19;
        $this->quoted_session( 5587, 4695, self::taxed_totals() );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_complete_marks_paid_when_quote_order_and_settlement_agree(): void {
        FD_Test_WC::$tax_rate = 0.19;
        $this->quoted_session( 5587, 5587, self::taxed_totals() );

        $response = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 5587 ), $this->handler->settled );
        $this->assertContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'completed', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_holds_the_order_when_settled_amount_is_short(): void {
        FD_Test_WC::$tax_rate = 0.19;
        $this->quoted_session( 4695, 5587, self::taxed_totals() );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_on_hold', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'on-hold', $this->order()->get_status() );
        $this->assertSame( 'requires_escalation', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_holds_the_order_when_settled_amount_is_missing(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        unset( $this->handler->result['settled_amount'] );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_on_hold', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'on-hold', $this->order()->get_status() );
    }

    public function test_complete_holds_the_order_when_the_handler_reports_an_inconsistent_settlement(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->handler->result['hold_reason'] = 'Settlement network differs from the quote';

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_on_hold', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'on-hold', $this->order()->get_status() );
        $this->assertSame( 'requires_escalation', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_claims_the_settled_transaction_before_marking_paid(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );

        $response = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame(
            self::SESSION_ID,
            $this->db->claims[ FD_Payment_Claims::key( FD_Payment_Claims::KIND_TRANSACTION, '0xfeed' ) ] ?? null
        );
    }

    public function test_complete_holds_the_order_when_the_transaction_already_paid_another_session(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->db->claims[ FD_Payment_Claims::key( FD_Payment_Claims::KIND_TRANSACTION, '0xFEED' ) ] = 'another-session';

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_on_hold', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'on-hold', $this->order()->get_status() );
        $this->assertSame( 'requires_escalation', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_accepts_a_transaction_already_claimed_by_the_same_session(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->db->claims[ FD_Payment_Claims::key( FD_Payment_Claims::KIND_TRANSACTION, '0xfeed' ) ] = self::SESSION_ID;

        $response = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertContains( 'payment_complete', $this->order()->calls );
    }

    public function test_complete_holds_the_order_when_the_settlement_has_no_transaction_reference(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        unset( $this->handler->result['transaction_reference'] );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_on_hold', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'on-hold', $this->order()->get_status() );
    }

    public function test_held_session_cannot_be_completed_again(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->db->sessions[ self::SESSION_ID ]['status'] = 'requires_escalation';

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_tax_inclusive_store_quotes_the_order_total_for_a_tax_free_destination(): void {
        FD_Test_WC::$included_rate = 0.19;

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 3529, 'fulfillment' => 495, 'total' => 4024 ), $this->stored_totals() );
        $this->assertSame( array( 4024 ), $this->handler->prepared );
    }

    public function test_tax_inclusive_store_quotes_shipping_tax(): void {
        FD_Test_WC::$included_rate = 0.19;
        FD_Test_WC::$tax_rate      = 0.19;

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 3529, 'fulfillment' => 495, 'tax' => 765, 'total' => 4789 ), $this->stored_totals() );
        $this->assertSame( array( 4789 ), $this->handler->prepared );
    }

    public function test_complete_refuses_to_settle_an_order_that_is_already_paid(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->order()->status = 'processing';

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'order_not_payable', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
    }

    public function test_update_quotes_on_a_fresh_order_when_the_stored_order_is_already_paid(): void {
        $this->order()->status = 'processing';

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 1, FD_Test_Order_Store::$created );
        $this->assertSame( array(), $this->order()->items );
    }

    public function test_create_binds_the_session_to_the_calling_platform(): void {
        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) ) );

        $row = $this->db->sessions[ wp_generate_uuid4() ];
        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( self::PLATFORM, $row['platform_id'] );
        $this->assertSame( array(), $response->get_headers() );
        $this->assertNull( $row['idempotency_key'] );
        $this->assertNull( $row['idempotency_hash'] );
    }

    public static function foreign_platforms(): array {
        return array(
            'no platform'      => array( null ),
            'another platform' => array( self::OTHER_PLATFORM ),
            'empty platform'   => array( '' ),
        );
    }

    #[DataProvider( 'foreign_platforms' )]
    public function test_checkout_session_routes_reject_a_foreign_platform( ?string $platform ): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $route   = '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID;
        $params  = array( 'id' => self::SESSION_ID );
        $headers = array( 'UCP-Agent' => '' );
        self::as_platform( $platform );

        $responses = array(
            'get'      => $this->controller()->get_session( new WP_REST_Request( $route, $headers, $params ) ),
            'update'   => $this->update( array( 'buyer' => array( 'first_name' => 'Eve' ) ), $platform ),
            'cancel'   => $this->controller()->cancel_session( new WP_REST_Request( $route . '/cancel', $headers, $params ) ),
            'complete' => $this->complete( $platform ),
        );

        foreach ( $responses as $name => $response ) {
            $this->assertSame( 404, $response->get_status(), $name . ' ' . json_encode( $response->get_data() ) );
            $this->assertSame( 'checkout_not_found', $response->get_data()['messages'][0]['code'] ?? $response->get_data()['code'] ?? null, $name );
        }
        $this->assertSame( array(), $this->handler->settled );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
        $this->assertSame( array(), $this->db->updates );
    }

    public function test_checkout_session_without_a_stored_platform_is_unreachable(): void {
        foreach ( array( null, '' ) as $stored ) {
            $this->db->sessions[ self::SESSION_ID ]['platform_id'] = $stored;

            $response = $this->controller()->get_session( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID, self::PLATFORM, array( 'id' => self::SESSION_ID ) ) );

            $this->assertSame( 404, $response->get_status(), json_encode( $response->get_data() ) );
        }
    }

    public function test_checkout_session_get_from_the_owning_platform_succeeds(): void {
        $response = $this->controller()->get_session( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID, self::PLATFORM, array( 'id' => self::SESSION_ID ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( self::SESSION_ID, $response->get_data()['id'] );
    }

    public function test_the_same_idempotency_key_from_another_platform_creates_its_own_session(): void {
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key']  = 'idem-shared-1';
        $this->db->sessions[ self::SESSION_ID ]['idempotency_hash'] = hash( 'sha256', json_encode( self::one_item() ) );
        self::as_platform( self::OTHER_PLATFORM );

        $response = $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-shared-1', 'UCP-Agent' => '' ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertNotSame( self::SESSION_ID, $response->get_data()['id'] );
        $this->assertSame( 1, FD_Test_Order_Store::$created );
        $this->assertSame( self::OTHER_PLATFORM, $this->db->sessions[ wp_generate_uuid4() ]['platform_id'] );
    }

    public function test_idempotent_replay_from_the_same_platform_returns_the_stored_session(): void {
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key']  = 'idem-shared-1';
        $this->db->sessions[ self::SESSION_ID ]['idempotency_hash'] = hash( 'sha256', json_encode( self::one_item() ) );

        $response = $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-shared-1' ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( self::SESSION_ID, $response->get_data()['id'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_the_same_idempotency_key_with_a_different_body_is_a_conflict(): void {
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key']  = 'idem-shared-1';
        $this->db->sessions[ self::SESSION_ID ]['idempotency_hash'] = hash( 'sha256', json_encode( self::one_item() ) );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '205' ), 'quantity' => 3 ) ) ), array( 'Idempotency-Key' => 'idem-shared-1' ) );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertStringNotContainsString( self::SESSION_ID, json_encode( $response->get_data() ) );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_a_legacy_row_without_a_platform_never_answers_an_idempotent_replay(): void {
        $this->db->sessions[ self::SESSION_ID ]['platform_id']      = null;
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key']  = 'idem-shared-1';
        $this->db->sessions[ self::SESSION_ID ]['idempotency_hash'] = hash( 'sha256', json_encode( self::one_item() ) );

        $response = $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-shared-1' ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertNotSame( self::SESSION_ID, $response->get_data()['id'] );
    }

    public function test_create_stores_the_idempotency_fingerprint_with_the_key(): void {
        $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-new-1' ) );

        $row = $this->db->sessions[ wp_generate_uuid4() ];
        $this->assertSame( 'idem-new-1', $row['idempotency_key'] );
        $this->assertSame( hash( 'sha256', json_encode( self::one_item() ) ), $row['idempotency_hash'] );
    }

    public function test_create_reports_a_storage_failure_instead_of_a_phantom_session(): void {
        $this->db->fail_inserts = true;

        $response = $this->create( self::one_item() );

        $this->assertSame( 503, $response->get_status(), json_encode( $response->get_data() ) );
    }

    #[DataProvider( 'foreign_platforms' )]
    public function test_order_get_rejects_a_foreign_platform( ?string $platform ): void {
        self::as_platform( $platform );

        $response = ( new FD_UCP_Order_Controller() )->get_order( new WP_REST_Request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD', array( 'UCP-Agent' => '' ), array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_order_without_a_stored_platform_is_unreachable(): void {
        $this->order()->meta = array( '_fd_ucp_handler_id' => 'xyz.fd.prism_payment', '_fd_ucp_tx_reference' => '0xmine' );

        $get = ( new FD_UCP_Order_Controller() )->get_order( self::request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD', self::PLATFORM, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $this->assertSame( 404, $get->get_status() );
    }

    #[DataProvider( 'foreign_platforms' )]
    public function test_returns_reject_a_foreign_platform( ?string $platform ): void {
        $this->order()->status = 'processing';
        $controller            = new FD_UCP_Returns_Controller();
        $headers               = array( 'UCP-Agent' => '' );
        self::as_platform( $platform );

        $create = $controller->create_return( new WP_REST_Request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD/returns', $headers, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );
        $list   = $controller->list_returns( new WP_REST_Request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD/returns', $headers, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $this->assertSame( 404, $create->get_status() );
        $this->assertSame( 404, $list->get_status() );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertSame( 'processing', $this->order()->status );
    }

    public function test_owner_return_request_records_no_refund(): void {
        $this->order()->status = 'processing';

        $response = ( new FD_UCP_Returns_Controller() )->create_return( self::request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD/returns', self::PLATFORM, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $this->assertSame( 202, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'requested', $response->get_data()['return']['status'] );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertSame( 'processing', $this->order()->status );
        $this->assertCount( 1, $this->order()->get_meta( '_fd_ucp_return_requests' ) );
    }

    #[DataProvider( 'foreign_platforms' )]
    public function test_buyer_identity_rejects_a_foreign_platform( ?string $platform ): void {
        $controller = new FD_UCP_Buyer_Identity_Controller();
        $route      = '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/buyer';
        $headers    = array( 'UCP-Agent' => '' );
        self::as_platform( $platform );

        $get = $controller->get_buyer( new WP_REST_Request( $route, $headers, array( 'id' => self::SESSION_ID ) ) );
        $put = $controller->update_buyer( new WP_REST_Request( $route, $headers, array( 'id' => self::SESSION_ID ), array( 'email' => 'eve@example.test' ) ) );

        $this->assertSame( 404, $get->get_status() );
        $this->assertSame( 404, $put->get_status() );
        $this->assertSame( array(), $this->db->updates );
    }

    public function test_buyer_identity_from_the_owning_platform_succeeds(): void {
        $response = ( new FD_UCP_Buyer_Identity_Controller() )->get_buyer( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/buyer', self::PLATFORM, array( 'id' => self::SESSION_ID ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
    }

    private function promote( string $code, ?string $platform = self::PLATFORM ): WP_REST_Response {
        self::as_platform( $platform );
        return ( new FD_UCP_Promotions_Controller( $this->controller() ) )->apply( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/promotions',
            array( 'UCP-Agent' => '' ),
            array( 'id' => self::SESSION_ID ),
            array( 'code' => $code )
        ) );
    }

    #[DataProvider( 'foreign_platforms' )]
    public function test_promotions_reject_a_foreign_platform( ?string $platform ): void {
        $this->assertSame( 404, $this->promote( 'SAVE10', $platform )->get_status() );
    }

    public function test_cart_belongs_to_the_creating_platform_and_carries_into_its_checkout_session(): void {
        $carts   = new FD_UCP_Cart_Controller();
        $created = $carts->create_cart( new WP_REST_Request( '/fd-ucp/v1/carts', array( 'UCP-Agent' => '' ), array(), self::one_item() ) );
        $cart_id = $created->get_data()['id'];
        $route   = '/fd-ucp/v1/carts/' . $cart_id;
        $this->assertSame( self::PLATFORM, $this->db->carts[ $cart_id ]['platform_id'] );

        self::as_platform( self::OTHER_PLATFORM );
        $foreign = $carts->get_cart( new WP_REST_Request( $route, array( 'UCP-Agent' => '' ), array( 'id' => $cart_id ) ) );
        $checkout = $carts->checkout( self::request( $route . '/checkout', self::PLATFORM, array( 'id' => $cart_id ) ) );

        $this->assertSame( 201, $created->get_status() );
        $this->assertSame( array(), $created->get_headers() );
        $this->assertSame( 404, $foreign->get_status() );
        $this->assertSame( 201, $checkout->get_status(), json_encode( $checkout->get_data() ) );
        $this->assertSame( self::PLATFORM, $this->db->sessions[ $checkout->get_data()['checkout_session_id'] ]['platform_id'] ?? null );
    }

    public function test_owner_sees_the_recorded_return_request(): void {
        $controller = new FD_UCP_Returns_Controller();
        $controller->create_return( self::request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD/returns', self::PLATFORM, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $response = $controller->list_returns( self::request( '/fd-ucp/v1/orders/wc_order_Zk3q9XvT1aBcD/returns', self::PLATFORM, array( 'id' => 'wc_order_Zk3q9XvT1aBcD' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'requested' ), array_column( $response->get_data()['returns'], 'status' ) );
    }


    private const DUMMY_ID = 'xyz.fd.dummy_payment';

    private static function dummy_registry( string $environment, bool $debug, mixed $enabled ): FD_Payment_Registry {
        FD_Test_WP::$environment = $environment;
        define( 'WP_DEBUG', $debug );
        if ( null !== $enabled ) {
            define( 'FD_DUMMY_PAYMENT_ENABLED', $enabled );
        }
        fd_dummy_payment_init();
        $registry = new FD_Payment_Registry();
        do_action( 'fd_ucp_register_payment_handlers', $registry );
        return $registry;
    }

    private function dummy_session( array $payment_meta ): void {
        $this->db->sessions[ self::SESSION_ID ]['totals']       = json_encode( self::untaxed_totals() );
        $this->db->sessions[ self::SESSION_ID ]['payment_meta'] = json_encode( $payment_meta );
    }

    private static function dummy_prepared( FD_Payment_Registry $registry ): array {
        return $registry->prepare_all( array(
            'total'             => 4695,
            'checkout_id'       => self::SESSION_ID,
            'checkout_base_url' => 'https://shop.example/wp-json/fd-ucp/v1',
            'store_name'        => 'Shop',
        ) );
    }

    private function complete_with_dummy( FD_Payment_Registry $registry, array $credential ): WP_REST_Response {
        return ( new FD_UCP_Checkout_Controller( $registry ) )->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            array(),
            array( 'id' => self::SESSION_ID ),
            array( 'payment' => array( 'instruments' => array( array(
                'handler_id' => self::DUMMY_ID,
                'credential' => $credential,
            ) ) ) )
        ) );
    }

    public static function dummy_blocked_configurations(): array {
        return array(
            'production with debug on and no opt-in' => array( 'production', true, null ),
            'production with opt-in'                 => array( 'production', true, true ),
            'staging with opt-in'                    => array( 'staging', true, true ),
            'unknown environment with opt-in'        => array( 'qa', true, true ),
            'development without opt-in'             => array( 'development', true, null ),
            'development with opt-in set to false'   => array( 'development', true, false ),
            'development with opt-in set to 1'       => array( 'development', true, 1 ),
            'development with opt-in set to "true"'  => array( 'development', true, 'true' ),
        );
    }

    #[DataProvider( 'dummy_blocked_configurations' )]
    #[RunInSeparateProcess]
    #[PreserveGlobalState( false )]
    public function test_dummy_handler_cannot_be_selected_outside_an_opted_in_development_site( string $environment, bool $debug, mixed $enabled ): void {
        $registry = self::dummy_registry( $environment, $debug, $enabled );
        $this->assertNull( $registry->get( self::DUMMY_ID ) );
        $this->assertArrayNotHasKey( self::DUMMY_ID, $registry->get_ucp_discovery_handlers() );

        $this->dummy_session( array( self::DUMMY_ID => array( 'prepared_amount' => 4695, 'prepared_at' => time() ) ) );
        $response = $this->complete_with_dummy( $registry, array( 'amount' => '4695' ) );

        $this->assertSame( 400, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_instrument', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState( false )]
    public function test_dummy_handler_refuses_to_settle_once_the_site_is_production(): void {
        $registry = self::dummy_registry( 'local', false, true );
        $prepared = self::dummy_prepared( $registry );
        FD_Test_WP::$environment = 'production';

        $result = $registry->get( self::DUMMY_ID )->settle_payment( array(
            'checkout_id'   => self::SESSION_ID,
            'credential'    => array( 'amount' => '4695' ),
            'checkout_meta' => $prepared,
        ) );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'transaction_reference', $result );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState( false )]
    public function test_dummy_handler_registered_directly_on_production_cannot_complete_a_checkout(): void {
        $registry = self::dummy_registry( 'production', true, true );
        $registry->register( new FD_Dummy_Handler() );
        $this->dummy_session( array( self::DUMMY_ID => array( 'prepared_amount' => 4695, 'prepared_at' => time() ) ) );

        $response = $this->complete_with_dummy( $registry, array( 'amount' => '4695' ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'payment_failed', $response->get_data()['messages'][0]['code'] );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState( false )]
    public function test_dummy_handler_refuses_to_settle_without_a_stored_quote(): void {
        $registry = self::dummy_registry( 'development', false, true );

        $result = $registry->get( self::DUMMY_ID )->settle_payment( array(
            'checkout_id'   => self::SESSION_ID,
            'credential'    => array( 'amount' => '4695' ),
            'checkout_meta' => array(),
        ) );

        $this->assertFalse( $result['success'] );
        $this->assertArrayNotHasKey( 'transaction_reference', $result );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState( false )]
    public function test_opted_in_development_dummy_settles_the_stored_quote_not_the_buyer_amount(): void {
        $registry = self::dummy_registry( 'development', false, true );
        $this->assertArrayNotHasKey( self::DUMMY_ID, $registry->get_ucp_discovery_handlers() );
        $this->dummy_session( self::dummy_prepared( $registry ) );

        $response = $this->complete_with_dummy( $registry, array( 'amount' => '1' ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'completed', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    private function new_cart( int $quantity = 2 ): array {
        $carts   = new FD_UCP_Cart_Controller();
        $created = $carts->create_cart( new WP_REST_Request(
            '/fd-ucp/v1/carts',
            array(),
            array(),
            array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => $quantity ) ) )
        ) );
        return array( $carts, self::PLATFORM, (string) $created->get_data()['id'] );
    }

    private function cart_request( string $platform, string $cart_id, array $body = array() ): WP_REST_Request {
        self::as_platform( $platform );
        return new WP_REST_Request( '/fd-ucp/v1/carts/' . $cart_id, array(), array( 'id' => $cart_id ), $body );
    }

    private function make_cart_unavailable( ?FD_Test_Product $replacement ): void {
        unset( FD_Test_Product_Store::$products[101] );
        if ( null !== $replacement ) {
            FD_Test_Product_Store::$products[101] = $replacement;
        }
    }

    public function test_cart_has_a_limited_lifetime(): void {
        [ , , $cart_id ] = $this->new_cart();

        $expires_at = strtotime( (string) ( $this->db->carts[ $cart_id ]['expires_at'] ?? '' ) );

        $this->assertEqualsWithDelta( time() + 6 * HOUR_IN_SECONDS, $expires_at, 5 );
    }

    public static function cart_operations(): array {
        return array(
            'read'     => array( 'get_cart' ),
            'update'   => array( 'update_cart' ),
            'checkout' => array( 'checkout' ),
            'delete'   => array( 'delete_cart' ),
        );
    }

    public static function dead_cart_expiries(): array {
        $cases = array();
        foreach ( self::cart_operations() as $name => [ $operation ] ) {
            $cases[ "$name after expiry" ]  = array( $operation, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
            $cases[ "$name without expiry" ] = array( $operation, null );
        }
        return $cases;
    }

    #[DataProvider( 'dead_cart_expiries' )]
    public function test_expired_or_unexpiring_cart_is_refused( string $operation, ?string $expires_at ): void {
        [ $carts, $platform, $cart_id ] = $this->new_cart();
        $this->db->carts[ $cart_id ]['expires_at'] = $expires_at;
        unset( $this->db->sessions[ $cart_id ] );

        $response = $carts->$operation( $this->cart_request( $platform, $cart_id, self::one_item() ) );

        $this->assertSame( 404, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertArrayNotHasKey( $cart_id, $this->db->sessions );
    }

    public function test_cart_checkout_reprices_the_stored_items_at_the_current_price(): void {
        [ $carts, $platform, $cart_id ] = $this->new_cart();
        unset( $this->db->sessions[ $cart_id ] );
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->checkout( $this->cart_request( $platform, $cart_id ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $session_id = $response->get_data()['checkout_session_id'];
        $items      = json_decode( $this->db->sessions[ $session_id ]['line_items'], true );
        $this->assertSame( 2500, $items[0]['item']['price'] );
        $this->assertSame( array( 'subtotal' => 5000, 'total' => 5000 ), $this->stored_totals( $session_id ) );
        $this->assertSame( 2500, $response->get_data()['line_items'][0]['item']['price'] );
        $this->assertSame( 5000, array_column( $response->get_data()['totals'], 'amount', 'type' )['total'] );
    }

    public function test_cart_read_shows_the_current_price(): void {
        [ $carts, $platform, $cart_id ] = $this->new_cart();
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->get_cart( $this->cart_request( $platform, $cart_id ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 2500, $response->get_data()['line_items'][0]['item']['price'] );
    }

    public function test_cart_update_prices_the_items_at_the_current_price(): void {
        [ $carts, $platform, $cart_id ] = $this->new_cart();
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->update_cart( $this->cart_request( $platform, $cart_id, self::one_item() ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 2500, $response->get_data()['line_items'][0]['item']['price'] );
    }

    public static function unavailable_products(): array {
        return array(
            'removed'         => array( null ),
            'not purchasable' => array( new FD_Test_Product( 101, '18.00', 'Test product', true, false ) ),
        );
    }

    #[DataProvider( 'unavailable_products' )]
    public function test_cart_checkout_refuses_a_product_that_is_no_longer_available( ?FD_Test_Product $replacement ): void {
        [ $carts, $platform, $cart_id ] = $this->new_cart();
        unset( $this->db->sessions[ $cart_id ] );
        $this->make_cart_unavailable( $replacement );

        $response = $carts->checkout( $this->cart_request( $platform, $cart_id ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertArrayNotHasKey( $cart_id, $this->db->sessions );
        $this->assertArrayHasKey( $cart_id, $this->db->carts );
    }

    #[DataProvider( 'unavailable_products' )]
    public function test_complete_refuses_to_settle_a_product_that_is_no_longer_available( ?FD_Test_Product $replacement ): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $this->make_cart_unavailable( $replacement );

        $response = $this->complete();

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_product', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_refuses_to_settle_an_expired_quote(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), time() - 3600 );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'quote_expired', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_refuses_to_settle_a_quote_without_a_preparation_time(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), null, false );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'quote_expired', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_complete_refuses_an_expired_quote_before_touching_the_session_or_order(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), time() - 3600 );
        $this->db->updates = array();

        $this->complete();

        $this->assertSame( array(), $this->db->updates );
    }

    public function test_complete_marks_the_session_in_progress_only_after_every_check_passed(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );
        $this->db->updates = array();

        $response = $this->complete();

        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $statuses = array_column( array_column( $this->db->updates, 1 ), 'status' );
        $this->assertNotContains( 'complete_in_progress', $statuses );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_complete_settles_a_quote_inside_its_lifetime(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), time() - 300 );

        $response = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 4695 ), $this->handler->settled );
    }

    public function test_update_requotes_when_the_stored_quote_is_older_than_its_lifetime(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), time() - 3600 );

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 4695 ), $this->handler->prepared );
        $stamp = json_decode( $this->db->sessions[ self::SESSION_ID ]['payment_meta'], true )[ $this->handler->id() ]['prepared_at'] ?? null;
        $this->assertEqualsWithDelta( time(), $stamp, 5 );
    }

    public function test_update_keeps_a_quote_inside_its_lifetime(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals(), time() - 300 );

        $response = $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array(), $this->handler->prepared );
    }

    public function test_new_quotes_carry_their_preparation_time(): void {
        $response = $this->create( self::one_item() );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $stamp = json_decode( $this->db->sessions[ wp_generate_uuid4() ]['payment_meta'], true )[ $this->handler->id() ]['prepared_at'] ?? null;
        $this->assertEqualsWithDelta( time(), $stamp, 5 );
    }

    public static function unpayable_quantities(): array {
        return array(
            'huge int'          => array( 1000000000 ),
            'int max'           => array( PHP_INT_MAX ),
            'int max as string' => array( '9223372036854775807' ),
            'float overflow'    => array( 1e30 ),
            'one above the cap' => array( 1000 ),
            'zero'              => array( 0 ),
            'negative'          => array( -5 ),
            'text'              => array( 'abc' ),
            'fraction'          => array( 1.5 ),
            'boolean'           => array( true ),
        );
    }

    #[DataProvider( 'unpayable_quantities' )]
    public function test_create_rejects_a_quantity_outside_the_allowed_range( mixed $quantity ): void {
        FD_Test_Product_Store::$products[400] = new FD_Test_Product( 400, '18.00', 'Backorder', true, true, 0, true );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '400' ), 'quantity' => $quantity ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_quantity', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
        $this->assertSame( array(), $this->handler->prepared );
    }

    #[DataProvider( 'unpayable_quantities' )]
    public function test_update_rejects_a_quantity_outside_the_allowed_range( mixed $quantity ): void {
        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => $quantity ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_quantity', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
    }

    #[DataProvider( 'unpayable_quantities' )]
    public function test_cart_line_items_reject_a_quantity_outside_the_allowed_range( mixed $quantity ): void {
        $priced = FD_UCP_Checkout_Pricing::catalog_line_items( array( array( 'item' => array( 'id' => '101' ), 'quantity' => $quantity ) ), false );

        $this->assertInstanceOf( WP_Error::class, $priced );
        $this->assertSame( 'invalid_quantity', $priced->get_error_code() );
    }

    public function test_create_accepts_the_largest_allowed_quantity(): void {
        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => '999' ) ) ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 1798200, $this->stored_totals( wp_generate_uuid4() )['subtotal'] );
    }

    public function test_create_rejects_a_total_beyond_the_payable_range(): void {
        FD_Test_Product_Store::$products[401] = new FD_Test_Product( 401, '5000000.00', 'Pricey', false );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '401' ), 'quantity' => 999 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_total', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_create_rejects_a_catalog_price_beyond_the_payable_range(): void {
        FD_Test_Product_Store::$products[402] = new FD_Test_Product( 402, '1.0E+30', 'Broken price', false );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '402' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_total', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_create_rejects_a_quantity_the_stock_cannot_cover(): void {
        FD_Test_Product_Store::$products[403] = new FD_Test_Product( 403, '18.00', 'Limited', true, true, 2 );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '403' ), 'quantity' => 3 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'out_of_stock', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_create_rejects_a_product_that_is_out_of_stock(): void {
        FD_Test_Product_Store::$products[403] = new FD_Test_Product( 403, '18.00', 'Sold out', true, true, 0 );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '403' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'out_of_stock', $response->get_data()['messages'][0]['code'] );
    }

    public function test_create_counts_a_product_repeated_across_lines_against_its_stock(): void {
        FD_Test_Product_Store::$products[403] = new FD_Test_Product( 403, '18.00', 'Limited', true, true, 3 );

        $response = $this->create( array( 'line_items' => array(
            array( 'item' => array( 'id' => '403' ), 'quantity' => 2 ),
            array( 'item' => array( 'id' => '403' ), 'quantity' => 2 ),
        ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'out_of_stock', $response->get_data()['messages'][0]['code'] );
    }

    public function test_create_accepts_a_backordered_quantity_within_the_cap(): void {
        FD_Test_Product_Store::$products[404] = new FD_Test_Product( 404, '18.00', 'Backorder', true, true, 0, true );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '404' ), 'quantity' => 5 ) ) ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
    }

    public function test_create_counts_variations_that_share_one_stock_pool(): void {
        FD_Test_Product_Store::$products[405] = new FD_Test_Product( 405, '18.00', 'Size S', true, true, 3, false, 900 );
        FD_Test_Product_Store::$products[406] = new FD_Test_Product( 406, '18.00', 'Size M', true, true, 3, false, 900 );

        $response = $this->create( array( 'line_items' => array(
            array( 'item' => array( 'id' => '405' ), 'quantity' => 2 ),
            array( 'item' => array( 'id' => '406' ), 'quantity' => 2 ),
        ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'out_of_stock', $response->get_data()['messages'][0]['code'] );
    }

    public function test_order_total_is_an_amount_only_inside_the_payable_range(): void {
        $order        = new WC_Order();
        $order->total = '46.95';
        $this->assertSame( 4695, FD_UCP_Checkout_Pricing::order_total_minor( $order ) );

        $order->total = '2000000000.00';
        $this->assertNull( FD_UCP_Checkout_Pricing::order_total_minor( $order ) );

        $order->total = '-5.00';
        $this->assertNull( FD_UCP_Checkout_Pricing::order_total_minor( $order ) );
    }

    public function test_complete_refuses_to_settle_when_the_order_total_is_beyond_the_payable_range(): void {
        FD_Test_WC::$tax_rate = 1000000000.0;
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'total_changed', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_priced_totals_reject_amounts_beyond_the_payable_range(): void {
        $this->assertInstanceOf( WP_Error::class, FD_UCP_Checkout_Pricing::priced_totals( PHP_INT_MAX, 0 ) );
        $this->assertInstanceOf( WP_Error::class, FD_UCP_Checkout_Pricing::priced_totals( 100, PHP_INT_MAX ) );
        $this->assertInstanceOf( WP_Error::class, FD_UCP_Checkout_Pricing::priced_totals( 100, 100, PHP_INT_MAX ) );
    }

    public function test_create_rejects_the_parent_of_a_variable_product(): void {
        FD_Test_Product_Store::$products[500] = new FD_Test_Product( 500, '18.00', 'Shirt', true, true, null, false, null, true );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '500' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'variation_required', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_update_rejects_the_parent_of_a_variable_product(): void {
        FD_Test_Product_Store::$products[500] = new FD_Test_Product( 500, '18.00', 'Shirt', true, true, null, false, null, true );

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '500' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'variation_required', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
    }

    public function test_cart_line_items_reject_the_parent_of_a_variable_product(): void {
        FD_Test_Product_Store::$products[500] = new FD_Test_Product( 500, '18.00', 'Shirt', true, true, null, false, null, true );

        $priced = FD_UCP_Checkout_Pricing::catalog_line_items( array( array( 'item' => array( 'id' => '500' ), 'quantity' => 1 ) ), false );

        $this->assertInstanceOf( WP_Error::class, $priced );
        $this->assertSame( 'variation_required', $priced->get_error_code() );
    }

    public function test_complete_refuses_to_settle_a_product_that_became_variable(): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '18.00', 'Shirt', true, true, null, false, null, true );

        $response = $this->complete();

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'variation_required', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
    }

    public function test_create_accepts_a_concrete_variation(): void {
        FD_Test_Product_Store::$products[501] = new FD_Test_Product( 501, '24.00', 'Shirt - Large', true, true, 5, false, 500 );

        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '501' ), 'quantity' => 2 ) ) ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 4800, $this->stored_totals( wp_generate_uuid4() )['subtotal'] );
    }

    public function test_promotion_is_written_to_the_order_and_payment_is_prepared_again(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );

        $response = $this->promote( 'SAVE10' );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 4200, 'fulfillment' => 495, 'discount' => 420, 'total' => 4275 ), $this->stored_totals() );
        $this->assertSame( array( 4275 ), $this->handler->prepared );
        $this->assertSame( 4275, json_decode( $this->db->sessions[ self::SESSION_ID ]['payment_meta'], true )[ $this->handler->id() ]['prepared_amount'] );
        $this->assertSame( array( 'SAVE10' ), $this->order()->get_coupon_codes() );
        $this->assertSame( '42.75', $this->order()->get_total() );
        $this->assertSame( 420, $response->get_data()['promotion_applied']['discount'] );
        $this->assertSame( 0, FD_Test_Coupon_Store::used( 'SAVE10' ) );
    }

    public function test_discounted_session_completes_at_the_discounted_total(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->promote( 'SAVE10' );
        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 4275 );

        $response = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 4275 ), $this->handler->settled );
        $this->assertContains( 'payment_complete', $this->order()->calls );
        $this->assertSame( array( 'SAVE10' ), $this->order()->get_coupon_codes() );
        $this->assertSame( '42.75', $this->order()->get_total() );
        $this->assertSame( 1, FD_Test_Coupon_Store::used( 'SAVE10' ) );
    }

    public function test_full_price_settlement_of_a_discounted_session_is_held(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->promote( 'SAVE10' );
        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 4695 );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
    }

    public function test_coupon_over_its_usage_limit_is_refused_and_changes_nothing(): void {
        FD_Test_Coupon_Store::add( 'ONCE', 'percent', 50, array( 'limit' => 1, 'used' => 1 ) );

        $response = $this->promote( 'ONCE' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'coupon_invalid', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_unknown_coupon_is_refused(): void {
        $response = $this->promote( 'NOPE' );

        $this->assertSame( 404, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 4695, $this->stored_total() );
    }

    public function test_discount_cannot_exceed_the_subtotal(): void {
        FD_Test_Coupon_Store::add( 'HUGE', 'fixed_cart', 1000 );

        $response = $this->promote( 'HUGE' );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 4200, 'fulfillment' => 495, 'discount' => 4200, 'total' => 495 ), $this->stored_totals() );
        $this->assertSame( array( 495 ), $this->handler->prepared );
    }

    public function test_promotion_that_leaves_nothing_to_pay_is_refused_and_not_kept_on_the_order(): void {
        FD_Test_Coupon_Store::add( 'FREE', 'percent', 100 );
        $this->db->sessions[ self::SESSION_ID ]['fulfillment'] = null;

        $response = $this->promote( 'FREE' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'invalid_total', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->handler->prepared );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_line_item_change_reprices_the_applied_coupon(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->promote( 'SAVE10' );

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 1800, 'fulfillment' => 495, 'discount' => 180, 'total' => 2115 ), $this->stored_totals() );
        $this->assertSame( array( 4275, 2115 ), $this->handler->prepared );
    }

    public function test_applied_coupon_is_dropped_when_its_conditions_no_longer_hold(): void {
        FD_Test_Coupon_Store::add( 'BIGSPEND', 'fixed_cart', 5, array( 'minimum' => 40.0 ) );
        $this->promote( 'BIGSPEND' );

        $response = $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '205' ), 'quantity' => 1 ) ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'subtotal' => 600, 'fulfillment' => 495, 'total' => 1095 ), $this->stored_totals() );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_complete_refuses_a_discounted_quote_whose_coupon_stopped_applying(): void {
        FD_Test_Coupon_Store::add( 'ONCE', 'percent', 50, array( 'limit' => 1 ) );
        $this->promote( 'ONCE' );
        FD_Test_Coupon_Store::$coupons['once']['used'] = 1;
        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 4275 );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'coupon_invalid', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertNotContains( 'payment_complete', $this->order()->calls );
    }

    public function test_coupon_usage_is_not_consumed_by_quoting_and_is_counted_once_at_payment(): void {
        FD_Test_Coupon_Store::add( 'LAST', 'percent', 10, array( 'limit' => 1 ) );

        $this->assertSame( 200, $this->promote( 'LAST' )->get_status() );
        $this->assertSame( 200, $this->update( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) ) )->get_status() );
        $this->assertSame( 200, $this->update( array( 'buyer' => array( 'first_name' => 'Anna' ) ) )->get_status() );
        $this->assertSame( 0, FD_Test_Coupon_Store::used( 'LAST' ) );
        $this->assertSame( array( 'subtotal' => 1800, 'fulfillment' => 495, 'discount' => 180, 'total' => 2115 ), $this->stored_totals() );

        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 2115 );
        $response              = $this->complete();

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 1, FD_Test_Coupon_Store::used( 'LAST' ) );
    }

    public function test_coupon_usage_is_not_counted_when_completion_is_refused(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->promote( 'SAVE10' );
        $this->handler->result = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 1 );

        $this->assertSame( 409, $this->complete()->get_status() );
        $this->assertSame( 0, FD_Test_Coupon_Store::used( 'SAVE10' ) );
    }

    public function test_individual_use_coupon_cannot_be_combined(): void {
        FD_Test_Coupon_Store::add( 'SOLO', 'percent', 10, array( 'individual' => true ) );
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->assertSame( 200, $this->promote( 'SAVE10' )->get_status() );

        $response = $this->promote( 'SOLO' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'coupon_invalid', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array( 'SAVE10' ), $this->order()->get_coupon_codes() );
        $this->assertSame( 4275, $this->stored_total() );
    }

    public function test_coupon_cannot_be_added_to_an_individual_use_coupon(): void {
        FD_Test_Coupon_Store::add( 'SOLO', 'percent', 10, array( 'individual' => true ) );
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->assertSame( 200, $this->promote( 'SOLO' )->get_status() );

        $response = $this->promote( 'SAVE10' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'SOLO' ), $this->order()->get_coupon_codes() );
    }

    public function test_coupon_restricted_to_other_emails_is_refused(): void {
        FD_Test_Coupon_Store::add( 'STAFF', 'percent', 50, array( 'emails' => array( '*@corp.example' ) ) );

        $response = $this->promote( 'STAFF' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'coupon_invalid', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_email_restricted_coupon_is_refused_without_a_buyer_email(): void {
        FD_Test_Coupon_Store::add( 'STAFF', 'percent', 50, array( 'emails' => array( '*@corp.example' ) ) );
        $this->db->sessions[ self::SESSION_ID ]['buyer'] = null;

        $response = $this->promote( 'STAFF' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_email_restricted_coupon_is_accepted_for_a_matching_buyer(): void {
        FD_Test_Coupon_Store::add( 'STAFF', 'percent', 50, array( 'emails' => array( '*@example.com' ) ) );

        $response = $this->promote( 'STAFF' );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'STAFF' ), $this->order()->get_coupon_codes() );
    }

    public function test_complete_refuses_when_the_buyer_email_no_longer_matches_the_coupon(): void {
        FD_Test_Coupon_Store::add( 'STAFF', 'percent', 50, array( 'emails' => array( '*@example.com' ) ) );
        $this->assertSame( 200, $this->promote( 'STAFF' )->get_status() );
        $buyer                                           = json_decode( $this->db->sessions[ self::SESSION_ID ]['buyer'], true );
        $buyer['email']                                  = 'mallory@elsewhere.test';
        $this->db->sessions[ self::SESSION_ID ]['buyer'] = json_encode( $buyer );
        $this->handler->result                           = array( 'success' => true, 'transaction_reference' => '0xfeed', 'settled_amount' => 2348 );

        $response = $this->complete();

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'coupon_invalid', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_coupon_over_its_per_user_limit_is_refused_by_buyer_email(): void {
        FD_Test_Coupon_Store::add( 'SOLO1', 'percent', 10, array( 'per_user' => 1 ) );
        FD_Test_Coupon_Store::$emails['solo1']['anna.schmidt@example.com'] = 1;

        $response = $this->promote( 'SOLO1' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public function test_per_user_limited_coupon_is_refused_without_a_buyer_email(): void {
        FD_Test_Coupon_Store::add( 'SOLO1', 'percent', 10, array( 'per_user' => 1 ) );
        $this->db->sessions[ self::SESSION_ID ]['buyer'] = null;

        $response = $this->promote( 'SOLO1' );

        $this->assertSame( 422, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    #[DataProvider( 'closed_session_statuses' )]
    public function test_promotion_is_refused_on_a_closed_session( string $status ): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->db->sessions[ self::SESSION_ID ]['status'] = $status;

        $response = $this->promote( 'SAVE10' );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    public static function closed_session_statuses(): array {
        return array(
            'canceled'            => array( 'canceled' ),
            'completed'           => array( 'completed' ),
            'requires_escalation' => array( 'requires_escalation' ),
            'expired'             => array( 'expired' ),
        );
    }

    public function test_promotion_is_refused_while_the_session_is_locked(): void {
        FD_Test_Coupon_Store::add( 'SAVE10', 'percent', 10 );
        $this->db->held = array( self::lock_name() );

        $response = $this->promote( 'SAVE10' );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'session_busy', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( 4695, $this->stored_total() );
        $this->assertSame( array(), $this->order()->get_coupon_codes() );
    }

    private static function one_item(): array {
        return array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) );
    }
}
