<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Validator {

    private const AUTHORIZATION_FIELDS = array( 'from', 'to', 'value', 'validAfter', 'validBefore', 'nonce' );
    private const REQUIREMENT_FIELDS   = array( 'scheme', 'network', 'asset', 'payTo', 'amount' );
    private const NONCE_PATTERN        = '/^0x[0-9a-fA-F]{64}$/';
    private const ATOMIC_AMOUNT_PATTERN = '/^[1-9][0-9]*$/';
    private const CLOCK_SKEW_SECONDS   = 30;
    private const X402_VERSION         = 2;
    private const SCHEME               = 'exact';
    private const NETWORK_PREFIX       = 'eip155:';

    public static function verify( $credential, $stored_config ): array|WP_Error {
        $quote = self::parse_quote( $stored_config );
        if ( ! $quote ) {
            return new WP_Error( 'missing_payment_requirements', 'No stored payment requirements to validate against' );
        }

        $payload = self::read_payment_payload( $credential );
        if ( ! $payload ) {
            return new WP_Error( 'invalid_credential', 'Invalid x402 credential format' );
        }

        if ( $payload['x402Version'] !== $quote['x402Version'] ) {
            return new WP_Error( 'version_mismatch', 'Credential x402 version does not match the stored payment requirements' );
        }

        $accepted = $payload['accepted'];
        if ( array_key_exists( 'network', $payload ) && $payload['network'] !== $accepted['network'] ) {
            return new WP_Error( 'network_mismatch', 'Credential network does not match its accepted requirements' );
        }

        $requirement = self::match_stored_requirement( $accepted, $quote['accepts'] );
        if ( ! $requirement ) {
            return new WP_Error( 'no_matching_accept', 'Accepted requirements do not match any stored payment requirement' );
        }

        if ( array_key_exists( 'resource', $payload )
            && ( $payload['resource']['url'] ?? null ) !== $quote['resource']['url']
        ) {
            return new WP_Error( 'resource_mismatch', 'Credential resource does not match this checkout' );
        }

        $authorization = $payload['payload']['authorization'];

        if ( 0 !== strcasecmp( $authorization['to'], $requirement['payTo'] ) ) {
            return new WP_Error( 'recipient_mismatch', 'Signed payment recipient does not match the expected payTo address' );
        }

        if ( $authorization['value'] !== $requirement['amount'] ) {
            return new WP_Error(
                'amount_mismatch',
                "Signed amount ({$authorization['value']}) does not equal the required amount ({$requirement['amount']})"
            );
        }

        $now = time();
        if ( (int) $authorization['validAfter'] > $now + self::CLOCK_SKEW_SECONDS ) {
            return new WP_Error( 'authorization_not_yet_valid', 'Signed payment is not valid yet' );
        }
        if ( (int) $authorization['validBefore'] <= $now ) {
            return new WP_Error( 'authorization_expired', 'Signed payment has expired' );
        }

        return array(
            'x402_version'         => $quote['x402Version'],
            'payment_requirements' => $requirement,
            'payment_payload'      => array(
                'x402Version' => $quote['x402Version'],
                'resource'    => $quote['resource'],
                'accepted'    => $requirement,
                'payload'     => array(
                    'signature'     => $payload['payload']['signature'],
                    'authorization' => array_intersect_key( $authorization, array_flip( self::AUTHORIZATION_FIELDS ) ),
                ),
            ),
            'payer'                => $authorization['from'],
            'value'                => $authorization['value'],
        );
    }

    public static function parse_quote( $config ): ?array {
        if ( ! is_array( $config )
            || self::X402_VERSION !== ( $config['x402Version'] ?? null )
            || ! self::is_filled_string( $config['resource']['url'] ?? null )
            || ! is_array( $config['accepts'] ?? null )
        ) {
            return null;
        }

        $accepts = array_values( array_filter( $config['accepts'], array( self::class, 'is_settleable_requirement' ) ) );
        if ( array() === $accepts ) {
            return null;
        }

        $config['accepts'] = $accepts;
        return $config;
    }

    private static function read_payment_payload( $credential ): ?array {
        $decoded = self::decode_to_array( $credential );
        if ( is_array( $decoded ) && isset( $decoded['authorization'] ) && ! isset( $decoded['paymentPayload'] ) ) {
            $decoded = self::decode_to_array( $decoded['authorization'] );
        }
        if ( ! is_array( $decoded ) || isset( $decoded['authorization'] ) ) {
            return null;
        }

        if ( isset( $decoded['paymentPayload'] ) && isset( $decoded['accepted'] ) ) {
            return null;
        }

        $payload = $decoded['paymentPayload'] ?? $decoded;
        if ( ! is_array( $payload )
            || ! is_int( $payload['x402Version'] ?? null )
            || ! self::is_requirement( $payload['accepted'] ?? null )
            || ! self::is_filled_string( $payload['payload']['signature'] ?? null )
            || ! is_array( $payload['payload']['authorization'] ?? null )
        ) {
            return null;
        }

        $authorization = $payload['payload']['authorization'];
        foreach ( self::AUTHORIZATION_FIELDS as $field ) {
            if ( ! self::is_filled_string( $authorization[ $field ] ?? null ) ) {
                return null;
            }
        }
        foreach ( array( 'validAfter', 'validBefore' ) as $field ) {
            if ( ! ctype_digit( $authorization[ $field ] ) ) {
                return null;
            }
        }
        if ( ! self::is_atomic_amount( $authorization['value'] ) ) {
            return null;
        }
        if ( ! preg_match( self::NONCE_PATTERN, $authorization['nonce'] ) ) {
            return null;
        }

        return $payload;
    }

    private static function match_stored_requirement( array $accepted, array $stored ): ?array {
        foreach ( $stored as $requirement ) {
            if ( $accepted['scheme'] === $requirement['scheme']
                && $accepted['network'] === $requirement['network']
                && 0 === strcasecmp( $accepted['asset'], $requirement['asset'] )
                && 0 === strcasecmp( $accepted['payTo'], $requirement['payTo'] )
                && $accepted['amount'] === $requirement['amount']
            ) {
                return $requirement;
            }
        }
        return null;
    }

    private static function is_requirement( $requirement ): bool {
        if ( ! is_array( $requirement ) ) {
            return false;
        }
        foreach ( self::REQUIREMENT_FIELDS as $field ) {
            if ( ! self::is_filled_string( $requirement[ $field ] ?? null ) ) {
                return false;
            }
        }
        return self::is_atomic_amount( $requirement['amount'] );
    }

    private static function is_atomic_amount( $value ): bool {
        return is_string( $value ) && 1 === preg_match( self::ATOMIC_AMOUNT_PATTERN, $value );
    }

    private static function is_settleable_requirement( $requirement ): bool {
        return self::is_requirement( $requirement )
            && self::SCHEME === $requirement['scheme']
            && str_starts_with( $requirement['network'], self::NETWORK_PREFIX );
    }

    private static function is_filled_string( $value ): bool {
        return is_string( $value ) && '' !== $value;
    }

    private static function decode_to_array( $input ): ?array {
        if ( is_array( $input ) ) {
            return $input;
        }
        if ( ! is_string( $input ) ) {
            return null;
        }

        $b64 = base64_decode( $input, true );
        if ( $b64 ) {
            $parsed = json_decode( $b64, true );
            if ( is_array( $parsed ) ) {
                return $parsed;
            }
        }

        $parsed = json_decode( $input, true );
        return is_array( $parsed ) ? $parsed : null;
    }
}
