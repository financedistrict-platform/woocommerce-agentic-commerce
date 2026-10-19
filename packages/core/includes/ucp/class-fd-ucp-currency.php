<?php
defined( 'ABSPATH' ) || exit;

final class FD_UCP_Currency {

    private const ZERO_DECIMALS  = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
    private const THREE_DECIMALS = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

    public static function exponent( string $code ): int {
        $code = strtoupper( trim( $code ) );
        if ( in_array( $code, self::ZERO_DECIMALS, true ) ) {
            return 0;
        }
        if ( in_array( $code, self::THREE_DECIMALS, true ) ) {
            return 3;
        }
        return 2;
    }

    public static function factor( string $code ): int {
        return 10 ** self::exponent( $code );
    }

    public static function to_minor( float $amount, string $currency ): int {
        return (int) round( $amount * self::factor( $currency ) );
    }

    public static function to_major( int $minor, string $currency ): float {
        return $minor / self::factor( $currency );
    }

    public static function to_major_string( int $minor, string $currency ): string {
        return number_format( $minor / self::factor( $currency ), self::exponent( $currency ), '.', '' );
    }
}
