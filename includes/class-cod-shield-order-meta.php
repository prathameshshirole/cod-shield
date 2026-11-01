<?php
/**
 * Normalized read model for COD-advance order data.
 *
 * Provides a single source of truth for "what advance/COD amounts does this
 * order carry?" across all display surfaces (orders column, metabox, inline
 * admin totals, order emails).
 *
 * It prefers this plugin's own meta keys (see Core::META_*) and transparently
 * falls back to the legacy/external RIRCOD schema when our keys are absent, so
 * orders created by the RIRCOD system (or any integration that writes the
 * rircod_* keys) are surfaced in our structured UI instead of remaining
 * buried as raw custom fields.
 *
 * RIRCOD schema (active when `rircod_is_cod` is yes / 1 / true):
 *   rircod_advance_amount  -> advance paid            (our META_ADVANCE)
 *   rircod_cod_amount      -> COD due on delivery      (our META_REMAINING)
 *   rircod_total_amount    -> total order amount       (our META_ORIGINAL_TOTAL)
 *
 * Read-only: this class never writes meta.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Order advance-meta value object + factory.
 */
class Order_Meta {

	/** RIRCOD: order is a COD+advance order when truthy. */
	const RIRCOD_IS_COD = 'rircod_is_cod';
	/** RIRCOD: amount due on delivery. */
	const RIRCOD_COD_AMOUNT = 'rircod_cod_amount';
	/** RIRCOD: total order amount. */
	const RIRCOD_TOTAL = 'rircod_total_amount';
	/** RIRCOD: advance already paid. */
	const RIRCOD_ADVANCE = 'rircod_advance_amount';

	const SOURCE_COD_SHIELD = 'cod_shield';
	const SOURCE_RIRCOD     = 'rircod';

	/**
	 * Where the data originated: 'cod_shield', 'rircod', or null.
	 *
	 * @var string|null
	 */
	public $source;

	/**
	 * Required advance amount.
	 *
	 * @var float
	 */
	public $advance = 0.0;

	/**
	 * Amount due on delivery.
	 *
	 * @var float
	 */
	public $remaining = 0.0;

	/**
	 * Recorded money received, distinct from the required advance.
	 *
	 * @var float
	 */
	public $received = 0.0;

	/**
	 * Expected delivery balance after the required advance.
	 *
	 * @var float
	 */
	public $expected_remaining = 0.0;

	/**
	 * Order currency.
	 *
	 * @var string
	 */
	public $currency = '';

	/**
	 * Original calculation mode.
	 *
	 * @var string
	 */
	public $mode = '';

	/**
	 * Original configured calculation value.
	 *
	 * @var string
	 */
	public $value = '';

	/**
	 * Collection source: gateway or manual.
	 *
	 * @var string
	 */
	public $payment_source = '';

	/**
	 * Separate manual reference; never replaces gateway evidence.
	 *
	 * @var string
	 */
	public $manual_reference = '';

	/**
	 * Collection timestamp.
	 *
	 * @var int
	 */
	public $paid_at = 0;

	/**
	 * Last correction attribution.
	 *
	 * @var array
	 */
	public $last_correction = array();

	/**
	 * Full order total at creation time.
	 *
	 * @var float
	 */
	public $total = 0.0;

	/**
	 * Gateway id used to collect the advance (empty for RIRCOD).
	 *
	 * @var string
	 */
	public $gateway = '';

	/**
	 * Gateway transaction id (empty for RIRCOD).
	 *
	 * @var string
	 */
	public $txn = '';

	/**
	 * Advance status: 'paid', 'pending', 'failed', or ''.
	 *
	 * @var string
	 */
	public $status = '';

	/**
	 * Private constructor — build via from_order() / from_meta_array().
	 */
	private function __construct() {
	}

	/**
	 * Build the view from a WC_Order.
	 *
	 * @param \WC_Order|mixed $order Order object.
	 * @return self
	 */
	public static function from_order( $order ) {
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return new self();
		}

		$view           = self::from_meta_array(
			array(
				Core::META_RECEIVED         => $order->get_meta( Core::META_RECEIVED ),
				Core::META_MODE             => $order->get_meta( Core::META_MODE ),
				Core::META_VALUE            => $order->get_meta( Core::META_VALUE ),
				Core::META_PAYMENT_SOURCE   => $order->get_meta( Core::META_PAYMENT_SOURCE ),
				Core::META_PAID_AT          => $order->get_meta( Core::META_PAID_AT ),
				Core::META_MANUAL_REFERENCE => $order->get_meta( Core::META_MANUAL_REFERENCE ),
				Core::META_LAST_CORRECTION  => $order->get_meta( Core::META_LAST_CORRECTION ),
				Core::META_ADVANCE          => $order->get_meta( Core::META_ADVANCE ),
				Core::META_REMAINING        => $order->get_meta( Core::META_REMAINING ),
				Core::META_ORIGINAL_TOTAL   => $order->get_meta( Core::META_ORIGINAL_TOTAL ),
				Core::META_GATEWAY_USED     => $order->get_meta( Core::META_GATEWAY_USED ),
				Core::META_TXN              => $order->get_meta( Core::META_TXN ),
				Core::META_STATUS           => $order->get_meta( Core::META_STATUS ),
				Core::META_AWAITING         => $order->get_meta( Core::META_AWAITING ),
				self::RIRCOD_IS_COD         => $order->get_meta( self::RIRCOD_IS_COD ),
				self::RIRCOD_ADVANCE        => $order->get_meta( self::RIRCOD_ADVANCE ),
				self::RIRCOD_COD_AMOUNT     => $order->get_meta( self::RIRCOD_COD_AMOUNT ),
				self::RIRCOD_TOTAL          => $order->get_meta( self::RIRCOD_TOTAL ),
			)
		);
		$view->currency = is_callable( array( $order, 'get_currency' ) ) ? $order->get_currency() : '';
		// An in-flight gateway order temporarily carries only the advance total.
		if ( $view->has_data() && 'yes' !== $order->get_meta( Core::META_ATTEMPT_STARTED ) && ( 'yes' !== $order->get_meta( Core::META_AWAITING ) || 'no' === $order->get_meta( Core::META_ATTEMPT_STARTED ) ) ) {
			$view->total = (float) $order->get_total();
		}
		$view->remaining          = max( 0.0, $view->total - $view->received );
		$view->expected_remaining = max( 0.0, $view->total - $view->advance );
		return $view;
	}

	/**
	 * Pure factory from a flat meta map ([key => value]). Unit-testable without
	 * WordPress. SureOrder meta takes precedence over the RIRCOD schema.
	 *
	 * @param array<string,mixed> $m Meta map.
	 * @return self
	 */
	public static function from_meta_array( array $m ) {
		$view = new self();

		// 1. Native SureOrder meta wins when present.
		$advance = (float) ( $m[ Core::META_ADVANCE ] ?? 0 );
		if ( $advance > 0 ) {
			$view->source    = self::SOURCE_COD_SHIELD;
			$view->advance   = $advance;
			$view->remaining = (float) ( $m[ Core::META_REMAINING ] ?? 0 );
			$view->total     = (float) ( $m[ Core::META_ORIGINAL_TOTAL ] ?? 0 );
			$view->gateway   = (string) ( $m[ Core::META_GATEWAY_USED ] ?? '' );
			$view->txn       = (string) ( $m[ Core::META_TXN ] ?? '' );
			$view->status    = (string) ( $m[ Core::META_STATUS ] ?? '' );
			if ( '' === $view->status ) {
				$view->status = self::is_truthy( $m[ Core::META_AWAITING ] ?? '' ) ? 'pending' : '';
			}
			if ( 'review' === ( $m[ Core::META_AWAITING ] ?? '' ) ) {
				$view->status = 'review';
			}
			$received                 = $m[ Core::META_RECEIVED ] ?? '';
			$view->received           = is_numeric( $received ) ? (float) $received : ( 'paid' === ( $m[ Core::META_STATUS ] ?? '' ) ? $advance : 0.0 );
			$view->total              = $view->total > 0 ? $view->total : $advance + $view->remaining;
			$view->remaining          = max( 0.0, $view->total - $view->received );
			$view->expected_remaining = max( 0.0, $view->total - $advance );
			$view->mode               = (string) ( $m[ Core::META_MODE ] ?? '' );
			$view->value              = (string) ( $m[ Core::META_VALUE ] ?? '' );
			$view->payment_source     = (string) ( $m[ Core::META_PAYMENT_SOURCE ] ?? '' );
			$view->manual_reference   = (string) ( $m[ Core::META_MANUAL_REFERENCE ] ?? '' );
			$view->paid_at            = (int) ( $m[ Core::META_PAID_AT ] ?? 0 );
			$view->last_correction    = is_array( $m[ Core::META_LAST_CORRECTION ] ?? null ) ? $m[ Core::META_LAST_CORRECTION ] : array();
			return $view;
		}

		// 2. Legacy/external RIRCOD schema.
		if ( self::is_truthy( $m[ self::RIRCOD_IS_COD ] ?? '' ) ) {
			$advance   = (float) ( $m[ self::RIRCOD_ADVANCE ] ?? 0 );
			$remaining = (float) ( $m[ self::RIRCOD_COD_AMOUNT ] ?? 0 );
			$total     = (float) ( $m[ self::RIRCOD_TOTAL ] ?? 0 );

			if ( $advance <= 0 && $remaining <= 0 && $total <= 0 ) {
				return $view; // Flagged but no amounts recorded — nothing to display.
			}

			$view->received           = $advance;
			$view->expected_remaining = $remaining;
			$view->source             = self::SOURCE_RIRCOD;
			$view->advance            = $advance;
			$view->remaining          = $remaining;
			$view->total              = $total > 0 ? $total : ( $advance + $remaining );
			// RIRCOD doesn't track gateway/txn; reuse ours if they happen to exist.
			$view->gateway = (string) ( $m[ Core::META_GATEWAY_USED ] ?? '' );
			$view->txn     = (string) ( $m[ Core::META_TXN ] ?? '' );
			$view->status  = $advance > 0 ? 'paid' : 'pending';
		}

		return $view;
	}

	/**
	 * Whether there is any advance/COD data worth displaying.
	 *
	 * @return bool
	 */
	public function has_data() {
		return null !== $this->source && ( $this->advance > 0 || $this->remaining > 0 );
	}

	/**
	 * Human-readable source label.
	 *
	 * @return string
	 */
	public function source_label() {
		switch ( $this->source ) {
			case self::SOURCE_COD_SHIELD:
				return __( 'SureOrder', 'cod-shield' );
			case self::SOURCE_RIRCOD:
				return __( 'RIRCOD', 'cod-shield' );
			default:
				return '—';
		}
	}

	/**
	 * Interpret a "truthy flag" value loosely (yes / 1 / true / on).
	 *
	 * @param mixed $value Raw meta value.
	 * @return bool
	 */
	private static function is_truthy( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value > 0;
		}
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', '1', 'true', 'on' ), true );
	}
}
