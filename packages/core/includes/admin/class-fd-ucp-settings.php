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
        add_filter( 'sanitize_option_' . FD_UCP_Platform_Auth::OPTION_ACCESS, array( __CLASS__, 'sanitize_platform_access' ), 10, 2 );
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
                'desc'  => __( 'AI platforms identify themselves with the UCP-Agent profile URL. Register a platform below to make its key mandatory and give its carts, checkout sessions and orders a private space that only that key can reach. Platforms you do not register are identified only by the profile URL they claim. A key grants the identity of the profile URL it is registered for, so register only profile URLs you have confirmed belong to the platform you are onboarding.', 'fd-ucp-for-woocommerce' ),
                'id'    => 'fd_ucp_platform_access_section',
            ),
            array(
                'title'   => __( 'Platform access', 'fd-ucp-for-woocommerce' ),
                'id'      => FD_UCP_Platform_Auth::OPTION_ACCESS,
                'type'    => 'select',
                'default' => FD_UCP_Platform_Auth::ACCESS_OPEN,
                'options' => array(
                    FD_UCP_Platform_Auth::ACCESS_OPEN          => __( 'Open (default): any platform can shop, registered platforms must send their key', 'fd-ucp-for-woocommerce' ),
                    FD_UCP_Platform_Auth::ACCESS_AUTHENTICATED => __( 'Authenticated: every platform must sign its requests or send a registered key', 'fd-ucp-for-woocommerce' ),
                    FD_UCP_Platform_Auth::ACCESS_REGISTERED    => __( 'Registered only: only platforms listed below can shop', 'fd-ucp-for-woocommerce' ),
                ),
                'desc'    => __( 'Enable Authenticated if the shop holds sensitive buyer data. Open trusts a platform that is not registered on its word, so its carts, checkout sessions and orders are protected only by their unguessable ids. Authenticated accepts a platform only with a valid signature or a registered key. Registered only also requires an enabled key in the list below, even for platforms that sign. To block a platform, disable its keys; deleting them removes the block.', 'fd-ucp-for-woocommerce' ),
            ),
            array(
                'id'        => FD_UCP_Platform_Auth::OPTION,
                'type'      => 'fd_ucp_platforms',
                'is_option' => false,
            ),
            array(
                'type' => 'sectionend',
                'id'   => 'fd_ucp_platform_access_section',
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
        $reveal    = is_array( $post['fd_ucp_platform_reveal'] ?? null ) ? $post['fd_ucp_platform_reveal'] : array();

        $revealed    = array();
        $unavailable = array();
        foreach ( self::clean_platforms( $stored ) as $platform ) {
            if ( isset( $deleted[ $platform['key_hash'] ] ) ) {
                continue;
            }
            if ( isset( $reveal[ $platform['key_hash'] ] ) ) {
                $key = isset( $platform['key_cipher'] ) ? FD_UCP_Key_Vault::open( $platform['key_cipher'] ) : null;
                if ( null === $key || ! hash_equals( $platform['key_hash'], hash( 'sha256', $key ) ) ) {
                    $unavailable[] = $platform['profile'];
                } else {
                    $revealed[] = array( 'profile' => $platform['profile'], 'key' => $key );
                }
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
                $key    = bin2hex( random_bytes( 32 ) );
                $cipher = FD_UCP_Key_Vault::seal( $key );
                if ( null === $cipher ) {
                    $errors[] = __( 'The key could not be stored securely, so no key was issued. Check that the OpenSSL extension is available.', 'fd-ucp-for-woocommerce' );
                } else {
                    $issued      = $key;
                    $platforms[] = array(
                        'profile'    => $profile,
                        'key_hash'   => hash( 'sha256', $key ),
                        'key_cipher' => $cipher,
                        'label'      => substr( sanitize_text_field( (string) ( $post['fd_ucp_platform_new_label'] ?? '' ) ), 0, 100 ),
                        'enabled'    => true,
                    );
                }
            }
        }

        return array(
            'platforms'   => $platforms,
            'issued'      => $issued,
            'profile'     => $profile,
            'errors'      => $errors,
            'revealed'    => $revealed,
            'unavailable' => $unavailable,
        );
    }

    private static function may_reveal( array $post ): bool {
        return current_user_can( 'manage_woocommerce' )
            && is_string( $post['_wpnonce'] ?? null )
            && (bool) wp_verify_nonce( $post['_wpnonce'], 'woocommerce-settings' );
    }

    public static function save_platforms(): void {
        $post = wp_unslash( $_POST );
        $post = is_array( $post ) ? $post : array();
        if ( ! empty( $post['fd_ucp_platform_reveal'] ) && ! self::may_reveal( $post ) ) {
            unset( $post['fd_ucp_platform_reveal'] );
            WC_Admin_Settings::add_error( __( 'Platform keys were not shown: the request is not authorised to view them.', 'fd-ucp-for-woocommerce' ) );
        }
        $result = self::apply_platform_changes( self::platforms(), $post );

        update_option( FD_UCP_Platform_Auth::OPTION, $result['platforms'], false );

        foreach ( $result['errors'] as $error ) {
            WC_Admin_Settings::add_error( $error );
        }
        foreach ( $result['revealed'] as $revealed ) {
            wc_get_logger()->info(
                'UCP platform key revealed',
                array(
                    'source'  => 'fd-ucp',
                    'profile' => $revealed['profile'],
                    'user_id' => get_current_user_id(),
                )
            );
            WC_Admin_Settings::add_message( sprintf(
                __( 'API key for %1$s: %2$s. Each view is logged.', 'fd-ucp-for-woocommerce' ),
                $revealed['profile'],
                $revealed['key']
            ) );
        }
        foreach ( $result['unavailable'] as $profile ) {
            WC_Admin_Settings::add_error( sprintf(
                __( 'No viewable key is stored for %s. Issue a new key for this platform, then disable this one.', 'fd-ucp-for-woocommerce' ),
                $profile
            ) );
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
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Platform profile', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Label', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Enabled', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Show', 'fd-ucp-for-woocommerce' ) . '</th><th>' . esc_html__( 'Delete', 'fd-ucp-for-woocommerce' ) . '</th></tr></thead><tbody>';
        foreach ( self::platforms() as $platform ) {
            $hash = esc_attr( $platform['key_hash'] );
            echo '<tr><td>' . esc_html( $platform['profile'] ) . '</td><td>' . esc_html( $platform['label'] ) . '</td>'
                . '<td><input type="checkbox" name="fd_ucp_platform_enabled[' . $hash . ']" value="1"' . ( $platform['enabled'] ? ' checked="checked"' : '' ) . ' /></td>'
                . '<td><input type="checkbox" name="fd_ucp_platform_reveal[' . $hash . ']" value="1" /></td>'
                . '<td><input type="checkbox" name="fd_ucp_platform_delete[' . $hash . ']" value="1" /></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><input type="url" name="fd_ucp_platform_new_profile" class="regular-text" placeholder="https://platform.example/.well-known/ucp" /> ';
        echo '<input type="text" name="fd_ucp_platform_new_label" class="regular-text" maxlength="100" placeholder="' . esc_attr__( 'Label', 'fd-ucp-for-woocommerce' ) . '" /></p>';
        echo '<p class="description">' . esc_html__( 'Enter a profile URL and save to issue a key for that platform. The key is shown when it is issued. Tick Show and save to view a key again; each view is written to WooCommerce > Status > Logs (source fd-ucp). Keys issued before this option existed cannot be shown, so issue a new key and disable the old one.', 'fd-ucp-for-woocommerce' ) . '</p>';
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
            $row = array(
                'profile'  => $profile,
                'key_hash' => $entry['key_hash'],
            );
            if ( is_string( $entry['key_cipher'] ?? null ) && '' !== $entry['key_cipher'] ) {
                $row['key_cipher'] = $entry['key_cipher'];
            }
            $row['label']   = is_string( $entry['label'] ?? null ) ? $entry['label'] : '';
            $row['enabled'] = ! empty( $entry['enabled'] );
            $clean[]        = $row;
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

    public static function sanitize_platform_access( $value, string $option ) {
        if ( is_string( $value ) && in_array( $value, FD_UCP_Platform_Auth::access_modes(), true ) ) {
            return $value;
        }
        return self::reject( $option, FD_UCP_Platform_Auth::ACCESS_OPEN );
    }

    private static function reject( string $option, $default ) {
        if ( class_exists( 'WC_Admin_Settings' ) ) {
            WC_Admin_Settings::add_error( sprintf( __( 'Invalid value for %s was not saved.', 'fd-ucp-for-woocommerce' ), $option ) );
        }
        return get_option( $option, $default );
    }
}
