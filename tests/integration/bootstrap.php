<?php
/**
 * WordPress and WooCommerce integration-test bootstrap.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

$cod_shield_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $cod_shield_tests_dir ) {
	$cod_shield_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $cod_shield_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR or run bin/install-wp-tests.sh.\n" );
	exit( 1 );
}

require_once $cod_shield_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and SureOrder before WordPress initializes plugins.
 *
 * @return void
 */
function cod_shield_manually_load_plugins(): void {
	$woocommerce_file = getenv( 'WC_TEST_PLUGIN_FILE' );
	if ( ! $woocommerce_file ) {
		$woocommerce_file = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}

	if ( ! file_exists( $woocommerce_file ) ) {
		throw new RuntimeException( 'WooCommerce plugin not found. Set WC_TEST_PLUGIN_FILE.' );
	}

	require_once $woocommerce_file;
	require_once __DIR__ . '/Stub_Gateway.php';
	add_filter(
		'woocommerce_payment_gateways',
		static function ( array $gateways ): array {
			$gateways[] = 'WC_Gateway_COD_Shield_Stub';
			return $gateways;
		}
	);

	$storage = getenv( 'COD_SHIELD_ORDER_STORAGE' );
	update_option( 'woocommerce_custom_orders_table_enabled', 'hpos' === $storage ? 'yes' : 'no' );

	require_once dirname( __DIR__, 2 ) . '/cod-shield.php';
}

tests_add_filter( 'muplugins_loaded', 'cod_shield_manually_load_plugins' );

require $cod_shield_tests_dir . '/includes/bootstrap.php';

if ( 'hpos' === getenv( 'COD_SHIELD_ORDER_STORAGE' ) ) {
	$data_synchronizer = wc_get_container()->get( Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
	$data_synchronizer->create_database_tables();
}
