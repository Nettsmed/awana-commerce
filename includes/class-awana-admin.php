<?php
/**
 * Admin UI for Awana Commerce — single consolidated page (WooCommerce → Awana Sync).
 *
 * Three tabs:
 *  - ordrer      All WC orders with sync status (B2B / B2C / Invoice-import). Default.
 *  - mislykkede  Orders with failed CRM sync, retry actions.
 *  - helse       5-rule health-check dashboard (delegates to Awana_Health_Check).
 *
 * Replaces the previous two-page split (awana-sync + awana-b2b-sync) introduced in
 * v1.2.0. Old URL ?page=awana-b2b-sync redirects here for back-compat.
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Awana_Admin {

	const PAGE_SLUG       = 'awana-sync';
	const LEGACY_PAGE     = 'awana-b2b-sync';
	const PER_PAGE        = 50;
	const VALID_TABS      = array( 'ordrer', 'mislykkede', 'helse' );
	const VALID_FILTERS   = array( 'all', 'b2b', 'b2c', 'invoice', 'missing_crm', 'errors', 'cancelled' );

	// Statuses excluded from the default Ordrer-tab view (and from summary
	// counts). Use the "Kansellerte" filter chip to surface them on demand.
	// Rationale: cancelled/failed orders aren't actionable for sync — the bulk
	// of them on Awana are auto-cancelled Nets-timeouts after the 60-min Hold
	// Stock window, plus historical test orders.
	const INACTIVE_STATUSES = array( 'trash', 'auto-draft', 'wc-cancelled', 'wc-failed' );

	/**
	 * Initialize admin hooks.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $instance, 'maybe_redirect_legacy_page' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_admin_scripts' ) );

		add_action( 'wp_ajax_awana_manual_sync', array( $instance, 'handle_manual_sync_ajax' ) );
		add_action( 'wp_ajax_awana_retry_sync', array( $instance, 'handle_retry_sync_ajax' ) );
		add_action( 'wp_ajax_awana_sync_order', array( $instance, 'handle_sync_order_ajax' ) );
		add_action( 'wp_ajax_awana_retry_checkout_sync', array( $instance, 'handle_retry_checkout_sync_ajax' ) );
	}

	/**
	 * Add the single Awana Sync menu entry under WooCommerce.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Awana Sync', 'awana-commerce' ),
			__( 'Awana Sync', 'awana-commerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Redirect legacy ?page=awana-b2b-sync URLs to the new consolidated page.
	 *
	 * Preserves the tab parameter — old "helse" tab still works, and the old
	 * "b2b" tab maps to the new "ordrer" tab with filter=b2b applied.
	 *
	 * Only redirects on GET. POST submissions to the legacy URL (e.g. a stale
	 * tab still showing the old retry form) would otherwise have their body
	 * silently dropped — better to fall through and let WP show "you don't
	 * have access" so the user notices and reloads.
	 */
	public function maybe_redirect_legacy_page() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( empty( $_GET['page'] ) || self::LEGACY_PAGE !== $_GET['page'] ) {
			return;
		}

		$old_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'b2b';
		$args    = array( 'page' => self::PAGE_SLUG );

		if ( 'helse' === $old_tab ) {
			$args['tab'] = 'helse';
		} else {
			// b2b (or anything else) → ordrer tab with B2B filter applied.
			$args['tab']    = 'ordrer';
			$args['filter'] = 'b2b';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Enqueue inline AJAX script on the Awana Sync page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $this->get_inline_script() );
	}

	/**
	 * Inline JS — handles "Retry" button on Mislykkede tab and "Sync" on Ordrer tab.
	 */
	private function get_inline_script() {
		ob_start();
		$ajax_url           = admin_url( 'admin-ajax.php' );
		$nonce_sync         = wp_create_nonce( 'awana_sync_order' );
		$nonce_retry_b2b    = wp_create_nonce( 'awana_retry_checkout_sync' );
		?>
		jQuery(document).ready(function($) {
			// Generic per-row sync (Ordrer + Mislykkede tabs).
			$('.awana-sync-order-btn').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				var orderId = $button.data('order-id');
				var $row = $button.closest('tr,li');
				var originalText = $button.text();

				$button.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing…', 'awana-commerce' ) ); ?>');

				$.ajax({
					url: '<?php echo esc_url( $ajax_url ); ?>',
					type: 'POST',
					data: {
						action: 'awana_sync_order',
						order_id: orderId,
						nonce: '<?php echo esc_js( $nonce_sync ); ?>'
					},
					success: function(response) {
						// Use jQuery DOM-construction + .text() so response.data.message
						// can't inject HTML (it can echo upstream Firebase/POG errors).
						if (response.success) {
							var $msg = $('<span>').css('color', 'green').text(' ✓ ' + response.data.message);
							$row.append($msg);
							$button.remove();
							setTimeout(function() { location.reload(); }, 1500);
						} else {
							var errMsg = (response.data && response.data.message) || 'Error';
							var $err = $('<span>').css('color', 'red').text(' ✗ ' + errMsg);
							$row.append($err);
							$button.prop('disabled', false).text(originalText);
						}
					},
					error: function() {
						$row.append($('<span>').css('color', 'red').text(' <?php echo esc_js( __( 'Request failed', 'awana-commerce' ) ); ?>'));
						$button.prop('disabled', false).text(originalText);
					}
				});
			});

			// B2B-specific retry (clears _awana_checkout_invoice_synced + re-sends Firebase create).
			$('.awana-b2b-retry-btn').on('click', function(e) {
				e.preventDefault();
				var $button = $(this);
				var orderId = $button.data('order-id');
				var $row = $button.closest('tr');
				var originalText = $button.text();

				$button.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing…', 'awana-commerce' ) ); ?>');

				$.ajax({
					url: '<?php echo esc_url( $ajax_url ); ?>',
					type: 'POST',
					data: {
						action: 'awana_retry_checkout_sync',
						order_id: orderId,
						nonce: '<?php echo esc_js( $nonce_retry_b2b ); ?>'
					},
					success: function(response) {
						// Use .text() for response.data.message — it can echo
						// upstream Firebase error bodies (attacker-influenced).
						// Construct via jQuery DOM ($('<span>').text()) instead of
						// concatenating into .html(). Same pattern as the deleted
						// Awana_B2B_Sync_Status used to.
						if (response.success) {
							var $msg = $('<span>').css({color: 'green', fontWeight: 'bold'}).text(response.data.message);
							$row.find('.awana-status-cell').empty().append($msg);
							$button.text('<?php echo esc_js( __( 'Done', 'awana-commerce' ) ); ?>');
							setTimeout(function() { location.reload(); }, 2000);
						} else {
							var errMsg = (response.data && response.data.message) || 'Unknown error';
							var $err = $('<span>').css({color: 'red'}).text(errMsg);
							$row.find('.awana-status-cell').append('<br>').append($err);
							$button.prop('disabled', false).text(originalText);
						}
					},
					error: function() {
						$row.find('.awana-status-cell').append($('<br>')).append($('<span>').css({color: 'red'}).text('<?php echo esc_js( __( 'Request failed', 'awana-commerce' ) ); ?>'));
						$button.prop('disabled', false).text(originalText);
					}
				});
			});
		});
		<?php
		return ob_get_clean();
	}

	// =========================================================================
	// Page rendering — orchestrator + per-tab renderers
	// =========================================================================

	/**
	 * Top-level page render — handles POST manual-sync, draws tab nav, dispatches.
	 */
	public function render_admin_page() {
		// Handle legacy POST submissions (manual sync form, retry buttons that submit a form).
		$this->handle_post_submissions();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'ordrer';
		if ( ! in_array( $tab, self::VALID_TABS, true ) ) {
			$tab = 'ordrer';
		}

		$page_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Awana Sync', 'awana-commerce' ); ?></h1>

			<nav class="nav-tab-wrapper" style="margin-top:12px;">
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'ordrer', $page_url ) ); ?>"
				   class="nav-tab <?php echo 'ordrer' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Ordrer', 'awana-commerce' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'mislykkede', $page_url ) ); ?>"
				   class="nav-tab <?php echo 'mislykkede' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Mislykkede syncs', 'awana-commerce' ); ?>
				</a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'helse', $page_url ) ); ?>"
				   class="nav-tab <?php echo 'helse' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Helse', 'awana-commerce' ); ?>
				</a>
			</nav>

			<?php
			if ( 'helse' === $tab ) {
				$this->render_helse_tab();
			} elseif ( 'mislykkede' === $tab ) {
				$this->render_mislykkede_tab();
			} else {
				$this->render_ordrer_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Process POST manual-sync / retry-sync submissions before any output.
	 *
	 * Output is fine here — we're already inside the page-render callback, so
	 * notices land above the tab nav.
	 */
	private function handle_post_submissions() {
		// Capability check — render_admin_page() is already gated by the menu's
		// 'manage_woocommerce' cap, but we re-check here so a future change to
		// expose this page under a lower cap doesn't silently widen who can
		// trigger sync. AJAX handlers do the same.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_POST['awana_manual_sync'] ) && check_admin_referer( 'awana_manual_sync', 'awana_manual_sync_nonce' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			if ( $order_id > 0 ) {
				$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id );
				$class  = $result['success'] ? 'notice-success' : 'notice-error';
				echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $result['message'] ) . '</p></div>';
			}
		}

		if ( isset( $_POST['awana_retry_sync'] ) && check_admin_referer( 'awana_retry_sync', 'awana_retry_sync_nonce' ) ) {
			$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			if ( $order_id > 0 ) {
				$result = Awana_CRM_Webhook::sync_all_order_metadata_to_crm( $order_id, true );
				$class  = $result['success'] ? 'notice-success' : 'notice-error';
				echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $result['message'] ) . '</p></div>';
			}
		}
	}

	// -------------------------------------------------------------------------
	// Tab: Ordrer (all WC orders with sync status + log of activity)
	// -------------------------------------------------------------------------

	private function render_ordrer_tab() {
		$filter       = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : 'all';
		if ( ! in_array( $filter, self::VALID_FILTERS, true ) ) {
			$filter = 'all';
		}
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$search       = isset( $_GET['awana_search'] ) ? sanitize_text_field( $_GET['awana_search'] ) : '';
		$summary      = $this->get_orders_summary();
		$orders       = $this->get_orders_page( $filter, $paged, $search );
		?>
		<p style="color:#50575e;margin-top:12px;">
			<?php esc_html_e( 'Aktive WooCommerce-ordrer med sync-status. Kansellerte og failed-ordrer er skjult som default — bruk "Kansellerte"-filteret hvis du trenger å se dem. B2B (Nets) og fakturaimporter har CRM-kobling i dag; B2C synkroniseres ikke ennå (på roadmap).', 'awana-commerce' ); ?>
		</p>

		<?php $this->render_search_form( $search, $filter ); ?>
		<?php $this->render_summary_cards( $summary ); ?>
		<?php $this->render_filter_chips( $filter, $summary ); ?>
		<?php $this->render_orders_table( $orders ); ?>
		<?php $this->render_pagination( $orders, $filter, $paged, $search ); ?>
		<?php $this->render_manual_sync_form(); ?>
		<?php
	}

	/**
	 * Search form — works on the Ordrer tab. Submits via GET so deep-linkable.
	 *
	 * Preserves the active filter so searching while on a B2B/B2C/etc. filter
	 * doesn't silently revert to "Alle".
	 */
	private function render_search_form( $search, $filter = 'all' ) {
		?>
		<form method="get" action="" style="margin:20px 0;padding:12px;background:#fff;border:1px solid #ccd0d4;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="ordrer" />
			<input type="hidden" name="filter" value="<?php echo esc_attr( $filter ); ?>" />
			<label for="awana_search" style="font-weight:600;margin-right:8px;">
				<?php esc_html_e( 'Søk:', 'awana-commerce' ); ?>
			</label>
			<input type="text" id="awana_search" name="awana_search" value="<?php echo esc_attr( $search ); ?>"
			       class="regular-text" placeholder="<?php esc_attr_e( 'Ordre-ID, navn, e-post, organisasjon, CRM-invoice-ID', 'awana-commerce' ); ?>" style="width:380px;" />
			<?php submit_button( __( 'Søk', 'awana-commerce' ), 'secondary', 'submit', false ); ?>
			<?php if ( ! empty( $search ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=ordrer' ) ); ?>" class="button">
					<?php esc_html_e( 'Tøm søk', 'awana-commerce' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * 4 stat-cards: total / synced / pending / failed.
	 */
	private function render_summary_cards( $summary ) {
		$cards = array(
			array( 'label' => __( 'Total ordrer', 'awana-commerce' ), 'value' => $summary['total'],   'color' => '#2271b1' ),
			array( 'label' => __( 'Synkronisert', 'awana-commerce' ), 'value' => $summary['synced'],  'color' => '#00a32a' ),
			array( 'label' => __( 'Pending',     'awana-commerce' ), 'value' => $summary['pending'], 'color' => '#dba617' ),
			array( 'label' => __( 'Mislykket',   'awana-commerce' ), 'value' => $summary['failed'],  'color' => '#d63638' ),
		);
		?>
		<div style="display:flex;gap:16px;margin:20px 0;">
			<?php foreach ( $cards as $card ) : ?>
				<div style="flex:1;background:#fff;border:1px solid #ccd0d4;border-top:4px solid <?php echo esc_attr( $card['color'] ); ?>;padding:16px;text-align:center;">
					<div style="font-size:28px;font-weight:600;color:<?php echo esc_attr( $card['color'] ); ?>;"><?php echo esc_html( $card['value'] ); ?></div>
					<div style="margin-top:4px;color:#50575e;"><?php echo esc_html( $card['label'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Filter chips — Alle / B2B / B2C / Faktura / Mangler CRM / Med feil.
	 */
	private function render_filter_chips( $active_filter, $summary ) {
		$filters = array(
			'all'         => array( 'label' => __( 'Alle',           'awana-commerce' ), 'count' => $summary['total'] ),
			'b2b'         => array( 'label' => __( 'B2B',            'awana-commerce' ), 'count' => $summary['b2b'] ),
			'b2c'         => array( 'label' => __( 'B2C',            'awana-commerce' ), 'count' => $summary['b2c'] ),
			'invoice'     => array( 'label' => __( 'Faktura-import', 'awana-commerce' ), 'count' => $summary['invoice'] ),
			'missing_crm' => array( 'label' => __( 'Mangler CRM-ID', 'awana-commerce' ), 'count' => $summary['pending'] ),
			'errors'      => array( 'label' => __( 'Med feil',       'awana-commerce' ), 'count' => $summary['failed'] ),
			'cancelled'   => array( 'label' => __( 'Kansellerte',    'awana-commerce' ), 'count' => $summary['cancelled'] ),
		);
		$last_key = array_key_last( $filters );
		?>
		<ul class="subsubsub" style="margin-bottom:10px;">
			<?php foreach ( $filters as $key => $row ) :
				$url   = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'ordrer', 'filter' => $key ), admin_url( 'admin.php' ) );
				$class = ( $active_filter === $key ) ? 'current' : '';
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
						<?php echo esc_html( $row['label'] ); ?>
						<span class="count">(<?php echo esc_html( $row['count'] ); ?>)</span>
					</a><?php if ( $key !== $last_key ) : ?> | <?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * The unified orders table — type / sync status / POG status / actions.
	 */
	private function render_orders_table( $orders ) {
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:80px;"><?php esc_html_e( 'Ordre #', 'awana-commerce' ); ?></th>
					<th style="width:60px;"><?php esc_html_e( 'Type', 'awana-commerce' ); ?></th>
					<th style="width:100px;"><?php esc_html_e( 'Dato', 'awana-commerce' ); ?></th>
					<th><?php esc_html_e( 'Kunde / Org', 'awana-commerce' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Total', 'awana-commerce' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'CRM Invoice ID', 'awana-commerce' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'CRM Sync', 'awana-commerce' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'POG', 'awana-commerce' ); ?></th>
					<th style="width:40px;" class="awana-status-cell"></th>
					<th style="width:90px;"><?php esc_html_e( 'Handling', 'awana-commerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $orders['items'] ) ) : ?>
					<tr><td colspan="10"><?php esc_html_e( 'Ingen ordrer matcher filteret.', 'awana-commerce' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $orders['items'] as $row ) : $this->render_order_row( $row ); endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * One table row.
	 */
	private function render_order_row( $row ) {
		$sync   = $this->get_sync_display( $row['crm_sync_woo'], $row['type'] );
		$dot    = $this->get_dot_display( $row );
		$badge  = $this->get_type_badge( $row['type'] );
		?>
		<tr>
			<td><a href="<?php echo esc_url( $row['edit_url'] ); ?>">#<?php echo esc_html( $row['order_number'] ); ?></a></td>
			<td><span style="<?php echo esc_attr( $badge['style'] ); ?>;padding:2px 6px;border-radius:3px;font-size:11px;font-weight:600;"><?php echo esc_html( $badge['text'] ); ?></span></td>
			<td><?php echo esc_html( $row['date'] ); ?></td>
			<td><?php echo esc_html( $row['customer'] ); ?></td>
			<td><?php echo wp_kses_post( $row['total'] ); ?></td>
			<td><?php $this->render_crm_invoice_cell( $row ); ?></td>
			<td><span style="color:<?php echo esc_attr( $sync['color'] ); ?>;font-weight:500;"><?php echo esc_html( $sync['label'] ); ?></span></td>
			<td><?php $this->render_pog_cell( $row ); ?></td>
			<td class="awana-status-cell" style="text-align:center;">
				<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:<?php echo esc_attr( $dot['color'] ); ?>;" title="<?php echo esc_attr( $dot['title'] ); ?>"></span>
			</td>
			<td>
				<?php if ( 'b2b' === $row['type'] ) : ?>
					<button type="button" class="button button-small awana-b2b-retry-btn" data-order-id="<?php echo esc_attr( $row['order_id'] ); ?>"><?php esc_html_e( 'Retry', 'awana-commerce' ); ?></button>
				<?php elseif ( 'invoice' === $row['type'] ) : ?>
					<button type="button" class="button button-small awana-sync-order-btn" data-order-id="<?php echo esc_attr( $row['order_id'] ); ?>"><?php esc_html_e( 'Sync', 'awana-commerce' ); ?></button>
				<?php else : ?>
					<span style="color:#a7aaad;font-size:11px;"><?php esc_html_e( 'B2C-sync ikke konf.', 'awana-commerce' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_crm_invoice_cell( $row ) {
		if ( ! empty( $row['crm_invoice_id'] ) ) :
			?>
			<code style="color:#00a32a;font-size:11px;"><?php echo esc_html( substr( $row['crm_invoice_id'], 0, 12 ) ); ?>&hellip;</code>
			<?php if ( ! empty( $row['crm_source'] ) ) : ?>
				<br><span style="color:#a7aaad;font-size:11px;"><?php echo esc_html( $row['crm_source'] ); ?></span>
			<?php endif; ?>
		<?php else : ?>
			<span style="color:<?php echo 'b2c' === $row['type'] ? '#a7aaad' : '#d63638'; ?>;font-weight:500;">
				<?php echo 'b2c' === $row['type'] ? esc_html__( '—', 'awana-commerce' ) : esc_html__( 'Mangler', 'awana-commerce' ); ?>
			</span>
			<?php
		endif;
	}

	private function render_pog_cell( $row ) {
		// Integrera writes pog_order_number when an order document is created,
		// and pog_invoice_number when it's converted to an invoice. Most orders
		// stay at the order stage (status="order") and never get an invoice
		// number — show whichever one is present.
		$pog_number = ! empty( $row['pog_invoice_number'] ) ? $row['pog_invoice_number'] : $row['pog_order_number'];

		if ( ! empty( $pog_number ) ) {
			echo '<span style="font-size:11px;">#' . esc_html( $pog_number ) . '</span>';
		}
		if ( ! empty( $row['pog_kid'] ) ) {
			echo '<br><span style="color:#50575e;font-size:11px;">KID: ' . esc_html( $row['pog_kid'] ) . '</span>';
		}
		if ( ! empty( $row['pog_status'] ) ) {
			echo '<br><span style="color:#50575e;font-size:11px;">' . esc_html( $row['pog_status'] ) . '</span>';
		} elseif ( empty( $pog_number ) ) {
			echo '<span style="color:#a7aaad;">&mdash;</span>';
		}
	}

	private function render_pagination( $orders, $filter, $paged, $search ) {
		if ( $orders['total_pages'] <= 1 ) {
			return;
		}
		$base_url = add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'tab' => 'ordrer', 'filter' => $filter, 'awana_search' => $search ),
			admin_url( 'admin.php' )
		);
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php echo esc_html( sprintf( _n( '%s ordre', '%s ordrer', $orders['total'], 'awana-commerce' ), number_format_i18n( $orders['total'] ) ) ); ?>
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
						<?php echo esc_html( $paged ); ?> <?php esc_html_e( 'av', 'awana-commerce' ); ?>
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

	/**
	 * Manual sync form — small, at the bottom of the Ordrer tab.
	 */
	private function render_manual_sync_form() {
		?>
		<div style="margin:24px 0;padding:12px;background:#fff;border:1px solid #ccd0d4;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Manuell sync', 'awana-commerce' ); ?></h3>
			<form method="post" action="">
				<?php wp_nonce_field( 'awana_manual_sync', 'awana_manual_sync_nonce' ); ?>
				<input type="number" name="order_id" min="1" placeholder="<?php esc_attr_e( 'Ordre-ID', 'awana-commerce' ); ?>" required style="width:160px;" />
				<?php submit_button( __( 'Sync nå', 'awana-commerce' ), 'primary', 'awana_manual_sync', false ); ?>
				<span style="color:#50575e;font-size:12px;margin-left:8px;">
					<?php esc_html_e( 'Triggrer sync_all_order_metadata_to_crm() på en gitt ordre.', 'awana-commerce' ); ?>
				</span>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Mislykkede syncs
	// -------------------------------------------------------------------------

	private function render_mislykkede_tab() {
		$search       = isset( $_GET['awana_search'] ) ? sanitize_text_field( $_GET['awana_search'] ) : '';
		$failed_syncs = $this->get_failed_syncs( $search );
		?>
		<p style="color:#50575e;margin-top:12px;">
			<?php esc_html_e( 'Ordrer hvor crm_sync_woo = "failed". Klikk Retry for å forsøke på nytt.', 'awana-commerce' ); ?>
		</p>

		<?php if ( empty( $failed_syncs ) ) : ?>
			<div class="notice notice-success inline" style="margin:20px 0;">
				<p><?php esc_html_e( '✓ Ingen mislykkede syncs.', 'awana-commerce' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ordre #', 'awana-commerce' ); ?></th>
						<th><?php esc_html_e( 'CRM Invoice ID', 'awana-commerce' ); ?></th>
						<th><?php esc_html_e( 'Siste feil', 'awana-commerce' ); ?></th>
						<th><?php esc_html_e( 'Sist forsøkt', 'awana-commerce' ); ?></th>
						<th><?php esc_html_e( 'Antall feil', 'awana-commerce' ); ?></th>
						<th><?php esc_html_e( 'Handling', 'awana-commerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $failed_syncs as $order ) : ?>
						<tr>
							<td><?php echo esc_html( $order['order_number'] ); ?></td>
							<td>
								<?php if ( ! empty( $order['invoice_id'] ) && '—' !== $order['invoice_id'] ) : ?>
									<a href="<?php echo esc_url( $this->get_firebase_url( $order['invoice_id'] ) ); ?>" target="_blank">
										<?php echo esc_html( $order['invoice_id'] ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $order['invoice_id'] ); ?>
								<?php endif; ?>
							</td>
							<td><span title="<?php echo esc_attr( $order['error'] ); ?>"><?php echo esc_html( $this->format_sync_error( $order['error'] ) ); ?></span></td>
							<td><?php echo esc_html( $order['last_attempt'] ); ?></td>
							<td><?php echo esc_html( $order['error_count'] ); ?></td>
							<td>
								<form method="post" action="" style="display:inline;">
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
		<?php
	}

	// -------------------------------------------------------------------------
	// Tab: Helse — delegated to Awana_Health_Check
	// -------------------------------------------------------------------------

	private function render_helse_tab() {
		if ( ! class_exists( 'Awana_Health_Check' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Awana_Health_Check class not loaded.', 'awana-commerce' ) . '</p></div>';
			return;
		}
		Awana_Health_Check::render_health_tab();
	}

	// =========================================================================
	// AJAX handlers
	// =========================================================================

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
		$result['success'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
	}

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
		$result['success'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
	}

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
		$result['success'] ? wp_send_json_success( array( 'message' => $result['message'] ) ) : wp_send_json_error( array( 'message' => $result['message'] ) );
	}

	/**
	 * AJAX: Re-fire Firebase createCheckoutInvoice for a B2B order.
	 *
	 * Clears the synced flag so notify_checkout_invoice_to_crm() runs again.
	 * Migrated from Awana_B2B_Sync_Status (consolidated in v1.4.0).
	 */
	public function handle_retry_checkout_sync_ajax() {
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

		Awana_Logger::info( 'B2B sync retry triggered from admin', array( 'order_id' => $order_id ) );

		$order->delete_meta_data( '_awana_checkout_invoice_synced' );
		$order->save();

		$result = Awana_CRM_Webhook::notify_checkout_invoice_to_crm( $order );
		if ( is_wp_error( $result ) ) {
			Awana_Logger::error( 'B2B sync retry failed', array( 'order_id' => $order_id, 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		} elseif ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Sync returned false — check order meta and webhook config.', 'awana-commerce' ) ) );
		} else {
			wp_send_json_success( array( 'message' => __( 'Synced successfully.', 'awana-commerce' ) ) );
		}
	}

	// =========================================================================
	// Data layer — direct SQL on wp_wc_orders for fast pagination
	// =========================================================================

	/**
	 * Paginated orders query. Direct SQL on the HPOS orders table so we don't
	 * load all order objects for every page (legacy did load-then-paginate which
	 * was O(N) per page-view).
	 *
	 * Returns: ['items' => [...rows], 'total' => int, 'total_pages' => int]
	 */
	private function get_orders_page( $filter, $paged, $search = '' ) {
		global $wpdb;

		// Restrict to real orders (not refunds, which share the wc_orders table).
		$where  = array( "o.type = 'shop_order'" );
		$joins  = array();
		$params = array();

		// Status exclusion. Default = active orders only. The 'cancelled' filter
		// inverts this to surface only cancelled/failed.
		if ( 'cancelled' === $filter ) {
			$where[] = "o.status IN ('wc-cancelled', 'wc-failed')";
		} else {
			$inactive_list = "'" . implode( "','", self::INACTIVE_STATUSES ) . "'";
			$where[]       = "o.status NOT IN ({$inactive_list})";
		}

		// Search across order ID, billing email, billing first/last name,
		// company, organization meta, and CRM invoice ID. Numeric search also
		// matches order ID exactly (so "94247" finds order 94247 even though
		// LIKE-ing it across other fields wouldn't).
		if ( ! empty( $search ) ) {
			$joins['inv_search']  = "LEFT JOIN {$wpdb->prefix}wc_orders_meta inv_search ON o.id = inv_search.order_id AND inv_search.meta_key = 'crm_invoice_id'";
			$joins['org_search']  = "LEFT JOIN {$wpdb->prefix}wc_orders_meta org_search ON o.id = org_search.order_id AND org_search.meta_key = '_awana_selected_org_title'";
			$joins['addr_search'] = "LEFT JOIN {$wpdb->prefix}wc_order_addresses addr_search ON o.id = addr_search.order_id AND addr_search.address_type = 'billing'";

			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(o.id = %d OR o.billing_email LIKE %s OR addr_search.first_name LIKE %s OR addr_search.last_name LIKE %s OR addr_search.company LIKE %s OR org_search.meta_value LIKE %s OR inv_search.meta_value = %s)';
			$params[] = absint( $search );
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $search;
		}

		// Filter joins.
		switch ( $filter ) {
			case 'b2b':
				$joins['pt'] = "JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type' AND pt.meta_value = 'organization'";
				break;
			case 'invoice':
				$joins['pt']  = "LEFT JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type'";
				$joins['inv'] = "JOIN {$wpdb->prefix}wc_orders_meta inv ON o.id = inv.order_id AND inv.meta_key = 'crm_invoice_id'";
				$where[]      = "(pt.meta_value IS NULL OR pt.meta_value <> 'organization')";
				break;
			case 'b2c':
				$joins['pt']  = "LEFT JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type'";
				$joins['inv'] = "LEFT JOIN {$wpdb->prefix}wc_orders_meta inv ON o.id = inv.order_id AND inv.meta_key = 'crm_invoice_id'";
				$where[]      = "(pt.meta_value IS NULL OR pt.meta_value <> 'organization')";
				$where[]      = '(inv.meta_value IS NULL OR inv.meta_value = \'\')';
				break;
			case 'missing_crm':
				$joins['inv'] = "LEFT JOIN {$wpdb->prefix}wc_orders_meta inv ON o.id = inv.order_id AND inv.meta_key = 'crm_invoice_id'";
				$joins['pt']  = "JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type' AND pt.meta_value = 'organization'";
				$where[]      = '(inv.meta_value IS NULL OR inv.meta_value = \'\')';
				break;
			case 'errors':
				$joins['err'] = "JOIN {$wpdb->prefix}wc_orders_meta err ON o.id = err.order_id AND err.meta_key = '_awana_sync_last_error' AND err.meta_value <> ''";
				break;
		}

		$join_sql  = implode( "\n", $joins );
		$where_sql = implode( ' AND ', $where );

		// Count.
		$count_sql = "SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o {$join_sql} WHERE {$where_sql}";
		$total     = empty( $params )
			? (int) $wpdb->get_var( $count_sql )
			: (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;

		// Page query — IDs only, then hydrate via wc_get_order for the visible page.
		$id_sql = "SELECT DISTINCT o.id FROM {$wpdb->prefix}wc_orders o {$join_sql} WHERE {$where_sql} ORDER BY o.id DESC LIMIT %d OFFSET %d";
		$id_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$ids       = $wpdb->get_col( $wpdb->prepare( $id_sql, $id_params ) );

		$items = array();
		foreach ( $ids as $order_id ) {
			$row = $this->build_order_row( (int) $order_id );
			if ( null !== $row ) {
				$items[] = $row;
			}
		}

		return array(
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * Counts for the 4 stat cards + filter chips.
	 *
	 * Cheap aggregate queries — no order hydration.
	 */
	private function get_orders_summary() {
		global $wpdb;

		// All "active" count queries below apply the same filter set as
		// get_orders_page() default view:
		//   - type='shop_order' excludes refunds (share wc_orders table)
		//   - status NOT IN INACTIVE_STATUSES hides trash, auto-draft, cancelled, failed
		// Keeping the filters in lock-step is what makes "Alle (N)" on the cards
		// match the count shown in the table header.
		$inactive_list  = "'" . implode( "','", self::INACTIVE_STATUSES ) . "'";
		$status_filter  = "o.type = 'shop_order' AND o.status NOT IN ({$inactive_list})";

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o WHERE {$status_filter}"
		);

		$b2b = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type' AND pt.meta_value = 'organization'
			 WHERE {$status_filter}"
		);

		$invoice_total = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta inv ON o.id = inv.order_id AND inv.meta_key = 'crm_invoice_id'
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type'
			 WHERE {$status_filter}
			   AND (pt.meta_value IS NULL OR pt.meta_value <> 'organization')"
		);

		$b2c = max( 0, $total - $b2b - $invoice_total );

		$synced = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta sm ON o.id = sm.order_id AND sm.meta_key = 'crm_sync_woo' AND sm.meta_value = 'success'
			 WHERE {$status_filter}"
		);
		$pending = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta pt ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type' AND pt.meta_value = 'organization'
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta inv ON o.id = inv.order_id AND inv.meta_key = 'crm_invoice_id'
			 WHERE {$status_filter}
			   AND (inv.meta_value IS NULL OR inv.meta_value = '')"
		);
		$failed = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT o.id) FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta em ON o.id = em.order_id AND em.meta_key = '_awana_sync_last_error' AND em.meta_value <> ''
			 WHERE {$status_filter}"
		);

		// Cancelled/failed (the inverse of the active status_filter, scoped to
		// shop_order). Used by the "Kansellerte" filter chip.
		$cancelled = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders o
			 WHERE o.type = 'shop_order' AND o.status IN ('wc-cancelled', 'wc-failed')"
		);

		return array(
			'total'     => $total,
			'b2b'       => $b2b,
			'b2c'       => $b2c,
			'invoice'   => $invoice_total,
			'synced'    => $synced,
			'pending'   => $pending,
			'failed'    => $failed,
			'cancelled' => $cancelled,
		);
	}

	/**
	 * Hydrate a single order row from its ID (called only for the visible page).
	 */
	private function build_order_row( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			// Guard against refund objects (WC_Order_Refund) — they live in the
			// same wc_orders table and lack get_billing_*() methods.
			return null;
		}

		$payment_type   = $order->get_meta( '_awana_payment_type', true );
		$crm_invoice_id = $order->get_meta( 'crm_invoice_id', true );
		$last_error     = $order->get_meta( '_awana_sync_last_error', true );

		// Type detection.
		if ( 'organization' === $payment_type ) {
			$type = 'b2b';
		} elseif ( ! empty( $crm_invoice_id ) ) {
			$type = 'invoice';
		} else {
			$type = 'b2c';
		}

		// Customer/org label.
		$organization = $order->get_meta( '_awana_selected_org_title', true );
		if ( ! empty( $organization ) ) {
			$customer = $organization;
		} else {
			$first    = $order->get_billing_first_name();
			$last     = $order->get_billing_last_name();
			$email    = $order->get_billing_email();
			$customer = trim( $first . ' ' . $last );
			if ( empty( $customer ) ) {
				$customer = $email ? $email : __( '(uten navn)', 'awana-commerce' );
			}
		}

		$order_date = $order->get_date_created();

		return array(
			'order_id'           => $order_id,
			'order_number'       => $order->get_order_number(),
			'edit_url'           => $order->get_edit_order_url(),
			'date'               => $order_date ? $order_date->date_i18n( get_option( 'date_format' ) ) : '',
			'customer'           => $customer,
			'total'              => $order->get_formatted_order_total(),
			'type'               => $type,
			'crm_invoice_id'     => $crm_invoice_id,
			'crm_sync_woo'       => $order->get_meta( 'crm_sync_woo', true ),
			'crm_source'         => $order->get_meta( 'crm_source', true ),
			'pog_status'         => $order->get_meta( 'pog_status', true ),
			'pog_invoice_number' => $order->get_meta( 'pog_invoice_number', true ),
			'pog_order_number'   => $order->get_meta( 'pog_order_number', true ),
			'pog_kid'            => $order->get_meta( 'pog_kid_number', true ),
			'has_error'          => ! empty( $last_error ),
			'last_error'         => $last_error ? $last_error : '',
		);
	}

	/**
	 * Failed-syncs list for Mislykkede tab.
	 *
	 * @param string $search Search query (order ID or invoice ID).
	 * @return array Failed sync rows.
	 */
	private function get_failed_syncs( $search = '' ) {
		$ids = wc_get_orders( array(
			'limit'      => 100,
			'meta_key'   => 'crm_sync_woo',
			'meta_value' => 'failed',
			'return'     => 'ids',
		) );

		$rows = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$invoice_id = $order->get_meta( 'crm_invoice_id', true );

			if ( ! empty( $search ) ) {
				$num     = (string) $order->get_order_number();
				$matches = ( false !== strpos( (string) $order_id, $search ) )
					|| ( false !== strpos( $num, $search ) )
					|| ( ! empty( $invoice_id ) && false !== strpos( (string) $invoice_id, $search ) );
				if ( ! $matches ) {
					continue;
				}
			}

			$last_error   = $order->get_meta( '_awana_sync_last_error', true );
			$last_attempt = $order->get_meta( '_awana_sync_last_attempt', true );
			$error_count  = $order->get_meta( '_awana_sync_error_count', true );

			$rows[] = array(
				'order_id'     => $order_id,
				'order_number' => $order->get_order_number(),
				'invoice_id'   => $invoice_id ? $invoice_id : '—',
				'error'        => $last_error ? $last_error : __( 'Ukjent feil', 'awana-commerce' ),
				'last_attempt' => $last_attempt ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_attempt ) : __( 'Aldri', 'awana-commerce' ),
				'error_count'  => $error_count ? $error_count : 0,
			);
		}
		return $rows;
	}

	// =========================================================================
	// Display helpers
	// =========================================================================

	private function get_type_badge( $type ) {
		$map = array(
			'b2b'     => array( 'text' => 'B2B', 'style' => 'background:#e3f2fd;color:#1565c0' ),
			'invoice' => array( 'text' => 'INV', 'style' => 'background:#fff3e0;color:#e65100' ),
			'b2c'     => array( 'text' => 'B2C', 'style' => 'background:#f0f0f1;color:#50575e' ),
		);
		return isset( $map[ $type ] ) ? $map[ $type ] : $map['b2c'];
	}

	private function get_sync_display( $sync_val, $type ) {
		// B2C orders aren't synced to CRM (yet) — show neutral, not failed.
		if ( 'b2c' === $type && empty( $sync_val ) ) {
			return array( 'color' => '#a7aaad', 'label' => __( 'N/A', 'awana-commerce' ) );
		}
		$map = array(
			'success' => array( 'color' => '#00a32a', 'label' => __( 'OK', 'awana-commerce' ) ),
			'failed'  => array( 'color' => '#d63638', 'label' => __( 'Failed', 'awana-commerce' ) ),
			'pending' => array( 'color' => '#dba617', 'label' => __( 'Pending', 'awana-commerce' ) ),
		);
		return isset( $map[ $sync_val ] ) ? $map[ $sync_val ] : array( 'color' => '#a7aaad', 'label' => '—' );
	}

	private function get_dot_display( $row ) {
		if ( ! empty( $row['has_error'] ) ) {
			return array( 'color' => '#d63638', 'title' => $row['last_error'] );
		}
		if ( ! empty( $row['crm_invoice_id'] ) ) {
			return array( 'color' => '#00a32a', 'title' => __( 'Synked', 'awana-commerce' ) );
		}
		if ( 'b2c' === $row['type'] ) {
			return array( 'color' => '#a7aaad', 'title' => __( 'B2C — sync ikke konfigurert', 'awana-commerce' ) );
		}
		return array( 'color' => '#dba617', 'title' => __( 'Pending', 'awana-commerce' ) );
	}

	private function format_sync_error( $error ) {
		if ( empty( $error ) ) {
			return __( 'Ingen feilmelding', 'awana-commerce' );
		}
		return strlen( $error ) > 100 ? substr( $error, 0, 100 ) . '…' : $error;
	}

	private function get_firebase_url( $invoice_id ) {
		if ( empty( $invoice_id ) ) {
			return '';
		}
		return 'https://console.firebase.google.com/u/0/project/awana-server/firestore/databases/-default-/data/~2Finvoices~2F' . $invoice_id;
	}
}
