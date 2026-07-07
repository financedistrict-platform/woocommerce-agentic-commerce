<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Discovery {

    private FD_Payment_Registry $registry;

    public function __construct( FD_Payment_Registry $registry ) {
        $this->registry = $registry;

        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'handle_request' ) );
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule( '^\.well-known/ucp/?$', 'index.php?fd_ucp_discovery=1', 'top' );
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'fd_ucp_discovery';
        return $vars;
    }

    public function handle_request(): void {
        if ( ! get_query_var( 'fd_ucp_discovery' ) ) {
            return;
        }

        $base_url = home_url( '/wp-json/fd-ucp/v1' );
        $profile  = FD_UCP_Formatter::format_profile( $base_url, $this->registry );

        header( 'Content-Type: application/json' );
        header( 'Cache-Control: public, max-age=300' );
        header( 'Access-Control-Allow-Origin: *' );

        echo wp_json_encode( $profile, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        exit;
    }
}
