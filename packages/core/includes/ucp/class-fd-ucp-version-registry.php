<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Version_Registry {

    public const LATEST                         = '2026-08-25';
    public const DEFAULT_CURRENT                = self::LATEST;
    public const SINGLE_VERSION_RELEASE_CURRENT = '2026-04-08';
    public const DEFAULT_SUPPORTED              = array( '2026-04-08', '2026-01-23' );

    public const NEGOTIATION_LENIENT = 'lenient';
    public const NEGOTIATION_STRICT  = 'strict';

    public const OPTION_CURRENT     = 'fd_ucp_version';
    public const OPTION_SUPPORTED   = 'fd_ucp_supported_versions';
    public const OPTION_NEGOTIATION = 'fd_ucp_version_negotiation';

    private const WIRES = array(
        '2026-08-25' => 'FD_UCP_Wire_20260825',
        '2026-04-08' => 'FD_UCP_Wire_20260408',
        '2026-01-23' => 'FD_UCP_Wire_20260123',
    );

    private string $current;
    private array $supported;
    private string $negotiation;
    private array $wires = array();

    public function __construct( string $current = self::DEFAULT_CURRENT, array $supported = self::DEFAULT_SUPPORTED, string $negotiation = self::NEGOTIATION_LENIENT ) {
        $this->current     = $current;
        $this->supported   = array_values( array_unique( array_filter(
            array_map( 'strval', $supported ),
            static fn( string $v ): bool => $v !== $current
        ) ) );
        $this->negotiation = $negotiation;
    }

    public static function from_options(): self {
        $supported = get_option( self::OPTION_SUPPORTED, self::DEFAULT_SUPPORTED );
        return new self(
            (string) get_option( self::OPTION_CURRENT, self::DEFAULT_CURRENT ),
            is_array( $supported ) ? $supported : self::DEFAULT_SUPPORTED,
            (string) get_option( self::OPTION_NEGOTIATION, self::NEGOTIATION_LENIENT )
        );
    }

    public static function known(): array {
        return array_keys( self::WIRES );
    }

    public static function negotiation_modes(): array {
        return array( self::NEGOTIATION_LENIENT, self::NEGOTIATION_STRICT );
    }

    public function current(): string {
        return $this->current;
    }

    public function supported(): array {
        return array_values( array_filter( $this->supported, array( self::class, 'is_known' ) ) );
    }

    public function enabled(): array {
        return array_merge( array( $this->current ), $this->supported() );
    }

    public function negotiation(): string {
        return $this->negotiation;
    }

    public function is_strict(): bool {
        return self::NEGOTIATION_STRICT === $this->negotiation;
    }

    public static function is_known( string $version ): bool {
        return isset( self::WIRES[ $version ] );
    }

    public function is_enabled( string $version ): bool {
        return in_array( $version, $this->enabled(), true );
    }

    public function wire( string $version ): FD_UCP_Wire_Format {
        if ( ! self::is_known( $version ) ) {
            throw new InvalidArgumentException( "Unknown UCP version: $version" );
        }
        if ( ! isset( $this->wires[ $version ] ) ) {
            $class                    = self::WIRES[ $version ];
            $this->wires[ $version ] = new $class();
        }
        return $this->wires[ $version ];
    }

    public function current_wire(): FD_UCP_Wire_Format {
        return $this->wire( self::is_known( $this->current ) ? $this->current : self::DEFAULT_CURRENT );
    }

    public function assert_valid(): ?string {
        if ( ! self::is_known( $this->current ) ) {
            return "Unknown UCP version: {$this->current}";
        }
        foreach ( $this->supported as $version ) {
            if ( ! self::is_known( $version ) ) {
                return "Unknown supported UCP version: $version";
            }
        }
        if ( ! in_array( $this->negotiation, self::negotiation_modes(), true ) ) {
            return "Unknown UCP version negotiation: {$this->negotiation}";
        }
        return null;
    }
}
