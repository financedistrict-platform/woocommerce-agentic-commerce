<?php
defined( 'ABSPATH' ) || exit;

interface FD_UCP_Wire_Format {

    public function version(): string;

    public function profile( string $endpoint, FD_Payment_Registry $registry, array $supported_versions_map ): array;

    public function checkout_session( array $session, FD_Payment_Registry $registry ): array;

    public function complete_response( array $session, WC_Order $order, FD_Payment_Registry $registry ): array;

    public function hold_response( array $session, WC_Order $order, FD_Payment_Registry $registry ): array;

    public function envelope( array $capabilities ): array;

    public function cart_totals( int $subtotal ): array;

    public function error( string $code, string $message ): array;

    public function capabilities(): array;

    public function supports( string $logical ): bool;
}
