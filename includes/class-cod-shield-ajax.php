<?php
/**
 * AJAX endpoints and checkout script enqueue for the live risk-free message
 * on the classic shortcode checkout.
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
	 * Enqueue the checkout script on the classic checkout page.
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

		wp_enqueue_script(
			'cod-shield-checkout',
			COD_SHIELD_URL . 'public/js/checkout.js',
			array( 'jquery' ),
			COD_SHIELD_VERSION,
			true
		);

		// Frontend checkout styles: advance-notice callout and the risk-free
		// info box. Enqueued here (wp_enqueue_scripts + is_checkout()) so it
		// loads on both the classic and the block checkout.
		wp_enqueue_style(
			'cod-shield-checkout',
			COD_SHIELD_URL . 'public/css/checkout.css',
			array(),
			COD_SHIELD_VERSION
		);

		$settings     = $core->all_settings();
		$free_message = ( 'yes' === $settings['msg_free_enabled'] )
			? wp_kses_post( (string) $settings['msg_free_text'] )
			: '';

		wp_localize_script(
			'cod-shield-checkout',
			'codShieldCheckout',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Core::NONCE_ACTION ),
				'mode'        => $settings['mode'],
				'freeMessage' => $free_message,
			)
		);
	}

	/**
	 * Return the advance amount + remaining for the current cart (display only).
	 *
	 * @return void
	 */
	public function get_advance() {
		check_ajax_referer( Core::NONCE_ACTION, 'nonce' );

		$core    = Core::instance();
		$total   = $core->get_cart_total();
		$advance = $core->calculate_advance( $total );

		wp_send_json_success(
			array(
				'total'         => $total,
				'advance'       => $advance,
				'remaining'     => max( 0.0, $total - $advance ),
				'required'      => $advance > 0,
				'available'     => ! $core->is_below_cod_minimum( $total ),
				'advanceHtml'   => wc_price( $advance ),
				'remainingHtml' => wc_price( max( 0.0, $total - $advance ) ),
			)
		);
	}
}
