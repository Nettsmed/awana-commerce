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
	 * Currently only data layer — UI/cron added in later steps.
	 */
	public static function init() {
		// Hooks registered in Step 5 (cron) and later.
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
