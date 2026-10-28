<?php
/**
 * Photos attached to notes and replies (1 to 3 each), and photos suggested for a spot on the page.
 *
 * Photos are private finishcoat_photo posts whose parent is their note. They are never placed into the page for
 * anyone. Files go through wp_handle_upload() into uploads/finishcoat/_originals/ (the file as sent, kept with a
 * random name behind a deny-all rule, downloadable by editors). From that, a display copy (re-saved, so location
 * and camera data are gone, at most 2,560 px) and a thumbnail are written to uploads/finishcoat/{project}/.
 * The Media Library is not used.
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the private photo type. It has no screen of its own: the Photos screen lists them.
 */
function finishcoat_register_photo_type() {
	register_post_type(
		'finishcoat_photo',
		array(
			'labels'              => array(
				'name'          => __( 'Client photos', 'finishcoat' ),
				'singular_name' => __( 'Client photo', 'finishcoat' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'can_export'          => false,
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
			'map_meta_cap'        => true,
		)
	);
}
add_action( 'init', 'finishcoat_register_photo_type' );

/**
 * Photo statuses with the label the studio sees.
 *
 * @return array<string, string>
 */
function finishcoat_photo_statuses() {
	$list = apply_filters(
		'finishcoat_photo_statuses',
		array(
			'attached'  => __( 'Attached', 'finishcoat' ),
			'suggested' => __( 'Suggested for a spot', 'finishcoat' ),
		)
	);
	return is_array( $list ) ? $list : array();
}

/**
 * The one line about Pro under a suggested photo, on the plugin's own screens only.
 */
function finishcoat_pro_photo_line() {
	echo '<p class="finishcoat-pro-line">' . esc_html__( 'With Pro, this photo would already be in place for you to approve.', 'finishcoat' ) . '</p>';
}

/**
 * Largest single photo in bytes: the setting, or what this server accepts if that is lower.
 *
 * @return int
 */
function finishcoat_photo_max_bytes() {
	$set    = (int) finishcoat_setting( 'photo_max_mb' ) * MB_IN_BYTES;
	$server = (int) wp_max_upload_size();
	return $server > 0 ? min( $set, $server ) : $set;
}

/**
 * Largest single photo in whole MB, for the page script.
 *
 * @return int
 */
function finishcoat_photo_max_mb() {
	return max( 1, (int) floor( finishcoat_photo_max_bytes() / MB_IN_BYTES ) );
}

/**
 * URL of the Photos screen.
 *
 * @param array $args Extra query args.
 * @return string
 */
function finishcoat_photos_url( $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'finishcoat-photos' ), $args ), admin_url( finishcoat_menu_parent() ) );
}

/*
 * ------------------------------------------------------------------------
 * Files
 * ------------------------------------------------------------------------
 */

/**
 * Uploads base dir and URL, without creating a year/month folder.
 *
 * @return array{dir: string, url: string}
 */
function finishcoat_upload_base() {
	$u = wp_upload_dir( null, false );
	return array(
		'dir' => untrailingslashit( (string) $u['basedir'] ),
		'url' => untrailingslashit( (string) $u['baseurl'] ),
	);
}

/**
 * Write a small guard file if it is not there yet.
 *
 * @param string $path    File path.
 * @param string $content Content.
 */
function finishcoat_put_guard_file( $path, $content ) {
	global $wp_filesystem;
	if ( file_exists( $path ) ) {
		return;
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( WP_Filesystem() && $wp_filesystem ) {
		$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}
}

/**
 * Folder name for a project's display files.
 *
 * @param string $slug Project slug.
 * @return string
 */
function finishcoat_photo_folder( $slug ) {
	$slug = sanitize_key( $slug );
	return '' === $slug || '_originals' === $slug ? 'site' : $slug;
}

/**
 * Create the photo folders (when first needed) with their guard files.
 *
 * @param string $slug Project slug.
 * @return array{root: string, project: string, originals: string, url: string}|WP_Error
 */
function finishcoat_photo_dirs( $slug ) {
	$b    = finishcoat_upload_base();
	$root = $b['dir'] . '/finishcoat';
	$dirs = array(
		'root'      => $root,
		'project'   => $root . '/' . finishcoat_photo_folder( $slug ),
		'originals' => $root . '/_originals',
	);
	foreach ( $dirs as $d ) {
		if ( ! wp_mkdir_p( $d ) ) {
			return new WP_Error( 'finishcoat_dir', 'Could not create a folder.' );
		}
	}
	finishcoat_put_guard_file( $root . '/.htaccess', "# Finishcoat: client photos.\nOptions -Indexes\n" );
	finishcoat_put_guard_file( $root . '/index.php', "<?php\n// Silence is golden.\n" );
	finishcoat_put_guard_file( $dirs['project'] . '/index.php', "<?php\n// Silence is golden.\n" );
	finishcoat_put_guard_file(
		$dirs['originals'] . '/.htaccess',
		"# Finishcoat: original client photos. Never served; editors download them through WordPress.\n"
		. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
	);
	finishcoat_put_guard_file( $dirs['originals'] . '/index.php', "<?php\n// Silence is golden.\n" );
	$dirs['url'] = $b['url'] . '/finishcoat';
	return $dirs;
}

/**
 * Absolute path of a stored relative photo path, or '' when it is not one of ours.
 *
 * @param string $rel Path relative to the uploads base dir.
 * @return string
 */
function finishcoat_photo_abs( $rel ) {
	$rel = ltrim( (string) $rel, '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) || 0 !== strpos( $rel, 'finishcoat/' ) ) {
		return '';
	}
	$b = finishcoat_upload_base();
	return $b['dir'] . '/' . $rel;
}

/**
 * Path relative to the uploads base dir, or '' when the file is outside it.
 *
 * @param string $abs Absolute path.
 * @return string
 */
function finishcoat_photo_rel( $abs ) {
	$b    = finishcoat_upload_base();
	$abs  = wp_normalize_path( (string) $abs );
	$base = wp_normalize_path( $b['dir'] ) . '/';
	return 0 === strpos( $abs, $base ) ? substr( $abs, strlen( $base ) ) : '';
}

/**
 * Public URL of a stored display file or thumbnail.
 *
 * @param string $rel Relative path.
 * @return string
 */
function finishcoat_photo_url( $rel ) {
	$b = finishcoat_upload_base();
	return '' !== (string) $rel ? $b['url'] . '/' . ltrim( (string) $rel, '/' ) : '';
}

/**
 * Remove files written in this request (used when an upload fails part way).
 *
 * @param string[] $paths Absolute paths.
 */
function finishcoat_unlink_all( $paths ) {
	foreach ( (array) $paths as $p ) {
		if ( '' !== (string) $p && is_file( $p ) ) {
			wp_delete_file( $p );
		}
	}
}

/*
 * ------------------------------------------------------------------------
 * Reading the request
 * ------------------------------------------------------------------------
 */

/**
 * Files sent in a field (photos[] or a single file), as a flat list. Empty file inputs are left out.
 *
 * @param string $field Field name.
 * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
 */
function finishcoat_photo_files_from_request( $field ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- the caller checked the nonce; each value is cast and checked, and the files themselves are checked by finishcoat_validate_photo().
	if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
		return array();
	}
	$f   = $_FILES[ $field ];
	$out = array();
	if ( isset( $f['name'] ) && is_array( $f['name'] ) ) {
		foreach ( array_keys( $f['name'] ) as $i ) {
			if ( is_array( $f['name'][ $i ] ) ) {
				continue;
			}
			$out[] = array(
				'name'     => sanitize_file_name( (string) $f['name'][ $i ] ),
				'type'     => isset( $f['type'][ $i ] ) ? sanitize_mime_type( (string) $f['type'][ $i ] ) : '',
				'tmp_name' => isset( $f['tmp_name'][ $i ] ) ? (string) $f['tmp_name'][ $i ] : '',
				'error'    => isset( $f['error'][ $i ] ) ? (int) $f['error'][ $i ] : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $f['size'][ $i ] ) ? (int) $f['size'][ $i ] : 0,
			);
		}
	} elseif ( isset( $f['name'] ) && is_scalar( $f['name'] ) ) {
		$out[] = array(
			'name'     => sanitize_file_name( (string) $f['name'] ),
			'type'     => isset( $f['type'] ) ? sanitize_mime_type( (string) $f['type'] ) : '',
			'tmp_name' => isset( $f['tmp_name'] ) ? (string) $f['tmp_name'] : '',
			'error'    => isset( $f['error'] ) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $f['size'] ) ? (int) $f['size'] : 0,
		);
	}
	// phpcs:enable
	return array_values(
		array_filter(
			$out,
			function ( $x ) {
				return UPLOAD_ERR_NO_FILE !== $x['error'];
			}
		)
	);
}

/**
 * Clean the spot of a suggested photo: { sel, fp, lbl, kind }. Null when there is none or it can't be read.
 *
 * @param string $raw JSON from the page.
 * @return array|null
 */
function finishcoat_sanitize_target( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw || 'null' === $raw || strlen( $raw ) >= 2000 ) {
		return null;
	}
	$t = json_decode( $raw, true );
	if ( ! is_array( $t ) || ! isset( $t['sel'] ) ) {
		return null;
	}
	$sel = finishcoat_sanitize_selector( $t['sel'] );
	if ( '' === trim( $sel ) ) {
		return null;
	}
	$fp  = strtolower( isset( $t['fp'] ) && is_scalar( $t['fp'] ) ? (string) $t['fp'] : '' );
	$lbl = mb_substr( sanitize_text_field( isset( $t['lbl'] ) && is_scalar( $t['lbl'] ) ? (string) $t['lbl'] : '' ), 0, 80 );
	return array(
		'sel'  => $sel,
		'fp'   => preg_match( '/^[0-9a-f]{1,8}$/', $fp ) ? $fp : '',
		'lbl'  => '' !== $lbl ? $lbl : __( 'Photo', 'finishcoat' ),
		'kind' => isset( $t['kind'] ) && 'bg' === $t['kind'] ? 'bg' : 'img',
	);
}

/*
 * ------------------------------------------------------------------------
 * Checking and processing
 * ------------------------------------------------------------------------
 */

/**
 * An error for one file.
 *
 * @param string $code    Code.
 * @param string $message Message for the visitor.
 * @param string $name    File name.
 * @param int    $status  HTTP status.
 * @return WP_Error
 */
function finishcoat_photo_error( $code, $message, $name = '', $status = 400 ) {
	return new WP_Error(
		$code,
		$message,
		array(
			'status' => $status,
			'file'   => mb_substr( sanitize_file_name( (string) $name ), 0, 120 ),
		)
	);
}

/**
 * The message for a photo the server can't read.
 *
 * @return string
 */
function finishcoat_unreadable_message() {
	return __( 'This photo can\'t be read. Try saving it as a JPEG.', 'finishcoat' );
}

/**
 * The message for a failure on the server.
 *
 * @return string
 */
function finishcoat_server_message() {
	return __( 'Something went wrong on our side. Try again in a minute.', 'finishcoat' );
}

/**
 * Types a photo may have, by extension.
 *
 * @return array<string, string>
 */
function finishcoat_upload_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'heic'         => 'image/heic',
		'heif'         => 'image/heif',
	);
}

/**
 * Check one uploaded file: upload error, size, real type, pixels. Does not decode the whole image.
 *
 * @param array $f         One entry from finishcoat_photo_files_from_request().
 * @param int   $max_bytes Largest allowed file.
 * @return array{mime: string, w: int, h: int, bytes: int}|WP_Error
 */
function finishcoat_validate_photo( $f, $max_bytes ) {
	$name = $f['name'];
	/* translators: %d: largest photo in MB. */
	$too_big = sprintf( __( 'Each photo can be up to %d MB.', 'finishcoat' ), max( 1, (int) floor( $max_bytes / MB_IN_BYTES ) ) );
	if ( UPLOAD_ERR_INI_SIZE === $f['error'] || UPLOAD_ERR_FORM_SIZE === $f['error'] ) {
		return finishcoat_photo_error( 'finishcoat_size', $too_big, $name, 413 );
	}
	$missing = __( 'A photo didn\'t come through. Try again.', 'finishcoat' );
	if ( UPLOAD_ERR_OK !== $f['error'] || '' === $f['tmp_name'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
		return finishcoat_photo_error( 'finishcoat_upload', $missing, $name );
	}
	$size = (int) filesize( $f['tmp_name'] );
	if ( $size <= 0 ) {
		return finishcoat_photo_error( 'finishcoat_upload', $missing, $name );
	}
	if ( $size > $max_bytes ) {
		return finishcoat_photo_error( 'finishcoat_size', $too_big, $name, 413 );
	}

	// The real type, from the file's bytes, never from its name or the browser.
	$mime = '';
	if ( function_exists( 'finfo_open' ) ) {
		$fi = finfo_open( FILEINFO_MIME_TYPE );
		if ( $fi ) {
			$mime = (string) finfo_file( $fi, $f['tmp_name'] );
			finfo_close( $fi );
		}
	}
	if ( '' === $mime || 'application/octet-stream' === $mime ) {
		$m    = wp_get_image_mime( $f['tmp_name'] );
		$mime = $m ? (string) $m : $mime;
	}
	$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
	$heic = __( 'This phone photo format can\'t be read here. Send it as a JPEG: on iPhone, Settings > Camera > Formats > Most Compatible.', 'finishcoat' );
	if ( in_array( $mime, array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ), true )
		|| ( ( '' === $mime || 'application/octet-stream' === $mime ) && in_array( $ext, array( 'heic', 'heif' ), true ) ) ) {
		if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/heic' ) ) ) {
			return finishcoat_photo_error( 'finishcoat_heic', $heic, $name );
		}
		$mime = 'image/heif' === $mime || 'heif' === $ext ? 'image/heif' : 'image/heic';
	} elseif ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return finishcoat_photo_error( 'finishcoat_type', __( 'Only photos (JPEG, PNG, WebP) can be sent.', 'finishcoat' ), $name );
	}

	$w = 0;
	$h = 0;
	if ( 'image/heic' !== $mime && 'image/heif' !== $mime ) {
		$info = wp_getimagesize( $f['tmp_name'] );
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || ( isset( $info['mime'] ) && $info['mime'] !== $mime ) ) {
			return finishcoat_photo_error( 'finishcoat_read', finishcoat_unreadable_message(), $name );
		}
		$w = (int) $info[0];
		$h = (int) $info[1];
		if ( $w * $h > 60000000 ) {
			return finishcoat_photo_error( 'finishcoat_pixels', __( 'This photo is too large to work with. Send a smaller copy.', 'finishcoat' ), $name );
		}
	}
	return array(
		'mime'  => $mime,
		'w'     => $w,
		'h'     => $h,
		'bytes' => $size,
	);
}

/**
 * Remove metadata from a file saved by the Imagick editor, keeping the color profile.
 * The GD editor never writes metadata, and Imagick only strips it when it resizes.
 *
 * @param string $path File.
 * @return bool
 */
function finishcoat_strip_with_imagick( $path ) {
	if ( ! class_exists( 'Imagick' ) ) {
		return false;
	}
	try {
		$im  = new Imagick( $path );
		$icc = '';
		try {
			$profiles = $im->getImageProfiles( 'icc', true );
			$icc      = isset( $profiles['icc'] ) ? $profiles['icc'] : '';
		} catch ( Exception $e ) {
			$icc = '';
		}
		$im->stripImage();
		if ( '' !== $icc ) {
			$im->profileImage( 'icc', $icc );
		}
		$im->writeImage( $path );
		$im->clear();
		return true;
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Whether a saved JPEG still has EXIF data. Other types: false (GD and Imagick re-saves do not add it).
 *
 * @param string $path File.
 * @param string $mime Mime type.
 * @return bool
 */
function finishcoat_has_exif( $path, $mime ) {
	if ( 'image/jpeg' !== $mime || ! function_exists( 'exif_read_data' ) ) {
		return false;
	}
	$d = @exif_read_data( $path, null, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file with no EXIF data is the expected case and makes PHP warn.
	if ( ! is_array( $d ) ) {
		return false;
	}
	return isset( $d['GPS'] ) || isset( $d['EXIF'] ) || isset( $d['IFD0'] );
}

/**
 * Move an uploaded file into the originals folder through wp_handle_upload(), under a random name.
 *
 * @param array  $f    File entry.
 * @param string $mime Its real type.
 * @param array  $dirs Result of finishcoat_photo_dirs().
 * @return string|WP_Error Absolute path of the stored original.
 */
function finishcoat_store_original( $f, $mime, $dirs ) {
	if ( ! function_exists( 'wp_handle_upload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$exts     = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/heic' => 'heic',
		'image/heif' => 'heif',
	);
	$ext      = isset( $exts[ $mime ] ) ? $exts[ $mime ] : 'jpg';
	$rand     = finishcoat_random_hex( 24 );
	$base_url = finishcoat_upload_base();
	$base_url = $base_url['url'];
	$to_dir   = function ( $u ) use ( $dirs, $base_url ) {
		$u['path']   = $dirs['originals'];
		$u['url']    = $base_url . '/finishcoat/_originals';
		$u['subdir'] = '/finishcoat/_originals';
		$u['error']  = false;
		return $u;
	};
	$file     = array(
		'name'     => 'photo.' . $ext,
		'type'     => $mime,
		'tmp_name' => $f['tmp_name'],
		'error'    => $f['error'],
		'size'     => $f['size'],
	);
	add_filter( 'upload_dir', $to_dir, 99 );
	$res = wp_handle_upload(
		$file,
		array(
			'test_form'                => false,
			'mimes'                    => finishcoat_upload_mimes(),
			'unique_filename_callback' => function ( $dir, $name, $dot_ext ) use ( $rand ) {
				return $rand . strtolower( (string) $dot_ext );
			},
		)
	);
	remove_filter( 'upload_dir', $to_dir, 99 );
	if ( ! is_array( $res ) || ! empty( $res['error'] ) || empty( $res['file'] ) || ! is_file( $res['file'] ) ) {
		return finishcoat_photo_error( 'finishcoat_read', finishcoat_unreadable_message(), $f['name'] );
	}
	if ( '' === finishcoat_photo_rel( $res['file'] ) || 0 !== strpos( wp_normalize_path( $res['file'] ), wp_normalize_path( $dirs['originals'] ) . '/' ) ) {
		wp_delete_file( $res['file'] );
		return finishcoat_photo_error( 'finishcoat_process', finishcoat_server_message(), $f['name'], 500 );
	}
	return (string) $res['file'];
}

/**
 * Save a re-sized, metadata-free copy of a local image.
 *
 * @param string $src  Source file.
 * @param string $mime Source type.
 * @param int    $max  Longest edge.
 * @param string $dir  Folder to write to.
 * @return array{path: string, mime: string, w: int, h: int}|WP_Error
 */
function finishcoat_save_copy( $src, $mime, $max, $dir ) {
	$ed = wp_get_image_editor( $src, array( 'mime_type' => $mime ) );
	if ( is_wp_error( $ed ) ) {
		return $ed;
	}
	if ( method_exists( $ed, 'maybe_exif_rotate' ) ) {
		$ed->maybe_exif_rotate();
	}
	$size = $ed->get_size();
	$w    = isset( $size['width'] ) ? (int) $size['width'] : 0;
	$h    = isset( $size['height'] ) ? (int) $size['height'] : 0;
	if ( $w < 1 || $h < 1 || $w * $h > 60000000 ) {
		return new WP_Error( 'finishcoat_read', finishcoat_unreadable_message() );
	}
	if ( max( $w, $h ) > $max ) {
		$r = $ed->resize( $max, $max, false );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
	}
	$ed->set_quality( 82 );
	// PNG and WebP keep their type (transparency for logos); everything else becomes JPEG.
	$out  = in_array( $mime, array( 'image/png', 'image/webp' ), true ) ? $mime : 'image/jpeg';
	$exts = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);
	add_filter( 'image_strip_meta', '__return_true', 99 );
	$saved = $ed->save( $dir . '/' . finishcoat_random_hex( 20 ) . '.' . $exts[ $out ], $out );
	remove_filter( 'image_strip_meta', '__return_true', 99 );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
		return new WP_Error( 'finishcoat_process', finishcoat_server_message() );
	}
	$path     = (string) $saved['path'];
	$out_mime = isset( $saved['mime-type'] ) ? (string) $saved['mime-type'] : $out;
	if ( $ed instanceof WP_Image_Editor_Imagick ) {
		finishcoat_strip_with_imagick( $path );
	}
	if ( finishcoat_has_exif( $path, $out_mime ) && ( ! finishcoat_strip_with_imagick( $path ) || finishcoat_has_exif( $path, $out_mime ) ) ) {
		wp_delete_file( $path );
		return new WP_Error( 'finishcoat_process', finishcoat_server_message() );
	}
	return array(
		'path' => $path,
		'mime' => $out_mime,
		'w'    => isset( $saved['width'] ) ? (int) $saved['width'] : 0,
		'h'    => isset( $saved['height'] ) ? (int) $saved['height'] : 0,
	);
}

/**
 * The pipeline for one checked upload: the original through wp_handle_upload(), then a display copy
 * (at most 2,560 px, quality 82, metadata gone) and a 480 px thumbnail.
 *
 * @param array $f    File entry.
 * @param array $v    Result of finishcoat_validate_photo().
 * @param array $dirs Result of finishcoat_photo_dirs().
 * @return array|WP_Error { file, thumb, orig, w, h, bytes, name, written: string[] }
 */
function finishcoat_process_photo( $f, $v, $dirs ) {
	$orig = finishcoat_store_original( $f, $v['mime'], $dirs );
	if ( is_wp_error( $orig ) ) {
		return $orig;
	}
	$written = array( $orig );
	$fail    = function ( $e ) use ( &$written, $f, $v ) {
		finishcoat_unlink_all( $written );
		if ( 'image/heic' === $v['mime'] || 'image/heif' === $v['mime'] ) {
			return finishcoat_photo_error( 'finishcoat_heic', __( 'This phone photo format can\'t be read here. Send it as a JPEG: on iPhone, Settings > Camera > Formats > Most Compatible.', 'finishcoat' ), $f['name'] );
		}
		$code = is_wp_error( $e ) ? $e->get_error_code() : 'finishcoat_process';
		return 'finishcoat_read' === $code
			? finishcoat_photo_error( 'finishcoat_read', finishcoat_unreadable_message(), $f['name'] )
			: finishcoat_photo_error( 'finishcoat_process', finishcoat_server_message(), $f['name'], 500 );
	};
	$display = finishcoat_save_copy( $orig, $v['mime'], 2560, $dirs['project'] );
	if ( is_wp_error( $display ) ) {
		return $fail( $display );
	}
	$written[] = $display['path'];
	$thumb     = finishcoat_save_copy( $display['path'], $display['mime'], 480, $dirs['project'] );
	if ( is_wp_error( $thumb ) ) {
		return $fail( $thumb );
	}
	$written[] = $thumb['path'];
	clearstatcache();
	$base = sanitize_file_name( (string) pathinfo( $f['name'], PATHINFO_FILENAME ) );
	$base = '' !== $base ? mb_substr( $base, 0, 100 ) : 'photo';
	return array(
		'file'    => finishcoat_photo_rel( $display['path'] ),
		'thumb'   => finishcoat_photo_rel( $thumb['path'] ),
		'orig'    => finishcoat_photo_rel( $orig ),
		'mime'    => $v['mime'],
		'w'       => $display['w'],
		'h'       => $display['h'],
		'bytes'   => (int) filesize( $orig ) + (int) filesize( $display['path'] ) + (int) filesize( $thumb['path'] ),
		'name'    => $base . '.' . strtolower( (string) pathinfo( $orig, PATHINFO_EXTENSION ) ),
		'written' => $written,
	);
}

/**
 * Bytes used by a project's photos (display copies, thumbnails, before pictures and originals).
 *
 * @param string $slug Project slug.
 * @return int
 */
function finishcoat_photo_bytes_used( $slug ) {
	$ids = finishcoat_get_all(
		array(
			'post_type'   => 'finishcoat_photo',
			'post_status' => 'any',
			'fields'      => 'ids',
		),
		20000
	);
	if ( ! $ids ) {
		return 0;
	}
	update_meta_cache( 'post', $ids );
	$n = 0;
	foreach ( $ids as $id ) {
		$p = get_post_meta( $id, '_finishcoat_project', true );
		if ( ( '' !== $p ? $p : 'site' ) === $slug ) {
			$n += (int) get_post_meta( $id, '_finishcoat_bytes', true );
		}
	}
	return $n;
}

/**
 * Check every file and the project's space, then write them. All or nothing: if one fails, the files already
 * written in this request are removed.
 *
 * @param array  $files Files from finishcoat_photo_files_from_request().
 * @param string $slug  Project slug.
 * @return array[]|WP_Error Processed photos (see finishcoat_process_photo()).
 */
function finishcoat_handle_upload( $files, $slug ) {
	$max_files = (int) finishcoat_setting( 'photo_max_files' );
	$max_bytes = finishcoat_photo_max_bytes();
	if ( count( $files ) > $max_files ) {
		/* translators: %d: most photos on one note. */
		return finishcoat_photo_error( 'finishcoat_count', sprintf( _n( 'You can send up to %d photo with a note.', 'You can send up to %d photos with a note.', $max_files, 'finishcoat' ), $max_files ) );
	}
	$checked  = array();
	$incoming = 0;
	foreach ( $files as $f ) {
		$v = finishcoat_validate_photo( $f, $max_bytes );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		$checked[] = array( $f, $v );
		$incoming += $v['bytes'];
	}
	$cap  = (int) finishcoat_setting( 'photo_cap_mb' ) * MB_IN_BYTES;
	$used = finishcoat_photo_bytes_used( $slug );
	$full = __( 'There is no room for more photos right now. The site owner has been told.', 'finishcoat' );
	if ( $used + $incoming > $cap ) {
		return finishcoat_photo_error( 'finishcoat_cap', $full );
	}
	$dirs = finishcoat_photo_dirs( $slug );
	if ( is_wp_error( $dirs ) ) {
		return finishcoat_photo_error( 'finishcoat_process', finishcoat_server_message(), '', 500 );
	}
	$done    = array();
	$written = array();
	$total   = 0;
	foreach ( $checked as $pair ) {
		$r = finishcoat_process_photo( $pair[0], $pair[1], $dirs );
		if ( is_wp_error( $r ) ) {
			finishcoat_unlink_all( $written );
			return $r;
		}
		$written = array_merge( $written, $r['written'] );
		$total  += $r['bytes'];
		$done[]  = $r;
	}
	// Display copies can come out larger than the originals (a PNG screenshot), so check again.
	if ( $used + $total > $cap ) {
		finishcoat_unlink_all( $written );
		return finishcoat_photo_error( 'finishcoat_cap', $full );
	}
	return $done;
}

/**
 * The small before picture of the original photo in a spot (made in the visitor's browser): checked like a photo,
 * then saved as a 480 px copy. Returns its relative path, or null when it can't be used.
 *
 * @param array  $f    File entry.
 * @param string $slug Project slug.
 * @return string|null
 */
function finishcoat_handle_before( $f, $slug ) {
	$v = finishcoat_validate_photo( $f, 3 * MB_IN_BYTES );
	if ( is_wp_error( $v ) || ! in_array( $v['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
		return null;
	}
	$dirs = finishcoat_photo_dirs( $slug );
	if ( is_wp_error( $dirs ) ) {
		return null;
	}
	$orig = finishcoat_store_original( $f, $v['mime'], $dirs );
	if ( is_wp_error( $orig ) ) {
		return null;
	}
	$copy = finishcoat_save_copy( $orig, $v['mime'], 480, $dirs['project'] );
	wp_delete_file( $orig );
	return is_wp_error( $copy ) ? null : finishcoat_photo_rel( $copy['path'] );
}

/**
 * Every file written for a list of processed photos.
 *
 * @param array[] $done Result of finishcoat_handle_upload().
 * @return string[]
 */
function finishcoat_written_files( $done ) {
	$out = array();
	foreach ( (array) $done as $d ) {
		$out = array_merge( $out, isset( $d['written'] ) ? (array) $d['written'] : array() );
	}
	return $out;
}

/**
 * Per-visitor photo limit. Checks without counting.
 *
 * @param int $n Photos in this request.
 * @return bool
 */
function finishcoat_photo_rate_ok( $n ) {
	return (int) get_transient( finishcoat_visitor_key( 'ph' ) ) + (int) $n <= (int) finishcoat_setting( 'photo_rate' );
}

/**
 * Count photos against the per-visitor limit.
 *
 * @param int $n Photos in this request.
 */
function finishcoat_photo_rate_add( $n ) {
	$key = finishcoat_visitor_key( 'ph' );
	set_transient( $key, (int) get_transient( $key ) + (int) $n, HOUR_IN_SECONDS );
}

/**
 * Store the processed photos of a note as photo posts. A suggested photo keeps its spot and before picture.
 *
 * @param int         $note_id Note ID.
 * @param string      $slug    Project slug.
 * @param string      $page    Page path.
 * @param string      $author  Who sent them.
 * @param array[]     $done    Result of finishcoat_handle_upload().
 * @param array|null  $target  Spot of a suggested photo, or null.
 * @param string|null $before  Relative path of the before picture, or null.
 * @return int[] Photo IDs.
 */
function finishcoat_save_photos( $note_id, $slug, $page, $author, $done, $target, $before ) {
	$ids = array();
	foreach ( $done as $i => $d ) {
		$pid = wp_insert_post(
			array(
				'post_type'   => 'finishcoat_photo',
				'post_status' => 'private',
				'post_parent' => (int) $note_id,
				'post_title'  => mb_substr( $author . ': ' . $d['name'], 0, 90 ),
			),
			true
		);
		if ( is_wp_error( $pid ) || ! $pid ) {
			finishcoat_unlink_all( $d['written'] );
			continue;
		}
		$suggested = $target && 0 === $i;
		$bytes     = (int) $d['bytes'];
		update_post_meta( $pid, '_finishcoat_project', $slug );
		update_post_meta( $pid, '_finishcoat_page', $page );
		update_post_meta( $pid, '_finishcoat_file', $d['file'] );
		update_post_meta( $pid, '_finishcoat_thumb', $d['thumb'] );
		update_post_meta( $pid, '_finishcoat_orig', $d['orig'] );
		update_post_meta( $pid, '_finishcoat_w', (int) $d['w'] );
		update_post_meta( $pid, '_finishcoat_h', (int) $d['h'] );
		update_post_meta( $pid, '_finishcoat_author', wp_slash( $author ) );
		update_post_meta( $pid, '_finishcoat_name', $d['name'] );
		update_post_meta( $pid, '_finishcoat_status', $suggested ? 'suggested' : 'attached' );
		if ( $suggested ) {
			update_post_meta( $pid, '_finishcoat_target', wp_slash( (string) wp_json_encode( $target ) ) );
			if ( $before ) {
				$abs = finishcoat_photo_abs( $before );
				update_post_meta( $pid, '_finishcoat_before', $before );
				$bytes += '' !== $abs && is_file( $abs ) ? (int) filesize( $abs ) : 0;
			}
		}
		update_post_meta( $pid, '_finishcoat_bytes', $bytes );
		finishcoat_code_mark( $pid );
		do_action( 'finishcoat_photo_apply', (int) $pid, $suggested ? 'suggested' : 'attached', (int) $note_id );
		$ids[] = (int) $pid;
	}
	return $ids;
}

/*
 * ------------------------------------------------------------------------
 * Shapes and queries
 * ------------------------------------------------------------------------
 */

/**
 * The spot of a suggested photo, or null.
 *
 * @param int $id Photo ID.
 * @return array|null
 */
function finishcoat_photo_target( $id ) {
	$t = (string) get_post_meta( $id, '_finishcoat_target', true );
	$a = '' !== $t ? json_decode( $t, true ) : null;
	return is_array( $a ) && isset( $a['sel'] ) ? $a : null;
}

/**
 * One photo as the page script sees it.
 *
 * @param WP_Post $p Post.
 * @return array
 */
function finishcoat_photo_shape( $p ) {
	$t      = finishcoat_photo_target( $p->ID );
	$thumb  = (string) get_post_meta( $p->ID, '_finishcoat_thumb', true );
	$before = (string) get_post_meta( $p->ID, '_finishcoat_before', true );
	return array(
		'id'     => (int) $p->ID,
		'note'   => (int) $p->post_parent,
		'url'    => finishcoat_photo_url( get_post_meta( $p->ID, '_finishcoat_file', true ) ),
		'thumb'  => finishcoat_photo_url( '' !== $thumb ? $thumb : get_post_meta( $p->ID, '_finishcoat_file', true ) ),
		'w'      => (int) get_post_meta( $p->ID, '_finishcoat_w', true ),
		'h'      => (int) get_post_meta( $p->ID, '_finishcoat_h', true ),
		'status' => (string) get_post_meta( $p->ID, '_finishcoat_status', true ),
		'author' => (string) get_post_meta( $p->ID, '_finishcoat_author', true ),
		'time'   => get_post_time( 'c', true, $p ),
		'spot'   => $t ? (string) $t['lbl'] : '',
		'before' => '' !== $before ? finishcoat_photo_url( $before ) : '',
	);
}

/**
 * Every photo of a project (newest first), leaving out photos whose note is in the trash.
 *
 * @param string $slug Project slug.
 * @return array[]
 */
function finishcoat_project_photos( $slug ) {
	$args = apply_filters(
		'finishcoat_photos_query',
		array(
			'post_type'   => 'finishcoat_photo',
			'post_status' => 'private',
			'orderby'     => 'date',
			'order'       => 'DESC',
		),
		$slug
	);
	$out  = array();
	foreach ( finishcoat_get_all( $args, 2000 ) as $p ) {
		if ( $p->post_parent && 'private' !== get_post_status( $p->post_parent ) ) {
			continue;
		}
		$out[] = finishcoat_photo_shape( $p );
	}
	return $out;
}

/**
 * Photo IDs of a note.
 *
 * @param int $note_id Note ID.
 * @return int[]
 */
function finishcoat_note_photo_ids( $note_id ) {
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => 'finishcoat_photo',
				'post_status'    => 'any',
				'post_parent'    => (int) $note_id,
				'posts_per_page' => 50,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		)
	);
}

/*
 * ------------------------------------------------------------------------
 * Delete
 * ------------------------------------------------------------------------
 */

/**
 * Delete a photo: its files and its post. Also drops it from its note's photo list.
 *
 * @param int  $id          Photo ID.
 * @param bool $update_note Whether to update the note's photo list.
 * @return bool
 */
function finishcoat_delete_photo( $id, $update_note = true ) {
	$p = get_post( $id );
	if ( ! $p || 'finishcoat_photo' !== $p->post_type ) {
		return false;
	}
	foreach ( array( '_finishcoat_file', '_finishcoat_thumb', '_finishcoat_orig', '_finishcoat_before' ) as $k ) {
		$abs = finishcoat_photo_abs( get_post_meta( $id, $k, true ) );
		if ( '' !== $abs && is_file( $abs ) ) {
			wp_delete_file( $abs );
		}
	}
	if ( $update_note && $p->post_parent ) {
		$list = get_post_meta( $p->post_parent, '_finishcoat_photos', true );
		if ( is_array( $list ) ) {
			$list = array_values( array_diff( array_map( 'intval', $list ), array( (int) $id ) ) );
			if ( $list ) {
				update_post_meta( $p->post_parent, '_finishcoat_photos', $list );
			} else {
				delete_post_meta( $p->post_parent, '_finishcoat_photos' );
			}
		}
	}
	return (bool) wp_delete_post( $id, true );
}

/**
 * When a note is deleted for good, its photos go too.
 *
 * @param int $post_id Post ID.
 */
function finishcoat_delete_note_photos( $post_id ) {
	if ( 'finishcoat_note' !== get_post_type( $post_id ) ) {
		return;
	}
	foreach ( finishcoat_note_photo_ids( $post_id ) as $pid ) {
		finishcoat_delete_photo( $pid, false );
	}
}
add_action( 'before_delete_post', 'finishcoat_delete_note_photos' );

/*
 * ------------------------------------------------------------------------
 * Original download
 * ------------------------------------------------------------------------
 */

/**
 * Download link for an original.
 *
 * @param int $id Photo ID.
 * @return string
 */
function finishcoat_photo_original_url( $id ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'finishcoat_photo_original',
				'id'     => (int) $id,
			),
			admin_url( 'admin-post.php' )
		),
		'finishcoat_photo_original_' . (int) $id
	);
}

/**
 * Send an original to an editor.
 */
function finishcoat_handle_photo_original() {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce for this ID is checked next.
	check_admin_referer( 'finishcoat_photo_original_' . $id );
	if ( ! finishcoat_is_studio() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'finishcoat' ), '', array( 'response' => 403 ) );
	}
	$p   = $id ? get_post( $id ) : null;
	$abs = $p && 'finishcoat_photo' === $p->post_type ? finishcoat_photo_abs( get_post_meta( $id, '_finishcoat_orig', true ) ) : '';
	if ( '' === $abs || ! is_file( $abs ) ) {
		wp_die( esc_html__( 'That photo is gone.', 'finishcoat' ), '', array( 'response' => 404 ) );
	}
	$ext  = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
	$name = (string) preg_replace( '/[^A-Za-z0-9._\-]/', '', sanitize_file_name( (string) get_post_meta( $id, '_finishcoat_name', true ) ) );
	$name = '' !== $name ? $name : 'photo-' . $id . '.' . $ext;
	$type = wp_check_filetype( $abs, finishcoat_upload_mimes() );
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex' );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
	header( 'Content-Disposition: attachment; filename="' . $name . '"' );
	header( 'Content-Length: ' . (string) filesize( $abs ) );
	readfile( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streams a private file to an editor; WP_Filesystem has no streaming read.
	exit;
}
add_action( 'admin_post_finishcoat_photo_original', 'finishcoat_handle_photo_original' );

/*
 * ------------------------------------------------------------------------
 * Email parts
 * ------------------------------------------------------------------------
 */

/**
 * One photo in a note email: a thumbnail linked to the full photo (and, for a suggested photo, the before picture).
 *
 * @param int $pid Photo ID.
 * @return string HTML, escaped.
 */
function finishcoat_photo_mail_html( $pid ) {
	$p = get_post( $pid );
	if ( ! $p ) {
		return '';
	}
	$sh   = finishcoat_photo_shape( $p );
	$img  = function ( $src, $alt ) {
		return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" width="160" style="display:inline-block;width:160px;max-width:45%;height:auto;border-radius:4px;border:1px solid #E6E1D6;vertical-align:middle">';
	};
	$html = '<div style="margin:0 0 14px">';
	if ( 'suggested' === $sh['status'] ) {
		/* translators: %s: name of the spot on the page. */
		$html .= '<p style="margin:0 0 6px;font-weight:600">' . esc_html( sprintf( __( 'Suggested for "%s"', 'finishcoat' ), $sh['spot'] ) ) . '</p>';
		if ( '' !== $sh['before'] ) {
			$html .= $img( $sh['before'], __( 'Before', 'finishcoat' ) ) . ' <span style="color:#5B5476">&rarr;</span> ';
		}
	}
	$html .= '<a href="' . esc_url( $sh['url'] ) . '">' . $img( $sh['thumb'], __( 'Photo from the client', 'finishcoat' ) ) . '</a>';
	$html .= '</div>';
	return $html;
}

/**
 * Tell the recipients the photo storage is full (at most once an hour).
 *
 * @param string $author Who tried to send.
 */
function finishcoat_mail_out_of_space( $author ) {
	if ( get_transient( 'finishcoat_cap_mail' ) ) {
		return;
	}
	set_transient( 'finishcoat_cap_mail', 1, HOUR_IN_SECONDS );
	$site = finishcoat_site_name();
	/* translators: %s: name of the person. */
	$html  = '<p>' . esc_html( sprintf( __( '%s tried to send photos, but the photo storage is full.', 'finishcoat' ), '' !== $author ? $author : __( 'A client', 'finishcoat' ) ) ) . '</p>';
	$html .= '<p>' . esc_html__( 'Delete photos you no longer need, or raise the limit in Settings.', 'finishcoat' ) . '</p>';
	$html .= '<p><a href="' . esc_url( finishcoat_photos_url() ) . '">' . esc_html__( 'Photos', 'finishcoat' ) . '</a> &middot; <a href="' . esc_url( finishcoat_settings_url() ) . '">' . esc_html__( 'Settings', 'finishcoat' ) . '</a></p>';
	/* translators: %s: site name. */
	finishcoat_send_mail( finishcoat_recipients(), sprintf( __( '[%s] Photo storage is full', 'finishcoat' ), $site ), finishcoat_mail_layout( $html ) );
}

/*
 * ------------------------------------------------------------------------
 * Admin: the Photos column on the notes list, and the Photos screen
 * ------------------------------------------------------------------------
 */

/**
 * A suggested photo as before and after, with its spot, Download original and the one Pro line.
 *
 * @param array $sh Photo shape.
 */
function finishcoat_photo_pair_html( $sh ) {
	echo '<div class="finishcoat-pair">';
	/* translators: %s: name of the spot on the page. */
	echo '<p class="finishcoat-spot">' . esc_html( sprintf( __( 'Suggested for "%s"', 'finishcoat' ), $sh['spot'] ) ) . '</p>';
	echo '<div class="finishcoat-pair-imgs">';
	if ( '' !== $sh['before'] ) {
		echo '<figure><img src="' . esc_url( $sh['before'] ) . '" alt="' . esc_attr__( 'The photo on the page now', 'finishcoat' ) . '" loading="lazy"><figcaption>' . esc_html__( 'Before', 'finishcoat' ) . '</figcaption></figure>';
	}
	echo '<figure><a href="' . esc_url( $sh['url'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $sh['thumb'] ) . '" alt="' . esc_attr__( 'The suggested photo', 'finishcoat' ) . '" loading="lazy"></a><figcaption>' . esc_html__( 'After', 'finishcoat' ) . '</figcaption></figure>';
	echo '</div>';
	echo '<a class="button button-small" href="' . esc_url( finishcoat_photo_original_url( $sh['id'] ) ) . '">' . esc_html__( 'Download original', 'finishcoat' ) . '</a>';
	finishcoat_pro_photo_line();
	echo '</div>';
}

/**
 * The Photos column of one note.
 *
 * @param int $note_id Note ID.
 */
function finishcoat_photo_admin_cell( $note_id ) {
	$ids = finishcoat_note_photo_ids( $note_id );
	if ( ! $ids ) {
		return;
	}
	$plain = array();
	foreach ( $ids as $pid ) {
		$sh = finishcoat_photo_shape( get_post( $pid ) );
		if ( 'suggested' === $sh['status'] ) {
			finishcoat_photo_pair_html( $sh );
		} else {
			$plain[] = $sh;
		}
	}
	if ( $plain ) {
		echo '<div class="finishcoat-thumbs">';
		foreach ( $plain as $sh ) {
			echo '<a href="' . esc_url( $sh['url'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $sh['thumb'] ) . '" alt="' . esc_attr__( 'Attached photo', 'finishcoat' ) . '" loading="lazy"></a>';
		}
		echo '</div><a href="' . esc_url( finishcoat_photos_url() ) . '">' . esc_html__( 'Photos screen', 'finishcoat' ) . '</a>';
	}
}

/**
 * Delete a photo from the Photos screen (admin-post action finishcoat_photo_delete).
 */
function finishcoat_handle_photo_delete() {
	$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this ID is checked next.
	check_admin_referer( 'finishcoat_photo_' . $id );
	if ( ! finishcoat_is_studio() ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'finishcoat' ), '', array( 'response' => 403 ) );
	}
	$p = $id ? get_post( $id ) : null;
	if ( ! $p || 'finishcoat_photo' !== $p->post_type ) {
		finishcoat_add_notice( 'error', __( 'That photo is gone.', 'finishcoat' ) );
	} elseif ( empty( $_POST['confirm'] ) ) {
		finishcoat_add_notice( 'warning', __( 'Nothing was deleted.', 'finishcoat' ) );
	} else {
		finishcoat_delete_photo( $id );
		finishcoat_add_notice( 'success', __( 'Photo deleted, with its original.', 'finishcoat' ) );
	}
	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : finishcoat_photos_url() );
	exit;
}
add_action( 'admin_post_finishcoat_photo_delete', 'finishcoat_handle_photo_delete' );

/**
 * The Photos screen: storage used and a grid of photo cards.
 */
function finishcoat_photos_page() {
	if ( ! finishcoat_is_studio() ) {
		return;
	}
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page number of a list.
	$used  = finishcoat_photo_bytes_used( 'site' );
	$cap   = (int) finishcoat_setting( 'photo_cap_mb' ) * MB_IN_BYTES;
	echo '<div class="wrap finishcoat-wrap"><h1>' . esc_html__( 'Client photos', 'finishcoat' ) . '</h1>';
	finishcoat_print_notices();
	echo '<p>' . esc_html__( 'Photos clients attached to their notes, and photos they suggested for a spot on a page. They never show on the page itself. Each one keeps its original, which you can download.', 'finishcoat' ) . '</p>';
	/* translators: 1: space used, 2: space allowed. */
	echo '<p class="description">' . esc_html( sprintf( __( 'Photo storage: %1$s of %2$s used.', 'finishcoat' ), $used > 0 ? size_format( $used, 1 ) : '0 B', size_format( $cap ) ) ) . '</p>';
	$q = new WP_Query(
		array(
			'post_type'      => 'finishcoat_photo',
			'post_status'    => 'private',
			'posts_per_page' => 48,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	if ( ! $q->posts ) {
		echo '<p>' . esc_html__( 'No photos yet.', 'finishcoat' ) . '</p></div>';
		return;
	}
	$statuses = finishcoat_photo_statuses();
	echo '<div class="finishcoat-grid">';
	foreach ( $q->posts as $p ) {
		$sh   = finishcoat_photo_shape( $p );
		$note = $p->post_parent ? (int) $p->post_parent : 0;
		$cl   = finishcoat_code_label_of( $p->ID );
		echo '<div class="finishcoat-card">';
		if ( 'suggested' === $sh['status'] ) {
			finishcoat_photo_pair_html( $sh );
		} else {
			echo '<a class="finishcoat-card-img" href="' . esc_url( $sh['url'] ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $sh['thumb'] ) . '" alt="' . esc_attr__( 'Attached photo', 'finishcoat' ) . '" loading="lazy"></a>';
		}
		echo '<div class="finishcoat-card-in"><span class="finishcoat-chip">' . esc_html( isset( $statuses[ $sh['status'] ] ) ? $statuses[ $sh['status'] ] : $sh['status'] ) . '</span><br>';
		if ( $note ) {
			$title = (string) get_post_meta( wp_get_post_parent_id( $note ) ? wp_get_post_parent_id( $note ) : $note, '_finishcoat_page_title', true );
			echo '<a href="' . esc_url( finishcoat_note_link( $note ) ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $title ? $title : (string) get_post_meta( $p->ID, '_finishcoat_page', true ) ) . '</a><br>';
		}
		$meta = array_filter( array( $sh['author'], $cl, get_the_date( '', $p ), $sh['w'] . ' x ' . $sh['h'] ) );
		echo '<span class="description">' . esc_html( implode( ' / ', $meta ) ) . '</span></div>';
		echo '<div class="finishcoat-card-acts">';
		if ( 'suggested' !== $sh['status'] ) {
			echo '<a class="button button-small" href="' . esc_url( finishcoat_photo_original_url( $p->ID ) ) . '">' . esc_html__( 'Download original', 'finishcoat' ) . '</a>';
		}
		// Two steps: Delete opens the question, then Yes, delete or Cancel.
		echo '<details class="finishcoat-del"><summary class="button-link button-link-delete">' . esc_html__( 'Delete', 'finishcoat' ) . '</summary>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>' . esc_html__( 'Delete this photo and its original for good?', 'finishcoat' ) . '</p>';
		echo '<input type="hidden" name="action" value="finishcoat_photo_delete"><input type="hidden" name="id" value="' . esc_attr( (string) $p->ID ) . '"><input type="hidden" name="confirm" value="1">';
		wp_nonce_field( 'finishcoat_photo_' . $p->ID );
		echo '<button type="submit" class="button button-small finishcoat-yes">' . esc_html__( 'Yes, delete', 'finishcoat' ) . '</button> <button type="button" class="button button-small finishcoat-cancel">' . esc_html__( 'Cancel', 'finishcoat' ) . '</button></form></details>';
		echo '</div></div>';
	}
	echo '</div>';
	$pages = (int) $q->max_num_pages;
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			(string) paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', finishcoat_photos_url() ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}
	echo '</div>';
}
