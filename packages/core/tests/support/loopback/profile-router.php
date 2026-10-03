<?php
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

if ( '/p' === $path ) {
    http_response_code( 308 );
    header( 'Location: /p/' );
    return;
}
if ( '/p/' === $path ) {
    header( 'Content-Type: application/json' );
    echo json_encode( array( 'ucp' => array( 'version' => '2026-04-08' ) ) );
    return;
}
if ( '/big' === $path ) {
    header( 'Content-Type: application/json' );
    echo '{"ucp":{"version":"2026-04-08"}}' . str_repeat( ' ', 200000 );
    return;
}
if ( '/exact' === $path ) {
    $head = '{"ucp":{"version":"2026-04-08"},"pad":"';
    echo $head . str_repeat( 'a', 131072 - strlen( $head ) - 2 ) . '"}';
    return;
}
http_response_code( 404 );
