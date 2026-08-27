<?php
/**
 * Global Header & Footer data helper.
 *
 * Single source of truth for the Header/Body/Footer option shape and
 * defaults, mirroring the pattern already used by SnipCore_Settings
 * so the admin UI, the executor, and uninstall all read/write the
 * exact same structure.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Header_Footer
 */
class SnipCore_Header_Footer {

	/**
	 * Option name storing the header/body/footer code.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'snipcore_header_footer';

	/**
	 * Request-scoped cache of the merged/sanitized fields, so repeat
	 * calls within the same request (the Global Header & Footer
	 * feature alone calls get_all() twice per frontend page when
	 * active: once to decide whether to open the output buffer, once
	 * again inside the buffer callback to do the actual splice) skip
	 * re-reading and re-sanitizing. Reset on save().
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Resets the request-scoped cache above. Hooked to WordPress'
	 * 'switch_blog' action (see the bottom of this file) so that on
	 * multisite, a mid-request switch_to_blog() to a different site
	 * can never leak Global Header/Footer content cached from the
	 * previous blog.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Returns the default (empty) value for every field.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'header' => '',
			'body'   => '',
			'footer' => '',
		);
	}

	/**
	 * Returns the current header/body/footer code, merged over the
	 * defaults so every key is always present regardless of what was
	 * stored by an older version of the plugin. Memoized for the
	 * remainder of the request; see $cache.
	 *
	 * @return array
	 */
	public static function get_all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION_NAME, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$cache = self::sanitize( array_merge( self::get_defaults(), $stored ) );
		}

		return self::$cache;
	}

	/**
	 * Normalizes a full header/body/footer array to guaranteed-valid
	 * string values for all three fields.
	 *
	 * Deliberately does not strip tags or otherwise alter markup:
	 * these fields exist specifically to hold raw HTML/CSS/JS,
	 * consistent with how SnipCore already treats a snippet's code
	 * field, and are only ever editable by an administrator
	 * (capability manage_options).
	 *
	 * @param array $input Raw input array.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::get_defaults();

		return array(
			'header' => isset( $input['header'] ) ? (string) $input['header'] : $defaults['header'],
			'body'   => isset( $input['body'] ) ? (string) $input['body'] : $defaults['body'],
			'footer' => isset( $input['footer'] ) ? (string) $input['footer'] : $defaults['footer'],
		);
	}

	/**
	 * Persists a full, sanitized header/body/footer array.
	 *
	 * @param array $data Data to save (will be sanitized).
	 * @return bool True if the option was updated (or was already current).
	 */
	public static function save( array $data ) {
		$result = update_option( self::OPTION_NAME, self::sanitize( $data ) );
		// Invalidate the request cache so any read for the rest of
		// this request reflects what was just saved.
		self::reset_cache();
		return $result;
	}
}

// Multisite: switch_to_blog()/restore_current_blog() can be called
// mid-request, changing which site's 'snipcore_header_footer' option
// get_option() would return. Reset the request-scoped cache on every
// such switch so it's always re-read fresh for the currently active
// blog.
add_action( 'switch_blog', array( 'SnipCore_Header_Footer', 'reset_cache' ) );
