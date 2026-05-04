<?php
/**
 * Awana B2B Invoice Notifier
 *
 * Sends a verification-mail to AWANA_HEALTH_ALERT_RECIPIENTS every time a new
 * B2B Faktura order lands in on-hold so that shipping- and VAT-mapping from
 * WC → POG can be controlled manually before we trust the Integrera fix
 * (ticket #6462, 2026-05-04). Designed as a temporary verification layer; turn
 * off via `define( 'AWANA_B2B_VERIFICATION_MODE', false )` in wp-config when
 * Romano's config has been confirmed across a handful of real orders.
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Awana_B2B_Invoice_Notifier {

	const META_NOTIFIED       = '_awana_b2b_notified';
	const META_PAYMENT_TYPE   = '_awana_payment_type';
	const PAYMENT_METHOD_BACS = 'bacs';
	const ORG_VALUE           = 'organization';

	public static function init() {
		add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'maybe_notify' ), 20, 2 );
	}

	public static function maybe_notify( $order_id, $order ) {
		if ( ! self::is_verification_mode_enabled() ) {
			return;
		}
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order || ! self::is_b2b_faktura_order( $order ) ) {
			return;
		}
		if ( $order->get_meta( self::META_NOTIFIED ) ) {
			return;
		}

		$recipients = self::get_recipients();
		if ( empty( $recipients ) ) {
			Awana_Logger::warning( 'awana_b2b_notifier: no AWANA_HEALTH_ALERT_RECIPIENTS configured, skipping mail' );
			return;
		}

		$subject = sprintf( '[Awana B2B] Ny Faktura-ordre #%d — verifiser POG-mapping', $order->get_id() );
		$body    = self::build_body( $order );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $recipients, $subject, $body, $headers );

		$order->update_meta_data( self::META_NOTIFIED, gmdate( 'c' ) );
		$order->save_meta_data();

		Awana_Logger::info( 'awana_b2b_notifier: verification mail sent', array(
			'order_id'   => $order->get_id(),
			'recipients' => $recipients,
			'sent'       => $sent,
		) );
	}

	private static function is_verification_mode_enabled(): bool {
		if ( ! defined( 'AWANA_B2B_VERIFICATION_MODE' ) ) {
			return true;
		}
		return (bool) AWANA_B2B_VERIFICATION_MODE;
	}

	private static function is_b2b_faktura_order( WC_Order $order ): bool {
		if ( self::PAYMENT_METHOD_BACS !== $order->get_payment_method() ) {
			return false;
		}
		return self::ORG_VALUE === $order->get_meta( self::META_PAYMENT_TYPE );
	}

	private static function get_recipients(): array {
		if ( ! defined( 'AWANA_HEALTH_ALERT_RECIPIENTS' ) || empty( AWANA_HEALTH_ALERT_RECIPIENTS ) ) {
			return array();
		}
		return array_filter( array_map( 'trim', explode( ',', AWANA_HEALTH_ALERT_RECIPIENTS ) ) );
	}

	private static function build_body( WC_Order $order ): string {
		$lines   = array();
		$lines[] = sprintf( 'Ny B2B Faktura-ordre #%d satt til on-hold.', $order->get_id() );
		$lines[] = '';
		$lines[] = 'Kunde:';
		$company = $order->get_billing_company();
		if ( $company ) {
			$lines[] = '  ' . $company;
		}
		$lines[] = '  ' . trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$lines[] = '  ' . sanitize_email( $order->get_billing_email() );
		$lines[] = '';

		$lines[] = 'Produktlinjer:';
		foreach ( $order->get_items() as $item ) {
			$lines[] = sprintf(
				'  - %s × %d = %s kr',
				$item->get_name(),
				$item->get_quantity(),
				number_format( (float) $item->get_total(), 2, ',', ' ' )
			);
		}

		$shipping_total = (float) $order->get_shipping_total();
		$lines[] = '';
		$lines[] = sprintf(
			'Frakt: %s kr (%s)',
			number_format( $shipping_total, 2, ',', ' ' ),
			$order->get_shipping_method() ?: '—'
		);
		$lines[] = sprintf( 'Mva: %s kr', number_format( (float) $order->get_total_tax(), 2, ',', ' ' ) );
		$lines[] = sprintf( 'Total: %s kr', number_format( (float) $order->get_total(), 2, ',', ' ' ) );
		$lines[] = '';

		$lines[] = 'Verifiser i POG (når Integrera har synket):';
		$lines[] = '  [ ] POG-fakturaen har shipping-linje (Posten Bring ' . number_format( $shipping_total, 2, ',', ' ' ) . ' kr)';
		$lines[] = '  [ ] POG-mva matcher WC-mva (' . number_format( (float) $order->get_total_tax(), 2, ',', ' ' ) . ' kr)';
		$lines[] = '  [ ] POG-total matcher WC-total (' . number_format( (float) $order->get_total(), 2, ',', ' ' ) . ' kr)';
		$lines[] = '';
		$lines[] = 'Hvis mismatch: reopen Integrera ticket #6462 med ordre-ID ' . $order->get_id() . '.';
		$lines[] = '';
		$lines[] = 'WC-ordre: ' . admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() );
		$lines[] = '';
		$lines[] = 'Slå av denne mailen når Romano sin fix er bekreftet:';
		$lines[] = "  define( 'AWANA_B2B_VERIFICATION_MODE', false ); // i wp-config.php";

		return implode( "\n", $lines );
	}
}
