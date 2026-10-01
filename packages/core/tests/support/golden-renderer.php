<?php

final class FD_Test_Golden_Renderer {

    public const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

    public static function fixtures(): string {
        return dirname( __DIR__ ) . '/fixtures';
    }

    public static function input( string $name ): array {
        return json_decode( file_get_contents( self::fixtures() . '/ucp/inputs/' . $name ), true );
    }

    public static function names( string $version ): array {
        $names = array( 'profile', 'checkout__create', 'checkout__complete', 'cart__create', 'error__invalid_instrument', 'error__checkout_not_found' );
        $wire  = ( new FD_UCP_Version_Registry( $version, array() ) )->wire( $version );
        return $wire->supports( 'cart' ) ? $names : array_values( array_diff( $names, array( 'cart__create' ) ) );
    }

    public static function render( string $version, FD_Payment_Registry $registry, array $supported = array() ): array {
        $versions = new FD_UCP_Version_Registry( $version, $supported );
        $context  = new FD_UCP_Request_Context( $versions, $version );
        FD_UCP_Request_Context::set( $context );
        $wire = $context->wire();

        $errors    = self::input( 'errors.json' );
        $documents = array(
            'profile'            => $wire->profile( 'https://store.test/wp-json/fd-ucp/v1', $registry, $context->supported_versions_map() ),
            'checkout__create'   => $wire->checkout_session( self::input( 'checkout-session.json' ), $registry ),
            'checkout__complete' => $wire->complete_response( self::input( 'checkout-session-completed.json' ), new WC_Order(), $registry ),
        );

        if ( $wire->supports( 'cart' ) ) {
            $cart   = self::input( 'cart.json' );
            $method = new ReflectionMethod( 'FD_UCP_Cart_Controller', 'format_cart_response' );
            $documents['cart__create'] = $method->invoke(
                $method->getDeclaringClass()->newInstanceWithoutConstructor(),
                $cart['cart_id'],
                $cart['line_items'],
                $cart['subtotal'],
                $cart['currency']
            );
        }

        foreach ( array( 'invalid_instrument', 'checkout_not_found' ) as $code ) {
            $documents[ 'error__' . $code ] = FD_UCP_Error::response( $code, $errors[ $code ]['message'], $errors[ $code ]['status'] )->get_data();
        }

        FD_UCP_Request_Context::set( null );
        return array_map( static fn( $document ): string => json_encode( $document, self::FLAGS ), $documents );
    }

    public static function prism_registry( string $recorded ): FD_Payment_Registry {
        FD_Test_WP::$http_response = array(
            'body'     => file_get_contents( self::fixtures() . '/prism/' . $recorded ),
            'response' => array( 'code' => 200 ),
        );
        $registry = new FD_Payment_Registry();
        $registry->register( new FD_Prism_Handler( 'https://gw.example', 'test-key' ) );
        return $registry;
    }
}
