<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class UcpErrorTest extends TestCase {

    public function test_error_response_structure(): void {
        $response = FD_UCP_Error::response( 'test_code', 'Something went wrong', 422 );

        $this->assertSame( 422, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'error', $data['ucp']['status'] );
        $this->assertSame( '2026-04-08', $data['ucp']['version'] );
        $this->assertSame( 'test_code', $data['messages'][0]['code'] );
        $this->assertSame( 'Something went wrong', $data['messages'][0]['content'] );
        $this->assertSame( 'fatal', $data['messages'][0]['severity'] );
    }

    public function test_default_status_is_400(): void {
        $response = FD_UCP_Error::response( 'bad_input', 'Bad' );
        $this->assertSame( 400, $response->get_status() );
    }
}
