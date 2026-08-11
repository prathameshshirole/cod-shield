<?php
/**
 * Integration coverage for the PDF Invoices & Packing Slips compatibility.
 *
 * The filter callback is exercised directly against a real WC_Order. The PDF
 * plugin itself is not installed in the test environment: the callback only
 * reads order meta and formats prices, so it runs identically whether or not
 * the plugin is present (the constructor's class check is the only guard that
 * depends on it).
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

use CodShield\Core;
use CodShield\PDF_Support;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PDF Invoices & Packing Slips integration tests.
 */
class Cod_Shield_Pdf_Support_Test extends WP_UnitTestCase {

	#[TestDox( 'A finalized advance order shows advance and balance rows on the invoice totals' )]
	public function test_finalized_order_shows_advance_split(): void {
		$order = $this->create_advance_order( 20, 100, 80, 'paid', 'no' );
		$totals = array(
			'order_total' => array(
				'label' => 'Total',
				'value' => wc_price( 100, array( 'currency' => $order->get_currency() ) ),
			),
		);

		$totals = $this->pdf_support()->add_advance_totals( $totals, $order, 'invoice' );

		$this->assertArrayHasKey( 'advance_paid', $totals );
		$this->assertArrayHasKey( 'balance_due', $totals );
		$this->assertStringContainsString( '20.00', wp_strip_all_tags( $totals['advance_paid']['value'] ) );
		$this->assertStringContainsString( '80.00', wp_strip_all_tags( $totals['balance_due']['value'] ) );
		$this->assertStringContainsString( '100.00', wp_strip_all_tags( $totals['order_total']['value'] ) );
	}

	#[TestDox( 'A mid-flight document shows the original total instead of the transient advance-only total' )]
	public function test_awaiting_order_invoice_shows_original_total(): void {
		$order = $this->create_advance_order( 20, 100, 80, 'pending', 'yes' );
		$order->set_total( 20 ); // Transient advance-only total while awaiting.
		$order->save();
		$totals = array(
			'order_total' => array(
				'label' => 'Total',
				'value' => wc_price( 20, array( 'currency' => $order->get_currency() ) ),
			),
		);

		$totals = $this->pdf_support()->add_advance_totals( $totals, $order, 'invoice' );

		$this->assertStringContainsString( '100.00', wp_strip_all_tags( $totals['order_total']['value'] ) );
		$this->assertArrayHasKey( 'advance_paid', $totals );
		$this->assertArrayHasKey( 'balance_due', $totals );
	}

	#[TestDox( 'Orders without an advance leave the invoice totals untouched' )]
	public function test_non_advance_order_untouched(): void {
		$order  = wc_create_order();
		$order->set_total( 100 );
		$order->save();
		$totals = array( 'order_total' => array( 'label' => 'Total', 'value' => 'x' ) );

		$this->assertSame( $totals, $this->pdf_support()->add_advance_totals( $totals, $order, 'invoice' ) );
	}

	#[TestDox( 'Non-invoice documents are never modified' )]
	public function test_non_invoice_document_untouched(): void {
		$order  = $this->create_advance_order( 20, 100, 80, 'paid', 'no' );
		$totals = array( 'order_total' => array( 'label' => 'Total', 'value' => 'x' ) );

		$this->assertSame( $totals, $this->pdf_support()->add_advance_totals( $totals, $order, 'packing-slip' ) );
	}

	/**
	 * Create an order seeded with advance meta.
	 *
	 * @param float  $advance  Advance amount.
	 * @param float  $original Original order total.
	 * @param float  $remaining Remaining amount.
	 * @param string $status   META_STATUS value.
	 * @param string $awaiting META_AWAITING value.
	 * @return WC_Order
	 */
	private function create_advance_order( float $advance, float $original, float $remaining, string $status, string $awaiting ): WC_Order {
		$order = wc_create_order();
		$order->set_total( $original );
		$order->update_meta_data( Core::META_ADVANCE, $advance );
		$order->update_meta_data( Core::META_ORIGINAL_TOTAL, $original );
		$order->update_meta_data( Core::META_REMAINING, $remaining );
		$order->update_meta_data( Core::META_STATUS, $status );
		$order->update_meta_data( Core::META_AWAITING, $awaiting );
		$order->save();
		return $order;
	}

	/** @return PDF_Support */
	private function pdf_support(): PDF_Support {
		$reflection = new ReflectionClass( PDF_Support::class );
		return $reflection->newInstanceWithoutConstructor();
	}
}
