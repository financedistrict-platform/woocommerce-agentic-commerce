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

    public function test_upgrade_from_a_release_without_the_option_serves_the_latest_version(): void {
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.1.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( FD_UCP_Version_Registry::LATEST, FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
        $this->assertArrayNotHasKey( FD_UCP_Version_Registry::OPTION_SUPPORTED, FD_Test_WP::$options );
        $this->assertSame( FD_UCP_DB_VERSION, FD_Test_WP::$options['fd_ucp_db_version'] );
        $versions = FD_UCP_Version_Registry::from_options();
        $this->assertSame( FD_UCP_Version_Registry::LATEST, $versions->current() );
        $this->assertSame( FD_UCP_Version_Registry::DEFAULT_SUPPORTED, $versions->supported() );
    }

    public function test_existing_supported_list_is_never_overwritten(): void {
        FD_Test_WP::$options['fd_ucp_db_version']                         = '1.1.0';
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_SUPPORTED ] = array( '2026-01-23' );

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( array( '2026-01-23' ), FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_SUPPORTED ] );
    }

    public function test_activation_over_an_upgraded_store_keeps_the_stored_version(): void {
        FD_Test_WP::$options['fd_ucp_db_version']                       = '1.1.0';
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] = '2026-04-08';

        FD_UCP_Installer::install();

        $this->assertSame( '2026-04-08', FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] );
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

    public function test_reactivating_a_fresh_store_keeps_every_version_enabled(): void {
        FD_UCP_Installer::install();
        FD_UCP_Installer::install();

        $this->assertArrayNotHasKey( FD_UCP_Version_Registry::OPTION_SUPPORTED, FD_Test_WP::$options );
        $this->assertSame( array( '2026-08-25', '2026-04-08', '2026-01-23' ), FD_UCP_Version_Registry::from_options()->enabled() );
    }

    public function test_upgrade_over_a_store_with_a_current_version_leaves_the_supported_list_alone(): void {
        FD_Test_WP::$options[ FD_UCP_Version_Registry::OPTION_CURRENT ] = FD_UCP_Version_Registry::LATEST;
        FD_Test_WP::$options['fd_ucp_db_version']                       = '1.1.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertArrayNotHasKey( FD_UCP_Version_Registry::OPTION_SUPPORTED, FD_Test_WP::$options );
    }

    public function test_upgrade_keeps_registered_only_access_under_the_new_option(): void {
        FD_Test_WP::$options['fd_ucp_db_version']    = '1.6.0';
        FD_Test_WP::$options['fd_ucp_signed_access'] = 'registered';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( 'registered', FD_Test_WP::$options['fd_ucp_platform_access'] );
        $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
        $this->assertSame( FD_UCP_DB_VERSION, FD_Test_WP::$options['fd_ucp_db_version'] );
    }

    public function test_upgrade_turns_every_other_old_value_into_open(): void {
        foreach ( array( 'open', '', 'closed', 'REGISTERED', null, array(), 0 ) as $old ) {
            FD_Test_WP::reset();
            FD_Test_WP::$options['fd_ucp_db_version']    = '1.6.0';
            FD_Test_WP::$options['fd_ucp_signed_access'] = $old;

            FD_UCP_Installer::maybe_upgrade();

            $this->assertSame( 'open', FD_Test_WP::$options['fd_ucp_platform_access'], var_export( $old, true ) );
            $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
        }
    }

    public function test_upgrade_without_the_old_option_leaves_the_new_one_unset(): void {
        FD_Test_WP::$options['fd_ucp_db_version'] = '1.6.0';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertArrayNotHasKey( 'fd_ucp_platform_access', FD_Test_WP::$options );
        $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
    }

    public function test_upgrade_never_overwrites_a_mode_the_merchant_already_chose(): void {
        FD_Test_WP::$options['fd_ucp_db_version']      = '1.6.0';
        FD_Test_WP::$options['fd_ucp_signed_access']   = 'registered';
        FD_Test_WP::$options['fd_ucp_platform_access'] = 'authenticated';

        FD_UCP_Installer::maybe_upgrade();

        $this->assertSame( 'authenticated', FD_Test_WP::$options['fd_ucp_platform_access'] );
        $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
    }

    public function test_migration_runs_once_and_later_choices_stick(): void {
        FD_Test_WP::$options['fd_ucp_db_version']    = '1.6.0';
        FD_Test_WP::$options['fd_ucp_signed_access'] = 'registered';
        FD_UCP_Installer::maybe_upgrade();

        FD_Test_WP::$options['fd_ucp_platform_access'] = 'open';
        FD_UCP_Installer::maybe_upgrade();
        FD_UCP_Installer::install();

        $this->assertSame( 'open', FD_Test_WP::$options['fd_ucp_platform_access'] );
    }

    public function test_reactivation_over_an_old_store_migrates_the_option_too(): void {
        FD_Test_WP::$options['fd_ucp_signed_access'] = 'registered';

        FD_UCP_Installer::install();

        $this->assertSame( 'registered', FD_Test_WP::$options['fd_ucp_platform_access'] );
        $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
    }

    public function test_a_fresh_install_leaves_the_mode_to_the_open_default(): void {
        FD_UCP_Installer::install();

        $this->assertArrayNotHasKey( 'fd_ucp_platform_access', FD_Test_WP::$options );
        $this->assertArrayNotHasKey( 'fd_ucp_signed_access', FD_Test_WP::$options );
    }
}
