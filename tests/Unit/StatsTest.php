<?php
/**
 * Unit tests for Stats::compute (pure logic, no DB).
 *
 * @package Abtest\Tests
 */

declare( strict_types=1 );

namespace Abtest\Tests\Unit;

use Abtest\Stats;
use PHPUnit\Framework\TestCase;

final class StatsTest extends TestCase {

	public function test_empty_counts_yield_zero_rates_and_no_significance(): void {
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => 0, 'conversions' => 0 ],
				'B' => [ 'impressions' => 0, 'conversions' => 0 ],
			]
		);
		$this->assertSame( 0.0, $out['A']['rate'] );
		$this->assertSame( 0.0, $out['B']['rate'] );
		$this->assertSame( 0.0, $out['lift'] );
		$this->assertFalse( $out['significant'] );
	}

	public function test_simple_rates_and_positive_lift(): void {
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 100 ], // 10%
				'B' => [ 'impressions' => 1000, 'conversions' => 150 ], // 15%
			]
		);
		$this->assertEqualsWithDelta( 0.10, $out['A']['rate'], 1e-9 );
		$this->assertEqualsWithDelta( 0.15, $out['B']['rate'], 1e-9 );
		$this->assertEqualsWithDelta( 0.50, $out['lift'], 1e-9 ); // (0.15-0.10)/0.10 = 50%
	}

	public function test_negative_lift_when_b_underperforms(): void {
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 200 ], // 20%
				'B' => [ 'impressions' => 1000, 'conversions' => 150 ], // 15%
			]
		);
		$this->assertEqualsWithDelta( -0.25, $out['lift'], 1e-9 );
	}

	public function test_strong_signal_is_significant(): void {
		// Large sample, clear difference: should be significant.
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => 5000, 'conversions' => 250 ],  // 5%
				'B' => [ 'impressions' => 5000, 'conversions' => 400 ],  // 8%
			]
		);
		$this->assertTrue( $out['significant'] );
		$this->assertLessThan( 0.05, $out['p_value'] );
	}

	public function test_weak_signal_is_not_significant(): void {
		// Tiny difference, small sample: should not be significant.
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => 100, 'conversions' => 10 ], // 10%
				'B' => [ 'impressions' => 100, 'conversions' => 11 ], // 11%
			]
		);
		$this->assertFalse( $out['significant'] );
		$this->assertGreaterThan( 0.05, $out['p_value'] );
	}

	public function test_p_value_is_two_sided(): void {
		// Symmetric extreme cases should yield same p-value.
		$above = Stats::compute(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 100 ],
				'B' => [ 'impressions' => 1000, 'conversions' => 200 ],
			]
		);
		$below = Stats::compute(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 200 ],
				'B' => [ 'impressions' => 1000, 'conversions' => 100 ],
			]
		);
		$this->assertEqualsWithDelta( $above['p_value'], $below['p_value'], 1e-9 );
	}

	public function test_negative_inputs_are_clamped(): void {
		$out = Stats::compute(
			[
				'A' => [ 'impressions' => -10, 'conversions' => -5 ],
				'B' => [ 'impressions' => 100, 'conversions' => 10 ],
			]
		);
		$this->assertSame( 0, $out['A']['impressions'] );
		$this->assertSame( 0, $out['A']['conversions'] );
	}
}
