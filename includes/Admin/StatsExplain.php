<?php
/**
 * StatsExplain — produces a contextual, plain-language reason explaining
 * WHY a given experiment doesn't have a winner declared. Surfaced as a
 * tooltip on the "No winner (α=…)" badge in the experiments list.
 *
 * Pure function : no DB access, no globals — easy to unit-test.
 *
 * @package Abtest
 */

namespace Abtest\Admin;

defined( 'ABSPATH' ) || exit;

final class StatsExplain {

	/** Below this many impressions on the smallest variant, the test is considered underpowered. */
	private const MIN_IMP_PER_VARIANT = 200;

	/** A running experiment with fewer days than this is considered "too early" regardless of stats. */
	private const MIN_DAYS_RUNNING_FOR_DECISION = 14;

	/** When the best p-value is within this multiple of α, the test is "borderline" and worth continuing. */
	private const BORDERLINE_FACTOR = 2.0;

	/** Relative spread between min and max conversion rate (in % of min) below which we say "no real effect". */
	private const FLAT_EFFECT_THRESHOLD = 0.15;

	/**
	 * Returns a one-line explanation in French (UI language) suitable for a tooltip.
	 * Used only when no winner is declared.
	 *
	 * @param array  $multi      Output of Stats::compute_multi() — needs 'variants', 'comparisons', 'alpha'.
	 * @param string $status     Experiment status (running, ended, paused, draft).
	 * @param string $started_at Mysql GMT datetime ('YYYY-MM-DD HH:MM:SS') or '' if not started.
	 * @param int    $now        Optional epoch override for tests (defaults to time()).
	 */
	public static function no_winner_reason( array $multi, string $status, string $started_at, ?int $now = null ): string {
		$now      = $now ?? time();
		$variants = isset( $multi['variants'] ) && is_array( $multi['variants'] ) ? $multi['variants'] : [];
		$cmps     = isset( $multi['comparisons'] ) && is_array( $multi['comparisons'] ) ? $multi['comparisons'] : [];
		$alpha    = isset( $multi['alpha'] ) ? (float) $multi['alpha'] : 0.05;

		// Edge : no comparisons at all (baseline-only experiment) — shouldn't show "No winner" but be safe.
		if ( empty( $cmps ) || empty( $variants ) ) {
			return __( 'Baseline experiment — there is no second variant to compare against.', 'ab-testing-wordpress' );
		}

		$impressions      = array_map( static fn( $v ) => (int) ( $v['impressions'] ?? 0 ), $variants );
		$min_impressions  = min( $impressions );
		$total_imp        = array_sum( $impressions );

		// (1) Running for less than 2 weeks → too early, don't even discuss the numbers.
		if ( 'running' === $status && '' !== $started_at ) {
			$started_ts = strtotime( $started_at . ' UTC' );
			if ( $started_ts ) {
				$days = (int) floor( ( $now - $started_ts ) / DAY_IN_SECONDS );
				if ( $days < self::MIN_DAYS_RUNNING_FOR_DECISION ) {
					return sprintf(
						/* translators: %d: days running */
						__( 'Trop tôt pour décider (%d jours). En général il faut 2 à 4 semaines pour atteindre une significance fiable sur un site à trafic modéré. Patience.', 'ab-testing-wordpress' ),
						$days
					);
				}
			}
		}

		// (2) Tiny sample on at least one variant → underpowered, no matter the lift observed.
		if ( $min_impressions < self::MIN_IMP_PER_VARIANT ) {
			return sprintf(
				/* translators: 1: smallest variant impressions, 2: typical threshold */
				__( 'Échantillon trop petit (%1$d impressions sur la variante la moins vue, %2$d minimum recommandé). Avec si peu de visiteurs on ne peut pas distinguer un vrai gain d\'un coup de chance. Continuer à collecter ou élargir le trafic.', 'ab-testing-wordpress' ),
				$min_impressions,
				self::MIN_IMP_PER_VARIANT
			);
		}

		// (3) Find the best (lowest p-value) comparison.
		$best_p     = 1.0;
		$best_label = '';
		foreach ( $cmps as $label => $cmp ) {
			$p = isset( $cmp['p_value'] ) ? (float) $cmp['p_value'] : 1.0;
			if ( $p < $best_p ) {
				$best_p     = $p;
				$best_label = (string) $label;
			}
		}

		// (4) Borderline : the best comparison is within 2× α of the threshold.
		if ( $best_p < $alpha * self::BORDERLINE_FACTOR ) {
			return sprintf(
				/* translators: 1: variant label, 2: p-value, 3: alpha threshold */
				__( 'Variante %1$s très proche du seuil (p=%2$.3f vs α=%3$.3f). Quelques semaines de plus de collecte suffiront probablement à trancher.', 'ab-testing-wordpress' ),
				$best_label,
				$best_p,
				$alpha
			);
		}

		// (5) Rates are too close to each other → genuine null result, the change doesn't matter.
		$rates = array_map(
			static function ( $v ) {
				$imp = (int) ( $v['impressions'] ?? 0 );
				return $imp > 0 ? (int) ( $v['conversions'] ?? 0 ) / $imp : 0.0;
			},
			$variants
		);
		$max_rate = max( $rates );
		$min_rate = min( $rates );
		$relative = $min_rate > 0 ? ( $max_rate - $min_rate ) / $min_rate : 0.0;

		if ( $relative < self::FLAT_EFFECT_THRESHOLD ) {
			return sprintf(
				/* translators: %s: relative spread percentage */
				__( 'Aucune différence détectable entre les variantes (toutes dans une fourchette de ±%s). Ce changement n\'a probablement pas d\'effet — passer au test suivant.', 'ab-testing-wordpress' ),
				number_format_i18n( $relative * 100, 1 ) . '%'
			);
		}

		// (6) Generic fallback — observed difference, not enough evidence.
		return sprintf(
			/* translators: 1: best p-value, 2: alpha threshold */
			__( 'Différence observée mais pas assez nette pour trancher (meilleur p=%1$.3f, il faudrait p<%2$.3f). Continuer le test ou augmenter le trafic.', 'ab-testing-wordpress' ),
			$best_p,
			$alpha
		);
	}
}
