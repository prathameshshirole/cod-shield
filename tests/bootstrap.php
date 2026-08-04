<?php
/**
 * PHPUnit bootstrap — loads the pure-logic classes without WordPress.
 *
 * The calculation engines (CodShield\Core::calculate_advance() /
 * calculate_fee()) are dependency-free, so unit tests can run in a plain
 * PHP environment. The main plugin file is NOT loaded here (it needs WP).
 *
 * @package COD_Shield
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

/*
 * Minimal polyfills so the pure-logic classes can be exercised without loading
 * WordPress. __() is a passthrough; other i18n/escape helpers are not needed
 * by the unit-tested code paths.
 */
if ( ! function_exists( '__' ) ) {
	/**
	 * Passthrough translation shim for unit tests.
	 *
	 * @param string $text   Text.
	 * @param string $domain Domain (unused).
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-cod-shield-core.php';
