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
        add_rewrite_rule( '^\.well-known/ucp/(\d{4}-\d{2}-\d{2})/?$', 'index.php?fd_ucp_discovery=1&fd_ucp_version=$matches[1]', 'top' );
    }

    public function register_query_vars( array $vars ): array {
        $vars[] = 'fd_ucp_discovery';
        $vars[] = 'fd_ucp_version';
        return $vars;
    }

    public function handle_request(): void {
        if ( ! get_query_var( 'fd_ucp_discovery' ) ) {
            return;
        }

        $result = self::render( FD_UCP_Version_Registry::from_options(), (string) get_query_var( 'fd_ucp_version' ), $this->registry );

        status_header( $result['status'] );
        header( 'Content-Type: application/json' );
        header( 'Cache-Control: public, max-age=300' );
        header( 'Access-Control-Allow-Origin: *' );

        echo wp_json_encode( $result['body'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        exit;
    }

    public static function render( FD_UCP_Version_Registry $versions, string $leaf_version, FD_Payment_Registry $registry ): array {
        $base_url = home_url( '/wp-json/fd-ucp/v1' );

        if ( null !== $versions->assert_valid() ) {
            return array(
                'status' => 500,
                'body'   => $versions->current_wire()->error( 'configuration_invalid', 'UCP configuration is invalid' ),
            );
        }

        if ( '' === $leaf_version ) {
            $context = new FD_UCP_Request_Context( $versions, $versions->current() );
            FD_UCP_Request_Context::set( $context );
            return array(
                'status' => 200,
                'body'   => $context->wire()->profile( $base_url, $registry, $context->supported_versions_map() ),
            );
        }

        if ( ! $versions->is_enabled( $leaf_version ) ) {
            return array(
                'status' => 404,
                'body'   => $versions->current_wire()->error( 'version_unsupported', sprintf(
                    'Version %s is not supported. This business implements versions %s.',
                    $leaf_version,
                    implode( ', ', $versions->enabled() )
                ) ),
            );
        }

        $context = new FD_UCP_Request_Context( $versions, $leaf_version );
        FD_UCP_Request_Context::set( $context );
        return array(
            'status' => 200,
            'body'   => $context->wire()->profile( $base_url, $registry, array() ),
        );
    }
}
