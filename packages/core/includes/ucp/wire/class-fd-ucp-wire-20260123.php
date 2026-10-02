<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Wire_20260123 extends FD_UCP_Wire_Base {

    private const VERSION     = '2026-01-23';
    private const SPEC_BASE   = 'https://ucp.dev/' . self::VERSION . '/specification/';
    private const SCHEMA_BASE = 'https://ucp.dev/' . self::VERSION . '/schemas/shopping/';

    private const LATER_HANDLER_FIELDS = array( 'available_instruments' );

    public function version(): string {
        return self::VERSION;
    }

    public function profile( string $endpoint, FD_Payment_Registry $registry, array $supported_versions_map ): array {
        return array(
            'ucp'          => array(
                'version'          => self::VERSION,
                'services'         => array(
                    'dev.ucp.shopping' => array(
                        array(
                            'version'   => self::VERSION,
                            'spec'      => 'https://ucp.dev/' . self::VERSION . '/specification/overview',
                            'transport' => 'rest',
                            'schema'    => 'https://ucp.dev/' . self::VERSION . '/services/shopping/openapi.json',
                            'endpoint'  => $endpoint,
                        ),
                    ),
                ),
                'capabilities'     => array(
                    'dev.ucp.shopping.checkout'    => array( $this->capability( 'checkout/', 'checkout.json' ) ),
                    'dev.ucp.shopping.fulfillment' => array( $this->capability( 'fulfillment/', 'fulfillment.json', 'dev.ucp.shopping.checkout' ) ),
                    'dev.ucp.shopping.order'       => array( $this->capability( 'order/', 'order.json' ) ),
                ),
                'payment_handlers' => $this->handler_registry( $this->without_later_fields( $registry->get_ucp_discovery_handlers( self::VERSION ) ) ),
            ),
            'name'         => FD_UCP_Plugin::instance()->store_name(),
            'signing_keys' => array(),
        );
    }

    public function checkout_session( array $session, FD_Payment_Registry $registry ): array {
        $response = parent::checkout_session( $session, $registry );
        unset( $response['signals'], $response['attribution'] );
        return $response;
    }

    public function error( string $code, string $message ): array {
        return array(
            'ucp'      => array( 'version' => self::VERSION ),
            'status'   => 'requires_escalation',
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

    public function capabilities(): array {
        return array( 'order' => array( 'name' => 'dev.ucp.shopping.order' ) );
    }

    protected function error_severity(): string {
        return 'requires_buyer_input';
    }

    protected function handler_registry( array $handlers ) {
        return $this->object_handler_registry( $handlers );
    }

    private function without_later_fields( array $handlers ): array {
        foreach ( $handlers as $ns => $entries ) {
            foreach ( $entries as $i => $entry ) {
                foreach ( self::LATER_HANDLER_FIELDS as $field ) {
                    unset( $handlers[ $ns ][ $i ][ $field ] );
                }
            }
        }
        return $handlers;
    }

    private function capability( string $spec_path, string $schema_file, ?string $extends = null ): array {
        $capability = array(
            'version' => self::VERSION,
            'spec'    => self::SPEC_BASE . $spec_path,
            'schema'  => self::SCHEMA_BASE . $schema_file,
        );
        if ( null !== $extends ) {
            $capability['extends'] = $extends;
        }
        return $capability;
    }
}
