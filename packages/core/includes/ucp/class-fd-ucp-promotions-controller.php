<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Promotions_Controller {

	private const NAMESPACE = 'fd-ucp/v1';

	public function __construct( private FD_UCP_Checkout_Controller $checkout ) {
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/promotions/validate', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'validate' ),
			'permission_callback' => array( 'FD_UCP_Plugin', 'require_platform' ),
		) );

		register_rest_route( self::NAMESPACE, '/checkout-sessions/(?P<id>[a-f0-9-]+)/promotions', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'apply' ),
			'permission_callback' => array( 'FD_UCP_Plugin', 'require_platform' ),
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
			'ucp'           => FD_UCP_Request_Context::current()->wire()->envelope( array() ),
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
		$body = $request->get_json_params();
		$code = sanitize_text_field( $body['code'] ?? '' );

		if ( empty( $code ) ) {
			return FD_UCP_Error::response( 'missing_code', 'Promotion code is required', 400 );
		}

		return $this->checkout->apply_promotion( $request, $code );
	}
}
