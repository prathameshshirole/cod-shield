<?php
/**
 * Unit tests: CodShield\Order_Meta::from_meta_array().
 *
 * Verifies the normalized read model picks up this plugin's own meta keys and
 * transparently falls back to the legacy/external RIRCOD schema, with COD
 * Shield meta taking precedence. Pure (no WordPress).
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Core;
use CodShield\Order_Meta;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/includes/class-cod-shield-order-meta.php';

/**
 * Order_Meta resolution tests.
 */
class OrderMetaTest extends TestCase {

	public function testEmptyMetaYieldsNoData() {
		$v = Order_Meta::from_meta_array( array() );
		$this->assertNull( $v->source );
		$this->assertFalse( $v->has_data() );
	}

	public function testCodShieldMetaIsRecognized() {
		$v = Order_Meta::from_meta_array(
			array(
				Core::META_ADVANCE        => 99,
				Core::META_REMAINING      => 401,
				Core::META_ORIGINAL_TOTAL => 500,
				Core::META_GATEWAY_USED   => 'razorpay',
				Core::META_TXN            => 'pay_123',
				Core::META_STATUS         => 'paid',
			)
		);
		$this->assertSame( Order_Meta::SOURCE_COD_SHIELD, $v->source );
		$this->assertSame( 99.0, $v->advance );
		$this->assertSame( 401.0, $v->remaining );
		$this->assertSame( 500.0, $v->total );
		$this->assertSame( 'razorpay', $v->gateway );
		$this->assertSame( 'paid', $v->status );
		$this->assertTrue( $v->has_data() );
	}

	public function testCodShieldAwaitingStatusDefaultsToPending() {
		$v = Order_Meta::from_meta_array(
			array(
				Core::META_ADVANCE  => 50,
				Core::META_AWAITING => 'yes',
			)
		);
		$this->assertSame( 'pending', $v->status );
	}

	public function testRircodSchemaIsRecognizedWhenFlagged() {
		$v = Order_Meta::from_meta_array(
			array(
				Order_Meta::RIRCOD_IS_COD     => 'yes',
				Order_Meta::RIRCOD_ADVANCE    => 100,
				Order_Meta::RIRCOD_COD_AMOUNT => 400,
				Order_Meta::RIRCOD_TOTAL      => 500,
			)
		);
		$this->assertSame( Order_Meta::SOURCE_RIRCOD, $v->source );
		$this->assertSame( 100.0, $v->advance );
		$this->assertSame( 400.0, $v->remaining );
		$this->assertSame( 500.0, $v->total );
		// RIRCOD advance present => treated as paid.
		$this->assertSame( 'paid', $v->status );
		$this->assertSame( 'RIRCOD', $v->source_label() );
		$this->assertTrue( $v->has_data() );
	}

	public function testRircodFlagAcceptsTruthyValues() {
		foreach ( array( '1', 'true', 'TRUE', 'on', 1, true ) as $flag ) {
			$v = Order_Meta::from_meta_array(
				array(
					Order_Meta::RIRCOD_IS_COD  => $flag,
					Order_Meta::RIRCOD_ADVANCE => 10,
				)
			);
			$this->assertSame( Order_Meta::SOURCE_RIRCOD, $v->source, "Flag value should activate RIRCOD: " . var_export( $flag, true ) );
		}
	}

	public function testRircodFlagFalsyDoesNotActivate() {
		foreach ( array( '0', 'no', '', 'false', 0, false ) as $flag ) {
			$v = Order_Meta::from_meta_array(
				array(
					Order_Meta::RIRCOD_IS_COD  => $flag,
					Order_Meta::RIRCOD_ADVANCE => 10,
				)
			);
			$this->assertNull( $v->source, "Flag value must NOT activate RIRCOD: " . var_export( $flag, true ) );
		}
	}

	public function testRircodFlaggedButNoAmountsYieldsNoData() {
		$v = Order_Meta::from_meta_array(
			array( Order_Meta::RIRCOD_IS_COD => 'yes' )
		);
		$this->assertNull( $v->source );
		$this->assertFalse( $v->has_data() );
	}

	public function testRircodTotalFallsBackToAdvancePlusRemaining() {
		$v = Order_Meta::from_meta_array(
			array(
				Order_Meta::RIRCOD_IS_COD     => 1,
				Order_Meta::RIRCOD_ADVANCE    => 75,
				Order_Meta::RIRCOD_COD_AMOUNT => 25,
			)
		);
		$this->assertSame( 100.0, $v->total );
	}

	public function testCodShieldTakesPrecedenceOverRircod() {
		$v = Order_Meta::from_meta_array(
			array(
				Core::META_ADVANCE          => 99,
				Core::META_STATUS           => 'paid',
				Order_Meta::RIRCOD_IS_COD   => 'yes',
				Order_Meta::RIRCOD_ADVANCE  => 1,
				Order_Meta::RIRCOD_TOTAL    => 2,
			)
		);
		$this->assertSame( Order_Meta::SOURCE_COD_SHIELD, $v->source );
		$this->assertSame( 99.0, $v->advance );
	}

	public function testZeroAdvanceCodShieldDoesNotActivateRircodFallback() {
		// Our keys present but zero => falls through to RIRCOD check.
		$v = Order_Meta::from_meta_array(
			array(
				Core::META_ADVANCE        => 0,
				Order_Meta::RIRCOD_IS_COD => 'yes',
				Order_Meta::RIRCOD_ADVANCE    => 50,
				Order_Meta::RIRCOD_COD_AMOUNT => 50,
			)
		);
		$this->assertSame( Order_Meta::SOURCE_RIRCOD, $v->source );
	}
}
