<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Request_Context {

    public const OUTCOME_NONE        = 'none';
    public const OUTCOME_MATCHED     = 'matched';
    public const OUTCOME_UNREACHABLE = 'unreachable';
    public const OUTCOME_UNDECLARED  = 'undeclared';
    public const OUTCOME_UNKNOWN     = 'unknown';
    public const OUTCOME_DISABLED    = 'disabled';
    public const OUTCOME_REDIRECTED  = 'redirected';

    public const FALLBACK_OUTCOMES = array( self::OUTCOME_UNREACHABLE, self::OUTCOME_UNDECLARED );

    private static ?self $current = null;

    private FD_UCP_Version_Registry $versions;
    private string $version;
    private string $outcome;
    private ?string $declared;
    private ?array $rejection;

    public function __construct( FD_UCP_Version_Registry $versions, string $version, string $outcome = self::OUTCOME_NONE, ?string $declared = null, ?array $rejection = null ) {
        $this->versions  = $versions;
        $this->version   = $version;
        $this->outcome   = $outcome;
        $this->declared  = $declared;
        $this->rejection = $rejection;
    }

    public static function current(): self {
        if ( null === self::$current ) {
            $versions       = FD_UCP_Version_Registry::from_options();
            self::$current = new self( $versions, $versions->current_wire()->version() );
        }
        return self::$current;
    }

    public static function set( ?self $context ): void {
        self::$current = $context;
    }

    public static function for_version( string $version, ?FD_UCP_Version_Registry $versions = null ): self {
        return new self( $versions ?? new FD_UCP_Version_Registry( $version, array() ), $version );
    }

    public function version(): string {
        return $this->version;
    }

    public function wire(): FD_UCP_Wire_Format {
        return $this->versions->wire( $this->version );
    }

    public function versions(): FD_UCP_Version_Registry {
        return $this->versions;
    }

    public function outcome(): string {
        return $this->outcome;
    }

    public function declared(): ?string {
        return $this->declared;
    }

    public function session_pin(): ?string {
        return self::OUTCOME_MATCHED === $this->outcome ? $this->version : null;
    }

    public function is_fallback(): bool {
        return in_array( $this->outcome, self::FALLBACK_OUTCOMES, true );
    }

    public function rejection(): ?array {
        return $this->rejection;
    }

    public function rejection_response(): ?WP_REST_Response {
        if ( null === $this->rejection ) {
            return null;
        }
        return new WP_REST_Response(
            $this->versions->current_wire()->error( $this->rejection['code'], $this->rejection['message'] ),
            $this->rejection['status']
        );
    }

    public function supported_versions_map(): array {
        $map = array();
        foreach ( $this->versions->enabled() as $version ) {
            if ( $version !== $this->version ) {
                $map[ $version ] = home_url( '/.well-known/ucp/' . $version );
            }
        }
        return $map;
    }
}
