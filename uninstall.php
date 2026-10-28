<?php
/**
 * Runs when Finishcoat is deleted from the Plugins screen.
 *
 * Notes, photos and settings are kept, unless "Delete all notes and photos when the plugin is deleted" is ticked
 * in Settings (it is off by default). Then every note, reply and photo post, the photo files in
 * uploads/finishcoat/, and the plugin's options are removed.
 *
 * @package Finishcoat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

/**
 * Remove everything Finishcoat stored on the current site, if the site asked for that.
 */
function finishcoat_uninstall_site() {
	$settings = get_option( 'finishcoat_settings', array() );
	if ( ! is_array( $settings ) || empty( $settings['delete_data'] ) ) {
		return;
	}
	foreach ( array( 'finishcoat_photo', 'finishcoat_note' ) as $type ) {
		do {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'any',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				wp_delete_post( (int) $id, true );
			}
		} while ( $ids );
	}

	$uploads = wp_upload_dir( null, false );
	$dir     = untrailingslashit( (string) $uploads['basedir'] ) . '/finishcoat';
	if ( is_dir( $dir ) ) {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->rmdir( $dir, true );
		}
	}

	foreach ( array( 'finishcoat_settings', 'finishcoat_codes', 'finishcoat_installed' ) as $option ) {
		delete_option( $option );
	}
	foreach ( array( 'finishcoat_waiting', 'finishcoat_rv_all', 'finishcoat_cap_mail' ) as $transient ) {
		delete_transient( $transient );
	}
	delete_metadata( 'user', 0, 'finishcoat_pro_notice_dismissed', '', true );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 1000,
		)
	) as $finishcoat_site_id ) {
		switch_to_blog( (int) $finishcoat_site_id );
		finishcoat_uninstall_site();
		restore_current_blog();
	}
} else {
	finishcoat_uninstall_site();
}
