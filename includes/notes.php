<?php
/**
 * Notes: the private note type, the public and studio endpoints, the waiting rule, the admin list and email alerts.
 *
 * Notes are private finishcoat_note posts; replies are child posts of the note. Visitors post through admin-ajax
 * (finishcoat_post) with a review code and the public nonce from finishcoat_list. Logged-in editors get studio
 * controls through the studio nonce from finishcoat_session. Guards on posting: the request's origin, a hidden
 * honeypot field, a minimum fill time, length limits, and rate limits per visitor and for the whole site.
 * No cookies are set and IP addresses are only used hashed, in short-lived transients.
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Longest note, in characters.
 */
define( 'FINISHCOAT_MAX_LEN', 2000 );

/**
 * Note statuses with the label clients see.
 *
 * @return array<string, string>
 */
function finishcoat_statuses() {
	return array(
		'new'   => __( 'New', 'finishcoat' ),
		'noted' => __( 'Noted', 'finishcoat' ),
		'doing' => __( 'In progress', 'finishcoat' ),
		'done'  => __( 'Done', 'finishcoat' ),
	);
}

/**
 * Register the private note type. Only editors and administrators see it.
 */
function finishcoat_register_note_type() {
	register_post_type(
		'finishcoat_note',
		array(
			'labels'              => array(
				'name'               => __( 'Client notes', 'finishcoat' ),
				'singular_name'      => __( 'Client note', 'finishcoat' ),
				'menu_name'          => __( 'Client notes', 'finishcoat' ),
				'all_items'          => __( 'Notes', 'finishcoat' ),
				'edit_item'          => __( 'Edit note', 'finishcoat' ),
				'view_item'          => __( 'View note', 'finishcoat' ),
				'search_items'       => __( 'Search notes', 'finishcoat' ),
				'not_found'          => __( 'No notes yet.', 'finishcoat' ),
				'not_found_in_trash' => __( 'No notes in the trash.', 'finishcoat' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => finishcoat_menu_parent(),
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'can_export'          => true,
			'supports'            => array( 'title', 'editor' ),
			'hierarchical'        => false,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'create_posts' => 'do_not_allow',
				'edit_posts'   => 'edit_others_posts',
			),
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'finishcoat_register_note_type' );

/*
 * ------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------
 */

/**
 * Whether the current user may use the studio controls.
 *
 * @return bool
 */
function finishcoat_is_studio() {
	return is_user_logged_in() && current_user_can( 'edit_others_posts' );
}

/**
 * Reduce a URL or Origin header to scheme://host[:port], lowercase, default ports dropped.
 *
 * @param mixed $raw URL or origin.
 * @return string Empty when it is not an http(s) origin.
 */
function finishcoat_normalize_origin( $raw ) {
	$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
	if ( '' === $raw || 'null' === $raw ) {
		return '';
	}
	$u = wp_parse_url( $raw );
	if ( ! is_array( $u ) || empty( $u['scheme'] ) || empty( $u['host'] ) ) {
		return '';
	}
	$scheme = strtolower( $u['scheme'] );
	$host   = strtolower( $u['host'] );
	if ( ( 'http' !== $scheme && 'https' !== $scheme ) || ! preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
		return '';
	}
	$port = isset( $u['port'] ) ? (int) $u['port'] : 0;
	if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
		$port = 0;
	}
	return $scheme . '://' . $host . ( $port ? ':' . $port : '' );
}

/**
 * This site's origin.
 *
 * @return string
 */
function finishcoat_site_origin() {
	$o = finishcoat_normalize_origin( home_url() );
	return '' !== $o ? $o : untrailingslashit( home_url() );
}

/**
 * Origins whose pages may use the endpoints. Lite allows only this site.
 *
 * @return string[]
 */
function finishcoat_allowed_origins() {
	$list = apply_filters( 'finishcoat_allowed_origins', array( finishcoat_site_origin() ) );
	return array_values( array_filter( array_map( 'finishcoat_normalize_origin', is_array( $list ) ? $list : array() ) ) );
}

/**
 * The request's origin: the Origin header, or the Referer's origin when a browser leaves Origin out.
 *
 * @return string
 */
function finishcoat_request_origin() {
	if ( isset( $_SERVER['HTTP_ORIGIN'] ) ) {
		return finishcoat_normalize_origin( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) );
	}
	$ref = wp_get_raw_referer();
	return $ref ? finishcoat_normalize_origin( $ref ) : '';
}

/**
 * Whether the request carries an Origin or a Referer header at all.
 *
 * @return bool
 */
function finishcoat_request_names_origin() {
	return isset( $_SERVER['HTTP_ORIGIN'] ) || '' !== (string) wp_get_raw_referer();
}

/**
 * Whether an origin may post.
 *
 * @param string $origin Origin.
 * @return bool
 */
function finishcoat_origin_ok( $origin ) {
	return '' !== $origin && in_array( $origin, finishcoat_allowed_origins(), true );
}

/**
 * The visitor's IP address, used only hashed for rate limits.
 *
 * @return string
 */
function finishcoat_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/**
 * Hashed key for a visitor, for transients.
 *
 * @param string $kind What is counted.
 * @return string
 */
function finishcoat_visitor_key( $kind ) {
	return 'finishcoat_' . $kind . '_' . substr( hash_hmac( 'sha256', $kind . '|' . finishcoat_client_ip(), wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * Per-visitor (30 an hour) and site-wide (400 an hour) limit for notes. Counts one note when allowed.
 *
 * @return bool
 */
function finishcoat_rate_ok() {
	$limits = apply_filters(
		'finishcoat_rate_limits',
		array(
			'visitor' => 30,
			'site'    => 400,
		)
	);
	$key    = finishcoat_visitor_key( 'rv' );
	$n      = (int) get_transient( $key );
	$all    = (int) get_transient( 'finishcoat_rv_all' );
	if ( $n >= (int) $limits['visitor'] || $all >= (int) $limits['site'] ) {
		return false;
	}
	set_transient( $key, $n + 1, HOUR_IN_SECONDS );
	set_transient( 'finishcoat_rv_all', $all + 1, HOUR_IN_SECONDS );
	return true;
}

/**
 * Clean a page path: allowed characters only, starting with '/', no '..'.
 *
 * @param mixed $raw Path.
 * @return string Clean path, or '' when not valid.
 */
function finishcoat_sanitize_path( $raw ) {
	// Keep every character a URL path may hold unencoded (RFC 3986), so the saved path matches location.pathname.
	// WordPress Playground serves sites under /scope:<name>/, for one.
	$p = (string) preg_replace( "#[^A-Za-z0-9/_.%\\-~:@!$&'()*+,;=]#", '', trim( is_scalar( $raw ) ? (string) $raw : '' ) );
	if ( '' === $p || '/' !== $p[0] || false !== strpos( $p, '..' ) || strlen( $p ) > 500 ) {
		return '';
	}
	return (string) preg_replace( '#/index\.(html|php)$#', '/', $p );
}

/**
 * Clean a CSS selector sent by the page script.
 *
 * @param mixed $sel Selector.
 * @return string
 */
function finishcoat_sanitize_selector( $sel ) {
	return mb_substr( (string) preg_replace( '/[^A-Za-z0-9 >#:._()\-\[\]="\']/', '', is_scalar( $sel ) ? (string) $sel : '' ), 0, 600 );
}

/**
 * Clean a pin anchor. Returns JSON, or '' when there is no usable anchor.
 *
 * @param string $anchor JSON from the page.
 * @return string
 */
function finishcoat_sanitize_anchor( $anchor ) {
	$anchor = (string) $anchor;
	if ( '' === $anchor || strlen( $anchor ) >= 2000 ) {
		return '';
	}
	$a = json_decode( $anchor, true );
	if ( ! is_array( $a ) || ! isset( $a['sel'] ) ) {
		return '';
	}
	$num = function ( $k, $max ) use ( $a ) {
		return max( 0, min( $max, isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ? (float) $a[ $k ] : 0 ) );
	};
	return (string) wp_json_encode(
		array(
			'sel' => finishcoat_sanitize_selector( $a['sel'] ),
			'rx'  => $num( 'rx', 1 ),
			'ry'  => $num( 'ry', 1 ),
			'dx'  => $num( 'dx', 1 ),
			'dy'  => $num( 'dy', 1 ),
			'vw'  => (int) $num( 'vw', 9999 ),
			'lbl' => mb_substr( sanitize_text_field( isset( $a['lbl'] ) && is_scalar( $a['lbl'] ) ? (string) $a['lbl'] : '' ), 0, 80 ),
		)
	);
}

/**
 * One request field, unslashed and run through sanitize_text_field (POST first, then GET).
 *
 * @param string $key Field.
 * @return string
 */
function finishcoat_param( $key ) {
	// phpcs:disable WordPress.Security.NonceVerification -- callers check the nonce or the review code before acting.
	if ( isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ) {
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
	if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) {
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}
	// phpcs:enable
	return '';
}

/**
 * The project a request names (default `site`), or '' when it does not exist.
 *
 * @return string
 */
function finishcoat_request_project() {
	$slug = sanitize_key( finishcoat_param( 'project' ) );
	$slug = '' !== $slug ? $slug : 'site';
	return finishcoat_project( $slug ) ? $slug : '';
}

/**
 * The project a note belongs to.
 *
 * @param int $id Note ID.
 * @return string
 */
function finishcoat_note_project( $id ) {
	$slug = (string) get_post_meta( (int) $id, '_finishcoat_project', true );
	return '' !== $slug ? $slug : 'site';
}

/**
 * The page address of a note, with ?finishcoat_note= so the page opens the note.
 *
 * @param int $id Note ID (a reply links to its note).
 * @return string
 */
function finishcoat_note_link( $id ) {
	$top    = wp_get_post_parent_id( $id );
	$top    = $top ? (int) $top : (int) $id;
	$origin = (string) get_post_meta( $top, '_finishcoat_origin', true );
	$origin = '' !== $origin ? $origin : finishcoat_site_origin();
	$page   = (string) get_post_meta( $top, '_finishcoat_page', true );
	return add_query_arg( 'finishcoat_note', $top, $origin . ( '' !== $page ? $page : '/' ) );
}

/**
 * One note as the page script sees it.
 *
 * @param WP_Post $p Post.
 * @return array
 */
function finishcoat_note_shape( $p ) {
	$anchor = (string) get_post_meta( $p->ID, '_finishcoat_anchor', true );
	$a      = '' !== $anchor ? json_decode( $anchor, true ) : null;
	$photos = get_post_meta( $p->ID, '_finishcoat_photos', true );
	$out    = array(
		'id'     => (int) $p->ID,
		'parent' => (int) $p->post_parent,
		'page'   => (string) get_post_meta( $p->ID, '_finishcoat_page', true ),
		'title'  => (string) get_post_meta( $p->ID, '_finishcoat_page_title', true ),
		'body'   => (string) $p->post_content,
		'author' => (string) get_post_meta( $p->ID, '_finishcoat_author', true ),
		'role'   => (string) get_post_meta( $p->ID, '_finishcoat_role', true ),
		'status' => (string) get_post_meta( $p->ID, '_finishcoat_status', true ),
		'anchor' => is_array( $a ) ? $a : null,
		'time'   => get_post_time( 'c', true, $p ),
	);
	if ( is_array( $photos ) && $photos ) {
		$out['photos'] = array_values( array_map( 'intval', $photos ) );
	}
	$cl = finishcoat_code_label_of( $p->ID );
	if ( '' !== $cl ) {
		$out['code_label'] = $cl;
	}
	return $out;
}

/**
 * A project as the page script sees it. Never includes a review code.
 *
 * @param string $slug Project slug.
 * @return array
 */
function finishcoat_project_payload( $slug ) {
	$p   = finishcoat_project( $slug );
	$out = array(
		'slug'            => $slug,
		'name'            => isset( $p['name'] ) ? (string) $p['name'] : '',
		'label'           => isset( $p['label'] ) ? (string) $p['label'] : finishcoat_tab_label(),
		'active'          => ! finishcoat_setting( 'paused' ),
		'photo_max_mb'    => finishcoat_photo_max_mb(),
		'photo_max_files' => (int) finishcoat_setting( 'photo_max_files' ),
	);
	return (array) apply_filters( 'finishcoat_project_shape', $out, $slug );
}

/**
 * Send JSON with no caching, then stop. With i18n=1 (the first request of a page script loaded in link mode) the
 * answer also carries the script's translated words. Pages on other allowed origins (an add-on) get a CORS header
 * without credentials.
 *
 * @param array $data   Data.
 * @param int   $status HTTP status.
 */
function finishcoat_json( $data, $status = 200 ) {
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	if ( '1' === finishcoat_param( 'i18n' ) && is_array( $data ) ) {
		$data['i18n'] = finishcoat_front_strings();
	}
	$o = finishcoat_request_origin();
	if ( '' !== $o && finishcoat_site_origin() !== $o && finishcoat_origin_ok( $o ) ) {
		header( 'Access-Control-Allow-Origin: ' . $o );
		header( 'Vary: Origin' );
	}
	wp_send_json( $data, $status );
}

/**
 * Every post a query matches, read 100 at a time, up to $max.
 *
 * @param array $args Arguments for get_posts().
 * @param int   $max  Most posts to return.
 * @return array Posts, or IDs when $args['fields'] is 'ids'.
 */
function finishcoat_get_all( $args, $max = 5000 ) {
	$args['posts_per_page'] = 100;
	$args['no_found_rows']  = true;
	$args['paged']          = 1;
	$out                    = array();
	do {
		$batch = get_posts( $args );
		$got   = count( $batch );
		$out   = array_merge( $out, $batch );
		$total = count( $out );
		++$args['paged'];
	} while ( 100 === $got && $total < $max );
	return array_slice( $out, 0, $max );
}

/**
 * Notes of a project, newest first.
 *
 * @param string $slug Project slug.
 * @return WP_Post[]
 */
function finishcoat_project_notes( $slug ) {
	$args = apply_filters(
		'finishcoat_notes_query',
		array(
			'post_type'   => 'finishcoat_note',
			'post_status' => 'private',
			'orderby'     => 'date',
			'order'       => 'DESC',
		),
		$slug
	);
	return finishcoat_get_all( $args, 1000 );
}

/*
 * ------------------------------------------------------------------------
 * Public endpoints
 * ------------------------------------------------------------------------
 */

/**
 * Every note and photo of the project, for the panel and the board. Needs a review code (or the studio nonce).
 * The answer carries the public nonce the page sends with new notes.
 *
 * Read only: it changes nothing. There is no nonce check here on purpose, because this is the request that hands
 * a visitor the nonce in the first place, and review pages are often served from a page cache where a nonce printed
 * into the HTML would go stale. Access is checked by finishcoat_require_code(): without a valid review code (or a
 * logged-in editor's studio nonce) it answers 403 and returns nothing. Every request that writes, finishcoat_post,
 * finishcoat_status and finishcoat_delete, checks a nonce, and the studio ones also check the user's capability.
 */
function finishcoat_ajax_list() {
	$slug = finishcoat_request_project();
	if ( '' === $slug ) {
		finishcoat_json(
			array(
				'ok'      => false,
				'message' => __( 'This site is not set up for notes.', 'finishcoat' ),
			),
			404
		);
	}
	finishcoat_require_code( $slug, finishcoat_studio_request() );
	$notes = array();
	foreach ( finishcoat_project_notes( $slug ) as $post ) {
		$notes[] = finishcoat_note_shape( $post );
	}
	finishcoat_json(
		array(
			'ok'       => true,
			'project'  => finishcoat_project_payload( $slug ),
			'statuses' => finishcoat_statuses(),
			'notes'    => $notes,
			'photos'   => finishcoat_project_photos( $slug ),
			'nonce'    => wp_create_nonce( 'finishcoat_public' ),
		)
	);
}
add_action( 'wp_ajax_finishcoat_list', 'finishcoat_ajax_list' );
add_action( 'wp_ajax_nopriv_finishcoat_list', 'finishcoat_ajax_list' );

/**
 * Logged-in editors get the studio nonce. Anyone else needs a review code and gets nothing but a yes.
 *
 * Read only: it changes nothing. It is how a logged-in editor's page gets the studio nonce, so it can't require one.
 * The studio nonce goes only to a user who passes finishcoat_is_studio() (the edit_others_posts capability).
 */
function finishcoat_ajax_session() {
	if ( finishcoat_is_studio() ) {
		$u = wp_get_current_user();
		finishcoat_json(
			array(
				'ok'     => true,
				'studio' => true,
				'name'   => $u ? (string) $u->display_name : '',
				'nonce'  => wp_create_nonce( 'finishcoat_studio' ),
			)
		);
	}
	$slug = finishcoat_request_project();
	finishcoat_require_code( '' !== $slug ? $slug : 'site', false );
	finishcoat_json(
		array(
			'ok'     => true,
			'studio' => false,
		)
	);
}
add_action( 'wp_ajax_finishcoat_session', 'finishcoat_ajax_session' );
add_action( 'wp_ajax_nopriv_finishcoat_session', 'finishcoat_ajax_session' );

/**
 * Refuse a request with a message.
 *
 * @param string $message Plain text for the visitor.
 * @param int    $status  HTTP status.
 */
function finishcoat_refuse( $message, $status = 400 ) {
	finishcoat_json(
		array(
			'ok'      => false,
			'message' => $message,
		),
		$status
	);
}

/**
 * Post a note or a reply, with up to 3 photos. A photo tried on the page ("Suggest this photo") comes with its
 * spot (`target`) and a small before picture of the original (`before`).
 */
function finishcoat_ajax_post() {
	// Some hosts, proxies and sandboxes (WordPress Playground among them) strip both Origin and Referer.
	// Such a request is let through to the nonce checks below, which still stop any cross-site post.
	// A request that names an origin must name this site.
	$origin = finishcoat_request_names_origin() ? finishcoat_request_origin() : finishcoat_site_origin();
	if ( ! finishcoat_origin_ok( $origin ) ) {
		finishcoat_refuse( __( 'Notes can only be sent from this site\'s pages.', 'finishcoat' ), 403 );
	}
	$slug = finishcoat_request_project();
	if ( '' === $slug ) {
		finishcoat_refuse( __( 'This site is not set up for notes.', 'finishcoat' ) );
	}
	$studio = finishcoat_studio_request();
	finishcoat_require_code( $slug, $studio );
	if ( ! $studio && ! check_ajax_referer( 'finishcoat_public', 'nonce', false ) ) {
		finishcoat_refuse( __( 'This page was open for a long time. Reload it and try again.', 'finishcoat' ), 403 );
	}

	if ( ! $studio && finishcoat_setting( 'paused' ) ) {
		finishcoat_refuse( __( 'New notes are paused right now.', 'finishcoat' ), 403 );
	}

	// Honeypot and fill time: drop silently.
	$hp = finishcoat_param( 'website' );
	$t  = absint( finishcoat_param( 't' ) );
	if ( ! $studio && ( '' !== $hp || $t < (int) finishcoat_setting( 'min_fill_ms' ) ) ) {
		finishcoat_json(
			array(
				'ok'   => true,
				'note' => null,
			)
		);
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce was checked above.
	$body   = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
	$anchor = isset( $_POST['anchor'] ) ? finishcoat_sanitize_anchor( sanitize_text_field( wp_unslash( $_POST['anchor'] ) ) ) : '';
	$target = isset( $_POST['target'] ) ? finishcoat_sanitize_target( sanitize_text_field( wp_unslash( $_POST['target'] ) ) ) : null;
	// phpcs:enable
	$title   = mb_substr( finishcoat_param( 'title' ), 0, 140 );
	$author  = mb_substr( trim( finishcoat_param( 'author' ) ), 0, 60 );
	$parent  = absint( finishcoat_param( 'parent' ) );
	$consent = '1' === finishcoat_param( 'consent' );
	$page    = finishcoat_sanitize_path( finishcoat_param( 'page' ) );
	if ( '' === $page ) {
		finishcoat_refuse( __( 'This page is not set up for notes.', 'finishcoat' ) );
	}
	$body = trim( mb_substr( $body, 0, FINISHCOAT_MAX_LEN ) );
	if ( $studio ) {
		$u      = wp_get_current_user();
		$author = $u && '' !== (string) $u->display_name ? (string) $u->display_name : finishcoat_site_name();
	} elseif ( '' === $author ) {
		finishcoat_refuse( __( 'Add your name so we know who it is from.', 'finishcoat' ) );
	}

	// Replies: same project, flattened to the top note, same page as that note.
	$note_origin = $origin;
	if ( $parent ) {
		$pp = get_post( $parent );
		if ( ! $pp || 'finishcoat_note' !== $pp->post_type || 'private' !== $pp->post_status || finishcoat_note_project( $parent ) !== $slug ) {
			finishcoat_refuse( __( 'That note is gone.', 'finishcoat' ) );
		}
		if ( $pp->post_parent ) {
			$parent = (int) $pp->post_parent;
		}
		$page        = (string) get_post_meta( $parent, '_finishcoat_page', true );
		$saved       = (string) get_post_meta( $parent, '_finishcoat_origin', true );
		$note_origin = '' !== $saved ? $saved : $origin;
		$anchor      = '';
		$target      = null;
	}

	$files  = finishcoat_photo_files_from_request( 'photos' );
	$before = $target ? finishcoat_photo_files_from_request( 'before' ) : array();
	if ( $files && ! $consent ) {
		finishcoat_refuse( __( 'Tick the box to confirm you can share these photos.', 'finishcoat' ) );
	}
	if ( $target && ! $files ) {
		finishcoat_refuse( __( 'Choose the photo you want to suggest.', 'finishcoat' ) );
	}
	if ( '' === $body ) {
		if ( $target ) {
			/* translators: %s: name of the spot on the page, such as a heading. */
			$body = sprintf( __( 'Suggested a photo for "%s".', 'finishcoat' ), $target['lbl'] );
		} elseif ( $files ) {
			/* translators: %d: number of photos. */
			$body = sprintf( _n( 'Sent %d photo.', 'Sent %d photos.', count( $files ), 'finishcoat' ), count( $files ) );
		} else {
			finishcoat_refuse( __( 'Write a note first.', 'finishcoat' ) );
		}
	}

	// Rate limits, checked before any work.
	if ( ! $studio && $files && ! finishcoat_photo_rate_ok( count( $files ) ) ) {
		finishcoat_refuse( __( 'That is a lot of photos in a short time. Try again in an hour.', 'finishcoat' ), 429 );
	}

	// Photos: check all, then write all, or none.
	$done = array();
	if ( $files ) {
		$done = finishcoat_handle_upload( $files, $slug );
		if ( is_wp_error( $done ) ) {
			$data = (array) $done->get_error_data();
			if ( 'finishcoat_cap' === $done->get_error_code() ) {
				finishcoat_mail_out_of_space( $author );
			}
			$out = array(
				'ok'      => false,
				'message' => $done->get_error_message(),
			);
			if ( ! empty( $data['file'] ) ) {
				$out['file'] = $data['file'];
			}
			finishcoat_json( $out, isset( $data['status'] ) ? (int) $data['status'] : 400 );
		}
	}
	$before_file = $before ? finishcoat_handle_before( $before[0], $slug ) : null;

	if ( ! $studio && ! finishcoat_rate_ok() ) {
		finishcoat_unlink_all( finishcoat_written_files( $done ) );
		if ( $before_file ) {
			finishcoat_unlink_all( array( finishcoat_photo_abs( $before_file ) ) );
		}
		finishcoat_refuse( __( 'That is a lot of notes in a short time. Try again in an hour.', 'finishcoat' ), 429 );
	}
	if ( ! $studio && $done ) {
		finishcoat_photo_rate_add( count( $done ) );
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'finishcoat_note',
			'post_status'  => 'private',
			'post_parent'  => $parent,
			'post_title'   => mb_substr( $author . ': ' . preg_replace( '/\s+/', ' ', $body ), 0, 90 ),
			'post_content' => $body,
		),
		true
	);
	if ( is_wp_error( $id ) || ! $id ) {
		finishcoat_unlink_all( finishcoat_written_files( $done ) );
		finishcoat_refuse( __( 'Something went wrong on our side. Try again in a minute.', 'finishcoat' ), 500 );
	}
	update_post_meta( $id, '_finishcoat_project', $slug );
	update_post_meta( $id, '_finishcoat_page', $page );
	update_post_meta( $id, '_finishcoat_page_title', wp_slash( $title ) );
	update_post_meta( $id, '_finishcoat_author', wp_slash( $author ) );
	update_post_meta( $id, '_finishcoat_role', $studio ? 'studio' : 'client' );
	update_post_meta( $id, '_finishcoat_origin', $note_origin );
	if ( ! $parent ) {
		update_post_meta( $id, '_finishcoat_status', 'new' );
	}
	if ( '' !== $anchor ) {
		update_post_meta( $id, '_finishcoat_anchor', wp_slash( $anchor ) );
	}
	finishcoat_code_mark( $id );

	$ids = $done ? finishcoat_save_photos( $id, $slug, $page, $author, $done, $target, $before_file ) : array();
	if ( $ids ) {
		update_post_meta( $id, '_finishcoat_photos', $ids );
	}
	finishcoat_bust_waiting();

	$out = array(
		'ok'     => true,
		'note'   => finishcoat_note_shape( get_post( $id ) ),
		'photos' => array_map( 'finishcoat_photo_shape', array_filter( array_map( 'get_post', $ids ) ) ),
	);
	if ( ! $studio ) {
		finishcoat_mail_note( $id, $ids );
	}
	do_action( 'finishcoat_note_sent', $id, $ids, $studio );
	finishcoat_json( $out );
}
add_action( 'wp_ajax_finishcoat_post', 'finishcoat_ajax_post' );
add_action( 'wp_ajax_nopriv_finishcoat_post', 'finishcoat_ajax_post' );

/*
 * ------------------------------------------------------------------------
 * Studio endpoints
 * ------------------------------------------------------------------------
 */

/**
 * Check a studio request: editor, valid studio nonce, a note that exists. Returns the note ID.
 *
 * @return int
 */
function finishcoat_studio_note() {
	if ( ! finishcoat_is_studio() || ! check_ajax_referer( 'finishcoat_studio', 'nonce', false ) ) {
		finishcoat_refuse( __( 'Log in to WordPress to do that.', 'finishcoat' ), 403 );
	}
	$id = absint( finishcoat_param( 'id' ) );
	$p  = $id ? get_post( $id ) : null;
	if ( ! $p || 'finishcoat_note' !== $p->post_type || ! current_user_can( 'edit_post', $id ) ) {
		finishcoat_refuse( __( 'That note is gone.', 'finishcoat' ), 404 );
	}
	return $id;
}

/**
 * Set a note's status.
 *
 * @param int    $id Note ID.
 * @param string $s  One of finishcoat_statuses().
 * @return bool False for an unknown status.
 */
function finishcoat_set_note_status( $id, $s ) {
	if ( ! array_key_exists( $s, finishcoat_statuses() ) ) {
		return false;
	}
	update_post_meta( (int) $id, '_finishcoat_status', $s );
	finishcoat_bust_waiting();
	return true;
}

/**
 * Studio: set a note's status from the page.
 */
function finishcoat_ajax_status() {
	$id = finishcoat_studio_note();
	if ( ! finishcoat_set_note_status( $id, sanitize_key( finishcoat_param( 'status' ) ) ) ) {
		finishcoat_refuse( __( 'Unknown status.', 'finishcoat' ) );
	}
	finishcoat_json(
		array(
			'ok'   => true,
			'note' => finishcoat_note_shape( get_post( $id ) ),
		)
	);
}
add_action( 'wp_ajax_finishcoat_status', 'finishcoat_ajax_status' );

/**
 * Studio: delete a note (and its replies, and their photos) from the page.
 */
function finishcoat_ajax_delete() {
	$id = finishcoat_studio_note();
	if ( ! current_user_can( 'delete_post', $id ) ) {
		finishcoat_refuse( __( 'You are not allowed to do that.', 'finishcoat' ), 403 );
	}
	foreach ( finishcoat_reply_ids( $id ) as $k ) {
		wp_delete_post( $k, true );
	}
	wp_delete_post( $id, true );
	finishcoat_bust_waiting();
	finishcoat_json( array( 'ok' => true ) );
}
add_action( 'wp_ajax_finishcoat_delete', 'finishcoat_ajax_delete' );

/**
 * IDs of a note's replies.
 *
 * @param int $id Note ID.
 * @return int[]
 */
function finishcoat_reply_ids( $id ) {
	return array_map(
		'intval',
		finishcoat_get_all(
			array(
				'post_type'   => 'finishcoat_note',
				'post_status' => 'any',
				'post_parent' => (int) $id,
				'fields'      => 'ids',
			)
		)
	);
}

/**
 * Deleting a note for good takes its replies along (the admin list's Delete Permanently, Empty Trash).
 *
 * @param int $post_id Post ID.
 */
function finishcoat_delete_replies( $post_id ) {
	$p = get_post( $post_id );
	if ( ! $p || 'finishcoat_note' !== $p->post_type || $p->post_parent ) {
		return;
	}
	foreach ( finishcoat_reply_ids( $post_id ) as $k ) {
		wp_delete_post( $k, true );
	}
}
add_action( 'before_delete_post', 'finishcoat_delete_replies', 5 );

/*
 * ------------------------------------------------------------------------
 * What waits for the studio (the count bubble and the Waiting view)
 * ------------------------------------------------------------------------
 */

/**
 * Notes that wait: a client's note that is not Done, and a client's reply with no studio reply after it in a
 * thread that is not Done. Cached for five minutes and cleared on every change.
 *
 * @return int[]
 */
function finishcoat_waiting_ids() {
	$cached = get_transient( 'finishcoat_waiting' );
	if ( is_array( $cached ) ) {
		return array_map( 'intval', $cached );
	}
	$posts  = finishcoat_get_all(
		array(
			'post_type'   => 'finishcoat_note',
			'post_status' => 'private',
			'orderby'     => 'ID',
			'order'       => 'ASC',
		)
	);
	$status = array();
	$studio = array();
	foreach ( $posts as $p ) {
		if ( ! $p->post_parent ) {
			$status[ $p->ID ] = (string) get_post_meta( $p->ID, '_finishcoat_status', true );
		} elseif ( 'studio' === get_post_meta( $p->ID, '_finishcoat_role', true ) ) {
			$studio[ $p->post_parent ] = max( isset( $studio[ $p->post_parent ] ) ? $studio[ $p->post_parent ] : 0, (int) $p->ID );
		}
	}
	$out = array();
	foreach ( $posts as $p ) {
		if ( 'studio' === get_post_meta( $p->ID, '_finishcoat_role', true ) ) {
			continue;
		}
		if ( ! $p->post_parent ) {
			if ( 'done' !== $status[ $p->ID ] ) {
				$out[] = (int) $p->ID;
			}
		} elseif ( isset( $status[ $p->post_parent ] ) && 'done' !== $status[ $p->post_parent ] && ( ! isset( $studio[ $p->post_parent ] ) || $studio[ $p->post_parent ] < $p->ID ) ) {
			$out[] = (int) $p->ID;
		}
	}
	set_transient( 'finishcoat_waiting', $out, 5 * MINUTE_IN_SECONDS );
	return $out;
}

/**
 * How many notes wait.
 *
 * @return int
 */
function finishcoat_waiting_count() {
	return max( 0, (int) apply_filters( 'finishcoat_note_waiting_count', count( finishcoat_waiting_ids() ) ) );
}

/**
 * Clear the waiting cache.
 */
function finishcoat_bust_waiting() {
	delete_transient( 'finishcoat_waiting' );
}

/**
 * Clear the waiting cache when a note changes in wp-admin (trash, restore, delete, edit).
 *
 * @param int $post_id Post ID.
 */
function finishcoat_note_changed( $post_id ) {
	if ( 'finishcoat_note' === get_post_type( $post_id ) ) {
		finishcoat_bust_waiting();
	}
}
add_action( 'trashed_post', 'finishcoat_note_changed' );
add_action( 'untrashed_post', 'finishcoat_note_changed' );
add_action( 'deleted_post', 'finishcoat_note_changed' );
add_action( 'save_post_finishcoat_note', 'finishcoat_note_changed' );

/*
 * ------------------------------------------------------------------------
 * Admin list
 * ------------------------------------------------------------------------
 */

/**
 * Columns of the notes list.
 *
 * @param array $cols Columns.
 * @return array
 */
function finishcoat_columns( $cols ) {
	return array(
		'cb'                => isset( $cols['cb'] ) ? $cols['cb'] : '',
		'title'             => __( 'Note', 'finishcoat' ),
		'finishcoat_from'   => __( 'From', 'finishcoat' ),
		'finishcoat_page'   => __( 'Page', 'finishcoat' ),
		'finishcoat_status' => __( 'Status', 'finishcoat' ),
		'finishcoat_photos' => __( 'Photos', 'finishcoat' ),
		'date'              => __( 'Date', 'finishcoat' ),
	);
}
add_filter( 'manage_finishcoat_note_posts_columns', 'finishcoat_columns' );

/**
 * Values of the notes list columns.
 *
 * @param string $col Column.
 * @param int    $id  Post ID.
 */
function finishcoat_column( $col, $id ) {
	if ( 'finishcoat_from' === $col ) {
		echo esc_html( (string) get_post_meta( $id, '_finishcoat_author', true ) );
		$cl = finishcoat_code_label_of( $id );
		if ( '' !== $cl ) {
			echo '<br><span class="description">' . esc_html( $cl ) . '</span>';
		}
		if ( 'studio' === get_post_meta( $id, '_finishcoat_role', true ) ) {
			echo '<br><span class="description">' . esc_html__( 'Studio reply', 'finishcoat' ) . '</span>';
		}
	} elseif ( 'finishcoat_page' === $col ) {
		$top   = wp_get_post_parent_id( $id );
		$top   = $top ? (int) $top : (int) $id;
		$page  = (string) get_post_meta( $top, '_finishcoat_page', true );
		$title = (string) get_post_meta( $top, '_finishcoat_page_title', true );
		echo '<a href="' . esc_url( finishcoat_note_link( $id ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $title ? $title : $page ) . '</a>';
		$a = json_decode( (string) get_post_meta( $top, '_finishcoat_anchor', true ), true );
		if ( is_array( $a ) && ! empty( $a['lbl'] ) ) {
			/* translators: %s: name of the spot the note is pinned to. */
			echo '<br><span class="description">' . esc_html( sprintf( __( 'Pinned to "%s"', 'finishcoat' ), $a['lbl'] ) ) . '</span>';
		}
	} elseif ( 'finishcoat_status' === $col ) {
		$st = finishcoat_statuses();
		$s  = (string) get_post_meta( $id, '_finishcoat_status', true );
		if ( isset( $st[ $s ] ) ) {
			echo esc_html( $st[ $s ] );
		} elseif ( wp_get_post_parent_id( $id ) ) {
			echo esc_html__( 'Reply', 'finishcoat' );
		}
		if ( in_array( (int) $id, finishcoat_waiting_ids(), true ) ) {
			echo '<br><span class="finishcoat-chip finishcoat-chip-wait">' . esc_html__( 'Waiting', 'finishcoat' ) . '</span>';
		}
	} elseif ( 'finishcoat_photos' === $col ) {
		finishcoat_photo_admin_cell( $id );
	}
}
add_action( 'manage_finishcoat_note_posts_custom_column', 'finishcoat_column', 10, 2 );

/**
 * Add Waiting to the views above the notes list.
 *
 * @param array $views Views.
 * @return array
 */
function finishcoat_list_views( $views ) {
	$n    = count( finishcoat_waiting_ids() );
	$cur  = 'waiting' === sanitize_key( finishcoat_param( 'finishcoat_view' ) );
	$url  = add_query_arg(
		array(
			'post_type'       => 'finishcoat_note',
			'finishcoat_view' => 'waiting',
		),
		admin_url( 'edit.php' )
	);
	$link = '<a href="' . esc_url( $url ) . '"' . ( $cur ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Waiting', 'finishcoat' ) . ' <span class="count">(' . absint( $n ) . ')</span></a>';
	if ( $cur ) {
		foreach ( $views as $k => $v ) {
			$views[ $k ] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $v );
		}
	}
	return array_merge( array( 'finishcoat_waiting' => $link ), $views );
}
add_filter( 'views_edit-finishcoat_note', 'finishcoat_list_views' );

/**
 * Apply the Waiting view to the notes list.
 *
 * @param WP_Query $q Query.
 */
function finishcoat_filter_admin_query( $q ) {
	global $pagenow;
	if ( ! is_admin() || ! $q->is_main_query() || 'edit.php' !== $pagenow || 'finishcoat_note' !== $q->get( 'post_type' ) ) {
		return;
	}
	if ( 'waiting' === sanitize_key( finishcoat_param( 'finishcoat_view' ) ) ) {
		$ids = finishcoat_waiting_ids();
		$q->set( 'post__in', $ids ? $ids : array( 0 ) );
		$q->set( 'post_status', 'private' );
	}
}
add_action( 'pre_get_posts', 'finishcoat_filter_admin_query' );

/**
 * An "Open on the page" row action.
 *
 * @param array   $actions Row actions.
 * @param WP_Post $post    Post.
 * @return array
 */
function finishcoat_row_actions( $actions, $post ) {
	if ( 'finishcoat_note' === $post->post_type ) {
		unset( $actions['inline hide-if-no-js'] );
		$actions['finishcoat_open'] = '<a href="' . esc_url( finishcoat_note_link( $post->ID ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open on the page', 'finishcoat' ) . '</a>';
	}
	return $actions;
}
add_filter( 'post_row_actions', 'finishcoat_row_actions', 10, 2 );

/*
 * ------------------------------------------------------------------------
 * Email
 * ------------------------------------------------------------------------
 */

/**
 * Email the recipients about a client note or reply, with its photos as linked thumbnails.
 *
 * @param int   $id  Note ID.
 * @param int[] $ids Photo IDs.
 * @return array Result of finishcoat_send_mail().
 */
function finishcoat_mail_note( $id, $ids ) {
	$p       = get_post( $id );
	$site    = finishcoat_site_name();
	$author  = (string) get_post_meta( $id, '_finishcoat_author', true );
	$top     = $p->post_parent ? (int) $p->post_parent : (int) $id;
	$title   = (string) get_post_meta( $top, '_finishcoat_page_title', true );
	$page    = (string) get_post_meta( $top, '_finishcoat_page', true );
	$code    = finishcoat_code_label_of( $id );
	$suggest = false;
	foreach ( $ids as $pid ) {
		$suggest = $suggest || 'suggested' === get_post_meta( $pid, '_finishcoat_status', true );
	}
	if ( $suggest ) {
		/* translators: 1: site name, 2: name of the person. */
		$subject = sprintf( __( '[%1$s] Photo suggested by %2$s', 'finishcoat' ), $site, $author );
	} elseif ( $p->post_parent ) {
		/* translators: 1: site name, 2: name of the person. */
		$subject = sprintf( __( '[%1$s] Reply from %2$s', 'finishcoat' ), $site, $author );
	} else {
		/* translators: 1: site name, 2: name of the person. */
		$subject = sprintf( __( '[%1$s] New note from %2$s', 'finishcoat' ), $site, $author );
	}
	$who = '' !== $code ? $author . ' (' . $code . ')' : $author;
	$on  = '' !== $title ? $title : $page;
	/* translators: 1: name of the person (and their code label), 2: page title. */
	$head  = sprintf( $p->post_parent ? __( '%1$s replied on %2$s', 'finishcoat' ) : __( '%1$s left a note on %2$s', 'finishcoat' ), $who, $on );
	$html  = '<p style="margin:0 0 12px;color:#5B5476">' . esc_html( $head ) . '</p>';
	$html .= '<p style="margin:0 0 16px;white-space:pre-wrap">' . esc_html( (string) $p->post_content ) . '</p>';
	foreach ( $ids as $pid ) {
		$html .= finishcoat_photo_mail_html( $pid );
	}
	$html .= '<p style="margin:16px 0 0"><a href="' . esc_url( finishcoat_note_link( $id ) ) . '" style="color:#120A2E;font-weight:600">' . esc_html__( 'Open the note on the page', 'finishcoat' ) . '</a></p>';
	$html .= '<p style="margin:6px 0 0"><a href="' . esc_url( admin_url( finishcoat_menu_parent() ) ) . '" style="color:#5B5476">' . esc_html__( 'All notes', 'finishcoat' ) . '</a></p>';
	return finishcoat_send_mail( finishcoat_recipients(), $subject, finishcoat_mail_layout( $html ) );
}
