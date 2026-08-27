<?php
/**
 * Plugin Name:       SnipCore
 * Plugin URI:        https://github.com/webbyvivek/snipcore
 * Description:       Lightweight core foundation plugin for the Snip suite. Provides activation/deactivation lifecycle handling, secure bootstrapping, and a minimal extensible base with no unnecessary dependencies.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Virtualcode
 * Author URI:        https://virtualcode.co/
 * License:            GPL v2 or later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        snipcore
 * Domain Path:        /languages
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Core plugin constants.
define( 'SNIPCORE_VERSION', '1.0.0' );
define( 'SNIPCORE_PLUGIN_FILE', __FILE__ );
define( 'SNIPCORE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SNIPCORE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SNIPCORE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation and deactivation handlers.
 *
 * SnipCore_Settings and SnipCore_Import_Export are deliberately NOT
 * required here: they are admin/upgrade-only concerns (settings
 * screen, import/export actions, the rare version-bump upgrade
 * routine) and are pulled in lazily, right where they're actually
 * needed (class-snipcore-activator.php, class-snipcore.php's
 * maybe_upgrade(), and class-snipcore-admin.php), so a plain
 * frontend request never parses/compiles that code at all.
 */
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-activator.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-deactivator.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-snippets.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-header-footer.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-safe-mode.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-executor.php';
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore-safe-mode-admin.php';

register_activation_hook( __FILE__, array( 'SnipCore_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SnipCore_Deactivator', 'deactivate' ) );

/**
 * Core loader class.
 */
require_once SNIPCORE_PLUGIN_DIR . 'includes/class-snipcore.php';

/**
 * Begins execution of the plugin.
 *
 * @return void
 */
function snipcore_run() {
	$plugin = new SnipCore();
	$plugin->run();
}
snipcore_run();
