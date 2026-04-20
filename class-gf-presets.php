<?php
/**
 * GF Presets — Main Add-On Class.
 *
 * Extends GFAddOn to provide global preset management for
 * Gravity Forms fields, notifications, and confirmations.
 */

defined( 'ABSPATH' ) || exit;

GFForms::include_addon_framework();

class GF_Presets extends GFAddOn {

	// ───────────────────────── Required class variables ─────────────────────────

	protected $_version                  = GF_PRESETS_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'gf-presets';
	protected $_path                     = 'gf-presets/gf-presets.php';
	protected $_full_path                = __FILE__;
	protected $_title                    = 'GF Presets';
	protected $_short_title              = 'Presets';

	// ───────────────────────── Capabilities ─────────────────────────────────────

	protected $_capabilities              = array( 'gravityforms_edit_forms', 'gravityforms_uninstall' );
	protected $_capabilities_settings_page = array( 'gravityforms_edit_forms' );
	protected $_capabilities_form_settings = array( 'gravityforms_edit_forms' );
	protected $_capabilities_uninstall     = array( 'gravityforms_uninstall' );

	// ───────────────────────── Singleton ────────────────────────────────────────

	private static $_instance = null;

	/**
	 * @return GF_Presets
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	// ───────────────────────── Includes ─────────────────────────────────────────

	/**
	 * Load all helper classes before any init method fires.
	 */
	public function pre_init() {
		parent::pre_init();

		require_once GF_PRESETS_DIR . 'includes/class-preset-store.php';
		require_once GF_PRESETS_DIR . 'includes/class-preset-link-store.php';
		require_once GF_PRESETS_DIR . 'includes/class-merge-tag-scanner.php';
		require_once GF_PRESETS_DIR . 'includes/class-cl-remapper.php';
		require_once GF_PRESETS_DIR . 'includes/class-sync-engine.php';
		require_once GF_PRESETS_DIR . 'includes/class-preset-field.php';
		require_once GF_PRESETS_DIR . 'includes/class-preset-notification.php';
		require_once GF_PRESETS_DIR . 'includes/class-preset-confirmation.php';
	}

	// ───────────────────────── Initialization ──────────────────────────────────

	public function init() {
		parent::init();

		// Ensure custom DB tables exist (covers first-run and REST-only contexts
		// where the GFAddOn admin-side upgrade check may not have fired yet).
		$this->maybe_create_tables();

		// Register REST API routes (must be in init(), not init_admin(),
		// because REST requests don't go through the admin context).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Orphan cleanup: delete links when a form is deleted.
		add_action( 'gform_after_delete_form', array( $this, 'cleanup_orphan_links' ) );

		// Propagate form editor saves to live-linked presets.
		add_action( 'gform_after_save_form', array( $this, 'on_form_saved' ), 20, 2 );
	}

	/**
	 * Run install() if the stored DB version doesn't match the current plugin version.
	 * Uses dbDelta() internally so it's safe to call repeatedly.
	 */
	private function maybe_create_tables() {
		if ( get_option( 'gf_presets_db_version' ) === $this->_version ) {
			return;
		}
		$this->install();
		update_option( 'gf_presets_db_version', $this->_version );
	}

	public function init_admin() {
		parent::init_admin();

		// Field editor sidebar: register a custom settings tab (closed by default).
		add_filter( 'gform_field_settings_tabs', array( $this, 'register_field_preset_tab' ), 10, 2 );
		add_action( 'gform_field_settings_tab_content_gf_presets', array( $this, 'render_field_preset_tab_content' ), 10, 2 );

		// Field editor sidebar: inline JS for fieldSettings registration + boot.
		add_action( 'gform_editor_js', array( $this, 'field_editor_js_init' ) );

		// Render modal templates on notification/confirmation editor pages.
		add_action( 'admin_footer', array( $this, 'maybe_render_editor_modals' ) );
	}

	// ───────────────────────── Field Editor Sidebar ─────────────────────────────

	/**
	 * Register the "Field Preset" tab in the field settings sidebar.
	 *
	 * @param array $tabs   Existing custom tabs.
	 * @param array $form   The current form object.
	 * @return array
	 */
	public function register_field_preset_tab( $tabs, $form ) {
		$tabs[] = array(
			'id'              => 'gf_presets',
			'title'           => esc_html__( 'Field Preset', 'gf-presets' ),
			'toggle_classes'  => array(),
			'body_classes'    => array(),
		);
		return $tabs;
	}

	/**
	 * Render content inside the "Field Preset" settings tab.
	 *
	 * @param array  $form    The current form object.
	 * @param string $tab_id  The current tab ID.
	 */
	public function render_field_preset_tab_content( $form, $tab_id ) {
		?>
		<li class="gf_presets_field_setting field_setting" id="gf_presets_field_setting">
			<div id="gf_presets_field_section">
				<div id="gf-presets-field-inner" class="gf-presets-field-inner" style="padding: 4px 0;">
					<!-- Populated by field-preset-editor.js -->
				</div>
			</div>
		</li>
		<?php
	}

	/**
	 * Output inline JS to register the field preset setting for all supported
	 * field types and relocate the container to the correct DOM position.
	 */
	public function field_editor_js_init() {
		// Standard GF field types that support presets.
		$supported_types = array(
			'text', 'textarea', 'select', 'multiselect', 'number', 'checkbox',
			'radio', 'hidden', 'html', 'section', 'page', 'date', 'time',
			'phone', 'address', 'website', 'email', 'fileupload', 'captcha',
			'list', 'name', 'password', 'post_title', 'post_body',
			'post_excerpt', 'post_tags', 'post_category', 'post_image',
			'post_custom_field', 'consent',
		);
		?>
		<script>
			jQuery( document ).ready( function() {
				if ( typeof fieldSettings === 'undefined' ) return;

				var types = <?php echo wp_json_encode( $supported_types ); ?>;
				for ( var i = 0; i < types.length; i++ ) {
					if ( fieldSettings[ types[ i ] ] ) {
						fieldSettings[ types[ i ] ] += ', .gf_presets_field_setting';
					}
				}
			} );
		</script>
		<?php

		// REST config + modals are output by maybe_render_editor_modals() via admin_footer.
	}

	// ───────────────────────── Editor Modal Templates ──────────────────────────

	/**
	 * On notification/confirmation editor pages, output the Save and Apply
	 * modal templates in the admin footer so the toolbar JS can use them.
	 */
	public function maybe_render_editor_modals() {
		// Render on any gf_edit_forms page: form editor, notification editor, confirmation editor.
		if ( 'gf_edit_forms' !== rgget( 'page' ) ) {
			return;
		}

		// Output REST config inline so all editor JS (field, notification, confirmation) can use it.
		?>
		<script>
			var gf_presets_config = {
				rest_nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
				rest_url:   '<?php echo esc_js( rest_url( 'gf-presets/v1/' ) ); ?>'
			};
		</script>
		<?php

		include GF_PRESETS_DIR . 'admin/views/modal-save.php';
		include GF_PRESETS_DIR . 'admin/views/modal-apply.php';

		// Toast container.
		echo '<div id="gf-presets-toast-container" class="gf-presets-toast-container"></div>';
	}

	// ───────────────────────── Database Install / Upgrade / Uninstall ───────────

	/**
	 * Create custom database tables on activation or upgrade.
	 */
	public function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$presets_table = $wpdb->prefix . 'gf_presets';
		$links_table   = $wpdb->prefix . 'gf_preset_links';

		$sql = "CREATE TABLE {$presets_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			preset_type VARCHAR(20) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			payload LONGTEXT NOT NULL,
			payload_version SMALLINT NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_type (preset_type)
		) {$charset_collate};

		CREATE TABLE {$links_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			preset_id BIGINT(20) UNSIGNED NOT NULL,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			object_id VARCHAR(50) NOT NULL,
			synced_hash VARCHAR(32) NOT NULL DEFAULT '',
			excluded_keys TEXT NULL,
			linked_at DATETIME NOT NULL,
			last_synced DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_link (preset_id, form_id, object_id),
			KEY idx_preset (preset_id),
			KEY idx_form (form_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Clean up on uninstall.
	 */
	public function uninstall() {
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_preset_links" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_presets" );

		delete_option( 'gf_presets_db_version' );

		return true;
	}

	// ───────────────────────── Plugin Page (Preset Library) ─────────────────────

	/**
	 * Render the Preset Library admin page.
	 * Uses plugin_page() for a fully custom admin UI (no Settings API wrapper).
	 */
	public function plugin_page() {
		// Output REST config inline — guaranteed to be available before JS runs.
		?>
		<script>
			var gf_presets_config = {
				rest_nonce: '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>',
				rest_url:   '<?php echo esc_js( rest_url( 'gf-presets/v1/' ) ); ?>'
			};
		</script>
		<?php
		require_once GF_PRESETS_DIR . 'admin/class-preset-admin.php';
		$admin = new GF_Presets_Admin();
		$admin->render();
	}

	public function plugin_page_title() {
		return esc_html__( 'Preset Library', 'gf-presets' );
	}

	// ───────────────────────── Scripts & Styles ────────────────────────────────

	public function scripts() {
		$rest_nonce = wp_create_nonce( 'wp_rest' );
		$rest_url   = rest_url( 'gf-presets/v1/' );

		return array_merge( parent::scripts(), array(
			array(
				'handle'  => 'gf_presets_library',
				'src'     => $this->get_base_url() . '/js/preset-library.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array( 'admin_page' => array( 'plugin_page' ) ),
				),
				'strings' => array(
					'rest_nonce'         => $rest_nonce,
					'rest_url'           => $rest_url,
					'confirm_delete'     => __( 'This preset is linked to %d forms. Deleting it will break all links and leave those forms with their current settings (unaffected, but no longer synced). Continue?', 'gf-presets' ),
					'confirm_break_link' => __( 'This object will keep its current settings but won\'t receive future updates. Continue?', 'gf-presets' ),
					'sync_success'       => __( 'Preset saved. Synced to %d forms.', 'gf-presets' ),
					'sync_partial'       => __( 'Preset saved. Synced to %d forms. %d conflicts. %d errors.', 'gf-presets' ),
					'delete_success'     => __( 'Preset deleted.', 'gf-presets' ),
					'error_generic'      => __( 'An error occurred. Please try again.', 'gf-presets' ),
				),
			),
			array(
				'handle'  => 'gf_presets_field_editor',
				'src'     => $this->get_base_url() . '/js/field-preset-editor.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array( 'admin_page' => array( 'form_editor' ) ),
				),
				'strings' => array(
					'rest_nonce'        => $rest_nonce,
					'rest_url'          => $rest_url,
					'save_success'      => __( 'Preset saved.', 'gf-presets' ),
					'apply_success'     => __( 'Preset applied.', 'gf-presets' ),
					'cl_remap_info'     => __( 'CL remapped: %s. Dropped: %s.', 'gf-presets' ),
					'cl_remap_note'     => __( 'Conditional logic will be remapped by matching field labels. Rules referencing fields not found in this form will be dropped.', 'gf-presets' ),
					'confirm_break_link' => __( 'This field will keep its current settings but won\'t receive future updates from the preset. Continue?', 'gf-presets' ),
					'link_removed'      => __( 'Live link removed.', 'gf-presets' ),
					'sync_warning'      => __( 'This field is live-linked. Saving this form will push your changes to all other forms using this preset.', 'gf-presets' ),
					'unsaved_field_warning' => __( 'This field has not been saved yet. Please save the form first, then apply the preset.', 'gf-presets' ),
					'error_generic'     => __( 'An error occurred. Please try again.', 'gf-presets' ),
					'status_active'     => __( 'Active', 'gf-presets' ),
					'status_inactive'   => __( 'Inactive', 'gf-presets' ),
				),
			),
			array(
				'handle'  => 'gf_presets_notif_bar',
				'src'     => $this->get_base_url() . '/js/notification-preset-bar.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array( 'admin_page' => array( 'form_settings' ) ),
				),
				'strings' => array(
					'rest_nonce'         => $rest_nonce,
					'rest_url'           => $rest_url,
					'save_success'       => __( 'Preset saved.', 'gf-presets' ),
					'apply_success'      => __( 'Preset applied.', 'gf-presets' ),
					'linked_badge'       => __( 'Linked to preset: "%s"', 'gf-presets' ),
					'confirm_break_link' => __( 'This object will keep its current settings but won\'t receive future updates. Continue?', 'gf-presets' ),
					'merge_tag_warning'  => __( 'This preset contains field-specific merge tags that may not exist in this form: %s', 'gf-presets' ),
					'error_generic'      => __( 'An error occurred. Please try again.', 'gf-presets' ),
				),
			),
		) );
	}

	public function styles() {
		return array_merge( parent::styles(), array(
			array(
				'handle'  => 'gf_presets_css',
				'src'     => $this->get_base_url() . '/css/gf-presets.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( 'admin_page' => array( 'plugin_page', 'form_editor', 'form_settings' ) ),
				),
			),
		) );
	}

	// ───────────────────────── REST API ─────────────────────────────────────────

	/**
	 * Register all REST API routes under /wp-json/gf-presets/v1/.
	 */
	public function register_rest_routes() {

		$namespace = 'gf-presets/v1';
		$addon     = $this;

		$permission = function () use ( $addon ) {
			$user    = wp_get_current_user();
			$user_id = $user ? $user->ID : 0;
			$can_gf  = current_user_can( 'gravityforms_edit_forms' );
			$can_wp  = current_user_can( 'manage_options' );

			$addon->log_debug( sprintf(
				'%s(): REST permission check — user_id=%d, user_login=%s, can(gravityforms_edit_forms)=%s, can(manage_options)=%s',
				__METHOD__,
				$user_id,
				$user ? $user->user_login : '(none)',
				$can_gf ? 'YES' : 'NO',
				$can_wp ? 'YES' : 'NO'
			) );

			// Allow access if user has either capability.
			return $can_gf || $can_wp;
		};

		// GET /presets — List all presets (optional ?type= and ?field_type= filters).
		register_rest_route( $namespace, '/presets', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list_presets' ),
			'permission_callback' => $permission,
			'args'                => array(
				'type' => array(
					'type'              => 'string',
					'enum'              => array( 'field', 'notification', 'confirmation' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'field_type' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Filter field presets by GF field type (e.g. select, text, html).',
				),
			),
		) );

		// POST /presets — Create a new preset.
		register_rest_route( $namespace, '/presets', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_create_preset' ),
			'permission_callback' => $permission,
		) );

		// PUT /presets/{id} — Update preset + trigger sync.
		register_rest_route( $namespace, '/presets/(?P<id>\d+)', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'rest_update_preset' ),
			'permission_callback' => $permission,
		) );

		// DELETE /presets/{id} — Delete preset.
		register_rest_route( $namespace, '/presets/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_delete_preset' ),
			'permission_callback' => $permission,
		) );

		// POST /presets/{id}/apply — Apply preset to a form object.
		register_rest_route( $namespace, '/presets/(?P<id>\d+)/apply', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_apply_preset' ),
			'permission_callback' => $permission,
		) );

		// GET /links — List links for a preset.
		register_rest_route( $namespace, '/links', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list_links' ),
			'permission_callback' => $permission,
			'args'                => array(
				'preset_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// DELETE /links/{id} — Break a specific live link.
		register_rest_route( $namespace, '/links/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( $this, 'rest_break_link' ),
			'permission_callback' => $permission,
		) );

		// GET /links/lookup — Find link for a specific form object.
		register_rest_route( $namespace, '/links/lookup', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_lookup_link' ),
			'permission_callback' => $permission,
			'args'                => array(
				'form_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'object_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// PUT /links/{id}/exclusions — Update excluded keys for a link.
		register_rest_route( $namespace, '/links/(?P<id>\d+)/exclusions', array(
			'methods'             => 'PUT',
			'callback'            => array( $this, 'rest_update_link_exclusions' ),
			'permission_callback' => $permission,
		) );

		// GET /extract-payload — Read a notification/confirmation/field from the DB.
		register_rest_route( $namespace, '/extract-payload', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_extract_payload' ),
			'permission_callback' => $permission,
			'args'                => array(
				'form_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
				'object_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'type' => array(
					'required'          => true,
					'type'              => 'string',
					'enum'              => array( 'field', 'notification', 'confirmation' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// POST /links/sync-from-source — Detect local changes on a linked
		// object and push them to the preset + all other linked objects.
		register_rest_route( $namespace, '/links/sync-from-source', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_sync_from_source' ),
			'permission_callback' => $permission,
		) );
	}

	// ─────── REST Callbacks ────────────────────────────────────────────────────

	/**
	 * GET /presets
	 */
	public function rest_list_presets( WP_REST_Request $request ) {
		$type       = $request->get_param( 'type' );
		$field_type = $request->get_param( 'field_type' );
		$rows       = GF_Preset_Store::list_all( $type );

		// Filter field presets by GF field type when requested.
		if ( $field_type && 'field' === $type ) {
			$rows = array_values( array_filter( $rows, function( $row ) use ( $field_type ) {
				$payload = json_decode( $row['payload'] ?? '{}', true );
				return isset( $payload['type'] ) && $payload['type'] === $field_type;
			} ) );
		}

		// Enrich each preset with its link count.
		foreach ( $rows as &$row ) {
			$links = GF_Preset_Link_Store::get_links_for_preset( $row['id'] );
			$row['linked_count'] = count( $links );
		}

		return new WP_REST_Response( $rows, 200 );
	}

	/**
	 * POST /presets
	 */
	public function rest_create_preset( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		// --- Validation ---
		$preset_type = sanitize_text_field( $params['preset_type'] ?? '' );
		if ( ! in_array( $preset_type, array( 'field', 'notification', 'confirmation' ), true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Invalid preset_type.', 'gf-presets' ) ), 400 );
		}

		$name = sanitize_text_field( $params['name'] ?? '' );
		if ( empty( $name ) || mb_strlen( $name ) > 255 ) {
			return new WP_REST_Response( array( 'message' => __( 'Name is required (max 255 chars).', 'gf-presets' ) ), 400 );
		}

		$payload = $params['payload'] ?? null;
		if ( ! is_array( $payload ) && ! is_object( $payload ) ) {
			// Try JSON-decoding if it's a string.
			if ( is_string( $payload ) ) {
				$payload = json_decode( $payload, true );
			}
			if ( ! is_array( $payload ) ) {
				return new WP_REST_Response( array( 'message' => __( 'Payload must be valid JSON.', 'gf-presets' ) ), 400 );
			}
		}

		$validation = $this->validate_payload_structure( $preset_type, $payload );
		if ( is_wp_error( $validation ) ) {
			return new WP_REST_Response( array( 'message' => $validation->get_error_message() ), 400 );
		}

		$description = sanitize_text_field( $params['description'] ?? '' );

		$id = GF_Preset_Store::create( $preset_type, $name, $description, $payload );

		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'message' => $id->get_error_message() ), 500 );
		}

		return new WP_REST_Response( GF_Preset_Store::read( $id ), 201 );
	}

	/**
	 * PUT /presets/{id}
	 */
	public function rest_update_preset( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$preset = GF_Preset_Store::read( $id );

		if ( ! $preset ) {
			return new WP_REST_Response( array( 'message' => __( 'Preset not found.', 'gf-presets' ) ), 404 );
		}

		$params = $request->get_json_params();

		$name = sanitize_text_field( $params['name'] ?? $preset['name'] );
		if ( empty( $name ) || mb_strlen( $name ) > 255 ) {
			return new WP_REST_Response( array( 'message' => __( 'Name is required (max 255 chars).', 'gf-presets' ) ), 400 );
		}

		$payload = $params['payload'] ?? null;
		if ( null !== $payload ) {
			if ( ! is_array( $payload ) && ! is_object( $payload ) ) {
				if ( is_string( $payload ) ) {
					$payload = json_decode( $payload, true );
				}
				if ( ! is_array( $payload ) ) {
					return new WP_REST_Response( array( 'message' => __( 'Payload must be valid JSON.', 'gf-presets' ) ), 400 );
				}
			}

			$validation = $this->validate_payload_structure( $preset['preset_type'], $payload );
			if ( is_wp_error( $validation ) ) {
				return new WP_REST_Response( array( 'message' => $validation->get_error_message() ), 400 );
			}
		} else {
			$payload = json_decode( $preset['payload'], true );
		}

		$description = sanitize_text_field( $params['description'] ?? $preset['description'] );

		$ok = GF_Preset_Store::update( $id, $name, $description, $payload );
		if ( is_wp_error( $ok ) ) {
			return new WP_REST_Response( array( 'message' => $ok->get_error_message() ), 500 );
		}

		// Trigger live link sync.
		$sync_result = GF_Sync_Engine::sync_preset( $id, $payload, $this );

		$updated = GF_Preset_Store::read( $id );
		$updated['sync_result'] = $sync_result;

		return new WP_REST_Response( $updated, 200 );
	}

	/**
	 * DELETE /presets/{id}
	 */
	public function rest_delete_preset( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$preset = GF_Preset_Store::read( $id );

		if ( ! $preset ) {
			return new WP_REST_Response( array( 'message' => __( 'Preset not found.', 'gf-presets' ) ), 404 );
		}

		$links        = GF_Preset_Link_Store::get_links_for_preset( $id );
		$linked_count = count( $links );

		// Delete all links first.
		foreach ( $links as $link ) {
			GF_Preset_Link_Store::remove_link( $link['id'] );
		}

		GF_Preset_Store::delete( $id );

		return new WP_REST_Response( array(
			'message'      => __( 'Preset deleted.', 'gf-presets' ),
			'linked_count' => $linked_count,
		), 200 );
	}

	/**
	 * POST /presets/{id}/apply
	 */
	public function rest_apply_preset( WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$preset = GF_Preset_Store::read( $id );

		if ( ! $preset ) {
			return new WP_REST_Response( array( 'message' => __( 'Preset not found.', 'gf-presets' ) ), 404 );
		}

		$params    = $request->get_json_params();
		$form_id   = absint( $params['form_id'] ?? 0 );
		$object_id = sanitize_text_field( $params['object_id'] ?? '' );
		$mode      = sanitize_text_field( $params['mode'] ?? 'copy' );

		$this->log_debug( sprintf(
			'%s(): Apply preset id=%d, form_id=%d, object_id=%s, mode=%s, raw params=%s',
			__METHOD__,
			$id,
			$form_id,
			$object_id,
			$mode,
			wp_json_encode( $params )
		) );

		if ( ! in_array( $mode, array( 'copy', 'link' ), true ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Mode must be "copy" or "link".', 'gf-presets' ) ), 400 );
		}

		// Verify form exists.
		$form = GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Form not found.', 'gf-presets' ) ), 404 );
		}

		$this->log_debug( sprintf(
			'%s(): Form %d loaded, field count=%d, field IDs=%s',
			__METHOD__,
			$form_id,
			is_array( $form['fields'] ) ? count( $form['fields'] ) : 0,
			wp_json_encode( array_map( function( $f ) {
				return is_object( $f ) ? $f->id : rgar( $f, 'id' );
			}, $form['fields'] ?? array() ) )
		) );

		// Verify object_id exists in the form.
		if ( '' === $object_id ) {
			return new WP_REST_Response( array( 'message' => __( 'object_id is required.', 'gf-presets' ) ), 400 );
		}

		// Check if the form has fields saved (user may need to save the form first).
		if ( 'field' === $preset['preset_type'] && empty( $form['fields'] ) ) {
			return new WP_REST_Response( array( 'message' => __( 'The form has no saved fields. Please save the form first, then apply the preset.', 'gf-presets' ) ), 400 );
		}

		// Prevent duplicate links.
		if ( 'link' === $mode ) {
			$existing = GF_Preset_Link_Store::get_link_for_object( $preset['id'], $form_id, $object_id );
			if ( $existing ) {
				return new WP_REST_Response( array( 'message' => __( 'A link already exists for this object.', 'gf-presets' ) ), 409 );
			}
		}

		$payload      = json_decode( $preset['payload'], true );
		$preset_type  = $preset['preset_type'];
		$apply_result = array();

		$this->log_debug( sprintf(
			'%s(): Preset #%d payload keys=%s, payload=%s',
			__METHOD__,
			$id,
			wp_json_encode( array_keys( $payload ?: array() ) ),
			wp_json_encode( $payload )
		) );

		// Validate field type compatibility before applying.
		if ( 'field' === $preset_type && ! empty( $payload['type'] ) ) {
			$target_field = null;
			foreach ( $form['fields'] as $f ) {
				$fid = is_object( $f ) ? $f->id : rgar( $f, 'id' );
				if ( (string) $fid === (string) $object_id ) {
					$target_field = $f;
					break;
				}
			}
			if ( $target_field ) {
				$target_type = is_object( $target_field ) ? $target_field->type : rgar( $target_field, 'type' );
				if ( $target_type && $target_type !== $payload['type'] ) {
					return new WP_REST_Response( array(
						'message' => sprintf(
							/* translators: %1$s: preset field type, %2$s: target field type */
							__( 'Type mismatch: this preset is for "%1$s" fields but the target field is "%2$s".', 'gf-presets' ),
							$payload['type'],
							$target_type
						),
					), 400 );
				}
			}
		}

		switch ( $preset_type ) {
			case 'field':
				$apply_result = GF_Preset_Field::apply( $form, $object_id, $payload );
				break;
			case 'notification':
				$apply_result = GF_Preset_Notification::apply( $form, $object_id, $payload );
				break;
			case 'confirmation':
				$apply_result = GF_Preset_Confirmation::apply( $form, $object_id, $payload );
				break;
		}

		if ( is_wp_error( $apply_result ) ) {
			$this->log_debug( sprintf(
				'%s(): Apply returned WP_Error: %s',
				__METHOD__,
				$apply_result->get_error_message()
			) );
			return new WP_REST_Response( array( 'message' => $apply_result->get_error_message() ), 400 );
		}

		$this->log_debug( sprintf(
			'%s(): Apply succeeded for %s, saving form %d…',
			__METHOD__,
			$preset_type,
			$form_id
		) );

		// Save the updated form.
		$updated_form = $apply_result['form'];
		$result       = GFAPI::update_form( $updated_form );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Failed to update form.', 'gf-presets' ) ), 500 );
		}

		// In GF 2.5+ confirmations and notifications live in dedicated DB
		// columns.  GFAPI::update_form() does not always persist those
		// columns reliably, so we write them explicitly.
		if ( 'confirmation' === $preset_type && ! empty( $updated_form['confirmations'] ) ) {
			GFFormsModel::update_form_meta( $form_id, $updated_form['confirmations'], 'confirmations' );
		}
		if ( 'notification' === $preset_type && ! empty( $updated_form['notifications'] ) ) {
			GFFormsModel::update_form_meta( $form_id, $updated_form['notifications'], 'notifications' );
		}

		// Create link row if Live Link mode.
		if ( 'link' === $mode ) {
			$hash = GF_Sync_Engine::compute_payload_hash( $payload );
			GF_Preset_Link_Store::add_link( $id, $form_id, $object_id, $hash );
		}

		return new WP_REST_Response( array(
			'message'   => __( 'Preset applied.', 'gf-presets' ),
			'mode'      => $mode,
			'notices'   => $apply_result['notices'] ?? array(),
			'cl_report' => $apply_result['cl_report'] ?? null,
		), 200 );
	}

	/**
	 * GET /links
	 */
	public function rest_list_links( WP_REST_Request $request ) {
		$preset_id = absint( $request->get_param( 'preset_id' ) );
		$links     = GF_Preset_Link_Store::get_links_for_preset( $preset_id );

		// Enrich with form title.
		foreach ( $links as &$link ) {
			$form = GFAPI::get_form( $link['form_id'] );
			$link['form_title'] = ( $form && ! is_wp_error( $form ) ) ? rgar( $form, 'title' ) : __( '(deleted)', 'gf-presets' );
		}

		return new WP_REST_Response( $links, 200 );
	}

	/**
	 * DELETE /links/{id}
	 */
	public function rest_break_link( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$link = GF_Preset_Link_Store::read( $id );

		if ( ! $link ) {
			return new WP_REST_Response( array( 'message' => __( 'Link not found.', 'gf-presets' ) ), 404 );
		}

		GF_Preset_Link_Store::remove_link( $id );

		return new WP_REST_Response( array( 'message' => __( 'Link broken.', 'gf-presets' ) ), 200 );
	}

	/**
	 * GET /links/lookup — Find the active link for a specific form object.
	 */
	public function rest_lookup_link( WP_REST_Request $request ) {
		$form_id   = absint( $request->get_param( 'form_id' ) );
		$object_id = sanitize_text_field( $request->get_param( 'object_id' ) );

		$link = GF_Preset_Link_Store::get_link_by_form_object( $form_id, $object_id );

		if ( ! $link ) {
			return new WP_REST_Response( null, 204 );
		}

		// Enrich with preset name and decoded excluded keys.
		$preset = GF_Preset_Store::read( $link['preset_id'] );
		$link['preset_name']   = $preset ? $preset['name'] : __( '(deleted)', 'gf-presets' );
		$link['excluded_keys'] = GF_Preset_Link_Store::get_excluded_keys( $link['id'] );

		return new WP_REST_Response( $link, 200 );
	}

	/**
	 * PUT /links/{id}/exclusions — Update excluded keys for a link.
	 */
	public function rest_update_link_exclusions( WP_REST_Request $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$link = GF_Preset_Link_Store::read( $id );

		if ( ! $link ) {
			return new WP_REST_Response( array( 'message' => __( 'Link not found.', 'gf-presets' ) ), 404 );
		}

		$params = $request->get_json_params();
		$keys   = isset( $params['excluded_keys'] ) && is_array( $params['excluded_keys'] )
			? array_map( 'sanitize_text_field', $params['excluded_keys'] )
			: array();

		GF_Preset_Link_Store::update_excluded_keys( $id, $keys );

		return new WP_REST_Response( array( 'excluded_keys' => $keys ), 200 );
	}

	/**
	 * GET /extract-payload — Read a saved notification/confirmation/field from
	 * the database and return its cleaned payload (ready for preset storage).
	 *
	 * This avoids fragile DOM-scraping for settings that are not exposed as
	 * simple form inputs (e.g. conditional logic, routing).
	 */
	public function rest_extract_payload( WP_REST_Request $request ) {
		$form_id   = absint( $request->get_param( 'form_id' ) );
		$object_id = sanitize_text_field( $request->get_param( 'object_id' ) );
		$type      = sanitize_text_field( $request->get_param( 'type' ) );

		$form = GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Form not found.', 'gf-presets' ) ), 404 );
		}

		$object = GF_Sync_Engine::extract_object( $form, $type, $object_id );
		if ( null === $object ) {
			return new WP_REST_Response( array(
				'message' => sprintf(
					/* translators: %s: object type (Field, Notification, or Confirmation) */
					__( '%s not found in the form.', 'gf-presets' ),
					ucfirst( $type )
				),
			), 404 );
		}

		// Clean the payload using the type-specific prepare_for_save method.
		switch ( $type ) {
			case 'field':
				$payload = GF_Preset_Field::prepare_for_save( $object, $form );
				break;
			case 'notification':
				$payload = GF_Preset_Notification::prepare_for_save( $object );
				break;
			case 'confirmation':
				$payload = GF_Preset_Confirmation::prepare_for_save( $object );
				break;
			default:
				return new WP_REST_Response( array( 'message' => __( 'Unknown type.', 'gf-presets' ) ), 400 );
		}

		return new WP_REST_Response( array( 'payload' => $payload ), 200 );
	}

	/**
	 * POST /links/sync-from-source — Called by the toolbar JS on page load
	 * when a linked confirmation/notification has been saved in GF.
	 *
	 * Reads the current object from the DB, compares with the stored preset
	 * payload, and if different, updates the preset and syncs to all other
	 * linked forms.
	 */
	public function rest_sync_from_source( WP_REST_Request $request ) {
		$params    = $request->get_json_params();
		$form_id   = absint( $params['form_id'] ?? 0 );
		$object_id = sanitize_text_field( $params['object_id'] ?? '' );

		if ( ! $form_id || ! $object_id ) {
			return new WP_REST_Response( array( 'message' => __( 'form_id and object_id required.', 'gf-presets' ) ), 400 );
		}

		// Find the link for this object.
		$link = GF_Preset_Link_Store::get_link_by_form_object( $form_id, $object_id );
		if ( ! $link ) {
			return new WP_REST_Response( array( 'message' => __( 'No link found.', 'gf-presets' ), 'synced' => false ), 200 );
		}

		$preset = GF_Preset_Store::read( $link['preset_id'] );
		if ( ! $preset ) {
			return new WP_REST_Response( array( 'message' => __( 'Preset not found.', 'gf-presets' ) ), 404 );
		}

		$type = $preset['preset_type'];
		$form = GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return new WP_REST_Response( array( 'message' => __( 'Form not found.', 'gf-presets' ) ), 404 );
		}

		// Extract the current object from the DB.
		$current_object = GF_Sync_Engine::extract_object( $form, $type, $object_id );
		if ( null === $current_object ) {
			return new WP_REST_Response( array( 'message' => __( 'Object not found in form.', 'gf-presets' ) ), 404 );
		}

		// Build clean payload (strip identity keys).
		$current_payload = GF_Sync_Engine::strip_identity_keys( $type, $current_object );

		// Compare with stored preset payload.
		$stored_payload = json_decode( $preset['payload'], true );
		$new_hash       = GF_Sync_Engine::compute_payload_hash( $current_payload );
		$stored_hash    = GF_Sync_Engine::compute_payload_hash( $stored_payload );

		if ( $new_hash === $stored_hash ) {
			$this->log_debug( __METHOD__ . "(): Preset #{$preset['id']} unchanged. No sync needed." );
			return new WP_REST_Response( array( 'synced' => false, 'message' => __( 'No changes detected.', 'gf-presets' ) ), 200 );
		}

		$this->log_debug( __METHOD__ . "(): Preset #{$preset['id']} changed via form #{$form_id}. Updating and syncing." );

		// Update the preset's stored payload.
		$ok = GF_Preset_Store::update( $preset['id'], $preset['name'], $preset['description'], $current_payload );
		if ( is_wp_error( $ok ) ) {
			return new WP_REST_Response( array( 'message' => $ok->get_error_message() ), 500 );
		}

		// Update the source link's hash.
		GF_Preset_Link_Store::update_sync_hash( $link['id'], $new_hash );

		// Sync to all other linked forms.
		$sync_result = GF_Sync_Engine::sync_preset( $preset['id'], $current_payload, $this, $form_id );

		$this->log_debug( sprintf(
			'%s(): Sync result: synced=%d, skipped=%d, conflicts=%d, errors=%d',
			__METHOD__,
			$sync_result['synced'],
			$sync_result['skipped'],
			count( $sync_result['conflicts'] ),
			count( $sync_result['errors'] )
		) );

		return new WP_REST_Response( array(
			'synced'      => true,
			'sync_result' => $sync_result,
		), 200 );
	}

	// ───────────────────────── Payload Validation ──────────────────────────────

	/**
	 * Validate that the payload has the minimum required keys for its type.
	 *
	 * @param string $type    Preset type.
	 * @param array  $payload Decoded payload.
	 * @return true|WP_Error
	 */
	private function validate_payload_structure( $type, $payload ) {
		switch ( $type ) {
			case 'field':
				if ( empty( $payload['type'] ) || empty( $payload['label'] ) ) {
					return new WP_Error( 'invalid_payload', __( 'Field preset payload must contain "type" and "label".', 'gf-presets' ) );
				}
				break;

			case 'notification':
				if ( ! isset( $payload['to'] ) || ! isset( $payload['subject'] ) || ! isset( $payload['message'] ) ) {
					return new WP_Error( 'invalid_payload', __( 'Notification preset payload must contain "to", "subject", and "message".', 'gf-presets' ) );
				}
				break;

			case 'confirmation':
				if ( empty( $payload['type'] ) || ! in_array( $payload['type'], array( 'message', 'redirect', 'page' ), true ) ) {
					return new WP_Error( 'invalid_payload', __( 'Confirmation preset payload must contain "type" (message, redirect, or page).', 'gf-presets' ) );
				}
				break;

			default:
				return new WP_Error( 'invalid_type', __( 'Unknown preset type.', 'gf-presets' ) );
		}

		return true;
	}

	// ───────────────────────── Orphan Cleanup ──────────────────────────────────

	/**
	 * Delete all preset links for a deleted form.
	 *
	 * @param int $form_id The deleted form ID.
	 */
	public function cleanup_orphan_links( $form_id ) {
		GF_Preset_Link_Store::delete_links_for_form( absint( $form_id ) );
		$this->log_debug( __METHOD__ . '(): Cleaned up links for deleted form #' . $form_id );
	}

	// ───────────────────────── Form Save → Preset Sync ─────────────────────────

	/**
	 * When a form is saved in the editor, check for live-linked objects.
	 * If any linked object was modified, update the preset payload and
	 * sync the change to all other linked forms.
	 *
	 * Hooked to gform_after_save_form (priority 20).
	 *
	 * @param array $form    The saved form object.
	 * @param bool  $is_new  Whether this is a newly created form.
	 */
	public function on_form_saved( $form, $is_new ) {
		if ( $is_new ) {
			return;
		}

		try {
			$this->process_form_sync( $form );
		} catch ( \Exception $e ) {
			$this->log_error( __METHOD__ . '(): Unexpected error: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			$this->log_error( __METHOD__ . '(): Fatal error: ' . $e->getMessage() );
		}
	}

	/**
	 * Internal: check linked objects in a saved form and propagate changes.
	 *
	 * @param array $form The saved form object.
	 */
	private function process_form_sync( $form ) {
		$form_id = absint( rgar( $form, 'id' ) );
		$links   = GF_Preset_Link_Store::get_links_for_form( $form_id );

		if ( empty( $links ) ) {
			return;
		}

		$this->log_debug( __METHOD__ . "(): Form #{$form_id} saved with " . count( $links ) . ' live link(s). Checking for changes.' );

		// Detect confirmation presets with multiple same-form links (siblings).
		// Their conditionalLogic is expected to differ, so exclude it from
		// the change-detection hash to avoid false-positive updates.
		$preset_link_counts = array();
		foreach ( $links as $link ) {
			$pid = $link['preset_id'];
			$preset_link_counts[ $pid ] = isset( $preset_link_counts[ $pid ] ) ? $preset_link_counts[ $pid ] + 1 : 1;
		}

		// Track which presets have already been updated in this pass to
		// prevent flip-flopping when multiple same-form siblings exist.
		$updated_presets = array();

		foreach ( $links as $link ) {
			$preset = GF_Preset_Store::read( $link['preset_id'] );
			if ( ! $preset ) {
				continue;
			}

			$type = $preset['preset_type'];

			// Extract the current object from the just-saved form.
			$current_object = GF_Sync_Engine::extract_object( $form, $type, $link['object_id'] );

			if ( null === $current_object ) {
				$this->log_debug( __METHOD__ . "(): Object {$link['object_id']} not found in form #{$form_id}. Skipping." );
				continue;
			}

			// Build a clean payload (strip identity keys like the JS does on save).
			// For field presets, use prepare_for_save() which also enriches
			// conditional logic rules with _sourceLabel for cross-form remapping.
			if ( 'field' === $type ) {
				$payload = GF_Preset_Field::prepare_for_save( $current_object, $form );
			} else {
				$payload = GF_Sync_Engine::strip_identity_keys( $type, $current_object );
			}

			// Check if the payload actually changed compared to the stored preset.
			$stored_payload = json_decode( $preset['payload'], true );

			// For confirmations with same-form siblings, exclude conditionalLogic
			// from comparison so that expected CL differences don't trigger
			// false updates and flip-flopping between siblings.
			$has_siblings = 'confirmation' === $type && $preset_link_counts[ $link['preset_id'] ] > 1;

			$compare_payload = $payload;
			$compare_stored  = $stored_payload;
			if ( $has_siblings ) {
				unset( $compare_payload['conditionalLogic'] );
				unset( $compare_stored['conditionalLogic'] );
			}

			$new_hash    = GF_Sync_Engine::compute_payload_hash( $compare_payload );
			$stored_hash = GF_Sync_Engine::compute_payload_hash( $compare_stored );

			if ( $new_hash === $stored_hash ) {
				$this->log_debug( __METHOD__ . "(): Preset #{$preset['id']} payload unchanged. No sync needed." );
				continue;
			}

			// If another sibling already updated this preset, skip to avoid
			// overwriting with a different sibling's values.
			if ( isset( $updated_presets[ $link['preset_id'] ] ) ) {
				$this->log_debug( __METHOD__ . "(): Preset #{$preset['id']} already updated by a sibling link. Skipping." );
				continue;
			}

			$this->log_debug( __METHOD__ . "(): Preset #{$preset['id']} payload changed via form #{$form_id}. Updating and syncing." );

			// Update the preset's stored payload.
			$ok = GF_Preset_Store::update( $preset['id'], $preset['name'], $preset['description'], $payload );
			if ( is_wp_error( $ok ) ) {
				$this->log_error( __METHOD__ . "(): Failed to update preset #{$preset['id']}: " . $ok->get_error_message() );
				continue;
			}

			$updated_presets[ $link['preset_id'] ] = true;

			// Update the source link's hash so the sync engine won't treat this form as conflicted.
			$full_hash = GF_Sync_Engine::compute_payload_hash( $payload );
			GF_Preset_Link_Store::update_sync_hash( $link['id'], $full_hash );

			// Sync the updated payload to all other linked forms (and same-form siblings).
			$sync_result = GF_Sync_Engine::sync_preset( $preset['id'], $payload, $this, $form_id );

			$this->log_debug( sprintf(
				'%s(): Sync result for preset #%d: synced=%d, skipped=%d, conflicts=%d, errors=%d',
				__METHOD__,
				$preset['id'],
				$sync_result['synced'],
				$sync_result['skipped'],
				count( $sync_result['conflicts'] ),
				count( $sync_result['errors'] )
			) );
		}
	}
}
