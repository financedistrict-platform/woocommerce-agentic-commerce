<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Returns_Controller {

	private const NAMESPACE = 'fd-ucp/v1';

	private const REQUESTS_META = '_fd_ucp_return_requests';

	private FD_Rate_Limiter $rate_limiter;

	public function __construct() {
		$this->rate_limiter = new FD_Rate_Limiter( 10, 60 );
	}

	public function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/orders/(?P<id>[\d]+)/returns', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_return' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_returns' ),
				'permission_callback' => '__return_true',
			),
		) );
	}

	public function create_return( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->resolve_order( $request );
		if ( $order instanceof WP_REST_Response ) {
			return $order;
		}

		$rl = $this->rate_limiter->check( 'returns_create' );
		if ( is_wp_error( $rl ) ) {
			return FD_UCP_Error::response( $rl->get_error_code(), $rl->get_error_message(), 429 );
		}

		$items     = $request->get_json_params()['items'] ?? array();
		$requested = array();
		$reasons   = array();

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$line_item_id = (int) ( $item['line_item_id'] ?? 0 );
			$qty          = (int) ( $item['quantity'] ?? 0 );

			$line_item = $order->get_item( $line_item_id );
			if ( ! $line_item ) {
				return FD_UCP_Error::response( 'invalid_line_item', "Line item {$line_item_id} not found on this order", 422 );
			}
			if ( $qty < 1 || $qty > $line_item->get_quantity() ) {
				return FD_UCP_Error::response( 'invalid_quantity', "Quantity for line item {$line_item_id} must be between 1 and {$line_item->get_quantity()}", 422 );
			}

			$requested[] = array(
				'line_item_id' => (string) $line_item_id,
				'quantity'     => $qty,
			);
			if ( ! empty( $item['reason'] ) ) {
				$reasons[] = sanitize_text_field( (string) $item['reason'] );
			}
		}

		$return = array(
			'id'         => wp_generate_uuid4(),
			'status'     => 'requested',
			'reason'     => $reasons ? implode( '; ', $reasons ) : 'Full order return',
			'items'      => $requested,
			'created_at' => current_time( 'mysql', true ),
		);

		$history   = $this->return_requests( $order );
		$history[] = $return;
		$order->update_meta_data( self::REQUESTS_META, $history );
		$order->add_order_note( 'UCP buyer requested a return: ' . $return['reason'] . '. Review it and issue any refund from the order screen.' );
		$order->save();

		return new WP_REST_Response( array(
			'ucp'    => FD_UCP_Request_Context::current()->wire()->envelope( array() ),
			'return' => $return,
		), 202 );
	}

	public function list_returns( WP_REST_Request $request ): WP_REST_Response {
		$order = $this->resolve_order( $request );
		if ( $order instanceof WP_REST_Response ) {
			return $order;
		}

		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refunds[] = array(
				'id'         => (string) $refund->get_id(),
				'amount'     => FD_UCP_Formatter::to_minor( (float) $refund->get_amount() ),
				'reason'     => $refund->get_reason(),
				'status'     => 'completed',
				'created_at' => $refund->get_date_created()->format( 'c' ),
			);
		}

		return new WP_REST_Response( array(
			'ucp'     => FD_UCP_Request_Context::current()->wire()->envelope( array() ),
			'refunds' => $refunds,
			'returns' => $this->return_requests( $order ),
		), 200 );
	}

	private function return_requests( WC_Order $order ): array {
		$requests = $order->get_meta( self::REQUESTS_META );
		return is_array( $requests ) ? $requests : array();
	}

	private function resolve_order( WP_REST_Request $request ): WC_Order|WP_REST_Response {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
		}

		if ( ! $order->get_meta( '_fd_ucp_handler_id' ) ) {
			return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
		}

		if ( ! FD_UCP_Session_Token::owns_order( $request, $order ) ) {
			return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
		}

		return $order;
	}
}
