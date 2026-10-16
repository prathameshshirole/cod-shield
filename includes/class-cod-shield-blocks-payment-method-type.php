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
	 * Load checkout synchronization when COD is enabled. Cart-specific
	 * availability is evaluated from live Store API extension data.
	 *
	 * @return bool
	 */
	public function is_active() {
		$core = Core::instance();
		return $core->cod_enabled() && $core->native_cod_enabled();
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
			array( 'wc-blocks-registry', 'wc-settings', 'wc-blocks-data-store', 'wc-blocks-checkout', 'wp-element', 'wp-data', 'wp-i18n', 'wp-notices' ),
			Core::asset_version( 'public/js/blocks/payment-method.js' ),
			true
		);
		wp_set_script_translations( 'wc-payment-method-cod-shield-advance', 'cod-shield', COD_SHIELD_PATH . 'languages' );
		return array( 'wc-payment-method-cod-shield-advance' );
	}

	/**
	 * Data passed to the client-side registerPaymentMethod() call.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data() {
		$core = Core::instance();

		$total     = $core->get_cart_total();
		$advance   = $core->calculate_advance( $total );
		$remaining = max( 0.0, $total - $advance );
		$message   = $core->get_advance_breakdown( $advance, $remaining );

		return array(
			'gateway_id'      => Core::GATEWAY_ID,
			'title'           => $core->get_cod_title(),
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
