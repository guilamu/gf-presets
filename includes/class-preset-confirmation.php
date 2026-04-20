<?php
/**
 * GF Preset Confirmation — Apply logic for confirmation presets.
 *
 * Handles applying a confirmation preset payload to a form confirmation,
 * including merge tag scanning and property merging.
 */

defined( 'ABSPATH' ) || exit;

class GF_Preset_Confirmation {

	/**
	 * Properties that must never be overwritten when applying a preset.
	 */
	private static $preserved_keys = array( 'name', 'id' );

	/**
	 * Apply a confirmation preset to a form.
	 *
	 * @param array  $form      The form object.
	 * @param string $object_id The target confirmation GUID.
	 * @param array  $payload   The preset payload (decoded).
	 * @return array|WP_Error {
	 *     @type array $form    Updated form object.
	 *     @type array $notices Warning/info notices.
	 * }
	 */
	public static function apply( $form, $object_id, $payload ) {
		if ( empty( $form['confirmations'] ) ) {
			return new WP_Error( 'conf_not_found', __( 'No confirmations in this form.', 'gf-presets' ) );
		}

		$found = false;

		foreach ( $form['confirmations'] as $guid => &$conf ) {
			if ( $guid === $object_id || rgar( $conf, 'id' ) === $object_id ) {
				// Overwrite all properties except name and id.
				foreach ( $payload as $key => $value ) {
					if ( in_array( $key, self::$preserved_keys, true ) ) {
						continue;
					}
					$conf[ $key ] = $value;
				}

				$found = true;
				break;
			}
		}
		unset( $conf );

		if ( ! $found ) {
			return new WP_Error( 'conf_not_found', sprintf(
				/* translators: %s: confirmation ID */
				__( 'Confirmation %s not found in the form.', 'gf-presets' ),
				$object_id
			) );
		}

		$notices = array();

		// Scan for field-specific merge tags.
		$merge_tags = GF_Merge_Tag_Scanner::scan_payload( $payload );
		if ( ! empty( $merge_tags ) ) {
			$notices[] = sprintf(
				/* translators: %s: comma-separated list of merge tags */
				__( 'This confirmation contains field-specific merge tags: %s — Review and update them after applying.', 'gf-presets' ),
				implode( ', ', array_map( function ( $tag ) { return '<code>' . esc_html( $tag ) . '</code>'; }, $merge_tags ) )
			);
		}

		return array(
			'form'    => $form,
			'notices' => $notices,
		);
	}

	/**
	 * Prepare a confirmation for saving as a preset.
	 *
	 * Strips identity keys.
	 *
	 * @param array $confirmation The confirmation object.
	 * @return array Cleaned payload ready for storage.
	 */
	public static function prepare_for_save( $confirmation ) {
		// Remove identity keys.
		unset( $confirmation['name'], $confirmation['id'] );

		return $confirmation;
	}
}
