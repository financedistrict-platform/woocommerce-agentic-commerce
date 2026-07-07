<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Buyer_Identity_Controller {

	private const NAMESPACE = 'fd-ucp/v1';

	private const BUYER_FIELDS = array( 'email', 'first_name', 'last_name', 'phone', 'company' );

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)/buyer', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_buyer' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'update_buyer' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	public function get_buyer( WP_REST_Request $request ): WP_REST_Response {
		$session = $this->load_session( $request );
		if ( $session instanceof WP_REST_Response ) {
			return $session;
		}

		$buyer = json_decode( $session['buyer'] ?? '{}', true ) ?: array();

		return new WP_REST_Response( $this->envelope( $session, $buyer ), 200 );
	}

	public function update_buyer( WP_REST_Request $request ): WP_REST_Response {
		$session = $this->load_session( $request );
		if ( $session instanceof WP_REST_Response ) {
			return $session;
		}

		$existing = json_decode( $session['buyer'] ?? '{}', true ) ?: array();
		$body     = $request->get_json_params();

		foreach ( self::BUYER_FIELDS as $field ) {
			if ( ! isset( $body[ $field ] ) ) {
				continue;
			}
			$existing[ $field ] = 'email' === $field
				? sanitize_email( $body[ $field ] )
				: sanitize_text_field( $body[ $field ] );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$wpdb->update(
			"{$wpdb->prefix}fd_ucp_checkout_sessions",
			array(
				'buyer'      => wp_json_encode( $existing ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $session['id'] ),
			array( '%s', '%s' ),
			array( '%s' )
		);

		return new WP_REST_Response( $this->envelope( $session, $existing ), 200 );
	}

	private function load_session( WP_REST_Request $request ) {
		$id = $request->get_param( 'id' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table
		$session = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fd_ucp_checkout_sessions WHERE id = %s", $id ),
			ARRAY_A
		);

		if ( ! $session ) {
			return FD_UCP_Error::response( 'session_not_found', 'Session not found', 404 );
		}

		$stored = $session['agent_fingerprint'] ?? '';
		if ( ! empty( $stored ) ) {
			$agent      = $request->get_header( 'ucp-agent' ) ?? '';
			$request_fp = hash( 'sha256', $agent );
			if ( ! hash_equals( $stored, $request_fp ) ) {
				return FD_UCP_Error::response( 'session_not_found', 'Session not found', 403 );
			}
		}

		return $session;
	}

	private function envelope( array $session, array $buyer ): array {
		$result = array();
		foreach ( self::BUYER_FIELDS as $field ) {
			$result[ $field ] = $buyer[ $field ] ?? '';
		}

		return array(
			'ucp'        => array(
				'version'      => '2026-04-08',
				'status'       => 'success',
				'capabilities' => array(
					'dev.ucp.shopping.buyer_identity' => array( array(
						'version' => '2026-04-08',
						'extends' => 'dev.ucp.shopping.checkout',
					) ),
				),
			),
			'session_id' => $session['id'],
			'buyer'      => $result,
		);
	}
}
