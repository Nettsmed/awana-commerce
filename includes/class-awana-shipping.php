<?php
/**
 * Awana Shipping behavior tweaks
 *
 * Currently: ensure Posten Bring is the default selected shipping method
 * over Local Pickup, regardless of which method WC's auto-pick prefers.
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Awana_Shipping {

	public static function init() {
		// Priority 20 to run after Posten Bring's own filters and after WC's
		// internal default-picking logic (priority 10 by convention).
		add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'prefer_posten_over_local_pickup' ), 20, 3 );
	}

	/**
	 * Prefer Posten Bring rates as default over Local Pickup.
	 *
	 * Background: WC's `wc_get_default_shipping_method_for_package` preserves
	 * a previously-chosen local_pickup across recalculation passes, even when
	 * better Posten rates appear in a later pass (Posten Bring registers as a
	 * global method that's added after the per-zone local_pickup). Net effect
	 * for awana.no after Local Pickup Straume was added 2026-04-29: WC pre-
	 * selected pickup for every customer, including those with no intent to
	 * pick up at Straume.
	 *
	 * Fix: when the resolved default is a local_pickup variant AND a Posten
	 * Bring rate is available, switch the default to the cheapest Posten rate
	 * (Posten Bring sorts its own rates by cost ASC, so taking the first one
	 * gives the cheapest). User can still manually click Local Pickup; this
	 * only changes what's pre-selected.
	 *
	 * @param string $default       Default shipping rate id (e.g. "local_pickup:5").
	 * @param array  $rates         Available shipping rates keyed by rate id.
	 * @param string $chosen_method Previously-chosen rate id (may be empty).
	 * @return string Modified default if Posten should win, otherwise unchanged.
	 */
	public static function prefer_posten_over_local_pickup( $default, $rates, $chosen_method ) {
		$default_method_id = current( explode( ':', (string) $default ) );

		if ( 'local_pickup' !== $default_method_id ) {
			return $default;
		}

		foreach ( $rates as $rate_id => $rate ) {
			if ( 0 === strpos( (string) $rate_id, 'posten-bring-checkout' ) ) {
				return $rate_id;
			}
		}

		return $default;
	}
}
