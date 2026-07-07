<?php
defined( 'ABSPATH' ) || exit;

class FD_Payment_Registry {

    /** @var FD_Payment_Handler[] */
    private array $handlers = array();

    public function register( FD_Payment_Handler $handler ): void {
        $this->handlers[ $handler->id() ] = $handler;
    }

    public function get( string $id ): ?FD_Payment_Handler {
        return $this->handlers[ $id ] ?? null;
    }

    public function get_ucp_discovery_handlers(): array {
        $merged = array();
        foreach ( $this->handlers as $handler ) {
            foreach ( $handler->get_ucp_discovery_handlers() as $ns => $entries ) {
                $merged[ $ns ] = array_merge( $merged[ $ns ] ?? array(), $entries );
            }
        }
        return $merged;
    }

    /**
     * Call prepare on all handlers. Returns keyed by handler id.
     */
    public function prepare_all( array $input ): array {
        $results = array();
        foreach ( $this->handlers as $handler ) {
            $results[ $handler->id() ] = $handler->prepare_checkout_payment( $input );
        }
        return $results;
    }

    /**
     * Route settlement to the matching handler.
     */
    public function settle( string $handler_id, array $input ): array {
        $handler = $this->get( $handler_id );
        if ( ! $handler ) {
            return array(
                'success' => false,
                'error'   => "Unknown payment handler: $handler_id",
            );
        }
        return $handler->settle_payment( $input );
    }

    /**
     * Merge checkout handler configs from all handlers for response formatting.
     */
    public function get_ucp_checkout_handlers( ?array $payment_meta = null ): array {
        $merged = array();
        foreach ( $this->handlers as $handler ) {
            foreach ( $handler->get_ucp_checkout_handlers( $payment_meta ) as $ns => $entries ) {
                $merged[ $ns ] = array_merge( $merged[ $ns ] ?? array(), $entries );
            }
        }
        return $merged;
    }
}
