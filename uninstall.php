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
	// The per-visitor rate limits and queued admin notices have hashed or per-user names, so sweep them by prefix.
	// They expire within an hour anyway; with a persistent object cache they are not in the options table at all.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time cleanup on uninstall; there is no API to delete transients by prefix.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_finishcoat_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_finishcoat_' ) . '%'
		)
	);
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
