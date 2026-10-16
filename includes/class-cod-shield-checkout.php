<?php
/**
 * Storefront behaviour: global COD kill switch, checkout messaging, server-side
 * validation, advance meta attach on order creation (classic + Blocks), and
 * thank-you summary.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Checkout class.
 */
class Checkout {

	/**
	 * Whether the native COD gateway was removed from the current available
	 * gateway list specifically so the advance proxy can replace it.
	 *
	 * Gateway_Bridge runs on the same filter at priority 20 and must not
	 * resurrect native COD that WooCommerce core already removed (e.g. the
	 * COD gateway's "enable for methods" shipping restriction). This flag is
	 * the only evidence that the cod entry was removed deliberately for the
	 * proxy swap, because the bridge can no longer see it in the list.
	 *
	 * @var bool
	 */
	private static $cod_removed_for_swap = false;

	/**
	 * Whether native COD was removed from the available list for proxy swap.
	 *
	 * @return bool
	 */
	public static function cod_removed_for_swap() {
		return self::$cod_removed_for_swap;
	}

	/**
	 * Whether the current REST request serves the Store API cart.
	 *
	 * @var bool
	 */
	private $store_api_cart_request = false;

	/**
	 * Hook registration.
	 */
	public function __construct() {
		// Global COD kill switch + native COD / proxy swapping.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_available_gateways' ), 10 );
		add_filter( 'woocommerce_gateway_title', array( $this, 'filter_cod_title' ), 10, 2 );

		// Native descriptions refresh with WooCommerce checkout fragments.
		add_filter( 'woocommerce_gateway_description', array( $this, 'filter_cod_description' ), 10, 2 );
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_cod_minimum_notice' ) );

		// "Place order" button shows what the customer actually pays right now:
		// the advance, not the full order total, when the advance flow applies.
		// Priority 20 so it runs after theme/plugin label filters (usually 10).
		// This early pass only REPLACES an existing trailing price — it never
		// appends, because CartFlows' own append at priority 99 has not run yet.
		add_filter( 'woocommerce_order_button_text', array( $this, 'filter_order_button_text' ), 20, 1 );

		// Late pass (after CartFlows' own filter at priority 99): CartFlows
		// funnel checkouts append the FULL cart total to the button label when
		// the step's "show price on button" option is enabled, and can replace
		// the whole label with the step's custom button text. Running again at
		// 1000 re-applies the advance after that append (Core::replace_button_price()
		// strips every trailing price, so a CartFlows-appended total can never
		// double up with the advance already placed at priority 20). It also
		// APPENDS the amount charged right now when that step option is off and
		// the button has no price at all — CartFlows-only, COD/advance methods
		// only (see filter_order_button_text_late()). Harmless no-op on plain
		// checkouts: no trailing price -> label left untouched.
		add_filter( 'woocommerce_order_button_text', array( $this, 'filter_order_button_text_late' ), 1000, 1 );
		add_filter( 'woocommerce_pay_order_button_text', array( $this, 'filter_order_button_text_late' ), 1000, 1 );

		// Server-side validation (classic checkout).
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout' ) );

		// Attach advance meta at order creation (classic + Blocks/Store API).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'attach_advance_meta_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'attach_advance_meta_blocks' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'attach_advance_meta_blocks' ), 10, 1 );

		// Store API validation for the block checkout (fail-closed).
		add_filter( 'woocommerce_store_api_cart_errors', array( $this, 'filter_store_api_cart_errors' ), 10, 2 );

		// Thank-you page confirmation of both amounts.
		add_action( 'woocommerce_thankyou', array( $this, 'render_thankyou_summary' ), 10, 1 );

		// Cart fees are visible before submission in classic and block checkout.
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'maybe_add_cod_fee' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'track_store_api_request' ), 10, 3 );
	}

	/**
	 * Kill switch + swap native COD for the proxy gateway.
	 *
	 * - Kill switch off -> remove native COD (and the proxy).
	 * - Advance flow active -> remove native COD (proxy added in
	 *   Gateway_Bridge on the same filter, priority 20).
	 * - Advance required but NOT operational (no delegation gateway) -> remove
	 *   native COD too: fail closed, never silently skip the advance.
	 * - Otherwise -> leave native COD alone.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways Available gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function filter_available_gateways( $gateways ) {
		$core = Core::instance();
		// Reset per evaluation: each filter run must independently prove that
		// native COD was deliberately removed for the proxy swap.
		self::$cod_removed_for_swap = false;
		if ( isset( $gateways['cod'] ) ) {
			$custom_title = trim( (string) $core->get_option( Core::OPT_COD_TITLE, '' ) );
			if ( '' !== $custom_title ) {
				$gateways['cod']->title = $custom_title;
			}
		}

		$order_pay = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );

		// Fail-closed on the "pay for order" endpoint: while an order still
		// owes an advance (pending or failed), native COD must not be
		// selectable there. The cart is empty on that page, so the
		// advance-is-required check below cannot see the order total;
		// selecting native COD on the pay form would complete the order
		// without collecting the advance. The advance proxy is added back by
		// Gateway_Bridge (priority 20) so the advance can be retried.
		if ( $order_pay ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order && $core->order_advance_outstanding( $order ) ) {
				unset( $gateways['cod'] );
				return $gateways;
			}
		}

		if ( ! $core->cod_enabled() ) {
			unset( $gateways['cod'], $gateways[ Core::GATEWAY_ID ] );
			return $gateways;
		}

		if ( ! isset( $gateways['cod'] ) ) {
			return $gateways; // Native COD disabled in WC itself.
		}

		// Order-pay endpoint for a non-advance order: there is no cart, so the
		// storefront minimum-order-value and advance-required policies (both
		// derived from the cart total below) cannot be evaluated here — they
		// are checkout-time gates. An existing order is paid with whatever
		// enabled gateways it can use, and the global kill switch above still
		// applies. (Advance orders were already handled by the
		// outstanding-advance branch; the proxy is added back on priority 20.)
		// Without this, the cart total reads 0 on this page and native COD
		// would be hidden for every order whenever a threshold is configured.
		if ( $order_pay ) {
			return $gateways;
		}

		// Order-value floor: below the configured threshold COD is hidden
		// entirely (native COD and the advance proxy) — independent of the
		// advance mode. At/above the threshold, normal rules apply.
		if ( $core->is_below_cod_minimum( $core->get_cart_total() ) ) {
			unset( $gateways['cod'], $gateways[ Core::GATEWAY_ID ] );
			return $gateways;
		}

		$required = $core->advance_is_required( $core->get_cart_total() );
		if ( $required && ! $core->advance_flow_active() ) {
			// Misconfigured: advance is due but cannot be collected. Fail closed.
			unset( $gateways['cod'] );
		} elseif ( $required ) {
			unset( $gateways['cod'] ); // Replaced by the proxy (priority 20).
			// The only legitimate pre-proxy removal: record it so Gateway_Bridge
			// (priority 20) can distinguish this swap from a WC-core removal
			// (shipping-method restriction / virtual cart / disabled gateway).
			self::$cod_removed_for_swap = true;
		}

		return $gateways;
	}

	/**
	 * Apply the custom title to native COD and the advance proxy.
	 *
	 * @param string $title      Existing gateway title.
	 * @param string $gateway_id Gateway id.
	 * @return string
	 */
	public function filter_cod_title( $title, $gateway_id ) {
		if ( ! in_array( $gateway_id, array( 'cod', Core::GATEWAY_ID ), true ) ) {
			return $title;
		}
		return Core::instance()->get_cod_title( $title );
	}

	/**
	 * Append the risk-free message to the native COD description on the classic
	 * checkout when no advance applies. (Blocks ignores this filter; the block
	 * integration renders its own content.)
	 *
	 * @param string $description Gateway description.
	 * @param string $gateway_id  Gateway id.
	 * @return string
	 */
	public function filter_cod_description( $description, $gateway_id ) {
		if ( 'cod' !== $gateway_id ) {
			return $description;
		}

		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return $description;
		}

		// When an advance is required native COD is not selectable anyway.
		if ( $core->advance_is_required( $core->get_cart_total() ) ) {
			return $description;
		}

		$message = $core->get_free_message();
		if ( '' === $message ) {
			return $description;
		}

		return $description . ( '' !== $description ? ' ' : '' ) . '<span class="cod-shield-free-note">' . esc_html( $message ) . '</span>';
	}

	/**
	 * Make the "Place order" button show the amount charged right now.
	 *
	 * EARLY pass (priority 20). On the classic checkout, themes commonly
	 * append the order total to the button label ("Place Order ₹1,042"). When
	 * the COD advance flow applies the customer is charged ONLY the advance
	 * via the delegation gateway (the remainder is due on delivery), so
	 * showing the full total on the button is misleading. Replace it with the
	 * advance amount, matching the label's existing decimal precision. Pure
	 * COD (no advance) and non-COD methods are left untouched here — the
	 * total is paid on delivery as shown, or CartFlows' own setting governs.
	 *
	 * This pass never appends a missing price: CartFlows' own append at
	 * priority 99 has not run yet, so appending here would double up once it
	 * does. The late pass (filter_order_button_text_late(), priority 1000)
	 * handles the price-less case.
	 *
	 * @param string $text Button label.
	 * @return string
	 */
	public function filter_order_button_text( $text ) {
		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return $text;
		}

		$resolved = $this->resolve_button_amount( false );
		if ( null === $resolved['amount'] || $resolved['amount'] <= 0 ) {
			return $text;
		}

		return Core::replace_button_price( $text, $this->format_button_amount( $text, $resolved['amount'] ) );
	}

	/**
	 * Make the "Place order" button show the amount charged right now.
	 *
	 * LATE pass (priority 1000, after CartFlows' own filter at 99). CartFlows
	 * funnel checkouts append the FULL cart total to the button label when the
	 * step's "show price on button" option is enabled, and can replace the
	 * whole label with the step's custom button text; running again here
	 * re-applies the advance after that append (replace_button_price() strips
	 * every trailing price, so a CartFlows-appended total can never double up
	 * with the advance). When that step option is OFF the button has no price
	 * at all by this point, and the amount charged right now — the advance,
	 * or the full total for plain COD — is APPENDED instead, using CartFlows'
	 * own separator. Only for the COD/advance methods, and only on CartFlows
	 * checkouts; non-COD methods and plain checkouts keep CartFlows' / the
	 * theme's own behaviour.
	 *
	 * The label is server-rendered and refreshed on every update_checkout
	 * fragment update, so selecting the COD advance method live-updates the
	 * shown amount.
	 *
	 * @param string $text Button label.
	 * @return string
	 */
	public function filter_order_button_text_late( $text ) {
		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return $text;
		}

		$resolved = $this->resolve_button_amount( true );
		if ( null === $resolved['amount'] || $resolved['amount'] <= 0 ) {
			// For non-COD prepaid methods (UPI, cards, Razorpay, etc.) on
			// CartFlows checkouts: resolve_button_amount() returns null because
			// this plugin does not own the label for those methods. When the
			// CartFlows step's "show price on button" option is OFF the button
			// arrives price-less here, so append the cart total with the same
			// separator CartFlows uses so the customer sees what they'll pay.
			// Gate: only on CartFlows, only for non-COD methods, total > 0.
			if ( $this->is_cartflows_checkout() ) {
				$method = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
				if ( '' !== $method && 'cod' !== $method && Core::GATEWAY_ID !== $method ) {
					$total = $core->get_cart_total();
					if ( $total > 0 ) {
						return Core::append_button_price( $text, wp_strip_all_tags( wc_price( $total ) ) );
					}
				}
			}
			return $text;
		}

		if ( ! $resolved['append_only'] ) {
			return Core::advance_button_label( html_entity_decode( wp_strip_all_tags( wc_price( $resolved['amount'] ) ), ENT_QUOTES, 'UTF-8' ) );
		}

		$new_amount = $this->format_button_amount( $text, $resolved['amount'] );

		if ( $resolved['append_only'] ) {
			// Plain COD: the whole total is paid on delivery. Only ever APPEND
			// a missing price on CartFlows checkouts — an existing price
			// (theme, or the CartFlows step option appending at 99) already
			// shows the correct total and must be left untouched. The replace
			// call doubles as the "is a price present?" probe: unchanged means
			// the label is price-less.
			if ( Core::replace_button_price( $text, $new_amount ) !== $text ) {
				return $text;
			}
			if ( $this->is_cartflows_checkout() ) {
				return Core::append_button_price( $text, wp_strip_all_tags( wc_price( $resolved['amount'] ) ) );
			}
			return $text;
		}

		// Advance proxy: replace an existing trailing price with the advance.
		$replaced = Core::replace_button_price( $text, $new_amount );
		if ( $replaced !== $text ) {
			return $replaced;
		}

		// No price on the button: on a CartFlows checkout whose step has the
		// "show price on button" option off, append the advance — formatted
		// with the store's own price decimals, exactly as CartFlows itself
		// would append ("&nbsp;&nbsp;" + total). Plain checkouts and non-COD
		// methods are never touched here.
		if ( $this->is_cartflows_checkout() ) {
			return Core::append_button_price( $text, wp_strip_all_tags( wc_price( $resolved['amount'] ) ) );
		}

		return $text;
	}

	/**
	 * Resolve the amount the "Place order" button should display.
	 *
	 * Returns the amount for the chosen COD/advance method, or a null amount
	 * when the button must be left untouched (COD disabled, method/flow does
	 * not qualify, below the COD minimum, or a non-COD method — where
	 * CartFlows' own price-display setting governs).
	 *
	 * $include_plain_cod gates the native-COD case: the early pass (priority
	 * 20) runs BEFORE CartFlows' own append at 99, so it must never append a
	 * plain-COD total (that would double up once CartFlows appends); the
	 * late pass (1000) runs after CartFlows and may.
	 *
	 * @param bool $include_plain_cod Whether the native-COD (full total) case
	 *                                is eligible.
	 * @return array{amount: float|null, append_only: bool}
	 */
	private function resolve_button_amount( $include_plain_cod ) {
		$core = Core::instance();

		// order-pay endpoint: no cart, so read the recorded advance from the
		// order itself. While an advance is outstanding the proxy is the only
		// offered method there, so the customer pays exactly the advance.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order && $core->order_advance_outstanding( $order ) ) {
				return array(
					'amount'      => (float) $order->get_meta( Core::META_ADVANCE ),
					'append_only' => false,
				);
			}
			return array(
				'amount'      => null,
				'append_only' => false,
			);
		}

		$method = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';

		if ( Core::GATEWAY_ID === $method ) {
			// Advance proxy chosen: the customer pays the advance right now.
			$total = $core->get_cart_total();
			if ( $core->is_below_cod_minimum( $total ) ) {
				return array(
					'amount'      => null,
					'append_only' => false,
				); // COD hidden below the minimum anyway.
			}
			if ( ! $core->advance_flow_active() ) {
				return array(
					'amount'      => null,
					'append_only' => false,
				); // Fail-closed: proxy not operational; leave as-is.
			}
			return array(
				'amount'      => $core->calculate_advance( $total ),
				'append_only' => false,
			);
		}

		if ( 'cod' === $method ) {
			if ( ! $include_plain_cod ) {
				// Early pass: CartFlows @99 appends the full total itself when
				// its step option is on; appending here would double it.
				return array(
					'amount'      => null,
					'append_only' => false,
				);
			}
			// Plain COD: the whole total is paid on delivery. Only ever
			// APPENDED when the button has no price (CartFlows step option
			// off) — an existing price already shows the correct total.
			$total = $core->get_cart_total();
			if ( $core->is_below_cod_minimum( $total ) || $core->advance_is_required( $total ) ) {
				return array(
					'amount'      => null,
					'append_only' => false,
				);
			}
			return array(
				'amount'      => $total,
				'append_only' => true,
			);
		}

		// Non-COD method: CartFlows' own price-display setting governs.
		return array(
			'amount'      => null,
			'append_only' => false,
		);
	}

	/**
	 * Format an amount for the button label, matching the label's existing
	 * decimal precision (₹1,042 -> 0 decimals) so the amount looks like the
	 * store's own price formatting.
	 *
	 * @param string $text   Button label.
	 * @param float  $amount Amount.
	 * @return string
	 */
	private function format_button_amount( $text, $amount ) {
		return wp_strip_all_tags(
			wc_price(
				$amount,
				array( 'decimals' => Core::label_price_decimals( $text ) )
			)
		);
	}

	/**
	 * Is the current page a CartFlows funnel checkout?
	 *
	 * Covers the page render of a funnel checkout step and CartFlows' own
	 * update_checkout AJAX (where the $post global is not the step), plus the
	 * shortcode-embedded checkout whose step id travels with the form/request
	 * — mirroring the checks CartFlows' own place_order_button_text() uses to
	 * resolve the checkout step. Plain (non-CartFlows) checkouts always return
	 * false.
	 *
	 * @return bool
	 */
	private function is_cartflows_checkout() {
		if ( function_exists( '_is_wcf_checkout_type' ) && _is_wcf_checkout_type() ) {
			return true;
		}
		if ( function_exists( '_is_wcf_doing_checkout_ajax' ) && _is_wcf_doing_checkout_ajax() ) {
			return true;
		}
		// Shortcode-embedded checkout: the step id is sent with the form and
		// the AJAX/request, exactly as CartFlows' own resolution expects.
		// Read-only presence check; no state change and no value processing.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_POST['_wcf_checkout_id'] ) || ! empty( $_GET['wcf_checkout_id'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Server-side validation on the classic checkout.
	 *
	 * @return void
	 */
	public function validate_checkout() {
		$core = Core::instance();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- Nonce verified by WooCommerce checkout; value sanitized with wc_clean()/wp_unslash().
		$method = isset( $_POST['payment_method'] ) ? wc_clean( wp_unslash( $_POST['payment_method'] ) ) : '';

		// Defense in depth: COD selected on an order below the minimum order
		// value (the total can change between page render and submit, e.g.
		// via a coupon applied on the checkout page).
		if ( in_array( $method, array( 'cod', Core::GATEWAY_ID ), true ) && $core->is_below_cod_minimum( $core->get_cart_total() ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: minimum order value. */
					__( 'Cash on Delivery is available for orders of at least %s. Please choose another payment method.', 'cod-shield' ),
					wc_price( (float) $core->get_option( Core::OPT_THRESHOLD, 0 ) )
				),
				'error'
			);
			return;
		}

		// Defense in depth: native COD selected while an advance is required.
		if ( 'cod' === $method && $core->advance_is_required( $core->get_cart_total() ) ) {
			wc_add_notice( __( 'This order requires an advance payment. Please select the "Cash on delivery (advance)" option.', 'cod-shield' ), 'error' );
			return;
		}

		// Proxy selected but flow not operational -> block.
		if ( Core::GATEWAY_ID === $method && ! $core->advance_flow_active() ) {
			wc_add_notice( __( 'Cash on Delivery is temporarily unavailable. Please choose another payment method.', 'cod-shield' ), 'error' );
		}
	}

	/**
	 * Attach advance meta on the classic checkout (fires before $order->save(),
	 * so no explicit save is needed).
	 *
	 * @param \WC_Order $order Order.
	 * @param array     $data  Posted checkout data (hook signature; unused).
	 * @return void
	 */
	public function attach_advance_meta_classic( $order, $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}
		$this->attach_advance_meta( $order );
	}

	/**
	 * Attach advance meta on the Blocks/Store API checkout. This hook may fire
	 * more than once; the routine is idempotent and saves explicitly.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	public function attach_advance_meta_blocks( $order ) {
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}
		$this->attach_advance_meta( $order );
		$order->save();
	}

	/**
	 * Attach advance meta when the order's payment method is the proxy gateway.
	 * Idempotent via the awaiting flag.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private function attach_advance_meta( $order ) {
		if ( Core::GATEWAY_ID !== $order->get_payment_method() ) {
			return;
		}
		if ( 'yes' === $order->get_meta( Core::META_AWAITING ) ) {
			return; // Already attached.
		}

		$core    = Core::instance();
		$total   = (float) $order->get_total();
		$advance = $core->calculate_advance( $total );

		$order->update_meta_data( Core::META_ADVANCE, $advance );
		$order->update_meta_data( Core::META_MODE, $core->get_option( Core::OPT_MODE, 'disabled' ) );
		$order->update_meta_data( Core::META_ORIGINAL_TOTAL, $total );
		$order->update_meta_data( Core::META_REMAINING, max( 0.0, $total - $advance ) );
		$order->update_meta_data( Core::META_STATUS, 'pending' );
		$order->update_meta_data( Core::META_AWAITING, 'yes' );
	}

	/**
	 * Fail-closed validation for the block checkout (Store API).
	 *
	 * @param \WP_Error $errors Errors object.
	 * @param \WC_Cart  $cart   Cart.
	 * @return \WP_Error
	 */
	public function filter_store_api_cart_errors( $errors, $cart ) {
		$core = Core::instance();
		if ( ! $core->cod_enabled() ) {
			return $errors;
		}

		// These are COD-specific rules. They must only block checkout when the
		// customer has actually chosen COD (or the advance proxy) — never when
		// they are paying by card / UPI / etc. The Store API throws an HTTP 409
		// (InvalidCartException) for ANY error added here, so adding them
		// unconditionally would block every low-value order regardless of the
		// selected method — even though the messages say "choose another payment
		// method". Gateway availability (filter_available_gateways(), which the
		// Store API also evaluates) already hides COD below the threshold and when
		// the advance is misconfigured; this filter is the fail-closed safety net
		// for the "customer still has COD selected" race, so it only needs to fire
		// once COD is the committed method.
		$method = ( function_exists( 'WC' ) && WC()->session ) ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
		if ( ! in_array( $method, array( 'cod', Core::GATEWAY_ID ), true ) ) {
			return $errors;
		}

		$total = (float) $cart->get_total( '' );

		// Order-value floor for the block checkout (fail-closed, COD only).
		if ( $core->is_below_cod_minimum( $total ) ) {
			$errors->add(
				'cod_shield_minimum',
				sprintf(
					/* translators: %s: minimum order value. */
					__( 'Cash on Delivery is available for orders of at least %s. Please choose another payment method.', 'cod-shield' ),
					wc_price( (float) $core->get_option( Core::OPT_THRESHOLD, 0 ) )
				)
			);
		}

		if ( $core->advance_is_required( $total ) && ! $core->advance_flow_active( $total ) ) {
			$errors->add(
				'cod_shield_unavailable',
				__( 'Cash on Delivery is temporarily unavailable. Please choose another payment method.', 'cod-shield' )
			);
		}
		return $errors;
	}

	/**
	 * Track Store API cart and checkout requests, including pretty and plain permalinks.
	 *
	 * @param mixed            $response REST response.
	 * @param array            $handler  Route handler.
	 * @param \WP_REST_Request $request  Current request.
	 * @return mixed
	 */
	public function track_store_api_request( $response, $handler, $request ) {
		$this->store_api_cart_request = (bool) preg_match( '#^/wc/store/v[0-9]+/(cart|checkout)(?:/|$)#', $request->get_route() );
		if ( null !== $response || 'POST' !== $request->get_method() || ! preg_match( '#^/wc/store/v[0-9]+/checkout/?$#', $request->get_route() ) ) {
			return $response;
		}
		$core = Core::instance();
		if ( ! $core->cod_enabled() || 'yes' !== $core->get_option( Core::OPT_FEE_ENABLED, 'no' ) ) {
			return $response;
		}
		$method = $request->get_param( 'payment_method' );
		if ( ! is_string( $method ) || '' === $method ) {
			return $response;
		}
		$preview_method = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
		$cod_methods    = array( 'cod', Core::GATEWAY_ID );
		if ( in_array( $method, $cod_methods, true ) !== in_array( $preview_method, $cod_methods, true ) ) {
			return new \WP_Error(
				'cod_shield_payment_totals_changed',
				__( 'Your payment method changed. Please select it again and wait for the order total to update before placing your order.', 'cod-shield' ),
				array( 'status' => 409 )
			);
		}
		return $response;
	}

	/**
	 * Explain why COD is unavailable inside the refreshed classic payment fragment.
	 *
	 * @return void
	 */
	public function render_cod_minimum_notice() {
		$core = Core::instance();
		if ( ! $core->cod_enabled() || ! $core->is_below_cod_minimum( $core->get_cart_total() ) ) {
			return;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		if ( ! isset( $gateways['cod'] ) || 'yes' !== $gateways['cod']->enabled ) {
			return;
		}
		echo '<p class="woocommerce-info cod-shield-minimum-notice" role="status">' . wp_kses_post(
			sprintf(
				/* translators: %s: minimum order value for cash on delivery. */
				__( 'Cash on delivery is available on orders of %s or more.', 'cod-shield' ),
				wc_price( (float) $core->get_option( Core::OPT_THRESHOLD, 0 ) )
			)
		) . '</p>';
	}

	/**
	 * Extra COD fee — classic and block checkout: add the fee to the cart when COD (or
	 * the advance proxy, which is still a COD order) is the chosen method.
	 *
	 * @param \WC_Cart $cart Cart.
	 * @return void
	 */
	public function maybe_add_cod_fee( $cart ) {
		if ( ! $this->store_api_cart_request && ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) ) {
			return;
		}

		$core = Core::instance();
		if ( 'no' === $core->get_option( Core::OPT_FEE_ENABLED, 'no' ) || ! $core->cod_enabled() ) {
			return;
		}

		$method = WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
		if ( ! in_array( $method, array( 'cod', Core::GATEWAY_ID ), true ) ) {
			return;
		}

		// WC_Cart::get_total() can still contain the previous calculation here.
		$total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		foreach ( $cart->get_fees() as $other_fee ) {
			if ( sanitize_title( __( 'Cash on Delivery Fee', 'cod-shield' ) ) !== $other_fee->id ) {
				$total += (float) $other_fee->amount + (float) ( $other_fee->tax ?? 0 );
			}
		}
		$fee = $core->calculate_fee( $total );
		if ( $fee <= 0 ) {
			return;
		}

		$settings = $core->all_settings();
		$cart->add_fee(
			__( 'Cash on Delivery Fee', 'cod-shield' ),
			$fee,
			'yes' === $settings['fee_taxable'],
			(string) $settings['fee_tax_class']
		);
	}

	/**
	 * Thank-you page summary showing both amounts for finalized advance orders.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function render_thankyou_summary( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || 'cod' !== $order->get_payment_method() ) {
			return;
		}

		$advance = (float) $order->get_meta( Core::META_ADVANCE );
		$status  = $order->get_meta( Core::META_STATUS );
		if ( 'paid' !== $status || $advance <= 0 ) {
			return;
		}

		$remaining = (float) $order->get_meta( Core::META_REMAINING );

		echo '<section class="cod-shield-thankyou">';
		echo '<h2 class="woocommerce-order-details__title">' . esc_html__( 'Payment summary', 'cod-shield' ) . '</h2>';
		echo '<ul class="woocommerce-order-overview woocommerce-thankyou-order-details">';
		echo '<li>' . esc_html__( 'Advance paid:', 'cod-shield' ) . ' <strong>' . wp_kses_post( wc_price( $advance ) ) . '</strong>';
		echo '</li>';
		echo '<li>' . esc_html__( 'Payable on delivery:', 'cod-shield' ) . ' <strong>' . wp_kses_post( wc_price( $remaining ) ) . '</strong></li>';
		echo '</ul></section>';
	}
}
