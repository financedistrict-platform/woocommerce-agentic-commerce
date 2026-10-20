<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatterProfileTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    private function profile(): array {
        return ( new FD_UCP_Version_Registry() )->wire( '2026-08-25' )->profile( 'https://store.test/wp-json/fd-ucp/v1', new FD_Payment_Registry(), array() );
    }

    public function test_default_profile_declares_ucp_version(): void {
        $profile = FD_UCP_Formatter::format_profile( 'https://store.test/wp-json/fd-ucp/v1', new FD_Payment_Registry() );
        $this->assertSame( FD_UCP_Version_Registry::LATEST, $profile['ucp']['version'] );
    }

    public function test_profile_declares_ucp_version(): void {
        $this->assertSame( '2026-08-25', $this->profile()['ucp']['version'] );
    }

    public function test_profile_top_level_keys_are_exact(): void {
        $this->assertSame( array( 'ucp', 'name' ), array_keys( $this->profile() ) );
    }

    public function test_profile_capabilities_are_exact(): void {
        $expected = array_map(
            static fn( string $name ): string => 'dev.ucp.shopping.' . $name,
            array( 'cart', 'catalog.search', 'catalog.lookup', 'checkout', 'fulfillment', 'order' )
        );
        $this->assertSame( $expected, array_keys( $this->profile()['ucp']['capabilities'] ) );
    }

    public function test_every_capability_has_versioned_spec_and_schema(): void {
        foreach ( $this->profile()['ucp']['capabilities'] as $name => $entries ) {
            foreach ( $entries as $entry ) {
                $this->assertSame( '2026-08-25', $entry['version'], $name );
                $this->assertStringStartsWith( 'https://ucp.dev/2026-08-25/specification/', $entry['spec'], $name );
                $this->assertStringStartsWith( 'https://ucp.dev/2026-08-25/schemas/shopping/', $entry['schema'], $name );
            }
        }
    }

    public function test_empty_payment_handlers_render_as_object(): void {
        $this->assertSame( '{}', json_encode( $this->profile()['ucp']['payment_handlers'] ) );
    }

    public static function currency_amounts(): array {
        return array(
            'zero decimals'  => array( 'JPY', 1500.0, 1500, '1500' ),
            'three decimals' => array( 'KWD', 1.234, 1234, '1.234' ),
            'two decimals'   => array( 'USD', 12.34, 1234, '12.34' ),
            'lowercase code' => array( 'jpy', 1500.0, 1500, '1500' ),
            'unknown code'   => array( 'XYZ', 12.34, 1234, '12.34' ),
        );
    }

    #[DataProvider( 'currency_amounts' )]
    public function test_minor_units_follow_the_currency_exponent( string $currency, float $major, int $minor, string $major_string ): void {
        $this->assertSame( $minor, FD_UCP_Currency::to_minor( $major, $currency ) );
        $this->assertSame( $major_string, FD_UCP_Currency::to_major_string( $minor, $currency ) );
        $this->assertEqualsWithDelta( $major, FD_UCP_Currency::to_major( $minor, $currency ), 0.0000001 );
    }

    public function test_order_lines_use_the_order_currency_exponent(): void {
        $order           = new WC_Order();
        $order->currency = 'JPY';
        $order->total    = '1500';

        $this->assertSame( 1500, FD_UCP_Checkout_Pricing::order_total_minor( $order ) );
    }

    public function test_payable_bound_uses_the_currency_exponent(): void {
        $yen           = new WC_Order();
        $yen->currency = 'JPY';
        $yen->total    = (string) FD_UCP_Checkout_Pricing::MAX_AMOUNT_MINOR;
        $euro           = new WC_Order();
        $euro->currency = 'EUR';
        $euro->total    = (string) ( FD_UCP_Checkout_Pricing::MAX_AMOUNT_MINOR / 100 );
        $over           = new WC_Order();
        $over->currency = 'EUR';
        $over->total    = (string) ( FD_UCP_Checkout_Pricing::MAX_AMOUNT_MINOR / 100 + 1 );

        $this->assertSame( FD_UCP_Checkout_Pricing::MAX_AMOUNT_MINOR, FD_UCP_Checkout_Pricing::order_total_minor( $yen ) );
        $this->assertSame( FD_UCP_Checkout_Pricing::MAX_AMOUNT_MINOR, FD_UCP_Checkout_Pricing::order_total_minor( $euro ) );
        $this->assertNull( FD_UCP_Checkout_Pricing::order_total_minor( $over ) );
    }
}
