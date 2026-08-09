<?php
/**
 * Unit tests for the minimal settings setup flow.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

namespace CodShield\Tests;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Settings setup UX regression tests (static source checks, no WordPress).
 */
class SettingsSetupUxTest extends TestCase {

	/**
	 * Get the settings class source.
	 *
	 * @return string
	 */
	private function settings_source(): string {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-settings.php' );
		$this->assertIsString( $source, 'Settings source should be readable.' );
		return $source;
	}

	#[TestDox( 'The main tab exposes a three-step setup and health panel' )]
	public function test_main_tab_contains_three_steps_and_status(): void {
		$source = $this->settings_source();

		$this->assertStringContainsString( "''               => __( 'Setup', 'cod-shield' )", $source );
		$this->assertStringContainsString( "__( '1. Enable Cash on Delivery', 'cod-shield' )", $source );
		$this->assertStringContainsString( "__( '2. Choose the advance', 'cod-shield' )", $source );
		$this->assertStringContainsString( "__( '3. Select an advance gateway', 'cod-shield' )", $source );
		$this->assertStringContainsString( "'type' => 'cod_shield_configuration_status'", $source );
	}

	#[TestDox( 'The gateway UI is a single compatible gateway selector with legacy storage support' )]
	public function test_gateway_selector_is_single_and_backward_compatible(): void {
		$source = $this->settings_source();

		$this->assertStringContainsString( "'type'     => 'cod_shield_gateway_select'", $source );
		$this->assertStringContainsString( "__( 'Select a tested gateway…', 'cod-shield' )", $source );
		$this->assertStringNotContainsString( "'type'     => 'multiselect'", $source );
		$this->assertStringContainsString( '$candidates = is_array( $raw_value ) ? $raw_value : array( $raw_value );', $source );
		$this->assertStringContainsString( 'return array( $gateway_id );', $source );
		$this->assertStringContainsString( 'Core::assess_gateway_compatibility(', $source );
	}

	#[TestDox( 'Invalid native COD and advance gateway configurations display warnings' )]
	public function test_configuration_warnings_are_registered(): void {
		$source = $this->settings_source();

		$this->assertStringContainsString( "add_action( 'admin_notices', array( \$this, 'handle_admin_configuration_notices' ) );", $source );
		$this->assertStringContainsString( 'WooCommerce Cash on Delivery is disabled, so COD cannot appear at checkout.', $source );
		$this->assertStringContainsString( 'Advance payment is enabled, but no eligible gateway is selected.', $source );
	}

	#[TestDox( 'Advanced rules use unambiguous COD value labels and India-focused eligibility fields' )]
	public function test_advanced_rules_include_clear_eligibility_controls(): void {
		$source = $this->settings_source();

		$this->assertStringContainsString( "__( 'Minimum order value for COD', 'cod-shield' )", $source );
		$this->assertStringContainsString( "__( 'Maximum order value for COD', 'cod-shield' )", $source );
		$this->assertStringContainsString( "__( 'Excluded delivery pincodes', 'cod-shield' )", $source );
		$this->assertStringContainsString( "preg_replace( '/[^A-Z0-9*,\\r\\n ]+/', ' ', \$rules )", $source );
	}
}
