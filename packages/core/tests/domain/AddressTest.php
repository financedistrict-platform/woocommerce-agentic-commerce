<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase {

    public function test_ucp_to_wc_maps_all_fields(): void {
        $ucp = array(
            'street_address'   => '123 Main St',
            'extended_address' => 'Suite 4',
            'address_locality' => 'San Francisco',
            'address_region'   => 'CA',
            'postal_code'      => '94105',
            'address_country'  => 'us',
        );

        $wc = FD_UCP_Address::ucp_to_wc( $ucp );

        $this->assertSame( '123 Main St', $wc['address_1'] );
        $this->assertSame( 'Suite 4', $wc['address_2'] );
        $this->assertSame( 'San Francisco', $wc['city'] );
        $this->assertSame( 'CA', $wc['state'] );
        $this->assertSame( '94105', $wc['postcode'] );
        $this->assertSame( 'US', $wc['country'] );
    }

    public function test_ucp_to_wc_handles_missing_fields(): void {
        $wc = FD_UCP_Address::ucp_to_wc( array( 'address_country' => 'HK' ) );

        $this->assertSame( '', $wc['address_1'] );
        $this->assertSame( '', $wc['address_2'] );
        $this->assertSame( '', $wc['city'] );
        $this->assertSame( 'HK', $wc['country'] );
    }

    public function test_wc_to_ucp_maps_all_fields(): void {
        $wc = array(
            'address_1' => '456 Market St',
            'address_2' => 'Floor 2',
            'city'      => 'Hong Kong',
            'state'     => 'HK',
            'postcode'  => '999077',
            'country'   => 'HK',
        );

        $ucp = FD_UCP_Address::wc_to_ucp( $wc );

        $this->assertSame( 'HK', $ucp['address_country'] );
        $this->assertSame( 'Hong Kong', $ucp['address_locality'] );
        $this->assertSame( '999077', $ucp['postal_code'] );
        $this->assertSame( '456 Market St', $ucp['street_address'] );
        $this->assertSame( 'Floor 2', $ucp['extended_address'] );
        $this->assertSame( 'HK', $ucp['address_region'] );
    }

    public function test_wc_to_ucp_omits_empty_optional_fields(): void {
        $wc = array(
            'address_1' => '',
            'address_2' => '',
            'city'      => 'London',
            'state'     => '',
            'postcode'  => 'SW1A 1AA',
            'country'   => 'GB',
        );

        $ucp = FD_UCP_Address::wc_to_ucp( $wc );

        $this->assertArrayNotHasKey( 'street_address', $ucp );
        $this->assertArrayNotHasKey( 'extended_address', $ucp );
        $this->assertArrayNotHasKey( 'address_region', $ucp );
        $this->assertSame( 'London', $ucp['address_locality'] );
    }

    public function test_roundtrip_preserves_data(): void {
        $ucp_original = array(
            'street_address'   => '1 Market St',
            'address_locality' => 'San Francisco',
            'address_region'   => 'CA',
            'postal_code'      => '94105',
            'address_country'  => 'US',
        );

        $wc  = FD_UCP_Address::ucp_to_wc( $ucp_original );
        $ucp = FD_UCP_Address::wc_to_ucp( $wc );

        $this->assertSame( $ucp_original['street_address'], $ucp['street_address'] );
        $this->assertSame( $ucp_original['address_locality'], $ucp['address_locality'] );
        $this->assertSame( $ucp_original['address_region'], $ucp['address_region'] );
        $this->assertSame( $ucp_original['postal_code'], $ucp['postal_code'] );
        $this->assertSame( $ucp_original['address_country'], $ucp['address_country'] );
    }
}
