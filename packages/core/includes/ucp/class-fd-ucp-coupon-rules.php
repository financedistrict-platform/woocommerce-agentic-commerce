<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Coupon_Rules {

    public static function rejection( WC_Coupon $coupon, WC_Discounts $discounts, string $email, array $applied ): ?string {
        if ( ! $coupon->get_id() ) {
            return 'Coupon not found';
        }

        $valid = $discounts->is_coupon_valid( $coupon );
        if ( is_wp_error( $valid ) ) {
            return $valid->get_error_message() ?: 'Coupon is not valid';
        }

        $email        = strtolower( trim( $email ) );
        $restrictions = array_filter( array_map( 'strtolower', array_map( 'strval', (array) $coupon->get_email_restrictions() ) ) );
        if ( $restrictions && ! self::email_allowed( $email, $restrictions ) ) {
            return 'This coupon is restricted to specific email addresses';
        }

        $per_user = (int) $coupon->get_usage_limit_per_user();
        if ( $per_user > 0 ) {
            if ( '' === $email ) {
                return 'A buyer email is required to check the usage limit of this coupon';
            }
            if ( $coupon->get_data_store()->get_usage_by_email( $coupon, $email ) >= $per_user ) {
                return 'Coupon usage limit has been reached';
            }
        }

        foreach ( $applied as $other ) {
            if ( $coupon->get_individual_use() || $other->get_individual_use() ) {
                return 'This coupon cannot be combined with the other coupons on the order';
            }
        }

        return null;
    }

    private static function email_allowed( string $email, array $restrictions ): bool {
        if ( '' === $email ) {
            return false;
        }
        foreach ( $restrictions as $pattern ) {
            if ( fnmatch( $pattern, $email ) ) {
                return true;
            }
        }
        return false;
    }
}
