<?php
/**
 * Order emails: inject a compact "advance paid / due on delivery" summary
 * into WooCommerce customer and admin order emails.
 *
 * Implemented on the woocommerce_email_after_order_table hook (NOT via a
 * template override) so it survives WooCommerce template version bumps.
 * Amounts are read from order meta only — the order's own total is never
 * modified here, so there is no data-integrity side effect (unlike the
 * total-swap approach used by some other partial-COD plugins).
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/**
 * Emails class.
 */
class Emails {

	/**
	 * Hook registration.
	 */
	public function __construct() {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_summary' ), 10, 4 );
	}

	/**
	 * Render the advance-payment summary in order emails.
	 *
	 * Only fires for finalized advance orders (META_STATUS 'paid' with an
	 * advance recorded), so the summary never claims an advance was collected
	 * before it actually was. The 'paid' flag is set by Gateway_Bridge during
	 * finalize_advance_order() — which runs before the post-payment status
	 * transition that triggers these emails.
	 *
	 * @param \WC_Order $order         Order.
	 * @param bool      $sent_to_admin Sent to admin (unused).
	 * @param bool      $plain_text    Plain-text email.
	 * @param \WC_Email $email         Email object (unused).
	 * @return void
	 */
	public function render_summary( $order, $sent_to_admin, $plain_text = false, $email = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Hook signature.
		if ( ! is_a( $order, '\WC_Order' ) ) {
			return;
		}

		$core = Core::instance();
		if ( 'yes' !== $core->get_option( Core::OPT_MSG_EMAIL_ENABLED, 'yes' ) ) {
			return;
		}

		// Normalized view covers both SureOrder orders and legacy RIRCOD orders.
		$view = Order_Meta::from_order( $order );

		// Only summarize once the advance is confirmed collected.
		if ( ! $view->has_data() || 'paid' !== $view->status || $view->advance <= 0 ) {
			return;
		}

		$heading = $this->resolve_heading( $core, $view->advance, $view->remaining, $view->gateway, (bool) $plain_text );

		if ( $plain_text ) {
			$this->render_plain( $heading, $view->advance, $view->remaining, $view->gateway );
		} else {
			$this->render_html( $heading, $view->advance, $view->remaining, $view->gateway );
		}
	}

	/**
	 * Resolve the optional heading line, with placeholder substitution.
	 *
	 * @param Core   $core      Core instance.
	 * @param float  $advance   Advance amount.
	 * @param float  $remaining Remaining amount.
	 * @param string $gateway   Gateway id.
	 * @param bool   $plain     Plain-text context.
	 * @return string Empty string when no heading is configured.
	 */
	private function resolve_heading( $core, $advance, $remaining, $gateway, $plain ) {
		$template = (string) $core->get_option( Core::OPT_MSG_EMAIL_TEXT, '' );
		if ( '' === trim( $template ) ) {
			return '';
		}
		return Core::substitute_placeholders(
			$template,
			array(
				'advance_amount'   => $this->money( $advance, $plain ),
				'remaining_amount' => $this->money( $remaining, $plain ),
				'gateway'          => $gateway ? $gateway : '',
			)
		);
	}

	/**
	 * Format an amount as a price string, HTML-safe for plain text.
	 *
	 * @param float $amount Amount.
	 * @param bool  $plain  Plain-text context.
	 * @return string
	 */
	private function money( $amount, $plain ) {
		$html = wc_price( $amount );
		return $plain ? html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES ) : (string) $html;
	}

	/**
	 * HTML email output.
	 *
	 * @param string $heading   Heading line (may be empty).
	 * @param float  $advance   Advance amount.
	 * @param float  $remaining Remaining amount.
	 * @param string $gateway   Gateway id.
	 * @return void
	 */
	private function render_html( $heading, $advance, $remaining, $gateway ) {
		echo '<div class="cod-shield-email-summary" style="margin:16px 0 24px;padding:12px 0;border-top:1px solid #e5e5e5;">';

		if ( '' !== $heading ) {
			echo '<p style="margin:0 0 8px;font-size:14px;font-weight:600;">' . wp_kses_post( $heading ) . '</p>';
		}

		echo '<table role="presentation" cellspacing="0" cellpadding="4" style="width:100%;border-collapse:collapse;font-size:14px;">';

		if ( $gateway ) {
			// translators: %s: gateway id.
			$advance_label = sprintf( __( 'Advance paid (via %s)', 'cod-shield' ), $gateway );
		} else {
			$advance_label = __( 'Advance paid', 'cod-shield' );
		}

		printf(
			'<tr><th scope="row" style="text-align:left;font-weight:400;padding:2px 0;">%1$s</th><td style="text-align:right;font-weight:600;color:#1a7f37;padding:2px 0;">%2$s</td></tr>',
			esc_html( $advance_label ),
			wp_kses_post( wc_price( $advance ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		if ( $remaining > 0 ) {
			printf(
				'<tr><th scope="row" style="text-align:left;font-weight:400;padding:2px 0;">%1$s</th><td style="text-align:right;font-weight:600;color:#9a6700;padding:2px 0;">%2$s</td></tr>',
				esc_html__( 'Due on delivery', 'cod-shield' ),
				wp_kses_post( wc_price( $remaining ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}

		echo '</table></div>';
	}

	/**
	 * Plain-text email output.
	 *
	 * @param string $heading   Heading line (may be empty).
	 * @param float  $advance   Advance amount.
	 * @param float  $remaining Remaining amount.
	 * @param string $gateway   Gateway id.
	 * @return void
	 */
	private function render_plain( $heading, $advance, $remaining, $gateway ) {
		if ( '' !== $heading ) {
			echo esc_html( $heading ) . "\n";
		}
		if ( $gateway ) {
			// translators: 1: gateway id, 2: advance amount.
			$advance_label = sprintf( __( 'Advance paid (via %1$s): %2$s', 'cod-shield' ), $gateway, $this->money( $advance, true ) );
		} else {
			// translators: %s: advance amount.
			$advance_label = sprintf( __( 'Advance paid: %s', 'cod-shield' ), $this->money( $advance, true ) );
		}
		echo esc_html( $advance_label ) . "\n";
		if ( $remaining > 0 ) {
			// translators: %s: amount.
			echo esc_html( sprintf( __( 'Due on delivery: %s', 'cod-shield' ), $this->money( $remaining, true ) ) ) . "\n";
		}
	}
}
