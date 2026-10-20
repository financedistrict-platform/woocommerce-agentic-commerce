<?php
defined( 'ABSPATH' ) || exit;

class FD_Dummy_Handler implements FD_Payment_Handler {

    private const HANDLER_ID = 'xyz.fd.dummy_payment';
    private const HANDLER_NS = 'xyz.fd.dummy_payment';

    private const ALLOWED_ENVIRONMENTS = array( 'local', 'development' );

    public static function enabled(): bool {
        return defined( 'FD_DUMMY_PAYMENT_ENABLED' )
            && true === constant( 'FD_DUMMY_PAYMENT_ENABLED' )
            && in_array( wp_get_environment_type(), self::ALLOWED_ENVIRONMENTS, true );
    }

    public function id(): string {
        return self::HANDLER_ID;
    }

    public function name(): string {
        return 'Dummy Payment (Test)';
    }

    public function get_ucp_discovery_handlers(): array {
        return array();
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
        if ( ! self::enabled() ) {
            return array(
                'success' => false,
                'error'   => 'The test payment handler is disabled on this site',
            );
        }

        $prepared_amount = $input['checkout_meta'][ self::HANDLER_ID ]['prepared_amount'] ?? null;
        if ( ! is_int( $prepared_amount ) || $prepared_amount <= 0 ) {
            return array(
                'success' => false,
                'error'   => 'No stored quote for this checkout',
            );
        }

        $fake_tx = '0x' . bin2hex( random_bytes( 32 ) );

        return array(
            'success'               => true,
            'transaction_reference' => $fake_tx,
            'payment_method'        => 'fd_dummy_payment',
            'payment_method_title'  => 'Dummy Payment (Test)',
            'network'               => 'dummy:testnet',
            'settled_amount'        => $prepared_amount,
            'order_meta'            => array(
                '_fd_dummy_tx_hash' => $fake_tx,
                '_fd_dummy_network' => 'dummy:testnet',
            ),
        );
    }

    public function validate_instrument( array $instrument ): ?string {
        return null;
    }

    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $dummy_data = $payment_meta[ self::HANDLER_ID ] ?? null;
        if ( ! $dummy_data || empty( $dummy_data['ucp'] ) ) {
            return array();
        }

        return $dummy_data['ucp'];
    }
}
