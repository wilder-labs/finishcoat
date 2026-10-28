<?php
/**
 * Plugin Name:       Finishcoat
 * Plugin URI:        https://wilderlabs.studio/finishcoat/
 * Description:       Your clients pin notes, replies and photos to any spot on your pages, and you keep the final say.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Wilder Labs
 * Author URI:        https://wilderlabs.studio
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       finishcoat
 * Domain Path:       /languages
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FINISHCOAT_VERSION', '1.0.0' );
define( 'FINISHCOAT_FILE', __FILE__ );
define( 'FINISHCOAT_DIR', __DIR__ );
define( 'FINISHCOAT_URL', plugin_dir_url( __FILE__ ) );

require_once FINISHCOAT_DIR . '/includes/settings.php';
require_once FINISHCOAT_DIR . '/includes/access.php';
require_once FINISHCOAT_DIR . '/includes/notes.php';
require_once FINISHCOAT_DIR . '/includes/photos.php';
require_once FINISHCOAT_DIR . '/includes/front.php';

/**
 * The one project of a Lite site. Notes and photos are stored with its slug, so an add-on can add more
 * projects later without moving any data.
 *
 * @return array{name: string, label: string}
 */
function finishcoat_site_project() {
	return array(
		'name'  => finishcoat_site_name(),
		'label' => finishcoat_tab_label(),
	);
}

/**
 * Every project, keyed by slug. Lite has one, `site`.
 *
 * @return array<string, array>
 */
function finishcoat_projects() {
	$list = apply_filters( 'finishcoat_projects', array( 'site' => finishcoat_site_project() ) );
	return is_array( $list ) && $list ? $list : array( 'site' => finishcoat_site_project() );
}

/**
 * One project, or null.
 *
 * @param string $slug Project slug.
 * @return array|null
 */
function finishcoat_project( $slug ) {
	$all = finishcoat_projects();
	return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : null;
}

/**
 * Activation: add settings, the first review code and the install date. Existing values are kept.
 */
function finishcoat_activate() {
	finishcoat_seed();
}
register_activation_hook( __FILE__, 'finishcoat_activate' );

/**
 * Create the plugin's options when they are missing.
 */
function finishcoat_seed() {
	if ( false === get_option( 'finishcoat_settings', false ) ) {
		add_option( 'finishcoat_settings', finishcoat_default_settings(), '', false );
	}
	if ( false === get_option( 'finishcoat_installed', false ) ) {
		add_option( 'finishcoat_installed', time(), '', false );
	}
	finishcoat_codes();
}

/**
 * Tell add-ons that Finishcoat is ready.
 */
function finishcoat_boot() {
	do_action( 'finishcoat_loaded' );
}
add_action( 'plugins_loaded', 'finishcoat_boot' );
