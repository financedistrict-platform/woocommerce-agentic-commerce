<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AgentProfileFetcherTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    private function fetcher( array $ips, bool $loopback = false ): FD_UCP_Agent_Profile_Fetcher {
        return new class( $ips, $loopback ) extends FD_UCP_Agent_Profile_Fetcher {
            private array $ips;

            public function __construct( array $ips, bool $loopback ) {
                parent::__construct( $loopback );
                $this->ips = $ips;
            }

            protected function resolve_host( string $host ): array {
                return $this->ips;
            }
        };
    }

    private function respond( string $body, int $code = 200 ): void {
        FD_Test_WP::$http_response = array( 'body' => $body, 'response' => array( 'code' => $code ) );
    }

    public function test_plain_http_is_rejected_without_a_request(): void {
        $this->assertTrue( $this->fetcher( array( '93.184.216.34' ) )->lookup( 'http://agent.example/p' )['failed'] );
        $this->assertSame( array(), FD_Test_WP::$requests );
    }

    public function test_private_and_loopback_hosts_are_rejected_without_the_test_flag(): void {
        foreach ( array( '10.0.0.5', '127.0.0.1', '169.254.169.254' ) as $ip ) {
            FD_Test_WP::reset();
            $this->assertTrue( $this->fetcher( array( $ip ) )->lookup( 'https://agent.example/p' )['failed'], $ip );
            $this->assertSame( array(), FD_Test_WP::$requests, $ip );
        }
        $this->assertTrue( $this->fetcher( array() )->lookup( 'http://127.0.0.1/p' )['failed'] );
    }

    public function test_any_private_address_among_several_rejects_the_host(): void {
        $this->assertTrue( $this->fetcher( array( '93.184.216.34', '10.0.0.5' ) )->lookup( 'https://agent.example/p' )['failed'] );
    }

    public function test_loopback_is_allowed_only_with_the_test_flag(): void {
        $this->respond( json_encode( array( 'ucp' => array( 'version' => '2026-08-25' ) ) ) );

        $result = $this->fetcher( array(), true )->lookup( 'http://127.0.0.1/p' );

        $this->assertSame( '2026-08-25', $result['version'] );
    }

    public function test_public_host_is_fetched_with_hardened_arguments_and_pinned_ip(): void {
        $this->respond( json_encode( array( 'ucp' => array( 'version' => '2026-08-25' ) ) ) );

        $result = $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/p' );
        $args   = FD_Test_WP::$requests[0]['args'];

        $this->assertSame( '2026-08-25', $result['version'] );
        $this->assertSame( FD_UCP_Agent_Profile_Fetcher::TIMEOUT, $args['timeout'] );
        $this->assertSame( FD_UCP_Agent_Profile_Fetcher::MAX_BYTES, $args['limit_response_size'] );
        $this->assertSame( 0, $args['redirection'] );
        $this->assertSame( 'fd-woocommerce-ucp/' . FD_UCP_VERSION, $args['user-agent'] );
    }

    public function test_pinning_action_is_removed_after_one_use(): void {
        $this->respond( json_encode( array( 'ucp' => array( 'version' => '2026-08-25' ) ) ) );

        $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/p' );

        $this->assertSame( 0, FD_Test_WP::hook_count( 'http_api_curl' ) );
    }

    public function test_failure_is_cached_and_not_retried(): void {
        $fetcher = $this->fetcher( array( '93.184.216.34' ) );
        $this->respond( '', 503 );

        $this->assertTrue( $fetcher->lookup( 'https://agent.example/p' )['failed'] );
        $this->assertTrue( $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/p' )['failed'] );
        $this->assertCount( 1, FD_Test_WP::$requests );
    }

    public function test_success_is_cached_in_the_object_cache_only(): void {
        $this->respond( json_encode( array( 'ucp' => array( 'version' => '2026-01-23' ) ) ) );

        $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/p' );
        $second = $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/p' );

        $this->assertSame( '2026-01-23', $second['version'] );
        $this->assertCount( 1, FD_Test_WP::$requests );
        $this->assertArrayHasKey( md5( 'https://agent.example/p' ), FD_Test_WP::$cache[ FD_UCP_Agent_Profile_Fetcher::CACHE_GROUP ] );
        $this->assertSame( array(), FD_Test_WP::$transients );
        $this->assertSame( array(), FD_Test_WP::$options );
    }

    public function test_one_fetch_per_request(): void {
        $this->respond( json_encode( array( 'ucp' => array( 'version' => '2026-01-23' ) ) ) );
        $fetcher = $this->fetcher( array( '93.184.216.34' ) );

        $fetcher->lookup( 'https://a.example/p' );
        $this->assertTrue( $fetcher->lookup( 'https://b.example/p' )['failed'] );
        $this->assertCount( 1, FD_Test_WP::$requests );
    }

    public function test_oversized_or_invalid_body_fails(): void {
        $this->respond( str_repeat( ' ', FD_UCP_Agent_Profile_Fetcher::MAX_BYTES + 1 ) . '{}' );
        $this->assertTrue( $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://agent.example/big' )['failed'] );
    }

    public function test_credentials_in_url_are_rejected(): void {
        $this->assertTrue( $this->fetcher( array( '93.184.216.34' ) )->lookup( 'https://user:pw@agent.example/p' )['failed'] );
    }
}
