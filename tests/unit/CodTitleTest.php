<?php
/**
 * Tests for the customer-facing COD title.
 *
 * @package COD_Shield
 */

declare( strict_types = 1 );

namespace CodShield\Tests;

use CodShield\Core;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * COD title tests.
 */
class CodTitleTest extends TestCase {

	#[TestDox( 'Configured COD title overrides the native title' )]
	public function test_configured_title_wins(): void {
		$this->assertSame( 'Pay on Delivery', Core::resolve_cod_title( ' Pay on Delivery ', 'Cash on delivery' ) );
	}

	#[TestDox( 'Blank COD title preserves the native gateway title' )]
	public function test_blank_title_uses_gateway_fallback(): void {
		$this->assertSame( 'Cash on delivery', Core::resolve_cod_title( '', 'Cash on delivery' ) );
	}

	#[TestDox( 'Advance proxy has a translated title when no native title exists' )]
	public function test_proxy_uses_final_fallback(): void {
		$this->assertSame( 'Cash on delivery (COD)', Core::resolve_cod_title( '', '' ) );
	}
}
