<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

final class UcpErrorTest extends TestCase {

    protected function setUp(): void {
        FD_Test_WP::reset();
    }

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

    public function test_error_response_structure_in_2026_08_25(): void {
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::for_version( '2026-08-25' ) );

        $data = FD_UCP_Error::response( 'test_code', 'Something went wrong', 422 )->get_data();

        $this->assertSame( 'error', $data['ucp']['status'] );
        $this->assertSame( '2026-08-25', $data['ucp']['version'] );
        $this->assertSame( 'test_code', $data['messages'][0]['code'] );
        $this->assertSame( 'Something went wrong', $data['messages'][0]['content'] );
        $this->assertSame( 'unrecoverable', $data['messages'][0]['severity'] );
    }

    public function test_error_response_structure_in_2026_01_23(): void {
        FD_UCP_Request_Context::set( FD_UCP_Request_Context::for_version( '2026-01-23' ) );

        $data = FD_UCP_Error::response( 'test_code', 'Something went wrong', 422 )->get_data();

        $this->assertSame( '2026-01-23', $data['ucp']['version'] );
        $this->assertSame( 'requires_escalation', $data['status'] );
        $this->assertSame( 'requires_buyer_input', $data['messages'][0]['severity'] );
    }

    public function test_default_status_is_400(): void {
        $response = FD_UCP_Error::response( 'bad_input', 'Bad' );
        $this->assertSame( 400, $response->get_status() );
    }
}
