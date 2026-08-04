<?php
/**
 * Unit tests: CodShield\Core::calculate_fee().
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * Extra COD fee engine tests (features.md).
 */
class CalculateFeeTest extends TestCase {

	/**
	 * Baseline settings fixture.
	 *
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'fee_enabled'    => 'yes',
				'fee_type'       => 'fixed',
				'fee_amount'     => 30,
				'fee_percentage' => 2,
			),
			$overrides
		);
	}

	public function testDisabledFeeReturnsZero() {
		$this->assertSame( 0.0, Core::calculate_fee( 1000, $this->settings( array( 'fee_enabled' => 'no' ) ) ) );
	}

	public function testZeroTotalReturnsZero() {
		$this->assertSame( 0.0, Core::calculate_fee( 0, $this->settings() ) );
	}

	public function testFixedFee() {
		$this->assertSame( 30.0, Core::calculate_fee( 500, $this->settings() ) );
	}

	public function testPercentageFee() {
		$settings = $this->settings( array( 'fee_type' => 'percentage' ) );
		$this->assertSame( 10.0, Core::calculate_fee( 500, $settings ) );
	}

	public function testZeroPercentageReturnsZero() {
		$settings = $this->settings( array( 'fee_type' => 'percentage', 'fee_percentage' => 0 ) );
		$this->assertSame( 0.0, Core::calculate_fee( 500, $settings ) );
	}

	public function testPercentageRoundsToTwoDecimals() {
		$settings = $this->settings( array( 'fee_type' => 'percentage' ) );
		$this->assertSame( 12.34, Core::calculate_fee( 617, $settings ) );
	}

	public function testFractionalPercentage() {
		$settings = $this->settings( array( 'fee_type' => 'percentage', 'fee_percentage' => 1.5 ) );
		$this->assertSame( 1.5, Core::calculate_fee( 100, $settings ) );
	}
}
