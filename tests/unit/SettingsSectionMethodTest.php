<?php
/**
 * Unit tests: CodShield\Core::settings_method_for_section().
 *
 * Regression guard for the "Extra COD Fee / Order Handling tabs render empty"
 * bug: WC_Settings_Page builds the settings method as
 * get_settings_for_{section}_section, and a PHP method name cannot contain a
 * hyphen, so hyphenated section slugs (cod-fee, order-handling) used to fall
 * through to the empty section registry. The resolver maps hyphens to
 * underscores so the declared methods are found.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\TestCase;

/**
 * Settings section method resolution tests.
 */
class SettingsSectionMethodTest extends TestCase {

	public function testDefaultSection() {
		$this->assertSame( 'get_settings_for_default_section', Core::settings_method_for_section( '' ) );
	}

	public function testPlainSlugIsUntouched() {
		$this->assertSame( 'get_settings_for_advance_section', Core::settings_method_for_section( 'advance' ) );
		$this->assertSame( 'get_settings_for_messaging_section', Core::settings_method_for_section( 'messaging' ) );
	}

	public function testHyphenatedSlugMapsToUnderscoreMethod() {
		$this->assertSame( 'get_settings_for_cod_fee_section', Core::settings_method_for_section( 'cod-fee' ) );
		$this->assertSame( 'get_settings_for_order_handling_section', Core::settings_method_for_section( 'order-handling' ) );
	}

	public function testUnderscoreSlugIsNormalizedToo() {
		// sanitize_title() turns underscores into hyphens, so both forms must
		// resolve to the same (hyphen-free) method name.
		$this->assertSame( Core::settings_method_for_section( 'cod-fee' ), Core::settings_method_for_section( 'cod_fee' ) );
	}

	/**
	 * Every section declared by the settings page must resolve to a method
	 * that actually exists in class-cod-shield-settings.php, or its tab
	 * renders empty. Static source check (no WordPress / WooCommerce needed).
	 */
	public function testEveryDeclaredSectionResolvesToAnExistingMethod() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-settings.php' );
		$this->assertIsString( $source );

		// Keep in sync with Settings::get_own_sections().
		// Underscore slugs: WC builds the settings method as
		// get_settings_for_{section}_section and a PHP method name cannot
		// contain a hyphen, so the slugs must resolve to their underscore form.
		$sections = array( '', 'advance', 'cod_fee', 'messaging', 'order_handling' );
		foreach ( $sections as $section ) {
			$method = Core::settings_method_for_section( $section );
			$this->assertStringContainsString(
				'function ' . $method,
				$source,
				'Section "' . $section . '" resolves to missing method ' . $method
			);
		}
	}
}
