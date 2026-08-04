<?php
/**
 * Unit tests: class-to-file autoloader resolution.
 *
 * This is the regression test for the 1.0.0 WSOD postmortem: the bootstrap
 * called `new Order_UI()` but the autoloader mapped CodShield\Order_UI to
 * includes/class-cod-shield-order-ui.php, while the class was shipped in the
 * misnamed class-cod-shield-order.php — a fatal on every plugins_loaded.
 *
 * A static scan for eval/exec/SQL cannot catch a wrong class name; only
 * actually resolving the autoload path can. This test mirrors the autoloader
 * in cod-shield.php and asserts that EVERY class declared under includes/
 * lives in exactly the file the autoloader will look for — so every `new X()`
 * in the codebase resolves. No WordPress required.
 *
 * Run with: composer test  (or vendor/bin/phpunit)
 *
 * @package COD_Shield
 */

namespace CodShield\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Autoloader resolution tests.
 */
class AutoloaderResolutionTest extends TestCase {

	/**
	 * The plugin's includes/ directory.
	 *
	 * @return string
	 */
	private function includes_dir() {
		return dirname( __DIR__, 2 ) . '/includes';
	}

	/**
	 * Replicate the spl_autoload_register mapping in cod-shield.php:
	 *   CodShield\<Class_Name>  ->  includes/class-cod-shield-class-name.php
	 *
	 * @param string $class FQCN.
	 * @return string Expected basename (no directory).
	 */
	private function autoloader_basename( $class ) {
		if ( 0 !== strpos( $class, 'CodShield\\' ) ) {
			return '';
		}
		$relative = str_replace( '_', '-', strtolower( substr( $class, strlen( 'CodShield\\' ) ) ) );
		return 'class-cod-shield-' . $relative . '.php';
	}

	/**
	 * Discover every CodShield class/interface/trait declared under includes/.
	 *
	 * @return array<int,array{file:string,class:string,kind:string}>
	 */
	private function declared_classes() {
		$found = array();
		foreach ( glob( $this->includes_dir() . '/class-cod-shield-*.php' ) as $file ) {
			$source = file_get_contents( $file );
			if ( false === $source ) {
				continue;
			}
			// Only declarations inside the CodShield namespace qualify.
			if ( ! preg_match( '/^\s*namespace\s+CodShield;/m', $source ) ) {
				continue;
			}
			if ( preg_match_all( '/^\s*(final\s+|abstract\s+)?(class|interface|trait)\s+([A-Za-z0-9_]+)/m', $source, $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$found[] = array(
						'file'  => basename( $file ),
						'class' => 'CodShield\\' . $match[3],
						'kind'  => $match[2],
					);
				}
			}
		}
		return $found;
	}

	/**
	 * Every declared class must live in the exact file the autoloader targets.
	 *
	 * This is the single assertion that covers the whole surface: if every
	 * declared class is correctly placed, every `new X()` / `X::method()`
	 * reference in the plugin resolves through the autoloader.
	 */
	public function testEveryDeclaredClassIsInItsCanonicalFile() {
		$declared = $this->declared_classes();
		$this->assertNotEmpty( $declared, 'No CodShield classes discovered — the includes/ glob is wrong.' );

		$failures = array();
		foreach ( $declared as $row ) {
			$expected = $this->autoloader_basename( $row['class'] );
			if ( $row['file'] !== $expected ) {
				$failures[] = sprintf(
					'%s "%s" is declared in %s but the autoloader resolves it to %s',
					$row['kind'],
					$row['class'],
					$row['file'],
					$expected
				);
			}
		}

		$this->assertSame(
			array(),
			$failures,
			"Class/file mismatches found (these cause fatal \"Class not found\" errors at runtime):\n" . implode( "\n", $failures )
		);
	}

	/**
	 * Direct regression assertion for the 1.0.0 bug so the failure is obvious
	 * to anyone reading this test in isolation.
	 */
	public function testOrderUiClassResolves() {
		$this->assertSame(
			'class-cod-shield-order-ui.php',
			$this->autoloader_basename( 'CodShield\\Order_UI' ),
			'CodShield\\Order_UI must resolve to class-cod-shield-order-ui.php'
		);
		$this->assertFileExists( $this->includes_dir() . '/class-cod-shield-order-ui.php' );
	}

	/**
	 * A file the autoloader would target but that does not exist means a class
	 * referenced via `new X()` can never be loaded. Guard against stray
	 * bootstrap references whose target file was never created/misnamed.
	 */
	public function testNoBootstrapInstantiationPointsToMissingFile() {
		$core = $this->includes_dir() . '/class-cod-shield-core.php';
		$this->assertFileExists( $core );
		$source = (string) file_get_contents( $core );

		preg_match_all( '/new\s+([A-Za-z0-9_\\\\]+)\s*\(/', $source, $m );
		$this->assertNotEmpty( $m[1], 'No instantiation sites found in Core.' );

		foreach ( array_unique( $m[1] ) as $ref ) {
			$leading = ltrim( $ref, '\\' );
			if ( 0 !== strpos( $leading, 'CodShield' ) && false === strpos( $leading, '_' ) ) {
				// Likely a foreign/global class (e.g. WP_Error); skip.
				continue;
			}
			$fqcn  = ( 0 === strpos( $leading, 'CodShield' ) ) ? $leading : 'CodShield\\' . $leading;
			$file  = $this->includes_dir() . '/' . $this->autoloader_basename( $fqcn );
			$this->assertFileExists(
				$file,
				sprintf( 'Core instantiates %s but its autoload target %s does not exist.', $fqcn, basename( $file ) )
			);
		}
	}
}
