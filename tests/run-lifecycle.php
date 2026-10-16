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
require_once __DIR__ . '/../includes/class-cod-shield-order-meta.php';
require_once __DIR__ . '/../includes/class-cod-shield-order-ui.php';

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

/** Capture the operational logging boundary without a WooCommerce install. */
$GLOBALS['_cod_logs'] = array();
final class Fake_Logger {
	public function warning( $message, $context ) {
		$GLOBALS['_cod_logs'][] = array( 'message' => $message, 'context' => $context );
	}
}
function wc_get_logger() {
	return new Fake_Logger();
}

/** Minimal hook dispatch for save-driven reconciliation coverage. */
$GLOBALS['_cod_actions'] = array();
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['_cod_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$callbacks = $GLOBALS['_cod_actions'][ $hook ] ?? array();
		ksort( $callbacks );
		foreach ( $callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as list( $callback, $accepted_args ) ) {
				$callback( ...array_slice( $args, 0, $accepted_args ) );
			}
		}
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

/** Scheduler boundaries; run with --wp-cron to exercise absent Action Scheduler. */
$GLOBALS['_cod_scheduled'] = array();
$GLOBALS['_cod_cron'] = array();
$GLOBALS['_cod_scheduler_ready'] = true;
$GLOBALS['_cod_scheduler_fail'] = false;
function did_action( $hook ) {
	return 'action_scheduler_init' === $hook && $GLOBALS['_cod_scheduler_ready'] ? 1 : 0;
}
if ( ! in_array( '--wp-cron', $argv, true ) ) {
	function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
		return isset( $GLOBALS['_cod_scheduled'][ serialize( array( $hook, $args, $group ) ) ] );
	}
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false ) {
		$key = serialize( array( $hook, $args, $group ) );
		if ( $GLOBALS['_cod_scheduler_fail'] || ( $unique && isset( $GLOBALS['_cod_scheduled'][ $key ] ) ) ) {
			return 0;
		}
		$GLOBALS['_cod_scheduled'][ $key ] = array( $hook, $args, $group, $unique );
		return count( $GLOBALS['_cod_scheduled'] );
	}
}
function wp_next_scheduled( $hook, $args = array() ) {
	return isset( $GLOBALS['_cod_cron'][ serialize( array( $hook, $args ) ) ] ) ? time() : false;
}
function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
	$GLOBALS['_cod_cron'][ serialize( array( $hook, $args ) ) ] = array( $hook, $args );
	return true;
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

/** Authentication boundaries: the real release service still owns all checks. */
$GLOBALS['_cod_can_edit_order'] = true;
function current_user_can( $capability, $order_id ) {
	return $GLOBALS['_cod_can_edit_order'] && 'edit_shop_order' === $capability && $order_id > 0;
}
function wp_verify_nonce( $nonce, $action ) {
	return $nonce === 'valid:' . $action ? 1 : false;
}
function get_current_user_id() {
	return 44;
}
function esc_js( $value ) {
	return addslashes( $value );
}
function esc_url( $value ) {
	return $value;
}
function admin_url( $path ) {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_nonce_field( $action, $name, $referer = true ) {
	echo '<input name="' . htmlspecialchars( $name, ENT_QUOTES ) . '" value="valid:' . htmlspecialchars( $action, ENT_QUOTES ) . '" />';
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
	public $saves              = 0;

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key, $single = true ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function delete_meta_data( $key ) {
		unset( $this->meta[ $key ] );
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
		++$this->saves;
		do_action( 'woocommerce_after_order_object_save', $this, null );
		return $this->id;
	}
}

class_alias( Fake_Order::class, 'WC_Order' );

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

// Outcome evaluation must expose the gate that failed without saving or noting.
$outcome_cases = array(
	array( array( Core::META_AWAITING => 'no' ), 'not_awaiting' ),
	array( array(), 'missing_transaction' ),
	array( array( 'txn' => '0' ), 'missing_transaction' ),
	array( array( 'txn' => '  ' ), 'missing_transaction' ),
	array( array( 'txn' => 'private-payment-token', 'method' => 'different_gateway' ), 'gateway_mismatch' ),
	array( array( 'txn' => 'txn-valid' ), 'ready' ),
	array( array( 'txn' => 'txn-legacy', Core::META_GATEWAY_USED => '' ), 'ready' ),
	array( array( 'txn' => 'txn-invalid', 'total' => 0, Core::META_ORIGINAL_TOTAL => 0, Core::META_ADVANCE => 0, Core::META_REMAINING => 0 ), 'invalid_total' ),
);
foreach ( $outcome_cases as list( $overrides, $expected ) ) {
	$candidate = make_advance_order( $overrides );
	$before = serialize( $candidate );
	check( $expected === $bridge->evaluate_finalization_outcome( $candidate ), 'outcome: ' . $expected );
	check( $before === serialize( $candidate ), 'outcome: evaluation is side-effect free for ' . $expected );
}

// 1. Transaction-gating: no gateway txn recorded -> finalization is a no-op.
$o1 = make_advance_order();
$bridge->finalize_advance_order( $o1->id );
check( 20.0 === (float) $o1->get_total(), '1. no-txn: total untouched (still advance)' );
check( 'yes' === $o1->get_meta( Core::META_AWAITING ), '1. no-txn: still awaiting' );
check( 'pending' === $o1->get_meta( Core::META_STATUS ), '1. no-txn: not marked paid' );
check( 'missing_transaction' === $o1->get_meta( '_cod_shield_finalization_diagnostic' ), '1. no-txn: bounded diagnostic stored' );
check( 1 === count( $o1->notes ), '1. no-txn: actionable diagnostic note added' );
$logs_after_missing = count( $GLOBALS['_cod_logs'] );
$saves_after_missing = $o1->saves;
$bridge->finalize_advance_order( $o1->id );
check( 1 === count( $o1->notes ), '1. no-txn: repeat rejection adds no note' );
check( $logs_after_missing === count( $GLOBALS['_cod_logs'] ), '1. no-txn: repeat rejection adds no log' );
check( $saves_after_missing === $o1->saves, '1. no-txn: repeat rejection does not save' );
$o1->set_transaction_id( 'private-payment-token' );
$o1->set_payment_method( 'different_gateway' );
$bridge->finalize_advance_order( $o1->id );
check( 2 === count( $o1->notes ), '1. changed rejection: new diagnostic note added' );
check( $logs_after_missing + 1 === count( $GLOBALS['_cod_logs'] ), '1. changed rejection: new diagnostic logged' );
check( false === strpos( serialize( $GLOBALS['_cod_logs'] ), 'private-payment-token' ), '1. privacy: logs omit transaction IDs' );
check( false === strpos( implode( ' ', $o1->notes ), 'private-payment-token' ), '1. privacy: rejection notes omit transaction IDs' );
$last_log = end( $GLOBALS['_cod_logs'] );
check(
	array(
		'source' => 'cod-shield',
		'order_id' => 1,
		'code' => 'gateway_mismatch',
		'gateway_used' => 'stub',
		'payment_method' => 'different_gateway',
		'status' => 'pending',
	) === ( $last_log['context'] ?? array() ),
	'1. privacy: log context contains only operational identifiers'
);

// 2. Normal finalization: txn present -> full total restored, COD, paid.
$o2 = make_advance_order( array( 'txn' => 'txn-1' ) );
$o2->update_meta_data( '_cod_shield_finalization_diagnostic', 'missing_transaction' );
$bridge->finalize_advance_order( $o2->id );
check( 100.0 === (float) $o2->get_total(), '2. finalize: full total restored (100)' );
check( 'cod' === $o2->get_payment_method(), '2. finalize: converted to COD' );
check( 'paid' === $o2->get_meta( Core::META_STATUS ), '2. finalize: status = paid' );
check( 'no' === $o2->get_meta( Core::META_AWAITING ), '2. finalize: awaiting cleared' );
check( 80.0 === (float) $o2->get_meta( Core::META_REMAINING ), '2. finalize: remaining = 80' );
check( 'txn-1' === $o2->get_meta( Core::META_TXN ), '2. finalize: txn recorded' );
check( 'processing' === $o2->get_status(), '2. finalize: order status = processing (default)' );
check( '' === $o2->get_meta( '_cod_shield_finalization_diagnostic' ), '2. finalize: stale rejection diagnostic cleared' );

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
check( 'gateway_mismatch' === $o7->get_meta( '_cod_shield_finalization_diagnostic' ), '7. gateway identity: mismatch diagnostic stored' );
$logs_after_mismatch = count( $GLOBALS['_cod_logs'] );
$bridge->finalize_advance_order( $o7->id );
check( 1 === count( $o7->notes ) && $logs_after_mismatch === count( $GLOBALS['_cod_logs'] ), '7. gateway identity: repeated mismatch diagnostic suppressed' );

$not_awaiting = make_advance_order( array( Core::META_AWAITING => 'no', 'txn' => 'txn-unrelated' ) );
$before_not_awaiting = serialize( $not_awaiting );
$bridge->finalize_advance_order( $not_awaiting->id );
check( $before_not_awaiting === serialize( $not_awaiting ), '8. not awaiting: order is untouched' );
check( $logs_after_mismatch === count( $GLOBALS['_cod_logs'] ), '8. not awaiting: no operational log noise' );

// 9. Preserve the original falsey transaction guard: "0" is not payment evidence.
$zero_transaction = make_advance_order( array( 'txn' => '0' ) );
$bridge->finalize_advance_order( $zero_transaction->id );
check( 20.0 === (float) $zero_transaction->get_total(), '9. zero transaction: advance total retained' );
check( 'stub' === $zero_transaction->get_payment_method(), '9. zero transaction: not converted to COD' );
check( 'pending' === $zero_transaction->get_meta( Core::META_STATUS ), '9. zero transaction: not marked paid' );
check( 'yes' === $zero_transaction->get_meta( Core::META_AWAITING ), '9. zero transaction: still awaiting' );
check( 'missing_transaction' === $zero_transaction->get_meta( '_cod_shield_finalization_diagnostic' ), '9. zero transaction: missing transaction diagnostic stored' );

// 10. A transaction saved after the last status event must finalize asynchronously.
$hooked_bridge = new Gateway_Bridge();
$late = make_advance_order( array( 'status' => 'processing' ) );
$bridge->finalize_advance_order( $late->id );
$bridge->revert_unpaid_advance_status( $late->id, 'pending', 'processing', $late );
$late->save();
check( 0 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '10. late save: no work queued without transaction evidence' );
$late->set_transaction_id( 'txn-late-save' );
$late->save();
$late->save();
check( 'pending' === $late->get_status() && 'pending' === $late->get_meta( Core::META_STATUS ), '10. late save: observer does not finalize inside save' );
check( 1 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '10. late save: duplicate saves queue one reconciliation' );
if ( function_exists( 'as_enqueue_async_action' ) ) {
	$queued = reset( $GLOBALS['_cod_scheduled'] );
	check( $queued && array( 1 ) === $queued[1] && 'cod-shield' === $queued[2] && true === $queued[3], '10. late save: Action Scheduler dispatch is unique and scoped to one order' );
} else {
	check( 1 === count( $GLOBALS['_cod_cron'] ), '10. late save: WP-Cron fallback is used when Action Scheduler is absent' );
}
$GLOBALS['_cod_scheduled'] = array();
$GLOBALS['_cod_cron'] = array();
do_action( 'cod_shield_reconcile_advance_order', $late->id );
check( 'paid' === $late->get_meta( Core::META_STATUS ) && 'no' === $late->get_meta( Core::META_AWAITING ), '10. late save: scheduled callback finalizes without another gateway status event' );
check( 100.0 === $late->get_total() && 'cod' === $late->get_payment_method(), '10. late save: full total and COD restored' );
$after_catchup = serialize( $late );
do_action( 'cod_shield_reconcile_advance_order', $late->id );
check( $after_catchup === serialize( $late ), '10. late save: duplicate scheduled callbacks do not finalize twice' );
check( 0 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '10. late save: finalizer saves do not enqueue more work' );

// Reload at execution: payment evidence may have changed since the enqueue.
$changed = make_advance_order( array( 'txn' => 'txn-before-queue' ) );
$changed->save();
$fresh = clone $changed;
$fresh->set_transaction_id( '' );
register_fake_order( $fresh );
$GLOBALS['_cod_scheduled'] = array();
$GLOBALS['_cod_cron'] = array();
do_action( 'cod_shield_reconcile_advance_order', $changed->id );
check( 'pending' === $fresh->get_meta( Core::META_STATUS ) && 'yes' === $fresh->get_meta( Core::META_AWAITING ), '11. reload: callback rechecks current payment evidence' );
check( 'missing_transaction' === $fresh->get_meta( Core::META_FINALIZATION_DIAGNOSTIC ), '11. reload: rejection uses the shared outcome evaluator' );

// A rejection saves diagnostic metadata while still awaiting: no recursive queue.
$rejected = make_advance_order( array( 'txn' => 'txn-wrong-gateway', 'method' => 'different_gateway' ) );
$rejected->save();
$GLOBALS['_cod_scheduled'] = array();
$GLOBALS['_cod_cron'] = array();
$reentered = false;
add_action(
	'woocommerce_after_order_object_save',
	static function ( $order ) use ( &$reentered ) {
		if ( ! $reentered ) {
			$reentered = true;
			do_action( 'cod_shield_reconcile_advance_order', $order->get_id() );
		}
	},
	20
);
do_action( 'cod_shield_reconcile_advance_order', $rejected->id );
check( 'gateway_mismatch' === $rejected->get_meta( Core::META_FINALIZATION_DIAGNOSTIC ) && 1 === count( $rejected->notes ), '12. recursion: diagnostic save and reentrant callback record one rejection' );
check( 0 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '12. recursion: diagnostic save does not schedule a loop' );
unset( $GLOBALS['_cod_actions']['woocommerce_after_order_object_save'][20] );

// Review orders are never automatically reconciled merely because they have a txn.
$review = make_advance_order( array( 'txn' => 'txn-review', Core::META_AWAITING => 'review' ) );
$review->save();
check( 0 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '13. review: save observer skips reconciliation-review orders' );

// Uninitialized or failing Action Scheduler falls back without duplicating jobs.
$GLOBALS['_cod_scheduler_ready'] = false;
$fallback = make_advance_order( array( 'txn' => 'txn-before-init' ) );
$fallback->save();
$GLOBALS['_cod_scheduler_ready'] = true;
$fallback->save();
check( 1 === count( $GLOBALS['_cod_cron'] ) && 0 === count( $GLOBALS['_cod_scheduled'] ), '14. fallback: an existing cron event prevents duplicate dispatch after scheduler init' );
$GLOBALS['_cod_cron'] = array();
$GLOBALS['_cod_scheduler_fail'] = true;
$fallback->save();
$fallback->save();
check( 1 === count( $GLOBALS['_cod_cron'] ), '14. fallback: enqueue failure uses one WP-Cron event' );
$GLOBALS['_cod_scheduler_fail'] = false;
$GLOBALS['_cod_cron'] = array();
do_action( 'cod_shield_reconcile_advance_order', $fallback->id );
check( 'paid' === $fallback->get_meta( Core::META_STATUS ), '14. fallback: WP-Cron callback finalizes with the same evidence gates' );
do_action( 'cod_shield_reconcile_advance_order', 999999 );
check( 0 === count( $GLOBALS['_cod_scheduled'] ) + count( $GLOBALS['_cod_cron'] ), '14. fallback: missing order callback safely does nothing' );

// 15. Stale recovery is explicit, protected, and never overrides payment evidence.
$stale_meta = array(
	Core::META_ATTEMPT_STARTED => 'yes',
	Core::META_ATTEMPT_STARTED_AT => time() - 3601,
);
$release_cases = array(
	'active' => array( Core::META_ATTEMPT_STARTED_AT => time() ),
	'future timestamp' => array( Core::META_ATTEMPT_STARTED_AT => time() + 3600 ),
	'legacy' => array( Core::META_ATTEMPT_STARTED_AT => '' ),
	'invalid timestamp' => array( Core::META_ATTEMPT_STARTED_AT => 'bad' ),
	'transaction' => array( 'txn' => 'txn-present' ),
	'falsey transaction' => array( 'txn' => '0' ),
	'recorded transaction' => array( Core::META_TXN => 'txn-recorded' ),
	'review' => array( Core::META_AWAITING => 'review' ),
	'review advance' => array( Core::META_STATUS => 'review' ),
	'paid' => array( Core::META_STATUS => 'paid' ),
	'unknown advance state' => array( Core::META_STATUS => 'unknown' ),
	'cancelled' => array( 'status' => 'cancelled' ),
	'invalid original total' => array( Core::META_ORIGINAL_TOTAL => 0 ),
	'no advance' => array( Core::META_ADVANCE => 0 ),
	'array original total' => array( Core::META_ORIGINAL_TOTAL => array( '100' ) ),
	'array advance' => array( Core::META_ADVANCE => array( '25' ) ),
	'non-numeric advance' => array( Core::META_ADVANCE => 'corrupt' ),
	'already released' => array( Core::META_ATTEMPT_STARTED => 'no' ),
);
foreach ( $release_cases as $label => $overrides ) {
	$protected = make_advance_order( array_replace( $stale_meta, $overrides ) );
	$before_release = serialize( $protected );
	$nonce = 'valid:' . Core::NONCE_RELEASE_ATTEMPT . '_' . $protected->id;
	check( ! $bridge->release_stale_advance( $protected->id, $nonce ), '15. release: refuses ' . $label );
	check( $before_release === serialize( $protected ), '15. release: preserves ' . $label . ' without mutation' );
}

$recoverable = make_advance_order( $stale_meta );
$before_release = serialize( $recoverable );
$nonce = 'valid:' . Core::NONCE_RELEASE_ATTEMPT . '_' . $recoverable->id;
$GLOBALS['_cod_can_edit_order'] = false;
check( ! $bridge->release_stale_advance( $recoverable->id, $nonce ), '16. release: capability denial' );
$GLOBALS['_cod_can_edit_order'] = true;
check( ! $bridge->release_stale_advance( $recoverable->id, 'invalid' ), '16. release: invalid nonce denial' );
check( ! $bridge->release_stale_advance( $recoverable->id, 'valid:' . Core::NONCE_RELEASE_ATTEMPT . '_999' ), '16. release: another order nonce denial' );
check( $before_release === serialize( $recoverable ), '16. release: authorization denial has no side effects' );

check( $bridge->release_stale_advance( $recoverable->id, $nonce ), '17. release: stale transaction-free attempt released' );
check( 'yes' === $recoverable->get_meta( Core::META_AWAITING ) && 'pending' === $recoverable->get_meta( Core::META_STATUS ), '17. release: awaiting pending obligation preserved' );
check( 'no' === $recoverable->get_meta( Core::META_ATTEMPT_STARTED ) && '' === $recoverable->get_meta( Core::META_ATTEMPT_STARTED_AT ), '17. release: attempt lock and timestamp reset' );
check( 100.0 === $recoverable->get_total() && Core::GATEWAY_ID === $recoverable->get_payment_method(), '17. release: full order total and proxy restored' );
check( $core_inst->order_advance_outstanding( $recoverable ), '17. release: order-pay advance obligation survives' );
check( ! Core::payment_attempt_is_in_flight( $recoverable->get_meta( Core::META_AWAITING ), $recoverable->get_meta( Core::META_ATTEMPT_STARTED ), $recoverable->get_transaction_id(), $recoverable->get_meta( Core::META_GATEWAY_USED ) ), '17. release: next proxy attempt is eligible' );
check( 1 === count( $recoverable->notes ) && false !== strpos( $recoverable->notes[0], 'released for retry by user #44' ), '17. release: attributable audit note recorded' );
$after_release = serialize( $recoverable );
check( ! $bridge->release_stale_advance( $recoverable->id, $nonce ) && $after_release === serialize( $recoverable ), '17. release: repeated action is idempotent' );

// 18. Exercise the actual metabox renderer, including release button gating.
$order_ui = ( new ReflectionClass( CodShield\Order_UI::class ) )->newInstanceWithoutConstructor();
$ui_cases = array(
	'stale' => array( array(), true ),
	'active' => array( array( Core::META_ATTEMPT_STARTED_AT => time() ), false ),
	'legacy' => array( array( Core::META_ATTEMPT_STARTED_AT => '' ), false ),
	'captured' => array( array( 'txn' => 'txn-ui' ), false ),
	'review' => array( array( Core::META_AWAITING => 'review' ), false ),
	'unknown advance state' => array( array( Core::META_STATUS => 'unknown' ), false ),
);
foreach ( $ui_cases as $label => list( $overrides, $show_release ) ) {
	$ui_order = make_advance_order( array_replace( $stale_meta, $overrides ) );
	ob_start();
	$order_ui->render_metabox( $ui_order );
	$html = ob_get_clean();
	check( $show_release === str_contains( $html, 'value="' . Core::NONCE_RELEASE_ATTEMPT . '"' ), '18. metabox: release button gating for ' . $label );
	if ( $show_release ) {
		check( str_contains( $html, 'name="cod_shield_release_nonce"' ) && str_contains( $html, 'valid:' . Core::NONCE_RELEASE_ATTEMPT . '_' . $ui_order->id ), '18. metabox: order-specific release nonce rendered' );
	}
}
$GLOBALS['_cod_can_edit_order'] = false;
ob_start();
$order_ui->render_metabox( make_advance_order( $stale_meta ) );
$html = ob_get_clean();
check( ! str_contains( $html, 'value="' . Core::NONCE_RELEASE_ATTEMPT . '"' ), '18. metabox: release button hidden without capability' );
$GLOBALS['_cod_can_edit_order'] = true;

// ---------------------------------------------------------------- summary.
echo "\n" . sprintf( '%d tests, %d failures', $GLOBALS['tests'], $GLOBALS['failures'] ) . "\n";
exit( $GLOBALS['failures'] > 0 ? 1 : 0 );
