<?php
/**
 * GF Sync Engine — Batch sync for live-linked presets.
 *
 * Called when a preset is updated (PUT /presets/{id}).
 * Iterates all linked form objects and pushes the new payload.
 * Includes hash-based conflict detection and orphan cleanup.
 */

defined( 'ABSPATH' ) || exit;

class GF_Sync_Engine {

	/**
	 * Sync a preset's payload to all live-linked form objects.
	 *
	 * @param int       $preset_id Preset ID.
	 * @param array     $payload   New preset payload (decoded).
	 * @param GF_Presets $addon     Addon instance for logging.
	 * @return array {
	 *     @type int   $synced    Number of successfully synced objects.
	 *     @type int   $skipped   Number of skipped (orphaned) objects.
	 *     @type array $conflicts Array of conflicted form IDs with messages.
	 *     @type array $errors    Array of error messages.
	 * }
	 */
	public static function sync_preset( $preset_id, $payload, $addon = null, $exclude_form_id = 0 ) {
		$links = GF_Preset_Link_Store::get_links_for_preset( $preset_id );

		$result = array(
			'synced'    => 0,
			'skipped'   => 0,
			'conflicts' => array(),
			'errors'    => array(),
		);

		if ( empty( $links ) ) {
			return $result;
		}

		$preset    = GF_Preset_Store::read( $preset_id );
		$type      = $preset ? $preset['preset_type'] : '';
		$new_hash  = self::compute_payload_hash( $payload );

		foreach ( $links as $link ) {
			$is_same_form = $exclude_form_id && (int) $link['form_id'] === (int) $exclude_form_id;

			// Skip the source form — except for confirmation same-form siblings
			// which should still sync (sans conditionalLogic, since two
			// confirmations in the same form cannot share conditional logic).
			if ( $is_same_form && 'confirmation' !== $type ) {
				continue;
			}

			$form = GFAPI::get_form( $link['form_id'] );

			// --- Orphan detection ---
			if ( ! $form || is_wp_error( $form ) ) {
				GF_Preset_Link_Store::remove_link( $link['id'] );
				$result['skipped']++;

				if ( $addon ) {
					$addon->log_debug( __METHOD__ . "(): Orphan link #{$link['id']} removed (form #{$link['form_id']} deleted)." );
				}
				continue;
			}

			// --- Extract current object from form ---
			$current_object = self::extract_object( $form, $type, $link['object_id'] );

			if ( null === $current_object ) {
				// Object no longer exists in the form — treat as orphan.
				GF_Preset_Link_Store::remove_link( $link['id'] );
				$result['skipped']++;

				if ( $addon ) {
					$addon->log_debug( __METHOD__ . "(): Object {$link['object_id']} not found in form #{$link['form_id']}. Link removed." );
				}
				continue;
			}

			// Fetch per-link excluded keys once (used in both conflict
			// detection and payload filtering below).
			$excluded = GF_Preset_Link_Store::get_excluded_keys( $link['id'] );

			// --- Conflict detection ---
			// For same-form confirmation siblings, skip conflict detection
			// because their conditionalLogic is expected to differ.
			if ( ! $is_same_form ) {
				// Only compare the keys present in the payload, ignoring extra
				// properties the target field may have.  Also exclude the
				// link's excluded keys — those properties were intentionally
				// not synced, so local differences are expected.
				$hashable_object = self::strip_identity_keys( $type, $current_object );
				$comparable      = array_intersect_key( $hashable_object, $payload );
				if ( ! empty( $excluded ) ) {
					foreach ( $excluded as $ek ) {
						unset( $comparable[ $ek ] );
					}
				}
				$current_hash = self::compute_payload_hash( $comparable );
				if ( ! empty( $link['synced_hash'] ) && $current_hash !== $link['synced_hash'] ) {
					$result['conflicts'][] = array(
						'link_id' => $link['id'],
						'form_id' => $link['form_id'],
						'message' => sprintf(
							/* translators: %d: form ID */
							__( 'Form #%d: object was edited locally since last sync.', 'gf-presets' ),
							$link['form_id']
						),
					);

					if ( $addon ) {
						$addon->log_debug( __METHOD__ . "(): Conflict on form #{$link['form_id']}, object {$link['object_id']} — local edit detected." );
					}
					continue;
				}
			}

			// --- Apply the new payload ---
			try {
				// Respect per-link excluded keys.
				$filtered_payload = $payload;
				if ( ! empty( $excluded ) ) {
					foreach ( $excluded as $key ) {
						unset( $filtered_payload[ $key ] );
					}
				}

				// For same-form confirmation siblings, exclude conditionalLogic
				// since each confirmation must have its own conditional logic.
				if ( $is_same_form && 'confirmation' === $type ) {
					unset( $filtered_payload['conditionalLogic'] );
				}

				$updated_form = self::apply_payload( $form, $type, $link['object_id'], $filtered_payload );

				$update_result = GFAPI::update_form( $updated_form );
				if ( is_wp_error( $update_result ) ) {
					$result['errors'][] = sprintf(
						'Form #%d: %s',
						$link['form_id'],
						$update_result->get_error_message()
					);
					continue;
				}

				// Explicitly persist confirmations / notifications to their
				// dedicated DB columns (GF 2.5+ stores them separately).
				if ( 'confirmation' === $type && ! empty( $updated_form['confirmations'] ) ) {
					GFFormsModel::update_form_meta( $link['form_id'], $updated_form['confirmations'], 'confirmations' );
				}
				if ( 'notification' === $type && ! empty( $updated_form['notifications'] ) ) {
					GFFormsModel::update_form_meta( $link['form_id'], $updated_form['notifications'], 'notifications' );
				}

				// Store a sync hash that matches what was actually applied:
				// exclude the link's excluded keys and (for same-form
				// confirmation siblings) conditionalLogic.
				$hash_payload = $payload;
				if ( ! empty( $excluded ) ) {
					foreach ( $excluded as $ek ) {
						unset( $hash_payload[ $ek ] );
					}
				}
				if ( $is_same_form && 'confirmation' === $type ) {
					unset( $hash_payload['conditionalLogic'] );
				}
				GF_Preset_Link_Store::update_sync_hash( $link['id'], self::compute_payload_hash( $hash_payload ) );
				$result['synced']++;

			} catch ( \Exception $e ) {
				$result['errors'][] = sprintf(
					'Form #%d: %s',
					$link['form_id'],
					$e->getMessage()
				);

				if ( $addon ) {
					$addon->log_error( __METHOD__ . "(): Error syncing form #{$link['form_id']}: " . $e->getMessage() );
				}
			}
		}

		// Log summary.
		if ( $addon ) {
			$addon->log_debug( sprintf(
				'%s(): Sync preset #%d: synced=%d, skipped=%d (orphan), conflicts=%d, errors=%d',
				__METHOD__,
				$preset_id,
				$result['synced'],
				$result['skipped'],
				count( $result['conflicts'] ),
				count( $result['errors'] )
			) );
		}

		return $result;
	}

	/**
	 * Force-sync a single link, ignoring conflict detection.
	 *
	 * @param int   $link_id  Link row ID.
	 * @param array $payload  Preset payload (decoded).
	 * @return true|WP_Error
	 */
	public static function force_sync_link( $link_id, $payload ) {
		$link = GF_Preset_Link_Store::read( $link_id );
		if ( ! $link ) {
			return new WP_Error( 'not_found', __( 'Link not found.', 'gf-presets' ) );
		}

		$preset = GF_Preset_Store::read( $link['preset_id'] );
		if ( ! $preset ) {
			return new WP_Error( 'not_found', __( 'Preset not found.', 'gf-presets' ) );
		}

		$form = GFAPI::get_form( $link['form_id'] );
		if ( ! $form || is_wp_error( $form ) ) {
			GF_Preset_Link_Store::remove_link( $link_id );
			return new WP_Error( 'orphan', __( 'Form has been deleted.', 'gf-presets' ) );
		}

		$updated_form  = self::apply_payload( $form, $preset['preset_type'], $link['object_id'], $payload );
		$update_result = GFAPI::update_form( $updated_form );

		if ( is_wp_error( $update_result ) ) {
			return $update_result;
		}

		// Explicitly persist confirmations / notifications to their
		// dedicated DB columns (GF 2.5+ stores them separately).
		$type = $preset['preset_type'];
		if ( 'confirmation' === $type && ! empty( $updated_form['confirmations'] ) ) {
			GFFormsModel::update_form_meta( $link['form_id'], $updated_form['confirmations'], 'confirmations' );
		}
		if ( 'notification' === $type && ! empty( $updated_form['notifications'] ) ) {
			GFFormsModel::update_form_meta( $link['form_id'], $updated_form['notifications'], 'notifications' );
		}

		$new_hash = self::compute_payload_hash( $payload );
		GF_Preset_Link_Store::update_sync_hash( $link_id, $new_hash );

		return true;
	}

	/**
	 * Extract a specific object from a form by type and ID.
	 *
	 * @param array  $form      Form object.
	 * @param string $type      Preset type (field, notification, confirmation).
	 * @param string $object_id The object's ID or GUID.
	 * @return array|null The extracted object, or null if not found.
	 */
	public static function extract_object( $form, $type, $object_id ) {
		switch ( $type ) {
			case 'field':
				if ( ! empty( $form['fields'] ) ) {
					foreach ( $form['fields'] as $field ) {
						$fid = is_object( $field ) ? $field->id : rgar( $field, 'id' );
						if ( (string) $fid === (string) $object_id ) {
							// Use JSON round-trip for GF_Field objects to get a clean array
							// matching what JS produces via JSON.stringify(field).
							if ( is_object( $field ) ) {
								return json_decode( wp_json_encode( $field ), true );
							}
							return $field;
						}
					}
				}
				break;

			case 'notification':
				if ( ! empty( $form['notifications'] ) ) {
					foreach ( $form['notifications'] as $guid => $notif ) {
						if ( $guid === $object_id || rgar( $notif, 'id' ) === $object_id ) {
							return $notif;
						}
					}
				}
				break;

			case 'confirmation':
				if ( ! empty( $form['confirmations'] ) ) {
					foreach ( $form['confirmations'] as $guid => $conf ) {
						if ( $guid === $object_id || rgar( $conf, 'id' ) === $object_id ) {
							return $conf;
						}
					}
				}
				break;
		}

		return null;
	}

	/**
	 * PHP runtime-only properties that GF adds to field objects via
	 * constructors or post_convert_field() but are never present in
	 * JS-originated payloads. Must be excluded from hashing so that
	 * JS-created presets and PHP-extracted payloads produce identical hashes.
	 */
	private static $runtime_field_keys = array(
		'checked_indicator_url',
		'checked_indicator_markup',
		'validateState',
		'is_payment',
	);

	/**
	 * Return the list of runtime-only field keys.
	 *
	 * @return array
	 */
	public static function get_runtime_field_keys() {
		return self::$runtime_field_keys;
	}

	/**
	 * Strip identity keys and runtime-only properties from an extracted
	 * object so it can be hashed consistently with a stored preset payload.
	 *
	 * @param string $type   Preset type.
	 * @param array  $object Extracted object.
	 * @return array Object without identity/runtime keys.
	 */
	public static function strip_identity_keys( $type, $object ) {
		$stripped = $object;

		switch ( $type ) {
			case 'field':
				unset( $stripped['id'], $stripped['formId'], $stripped['pageNumber'] );
				foreach ( self::$runtime_field_keys as $rk ) {
					unset( $stripped[ $rk ] );
				}
				break;

			case 'notification':
			case 'confirmation':
				unset( $stripped['name'], $stripped['id'] );
				break;
		}

		return $stripped;
	}

	/**
	 * Compute a stable hash for a payload array.
	 *
	 * Recursively sorts keys so that the hash is insensitive to the order
	 * in which different GF_Field objects serialise their properties.
	 *
	 * @param array $data Associative array.
	 * @return string MD5 hex digest.
	 */
	public static function compute_payload_hash( $data ) {
		self::ksort_recursive( $data );
		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Recursively sort an array by key.
	 *
	 * @param mixed $array Passed by reference.
	 */
	private static function ksort_recursive( &$array ) {
		if ( ! is_array( $array ) ) {
			return;
		}
		ksort( $array );
		foreach ( $array as &$value ) {
			if ( is_array( $value ) ) {
				self::ksort_recursive( $value );
			}
		}
	}

	/**
	 * Apply a preset payload to a form object, preserving identity keys.
	 *
	 * @param array  $form      Form object.
	 * @param string $type      Preset type.
	 * @param string $object_id Object ID/GUID.
	 * @param array  $payload   Preset payload.
	 * @return array Updated form object.
	 */
	private static function apply_payload( $form, $type, $object_id, $payload ) {
		switch ( $type ) {
			case 'field':
				foreach ( $form['fields'] as $index => $field ) {
					$fid = is_object( $field ) ? $field->id : rgar( $field, 'id' );
					if ( (string) $fid === (string) $object_id ) {
						// Preserve identity keys.
						$preserved_id = $fid;

						// Overwrite all properties except 'id'.
						foreach ( $payload as $key => $value ) {
							if ( in_array( $key, array( 'id', 'formId', 'pageNumber' ), true ) ) {
								continue;
							}
							if ( is_object( $form['fields'][ $index ] ) ) {
								$form['fields'][ $index ]->{$key} = $value;
							} else {
								$form['fields'][ $index ][ $key ] = $value;
							}
						}

						// Run CL remapper on the synced field.
						$cl = is_object( $form['fields'][ $index ] )
							? $form['fields'][ $index ]->conditionalLogic
							: rgar( $form['fields'][ $index ], 'conditionalLogic' );

						if ( ! empty( $cl ) ) {
							$remap_result = GF_CL_Remapper::remap_on_apply( $cl, $form );
							if ( is_object( $form['fields'][ $index ] ) ) {
								$form['fields'][ $index ]->conditionalLogic = $remap_result['conditional_logic'];
							} else {
								$form['fields'][ $index ]['conditionalLogic'] = $remap_result['conditional_logic'];
							}
						}

						break;
					}
				}
				break;

			case 'notification':
				if ( ! empty( $form['notifications'] ) ) {
					foreach ( $form['notifications'] as $guid => &$notif ) {
						if ( $guid === $object_id || rgar( $notif, 'id' ) === $object_id ) {
							// Preserve name and id.
							$preserve_name = rgar( $notif, 'name' );
							$preserve_id   = rgar( $notif, 'id' );

							foreach ( $payload as $key => $value ) {
								if ( in_array( $key, array( 'name', 'id' ), true ) ) {
									continue;
								}
								$notif[ $key ] = $value;
							}

							break;
						}
					}
					unset( $notif );
				}
				break;

			case 'confirmation':
				if ( ! empty( $form['confirmations'] ) ) {
					foreach ( $form['confirmations'] as $guid => &$conf ) {
						if ( $guid === $object_id || rgar( $conf, 'id' ) === $object_id ) {
							$preserve_name = rgar( $conf, 'name' );
							$preserve_id   = rgar( $conf, 'id' );

							foreach ( $payload as $key => $value ) {
								if ( in_array( $key, array( 'name', 'id' ), true ) ) {
									continue;
								}
								$conf[ $key ] = $value;
							}

							break;
						}
					}
					unset( $conf );
				}
				break;
		}

		return $form;
	}
}
