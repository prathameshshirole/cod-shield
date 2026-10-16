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

	#[TestDox( 'A structured eligible result carries the empty code and empty message' )]
	public function testStructuredEvaluatorReturnsEligible(): void {
		$result = Eligibility::evaluate_rules(
			array(
				'total'             => 5000,
				'maximum'           => 10000,
				'postcode'          => '110001',
				'excluded_pincodes' => "400001,\n110002",
			)
		);

		$this->assertTrue( $result['eligible'] );
		$this->assertSame( '', $result['code'] );
		$this->assertSame( '', $result['message'] );
	}

	#[TestDox( 'A structured above-maximum result uses a stable code and formatted message' )]
	public function testStructuredEvaluatorReportsMaximum(): void {
		$result = Eligibility::evaluate_rules(
			array(
				'total'             => 5000.01,
				'maximum'           => 5000,
				'postcode'          => '',
				'excluded_pincodes' => '',
			)
		);

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'above_maximum', $result['code'] );
		$this->assertStringContainsString( 'orders up to', $result['message'] );
		$this->assertStringContainsString( '5,000.00', $result['message'] );
	}

	#[TestDox( 'A structured excluded-pincode result uses a stable code' )]
	public function testStructuredEvaluatorReportsExcludedPincode(): void {
		$result = Eligibility::evaluate_rules(
			array(
				'total'             => 100,
				'maximum'           => 0,
				'postcode'          => '400076',
				'excluded_pincodes' => '400*',
			)
		);

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'excluded_pincode', $result['code'] );
		$this->assertStringContainsString( 'not available for this delivery pincode', $result['message'] );
	}

	#[TestDox( 'The maximum rule is evaluated before the pincode rule' )]
	public function testStructuredEvaluatorMaximumTakesPrecedence(): void {
		$result = Eligibility::evaluate_rules(
			array(
				'total'             => 6000,
				'maximum'           => 5000,
				'postcode'          => '400076',
				'excluded_pincodes' => '400*',
			)
		);

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'above_maximum', $result['code'] );
	}

	#[TestDox( 'An order exactly at the maximum stays eligible' )]
	public function testStructuredEvaluatorMaximumBoundary(): void {
		$result = Eligibility::evaluate_rules(
			array(
				'total'             => 5000,
				'maximum'           => 5000,
				'postcode'          => '',
				'excluded_pincodes' => '',
			)
		);

		$this->assertTrue( $result['eligible'] );
	}

	#[TestDox( 'The proxy can only replace native COD that Checkout deliberately swapped out' )]
	public function testProxyInsertionRequiresNativeCodSwapEvidence(): void {
		$bridge_source  = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-gateway-bridge.php' );
		$checkout_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-checkout.php' );

		$this->assertIsString( $bridge_source );
		$this->assertIsString( $checkout_source );
		$this->assertStringContainsString( 'Checkout::cod_removed_for_swap()', $bridge_source );
		$this->assertStringContainsString( 'self::$cod_removed_for_swap = false;', $checkout_source );
		$this->assertStringContainsString( 'self::$cod_removed_for_swap = true;', $checkout_source );
	}

	#[TestDox( 'ID lists accept commas, spaces, and new lines and drop invalid tokens' )]
	public function testNormalizeIdList(): void {
		$this->assertSame( array( 10, 42 ), Eligibility::normalize_id_list( "10, abc, 0, -5, 3.7, 42\n10" ) );
		$this->assertSame( array( 5 ), Eligibility::normalize_id_list( '5 5 5' ) );
		$this->assertSame( array(), Eligibility::normalize_id_list( 'abc, def' ) );
		$this->assertSame( array(), Eligibility::normalize_id_list( '' ) );
	}

	#[TestDox( 'Shipping method lists keep rate and instance tokens in a canonical form' )]
	public function testNormalizeShippingMethodList(): void {
		$this->assertSame(
			array( 'flat_rate:23', 'local_pickup', 'flat_rate' ),
			Eligibility::normalize_shipping_method_list( "Flat_Rate:23, local_pickup\nflat_rate:0" )
		);
		$this->assertSame( array(), Eligibility::normalize_shipping_method_list( ' , \n' ) );
	}

	#[TestDox( 'A shipping exclusion matches the rate id or the exact instance' )]
	public function testShippingSelectionMatchesRateAndInstance(): void {
		$selected = array( 'flat_rate:23', 'free_shipping:1' );
		$this->assertTrue( Eligibility::shipping_selection_matches_exclusions( $selected, array( 'flat_rate' ) ) );
		$this->assertTrue( Eligibility::shipping_selection_matches_exclusions( $selected, array( 'flat_rate:23' ) ) );
		$this->assertFalse( Eligibility::shipping_selection_matches_exclusions( $selected, array( 'flat_rate:24' ) ) );
		$this->assertFalse( Eligibility::shipping_selection_matches_exclusions( $selected, array() ) );
	}

	#[TestDox( 'Multiple packages fail eligibility when any selected method is excluded' )]
	public function testMultiplePackagesAnyMatchExcludes(): void {
		$this->assertTrue(
			Eligibility::shipping_selection_matches_exclusions(
				array( 'flat_rate:1', 'local_pickup:5' ),
				array( 'local_pickup:5' )
			)
		);
		$this->assertFalse(
			Eligibility::shipping_selection_matches_exclusions(
				array( 'flat_rate:1', 'local_pickup:5' ),
				array( 'free_shipping:9' )
			)
		);
	}

	#[TestDox( 'A cart item is excluded by product, variation, category, or shipping class' )]
	public function testCartItemExclusions(): void {
		$simple     = array( 'product_id' => 12, 'variation_id' => 0, 'category_ids' => array( 4 ), 'shipping_class_id' => 9 );
		$variation  = array( 'product_id' => 12, 'variation_id' => 77, 'category_ids' => array( 4 ), 'shipping_class_id' => 9 );

		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $simple ), array( 12 ), array(), array() ) );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $variation ), array( 77 ), array(), array() ) );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $simple ), array(), array( 4 ), array() ) );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $simple ), array(), array(), array( 9 ) ) );
		$this->assertFalse( Eligibility::cart_items_match_exclusions( array( $simple ), array( 99 ), array( 5 ), array( 8 ) ) );
	}

	#[TestDox( 'Variations inherit the parent product restrictions' )]
	public function testVariationMatchesParent(): void {
		$variation = array( 'product_id' => 12, 'variation_id' => 77, 'category_ids' => array( 4 ), 'shipping_class_id' => 9 );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $variation ), array( 12 ), array(), array() ) );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $variation ), array(), array( 4 ), array() ) );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $variation ), array(), array(), array( 9 ) ) );
	}

	#[TestDox( 'A mixed cart is excluded when any item matches' )]
	public function testMixedCartAnyItemExcludes(): void {
		$allowed  = array( 'product_id' => 1, 'variation_id' => 0, 'category_ids' => array( 2 ), 'shipping_class_id' => 3 );
		$excluded = array( 'product_id' => 50, 'variation_id' => 0, 'category_ids' => array( 2 ), 'shipping_class_id' => 3 );
		$this->assertTrue( Eligibility::cart_items_match_exclusions( array( $allowed, $excluded ), array( 50 ), array(), array() ) );
		$this->assertFalse( Eligibility::cart_items_match_exclusions( array( $allowed, $excluded ), array( 60 ), array(), array() ) );
	}

	#[TestDox( 'Items with missing product data never fatal and never match' )]
	public function testDeletedProductDataDoesNotFatal(): void {
		$empty = array( 'product_id' => 0, 'variation_id' => 0, 'category_ids' => array(), 'shipping_class_id' => 0 );
		$this->assertFalse( Eligibility::cart_items_match_exclusions( array( $empty ), array( 1 ), array( 1 ), array( 1 ) ) );
	}

	#[TestDox( 'Shipping exclusions are evaluated before catalog exclusions' )]
	public function testShippingExclusionTakesPrecedenceOverCatalog(): void {
		$item = array( 'product_id' => 12, 'variation_id' => 0, 'category_ids' => array( 4 ), 'shipping_class_id' => 9 );
		$result = Eligibility::evaluate_rules(
			array(
				'total'                     => 100,
				'maximum'                   => 0,
				'postcode'                  => '',
				'excluded_pincodes'         => '',
				'selected_shipping_methods' => array( 'local_pickup:5' ),
				'excluded_shipping_methods' => array( 'local_pickup' ),
				'cart_items'                => array( $item ),
				'excluded_products'         => array( 12 ),
				'excluded_categories'       => array(),
				'excluded_shipping_classes' => array(),
			)
		);
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'excluded_shipping_method', $result['code'] );
	}

	#[TestDox( 'Excluded products produce a stable code and message' )]
	public function testExcludedProductResult(): void {
		$item = array( 'product_id' => 12, 'variation_id' => 0, 'category_ids' => array(), 'shipping_class_id' => 0 );
		$result = Eligibility::evaluate_rules(
			array(
				'total'                     => 100,
				'maximum'                   => 0,
				'postcode'                  => '',
				'excluded_pincodes'         => '',
				'selected_shipping_methods' => array(),
				'excluded_shipping_methods' => array(),
				'cart_items'                => array( $item ),
				'excluded_products'         => array( 12 ),
				'excluded_categories'       => array(),
				'excluded_shipping_classes' => array(),
			)
		);
		$this->assertFalse( $result['eligible'] );
		$this->assertSame( 'excluded_product', $result['code'] );
		$this->assertStringContainsString( 'not available for some items', $result['message'] );
	}

	#[TestDox( 'Eligibility runs after proxy insertion and is enforced by Checkout Blocks' )]
	public function testEligibilityIsWiredAfterGatewayBridge(): void {
		$eligibility_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-eligibility.php' );
		$blocks_source      = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-blocks-payment-method-type.php' );

		$this->assertIsString( $eligibility_source );
		$this->assertIsString( $blocks_source );
		$this->assertStringContainsString( "array( \$this, 'filter_available_gateways' ), 30", $eligibility_source );
		$this->assertStringContainsString( '$core->native_cod_enabled()', $blocks_source );
		$live_source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-cod-shield-blocks.php' );
		$this->assertStringContainsString( 'Eligibility::evaluate_current_cart()', $live_source );
		$this->assertStringContainsString( "'cod_ineligibility_message'", $live_source );
	}
}
