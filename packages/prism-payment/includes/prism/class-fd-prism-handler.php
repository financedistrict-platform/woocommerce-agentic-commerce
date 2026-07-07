<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Handler implements FD_Payment_Handler {

    private const HANDLER_ID    = 'xyz.fd.prism_payment';
    private const CACHE_KEY     = 'fd_prism_discovery_cache';
    private const CACHE_TTL     = 300; // 5 minutes

    private FD_Prism_Client $client;

    public function __construct( string $api_url, string $api_key ) {
        $this->client = new FD_Prism_Client( $api_url, $api_key );
    }

    public function id(): string {
        return self::HANDLER_ID;
    }

    public function name(): string {
        return 'Prism Stablecoin';
    }

    // =========================================================================
    // Discovery
    // =========================================================================

    public function get_ucp_discovery_handlers(): array {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $handlers = $this->client->fetch_ucp_handlers();
        if ( ! $handlers ) {
            $stale = get_option( '_fd_prism_discovery_stale', array() );
            return $stale;
        }

        $handlers = $this->normalize_handler_entries( $handlers );

        set_transient( self::CACHE_KEY, $handlers, self::CACHE_TTL );
        update_option( '_fd_prism_discovery_stale', $handlers, false );

        return $handlers;
    }

    private function normalize_handler_entries( array $handlers ): array {
        foreach ( $handlers as $ns => &$entries ) {
            foreach ( $entries as &$entry ) {
                if ( empty( $entry['name'] ) ) {
                    $entry['name'] = $ns;
                }
                if ( isset( $entry['schema'] ) && ! isset( $entry['config_schema'] ) ) {
                    $entry['config_schema'] = $entry['schema'];
                    unset( $entry['schema'] );
                }
                if ( ! isset( $entry['instrument_schemas'] ) ) {
                    $entry['instrument_schemas'] = array();
                }
            }
        }
        return $handlers;
    }

    // =========================================================================
    // Checkout Prepare
    // =========================================================================

    public function prepare_checkout_payment( array $input ): ?array {
        $total      = (int) $input['total']; // minor units
        $currency   = $input['currency'];
        $session_id = $input['checkout_id'];
        $base_url   = $input['checkout_base_url'];
        $store_name = $input['store_name'];
        $existing   = $input['checkout_meta'][ self::HANDLER_ID ] ?? null;

        $resource_url = "$base_url/checkout-sessions/$session_id";

        // Idempotency: skip if resource URL and amount unchanged
        if ( $existing
            && ( $existing['prepared_resource_url'] ?? '' ) === $resource_url
            && ( $existing['prepared_amount'] ?? 0 ) === $total
        ) {
            return $existing;
        }

        $amount_major = FD_Prism_Client::minor_to_major_string( $total );
        $order_label  = $input['order_label'] ?? 'Checkout';

        $result = $this->client->prepare_ucp_payment(
            $amount_major,
            $currency,
            $resource_url,
            "$order_label at $store_name"
        );

        if ( ! $result ) {
            return $existing; // fall back to previous quote
        }

        return array(
            'ucp'                  => $result,
            'prepared_amount'      => $total,
            'prepared_resource_url' => $resource_url,
        );
    }

    // =========================================================================
    // Settlement
    // =========================================================================

    public function settle_payment( array $input ): array {
        $credential = $input['credential'];

        // The credential may be a base64 string or a structured object
        $authorization = $this->decode_credential( $credential );
        if ( ! $authorization ) {
            return array(
                'success' => false,
                'error'   => 'Invalid x402 credential format',
            );
        }

        $validation = FD_Prism_Validator::validate_credential(
            $authorization,
            $input['checkout_meta'] ?? null
        );
        if ( is_wp_error( $validation ) ) {
            return array(
                'success' => false,
                'error'   => $validation->get_error_message(),
            );
        }

        $result = $this->client->settle( $authorization );
        if ( ! $result ) {
            return array(
                'success' => false,
                'error'   => 'Prism settlement request failed',
            );
        }

        // Normalize transaction reference across field name variants
        $tx_ref = $result['transaction'] ?? $result['transactionHash']
            ?? $result['facilitatorTransactionId'] ?? $result['txHash'] ?? '';

        $success = $result['success'] ?? ( ! empty( $tx_ref ) );

        if ( ! $success ) {
            return array(
                'success' => false,
                'error'   => $result['error'] ?? $result['errorReason'] ?? $result['reason'] ?? 'Settlement failed',
            );
        }

        $network = $result['network']
            ?? $authorization['paymentPayload']['accepted']['network']
            ?? $authorization['paymentPayload']['network'] ?? '';

        $payer_info = FD_Prism_Validator::extract_signed_summary( $authorization );

        $prism_payment_id = $result['facilitatorTransactionId']
            ?? $result['paymentId'] ?? $result['id'] ?? '';

        $order_meta = array(
            '_fd_prism_tx_hash'    => $tx_ref,
            '_fd_prism_network'    => $network,
            '_fd_prism_payment_id' => $prism_payment_id,
        );
        if ( $payer_info ) {
            $order_meta['_fd_prism_payer']  = $payer_info['to'] ?? '';
            $order_meta['_fd_prism_asset']  = $payer_info['asset'] ?? '';
            $order_meta['_fd_prism_amount'] = $payer_info['value'] ?? '';
        }

        return array(
            'success'               => true,
            'transaction_reference' => $tx_ref,
            'payment_method'        => 'fd_prism_x402',
            'payment_method_title'  => 'Prism Stablecoin',
            'network'               => $network,
            'order_meta'            => $order_meta,
        );
    }

    // =========================================================================
    // Checkout Response
    // =========================================================================

    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $prism_data = $payment_meta[ self::HANDLER_ID ] ?? null;
        if ( ! $prism_data || empty( $prism_data['ucp'] ) ) {
            return array();
        }

        // The UCP prepare response is already in the right shape
        return $prism_data['ucp'];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Decode an x402 credential from various wire formats.
     * Returns the full x402 authorization object or null.
     */
    private function decode_credential( $credential ): ?array {
        if ( is_string( $credential ) ) {
            // Try base64 decode
            $decoded = base64_decode( $credential, true );
            if ( $decoded ) {
                $parsed = json_decode( $decoded, true );
                if ( is_array( $parsed ) ) {
                    return $parsed;
                }
            }
            // Try direct JSON
            $parsed = json_decode( $credential, true );
            if ( is_array( $parsed ) ) {
                return $parsed;
            }
            return null;
        }

        if ( is_array( $credential ) ) {
            // If it has paymentPayload, it's already the full authorization
            if ( isset( $credential['paymentPayload'] ) ) {
                return $credential;
            }
            // If it has an authorization field, extract it
            if ( isset( $credential['authorization'] ) ) {
                return $this->decode_credential( $credential['authorization'] );
            }
            return $credential;
        }

        return null;
    }
}
