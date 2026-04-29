<?php
/**
 * Unit tests for the WPML/Polylang language-prefix stripper.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\MultiLanguage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MultiLanguageTest extends TestCase {

	protected function setUp(): void {
		MultiLanguage::reset_cache();
	}

	/**
	 * Force the cached language list (skip plugin-detection plumbing for unit tests).
	 *
	 * @param string[] $languages
	 */
	private function force_languages( array $languages ): void {
		// ReflectionProperty::setValue on a private static — accessible without
		// setAccessible() since PHP 8.1 (which is our minimum).
		( new ReflectionProperty( MultiLanguage::class, 'languages_cache' ) )->setValue( null, $languages );
	}

	public function test_returns_path_unchanged_when_no_languages_active(): void {
		$this->force_languages( [] );
		$this->assertSame( '/promo/', MultiLanguage::strip_language_prefix( '/promo/' ) );
		$this->assertSame( '/fr/promo/', MultiLanguage::strip_language_prefix( '/fr/promo/' ), 'Without active languages, /fr/ stays — could be a real page slug.' );
	}

	public function test_strips_known_prefix(): void {
		$this->force_languages( [ 'fr', 'en', 'de' ] );
		$this->assertSame( '/promo/', MultiLanguage::strip_language_prefix( '/fr/promo/' ) );
		$this->assertSame( '/landing/page/', MultiLanguage::strip_language_prefix( '/de/landing/page/' ) );
	}

	public function test_keeps_unknown_prefix(): void {
		$this->force_languages( [ 'fr', 'en' ] );
		// `de` not in the active list → it's a real path segment.
		$this->assertSame( '/de/promo/', MultiLanguage::strip_language_prefix( '/de/promo/' ) );
	}

	public function test_handles_compound_language_slugs(): void {
		$this->force_languages( [ 'pt-br', 'en-us', 'fr' ] );
		$this->assertSame( '/promo/', MultiLanguage::strip_language_prefix( '/pt-br/promo/' ) );
		$this->assertSame( '/promo/', MultiLanguage::strip_language_prefix( '/en-us/promo/' ) );
	}

	public function test_strips_only_at_leading_position(): void {
		$this->force_languages( [ 'fr' ] );
		// `/fr/` appearing later in the path is just a segment, not a prefix.
		$this->assertSame( '/blog/fr/promo/', MultiLanguage::strip_language_prefix( '/blog/fr/promo/' ) );
	}

	public function test_preserves_query_string(): void {
		$this->force_languages( [ 'fr' ] );
		$this->assertSame( '/promo/?campaign=fb', MultiLanguage::strip_language_prefix( '/fr/promo/?campaign=fb' ) );
	}

	public function test_root_language_url_collapses_to_root(): void {
		$this->force_languages( [ 'fr' ] );
		// /fr/ → / (the language home)
		$this->assertSame( '/', MultiLanguage::strip_language_prefix( '/fr/' ) );
	}

	public function test_idempotent_on_already_stripped_path(): void {
		$this->force_languages( [ 'fr' ] );
		$first  = MultiLanguage::strip_language_prefix( '/fr/promo/' );
		$second = MultiLanguage::strip_language_prefix( $first );
		$this->assertSame( '/promo/', $first );
		$this->assertSame( '/promo/', $second );
	}

	public function test_non_string_returns_string_cast(): void {
		$this->force_languages( [ 'fr' ] );
		$this->assertSame( '', MultiLanguage::strip_language_prefix( null ) );
	}
}
