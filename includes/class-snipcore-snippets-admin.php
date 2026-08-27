<?php
/**
 * Snippets admin controller: All Snippets, Add/Edit Snippet, and
 * the row/bulk-action, AJAX-toggle, and single-snippet-export
 * handlers behind them.
 *
 * Extracted from SnipCore_Admin (Architecture Fix Phase 3) so this
 * class owns exactly the Snippets-screen request-handling
 * responsibility: nothing here changes behavior — every URL, form
 * action, nonce, capability check, redirect, notice, and storage
 * call is unchanged from the original SnipCore_Admin methods this
 * class was built from.
 *
 * A handful of small helpers used both by these screens and by the
 * Settings > Import/Export tab (get_list_url(), get_type_label(),
 * get_list_order_choices(), send_export_download()) remain on
 * SnipCore_Admin to avoid duplicating them; this class calls them
 * back through the $admin reference it is constructed with.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Snippets_Admin
 */
class SnipCore_Snippets_Admin {

	/**
	 * Slug used for the top-level menu and the "All Snippets" page.
	 *
	 * Mirrors SnipCore_Admin::MENU_SLUG (a fixed plugin routing
	 * slug, not configurable data) so this class does not need a
	 * cross-class constant reference for its own page/redirect URLs.
	 *
	 * @var string
	 */
	const MENU_SLUG = 'snipcore';

	/**
	 * The SnipCore_Admin instance this controller was constructed
	 * with, used only to call the small set of rendering helpers
	 * shared with the Settings > Import/Export tab (see class
	 * docblock above).
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
	 * Hook registration for the Snippets admin screens.
	 *
	 * Same hook names and callback priorities as the original
	 * SnipCore_Admin::init() registrations for these actions.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_snipcore_toggle_status', array( $this, 'ajax_toggle_status' ) );
		add_action( 'admin_post_snipcore_clone', array( $this, 'handle_clone' ) );
		add_action( 'admin_post_snipcore_trash', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_snipcore_restore', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_snipcore_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_snipcore_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_snipcore_save_snippet', array( $this, 'handle_save_snippet' ) );
		add_action( 'admin_post_snipcore_bulk_snippets', array( $this, 'handle_bulk_action' ) );
	}

	/**
	 * Sorts a list of snippets according to the "Snippets List Order"
	 * General setting.
	 *
	 * @param array $snippets Snippets to sort.
	 * @return array
	 */
	public function sort_snippets( array $snippets ) {
		$settings = SnipCore_Admin::get_settings();
		$order    = array_key_exists( $settings['list_order'], $this->admin->get_list_order_choices() ) ? $settings['list_order'] : 'name_asc';

		usort(
			$snippets,
			static function ( $a, $b ) use ( $order ) {
				switch ( $order ) {
					case 'name_desc':
						return strcasecmp( isset( $b['name'] ) ? $b['name'] : '', isset( $a['name'] ) ? $a['name'] : '' );
					case 'modified_desc':
						return strcmp( isset( $b['modified'] ) ? $b['modified'] : '', isset( $a['modified'] ) ? $a['modified'] : '' );
					case 'modified_asc':
						return strcmp( isset( $a['modified'] ) ? $a['modified'] : '', isset( $b['modified'] ) ? $b['modified'] : '' );
					case 'name_asc':
					default:
						return strcasecmp( isset( $a['name'] ) ? $a['name'] : '', isset( $b['name'] ) ? $b['name'] : '' );
				}
			}
		);

		return $snippets;
	}

	/**
	 * Filters a list of snippets down to those matching a free-text
	 * search term. Matches case-insensitively against name,
	 * description, tags, and code — the same fields visible (or
	 * summarized) in the list/edit screens, so a match always
	 * corresponds to something the admin could plausibly be looking
	 * for.
	 *
	 * @param array  $snippets Snippets to filter.
	 * @param string $search   Raw search term (already trimmed).
	 * @return array
	 */
	private function filter_snippets_by_search( array $snippets, $search ) {

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		if ( '' === $needle ) {
			return $snippets;
		}

		return array_values(
			array_filter(
				$snippets,
				static function ( $snippet ) use ( $needle ) {

					$haystacks   = array();
					$haystacks[] = isset( $snippet['name'] ) ? $snippet['name'] : '';
					$haystacks[] = isset( $snippet['description'] ) ? $snippet['description'] : '';
					$haystacks[] = isset( $snippet['code'] ) ? $snippet['code'] : '';

					if ( ! empty( $snippet['tags'] ) && is_array( $snippet['tags'] ) ) {
						$haystacks[] = implode( ' ', $snippet['tags'] );
					}

					$combined = function_exists( 'mb_strtolower' )
						? mb_strtolower( implode( ' ', $haystacks ) )
						: strtolower( implode( ' ', $haystacks ) );

					return false !== strpos( $combined, $needle );
				}
			)
		);
	}

	/**
	 * Handles the AJAX request to toggle a snippet's active status.
	 *
	 * @return void
	 */
	public function ajax_toggle_status() {

		check_ajax_referer( 'snipcore_toggle_status', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'snipcore' ) ), 403 );
		}

		$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( '' === $id || ! in_array( $status, SnipCore_Snippets::STATUSES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'snipcore' ) ) );
		}

		if ( ! SnipCore_Snippets::set_status( $id, $status ) ) {
			wp_send_json_error( array( 'message' => __( 'Snippet not found, or it is in the Trash and cannot be toggled until restored.', 'snipcore' ) ) );
		}

		wp_send_json_success( array( 'status' => $status ) );
	}

	/**
	 * Renders a compact, read-only diagnostics panel at the top of the
	 * Edit Snippet screen, summarizing exactly what will determine
	 * whether this snippet executes: activation status, Schedule
	 * Snippet state, and any applicable Site Display / Device Display
	 * restriction. Purely informational — every value here is derived
	 * from the same data and logic the execution engine itself uses
	 * (SnipCore_Snippets::get_schedule_state(), the stored
	 * display_mode/device_display fields), so it can never drift out
	 * of sync with actual behavior. Only shown when editing an
	 * existing snippet; a new, unsaved snippet has nothing yet to
	 * diagnose.
	 *
	 * @param array $snippet Snippet record.
	 * @return void
	 */
	private function render_diagnostics_panel( array $snippet ) {
		$status = isset( $snippet['status'] ) && 'active' === $snippet['status'] ? 'active' : 'inactive';

		$schedule_state        = SnipCore_Snippets::get_schedule_state( $snippet );
		$schedule_state_labels = array(
			'unscheduled' => __( 'Not scheduled — runs whenever its other rules allow', 'snipcore' ),
			'upcoming'    => __( 'Scheduled — has not started yet', 'snipcore' ),
			'active'      => __( 'Scheduled — currently within its window', 'snipcore' ),
			'expired'     => __( 'Scheduled — window has ended', 'snipcore' ),
		);

		$display_mode  = isset( $snippet['display_mode'] ) ? $snippet['display_mode'] : 'all';
		$display_count = isset( $snippet['display_post_ids'] ) && is_array( $snippet['display_post_ids'] )
			? count( $snippet['display_post_ids'] )
			: 0;

		if ( 'specific' === $display_mode && $display_count > 0 ) {
			/* translators: %d: number of targeted posts/pages. */
			$display_summary = sprintf( _n( 'Only on %d selected page/post', 'Only on %d selected pages/posts', $display_count, 'snipcore' ), $display_count );
		} elseif ( 'exclude' === $display_mode && $display_count > 0 ) {
			/* translators: %d: number of excluded posts/pages. */
			$display_summary = sprintf( _n( 'Everywhere except %d selected page/post', 'Everywhere except %d selected pages/posts', $display_count, 'snipcore' ), $display_count );
		} else {
			$display_summary = __( 'No restriction — whole site', 'snipcore' );
		}

		$device_display        = isset( $snippet['device_display'] ) ? $snippet['device_display'] : 'all';
		$device_summary_labels = array(
			'desktop' => __( 'Desktop only', 'snipcore' ),
			'mobile'  => __( 'Mobile only', 'snipcore' ),
		);
		$device_summary         = isset( $device_summary_labels[ $device_display ] )
			? $device_summary_labels[ $device_display ]
			: __( 'No restriction — desktop + mobile', 'snipcore' );
		?>
		<div class="snipcore-diagnostics">
			<h2><?php esc_html_e( 'Execution Diagnostics', 'snipcore' ); ?></h2>
			<ul>
				<li>
					<strong><?php esc_html_e( 'Status:', 'snipcore' ); ?></strong>
					<span class="snipcore-status-badge snipcore-status-<?php echo esc_attr( $status ); ?>">
						<?php echo 'active' === $status ? esc_html__( 'Active', 'snipcore' ) : esc_html__( 'Inactive', 'snipcore' ); ?>
					</span>
				</li>
				<li>
					<strong><?php esc_html_e( 'Schedule:', 'snipcore' ); ?></strong>
					<?php echo esc_html( isset( $schedule_state_labels[ $schedule_state ] ) ? $schedule_state_labels[ $schedule_state ] : $schedule_state_labels['unscheduled'] ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Site Display:', 'snipcore' ); ?></strong>
					<?php echo esc_html( $display_summary ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Device Display:', 'snipcore' ); ?></strong>
					<?php echo esc_html( $device_summary ); ?>
				</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Human-readable label for a snippet 'location' value, shared by
	 * the All Snippets list table and the Add/Edit Snippet screen's
	 * Location field.
	 *
	 * @param string $location Raw stored location.
	 * @return string
	 */
	private function get_location_label( $location ) {
		$labels = array(
			'everywhere' => __( 'Run Everywhere', 'snipcore' ),
			'admin'      => __( 'Administrative Area', 'snipcore' ),
			'frontend'   => __( 'Frontend', 'snipcore' ),
			'once'       => __( 'Only Run Once', 'snipcore' ),
		);

		return isset( $labels[ $location ] ) ? $labels[ $location ] : $location;
	}

	/**
	 * Formats a stored snippet date (e.g. the 'modified' timestamp) for
	 * display in the All Snippets list, as "2025/07/10 at 3:22 pm" —
	 * a fixed, locale-independent format used for every admin, rather
	 * than the site's configurable date_format/time_format options.
	 *
	 * @param string $mysql_datetime Stored MySQL datetime value.
	 * @return string
	 */
	private function format_snipcore_date( $mysql_datetime ) {
		return mysql2date( 'Y/m/d \a\t g:i a', $mysql_datetime );
	}

	/**
	 * Converts a stored 'YYYY-MM-DD HH:MM:SS' Schedule Snippet value
	 * into the 'YYYY-MM-DDTHH:MM' format an HTML datetime-local input
	 * expects for its value attribute. Empty/invalid input yields ''
	 * so the field simply renders blank.
	 *
	 * @param string $mysql_datetime Stored value, e.g. from $snippet['schedule_start'].
	 * @return string
	 */
	private function to_datetime_local_value( $mysql_datetime ) {
		$mysql_datetime = trim( (string) $mysql_datetime );

		if ( '' === $mysql_datetime ) {
			return '';
		}

		$date = \DateTime::createFromFormat( 'Y-m-d H:i:s', $mysql_datetime );

		if ( ! $date instanceof \DateTime ) {
			return '';
		}

		return $date->format( 'Y-m-d\TH:i' );
	}

	/**
	 * Returns the published posts/pages offered in the Site Display
	 * picker on the Add/Edit Snippet screen: id + a human-readable
	 * label (title, falling back to a generic placeholder for an
	 * untitled item, plus its post type so pages and posts aren't
	 * ambiguous in one combined list).
	 *
	 * Capped generously so the native <select> stays usable on a
	 * large site rather than rendering thousands of options; a
	 * snippet already targeting an item outside that cap (e.g. after
	 * the site grew) keeps working regardless, since rendering only
	 * reads the stored IDs and never depends on this list.
	 *
	 * @return array[] Each: array{id: int, label: string}.
	 */
	private function get_display_targetable_posts() {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
				'posts_per_page' => 300,
				'no_found_rows'  => true,
			)
		);

		$post_type_objects = array();

		return array_map(
			static function ( $post ) use ( &$post_type_objects ) {
				if ( ! isset( $post_type_objects[ $post->post_type ] ) ) {
					$post_type_objects[ $post->post_type ] = get_post_type_object( $post->post_type );
				}
				$type_label = $post_type_objects[ $post->post_type ]
					? $post_type_objects[ $post->post_type ]->labels->singular_name
					: $post->post_type;

				$title = '' !== $post->post_title ? $post->post_title : __( '(no title)', 'snipcore' );

				return array(
					'id'    => $post->ID,
					'label' => sprintf( '%1$s (%2$s)', $title, $type_label ),
					'type'  => $post->post_type,
				);
			},
			$posts
		);
	}

	/**
	 * Builds a nonce-protected row-action URL for a given action and
	 * snippet, submitted through admin-post.php.
	 *
	 * admin.php only dispatches admin_action_{$action} when no
	 * plugin "page" is also present in the URL — see wp-admin/admin.php,
	 * where that dispatch lives in the same branch that only runs
	 * when $plugin_page is NOT set (the branch that does run when a
	 * page is set renders the page and exit()s before ever reaching
	 * it). Since every row-action link here also carries the All
	 * Snippets page slug (so the link still makes sense as a URL on
	 * its own), an admin.php destination would silently reload the
	 * list page instead of ever running the action. admin-post.php
	 * has no such gate — it unconditionally fires
	 * admin_post_{$action} — so these row actions register on that
	 * hook instead, matching every other form in this plugin (bulk
	 * actions, save, import, export-selected).
	 *
	 * @param string $action      Action name (matches admin_post_{action}).
	 * @param string $id          Snippet ID.
	 * @param array  $list_context Optional 'tab' / 's' / 'paged' to round-trip
	 *                             through the handler so it can redirect back
	 *                             to the exact list view (tab, search, page)
	 *                             the action was triggered from, instead of
	 *                             always bouncing to the first page of "All".
	 * @return string
	 */
	private function get_action_url( $action, $id, array $list_context = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'action'  => $action,
					'id'      => $id,
					'page'    => self::MENU_SLUG,
				),
				$list_context
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'snipcore_' . $action . '_' . $id );
	}

	/**
	 * Reads the 'tab' / 's' / 'paged' list-context args a row-action
	 * handler was invoked with (round-tripped by get_action_url()) so
	 * the post-action redirect can return to the same list view —
	 * same tab, search term, and page — instead of always resetting
	 * to page 1 of "All Snippets".
	 *
	 * @return array
	 */
	private function get_list_context_from_request() {
		$context = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect-target state; the mutating action itself is nonce/capability-checked separately in each handler.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( array_key_exists( $tab, $this->get_tabs() ) && 'all' !== $tab ) {
			$context['tab'] = $tab;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( array_key_exists( $status, $this->get_status_filters() ) && 'all' !== $status ) {
			$context['status'] = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';
		if ( '' !== $search ) {
			$context['s'] = $search;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$paged = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 0;
		if ( $paged > 1 ) {
			$context['paged'] = $paged;
		}

		return $context;
	}

	/**
	 * Handles the "Clone" row action.
	 *
	 * @return void
	 */
	public function handle_clone() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'snipcore_snipcore_clone_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$redirect_args = $this->get_list_context_from_request();

		if ( '' !== $id ) {
			$new_id = SnipCore_Snippets::duplicate( $id );
			if ( false !== $new_id ) {
				$redirect_args['snipcore_cloned'] = 1;
			}
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $this->admin->get_list_url() ) );
		exit;
	}

	/**
	 * Handles the "Trash" row action.
	 *
	 * @return void
	 */
	public function handle_trash() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'snipcore_snipcore_trash_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$redirect_args = $this->get_list_context_from_request();

		if ( '' !== $id && SnipCore_Snippets::trash( $id ) ) {
			$redirect_args['snipcore_bulk_done']  = 'trash';
			$redirect_args['snipcore_bulk_count'] = 1;
		} else {
			$redirect_args['snipcore_trash_error'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $this->admin->get_list_url() ) );
		exit;
	}

	/**
	 * Handles the "Restore" row action (Trash tab only): moves a
	 * trashed snippet back to the main list.
	 *
	 * @return void
	 */
	public function handle_restore() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'snipcore_snipcore_restore_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		// Restore is only ever reached from the Trashed filter, so the
		// redirect always lands back there regardless of what (if
		// anything) the round-tripped list context says — search
		// term, type tab, and page are still honored so a restore from
		// page 2 of a filtered Trashed view returns to that same
		// tab/page/search.
		$redirect_args           = $this->get_list_context_from_request();
		$redirect_args['status'] = 'trashed';

		if ( '' !== $id && SnipCore_Snippets::restore( $id ) ) {
			$redirect_args['snipcore_bulk_done']  = 'restore';
			$redirect_args['snipcore_bulk_count'] = 1;
		} else {
			$redirect_args['snipcore_trash_error'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $this->admin->get_list_url() ) );
		exit;
	}

	/**
	 * Handles the "Delete Permanently" row action (Trash tab only):
	 * removes a trashed snippet from storage for good. Only ever
	 * reachable for snippets already in the Trash — this is the
	 * second, deliberate step after trash(), never a substitute for it.
	 *
	 * @return void
	 */
	public function handle_delete() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'snipcore_snipcore_delete_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$redirect_args           = $this->get_list_context_from_request();
		$redirect_args['status'] = 'trashed';

		if ( '' !== $id && SnipCore_Snippets::delete_permanently( $id ) ) {
			$redirect_args['snipcore_bulk_done']  = 'delete';
			$redirect_args['snipcore_bulk_count'] = 1;
		} else {
			$redirect_args['snipcore_trash_error'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, $this->admin->get_list_url() ) );
		exit;
	}

	/**
	 * Handles the bulk "Clone" / "Trash" / "Export" action (plus
	 * "Restore" / "Delete Permanently" on the Trash tab) from the All
	 * Snippets list. One shared handler for all of these so the nonce
	 * check, capability check, and ID sanitizing only have to be
	 * gotten right once; the per-snippet effect (other than Export,
	 * which streams a file directly) is delegated to the same
	 * SnipCore_Snippets::duplicate()/trash()/restore()/
	 * delete_permanently() methods the single-row Clone/Trash/
	 * Restore/Delete Permanently row actions already use, so bulk and
	 * single-row actions can never drift apart in behavior.
	 *
	 * Bulk Activate/Deactivate were removed for the 1.0.0 release —
	 * bulk Activate triggered a critical error, and the individual
	 * per-row activation toggle (ajax_toggle_status()) already covers
	 * that need reliably, so there is no bulk equivalent to fix or
	 * keep here.
	 *
	 * Trash is destructive (the row disappears from the active list),
	 * so the list screen also asks for a JS confirmation before this
	 * ever gets submitted — but that is a UI courtesy only; this
	 * handler is the actual enforcement point and does not rely on it.
	 *
	 * @return void
	 */
	public function handle_bulk_action() {

		check_admin_referer( 'snipcore_bulk_snippets', 'snipcore_bulk_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$bulk_action = isset( $_POST['snipcore_bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_bulk_action'] ) ) : '';
		$ids         = isset( $_POST['snipcore_bulk_ids'] )
			? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['snipcore_bulk_ids'] ) )
			: array();
		$ids         = array_values( array_filter( $ids, static function ( $id ) {
			return '' !== $id;
		} ) );

		$tab      = isset( $_POST['snipcore_bulk_tab'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_bulk_tab'] ) ) : 'all';
		$status   = isset( $_POST['snipcore_bulk_status'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_bulk_status'] ) ) : 'all';
		$search   = isset( $_POST['snipcore_bulk_search'] ) ? sanitize_text_field( wp_unslash( $_POST['snipcore_bulk_search'] ) ) : '';
		$paged    = isset( $_POST['snipcore_bulk_paged'] ) ? absint( wp_unslash( $_POST['snipcore_bulk_paged'] ) ) : 0;

		if ( ! array_key_exists( $status, $this->get_status_filters() ) ) {
			$status = 'all';
		}

		$redirect_args = array( 'page' => self::MENU_SLUG );
		if ( 'all' !== $tab ) {
			$redirect_args['tab'] = $tab;
		}
		if ( 'all' !== $status ) {
			$redirect_args['status'] = $status;
		}
		if ( '' !== $search ) {
			$redirect_args['s'] = $search;
		}
		if ( $paged > 1 ) {
			$redirect_args['paged'] = $paged;
		}

		// Export doesn't redirect — it streams a JSON file directly,
		// the same way the single-row Export action does — so it's
		// handled first and exits before any of the redirect-based
		// actions below.
		if ( 'export' === $bulk_action ) {
			if ( empty( $ids ) ) {
				$redirect_args['snipcore_bulk_none'] = 1;
				wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
				exit;
			}

			$id_lookup = array_flip( $ids );
			$snippets  = array_values(
				array_filter(
					SnipCore_Snippets::get_all( false ),
					static function ( $snippet ) use ( $id_lookup ) {
						return isset( $snippet['id'] ) && isset( $id_lookup[ $snippet['id'] ] );
					}
				)
			);

			if ( empty( $snippets ) ) {
				wp_die( esc_html__( 'No matching snippets were found to export.', 'snipcore' ) );
			}

			$snippets = array_map(
				static function ( $snippet ) {
					unset( $snippet['trashed'] );
					return $snippet;
				},
				$snippets
			);

			$filename = 'snipcore-snippets-' . gmdate( 'Y-m-d' ) . '.json';

			$this->admin->send_export_download( $filename, 'application/json', SnipCore_Import_Export::to_json( $snippets ) );
			return;
		}

		$allowed_actions = array( 'clone', 'trash', 'restore', 'delete' );
		$count           = 0;

		if ( in_array( $bulk_action, $allowed_actions, true ) && ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$success = false;
				if ( 'clone' === $bulk_action ) {
					$success = false !== SnipCore_Snippets::duplicate( $id );
				} elseif ( 'trash' === $bulk_action ) {
					$success = (bool) SnipCore_Snippets::trash( $id );
				} elseif ( 'restore' === $bulk_action ) {
					$success = (bool) SnipCore_Snippets::restore( $id );
				} elseif ( 'delete' === $bulk_action ) {
					$success = (bool) SnipCore_Snippets::delete_permanently( $id );
				}
				if ( $success ) {
					++$count;
				}
			}

			$redirect_args['snipcore_bulk_done']  = $bulk_action;
			$redirect_args['snipcore_bulk_count'] = $count;
		} elseif ( in_array( $bulk_action, $allowed_actions, true ) ) {
			// A recognized action with no snippets checked: this is the
			// "select a bulk action, click Apply, nothing was checked"
			// case — surface it rather than silently reloading the list
			// with no feedback at all.
			$redirect_args['snipcore_bulk_none'] = 1;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles the "Export" row action: outputs a single snippet as a
	 * JSON file download, wrapped as a one-item array so it always
	 * re-imports through exactly the same parser/preview/insert path
	 * as a multi-snippet "Export Selected" file (see
	 * SnipCore_Import_Export::parse()/to_json()) — one export code
	 * path for every field that needs to survive the round trip,
	 * rather than a second, easily-drifting copy of it here.
	 *
	 * @return void
	 */
	public function handle_export() {
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';

		check_admin_referer( 'snipcore_snipcore_export_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$snippet = SnipCore_Snippets::get( $id );

		if ( null === $snippet ) {
			wp_die( esc_html__( 'Snippet not found.', 'snipcore' ) );
		}

		unset( $snippet['trashed'] );

		$filename = sanitize_title( $snippet['name'] ? $snippet['name'] : 'snippet' ) . '.json';

		$this->admin->send_export_download( $filename, 'application/json', SnipCore_Import_Export::to_json( array( $snippet ) ) );
	}

	/**
	 * Returns the tab definitions for the All Snippets page.
	 *
	 * Keys are used as the `tab` query arg; values are the visible label.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'all'       => __( 'All Snippets', 'snipcore' ),
			'functions' => __( 'Functions [PHP]', 'snipcore' ),
			'content'   => __( 'Content [HTML]', 'snipcore' ),
			'style'     => __( 'Style [CSS]', 'snipcore' ),
			'scripts'   => __( 'Scripts [JS]', 'snipcore' ),
		);
	}

	/**
	 * Returns the status-filter definitions shown as compact buttons
	 * above the search box on the All Snippets page. Keys are used as
	 * the `status` query arg; values are the visible label.
	 *
	 * Unlike the type tabs (which switch the underlying data source
	 * between "live" and "trashed"), these filter the *same* list —
	 * see render_all_snippets_page() — so a single "trash" concept
	 * doesn't have to live in two different query args.
	 *
	 * 'all' is always shown; the others only render when at least one
	 * matching snippet exists (checked in render_all_snippets_page()).
	 *
	 * @return array
	 */
	private function get_status_filters() {
		return array(
			'all'        => __( 'All', 'snipcore' ),
			'activate'   => __( 'Active', 'snipcore' ),
			'deactivate' => __( 'Inactive', 'snipcore' ),
			'trashed'    => __( 'Trashed', 'snipcore' ),
		);
	}

	/**
	 * Renders the "All Snippets" page shell: type tab navigation, the
	 * compact All/Activate/Deactivate/Trashed status filter row, and a
	 * native WordPress-style list table populated from stored
	 * snippets, or the Add/Edit screen when ?action=edit is present.
	 * Uses native WordPress admin markup and classes for the tabs and
	 * table (nav-tab-wrapper, wp-list-table); the status filters are
	 * rendered as a native WordPress subsubsub list with counts (see
	 * get_status_filters()) that replaced the old standalone Trash tab,
	 * sitting on the same row as the search box.
	 *
	 * @return void
	 */
	public function render_all_snippets_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only (GET) action-tab navigation; nothing here mutates state.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action ) {
			$this->render_edit_page();
			return;
		}

		$tabs        = $this->get_tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only (GET) tab navigation, same as the action/search/status params it sits alongside; nothing here mutates state.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';

		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'all';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a search query is read-only (GET) navigation, same as the tab/page params it sits alongside; nothing here mutates state.
		$search = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '';

		$status_filters  = $this->get_status_filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only (GET) status-filter navigation, same as the tab/search params it sits alongside; nothing here mutates state.
		$current_status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';

		if ( ! array_key_exists( $current_status, $status_filters ) ) {
			$current_status = 'all';
		}

		$is_trash_tab = ( 'trashed' === $current_status );

		// Every status filter (including Trashed) starts from the same
		// pool — all snippets of the current type tab, live and
		// trashed alike — so switching the status filter never has to
		// swap data sources; it only narrows the same list. This is
		// also what lets each filter's visibility be computed
		// consistently against "the current type tab + search", below.
		$type_filtered = $this->sort_snippets( SnipCore_Snippets::get_all( true ) );

		if ( 'all' !== $current_tab ) {
			$type_filtered = array_values(
				array_filter(
					$type_filtered,
					static function ( $snippet ) use ( $current_tab ) {
						return isset( $snippet['type'] ) && $current_tab === $snippet['type'];
					}
				)
			);
		}

		if ( '' !== $search ) {
			$type_filtered = $this->filter_snippets_by_search( $type_filtered, $search );
		}

		// Determine which status filters actually have a match (within
		// the current type tab + search) so empty ones can be hidden
		// from the compact filter row — 'all' is always shown.
		$status_counts = array(
			'activate'   => 0,
			'deactivate' => 0,
			'trashed'    => 0,
		);
		foreach ( $type_filtered as $snippet ) {
			if ( ! empty( $snippet['trashed'] ) ) {
				++$status_counts['trashed'];
				continue;
			}
			if ( isset( $snippet['status'] ) && 'active' === $snippet['status'] ) {
				++$status_counts['activate'];
			} else {
				++$status_counts['deactivate'];
			}
		}

		// Now apply the selected status filter on top of the
		// type+search filtered pool to get the list actually shown.
		$snippets = array_values(
			array_filter(
				$type_filtered,
				static function ( $snippet ) use ( $current_status ) {
					$trashed = ! empty( $snippet['trashed'] );

					switch ( $current_status ) {
						case 'trashed':
							return $trashed;
						case 'activate':
							return ! $trashed && isset( $snippet['status'] ) && 'active' === $snippet['status'];
						case 'deactivate':
							return ! $trashed && ( ! isset( $snippet['status'] ) || 'active' !== $snippet['status'] );
						default:
							return ! $trashed;
					}
				}
			)
		);

		$settings_for_list    = SnipCore_Admin::get_settings();
		$enable_tags          = ! empty( $settings_for_list['enable_tags'] );
		$enable_descriptions  = ! empty( $settings_for_list['enable_descriptions'] );

		// Pagination: how many snippets to show per page, and which
		// page is currently requested. The per-page choice is a
		// per-admin preference (native WP "Screen Options" pattern),
		// persisted to user meta so it sticks across visits; the page
		// number is transient GET state like the tab/search params it
		// sits alongside.
		$per_page_choices = array( 10, 20, 50, 100 );
		$per_page         = $this->get_list_per_page( $per_page_choices );

		$total_items  = count( $snippets );
		$total_pages  = $per_page > 0 ? (int) ceil( $total_items / $per_page ) : 1;
		$total_pages  = max( 1, $total_pages );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination state, same as the tab/search params it sits alongside.
		$current_page = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
		$current_page = max( 1, min( $current_page, $total_pages ) );

		$snippets_page = array_slice( $snippets, ( $current_page - 1 ) * $per_page, $per_page );

		// Base query args every pagination/per-page link needs to
		// preserve, so switching pages never loses the active tab
		// or search term.
		$base_args = array( 'page' => self::MENU_SLUG );
		if ( 'all' !== $current_tab ) {
			$base_args['tab'] = $current_tab;
		}
		if ( 'all' !== $current_status ) {
			$base_args['status'] = $current_status;
		}
		if ( '' !== $search ) {
			$base_args['s'] = $search;
		}

		// Same idea as $base_args, but also carries the current page —
		// this is what row actions (Trash/Restore/Delete/Clone/Export)
		// round-trip through get_action_url() so their post-action
		// redirect can return to this exact list view instead of
		// always resetting to page 1 of "All Snippets".
		$row_list_context = $base_args;
		unset( $row_list_context['page'] );
		if ( $current_page > 1 ) {
			$row_list_context['paged'] = $current_page;
		}
		?>
		<div class="wrap snipcore-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Snippets', 'snipcore' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Add New', 'snipcore' ); ?>
			</a>
			<hr class="wp-header-end">

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<?php
					$tab_url_args = array( 'page' => self::MENU_SLUG, 'tab' => $slug );
					if ( 'all' !== $current_status ) {
						$tab_url_args['status'] = $current_status;
					}
					if ( '' !== $search ) {
						$tab_url_args['s'] = $search;
					}
					?>
					<?php
					// Split the "Label [LANG]" tab text into its base label
					// and language tag so the language portion can be
					// rendered as a small colored side badge; tabs with no
					// bracketed language (e.g. "All Snippets") are left as
					// plain text.
					$tab_lang_map = array(
						'PHP'  => '#7277AE',
						'HTML' => '#DC4821',
						'CSS'  => '#196FB4',
						'JS'   => '#E9D44D',
					);
					$tab_base_label = $label;
					$tab_lang_code  = '';
					if ( preg_match( '/^(.*)\s\[([A-Za-z]+)\]$/', $label, $tab_label_matches ) ) {
						$tab_base_label = $tab_label_matches[1];
						$tab_lang_code  = strtoupper( $tab_label_matches[2] );
					}
					?>
					<a href="<?php echo esc_url( add_query_arg( $tab_url_args, admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo ( $current_tab === $slug ) ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_base_label ); ?>
						<?php if ( '' !== $tab_lang_code && isset( $tab_lang_map[ $tab_lang_code ] ) ) : ?>
							<span class="snipcore-tab-lang-badge" style="background-color: <?php echo esc_attr( $tab_lang_map[ $tab_lang_code ] ); ?>;">
								<?php echo esc_html( $tab_lang_code ); ?>
							</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<div class="snipcore-controls-row">
				<ul class="subsubsub snipcore-status-filters">
					<?php
					// All four filters are always rendered (so counts can
					// be updated live from JS without a page reload); a
					// filter with zero matches is simply hidden via the
					// snipcore-hidden class rather than omitted, and the
					// pipe separators are reflowed by JS to only appear
					// between the filters currently visible — see
					// snipcoreRefreshStatusSeparators() in assets/admin.js.
					foreach ( $status_filters as $status_slug => $status_label ) :
						$status_url_args = array( 'page' => self::MENU_SLUG );
						if ( 'all' !== $current_tab ) {
							$status_url_args['tab'] = $current_tab;
						}
						if ( 'all' !== $status_slug ) {
							$status_url_args['status'] = $status_slug;
						}
						if ( '' !== $search ) {
							$status_url_args['s'] = $search;
						}
						$status_item_count = ( 'all' === $status_slug ) ? count( $type_filtered ) : $status_counts[ $status_slug ];
						$is_empty          = ( 'all' !== $status_slug && 0 === $status_item_count );
						?>
						<li class="<?php echo esc_attr( $status_slug . ( $is_empty ? ' snipcore-hidden' : '' ) ); ?>" data-status="<?php echo esc_attr( $status_slug ); ?>" data-count="<?php echo esc_attr( $status_item_count ); ?>">
							<a href="<?php echo esc_url( add_query_arg( $status_url_args, admin_url( 'admin.php' ) ) ); ?>"
								class="<?php echo ( $current_status === $status_slug ) ? 'current' : ''; ?>">
								<?php echo esc_html( $status_label ); ?>
								<span class="count">(<?php echo esc_html( number_format_i18n( $status_item_count ) ); ?>)</span>
							</a><span class="snipcore-status-filter-sep"> | </span>
						</li>
					<?php endforeach; ?>
				</ul>

				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="search-form snipcore-search-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<?php if ( 'all' !== $current_tab ) : ?>
						<input type="hidden" name="tab" value="<?php echo esc_attr( $current_tab ); ?>" />
					<?php endif; ?>
					<?php if ( 'all' !== $current_status ) : ?>
						<input type="hidden" name="status" value="<?php echo esc_attr( $current_status ); ?>" />
					<?php endif; ?>
					<p class="search-box">
						<label class="screen-reader-text" for="snipcore-search-input"><?php esc_html_e( 'Search Snippets', 'snipcore' ); ?></label>
						<input type="search" id="snipcore-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, description, tags, or code…', 'snipcore' ); ?>" />
						<button type="submit" class="button"><?php esc_html_e( 'Search Snippets', 'snipcore' ); ?></button>
						<?php if ( '' !== $search ) : ?>
							<?php
							$clear_args = array( 'page' => self::MENU_SLUG );
							if ( 'all' !== $current_tab ) {
								$clear_args['tab'] = $current_tab;
							}
							if ( 'all' !== $current_status ) {
								$clear_args['status'] = $current_status;
							}
							?>
							<a href="<?php echo esc_url( add_query_arg( $clear_args, admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Clear Search', 'snipcore' ); ?></a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the clone-action redirect result; the action itself was already nonce/capability-checked in handle_clone().
			$cloned = isset( $_GET['snipcore_cloned'] ) ? absint( wp_unslash( $_GET['snipcore_cloned'] ) ) : 0;
			?>
			<?php if ( $cloned ) : ?>
				<div class="notice notice-success is-dismissible snipcore-bulk-notice">
					<p><?php esc_html_e( 'Snippet cloned. The copy has been added as a new, inactive snippet.', 'snipcore' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the trash-action redirect result; the action itself was already nonce/capability-checked in handle_trash().
			$trash_error = isset( $_GET['snipcore_trash_error'] ) ? absint( wp_unslash( $_GET['snipcore_trash_error'] ) ) : 0;
			?>
			<?php if ( $trash_error ) : ?>
				<div class="notice notice-error is-dismissible snipcore-bulk-notice">
					<p><?php esc_html_e( 'That action could not be completed. The snippet may have already been removed or restored.', 'snipcore' ); ?></p>
				</div>
			<?php endif; ?>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the bulk-action redirect result; the action itself was already nonce/capability-checked in handle_bulk_action().
			$bulk_done = isset( $_GET['snipcore_bulk_done'] ) ? sanitize_key( wp_unslash( $_GET['snipcore_bulk_done'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$bulk_count = isset( $_GET['snipcore_bulk_count'] ) ? absint( wp_unslash( $_GET['snipcore_bulk_count'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$bulk_none = isset( $_GET['snipcore_bulk_none'] ) ? absint( wp_unslash( $_GET['snipcore_bulk_none'] ) ) : 0;
			$bulk_notices = array(
				/* translators: %d: Number of snippets that were cloned. */
				'clone'   => _n( '%d snippet cloned.', '%d snippets cloned.', $bulk_count, 'snipcore' ),
				/* translators: %d: Number of snippets that were moved to Trash. */
				'trash'   => _n( '%d snippet moved to Trash.', '%d snippets moved to Trash.', $bulk_count, 'snipcore' ),
				/* translators: %d: Number of snippets that were restored. */
				'restore' => _n( '%d snippet restored.', '%d snippets restored.', $bulk_count, 'snipcore' ),
				/* translators: %d: Number of snippets that were permanently deleted. */
				'delete'  => _n( '%d snippet permanently deleted.', '%d snippets permanently deleted.', $bulk_count, 'snipcore' ),
			);
			?>
			<?php if ( $bulk_none ) : ?>
				<div class="notice notice-warning is-dismissible snipcore-bulk-notice">
					<p><?php esc_html_e( 'No snippets were selected. Please check one or more snippets and choose a bulk action.', 'snipcore' ); ?></p>
				</div>
			<?php elseif ( isset( $bulk_notices[ $bulk_done ] ) ) : ?>
				<div class="notice notice-success is-dismissible snipcore-bulk-notice">
					<p><?php echo esc_html( sprintf( $bulk_notices[ $bulk_done ], $bulk_count ) ); ?></p>
				</div>
			<?php endif; ?>

			<form id="snipcore-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'snipcore_bulk_snippets', 'snipcore_bulk_nonce' ); ?>
				<input type="hidden" name="action" value="snipcore_bulk_snippets" />
				<input type="hidden" name="snipcore_bulk_action" value="-1" />
				<input type="hidden" name="snipcore_bulk_tab" value="<?php echo esc_attr( $current_tab ); ?>" />
				<input type="hidden" name="snipcore_bulk_status" value="<?php echo esc_attr( $current_status ); ?>" />
				<input type="hidden" name="snipcore_bulk_search" value="<?php echo esc_attr( $search ); ?>" />
				<input type="hidden" name="snipcore_bulk_paged" value="<?php echo esc_attr( $current_page ); ?>" />

				<div class="tablenav top snipcore-tablenav">
					<div class="snipcore-bulk-actions snipcore-bulk-actions-top alignleft actions bulkactions">
						<label class="screen-reader-text" for="snipcore-bulk-action-top"><?php esc_html_e( 'Select bulk action', 'snipcore' ); ?></label>
						<select id="snipcore-bulk-action-top" class="snipcore-bulk-action-select">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'snipcore' ); ?></option>
							<?php if ( $is_trash_tab ) : ?>
								<option value="restore"><?php esc_html_e( 'Restore', 'snipcore' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete Permanently', 'snipcore' ); ?></option>
							<?php else : ?>
								<option value="clone"><?php esc_html_e( 'Clone', 'snipcore' ); ?></option>
								<option value="trash"><?php esc_html_e( 'Move to Trash', 'snipcore' ); ?></option>
								<option value="export"><?php esc_html_e( 'Export', 'snipcore' ); ?></option>
							<?php endif; ?>
						</select>
						<button type="submit" class="button action snipcore-bulk-apply"><?php esc_html_e( 'Apply', 'snipcore' ); ?></button>
					</div>
					<?php $this->render_list_pagination( $base_args, $current_page, $total_pages, $total_items, $per_page, $per_page_choices, 'top', false ); ?>
					<br class="clear" />
				</div>

			<table class="wp-list-table widefat fixed striped table-view-list snipcore-snippets-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="snipcore-bulk-select-all-1"><?php esc_html_e( 'Select all', 'snipcore' ); ?></label>
							<input id="snipcore-bulk-select-all-1" type="checkbox" class="snipcore-bulk-select-all" />
						</td>
						<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Name', 'snipcore' ); ?></th>
						<th scope="col" class="manage-column"><?php esc_html_e( 'Type', 'snipcore' ); ?></th>
						<?php if ( $enable_descriptions ) : ?>
							<th scope="col" class="manage-column column-snipcore-description"><?php esc_html_e( 'Description', 'snipcore' ); ?></th>
						<?php endif; ?>
						<?php if ( $enable_tags ) : ?>
							<th scope="col" class="manage-column column-snipcore-tags"><?php esc_html_e( 'Tags', 'snipcore' ); ?></th>
						<?php endif; ?>
						<th scope="col" class="manage-column"><?php esc_html_e( 'Date', 'snipcore' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $snippets_page ) ) : ?>
						<tr class="no-items">
							<td class="colspanchange snipcore-empty-state" colspan="<?php echo esc_attr( 4 + ( $enable_tags ? 1 : 0 ) + ( $enable_descriptions ? 1 : 0 ) ); ?>">
								<?php if ( '' !== $search ) : ?>
									<span class="dashicons dashicons-search snipcore-empty-state-icon" aria-hidden="true"></span>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'No snippets matched your search.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub">
										<?php
										printf(
											/* translators: %s: the search term. */
											esc_html__( 'Nothing matched "%s". Try a different term or clear the search.', 'snipcore' ),
											esc_html( $search )
										);
										?>
									</p>
									<?php
									$clear_search_args = $base_args;
									unset( $clear_search_args['s'] );
									?>
									<p><a href="<?php echo esc_url( add_query_arg( $clear_search_args, admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'Clear search', 'snipcore' ); ?></a></p>
								<?php elseif ( $is_trash_tab ) : ?>
									<span class="dashicons dashicons-trash snipcore-empty-state-icon" aria-hidden="true"></span>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'Trash is empty.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub"><?php esc_html_e( 'Snippets you trash will show up here until you restore or permanently delete them.', 'snipcore' ); ?></p>
								<?php elseif ( 'activate' === $current_status ) : ?>
									<span class="dashicons dashicons-yes-alt snipcore-empty-state-icon" aria-hidden="true"></span>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'No active snippets.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub"><?php esc_html_e( 'Nothing here is currently running. Activate a snippet from the full list to see it in this view.', 'snipcore' ); ?></p>
									<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $current_tab ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'View All Snippets', 'snipcore' ); ?></a></p>
								<?php elseif ( 'deactivate' === $current_status ) : ?>
									<span class="dashicons dashicons-marker snipcore-empty-state-icon" aria-hidden="true"></span>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'No inactive snippets.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub"><?php esc_html_e( 'Everything here is currently active.', 'snipcore' ); ?></p>
									<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'tab' => $current_tab ), admin_url( 'admin.php' ) ) ); ?>" class="button"><?php esc_html_e( 'View All Snippets', 'snipcore' ); ?></a></p>
								<?php elseif ( 'all' !== $current_tab ) : ?>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'No snippets in this category yet.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub"><?php esc_html_e( 'Snippets of this type will appear here once you add one.', 'snipcore' ); ?></p>
									<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add New Snippet', 'snipcore' ); ?></a></p>
								<?php else : ?>
									<span class="dashicons dashicons-media-code snipcore-empty-state-icon" aria-hidden="true"></span>
									<p class="snipcore-empty-state-message"><?php esc_html_e( 'No snippets yet.', 'snipcore' ); ?></p>
									<p class="snipcore-empty-state-sub"><?php esc_html_e( 'Get started by creating your first PHP, HTML, CSS, or JS snippet.', 'snipcore' ); ?></p>
									<p><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit' ), admin_url( 'admin.php' ) ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add New Snippet', 'snipcore' ); ?></a></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $snippets_page as $snippet ) : ?>
							<?php
							$id       = isset( $snippet['id'] ) ? $snippet['id'] : '';
							$name     = isset( $snippet['name'] ) ? $snippet['name'] : '';
							$type     = isset( $snippet['type'] ) ? $snippet['type'] : '';
							$status   = isset( $snippet['status'] ) && 'active' === $snippet['status'] ? 'active' : 'inactive';
							$modified = isset( $snippet['modified'] ) ? $snippet['modified'] : '';
							$type_label     = $this->admin->get_type_label( $type );
							$row_class = 'active' === $status ? 'snipcore-row-active' : 'snipcore-row-inactive';
							?>
							<tr class="<?php echo esc_attr( $row_class ); ?>">
								<th scope="row" class="check-column">
									<label class="screen-reader-text" for="snipcore-bulk-<?php echo esc_attr( $id ); ?>">
										<?php
										/* translators: %s: snippet name. */
										printf( esc_html__( 'Select %s', 'snipcore' ), esc_html( $name ) );
										?>
									</label>
									<input id="snipcore-bulk-<?php echo esc_attr( $id ); ?>" type="checkbox" class="snipcore-bulk-item" name="snipcore_bulk_ids[]" value="<?php echo esc_attr( $id ); ?>" />
								</th>
								<td class="column-primary has-row-actions" data-colname="<?php esc_attr_e( 'Name', 'snipcore' ); ?>">
									<div class="snipcore-name-cell">
										<div class="snipcore-name-row">
											<?php if ( ! $is_trash_tab ) : ?>
												<span class="snipcore-toggle-wrap">
													<label class="snipcore-toggle-switch" for="snipcore-toggle-<?php echo esc_attr( $id ); ?>">
														<input
															type="checkbox"
															id="snipcore-toggle-<?php echo esc_attr( $id ); ?>"
															class="snipcore-toggle"
															data-id="<?php echo esc_attr( $id ); ?>"
															<?php checked( 'active', $status ); ?>
														/>
														<span class="snipcore-toggle-slider" aria-hidden="true"></span>
														<span class="screen-reader-text">
															<?php esc_html_e( 'Toggle activation', 'snipcore' ); ?>
														</span>
													</label>
												</span>
											<?php endif; ?>
											<strong class="row-title"><?php echo esc_html( $name ); ?></strong>
										</div>
										<?php
										$schedule_state         = SnipCore_Snippets::get_schedule_state( $snippet );
										$schedule_state_labels  = array(
											'upcoming' => __( 'Scheduled: upcoming', 'snipcore' ),
											'active'   => __( 'Scheduled: in window', 'snipcore' ),
											'expired'  => __( 'Scheduled: expired', 'snipcore' ),
										);
										?>
										<?php if ( isset( $schedule_state_labels[ $schedule_state ] ) ) : ?>
											<span class="snipcore-schedule-badge snipcore-schedule-<?php echo esc_attr( $schedule_state ); ?>" title="<?php esc_attr_e( 'This snippet only executes within its configured Schedule window.', 'snipcore' ); ?>">
												<?php echo esc_html( $schedule_state_labels[ $schedule_state ] ); ?>
											</span>
										<?php endif; ?>
									</div>
									<div class="row-actions">
										<?php if ( $is_trash_tab ) : ?>
											<span class="untrash">
												<a href="<?php echo esc_url( $this->get_action_url( 'snipcore_restore', $id, $row_list_context ) ); ?>">
													<?php esc_html_e( 'Restore', 'snipcore' ); ?>
												</a> |
											</span>
											<span class="delete">
												<a href="<?php echo esc_url( $this->get_action_url( 'snipcore_delete', $id, $row_list_context ) ); ?>"
													onclick="return confirm('<?php echo esc_js( __( 'Permanently delete this snippet? This cannot be undone.', 'snipcore' ) ); ?>');"
													class="submitdelete">
													<?php esc_html_e( 'Delete Permanently', 'snipcore' ); ?>
												</a>
											</span>
										<?php else : ?>
											<span class="edit">
												<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::MENU_SLUG, 'action' => 'edit', 'id' => $id ), admin_url( 'admin.php' ) ) ); ?>">
													<?php esc_html_e( 'Edit', 'snipcore' ); ?>
												</a> |
											</span>
											<span class="clone">
												<a href="<?php echo esc_url( $this->get_action_url( 'snipcore_clone', $id, $row_list_context ) ); ?>">
													<?php esc_html_e( 'Clone', 'snipcore' ); ?>
												</a> |
											</span>
											<span class="export">
												<a href="<?php echo esc_url( $this->get_action_url( 'snipcore_export', $id, $row_list_context ) ); ?>">
													<?php esc_html_e( 'Export', 'snipcore' ); ?>
												</a> |
											</span>
											<span class="trash">
												<a href="<?php echo esc_url( $this->get_action_url( 'snipcore_trash', $id, $row_list_context ) ); ?>"
													onclick="return confirm('<?php echo esc_js( __( 'Move this snippet to Trash?', 'snipcore' ) ); ?>');"
													class="submitdelete">
													<?php esc_html_e( 'Trash', 'snipcore' ); ?>
												</a>
											</span>
										<?php endif; ?>
									</div>
									<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'snipcore' ); ?></span></button>
								</td>
								<td data-colname="<?php esc_attr_e( 'Type', 'snipcore' ); ?>">
									<?php
									$type_lang_map = array(
										'PHP'  => '#7277AE',
										'HTML' => '#DC4821',
										'CSS'  => '#196FB4',
										'JS'   => '#E9D44D',
									);
									?>
									<?php if ( isset( $type_lang_map[ $type_label ] ) ) : ?>
										<span class="snipcore-tab-lang-badge" style="background-color: <?php echo esc_attr( $type_lang_map[ $type_label ] ); ?>;">
											<?php echo esc_html( $type_label ); ?>
										</span>
									<?php else : ?>
										<?php echo esc_html( $type_label ); ?>
									<?php endif; ?>
								</td>
								<?php if ( $enable_descriptions ) : ?>
									<td class="column-snipcore-description" data-colname="<?php esc_attr_e( 'Description', 'snipcore' ); ?>">
										<?php
										$description = isset( $snippet['description'] ) ? trim( (string) $snippet['description'] ) : '';
										if ( '' !== $description ) {
											// A generous server-side cap keeps pathologically long
											// descriptions out of the markup; the CSS ellipsis
											// (.snipcore-description-text) handles the actual clean,
											// single-line truncation for anything that still
											// overflows the column at render time. The full text is
											// always available via the title tooltip.
											$capped = ( function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description ) ) > 300
												? ( function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 300 ) : substr( $description, 0, 300 ) ) . '…'
												: $description;
											printf( '<span class="snipcore-description-text" title="%1$s">%2$s</span>', esc_attr( $description ), esc_html( $capped ) );
										} else {
											echo '<span class="snipcore-empty-cell">&#8212;</span>';
										}
										?>
									</td>
								<?php endif; ?>
								<?php if ( $enable_tags ) : ?>
									<td class="column-snipcore-tags" data-colname="<?php esc_attr_e( 'Tags', 'snipcore' ); ?>">
										<?php if ( ! empty( $snippet['tags'] ) && is_array( $snippet['tags'] ) ) : ?>
											<?php
											$clean_tags = array_map( 'sanitize_text_field', $snippet['tags'] );
											?>
											<?php foreach ( $clean_tags as $tag ) : ?>
												<span class="snipcore-tag-chip"><?php echo esc_html( $tag ); ?></span>
											<?php endforeach; ?>
										<?php else : ?>
											<span class="snipcore-empty-cell">&#8212;</span>
										<?php endif; ?>
									</td>
								<?php endif; ?>
								<td data-colname="<?php esc_attr_e( 'Date', 'snipcore' ); ?>"><?php echo $modified ? esc_html( $this->format_snipcore_date( $modified ) ) : ''; ?></td>

							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
				<tfoot>
					<tr>
						<td class="manage-column column-cb check-column">
							<label class="screen-reader-text" for="snipcore-bulk-select-all-2"><?php esc_html_e( 'Select all', 'snipcore' ); ?></label>
							<input id="snipcore-bulk-select-all-2" type="checkbox" class="snipcore-bulk-select-all" />
						</td>
						<th scope="col" class="manage-column column-primary"><?php esc_html_e( 'Name', 'snipcore' ); ?></th>
						<th scope="col" class="manage-column"><?php esc_html_e( 'Type', 'snipcore' ); ?></th>
						<?php if ( $enable_descriptions ) : ?>
							<th scope="col" class="manage-column column-snipcore-description"><?php esc_html_e( 'Description', 'snipcore' ); ?></th>
						<?php endif; ?>
						<?php if ( $enable_tags ) : ?>
							<th scope="col" class="manage-column column-snipcore-tags"><?php esc_html_e( 'Tags', 'snipcore' ); ?></th>
						<?php endif; ?>
						<th scope="col" class="manage-column"><?php esc_html_e( 'Date', 'snipcore' ); ?></th>
					</tr>
				</tfoot>
			</table>

				<div class="tablenav bottom snipcore-tablenav">
					<div class="snipcore-bulk-actions snipcore-bulk-actions-bottom alignleft actions bulkactions">
						<label class="screen-reader-text" for="snipcore-bulk-action-bottom"><?php esc_html_e( 'Select bulk action', 'snipcore' ); ?></label>
						<select id="snipcore-bulk-action-bottom" class="snipcore-bulk-action-select">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'snipcore' ); ?></option>
							<?php if ( $is_trash_tab ) : ?>
								<option value="restore"><?php esc_html_e( 'Restore', 'snipcore' ); ?></option>
								<option value="delete"><?php esc_html_e( 'Delete Permanently', 'snipcore' ); ?></option>
							<?php else : ?>
								<option value="clone"><?php esc_html_e( 'Clone', 'snipcore' ); ?></option>
								<option value="trash"><?php esc_html_e( 'Move to Trash', 'snipcore' ); ?></option>
								<option value="export"><?php esc_html_e( 'Export', 'snipcore' ); ?></option>
							<?php endif; ?>
						</select>
						<button type="submit" class="button action snipcore-bulk-apply"><?php esc_html_e( 'Apply', 'snipcore' ); ?></button>
					</div>
					<?php $this->render_list_pagination( $base_args, $current_page, $total_pages, $total_items, $per_page, $per_page_choices, 'bottom' ); ?>
					<br class="clear" />
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Reads the admin's saved "items per page" preference for the All
	 * Snippets list, falling back to a sane default. Stored as user
	 * meta (like WordPress core's own per-screen list table
	 * preferences) so it persists across visits without needing a
	 * dedicated settings field.
	 *
	 * @param int[] $choices Allowed per-page values.
	 * @return int
	 */
	private function get_list_per_page( array $choices ) {

		$default = in_array( 10, $choices, true ) ? 10 : $choices[0];

		// A per-page change is submitted as a GET param from the
		// selector below; persist it immediately so it's remembered
		// next time, then fall through and use it for this request.
		// The selector's URL includes a nonce (added in
		// render_list_pagination()) verified here before the change
		// is persisted to user meta.
		if ( isset( $_GET['snipcore_per_page'] ) ) {
			check_admin_referer( 'snipcore_per_page', 'snipcore_per_page_nonce' );
			$requested = absint( wp_unslash( $_GET['snipcore_per_page'] ) );
			if ( in_array( $requested, $choices, true ) ) {
				update_user_meta( get_current_user_id(), 'snipcore_list_per_page', $requested );
				return $requested;
			}
		}

		$stored = (int) get_user_meta( get_current_user_id(), 'snipcore_list_per_page', true );

		return in_array( $stored, $choices, true ) ? $stored : $default;
	}

	/**
	 * Renders a native-styled pagination block (item range, per-page
	 * selector, and Prev/Next/First/Last page links) matching
	 * WordPress core's own list table tablenav markup and classes.
	 *
	 * @param array $base_args        Query args (page/tab/s) every link must preserve.
	 * @param int   $current_page     Current 1-based page number.
	 * @param int   $total_pages      Total number of pages.
	 * @param int   $total_items      Total matching snippets (pre-pagination).
	 * @param int   $per_page         Current items-per-page value.
	 * @param int[] $per_page_choices Allowed items-per-page values.
	 * @param string $position        'top' or 'bottom' — used only to keep element ids unique.
	 * @param bool   $show_per_page   Whether to render the "Show N per page" selector. The
	 *                                selector is only needed once per screen, so callers
	 *                                that render this block more than once (top + bottom)
	 *                                should enable it for a single position only.
	 * @return void
	 */
	private function render_list_pagination( array $base_args, $current_page, $total_pages, $total_items, $per_page, array $per_page_choices, $position, $show_per_page = true ) {

		if ( 0 === $total_items ) {
			return;
		}

		$per_page_id = 'snipcore-per-page-' . $position;
		?>
		<div class="snipcore-pagination-controls tablenav-pages">
			<?php if ( $show_per_page ) : ?>
				<span class="snipcore-per-page-form">
					<label for="<?php echo esc_attr( $per_page_id ); ?>" class="snipcore-per-page-label">
						<?php esc_html_e( 'Show', 'snipcore' ); ?>
					</label>
					<select
						name="snipcore_per_page"
						id="<?php echo esc_attr( $per_page_id ); ?>"
						class="snipcore-per-page-select"
						data-base-url="<?php echo esc_url( wp_nonce_url( add_query_arg( $base_args, admin_url( 'admin.php' ) ), 'snipcore_per_page', 'snipcore_per_page_nonce' ) ); ?>"
					>
						<?php foreach ( $per_page_choices as $choice ) : ?>
							<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $per_page, $choice ); ?>><?php echo esc_html( $choice ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="snipcore-per-page-label"><?php esc_html_e( 'per page', 'snipcore' ); ?></span>
				</span>
			<?php endif; ?>

			<?php if ( $total_pages > 1 ) : ?>
				<?php if ( 'top' !== $position ) : ?>
				<span class="displaying-num">
					<?php
					printf(
						/* translators: 1: first item number on this page, 2: last item number on this page, 3: total number of items. */
						esc_html__( '%1$s–%2$s of %3$s', 'snipcore' ),
						esc_html( number_format_i18n( ( $current_page - 1 ) * $per_page + 1 ) ),
						esc_html( number_format_i18n( min( $current_page * $per_page, $total_items ) ) ),
						esc_html( number_format_i18n( $total_items ) )
					);
					?>
				</span>
				<?php endif; ?>
				<span class="pagination-links">
					<?php
					$first_args          = $base_args;
					$prev_args           = $base_args;
					$next_args           = $base_args;
					$last_args           = $base_args;
					$first_args['paged'] = 1;
					$prev_args['paged']  = max( 1, $current_page - 1 );
					$next_args['paged']  = min( $total_pages, $current_page + 1 );
					$last_args['paged']  = $total_pages;

					$is_first = ( 1 === $current_page );
					$is_last  = ( $current_page === $total_pages );
					?>
					<?php if ( $is_first ) : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
					<?php else : ?>
						<a class="first-page button" href="<?php echo esc_url( add_query_arg( $first_args, admin_url( 'admin.php' ) ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'First page', 'snipcore' ); ?></span>
							<span aria-hidden="true">&laquo;</span>
						</a>
						<a class="prev-page button" href="<?php echo esc_url( add_query_arg( $prev_args, admin_url( 'admin.php' ) ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'snipcore' ); ?></span>
							<span aria-hidden="true">&lsaquo;</span>
						</a>
					<?php endif; ?>

					<span class="paging-input">
						<?php
						printf(
							/* translators: 1: current page number, 2: total number of pages. */
							esc_html__( '%1$s of %2$s', 'snipcore' ),
							'<span class="current-page">' . esc_html( number_format_i18n( $current_page ) ) . '</span>',
							'<span class="total-pages">' . esc_html( number_format_i18n( $total_pages ) ) . '</span>'
						);
						?>
					</span>

					<?php if ( $is_last ) : ?>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
						<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
					<?php else : ?>
						<a class="next-page button" href="<?php echo esc_url( add_query_arg( $next_args, admin_url( 'admin.php' ) ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'snipcore' ); ?></span>
							<span aria-hidden="true">&rsaquo;</span>
						</a>
						<a class="last-page button" href="<?php echo esc_url( add_query_arg( $last_args, admin_url( 'admin.php' ) ) ); ?>">
							<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'snipcore' ); ?></span>
							<span aria-hidden="true">&raquo;</span>
						</a>
					<?php endif; ?>
				</span>
			<?php elseif ( 'top' !== $position ) : ?>
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: total number of items. */
						esc_html( _n( '%s item', '%s items', $total_items, 'snipcore' ) ),
						esc_html( number_format_i18n( $total_items ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders the Add/Edit Snippet screen. Serves both "Add New"
	 * (no id in the query string) and "Edit" (existing snippet loaded
	 * by id) using the same form and the same handle_save_snippet()
	 * handler, so both flows share one code path and one set of
	 * validation/security checks.
	 *
	 * @return void
	 */
	private function render_edit_page() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only (GET) lookup of which snippet to display for editing; the actual save is nonce/capability-checked in handle_save_snippet().
		$id      = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$snippet = $id ? SnipCore_Snippets::get( $id ) : null;

		if ( $id && null === $snippet ) {
			wp_die( esc_html__( 'Snippet not found.', 'snipcore' ) );
		}

		$snippet = $snippet ? $snippet : array(
			'id'                => '',
			'name'              => '',
			'type'              => 'functions',
			'location'          => 'everywhere',
			'code'              => '',
			'description'       => '',
			'tags'              => array(),
			'priority'          => 10,
			'status'            => 'inactive',
			'display_mode'      => 'all',
			'display_post_ids'  => array(),
			'device_display'    => 'all',
			'schedule_enabled'  => false,
			'schedule_start'    => '',
			'schedule_end'      => '',
		);

		// If the last save attempt was rejected (see redirect_with_error()),
		// re-populate the form with what the user actually submitted
		// rather than the previously-stored (or blank, for a new
		// snippet) record, so a rejected save doesn't cost them their
		// entered code and settings. Only ever set alongside $error,
		// and only for the duration of this one redisplay.
		$preserved = get_transient( 'snipcore_form_preserve_' . get_current_user_id() );
		if ( $preserved ) {
			delete_transient( 'snipcore_form_preserve_' . get_current_user_id() );
			if ( is_array( $preserved ) ) {
				$snippet = array_merge( $snippet, $preserved );
			}
		}

		$tags_value = is_array( $snippet['tags'] ) ? implode( ', ', $snippet['tags'] ) : $snippet['tags'];

		$settings             = SnipCore_Admin::get_settings();
		$enable_tags          = ! empty( $settings['enable_tags'] );
		$enable_descriptions  = ! empty( $settings['enable_descriptions'] );
		$description_rows     = (int) $settings['description_editor_height'];
		if ( $description_rows < 1 ) {
			$description_rows = 3;
		}

		$error = get_transient( 'snipcore_form_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'snipcore_form_error_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<div class="snipcore-edit-header-sticky">
				<h1 class="wp-heading-inline">
					<?php echo $id ? esc_html__( 'Edit Snippet', 'snipcore' ) : esc_html__( 'Add New Snippet', 'snipcore' ); ?>
				</h1>
				<a href="<?php echo esc_url( $this->admin->get_list_url() ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back to All Snippets', 'snipcore' ); ?>
				</a>
				<span class="snipcore-toggle-wrap snipcore-header-edit-actions">
					<span class="snipcore-toggle-text" id="snipcore-status-toggle-text">
						<?php echo 'active' === $snippet['status'] ? esc_html__( 'Active', 'snipcore' ) : esc_html__( 'Inactive', 'snipcore' ); ?>
					</span>
					<label class="snipcore-toggle-switch snipcore-toggle-switch-lg" for="snipcore-status-toggle">
						<input
							type="checkbox"
							id="snipcore-status-toggle"
							name="status"
							class="snipcore-status-toggle"
							value="active"
							form="snipcore-edit-snippet-form"
							<?php checked( 'active', $snippet['status'] ); ?>
						/>
						<span class="snipcore-toggle-slider" aria-hidden="true"></span>
						<span class="screen-reader-text">
							<?php esc_html_e( 'Toggle activation', 'snipcore' ); ?>
						</span>
					</label>
					<button type="submit" form="snipcore-edit-snippet-form" class="button button-primary">
						<?php echo $id ? esc_html__( 'Update', 'snipcore' ) : esc_html__( 'Save Snippet', 'snipcore' ); ?>
					</button>
				</span>
				<hr class="wp-header-end">
			</div>

			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error ); ?></p>
				</div>
			<?php elseif ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the save-action redirect result; the action itself was already nonce/capability-checked in handle_save_snippet(). ?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo 'active' === $snippet['status']
							? esc_html__( 'Snippet saved and activated.', 'snipcore' )
							: esc_html__( 'Snippet saved.', 'snipcore' );
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="snipcore-edit-snippet-form" class="snipcore-loading-on-submit">
				<?php wp_nonce_field( 'snipcore_save_snippet_' . $id ); ?>
				<input type="hidden" name="action" value="snipcore_save_snippet" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>" />

				<div class="snipcore-edit-columns-bordered snipcore-edit-columns-top">
					<div class="snipcore-edit-column">
							<div class="snipcore-inline-row">
								<div class="snipcore-inline-field">
									<input name="name" type="text" id="snipcore-name" class="regular-text" placeholder="<?php esc_attr_e( 'Enter Snippet Title', 'snipcore' ); ?>" value="<?php echo esc_attr( $snippet['name'] ); ?>" />
								</div>
								<div class="snipcore-inline-field snipcore-inline-field-type">
									<?php
									$type_lang_map    = array(
										'PHP'  => '#7277AE',
										'HTML' => '#DC4821',
										'CSS'  => '#196FB4',
										'JS'   => '#E9D44D',
									);
									$current_type_label = $this->admin->get_type_label( $snippet['type'] );
									?>
									<select name="type" id="snipcore-type">
										<option value="functions" <?php selected( $snippet['type'], 'functions' ); ?>><?php esc_html_e( 'PHP', 'snipcore' ); ?></option>
										<option value="content" <?php selected( $snippet['type'], 'content' ); ?>><?php esc_html_e( 'HTML', 'snipcore' ); ?></option>
										<option value="style" <?php selected( $snippet['type'], 'style' ); ?>><?php esc_html_e( 'CSS', 'snipcore' ); ?></option>
										<option value="scripts" <?php selected( $snippet['type'], 'scripts' ); ?>><?php esc_html_e( 'JS', 'snipcore' ); ?></option>
									</select>
								</div>
							</div>
							<div class="snipcore-stacked-field">
								<label for="snipcore-code"><?php esc_html_e( 'Snippet Content', 'snipcore' ); ?></label>
								<div class="snipcore-code-editor-wrap">
									<div class="snipcore-editor-toolbar">
										<span class="snipcore-tab-lang-badge" id="snipcore-language-badge" style="background-color: <?php echo esc_attr( isset( $type_lang_map[ $current_type_label ] ) ? $type_lang_map[ $current_type_label ] : '#2271b1' ); ?>;"><?php echo esc_html( $current_type_label ); ?></span>
									</div>
									<textarea name="code" id="snipcore-code" class="large-text code" rows="18" spellcheck="false" placeholder="<?php esc_attr_e( 'Enter code here', 'snipcore' ); ?>"><?php echo esc_textarea( $snippet['code'] ); ?></textarea>
								</div>
							</div>
						</div>
						<div class="snipcore-edit-column">
							<div class="snipcore-stacked-field">
								<label for="snipcore-location"><?php esc_html_e( 'Location', 'snipcore' ); ?></label>
								<select name="location" id="snipcore-location">
									<option value="everywhere" <?php selected( $snippet['location'], 'everywhere' ); ?>><?php esc_html_e( 'Run Everywhere', 'snipcore' ); ?></option>
									<option value="admin" <?php selected( $snippet['location'], 'admin' ); ?>><?php esc_html_e( 'Administrative Area', 'snipcore' ); ?></option>
									<option value="frontend" <?php selected( $snippet['location'], 'frontend' ); ?>><?php esc_html_e( 'Frontend', 'snipcore' ); ?></option>
									<option value="once" <?php selected( $snippet['location'], 'once' ); ?>><?php esc_html_e( 'Only Run Once', 'snipcore' ); ?></option>
								</select>
							</div>							<?php if ( $enable_descriptions ) : ?>
							<div class="snipcore-stacked-field">
								<label for="snipcore-description"><?php esc_html_e( 'Description', 'snipcore' ); ?></label>
								<textarea name="description" id="snipcore-description" class="large-text" rows="<?php echo esc_attr( $description_rows ); ?>" placeholder="<?php esc_attr_e( 'Add a short note', 'snipcore' ); ?>"><?php echo esc_textarea( $snippet['description'] ); ?></textarea>
							</div>
							<?php endif; ?>
							<?php if ( $enable_tags ) : ?>
							<div class="snipcore-stacked-field">
								<label for="snipcore-tags"><?php esc_html_e( 'Tags', 'snipcore' ); ?></label>
								<input name="tags" type="text" id="snipcore-tags" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. header, tracking', 'snipcore' ); ?>" value="<?php echo esc_attr( $tags_value ); ?>" />
							</div>
							<?php endif; ?>
							<div class="snipcore-stacked-field">
								<label for="snipcore-priority"><?php esc_html_e( 'Priority', 'snipcore' ); ?></label>
								<input name="priority" type="number" id="snipcore-priority" class="small-text" value="<?php echo esc_attr( $snippet['priority'] ); ?>" step="1" />
							</div>
							<div class="snipcore-stacked-field">
								<label><?php esc_html_e( 'Device Display', 'snipcore' ); ?></label>
								<div class="snipcore-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Device Display', 'snipcore' ); ?>">
									<input type="radio" id="snipcore-device-display-all" name="device_display" value="all" <?php checked( 'all', $snippet['device_display'] ); ?> />
									<label for="snipcore-device-display-all"><?php esc_html_e( 'All Devices', 'snipcore' ); ?></label>

									<input type="radio" id="snipcore-device-display-desktop" name="device_display" value="desktop" <?php checked( 'desktop', $snippet['device_display'] ); ?> />
									<label for="snipcore-device-display-desktop"><?php esc_html_e( 'Desktop Only', 'snipcore' ); ?></label>

									<input type="radio" id="snipcore-device-display-mobile" name="device_display" value="mobile" <?php checked( 'mobile', $snippet['device_display'] ); ?> />
									<label for="snipcore-device-display-mobile"><?php esc_html_e( 'Mobile Only', 'snipcore' ); ?></label>
								</div>
								<p class="description"><?php esc_html_e( 'Frontend output only.', 'snipcore' ); ?></p>
							</div>
						</div>
					</div><!-- .snipcore-edit-columns-bordered.snipcore-edit-columns-top -->

				<div class="snipcore-edit-section">
					<h2><?php esc_html_e( 'Display Rules', 'snipcore' ); ?></h2>
					<?php /* Inner border box intentionally removed: Site Display content now sits directly in this section's outer box. */ ?>
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Site Display', 'snipcore' ); ?></th>
									<td>
										<?php
										$chosen        = is_array( $snippet['display_post_ids'] ) ? $snippet['display_post_ids'] : array();
										$targetable    = $this->get_display_targetable_posts();
										$mode          = isset( $snippet['display_mode'] ) ? $snippet['display_mode'] : 'all';
										$is_targeted   = 'all' !== $mode;
										// 'specific' is the default sub-mode the segmented
										// buttons fall back to whenever the toggle is turned
										// on but the stored mode is still 'all' (e.g. a
										// brand new snippet) — 'exclude' only shows as
										// checked when it's the value already on record.
										$sub_mode      = 'exclude' === $mode ? 'exclude' : 'specific';
										$targetable_by = array(
											'page' => array(),
											'post' => array(),
										);
										foreach ( $targetable as $post_option ) {
											$bucket = 'page' === $post_option['type'] ? 'page' : 'post';
											$targetable_by[ $bucket ][] = $post_option;
										}
										?>
										<fieldset class="snipcore-display-fieldset">
											<span class="snipcore-toggle-wrap">
												<label class="snipcore-toggle-switch" for="snipcore-display-mode-all">
													<input
														type="checkbox"
														id="snipcore-display-mode-all"
														class="snipcore-display-toggle"
														<?php checked( $is_targeted ); ?>
													/>
													<span class="snipcore-toggle-slider" aria-hidden="true"></span>
													<span class="screen-reader-text"><?php esc_html_e( 'Target Specific Pages & Posts', 'snipcore' ); ?></span>
												</label>
												<span class="snipcore-toggle-text"><?php esc_html_e( 'Target Specific Pages & Posts', 'snipcore' ); ?></span>
											</span>

											<input type="hidden" name="display_mode" id="snipcore-display-mode-field" value="<?php echo esc_attr( $mode ); ?>" />

											<div id="snipcore-display-targeting-wrap" class="snipcore-display-targeting-wrap<?php echo $is_targeted ? '' : ' snipcore-hidden'; ?>">
												<div class="snipcore-display-mode-cards" id="snipcore-display-mode-buttons" role="radiogroup" aria-label="<?php esc_attr_e( 'Pages & Posts Targeting', 'snipcore' ); ?>">
													<label class="snipcore-display-mode-card" for="snipcore-display-mode-specific">
														<input type="radio" id="snipcore-display-mode-specific" name="display_sub_mode" value="specific" <?php checked( 'specific', $sub_mode ); ?> />
														<span class="snipcore-display-mode-card-label"><?php esc_html_e( 'Specific Pages & Posts', 'snipcore' ); ?></span>
													</label>

													<label class="snipcore-display-mode-card" for="snipcore-display-mode-exclude">
														<input type="radio" id="snipcore-display-mode-exclude" name="display_sub_mode" value="exclude" <?php checked( 'exclude', $sub_mode ); ?> />
														<span class="snipcore-display-mode-card-label"><?php esc_html_e( 'Exclude Pages & Posts', 'snipcore' ); ?></span>
													</label>
												</div>

												<p class="snipcore-display-targeting-list">
													<div class="snipcore-bordered-box snipcore-checkbox-list" id="snipcore-display-post-ids-wrap" role="group" aria-label="<?php esc_attr_e( 'Pages & Posts', 'snipcore' ); ?>">
														<?php if ( ! empty( $targetable_by['page'] ) ) : ?>
															<div class="snipcore-checkbox-group-label"><?php esc_html_e( 'Pages', 'snipcore' ); ?></div>
															<?php foreach ( $targetable_by['page'] as $post_option ) : ?>
																<label class="snipcore-checkbox-item">
																	<input type="checkbox" name="display_post_ids[]" value="<?php echo esc_attr( $post_option['id'] ); ?>" <?php checked( in_array( $post_option['id'], $chosen, true ) ); ?> />
																	<?php echo esc_html( $post_option['label'] ); ?>
																</label>
															<?php endforeach; ?>
														<?php endif; ?>
														<?php if ( ! empty( $targetable_by['post'] ) ) : ?>
															<div class="snipcore-checkbox-group-label"><?php esc_html_e( 'Posts', 'snipcore' ); ?></div>
															<?php foreach ( $targetable_by['post'] as $post_option ) : ?>
																<label class="snipcore-checkbox-item">
																	<input type="checkbox" name="display_post_ids[]" value="<?php echo esc_attr( $post_option['id'] ); ?>" <?php checked( in_array( $post_option['id'], $chosen, true ) ); ?> />
																	<?php echo esc_html( $post_option['label'] ); ?>
																</label>
															<?php endforeach; ?>
														<?php endif; ?>
													</div>
												</p>
											</div>
										</fieldset>
									</td>
								</tr>
							</tbody>
						</table>
					<div class="snipcore-edit-row">
						<table class="form-table" role="presentation">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Schedule Snippet', 'snipcore' ); ?></th>
									<td>
										<span class="snipcore-toggle-wrap">
											<label class="snipcore-toggle-switch" for="snipcore-schedule-enabled">
												<input type="checkbox" id="snipcore-schedule-enabled" class="snipcore-schedule-toggle" name="schedule_enabled" value="1" <?php checked( ! empty( $snippet['schedule_enabled'] ) ); ?> />
												<span class="snipcore-toggle-slider" aria-hidden="true"></span>
												<span class="screen-reader-text"><?php esc_html_e( 'Restrict this snippet to a date/time window', 'snipcore' ); ?></span>
											</label>
											<span class="snipcore-toggle-text"><?php esc_html_e( 'Restrict this snippet to a date/time window', 'snipcore' ); ?></span>
										</span>
										<div id="snipcore-schedule-fields-wrap" class="snipcore-conditional-field snipcore-schedule-fields<?php echo empty( $snippet['schedule_enabled'] ) ? ' snipcore-hidden' : ''; ?>">
											<div class="snipcore-stacked-field">
												<label for="snipcore-schedule-start"><?php esc_html_e( 'Start', 'snipcore' ); ?></label>
												<input type="datetime-local" id="snipcore-schedule-start" name="schedule_start" class="snipcore-schedule-input" value="<?php echo esc_attr( $this->to_datetime_local_value( $snippet['schedule_start'] ) ); ?>" />
											</div>
											<div class="snipcore-stacked-field">
												<label for="snipcore-schedule-end"><?php esc_html_e( 'End', 'snipcore' ); ?></label>
												<input type="datetime-local" id="snipcore-schedule-end" name="schedule_end" class="snipcore-schedule-input" value="<?php echo esc_attr( $this->to_datetime_local_value( $snippet['schedule_end'] ) ); ?>" />
											</div>
										</div>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

			</form>
		</div>
		<?php
	}

	/**
	 * Handles saving the Add/Edit Snippet form.
	 *
	 * Validates required fields, enforces nonce/capability checks,
	 * and stores the snippet with the activation state set by the
	 * Active/Inactive toggle.
	 *
	 * @return void
	 */
	public function handle_save_snippet() {

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		check_admin_referer( 'snipcore_save_snippet_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'functions';
		$location = isset( $_POST['location'] ) ? sanitize_key( wp_unslash( $_POST['location'] ) ) : 'everywhere';

		// Validation: a snippet must have a title and a recognized type/location.
		if ( '' === $name ) {
			$this->redirect_with_error( $id, __( 'Snippet Title is required.', 'snipcore' ) );
		}

		if ( ! in_array( $type, SnipCore_Snippets::TYPES, true ) ) {
			$this->redirect_with_error( $id, __( 'Please choose a valid snippet type.', 'snipcore' ) );
		}

		if ( ! in_array( $location, SnipCore_Snippets::LOCATIONS, true ) ) {
			$this->redirect_with_error( $id, __( 'Please choose a valid location.', 'snipcore' ) );
		}

		$existing = $id ? SnipCore_Snippets::get( $id ) : null;

		// When the Description/Tags fields are hidden via the General settings,
		// the form won't post them at all — preserve whatever the snippet
		// already had rather than treating the absence as "clear this field".
		$description = isset( $_POST['description'] )
			? sanitize_textarea_field( wp_unslash( $_POST['description'] ) )
			: ( $existing ? $existing['description'] : '' );

		$tags = isset( $_POST['tags'] )
			? sanitize_text_field( wp_unslash( $_POST['tags'] ) )
			: ( $existing ? $existing['tags'] : '' );

		$display_mode = isset( $_POST['display_mode'] ) ? sanitize_key( wp_unslash( $_POST['display_mode'] ) ) : 'all';
		if ( ! in_array( $display_mode, SnipCore_Snippets::DISPLAY_MODES, true ) ) {
			$display_mode = 'all';
		}

		// The Site Display picker is a native <select multiple>, which
		// (per how browsers submit forms) sends no display_post_ids
		// key at all when nothing is selected — not an empty one. So
		// unlike Description/Tags, "absent" here legitimately means
		// "cleared to none" rather than "field wasn't shown", since
		// this block is always rendered in the form.
		$display_post_ids = isset( $_POST['display_post_ids'] )
			? array_map( 'absint', wp_unslash( (array) $_POST['display_post_ids'] ) )
			: array();

		$device_display = isset( $_POST['device_display'] ) ? sanitize_key( wp_unslash( $_POST['device_display'] ) ) : 'all';
		if ( ! in_array( $device_display, SnipCore_Snippets::DEVICE_MODES, true ) ) {
			$device_display = 'all';
		}

		$schedule_enabled = ! empty( $_POST['schedule_enabled'] );
		$schedule_start   = isset( $_POST['schedule_start'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_start'] ) ) : '';
		$schedule_end     = isset( $_POST['schedule_end'] ) ? sanitize_text_field( wp_unslash( $_POST['schedule_end'] ) ) : '';

		// A schedule with both ends set must have Start before End —
		// anything else can never match and is almost certainly a
		// mistake, so catch it here rather than silently saving a
		// snippet that can never run.
		if ( $schedule_enabled && '' !== $schedule_start && '' !== $schedule_end
			&& strtotime( str_replace( 'T', ' ', $schedule_start ) ) >= strtotime( str_replace( 'T', ' ', $schedule_end ) ) ) {
			$this->redirect_with_error( $id, __( 'Schedule Start must be before Schedule End.', 'snipcore' ) );
		}

		$data = array(
			'name'              => $name,
			'type'              => $type,
			'location'          => $location,
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This is the snippet's raw PHP/JS/CSS/HTML source, intentionally not run through a sanitizer: sanitize_text_field()/sanitize_textarea_field() and similar would mangle or strip valid code (quotes, tags, angle brackets, etc.), destroying the snippet's functionality. The request is already nonce- and capability-checked above (check_admin_referer() + current_user_can( 'manage_options' )), wp_unslash() reverses WordPress's automatic slashing, and the value is escaped appropriately for its context at output time (see class-snipcore-executor.php).
			'code'              => isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '',
			'description'       => $description,
			'tags'              => $tags,
			'priority'          => isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 10,
			'trashed'           => $existing ? $existing['trashed'] : false,
			'display_mode'      => $display_mode,
			'display_post_ids'  => $display_post_ids,
			'device_display'    => $device_display,
			'schedule_enabled'  => $schedule_enabled,
			'schedule_start'    => $schedule_start,
			'schedule_end'      => $schedule_end,
		);

		// Activation state comes directly from the Active/Inactive
		// toggle: a checked box posts status=active; an unchecked box
		// posts nothing at all (standard checkbox behavior), which
		// means inactive. New snippets with the toggle left off stay
		// inactive, matching the previous "Save Snippet" behavior.
		$data['status'] = ( isset( $_POST['status'] ) && 'active' === sanitize_key( wp_unslash( $_POST['status'] ) ) )
			? 'active'
			: 'inactive';

		// Reject an obvious type/code mismatch before it ever reaches
		// storage, using the exact same rules SnipCore_Snippets applies
		// at the storage layer (Phase 2) and SnipCore_Executor applies
		// at execution time (Phase 3) - this is only an earlier,
		// user-facing check of that same single rule set, not a
		// second one.
		$mismatch_reason = SnipCore_Snippets::get_type_code_mismatch_reason( $data['type'], $data['code'] );
		if ( '' !== $mismatch_reason ) {
			$this->redirect_with_error( $id, $mismatch_reason, $data );
		}

		if ( $id && null !== $existing ) {
			$success = SnipCore_Snippets::update( $id, $data );
		} else {
			$id      = SnipCore_Snippets::insert( $data );
			$success = (bool) $id;
		}

		if ( ! $success ) {
			$this->redirect_with_error( $id, __( 'Snippet could not be saved. Please try again.', 'snipcore' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'action'  => 'edit',
					'id'      => $id,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Stores a validation error message and redirects back to the
	 * edit screen. Uses a short-lived per-user transient rather than
	 * echoing user input back unsanitized.
	 *
	 * @param string     $id      Snippet ID, if editing an existing snippet.
	 * @param string     $message Human-readable error message.
	 * @param array|null $preserve Optional. The submitted (already
	 *                             sanitized) field values to re-populate
	 *                             the form with on redisplay, so the
	 *                             user doesn't have to retype their code
	 *                             and settings after a rejected save.
	 *                             Omit for validation failures that
	 *                             happen before $data is assembled.
	 * @return void
	 */
	private function redirect_with_error( $id, $message, $preserve = null ) {
		set_transient( 'snipcore_form_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );

		if ( null !== $preserve ) {
			set_transient( 'snipcore_form_preserve_' . get_current_user_id(), $preserve, MINUTE_IN_SECONDS );
		}

		$args = array(
			'page'   => self::MENU_SLUG,
			'action' => 'edit',
		);
		if ( $id ) {
			$args['id'] = $id;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
