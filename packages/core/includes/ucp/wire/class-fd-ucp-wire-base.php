<?php
defined( 'ABSPATH' ) || exit;

abstract class FD_UCP_Wire_Base implements FD_UCP_Wire_Format {

    abstract public function version(): string;

    public function checkout_session( array $session, FD_Payment_Registry $registry ): array {
        $line_items   = $this->decode_json( $session['line_items'] ?? '[]' );
        $totals       = $this->decode_json( $session['totals'] ?? '[]' );
        $buyer        = $this->decode_json( $session['buyer'] ?? 'null' );
        $fulfillment  = $this->decode_json( $session['fulfillment'] ?? 'null' );
        $payment_meta = $this->decode_json( $session['payment_meta'] ?? 'null' );

        $status   = FD_UCP_Status::resolve( $session );
        $missing  = FD_UCP_Status::missing_requirements( $session );
        $messages = FD_UCP_Status::missing_messages( $missing );
        $messages = apply_filters( 'fd_ucp_checkout_messages', $messages, $session );

        $response = array(
            'ucp'        => array(
                'version'          => $this->version(),
                'status'           => 'success',
                'capabilities'     => array(
                    'dev.ucp.shopping.checkout'    => array( array( 'version' => $this->version() ) ),
                    'dev.ucp.shopping.fulfillment' => array( array(
                        'version' => $this->version(),
                        'extends' => 'dev.ucp.shopping.checkout',
                    ) ),
                ),
                'payment_handlers' => $this->handler_registry( $registry->get_ucp_checkout_handlers( $payment_meta ) ),
            ),
            'id'         => $session['id'],
            'status'     => $status,
            'currency'   => $session['currency'] ?? 'USD',
            'line_items' => $line_items,
            'totals'     => $totals,
            'messages'   => $messages,
            'links'      => array(),
        );

        if ( $buyer ) {
            $response['buyer'] = $buyer;
        }
        if ( $fulfillment ) {
            $response['fulfillment'] = $fulfillment;
        }
        if ( ! empty( $session['expires_at'] ) ) {
            $response['expires_at'] = $session['expires_at'];
        }

        return $response;
    }

    public function complete_response( array $session, WC_Order $order, FD_Payment_Registry $registry ): array {
        $response           = $this->checkout_session( $session, $registry );
        $response['status'] = 'completed';

        $response['order'] = array(
            'id'            => (string) $order->get_id(),
            'label'         => $order->get_order_number(),
            'permalink_url' => $order->get_view_order_url(),
        );

        $tx_ref = $order->get_meta( '_fd_ucp_tx_reference' );
        if ( $tx_ref ) {
            $response['order']['transaction_reference'] = $tx_ref;
            $network = $order->get_meta( '_fd_ucp_network' );
            if ( $network ) {
                $response['order']['network'] = $network;
            }
        }

        return $response;
    }

    public function envelope( array $capabilities ): array {
        $declared = $this->capabilities();
        $block    = array();
        foreach ( $capabilities as $logical ) {
            if ( ! isset( $declared[ $logical ] ) ) {
                continue;
            }
            $entry = array( 'version' => $this->version() );
            if ( ! empty( $declared[ $logical ]['extends'] ) ) {
                $entry['extends'] = $declared[ $logical ]['extends'];
            }
            $block[ $declared[ $logical ]['name'] ] = array( $entry );
        }

        $envelope = array(
            'version' => $this->version(),
            'status'  => 'success',
        );
        if ( $block ) {
            $envelope['capabilities'] = $block;
        }
        return $envelope;
    }

    public function cart_totals( int $subtotal ): array {
        return array(
            array( 'type' => 'subtotal', 'amount' => $subtotal ),
        );
    }

    public function error( string $code, string $message ): array {
        return array(
            'ucp'      => array(
                'version' => $this->version(),
                'status'  => 'error',
            ),
            'messages' => array(
                array(
                    'type'     => 'error',
                    'code'     => $code,
                    'content'  => $message,
                    'severity' => $this->error_severity(),
                ),
            ),
        );
    }

    public function supports( string $logical ): bool {
        return isset( $this->capabilities()[ $logical ] );
    }

    protected function error_severity(): string {
        return 'fatal';
    }

    protected function handler_registry( array $handlers ) {
        return $handlers;
    }

    protected function object_handler_registry( array $handlers ) {
        if ( empty( $handlers ) ) {
            return new stdClass();
        }
        foreach ( $handlers as $ns => $entries ) {
            foreach ( $entries as $i => $entry ) {
                if ( is_array( $entry['config'] ?? null ) ) {
                    $handlers[ $ns ][ $i ]['config'] = (object) $entry['config'];
                }
            }
        }
        return $handlers;
    }

    protected function with_supported_versions( array $ucp, array $supported_versions_map ): array {
        if ( empty( $supported_versions_map ) ) {
            return $ucp;
        }
        $result = array();
        foreach ( $ucp as $key => $value ) {
            $result[ $key ] = $value;
            if ( 'version' === $key ) {
                $result['supported_versions'] = $supported_versions_map;
            }
        }
        return $result;
    }

    protected function decode_json( string $json ) {
        $decoded = json_decode( $json, true );
        return ( json_last_error() === JSON_ERROR_NONE ) ? $decoded : null;
    }
}
