<?php
/** Generic proxy delegation regressions, without WordPress. */
require __DIR__ . '/run-order-editor.php';
function wc_add_notice( $message, $type ) {}
class WC_Payment_Gateway {
 public function get_return_url( $order ) { return '/received'; }
}
class Proxy_Order extends WC_Order {
 public function set_total( $value ) { $this->total = $value; }
 public function get_items( $type ) { return array(); }
}
class Proxy_Core extends CodShield\Core {
 public $gateway;
 public $requested;
 public function __construct() {}
 public function cod_enabled() { return false; }
 public static function calculate_advance( $total, $settings = null ) { throw new RuntimeException( 'Retry must not recalculate' ); }
 public function delegation_gateway( $saved_id = '' ) { $this->requested = $saved_id; return $this->gateway; }
 public function get_option( $key, $fallback = null ) { return $fallback; }
 public function get_cod_title( $fallback = '' ) { return 'COD'; }
}
class Proxy_Delegate {
 public $id = 'saved_gateway';
 public $mode = 'failure';
 public $calls = 0;
 public function get_title() { return 'Saved gateway'; }
 public function process_payment( $id ) {
  $this->calls++;
  $order = $GLOBALS['order'];
  check( $order->total === 80.0, 'Saved required advance used' );
  if ( $this->mode === 'throw' ) { throw new Error( 'Network uncertain' ); }
  if ( $this->mode === 'captured' ) { $order->txn = 'captured'; return array( 'result' => 'failure' ); }
  if ( $this->mode === 'paid_throw' ) {
   $fresh = clone $order;
   $fresh->meta[CodShield\Core::META_STATUS] = 'paid';
   $fresh->meta[CodShield\Core::META_RECEIVED] = 80;
   $fresh->meta[CodShield\Core::META_AWAITING] = 'no';
   $fresh->meta[CodShield\Core::META_ATTEMPT_STARTED] = 'no';
   $fresh->total = 500;
   $fresh->method = 'cod';
   $GLOBALS['order'] = $fresh;
   throw new Error( 'Response lost after callback' );
  }
  return array( 'result' => $this->mode );
 }
}
require __DIR__ . '/../includes/class-cod-shield-gateway-cod-advance.php';
$core = new Proxy_Core();
$core->gateway = new Proxy_Delegate();
(new ReflectionProperty( CodShield\Core::class, 'instance' ))->setValue( null, $core );
$proxy = (new ReflectionClass( CodShield\Gateway_COD_Advance::class ))->newInstanceWithoutConstructor();
foreach ( array( 'failure', 'success', 'throw', 'captured', 'paid_throw' ) as $mode ) {
 $order = new Proxy_Order();
 $order->meta = array( CodShield\Core::META_ADVANCE => 80, CodShield\Core::META_GATEWAY_USED => 'saved_gateway', CodShield\Core::META_VALUE => 80, CodShield\Core::META_ATTEMPT_STARTED => 'no', CodShield\Core::META_AWAITING => 'no' );
 $GLOBALS['order'] = $order;
 $core->gateway->mode = $mode;
 $result = $proxy->process_payment( 1 );
 $order = $GLOBALS['order'];
 check( $core->requested === 'saved_gateway', 'Retry uses saved gateway even when settings disabled' );
 if ( $mode === 'failure' ) {
  check( $order->total === 500.0 && $order->meta[CodShield\Core::META_ATTEMPT_STARTED] === 'no', 'Explicit failure restores retryable full order' );
 } elseif ( $mode === 'paid_throw' ) {
  check( $result['result'] === 'success' && $order->meta[CodShield\Core::META_STATUS] === 'paid' && $order->method === 'cod' && $order->total === 500, 'Reload preserves synchronous successful callback after throw' );
 } else {
  check( $order->total === 80.0 && $order->meta[CodShield\Core::META_ATTEMPT_STARTED] === 'yes', 'Attempt remains locked at advance total' );
  $before = $core->gateway->calls;
  $proxy->process_payment( 1 );
  check( $core->gateway->calls === $before, 'Duplicate delegation blocked' );
 }
}
echo "Proxy delegation regressions: " . ( $GLOBALS['checks'] - 25 ) . " assertions passed.\n";
