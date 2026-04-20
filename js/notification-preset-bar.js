/**
 * GF Presets — Notification/Confirmation Preset Toolbar JS.
 *
 * Injected via form_settings enqueue. Self-detects the notification
 * and confirmation editor pages via DOM inspection and only initializes
 * when the correct editor is present.
 *
 * Provides Save as Preset, Load Preset, and Link badge + Break Link.
 *
 * Localized strings at: gf_presets_notif_bar_strings
 */
( function( $ ) {
	'use strict';

	var strings = typeof gf_presets_notif_bar_strings !== 'undefined' ? gf_presets_notif_bar_strings : {};
	var config  = typeof gf_presets_config !== 'undefined' ? gf_presets_config : {};

	var restNonce = config.rest_nonce || strings.rest_nonce || '';
	var restUrl   = config.rest_url   || strings.rest_url   || '/wp-json/gf-presets/v1/';

	// ─── REST helper ───────────────────────────────────────────────────────────

	function api( opts ) {
		var parts = opts.path.split( '?' );
		var url   = restUrl + parts[ 0 ];
		if ( parts[ 1 ] ) {
			url += ( url.indexOf( '?' ) !== -1 ? '&' : '?' ) + parts[ 1 ];
		}

		return $.ajax( {
			url:         url,
			method:      opts.method || 'GET',
			beforeSend:  function( xhr ) {
				xhr.setRequestHeader( 'X-WP-Nonce', restNonce );
			},
			contentType: opts.data ? 'application/json' : undefined,
			data:        opts.data ? JSON.stringify( opts.data ) : undefined,
			dataType:    'json',
		} );
	}

	$( document ).ready( function() {
		detectAndInit();
	} );

	function detectAndInit() {
		var params  = new URLSearchParams( window.location.search );
		var page    = params.get( 'page' );
		var subview = params.get( 'subview' );
		var formId  = parseInt( params.get( 'id' ) || 0, 10 );

		if ( page !== 'gf_edit_forms' || ! formId ) return;

		// Detect notification / confirmation editor pages.
		// The DOM checks inside initToolbar() prevent activation on list pages.
		if ( subview === 'notification' ) {
			initToolbar( 'notification', formId );
		} else if ( subview === 'confirmation' ) {
			initToolbar( 'confirmation', formId );
		}
	}

	function initToolbar( type, formId ) {
		// Find injection target.
		// GF 2.5+ renders the save button as <button id="gform-settings-save">
		// inside <div class="gform-settings-save-container"> within <form id="gform-settings">.
		var $target = $( '.gform-settings-save-container' ).first();

		if ( ! $target.length ) {
			$target = $( '#gform-settings-save' ).closest( 'div' );
		}

		if ( ! $target.length ) {
			// Legacy GF (<2.5) fallbacks.
			$target = $( '#notification_submit_container' );
		}

		if ( ! $target.length ) {
			$target = $( 'form#gform-settings' );
		}

		if ( ! $target.length ) {
			console.warn( '[GF Presets] Cannot find injection target. GF DOM may have changed.' );
			return;
		}

		// Determine the object ID (notification GUID or confirmation GUID).
		var objectId = getObjectId( type );
		var unsaved  = ! objectId || objectId === '0';

		if ( unsaved ) {
			// No saved object yet — render unlinked toolbar immediately.
			renderToolbar( type, formId, objectId, $target, null, true );
			return;
		}

		// Check if this object has an active live link.
		api( {
			path: 'links/lookup?form_id=' + formId + '&object_id=' + encodeURIComponent( objectId ),
		} ).then( function( link ) {
			if ( link && link.preset_id ) {
				renderToolbar( type, formId, objectId, $target, {
					link_id:   link.id,
					preset_id: link.preset_id,
					name:      link.preset_name || 'Preset #' + link.preset_id,
				}, false );

				// Auto-sync: push any local changes to the preset + other linked forms.
				api( {
					path:   'links/sync-from-source',
					method: 'POST',
					data:   { form_id: formId, object_id: objectId },
				} ).then( function( result ) {
					if ( result.synced ) {
						var sr = result.sync_result || {};
						var msg = 'Preset synced to ' + ( sr.synced || 0 ) + ' form(s).';
						if ( sr.conflicts && sr.conflicts.length ) {
							msg += ' ' + sr.conflicts.length + ' conflict(s).';
						}
						showToast( 'success', msg );
					}
				} );
			} else {
				renderToolbar( type, formId, objectId, $target, null, false );
			}
		} ).fail( function() {
			// Link lookup failed — render unlinked toolbar.
			renderToolbar( type, formId, objectId, $target, null, false );
		} );
	}

	function getObjectId( type ) {
		var params = new URLSearchParams( window.location.search );

		if ( type === 'notification' ) {
			return params.get( 'nid' ) || null;
		} else {
			return params.get( 'cid' ) || null;
		}
	}

	function renderToolbar( type, formId, objectId, $target, linkedPreset, unsaved ) {
		var $toolbar = $( '<div class="gf-presets-notif-toolbar" id="gf-presets-notif-toolbar"></div>' );

		if ( linkedPreset ) {
			var badgeText = ( strings.linked_badge || 'Linked to preset: "%s"' ).replace( '%s', linkedPreset.name );

			$toolbar.html(
				'<span class="gf-presets-link-badge">🔗 ' + esc( badgeText ) + '</span> ' +
				'<button type="button" class="button gf-presets-break-link-btn" data-link-id="' + linkedPreset.link_id + '">Break Link</button>'
			);
		} else {
			var loadDisabled = unsaved ? ' disabled="disabled"' : '';

			$toolbar.html(
				( unsaved
					? '<span class="gf-presets-unsaved-notice">'
						+ '<span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:2px;"></span> '
						+ esc( strings.unsaved_warning || 'Save this ' + type + ' first to load presets.' )
						+ '</span> '
					: ''
				) +
				'<button type="button" class="button gf-presets-save-btn" id="gf-presets-save-notif">' +
					'<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:3px;"></span> Save as Preset' +
				'</button> ' +
				'<div class="gf-presets-load-wrap">' +
					'<button type="button" class="button gf-presets-load-btn" id="gf-presets-load-notif"' + loadDisabled + '>' +
						'<span class="dashicons dashicons-portfolio" style="vertical-align:middle;margin-right:3px;"></span> Load Preset ▾' +
					'</button>' +
					'<div class="gf-presets-load-dropdown" id="gf-presets-load-dropdown" style="display:none;"></div>' +
				'</div>'
			);
		}

		$target.before( $toolbar );

		bindToolbarEvents( type, formId, objectId, unsaved );
	}

	function bindToolbarEvents( type, formId, objectId, unsaved ) {

		// Save as Preset.
		$( document ).on( 'click', '#gf-presets-save-notif', function() {
			if ( unsaved ) {
				showToast( 'error', strings.unsaved_warning || 'Save this ' + type + ' first to load presets.' );
				return;
			}

			// Fetch the full object payload from the server (DB) so we get
			// every property including conditional logic, routing, etc.
			api( {
				path: 'extract-payload?form_id=' + formId + '&object_id=' + encodeURIComponent( objectId ) + '&type=' + type,
			} ).then( function( data ) {
				var payload = data.payload;
				if ( ! payload || typeof payload !== 'object' ) {
					showToast( 'error', 'Could not read the current ' + type + ' data from the server.' );
					return;
				}

				var $modal = $( '#gf-presets-modal-save' );
				$( '#gf-presets-save-type' ).val( type );
				$( '#gf-presets-save-payload' ).val( JSON.stringify( payload ) );
				$( '#gf-presets-save-name' ).val( '' );
				$( '#gf-presets-save-desc' ).val( '' );
				$( '#gf-presets-save-cl-notice' ).hide();
				$modal.fadeIn( 200 );
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || 'Could not read the current ' + type + ' data.';
				showToast( 'error', msg );
			} );
		} );

		// Load Preset dropdown.
		$( document ).on( 'click', '#gf-presets-load-notif', function() {
			if ( unsaved ) {
				showToast( 'error', strings.unsaved_warning || 'Save this ' + type + ' first to load presets.' );
				return;
			}

			var $dropdown = $( '#gf-presets-load-dropdown' );

			if ( $dropdown.is( ':visible' ) ) {
				$dropdown.hide();
				return;
			}

			$dropdown.html( '<span class="spinner is-active" style="float:none;"></span>' ).show();

			api( { path: 'presets?type=' + type } ).then( function( data ) {
				if ( data.length === 0 ) {
					$dropdown.html( '<div class="gf-presets-dropdown-empty">No presets available.</div>' );
					return;
				}

				var html = '<ul class="gf-presets-dropdown-list">';
				data.forEach( function( p ) {
					html += '<li><button type="button" class="gf-presets-dropdown-item" data-preset-id="' + p.id + '" data-preset-name="' + escAttr( p.name ) + '">' + esc( p.name ) + '</button></li>';
				} );
				html += '</ul>';
				$dropdown.html( html );
			} );
		} );

		// Select preset from dropdown → open Apply modal.
		$( document ).on( 'click', '.gf-presets-dropdown-item', function() {
			var presetId   = $( this ).data( 'preset-id' );
			var presetName = $( this ).data( 'preset-name' );

			$( '#gf-presets-load-dropdown' ).hide();

			var $modal = $( '#gf-presets-modal-apply' );
			$( '#gf-presets-apply-name' ).text( presetName );
			$( '#gf-presets-apply-id' ).val( presetId );
			$( '#gf-presets-apply-form-id' ).val( formId );
			$( '#gf-presets-apply-object-id' ).val( objectId );
			$( '#gf-presets-apply-cl-note' ).hide();
			$( 'input[name="gf-presets-apply-mode"][value="copy"]' ).prop( 'checked', true );
			$( '#gf-presets-apply-link-warning' ).hide();
			$modal.fadeIn( 200 );
		} );

		// Break Link.
		$( document ).on( 'click', '.gf-presets-break-link-btn', function() {
			var linkId = $( this ).data( 'link-id' );

			if ( ! confirm( strings.confirm_break_link || 'Break this link?' ) ) return;

			api( {
				path: 'links/' + linkId,
				method: 'DELETE',
			} ).then( function() {
				location.reload();
			} );
		} );

		// Close dropdown on outside click.
		$( document ).on( 'click', function( e ) {
			if ( ! $( e.target ).closest( '.gf-presets-load-wrap' ).length ) {
				$( '#gf-presets-load-dropdown' ).hide();
			}
		} );

		// ─── Shared Modal Events ──────────────────────────────────────────────

		// Close modals.
		$( document ).on( 'click', '.gf-presets-modal-close, .gf-presets-modal-cancel', function() {
			var modalId = $( this ).data( 'modal' );
			$( '#' + modalId ).fadeOut( 200 );
		} );

		// Close modal on overlay click.
		$( document ).on( 'click', '.gf-presets-modal-overlay', function( e ) {
			if ( $( e.target ).hasClass( 'gf-presets-modal-overlay' ) ) {
				$( this ).fadeOut( 200 );
			}
		} );

		// Live Link radio → show/hide warning.
		$( document ).on( 'change', 'input[name="gf-presets-apply-mode"]', function() {
			$( '#gf-presets-apply-link-warning' ).toggle( $( this ).val() === 'link' );
		} );

		// Save Preset submit.
		$( document ).on( 'click', '#gf-presets-save-submit', function() {
			var name = $( '#gf-presets-save-name' ).val().trim();
			if ( ! name ) {
				$( '#gf-presets-save-name' ).focus();
				return;
			}

			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Saving…' );

			var payload;
			try { payload = JSON.parse( $( '#gf-presets-save-payload' ).val() ); }
			catch ( e ) { payload = {}; }

			api( {
				path: 'presets',
				method: 'POST',
				data: {
					preset_type: $( '#gf-presets-save-type' ).val(),
					name:        name,
					description: $( '#gf-presets-save-desc' ).val().trim(),
					payload:     payload,
				},
			} ).then( function() {
				showToast( 'success', strings.save_success || 'Preset saved.' );
				$( '#gf-presets-modal-save' ).fadeOut( 200 );
				$btn.prop( 'disabled', false ).text( 'Save Preset' );
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
				$btn.prop( 'disabled', false ).text( 'Save Preset' );
			} );
		} );

		// Apply Preset submit.
		$( document ).on( 'click', '#gf-presets-apply-submit', function() {
			if ( unsaved ) {
				showToast( 'error', strings.unsaved_warning || 'Save this ' + type + ' first to load presets.' );
				return;
			}

			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Applying…' );

			var mode = $( 'input[name="gf-presets-apply-mode"]:checked' ).val() || 'copy';

			api( {
				path: 'presets/' + $( '#gf-presets-apply-id' ).val() + '/apply',
				method: 'POST',
				data: {
					form_id:   formId,
					object_id: objectId,
					mode:      mode,
				},
			} ).then( function( result ) {
				showToast( 'success', strings.apply_success || 'Preset applied.' );
				$( '#gf-presets-modal-apply' ).fadeOut( 200 );
				$btn.prop( 'disabled', false ).text( 'Apply Preset' );

				if ( result.notices && result.notices.length ) {
					result.notices.forEach( function( n ) {
						showToast( 'info', n );
					} );
				}

				// Reload to reflect applied preset.
				location.reload();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
				$btn.prop( 'disabled', false ).text( 'Apply Preset' );
			} );
		} );
	}

	// ─── Toast ──────────────────────────────────────────────────────────────────

	function showToast( type, message ) {
		var $container = $( '#gf-presets-toast-container' );
		if ( ! $container.length ) {
			$( 'body' ).append( '<div id="gf-presets-toast-container" class="gf-presets-toast-container"></div>' );
			$container = $( '#gf-presets-toast-container' );
		}

		var cls = 'gf-presets-toast gf-presets-toast-' + type;
		var $toast = $( '<div class="' + cls + '">' + esc( message ) + '</div>' );

		$container.append( $toast );

		setTimeout( function() {
			$toast.addClass( 'fade-out' );
			setTimeout( function() { $toast.remove(); }, 400 );
		}, 4000 );
	}

	/**
	 * Scrape the current notification/confirmation data from the editor form.
	 *
	 * GF 2.5+ uses the Settings renderer which generates inputs with
	 * name="_gform_setting_<field>" and id="<field>" (e.g. id="toEmail").
	 * Legacy GF (<2.5) used id="gform_notification_<field>".
	 */
	function scrapePayload( type ) {
		var payload = {};

		if ( type === 'notification' ) {
			payload.to           = val( 'toEmail', 'gform_notification_to_email' );
			payload.toType       = radio( 'toType', 'gform_notification_to_type' ) || 'email';
			payload.from         = val( 'from', 'gform_notification_from' );
			payload.fromName     = val( 'fromName', 'gform_notification_from_name' );
			payload.replyTo      = val( 'replyTo', 'gform_notification_reply_to' );
			payload.bcc          = val( 'bcc', 'gform_notification_bcc' );
			payload.cc           = val( 'cc', 'gform_notification_cc' );
			payload.subject      = val( 'subject', 'gform_notification_subject' );
			payload.message      = editorVal( 'message', 'gform_notification_message' );
			payload.disableAutoformat = checked( 'disableAutoformat', 'gform_notification_disable_autoformat' );
			payload.event        = val( 'event', 'gform_notification_event' ) || 'form_submission';
		} else if ( type === 'confirmation' ) {
			payload.type         = radio( 'type', 'form_confirmation_type' ) || val( 'type', 'form_confirmation_type' ) || 'message';
			payload.message      = editorVal( 'message', 'form_confirmation_message' );
			payload.url          = val( 'url', 'form_confirmation_url' );
			payload.queryString  = val( 'queryString', 'form_confirmation_querystring' );
			payload.pageId       = val( 'pageId', 'form_confirmation_page' );
			payload.disableAutoformat = checked( 'disableAutoformat', 'form_confirmation_disable_autoformat' );
		}

		return payload;
	}

	/** Get value from GF 2.5+ id or legacy id. */
	function val( newId, legacyId ) {
		var v = $( '#' + newId ).val();
		if ( typeof v === 'undefined' || v === null ) {
			v = $( '#_gform_setting_' + newId ).val();
		}
		if ( typeof v === 'undefined' || v === null ) {
			v = $( '[name="_gform_setting_' + newId + '"]' ).val();
		}
		if ( typeof v === 'undefined' || v === null ) {
			v = $( '#' + legacyId ).val();
		}
		return v || '';
	}

	/** Get checked radio value from GF 2.5+ name or legacy name. */
	function radio( newName, legacyName ) {
		var v = $( 'input[name="_gform_setting_' + newName + '"]:checked' ).val();
		if ( ! v ) {
			v = $( 'input[name="' + legacyName + '"]:checked' ).val();
		}
		return v || '';
	}

	/** Get checkbox / toggle checked state. */
	function checked( newId, legacyId ) {
		var selectors = [
			'#' + newId,
			'#_gform_setting_' + newId,
			'input[name="_gform_setting_' + newId + '"]',
			'#' + legacyId
		];

		for ( var i = 0; i < selectors.length; i++ ) {
			var $el = $( selectors[ i ] );
			if ( ! $el.length ) continue;

			// Standard checkbox / GF toggle checkbox.
			if ( $el.is( ':checkbox' ) ) {
				return $el.is( ':checked' );
			}

			// GF 2.5+ sometimes uses a hidden input with "0"/"1".
			if ( $el.is( 'input[type="hidden"]' ) ) {
				return $el.val() === '1';
			}
		}

		// GF 2.5+ toggle widget: the visible toggle has a sibling hidden input.
		var $toggle = $( '.gform-settings-field--toggle [name="_gform_setting_' + newId + '"]' );
		if ( $toggle.length ) {
			if ( $toggle.is( ':checkbox' ) ) return $toggle.is( ':checked' );
			return $toggle.val() === '1';
		}

		return false;
	}

	/** Get editor/textarea content by GF 2.5+ id or legacy id. */
	function editorVal( newId, legacyId ) {
		var v = getEditorContent( newId );
		if ( ! v ) {
			v = getEditorContent( legacyId );
		}
		return v || '';
	}

	/**
	 * Get content from a TinyMCE editor or plain textarea.
	 * Tries the given ID, then the GF 2.5+ _gform_setting_ prefixed ID.
	 */
	function getEditorContent( editorId ) {
		var ids = [ editorId, '_gform_setting_' + editorId ];
		var i, editor, v;

		if ( typeof tinyMCE !== 'undefined' ) {
			for ( i = 0; i < ids.length; i++ ) {
				editor = tinyMCE.get( ids[ i ] );
				if ( editor && ! editor.isHidden() ) {
					return editor.getContent();
				}
			}
		}

		for ( i = 0; i < ids.length; i++ ) {
			v = $( '#' + ids[ i ] ).val();
			if ( v ) return v;
		}

		return '';
	}

	// ─── Utilities ─────────────────────────────────────────────────────────────

	function esc( str ) {
		if ( ! str ) return '';
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function escAttr( str ) {
		return esc( str ).replace( /"/g, '&quot;' );
	}

} )( jQuery );
