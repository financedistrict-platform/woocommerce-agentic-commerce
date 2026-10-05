<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Handler implements FD_Payment_Handler, FD_Versioned_Payment_Handler {

    private const HANDLER_ID        = 'xyz.fd.prism_payment';
    private const LEGACY_HANDLER_ID = 'x402';
    private const CACHE_PREFIX      = 'fd_prism_discovery_';
    private const STALE_PREFIX      = '_fd_prism_discovery_stale_';
    private const CACHE_TTL         = 300;
    private const INSTRUMENT_TYPES  = array( 'x402', 'tokenized', 'default' );

    private FD_Prism_Client $client;
    private string $api_url;

    public function __construct( string $api_url, string $api_key ) {
        $this->api_url = $api_url;
        $this->client  = new FD_Prism_Client( $api_url, $api_key );
    }

    public function id(): string {
        return self::HANDLER_ID;
    }

    public function name(): string {
        return 'Prism Stablecoin';
    }


    public static function cache_key( string $api_url, string $ucp_version ): string {
        return self::CACHE_PREFIX . md5( $api_url . '|' . $ucp_version );
    }

    public static function stale_key( string $api_url, string $ucp_version ): string {
        return self::STALE_PREFIX . md5( $api_url . '|' . $ucp_version );
    }

    public function get_ucp_discovery_handlers(): array {
        return $this->get_ucp_discovery_handlers_for_version( FD_UCP_Request_Context::current()->version() );
    }

    public function get_ucp_discovery_handlers_for_version( string $ucp_version ): array {
        $cache_key = self::cache_key( $this->api_url, $ucp_version );
        $stale_key = self::stale_key( $this->api_url, $ucp_version );

        $cached = get_transient( $cache_key );
        if ( false !== $cached ) {
            $canonical = $this->canonical_handlers( $cached );
            if ( null !== $canonical ) {
                return $canonical;
            }
        }

        $fetched = $this->client->fetch_ucp_handlers( $ucp_version );
        if ( $fetched ) {
            $canonical = $this->canonical_handlers( $fetched );
            if ( null !== $canonical ) {
                set_transient( $cache_key, $fetched, self::CACHE_TTL );
                update_option( $stale_key, $fetched, false );
                return $canonical;
            }
            error_log( 'fd-prism: Prism handlers entry rejected, missing or invalid field: ' . $this->contract_violation( $fetched ) );
        }

        $stale     = get_option( $stale_key, array() );
        $canonical = is_array( $stale ) ? $this->canonical_handlers( $stale ) : null;
        return $canonical ?? array();
    }

    private function canonical_handlers( $handlers ): ?array {
        if ( null !== $this->contract_violation( $handlers ) ) {
            return null;
        }
        foreach ( $handlers as $ns => $entries ) {
            foreach ( $entries as $i => $entry ) {
                $handlers[ $ns ][ $i ] = $this->canonical_entry( $entry, (string) $ns );
            }
        }
        return $handlers;
    }

    private function canonical_entry( array $entry, string $ns ): array {
        if ( self::HANDLER_ID === $ns && self::LEGACY_HANDLER_ID === ( $entry['id'] ?? null ) ) {
            $entry['id'] = self::HANDLER_ID;
        }
        if ( ! isset( $entry['schema'] ) && isset( $entry['config_schema'] ) ) {
            $entry['schema'] = $entry['config_schema'];
        }
        if ( ! isset( $entry['instrument_schemas'] ) ) {
            $entry['instrument_schemas'] = array();
        }
        if ( empty( $entry['name'] ) ) {
            unset( $entry['name'] );
            $entry['name'] = $ns;
        }
        return $entry;
    }

    private function contract_violation( $handlers ): ?string {
        $entry = is_array( $handlers ) ? ( $handlers[ self::HANDLER_ID ][0] ?? null ) : null;
        if ( ! is_array( $entry ) ) {
            return self::HANDLER_ID . '[0]';
        }
        if ( ! in_array( $entry['id'] ?? null, array( self::HANDLER_ID, self::LEGACY_HANDLER_ID ), true ) ) {
            return 'id';
        }
        foreach ( array( 'version', 'spec' ) as $field ) {
            if ( ! is_string( $entry[ $field ] ?? null ) || '' === $entry[ $field ] ) {
                return $field;
            }
        }
        $schema = $entry['schema'] ?? $entry['config_schema'] ?? null;
        if ( ! is_string( $schema ) || '' === $schema ) {
            return 'schema';
        }
        return null;
    }

    public function validate_instrument( array $instrument ): ?string {
        $type = $instrument['type'] ?? null;
        if ( null !== $type && ! in_array( $type, self::INSTRUMENT_TYPES, true ) ) {
            return 'Prism instrument type must be "x402"';
        }

        $credential = $instrument['credential'] ?? null;
        if ( is_array( $credential ) && null !== ( $credential['type'] ?? null ) && 'x402' !== $credential['type'] ) {
            return 'Prism credential type must be "x402"';
        }
        return null;
    }


    public function prepare_checkout_payment( array $input ): ?array {
        $total      = (int) $input['total'];
        $currency   = $input['currency'];
        $session_id = $input['checkout_id'];
        $base_url   = $input['checkout_base_url'];
        $store_name = $input['store_name'];
        $existing   = $input['checkout_meta'][ self::HANDLER_ID ] ?? null;

        $resource_url = "$base_url/checkout-sessions/$session_id";
        $version      = FD_UCP_Request_Context::current()->version();

        if ( $existing
            && ( $existing['prepared_resource_url'] ?? '' ) === $resource_url
            && ( $existing['prepared_amount'] ?? 0 ) === $total
            && ( $existing['prepared_version'] ?? '' ) === $version
        ) {
            return $existing;
        }

        $amount_major = FD_Prism_Client::minor_to_major_string( $total );
        $order_label  = $input['order_label'] ?? 'Checkout';

        $result = $this->client->prepare_ucp_payment(
            $amount_major,
            $currency,
            $resource_url,
            "$order_label at $store_name",
            $version
        );

        if ( ! $result ) {
            return $existing;
        }

        return array(
            'ucp'                  => $result,
            'prepared_amount'      => $total,
            'prepared_resource_url' => $resource_url,
            'prepared_version'     => $version,
        );
    }


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

        $settled_payer = $result['payer'] ?? '';

        $order_meta = array(
            '_fd_prism_tx_hash' => $tx_ref,
            '_fd_prism_network' => $network,
            '_fd_prism_payer'   => is_string( $settled_payer ) && '' !== $settled_payer
                ? $settled_payer
                : ( $payer_info['from'] ?? '' ),
        );
        if ( $payer_info ) {
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


    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $prism_data = $payment_meta[ self::HANDLER_ID ] ?? null;
        if ( ! $prism_data || empty( $prism_data['ucp'] ) ) {
            return array();
        }

        return $prism_data['ucp'];
    }


    private function decode_credential( $credential ): ?array {
        if ( is_string( $credential ) ) {
            $decoded = base64_decode( $credential, true );
            if ( $decoded ) {
                $parsed = json_decode( $decoded, true );
                if ( is_array( $parsed ) ) {
                    return $parsed;
                }
            }
            $parsed = json_decode( $credential, true );
            return is_array( $parsed ) ? $parsed : null;
        }

        if ( ! is_array( $credential ) ) {
            return null;
        }
        if ( isset( $credential['authorization'] ) && ! isset( $credential['paymentPayload'] ) ) {
            return $this->decode_credential( $credential['authorization'] );
        }
        return $credential;
    }
}
