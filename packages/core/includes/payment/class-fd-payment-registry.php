<?php
defined( 'ABSPATH' ) || exit;

class FD_Payment_Registry {

    public const QUOTE_TTL = 900;

    private const ALIASES = array( 'x402' => 'xyz.fd.prism_payment' );

    /** @var FD_Payment_Handler[] */
    private array $handlers = array();

    public function register( FD_Payment_Handler $handler ): void {
        $this->handlers[ $handler->id() ] = $handler;
    }

    public function canonical_id( string $id ): string {
        $canonical = self::ALIASES[ $id ] ?? null;
        return ( null !== $canonical && isset( $this->handlers[ $canonical ] ) ) ? $canonical : $id;
    }

    public function get( string $id ): ?FD_Payment_Handler {
        return $this->handlers[ $this->canonical_id( $id ) ] ?? null;
    }

    public function get_ucp_discovery_handlers( ?string $version = null ): array {
        $merged = array();
        foreach ( $this->handlers as $handler ) {
            $entries_by_ns = ( null !== $version && $handler instanceof FD_Versioned_Payment_Handler )
                ? $handler->get_ucp_discovery_handlers_for_version( $version )
                : $handler->get_ucp_discovery_handlers();
            foreach ( $entries_by_ns as $ns => $entries ) {
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
            $prepared = $handler->prepare_checkout_payment( $input );
            if ( is_array( $prepared ) ) {
                $prepared['prepared_at'] ??= time();
            }
            $results[ $handler->id() ] = $prepared;
        }
        return $results;
    }

    public static function quote_is_fresh( mixed $handler_meta ): bool {
        $prepared_at = is_array( $handler_meta ) ? ( $handler_meta['prepared_at'] ?? null ) : null;
        return is_int( $prepared_at ) && time() - $prepared_at <= self::QUOTE_TTL;
    }

    public function without_stale_quotes( array $payment_meta ): array {
        foreach ( $this->handlers as $id => $handler ) {
            if ( is_array( $payment_meta[ $id ] ?? null ) && ! self::quote_is_fresh( $payment_meta[ $id ] ) ) {
                unset( $payment_meta[ $id ] );
            }
        }
        return $payment_meta;
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

    public function validate_instrument( string $handler_id, array $instrument ): ?string {
        $handler = $this->get( $handler_id );
        if ( ! $handler ) {
            return "Unknown payment handler: $handler_id";
        }
        return $handler->validate_instrument( $instrument );
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
