<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class AgentProfileFetcherLoopbackTest extends TestCase {

    private static $server;
    private static int $port;

    public static function setUpBeforeClass(): void {
        if ( ! function_exists( 'curl_init' ) ) {
            self::markTestSkipped( 'curl extension is required' );
        }
        $socket = stream_socket_server( 'tcp://127.0.0.1:0' );
        self::$port = (int) substr( strrchr( stream_socket_get_name( $socket, false ), ':' ), 1 );
        fclose( $socket );

        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg( PHP_BINARY ),
            self::$port,
            escapeshellarg( dirname( __DIR__ ) . '/support/loopback/profile-router.php' )
        );
        self::$server = proc_open( $command, array( 1 => array( 'null' ), 2 => array( 'null' ) ), $pipes );

        for ( $i = 0; $i < 50; $i++ ) {
            $probe = @fsockopen( '127.0.0.1', self::$port );
            if ( $probe ) {
                fclose( $probe );
                return;
            }
            usleep( 100000 );
        }
        self::fail( 'loopback server did not start' );
    }

    public static function tearDownAfterClass(): void {
        if ( is_resource( self::$server ) ) {
            $status = proc_get_status( self::$server );
            if ( PHP_OS_FAMILY === 'Windows' ) {
                exec( 'taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL' );
            } else {
                proc_terminate( self::$server );
            }
            proc_close( self::$server );
        }
    }

    protected function setUp(): void {
        FD_Test_WP::reset();
        FD_Test_WP::$live_http = true;
    }

    private function url( string $path ): string {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function test_same_origin_308_is_followed_over_real_http(): void {
        $result = ( new FD_UCP_Agent_Profile_Fetcher( true ) )->lookup( $this->url( '/p' ) );

        $this->assertSame( '2026-04-08', $result['version'] );
        $this->assertCount( 2, FD_Test_WP::$requests );
        $this->assertSame( $this->url( '/p/' ), FD_Test_WP::$requests[1]['url'] );
        $this->assertIsFloat( FD_Test_WP::$requests[1]['args']['timeout'] );
    }

    public function test_oversized_body_fails_over_real_http(): void {
        $result = ( new FD_UCP_Agent_Profile_Fetcher( true ) )->lookup( $this->url( '/big' ) );

        $this->assertSame( array( 'failed' => true ), $result );
    }

    public function test_body_of_exactly_the_cap_is_accepted_over_real_http(): void {
        $result = ( new FD_UCP_Agent_Profile_Fetcher( true ) )->lookup( $this->url( '/exact' ) );

        $this->assertSame( '2026-04-08', $result['version'] );
    }
}
