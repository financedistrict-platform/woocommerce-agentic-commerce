<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class KeyVaultTest extends TestCase {

    private const KEY = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    private function flip( string $sealed, int $offset ): string {
        $raw            = base64_decode( $sealed, true );
        $raw[ $offset ] = chr( ord( $raw[ $offset ] ) ^ 1 );
        return base64_encode( $raw );
    }

    public function test_sealed_value_opens_back_to_the_original(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );

        $this->assertIsString( $sealed );
        $this->assertSame( self::KEY, FD_UCP_Key_Vault::open( $sealed ) );
    }

    public function test_sealed_value_does_not_contain_the_plain_value(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );

        $this->assertStringNotContainsString( self::KEY, $sealed );
        $this->assertStringNotContainsString( self::KEY, (string) base64_decode( $sealed, true ) );
    }

    public function test_every_seal_uses_a_fresh_iv(): void {
        $first  = FD_UCP_Key_Vault::seal( self::KEY );
        $second = FD_UCP_Key_Vault::seal( self::KEY );

        $this->assertNotSame( $first, $second );
        $this->assertNotSame( substr( base64_decode( $first, true ), 0, 12 ), substr( base64_decode( $second, true ), 0, 12 ) );
        $this->assertSame( self::KEY, FD_UCP_Key_Vault::open( $second ) );
    }

    public function test_layout_is_iv_then_tag_then_ciphertext(): void {
        $raw = base64_decode( FD_UCP_Key_Vault::seal( self::KEY ), true );

        $this->assertSame( 12 + 16 + strlen( self::KEY ), strlen( $raw ) );
    }

    public function test_a_flipped_bit_anywhere_fails_closed(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );
        $length = strlen( base64_decode( $sealed, true ) );

        foreach ( array( 0, 11, 12, 27, 28, $length - 1 ) as $offset ) {
            $this->assertNull( FD_UCP_Key_Vault::open( $this->flip( $sealed, $offset ) ), 'offset ' . $offset );
        }
    }

    public function test_truncated_or_malformed_input_fails_closed(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );
        $raw    = base64_decode( $sealed, true );

        foreach ( array( '', 'not base64 !!', base64_encode( substr( $raw, 0, 27 ) ), base64_encode( substr( $raw, 0, 28 ) ), base64_encode( substr( $raw, 0, -1 ) ), base64_encode( $raw . 'x' ) ) as $input ) {
            $this->assertNull( FD_UCP_Key_Vault::open( $input ), $input );
        }
    }

    public function test_a_different_salt_cannot_open_the_value(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );

        FD_Test_WP::$salt = 'rotated-salt';

        $this->assertNull( FD_UCP_Key_Vault::open( $sealed ) );
    }

    public function test_the_key_is_derived_from_the_secure_auth_salt(): void {
        $raw = base64_decode( FD_UCP_Key_Vault::seal( self::KEY ), true );
        $key = hash( 'sha256', wp_salt( 'secure_auth' ) . '|fd_ucp_platform_keys', true );

        $plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

        $this->assertSame( self::KEY, $plain );
    }

    public function test_sealing_and_opening_return_null_instead_of_a_fatal_when_openssl_is_unavailable(): void {
        $sealed = FD_UCP_Key_Vault::seal( self::KEY );

        $result = FD_Test_Without_OpenSSL::run(
            'echo json_encode( array( FD_UCP_Key_Vault::seal( ' . var_export( self::KEY, true ) . ' ), FD_UCP_Key_Vault::open( ' . var_export( $sealed, true ) . ' ) ) );'
        );

        $this->assertSame( array( null, null ), $result );
    }
}
