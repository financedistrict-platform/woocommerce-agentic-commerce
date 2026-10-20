<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Client {

    private const SETTLE_TIMEOUT = 45;

    private string $api_url;
    private string $api_key;

    public function __construct( string $api_url, string $api_key ) {
        $this->api_url = rtrim( $api_url, '/' );
        $this->api_key = $api_key;
    }

    public function fetch_ucp_handlers( string $ucp_version ): ?array {
        return $this->get( '/ucp/' . rawurlencode( $ucp_version ) . '/handlers' );
    }

    public function get_settlement_summary( string $tx_hash ): ?array {
        return $this->get( '/api/v2/merchant/payments/' . rawurlencode( $tx_hash ) . '/settlement', 5 );
    }

    public function prepare_payment_requirements( string $amount, string $currency, string $resource_url, string $description ): ?array {
        return $this->post( '/api/v2/merchant/payment-requirements', array(
            'amount'   => $amount,
            'currency' => $currency,
            'resource' => array(
                'url'         => $resource_url,
                'description' => $description,
            ),
        ) );
    }

    public function settle( int $x402_version, array $payment_payload, array $payment_requirements ): array {
        $path     = "/api/v{$x402_version}/payment/settle";
        $response = wp_remote_post( $this->api_url . $path, array(
            'headers' => $this->headers(),
            'body'    => wp_json_encode( array(
                'paymentPayload'      => $payment_payload,
                'paymentRequirements' => $payment_requirements,
            ) ),
            'timeout' => self::SETTLE_TIMEOUT,
        ) );

        if ( is_wp_error( $response ) ) {
            wc_get_logger()->error( "POST $path failed: " . $response->get_error_message(), array( 'source' => 'fd-prism' ) );
            return array( 'status' => 0, 'body' => null );
        }

        $status  = (int) wp_remote_retrieve_response_code( $response );
        $raw     = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );
        if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) ) {
            wc_get_logger()->error( "POST $path returned HTTP $status: " . substr( $raw, 0, 500 ), array( 'source' => 'fd-prism' ) );
        }

        return array(
            'status' => $status,
            'body'   => is_array( $decoded ) ? $decoded : null,
        );
    }

    private function get( string $path, int $timeout = 15 ): ?array {
        $response = wp_remote_get( $this->api_url . $path, array(
            'headers' => $this->headers(),
            'timeout' => $timeout,
        ) );

        return $this->parse_response( $response, "GET $path" );
    }

    private function post( string $path, array $body ): ?array {
        $response = wp_remote_post( $this->api_url . $path, array(
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        return $this->parse_response( $response, "POST $path" );
    }

    public function headers(): array {
        return array(
            'X-API-Key'    => $this->api_key,
            'Content-Type' => 'application/json',
        );
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
}
