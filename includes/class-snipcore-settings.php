<?php
/**
 * Shared settings helper.
 *
 * Single source of truth for the General settings' option shape and
 * defaults, so the activator, the upgrade routine, and the admin UI
 * all read/write the exact same structure and nothing drifts out of
 * sync between them.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Settings
 */
class SnipCore_Settings {

	/**
	 * Option name storing the General settings array.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'snipcore_settings';

	/**
	 * Allowed values for the "Save & Activate default action" setting.
	 *
	 * @var string[]
	 */
	const SAVE_ACTIONS = array( 'activate', 'save' );

	/**
	 * Allowed values for the "Snippets List Order" setting.
	 *
	 * @var string[]
	 */
	const LIST_ORDERS = array( 'name_asc', 'name_desc', 'modified_desc', 'modified_asc' );

	/**
	 * Returns the default value for every General-tab setting.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'delete_data_on_uninstall'  => false,
			'default_save_action'       => 'activate',
			'enable_tags'               => true,
			'enable_descriptions'       => true,
			'description_editor_height' => 3,
			'list_order'                => 'name_asc',
		);
	}

	/**
	 * Returns the current General settings, merged over the defaults
	 * so every key is always present regardless of what was stored
	 * by an older version of the plugin.
	 *
	 * @return array
	 */
	public static function get_all() {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize( array_merge( self::get_defaults(), $stored ) );
	}

	/**
	 * Returns a single setting's current value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::get_all();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Sanitizes/normalizes a full settings array to guaranteed-valid
	 * values and types. Used both when reading (to tolerate data
	 * saved by an older version) and when saving.
	 *
	 * @param array $input Raw settings array.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::get_defaults();

		$default_save_action = isset( $input['default_save_action'] ) ? sanitize_key( $input['default_save_action'] ) : $defaults['default_save_action'];
		if ( ! in_array( $default_save_action, self::SAVE_ACTIONS, true ) ) {
			$default_save_action = $defaults['default_save_action'];
		}

		$list_order = isset( $input['list_order'] ) ? sanitize_key( $input['list_order'] ) : $defaults['list_order'];
		if ( ! in_array( $list_order, self::LIST_ORDERS, true ) ) {
			$list_order = $defaults['list_order'];
		}

		$description_editor_height = isset( $input['description_editor_height'] ) ? (int) $input['description_editor_height'] : $defaults['description_editor_height'];
		if ( $description_editor_height < 1 ) {
			$description_editor_height = 1;
		} elseif ( $description_editor_height > 20 ) {
			$description_editor_height = 20;
		}

		return array(
			'delete_data_on_uninstall'  => ! empty( $input['delete_data_on_uninstall'] ),
			'default_save_action'       => $default_save_action,
			'enable_tags'               => ! empty( $input['enable_tags'] ),
			'enable_descriptions'       => ! empty( $input['enable_descriptions'] ),
			'description_editor_height' => $description_editor_height,
			'list_order'                => $list_order,
		);
	}

	/**
	 * Persists a full, sanitized settings array to the database.
	 *
	 * @param array $settings Settings to save (will be sanitized).
	 * @return bool True if the option was updated (or was already current).
	 */
	public static function save( array $settings ) {
		return update_option( self::OPTION_NAME, self::sanitize( $settings ) );
	}

	/**
	 * Ensures the stored option contains every current setting key,
	 * backfilling any that are missing (e.g. after an upgrade from a
	 * version that only knew about a subset of them) so the value on
	 * disk — not just the runtime-merged value — is always complete.
	 *
	 * @return void
	 */
	public static function ensure_persisted() {
		$stored = get_option( self::OPTION_NAME, false );

		if ( false === $stored ) {
			add_option( self::OPTION_NAME, self::get_defaults() );
			return;
		}

		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( self::get_defaults(), $stored );

		// Only write back if something was actually missing/invalid,
		// to avoid needless option writes on every request.
		if ( $merged !== $stored || self::sanitize( $merged ) !== $stored ) {
			self::save( $merged );
		}
	}
}
