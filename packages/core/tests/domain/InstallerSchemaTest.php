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
        $this->assertStringContainsString( 'ALTER TABLE `wp_fd_ucp_carts`', $this->db->alters[1] );
        $this->assertStringContainsString( 'DROP COLUMN session_token_hash', $this->db->alters[1] );

        $sessions = $this->db->schema['wp_fd_ucp_checkout_sessions'];
        $this->assertContains( 'platform_idempotency', $sessions['indexes'] );
        $this->assertNotContains( 'idempotency_key', $sessions['indexes'] );
        $this->assertNotContains( 'session_token_hash', $sessions['columns'] );
        $this->assertNotContains( 'session_token_hash', $this->db->schema['wp_fd_ucp_carts']['columns'] );
        $this->assertSame( '1.6.1', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_upgrade_is_idempotent(): void {
        $this->legacy();
        FD_UCP_Installer::maybe_upgrade();
        $this->db->alters = array();
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.5.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( array(), $this->db->alters );
        $this->assertSame( '1.6.1', FD_Test_WP::$options['fd_ucp_db_version'] );
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
        $this->assertSame( '1.6.1', FD_Test_WP::$options['fd_ucp_db_version'] );
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

        $this->assertSame( '1.6.1', FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_table_definitions_carry_the_new_columns_and_key_only(): void {
        $source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-fd-ucp-installer.php' );

        $this->assertStringContainsString( 'UNIQUE KEY platform_idempotency (platform_id, idempotency_key)', $source );
        $this->assertStringNotContainsString( 'UNIQUE KEY idempotency_key', $source );
        $this->assertStringNotContainsString( 'session_token_hash CHAR', $source );
    }
}
