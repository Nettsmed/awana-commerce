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

	const PER_PAGE = 50;

	/** @var array|null Cached CRM order IDs for current request. */
	private $crm_order_ids_cache = null;

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
							var $msg = $('<span>').css({color: 'green', fontWeight: 'bold'}).text(response.data.message);
							$row.find('.awana-b2b-status-cell').empty().append($msg);
							$button.text('<?php echo esc_js( __( 'Done', 'awana-commerce' ) ); ?>');
							setTimeout(function() { location.reload(); }, 2000);
						} else {
							var $err = $('<span>').css({color: 'red'}).text(response.data.message || 'Unknown error');
							$row.find('.awana-b2b-status-cell').append($('<br>')).append($err);
							$button.prop('disabled', false).text(originalText);
						}
					},
					error: function() {
						var $err = $('<span>').css({color: 'red'}).text('<?php echo esc_js( __( 'Request failed', 'awana-commerce' ) ); ?>');
						$row.find('.awana-b2b-status-cell').append($('<br>')).append($err);
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

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Render the admin page (orchestrator)
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
			<?php
			$this->render_summary_cards( $summary );
			$this->render_filters( $filter, $summary );
			$this->render_orders_table( $orders );
			$this->render_pagination( $orders, $filter, $paged );
			?>
		</div>
		<?php
	}

	/**
	 * Render summary stat cards
	 *
	 * @param array $summary Summary counts.
	 */
	private function render_summary_cards( $summary ) {
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
		?>
		<div style="display: flex; gap: 16px; margin: 20px 0;">
			<?php foreach ( $cards as $card ) : ?>
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
		<?php
	}

	/**
	 * Render filter links (All / Missing CRM ID / With Errors)
	 *
	 * @param string $active_filter Currently active filter key.
	 * @param array  $summary       Summary counts.
	 */
	private function render_filters( $active_filter, $summary ) {
		$filters = array(
			'all'         => __( 'All', 'awana-commerce' ),
			'missing_crm' => __( 'Missing CRM ID', 'awana-commerce' ),
			'errors'      => __( 'With Errors', 'awana-commerce' ),
		);
		$filter_counts = array(
			'all'         => $summary['total'],
			'missing_crm' => $summary['pending'],
			'errors'      => $summary['failed'],
		);
		$last_key = array_key_last( $filters );
		?>
		<ul class="subsubsub" style="margin-bottom: 10px;">
			<?php foreach ( $filters as $key => $label ) :
				$url   = add_query_arg( array( 'page' => 'awana-b2b-sync', 'filter' => $key ), admin_url( 'admin.php' ) );
				$class = ( $active_filter === $key ) ? 'current' : '';
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
		<?php
	}

	/**
	 * Render the orders table
	 *
	 * @param array $orders Orders data with 'items', 'total', 'total_pages'.
	 */
	private function render_orders_table( $orders ) {
		?>
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
						<?php $this->render_order_row( $row ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render a single order table row
	 *
	 * @param array $row Order row data.
	 */
	private function render_order_row( $row ) {
		$sync = $this->get_sync_display( $row['crm_sync_woo'] );
		$dot  = $this->get_dot_display( $row );
		$badge_style = 'B2B' === $row['type_label']
			? 'background:#e3f2fd;color:#1565c0'
			: 'background:#fff3e0;color:#e65100';
		$badge_text = 'B2B' === $row['type_label'] ? 'B2B' : 'INV';
		?>
		<tr>
			<td><a href="<?php echo esc_url( $row['edit_url'] ); ?>">#<?php echo esc_html( $row['order_number'] ); ?></a></td>
			<td><span style="<?php echo esc_attr( $badge_style ); ?>;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;"><?php echo esc_html( $badge_text ); ?></span></td>
			<td><?php echo esc_html( $row['date'] ); ?></td>
			<td><?php echo esc_html( $row['organization'] ); ?></td>
			<td><?php echo wp_kses_post( $row['total'] ); ?></td>
			<td><?php $this->render_crm_invoice_cell( $row ); ?></td>
			<td><span style="color:<?php echo esc_attr( $sync['color'] ); ?>;font-weight:500;"><?php echo esc_html( $sync['label'] ); ?></span></td>
			<td><?php $this->render_pog_cell( $row ); ?></td>
			<td class="awana-b2b-status-cell" style="text-align:center;">
				<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $dot['color'] ); ?>;" title="<?php echo esc_attr( $dot['title'] ); ?>"></span>
			</td>
			<td>
				<?php if ( 'B2B' === $row['type_label'] ) : ?>
					<button type="button" class="button button-small awana-b2b-retry-btn" data-order-id="<?php echo esc_attr( $row['order_id'] ); ?>"><?php echo esc_html( __( 'Retry', 'awana-commerce' ) ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the CRM Invoice ID table cell content
	 *
	 * @param array $row Order row data.
	 */
	private function render_crm_invoice_cell( $row ) {
		if ( ! empty( $row['crm_invoice_id'] ) ) :
			?>
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
		<?php
		endif;
	}

	/**
	 * Render the POG table cell content
	 *
	 * @param array $row Order row data.
	 */
	private function render_pog_cell( $row ) {
		if ( ! empty( $row['pog_invoice_number'] ) ) :
			?>
			<span style="font-size: 11px;">#<?php echo esc_html( $row['pog_invoice_number'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $row['pog_kid'] ) ) : ?>
			<br><span style="color: #50575e; font-size: 11px;">KID: <?php echo esc_html( $row['pog_kid'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $row['pog_status'] ) ) : ?>
			<br><span style="color: #50575e; font-size: 11px;"><?php echo esc_html( $row['pog_status'] ); ?></span>
		<?php elseif ( empty( $row['pog_invoice_number'] ) ) : ?>
			<span style="color: #a7aaad;">&mdash;</span>
		<?php
		endif;
	}

	/**
	 * Get CRM sync status display values
	 *
	 * @param string $sync_val Raw sync status value.
	 * @return array{color: string, label: string}
	 */
	private function get_sync_display( $sync_val ) {
		$map = array(
			'success' => array( 'color' => '#00a32a', 'label' => 'OK' ),
			'failed'  => array( 'color' => '#d63638', 'label' => 'Failed' ),
			'pending' => array( 'color' => '#dba617', 'label' => 'Pending' ),
		);

		return isset( $map[ $sync_val ] )
			? $map[ $sync_val ]
			: array( 'color' => '#a7aaad', 'label' => "\xE2\x80\x94" );
	}

	/**
	 * Get status dot display values
	 *
	 * @param array $row Order row data.
	 * @return array{color: string, title: string}
	 */
	private function get_dot_display( $row ) {
		if ( ! empty( $row['has_error'] ) ) {
			return array( 'color' => '#d63638', 'title' => $row['last_error'] );
		}
		if ( ! empty( $row['crm_invoice_id'] ) ) {
			return array( 'color' => '#00a32a', 'title' => __( 'Synced', 'awana-commerce' ) );
		}

		return array( 'color' => '#dba617', 'title' => __( 'Pending', 'awana-commerce' ) );
	}

	/**
	 * Render pagination controls
	 *
	 * @param array  $orders Orders data with 'total' and 'total_pages'.
	 * @param string $filter Active filter key.
	 * @param int    $paged  Current page number.
	 */
	private function render_pagination( $orders, $filter, $paged ) {
		if ( $orders['total_pages'] <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array( 'page' => 'awana-b2b-sync', 'filter' => $filter ),
			admin_url( 'admin.php' )
		);
		?>
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
					<?php if ( $paged > 1 ) : ?>
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
		<?php
	}

	// -------------------------------------------------------------------------
	// Data
	// -------------------------------------------------------------------------

	/**
	 * Get all CRM-related order IDs (B2B orders + any order with crm_invoice_id)
	 *
	 * Results are cached in an instance property so get_summary() and
	 * get_b2b_orders() don't query twice in the same request.
	 *
	 * @return array Order IDs.
	 */
	private function get_crm_order_ids() {
		if ( null !== $this->crm_order_ids_cache ) {
			return $this->crm_order_ids_cache;
		}

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
				'limit'        => -1,
				'meta_key'     => 'crm_invoice_id',
				'meta_compare' => 'EXISTS',
				'return'       => 'ids',
			)
		);

		// Merge and deduplicate
		$this->crm_order_ids_cache = array_unique( array_merge( $b2b_ids, $crm_ids ) );

		return $this->crm_order_ids_cache;
	}

	/**
	 * Get summary counts for CRM orders
	 *
	 * @return array Summary with total, synced, pending, failed counts.
	 */
	private function get_summary() {
		$order_ids = $this->get_crm_order_ids();

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
			'total'   => count( $order_ids ),
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
			$row = $this->build_order_row( $order_id, $filter );
			if ( null !== $row ) {
				$filtered[] = $row;
			}
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

	/**
	 * Build a single order row array from an order ID
	 *
	 * Returns null if the order doesn't exist or is excluded by the filter.
	 *
	 * @param int    $order_id WooCommerce order ID.
	 * @param string $filter   Active filter key.
	 * @return array|null Order row data or null if filtered out.
	 */
	private function build_order_row( $order_id, $filter ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$crm_invoice_id = $order->get_meta( 'crm_invoice_id', true );
		$last_error     = $order->get_meta( '_awana_sync_last_error', true );

		// Apply filter
		if ( 'missing_crm' === $filter && ! empty( $crm_invoice_id ) ) {
			return null;
		}
		if ( 'errors' === $filter && empty( $last_error ) ) {
			return null;
		}

		$payment_type = $order->get_meta( '_awana_payment_type', true );
		$order_date   = $order->get_date_created();

		// Determine order type label
		$type_label = '';
		if ( 'organization' === $payment_type ) {
			$type_label = 'B2B';
		} elseif ( ! empty( $crm_invoice_id ) ) {
			$type_label = 'Invoice';
		}

		$organization = $order->get_meta( '_awana_selected_org_title', true );

		return array(
			'order_id'           => $order_id,
			'order_number'       => $order->get_order_number(),
			'edit_url'           => $order->get_edit_order_url(),
			'date'               => $order_date ? $order_date->date_i18n( get_option( 'date_format' ) ) : '',
			'organization'       => $organization ? $organization : __( "\xE2\x80\x94", 'awana-commerce' ),
			'total'              => $order->get_formatted_order_total(),
			'payment_method'     => $order->get_payment_method_title(),
			'crm_invoice_id'     => $crm_invoice_id,
			'crm_sync_woo'       => $order->get_meta( 'crm_sync_woo', true ),
			'crm_source'         => $order->get_meta( 'crm_source', true ),
			'pog_status'         => $order->get_meta( 'pog_status', true ),
			'pog_invoice_number' => $order->get_meta( 'pog_invoice_number', true ),
			'pog_kid'            => $order->get_meta( 'pog_kid_number', true ),
			'type_label'         => $type_label,
			'has_error'          => ! empty( $last_error ),
			'last_error'         => $last_error ? $last_error : '',
		);
	}
}
