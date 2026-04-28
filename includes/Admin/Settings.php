<?php
/**
 * Settings sub-page — GA4 Measurement Protocol + custom Webhooks.
 *
 * @package Abtest
 */

declare( strict_types=1 );

namespace Abtest\Admin;

use Abtest\Integrations\Ga4;
use Abtest\Integrations\Webhook;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const NONCE = 'abtest_save_settings';

	public static function render(): void {
		Admin::maybe_render_notice();
		$ga4_cfg    = Ga4::get_settings();
		$webhooks   = Webhook::get_all();
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap abtest-wrap">
			<h1><?php esc_html_e( 'Settings', 'ab-testing-wordpress' ); ?></h1>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>" class="abtest-form">
				<?php wp_nonce_field( self::NONCE, '_abtest_settings_nonce' ); ?>
				<input type="hidden" name="action" value="abtest_save_settings">

				<h2><?php esc_html_e( 'Google Analytics 4', 'ab-testing-wordpress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Forward every impression and conversion to GA4 via the Measurement Protocol. Fire-and-forget — never blocks page rendering.', 'ab-testing-wordpress' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'ab-testing-wordpress' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="ga4_enabled" value="1" <?php checked( ! empty( $ga4_cfg['enabled'] ) ); ?>>
								<?php esc_html_e( 'Send A/B events to GA4', 'ab-testing-wordpress' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Events sent: abtest_impression, abtest_conversion. Both include params: experiment_id, variant (A/B), test_url.', 'ab-testing-wordpress' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-ga4-id"><?php esc_html_e( 'Measurement ID', 'ab-testing-wordpress' ); ?></label></th>
						<td>
							<input type="text" id="abtest-ga4-id" name="ga4_measurement_id" class="regular-text code" value="<?php echo esc_attr( $ga4_cfg['measurement_id'] ); ?>" placeholder="G-XXXXXXXXXX">
							<p class="description"><?php esc_html_e( 'Your GA4 Measurement ID (starts with "G-"). Find it under Admin → Data Streams in GA4.', 'ab-testing-wordpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="abtest-ga4-secret"><?php esc_html_e( 'API Secret', 'ab-testing-wordpress' ); ?></label></th>
						<td>
							<input type="password" id="abtest-ga4-secret" name="ga4_api_secret" class="regular-text code" value="<?php echo esc_attr( $ga4_cfg['api_secret'] ); ?>" autocomplete="off">
							<p class="description">
								<?php esc_html_e( 'Generated in GA4 → Admin → Data Streams → your stream → Measurement Protocol API secrets.', 'ab-testing-wordpress' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="abtest-section-title"><?php esc_html_e( 'Webhooks', 'ab-testing-wordpress' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'POST every A/B event to one or more HTTP endpoints. Pipe to Zapier, Mixpanel, Segment, Slack, n8n, your data warehouse, anything that accepts JSON over HTTP. Fire-and-forget — never blocks page rendering.', 'ab-testing-wordpress' ); ?>
				</p>

				<div class="abtest-webhooks" data-empty-msg="<?php esc_attr_e( 'No webhooks configured. Click + Add webhook.', 'ab-testing-wordpress' ); ?>">
					<?php if ( empty( $webhooks ) ) : ?>
						<p class="abtest-webhooks-empty"><?php esc_html_e( 'No webhooks configured. Click + Add webhook.', 'ab-testing-wordpress' ); ?></p>
					<?php else : ?>
						<?php foreach ( $webhooks as $i => $hook ) : ?>
							<?php self::render_webhook_row( $i, $hook ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<p>
					<button type="button" class="button button-secondary abtest-webhook-add">+ <?php esc_html_e( 'Add webhook', 'ab-testing-wordpress' ); ?></button>
				</p>

				<?php submit_button( __( 'Save settings', 'ab-testing-wordpress' ) ); ?>
			</form>

			<?php self::render_api_docs(); ?>
		</div>
		<?php
	}

	/**
	 * Show the REST API documentation block (read-only, no form).
	 */
	private static function render_api_docs(): void {
		$endpoint = rest_url( 'abtest/v1/stats' );
		$apppw    = admin_url( 'profile.php#application-passwords-section' );
		?>
		<h2 class="abtest-section-title"><?php esc_html_e( 'REST API — pull stats', 'ab-testing-wordpress' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Pull experiment stats programmatically (n8n, Make, Pipedream, custom dashboards). Authenticated via WP Application Passwords.', 'ab-testing-wordpress' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint', 'ab-testing-wordpress' ); ?></th>
				<td>
					<input type="text" readonly class="large-text code" value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select();">
					<p class="description"><?php esc_html_e( 'Method: GET. Returns JSON with all matching experiments and their stats.', 'ab-testing-wordpress' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Authentication', 'ab-testing-wordpress' ); ?></th>
				<td>
					<p>
						<?php
						printf(
							/* translators: %s: link to user profile application passwords section */
							esc_html__( 'Generate a WP Application Password for your user (%s) — that user must have the manage_options capability. Use it in n8n as Basic Auth: username = your WP login, password = the generated 24-char string.', 'ab-testing-wordpress' ),
							'<a href="' . esc_url( $apppw ) . '">' . esc_html__( 'your profile → Application Passwords', 'ab-testing-wordpress' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Query parameters', 'ab-testing-wordpress' ); ?></th>
				<td>
					<ul class="abtest-api-params">
						<li><code>url=/promo/</code> — <?php esc_html_e( 'filter experiments to a single test URL', 'ab-testing-wordpress' ); ?></li>
						<li><code>experiment_id=38</code> — <?php esc_html_e( 'fetch one experiment by ID', 'ab-testing-wordpress' ); ?></li>
						<li><code>status=running</code> — <?php esc_html_e( 'filter by experiment status (draft, running, paused, ended)', 'ab-testing-wordpress' ); ?></li>
						<li><code>from=YYYY-MM-DD&amp;to=YYYY-MM-DD</code> — <?php esc_html_e( 'restrict event date range for the stats computation', 'ab-testing-wordpress' ); ?></li>
						<li><code>breakdown=daily</code> — <?php esc_html_e( 'include per-day series (for charting)', 'ab-testing-wordpress' ); ?></li>
					</ul>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Example (curl)', 'ab-testing-wordpress' ); ?></th>
				<td>
					<pre class="abtest-api-example"><?php
						printf(
							"curl -u 'admin:xxxx xxxx xxxx xxxx xxxx xxxx' \\\n     '%s?status=running&from=2026-04-01'",
							esc_html( $endpoint )
						);
					?></pre>
					<p class="description">
						<?php esc_html_e( 'Replace "admin" with your WP username and the password with the Application Password (with or without spaces — both are accepted).', 'ab-testing-wordpress' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_webhook_row( int $index, array $hook ): void {
		$test_url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'abtest_test_webhook',
					'webhook' => $index,
				],
				admin_url( 'admin-post.php' )
			),
			'abtest_test_webhook'
		);
		?>
		<div class="abtest-webhook-row">
			<div class="abtest-webhook-head">
				<label>
					<?php esc_html_e( 'Name:', 'ab-testing-wordpress' ); ?>
					<input type="text" name="webhooks[<?php echo (int) $index; ?>][name]" value="<?php echo esc_attr( $hook['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Slack alerts', 'ab-testing-wordpress' ); ?>">
				</label>
				<label class="abtest-webhook-enabled">
					<input type="checkbox" name="webhooks[<?php echo (int) $index; ?>][enabled]" value="1" <?php checked( ! empty( $hook['enabled'] ) ); ?>>
					<?php esc_html_e( 'Enabled', 'ab-testing-wordpress' ); ?>
				</label>
				<a href="<?php echo esc_url( $test_url ); ?>" class="button button-secondary abtest-webhook-test"><?php esc_html_e( 'Send test event', 'ab-testing-wordpress' ); ?></a>
				<button type="button" class="button-link abtest-webhook-remove" aria-label="<?php esc_attr_e( 'Remove this webhook', 'ab-testing-wordpress' ); ?>">
					<?php esc_html_e( 'Remove', 'ab-testing-wordpress' ); ?>
				</button>
			</div>
			<div class="abtest-webhook-fields">
				<label>
					<?php esc_html_e( 'URL', 'ab-testing-wordpress' ); ?>
					<input type="url" name="webhooks[<?php echo (int) $index; ?>][url]" value="<?php echo esc_attr( $hook['url'] ?? '' ); ?>" class="large-text code" placeholder="https://hooks.zapier.com/hooks/catch/...">
				</label>
				<label>
					<?php esc_html_e( 'Secret (optional)', 'ab-testing-wordpress' ); ?>
					<input type="text" name="webhooks[<?php echo (int) $index; ?>][secret]" value="<?php echo esc_attr( $hook['secret'] ?? '' ); ?>" class="large-text code" autocomplete="off">
					<small><?php esc_html_e( 'When set, requests include X-Abtest-Signature: sha256=<HMAC of body using this secret>. Lets your endpoint verify authenticity.', 'ab-testing-wordpress' ); ?></small>
				</label>
				<label>
					<?php esc_html_e( 'Fire on:', 'ab-testing-wordpress' ); ?>
					<select name="webhooks[<?php echo (int) $index; ?>][fire_on]">
						<option value="<?php echo esc_attr( Webhook::FIRE_ALL ); ?>" <?php selected( ( $hook['fire_on'] ?? Webhook::FIRE_ALL ), Webhook::FIRE_ALL ); ?>>
							<?php esc_html_e( 'All events (impressions + conversions)', 'ab-testing-wordpress' ); ?>
						</option>
						<option value="<?php echo esc_attr( Webhook::FIRE_CONVERSION ); ?>" <?php selected( ( $hook['fire_on'] ?? Webhook::FIRE_ALL ), Webhook::FIRE_CONVERSION ); ?>>
							<?php esc_html_e( 'Conversions only (low volume)', 'ab-testing-wordpress' ); ?>
						</option>
					</select>
				</label>
			</div>
		</div>
		<?php
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ab-testing-wordpress' ), 403 );
		}
		check_admin_referer( self::NONCE, '_abtest_settings_nonce' );

		// --- GA4 ---
		$enabled        = ! empty( $_POST['ga4_enabled'] );
		$measurement_id = isset( $_POST['ga4_measurement_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_measurement_id'] ) ) : '';
		$api_secret     = isset( $_POST['ga4_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_api_secret'] ) ) : '';

		$warning = '';
		if ( $enabled && '' !== $measurement_id && 0 !== strpos( $measurement_id, 'G-' ) ) {
			$warning = __( 'Measurement ID usually starts with "G-". Saved anyway — double-check it in GA4.', 'ab-testing-wordpress' );
		}

		update_option(
			Ga4::OPTION_KEY,
			[
				'enabled'        => (bool) $enabled,
				'measurement_id' => $measurement_id,
				'api_secret'     => $api_secret,
			]
		);

		// --- Webhooks ---
		$raw_webhooks = isset( $_POST['webhooks'] ) && is_array( $_POST['webhooks'] ) ? wp_unslash( $_POST['webhooks'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Webhook::set_all( $raw_webhooks );

		$args = [
			'page'            => Admin::menu_slug(),
			'action'          => 'settings',
			'abtest_notice'   => rawurlencode( '' !== $warning ? $warning : __( 'Settings saved.', 'ab-testing-wordpress' ) ),
			'abtest_notice_t' => '' !== $warning ? 'warning' : 'success',
		];
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * "Send test event" button handler — POST a synthetic payload to the chosen webhook
	 * with `blocking=true` so we can report success / failure back to the admin.
	 */
	public static function handle_test_webhook(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden', 'ab-testing-wordpress' ), 403 );
		}
		check_admin_referer( 'abtest_test_webhook' );

		$index    = isset( $_GET['webhook'] ) ? absint( wp_unslash( $_GET['webhook'] ) ) : -1;
		$webhooks = Webhook::get_all();
		if ( ! isset( $webhooks[ $index ] ) ) {
			self::redirect_settings_notice( 'error', __( 'Webhook not found.', 'ab-testing-wordpress' ) );
		}

		$hook = $webhooks[ $index ];
		if ( '' === $hook['url'] ) {
			self::redirect_settings_notice( 'error', __( 'This webhook has no URL configured.', 'ab-testing-wordpress' ) );
		}

		$response = Webhook::send( $hook, Webhook::test_payload(), true );
		if ( is_wp_error( $response ) ) {
			self::redirect_settings_notice(
				'error',
				sprintf(
					/* translators: %s: error message */
					__( 'Test failed: %s', 'ab-testing-wordpress' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$msg  = $code >= 200 && $code < 300
			? sprintf(
				/* translators: 1: webhook name or URL, 2: HTTP status code */
				__( 'Test sent to "%1$s" — HTTP %2$d. Check the receiving end to confirm.', 'ab-testing-wordpress' ),
				'' !== $hook['name'] ? $hook['name'] : $hook['url'],
				$code
			)
			: sprintf(
				/* translators: 1: webhook name or URL, 2: HTTP status code */
				__( 'Test sent to "%1$s" but endpoint replied HTTP %2$d. Check your endpoint config.', 'ab-testing-wordpress' ),
				'' !== $hook['name'] ? $hook['name'] : $hook['url'],
				$code
			);
		self::redirect_settings_notice( $code >= 200 && $code < 300 ? 'success' : 'warning', $msg );
	}

	private static function redirect_settings_notice( string $type, string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'            => Admin::menu_slug(),
					'action'          => 'settings',
					'abtest_notice'   => rawurlencode( $message ),
					'abtest_notice_t' => $type,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
