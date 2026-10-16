<?php
/**
 * Stub delegation gateway for WooCommerce integration tests.
 *
 * Drop-in gateway used by tests/integration/LifeCycleTest.php (when run under
 * the WordPress + WooCommerce test framework). It lets a test control exactly
 * what the delegated gateway "charges" and whether it succeeds, so the full
 * COD-advance money lifecycle can be exercised end-to-end against a real
 * WC_Order:
 *
 *   - process_payment() success -> sets the transaction id + calls
 *     payment_complete(), which fires the hooks Gateway_Bridge finalizes on.
 *   - $charge_override -> set the order total to a different amount before
 *     "completing" (used to drive the overcharge guard).
 *   - $should_succeed=false -> return a failure result so process_payment()'s
 *     restore path runs.
 *
 * Register it as the delegation gateway by filtering the option
 * Core::OPT_GATEWAYS to ['cod_shield_stub'] and adding an instance to
 * WC()->payment_gateways()->payment_gateways in the test setUp().
 *
 * This file is ONLY loaded by the integration test bootstrap (it requires
 * WC_Payment_Gateway, so it is excluded from the standalone runners and from
 * PHPCS via the tests/ exclude pattern).
 *
 * @package COD_Shield
 */

if ( ! class_exists( 'WC_Gateway_COD_Shield_Stub' ) ) {

	/**
	 * Controllable stub gateway.
	 */
	class WC_Gateway_COD_Shield_Stub extends \WC_Payment_Gateway {

		/**
		 * Whether the next process_payment() should succeed.
		 *
		 * @var bool
		 */
		public $should_succeed = true;

		/**
		 * Override the order total before "charging" (null = leave as-is).
		 * Set to a value greater than the advance to drive the overcharge guard.
		 *
		 * @var float|null
		 */
		public $charge_override = null;

		/**
		 * Transaction id to record on success.
		 *
		 * @var string
		 */
		public $txn_id = 'txn-stub-001';

		/** @var int Number of payment initiation calls. */
		public $process_count = 0;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id           = 'cod_shield_stub';
			$this->method_title = 'Stub Gateway';
			$this->title        = 'Stub Gateway';
			$this->enabled      = 'yes';
			$this->has_fields   = false;
			$this->supports     = array( 'products' );
		}

		/**
		 * Mimic a real gateway charge with controllable outcome.
		 *
		 * @param int $order_id Order id.
		 * @return array<string,mixed>
		 */
		public function process_payment( $order_id ) {
			++$this->process_count;
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return array( 'result' => 'failure' );
			}

			if ( null !== $this->charge_override ) {
				$order->set_total( (float) $this->charge_override );
				$order->save();
			}

			if ( ! $this->should_succeed ) {
				return array( 'result' => 'failure', 'messages' => 'Stub gateway declined.' );
			}

			// Success: record the transaction and complete payment. This fires
			// woocommerce_payment_complete (and the processing transition) which
			// Gateway_Bridge::finalize_advance_order() listens for — exactly the
			// real webhook/order-status flow under test.
			$order->payment_complete( $this->txn_id );
			return array(
				'result'   => 'success',
				'redirect' => '',
			);
		}
	}
}
