<?php
/**
 * Standalone checkout payment breakdown regression tests.
 *
 * Exercises Core messaging and the classic gateway's order-pay description
 * without a WordPress database. Run with php tests/run-breakdown.php.
 *
 * @package COD_Shield
 */

define( 'ABSPATH', __DIR__ . '/../' );

function __( $text, $domain = 'default' ) {
	return $text;
}
function get_option( $key, $default = null ) {
	return $GLOBALS['breakdown_options'][ $key ] ?? $default;
}
function wc_price( $amount, $args = array() ) {
	$currency = $args['currency'] ?? 'INR';
	return '<span class="amount">' . $currency . ' ' . number_format( $amount, 2, '.', '' ) . '</span>';
}
function wp_kses_post( $html ) {
	$GLOBALS['breakdown_sanitized'][] = $html;
	return strip_tags( preg_replace( '#<script[^>]*>.*?</script>#is', '', $html ), '<span><strong><p>' );
}
function is_wc_endpoint_url( $endpoint ) {
	return $GLOBALS['breakdown_order_pay'];
}
function get_query_var( $key ) {
	return 1;
}
function absint( $value ) {
	return abs( (int) $value );
}
function wc_get_order( $order_id ) {
	return $GLOBALS['breakdown_order'];
}
class WC_Payment_Gateway {
	public $id;
	public $method_title;
	public $method_description;
	public $has_fields;
	public $supports;
	public $enabled;
	public $title;
	public $description;
}

require_once __DIR__ . '/../includes/class-cod-shield-core.php';
require_once __DIR__ . '/../includes/class-cod-shield-gateway-cod-advance.php';

use CodShield\Core;
use CodShield\Gateway_COD_Advance;

class Breakdown_Core extends Core {
	public function __construct() {}
	public function get_cart_total() {
		return 1000.0;
	}
}
class Breakdown_Order {
	public $meta = array();
	public $total = 99.0;
	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}
	public function get_total() {
		return $this->total;
	}
	public function get_currency() {
		return 'GBP';
	}
}

$checks = 0;
function breakdown_contains( $html, $expected ) {
	global $checks;
	++$checks;
	if ( false === strpos( $html, $expected ) ) {
		fwrite( STDERR, "FAIL: Missing $expected in $html\n" );
		exit( 1 );
	}
}
function breakdown_excludes( $html, $unexpected ) {
	global $checks;
	++$checks;
	if ( false !== strpos( $html, $unexpected ) ) {
		fwrite( STDERR, "FAIL: Unexpected $unexpected in $html\n" );
		exit( 1 );
	}
}

$GLOBALS['breakdown_options'] = array_merge( Core::get_defaults(), array( Core::OPT_MODE => 'fixed' ) );
$GLOBALS['breakdown_order_pay'] = false;
$GLOBALS['breakdown_sanitized'] = array();
$core = new Breakdown_Core();
$singleton = new ReflectionProperty( Core::class, 'instance' );
$singleton->setValue( null, $core );
$gateway = new Gateway_COD_Advance();

$html = $gateway->get_description();
breakdown_contains( $html, '<dt>Pay now</dt><dd><span class="amount">INR 99.00</span></dd>' );
breakdown_contains( $html, '<dt>Pay on delivery</dt><dd><span class="amount">INR 901.00</span></dd>' );
breakdown_contains( $html, 'This advance is part of your order total.' );
breakdown_excludes( $html, 'cod-shield-payment-instructions' );

$GLOBALS['breakdown_options'][ Core::OPT_MSG_ADV_ENABLED ] = 'no';
$html = $core->get_advance_breakdown( 99, 901 );
breakdown_contains( $html, 'INR 99.00' );
breakdown_contains( $html, 'INR 901.00' );
breakdown_excludes( $html, 'cod-shield-payment-instructions' );

$GLOBALS['breakdown_options'][ Core::OPT_MSG_ADV_ENABLED ] = 'yes';
$GLOBALS['breakdown_options'][ Core::OPT_MSG_ADV_TEXT ] = '<strong>Deposit {advance_amount}; balance {remaining_amount}; rate {percentage}%.</strong><script>bad()</script>';
$html = $core->get_advance_breakdown( 99, 901, 'GBP' );
breakdown_contains( $html, '<strong>Deposit <span class="amount">GBP 99.00</span>; balance <span class="amount">GBP 901.00</span>; rate 10%.</strong>' );
breakdown_contains( $html, 'cod-shield-payment-instructions' );
breakdown_excludes( $html, '<script>' );
breakdown_contains( $html, '<dt>Pay now</dt>' );

$GLOBALS['breakdown_options'][ Core::OPT_MSG_ADV_TEXT ] = Core::get_defaults()[ Core::OPT_MSG_ADV_TEXT ];
$GLOBALS['breakdown_order_pay'] = true;
$order = new Breakdown_Order();
$order->meta = array( Core::META_ADVANCE => 99, Core::META_REMAINING => 901, Core::META_ORIGINAL_TOTAL => 1000 );
$GLOBALS['breakdown_order'] = $order;
$html = $gateway->get_description();
breakdown_contains( $html, 'GBP 99.00' );
breakdown_contains( $html, 'GBP 901.00' );
breakdown_excludes( $html, 'INR' );

// Explicit zero must remain zero, even when the order total is restored.
$order->meta[ Core::META_REMAINING ] = '0';
$order->total = 1000;
$html = $gateway->get_description();
breakdown_contains( $html, '<dt>Pay on delivery</dt><dd><span class="amount">GBP 0.00</span></dd>' );
breakdown_excludes( $html, 'GBP 901.00' );

// A legacy missing balance uses the original total, not the reduced advance.
unset( $order->meta[ Core::META_REMAINING ] );
$order->total = 99;
$html = $gateway->get_description();
breakdown_contains( $html, 'GBP 901.00' );

// Legacy orders without either balance or original total use their full total.
unset( $order->meta[ Core::META_ORIGINAL_TOTAL ] );
$order->total = 1000;
$html = $gateway->get_description();
breakdown_contains( $html, 'GBP 901.00' );

$html = $core->get_advance_breakdown( -5, -10 );
breakdown_contains( $html, '<dt>Pay now</dt><dd><span class="amount">INR 0.00</span></dd>' );
breakdown_contains( $html, '<dt>Pay on delivery</dt><dd><span class="amount">INR 0.00</span></dd>' );

echo "Payment breakdown: $checks checks passed.\n";
