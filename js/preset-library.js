/**
 * GF Presets — Preset Library Page JS.
 *
 * Handles: tabs, search, CRUD operations, inline editor,
 * linked forms expand, delete guard, and sync feedback.
 *
 * Localized strings available at: gf_presets_library_strings
 */
( function( $ ) {
	'use strict';

	var strings = typeof gf_presets_library_strings !== 'undefined' ? gf_presets_library_strings : {};
	var config  = typeof gf_presets_config !== 'undefined' ? gf_presets_config : {};
	var presets = [];
	var currentFilter = 'all';

	// Resolve nonce and URL: inline config (reliable) > GFAddOn strings > fallback.
	var restNonce = config.rest_nonce || strings.rest_nonce || '';
	var restUrl   = config.rest_url   || strings.rest_url   || '/wp-json/gf-presets/v1/';

	console.log( '[GF Presets] nonce:', restNonce ? restNonce.substring( 0, 6 ) + '...' : '(MISSING)' );
	console.log( '[GF Presets] rest_url:', restUrl );

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

	// ─── Init ──────────────────────────────────────────────────────────────────

	$( document ).ready( function() {
		loadPresets();
		bindEvents();
	} );

	// ─── Data Loading ──────────────────────────────────────────────────────────

	function loadPresets() {
		$( '#gf-presets-list' ).html(
			'<div class="gf-presets-loading"><span class="spinner is-active" style="float:none;"></span> Loading…</div>'
		);

		api( { path: 'presets' } ).then( function( data ) {
			presets = data;
			renderList();
		} ).fail( function() {
			$( '#gf-presets-list' ).html(
				'<div class="gf-presets-error">' + ( strings.error_generic || 'Error loading presets.' ) + '</div>'
			);
		} );
	}

	// ─── Rendering ─────────────────────────────────────────────────────────────

	function renderList() {
		var $list  = $( '#gf-presets-list' );
		var $empty = $( '#gf-presets-empty' );
		var query  = $( '#gf-presets-search' ).val().toLowerCase().trim();

		var filtered = presets.filter( function( p ) {
			if ( currentFilter !== 'all' && p.preset_type !== currentFilter ) {
				return false;
			}
			if ( query && p.name.toLowerCase().indexOf( query ) === -1 ) {
				return false;
			}
			return true;
		} );

		$list.empty();

		if ( filtered.length === 0 ) {
			$list.hide();
			$empty.show();
			return;
		}

		$empty.hide();
		$list.show();

		filtered.forEach( function( p ) {
			$list.append( buildCard( p ) );
		} );
	}

	function buildCard( p ) {
		var icon = { field: '📄', notification: '📧', confirmation: '✅' }[ p.preset_type ] || '📋';
		var badgeClass = 'gf-presets-badge-' + p.preset_type;
		var linkText   = p.linked_count > 0
			? '🔗 ' + p.linked_count + ' form' + ( p.linked_count > 1 ? 's' : '' )
			: '';

		var date = p.created_at ? new Date( p.created_at + 'Z' ).toLocaleDateString() : '';

		var html = '<div class="gf-presets-card" data-id="' + p.id + '" data-type="' + esc( p.preset_type ) + '">' +
			'<div class="gf-presets-card-header">' +
				'<span class="gf-presets-card-icon">' + icon + '</span>' +
				'<span class="gf-presets-card-name">' + esc( p.name ) + '</span>' +
				'<span class="gform-badge ' + badgeClass + '">' + esc( p.preset_type ) + '</span>' +
				( linkText ? '<button type="button" class="gf-presets-card-links-btn" data-id="' + p.id + '">' + linkText + '</button>' : '' ) +
			'</div>' +
			'<div class="gf-presets-card-meta">' +
				( date ? 'Created ' + date + ' · ' : '' ) +
				'<button type="button" class="button gf-presets-action-edit" data-id="' + p.id + '">Edit</button> · ' +
				'<button type="button" class="button gf-presets-action-duplicate" data-id="' + p.id + '">Duplicate</button> · ' +
				'<button type="button" class="button gf-presets-action-delete" data-id="' + p.id + '" data-linked="' + p.linked_count + '">Delete</button>' +
			'</div>' +
			'<div class="gf-presets-card-editor" data-id="' + p.id + '" style="display:none;"></div>' +
			'<div class="gf-presets-card-links-expand" data-id="' + p.id + '" style="display:none;"></div>' +
		'</div>';

		return html;
	}

	// ─── Inline Editor ─────────────────────────────────────────────────────────

	function openEditor( id ) {
		var p = presets.find( function( x ) { return x.id == id; } );
		if ( ! p ) return;

		var $editor = $( '.gf-presets-card-editor[data-id="' + id + '"]' );

		// Close other open editors.
		$( '.gf-presets-card-editor' ).not( $editor ).slideUp( 200 );

		if ( $editor.is( ':visible' ) ) {
			$editor.slideUp( 200 );
			return;
		}

		var payload;
		try {
			payload = JSON.parse( p.payload );
		} catch ( e ) {
			payload = {};
		}

		// Build a human-readable summary of key payload properties.
		var summary = buildPayloadSummary( p.preset_type, payload );

		var linkedCount = p.linked_count || 0;
		var syncWarning = linkedCount > 0
			? '<div class="gf-presets-notice gf-presets-notice-warning"><span class="dashicons dashicons-warning"></span> Saving will sync ' + linkedCount + ' linked form' + ( linkedCount > 1 ? 's' : '' ) + '.</div>'
			: '';

		var editorHtml =
			'<div class="gf-presets-inline-editor">' +
				'<div class="gf-presets-form-group">' +
					'<label>Name</label>' +
					'<input type="text" class="gf-presets-input gf-presets-edit-name" value="' + escAttr( p.name ) + '" maxlength="255" />' +
				'</div>' +
				'<div class="gf-presets-form-group">' +
					'<label>Description</label>' +
					'<textarea class="gf-presets-textarea gf-presets-edit-desc" rows="2">' + esc( p.description || '' ) + '</textarea>' +
				'</div>' +
				'<div class="gf-presets-form-group">' +
					'<label>Payload Preview (read-only)</label>' +
					'<div class="gf-presets-payload-preview">' + summary + '</div>' +
				'</div>' +
				syncWarning +
				'<div class="gf-presets-editor-actions">' +
					'<button type="button" class="button gf-presets-edit-cancel">Cancel</button>' +
					'<button type="button" class="button button-primary gf-presets-edit-save" data-id="' + id + '">Save Changes</button>' +
				'</div>' +
			'</div>';

		$editor.html( editorHtml ).slideDown( 200 );
	}

	function buildPayloadSummary( type, payload ) {
		var rows = [];

		// Human-friendly labels for well-known keys.
		var labels = {
			type: 'Type', label: 'Label', isRequired: 'Required', cssClass: 'CSS Class',
			description: 'Description', placeholder: 'Placeholder', defaultValue: 'Default Value',
			size: 'Size', inputMask: 'Input Mask', maxLength: 'Max Length',
			choices: 'Choices', inputs: 'Inputs', enableChoiceValue: 'Choice Values',
			conditionalLogic: 'Conditional Logic', visibility: 'Visibility',
			adminLabel: 'Admin Label', errorMessage: 'Error Message',
			to: 'To', toType: 'To Type', from: 'From', fromName: 'From Name',
			replyTo: 'Reply To', bcc: 'BCC', cc: 'CC',
			subject: 'Subject', message: 'Message', event: 'Event',
			disableAutoformat: 'Disable Auto-Formatting',
			url: 'URL', queryString: 'Query String', pageId: 'Page',
			routing: 'Routing', name: 'Name', id: 'ID',
		};

		// Skip keys that are always stripped / identity.
		var skipKeys = { id: true, formId: true, pageNumber: true };

		// Keys that are part of the CL composite — handled separately.
		var clKeys = {
			conditionalLogic: true,
			confirmation_conditional_logic: true,
			confirmation_conditional_logic_object: true,
			notification_conditional_logic: true,
			notification_conditional_logic_object: true,
		};

		var hasCL = false;

		Object.keys( payload ).forEach( function( key ) {
			if ( skipKeys[ key ] ) return;

			// Collect CL-related keys into one row later.
			if ( clKeys[ key ] ) {
				if ( payload[ key ] ) hasCL = true;
				return;
			}

			var val   = payload[ key ];
			var label = labels[ key ] || key;
			var display;

			if ( val === null || val === undefined || val === '' ) return;

			if ( typeof val === 'boolean' ) {
				display = val ? 'Yes' : 'No';
			} else if ( typeof val === 'object' ) {
				display = formatObjectPreview( key, val );
			} else {
				display = String( val );
				if ( display.length > 120 ) {
					display = display.substring( 0, 120 ) + '…';
				}
			}

			rows.push( '<tr><td>' + esc( label ) + '</td><td>' + esc( display ) + '</td></tr>' );
		} );

		// Summarise Conditional Logic as a single readable row.
		if ( hasCL ) {
			rows.push( '<tr><td>Conditional Logic</td><td>' + esc( formatCLPreview( payload ) ) + '</td></tr>' );
		}

		if ( rows.length === 0 ) {
			return '<em>No preview available.</em>';
		}

		return '<table class="gf-presets-summary-table">' + rows.join( '' ) + '</table>';
	}

	/**
	 * Build a human-readable one-liner for a conditional logic object.
	 */
	function formatCLPreview( payload ) {
		// The actual CL rules live in conditionalLogic or *_conditional_logic_object.
		var cl = payload.conditionalLogic
			|| payload.confirmation_conditional_logic_object
			|| payload.notification_conditional_logic_object
			|| null;

		if ( ! cl || typeof cl !== 'object' ) return 'Enabled';

		var rules = cl.rules || [];
		if ( rules.length === 0 ) return 'Enabled (no rules)';

		var logic = ( cl.logicType === 'any' ? 'Any' : 'All' );
		var parts = rules.map( function( r ) {
			return ( r.fieldLabel || 'Field #' + r.fieldId ) + ' ' + ( r.operator || 'is' ) + ' "' + ( r.value || '' ) + '"';
		} );

		var action = cl.actionType === 'hide' ? 'Hide' : 'Show';
		return action + ' if ' + logic + ': ' + parts.join( ', ' );
	}

	/**
	 * Format an object/array value for the preview table.
	 */
	function formatObjectPreview( key, val ) {
		if ( Array.isArray( val ) ) {
			// Choices: show first few labels.
			if ( key === 'choices' && val.length > 0 && val[ 0 ].text ) {
				var labels = val.slice( 0, 4 ).map( function( c ) { return c.text; } );
				return labels.join( ', ' ) + ( val.length > 4 ? ' … (' + val.length + ' total)' : '' );
			}
			return val.length + ' item' + ( val.length !== 1 ? 's' : '' );
		}
		return Object.keys( val ).length + ' key' + ( Object.keys( val ).length !== 1 ? 's' : '' );
	}

	// ─── Linked Forms Expand ───────────────────────────────────────────────────

	function toggleLinkedForms( id ) {
		var $expand = $( '.gf-presets-card-links-expand[data-id="' + id + '"]' );

		if ( $expand.is( ':visible' ) ) {
			$expand.slideUp( 200 );
			return;
		}

		$expand.html( '<span class="spinner is-active" style="float:none;"></span>' ).slideDown( 200 );

		api( { path: 'links?preset_id=' + id } ).then( function( links ) {
			if ( links.length === 0 ) {
				$expand.html( '<em>No linked forms.</em>' );
				return;
			}

			var html = '<ul class="gf-presets-linked-list">';
			var maxShow = 4;

			links.forEach( function( link, i ) {
				var hidden = i >= maxShow ? ' style="display:none;" class="gf-presets-linked-extra"' : '';
				html += '<li' + hidden + '>' +
					'<span class="gf-presets-linked-form-title">' + esc( link.form_title ) + '</span>' +
					' <button type="button" class="button gf-presets-break-link" data-link-id="' + link.id + '" data-preset-id="' + id + '">Break Link</button>' +
				'</li>';
			} );

			if ( links.length > maxShow ) {
				html += '<li><button type="button" class="button gf-presets-show-all-links">Show all (' + links.length + ')</button></li>';
			}

			html += '</ul>';
			$expand.html( html );
		} );
	}

	// ─── Event Bindings ────────────────────────────────────────────────────────

	function bindEvents() {

		// Tab switching.
		$( '#gf-presets-tabs' ).on( 'click', '.gf-presets-tab', function() {
			$( '.gf-presets-tab' ).removeClass( 'active' );
			$( this ).addClass( 'active' );
			currentFilter = $( this ).data( 'type' );
			renderList();
		} );

		// Search.
		$( '#gf-presets-search' ).on( 'input', function() {
			renderList();
		} );

		// Edit.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-action-edit', function() {
			openEditor( $( this ).data( 'id' ) );
		} );

		// Cancel edit.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-edit-cancel', function() {
			$( this ).closest( '.gf-presets-card-editor' ).slideUp( 200 );
		} );

		// Save edit.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-edit-save', function() {
			var id   = $( this ).data( 'id' );
			var $card = $( this ).closest( '.gf-presets-card' );
			var name = $card.find( '.gf-presets-edit-name' ).val().trim();
			var desc = $card.find( '.gf-presets-edit-desc' ).val().trim();

			if ( ! name ) {
				alert( 'Name is required.' );
				return;
			}

			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Saving…' );

			api( {
				path: 'presets/' + id,
				method: 'PUT',
				data: { name: name, description: desc },
			} ).then( function( result ) {
				showToast( 'success', strings.sync_success
					? strings.sync_success.replace( '%d', result.sync_result ? result.sync_result.synced : 0 )
					: 'Saved.' );
				loadPresets();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
				$btn.prop( 'disabled', false ).text( 'Save Changes' );
			} );
		} );

		// Duplicate.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-action-duplicate', function() {
			var id = $( this ).data( 'id' );
			var p  = presets.find( function( x ) { return x.id == id; } );
			if ( ! p ) return;

			var payload;
			try { payload = JSON.parse( p.payload ); } catch ( e ) { payload = {}; }

			api( {
				path: 'presets',
				method: 'POST',
				data: {
					preset_type: p.preset_type,
					name:        p.name + ' (Copy)',
					description: p.description || '',
					payload:     payload,
				},
			} ).then( function() {
				showToast( 'success', 'Preset duplicated.' );
				loadPresets();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
			} );
		} );

		// Delete.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-action-delete', function() {
			var id     = $( this ).data( 'id' );
			var linked = parseInt( $( this ).data( 'linked' ) || 0, 10 );

			var msg = linked > 0
				? ( strings.confirm_delete || 'This preset is linked to %d forms. Continue?' ).replace( '%d', linked )
				: 'Delete this preset?';

			if ( ! confirm( msg ) ) return;

			api( {
				path: 'presets/' + id,
				method: 'DELETE',
			} ).then( function() {
				showToast( 'success', strings.delete_success || 'Preset deleted.' );
				loadPresets();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
			} );
		} );

		// Linked forms expand.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-card-links-btn', function() {
			toggleLinkedForms( $( this ).data( 'id' ) );
		} );

		// Show all linked forms.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-show-all-links', function() {
			$( this ).closest( 'ul' ).find( '.gf-presets-linked-extra' ).show();
			$( this ).parent().remove();
		} );

		// Break link.
		$( '#gf-presets-list' ).on( 'click', '.gf-presets-break-link', function() {
			var linkId   = $( this ).data( 'link-id' );
			var presetId = $( this ).data( 'preset-id' );

			if ( ! confirm( strings.confirm_break_link || 'Break this link?' ) ) return;

			api( {
				path: 'links/' + linkId,
				method: 'DELETE',
			} ).then( function() {
				showToast( 'success', 'Link broken.' );
				loadPresets();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
			} );
		} );
	}

	// ─── Toast Notifications ─────────────────────────────────────────────────

	function showToast( type, message ) {
		var $container = $( '#gf-presets-toast-container' );
		var cls = 'gf-presets-toast gf-presets-toast-' + type;
		var $toast = $( '<div class="' + cls + '">' + esc( message ) + '</div>' );

		$container.append( $toast );

		setTimeout( function() {
			$toast.addClass( 'fade-out' );
			setTimeout( function() { $toast.remove(); }, 400 );
		}, 4000 );
	}

	// ─── Utilities ────────────────────────────────────────────────────────────

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
