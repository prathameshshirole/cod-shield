<?php
/**
 * Unit tests: SureOrder General settings tab banner.
 *
 * Regression guard for the "raw HTML printed on the General settings tab"
 * bug: the section `title` of a WC settings page is rendered by WooCommerce
 * with esc_html() (WC_Admin_Settings::output_fields()), so an <img> inside
 * it was displayed as literal text. The banner logo now lives in the `desc`
 * field — which WC renders through wp_kses_post() — and is served from
 * admin/img/, a folder that ships with the plugin, never from
 * .wordpress-org/, the WP.org listing-artwork folder that is not part of an
 * installed plugin.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Settings General-tab banner tests (static source checks, no WordPress).
 */
class SettingsGeneralBannerTest extends TestCase {

	/**
	 * The plugin root directory.
	 *
	 * @return string
	 */
	private function plugin_dir() {
		return dirname( __DIR__, 2 );
	}

	/**
	 * The settings class source.
	 *
	 * @return string
	 */
	private function settings_source() {
		$file   = $this->plugin_dir() . '/includes/class-cod-shield-settings.php';
		$source = file_get_contents( $file );
		$this->assertIsString( $source, 'Settings source should be readable.' );
		return $source;
	}

	/**
	 * The body of get_settings_for_default_section().
	 *
	 * @return string
	 */
	private function general_section_source() {
		$source = $this->settings_source();
		preg_match( '/function get_settings_for_default_section\(\).*?return array\((.*?)\);\n\t\}/s', $source, $m );
		$this->assertNotEmpty( $m, 'get_settings_for_default_section() body not found in settings source.' );
		return $m[1];
	}

	/**
	 * The General section title must be plain text: WooCommerce escapes the
	 * `title` of a settings field with esc_html(), so markup is printed as
	 * literal text on the settings page.
	 */
	public function testGeneralSectionTitleIsPlainText() {
		$body = $this->general_section_source();
		preg_match( "/'title'\s*=>\s*(.*?),\n/", $body, $m );
		$this->assertNotEmpty( $m, 'General section title field not found.' );
		$this->assertStringNotContainsString( '<', $m[1], 'Section titles are esc_html()-ed by WooCommerce; HTML would render as raw text.' );
	}

	/**
	 * The banner logo must live in the `desc` field (rendered through
	 * wp_kses_post()) and be served from admin/img/, which ships with the
	 * plugin.
	 */
	public function testBannerLogoLivesInDescAndShipsWithPlugin() {
		$body = $this->general_section_source();
		$this->assertStringContainsString(
			"'desc'  => '<img src=\"' . esc_url( COD_SHIELD_URL . 'admin/img/icon-128x128.png' )",
			$body,
			'The banner logo must be in the desc field and served from admin/img/.'
		);
		$this->assertFileExists(
			$this->plugin_dir() . '/admin/img/icon-128x128.png',
			'The icon referenced by the settings page must ship inside the plugin.'
		);
	}

	/**
	 * No runtime code may reference .wordpress-org/: that folder holds the
	 * WP.org listing artwork and is not part of an installed plugin, so such
	 * references 404 on real installs. Only quoted string literals count —
	 * explanatory comments may mention the folder name.
	 */
	public function testNoRuntimeReferencesToWordPressOrgAssetsFolder() {
		$source = $this->settings_source();
		$this->assertStringNotContainsString(
			"'.wordpress-org",
			$source,
			'.wordpress-org/ is not shipped with the plugin; runtime string references would 404.'
		);
	}
}
