<?php
/**
 * Unit tests: CodShield\Core::substitute_placeholders().
 *
 * This helper backs both the checkout messaging (format_message) and the
 * order-email summary heading, so it must be solid on its own.
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder substitution tests.
 */
class PlaceholderTest extends TestCase {

	public function testReplacesKnownTokens() {
		$this->assertSame(
			'A 1 B 2 C',
			Core::substitute_placeholders( 'A {x} B {y} C', array( 'x' => '1', 'y' => '2' ) )
		);
	}

	public function testLeavesUnknownTokensUntouched() {
		$this->assertSame( 'hi {missing}', Core::substitute_placeholders( 'hi {missing}', array() ) );
	}

	public function testEmptyTemplate() {
		$this->assertSame( '', Core::substitute_placeholders( '', array( 'x' => '1' ) ) );
	}

	public function testCastsNonStringValuesToString() {
		$this->assertSame( 'v=10', Core::substitute_placeholders( 'v={n}', array( 'n' => 10 ) ) );
	}

	public function testHandlesEmptyReplacementValue() {
		$this->assertSame( 'adv= done', Core::substitute_placeholders( 'adv={a} done', array( 'a' => '' ) ) );
	}

	public function testHandlesDecimalAndCurrencyLikeStrings() {
		// Real usage passes wc_price() output (HTML) or a plain number — both are opaque strings here.
		$this->assertSame( 'pay <bdi>₹99.00</bdi> now', Core::substitute_placeholders( 'pay {amt} now', array( 'amt' => '<bdi>₹99.00</bdi>' ) ) );
	}
}
