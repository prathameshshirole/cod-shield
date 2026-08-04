<?php
/**
 * Standalone unit tests for the advance calculation engine
 * (CodShield\Core::calculate_advance()).
 *
 * The engine is a pure function, so these tests run without WordPress:
 *
 *   php tests/run-tests.php
 *
 * @package COD_Shield
 */

// Allow loading the class file outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}

require_once __DIR__ . '/../includes/class-cod-shield-core.php';

use CodShield\Core;

/**
 * @param float              $expected Expected advance.
 * @param float              $total    Order total.
 * @param array<string,mixed> $settings Settings array.
 * @param string             $label    Test label.
 */
function assert_advance( $expected, $total, array $settings, $label ) {
	$actual = Core::calculate_advance( $total, $settings );
	$pass   = abs( $actual - $expected ) < 0.0001;
	printf(
		"[%s] %-58s expected %-8s got %-8s\n",
		$pass ? 'PASS' : 'FAIL',
		$label,
		(string) $expected,
		(string) $actual
	);
	if ( ! $pass ) {
		$GLOBALS['failures']++;
	}
	$GLOBALS['tests']++;
}

$GLOBALS['tests']    = 0;
$GLOBALS['failures'] = 0;

$fixed    = array( 'mode' => 'fixed', 'fixed' => 99, 'percentage' => 10, 'min' => 49, 'max' => 999, 'threshold' => 0 );
$pct      = array( 'mode' => 'percentage', 'fixed' => 99, 'percentage' => 10, 'min' => 49, 'max' => 999, 'threshold' => 0 );
$pctRaw   = array( 'mode' => 'percentage', 'fixed' => 99, 'percentage' => 10, 'min' => 0, 'max' => 0, 'threshold' => 0 );
$off      = array( 'mode' => 'disabled', 'fixed' => 99, 'percentage' => 10, 'min' => 49, 'max' => 999, 'threshold' => 0 );

// Extra COD fee fixtures (features.md).
$feeFixed = array( 'fee_enabled' => 'yes', 'fee_type' => 'fixed', 'fee_amount' => 30, 'fee_percentage' => 2 );
$feePct   = array( 'fee_enabled' => 'yes', 'fee_type' => 'percentage', 'fee_amount' => 30, 'fee_percentage' => 2 );
$feeOff   = array( 'fee_enabled' => 'no', 'fee_type' => 'fixed', 'fee_amount' => 30, 'fee_percentage' => 2 );

// Mode / total guards.
assert_advance( 0, 1000, $off, 'disabled mode -> 0' );
assert_advance( 0, 0, $fixed, 'zero total -> 0' );
assert_advance( 0, -50, $fixed, 'negative total -> 0' );
assert_advance( 0, 1000, array_merge( $pctRaw, array( 'percentage' => 0 ) ), '0% percentage -> 0' );

// Threshold.
assert_advance( 0, 400, array_merge( $fixed, array( 'threshold' => 500 ) ), 'below threshold -> 0' );
assert_advance( 99, 500, array_merge( $fixed, array( 'threshold' => 500 ) ), 'at threshold -> applies' );
assert_advance( 99, 600, array_merge( $fixed, array( 'threshold' => 500 ) ), 'above threshold -> applies' );

// Fixed mode.
assert_advance( 99, 1000, $fixed, 'fixed amount exact' );
assert_advance( 100, 1000, array_merge( $fixed, array( 'min' => 100 ) ), 'fixed clamped up to min' );
assert_advance( 50, 1000, array_merge( $fixed, array( 'max' => 50 ) ), 'fixed clamped down to max' );

// Percentage mode.
assert_advance( 100, 1000, $pct, '10% of 1000' );
assert_advance( 30, 300, $pctRaw, '10% of 300 (no min)' );
assert_advance( 49, 300, $pct, '10% of 300 floored to min 49' );
assert_advance( 999, 20000, $pct, '10% of 20000 capped to max 999' );
assert_advance( 33.33, 100, array_merge( $pctRaw, array( 'percentage' => 33.333 ) ), 'rounding to 2dp' );

// Never exceed order total.
assert_advance( 100, 100, array_merge( $fixed, array( 'fixed' => 5000 ) ), 'fixed 5000 on total 100 -> 100' );
assert_advance( 0, 0, array_merge( $fixed, array( 'fixed' => 5000 ) ), 'zero total with big fixed -> 0' );

// Min/max interplay (max applied last, documented behaviour).
assert_advance( 50, 1000, array_merge( $fixed, array( 'min' => 200, 'max' => 50 ) ), 'conflicting min>max -> max wins' );

// ---- Extra COD fee engine ----

function assert_fee( $expected, $total, array $settings, $label ) {
	$actual = Core::calculate_fee( $total, $settings );
	$pass   = abs( $actual - $expected ) < 0.0001;
	printf(
		"[%s] %-58s expected %-8s got %-8s\n",
		$pass ? 'PASS' : 'FAIL',
		$label,
		(string) $expected,
		(string) $actual
	);
	if ( ! $pass ) {
		$GLOBALS['failures']++;
	}
	$GLOBALS['tests']++;
}

assert_fee( 0, 1000, $feeOff, 'fee disabled -> 0' );
assert_fee( 0, 0, $feeFixed, 'fee zero total -> 0' );
assert_fee( 30, 500, $feeFixed, 'fixed fee 30' );
assert_fee( 10, 500, $feePct, '2% of 500' );
assert_fee( 0, 500, array_merge( $feePct, array( 'fee_percentage' => 0 ) ), '0% fee -> 0' );
assert_fee( 12.34, 617, $feePct, '2% of 617 -> rounding to 2dp' );
assert_fee( 1.5, 100, array_merge( $feePct, array( 'fee_percentage' => 1.5 ) ), '1.5% of 100' );

echo "\n" . sprintf( '%d tests, %d failures', $GLOBALS['tests'], $GLOBALS['failures'] ) . "\n";
exit( $GLOBALS['failures'] > 0 ? 1 : 0 );
