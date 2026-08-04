<?php
/**
 * Plugin Name:       COD Shield for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/cod-shield/
 * Description:       Reduce COD RTO by collecting a partial advance payment through existing WooCommerce gateways (Razorpay, Cashfree, etc.). Includes a global COD kill switch, configurable advance rules, an extra COD fee, checkout messaging, and full admin order visibility.
 * Version:           2.0.0
 * Author:            Prathamesh Shirole
 * Author URI:        https://profiles.wordpress.org/prathaverse/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       cod-shield
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 *
 * @package COD_Shield
 */

defined( 'ABSPATH' ) || exit;

define( 'COD_SHIELD_VERSION', '2.0.0' );
define( 'COD_SHIELD_FILE', __FILE__ );
define( 'COD_SHIELD_PATH', plugin_dir_path( __FILE__ ) );
define( 'COD_SHIELD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Lightweight PSR-4-style autoloader for the CodShield\ namespace.
 *
 * Maps CodShield\Class_Name to includes/class-cod-shield-class-name.php.
 * Keeps the plugin dependency-free (no vendor/ directory required at
 * runtime), while removing the need to maintain a require_once list.
 */
spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CodShield\\' ) ) {
			return;
		}
		$relative = str_replace( '_', '-', strtolower( substr( $class_name, strlen( 'CodShield\\' ) ) ) );
		$file     = COD_SHIELD_PATH . 'includes/class-cod-shield-' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

/**
 * Declare compatibility with HPOS (custom order tables) and the Cart/Checkout
 * blocks so WooCommerce does not show "incompatible" warnings.
 *
 * Fires on before_woocommerce_init per the HPOS extension recipe book.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Bootstrap the plugin once all plugins (including WooCommerce) are loaded.
 */
add_action(
	'plugins_loaded',
	static function () {
		// Translations load automatically since WP 4.6 (domain cod-shield,
		// languages/ subfolder). No manual load_plugin_textdomain() needed.

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'COD Shield for WooCommerce requires WooCommerce to be installed and active.', 'cod-shield' );
					echo '</p></div>';
				}
			);
			return;
		}

		\CodShield\Core::instance();
	},
	20
);

/**
 * Install default options on activation (no data is destroyed on deactivate).
 */
register_activation_hook(
	__FILE__,
	static function () {
		foreach ( \CodShield\Core::get_defaults() as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
);
