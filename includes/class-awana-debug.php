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
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'awana_debug_nonce' );

		$parts = array(
			$this->get_js_ajax_helper( $nonce, $ajax_url ),
			$this->get_js_ajax_button_handlers(),
			$this->get_js_simulation_handlers(),
			$this->get_js_uid_handlers(),
		);

		return 'jQuery(document).ready(function($) {' . implode( "\n", $parts ) . '});';
	}

	/**
	 * Get JS AJAX helper function.
	 *
	 * @param string $nonce AJAX nonce.
	 * @param string $ajax_url AJAX URL.
	 * @return string JavaScript code.
	 */
	private function get_js_ajax_helper( $nonce, $ajax_url ) {
		return '
			function doAjax(action, extraData, callback) {
				var data = $.extend({
					action: action,
					nonce: "' . esc_js( $nonce ) . '"
				}, extraData || {});
				$.ajax({
					url: "' . esc_url( $ajax_url ) . '",
					type: "POST",
					data: data,
					success: function(response) { if (callback) callback(response); },
					error: function(xhr, status, error) {
						if (callback) callback({success: false, data: {message: error}});
					}
				});
			}';
	}

	/**
	 * Get JS handlers for AJAX action buttons.
	 *
	 * @return string JavaScript code.
	 */
	private function get_js_ajax_button_handlers() {
		$handlers = array();

		$handlers[] = $this->get_js_result_button(
			'awana-debug-fetch-firebase',
			'awana_debug_fetch_firebase',
			'awana-debug-firebase-result',
			__( 'Fetching...', 'awana-digital-sync' ),
			__( 'Fetch from Firebase', 'awana-digital-sync' )
		);
		$handlers[] = $this->get_js_reload_button(
			'awana-debug-force-sync',
			'awana_debug_force_sync',
			__( 'Syncing...', 'awana-digital-sync' ),
			__( 'Force Sync', 'awana-digital-sync' )
		);
		$handlers[] = $this->get_js_reload_button(
			'awana-debug-clear-cache',
			'awana_debug_clear_cache',
			__( 'Clearing...', 'awana-digital-sync' ),
			__( 'Clear Cache', 'awana-digital-sync' )
		);
		$handlers[] = $this->get_js_result_button(
			'awana-debug-compare',
			'awana_debug_compare',
			'awana-debug-compare-result',
			__( 'Comparing...', 'awana-digital-sync' ),
			__( 'Compare Firebase vs Cache', 'awana-digital-sync' )
		);
		$handlers[] = $this->get_js_result_button(
			'awana-debug-test-connection',
			'awana_debug_test_connection',
			'awana-debug-connection-result',
			__( 'Testing...', 'awana-digital-sync' ),
			__( 'Test Firebase Connection', 'awana-digital-sync' )
		);
		$handlers[] = $this->get_js_result_button(
			'awana-debug-refresh-log',
			'awana_debug_refresh_log',
			'awana-debug-log-result',
			__( 'Refreshing...', 'awana-digital-sync' ),
			__( 'Refresh Log', 'awana-digital-sync' )
		);

		return implode( "\n", $handlers );
	}

	/**
	 * Get JS for a button that shows results in a container.
	 *
	 * @param string $btn_id      Button element ID.
	 * @param string $action      AJAX action name.
	 * @param string $result_id   Result container ID.
	 * @param string $loading_txt Loading button text.
	 * @param string $ready_txt   Ready button text.
	 * @return string JavaScript code.
	 */
	private function get_js_result_button( $btn_id, $action, $result_id, $loading_txt, $ready_txt ) {
		return '
			$("#' . esc_js( $btn_id ) . '").on("click", function() {
				var $btn = $(this);
				var $result = $("#' . esc_js( $result_id ) . '");
				$btn.prop("disabled", true).text("' . esc_js( $loading_txt ) . '");
				$result.html("Loading...").show();
				doAjax("' . esc_js( $action ) . '", {}, function(response) {
					$btn.prop("disabled", false).text("' . esc_js( $ready_txt ) . '");
					if (response.success) {
						$result.html(response.data.html).show();
					} else {
						$result.html("<span style=\"color:red;\">Error: " + (response.data ? response.data.message : "Unknown error") + "</span>").show();
					}
				});
			});';
	}

	/**
	 * Get JS for a button that reloads the page on success.
	 *
	 * @param string $btn_id      Button element ID.
	 * @param string $action      AJAX action name.
	 * @param string $loading_txt Loading button text.
	 * @param string $ready_txt   Ready button text.
	 * @return string JavaScript code.
	 */
	private function get_js_reload_button( $btn_id, $action, $loading_txt, $ready_txt ) {
		return '
			$("#' . esc_js( $btn_id ) . '").on("click", function() {
				var $btn = $(this);
				$btn.prop("disabled", true).text("' . esc_js( $loading_txt ) . '");
				doAjax("' . esc_js( $action ) . '", {}, function(response) {
					$btn.prop("disabled", false).text("' . esc_js( $ready_txt ) . '");
					if (response.success) { location.reload(); }
					else { alert(response.data.message); }
				});
			});';
	}

	/**
	 * Get JS handlers for checkout and writeback simulation.
	 *
	 * @return string JavaScript code.
	 */
	private function get_js_simulation_handlers() {
		return '
			$("#awana-debug-org-select").on("change", function() {
				var orgData = $(this).val();
				var $result = $("#awana-debug-checkout-result");
				if (!orgData) { $result.html("").hide(); return; }
				try {
					var org = JSON.parse(orgData);
					var html = "<strong>_awana_selected_org order-meta:</strong><pre>" + JSON.stringify(org, null, 2) + "</pre>";
					if (!org.pogCustomerNumber) {
						html += "<p style=\"color: #856404;\">Warning: pogCustomerNumber is empty</p>";
					} else {
						html += "<p style=\"color: #155724;\">pogCustomerNumber: " + org.pogCustomerNumber + "</p>";
					}
					$result.html(html).show();
				} catch(e) {
					$result.html("<span style=\"color:red;\">Error parsing org data</span>").show();
				}
			});
			$("#awana-debug-writeback-simulate").on("click", function() {
				var orgData = $("#awana-debug-writeback-org-select").val();
				var newPog = $("#awana-debug-new-pog").val();
				var $result = $("#awana-debug-writeback-result");
				if (!orgData) {
					$result.html("<span style=\"color:red;\">Please select an organization</span>").show();
					return;
				}
				try {
					var org = JSON.parse(orgData);
					var payload = {
						memberId: org.id || org.memberId || "unknown",
						organizationId: org.organizationId || "unknown",
						pogCustomerNumber: newPog || "(empty)"
					};
					var html = "<strong>Dry run - would send to updateMemberPogCustomerNumber:</strong>";
					html += "<pre>" + JSON.stringify(payload, null, 2) + "</pre>";
					html += "<p><em>No actual request was made.</em></p>";
					$result.html(html).show();
				} catch(e) {
					$result.html("<span style=\"color:red;\">Error parsing org data</span>").show();
				}
			});';
	}

	/**
	 * Get JS handlers for Firebase UID management.
	 *
	 * @return string JavaScript code.
	 */
	private function get_js_uid_handlers() {
		return '
			$("#awana-debug-update-uid").on("click", function() {
				var $btn = $(this);
				var newUid = $("#awana-debug-firebase-uid-input").val().trim();
				if (!newUid) { alert("' . esc_js( __( 'Please enter a Firebase UID', 'awana-digital-sync' ) ) . '"); return; }
				if (!confirm("' . esc_js( __( 'Are you sure you want to update the Firebase UID?', 'awana-digital-sync' ) ) . '")) { return; }
				$btn.prop("disabled", true).text("' . esc_js( __( 'Updating...', 'awana-digital-sync' ) ) . '");
				doAjax("awana_debug_update_firebase_uid", {firebase_uid: newUid}, function(response) {
					$btn.prop("disabled", false).text("' . esc_js( __( 'Update Firebase UID', 'awana-digital-sync' ) ) . '");
					if (response.success) { location.reload(); }
					else { alert(response.data.message); }
				});
			});
			$("#awana-debug-clear-uid").on("click", function() {
				if (!confirm("' . esc_js( __( 'Are you sure you want to clear the Firebase UID?', 'awana-digital-sync' ) ) . '")) { return; }
				var $btn = $(this);
				$btn.prop("disabled", true).text("' . esc_js( __( 'Clearing...', 'awana-digital-sync' ) ) . '");
				doAjax("awana_debug_update_firebase_uid", {firebase_uid: ""}, function(response) {
					$btn.prop("disabled", false).text("' . esc_js( __( 'Clear UID', 'awana-digital-sync' ) ) . '");
					if (response.success) { location.reload(); }
					else { alert(response.data.message); }
				});
			});';
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

			<?php
			$this->render_section_user_info( $user_id, $user, $firebase_uid, $last_sync );
			$this->render_section_update_uid( $firebase_uid );
			$this->render_section_fetch_firebase( $firebase_uid );
			$this->render_section_cached_orgs( $firebase_uid, $orgs );
			$this->render_section_diff( $firebase_uid );
			$this->render_section_checkout_sim( $orgs );
			$this->render_section_writeback_sim( $orgs );
			$this->render_section_connection_test();
			$this->render_section_log();
			?>
		</div>
		<?php
	}

	/**
	 * Render user info section.
	 *
	 * @param int      $user_id      User ID.
	 * @param WP_User  $user         User object.
	 * @param string   $firebase_uid Firebase UID.
	 * @param string   $last_sync    Last sync timestamp.
	 */
	private function render_section_user_info( $user_id, $user, $firebase_uid, $last_sync ) {
		?>
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
		<?php
	}

	/**
	 * Render update Firebase UID section.
	 *
	 * @param string $firebase_uid Current Firebase UID.
	 */
	private function render_section_update_uid( $firebase_uid ) {
		?>
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
		<?php
	}

	/**
	 * Render fetch from Firebase section.
	 *
	 * @param string $firebase_uid Firebase UID.
	 */
	private function render_section_fetch_firebase( $firebase_uid ) {
		?>
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
		<?php
	}

	/**
	 * Render cached organizations section.
	 *
	 * @param string     $firebase_uid Firebase UID.
	 * @param array|null $orgs         Organizations data.
	 */
	private function render_section_cached_orgs( $firebase_uid, $orgs ) {
		?>
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
		<?php
	}

	/**
	 * Render diff section.
	 *
	 * @param string $firebase_uid Firebase UID.
	 */
	private function render_section_diff( $firebase_uid ) {
		?>
		<div class="awana-debug-section">
			<h2><?php echo esc_html__( 'Diff Firebase vs Cache', 'awana-digital-sync' ); ?></h2>
			<p><?php echo esc_html__( 'Compare fresh Firebase data with cached data to identify differences.', 'awana-digital-sync' ); ?></p>
			<button id="awana-debug-compare" class="button" <?php echo $firebase_uid ? '' : 'disabled'; ?>>
				<?php echo esc_html__( 'Compare Firebase vs Cache', 'awana-digital-sync' ); ?>
			</button>
			<div id="awana-debug-compare-result" class="awana-debug-result" style="display: none;"></div>
		</div>
		<?php
	}

	/**
	 * Render checkout simulation section.
	 *
	 * @param array|null $orgs Organizations data.
	 */
	private function render_section_checkout_sim( $orgs ) {
		?>
		<div class="awana-debug-section">
			<h2><?php echo esc_html__( 'Simuler checkout org-valg', 'awana-digital-sync' ); ?></h2>
			<p><?php echo esc_html__( 'Select an organization to see what would be saved as order meta.', 'awana-digital-sync' ); ?></p>
			<?php if ( $orgs && is_array( $orgs ) ) : ?>
				<?php $org_list = isset( $orgs['organizations'] ) ? $orgs['organizations'] : ( isset( $orgs[0] ) ? $orgs : array() ); ?>
				<select id="awana-debug-org-select" style="min-width: 300px;">
					<option value=""><?php echo esc_html__( '-- Select organization --', 'awana-digital-sync' ); ?></option>
					<?php foreach ( $org_list as $org ) : ?>
						<?php
						$org_name = ! empty( $org['title'] ) ? $org['title'] : ( $org['organizationId'] ?? __( 'Unknown', 'awana-digital-sync' ) );
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
		<?php
	}

	/**
	 * Render write-back simulation section.
	 *
	 * @param array|null $orgs Organizations data.
	 */
	private function render_section_writeback_sim( $orgs ) {
		?>
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
							<?php $org_name = ! empty( $org['title'] ) ? $org['title'] : ( $org['organizationId'] ?? __( 'Unknown', 'awana-digital-sync' ) ); ?>
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
		<?php
	}

	/**
	 * Render connection test section.
	 */
	private function render_section_connection_test() {
		?>
		<div class="awana-debug-section">
			<h2><?php echo esc_html__( 'Test Firebase-tilkobling', 'awana-digital-sync' ); ?></h2>
			<p><?php echo esc_html__( 'Check configuration constants and test Firebase API connectivity.', 'awana-digital-sync' ); ?></p>
			<button id="awana-debug-test-connection" class="button">
				<?php echo esc_html__( 'Test Firebase Connection', 'awana-digital-sync' ); ?>
			</button>
			<div id="awana-debug-connection-result" class="awana-debug-result" style="display: none;"></div>
		</div>
		<?php
	}

	/**
	 * Render log section.
	 */
	private function render_section_log() {
		?>
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
			$org_name = ! empty( $org['title'] ) ? $org['title'] : ( $org['organizationId'] ?? __( 'Unknown', 'awana-digital-sync' ) );
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
	 * Build Firebase API request args.
	 *
	 * @param string $firebase_uid Firebase UID.
	 * @return array Request args for wp_remote_request.
	 */
	private function build_firebase_request_args( $firebase_uid ) {
		return array(
			'method'  => 'POST',
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-API-Key'    => AWANA_FIREBASE_API_KEY,
			),
			'body'    => wp_json_encode( array( 'uid' => (string) $firebase_uid ) ),
		);
	}

	/**
	 * Validate AJAX request and return Firebase UID.
	 *
	 * @return string Firebase UID (calls wp_die on failure, never returns null).
	 */
	private function validate_ajax_with_firebase_uid() {
		check_ajax_referer( 'awana_debug_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'awana-digital-sync' ) ) );
		}

		$user_id      = get_current_user_id();
		$firebase_uid = get_user_meta( $user_id, 'mo_firebase_user_uid', true );

		if ( empty( $firebase_uid ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase UID not set for current user.', 'awana-digital-sync' ) ) );
		}

		return $firebase_uid;
	}

	/**
	 * Handle fetch_firebase AJAX request
	 */
	public function handle_fetch_firebase() {
		$firebase_uid = $this->validate_ajax_with_firebase_uid();

		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || ! defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase constants not configured.', 'awana-digital-sync' ) ) );
		}

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $this->build_firebase_request_args( $firebase_uid ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		$html = $this->render_firebase_response_html( $status_code, $body, $decoded );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render Firebase response as HTML.
	 *
	 * @param int         $status_code HTTP status code.
	 * @param string      $body        Raw response body.
	 * @param array|null  $decoded     Decoded JSON response.
	 * @return string HTML.
	 */
	private function render_firebase_response_html( $status_code, $body, $decoded ) {
		ob_start();
		echo '<p><strong>' . esc_html__( 'Status:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $status_code ) . '</p>';

		if ( $decoded ) {
			$org_list = isset( $decoded['organizations'] ) ? $decoded['organizations'] : ( isset( $decoded[0] ) ? $decoded : array() );
			$count    = is_array( $org_list ) ? count( $org_list ) : 0;
			echo '<p><strong>' . esc_html__( 'Organizations found:', 'awana-digital-sync' ) . '</strong> ' . esc_html( $count ) . '</p>';

			if ( $count > 0 ) {
				foreach ( $org_list as $org ) {
					$has_pog  = ! empty( $org['pogCustomerNumber'] );
					$org_name = ! empty( $org['title'] ) ? $org['title'] : ( $org['organizationId'] ?? 'Unknown' );
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

		return ob_get_clean();
	}

	/**
	 * Handle force_sync AJAX request
	 */
	public function handle_force_sync() {
		$firebase_uid = $this->validate_ajax_with_firebase_uid();
		$user_id      = get_current_user_id();

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
		$firebase_uid = $this->validate_ajax_with_firebase_uid();
		$user_id      = get_current_user_id();

		$cached_raw = get_user_meta( $user_id, '_awana_organizations', true );
		$cached     = $cached_raw ? json_decode( $cached_raw, true ) : null;

		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || ! defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			wp_send_json_error( array( 'message' => __( 'Firebase constants not configured.', 'awana-digital-sync' ) ) );
		}

		$response = wp_remote_request( AWANA_FIREBASE_GET_ORGS_URL, $this->build_firebase_request_args( $firebase_uid ) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$body  = wp_remote_retrieve_body( $response );
		$fresh = json_decode( $body, true );

		$html = $this->render_compare_html( $cached, $fresh );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render comparison HTML between cached and fresh data.
	 *
	 * @param array|null $cached Cached org data.
	 * @param array|null $fresh  Fresh org data from Firebase.
	 * @return string HTML.
	 */
	private function render_compare_html( $cached, $fresh ) {
		ob_start();

		if ( ! $cached && ! $fresh ) {
			echo '<p>' . esc_html__( 'No data available for comparison.', 'awana-digital-sync' ) . '</p>';
		} elseif ( ! $cached ) {
			echo '<p class="awana-debug-diff-added">' . esc_html__( 'Cache is empty. Firebase has data.', 'awana-digital-sync' ) . '</p>';
		} elseif ( ! $fresh ) {
			echo '<p class="awana-debug-diff-removed">' . esc_html__( 'Firebase returned no data. Cache has data.', 'awana-digital-sync' ) . '</p>';
		} else {
			$this->render_org_diff( $cached, $fresh );
		}

		return ob_get_clean();
	}

	/**
	 * Render org-by-org diff between cached and fresh data.
	 *
	 * @param array $cached Cached org data.
	 * @param array $fresh  Fresh org data from Firebase.
	 */
	private function render_org_diff( $cached, $fresh ) {
		$cached_json = wp_json_encode( $cached, JSON_PRETTY_PRINT );
		$fresh_json  = wp_json_encode( $fresh, JSON_PRETTY_PRINT );

		if ( $cached_json === $fresh_json ) {
			echo '<p style="color: #155724;">✅ ' . esc_html__( 'No differences - cache matches Firebase.', 'awana-digital-sync' ) . '</p>';
			return;
		}

		echo '<p style="color: #856404;">⚠️ ' . esc_html__( 'Differences found:', 'awana-digital-sync' ) . '</p>';

		$cached_by_id = $this->index_orgs_by_id( $cached );
		$fresh_by_id  = $this->index_orgs_by_id( $fresh );
		$all_ids      = array_unique( array_merge( array_keys( $cached_by_id ), array_keys( $fresh_by_id ) ) );

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
				$diff = $this->array_diff_recursive( $cached_by_id[ $id ], $fresh_by_id[ $id ] );
				if ( ! empty( $diff ) ) {
					$org_name = isset( $fresh_by_id[ $id ]['title'] ) ? $fresh_by_id[ $id ]['title'] : $id;
					echo '<div class="awana-debug-diff-changed" style="padding:5px;margin:5px 0;">';
					echo '🔄 ' . esc_html__( 'Changed:', 'awana-digital-sync' ) . ' ' . esc_html( $org_name );
					echo '<pre style="font-size:11px;margin:5px 0 0;">' . esc_html( wp_json_encode( $diff, JSON_PRETTY_PRINT ) ) . '</pre>';
					echo '</div>';
				}
			}
		}
	}

	/**
	 * Index organizations by their ID.
	 *
	 * @param array $data Org data (may contain 'organizations' key or be flat).
	 * @return array Orgs indexed by ID.
	 */
	private function index_orgs_by_id( $data ) {
		$org_list = isset( $data['organizations'] ) ? $data['organizations'] : ( isset( $data[0] ) ? $data : array() );
		$by_id    = array();

		foreach ( $org_list as $org ) {
			$id = isset( $org['id'] ) ? $org['id'] : ( isset( $org['organizationId'] ) ? $org['organizationId'] : null );
			if ( $id ) {
				$by_id[ $id ] = $org;
			}
		}

		return $by_id;
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
		$this->render_constants_check();
		$this->render_connection_test();
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render configuration constants check.
	 */
	private function render_constants_check() {
		$constants = array(
			'AWANA_FIREBASE_GET_ORGS_URL'          => 'Get Orgs URL',
			'AWANA_FIREBASE_API_KEY'               => 'API Key',
			'AWANA_FIREBASE_UPDATE_MEMBER_POG_URL' => 'Update Member POG URL',
			'AWANA_POG_CUSTOMER_WEBHOOK_URL'       => 'POG Customer Webhook URL',
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
	}

	/**
	 * Render Firebase connection test result.
	 */
	private function render_connection_test() {
		if ( ! defined( 'AWANA_FIREBASE_GET_ORGS_URL' ) || ! defined( 'AWANA_FIREBASE_API_KEY' ) ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Connection Test', 'awana-digital-sync' ) . '</h3>';

		$response = wp_remote_request(
			AWANA_FIREBASE_GET_ORGS_URL,
			$this->build_firebase_request_args( 'test-dummy-uid-debug' )
		);

		if ( is_wp_error( $response ) ) {
			echo '<p><span class="awana-debug-status error">❌ ' . esc_html( $response->get_error_message() ) . '</span></p>';
			return;
		}

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
