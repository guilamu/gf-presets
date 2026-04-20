<?php
/**
 * Save Preset Modal Template.
 *
 * Used when saving a field, notification, or confirmation as a new preset.
 * Hidden by default; shown via JS.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="gf-presets-modal-overlay" id="gf-presets-modal-save" style="display: none;">
	<div class="gf-presets-modal">
		<div class="gf-presets-modal-header">
			<h3><?php esc_html_e( 'Save as Preset', 'gf-presets' ); ?></h3>
			<button type="button" class="gf-presets-modal-close" data-modal="gf-presets-modal-save" aria-label="<?php esc_attr_e( 'Close', 'gf-presets' ); ?>">✕</button>
		</div>
		<div class="gf-presets-modal-body">
			<div class="gf-presets-form-group">
				<label for="gf-presets-save-name"><?php esc_html_e( 'Name', 'gf-presets' ); ?> <span class="required">*</span></label>
				<input type="text" id="gf-presets-save-name" class="gf-presets-input" maxlength="255" required />
			</div>

			<div class="gf-presets-form-group">
				<label for="gf-presets-save-desc"><?php esc_html_e( 'Description (optional)', 'gf-presets' ); ?></label>
				<textarea id="gf-presets-save-desc" class="gf-presets-textarea" rows="3"></textarea>
			</div>

			<!-- CL notice for field presets -->
			<div class="gf-presets-notice gf-presets-notice-info" id="gf-presets-save-cl-notice" style="display: none;">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Conditional logic rules will be saved with label mapping for portability across forms.', 'gf-presets' ); ?>
			</div>

			<!-- Hidden fields -->
			<input type="hidden" id="gf-presets-save-type" value="" />
			<input type="hidden" id="gf-presets-save-payload" value="" />
		</div>
		<div class="gf-presets-modal-footer">
			<button type="button" class="button gf-presets-modal-cancel" data-modal="gf-presets-modal-save">
				<?php esc_html_e( 'Cancel', 'gf-presets' ); ?>
			</button>
			<button type="button" class="button button-primary" id="gf-presets-save-submit">
				<?php esc_html_e( 'Save Preset', 'gf-presets' ); ?>
			</button>
		</div>
	</div>
</div>
