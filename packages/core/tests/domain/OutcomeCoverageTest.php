<?php
declare( strict_types=1 );

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutcomeCoverageTest extends TestCase {

    private const PROFILE = 'https://agent.example/.well-known/ucp';

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

    public static function outcomes(): array {
        $cases = array();
        foreach ( ( new ReflectionClass( FD_UCP_Request_Context::class ) )->getReflectionConstants() as $constant ) {
            if ( str_starts_with( $constant->getName(), 'OUTCOME_' ) ) {
                $cases[ $constant->getName() ] = array( $constant->getValue() );
            }
        }
        return $cases;
    }

    private static function rejecting_scenarios(): array {
        return array(
            FD_UCP_Request_Context::OUTCOME_UNKNOWN    => array( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-01-11' ), FD_UCP_Version_Registry::DEFAULT_SUPPORTED ),
            FD_UCP_Request_Context::OUTCOME_DISABLED   => array( FD_Test_Fixture_Profile_Fetcher::declaring( '2026-04-08' ), array() ),
            FD_UCP_Request_Context::OUTCOME_REDIRECTED => array( FD_Test_Fixture_Profile_Fetcher::redirecting( 'https://other.example/p' ), FD_UCP_Version_Registry::DEFAULT_SUPPORTED ),
        );
    }

    #[DataProvider( 'outcomes' )]
    public function test_every_outcome_is_a_fallback_or_a_lenient_rejection( string $outcome ): void {
        $exempt = array_merge( FD_UCP_Request_Context::FALLBACK_OUTCOMES, array( FD_UCP_Request_Context::OUTCOME_NONE, FD_UCP_Request_Context::OUTCOME_MATCHED ) );
        if ( in_array( $outcome, $exempt, true ) ) {
            $this->addToAssertionCount( 1 );
            return;
        }

        $scenarios = self::rejecting_scenarios();
        $this->assertArrayHasKey( $outcome, $scenarios, "Outcome $outcome is neither a fallback nor covered by a rejecting scenario" );

        list( $profile, $supported ) = $scenarios[ $outcome ];
        $versions = new FD_UCP_Version_Registry( FD_UCP_Version_Registry::DEFAULT_CURRENT, $supported, 'lenient' );
        $fetcher  = new FD_Test_Fixture_Profile_Fetcher( array( self::PROFILE => $profile ) );
        $context  = ( new FD_UCP_Version_Resolver( $versions, $fetcher ) )->resolve( 'profile="' . self::PROFILE . '"' );

        $this->assertSame( $outcome, $context->outcome() );
        $this->assertNotNull( $context->rejection(), "Outcome $outcome is silently served in lenient mode" );
    }
}
