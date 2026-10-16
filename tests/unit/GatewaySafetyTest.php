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

	public function test_attempt_classification_preserves_evidence_and_interval_boundaries(): void {
		$base = array(
			'awaiting' => 'yes',
			'attempt_started' => 'yes',
			'started_at' => '10000',
			'transaction_id' => '',
			'recorded_transaction_id' => '',
			'status' => 'pending',
		);
		$cases = array(
			array( array(), 13599, 'active' ),
			array( array(), 13600, 'stale' ),
			array( array(), 13601, 'stale' ),
			array( array(), 9999, 'active' ),
			array( array( 'started_at' => '' ), 13600, 'legacy_unknown' ),
			array( array( 'started_at' => 'invalid' ), 13600, 'legacy_unknown' ),
			array( array( 'started_at' => '-1' ), 13600, 'legacy_unknown' ),
			array( array( 'started_at' => '0' ), 13600, 'legacy_unknown' ),
			array( array( 'transaction_id' => 'txn-1' ), 13600, 'captured' ),
			array( array( 'recorded_transaction_id' => '0' ), 13600, 'captured' ),
			array( array( 'status' => 'paid' ), 13600, 'captured' ),
			array( array( 'awaiting' => 'review', 'transaction_id' => 'txn-1' ), 13600, 'review' ),
			array( array( 'status' => 'review' ), 13600, 'review' ),
			array( array( 'attempt_started' => '', 'started_at' => '' ), 13600, 'legacy_unknown' ),
			array( array( 'attempt_started' => '' ), 13600, 'legacy_unknown' ),
			array( array( 'attempt_started' => 'unknown' ), 13600, 'legacy_unknown' ),
			array( array( 'attempt_started' => 'no', 'started_at' => '' ), 13600, 'inactive' ),
			array( array( 'awaiting' => 'no' ), 13600, 'inactive' ),
		);
		foreach ( $cases as list( $overrides, $now, $expected ) ) {
			$this->assertSame( $expected, Core::classify_payment_attempt( array_replace( $base, $overrides ), $now, 60 ), json_encode( $overrides ) . ':' . $now );
		}
	}

	public function test_stale_interval_is_bounded_and_invalid_values_use_safe_default(): void {
		foreach ( array( array( '', 60 ), array( 'bad', 60 ), array( array(), 60 ), array( 0, 15 ), array( -20, 15 ), array( 20.9, 20 ), array( '90', 90 ), array( 20000, 10080 ) ) as list( $input, $expected ) ) {
			$this->assertSame( $expected, Core::normalize_stale_attempt_minutes( $input ) );
		}
	}

	public function test_configured_interval_controls_when_an_attempt_is_stale(): void {
		$state = array( 'awaiting' => 'yes', 'attempt_started' => 'yes', 'started_at' => 10000 );
		$this->assertSame( 'active', Core::classify_payment_attempt( $state, 10899, 15 ) );
		$this->assertSame( 'stale', Core::classify_payment_attempt( $state, 10900, 15 ) );
		$this->assertSame( 'active', Core::classify_payment_attempt( $state, 13600, 120 ) );
		$this->assertSame( 'stale', Core::classify_payment_attempt( $state, 17200, 120 ) );
	}

	public function test_finalization_diagnostics_only_log_changed_actionable_rejections(): void {
		$cases = array(
			array( '', 'missing_transaction', true ),
			array( 'missing_transaction', 'missing_transaction', false ),
			array( 'missing_transaction', 'gateway_mismatch', true ),
			array( 'gateway_mismatch', 'gateway_mismatch', false ),
			array( '', 'invalid_total', true ),
			array( '', 'not_awaiting', false ),
			array( 'missing_transaction', 'ready', false ),
			array( '', 'unknown', false ),
		);

		foreach ( $cases as list( $previous, $current, $expected ) ) {
			$this->assertSame( $expected, Core::should_log_finalization_diagnostic( $previous, $current ), $previous . ' -> ' . $current );
		}
	}

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

	#[TestDox( 'Unknown fieldless redirect gateway is not explicitly supported' )]
	public function test_unknown_fieldless_redirect_gateway_is_rejected(): void {
		$result = Core::assess_gateway_compatibility(
			'redirect_gateway',
			$this->gateway_characteristics( array( 'id' => 'redirect_gateway', 'gateway_class' => 'WC_Redirect_Gateway' ) )
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'gateway_not_explicitly_supported', $result['code'] );
	}

	public function test_supported_gateway_class_map_is_explicit(): void {
		$this->assertSame(
			array(
				'cashfree' => array( 'WC_Cashfree_Payments' ),
				'razorpay' => array( 'WC_Razorpay' ),
			),
			Core::supported_gateway_classes()
		);
	}

	public function test_in_flight_attempt_state_blocks_only_delegated_orders(): void {
		$this->assertFalse( Core::payment_attempt_is_in_flight( 'yes', '', '', '' ) );
		$this->assertTrue( Core::payment_attempt_is_in_flight( 'yes', 'yes', '', 'razorpay' ) );
		$this->assertTrue( Core::payment_attempt_is_in_flight( 'yes', '', '', 'razorpay' ) );
		$this->assertFalse( Core::payment_attempt_is_in_flight( 'yes', 'no', '', 'razorpay' ) );
		$this->assertTrue( Core::payment_attempt_is_in_flight( 'yes', '', 'txn-1', '' ) );
		$this->assertTrue( Core::payment_attempt_is_in_flight( 'review', '', '', '' ) );
	}

	public function test_only_pending_awaiting_orders_restore_delegated_state(): void {
		$this->assertTrue( Core::should_restore_in_flight_state( 'yes', 'pending', 'razorpay', 20 ) );
		$this->assertFalse( Core::should_restore_in_flight_state( 'no', 'paid', 'razorpay', 20 ) );
		$this->assertFalse( Core::should_restore_in_flight_state( 'review', 'pending', 'razorpay', 20 ) );
		$this->assertFalse( Core::should_restore_in_flight_state( 'yes', 'paid', 'razorpay', 20 ) );
	}

	public function test_malformed_supported_gateway_filter_values_are_ignored(): void {
		$result = Core::assess_gateway_compatibility(
			'custom',
			$this->gateway_characteristics(
				array(
					'id'                        => 'custom',
					'gateway_class'             => 'WC_Custom',
					'supported_gateway_classes' => array( 'custom' => array( new \stdClass(), '', 'WC_Custom' ) ),
				)
			)
		);

		$this->assertTrue( $result['compatible'] );
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

	#[TestDox( 'Optional token support cannot make an unknown redirect gateway supported' )]
	public function test_optional_token_support_does_not_allow_unknown_gateway(): void {
		$result = Core::assess_gateway_compatibility(
			'redirect_tokens',
			$this->gateway_characteristics(
				array(
					'id'       => 'redirect_tokens',
					'supports' => array( 'products', 'tokenization' ),
				)
			)
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'gateway_not_explicitly_supported', $result['code'] );
	}

	#[TestDox( 'Official Razorpay standard checkout with automatic capture is compatible' )]
	public function test_official_razorpay_automatic_capture_is_compatible(): void {
		$result = Core::assess_gateway_compatibility(
			'razorpay',
			$this->gateway_characteristics(
				array(
					'gateway_class' => 'WC_Razorpay',
					'supports'      => array( 'products', 'refunds' ),
					'payment_action' => 'capture',
					'route_enabled'  => 'no',
				)
			)
		);

		$this->assertTrue( $result['compatible'] );
		$this->assertSame( 'compatible', $result['code'] );
	}

	#[TestDox( 'Razorpay manual capture is rejected because authorization is not collection' )]
	public function test_razorpay_manual_capture_is_incompatible(): void {
		$result = Core::assess_gateway_compatibility(
			'razorpay',
			$this->gateway_characteristics(
				array(
					'gateway_class' => 'WC_Razorpay',
					'payment_action' => 'authorize',
				)
			)
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'razorpay_manual_capture_unsupported', $result['code'] );
	}

	#[TestDox( 'Razorpay Route is rejected because transfers can exceed the advance' )]
	public function test_razorpay_route_is_incompatible(): void {
		$result = Core::assess_gateway_compatibility(
			'razorpay',
			$this->gateway_characteristics(
				array(
					'gateway_class' => 'WC_Razorpay',
					'payment_action' => 'capture',
					'route_enabled'  => 'yes',
				)
			)
		);

		$this->assertFalse( $result['compatible'] );
		$this->assertSame( 'razorpay_route_unsupported', $result['code'] );
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
				'gateway_class' => 'WC_Razorpay',
			),
			$overrides
		);
	}
}
