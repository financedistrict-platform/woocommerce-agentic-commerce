<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Wire_20260825 extends FD_UCP_Wire_Base {

    private const VERSION = '2026-08-25';

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
                'dev.ucp.shopping.cart'            => array( $this->capability( 'specification/cart', 'schemas/shopping/cart.json' ) ),
                'dev.ucp.shopping.catalog.search'  => array( $this->capability( 'specification/catalog/search', 'schemas/shopping/catalog_search.json' ) ),
                'dev.ucp.shopping.catalog.lookup'  => array( $this->capability( 'specification/catalog/lookup', 'schemas/shopping/catalog_lookup.json' ) ),
                'dev.ucp.shopping.checkout'        => array( $this->capability( 'specification/checkout', 'schemas/shopping/checkout.json' ) ),
                'dev.ucp.shopping.fulfillment'     => array( $this->capability( 'specification/fulfillment', 'schemas/shopping/fulfillment.json' ) + array(
                    'extends' => 'dev.ucp.shopping.checkout',
                ) ),
                'dev.ucp.shopping.order'           => array( $this->capability( 'specification/order', 'schemas/shopping/order.json' ) ),
            ),
            'payment_handlers' => $this->handler_registry( $registry->get_ucp_discovery_handlers( self::VERSION ) ),
        );

        return array(
            'ucp'  => $this->with_supported_versions( $ucp, $supported_versions_map ),
            'name' => FD_UCP_Plugin::instance()->store_name(),
        );
    }

    public function capabilities(): array {
        return array(
            'buyer_identity' => array( 'name' => 'dev.ucp.shopping.buyer_identity', 'extends' => 'dev.ucp.shopping.checkout' ),
            'cart'           => array( 'name' => 'dev.ucp.shopping.cart' ),
            'catalog.search' => array( 'name' => 'dev.ucp.shopping.catalog.search' ),
            'catalog.lookup' => array( 'name' => 'dev.ucp.shopping.catalog.lookup' ),
            'order'          => array( 'name' => 'dev.ucp.shopping.order' ),
        );
    }

    private function capability( string $spec_path, string $schema_path ): array {
        $base = 'https://ucp.dev/' . self::VERSION . '/';
        return array(
            'version' => self::VERSION,
            'spec'    => $base . $spec_path,
            'schema'  => $base . $schema_path,
        );
    }

    public function cart_totals( int $subtotal ): array {
        return array(
            array( 'type' => 'subtotal', 'amount' => $subtotal ),
            array( 'type' => 'total', 'amount' => $subtotal ),
        );
    }

    protected function error_severity(): string {
        return 'unrecoverable';
    }

    protected function handler_registry( array $handlers ) {
        return $this->object_handler_registry( $handlers );
    }
}
