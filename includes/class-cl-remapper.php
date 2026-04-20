<?php
/**
 * GF CL Remapper — Conditional Logic label-based remapping.
 *
 * When a field preset is saved, each CL rule stores the source field's label as
 * `_sourceLabel`. When applying to a target form, this class matches labels in
 * the target form and remaps field IDs accordingly.
 */

defined( 'ABSPATH' ) || exit;

class GF_CL_Remapper {

	/**
	 * Enrich conditional logic rules with source labels on SAVE.
	 *
	 * For each CL rule referencing a fieldId, store the label of that field
	 * from the source form as `_sourceLabel`.
	 *
	 * @param array $conditional_logic The CL object from the field.
	 * @param array $form              The source form.
	 * @return array Enriched CL object.
	 */
	public static function enrich_on_save( $conditional_logic, $form ) {
		if ( empty( $conditional_logic ) || empty( $conditional_logic['rules'] ) ) {
			return $conditional_logic;
		}

		// Build a field ID → label map from the form.
		$label_map = self::build_label_map( $form );

		foreach ( $conditional_logic['rules'] as &$rule ) {
			$field_id = rgar( $rule, 'fieldId' );
			if ( $field_id && isset( $label_map[ $field_id ] ) ) {
				$rule['_sourceLabel'] = $label_map[ $field_id ];
			}
		}

		return $conditional_logic;
	}

	/**
	 * Remap conditional logic rules when APPLYING to a target form.
	 *
	 * For each rule:
	 *   1. Match _sourceLabel → field label in target form
	 *   2. Match found → remap fieldId, remove _sourceLabel
	 *   3. No match → drop the rule, add to dropped list
	 *   4. All rules dropped → return null for conditionalLogic
	 *
	 * @param array|null $conditional_logic The CL object from the preset payload.
	 * @param array      $target_form      The target form.
	 * @return array {
	 *     @type array|null $conditional_logic Remapped CL (or null if all dropped).
	 *     @type array      $remapped          Label → target field ID mappings made.
	 *     @type array      $dropped           Labels that had no match in target form.
	 * }
	 */
	public static function remap_on_apply( $conditional_logic, $target_form ) {
		$result = array(
			'conditional_logic' => null,
			'remapped'          => array(),
			'dropped'           => array(),
		);

		if ( empty( $conditional_logic ) || empty( $conditional_logic['rules'] ) ) {
			return $result;
		}

		// Build a label → field ID map from the target form.
		$target_map = self::build_reverse_label_map( $target_form );

		$kept_rules = array();

		foreach ( $conditional_logic['rules'] as $rule ) {
			$source_label = rgar( $rule, '_sourceLabel' );

			if ( empty( $source_label ) ) {
				// No label stored — try to keep the rule as-is (best effort).
				$kept_rules[] = $rule;
				continue;
			}

			$target_ids = rgar( $target_map, $source_label );

			if ( ! $target_ids ) {
				// No match — drop the rule.
				$result['dropped'][] = $source_label;
				continue;
			}

			if ( count( $target_ids ) > 1 ) {
				// Ambiguous: same label on multiple fields → drop + warn.
				$result['dropped'][] = $source_label . ' (ambiguous)';
				continue;
			}

			// Exact match — remap.
			$rule['fieldId'] = $target_ids[0];
			unset( $rule['_sourceLabel'] );
			$kept_rules[] = $rule;

			$result['remapped'][ $source_label ] = $target_ids[0];
		}

		if ( ! empty( $kept_rules ) ) {
			$conditional_logic['rules'] = $kept_rules;
			$result['conditional_logic'] = $conditional_logic;
		}

		return $result;
	}

	/**
	 * Build a field ID → label map from form fields.
	 *
	 * @param array $form Form object.
	 * @return array
	 */
	private static function build_label_map( $form ) {
		$map = array();

		if ( empty( $form['fields'] ) ) {
			return $map;
		}

		foreach ( $form['fields'] as $field ) {
			$id    = is_object( $field ) ? $field->id : rgar( $field, 'id' );
			$label = is_object( $field ) ? $field->label : rgar( $field, 'label' );

			if ( $id && $label ) {
				$map[ $id ] = $label;
			}

			// Include sub-input labels for multi-input fields (name, address, etc.)
			$inputs = is_object( $field ) ? $field->inputs : rgar( $field, 'inputs' );
			if ( is_array( $inputs ) ) {
				foreach ( $inputs as $input ) {
					$input_id    = rgar( $input, 'id' );
					$input_label = rgar( $input, 'label' );
					if ( $input_id && $input_label ) {
						$map[ $input_id ] = $label . ' (' . $input_label . ')';
					}
				}
			}
		}

		return $map;
	}

	/**
	 * Build a label → [field IDs] reverse map from form fields.
	 *
	 * Returns an array where labels map to arrays of field IDs,
	 * to detect ambiguity (same label on multiple fields).
	 *
	 * @param array $form Form object.
	 * @return array
	 */
	private static function build_reverse_label_map( $form ) {
		$map = array();

		if ( empty( $form['fields'] ) ) {
			return $map;
		}

		foreach ( $form['fields'] as $field ) {
			$id    = is_object( $field ) ? $field->id : rgar( $field, 'id' );
			$label = is_object( $field ) ? $field->label : rgar( $field, 'label' );

			if ( $id && $label ) {
				if ( ! isset( $map[ $label ] ) ) {
					$map[ $label ] = array();
				}
				$map[ $label ][] = $id;
			}
		}

		return $map;
	}
}
