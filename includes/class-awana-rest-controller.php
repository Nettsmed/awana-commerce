<?php
/**
 * REST Controller class for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST Controller class
 */
class Awana_REST_Controller {

	/**
	 * Initialize the REST controller
	 */
	public static function init() {
		$instance = new self();
		add_action( 'rest_api_init', array( $instance, 'register_routes' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		register_rest_route(
			'awana/v1',
			'/invoice',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_invoice' ),
				'permission_callback' => array( $this, 'check_api_key' ),
			)
		);
	}

	/**
	 * Check API key authentication
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_api_key( $request ) {
		$api_key = $request->get_header( 'X-CRM-API-Key' );

		if ( ! $api_key ) {
			return new WP_Error(
				'missing_api_key',
				'Missing X-CRM-API-Key header',
				array( 'status' => 401 )
			);
		}

		$expected_key = defined( 'AWANA_DIGITAL_API_KEY' ) ? AWANA_DIGITAL_API_KEY : '';

		if ( empty( $expected_key ) ) {
			Awana_Logger::error( 'AWANA_DIGITAL_API_KEY not defined in wp-config.php', array( 'error' => 'config_missing' ) );
			return new WP_Error(
				'api_key_not_configured',
				'API key not configured on server',
				array( 'status' => 500 )
			);
		}

		if ( ! hash_equals( $expected_key, $api_key ) ) {
			Awana_Logger::warning( 'Invalid API key attempt', array( 'provided_key_length' => strlen( $api_key ) ) );
			return new WP_Error(
				'invalid_api_key',
				'Invalid API key',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle invoice creation/update
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_invoice( $request ) {
		$data = $request->get_json_params();

		Awana_Logger::info( 'Invoice received', array( 'invoice_id' => $data['invoiceId'] ?? 'unknown' ) );

		// Create or update order
		$order = Awana_Order_Handler::create_or_update_order( $data );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Get line errors and is_new_order flag from order handler
		$line_errors = ! empty( $order->awana_line_errors ) ? $order->awana_line_errors : array();
		$is_new_order = ! empty( $order->awana_is_new_order ) ? $order->awana_is_new_order : false;

		// Prepare response
		$response_data = array(
			'success'          => true,
			'wooOrderId'       => $order->get_id(),
			'wooOrderNumber'   => $order->get_order_number(),
			'wooStatus'        => $order->get_status(),
			'digitalInvoiceId' => $data['invoiceId'],
			'message'          => $is_new_order ? 'Order created from digital invoice' : 'Order updated from digital invoice',
		);

		// Add warnings if there were line item errors
		if ( ! empty( $line_errors ) ) {
			$response_data['warnings'] = $line_errors;
		}

		return new WP_REST_Response( $response_data, 200 );
	}
}

