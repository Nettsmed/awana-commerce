<?php
/**
 * Product mapping functions for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find WooCommerce product by product ID from Digital system
 *
 * @param mixed $product_id Product ID from Digital system (can be WooCommerce product ID or SKU).
 * @return WC_Product|false
 */
function awana_find_product( $product_id ) {
	if ( empty( $product_id ) ) {
		return false;
	}

	// Try to find product by ID first
	$product = wc_get_product( $product_id );

	// If not found, try SKU
	if ( ! $product ) {
		$product_id_by_sku = wc_get_product_id_by_sku( $product_id );
		if ( $product_id_by_sku ) {
			$product = wc_get_product( $product_id_by_sku );
		}
	}

	return $product;
}

/**
 * Add product line to order
 *
 * @param WC_Order $order WooCommerce order object.
 * @param array    $line_data Line item data from Digital system.
 * @param array    $order_data Optional order-level data (e.g., pricesIncludeTax).
 * @return array Array with 'success' boolean and 'error' message if failed.
 */
function awana_add_product_line_to_order( $order, $line_data, $order_data = array() ) {
	$product = awana_find_product( $line_data['productId'] ?? null );

	if ( ! $product ) {
		return array(
			'success' => false,
			'error'   => sprintf( 'Product not found for productId: %s', $line_data['productId'] ?? 'N/A' ),
		);
	}

	$quantity = ! empty( $line_data['quantity'] ) ? absint( $line_data['quantity'] ) : 1;

	// Add product to order (WooCommerce will use product's price automatically)
	$item_id = $order->add_product( $product, $quantity );
	
	// Handle both cases: add_product() can return item ID (int) or item object
	if ( is_numeric( $item_id ) ) {
		$item = $order->get_item( $item_id );
	} else {
		$item = $item_id;
	}
	
	// Ensure we have a valid item object
	if ( ! $item || ! is_a( $item, 'WC_Order_Item_Product' ) ) {
		return array(
			'success' => false,
			'error'   => 'Failed to create order item',
		);
	}

	// Override price with unitPrice from API if provided
	// Only set the unit price - let WooCommerce handle tax according to its settings
	if ( isset( $line_data['unitPrice'] ) ) {
		$unit_price = floatval( $line_data['unitPrice'] );
		
		// Store the unit price in meta so we can reapply after calculate_totals()
		// Use wc_update_order_item_meta for order items to ensure it persists
		wc_update_order_item_meta( $item->get_id(), 'crm_unit_price', $unit_price );
		wc_update_order_item_meta( $item->get_id(), 'crm_custom_price', true );
	}
	
	// Override product title with description if provided
	// Store description in meta so we can reapply after calculate_totals()
	if ( ! empty( $line_data['description'] ) ) {
		// Update line item name - this overwrites the product title
		$item->set_name( $line_data['description'] );
		// Store in meta so we can reapply after calculate_totals()
		wc_update_order_item_meta( $item->get_id(), 'crm_custom_name', $line_data['description'] );
	}

	// Copy pog_product_id from product meta to order line item meta
	// so Integrera gets it per purchased product (same as checkout hook)
	$pog_product_id = get_post_meta( $product->get_id(), 'pog_product_id', true );
	if ( ! empty( $pog_product_id ) ) {
		$item->add_meta_data( 'pog_product_id', $pog_product_id, true );
		$item->save();
	}

	return array(
		'success' => true,
		'item'    => $item,
	);
}



