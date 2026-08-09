<?php
/**
 * Unit tests for India-focused COD eligibility rules.
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use CodShield\Eligibility;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * COD eligibility tests.
 */
class EligibilityTest extends TestCase {

	#[TestDox( 'Maximum COD value is disabled when configured as zero' )]
	public function testMaximumDisabledAtZero(): void {
		$this->assertFalse( Eligibility::is_above_maximum( 5000, 0 ) );
	}

	#[TestDox( 'Orders at the maximum are allowed and orders above it are blocked' )]
	public function testMaximumBoundary(): void {
		$this->assertFalse( Eligibility::is_above_maximum( 5000, 5000 ) );
		$this->assertTrue( Eligibility::is_above_maximum( 5000.01, 5000 ) );
	}

	#[TestDox( 'Exact pincode exclusions are normalized before matching' )]
	public function testExactPincodeMatch(): void {
		$this->assertTrue( Eligibility::postcode_matches_rules( '110 001', "400001,\n110001" ) );
		$this->assertFalse( Eligibility::postcode_matches_rules( '110002', "400001,\n110001" ) );
	}

	#[TestDox( 'A trailing wildcard excludes a pincode prefix' )]
	public function testPincodePrefixMatch(): void {
		$this->assertTrue( Eligibility::postcode_matches_rules( '400076', '110001 400*' ) );
		$this->assertFalse( Eligibility::postcode_matches_rules( '401076', '110001 400*' ) );
	}

	#[TestDox( 'Empty pincode data never blocks COD' )]
	public function testEmptyPincodeDoesNotMatch(): void {
		$this->assertFalse( Eligibility::postcode_matches_rules( '', '400*' ) );
		$this->assertFalse( Eligibility::postcode_matches_rules( '400076', '' ) );
	}

	#[TestDox( 'Eligibility runs after proxy insertion and is enforced by Checkout Blocks' )]
	public function testEligibilityIsWiredAfterGatewayBridge(): void {
		$eligibility_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-eligibility.php' );
		$blocks_source      = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-blocks-payment-method-type.php' );

		$this->assertIsString( $eligibility_source );
		$this->assertIsString( $blocks_source );
		$this->assertStringContainsString( "array( \$this, 'filter_available_gateways' ), 30", $eligibility_source );
		$this->assertStringContainsString( '$core->native_cod_enabled()', $blocks_source );
		$this->assertStringContainsString( 'Eligibility::get_current_ineligibility_reason()', $blocks_source );
	}
}
