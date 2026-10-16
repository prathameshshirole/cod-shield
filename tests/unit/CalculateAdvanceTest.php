<?php
/**
 * Unit tests: CodShield\Core::calculate_advance().
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * Advance calculation engine tests.
 */
class CalculateAdvanceTest extends TestCase {

	/**
	 * Baseline settings fixture.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'mode'      => 'fixed',
				'fixed'     => 99,
				'percentage' => 10,
				'min'       => 49,
				'max'       => 999,
				'threshold' => 0,
			),
			$overrides
		);
	}

	public function testDisabledModeReturnsZero() {
		$this->assertSame( 0.0, Core::calculate_advance( 1000, $this->settings( array( 'mode' => 'disabled' ) ) ) );
	}

	public function testZeroAndNegativeTotalsReturnZero() {
		$this->assertSame( 0.0, Core::calculate_advance( 0, $this->settings() ) );
		$this->assertSame( 0.0, Core::calculate_advance( -50, $this->settings() ) );
	}

	public function testBelowThresholdReturnsZero() {
		$settings = $this->settings( array( 'threshold' => 500 ) );
		$this->assertSame( 0.0, Core::calculate_advance( 400, $settings ) );
	}

	public function testAtThresholdApplies() {
		$settings = $this->settings( array( 'threshold' => 500 ) );
		$this->assertSame( 99.0, Core::calculate_advance( 500, $settings ) );
	}

	public function testFixedAmountExact() {
		$this->assertSame( 99.0, Core::calculate_advance( 1000, $this->settings() ) );
	}

	public function testFixedClampedToMin() {
		$this->assertSame( 100.0, Core::calculate_advance( 1000, $this->settings( array( 'min' => 100 ) ) ) );
	}

	public function testFixedClampedToMax() {
		$this->assertSame( 50.0, Core::calculate_advance( 1000, $this->settings( array( 'max' => 50 ) ) ) );
	}

	public function testPercentageOfTotal() {
		$settings = $this->settings( array( 'mode' => 'percentage', 'min' => 0, 'max' => 0 ) );
		$this->assertSame( 100.0, Core::calculate_advance( 1000, $settings ) );
		$this->assertSame( 30.0, Core::calculate_advance( 300, $settings ) );
	}

	public function testFreshInstallLimitsDoNotChangeLiteralValues() {
		$this->assertSame( 25.0, Core::calculate_advance( 300, $this->settings( array( 'fixed' => 25, 'min' => 0, 'max' => 0 ) ) ) );
		$this->assertSame( 30.0, Core::calculate_advance( 300, $this->settings( array( 'mode' => 'percentage', 'min' => 0, 'max' => 0 ) ) ) );
		$this->assertSame( 2000.0, Core::calculate_advance( 20000, $this->settings( array( 'mode' => 'percentage', 'min' => 0, 'max' => 0 ) ) ) );
	}

	public function testPercentageFlooredToMin() {
		$settings = $this->settings( array( 'mode' => 'percentage' ) );
		$this->assertSame( 49.0, Core::calculate_advance( 300, $settings ) ); // 30 -> min 49.
	}

	public function testPercentageCappedToMax() {
		$settings = $this->settings( array( 'mode' => 'percentage' ) );
		$this->assertSame( 999.0, Core::calculate_advance( 20000, $settings ) ); // 2000 -> max 999.
	}

	public function testRoundingToTwoDecimals() {
		$settings = $this->settings( array( 'mode' => 'percentage', 'percentage' => 33.333, 'min' => 0, 'max' => 0 ) );
		$this->assertSame( 33.33, Core::calculate_advance( 100, $settings ) );
	}

	public function testNeverExceedsOrderTotal() {
		$this->assertSame( 100.0, Core::calculate_advance( 100, $this->settings( array( 'fixed' => 5000 ) ) ) );
	}

	public function testZeroPercentageReturnsZero() {
		$settings = $this->settings( array( 'mode' => 'percentage', 'percentage' => 0, 'min' => 0, 'max' => 0 ) );
		$this->assertSame( 0.0, Core::calculate_advance( 1000, $settings ) );
	}
}
