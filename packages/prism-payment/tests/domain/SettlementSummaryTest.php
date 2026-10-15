<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class SettlementSummaryTest extends TestCase {

    private static function contract( array $overrides = array() ): array {
        return array_merge(
            array(
                'transaction' => '0xAbC',
                'network'     => 'eip155:84532',
                'asset'       => 'USDC',
                'status'      => 'confirmed',
                'gross'       => '100.00',
                'fee'         => '1.50',
                'cashback'    => '5.00',
                'net'         => '93.50',
            ),
            $overrides
        );
    }

    private static function order( array $meta = array( '_fd_prism_tx_hash' => '0xAbC' ) ): WC_Order {
        $order       = new WC_Order();
        $order->meta = $meta;
        return $order;
    }

    private static function summary( ?array $response ): array {
        $client = new class( $response ) extends FD_Prism_Client {
            public int $calls = 0;
            public array $hashes = array();
            private ?array $response;

            public function __construct( ?array $response ) {
                parent::__construct( 'https://gateway.test', 'key' );
                $this->response = $response;
            }

            public function get_settlement_summary( string $tx_hash ): ?array {
                $this->calls++;
                $this->hashes[] = $tx_hash;
                return $this->response;
            }
        };
        return array( new FD_Prism_Settlement_Summary( $client ), $client );
    }

    public function test_confirmed_summary_is_returned_and_stored(): void {
        [ $summary, $client ] = self::summary( self::contract() );
        $order                = self::order();

        $result = $summary->for_order( $order );

        $this->assertSame( '93.50', $result['net'] );
        $this->assertSame( '5.00', $result['cashback'] );
        $this->assertSame( '1.50', $result['fee'] );
        $this->assertSame( 'USDC', $result['asset'] );
        $this->assertSame( '93.50', $order->meta['_fd_prism_net_amount'] );
        $this->assertSame( '5.00', $order->meta['_fd_prism_cashback_amount'] );
        $this->assertSame( '1.50', $order->meta['_fd_prism_fee_amount'] );
        $this->assertSame( 'USDC', $order->meta['_fd_prism_settlement_asset'] );
        $this->assertSame( 1, $client->calls );
    }

    public function test_tx_hash_is_sent_exactly_as_stored(): void {
        [ $summary, $client ] = self::summary( self::contract() );

        $summary->for_order( self::order() );

        $this->assertSame( array( '0xAbC' ), $client->hashes );
    }

    public function test_unreachable_prism_returns_null_and_stores_nothing(): void {
        [ $summary ] = self::summary( null );
        $order       = self::order();

        $this->assertNull( $summary->for_order( $order ) );
        $this->assertArrayNotHasKey( '_fd_prism_net_amount', $order->meta );
    }

    public function test_unconfirmed_payment_returns_null_and_stores_nothing(): void {
        foreach ( array( 'broadcasted', 'rejected', 'pending', '' ) as $status ) {
            [ $summary ] = self::summary( self::contract( array( 'status' => $status ) ) );
            $order       = self::order();

            $this->assertNull( $summary->for_order( $order ), "status '$status'" );
            $this->assertArrayNotHasKey( '_fd_prism_net_amount', $order->meta, "status '$status'" );
        }
    }

    public function test_missing_status_returns_null_and_stores_nothing(): void {
        $response = self::contract();
        unset( $response['status'] );
        [ $summary ] = self::summary( $response );
        $order       = self::order();

        $this->assertNull( $summary->for_order( $order ) );
        $this->assertArrayNotHasKey( '_fd_prism_net_amount', $order->meta );
    }

    public function test_missing_field_returns_null_and_stores_nothing(): void {
        foreach ( array( 'net', 'cashback', 'fee', 'asset' ) as $field ) {
            $response = self::contract();
            unset( $response[ $field ] );
            [ $summary ] = self::summary( $response );
            $order       = self::order();

            $this->assertNull( $summary->for_order( $order ), "missing $field" );
            $this->assertArrayNotHasKey( '_fd_prism_net_amount', $order->meta, "missing $field" );
        }
    }

    public function test_order_without_tx_hash_never_calls_prism(): void {
        [ $summary, $client ] = self::summary( self::contract() );

        $this->assertNull( $summary->for_order( self::order( array() ) ) );
        $this->assertSame( 0, $client->calls );
    }

    public function test_stored_meta_skips_the_prism_call(): void {
        [ $summary, $client ] = self::summary( self::contract() );
        $order                = self::order( array(
            '_fd_prism_tx_hash'          => '0xAbC',
            '_fd_prism_net_amount'       => '90.00',
            '_fd_prism_cashback_amount'  => '8.00',
            '_fd_prism_fee_amount'       => '2.00',
            '_fd_prism_settlement_asset' => 'USDC',
        ) );

        $result = $summary->for_order( $order );

        $this->assertSame( '90.00', $result['net'] );
        $this->assertSame( 0, $client->calls );
    }

    public function test_refund_note_states_the_refundable_amount(): void {
        $note = FD_Prism_Settlement_Summary::refund_note( array(
            'net'      => '93.50',
            'cashback' => '5.00',
            'fee'      => '1.50',
            'asset'    => 'USDC',
        ) );

        $this->assertStringContainsString( 'Refund up to 93.50 USDC', $note );
        $this->assertStringContainsString( '5.00 USDC cashback', $note );
        $this->assertStringContainsString( '1.50 USDC fees', $note );
    }

    public function test_refund_note_without_summary_says_unavailable(): void {
        $note = FD_Prism_Settlement_Summary::refund_note( null );

        $this->assertStringContainsString( 'unavailable', $note );
        $this->assertStringNotContainsString( 'Refund up to', $note );
    }

    public function test_return_created_adds_exactly_one_note(): void {
        [ $summary ] = self::summary( self::contract() );
        $order       = self::order();

        $summary->on_return_created( $order );

        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( 'Refund up to 93.50 USDC', $order->notes[0] );
    }

    public function test_return_created_notes_unavailable_when_prism_fails(): void {
        [ $summary ] = self::summary( null );
        $order       = self::order();

        $summary->on_return_created( $order );

        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( 'unavailable', $order->notes[0] );
    }

    public function test_return_created_ignores_non_prism_orders(): void {
        [ $summary, $client ] = self::summary( self::contract() );
        $order                = self::order( array() );

        $summary->on_return_created( $order );

        $this->assertSame( array(), $order->notes );
        $this->assertSame( 0, $client->calls );
    }
}
