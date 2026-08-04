<?php
/**
 * Unit tests: checkout advance-notice highlight.
 *
 * Regression guard for the "advance message is plain, easy-to-miss text at
 * checkout" complaint. The message line ("An advance of ₹99 secures this
 * order. Pay ₹1,727 in cash on delivery.") must render inside a highlighted,
 * bold callout (class `cod-shield-advance-notice`) on BOTH the classic and
 * the block checkout, and the stylesheet that styles it must be enqueued on
 * the storefront.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Checkout advance-notice highlight tests (static source checks, no WordPress).
 */
class AdvanceNoticeTest extends TestCase {

	/**
	 * The plugin root directory.
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Read a plugin source file.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function read_source( $relative ) {
		$file   = $this->plugin_dir() . '/' . $relative;
		$source = file_get_contents( $file );
		$this->assertIsString( $source, "$relative should be readable." );
		return $source;
	}

	/**
	 * Classic checkout: the proxy gateway's get_description() must wrap the
	 * advance message (and its no-message fallback) in the callout.
	 */
	public function testClassicCheckoutWrapsMessageInNotice() {
		$source = $this->read_source( 'includes/class-cod-shield-gateway-cod-advance.php' );
		$this->assertSame(
			2,
			substr_count( $source, '<div class="cod-shield-advance-notice">' ),
			'get_description() must wrap both the configured message and the fallback in the notice callout.'
		);
	}

	/**
	 * Block checkout: the payment-method data must wrap the message in the
	 * same callout so both checkouts render identically.
	 */
	public function testBlockCheckoutWrapsMessageInNotice() {
		$source = $this->read_source( 'includes/class-cod-shield-blocks-payment-method-type.php' );
		$this->assertStringContainsString(
			'<div class="cod-shield-advance-notice">',
			$source,
			'The block payment-method data must wrap the advance message in the notice callout.'
		);
	}

	/**
	 * The frontend stylesheet must exist, style the callout, and be enqueued
	 * on checkout pages. (Styles previously lived in admin.css, which never
	 * loads on the storefront — the notice rendered plain.)
	 */
	public function testFrontendStylesheetExistsAndIsEnqueued() {
		$css = $this->read_source( 'public/css/checkout.css' );
		$this->assertStringContainsString(
			'.cod-shield-advance-notice',
			$css,
			'The checkout stylesheet must contain the advance-notice rule.'
		);
		// Bold + colour: the two required visual cues (not colour alone).
		$this->assertMatchesRegularExpression(
			'/font-weight:\s*(600|700|bold)/',
			$css,
			'The advance-notice rule must set a bold font weight.'
		);
		$this->assertMatchesRegularExpression(
			'/background:\s*#[0-9a-fA-F]{6}/',
			$css,
			'The advance-notice rule must set a colour highlight background.'
		);

		$ajax = $this->read_source( 'includes/class-cod-shield-ajax.php' );
		$this->assertStringContainsString(
			"wp_enqueue_style(\n\t\t\t'cod-shield-checkout',\n\t\t\tCOD_SHIELD_URL . 'public/css/checkout.css'",
			$ajax,
			'The checkout stylesheet must be enqueued on checkout pages.'
		);
	}

	/**
	 * The risk-free info box styles must also live in the frontend sheet —
	 * they are referenced by public/js/checkout.js on the classic checkout.
	 */
	public function testRiskFreeBoxStylesMovedToFrontendSheet() {
		$css = $this->read_source( 'public/css/checkout.css' );
		$this->assertStringContainsString(
			'.cod-shield-info-box',
			$css,
			'The .cod-shield-info-box rules used by the classic checkout must live in the frontend stylesheet.'
		);

		$admin = $this->read_source( 'admin/css/admin.css' );
		$this->assertStringNotContainsString(
			'.cod-shield-info-box',
			$admin,
			'admin.css is admin-only; the checkout info-box rules must not live there.'
		);
	}
}
