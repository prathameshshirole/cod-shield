<?php
/** Standalone regression coverage for audited bookkeeping corrections. */
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = '' ) { return $text; }
function current_user_can( ...$args ) { return $GLOBALS['allowed']; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wc_get_price_decimals() { return 2; }
function wc_price( $value, $args = array() ) { return (string) $value; }
function get_current_user_id() { return 7; }
function wc_get_order( $id ) { return $GLOBALS['order']; }
class WC_Order {
 public $meta = array(); public $total = 500; public $status = 'pending'; public $method = 'cod_shield'; public $txn = ''; public $notes = array(); public $changes = array();
 public function get_id() { return 1; }
 public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
 public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
 public function get_total() { return $this->total; }
 public function get_currency() { return 'INR'; }
 public function get_status() { return $this->status; }
 public function get_changes() { return $this->changes; }
 public function get_payment_method() { return $this->method; }
 public function get_transaction_id() { return $this->txn; }
 public function has_status( $statuses ) { return in_array( $this->status, (array) $statuses, true ); }
 public function set_payment_method( $value ) { $this->method = $value; }
 public function set_payment_method_title( $value ) {}
 public function save() {}
 public function set_status( $value ) { $this->status = $value; }
 public function update_status( $value ) { $this->status = $value; }
 public function add_order_note( $value ) { $this->notes[] = $value; }
}
require_once __DIR__ . '/../includes/class-cod-shield-core.php';
require_once __DIR__ . '/../includes/class-cod-shield-order-meta.php';
require_once __DIR__ . '/../includes/class-cod-shield-order-editor.php';
use CodShield\Core;
use CodShield\Order_Editor;
use CodShield\Order_Meta;
$editor = (new ReflectionClass( Order_Editor::class ))->newInstanceWithoutConstructor();
$GLOBALS['allowed'] = true;
function fixture() {
 $GLOBALS['order'] = new WC_Order();
 $GLOBALS['order']->meta = array( Core::META_ADVANCE => 100, Core::META_ORIGINAL_TOTAL => 500, Core::META_STATUS => 'pending', Core::META_ATTEMPT_STARTED => 'no' );
 return $GLOBALS['order'];
}
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } $GLOBALS['checks']++; }
function correct( $amount, $kind = 'received', $extra = array(), $nonce = 'valid' ) {
 global $editor, $order;
 return $editor->correct( 1, $nonce, array_merge( array( 'revision' => Order_Editor::revision( $order ), 'kind' => $kind, 'amount' => $amount, 'reason' => 'Verified receipt', 'reference' => 'bank-123' ), $extra ) );
}
$GLOBALS['checks'] = 0;
$order = fixture();
check( Order_Meta::from_order( $order )->remaining === 500.0, 'Unpaid balance must include required advance' );
$GLOBALS['allowed'] = false;
check( correct( 100 ) === 'denied', 'Unauthorized correction blocked' );
$GLOBALS['allowed'] = true;
check( correct( 100, 'received', array(), 'bad' ) === 'denied', 'Nonce enforced' );
check( correct( 100, 'received', array( 'revision' => 'old' ) ) === 'stale', 'Stale editor blocked' );
foreach ( array( -1, 501, 'x', 1.234 ) as $invalid ) { check( correct( $invalid ) === 'invalid', 'Invalid money rejected' ); }
check( correct( 100, 'received', array( 'reason' => '' ) ) === 'invalid', 'Reason mandatory' );
$order->meta[Core::META_ATTEMPT_STARTED] = 'yes';
check( correct( 100 ) === 'locked', 'Live attempt locked' );
$order->meta[Core::META_ATTEMPT_STARTED] = 'no';
check( correct( 80, 'required' ) === 'saved', 'Required advance editable before collection' );
$order->txn = 'gateway-immutable';
$order->meta[Core::META_TXN] = 'gateway-immutable';
check( correct( 80 ) === 'saved', 'Manual receipt saved' );
check( $order->txn === 'gateway-immutable' && $order->meta[Core::META_TXN] === 'gateway-immutable', 'Gateway evidence preserved' );
check( $order->meta[Core::META_MANUAL_REFERENCE] === 'bank-123', 'Separate reference saved' );
check( $order->meta[Core::META_LAST_CORRECTION]['user_id'] === 7 && count( $order->notes ) === 2, 'Audit attribution and notes' );
check( $order->meta[Core::META_REMAINING] === 420.0 && $order->meta[Core::META_STATUS] === 'paid', 'Receipt reduces balance' );
check( correct( 70, 'required' ) === 'locked', 'Cannot rewrite requirement after collection' );
check( correct( 20 ) === 'saved' && $order->status === 'on-hold', 'Underpayment held for review' );
check( Order_Meta::from_order( $order )->remaining === 480.0, 'Review balance actual' );
check( correct( 80 ) === 'saved', 'Manual review can be corrected again' );
$order->total = 60; $order->changes = array( 'total' => 60 );
$editor->revalidate_total( $order );
check( $order->meta[Core::META_STATUS] === 'review' && $order->status === 'on-hold', 'Order total below receipt triggers review' );
$legacy = Order_Meta::from_meta_array( array( Order_Meta::RIRCOD_IS_COD => 'yes', Order_Meta::RIRCOD_ADVANCE => 100, Order_Meta::RIRCOD_COD_AMOUNT => 400 ) );
check( $legacy->received === 100.0, 'Legacy received retained' );
$legacy = Order_Meta::from_meta_array( array( Core::META_ADVANCE => 100, Core::META_ORIGINAL_TOTAL => 500, Core::META_STATUS => 'paid', Core::META_AWAITING => 'review' ) );
check( $legacy->received === 100.0, 'Review does not lose legacy receipt' );
$legacy = Order_Meta::from_meta_array( array( Core::META_ADVANCE => 100, Core::META_STATUS => 'paid', Core::META_RECEIVED => 0 ) );
check( $legacy->received === 0.0, 'Explicit zero overrides paid fallback' );
$order = fixture();
$order->meta[Core::META_AWAITING] = 'yes';
$order->total = 600;
check( Order_Meta::from_order( $order )->total === 600.0, 'Unstarted awaiting order uses edited full total' );
echo 'Order editor: ' . $GLOBALS['checks'] . " assertions passed.\n";
