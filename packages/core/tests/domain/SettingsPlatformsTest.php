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

        $this->assertSame( 'profile_not_trusted', FD_Test_Platform_Vectors::auth()->authenticate( $request )['error']['code'] );
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

    private function reveal( array $hashes, array $extra = array() ): void {
        $reveal = array();
        foreach ( $hashes as $hash ) {
            $reveal[ $hash ] = '1';
        }
        $this->save( array_merge( array( '_wpnonce' => 'valid-nonce', 'fd_ucp_platform_reveal' => $reveal ), $extra ) );
    }

    private function keep_enabled( string ...$keys ): array {
        $enabled = array();
        foreach ( $keys as $key ) {
            $enabled[ hash( 'sha256', $key ) ] = '1';
        }
        return array( 'fd_ucp_platform_enabled' => $enabled );
    }

    public function test_issued_row_stores_a_cipher_that_opens_to_the_key_beside_the_unchanged_hash(): void {
        $key = $this->issue();

        $stored = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0];
        $this->assertSame( hash( 'sha256', $key ), $stored['key_hash'] );
        $this->assertIsString( $stored['key_cipher'] );
        $this->assertStringNotContainsString( $key, $stored['key_cipher'] );
        $this->assertSame( $key, FD_UCP_Key_Vault::open( $stored['key_cipher'] ) );
    }

    public function test_reveal_shows_the_issued_key_for_the_ticked_row_only(): void {
        $first  = $this->issue( 'https://one.example/ucp' );
        $second = $this->issue( 'https://two.example/ucp' );
        WC_Admin_Settings::reset();

        $this->reveal( array( hash( 'sha256', $second ) ), $this->keep_enabled( $first, $second ) );

        $text = implode( "\n", WC_Admin_Settings::$messages );
        $this->assertStringContainsString( 'https://two.example/ucp', $text );
        $this->assertStringContainsString( $second, $text );
        $this->assertStringNotContainsString( $first, $text );
        $this->assertSame( array(), WC_Admin_Settings::$errors );
    }

    public function test_nothing_is_revealed_unless_a_row_is_ticked(): void {
        $key = $this->issue();
        WC_Admin_Settings::reset();

        $this->save( array( '_wpnonce' => 'valid-nonce' ) + $this->keep_enabled( $key ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertSame( array(), WC_Admin_Settings::$errors );
    }

    public function test_reveal_does_not_change_the_registry(): void {
        $key    = $this->issue();
        $before = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ];

        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        $this->assertSame( $before, FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] );
    }

    public function test_cipher_survives_saves_that_toggle_other_rows(): void {
        $first  = $this->issue( 'https://one.example/ucp' );
        $second = $this->issue( 'https://two.example/ucp' );
        $this->save( $this->keep_enabled( $first ) );
        $this->save( $this->keep_enabled( $first, $second ) );
        WC_Admin_Settings::reset();

        $this->reveal( array( hash( 'sha256', $second ) ), $this->keep_enabled( $first, $second ) );

        $this->assertStringContainsString( $second, implode( "\n", WC_Admin_Settings::$messages ) );
    }

    public function test_reveal_works_for_a_disabled_row(): void {
        $key = $this->issue();
        $this->save( array() );
        WC_Admin_Settings::reset();

        $this->reveal( array( hash( 'sha256', $key ) ) );

        $this->assertStringContainsString( $key, implode( "\n", WC_Admin_Settings::$messages ) );
    }

    public function test_a_row_deleted_in_the_same_save_is_not_revealed(): void {
        $key = $this->issue();
        WC_Admin_Settings::reset();
        FD_Test_WP::$logs = array();

        $this->reveal( array( hash( 'sha256', $key ) ), array( 'fd_ucp_platform_delete' => array( hash( 'sha256', $key ) => '1' ) ) );

        $this->assertStringNotContainsString( $key, implode( "\n", WC_Admin_Settings::$messages ) );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_unknown_hash_in_the_reveal_list_is_ignored(): void {
        $this->issue();
        WC_Admin_Settings::reset();
        FD_Test_WP::$logs = array();

        $this->reveal( array( str_repeat( 'a', 64 ), 'junk' ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertSame( array(), WC_Admin_Settings::$errors );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_hash_only_row_asks_for_a_new_key_and_never_outputs_one(): void {
        $key  = bin2hex( random_bytes( 32 ) );
        $hash = hash( 'sha256', $key );
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] = array(
            array( 'profile' => self::PROFILE, 'key_hash' => $hash, 'label' => 'Old', 'enabled' => true ),
        );

        $this->reveal( array( $hash ), array( 'fd_ucp_platform_enabled' => array( $hash => '1' ) ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertCount( 1, WC_Admin_Settings::$errors );
        $this->assertStringContainsString( 'Issue a new key', WC_Admin_Settings::$errors[0] );
        $this->assertStringContainsString( self::PROFILE, WC_Admin_Settings::$errors[0] );
        $this->assertDoesNotMatchRegularExpression( '/[0-9a-f]{32}/', WC_Admin_Settings::$errors[0] );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_reveal_without_openssl_asks_for_a_new_key_instead_of_a_fatal(): void {
        $key = $this->issue();
        $row = FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0];

        $result = FD_Test_Without_OpenSSL::run(
            '$r = FD_UCP_Settings::apply_platform_changes( array( ' . var_export( $row, true ) . ' ), array( "fd_ucp_platform_reveal" => array( ' . var_export( $row['key_hash'], true ) . ' => "1" ) ) );'
            . ' echo json_encode( array( "revealed" => $r["revealed"], "unavailable" => $r["unavailable"] ) );'
        );

        $this->assertSame( array(), $result['revealed'] );
        $this->assertSame( array( self::PROFILE ), $result['unavailable'] );
        $this->assertStringNotContainsString( $key, json_encode( $result ) );
    }

    public function test_cipher_that_does_not_open_to_the_key_of_the_row_is_never_shown(): void {
        $key   = $this->issue();
        $other = bin2hex( random_bytes( 32 ) );
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'] = FD_UCP_Key_Vault::seal( $other );
        WC_Admin_Settings::reset();
        FD_Test_WP::$logs = array();

        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertCount( 1, WC_Admin_Settings::$errors );
        $this->assertStringContainsString( 'Issue a new key', WC_Admin_Settings::$errors[0] );
        $this->assertStringNotContainsString( $other, json_encode( array( WC_Admin_Settings::$errors, FD_Test_WP::$logs ) ) );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_tampered_cipher_asks_for_a_new_key_and_never_outputs_a_partial_value(): void {
        $key  = $this->issue();
        $raw  = base64_decode( FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'], true );
        $last = strlen( $raw ) - 1;

        $raw[ $last ] = chr( ord( $raw[ $last ] ) ^ 1 );
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'] = base64_encode( $raw );
        WC_Admin_Settings::reset();
        FD_Test_WP::$logs = array();

        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertCount( 1, WC_Admin_Settings::$errors );
        $this->assertStringContainsString( 'Issue a new key', WC_Admin_Settings::$errors[0] );
        $this->assertStringNotContainsString( substr( $key, 0, 8 ), WC_Admin_Settings::$errors[0] );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_cipher_that_cannot_be_opened_after_a_salt_change_asks_for_a_new_key(): void {
        $key              = $this->issue();
        FD_Test_WP::$salt = 'rotated-salt';
        WC_Admin_Settings::reset();

        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertStringContainsString( 'Issue a new key', WC_Admin_Settings::$errors[0] );
    }

    public function test_reveal_requires_the_manage_capability(): void {
        $key                    = $this->issue();
        FD_Test_WP::$can_manage = false;
        WC_Admin_Settings::reset();
        FD_Test_WP::$logs = array();

        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        $this->assertSame( array(), WC_Admin_Settings::$messages );
        $this->assertCount( 1, WC_Admin_Settings::$errors );
        $this->assertStringNotContainsString( $key, json_encode( array( WC_Admin_Settings::$errors, FD_Test_WP::$logs ) ) );
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_reveal_requires_a_valid_nonce(): void {
        $key = $this->issue();
        FD_Test_WP::$logs = array();

        foreach ( array( array(), array( '_wpnonce' => 'forged' ), array( '_wpnonce' => array( 'valid-nonce' ) ) ) as $nonce ) {
            WC_Admin_Settings::reset();
            $this->save( $nonce + array( 'fd_ucp_platform_reveal' => array( hash( 'sha256', $key ) => '1' ) ) + $this->keep_enabled( $key ) );

            $this->assertSame( array(), WC_Admin_Settings::$messages );
            $this->assertCount( 1, WC_Admin_Settings::$errors );
            $this->assertStringNotContainsString( $key, json_encode( WC_Admin_Settings::$errors ) );
        }
        $this->assertSame( array(), FD_Test_WP::$logs );
    }

    public function test_refused_reveal_still_applies_the_other_registry_changes(): void {
        $key                    = $this->issue();
        FD_Test_WP::$can_manage = false;

        $this->save( array( 'fd_ucp_platform_reveal' => array( hash( 'sha256', $key ) => '1' ) ) );

        $this->assertFalse( FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['enabled'] );
    }

    public function test_each_reveal_is_logged_with_profile_and_user_but_never_the_key(): void {
        $first            = $this->issue( 'https://one.example/ucp' );
        $second           = $this->issue( 'https://two.example/ucp' );
        FD_Test_WP::$logs = array();

        $this->reveal( array( hash( 'sha256', $first ), hash( 'sha256', $second ) ), $this->keep_enabled( $first, $second ) );

        $this->assertCount( 2, FD_Test_WP::$logs );
        foreach ( FD_Test_WP::$logs as $index => $log ) {
            $this->assertSame( 'info', $log['level'] );
            $this->assertSame( 'fd-ucp', $log['context']['source'] );
            $this->assertSame( 7, $log['context']['user_id'] );
            $this->assertSame( array( 'https://one.example/ucp', 'https://two.example/ucp' )[ $index ], $log['context']['profile'] );
        }
        $dump = json_encode( FD_Test_WP::$logs );
        $this->assertStringNotContainsString( $first, $dump );
        $this->assertStringNotContainsString( $second, $dump );
        $this->assertStringNotContainsString( hash( 'sha256', $first ), $dump );
    }

    public function test_platform_table_offers_a_show_checkbox_and_never_prints_a_key(): void {
        $key = $this->issue();

        ob_start();
        FD_UCP_Settings::render_platforms( array() );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'name="fd_ucp_platform_reveal[' . hash( 'sha256', $key ) . ']"', $html );
        $this->assertStringContainsString( 'Show', $html );
        $this->assertStringNotContainsString( $key, $html );
        $this->assertStringNotContainsString( FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'], $html );
    }

    public function test_reveal_request_does_not_put_the_key_in_the_rendered_table(): void {
        $key = $this->issue();
        $this->reveal( array( hash( 'sha256', $key ) ), $this->keep_enabled( $key ) );

        ob_start();
        FD_UCP_Settings::render_platforms( array() );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( $key, $html );
    }

    public function test_hash_only_rows_keep_authenticating_exactly_as_before(): void {
        $key = bin2hex( random_bytes( 32 ) );
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ] = array(
            array( 'profile' => self::PROFILE, 'key_hash' => hash( 'sha256', $key ), 'label' => 'Old', 'enabled' => true ),
        );
        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'x-api-key' => $key ) );
        $wrong   = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'x-api-key' => bin2hex( random_bytes( 32 ) ) ) );

        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );
        $this->assertSame( 'key_not_found', FD_Test_Platform_Vectors::auth()->authenticate( $wrong )['error']['code'] );

        $this->save( $this->keep_enabled( $key ) );
        $this->assertArrayNotHasKey( 'key_cipher', FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0] );
        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );
    }

    public function test_verification_ignores_the_cipher_entirely(): void {
        $key     = $this->issue();
        $request = FD_Test_Platform_Vectors::request( array( 'ucp-agent' => 'profile="' . self::PROFILE . '"', 'x-api-key' => $key ) );

        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'] = 'garbage';
        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );

        unset( FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][0]['key_cipher'] );
        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );

        FD_Test_WP::$salt = 'rotated-salt';
        $this->assertSame( array( 'platform_id' => self::PROFILE ), FD_Test_Platform_Vectors::auth()->authenticate( $request ) );
    }

    public function test_section_registers_the_platform_field_without_a_wordpress_option(): void {
        $fields = FD_UCP_Settings::settings( array(), FD_UCP_Settings::SECTION );
        $field  = array_values( array_filter( $fields, static fn( array $f ): bool => 'fd_ucp_platforms' === $f['type'] ) );

        $this->assertCount( 1, $field );
        $this->assertFalse( $field[0]['is_option'] );
    }

    private function signed_access_field(): array {
        $fields = FD_UCP_Settings::settings( array(), FD_UCP_Settings::SECTION );
        $found  = array_values( array_filter( $fields, static fn( array $f ): bool => ( $f['id'] ?? '' ) === 'fd_ucp_signed_access' ) );
        $this->assertCount( 1, $found );
        return $found[0];
    }

    public function test_signed_access_is_a_select_in_the_platform_access_section(): void {
        $fields = FD_UCP_Settings::settings( array(), FD_UCP_Settings::SECTION );
        $ids    = array_column( $fields, 'id' );
        $field  = $this->signed_access_field();

        $this->assertSame( 'select', $field['type'] );
        $this->assertSame( 'open', $field['default'] );
        $this->assertSame( array( 'open', 'registered' ), array_keys( $field['options'] ) );
        $this->assertNotSame( '', trim( (string) ( $field['desc'] ?? '' ) ) );
        $this->assertGreaterThan( array_search( 'fd_ucp_platform_access', $ids, true ), array_search( 'fd_ucp_signed_access', $ids, true ) );
        $this->assertLessThan( array_search( 'fd_ucp_platform_access', array_reverse( $ids, true ), true ), array_search( 'fd_ucp_signed_access', $ids, true ) );
    }

    public function test_signed_access_field_is_wired_to_the_option_the_auth_class_reads(): void {
        $field = $this->signed_access_field();

        $this->assertSame( FD_UCP_Platform_Auth::OPTION_SIGNED_ACCESS, $field['id'] );
        $this->assertSame( FD_UCP_Platform_Auth::signed_access_modes(), array_keys( $field['options'] ) );
        $this->assertSame( FD_UCP_Platform_Auth::SIGNED_ACCESS_OPEN, $field['default'] );
    }

    public function test_signed_access_option_is_sanitised_by_the_settings_filter(): void {
        FD_UCP_Settings::init();

        $hooks = FD_Test_WP::$hooks[ 'sanitize_option_' . FD_UCP_Platform_Auth::OPTION_SIGNED_ACCESS ] ?? array();

        $this->assertSame( array( array( FD_UCP_Settings::class, 'sanitize_signed_access' ) ), array_column( $hooks, 0 ) );
    }

    public function test_signed_access_sanitiser_keeps_the_two_values(): void {
        $this->assertSame( 'open', FD_UCP_Settings::sanitize_signed_access( 'open', 'fd_ucp_signed_access' ) );
        $this->assertSame( 'registered', FD_UCP_Settings::sanitize_signed_access( 'registered', 'fd_ucp_signed_access' ) );
        $this->assertSame( array(), WC_Admin_Settings::$errors );
    }

    public function test_signed_access_sanitiser_defaults_to_open_when_nothing_is_stored(): void {
        foreach ( array( 'closed', '', 'OPEN', null, array(), 1 ) as $value ) {
            WC_Admin_Settings::reset();

            $this->assertSame( 'open', FD_UCP_Settings::sanitize_signed_access( $value, 'fd_ucp_signed_access' ) );
            $this->assertCount( 1, WC_Admin_Settings::$errors );
        }
    }

    public function test_signed_access_sanitiser_keeps_the_stored_value_on_invalid_input(): void {
        FD_Test_WP::$options['fd_ucp_signed_access'] = 'registered';

        $this->assertSame( 'registered', FD_UCP_Settings::sanitize_signed_access( 'closed', 'fd_ucp_signed_access' ) );
    }
}
