<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Installer {

    public const SESSIONS_TABLE = 'fd_ucp_checkout_sessions';
    public const CARTS_TABLE    = 'fd_ucp_carts';

    private const ERROR_THROTTLE = 'fd_ucp_schema_error';
    private const LEGACY_ACCESS  = 'fd_ucp_signed_access';
    private const ACCESS         = 'fd_ucp_platform_access';

    public static function install(): void {
        self::migrate();
        self::flush_rules();
        self::seed_version_option();
        self::migrate_platform_access();
        self::record_schema_version();
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'fd_ucp_db_version' ) !== FD_UCP_DB_VERSION ) {
            self::migrate();
            self::seed_version_option();
            self::migrate_platform_access();
            add_action( 'init', array( __CLASS__, 'flush_rewrite_rules_after_upgrade' ), 20 );
            self::record_schema_version();
        }
    }

    public static function flush_rewrite_rules_after_upgrade(): void {
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function seed_version_option(): void {
        add_option( FD_UCP_Version_Registry::OPTION_CURRENT, FD_UCP_Version_Registry::LATEST );
    }

    private static function migrate_platform_access(): void {
        $legacy = get_option( self::LEGACY_ACCESS, false );
        if ( false === $legacy ) {
            return;
        }

        add_option( self::ACCESS, 'registered' === $legacy ? 'registered' : 'open' );
        delete_option( self::LEGACY_ACCESS );
    }

    private static function migrate(): void {
        self::alter_existing_tables();
        self::create_tables();
    }

    private static function record_schema_version(): void {
        if ( self::schema_is_current() ) {
            update_option( 'fd_ucp_db_version', FD_UCP_DB_VERSION );
            return;
        }

        if ( false === get_transient( self::ERROR_THROTTLE ) ) {
            set_transient( self::ERROR_THROTTLE, 1, HOUR_IN_SECONDS );
            wc_get_logger()->error( 'UCP schema upgrade did not complete; it will be retried on the next request', array( 'source' => 'fd-ucp' ) );
        }
    }

    private static function alter_existing_tables(): void {
        global $wpdb;
        $sessions = $wpdb->prefix . self::SESSIONS_TABLE;
        $carts    = $wpdb->prefix . self::CARTS_TABLE;

        $clauses = array();
        if ( self::table_exists( $sessions ) ) {
            if ( ! self::column_exists( $sessions, 'platform_id' ) ) {
                $clauses[] = 'ADD COLUMN platform_id VARCHAR(191) NULL';
            }
            if ( ! self::column_exists( $sessions, 'idempotency_hash' ) ) {
                $clauses[] = 'ADD COLUMN idempotency_hash CHAR(64) NULL';
            }
            if ( self::column_exists( $sessions, 'idempotency_key' ) && ! self::index_exists( $sessions, 'platform_idempotency' ) ) {
                $clauses[] = 'ADD UNIQUE KEY platform_idempotency (platform_id, idempotency_key)';
            }
            if ( self::index_exists( $sessions, 'idempotency_key' ) ) {
                $clauses[] = 'DROP INDEX idempotency_key';
            }
            if ( self::column_exists( $sessions, 'session_token_hash' ) ) {
                $clauses[] = 'DROP COLUMN session_token_hash';
            }
            self::alter( $sessions, $clauses );
        }

        $clauses = array();
        if ( self::table_exists( $carts ) ) {
            if ( ! self::column_exists( $carts, 'platform_id' ) ) {
                $clauses[] = 'ADD COLUMN platform_id VARCHAR(191) NULL';
            }
            if ( self::column_exists( $carts, 'session_token_hash' ) ) {
                $clauses[] = 'DROP COLUMN session_token_hash';
            }
            self::alter( $carts, $clauses );
        }
    }

    private static function alter( string $table, array $clauses ): void {
        global $wpdb;
        if ( empty( $clauses ) ) {
            return;
        }
        $wpdb->query( "ALTER TABLE `{$table}` " . implode( ', ', $clauses ) );
    }

    private static function schema_is_current(): bool {
        global $wpdb;
        $sessions = $wpdb->prefix . self::SESSIONS_TABLE;
        $carts    = $wpdb->prefix . self::CARTS_TABLE;

        return self::column_exists( $sessions, 'platform_id' )
            && self::column_exists( $sessions, 'idempotency_hash' )
            && ! self::column_exists( $sessions, 'session_token_hash' )
            && self::index_exists( $sessions, 'platform_idempotency' )
            && ! self::index_exists( $sessions, 'idempotency_key' )
            && self::column_exists( $carts, 'platform_id' )
            && ! self::column_exists( $carts, 'session_token_hash' );
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    private static function column_exists( string $table, string $column ): bool {
        global $wpdb;
        $wpdb->suppress_errors( true );
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );
        $wpdb->suppress_errors( false );
        return null !== $found;
    }

    private static function index_exists( string $table, string $index ): bool {
        global $wpdb;
        $wpdb->suppress_errors( true );
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index ) );
        $wpdb->suppress_errors( false );
        return null !== $found;
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$wpdb->prefix}fd_ucp_checkout_sessions (
            id VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'incomplete',
            currency VARCHAR(3) NOT NULL DEFAULT 'USD',
            line_items LONGTEXT NOT NULL,
            totals LONGTEXT NULL,
            buyer LONGTEXT NULL,
            fulfillment LONGTEXT NULL,
            payment_meta LONGTEXT NULL,
            wc_order_id BIGINT NULL,
            platform_id VARCHAR(191) NULL,
            idempotency_key VARCHAR(128) NULL,
            idempotency_hash CHAR(64) NULL,
            ucp_version VARCHAR(10) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY wc_order_id (wc_order_id),
            UNIQUE KEY platform_idempotency (platform_id, idempotency_key)
        ) $charset;

        CREATE TABLE {$wpdb->prefix}fd_ucp_carts (
            id VARCHAR(64) NOT NULL,
            line_items LONGTEXT NOT NULL,
            platform_id VARCHAR(191) NULL,
            ucp_version VARCHAR(10) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE {$wpdb->prefix}fd_ucp_payment_claims (
            claim_key CHAR(64) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            checkout_id VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (claim_key),
            KEY checkout_id (checkout_id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function flush_rules(): void {
        FD_UCP_Discovery::add_rewrite_rules();
        flush_rewrite_rules();
    }
}
