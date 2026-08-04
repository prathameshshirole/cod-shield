<?php
/**
 * Blocks / Cart & Checkout integration.
 *
 * Registers the proxy gateway with the Checkout block via
 * AbstractPaymentMethodType, so the advance messaging renders natively next to
 * the payment method radio on the block checkout (classic hooks such as
 * woocommerce_review_order_before_payment are not supported by the block).
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks integration class.
 */
class Blocks {

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( $this, 'register_payment_method_type' ) );
	}

	/**
	 * Register the payment method type with the blocks registry.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry Registry.
	 * @return void
	 */
	public function register_payment_method_type( $registry ) {
		$registry->register( new Blocks_Payment_Method_Type() );
	}
}
