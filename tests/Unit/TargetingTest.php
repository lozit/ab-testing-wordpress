<?php
/**
 * Unit tests for the User-Agent → device classifier.
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Targeting;
use PHPUnit\Framework\TestCase;

final class TargetingTest extends TestCase {

	/** @dataProvider mobile_uas */
	public function test_classifies_mobile( string $ua ): void {
		$this->assertSame( 'mobile', Targeting::device_from_ua( $ua ) );
	}

	public static function mobile_uas(): array {
		return [
			'iPhone Safari'    => [ 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' ],
			'Android phone'    => [ 'Mozilla/5.0 (Linux; Android 14; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36' ],
			'iPod'             => [ 'Mozilla/5.0 (iPod touch; CPU iPhone OS 14_0 like Mac OS X) Mobile/18A373' ],
			'Windows Phone'    => [ 'Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Mobile Safari/537.36 Edge/15.15063' ],
		];
	}

	/** @dataProvider tablet_uas */
	public function test_classifies_tablet( string $ua ): void {
		$this->assertSame( 'tablet', Targeting::device_from_ua( $ua ) );
	}

	public static function tablet_uas(): array {
		return [
			'iPad'                => [ 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1' ],
			'Android tablet'      => [ 'Mozilla/5.0 (Linux; Android 14; SM-X800) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ],
			'Generic tablet UA'   => [ 'Mozilla/5.0 (Linux; Tablet; rv:118.0) Gecko/118.0 Firefox/118.0' ],
		];
	}

	/** @dataProvider desktop_uas */
	public function test_classifies_desktop( string $ua ): void {
		$this->assertSame( 'desktop', Targeting::device_from_ua( $ua ) );
	}

	public static function desktop_uas(): array {
		return [
			'Mac Safari'    => [ 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15' ],
			'Windows Chrome' => [ 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' ],
			'Linux Firefox' => [ 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:118.0) Gecko/20100101 Firefox/118.0' ],
		];
	}

	public function test_empty_ua_falls_back_to_desktop(): void {
		$this->assertSame( 'desktop', Targeting::device_from_ua( '' ) );
	}

	public function test_country_from_cf_header(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'FR';
		$this->assertSame( 'FR', Targeting::current_country() );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_country_xx_is_unknown(): void {
		$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
		$this->assertSame( '', Targeting::current_country() );
		unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	}

	public function test_country_falls_back_to_filter(): void {
		// Wire a one-shot filter for this test.
		$cb = static function () { return 'BE'; };
		add_filter( 'abtest_visitor_country', $cb );
		$this->assertSame( 'BE', Targeting::current_country() );
		remove_filter( 'abtest_visitor_country', $cb );
	}

	public function test_country_filter_validates_format(): void {
		$cb = static function () { return 'too-long-and-bad'; };
		add_filter( 'abtest_visitor_country', $cb );
		$this->assertSame( '', Targeting::current_country() );
		remove_filter( 'abtest_visitor_country', $cb );
	}
}
