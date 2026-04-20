<?php
/**
 * Uninstall GF Presets.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all plugin data: custom tables, options, and transients.
 *
 * @package GF_Presets
 */

// Exit if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom database tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_preset_links" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gf_presets" );

// Delete options.
delete_option( 'gf_presets_db_version' );

// Delete transients.
delete_transient( 'gf_presets_github_release' );
