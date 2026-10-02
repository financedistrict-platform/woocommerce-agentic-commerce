<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class InstallerSeedTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
        $GLOBALS['wpdb'] = new FD_Test_Wpdb();
    }

    public function test_fresh_install_seeds_the_latest_version(): void {
        FD_UCP_Installer::install();

        $this->assertSame( FD_UCP_Version_Registry::LATEST, FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
        $this->assertArrayNotHasKey( FD_UCP_Version_Registry::OPTION_SUPPORTED, FD_Test_WP::$options );
        $this->assertSame( FD_UCP_DB_VERSION, FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_upgrade_from_a_release_without_the_option_keeps_the_single_version_it_served(): void {
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.1.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( FD_UCP_Version_Registry::SINGLE_VERSION_RELEASE_CURRENT, FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
        $this->assertSame( FD_UCP_DB_VERSION, FD_Test_WP::$options['fd_ucp_db_version'] );
        $versions = FD_UCP_Version_Registry::from_options();
        $this->assertSame( FD_UCP_Version_Registry::SINGLE_VERSION_RELEASE_CURRENT, $versions->current() );
        $this->assertSame( array( '2026-08-25', '2026-01-23' ), $versions->supported() );
    }

    public function test_existing_supported_list_is_never_overwritten(): void {
        FD_Test_WP::$options['fd_ucp_db_version']                         = '1.1.0';
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_SUPPORTED ] = array( '2026-01-23' );

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( array( '2026-01-23' ), FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_SUPPORTED ] );
    }

    public function test_activation_over_an_upgraded_store_keeps_the_pinned_version(): void {
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.1.0';

        FD_UCP_Installer::install();

        $this->assertSame( FD_UCP_Version_Registry::SINGLE_VERSION_RELEASE_CURRENT, FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
    }

    public function test_existing_option_is_never_overwritten(): void {
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] = '2026-01-23';

        FD_UCP_Installer::install();
        $this->assertSame( '2026-01-23', FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );

        FD_Test_WP::$options['fd_ucp_db_version'] = '1.1.0';
        FD_UCP_Installer::maybe_upgrade();
        $this->assertSame( '2026-01-23', FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
    }

    public function test_reactivation_keeps_the_admin_choice(): void {
        FD_UCP_Installer::install();
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] = '2026-04-08';

        FD_UCP_Installer::install();

        $this->assertSame( '2026-04-08', FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
    }

    public function test_upgrade_check_on_a_current_store_changes_nothing(): void {
        FD_UCP_Installer::install();
        $before = FD_Test_WP::$options;

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( $before, FD_Test_WP::$options );
    }
}
