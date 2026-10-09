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
    public static bool $live_http   = false;
    public static array $uuids      = array();
    public static array $routes     = array();
    public static string $environment = 'production';

    public static function reset(): void {
        self::$routes        = array();
        self::$uuids         = array();
        self::$options       = array();
        self::$transients    = array();
        self::$cache         = array();
        self::$hooks         = array();
        self::$actions       = array();
        self::$logs          = array();
        self::$requests      = array();
        self::$http_response = null;
        self::$http_queue    = array();
        self::$live_http     = false;
        self::$environment   = 'production';
        FD_UCP_Request_Context::set( null );
        WC_Admin_Settings::reset();
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

function register_rest_route( string $namespace, string $route, array $args = array() ): bool {
    foreach ( isset( $args['methods'] ) ? array( $args ) : $args as $endpoint ) {
        FD_Test_WP::$routes[] = array( 'route' => $route, 'methods' => $endpoint['methods'], 'callback' => $endpoint['callback'], 'permission_callback' => $endpoint['permission_callback'] ?? null );
    }
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

function wp_get_environment_type(): string {
    return FD_Test_WP::$environment;
}

function plugin_dir_path( string $file ): string {
    return dirname( $file ) . '/';
}

function wc_get_logger(): FD_Test_Logger {
    return new FD_Test_Logger();
}

function wp_parse_url( string $url, int $component = -1 ) {
    return parse_url( $url, $component );
}

function wp_safe_remote_get( string $url, array $args = array() ) {
    FD_Test_WP::$requests[] = array( 'url' => $url, 'args' => $args );
    if ( FD_Test_WP::$live_http ) {
        return fd_test_live_get( $url, $args );
    }
    do_action( 'http_api_curl', curl_init(), $args, $url );
    if ( ! empty( FD_Test_WP::$http_queue ) ) {
        return array_shift( FD_Test_WP::$http_queue );
    }
    return FD_Test_WP::$http_response ?? new WP_Error( 'http_request_failed', 'no response' );
}

function fd_test_live_get( string $url, array $args ) {
    $headers = array();
    $body    = '';
    $limit   = (int) ( $args['limit_response_size'] ?? 0 );
    $handle  = curl_init( $url );
    curl_setopt_array( $handle, array(
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT_MS     => (int) round( ( (float) ( $args['timeout'] ?? 5 ) ) * 1000 ),
        CURLOPT_USERAGENT      => $args['user-agent'] ?? '',
        CURLOPT_HEADERFUNCTION => static function ( $h, string $line ) use ( &$headers ): int {
            if ( str_contains( $line, ':' ) ) {
                list( $name, $value ) = explode( ':', $line, 2 );
                $headers[ strtolower( trim( $name ) ) ] = trim( $value );
            }
            return strlen( $line );
        },
        CURLOPT_WRITEFUNCTION  => static function ( $h, string $chunk ) use ( &$body, $limit ): int {
            $length = strlen( $chunk );
            if ( $limit > 0 && strlen( $body ) + $length > $limit ) {
                $chunk = substr( $chunk, 0, $limit - strlen( $body ) );
                $body .= $chunk;
                return $length;
            }
            $body .= $chunk;
            return $length;
        },
    ) );
    do_action( 'http_api_curl', $handle, $args, $url );
    $ok   = curl_exec( $handle );
    $code = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
    $err  = curl_error( $handle );
    curl_close( $handle );
    if ( false === $ok ) {
        return new WP_Error( 'http_request_failed', $err );
    }
    return array( 'body' => $body, 'response' => array( 'code' => $code ), 'headers' => $headers );
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

function set_url_scheme( string $url, ?string $scheme = null ): string {
    return preg_replace( '#^[a-z][a-z0-9+.-]*://#i', ( $scheme ?? 'https' ) . '://', $url );
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

function esc_attr( string $text ): string {
    return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_attr__( string $text, string $domain = '' ): string {
    return esc_attr( $text );
}

function wp_unslash( $value ) {
    return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

final class WC_Admin_Settings {
    public static array $messages = array();
    public static array $errors   = array();

    public static function add_message( string $text ): void {
        self::$messages[] = $text;
    }

    public static function add_error( string $text ): void {
        self::$errors[] = $text;
    }

    public static function reset(): void {
        self::$messages = array();
        self::$errors   = array();
    }
}

function wc_get_product( $id ) {
    return FD_Test_Product_Store::$products[ (int) $id ] ?? null;
}

final class FD_Test_Product_Store {
    public static array $products = array();
}

final class FD_Test_Product {
    public function __construct( private int $id, private string $price, private string $name = 'Test product', private bool $ships = true, private bool $purchasable = true, private ?int $stock = null, private bool $backorders = false, private ?int $stock_owner = null, private bool $has_variations = false ) {
    }

    public function has_child(): bool {
        return $this->has_variations;
    }

    public function get_stock_managed_by_id(): int {
        return $this->stock_owner ?? $this->id;
    }

    public function is_in_stock(): bool {
        return null === $this->stock || $this->backorders || $this->stock > 0;
    }

    public function has_enough_stock( $quantity ): bool {
        return null === $this->stock || $this->backorders || $this->stock >= $quantity;
    }

    public function needs_shipping(): bool {
        return $this->ships;
    }

    public function get_id(): int {
        return $this->id;
    }

    public function get_price(): string {
        return $this->price;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function is_purchasable(): bool {
        return $this->purchasable;
    }
}

final class FD_Test_Coupon_Store {
    public static array $coupons = array();
    public static array $emails  = array();

    public static function add( string $code, string $type, float $amount, array $options = array() ): void {
        self::$coupons[ strtolower( $code ) ] = $options + array(
            'code'       => $code,
            'type'       => $type,
            'amount'     => $amount,
            'limit'      => 0,
            'used'       => 0,
            'minimum'    => 0.0,
            'emails'     => array(),
            'per_user'   => 0,
            'individual' => false,
        );
    }

    public static function used( string $code ): int {
        return self::$coupons[ strtolower( $code ) ]['used'];
    }

    public static function reset(): void {
        self::$coupons = array();
        self::$emails  = array();
    }
}

final class FD_Test_Coupon_Data_Store {
    public function get_usage_by_email( WC_Coupon $coupon, string $email ): int {
        return FD_Test_Coupon_Store::$emails[ strtolower( $coupon->get_code() ) ][ $email ] ?? 0;
    }
}

class WC_Coupon {
    private array $data;

    public function __construct( string $code = '' ) {
        $this->data = FD_Test_Coupon_Store::$coupons[ strtolower( $code ) ] ?? array();
    }

    public function get_id(): int {
        return $this->data ? 7 : 0;
    }

    public function get_code(): string {
        return $this->data['code'] ?? '';
    }

    public function get_discount_type(): string {
        return $this->data['type'] ?? '';
    }

    public function get_amount(): string {
        return (string) ( $this->data['amount'] ?? 0 );
    }

    public function get_usage_limit(): int {
        return $this->data['limit'] ?? 0;
    }

    public function get_usage_count(): int {
        return $this->data['used'] ?? 0;
    }

    public function get_minimum_amount(): float {
        return $this->data['minimum'] ?? 0.0;
    }

    public function get_email_restrictions(): array {
        return $this->data['emails'] ?? array();
    }

    public function get_usage_limit_per_user(): int {
        return $this->data['per_user'] ?? 0;
    }

    public function get_individual_use(): bool {
        return $this->data['individual'] ?? false;
    }

    public function get_data_store(): FD_Test_Coupon_Data_Store {
        return new FD_Test_Coupon_Data_Store();
    }

    public function is_valid(): bool {
        return true;
    }

    public function get_error_message(): string {
        return '';
    }

    public function get_description(): string {
        return '';
    }
}

final class WC_Discounts {
    public function __construct( private WC_Order $order ) {
    }

    public function is_coupon_valid( WC_Coupon $coupon ): true|WP_Error {
        if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
            return new WP_Error( 'invalid_coupon', 'Coupon usage limit has been reached' );
        }
        if ( $coupon->get_minimum_amount() > $this->order->get_subtotal() ) {
            return new WP_Error( 'invalid_coupon', 'The minimum spend for this coupon has not been met' );
        }
        return true;
    }
}

final class WC_Order_Item_Coupon {
    public string $type    = 'coupon';
    private string $code   = '';
    private float $amount  = 0.0;

    public function set_code( string $code ): void {
        $this->code = $code;
    }

    public function get_code(): string {
        return $this->code;
    }

    public function set_discount( float $amount ): void {
        $this->amount = $amount;
    }

    public function get_discount(): string {
        return number_format( $this->amount, 2, '.', '' );
    }

    public function discount(): float {
        return $this->amount;
    }
}

final class FD_Test_Shipping_Rate {
    public function __construct( private string $id, private string $cost, private string $label = 'Rate' ) {
    }

    public function get_id(): string {
        return $this->id;
    }

    public function get_cost(): string {
        return $this->cost;
    }

    public function get_label(): string {
        return $this->label;
    }
}

final class FD_Test_WC {
    public static ?FD_Test_WC $instance = null;
    public static array $rates          = array();
    public static float $tax_rate       = 0.0;
    public static float $included_rate  = 0.0;
    public $session;
    public $customer;

    public function __construct() {
        $this->session  = new stdClass();
        $this->customer = new stdClass();
    }

    public function shipping(): object {
        return new class() {
            public function load_shipping_methods(): void {
            }

            public function calculate_shipping_for_package( array $package ): array {
                return array( 'rates' => FD_Test_WC::$rates );
            }
        };
    }
}

function WC(): FD_Test_WC {
    return FD_Test_WC::$instance ??= new FD_Test_WC();
}

function sanitize_title( string $title ): string {
    return strtolower( preg_replace( '/[^A-Za-z0-9_\-]+/', '-', $title ) );
}

function sanitize_email( string $email ): string {
    return trim( $email );
}

function get_woocommerce_currency(): string {
    return 'EUR';
}

function wp_generate_uuid4(): string {
    if ( ! empty( FD_Test_WP::$uuids ) ) {
        return array_shift( FD_Test_WP::$uuids );
    }
    return '9b2e7c1a-4d3f-4e8a-b6c5-1f0a2d3e4b5c';
}

function wc_create_order( array $args = array() ): WC_Order {
    FD_Test_Order_Store::$created++;
    $order                       = new WC_Order();
    FD_Test_Order_Store::$made[] = $order;
    return $order;
}

function wc_get_order( $id ) {
    return FD_Test_Order_Store::$orders[ (int) $id ] ?? null;
}

function wc_get_order_id_by_order_key( string $key ): int {
    foreach ( FD_Test_Order_Store::$orders as $id => $order ) {
        if ( $order->get_order_key() === $key ) {
            return (int) $id;
        }
    }
    return 0;
}

function wc_create_refund( array $args ) {
    FD_Test_Order_Store::$refunds[] = $args;
    return new WP_Error( 'refund_recorded', 'Refund recorded by test stub' );
}

final class FD_Test_Order_Store {
    public static array $made   = array();
    public static int $created  = 0;
    public static array $orders  = array();
    public static array $refunds = array();
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $headers;
        private array $params;
        private array $json;
        private string $route;
        private string $method;
        private string $body;
        public array $form = array();

        public function __construct( string $route = '/fd-ucp/v1', array $headers = array(), array $params = array(), array $json = array(), string $method = 'GET', ?string $body = null ) {
            $this->route   = $route;
            $this->headers = array_change_key_case( $headers, CASE_LOWER );
            $this->params  = $params;
            $this->json    = $json;
            $this->method  = $method;
            $this->body    = $body ?? ( array() === $json ? '' : json_encode( $json ) );
        }

        public function get_header( string $name ): ?string {
            $value = $this->headers[ strtolower( $name ) ] ?? null;
            return is_array( $value ) ? implode( ',', $value ) : $value;
        }

        public function get_header_as_array( string $name ): ?array {
            $value = $this->headers[ strtolower( $name ) ] ?? null;
            return null === $value ? null : (array) $value;
        }

        public function get_method(): string {
            return $this->method;
        }

        public function get_body(): string {
            return $this->body;
        }

        public function get_body_params(): array {
            return $this->form;
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
    public string $order_key = 'wc_order_Zk3q9XvT1aBcD';
    public array $meta   = array();
    public array $calls  = array();
    public array $items  = array();
    public string $total = '0.00';
    public string $status = 'pending';
    public float $subtotal = 0.0;
    public float $shipping = 0.0;
    public float $tax      = 0.0;
    public float $discount = 0.0;
    public array $coupon_items = array();
    public string $billing_email = '';
    public bool $usage_recorded = false;

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

    public function get_order_key(): string {
        return $this->order_key;
    }

    public function get_view_order_url(): string {
        return 'https://store.test/my-account/view-order/1001/';
    }

    public function get_checkout_order_received_url(): string {
        return 'http://store.test/checkout/order-received/1001/?key=' . $this->order_key;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_meta( string $key ) {
        return $this->meta[ $key ] ?? '';
    }

    public function update_meta_data( string $key, $value ): void {
        $this->meta[ $key ] = $value;
    }

    public function add_product( $product, int $quantity = 1 ): int {
        $this->items[] = array( 'line_item', (float) $product->get_price() * $quantity );
        return count( $this->items );
    }

    public function add_item( $item ): void {
        if ( $item instanceof WC_Order_Item_Coupon ) {
            $this->coupon_items[] = $item;
            return;
        }
        $this->items[] = array( $item->type, (float) $item->total );
    }

    public function get_coupon_codes(): array {
        return array_map( static fn( WC_Order_Item_Coupon $i ): string => $i->get_code(), $this->coupon_items );
    }

    public function get_items( string $type = 'line_item' ): array {
        return 'coupon' === $type ? $this->coupon_items : array();
    }

    public function recalculate_coupons(): void {
        $lines = 0.0;
        foreach ( $this->items as $item ) {
            $lines += 'line_item' === $item[0] ? $item[1] : 0.0;
        }
        foreach ( $this->coupon_items as $item ) {
            $coupon = new WC_Coupon( $item->get_code() );
            $amount = (float) $coupon->get_amount();
            $item->set_discount( round( 'percent' === $coupon->get_discount_type() ? $lines * $amount / 100 : min( $amount, $lines ), 2 ) );
        }
        $this->calculate_totals();
    }

    public function get_billing_email(): string {
        return $this->billing_email;
    }

    public function set_billing_email( string $email ): void {
        $this->billing_email = $email;
    }

    public function get_total_discount(): string {
        return number_format( $this->discount, 2, '.', '' );
    }

    public function remove_order_items( ?string $type = null ): void {
        if ( null === $type || 'coupon' === $type ) {
            $this->coupon_items = array();
        }
        $this->items = array_values( array_filter( $this->items, static fn( array $i ): bool => null !== $type && $i[0] !== $type ) );
    }

    public function calculate_totals( bool $and_taxes = true ): float {
        $lines          = 0.0;
        $this->shipping = 0.0;
        $this->discount = 0.0;
        foreach ( $this->items as $item ) {
            if ( 'shipping' === $item[0] ) {
                $this->shipping += $item[1];
            } else {
                $lines += $item[1];
            }
        }
        $this->subtotal = round( $lines / ( 1 + FD_Test_WC::$included_rate ), 2 );
        foreach ( $this->coupon_items as $item ) {
            $this->discount += $item->discount();
        }
        $this->discount = min( $this->discount, round( $lines / ( 1 + FD_Test_WC::$included_rate ), 2 ) );
        $this->tax      = round( ( $this->subtotal - $this->discount + $this->shipping ) * FD_Test_WC::$tax_rate, 2 );
        $this->total    = number_format( $this->subtotal - $this->discount + $this->shipping + $this->tax, 2, '.', '' );
        return (float) $this->total;
    }

    public function get_total(): string {
        return $this->total;
    }

    public function get_subtotal(): float {
        return $this->subtotal;
    }

    public function get_shipping_total(): string {
        return number_format( $this->shipping, 2, '.', '' );
    }

    public function get_total_tax(): string {
        return number_format( $this->tax, 2, '.', '' );
    }

    public function get_refunds(): array {
        return array();
    }

    public function needs_payment(): bool {
        return in_array( $this->status, array( 'pending', 'failed' ), true );
    }

    public function update_status( string $status, string $note = '' ): bool {
        $this->calls[] = 'update_status';
        $this->status  = $status;
        return true;
    }

    public function payment_complete( string $transaction_id = '' ): bool {
        $this->calls[] = 'payment_complete';
        if ( ! $this->usage_recorded ) {
            $this->usage_recorded = true;
            foreach ( $this->get_coupon_codes() as $code ) {
                FD_Test_Coupon_Store::$coupons[ strtolower( $code ) ]['used']++;
            }
        }
        $this->status  = 'processing';
        return true;
    }

    public function __call( string $name, array $args ) {
        $this->calls[] = $name;
        return null;
    }
}

class WC_Order_Item_Product {
    public string $type = 'line_item';
    public float $total = 0.0;

    public function set_total( $total ): void {
        $this->total = (float) $total;
    }

    public function __call( string $name, array $args ) {
        return null;
    }
}

class WC_Order_Item_Shipping {
    public string $type = 'shipping';
    public float $total = 0.0;

    public function set_total( $total ): void {
        $this->total = (float) $total;
    }

    public function __call( string $name, array $args ) {
        return null;
    }
}

final class FD_Test_Wpdb {
    public string $prefix = 'wp_';
    public array $sessions = array();
    public array $carts    = array();
    public array $updates  = array();
    public array $calls    = array();
    public array $held     = array();
    public array $claims   = array();
    public array $schema   = array();
    public array $alters   = array();
    public bool $fail_alters = false;
    public bool $fail_inserts = false;

    public function __construct() {
        $this->schema = self::current_schema();
    }

    public static function current_schema(): array {
        return array(
            'wp_fd_ucp_checkout_sessions' => array(
                'columns' => array( 'id', 'status', 'platform_id', 'idempotency_key', 'idempotency_hash', 'ucp_version' ),
                'indexes' => array( 'PRIMARY', 'status', 'wc_order_id', 'platform_idempotency' ),
            ),
            'wp_fd_ucp_carts'             => array(
                'columns' => array( 'id', 'line_items', 'platform_id', 'ucp_version' ),
                'indexes' => array( 'PRIMARY' ),
            ),
            'wp_fd_ucp_payment_claims'    => array(
                'columns' => array( 'claim_key', 'kind', 'checkout_id' ),
                'indexes' => array( 'PRIMARY', 'checkout_id' ),
            ),
        );
    }

    public static function legacy_schema(): array {
        return array(
            'wp_fd_ucp_checkout_sessions' => array(
                'columns' => array( 'id', 'status', 'session_token_hash', 'idempotency_key', 'ucp_version' ),
                'indexes' => array( 'PRIMARY', 'status', 'wc_order_id', 'idempotency_key' ),
            ),
            'wp_fd_ucp_carts'             => array(
                'columns' => array( 'id', 'line_items', 'session_token_hash', 'ucp_version' ),
                'indexes' => array( 'PRIMARY' ),
            ),
            'wp_fd_ucp_payment_claims'    => array(
                'columns' => array( 'claim_key', 'kind', 'checkout_id' ),
                'indexes' => array( 'PRIMARY', 'checkout_id' ),
            ),
        );
    }

    public function esc_like( string $text ): string {
        return $text;
    }

    public function suppress_errors( bool $suppress = true ): bool {
        return false;
    }

    private function show( string $query ) {
        if ( preg_match( "/^SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
            return isset( $this->schema[ $m[1] ] ) ? $m[1] : null;
        }
        if ( preg_match( "/^SHOW COLUMNS FROM `([^`]+)` LIKE '([^']+)'/", $query, $m ) ) {
            return in_array( $m[2], $this->schema[ $m[1] ]['columns'] ?? array(), true ) ? $m[2] : null;
        }
        if ( preg_match( "/^SHOW INDEX FROM `([^`]+)` WHERE Key_name = '([^']+)'/", $query, $m ) ) {
            return in_array( $m[2], $this->schema[ $m[1] ]['indexes'] ?? array(), true ) ? $m[1] : null;
        }
        return false;
    }

    private function alter( string $query ): int|false {
        $this->alters[] = $query;
        if ( $this->fail_alters || ! preg_match( '/^ALTER TABLE `([^`]+)` (.+)$/', $query, $m ) || ! isset( $this->schema[ $m[1] ] ) ) {
            return false;
        }

        $table = $this->schema[ $m[1] ];
        foreach ( preg_split( '/,\s*(?=ADD |DROP )/', $m[2] ) as $clause ) {
            if ( preg_match( '/^ADD COLUMN (\w+)/', $clause, $c ) ) {
                if ( in_array( $c[1], $table['columns'], true ) ) {
                    return false;
                }
                $table['columns'][] = $c[1];
            } elseif ( preg_match( '/^ADD UNIQUE KEY (\w+)/', $clause, $c ) ) {
                if ( in_array( $c[1], $table['indexes'], true ) ) {
                    return false;
                }
                $table['indexes'][] = $c[1];
            } elseif ( preg_match( '/^DROP INDEX (\w+)/', $clause, $c ) ) {
                if ( ! in_array( $c[1], $table['indexes'], true ) ) {
                    return false;
                }
                $table['indexes'] = array_values( array_diff( $table['indexes'], array( $c[1] ) ) );
            } elseif ( preg_match( '/^DROP COLUMN (\w+)/', $clause, $c ) ) {
                if ( ! in_array( $c[1], $table['columns'], true ) ) {
                    return false;
                }
                $table['columns'] = array_values( array_diff( $table['columns'], array( $c[1] ) ) );
            } else {
                return false;
            }
        }

        $this->schema[ $m[1] ] = $table;
        return 0;
    }

    public function get_charset_collate(): string {
        return '';
    }

    public function prepare( string $query, ...$args ): string {
        return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
    }

    public function get_var( string $query ) {
        if ( 0 === strpos( $query, 'SHOW ' ) ) {
            return $this->show( $query );
        }
        $this->calls[] = $query;
        if ( false !== strpos( $query, 'fd_ucp_payment_claims' ) ) {
            preg_match( "/claim_key = '([0-9a-f]+)'/", $query, $m );
            return $this->claims[ $m[1] ?? '' ] ?? null;
        }
        foreach ( $this->held as $name ) {
            if ( false !== strpos( $query, "GET_LOCK('" . $name . "'" ) ) {
                return '0';
            }
        }
        return '1';
    }

    public function get_row( string $query, $output = null ) {
        $platform = preg_match( "/platform_id = '([^']*)'/", $query, $m ) ? $m[1] : null;
        foreach ( $this->rows( $query ) as $row ) {
            if ( null !== $platform && ( $row['platform_id'] ?? null ) !== $platform ) {
                continue;
            }
            foreach ( array( 'id', 'idempotency_key' ) as $column ) {
                if ( ! empty( $row[ $column ] ) && false !== strpos( $query, "'" . $row[ $column ] . "'" ) ) {
                    return $row;
                }
            }
        }
        return null;
    }

    public function update( string $table, array $data, array $where ): int {
        $this->calls[]   = 'update';
        $this->updates[] = array( $table, $data, $where );
        $rows            = &$this->table( $table );
        if ( isset( $rows[ $where['id'] ] ) ) {
            $rows[ $where['id'] ] = array_merge( $rows[ $where['id'] ], $data );
        }
        return 1;
    }

    public function query( string $query ): int|false {
        if ( 0 === strpos( $query, 'ALTER TABLE' ) ) {
            return $this->alter( $query );
        }
        preg_match( "/VALUES \('([0-9a-f]+)', '[^']*', '([^']*)'/", $query, $m );
        if ( isset( $this->claims[ $m[1] ] ) ) {
            return 0;
        }
        $this->claims[ $m[1] ] = $m[2];
        return 1;
    }

    public function insert( string $table, array $data ): int|false {
        if ( $this->fail_inserts ) {
            return false;
        }
        $rows                = &$this->table( $table );
        $rows[ $data['id'] ] = $data;
        return 1;
    }

    public function delete( string $table, array $where ): int {
        $rows = &$this->table( $table );
        unset( $rows[ $where['id'] ] );
        return 1;
    }

    private function rows( string $query ): array {
        return false !== strpos( $query, 'fd_ucp_carts' ) ? $this->carts : $this->sessions;
    }

    private function &table( string $table ): array {
        if ( false !== strpos( $table, 'fd_ucp_carts' ) ) {
            return $this->carts;
        }
        return $this->sessions;
    }
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
