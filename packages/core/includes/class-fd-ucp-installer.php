<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Installer {

    public static function install(): void {
        self::create_tables();
        self::flush_rules();
        update_option( 'fd_ucp_db_version', FD_UCP_DB_VERSION );
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
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
            agent_fingerprint VARCHAR(128) NULL,
            idempotency_key VARCHAR(128) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            expires_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY wc_order_id (wc_order_id),
            UNIQUE KEY idempotency_key (idempotency_key)
        ) $charset;

        CREATE TABLE {$wpdb->prefix}fd_ucp_carts (
            id VARCHAR(64) NOT NULL,
            line_items LONGTEXT NOT NULL,
            agent_fingerprint VARCHAR(128) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY agent_fingerprint (agent_fingerprint)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function flush_rules(): void {
        FD_UCP_Discovery::add_rewrite_rules();
        flush_rewrite_rules();
    }
}
