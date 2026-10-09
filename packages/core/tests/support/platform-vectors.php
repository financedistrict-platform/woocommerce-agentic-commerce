<?php

final class FD_Test_Platform_Vectors {

    public const PROFILE = 'https://platform.example/.well-known/ucp';

    private static ?array $data = null;

    public static function data(): array {
        if ( null === self::$data ) {
            self::$data = json_decode( (string) file_get_contents( __DIR__ . '/platform-auth-vectors.json' ), true );
        }
        return self::$data;
    }

    public static function clock( int $offset = 10 ): callable {
        $now = self::data()['created'] + $offset;
        return static fn(): int => $now;
    }

    public static function profile_body( ?array $keys = null ): string {
        return json_encode( array(
            'ucp'  => array( 'version' => '2026-08-25' ),
            'keys' => $keys ?? self::data()['keys'],
        ) );
    }

    public static function fetcher( ?array $keys = null ): FD_Test_Fixture_Profile_Fetcher {
        return new FD_Test_Fixture_Profile_Fetcher( array( self::PROFILE => self::profile_body( $keys ) ) );
    }

    public static function auth( ?FD_UCP_Agent_Profile_Fetcher $fetcher = null, int $clock_offset = 10 ): FD_UCP_Platform_Auth {
        return new FD_UCP_Platform_Auth( $fetcher ?? self::fetcher(), self::clock( $clock_offset ) );
    }

    public static function signed( string $name, array $header_overrides = array(), ?string $body = null, ?string $method = null, ?string $uri = null ): WP_REST_Request {
        $vector  = self::data()[ $name ];
        $method  = $method ?? $vector['method'];
        $headers = array_merge(
            $vector['headers'],
            array( 'signature-input' => $vector['signature_input'], 'signature' => $vector['signature'] ),
            $header_overrides
        );
        return self::request( $headers, $method, $uri ?? $vector['uri'], $body ?? $vector['body'] );
    }

    public static function request( array $headers, string $method = 'GET', string $uri = '/wp-json/fd-ucp/v1/carts/x', string $body = '' ): WP_REST_Request {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $uri;
        return new WP_REST_Request( '/fd-ucp/v1/carts/x', $headers, array(), array(), $method, $body );
    }

    public static function register( string $key, string $profile = self::PROFILE, bool $enabled = true ): void {
        FD_Test_WP::$options[ FD_UCP_Platform_Auth::OPTION ][] = array(
            'profile'  => $profile,
            'key_hash' => hash( 'sha256', $key ),
            'label'    => 'test',
            'enabled'  => $enabled,
        );
    }

    public static function reset_server(): void {
        unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] );
    }
}
