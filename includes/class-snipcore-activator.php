<?php
/**
 * Fired during plugin activation.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Activator
 *
 * Handles all logic required on plugin activation.
 */
class SnipCore_Activator {

	/**
	 * Runs on activation.
	 *
	 * @return void
	 */
	public static function activate() {

		require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-settings.php';

		// Require a minimum PHP version; fail safely with a clear message.
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( SNIPCORE_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'SnipCore requires PHP 7.4 or higher. The plugin has been deactivated.', 'snipcore' ),
				esc_html__( 'Plugin Activation Error', 'snipcore' ),
				array( 'back_link' => true )
			);
		}

		// Store/refresh the installed version for future upgrade routines.
		update_option( 'snipcore_version', SNIPCORE_VERSION );

		// Seed/backfill General settings so every current key is present
		// and persisted on disk, whether this is a fresh install or a
		// reactivation of a site that already has an (older-shaped) option.
		SnipCore_Settings::ensure_persisted();

		// Flush rewrite rules in case future modules register custom post types/rewrites.
		flush_rewrite_rules();
	}
}
