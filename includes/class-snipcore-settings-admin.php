<?php
/**
 * Settings admin controller: the Settings page (General and
 * Import/Export tabs), its Settings API registration, and the
 * request/callback handlers behind them.
 *
 * Extracted from SnipCore_Admin (Architecture Fix Phase 4) so this
 * class owns exactly the Settings-screen responsibility: nothing
 * here changes behavior — every URL, form action, nonce, capability
 * check, redirect, notice, option name, default, and storage call is
 * unchanged from the original SnipCore_Admin methods this class was
 * built from.
 *
 * A handful of small helpers used both by these screens and by the
 * Snippets/Header-Footer screens (get_settings(), get_list_url(),
 * get_type_label(), get_list_order_choices(), send_export_download(),
 * enqueue_assets()) remain on SnipCore_Admin to avoid duplicating
 * them; this class calls them back through the $admin reference it
 * is constructed with.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Settings_Admin
 */
class SnipCore_Settings_Admin {

	/**
	 * Slug used for the top-level menu and the "All Snippets" page.
	 *
	 * Mirrors SnipCore_Admin::MENU_SLUG (a fixed plugin routing
	 * slug, not configurable data) so this class does not need a
	 * cross-class constant reference for its own redirect URLs.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'snipcore';

	/**
	 * Slug used for the Settings submenu page.
	 *
	 * Mirrors SnipCore_Admin::SETTINGS_SLUG.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'snipcore-settings';

	/**
	 * The SnipCore_Admin instance this controller was constructed
	 * with, used only to call the small set of helpers shared with
	 * the Snippets and Header & Footer screens (see class docblock
	 * above).
	 *
	 * @var SnipCore_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 *
	 * @param SnipCore_Admin $admin The SnipCore_Admin instance, used for shared helper calls.
	 */
	public function __construct( SnipCore_Admin $admin ) {
		$this->admin = $admin;
	}

	/**
	 * Hook registration for the Settings admin screen.
	 *
	 * Same hook names and callback priorities as the original
	 * SnipCore_Admin::init() registrations for these actions.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_action_snipcore_import_cancel', array( $this, 'handle_import_cancel' ) );
		add_action( 'admin_post_snipcore_export_snippets', array( $this, 'handle_export_snippets' ) );
		add_action( 'admin_post_snipcore_export_complete', array( $this, 'handle_export_complete' ) );
		add_action( 'admin_post_snipcore_import_upload', array( $this, 'handle_import_upload' ) );
		add_action( 'admin_post_snipcore_import_confirm', array( $this, 'handle_import_confirm' ) );
		add_action( 'admin_post_snipcore_safe_mode_toggle', array( $this, 'handle_safe_mode_toggle' ) );
	}

	/**
	 * Returns the allowed values for the "Default Save Action" field.
	 *
	 * @return string[]
	 */
	private function get_default_save_action_choices() {
		return SnipCore_Settings::SAVE_ACTIONS;
	}

	/**
	 * Registers the settings group/fields via the Settings API.
	 *
	 * The uninstall routine reads delete_data_on_uninstall to decide
	 * whether to remove stored snippets/settings; the remaining
	 * fields shape the Add/Edit Snippet screen and the All Snippets
	 * list table.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'snipcore_settings_group',
			'snipcore_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => SnipCore_Admin::get_settings_defaults(),
			)
		);

		add_settings_section(
			'snipcore_general_section',
			'',
			'__return_false',
			self::SETTINGS_SLUG
		);

		add_settings_field(
			'snipcore_delete_data_on_uninstall',
			__( 'Complete Uninstall', 'snipcore' ),
			array( $this, 'render_delete_data_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);

		add_settings_field(
			'snipcore_default_save_action',
			__( 'Default Save Action', 'snipcore' ),
			array( $this, 'render_default_save_action_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);

		add_settings_field(
			'snipcore_enable_tags',
			__( 'Snippet Tags', 'snipcore' ),
			array( $this, 'render_enable_tags_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);

		add_settings_field(
			'snipcore_enable_descriptions',
			__( 'Snippet Descriptions', 'snipcore' ),
			array( $this, 'render_enable_descriptions_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);

		add_settings_field(
			'snipcore_description_editor_height',
			__( 'Description Editor Height', 'snipcore' ),
			array( $this, 'render_description_editor_height_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);

		add_settings_field(
			'snipcore_list_order',
			__( 'Snippets List Order', 'snipcore' ),
			array( $this, 'render_list_order_field' ),
			self::SETTINGS_SLUG,
			'snipcore_general_section'
		);
	}

	/**
	 * Renders the "Complete Uninstall" checkbox field.
	 *
	 * Checking this box is a destructive, irreversible choice, so the
	 * checkbox carries a data attribute that the enqueued JS uses to
	 * require an explicit browser confirmation before the box can be
	 * checked. Normal deactivation never reads or acts on this
	 * setting — only uninstall.php does.
	 *
	 * @return void
	 */
	public function render_delete_data_field() {
		$settings = SnipCore_Admin::get_settings();
		?>
		<div class="snipcore-toggle-row">
			<label class="snipcore-settings-switch" for="snipcore-delete-data-on-uninstall">
				<input
					type="checkbox"
					id="snipcore-delete-data-on-uninstall"
					class="snipcore-complete-uninstall-toggle snipcore-settings-switch-input"
					name="snipcore_settings[delete_data_on_uninstall]"
					value="1"
					data-confirm="<?php echo esc_attr__( 'Enable Complete Uninstall?\n\nWhen SnipCore is uninstalled (not just deactivated), ALL snippets and ALL SnipCore settings will be permanently deleted. This cannot be undone.\n\nDeactivating the plugin will NOT delete anything — this only applies when it is removed entirely.', 'snipcore' ); ?>"
					<?php checked( $settings['delete_data_on_uninstall'] ); ?>
				/>
				<span class="snipcore-settings-switch-slider" aria-hidden="true"></span>
			</label>
			<label for="snipcore-delete-data-on-uninstall" class="snipcore-toggle-row-label">
				<?php esc_html_e( 'Permanently delete all SnipCore snippets and settings when the plugin is uninstalled.', 'snipcore' ); ?>
			</label>
		</div>
		<p class="description">
			<?php esc_html_e( 'Only applies on uninstall — deactivating the plugin never deletes anything.', 'snipcore' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the "Save & Activate default action" radio field.
	 *
	 * Controls which button on the Add/Edit Snippet screen is styled
	 * as the primary button and used as the default when a form is
	 * submitted without an explicit button value (e.g. the Enter key).
	 *
	 * @return void
	 */
	public function render_default_save_action_field() {
		$settings = SnipCore_Admin::get_settings();
		$current  = in_array( $settings['default_save_action'], $this->get_default_save_action_choices(), true )
			? $settings['default_save_action']
			: 'activate';
		?>
		<fieldset>
			<div class="snipcore-inline-radio-group">
				<label for="snipcore-default-save-action-activate">
					<input
						type="radio"
						id="snipcore-default-save-action-activate"
						name="snipcore_settings[default_save_action]"
						value="activate"
						<?php checked( 'activate', $current ); ?>
					/>
					<?php esc_html_e( 'Save & Activate', 'snipcore' ); ?>
				</label>
				<label for="snipcore-default-save-action-save">
					<input
						type="radio"
						id="snipcore-default-save-action-save"
						name="snipcore_settings[default_save_action]"
						value="save"
						<?php checked( 'save', $current ); ?>
					/>
					<?php esc_html_e( 'Save Snippet', 'snipcore' ); ?>
				</label>
			</div>
			<p class="description"><?php esc_html_e( 'Which action is used as the primary button and default when saving a snippet.', 'snipcore' ); ?></p>
		</fieldset>
		<?php
	}

	/**
	 * Renders the "Enable Snippet Tags" checkbox field.
	 *
	 * @return void
	 */
	public function render_enable_tags_field() {
		$settings = SnipCore_Admin::get_settings();
		?>
		<div class="snipcore-toggle-row">
			<label class="snipcore-settings-switch" for="snipcore-enable-tags">
				<input
					type="checkbox"
					id="snipcore-enable-tags"
					class="snipcore-settings-switch-input"
					name="snipcore_settings[enable_tags]"
					value="1"
					<?php checked( $settings['enable_tags'] ); ?>
				/>
				<span class="snipcore-settings-switch-slider" aria-hidden="true"></span>
			</label>
			<label for="snipcore-enable-tags" class="snipcore-toggle-row-label">
				<?php esc_html_e( 'Show the Tags field on the Add/Edit Snippet screen.', 'snipcore' ); ?>
			</label>
		</div>
		<p class="description"><?php esc_html_e( 'Also adds a Tags column to the All Snippets list. Existing tags are kept even when hidden.', 'snipcore' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Enable Snippet Descriptions" checkbox field.
	 *
	 * @return void
	 */
	public function render_enable_descriptions_field() {
		$settings = SnipCore_Admin::get_settings();
		?>
		<div class="snipcore-toggle-row">
			<label class="snipcore-settings-switch" for="snipcore-enable-descriptions">
				<input
					type="checkbox"
					id="snipcore-enable-descriptions"
					class="snipcore-settings-switch-input"
					name="snipcore_settings[enable_descriptions]"
					value="1"
					<?php checked( $settings['enable_descriptions'] ); ?>
				/>
				<span class="snipcore-settings-switch-slider" aria-hidden="true"></span>
			</label>
			<label for="snipcore-enable-descriptions" class="snipcore-toggle-row-label">
				<?php esc_html_e( 'Show the Description field on the Add/Edit Snippet screen.', 'snipcore' ); ?>
			</label>
		</div>
		<p class="description"><?php esc_html_e( 'Also adds a Description column to the All Snippets list. Existing descriptions are kept even when hidden.', 'snipcore' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Description Editor Height" number field.
	 *
	 * @return void
	 */
	public function render_description_editor_height_field() {
		$settings = SnipCore_Admin::get_settings();
		$height   = (int) $settings['description_editor_height'];
		if ( $height < 1 ) {
			$height = 3;
		}
		?>
		<input
			type="number"
			id="snipcore-description-editor-height"
			name="snipcore_settings[description_editor_height]"
			class="small-text"
			min="1"
			max="20"
			step="1"
			value="<?php echo esc_attr( $height ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Visible text rows for the Description field. Default: 3.', 'snipcore' ); ?></p>
		<?php
	}

	/**
	 * Renders the "Snippets List Order" dropdown field.
	 *
	 * @return void
	 */
	public function render_list_order_field() {
		$settings = SnipCore_Admin::get_settings();
		$choices  = $this->admin->get_list_order_choices();
		$current  = array_key_exists( $settings['list_order'], $choices ) ? $settings['list_order'] : 'name_asc';
		?>
		<select id="snipcore-list-order" name="snipcore_settings[list_order]">
			<?php foreach ( $choices as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Default sort order for the All Snippets list.', 'snipcore' ); ?></p>
		<?php
	}

	/**
	 * Streams a plugin-generated payload to the browser as a file
	 * download. Delegates to SnipCore_Admin::send_export_download(),
	 * the shared implementation used by both this class and
	 * SnipCore_Snippets_Admin.
	 *
	 * Retrieves the stored, non-trashed snippets, sorted per the
	 * "Snippets List Order" setting.
	 *
	 * @return array
	 */
	private function get_snippets() {
		return $this->admin->get_sorted_snippets();
	}

	/**
	 * Handles the "Export Selected" form from the Import/Export
	 * settings tab: outputs the chosen snippets, in the chosen format
	 * (JSON or XML), as a single file download.
	 *
	 * @return void
	 */
	public function handle_export_snippets() {

		check_admin_referer( 'snipcore_export_snippets' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$format = isset( $_POST['snipcore_export_format'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_export_format'] ) ) : 'json';
		$format = in_array( $format, array( 'json', 'xml' ), true ) ? $format : 'json';

		$selected_ids = isset( $_POST['snipcore_export_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['snipcore_export_ids'] ) )
			: array();

		$snippets = SnipCore_Snippets::get_all( false );

		if ( ! empty( $selected_ids ) ) {
			$selected_ids = array_flip( $selected_ids );
			$snippets     = array_values(
				array_filter(
					$snippets,
					static function ( $snippet ) use ( $selected_ids ) {
						return isset( $snippet['id'] ) && isset( $selected_ids[ $snippet['id'] ] );
					}
				)
			);
		}

		if ( empty( $snippets ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => self::SETTINGS_SLUG,
						'tab'           => 'import_export',
						'export_error'  => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$snippets = array_map(
			static function ( $snippet ) {
				unset( $snippet['trashed'] );
				return $snippet;
			},
			$snippets
		);

		$is_xml   = ( 'xml' === $format );
		$filename = 'snipcore-snippets-' . gmdate( 'Y-m-d' ) . ( $is_xml ? '.xml' : '.json' );
		$body     = $is_xml ? SnipCore_Import_Export::to_xml( $snippets ) : SnipCore_Import_Export::to_json( $snippets );

		$this->admin->send_export_download( $filename, $is_xml ? 'application/xml' : 'application/json', $body );
	}

	/**
	 * Handles the "Complete JSON Export" action: a single click that
	 * downloads every non-trashed snippet with every stored field
	 * (id, name, type, status, location, priority, description, tags,
	 * code, created, modified), wrapped in a small metadata envelope
	 * together with the current General settings — see
	 * SnipCore_Import_Export::build_complete_export(). Unlike "Export
	 * Selected", this always includes everything and ignores any
	 * selection made in the Export form.
	 *
	 * @return void
	 */
	public function handle_export_complete() {

		check_admin_referer( 'snipcore_export_complete' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$body     = SnipCore_Import_Export::build_complete_export();
		$filename = 'snipcore-complete-export-' . gmdate( 'Y-m-d' ) . '.json';

		$this->admin->send_export_download( $filename, 'application/json', $body );
	}

	/**
	 * Sanitizes settings input before saving.
	 *
	 * @param mixed $input Raw input from the settings form.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		return SnipCore_Settings::sanitize( $input );
	}

	/**
	 * Returns the per-user transient key used to hold a parsed import
	 * batch between the upload step and the confirm step. Scoped to
	 * the current user so two admins previewing imports at the same
	 * time never see or confirm each other's files.
	 *
	 * @return string
	 */
	private function get_import_transient_key() {
		return 'snipcore_import_preview_' . get_current_user_id();
	}

	/**
	 * Normalizes a $_FILES entry into a flat list of single-file
	 * arrays, whether the browser submitted one file or several (the
	 * upload field uses name="snipcore_import_file[]" with the
	 * multiple attribute, so PHP groups the sub-keys by index).
	 *
	 * @param array|null $files Raw $_FILES['snipcore_import_file'] entry.
	 * @return array[]
	 */
	private function normalize_uploaded_files( $files ) {
		if ( empty( $files ) || ! isset( $files['name'] ) ) {
			return array();
		}

		if ( ! is_array( $files['name'] ) ) {
			return array( $files );
		}

		$normalized = array();
		foreach ( $files['name'] as $i => $name ) {
			if ( '' === $name && UPLOAD_ERR_NO_FILE === $files['error'][ $i ] ) {
				continue;
			}
			$normalized[] = array(
				'name'     => $name,
				'type'     => isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '',
				'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? $files['tmp_name'][ $i ] : '',
				'error'    => isset( $files['error'][ $i ] ) ? $files['error'][ $i ] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $files['size'][ $i ] ) ? $files['size'][ $i ] : 0,
			);
		}

		return $normalized;
	}

	/**
	 * Handles the file-upload step of Import: reads and securely
	 * parses every uploaded file (JSON and/or XML, in any mix), then
	 * stashes the resulting snippet records in a short-lived, per-user
	 * transient and redirects to the preview step. Nothing is written
	 * to the snippets store here — see handle_import_confirm().
	 *
	 * @return void
	 */
	public function handle_import_upload() {

		check_admin_referer( 'snipcore_import_upload' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$redirect_args = array(
			'page' => self::SETTINGS_SLUG,
			'tab'  => 'import_export',
		);

		$raw_files = isset( $_FILES['snipcore_import_file'] ) ? $_FILES['snipcore_import_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file upload metadata; contents are only ever read via tmp_name and parsed securely below, never trusted directly.
		$files     = $this->normalize_uploaded_files( $raw_files );

		if ( empty( $files ) ) {
			$this->redirect_import_error( $redirect_args );
		}

		$items       = array();
		$errors      = array();
		$seen_names  = array();

		foreach ( $files as $file ) {

			$label = '' !== $file['name'] ? sanitize_text_field( $file['name'] ) : __( 'uploaded file', 'snipcore' );

			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				/* translators: %s: file name. */
				$errors[] = sprintf( __( '"%s" failed to upload and was skipped.', 'snipcore' ), $label );
				continue;
			}

			if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
				/* translators: %s: file name. */
				$errors[] = sprintf( __( '"%s" could not be verified and was skipped.', 'snipcore' ), $label );
				continue;
			}

			if ( $file['size'] > SnipCore_Import_Export::MAX_FILE_BYTES ) {
				/* translators: %s: file name. */
				$errors[] = sprintf( __( '"%s" is larger than the 2MB import limit and was skipped.', 'snipcore' ), $label );
				continue;
			}

			$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a PHP-generated upload tmp file, size-capped just above.

			if ( false === $contents || strlen( $contents ) > SnipCore_Import_Export::MAX_FILE_BYTES ) {
				/* translators: %s: file name. */
				$errors[] = sprintf( __( '"%s" could not be read and was skipped.', 'snipcore' ), $label );
				continue;
			}

			$result = SnipCore_Import_Export::parse( $contents, $label, $seen_names );

			$items  = array_merge( $items, $result['items'] );
			$errors = array_merge( $errors, $result['errors'] );

			if ( count( $items ) >= SnipCore_Import_Export::MAX_ITEMS ) {
				$items = array_slice( $items, 0, SnipCore_Import_Export::MAX_ITEMS );
				/* translators: %d: maximum number of snippets accepted in a single import batch. */
				$errors[] = sprintf( __( 'Only the first %d snippets found are shown; any additional ones were ignored.', 'snipcore' ), SnipCore_Import_Export::MAX_ITEMS );
				break;
			}
		}

		if ( empty( $items ) && empty( $errors ) ) {
			$this->redirect_import_error( $redirect_args );
		}

		set_transient(
			$this->get_import_transient_key(),
			array(
				'items'  => $items,
				'errors' => $errors,
			),
			10 * MINUTE_IN_SECONDS
		);

		$redirect_args['step'] = 'preview';
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles the confirm step of Import: reads the batch the admin
	 * reviewed on the preview screen back out of the transient (never
	 * from request input) and inserts only the records they left
	 * checked. Imported snippets are always inserted as new, inactive,
	 * non-trashed records — an administrator must explicitly activate
	 * them afterward, same as any other newly added snippet.
	 *
	 * @return void
	 */
	public function handle_import_confirm() {

		check_admin_referer( 'snipcore_import_confirm' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$redirect_args = array(
			'page' => self::SETTINGS_SLUG,
			'tab'  => 'import_export',
		);

		$transient_key = $this->get_import_transient_key();
		$preview       = get_transient( $transient_key );
		delete_transient( $transient_key );

		if ( ! is_array( $preview ) || empty( $preview['items'] ) ) {
			$this->redirect_import_error( $redirect_args );
		}

		$selected = isset( $_POST['snipcore_import_selected'] )
			? array_map( 'absint', wp_unslash( (array) $_POST['snipcore_import_selected'] ) )
			: array();

		// Indices the admin explicitly opted to rename-on-import rather
		// than skip. Only meaningful for rows the preview flagged as
		// is_duplicate; harmless if sent for anything else.
		$rename = isset( $_POST['snipcore_import_rename'] )
			? array_map( 'absint', wp_unslash( (array) $_POST['snipcore_import_rename'] ) )
			: array();
		$rename = array_flip( $rename );

		$imported = 0;
		$skipped  = 0;

		foreach ( $selected as $index ) {

			if ( ! isset( $preview['items'][ $index ]['snippet'] ) ) {
				continue;
			}

			$item         = $preview['items'][ $index ];
			$data         = $item['snippet'];
			$is_duplicate = ! empty( $item['is_duplicate'] );

			// Never overwrite: a flagged duplicate is only imported if
			// the admin explicitly checked "import as new copy" for
			// that row, in which case the name is disambiguated. A
			// flagged duplicate left unchecked-for-rename is skipped
			// outright rather than silently colliding with (and
			// visually shadowing) the existing snippet.
			if ( $is_duplicate ) {
				if ( ! isset( $rename[ $index ] ) ) {
					++$skipped;
					continue;
				}
				$data['name'] = SnipCore_Snippets::make_unique_name( $data['name'] );
			} elseif ( SnipCore_Snippets::name_exists( $data['name'] ) ) {
				// Belt-and-suspenders: the store changed since the
				// preview was built (e.g. another admin imported the
				// same name in the meantime). Never insert a silent
				// same-name collision — disambiguate instead of
				// skipping, since this wasn't flagged as a choice the
				// admin already made.
				$data['name'] = SnipCore_Snippets::make_unique_name( $data['name'] );
			}

			$data['status']  = 'inactive';
			$data['trashed'] = false;
			unset( $data['id'], $data['created'], $data['modified'] );

			if ( SnipCore_Snippets::insert( $data ) ) {
				++$imported;
			}
		}

		$redirect_args['imported'] = $imported;
		$redirect_args['skipped']  = $skipped;

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles "Cancel" from the Import preview screen: discards the
	 * pending batch without importing anything.
	 *
	 * @return void
	 */
	public function handle_import_cancel() {

		check_admin_referer( 'snipcore_import_cancel' );

		if ( current_user_can( 'manage_options' ) ) {
			delete_transient( $this->get_import_transient_key() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::SETTINGS_SLUG,
					'tab'  => 'import_export',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirects back to the Import/Export tab after a failed import.
	 *
	 * @param array $redirect_args Base redirect query args.
	 * @return void
	 */
	private function redirect_import_error( array $redirect_args ) {
		$redirect_args['import_error'] = '1';
		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Returns the tab definitions for the Settings page.
	 *
	 * @return array
	 */
	private function get_settings_tabs() {
		return array(
			'general'       => __( 'General', 'snipcore' ),
			'import_export' => __( 'Import/Export', 'snipcore' ),
			'safe_mode'     => __( 'Safe Mode', 'snipcore' ),
		);
	}

	/**
	 * Renders the "Settings" page: tab navigation plus the General or
	 * Import/Export tab content. Native WordPress admin markup only
	 * (nav-tab-wrapper, form-table via the Settings API).
	 *
	 * @return void
	 */
	public function render_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs        = $this->get_settings_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab navigation (GET); nothing here mutates state.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'general';
		}
		?>
		<div class="wrap snipcore-settings-wrap">
			<h1><?php esc_html_e( 'SnipCore Settings', 'snipcore' ); ?></h1>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::SETTINGS_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo ( $current_tab === $slug ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php if ( 'import_export' === $current_tab ) : ?>
				<?php $this->render_import_export_tab(); ?>
			<?php elseif ( 'safe_mode' === $current_tab ) : ?>
				<?php $this->render_safe_mode_tab(); ?>
			<?php else : ?>
				<?php $this->render_general_tab(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the General tab: the Settings API form for the single
	 * registered field.
	 *
	 * @return void
	 */
	private function render_general_tab() {
		// Renders the standard "Settings saved." success notice that the
		// WordPress Settings API queues on the options.php redirect.
		// Screens registered the normal way (options-general.php etc.)
		// get this for free from wp-admin; a custom top-level page like
		// this one has to call it explicitly or the confirmation never
		// appears even though the save itself worked.
		settings_errors( 'snipcore_settings_group' );
		?>
		<form action="options.php" method="post" class="snipcore-loading-on-submit">
			<?php settings_fields( 'snipcore_settings_group' ); ?>

			<div class="snipcore-settings-columns">
				<div class="snipcore-settings-column">
					<div class="snipcore-io-section snipcore-settings-group snipcore-danger-zone">
						<h2><?php esc_html_e( 'Danger Zone', 'snipcore' ); ?></h2>
						<div class="snipcore-settings-field">
							<?php $this->render_delete_data_field(); ?>
						</div>
					</div>

					<div class="snipcore-io-section snipcore-settings-group">
						<h2><?php esc_html_e( 'Snippet List', 'snipcore' ); ?></h2>
						<div class="snipcore-settings-field">
							<?php $this->render_list_order_field(); ?>
						</div>
					</div>
				</div>

				<div class="snipcore-settings-column">
					<div class="snipcore-io-section snipcore-settings-group">
						<h2><?php esc_html_e( 'Snippet Fields', 'snipcore' ); ?></h2>
						<div class="snipcore-settings-field">
							<?php $this->render_enable_tags_field(); ?>
						</div>
						<div class="snipcore-settings-field">
							<?php $this->render_enable_descriptions_field(); ?>
							<div class="snipcore-nested-field" id="snipcore-description-height-wrap">
								<?php $this->render_description_editor_height_field(); ?>
							</div>
						</div>
					</div>
				</div>
			</div>

			<?php submit_button( __( 'Save Changes', 'snipcore' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Handles the Safe Mode tab's toggle/dismiss form submission
	 * (enable, disable, or dismiss the last auto-disable notice).
	 * Behavior is unchanged from the previous standalone Safe Mode
	 * page — only the surrounding UI moved.
	 *
	 * @return void
	 */
	public function handle_safe_mode_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		check_admin_referer( 'snipcore_safe_mode_toggle' );

		$action = isset( $_POST['snipcore_safe_mode_action'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_safe_mode_action'] ) ) : '';

		if ( 'enable' === $action ) {
			SnipCore_Safe_Mode::enable();
		} elseif ( 'disable' === $action ) {
			SnipCore_Safe_Mode::disable();
		} elseif ( 'dismiss_error' === $action ) {
			SnipCore_Safe_Mode::clear_last_error();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::SETTINGS_SLUG,
					'tab'  => 'safe_mode',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the Settings > Safe Mode tab: the emergency
	 * "Disable All Snippets" switch and the most recent auto-disable
	 * notice, if any. Same functionality as the snippet's original
	 * standalone admin page, restyled to match the bordered/card
	 * layout the General and Import/Export tabs already use
	 * (.snipcore-io-section.snipcore-settings-group columns, the same
	 * heading sizes, spacing, and button styles) rather than the
	 * plain \.wrap markup it used before.
	 *
	 * @return void
	 */
	private function render_safe_mode_tab() {

		$is_enabled       = SnipCore_Safe_Mode::is_enabled();
		$forced_by_config = defined( 'SNIPCORE_SAFE_MODE' ) && SNIPCORE_SAFE_MODE;
		$last_error       = SnipCore_Safe_Mode::get_last_error();
		$toggle_url       = admin_url( 'admin-post.php' );
		?>
		<div class="snipcore-settings-columns">
			<div class="snipcore-settings-column">
				<div class="snipcore-io-section snipcore-settings-group">
					<h2><?php esc_html_e( 'Emergency Switch', 'snipcore' ); ?></h2>
					<div class="snipcore-settings-field">
						<p class="description">
							<?php esc_html_e( 'Immediately stop all custom snippets (PHP, CSS, JS, and HTML) from running, without deleting or modifying any snippet. Use this if a snippet is causing problems and you need the site back to normal right away.', 'snipcore' ); ?>
						</p>
					</div>

					<?php if ( $forced_by_config ) : ?>
						<div class="snipcore-settings-field">
							<p class="description">
								<?php esc_html_e( 'Safe Mode is currently forced on via the SNIPCORE_SAFE_MODE constant in wp-config.php. Remove it, or set it to false, to allow toggling Safe Mode from this page.', 'snipcore' ); ?>
							</p>
						</div>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( $toggle_url ); ?>">
							<?php wp_nonce_field( 'snipcore_safe_mode_toggle' ); ?>
							<input type="hidden" name="action" value="snipcore_safe_mode_toggle" />
							<div class="snipcore-settings-field">
								<?php if ( $is_enabled ) : ?>
									<p>
										<strong><?php esc_html_e( 'Safe Mode is currently ON.', 'snipcore' ); ?></strong>
										<?php esc_html_e( 'No snippets are executing or rendering.', 'snipcore' ); ?>
									</p>
									<input type="hidden" name="snipcore_safe_mode_action" value="disable" />
									<?php submit_button( __( 'Turn Safe Mode Off (resume snippets)', 'snipcore' ), 'primary', 'submit', false ); ?>
								<?php else : ?>
									<p><?php esc_html_e( 'Safe Mode is currently OFF. Snippets are running normally.', 'snipcore' ); ?></p>
									<input type="hidden" name="snipcore_safe_mode_action" value="enable" />
									<?php submit_button( __( 'Turn Safe Mode On (disable all snippets)', 'snipcore' ), 'delete', 'submit', false ); ?>
								<?php endif; ?>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $last_error ) : ?>
				<div class="snipcore-settings-column">
					<div class="snipcore-io-section snipcore-settings-group snipcore-danger-zone">
						<h2><?php esc_html_e( 'Last Automatically Disabled Snippet', 'snipcore' ); ?></h2>
						<div class="snipcore-settings-field">
							<p>
								<strong><?php echo esc_html( isset( $last_error['name'] ) ? $last_error['name'] : '' ); ?></strong>
								<br />
								<span class="description">
									<?php
									printf(
										/* translators: %s: snippet ID. */
										esc_html__( 'ID: %s', 'snipcore' ),
										esc_html( isset( $last_error['id'] ) ? $last_error['id'] : '' )
									);
									?>
								</span>
							</p>
							<?php if ( ! empty( $last_error['message'] ) ) : ?>
								<p><code><?php echo esc_html( $last_error['message'] ); ?></code></p>
							<?php endif; ?>
							<?php if ( ! empty( $last_error['time'] ) ) : ?>
								<p class="description"><?php echo esc_html( $last_error['time'] ); ?></p>
							<?php endif; ?>
						</div>
						<form method="post" action="<?php echo esc_url( $toggle_url ); ?>">
							<?php wp_nonce_field( 'snipcore_safe_mode_toggle' ); ?>
							<input type="hidden" name="action" value="snipcore_safe_mode_toggle" />
							<input type="hidden" name="snipcore_safe_mode_action" value="dismiss_error" />
							<?php submit_button( __( 'Dismiss', 'snipcore' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the Import/Export tab. Shows the Import preview screen
	 * when a parsed batch is pending for the current user; otherwise
	 * shows the normal Export controls and Import upload form.
	 *
	 * @return void
	 */
	private function render_import_export_tab() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the import-action redirect result; the action itself was already nonce/capability-checked in handle_import_confirm().
		$imported     = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$skipped      = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$import_error = isset( $_GET['import_error'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the export-action redirect result; the action itself was already nonce/capability-checked in handle_export().
		$export_error = isset( $_GET['export_error'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only step navigation (GET); nothing here mutates state.
		$step         = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		$preview      = ( 'preview' === $step ) ? get_transient( $this->get_import_transient_key() ) : false;
		?>
		<?php if ( $export_error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'No snippets were selected to export. Please check one or more snippets and try again.', 'snipcore' ); ?></p>
			</div>
		<?php elseif ( $import_error ) : ?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'The uploaded file(s) could not be read. Please upload one or more valid SnipCore export files (.json or .xml).', 'snipcore' ); ?></p>
			</div>
		<?php elseif ( null !== $imported ) : ?>
			<div class="notice <?php echo ( 0 === $imported ) ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
				<p>
					<?php
					printf(
						/* translators: %d: number of snippets imported. */
						esc_html(
							/* translators: %d: number of snippets imported. */
							_n(
								'%d snippet imported. New snippets are inactive until you activate them.',
								'%d snippets imported. New snippets are inactive until you activate them.',
								$imported,
								'snipcore'
							)
						),
						(int) $imported
					);
					?>
				</p>
				<?php if ( $skipped > 0 ) : ?>
					<p>
						<?php
						printf(
							/* translators: %d: number of duplicate-named snippets skipped. */
							esc_html(
								/* translators: %d: number of duplicate-named snippets skipped. */
								_n(
									'%d snippet was skipped because it had the same name as an existing snippet and "Import as new copy" was not selected for it.',
									'%d snippets were skipped because they had the same name as existing snippets and "Import as new copy" was not selected for them.',
									$skipped,
									'snipcore'
								)
							),
							(int) $skipped
						);
						?>
					</p>
				<?php endif; ?>
				<?php if ( $imported > 0 ) : ?>
					<p><a href="<?php echo esc_url( $this->admin->get_list_url() ); ?>"><?php esc_html_e( 'View All Snippets', 'snipcore' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( is_array( $preview ) ) : ?>
			<?php $this->render_import_preview( $preview ); ?>
		<?php else : ?>
			<div class="snipcore-io-columns">
				<div class="snipcore-io-column snipcore-io-column-export">
					<?php $this->render_export_section(); ?>
				</div>
				<div class="snipcore-io-column snipcore-io-column-import">
					<?php $this->render_import_upload_form(); ?>
				</div>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the Export controls: a format choice (JSON/XML) and a
	 * checklist of which non-trashed snippets to include, submitting
	 * to handle_export_snippets() for a same-request file download.
	 *
	 * @return void
	 */
	private function render_export_section() {

		$snippets = $this->get_snippets();
		?>
		<div class="snipcore-io-section">
			<h2><?php esc_html_e( 'Export', 'snipcore' ); ?></h2>

			<?php if ( empty( $snippets ) ) : ?>
				<p><em><?php esc_html_e( 'There are no snippets to export yet.', 'snipcore' ); ?></em></p>
			<?php else : ?>
				<?php
				$complete_export_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'   => self::SETTINGS_SLUG,
							'tab'    => 'import_export',
							'action' => 'snipcore_export_complete',
						),
						admin_url( 'admin-post.php' )
					),
					'snipcore_export_complete'
				);
				?>
				<div class="snipcore-io-primary-action">
					<p><?php esc_html_e( 'A complete, restorable backup of every snippet.', 'snipcore' ); ?></p>
					<a href="<?php echo esc_url( $complete_export_url ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Download Complete JSON Export', 'snipcore' ); ?></a>
				</div>

				<p class="snipcore-io-divider"><?php esc_html_e( 'Or export a selection', 'snipcore' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="snipcore-export-form" class="snipcore-loading-on-submit">
					<?php wp_nonce_field( 'snipcore_export_snippets' ); ?>
					<input type="hidden" name="action" value="snipcore_export_snippets" />

					<div class="snipcore-io-card">
						<p class="snipcore-io-card-heading"><?php esc_html_e( 'Format', 'snipcore' ); ?></p>
						<p class="snipcore-io-format-choice">
							<label>
								<input type="radio" name="snipcore_export_format" value="json" checked="checked" />
								<?php esc_html_e( 'JSON', 'snipcore' ); ?>
							</label>
							<label>
								<input type="radio" name="snipcore_export_format" value="xml" />
								<?php esc_html_e( 'XML', 'snipcore' ); ?>
							</label>
						</p>

						<p class="snipcore-io-card-heading"><?php esc_html_e( 'Select Snippets', 'snipcore' ); ?></p>
						<div class="snipcore-select-all-row">
							<label>
								<input type="checkbox" id="snipcore-export-select-all" checked="checked" />
								<?php esc_html_e( 'Select all snippets', 'snipcore' ); ?>
							</label>
						</div>
						<ul class="snipcore-export-list">
							<?php
							$type_lang_map = array(
								'PHP'  => '#7277AE',
								'HTML' => '#DC4821',
								'CSS'  => '#196FB4',
								'JS'   => '#E9D44D',
							);
							?>
							<?php foreach ( $snippets as $snippet ) : ?>
								<?php $type_label = $this->admin->get_type_label( $snippet['type'] ); ?>
								<li>
									<label>
										<input type="checkbox" class="snipcore-export-item" name="snipcore_export_ids[]" value="<?php echo esc_attr( $snippet['id'] ); ?>" checked="checked" />
										<span class="snipcore-export-name"><?php echo esc_html( '' !== $snippet['name'] ? $snippet['name'] : __( '(untitled)', 'snipcore' ) ); ?></span>
										<?php if ( isset( $type_lang_map[ $type_label ] ) ) : ?>
											<span class="snipcore-tab-lang-badge" style="background-color: <?php echo esc_attr( $type_lang_map[ $type_label ] ); ?>;">
												<?php echo esc_html( $type_label ); ?>
											</span>
										<?php else : ?>
											<span class="snipcore-location-badge"><?php echo esc_html( $type_label ); ?></span>
										<?php endif; ?>
									</label>
								</li>
							<?php endforeach; ?>
						</ul>

						<p class="submit">
							<button
								type="submit"
								id="snipcore-export-selected-button"
								class="button button-primary"
								data-label-default="<?php echo esc_attr__( 'Export Selected', 'snipcore' ); ?>"
								<?php /* translators: %d: total number of snippets available to export. */ ?>
								data-label-all="<?php echo esc_attr__( 'Export All (%d)', 'snipcore' ); ?>"
								<?php /* translators: %d: number of snippets currently selected for export. */ ?>
								data-label-some="<?php echo esc_attr__( 'Export %d Selected', 'snipcore' ); ?>"
							><?php esc_html_e( 'Export Selected', 'snipcore' ); ?></button>
						</p>
					</div>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the Import upload form: multi-file selection accepting
	 * SnipCore JSON or XML exports, submitting to
	 * handle_import_upload() which parses everything server-side and
	 * redirects to the preview step — nothing is imported directly
	 * from this form.
	 *
	 * @return void
	 */
	private function render_import_upload_form() {
		?>
		<div class="snipcore-io-section">
			<h2><?php esc_html_e( 'Import', 'snipcore' ); ?></h2>
			<p class="snipcore-io-lede"><?php esc_html_e( 'Upload SnipCore export files — you\'ll preview and choose what to add before anything is imported.', 'snipcore' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="snipcore-loading-on-submit" id="snipcore-import-form">
				<?php wp_nonce_field( 'snipcore_import_upload' ); ?>
				<input type="hidden" name="action" value="snipcore_import_upload" />

				<div class="snipcore-io-upload-area" id="snipcore-import-dropzone" tabindex="0" role="button" aria-label="<?php esc_attr_e( 'Choose files to import', 'snipcore' ); ?>">
					<span class="dashicons dashicons-upload snipcore-io-upload-icon" aria-hidden="true"></span>
					<p class="snipcore-io-upload-primary"><?php esc_html_e( 'Drag and drop files here, or click to browse', 'snipcore' ); ?></p>
					<p class="snipcore-io-upload-secondary"><?php esc_html_e( 'Supports JSON and XML files', 'snipcore' ); ?></p>
					<input type="file" id="snipcore-import-file-input" name="snipcore_import_file[]" accept="application/json,.json,text/xml,application/xml,.xml" multiple="multiple" required="required" class="snipcore-io-upload-input" />
					<ul id="snipcore-import-file-list" class="snipcore-import-file-list"></ul>
				</div>
				<p class="snipcore-io-hint"><?php esc_html_e( '.json or .xml, up to 2MB each. Imports are added as new, inactive snippets — nothing existing is changed.', 'snipcore' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Upload & Preview', 'snipcore' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the Import preview screen: what was parsed out of the
	 * uploaded file(s), any files/entries that had to be skipped, and
	 * (when there's anything importable) a checklist to confirm
	 * exactly which snippets to add.
	 *
	 * @param array $preview Transient payload from handle_import_upload(): array{items: array, errors: array}.
	 * @return void
	 */
	private function render_import_preview( array $preview ) {

		$items  = isset( $preview['items'] ) ? $preview['items'] : array();
		$errors = isset( $preview['errors'] ) ? $preview['errors'] : array();

		$cancel_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => self::SETTINGS_SLUG,
					'tab'    => 'import_export',
					'action' => 'snipcore_import_cancel',
				),
				admin_url( 'admin.php' )
			),
			'snipcore_import_cancel'
		);
		?>
		<h2><?php esc_html_e( 'Import Preview', 'snipcore' ); ?></h2>

		<?php if ( ! empty( $errors ) ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Some items were skipped:', 'snipcore' ); ?></strong></p>
				<ul class="snipcore-skipped-files">
					<?php foreach ( $errors as $error ) : ?>
						<li><?php echo esc_html( $error ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( empty( $items ) ) : ?>
			<p><?php esc_html_e( 'No importable snippets were found in the uploaded file(s).', 'snipcore' ); ?></p>
			<p><a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Back', 'snipcore' ); ?></a></p>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of snippets found. */
					esc_html(
						/* translators: %d: number of snippets found. */
						_n(
							'%d snippet found. Review the details below, then confirm which to import.',
							'%d snippets found. Review the details below, then confirm which to import.',
							count( $items ),
							'snipcore'
						)
					),
					count( $items )
				);
				?>
			</p>

			<?php
			$duplicate_count_summary = count(
				array_filter(
					$items,
					static function ( $item ) {
						return ! empty( $item['is_duplicate'] );
					}
				)
			);
			$new_count_summary = count( $items ) - $duplicate_count_summary;
			?>
			<p class="snipcore-import-summary">
				<?php
				printf(
					/* translators: 1: number of new snippets, 2: number of duplicate-named snippets. */
					esc_html__( '%1$s new, %2$s with a name already in use.', 'snipcore' ),
					'<strong>' . esc_html( $new_count_summary ) . '</strong>',
					'<strong>' . esc_html( $duplicate_count_summary ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snipcore-loading-on-submit" id="snipcore-import-confirm-form">
				<?php wp_nonce_field( 'snipcore_import_confirm' ); ?>
				<input type="hidden" name="action" value="snipcore_import_confirm" />

				<?php $duplicate_count = $duplicate_count_summary; ?>

				<?php if ( $duplicate_count > 0 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: number of snippets whose name matches an existing snippet. */
								esc_html(
									/* translators: %d: number of snippets whose name matches an existing snippet. */
									_n(
										'%d snippet has the same name as one you already have. It is unchecked by default and will be skipped unless you tick "Import as new copy" for it — existing snippets are never overwritten.',
										'%d snippets have the same name as ones you already have. They are unchecked by default and will be skipped unless you tick "Import as new copy" for them — existing snippets are never overwritten.',
										$duplicate_count,
										'snipcore'
									)
								),
								absint( $duplicate_count )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped snipcore-snippets-table snipcore-import-preview-table">
					<thead>
						<tr>
							<td class="check-column"><input type="checkbox" id="snipcore-import-select-all" <?php checked( 0 === $duplicate_count ); ?> /></td>
							<th><?php esc_html_e( 'Name', 'snipcore' ); ?></th>
							<th><?php esc_html_e( 'Type', 'snipcore' ); ?></th>
							<th><?php esc_html_e( 'Location', 'snipcore' ); ?></th>
							<th><?php esc_html_e( 'Code Preview', 'snipcore' ); ?></th>
							<th><?php esc_html_e( 'Source File', 'snipcore' ); ?></th>
							<th><?php esc_html_e( 'Status', 'snipcore' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $items as $index => $item ) : ?>
							<?php
							$snippet      = $item['snippet'];
							$is_duplicate = ! empty( $item['is_duplicate'] );
							?>
							<tr class="<?php echo $is_duplicate ? 'snipcore-import-row-duplicate' : ''; ?>">
								<th class="check-column">
									<input
										type="checkbox"
										class="snipcore-import-item"
										name="snipcore_import_selected[]"
										value="<?php echo esc_attr( $index ); ?>"
										<?php checked( ! $is_duplicate ); ?>
										data-duplicate="<?php echo $is_duplicate ? '1' : '0'; ?>"
									/>
								</th>
								<td><?php echo esc_html( '' !== $snippet['name'] ? $snippet['name'] : __( '(untitled)', 'snipcore' ) ); ?></td>
								<td><?php echo esc_html( $snippet['type'] ); ?></td>
								<td><span class="snipcore-location-badge"><?php echo esc_html( $snippet['location'] ); ?></span></td>
								<td><code><?php echo esc_html( $this->truncate_code_preview( $snippet['code'] ) ); ?></code></td>
								<td><?php echo esc_html( $item['source'] ); ?></td>
								<td>
									<?php if ( $is_duplicate ) : ?>
										<span class="snipcore-duplicate-badge"><?php esc_html_e( 'Duplicate name', 'snipcore' ); ?></span>
										<br />
										<label>
											<input type="checkbox" class="snipcore-import-rename" name="snipcore_import_rename[]" value="<?php echo esc_attr( $index ); ?>" />
											<?php esc_html_e( 'Import as new copy', 'snipcore' ); ?>
										</label>
									<?php else : ?>
										<span class="snipcore-new-badge"><?php esc_html_e( 'New', 'snipcore' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm Import', 'snipcore' ); ?></button>
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'snipcore' ); ?></a>
				</p>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Collapses whitespace and truncates a snippet's code for display
	 * in the single-line Import preview table cell.
	 *
	 * @param string $code Raw snippet code.
	 * @return string
	 */
	private function truncate_code_preview( $code ) {
		$code = trim( preg_replace( '/\s+/', ' ', (string) $code ) );

		if ( strlen( $code ) > 140 ) {
			$code = substr( $code, 0, 140 ) . '…';
		}

		return $code;
	}
}
