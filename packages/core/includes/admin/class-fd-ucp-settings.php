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
        add_action( 'woocommerce_admin_field_fd_ucp_platforms', array( __CLASS__, 'render_platforms' ) );
        add_action( 'woocommerce_update_options_advanced_' . self::SECTION, array( __CLASS__, 'save_platforms' ) );
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
            array(
                'title' => __( 'Platform access', 'fd-ucp-for-woocommerce' ),
                'type'  => 'title',
                'desc'  => __( 'AI agents that do not sign their requests need a key registered for their platform profile. They send it in the X-API-Key header together with the UCP-Agent header. A key grants the identity of the profile URL it is registered for, so register only profile URLs you have confirmed belong to the agent you are onboarding.', 'fd-ucp-for-woocommerce' ),
                'id'    => 'fd_ucp_platform_access',
            ),
            array(
                'id'        => FD_UCP_Platform_Auth::OPTION,
                'type'      => 'fd_ucp_platforms',
                'is_option' => false,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'fd_ucp_platform_access',
            ),
        );
    }

    public static function platforms(): array {
        $stored = get_option( FD_UCP_Platform_Auth::OPTION, array() );
        return self::clean_platforms( is_array( $stored ) ? $stored : array() );
    }

    public static function apply_platform_changes( array $stored, array $post ): array {
        $platforms = array();
        $enabled   = is_array( $post['fd_ucp_platform_enabled'] ?? null ) ? $post['fd_ucp_platform_enabled'] : array();
        $deleted   = is_array( $post['fd_ucp_platform_delete'] ?? null ) ? $post['fd_ucp_platform_delete'] : array();

        foreach ( self::clean_platforms( $stored ) as $platform ) {
            if ( isset( $deleted[ $platform['key_hash'] ] ) ) {
                continue;
            }
            $platform['enabled'] = isset( $enabled[ $platform['key_hash'] ] );
            $platforms[]         = $platform;
        }

        $issued  = null;
        $profile = null;
        $errors  = array();
        $url     = trim( (string) ( $post['fd_ucp_platform_new_profile'] ?? '' ) );
        if ( '' !== $url ) {
            $profile = FD_UCP_Platform_Auth::normalise_profile_url( $url );
            if ( null === $profile ) {
                $errors[] = sprintf(
                    __( 'Enter the https profile URL of the platform (at most %d characters).', 'fd-ucp-for-woocommerce' ),
                    FD_UCP_Platform_Auth::MAX_PROFILE_LENGTH
                );
            } else {
                $issued      = bin2hex( random_bytes( 32 ) );
                $platforms[] = array(
                    'profile'  => $profile,
                    'key_hash' => hash( 'sha256', $issued ),
                    'label'    => substr( sanitize_text_field( (string) ( $post['fd_ucp_platform_new_label'] ?? '' ) ), 0, 100 ),
                    'enabled'  => true,
                );
            }
        }

        return array(
            'platforms' => $platforms,
            'issued'    => $issued,
            'profile'   => $profile,
            'errors'    => $errors,
        );
    }

    public static function save_platforms(): void {
        $post   = wp_unslash( $_POST );
        $result = self::apply_platform_changes( self::platforms(), is_array( $post ) ? $post : array() );

        update_option( FD_UCP_Platform_Auth::OPTION, $result['platforms'], false );

        foreach ( $result['errors'] as $error ) {
            WC_Admin_Settings::add_error( $error );
        }
        if ( null !== $result['issued'] ) {
            WC_Admin_Settings::add_message( sprintf(
                __( 'API key for %1$s: %2$s. Copy it now, it is shown only once.', 'fd-ucp-for-woocommerce' ),
                $result['profile'],
                $result['issued']
            ) );
        }
    }

    public static function render_platforms( array $field ): void {
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__( 'Registered platforms', 'fd-ucp-for-woocommerce' ) . '</th><td class="forminp">';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Platform profile', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Label', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Enabled', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Delete', 'fd-ucp-for-woocommerce' ) . '</th></tr></thead><tbody>';
        foreach ( self::platforms() as $platform ) {
            $hash = esc_attr( $platform['key_hash'] );
            echo '<tr><td>' . esc_html( $platform['profile'] ) . '</td><td>' . esc_html( $platform['label'] ) . '</td>'
                . '<td><input type="checkbox" name="fd_ucp_platform_enabled[' . $hash . ']" value="1"' . ( $platform['enabled'] ? ' checked="checked"' : '' ) . ' /></td>'
                . '<td><input type="checkbox" name="fd_ucp_platform_delete[' . $hash . ']" value="1" /></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><input type="url" name="fd_ucp_platform_new_profile" class="regular-text" placeholder="https://platform.example/.well-known/ucp" /> ';
        echo '<input type="text" name="fd_ucp_platform_new_label" class="regular-text" maxlength="100" placeholder="' . esc_attr__( 'Label', 'fd-ucp-for-woocommerce' ) . '" /></p>';
        echo '<p class="description">' . esc_html__( 'Enter a profile URL and save to issue a key for that platform. The key is shown once and only its hash is stored.', 'fd-ucp-for-woocommerce' ) . '</p>';
        echo '</td></tr>';
    }

    private static function clean_platforms( array $stored ): array {
        $clean = array();
        foreach ( $stored as $entry ) {
            if ( ! is_array( $entry ) || ! is_string( $entry['key_hash'] ?? null ) || ! preg_match( '/^[0-9a-f]{64}$/', $entry['key_hash'] ) ) {
                continue;
            }
            $profile = FD_UCP_Platform_Auth::normalise_profile_url( (string) ( $entry['profile'] ?? '' ) );
            if ( null === $profile ) {
                continue;
            }
            $clean[] = array(
                'profile'  => $profile,
                'key_hash' => $entry['key_hash'],
                'label'    => is_string( $entry['label'] ?? null ) ? $entry['label'] : '',
                'enabled'  => ! empty( $entry['enabled'] ),
            );
        }
        return $clean;
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
