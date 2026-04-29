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
			'query string kept'       => [ '/promo/?campaign=fb', '/promo/?campaign=fb' ],
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
			'malformed query (no =)' => [ '/promo/?broken' ],
			'malformed query (no value)' => [ '/promo/?key=' ],
		];
	}

	// ----- Query string normalization & matching -----

	public function test_normalize_keeps_query_with_sorted_params(): void {
		// Same params in different order → same canonical form.
		$a = Experiment::normalize_path( '/promo/?b=2&a=1' );
		$b = Experiment::normalize_path( '/promo/?a=1&b=2' );
		$this->assertSame( '/promo/?a=1&b=2', $a );
		$this->assertSame( $a, $b );
	}

	public function test_normalize_strips_fragment(): void {
		$this->assertSame( '/promo/?a=1', Experiment::normalize_path( '/promo/?a=1#section' ) );
	}

	public function test_path_only_extracts_correctly(): void {
		$this->assertSame( '/promo/', Experiment::path_only( '/promo/?a=1&b=2' ) );
		$this->assertSame( '/promo/', Experiment::path_only( '/promo/' ) );
	}

	public function test_query_params_extracts_assoc(): void {
		$this->assertSame(
			[ 'a' => '1', 'b' => '2' ],
			Experiment::query_params( '/promo/?a=1&b=2' )
		);
		$this->assertSame( [], Experiment::query_params( '/promo/' ) );
	}

	public function test_unicode_lowercased_via_mb(): void {
		$this->assertSame( '/promotion-été/', Experiment::normalize_path( '/PROMOTION-ÉTÉ/' ) );
	}
}
