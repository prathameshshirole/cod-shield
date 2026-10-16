<?php
/**
 * The hidden "COD + Advance" proxy gateway and the order finalization logic.
 *
 * Flow:
 *  1. Customer selects the proxy method at checkout (it replaces native COD
 *     while an advance is required).
 *  2. process_payment() computes the advance server-side, stashes the original
 *     order total in meta, temporarily sets the order total to the advance
 *     amount, and delegates to the admin-selected gateway's process_payment().
 *  3. The delegation gateway runs its normal flow and charges only the
 *     advance amount.
 *  4. On payment completion (woocommerce_payment_complete / status
 *     transitions), finalize_advance_order() restores the full total, switches
 *     the order to the 'cod' payment method, records advance meta + txn id,
 *     and applies the configured order status. Finalization is gated on a
 *     recorded gateway transaction id — without one the order is never marked
 *     paid / converted, so fulfilment stays blocked.
 *  5. Fulfilment guard: while an advance is unpaid the order can never reach
 *     processing/completed — status transitions without a recorded gateway
 *     transaction are rolled back to pending.
 *  6. Overcharge guard: if the order total at finalization materially exceeds
 *     the recorded advance (a gateway that recalculates order totals charges
 *     the FULL order), the order is NOT converted to COD with the remainder
 *     still "due on delivery" — that would double-charge the customer. The
 *     order is flagged with a warning note, the advance marked paid, and the
 *     amount still due reduced to what the gateway actually left unpaid.
 *  7. Manual reconciliation: an admin can mark an advance as paid from the
 *     order screen when the gateway never recorded a transaction id (e.g. the
 *     customer paid outside the gateway). Nonce + capability checked.
 *  8. Cancellation protection: awaiting-advance orders are exempt from WC
 *     core's "cancel unpaid orders" scheduler, and any cancelled order that
 *     may carry a captured advance is flagged 'review' for manual
 *     reconciliation instead of silently dropping the collected money.
 *
 * Fail-closed: if no delegation gateway is configured, the proxy is
 * unavailable AND native COD is removed from checkout, so an advance can
 * never be silently skipped.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Gateway bridge.
 */
class Gateway_Bridge {

	/**
	 * Orders currently dispatching save-driven work.
	 *
	 * @var array<int,bool>
	 */
	private static $observing_orders = array();

	/**
	 * Orders currently running scheduled reconciliation.
	 *
	 * @var array<int,bool>
	 */
	private static $reconciling_orders = array();

	/**
	 * Hook registration.
	 */
	public function __construct() {
		// Let Cashfree finish a browser-return capture after its webhook has
		// already finalized the advance and converted the stored method to COD.
		add_filter( 'woocommerce_order_get_payment_method', array( $this, 'expose_cashfree_payment_method_during_capture' ), 10, 2 );

		// Inject the proxy gateway into the AVAILABLE gateways list only
		// (never into the full registered list, so it stays out of the admin
		// gateway settings screen).
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_add_proxy_gateway' ), 20 );

		// Finalize the COD order once the advance has been paid.
		add_action( 'woocommerce_payment_complete', array( $this, 'finalize_advance_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'finalize_advance_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'finalize_advance_order' ), 10, 1 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'maybe_schedule_advance_reconciliation' ), 10, 1 );
		add_action( Core::ACTION_RECONCILE_ADVANCE, array( $this, 'reconcile_advance_order' ), 10, 1 );

		// Some gateways record the transaction id in a second step/request,
		// AFTER marking the order processing. Finalize on the status change
		// itself (priority 9 — before the fulfilment guard at 10) so a
		// late-recorded txn is honoured instead of the order being rolled back
		// to pending with the advance collected but never finalized.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_finalize_on_status_change' ), 9, 4 );

		// Fulfilment guard: an order must never reach processing/completed
		// while its advance is still unpaid. Reverts such transitions.
		add_action( 'woocommerce_order_status_changed', array( $this, 'revert_unpaid_advance_status' ), 10, 4 );

		// Auto-cancel protection: WC core's "cancel unpaid orders" scheduler
		// must never kill an order whose advance charge is in flight or
		// already captured but not yet finalized.
		add_filter( 'woocommerce_cancel_unpaid_order', array( $this, 'prevent_auto_cancel_of_advance_order' ), 10, 2 );

		// Cancellation reconciliation: a cancelled order that may still carry
		// a captured advance is flagged for manual review instead of silently
		// dropping the collected money.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'flag_cancelled_advance_for_review' ), 10, 1 );

		// Admin reconciliation: manually mark an advance as paid (e.g. the
		// customer paid outside the gateway, or the gateway never recorded a
		// transaction id). Nonce + capability checked in the handler.
		add_action( 'admin_post_' . Core::NONCE_MANUAL_PAID, array( $this, 'handle_manual_mark_advance_paid' ) );
		add_action( 'admin_post_' . Core::NONCE_RELEASE_ATTEMPT, array( $this, 'handle_release_stale_advance' ) );
		add_action( 'admin_notices', array( $this, 'render_manual_paid_notice' ) );
	}

	/**
	 * Present Cashfree as the payment method only inside its capture callback.
	 *
	 * The order remains stored as COD. Cashfree still performs its own order-key
	 * validation immediately after reading this value, so this compatibility
	 * view does not weaken callback authentication.
	 *
	 * @param string    $payment_method Stored payment method.
	 * @param \WC_Order $order          Order being read.
	 * @return string
	 */
	public function expose_cashfree_payment_method_during_capture( $payment_method, $order ) {
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return $payment_method;
		}

		$api_endpoint = function_exists( 'get_query_var' ) ? sanitize_key( (string) get_query_var( 'wc-api' ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only gateway callback routing; Cashfree validates the order key.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( Core::should_expose_cashfree_payment_method(
			(string) $payment_method,
			(string) $order->get_meta( Core::META_GATEWAY_USED ),
			(string) $order->get_meta( Core::META_STATUS ),
			$api_endpoint,
			$action
		) ) {
			return 'cashfree';
		}

		return $payment_method;
	}

	/**
	 * Append the proxy gateway to the available list when the advance flow is
	 * active for the current cart, and hide it otherwise.
	 *
	 * Native COD removal/keeping is handled in Checkout::filter_available_gateways()
	 * on the same filter (priority 10); this runs after it.
	 *
	 * @param array<string,\WC_Payment_Gateway> $gateways Available gateways.
	 * @return array<string,\WC_Payment_Gateway>
	 */
	public function maybe_add_proxy_gateway( $gateways ) {
		$core = Core::instance();

		// Order-pay endpoint: no cart, so decide from the order itself.
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			$order_id = absint( get_query_var( 'order-pay' ) );
			$order    = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order && $core->order_advance_outstanding( $order ) ) {
				if ( $core->delegation_gateway() ) {
					$gateways[ Core::GATEWAY_ID ] = new Gateway_COD_Advance();
				} else {
					unset( $gateways[ Core::GATEWAY_ID ] );
				}
				// Advance-only retry: while the advance is unpaid, the ONLY way to
				// pay on this endpoint is the advance proxy. This stops a customer
				// from settling the full order online and skipping the COD
				// commitment the advance exists to enforce.
				foreach ( array_keys( $gateways ) as $id ) {
					if ( Core::GATEWAY_ID !== $id ) {
						unset( $gateways[ $id ] );
					}
				}
			} else {
				unset( $gateways[ Core::GATEWAY_ID ] );
			}
			return $gateways;
		}

		// Native COD parity: only replace native COD that was actually there.
		// Checkout (priority 10) records the deliberate swap; when WooCommerce
		// core itself removed COD ("enable for methods" shipping restriction,
		// virtual-only carts, disabled gateway) the proxy must never resurrect it.
		if ( $core->advance_flow_active() && Checkout::cod_removed_for_swap() ) {
			$gateways[ Core::GATEWAY_ID ] = new Gateway_COD_Advance();
		} else {
			unset( $gateways[ Core::GATEWAY_ID ] );
		}

		return $gateways;
	}

	/**
	 * Restore full totals and convert to a plain COD order once the advance
	 * charge has completed. Idempotent via the _cod_shield_awaiting_advance
	 * flag.
	 *
	 * @param int|\WC_Order $order_id Order id or object.
	 * @return void
	 */
	public function finalize_advance_order( $order_id ) {
		$order = is_a( $order_id, '\WC_Order' ) ? $order_id : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$outcome = $this->evaluate_finalization_outcome( $order );
		if ( 'ready' !== $outcome ) {
			$this->record_finalization_diagnostic( $order, $outcome );
			return;
		}

		$this->do_finalize( $order, $order->get_transaction_id(), false );
	}

	/**
	 * Queue catch-up when a gateway persists payment evidence after its events.
	 *
	 * Never finalize or save from the observer: the gateway may still be inside
	 * its own save/status transition. Rejected evidence is evaluated again by
	 * the finalizer, which owns diagnostics and all money-related mutations.
	 *
	 * @param \WC_Order $order Persisted order.
	 * @return void
	 */
	public function maybe_schedule_advance_reconciliation( $order ): void {
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		if ( $order_id <= 0 || isset( self::$observing_orders[ $order_id ] ) || isset( self::$reconciling_orders[ $order_id ] ) ) {
			return;
		}
		if ( in_array( $this->evaluate_finalization_outcome( $order ), array( 'not_awaiting', 'missing_transaction' ), true ) ) {
			return;
		}

		self::$observing_orders[ $order_id ] = true;
		try {
			$args = array( $order_id );
			// Keep a previously queued fallback when Action Scheduler becomes
			// available later, instead of dispatching the same order twice.
			if ( wp_next_scheduled( Core::ACTION_RECONCILE_ADVANCE, $args ) ) {
				return;
			}

			if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) && did_action( 'action_scheduler_init' ) ) {
				if ( as_has_scheduled_action( Core::ACTION_RECONCILE_ADVANCE, $args, 'cod-shield' ) ) {
					return;
				}
				$action_id = as_enqueue_async_action( Core::ACTION_RECONCILE_ADVANCE, $args, 'cod-shield', true );
				// A unique enqueue may lose a race to another request. Only
				// fall back if no matching pending/running action exists.
				if ( $action_id || as_has_scheduled_action( Core::ACTION_RECONCILE_ADVANCE, $args, 'cod-shield' ) ) {
					return;
				}
			}

			wp_schedule_single_event( time(), Core::ACTION_RECONCILE_ADVANCE, $args );
		} finally {
			unset( self::$observing_orders[ $order_id ] );
		}
	}

	/**
	 * Reload one order and apply the existing idempotent evidence-gated finalizer.
	 *
	 * Diagnostic saves can leave the awaiting flag set. Suppress their observer
	 * and any reentrant callback until this reconciliation exits, even on error.
	 *
	 * @param int $order_id Order ID supplied by either scheduler.
	 * @return void
	 */
	public function reconcile_advance_order( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || isset( self::$reconciling_orders[ $order_id ] ) ) {
			return;
		}

		self::$reconciling_orders[ $order_id ] = true;
		try {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$this->finalize_advance_order( $order );
			}
		} finally {
			unset( self::$reconciling_orders[ $order_id ] );
		}
	}

	/**
	 * Evaluate automatic finalization preconditions without mutating the order.
	 *
	 * The ready outcome means the evidence gates passed, not that money was
	 * marked paid. The existing finalizer still applies its overcharge guard.
	 *
	 * @param \WC_Order $order Order to evaluate.
	 * @return string Stable outcome code.
	 */
	public function evaluate_finalization_outcome( $order ): string {
		if ( 'yes' !== $order->get_meta( Core::META_AWAITING ) ) {
			return 'not_awaiting';
		}

		// The advance only counts as paid once the delegation gateway records
		// a real transaction id. Without one there is no proof of collection,
		// so the order is never finalized (marked paid / converted to plain
		// COD) and fulfilment stays blocked.
		$txn = $order->get_transaction_id();
		if ( ! $txn || '' === trim( (string) $txn ) ) {
			return 'missing_transaction';
		}

		$gateway_used = (string) $order->get_meta( Core::META_GATEWAY_USED );
		if ( '' !== $gateway_used && $gateway_used !== $order->get_payment_method() ) {
			return 'gateway_mismatch';
		}

		$original = (float) $order->get_meta( Core::META_ORIGINAL_TOTAL );
		if ( $original <= 0 ) {
			$original = (float) $order->get_meta( Core::META_ADVANCE ) + (float) $order->get_meta( Core::META_REMAINING );
		}
		if ( $original <= 0 && (float) $order->get_total() <= 0 ) {
			return 'invalid_total';
		}

		return 'ready';
	}

	/**
	 * Persist one diagnostic per consecutive actionable rejection state.
	 *
	 * Log only operational identifiers. Never include transaction IDs, request
	 * data, payment tokens, or customer information in rejection diagnostics.
	 *
	 * @param \WC_Order $order   Order rejected by automatic finalization.
	 * @param string    $outcome Stable outcome code.
	 * @return void
	 */
	private function record_finalization_diagnostic( $order, string $outcome ): void {
		$previous = (string) $order->get_meta( Core::META_FINALIZATION_DIAGNOSTIC );
		if ( ! Core::should_log_finalization_diagnostic( $previous, $outcome ) ) {
			return;
		}

		$order->update_meta_data( Core::META_FINALIZATION_DIAGNOSTIC, $outcome );
		$order->save();

		$messages = array(
			'missing_transaction' => __( 'COD advance finalization is waiting for a gateway transaction ID. Verify payment in the gateway dashboard before fulfilment.', 'cod-shield' ),
			'gateway_mismatch'    => __( 'COD advance finalization was blocked because the payment method differs from the recorded advance gateway. Verify the payment and gateway before fulfilment.', 'cod-shield' ),
			'invalid_total'       => __( 'COD advance finalization was blocked because no valid order total could be restored. Review the order totals and payment before fulfilment.', 'cod-shield' ),
		);
		$order->add_order_note( $messages[ $outcome ] );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'COD advance finalization rejected.',
				array(
					'source'         => 'cod-shield',
					'order_id'       => $order->get_id(),
					'code'           => $outcome,
					'gateway_used'   => (string) $order->get_meta( Core::META_GATEWAY_USED ),
					'payment_method' => (string) $order->get_payment_method(),
					'status'         => (string) $order->get_status(),
				)
			);
		}
	}

	/**
	 * Finalize on a fulfilment-bound status change (processing/completed).
	 *
	 * Covers gateways that record the transaction id in a second step or a
	 * separate request, after the status was already moved to processing: the
	 * status_processing hook above ran before the txn existed, and the
	 * fulfilment guard would otherwise roll the order back to pending even
	 * though the advance was actually collected. Runs at priority 9, before
	 * revert_unpaid_advance_status() at 10, so a late-recorded txn wins.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	public function maybe_finalize_on_status_change( $order_id, $from, $to, $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}
		$this->finalize_advance_order( $order_id );
	}

	/**
	 * Manually mark an outstanding advance as paid (admin reconciliation).
	 *
	 * Intended for the case where the advance was collected outside the
	 * gateway (bank transfer, cash, a gateway that never recorded a
	 * transaction id) and the order is otherwise stuck awaiting. Capability
	 * and nonce are verified by the admin-post handler before this runs.
	 *
	 * @param int|\WC_Order $order_id Order id or object.
	 * @return bool Whether the order was marked paid.
	 */
	public function manual_mark_advance_paid( $order_id ) {
		$order = is_a( $order_id, '\WC_Order' ) ? $order_id : wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$advance = (float) $order->get_meta( Core::META_ADVANCE );
		if ( $advance <= 0 || 'paid' === $order->get_meta( Core::META_STATUS ) ) {
			return false;
		}

		$this->do_finalize( $order, '', true );
		return true;
	}

	/**
	 * Finalization core — shared by the gateway-driven path and the manual
	 * admin action.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $txn    Gateway transaction id ('' for manual).
	 * @param bool      $manual Whether this is an explicit admin override.
	 * @return void
	 */
	private function do_finalize( $order, $txn, $manual ) {
		$advance  = (float) $order->get_meta( Core::META_ADVANCE );
		$original = (float) $order->get_meta( Core::META_ORIGINAL_TOTAL );
		if ( $original <= 0 ) {
			// Legacy/partial-meta guard: derive from remaining + advance.
			$original = $advance + (float) $order->get_meta( Core::META_REMAINING );
		}
		if ( $original <= 0 ) {
			$original = (float) $order->get_total();
		}
		if ( $original <= 0 ) {
			return; // Nothing to restore — abort rather than corrupt the order.
		}

		$core = Core::instance();

		// Overcharge guard: if the order total at finalization materially
		// exceeds the recorded advance, the delegation gateway recalculated
		// order totals and charged more than the advance (worst case: the full
		// order). Never silently convert such an order to COD with the
		// remainder still "due on delivery" — that would double-charge the
		// customer (online AND at the door). Flag it, mark the advance paid,
		// and reduce the amount still due to what the gateway actually left
		// unpaid. The payment method stays on the gateway.
		//
		// Gateway path: the order total at finalization reflects what the
		// gateway charged, so the comparison always applies.
		// Manual path: only meaningful while the delegation gateway is still
		// the order's payment method (a gateway charge was initiated but never
		// finalized). When the process_payment() failure path restored the
		// proxy method and the full COD total, that total is legitimately the
		// full amount — the admin is asserting collection elsewhere — so the
		// comparison would always trip and is skipped.
		$charged       = (float) $order->get_total();
		$gateway_used  = (string) $order->get_meta( Core::META_GATEWAY_USED );
		$guard_applies = ! $manual || ( '' !== $gateway_used && $order->get_payment_method() === $gateway_used );
		if ( $guard_applies && $charged > $advance + 1.0 ) {
			$remaining = max( 0.0, $original - $charged );
			$order->set_total( $original );
			$order->update_meta_data( Core::META_TXN, (string) $txn );
			$order->update_meta_data( Core::META_STATUS, 'paid' );
			$order->update_meta_data( Core::META_REMAINING, $remaining );
			$order->update_meta_data( Core::META_AWAITING, 'no' );
			$order->delete_meta_data( Core::META_ATTEMPT_STARTED );
			$order->delete_meta_data( Core::META_FINALIZATION_DIAGNOSTIC );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: 1: charged amount, 2: advance amount, 3: transaction id, 4: remaining amount. */
					__( 'WARNING: the order total at payment completion (%1$s) exceeded the recorded advance (%2$s) — the gateway may have charged more than the advance (transaction: %3$s). The order was NOT converted to COD; %4$s remains payable on delivery. Verify the charge in the gateway dashboard before fulfilment.', 'cod-shield' ),
					wc_price( $charged ),
					wc_price( $advance ),
					$txn ? $txn : '—',
					wc_price( $remaining )
				)
			);
			return;
		}

		// Normal path: restore the real order total and switch to COD.
		$order->set_total( $original );
		$cod_gateway = $core->native_cod_enabled() ? WC()->payment_gateways()->payment_gateways()['cod'] : null;
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( $core->get_cod_title( $cod_gateway ? $cod_gateway->get_title() : __( 'Cash on delivery', 'cod-shield' ) ) );

		if ( $txn ) {
			$order->update_meta_data( Core::META_TXN, (string) $txn );
		}
		$order->update_meta_data( Core::META_STATUS, 'paid' );
		$order->update_meta_data( Core::META_REMAINING, max( 0.0, $original - $advance ) );
		$order->update_meta_data( Core::META_AWAITING, 'no' );
		$order->delete_meta_data( Core::META_ATTEMPT_STARTED );
		$order->delete_meta_data( Core::META_FINALIZATION_DIAGNOSTIC );
		$order->save();

		if ( $manual ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: advance amount, 2: user display name. */
					__( 'COD advance of %1$s manually marked as paid by %2$s — no gateway transaction recorded. Verify collection separately.', 'cod-shield' ),
					wc_price( $advance ),
					wp_get_current_user()->display_name
				)
			);
		} else {
			$gateway = $order->get_meta( Core::META_GATEWAY_USED );
			$order->add_order_note(
				sprintf(
					/* translators: 1: advance amount, 2: gateway id, 3: transaction id, 4: remaining amount. */
					__( 'COD advance of %1$s collected via %2$s (transaction: %3$s). %4$s remains payable on delivery.', 'cod-shield' ),
					wc_price( $advance ),
					$gateway ? $gateway : '—',
					$txn ? $txn : '—',
					wc_price( max( 0.0, $original - $advance ) )
				)
			);
		}

		// Advance is paid: apply the configured COD status. This runs exactly
		// once because the awaiting flag was just cleared, and only for
		// payment-driven transitions (a transaction id was required above),
		// covering pending/failed retries and webhook-driven status changes.
		if ( $order->has_status( array( 'pending', 'failed', 'processing', 'completed' ) ) ) {
			$order->update_status( $core->get_option( Core::OPT_ORDER_STATUS, 'processing' ) );
		}
	}

	/**
	 * Explicitly release only a stale attempt with no recorded collection.
	 *
	 * Reload after authorization so the screen's previous state is never trusted.
	 * Keep awaiting=yes: the advance obligation and order-pay proxy must survive.
	 *
	 * @param int    $order_id Order id.
	 * @param string $nonce Order-specific release nonce.
	 * @return bool Whether the attempt was released.
	 */
	public function release_stale_advance( int $order_id, string $nonce ): bool {
		if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) || ! wp_verify_nonce( $nonce, Core::NONCE_RELEASE_ATTEMPT . '_' . $order_id ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		$core  = Core::instance();
		if ( ! $order || 'stale' !== $core->payment_attempt_state( $order ) || ! $order->has_status( array( 'pending', 'failed' ) ) || ! in_array( $order->get_meta( Core::META_STATUS ), array( 'pending', 'failed' ), true ) ) {
			return false;
		}
		$original_raw = $order->get_meta( Core::META_ORIGINAL_TOTAL );
		$advance_raw  = $order->get_meta( Core::META_ADVANCE );
		// Corrupted legacy metadata must fail closed: an array or non-numeric
		// string would cast to a misleading float instead of refusing release.
		if ( ! is_numeric( $original_raw ) || ! is_numeric( $advance_raw ) ) {
			return false;
		}
		$original = (float) $original_raw;
		$advance  = (float) $advance_raw;
		if ( ! is_finite( $original ) || ! is_finite( $advance ) || $advance <= 0 || $original < $advance ) {
			return false;
		}

		$order->set_total( $original );
		$order->set_payment_method( Core::GATEWAY_ID );
		$order->set_payment_method_title( $core->get_cod_title() );
		$order->update_meta_data( Core::META_AWAITING, 'yes' );
		$order->update_meta_data( Core::META_STATUS, 'pending' );
		$order->update_meta_data( Core::META_ATTEMPT_STARTED, 'no' );
		$order->delete_meta_data( Core::META_ATTEMPT_STARTED_AT );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: %d: administrator user ID. */
				__( 'Stale COD advance attempt released for retry by user #%d. No payment was marked as received. Verify the original attempt with the gateway before retrying.', 'cod-shield' ),
				get_current_user_id()
			)
		);
		return true;
	}

	/**
	 * Admin-post handler for explicit stale attempt recovery.
	 *
	 * @return void
	 */
	public function handle_release_stale_advance(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Order-specific nonce verified by release_stale_advance before mutation.
		$order_id = isset( $_POST['cod_shield_order_id'] ) ? absint( wp_unslash( $_POST['cod_shield_order_id'] ) ) : 0;
		$nonce    = isset( $_POST['cod_shield_release_nonce'] ) && is_string( $_POST['cod_shield_release_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cod_shield_release_nonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$released = $this->release_stale_advance( $order_id, $nonce );
		$order    = $order_id ? wc_get_order( $order_id ) : null;
		$back     = $order && current_user_can( 'edit_shop_order', $order_id ) ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );
		wp_safe_redirect( add_query_arg( 'cod_shield_notice', $released ? 'released' : 'release_denied', $back ) );
		exit;
	}

	/**
	 * Admin-post handler for the "mark advance as paid" action.
	 *
	 * @return void
	 */
	public function handle_manual_mark_advance_paid() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified via check_admin_referer below.
		$order_id = isset( $_POST['cod_shield_order_id'] ) ? absint( wp_unslash( $_POST['cod_shield_order_id'] ) ) : 0;
		check_admin_referer( Core::NONCE_MANUAL_PAID . '_' . $order_id, 'cod_shield_manual_paid_nonce' );

		$order = $order_id ? wc_get_order( $order_id ) : null;
		$back  = $order ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_safe_redirect( add_query_arg( 'cod_shield_notice', 'denied', $back ) );
			exit;
		}

		$notice = $this->manual_mark_advance_paid( $order_id ) ? 'marked' : 'not_found';
		wp_safe_redirect( add_query_arg( 'cod_shield_notice', $notice, $back ) );
		exit;
	}

	/**
	 * Admin notice after the manual "mark advance as paid" redirect.
	 *
	 * @return void
	 */
	public function render_manual_paid_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice display.
		$notice = isset( $_GET['cod_shield_notice'] ) ? sanitize_key( wp_unslash( $_GET['cod_shield_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		$messages = array(
			'released'       => __( 'Stale advance attempt released. The advance remains unpaid and can be retried.', 'cod-shield' ),
			'release_denied' => __( 'Attempt not released. Check your permission, reload the order, and verify that it is stale with no transaction or review flag.', 'cod-shield' ),
			'marked'         => __( 'COD advance marked as paid. The order was converted to Cash on Delivery.', 'cod-shield' ),
			'denied'         => __( 'You do not have permission to mark an advance as paid.', 'cod-shield' ),
			'not_found'      => __( 'The order could not be found, or its advance is already paid.', 'cod-shield' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = in_array( $notice, array( 'marked', 'released' ), true ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}

	/**
	 * Fulfilment guard — "processing only after the advance is paid".
	 *
	 * An order that still owes its advance must never sit in a fulfilment
	 * status (processing/completed). This hook fires after a status change, so
	 * an offending transition is rolled back to pending with an explanatory
	 * order note.
	 *
	 * Covers:
	 *  - manual admin overrides (moving an awaiting order to
	 *    processing/completed while the customer never paid), and
	 *  - gateways that fire paid-status transitions without recording a
	 *    transaction id (no proof of collection).
	 *
	 * "Paid" means a real gateway transaction was recorded — the same
	 * invariant finalize_advance_order() enforces. The check deliberately does
	 * NOT depend on the COD kill switch or the awaiting flag, so it stays
	 * active even if the admin later disables COD or a failure path cleared
	 * the flag.
	 *
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order object.
	 * @return void
	 */
	public function revert_unpaid_advance_status( $order_id, $from, $to, $order ) {
		if ( ! in_array( $to, array( 'processing', 'completed' ), true ) ) {
			return;
		}

		if ( ! is_a( $order, '\WC_Order' ) ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
		}

		$advance = (float) $order->get_meta( Core::META_ADVANCE );
		if ( $advance <= 0 || 'paid' === $order->get_meta( Core::META_STATUS ) ) {
			return; // Not an advance order, or the advance is already collected.
		}
		if ( $order->get_transaction_id() ) {
			return; // Real gateway transaction recorded — advance is paid.
		}

		$order->update_status(
			'pending',
			__( 'Moved back to pending: this order cannot be processing or completed while its COD advance is unpaid.', 'cod-shield' )
		);
	}

	/**
	 * Protect awaiting-advance orders from WC core's "cancel unpaid orders".
	 *
	 * The unpaid-order canceller (woocommerce_cancel_unpaid_orders) only
	 * targets pending orders — exactly the state an awaiting-advance order is
	 * in while the gateway charge is in flight, or captured but not yet
	 * finalized. Cancelling there would strand a captured advance without a
	 * refund.
	 *
	 * @param bool            $cancel Whether core would cancel the order.
	 * @param \WC_Order|mixed $order  Order under consideration.
	 * @return bool
	 */
	public function prevent_auto_cancel_of_advance_order( $cancel, $order ) {
		if ( ! $cancel || ! is_a( $order, '\WC_Order' ) ) {
			return $cancel;
		}

		// Awaiting advance (charge initiated or captured but not finalized) —
		// exempt from the unpaid-order canceller.
		if ( 'yes' === $order->get_meta( Core::META_AWAITING ) ) {
			return false;
		}

		return $cancel;
	}

	/**
	 * Flag cancelled orders whose advance may have been captured.
	 *
	 * WC core can cancel an order for many reasons; the charge may still have
	 * gone through (captured but not finalized, e.g. webhook latency or a
	 * lost redirect). Surface such orders for manual reconciliation instead
	 * of silently dropping the collected money.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function flag_cancelled_advance_for_review( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Charge may have been captured even though the order was cancelled.
		if ( $order->get_transaction_id() || 'yes' === $order->get_meta( Core::META_AWAITING ) ) {
			$order->add_order_note(
				__( 'ADVANCE RECONCILIATION REQUIRED: this order was cancelled while an advance payment may have been captured. Verify with the gateway and refund if charged.', 'cod-shield' )
			);
			// HPOS-safe: meta is always written through WC_Order CRUD.
			$order->update_meta_data( Core::META_AWAITING, 'review' );
			$order->save();
		}
	}
}
