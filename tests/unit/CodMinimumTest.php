<?php
/**
 * Unit tests: CodShield\Core::is_below_cod_minimum().
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * COD minimum order value tests.
 */
class CodMinimumTest extends TestCase {

	/**
	 * Baseline settings fixture.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array( 'threshold' => 500 ),
			$overrides
		);
	}

	public function testZeroThresholdMeansNoMinimum() {
		$this->assertFalse( Core::is_below_cod_minimum( 100, $this->settings( array( 'threshold' => 0 ) ) ) );
		$this->assertFalse( Core::is_below_cod_minimum( 1000, $this->settings( array( 'threshold' => 0 ) ) ) );
	}

	public function testBelowThresholdIsHidden() {
		$this->assertTrue( Core::is_below_cod_minimum( 499, $this->settings() ) );
		$this->assertTrue( Core::is_below_cod_minimum( 0, $this->settings() ) );
	}

	public function testAtAndAboveThresholdIsAllowed() {
		$this->assertFalse( Core::is_below_cod_minimum( 500, $this->settings() ) );
		$this->assertFalse( Core::is_below_cod_minimum( 500.01, $this->settings() ) );
		$this->assertFalse( Core::is_below_cod_minimum( 2000, $this->settings() ) );
	}

	public function testNegativeTotalIsHidden() {
		$this->assertTrue( Core::is_below_cod_minimum( -10, $this->settings() ) );
	}
}
