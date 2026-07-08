<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Status {

    /**
     * Derive UCP checkout status from session data.
     */
    public static function resolve( array $session ): string {
        if ( in_array( $session['status'], array( 'canceled', 'completed', 'expired' ), true ) ) {
            return $session['status'];
        }

        $missing = self::missing_requirements( $session );
        if ( empty( $missing ) ) {
            return 'ready_for_complete';
        }

        return 'incomplete';
    }

    /**
     * Return list of missing fields that prevent completion.
     */
    public static function missing_requirements( array $session ): array {
        $missing = array();

        $line_items = is_string( $session['line_items'] ?? null )
            ? json_decode( $session['line_items'], true )
            : ( $session['line_items'] ?? array() );

        if ( empty( $line_items ) ) {
            $missing[] = 'items';
        }

        $buyer = is_string( $session['buyer'] ?? null )
            ? json_decode( $session['buyer'], true )
            : ( $session['buyer'] ?? array() );

        if ( empty( $buyer['email'] ) ) {
            $missing[] = 'email';
        }

        $fulfillment = is_string( $session['fulfillment'] ?? null )
            ? json_decode( $session['fulfillment'], true )
            : ( $session['fulfillment'] ?? array() );

        $has_address = ! empty( $fulfillment['methods'][0]['destinations'][0]['address_country'] );
        if ( ! $has_address ) {
            $missing[] = 'shipping_address';
        }

        $group = $fulfillment['methods'][0]['groups'][0] ?? null;
        if ( $has_address && $group && ! empty( $group['options'] ) && empty( $group['selected_option_id'] ) ) {
            $missing[] = 'selected_fulfillment_option';
        }

        return $missing;
    }

    /**
     * Build UCP messages array from missing requirements.
     */
    public static function missing_messages( array $missing ): array {
        $messages = array();
        $map      = array(
            'items'            => array( 'path' => '$.line_items', 'content' => 'At least one line item is required' ),
            'email'            => array( 'path' => '$.buyer.email', 'content' => 'Buyer email is required' ),
            'shipping_address'             => array( 'path' => '$.fulfillment.methods[0].destinations[0]', 'content' => 'Shipping address is required' ),
            'selected_fulfillment_option'  => array( 'path' => '$.fulfillment.methods[0].groups[0].selected_option_id', 'content' => 'Please select a fulfillment option' ),
        );

        foreach ( $missing as $field ) {
            if ( isset( $map[ $field ] ) ) {
                $messages[] = array(
                    'type'     => 'error',
                    'code'     => "missing_$field",
                    'content'  => $map[ $field ]['content'],
                    'severity' => 'recoverable',
                    'path'     => $map[ $field ]['path'],
                );
            }
        }

        return $messages;
    }
}
