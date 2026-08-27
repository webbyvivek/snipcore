<?php
/**
 * Snippet execution engine.
 *
 * Runs active, non-trashed snippets at the correct point in the
 * request lifecycle based on their type (PHP/HTML/CSS/JS) and their
 * location (everywhere, admin, frontend, once). Only ever executes
 * snippets that a site administrator (capability manage_options) has
 * explicitly created and activated through the SnipCore admin UI —
 * this module never accepts code or execution instructions from
 * request input.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Executor
 *
 * Rule application matrix — how Site Display, Device Display, and
 * Schedule Snippet combine at each execution/output point:
 *
 * | Caller                                | Schedule | Site Display | Device Display |
 * |----------------------------------------|:--------:|:-------------:|:---------------:|
 * | run_php_snippets() (admin + "once")     |    Yes   |      No       |       No        |
 * | run_php_snippets_frontend()             |    Yes   |     Yes       |      Yes        |
 * | output_frontend_head()/_footer()        |    Yes   |     Yes       |      Yes        |
 * | output_admin_head()/_footer()           |    Yes   |      No       |       No        |
 *
 * Schedule Snippet is a hard gate on whether a snippet may execute at
 * all, so it is checked unconditionally everywhere (inside
 * get_runnable_snippets()). Site Display and Device Display are
 * frontend-targeting concepts and are only ever consulted where the
 * flags above show "Yes" — admin-context execution and output never
 * evaluate them, so admin-only snippets behave exactly as they did
 * before either feature existed.
 */
class SnipCore_Executor {

	/**
	 * Registers the runtime hooks. Safe to call unconditionally
	 * (frontend and wp-admin); each hook checks context internally.
	 *
	 * @return void
	 */
	public function init() {
		// Emergency kill switch: when active, no snippet of any type
		// executes or outputs, and Global Header & Footer is skipped
		// too — but the hooks below are still registered (cheap, and
		// keeps behavior simple); each callback bails out via
		// SnipCore_Safe_Mode::is_enabled() at the very top, before
		// doing any real work or touching snippet storage.
		//
		// Crash recovery's shutdown handler is registered
		// unconditionally, independent of the kill switch, so it is
		// still active on the very request where the kill switch
		// itself might be getting flipped on.
		$safe_mode = new SnipCore_Safe_Mode();
		$safe_mode->init();

		// PHP snippets run early, once WP (and plugins) are fully loaded,
		// so registered hooks/functions are available but templates
		// haven't rendered yet. This timing is unaffected by Site
		// Display and covers admin-context and "Only Run Once" PHP
		// snippets exactly as before.
		add_action( 'init', array( $this, 'run_php_snippets' ), 20 );

		// Frontend PHP snippets that may use Site Display run slightly
		// later, once the main query is parsed, so is_singular()/
		// get_the_ID() checks inside passes_display_rules() are
		// accurate. Still fires well before any template output.
		add_action( 'wp', array( $this, 'run_php_snippets_frontend' ) );

		// Output-type snippets (CSS/JS/HTML) are placed at native,
		// well-known WordPress render points.
		add_action( 'wp_head', array( $this, 'output_frontend_head' ) );
		add_action( 'wp_footer', array( $this, 'output_frontend_footer' ) );
		add_action( 'admin_head', array( $this, 'output_admin_head' ) );
		add_action( 'admin_footer', array( $this, 'output_admin_footer' ) );

		// Global Header & Footer: three site-wide code fields managed
		// on their own admin screen (independent of individual
		// snippets). Rather than relying on theme-dependent hooks
		// (some themes never call wp_body_open(), and other plugins
		// may print markup after wp_head()/wp_footer() has already
		// fired), the final rendered HTML is captured in an output
		// buffer and the three fields are spliced directly into it —
		// Header immediately before </head>, Body immediately after
		// the opening <body> tag, and Footer immediately before
		// </body> — so placement is correct regardless of theme or
		// plugin load order. See maybe_start_global_output_buffer().
		add_action( 'template_redirect', array( $this, 'maybe_start_global_output_buffer' ) );
	}

	/**
	 * Determines whether a snippet's location matches the current request context.
	 *
	 * @param string $location One of SnipCore_Snippets::LOCATIONS.
	 * @return bool
	 */
	private function location_matches_context( $location ) {
		if ( 'everywhere' === $location || 'once' === $location ) {
			return true;
		}

		if ( 'admin' === $location ) {
			return is_admin();
		}

		if ( 'frontend' === $location ) {
			return ! is_admin();
		}

		return false;
	}

	/**
	 * Determines whether a snippet's Site Display targeting (Specific
	 * Pages & Posts / Exclude Pages & Posts) allows it to render on
	 * the current page.
	 *
	 * Scope is deliberately narrow: Site Display only ever restricts
	 * rendering that would otherwise happen on the public frontend for
	 * a single post/page. It never affects wp-admin rendering, "Only
	 * Run Once" snippets, or any frontend context that isn't a
	 * singular post/page (the home page, an archive, a search results
	 * page, etc. have no single post for "Specific"/"Exclude" to test
	 * against, so targeting simply doesn't apply there and the
	 * snippet's normal location rule alone decides). This keeps every
	 * location other than frontend/everywhere working exactly as
	 * before.
	 *
	 * @param array $snippet Snippet record.
	 * @return bool
	 */
	private function passes_display_rules( array $snippet ) {
		$mode = isset( $snippet['display_mode'] ) ? $snippet['display_mode'] : 'all';

		if ( 'all' === $mode ) {
			return true;
		}

		// Site Display is a frontend-only concept: it never restricts
		// admin-side rendering, and "Only Run Once" snippets keep
		// firing regardless of page, matching their existing
		// run-once-anywhere-then-deactivate behavior.
		$location = isset( $snippet['location'] ) ? $snippet['location'] : 'everywhere';
		if ( is_admin() || 'once' === $location || ! in_array( $location, array( 'frontend', 'everywhere' ), true ) ) {
			return true;
		}

		if ( ! is_singular() ) {
			// No single post/page to test targeting against: a
			// "Specific" list can't match, so the snippet stays
			// hidden; an "Exclude" list can't exclude anything, so
			// the snippet stays visible. Either way this defers to
			// what makes sense for a list built entirely out of
			// individual posts/pages.
			return 'exclude' === $mode;
		}

		$current_id = (int) get_the_ID();
		$targeted   = isset( $snippet['display_post_ids'] ) && is_array( $snippet['display_post_ids'] )
			? $snippet['display_post_ids']
			: array();

		$is_targeted = in_array( $current_id, $targeted, true );

		return 'specific' === $mode ? $is_targeted : ! $is_targeted;
	}

	/**
	 * Determines whether a snippet's Device Display targeting (Desktop /
	 * Desktop + Mobile / Mobile) allows it to render for the current
	 * request.
	 *
	 * Like Site Display, Device Display is a frontend-only concept: it
	 * is only ever consulted by the automatic frontend output hooks
	 * (get_runnable_snippets() with $apply_device_rules = true). It is
	 * never consulted for admin-context rendering (output_admin_head(),
	 * output_admin_footer(), run_php_snippets() for admin/once
	 * snippets).
	 *
	 * Device detection uses WordPress' own wp_is_mobile(), which is
	 * unaffected by (and evaluated independently of) Site Display's
	 * is_singular()/get_the_ID() checks, so the two targeting controls
	 * combine without interfering with each other.
	 *
	 * @param array $snippet Snippet record.
	 * @return bool
	 */
	private function passes_device_rules( array $snippet ) {
		$device = isset( $snippet['device_display'] ) ? $snippet['device_display'] : 'all';

		if ( 'all' === $device ) {
			return true;
		}

		$is_mobile = function_exists( 'wp_is_mobile' ) ? wp_is_mobile() : false;

		if ( 'mobile' === $device ) {
			return $is_mobile;
		}

		if ( 'desktop' === $device ) {
			return ! $is_mobile;
		}

		return true;
	}

	/**
	 * Returns active snippets of a given type that are eligible to run
	 * in the current request context, sorted by priority (ascending).
	 *
	 * Also acts as the single defense-in-depth gate for type/code
	 * mismatches (see the inline check below) - every execution/output
	 * path (execute_php_snippet(), output_style_snippets(),
	 * output_script_snippets(), output_content_snippets()) obtains its
	 * snippet list exclusively through this method, so a mismatched
	 * snippet filtered out here can never reach any of them.
	 *
	 * @param string $type                One of SnipCore_Snippets::TYPES.
	 * @param bool   $apply_display_rules Whether to also filter by
	 *                                    passes_display_rules(). Pass
	 *                                    false when the caller will
	 *                                    immediately discard any
	 *                                    snippet that check would apply
	 *                                    to anyway (e.g. run_php_snippets()
	 *                                    at 'init', before it's safe to
	 *                                    evaluate is_singular()).
	 * @param bool   $apply_device_rules  Whether to also filter by
	 *                                    passes_device_rules(). Only
	 *                                    ever pass true for the
	 *                                    automatic frontend output
	 *                                    hooks; admin-context callers
	 *                                    must leave this false so
	 *                                    Device Display never affects
	 *                                    them.
	 * @return array
	 */
	private function get_runnable_snippets( $type, $apply_display_rules = true, $apply_device_rules = false ) {
		// Emergency kill switch: no snippet of any type is ever
		// considered runnable while active. Checked here — the single
		// choke point every execution/output path already goes
		// through — so every caller is covered by one check.
		if ( SnipCore_Safe_Mode::is_enabled() ) {
			return array();
		}

		$snippets = array_filter(
			SnipCore_Snippets::get_active(),
			function ( $snippet ) use ( $type, $apply_display_rules, $apply_device_rules ) {
				if ( ! isset( $snippet['type'] ) || $type !== $snippet['type'] ) {
					return false;
				}
				// Final defense-in-depth gate, independent of whatever
				// validation happened at save time: a snippet whose
				// type/code pairing is an obvious mismatch (legacy
				// record, pre-Phase-2 import, manual DB edit, or a
				// save-time check that was somehow bypassed) must never
				// reach eval() or be echoed into the page, regardless of
				// how it got into storage. Reuses the same rules
				// SnipCore_Snippets already applies at save time, so
				// there is exactly one place the mismatch logic lives.
				if ( '' !== SnipCore_Snippets::get_type_code_mismatch_reason( $snippet['type'], isset( $snippet['code'] ) ? $snippet['code'] : '' ) ) {
					return false;
				}
				if ( ! $this->location_matches_context( $snippet['location'] ) ) {
					return false;
				}
				// Schedule Snippet is checked unconditionally (unlike
				// Site Display / Device Display, which only apply to
				// specific callers) — a schedule window is a hard
				// gate on whether the snippet may execute at all, in
				// every context (admin, frontend, "Only Run Once").
				// Snippets with scheduling disabled always pass this
				// check, so they behave exactly as before.
				if ( ! SnipCore_Snippets::is_within_schedule( $snippet ) ) {
					return false;
				}
				if ( $apply_display_rules && ! $this->passes_display_rules( $snippet ) ) {
					return false;
				}
				return ! $apply_device_rules || $this->passes_device_rules( $snippet );
			}
		);

		usort(
			$snippets,
			static function ( $a, $b ) {
				$pa = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
				$pb = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
				return $pa <=> $pb;
			}
		);

		return $snippets;
	}

	/**
	 * If a snippet's location is "once", deactivates it immediately
	 * after execution so it never runs again.
	 *
	 * @param array $snippet Snippet record.
	 * @return void
	 */
	private function maybe_retire_once( array $snippet ) {
		if ( isset( $snippet['location'] ) && 'once' === $snippet['location'] ) {
			SnipCore_Snippets::set_status( $snippet['id'], 'inactive' );
		}
	}

	/**
	 * Executes one PHP snippet's code, isolated in its own closure so a
	 * runtime error in it cannot expose or corrupt another snippet's
	 * variables. Errors/exceptions are caught so a broken snippet
	 * cannot fatal the entire site; they are only surfaced via the PHP
	 * error log, never echoed to visitors.
	 *
	 * @param array $snippet Snippet record.
	 * @return void
	 */
	private function execute_php_snippet( array $snippet ) {
		$code = isset( $snippet['code'] ) ? (string) $snippet['code'] : '';

		if ( '' === trim( $code ) ) {
			$this->maybe_retire_once( $snippet );
			return;
		}

		// Record which snippet is about to run, with nothing else in
		// between this and eval(), so that if the very next thing
		// that happens is an uncatchable fatal error (one PHP cannot
		// deliver as a \Throwable — e.g. exhausting memory, or a
		// fatal that terminates the process before userland exception
		// handling gets a chance to run), SnipCore_Safe_Mode's
		// shutdown handler can still identify which snippet caused
		// it and disable it before the next request. See
		// class-snipcore-safe-mode.php for the full explanation.
		SnipCore_Safe_Mode::mark_running( $snippet );

		try {
			// Isolated scope: snippets are written as plain PHP
			// statements (no opening <?php tag), matching the
			// documented authoring convention for this field.
			$runner = function () use ( $code ) {
				// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- Intentional, admin-authored PHP snippet execution is this plugin's core feature; code is only ever supplied by users with the capability required to manage snippets (checked before save), run in an isolated closure, and wrapped in a Throwable catch so a broken snippet cannot fatal the site. There is no alternative to eval() for executing arbitrary admin-authored PHP at runtime.
				eval( $code );
			};
			$runner();
		} catch ( \Throwable $e ) {
			$this->log_snippet_error( $snippet, $e->getMessage() );
		}

		// Execution completed in a way PHP could still run follow-up
		// code (returned normally or threw a catchable \Throwable),
		// so this was not an uncatchable fatal — clear the marker
		// immediately rather than waiting for shutdown, so the
		// shutdown handler has nothing to act on for this snippet.
		SnipCore_Safe_Mode::clear_running();

		$this->maybe_retire_once( $snippet );
	}

	/**
	 * Runs eligible PHP ("functions") snippets that do NOT need the
	 * main query to decide whether they run: admin-context snippets,
	 * and "Only Run Once" snippets (which fire regardless of page).
	 * These keep running at the same 'init' timing SnipCore has always
	 * used for PHP snippets, unchanged by Site Display.
	 *
	 * Frontend/everywhere-location snippets are deliberately excluded
	 * here and handled instead by run_php_snippets_frontend() — Site
	 * Display's "Specific"/"Exclude" targeting depends on conditional
	 * tags like is_singular() and get_the_ID(), which are not reliable
	 * yet this early (the main query isn't parsed until the 'wp'
	 * hook). Snippets with no display restriction ('all') would run
	 * correctly at either time, but are still deferred to keep all
	 * frontend PHP snippets on one single, consistent execution point
	 * rather than splitting by whether each one happens to use Site
	 * Display today.
	 *
	 * @return void
	 */
	public function run_php_snippets() {
		// Display rules are skipped here (they're a no-op for the
		// admin/once snippets this loop actually executes, and
		// evaluating is_singular() this early is unreliable — see the
		// docblock above) and are applied properly in
		// run_php_snippets_frontend() instead for anything they'd
		// actually affect.
		foreach ( $this->get_runnable_snippets( 'functions', false ) as $snippet ) {
			$location = isset( $snippet['location'] ) ? $snippet['location'] : 'everywhere';

			if ( is_admin() || 'once' === $location ) {
				$this->execute_php_snippet( $snippet );
			}
		}
	}

	/**
	 * Runs eligible frontend PHP ("functions") snippets once the main
	 * query is available, so Site Display's is_singular()/get_the_ID()
	 * checks inside passes_display_rules() are accurate. Never fires
	 * in wp-admin — admin-context PHP snippets already ran via
	 * run_php_snippets() on 'init', unaffected by this.
	 *
	 * @return void
	 */
	public function run_php_snippets_frontend() {
		if ( is_admin() ) {
			return;
		}

		foreach ( $this->get_runnable_snippets( 'functions', true, true ) as $snippet ) {
			$location = isset( $snippet['location'] ) ? $snippet['location'] : 'everywhere';

			// 'admin' can't reach here (get_runnable_snippets() already
			// filtered by location_matches_context(), and 'admin'
			// never matches outside is_admin()); 'once' already ran
			// via run_php_snippets() above. What's left is exactly
			// frontend/everywhere.
			if ( 'once' !== $location ) {
				$this->execute_php_snippet( $snippet );
			}
		}
	}

	/**
	 * Outputs CSS snippets, wrapped in a <style> tag.
	 *
	 * @param array $snippets Snippets to render.
	 * @return void
	 */
	private function output_style_snippets( array $snippets ) {
		foreach ( $snippets as $snippet ) {
			$code = isset( $snippet['code'] ) ? trim( (string) $snippet['code'] ) : '';
			if ( '' === $code ) {
				$this->maybe_retire_once( $snippet );
				continue;
			}
			printf(
				'<style id="snipcore-style-%s">%s</style>' . "\n",
				esc_attr( $snippet['id'] ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSS output inside a <style> element; wp_strip_all_tags() removes any stray markup/script vectors, and esc_html() is not usable here because it would HTML-entity-encode legitimate CSS syntax (quotes, ampersands, angle brackets in selectors/values) and break the stylesheet.
				wp_strip_all_tags( $code ) // Strip any stray markup; only CSS is expected here.
			);
			$this->maybe_retire_once( $snippet );
		}
	}

	/**
	 * Outputs JS snippets, wrapped in a <script> tag.
	 *
	 * @param array $snippets Snippets to render.
	 * @return void
	 */
	private function output_script_snippets( array $snippets ) {
		foreach ( $snippets as $snippet ) {
			$code = isset( $snippet['code'] ) ? trim( (string) $snippet['code'] ) : '';
			if ( '' === $code ) {
				$this->maybe_retire_once( $snippet );
				continue;
			}
			printf(
				'<script id="snipcore-script-%s">%s</script>' . "\n",
				esc_attr( $snippet['id'] ),
				$code // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-authored JS is output verbatim by design.
			);
			$this->maybe_retire_once( $snippet );
		}
	}

	/**
	 * Outputs HTML ("content") snippets verbatim.
	 *
	 * @param array $snippets Snippets to render.
	 * @return void
	 */
	private function output_content_snippets( array $snippets ) {
		foreach ( $snippets as $snippet ) {
			$code = isset( $snippet['code'] ) ? trim( (string) $snippet['code'] ) : '';
			if ( '' === $code ) {
				$this->maybe_retire_once( $snippet );
				continue;
			}
			echo $code . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin-authored HTML is output verbatim by design.
			$this->maybe_retire_once( $snippet );
		}
	}

	/**
	 * Renders on the frontend <head>: CSS snippets only.
	 *
	 * @return void
	 */
	public function output_frontend_head() {
		if ( is_admin() ) {
			return;
		}
		$this->output_style_snippets( $this->get_runnable_snippets( 'style', true, true ) );
	}

	/**
	 * Renders before the frontend closing </body>: HTML and JS snippets.
	 *
	 * @return void
	 */
	public function output_frontend_footer() {
		if ( is_admin() ) {
			return;
		}
		$this->output_content_snippets( $this->get_runnable_snippets( 'content', true, true ) );
		$this->output_script_snippets( $this->get_runnable_snippets( 'scripts', true, true ) );
	}

	/**
	 * Renders in wp-admin's <head>: CSS snippets only.
	 *
	 * @return void
	 */
	public function output_admin_head() {
		if ( ! is_admin() ) {
			return;
		}
		$this->output_style_snippets( $this->get_runnable_snippets( 'style' ) );
	}

	/**
	 * Renders before wp-admin's closing </body>: HTML and JS snippets.
	 *
	 * @return void
	 */
	public function output_admin_footer() {
		if ( ! is_admin() ) {
			return;
		}
		$this->output_content_snippets( $this->get_runnable_snippets( 'content' ) );
		$this->output_script_snippets( $this->get_runnable_snippets( 'scripts' ) );
	}

	/**
	 * Determines whether the current request is a normal, cacheable
	 * frontend page view that it is safe to wrap in an output buffer
	 * for Global Header & Footer injection.
	 *
	 * Deliberately conservative: anything that isn't a full HTML
	 * document response — admin-ajax, REST API, XML-RPC, WP-Cron, a
	 * feed, robots.txt, or a trackback — is left completely
	 * untouched, so Global Header & Footer can never corrupt a JSON
	 * payload, an XML feed, or any other non-HTML output.
	 *
	 * @return bool
	 */
	private function is_safe_frontend_document_request() {

		if ( is_admin() ) {
			return false;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}

		if ( is_feed() || is_robots() || is_trackback() ) {
			return false;
		}

		return true;
	}

	/**
	 * Starts an output buffer for the current frontend page, so the
	 * Global Header & Footer fields can be spliced directly into the
	 * final rendered HTML (see inject_global_header_footer()). Only
	 * buffers when there is actually something configured to inject,
	 * so a site that isn't using this feature pays no output-buffer
	 * overhead at all.
	 *
	 * @return void
	 */
	public function maybe_start_global_output_buffer() {

		if ( SnipCore_Safe_Mode::is_enabled() ) {
			return;
		}

		if ( ! $this->is_safe_frontend_document_request() ) {
			return;
		}

		$fields = SnipCore_Header_Footer::get_all();

		if ( '' === trim( (string) $fields['header'] )
			&& '' === trim( (string) $fields['body'] )
			&& '' === trim( (string) $fields['footer'] ) ) {
			return;
		}

		ob_start( array( $this, 'inject_global_header_footer' ) );
	}

	/**
	 * Output buffer callback: splices the Global Header, Body, and
	 * Footer fields into the fully rendered page HTML.
	 *
	 * - Header is inserted immediately before the first `</head>`.
	 * - Body is inserted immediately after the opening `<body ...>` tag.
	 * - Footer is inserted immediately before the last `</body>`.
	 *
	 * Any field whose target tag isn't found in the buffer (e.g. a
	 * non-standard or partial-HTML response that slipped past
	 * is_safe_frontend_document_request()) is simply skipped for that
	 * field rather than guessed at, and the whole operation is
	 * wrapped in a try/catch so a malformed page can never be
	 * corrupted or fataled by this feature — on any error, the
	 * original, unmodified buffer is returned untouched.
	 *
	 * @param string $buffer Fully rendered page output.
	 * @return string
	 */
	public function inject_global_header_footer( $buffer ) {
		try {
			return $this->splice_global_header_footer( (string) $buffer );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'SnipCore: error injecting Global Header & Footer: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, gated behind WP_DEBUG.
			}
			return $buffer;
		}
	}

	/**
	 * Performs the actual splicing described in
	 * inject_global_header_footer(). Split into its own method so the
	 * try/catch wrapper above stays focused purely on error handling.
	 *
	 * @param string $buffer Fully rendered page output.
	 * @return string
	 */
	private function splice_global_header_footer( $buffer ) {

		if ( '' === $buffer ) {
			return $buffer;
		}

		$fields = SnipCore_Header_Footer::get_all();
		$header = trim( (string) $fields['header'] );
		$body   = trim( (string) $fields['body'] );
		$footer = trim( (string) $fields['footer'] );

		if ( '' !== $header ) {
			$replaced = preg_replace_callback(
				'/<\/head\s*>/i',
				static function ( $matches ) use ( $header ) {
					return $header . "\n" . $matches[0];
				},
				$buffer,
				1
			);
			if ( null !== $replaced ) {
				$buffer = $replaced;
			}
		}

		if ( '' !== $body && preg_match( '/<body\b[^>]*>/i', $buffer, $body_match, PREG_OFFSET_CAPTURE ) ) {
			$insert_at = $body_match[0][1] + strlen( $body_match[0][0] );
			$buffer    = substr( $buffer, 0, $insert_at ) . "\n" . $body . substr( $buffer, $insert_at );
		}

		if ( '' !== $footer ) {
			$closing_body_pos = strripos( $buffer, '</body>' );
			if ( false !== $closing_body_pos ) {
				$buffer = substr( $buffer, 0, $closing_body_pos ) . $footer . "\n" . substr( $buffer, $closing_body_pos );
			}
		}

		return $buffer;
	}

	/**
	 * Logs a snippet execution error without exposing details to visitors.
	 *
	 * @param array  $snippet Snippet record.
	 * @param string $message Error message.
	 * @return void
	 */
	private function log_snippet_error( array $snippet, $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional, gated behind WP_DEBUG.
				sprintf(
					'SnipCore: error executing snippet "%s" (%s): %s',
					isset( $snippet['name'] ) ? $snippet['name'] : '',
					isset( $snippet['id'] ) ? $snippet['id'] : '',
					$message
				)
			);
		}
	}
}
