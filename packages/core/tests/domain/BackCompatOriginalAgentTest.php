<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackCompatOriginalAgentTest extends TestCase {

    private const SESSION_ID = '5f0c2a8e-3b1d-4c6e-9a7f-2d4b8e1c0a91';
    private const TOKEN      = 'c0ffee11c0ffee22c0ffee33c0ffee44c0ffee55c0ffee66c0ffee77c0ffee88';

    private FD_Test_Wpdb $db;
    private WC_Order $order;
    private object $handler;

    protected function setUp(): void {
        FD_Test_WP::reset();

        $session                       = FD_Test_Golden_Renderer::input( 'checkout-session.json' );
        $session['session_token_hash'] = hash( 'sha256', self::TOKEN );
        $session['ucp_version']        = null;
        $session['expires_at']         = gmdate( 'Y-m-d H:i:s', time() + 3600 );
        $session['payment_meta']       = json_encode( array( 'xyz.fd.prism_payment' => array( 'prepared_amount' => 4695 ) ) );

        $this->db                         = new FD_Test_Wpdb();
        $this->db->sessions[ self::SESSION_ID ] = $session;
        $GLOBALS['wpdb']                  = $this->db;

        FD_Test_WP::$options['fd_ucp_db_version'] = '1.1.0';
        FD_UCP_Installer::maybe_upgrade();

        $this->order                      = new WC_Order();
        FD_Test_Order_Store::$orders      = array( 1001 => $this->order );

        $this->handler = new class() extends FD_Prism_Handler {
            public array $settled = array();

            public function __construct() {
                parent::__construct( 'https://gw.example', 'test-key' );
            }

            public function settle_payment( array $input ): array {
                $this->settled[] = $input;
                return array( 'success' => true, 'transaction_reference' => '0x' . str_repeat( 'cd', 32 ), 'network' => 'eip155:84532', 'settled_amount' => 4695 );
            }
        };
    }

    private function complete( array $instrument ): WP_REST_Response {
        $registry = new FD_Payment_Registry();
        $registry->register( $this->handler );
        $controller = new FD_UCP_Checkout_Controller( $registry );

        return $controller->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            array( 'UCP-Session-Token' => self::TOKEN ),
            array( 'id' => self::SESSION_ID ),
            array( 'payment' => array( 'instruments' => array( $instrument ) ) )
        ) );
    }

    public static function original_instruments(): array {
        $authorization = array( 'x402Version' => 2, 'paymentPayload' => array( 'network' => 'eip155:84532' ) );
        return array(
            'tokenized without id or credential type' => array( array(
                'handler_id' => 'xyz.fd.prism_payment',
                'type'       => 'tokenized',
                'credential' => $authorization,
            ) ),
            'x402 handler id without type'            => array( array(
                'handler_id' => 'x402',
                'credential' => $authorization,
            ) ),
            'default type with base64 credential'     => array( array(
                'handler_id' => 'x402',
                'type'       => 'default',
                'credential' => base64_encode( json_encode( $authorization ) ),
            ) ),
            'current x402 instrument'                 => array( array(
                'id'         => 'inst_1',
                'handler_id' => 'xyz.fd.prism_payment',
                'type'       => 'x402',
                'credential' => array( 'type' => 'x402' ) + $authorization,
            ) ),
        );
    }

    #[DataProvider( 'original_instruments' )]
    public function test_original_era_instrument_completes_with_the_canonical_handler( array $instrument ): void {
        $response = $this->complete( $instrument );

        $this->assertSame( 200, $response->get_status(), json_encode( $response->get_data() ) );
        $this->assertSame( 'completed', $response->get_data()['status'] );
        $this->assertCount( 1, $this->handler->settled );
        $this->assertSame( 'xyz.fd.prism_payment', $this->handler->settled[0]['handler_id'] );
        $this->assertSame( $instrument['credential'], $this->handler->settled[0]['credential'] );
        $this->assertSame( 'xyz.fd.prism_payment', $this->order->get_meta( '_fd_ucp_handler_id' ) );
        $this->assertSame( FD_UCP_Version_Registry::LATEST, $response->get_data()['ucp']['version'] );
    }

    public function test_throwing_handler_releases_the_session(): void {
        $registry = new FD_Payment_Registry();
        $registry->register( new class() extends FD_Prism_Handler {
            public function __construct() {
                parent::__construct( 'https://gw.example', 'test-key' );
            }

            public function settle_payment( array $input ): array {
                throw new RuntimeException( 'boom' );
            }
        } );

        $response = ( new FD_UCP_Checkout_Controller( $registry ) )->complete_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID . '/complete',
            array( 'UCP-Session-Token' => self::TOKEN ),
            array( 'id' => self::SESSION_ID ),
            array( 'payment' => array( 'instruments' => array( array( 'handler_id' => 'x402', 'credential' => array( 'a' => 1 ) ) ) ) )
        ) );

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( 'incomplete', $this->db->sessions[ self::SESSION_ID ]['status'] );
    }

    public function test_unknown_handler_is_rejected_before_settle(): void {
        $response = $this->complete( array( 'handler_id' => 'other', 'credential' => array( 'x' => 1 ) ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'invalid_instrument', $response->get_data()['messages'][0]['code'] );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_foreign_instrument_type_is_rejected_before_settle(): void {
        $response = $this->complete( array( 'handler_id' => 'xyz.fd.prism_payment', 'type' => 'card', 'credential' => array( 'x' => 1 ) ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( array(), $this->handler->settled );
    }

    public function test_missing_credential_keeps_the_original_error(): void {
        $response = $this->complete( array( 'handler_id' => 'xyz.fd.prism_payment' ) );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'handler_id and credential are required', $response->get_data()['messages'][0]['content'] );
    }

    public function test_session_pinned_to_another_version_rejects_a_matched_different_agent(): void {
        $this->db->sessions[ self::SESSION_ID ]['ucp_version'] = '2026-08-25';
        $plugin   = FD_UCP_Plugin::instance();
        $resolver = new ReflectionProperty( FD_UCP_Plugin::class, 'resolver' );
        $resolver->setValue( $plugin, new FD_UCP_Version_Resolver(
            new FD_UCP_Version_Registry(),
            new FD_Test_Fixture_Profile_Fetcher( array( 'https://agent.example/p' => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ) ) )
        ) );

        $registry = new FD_Payment_Registry();
        $registry->register( $this->handler );
        $response = ( new FD_UCP_Checkout_Controller( $registry ) )->get_session( new WP_REST_Request(
            '/fd-ucp/v1/checkout-sessions/' . self::SESSION_ID,
            array( 'UCP-Agent' => 'profile="https://agent.example/p"', 'UCP-Session-Token' => self::TOKEN ),
            array( 'id' => self::SESSION_ID )
        ) );
        $resolver->setValue( $plugin, null );

        $this->assertSame( 422, $response->get_status() );
        $this->assertSame( 'version_unsupported', $response->get_data()['messages'][0]['code'] );
    }
}
