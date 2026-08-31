/**
 * Bale Connector — CF7 editor panel behavior.
 *
 * Save via AJAX, live character counter, preview rendering (server-side, so
 * it exactly matches what Bale will receive) and a test send.
 *
 * Vanilla JS only, matching AGENTS.md §10.
 */
( function () {
	'use strict';

	if ( typeof baleCf7Panel === 'undefined' ) {
		return;
	}

	var cfg = baleCf7Panel;

	function postAction( action, data, onDone ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );

		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				onDone( json );
			} )
			.catch( function () {
				onDone( { success: false, data: { message: cfg.i18n.errorGeneric } } );
			} );
	}

	function showNotice( element, message, isError ) {
		element.hidden = false;
		element.textContent = message;
		element.classList.toggle( 'notice-error', !! isError );
		element.classList.toggle( 'notice-success', ! isError );
		element.classList.toggle( 'bale-cf7-notice', true );
	}

	function currentTemplate() {
		return document.getElementById( 'bale-cf7-template' ).value;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var counter = document.getElementById( 'bale-cf7-counter' );
		var templateField = document.getElementById( 'bale-cf7-template' );
		var previewBox = document.getElementById( 'bale-cf7-preview-box' );

		function updateCounter() {
			counter.textContent = templateField.value.length + ' / 4096';
			counter.style.color = templateField.value.length > 4096 ? '#b32d2e' : '';
		}

		templateField.addEventListener( 'input', updateCounter );
		updateCounter();

		// Preview: server renders with sample data so the preview shows the
		// exact escaping applied to submitted values.
		var previewButton = document.getElementById( 'bale-cf7-preview-btn' );
		if ( previewButton ) {
			previewButton.addEventListener( 'click', function () {
				postAction(
					'bale_cf7_preview',
					{ template: templateField.value, form_id: document.getElementById( 'bale-cf7-form-id' ).value },
					function ( json ) {
						if ( json.success ) {
							previewBox.textContent = json.data.rendered;
							previewBox.hidden = false;
							updateCounter();
							if ( json.data.over ) {
								showNotice( previewBox, cfg.i18n.charLimit, true );
							}
						} else {
							showNotice( previewBox, json.data && json.data.message ? json.data.message : cfg.i18n.errorGeneric, true );
						}
					}
				);
			} );
		}

		// Save happens natively: the panel inputs carry name attributes and
		// are submitted with CF7's own form, so settings persist through the
		// wpcf7_save_contact_form hook — no separate AJAX save here.

		// Test send.
		var testSendButton = document.getElementById( 'bale-cf7-test-send' );
		if ( testSendButton ) {
			testSendButton.addEventListener( 'click', function () {
				var select = document.getElementById( 'bale-cf7-test-recipient' );
				var recipientId = select ? parseInt( select.value, 10 ) : 0;

				if ( ! recipientId ) {
					return;
				}

				testSendButton.disabled = true;
				showNotice( previewBox, cfg.i18n.sending, false );

				postAction(
					'bale_cf7_test_send',
					{ recipient_id: recipientId, form_id: document.getElementById( 'bale-cf7-form-id' ).value },
					function ( json ) {
						testSendButton.disabled = false;

						if ( json.success ) {
							showNotice( previewBox, json.data.message, false );
						} else {
							showNotice( previewBox, json.data && json.data.message ? json.data.message : cfg.i18n.errorGeneric, true );
						}
					}
				);
			} );
		}
	} );
} )();
