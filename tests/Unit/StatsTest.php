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

	// ----- compute_multi() — N variants, pairwise vs baseline, Bonferroni -----

	public function test_compute_multi_returns_per_variant_rates(): void {
		$out = Stats::compute_multi(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 50 ],
				'B' => [ 'impressions' => 1000, 'conversions' => 80 ],
				'C' => [ 'impressions' => 1000, 'conversions' => 65 ],
			],
			[ 'A', 'B', 'C' ]
		);
		$this->assertEqualsWithDelta( 0.05,  $out['variants']['A']['rate'], 1e-9 );
		$this->assertEqualsWithDelta( 0.08,  $out['variants']['B']['rate'], 1e-9 );
		$this->assertEqualsWithDelta( 0.065, $out['variants']['C']['rate'], 1e-9 );
		$this->assertSame( 'A', $out['baseline'] );
	}

	public function test_compute_multi_pairwise_comparisons_only_for_non_baseline(): void {
		$out = Stats::compute_multi(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 50 ],
				'B' => [ 'impressions' => 1000, 'conversions' => 80 ],
				'C' => [ 'impressions' => 1000, 'conversions' => 65 ],
			],
			[ 'A', 'B', 'C' ]
		);
		$this->assertArrayHasKey( 'B', $out['comparisons'] );
		$this->assertArrayHasKey( 'C', $out['comparisons'] );
		$this->assertArrayNotHasKey( 'A', $out['comparisons'] ); // baseline never compared to itself
		$this->assertSame( 'A', $out['comparisons']['B']['vs'] );
		$this->assertSame( 'A', $out['comparisons']['C']['vs'] );
	}

	public function test_compute_multi_applies_bonferroni_correction(): void {
		// 3 variants → 2 comparisons → alpha = 0.05 / 2 = 0.025
		$out = Stats::compute_multi(
			[
				'A' => [ 'impressions' => 1000, 'conversions' => 50 ],
				'B' => [ 'impressions' => 1000, 'conversions' => 80 ],
				'C' => [ 'impressions' => 1000, 'conversions' => 65 ],
			],
			[ 'A', 'B', 'C' ]
		);
		$this->assertEqualsWithDelta( 0.025, $out['alpha'], 1e-9 );

		// 4 variants → 3 comparisons → alpha = 0.05 / 3 ≈ 0.0167
		$out4 = Stats::compute_multi(
			[
				'A' => [ 'impressions' => 100, 'conversions' => 5 ],
				'B' => [ 'impressions' => 100, 'conversions' => 6 ],
				'C' => [ 'impressions' => 100, 'conversions' => 7 ],
				'D' => [ 'impressions' => 100, 'conversions' => 8 ],
			],
			[ 'A', 'B', 'C', 'D' ]
		);
		$this->assertEqualsWithDelta( 0.05 / 3, $out4['alpha'], 1e-9 );
	}

	public function test_compute_multi_picks_best_only_among_significant_variants(): void {
		// Strong, clearly significant signal for B (n=5000 each, A=5%, B=8%).
		// C has high rate but tiny sample → not significant even at alpha=0.025.
		$out = Stats::compute_multi(
			[
				'A' => [ 'impressions' => 5000, 'conversions' => 250 ],   // 5%
				'B' => [ 'impressions' => 5000, 'conversions' => 400 ],   // 8% — should pass Bonferroni-corrected p
				'C' => [ 'impressions' => 25,   'conversions' => 3 ],     // 12% but n=25 is way too small
			],
			[ 'A', 'B', 'C' ]
		);
		$this->assertTrue( $out['comparisons']['B']['significant'] );
		$this->assertFalse( $out['comparisons']['C']['significant'] );
		$this->assertSame( 'B', $out['best'] );
	}

	public function test_compute_multi_baseline_only_returns_no_comparisons(): void {
		$out = Stats::compute_multi(
			[ 'A' => [ 'impressions' => 1000, 'conversions' => 50 ] ],
			[ 'A' ]
		);
		$this->assertSame( 'A', $out['baseline'] );
		$this->assertNull( $out['best'] );
		$this->assertEmpty( $out['comparisons'] );
		$this->assertCount( 1, $out['variants'] );
	}
}
