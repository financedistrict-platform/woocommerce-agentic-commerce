<?php

final class FD_Test_Without_OpenSSL {

    public static function run( string $code ): array {
        $script = 'require ' . var_export( dirname( __DIR__ ) . '/bootstrap.php', true ) . '; ' . $code;
        $pipes  = array();
        $proc   = proc_open(
            array( PHP_BINARY, '-d', 'disable_functions=openssl_encrypt,openssl_decrypt', '-r', $script ),
            array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
            $pipes
        );
        if ( ! is_resource( $proc ) ) {
            throw new RuntimeException( 'Could not start the PHP subprocess' );
        }
        $stdout = stream_get_contents( $pipes[1] );
        $stderr = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $status = proc_close( $proc );

        $decoded = json_decode( $stdout, true );
        if ( 0 !== $status || ! is_array( $decoded ) ) {
            throw new RuntimeException( 'Subprocess failed (' . $status . '): ' . trim( $stdout . ' ' . $stderr ) );
        }
        return $decoded;
    }
}
