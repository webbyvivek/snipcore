<?php
/**
 * Fired during plugin deactivation.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Deactivator
 *
 * Handles all logic required on plugin deactivation.
 * Note: does NOT delete options/data — that is reserved for uninstall.php.
 */
class SnipCore_Deactivator {

	/**
	 * Runs on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Flush rewrite rules to clean up any registered on activation.
		flush_rewrite_rules();
	}
}
