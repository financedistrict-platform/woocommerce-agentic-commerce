<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Cart_Controller {

	private const NAMESPACE = 'fd-ucp/v1';
	private const LIFETIME  = 6 * HOUR_IN_SECONDS;

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/carts', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_cart' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/carts/(?P<id>[a-f0-9-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_cart' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_cart' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_cart' ),
				'permission_callback' => '__return_true',
			),
		) );

		register_rest_route( self::NAMESPACE, '/carts/(?P<id>[a-f0-9-]+)/checkout', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'checkout' ),
			'permission_callback' => '__return_true',
		) );
	}

	// =========================================================================
	// Create
	// =========================================================================

	public function create_cart( WP_REST_Request $request ): WP_REST_Response {
		$body       = $request->get_json_params();
		$line_items = $body['line_items'] ?? null;

		if ( ! is_array( $line_items ) || empty( $line_items ) ) {
			return FD_UCP_Error::response( 'missing_line_items', 'line_items array is required', 400 );
		}

		$cart_id  = wp_generate_uuid4();
		$currency = get_woocommerce_currency();

		$formatted_items = FD_UCP_Checkout_Pricing::catalog_line_items( $line_items, false );
		if ( is_wp_error( $formatted_items ) ) {
			return FD_UCP_Error::response( $formatted_items->get_error_code(), $formatted_items->get_error_message(), 422 );
		}
		$cart_subtotal = FD_UCP_Checkout_Pricing::subtotal_of( $formatted_items );

		$now    = current_time( 'mysql', true );
		$stored = $this->insert_cart( array(
			'id'          => $cart_id,
			'line_items'  => wp_json_encode( $formatted_items ),
			'platform_id' => FD_UCP_Request_Context::current()->platform_id(),
			'ucp_version' => FD_UCP_Request_Context::current()->session_pin(),
			'created_at'  => $now,
			'updated_at'  => $now,
			'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + self::LIFETIME ),
		) );
		if ( ! $stored ) {
			return FD_UCP_Error::response( 'storage_unavailable', 'The cart could not be stored', 503 );
		}

		return new WP_REST_Response( $this->format_cart_response( $cart_id, $formatted_items, $cart_subtotal, $currency ), 201 );
	}

	// =========================================================================
	// Get
	// =========================================================================

	public function get_cart( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->owned_cart( $request );
		if ( $cart instanceof WP_REST_Response ) {
			return $cart;
		}

		$line_items = $this->current_line_items( $cart );
		if ( is_wp_error( $line_items ) ) {
			return FD_UCP_Error::response( $line_items->get_error_code(), $line_items->get_error_message(), 422 );
		}

		return new WP_REST_Response(
			$this->format_cart_response( $cart['id'], $line_items, FD_UCP_Checkout_Pricing::subtotal_of( $line_items ), get_woocommerce_currency() ),
			200
		);
	}

	// =========================================================================
	// Update
	// =========================================================================

	public function update_cart( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->owned_cart( $request );
		if ( $cart instanceof WP_REST_Response ) {
			return $cart;
		}

		$body       = $request->get_json_params();
		$line_items = $body['line_items'] ?? null;

		if ( ! is_array( $line_items ) || empty( $line_items ) ) {
			return FD_UCP_Error::response( 'missing_line_items', 'line_items array is required', 400 );
		}

		$currency        = get_woocommerce_currency();
		$formatted_items = FD_UCP_Checkout_Pricing::catalog_line_items( $line_items, false );
		if ( is_wp_error( $formatted_items ) ) {
			return FD_UCP_Error::response( $formatted_items->get_error_code(), $formatted_items->get_error_message(), 422 );
		}
		$cart_subtotal = FD_UCP_Checkout_Pricing::subtotal_of( $formatted_items );

		$this->update_cart_row( $cart['id'], array(
			'line_items' => wp_json_encode( $formatted_items ),
			'updated_at' => current_time( 'mysql', true ),
		) );

		return new WP_REST_Response(
			$this->format_cart_response( $cart['id'], $formatted_items, $cart_subtotal, $currency ),
			200
		);
	}

	// =========================================================================
	// Checkout
	// =========================================================================

	public function checkout( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->owned_cart( $request );
		if ( $cart instanceof WP_REST_Response ) {
			return $cart;
		}

		$line_items = $this->current_line_items( $cart );
		if ( is_wp_error( $line_items ) ) {
			return FD_UCP_Error::response( $line_items->get_error_code(), $line_items->get_error_message(), 422 );
		}

		$totals = FD_UCP_Checkout_Pricing::priced_totals( FD_UCP_Checkout_Pricing::subtotal_of( $line_items ), 0 );
		if ( is_wp_error( $totals ) ) {
			return FD_UCP_Error::response( $totals->get_error_code(), $totals->get_error_message(), 422 );
		}

		$currency   = get_woocommerce_currency();
		$session_id = wp_generate_uuid4();

		$now = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
		$stored = $wpdb->insert( "{$wpdb->prefix}fd_ucp_checkout_sessions", array(
			'id'                => $session_id,
			'status'            => 'incomplete',
			'currency'          => $currency,
			'line_items'        => wp_json_encode( $line_items ),
			'totals'            => wp_json_encode( $totals ),
			'buyer'             => null,
			'fulfillment'       => null,
			'payment_meta'      => null,
			'platform_id'       => $cart['platform_id'],
			'ucp_version'       => FD_UCP_Request_Context::current()->session_pin(),
			'created_at'        => $now,
			'updated_at'        => $now,
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ),
		) );
		if ( false === $stored ) {
			return FD_UCP_Error::response( 'storage_unavailable', 'The checkout session could not be stored', 503 );
		}

		$this->delete_cart_row( $cart['id'] );

		$checkout_url = home_url( '/wp-json/' . self::NAMESPACE . '/checkout-sessions/' . $session_id );

		return new WP_REST_Response( array(
			'ucp'          => FD_UCP_Request_Context::current()->wire()->envelope( array( 'cart' ) ),
			'checkout_session_id' => $session_id,
			'checkout_url'        => $checkout_url,
			'line_items'          => $line_items,
			'totals'              => $totals,
			'currency'            => $currency,
		), 201 );
	}

	// =========================================================================
	// Delete
	// =========================================================================

	public function delete_cart( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->owned_cart( $request );
		if ( $cart instanceof WP_REST_Response ) {
			return $cart;
		}

		$this->delete_cart_row( $cart['id'] );

		return new WP_REST_Response( null, 204 );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function format_cart_response( string $cart_id, array $line_items, int $subtotal, string $currency ): array {
		return array(
			'ucp'        => FD_UCP_Request_Context::current()->wire()->envelope( array( 'cart' ) ),
			'id'         => $cart_id,
			'currency'   => $currency,
			'line_items' => $line_items,
			'totals'     => FD_UCP_Request_Context::current()->wire()->cart_totals( $subtotal ),
		);
	}

	private function current_line_items( array $cart ): array|WP_Error {
		return FD_UCP_Checkout_Pricing::catalog_line_items( json_decode( $cart['line_items'] ?? '[]', true ) ?: array(), true );
	}

	private function owned_cart( WP_REST_Request $request ): array|WP_REST_Response {
		$cart = $this->load_cart( (string) $request->get_param( 'id' ) );
		if ( ! $cart || ! FD_UCP_Ownership::owns_row( $cart ) ) {
			return FD_UCP_Error::response( 'cart_not_found', 'Cart not found', 404 );
		}

		$pin = FD_UCP_Plugin::instance()->pin_session( $request, $cart['ucp_version'] ?? null );
		return null !== $pin ? $pin : $cart;
	}

	// =========================================================================
	// Database
	// =========================================================================

	private function insert_cart( array $data ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
		return false !== $wpdb->insert( "{$wpdb->prefix}fd_ucp_carts", $data );
	}

	private function load_cart( string $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_carts WHERE id = %s", $id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$expires_at = strtotime( (string) ( $row['expires_at'] ?? '' ) );
		if ( false === $expires_at || $expires_at < time() ) {
			$this->delete_cart_row( $id );
			return null;
		}

		return $row;
	}

	private function update_cart_row( string $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$wpdb->update( "{$wpdb->prefix}fd_ucp_carts", $data, array( 'id' => $id ) );
	}

	private function delete_cart_row( string $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$wpdb->delete( "{$wpdb->prefix}fd_ucp_carts", array( 'id' => $id ) );
	}
}
