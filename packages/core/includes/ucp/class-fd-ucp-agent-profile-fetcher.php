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

    public function refresh( string $url ): array {
        if ( $this->fetched ) {
            return $this->lookup( $url );
        }
        $this->fetched = true;

        $result = $this->fetch( $url );
        if ( empty( $result['failed'] ) ) {
            wp_cache_set( md5( $url ), $result, self::CACHE_GROUP, self::CACHE_TTL );
        }
        return $result;
    }

    private function fetch( string $url ): array {
        $target = $this->validated_target( $url );
        if ( null === $target ) {
            return array( 'failed' => true );
        }

        $started  = microtime( true );
        $response = $this->request( $url, $target['host'], $target['port'], $target['ip'], self::TIMEOUT );
        if ( null === $response ) {
            return array( 'failed' => true );
        }

        if ( $this->is_redirect( $response['code'] ) ) {
            $response = $this->follow( $url, $target, $response, $started );
            if ( isset( $response['failed'] ) ) {
                return $response;
            }
        }

        return $this->parse( $response );
    }

    private function parse( array $response ): array {
        if ( 200 !== $response['code'] || strlen( $response['body'] ) > self::MAX_BYTES ) {
            return array( 'failed' => true );
        }

        $profile = json_decode( $response['body'], true );
        if ( ! is_array( $profile ) ) {
            return array( 'failed' => true );
        }

        $version = $profile['ucp']['version'] ?? null;
        $keys    = $profile['keys'] ?? array();
        return array(
            'version'    => is_string( $version ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $version ) ? $version : null,
            'keys'       => is_array( $keys ) ? array_values( array_filter( $keys, 'is_array' ) ) : array(),
            'fetched_at' => time(),
        );
    }

    private function follow( string $url, array $target, array $response, float $started ): array {
        $location = $this->absolute_location( $url, $response['location'] );
        if ( null === $location ) {
            return $this->redirected( null );
        }

        $hop = wp_parse_url( $location );
        if ( ! $this->is_same_target( $url, $location, $hop, $target ) ) {
            return $this->redirected( $location );
        }

        $remaining = self::TIMEOUT - ( microtime( true ) - $started );
        if ( $remaining <= 0 ) {
            return array( 'failed' => true );
        }

        $followed = $this->request( $this->rebuilt( $hop, $target['host'] ), $target['host'], $target['port'], $target['ip'], $remaining );
        if ( null === $followed ) {
            return array( 'failed' => true );
        }
        if ( $this->is_redirect( $followed['code'] ) ) {
            return $this->redirected( $this->absolute_location( $location, $followed['location'] ) );
        }
        return $followed;
    }

    private function redirected( ?string $location ): array {
        return array(
            'failed'   => true,
            'reason'   => 'redirected',
            'location' => null === $location ? null : $this->without_userinfo( $location ),
        );
    }

    private function without_userinfo( string $location ): ?string {
        $parts = wp_parse_url( $location );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return null;
        }
        return substr( $this->rebuilt( $parts, $parts['host'] ), 0, 512 );
    }

    private function rebuilt( array $parts, string $host ): string {
        return $parts['scheme'] . '://' . $host
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . ( $parts['path'] ?? '' )
            . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
    }

    private function is_redirect( int $code ): bool {
        return in_array( $code, array( 301, 302, 303, 307, 308 ), true );
    }

    private function absolute_location( string $base, ?string $location ): ?string {
        $location = preg_replace( '/[\x00-\x1F\x7F]/', '', trim( (string) $location ) );
        if ( '' === $location ) {
            return null;
        }
        $absolute = WP_Http::make_absolute_url( $location, $base );
        $hash     = strpos( $absolute, '#' );
        $absolute = false === $hash ? $absolute : substr( $absolute, 0, $hash );
        return '' === $absolute ? null : $absolute;
    }

    private function is_same_target( string $from, string $to, $hop, array $target ): bool {
        if ( ! is_array( $hop ) || isset( $hop['user'] ) || isset( $hop['pass'] ) || ! $this->same_origin( $from, $to ) ) {
            return false;
        }
        $default_port = 'https' === strtolower( $hop['scheme'] ?? '' ) ? 443 : 80;
        return (int) ( $hop['port'] ?? $default_port ) === $target['port'];
    }

    private function same_origin( string $from, string $to ): bool {
        $a = wp_parse_url( $from );
        $b = wp_parse_url( $to );
        return strtolower( $a['scheme'] ?? '' ) === strtolower( $b['scheme'] ?? '' )
            && strtolower( $a['host'] ?? '' ) === strtolower( $b['host'] ?? '' );
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

    protected function request( string $url, string $host, int $port, string $ip, int|float $timeout ): ?array {
        $pin = static function ( $handle ) use ( &$pin, $host, $port, $ip ): void {
            remove_action( 'http_api_curl', $pin, 10 );
            curl_setopt( $handle, CURLOPT_RESOLVE, array( "$host:$port:$ip" ) );
        };
        add_action( 'http_api_curl', $pin, 10, 1 );

        $response = wp_safe_remote_get( $url, array(
            'timeout'             => $timeout,
            'limit_response_size' => self::MAX_BYTES + 1,
            'redirection'         => 0,
            'user-agent'          => 'fd-woocommerce-ucp/' . FD_UCP_VERSION,
        ) );

        remove_action( 'http_api_curl', $pin, 10 );

        if ( is_wp_error( $response ) ) {
            return null;
        }
        $location = wp_remote_retrieve_header( $response, 'location' );
        return array(
            'code'     => (int) wp_remote_retrieve_response_code( $response ),
            'body'     => (string) wp_remote_retrieve_body( $response ),
            'location' => is_string( $location ) ? $location : null,
        );
    }
}
