<?php
/**
 * Plugin Name: GF Presets
 * Plugin URI: https://github.com/guilamu/gf-presets
 * Description: Global preset library for Gravity Forms fields, notifications, and confirmations. Save once, apply anywhere — as a one-time copy or a synced live link.
 * Version: 0.9.1
 * Author: Guilamu
 * Author URI: https://github.com/guilamu
 * Text Domain: gf-presets
 * Domain Path: /languages
 * Update URI: https://github.com/guilamu/gf-presets/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: AGPL-3.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'GF_PRESETS_VERSION', '0.9.1' );
define( 'GF_PRESETS_FILE', __FILE__ );
define( 'GF_PRESETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'GF_PRESETS_URL', plugin_dir_url( __FILE__ ) );

// GitHub auto-updater.
require_once GF_PRESETS_DIR . 'includes/class-github-updater.php';

add_action( 'gform_loaded', array( 'GF_Presets_Bootstrap', 'load' ), 5 );

class GF_Presets_Bootstrap {

	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		require_once GF_PRESETS_DIR . 'class-gf-presets.php';
		GFAddOn::register( 'GF_Presets' );
	}
}

/**
 * Global accessor for the GF_Presets singleton.
 *
 * @return GF_Presets
 */
function gf_presets() {
	return GF_Presets::get_instance();
}

/**
 * Register with Guilamu Bug Reporter.
 */
add_action( 'plugins_loaded', function () {
	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		Guilamu_Bug_Reporter::register( array(
			'slug'        => 'gf-presets',
			'name'        => 'GF Presets',
			'version'     => GF_PRESETS_VERSION,
			'github_repo' => 'guilamu/gf-presets',
		) );
	}
}, 20 );

/**
 * Add plugin row meta links: View details, Report a Bug.
 */
add_filter( 'plugin_row_meta', 'gf_presets_plugin_row_meta', 10, 2 );

function gf_presets_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( GF_PRESETS_FILE ) !== $file ) {
		return $links;
	}

	// "View details" thickbox link.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url( self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=gf-presets'
			. '&TB_iframe=true&width=772&height=926'
		) ),
		esc_attr__( 'More information about GF Presets', 'gf-presets' ),
		esc_attr__( 'GF Presets', 'gf-presets' ),
		esc_html__( 'View details', 'gf-presets' )
	);

	// "Report a Bug" link.
	if ( class_exists( 'Guilamu_Bug_Reporter' ) ) {
		$links[] = sprintf(
			'<a href="#" class="guilamu-bug-report-btn" data-plugin-slug="gf-presets" data-plugin-name="%s">%s</a>',
			esc_attr__( 'GF Presets', 'gf-presets' ),
			esc_html__( '🐛 Report a Bug', 'gf-presets' )
		);
	} else {
		$links[] = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			'https://github.com/guilamu/guilamu-bug-reporter/releases',
			esc_html__( '🐛 Report a Bug (install Bug Reporter)', 'gf-presets' )
		);
	}

	return $links;
}
