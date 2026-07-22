<?php
defined( 'ABSPATH' ) || exit;

class FD_Dummy_Handler implements FD_Payment_Handler {

    private const HANDLER_ID = 'xyz.fd.dummy_payment';
    private const HANDLER_NS = 'xyz.fd.dummy_payment';

    public function id(): string {
        return self::HANDLER_ID;
    }

    public function name(): string {
        return 'Dummy Payment (Test)';
    }

    public function get_ucp_discovery_handlers(): array {
        return array(
            self::HANDLER_NS => array(
                array(
                    'id'                => 'dummy',
                    'name'              => self::HANDLER_NS,
                    'version'           => '2026-01-01',
                    'type'              => 'custom',
                    'schema'            => array(),
                    'instrument_schemas' => array(),
                    'config'            => array(
                        'description' => 'Dummy payment handler for testing. Always succeeds.',
                        'accepts'     => array(
                            array(
                                'scheme'  => 'exact',
                                'network' => 'dummy:testnet',
                                'asset'   => 'DUMMY',
                                'payTo'   => '0x0000000000000000000000000000000000000000',
                            ),
                        ),
                    ),
                ),
            ),
        );
    }

    public function prepare_checkout_payment( array $input ): ?array {
        $total      = (int) $input['total'];
        $session_id = $input['checkout_id'];
        $base_url   = $input['checkout_base_url'];
        $store_name = $input['store_name'];

        $resource_url = "$base_url/checkout-sessions/$session_id";
        $order_label  = $input['order_label'] ?? 'Checkout';

        return array(
            'ucp' => array(
                self::HANDLER_NS => array(
                    array(
                        'id'      => 'dummy',
                        'version' => '2026-01-01',
                        'config'  => array(
                            'description' => "$order_label at $store_name",
                            'accepts'     => array(
                                array(
                                    'scheme'  => 'exact',
                                    'network' => 'dummy:testnet',
                                    'asset'   => 'DUMMY',
                                    'amount'  => (string) $total,
                                    'payTo'   => '0x0000000000000000000000000000000000000000',
                                ),
                            ),
                        ),
                    ),
                ),
            ),
            'prepared_amount'       => $total,
            'prepared_resource_url' => $resource_url,
        );
    }

    public function settle_payment( array $input ): array {
        $checkout_meta = $input['checkout_meta'] ?? null;
        $stored = $checkout_meta[ self::HANDLER_ID ]['ucp'][ self::HANDLER_NS ][0]['config']['accepts'][0] ?? null;

        if ( $stored ) {
            $credential = $input['credential'] ?? array();
            $submitted_amount = (string) ( $credential['amount'] ?? $credential['value'] ?? '0' );
            $required_amount  = (string) ( $stored['amount'] ?? '0' );

            if ( $required_amount !== '0' && $submitted_amount !== $required_amount ) {
                return array(
                    'success' => false,
                    'error'   => "Amount mismatch: submitted $submitted_amount, required $required_amount",
                );
            }
        }

        $fake_tx = '0x' . bin2hex( random_bytes( 32 ) );

        return array(
            'success'               => true,
            'transaction_reference' => $fake_tx,
            'payment_method'        => 'fd_dummy_payment',
            'payment_method_title'  => 'Dummy Payment (Test)',
            'network'               => 'dummy:testnet',
            'order_meta'            => array(
                '_fd_dummy_tx_hash' => $fake_tx,
                '_fd_dummy_network' => 'dummy:testnet',
            ),
        );
    }

    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $dummy_data = $payment_meta[ self::HANDLER_ID ] ?? null;
        if ( ! $dummy_data || empty( $dummy_data['ucp'] ) ) {
            return array();
        }

        return $dummy_data['ucp'];
    }
}
