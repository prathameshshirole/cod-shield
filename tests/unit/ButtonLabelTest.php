<?php
/**
 * Unit tests: CodShield\Core button-label helpers used by
 * Checkout::filter_order_button_text().
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * Button label price replacement / decimal detection tests.
 */
class ButtonLabelTest extends TestCase {

	/**
	 * Two real non-breaking spaces (U+00A0) — the separator themes use when
	 * appending the total to the "Place order" button label.
	 *
	 * @var string
	 */
	private const NBSP = "\xC2\xA0\xC2\xA0";

	public function testAdvanceButtonConfirmsCodWithExactAmount() {
		$this->assertSame( 'Pay ₹240 & confirm COD', Core::advance_button_label( '₹240' ) );
	}

	public function testAdvanceCheckoutTitleLeadsWithExactAmount() {
		$this->assertSame( 'Confirm COD with ₹240 now', Core::advance_checkout_title( '₹240' ) );
	}

	/*
	 * replace_button_price()
	 */

	public function testReplacesTrailingPriceWithNbspSeparator() {
		$this->assertSame(
			'Place Order' . self::NBSP . '₹104',
			Core::replace_button_price( 'Place Order' . self::NBSP . '₹1,042', '₹104' )
		);
	}

	public function testReplacesTrailingPriceWithSpaceSeparator() {
		$this->assertSame(
			'Place Order ₹104',
			Core::replace_button_price( 'Place Order ₹1,042', '₹104' )
		);
	}

	public function testReplacesTrailingPriceWithLiteralNbspEntity() {
		$this->assertSame(
			'Place Order&nbsp;&nbsp;₹104',
			Core::replace_button_price( 'Place Order&nbsp;&nbsp;₹1,042', '₹104' )
		);
	}

	public function testReplacesRightPositionCurrencySymbol() {
		$this->assertSame(
			'Pay ' . self::NBSP . '104₹',
			Core::replace_button_price( 'Pay ' . self::NBSP . '1,042₹', '104₹' )
		);
	}

	public function testPreservesDecimalPrecisionOfReplacement() {
		$this->assertSame(
			'Place Order ' . self::NBSP . '$104.20',
			Core::replace_button_price( 'Place Order ' . self::NBSP . '$1,042.50', '$104.20' )
		);
	}

	public function testDollarInReplacementIsNotABackreference() {
		// "US$104" contains "$1" — must survive preg_replace untouched.
		$this->assertSame(
			'Place Order' . self::NBSP . 'US$104',
			Core::replace_button_price( 'Place Order' . self::NBSP . '₹1,042', 'US$104' )
		);
	}

	public function testBarePriceLabelIsReplaced() {
		$this->assertSame( '₹104', Core::replace_button_price( '₹1,042', '₹104' ) );
	}

	public function testLabelWithoutPriceIsUnchanged() {
		$this->assertSame(
			'Place Order',
			Core::replace_button_price( 'Place Order', '₹104' )
		);
	}

	public function testNonPriceSuffixIsUnchanged() {
		$this->assertSame(
			'Place Order now',
			Core::replace_button_price( 'Place Order now', '₹104' )
		);
	}

	public function testStripsEveryTrailingPriceBeforeAppending() {
		// CartFlows appends the full cart total AFTER the plugin's priority-20
		// pass already replaced a theme-appended price -> "… ₹99 ₹1,042". Only
		// the final amount (the advance) may remain.
		$this->assertSame(
			'Place Order ₹99',
			Core::replace_button_price( 'Place Order ₹99 ₹1,042', '₹99' )
		);
	}

	public function testStripsEveryTrailingPriceWithNbspSeparators() {
		$this->assertSame(
			'Place Order&nbsp;&nbsp;₹99',
			Core::replace_button_price( 'Place Order&nbsp;&nbsp;₹99&nbsp;&nbsp;₹1,042', '₹99' )
		);
	}

	public function testRepeatedApplicationIsIdempotent() {
		// The priority-1000 late pass re-runs the same replacement; applying
		// it twice must not change the result.
		$once  = Core::replace_button_price( 'Place Order&nbsp;&nbsp;₹1,042', '₹99' );
		$twice = Core::replace_button_price( $once, '₹99' );
		$this->assertSame( $once, $twice );
		$this->assertSame( 'Place Order&nbsp;&nbsp;₹99', $twice );
	}

	/*
	 * append_button_price()
	 */

	public function testAppendsWithCartflowsNbspSeparator() {
		// CartFlows appends the total with exactly this separator when the
		// step's "show price on button" option is on; the append path must
		// match it so the forced price looks identical.
		$this->assertSame(
			'Place Order&nbsp;&nbsp;₹104',
			Core::append_button_price( 'Place Order', '₹104' )
		);
	}

	public function testAppendsToCustomCartflowsButtonText() {
		$this->assertSame(
			'Buy Now&nbsp;&nbsp;₹99',
			Core::append_button_price( 'Buy Now', '₹99' )
		);
	}

	public function testAppendIsNotBackreferenceSensitive() {
		// Plain concatenation — a literal "$" in the amount must survive.
		$this->assertSame(
			'Place Order&nbsp;&nbsp;US$104',
			Core::append_button_price( 'Place Order', 'US$104' )
		);
	}

	public function testAppendToBarePriceLabel() {
		$this->assertSame(
			'₹104&nbsp;&nbsp;₹104',
			Core::append_button_price( '₹104', '₹104' )
		);
	}

	/*
	 * label_price_decimals()
	 */

	public function testNoDecimalsForThousandsSeparatedPrice() {
		$this->assertSame( 0, Core::label_price_decimals( 'Place Order' . self::NBSP . '₹1,042' ) );
	}

	public function testTwoDecimalsForDotPrice() {
		$this->assertSame( 2, Core::label_price_decimals( 'Pay ₹1,042.50' ) );
	}

	public function testTwoDecimalsFallbackWhenNoPrice() {
		$this->assertSame( 2, Core::label_price_decimals( 'Place Order' ) );
	}

	public function testZeroDecimalsForBarePrice() {
		$this->assertSame( 0, Core::label_price_decimals( '₹1,042' ) );
	}

	public function testCommaDecimalCountsDecimals() {
		$this->assertSame( 2, Core::label_price_decimals( 'x ' . self::NBSP . '104,25₹' ) );
	}

	public function testZeroDecimalsForIntegerPrice() {
		$this->assertSame( 0, Core::label_price_decimals( 'y ₹104' ) );
	}

	public function testLakhFormatHasZeroDecimals() {
		$this->assertSame( 0, Core::label_price_decimals( '1,00,000₹' ) );
	}
}
