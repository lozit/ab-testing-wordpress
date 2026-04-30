<?php
/**
 * Unit tests for the URL-scoped settings store.
 *
 * @package Abtest\Tests
 */

namespace Abtest\Tests\Unit;

use Abtest\UrlSettings;
use PHPUnit\Framework\TestCase;

final class UrlSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__abtest_options'] = [];
	}

	public function test_get_returns_defaults_for_unknown_url(): void {
		$this->assertSame( [ 'noindex' => false ], UrlSettings::get( '/never-set/' ) );
	}

	public function test_get_returns_defaults_for_empty_url(): void {
		$this->assertSame( [ 'noindex' => false ], UrlSettings::get( '' ) );
	}

	public function test_set_then_get_roundtrip(): void {
		UrlSettings::set( '/promo/', [ 'noindex' => true ] );
		$this->assertSame( [ 'noindex' => true ], UrlSettings::get( '/promo/' ) );
	}

	public function test_url_is_normalized_on_both_set_and_get(): void {
		// Set with a non-canonical form, get with another non-canonical form — both
		// should hit the same normalized key.
		UrlSettings::set( 'PROMO', [ 'noindex' => true ] );
		$this->assertTrue( UrlSettings::is_noindex( '/promo/' ) );
		$this->assertTrue( UrlSettings::is_noindex( 'promo/' ) );
		$this->assertTrue( UrlSettings::is_noindex( '/PROMO/' ) );
	}

	public function test_set_default_value_prunes_the_entry(): void {
		// Step 1: set a non-default value.
		UrlSettings::set( '/promo/', [ 'noindex' => true ] );
		$stored = $GLOBALS['__abtest_options'][ UrlSettings::OPTION_KEY ] ?? [];
		$this->assertArrayHasKey( '/promo/', $stored );

		// Step 2: set back to default → the entry must disappear so the option stays compact.
		UrlSettings::set( '/promo/', [ 'noindex' => false ] );
		$stored = $GLOBALS['__abtest_options'][ UrlSettings::OPTION_KEY ] ?? [];
		$this->assertArrayNotHasKey( '/promo/', $stored );
	}

	public function test_each_url_is_independent(): void {
		UrlSettings::set( '/promo/', [ 'noindex' => true ] );
		UrlSettings::set( '/landing/', [ 'noindex' => false ] );
		$this->assertTrue( UrlSettings::is_noindex( '/promo/' ) );
		$this->assertFalse( UrlSettings::is_noindex( '/landing/' ) );
		$this->assertFalse( UrlSettings::is_noindex( '/other/' ) );
	}

	public function test_unknown_settings_keys_are_ignored(): void {
		// Future-proofing: extra keys passed to set() are silently dropped.
		UrlSettings::set( '/promo/', [ 'noindex' => true, 'unknown_future_flag' => 'whatever' ] );
		$got = UrlSettings::get( '/promo/' );
		$this->assertArrayNotHasKey( 'unknown_future_flag', $got );
		$this->assertTrue( $got['noindex'] );
	}
}
