<?php
/**
 * Helper functions for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get JSON params from request
 *
 * @param WP_REST_Request $request Request object.
 * @return array
 */
function awana_get_json( $request ) {
	return $request->get_json_params();
}

/**
 * Find order by digital invoice ID
 *
 * @param string $invoice_id Digital invoice ID.
 * @return WC_Order|false
 */
function awana_find_order_by_invoice_id( $invoice_id ) {
	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => 'crm_invoice_id',
			'meta_value' => $invoice_id,
			'return'     => 'ids',
		)
	);

	if ( empty( $orders ) ) {
		return false;
	}

	return wc_get_order( $orders[0] );
}

/**
 * Map digital status to WooCommerce status
 *
 * @param string $digital_status Status from digital system.
 * @return string WooCommerce status.
 */
function awana_map_status_digital_to_woo( $digital_status ) {
	switch ( $digital_status ) {
		case 'draft':
			return 'pending';
		case 'unpaid':
			return 'on-hold';
		case 'paid':
			return 'completed';
		case 'cancelled':
			return 'cancelled';
		case 'refunded':
			return 'refunded';
		default:
			return 'pending';
	}
}

/**
 * Split name into first and last name
 *
 * @param string $full_name Full name.
 * @return array Array with 'first' and 'last' keys.
 */
function awana_split_name( $full_name ) {
	$name_parts = explode( ' ', trim( $full_name ), 2 );
	return array(
		'first' => $name_parts[0] ?? '',
		'last'  => $name_parts[1] ?? '',
	);
}



