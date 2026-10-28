<?php
/**
 * The front end: when the Suggestions tab loads, the page script's settings, and the [finishcoat] shortcode.
 *
 * Nothing on the front end talks to any server but this site. The page script and its loader ship in the plugin
 * folder and are added with wp_enqueue_script(). The site's own fonts are used; none are loaded.
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the tab loads for this visitor: 'now' (the page script is added), 'lazy' (only the small loader, which adds
 * the page script for visitors with a review code), or 'none'.
 *
 * @return string
 */
function finishcoat_front_mode() {
	if ( finishcoat_is_studio() ) {
		return 'now';
	}
	$show = (string) finishcoat_setting( 'show' );
	if ( 'logged_in' === $show ) {
		return is_user_logged_in() ? 'now' : 'none';
	}
	return 'everyone' === $show ? 'now' : 'lazy';
}

/**
 * Whether a page builder's editor, the Customizer or a feed is being shown, where the tab would get in the way.
 *
 * @return bool
 */
function finishcoat_is_editor_view() {
	if ( is_admin() || is_feed() || is_embed() || is_customize_preview() || wp_is_json_request() ) {
		return true;
	}
	foreach ( array( 'et_fb', 'elementor-preview', 'fl_builder', 'bricks', 'ct_builder', 'brizy-edit-iframe', 'vc_editable' ) as $arg ) {
		if ( isset( $_GET[ $arg ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checks whether an editor view is open.
			return true;
		}
	}
	return false;
}

/**
 * Whether the tab loads on the current page.
 *
 * @param bool $forced The [finishcoat] shortcode is on the page.
 * @return bool
 */
function finishcoat_loads_here( $forced = false ) {
	if ( finishcoat_is_editor_view() || 'none' === finishcoat_front_mode() ) {
		return false;
	}
	$skipped = is_singular() && in_array( (int) get_queried_object_id(), finishcoat_skip_ids(), true );
	return (bool) apply_filters( 'finishcoat_load_on_page', $forced || ! $skipped, $forced );
}

/**
 * Settings the page script reads. It never includes a review code.
 *
 * @param string $mode Result of finishcoat_front_mode().
 * @return array
 */
function finishcoat_front_config( $mode ) {
	return array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'project' => 'site',
		'src'     => add_query_arg( 'ver', FINISHCOAT_VERSION, FINISHCOAT_URL . 'assets/finishcoat.js' ),
		'lazy'    => 'lazy' === $mode,
		'site'    => finishcoat_site_name(),
		'label'   => finishcoat_tab_label(),
		'theme'   => finishcoat_theme(),
		'credit'  => (bool) finishcoat_setting( 'credit' ),
		'ver'     => FINISHCOAT_VERSION,
		// In link mode most visitors never load the page script, so its words come with its first request instead.
		'i18n'    => 'lazy' === $mode ? null : finishcoat_front_strings(),
	);
}

/**
 * The site name as plain text.
 *
 * @return string
 */
function finishcoat_site_name() {
	return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
}

/**
 * Add the loader (and, unless it is left to the loader, the page script).
 *
 * @param bool $forced The [finishcoat] shortcode asked for it.
 */
function finishcoat_enqueue_front( $forced = false ) {
	static $done = false;
	if ( $done || ! finishcoat_loads_here( $forced ) ) {
		return;
	}
	$done = true;
	$mode = finishcoat_front_mode();
	$boot = (string) file_get_contents( plugin_dir_path( FINISHCOAT_FILE ) . 'assets/finishcoat-boot.js' );
	wp_register_script( 'finishcoat-boot', false, array(), FINISHCOAT_VERSION, array( 'in_footer' => true ) );
	wp_enqueue_script( 'finishcoat-boot' );
	wp_add_inline_script( 'finishcoat-boot', 'window.FinishcoatConfig = ' . wp_json_encode( finishcoat_front_config( $mode ) ) . ";\n" . $boot );
	wp_register_script(
		'finishcoat',
		FINISHCOAT_URL . 'assets/finishcoat.js',
		array( 'finishcoat-boot' ),
		FINISHCOAT_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	if ( 'now' === $mode ) {
		wp_enqueue_script( 'finishcoat' );
	}
	do_action( 'finishcoat_front_scripts', $mode );
}

/**
 * Front-end hook.
 */
function finishcoat_front_scripts() {
	finishcoat_enqueue_front( false );
}
add_action( 'wp_enqueue_scripts', 'finishcoat_front_scripts' );

/**
 * [finishcoat] loads the tab on this page, even on a skipped page. [finishcoat board="yes"] also shows the board
 * of every note in its place. Who sees the tab still follows the Show setting, and the board needs a review code.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function finishcoat_shortcode( $atts ) {
	$a = shortcode_atts( array( 'board' => 'no' ), $atts, 'finishcoat' );
	finishcoat_enqueue_front( true );
	if ( in_array( strtolower( (string) $a['board'] ), array( 'yes', '1', 'true' ), true ) ) {
		return '<div class="finishcoat-board-slot" data-finishcoat-board></div>';
	}
	return '';
}
add_shortcode( 'finishcoat', 'finishcoat_shortcode' );

/**
 * Every string the page script shows, translated. The script looks them up by their English text.
 *
 * @return array<string, string>
 */
function finishcoat_front_strings() {
	return array(
		'just now'                                        => __( 'just now', 'finishcoat' ),
		/* translators: %d: number of minutes. */
		'%d min ago'                                      => __( '%d min ago', 'finishcoat' ),
		/* translators: %d: number of hours. */
		'%d hr ago'                                       => __( '%d hr ago', 'finishcoat' ),
		'The notes service did not answer. Try again in a minute.' => __( 'The notes service did not answer. Try again in a minute.', 'finishcoat' ),
		'No connection. Check your internet and try again.' => __( 'No connection. Check your internet and try again.', 'finishcoat' ),
		'That code did not work. Check it and try again.' => __( 'That code did not work. Check it and try again.', 'finishcoat' ),
		'Suggestions'                                     => __( 'Suggestions', 'finishcoat' ),
		'Continue'                                        => __( 'Continue', 'finishcoat' ),
		'Enter your review code'                          => __( 'Enter your review code', 'finishcoat' ),
		'Your web designer sent you this code.'           => __( 'Your web designer sent you this code.', 'finishcoat' ),
		'Enter your review code.'                         => __( 'Enter your review code.', 'finishcoat' ),
		'Checking...'                                     => __( 'Checking...', 'finishcoat' ),
		'Could not load notes.'                           => __( 'Could not load notes.', 'finishcoat' ),
		/* translators: %s: label of the tab, such as Suggestions. */
		'Close %s'                                        => __( 'Close %s', 'finishcoat' ),
		'New'                                             => __( 'New', 'finishcoat' ),
		'Noted'                                           => __( 'Noted', 'finishcoat' ),
		'In progress'                                     => __( 'In progress', 'finishcoat' ),
		'Done'                                            => __( 'Done', 'finishcoat' ),
		'Show'                                            => __( 'Show', 'finishcoat' ),
		'What would you change, keep or ask about on this page?' => __( 'What would you change, keep or ask about on this page?', 'finishcoat' ),
		'Your name'                                       => __( 'Your name', 'finishcoat' ),
		'Pin it to a spot'                                => __( 'Pin it to a spot', 'finishcoat' ),
		'Send'                                            => __( 'Send', 'finishcoat' ),
		'New notes are paused right now.'                 => __( 'New notes are paused right now.', 'finishcoat' ),
		'Add photos'                                      => __( 'Add photos', 'finishcoat' ),
		'Try my photo here'                               => __( 'Try my photo here', 'finishcoat' ),
		'Photos to send'                                  => __( 'Photos to send', 'finishcoat' ),
		'I own these photos or have permission to use them.' => __( 'I own these photos or have permission to use them.', 'finishcoat' ),
		'Leave a suggestion'                              => __( 'Leave a suggestion', 'finishcoat' ),
		'Want a different photo? Point at any photo on the page and click Try my photo here. Only you see it until you suggest it.' => __( 'Want a different photo? Point at any photo on the page and click Try my photo here. Only you see it until you suggest it.', 'finishcoat' ),
		'Want a different photo? Tap Try my photo here, then tap a photo on the page. Only you see it until you suggest it.' => __( 'Want a different photo? Tap Try my photo here, then tap a photo on the page. Only you see it until you suggest it.', 'finishcoat' ),
		'Forget this code'                                => __( 'Forget this code', 'finishcoat' ),
		'Made with Finishcoat'                            => __( 'Made with Finishcoat', 'finishcoat' ),
		/* translators: %s: site name. */
		'Notes on this page go straight to %s.'           => __( 'Notes on this page go straight to %s.', 'finishcoat' ),
		'Notes on this page go straight to the site owner.' => __( 'Notes on this page go straight to the site owner.', 'finishcoat' ),
		'Open'                                            => __( 'Open', 'finishcoat' ),
		'All'                                             => __( 'All', 'finishcoat' ),
		'Open full size'                                  => __( 'Open full size', 'finishcoat' ),
		/* translators: %s: name of the person who sent the photo. */
		'Photo from %s, open full size'                   => __( 'Photo from %s, open full size', 'finishcoat' ),
		'the client'                                      => __( 'the client', 'finishcoat' ),
		'The photo on the page now'                       => __( 'The photo on the page now', 'finishcoat' ),
		'Before'                                          => __( 'Before', 'finishcoat' ),
		'After'                                           => __( 'After', 'finishcoat' ),
		/* translators: %s: name of the spot on the page, such as a heading. */
		'Suggested for “%s”'                              => __( 'Suggested for “%s”', 'finishcoat' ),
		'a photo'                                         => __( 'a photo', 'finishcoat' ),
		'Show the spot on the page'                       => __( 'Show the spot on the page', 'finishcoat' ),
		/* translators: %d: note number on this page. */
		'Note %d, show the spot on the page'              => __( 'Note %d, show the spot on the page', 'finishcoat' ),
		'Someone'                                         => __( 'Someone', 'finishcoat' ),
		'On'                                              => __( 'On', 'finishcoat' ),
		/* translators: %s: name of the spot on the page, such as a heading. */
		'Pinned to “%s”'                                  => __( 'Pinned to “%s”', 'finishcoat' ),
		'Open on the page'                                => __( 'Open on the page', 'finishcoat' ),
		'Reply'                                           => __( 'Reply', 'finishcoat' ),
		'Status'                                          => __( 'Status', 'finishcoat' ),
		'Delete'                                          => __( 'Delete', 'finishcoat' ),
		'Delete for good?'                                => __( 'Delete for good?', 'finishcoat' ),
		'That did not work.'                              => __( 'That did not work.', 'finishcoat' ),
		'Loading notes...'                                => __( 'Loading notes...', 'finishcoat' ),
		'No notes on this page.'                          => __( 'No notes on this page.', 'finishcoat' ),
		'No notes on this page yet. Write one below. Use Pin it to a spot if it is about one part of the page.' => __( 'No notes on this page yet. Write one below. Use Pin it to a spot if it is about one part of the page.', 'finishcoat' ),
		'Everything on this page is marked done.'         => __( 'Everything on this page is marked done.', 'finishcoat' ),
		'Nothing here yet.'                               => __( 'Nothing here yet.', 'finishcoat' ),
		/* translators: 1: label of the tab, 2: number of open notes. */
		'%1$s, %2$d open on this page'                    => __( '%1$s, %2$d open on this page', 'finishcoat' ),
		/* translators: 1: note number, 2: name of the person. */
		'Note %1$d from %2$s'                             => __( 'Note %1$d from %2$s', 'finishcoat' ),
		/* translators: %s: name of the person. */
		'Replying to %s'                                  => __( 'Replying to %s', 'finishcoat' ),
		/* translators: %d: note number. */
		'note %d'                                         => __( 'note %d', 'finishcoat' ),
		'Cancel'                                          => __( 'Cancel', 'finishcoat' ),
		'Write a reply'                                   => __( 'Write a reply', 'finishcoat' ),
		'this spot'                                       => __( 'this spot', 'finishcoat' ),
		'Click or tap a photo on the page to try your own photo there.' => __( 'Click or tap a photo on the page to try your own photo there.', 'finishcoat' ),
		'Click the spot your note is about.'              => __( 'Click the spot your note is about.', 'finishcoat' ),
		'That spot has no photo. Click or tap a photo.'   => __( 'That spot has no photo. Click or tap a photo.', 'finishcoat' ),
		'Your new note goes here'                         => __( 'Your new note goes here', 'finishcoat' ),
		/* translators: %s: name of the photo on the page. */
		'Pinned to the photo “%s”'                        => __( 'Pinned to the photo “%s”', 'finishcoat' ),
		'Do not suggest it'                               => __( 'Do not suggest it', 'finishcoat' ),
		'Remove pin'                                      => __( 'Remove pin', 'finishcoat' ),
		'Move the pin'                                    => __( 'Move the pin', 'finishcoat' ),
		/* translators: %s: file name. */
		'Only photos (JPEG, PNG, WebP) can be sent. %s was left out.' => __( 'Only photos (JPEG, PNG, WebP) can be sent. %s was left out.', 'finishcoat' ),
		/* translators: 1: file name, 2: largest photo in MB. */
		'%1$s is too big to send. The limit is %2$d MB a photo.' => __( '%1$s is too big to send. The limit is %2$d MB a photo.', 'finishcoat' ),
		/* translators: %s: file name. */
		'%s is empty. Try another photo.'                 => __( '%s is empty. Try another photo.', 'finishcoat' ),
		/* translators: %d: most photos on one note. */
		'You can send up to %d photos with a note.'       => __( 'You can send up to %d photos with a note.', 'finishcoat' ),
		/* translators: %s: name of the photo on the page. */
		'Your photo for “%s”. Only you see it on the page. Send to suggest it.' => __( 'Your photo for “%s”. Only you see it on the page. Send to suggest it.', 'finishcoat' ),
		'1 photo to send'                                 => __( '1 photo to send', 'finishcoat' ),
		/* translators: %d: number of photos. */
		'%d photos to send'                               => __( '%d photos to send', 'finishcoat' ),
		/* translators: %s: file name. */
		'Remove %s'                                       => __( 'Remove %s', 'finishcoat' ),
		'Sending...'                                      => __( 'Sending...', 'finishcoat' ),
		'Write a note first.'                             => __( 'Write a note first.', 'finishcoat' ),
		'Add your name so we know who it is from.'        => __( 'Add your name so we know who it is from.', 'finishcoat' ),
		'Tick the box to confirm you can share these photos.' => __( 'Tick the box to confirm you can share these photos.', 'finishcoat' ),
		'Uploading...'                                    => __( 'Uploading...', 'finishcoat' ),
		'Saving...'                                       => __( 'Saving...', 'finishcoat' ),
		/* translators: %d: percent uploaded. %% is a percent sign. */
		'Uploading... %d%%'                               => __( 'Uploading... %d%%', 'finishcoat' ),
		'Those photos are too big to send at once. Try fewer.' => __( 'Those photos are too big to send at once. Try fewer.', 'finishcoat' ),
		'Reply sent.'                                     => __( 'Reply sent.', 'finishcoat' ),
		'Thanks. Your photo suggestion is in, pinned to that spot.' => __( 'Thanks. Your photo suggestion is in, pinned to that spot.', 'finishcoat' ),
		'Thanks. Your note is in and the site owner has been told.' => __( 'Thanks. Your note is in and the site owner has been told.', 'finishcoat' ),
		'Background photo'                                => __( 'Background photo', 'finishcoat' ),
		/* translators: %d: number of the photo on the page. */
		'Photo %d'                                        => __( 'Photo %d', 'finishcoat' ),
		'Your photo on the page'                          => __( 'Your photo on the page', 'finishcoat' ),
		'Only you can see this photo here. Nothing is sent until you suggest it.' => __( 'Only you can see this photo here. Nothing is sent until you suggest it.', 'finishcoat' ),
		'Suggest this photo'                              => __( 'Suggest this photo', 'finishcoat' ),
		'Try another'                                     => __( 'Try another', 'finishcoat' ),
		'Put the original back'                           => __( 'Put the original back', 'finishcoat' ),
		'Add a note about this photo (optional)'          => __( 'Add a note about this photo (optional)', 'finishcoat' ),
		'Page'                                            => __( 'Page', 'finishcoat' ),
		/* translators: %s: label of the tab, such as Suggestions. */
		'%s board'                                        => __( '%s board', 'finishcoat' ),
		/* translators: %s: label of the tab, such as Suggestions. */
		'Every note left on this site, in one place. Use the %s tab on the right edge of any page to add one.' => __( 'Every note left on this site, in one place. Use the %s tab on the right edge of any page to add one.', 'finishcoat' ),
		'All pages'                                       => __( 'All pages', 'finishcoat' ),
		'Everything'                                      => __( 'Everything', 'finishcoat' ),
		'Photos'                                          => __( 'Photos', 'finishcoat' ),
		'No suggestions yet.'                             => __( 'No suggestions yet.', 'finishcoat' ),
		'Nothing matches those filters.'                  => __( 'Nothing matches those filters.', 'finishcoat' ),
		/* translators: %d: number of notes. */
		'Show %d more'                                    => __( 'Show %d more', 'finishcoat' ),
		/* translators: %s: name of the logged-in user. */
		'Studio mode: replying as %s'                     => __( 'Studio mode: replying as %s', 'finishcoat' ),
		'the site owner'                                  => __( 'the site owner', 'finishcoat' ),
		'Notes or photos? Leave them here.'               => __( 'Notes or photos? Leave them here.', 'finishcoat' ),
	);
}
