<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Cart_Controller {

	private const NAMESPACE = 'fd-ucp/v1';
	private const UCP_VERSION = '2026-04-08';

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

		$formatted_items = array();
		$cart_subtotal   = 0;

		foreach ( $line_items as $item ) {
			$product_id = (int) ( $item['item']['id'] ?? 0 );
			$quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$product    = wc_get_product( $product_id );

			if ( ! $product || ! $product->is_purchasable() ) {
				return FD_UCP_Error::response( 'invalid_product', "Product $product_id not found or not purchasable", 422 );
			}

			$price      = FD_UCP_Formatter::to_minor( (float) $product->get_price() );
			$item_total = $price * $quantity;
			$cart_subtotal += $item_total;

			$formatted_items[] = array(
				'id'       => 'li_' . ( count( $formatted_items ) + 1 ),
				'item'     => array(
					'id'    => (string) $product_id,
					'title' => $product->get_name(),
					'price' => $price,
				),
				'quantity' => $quantity,
				'totals'   => array(
					array( 'type' => 'subtotal', 'amount' => $item_total ),
					array( 'type' => 'total', 'amount' => $item_total ),
				),
			);
		}

		$now = current_time( 'mysql', true );
		$this->insert_cart( array(
			'id'                => $cart_id,
			'line_items'        => wp_json_encode( $formatted_items ),
			'agent_fingerprint' => $this->compute_fingerprint( $request ),
			'created_at'        => $now,
			'updated_at'        => $now,
		) );

		return new WP_REST_Response(
			$this->format_cart_response( $cart_id, $formatted_items, $cart_subtotal, $currency ),
			201
		);
	}

	// =========================================================================
	// Get
	// =========================================================================

	public function get_cart( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->load_cart( $request->get_param( 'id' ) );
		if ( ! $cart ) {
			return FD_UCP_Error::response( 'cart_not_found', 'Cart not found', 404 );
		}

		$ownership = $this->verify_cart_ownership( $request, $cart );
		if ( is_wp_error( $ownership ) ) {
			return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
		}

		$line_items    = json_decode( $cart['line_items'], true );
		$cart_subtotal = $this->sum_subtotals( $line_items );

		return new WP_REST_Response(
			$this->format_cart_response( $cart['id'], $line_items, $cart_subtotal, get_woocommerce_currency() ),
			200
		);
	}

	// =========================================================================
	// Update
	// =========================================================================

	public function update_cart( WP_REST_Request $request ): WP_REST_Response {
		$cart = $this->load_cart( $request->get_param( 'id' ) );
		if ( ! $cart ) {
			return FD_UCP_Error::response( 'cart_not_found', 'Cart not found', 404 );
		}

		$ownership = $this->verify_cart_ownership( $request, $cart );
		if ( is_wp_error( $ownership ) ) {
			return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
		}

		$body       = $request->get_json_params();
		$line_items = $body['line_items'] ?? null;

		if ( ! is_array( $line_items ) || empty( $line_items ) ) {
			return FD_UCP_Error::response( 'missing_line_items', 'line_items array is required', 400 );
		}

		$currency        = get_woocommerce_currency();
		$formatted_items = array();
		$cart_subtotal   = 0;

		foreach ( $line_items as $item ) {
			$product_id = (int) ( $item['item']['id'] ?? 0 );
			$quantity   = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$product    = wc_get_product( $product_id );

			if ( ! $product || ! $product->is_purchasable() ) {
				return FD_UCP_Error::response( 'invalid_product', "Product $product_id not found or not purchasable", 422 );
			}

			$price      = FD_UCP_Formatter::to_minor( (float) $product->get_price() );
			$item_total = $price * $quantity;
			$cart_subtotal += $item_total;

			$formatted_items[] = array(
				'id'       => 'li_' . ( count( $formatted_items ) + 1 ),
				'item'     => array(
					'id'    => (string) $product_id,
					'title' => $product->get_name(),
					'price' => $price,
				),
				'quantity' => $quantity,
				'totals'   => array(
					array( 'type' => 'subtotal', 'amount' => $item_total ),
					array( 'type' => 'total', 'amount' => $item_total ),
				),
			);
		}

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
		$cart = $this->load_cart( $request->get_param( 'id' ) );
		if ( ! $cart ) {
			return FD_UCP_Error::response( 'cart_not_found', 'Cart not found', 404 );
		}

		$ownership = $this->verify_cart_ownership( $request, $cart );
		if ( is_wp_error( $ownership ) ) {
			return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
		}

		$line_items    = json_decode( $cart['line_items'], true );
		$currency      = get_woocommerce_currency();
		$session_id    = wp_generate_uuid4();
		$cart_subtotal = $this->sum_subtotals( $line_items );

		$totals = array(
			array( 'type' => 'subtotal', 'amount' => $cart_subtotal ),
			array( 'type' => 'total', 'amount' => $cart_subtotal ),
		);

		$now = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
		$wpdb->insert( "{$wpdb->prefix}fd_ucp_checkout_sessions", array(
			'id'                => $session_id,
			'status'            => 'incomplete',
			'currency'          => $currency,
			'line_items'        => $cart['line_items'],
			'totals'            => wp_json_encode( $totals ),
			'buyer'             => null,
			'fulfillment'       => null,
			'payment_meta'      => null,
			'agent_fingerprint' => $cart['agent_fingerprint'],
			'created_at'        => $now,
			'updated_at'        => $now,
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 6 * HOUR_IN_SECONDS ),
		) );

		$this->delete_cart_row( $cart['id'] );

		$checkout_url = home_url( '/wp-json/' . self::NAMESPACE . '/checkout-sessions/' . $session_id );

		return new WP_REST_Response( array(
			'ucp'          => array(
				'version'      => self::UCP_VERSION,
				'status'       => 'success',
				'capabilities' => array(
					'dev.ucp.shopping.cart' => array( array( 'version' => self::UCP_VERSION ) ),
				),
			),
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
		$cart = $this->load_cart( $request->get_param( 'id' ) );
		if ( ! $cart ) {
			return FD_UCP_Error::response( 'cart_not_found', 'Cart not found', 404 );
		}

		$ownership = $this->verify_cart_ownership( $request, $cart );
		if ( is_wp_error( $ownership ) ) {
			return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
		}

		$this->delete_cart_row( $cart['id'] );

		return new WP_REST_Response( null, 204 );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function format_cart_response( string $cart_id, array $line_items, int $subtotal, string $currency ): array {
		return array(
			'ucp'        => array(
				'version'      => self::UCP_VERSION,
				'status'       => 'success',
				'capabilities' => array(
					'dev.ucp.shopping.cart' => array( array( 'version' => self::UCP_VERSION ) ),
				),
			),
			'id'         => $cart_id,
			'currency'   => $currency,
			'line_items' => $line_items,
			'totals'     => array(
				array( 'type' => 'subtotal', 'amount' => $subtotal ),
			),
		);
	}

	private function sum_subtotals( array $line_items ): int {
		$total = 0;
		foreach ( $line_items as $li ) {
			foreach ( $li['totals'] ?? array() as $t ) {
				if ( 'subtotal' === $t['type'] ) {
					$total += (int) $t['amount'];
				}
			}
		}
		return $total;
	}

	private function verify_cart_ownership( WP_REST_Request $request, array $cart ): true|WP_Error {
		$stored = $cart['agent_fingerprint'] ?? '';
		if ( empty( $stored ) ) {
			return true;
		}
		$current = $this->compute_fingerprint( $request );
		if ( ! hash_equals( $stored, $current ) ) {
			return new WP_Error( 'cart_ownership', 'Cart belongs to a different agent' );
		}
		return true;
	}

	private function compute_fingerprint( WP_REST_Request $request ): string {
		$agent = $request->get_header( 'ucp-agent' ) ?? '';
		return hash( 'sha256', $agent );
	}

	// =========================================================================
	// Database
	// =========================================================================

	private function insert_cart( array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table
		$wpdb->insert( "{$wpdb->prefix}fd_ucp_carts", $data );
	}

	private function load_cart( string $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_carts WHERE id = %s", $id ),
			ARRAY_A
		);
		return $row ?: null;
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
