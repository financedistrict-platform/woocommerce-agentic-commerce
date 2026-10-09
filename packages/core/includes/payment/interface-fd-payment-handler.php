<?php
defined( 'ABSPATH' ) || exit;

interface FD_Payment_Handler {

    public function id(): string;

    public function name(): string;

    /**
     * Return handler entries for /.well-known/ucp discovery.
     * Shape: [ '<handler_namespace>' => [ { id, version, spec, schema, config } ] ]
     */
    public function get_ucp_discovery_handlers(): array;

    /**
     * Prepare payment requirements for a checkout session.
     * Called on session create/update. Returns data to store in payment_meta,
     * or null if preparation fails.
     *
     * @param array $input {
     *   checkout_id: string, total: int (minor units), currency: string,
     *   checkout_base_url: string, store_name: string, checkout_meta: ?array
     * }
     */
    public function prepare_checkout_payment( array $input ): ?array;

    /**
     * Settle a payment using the agent's credential. Implementations should record the credential
     * with FD_Payment_Claims before contacting the gateway so one credential cannot settle two checkouts.
     * Returns [
     *   'success' => bool,
     *   'transaction_reference' => string (required; an empty or reused value puts the order on hold),
     *   'settled_amount' => int,
     *   'hold_reason' => ?string (a non-empty value puts the order on hold even when the amounts agree),
     *   'network' => ?string,
     *   'payment_method' => ?string,
     *   'payment_method_title' => ?string,
     *   'order_meta' => ?array,    // extra key-value pairs to store on the WC order
     *   'error' => ?string,
     * ]
     *
     * @param array $input { checkout_id: string, handler_id: string, credential: mixed, checkout_meta: ?array }
     */
    public function settle_payment( array $input ): array;

    public function validate_instrument( array $instrument ): ?string;

    /**
     * Return handler config for inclusion in checkout session responses.
     * Shape: [ '<handler_namespace>' => [ { id, version, config } ] ]
     */
    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array;
}
