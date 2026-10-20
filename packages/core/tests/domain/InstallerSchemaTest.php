<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class InstallerSchemaTest extends TestCase {

    private FD_Test_Wpdb $db;

    protected function setUp(): void {
        FD_Test_WP::reset();
        $this->db        = new FD_Test_Wpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    private function legacy(): void {
        $this->db->schema = FD_Test_Wpdb::legacy_schema();
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.3.0';
    }

    public function test_upgrade_issues_one_atomic_alter_per_table_and_reaches_the_new_schema(): void {
        $this->legacy();

        FD_UCP_Installer::maybe_upgrade();

        $this->assertCount( 2, $this->db->alters );
        $this->assertStringContainsString( 'ALTER TABLE `wp_fd_ucp_checkout_sessions`', $this->db->alters[0] );
        $this->assertStringContainsString( 'ADD COLUMN platform_id VARCHAR(191) NULL', $this->db->alters[0] );
        $this->assertStringContainsString( 'ADD UNIQUE KEY platform_idempotency (platform_id, idempotency_key)', $this->db->alters[0] );
        $this->assertStringContainsString( 'DROP INDEX idempotency_key', $this->db->alters[0] );
        $this->assertStringContainsString( 'DROP COLUMN session_token_hash', $this->db->alters[0] );
        $this->assertStringContainsString( 'ADD COLUMN session_secret_hash CHAR(64) NULL', $this->db->alters[0] );
        $this->assertStringContainsString( 'ALTER TABLE `wp_fd_ucp_carts`', $this->db->alters[1] );
        $this->assertStringContainsString( 'ADD COLUMN cart_secret_hash CHAR(64) NULL', $this->db->alters[1] );
        $this->assertStringContainsString( 'DROP COLUMN session_token_hash', $this->db->alters[1] );

        $sessions = $this->db->schema['wp_fd_ucp_checkout_sessions'];
        $this->assertContains( 'platform_idempotency', $sessions['indexes'] );
        $this->assertNotContains( 'idempotency_key', $sessions['indexes'] );
        $this->assertNotContains( 'session_token_hash', $sessions['columns'] );
        $this->assertNotContains( 'session_token_hash', $this->db->schema['wp_fd_ucp_carts']['columns'] );
        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_upgrade_from_1_6_1_adds_the_secret_columns_and_keeps_every_row(): void {
        $schema = FD_Test_Wpdb::current_schema();
        $schema['wp_fd_ucp_checkout_sessions']['columns'] = array_values( array_diff( $schema['wp_fd_ucp_checkout_sessions']['columns'], array( 'session_secret_hash' ) ) );
        $schema['wp_fd_ucp_carts']['columns']             = array_values( array_diff( $schema['wp_fd_ucp_carts']['columns'], array( 'cart_secret_hash' ) ) );
        $this->db->schema                                 = $schema;
        $this->db->sessions['s1']                         = array( 'id' => 's1', 'status' => 'payment_pending' );
        $this->db->carts['c1']                            = array( 'id' => 'c1' );
        FD_Test_WP::$options['fd_ucp_db_version']         = '1.6.1';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( array( 'id' => 's1', 'status' => 'payment_pending' ), $this->db->sessions['s1'] );
        $this->assertSame( array( 'id' => 'c1' ), $this->db->carts['c1'] );
        $this->assertCount( 2, $this->db->alters );
        foreach ( $this->db->alters as $alter ) {
            $this->assertStringNotContainsString( 'DELETE', $alter );
        }
        $this->assertStringContainsString( 'ADD COLUMN session_secret_hash CHAR(64) NULL', $this->db->alters[0] );
        $this->assertStringContainsString( 'ADD COLUMN cart_secret_hash CHAR(64) NULL', $this->db->alters[1] );
        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
        $this->assertContains( 'session_secret_hash', $this->db->schema['wp_fd_ucp_checkout_sessions']['columns'] );
        $this->assertContains( 'cart_secret_hash', $this->db->schema['wp_fd_ucp_carts']['columns'] );
    }

    public function test_upgrade_with_the_secret_columns_present_alters_nothing(): void {
        $this->db->sessions['s1']                 = array( 'id' => 's1' );
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.6.1';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertArrayHasKey( 's1', $this->db->sessions );
        $this->assertSame( array(), $this->db->alters );
        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_upgrade_is_idempotent(): void {
        $this->legacy();
        FD_UCP_Installer::maybe_upgrade();
        $this->db->alters = array();
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.5.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( array(), $this->db->alters );
        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_current_schema_is_never_altered(): void {
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.5.0';

        FD_UCP_Installer::maybe_upgrade();
        FD_UCP_Installer::install();

        $this->assertSame( array(), $this->db->alters );
    }

    public function test_old_idempotency_key_is_replaced_even_without_the_token_column(): void {
        $this->legacy();
        $this->db->schema['wp_fd_ucp_checkout_sessions']['columns'] = array( 'id', 'idempotency_key' );

        FD_UCP_Installer::maybe_upgrade();

        $this->assertContains( 'platform_idempotency', $this->db->schema['wp_fd_ucp_checkout_sessions']['indexes'] );
        $this->assertNotContains( 'idempotency_key', $this->db->schema['wp_fd_ucp_checkout_sessions']['indexes'] );
        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_failed_alter_leaves_the_schema_untouched_and_the_version_unrecorded(): void {
        $this->legacy();
        $this->db->fail_alters = true;

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( FD_Test_Wpdb::legacy_schema(), $this->db->schema );
        $this->assertSame( '1.3.0', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_failed_upgrade_is_retried_and_logged_once_per_throttle_window(): void {
        $this->legacy();
        $this->db->fail_alters = true;

        FD_UCP_Installer::maybe_upgrade();
        FD_UCP_Installer::maybe_upgrade();
        $this->assertCount( 4, $this->db->alters );
        $this->assertCount( 1, FD_Test_WP::$logs );

        $this->db->fail_alters = false;
        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( '1.6.2', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_table_definitions_carry_the_new_columns_and_key_only(): void {
        $source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-fd-ucp-installer.php' );

        $this->assertStringContainsString( 'UNIQUE KEY platform_idempotency (platform_id, idempotency_key)', $source );
        $this->assertStringNotContainsString( 'UNIQUE KEY idempotency_key', $source );
        $this->assertStringNotContainsString( 'session_token_hash CHAR', $source );
        $this->assertStringContainsString( 'session_secret_hash CHAR(64) NOT NULL,', $source );
        $this->assertStringContainsString( 'cart_secret_hash CHAR(64) NOT NULL,', $source );
        $this->assertStringNotContainsString( 'DELETE FROM', $source );
    }
}
