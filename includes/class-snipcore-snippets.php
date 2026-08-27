<?php
/**
 * Snippet data model and storage layer.
 *
 * Stores snippets as a single option (lightweight, no custom table).
 * Provides the minimal CRUD needed to support list-table row actions:
 * get, insert, update, duplicate (clone), and trash (soft delete).
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Snippets
 */
class SnipCore_Snippets {

	/**
	 * Option name storing the snippets array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'snipcore_snippets';

	/**
	 * Option name that formerly stored the next stable numeric ID to
	 * assign to a snippet (used only by the now-removed shortcode
	 * feature). No longer written to, but the constant is retained
	 * because uninstall.php still references it to clean up this
	 * option on full data removal.
	 *
	 * @var string
	 */
	const NUM_ID_OPTION_NAME = 'snipcore_next_num_id';

	/**
	 * Allowed snippet types, mapped to the tabs introduced in 1.1.3.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'functions', 'content', 'style', 'scripts' );

	/**
	 * Regex signals that are near-unambiguous markers of PHP source:
	 * an explicit open tag, PHP's superglobals, $GLOBALS, a namespace
	 * declaration, require/include, or the handful of WordPress PHP
	 * API calls ("functions"-type snippets are typically WP hooks)
	 * that never appear as literal text in legitimate HTML/CSS/JS.
	 *
	 * Deliberately excludes generic tokens like `function`, `$var`,
	 * or `{ }` that are equally at home in JavaScript, to keep false
	 * positives on legitimate JS/CSS/HTML at essentially zero.
	 *
	 * @var string[]
	 */
	const PHP_SIGNAL_PATTERNS = array(
		'/<\?php\b/i',
		'/<\?=/',
		'/\$_(GET|POST|REQUEST|SERVER|COOKIE|SESSION|FILES)\b/',
		'/\$GLOBALS\b/',
		'/\bnamespace\s+[A-Za-z_][A-Za-z0-9_\\\\]*\s*;/',
		'/\b(require|include)(_once)?\s*\(/i',
		'/\badd_(action|filter)\s*\(/',
		'/\bwp_enqueue_(script|style)\s*\(/',
	);

	/**
	 * Structural tags that, when they appear at the very start of a
	 * snippet's code, mean the content is a pasted HTML document or
	 * element wrapper rather than bare PHP statements. Checked only
	 * against the leading edge of the code (not "contains anywhere")
	 * so that legitimate PHP which merely echoes markup further down
	 * (e.g. `echo '<html>...'`) is never flagged.
	 *
	 * @var string[]
	 */
	const MARKUP_LEADING_SIGNALS = array( '<!doctype', '<html', '<head', '<body', '<style', '<script' );

	/**
	 * Allowed activation statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'active', 'inactive' );

	/**
	 * Allowed execution locations.
	 *
	 * - everywhere: runs on both the frontend and wp-admin.
	 * - admin:      runs only in wp-admin.
	 * - frontend:   runs only on the public-facing site.
	 * - once:       runs a single time (any context) then auto-deactivates.
	 *
	 * @var string[]
	 */
	const LOCATIONS = array( 'everywhere', 'admin', 'frontend', 'once' );

	/**
	 * Allowed values for the "Site Display" targeting mode.
	 *
	 * - all:      no restriction; renders per the normal location rules.
	 * - specific: only renders on the chosen posts/pages.
	 * - exclude:  renders everywhere the normal location rules allow,
	 *             except on the chosen posts/pages.
	 *
	 * @var string[]
	 */
	const DISPLAY_MODES = array( 'all', 'specific', 'exclude' );

	/**
	 * Allowed values for the "Device Display" targeting control.
	 *
	 * - all:     no restriction; renders on both desktop and mobile.
	 * - desktop: renders only on desktop (non-mobile) frontend requests.
	 * - mobile:  renders only on mobile frontend requests.
	 *
	 * Like Site Display, this is a frontend-only concept: it never
	 * restricts admin execution.
	 *
	 * @var string[]
	 */
	const DEVICE_MODES = array( 'all', 'desktop', 'mobile' );

	/**
	 * Returns the default shape of a single snippet record.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'id'                => '',
			'snip_num_id'       => 0,
			'name'              => '',
			'type'              => 'functions',
			'status'            => 'inactive',
			'location'          => 'everywhere',
			'code'              => '',
			'description'       => '',
			'tags'              => array(),
			'priority'          => 10,
			'trashed'           => false,
			'shortcode_id'      => '',
			'display_mode'      => 'all',
			'display_post_ids'  => array(),
			'device_display'    => 'all',
			'schedule_enabled'  => false,
			'schedule_start'    => '',
			'schedule_end'      => '',
			'modified'          => '',
			'created'           => '',
		);
	}

	/**
	 * Request-scoped cache of the raw snippets array, so read() only
	 * ever does the get_option() call + array-shape check once per
	 * request no matter how many times it's called. The executor
	 * alone can call into this (via get_active()) five or more times
	 * on a single frontend page load (once per hook: init, wp,
	 * wp_head, wp_footer x2) — none of which change the stored data
	 * mid-request, so re-deriving it every time is pure duplicate
	 * work. Reset to null (not populated)
	 * whenever write() persists a change, so nothing in the same
	 * request can ever observe stale data after a save.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Resets the request-scoped cache above. Hooked to WordPress'
	 * 'switch_blog' action (see the bottom of this file) so that on
	 * multisite, a mid-request switch_to_blog() to a different site
	 * can never leak snippet data cached from the previous blog. Also
	 * exposed publicly so it can be called directly if ever needed.
	 *
	 * @return void
	 */
	public static function reset_cache() {
		self::$cache = null;
	}

	/**
	 * Reads the raw snippets array from storage, memoized for the
	 * remainder of the request. See $cache.
	 *
	 * @return array
	 */
	private static function read() {
		if ( null === self::$cache ) {
			$snippets     = get_option( self::OPTION_NAME, array() );
			self::$cache = is_array( $snippets ) ? $snippets : array();
		}
		return self::$cache;
	}

	/**
	 * Persists the snippets array to storage.
	 *
	 * @param array $snippets Full snippets array.
	 * @return void
	 */
	private static function write( array $snippets ) {
		update_option( self::OPTION_NAME, $snippets );
		// Invalidate the request cache so any read for the rest of
		// this request reflects what was just written, rather than
		// the pre-write snapshot.
		self::reset_cache();
	}

	/**
	 * Returns all snippets.
	 *
	 * @param bool $include_trashed Whether to include trashed snippets.
	 * @return array
	 */
	public static function get_all( $include_trashed = false ) {
		$snippets = self::read();

		if ( $include_trashed ) {
			return $snippets;
		}

		return array_values(
			array_filter(
				$snippets,
				static function ( $snippet ) {
					return empty( $snippet['trashed'] );
				}
			)
		);
	}

	/**
	 * Returns a single snippet by ID, or null if not found.
	 *
	 * @param string $id Snippet ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::read() as $snippet ) {
			if ( isset( $snippet['id'] ) && $snippet['id'] === $id ) {
				return $snippet;
			}
		}
		return null;
	}

	/**
	 * Checks whether a snippet's selected type and its code are
	 * obviously incompatible, so a mismatched pair can be rejected
	 * before it ever reaches storage (and, downstream, the frontend
	 * executor).
	 *
	 * This is intentionally conservative: it does not attempt to
	 * fully parse or validate PHP/HTML/CSS/JS syntax, only to catch
	 * the confirmed/obvious mismatches described in the type-mismatch
	 * audit -
	 *   1. Code containing explicit PHP tags (or unambiguous PHP-only
	 *      constructs) saved under a non-PHP type.
	 *   2. Code that is a pasted HTML document/element (starting with
	 *      `<!DOCTYPE`, `<html>`, `<style>`, etc.) saved as PHP, which
	 *      cannot be valid SnipCore PHP snippet code (bare PHP
	 *      statements, no required opening tag).
	 *
	 * Empty/whitespace-only code is always considered valid here; the
	 * existing empty-code handling elsewhere (form validation on
	 * save, no-op skip at execution time) is unaffected and unchanged
	 * by this method.
	 *
	 * @param string $type Sanitized snippet type (one of self::TYPES,
	 *                      or an already-invalid value - the caller is
	 *                      responsible for enum validation separately).
	 * @param string $code Raw, unslashed snippet code as submitted.
	 * @return string Empty string if the pairing is acceptable, or a
	 *                human-readable reason if it must be rejected.
	 */
	public static function get_type_code_mismatch_reason( $type, $code ) {
		$code = trim( (string) $code );

		if ( '' === $code ) {
			return '';
		}

		// Rule 1: unambiguous PHP signals present, but PHP isn't the
		// selected type. Covers explicit <?php/<?= tags as well as
		// PHP-only constructs (superglobals, namespace, require/
		// include, core WP hook calls) that can appear in valid
		// SnipCore PHP snippets even without an opening tag, but
		// never belong in legitimate HTML/CSS/JS snippet code.
		if ( 'functions' !== $type ) {
			foreach ( self::PHP_SIGNAL_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $code ) ) {
					return __( 'This code contains PHP-specific syntax (such as a PHP tag, a superglobal, or a WordPress hook call), but the selected type is not PHP. Choose the PHP type, or remove the PHP code.', 'snipcore' );
				}
			}
		}

		// Rule 2: the type is PHP, but the code is a pasted HTML
		// document or element wrapper - it cannot be valid bare PHP
		// snippet code under SnipCore's no-opening-tag convention.
		// Checked only against the leading edge of the trimmed code,
		// never "contains anywhere", so PHP that merely echoes or
		// builds markup further down is never flagged.
		if ( 'functions' === $type ) {
			$leading = strtolower( substr( $code, 0, 20 ) );
			foreach ( self::MARKUP_LEADING_SIGNALS as $signal ) {
				if ( 0 === strpos( $leading, $signal ) ) {
					return __( 'This code looks like HTML/CSS/JS markup (it starts with an HTML tag), but the selected type is PHP. Choose the matching type, or replace the code with PHP.', 'snipcore' );
				}
			}
		}

		return '';
	}

	/**
	 * Sanitizes a snippet record before it is stored.
	 *
	 * @param array $data Raw snippet data.
	 * @return array Sanitized snippet data.
	 */
	private static function sanitize( array $data ) {
		$type     = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'functions';
		$status   = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'inactive';
		$location = isset( $data['location'] ) ? sanitize_key( $data['location'] ) : 'everywhere';

		$tags = array();
		if ( isset( $data['tags'] ) ) {
			$raw_tags = is_array( $data['tags'] ) ? $data['tags'] : explode( ',', (string) $data['tags'] );
			foreach ( $raw_tags as $tag ) {
				$tag = sanitize_text_field( trim( $tag ) );
				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}
		}

		$priority = isset( $data['priority'] ) ? (int) $data['priority'] : 10;

		// shortcode_id is intentionally NOT accepted from arbitrary input
		// (e.g. an import file or a spoofed form field). It is no longer
		// generated for new snippets now that the shortcode feature has
		// been removed; whatever is already on the record (passed
		// through by insert()/update() before sanitize() runs) is kept
		// as-is here for any snippet that already had one.
		$shortcode_id = isset( $data['shortcode_id'] ) ? sanitize_key( $data['shortcode_id'] ) : '';

		// snip_num_id is intentionally NOT accepted from arbitrary input,
		// for the same reason: it is no longer generated for new or
		// edited snippets now that the shortcode feature has been
		// removed; whatever is already on the record (passed through
		// by insert()/update() before sanitize() runs) is kept as-is
		// here for any snippet that already had one. absint() here
		// just guards the type.
		$snip_num_id = isset( $data['snip_num_id'] ) ? absint( $data['snip_num_id'] ) : 0;

		$display_mode = isset( $data['display_mode'] ) ? sanitize_key( $data['display_mode'] ) : 'all';
		if ( ! in_array( $display_mode, self::DISPLAY_MODES, true ) ) {
			$display_mode = 'all';
		}

		$display_post_ids = array();
		if ( isset( $data['display_post_ids'] ) ) {
			$raw_ids = is_array( $data['display_post_ids'] )
				? $data['display_post_ids']
				: explode( ',', (string) $data['display_post_ids'] );

			foreach ( $raw_ids as $post_id ) {
				$post_id = absint( $post_id );
				if ( $post_id > 0 ) {
					$display_post_ids[] = $post_id;
				}
			}
			$display_post_ids = array_values( array_unique( $display_post_ids ) );
		}

		// "all" (no restriction) never needs a post list; keeping it
		// empty in that case avoids storing stale IDs from a prior
		// mode that would otherwise linger unused in the record.
		if ( 'all' === $display_mode ) {
			$display_post_ids = array();
		}

		$device_display = isset( $data['device_display'] ) ? sanitize_key( $data['device_display'] ) : 'all';
		if ( ! in_array( $device_display, self::DEVICE_MODES, true ) ) {
			$device_display = 'all';
		}

		$schedule_enabled = ! empty( $data['schedule_enabled'] );
		$schedule_start   = isset( $data['schedule_start'] ) ? self::sanitize_datetime( $data['schedule_start'] ) : '';
		$schedule_end     = isset( $data['schedule_end'] ) ? self::sanitize_datetime( $data['schedule_end'] ) : '';

		// A disabled schedule never needs stored dates; keeping them
		// empty avoids stale start/end values lingering unused (and
		// avoids ever evaluating them) once scheduling is turned off,
		// mirroring how "all" clears display_post_ids above.
		if ( ! $schedule_enabled ) {
			$schedule_start = '';
			$schedule_end   = '';
		}

		return array(
			'id'                => isset( $data['id'] ) ? sanitize_text_field( $data['id'] ) : '',
			'snip_num_id'       => $snip_num_id,
			'name'              => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'type'              => in_array( $type, self::TYPES, true ) ? $type : 'functions',
			'status'            => in_array( $status, self::STATUSES, true ) ? $status : 'inactive',
			'location'          => in_array( $location, self::LOCATIONS, true ) ? $location : 'everywhere',
			'code'              => isset( $data['code'] ) ? wp_unslash( $data['code'] ) : '',
			'description'       => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'tags'              => $tags,
			'priority'          => $priority,
			'trashed'           => ! empty( $data['trashed'] ),
			'shortcode_id'      => $shortcode_id,
			'display_mode'      => $display_mode,
			'display_post_ids'  => $display_post_ids,
			'device_display'    => $device_display,
			'schedule_enabled'  => $schedule_enabled,
			'schedule_start'    => $schedule_start,
			'schedule_end'      => $schedule_end,
			'modified'          => isset( $data['modified'] ) ? $data['modified'] : current_time( 'mysql' ),
			'created'           => isset( $data['created'] ) ? $data['created'] : current_time( 'mysql' ),
		);
	}

	/**
	 * Validates and normalizes a datetime string submitted for
	 * Schedule Snippet's start/end fields (from an HTML
	 * datetime-local input, "YYYY-MM-DDTHH:MM", or an equivalent
	 * "YYYY-MM-DD HH:MM[:SS]" value from an imported/exported
	 * record).
	 *
	 * The value is treated as the site's own local wall-clock time —
	 * the same convention current_time( 'mysql' ) uses for
	 * 'created'/'modified' — and is only reformatted, never shifted
	 * by a timezone conversion, so "2025-01-01 09:00" entered by the
	 * admin means 9am site time both when stored and when compared
	 * against current_time( 'mysql' ) at runtime.
	 *
	 * @param mixed $value Raw value.
	 * @return string Normalized 'YYYY-MM-DD HH:MM:SS', or '' if invalid/empty.
	 */
	private static function sanitize_datetime( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$value = str_replace( 'T', ' ', $value );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i' ) as $format ) {
			$date = \DateTime::createFromFormat( $format, $value );
			if ( $date instanceof \DateTime ) {
				$errors = \DateTime::getLastErrors();
				if ( empty( $errors['warning_count'] ) && empty( $errors['error_count'] ) ) {
					return $date->format( 'Y-m-d H:i:00' );
				}
			}
		}

		return '';
	}

	/**
	 * Determines whether "now" (the site's current local time) falls
	 * within a snippet's configured Schedule Snippet window.
	 *
	 * Snippets with scheduling disabled always pass — Schedule
	 * Snippet is purely additive and never restricts a snippet that
	 * hasn't opted into it. An enabled schedule with no start acts as
	 * open-ended in the past; no end acts as open-ended in the
	 * future; both empty (schedule enabled but never configured)
	 * matches always, same as disabled.
	 *
	 * @param array $snippet Snippet record.
	 * @return bool
	 */
	public static function is_within_schedule( array $snippet ) {
		if ( empty( $snippet['schedule_enabled'] ) ) {
			return true;
		}

		$now = current_time( 'mysql' );

		$start = isset( $snippet['schedule_start'] ) ? (string) $snippet['schedule_start'] : '';
		if ( '' !== $start && $now < $start ) {
			return false;
		}

		$end = isset( $snippet['schedule_end'] ) ? (string) $snippet['schedule_end'] : '';
		if ( '' !== $end && $now > $end ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns a machine-readable summary of a snippet's Schedule
	 * Snippet state, for display as a diagnostic in the admin UI. Pure
	 * read-only reporting — this never influences execution, which is
	 * decided solely by is_within_schedule().
	 *
	 * @param array $snippet Snippet record.
	 * @return string One of 'unscheduled', 'upcoming', 'active', 'expired'.
	 */
	public static function get_schedule_state( array $snippet ) {
		if ( empty( $snippet['schedule_enabled'] ) ) {
			return 'unscheduled';
		}

		$now   = current_time( 'mysql' );
		$start = isset( $snippet['schedule_start'] ) ? (string) $snippet['schedule_start'] : '';
		$end   = isset( $snippet['schedule_end'] ) ? (string) $snippet['schedule_end'] : '';

		if ( '' !== $start && $now < $start ) {
			return 'upcoming';
		}

		if ( '' !== $end && $now > $end ) {
			return 'expired';
		}

		return 'active';
	}

	/**
	 * Sanitizes raw (e.g. imported) snippet data into the same shape
	 * used everywhere else, without persisting it. Used by the Import
	 * preview so what's shown to the admin before import matches
	 * exactly what will be stored if they confirm.
	 *
	 * shortcode_id and snip_num_id are always cleared here regardless
	 * of what the imported file contains: neither field is populated
	 * for new snippets anymore now that the shortcode feature has
	 * been removed, so an import should never assign either directly
	 * — doing so could collide with (or impersonate) an existing
	 * snippet's legacy identifiers.
	 *
	 * @param array $data Raw snippet fields.
	 * @return array
	 */
	public static function sanitize_preview( array $data ) {
		unset( $data['shortcode_id'], $data['snip_num_id'] );
		return self::sanitize( $data );
	}

	/**
	 * Returns the names of every current, non-trashed snippet, for
	 * case-insensitive duplicate detection during Import. Trashed
	 * snippets are deliberately excluded — a name that only collides
	 * with something already trashed is not a meaningful duplicate.
	 *
	 * @return string[] Lowercased snippet names.
	 */
	public static function get_existing_names() {
		return array_map(
			static function ( $snippet ) {
				return function_exists( 'mb_strtolower' )
					? mb_strtolower( trim( (string) $snippet['name'] ) )
					: strtolower( trim( (string) $snippet['name'] ) );
			},
			self::get_all( false )
		);
	}

	/**
	 * Case-insensitive check for whether a name matches any current,
	 * non-trashed snippet.
	 *
	 * @param string $name Name to check.
	 * @return bool
	 */
	public static function name_exists( $name ) {
		$needle = function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( (string) $name ) )
			: strtolower( trim( (string) $name ) );

		if ( '' === $needle ) {
			return false;
		}

		return in_array( $needle, self::get_existing_names(), true );
	}

	/**
	 * Finds a name that does not collide with any current, non-trashed
	 * snippet by appending an incrementing " (imported)" / " (imported 2)"
	 * suffix. Used as a last-resort safety net so an insert can never
	 * silently collide with — and give the false impression of
	 * overwriting — an existing snippet by name.
	 *
	 * @param string $name Desired name.
	 * @return string A name guaranteed not to collide, at time of call.
	 */
	public static function make_unique_name( $name ) {
		$base = '' !== trim( (string) $name ) ? trim( (string) $name ) : __( '(untitled)', 'snipcore' );

		if ( ! self::name_exists( $base ) ) {
			return $base;
		}

		$suffix  = __( 'imported', 'snipcore' );
		$attempt = sprintf( '%s (%s)', $base, $suffix );
		$counter = 2;

		while ( self::name_exists( $attempt ) ) {
			$attempt = sprintf( '%s (%s %d)', $base, $suffix, $counter );
			++$counter;
		}

		return $attempt;
	}

	/**
	 * Inserts a new snippet.
	 *
	 * Rejects (without writing) if the type/code pairing is an
	 * obvious mismatch - see get_type_code_mismatch_reason(). This is
	 * the storage layer's own check and applies regardless of caller
	 * (Add New Snippet, import, or any future save path), independent
	 * of whatever validation the caller already did.
	 *
	 * @param array $data Snippet fields (id/modified/created are generated).
	 * @return string The new snippet's ID, or '' if the type/code
	 *                pairing was rejected.
	 */
	public static function insert( array $data ) {
		$type_check = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'functions';
		$code_check = isset( $data['code'] ) ? wp_unslash( $data['code'] ) : '';

		if ( '' !== self::get_type_code_mismatch_reason( $type_check, $code_check ) ) {
			return '';
		}

		$snippets = self::read();

		$now = current_time( 'mysql' );

		$data['id']       = wp_generate_uuid4();
		$data['modified'] = $now;
		$data['created']  = $now;

		$snippet    = array_merge( self::defaults(), self::sanitize( $data ) );
		$snippets[] = $snippet;

		self::write( $snippets );

		return $snippet['id'];
	}

	/**
	 * Updates an existing snippet.
	 *
	 * Rejects (without writing) if the type/code pairing is an
	 * obvious mismatch - see get_type_code_mismatch_reason(), same as
	 * insert().
	 *
	 * @param string $id   Snippet ID.
	 * @param array  $data Fields to update.
	 * @return bool True on success, false if the snippet was not
	 *              found or the type/code pairing was rejected.
	 */
	public static function update( $id, array $data ) {
		$type_check = isset( $data['type'] ) ? sanitize_key( $data['type'] ) : 'functions';
		$code_check = isset( $data['code'] ) ? wp_unslash( $data['code'] ) : '';

		if ( '' !== self::get_type_code_mismatch_reason( $type_check, $code_check ) ) {
			return false;
		}

		$snippets = self::read();
		$found    = false;

		foreach ( $snippets as &$snippet ) {
			if ( isset( $snippet['id'] ) && $snippet['id'] === $id ) {
				$data['id']       = $id;
				$data['created']  = isset( $snippet['created'] ) ? $snippet['created'] : current_time( 'mysql' );
				$data['modified'] = current_time( 'mysql' );

				// Preserve the existing shortcode_id and snip_num_id
				// across edits — the edit form doesn't (and shouldn't)
				// post either field, so without this an update would
				// otherwise wipe them back to their defaults via
				// sanitize(). Neither field is generated anymore now
				// that the shortcode feature has been removed; any
				// value present here is legacy data from before that
				// removal and is simply carried forward unchanged.
				$data['shortcode_id'] = ! empty( $snippet['shortcode_id'] )
					? $snippet['shortcode_id']
					: '';

				$data['snip_num_id'] = ! empty( $snippet['snip_num_id'] )
					? $snippet['snip_num_id']
					: 0;

				$snippet = array_merge( self::defaults(), self::sanitize( $data ) );
				$found   = true;
				break;
			}
		}
		unset( $snippet );

		if ( $found ) {
			self::write( $snippets );
		}

		return $found;
	}

	/**
	 * Duplicates ("clones") an existing snippet.
	 *
	 * @param string $id Snippet ID to clone.
	 * @return string|false The new snippet's ID, or false if the source was not found.
	 */
	public static function duplicate( $id ) {
		$source = self::get( $id );

		if ( null === $source ) {
			return false;
		}

		$copy = $source;
		// A clone must not carry over the original's id, timestamps,
		// or legacy shortcode/num identifiers — those are per-record
		// values, not something a duplicate should share.
		unset( $copy['id'], $copy['modified'], $copy['created'], $copy['shortcode_id'], $copy['snip_num_id'] );

		$copy['name']    = self::generate_clone_name( isset( $source['name'] ) ? $source['name'] : '' );
		$copy['status']  = 'inactive';
		$copy['trashed'] = false;

		return self::insert( $copy );
	}

	/**
	 * Builds a clone's display name from its source: strips any
	 * existing "(Copy)" / "(Copy 2)" suffix down to the same base
	 * name every generation of clone shares, then appends the first
	 * suffix not already in use among current, non-trashed snippets.
	 * This keeps repeated or chained cloning (a clone of a clone)
	 * from producing ambiguous duplicate names or ever-growing
	 * "(Copy) (Copy) (Copy)" chains — every clone is named
	 * "X (Copy)", "X (Copy 2)", "X (Copy 3)", and so on.
	 *
	 * @param string $source_name The name being cloned from.
	 * @return string
	 */
	private static function generate_clone_name( $source_name ) {
		$source_name = (string) $source_name;

		// Peel off a trailing "(Copy)" or "(Copy N)" so cloning a
		// clone reuses the original base name instead of stacking
		// another suffix onto it.
		$base = preg_replace( '/\s*\(Copy(?:\s+\d+)?\)\s*$/', '', $source_name );
		$base = '' !== $base ? $base : $source_name;

		$existing_names = wp_list_pluck( self::get_all( false ), 'name' );

		/* translators: %s: original snippet name. */
		$first_choice = sprintf( __( '%s (Copy)', 'snipcore' ), $base );
		if ( ! in_array( $first_choice, $existing_names, true ) ) {
			return $first_choice;
		}

		$n = 2;
		do {
			/* translators: 1: original snippet name, 2: copy number. */
			$candidate = sprintf( __( '%1$s (Copy %2$d)', 'snipcore' ), $base, $n );
			++$n;
		} while ( in_array( $candidate, $existing_names, true ) );

		return $candidate;
	}

	/**
	 * Moves a snippet to trash (soft delete).
	 *
	 * @param string $id Snippet ID.
	 * @return bool True on success, false if the snippet was not found.
	 */
	public static function trash( $id ) {
		return self::update( $id, array_merge( (array) self::get( $id ), array( 'trashed' => true ) ) );
	}

	/**
	 * Restores a trashed snippet back to the main list. The snippet
	 * comes back inactive-or-whatever-it-was — status is untouched by
	 * this call, only 'trashed' flips back to false — so a restored
	 * snippet never silently starts executing again; an admin still
	 * has to explicitly (re)activate it, same as any newly added one.
	 *
	 * Only operates on a snippet that is actually trashed — this is
	 * the mirror of delete_permanently()'s guard below, keeping
	 * "restore" and "delete" meaningful only as Trash-tab actions on
	 * Trash-tab items, never as a side door for touching a live one.
	 *
	 * @param string $id Snippet ID.
	 * @return bool True on success, false if the snippet was not found or was not trashed.
	 */
	public static function restore( $id ) {
		$snippet = self::get( $id );

		if ( null === $snippet || empty( $snippet['trashed'] ) ) {
			return false;
		}

		return self::update( $id, array_merge( $snippet, array( 'trashed' => false ) ) );
	}

	/**
	 * Permanently removes a trashed snippet from storage. Unlike
	 * trash(), this is not reversible — the record is dropped from
	 * the snippets array entirely, not merely flagged.
	 *
	 * Only ever deletes a snippet that is already trashed. This is a
	 * deliberate guard, not an incidental one: it means permanent
	 * deletion can never be reached in one step for a live (non-
	 * trashed) snippet — trash() must run first — so "Trash" stays
	 * the only path that removes a snippet from execution/the main
	 * list, and "Delete Permanently" stays a second, distinct step an
	 * admin takes only from within the Trash tab.
	 *
	 * @param string $id Snippet ID.
	 * @return bool True on success, false if the snippet was not found or was not trashed.
	 */
	public static function delete_permanently( $id ) {
		$snippets = self::read();
		$found    = false;
		$kept     = array();

		foreach ( $snippets as $snippet ) {
			if ( isset( $snippet['id'] ) && $snippet['id'] === $id ) {
				if ( empty( $snippet['trashed'] ) ) {
					// Not trashed: refuse, and stop early rather than
					// continuing to build $kept — nothing should be
					// written for a no-op/refused call.
					return false;
				}
				$found = true;
				continue;
			}
			$kept[] = $snippet;
		}

		if ( $found ) {
			self::write( $kept );
		}

		return $found;
	}

	/**
	 * Returns only the trashed snippets, in no particular order (the
	 * caller sorts as needed — same pattern as get_all()).
	 *
	 * @return array
	 */
	public static function get_trashed() {
		return array_values(
			array_filter(
				self::read(),
				static function ( $snippet ) {
					return ! empty( $snippet['trashed'] );
				}
			)
		);
	}

	/**
	 * Returns active, non-trashed snippets — the only ones eligible
	 * to be executed at runtime.
	 *
	 * @return array
	 */
	public static function get_active() {
		return array_values(
			array_filter(
				self::get_all( false ),
				static function ( $snippet ) {
					return isset( $snippet['status'] ) && 'active' === $snippet['status'];
				}
			)
		);
	}

	/**
	 * Sets the activation status for a snippet.
	 *
	 * @param string $id     Snippet ID.
	 * @param string $status Either 'active' or 'inactive'.
	 * @return bool True on success, false if the snippet was not found.
	 */
	public static function set_status( $id, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$snippet = self::get( $id );

		// A trashed snippet's status is not toggleable from outside
		// the Trash flow (its Toggle control is hidden there) — this
		// isn't a strict execution safety net (get_active() already
		// excludes anything trashed regardless of status) but it
		// keeps the stored status from silently drifting to 'active'
		// while sitting in the Trash, which would be surprising the
		// moment the snippet is later restored.
		if ( null === $snippet || ! empty( $snippet['trashed'] ) ) {
			return false;
		}

		return self::update( $id, array_merge( $snippet, array( 'status' => $status ) ) );
	}
}

// Multisite: switch_to_blog()/restore_current_blog() can be called
// mid-request by WordPress core or another plugin, changing which
// site's 'snipcore_snippets' option get_option() would return. Reset
// the request-scoped cache on every such switch so it's always
// re-read fresh for whichever blog is currently active, rather than
// serving another site's snippets after a switch.
add_action( 'switch_blog', array( 'SnipCore_Snippets', 'reset_cache' ) );
