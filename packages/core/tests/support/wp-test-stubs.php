<?php

final class FD_Test_WP {

    public static array $options    = array();
    public static array $transients = array();
    public static array $cache      = array();
    public static array $hooks      = array();
    public static array $actions    = array();
    public static array $logs       = array();
    public static array $requests   = array();
    public static $http_response    = null;
    public static array $http_queue = array();

    public static function reset(): void {
        self::$options       = array();
        self::$transients    = array();
        self::$cache         = array();
        self::$hooks         = array();
        self::$actions       = array();
        self::$logs          = array();
        self::$requests      = array();
        self::$http_response = null;
        self::$http_queue    = array();
        FD_UCP_Request_Context::set( null );
    }

    public static function hook_count( string $tag ): int {
        return count( self::$hooks[ $tag ] ?? array() );
    }
}

final class FD_Test_Logger {

    public function __call( string $level, array $args ): void {
        FD_Test_WP::$logs[] = array( 'level' => $level, 'message' => $args[0] ?? '', 'context' => $args[1] ?? array() );
    }
}

function get_option( string $key, $default = false ) {
    return array_key_exists( $key, FD_Test_WP::$options ) ? FD_Test_WP::$options[ $key ] : $default;
}

function update_option( string $key, $value, $autoload = null ): bool {
    FD_Test_WP::$options[ $key ] = $value;
    return true;
}

function add_option( string $key, $value = '', $deprecated = '', $autoload = null ): bool {
    if ( array_key_exists( $key, FD_Test_WP::$options ) ) {
        return false;
    }
    FD_Test_WP::$options[ $key ] = $value;
    return true;
}

function add_rewrite_rule( string $regex, string $query, string $after = 'bottom' ): void {
}

function flush_rewrite_rules(): void {
}

function delete_option( string $key ): bool {
    unset( FD_Test_WP::$options[ $key ] );
    return true;
}

function get_transient( string $key ) {
    return FD_Test_WP::$transients[ $key ] ?? false;
}

function set_transient( string $key, $value, int $ttl = 0 ): bool {
    FD_Test_WP::$transients[ $key ] = $value;
    return true;
}

function delete_transient( string $key ): bool {
    unset( FD_Test_WP::$transients[ $key ] );
    return true;
}

function wp_cache_get( string $key, string $group = '' ) {
    return FD_Test_WP::$cache[ $group ][ $key ] ?? false;
}

function wp_cache_set( string $key, $value, string $group = '', int $ttl = 0 ): bool {
    FD_Test_WP::$cache[ $group ][ $key ] = $value;
    return true;
}

function add_action( string $tag, $callback = null, int $priority = 10, int $args = 1 ): bool {
    FD_Test_WP::$hooks[ $tag ][] = array( $callback, $priority );
    return true;
}

function add_filter( string $tag, $callback = null, int $priority = 10, int $args = 1 ): bool {
    return add_action( $tag, $callback, $priority, $args );
}

function remove_action( string $tag, $callback, int $priority = 10 ): bool {
    foreach ( FD_Test_WP::$hooks[ $tag ] ?? array() as $i => $hook ) {
        if ( $hook[0] === $callback && $hook[1] === $priority ) {
            unset( FD_Test_WP::$hooks[ $tag ][ $i ] );
            return true;
        }
    }
    return false;
}

function do_action( string $tag, ...$args ): void {
    FD_Test_WP::$actions[] = array( $tag, $args );
    foreach ( FD_Test_WP::$hooks[ $tag ] ?? array() as $hook ) {
        if ( is_callable( $hook[0] ) ) {
            call_user_func_array( $hook[0], $args );
        }
    }
}

function apply_filters( string $tag, $value, ...$args ) {
    return $value;
}

function wc_get_logger(): FD_Test_Logger {
    return new FD_Test_Logger();
}

function wp_parse_url( string $url, int $component = -1 ) {
    return parse_url( $url, $component );
}

function wp_safe_remote_get( string $url, array $args = array() ) {
    FD_Test_WP::$requests[] = array( 'url' => $url, 'args' => $args );
    do_action( 'http_api_curl', curl_init(), $args, $url );
    if ( ! empty( FD_Test_WP::$http_queue ) ) {
        return array_shift( FD_Test_WP::$http_queue );
    }
    return FD_Test_WP::$http_response ?? new WP_Error( 'http_request_failed', 'no response' );
}

function wp_remote_retrieve_header( $response, string $header ) {
    return $response['headers'][ strtolower( $header ) ] ?? '';
}

final class WP_Http {

    public static function make_absolute_url( string $maybe_relative_path, string $url ): string {
        if ( empty( $url ) ) {
            return $maybe_relative_path;
        }
        $parts = parse_url( $maybe_relative_path );
        if ( isset( $parts['scheme'] ) ) {
            return $maybe_relative_path;
        }
        $base = parse_url( $url );
        if ( ! isset( $base['scheme'] ) || ! isset( $base['host'] ) ) {
            return $maybe_relative_path;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . ( isset( $base['port'] ) ? ':' . $base['port'] : '' );
        if ( str_starts_with( $maybe_relative_path, '//' ) ) {
            return $base['scheme'] . ':' . $maybe_relative_path;
        }
        if ( str_starts_with( $maybe_relative_path, '/' ) ) {
            return $origin . $maybe_relative_path;
        }
        $dir = preg_replace( '#[^/]*$#', '', $base['path'] ?? '/' );
        return $origin . $dir . $maybe_relative_path;
    }
}

function wp_remote_get( string $url, array $args = array() ) {
    FD_Test_WP::$requests[] = array( 'url' => $url, 'args' => $args );
    if ( null !== FD_Test_WP::$http_response ) {
        return FD_Test_WP::$http_response;
    }
    $recorded = getenv( 'FD_PRISM_RECORDED' );
    return array(
        'body'     => $recorded ? file_get_contents( $recorded ) : '',
        'response' => array( 'code' => $recorded ? 200 : 500 ),
    );
}

function wp_remote_post( string $url, array $args = array() ) {
    FD_Test_WP::$requests[] = array( 'url' => $url, 'args' => $args );
    return FD_Test_WP::$http_response ?? array( 'body' => '', 'response' => array( 'code' => 500 ) );
}

function wp_json_encode( $data, int $flags = 0 ) {
    return json_encode( $data, $flags );
}

function current_time( string $type, $gmt = 0 ): string {
    return '2026-04-20 10:07:00';
}

function esc_html( string $text ): string {
    return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html__( string $text, string $domain = '' ): string {
    return esc_html( $text );
}

function __( string $text, string $domain = '' ): string {
    return $text;
}

function wc_get_product( $id ) {
    return null;
}

function wc_get_order( $id ) {
    return FD_Test_Order_Store::$orders[ (int) $id ] ?? null;
}

final class FD_Test_Order_Store {
    public static array $orders = array();
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $headers;
        private array $params;
        private array $json;
        private string $route;

        public function __construct( string $route = '/fd-ucp/v1', array $headers = array(), array $params = array(), array $json = array() ) {
            $this->route   = $route;
            $this->headers = array_change_key_case( $headers, CASE_LOWER );
            $this->params  = $params;
            $this->json    = $json;
        }

        public function get_header( string $name ): ?string {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }

        public function get_param( string $name ) {
            return $this->params[ $name ] ?? null;
        }

        public function get_json_params(): array {
            return $this->json;
        }

        public function get_route(): string {
            return $this->route;
        }
    }
}

class WC_Order {
    public array $meta  = array();
    public array $calls = array();

    public function __construct() {
        $this->meta = array(
            '_fd_ucp_tx_reference' => '0x' . str_repeat( 'ab', 32 ),
            '_fd_ucp_network'      => 'eip155:84532',
        );
    }

    public function get_id(): int {
        return 1001;
    }

    public function get_order_number(): string {
        return '1001';
    }

    public function get_view_order_url(): string {
        return 'https://store.test/my-account/view-order/1001/';
    }

    public function get_status(): string {
        return 'pending';
    }

    public function get_meta( string $key ) {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function __call( string $name, array $args ) {
        $this->calls[] = $name;
        return null;
    }
}

class WC_Order_Item_Product {
    public function __call( string $name, array $args ) {
        return null;
    }
}

class WC_Order_Item_Shipping {
    public function __call( string $name, array $args ) {
        return null;
    }
}

final class FD_Test_Wpdb {
    public string $prefix = 'wp_';
    public array $sessions = array();
    public array $updates  = array();

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare( string $query, ...$args ): string {
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    public function get_var( string $query ) {
        return '1';
    }

    public function get_row( string $query, $output = null ) {
        foreach ( $this->sessions as $id => $row ) {
            if ( false !== strpos( $query, "'" . $id . "'" ) ) {
                return $row;
            }
        }
        return null;
    }

    public function update( string $table, array $data, array $where ): int {
        $this->updates[] = array( $table, $data, $where );
        if ( isset( $this->sessions[ $where['id'] ] ) ) {
            $this->sessions[ $where['id'] ] = array_merge( $this->sessions[ $where['id'] ], $data );
        }
        return 1;
    }

    public function insert( string $table, array $data ): int {
        $this->sessions[ $data['id'] ] = $data;
        return 1;
    }
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
