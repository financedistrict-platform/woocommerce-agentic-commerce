<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Wire20260408GoldenTest extends TestCase {

    private const VERSION = '2026-04-08';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function documents(): array {
        return array_map( static fn( string $name ): array => array( $name ), array_combine(
            FD_Test_Golden_Renderer::names( self::VERSION ),
            FD_Test_Golden_Renderer::names( self::VERSION )
        ) );
    }

    #[DataProvider( 'documents' )]
    public function test_empty_registry_output_is_byte_equal_to_original_release( string $name ): void {
        $rendered = FD_Test_Golden_Renderer::render( self::VERSION, new FD_Payment_Registry() );

        $this->assertSame(
            file_get_contents( FD_Test_Golden_Renderer::fixtures() . '/ucp/' . self::VERSION . '/' . $name . '.json' ),
            $rendered[ $name ]
        );
    }

    public function test_default_supported_versions_only_add_the_supported_versions_key(): void {
        $original = json_decode( FD_Test_Golden_Renderer::render( self::VERSION, new FD_Payment_Registry() )['profile'], true );
        $default  = json_decode( FD_Test_Golden_Renderer::render( self::VERSION, new FD_Payment_Registry(), FD_UCP_Version_Registry::DEFAULT_SUPPORTED )['profile'], true );

        $this->assertSame(
            array(
                '2026-08-25' => 'https://store.test/.well-known/ucp/2026-08-25',
                '2026-01-23' => 'https://store.test/.well-known/ucp/2026-01-23',
            ),
            $default['ucp']['supported_versions']
        );
        unset( $default['ucp']['supported_versions'] );
        $this->assertSame( json_encode( $original ), json_encode( $default ) );
    }

    public function test_default_supported_versions_leave_other_documents_unchanged(): void {
        $original = FD_Test_Golden_Renderer::render( self::VERSION, new FD_Payment_Registry() );
        $default  = FD_Test_Golden_Renderer::render( self::VERSION, new FD_Payment_Registry(), FD_UCP_Version_Registry::DEFAULT_SUPPORTED );

        unset( $original['profile'], $default['profile'] );
        $this->assertSame( $original, $default );
    }

    public function test_leaf_profile_never_contains_supported_versions(): void {
        foreach ( FD_UCP_Version_Registry::known() as $version ) {
            $result = FD_UCP_Discovery::render( new FD_UCP_Version_Registry(), $version, new FD_Payment_Registry() );
            $this->assertSame( 200, $result['status'], $version );
            $this->assertArrayNotHasKey( 'supported_versions', $result['body']['ucp'], $version );
            $this->assertSame( $version, $result['body']['ucp']['version'] );
        }
    }

    public function test_root_profile_lists_enabled_versions_with_leaf_urls(): void {
        $result = FD_UCP_Discovery::render( new FD_UCP_Version_Registry(), '', new FD_Payment_Registry() );

        $this->assertSame( self::VERSION, $result['body']['ucp']['version'] );
        $this->assertSame( array( '2026-08-25', '2026-01-23' ), array_keys( $result['body']['ucp']['supported_versions'] ) );
    }

    public function test_leaf_profile_of_disabled_version_is_not_found(): void {
        $result = FD_UCP_Discovery::render( new FD_UCP_Version_Registry( self::VERSION, array() ), '2026-08-25', new FD_Payment_Registry() );

        $this->assertSame( 404, $result['status'] );
        $this->assertSame( 'version_unsupported', $result['body']['messages'][0]['code'] );
    }
}
