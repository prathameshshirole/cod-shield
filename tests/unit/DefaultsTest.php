<?php
/**
 * Unit tests: fresh-install defaults (CodShield\Core::get_defaults()).
 *
 * Regression guards for two live-site findings:
 *  - the order-value threshold must default to 0 (no minimum) so activating
 *    the plugin never silently hides small COD orders — even for merchants who
 *    only want the kill switch or the advance feature — and
 *  - the order status applied after the advance is paid defaults to
 *    'processing' (the advance was already collected online), so the customer
 *    does not receive a processing/payment-received email while the order
 *    actually lands on hold.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use CodShield\Eligibility;
use PHPUnit\Framework\TestCase;

/**
 * Defaults tests.
 */
class DefaultsTest extends TestCase {

	public function testThresholdDefaultsToNoMinimum() {
		$defaults = Core::get_defaults();
		$this->assertSame( 0, $defaults[ Core::OPT_THRESHOLD ] );
	}

	public function testOrderStatusDefaultsToProcessing() {
		$defaults = Core::get_defaults();
		$this->assertSame( 'processing', $defaults[ Core::OPT_ORDER_STATUS ] );
	}

	public function testKillSwitchDefaultsToEnabled() {
		$defaults = Core::get_defaults();
		$this->assertSame( 'yes', $defaults[ Core::OPT_ENABLED ] );
	}

	public function testCodTitleDefaultsToNativeGatewayTitle() {
		$defaults = Core::get_defaults();
		$this->assertSame( '', $defaults[ Core::OPT_COD_TITLE ] );
	}

	public function testAdvanceModeDefaultsToDisabled() {
		$defaults = Core::get_defaults();
		$this->assertSame( 'disabled', $defaults[ Core::OPT_MODE ] );
	}

	public function testEligibilityRulesDefaultToDisabled() {
		$defaults = Core::get_defaults();
		$this->assertSame( 0, $defaults[ Eligibility::OPT_MAXIMUM ] );
		$this->assertSame( '', $defaults[ Eligibility::OPT_EXCLUDED_PINCODES ] );
	}
}
