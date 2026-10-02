<?php
defined( 'ABSPATH' ) || exit;

interface FD_Versioned_Payment_Handler {

    public function get_ucp_discovery_handlers_for_version( string $ucp_version ): array;
}
