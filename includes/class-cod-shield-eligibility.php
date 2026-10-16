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

	/**
	 * Memoized evaluation results keyed by request fingerprint.
	 *
	 * @var array<string,array{eligible:bool,code:string,message:string}>
	 */
	private static $evaluation_cache = array();

	/**
	 * Memoized resolved product descriptors keyed by product/variation ids.
	 *
	 * @var array<string,array{product_id:int,variation_id:int,category_ids:int[],shipping_class_id:int}>
	 */
	private static $resolved_product_cache = array();

	const OPT_MAXIMUM                   = 'cod_shield_cod_maximum';
	const OPT_EXCLUDED_PINCODES         = 'cod_shield_excluded_pincodes';
	const OPT_EXCLUDED_SHIPPING_ZONES   = 'cod_shield_excluded_shipping_zones';
	const OPT_EXCLUDED_SHIPPING_METHODS = 'cod_shield_excluded_shipping_methods';
	const OPT_EXCLUDED_PRODUCTS         = 'cod_shield_excluded_products';
	const OPT_EXCLUDED_CATEGORIES       = 'cod_shield_excluded_categories';
	const OPT_EXCLUDED_SHIPPING_CLASSES = 'cod_shield_excluded_shipping_classes';

	/**
	 * Stable reason code: cart total above the configured COD maximum.
	 */
	const CODE_ABOVE_MAXIMUM = 'above_maximum';

	/**
	 * Stable reason code: delivery pincode matches an exclusion rule.
	 */
	const CODE_EXCLUDED_PINCODE = 'excluded_pincode';

	/**
	 * Stable reason code: a selected shipping method/instance/zone is excluded.
	 */
	const CODE_EXCLUDED_SHIPPING_METHOD = 'excluded_shipping_method';

	/**
	 * Stable reason code: a cart item matches a product/category/shipping-class exclusion.
	 */
	const CODE_EXCLUDED_PRODUCT = 'excluded_product';

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
	 * Structured eligibility result for the current cart.
	 *
	 * Single evaluation shared by gateway filtering, classic validation,
	 * Store API validation, and Blocks display. The order-pay endpoint is
	 * not re-evaluated: an existing recorded obligation keeps its own rules.
	 * Memoized per request fingerprint so gateway filtering, validation, and
	 * AJAX share one evaluation.
	 *
	 * @return array{eligible:bool,code:string,message:string}
	 */
	public static function evaluate_current_cart(): array {
		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			// The global kill switch is enforced elsewhere; no rule applies.
			return self::eligible_result();
		}

		$context = array(
			'total'                     => $core->get_cart_total(),
			'maximum'                   => (float) get_option( self::OPT_MAXIMUM, 0 ),
			'postcode'                  => self::current_customer_postcode(),
			'excluded_pincodes'         => (string) get_option( self::OPT_EXCLUDED_PINCODES, '' ),
			'selected_shipping_methods' => self::current_selected_shipping_methods(),
			'excluded_shipping_methods' => self::current_excluded_shipping_methods(),
			'cart_items'                => self::current_cart_item_states(),
			'excluded_products'         => self::normalize_id_list( get_option( self::OPT_EXCLUDED_PRODUCTS, '' ) ),
			'excluded_categories'       => self::normalize_id_list( get_option( self::OPT_EXCLUDED_CATEGORIES, '' ) ),
			'excluded_shipping_classes' => self::normalize_id_list( get_option( self::OPT_EXCLUDED_SHIPPING_CLASSES, '' ) ),
		);

			$fingerprint = md5( (string) wp_json_encode( $context ) );
		if ( isset( self::$evaluation_cache[ $fingerprint ] ) ) {
			return self::$evaluation_cache[ $fingerprint ];
		}

		$result                 = self::evaluate_rules( $context );
		self::$evaluation_cache = array( $fingerprint => $result );
		return $result;
	}

	/**
	 * Pure structured evaluation of the configured eligibility rules.
	 *
	 * Deterministic order: maximum value first, then pincode exclusions.
	 * Every exclusion fails closed with a stable machine-readable code and a
	 * merchant/customer-facing message.
	 *
	 * @param array{total:float,maximum:float,postcode:string,excluded_pincodes:string} $context Rule inputs.
	 * @return array{eligible:bool,code:string,message:string}
	 */
	public static function evaluate_rules( array $context ): array {
		$maximum = (float) ( $context['maximum'] ?? 0 );
		$total   = (float) ( $context['total'] ?? 0 );
		if ( self::is_above_maximum( $total, $maximum ) ) {
			return array(
				'eligible' => false,
				'code'     => self::CODE_ABOVE_MAXIMUM,
				'message'  => sprintf(
						/* translators: %s: maximum order value for Cash on Delivery. */
					__( 'Cash on Delivery is available for orders up to %s. Please choose an online payment method.', 'cod-shield' ),
					wc_price( $maximum )
				),
			);
		}

		$postcode = (string) ( $context['postcode'] ?? '' );
		$rules    = (string) ( $context['excluded_pincodes'] ?? '' );
		if ( '' !== $postcode && self::postcode_matches_rules( $postcode, $rules ) ) {
			return array(
				'eligible' => false,
				'code'     => self::CODE_EXCLUDED_PINCODE,
				'message'  => __( 'Cash on Delivery is not available for this delivery pincode. Please choose an online payment method.', 'cod-shield' ),
			);
		}

		// Shipping exclusions are evaluated before catalog exclusions.
		$selected_shipping = array();
		foreach ( (array) ( $context['selected_shipping_methods'] ?? array() ) as $rate_id ) {
			if ( is_scalar( $rate_id ) && '' !== trim( (string) $rate_id ) ) {
				$selected_shipping[] = (string) $rate_id;
			}
		}
		$excluded_shipping = array();
		foreach ( (array) ( $context['excluded_shipping_methods'] ?? array() ) as $token ) {
			if ( is_scalar( $token ) && '' !== trim( (string) $token ) ) {
				$excluded_shipping[] = (string) $token;
			}
		}
		if ( self::shipping_selection_matches_exclusions( $selected_shipping, $excluded_shipping ) ) {
			return array(
				'eligible' => false,
				'code'     => self::CODE_EXCLUDED_SHIPPING_METHOD,
				'message'  => __( 'Cash on Delivery is not available for the selected shipping method. Please choose a different shipping method or an online payment method.', 'cod-shield' ),
			);
		}

		$items      = isset( $context['cart_items'] ) && is_array( $context['cart_items'] ) ? $context['cart_items'] : array();
		$products   = self::normalize_id_list( $context['excluded_products'] ?? array() );
		$categories = self::normalize_id_list( $context['excluded_categories'] ?? array() );
		$classes    = self::normalize_id_list( $context['excluded_shipping_classes'] ?? array() );
		if ( self::cart_items_match_exclusions( $items, $products, $categories, $classes ) ) {
			return array(
				'eligible' => false,
				'code'     => self::CODE_EXCLUDED_PRODUCT,
				'message'  => __( 'Cash on Delivery is not available for some items in your cart. Please remove them or choose an online payment method.', 'cod-shield' ),
			);
		}

		return self::eligible_result();
	}

	/**
	 * Determine why the current cart cannot use COD.
	 *
	 * Compatibility wrapper around the structured evaluator.
	 *
	 * @return string|null
	 */
	public static function get_current_ineligibility_reason() {
		$result = self::evaluate_current_cart();
		return $result['eligible'] ? null : $result['message'];
	}

	/**
	 * Build the eligible result.
	 *
	 * @return array{eligible:bool,code:string,message:string}
	 */
	private static function eligible_result(): array {
		return array(
			'eligible' => true,
			'code'     => '',
			'message'  => '',
		);
	}

	/**
	 * Normalize a newline/comma/space separated list of positive integer ids.
	 *
	 * Invalid tokens (non-numeric, zero, negative, fractional) are dropped;
	 * duplicates collapse; the result is sorted.
	 *
	 * @param mixed $raw Raw option value or id array.
	 * @return array<int,int>
	 */
	public static function normalize_id_list( $raw ): array {
		$tokens = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$ids    = array();
		foreach ( (array) $tokens as $token ) {
			if ( ! is_scalar( $token ) ) {
				continue;
			}
			$token = trim( (string) $token );
			if ( '' === $token || ! ctype_digit( $token ) ) {
				continue;
			}
			$id = (int) $token;
			if ( $id >= 1 ) {
				$ids[ $id ] = true;
			}
		}
		return array_map( 'intval', array_keys( $ids ) );
	}

	/**
	 * Normalize shipping-method exclusion tokens.
	 *
	 * Accepts rate ids (`flat_rate`) and instance ids (`flat_rate:23`); tokens
	 * are lowercased, validated, and de-duplicated. A zero instance suffix is
	 * dropped (it means "any instance").
	 *
	 * @param mixed $raw Raw option value or token array.
	 * @return array<int,string>
	 */
	public static function normalize_shipping_method_list( $raw ): array {
		$tokens  = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$methods = array();
		foreach ( (array) $tokens as $token ) {
			if ( ! is_scalar( $token ) ) {
				continue;
			}
			$token = strtolower( trim( (string) $token ) );
			// Reject tokens containing anything outside the rate-id charset
			// instead of silently stripping garbage into a valid-looking name.
			if ( '' === $token || ! preg_match( '/^[a-z0-9_:\-]+$/', $token ) ) {
				continue;
			}
			if ( ! preg_match( '/^([a-z0-9_\-]+)(?::(\d+))?$/', $token, $matches ) ) {
				continue;
			}
			$instance        = isset( $matches[2] ) ? (int) $matches[2] : 0;
			$key             = $instance > 0 ? $matches[1] . ':' . $instance : $matches[1];
			$methods[ $key ] = true;
		}
		return array_keys( $methods );
	}

	/**
	 * Whether any selected shipping rate matches an excluded method/instance.
	 *
	 * Selected entries are WooCommerce rate ids (`method:instance`). An
	 * exclusion without an instance matches every instance of that method.
	 * Multiple packages: any matching package excludes COD.
	 *
	 * @param array<int,string> $selected Selected rate ids.
	 * @param array<int,string> $excluded Excluded rate/instance tokens.
	 * @return bool
	 */
	public static function shipping_selection_matches_exclusions( array $selected, array $excluded ): bool {
		if ( array() === $excluded ) {
			return false;
		}
		foreach ( $selected as $rate_id ) {
			$rate_id = strtolower( trim( (string) $rate_id ) );
			if ( '' === $rate_id ) {
				continue;
			}
			$parts    = explode( ':', $rate_id, 2 );
			$method   = $parts[0];
			$instance = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : null;
			foreach ( $excluded as $excluded_token ) {
				$excluded_parts = explode( ':', (string) $excluded_token, 2 );
				if ( $excluded_parts[0] !== $method ) {
					continue;
				}
				$excluded_instance = isset( $excluded_parts[1] ) && ctype_digit( $excluded_parts[1] ) ? (int) $excluded_parts[1] : null;
				if ( null === $excluded_instance || $excluded_instance === $instance ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether any cart item matches a product/category/shipping-class exclusion.
	 *
	 * Variations match their own id AND the parent product's id, categories,
	 * and shipping class. Items with missing product data never match.
	 *
	 * @param array<int,array<string,mixed>> $items Resolved item descriptors.
	 * @param array<int,int>                 $excluded_products Excluded product ids.
	 * @param array<int,int>                 $excluded_categories Excluded category term ids.
	 * @param array<int,int>                 $excluded_classes Excluded shipping class term ids.
	 * @return bool
	 */
	public static function cart_items_match_exclusions( array $items, array $excluded_products, array $excluded_categories, array $excluded_classes ): bool {
		if ( array() === $excluded_products && array() === $excluded_categories && array() === $excluded_classes ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			if ( ( $product_id > 0 && in_array( $product_id, $excluded_products, true ) )
				|| ( $variation_id > 0 && in_array( $variation_id, $excluded_products, true ) ) ) {
				return true;
			}
			$category_ids = isset( $item['category_ids'] ) && is_array( $item['category_ids'] ) ? $item['category_ids'] : array();
			foreach ( $category_ids as $category_id ) {
				if ( is_scalar( $category_id ) && in_array( (int) $category_id, $excluded_categories, true ) ) {
					return true;
				}
			}
			$shipping_class_id = (int) ( $item['shipping_class_id'] ?? 0 );
			if ( $shipping_class_id > 0 && in_array( $shipping_class_id, $excluded_classes, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Selected shipping rate ids for the current customer (one per package).
	 *
	 * @return array<int,string>
	 */ private static function current_selected_shipping_methods(): array {
		$selected = array();
		if ( function_exists( 'WC' ) && WC()->session ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
			foreach ( (array) $chosen as $rate_id ) {
				if ( is_scalar( $rate_id ) && '' !== trim( (string) $rate_id ) ) {
					$selected[] = (string) $rate_id;
				}
			}
		}
		return $selected;
}

	/**
	 * Excluded shipping tokens: configured methods plus every method instance
	 * of each excluded shipping zone.
	 *
	 * @return array<int,string>
	 */
private static function current_excluded_shipping_methods(): array {
	$tokens = self::normalize_shipping_method_list( get_option( self::OPT_EXCLUDED_SHIPPING_METHODS, '' ) );
	foreach ( self::normalize_id_list( get_option( self::OPT_EXCLUDED_SHIPPING_ZONES, '' ) ) as $zone_id ) {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			continue;
		}
		try {
			$zone = \WC_Shipping_Zones::get_zone( $zone_id );
		} catch ( \Exception $e ) {
			continue; // Deleted zone id: ignore, never fatal.
		}
		if ( ! $zone ) {
			continue;
		}
		foreach ( $zone->get_shipping_methods( false ) as $instance ) {
			$method_id = is_callable( array( $instance, 'get_rate_id' ) ) ? $instance->get_rate_id() : '';
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.MemberNotSnakeCase -- WooCommerce property.
			$instance_id = isset( $instance->instance_id ) ? (int) $instance->instance_id : 0;
			if ( $instance_id > 0 ) {
				$tokens[] = ( '' !== $method_id ? $method_id : '' ) . ':' . $instance_id;
			}
		}
	}
	return array_values( array_unique( $tokens ) );
}

	/**
	 * Resolve the current cart's items into exclusion-check descriptors.
	 *
	 * Variations inherit the parent product's categories and shipping class.
	 * Products are resolved once per request and memoized; deleted products
	 * degrade to empty descriptors instead of fatal.
	 *
	 * @return array<int,array{product_id:int,variation_id:int,category_ids:int[],shipping_class_id:int}>
	 */
private static function current_cart_item_states(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}

	$states = array();
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$product_id   = (int) ( $item['product_id'] ?? 0 );
		$variation_id = (int) ( $item['variation_id'] ?? 0 );
		$cache_key    = $product_id . ':' . $variation_id;
		if ( isset( self::$resolved_product_cache[ $cache_key ] ) ) {
			$states[] = self::$resolved_product_cache[ $cache_key ];
			continue;
		}

		$state   = array(
			'product_id'        => $product_id,
			'variation_id'      => $variation_id,
			'category_ids'      => array(),
			'shipping_class_id' => 0,
		);
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $variation_id > 0 ? $variation_id : $product_id ) : false;
		if ( $product ) {
			$parent = $variation_id > 0 && is_callable( array( $product, 'get_parent_id' ) ) ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( $parent ) {
				$state['product_id']        = (int) $parent->get_id();
				$state['category_ids']      = is_callable( array( $parent, 'get_category_ids' ) ) ? array_map( 'intval', (array) $parent->get_category_ids() ) : array();
				$state['shipping_class_id'] = is_callable( array( $parent, 'get_shipping_class_id' ) ) ? (int) $parent->get_shipping_class_id() : 0;
			}
		}

		self::$resolved_product_cache[ $cache_key ] = $state;
		$states[]                                   = $state;
	}
	return $states;
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
