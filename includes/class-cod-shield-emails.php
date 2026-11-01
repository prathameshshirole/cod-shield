<?php
/**
 * Payment summaries for customer order pages and emails.
 *
 * @package COD_Shield
 */

namespace CodShield;

defined( 'ABSPATH' ) || exit;

/** Display recorded amounts without changing order data. */
class Emails {

	/** Register customer and email displays. */
	public function __construct() {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_summary' ), 10, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_summary' ), 10, 1 );
	}

	/**
	 * Shared financial rows for customer pages, emails and invoices.
	 *
	 * @param Order_Meta $view Recorded order data.
	 * @return array
	 */
	public static function summary_rows( $view ) {
		$rows = array(
			'required' => array(
				'label'  => __( 'Advance required', 'cod-shield' ),
				'amount' => $view->advance,
			),
			'received' => array(
				'label'  => __( 'Payment received', 'cod-shield' ),
				'amount' => $view->received,
			),
			'balance'  => array(
				'label'  => 'paid' === $view->status ? __( 'Due on delivery', 'cod-shield' ) : __( 'Outstanding balance', 'cod-shield' ),
				'amount' => $view->remaining,
			),
		);
		if ( 'paid' !== $view->status ) {
			$rows['expected'] = array(
				'label'  => __( 'Expected delivery balance after advance payment', 'cod-shield' ),
				'amount' => $view->expected_remaining,
			);
		}
		return $rows;
	}

	/**
	 * Render only recorded partial-COD orders, including pending payments.
	 *
	 * @param \WC_Order $order Order.
	 * @param bool      $sent_to_admin Admin email context.
	 * @param bool      $plain_text Plain email context.
	 * @param mixed     $email Email object.
	 */
	public function render_summary( $order, $sent_to_admin = false, $plain_text = false, $email = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WooCommerce hook signature.
		$view = Order_Meta::from_order( $order );
		if ( ! $view->has_data() ) {
			return;
		}
		$states  = array(
			'paid'    => __( 'Advance received', 'cod-shield' ),
			'pending' => __( 'Awaiting advance payment', 'cod-shield' ),
			'failed'  => __( 'Advance payment failed', 'cod-shield' ),
			'review'  => __( 'Payment needs review', 'cod-shield' ),
		);
		$heading = $states[ $view->status ] ?? __( 'Partial COD payment', 'cod-shield' );
		if ( $plain_text ) {
			echo esc_html( $heading ) . "\n";
		} else {
			echo '<div class="cod-shield-email-summary"><h3>' . esc_html( $heading ) . '</h3><table role="presentation">';
		}
		foreach ( self::summary_rows( $view ) as $row ) {
			$price = wc_price( $row['amount'], array( 'currency' => $view->currency ) );
			if ( $plain_text ) {
				echo esc_html( $row['label'] . ': ' . html_entity_decode( wp_strip_all_tags( $price ), ENT_QUOTES, 'UTF-8' ) ) . "\n";
			} else {
				echo '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . wp_kses_post( $price ) . '</td></tr>';
			}
		}
		if ( ! $plain_text ) {
			echo '</table></div>';
		}
	}
}
