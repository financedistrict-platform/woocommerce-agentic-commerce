<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Validator {

    /**
     * Validate an x402 credential against stored Prism payment requirements.
     * Returns true on success, WP_Error on failure.
     */
    public static function validate_credential( $credential, ?array $payment_meta ): true|WP_Error {
        $summary = self::extract_signed_summary( $credential );
        if ( ! $summary ) {
            return new WP_Error(
                'invalid_credential',
                'Could not extract payment summary from credential'
            );
        }

        $stored_accepts = self::read_stored_accepts( $payment_meta );
        if ( ! $stored_accepts ) {
            return new WP_Error(
                'missing_payment_requirements',
                'No stored payment requirements to validate against'
            );
        }

        return self::validate_against_stored( $summary, $stored_accepts );
    }

    /**
     * Extract { network, asset, value, to } from an x402 credential.
     * Handles multiple wire formats: base64 string, nested paymentPayload, flat object.
     */
    public static function extract_signed_summary( $credential ): ?array {
        $decoded = self::decode_to_array( $credential );
        if ( ! $decoded ) {
            return null;
        }

        // Format: { paymentPayload: { network, payload: { authorization: { to, value } } } }
        $payload = $decoded['paymentPayload'] ?? null;
        if ( is_array( $payload ) ) {
            $auth = $payload['payload']['authorization'] ?? null;
            if ( is_array( $auth ) ) {
                $accepted = $payload['accepted'] ?? array();
                return array(
                    'network' => $payload['network'] ?? $accepted['network'] ?? '',
                    'asset'   => $decoded['paymentRequirements']['asset']
                        ?? $accepted['asset'] ?? $payload['payload']['asset'] ?? '',
                    'value'   => (string) ( $auth['value'] ?? '0' ),
                    'to'      => $auth['to'] ?? '',
                );
            }
        }

        // Format: flat { network, asset, value, to }
        if ( isset( $decoded['network'], $decoded['value'] ) ) {
            return array(
                'network' => $decoded['network'],
                'asset'   => $decoded['asset'] ?? '',
                'value'   => (string) $decoded['value'],
                'to'      => $decoded['to'] ?? '',
            );
        }

        return null;
    }

    /**
     * Read the stored accepts[] entries from Prism checkout data.
     */
    private static function read_stored_accepts( ?array $payment_meta ): ?array {
        $prism = $payment_meta['xyz.fd.prism_payment'] ?? null;
        if ( ! $prism ) {
            return null;
        }

        $ucp = $prism['ucp'] ?? null;
        if ( ! is_array( $ucp ) ) {
            return null;
        }

        // Look for accepts in the nested handler config
        foreach ( $ucp as $entries ) {
            if ( ! is_array( $entries ) ) {
                continue;
            }
            foreach ( (array) $entries as $entry ) {
                if ( isset( $entry['config']['accepts'] ) ) {
                    return $entry['config']['accepts'];
                }
            }
        }

        return null;
    }

    /**
     * Validate extracted summary against stored accepts.
     */
    private static function validate_against_stored( array $summary, array $accepts ): true|WP_Error {
        foreach ( $accepts as $accept ) {
            // Match on network
            if ( $accept['network'] !== $summary['network'] ) {
                continue;
            }

            // Match on asset (case-insensitive for checksum addresses)
            if ( isset( $accept['asset'] ) && $summary['asset']
                && 0 !== strcasecmp( $accept['asset'], $summary['asset'] )
            ) {
                continue;
            }

            // Match on recipient
            if ( isset( $accept['payTo'] ) && $summary['to']
                && 0 !== strcasecmp( $accept['payTo'], $summary['to'] )
            ) {
                return new WP_Error(
                    'recipient_mismatch',
                    'Signed payment recipient does not match the expected payTo address'
                );
            }

            // Match on amount (BigInt comparison via bccomp)
            $stored_amount = (string) ( $accept['amount'] ?? '0' );
            $signed_amount = $summary['value'];

            if ( function_exists( 'bccomp' ) ) {
                $cmp = bccomp( $signed_amount, $stored_amount );
            } elseif ( function_exists( 'gmp_cmp' ) ) {
                $cmp = gmp_cmp( $signed_amount, $stored_amount );
            } else {
                $max_len = max( strlen( $signed_amount ), strlen( $stored_amount ) );
                $cmp = strcmp(
                    str_pad( $signed_amount, $max_len, '0', STR_PAD_LEFT ),
                    str_pad( $stored_amount, $max_len, '0', STR_PAD_LEFT )
                );
            }
            if ( $cmp < 0 ) {
                return new WP_Error(
                    'amount_too_low',
                    "Signed amount ($signed_amount) is less than required ($stored_amount)"
                );
            }

            return true;
        }

        // No matching accept entry found — could be a different network/asset
        return new WP_Error(
            'no_matching_accept',
            "No stored payment requirement matches network={$summary['network']}"
        );
    }

    private static function decode_to_array( $input ): ?array {
        if ( is_array( $input ) ) {
            return $input;
        }

        if ( ! is_string( $input ) ) {
            return null;
        }

        // Try base64
        $b64 = base64_decode( $input, true );
        if ( $b64 ) {
            $parsed = json_decode( $b64, true );
            if ( is_array( $parsed ) ) {
                return $parsed;
            }
        }

        // Try direct JSON
        $parsed = json_decode( $input, true );
        if ( is_array( $parsed ) ) {
            return $parsed;
        }

        return null;
    }
}
