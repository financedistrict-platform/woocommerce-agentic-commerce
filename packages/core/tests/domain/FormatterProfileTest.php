<?php
declare( strict_types=1 );

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
        $this->assertSame( '2026-04-08', $profile['ucp']['version'] );
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
}
