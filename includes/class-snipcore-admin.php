<?php
/**
 * Admin-facing functionality: menu registration and page rendering.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Admin
 *
 * Registers a single top-level admin menu ("SnipCore") with exactly
 * three submenu items: All Snippets, Header & Footer, and Settings.
 * No dashboard widgets, no extra menus, native WordPress admin UI
 * only.
 */
class SnipCore_Admin {

	/**
	 * Slug used for the top-level menu and the "All Snippets" page.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'snipcore';

	/**
	 * Slug used for the Settings submenu page.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'snipcore-settings';

	/**
	 * Slug used for the Global Header & Footer submenu page.
	 *
	 * @var string
	 */
	const HEADER_FOOTER_SLUG = 'snipcore-header-footer';

	/**
	 * Snippets-specific admin controller: All Snippets, Add/Edit
	 * Snippet, and the row/bulk-action handlers for them. Extracted
	 * into its own class (see class-snipcore-snippets-admin.php) so
	 * this class no longer owns that request-handling responsibility
	 * directly; a few small rendering helpers used by both the
	 * Snippets screens and the Settings > Import/Export tab remain
	 * here and are called back through the $admin reference each
	 * SnipCore_Snippets_Admin instance is constructed with.
	 *
	 * @var SnipCore_Snippets_Admin
	 */
	private $snippets_admin;

	/**
	 * Settings-specific admin controller: the Settings page (General
	 * and Import/Export tabs), its Settings API registration, and the
	 * request handlers behind them. Extracted into its own class (see
	 * class-snipcore-settings-admin.php) so this class no longer owns
	 * that responsibility directly; a few small helpers used by both
	 * the Settings screens and the Snippets/Header-Footer screens
	 * remain here and are called back through the $admin reference
	 * each SnipCore_Settings_Admin instance is constructed with.
	 *
	 * @var SnipCore_Settings_Admin
	 */
	private $settings_admin;

	/**
	 * Header/Footer-specific admin controller: the Global Header &
	 * Footer admin page and its save handler. Extracted into its own
	 * class (see class-snipcore-header-footer-admin.php) so this
	 * class no longer owns that responsibility directly; the screen's
	 * asset enqueuing remains here since enqueue_assets() is a
	 * cross-cutting method shared with the List and Settings screens.
	 *
	 * @var SnipCore_Header_Footer_Admin
	 */
	private $header_footer_admin;

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public function init() {
		$this->snippets_admin = new SnipCore_Snippets_Admin( $this );
		$this->snippets_admin->init();

		$this->settings_admin = new SnipCore_Settings_Admin( $this );
		$this->settings_admin->init();

		$this->header_footer_admin = new SnipCore_Header_Footer_Admin( $this );
		$this->header_footer_admin->init();

		add_filter( 'plugin_action_links_' . SNIPCORE_PLUGIN_BASENAME, array( $this, 'add_plugin_action_links' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registers the top-level menu and its two submenu items only.
	 *
	 * @return void
	 */
	public function register_menu() {

		// Top-level menu; also serves as the "All Snippets" screen.
		add_menu_page(
			__( 'SnipCore', 'snipcore' ),
			__( 'SnipCore', 'snipcore' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->snippets_admin, 'render_all_snippets_page' ),
			'dashicons-admin-post',
			25
		);

		// Submenu item 1: All Snippets (renames the auto-added duplicate).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Snippets', 'snipcore' ),
			__( 'All Snippets', 'snipcore' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this->snippets_admin, 'render_all_snippets_page' )
		);

		// Submenu item: Add New (opens the existing Add New Snippet
		// screen — same render_all_snippets_page()-adjacent editor
		// used by the in-page "Add New" button, just reachable from
		// the sidebar as well). Positioned directly under All
		// Snippets, before Header & Footer and Settings.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Snippet', 'snipcore' ),
			__( '&#65291; Add New', 'snipcore' ),
			'manage_options',
			self::MENU_SLUG . '&action=edit',
			array( $this->snippets_admin, 'render_all_snippets_page' )
		);

		// Submenu item 2: Global Header & Footer.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Header & Footer', 'snipcore' ),
			__( 'Header & Footer', 'snipcore' ),
			'manage_options',
			self::HEADER_FOOTER_SLUG,
			array( $this->header_footer_admin, 'render_header_footer_page' )
		);

		// Submenu item 3: Settings.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'snipcore' ),
			__( 'Settings', 'snipcore' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this->settings_admin, 'render_settings_page' )
		);
	}

	/**
	 * Adds a "Snippets" action link to this plugin's row on the
	 * Plugins screen, alongside the native Activate/Deactivate and
	 * Settings links. Points to the existing "All Snippets" page —
	 * no new functionality, just a shortcut to it.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( $links ) {
		$snippets_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->get_list_url() ),
			esc_html__( 'Snippets', 'snipcore' )
		);

		array_unshift( $links, $snippets_link );

		return $links;
	}

	/**
	 * Returns the default values for every General-tab setting.
	 *
	 * Delegates to SnipCore_Settings, the single source of truth
	 * shared with the activator and the upgrade routine, so the
	 * admin UI can never drift out of sync with what actually gets
	 * persisted.
	 *
	 * @return array
	 */
	public static function get_settings_defaults() {
		return SnipCore_Settings::get_defaults();
	}

	/**
	 * Returns the allowed values, with labels, for the "Snippets List
	 * Order" field.
	 *
	 * @return array
	 */
	public function get_list_order_choices() {
		return array(
			'name_asc'      => __( 'Name (A-Z)', 'snipcore' ),
			'name_desc'     => __( 'Name (Z-A)', 'snipcore' ),
			'modified_desc' => __( 'Modified (Latest first)', 'snipcore' ),
			'modified_asc'  => __( 'Modified (Oldest first)', 'snipcore' ),
		);
	}

	/**
	 * Returns the current General-tab settings, merged over defaults
	 * so every key is always present.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return SnipCore_Settings::get_all();
	}

	/**
	 * Returns the admin page hook suffix for the Settings screen
	 * (the "Complete Uninstall" confirmation JS lives there).
	 *
	 * @return string
	 */
	private function get_settings_hook_suffix() {
		return self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG;
	}

	/**
	 * Returns the admin page hook suffix for the Header & Footer screen.
	 *
	 * @return string
	 */
	private function get_header_footer_hook_suffix() {
		return self::MENU_SLUG . '_page_' . self::HEADER_FOOTER_SLUG;
	}

	/**
	 * Enqueues minimal inline CSS/JS, scoped strictly to the SnipCore
	 * "All Snippets" (including Add/Edit Snippet), "Header & Footer",
	 * and "Settings" screens. No external libraries; uses native
	 * WordPress admin color variables so it matches core styling.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {

		$is_list_screen          = ( 'toplevel_page_' . self::MENU_SLUG === $hook_suffix );
		$is_settings_screen      = ( $this->get_settings_hook_suffix() === $hook_suffix );
		$is_header_footer_screen = ( $this->get_header_footer_hook_suffix() === $hook_suffix );

		if ( ! $is_list_screen && ! $is_settings_screen && ! $is_header_footer_screen ) {
			return;
		}

		$action        = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing, same as the page/tab params it sits alongside.
		$is_edit_screen = ( $is_list_screen && 'edit' === $action );

		wp_register_style( 'snipcore-admin', SNIPCORE_PLUGIN_URL . 'assets/admin.css', array(), SNIPCORE_VERSION );
		wp_enqueue_style( 'snipcore-admin' );

		wp_register_script( 'snipcore-admin', SNIPCORE_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), SNIPCORE_VERSION, true );
		wp_enqueue_script( 'snipcore-admin' );

		wp_localize_script(
			'snipcore-admin',
			'snipcoreAdmin',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'snipcore_toggle_status' ),
				'selectAtLeastOne'       => __( 'Select at least one snippet to export.', 'snipcore' ),
				'selectABulkAction'      => __( 'Please select a bulk action.', 'snipcore' ),
				'selectOneOrMore'        => __( 'Please select at least one snippet.', 'snipcore' ),
				'confirmBulkTrash'       => __( 'Move the selected snippets to Trash?', 'snipcore' ),
				'confirmBulkDelete'      => __( 'Permanently delete the selected snippets? This cannot be undone.', 'snipcore' ),
				'processingLabel'        => __( 'Processing…', 'snipcore' ),
				'unsupportedFileType'    => __( 'Unsupported file type — only .json and .xml files can be imported.', 'snipcore' ),
				'selectAtLeastOneImport' => __( 'Select at least one snippet to import.', 'snipcore' ),
				'noMatchesLabel'         => __( 'No snippets match this filter anymore.', 'snipcore' ),
				'activeLabel'            => __( 'Active', 'snipcore' ),
				'inactiveLabel'          => __( 'Inactive', 'snipcore' ),
			)
		);

		if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
			return;
		}

		// Shared helper: initializes a native WordPress code editor on
		// a textarea and — since CodeMirror.fromTextArea() does not
		// keep the underlying <textarea> in sync on its own — keeps
		// the two in sync as the admin types and again right before
		// the form submits, so nothing typed is ever lost on save.
		$sync_helper_js = '
			function snipcoreInitCodeEditor( id, settings ) {
				var el = document.getElementById( id );
				if ( ! el || ! window.wp || ! wp.codeEditor ) {
					return null;
				}
				var instance = wp.codeEditor.initialize( el, settings );
				var cm       = instance.codemirror;
				var syncTimer;
				cm.on( "change", function ( doc ) {
					window.clearTimeout( syncTimer );
					syncTimer = window.setTimeout( function () { doc.save(); }, 300 );
				} );
				jQuery( el ).closest( "form" ).on( "submit", function () { cm.save(); } );
				return instance;
			}
		';

		// The Header & Footer screen loads WordPress' own native code
		// editor (the same CodeMirror-based component used by
		// Appearance > Theme File Editor and Customizer > Additional
		// CSS) and enhances its three plain <textarea> fields with it.
		if ( $is_header_footer_screen ) {
			$editor_settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );

			wp_add_inline_script(
				'snipcore-admin',
				'jQuery( function ( $ ) {'
				. $sync_helper_js
				. 'var settings = ' . wp_json_encode( false === $editor_settings ? array() : $editor_settings ) . ';'
				. 'if ( ! settings ) { return; }'
				. '[ "snipcore-header-code", "snipcore-body-code", "snipcore-footer-code" ].forEach( function ( id ) {'
				. 'var instance = snipcoreInitCodeEditor( id, settings );'
				. 'if ( instance ) { instance.codemirror.setSize( null, 300 ); }'
				. '} );'
				. '} );'
			);
		}

		// The Add/Edit Snippet screen loads the same native code
		// editor for the "Snippet Content" field, with proper syntax
		// support for all four snippet types (PHP/HTML/CSS/JS).
		// Requesting the PHP mime up front pulls in CodeMirror's
		// bundled htmlmixed/css/javascript/clike modes as well (PHP
		// mode is a composite of all of them), so switching the
		// "Type" dropdown can simply change the editor's mode
		// client-side — no extra libraries, no extra requests.
		if ( $is_edit_screen ) {
			$editor_settings = wp_enqueue_code_editor(
				array(
					'type'       => 'application/x-httpd-php',
					'codemirror' => array(
						// Snippet code is authored as bare statements
						// (no opening <?php tag — see the executor),
						// so full-file PHPCS linting would flag an
						// unrelated "missing open tag" on every
						// snippet; keep the editor to syntax
						// highlighting only, which is also lighter.
						'lint'          => false,
						// Readability: wrap long lines instead of
						// forcing horizontal scroll, highlight the
						// active line, and highlight matching
						// brackets. All three are core CodeMirror
						// options/addons already bundled with WP's
						// code editor package — no extra libraries.
						'lineWrapping'  => true,
						'styleActiveLine' => true,
						'matchBrackets' => true,
					),
				)
			);

			wp_add_inline_script(
				'snipcore-admin',
				'jQuery( function ( $ ) {'
				. $sync_helper_js
				. 'var settings = ' . wp_json_encode( false === $editor_settings ? array() : $editor_settings ) . ';'
				. 'if ( ! settings ) { return; }'
				. 'var modeMap = {'
				. 'functions: { name: "application/x-httpd-php", startOpen: true },'
				. 'content: "htmlmixed",'
				. 'style: "css",'
				. 'scripts: "javascript"'
				. '};'
				. 'var languageLabels = { functions: "PHP", content: "HTML", style: "CSS", scripts: "JS" };'
				. 'var languageColors = { PHP: "#7277AE", HTML: "#DC4821", CSS: "#196FB4", JS: "#E9D44D" };'
				. 'var locationOptionsMap = {'
				. 'functions: ['
				. '{ value: "everywhere", label: ' . wp_json_encode( __( 'Run Site-Wide', 'snipcore' ) ) . ' },'
				. '{ value: "admin", label: ' . wp_json_encode( __( 'Run Only in Admin Panel', 'snipcore' ) ) . ' },'
				. '{ value: "frontend", label: ' . wp_json_encode( __( 'Run Only on Site Front-End', 'snipcore' ) ) . ' }'
				. '],'
				. 'content: ['
				. '{ value: "everywhere", label: ' . wp_json_encode( __( 'Insert in Site <head>', 'snipcore' ) ) . ' },'
				. '{ value: "frontend", label: ' . wp_json_encode( __( 'Insert Before </body>', 'snipcore' ) ) . ' }'
				. '],'
				. 'style: ['
				. '{ value: "frontend", label: ' . wp_json_encode( __( 'Site Front-End', 'snipcore' ) ) . ' },'
				. '{ value: "admin", label: ' . wp_json_encode( __( 'Admin Panel', 'snipcore' ) ) . ' }'
				. '],'
				. 'scripts: ['
				. '{ value: "everywhere", label: ' . wp_json_encode( __( 'Insert in Site <head>', 'snipcore' ) ) . ' },'
				. '{ value: "frontend", label: ' . wp_json_encode( __( 'Insert Before </body>', 'snipcore' ) ) . ' }'
				. ']'
				. '};'
				. 'var $type     = $( "#snipcore-type" );'
				. 'var $badge    = $( "#snipcore-language-badge" );'
				. 'var $location = $( "#snipcore-location" );'
				. 'function renderLocationOptions( type, preferredValue ) {'
				. 'var options = locationOptionsMap[ type ] || locationOptionsMap.functions;'
				. 'var validValues = options.map( function ( opt ) { return opt.value; } );'
				. 'var nextValue = validValues.indexOf( preferredValue ) !== -1 ? preferredValue : options[ 0 ].value;'
				. '$location.empty();'
				. 'options.forEach( function ( opt ) {'
				. '$( "<option></option>" ).attr( "value", opt.value ).text( opt.label ).appendTo( $location );'
				. '} );'
				. '$location.val( nextValue );'
				. '}'
				. 'renderLocationOptions( $type.val(), $location.val() );'
				. 'settings.codemirror = $.extend( {}, settings.codemirror, { mode: modeMap[ $type.val() ] || modeMap.functions } );'
				. 'var instance = snipcoreInitCodeEditor( "snipcore-code", settings );'
				. 'if ( ! instance ) { return; }'
				. 'instance.codemirror.setSize( null, 420 );'
				. '$( document ).on( "change", "#snipcore-type", function () {'
				. 'var type = $( this ).val();'
				. 'var label = languageLabels[ type ] || type;'
				. 'var color = languageColors[ label ] || "#2271b1";'
				. 'instance.codemirror.setOption( "mode", modeMap[ type ] || modeMap.functions );'
				. '$badge.text( label ).css( "background-color", color );'
				. 'renderLocationOptions( type, $location.val() );'
				. '} );'
				. '} );'
			);
		}
	}

	/**
	 * Retrieves the stored, non-trashed snippets, sorted per the
	 * "Snippets List Order" setting.
	 *
	 * Shared helper: used by the Export tab (SnipCore_Settings_Admin)
	 * as well as the Snippets screens (SnipCore_Snippets_Admin),
	 * since both need the same sorted list.
	 *
	 * @return array
	 */
	public function get_sorted_snippets() {
		return $this->snippets_admin->sort_snippets( SnipCore_Snippets::get_all( false ) );
	}

	/**
	 * Human-readable label for a snippet 'type' value, shared by the
	 * All Snippets list table and the language badge on the Add/Edit
	 * Snippet code editor, so the two stay in sync automatically.
	 *
	 * @param string $type Raw stored type ('functions', 'content', 'style', 'scripts').
	 * @return string
	 */
	public function get_type_label( $type ) {
		$labels = array(
			'functions' => __( 'PHP', 'snipcore' ),
			'content'   => __( 'HTML', 'snipcore' ),
			'style'     => __( 'CSS', 'snipcore' ),
			'scripts'   => __( 'JS', 'snipcore' ),
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Returns the base "All Snippets" list URL.
	 *
	 * @return string
	 */
	public function get_list_url() {
		return add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * Streams a plugin-generated payload to the browser as a file
	 * download, with the same hardened handling on every export path
	 * in the plugin — centralized here so it only has to be gotten
	 * right once:
	 *
	 * - Discards any buffered output first, so a stray notice/warning
	 *   earlier in the request can't land inside the downloaded file.
	 * - Filename is always re-sanitized here (belt-and-suspenders even
	 *   when the caller already sanitized it), and quoted per the
	 *   Content-Disposition header syntax to avoid ambiguity.
	 * - X-Content-Type-Options: nosniff stops the browser from
	 *   second-guessing the declared content type.
	 * - Content-Length is set explicitly from the actual body.
	 * - nocache_headers() ensures a download is never served stale
	 *   from a cache.
	 *
	 * @param string $filename     Suggested filename (always re-sanitized).
	 * @param string $content_type MIME type, no charset (charset=utf-8 is added here).
	 * @param string $body         Body to output. Must always be plugin-generated (wp_json_encode()/DOMDocument), never raw request input.
	 * @return void Exits the request.
	 */
	public function send_export_download( $filename, $content_type, $body ) {

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'snipcore-export';
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type . '; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $body ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-escaping, plugin-generated payload (wp_json_encode()/DOMDocument), never raw request input.
		echo $body;
		exit;
	}

	/*
	 * NOTE: the label text above is rendered with a per-status item
	 * count appended (e.g. "Activate (5)") in render_all_snippets_page(),
	 * matching the native WordPress subsubsub pattern.
	 */
}
