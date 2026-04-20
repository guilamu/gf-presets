<?php
/**
 * GF Preset Link Store — CRUD for the gf_preset_links table.
 *
 * Tracks which form objects are live-linked to a preset.
 * No row = copy (independent); a row = live link (synced).
 */

defined( 'ABSPATH' ) || exit;

class GF_Preset_Link_Store {

	/**
	 * Get the table name.
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gf_preset_links';
	}

	/**
	 * Add a live link.
	 *
	 * @param int    $preset_id  Preset ID.
	 * @param int    $form_id    Form ID.
	 * @param string $object_id  Notification/confirmation GUID or field ID.
	 * @param string $hash       MD5 hash of the payload at link time.
	 * @return int|WP_Error      New link ID or error.
	 */
	public static function add_link( $preset_id, $form_id, $object_id, $hash = '' ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'preset_id'   => $preset_id,
				'form_id'     => $form_id,
				'object_id'   => $object_id,
				'synced_hash' => $hash,
				'linked_at'   => $now,
				'last_synced' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_error', __( 'Failed to create link: ', 'gf-presets' ) . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a link by its ID.
	 *
	 * @param int $id Link row ID.
	 * @return bool
	 */
	public static function remove_link( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			self::table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * Read a single link by ID.
	 *
	 * @param int $id Link row ID.
	 * @return array|null
	 */
	public static function read( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all links for a given preset.
	 *
	 * @param int $preset_id Preset ID.
	 * @return array
	 */
	public static function get_links_for_preset( $preset_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE preset_id = %d ORDER BY linked_at DESC",
				$preset_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Get a specific link by (preset_id, form_id, object_id).
	 *
	 * @param int    $preset_id Preset ID.
	 * @param int    $form_id   Form ID.
	 * @param string $object_id Object ID.
	 * @return array|null
	 */
	public static function get_link_for_object( $preset_id, $form_id, $object_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE preset_id = %d AND form_id = %d AND object_id = %s",
				$preset_id,
				$form_id,
				$object_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Find link for a specific form object, regardless of preset.
	 * Useful for checking if a notification/field is linked to any preset.
	 *
	 * @param int    $form_id   Form ID.
	 * @param string $object_id Object ID.
	 * @return array|null
	 */
	public static function get_link_by_form_object( $form_id, $object_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE form_id = %d AND object_id = %s",
				$form_id,
				$object_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all links for a given form.
	 *
	 * @param int $form_id Form ID.
	 * @return array
	 */
	public static function get_links_for_form( $form_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM " . self::table() . " WHERE form_id = %d ORDER BY linked_at DESC",
				$form_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Delete all links for a specific form (orphan cleanup).
	 *
	 * @param int $form_id Form ID.
	 * @return int Number of rows deleted.
	 */
	public static function delete_links_for_form( $form_id ) {
		global $wpdb;

		return (int) $wpdb->delete(
			self::table(),
			array( 'form_id' => $form_id ),
			array( '%d' )
		);
	}

	/**
	 * Update the synced_hash and last_synced timestamp for a link.
	 *
	 * @param int    $id   Link row ID.
	 * @param string $hash New MD5 hash.
	 * @return bool
	 */
	public static function update_sync_hash( $id, $hash ) {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table(),
			array(
				'synced_hash' => $hash,
				'last_synced' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count links for a preset.
	 *
	 * @param int $preset_id Preset ID.
	 * @return int
	 */
	public static function count_links( $preset_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM " . self::table() . " WHERE preset_id = %d",
				$preset_id
			)
		);
	}

	/**
	 * Get the excluded keys for a link (decoded array).
	 *
	 * @param int $id Link row ID.
	 * @return array List of excluded field property keys.
	 */
	public static function get_excluded_keys( $id ) {
		global $wpdb;

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT excluded_keys FROM " . self::table() . " WHERE id = %d",
				$id
			)
		);

		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Update the excluded keys for a link.
	 *
	 * @param int   $id   Link row ID.
	 * @param array $keys List of field property keys to exclude from sync.
	 * @return bool
	 */
	public static function update_excluded_keys( $id, $keys ) {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table(),
			array( 'excluded_keys' => wp_json_encode( array_values( $keys ) ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
