<?php
/**
 * CRM Webhook Handler class for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Webhook Handler class
 */
class Awana_CRM_Webhook {

	/**
	 * Send webhook to CRM when invoice is paid
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param array    $payment_data Payment data.
	 */
	public static function notify_invoice_paid( $order, $payment_data = array() ) {
		$invoice_id = $order->get_meta( 'crm_invoice_id' );
		$member_id  = $order->get_meta( 'crm_member_id' );

		if ( empty( $invoice_id ) || empty( $member_id ) ) {
			Awana_Logger::warning(
				'Cannot send payment webhook - missing invoice_id or member_id',
				array(
					'order_id' => $order->get_id(),
					'invoice_id' => $invoice_id,
					'member_id' => $member_id,
				)
			);
			return false;
		}

		$webhook_url = self::get_crm_webhook_url();
		if ( empty( $webhook_url ) ) {
			Awana_Logger::warning( 'CRM webhook URL not configured', array( 'order_id' => $order->get_id() ) );
			return false;
		}

		$payload = array(
			'invoiceId'  => $invoice_id,
			'memberId'   => $member_id,
			'status'     => 'paid',
			'amountPaid' => ! empty( $payment_data['amountPaid'] ) ? $payment_data['amountPaid'] : $order->get_total(),
			'paidAt'     => $order->get_meta( '_paid_at' ) ?: current_time( 'mysql' ),
			'event'      => 'invoice_paid',
			'timestamp'  => current_time( 'mysql' ),
		);

		// Set sync status to pending before webhook attempt
		self::update_sync_status( $order, false, '', true );

		$result = self::send_webhook( $webhook_url, $payload, 'Invoice paid' );

		// Update sync status based on result
		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) ) {
				$error_message .= ' (HTTP ' . $result->get_error_data()['status'] . ')';
			}
			self::update_sync_status( $order, false, $error_message );
		} else {
			self::update_sync_status( $order, true );
		}

		return $result;
	}

	/**
	 * Send webhook to invoiceStatusWebhook when Integrera updates POG status-related fields.
	 * Sends payload containing invoiceId and any of: kid, pogInvoiceNumber/invoiceNumber and mapped status.
	 * Status mapping prioritizes WooCommerce order status over POG status.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param string   $event_name Event name for logging.
	 * @param bool     $skip_status_update Whether to skip updating sync status (for use in batch syncs).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function notify_invoice_status_to_crm( $order, $event_name = 'Invoice status sync', $skip_status_update = false ) {
		$invoice_id = $order->get_meta( 'crm_invoice_id' );
		$member_id  = $order->get_meta( 'crm_member_id' );

		if ( empty( $invoice_id ) || empty( $member_id ) ) {
			Awana_Logger::warning(
				'Cannot send invoice status webhook - missing invoice_id or member_id',
				array(
					'order_id'   => $order->get_id(),
					'invoice_id' => $invoice_id,
					'member_id'  => $member_id,
				)
			);
			return false;
		}

		// Get webhook URL from wp-config.php constant (required)
		if ( ! defined( 'AWANA_INVOICE_STATUS_WEBHOOK_URL' ) || empty( AWANA_INVOICE_STATUS_WEBHOOK_URL ) ) {
			Awana_Logger::error(
				'Invoice status webhook URL not configured - AWANA_INVOICE_STATUS_WEBHOOK_URL must be defined in wp-config.php',
				array( 'order_id' => $order->get_id() )
			);
			return false;
		}
		$webhook_url = AWANA_INVOICE_STATUS_WEBHOOK_URL;

		// API key is optional for this endpoint; if configured, we send it as x-api-key
		$api_key = ( defined( 'AWANA_INVOICE_STATUS_WEBHOOK_API_KEY' ) && ! empty( AWANA_INVOICE_STATUS_WEBHOOK_API_KEY ) )
			? AWANA_INVOICE_STATUS_WEBHOOK_API_KEY
			: '';

		$pog_invoice_number  = $order->get_meta( 'pog_invoice_number', true );
		$pog_kid_number      = $order->get_meta( 'pog_kid_number', true );
		$pog_status          = $order->get_meta( 'pog_status', true );
		$order_status        = $order->get_status();

		$payload = array(
			'invoiceId' => $invoice_id,
		);

		if ( ! empty( $pog_kid_number ) ) {
			$payload['kid'] = (string) $pog_kid_number;
		}

		if ( ! empty( $pog_invoice_number ) ) {
			// invoiceStatusWebhook supports these field names; we send both for compatibility.
			$payload['pogInvoiceNumber'] = (string) $pog_invoice_number;
			$payload['invoiceNumber']    = (string) $pog_invoice_number;
		}

		// Prioritize WooCommerce order status over POG status
		if ( $order_status === 'completed' ) {
			// If order is completed, always send status="paid" regardless of POG status
			$payload['status'] = 'paid';
		} elseif ( ! empty( $pog_status ) ) {
			// Otherwise, use POG status mapping
			$mapped_status = self::map_pog_status_to_webhook_status( $pog_status );
			if ( $mapped_status !== null ) {
				$payload['status'] = $mapped_status;
			} else {
				Awana_Logger::warning(
					'Unknown pog_status value - not mapping to status for webhook payload',
					array(
						'order_id'    => $order->get_id(),
						'pog_status'  => $pog_status,
						'invoice_id'  => $invoice_id,
						'member_id'   => $member_id,
						'webhook_url' => $webhook_url,
					)
				);
			}
		}

		// Set sync status to pending before webhook attempt (unless skipping status updates)
		if ( ! $skip_status_update ) {
			self::update_sync_status( $order, false, '', true );
		}

		$result = self::send_x_api_key_webhook( $webhook_url, $payload, $api_key, $event_name );

		// Update sync status based on result (unless skipping status updates)
		if ( ! $skip_status_update ) {
			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) ) {
					$error_message .= ' (HTTP ' . $result->get_error_data()['status'] . ')';
				}
				self::update_sync_status( $order, false, $error_message );
			} else {
				self::update_sync_status( $order, true );
			}
		}

		return $result;
	}

	/**
	 * Map pog_status values to webhook "status" values expected by receiver.
	 *
	 * @param string $pog_status POG status value stored on the order.
	 * @return string|null Mapped status, or null if unknown.
	 */
	private static function map_pog_status_to_webhook_status( $pog_status ) {
		$normalized = strtolower( trim( (string) $pog_status ) );
		switch ( $normalized ) {
			case 'order':
				return 'transferred';
			case 'invoice':
				return 'unpaid';
			default:
				return null;
		}
	}

	/**
	 * Send webhook to CRM when Integrera updates POG customer number
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param mixed    $pog_customer_number POG customer number.
	 * @param bool     $skip_status_update Whether to skip updating sync status (for use in batch syncs).
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function notify_pog_customer_number_to_crm( $order, $pog_customer_number, $skip_status_update = false ) {
		$invoice_id = $order->get_meta( 'crm_invoice_id' );
		$member_id  = $order->get_meta( 'crm_member_id' );

		if ( empty( $invoice_id ) || empty( $member_id ) ) {
			Awana_Logger::warning(
				'Cannot send POG customer number webhook - missing invoice_id or member_id',
				array(
					'order_id'   => $order->get_id(),
					'invoice_id' => $invoice_id,
					'member_id'  => $member_id,
				)
			);
			return false;
		}

		// Get webhook URL from wp-config.php constant (required)
		if ( ! defined( 'AWANA_POG_CUSTOMER_WEBHOOK_URL' ) || empty( AWANA_POG_CUSTOMER_WEBHOOK_URL ) ) {
			Awana_Logger::error(
				'POG customer webhook URL not configured - AWANA_POG_CUSTOMER_WEBHOOK_URL must be defined in wp-config.php',
				array( 'order_id' => $order->get_id() )
			);
			return false;
		}
		$webhook_url = AWANA_POG_CUSTOMER_WEBHOOK_URL;

		// Get API key from wp-config.php constant (required)
		if ( ! defined( 'AWANA_POG_CUSTOMER_WEBHOOK_API_KEY' ) || empty( AWANA_POG_CUSTOMER_WEBHOOK_API_KEY ) ) {
			Awana_Logger::error(
				'POG customer webhook API key not configured - AWANA_POG_CUSTOMER_WEBHOOK_API_KEY must be defined in wp-config.php',
				array( 'order_id' => $order->get_id() )
			);
			return false;
		}
		$api_key = AWANA_POG_CUSTOMER_WEBHOOK_API_KEY;

		$payload = array(
			'invoiceId'           => $invoice_id,
			'pog_customer_number' => (string) $pog_customer_number,
		);

		// Set sync status to pending before webhook attempt (unless skipping status updates)
		if ( ! $skip_status_update ) {
			self::update_sync_status( $order, false, '', true );
		}

		$result = self::send_pog_customer_webhook( $webhook_url, $payload, $api_key, 'POG customer number sync' );

		// Update sync status based on result (unless skipping status updates)
		if ( ! $skip_status_update ) {
			if ( is_wp_error( $result ) ) {
				$error_message = $result->get_error_message();
				if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) ) {
					$error_message .= ' (HTTP ' . $result->get_error_data()['status'] . ')';
				}
				self::update_sync_status( $order, false, $error_message );
			} else {
				self::update_sync_status( $order, true );
			}
		}

		return $result;
	}

	/**
	 * Get CRM webhook URL from configuration
	 *
	 * @return string|false Webhook URL or false if not configured.
	 */
	private static function get_crm_webhook_url() {
		// Check if URL is defined in wp-config.php
		if ( defined( 'AWANA_CRM_WEBHOOK_URL' ) ) {
			return AWANA_CRM_WEBHOOK_URL;
		}

		// Check if URL is stored in options (for admin configuration)
		$url = get_option( 'awana_crm_webhook_url', '' );
		if ( ! empty( $url ) ) {
			return $url;
		}

		return false;
	}

	/**
	 * Send webhook to CRM
	 *
	 * @param string $url Webhook URL.
	 * @param array  $payload Payload data.
	 * @param string $event_name Event name for logging.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function send_webhook( $url, $payload, $event_name ) {
		$api_key = defined( 'AWANA_CRM_WEBHOOK_API_KEY' ) ? AWANA_CRM_WEBHOOK_API_KEY : '';

		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		);

		// Add API key to headers if configured
		if ( ! empty( $api_key ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Awana_Logger::error(
				sprintf( 'Failed to send %s webhook to CRM', $event_name ),
				array(
					'error'   => $response->get_error_message(),
					'url'     => $url,
					'payload' => $payload,
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			Awana_Logger::info(
				sprintf( 'Successfully sent %s webhook to CRM', $event_name ),
				array(
					'url'         => $url,
					'status_code' => $status_code,
					'payload'     => $payload,
				)
			);
			return true;
		} else {
			Awana_Logger::warning(
				sprintf( 'CRM webhook returned non-2xx status for %s', $event_name ),
				array(
					'url'         => $url,
					'status_code' => $status_code,
					'response'    => wp_remote_retrieve_body( $response ),
					'payload'     => $payload,
				)
			);
			return new WP_Error( 'webhook_failed', 'CRM webhook returned error status', array( 'status' => $status_code ) );
		}
	}

	/**
	 * Send webhook to POG customer number webhook endpoint
	 * Uses x-api-key header format instead of Authorization Bearer
	 *
	 * @param string $url Webhook URL.
	 * @param array  $payload Payload data.
	 * @param string $api_key API key for authentication.
	 * @param string $event_name Event name for logging.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function send_pog_customer_webhook( $url, $payload, $api_key, $event_name ) {
		return self::send_x_api_key_webhook( $url, $payload, $api_key, $event_name );
	}

	/**
	 * Update sync status for an order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @param bool     $success Whether the sync was successful.
	 * @param string   $error_message Error message if sync failed.
	 * @param bool     $is_pending Whether this is setting status to pending before attempt.
	 * @return void
	 */
	private static function update_sync_status( $order, $success, $error_message = '', $is_pending = false ) {
		$current_time = time();

		if ( $is_pending ) {
			$order->update_meta_data( 'crm_sync_woo', 'pending' );
			$order->update_meta_data( '_awana_sync_last_attempt', $current_time );
		} elseif ( $success ) {
			$order->update_meta_data( 'crm_sync_woo', 'success' );
			$order->update_meta_data( '_awana_sync_last_attempt', $current_time );
			$order->update_meta_data( '_awana_sync_last_success', $current_time );
			$order->delete_meta_data( '_awana_sync_last_error' );
			$order->update_meta_data( '_awana_sync_error_count', 0 );
		} else {
			$order->update_meta_data( 'crm_sync_woo', 'failed' );
			$order->update_meta_data( '_awana_sync_last_attempt', $current_time );
			$order->update_meta_data( '_awana_sync_last_error', $error_message );
			$current_count = (int) $order->get_meta( '_awana_sync_error_count', true );
			$order->update_meta_data( '_awana_sync_error_count', $current_count + 1 );
		}

		$order->save();
	}

	/**
	 * Sync all order metadata to CRM (manual sync).
	 *
	 * @param int  $order_id WooCommerce order ID.
	 * @param bool $force Whether to force sync even if already synced.
	 * @return array Array with 'success' boolean and 'message' string.
	 */
	public static function sync_all_order_metadata_to_crm( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Order #%d not found.', $order_id ),
			);
		}

		$invoice_id = $order->get_meta( 'crm_invoice_id', true );
		$member_id  = $order->get_meta( 'crm_member_id', true );

		if ( empty( $invoice_id ) || empty( $member_id ) ) {
			return array(
				'success' => false,
				'message' => sprintf( 'Order #%d is not an Awana order (missing invoice_id or member_id).', $order_id ),
			);
		}

		$results = array();
		$has_errors = false;
		$error_messages = array();

		// Set sync status to pending before starting batch sync
		self::update_sync_status( $order, false, '', true );

		// Sync POG customer number if exists (skip individual status updates)
		$pog_customer_number = $order->get_meta( 'pog_customer_number', true );
		if ( ! empty( $pog_customer_number ) ) {
			$result = self::notify_pog_customer_number_to_crm( $order, $pog_customer_number, true );
			if ( is_wp_error( $result ) ) {
				$error_msg = $result->get_error_message();
				if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) ) {
					$error_msg .= ' (HTTP ' . $result->get_error_data()['status'] . ')';
				}
				$results[] = 'POG customer number sync failed: ' . $error_msg;
				$error_messages[] = $error_msg;
				$has_errors = true;
			} else {
				$results[] = 'POG customer number synced successfully';
			}
		}

		// Sync invoice status (includes POG status, KID, invoice number, and order status) (skip individual status updates)
		$result = self::notify_invoice_status_to_crm( $order, 'Manual sync', true );
		if ( is_wp_error( $result ) ) {
			$error_msg = $result->get_error_message();
			if ( $result->get_error_data() && isset( $result->get_error_data()['status'] ) ) {
				$error_msg .= ' (HTTP ' . $result->get_error_data()['status'] . ')';
			}
			$results[] = 'Invoice status sync failed: ' . $error_msg;
			$error_messages[] = $error_msg;
			$has_errors = true;
		} else {
			$results[] = 'Invoice status synced successfully';
		}

		// Update sync status based on combined results
		if ( $has_errors ) {
			$combined_error_message = implode( '; ', $error_messages );
			self::update_sync_status( $order, false, $combined_error_message );
		} else {
			self::update_sync_status( $order, true );
		}

		return array(
			'success' => ! $has_errors,
			'message' => implode( '; ', $results ),
		);
	}

	/**
	 * Send webhook using x-api-key header format.
	 *
	 * @param string $url Webhook URL.
	 * @param array  $payload Payload data.
	 * @param string $api_key API key for authentication (optional).
	 * @param string $event_name Event name for logging.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private static function send_x_api_key_webhook( $url, $payload, $api_key, $event_name ) {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( ! empty( $api_key ) ) {
			$headers['x-api-key'] = $api_key;
		}

		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
		);

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Awana_Logger::error(
				sprintf( 'Failed to send %s webhook', $event_name ),
				array(
					'error'   => $response->get_error_message(),
					'url'     => $url,
					'payload' => $payload,
				)
			);
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			Awana_Logger::info(
				sprintf( 'Successfully sent %s webhook', $event_name ),
				array(
					'url'         => $url,
					'status_code' => $status_code,
					'payload'     => $payload,
				)
			);
			return true;
		} else {
			Awana_Logger::warning(
				sprintf( 'Webhook returned non-2xx status for %s', $event_name ),
				array(
					'url'         => $url,
					'status_code' => $status_code,
					'response'    => wp_remote_retrieve_body( $response ),
					'payload'     => $payload,
				)
			);
			return new WP_Error( 'webhook_failed', 'Webhook returned error status', array( 'status' => $status_code ) );
		}
	}
}

