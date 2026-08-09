<?php
/**
 * Standalone money-lifecycle tests for the COD advance flow.
 *
 * The pure calculation engines (CodShield\Core::calculate_advance() /
 * calculate_fee()) are covered by tests/run-tests.php + the PHPUnit suite.
 * THIS harness covers the *money lifecycle* — the code that moves money and
 * mutates order totals (do_finalize, the fulfilment revert guard, manual
 * reconciliation) — which the unit suite deliberately does not exercise.
 *
 * It runs WITHOUT WordPress or a database: a lightweight Fake_Order plus
 * minimal WP/WC polyfills stand in for the real environment so the guard
 * logic and money math can be regression-tested here, in CI, on every commit.
 *
 *   php tests/run-lifecycle.php
 *
 * Coverage:
 *   1. Finalization is transaction-gated (no gateway txn -> no-op).
 *   2. Normal finalization restores the full total, converts to COD, marks paid.
 *   3. Overcharge guard: a gateway that charged more than the advance is NOT
 *      silently converted to COD; the order is flagged and the method retained.
 *   4. Fulfilment guard reverts an unpaid-advance order moved to processing.
 *   5. Manual "mark advance as paid" converts the order after a failed attempt.
 *   6. Finalization is idempotent (a second call is a no-op, no duplicate notes).
 *   7. A transaction from a different payment method cannot finalize.
 *
 * NOTE: these tests exercise the plugin's lifecycle *logic* against a fake
 * order. Full end-to-end coverage against a real WC_Order (process_payment
 * delegation, webhook-driven status transitions) still requires the WordPress +
 * WooCommerce test framework (see tests/integration/README.md).
 *
 * @package COD_Shield
 */

// Allow loading the class files outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-cod-shield-core.php';
require_once __DIR__ . '/../includes/class-cod-shield-gateway-bridge.php';

use CodShield\Core;
use CodShield\Gateway_Bridge;

// ------------------------------------------------------------------ polyfills.

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return $text;
	}
}

/** No-op hook registration (lifecycle logic under test does not rely on them). */
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

/** In-memory option store (seeded with plugin defaults). */
$GLOBALS['_cod_opts'] = array_merge(
	array_fill_keys(
		array(
			Core::OPT_ENABLED, Core::OPT_MODE, Core::OPT_FIXED, Core::OPT_PERCENTAGE,
			Core::OPT_MIN, Core::OPT_MAX, Core::OPT_THRESHOLD, Core::OPT_GATEWAYS,
			Core::OPT_FAILURE, Core::OPT_ORDER_STATUS,
		),
		null
	),
	Core::get_defaults()
);

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['_cod_opts'] ) ? $GLOBALS['_cod_opts'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['_cod_opts'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	function wc_price( $amount ) {
		return round( (float) $amount, 2 ) . '';
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return (object) array( 'display_name' => 'Tester' );
	}
}

/**
 * Minimal WC() stub: payment_gateways()->payment_gateways() returns a 'cod'
 * gateway that is enabled and has a title (enough for Core::native_cod_enabled()
 * and the set_payment_method_title() lookup in do_finalize()). Real classes
 * (not stdClass) so the chained method calls resolve.
 */
final class Fake_WC_Gateway {
	public $enabled = 'yes';
	public function get_title() {
		return 'Cash on delivery';
	}
}
final class Fake_WC_Payment_Gateways {
	private $gateways;
	public function __construct( array $gateways ) {
		$this->gateways = $gateways;
	}
	public function payment_gateways() {
		return $this->gateways;
	}
}
final class Fake_WC {
	private $pg;
	public function __construct( $pg ) {
		$this->pg = $pg;
	}
	public function payment_gateways() {
		return $this->pg;
	}
}
function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalidName -- polyfill.
	static $wc;
	if ( null === $wc ) {
		$cod = new Fake_WC_Gateway();
		$pg  = new Fake_WC_Payment_Gateways( array( 'cod' => $cod ) );
		$wc  = new Fake_WC( $pg );
	}
	return $wc;
}

// ------------------------------------------------------------------ fake order.

/**
 * Lightweight stand-in for WC_Order implementing exactly the surface the
 * lifecycle code under test touches.
 */
final class Fake_Order {

	public $id = 1;
	private $meta              = array();
	private $total             = 0.0;
	private $payment_method    = '';
	private $payment_method_title = '';
	private $transaction_id    = '';
	private $status            = 'pending';
	public $notes              = array();

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $t ) {
		$this->total = (float) $t;
	}
	public function get_payment_method() {
		return $this->payment_method;
	}
	public function set_payment_method( $m ) {
		$this->payment_method = $m;
	}
	public function get_payment_method_title() {
		return $this->payment_method_title;
	}
	public function set_payment_method_title( $t ) {
		$this->payment_method_title = $t;
	}
	public function get_transaction_id() {
		return $this->transaction_id;
	}
	public function set_transaction_id( $t ) {
		$this->transaction_id = $t;
	}
	public function get_status() {
		return $this->status;
	}
	public function has_status( $statuses ) {
		return in_array( $this->status, (array) $statuses, true );
	}
	public function update_status( $status, $note = '' ) {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
	public function save() {
		return $this->id;
	}
}

// ------------------------------------------------------ fake order registry.

$GLOBALS['_cod_orders'] = array();

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order ) {
		if ( $order instanceof Fake_Order ) {
			return $order;
		}
		return isset( $GLOBALS['_cod_orders'][ $order ] ) ? $GLOBALS['_cod_orders'][ $order ] : false;
	}
}

function register_fake_order( Fake_Order $order ) {
	$GLOBALS['_cod_orders'][ $order->id ] = $order;
	return $order;
}

// --------------------------------------------------- Core singleton (no ctor).
// Core::__construct() instantiates the six runtime subsystems (which only
// register hooks) — we bypass it via reflection so this harness depends on
// nothing but the option/WC polyfills above.
$core_refl = new ReflectionClass( 'CodShield\\Core' );
$core_inst = $core_refl->newInstanceWithoutConstructor();
// ReflectionProperty::setAccessible() is a no-op since PHP 8.1 (deprecated as
// of 8.5) — private static properties are reachable via reflection directly.
$inst_prop = $core_refl->getProperty( 'instance' );
$inst_prop->setValue( null, $core_inst );

// A Gateway_Bridge with no constructor (skip hook registration).
$bridge_refl = new ReflectionClass( 'CodShield\\Gateway_Bridge' );
$bridge      = $bridge_refl->newInstanceWithoutConstructor();

// ------------------------------------------------------------- test helpers.

$GLOBALS['tests']    = 0;
$GLOBALS['failures'] = 0;

/**
 * @param bool   $ok     Assertion result.
 * @param string $label  Test label.
 */
function check( $ok, $label ) {
	printf( "[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label );
	$GLOBALS['tests'] ++;
	if ( ! $ok ) {
		$GLOBALS['failures'] ++;
	}
}

/**
 * Build a pre-finalize advance order.
 *
 * @param array $overrides Property/meta overrides.
 * @return Fake_Order
 */
function make_advance_order( array $overrides = array() ) {
	$o = new Fake_Order();
	$o->set_total( 20.0 );                                 // swapped to the advance
	$o->update_meta_data( Core::META_ADVANCE, 20.0 );
	$o->update_meta_data( Core::META_ORIGINAL_TOTAL, 100.0 );
	$o->update_meta_data( Core::META_REMAINING, 80.0 );
	$o->update_meta_data( Core::META_STATUS, 'pending' );
	$o->update_meta_data( Core::META_AWAITING, 'yes' );
	$o->update_meta_data( Core::META_GATEWAY_USED, 'stub' );
	$o->set_payment_method( 'stub' );
	$o->set_transaction_id( '' );
	foreach ( $overrides as $k => $v ) {
		if ( in_array( $k, array( 'total', 'method', 'method_title', 'txn', 'status' ), true ) ) {
			switch ( $k ) {
				case 'total':
					$o->set_total( $v );
					break;
				case 'method':
					$o->set_payment_method( $v );
					break;
				case 'method_title':
					$o->set_payment_method_title( $v );
					break;
				case 'txn':
					$o->set_transaction_id( $v );
					break;
				case 'status':
					$o->update_status( $v );
					break;
			}
			continue;
		}
		$o->update_meta_data( $k, $v );
	}
	return register_fake_order( $o );
}

// ================================================================ TESTS.

// 1. Transaction-gating: no gateway txn recorded -> finalization is a no-op.
$o1 = make_advance_order();
$bridge->finalize_advance_order( $o1->id );
check( 20.0 === (float) $o1->get_total(), '1. no-txn: total untouched (still advance)' );
check( 'yes' === $o1->get_meta( Core::META_AWAITING ), '1. no-txn: still awaiting' );
check( 'pending' === $o1->get_meta( Core::META_STATUS ), '1. no-txn: not marked paid' );

// 2. Normal finalization: txn present -> full total restored, COD, paid.
$o2 = make_advance_order( array( 'txn' => 'txn-1' ) );
$bridge->finalize_advance_order( $o2->id );
check( 100.0 === (float) $o2->get_total(), '2. finalize: full total restored (100)' );
check( 'cod' === $o2->get_payment_method(), '2. finalize: converted to COD' );
check( 'paid' === $o2->get_meta( Core::META_STATUS ), '2. finalize: status = paid' );
check( 'no' === $o2->get_meta( Core::META_AWAITING ), '2. finalize: awaiting cleared' );
check( 80.0 === (float) $o2->get_meta( Core::META_REMAINING ), '2. finalize: remaining = 80' );
check( 'txn-1' === $o2->get_meta( Core::META_TXN ), '2. finalize: txn recorded' );
check( 'processing' === $o2->get_status(), '2. finalize: order status = processing (default)' );

// 3. Overcharge guard: gateway charged MORE than the advance -> NOT converted.
$o3 = make_advance_order( array( 'total' => 150.0, 'txn' => 'txn-2' ) ); // gateway charged 150 of a 100 order.
$bridge->finalize_advance_order( $o3->id );
check( 100.0 === (float) $o3->get_total(), '3. overcharge: total restored to original (100)' );
check( 'stub' === $o3->get_payment_method(), '3. overcharge: NOT converted to COD (method retained)' );
check( 'paid' === $o3->get_meta( Core::META_STATUS ), '3. overcharge: advance marked paid' );
check( 0.0 === (float) $o3->get_meta( Core::META_REMAINING ), '3. overcharge: remaining reduced to 0 (100-150)' );
check( false !== strpos( implode( ' ', $o3->notes ), 'WARNING' ), '3. overcharge: WARNING note recorded' );

// 4. Fulfilment guard: unpaid advance moved to processing -> reverted to pending.
$o4 = make_advance_order( array( 'status' => 'processing' ) ); // advance unpaid, no txn.
$bridge->revert_unpaid_advance_status( $o4->id, 'pending', 'processing', $o4 );
check( 'pending' === $o4->get_status(), '4. revert: unpaid advance order rolled back to pending' );
check( count( $o4->notes ) >= 1, '4. revert: explanatory note added' );

// 5. Manual mark after a failed attempt (failure path restored proxy + full total).
$o5 = make_advance_order(
	array(
		'total'  => 100.0,                   // failure path restored the full COD total
		'method' => Core::GATEWAY_ID,        // reverted to the proxy
	)
);
$bridge->manual_mark_advance_paid( $o5->id );
check( 'cod' === $o5->get_payment_method(), '5. manual: converted to COD' );
check( 100.0 === (float) $o5->get_total(), '5. manual: full total preserved' );
check( 'paid' === $o5->get_meta( Core::META_STATUS ), '5. manual: status = paid' );
check( 'no' === $o5->get_meta( Core::META_AWAITING ), '5. manual: awaiting cleared' );
check( 'processing' === $o5->get_status(), '5. manual: order status = processing' );
check( false !== strpos( implode( ' ', $o5->notes ), 'manually marked' ), '5. manual: manual note recorded' );

// 6. Idempotency: a second finalization is a no-op (no duplicate note).
$o6 = make_advance_order( array( 'txn' => 'txn-3' ) );
$bridge->finalize_advance_order( $o6->id );
$notes_after_first = count( $o6->notes );
$bridge->finalize_advance_order( $o6->id ); // awaiting is now 'no' -> returns early.
check( count( $o6->notes ) === $notes_after_first, '6. idempotent: second finalize adds no note' );

// 7. Gateway identity: a transaction on another method cannot finalize.
$o7 = make_advance_order(
	array(
		'method' => 'different_gateway',
		'txn'    => 'txn-wrong-method',
	)
);
$bridge->finalize_advance_order( $o7->id );
check( 20.0 === (float) $o7->get_total(), '7. gateway identity: total untouched' );
check( 'yes' === $o7->get_meta( Core::META_AWAITING ), '7. gateway identity: still awaiting' );
check( 'pending' === $o7->get_meta( Core::META_STATUS ), '7. gateway identity: not marked paid' );

// ---------------------------------------------------------------- summary.
echo "\n" . sprintf( '%d tests, %d failures', $GLOBALS['tests'], $GLOBALS['failures'] ) . "\n";
exit( $GLOBALS['failures'] > 0 ? 1 : 0 );
