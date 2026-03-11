<?php
/**
 * Admin UI class for Awana Commerce
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI class
 */
class Awana_Admin {

	/**
	 * Initialize the admin UI
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_admin_scripts' ) );
		add_action( 'wp_ajax_awana_manual_sync', array( $instance, 'handle_manual_sync_ajax' ) );
		add_action( 'wp_ajax_awana_retry_sync', array( $instance, 'handle_retry_sync_ajax' ) );
		add_action( 'wp_ajax_awana_sync_order', array( $instance, 'handle_sync_order_ajax' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Awana Sync', 'awana-commerce' ),
			__( 'Awana Sync', 'awana-commerce' ),
			'manage_woocommerce',
			'awana-sync',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_awana-sync' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $this->get_inline_script() );
	}

	/**
	 * Get inline JavaScript for AJAX sync functionality
	 *
	 * @return string JavaScript code.
	 */
	private function get_inline_script() {
		ob_start();
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce = wp_create_nonce( 'awana_sync_order' );
		?>
		jQuery(document).ready(function($) {
			$('.awana-sync-order-btn').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				var orderId = $button.data('order-id');
				var $listItem = $button.closest('li');
				var originalText = $button.text();
				
				// Disable button and show loading state
				$button.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing...', 'awana-commerce' ) ); ?>');
				
				$.ajax({
					url: '<?php echo esc_url( $ajax_url ); ?>',
					type: 'POST',
					data: {
						action: 'awana_sync_order',
						order_id: orderId,
						nonce: '<?php echo esc_js( $nonce ); ?>'
					},
					success: function(response) {
						if (response.success) {
							// Show success message
							$listItem.append('<span style="color: green; margin-left: 10px;">✓ ' + response.data.message + '</span>');
							// Remove the button
							$button.remove();
							// Optionally fade out the list item after a delay
							setTimeout(function() {
								$listItem.fadeOut(500, function() {
									$(this).remove();
									// Reload page after 2 seconds to refresh the health check
									setTimeout(function() {
										location.reload();
									}, 2000);
								});
							}, 1500);
						} else {
							// Show error message
							$listItem.append('<span style="color: red; margin-left: 10px;">✗ ' + response.data.message + '</span>');
							$button.prop('disabled', false).text(originalText);
						}
					},
					error: function() {
						$listItem.append('<span style="color: red; margin-left: 10px;"><?php echo esc_js( __( 'Error: Sync request failed', 'awana-commerce' ) ); ?></span>');
						$button.prop('disabled', false).text(originalText);
					}
				});
			});
		});
		<?php
		return ob_get_clean();
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		// Handle form submissions
		if ( isset( $_POST['awana_manual_sync'] ) && check_admin_referer( 'awana_manual_sync', 'awana_manual_sync_nonce' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			if ( $order_id > 0 ) {
				$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id );
				if ( $result['success'] ) {
					echo '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html( $result['message'] ) . '</p></div>';
				}
			}
		}

		if ( isset( $_POST['awana_retry_sync'] ) && check_admin_referer( 'awana_retry_sync', 'awana_retry_sync_nonce' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			if ( $order_id > 0 ) {
				$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id, true );
				if ( $result['success'] ) {
					echo '<div class="notice notice-success"><p>' . esc_html( $result['message'] ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html( $result['message'] ) . '</p></div>';
				}
			}
		}

		// Get search query
		$search_query = isset( $_GET['awana_search'] ) ? sanitize_text_field( $_GET['awana_search'] ) : '';
		$search_type = isset( $_GET['awana_search_type'] ) ? sanitize_text_field( $_GET['awana_search_type'] ) : 'order_id';

		$failed_syncs = $this->get_failed_syncs( $search_query, $search_type );
		$stats = $this->get_sync_statistics();
		$recent_syncs = $this->get_recent_syncs( 20, $search_query, $search_type );
		$completed_not_synced = $this->get_completed_orders_not_synced( $search_query, $search_type );
		$high_error_orders = $this->get_orders_with_high_error_count( $search_query, $search_type );

		?>
		<div class="wrap">
			<h1><?php echo esc_html( __( 'Awana Sync Management', 'awana-commerce' ) ); ?></h1>
			
			<div class="notice notice-info" style="margin: 20px 0;">
				<p>
					<strong><?php echo esc_html( __( 'About this dashboard:', 'awana-commerce' ) ); ?></strong>
					<?php echo esc_html( __( 'This dashboard tracks the sync status between AWANA CRM and WooCommerce. It does not track Integrera sync operations.', 'awana-commerce' ) ); ?>
				</p>
			</div>

			<!-- Search -->
			<div class="awana-search" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4;">
				<h2 style="margin-top: 0;"><?php echo esc_html( __( 'Search', 'awana-commerce' ) ); ?></h2>
				<form method="get" action="">
					<input type="hidden" name="page" value="awana-sync" />
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="awana_search_type"><?php echo esc_html( __( 'Search By', 'awana-commerce' ) ); ?></label>
								</th>
								<td>
									<select id="awana_search_type" name="awana_search_type">
										<option value="order_id" <?php selected( $search_type, 'order_id' ); ?>><?php echo esc_html( __( 'Order ID', 'awana-commerce' ) ); ?></option>
										<option value="invoice_id" <?php selected( $search_type, 'invoice_id' ); ?>><?php echo esc_html( __( 'Invoice ID', 'awana-commerce' ) ); ?></option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="awana_search"><?php echo esc_html( __( 'Search', 'awana-commerce' ) ); ?></label>
								</th>
								<td>
									<input type="text" id="awana_search" name="awana_search" value="<?php echo esc_attr( $search_query ); ?>" class="regular-text" placeholder="<?php echo esc_attr( __( 'Enter order ID or invoice ID...', 'awana-commerce' ) ); ?>" />
									<?php submit_button( __( 'Search', 'awana-commerce' ), 'secondary', 'submit', false ); ?>
									<?php if ( ! empty( $search_query ) ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=awana-sync' ) ); ?>" class="button"><?php echo esc_html( __( 'Clear', 'awana-commerce' ) ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</form>
			</div>

			<!-- Statistics -->
			<div class="awana-sync-stats" style="margin: 20px 0;">
				<h2><?php echo esc_html( __( 'Sync Statistics', 'awana-commerce' ) ); ?></h2>
				<table class="widefat">
					<tbody>
						<tr>
							<td><strong><?php echo esc_html( __( 'Total Orders Synced', 'awana-commerce' ) ); ?>:</strong></td>
							<td><?php echo esc_html( $stats['total_synced'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php echo esc_html( __( 'Failed Syncs', 'awana-commerce' ) ); ?>:</strong></td>
							<td><?php echo esc_html( $stats['failed_count'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php echo esc_html( __( 'Success Rate', 'awana-commerce' ) ); ?>:</strong></td>
							<td><?php echo esc_html( $stats['success_rate'] ); ?>%</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Recent Sync Activity -->
			<div class="awana-recent-syncs" style="margin: 20px 0;">
				<h2><?php echo esc_html( __( 'Recent Sync Activity', 'awana-commerce' ) ); ?></h2>
				<?php if ( empty( $recent_syncs ) ) : ?>
					<p><?php echo esc_html( __( 'No recent sync activity.', 'awana-commerce' ) ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php echo esc_html( __( 'Time', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Order ID', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Invoice ID', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Sync Type', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Status', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Result', 'awana-commerce' ) ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_syncs as $sync ) : ?>
								<tr>
									<td><?php echo esc_html( $sync['last_attempt'] ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $sync['order_id'] . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $sync['order_number'] ); ?>
										</a>
									</td>
									<td>
										<?php if ( ! empty( $sync['invoice_id'] ) && $sync['invoice_id'] !== __( 'N/A', 'awana-commerce' ) ) : ?>
											<a href="<?php echo esc_url( $this->get_firebase_url( $sync['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>">
												<?php echo esc_html( $sync['invoice_id'] ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $sync['invoice_id'] ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $sync['sync_type'] ); ?></td>
									<td>
										<?php
										$status_class = '';
										if ( $sync['status'] === 'success' ) {
											$status_class = 'color: green;';
										} elseif ( $sync['status'] === 'failed' ) {
											$status_class = 'color: red;';
										} elseif ( $sync['status'] === 'pending' ) {
											$status_class = 'color: orange;';
										}
										?>
										<span style="<?php echo esc_attr( $status_class ); ?>">
											<?php echo esc_html( ucfirst( $sync['status'] ) ); ?>
										</span>
									</td>
									<td>
										<?php if ( ! empty( $sync['error'] ) ) : ?>
											<span style="color: red;" title="<?php echo esc_attr( $sync['error'] ); ?>">
												<?php echo esc_html( $sync['error'] ); ?>
											</span>
										<?php else : ?>
											<span style="color: green;"><?php echo esc_html( __( 'Success', 'awana-commerce' ) ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Sync Health Check -->
			<div class="awana-sync-health" style="margin: 20px 0;">
				<h2><?php echo esc_html( __( 'Sync Health Check', 'awana-commerce' ) ); ?></h2>
				<?php if ( empty( $completed_not_synced ) && empty( $high_error_orders ) ) : ?>
					<p style="color: green;">
						<strong><?php echo esc_html( __( '✓ No sync issues detected.', 'awana-commerce' ) ); ?></strong>
					</p>
				<?php else : ?>
					<?php if ( ! empty( $completed_not_synced ) ) : ?>
						<div class="awana-health-issue" style="margin: 15px 0; padding: 10px; border-left: 4px solid #dc3232; background: #fff;">
							<h3 style="margin-top: 0;">
								<?php echo esc_html( __( 'Completed Orders Not Synced as Paid', 'awana-commerce' ) ); ?>
								<span style="color: #dc3232;">(<?php echo esc_html( count( $completed_not_synced ) ); ?>)</span>
							</h3>
							<p><?php echo esc_html( __( 'Orders with completed status that haven\'t been synced with paid status to CRM.', 'awana-commerce' ) ); ?></p>
							<?php if ( count( $completed_not_synced ) <= 10 ) : ?>
								<ul>
									<?php foreach ( $completed_not_synced as $order_data ) : ?>
										<li>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_data['order_id'] . '&action=edit' ) ); ?>">
												Order #<?php echo esc_html( $order_data['order_number'] ); ?>
											</a>
											<?php if ( ! empty( $order_data['invoice_id'] ) ) : ?>
												- Invoice ID: <a href="<?php echo esc_url( $this->get_firebase_url( $order_data['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>"><?php echo esc_html( $order_data['invoice_id'] ); ?></a>
											<?php endif; ?>
											<?php if ( ! empty( $order_data['completed_date'] ) ) : ?>
												- <?php echo esc_html( __( 'Completed:', 'awana-commerce' ) . ' ' . $order_data['completed_date'] ); ?>
											<?php endif; ?>
											<button type="button" class="button button-small awana-sync-order-btn" data-order-id="<?php echo esc_attr( $order_data['order_id'] ); ?>" style="margin-left: 10px;">
												<?php echo esc_html( __( 'Sync', 'awana-commerce' ) ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p><?php echo esc_html( sprintf( __( '%d orders found. Showing first 10.', 'awana-commerce' ), count( $completed_not_synced ) ) ); ?></p>
								<ul>
									<?php foreach ( array_slice( $completed_not_synced, 0, 10 ) as $order_data ) : ?>
										<li>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_data['order_id'] . '&action=edit' ) ); ?>">
												Order #<?php echo esc_html( $order_data['order_number'] ); ?>
											</a>
											<?php if ( ! empty( $order_data['invoice_id'] ) ) : ?>
												- Invoice ID: <a href="<?php echo esc_url( $this->get_firebase_url( $order_data['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>"><?php echo esc_html( $order_data['invoice_id'] ); ?></a>
											<?php endif; ?>
											<button type="button" class="button button-small awana-sync-order-btn" data-order-id="<?php echo esc_attr( $order_data['order_id'] ); ?>" style="margin-left: 10px;">
												<?php echo esc_html( __( 'Sync', 'awana-commerce' ) ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $high_error_orders ) ) : ?>
						<div class="awana-health-issue" style="margin: 15px 0; padding: 10px; border-left: 4px solid #dc3232; background: #fff;">
							<h3 style="margin-top: 0;">
								<?php echo esc_html( __( 'Orders with Multiple Sync Failures', 'awana-commerce' ) ); ?>
								<span style="color: #dc3232;">(<?php echo esc_html( count( $high_error_orders ) ); ?>)</span>
							</h3>
							<p><?php echo esc_html( __( 'Orders that have failed to sync 3 or more times.', 'awana-commerce' ) ); ?></p>
							<?php if ( count( $high_error_orders ) <= 10 ) : ?>
								<ul>
									<?php foreach ( $high_error_orders as $order_data ) : ?>
										<li>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_data['order_id'] . '&action=edit' ) ); ?>">
												Order #<?php echo esc_html( $order_data['order_number'] ); ?>
											</a>
											<?php if ( ! empty( $order_data['invoice_id'] ) ) : ?>
												- Invoice ID: <a href="<?php echo esc_url( $this->get_firebase_url( $order_data['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>"><?php echo esc_html( $order_data['invoice_id'] ); ?></a>
											<?php endif; ?>
											- <?php echo esc_html( sprintf( __( '%d errors', 'awana-commerce' ), $order_data['error_count'] ) ); ?>
											<?php if ( ! empty( $order_data['last_error'] ) ) : ?>
												: <?php echo esc_html( $order_data['last_error'] ); ?>
											<?php endif; ?>
											<button type="button" class="button button-small awana-sync-order-btn" data-order-id="<?php echo esc_attr( $order_data['order_id'] ); ?>" style="margin-left: 10px;">
												<?php echo esc_html( __( 'Sync', 'awana-commerce' ) ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p><?php echo esc_html( sprintf( __( '%d orders found. Showing first 10.', 'awana-commerce' ), count( $high_error_orders ) ) ); ?></p>
								<ul>
									<?php foreach ( array_slice( $high_error_orders, 0, 10 ) as $order_data ) : ?>
										<li>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_data['order_id'] . '&action=edit' ) ); ?>">
												Order #<?php echo esc_html( $order_data['order_number'] ); ?>
											</a>
											<?php if ( ! empty( $order_data['invoice_id'] ) ) : ?>
												- Invoice ID: <a href="<?php echo esc_url( $this->get_firebase_url( $order_data['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>"><?php echo esc_html( $order_data['invoice_id'] ); ?></a>
											<?php endif; ?>
											- <?php echo esc_html( sprintf( __( '%d errors', 'awana-commerce' ), $order_data['error_count'] ) ); ?>
											<button type="button" class="button button-small awana-sync-order-btn" data-order-id="<?php echo esc_attr( $order_data['order_id'] ); ?>" style="margin-left: 10px;">
												<?php echo esc_html( __( 'Sync', 'awana-commerce' ) ); ?>
											</button>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- Manual Sync -->
			<div class="awana-manual-sync" style="margin: 20px 0;">
				<h2><?php echo esc_html( __( 'Manual Sync', 'awana-commerce' ) ); ?></h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'awana_manual_sync', 'awana_manual_sync_nonce' ); ?>
					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row">
									<label for="order_id"><?php echo esc_html( __( 'Order ID', 'awana-commerce' ) ); ?></label>
								</th>
								<td>
									<input type="number" id="order_id" name="order_id" class="regular-text" min="1" required />
									<p class="description"><?php echo esc_html( __( 'Enter the WooCommerce order ID to sync.', 'awana-commerce' ) ); ?></p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( __( 'Sync Now', 'awana-commerce' ), 'primary', 'awana_manual_sync' ); ?>
				</form>
			</div>

			<!-- Failed Syncs -->
			<div class="awana-failed-syncs" style="margin: 20px 0;">
				<h2><?php echo esc_html( __( 'Failed Syncs', 'awana-commerce' ) ); ?></h2>
				<?php if ( empty( $failed_syncs ) ) : ?>
					<p><?php echo esc_html( __( 'No failed syncs found.', 'awana-commerce' ) ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php echo esc_html( __( 'Order ID', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Order Number', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Invoice ID', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Last Error', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Last Attempt', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Error Count', 'awana-commerce' ) ); ?></th>
								<th><?php echo esc_html( __( 'Actions', 'awana-commerce' ) ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $failed_syncs as $order ) : ?>
								<tr>
									<td><?php echo esc_html( $order['order_id'] ); ?></td>
									<td><?php echo esc_html( $order['order_number'] ); ?></td>
									<td>
										<?php if ( ! empty( $order['invoice_id'] ) && $order['invoice_id'] !== __( 'N/A', 'awana-commerce' ) ) : ?>
											<a href="<?php echo esc_url( $this->get_firebase_url( $order['invoice_id'] ) ); ?>" target="_blank" title="<?php echo esc_attr( __( 'View in Firebase', 'awana-commerce' ) ); ?>">
												<?php echo esc_html( $order['invoice_id'] ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $order['invoice_id'] ); ?>
										<?php endif; ?>
									</td>
									<td>
										<span title="<?php echo esc_attr( $order['error'] ); ?>">
											<?php echo esc_html( $this->format_sync_error( $order['error'] ) ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $order['last_attempt'] ); ?></td>
									<td><?php echo esc_html( $order['error_count'] ); ?></td>
									<td>
										<form method="post" action="" style="display: inline;">
											<?php wp_nonce_field( 'awana_retry_sync', 'awana_retry_sync_nonce' ); ?>
											<input type="hidden" name="order_id" value="<?php echo esc_attr( $order['order_id'] ); ?>" />
											<?php submit_button( __( 'Retry', 'awana-commerce' ), 'small', 'awana_retry_sync', false ); ?>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle manual sync AJAX request
	 */
	public function handle_manual_sync_ajax() {
		check_ajax_referer( 'awana_manual_sync', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-commerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'awana-commerce' ) ) );
		}

		$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Handle retry sync AJAX request
	 */
	public function handle_retry_sync_ajax() {
		check_ajax_referer( 'awana_retry_sync', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-commerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'awana-commerce' ) ) );
		}

		$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id, true );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Handle sync order AJAX request (from health check)
	 */
	public function handle_sync_order_ajax() {
		check_ajax_referer( 'awana_sync_order', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-commerce' ) ) );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		if ( $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order ID.', 'awana-commerce' ) ) );
		}

		$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id, true );

		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => $result['message'] ) );
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * Get Firebase URL for invoice ID
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return string Firebase URL.
	 */
	private function get_firebase_url( $invoice_id ) {
		if ( empty( $invoice_id ) ) {
			return '';
		}
		// Firebase uses ~2F to encode / in paths: ~2Finvoices~2F{invoice_id}
		// The invoice ID is appended directly without additional encoding
		$encoded_path = '~2Finvoices~2F' . $invoice_id;
		return 'https://console.firebase.google.com/u/0/project/awana-server/firestore/databases/-default-/data/' . $encoded_path;
	}

	/**
	 * Get failed syncs
	 *
	 * @param string $search_query Search query.
	 * @param string $search_type Search type (order_id or invoice_id).
	 * @return array Array of failed sync orders.
	 */
	private function get_failed_syncs( $search_query = '', $search_type = 'order_id' ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'meta_key'   => 'crm_sync_woo',
				'meta_value' => 'failed',
				'return'     => 'ids',
			)
		);

		$failed_syncs = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$invoice_id = $order->get_meta( 'crm_invoice_id', true );
			
			// Apply search filter
			if ( ! empty( $search_query ) ) {
				if ( $search_type === 'order_id' && strpos( (string) $order_id, $search_query ) === false && strpos( (string) $order->get_order_number(), $search_query ) === false ) {
					continue;
				} elseif ( $search_type === 'invoice_id' && ( empty( $invoice_id ) || strpos( (string) $invoice_id, $search_query ) === false ) ) {
					continue;
				}
			}

			$last_error = $order->get_meta( '_awana_sync_last_error', true );
			$last_attempt = $order->get_meta( '_awana_sync_last_attempt', true );
			$error_count = $order->get_meta( '_awana_sync_error_count', true );

			$failed_syncs[] = array(
				'order_id'     => $order_id,
				'order_number' => $order->get_order_number(),
				'invoice_id'   => $invoice_id ? $invoice_id : __( 'N/A', 'awana-commerce' ),
				'error'        => $last_error ? $last_error : __( 'Unknown error', 'awana-commerce' ),
				'last_attempt' => $last_attempt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_attempt ) : __( 'Never', 'awana-commerce' ),
				'error_count'  => $error_count ? $error_count : 0,
			);
		}

		return $failed_syncs;
	}

	/**
	 * Get sync statistics
	 *
	 * @return array Statistics array.
	 */
	private function get_sync_statistics() {
		// Get all Awana orders
		$all_orders = wc_get_orders(
			array(
				'limit'      => -1,
				'meta_key'   => 'crm_invoice_id',
				'meta_compare' => 'EXISTS',
				'return'     => 'ids',
			)
		);

		$total_synced = count( $all_orders );
		$failed_count = 0;
		$success_count = 0;

		foreach ( $all_orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$sync_status = $order->get_meta( 'crm_sync_woo', true );
			if ( $sync_status === 'failed' ) {
				$failed_count++;
			} elseif ( $sync_status === 'success' ) {
				$success_count++;
			}
		}

		// Calculate success rate based only on orders that have attempted to sync (success + failed)
		$total_attempted = $success_count + $failed_count;
		$success_rate = $total_attempted > 0 ? round( ( $success_count / $total_attempted ) * 100, 1 ) : 0;

		return array(
			'total_synced' => $total_synced,
			'failed_count' => $failed_count,
			'success_count' => $success_count,
			'success_rate' => $success_rate,
		);
	}

	/**
	 * Format sync error for display
	 *
	 * @param string $error Error message.
	 * @return string Formatted error message.
	 */
	private function format_sync_error( $error ) {
		if ( empty( $error ) ) {
			return __( 'No error message', 'awana-commerce' );
		}

		// Truncate long error messages
		if ( strlen( $error ) > 100 ) {
			return substr( $error, 0, 100 ) . '...';
		}

		return $error;
	}

	/**
	 * Get recent sync activity
	 *
	 * @param int    $limit Number of recent syncs to retrieve.
	 * @param string $search_query Search query.
	 * @param string $search_type Search type (order_id or invoice_id).
	 * @return array Array of recent sync activities.
	 */
	private function get_recent_syncs( $limit = 50, $search_query = '', $search_type = 'order_id' ) {
		// Get all Awana orders ordered by last sync attempt
		$orders = wc_get_orders(
			array(
				'limit'      => $limit,
				'meta_key'   => '_awana_sync_last_attempt',
				'orderby'    => 'meta_value_num',
				'order'      => 'DESC',
				'meta_compare' => 'EXISTS',
				'return'     => 'ids',
			)
		);

		$recent_syncs = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$invoice_id = $order->get_meta( 'crm_invoice_id', true );
			
			// Apply search filter
			if ( ! empty( $search_query ) ) {
				if ( $search_type === 'order_id' && strpos( (string) $order_id, $search_query ) === false && strpos( (string) $order->get_order_number(), $search_query ) === false ) {
					continue;
				} elseif ( $search_type === 'invoice_id' && ( empty( $invoice_id ) || strpos( (string) $invoice_id, $search_query ) === false ) ) {
					continue;
				}
			}

			$sync_status = $order->get_meta( 'crm_sync_woo', true );
			$last_attempt = $order->get_meta( '_awana_sync_last_attempt', true );
			$last_success = $order->get_meta( '_awana_sync_last_success', true );
			$last_error = $order->get_meta( '_awana_sync_last_error', true );
			$order_status = $order->get_status();

			// Determine sync type based on what triggered it
			$sync_type = $this->determine_sync_type( $order );

			$recent_syncs[] = array(
				'order_id'     => $order_id,
				'order_number' => $order->get_order_number(),
				'invoice_id'   => $invoice_id ? $invoice_id : __( 'N/A', 'awana-commerce' ),
				'sync_type'    => $sync_type,
				'status'       => $sync_status,
				'order_status' => $order_status,
				'last_attempt' => $last_attempt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_attempt ) : __( 'Never', 'awana-commerce' ),
				'last_success' => $last_success ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_success ) : __( 'Never', 'awana-commerce' ),
				'error'        => $last_error ? $this->format_sync_error( $last_error ) : '',
				'timestamp'    => $last_attempt ? $last_attempt : 0,
			);
		}

		// Sort by timestamp descending (most recent first)
		usort( $recent_syncs, function( $a, $b ) {
			return $b['timestamp'] - $a['timestamp'];
		} );

		return array_slice( $recent_syncs, 0, $limit );
	}

	/**
	 * Determine sync type based on order state
	 *
	 * @param WC_Order $order Order object.
	 * @return string Sync type description.
	 */
	private function determine_sync_type( $order ) {
		$order_status = $order->get_status();
		$pog_customer = $order->get_meta( 'pog_customer_number', true );
		$pog_status = $order->get_meta( 'pog_status', true );
		$last_success = $order->get_meta( '_awana_sync_last_success', true );
		$last_attempt = $order->get_meta( '_awana_sync_last_attempt', true );

		// Check if sync happened after order was completed
		if ( $order_status === 'completed' && $last_success ) {
			$order_date = $order->get_date_completed();
			if ( $order_date && $last_success >= $order_date->getTimestamp() ) {
				return __( 'Order Status Change', 'awana-commerce' );
			}
		}

		// Check if POG customer number exists and was synced
		if ( ! empty( $pog_customer ) ) {
			$synced_customer = $order->get_meta( '_pog_customer_synced_to_crm', true );
			if ( (string) $pog_customer === (string) $synced_customer ) {
				return __( 'POG Customer Number', 'awana-commerce' );
			}
		}

		// Check if POG status/KID exists
		if ( ! empty( $pog_status ) ) {
			return __( 'POG Status/KID', 'awana-commerce' );
		}

		// Default to manual if we can't determine
		return __( 'Manual', 'awana-commerce' );
	}

	/**
	 * Get completed orders that haven't been synced as paid
	 *
	 * @param string $search_query Search query.
	 * @param string $search_type Search type (order_id or invoice_id).
	 * @return array Array of completed orders not synced.
	 */
	private function get_completed_orders_not_synced( $search_query = '', $search_type = 'order_id' ) {
		$orders = wc_get_orders(
			array(
				'limit'      => 100,
				'status'     => 'completed',
				'meta_key'   => 'crm_invoice_id',
				'meta_compare' => 'EXISTS',
				'return'     => 'ids',
			)
		);

		$results = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$invoice_id = $order->get_meta( 'crm_invoice_id', true );
			
			// Apply search filter
			if ( ! empty( $search_query ) ) {
				if ( $search_type === 'order_id' && strpos( (string) $order_id, $search_query ) === false && strpos( (string) $order->get_order_number(), $search_query ) === false ) {
					continue;
				} elseif ( $search_type === 'invoice_id' && ( empty( $invoice_id ) || strpos( (string) $invoice_id, $search_query ) === false ) ) {
					continue;
				}
			}

			$last_success = $order->get_meta( '_awana_sync_last_success', true );
			$order_date = $order->get_date_completed();

			// If never synced, or last sync was before order was completed
			if ( ! $last_success || ( $order_date && $last_success < $order_date->getTimestamp() ) ) {
				$results[] = array(
					'order_id'       => $order_id,
					'order_number'   => $order->get_order_number(),
					'invoice_id'     => $invoice_id,
					'completed_date' => $order_date ? $order_date->date_i18n( get_option( 'date_format' ) ) : '',
				);
			}
		}

		return $results;
	}

	/**
	 * Get orders with high error counts
	 *
	 * @param string $search_query Search query.
	 * @param string $search_type Search type (order_id or invoice_id).
	 * @return array Array of orders with 3+ sync failures.
	 */
	private function get_orders_with_high_error_count( $search_query = '', $search_type = 'order_id' ) {
		$orders = wc_get_orders(
			array(
				'limit'       => 100,
				'meta_key'    => '_awana_sync_error_count',
				'meta_value'  => 3,
				'meta_compare' => '>=',
				'meta_type'   => 'NUMERIC',
				'return'      => 'ids',
			)
		);

		$results = array();
		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$invoice_id = $order->get_meta( 'crm_invoice_id', true );
			
			// Apply search filter
			if ( ! empty( $search_query ) ) {
				if ( $search_type === 'order_id' && strpos( (string) $order_id, $search_query ) === false && strpos( (string) $order->get_order_number(), $search_query ) === false ) {
					continue;
				} elseif ( $search_type === 'invoice_id' && ( empty( $invoice_id ) || strpos( (string) $invoice_id, $search_query ) === false ) ) {
					continue;
				}
			}

			$error_count = $order->get_meta( '_awana_sync_error_count', true );
			$last_error = $order->get_meta( '_awana_sync_last_error', true );

			$results[] = array(
				'order_id'     => $order_id,
				'order_number' => $order->get_order_number(),
				'invoice_id'   => $invoice_id,
				'error_count'  => $error_count ? $error_count : 0,
				'last_error'   => $last_error ? $this->format_sync_error( $last_error ) : __( 'No error message', 'awana-commerce' ),
			);
		}

		return $results;
	}
}

