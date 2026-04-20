/**
 * GF Presets — Field Preset Editor JS.
 *
 * Injected into the form editor via form_editor enqueue.
 * Provides the sidebar accordion section for field presets:
 * - Save as Preset (captures current field object)
 * - Load Preset dropdown + Apply modal
 *
 * Localized strings at: gf_presets_field_editor_strings
 */
( function( $ ) {
	'use strict';

	console.log( '[GF Presets] field-preset-editor.js loaded.' );

	var strings = typeof gf_presets_field_editor_strings !== 'undefined' ? gf_presets_field_editor_strings : {};
	var config  = typeof gf_presets_config !== 'undefined' ? gf_presets_config : {};

	console.log( '[GF Presets] config available:', !! config.rest_nonce, 'strings available:', !! strings.rest_nonce );

	var restNonce = config.rest_nonce || strings.rest_nonce || '';
	var restUrl   = config.rest_url   || strings.rest_url   || '/wp-json/gf-presets/v1/';

	if ( ! restNonce ) {
		console.warn( '[GF Presets] No REST nonce found — API calls will fail.' );
	}

	// ─── REST helper ───────────────────────────────────────────────────────────

	function api( opts ) {
		// Split path from query string so we can append with the correct separator.
		// rest_url may already contain '?' when pretty permalinks are off.
		var parts = opts.path.split( '?' );
		var url   = restUrl + parts[ 0 ];
		if ( parts[ 1 ] ) {
			url += ( url.indexOf( '?' ) !== -1 ? '&' : '?' ) + parts[ 1 ];
		}

		console.log( '[GF Presets] API ' + ( opts.method || 'GET' ) + ' ' + url );

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
		console.log( '[GF Presets] document.ready — initializing.' );
		initFieldPresetUI();
		bindModalEvents();
	} );

	// ─── Sidebar accordion injection ────────────────────────────────────────────

	function initFieldPresetUI() {
		// Wait for form editor to be ready.
		if ( typeof gform === 'undefined' || typeof GetSelectedField !== 'function' ) {
			console.warn( '[GF Presets] initFieldPresetUI — gform or GetSelectedField not available, aborting.', { gform: typeof gform, GetSelectedField: typeof GetSelectedField } );
			return;
		}

		console.log( '[GF Presets] initFieldPresetUI — binding gform_load_field_settings.' );

		// Listen for field selection changes to show/hide preset controls.
		$( document ).on( 'gform_load_field_settings', function( event, field, form ) {
			console.log( '[GF Presets] gform_load_field_settings fired, field:', field && field.id, field && field.type );
			onFieldSelected( field, form );
		} );
	}

	function onFieldSelected( field, form ) {
		var $container = $( '#gf_presets_field_section' );

		if ( ! $container.length ) {
			console.warn( '[GF Presets] onFieldSelected — #gf_presets_field_section not found in DOM.' );
			return;
		}

		console.log( '[GF Presets] onFieldSelected — injecting controls into #gf-presets-field-inner.' );

		// Build the Save + Load UI inside the container.
		var html =
			'<div class="gf-presets-field-controls">' +
				'<button type="button" class="button gf-presets-field-save-btn" id="gf-presets-field-save">' +
					'<span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:3px;"></span> Save as Preset' +
				'</button>' +
				'<div class="gf-presets-field-load-wrap">' +
					'<select id="gf-presets-field-load-select" class="gf-presets-field-select">' +
						'<option value="">' + '— Load Preset —' + '</option>' +
					'</select>' +
				'</div>' +
			'</div>' +
			'<div id="gf-presets-field-link-info" class="gf-presets-field-link-info" style="display:none;">' +
				'<span class="dashicons dashicons-admin-links" style="vertical-align:middle;margin-right:4px;color:#2271b1;"></span>' +
				'<span id="gf-presets-field-link-label" class="gf-presets-link-label"></span>' +
				'<button type="button" class="button-link gf-presets-break-link-btn" id="gf-presets-field-break-link" title="Break live link">' +
					'<span class="dashicons dashicons-editor-unlink" style="vertical-align:middle;font-size:16px;width:16px;height:16px;"></span> Unlink' +
				'</button>' +
			'</div>' +
			'<div id="gf-presets-field-sync-warning" class="gf-presets-field-sync-warning" style="display:none;">' +
				'<span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:4px;"></span>' +
				'<span>' + ( strings.sync_warning || 'This field is live-linked. Saving this form will push your changes to all other forms using this preset.' ) + '</span>' +
			'</div>';

		$container.find( '#gf-presets-field-inner' ).html( html );

		// Bind events directly on the injected elements.
		// GF's form editor stops event propagation inside field settings,
		// so delegation from document doesn't reach these elements.
		$container.find( '#gf-presets-field-save' ).off( 'click.gfpresets' ).on( 'click.gfpresets', handleFieldSaveClick );
		$container.find( '#gf-presets-field-load-select' ).off( 'change.gfpresets' ).on( 'change.gfpresets', handleFieldLoadChange );
		$container.find( '#gf-presets-field-break-link' ).off( 'click.gfpresets' ).on( 'click.gfpresets', handleBreakLink );

		// Populate the load dropdown.
		populateFieldPresetDropdown();

		// Check for active live link.
		checkFieldLiveLink( field, form );
	}

	/**
	 * Check if the current field has a live link and update the UI.
	 */
	var _syncWarningShown = false;
	var _fieldIsLinked    = false;
	var _currentLinkId    = 0;
	var _excludedKeys     = [];

	// ─── Setting → field property key mapping ───────────────────────────────
	// Maps the GF tooltip class suffix (after "tooltip_form_field_") to the
	// field property key(s) that setting controls.
	var TOOLTIP_TO_KEYS = {
		// General
		'label':              ['label'],
		'description':        ['description'],
		'admin_label':        ['adminLabel'],
		'css_class':          ['cssClass'],
		'default_value':      ['defaultValue'],
		'default_input_values': ['inputs'],
		'placeholder':        ['placeholder'],
		'input_placeholders': ['inputs'],
		'maxlength':          ['maxLength'],
		'maxrows':            ['maxRows'],
		'size':               ['size'],
		'visibility':         ['visibility'],
		'validation_message': ['errorMessage'],
		'required':           ['isRequired'],
		'no_duplicate':       ['noDuplicates'],
		'conditional_logic':  ['conditionalLogic'],
		'mask':               ['inputMask', 'inputMaskValue', 'inputMaskIsCustom'],
		'prepopulate':        ['allowsPrepopulate', 'inputName'],
		'autocomplete':       ['enableAutocomplete', 'autocompleteAttribute'],
		'content':            ['content'],

		// Appearance / placement
		'label_placement':        ['labelPlacement'],
		'description_placement':  ['descriptionPlacement'],
		'sub_label_placement':    ['subLabelPlacement'],
		'sub_labels':             ['inputs'],
		'hide_label':             ['labelPlacement'],

		// Number
		'number_format':      ['numberFormat'],
		'number_range':       ['rangeMin', 'rangeMax'],
		'enable_calculation': ['enableCalculation'],
		'calculation_formula':  ['calculationFormula'],
		'calculation_rounding': ['calculationRounding'],

		// Choices
		'choices':            ['choices', 'enableChoiceValue'],
		'choice_values':      ['enableChoiceValue'],
		'other_choice':       ['enableOtherChoice'],
		'select_all_choices': ['enableSelectAll'],
		'display_choices_in_columns': ['enableColumns'],
		'checkbox_label':     ['checkboxLabel'],
		'enable_enhanced_ui': ['enableEnhancedUI'],

		// Format
		'name_format':        ['nameFormat'],
		'date_input_type':    ['dateType'],
		'date_format':        ['dateFormat', 'calendarIconType', 'calendarIconUrl'],
		'time_format':        ['timeFormat'],
		'phone_format':       ['phoneFormat'],
		'address_type':       ['addressType'],

		// File upload
		'fileupload_allowed_extensions': ['allowedExtensions'],
		'max_file_size':      ['maxFileSize'],
		'multiple_files':     ['multipleFiles', 'maxFiles'],
		'max_files':          ['maxFiles'],

		// Post / advanced
		'custom_field_name':  ['postCustomFieldName'],
		'rich_text_editor':   ['useRichTextEditor'],
	};

	/**
	 * Extract the setting key from a tooltip button's class list.
	 * Looks for a class matching "tooltip_form_field_{key}" and returns {key}.
	 */
	function getTooltipSettingKey( el ) {
		var classes = ( el.className || '' ).split( /\s+/ );
		for ( var i = 0; i < classes.length; i++ ) {
			var m = classes[ i ].match( /^tooltip_form_field_(.+)$/ );
			if ( m ) return m[ 1 ];
		}
		return null;
	}

	/**
	 * Update the Field Preset tab toggle button with an Active/Inactive badge.
	 */
	function updatePresetTabBadge( isLinked ) {
		var $toggle = $( '#gf_presets_tab_toggle' );
		if ( ! $toggle.length ) return;

		// Remove any existing badge.
		$toggle.find( '.gfp-preset-status' ).remove();

		var activeClass = isLinked ? 'gform-status--active' : 'gform-status--inactive';
		var label       = isLinked ? ( strings.status_active || 'Active' ) : ( strings.status_inactive || 'Inactive' );

		var badge = '<span class="gfp-preset-status gform-status-indicator gform-status-indicator--size-sm gform-status-indicator--theme-cosmos gform-status--no-hover ' + activeClass + '">' +
			'<span class="gform-status-indicator-status gform-typography--weight-medium gform-typography--size-text-xs">' + label + '</span>' +
		'</span>';

		$toggle.append( badge );
	}

	function checkFieldLiveLink( field, form ) {
		var $info    = $( '#gf-presets-field-link-info' );
		var $warning = $( '#gf-presets-field-sync-warning' );
		$info.hide().removeData( 'linkId' );
		$warning.hide();
		_syncWarningShown = false;
		_fieldIsLinked    = false;
		_currentLinkId    = 0;
		_excludedKeys     = [];

		// Remove any previous change watcher and link icons.
		unbindSyncChangeWatcher();
		removeSyncIcons();

		var formId = form ? form.id : ( typeof window.form !== 'undefined' ? window.form.id : 0 );
		if ( ! formId || ! field || ! field.id ) return;

		api( { path: 'links/lookup?form_id=' + formId + '&object_id=' + field.id } ).then( function( data, textStatus, jqXHR ) {
			if ( jqXHR.status === 204 || ! data ) {
				$info.hide();
				updatePresetTabBadge( false );
				return;
			}
			$( '#gf-presets-field-link-label' ).text( 'Linked to: ' + ( data.preset_name || 'Preset #' + data.preset_id ) );
			$info.data( 'linkId', data.id ).fadeIn( 150 );
			$( '.gf-presets-field-controls' ).hide();
			_fieldIsLinked = true;
			_currentLinkId = data.id;
			_excludedKeys  = data.excluded_keys || [];

			updatePresetTabBadge( true );

			// Inject per-setting sync icons next to each tooltip.
			injectSyncIcons();

			// Start watching for settings changes on this field.
			bindSyncChangeWatcher();
		} );
	}

	// Settings sections that lack a gf_tooltip element but should still
	// show a sync icon.  Maps a jQuery selector to the TOOLTIP_TO_KEYS key.
	var EXTRA_SYNC_ANCHORS = {
		// Choices section header label (GF renders inside a separate trigger-section <li>).
		'.choices-ui__trigger-section .section_label': 'choices',
		// Conditional Logic accordion label (GF 2.5+ renders CL as an
		// accordion row; the old tooltip is hidden inside the collapsed area).
		'.conditional_logic_field_setting .conditional_logic_accordion__label': 'conditional_logic',
	};

	/**
	 * Build a sync-toggle button element.
	 */
	function buildSyncIcon( settingKey, propKeys ) {
		var isExcluded = propKeys.some( function( k ) { return _excludedKeys.indexOf( k ) !== -1; } );

		var iconClass  = isExcluded ? 'dashicons-editor-unlink' : 'dashicons-admin-links';
		var stateClass = isExcluded ? 'is-excluded' : 'is-linked';
		var tooltip    = isExcluded
			? 'This setting is NOT synced. Click to include in sync.'
			: 'This setting is synced. Click to exclude from sync.';

		var $icon = $( '<button type="button" class="gfp-sync-toggle ' + stateClass + '" ' +
			'data-setting="' + settingKey + '" ' +
			'data-keys="' + escAttr( propKeys.join( ',' ) ) + '" ' +
			'title="' + escAttr( tooltip ) + '">' +
			'<span class="dashicons ' + iconClass + '"></span>' +
		'</button>' );

		// Direct click binding (capture-safe for GF propagation stop).
		$icon[ 0 ].addEventListener( 'click', onSyncIconClick, true );

		return $icon;
	}

	/**
	 * Inject a small link/unlink icon next to each recognized tooltip (?) button.
	 */
	function injectSyncIcons() {
		// Always remove existing icons first to avoid duplicates from rapid re-calls.
		removeSyncIcons();

		// 1. Tooltip-based icons (the common case).
		$( '#field_settings_container .gf_tooltip' ).each( function() {
			var settingKey = getTooltipSettingKey( this );
			if ( ! settingKey || ! TOOLTIP_TO_KEYS[ settingKey ] ) return;

			var $icon = buildSyncIcon( settingKey, TOOLTIP_TO_KEYS[ settingKey ] );
			$( this ).after( $icon );
		} );

		// 2. Extra anchors for sections that have no tooltip element.
		$.each( EXTRA_SYNC_ANCHORS, function( selector, settingKey ) {
			if ( ! TOOLTIP_TO_KEYS[ settingKey ] ) return;
			var $anchor = $( '#field_settings_container ' + selector );
			if ( ! $anchor.length ) return;
			// Skip if an icon was already placed (e.g. a tooltip variant exists).
			if ( $anchor.children( '.gfp-sync-toggle[data-setting="' + settingKey + '"]' ).length ) return;

			var $icon = buildSyncIcon( settingKey, TOOLTIP_TO_KEYS[ settingKey ] );
			// Append inside the anchor (not after) because block-level labels
			// like .section_label would push a sibling onto a new line.
			$anchor.append( $icon );
		} );
	}

	/**
	 * Remove all injected sync icons.
	 */
	function removeSyncIcons() {
		$( '#field_settings_container .gfp-sync-toggle' ).each( function() {
			this.removeEventListener( 'click', onSyncIconClick, true );
			$( this ).remove();
		} );
	}

	/**
	 * Handle click on a per-setting sync icon.
	 */
	function onSyncIconClick( e ) {
		e.preventDefault();
		e.stopPropagation();

		var $btn      = $( this );
		var keys      = $btn.data( 'keys' ).split( ',' );
		var isNowExcluded = ! $btn.hasClass( 'is-excluded' );

		// Update local state.
		if ( isNowExcluded ) {
			keys.forEach( function( k ) {
				if ( _excludedKeys.indexOf( k ) === -1 ) _excludedKeys.push( k );
			} );
			$btn.removeClass( 'is-linked' ).addClass( 'is-excluded' )
				.attr( 'title', 'This setting is NOT synced. Click to include in sync.' )
				.find( '.dashicons' ).removeClass( 'dashicons-admin-links' ).addClass( 'dashicons-editor-unlink' );
		} else {
			_excludedKeys = _excludedKeys.filter( function( k ) { return keys.indexOf( k ) === -1; } );
			$btn.removeClass( 'is-excluded' ).addClass( 'is-linked' )
				.attr( 'title', 'This setting is synced. Click to exclude from sync.' )
				.find( '.dashicons' ).removeClass( 'dashicons-editor-unlink' ).addClass( 'dashicons-admin-links' );
		}

		// Persist to REST API.
		api( {
			path: 'links/' + _currentLinkId + '/exclusions',
			method: 'PUT',
			data: { excluded_keys: _excludedKeys },
		} );
	}

	var _captureHandler = null;

	/**
	 * Watch for any input change inside the active field settings panel.
	 * Uses capture-phase listener because GF stops event propagation
	 * inside field settings, preventing jQuery delegated events from firing.
	 */
	function bindSyncChangeWatcher() {
		var settingsEl = document.getElementById( 'field_settings_container' );
		if ( ! settingsEl ) return;

		_captureHandler = function( e ) {
			var tag = e.target.tagName;
			if ( tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'TEXTAREA' ) return;
			onLinkedFieldChange.call( e.target );
		};

		settingsEl.addEventListener( 'input', _captureHandler, true );
		settingsEl.addEventListener( 'change', _captureHandler, true );
	}

	function unbindSyncChangeWatcher() {
		var settingsEl = document.getElementById( 'field_settings_container' );
		if ( ! settingsEl || ! _captureHandler ) return;

		settingsEl.removeEventListener( 'input', _captureHandler, true );
		settingsEl.removeEventListener( 'change', _captureHandler, true );
		_captureHandler = null;
	}

	function onLinkedFieldChange() {
		if ( ! _fieldIsLinked || _syncWarningShown ) return;

		// Ignore changes inside the presets section itself (save name, load dropdown, etc.).
		if ( $( this ).closest( '#gf_presets_field_section' ).length ) return;

		_syncWarningShown = true;
		$( '#gf-presets-field-sync-warning' ).slideDown( 150 );
	}

	/**
	 * Break the active live link for the current field.
	 */
	function handleBreakLink() {
		var $info  = $( '#gf-presets-field-link-info' );
		var linkId = $info.data( 'linkId' );

		if ( ! linkId ) return;

		if ( ! confirm( strings.confirm_break_link || 'This field will keep its current settings but won\'t receive future updates from the preset. Continue?' ) ) {
			return;
		}

		api( { path: 'links/' + linkId, method: 'DELETE' } ).then( function() {
			$info.removeData( 'linkId' ).fadeOut( 150 );
			$( '.gf-presets-field-controls' ).show();
			_fieldIsLinked = false;
			_currentLinkId = 0;
			_excludedKeys  = [];
			removeSyncIcons();
			unbindSyncChangeWatcher();
			$( '#gf-presets-field-sync-warning' ).hide();
			updatePresetTabBadge( false );
			showToast( 'success', 'Live link removed.' );
		} ).fail( function( xhr ) {
			var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
			showToast( 'error', msg );
		} );
	}

	function populateFieldPresetDropdown() {
		var $select = $( '#gf-presets-field-load-select' );
		if ( ! $select.length ) return;

		// Only show presets matching the current field type.
		var field     = ( typeof GetSelectedField === 'function' ) ? GetSelectedField() : null;
		var fieldType = field ? ( field.type || '' ) : '';
		var query     = 'presets?type=field';
		if ( fieldType ) {
			query += '&field_type=' + encodeURIComponent( fieldType );
		}

		api( { path: query } ).then( function( data ) {
			$select.find( 'option:not(:first)' ).remove();

			data.forEach( function( p ) {
				$select.append( '<option value="' + p.id + '" data-name="' + escAttr( p.name ) + '">' + esc( p.name ) + '</option>' );
			} );
		} );
	}

	// ─── Event Bindings ────────────────────────────────────────────────────────

	// Handler for "Save as Preset" button inside field settings.
	function handleFieldSaveClick() {
		console.log( '[GF Presets] Save button clicked.' );

		if ( typeof GetSelectedField !== 'function' ) {
			console.warn( '[GF Presets] GetSelectedField is not a function — aborting.' );
			return;
		}

		var field = GetSelectedField();
		console.log( '[GF Presets] Selected field:', field && field.id, field && field.label );

		if ( ! field ) {
			alert( 'No field selected.' );
			return;
		}

		// Clone the field object.
		var payload = JSON.parse( JSON.stringify( field ) );

		// Remove identity keys.
		delete payload.id;
		delete payload.formId;
		delete payload.pageNumber;

		// Show CL notice if field has conditional logic.
		var hasCL = payload.conditionalLogic && payload.conditionalLogic.rules && payload.conditionalLogic.rules.length > 0;

		var $modal = $( '#gf-presets-modal-save' );
		console.log( '[GF Presets] Modal #gf-presets-modal-save found:', $modal.length > 0 );

		if ( ! $modal.length ) {
			console.error( '[GF Presets] Modal #gf-presets-modal-save is NOT in the DOM. Modals may not have been rendered on this page.' );
			return;
		}

		$( '#gf-presets-save-type' ).val( 'field' );
		$( '#gf-presets-save-payload' ).val( JSON.stringify( payload ) );
		$( '#gf-presets-save-name' ).val( field.label || '' );
		$( '#gf-presets-save-desc' ).val( '' );
		$( '#gf-presets-save-cl-notice' ).toggle( hasCL );
		$modal.fadeIn( 200 );
		console.log( '[GF Presets] Modal fadeIn triggered.' );
	}

	// Handler for "Load Preset" dropdown inside field settings.
	function handleFieldLoadChange() {
		var $this    = $( this );
		var presetId = $this.val();
		if ( ! presetId ) return;

		var presetName = $this.find( ':selected' ).data( 'name' ) || '';
		var field      = GetSelectedField();

		if ( ! field ) {
			alert( 'No field selected.' );
			return;
		}

		var formId   = typeof form !== 'undefined' ? form.id : ( $( '#gform_id' ).val() || 0 );
		var objectId = String( field.id );

		// Detect unsaved fields: if the field's DOM element has the 'gfield_new_
		// class, or if the field ID is not yet in the form's saved field list,
		// warn the user that they need to save the form first.
		var fieldSavedInDB = false;
		if ( typeof form !== 'undefined' && form.fields ) {
			for ( var i = 0; i < form.fields.length; i++ ) {
				var fid = form.fields[ i ].id || ( form.fields[ i ] && form.fields[ i ].id );
				if ( String( fid ) === objectId ) {
					fieldSavedInDB = true;
					break;
				}
			}
		}
		// GF uses a 'gfield_new' marker class for fields that haven't been
		// persisted yet.  Also fall back to checking whether the field's DOM
		// element carries 'field_new_' id prefix.
		var $fieldEl = $( '#field_' + objectId );
		var looksNew = $fieldEl.hasClass( 'gfield_new' ) || $( '#field_new_' + objectId ).length > 0;

		// If the field exists only in the JS editor but not yet in the DB,
		// ask the user to save the form first.
		if ( looksNew || ! fieldSavedInDB ) {
			alert( strings.unsaved_field_warning || 'This field has not been saved yet. Please save the form first, then apply the preset.' );
			$this.val( '' );
			return;
		}

		var $modal = $( '#gf-presets-modal-apply' );
		$( '#gf-presets-apply-name' ).text( presetName );
		$( '#gf-presets-apply-id' ).val( presetId );
		$( '#gf-presets-apply-form-id' ).val( formId );
		$( '#gf-presets-apply-object-id' ).val( objectId );
		$( '#gf-presets-apply-cl-note' ).show();
		$( 'input[name="gf-presets-apply-mode"][value="copy"]' ).prop( 'checked', true );
		$( '#gf-presets-apply-link-warning' ).hide();
		$modal.fadeIn( 200 );

		// Reset the dropdown.
		$this.val( '' );
	}

	function bindModalEvents() {

		console.log( '[GF Presets] bindModalEvents — all handlers registered.' );

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
				populateFieldPresetDropdown();
			} ).fail( function( xhr ) {
				var msg = ( xhr.responseJSON && xhr.responseJSON.message ) || strings.error_generic;
				showToast( 'error', msg );
				$btn.prop( 'disabled', false ).text( 'Save Preset' );
			} );
		} );

		// Apply Preset submit.
		$( document ).on( 'click', '#gf-presets-apply-submit', function() {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).text( 'Applying…' );

			var mode = $( 'input[name="gf-presets-apply-mode"]:checked' ).val() || 'copy';

			api( {
				path: 'presets/' + $( '#gf-presets-apply-id' ).val() + '/apply',
				method: 'POST',
				data: {
					form_id:   parseInt( $( '#gf-presets-apply-form-id' ).val(), 10 ),
					object_id: $( '#gf-presets-apply-object-id' ).val(),
					mode:      mode,
				},
			} ).then( function( result ) {
				showToast( 'success', strings.apply_success || 'Preset applied.' );
				$( '#gf-presets-modal-apply' ).fadeOut( 200 );
				$btn.prop( 'disabled', false ).text( 'Apply Preset' );

				// Show CL remapping notices.
				if ( result.notices && result.notices.length ) {
					result.notices.forEach( function( n ) {
						showToast( 'info', n );
					} );
				}

				// Reload the form editor to reflect changes.
				if ( typeof form !== 'undefined' && typeof LoadFieldChoices === 'function' ) {
					location.reload();
				}

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

	// ─── Utilities ──────────────────────────────────────────────────────────────

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
