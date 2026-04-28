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
	 */
	public static function init() {
		// Custom cron interval.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_intervals' ) );

		// Cron hooks.
		add_action( 'awana_health_check', array( __CLASS__, 'run_cron' ) );
		add_action( 'awana_health_daily_summary', array( __CLASS__, 'send_daily_summary' ) );

		// Auto-schedule on first load if not yet scheduled.
		if ( ! wp_next_scheduled( 'awana_health_check' ) ) {
			wp_schedule_event( time() + 300, 'awana_thirty_minutes', 'awana_health_check' );
		}
		if ( ! wp_next_scheduled( 'awana_health_daily_summary' ) ) {
			wp_schedule_event( self::next_8am_local(), 'daily', 'awana_health_daily_summary' );
		}
	}

	/**
	 * Add 30-minute cron interval.
	 */
	public static function add_cron_intervals( $schedules ) {
		$schedules['awana_thirty_minutes'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => 'Hver 30. min (Awana Sync)',
		);
		return $schedules;
	}

	/**
	 * Cron callback — run health checks and send alerts on red rules.
	 */
	public static function run_cron() {
		$results = self::run_checks();
		$issues  = array();
		foreach ( $results as $rule ) {
			if ( self::SEVERITY_RED === $rule['severity'] ) {
				$issues[] = $rule;
			}
		}
		if ( ! empty( $issues ) ) {
			self::send_alert( $issues );
		}
	}

	/**
	 * Send alarm e-mail with dedup per rule.
	 *
	 * @param array $issues Red-severity rules from run_checks().
	 */
	public static function send_alert( array $issues ) {
		$dedup_hours = defined( 'AWANA_HEALTH_DEDUP_HOURS' ) ? (int) AWANA_HEALTH_DEDUP_HOURS : self::DEDUP_HOURS;
		$to_send     = array();
		foreach ( $issues as $rule ) {
			$dedup_key = self::TRANSIENT_PREFIX . $rule['rule_id'];
			if ( get_transient( $dedup_key ) ) {
				continue;
			}
			$to_send[] = $rule;
			set_transient( $dedup_key, true, $dedup_hours * HOUR_IN_SECONDS );
		}
		if ( empty( $to_send ) ) {
			return;
		}

		$recipients = self::get_alert_recipients();
		if ( empty( $recipients ) ) {
			Awana_Logger::warning( 'awana_health: no AWANA_HEALTH_ALERT_RECIPIENTS configured, skipping mail' );
			return;
		}

		$subject = sprintf( '[Awana Sync] %d %s trigget — handling kreves', count( $to_send ), count( $to_send ) === 1 ? 'regel' : 'regler' );
		$body    = self::build_alert_body( $to_send );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $recipients, $subject, $body, $headers );

		Awana_Logger::info( 'awana_health: alarm sent', array(
			'recipients' => $recipients,
			'rules'      => array_column( $to_send, 'rule_id' ),
		) );

		// Sentry breadcrumb if available.
		if ( function_exists( '\\Sentry\\addBreadcrumb' ) ) {
			\Sentry\addBreadcrumb( new \Sentry\Breadcrumb(
				\Sentry\Breadcrumb::LEVEL_WARNING,
				\Sentry\Breadcrumb::TYPE_DEFAULT,
				'awana_health',
				$subject,
				array( 'rules' => array_column( $to_send, 'rule_id' ) )
			) );
		}
	}

	/**
	 * Daily summary cron callback — sends regardless of state.
	 */
	public static function send_daily_summary() {
		$results    = self::run_checks();
		$recipients = self::get_alert_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$counts = array(
			'red'    => 0,
			'yellow' => 0,
			'green'  => 0,
		);
		foreach ( $results as $rule ) {
			$counts[ $rule['severity'] ]++;
		}

		$subject = sprintf(
			'[Awana Sync] Daglig sammendrag — %d røde, %d gule, %d grønne',
			$counts['red'],
			$counts['yellow'],
			$counts['green']
		);
		$body    = self::build_summary_body( $results );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $recipients, $subject, $body, $headers );

		Awana_Logger::info( 'awana_health: daily summary sent', array( 'recipients' => $recipients ) );
	}

	/**
	 * Build the alarm-mail body (plain text).
	 */
	private static function build_alert_body( array $issues ): string {
		$body  = "Helsesjekken har funnet problemer som krever oppmerksomhet:\n\n";
		foreach ( $issues as $rule ) {
			$body .= '🔴 ' . $rule['label'] . "\n";
			$body .= '   ' . $rule['description'] . "\n";
			if ( $rule['count'] > 0 ) {
				$body .= sprintf( "   %d ordrer", $rule['count'] );
				if ( $rule['sum_amount'] > 0 ) {
					$body .= sprintf( ', sum %s kr', number_format( $rule['sum_amount'], 0, ',', ' ' ) );
				}
				$body .= "\n";
				$preview = array_slice( $rule['orders'], 0, 5 );
				foreach ( $preview as $order ) {
					$body .= sprintf(
						"     - #%d (%s kr, %d t) %s\n",
						$order['id'],
						number_format( (float) $order['total'], 0, ',', ' ' ),
						(int) $order['age_hours'],
						$order['email']
					);
				}
				if ( $rule['count'] > 5 ) {
					$body .= sprintf( "     + %d flere\n", $rule['count'] - 5 );
				}
			}
			$body .= "\n";
		}
		$body .= "\n----\n";
		$body .= 'Se detaljer: ' . admin_url( 'admin.php?page=awana-b2b-sync&tab=helse' ) . "\n\n";
		$body .= sprintf( '(Ingen ny mail om samme regel innen %d timer. Definer AWANA_HEALTH_DEDUP_HOURS i wp-config.php for å justere.)', defined( 'AWANA_HEALTH_DEDUP_HOURS' ) ? (int) AWANA_HEALTH_DEDUP_HOURS : self::DEDUP_HOURS );
		return $body;
	}

	/**
	 * Build the daily summary mail body (plain text).
	 */
	private static function build_summary_body( array $results ): string {
		$body  = sprintf( "Sync-status %s:\n\n", date_i18n( 'd.m.Y' ) );
		foreach ( $results as $rule_id => $rule ) {
			$dot = self::SEVERITY_RED === $rule['severity'] ? '🔴' : ( self::SEVERITY_YELLOW === $rule['severity'] ? '🟡' : '🟢' );
			if ( self::RULE_LAST_POG_SYNC === $rule_id ) {
				$body .= sprintf( "%s %s — %s\n", $dot, $rule['label'], $rule['age_human'] ?? 'aldri' );
			} else {
				$line = sprintf( '%s %s — %d ordrer', $dot, $rule['label'], (int) $rule['count'] );
				if ( $rule['sum_amount'] > 0 ) {
					$line .= sprintf( ' (≈ %s kr)', number_format( $rule['sum_amount'], 0, ',', ' ' ) );
				}
				$body .= $line . "\n";
			}
		}
		$body .= "\n----\n";
		$body .= 'Se Awana Sync → Helse: ' . admin_url( 'admin.php?page=awana-b2b-sync&tab=helse' ) . "\n";
		return $body;
	}

	/**
	 * Resolve recipients from constant; supports CSV.
	 *
	 * @return array<string>
	 */
	private static function get_alert_recipients(): array {
		if ( ! defined( 'AWANA_HEALTH_ALERT_RECIPIENTS' ) || empty( AWANA_HEALTH_ALERT_RECIPIENTS ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', explode( ',', AWANA_HEALTH_ALERT_RECIPIENTS ) ) );
	}

	/**
	 * Calculate next 8 AM in Europe/Oslo timezone (returns UTC timestamp).
	 */
	private static function next_8am_local(): int {
		$tz   = wp_timezone();
		$next = new DateTimeImmutable( 'tomorrow 08:00', $tz );
		return $next->getTimestamp();
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
