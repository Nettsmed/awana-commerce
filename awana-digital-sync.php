<?php
/**
 * Plugin Name: Awana Digital Sync
 * Plugin URI: https://awana.no
 * Description: Syncs invoices from Digital/CRM to WooCommerce as guest orders and handles POG/Integrera sync updates.
 * Version: 1.1.2
 * Author: Awana
 * Author URI: https://awana.no
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Text Domain: awana-digital-sync
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Check if WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

// Define plugin constants
define( 'AWANA_DIGITAL_SYNC_VERSION', '1.1.2' );
define( 'AWANA_DIGITAL_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'AWANA_DIGITAL_SYNC_URL', plugin_dir_url( __FILE__ ) );

// Include required files
include_once 'includes/class-awana-logger.php';
include_once 'includes/helpers.php';
include_once 'includes/product-mapping.php';
include_once 'includes/class-awana-crm-webhook.php';
include_once 'includes/class-awana-order-handler.php';
include_once 'includes/class-awana-rest-controller.php';
include_once 'includes/class-awana-org-sync.php';

// Initialize the plugin
Awana_REST_Controller::init();
Awana_Org_Sync::init();

// Initialize admin UI only in admin context
if ( is_admin() ) {
	include_once 'includes/class-awana-admin.php';
	Awana_Admin::init();
}

/**
 * Detect when Integrera updates pog_customer_number and sync to CRM
 * Integrera updates order meta directly, bypassing our API endpoint
 */
add_action( 'updated_postmeta', function( $meta_id, $object_id, $meta_key, $meta_value ) {
	$pog_sync_meta_keys = array(
		'pog_customer_number',
		'pog_invoice_number',
		'pog_kid_number',
		'pog_status',
	);

	// Only process POG sync meta updates
	if ( ! in_array( $meta_key, $pog_sync_meta_keys, true ) ) {
		return;
	}

	// Check if this is a WooCommerce order
	$order = wc_get_order( $object_id );
	if ( ! $order ) {
		return;
	}

	// Check if this order has the required CRM meta fields
	$invoice_id = $order->get_meta( 'crm_invoice_id', true );
	$member_id = $order->get_meta( 'crm_member_id', true );
	
	if ( empty( $invoice_id ) || empty( $member_id ) ) {
		return; // Not an Awana order, skip
	}

	// Map meta key to a per-field "last synced" marker to avoid duplicate webhooks
	$synced_meta_key_by_meta_key = array(
		'pog_customer_number' => '_pog_customer_synced_to_crm',
		'pog_invoice_number'  => '_pog_invoice_number_synced_to_crm',
		'pog_kid_number'      => '_pog_kid_number_synced_to_crm',
		'pog_status'          => '_pog_status_synced_to_crm',
	);

	$synced_meta_key = $synced_meta_key_by_meta_key[ $meta_key ] ?? '';
	if ( empty( $synced_meta_key ) ) {
		return;
	}

	$last_synced_value = $order->get_meta( $synced_meta_key, true );

	// If this value was already synced, skip (prevent duplicate webhooks)
	if ( (string) $last_synced_value === (string) $meta_value ) {
		return;
	}

	// If the value changed and is not empty, sync to CRM
	if ( ! empty( $meta_value ) ) {
		Awana_Logger::info(
			'POG meta updated by Integrera - syncing to CRM',
			array(
				'order_id'       => $object_id,
				'meta_key'       => $meta_key,
				'new_value'      => $meta_value,
				'previous_value' => $last_synced_value,
			)
		);

		// Send to correct webhook endpoint depending on field
		if ( $meta_key === 'pog_customer_number' ) {
			Awana_CRM_Webhook::notify_pog_customer_number_to_crm( $order, $meta_value );
		} else {
			Awana_CRM_Webhook::notify_invoice_status_to_crm( $order, 'Invoice status sync (meta update)' );
		}

		// Mark as synced to prevent duplicate webhooks
		$order->update_meta_data( $synced_meta_key, $meta_value );
		$order->save();
	}
}, 10, 4 );

/**
 * Detect when WooCommerce order status changes to "completed" and sync to CRM
 */
add_action( 'woocommerce_order_status_changed', function( $order_id, $old_status, $new_status, $order ) {
	// Only process if status changed to "completed"
	if ( $new_status !== 'completed' ) {
		return;
	}

	// Check if this is an Awana order
	$invoice_id = $order->get_meta( 'crm_invoice_id', true );
	$member_id  = $order->get_meta( 'crm_member_id', true );

	if ( empty( $invoice_id ) || empty( $member_id ) ) {
		return; // Not an Awana order, skip
	}

	Awana_Logger::info(
		'Order status changed to completed - syncing to CRM',
		array(
			'order_id'   => $order_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
			'invoice_id' => $invoice_id,
		)
	);

	// Trigger invoice status sync (will send status="paid" because order is completed)
	Awana_CRM_Webhook::notify_invoice_status_to_crm( $order, 'Order status changed to completed' );
}, 10, 4 );

/**
 * Detect pog_customer_number updates via order save hook (HPOS compatible backup)
 * This ensures compatibility with High-Performance Order Storage
 */
add_action( 'woocommerce_after_order_object_save', function( $order ) {
	// Only process if this is an Awana order
	$invoice_id = $order->get_meta( 'crm_invoice_id', true );
	$member_id = $order->get_meta( 'crm_member_id', true );
	
	if ( empty( $invoice_id ) || empty( $member_id ) ) {
		return; // Not an Awana order
	}

	$pog_fields = array(
		'pog_customer_number' => '_pog_customer_synced_to_crm',
		'pog_invoice_number'  => '_pog_invoice_number_synced_to_crm',
		'pog_kid_number'      => '_pog_kid_number_synced_to_crm',
		'pog_status'          => '_pog_status_synced_to_crm',
	);

	$changes_to_mark = array();
	$should_send_customer_number_webhook = false;
	$should_send_invoice_status_webhook  = false;

	foreach ( $pog_fields as $meta_key => $synced_meta_key ) {
		$current_value = $order->get_meta( $meta_key, true );
		$last_synced   = $order->get_meta( $synced_meta_key, true );

		if ( ! empty( $current_value ) && (string) $last_synced !== (string) $current_value ) {
			$changes_to_mark[ $synced_meta_key ] = $current_value;

			if ( $meta_key === 'pog_customer_number' ) {
				$should_send_customer_number_webhook = true;
			} else {
				$should_send_invoice_status_webhook = true;
			}
		}
	}

	// If anything changed, send relevant webhook(s) and mark all changed fields as synced
	if ( ! empty( $changes_to_mark ) ) {
		Awana_Logger::info(
			'POG meta changed on order save - syncing to CRM',
			array(
				'order_id' => $order->get_id(),
				'changed_synced_meta_keys' => array_keys( $changes_to_mark ),
			)
		);

		if ( $should_send_customer_number_webhook ) {
			$current_pog_customer_number = $order->get_meta( 'pog_customer_number', true );
			if ( ! empty( $current_pog_customer_number ) ) {
				Awana_CRM_Webhook::notify_pog_customer_number_to_crm( $order, $current_pog_customer_number );
			}
		}

		if ( $should_send_invoice_status_webhook ) {
			Awana_CRM_Webhook::notify_invoice_status_to_crm( $order, 'Invoice status sync (order save)' );
		}

		foreach ( $changes_to_mark as $synced_meta_key => $value ) {
			$order->update_meta_data( $synced_meta_key, $value );
		}

		$order->save();
	}
}, 10, 1 );

// Declare WooCommerce HPOS (High-Performance Order Storage) compatibility
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Copy pog_product_id from product meta to order line item meta
 * so Integrera gets it per purchased product.
 * This works for both checkout orders and API-created orders.
 */
add_action( 'woocommerce_checkout_create_order_line_item', function( $item, $cart_item_key, $values, $order ) {
	$product = $item->get_product();

	if ( ! $product ) {
		return;
	}

	// Read POG product id from product meta
	$pog_product_id = get_post_meta( $product->get_id(), 'pog_product_id', true );

	if ( ! empty( $pog_product_id ) ) {
		// Attach to line item meta with same key Integrera should read
		$item->add_meta_data( 'pog_product_id', $pog_product_id, true );
	}
}, 10, 4 );
