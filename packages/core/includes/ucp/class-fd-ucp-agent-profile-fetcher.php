<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Agent_Profile_Fetcher {

    public const CACHE_GROUP = 'fd_ucp_profiles';
    public const CACHE_TTL   = 600;
    public const MAX_BYTES   = 131072;
    public const TIMEOUT     = 3;

    private bool $allow_loopback;
    private bool $fetched = false;

    public function __construct( bool $allow_loopback_for_tests = false ) {
        $this->allow_loopback = $allow_loopback_for_tests;
    }

    public function lookup( string $url ): array {
        $key    = md5( $url );
        $cached = wp_cache_get( $key, self::CACHE_GROUP );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        if ( $this->fetched ) {
            return array( 'failed' => true );
        }
        $this->fetched = true;

        $result = $this->fetch( $url );
        wp_cache_set( $key, $result, self::CACHE_GROUP, self::CACHE_TTL );
        return $result;
    }

    private function fetch( string $url ): array {
        $target = $this->validated_target( $url );
        if ( null === $target ) {
            return array( 'failed' => true );
        }

        $body = $this->request( $url, $target['host'], $target['port'], $target['ip'] );
        if ( null === $body || strlen( $body ) > self::MAX_BYTES ) {
            return array( 'failed' => true );
        }

        $profile = json_decode( $body, true );
        if ( ! is_array( $profile ) ) {
            return array( 'failed' => true );
        }

        $version = $profile['ucp']['version'] ?? null;
        return array(
            'version'    => is_string( $version ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) ? $version : null,
            'fetched_at' => time(),
        );
    }

    private function validated_target( string $url ): ?array {
        $parts  = wp_parse_url( $url );
        $scheme = strtolower( $parts['scheme'] ?? '' );
        $host   = strtolower( $parts['host'] ?? '' );
        if ( '' === $host || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return null;
        }

        $loopback = $this->allow_loopback && '127.0.0.1' === $host;
        if ( 'https' !== $scheme && ! ( $loopback && 'http' === $scheme ) ) {
            return null;
        }

        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : $this->resolve_host( $host );
        if ( empty( $ips ) ) {
            return null;
        }
        foreach ( $ips as $ip ) {
            if ( $loopback && '127.0.0.1' === $ip ) {
                continue;
            }
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return null;
            }
        }

        return array(
            'host' => $host,
            'port' => (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) ),
            'ip'   => $ips[0],
        );
    }

    protected function resolve_host( string $host ): array {
        $ips = gethostbynamel( $host );
        return is_array( $ips ) ? $ips : array();
    }

    protected function request( string $url, string $host, int $port, string $ip ): ?string {
        $pin = static function ( $handle ) use ( &$pin, $host, $port, $ip ): void {
            remove_action( 'http_api_curl', $pin, 10 );
            curl_setopt( $handle, CURLOPT_RESOLVE, array( "$host:$port:$ip" ) );
        };
        add_action( 'http_api_curl', $pin, 10, 1 );

        $response = wp_safe_remote_get( $url, array(
            'timeout'             => self::TIMEOUT,
            'limit_response_size' => self::MAX_BYTES,
            'redirection'         => 0,
            'user-agent'          => 'fd-woocommerce-ucp/' . FD_UCP_VERSION,
        ) );

        remove_action( 'http_api_curl', $pin, 10 );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }
        return (string) wp_remote_retrieve_body( $response );
    }
}
