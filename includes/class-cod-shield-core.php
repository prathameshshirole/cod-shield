<?php
/**
 * Core bootstrap, option accessors and the single-source-of-truth advance
 * calculation engine for COD Shield for WooCommerce.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Core class.
 */
class Core {

	const OPT_ENABLED           = 'cod_shield_enabled';
	const OPT_MODE              = 'cod_shield_advance_mode';
	const OPT_FIXED             = 'cod_shield_advance_fixed';
	const OPT_PERCENTAGE        = 'cod_shield_advance_percentage';
	const OPT_MIN               = 'cod_shield_advance_min';
	const OPT_MAX               = 'cod_shield_advance_max';
	const OPT_THRESHOLD         = 'cod_shield_advance_threshold';
	const OPT_GATEWAYS          = 'cod_shield_advance_gateways';
	const OPT_MSG_ADV_ENABLED   = 'cod_shield_msg_advance_enabled';
	const OPT_MSG_ADV_TEXT      = 'cod_shield_msg_advance_text';
	const OPT_MSG_FREE_ENABLED  = 'cod_shield_msg_free_enabled';
	const OPT_MSG_FREE_TEXT     = 'cod_shield_msg_free_text';
	const OPT_MSG_EMAIL_ENABLED = 'cod_shield_msg_email_enabled';
	const OPT_MSG_EMAIL_TEXT    = 'cod_shield_msg_email_text';
	const OPT_FAILURE           = 'cod_shield_on_advance_failure';
	const OPT_ORDER_STATUS      = 'cod_shield_order_status';

	const OPT_FEE_ENABLED    = 'cod_shield_fee_enabled';
	const OPT_FEE_TYPE       = 'cod_shield_fee_type';
	const OPT_FEE_AMOUNT     = 'cod_shield_fee_amount';
	const OPT_FEE_PERCENTAGE = 'cod_shield_fee_percentage';
	const OPT_FEE_TAXABLE    = 'cod_shield_fee_taxable';
	const OPT_FEE_TAX_CLASS  = 'cod_shield_fee_tax_class';

	/** ID of the hidden proxy gateway that collects the advance. */
	const GATEWAY_ID = 'cod_shield_advance';

	/** Checkout AJAX nonce action. */
	const NONCE_ACTION = 'cod_shield_checkout';

	/** Admin "mark advance as paid" action (admin-post). */
	const NONCE_MANUAL_PAID = 'cod_shield_mark_advance_paid';

	// Order meta keys (HPOS-safe, always read/written through WC_Order CRUD).
	const META_ADVANCE        = '_cod_shield_advance_amount';
	const META_MODE           = '_cod_shield_advance_mode';
	const META_GATEWAY_USED   = '_cod_shield_gateway_used';
	const META_TXN            = '_cod_shield_gateway_txn_id';
	const META_STATUS         = '_cod_shield_advance_status';
	const META_REMAINING      = '_cod_shield_remaining_due';
	const META_ORIGINAL_TOTAL = '_cod_shield_original_total';
	const META_AWAITING       = '_cod_shield_awaiting_advance';

	/**
	 * Singleton instance.
	 *
	 * @var Core|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return Core
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_defaults() {
		return array(
			self::OPT_ENABLED           => 'yes',
			self::OPT_MODE              => 'disabled',
			self::OPT_FIXED             => 99,
			self::OPT_PERCENTAGE        => 10,
			self::OPT_MIN               => 49,
			self::OPT_MAX               => 999,
			// Order-value floor: 0 = no minimum. A non-zero default would hide
			// small COD orders the moment the plugin is activated on a live
			// store — even for merchants who only want the kill switch or the
			// advance feature — so the minimum is deliberately opt-in.
			self::OPT_THRESHOLD         => 0,
			self::OPT_GATEWAYS          => array(),
			self::OPT_MSG_ADV_ENABLED   => 'yes',
			self::OPT_MSG_ADV_TEXT      => __( 'Pay {advance_amount} now, {remaining_amount} on delivery (COD).', 'cod-shield' ),
			self::OPT_MSG_FREE_ENABLED  => 'yes',
			self::OPT_MSG_FREE_TEXT     => __( 'Cash on Delivery available — no advance payment required.', 'cod-shield' ),
			self::OPT_MSG_EMAIL_ENABLED => 'yes',
			self::OPT_MSG_EMAIL_TEXT    => __( 'Advance payment received', 'cod-shield' ),
			self::OPT_FAILURE           => 'allow',
			// The advance is already collected online when this status is
			// applied, so default to Processing. With 'on-hold' the customer
			// receives the processing/payment-received email (fired when
			// payment_complete() sets processing) while the order actually
			// lands on hold — no on-hold email follows because the transition
			// is processing -> on-hold, which no WC email listens to.
			self::OPT_ORDER_STATUS      => 'processing',
			self::OPT_FEE_ENABLED       => 'no',
			self::OPT_FEE_TYPE          => 'fixed',
			self::OPT_FEE_AMOUNT        => 30,
			self::OPT_FEE_PERCENTAGE    => 2,
			self::OPT_FEE_TAXABLE       => 'no',
			self::OPT_FEE_TAX_CLASS     => '',
		);
	}

	/**
	 * Private constructor: wire up component classes.
	 *
	 * Classes are loaded via the autoloader registered in cod-shield.php.
	 */
	private function __construct() {
		new Gateway_Bridge();
		new Checkout();
		new Order_UI();
		new Emails();
		new Ajax();
		new Blocks();

		// Register the custom settings tab (WC_Settings_Page subclass).
		add_filter(
			'woocommerce_get_settings_pages',
			static function ( $pages ) {
				$pages[] = new Settings();
				return $pages;
			}
		);
	}

	/*
	---------------------------------------------------------------------
	 * Option accessors
	 */

	/**
	 * Read a plugin option with default fallback.
	 *
	 * @param string $key      Option name.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public function get_option( $key, $fallback = null ) {
		$defaults = self::get_defaults();
		$value    = get_option( $key, $defaults[ $key ] ?? null );
		return ( null === $value ) ? $fallback : $value;
	}

	/**
	 * All settings as a flat array (used by the calculation engine, AJAX and
	 * the Blocks integration).
	 *
	 * @return array<string,mixed>
	 */
	public function all_settings() {
		return array(
			'enabled'           => $this->get_option( self::OPT_ENABLED, 'yes' ),
			'mode'              => $this->get_option( self::OPT_MODE, 'disabled' ),
			'fixed'             => (float) $this->get_option( self::OPT_FIXED, 0 ),
			'percentage'        => (float) $this->get_option( self::OPT_PERCENTAGE, 0 ),
			'min'               => (float) $this->get_option( self::OPT_MIN, 0 ),
			'max'               => (float) $this->get_option( self::OPT_MAX, 0 ),
			'threshold'         => (float) $this->get_option( self::OPT_THRESHOLD, 0 ),
			'gateways'          => (array) $this->get_option( self::OPT_GATEWAYS, array() ),
			'msg_adv_enabled'   => $this->get_option( self::OPT_MSG_ADV_ENABLED, 'yes' ),
			'msg_adv_text'      => $this->get_option( self::OPT_MSG_ADV_TEXT, '' ),
			'msg_free_enabled'  => $this->get_option( self::OPT_MSG_FREE_ENABLED, 'yes' ),
			'msg_free_text'     => $this->get_option( self::OPT_MSG_FREE_TEXT, '' ),
			'msg_email_enabled' => $this->get_option( self::OPT_MSG_EMAIL_ENABLED, 'yes' ),
			'msg_email_text'    => $this->get_option( self::OPT_MSG_EMAIL_TEXT, '' ),
			'failure'           => $this->get_option( self::OPT_FAILURE, 'allow' ),
			'order_status'      => $this->get_option( self::OPT_ORDER_STATUS, 'processing' ),
			'fee_enabled'       => $this->get_option( self::OPT_FEE_ENABLED, 'no' ),
			'fee_type'          => $this->get_option( self::OPT_FEE_TYPE, 'fixed' ),
			'fee_amount'        => (float) $this->get_option( self::OPT_FEE_AMOUNT, 0 ),
			'fee_percentage'    => (float) $this->get_option( self::OPT_FEE_PERCENTAGE, 0 ),
			'fee_taxable'       => $this->get_option( self::OPT_FEE_TAXABLE, 'no' ),
			'fee_tax_class'     => (string) $this->get_option( self::OPT_FEE_TAX_CLASS, '' ),
		);
	}

	/**
	 * Is the global COD kill switch on (i.e. COD allowed at all)?
	 *
	 * @return bool
	 */
	public function cod_enabled() {
		return 'yes' === $this->get_option( self::OPT_ENABLED, 'yes' );
	}

	/**
	 * Is advance collection enabled for a given order total?
	 *
	 * Pure mode/threshold check — does NOT validate that a delegation gateway
	 * is configured (see delegation_gateway()).
	 *
	 * @param float $total Order/cart total.
	 * @return bool
	 */
	public function advance_is_required( $total ) {
		if ( ! $this->cod_enabled() ) {
			return false;
		}
		$settings = $this->all_settings();
		if ( 'disabled' === $settings['mode'] ) {
			return false;
		}
		$total = (float) $total;
		if ( $total <= 0 ) {
			return false;
		}
		if ( $settings['threshold'] > 0 && $total < $settings['threshold'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Is the cart/order total below the COD minimum order value?
	 *
	 * When the threshold is set (> 0), Cash on Delivery is hidden entirely
	 * for orders below it — independent of the advance mode. Threshold 0
	 * means no minimum (COD always allowed, subject to the kill switch).
	 *
	 * Pure function (same contract as calculate_advance()) so it can be
	 * unit-tested without WordPress.
	 *
	 * @param float               $total    Order/cart total.
	 * @param array<string,mixed> $settings Settings array (see all_settings()).
	 * @return bool
	 */
	public static function is_below_cod_minimum( $total, array $settings = array() ) {
		if ( empty( $settings ) ) {
			$settings = self::instance()->all_settings();
		}
		$threshold = (float) ( $settings['threshold'] ?? 0 );
		if ( $threshold <= 0 ) {
			return false;
		}
		return (float) $total < $threshold;
	}

	/**
	 * The configured delegation gateway object, or null.
	 *
	 * Looks the gateway up in the FULL registered gateway list (never the
	 * filtered "available" list, to avoid recursion) and verifies it is
	 * enabled.
	 *
	 * @return \WC_Payment_Gateway|null
	 */
	public function delegation_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$ids = (array) $this->get_option( self::OPT_GATEWAYS, array() );
		if ( empty( $ids ) ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		foreach ( $ids as $id ) {
			if ( isset( $gateways[ $id ] ) && 'yes' === $gateways[ $id ]->enabled ) {
				return $gateways[ $id ];
			}
		}
		return null;
	}

	/**
	 * Is native WooCommerce COD enabled in its own settings?
	 *
	 * @return bool
	 */
	public function native_cod_enabled() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['cod'] ) && 'yes' === $gateways['cod']->enabled;
	}

	/*
	---------------------------------------------------------------------
	 * Advance calculation — single source of truth (server-side).
	 */

	/**
	 * Calculate the advance amount for a given order total.
	 *
	 * Pure function: takes an explicit settings array (defaults to the stored
	 * options) so it can be unit-tested without WordPress and is never
	 * dependent on client input.
	 *
	 * Rules:
	 *  - mode 'disabled' or total <= 0            -> 0
	 *  - total < threshold                        -> 0
	 *  - fixed mode                               -> fixed value
	 *  - percentage mode                          -> total * pct/100
	 *  - clamped to [min, max] when those are > 0
	 *  - never exceeds the order total
	 *  - rounded to 2 decimal places
	 *
	 * @param float               $total    Order/cart total.
	 * @param array<string,mixed> $settings Settings array (see all_settings()).
	 * @return float
	 */
	public static function calculate_advance( $total, array $settings = array() ) {
		if ( empty( $settings ) ) {
			$settings = self::instance()->all_settings();
		}

		$total = (float) $total;

		if ( 'disabled' === ( $settings['mode'] ?? 'disabled' ) || $total <= 0 ) {
			return 0.0;
		}

		$threshold = (float) ( $settings['threshold'] ?? 0 );
		if ( $threshold > 0 && $total < $threshold ) {
			return 0.0;
		}

		if ( 'fixed' === ( $settings['mode'] ?? '' ) ) {
			$amount = (float) ( $settings['fixed'] ?? 0 );
		} else {
			$pct    = (float) ( $settings['percentage'] ?? 0 );
			$amount = $total * ( $pct / 100 );
		}

		$min = (float) ( $settings['min'] ?? 0 );
		$max = (float) ( $settings['max'] ?? 0 );
		if ( $min > 0 ) {
			$amount = max( $amount, $min );
		}
		if ( $max > 0 ) {
			$amount = min( $amount, $max );
		}

		// Advance must never exceed the order total (zero-total edge case).
		$amount = min( $amount, $total );

		return round( $amount * 100 ) / 100;
	}

	/*
	---------------------------------------------------------------------
	 * Extra COD fee calculation (features.md: "Extra COD Fees Option").
	 */

	/**
	 * Calculate the extra COD fee for a given order total.
	 *
	 * Pure function (same contract as calculate_advance()) so it can be
	 * unit-tested without WordPress.
	 *
	 * @param float               $total    Order/cart total (pre-fee).
	 * @param array<string,mixed> $settings Settings array (see all_settings()).
	 * @return float
	 */
	public static function calculate_fee( $total, array $settings = array() ) {
		if ( empty( $settings ) ) {
			$settings = self::instance()->all_settings();
		}

		$total = (float) $total;

		if ( 'no' === ( $settings['fee_enabled'] ?? 'no' ) || $total <= 0 ) {
			return 0.0;
		}

		if ( 'percentage' === ( $settings['fee_type'] ?? 'fixed' ) ) {
			$pct    = (float) ( $settings['fee_percentage'] ?? 0 );
			$amount = $total * ( $pct / 100 );
		} else {
			$amount = (float) ( $settings['fee_amount'] ?? 0 );
		}

		return round( $amount * 100 ) / 100;
	}

	/*
	---------------------------------------------------------------------
	 * Messaging
	 */

	/**
	 * Render a messaging template with placeholder substitution.
	 *
	 * Placeholders: {advance_amount}, {remaining_amount}, {percentage}.
	 *
	 * @param string $template  Message template.
	 * @param float  $advance   Advance amount.
	 * @param float  $remaining Remaining amount due on delivery.
	 * @param float  $pct       Percentage (for templates that display it).
	 * @return string
	 */
	public function format_message( $template, $advance, $remaining, $pct ) {
		return self::substitute_placeholders(
			$template,
			array(
				'advance_amount'   => wc_price( $advance ),
				'remaining_amount' => wc_price( $remaining ),
				'percentage'       => (string) round( $pct ),
			)
		);
	}

	/**
	 * Replace {placeholder} tokens in a template with the supplied values.
	 *
	 * Pure helper shared by checkout messaging and the order-email summary so
	 * the token set stays consistent and is unit-testable without WordPress.
	 *
	 * @param string               $template Template.
	 * @param array<string,string> $values   Token name (no braces) => replacement.
	 * @return string
	 */
	public static function substitute_placeholders( $template, array $values ) {
		$tokens = array();
		foreach ( $values as $key => $value ) {
			$tokens[ '{' . $key . '}' ] = (string) $value;
		}
		return strtr( (string) $template, $tokens );
	}

	/**
	 * The message shown when an advance is required ('' if messaging disabled
	 * or mode is not active).
	 *
	 * @param float $advance   Advance amount.
	 * @param float $remaining Remaining due.
	 * @return string
	 */
	public function get_advance_message( $advance, $remaining ) {
		$settings = $this->all_settings();
		if ( 'yes' !== $settings['msg_adv_enabled'] || empty( $settings['msg_adv_text'] ) ) {
			return '';
		}
		return $this->format_message( $settings['msg_adv_text'], $advance, $remaining, (float) $settings['percentage'] );
	}

	/**
	 * The "risk-free COD" message shown when no advance applies.
	 *
	 * @return string
	 */
	public function get_free_message() {
		$settings = $this->all_settings();
		if ( 'yes' !== $settings['msg_free_enabled'] || empty( $settings['msg_free_text'] ) ) {
			return '';
		}
		return (string) $settings['msg_free_text'];
	}

	/*
	---------------------------------------------------------------------
	 * Helpers
	 */

	/**
	 * Is HPOS (custom order tables) in use?
	 *
	 * @return bool
	 */
	public function is_hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Current cart total (0 if no cart).
	 *
	 * @return float
	 */
	public function get_cart_total() {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			return (float) WC()->cart->get_total( '' );
		}
		return 0.0;
	}

	/**
	 * Resolve the settings-section method name for a section slug.
	 *
	 * WC_Settings_Page builds the settings method as
	 * `get_settings_for_{section}_section`; PHP method names cannot contain
	 * hyphens, so hyphenated section slugs (e.g. "cod-fee", "order-handling")
	 * would resolve to a method that cannot exist and the tab renders empty.
	 * This helper maps every hyphen to an underscore so the declared methods
	 * (`get_settings_for_cod_fee_section`, ...) are found. Pure function so it
	 * is unit-testable without WordPress.
	 *
	 * @param string $section Section slug ('' for the default section).
	 * @return string
	 */
	public static function settings_method_for_section( $section ) {
		if ( '' === (string) $section ) {
			return 'get_settings_for_default_section';
		}
		return 'get_settings_for_' . str_replace( '-', '_', (string) $section ) . '_section';
	}

	/**
	 * Whether the advance flow is fully operational (mode active, threshold
	 * met, cart present and a delegation gateway configured).
	 *
	 * Used by the proxy gateway availability and checkout validation.
	 *
	 * @param float $total Order total (defaults to current cart total).
	 * @return bool
	 */
	public function advance_flow_active( $total = null ) {
		if ( null === $total ) {
			$total = $this->get_cart_total();
		}
		if ( ! $this->advance_is_required( $total ) ) {
			return false;
		}
		return null !== $this->delegation_gateway();
	}

	/**
	 * Whether an order still has an outstanding (unpaid) advance.
	 *
	 * Used on the order-pay endpoint where there is no cart, so the
	 * cart-based advance_flow_active() check cannot see the order.
	 *
	 * @param \WC_Order|mixed $order Order object.
	 * @return bool
	 */
	public function order_advance_outstanding( $order ) {
		if ( ! is_a( $order, '\WC_Order' ) || ! $this->cod_enabled() ) {
			return false;
		}
		$advance = (float) $order->get_meta( self::META_ADVANCE );
		if ( $advance <= 0 ) {
			return false;
		}
		return 'paid' !== $order->get_meta( self::META_STATUS );
	}

	/**
	 * Whether the current cart qualifies for Cash on Delivery.
	 *
	 * Mirrors the native WC_Gateway_COD availability rules so the advance
	 * proxy never appears for carts where native COD itself would be hidden:
	 * virtual-only orders (when COD is configured for shippable orders),
	 * physical-only orders (when COD is configured for virtual orders), and
	 * subscription/recurring-payment carts.
	 *
	 * @return bool
	 */
	public function cart_qualifies_for_cod() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return true; // Can't determine — don't block.
		}

		// Read the native COD gateway's enable_for_virtual setting so the
		// proxy mirrors the same shipping eligibility.
		$native_cod = WC()->payment_gateways()
			? WC()->payment_gateways()->payment_gateways()['cod'] ?? null
			: null;

		$enable_for_virtual = 'no'; // Native COD default.
		if ( $native_cod && isset( $native_cod->settings['enable_for_virtual'] ) ) {
			$enable_for_virtual = $native_cod->settings['enable_for_virtual'];
		}

		$needs_shipping = WC()->cart->needs_shipping();

		if ( 'no' === $enable_for_virtual && ! $needs_shipping ) {
			return false;
		}
		if ( 'virtual' === $enable_for_virtual && $needs_shipping ) {
			return false;
		}

		// Subscriptions: COD does not apply to recurring-payment carts.
		if ( class_exists( 'WC_Subscriptions_Cart' )
			&& method_exists( 'WC_Subscriptions_Cart', 'cart_contains_subscription' )
			&& \WC_Subscriptions_Cart::cart_contains_subscription()
		) {
			return false;
		}

		return true;
	}

	/**
	 * Replace the trailing price on a checkout button label with a new amount.
	 *
	 * Themes commonly append the formatted order total to the "Place order"
	 * button label ("Place Order ₹1,042"). This strips the trailing price(s)
	 * and appends the replacement amount, preserving the label prefix and the
	 * separator in front of the price. When the label has no trailing price,
	 * the text is returned unchanged.
	 *
	 * Every trailing price is stripped, not just one: a plugin such as
	 * CartFlows appends the full cart total AFTER the plugin's own
	 * priority-20 pass already replaced a theme-appended price, which would
	 * otherwise leave a stale amount on the button ("… ₹99 ₹1,042”). The
	 * first separator run is preserved so the output keeps the label's
	 * spacing style.
	 *
	 * Handles both currency positions ("₹1,042" and "1,042₹") and both real
	 * non-breaking spaces and literal "&nbsp;" entities as separators.
	 *
	 * Pure function (no WordPress) so it can be unit-tested.
	 *
	 * @param string $text       Existing button label.
	 * @param string $new_amount Plain-text replacement amount (e.g. "₹104").
	 * @return string
	 */
	public static function replace_button_price( $text, $new_amount ) {
		$text       = (string) $text;
		$new_amount = (string) $new_amount;

		$symbol = '[\p{Sc}\p{So}]';
		$sep    = '(?:\s|\xC2\xA0|&nbsp;)+';

		// Group 1 = separator in front of the price, preserved in the output.
		$pattern = '/(' . $sep . ')?(?:' . $symbol . '\s?[0-9][0-9.,]*|[0-9][0-9.,]*\s?' . $symbol . ')$/u';

		$separator = '';
		$cleaned   = $text;
		$previous  = null;
		while ( $previous !== $cleaned && preg_match( $pattern, $cleaned, $m ) ) {
			$previous = $cleaned;
			if ( '' === $separator && isset( $m[1] ) && '' !== $m[1] ) {
				$separator = $m[1];
			}
			$cleaned = preg_replace( $pattern, '', $cleaned );
		}

		if ( $cleaned === $text ) {
			return $text; // No trailing price — nothing to replace.
		}

		// $new_amount is appended verbatim (no preg_replace), so a literal "$"
		// in the amount (e.g. USD) can never be read as a backreference.
		return $cleaned . $separator . $new_amount;
	}

	/**
	 * Append an amount to a checkout button label that carries no price yet.
	 *
	 * CartFlows funnel checkouts append the full cart total with exactly
	 * this separator ("&nbsp;&nbsp;") when the step's "show price on button"
	 * option is enabled; when the option is off the button has no price at
	 * all and this plugin appends the amount charged right now with the same
	 * separator, so the result is visually identical to CartFlows' own
	 * output. Plain checkouts and non-COD methods are never appended to.
	 *
	 * The caller must only call this when the label has no trailing price
	 * (i.e. replace_button_price() returned the input unchanged) — appending
	 * on top of an existing price would double it.
	 *
	 * Pure function (no WordPress) so it can be unit-tested.
	 *
	 * @param string $text       Existing button label.
	 * @param string $new_amount Plain-text amount to append (e.g. "₹104").
	 * @return string
	 */
	public static function append_button_price( $text, $new_amount ) {
		return (string) $text . '&nbsp;&nbsp;' . (string) $new_amount;
	}

	/**
	 * Decimal precision of the price shown on a checkout button label.
	 *
	 * "Place Order ₹1,042" -> 0, "Pay ₹1,042.50" -> 2, "1,042₹" -> 0.
	 * Used so the advance shown on the button matches the store's existing
	 * price formatting. Falls back to 2 (the WooCommerce default) when no
	 * price is present.
	 *
	 * Pure function (no WordPress) so it can be unit-tested.
	 *
	 * @param string $text Button label.
	 * @return int
	 */
	public static function label_price_decimals( $text ) {
		$text   = (string) $text;
		$symbol = '[\p{Sc}\p{So}]';
		$sep    = '(?:\s|\xC2\xA0|&nbsp;)+';

		$number = null;
		if ( preg_match( '/(?:' . $sep . ')?' . $symbol . '\s?([0-9][0-9.,]*)$/u', $text, $m ) ) {
			$number = $m[1];
		} elseif ( preg_match( '/(?:' . $sep . ')?([0-9][0-9.,]*)\s?' . $symbol . '$/u', $text, $m ) ) {
			$number = $m[1];
		}
		if ( null === $number ) {
			return 2;
		}

		if ( false !== strpos( $number, '.' ) ) {
			return strlen( explode( '.', $number )[1] );
		}
		if ( false !== strpos( $number, ',' ) ) {
			$parts = explode( ',', $number );
			$last  = (string) array_pop( $parts );
			// "1,042" / "1,00,000" -> comma is a thousands separator -> 0.
			return ( 3 === strlen( $last ) ) ? 0 : strlen( $last );
		}
		return 0;
	}
}
