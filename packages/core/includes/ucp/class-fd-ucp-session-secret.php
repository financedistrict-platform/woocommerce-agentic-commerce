<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Session_Secret {

    public const HEADER = 'UCP-Session-Secret';

    public static function issue(): array {
        $secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        return array( 'secret' => $secret, 'hash' => hash( 'sha256', $secret ) );
    }

    public static function matches( string $stored_hash, string $presented ): bool {
        if ( '' === $presented || 1 !== preg_match( '/^[0-9a-f]{64}$/', $stored_hash ) ) {
            return false;
        }
        return hash_equals( $stored_hash, hash( 'sha256', $presented ) );
    }
}
