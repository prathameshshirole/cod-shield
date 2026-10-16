<?php
/**
 * Checkout cart-fee and messaging regressions without WordPress.
 *
 * @package COD_Shield
 */

define( 'ABSPATH', __DIR__ . '/../' );
require_once __DIR__ . '/../includes/class-cod-shield-core.php';
require_once __DIR__ . '/../includes/class-cod-shield-checkout.php';

use CodShield\Core;
use CodShield\Checkout;
use CodShield\Eligibility;
use CodShield\Gateway_Bridge;

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text ); }
function wp_kses_post( $text ) { return $text; }
function sanitize_title( $text ) { return strtolower( str_replace( ' ', '-', $text ) ); }
function wc_price( $value ) { return '$' . number_format( $value, 2 ); }
function is_checkout() { return false; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function apply_filters( $name, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function WC() { return $GLOBALS['wc']; }
function wc_get_order( $id ) { return $GLOBALS['order']; }

final class Checkout_Session {
	public $method = Core::GATEWAY_ID;
	public $chosen_shipping = array();
	public function get( $key, $default = '' ) {
		if ( 'chosen_shipping_methods' === $key ) {
			return $this->chosen_shipping;
		}
		return 'chosen_payment_method' === $key ? $this->method : $default;
	}
}
final class Checkout_Cart {
	public $contents = 100;
	public $tax = 10;
	public $shipping = 20;
	public $shipping_tax = 2;
	public $fees = array();
	public $added = array();
	public $items = array();
	public function get_cart_contents_total() { return $this->contents; }
	public function get_cart_contents_tax() { return $this->tax; }
	public function get_shipping_total() { return $this->shipping; }
	public function get_shipping_tax() { return $this->shipping_tax; }
	public function get_fees() { return $this->fees; }
	public function get_total( $context = '' ) { return 9999; }
	public function get_cart() { return $this->items; }
	public function needs_shipping() { return true; }
	public function add_fee( $name, $amount, $taxable, $tax_class ) {
		$this->added[] = array( $name, $amount, $taxable, $tax_class );
	}
}
final class Checkout_Request {
	private $route;
	public $payment_method = '';
	public function __construct( $route ) { $this->route = $route; }
	public function get_route() { return $this->route; }
	public function get_method() { return 'POST'; }
	public function get_param( $key ) { return $this->payment_method; }
}
final class WP_Error {
	public function __construct( $code, $message, $data ) {}
}
final class Checkout_Gateways {
	public function payment_gateways() { return array( 'cod' => (object) array( 'enabled' => 'yes' ) ); }
}
final class Checkout_WC {
	public $cart;
	public $session;
	public $customer = null;
	public $gateway_provider_override = null;
	public function payment_gateways() { return $this->gateway_provider_override ?? new Checkout_Gateways(); }
}
final class Checkout_Order {
	public function get_payment_method() { return 'cod'; }
	public function get_meta( $key ) {
		return array( Core::META_STATUS => 'paid', Core::META_ADVANCE => 20, Core::META_REMAINING => 80, Core::META_GATEWAY_USED => 'internal_secret_gateway' )[ $key ] ?? '';
	}
}

$GLOBALS['options'] = array_merge( Core::get_defaults(), array(
	Core::OPT_FEE_ENABLED => 'yes', Core::OPT_FEE_TYPE => 'percentage',
	Core::OPT_FEE_PERCENTAGE => 10, Core::OPT_FEE_TAXABLE => 'yes',
	Core::OPT_FEE_TAX_CLASS => 'reduced-rate',
) );
$core_class = new ReflectionClass( Core::class );
$core_class->getProperty( 'instance' )->setValue( null, $core_class->newInstanceWithoutConstructor() );
$GLOBALS['wc'] = new Checkout_WC();
WC()->cart = new Checkout_Cart();
WC()->session = new Checkout_Session();
$checkout = ( new ReflectionClass( Checkout::class ) )->newInstanceWithoutConstructor();
$checks = 0;
function checkout_assert( $condition, $message ) {
	global $checks;
	++$checks;
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}

$checkout->track_store_api_request( null, array(), new Checkout_Request( '/wc/store/v1/cart/extensions' ) );
$checkout->maybe_add_cod_fee( WC()->cart );
checkout_assert( 13.2 === WC()->cart->added[0][1], 'Fee must use current discounted cart amounts including product and shipping tax, not stale get_total().' );
checkout_assert( true === WC()->cart->added[0][2] && 'reduced-rate' === WC()->cart->added[0][3], 'Taxable fee configuration must reach WooCommerce before checkout.' );
WC()->cart->fees = array(
	(object) array( 'id' => 'cash-on-delivery-fee', 'amount' => 13.2, 'tax' => 1.32 ),
	(object) array( 'id' => 'packing', 'amount' => 5, 'tax' => 0.5 ),
);
$checkout->maybe_add_cod_fee( WC()->cart );
checkout_assert( 13.75 === WC()->cart->added[1][1], 'Repeated calculations exclude the previous COD fee and its tax, while including other fees.' );
WC()->cart->contents = 50;
$checkout->maybe_add_cod_fee( WC()->cart );
checkout_assert( 8.75 === WC()->cart->added[2][1], 'Coupon changes must lower the fee before submission.' );
WC()->session->method = 'card';
$checkout->maybe_add_cod_fee( WC()->cart );
checkout_assert( 3 === count( WC()->cart->added ), 'Switching to another payment method must not add a COD fee.' );
WC()->session->method = 'cod';
$checkout->track_store_api_request( null, array(), new Checkout_Request( '/wc/v3/orders' ) );
$checkout->maybe_add_cod_fee( WC()->cart );
checkout_assert( 3 === count( WC()->cart->added ), 'Administrative REST requests must not add storefront fees.' );
$GLOBALS['options'][ Core::OPT_THRESHOLD ] = 10000;
ob_start();
$checkout->render_cod_minimum_notice();
$notice = ob_get_clean();
checkout_assert( str_contains( $notice, 'Cash on delivery is available on orders of $10,000.00 or more.' ), 'Hidden COD must have an upfront minimum explanation.' );
$GLOBALS['order'] = new Checkout_Order();
ob_start();
$checkout->render_thankyou_summary( 1 );
$summary = ob_get_clean();
checkout_assert( ! str_contains( $summary, 'internal_secret_gateway' ) && str_contains( $summary, '$80.00' ), 'Confirmation must show delivery amount without exposing internal gateway identifiers.' );
$request = new Checkout_Request( '/wc/store/v1/checkout' );
$request->payment_method = 'card';
checkout_assert( $checkout->track_store_api_request( null, array(), $request ) instanceof WP_Error, 'Checkout must reject charging stale COD fees when switching to card without refreshing.' );
WC()->session->method = 'card';
$request->payment_method = Core::GATEWAY_ID;
checkout_assert( $checkout->track_store_api_request( null, array(), $request ) instanceof WP_Error, 'Checkout must reject COD submission when its fee has not been previewed.' );
WC()->session->method = Core::GATEWAY_ID;
checkout_assert( null === $checkout->track_store_api_request( null, array(), $request ), 'Checkout accepts the same payment method used to preview totals.' );

// ------------------------------------------------------------------ native COD parity.
// The advance proxy must never resurrect native COD that WooCommerce core
// removed (e.g. the COD gateway's own "enable for methods" shipping
// restriction), while still replacing native COD in the normal advance flow.

/** Minimal native gateway stubs for the parity chain. */
class WC_Payment_Gateway {
	public $id;
	public $method_title;
	public $method_description;
	public $has_fields;
	public $supports;
	public $enabled;
	public $title;
	public $description;
	public $order_button_text;
}
class WC_Order {}
class Checkout_Gateway_Entry extends WC_Payment_Gateway {
	public $enabled = 'yes';
}
class Checkout_Outstanding_Order extends WC_Order {
	public function get_meta( $key = '', $single = false, $context = 'view' ) {
		return array(
			Core::META_STATUS   => 'pending',
			Core::META_ADVANCE  => 20,
			Core::META_AWAITING => 'yes',
		)[ (string) $key ] ?? '';
	}
}
class WC_Razorpay extends WC_Payment_Gateway {
	public $id       = 'razorpay';
	public $enabled  = 'yes';
	public $supports = array( 'products' );
	public function process_payment( $order_id ) { return array(); }
	public function get_option( $key, $default = '' ) { return $default; }
}
final class Checkout_Gateways_With_Delegation {
	public function payment_gateways() {
		return array(
			'cod'      => (object) array( 'enabled' => 'yes' ),
			'razorpay' => new WC_Razorpay(),
		);
	}
}
$GLOBALS['checkout_order_pay'] = false;
function is_wc_endpoint_url( $endpoint ) { return $GLOBALS['checkout_order_pay']; }
function get_query_var( $key ) { return 1; }
function absint( $value ) { return abs( (int) $value ); }

require_once __DIR__ . '/../includes/class-cod-shield-eligibility.php';
require_once __DIR__ . '/../includes/class-cod-shield-gateway-cod-advance.php';
require_once __DIR__ . '/../includes/class-cod-shield-gateway-bridge.php';
require_once __DIR__ . '/../includes/class-cod-shield-blocks.php';

$GLOBALS['options'][ Core::OPT_MODE ]     = 'fixed';
$GLOBALS['options'][ Core::OPT_THRESHOLD ] = 0;
$GLOBALS['options'][ Core::OPT_GATEWAYS ] = array( 'razorpay' );
$GLOBALS['options'][ Eligibility::OPT_MAXIMUM ] = 0;
$GLOBALS['wc'] = new Checkout_WC();
$GLOBALS['wc']->gateway_provider_override = new Checkout_Gateways_With_Delegation();
$GLOBALS['wc']->cart = new Checkout_Cart();
$GLOBALS['wc']->session = new Checkout_Session();
$GLOBALS['order'] = new Checkout_Order();
$parity_core = new ReflectionProperty( Core::class, 'instance' );
$parity_core->setValue( null, $core_class->newInstanceWithoutConstructor() );
$bridge = ( new ReflectionClass( Gateway_Bridge::class ) )->newInstanceWithoutConstructor();

// Case A: WooCommerce core removed native COD (shipping-method restriction).
$available = array( 'card' => new Checkout_Gateway_Entry() );
$filtered  = $checkout->filter_available_gateways( $available );
$filtered  = $bridge->maybe_add_proxy_gateway( $filtered );
checkout_assert( ! isset( $filtered[ Core::GATEWAY_ID ] ), 'Proxy must never appear when native COD was removed by shipping-method configuration.' );

// Case B: the normal advance flow swaps native COD for the proxy.
$available = array( 'card' => new Checkout_Gateway_Entry(), 'cod' => new Checkout_Gateway_Entry() );
$filtered  = $checkout->filter_available_gateways( $available );
checkout_assert( ! isset( $filtered['cod'] ), 'The advance flow must swap out native COD at priority 10.' );
$filtered = $bridge->maybe_add_proxy_gateway( $filtered );
checkout_assert( isset( $filtered[ Core::GATEWAY_ID ] ), 'The proxy must replace native COD in the normal advance flow.' );

// Case C: order-pay for an outstanding advance is never re-evaluated against
// storefront rules — even an active maximum exclusion must not remove the proxy.
$eligibility = ( new ReflectionClass( Eligibility::class ) )->newInstanceWithoutConstructor();
$GLOBALS['options'][ Eligibility::OPT_MAXIMUM ] = 100;
$GLOBALS['checkout_order_pay'] = true;
$GLOBALS['order'] = new Checkout_Outstanding_Order();
$order_pay_gateways = array( 'card' => new Checkout_Gateway_Entry(), 'cod' => new Checkout_Gateway_Entry(), Core::GATEWAY_ID => new Checkout_Gateway_Entry() );
$filtered = $eligibility->filter_available_gateways( $order_pay_gateways );
checkout_assert( isset( $filtered['cod'] ) && isset( $filtered[ Core::GATEWAY_ID ] ), 'Order-pay must bypass storefront eligibility rules for an existing advance obligation.' );

// Same exclusion on a storefront (non order-pay) list removes both methods.
$GLOBALS['checkout_order_pay'] = false;
$filtered = $eligibility->filter_available_gateways( $order_pay_gateways );
checkout_assert( ! isset( $filtered['cod'] ) && ! isset( $filtered[ Core::GATEWAY_ID ] ), 'Storefront eligibility must remove native COD and the proxy above the maximum.' );
$GLOBALS['options'][ Eligibility::OPT_MAXIMUM ] = 0;
$GLOBALS['order'] = new Checkout_Order();

// Case D: a shipping-method exclusion removes both methods on the storefront.
$GLOBALS['wc']->session->chosen_shipping = array( 'local_pickup:5' );
$GLOBALS['options'][ Eligibility::OPT_EXCLUDED_SHIPPING_METHODS ] = 'local_pickup';
$storefront = $eligibility->filter_available_gateways( $order_pay_gateways );
checkout_assert( ! isset( $storefront['cod'] ) && ! isset( $storefront[ Core::GATEWAY_ID ] ), 'A selected excluded shipping method must remove native COD and the proxy.' );
checkout_assert( 'excluded_shipping_method' === Eligibility::evaluate_current_cart()['code'], 'Shipping exclusions report their stable reason code.' );
$GLOBALS['options'][ Eligibility::OPT_EXCLUDED_SHIPPING_METHODS ] = '';

// Case E: a catalog exclusion removes both methods on the storefront.
$GLOBALS['wc']->session->chosen_shipping = array();
$GLOBALS['options'][ Eligibility::OPT_EXCLUDED_PRODUCTS ] = '50';
$GLOBALS['wc']->cart->items = array( array( 'product_id' => 50, 'variation_id' => 0 ) );
$storefront = $eligibility->filter_available_gateways( $order_pay_gateways );
checkout_assert( ! isset( $storefront['cod'] ) && ! isset( $storefront[ Core::GATEWAY_ID ] ), 'An excluded product in the cart must remove native COD and the proxy.' );
checkout_assert( 'excluded_product' === Eligibility::evaluate_current_cart()['code'], 'Catalog exclusions report their stable reason code.' );

// Case F: the Blocks Store API payload carries the structured reason, and the
// shared evaluation never depends on the chosen payment method.
$blocks = ( new ReflectionClass( CodShield\Blocks::class ) )->newInstanceWithoutConstructor();
$payload = $blocks->get_cart_data();
checkout_assert( false === $payload['cod_eligible'] && 'excluded_product' === $payload['cod_ineligibility_code'], 'Blocks data carries the structured ineligibility code.' );
checkout_assert( str_contains( $payload['cod_ineligibility_message'], 'not available for some items' ), 'Blocks data carries the customer-facing reason.' );
checkout_assert( false === $payload['available'], 'Blocks availability follows the shared evaluation.' );
$GLOBALS['wc']->session->method = 'card';
$payload_card = $blocks->get_cart_data();
checkout_assert( $payload_card['cod_ineligibility_code'] === $payload['cod_ineligibility_code'] && $payload_card['payment_method'] === 'card', 'Reason fields are independent of the selected payment method.' );
$GLOBALS['wc']->session->method = Core::GATEWAY_ID;

// Eligible cart: structured fields are clean and availability is restored.
$GLOBALS['options'][ Eligibility::OPT_EXCLUDED_PRODUCTS ] = '';
$GLOBALS['wc']->cart->items = array();
$payload = $blocks->get_cart_data();
checkout_assert( true === $payload['cod_eligible'] && '' === $payload['cod_ineligibility_code'] && '' === $payload['cod_ineligibility_message'], 'An eligible cart reports clean structured fields.' );
checkout_assert( true === $payload['available'], 'Availability is restored once exclusions no longer match.' );
echo "Checkout regressions: $checks checks passed.\n";
