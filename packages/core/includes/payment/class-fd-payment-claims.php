<?php
defined( 'ABSPATH' ) || exit;

final class FD_Payment_Claims {

    public const KIND_AUTHORIZATION = 'authorization';
    public const KIND_TRANSACTION   = 'transaction';

    public static function key( string $kind, string $value ): string {
        return hash( 'sha256', $kind . '|' . strtolower( trim( $value ) ) );
    }

    public static function authorization_value( string $network, string $asset, string $payer, string $nonce ): string {
        return implode( '|', array( $network, $asset, $payer, $nonce ) );
    }

    public static function claim( string $kind, string $value, string $checkout_id ): bool {
        global $wpdb;

        if ( '' === trim( $value ) || '' === $checkout_id ) {
            return false;
        }

        $table = "{$wpdb->prefix}fd_ucp_payment_claims";
        $key   = self::key( $kind, $value );

        $rows = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $table (claim_key, kind, checkout_id, created_at) VALUES (%s, %s, %s, %s)",
            $key,
            $kind,
            $checkout_id,
            current_time( 'mysql', true )
        ) );
        if ( false === $rows ) {
            error_log( 'fd-ucp: payment claim store failed: ' . $wpdb->last_error );
            return false;
        }
        if ( 1 === $rows ) {
            return true;
        }

        $owner = $wpdb->get_var( $wpdb->prepare( "SELECT checkout_id FROM $table WHERE claim_key = %s", $key ) );
        return $owner === $checkout_id;
    }
}
