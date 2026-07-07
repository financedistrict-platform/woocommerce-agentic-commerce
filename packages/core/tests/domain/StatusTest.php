<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

    private function make_session( array $overrides = array() ): array {
        return array_merge( array(
            'status'      => 'incomplete',
            'line_items'  => json_encode( array( array( 'id' => 'li_1' ) ) ),
            'buyer'       => json_encode( array( 'email' => 'test@example.com' ) ),
            'fulfillment' => json_encode( array(
                'methods' => array( array(
                    'type'         => 'shipping',
                    'destinations' => array( array(
                        'address_country' => 'US',
                        'postal_code'     => '94105',
                    ) ),
                    'groups'       => array( array(
                        'options'            => array( array( 'id' => 'flat_rate1' ) ),
                        'selected_option_id' => 'flat_rate1',
                    ) ),
                ) ),
            ) ),
        ), $overrides );
    }

    // ── resolve() ────────────────────────────────────────────

    public function test_canceled_session_returns_canceled(): void {
        $session = $this->make_session( array( 'status' => 'canceled' ) );
        $this->assertSame( 'canceled', FD_UCP_Status::resolve( $session ) );
    }

    public function test_completed_session_returns_completed(): void {
        $session = $this->make_session( array( 'status' => 'completed' ) );
        $this->assertSame( 'completed', FD_UCP_Status::resolve( $session ) );
    }

    public function test_full_session_returns_ready_for_complete(): void {
        $session = $this->make_session();
        $this->assertSame( 'ready_for_complete', FD_UCP_Status::resolve( $session ) );
    }

    public function test_missing_email_returns_incomplete(): void {
        $session = $this->make_session( array( 'buyer' => json_encode( array() ) ) );
        $this->assertSame( 'incomplete', FD_UCP_Status::resolve( $session ) );
    }

    public function test_missing_address_returns_incomplete(): void {
        $session = $this->make_session( array( 'fulfillment' => json_encode( array() ) ) );
        $this->assertSame( 'incomplete', FD_UCP_Status::resolve( $session ) );
    }

    public function test_missing_line_items_returns_incomplete(): void {
        $session = $this->make_session( array( 'line_items' => json_encode( array() ) ) );
        $this->assertSame( 'incomplete', FD_UCP_Status::resolve( $session ) );
    }

    // ── missing_requirements() ───────────────────────────────

    public function test_complete_session_has_no_missing(): void {
        $missing = FD_UCP_Status::missing_requirements( $this->make_session() );
        $this->assertEmpty( $missing );
    }

    public function test_empty_session_missing_everything(): void {
        $session = array(
            'status'      => 'incomplete',
            'line_items'  => '[]',
            'buyer'       => '{}',
            'fulfillment' => '{}',
        );
        $missing = FD_UCP_Status::missing_requirements( $session );

        $this->assertContains( 'items', $missing );
        $this->assertContains( 'email', $missing );
        $this->assertContains( 'shipping_address', $missing );
    }

    public function test_unselected_shipping_option_is_flagged(): void {
        $session = $this->make_session( array(
            'fulfillment' => json_encode( array(
                'methods' => array( array(
                    'type'         => 'shipping',
                    'destinations' => array( array( 'address_country' => 'US' ) ),
                    'groups'       => array( array(
                        'options'            => array( array( 'id' => 'flat_rate1' ) ),
                        'selected_option_id' => '',
                    ) ),
                ) ),
            ) ),
        ) );
        $missing = FD_UCP_Status::missing_requirements( $session );
        $this->assertContains( 'selected_fulfillment_option', $missing );
    }

    // ── missing_messages() ───────────────────────────────────

    public function test_missing_messages_returns_error_per_field(): void {
        $messages = FD_UCP_Status::missing_messages( array( 'email', 'shipping_address' ) );

        $this->assertCount( 2, $messages );
        $this->assertSame( 'missing_email', $messages[0]['code'] );
        $this->assertSame( 'error', $messages[0]['type'] );
        $this->assertSame( 'missing_shipping_address', $messages[1]['code'] );
    }

    public function test_empty_missing_returns_no_messages(): void {
        $this->assertEmpty( FD_UCP_Status::missing_messages( array() ) );
    }
}
