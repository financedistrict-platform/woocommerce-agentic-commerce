<?php

final class FD_Test_Fixture_Profile_Fetcher extends FD_UCP_Agent_Profile_Fetcher {

    private array $bodies;
    public array $requested = array();

    public function __construct( array $bodies_by_url ) {
        parent::__construct();
        $this->bodies = $bodies_by_url;
    }

    public static function redirecting( ?string $location, int $code = 301 ): array {
        return array( 'code' => $code, 'location' => $location );
    }

    public static function declaring( ?string $version ): string {
        return json_encode( array( 'ucp' => null === $version ? array( 'capabilities' => array() ) : array( 'version' => $version ) ) );
    }

    protected function resolve_host( string $host ): array {
        return array( '93.184.216.34' );
    }

    protected function request( string $url, string $host, int $port, string $ip, int|float $timeout ): ?array {
        $this->requested[] = $url;
        $entry = $this->bodies[ $url ] ?? null;
        if ( null === $entry ) {
            return null;
        }
        return is_array( $entry ) ? $entry + array( 'body' => '', 'location' => null ) : array( 'code' => 200, 'body' => $entry, 'location' => null );
    }
}
