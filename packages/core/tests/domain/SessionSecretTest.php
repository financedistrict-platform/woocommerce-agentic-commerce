<?php

use PHPUnit\Framework\TestCase;

final class SessionSecretTest extends TestCase {

    public function test_issue_returns_an_unpadded_url_safe_secret_and_its_sha256_hash(): void {
        $issued = FD_UCP_Session_Secret::issue();

        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $issued['secret'] );
        $this->assertSame( hash( 'sha256', $issued['secret'] ), $issued['hash'] );
    }

    public function test_two_issues_differ(): void {
        $this->assertNotSame( FD_UCP_Session_Secret::issue()['secret'], FD_UCP_Session_Secret::issue()['secret'] );
    }

    public function test_the_issued_secret_matches_its_hash(): void {
        $issued = FD_UCP_Session_Secret::issue();

        $this->assertTrue( FD_UCP_Session_Secret::matches( $issued['hash'], $issued['secret'] ) );
    }

    public function test_a_wrong_or_empty_secret_does_not_match(): void {
        $issued = FD_UCP_Session_Secret::issue();

        $this->assertFalse( FD_UCP_Session_Secret::matches( $issued['hash'], 'wrong' ) );
        $this->assertFalse( FD_UCP_Session_Secret::matches( $issued['hash'], '' ) );
    }

    public function test_an_empty_or_malformed_stored_hash_never_matches(): void {
        $issued = FD_UCP_Session_Secret::issue();

        $this->assertFalse( FD_UCP_Session_Secret::matches( '', $issued['secret'] ) );
        $this->assertFalse( FD_UCP_Session_Secret::matches( 'garbage', $issued['secret'] ) );
        $this->assertFalse( FD_UCP_Session_Secret::matches( strtoupper( $issued['hash'] ), $issued['secret'] ) );
    }
}
