<?php
/**
 * Unit tests for the URL normalization + validation logic.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Admin\Admin;
use Abtest\Experiment;
use PHPUnit\Framework\TestCase;

final class UrlValidatorTest extends TestCase {

	/**
	 * @dataProvider normalize_cases
	 */
	public function test_normalize_path( string $input, string $expected ): void {
		$this->assertSame( $expected, Experiment::normalize_path( $input ) );
	}

	public static function normalize_cases(): array {
		return [
			'plain path adds slashes' => [ 'promo', '/promo/' ],
			'leading slash only'      => [ '/promo', '/promo/' ],
			'trailing slash only'     => [ 'promo/', '/promo/' ],
			'both slashes preserved'  => [ '/promo/', '/promo/' ],
			'lowercased'              => [ '/Promo/', '/promo/' ],
			'mixed case nested'       => [ 'Parent/Child', '/parent/child/' ],
			'whitespace trimmed'      => [ '  /promo/  ', '/promo/' ],
			'empty stays empty'       => [ '', '' ],
			'root stays root'         => [ '/', '/' ],
			'query string stripped'   => [ '/promo/?campaign=fb', '/promo/' ],
			'fragment stripped'       => [ '/promo/#section', '/promo/' ],
		];
	}

	/**
	 * @dataProvider valid_url_cases
	 */
	public function test_is_valid_test_url_accepts( string $path ): void {
		$this->assertTrue( Admin::is_valid_test_url( $path ), "Expected valid: $path" );
	}

	public static function valid_url_cases(): array {
		return [
			[ '/promo/' ],
			[ '/landing-2026/' ],
			[ '/parent/child/' ],
			[ '/a/b/c/' ],
			[ '/with_underscore/' ],
			[ '/' ],
		];
	}

	/**
	 * @dataProvider invalid_url_cases
	 */
	public function test_is_valid_test_url_rejects( string $path ): void {
		$this->assertFalse( Admin::is_valid_test_url( $path ), "Expected invalid: $path" );
	}

	public static function invalid_url_cases(): array {
		return [
			'no leading slash'      => [ 'promo/' ],
			'no trailing slash'     => [ '/promo' ],
			'uppercase'             => [ '/Promo/' ],
			'spaces'                => [ '/with space/' ],
			'special chars'         => [ '/promo!/' ],
			'empty'                 => [ '' ],
			'just text'             => [ 'hello' ],
			'double slash inside'   => [ '/parent//child/' ],
		];
	}
}
