<?php
/**
 * GF Preset Notification — Apply logic for notification presets.
 *
 * Handles applying a notification preset payload to a form notification,
 * including merge tag scanning and property merging.
 */

defined( 'ABSPATH' ) || exit;

class GF_Preset_Notification {

	/**
	 * Properties that must never be overwritten when applying a preset.
	 */
	private static $preserved_keys = array( 'name', 'id' );

	/**
	 * Apply a notification preset to a form.
	 *
	 * @param array  $form      The form object.
	 * @param string $object_id The target notification GUID.
	 * @param array  $payload   The preset payload (decoded).
	 * @return array|WP_Error {
	 *     @type array $form    Updated form object.
	 *     @type array $notices Warning/info notices.
	 * }
	 */
	public static function apply( $form, $object_id, $payload ) {
		if ( empty( $form['notifications'] ) ) {
			return new WP_Error( 'notif_not_found', __( 'No notifications in this form.', 'gf-presets' ) );
		}

		$found = false;

		foreach ( $form['notifications'] as $guid => &$notif ) {
			if ( $guid === $object_id || rgar( $notif, 'id' ) === $object_id ) {
				// Overwrite all properties except name and id.
				foreach ( $payload as $key => $value ) {
					if ( in_array( $key, self::$preserved_keys, true ) ) {
						continue;
					}
					$notif[ $key ] = $value;
				}

				$found = true;
				break;
			}
		}
		unset( $notif );

		if ( ! $found ) {
			return new WP_Error( 'notif_not_found', sprintf(
				/* translators: %s: notification ID */
				__( 'Notification %s not found in the form.', 'gf-presets' ),
				$object_id
			) );
		}

		$notices = array();

		// Scan for field-specific merge tags.
		$merge_tags = GF_Merge_Tag_Scanner::scan_payload( $payload );
		if ( ! empty( $merge_tags ) ) {
			$notices[] = sprintf(
				/* translators: %s: comma-separated list of merge tags */
				__( 'This notification contains field-specific merge tags: %s — Review and update them after applying.', 'gf-presets' ),
				implode( ', ', array_map( function ( $tag ) { return '<code>' . esc_html( $tag ) . '</code>'; }, $merge_tags ) )
			);
		}

		return array(
			'form'    => $form,
			'notices' => $notices,
		);
	}

	/**
	 * Prepare a notification for saving as a preset.
	 *
	 * Strips identity keys.
	 *
	 * @param array $notification The notification object.
	 * @return array Cleaned payload ready for storage.
	 */
	public static function prepare_for_save( $notification ) {
		// Remove identity keys.
		unset( $notification['name'], $notification['id'] );

		return $notification;
	}
}
