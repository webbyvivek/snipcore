<?php
/**
 * Header/Footer admin controller: the Global Header & Footer admin
 * page and the save handler behind it.
 *
 * Extracted from SnipCore_Admin (Architecture Fix Phase 5) so this
 * class owns exactly the Header/Footer-screen responsibility:
 * nothing here changes behavior — the URL, form actions, nonces,
 * capability checks, redirect, notices, and storage call are
 * unchanged from the original SnipCore_Admin methods this class was
 * built from.
 *
 * The screen's asset enqueuing (code editor init, etc.) remains a
 * cross-cutting concern handled by SnipCore_Admin::enqueue_assets(),
 * since that single method already scopes and enqueues assets for
 * the List, Settings, and Header & Footer screens together; this
 * class does not duplicate or touch it.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Header_Footer_Admin
 */
class SnipCore_Header_Footer_Admin {

	/**
	 * Slug used for the Global Header & Footer submenu page.
	 *
	 * Mirrors SnipCore_Admin::HEADER_FOOTER_SLUG (a fixed plugin
	 * routing slug, not configurable data) so this class does not
	 * need a cross-class constant reference for its own redirect
	 * URLs, following the same pattern already used by
	 * SnipCore_Snippets_Admin::MENU_SLUG and
	 * SnipCore_Settings_Admin::SETTINGS_SLUG.
	 *
	 * @var string
	 */
	const HEADER_FOOTER_SLUG = 'snipcore-header-footer';

	/**
	 * The SnipCore_Admin instance this controller was constructed
	 * with. Currently unused directly by this class (it has no
	 * helper calls back into SnipCore_Admin), but accepted for
	 * consistency with the SnipCore_Snippets_Admin /
	 * SnipCore_Settings_Admin constructor pattern and in case a
	 * genuinely shared helper is needed here in the future.
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
	 * Hook registration for the Header & Footer admin screen.
	 *
	 * Same hook name and callback priority as the original
	 * SnipCore_Admin::init() registration for this action.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_post_snipcore_save_header_footer', array( $this, 'handle_save_header_footer' ) );
	}

	/**
	 * Renders the "Header & Footer" page: three native WordPress code
	 * editors (Header, Body, Footer) and nothing else. Kept
	 * deliberately simple and lightweight — one field per section,
	 * one Save button, no tabs, no extra options.
	 *
	 * @return void
	 */
	public function render_header_footer_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$fields = SnipCore_Header_Footer::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Global Header & Footer', 'snipcore' ); ?></h1>
			<p><?php esc_html_e( 'Add code to run on every page. Each section saves independently.', 'snipcore' ); ?></p>

			<?php
			$updated_field  = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of the save-handler's redirect result; the save itself was already nonce/capability-checked in handle_save_header_footer().
			$updated_labels = array(
				'header' => __( 'Header saved.', 'snipcore' ),
				'body'   => __( 'Body saved.', 'snipcore' ),
				'footer' => __( 'Footer saved.', 'snipcore' ),
			);
			?>
			<?php if ( isset( $updated_labels[ $updated_field ] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( $updated_labels[ $updated_field ] ); ?></p>
				</div>
			<?php endif; ?>

			<div class="snipcore-hf-sections">
			<?php
			$sections = array(
				'header' => array(
					'title'       => __( 'Header', 'snipcore' ),
					'placement'   => __( 'Placement: start of &lt;head&gt;', 'snipcore' ),
					'description' => __( 'For meta tags, verification codes, or early-loading CSS/JS.', 'snipcore' ),
				),
				'body'   => array(
					'title'       => __( 'Body', 'snipcore' ),
					'placement'   => __( 'Placement: right after the opening &lt;body&gt; tag', 'snipcore' ),
					'description' => __( 'For tag-manager noscript snippets and similar.', 'snipcore' ),
				),
				'footer' => array(
					'title'       => __( 'Footer', 'snipcore' ),
					'placement'   => __( 'Placement: end of the page, before &lt;/body&gt;', 'snipcore' ),
					'description' => __( 'For analytics and chat-widget scripts.', 'snipcore' ),
				),
			);
			foreach ( $sections as $field => $section ) :
				?>
				<div class="snipcore-hf-section">
					<h2><?php echo esc_html( $section['title'] ); ?></h2>
					<p class="snipcore-hf-placement"><?php echo esc_html( $section['placement'] ); ?></p>
					<p class="description snipcore-hf-description"><?php echo esc_html( $section['description'] ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="snipcore-loading-on-submit">
						<?php wp_nonce_field( 'snipcore_save_header_footer_' . $field ); ?>
						<input type="hidden" name="action" value="snipcore_save_header_footer" />
						<input type="hidden" name="snipcore_hf_field" value="<?php echo esc_attr( $field ); ?>" />
						<div class="snipcore-header-footer-editor">
							<textarea name="<?php echo esc_attr( $field ); ?>" id="snipcore-<?php echo esc_attr( $field ); ?>-code" class="large-text code" rows="12" spellcheck="false"><?php echo esc_textarea( $fields[ $field ] ); ?></textarea>
						</div>
						<?php
						submit_button(
							/* translators: %s: section title (Header, Body, or Footer). */
							sprintf( __( 'Save %s', 'snipcore' ), $section['title'] ),
							'primary',
							'submit',
							true,
							array( 'class' => 'button button-primary snipcore-hf-save' )
						);
						?>
					</form>
				</div>
				<?php
			endforeach;
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handles saving a single Header/Body/Footer field.
	 *
	 * Each of the three sections on the Global Header & Footer screen
	 * posts independently (its own form, its own nonce, its own
	 * "Save" button), so saving one section never re-submits — and
	 * can never accidentally clobber — the other two: only the
	 * submitted field is changed; the other two are read from storage
	 * and written back exactly as they already were.
	 *
	 * @return void
	 */
	public function handle_save_header_footer() {

		$field = isset( $_POST['snipcore_hf_field'] ) ? sanitize_key( wp_unslash( $_POST['snipcore_hf_field'] ) ) : '';

		if ( ! in_array( $field, array( 'header', 'body', 'footer' ), true ) ) {
			wp_die( esc_html__( 'Invalid Header & Footer field.', 'snipcore' ) );
		}

		check_admin_referer( 'snipcore_save_header_footer_' . $field );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'snipcore' ) );
		}

		$current            = SnipCore_Header_Footer::get_all();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This is raw HTML/JS/CSS code for the site's Header/Body/Footer injection point, intentionally not run through a sanitizer: sanitize_text_field()/sanitize_textarea_field() and similar would mangle or strip valid markup and code, destroying the feature's functionality. The request is already nonce- and capability-checked above (check_admin_referer() + current_user_can( 'manage_options' )), wp_unslash() reverses WordPress's automatic slashing, and the field name itself is validated against a fixed whitelist ('header'/'body'/'footer') before this line runs.
		$current[ $field ]  = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';

		SnipCore_Header_Footer::save( $current );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::HEADER_FOOTER_SLUG,
					'updated' => $field,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
