<?php
/**
 * Payment method type for the Cart & Checkout block.
 *
 * Registers the proxy gateway with the Checkout block so the advance
 * messaging renders natively next to the payment method radio (classic hooks
 * such as woocommerce_review_order_before_payment are not supported by the
 * block).
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks payment method type.
 */
class Blocks_Payment_Method_Type extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	protected $name = Core::GATEWAY_ID;

	/**
	 * Settings snapshot.
	 *
	 * @var array<string,mixed>
	 */
	protected $settings = array();

	/**
	 * Initialize.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = Core::instance()->all_settings();
	}

	/**
	 * Active only when the advance flow is operational for the current cart
	 * AND the cart qualifies for Cash on Delivery (shipping rules, no
	 * subscriptions).
	 *
	 * @return bool
	 */
	public function is_active() {
		$core = Core::instance();
		return $core->advance_flow_active() && $core->cart_qualifies_for_cod();
	}

	/**
	 * Register the frontend script handle.
	 *
	 * @return array<string>
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'wc-payment-method-cod-shield-advance',
			COD_SHIELD_URL . 'public/js/blocks/payment-method.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element' ),
			COD_SHIELD_VERSION,
			true
		);
		return array( 'wc-payment-method-cod-shield-advance' );
	}

	/**
	 * Data passed to the client-side registerPaymentMethod() call.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$core     = Core::instance();
		$settings = $core->all_settings();

		// Mirror the order-time math: on the block checkout the extra COD fee is
		// applied to the order right before payment (see
		// Checkout::maybe_add_cod_fee_to_order), so the total used for the
		// message includes it — otherwise the displayed advance could drift from
		// the charged amount. Classic checkout is already consistent (the fee is
		// in the cart once COD is selected).
		$total = $core->get_cart_total();
		if ( 'yes' === ( $settings['fee_enabled'] ?? 'no' ) ) {
			$total += $core->calculate_fee( $total );
		}
		$advance   = $core->calculate_advance( $total );
		$remaining = max( 0.0, $total - $advance );

		// The message is admin-configurable; sanitise it server-side before it
		// is injected into the block checkout via dangerouslySetInnerHTML.
		$message = wp_kses_post( (string) $core->get_advance_message( $advance, $remaining ) );
		if ( '' !== $message ) {
			// Same highlighted callout as the classic checkout
			// (public/css/checkout.css) so both checkouts look identical.
			$message = '<div class="cod-shield-advance-notice">' . $message . '</div>';
		}

		return array(
			'gateway_id'      => Core::GATEWAY_ID,
			'title'           => __( 'Cash on delivery (COD)', 'cod-shield' ),
			'description'     => $message,
			'message'         => $message,
			'advance'         => $advance,
			'remaining'       => $remaining,
			'advance_html'    => wc_price( $advance ),
			'remaining_html'  => wc_price( $remaining ),
			// Plain-text amounts for the no-message fallback (it is rendered as
			// a React text child — HTML here would show up as raw markup).
			'advance_plain'   => wp_strip_all_tags( wc_price( $advance ) ),
			'remaining_plain' => wp_strip_all_tags( wc_price( $remaining ) ),
			'supports'        => array( 'products' ),
		);
	}
}
