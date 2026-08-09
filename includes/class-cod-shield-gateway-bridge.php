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
	 * Hook registration.
	 */
	public function __construct() {
		// Inject the proxy gateway into the AVAILABLE gateways list only
		// (never into the full registered list, so it stays out of the admin
		// gateway settings screen).
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'maybe_add_proxy_gateway' ), 20 );

		// Finalize the COD order once the advance has been paid.
		add_action( 'woocommerce_payment_complete', array( $this, 'finalize_advance_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'finalize_advance_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'finalize_advance_order' ), 10, 1 );

		// Some gateways record the transaction id in a second step/request,
		// AFTER marking the order processing. Finalize on the status change
		// itself (priority 9 — before the fulfilment guard at 10) so a
		// late-recorded txn is honoured instead of the order being rolled back
		// to pending with the advance collected but never finalized.
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_finalize_on_status_change' ), 9, 4 );

		// Fulfilment guard: an order must never reach processing/completed
		// while its advance is still unpaid. Reverts such transitions.
		add_action( 'woocommerce_order_status_changed', array( $this, 'revert_unpaid_advance_status' ), 10, 4 );

		// Admin reconciliation: manually mark an advance as paid (e.g. the
		// customer paid outside the gateway, or the gateway never recorded a
		// transaction id). Nonce + capability checked in the handler.
		add_action( 'admin_post_' . Core::NONCE_MANUAL_PAID, array( $this, 'handle_manual_mark_advance_paid' ) );
		add_action( 'admin_notices', array( $this, 'render_manual_paid_notice' ) );
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

		if ( $core->advance_flow_active() ) {
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

		if ( 'yes' !== $order->get_meta( Core::META_AWAITING ) ) {
			return;
		}

		// The advance only counts as paid once the delegation gateway records
		// a real transaction id. Without one there is no proof of collection,
		// so the order is never finalized (marked paid / converted to plain
		// COD) and fulfilment stays blocked.
		$txn = $order->get_transaction_id();
		if ( ! $txn ) {
			return;
		}

		$gateway_used = (string) $order->get_meta( Core::META_GATEWAY_USED );
		if ( '' !== $gateway_used && $gateway_used !== $order->get_payment_method() ) {
			return;
		}

		$this->do_finalize( $order, $txn, false );
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
			'marked'    => __( 'COD advance marked as paid. The order was converted to Cash on Delivery.', 'cod-shield' ),
			'denied'    => __( 'You do not have permission to mark an advance as paid.', 'cod-shield' ),
			'not_found' => __( 'The order could not be found, or its advance is already paid.', 'cod-shield' ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		$class = 'marked' === $notice ? 'notice-success' : 'notice-error';
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
}
