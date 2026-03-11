<?php
/**
 * Order Handler class for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order Handler class
 */
class Awana_Order_Handler {

	/**
	 * Create or update order from invoice data
	 *
	 * @param array $data Invoice data from Digital system.
	 * @return WC_Order|WP_Error
	 */
	public static function create_or_update_order( $data ) {
		// Validate required fields
		$required = array( 'invoiceId', 'email', 'invoiceLines' );
		foreach ( $required as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new WP_Error(
					'bad_request',
					sprintf( 'Missing required field: %s', $field ),
					array( 'status' => 400 )
				);
			}
		}

		if ( ! is_array( $data['invoiceLines'] ) || count( $data['invoiceLines'] ) === 0 ) {
			return new WP_Error(
				'bad_request',
				'invoiceLines must be a non-empty array',
				array( 'status' => 400 )
			);
		}

		// Find or create order
		$order        = awana_find_order_by_invoice_id( $data['invoiceId'] );
		$is_new_order = false;

		if ( ! $order ) {
			// Create new order
			$order = wc_create_order();

			if ( is_wp_error( $order ) ) {
				Awana_Logger::error( 'Failed to create order', array( 'error' => $order->get_error_message() ) );
				return new WP_Error(
					'order_creation_failed',
					'Failed to create WooCommerce order',
					array( 'status' => 500 )
				);
			}

			$is_new_order = true;
			Awana_Logger::info( 'Order created', array( 'order_id' => $order->get_id(), 'invoice_id' => $data['invoiceId'] ) );
		} else {
			Awana_Logger::info( 'Order found, updating', array( 'order_id' => $order->get_id(), 'invoice_id' => $data['invoiceId'] ) );
		}

		// Set order data
		self::set_order_customer_data( $order, $data );
		$line_errors = self::set_order_line_items( $order, $data['invoiceLines'], $data );
		
		// Save order after setting line items to ensure meta is persisted before calculate_totals()
		$order->save();
		
		self::set_order_totals( $order, $data );
		self::set_order_status( $order, $data );
		self::set_order_meta( $order, $data );

		// Save order again after all updates
		$order->save();

		// Finalize order (convert from placeholder to real order if needed)
		self::finalize_order( $order );

		// Store line errors and is_new_order flag for REST response
		$order->awana_line_errors = $line_errors;
		$order->awana_is_new_order = $is_new_order;

		// Trigger action hook
		do_action( 'awana_digital_invoice_created', $order, $data );

		return $order;
	}

	/**
	 * Set customer data on order
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $data Invoice data.
	 */
	private static function set_order_customer_data( $order, $data ) {
		// Set guest customer (no customer_id)
		$order->set_customer_id( 0 );

		// Set billing information
		// Use firstName and lastName only (no fallbacks)
		$billing_first_name = $data['firstName'] ?? '';
		$billing_last_name = $data['lastName'] ?? '';

		$order->set_billing_email( $data['email'] );
		$order->set_billing_first_name( $billing_first_name );
		$order->set_billing_last_name( $billing_last_name );
		$order->set_billing_company( ! empty( $data['customerName'] ) ? $data['customerName'] : '' );

		// Extract address from shippingLines if available
		$shipping_address = null;
		if ( ! empty( $data['shippingLines'] ) && is_array( $data['shippingLines'] ) && count( $data['shippingLines'] ) > 0 ) {
			$shipping_address = $data['shippingLines'][0];
		}

		// Set country code - prefer shippingLines, then countryId, always default to NO (Norway)
		$country_code = 'NO';
		if ( $shipping_address && ! empty( $shipping_address['country'] ) ) {
			$country_from_address = strtoupper( trim( $shipping_address['country'] ) );
			// Only use if it's a valid country code, otherwise default to NO
			$country_code = ! empty( $country_from_address ) ? $country_from_address : 'NO';
		} elseif ( ! empty( $data['countryId'] ) ) {
			$country_from_data = strtoupper( trim( $data['countryId'] ) );
			// Only use if it's a valid country code, otherwise default to NO
			$country_code = ! empty( $country_from_data ) ? $country_from_data : 'NO';
		}
		// Always ensure country is set to NO (Norway) - this is the default
		$country_code = ! empty( $country_code ) ? $country_code : 'NO';

		// Set billing address - always set country (defaults to NO/Norway)
		$order->set_billing_country( $country_code );
		if ( $shipping_address ) {
			if ( ! empty( $shipping_address['address'] ) ) {
				$order->set_billing_address_1( $shipping_address['address'] );
			}
			if ( ! empty( $shipping_address['postalCode'] ) ) {
				$order->set_billing_postcode( $shipping_address['postalCode'] );
			}
			if ( ! empty( $shipping_address['postalArea'] ) ) {
				$order->set_billing_city( $shipping_address['postalArea'] );
			}
		}

		// Set shipping address (same as billing per user requirement)
		// Use firstName and lastName only (no fallbacks)
		$shipping_first_name = $data['firstName'] ?? '';
		$shipping_last_name = $data['lastName'] ?? '';
		
		$order->set_shipping_first_name( $shipping_first_name );
		$order->set_shipping_last_name( $shipping_last_name );
		$order->set_shipping_company( ! empty( $data['customerName'] ) ? $data['customerName'] : '' );
		$order->set_shipping_country( $country_code );
		if ( $shipping_address ) {
			if ( ! empty( $shipping_address['address'] ) ) {
				$order->set_shipping_address_1( $shipping_address['address'] );
			}
			if ( ! empty( $shipping_address['postalCode'] ) ) {
				$order->set_shipping_postcode( $shipping_address['postalCode'] );
			}
			if ( ! empty( $shipping_address['postalArea'] ) ) {
				$order->set_shipping_city( $shipping_address['postalArea'] );
			}
		}
	}

	/**
	 * Set line items on order
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $invoice_lines Array of invoice line items.
	 * @param array    $order_data Order-level data (for pricesIncludeTax, etc.).
	 * @return array Array of errors encountered.
	 */
	private static function set_order_line_items( $order, $invoice_lines, $order_data = array() ) {
		// Remove existing line items if updating
		if ( $order->get_item_count() > 0 ) {
			foreach ( $order->get_items() as $item ) {
				$order->remove_item( $item->get_id() );
			}
		}

		$line_errors = array();

		// Add invoice lines
		foreach ( $invoice_lines as $line ) {
			$result = awana_add_product_line_to_order( $order, $line, $order_data );

			if ( ! $result['success'] ) {
				$line_errors[] = $result['error'];
				Awana_Logger::warning( 'Product not found', array( 'product_id' => $line['productId'] ?? 'N/A' ) );
			}
		}

		return $line_errors;
	}

	/**
	 * Set totals and payment method on order
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $data Invoice data.
	 */
	private static function set_order_totals( $order, $data ) {
		// Set currency
		$order->set_currency( $data['currency'] ?? 'NOK' );

		// Set payment method
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Faktura' );

		// Reapply custom prices and names after calculate_totals() to ensure they're not overwritten
		// This must be done after calculate_totals() because it recalculates from product prices
		$order->calculate_totals();
		
		// Reapply custom prices and descriptions from unitPrice if they were set
		// Get items fresh after calculate_totals() to ensure we have the latest data
		$items = $order->get_items();
		foreach ( $items as $item_id => $item ) {
			// Reapply custom price - set both subtotal and total to unitPrice * quantity
			// WooCommerce will calculate tax based on product tax settings
			$custom_price = wc_get_order_item_meta( $item_id, 'crm_custom_price', true );
			if ( $custom_price ) {
				$unit_price = floatval( wc_get_order_item_meta( $item_id, 'crm_unit_price', true ) );
				if ( $unit_price > 0 ) {
					$quantity = $item->get_quantity();
					$line_subtotal = $unit_price * $quantity;
					
					// Set both subtotal and total to the same value
					// WooCommerce will add tax to the total if the product has tax
					$item->set_subtotal( $line_subtotal );
					$item->set_total( $line_subtotal );
					
					// Save the item changes
					$item->save();
				}
			}
			
			// Reapply custom name/description
			$custom_name = wc_get_order_item_meta( $item_id, 'crm_custom_name', true );
			if ( ! empty( $custom_name ) ) {
				$item->set_name( $custom_name );
				$item->save();
			}
		}
		
		// Recalculate totals after setting custom prices to ensure tax is calculated correctly
		$order->calculate_totals();

		// Compare with expected total and log if mismatch
		$expected_total = ! empty( $data['total'] ) ? floatval( $data['total'] ) : 0;
		$actual_total   = $order->get_total();
		if ( abs( $expected_total - $actual_total ) > 0.01 ) {
			Awana_Logger::warning(
				'Total mismatch',
				array(
					'expected' => $expected_total,
					'actual'   => $actual_total,
					'diff'     => $expected_total - $actual_total,
					'order_id' => $order->get_id(),
				)
			);
		}
	}

	/**
	 * Set order status
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $data Invoice data.
	 */
	private static function set_order_status( $order, $data ) {
		$woo_status = awana_map_status_digital_to_woo( $data['status'] ?? 'pending' );
		$order->set_status( $woo_status );
	}

	/**
	 * Finalize order (convert from placeholder to real order)
	 *
	 * @param WC_Order $order WooCommerce order object.
	 */
	private static function finalize_order( $order ) {
		// Check if order is still a placeholder
		$order_type = $order->get_type();
		
		if ( $order_type === 'shop_order_placehold' ) {
			// Get current status
			$current_status = $order->get_status();
			
			// Force finalization by updating the post type directly
			// WooCommerce should convert placeholders automatically, but sometimes doesn't
			// This ensures the order is converted from placeholder to real order
			global $wpdb;
			$updated = $wpdb->update(
				$wpdb->posts,
				array( 'post_type' => 'shop_order' ),
				array( 'ID' => $order->get_id() ),
				array( '%s' ),
				array( '%d' )
			);
			
			// Clear cache to ensure changes are reflected
			if ( $updated !== false ) {
				clean_post_cache( $order->get_id() );
				
				Awana_Logger::info(
					'Order finalized',
					array(
						'order_id'   => $order->get_id(),
						'status'     => $current_status,
						'old_type'   => 'shop_order_placehold',
						'new_type'   => 'shop_order',
					)
				);
			}
		}
	}

	/**
	 * Set order meta data
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $data Invoice data.
	 */
	private static function set_order_meta( $order, $data ) {
		// Store all meta with crm_ prefix (no underscore)
		// invoiceId is required, but add safety check
		if ( ! empty( $data['invoiceId'] ) ) {
			$order->update_meta_data( 'crm_invoice_id', $data['invoiceId'] );
		}
		if ( ! empty( $data['memberId'] ) ) {
			$order->update_meta_data( 'crm_member_id', $data['memberId'] );
		}
		if ( ! empty( $data['organizationId'] ) ) {
			$order->update_meta_data( 'crm_organization_id', $data['organizationId'] );
		}
		if ( ! empty( $data['source'] ) ) {
			$order->update_meta_data( 'crm_source', $data['source'] );
		}
		// Initialize sync status as 'never_synced' for new orders from CRM
		$order->update_meta_data( 'crm_sync_woo', 'never_synced' );

		if ( ! empty( $data['pogCustomerNumber'] ) ) {
			$order->update_meta_data( 'pog_customer_number', $data['pogCustomerNumber'] );
			// Mark as already synced since it came from CRM API (CRM already knows about it)
			$order->update_meta_data( '_pog_customer_synced_to_crm', $data['pogCustomerNumber'] );
		}

		// Store POG custom fields from invoice
		if ( isset( $data['pogDepartmentId'] ) && is_numeric( $data['pogDepartmentId'] ) ) {
			$order->update_meta_data( 'pog_department_id', intval( $data['pogDepartmentId'] ) );
		}
		if ( ! empty( $data['pogOurReference'] ) ) {
			$order->update_meta_data( 'pog_our_reference', sanitize_text_field( $data['pogOurReference'] ) );
		}
		if ( ! empty( $data['pogYourReference'] ) ) {
			$order->update_meta_data( 'pog_your_reference', sanitize_text_field( $data['pogYourReference'] ) );
		}
		if ( isset( $data['pogOurReferenceId'] ) && is_numeric( $data['pogOurReferenceId'] ) ) {
			$order->update_meta_data( 'pog_our_reference_id', intval( $data['pogOurReferenceId'] ) );
		}
	}
}

