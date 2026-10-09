<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Checkout_Pricing {

    public static function priced_totals( int $subtotal, int $shipping, int $tax = 0 ): array|WP_Error {
        if ( $shipping < 0 ) {
            return new WP_Error( 'invalid_fulfillment', 'Shipping cannot be priced' );
        }
        if ( $tax < 0 ) {
            return new WP_Error( 'invalid_total', 'Tax cannot be priced' );
        }

        $total = $subtotal + $shipping + $tax;
        if ( $total <= 0 ) {
            return new WP_Error( 'invalid_total', 'Checkout total must be greater than zero' );
        }

        $totals = array( array( 'type' => 'subtotal', 'amount' => $subtotal ) );
        if ( $shipping > 0 ) {
            $totals[] = array( 'type' => 'fulfillment', 'amount' => $shipping );
        }
        if ( $tax > 0 ) {
            $totals[] = array( 'type' => 'tax', 'amount' => $tax );
        }
        $totals[] = array( 'type' => 'total', 'amount' => $total );

        return $totals;
    }

    public static function order_totals( WC_Order $order ): array|WP_Error {
        $totals = self::priced_totals(
            FD_UCP_Formatter::to_minor( (float) $order->get_subtotal() ),
            FD_UCP_Formatter::to_minor( (float) $order->get_shipping_total() ),
            FD_UCP_Formatter::to_minor( (float) $order->get_total_tax() )
        );
        if ( is_wp_error( $totals ) ) {
            return $totals;
        }

        if ( self::total_of( $totals ) !== FD_UCP_Formatter::to_minor( (float) $order->get_total() ) ) {
            return new WP_Error( 'invalid_total', 'Order total includes charges the checkout cannot quote' );
        }

        return $totals;
    }

    public static function subtotal_of( array $line_items ): int {
        $subtotal = 0;
        foreach ( $line_items as $line_item ) {
            $subtotal += self::total_of( $line_item['totals'] ?? array() );
        }
        return $subtotal;
    }

    public static function amount_mismatch( array $amounts ): ?string {
        $expected = null;
        foreach ( $amounts as $name => $amount ) {
            if ( ! is_int( $amount ) ) {
                return "The $name amount is missing";
            }
            $expected ??= $amount;
            if ( $amount !== $expected ) {
                return 'Amounts disagree: ' . implode( ', ', array_map(
                    static fn( $n, $a ) => "$n $a",
                    array_keys( $amounts ),
                    $amounts
                ) );
            }
        }
        return null === $expected ? 'No amounts to compare' : null;
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
