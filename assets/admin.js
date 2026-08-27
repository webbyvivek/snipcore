/**
 * SnipCore admin scripts.
 *
 * Enqueued only on SnipCore's own admin screens (All Snippets,
 * Add/Edit Snippet, Global Header & Footer, Settings) — see
 * SnipCore_Admin::enqueue_assets(). Extracted verbatim from the
 * former SnipCore_Admin::get_inline_js() inline string; no logic
 * was added, removed, or changed. Runtime values (AJAX URL, nonce,
 * translated strings) are supplied separately via the localized
 * `snipcoreAdmin` object (wp_localize_script()), unaffected by
 * this extraction.
 */
jQuery( function( $ ) {

	// Keeps the ' | ' separators in the status-filter row
	// (All | Activate | Deactivate | Trashed) in sync with
	// whichever filters are currently visible, since a
	// filter can be hidden/shown live as counts change.
	function snipcoreRefreshStatusSeparators() {
		var $items = $( '.snipcore-status-filters > li' ).not( '.snipcore-hidden' );
		$( '.snipcore-status-filters > li .snipcore-status-filter-sep' ).hide();
		$items.not( ':last' ).find( '.snipcore-status-filter-sep' ).show();
	}
	snipcoreRefreshStatusSeparators();

	// Adjusts one status filter's count in place (All,
	// Activate, Deactivate, or Trashed) — used to keep the
	// filter row's counts live when a snippet's activation
	// state changes, without a page reload.
	function snipcoreAdjustStatusCount( slug, delta ) {
		var $li = $( '.snipcore-status-filters > li[data-status="' + slug + '"]' );
		if ( ! $li.length || ! delta ) {
			return;
		}
		var count = Math.max( 0, ( parseInt( $li.attr( 'data-count' ), 10 ) || 0 ) + delta );
		$li.attr( 'data-count', count );
		$li.find( '.count' ).text( '(' + count.toLocaleString() + ')' );
		if ( 'all' !== slug ) {
			$li.toggleClass( 'snipcore-hidden', 0 === count );
		}
		snipcoreRefreshStatusSeparators();
	}

	// If the table has no visible rows left (every snippet
	// was filtered out of the current Activate/Deactivate
	// view), show a lightweight placeholder row instead of
	// leaving an empty table body.
	function snipcoreRefreshEmptyState() {
		var $table = $( '.snipcore-snippets-table' );
		var $tbody = $table.find( 'tbody' );
		var hasRows = $tbody.find( 'tr' ).not( '.snipcore-js-empty-row' ).filter( ':visible' ).length > 0;

		$tbody.find( '.snipcore-js-empty-row' ).remove();

		if ( ! hasRows ) {
			var colCount = $table.find( 'thead th, thead td' ).length;
			$tbody.append(
				$( '<tr class="no-items snipcore-js-empty-row"></tr>' ).append(
					$( '<td class="colspanchange snipcore-empty-state"></td>' )
						.attr( 'colspan', colCount )
						.append( $( '<p class="snipcore-empty-state-message"></p>' ).text( snipcoreAdmin.noMatchesLabel ) )
				)
			);
		}
	}

	// Reverts a toggle (and everything it optimistically
	// changed) after a failed save.
	function snipcoreRevertToggle( $toggle, $row, requestedActive, rowLeftView ) {
		$toggle.prop( 'checked', ! requestedActive );
		$row.toggleClass( 'snipcore-row-active', ! requestedActive );
		$row.toggleClass( 'snipcore-row-inactive', requestedActive );

		snipcoreAdjustStatusCount( 'activate', requestedActive ? -1 : 1 );
		snipcoreAdjustStatusCount( 'deactivate', requestedActive ? 1 : -1 );

		if ( rowLeftView ) {
			$row.stop( true, true ).fadeIn( 150 );
			$( '.snipcore-snippets-table tbody .snipcore-js-empty-row' ).remove();
		}
	}

	$( document ).on( 'change', '.snipcore-toggle', function () {
		var $toggle = $( this );
		var id = $toggle.data( 'id' );
		var $row  = $toggle.closest( 'tr' );
		var requestedActive = $toggle.is( ':checked' );

		// Update instantly: flip the row's active/inactive
		// styling right away instead of waiting on the
		// network round-trip, so the switch itself is the
		// only thing that ever needs to move. No spinner,
		// no disabling, no dimming overlay — if the request
		// fails, everything optimistically changed here is
		// simply reverted below.
		$row.toggleClass( 'snipcore-row-active', requestedActive );
		$row.toggleClass( 'snipcore-row-inactive', ! requestedActive );

		snipcoreAdjustStatusCount( 'activate', requestedActive ? 1 : -1 );
		snipcoreAdjustStatusCount( 'deactivate', requestedActive ? -1 : 1 );

		// If we're currently viewing the Activate or
		// Deactivate filter and this snippet just moved to
		// the other state, it no longer belongs in view —
		// fade it out (not remove — it's brought straight
		// back with fadeIn if the save turns out to fail).
		var currentStatus = new URLSearchParams( window.location.search ).get( 'status' ) || 'all';
		var rowLeavesView = ( 'activate' === currentStatus && ! requestedActive ) || ( 'deactivate' === currentStatus && requestedActive );

		if ( rowLeavesView ) {
			$row.stop( true, true ).fadeOut( 150, function () {
				snipcoreRefreshEmptyState();
			} );
		}

		$.post( snipcoreAdmin.ajaxUrl, {
			action: 'snipcore_toggle_status',
			nonce: snipcoreAdmin.nonce,
			id: id,
			status: requestedActive ? 'active' : 'inactive'
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				snipcoreRevertToggle( $toggle, $row, requestedActive, rowLeavesView );
			}
		} ).fail( function () {
			snipcoreRevertToggle( $toggle, $row, requestedActive, rowLeavesView );
		} );
	} );

	// Add/Edit Snippet: keep the Active/Inactive label in sync
	// with the toggle the moment it's clicked, so the state
	// stays visually obvious without needing a save/reload.
	$( document ).on( 'change', '.snipcore-status-toggle', function () {
		var $toggle = $( this );
		var $text   = $( '#snipcore-status-toggle-text' );
		$text.text( $toggle.is( ':checked' ) ? snipcoreAdmin.activeLabel : snipcoreAdmin.inactiveLabel );
	} );

	// Complete Uninstall is destructive and irreversible once the
	// plugin is removed, so require an explicit confirmation
	// before the checkbox can be checked. Unchecking it (turning
	// the safeguard back on) never needs confirmation.
	$( document ).on( 'change', '.snipcore-complete-uninstall-toggle', function () {
		var $toggle = $( this );

		if ( $toggle.is( ':checked' ) ) {
			var message = $toggle.data( 'confirm' );
			if ( ! window.confirm( message ) ) {
				$toggle.prop( 'checked', false );
			}
		}
	} );

	// All Snippets list: changing the 'items per page'
	// selector navigates (GET) straight to the same list
	// view with the new page size applied and the page
	// number reset to 1. This is a plain navigation, not a
	// form submission — the selector deliberately isn't
	// wrapped in its own <form> (nesting a <form> inside
	// #snipcore-bulk-form is invalid HTML and gets merged
	// into the outer POST form by the browser, which used
	// to route this through the bulk-action submit handler
	// below and trigger its "select a bulk action" guard).
	// Keeping this as an independent navigation means
	// pagination/per-page never touches bulk-action
	// validation at all, in either direction.
	$( document ).on( 'change', '.snipcore-per-page-select', function () {
		var base = $( this ).data( 'base-url' );
		var val  = $( this ).val();
		if ( ! base ) {
			return;
		}
		var url = new URL( base, window.location.href );
		url.searchParams.set( 'snipcore_per_page', val );
		url.searchParams.delete( 'paged' );
		window.location.href = url.toString();
	} );

	// All Snippets list: keep the header/footer 'select all'
	// checkboxes and every row checkbox in sync with each
	// other, in both directions, the same way core's list
	// tables do.
	$( document ).on( 'change', '.snipcore-bulk-select-all', function () {
		var checked = $( this ).is( ':checked' );
		$( '.snipcore-bulk-select-all, .snipcore-bulk-item' ).prop( 'checked', checked );
	} );
	$( document ).on( 'change', '.snipcore-bulk-item', function () {
		var total   = $( '.snipcore-bulk-item' ).length;
		var checked = $( '.snipcore-bulk-item:checked' ).length;
		$( '.snipcore-bulk-select-all' ).prop( 'checked', total > 0 && total === checked );
	} );

	// Require a chosen action and at least one selected
	// snippet before the bulk form can submit; Trash is
	// destructive, so it additionally requires an explicit
	// confirmation. Whichever of the top/bottom 'Apply'
	// buttons was clicked determines which action dropdown
	// is authoritative, since the two are not kept in sync.
	$( document ).on( 'click', '.snipcore-bulk-apply', function () {
		$( this ).closest( 'form' ).data( 'snipcoreClickedAction', $( this ).closest( '.snipcore-bulk-actions' ).find( '.snipcore-bulk-action-select' ).val() );
	} );
	$( document ).on( 'submit', '#snipcore-bulk-form', function ( e ) {
		var $form        = $( this );
		var bulkAction    = $form.data( 'snipcoreClickedAction' );
		var selectedCount = $( '.snipcore-bulk-item:checked' ).length;

		if ( ! bulkAction || '-1' === bulkAction ) {
			window.alert( snipcoreAdmin.selectABulkAction );
			e.preventDefault();
			return;
		}
		if ( 0 === selectedCount ) {
			window.alert( snipcoreAdmin.selectOneOrMore );
			e.preventDefault();
			return;
		}
		if ( 'trash' === bulkAction && ! window.confirm( snipcoreAdmin.confirmBulkTrash ) ) {
			e.preventDefault();
			return;
		}
		if ( 'delete' === bulkAction && ! window.confirm( snipcoreAdmin.confirmBulkDelete ) ) {
			e.preventDefault();
			return;
		}

		$form.find( 'input[name="snipcore_bulk_action"]' ).val( bulkAction );

		// Passed validation and is actually submitting now:
		// disable both Apply buttons so a second click (or
		// the slow round trip on a large selection) can't
		// fire the same bulk action twice.
		$form.find( '.snipcore-bulk-apply' )
			.prop( 'disabled', true )
			.addClass( 'snipcore-is-busy' )
			.text( snipcoreAdmin.processingLabel );
	} );

	// Import/Export tab: 'select all' checkboxes for the
	// export snippet list and the import preview table.
	function snipcoreUpdateExportButton() {
		var total    = $( '.snipcore-export-item' ).length;
		var checked  = $( '.snipcore-export-item:checked' ).length;
		var $button = $( '#snipcore-export-selected-button' );

		if ( ! $button.length ) {
			return;
		}

		$button.prop( 'disabled', 0 === checked );

		if ( 0 === checked ) {
			$button.text( $button.data( 'labelDefault' ) );
		} else if ( checked === total ) {
			$button.text( $button.data( 'labelAll' ).replace( '%d', checked ) );
		} else {
			$button.text( $button.data( 'labelSome' ).replace( '%d', checked ) );
		}
	}
	$( document ).on( 'change', '#snipcore-export-select-all', function () {
		$( '.snipcore-export-item' ).prop( 'checked', $( this ).is( ':checked' ) );
		snipcoreUpdateExportButton();
	} );
	$( document ).on( 'change', '.snipcore-export-item', function () {
		var total   = $( '.snipcore-export-item' ).length;
		var checked = $( '.snipcore-export-item:checked' ).length;
		$( '#snipcore-export-select-all' ).prop( 'checked', total === checked );
		snipcoreUpdateExportButton();
	} );
	snipcoreUpdateExportButton();
	$( document ).on( 'submit', '#snipcore-export-form', function ( e ) {
		if ( 0 === $( '.snipcore-export-item:checked' ).length ) {
			window.alert( snipcoreAdmin.selectAtLeastOne );
			e.preventDefault();
		}
	} );

	$( document ).on( 'change', '#snipcore-import-select-all', function () {
		$( '.snipcore-import-item' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );
	$( document ).on( 'change', '.snipcore-import-item', function () {
		var total   = $( '.snipcore-import-item' ).length;
		var checked = $( '.snipcore-import-item:checked' ).length;
		$( '#snipcore-import-select-all' ).prop( 'checked', total === checked );
	} );

	// Ticking "Import as new copy" on a duplicate row implies
	// the admin wants that row included; keep its row
	// checkbox in sync so they don't have to tick both.
	// Unticking it does NOT auto-uncheck the row, since the
	// admin may still want it selected (it will simply be
	// skipped server-side unless renamed — see
	// handle_import_confirm()).
	$( document ).on( 'change', '.snipcore-import-rename', function () {
		if ( $( this ).is( ':checked' ) ) {
			$( this ).closest( 'tr' ).find( '.snipcore-import-item' ).prop( 'checked', true ).trigger( 'change' );
		}
	} );

	// Import upload dropzone: purely visual drag-over feedback.
	// The native file input already sits on top of the whole
	// area and handles both click-to-browse and drag/drop of
	// files itself, so no functional wiring is needed here.
	$( document ).on( 'dragenter dragover', '#snipcore-import-dropzone', function ( e ) {
		e.preventDefault();
		$( this ).addClass( 'snipcore-io-upload-dragover' );
	} );
	$( document ).on( 'dragleave drop', '#snipcore-import-dropzone', function ( e ) {
		e.preventDefault();
		$( this ).removeClass( 'snipcore-io-upload-dragover' );
	} );

	// Import upload form: list exactly which files were
	// chosen (the native "3 files selected" summary some
	// browsers show isn't enough to know *which* files),
	// flagging anything that isn't .json/.xml so a mistaken
	// selection is obvious before the round trip — the
	// actual accept/type/size check still happens
	// server-side in handle_import_upload().
	$( document ).on( 'change', '#snipcore-import-file-input', function () {
		var $list = $( '#snipcore-import-file-list' ).empty();
		var files = this.files || [];
		$.each( files, function ( i, file ) {
			var isValid = /\.(json|xml)$/i.test( file.name );
			var $item  = $( '<li></li>' ).text( file.name );
			if ( ! isValid ) {
				$item.addClass( 'snipcore-import-file-invalid' )
					.append( $( '<span></span>' ).text( ' — ' + snipcoreAdmin.unsupportedFileType ) );
			}
			$list.append( $item );
		} );
	} );

	// Import preview: require at least one row checked
	// before letting "Confirm Import" submit, same courtesy
	// the Export form already gives.
	$( document ).on( 'submit', '#snipcore-import-confirm-form', function ( e ) {
		if ( 0 === $( '.snipcore-import-item:checked' ).length ) {
			window.alert( snipcoreAdmin.selectAtLeastOneImport );
			e.preventDefault();
		}
	} );

	// Site Display: the toggle directly represents whether
	// Pages & Posts targeting is on. Off = Entire Website
	// (display_mode is set to all), and the Specific/Exclude
	// segmented buttons plus the checkbox list are hidden
	// entirely. On = the segmented buttons and list show, and
	// the picked segmented value (specific/exclude) is written
	// into the hidden display_mode field that the existing
	// backend logic reads unchanged.
	function snipcoreToggleSiteDisplay() {
		var isTargeted = $( '#snipcore-display-mode-all' ).is( ':checked' );
		var $wrap      = $( '#snipcore-display-targeting-wrap' );

		$wrap.toggleClass( 'snipcore-hidden', ! isTargeted );
		$wrap.find( 'input[type="checkbox"], input[type="radio"]' ).prop( 'disabled', ! isTargeted );

		if ( isTargeted ) {
			$( '#snipcore-display-mode-field' ).val( $( 'input[name="display_sub_mode"]:checked' ).val() || 'specific' );
		} else {
			$( '#snipcore-display-mode-field' ).val( 'all' );
		}
	}
	$( document ).on( 'change', '#snipcore-display-mode-all, input[name="display_sub_mode"]', snipcoreToggleSiteDisplay );
	if ( $( '#snipcore-display-mode-all' ).length ) {
		snipcoreToggleSiteDisplay();
	}

	// Same idea for the Schedule Snippet start/end fields:
	// only show them once the restriction is turned on.
	function snipcoreToggleScheduleFields() {
		$( '#snipcore-schedule-fields-wrap' ).toggleClass( 'snipcore-hidden', ! $( '#snipcore-schedule-enabled' ).is( ':checked' ) );
	}
	$( document ).on( 'change', '#snipcore-schedule-enabled', snipcoreToggleScheduleFields );
	if ( $( '#snipcore-schedule-enabled' ).length ) {
		snipcoreToggleScheduleFields();
	}

	// Settings > General: 'Description Editor Height' only
	// has any effect while 'Snippet Descriptions' is on, so
	// dim its row (rather than hide it, since it lives in
	// its own Settings API table row) whenever that is off.
	// The field is never actually disabled, so its saved
	// value is preserved even while dimmed; this is purely
	// a visual cue that it currently does nothing.
	function snipcoreToggleDescriptionHeight() {
		var enabled = $( '#snipcore-enable-descriptions' ).is( ':checked' );
		$( '#snipcore-description-height-wrap' ).toggleClass( 'snipcore-field-row-disabled', ! enabled );
	}
	$( document ).on( 'change', '#snipcore-enable-descriptions', snipcoreToggleDescriptionHeight );
	if ( $( '#snipcore-enable-descriptions' ).length ) {
		snipcoreToggleDescriptionHeight();
	}

	// Generic submit-once guard for the plugin's full-page
	// POST forms (save snippet, header/footer, export
	// selected): bound last so any form-specific validation
	// above (e.g. the export form's 'select at least one'
	// check) has already had a chance to preventDefault();
	// only a submission that actually proceeds disables the
	// buttons. Purely cosmetic — every handler already
	// re-validates server-side regardless.
	$( document ).on( 'submit', '.snipcore-loading-on-submit', function ( e ) {
		if ( e.isDefaultPrevented() ) {
			return;
		}
		$( this ).find( 'button[type="submit"], input[type="submit"]' )
			.prop( 'disabled', true )
			.addClass( 'snipcore-is-busy' );
	} );
} );
