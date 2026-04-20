<?php
/**
 * Apply Preset Modal Template.
 *
 * Shared modal for applying a preset to a form object (field, notification, confirmation).
 * Shows Copy vs Live Link radio, merge tag warning, and CL remapping note.
 * Hidden by default; shown via JS.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="gf-presets-modal-overlay" id="gf-presets-modal-apply" style="display: none;">
	<div class="gf-presets-modal">
		<div class="gf-presets-modal-header">
			<h3>
				<?php esc_html_e( 'Apply Preset:', 'gf-presets' ); ?>
				<span id="gf-presets-apply-name" class="gf-presets-apply-name"></span>
			</h3>
			<button type="button" class="gf-presets-modal-close" data-modal="gf-presets-modal-apply" aria-label="<?php esc_attr_e( 'Close', 'gf-presets' ); ?>">✕</button>
		</div>
		<div class="gf-presets-modal-body">
			<p><?php esc_html_e( 'How do you want to apply this preset?', 'gf-presets' ); ?></p>

			<!-- Copy / Live Link Radio -->
			<div class="gf-presets-mode-choices">
				<label class="gf-presets-mode-choice">
					<input type="radio" name="gf-presets-apply-mode" value="copy" checked />
					<span class="gf-presets-mode-label">
						<strong><?php esc_html_e( 'Copy', 'gf-presets' ); ?></strong>
						<span class="gf-presets-mode-desc"><?php esc_html_e( 'Independent copy. No future sync.', 'gf-presets' ); ?></span>
					</span>
				</label>
				<label class="gf-presets-mode-choice">
					<input type="radio" name="gf-presets-apply-mode" value="link" />
					<span class="gf-presets-mode-label">
						<strong><?php esc_html_e( 'Live Link', 'gf-presets' ); ?></strong>
						<span class="gf-presets-mode-desc"><?php esc_html_e( 'Stays synced with the preset.', 'gf-presets' ); ?></span>
					</span>
				</label>
			</div>

			<!-- Live Link warning -->
			<div class="gf-presets-notice gf-presets-notice-warning" id="gf-presets-apply-link-warning" style="display: none;">
				<span class="dashicons dashicons-warning"></span>
				<?php esc_html_e( 'Changes to the preset will overwrite this object in all linked forms automatically.', 'gf-presets' ); ?>
			</div>

			<hr class="gf-presets-divider" />

			<!-- Merge Tag Warning (shown only when merge tags detected) -->
			<div class="gf-presets-notice gf-presets-notice-warning" id="gf-presets-apply-merge-warning" style="display: none;">
				<span class="dashicons dashicons-warning"></span>
				<div>
					<strong><?php esc_html_e( 'Merge Tag Warning', 'gf-presets' ); ?></strong>
					<p><?php esc_html_e( 'This preset contains field-specific merge tags:', 'gf-presets' ); ?></p>
					<div id="gf-presets-apply-merge-tags" class="gf-presets-merge-tag-chips"></div>
					<p class="gf-presets-notice-detail"><?php esc_html_e( 'These field IDs may not exist in this form. Review and update them after applying.', 'gf-presets' ); ?></p>
				</div>
			</div>

			<!-- CL Remapping note (field presets only) -->
			<div class="gf-presets-notice gf-presets-notice-info" id="gf-presets-apply-cl-note" style="display: none;">
				<span class="dashicons dashicons-info-outline"></span>
				<?php esc_html_e( 'Conditional logic will be remapped by matching field labels. Rules referencing fields not found in this form will be dropped.', 'gf-presets' ); ?>
			</div>

			<!-- Hidden fields -->
			<input type="hidden" id="gf-presets-apply-id" value="" />
			<input type="hidden" id="gf-presets-apply-form-id" value="" />
			<input type="hidden" id="gf-presets-apply-object-id" value="" />
		</div>
		<div class="gf-presets-modal-footer">
			<button type="button" class="button gf-presets-modal-cancel" data-modal="gf-presets-modal-apply">
				<?php esc_html_e( 'Cancel', 'gf-presets' ); ?>
			</button>
			<button type="button" class="button button-primary" id="gf-presets-apply-submit">
				<?php esc_html_e( 'Apply Preset', 'gf-presets' ); ?>
			</button>
		</div>
	</div>
</div>
