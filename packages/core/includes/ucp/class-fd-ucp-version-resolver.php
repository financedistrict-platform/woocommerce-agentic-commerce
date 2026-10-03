<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Version_Resolver {

    private FD_UCP_Version_Registry $versions;
    private FD_UCP_Agent_Profile_Fetcher $fetcher;

    public function __construct( FD_UCP_Version_Registry $versions, FD_UCP_Agent_Profile_Fetcher $fetcher ) {
        $this->versions = $versions;
        $this->fetcher  = $fetcher;
    }

    public static function profile_url( ?string $agent_header ): ?string {
        if ( ! is_string( $agent_header ) || ! preg_match( '/profile="([^"]+)"/', $agent_header, $m ) ) {
            return null;
        }
        return $m[1];
    }

    public function resolve( ?string $agent_header, ?string $pinned = null ): FD_UCP_Request_Context {
        $current = $this->versions->current();
        $pinned  = ( null !== $pinned && '' !== $pinned ) ? $pinned : null;
        $url     = self::profile_url( $agent_header );

        if ( null === $url ) {
            return $this->context( $pinned ?? $current, FD_UCP_Request_Context::OUTCOME_NONE );
        }

        $profile  = $this->fetcher->lookup( $url );
        $declared = empty( $profile['failed'] ) ? ( $profile['version'] ?? null ) : null;
        $outcome  = $this->outcome( $profile, $declared );
        $host     = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' );

        if ( FD_UCP_Request_Context::OUTCOME_MATCHED === $outcome ) {
            if ( null !== $pinned && $declared !== $pinned ) {
                return $this->reject( $declared, $outcome, $host, 422, 'version_unsupported', sprintf( 'This session is bound to UCP version %s; the agent profile now declares %s.', $pinned, $declared ) );
            }
            return $this->served( $declared, $outcome, $declared, $host );
        }

        if ( FD_UCP_Request_Context::OUTCOME_REDIRECTED === $outcome ) {
            $location = $profile['location'] ?? null;
            $message  = null === $location ? 'Agent profile URL redirects; use the final URL.' : sprintf( 'Agent profile URL redirects to %s; use the final URL.', $location );
            return $this->reject( $declared, $outcome, $host, 424, 'profile_redirected', $message, $location );
        }

        if ( FD_UCP_Request_Context::OUTCOME_DISABLED === $outcome || FD_UCP_Request_Context::OUTCOME_UNKNOWN === $outcome ) {
            return $this->reject( $declared, $outcome, $host, 422, 'version_unsupported', $this->unsupported_message( $declared ) );
        }

        if ( $this->versions->is_strict() ) {
            if ( FD_UCP_Request_Context::OUTCOME_UNREACHABLE === $outcome ) {
                return $this->reject( $declared, $outcome, $host, 424, 'profile_unreachable', 'Agent profile could not be retrieved.' );
            }
            return $this->reject( $declared, $outcome, $host, 422, 'profile_malformed', 'Agent profile does not declare a UCP version.' );
        }

        $served = $pinned ?? $current;
        wc_get_logger()->warning(
            sprintf( 'UCP agent profile resolution %s for %s, serving %s', $outcome, $host, $served ),
            array( 'source' => 'fd-ucp', 'ucp_profile_resolution' => $outcome )
        );
        return $this->served( $served, $outcome, $declared, $host );
    }

    private function outcome( array $profile, ?string $declared ): string {
        if ( ! empty( $profile['failed'] ) ) {
            return 'redirected' === ( $profile['reason'] ?? null ) ? FD_UCP_Request_Context::OUTCOME_REDIRECTED : FD_UCP_Request_Context::OUTCOME_UNREACHABLE;
        }
        if ( null === $declared ) {
            return FD_UCP_Request_Context::OUTCOME_UNDECLARED;
        }
        if ( ! FD_UCP_Version_Registry::is_known( $declared ) ) {
            return FD_UCP_Request_Context::OUTCOME_UNKNOWN;
        }
        if ( ! $this->versions->is_enabled( $declared ) ) {
            return FD_UCP_Request_Context::OUTCOME_DISABLED;
        }
        return FD_UCP_Request_Context::OUTCOME_MATCHED;
    }

    private function served( string $version, string $outcome, ?string $declared, string $host ): FD_UCP_Request_Context {
        do_action( 'fd_ucp_profile_resolution', $outcome, $version, $host );
        return $this->context( $version, $outcome, $declared );
    }

    private function reject( ?string $declared, string $outcome, string $host, int $status, string $code, string $message, ?string $location = null ): FD_UCP_Request_Context {
        do_action( 'fd_ucp_profile_resolution', $outcome, null, $host );
        wc_get_logger()->warning(
            sprintf( 'UCP agent profile resolution %s for %s rejected with %s', $outcome, $host, $code ),
            array( 'source' => 'fd-ucp', 'ucp_profile_resolution' => $outcome, 'location' => $location )
        );
        return new FD_UCP_Request_Context(
            $this->versions,
            $this->versions->current_wire()->version(),
            $outcome,
            $declared,
            array( 'status' => $status, 'code' => $code, 'message' => $message )
        );
    }

    private function context( string $version, string $outcome, ?string $declared = null ): FD_UCP_Request_Context {
        $version = FD_UCP_Version_Registry::is_known( $version ) ? $version : $this->versions->current_wire()->version();
        return new FD_UCP_Request_Context( $this->versions, $version, $outcome, $declared );
    }

    public function unsupported_message( string $requested ): string {
        return sprintf(
            'Version %s is not supported. This business implements versions %s.',
            $requested,
            implode( ', ', $this->versions->enabled() )
        );
    }
}
