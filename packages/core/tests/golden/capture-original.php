<?php

[ , $orig, $inputs, $out, $lane ] = $argv;

require_once $orig . '/packages/core/tests/bootstrap.php';
require_once __DIR__ . '/capture-stubs.php';

$core = $orig . '/packages/core/includes';
foreach ( array(
    'payment/interface-fd-payment-handler.php',
    'payment/class-fd-payment-registry.php',
    'ucp/class-fd-ucp-error.php',
    'ucp/class-fd-ucp-address.php',
    'ucp/class-fd-ucp-status.php',
    'ucp/class-fd-ucp-formatter.php',
    'ucp/class-fd-ucp-discovery.php',
    'ucp/class-fd-ucp-cart-controller.php',
    'class-fd-ucp-plugin.php',
) as $file ) {
    require_once $core . '/' . $file;
}

$registry = new FD_Payment_Registry();
if ( 'prism' === $lane ) {
    $prism = $orig . '/packages/prism-payment/includes/prism';
    require_once $prism . '/class-fd-prism-client.php';
    require_once $prism . '/class-fd-prism-validator.php';
    require_once $prism . '/class-fd-prism-handler.php';
    $registry->register( new FD_Prism_Handler( 'https://gw.example', 'test-key' ) );
}

$read = static fn( string $name ): array => json_decode( file_get_contents( $inputs . '/' . $name ), true );

$session   = $read( 'checkout-session.json' );
$completed = $read( 'checkout-session-completed.json' );
$cart      = $read( 'cart.json' );
$errors    = $read( 'errors.json' );

$method = new ReflectionMethod( 'FD_UCP_Cart_Controller', 'format_cart_response' );
$method->setAccessible( true );
$cart_body = $method->invoke(
    $method->getDeclaringClass()->newInstanceWithoutConstructor(),
    $cart['cart_id'],
    $cart['line_items'],
    $cart['subtotal'],
    $cart['currency']
);

$documents = array(
    'profile'                   => FD_UCP_Formatter::format_profile( 'https://store.test/wp-json/fd-ucp/v1', $registry ),
    'checkout__create'          => FD_UCP_Formatter::format_checkout_session( $session, $registry ),
    'checkout__complete'        => FD_UCP_Formatter::format_complete_response( $completed, new WC_Order(), $registry ),
    'cart__create'              => $cart_body,
    'error__invalid_instrument' => FD_UCP_Error::response( 'invalid_instrument', $errors['invalid_instrument']['message'], $errors['invalid_instrument']['status'] )->get_data(),
    'error__checkout_not_found' => FD_UCP_Error::response( 'checkout_not_found', $errors['checkout_not_found']['message'], $errors['checkout_not_found']['status'] )->get_data(),
);

if ( ! is_dir( $out ) ) {
    mkdir( $out, 0777, true );
}
foreach ( $documents as $name => $document ) {
    file_put_contents( $out . '/' . $name . '.json', json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
}
