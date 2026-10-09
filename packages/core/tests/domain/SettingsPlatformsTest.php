<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class SettingsPlatformsTest extends TestCase {

    private const PROFILE = 'https://platform.example/.well-known/ucp';

    protected function setUp(): void {
        FD_Test_WP::reset();
        $_POST = array();
    }

    protected function tearDown(): void {
        $_POST = array();
        FD_Test_Platform_Vectors::reset_server();
    }

    private function save( array $post ): void {
        $_POST = $post;
        FD_UCP_Settings::save_platforms();
    }

    private function issue( string $profile = self::PROFILE, string $label = 'Demo' ): string {
        $this->save( array( 'fd_ucp_platform_new_profile' => $profile, 'fd_ucp_platform_new_label' => $label ) );
        preg_match( '/: ([0-9a-f]{64})\./', end( WC_Admin_Settings::$messages ), $m );
        return $m[1];
    }

    public function test_stored_option_holds_the_hash_and_never_the_raw_key(): void {
        $key = $this->issue();

        $stored = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ];
        $this->assertCount( 1, $stored );
        $this->assertSame( hash( 'sha256', $key ), $stored[0]['key_hash'] );
        $this->assertSame( self::PROFILE, $stored[0]['profile'] );
        $this->assertSame( 'Demo', $stored[0]['label'] );
        $this->assertTrue( $stored[0]['enabled'] );
        $this->assertStringNotContainsString( $key, serialize( FD_Test_WP::$options ) );
        $this->assertStringNotContainsString( $key, json_encode( FD_Test_WP::$logs ) );
    }

    public function test_raw_key_is_shown_once_and_is_64_hex_characters(): void {
        $key = $this->issue();

        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $key );
        $this->assertCount( 1, WC_Admin_Settings::$messages );

        WC_Admin_Settings::reset();
        $this->save( array() );
        $this->assertSame( array(), WC_Admin_Settings::$messages );
    }

    public function test_issued_key_authenticates_the_platform(): void {
        $key = $this->issue();

        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'x-api-key' => $key ) );

        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );
    }

    public function test_every_key_is_distinct_and_the_profile_is_normalised(): void {
        $first  = $this->issue( 'HTTPS://Platform.example/.well-known/ucp/' );
        $second = $this->issue();

        $this->assertNotSame( $first, $second );
        $stored = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ];
        $this->assertCount( 2, $stored );
        $this->assertSame( self::PROFILE, $stored[0]['profile'] );
    }

    public function test_invalid_profile_urls_are_refused_without_issuing_a_key(): void {
        foreach ( array( 'http://platform.example/ucp', 'not a url', 'https://u:p@platform.example/ucp' ) as $url ) {
            WC_Admin_Settings::reset();
            $this->save( array( 'fd_ucp_platform_new_profile' => $url ) );

            $this->assertSame( array(), WC_Admin_Settings::$messages, $url );
            $this->assertCount( 1, WC_Admin_Settings::$errors, $url );
            $this->assertSame( array(), FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ], $url );
        }
    }

    public function test_unchecked_platforms_are_disabled_and_deleted_ones_removed(): void {
        $first_key  = $this->issue( 'https://one.example/ucp' );
        $second_key = $this->issue( 'https://two.example/ucp' );
        $third_key  = $this->issue( 'https://three.example/ucp' );

        $this->save( array(
            'fd_ucp_platform_enabled' => array( hash( 'sha256', $first_key ) => '1' ),
            'fd_ucp_platform_delete'  => array( hash( 'sha256', $third_key ) => '1' ),
        ) );

        $stored = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ];
        $this->assertSame( array( hash( 'sha256', $first_key ), hash( 'sha256', $second_key ) ), array_column( $stored, 'key_hash' ) );
        $this->assertSame( array( true, false ), array_column( $stored, 'enabled' ) );
    }

    public function test_disabled_platform_key_stops_authenticating(): void {
        $key = $this->issue();
        $this->save( array() );

        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'x-api-key' => $key ) );

        $this->assertSame( 'key_not_found', FD_Test_Platform_Vectors::auth()->authenticate( $request )['error']['code'] );
    }

    public function test_malformed_stored_entries_are_dropped_on_save(): void {
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] = array(
            'junk',
            array( 'profile' => self::PROFILE, 'key_hash' => 'short', 'enabled' => true ),
            array( 'profile' => 'http://insecure.example', 'key_hash' => str_repeat( 'a', 64 ), 'enabled' => true ),
        );

        $this->save( array() );

        $this->assertSame( array(), FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] );
    }

    public function test_section_registers_the_platform_field_without_a_wordpress_option(): void {
        $fields = FD_UCP_Settings::settings( array(), FD_UCP_Settings::SECTION );
        $field  = array_values( array_filter( $fields, static fn( array $f ): bool => 'fd_ucp_platforms' === $f['type'] ) );

        $this->assertCount( 1, $field );
        $this->assertFalse( $field[0]['is_option'] );
    }
}
