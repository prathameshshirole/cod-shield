<?php
/**
 * "PDF Invoices & Packing Slips for WooCommerce" compatibility.
 *
 * Renders the COD advance split on the invoice totals: how much was paid
 * online when the order was placed and how much remains due on delivery.
 * Without this, a finalized advance order invoices as a plain COD order and
 * the totals give no indication that part of the amount was already collected
 * (or how much is still due at the door).
 *
 * The integration is inert unless the PDF plugin is active: hooks are only
 * registered when the plugin's v3 document classes exist, so this file never
 * references the PDF plugin's classes directly and there is no runtime
 * dependency on it.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * PDF Invoices & Packing Slips compatibility.
 */
class PDF_Support {

	/**
	 * Hook registration.
	 */
	public function __construct() {
		// Only hook when the PDF plugin (v3+) is actually loaded. The class
		// check covers namespaced v3 installs; the legacy v2 namespace simply
		// never matches, so the integration degrades to a no-op there.
		if ( ! class_exists( '\WPO\IPS\Documents\OrderDocument' ) ) {
			return;
		}

		// Append the advance/balance split to the invoice totals.
		add_filter( 'wpo_wcpdf_woocommerce_totals', array( $this, 'add_advance_totals' ), 10, 3 );
	}

	/**
	 * Append "advance paid online" / "balance due on delivery" rows to the
	 * invoice totals, and guard the order total against mid-flight documents.
	 *
	 * Packing slips render no totals table, so this only ever affects
	 * documents that actually print totals; it is gated to the invoice so a
	 * credit note or other custom document never inherits the split.
	 *
	 * @param array           $totals        Totals rows.
	 * @param \WC_Order|mixed $order         Order object.
	 * @param string          $document_type Document type (invoice, packing-slip, …).
	 * @return array
	 */
	public function add_advance_totals( $totals, $order, $document_type ) {
		if ( 'invoice' !== $document_type || ! is_a( $order, '\WC_Order' ) ) {
			return $totals;
		}

		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() ) {
			return $totals;
		}
		if ( 'yes' === $order->get_meta( Core::META_AWAITING ) && isset( $totals['order_total'] ) ) {
			$totals['order_total']['value'] = wc_price( $view->total, array( 'currency' => $view->currency ) );
		}
		foreach ( Emails::summary_rows( $view ) as $key => $row ) {
			$totals[ 'cod_shield_' . $key ] = array(
				'label' => $row['label'],
				'value' => wc_price( $row['amount'], array( 'currency' => $view->currency ) ),
			);
		}

		return $totals;
	}
}
