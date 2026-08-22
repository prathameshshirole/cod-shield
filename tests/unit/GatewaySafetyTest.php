<?php
/**
 * Tests for gateway selection and delegation safety helpers.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Gateway safety tests.
 */
class GatewaySafetyTest extends TestCase {

	#[TestDox( 'Legacy gateway arrays resolve to one deterministic gateway' )]
	public function test_legacy_array_uses_first_non_empty_gateway(): void {
		$this->assertSame(
			'razorpay',
			Core::resolve_delegation_gateway_id( array( '', 'razorpay', 'cashfree' ) )
		);
	}

	#[TestDox( 'Scalar gateway settings remain supported' )]
	public function test_scalar_gateway_setting_is_supported(): void {
		$this->assertSame( 'cashfree', Core::resolve_delegation_gateway_id( ' cashfree ' ) );
	}

	#[TestDox( 'Fieldless redirect gateway is compatible' )]
	public function test_fieldless_redirect_gateway_is_compatible(): void {
		$result = Core::assess_gateway_compatibility(
			'razorpay',
			$this->gateway_characteristics()
		);

		$this->assertTrue( $result['compatible'] );
		$this->assertSame( 'compatible', $result['code'] );
	}

	#[TestDox( 'Only the configured gateway identity is accepted' )]
	public function test_gateway_identity_must_match_configuration(): void {
		$result = Core::assess_gateway_compatibility(
			'razorpay',
			$this->gateway_characteristics( array( 'id' => 'cashfree' ) )
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'gateway_id_mismatch', $result['code'] );
	}

	#[TestDox( 'Disabled, COD, proxy and offline gateways are rejected' )]
	public function test_clearly_unsuitable_gateways_are_rejected(): void {
		$cases = array(
			array( 'razorpay', array( 'enabled' => 'no' ), 'gateway_disabled' ),
			array( 'cod', array( 'id' => 'cod' ), 'cod_or_proxy_gateway' ),
			array( Core::GATEWAY_ID, array( 'id' => Core::GATEWAY_ID ), 'cod_or_proxy_gateway' ),
			array( 'bacs', array( 'id' => 'bacs' ), 'offline_gateway' ),
			array( 'cheque', array( 'id' => 'cheque' ), 'offline_gateway' ),
		);

		foreach ( $cases as list( $id, $overrides, $code ) ) {
			$result = Core::assess_gateway_compatibility(
				$id,
				$this->gateway_characteristics( $overrides )
			);

			$this->assertFalse( $result['compatible'] );
			$this->assertSame( $code, $result['code'] );
		}
	}

	#[TestDox( 'Checkout fields and direct tokenized forms are rejected' )]
	public function test_checkout_field_gateways_are_rejected(): void {
		$fields = Core::assess_gateway_compatibility(
			'custom_fields',
			$this->gateway_characteristics(
				array(
					'id'         => 'custom_fields',
					'has_fields' => true,
				)
			)
		);
		$tokenized = Core::assess_gateway_compatibility(
			'card_gateway',
			$this->gateway_characteristics(
				array(
					'id'         => 'card_gateway',
					'has_fields' => true,
					'supports'   => array( 'products', 'tokenization' ),
				)
			)
		);

		$this->assertSame( 'checkout_fields_required', $fields['code'] );
		$this->assertSame( 'tokenized_fields_required', $tokenized['code'] );
	}

	#[TestDox( 'Optional token support does not block a redirect gateway' )]
	public function test_optional_token_support_is_allowed_without_fields(): void {
		$result = Core::assess_gateway_compatibility(
			'redirect_tokens',
			$this->gateway_characteristics(
				array(
					'id'       => 'redirect_tokens',
					'supports' => array( 'products', 'tokenization' ),
				)
			)
		);

		$this->assertTrue( $result['compatible'] );
	}

	#[TestDox( 'Cashfree description fields do not block its hosted redirect flow' )]
	public function test_cashfree_description_fields_are_compatible(): void {
		$result = Core::assess_gateway_compatibility(
			'cashfree',
			$this->gateway_characteristics(
				array(
					'id'            => 'cashfree',
					'gateway_class' => 'WC_Cashfree_Payments',
					'has_fields'    => true,
					'supports'      => array( 'products', 'refunds' ),
				)
			)
		);

		$this->assertTrue( $result['compatible'] );
		$this->assertSame( 'compatible', $result['code'] );
	}

	#[TestDox( 'Unknown gateways cannot bypass field safety by using the Cashfree ID' )]
	public function test_cashfree_id_requires_the_official_gateway_class(): void {
		$result = Core::assess_gateway_compatibility(
			'cashfree',
			$this->gateway_characteristics(
				array(
					'id'            => 'cashfree',
					'gateway_class' => 'Unrelated_Cashfree_Gateway',
					'has_fields'    => true,
				)
			)
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'checkout_fields_required', $result['code'] );
	}

	#[TestDox( 'Cashfree capture sees its gateway after webhook-first finalization' )]
	public function test_cashfree_capture_compatibility_view(): void {
		$this->assertTrue(
			Core::should_expose_cashfree_payment_method( 'cod', 'cashfree', 'paid', 'cashfree', 'capture' )
		);
	}

	#[TestDox( 'Cashfree compatibility view is limited to its paid capture callback' )]
	public function test_cashfree_capture_compatibility_view_is_narrowly_scoped(): void {
		$cases = array(
			array( 'cashfree', 'cashfree', 'paid', 'cashfree', 'capture' ),
			array( 'cod', 'razorpay', 'paid', 'cashfree', 'capture' ),
			array( 'cod', 'cashfree', 'pending', 'cashfree', 'capture' ),
			array( 'cod', 'cashfree', 'paid', 'other', 'capture' ),
			array( 'cod', 'cashfree', 'paid', 'cashfree', 'notify' ),
		);

		foreach ( $cases as $case ) {
			$this->assertFalse( Core::should_expose_cashfree_payment_method( ...$case ) );
		}
	}

	#[TestDox( 'Matching order components and payable advance are safe' )]
	public function test_consistent_delegation_amounts_are_safe(): void {
		$result = Core::assess_delegation_amounts( 100, 100, true, 20, 20 );

		$this->assertTrue( $result['safe'] );
		$this->assertSame( 'safe', $result['code'] );
	}

	#[TestDox( 'Inconsistent item, shipping or tax totals stop delegation' )]
	public function test_component_total_mismatch_is_unsafe(): void {
		$result = Core::assess_delegation_amounts( 100, 125, true, 20, 20 );

		$this->assertFalse( $result['safe'] );
		$this->assertSame( 'order_components_mismatch', $result['code'] );
	}

	#[TestDox( 'A persisted total different from the advance stops delegation' )]
	public function test_payable_total_mismatch_is_unsafe(): void {
		$result = Core::assess_delegation_amounts( 100, 100, true, 20, 100 );

		$this->assertFalse( $result['safe'] );
		$this->assertSame( 'payable_total_mismatch', $result['code'] );
	}

	#[TestDox( 'Orders without inspectable components still verify the payable total' )]
	public function test_missing_components_do_not_create_a_false_mismatch(): void {
		$result = Core::assess_delegation_amounts( 100, 0, false, 20, 20 );

		$this->assertTrue( $result['safe'] );
	}

	/**
	 * Build gateway characteristics.
	 *
	 * @param array<string,mixed> $overrides Characteristic overrides.
	 * @return array<string,mixed>
	 */
	private function gateway_characteristics( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 'razorpay',
				'enabled'     => 'yes',
				'has_fields'  => false,
				'supports'    => array( 'products' ),
				'can_process' => true,
				'is_direct'   => false,
			),
			$overrides
		);
	}
}
