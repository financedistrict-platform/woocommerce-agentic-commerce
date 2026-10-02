<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Wire_20260408 extends FD_UCP_Wire_Base {

    private const VERSION     = '2026-04-08';
    private const SPEC_BASE   = 'https://ucp.dev/' . self::VERSION . '/specification/';
    private const SCHEMA_BASE = 'https://ucp.dev/' . self::VERSION . '/schemas/shopping/';

    public function version(): string {
        return self::VERSION;
    }

    public function profile( string $endpoint, FD_Payment_Registry $registry, array $supported_versions_map ): array {
        $ucp = array(
            'version'          => self::VERSION,
            'services'         => array(
                'dev.ucp.shopping' => array(
                    array(
                        'version'   => self::VERSION,
                        'spec'      => 'https://ucp.dev/' . self::VERSION . '/specification/overview',
                        'transport' => 'rest',
                        'schema'    => 'https://ucp.dev/' . self::VERSION . '/services/shopping/rest.openapi.json',
                        'endpoint'  => $endpoint,
                    ),
                ),
            ),
            'capabilities'     => array(
                'dev.ucp.shopping.cart'            => array( $this->capability( 'cart/', 'cart.json' ) ),
                'dev.ucp.shopping.catalog.search'  => array( $this->capability( 'catalog/', 'catalog.json' ) ),
                'dev.ucp.shopping.catalog.lookup'  => array( $this->capability( 'catalog/', 'catalog.json' ) ),
                'dev.ucp.shopping.checkout'        => array( $this->capability( 'checkout/', 'checkout.json' ) ),
                'dev.ucp.shopping.fulfillment'     => array( $this->capability( 'fulfillment/', 'fulfillment.json', 'dev.ucp.shopping.checkout' ) ),
                'dev.ucp.shopping.buyer_identity'  => array( $this->capability( 'buyer-identity/', 'buyer-identity.json', 'dev.ucp.shopping.checkout' ) ),
                'dev.ucp.shopping.promotions'      => array( $this->capability( 'discount/', 'discount.json', 'dev.ucp.shopping.checkout' ) ),
                'dev.ucp.shopping.orders'          => array( $this->capability( 'order/', 'order.json' ) ),
                'dev.ucp.shopping.returns'         => array( $this->capability( 'returns/', 'returns.json', 'dev.ucp.shopping.orders' ) ),
            ),
            'payment_handlers' => $this->handler_registry( $registry->get_ucp_discovery_handlers( self::VERSION ) ),
        );

        return array(
            'ucp'          => $this->with_supported_versions( $ucp, $supported_versions_map ),
            'name'         => FD_UCP_Plugin::instance()->store_name(),
            'signing_keys' => array(),
        );
    }

    public function capabilities(): array {
        return array(
            'buyer_identity' => array( 'name' => 'dev.ucp.shopping.buyer_identity', 'extends' => 'dev.ucp.shopping.checkout' ),
            'cart'           => array( 'name' => 'dev.ucp.shopping.cart' ),
            'catalog.search' => array( 'name' => 'dev.ucp.shopping.catalog.search' ),
            'catalog.lookup' => array( 'name' => 'dev.ucp.shopping.catalog.lookup' ),
            'order'          => array( 'name' => 'dev.ucp.shopping.orders' ),
        );
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
