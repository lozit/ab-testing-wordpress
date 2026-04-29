<?php
/**
 * Unit tests for the consent gating helper.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Consent;
use PHPUnit\Framework\TestCase;

final class ConsentTest extends TestCase {

	protected function setUp(): void {
		// Reset the in-memory option + filter stores between tests.
		$GLOBALS['__abtest_options'] = [];
		$GLOBALS['__abtest_filters'] = [];
	}

	public function test_setting_off_never_blocks(): void {
		// Default abtest_settings has no require_consent key — treated as off.
		$this->assertFalse( Consent::is_blocked() );

		// Explicit off.
		update_option( 'abtest_settings', [ 'require_consent' => false ] );
		$this->assertFalse( Consent::is_blocked() );

		// Even if a banner says "no", we don't block when the toggle is off.
		add_filter( Consent::FILTER, '__return_false' );
		$this->assertFalse( Consent::is_blocked() );
	}

	public function test_setting_on_filter_true_does_not_block(): void {
		update_option( 'abtest_settings', [ 'require_consent' => true ] );
		add_filter( Consent::FILTER, '__return_true' );
		$this->assertFalse( Consent::is_blocked() );
	}

	public function test_setting_on_filter_false_blocks(): void {
		update_option( 'abtest_settings', [ 'require_consent' => true ] );
		add_filter( Consent::FILTER, '__return_false' );
		$this->assertTrue( Consent::is_blocked() );
	}

	public function test_setting_on_no_filter_blocks_by_default(): void {
		// Filter not wired → returns null → blocks (safe default).
		update_option( 'abtest_settings', [ 'require_consent' => true ] );
		$this->assertTrue( Consent::is_blocked() );
	}

	public function test_filter_returning_truthy_non_true_blocks(): void {
		// Strict comparison: only literal true is treated as consent. "yes",
		// 1, [], etc. are not enough — banner integrations should pass real bools.
		update_option( 'abtest_settings', [ 'require_consent' => true ] );
		add_filter( Consent::FILTER, fn() => 'yes' );
		$this->assertTrue( Consent::is_blocked() );

		$GLOBALS['__abtest_filters'] = [];
		add_filter( Consent::FILTER, fn() => 1 );
		$this->assertTrue( Consent::is_blocked() );
	}
}
