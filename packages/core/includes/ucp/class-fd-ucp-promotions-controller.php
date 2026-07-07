<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Promotions_Controller {

	private const NAMESPACE = 'fd-ucp/v1';

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/promotions/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)/promotions', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'apply' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function validate( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$code = sanitize_text_field( $body['code'] ?? '' );

		if ( empty( $code ) ) {
			return FD_UCP_Error::response( 'missing_code', 'Promotion code is required', 400 );
		}

		$coupon = new WC_Coupon( $code );

		if ( ! $coupon->get_id() ) {
			return FD_UCP_Error::response( 'coupon_not_found', 'Coupon not found', 404 );
		}

		$valid   = $coupon->is_valid();
		$result  = array(
			'ucp'           => array( 'version' => '2026-04-08', 'status' => 'success' ),
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
			'description'   => $coupon->get_description(),
			'valid'         => $valid,
		);

		if ( ! $valid ) {
			$errors = $coupon->get_error_message();
			$result['error_message'] = $errors ?: 'Coupon is not valid';
		}

		return new WP_REST_Response( $result, 200 );
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response {
		$id   = $request->get_param( 'id' );
		$body = $request->get_json_params();
		$code = sanitize_text_field( $body['code'] ?? '' );

		if ( empty( $code ) ) {
			return FD_UCP_Error::response( 'missing_code', 'Promotion code is required', 400 );
		}

		$session = $this->load_session( $id );
		if ( ! $session ) {
			return FD_UCP_Error::response( 'checkout_not_found', 'Checkout session not found', 404 );
		}

		$ownership = $this->verify_ownership( $request, $session );
		if ( is_wp_error( $ownership ) ) {
			return FD_UCP_Error::response( $ownership->get_error_code(), $ownership->get_error_message(), 403 );
		}

		if ( in_array( $session['status'], array( 'canceled', 'completed' ), true ) ) {
			return FD_UCP_Error::response( 'session_' . $session['status'], 'Session has been ' . $session['status'], 409 );
		}

		$coupon = new WC_Coupon( $code );

		if ( ! $coupon->get_id() ) {
			return FD_UCP_Error::response( 'coupon_not_found', 'Coupon not found', 404 );
		}

		if ( ! $coupon->is_valid() ) {
			$error_msg = $coupon->get_error_message() ?: 'Coupon is not valid';
			return FD_UCP_Error::response( 'coupon_invalid', $error_msg, 422 );
		}

		$totals       = json_decode( $session['totals'], true );
		$subtotal     = $this->extract_amount_by_type( $totals, 'subtotal' );
		$fulfillment  = $this->extract_amount_by_type( $totals, 'fulfillment' );
		$coupon_amount = $coupon->get_amount();
		$discount_type = $coupon->get_discount_type();

		switch ( $discount_type ) {
			case 'percent':
				$discount = (int) round( $subtotal * ( $coupon_amount / 100 ) );
				break;
			case 'fixed_cart':
				$discount = FD_UCP_Formatter::to_minor( (float) $coupon_amount );
				break;
			case 'fixed_product':
				$line_items   = json_decode( $session['line_items'], true );
				$total_items  = 0;
				foreach ( $line_items as $li ) {
					$total_items += (int) ( $li['quantity'] ?? 1 );
				}
				$discount = FD_UCP_Formatter::to_minor( (float) $coupon_amount ) * $total_items;
				break;
			default:
				return FD_UCP_Error::response( 'unsupported_discount_type', "Discount type $discount_type is not supported", 422 );
		}

		// Cap discount at subtotal
		if ( $discount > $subtotal ) {
			$discount = $subtotal;
		}

		$new_total  = $subtotal + $fulfillment - $discount;
		$new_totals = array(
			array( 'type' => 'subtotal', 'amount' => $subtotal ),
		);
		if ( $fulfillment > 0 ) {
			$new_totals[] = array( 'type' => 'fulfillment', 'amount' => $fulfillment );
		}
		$new_totals[] = array( 'type' => 'discount', 'amount' => $discount );
		$new_totals[] = array( 'type' => 'total', 'amount' => max( 0, $new_total ) );

		$payment_meta = json_decode( $session['payment_meta'] ?? '{}', true ) ?: array();
		$payment_meta['promotions'] = array(
			array(
				'code'          => $coupon->get_code(),
				'discount_type' => $discount_type,
				'amount'        => $coupon_amount,
				'discount'      => $discount,
			),
		);

		$this->update_session_row( $session['id'], array(
			'totals'       => wp_json_encode( $new_totals ),
			'payment_meta' => wp_json_encode( $payment_meta ),
			'updated_at'   => current_time( 'mysql', true ),
		) );

		return new WP_REST_Response( array(
			'ucp'    => array( 'version' => '2026-04-08', 'status' => 'success' ),
			'id'     => $session['id'],
			'totals' => $new_totals,
			'promotion_applied' => array(
				'code'          => $coupon->get_code(),
				'discount_type' => $discount_type,
				'amount'        => $coupon_amount,
				'discount'      => $discount,
			),
		), 200 );
	}

	private function verify_ownership( WP_REST_Request $request, array $session ): true|WP_Error {
		$stored = $session['agent_fingerprint'] ?? '';
		if ( empty( $stored ) ) {
			return true;
		}
		$agent   = $request->get_header( 'ucp-agent' ) ?? '';
		$current = hash( 'sha256', $agent );
		if ( ! hash_equals( $stored, $current ) ) {
			return new WP_Error( 'session_ownership', 'Session belongs to a different agent' );
		}
		return true;
	}

	private function extract_amount_by_type( array $totals, string $type ): int {
		foreach ( $totals as $t ) {
			if ( $type === ( $t['type'] ?? '' ) ) {
				return (int) $t['amount'];
			}
		}
		return 0;
	}

	private function load_session( string $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_checkout_sessions WHERE id = %s", $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function update_session_row( string $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$wpdb->update( "{$wpdb->prefix}fd_ucp_checkout_sessions", $data, array( 'id' => $id ) );
	}
}
