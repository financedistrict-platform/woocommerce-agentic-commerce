<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WireVersionSnapshotTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function snapshots(): array {
        $cases = array();
        foreach ( array( '2026-08-25', '2026-01-23' ) as $version ) {
            foreach ( FD_Test_Golden_Renderer::names( $version ) as $name ) {
                $cases[ "$version/$name" ] = array( $version, $name );
            }
        }
        return $cases;
    }

    #[DataProvider( 'snapshots' )]
    public function test_version_output_matches_snapshot( string $version, string $name ): void {
        $path     = FD_Test_Golden_Renderer::fixtures() . '/ucp/' . $version . '/' . $name . '.json';
        $rendered = FD_Test_Golden_Renderer::render( $version, new FD_Payment_Registry() )[ $name ];

        if ( getenv( 'FD_UPDATE_SNAPSHOTS' ) ) {
            if ( ! is_dir( dirname( $path ) ) ) {
                mkdir( dirname( $path ), 0777, true );
            }
            file_put_contents( $path, $rendered );
        }

        $this->assertFileExists( $path );
        $this->assertSame( file_get_contents( $path ), $rendered );
    }

    public function test_2026_01_23_has_no_cart_or_catalog(): void {
        $profile = json_decode( FD_Test_Golden_Renderer::render( '2026-01-23', new FD_Payment_Registry() )['profile'], true );

        foreach ( array_keys( $profile['ucp']['capabilities'] ) as $name ) {
            $this->assertStringNotContainsString( 'cart', $name );
            $this->assertStringNotContainsString( 'catalog', $name );
        }
        $this->assertArrayNotHasKey( 'supported_versions', $profile['ucp'] );
        $this->assertArrayHasKey( 'signing_keys', $profile );
    }

    public function test_2026_01_23_drops_available_instruments_from_handler_entries(): void {
        $registry = FD_Test_Golden_Renderer::prism_registry( 'current-handlers-2026-04-08.json' );
        $json     = FD_Test_Golden_Renderer::render( '2026-01-23', $registry, array( '2026-04-08' ) )['profile'];
        $profile  = json_decode( $json, true );

        $entry = $profile['ucp']['payment_handlers']['xyz.fd.prism_payment'][0];
        $this->assertArrayNotHasKey( 'available_instruments', $entry );
        $this->assertStringContainsString( '"config": {}', $json );
    }

    public function test_2026_08_25_renders_empty_handler_config_as_object(): void {
        $registry = FD_Test_Golden_Renderer::prism_registry( 'current-handlers-2026-04-08.json' );
        $json     = FD_Test_Golden_Renderer::render( '2026-08-25', $registry )['profile'];

        $this->assertStringContainsString( '"config": {}', $json );
        $this->assertStringContainsString( '"available_instruments"', $json );
    }

    public function test_2026_04_08_keeps_the_original_empty_handler_config(): void {
        $registry = FD_Test_Golden_Renderer::prism_registry( 'current-handlers-2026-04-08.json' );
        $json     = FD_Test_Golden_Renderer::render( '2026-04-08', $registry )['profile'];

        $this->assertStringContainsString( '"config": []', $json );
    }
}
