<?php
/**
 * The hidden "COD + Advance" proxy gateway.
 *
 * Flow:
 *  1. Customer selects the proxy method at checkout (it replaces native COD
 *     while an advance is required).
 *  2. process_payment() computes the advance server-side, stashes the original
 *     order total in meta, temporarily sets the order total to the advance
 *     amount, and delegates to the admin-selected gateway's process_payment().
 *  3. The delegation gateway runs its normal flow and charges only the
 *     advance amount.
 *  4. On payment completion, Gateway_Bridge::finalize_advance_order() restores
 *     the full total and converts the order back to plain COD.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Proxy payment gateway.
 *
 * @property string $id
 */
class Gateway_COD_Advance extends \WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Core::GATEWAY_ID;
		$this->method_title       = __( 'Cash on Delivery (with advance)', 'cod-shield' );
		$this->method_description = __( 'Internal proxy gateway that collects a partial advance payment on COD orders via an existing enabled gateway. Not shown in gateway settings.', 'cod-shield' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->enabled            = 'yes'; // Availability is governed by is_available().
		$this->title              = Core::instance()->get_cod_title();
		$this->description        = '';
	}

	/**
	 * The proxy is available when the advance flow is active for the current
	 * cart, or — on the order-pay endpoint (no cart) — when the order itself
	 * still owes an advance.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$core = Core::instance();
		if ( ! $core->cod_enabled() || ! $core->native_cod_enabled() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			// Mirror Gateway_Bridge::maybe_add_proxy_gateway(): the proxy is
			// only retryable on order-pay when a delegation gateway is still
			// configured (fail-closed otherwise).
			return $order && $core->order_advance_outstanding( $order ) && $core->delegation_gateway();
		}

		// Mirror the native COD gateway's shipping and subscription
		// rules so the advance proxy never appears for carts where native
		// COD itself would be hidden.
		if ( ! $core->cart_qualifies_for_cod() ) {
			return false;
		}

		return $core->advance_flow_active();
	}

	/**
	 * Show a live message under the payment method radio at checkout.
	 *
	 * @return string
	 */
	public function get_description() {
		$core    = Core::instance();
		$total   = $core->get_cart_total();
		$advance = $core->calculate_advance( $total );

		// Order-pay endpoint: there is no cart, so read the amounts recorded
		// on the order when the advance was initiated instead of deriving
		// them from the (empty) cart.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				$order_advance = (float) $order->get_meta( Core::META_ADVANCE );
				if ( $order_advance > 0 ) {
					$advance   = $order_advance;
					$remaining = (float) $order->get_meta( Core::META_REMAINING );
					if ( $remaining <= 0 ) {
						$remaining = max( 0.0, (float) $order->get_total() - $advance );
					}
					// Keep the fall-through math below consistent.
					$total = $advance + $remaining;
				}
			}
		}

		$message = $core->get_advance_message( $advance, max( 0.0, $total - $advance ) );

		if ( '' !== $message ) {
			// Wrapped in a highlighted callout (public/css/checkout.css) so the
			// key commitment — how much is due now — is scanned in a glance.
			return '<div class="cod-shield-advance-notice">' . wp_kses_post( $message ) . '</div>';
		}

		// Fall back to a plain explanation when messaging is disabled.
		return '<div class="cod-shield-advance-notice">' . sprintf(
			/* translators: 1: advance amount, 2: remaining amount. */
			__( 'Pay %1$s now, %2$s on delivery (COD).', 'cod-shield' ),
			wc_price( $advance ),
			wc_price( max( 0.0, $total - $advance ) )
		) . '</div>';
	}

	/**
	 * Collect the advance via the configured delegation gateway.
	 *
	 * @param int $order_id Order id.
	 * @return array<string,mixed>
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		$core  = Core::instance();

		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$total   = (float) $order->get_total();
		$advance = $core->calculate_advance( $total );

		// Safety: the advance must be positive for this method to be used.
		if ( $advance <= 0 ) {
			wc_add_notice( __( 'The advance payment is not available for this order. Please choose a different payment method.', 'cod-shield' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$gateway = $core->delegation_gateway();
		if ( ! $gateway ) {
			wc_add_notice( __( 'Cash on Delivery is temporarily unavailable. Please choose another payment method.', 'cod-shield' ), 'error' );
			$this->log_advance_failure( $order, 'no delegation gateway configured' );
			return array( 'result' => 'failure' );
		}

		$components = $this->get_order_charge_components( $order );
		$tolerance  = function_exists( 'wc_get_price_decimals' )
			? pow( 10, -1 * wc_get_price_decimals() )
			: 0.01;
		$amounts    = Core::assess_delegation_amounts(
			$total,
			$components['total'],
			$components['available'],
			$advance,
			$advance,
			$tolerance
		);
		if ( ! $amounts['safe'] ) {
			wc_add_notice( __( 'The advance payment could not be started safely. Please choose another payment method.', 'cod-shield' ), 'error' );
			$this->log_advance_failure( $order, 'unsafe order amount state: ' . $amounts['code'] );
			return array( 'result' => 'failure' );
		}

		// Persist advance context before redirecting to the gateway.
		$order->update_meta_data( Core::META_ADVANCE, $advance );
		$order->update_meta_data( Core::META_MODE, $core->get_option( Core::OPT_MODE, 'disabled' ) );
		$order->update_meta_data( Core::META_ORIGINAL_TOTAL, $total );
		$order->update_meta_data( Core::META_REMAINING, max( 0.0, $total - $advance ) );
		$order->update_meta_data( Core::META_STATUS, 'pending' );
		$order->update_meta_data( Core::META_AWAITING, 'yes' );
		$order->update_meta_data( Core::META_GATEWAY_USED, $gateway->id );
		$order->save();

		// Temporarily reduce the order total to the advance and adopt the
		// delegation gateway as the order's payment method BEFORE delegating.
		//
		// Adopting the method matters for redirect-style gateways (e.g.
		// Razorpay): they send the customer to the order-pay page after
		// process_payment() and render their checkout UI there via the
		// `woocommerce_receipt_{method}` hook — which only fires when the
		// order's payment method matches the gateway ID. Leaving the proxy ID
		// in place produces an empty receipt page with no payment form and no
		// popup, stranding the customer. Adopting the real gateway also keeps
		// its callbacks/webhooks (which key off the method) healthy.
		// finalize_advance_order() switches the method back to COD once the
		// advance is paid.
		//
		// These mutations and the delegation call are inside the try so any
		// exception falls through to the shared failure handling below, which
		// restores the full total and the proxy method — the order can never be
		// left at the advance total with a foreign payment method (e.g. by a
		// fatal in a third-party plugin hooked into the gateway's flow).
		try {
			$order->set_total( $advance );
			$order->save();

			$amounts = Core::assess_delegation_amounts(
				$total,
				$components['total'],
				$components['available'],
				$advance,
				(float) $order->get_total(),
				$tolerance
			);
			if ( ! $amounts['safe'] ) {
				wc_add_notice( __( 'The advance payment could not be started safely. Please choose another payment method.', 'cod-shield' ), 'error' );
				$result = array(
					'result'   => 'failure',
					'messages' => 'unsafe order amount state: ' . $amounts['code'],
				);
			} else {
				$order->set_payment_method( $gateway->id );
				$order->set_payment_method_title( $gateway->get_title() );
				$order->save();

				$result = $gateway->process_payment( $order_id );
			}
		} catch ( \Throwable $e ) {
			// Route exceptions through the same failure handling so the order is
			// restored and can never be charged the full amount by accident.
			$result = array(
				'result'   => 'failure',
				'messages' => $e->getMessage(),
			);
		}

		if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: advance amount, 2: gateway title. */
					__( 'Advance payment of %1$s initiated via %2$s. Awaiting gateway confirmation.', 'cod-shield' ),
					wc_price( $advance ),
					$gateway->get_title()
				)
			);
			return $result;
		}

		// Failure / abandoned: restore the full total, revert to the proxy method
		// (so a later "pay for order" visit can never charge the full amount via
		// the delegation gateway by accident) and record the failure.
		$this->log_advance_failure( $order, $result['messages'] ?? __( 'Gateway declined the payment or the customer abandoned it.', 'cod-shield' ) );
		$order->set_total( $total );
		$order->set_payment_method( Core::GATEWAY_ID );
		$order->set_payment_method_title( $core->get_cod_title() );
		$order->update_meta_data( Core::META_STATUS, 'failed' );
		$order->update_meta_data( Core::META_AWAITING, 'no' );
		$order->save();

		if ( 'block' === $core->get_option( Core::OPT_FAILURE, 'allow' ) ) {
			$order->update_status( 'cancelled', __( 'Advance payment failed; order cancelled per COD Shield settings.', 'cod-shield' ) );
		}

		return array( 'result' => 'failure' );
	}

	/**
	 * Sum the original charge-bearing order components.
	 *
	 * @param \WC_Order $order Order.
	 * @return array{total:float,available:bool}
	 */
	private function get_order_charge_components( $order ) {
		$total     = 0.0;
		$available = false;

		foreach ( array( 'line_item', 'fee', 'shipping' ) as $item_type ) {
			foreach ( $order->get_items( $item_type ) as $item ) {
				if ( ! is_callable( array( $item, 'get_total' ) ) ) {
					continue;
				}
				$available = true;
				$total    += (float) $item->get_total();
				if ( is_callable( array( $item, 'get_total_tax' ) ) ) {
					$total += (float) $item->get_total_tax();
				}
			}
		}

		return array(
			'total'     => $total,
			'available' => $available,
		);
	}

	/**
	 * Record an advance failure order note.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $reason Failure reason.
	 * @return void
	 */
	private function log_advance_failure( $order, $reason ) {
		$order->add_order_note(
			sprintf(
				/* translators: %s: failure reason. */
				__( 'COD advance payment failed: %s', 'cod-shield' ),
				(string) $reason
			)
		);
	}
}
