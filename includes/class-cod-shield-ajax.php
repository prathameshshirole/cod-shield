<?php
/**
 * Checkout styles and a legacy display-only advance endpoint.
 *
 * The AJAX response is display-only: the authoritative amount is always
 * re-calculated server-side at order creation / payment time.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Ajax class.
 */
class Ajax {

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'wp_ajax_cod_shield_get_advance', array( $this, 'get_advance' ) );
		add_action( 'wp_ajax_nopriv_cod_shield_get_advance', array( $this, 'get_advance' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_assets' ) );
	}

	/**
	 * Enqueue checkout styles for classic and block checkout.
	 *
	 * @return void
	 */
	public function enqueue_checkout_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return;
		}

		// Shared payment breakdown styles for classic and block checkout.
		wp_enqueue_style(
			'cod-shield-checkout',
			COD_SHIELD_URL . 'public/css/checkout.css',
			array(),
			Core::asset_version( 'public/css/checkout.css' )
		);
	}

	/**
	 * Return the advance amount + remaining for the current cart (display only).
	 *
	 * @return void
	 */
	public function get_advance() {
		check_ajax_referer( Core::NONCE_ACTION, 'nonce' );

		$core        = Core::instance();
		$total       = $core->get_cart_total();
		$advance     = $core->calculate_advance( $total );
		$eligibility = Eligibility::evaluate_current_cart();

		wp_send_json_success(
			array(
				'total'                     => $total,
				'advance'                   => $advance,
				'remaining'                 => max( 0.0, $total - $advance ),
				'required'                  => $advance > 0,
				'available'                 => ! $core->is_below_cod_minimum( $total ),
				'advanceHtml'               => wc_price( $advance ),
				'remainingHtml'             => wc_price( max( 0.0, $total - $advance ) ),
				'cod_eligible'              => $eligibility['eligible'],
				'cod_ineligibility_code'    => $eligibility['code'],
				'cod_ineligibility_message' => $eligibility['message'],
			)
		);
	}
}
