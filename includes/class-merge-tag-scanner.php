<?php
/**
 * GF Merge Tag Scanner — Detects field-specific merge tags in a payload.
 *
 * Scans notification/confirmation payloads for patterns like {Name:3}, {Email:5}.
 * Generic/system merge tags ({all_fields}, {form_title}, etc.) are whitelisted.
 */

defined( 'ABSPATH' ) || exit;

class GF_Merge_Tag_Scanner {

	/**
	 * Generic merge tags that should NOT be flagged.
	 * These work without referencing a specific field ID.
	 */
	private static $whitelist = array(
		'all_fields',
		'form_title',
		'form_id',
		'entry_id',
		'entry_url',
		'date_mdy',
		'date_dmy',
		'date_created',
		'ip',
		'source_url',
		'post_id',
		'post_edit_url',
		'post_url',
		'admin_email',
		'user_agent',
		'referer',
		'login_url',
		'user:display_name',
		'user:user_email',
		'user:user_login',
		'user:first_name',
		'user:last_name',
		'user:ID',
		'embed_post:ID',
		'embed_post:post_title',
		'embed_url',
		'pricing_fields',
		'payment_status',
		'payment_amount',
		'payment_date',
		'transaction_id',
		'transaction_type',
		'is_starred',
		'is_read',
		'created_by',
		'sequence',
		'save_link',
		'save_email_input',
		'resume_token',
		'workflow_timeline',
		'coupon_codes',
	);

	/**
	 * Scan text for field-specific merge tags.
	 *
	 * Returns an array of detected merge tags like {Name:3}, {Email:5}.
	 * Generic tags ({all_fields}, etc.) are excluded.
	 *
	 * @param string $text Text to scan.
	 * @return array Array of merge tag strings found.
	 */
	public static function scan_text( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return array();
		}

		$found = array();

		// Match merge tags: {something:number} or {something:number:modifier}
		if ( preg_match_all( '/\{([^}:]+):(\d+)(?::[^}]*)?\}/', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$tag_name = trim( $match[1] );

				// Skip whitelisted generic tags (check base name before colon).
				if ( self::is_whitelisted( $tag_name ) ) {
					continue;
				}

				$found[] = $match[0]; // Full merge tag, e.g. {Name:3}
			}
		}

		return array_unique( $found );
	}

	/**
	 * Scan an entire payload array recursively for merge tags.
	 *
	 * @param array $payload Decoded payload.
	 * @return array Array of merge tag strings found.
	 */
	public static function scan_payload( $payload ) {
		$all_tags = array();

		if ( ! is_array( $payload ) ) {
			return $all_tags;
		}

		array_walk_recursive( $payload, function ( $value ) use ( &$all_tags ) {
			if ( is_string( $value ) ) {
				$tags = self::scan_text( $value );
				$all_tags = array_merge( $all_tags, $tags );
			}
		} );

		return array_unique( $all_tags );
	}

	/**
	 * Check if a tag name (the part before the colon) is whitelisted.
	 *
	 * @param string $name Tag name.
	 * @return bool
	 */
	private static function is_whitelisted( $name ) {
		$lower = strtolower( $name );

		foreach ( self::$whitelist as $item ) {
			if ( strtolower( $item ) === $lower ) {
				return true;
			}
			// Handle prefixed patterns like "user:" tags.
			if ( strpos( $item, ':' ) !== false ) {
				$parts = explode( ':', $item, 2 );
				if ( strtolower( $parts[0] ) === $lower ) {
					return true;
				}
			}
		}

		return false;
	}
}
