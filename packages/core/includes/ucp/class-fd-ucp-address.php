<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Address {

    private const ALIASES = array(
        'country'          => 'address_country',
        'address_country'  => 'address_country',
        'city'             => 'address_locality',
        'address_locality' => 'address_locality',
        'state'            => 'address_region',
        'region'           => 'address_region',
        'address_region'   => 'address_region',
        'zip'              => 'postal_code',
        'zipcode'          => 'postal_code',
        'zip_code'         => 'postal_code',
        'postcode'         => 'postal_code',
        'postal_code'      => 'postal_code',
        'street'           => 'street_address',
        'address_1'        => 'street_address',
        'address1'         => 'street_address',
        'street_address'   => 'street_address',
        'address_2'        => 'extended_address',
        'address2'         => 'extended_address',
        'extended_address' => 'extended_address',
    );

    /**
     * Normalize an address from any common format to UCP schema.org keys.
     */
    public static function normalize( array $input ): array {
        $addr = $input['address'] ?? $input;

        $normalized = array();
        foreach ( $addr as $key => $value ) {
            $canonical = self::ALIASES[ strtolower( $key ) ] ?? null;
            if ( $canonical && ! isset( $normalized[ $canonical ] ) ) {
                $normalized[ $canonical ] = $value;
            }
        }

        foreach ( $input as $key => $value ) {
            if ( $key === 'address' || isset( $normalized[ $key ] ) ) {
                continue;
            }
            $canonical = self::ALIASES[ strtolower( $key ) ] ?? null;
            if ( $canonical && ! isset( $normalized[ $canonical ] ) ) {
                $normalized[ $canonical ] = $value;
            }
        }

        if ( ! empty( $input['id'] ) ) {
            $normalized['id'] = $input['id'];
        }

        return $normalized;
    }

    /**
     * UCP address → WC order address fields.
     */
    public static function ucp_to_wc( array $ucp ): array {
        return array(
            'address_1' => $ucp['street_address'] ?? '',
            'address_2' => $ucp['extended_address'] ?? '',
            'city'      => $ucp['address_locality'] ?? '',
            'state'     => $ucp['address_region'] ?? '',
            'postcode'  => $ucp['postal_code'] ?? '',
            'country'   => strtoupper( $ucp['address_country'] ?? '' ),
        );
    }

    /**
     * WC order address → UCP address.
     */
    public static function wc_to_ucp( array $wc ): array {
        $addr = array(
            'address_country'  => $wc['country'] ?? '',
            'address_locality' => $wc['city'] ?? '',
            'postal_code'      => $wc['postcode'] ?? '',
        );

        if ( ! empty( $wc['address_1'] ) ) {
            $addr['street_address'] = $wc['address_1'];
        }
        if ( ! empty( $wc['address_2'] ) ) {
            $addr['extended_address'] = $wc['address_2'];
        }
        if ( ! empty( $wc['state'] ) ) {
            $addr['address_region'] = $wc['state'];
        }

        return $addr;
    }
}
