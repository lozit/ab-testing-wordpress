<?php
/**
 * HelpTabs — registers WordPress's native contextual help on the A/B Tests
 * admin screens. Visible via the "Help" pull-down at the top-right of every
 * wp-admin page.
 *
 * Goal : non-statisticians who install the plugin should find a friendly
 * primer on p-value / α / Bonferroni / sample-size, plus a quick-start guide,
 * without leaving the admin.
 *
 * @package Abtest
 */

namespace Abtest\Admin;

defined( 'ABSPATH' ) || exit;

final class HelpTabs {

	public static function register(): void {
		add_action( 'current_screen', [ self::class, 'maybe_attach' ] );
	}

	public static function maybe_attach( \WP_Screen $screen ): void {
		if ( ! self::is_abtest_screen( $screen ) ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'      => 'abtest-quick-start',
				'title'   => __( 'Quick start', 'ab-testing-wordpress' ),
				'content' => self::tab_quick_start(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-stats',
				'title'   => __( 'Stats expliquées', 'ab-testing-wordpress' ),
				'content' => self::tab_stats(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-multi',
				'title'   => __( 'Multi-variantes', 'ab-testing-wordpress' ),
				'content' => self::tab_multi(),
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'abtest-privacy',
				'title'   => __( 'Privacy & RGPD', 'ab-testing-wordpress' ),
				'content' => self::tab_privacy(),
			]
		);

		$screen->set_help_sidebar( self::sidebar() );
	}

	private static function is_abtest_screen( \WP_Screen $screen ): bool {
		// Our admin pages all live under the `ab-testing` menu slug; the screen ID
		// looks like 'toplevel_page_ab-testing' or 'a-b-tests_page_…' depending on
		// nesting. Match anything that contains 'ab-testing'.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only screen detection, no mutation
		return false !== strpos( (string) $screen->id, 'ab-testing' )
			|| ( isset( $_GET['page'] ) && 0 === strpos( (string) wp_unslash( $_GET['page'] ), 'ab-testing' ) );
		// phpcs:enable
	}

	private static function tab_quick_start(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Lancer votre premier A/B test en 3 minutes', 'ab-testing-wordpress' ); ?></h3>
		<ol>
			<li><strong><?php esc_html_e( 'Préparez 2 pages WordPress', 'ab-testing-wordpress' ); ?></strong> — la version actuelle (Variant A) et la nouvelle (Variant B). Statut <em>Privé</em> recommandé : le plugin les sert quand-même via l'URL test.</li>
			<li><strong><?php esc_html_e( 'A/B Tests → Add new', 'ab-testing-wordpress' ); ?></strong> : choisissez l'URL de test (ex. <code>/landing/</code>), sélectionnez les 2 variantes, définissez l'objectif (URL atteinte ou clic sur sélecteur CSS).</li>
			<li><strong><?php esc_html_e( 'Cliquez "Save & Start"', 'ab-testing-wordpress' ); ?></strong> — les visiteurs sont répartis 50/50 par cookie persistant. Vous voyez les stats en temps réel sur la liste principale.</li>
		</ol>

		<h3><?php esc_html_e( 'Importer une page existante (HTML)', 'ab-testing-wordpress' ); ?></h3>
		<p><?php esc_html_e( 'Vous avez une landing page existante en HTML brut ? Allez dans Import HTML et déposez votre fichier .html, .htm ou .zip (avec assets CSS/JS/images). Le plugin l\'importe avec un thème "Blank Canvas" (rendu byte-perfect, zéro wrapper WordPress).', 'ab-testing-wordpress' ); ?></p>

		<h3><?php esc_html_e( 'Workflow de dev avec un IDE', 'ab-testing-wordpress' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: code path */
			esc_html__( 'Éditez vos pages directement sous %s — la commande Watch Directory (cron 5 min) sync vos changements en pages WordPress sans intervention.', 'ab-testing-wordpress' ),
			'<code>wp-content/uploads/abtest-templates/{slug}/index.html</code>'
		);
		?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_stats(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Pourquoi parfois "No winner" alors qu\'une variante a l\'air meilleure ?', 'ab-testing-wordpress' ); ?></h3>
		<p><?php esc_html_e( 'Le plugin ne déclare pas un winner uniquement parce qu\'une variante a un meilleur taux de conversion brut. Il fait un test statistique (z-test à 2 proportions) pour répondre à la question : "cette différence est-elle assez nette pour ne pas être due au hasard ?". Tant qu\'on ne peut pas l\'affirmer, aucun winner n\'est annoncé.', 'ab-testing-wordpress' ); ?></p>

		<h3><?php esc_html_e( 'Le vocabulaire en 4 mots', 'ab-testing-wordpress' ); ?></h3>
		<dl>
			<dt><strong>p-value</strong></dt>
			<dd><?php esc_html_e( 'Probabilité que la différence observée entre A et B soit du pur hasard. Plus c\'est petit, plus c\'est solide. p=0.02 = "il n\'y a que 2% de chances que ce soit un coup de chance".', 'ab-testing-wordpress' ); ?></dd>

			<dt><strong>α (alpha)</strong></dt>
			<dd><?php esc_html_e( 'Votre seuil de tolérance au faux positif, fixé à l\'avance. Standard CRO : 0.05 (5%). Si p < α → on déclare un winner. Si p > α → "pas assez de preuves", on ne tranche pas.', 'ab-testing-wordpress' ); ?></dd>

			<dt><strong>Lift</strong></dt>
			<dd><?php esc_html_e( 'Différence relative de taux de conversion entre la variante et la baseline. B à 7.5% vs A à 5% → lift de +50%.', 'ab-testing-wordpress' ); ?></dd>

			<dt><strong>95% CI (Confidence Interval)</strong></dt>
			<dd><?php esc_html_e( 'La fourchette dans laquelle se trouve probablement le vrai lift. Lift = +50% [10% ; 90%] signifie "on est sûr à 95% que le vrai gain est entre +10% et +90%". Plus la fourchette est étroite, plus la mesure est précise.', 'ab-testing-wordpress' ); ?></dd>
		</dl>

		<h3><?php esc_html_e( 'Les 4 raisons fréquentes de "No winner"', 'ab-testing-wordpress' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Trop tôt', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'le test tourne depuis quelques jours seulement. Sur un site à 1000 visites/mois il faut typiquement 2 à 4 semaines pour atteindre la significance.', 'ab-testing-wordpress' ); ?></li>
			<li><strong><?php esc_html_e( 'Trop peu d\'échantillons', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'avec 100 visiteurs par variante, même un lift de +50% peut être un coup de chance. Visez 500+ par variante pour pouvoir détecter un lift de +30%.', 'ab-testing-wordpress' ); ?></li>
			<li><strong><?php esc_html_e( 'Vrai null result', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'A et B convertissent pareil. Le changement testé n\'a pas d\'effet réel. C\'est une info utile : passez à autre chose.', 'ab-testing-wordpress' ); ?></li>
			<li><strong><?php esc_html_e( 'Borderline', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'p légèrement au-dessus de α (ex. 0.06). Continuer 1-2 semaines de plus suffit souvent à trancher.', 'ab-testing-wordpress' ); ?></li>
		</ul>
		<p><em><?php esc_html_e( 'Survolez le badge "No winner (α=…)" sur la liste principale pour voir laquelle de ces raisons s\'applique à votre test.', 'ab-testing-wordpress' ); ?></em></p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_multi(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Tester plus de 2 variantes (A/B/C/D)', 'ab-testing-wordpress' ); ?></h3>
		<p><?php esc_html_e( 'Le plugin supporte jusqu\'à 4 variantes simultanées (A, B, C, D) sur une même URL. Le trafic est réparti équitablement (1/N par variante).', 'ab-testing-wordpress' ); ?></p>

		<h3><?php esc_html_e( 'Pourquoi α est plus strict en multi-variantes (Bonferroni)', 'ab-testing-wordpress' ); ?></h3>
		<p><?php esc_html_e( 'Quand vous comparez plusieurs variantes contre la baseline en parallèle, vous multipliez les occasions d\'avoir un faux positif. Pour rester aussi prudent qu\'en A/B simple, le plugin applique la correction de Bonferroni :', 'ab-testing-wordpress' ); ?></p>
		<pre style="background:#f6f7f7;padding:8px;border-radius:4px;">α corrigé = α global / nombre de comparaisons</pre>
		<table class="widefat striped" style="max-width:500px;">
			<thead><tr><th>Variantes</th><th>Comparaisons</th><th>α effectif</th></tr></thead>
			<tbody>
				<tr><td>A/B</td><td>1</td><td>0.050</td></tr>
				<tr><td>A/B/C</td><td>2</td><td>0.025</td></tr>
				<tr><td>A/B/C/D</td><td>3</td><td>0.017</td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Conséquence pratique : avec 3 ou 4 variantes, il faut 2 à 3 fois plus de visiteurs pour atteindre la significance. Préférez l\'A/B simple sauf si vous avez une vraie raison de tester plusieurs variantes en parallèle.', 'ab-testing-wordpress' ); ?></p>
		<?php
		return (string) ob_get_clean();
	}

	private static function tab_privacy(): string {
		ob_start();
		?>
		<h3><?php esc_html_e( 'Ce que le plugin stocke (et ne stocke pas)', 'ab-testing-wordpress' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Cookies', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'un cookie par expérience nommé `abtest_{ID}`, valeur = lettre de variante (a/b/c/d), 30 jours, HttpOnly + SameSite=Lax + Secure.', 'ab-testing-wordpress' ); ?></li>
			<li><strong><?php esc_html_e( 'visitor_hash', 'ab-testing-wordpress' ); ?></strong> — <?php esc_html_e( 'hash SHA-256 tronqué à 16 chars (64 bits) de IP+User-Agent+sel. Non-réversible, single-site, jamais d\'IP/UA brut stocké.', 'ab-testing-wordpress' ); ?></li>
			<li><strong><?php esc_html_e( 'Aucun', 'ab-testing-wordpress' ); ?></strong> : <?php esc_html_e( 'email, nom, identifiant utilisateur, cookie tiers, tracker cross-site.', 'ab-testing-wordpress' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Activer le consentement (RGPD)', 'ab-testing-wordpress' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: filter name */
			esc_html__( 'Settings → "Require visitor consent" + branchez votre bandeau via le filtre %s. Sans consentement, le visiteur voit silencieusement la baseline (zéro cookie, zéro tracking). Snippets prêts pour Complianz / CookieYes / Cookiebot dans le README.', 'ab-testing-wordpress' ),
			'<code>abtest_visitor_has_consent</code>'
		);
		?>
		</p>

		<h3><?php esc_html_e( 'Politique de confidentialité', 'ab-testing-wordpress' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: file name */
			esc_html__( 'Texte prêt à coller : Settings → Privacy → Policy Guide → "AB Testing WordPress" (généré automatiquement par le plugin). Détail complet du modèle de menace dans %s sur GitHub.', 'ab-testing-wordpress' ),
			'<code>SECURITY.md</code>'
		);
		?>
		</p>
		<?php
		return (string) ob_get_clean();
	}

	private static function sidebar(): string {
		ob_start();
		?>
		<p><strong><?php esc_html_e( 'Aller plus loin', 'ab-testing-wordpress' ); ?></strong></p>
		<p><a href="https://github.com/lozit/ab-testing-wordpress" target="_blank" rel="noopener">GitHub README</a></p>
		<p><a href="https://github.com/lozit/ab-testing-wordpress/blob/main/SECURITY.md" target="_blank" rel="noopener">Security policy</a></p>
		<p><a href="https://github.com/lozit/ab-testing-wordpress/blob/main/docs/security/latest.md" target="_blank" rel="noopener">Latest security audit</a></p>
		<?php
		return (string) ob_get_clean();
	}
}
