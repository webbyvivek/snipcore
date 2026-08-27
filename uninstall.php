<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package SnipCore
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-snipcore-settings.php';
require_once __DIR__ . '/includes/class-snipcore-snippets.php';
require_once __DIR__ . '/includes/class-snipcore-header-footer.php';

/**
 * Determines whether the current site's stored setting allows data
 * removal on uninstall. Defaults to true (matches prior versions'
 * always-delete behavior) when the setting has never been saved.
 *
 * @return bool
 */
function snipcore_uninstall_should_delete_data() {
	return (bool) SnipCore_Settings::get( 'delete_data_on_uninstall' );
}

/**
 * Remove plugin options and data.
 *
 * Kept minimal and explicit: only remove data this plugin itself
 * created, and only if the "Delete all snippets and settings on
 * uninstall" setting (General tab) is enabled for that site.
 */
function snipcore_uninstall_cleanup() {
	// Single-site cleanup.
	if ( snipcore_uninstall_should_delete_data() ) {
		delete_option( 'snipcore_version' );
		delete_option( SnipCore_Settings::OPTION_NAME );
		delete_option( SnipCore_Snippets::OPTION_NAME );
		delete_option( SnipCore_Snippets::NUM_ID_OPTION_NAME );
		delete_option( SnipCore_Header_Footer::OPTION_NAME );
	}

	// Multisite cleanup.
	if ( is_multisite() ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			if ( snipcore_uninstall_should_delete_data() ) {
				delete_option( 'snipcore_version' );
				delete_option( SnipCore_Settings::OPTION_NAME );
				delete_option( SnipCore_Snippets::OPTION_NAME );
				delete_option( SnipCore_Snippets::NUM_ID_OPTION_NAME );
				delete_option( SnipCore_Header_Footer::OPTION_NAME );
			}
			restore_current_blog();
		}
	}
}
snipcore_uninstall_cleanup();
