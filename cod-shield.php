<?php
/**
 * Plugin Name:       COD Shield for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/cod-shield/
 * Description:       Reduce COD RTO by collecting a partial advance payment through one compatible WooCommerce gateway, with simple setup, optional eligibility rules, and clear order visibility.
 * Version:           2.0.2
 * Author:            Prathamesh Shirole
 * Author URI:        https://profiles.wordpress.org/prathaverse/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
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

/*
Copyright (C) 2026 Prathamesh Shirole

COD Shield for WooCommerce is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

COD Shield for WooCommerce is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with COD Shield for WooCommerce. If not, see https://www.gnu.org/licenses/.
*/

defined( 'ABSPATH' ) || exit;

define( 'COD_SHIELD_VERSION', '2.0.2' );
define( 'COD_SHIELD_FILE', __FILE__ );
define( 'COD_SHIELD_PATH', plugin_dir_path( __FILE__ ) );
define( 'COD_SHIELD_URL', plugin_dir_url( __FILE__ ) );

/**
 * Put the minimal setup one click away from the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=cod_shield' ) ) . '">' . esc_html__( 'Settings', 'cod-shield' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

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
