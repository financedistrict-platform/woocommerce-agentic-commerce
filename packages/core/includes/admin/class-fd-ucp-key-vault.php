<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Key_Vault {

    private const CIPHER      = 'aes-256-gcm';
    private const IV_LENGTH   = 12;
    private const TAG_LENGTH  = 16;
    private const KEY_CONTEXT = '|fd_ucp_platform_keys';

    public static function seal( string $plain ): ?string {
        if ( '' === $plain ) {
            return null;
        }
        $iv  = random_bytes( self::IV_LENGTH );
        $tag = '';

        $cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH );
        if ( false === $cipher || self::TAG_LENGTH !== strlen( $tag ) ) {
            return null;
        }

        return base64_encode( $iv . $tag . $cipher );
    }

    public static function open( string $sealed ): ?string {
        $raw = base64_decode( $sealed, true );
        if ( false === $raw || strlen( $raw ) <= self::IV_LENGTH + self::TAG_LENGTH ) {
            return null;
        }

        $plain = openssl_decrypt(
            substr( $raw, self::IV_LENGTH + self::TAG_LENGTH ),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr( $raw, 0, self::IV_LENGTH ),
            substr( $raw, self::IV_LENGTH, self::TAG_LENGTH )
        );

        return false === $plain || '' === $plain ? null : $plain;
    }

    private static function key(): string {
        return hash( 'sha256', wp_salt( 'secure_auth' ) . self::KEY_CONTEXT, true );
    }
}
