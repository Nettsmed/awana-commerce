<?php
/**
 * Debug page for B2B Org Sync
 *
 * @package Awana_Digital_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug class for org sync validation
 */
class Awana_Debug {

	const AJAX_ACTIONS = array(
		'awana_debug_fetch_firebase',
		'awana_debug_force_sync',
		'awana_debug_clear_cache',
		'awana_debug_compare',
		'awana_debug_test_connection',
		'awana_debug_refresh_log',
		'awana_debug_update_firebase_uid',
	);

	/**
	 * Initialize the debug UI
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_admin_scripts' ) );

		// Register AJAX handlers.
		foreach ( self::AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_' . $action, array( $instance, 'handle_' . str_replace( 'awana_debug_', '', $action ) ) );
		}
	}

	/**
	 * Add admin menu under WooCommerce
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Awana Org Debug', 'awana-digital-sync' ),
			__( 'Awana Org Debug', 'awana-digital-sync' ),
			'manage_woocommerce',
			'awana-org-debug',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin scripts
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_awana-org-debug' !== $hook ) {
			return;
		}

		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', $this->get_inline_script() );
		wp_add_inline_style( 'wp-admin', $this->get_inline_styles() );
	}

	/**
	 * Get inline CSS styles
	 *
	 * @return string CSS code.
	 */
	private function get_inline_styles() {
		return '
			.awana-debug-section {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 15px 20px;
				margin: 15px 0;
			}
			.awana-debug-section h2 {
				margin-top: 0;
				padding-bottom: 10px;
				border-bottom: 1px solid #eee;
			}
			.awana-debug-grid {
				display: grid;
				grid-template-columns: 200px 1fr;
				gap: 8px 15px;
				margin: 10px 0;
			}
			.awana-debug-grid dt {
				font-weight: 600;
				color: #1d2327;
			}
			.awana-debug-grid dd {
				margin: 0;
				word-break: break-word;
			}
			.awana-debug-result {
				background: #f6f7f7;
				border: 1px solid #dcdcde;
				padding: 10px 15px;
				margin: 10px 0;
				max-height: 400px;
				overflow: auto;
				font-family: monospace;
				font-size: 12px;
				white-space: pre-wrap;
			}
			.awana-debug-status {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 500;
			}
			.awana-debug-status.fresh { background: #d4edda; color: #155724; }
			.awana-debug-status.stale { background: #fff3cd; color: #856404; }
			.awana-debug-status.expired { background: #f8d7da; color: #721c24; }
			.awana-debug-status.ok { background: #d4edda; color: #155724; }
			.awana-debug-status.warning { background: #fff3cd; color: #856404; }
			.awana-debug-status.error { background: #f8d7da; color: #721c24; }
			.awana-debug-org-card {
				border: 1px solid #dcdcde;
				padding: 10px 15px;
				margin: 8px 0;
				background: #fafafa;
			}
			.awana-debug-org-card.has-pog { border-left: 4px solid #28a745; }
			.awana-debug-org-card.no-pog { border-left: 4px solid #ffc107; }
			.awana-debug-log-container {
				background: #1d2327;
				color: #c3c4c7;
				padding: 15px;
				font-family: monospace;
				font-size: 11px;
				max-height: 300px;
				overflow: auto;
			}
			.awana-debug-log-line {
				margin: 2px 0;
				padding: 2px 0;
				border-bottom: 1px solid #32373c;
			}
			.awana-debug-diff-added { background: #d4edda; }
			.awana-debug-diff-removed { background: #f8d7da; }
			.awana-debug-diff-changed { background: #fff3cd; }
		';
	}

	/**
	 * Get inline JavaScript
	 *
	 * @return string JavaScript code.
	 */
	private function get_inline_script() {
		ob_start();
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'awana_debug_nonce' );
		?>
		jQuery(document).ready(function($) {
			function doAjax(action, extraData, callback) {
				var data = $.extend({
					action: action,
					nonce: '<?php echo esc_js( $nonce ); ?>'
				}, extraData || {});

				$.ajax({
					url: '<?php echo esc_url( $ajax_url ); ?>',
					type: 'POST',
					data: data,
					success: function(response) {
						if (callback) callback(response);
					},
					error: function(xhr, status, error) {
						if (callback) callback({success: false, data: {message: error}});
					}
				});
			}

			// Fetch from Firebase
			$('#awana-debug-fetch-firebase').on('click', function() {
				var $btn = $(this);
				var $result = $('#awana-debug-firebase-result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Fetching...', 'awana-digital-sync' ) ); ?>');
				$result.html('Loading...').show();

				doAjax('awana_debug_fetch_firebase', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Fetch from Firebase', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						$result.html(response.data.html).show();
					} else {
						$result.html('<span style="color:red;">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</span>').show();
					}
				});
			});

			// Force Sync
			$('#awana-debug-force-sync').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Syncing...', 'awana-digital-sync' ) ); ?>');

				doAjax('awana_debug_force_sync', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Force Sync', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message);
					}
				});
			});

			// Clear Cache
			$('#awana-debug-clear-cache').on('click', function() {
				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Clearing...', 'awana-digital-sync' ) ); ?>');

				doAjax('awana_debug_clear_cache', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Clear Cache', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message);
					}
				});
			});

			// Compare/Diff
			$('#awana-debug-compare').on('click', function() {
				var $btn = $(this);
				var $result = $('#awana-debug-compare-result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Comparing...', 'awana-digital-sync' ) ); ?>');
				$result.html('Loading...').show();

				doAjax('awana_debug_compare', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Compare Firebase vs Cache', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						$result.html(response.data.html).show();
					} else {
						$result.html('<span style="color:red;">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</span>').show();
					}
				});
			});

			// Test Connection
			$('#awana-debug-test-connection').on('click', function() {
				var $btn = $(this);
				var $result = $('#awana-debug-connection-result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Testing...', 'awana-digital-sync' ) ); ?>');
				$result.html('Loading...').show();

				doAjax('awana_debug_test_connection', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Test Firebase Connection', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						$result.html(response.data.html).show();
					} else {
						$result.html('<span style="color:red;">Error: ' + (response.data ? response.data.message : 'Unknown error') + '</span>').show();
					}
				});
			});

			// Refresh Log
			$('#awana-debug-refresh-log').on('click', function() {
				var $btn = $(this);
				var $result = $('#awana-debug-log-result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Refreshing...', 'awana-digital-sync' ) ); ?>');

				doAjax('awana_debug_refresh_log', {}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Refresh Log', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						$result.html(response.data.html);
					} else {
						$result.html('<span style="color:red;">' + response.data.message + '</span>');
					}
				});
			});

			// Checkout simulation dropdown
			$('#awana-debug-org-select').on('change', function() {
				var orgData = $(this).val();
				var $result = $('#awana-debug-checkout-result');

				if (!orgData) {
					$result.html('').hide();
					return;
				}

				try {
					var org = JSON.parse(orgData);
					var html = '<strong>_awana_selected_org order-meta:</strong><pre>' + JSON.stringify(org, null, 2) + '</pre>';

					if (!org.pogCustomerNumber) {
						html += '<p style="color: #856404;">⚠️ Warning: pogCustomerNumber is empty</p>';
					} else {
						html += '<p style="color: #155724;">✅ pogCustomerNumber: ' + org.pogCustomerNumber + '</p>';
					}

					$result.html(html).show();
				} catch(e) {
					$result.html('<span style="color:red;">Error parsing org data</span>').show();
				}
			});

			// Write-back simulation
			$('#awana-debug-writeback-simulate').on('click', function() {
				var orgData = $('#awana-debug-writeback-org-select').val();
				var newPog = $('#awana-debug-new-pog').val();
				var $result = $('#awana-debug-writeback-result');

				if (!orgData) {
					$result.html('<span style="color:red;">Please select an organization</span>').show();
					return;
				}

				try {
					var org = JSON.parse(orgData);
					var payload = {
						memberId: org.id || org.memberId || 'unknown',
						organizationId: org.organizationId || 'unknown',
						pogCustomerNumber: newPog || '(empty)'
					};

					var html = '<strong>Dry run - would send to updateMemberPogCustomerNumber:</strong>';
					html += '<pre>' + JSON.stringify(payload, null, 2) + '</pre>';
					html += '<p><em>No actual request was made.</em></p>';

					$result.html(html).show();
				} catch(e) {
					$result.html('<span style="color:red;">Error parsing org data</span>').show();
				}
			});

			// Update Firebase UID
			$('#awana-debug-update-uid').on('click', function() {
				var $btn = $(this);
				var newUid = $('#awana-debug-firebase-uid-input').val().trim();

				if (!newUid) {
					alert('<?php echo esc_js( __( 'Please enter a Firebase UID', 'awana-digital-sync' ) ); ?>');
					return;
				}

				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to update the Firebase UID?', 'awana-digital-sync' ) ); ?>')) {
					return;
				}

				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Updating...', 'awana-digital-sync' ) ); ?>');

				doAjax('awana_debug_update_firebase_uid', {firebase_uid: newUid}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Update Firebase UID', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message);
					}
				});
			});

			// Clear Firebase UID
			$('#awana-debug-clear-uid').on('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to clear the Firebase UID?', 'awana-digital-sync' ) ); ?>')) {
					return;
				}

				var $btn = $(this);
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Clearing...', 'awana-digital-sync' ) ); ?>');

				doAjax('awana_debug_update_firebase_uid', {firebase_uid: ''}, function(response) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Clear UID', 'awana-digital-sync' ) ); ?>');
					if (response.success) {
						location.reload();
					} else {
						alert(response.data.message);
					}
				});
			});
		});
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the admin page
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'awana-digital-sync' ) );
		}

		$user_id      = get_current_user_id();
		$user         = wp_get_current_user();
		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );
		$last_sync    = get_user_meta( $user_id, '_awana_orgs_last_sync', true );
		$orgs_raw     = get_user_meta( $user_id, '_awana_organizations', true );
		$orgs         = $orgs_raw ? json_decode( $orgs_raw, true ) : null;

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Awana Org Debug', 'awana-digital-sync' ); ?></h1>
			<p><?php echo esc_html__( 'Debug page for validating B2B org sync from Firebase to user-meta to checkout.', 'awana-digital-sync' ); ?></p>

			<!-- Section 1: Brukerinfo -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Brukerinfo', 'awana-digital-sync' ); ?></h2>
				<dl class="awana-debug-grid">
					<dt><?php echo esc_html__( 'User ID', 'awana-digital-sync' ); ?></dt>
					<dd><?php echo esc_html( $user_id ); ?></dd>

					<dt><?php echo esc_html__( 'Email', 'awana-digital-sync' ); ?></dt>
					<dd><?php echo esc_html( $user->user_email ); ?></dd>

					<dt><?php echo esc_html__( 'Firebase UID', 'awana-digital-sync' ); ?></dt>
					<dd>
						<?php if ( $firebase_uid ) : ?>
							<code><?php echo esc_html( $firebase_uid ); ?></code>
						<?php else : ?>
							<span class="awana-debug-status error"><?php echo esc_html__( 'Not set', 'awana-digital-sync' ); ?></span>
						<?php endif; ?>
					</dd>

					<dt><?php echo esc_html__( 'Last Sync', 'awana-digital-sync' ); ?></dt>
					<dd>
						<?php if ( $last_sync ) : ?>
							<?php
							$last_sync_time = intval( $last_sync );
							$human_time     = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_sync_time );
							$relative       = human_time_diff( $last_sync_time, time() ) . ' ' . __( 'ago', 'awana-digital-sync' );
							?>
							<?php echo esc_html( $human_time ); ?> (<?php echo esc_html( $relative ); ?>)
						<?php else : ?>
							<span class="awana-debug-status warning"><?php echo esc_html__( 'Never synced', 'awana-digital-sync' ); ?></span>
						<?php endif; ?>
					</dd>

					<dt><?php echo esc_html__( 'TTL Status', 'awana-digital-sync' ); ?></dt>
					<dd>
						<?php
						$should_sync = Awana_Org_Sync::should_sync( $user_id );
						$ttl_hours   = Awana_Org_Sync::TTL_SECONDS / 3600;
						if ( $should_sync ) :
							?>
							<span class="awana-debug-status expired"><?php echo esc_html__( 'Expired - will sync on next page load', 'awana-digital-sync' ); ?></span>
						<?php else : ?>
							<?php
							$time_remaining = ( $last_sync + Awana_Org_Sync::TTL_SECONDS ) - time();
							$hours_left     = round( $time_remaining / 3600, 1 );
							?>
							<span class="awana-debug-status fresh"><?php echo esc_html( sprintf( __( 'Fresh - %s hours remaining', 'awana-digital-sync' ), $hours_left ) ); ?></span>
						<?php endif; ?>
						<br><small><?php echo esc_html( sprintf( __( 'TTL: %d seconds (%d hours)', 'awana-digital-sync' ), Awana_Org_Sync::TTL_SECONDS, $ttl_hours ) ); ?></small>
					</dd>
				</dl>
			</div>

			<!-- Section: Update Firebase UID -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Update Firebase UID', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Change the Firebase UID for testing purposes.', 'awana-digital-sync' ); ?></p>
				<p>
					<input type="text" id="awana-debug-firebase-uid-input" class="regular-text"
						value="<?php echo esc_attr( $firebase_uid ); ?>"
						placeholder="<?php echo esc_attr__( 'Enter Firebase UID', 'awana-digital-sync' ); ?>"
						style="width: 350px;" />
				</p>
				<p>
					<button id="awana-debug-update-uid" class="button button-primary">
						<?php echo esc_html__( 'Update Firebase UID', 'awana-digital-sync' ); ?>
					</button>
					<button id="awana-debug-clear-uid" class="button" <?php echo $firebase_uid ? '' : 'disabled'; ?>>
						<?php echo esc_html__( 'Clear UID', 'awana-digital-sync' ); ?>
					</button>
				</p>
				<p><small><?php echo esc_html__( 'Warning: Changing the UID will affect org sync for the current user.', 'awana-digital-sync' ); ?></small></p>
			</div>

			<!-- Section 2: Fetch from Firebase -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Fetch from Firebase', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Fetch organizations directly from Firebase API without updating cache.', 'awana-digital-sync' ); ?></p>
				<button id="awana-debug-fetch-firebase" class="button button-primary" <?php echo $firebase_uid ? '' : 'disabled'; ?>>
					<?php echo esc_html__( 'Fetch from Firebase', 'awana-digital-sync' ); ?>
				</button>
				<?php if ( ! $firebase_uid ) : ?>
					<span style="color: #dc3232; margin-left: 10px;"><?php echo esc_html__( 'Firebase UID not set', 'awana-digital-sync' ); ?></span>
				<?php endif; ?>
				<div id="awana-debug-firebase-result" class="awana-debug-result" style="display: none;"></div>
			</div>

			<!-- Section 3: Lagret org-data -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Lagret org-data', 'awana-digital-sync' ); ?></h2>
				<p>
					<button id="awana-debug-force-sync" class="button" <?php echo $firebase_uid ? '' : 'disabled'; ?>>
						<?php echo esc_html__( 'Force Sync', 'awana-digital-sync' ); ?>
					</button>
					<button id="awana-debug-clear-cache" class="button">
						<?php echo esc_html__( 'Clear Cache', 'awana-digital-sync' ); ?>
					</button>
				</p>
				<?php if ( $orgs ) : ?>
					<?php $this->render_organizations( $orgs ); ?>
				<?php else : ?>
					<p class="awana-debug-status warning"><?php echo esc_html__( 'No cached organizations', 'awana-digital-sync' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Section 4: Diff Firebase vs Cache -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Diff Firebase vs Cache', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Compare fresh Firebase data with cached data to identify differences.', 'awana-digital-sync' ); ?></p>
				<button id="awana-debug-compare" class="button" <?php echo $firebase_uid ? '' : 'disabled'; ?>>
					<?php echo esc_html__( 'Compare Firebase vs Cache', 'awana-digital-sync' ); ?>
				</button>
				<div id="awana-debug-compare-result" class="awana-debug-result" style="display: none;"></div>
			</div>

			<!-- Section 5: Simuler checkout org-valg -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Simuler checkout org-valg', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Select an organization to see what would be saved as order meta.', 'awana-digital-sync' ); ?></p>
				<?php if ( $orgs && is_array( $orgs ) ) : ?>
					<?php $org_list = isset( $orgs['organizations'] ) ? $orgs['organizations'] : ( isset( $orgs[0] ) ? $orgs : array() ); ?>
					<select id="awana-debug-org-select" style="min-width: 300px;">
						<option value=""><?php echo esc_html__( '-- Select organization --', 'awana-digital-sync' ); ?></option>
						<?php foreach ( $org_list as $org ) : ?>
							<?php
							$org_name = isset( $org['name'] ) ? $org['name'] : ( isset( $org['organizationName'] ) ? $org['organizationName'] : __( 'Unknown', 'awana-digital-sync' ) );
							$pog      = isset( $org['pogCustomerNumber'] ) ? $org['pogCustomerNumber'] : '';
							$label    = $org_name . ( $pog ? ' (POG: ' . $pog . ')' : ' (no POG)' );
							?>
							<option value="<?php echo esc_attr( wp_json_encode( $org ) ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<div id="awana-debug-checkout-result" class="awana-debug-result" style="display: none;"></div>
				<?php else : ?>
					<p class="awana-debug-status warning"><?php echo esc_html__( 'No cached organizations available', 'awana-digital-sync' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Section 6: Write-back test (dry run) -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Write-back test (dry run)', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Simulate what would be sent to updateMemberPogCustomerNumber. No actual request is made.', 'awana-digital-sync' ); ?></p>
				<?php if ( $orgs && is_array( $orgs ) ) : ?>
					<?php $org_list = isset( $orgs['organizations'] ) ? $orgs['organizations'] : ( isset( $orgs[0] ) ? $orgs : array() ); ?>
					<p>
						<label for="awana-debug-writeback-org-select"><?php echo esc_html__( 'Organization:', 'awana-digital-sync' ); ?></label>
						<select id="awana-debug-writeback-org-select" style="min-width: 300px;">
							<option value=""><?php echo esc_html__( '-- Select organization --', 'awana-digital-sync' ); ?></option>
							<?php foreach ( $org_list as $org ) : ?>
								<?php $org_name = isset( $org['name'] ) ? $org['name'] : ( isset( $org['organizationName'] ) ? $org['organizationName'] : __( 'Unknown', 'awana-digital-sync' ) ); ?>
								<option value="<?php echo esc_attr( wp_json_encode( $org ) ); ?>"><?php echo esc_html( $org_name ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="awana-debug-new-pog"><?php echo esc_html__( 'New POG number:', 'awana-digital-sync' ); ?></label>
						<input type="text" id="awana-debug-new-pog" class="regular-text" placeholder="e.g., 12345" />
					</p>
					<button id="awana-debug-writeback-simulate" class="button">
						<?php echo esc_html__( 'Simulate Write-back', 'awana-digital-sync' ); ?>
					</button>
					<div id="awana-debug-writeback-result" class="awana-debug-result" style="display: none;"></div>
				<?php else : ?>
					<p class="awana-debug-status warning"><?php echo esc_html__( 'No cached organizations available', 'awana-digital-sync' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Section 7: Test Firebase-tilkobling -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Test Firebase-tilkobling', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Check configuration constants and test Firebase API connectivity.', 'awana-digital-sync' ); ?></p>
				<button id="awana-debug-test-connection" class="button">
					<?php echo esc_html__( 'Test Firebase Connection', 'awana-digital-sync' ); ?>
				</button>
				<div id="awana-debug-connection-result" class="awana-debug-result" style="display: none;"></div>
			</div>

			<!-- Section 8: Logg-visning -->
			<div class="awana-debug-section">
				<h2><?php echo esc_html__( 'Logg-visning', 'awana-digital-sync' ); ?></h2>
				<p><?php echo esc_html__( 'Recent log entries related to org sync (last 30 lines).', 'awana-digital-sync' ); ?></p>
				<button id="awana-debug-refresh-log" class="button">
					<?php echo esc_html__( 'Refresh Log', 'awana-digital-sync' ); ?>
				</button>
				<div id="awana-debug-log-result">
					<?php echo $this->get_log_html(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render organizations list
	 *
	 * @param array $orgs Organizations data.
	 */
	private function render_organizations( $orgs ) {
		$org_list = isset( $orgs['organizations'] ) ? $orgs['organizations'] : ( isset( $orgs[0] ) ? $orgs : array( $orgs ) );

		if ( empty( $org_list ) ) {
			echo '<p class="awana-debug-status warning">' . esc_html__( 'No organizations in cache', 'awana-digital-sync' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html( sprintf( __( 'Found %d organization(s)', 'awana-digital-sync' ), count( $org_list ) ) ) . '</p>';

		foreach ( $org_list as $org ) {
			$has_pog  = ! empty( $org['pogCustomerNumber'] );
			$org_name = isset( $org['name'] ) ? $org['name'] : ( isset( $org['organizationName'] ) ? $org['organizationName'] : __( 'Unknown', 'awana-digital-sync' ) );
			?>
			<div class="awana-debug-org-card <?php echo $has_pog ? 'has-pog' : 'no-pog'; ?>">
				<strong><?php echo esc_html( $org_name ); ?></strong>
				<?php echo $has_pog ? '✅' : '⚠️'; ?>
				<dl class="awana-debug-grid">
					<?php foreach ( $org as $key => $value ) : ?>
						<dt><?php echo esc_html( $key ); ?></dt>
						<dd>
							<?php
							if ( is_array( $value ) || is_object( $value ) ) {
								echo '<pre style="margin:0;font-size:11px;">' . esc_html( wp_json_encode( $value, JSON_PRETTY_PRINT ) ) . '</pre>';
							} else {
								echo esc_html( $value );
							}
							?>
						</dd>
					<?php endforeach; ?>
				</dl>
			</div>
			<?php
		}
	}

	/**
	 * Get log HTML
	 *
	 * @return string HTML for log display.
	 */
	private function get_log_html() {
		$log_dir   = WP_CONTENT_DIR . '/wc-logs/';
		$log_files = glob( $log_dir . 'awana_digital-*.log' );

		if ( empty( $log_files ) ) {
			return '<p class="awana-debug-status warning">' . esc_html__( 'No log files found', 'awana-digital-sync' ) . '</p>';
		}

		// Get the most recent log file.
		usort(
			$log_files,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		$log_file = $log_files[0];
		$content  = file_get_contents( $log_file );

		if ( empty( $content ) ) {
			return '<p class="awana-debug-status warning">' . esc_html__( 'Log file is empty', 'awana-digital-sync' ) . '</p>';
		}

		// Split into lines and filter for relevant entries.
		$lines          = explode( "\n", $content );
		$keywords       = array( 'org', 'sync', 'pog', 'member', 'organization', 'firebase' );
		$filtered_lines = array();

		foreach ( $lines as $line ) {
			$line_lower = strtolower( $line );
			foreach ( $keywords as $keyword ) {
				if ( strpos( $line_lower, $keyword ) !== false ) {
					$filtered_lines[] = $line;
					break;
				}
			}
		}

		// Take last 30 lines, newest first.
		$filtered_lines = array_slice( array_reverse( $filtered_lines ), 0, 30 );

		if ( empty( $filtered_lines ) ) {
			return '<p class="awana-debug-status warning">' . esc_html__( 'No matching log entries found', 'awana-digital-sync' ) . '</p>';
		}

		$html = '<div class="awana-debug-log-container">';
		foreach ( $filtered_lines as $line ) {
			$html .= '<div class="awana-debug-log-line">' . esc_html( $line ) . '</div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Handle fetch_firebase AJAX request
	 */
	public function handle_fetch_firebase() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id      = get_current_user_id();
		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );

		if ( empty( $firebase_uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase UID not set for current user.', 'awana-digital-sync' ) ) );
		}

		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || ! defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase constants not configured.', 'awana-digital-sync' ) ) );
		}

		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => AWANA_FIREBASE_API_KEY,
			),
			'body'    => wp_json_encode( array( 'uid' => (string) $firebase_uid ) ),
		);

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		ob_start();
		echo '<p><strong>' . esc_html__( 'Status:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $status_code ) . '</p>';

		if ( $decoded ) {
			$org_list = isset( $decoded['organizations'] ) ? $decoded['organizations'] : ( isset( $decoded[0] ) ? $decoded : array() );
			$count    = is_array( $org_list ) ? count( $org_list ) : 0;
			echo '<p><strong>' . esc_html__( 'Organizations found:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $count ) . '</p>';

			if ( $count > 0 ) {
				foreach ( $org_list as $org ) {
					$has_pog  = ! empty( $org['pogCustomerNumber'] );
					$org_name = isset( $org['name'] ) ? $org['name'] : ( isset( $org['organizationName'] ) ? $org['organizationName'] : 'Unknown' );
					echo '<div style="padding:5px;margin:5px 0;background:' . ( $has_pog ? '#d4edda' : '#fff3cd' ) . ';">';
					echo ( $has_pog ? '✅' : '⚠️' ) . ' ' . esc_html( $org_name );
					if ( $has_pog ) {
						echo ' (POG: ' . esc_html( $org['pogCustomerNumber'] ) . ')';
					}
					echo '</div>';
				}
			}

			echo '<details><summary>' . esc_html__( 'Raw JSON', 'awana-digital-sync' ) . '</summary>';
			echo '<pre>' . esc_html( wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
			echo '</details>';
		} else {
			echo '<pre>' . esc_html( $body ) . '</pre>';
		}

		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Handle force_sync AJAX request
	 */
	public function handle_force_sync() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id      = get_current_user_id();
		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );

		if ( empty( $firebase_uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase UID not set for current user.', 'awana-digital-sync' ) ) );
		}

		$result = Awana_Org_Sync::sync_organizations( $user_id, $firebase_uid );

		if ( $result ) {
			wp_send_json_success( array( 'message' => __( 'Sync completed successfully.', 'awana-digital-sync' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Sync failed. Check logs for details.', 'awana-digital-sync' ) ) );
		}
	}

	/**
	 * Handle clear_cache AJAX request
	 */
	public function handle_clear_cache() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, '_awana_organizations' );
		delete_user_meta( $user_id, '_awana_orgs_last_sync' );

		wp_send_json_success( array( 'message' => __( 'Cache cleared.', 'awana-digital-sync' ) ) );
	}

	/**
	 * Handle compare AJAX request
	 */
	public function handle_compare() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id      = get_current_user_id();
		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );

		if ( empty( $firebase_uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase UID not set for current user.', 'awana-digital-sync' ) ) );
		}

		// Get cached data.
		$cached_raw = get_user_meta( $user_id, '_awana_organizations', true );
		$cached     = $cached_raw ? json_decode( $cached_raw, true ) : null;

		// Fetch fresh from Firebase.
		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || ! defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase constants not configured.', 'awana-digital-sync' ) ) );
		}

		$args = array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => AWANA_FIREBASE_API_KEY,
			),
			'body'    => wp_json_encode( array( 'uid' => (string) $firebase_uid ) ),
		);

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $args );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$body   = wp_remote_retrieve_body( $response );
		$fresh  = json_decode( $body, true );

		ob_start();

		if ( ! $cached && ! $fresh ) {
			echo '<p>' . esc_html__( 'No data available for comparison.', 'awana-digital-sync' ) . '</p>';
		} elseif ( ! $cached ) {
			echo '<p class="awana-debug-diff-added">' . esc_html__( 'Cache is empty. Firebase has data.', 'awana-digital-sync' ) . '</p>';
		} elseif ( ! $fresh ) {
			echo '<p class="awana-debug-diff-removed">' . esc_html__( 'Firebase returned no data. Cache has data.', 'awana-digital-sync' ) . '</p>';
		} else {
			$cached_json = wp_json_encode( $cached, JSON_PRETTY_PRINT );
			$fresh_json  = wp_json_encode( $fresh, JSON_PRETTY_PRINT );

			if ( $cached_json === $fresh_json ) {
				echo '<p style="color: #155724;">✅ ' . esc_html__( 'No differences - cache matches Firebase.', 'awana-digital-sync' ) . '</p>';
			} else {
				echo '<p style="color: #856404;">⚠️ ' . esc_html__( 'Differences found:', 'awana-digital-sync' ) . '</p>';

				// Compare org by org.
				$cached_orgs = isset( $cached['organizations'] ) ? $cached['organizations'] : ( isset( $cached[0] ) ? $cached : array() );
				$fresh_orgs  = isset( $fresh['organizations'] ) ? $fresh['organizations'] : ( isset( $fresh[0] ) ? $fresh : array() );

				// Index by org id.
				$cached_by_id = array();
				$fresh_by_id  = array();

				foreach ( $cached_orgs as $org ) {
					$id                  = isset( $org['id'] ) ? $org['id'] : ( isset( $org['organizationId'] ) ? $org['organizationId'] : null );
					if ( $id ) {
						$cached_by_id[ $id ] = $org;
					}
				}
				foreach ( $fresh_orgs as $org ) {
					$id                 = isset( $org['id'] ) ? $org['id'] : ( isset( $org['organizationId'] ) ? $org['organizationId'] : null );
					if ( $id ) {
						$fresh_by_id[ $id ] = $org;
					}
				}

				$all_ids = array_unique( array_merge( array_keys( $cached_by_id ), array_keys( $fresh_by_id ) ) );

				foreach ( $all_ids as $id ) {
					$in_cache = isset( $cached_by_id[ $id ] );
					$in_fresh = isset( $fresh_by_id[ $id ] );

					if ( $in_cache && ! $in_fresh ) {
						echo '<div class="awana-debug-diff-removed" style="padding:5px;margin:5px 0;">';
						echo '➖ ' . esc_html__( 'Removed from Firebase:', 'awana-digital-sync' ) . ' ' . esc_html( $id );
						echo '</div>';
					} elseif ( ! $in_cache && $in_fresh ) {
						echo '<div class="awana-debug-diff-added" style="padding:5px;margin:5px 0;">';
						echo '➕ ' . esc_html__( 'New in Firebase:', 'awana-digital-sync' ) . ' ' . esc_html( $id );
						echo '</div>';
					} else {
						$cached_org = $cached_by_id[ $id ];
						$fresh_org  = $fresh_by_id[ $id ];
						$diff       = $this->array_diff_recursive( $cached_org, $fresh_org );

						if ( ! empty( $diff ) ) {
							$org_name = isset( $fresh_org['name'] ) ? $fresh_org['name'] : ( isset( $fresh_org['organizationName'] ) ? $fresh_org['organizationName'] : $id );
							echo '<div class="awana-debug-diff-changed" style="padding:5px;margin:5px 0;">';
							echo '🔄 ' . esc_html__( 'Changed:', 'awana-digital-sync' ) . ' ' . esc_html( $org_name );
							echo '<pre style="font-size:11px;margin:5px 0 0;">' . esc_html( wp_json_encode( $diff, JSON_PRETTY_PRINT ) ) . '</pre>';
							echo '</div>';
						}
					}
				}
			}
		}

		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Recursive array diff helper
	 *
	 * @param array $array1 First array.
	 * @param array $array2 Second array.
	 * @return array Differences.
	 */
	private function array_diff_recursive( $array1, $array2 ) {
		$diff = array();

		foreach ( $array1 as $key => $value ) {
			if ( ! array_key_exists( $key, $array2 ) ) {
				$diff[ $key ] = array(
					'cached' => $value,
					'fresh'  => '(missing)',
				);
			} elseif ( is_array( $value ) && is_array( $array2[ $key ] ) ) {
				$nested = $this->array_diff_recursive( $value, $array2[ $key ] );
				if ( ! empty( $nested ) ) {
					$diff[ $key ] = $nested;
				}
			} elseif ( $value !== $array2[ $key ] ) {
				$diff[ $key ] = array(
					'cached' => $value,
					'fresh'  => $array2[ $key ],
				);
			}
		}

		foreach ( $array2 as $key => $value ) {
			if ( ! array_key_exists( $key, $array1 ) ) {
				$diff[ $key ] = array(
					'cached' => '(missing)',
					'fresh'  => $value,
				);
			}
		}

		return $diff;
	}

	/**
	 * Handle test_connection AJAX request
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		ob_start();

		// Check constants.
		$constants = array(
			'AWANA_FIREBASE_GET_ORGS_URL'        => 'Get Orgs URL',
			'AWANA_FIREBASE_API_KEY'             => 'API Key',
			'AWANA_FIREBASE_UPDATE_MEMBER_POG_URL' => 'Update Member POG URL',
			'AWANA_POG_CUSTOMER_WEBHOOK_URL'     => 'POG Customer Webhook URL',
		);

		echo '<h3>' . esc_html__( 'Configuration Constants', 'awana-digital-sync' ) . '</h3>';
		echo '<dl class="awana-debug-grid">';

		foreach ( $constants as $const => $label ) {
			echo '<dt>' . esc_html( $label ) . '</dt>';
			echo '<dd>';
			if ( defined( $const ) && ! empty( constant( $const ) ) ) {
				echo '<span class="awana-debug-status ok">✅ ' . esc_html__( 'Defined', 'awana-digital-sync' ) . '</span>';
			} else {
				echo '<span class="awana-debug-status error">❌ ' . esc_html__( 'Not defined', 'awana-digital-sync' ) . '</span>';
			}
			echo '</dd>';
		}

		echo '</dl>';

		// Test connection with dummy UID.
		if ( defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) && defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			echo '<h3>' . esc_html__( 'Connection Test', 'awana-digital-sync' ) . '</h3>';

			$args = array(
				'method'  => 'POST',
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/json',
					'X-API-Key'    => AWANA_FIREBASE_API_KEY,
				),
				'body'    => wp_json_encode( array( 'uid' => 'test-dummy-uid-debug' ) ),
			);

			$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $args );

			if ( is_wp_error( $response ) ) {
				echo '<p><span class="awana-debug-status error">❌ ' . esc_html( $response->get_error_message() ) . '</span></p>';
			} else {
				$status_code = wp_remote_retrieve_response_code( $response );
				echo '<p><strong>' . esc_html__( 'HTTP Status:', 'awana-digital-sync' ) . '</strong> ';

				if ( $status_code >= 200 && $status_code < 300 ) {
					echo '<span class="awana-debug-status ok">' . esc_html( $status_code ) . '</span>';
				} elseif ( $status_code >= 400 && $status_code < 500 ) {
					echo '<span class="awana-debug-status warning">' . esc_html( $status_code ) . ' (' . esc_html__( 'Expected for invalid UID', 'awana-digital-sync' ) . ')</span>';
				} else {
					echo '<span class="awana-debug-status error">' . esc_html( $status_code ) . '</span>';
				}

				echo '</p>';
				echo '<p><small>' . esc_html__( 'Note: 4xx status is expected when testing with dummy UID.', 'awana-digital-sync' ) . '</small></p>';
			}
		}

		$html = ob_get_clean();
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Handle refresh_log AJAX request
	 */
	public function handle_refresh_log() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		wp_send_json_success( array( 'html' => $this->get_log_html() ) );
	}

	/**
	 * Handle update_firebase_uid AJAX request
	 */
	public function handle_update_firebase_uid() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id      = get_current_user_id();
		$firebase_uid = isset( $_POST['firebase_uid'] ) ? sanitize_text_field( wp_unslash( $_POST['firebase_uid'] ) ) : '';

		if ( empty( $firebase_uid ) ) {
			// Clear the UID.
			delete_user_meta( $user_id, 'mo_firebase_user_uid' );
			// Also clear cached org data since it's no longer valid.
			delete_user_meta( $user_id, '_awana_organizations' );
			delete_user_meta( $user_id, '_awana_orgs_last_sync' );
			wp_send_json_success( array( 'message' => __( 'Firebase UID cleared.', 'awana-digital-sync' ) ) );
		} else {
			update_user_meta( $user_id, 'mo_firebase_user_uid', $firebase_uid );
			// Clear cached org data so it will be re-fetched with new UID.
			delete_user_meta( $user_id, '_awana_organizations' );
			delete_user_meta( $user_id, '_awana_orgs_last_sync' );
			wp_send_json_success( array( 'message' => __( 'Firebase UID updated.', 'awana-digital-sync' ) ) );
		}
	}
}
