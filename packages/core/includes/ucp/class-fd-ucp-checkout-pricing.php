<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Checkout_Pricing {

    public const MAX_QUANTITY     = 999;
    public const MAX_AMOUNT_MINOR = 100000000000;

    public static function priced_totals( int $subtotal, int $shipping, int $tax = 0 ): array|WP_Error {
        if ( $shipping < 0 || $shipping > self::MAX_AMOUNT_MINOR ) {
            return new WP_Error( 'invalid_fulfillment', 'Shipping cannot be priced' );
        }
        if ( $tax < 0 || $subtotal < 0 || $tax > self::MAX_AMOUNT_MINOR || $subtotal > self::MAX_AMOUNT_MINOR ) {
            return new WP_Error( 'invalid_total', 'Checkout amounts are outside the payable range' );
        }

        $total = $subtotal + $shipping + $tax;
        if ( $total <= 0 || $total > self::MAX_AMOUNT_MINOR ) {
            return new WP_Error( 'invalid_total', 'Checkout total must be greater than zero and within the payable range' );
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

    public static function catalog_line_items( array $items, bool $keep_ids ): array|WP_Error {
        $priced   = array();
        $reserved = array();
        $subtotal = 0;

        foreach ( $items as $item ) {
            $product_id = (int) ( $item['item']['id'] ?? 0 );
            $quantity   = self::quantity_of( $item['quantity'] ?? 1 );
            if ( null === $quantity ) {
                return new WP_Error( 'invalid_quantity', 'Quantity must be a whole number between 1 and ' . self::MAX_QUANTITY );
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! $product->is_purchasable() ) {
                return new WP_Error( 'invalid_product', "Product $product_id not found or not purchasable" );
            }

            $stock_owner              = $product->get_stock_managed_by_id();
            $reserved[ $stock_owner ] = ( $reserved[ $stock_owner ] ?? 0 ) + $quantity;
            if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $reserved[ $stock_owner ] ) ) {
                return new WP_Error( 'out_of_stock', "Product $product_id does not have enough stock" );
            }

            $price = self::payable_minor( (float) $product->get_price() );
            if ( null === $price ) {
                return new WP_Error( 'invalid_total', "Product $product_id has a price the checkout cannot quote" );
            }

            $item_total = $price * $quantity;
            $subtotal  += $item_total;
            if ( $item_total > self::MAX_AMOUNT_MINOR || $subtotal > self::MAX_AMOUNT_MINOR ) {
                return new WP_Error( 'invalid_total', 'Checkout total is outside the payable range' );
            }

            $priced[] = array(
                'id'       => $keep_ids && ! empty( $item['id'] ) ? $item['id'] : 'li_' . ( count( $priced ) + 1 ),
                'item'     => array(
                    'id'    => (string) $product_id,
                    'title' => $product->get_name(),
                    'price' => $price,
                ),
                'quantity' => $quantity,
                'totals'   => array(
                    array( 'type' => 'subtotal', 'amount' => $item_total ),
                    array( 'type' => 'total', 'amount' => $item_total ),
                ),
            );
        }

        return $priced;
    }

    public static function order_totals( WC_Order $order ): array|WP_Error {
        $subtotal = self::payable_minor( (float) $order->get_subtotal() );
        $shipping = self::payable_minor( (float) $order->get_shipping_total() );
        $tax      = self::payable_minor( (float) $order->get_total_tax() );
        $total    = self::payable_minor( (float) $order->get_total() );
        if ( null === $subtotal || null === $shipping || null === $tax || null === $total ) {
            return new WP_Error( 'invalid_total', 'Order total is outside the payable range' );
        }

        $totals = self::priced_totals( $subtotal, $shipping, $tax );
        if ( is_wp_error( $totals ) ) {
            return $totals;
        }

        if ( self::total_of( $totals ) !== $total ) {
            return new WP_Error( 'invalid_total', 'Order total includes charges the checkout cannot quote' );
        }

        return $totals;
    }

    private static function quantity_of( mixed $raw ): ?int {
        if ( is_bool( $raw ) ) {
            return null;
        }

        $quantity = filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => self::MAX_QUANTITY ) ) );

        return false === $quantity ? null : $quantity;
    }

    public static function order_total_minor( WC_Order $order ): ?int {
        return self::payable_minor( (float) $order->get_total() );
    }

    private static function payable_minor( float $amount ): ?int {
        if ( ! is_finite( $amount ) || $amount < 0 || $amount > self::MAX_AMOUNT_MINOR / 100 ) {
            return null;
        }

        return FD_UCP_Formatter::to_minor( $amount );
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
