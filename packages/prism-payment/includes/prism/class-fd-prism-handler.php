<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Handler implements FD_Payment_Handler, FD_Versioned_Payment_Handler {

    private const HANDLER_ID        = 'xyz.fd.prism_payment';
    private const LEGACY_HANDLER_ID = 'x402';
    private const CACHE_PREFIX      = 'fd_prism_discovery_';
    private const STALE_PREFIX      = '_fd_prism_discovery_stale_';
    private const CACHE_TTL         = 300;
    private const INSTRUMENT_TYPES  = array( 'x402', 'tokenized', 'default' );
    private const REVIEW_REASONS    = array( 'authorization_consumed', 'checkout_already_settled', 'settlement_unresolved' );
    private const UNANSWERED_REASONS = array( 'checkout_settlement_in_progress', 'settlement_pending', 'unexpected_settle_error' );
    private const HTTP_PENDING_STATUSES = array( 408, 409, 429 );
    private const REASON_NOTE_LENGTH = 200;
    private const PRE_BROADCAST_REASONS = array(
        'authorization_mismatch',
        'unsupported_network',
        'unsupported_token',
        'sanctions_hit',
        'risk_threshold_exceeded',
        'point_of_service_not_found',
        'seller_wallet_not_configured',
        'affiliate_residual_overflow',
        'affiliate_settlement_unavailable',
        'cashback_outside_campaign_bounds',
        'invalid_payment_accepted_mismatch',
        'network_not_found',
        'invalid_signature',
        'invalid_exact_evm_payload_recipient_mismatch',
        'invalid_exact_evm_payload_authorization_value',
        'invalid_exact_evm_payload_authorization_time_bounds',
        'invalid_exact_evm_payload_authorization_not_yet_valid',
        'invalid_exact_evm_payload_authorization_expired',
        'invalid_exact_evm_payload_authorization_missing_signature',
        'invalid_exact_evm_payload_authorization_signature',
        'invalid_amount_format',
        'invalid_amount_zero_or_negative',
        'invalid_amount_below_required',
        'invalid_amount_exceeds_maximum',
        'invalid_timestamp_format',
        'invalid_timestamp_order',
        'insufficient_funds',
        'balance_check_failed',
        'balance_verification_failed',
        'unauthorized',
        'invalid_authorization_recipient',
        'invalid_authorization_format',
        'quote_not_found',
        'quote_mismatch',
        'credential_already_used',
    );
    private const PRE_BROADCAST_PREFIXES = array( 'unknown_network:', 'unknown_token:', 'missing_variant_config:' );

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

        $declaration = $this->get_ucp_discovery_handlers_for_version( $version )[ self::HANDLER_ID ][0] ?? null;
        if ( ! is_array( $declaration ) ) {
            error_log( 'fd-prism: no Prism handler declaration for UCP version ' . $version . ', checkout entry omitted' );
            return null;
        }

        $amount_major = FD_UCP_Currency::to_major_string( $total, $currency );
        $order_label  = $input['order_label'] ?? 'Checkout';

        $result = $this->client->prepare_payment_requirements(
            $amount_major,
            $currency,
            $resource_url,
            "$order_label at $store_name"
        );

        $quote = FD_Prism_Validator::parse_quote( $result );
        if ( ! $quote ) {
            error_log( 'fd-prism: payment requirements response has no settleable x402 v2 quote, checkout entry omitted' );
            return null;
        }

        return array(
            'ucp'                  => array(
                self::HANDLER_ID => array(
                    array(
                        'id'      => $declaration['id'],
                        'version' => $declaration['version'],
                        'config'  => $quote,
                    ),
                ),
            ),
            'prepared_amount'      => $total,
            'prepared_resource_url' => $resource_url,
            'prepared_version'     => $version,
        );
    }


    public function settle_payment( array $input ): array {
        $verified = FD_Prism_Validator::verify(
            $input['credential'] ?? null,
            $input['checkout_meta'][ self::HANDLER_ID ]['ucp'][ self::HANDLER_ID ][0]['config'] ?? null,
            ! empty( $input['resume'] )
        );
        if ( is_wp_error( $verified ) ) {
            return array(
                'success' => false,
                'error'   => $verified->get_error_message(),
            );
        }

        $requirement   = $verified['payment_requirements'];
        $authorization = $verified['payment_payload']['payload']['authorization'];
        $claimed       = FD_Payment_Claims::claim(
            FD_Payment_Claims::KIND_AUTHORIZATION,
            FD_Payment_Claims::authorization_value( $requirement['network'], $requirement['asset'], $authorization['from'], $authorization['nonce'] ),
            (string) ( $input['checkout_id'] ?? '' )
        );
        if ( ! $claimed ) {
            return array(
                'success' => false,
                'error'   => 'This signed payment cannot be used for this checkout',
            );
        }

        $response = $this->client->settle(
            $verified['x402_version'],
            $verified['payment_payload'],
            $verified['payment_requirements']
        );
        $no_answer = $this->no_answer_reason( $response );
        if ( null !== $no_answer ) {
            return self::pending_result( $no_answer, false );
        }

        $result = $response['body'];
        if ( ! is_array( $result ) ) {
            return array(
                'success' => false,
                'error'   => 'Prism settlement request failed',
            );
        }

        $success = $result['success'] ?? null;
        $tx_ref  = $this->transaction_reference( $result );
        if ( true !== $success && ( false === $success || '' === $tx_ref ) ) {
            $reason      = $result['error'] ?? $result['errorReason'] ?? $result['reason'] ?? null;
            $code        = is_string( $reason ) ? trim( $reason ) : '';
            $checkout_id = (string) ( $input['checkout_id'] ?? '' );
            if ( $response['status'] < 300 && in_array( $code, self::REVIEW_REASONS, true ) ) {
                $this->log_settlement_response( $result, $checkout_id, 'needs review' );
                return array(
                    'success' => false,
                    'review'  => true,
                    'error'   => "Prism settlement needs review: $code",
                );
            }
            if ( $response['status'] < 300 && ! self::is_pre_broadcast_reason( $code ) ) {
                $this->log_settlement_response( $result, $checkout_id, 'unconfirmed' );
                $answered = '' !== $code && ! in_array( $code, self::UNANSWERED_REASONS, true );
                return self::pending_result( '' !== $code ? $code : 'no reason given', $answered );
            }
            $this->log_settlement_response( $result, $checkout_id, 'rejected' );
            return array(
                'success' => false,
                'error'   => is_string( $reason ) && '' !== $reason ? $reason : 'Settlement failed',
            );
        }

        $hold = $this->settlement_discrepancy( $result, $requirement, $verified['payer'], $tx_ref );
        if ( null !== $hold ) {
            $this->log_settlement_response( $result, (string) ( $input['checkout_id'] ?? '' ), 'held: ' . $hold );
        }

        $settled_payer = $result['payer'] ?? '';
        $payer         = null === $hold && is_string( $settled_payer ) && '' !== $settled_payer ? $settled_payer : $verified['payer'];

        $settlement = array(
            'success'               => true,
            'transaction_reference' => $tx_ref,
            'payment_method'        => 'fd_prism_x402',
            'payment_method_title'  => 'Prism Stablecoin',
            'network'               => $requirement['network'],
            'settled_amount'        => null === $hold ? ( $input['checkout_meta'][ self::HANDLER_ID ]['prepared_amount'] ?? null ) : null,
            'order_meta'            => array(
                '_fd_prism_tx_hash' => $tx_ref,
                '_fd_prism_network' => $requirement['network'],
                '_fd_prism_payer'   => $payer,
                '_fd_prism_asset'   => $requirement['asset'],
                '_fd_prism_amount'  => $verified['value'],
            ),
        );
        if ( null !== $hold ) {
            $settlement['hold_reason'] = $hold;
        }
        return $settlement;
    }

    private function no_answer_reason( array $response ): ?string {
        $status = $response['status'];
        if ( $status >= 400 && $status < 500 && ! in_array( $status, self::HTTP_PENDING_STATUSES, true ) ) {
            return null;
        }
        if ( 0 === $status ) {
            return 'no response';
        }
        if ( $status < 200 || $status >= 300 ) {
            return "HTTP $status";
        }
        $body = $response['body'];
        return ! is_array( $body ) || array() === $body ? 'unreadable response' : null;
    }

    private static function is_pre_broadcast_reason( string $code ): bool {
        if ( in_array( $code, self::PRE_BROADCAST_REASONS, true ) ) {
            return true;
        }
        foreach ( self::PRE_BROADCAST_PREFIXES as $prefix ) {
            if ( str_starts_with( $code, $prefix ) ) {
                return true;
            }
        }
        return false;
    }

    private static function pending_result( string $reason, bool $answered ): array {
        return array(
            'success'  => false,
            'pending'  => true,
            'answered' => $answered,
            'reason'   => substr( (string) preg_replace( '/[^\x20-\x7E]/', '', $reason ), 0, self::REASON_NOTE_LENGTH ),
            'error'    => 'Settlement outcome is not confirmed yet',
        );
    }

    private function transaction_reference( array $result ): string {
        foreach ( array( 'transaction', 'transactionHash', 'facilitatorTransactionId', 'txHash' ) as $field ) {
            if ( is_string( $result[ $field ] ?? null ) && '' !== $result[ $field ] ) {
                return $result[ $field ];
            }
        }
        return '';
    }

    private function settlement_discrepancy( array $result, array $requirement, string $signed_payer, string $tx_ref ): ?string {
        if ( true !== ( $result['success'] ?? null ) ) {
            return 'Prism reported a transaction without confirming success';
        }
        if ( '' === $tx_ref ) {
            return 'Prism settlement response has no transaction hash';
        }
        $network = $result['network'] ?? null;
        if ( $network !== $requirement['network'] ) {
            return 'Prism settlement network ' . self::describe( $network ) . ' differs from the stored ' . $requirement['network'];
        }
        $payer = $result['payer'] ?? null;
        if ( null !== $payer
            && '' !== $payer
            && ( ! is_string( $payer ) || 0 !== strcasecmp( $payer, $signed_payer ) )
        ) {
            return 'Prism settlement payer ' . self::describe( $payer ) . ' differs from the signed ' . $signed_payer;
        }
        $amount = $result['amount'] ?? null;
        if ( is_int( $amount ) ) {
            $amount = (string) $amount;
        }
        if ( null !== $amount && $amount !== $requirement['amount'] ) {
            return 'Prism settlement amount ' . self::describe( $amount ) . ' differs from the stored ' . $requirement['amount'];
        }
        return null;
    }

    private static function describe( $value ): string {
        return is_string( $value ) ? $value : gettype( $value );
    }

    private function log_settlement_response( array $result, string $checkout_id, string $outcome ): void {
        wc_get_logger()->error(
            "Settlement response $outcome for checkout $checkout_id: " . substr( (string) wp_json_encode( $result ), 0, 500 ),
            array( 'source' => 'fd-prism' )
        );
    }


    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $prism_data = $payment_meta[ self::HANDLER_ID ] ?? null;
        if ( ! $prism_data || empty( $prism_data['ucp'] ) ) {
            return array();
        }

        return $prism_data['ucp'];
    }
}
