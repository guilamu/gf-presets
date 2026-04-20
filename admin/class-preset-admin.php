<?php
/**
 * GF Presets Admin — Preset Library page renderer.
 *
 * Renders the custom admin page registered via plugin_page().
 * Contains the card list, tabs, inline editor, and modal templates.
 */

defined( 'ABSPATH' ) || exit;

class GF_Presets_Admin {

	/**
	 * Render the Preset Library page.
	 */
	public function render() {
		?>
		<div class="wrap gf-presets-library" id="gf-presets-library">

			<!-- Tabs -->
			<nav class="gf-presets-tabs" id="gf-presets-tabs">
				<button type="button" class="gf-presets-tab active" data-type="all">
					<?php esc_html_e( 'All', 'gf-presets' ); ?>
				</button>
				<button type="button" class="gf-presets-tab" data-type="field">
					<?php esc_html_e( 'Fields', 'gf-presets' ); ?>
				</button>
				<button type="button" class="gf-presets-tab" data-type="notification">
					<?php esc_html_e( 'Notifications', 'gf-presets' ); ?>
				</button>
				<button type="button" class="gf-presets-tab" data-type="confirmation">
					<?php esc_html_e( 'Confirmations', 'gf-presets' ); ?>
				</button>
			</nav>

			<!-- Toolbar -->
			<div class="gf-presets-toolbar">
				<button type="button" class="button button-primary" id="gf-presets-new-btn">
					<span class="dashicons dashicons-plus-alt2" style="vertical-align: middle; margin-right: 4px;"></span>
					<?php esc_html_e( 'New Preset', 'gf-presets' ); ?>
				</button>
				<div class="gf-presets-search-wrap">
					<input type="search"
					       id="gf-presets-search"
					       class="gf-presets-search"
					       placeholder="<?php esc_attr_e( 'Search presets…', 'gf-presets' ); ?>"
					/>
				</div>
			</div>

			<!-- Preset List -->
			<div class="gf-presets-list" id="gf-presets-list">
				<div class="gf-presets-loading">
					<span class="spinner is-active" style="float: none;"></span>
					<?php esc_html_e( 'Loading presets…', 'gf-presets' ); ?>
				</div>
			</div>

			<!-- Empty State -->
			<div class="gf-presets-empty" id="gf-presets-empty" style="display: none;">
				<div class="gf-presets-empty-icon">📋</div>
				<h3><?php esc_html_e( 'No presets yet', 'gf-presets' ); ?></h3>
				<p><?php esc_html_e( 'Save a field, notification, or confirmation as a preset to start building your library.', 'gf-presets' ); ?></p>
			</div>
		</div>

		<?php
		// Include modal templates.
		require_once GF_PRESETS_DIR . 'admin/views/modal-save.php';
		require_once GF_PRESETS_DIR . 'admin/views/modal-apply.php';

		// Toast container.
		?>
		<div id="gf-presets-toast-container" class="gf-presets-toast-container"></div>
		<?php
	}
}
