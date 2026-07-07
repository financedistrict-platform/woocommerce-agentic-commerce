<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Order_Meta_Box {

    private const EXPLORER_URLS = array(
        'eip155:1'     => 'https://etherscan.io/tx/',
        'eip155:8453'  => 'https://basescan.org/tx/',
        'eip155:84532' => 'https://sepolia.basescan.org/tx/',
        'eip155:42161' => 'https://arbiscan.io/tx/',
        'eip155:137'   => 'https://polygonscan.com/tx/',
        'eip155:56'    => 'https://bscscan.com/tx/',
    );

    private const ADDRESS_URLS = array(
        'eip155:1'     => 'https://etherscan.io/address/',
        'eip155:8453'  => 'https://basescan.org/address/',
        'eip155:84532' => 'https://sepolia.basescan.org/address/',
        'eip155:42161' => 'https://arbiscan.io/address/',
        'eip155:137'   => 'https://polygonscan.com/address/',
        'eip155:56'    => 'https://bscscan.com/address/',
    );

    private const NETWORK_LABELS = array(
        'eip155:1'     => 'Ethereum Mainnet',
        'eip155:8453'  => 'Base',
        'eip155:84532' => 'Base Sepolia (Testnet)',
        'eip155:42161' => 'Arbitrum One',
        'eip155:137'   => 'Polygon',
        'eip155:56'    => 'BNB Chain',
    );

    public static function init(): void {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
    }

    public static function enqueue_styles( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true ) ) {
            return;
        }
        wp_enqueue_style(
            'fd-prism-meta-box',
            plugins_url( 'assets/css/prism-meta-box.css', dirname( __DIR__ ) ),
            array(),
            '0.1.0'
        );
    }

    public static function add_meta_box(): void {
        $screen = self::get_order_screen();
        if ( ! $screen ) {
            return;
        }

        add_meta_box(
            'fd-prism-payment',
            __( 'Prism Payment', 'fd-prism-for-woocommerce' ),
            array( __CLASS__, 'render' ),
            $screen,
            'side',
            'high'
        );
    }

    public static function render( $post_or_order ): void {
        $order = self::resolve_order( $post_or_order );
        if ( ! $order ) {
            return;
        }

        $tx_hash = $order->get_meta( '_fd_prism_tx_hash' );
        if ( ! $tx_hash ) {
            echo '<p style="color:#999;">' . esc_html__( 'Not a Prism payment.', 'fd-prism-for-woocommerce' ) . '</p>';
            return;
        }

        $network = $order->get_meta( '_fd_prism_network' );
        $network_label = self::NETWORK_LABELS[ $network ] ?? $network;
        $explorer_base = self::EXPLORER_URLS[ $network ] ?? null;
        $address_base  = self::ADDRESS_URLS[ $network ] ?? null;

        echo '<dl class="fd-prism-meta">';

        // Network
        $is_testnet = str_contains( $network_label, 'Testnet' ) || str_contains( $network_label, 'Sepolia' );
        $badge_class = $is_testnet ? 'fd-prism-badge--testnet' : 'fd-prism-badge--mainnet';
        echo '<dt>' . esc_html__( 'Network', 'fd-prism-for-woocommerce' ) . '</dt>';
        echo '<dd><span class="fd-prism-badge ' . esc_attr( $badge_class ) . '">' . esc_html( $network_label ) . '</span></dd>';

        // Transaction Hash
        echo '<dt>' . esc_html__( 'Transaction Hash', 'fd-prism-for-woocommerce' ) . '</dt>';
        if ( $explorer_base ) {
            $tx_url = $explorer_base . $tx_hash;
            $short  = substr( $tx_hash, 0, 10 ) . '...' . substr( $tx_hash, -8 );
            echo '<dd><a href="' . esc_url( $tx_url ) . '" target="_blank" rel="noopener">' . esc_html( $short ) . ' &#x2197;</a></dd>';
        } else {
            echo '<dd><code>' . esc_html( $tx_hash ) . '</code></dd>';
        }

        // Payer (agent fingerprint → we don't store payer address yet, but we can extract from the credential)
        $payer = $order->get_meta( '_fd_prism_payer' );
        if ( $payer && $address_base ) {
            $short_payer = substr( $payer, 0, 6 ) . '...' . substr( $payer, -4 );
            echo '<dt>' . esc_html__( 'Payer', 'fd-prism-for-woocommerce' ) . '</dt>';
            echo '<dd><a href="' . esc_url( $address_base . $payer ) . '" target="_blank" rel="noopener">' . esc_html( $short_payer ) . ' &#x2197;</a></dd>';
        } elseif ( $payer ) {
            echo '<dt>' . esc_html__( 'Payer', 'fd-prism-for-woocommerce' ) . '</dt>';
            echo '<dd><code>' . esc_html( $payer ) . '</code></dd>';
        }

        // Amount (atomic)
        $amount = $order->get_meta( '_fd_prism_amount' );
        if ( $amount ) {
            $asset  = $order->get_meta( '_fd_prism_asset' );
            $symbol = self::asset_symbol( $asset );
            $decimal = number_format( (int) $amount / 1_000_000, 6, '.', '' );
            echo '<dt>' . esc_html__( 'Settlement Amount', 'fd-prism-for-woocommerce' ) . '</dt>';
            echo '<dd>' . esc_html( $decimal ) . ' ' . esc_html( $symbol ) . '</dd>';
        }

        // Prism Reference
        $prism_ref = $order->get_meta( '_fd_prism_payment_id' );
        if ( $prism_ref ) {
            echo '<dt>' . esc_html__( 'Prism Reference', 'fd-prism-for-woocommerce' ) . '</dt>';
            echo '<dd><code class="fd-prism-ref">' . esc_html( $prism_ref ) . '</code></dd>';
        }

        echo '</dl>';
    }

    private static function get_order_screen(): ?string {
        if ( class_exists( 'Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
            $controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
            if ( method_exists( $controller, 'custom_orders_table_usage_is_enabled' ) && $controller->custom_orders_table_usage_is_enabled() ) {
                return wc_get_page_screen_id( 'shop-order' );
            }
        }
        return 'shop_order';
    }

    private static function asset_symbol( string $asset ): string {
        $known = array(
            '0x036cbd53842c5426634e7929541ec2318f3dcf7e' => 'USDC',
            '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913' => 'USDC',
            '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48' => 'USDC',
            '0xaf88d065e77c8cc2239327c5edb3a432268e5831' => 'USDC',
            '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359' => 'USDC',
            '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d' => 'USDC',
            '0xab27f55db008704ed8098f0dfbcf5e1aa387b9d9' => 'FDUSD',
        );
        return $known[ strtolower( $asset ) ] ?? ( $asset ?: 'USDC' );
    }

    private static function resolve_order( $post_or_order ): ?WC_Order {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof WP_Post ) {
            return wc_get_order( $post_or_order->ID );
        }
        return null;
    }
}
