<?php
/**
 * Integration coverage for the COD advance money lifecycle.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

use CodShield\Checkout;
use CodShield\Core;
use CodShield\Gateway_Bridge;
use CodShield\Gateway_COD_Advance;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * COD advance lifecycle integration tests.
 */
class Cod_Shield_Lifecycle_Test extends WP_UnitTestCase {

	/** @var WC_Gateway_COD_Shield_Stub */
	private $stub_gateway;

	/** Set up plugin and gateway state. */
	public function set_up(): void {
		parent::set_up();

		foreach ( Core::get_defaults() as $key => $value ) {
			update_option( $key, $value );
		}
		update_option( Core::OPT_MODE, 'fixed' );
		update_option( Core::OPT_FIXED, 20 );
		update_option( Core::OPT_MIN, 0 );
		update_option( Core::OPT_MAX, 0 );
		update_option( Core::OPT_GATEWAYS, array( 'cod_shield_stub' ) );

		$gateways                    = WC()->payment_gateways()->payment_gateways();
		$this->stub_gateway          = $gateways['cod_shield_stub'];
		$this->stub_gateway->enabled = 'yes';
		$this->stub_gateway->should_succeed = true;
		$this->stub_gateway->charge_override = null;
		$this->stub_gateway->txn_id = 'txn-stub-001';
		$gateways['cod']->enabled = 'yes';
	}

	#[TestDox( 'Successful delegation restores the full total and converts the order to COD' )]
	public function test_successful_delegation_finalizes_order(): void {
		update_option( Core::OPT_COD_TITLE, 'Pay on Delivery' );
		$gateway = new Gateway_COD_Advance();
		$order   = $this->create_advance_order();
		$result  = $gateway->process_payment( $order->get_id() );
		$order  = $this->reload_order( $order->get_id() );

		$this->assertSame( 'Pay on Delivery', $gateway->get_title() );
		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 100.0, (float) $order->get_total() );
		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertSame( 'Pay on Delivery', $order->get_payment_method_title() );
		$this->assertSame( 'txn-stub-001', $order->get_transaction_id() );
		$this->assertSame( 'txn-stub-001', $order->get_meta( Core::META_TXN ) );
		$this->assertSame( 'paid', $order->get_meta( Core::META_STATUS ) );
		$this->assertSame( 'no', $order->get_meta( Core::META_AWAITING ) );
		$this->assertSame( 80.0, (float) $order->get_meta( Core::META_REMAINING ) );
	}

	#[TestDox( 'Failed delegation restores the full total and remains retryable' )]
	public function test_failed_delegation_restores_order(): void {
		$this->stub_gateway->should_succeed = false;
		$order  = $this->create_advance_order();
		$result = ( new Gateway_COD_Advance() )->process_payment( $order->get_id() );
		$order  = $this->reload_order( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 100.0, (float) $order->get_total() );
		$this->assertSame( Core::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'failed', $order->get_meta( Core::META_STATUS ) );
		$this->assertSame( 'no', $order->get_meta( Core::META_AWAITING ) );
		$this->assertSame( 'pending', $order->get_status() );
	}

	#[TestDox( 'Configured blocking cancels an order after delegation failure' )]
	public function test_failed_delegation_can_cancel_order(): void {
		update_option( Core::OPT_FAILURE, 'block' );
		$this->stub_gateway->should_succeed = false;
		$order = $this->create_advance_order();

		( new Gateway_COD_Advance() )->process_payment( $order->get_id() );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 100.0, (float) $order->get_total() );
	}

	#[TestDox( 'Order-pay retry is refused once a charge may already be captured' )]
	public function test_retry_refused_after_charge_initiated(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$order->set_transaction_id( 'txn-stub-001' );
		$order->save();

		$result = ( new Gateway_COD_Advance() )->process_payment( $order->get_id() );
		$order  = $this->reload_order( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		// Refusal is a no-op: total, awaiting state and txn are untouched.
		$this->assertSame( 100.0, (float) $order->get_total() );
		$this->assertSame( 'yes', $order->get_meta( Core::META_AWAITING ) );
		$this->assertSame( 'txn-stub-001', $order->get_transaction_id() );
	}

	#[TestDox( 'Order-pay retry is refused while an order is flagged for advance review' )]
	public function test_retry_refused_for_review_flagged_order(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$order->update_meta_data( Core::META_AWAITING, 'review' );
		$order->save();

		$result = ( new Gateway_COD_Advance() )->process_payment( $order->get_id() );
		$order  = $this->reload_order( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'review', $order->get_meta( Core::META_AWAITING ) );
		$this->assertSame( 100.0, (float) $order->get_total() );
	}

	#[TestDox( 'Awaiting-advance orders are exempt from the unpaid-order canceller' )]
	public function test_auto_cancel_guard_exempts_awaiting_advance(): void {
		$bridge = $this->bridge();

		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$this->assertFalse( $bridge->prevent_auto_cancel_of_advance_order( true, $order ) );

		// Orders that are not awaiting an advance, and non-order values, pass
		// the canceller through unchanged.
		$plain = $this->create_advance_order();
		$this->assertTrue( $bridge->prevent_auto_cancel_of_advance_order( true, $plain ) );
		$this->assertFalse( $bridge->prevent_auto_cancel_of_advance_order( false, $plain ) );
		$this->assertTrue( $bridge->prevent_auto_cancel_of_advance_order( true, null ) );
	}

	#[TestDox( 'A cancelled order with a captured advance is flagged for review' )]
	public function test_cancelled_advance_with_txn_is_flagged_for_review(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$order->set_transaction_id( 'txn-stub-001' );
		$order->set_status( 'cancelled' );
		$order->save();

		$this->bridge()->flag_cancelled_advance_for_review( $order->get_id() );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'review', $order->get_meta( Core::META_AWAITING ) );
		$this->assertStringContainsString( 'ADVANCE RECONCILIATION REQUIRED', $this->order_notes( $order ) );
	}

	#[TestDox( 'A cancelled order awaiting an in-flight charge is flagged for review' )]
	public function test_cancelled_order_with_inflight_charge_is_flagged(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$order->set_status( 'cancelled' );
		$order->save();

		$this->bridge()->flag_cancelled_advance_for_review( $order->get_id() );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'review', $order->get_meta( Core::META_AWAITING ) );
		$this->assertStringContainsString( 'ADVANCE RECONCILIATION REQUIRED', $this->order_notes( $order ) );
	}

	#[TestDox( 'Cancelling an order that never started an advance is left alone' )]
	public function test_cancel_without_advance_activity_is_not_flagged(): void {
		$order = $this->create_advance_order();
		$order->set_status( 'cancelled' );
		$order->save();

		$this->bridge()->flag_cancelled_advance_for_review( $order->get_id() );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( '', $order->get_meta( Core::META_AWAITING ) );
		$this->assertStringNotContainsString( 'ADVANCE RECONCILIATION REQUIRED', $this->order_notes( $order ) );
	}

	#[TestDox( 'Overcharge protection retains the online gateway and clears the COD remainder' )]
	public function test_overcharge_guard_prevents_double_collection(): void {
		$this->stub_gateway->charge_override = 100.0;
		$order = $this->create_advance_order();

		( new Gateway_COD_Advance() )->process_payment( $order->get_id() );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'cod_shield_stub', $order->get_payment_method() );
		$this->assertSame( 100.0, (float) $order->get_total() );
		$this->assertSame( 0.0, (float) $order->get_meta( Core::META_REMAINING ) );
		$this->assertStringContainsString( 'WARNING', $this->order_notes( $order ) );
	}

	#[TestDox( 'An unpaid advance cannot remain in a fulfilment status' )]
	public function test_fulfilment_guard_reverts_unpaid_order(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$order->set_status( 'processing' );
		$order->save();

		$this->bridge()->revert_unpaid_advance_status( $order->get_id(), 'pending', 'processing', $order );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'pending', $order->get_status() );
	}

	#[TestDox( 'Manual reconciliation finalizes once and persists across reloads' )]
	public function test_manual_reconciliation_is_idempotent(): void {
		$order = $this->create_advance_order();
		$this->seed_awaiting_meta( $order );
		$bridge = $this->bridge();

		$this->assertTrue( $bridge->manual_mark_advance_paid( $order ) );
		$this->assertFalse( $bridge->manual_mark_advance_paid( $order ) );
		$order = $this->reload_order( $order->get_id() );

		$this->assertSame( 'cod', $order->get_payment_method() );
		$this->assertSame( 'paid', $order->get_meta( Core::META_STATUS ) );
		$this->assertSame( 100.0, (float) $order->get_total() );
	}

	#[TestDox( 'Block checkout validation applies COD rules only to COD methods' )]
	public function test_store_api_validation_is_scoped_to_cod(): void {
		update_option( Core::OPT_THRESHOLD, 50 );
		$cart = new class() {
			public function get_total(): float {
				return 25.0;
			}
		};
		$checkout = $this->checkout();
		WC()->session->set( 'chosen_payment_method', 'stripe' );
		$errors = $checkout->filter_store_api_cart_errors( new WP_Error(), $cart );
		$this->assertFalse( $errors->has_errors() );

		WC()->session->set( 'chosen_payment_method', Core::GATEWAY_ID );
		$errors = $checkout->filter_store_api_cart_errors( new WP_Error(), $cart );
		$this->assertTrue( $errors->has_errors() );
		$this->assertNotEmpty( $errors->get_error_message( 'cod_shield_minimum' ) );
	}

	#[TestDox( 'Block COD fee creation is idempotent' )]
	public function test_block_cod_fee_is_idempotent(): void {
		update_option( Core::OPT_FEE_ENABLED, 'yes' );
		update_option( Core::OPT_FEE_AMOUNT, 30 );
		$order    = $this->create_advance_order();
		$checkout = $this->checkout();

		$checkout->maybe_add_cod_fee_to_order( $order );
		$checkout->maybe_add_cod_fee_to_order( $order );
		$order = $this->reload_order( $order->get_id() );

		$this->assertCount( 1, $order->get_fees() );
		$this->assertSame( 130.0, (float) $order->get_total() );
	}

	/** @return WC_Order */
	private function create_advance_order(): WC_Order {
		$order = wc_create_order();
		$order->set_total( 100 );
		$order->set_payment_method( Core::GATEWAY_ID );
		$order->set_payment_method_title( 'Cash on delivery (COD)' );
		$order->save();
		return $order;
	}

	/** @param WC_Order $order Order. */
	private function seed_awaiting_meta( WC_Order $order ): void {
		$order->update_meta_data( Core::META_ADVANCE, 20 );
		$order->update_meta_data( Core::META_ORIGINAL_TOTAL, 100 );
		$order->update_meta_data( Core::META_REMAINING, 80 );
		$order->update_meta_data( Core::META_STATUS, 'pending' );
		$order->update_meta_data( Core::META_AWAITING, 'yes' );
		$order->update_meta_data( Core::META_GATEWAY_USED, 'cod_shield_stub' );
		$order->save();
	}

	/** @return WC_Order */
	private function reload_order( int $order_id ): WC_Order {
		clean_post_cache( $order_id );
		wp_cache_flush();
		return wc_get_order( $order_id );
	}

	private function order_notes( WC_Order $order ): string {
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		return implode( ' ', wp_list_pluck( $notes, 'content' ) );
	}

	private function bridge(): Gateway_Bridge {
		$reflection = new ReflectionClass( Gateway_Bridge::class );
		return $reflection->newInstanceWithoutConstructor();
	}

	private function checkout(): Checkout {
		$reflection = new ReflectionClass( Checkout::class );
		return $reflection->newInstanceWithoutConstructor();
	}
}
