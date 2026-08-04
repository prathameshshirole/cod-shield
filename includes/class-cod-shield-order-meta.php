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
	 * Advance already collected.
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

		return self::from_meta_array(
			array(
				Core::META_ADVANCE        => $order->get_meta( Core::META_ADVANCE ),
				Core::META_REMAINING      => $order->get_meta( Core::META_REMAINING ),
				Core::META_ORIGINAL_TOTAL => $order->get_meta( Core::META_ORIGINAL_TOTAL ),
				Core::META_GATEWAY_USED   => $order->get_meta( Core::META_GATEWAY_USED ),
				Core::META_TXN            => $order->get_meta( Core::META_TXN ),
				Core::META_STATUS         => $order->get_meta( Core::META_STATUS ),
				Core::META_AWAITING       => $order->get_meta( Core::META_AWAITING ),
				self::RIRCOD_IS_COD       => $order->get_meta( self::RIRCOD_IS_COD ),
				self::RIRCOD_ADVANCE      => $order->get_meta( self::RIRCOD_ADVANCE ),
				self::RIRCOD_COD_AMOUNT   => $order->get_meta( self::RIRCOD_COD_AMOUNT ),
				self::RIRCOD_TOTAL        => $order->get_meta( self::RIRCOD_TOTAL ),
			)
		);
	}

	/**
	 * Pure factory from a flat meta map ([key => value]). Unit-testable without
	 * WordPress. COD Shield meta takes precedence over the RIRCOD schema.
	 *
	 * @param array<string,mixed> $m Meta map.
	 * @return self
	 */
	public static function from_meta_array( array $m ) {
		$view = new self();

		// 1. Native COD Shield meta wins when present.
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

			$view->source    = self::SOURCE_RIRCOD;
			$view->advance   = $advance;
			$view->remaining = $remaining;
			$view->total     = $total > 0 ? $total : ( $advance + $remaining );
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
				return __( 'COD Shield', 'cod-shield' );
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
