<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Settlement_Summary {

    public const NET_META      = '_fd_prism_net_amount';
    public const CASHBACK_META = '_fd_prism_cashback_amount';
    public const FEE_META      = '_fd_prism_fee_amount';
    public const ASSET_META    = '_fd_prism_settlement_asset';

    public function __construct( private FD_Prism_Client $client ) {}

    public static function from_gateway(): ?self {
        $gateway = new FD_Prism_Gateway();
        if ( 'yes' !== $gateway->enabled || ! $gateway->api_url() || ! $gateway->api_key() ) {
            return null;
        }
        return new self( new FD_Prism_Client( $gateway->api_url(), $gateway->api_key() ) );
    }

    public function for_order( WC_Order $order ): ?array {
        if ( '' !== (string) $order->get_meta( self::NET_META ) ) {
            return array(
                'net'      => (string) $order->get_meta( self::NET_META ),
                'cashback' => (string) $order->get_meta( self::CASHBACK_META ),
                'fee'      => (string) $order->get_meta( self::FEE_META ),
                'asset'    => (string) $order->get_meta( self::ASSET_META ),
            );
        }

        $tx_hash = (string) $order->get_meta( '_fd_prism_tx_hash' );
        if ( '' === $tx_hash ) {
            return null;
        }

        $summary = $this->client->get_settlement_summary( $tx_hash );
        if ( ! is_array( $summary ) || 'confirmed' !== ( $summary['status'] ?? null ) ) {
            return null;
        }

        $values = array();
        foreach ( array( 'net', 'cashback', 'fee', 'asset' ) as $key ) {
            if ( ! isset( $summary[ $key ] ) || ! is_scalar( $summary[ $key ] ) || '' === (string) $summary[ $key ] ) {
                return null;
            }
            $values[ $key ] = sanitize_text_field( (string) $summary[ $key ] );
        }

        $order->update_meta_data( self::NET_META, $values['net'] );
        $order->update_meta_data( self::CASHBACK_META, $values['cashback'] );
        $order->update_meta_data( self::FEE_META, $values['fee'] );
        $order->update_meta_data( self::ASSET_META, $values['asset'] );
        $order->save_meta_data();

        return $values;
    }

    public static function refund_note( ?array $summary ): string {
        if ( null === $summary ) {
            return 'Prism settlement summary is unavailable. Check the net amount received in the Prism portal before refunding.';
        }
        return sprintf(
            'Prism settlement: received %1$s %4$s net (%2$s %4$s cashback already paid to the buyer, %3$s %4$s fees). Refund up to %1$s %4$s.',
            $summary['net'],
            $summary['cashback'],
            $summary['fee'],
            $summary['asset']
        );
    }

    public function on_return_created( WC_Order $order ): void {
        if ( '' === (string) $order->get_meta( '_fd_prism_tx_hash' ) ) {
            return;
        }
        $order->add_order_note( self::refund_note( $this->for_order( $order ) ) );
    }
}
