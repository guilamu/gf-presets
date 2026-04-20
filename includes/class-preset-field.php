<?php
/**
 * GF Preset Field — Apply logic for field presets.
 *
 * Handles applying a field preset payload to a form field,
 * including CL remapping and property merging.
 */

defined( 'ABSPATH' ) || exit;

class GF_Preset_Field {

	/**
	 * Properties that must never be overwritten when applying a preset.
	 * These are identity/position properties that belong to the target field.
	 */
	private static $preserved_keys = array( 'id', 'formId', 'pageNumber' );

	/**
	 * Apply a field preset to a form.
	 *
	 * @param array  $form      The form object.
	 * @param string $object_id The target field ID.
	 * @param array  $payload   The preset payload (decoded).
	 * @return array|WP_Error {
	 *     @type array      $form      Updated form object.
	 *     @type array      $notices   Warning/info notices for the user.
	 *     @type array|null $cl_report CL remapping report.
	 * }
	 */
	public static function apply( $form, $object_id, $payload ) {
		$field_index = null;

		// Find the target field.
		foreach ( $form['fields'] as $index => $field ) {
			$fid = is_object( $field ) ? $field->id : rgar( $field, 'id' );

			// Debug: log field iteration for troubleshooting.
			if ( class_exists( 'GFAddOn' ) && method_exists( 'GFAddOn', 'get_instance' ) ) {
				GF_Presets::get_instance()->log_debug( sprintf(
					'%s(): Checking field index=%d, fid=%s (type=%s), object_id=%s (type=%s), match=%s',
					__METHOD__,
					$index,
					var_export( $fid, true ),
					gettype( $fid ),
					var_export( $object_id, true ),
					gettype( $object_id ),
					(string) $fid === (string) $object_id ? 'YES' : 'NO'
				) );
			}

			if ( (string) $fid === (string) $object_id ) {
				$field_index = $index;
				break;
			}
		}

		if ( null === $field_index ) {
			return new WP_Error(
				'field_not_found',
				sprintf(
					/* translators: %s: field ID */
					__( 'Field %s not found in the form. If you just added this field, please save the form first and then apply the preset.', 'gf-presets' ),
					$object_id
				)
			);
		}

		$notices   = array();
		$cl_report = null;

		// Apply all payload properties except preserved keys.
		foreach ( $payload as $key => $value ) {
			if ( in_array( $key, self::$preserved_keys, true ) ) {
				continue;
			}

			if ( is_object( $form['fields'][ $field_index ] ) ) {
				$form['fields'][ $field_index ]->{$key} = $value;
			} else {
				$form['fields'][ $field_index ][ $key ] = $value;
			}
		}

		// CL remapping.
		$cl = is_object( $form['fields'][ $field_index ] )
			? $form['fields'][ $field_index ]->conditionalLogic
			: rgar( $form['fields'][ $field_index ], 'conditionalLogic' );

		if ( ! empty( $cl ) && ! empty( $cl['rules'] ) ) {
			$remap_result = GF_CL_Remapper::remap_on_apply( $cl, $form );

			// Update the field's CL.
			if ( is_object( $form['fields'][ $field_index ] ) ) {
				$form['fields'][ $field_index ]->conditionalLogic = $remap_result['conditional_logic'];
			} else {
				$form['fields'][ $field_index ]['conditionalLogic'] = $remap_result['conditional_logic'];
			}

			$cl_report = $remap_result;

			// Build user-facing notices.
			if ( ! empty( $remap_result['remapped'] ) ) {
				foreach ( $remap_result['remapped'] as $label => $target_id ) {
					$notices[] = sprintf(
						/* translators: 1: field label, 2: field ID */
						__( 'CL remapped: "%1$s" → field #%2$s.', 'gf-presets' ),
						$label,
						$target_id
					);
				}
			}

			if ( ! empty( $remap_result['dropped'] ) ) {
				foreach ( $remap_result['dropped'] as $label ) {
					$notices[] = sprintf(
						/* translators: %s: field label */
						__( 'CL dropped: "%s" (no match in this form).', 'gf-presets' ),
						$label
					);
				}
			}
		}

		return array(
			'form'      => $form,
			'notices'   => $notices,
			'cl_report' => $cl_report,
		);
	}

	/**
	 * Prepare a field for saving as a preset.
	 *
	 * Strips identity keys and enriches CL with source labels.
	 *
	 * @param array $field The field object (as array).
	 * @param array $form  The source form.
	 * @return array Cleaned payload ready for storage.
	 */
	public static function prepare_for_save( $field, $form ) {
		// Convert to array if object.
		if ( is_object( $field ) ) {
			$field = json_decode( wp_json_encode( $field ), true );
		}

		// Remove identity keys that are form-specific.
		unset( $field['id'], $field['formId'], $field['pageNumber'] );

		// Enrich CL rules with source labels.
		if ( ! empty( $field['conditionalLogic'] ) ) {
			$field['conditionalLogic'] = GF_CL_Remapper::enrich_on_save(
				$field['conditionalLogic'],
				$form
			);
		}

		return $field;
	}
}
