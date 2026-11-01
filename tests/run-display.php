<?php
/** Customer display regressions, without WordPress. */
define( 'ABSPATH', __DIR__ );
function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( $text ); }
function wp_kses_post( $text ) { return $text; }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wc_price( $amount, $args = array() ) { return ( $args['currency'] ?? 'INR' ) . ' ' . number_format( $amount, 2, '.', '' ); }
class WC_Order {
    public $meta = array();
    public $total = 20;
    public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
    public function get_total() { return $this->total; }
    public function get_currency() { return 'GBP'; }
}
require_once __DIR__ . '/../includes/class-cod-shield-core.php';
require_once __DIR__ . '/../includes/class-cod-shield-order-meta.php';
require_once __DIR__ . '/../includes/class-cod-shield-emails.php';
require_once __DIR__ . '/../includes/class-cod-shield-pdf-support.php';
use CodShield\Core;
use CodShield\Emails;
use CodShield\PDF_Support;
$checks = 0;
function display_assert( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( !$condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit(1); }
}
$order = new WC_Order();
$order->meta = array( Core::META_ADVANCE => 20, Core::META_ORIGINAL_TOTAL => 100, Core::META_STATUS => 'pending', Core::META_AWAITING => 'yes' );
$email = (new ReflectionClass( Emails::class ))->newInstanceWithoutConstructor();
$pdf = (new ReflectionClass( PDF_Support::class ))->newInstanceWithoutConstructor();
$rows = $pdf->add_advance_totals( array( 'order_total' => array('value' => '20') ), $order, 'invoice' );
display_assert( 'GBP 100.00' === $rows['order_total']['value'], 'Pending invoice shows full total.' );
display_assert( 'GBP 0.00' === $rows['cod_shield_received']['value'], 'Pending invoice never claims advance received.' );
display_assert( 'GBP 80.00' === $rows['cod_shield_expected']['value'], 'Pending expected delivery balance separately labeled.' );
ob_start(); $email->render_summary( $order, false, true ); $plain = ob_get_clean();
display_assert( str_contains($plain, 'Awaiting advance payment') && str_contains($plain, 'Payment received: GBP 0.00'), 'Pending email explicitly states pending and zero received.' );
$order->meta[Core::META_STATUS] = 'paid';
$order->meta[Core::META_AWAITING] = 'no';
$order->meta[Core::META_RECEIVED] = 30;
$order->total = 100;
$rows = $pdf->add_advance_totals( array(), $order, 'invoice' );
display_assert( 'GBP 30.00' === $rows['cod_shield_received']['value'] && 'GBP 70.00' === $rows['cod_shield_balance']['value'], 'Corrected received amount drives invoice balance, not required advance.' );
display_assert( !isset($rows['cod_shield_expected']), 'Confirmed payment has one delivery balance.' );
ob_start(); $email->render_summary( $order ); $html = ob_get_clean();
display_assert( str_contains($html, 'GBP 30.00') && str_contains($html, 'GBP 70.00'), 'Customer order page shows corrected money in order currency.' );
display_assert( array() === $pdf->add_advance_totals( array(), $order, 'credit-note' ), 'Credit notes remain unchanged.' );
$order->meta = array();
ob_start(); $email->render_summary( $order ); $html = ob_get_clean();
display_assert( '' === $html, 'Ordinary prepaid order has no partial COD summary.' );
echo "Customer displays: $checks checks passed.\n";
