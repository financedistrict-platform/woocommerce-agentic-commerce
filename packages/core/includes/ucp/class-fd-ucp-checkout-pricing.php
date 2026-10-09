<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Checkout_Pricing {

    public static function priced_totals( int $subtotal, int $shipping ): array|WP_Error {
        if ( $shipping < 0 ) {
            return new WP_Error( 'invalid_fulfillment', 'Shipping cannot be priced' );
        }

        $total = $subtotal + $shipping;
        if ( $total <= 0 ) {
            return new WP_Error( 'invalid_total', 'Checkout total must be greater than zero' );
        }

        $totals = array( array( 'type' => 'subtotal', 'amount' => $subtotal ) );
        if ( $shipping > 0 ) {
            $totals[] = array( 'type' => 'fulfillment', 'amount' => $shipping );
        }
        $totals[] = array( 'type' => 'total', 'amount' => $total );

        return $totals;
    }

    public static function totals_from_session( array $session ): array|WP_Error {
        $subtotal = 0;
        foreach ( json_decode( $session['line_items'] ?? '[]', true ) ?: array() as $line_item ) {
            $subtotal += self::total_of( $line_item['totals'] ?? array() );
        }

        return self::priced_totals(
            $subtotal,
            self::selected_shipping_cost( json_decode( $session['fulfillment'] ?? 'null', true ) )
        );
    }

    public static function total_of( ?array $totals ): int {
        foreach ( $totals ?? array() as $t ) {
            if ( 'total' === ( $t['type'] ?? '' ) ) {
                return (int) $t['amount'];
            }
        }
        return 0;
    }

    public static function selected_shipping_cost( ?array $fulfillment ): int {
        $group       = $fulfillment['methods'][0]['groups'][0] ?? null;
        $selected_id = $group['selected_option_id'] ?? null;
        if ( ! $selected_id ) {
            return 0;
        }

        foreach ( $group['options'] ?? array() as $option ) {
            if ( ( $option['id'] ?? null ) === $selected_id ) {
                return self::total_of( $option['totals'] ?? array() );
            }
        }

        return 0;
    }
}
