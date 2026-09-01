/**
 * Bale Connector — Logs screen behaviour (vanilla JS).
 *
 * - Expands/collapses the full payload/response JSON ("Show more" toggles).
 * - Confirms destructive actions before submitting their POST forms.
 *   The actual deletion is always a server-side, nonce-verified POST —
 *   the confirm() here is only a UX guard against accidental clicks.
 */
( function () {
	'use strict';

	var i18n = ( window.baleConnectorLogs && window.baleConnectorLogs.i18n ) || {};

	/**
	 * Wire the JSON "Show more" toggles via event delegation.
	 */
	function initJsonToggles() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.bale-log-toggle' );
			if ( ! button ) {
				return;
			}

			var cell = button.closest( '.bale-log-json' );
			if ( ! cell ) {
				return;
			}

			var pre = cell.querySelector( '.bale-log-json-full' );
			if ( ! pre ) {
				return;
			}

			var isHidden = pre.hasAttribute( 'hidden' );
			if ( isHidden ) {
				pre.removeAttribute( 'hidden' );
				button.textContent = i18n.hideDetails || 'Show less';
			} else {
				pre.setAttribute( 'hidden', 'hidden' );
				button.textContent = i18n.showMore || 'Show more';
			}
		} );
	}

	/**
	 * Wire confirm dialogs on the delete POST forms.
	 */
	function initDeleteConfirms() {
		// Single-row delete buttons.
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;

			if ( form.matches( '.bale-row-delete-form' ) ) {
				if ( ! window.confirm( i18n.confirmDelete || 'Delete this log entry?' ) ) {
					event.preventDefault();
				}
				return;
			}

			if ( form.id === 'bale-logs-bulk-form' ) {
				var bulkSelect = form.querySelector( 'select[name="action"]' );
				var action2 = form.querySelector( 'select[name="action2"]' );
				var chosen = '';
				if ( bulkSelect && bulkSelect.value && bulkSelect.value !== '-1' ) {
					chosen = bulkSelect.value;
				} else if ( action2 && action2.value && action2.value !== '-1' ) {
					chosen = action2.value;
				}

				if ( ! chosen ) {
					// No bulk action chosen: let the table show its standard
					// "no items selected" notice instead of submitting.
					event.preventDefault();
					window.alert( i18n.nothingSelected || 'Please select at least one log entry and an action.' );
					return;
				}

				var isDeleteAll = chosen === 'bale_delete_all_logs';
				var checked = form.querySelectorAll( 'input[name="log_ids[]"]:checked' );

				if ( ! isDeleteAll && 0 === checked.length ) {
					event.preventDefault();
					window.alert( i18n.nothingSelected || 'Please select at least one log entry and an action.' );
					return;
				}

				var message = isDeleteAll
					? ( i18n.confirmDeleteAll || 'Delete ALL log entries? This cannot be undone.' )
					: ( i18n.confirmBulkDelete || 'Delete the selected log entries?' );

				if ( ! window.confirm( message ) ) {
					event.preventDefault();
				}
				return;
			}

			if ( form.matches( '.bale-delete-all-form' ) ) {
				if ( ! window.confirm( i18n.confirmDeleteAll || 'Delete ALL log entries? This cannot be undone.' ) ) {
					event.preventDefault();
				}
			}
		} );
	}

	/**
	 * Auto-hide the deletion success notices after a few seconds.
	 */
	function initNoticeAutohide() {
		var notices = document.querySelectorAll( '.bale-connector-wrap .notice.is-dismissible' );
		if ( ! notices.length ) {
			return;
		}
		window.setTimeout( function () {
			notices.forEach( function ( notice ) {
				notice.style.transition = 'opacity 0.5s';
				notice.style.opacity = '0';
				window.setTimeout( function () {
					notice.remove();
				}, 500 );
			} );
		}, 4000 );
	}

	function init() {
		initJsonToggles();
		initDeleteConfirms();
		initNoticeAutohide();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
