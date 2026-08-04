<?php
/**
 * Unit tests: Order_UI HPOS list-table hook signatures.
 *
 * Regression guard for the production fatal
 *
 *   ArgumentCountError: Too few arguments to function
 *   CodShield\Order_UI::apply_orderby_query_args()
 *
 * on the WooCommerce → Orders admin screen (HPOS). WooCommerce applies
 * `woocommerce_order_list_table_prepare_items_query_args` with a SINGLE
 * argument — the query-args array (documented `@since 6.9.0`) — but the
 * callbacks were declared with a required second `$order_type` parameter,
 * so every HPOS orders-list render crashed with a white screen. This test
 * pins both the registration (accepted args = 1) and the callback
 * signatures so the class can never regress into that fatal again.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Order_UI HPOS hook signature tests (static source check, no WordPress).
 */
class OrderUiHooksTest extends TestCase {

	/**
	 * Both callbacks on the HPOS prepare-items filter must be registered to
	 * accept exactly ONE argument (what WooCommerce actually passes) and
	 * must declare exactly one parameter — a required second parameter
	 * throws ArgumentCountError on the orders list page.
	 */
	public function testPrepareItemsQueryArgsCallbacksAcceptOneArgument() {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-order-ui.php' );
		$this->assertIsString( $source );

		foreach ( array( 'apply_orderby_query_args' => 10, 'apply_filter_query_args' => 20 ) as $callback => $priority ) {
			// Registration: accepted args must be 1, never 2+.
			$this->assertStringContainsString(
				"add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( \$this, '" . $callback . "' ), " . $priority . ", 1 );",
				$source,
				$callback . ' must be registered with accepted-args = 1'
			);

			// Callback signature: exactly one parameter ($query_args).
			$this->assertStringContainsString(
				'function ' . $callback . '( $query_args ) {',
				$source,
				$callback . ' must declare exactly one parameter ($query_args)'
			);
			$this->assertStringNotContainsString(
				'function ' . $callback . '( $query_args, $',
				$source,
				$callback . ' must not declare a second required parameter'
			);
		}
	}
}
