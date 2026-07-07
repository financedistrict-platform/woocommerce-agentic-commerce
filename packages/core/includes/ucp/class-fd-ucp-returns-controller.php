<?php
defined( 'ABSPATH' ) || exit;

class FD_UCP_Returns_Controller {

	private const NAMESPACE = 'fd-ucp/v1';

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

		$items = $request->get_json_params()['items'] ?? array();

		if ( empty( $items ) ) {
			$refund_amount     = (float) $order->get_total();
			$reason            = 'Full order refund';
			$refund_line_items = array();
		} else {
			$refund_amount     = 0;
			$reason            = '';
			$refund_line_items = array();
			$reasons           = array();

			foreach ( $items as $item ) {
				$line_item_id = (int) ( $item['line_item_id'] ?? 0 );
				$qty          = (int) ( $item['quantity'] ?? 0 );

				$line_item = $order->get_item( $line_item_id );
				if ( ! $line_item ) {
					return FD_UCP_Error::response( 'invalid_line_item', "Line item {$line_item_id} not found on this order", 422 );
				}

				$unit_price     = (float) $line_item->get_total() / max( 1, $line_item->get_quantity() );
				$item_refund    = $unit_price * $qty;
				$refund_amount += $item_refund;

				$refund_line_items[ $line_item_id ] = array(
					'qty'          => $qty,
					'refund_total' => $item_refund,
				);

				if ( ! empty( $item['reason'] ) ) {
					$reasons[] = $item['reason'];
				}
			}

			$reason = implode( '; ', $reasons );
		}

		$refund = wc_create_refund( array(
			'order_id'   => $order->get_id(),
			'amount'     => $refund_amount,
			'reason'     => $reason,
			'line_items' => $refund_line_items,
		) );

		if ( is_wp_error( $refund ) ) {
			return FD_UCP_Error::response( 'refund_failed', $refund->get_error_message(), 500 );
		}

		$refund_items = array();
		foreach ( $refund->get_items() as $refund_item ) {
			$refund_items[] = array(
				'line_item_id' => (string) $refund_item->get_meta( '_refunded_item_id' ),
				'quantity'     => abs( $refund_item->get_quantity() ),
				'amount'       => FD_UCP_Formatter::to_minor( abs( (float) $refund_item->get_total() ) ),
			);
		}

		return new WP_REST_Response( array(
			'ucp'    => array(
				'version' => '2026-04-08',
				'status'  => 'success',
			),
			'refund' => array(
				'id'     => (string) $refund->get_id(),
				'amount' => FD_UCP_Formatter::to_minor( (float) $refund->get_amount() ),
				'status' => 'completed',
				'reason' => $refund->get_reason(),
				'items'  => $refund_items,
			),
		), 201 );
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
			'ucp'     => array(
				'version' => '2026-04-08',
				'status'  => 'success',
			),
			'refunds' => $refunds,
		), 200 );
	}

	private function resolve_order( WP_REST_Request $request ): WC_Order|WP_REST_Response {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
		}

		if ( ! $order->get_meta( '_fd_ucp_handler_id' ) ) {
			return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
		}

		$fingerprint = $order->get_meta( '_fd_ucp_agent_fingerprint' );
		if ( $fingerprint ) {
			$agent      = $request->get_header( 'ucp-agent' ) ?? '';
			$request_fp = hash( 'sha256', $agent );
			if ( ! hash_equals( $fingerprint, $request_fp ) ) {
				return FD_UCP_Error::response( 'order_not_found', 'Order not found', 404 );
			}
		}

		return $order;
	}
}
