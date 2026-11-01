<?php
/**
 * Explicit, audited corrections to partial-COD order records.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Edit bookkeeping without issuing gateway charges or refunds.
 */
class Order_Editor {

	const ACTION = 'cod_shield_correct_payment';

	/** Register admin actions and order-total revalidation. */
	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'revalidate_total' ), 20, 1 );
	}

	/**
	 * A saved attempt must be reconciled before its financial record is edited.
	 *
	 * @param \WC_Order $order Order.
	 * @return bool
	 */
	public static function is_locked( $order ) {
		return 'yes' === $order->get_meta( Core::META_ATTEMPT_STARTED )
			|| ( 'yes' === $order->get_meta( Core::META_AWAITING ) && 'no' !== $order->get_meta( Core::META_ATTEMPT_STARTED ) );
	}

	/**
	 * Detect a stale editor submission after a callback or another editor saves.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function revision( $order ) {
		$values = array( $order->get_total(), $order->get_status(), $order->get_payment_method(), $order->get_transaction_id() );
		foreach ( array( Core::META_ADVANCE, Core::META_RECEIVED, Core::META_STATUS, Core::META_AWAITING, Core::META_ATTEMPT_STARTED, Core::META_LAST_CORRECTION, Core::META_MANUAL_REFERENCE ) as $key ) {
			$values[] = $order->get_meta( $key );
		}
		return hash( 'sha256', wp_json_encode( $values ) );
	}

	/**
	 * Validate and apply an authorized correction to a freshly loaded order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $nonce Order-specific nonce.
	 * @param array  $input Sanitized input.
	 * @return string Result code.
	 */
	public function correct( $order_id, $nonce, array $input ) {
		if ( ! current_user_can( 'edit_shop_order', $order_id ) || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $order_id ) ) {
			return 'denied';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( self::revision( $order ), (string) ( $input['revision'] ?? '' ) ) ) {
			return 'stale';
		}
		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() || self::is_locked( $order ) || $order->has_status( array( 'cancelled', 'refunded', 'trash' ) ) ) {
			return 'locked';
		}
		$reason = trim( (string) ( $input['reason'] ?? '' ) );
		$kind   = (string) ( $input['kind'] ?? '' );
		$raw    = $input['amount'] ?? '';
		if ( '' === $reason || ! in_array( $kind, array( 'required', 'received' ), true ) || ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
			return 'invalid';
		}
		$amount   = (float) $raw;
		$decimals = wc_get_price_decimals();
		if ( ! is_finite( $amount ) || $amount < 0 || $amount > $view->total || ( 'required' === $kind && $amount <= 0 ) || abs( round( $amount, $decimals ) - $amount ) > 0.0000001 ) {
			return 'invalid';
		}
		if ( 'required' === $kind && ( $view->received > 0 || $order->get_transaction_id() || $view->txn ) ) {
			return 'locked';
		}

		$previous = 'required' === $kind ? $view->advance : $view->received;
		$required = 'required' === $kind ? $amount : $view->advance;
		$received = 'received' === $kind ? $amount : $view->received;
		$status   = $received >= $required ? 'paid' : ( $received > 0 ? 'review' : 'pending' );
		$record   = array(
			'field'   => $kind,
			'before'  => $previous,
			'after'   => $amount,
			'user_id' => get_current_user_id(),
			'at'      => time(),
			'reason'  => $reason,
		);
		// Materialize legacy records only when an editor explicitly corrects one.
		$order->update_meta_data( Core::META_ADVANCE, $required );
		$order->update_meta_data( Core::META_RECEIVED, $received );
		$order->update_meta_data( Core::META_REMAINING, $view->total - $received );
		if ( '' === $order->get_meta( Core::META_ORIGINAL_TOTAL ) ) {
			$order->update_meta_data( Core::META_ORIGINAL_TOTAL, $view->total );
		}
		$order->update_meta_data( Core::META_STATUS, $status );
		$order->update_meta_data( Core::META_AWAITING, 'review' === $status ? 'review' : 'no' );
		$order->update_meta_data( Core::META_LAST_CORRECTION, $record );
		if ( 'received' === $kind ) {
			$order->update_meta_data( Core::META_PAYMENT_SOURCE, 'manual' );
			$order->update_meta_data( Core::META_MANUAL_REFERENCE, (string) ( $input['reference'] ?? '' ) );
			if ( $received > 0 && ! $order->get_meta( Core::META_PAID_AT ) ) {
				$order->update_meta_data( Core::META_PAID_AT, time() );
			}
		}
		if ( 'paid' === $status ) {
			$order->set_payment_method( 'cod' );
			$order->set_payment_method_title( __( 'Cash on delivery', 'cod-shield' ) );
		} elseif ( 0.0 === $received ) {
			$order->set_payment_method( Core::GATEWAY_ID );
		}
		$order->save();
		if ( 'paid' !== $status && $order->has_status( array( 'processing', 'completed' ) ) ) {
			$order->update_status( 'on-hold' );
		} elseif ( 'paid' === $status && $order->has_status( array( 'pending', 'failed' ) ) ) {
			$order->update_status( 'processing' );
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: changed field, 2: old amount, 3: new amount, 4: user ID, 5: reason, 6: reference. */
				__( 'Partial COD correction — %1$s: %2$s → %3$s by user #%4$d. Reason: %5$s. Manual reference: %6$s. This records a correction only; no charge or refund was issued.', 'cod-shield' ),
				$kind,
				wc_price( $previous, array( 'currency' => $view->currency ) ),
				wc_price( $amount, array( 'currency' => $view->currency ) ),
				get_current_user_id(),
				$reason,
				(string) ( $input['reference'] ?? '' )
			)
		);
		return 'saved';
	}

	/** Handle the dedicated POST; never process arbitrary order-save input. */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in correct() before any mutation.
		$order_id = isset( $_POST['cod_shield_order_id'] ) ? absint( wp_unslash( $_POST['cod_shield_order_id'] ) ) : 0;
		$nonce    = isset( $_POST['cod_shield_editor_nonce'] ) && is_string( $_POST['cod_shield_editor_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cod_shield_editor_nonce'] ) ) : '';
		$input    = array();
		foreach ( array( 'kind', 'amount', 'reason', 'reference', 'revision' ) as $key ) {
			$field         = 'cod_shield_edit_' . $key;
			$input[ $key ] = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$result = $this->correct( $order_id, $nonce, $input );
		$order  = wc_get_order( $order_id );
		$back   = $order && current_user_can( 'edit_shop_order', $order_id ) ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );
		wp_safe_redirect( add_query_arg( 'cod_shield_correction', $result, $back ) );
		exit;
	}

	/** Display an escaped correction result. */
	public function notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice.
		$result   = isset( $_GET['cod_shield_correction'] ) && is_string( $_GET['cod_shield_correction'] ) ? sanitize_key( wp_unslash( $_GET['cod_shield_correction'] ) ) : '';
		$messages = array(
			'saved'   => __( 'Partial COD record updated. No charge or refund was issued.', 'cod-shield' ),
			'denied'  => __( 'Correction refused. Check your permission and reload the order.', 'cod-shield' ),
			'stale'   => __( 'The order changed since this page loaded. Reload it before correcting payment details.', 'cod-shield' ),
			'locked'  => __( 'This payment record is locked. Reconcile any pending attempt or review state before editing.', 'cod-shield' ),
			'invalid' => __( 'Enter a reason and a valid amount within the order total, using the currency precision.', 'cod-shield' ),
		);
		if ( isset( $messages[ $result ] ) ) {
			echo '<div class="notice ' . ( 'saved' === $result ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $messages[ $result ] ) . '</p></div>';
		}
	}

	/**
	 * Revalidate a changed full total without recursive saves or silent clamps.
	 *
	 * @param \WC_Order $order Order being saved.
	 * @return void
	 */
	public function revalidate_total( $order ) {
		if ( ! is_a( $order, '\WC_Order' ) || ! $order->get_id() || ! array_key_exists( 'total', $order->get_changes() ) || self::is_locked( $order ) ) {
			return;
		}
		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() ) {
			return;
		}
		$total = (float) $order->get_total();
		$order->update_meta_data( Core::META_REMAINING, $total - $view->received );
		if ( $total < $view->received || $total < $view->advance ) {
			$order->update_meta_data( Core::META_STATUS, 'review' );
			$order->update_meta_data( Core::META_AWAITING, 'review' );
			$order->set_status( 'on-hold' );
			$order->add_order_note( __( 'Partial COD needs review: the revised order total is below the recorded or required advance. No charge or refund was issued.', 'cod-shield' ) );
		}
	}
}
