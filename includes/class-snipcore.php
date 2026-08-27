<?php
/**
 * The core plugin class.
 *
 * Defines internationalization, admin-facing hooks, and public-facing hooks.
 * Kept intentionally minimal as a foundation for future modules.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore
 */
class SnipCore {

	/**
	 * Current plugin version, cached for quick reference.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Constructor: set up version and basic properties.
	 */
	public function __construct() {
		$this->version = defined( 'SNIPCORE_VERSION' ) ? SNIPCORE_VERSION : '1.1.1';
	}

	/**
	 * Registers all hooks for the plugin.
	 *
	 * @return void
	 */
	public function run() {
		$this->maybe_upgrade();
		$this->init_executor();
		$this->init_admin();
	}

	/**
	 * Initializes the snippet execution engine. Runs in every request
	 * context (frontend and wp-admin) since active snippets may target
	 * either or both.
	 *
	 * @return void
	 */
	private function init_executor() {
		$executor = new SnipCore_Executor();
		$executor->init();
	}

	/**
	 * Loads and initializes admin-only functionality.
	 *
	 * @return void
	 */
	private function init_admin() {
		if ( ! is_admin() ) {
			return;
		}

		// Settings and Import/Export are only ever used from the admin
		// screens, so both are loaded here (guarded by require_once,
		// so this is a no-op if maybe_upgrade() already pulled
		// Settings in earlier in this same request) rather than on
		// every frontend request.
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-settings.php';
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-import-export.php';
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-snippets-admin.php';
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-settings-admin.php';
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-header-footer-admin.php';
		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-admin.php';

		$admin = new SnipCore_Admin();
		$admin->init();

		// Cross-screen admin notice for auto-disabled snippets (see
		// class-snipcore-safe-mode-admin.php); the Safe Mode toggle
		// itself lives on the Settings > Safe Mode tab, registered
		// above as part of SnipCore_Admin/SnipCore_Settings_Admin.
		$safe_mode_admin = new SnipCore_Safe_Mode_Admin();
		$safe_mode_admin->init();
	}

	/**
	 * Runs a lightweight version check on init so future releases
	 * can add upgrade routines without needing re-activation.
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		$installed_version = get_option( 'snipcore_version', '' );

		if ( $installed_version !== $this->version ) {
			// SnipCore_Settings is otherwise only needed by the admin
			// screens, so it's loaded here on-demand rather than on
			// every request — this branch only runs once per version
			// bump, immediately after which installed_version matches
			// and it's skipped entirely.
			require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-settings.php';

			// Backfill any General settings keys introduced since the
			// installed version, so the stored option is always complete
			// (not just complete at read-time via a runtime merge).
			SnipCore_Settings::ensure_persisted();

			update_option( 'snipcore_version', $this->version );
		}
	}

	/**
	 * Returns the current plugin version.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}
