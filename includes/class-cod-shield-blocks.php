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
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_cart_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_cart_data' ) );
		}
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
	/** Register live cart data and payment selection updates. */
	public function register_cart_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'cod-shield',
				'data_callback'   => array( $this, 'get_cart_data' ),
				'schema_callback' => array( $this, 'get_cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => 'cod-shield',
				'callback'  => array( $this, 'update_payment_method' ),
			)
		);
	}

	/**
	 * Persist the selected gateway before Store API recalculates the cart.
	 *
	 * @param array $data Extension update data.
	 * @throws \InvalidArgumentException When the method identifier is invalid.
	 */
	public function update_payment_method( $data ) {
		$method = $data['payment_method'] ?? null;
		if ( ! is_string( $method ) || sanitize_key( $method ) !== $method ) {
			throw new \InvalidArgumentException( esc_html__( 'Please select a valid payment method.', 'cod-shield' ) );
		}
		if ( WC()->session ) {
			WC()->session->set( 'chosen_payment_method', $method );
		}
	}

	/**
	 * Get live checkout amounts, including cart fee taxes.
	 *
	 * @return array Live cart data.
	 */
	public function get_cart_data() {
		$core        = Core::instance();
		$total       = $core->get_cart_total();
		$advance     = $core->calculate_advance( $total );
		$remaining   = max( 0.0, $total - $advance );
		$eligibility = Eligibility::evaluate_current_cart();
		return array(
			'available'                 => $core->native_cod_enabled() && $core->advance_flow_active() && $core->cart_qualifies_for_cod() && $eligibility['eligible'],
			'cod_eligible'              => $eligibility['eligible'],
			'cod_ineligibility_code'    => $eligibility['code'],
			'cod_ineligibility_message' => $eligibility['message'],
			'message'                   => $core->get_advance_breakdown( $advance, $remaining ),
			'minimum_notice'            => $core->cod_enabled() && $core->native_cod_enabled() && $core->is_below_cod_minimum( $total ) ? sprintf(
				/* translators: %s: minimum order amount for COD. */
				__( 'Cash on delivery is available on orders of %s or more.', 'cod-shield' ),
				html_entity_decode( wp_strip_all_tags( wc_price( $core->all_settings()['threshold'] ) ), ENT_QUOTES, 'UTF-8' )
			) : '',
			'payment_method'            => WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '',
		);
	}

	/**
	 * Get the Store API response schema.
	 *
	 * @return array Response schema.
	 */
	public function get_cart_schema() {
		return array(
			'available'                 => array(
				'type'     => 'boolean',
				'readonly' => true,
			),
			'cod_eligible'              => array(
				'type'     => 'boolean',
				'readonly' => true,
			),
			'cod_ineligibility_code'    => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'cod_ineligibility_message' => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'minimum_notice'            => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'message'                   => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'payment_method'            => array(
				'type'     => 'string',
				'readonly' => true,
			),
		);
	}
}
