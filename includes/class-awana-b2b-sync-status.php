<?php
/**
 * B2B Sync Status admin page for Awana Commerce
 *
 * Shows sync status across WooCommerce, Firebase, and POG for B2B (organization) orders.
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * B2B Sync Status admin page class
 */
class Awana_B2B_Sync_Status {

	/**
	 * Orders per page
	 *
	 * @var int
	 */
	const PER_PAGE = 50;

	/**
	 * Initialize the admin page
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_awana_retry_checkout_sync', array( $instance, 'handle_retry_checkout_sync' ) );
	}

	/**
	 * Add admin menu under WooCommerce
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'CRM Order Sync', 'awana-commerce' ),
			__( 'CRM Sync', 'awana-commerce' ),
			'manage_woocommerce',
			'awana-b2b-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue inline scripts on this page only
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_awana-b2b-sync' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $this->get_inline_script() );
	}

	/**
	 * Get inline JavaScript for retry AJAX functionality
	 *
	 * @return string JavaScript code.
	 */
	private function get_inline_script() {
		ob_start();
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'awana_retry_checkout_sync' );
		?>
		jQuery(document).ready(function($) {
			$('.awana-b2b-retry-btn').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				var orderId = $button.data('order-id');
				var $row = $button.closest('tr');
				var originalText = $button.text();

				$button.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing...', 'awana-commerce' ) ); ?>');

				$.ajax({
					url: '<?php echo esc_url( $ajax_url ); ?>',
					type: 'POST',
					data: {
						action: 'awana_retry_checkout_sync',
						order_id: orderId,
						nonce: '<?php echo esc_js( $nonce ); ?>'
					},
					success: function(response) {
						if (response.success) {
							$row.find('.awana-b2b-status-cell').html('<span style="color: green; font-weight: bold;">' + response.data.message + '</span>');
							$button.text('<?php echo esc_js( __( 'Done', 'awana-commerce' ) ); ?>');
							setTimeout(function() { location.reload(); }, 2000);
						} else {
							$row.find('.awana-b2b-status-cell').append('<br><span style="color: red;">' + response.data.message + '</span>');
							$button.prop('disabled', false).text(originalText);
						}
					},
					error: function() {
						$row.find('.awana-b2b-status-cell').append('<br><span style="color: red;"><?php echo esc_js( __( 'Request failed', 'awana-commerce' ) ); ?></span>');
						$button.prop('disabled', false).text(originalText);
					}
				});
			});
		});
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle retry checkout sync AJAX request
	 */
	public function handle_retry_checkout_sync() {
		check_ajax_referer( 'awana_retry_checkout_sync', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-commerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'awana-commerce' ) ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'awana-commerce' ) ) );
		}

		$payment_type = $order->get_meta( '_awana_payment_type', true );
		if ( 'organization' !== $payment_type ) {
			wp_send_json_error( array( 'message' => __( 'Not a B2B order.', 'awana-commerce' ) ) );
		}

		Awana_Logger::info(
			'B2B sync retry triggered from admin',
			array( 'order_id' => $order_id )
		);

		// Clear the synced flag so notify_checkout_invoice_to_crm will run again
		$order->delete_meta_data( '_awana_checkout_invoice_synced' );
		$order->save();

		$result = Awana_CRM_Webhook::notify_checkout_invoice_to_crm( $order );

		if ( is_wp_error( $result ) ) {
			$error_message = $result->get_error_message();
			Awana_Logger::error(
				'B2B sync retry failed',
				array(
					'order_id' => $order_id,
					'error'    => $error_message,
				)
			);
			wp_send_json_error( array( 'message' => $error_message ) );
		} elseif ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Sync returned false - check order meta and webhook config.', 'awana-commerce' ) ) );
		} else {
			wp_send_json_success( array( 'message' => __( 'Synced successfully.', 'awana-commerce' ) ) );
		}
	}

	/**
	 * Render the admin page
	 */
	public function render_page() {
		$filter  = isset( $_GET['filter'] ) ? sanitize_text_field( $_GET['filter'] ) : 'all';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$summary = $this->get_summary();
		$orders  = $this->get_b2b_orders( $filter, $paged );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'CRM Order Sync', 'awana-commerce' ) ); ?></h1>
			<p style="color: #50575e; margin-top: -5px;">
				<?php echo esc_html( __( 'All orders with CRM integration — B2B checkout (Nets) and invoice-created orders.', 'awana-commerce' ) ); ?>
			</p>

			<!-- Summary bar -->
			<div style="display: flex; gap: 16px; margin: 20px 0;">
				<?php
				$cards = array(
					array(
						'label' => __( 'Total B2B Orders', 'awana-commerce' ),
						'value' => $summary['total'],
						'color' => '#2271b1',
					),
					array(
						'label' => __( 'Synced', 'awana-commerce' ),
						'value' => $summary['synced'],
						'color' => '#00a32a',
					),
					array(
						'label' => __( 'Pending', 'awana-commerce' ),
						'value' => $summary['pending'],
						'color' => '#dba617',
					),
					array(
						'label' => __( 'Failed', 'awana-commerce' ),
						'value' => $summary['failed'],
						'color' => '#d63638',
					),
				);
				foreach ( $cards as $card ) :
					?>
					<div style="flex: 1; background: #fff; border: 1px solid #ccd0d4; border-top: 4px solid <?php echo esc_attr( $card['color'] ); ?>; padding: 16px; text-align: center;">
						<div style="font-size: 28px; font-weight: 600; color: <?php echo esc_attr( $card['color'] ); ?>;">
							<?php echo esc_html( $card['value'] ); ?>
						</div>
						<div style="margin-top: 4px; color: #50575e;">
							<?php echo esc_html( $card['label'] ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Filters -->
			<ul class="subsubsub" style="margin-bottom: 10px;">
				<?php
				$filters = array(
					'all'          => __( 'All', 'awana-commerce' ),
					'missing_crm'  => __( 'Missing CRM ID', 'awana-commerce' ),
					'errors'       => __( 'With Errors', 'awana-commerce' ),
				);
				$filter_counts = array(
					'all'          => $summary['total'],
					'missing_crm'  => $summary['pending'],
					'errors'       => $summary['failed'],
				);
				$last_key = array_key_last( $filters );
				foreach ( $filters as $key => $label ) :
					$url   = add_query_arg( array( 'page' => 'awana-b2b-sync', 'filter' => $key ), admin_url( 'admin.php' ) );
					$class = ( $filter === $key ) ? 'current' : '';
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
							<?php echo esc_html( $label ); ?>
							<span class="count">(<?php echo esc_html( $filter_counts[ $key ] ); ?>)</span>
						</a>
						<?php if ( $key !== $last_key ) : ?>
							|
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<!-- Orders table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 70px;"><?php echo esc_html( __( 'Order #', 'awana-commerce' ) ); ?></th>
						<th style="width: 50px;"><?php echo esc_html( __( 'Type', 'awana-commerce' ) ); ?></th>
						<th style="width: 90px;"><?php echo esc_html( __( 'Date', 'awana-commerce' ) ); ?></th>
						<th><?php echo esc_html( __( 'Organization', 'awana-commerce' ) ); ?></th>
						<th style="width: 80px;"><?php echo esc_html( __( 'Total', 'awana-commerce' ) ); ?></th>
						<th style="width: 160px;"><?php echo esc_html( __( 'CRM Invoice ID', 'awana-commerce' ) ); ?></th>
						<th style="width: 80px;"><?php echo esc_html( __( 'CRM Sync', 'awana-commerce' ) ); ?></th>
						<th style="width: 120px;"><?php echo esc_html( __( 'POG', 'awana-commerce' ) ); ?></th>
						<th style="width: 40px;"><?php echo esc_html( __( '', 'awana-commerce' ) ); ?></th>
						<th style="width: 90px;"><?php echo esc_html( __( 'Actions', 'awana-commerce' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders['items'] ) ) : ?>
						<tr>
							<td colspan="11"><?php echo esc_html( __( 'No CRM orders found.', 'awana-commerce' ) ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $orders['items'] as $row ) : ?>
							<tr>
								<!-- Order # -->
								<td>
									<a href="<?php echo esc_url( $row['edit_url'] ); ?>">
										#<?php echo esc_html( $row['order_number'] ); ?>
									</a>
								</td>

								<!-- Type -->
								<td>
									<?php if ( 'B2B' === $row['type_label'] ) : ?>
										<span style="background: #e3f2fd; color: #1565c0; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;">B2B</span>
									<?php else : ?>
										<span style="background: #fff3e0; color: #e65100; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600;">INV</span>
									<?php endif; ?>
								</td>

								<!-- Date -->
								<td><?php echo esc_html( $row['date'] ); ?></td>

								<!-- Organization -->
								<td><?php echo esc_html( $row['organization'] ); ?></td>

								<!-- Total -->
								<td><?php echo wp_kses_post( $row['total'] ); ?></td>

								<!-- CRM Invoice ID -->
								<td>
									<?php if ( ! empty( $row['crm_invoice_id'] ) ) : ?>
										<code style="color: #00a32a; font-size: 11px;">
											<?php echo esc_html( substr( $row['crm_invoice_id'], 0, 12 ) ); ?>&hellip;
										</code>
										<?php if ( ! empty( $row['crm_source'] ) ) : ?>
											<br><span style="color: #a7aaad; font-size: 11px;"><?php echo esc_html( $row['crm_source'] ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<span style="color: #d63638; font-weight: 500;">
											<?php echo esc_html( __( 'Missing', 'awana-commerce' ) ); ?>
										</span>
									<?php endif; ?>
								</td>

								<!-- CRM Sync Status -->
								<td>
									<?php
									$sync_val   = $row['crm_sync_woo'];
									$sync_color = '#a7aaad';
									$sync_label = '—';
									if ( 'success' === $sync_val ) {
										$sync_color = '#00a32a';
										$sync_label = 'OK';
									} elseif ( 'failed' === $sync_val ) {
										$sync_color = '#d63638';
										$sync_label = 'Failed';
									} elseif ( 'pending' === $sync_val ) {
										$sync_color = '#dba617';
										$sync_label = 'Pending';
									}
									?>
									<span style="color: <?php echo esc_attr( $sync_color ); ?>; font-weight: 500;">
										<?php echo esc_html( $sync_label ); ?>
									</span>
								</td>

								<!-- POG -->
								<td>
									<?php if ( ! empty( $row['pog_invoice_number'] ) ) : ?>
										<span style="font-size: 11px;">#<?php echo esc_html( $row['pog_invoice_number'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $row['pog_kid'] ) ) : ?>
										<br><span style="color: #50575e; font-size: 11px;">KID: <?php echo esc_html( $row['pog_kid'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $row['pog_status'] ) ) : ?>
										<br><span style="color: #50575e; font-size: 11px;"><?php echo esc_html( $row['pog_status'] ); ?></span>
									<?php elseif ( empty( $row['pog_invoice_number'] ) ) : ?>
										<span style="color: #a7aaad;">&mdash;</span>
									<?php endif; ?>
								</td>

								<!-- Sync status dot -->
								<td class="awana-b2b-status-cell" style="text-align: center;">
									<?php
									$dot_color = '#dba617';
									$dot_title = __( 'Pending', 'awana-commerce' );
									if ( ! empty( $row['has_error'] ) ) {
										$dot_color = '#d63638';
										$dot_title = $row['last_error'];
									} elseif ( ! empty( $row['crm_invoice_id'] ) ) {
										$dot_color = '#00a32a';
										$dot_title = __( 'Synced', 'awana-commerce' );
									}
									?>
									<span
										style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo esc_attr( $dot_color ); ?>;"
										title="<?php echo esc_attr( $dot_title ); ?>"
									></span>
								</td>

								<!-- Actions -->
								<td>
									<?php if ( 'B2B' === $row['type_label'] ) : ?>
										<button
											type="button"
											class="button button-small awana-b2b-retry-btn"
											data-order-id="<?php echo esc_attr( $row['order_id'] ); ?>"
										>
											<?php echo esc_html( __( 'Retry', 'awana-commerce' ) ); ?>
										</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $orders['total_pages'] > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							echo esc_html( sprintf(
								/* translators: %s: number of items */
								_n( '%s item', '%s items', $orders['total'], 'awana-commerce' ),
								number_format_i18n( $orders['total'] )
							) );
							?>
						</span>
						<span class="pagination-links">
							<?php
							$base_url = add_query_arg(
								array( 'page' => 'awana-b2b-sync', 'filter' => $filter ),
								admin_url( 'admin.php' )
							);

							// First page
							if ( $paged > 1 ) :
								?>
								<a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">&laquo;</a>
								<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ); ?>">&lsaquo;</a>
							<?php else : ?>
								<span class="tablenav-pages-navspan button disabled">&laquo;</span>
								<span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
							<?php endif; ?>

							<span class="paging-input">
								<?php echo esc_html( $paged ); ?>
								<?php echo esc_html( __( 'of', 'awana-commerce' ) ); ?>
								<span class="total-pages"><?php echo esc_html( $orders['total_pages'] ); ?></span>
							</span>

							<?php if ( $paged < $orders['total_pages'] ) : ?>
								<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ); ?>">&rsaquo;</a>
								<a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $orders['total_pages'], $base_url ) ); ?>">&raquo;</a>
							<?php else : ?>
								<span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
								<span class="tablenav-pages-navspan button disabled">&raquo;</span>
							<?php endif; ?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get all CRM-related order IDs (B2B orders + any order with crm_invoice_id)
	 *
	 * @return array Order IDs.
	 */
	private function get_crm_order_ids() {
		// B2B orders
		$b2b_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'meta_key'   => '_awana_payment_type',
				'meta_value' => 'organization',
				'return'     => 'ids',
			)
		);

		// Orders with crm_invoice_id (from invoice/membership pipeline)
		$crm_ids = wc_get_orders(
			array(
				'limit'       => -1,
				'meta_key'    => 'crm_invoice_id',
				'meta_compare' => 'EXISTS',
				'return'      => 'ids',
			)
		);

		// Merge and deduplicate
		return array_unique( array_merge( $b2b_ids, $crm_ids ) );
	}

	/**
	 * Get summary counts for CRM orders
	 *
	 * @return array Summary with total, synced, pending, failed counts.
	 */
	private function get_summary() {
		$order_ids = $this->get_crm_order_ids();

		$total   = count( $order_ids );
		$synced  = 0;
		$pending = 0;
		$failed  = 0;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$crm_invoice_id = $order->get_meta( 'crm_invoice_id', true );
			$last_error     = $order->get_meta( '_awana_sync_last_error', true );

			if ( ! empty( $last_error ) ) {
				$failed++;
			} elseif ( ! empty( $crm_invoice_id ) ) {
				$synced++;
			} else {
				$pending++;
			}
		}

		return array(
			'total'   => $total,
			'synced'  => $synced,
			'pending' => $pending,
			'failed'  => $failed,
		);
	}

	/**
	 * Get B2B orders for the table, applying filter and pagination
	 *
	 * @param string $filter Filter type: all, missing_crm, errors.
	 * @param int    $paged  Current page number.
	 * @return array Array with 'items', 'total', 'total_pages'.
	 */
	private function get_b2b_orders( $filter, $paged ) {
		$order_ids = $this->get_crm_order_ids();

		// Sort by ID descending (newest first)
		rsort( $order_ids );

		// Build filtered list
		$filtered = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$crm_invoice_id = $order->get_meta( 'crm_invoice_id', true );
			$last_error     = $order->get_meta( '_awana_sync_last_error', true );

			// Apply filter
			if ( 'missing_crm' === $filter && ! empty( $crm_invoice_id ) ) {
				continue;
			}
			if ( 'errors' === $filter && empty( $last_error ) ) {
				continue;
			}

			$pog_status         = $order->get_meta( 'pog_status', true );
			$pog_invoice_number = $order->get_meta( 'pog_invoice_number', true );
			$pog_kid            = $order->get_meta( 'pog_kid_number', true );
			$organization       = $order->get_meta( '_awana_selected_org_title', true );
			$payment_type       = $order->get_meta( '_awana_payment_type', true );
			$crm_sync_woo       = $order->get_meta( 'crm_sync_woo', true );
			$crm_source         = $order->get_meta( 'crm_source', true );
			$order_date         = $order->get_date_created();

			// Determine order type label
			$type_label = '';
			if ( 'organization' === $payment_type ) {
				$type_label = 'B2B';
			} elseif ( ! empty( $crm_invoice_id ) ) {
				$type_label = 'Invoice';
			}

			$filtered[] = array(
				'order_id'           => $order_id,
				'order_number'       => $order->get_order_number(),
				'edit_url'           => $order->get_edit_order_url(),
				'date'               => $order_date ? $order_date->date_i18n( get_option( 'date_format' ) ) : '',
				'organization'       => $organization ? $organization : __( '—', 'awana-commerce' ),
				'total'              => $order->get_formatted_order_total(),
				'payment_method'     => $order->get_payment_method_title(),
				'crm_invoice_id'     => $crm_invoice_id,
				'crm_sync_woo'       => $crm_sync_woo,
				'crm_source'         => $crm_source,
				'pog_status'         => $pog_status,
				'pog_invoice_number' => $pog_invoice_number,
				'pog_kid'            => $pog_kid,
				'type_label'         => $type_label,
				'has_error'          => ! empty( $last_error ),
				'last_error'         => $last_error ? $last_error : '',
			);
		}

		$total       = count( $filtered );
		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;
		$items       = array_slice( $filtered, $offset, self::PER_PAGE );

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}
}
