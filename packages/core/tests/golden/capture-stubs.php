<?php

if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '' ): string {
        return 'name' === $show ? 'Demo Store' : '';
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return 'https://store.test' . $path;
    }
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ): bool {
        return true;
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ): bool {
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( ...$args ): bool {
        return true;
    }
}

if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $tag, $value, ...$args ) {
        return $value;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) {
        return false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $ttl = 0 ): bool {
        return true;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, $value, $autoload = null ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = array() ): array {
        return array(
            'body'     => file_get_contents( getenv( 'FD_PRISM_RECORDED' ) ),
            'response' => array( 'code' => 200 ),
        );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string {
        return $response['body'];
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ): int {
        return $response['response']['code'];
    }
}

if ( ! function_exists( 'wc_get_logger' ) ) {
    function wc_get_logger(): object {
        return new class() {
            public function __call( string $name, array $args ): void {
            }
        };
    }
}

if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        public function get_id(): int {
            return 1001;
        }

        public function get_order_number(): string {
            return '1001';
        }

        public function get_view_order_url(): string {
            return 'https://store.test/my-account/view-order/1001/';
        }

        public function get_meta( string $key ) {
            return match ( $key ) {
                '_fd_ucp_tx_reference' => '0x' . str_repeat( 'ab', 32 ),
                '_fd_ucp_network'      => 'eip155:84532',
                default                => '',
            };
        }
    }
}
