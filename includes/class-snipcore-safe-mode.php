<?php
/**
 * Snippet Error Recovery / Safe Mode.
 *
 * Protects the site from a faulty PHP snippet taking it down
 * permanently. There are two independent layers:
 *
 * 1. Crash recovery: immediately before a PHP snippet is eval()'d, a
 *    small "currently executing" marker naming that snippet is
 *    persisted to the database. If the snippet triggers a true fatal
 *    error (the kind PHP cannot deliver as a catchable \Throwable —
 *    e.g. exhausting memory, calling exit()/die(), a fatal error in
 *    code that itself can't be wrapped, or an uncaught fatal that
 *    still manages to terminate the process), normal PHP execution
 *    stops immediately and any try/catch around the eval() never gets
 *    a chance to run. What *does* still run in that situation is a
 *    PHP shutdown function, which this class registers once per
 *    request. On shutdown, if the marker is still present (meaning
 *    execute_php_snippet() never reached its own cleanup) and
 *    error_get_last() reports a fatal-class error, that snippet is
 *    identified as the culprit and is automatically deactivated
 *    before the *next* request runs — the current, already-broken
 *    response cannot be salvaged, but every subsequent request is
 *    protected.
 *
 * 2. Emergency kill switch: a single boolean option
 *    (snipcore_safe_mode) that, when enabled, skips ALL snippet
 *    execution/output (PHP, CSS, JS, HTML, and Global Header/Footer)
 *    without touching any snippet's stored status or code. It can be
 *    toggled from a small, dependency-free admin page that registers
 *    on plain admin_menu/admin_init with no reliance on the rest of
 *    SnipCore's admin UI, and can additionally be toggled by defining
 *    SNIPCORE_SAFE_MODE in wp-config.php — a path that works even if
 *    a faulty snippet has made wp-admin itself unreachable, since
 *    wp-config.php loads before any plugin code.
 *
 * Both mechanisms are checked with at most one lightweight
 * get_option() call each; SnipCore_Snippets' own request-scoped cache
 * means neither adds a query beyond what the executor already does.
 *
 * @package SnipCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SnipCore_Safe_Mode
 */
class SnipCore_Safe_Mode {

	/**
	 * Option storing the emergency "disable all snippets" switch.
	 * Autoload is disabled intentionally elsewhere (see enable()) —
	 * this is genuinely needed on every request, so unlike the crash
	 * marker it is allowed to autoload for speed rather than being
	 * fetched with a dedicated query.
	 *
	 * @var string
	 */
	const KILL_SWITCH_OPTION = 'snipcore_safe_mode';

	/**
	 * Option marking which snippet is currently mid-execution. Only
	 * ever set for the brief window between "about to eval()" and
	 * "eval() returned/threw", so under normal operation it exists in
	 * the database for a fraction of a millisecond before being
	 * deleted again. Autoload disabled: it is only ever read inside
	 * the shutdown handler, which uses get_option() directly, never
	 * on the hot path of a normal request.
	 *
	 * @var string
	 */
	const RUNNING_MARKER_OPTION = 'snipcore_snippet_running';

	/**
	 * Option storing details of the most recently auto-disabled
	 * snippet, surfaced as an admin notice. Cleared once the notice
	 * has been dismissed/acknowledged.
	 *
	 * @var string
	 */
	const LAST_ERROR_OPTION = 'snipcore_last_snippet_error';

	/**
	 * Guards against the shutdown handler doing its work more than
	 * once (register_shutdown_function callbacks can in rare
	 * multi-buffer scenarios still only fire once, but this also
	 * protects against double-init of this class within one request).
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

	/**
	 * Error types PHP considers fatal — i.e. the categories a
	 * try/catch around eval() cannot be relied on to intercept,
	 * because the engine tears down execution before userland
	 * exception handling can run for them. Warnings/notices/deprecations
	 * are deliberately excluded: a snippet that merely triggers a
	 * PHP warning is not "faulty" in the sense this system cares
	 * about, and must not be auto-deactivated over it.
	 *
	 * @var int[]
	 */
	const FATAL_ERROR_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	/**
	 * Registers the shutdown handler. Safe to call on every request
	 * (frontend and admin) — the handler itself is cheap (one
	 * get_option() call) and only ever does further work on the rare
	 * request where a marker was left behind by a fatal error.
	 *
	 * @return void
	 */
	public function init() {
		if ( self::$shutdown_registered ) {
			return;
		}
		self::$shutdown_registered = true;
		register_shutdown_function( array( $this, 'handle_shutdown' ) );
	}

	/**
	 * Whether the emergency "disable all snippets" switch is active,
	 * either via the stored option or the wp-config.php constant
	 * override. Checked by the executor before doing anything else,
	 * so when active, execution/output of every snippet type (and
	 * Global Header & Footer) is skipped in a single cheap check.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( defined( 'SNIPCORE_SAFE_MODE' ) && SNIPCORE_SAFE_MODE ) {
			return true;
		}
		return (bool) get_option( self::KILL_SWITCH_OPTION, false );
	}

	/**
	 * Turns the emergency kill switch on.
	 *
	 * @return void
	 */
	public static function enable() {
		update_option( self::KILL_SWITCH_OPTION, true );
	}

	/**
	 * Turns the emergency kill switch off.
	 *
	 * @return void
	 */
	public static function disable() {
		update_option( self::KILL_SWITCH_OPTION, false );
	}

	/**
	 * Marks a PHP snippet as "about to execute". Called immediately
	 * before eval(), with nothing else in between, so that if the
	 * very next thing that happens is an uncatchable fatal, the
	 * shutdown handler can still identify which snippet was running.
	 *
	 * A single small option write per PHP-snippet execution is an
	 * intentional, deliberate cost of this safety net — there is no
	 * way to know a snippet is about to fatal without recording
	 * something before it runs, and it is scoped to PHP ("functions")
	 * snippets only, never CSS/JS/HTML, which cannot fatal the
	 * process the way eval'd PHP can.
	 *
	 * @param array $snippet Snippet record about to be executed.
	 * @return void
	 */
	public static function mark_running( array $snippet ) {
		update_option(
			self::RUNNING_MARKER_OPTION,
			array(
				'id'   => isset( $snippet['id'] ) ? $snippet['id'] : '',
				'name' => isset( $snippet['name'] ) ? $snippet['name'] : '',
			),
			false // Do not autoload; only ever read explicitly in handle_shutdown().
		);
	}

	/**
	 * Clears the "about to execute" marker. Called right after eval()
	 * returns or throws a catchable \Throwable — i.e. whenever
	 * execution of the snippet completed in a way PHP could still run
	 * follow-up code, meaning no uncatchable fatal happened.
	 *
	 * @return void
	 */
	public static function clear_running() {
		delete_option( self::RUNNING_MARKER_OPTION );
	}

	/**
	 * Shutdown handler. Runs at the end of every single request, but
	 * only ever performs meaningful work on the rare request where a
	 * PHP snippet was mid-execution (per mark_running()) when the
	 * request ended and the last PHP error was fatal — the exact
	 * signature of an uncatchable fatal error inside a snippet.
	 *
	 * Deliberately avoids anything that could itself error inside a
	 * shutdown handler (no snippet code runs here, no complex object
	 * graph, only plain option reads/writes) to avoid recursive error
	 * handling or a second fatal while handling the first.
	 *
	 * @return void
	 */
	public function handle_shutdown() {
		// Cheapest possible check first: if no marker is set, a
		// snippet either finished cleanly or none was running, and we
		// can return without looking at the error at all.
		$running = get_option( self::RUNNING_MARKER_OPTION, false );

		if ( empty( $running ) || ! is_array( $running ) || empty( $running['id'] ) ) {
			return;
		}

		$error = error_get_last();

		// The marker survived to shutdown but there is no fatal-class
		// error on record: not the scenario this handler exists for
		// (e.g. a well-behaved eval() call somehow didn't reach its
		// own clear_running(), which shouldn't normally happen, but
		// erring toward not touching the snippet is the safe default
		// when there's no actual evidence of a fatal).
		if ( ! is_array( $error ) || ! isset( $error['type'] ) || ! in_array( $error['type'], self::FATAL_ERROR_TYPES, true ) ) {
			// Still clear the stale marker so it can't wrongly persist
			// into a future request.
			delete_option( self::RUNNING_MARKER_OPTION );
			return;
		}

		// Clear the marker before doing anything else, so that if
		// deactivating the snippet or writing the error log somehow
		// fails, the marker itself can never be misread as "still
		// running" on the next request.
		delete_option( self::RUNNING_MARKER_OPTION );

		$this->disable_faulty_snippet( $running['id'], $running['name'], $error );
	}

	/**
	 * Deactivates the snippet identified as the cause of a fatal
	 * error and records details for the admin notice. Only ever
	 * changes the snippet's 'status' to 'inactive' — code, name, and
	 * every other field are left completely untouched, so the
	 * snippet remains saved and can be fixed and manually
	 * re-enabled.
	 *
	 * @param string $snippet_id   Snippet ID.
	 * @param string $snippet_name Snippet name, for the admin notice.
	 * @param array  $error        The error_get_last() array.
	 * @return void
	 */
	private function disable_faulty_snippet( $snippet_id, $snippet_name, array $error ) {
		// SnipCore_Snippets is always loaded by this point (the
		// executor that called mark_running() requires it), but guard
		// anyway since shutdown functions run in a context where
		// almost anything could theoretically already be torn down.
		if ( ! class_exists( 'SnipCore_Snippets' ) ) {
			return;
		}

		$snippet = SnipCore_Snippets::get( $snippet_id );

		// Already inactive or gone (e.g. deleted between requests):
		// nothing to disable, but still record the error for
		// visibility if the snippet still exists.
		$already_inactive = ( null === $snippet || 'active' !== $snippet['status'] );

		if ( ! $already_inactive ) {
			SnipCore_Snippets::set_status( $snippet_id, 'inactive' );
		}

		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'id'      => $snippet_id,
				'name'    => $snippet_name,
				'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				'file'    => isset( $error['file'] ) ? (string) $error['file'] : '',
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
				'time'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);
	}

	/**
	 * Returns the details of the most recently auto-disabled snippet,
	 * or null if there is none on record.
	 *
	 * @return array|null
	 */
	public static function get_last_error() {
		$data = get_option( self::LAST_ERROR_OPTION, false );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clears the recorded auto-disable error, e.g. once the admin has
	 * seen and dismissed the notice.
	 *
	 * @return void
	 */
	public static function clear_last_error() {
		delete_option( self::LAST_ERROR_OPTION );
	}
}
