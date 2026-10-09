<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CheckoutTamperTest extends TestCase {

    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const SUBTOTAL   = 4200;
    private const TOKEN      = 'c0ffee11c0ffee22c0ffee33c0ffee44c0ffee55c0ffee66c0ffee77c0ffee88';

    private FD_Test_Wpdb $db;
    private object $handler;

    protected function setUp(): void {
        FD_Test_WP::reset();

        $session                       = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['session_token_hash'] = hash( 'sha256', self::TOKEN );
        $session['ucp_version']        = null;
        $session['expires_at']         = gmdate( 'Y-m-d H:i:s', time() + 3600 );

        $this->db                               = new FD_Test_Wpdb();
        $this->db->sessions[ self::SESSION_ID ] = $session;
        $GLOBALS['wpdb']                        = $this->db;

        FD_Test_Order_Store::$orders     = array( 1001 => self::owned_order( self::TOKEN, '0xmine' ) );
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
        FD_Test_WC::$rates               = array();
        FD_Test_WC::$tax_rate            = 0.0;
        FD_Test_WC::$included_rate       = 0.0;
    }

    private function controller(): FD_UCP_Checkout_Controller {
        $registry = new FD_Payment_Registry();
        $registry->register( $this->handler );
        return new FD_UCP_Checkout_Controller( $registry );
    }

    private static function owned_order( string $token, string $tx_reference ): WC_Order {
        $order       = new WC_Order();
        $order->meta = array(
            '_fd_ucp_tx_reference'       => $tx_reference,
            '_fd_ucp_handler_id'         => 'xyz.fd.prism_payment',
            '_fd_ucp_session_token_hash' => hash( 'sha256', $token ),
        );
        return $order;
    }

    private static function headers( ?string $token ): array {
        return null === $token ? array() : array( 'UCP-Session-Token' => $token );
    }

    private static function request( string $route, ?string $token, array $params = array(), array $body = array() ): WP_REST_Request {
        return new WP_REST_Request( $route, self::headers( $token ), $params, $body );
    }

    private function update( array $body, ?string $token = self::TOKEN ): WP_REST_Response {
        return $this->controller()->update_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID,
            self::headers( $token ),
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

    private function complete( ?string $token = self::TOKEN ): WP_REST_Response {
        return $this->controller()->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            self::headers( $token ),
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

    public function test_create_returns_a_session_token_once_and_stores_only_its_hash(): void {
        $response = $this->create( array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) ) );

        $token = $response->get_headers()['UCP-Session-Token'] ?? '';
        $row   = $this->db->sessions[ wp_generate_uuid4() ];
        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
        $this->assertSame( hash( 'sha256', $token ), $row['session_token_hash'] ?? null );
        $this->assertStringNotContainsString( $token, json_encode( $row ) );
        $this->assertStringNotContainsString( $token, json_encode( $response->get_data() ) );
    }

    public static function foreign_tokens(): array {
        return array(
            'no token, same agent header' => array( null ),
            'wrong token'                 => array( str_repeat( 'f', 64 ) ),
            'empty token'                 => array( '' ),
        );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_checkout_session_routes_reject_a_foreign_token( ?string $token ): void {
        $this->quoted_session( 4695, 4695, self::untaxed_totals() );
        $route   = '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID;
        $params  = array( 'id' => self::SESSION_ID );
        $headers = self::headers( $token ) + array( 'UCP-Agent' => '' );

        $responses = array(
            'get'      => $this->controller()->get_session( new WP_REST_Request( $route, $headers, $params ) ),
            'update'   => $this->update( array( 'buyer' => array( 'first_name' => 'Eve' ) ), $token ),
            'cancel'   => $this->controller()->cancel_session( new WP_REST_Request( $route . '/cancel', $headers, $params ) ),
            'complete' => $this->complete( $token ),
        );

        foreach ( $responses as $name => $response ) {
            $this->assertSame( 403, $response->get_status(), $name . ' ' . json_encode( $response->get_data() ) );
        }
        $this->assertSame( array(), $this->handler->settled );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
        $this->assertSame( array(), $this->db->updates );
    }

    public function test_checkout_session_without_a_stored_token_hash_is_rejected(): void {
        unset( $this->db->sessions[ self::SESSION_ID ]['session_token_hash'] );

        $response = $this->controller()->get_session( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID, self::TOKEN, array( 'id' => self::SESSION_ID ) ) );

        $this->assertSame( 403, $response->get_status(), json_encode( $response->get_data() ) );
    }

    public function test_checkout_session_get_with_the_session_token_succeeds(): void {
        $response = $this->controller()->get_session( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID, self::TOKEN, array( 'id' => self::SESSION_ID ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( self::SESSION_ID, $response->get_data()['id'] );
    }

    public function test_idempotent_replay_without_the_session_token_does_not_return_the_session(): void {
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key'] = 'idem-shared-1';

        $response = $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-shared-1', 'UCP-Agent' => '' ) );

        $this->assertSame( 409, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertStringNotContainsString( self::SESSION_ID, json_encode( $response->get_data() ) );
        $this->assertSame( 0, FD_Test_Order_Store::$created );
    }

    public function test_idempotent_replay_with_the_session_token_returns_the_session(): void {
        $this->db->sessions[ self::SESSION_ID ]['idempotency_key'] = 'idem-shared-1';

        $response = $this->create( self::one_item(), array( 'Idempotency-Key' => 'idem-shared-1', 'UCP-Session-Token' => self::TOKEN ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( self::SESSION_ID, $response->get_data()['id'] );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_order_list_does_not_return_other_buyers_orders( ?string $token ): void {
        FD_Test_Order_Store::$orders[1002] = self::owned_order( 'another-buyer', '0xtheirs' );

        $response = ( new FD_UCP_Order_Controller() )->list_orders( new WP_REST_Request( '/fd-ucp/v1/orders', self::headers( $token ) + array( 'UCP-Agent' => '' ) ) );

        $this->assertStringNotContainsString( '0xmine', json_encode( $response->get_data() ) );
        $this->assertStringNotContainsString( '0xtheirs', json_encode( $response->get_data() ) );
    }

    public function test_order_list_returns_only_the_order_of_the_presented_token(): void {
        FD_Test_Order_Store::$orders[1002] = self::owned_order( 'another-buyer', '0xtheirs' );

        $response = ( new FD_UCP_Order_Controller() )->list_orders( self::request( '/fd-ucp/v1/orders', self::TOKEN ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( '0xmine' ), array_column( $response->get_data()['orders'], 'transaction_reference' ) );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_order_get_rejects_a_foreign_token( ?string $token ): void {
        $response = ( new FD_UCP_Order_Controller() )->get_order( new WP_REST_Request( '/fd-ucp/v1/orders/1001', self::headers( $token ) + array( 'UCP-Agent' => '' ), array( 'id' => 1001 ) ) );

        $this->assertSame( 404, $response->get_status() );
    }

    public function test_order_without_a_stored_token_hash_is_rejected(): void {
        $this->order()->meta = array( '_fd_ucp_handler_id' => 'xyz.fd.prism_payment', '_fd_ucp_tx_reference' => '0xmine' );

        $get  = ( new FD_UCP_Order_Controller() )->get_order( self::request( '/fd-ucp/v1/orders/1001', self::TOKEN, array( 'id' => 1001 ) ) );
        $list = ( new FD_UCP_Order_Controller() )->list_orders( self::request( '/fd-ucp/v1/orders', self::TOKEN ) );

        $this->assertSame( 404, $get->get_status() );
        $this->assertStringNotContainsString( '0xmine', json_encode( $list->get_data() ) );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_returns_reject_a_foreign_token( ?string $token ): void {
        $this->order()->status = 'processing';
        $controller            = new FD_UCP_Returns_Controller();
        $headers               = self::headers( $token ) + array( 'UCP-Agent' => '' );

        $create = $controller->create_return( new WP_REST_Request( '/fd-ucp/v1/orders/1001/returns', $headers, array( 'id' => 1001 ) ) );
        $list   = $controller->list_returns( new WP_REST_Request( '/fd-ucp/v1/orders/1001/returns', $headers, array( 'id' => 1001 ) ) );

        $this->assertSame( 404, $create->get_status() );
        $this->assertSame( 404, $list->get_status() );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertSame( 'processing', $this->order()->status );
    }

    public function test_owner_return_request_records_no_refund(): void {
        $this->order()->status = 'processing';

        $response = ( new FD_UCP_Returns_Controller() )->create_return( self::request( '/fd-ucp/v1/orders/1001/returns', self::TOKEN, array( 'id' => 1001 ) ) );

        $this->assertSame( 202, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'requested', $response->get_data()['return']['status'] );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertSame( 'processing', $this->order()->status );
        $this->assertCount( 1, $this->order()->get_meta( '_fd_ucp_return_requests' ) );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_buyer_identity_rejects_a_foreign_token( ?string $token ): void {
        $controller = new FD_UCP_Buyer_Identity_Controller();
        $route      = '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/buyer';
        $headers    = self::headers( $token ) + array( 'UCP-Agent' => '' );

        $get = $controller->get_buyer( new WP_REST_Request( $route, $headers, array( 'id' => self::SESSION_ID ) ) );
        $put = $controller->update_buyer( new WP_REST_Request( $route, $headers, array( 'id' => self::SESSION_ID ), array( 'email' => 'eve@example.test' ) ) );

        $this->assertSame( 403, $get->get_status() );
        $this->assertSame( 403, $put->get_status() );
        $this->assertSame( array(), $this->db->updates );
    }

    public function test_buyer_identity_with_the_session_token_succeeds(): void {
        $response = ( new FD_UCP_Buyer_Identity_Controller() )->get_buyer( self::request( '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/buyer', self::TOKEN, array( 'id' => self::SESSION_ID ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
    }

    #[DataProvider( 'foreign_tokens' )]
    public function test_promotions_reject_a_foreign_token( ?string $token ): void {
        $response = ( new FD_UCP_Promotions_Controller() )->apply( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/promotions',
            self::headers( $token ) + array( 'UCP-Agent' => '' ),
            array( 'id' => self::SESSION_ID ),
            array( 'code' => 'SAVE10' )
        ) );

        $this->assertSame( 403, $response->get_status() );
    }

    public function test_cart_token_guards_the_cart_and_carries_into_its_checkout_session(): void {
        $carts   = new FD_UCP_Cart_Controller();
        $created = $carts->create_cart( new WP_REST_Request( '/fd-ucp/v1/carts', array( 'UCP-Agent' => '' ), array(), self::one_item() ) );
        $token   = $created->get_headers()['UCP-Session-Token'] ?? '';
        $cart_id = $created->get_data()['id'];
        $route   = '/fd-ucp/v1/carts/' . $cart_id;

        $foreign  = $carts->get_cart( new WP_REST_Request( $route, array( 'UCP-Agent' => '' ), array( 'id' => $cart_id ) ) );
        $checkout = $carts->checkout( self::request( $route . '/checkout', $token, array( 'id' => $cart_id ) ) );

        $this->assertSame( 201, $created->get_status() );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $token );
        $this->assertSame( 403, $foreign->get_status() );
        $this->assertSame( 201, $checkout->get_status(), json_encode( $checkout->get_data() ) );
        $this->assertSame( hash( 'sha256', $token ), $this->db->sessions[ $checkout->get_data()['checkout_session_id'] ]['session_token_hash'] ?? null );
    }

    public function test_owner_sees_the_recorded_return_request(): void {
        $controller = new FD_UCP_Returns_Controller();
        $controller->create_return( self::request( '/fd-ucp/v1/orders/1001/returns', self::TOKEN, array( 'id' => 1001 ) ) );

        $response = $controller->list_returns( self::request( '/fd-ucp/v1/orders/1001/returns', self::TOKEN, array( 'id' => 1001 ) ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( array( 'requested' ), array_column( $response->get_data()['returns'], 'status' ) );
    }

    public function test_every_route_except_the_public_ones_refuses_a_request_without_the_session_token(): void {
        $public = array(
            'POST /catalog/search',
            'POST /catalog/lookup',
            'POST /checkout-sessions',
            'POST /carts',
            'POST /promotions/validate',
        );
        $this->db->carts[ self::SESSION_ID ] = array(
            'id'                 => self::SESSION_ID,
            'line_items'         => json_encode( array() ),
            'session_token_hash' => hash( 'sha256', self::TOKEN ),
            'ucp_version'        => null,
            'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + 3600 ),
        );
        FD_UCP_Plugin::instance()->register_rest_routes();

        $guarded = array();
        foreach ( FD_Test_WP::$routes as $route ) {
            foreach ( (array) explode( ',', $route['methods'] ) as $method ) {
                $name = trim( $method ) . ' ' . $route['route'];
                if ( in_array( $name, $public, true ) ) {
                    continue;
                }
                $id       = str_starts_with( $route['route'], '/orders' ) ? 1001 : self::SESSION_ID;
                $path     = preg_replace( '/\(\?P<id>[^)]+\)/', (string) $id, $route['route'] );
                $response = call_user_func( $route['callback'], new WP_REST_Request(
                    '/fd-ucp/v1' . $path,
                    array( 'UCP-Agent' => '' ),
                    array( 'id' => $id ),
                    array( 'code' => 'SAVE10', 'items' => array(), 'buyer' => array( 'first_name' => 'Eve' ) )
                ) );
                $this->assertContains( $response->get_status(), array( 401, 403, 404 ), $name );
                $guarded[] = $name;
            }
        }

        $this->assertCount( 15, $guarded, implode( ', ', $guarded ) );
        $this->assertSame( array(), $this->db->updates );
        $this->assertSame( array(), FD_Test_Order_Store::$refunds );
        $this->assertSame( array(), $this->handler->settled );
        $this->assertArrayHasKey( self::SESSION_ID, $this->db->carts );
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
            self::headers( self::TOKEN ),
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
        return array( $carts, (string) ( $created->get_headers()['UCP-Session-Token'] ?? '' ), (string) $created->get_data()['id'] );
    }

    private function cart_request( string $token, string $cart_id, array $body = array() ): WP_REST_Request {
        return new WP_REST_Request( '/fd-ucp/v1/carts/' . $cart_id, self::headers( $token ), array( 'id' => $cart_id ), $body );
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
        [ $carts, $token, $cart_id ] = $this->new_cart();
        $this->db->carts[ $cart_id ]['expires_at'] = $expires_at;
        unset( $this->db->sessions[ $cart_id ] );

        $response = $carts->$operation( $this->cart_request( $token, $cart_id, self::one_item() ) );

        $this->assertSame( 404, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertArrayNotHasKey( $cart_id, $this->db->sessions );
    }

    public function test_cart_checkout_reprices_the_stored_items_at_the_current_price(): void {
        [ $carts, $token, $cart_id ] = $this->new_cart();
        unset( $this->db->sessions[ $cart_id ] );
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->checkout( $this->cart_request( $token, $cart_id ) );

        $this->assertSame( 201, $response->get_status(), json_encode( $response->get_data() ) );
        $session_id = $response->get_data()['checkout_session_id'];
        $items      = json_decode( $this->db->sessions[ $session_id ]['line_items'], true );
        $this->assertSame( 2500, $items[0]['item']['price'] );
        $this->assertSame( array( 'subtotal' => 5000, 'total' => 5000 ), $this->stored_totals( $session_id ) );
        $this->assertSame( 2500, $response->get_data()['line_items'][0]['item']['price'] );
        $this->assertSame( 5000, array_column( $response->get_data()['totals'], 'amount', 'type' )['total'] );
    }

    public function test_cart_read_shows_the_current_price(): void {
        [ $carts, $token, $cart_id ] = $this->new_cart();
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->get_cart( $this->cart_request( $token, $cart_id ) );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 2500, $response->get_data()['line_items'][0]['item']['price'] );
    }

    public function test_cart_update_prices_the_items_at_the_current_price(): void {
        [ $carts, $token, $cart_id ] = $this->new_cart();
        FD_Test_Product_Store::$products[101] = new FD_Test_Product( 101, '25.00' );

        $response = $carts->update_cart( $this->cart_request( $token, $cart_id, self::one_item() ) );

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
        [ $carts, $token, $cart_id ] = $this->new_cart();
        unset( $this->db->sessions[ $cart_id ] );
        $this->make_cart_unavailable( $replacement );

        $response = $carts->checkout( $this->cart_request( $token, $cart_id ) );

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

    private static function one_item(): array {
        return array( 'line_items' => array( array( 'item' => array( 'id' => '101' ), 'quantity' => 1 ) ) );
    }
}
