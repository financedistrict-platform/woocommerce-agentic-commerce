<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Client {

    private string $api_url;
    private string $api_key;

    public function __construct( string $api_url, string $api_key ) {
        $this->api_url = rtrim( $api_url, '/' );
        $this->api_key = $api_key;
    }

    /**
     * GET /api/v2/merchant/ucp/handlers — UCP discovery entries.
     */
    public function fetch_ucp_handlers(): ?array {
        return $this->get( '/api/v2/merchant/ucp/handlers' );
    }

    /**
     * POST /api/v2/merchant/ucp/payment-requirements — UCP checkout prepare.
     * Amount in major fiat units (e.g. "15.00").
     */
    public function prepare_ucp_payment( string $amount, string $currency, string $resource_url, string $description ): ?array {
        return $this->post( '/api/v2/merchant/ucp/payment-requirements', array(
            'amount'   => $amount,
            'currency' => $currency,
            'resource' => array(
                'url'         => $resource_url,
                'description' => $description,
            ),
        ) );
    }

    /**
     * POST /api/v2/payment/settle — settle on-chain.
     */
    public function settle( array $x402_authorization ): ?array {
        $version = (int) ( $x402_authorization['x402Version']
            ?? $x402_authorization['paymentPayload']['x402Version'] ?? 2 );
        $body = array(
            'paymentPayload'      => $x402_authorization['paymentPayload'] ?? $x402_authorization,
            'paymentRequirements' => $x402_authorization['paymentRequirements'] ?? null,
        );
        return $this->post( "/api/v{$version}/payment/settle", $body );
    }

    /**
     * POST /api/v2/payment/verify — verify x402 authorization.
     */
    public function verify( array $x402_authorization ): ?array {
        $version = (int) ( $x402_authorization['x402Version'] ?? 2 );
        return $this->post( "/api/v{$version}/payment/verify", $x402_authorization );
    }

    private function get( string $path ): ?array {
        $response = wp_remote_get( $this->api_url . $path, array(
            'headers' => array(
                'X-API-Key'    => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 15,
        ) );

        return $this->parse_response( $response, "GET $path" );
    }

    private function post( string $path, array $body ): ?array {
        $response = wp_remote_post( $this->api_url . $path, array(
            'headers' => array(
                'X-API-Key'    => $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        return $this->parse_response( $response, "POST $path" );
    }

    private function parse_response( $response, string $context ): ?array {
        $logger = wc_get_logger();

        if ( is_wp_error( $response ) ) {
            $logger->error( "$context failed: " . $response->get_error_message(), array( 'source' => 'fd-prism' ) );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            $truncated = substr( $body, 0, 500 );
            $logger->error( "$context returned HTTP $code: $truncated", array( 'source' => 'fd-prism' ) );
            return null;
        }

        $decoded = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $truncated = substr( $body, 0, 500 );
            $logger->error( "$context returned invalid JSON: $truncated", array( 'source' => 'fd-prism' ) );
            return null;
        }

        return $decoded;
    }

    /**
     * Convert minor units (cents) to major unit decimal string.
     * 4500 → "45.00"
     */
    public static function minor_to_major_string( int $minor_units ): string {
        return number_format( $minor_units / 100, 2, '.', '' );
    }
}
