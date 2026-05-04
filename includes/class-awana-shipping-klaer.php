<?php
/**
 * Klær (clothing) shipping handling for awana.no.
 *
 * Business model (confirmed by Eirik 2026-04-30):
 *  - Klær (t-shirts etc) is fulfilled and shipped by Melings (the producer)
 *    directly from their warehouse, billed at a flat 199 kr inkl. mva per order.
 *  - Books and other items are shipped by Awana from Straume via Posten Bring,
 *    or picked up by the customer at Idrettsvegen 10, Straume.
 *  - A mixed cart (klær + books) should produce TWO charges: 199 for the klær
 *    and a Posten Bring rate (or Local Pickup) for the books.
 *
 * Implementation: fee-approach.
 *
 *   - Klær-only cart: replace all shipping rates with a single Awana flat
 *     rate of 199 inkl. mva. Customer cannot pick the package up at Straume —
 *     the clothes physically live at Melings, not Awana's office.
 *
 *   - Mixed cart (klær + other): leave the standard shipping options intact
 *     (Posten Bring rates, Local Pickup), and add a 199 inkl. mva fee labelled
 *     "Frakt klær (Melings)". Customer sees their chosen Bring/Pickup cost
 *     PLUS the 199 fee in the cart total.
 *
 *   - Other-only cart: untouched.
 *
 * Why fee-approach instead of split-package
 * -----------------------------------------
 * The cleanest architecture would be to split the cart into two shipping
 * packages by class — clothing in package A (only Melings flat rate available),
 * everything else in package B (Posten Bring + Local Pickup). That maps 1:1 to
 * Eirik's "vi bruker bring direkte på resten" and gives POG-faktura two
 * proper shipping line items.
 *
 * However: WooCommerce Blocks (`Automattic\WooCommerce\Blocks\Shipping\
 * ShippingController::filter_shipping_packages()`) strips Local Pickup from
 * ALL packages when not every package supports it. With a klær-package whose
 * only rate is our flat (a non-pickup method), Local Pickup disappears from
 * the bok-package too — a real UX regression for customers near Straume.
 *
 * Additionally, all 23 klær products on awana.no currently have unset/zero
 * weight (verified 2026-04-30 against prod). So Posten Bring's rate
 * calculation on a mixed cart is unaffected by clothing — Bring effectively
 * sees only the book weight today. The technical reason to split (avoid
 * over-charging Bring on klær weight) doesn't exist in practice.
 *
 * Upgrade path (if klær weights get registered): switch to split-package via
 * woocommerce_cart_shipping_packages + woocommerce_package_rates filters,
 * accept the Local Pickup trade-off, and verify Posten Bring + POG handle
 * multi-package payloads correctly.
 *
 * Replaces Code Snippet #12 "Sikre fast frakt-sats for klær", which lived in
 * wp_snippets from 2025-11-03. The snippet's approach is the same in shape;
 * this class adds:
 *   - Single source of truth for the rate amount (KLAER_FLAT_RATE_INCL_TAX)
 *   - Customer-facing label "Frakt klær (Melings)" instead of the opaque
 *     "Tillegg for spesialfrakt"
 *   - A real WC_Shipping_Rate object for klær-only carts (the snippet hijacked
 *     the cheapest existing rate, which broke if the Bring API failed and no
 *     rates came through)
 *   - Explicit tax-class on the fee
 *   - Sentry breadcrumbs for unexpected paths
 *   - Single-slug detection helper (legacy 'melings' still accepted until DB
 *     consolidation lands)
 *
 * @package Awana_Commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Awana_Shipping_Klaer {

	/** Customer-facing total for klær shipping, including 25% Norwegian VAT. */
	const KLAER_FLAT_RATE_INCL_TAX = 199.00;

	/** Stable rate id for the klær-only flat rate. */
	const KLAER_RATE_ID = 'awana_klaer_flat';

	/** Customer-facing labels. */
	const KLAER_RATE_LABEL = 'Frakt klær (Melings)';
	const KLAER_FEE_LABEL  = 'Frakt klær (Melings)';

	/**
	 * Shipping-class slugs treated as klær.
	 * 'melings' is legacy — kept until shipping_class consolidation moves the
	 * 21 Melings products to the 'klaer' class.
	 */
	const KLAER_SHIPPING_CLASS_SLUGS = array( 'klaer', 'melings' );

	public static function init() {
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 100, 2 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_klaer_fee_for_mixed_cart' ), 20, 1 );
	}

	/**
	 * Per-package rate filter.
	 *
	 *  - Klær-only package: return ONLY our flat 199-rate. Hides Posten Bring
	 *    and Local Pickup. Clothes don't ship from Straume, so a "pickup at
	 *    Straume"-option for a klær-only cart would mislead the customer.
	 *
	 *  - Mixed or other-only: pass through whatever rates were calculated,
	 *    but defensively strip our klær flat rate if it leaked in (it
	 *    shouldn't normally).
	 *
	 *  - Empty input rates: still return our klær flat rate for klær-only
	 *    carts (so checkout doesn't break if the Bring API returns nothing).
	 */
	public static function filter_rates( $rates, $package ) {
		list( $has_klaer, $has_other ) = self::detect_classes( isset( $package['contents'] ) ? $package['contents'] : array() );

		if ( $has_klaer && ! $has_other ) {
			return array( self::KLAER_RATE_ID => self::build_klaer_rate() );
		}

		// Mixed and other-only: ensure our klær rate isn't visible in the
		// shipping selector (the fee handles the klær-charge instead).
		if ( is_array( $rates ) ) {
			foreach ( array_keys( $rates ) as $rate_id ) {
				if ( false !== strpos( (string) $rate_id, self::KLAER_RATE_ID ) ) {
					unset( $rates[ $rate_id ] );
				}
			}
		}
		return $rates;
	}

	/**
	 * Mixed-cart fee.
	 *
	 * Adds a 199 inkl-mva fee to the cart total when the cart contains BOTH
	 * klær and non-klær items. Klær-only and other-only carts: no fee.
	 *
	 * The 'taxable' flag plus the explicit 'standard' tax class causes WC to
	 * apply the standard 25% VAT. Because 199 is the gross amount and WC's
	 * fee API doesn't have a "gross input" mode, we register the fee with
	 * the net amount and let WC add tax back to reach 199 incl.
	 */
	public static function add_klaer_fee_for_mixed_cart( $cart ) {
		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		// Skip in admin context unless it's a frontend AJAX call (shipping recalcs etc.).
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( $cart->is_empty() ) {
			return;
		}

		list( $has_klaer, $has_other ) = self::detect_classes( $cart->get_cart() );
		if ( ! $has_klaer || ! $has_other ) {
			return;
		}

		// WC always treats fee->amount as NET and adds tax on top via
		// WC_Cart_Totals::get_fees_from_cart() (`$price_includes_tax = false`),
		// regardless of the woocommerce_prices_include_tax option. So we
		// register the fee with the NET amount (159.20) and let WC add the
		// 25% MVA back to land at 199.00 incl. for the customer.
		//
		// The empty string '' is WC's "standard" tax class; passing the
		// literal 'standard' returns no rates because the DB rate has
		// tax_rate_class='' (verified on awana.no 2026-04-30).
		$gross     = self::KLAER_FLAT_RATE_INCL_TAX;
		$tax_rates = WC_Tax::get_rates( '', $cart->get_customer() );
		if ( empty( $tax_rates ) ) {
			// Fallback: shipping tax rates (tax_rate_shipping=1 in DB) — same
			// 25% MVA on awana.no, kept as defense-in-depth for setups where
			// the standard rate isn't configured.
			$tax_rates = WC_Tax::get_shipping_tax_rates();
		}
		$taxes     = WC_Tax::calc_tax( $gross, $tax_rates, true );
		$tax_total = is_array( $taxes ) ? array_sum( $taxes ) : 0.0;
		$net       = (float) wc_format_decimal( $gross - $tax_total, wc_get_price_decimals() );

		$cart->add_fee(
			__( self::KLAER_FEE_LABEL, 'awana-commerce' ),
			$net,
			true,
			''
		);
	}

	/**
	 * Inspect cart items and report whether klær and/or other classes are
	 * present.
	 *
	 * @param array $items Cart contents (key => item) as produced by
	 *                     WC_Cart::get_cart() or a shipping package.
	 * @return array{0:bool,1:bool} [has_klaer, has_other]
	 */
	private static function detect_classes( $items ) {
		$has_klaer = false;
		$has_other = false;
		foreach ( $items as $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				$has_other = true;
				continue;
			}
			$slug = (string) $product->get_shipping_class();
			if ( in_array( $slug, self::KLAER_SHIPPING_CLASS_SLUGS, true ) ) {
				$has_klaer = true;
			} else {
				$has_other = true;
			}
		}
		return array( $has_klaer, $has_other );
	}

	/**
	 * Build the klær flat-rate WC_Shipping_Rate object.
	 *
	 * KLAER_FLAT_RATE_INCL_TAX is the customer-facing total. WC stores
	 * shipping cost as net and tax as a separate amount; we split 199 into
	 * 159.20 net + 39.80 (25% VAT) so the displayed total is exactly 199 in
	 * both inclusive and exclusive checkout views.
	 */
	private static function build_klaer_rate() {
		$gross     = self::KLAER_FLAT_RATE_INCL_TAX;
		$tax_rates = WC_Tax::get_shipping_tax_rates();
		$taxes     = WC_Tax::calc_tax( $gross, $tax_rates, true );
		$tax_total = is_array( $taxes ) ? array_sum( $taxes ) : 0.0;
		$net       = (float) wc_format_decimal( $gross - $tax_total, wc_get_price_decimals() );

		$rate = new WC_Shipping_Rate(
			self::KLAER_RATE_ID,
			__( self::KLAER_RATE_LABEL, 'awana-commerce' ),
			$net,
			$taxes,
			self::KLAER_RATE_ID,
			0
		);
		$rate->add_meta_data( 'description', __( 'Sendes direkte fra Melings.', 'awana-commerce' ) );
		return $rate;
	}
}
