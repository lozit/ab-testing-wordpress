<?php
/**
 * Unit tests for StatsExplain — covers each "no winner" reason branch.
 *
 * @package Abtest\Tests
 */

namespace Abtest\Tests\Unit;

use Abtest\Admin\StatsExplain;
use PHPUnit\Framework\TestCase;

final class StatsExplainTest extends TestCase {

	private function multi( array $variants, array $comparisons, float $alpha = 0.05 ): array {
		return [
			'variants'    => $variants,
			'comparisons' => $comparisons,
			'alpha'       => $alpha,
		];
	}

	public function test_running_under_two_weeks_is_too_early(): void {
		$multi = $this->multi(
			[
				'A' => [ 'impressions' => 500, 'conversions' => 25 ],
				'B' => [ 'impressions' => 500, 'conversions' => 30 ],
			],
			[ 'B' => [ 'p_value' => 0.4, 'lift' => 0.2, 'significant' => false ] ]
		);
		$now    = strtotime( '2026-04-30 12:00:00 UTC' );
		$started = '2026-04-25 09:00:00'; // ~5 days ago
		$reason  = StatsExplain::no_winner_reason( $multi, 'running', $started, $now );
		$this->assertStringContainsString( 'Trop tôt', $reason );
		$this->assertStringContainsString( '5 jours', $reason );
	}

	public function test_small_sample_is_underpowered(): void {
		// Even with a huge observed lift, n < 200 is the dominant explanation.
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 100, 'conversions' => 5 ],
				'B' => [ 'impressions' => 95,  'conversions' => 12 ],
			],
			[ 'B' => [ 'p_value' => 0.08, 'lift' => 1.5, 'significant' => false ] ]
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'ended', '2026-01-01 09:00:00' );
		$this->assertStringContainsString( 'trop petit', $reason );
		$this->assertStringContainsString( '95', $reason ); // smallest variant
	}

	public function test_borderline_p_value_just_above_alpha(): void {
		// p = 0.055, α = 0.05 → within 2× α → "borderline, continue".
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 800, 'conversions' => 40 ],
				'B' => [ 'impressions' => 800, 'conversions' => 56 ],
			],
			[ 'B' => [ 'p_value' => 0.055, 'lift' => 0.4, 'significant' => false ] ]
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'ended', '2026-01-01 09:00:00' );
		$this->assertStringContainsString( 'proche du seuil', $reason );
		$this->assertStringContainsString( 'B', $reason );
	}

	public function test_flat_effect_is_genuine_null_result(): void {
		// All rates within ~5% of each other, p far above α → "no real effect".
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 500, 'conversions' => 25 ],
				'B' => [ 'impressions' => 500, 'conversions' => 26 ],
			],
			[ 'B' => [ 'p_value' => 0.85, 'lift' => 0.04, 'significant' => false ] ]
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'ended', '2026-01-01 09:00:00' );
		$this->assertStringContainsString( 'Aucune différence', $reason );
	}

	public function test_generic_fallback_observed_but_inconclusive(): void {
		// Sample big enough, p well above α, lift > 15% — generic message.
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 600, 'conversions' => 30 ],
				'B' => [ 'impressions' => 600, 'conversions' => 42 ],
			],
			[ 'B' => [ 'p_value' => 0.18, 'lift' => 0.4, 'significant' => false ] ]
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'ended', '2026-01-01 09:00:00' );
		$this->assertStringContainsString( 'Différence observée', $reason );
		$this->assertStringContainsString( '0.180', $reason );
	}

	public function test_baseline_only_experiment(): void {
		$multi  = $this->multi(
			[ 'A' => [ 'impressions' => 500, 'conversions' => 25 ] ],
			[]
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'running', '2026-04-01 09:00:00' );
		$this->assertStringContainsString( 'Baseline', $reason );
	}

	public function test_running_for_long_falls_through_early_check(): void {
		// 30 days running → no longer "too early" — should hit downstream branches.
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 800, 'conversions' => 40 ],
				'B' => [ 'impressions' => 800, 'conversions' => 56 ],
			],
			[ 'B' => [ 'p_value' => 0.055, 'lift' => 0.4, 'significant' => false ] ]
		);
		$now     = strtotime( '2026-04-30 12:00:00 UTC' );
		$started = '2026-03-25 09:00:00'; // ~36 days ago
		$reason  = StatsExplain::no_winner_reason( $multi, 'running', $started, $now );
		$this->assertStringNotContainsString( 'Trop tôt', $reason );
		$this->assertStringContainsString( 'proche du seuil', $reason );
	}

	public function test_multivariant_uses_corrected_alpha(): void {
		// α = 0.025 (Bonferroni for 3 variants). p = 0.04 is no longer borderline (it's above 2×α=0.05).
		$multi  = $this->multi(
			[
				'A' => [ 'impressions' => 800, 'conversions' => 40 ],
				'B' => [ 'impressions' => 800, 'conversions' => 50 ],
				'C' => [ 'impressions' => 800, 'conversions' => 56 ],
			],
			[
				'B' => [ 'p_value' => 0.30, 'lift' => 0.25, 'significant' => false ],
				'C' => [ 'p_value' => 0.04, 'lift' => 0.40, 'significant' => false ],
			],
			0.025
		);
		$reason = StatsExplain::no_winner_reason( $multi, 'ended', '2026-01-01 09:00:00' );
		$this->assertStringContainsString( 'C', $reason );
		// p=0.04 < 2×α=0.05 → borderline message expected.
		$this->assertStringContainsString( 'proche du seuil', $reason );
	}
}
