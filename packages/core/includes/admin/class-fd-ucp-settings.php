<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Settings {

    public const SECTION = 'fd_ucp';

    public static function init(): void {
        add_filter( 'woocommerce_get_sections_advanced', array( __CLASS__, 'add_section' ) );
        add_filter( 'woocommerce_get_settings_advanced', array( __CLASS__, 'settings' ), 10, 2 );
        add_filter( 'sanitize_option_' . FD_UCP_Version_Registry::OPTION_CURRENT, array( __CLASS__, 'sanitize_current' ), 10, 2 );
        add_filter( 'sanitize_option_' . FD_UCP_Version_Registry::OPTION_SUPPORTED, array( __CLASS__, 'sanitize_supported' ), 10, 2 );
        add_filter( 'sanitize_option_' . FD_UCP_Version_Registry::OPTION_NEGOTIATION, array( __CLASS__, 'sanitize_negotiation' ), 10, 2 );
    }

    public static function add_section( array $sections ): array {
        $sections[ self::SECTION ] = __( 'UCP versions', 'fd-ucp-for-woocommerce' );
        return $sections;
    }

    public static function settings( array $settings, $current_section ): array {
        if ( self::SECTION !== $current_section ) {
            return $settings;
        }

        $versions = array_combine( FD_UCP_Version_Registry::known(), FD_UCP_Version_Registry::known() );

        return array(
            array(
                'title' => __( 'UCP versions', 'fd-ucp-for-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( 'Choose which Universal Commerce Protocol versions AI agents can use with this store.', 'fd-ucp-for-woocommerce' ),
                'id'    => 'fd_ucp_versions',
            ),
            array(
                'title'   => __( 'Current version', 'fd-ucp-for-woocommerce' ),
                'id'      => FD_UCP_Version_Registry::OPTION_CURRENT,
                'type'    => 'select',
                'default' => FD_UCP_Version_Registry::DEFAULT_CURRENT,
                'options' => $versions,
            ),
            array(
                'title'   => __( 'Also supported versions', 'fd-ucp-for-woocommerce' ),
                'id'      => FD_UCP_Version_Registry::OPTION_SUPPORTED,
                'type'    => 'multiselect',
                'class'   => 'wc-enhanced-select',
                'default' => FD_UCP_Version_Registry::DEFAULT_SUPPORTED,
                'options' => $versions,
            ),
            array(
                'title'   => __( 'Version negotiation', 'fd-ucp-for-woocommerce' ),
                'id'      => FD_UCP_Version_Registry::OPTION_NEGOTIATION,
                'type'    => 'select',
                'default' => FD_UCP_Version_Registry::NEGOTIATION_LENIENT,
                'options' => array(
                    FD_UCP_Version_Registry::NEGOTIATION_LENIENT => __( 'Lenient: serve the current version when an agent profile cannot be read', 'fd-ucp-for-woocommerce' ),
                    FD_UCP_Version_Registry::NEGOTIATION_STRICT  => __( 'Strict: reject requests whose agent profile cannot be read', 'fd-ucp-for-woocommerce' ),
                ),
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'fd_ucp_versions',
            ),
        );
    }

    public static function sanitize_current( $value, string $option ) {
        if ( is_string( $value ) && FD_UCP_Version_Registry::is_known( $value ) ) {
            return $value;
        }
        return self::reject( $option, FD_UCP_Version_Registry::DEFAULT_CURRENT );
    }

    public static function sanitize_supported( $value, string $option ) {
        $value = is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
        foreach ( $value as $version ) {
            if ( ! FD_UCP_Version_Registry::is_known( $version ) ) {
                return self::reject( $option, FD_UCP_Version_Registry::DEFAULT_SUPPORTED );
            }
        }
        return $value;
    }

    public static function sanitize_negotiation( $value, string $option ) {
        if ( is_string( $value ) && in_array( $value, FD_UCP_Version_Registry::negotiation_modes(), true ) ) {
            return $value;
        }
        return self::reject( $option, FD_UCP_Version_Registry::NEGOTIATION_LENIENT );
    }

    private static function reject( string $option, $default ) {
        if ( class_exists( 'WC_Admin_Settings' ) ) {
            WC_Admin_Settings::add_error( sprintf( __( 'Invalid value for %s was not saved.', 'fd-ucp-for-woocommerce' ), $option ) );
        }
        return get_option( $option, $default );
    }
}
