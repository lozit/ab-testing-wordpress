<?php
/**
 * HTML Import sub-page — upload a complete HTML document into a WordPress page
 * with the Blank Canvas template assigned.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Template;

defined( 'ABSPATH' ) || exit;

final class HtmlImport {

	public const NONCE        = 'abtest_import_html';
	public const ALLOWED_EXTS = [ 'html', 'htm' ];

	/**
	 * Maximum upload size in bytes. Default 5 MiB.
	 * Filterable via 'abtest_html_import_max_bytes'. Capped by PHP's upload_max_filesize.
	 */
	public static function max_bytes(): int {
		$default = 5 * 1048576;
		$filtered = (int) apply_filters( 'abtest_html_import_max_bytes', $default );
		$php_limit = wp_max_upload_size();
		return min( $filtered, $php_limit > 0 ? $php_limit : $filtered );
	}

	public static function render(): void {
		Admin::maybe_render_notice();

		$action_url = admin_url( 'admin-post.php' );
		$pages      = get_posts(
			[
				'post_type'      => 'page',
				'post_status'    => [ 'publish', 'private', 'draft' ],
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
		?>
		<div class="wrap abtest-wrap">
			<h1><?php esc_html_e( 'Import HTML', 'ab-testing-wordpress' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Upload a complete HTML document (with its own DOCTYPE, head, body) and import it as a WordPress page rendered with no theme wrapper. Useful for landing-page templates designed outside WordPress.', 'ab-testing-wordpress' ); ?>
			</p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( $action_url ); ?>" class="abtest-form abtest-import-form">
				<?php wp_nonce_field( self::NONCE, '_abtest_import_nonce' ); ?>
				<input type="hidden" name="action" value="abtest_import_html">

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="abtest-html-file"><?php esc_html_e( 'HTML file', 'ab-testing-wordpress' ); ?></label></th>
						<td>
							<input type="file" id="abtest-html-file" name="html_file" accept=".html,.htm" required>
							<p class="description">
								<?php
								printf(
									/* translators: %s: max size, human-readable */
									esc_html__( 'Max %s. Only .html and .htm extensions accepted.', 'ab-testing-wordpress' ),
									esc_html( size_format( self::max_bytes() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-target"><?php esc_html_e( 'Target', 'ab-testing-wordpress' ); ?></label></th>
						<td>
							<select id="abtest-target" name="target_page_id">
								<option value="0"><?php esc_html_e( '— Create a new page —', 'ab-testing-wordpress' ); ?></option>
								<?php foreach ( $pages as $page ) : ?>
									<option value="<?php echo (int) $page->ID; ?>">
										<?php echo esc_html( get_the_title( $page ) . ' (#' . $page->ID . ' · ' . $page->post_status . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Pick an existing page to overwrite, or leave on "Create a new page" to make a fresh one.', 'ab-testing-wordpress' ); ?></p>
						</td>
					</tr>
					<tr class="abtest-new-page-row">
						<th scope="row"><label for="abtest-new-title"><?php esc_html_e( 'Page title (when creating new)', 'ab-testing-wordpress' ); ?></label></th>
						<td>
							<input type="text" id="abtest-new-title" name="new_title" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Variant A — Landing v1', 'ab-testing-wordpress' ); ?>">
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Import HTML', 'ab-testing-wordpress' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_upload(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ab-testing-wordpress' ), 403 );
		}
		check_admin_referer( self::NONCE, '_abtest_import_nonce' );

		// Validate upload.
		if ( ! isset( $_FILES['html_file'] ) || ! is_array( $_FILES['html_file'] ) ) {
			self::redirect_error( __( 'No file uploaded.', 'ab-testing-wordpress' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$file = $_FILES['html_file'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::redirect_error( self::upload_error_message( (int) ( $file['error'] ?? -1 ) ) );
		}

		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 ) {
			self::redirect_error( __( 'Empty file.', 'ab-testing-wordpress' ) );
		}
		if ( $size > self::max_bytes() ) {
			self::redirect_error(
				sprintf(
					/* translators: 1: actual size, 2: limit */
					__( 'File too large (%1$s). Max %2$s.', 'ab-testing-wordpress' ),
					size_format( $size ),
					size_format( self::max_bytes() )
				)
			);
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
			self::redirect_error( __( 'Only .html and .htm files are accepted.', 'ab-testing-wordpress' ) );
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			self::redirect_error( __( 'Upload failed.', 'ab-testing-wordpress' ) );
		}

		$contents = file_get_contents( $tmp_name );
		if ( false === $contents ) {
			self::redirect_error( __( 'Could not read uploaded file.', 'ab-testing-wordpress' ) );
		}

		$target_id = isset( $_POST['target_page_id'] ) ? absint( wp_unslash( $_POST['target_page_id'] ) ) : 0;
		$new_title = isset( $_POST['new_title'] ) ? sanitize_text_field( wp_unslash( $_POST['new_title'] ) ) : '';

		if ( $target_id > 0 ) {
			$page_id = self::replace_existing( $target_id, $contents );
		} else {
			$page_id = self::create_new( $new_title, $contents );
		}

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			$msg = is_wp_error( $page_id ) ? $page_id->get_error_message() : __( 'Failed to save page.', 'ab-testing-wordpress' );
			self::redirect_error( (string) $msg );
		}

		// Always assign the Blank Canvas template (idempotent).
		update_post_meta( (int) $page_id, '_wp_page_template', Template::TEMPLATE_SLUG );

		self::redirect_success(
			sprintf(
				/* translators: 1: page title, 2: page ID */
				__( 'HTML imported into "%1$s" (#%2$d).', 'ab-testing-wordpress' ),
				get_the_title( (int) $page_id ),
				(int) $page_id
			),
			(int) $page_id
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function create_new( string $title, string $html ) {
		if ( '' === $title ) {
			$title = sprintf( __( 'HTML import — %s', 'ab-testing-wordpress' ), current_time( 'Y-m-d H:i:s' ) );
		}
		// wp_insert_post() runs wp_unslash() on inputs (it expects $_POST-style slashed data).
		// Our raw file content has no slashes, so the unslash would strip real backslashes from
		// JSON payloads / regex / Babel sources etc. We pre-slash to neutralize that.
		return wp_insert_post(
			[
				'post_type'    => 'page',
				'post_status'  => 'private',
				'post_title'   => wp_slash( $title ),
				'post_content' => wp_slash( $html ),
			],
			true
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function replace_existing( int $page_id, string $html ) {
		$existing = get_post( $page_id );
		if ( ! $existing instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', __( 'Target page not found.', 'ab-testing-wordpress' ) );
		}
		$result = wp_update_post(
			[
				'ID'           => $page_id,
				'post_content' => wp_slash( $html ),
			],
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $page_id;
	}

	private static function upload_error_message( int $code ): string {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'File too large (server upload limit).', 'ab-testing-wordpress' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'Upload was interrupted.', 'ab-testing-wordpress' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file selected.', 'ab-testing-wordpress' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Server could not save the upload.', 'ab-testing-wordpress' );
			default:
				return __( 'Upload error.', 'ab-testing-wordpress' );
		}
	}

	private static function redirect_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => Admin::menu_slug(),
					'action'          => 'import',
					'abtest_notice'   => rawurlencode( $message ),
					'abtest_notice_t' => 'error',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private static function redirect_success( string $message, int $page_id ): void {
		$args = [
			'page'              => Admin::menu_slug(),
			'action'            => 'import',
			'abtest_notice'     => rawurlencode( $message ),
			'abtest_notice_t'   => 'success',
			'abtest_imported'   => $page_id,
		];
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
