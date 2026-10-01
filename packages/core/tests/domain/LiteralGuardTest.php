<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class LiteralGuardTest extends TestCase {

    private const PATTERN = '/2026-01-23|2026-04-08|2026-08-25|ucp\.dev\/\d{4}-\d{2}-\d{2}/';

    public function test_version_dates_live_only_in_the_registry_and_wire_translators(): void {
        $packages = dirname( __DIR__, 3 );
        $allowed  = array(
            realpath( $packages . '/core/includes/ucp/wire' ),
            realpath( $packages . '/core/includes/ucp/class-fd-ucp-version-registry.php' ),
        );

        $hits = array();
        foreach ( array( $packages . '/core/includes', $packages . '/prism-payment/includes' ) as $root ) {
            $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $files as $file ) {
                $path = $file->getRealPath();
                if ( 'php' !== $file->getExtension() || $this->is_allowed( $path, $allowed ) ) {
                    continue;
                }
                foreach ( file( $path ) as $number => $line ) {
                    if ( preg_match( self::PATTERN, $line ) ) {
                        $hits[] = $path . ':' . ( $number + 1 );
                    }
                }
            }
        }

        $this->assertSame( array(), $hits );
    }

    private function is_allowed( string $path, array $allowed ): bool {
        foreach ( $allowed as $prefix ) {
            if ( $path === $prefix || 0 === strpos( $path, $prefix . DIRECTORY_SEPARATOR ) ) {
                return true;
            }
        }
        return false;
    }
}
