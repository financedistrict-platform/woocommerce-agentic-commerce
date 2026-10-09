<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AgentProfileFetcherKeysTest extends TestCase {

    private const URL = 'https://agent.example/.well-known/ucp';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    private function profile( array $keys ): string {
        return json_encode( array( 'ucp' => array( 'version' => '2026-08-25' ), 'keys' => $keys ) );
    }

    public function test_parsed_profile_keeps_the_key_set(): void {
        $key     = array( 'kid' => 'k1', 'kty' => 'EC', 'crv' => 'P-256', 'x' => 'a', 'y' => 'b' );
        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( $key ) ) ) );

        $result = $fetcher->lookup( self::URL );

        $this->assertSame( array( $key ), $result['keys'] );
        $this->assertSame( '2026-08-25', $result['version'] );
    }

    public function test_profile_without_keys_yields_an_empty_set(): void {
        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => FD_Test_Fixture_Profile_Fetcher::declaring( '2026-08-25' ) ) );

        $this->assertSame( array(), $fetcher->lookup( self::URL )['keys'] );
    }

    public function test_malformed_key_set_is_reduced_to_objects(): void {
        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( 'junk', array( 'kid' => 'k1' ), 7 ) ) ) );

        $this->assertSame( array( array( 'kid' => 'k1' ) ), $fetcher->lookup( self::URL )['keys'] );
    }

    public function test_refresh_bypasses_a_cached_profile_once_per_request(): void {
        $cached = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'old' ) ) ) ) );
        $cached->lookup( self::URL );

        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'new' ) ) ) ) );
        $fetcher->refresh( self::URL );
        $fetcher->refresh( self::URL );

        $this->assertCount( 1, $fetcher->requested );
    }

    public function test_refresh_after_a_network_fetch_does_not_fetch_again(): void {
        $fetcher = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'k1' ) ) ) ) );
        $fetcher->lookup( self::URL );

        $result = $fetcher->refresh( self::URL );

        $this->assertCount( 1, $fetcher->requested );
        $this->assertSame( 'k1', $result['keys'][0]['kid'] );
    }

    public function test_refresh_replaces_the_cached_key_set(): void {
        $old = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'old' ) ) ) ) );
        $old->lookup( self::URL );

        $rotated = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'new' ) ) ) ) );
        $this->assertSame( 'old', $rotated->lookup( self::URL )['keys'][0]['kid'] );

        $this->assertSame( 'new', $rotated->refresh( self::URL )['keys'][0]['kid'] );
        $this->assertSame( 'new', $rotated->lookup( self::URL )['keys'][0]['kid'] );
    }

    public function test_failed_refresh_keeps_the_cached_profile(): void {
        $first = new FD_Test_Fixture_Profile_Fetcher( array( self::URL => $this->profile( array( array( 'kid' => 'k1' ) ) ) ) );
        $first->lookup( self::URL );

        $down = new FD_Test_Fixture_Profile_Fetcher( array() );
        $this->assertTrue( $down->refresh( self::URL )['failed'] );
        $this->assertSame( 'k1', $down->lookup( self::URL )['keys'][0]['kid'] );
    }
}
