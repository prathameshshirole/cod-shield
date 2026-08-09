<?php
/**
 * Optional India-focused Cash on Delivery eligibility rules.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * COD eligibility rules.
 */
class Eligibility {

	const OPT_MAXIMUM           = 'cod_shield_cod_maximum';
	const OPT_EXCLUDED_PINCODES = 'cod_shield_excluded_pincodes';

	/**
	 * Register checkout eligibility hooks.
	 */
	public function __construct() {
		// Run after Gateway_Bridge adds the advance proxy at priority 20.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 30 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_checkout' ) );
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'filter_store_api_cart_errors' ), 15, 2 );
	}

	/**
	 * Remove COD methods when an optional eligibility rule fails.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways Available gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function filter_available_gateways( $gateways ) {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return $gateways;
		}

		if ( null !== self::get_current_ineligibility_reason() ) {
			unset( $gateways['cod'], $gateways[ Core::GATEWAY_ID ] );
		}

		return $gateways;
	}

	/**
	 * Validate eligibility during classic checkout submission.
	 *
	 * @return void
	 */
	public function validate_classic_checkout() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce verifies the checkout request; wc_clean() sanitizes the value.
		$method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( ! in_array( $method, array( 'cod', Core::GATEWAY_ID ), true ) ) {
			return;
		}

		$reason = self::get_current_ineligibility_reason();
		if ( null !== $reason ) {
			wc_add_notice( $reason, 'error' );
		}
	}

	/**
	 * Validate eligibility during Checkout Block submission.
	 *
	 * @param \WP_Error $errors Errors object.
	 * @param \WC_Cart  $cart   Cart object.
	 * @return \WP_Error
	 */
	public function filter_store_api_cart_errors( $errors, $cart ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		$method = ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
		if ( ! in_array( $method, array( 'cod', Core::GATEWAY_ID ), true ) ) {
			return $errors;
		}

		$reason = self::get_current_ineligibility_reason();
		if ( null !== $reason ) {
			$errors->add( 'cod_shield_eligibility', $reason );
		}

		return $errors;
	}

	/**
	 * Determine why the current cart cannot use COD.
	 *
	 * @return string|null
	 */
	public static function get_current_ineligibility_reason() {
		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return null;
		}

		$maximum = (float) get_option( self::OPT_MAXIMUM, 0 );
		if ( self::is_above_maximum( $core->get_cart_total(), $maximum ) ) {
			return sprintf(
				/* translators: %s: maximum order value for Cash on Delivery. */
				__( 'Cash on Delivery is available for orders up to %s. Please choose an online payment method.', 'cod-shield' ),
				wc_price( $maximum )
			);
		}

		$postcode = self::current_customer_postcode();
		$rules    = (string) get_option( self::OPT_EXCLUDED_PINCODES, '' );
		if ( '' !== $postcode && self::postcode_matches_rules( $postcode, $rules ) ) {
			return __( 'Cash on Delivery is not available for this delivery pincode. Please choose an online payment method.', 'cod-shield' );
		}

		return null;
	}

	/**
	 * Get the best-known shipping postcode for the checkout customer.
	 *
	 * @return string
	 */
	private static function current_customer_postcode() {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}

		$postcode = (string) WC()->customer->get_shipping_postcode();
		if ( '' === trim( $postcode ) ) {
			$postcode = (string) WC()->customer->get_billing_postcode();
		}

		return self::normalize_postcode( $postcode );
	}

	/**
	 * Whether an order exceeds the configured maximum COD value.
	 *
	 * @param float $total   Cart total.
	 * @param float $maximum Maximum COD value; zero disables the rule.
	 * @return bool
	 */
	public static function is_above_maximum( $total, $maximum ) {
		$maximum = (float) $maximum;
		return $maximum > 0 && (float) $total > $maximum;
	}

	/**
	 * Match a postcode against exact or trailing-wildcard rules.
	 *
	 * Rules may be separated with commas, spaces, or new lines. For example,
	 * `110001` excludes one pincode and `400*` excludes every 400xxx pincode.
	 *
	 * @param string $postcode Customer postcode.
	 * @param string $rules    Configured exclusion rules.
	 * @return bool
	 */
	public static function postcode_matches_rules( $postcode, $rules ) {
		$postcode = self::normalize_postcode( $postcode );
		if ( '' === $postcode ) {
			return false;
		}

		$rules = preg_split( '/[\s,]+/', strtoupper( (string) $rules ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $rules as $rule ) {
			$rule = preg_replace( '/[^A-Z0-9*]/', '', $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( '*' === substr( $rule, -1 ) ) {
				$prefix = rtrim( $rule, '*' );
				if ( '' !== $prefix && 0 === strpos( $postcode, $prefix ) ) {
					return true;
				}
			} elseif ( $postcode === $rule ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a postcode for comparisons.
	 *
	 * @param string $postcode Postcode.
	 * @return string
	 */
	private static function normalize_postcode( $postcode ) {
		return preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( (string) $postcode ) ) );
	}
}
