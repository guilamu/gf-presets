<?php
/**
 * GF Preset Store — CRUD for the gf_presets table.
 */

defined( 'ABSPATH' ) || exit;

class GF_Preset_Store {

	/**
	 * Get the table name.
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gf_presets';
	}

	/**
	 * Create a new preset.
	 *
	 * @param string $type        One of field, notification, confirmation.
	 * @param string $name        Human-readable name.
	 * @param string $description Optional description.
	 * @param array  $payload     Decoded payload data.
	 * @return int|WP_Error       New preset ID or error.
	 */
	public static function create( $type, $name, $description, $payload ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'preset_type'     => $type,
				'name'            => $name,
				'description'     => $description,
				'payload'         => wp_json_encode( $payload ),
				'payload_version' => 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_error', __( 'Failed to create preset: ', 'gf-presets' ) . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a single preset by ID.
	 *
	 * @param int $id Preset ID.
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
	 * Update a preset.
	 *
	 * @param int    $id          Preset ID.
	 * @param string $name        Updated name.
	 * @param string $description Updated description.
	 * @param array  $payload     Updated payload.
	 * @return true|WP_Error
	 */
	public static function update( $id, $name, $description, $payload ) {
		global $wpdb;

		$updated = $wpdb->update(
			self::table(),
			array(
				'name'        => $name,
				'description' => $description,
				'payload'     => wp_json_encode( $payload ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_error', __( 'Failed to update preset: ', 'gf-presets' ) . $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete a preset.
	 *
	 * @param int $id Preset ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			self::table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}

	/**
	 * List all presets, optionally filtered by type.
	 *
	 * @param string|null $type Optional type filter.
	 * @return array
	 */
	public static function list_all( $type = null ) {
		global $wpdb;

		$table = self::table();

		if ( $type ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE preset_type = %s ORDER BY updated_at DESC",
				$type
			);
		} else {
			$sql = "SELECT * FROM {$table} ORDER BY updated_at DESC";
		}

		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	/**
	 * Duplicate a preset (create a copy with a new name).
	 *
	 * @param int $id Preset ID to duplicate.
	 * @return int|WP_Error New preset ID or error.
	 */
	public static function duplicate( $id ) {
		$preset = self::read( $id );
		if ( ! $preset ) {
			return new WP_Error( 'not_found', __( 'Preset not found.', 'gf-presets' ) );
		}

		$payload = json_decode( $preset['payload'], true );
		/* translators: %s: original preset name */
		$new_name = sprintf( __( '%s (Copy)', 'gf-presets' ), $preset['name'] );

		return self::create( $preset['preset_type'], $new_name, $preset['description'], $payload );
	}
}
