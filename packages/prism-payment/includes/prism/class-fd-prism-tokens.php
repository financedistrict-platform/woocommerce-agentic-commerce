<?php
defined( 'ABSPATH' ) || exit;

class FD_Prism_Tokens {

    private const KNOWN = array(
        '0x036cbd53842c5426634e7929541ec2318f3dcf7e' => array( 'symbol' => 'USDC', 'decimals' => 6 ),
        '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913' => array( 'symbol' => 'USDC', 'decimals' => 6 ),
        '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48' => array( 'symbol' => 'USDC', 'decimals' => 6 ),
        '0xaf88d065e77c8cc2239327c5edb3a432268e5831' => array( 'symbol' => 'USDC', 'decimals' => 6 ),
        '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359' => array( 'symbol' => 'USDC', 'decimals' => 6 ),
        '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d' => array( 'symbol' => 'USDC', 'decimals' => 18 ),
        '0xab27f55db008704ed8098f0dfbcf5e1aa387b9d9' => array( 'symbol' => 'FDUSD', 'decimals' => 18 ),
        '0xc5f0f7b66764f6ec8c8dff7ba683102295e16409' => array( 'symbol' => 'FDUSD', 'decimals' => 18 ),
    );

    public static function amount_label( string $atomic, string $asset ): string {
        if ( '' === $atomic || ! ctype_digit( $atomic ) ) {
            return '';
        }

        $token = self::KNOWN[ strtolower( $asset ) ] ?? null;
        if ( null === $token ) {
            return '' === $asset
                ? "$atomic atomic units"
                : "$atomic atomic units of $asset";
        }

        return self::to_decimal_string( $atomic, $token['decimals'] ) . ' ' . $token['symbol'];
    }

    private static function to_decimal_string( string $atomic, int $decimals ): string {
        $padded = str_pad( ltrim( $atomic, '0' ), $decimals + 1, '0', STR_PAD_LEFT );
        return substr( $padded, 0, -$decimals ) . '.' . substr( $padded, -$decimals );
    }
}
