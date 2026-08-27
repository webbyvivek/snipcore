<?php
/**
 * Cross-screen admin notice for Snippet Error Recovery / Safe Mode.
 *
 * The Safe Mode toggle and auto-disable details themselves live on
 * the Settings > Safe Mode tab (see
 * SnipCore_Settings_Admin::render_safe_mode_tab()), styled to match
 * the rest of that screen. All this class does is surface a
 * dismissible admin notice, on every wp-admin screen, pointing the
 * admin there when a snippet was recently auto-disabled — so they
 * don't have to already know to go looking for it.
 *
 * Kept as its own lightweight class (rather than folded into
 * SnipCore_Admin) so it can be hooked independently of whether the
 * rest of the Settings screen loads without issue; the actual
 * recovery mechanism it reports on (the shutdown handler and the
 * emergency kill switch, see class-snipcore-safe-mode.php) does not
 * depend on this notice or on the Settings screen rendering at all —
 * both keep working even if this class were removed entirely, and
 * the SNIPCORE_SAFE_MODE wp-config.php constant remains available as
 * a last resort independent of any admin screen.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Safe_Mode_Admin
 */
class SnipCore_Safe_Mode_Admin {

	/**
	 * Registers hooks. Only ever runs in wp-admin (guarded by the
	 * caller, class-snipcore.php's init_admin(), which already checks
	 * is_admin() before requiring this file at all).
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Shows a dismissible admin notice, on every wp-admin screen, when
	 * a snippet was recently auto-disabled after causing a fatal
	 * error. Points to Settings > Safe Mode for details/dismissal.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last_error = SnipCore_Safe_Mode::get_last_error();

		if ( ! $last_error ) {
			return;
		}

		$safe_mode_tab_url = add_query_arg(
			array(
				'page' => SnipCore_Settings_Admin::SETTINGS_SLUG,
				'tab'  => 'safe_mode',
			),
			admin_url( 'admin.php' )
		);

		// Avoid double-showing on the Safe Mode tab itself, which
		// already renders the same information inline.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, used only to decide whether to render a notice.
		$screen_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, used only to decide whether to render a notice.
		$screen_tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( SnipCore_Settings_Admin::SETTINGS_SLUG === $screen_page && 'safe_mode' === $screen_tab ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'SnipCore automatically disabled a snippet', 'snipcore' ); ?></strong>
				&mdash;
				<?php
				printf(
					/* translators: %s: snippet name. */
					esc_html__( 'the snippet "%s" caused a fatal error and has been deactivated to keep your site running. Fix the code and re-enable it when ready.', 'snipcore' ),
					esc_html( isset( $last_error['name'] ) ? $last_error['name'] : '' )
				);
				?>
				<a href="<?php echo esc_url( $safe_mode_tab_url ); ?>">
					<?php esc_html_e( 'View details', 'snipcore' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
