<?php
/**
 * Plugin-provided "Blank Canvas" page template.
 *
 * Renders the post_content raw, with no theme wrapper, header(), footer(),
 * wp_head(), or wp_footer(). Designed for full HTML documents (DOCTYPE + head + body)
 * imported via the HTML Import screen.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest;

defined( 'ABSPATH' ) || exit;

final class Template {

	public const TEMPLATE_SLUG  = 'abtest-blank-canvas';
	public const TEMPLATE_LABEL = 'Blank Canvas (A/B)';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_filter( 'theme_page_templates', [ $this, 'register_in_dropdown' ] );
		add_filter( 'theme_post_templates', [ $this, 'register_in_dropdown' ] );
		add_filter( 'template_include', [ $this, 'maybe_use_template' ], 99 );
	}

	/**
	 * Adds our template option to the Page Attributes "Template" dropdown in the editor.
	 *
	 * @param array<string,string> $templates
	 * @return array<string,string>
	 */
	public function register_in_dropdown( array $templates ): array {
		$templates[ self::TEMPLATE_SLUG ] = self::TEMPLATE_LABEL;
		return $templates;
	}

	/**
	 * If the current post asked for our template, route the request to our PHP file
	 * instead of letting the theme decide.
	 */
	public function maybe_use_template( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $template;
		}
		$assigned = (string) get_post_meta( $post->ID, '_wp_page_template', true );
		if ( self::TEMPLATE_SLUG !== $assigned ) {
			return $template;
		}
		$path = ABTEST_PLUGIN_DIR . 'templates/blank-canvas.php';
		if ( ! is_readable( $path ) ) {
			return $template;
		}
		return $path;
	}
}
