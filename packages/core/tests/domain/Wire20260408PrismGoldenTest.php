<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Wire20260408PrismGoldenTest extends TestCase {

    private const VERSION     = '2026-04-08';
    private const NAMESPACE   = 'xyz.fd.prism_payment';
    private const PRISM_OWNED = array( 'id', 'version', 'spec', 'schema', 'config_schema', 'instrument_schemas', 'available_instruments' );
    private const PLUGIN_OWNED = array( 'name', 'config' );

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function lanes(): array {
        $lanes = array();
        foreach ( array( 'current-handlers-2026-04-08.json', 'legacy-handlers.json' ) as $recorded ) {
            foreach ( array( '2026-04-08-prism', '2026-04-08-prism-nsid' ) as $golden ) {
                foreach ( FD_Test_Golden_Renderer::names( self::VERSION ) as $name ) {
                    $lanes[ "$recorded vs $golden/$name" ] = array( $recorded, $golden, $name );
                }
            }
        }
        return $lanes;
    }

    #[DataProvider( 'lanes' )]
    public function test_upgraded_plugin_matches_original_release_under_the_diff_policy( string $recorded, string $golden, string $name ): void {
        $rendered = FD_Test_Golden_Renderer::render( self::VERSION, FD_Test_Golden_Renderer::prism_registry( $recorded ) )[ $name ];
        $original = file_get_contents( FD_Test_Golden_Renderer::fixtures() . '/ucp/' . $golden . '/' . $name . '.json' );

        $this->assertSame( $this->without( $original, self::PRISM_OWNED ), $this->without( $rendered, self::PRISM_OWNED ) );
        $this->assertSame( $this->only( $original, self::PLUGIN_OWNED ), $this->only( $rendered, self::PLUGIN_OWNED ) );
        $this->assertSame( $this->outside_entries( $original ), $this->outside_entries( $rendered ) );
    }

    public function test_profile_keeps_the_prism_handler_for_both_recorded_shapes(): void {
        foreach ( array( 'current-handlers-2026-04-08.json', 'legacy-handlers.json' ) as $recorded ) {
            FD_Test_WP::reset();
            $profile = json_decode( FD_Test_Golden_Renderer::render( self::VERSION, FD_Test_Golden_Renderer::prism_registry( $recorded ) )['profile'], true );
            $entry   = $profile['ucp']['payment_handlers'][ self::NAMESPACE ][0];

            $this->assertSame( self::NAMESPACE, $entry['id'], $recorded );
            $this->assertIsString( $entry['schema'], $recorded );
            $this->assertSame( self::NAMESPACE, $entry['name'], $recorded );
        }
    }

    private function entries( $document ): array {
        return $document->ucp->payment_handlers->{self::NAMESPACE} ?? array();
    }

    private function without( string $json, array $keys ): string {
        $document = json_decode( $json );
        foreach ( $this->entries( $document ) as $entry ) {
            foreach ( $keys as $key ) {
                unset( $entry->$key );
            }
        }
        return json_encode( $document, FD_Test_Golden_Renderer::FLAGS );
    }

    private function only( string $json, array $keys ): string {
        $kept = array();
        foreach ( $this->entries( json_decode( $json ) ) as $entry ) {
            $fields = array();
            foreach ( $keys as $key ) {
                if ( property_exists( $entry, $key ) ) {
                    $fields[ $key ] = $entry->$key;
                }
            }
            $kept[] = $fields;
        }
        return json_encode( $kept, FD_Test_Golden_Renderer::FLAGS );
    }

    private function outside_entries( string $json ): string {
        $document = json_decode( $json );
        if ( isset( $document->ucp->payment_handlers->{self::NAMESPACE} ) ) {
            $document->ucp->payment_handlers->{self::NAMESPACE} = null;
        }
        return json_encode( $document, FD_Test_Golden_Renderer::FLAGS );
    }
}
