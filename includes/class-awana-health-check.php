<?php
/**
 * Awana Sync Health Check
 *
 * Periodic checks against WC DB to detect orders that should have been
 * synced to PowerOffice Go (via Integrera) or to Firebase CRM but weren't.
 *
 * Catches the kind of incident where Integrera-køen sto stoppet i 13 dager
 * (15.04.–28.04.2026) without anyone noticing.
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Awana_Health_Check {

	const TRANSIENT_PREFIX = 'awana_health_alert_sent_';
	const DEDUP_HOURS      = 24;
	const OPTION_LAST_SYNC = 'awana_health_last_pog_sync';

	const META_DISMISSED         = '_awana_health_dismissed';
	const META_DISMISSED_BY      = '_awana_health_dismissed_by';
	const META_DISMISSED_NOTE    = '_awana_health_dismissed_note';
	const META_LAST_ATTEMPT_TS   = '_awana_health_last_attempt_ts';
	const META_LAST_ATTEMPT_ERR  = '_awana_health_last_attempt_error';

	const RULE_PENDING_NETS         = 'rule_1_pending_nets';
	const RULE_FAKTURA_NO_POG       = 'rule_2_faktura_no_pog';
	const RULE_B2B_NETS_NO_FIREBASE = 'rule_3_b2b_nets_no_firebase';
	const RULE_MIGRATION_ORPHANS    = 'rule_4_migration_orphans';
	const RULE_LAST_POG_SYNC        = 'rule_5_last_pog_sync';

	const SEVERITY_RED    = 'red';
	const SEVERITY_YELLOW = 'yellow';
	const SEVERITY_GREEN  = 'green';

	/**
	 * Initialize hooks (cron, AJAX, etc.).
	 *
	 * UI is wired up via Awana_B2B_Sync_Status::render_page() (tab='helse').
	 * Cron + AJAX added in later steps.
	 */
	public static function init() {
		// AJAX/cron hooks registered in later steps.
	}

	/**
	 * Render the Helse tab content.
	 */
	public static function render_health_tab() {
		$results = self::run_checks();
		?>
		<div style="margin-top: 16px;">
			<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
				<p style="color: #50575e; margin: 0;">
					<?php esc_html_e( 'Sjekker WC-DB hver 30. min for ordrer som SKULLE vært synket men ikke er det. Catch-all for Integrera- og Firebase-feil.', 'awana-commerce' ); ?>
				</p>
				<a href="<?php echo esc_url( add_query_arg( 'refresh', time() ) ); ?>" class="button button-primary">
					↻ <?php esc_html_e( 'Refresh nå', 'awana-commerce' ); ?>
				</a>
			</div>

			<?php self::render_summary_cards( $results ); ?>

			<?php self::render_stuck_orders_section( $results ); ?>

			<?php self::render_settings_block(); ?>
		</div>

		<style>
		.awana-health-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 24px; }
		@media (max-width: 1100px) { .awana-health-grid { grid-template-columns: repeat(2, 1fr); } }
		.awana-health-card { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #c3c4c7; border-radius: 3px; padding: 14px 16px; }
		.awana-health-card.red    { border-left-color: #d63638; }
		.awana-health-card.yellow { border-left-color: #dba617; }
		.awana-health-card.green  { border-left-color: #00a32a; }
		.awana-health-card .h-label { font-size: 11px; color: #50575e; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; margin-bottom: 4px; }
		.awana-health-card .h-status { font-size: 16px; font-weight: 600; margin-bottom: 4px; }
		.awana-health-card.red    .h-status { color: #b32d2e; }
		.awana-health-card.yellow .h-status { color: #b26200; }
		.awana-health-card.green  .h-status { color: #00733e; }
		.awana-health-card .h-detail { font-size: 12px; color: #646970; line-height: 1.45; }
		.awana-stuck-section { background: #fff; border: 1px solid #c3c4c7; border-radius: 3px; margin-bottom: 16px; }
		.awana-stuck-section h3 { background: #f6f7f7; border-bottom: 1px solid #c3c4c7; margin: 0; padding: 12px 16px; font-size: 14px; }
		.awana-stuck-section table { width: 100%; border-collapse: collapse; }
		.awana-stuck-section th, .awana-stuck-section td { padding: 8px 12px; text-align: left; font-size: 12px; border-bottom: 1px solid #f0f0f1; }
		.awana-stuck-section th { background: #f6f7f7; text-transform: uppercase; font-size: 10px; letter-spacing: 0.04em; }
		.awana-stuck-section .num { text-align: right; font-variant-numeric: tabular-nums; }
		</style>
		<?php
	}

	/**
	 * Render the 5 health-cards grid.
	 */
	private static function render_summary_cards( array $results ) {
		echo '<div class="awana-health-grid">';
		foreach ( $results as $rule_id => $rule ) {
			$severity = esc_attr( $rule['severity'] );
			?>
			<div class="awana-health-card <?php echo $severity; ?>">
				<div class="h-label"><?php echo esc_html( $rule['label'] ); ?></div>
				<div class="h-status">
					<?php echo self::severity_icon( $rule['severity'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — static safe HTML ?>
					<?php
					if ( self::RULE_LAST_POG_SYNC === $rule_id ) {
						echo esc_html( $rule['age_human'] ?? '—' );
					} else {
						printf( '%d ordrer', (int) $rule['count'] );
					}
					?>
				</div>
				<div class="h-detail">
					<?php
					if ( $rule['count'] > 0 && $rule['sum_amount'] > 0 ) {
						printf( 'Sum: %s kr · ', esc_html( number_format( $rule['sum_amount'], 0, ',', ' ' ) ) );
					}
					echo esc_html( $rule['description'] );
					?>
				</div>
			</div>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Render detail tables for each rule with affected orders.
	 */
	private static function render_stuck_orders_section( array $results ) {
		foreach ( $results as $rule_id => $rule ) {
			if ( empty( $rule['orders'] ) ) {
				continue;
			}
			$visible = array_slice( $rule['orders'], 0, 10 );
			$rest    = max( 0, $rule['count'] - 10 );
			?>
			<div class="awana-stuck-section">
				<h3>
					<?php echo esc_html( $rule['label'] ); ?>
					<span style="font-weight: normal; color: #646970; font-size: 12px;">
						(<?php echo (int) $rule['count']; ?> ordrer<?php if ( $rule['sum_amount'] > 0 ) : ?>, sum <?php echo esc_html( number_format( $rule['sum_amount'], 0, ',', ' ' ) ); ?> kr<?php endif; ?>)
					</span>
				</h3>
				<table>
					<thead>
						<tr>
							<th>Ordre</th>
							<th>Dato (UTC)</th>
							<th class="num">Beløp</th>
							<th>E-post</th>
							<th class="num">Alder (t)</th>
							<th>Handling</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $visible as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $row['id'] ) ); ?>" target="_blank">
										#<?php echo (int) $row['id']; ?>
									</a>
								</td>
								<td><?php echo esc_html( $row['date_gmt'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format( (float) $row['total'], 0, ',', ' ' ) ); ?></td>
								<td><?php echo esc_html( $row['email'] ); ?></td>
								<td class="num"><?php echo (int) $row['age_hours']; ?></td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $row['id'] ) ); ?>" target="_blank" class="button button-small">
										Åpne
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if ( $rest > 0 ) : ?>
							<tr>
								<td colspan="6" style="text-align: center; color: #646970; font-style: italic;">
									+ <?php echo (int) $rest; ?> flere ordre
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php
		}
	}

	/**
	 * Render configuration info block.
	 */
	private static function render_settings_block() {
		$recipients = defined( 'AWANA_HEALTH_ALERT_RECIPIENTS' ) ? AWANA_HEALTH_ALERT_RECIPIENTS : '(ikke konfigurert ennå — sett AWANA_HEALTH_ALERT_RECIPIENTS i wp-config.php)';
		$dedup      = defined( 'AWANA_HEALTH_DEDUP_HOURS' ) ? AWANA_HEALTH_DEDUP_HOURS : self::DEDUP_HOURS;
		?>
		<div class="awana-stuck-section">
			<h3><?php esc_html_e( 'Konfigurasjon', 'awana-commerce' ); ?></h3>
			<table>
				<tr><td style="color: #646970; width: 220px;">Cron-frekvens</td><td>Hver 30 min (kommer i Steg 5)</td></tr>
				<tr><td style="color: #646970;">Daglig sammendrag</td><td>Kl 08:00 Europe/Oslo (kommer i Steg 5)</td></tr>
				<tr><td style="color: #646970;">Mottakere</td><td><?php echo esc_html( $recipients ); ?></td></tr>
				<tr><td style="color: #646970;">Dedup-vindu</td><td><?php echo (int) $dedup; ?> timer per regel</td></tr>
				<tr><td style="color: #646970;">wp_option for siste sync</td><td><code><?php echo esc_html( self::OPTION_LAST_SYNC ); ?></code></td></tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Color-blind safe icon for severity level.
	 */
	private static function severity_icon( string $severity ): string {
		switch ( $severity ) {
			case self::SEVERITY_RED:    return '<span style="color:#d63638; font-weight:700; margin-right:6px;">!</span>';
			case self::SEVERITY_YELLOW: return '<span style="color:#dba617; font-weight:700; margin-right:6px;">?</span>';
			case self::SEVERITY_GREEN:  return '<span style="color:#00a32a; font-weight:700; margin-right:6px;">✓</span>';
			default:                    return '';
		}
	}

	/**
	 * Run all 5 health checks and return aggregated result.
	 *
	 * @return array<string, array> Keyed by rule_id.
	 */
	public static function run_checks(): array {
		return array(
			self::RULE_PENDING_NETS         => self::rule_1_pending_nets(),
			self::RULE_FAKTURA_NO_POG       => self::rule_2_faktura_no_pog(),
			self::RULE_B2B_NETS_NO_FIREBASE => self::rule_3_b2b_nets_no_firebase(),
			self::RULE_MIGRATION_ORPHANS    => self::rule_4_migration_orphans(),
			self::RULE_LAST_POG_SYNC        => self::rule_5_last_pog_sync(),
		);
	}

	/**
	 * Build a uniform result struct for each rule.
	 *
	 * @param string $rule_id     Rule identifier.
	 * @param string $label       Human-readable label.
	 * @param string $description Longer explanation.
	 * @param string $severity    'red' | 'yellow' | 'green'.
	 * @param array  $orders      List of affected order rows: [['id'=>int, 'total'=>float, 'email'=>string, 'date_gmt'=>string, 'age_hours'=>int], ...].
	 * @param array  $extra       Optional extra fields.
	 * @return array
	 */
	private static function make_result( string $rule_id, string $label, string $description, string $severity, array $orders = array(), array $extra = array() ): array {
		$count      = count( $orders );
		$sum_amount = array_reduce( $orders, fn( $carry, $row ) => $carry + (float) ( $row['total'] ?? 0 ), 0.0 );
		$order_ids  = array_map( fn( $row ) => (int) $row['id'], $orders );

		return array_merge(
			array(
				'rule_id'     => $rule_id,
				'label'       => $label,
				'description' => $description,
				'severity'    => $severity,
				'count'       => $count,
				'sum_amount'  => round( $sum_amount, 2 ),
				'order_ids'   => $order_ids,
				'orders'      => $orders,
			),
			$extra
		);
	}

	/**
	 * Regel 1 — Pending Nets > 2 timer (gul).
	 *
	 * After Hold Stock = 60: should always be 0. >0 means WC-cron is dead.
	 */
	private static function rule_1_pending_nets(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT id, total_amount AS total, billing_email AS email, date_created_gmt AS date_gmt,
			        TIMESTAMPDIFF(HOUR, date_created_gmt, NOW()) AS age_hours
			 FROM {$wpdb->prefix}wc_orders
			 WHERE status = 'wc-pending'
			   AND payment_method = 'dibs_easy'
			   AND date_created_gmt < DATE_SUB(NOW(), INTERVAL 2 HOUR)
			 ORDER BY date_created_gmt ASC",
			ARRAY_A
		) ?: array();

		$severity = empty( $rows ) ? self::SEVERITY_GREEN : self::SEVERITY_YELLOW;

		return self::make_result(
			self::RULE_PENDING_NETS,
			'Pending Nets > 2t',
			'Nets-ordrer som har stått som pending i over 2 timer. Skal være 0 etter Hold Stock = 60. Hvis >0 er WC-cron sannsynligvis død.',
			$severity,
			$rows
		);
	}

	/**
	 * Regel 2 — On-hold Faktura > 48t uten POG-kobling (rød).
	 *
	 * Catch-all for Integrera-pipen — det signalet vi gikk glipp av i 13-dagers-incidenten.
	 */
	private static function rule_2_faktura_no_pog(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT o.id, o.total_amount AS total, o.billing_email AS email, o.date_created_gmt AS date_gmt,
			        TIMESTAMPDIFF(HOUR, o.date_created_gmt, NOW()) AS age_hours
			 FROM {$wpdb->prefix}wc_orders o
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta m
			   ON o.id = m.order_id AND m.meta_key = 'pog_customer_number'
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta dis
			   ON o.id = dis.order_id AND dis.meta_key = '" . self::META_DISMISSED . "'
			 WHERE o.status = 'wc-on-hold'
			   AND o.payment_method = 'bacs'
			   AND o.date_created_gmt < DATE_SUB(NOW(), INTERVAL 48 HOUR)
			   AND m.meta_value IS NULL
			   AND dis.meta_value IS NULL
			 ORDER BY o.date_created_gmt ASC",
			ARRAY_A
		) ?: array();

		$severity = empty( $rows ) ? self::SEVERITY_GREEN : self::SEVERITY_RED;

		return self::make_result(
			self::RULE_FAKTURA_NO_POG,
			'Faktura uten POG-kobling',
			'On-hold Faktura-ordrer eldre enn 48 timer som mangler pog_customer_number. Catch-all for Integrera-pipen.',
			$severity,
			$rows
		);
	}

	/**
	 * Regel 3 — B2B Nets uten Firebase crm_invoice_id > 1 time (gul).
	 *
	 * Catch Firebase createCheckoutInvoice-feil.
	 */
	private static function rule_3_b2b_nets_no_firebase(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT o.id, o.total_amount AS total, o.billing_email AS email, o.date_created_gmt AS date_gmt,
			        TIMESTAMPDIFF(HOUR, o.date_created_gmt, NOW()) AS age_hours
			 FROM {$wpdb->prefix}wc_orders o
			 JOIN {$wpdb->prefix}wc_orders_meta pt
			   ON o.id = pt.order_id AND pt.meta_key = '_awana_payment_type' AND pt.meta_value = 'organization'
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta crm
			   ON o.id = crm.order_id AND crm.meta_key = 'crm_invoice_id'
			 LEFT JOIN {$wpdb->prefix}wc_orders_meta dis
			   ON o.id = dis.order_id AND dis.meta_key = '" . self::META_DISMISSED . "'
			 WHERE o.status IN ('wc-processing', 'wc-completed')
			   AND o.payment_method = 'dibs_easy'
			   AND o.date_created_gmt < DATE_SUB(NOW(), INTERVAL 1 HOUR)
			   AND crm.meta_value IS NULL
			   AND dis.meta_value IS NULL
			 ORDER BY o.date_created_gmt ASC",
			ARRAY_A
		) ?: array();

		$severity = empty( $rows ) ? self::SEVERITY_GREEN : self::SEVERITY_YELLOW;

		return self::make_result(
			self::RULE_B2B_NETS_NO_FIREBASE,
			'B2B Nets uten Firebase',
			'B2B-ordrer betalt med Nets eldre enn 1 time som mangler crm_invoice_id (Firebase createCheckoutInvoice).',
			$severity,
			$rows
		);
	}

	/**
	 * Regel 4 — Migrasjons-orphans (gul, info).
	 *
	 * Faktura-ordrer uten _awana_payment_type-tag (pre-v1.2.2 orphans).
	 * Burde være 0 etter cleanup. Informativ.
	 */
	private static function rule_4_migration_orphans(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT o.id, o.total_amount AS total, o.billing_email AS email, o.date_created_gmt AS date_gmt,
			        TIMESTAMPDIFF(HOUR, o.date_created_gmt, NOW()) AS age_hours
			 FROM {$wpdb->prefix}wc_orders o
			 WHERE o.status = 'wc-on-hold'
			   AND o.payment_method = 'bacs'
			   AND o.id NOT IN (
			     SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
			     WHERE meta_key = '_awana_payment_type'
			   )
			   AND o.id NOT IN (
			     SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
			     WHERE meta_key = '" . self::META_DISMISSED . "' AND meta_value = '1'
			   )
			 ORDER BY o.date_created_gmt ASC",
			ARRAY_A
		) ?: array();

		$severity = empty( $rows ) ? self::SEVERITY_GREEN : self::SEVERITY_YELLOW;

		return self::make_result(
			self::RULE_MIGRATION_ORPHANS,
			'Migrasjons-orphans',
			'Faktura-ordrer uten _awana_payment_type-tag (pre-v1.2.2 orphans). Bør være 0 etter manuell rydding.',
			$severity,
			$rows
		);
	}

	/**
	 * Regel 5 — Sist vellykket POG-mapping > 24t (rød).
	 *
	 * Tracks awana_health_last_pog_sync option, which is updated by
	 * Awana_CRM_Webhook::handle_checkout_invoice_response() on success.
	 */
	private static function rule_5_last_pog_sync(): array {
		$last_sync_str = get_option( self::OPTION_LAST_SYNC );

		if ( empty( $last_sync_str ) ) {
			// Never recorded — return yellow (not red — could be a fresh install)
			return self::make_result(
				self::RULE_LAST_POG_SYNC,
				'Sist POG-mapping',
				'Tidspunkt for siste vellykkede POG-mapping har ikke blitt registrert ennå. Aktiveres ved første POG-suksess etter at trackingen er deployet.',
				self::SEVERITY_YELLOW,
				array(),
				array(
					'last_sync_ts'  => null,
					'age_hours'     => null,
					'age_human'     => 'aldri',
				)
			);
		}

		$last_sync_ts = strtotime( $last_sync_str );
		$age_seconds  = time() - $last_sync_ts;
		$age_hours    = (int) floor( $age_seconds / HOUR_IN_SECONDS );

		$severity = $age_hours > 24 ? self::SEVERITY_RED : self::SEVERITY_GREEN;

		return self::make_result(
			self::RULE_LAST_POG_SYNC,
			'Sist POG-mapping',
			sprintf( 'Siste vellykkede POG-mapping var %d timer siden (%s). Skal være < 24t hvis trafikken er normal.', $age_hours, $last_sync_str ),
			$severity,
			array(),
			array(
				'last_sync_ts' => $last_sync_str,
				'age_hours'    => $age_hours,
				'age_human'    => human_time_diff( $last_sync_ts, time() ) . ' siden',
			)
		);
	}
}
