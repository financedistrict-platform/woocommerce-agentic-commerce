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
        if ( false !== $cached && $this->contract_entry_ok( $cached ) ) {
            return $this->as_wire( $cached );
        }

        $handlers = $this->client->fetch_ucp_handlers();
        if ( $handlers ) {
            $violation = $this->contract_violation( $handlers );
            if ( null === $violation ) {
                set_transient( self::CACHE_KEY, $handlers, self::CACHE_TTL );
                update_option( '_fd_prism_discovery_stale', $handlers, false );
                return $this->as_wire( $handlers );
            }
            error_log( 'fd-prism: Prism handlers entry rejected, missing or invalid field: ' . $violation );
        }

        $stale = get_option( '_fd_prism_discovery_stale', array() );
        if ( is_array( $stale ) && $this->contract_entry_ok( $stale ) ) {
            return $this->as_wire( $stale );
        }

        return array();
    }

    private function contract_entry_ok( $handlers ): bool {
        return null === $this->contract_violation( $handlers );
    }

    private function contract_violation( $handlers ): ?string {
        $entry = is_array( $handlers ) ? ( $handlers[ self::HANDLER_ID ][0] ?? null ) : null;
        if ( ! is_array( $entry ) ) {
            return self::HANDLER_ID . '[0]';
        }
        if ( ( $entry['id'] ?? null ) !== self::HANDLER_ID ) {
            return 'id';
        }
        foreach ( array( 'version', 'schema', 'spec' ) as $field ) {
            if ( ! is_string( $entry[ $field ] ?? null ) || '' === $entry[ $field ] ) {
                return $field;
            }
        }
        return null;
    }

    private function as_wire( array $handlers ): array {
        foreach ( $handlers as $ns => $entries ) {
            foreach ( $entries as $i => $entry ) {
                if ( is_array( $entry['config'] ?? null ) ) {
                    $handlers[ $ns ][ $i ]['config'] = (object) $entry['config'];
                }
            }
        }
        return $handlers;
    }

    public function validate_instrument( array $instrument ): ?string {
        $credential      = $instrument['credential'] ?? null;
        $credential_type = is_array( $credential ) ? ( $credential['type'] ?? null ) : null;

        if ( 'x402' !== ( $instrument['type'] ?? null ) || 'x402' !== $credential_type ) {
            return 'Prism instrument and credential type must be "x402"';
        }
        return null;
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

    private function decode_credential( $credential ): ?array {
        if ( ! is_array( $credential ) ) {
            return null;
        }
        if ( is_array( $credential['authorization'] ?? null ) && ! isset( $credential['paymentPayload'] ) ) {
            return $credential['authorization'];
        }
        return $credential;
    }
}
