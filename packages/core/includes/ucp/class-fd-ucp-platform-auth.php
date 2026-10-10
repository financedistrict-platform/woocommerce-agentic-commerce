<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Platform_Auth {

    public const OPTION               = 'fd_ucp_platforms';
    public const OPTION_ACCESS        = 'fd_ucp_platform_access';
    public const ACCESS_OPEN          = 'open';
    public const ACCESS_AUTHENTICATED = 'authenticated';
    public const ACCESS_REGISTERED    = 'registered';
    public const UNVERIFIED_PREFIX    = 'unverified:';
    public const CREATED_WINDOW       = 300;
    public const MAX_PROFILE_LENGTH   = 180;
    private const MAX_MEMBERS       = 3;
    private const STANDING_ENABLED  = 'enabled';
    private const STANDING_REVOKED  = 'revoked';
    private const STANDING_UNKNOWN  = 'unknown';
    private const P256_SPKI_PREFIX  = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private FD_UCP_Agent_Profile_Fetcher $fetcher;
    private $clock;

    public function __construct( FD_UCP_Agent_Profile_Fetcher $fetcher, ?callable $clock = null ) {
        $this->fetcher = $fetcher;
        $this->clock   = $clock ?? 'time';
    }

    public static function normalise_profile_url( string $url ): ?string {
        $url = trim( $url );
        if ( '' === $url || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
            return null;
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return null;
        }

        $host = strtolower( (string) ( $parts['host'] ?? '' ) );
        if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === $host ) {
            return null;
        }

        $port       = isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
        $path       = rtrim( (string) ( $parts['path'] ?? '' ), '/' );
        $query      = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
        $normalised = 'https://' . $host . $port . $path . $query;

        return strlen( $normalised ) > self::MAX_PROFILE_LENGTH ? null : $normalised;
    }

    public static function access_modes(): array {
        return array( self::ACCESS_OPEN, self::ACCESS_AUTHENTICATED, self::ACCESS_REGISTERED );
    }

    private static function mode(): string {
        $stored = get_option( self::OPTION_ACCESS, self::ACCESS_OPEN );
        return is_string( $stored ) && in_array( $stored, self::access_modes(), true ) ? $stored : self::ACCESS_REGISTERED;
    }

    public function authenticate( WP_REST_Request $request ): array {
        $declared = (string) FD_UCP_Version_Resolver::profile_url( $request->get_header( 'ucp-agent' ) );
        $profile  = self::normalise_profile_url( $declared );
        if ( null === $profile ) {
            return self::failure( 'invalid_profile_url', 400, 'UCP-Agent must carry the https profile URL of the calling platform' );
        }

        $registry = self::registry();
        $standing = self::standing( $registry, $profile );
        if ( self::STANDING_REVOKED === $standing ) {
            return self::failure( 'profile_not_trusted', 403, 'This platform profile has been disabled by the store owner' );
        }

        if ( '' !== trim( (string) $request->get_header( 'signature-input' ) ) || '' !== trim( (string) $request->get_header( 'signature' ) ) ) {
            if ( self::STANDING_ENABLED !== $standing && self::ACCESS_REGISTERED === self::mode() ) {
                return self::failure( 'profile_not_trusted', 403, 'This store accepts signed requests only from registered platforms' );
            }
            return $this->by_signature( $request, $profile, $declared );
        }

        $key = trim( (string) $request->get_header( 'x-api-key' ) );
        if ( '' !== $key ) {
            return self::by_api_key( $key, $profile, $registry );
        }

        return self::without_credentials( $profile, $standing );
    }

    private static function without_credentials( string $profile, string $standing ): array {
        if ( self::STANDING_ENABLED === $standing ) {
            return self::failure( 'signature_missing', 401, $profile . ' is registered on this store. Send its X-API-Key or sign the request' );
        }

        switch ( self::mode() ) {
            case self::ACCESS_OPEN:
                return array( 'platform_id' => self::UNVERIFIED_PREFIX . $profile );
            case self::ACCESS_AUTHENTICATED:
                return self::failure( 'signature_missing', 401, 'Sign the request or present an X-API-Key registered for your platform' );
            default:
                return self::failure( 'profile_not_trusted', 403, 'This store accepts only registered platforms' );
        }
    }

    private static function failure( string $code, int $status, string $message ): array {
        return array( 'error' => array( 'code' => $code, 'status' => $status, 'message' => $message ) );
    }

    private static function registry(): array {
        $stored   = get_option( self::OPTION, array() );
        $registry = array();

        foreach ( is_array( $stored ) ? $stored : array() as $entry ) {
            if ( ! is_array( $entry ) || ! is_string( $entry['key_hash'] ?? null ) ) {
                continue;
            }
            $registry[] = array(
                'profile'  => self::normalise_profile_url( (string) ( $entry['profile'] ?? '' ) ),
                'key_hash' => strtolower( $entry['key_hash'] ),
                'enabled'  => ! empty( $entry['enabled'] ),
            );
        }
        return $registry;
    }

    private static function standing( array $registry, string $profile ): string {
        $standing = self::STANDING_UNKNOWN;
        foreach ( $registry as $entry ) {
            if ( $profile !== $entry['profile'] ) {
                continue;
            }
            if ( $entry['enabled'] ) {
                return self::STANDING_ENABLED;
            }
            $standing = self::STANDING_REVOKED;
        }
        return $standing;
    }

    private static function by_api_key( string $key, string $profile, array $registry ): array {
        $presented  = hash( 'sha256', $key );
        $registered = array();

        foreach ( $registry as $entry ) {
            if ( $entry['enabled'] && hash_equals( $entry['key_hash'], $presented ) ) {
                $registered[] = $entry['profile'];
            }
        }

        if ( empty( $registered ) ) {
            return self::failure( 'key_not_found', 401, 'The X-API-Key is not registered on this store or has been disabled. Ask the store owner to check WooCommerce > Settings > Advanced > UCP versions > Platform access' );
        }
        if ( ! in_array( $profile, $registered, true ) ) {
            return self::failure( 'profile_not_trusted', 403, 'The X-API-Key is not registered for this platform profile' );
        }
        return array( 'platform_id' => $profile );
    }

    private function by_signature( WP_REST_Request $request, string $profile, string $declared ): array {
        $method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) );
        if ( '' === $method || $method !== strtoupper( $request->get_method() ) ) {
            return self::failure( 'signature_invalid', 401, 'The request method does not match the signed method' );
        }

        $inputs     = self::dictionary( (string) $request->get_header( 'signature-input' ) );
        $signatures = self::dictionary( (string) $request->get_header( 'signature' ) );
        $labels     = array_slice( array_values( array_intersect( array_keys( $inputs ), array_keys( $signatures ) ) ), 0, self::MAX_MEMBERS );
        if ( empty( $labels ) ) {
            return self::failure( 'signature_invalid', 401, 'Signature and Signature-Input do not share a member' );
        }

        $first_error = null;
        foreach ( $labels as $label ) {
            $outcome = $this->verify_member( $request, $profile, $declared, $method, $inputs[ $label ], $signatures[ $label ] );
            if ( isset( $outcome['platform_id'] ) ) {
                return $outcome;
            }
            $first_error ??= $outcome;
        }
        return $first_error;
    }

    private function verify_member( WP_REST_Request $request, string $profile, string $declared_url, string $method, string $raw_input, string $raw_signature ): array {
        $input = self::parse_signature_input( $raw_input );
        if ( null === $input ) {
            return self::failure( 'signature_invalid', 401, 'Signature-Input is malformed or uses unsupported components' );
        }

        $keyid = $input['params']['keyid'] ?? '';
        if ( ! is_string( $keyid ) || '' === $keyid ) {
            return self::failure( 'signature_invalid', 401, 'Signature-Input must carry a keyid' );
        }

        $declared = $input['params']['alg'] ?? null;
        if ( null !== $declared && ! in_array( $declared, array( 'ecdsa-p256-sha256', 'ed25519' ), true ) ) {
            return self::failure( 'algorithm_unsupported', 400, 'Only ES256 and Ed25519 signatures are supported' );
        }

        $window = $this->freshness_error( $input['params'] );
        if ( null !== $window ) {
            return $window;
        }

        $body = (string) $request->get_body();
        if ( '' === $body && array() !== $request->get_body_params() ) {
            return self::failure( 'signature_invalid', 401, 'A request without a signed body cannot carry form parameters' );
        }

        $coverage = $this->coverage_error( $request, $input['components'], $body );
        if ( null !== $coverage ) {
            return $coverage;
        }

        $digest = self::digest_error( $request, $body );
        if ( null !== $digest ) {
            return $digest;
        }

        $signature = self::decode_signature( $raw_signature );
        if ( null === $signature ) {
            return self::failure( 'signature_invalid', 401, 'Signature is not a valid byte sequence' );
        }

        $base = $this->signature_base( $request, $method, $input );
        if ( null === $base ) {
            return self::failure( 'signature_invalid', 401, 'A signed component is missing from the request' );
        }

        $key = $this->find_key( $declared_url, $keyid );
        if ( isset( $key['error'] ) ) {
            return $key;
        }

        $jwk = self::usable_key( $key['jwk'], $declared );
        if ( isset( $jwk['error'] ) ) {
            return $jwk;
        }

        if ( ! self::signature_matches( $jwk, $base, $signature ) ) {
            return self::failure( 'signature_invalid', 401, 'The signature does not verify against the published key' );
        }
        return array( 'platform_id' => $profile );
    }

    private function freshness_error( array $params ): ?array {
        $now = (int) ( $this->clock )();

        if ( isset( $params['created'] ) ) {
            if ( ! is_int( $params['created'] ) || abs( $now - $params['created'] ) > self::CREATED_WINDOW ) {
                return self::failure( 'signature_invalid', 401, 'The signature was created outside the accepted time window' );
            }
        }
        if ( isset( $params['expires'] ) ) {
            if ( ! is_int( $params['expires'] ) || $now > $params['expires'] ) {
                return self::failure( 'signature_invalid', 401, 'The signature has expired' );
            }
        }
        return null;
    }

    private function coverage_error( WP_REST_Request $request, array $components, string $body ): ?array {
        $required = array( '@method', '@authority', '@path', 'ucp-agent' );
        if ( '' !== self::request_target()['query'] ) {
            $required[] = '@query';
        }
        if ( '' !== $body ) {
            array_push( $required, 'content-digest', 'content-type' );
        }
        if ( null !== $request->get_header_as_array( 'idempotency-key' ) ) {
            $required[] = 'idempotency-key';
        }

        if ( array() !== array_diff( $required, $components ) ) {
            return self::failure( 'signature_invalid', 401, 'The signature does not cover every required component' );
        }
        return null;
    }

    private static function digest_error( WP_REST_Request $request, string $body ): ?array {
        $header = $request->get_header_as_array( 'content-digest' );
        if ( null === $header ) {
            return '' === $body ? null : self::failure( 'signature_invalid', 401, 'A request body requires a Content-Digest header' );
        }

        $members = self::dictionary( implode( ', ', $header ) );
        $value   = $members['sha-256'] ?? null;
        $claimed = is_string( $value ) ? self::byte_sequence( $value ) : null;
        if ( null === $claimed || ! hash_equals( hash( 'sha256', $body, true ), $claimed ) ) {
            return self::failure( 'digest_mismatch', 400, 'Content-Digest does not match the request body' );
        }
        return null;
    }

    private function signature_base( WP_REST_Request $request, string $method, array $input ): ?string {
        $target = self::request_target();
        $lines  = array();

        foreach ( $input['components'] as $name ) {
            switch ( $name ) {
                case '@method':
                    $value = $method;
                    break;
                case '@authority':
                    $value = self::authority();
                    break;
                case '@path':
                    $value = $target['path'];
                    break;
                case '@query':
                    $value = '?' . $target['query'];
                    break;
                default:
                    if ( '@' === $name[0] ) {
                        return null;
                    }
                    $values = $request->get_header_as_array( $name );
                    if ( null === $values ) {
                        return null;
                    }
                    $value = implode( ', ', array_map( 'trim', $values ) );
            }
            $lines[] = '"' . $name . '": ' . $value;
        }

        $lines[] = '"@signature-params": ' . $input['raw'];
        return implode( "\n", $lines );
    }

    private static function authority(): string {
        $parts  = wp_parse_url( home_url() );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host   = strtolower( (string) ( $parts['host'] ?? '' ) );
        $port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
        $plain  = 'https' === $scheme ? 443 : 80;

        return null === $port || $plain === $port ? $host : $host . ':' . $port;
    }

    private static function request_target(): array {
        $uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $uri = preg_replace( '#^[a-z][a-z0-9+.\-]*://[^/?]*#i', '', $uri );
        $cut = strpos( $uri, '?' );

        $path = false === $cut ? $uri : substr( $uri, 0, $cut );
        return array(
            'path'  => '' === $path ? '/' : $path,
            'query' => false === $cut ? '' : substr( $uri, $cut + 1 ),
        );
    }

    private function find_key( string $profile, string $keyid ): array {
        $loaded = $this->fetcher->lookup( $profile );
        if ( ! empty( $loaded['failed'] ) ) {
            return self::failure( 'profile_unreachable', 424, 'The platform profile could not be retrieved' );
        }

        $jwk = self::key_by_id( $loaded['keys'] ?? array(), $keyid );
        if ( null === $jwk ) {
            $refreshed = $this->fetcher->refresh( $profile );
            $jwk       = empty( $refreshed['failed'] ) ? self::key_by_id( $refreshed['keys'] ?? array(), $keyid ) : null;
        }

        return null === $jwk
            ? self::failure( 'key_not_found', 401, 'No key with this keyid is published in the platform profile' )
            : array( 'jwk' => $jwk );
    }

    private static function key_by_id( array $keys, string $keyid ): ?array {
        foreach ( $keys as $jwk ) {
            if ( is_array( $jwk ) && isset( $jwk['kid'] ) && is_string( $jwk['kid'] ) && $keyid === $jwk['kid'] ) {
                return $jwk;
            }
        }
        return null;
    }

    private static function usable_key( array $jwk, ?string $declared ): array {
        $ops = $jwk['key_ops'] ?? null;
        if ( ( isset( $jwk['use'] ) && 'sig' !== $jwk['use'] ) || ( is_array( $ops ) && ! in_array( 'verify', $ops, true ) ) ) {
            return self::failure( 'key_not_found', 401, 'The published key is not a signature verification key' );
        }

        $kty = $jwk['kty'] ?? null;
        $crv = $jwk['crv'] ?? null;
        $alg = $jwk['alg'] ?? null;

        if ( 'EC' === $kty && 'P-256' === $crv && ( null === $alg || 'ES256' === $alg ) && ( null === $declared || 'ecdsa-p256-sha256' === $declared ) ) {
            $x = self::base64url( $jwk['x'] ?? null );
            $y = self::base64url( $jwk['y'] ?? null );
            if ( null !== $x && null !== $y && 32 === strlen( $x ) && 32 === strlen( $y ) ) {
                return array( 'type' => 'ES256', 'public' => "\x04" . $x . $y );
            }
            return self::failure( 'key_not_found', 401, 'The published key is malformed' );
        }

        if ( 'OKP' === $kty && 'Ed25519' === $crv && ( null === $alg || in_array( $alg, array( 'EdDSA', 'Ed25519' ), true ) ) && ( null === $declared || 'ed25519' === $declared ) ) {
            $x = self::base64url( $jwk['x'] ?? null );
            if ( null !== $x && 32 === strlen( $x ) && function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
                return array( 'type' => 'Ed25519', 'public' => $x );
            }
            return self::failure( 'key_not_found', 401, 'The published key is malformed' );
        }

        return self::failure( 'algorithm_unsupported', 400, 'Only ES256 and Ed25519 keys are supported' );
    }

    private static function signature_matches( array $jwk, string $base, string $signature ): bool {
        if ( 64 !== strlen( $signature ) ) {
            return false;
        }

        if ( 'Ed25519' === $jwk['type'] ) {
            try {
                return sodium_crypto_sign_verify_detached( $signature, $base, $jwk['public'] );
            } catch ( \Throwable $e ) {
                return false;
            }
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split( base64_encode( hex2bin( self::P256_SPKI_PREFIX ) . $jwk['public'] ), 64, "\n" )
            . "-----END PUBLIC KEY-----\n";

        $public = openssl_pkey_get_public( $pem );
        if ( false === $public ) {
            while ( false !== openssl_error_string() ) {
            }
            return false;
        }

        return 1 === openssl_verify( $base, self::der_signature( $signature ), $public, OPENSSL_ALGO_SHA256 );
    }

    private static function der_signature( string $raw ): string {
        $encode = static function ( string $integer ): string {
            $integer = ltrim( $integer, "\x00" );
            if ( '' === $integer || ord( $integer[0] ) > 0x7F ) {
                $integer = "\x00" . $integer;
            }
            return "\x02" . chr( strlen( $integer ) ) . $integer;
        };

        $body = $encode( substr( $raw, 0, 32 ) ) . $encode( substr( $raw, 32, 32 ) );
        return "\x30" . chr( strlen( $body ) ) . $body;
    }

    private static function parse_signature_input( string $raw ): ?array {
        $raw = trim( $raw );
        if ( ! preg_match( '/^\(\s*((?:"[^"\\\\]*"\s*)*)\)((?:;[a-z*][a-z0-9_.*\-]*(?:=(?:"[^"\\\\]*"|[^;\s"]+))?)*)$/', $raw, $m ) ) {
            return null;
        }

        preg_match_all( '/"([^"]*)"/', $m[1], $names );
        $components = $names[1];
        if ( empty( $components ) || $components !== array_values( array_unique( $components ) ) ) {
            return null;
        }
        foreach ( $components as $name ) {
            if ( '' === $name || $name !== strtolower( $name ) ) {
                return null;
            }
        }

        $params = array();
        preg_match_all( '/;([a-z*][a-z0-9_.*\-]*)(?:=("[^"\\\\]*"|[^;\s"]+))?/', $m[2], $found, PREG_SET_ORDER );
        foreach ( $found as $pair ) {
            $value = $pair[2] ?? '';
            if ( '' !== $value && '"' === $value[0] ) {
                $params[ $pair[1] ] = substr( $value, 1, -1 );
            } elseif ( ctype_digit( $value ) && strlen( $value ) < 16 ) {
                $params[ $pair[1] ] = (int) $value;
            } else {
                $params[ $pair[1] ] = $value;
            }
        }

        return array( 'components' => $components, 'params' => $params, 'raw' => $raw );
    }

    private static function dictionary( string $header ): array {
        $members = array();
        $buffer  = '';
        $quoted  = false;
        $depth   = 0;

        foreach ( str_split( $header ) as $char ) {
            if ( '"' === $char ) {
                $quoted = ! $quoted;
            } elseif ( ! $quoted && '(' === $char ) {
                ++$depth;
            } elseif ( ! $quoted && ')' === $char ) {
                $depth = max( 0, $depth - 1 );
            }

            if ( ',' === $char && ! $quoted && 0 === $depth ) {
                self::collect_member( $members, $buffer );
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        self::collect_member( $members, $buffer );

        return $members;
    }

    private static function collect_member( array &$members, string $member ): void {
        $member = trim( $member );
        $cut    = strpos( $member, '=' );
        if ( false === $cut ) {
            return;
        }
        $label = substr( $member, 0, $cut );
        if ( preg_match( '/^[a-z*][a-z0-9_.*\-]*$/', $label ) ) {
            $members[ $label ] = trim( substr( $member, $cut + 1 ) );
        }
    }

    private static function decode_signature( string $value ): ?string {
        $decoded = self::byte_sequence( $value );
        return null === $decoded ? null : $decoded;
    }

    private static function byte_sequence( string $value ): ?string {
        if ( ! preg_match( '/^:([A-Za-z0-9+\/]*={0,2}):/', $value, $m ) ) {
            return null;
        }
        $decoded = base64_decode( $m[1], true );
        return false === $decoded ? null : $decoded;
    }

    private static function base64url( mixed $value ): ?string {
        if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $value ) ) {
            return null;
        }
        $padded  = strtr( $value, '-_', '+/' );
        $decoded = base64_decode( $padded . str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 ), true );
        return false === $decoded ? null : $decoded;
    }
}
