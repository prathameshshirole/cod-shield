<?php
/**
 * Regression tests for the manual advance-paid admin action markup.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

namespace CodShield\Tests;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Manual advance-paid admin action tests.
 */
class ManualPaidActionTest extends TestCase {

	#[TestDox( 'Uses a nonce-protected POST button without nesting a form in the order editor' )]
	public function test_uses_nonce_protected_post_button_without_nested_form(): void {
		$root      = dirname( __DIR__, 2 );
		$ui_source = file_get_contents( $root . '/includes/class-cod-shield-order-ui.php' );
		$handler   = file_get_contents( $root . '/includes/class-cod-shield-gateway-bridge.php' );

		$this->assertIsString( $ui_source, 'The order UI source should be readable' );
		$this->assertIsString( $handler, 'The admin action handler source should be readable' );
		$this->assertStringContainsString( 'formmethod="post"', $ui_source );
		$this->assertStringContainsString( "wp_nonce_field( Core::NONCE_MANUAL_PAID", $ui_source );
		$this->assertStringNotContainsString( '<form', $ui_source );
		$this->assertStringNotContainsString( 'wp_nonce_url(', $ui_source );
		$this->assertStringContainsString( "\$_POST['cod_shield_order_id']", $handler );
		$this->assertStringNotContainsString( "\$_GET['order_id']", $handler );
		$this->assertStringContainsString( "current_user_can( 'edit_shop_order', \$order_id )", $handler );
	}
}
