<?php
/**
 * Settings: defaults, sanitizing, the Settings screen, the test email and admin notices.
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ------------------------------------------------------------------------
 * Values
 * ------------------------------------------------------------------------
 */

/**
 * Default settings.
 *
 * @return array
 */
function finishcoat_default_settings() {
	return array(
		'show'            => 'link',
		'skip'            => '',
		'label'           => '',
		'paper'           => '#F5F2EA',
		'panel'           => '#FFFFFF',
		'ink'             => '#120A2E',
		'accent'          => '#5B5476',
		'btn'             => '#120A2E',
		'btn_ink'         => '#F5F2EA',
		'radius'          => 4,
		'recipients'      => (string) get_option( 'admin_email' ),
		'photo_max_mb'    => 15,
		'photo_max_files' => 3,
		'photo_cap_mb'    => 500,
		'photo_rate'      => 40,
		'min_fill_ms'     => 1500,
		'paused'          => false,
		'credit'          => false,
		'delete_data'     => false,
	);
}

/**
 * Who sees the Suggestions tab.
 *
 * @return array<string, string>
 */
function finishcoat_show_choices() {
	return array(
		'everyone'  => __( 'Everyone', 'finishcoat' ),
		'logged_in' => __( 'Logged-in users only', 'finishcoat' ),
		'link'      => __( 'Only people who open the review link', 'finishcoat' ),
	);
}

/**
 * Current settings, filled out with defaults and the right types.
 *
 * @return array
 */
function finishcoat_settings() {
	$s = get_option( 'finishcoat_settings', array() );
	$s = array_merge( finishcoat_default_settings(), is_array( $s ) ? $s : array() );

	$s['show']            = array_key_exists( (string) $s['show'], finishcoat_show_choices() ) ? (string) $s['show'] : 'link';
	$s['skip']            = (string) $s['skip'];
	$s['label']           = (string) $s['label'];
	$s['radius']          = max( 0, min( 24, (int) $s['radius'] ) );
	$s['recipients']      = (string) $s['recipients'];
	$s['photo_max_mb']    = max( 1, (int) $s['photo_max_mb'] );
	$s['photo_max_files'] = max( 1, min( 3, (int) $s['photo_max_files'] ) );
	$s['photo_cap_mb']    = max( 10, (int) $s['photo_cap_mb'] );
	$s['photo_rate']      = max( 1, (int) $s['photo_rate'] );
	$s['min_fill_ms']     = max( 0, (int) $s['min_fill_ms'] );
	foreach ( array( 'paused', 'credit', 'delete_data' ) as $k ) {
		$s[ $k ] = (bool) $s[ $k ];
	}
	$def = finishcoat_default_settings();
	foreach ( array_keys( finishcoat_color_fields() ) as $k ) {
		$c       = finishcoat_clean_color( $s[ $k ] );
		$s[ $k ] = '' !== $c ? $c : $def[ $k ];
	}
	return $s;
}

/**
 * One setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function finishcoat_setting( $key ) {
	$s = finishcoat_settings();
	return isset( $s[ $key ] ) ? $s[ $key ] : null;
}

/**
 * The tab label: the setting, or "Suggestions".
 *
 * @return string
 */
function finishcoat_tab_label() {
	$s     = get_option( 'finishcoat_settings', array() );
	$label = is_array( $s ) && isset( $s['label'] ) ? trim( (string) $s['label'] ) : '';
	return '' !== $label ? $label : __( 'Suggestions', 'finishcoat' );
}

/**
 * A color as #RRGGBB in capitals, or '' when it is not a hex color. #ABC becomes #AABBCC.
 *
 * @param mixed $raw Color.
 * @return string
 */
function finishcoat_clean_color( $raw ) {
	$c = sanitize_hex_color( trim( is_scalar( $raw ) ? (string) $raw : '' ) );
	if ( ! $c ) {
		return '';
	}
	if ( 4 === strlen( $c ) ) {
		$c = '#' . $c[1] . $c[1] . $c[2] . $c[2] . $c[3] . $c[3];
	}
	return strtoupper( $c );
}

/**
 * Color settings with their labels.
 *
 * @return array<string, string>
 */
function finishcoat_color_fields() {
	return array(
		'paper'   => __( 'Panel background', 'finishcoat' ),
		'panel'   => __( 'Card background', 'finishcoat' ),
		'ink'     => __( 'Text', 'finishcoat' ),
		'accent'  => __( 'Accent', 'finishcoat' ),
		'btn'     => __( 'Button', 'finishcoat' ),
		'btn_ink' => __( 'Button text', 'finishcoat' ),
	);
}

/**
 * Mix two #RRGGBB colors. $t = 0 gives $a, 1 gives $b.
 *
 * @param string $a Color.
 * @param string $b Color.
 * @param float  $t Amount of $b.
 * @return string
 */
function finishcoat_mix( $a, $b, $t ) {
	$out = '#';
	for ( $i = 1; $i < 7; $i += 2 ) {
		$x    = hexdec( substr( $a, $i, 2 ) );
		$y    = hexdec( substr( $b, $i, 2 ) );
		$out .= str_pad( dechex( (int) round( $x + ( $y - $x ) * $t ) ), 2, '0', STR_PAD_LEFT );
	}
	return strtoupper( $out );
}

/**
 * The panel theme the page script uses. Muted text, lines and tints are mixed from the chosen colors.
 * Fonts are left to the site's theme.
 *
 * @return array<string, string>
 */
function finishcoat_theme() {
	$s = finishcoat_settings();
	return array(
		'paper'     => $s['paper'],
		'panel'     => $s['panel'],
		'ink'       => $s['ink'],
		'soft'      => finishcoat_mix( $s['ink'], $s['paper'], 0.35 ),
		'line'      => finishcoat_mix( $s['ink'], $s['paper'], 0.82 ),
		'wash'      => finishcoat_mix( $s['ink'], $s['paper'], 0.9 ),
		'accent'    => $s['accent'],
		'accentInk' => finishcoat_mix( $s['accent'], $s['ink'], 0.35 ),
		'btn'       => $s['btn'],
		'btnInk'    => $s['btn_ink'],
		'radius'    => $s['radius'] . 'px',
	);
}

/**
 * Clean a list of addresses into "a@x.com, b@y.com". Invalid ones are dropped.
 *
 * @param mixed $raw Comma, semicolon or space separated addresses.
 * @return string
 */
function finishcoat_sanitize_recipients( $raw ) {
	$ok = array();
	foreach ( preg_split( '/[\s,;]+/', is_scalar( $raw ) ? (string) $raw : '' ) as $e ) {
		$e = sanitize_email( $e );
		if ( '' !== $e && is_email( $e ) ) {
			$ok[] = $e;
		}
	}
	return implode( ', ', array_values( array_unique( $ok ) ) );
}

/**
 * Addresses that get note emails.
 *
 * @return string[]
 */
function finishcoat_recipients() {
	$list = finishcoat_sanitize_recipients( finishcoat_setting( 'recipients' ) );
	return '' === $list ? array() : explode( ', ', $list );
}

/**
 * Clean "12, 34 x 56" into "12, 34, 56".
 *
 * @param mixed $raw Page IDs.
 * @return string
 */
function finishcoat_sanitize_ids( $raw ) {
	$ids = array();
	foreach ( preg_split( '/[^0-9]+/', is_scalar( $raw ) ? (string) $raw : '' ) as $n ) {
		$n = absint( $n );
		if ( $n && ! in_array( $n, $ids, true ) ) {
			$ids[] = $n;
		}
		if ( count( $ids ) >= 200 ) {
			break;
		}
	}
	return implode( ', ', $ids );
}

/**
 * Page IDs where the tab never loads.
 *
 * @return int[]
 */
function finishcoat_skip_ids() {
	$v = finishcoat_sanitize_ids( finishcoat_setting( 'skip' ) );
	return '' === $v ? array() : array_map( 'intval', explode( ', ', $v ) );
}

/**
 * Clamp a number into a range.
 *
 * @param mixed $v   Value.
 * @param int   $min Minimum.
 * @param int   $max Maximum.
 * @param int   $def Used when the value is not a number.
 * @return int
 */
function finishcoat_clamp_int( $v, $min, $max, $def ) {
	if ( ! is_numeric( $v ) ) {
		return (int) $def;
	}
	return max( $min, min( $max, (int) $v ) );
}

/**
 * Number fields on the Settings screen: key => [ label, min, max, help ].
 *
 * @return array<string, array>
 */
function finishcoat_number_fields() {
	$server = max( 1, (int) floor( wp_max_upload_size() / MB_IN_BYTES ) );
	return array(
		'photo_max_mb'    => array(
			__( 'Largest photo (MB)', 'finishcoat' ),
			1,
			100,
			/* translators: %d: upload limit of this server in MB. */
			sprintf( __( 'This server accepts up to %d MB per upload. The lower of the two applies.', 'finishcoat' ), $server ),
		),
		'photo_max_files' => array( __( 'Photos per note', 'finishcoat' ), 1, 3, __( 'Up to 3 photos on a note or a reply.', 'finishcoat' ) ),
		'photo_cap_mb'    => array( __( 'Photo storage (MB)', 'finishcoat' ), 10, 100000, __( 'Counts the display copies and the originals. When it is full, uploads stop and you get an email.', 'finishcoat' ) ),
		'photo_rate'      => array( __( 'Photos per hour per visitor', 'finishcoat' ), 1, 1000, __( 'Counted by a hashed IP address. The address itself is never stored.', 'finishcoat' ) ),
		'min_fill_ms'     => array( __( 'Minimum time to write a note (ms)', 'finishcoat' ), 0, 60000, __( 'Notes sent faster than this after the panel opens are dropped as spam. Logged-in editors skip this.', 'finishcoat' ) ),
	);
}

/**
 * Sanitize callback for the settings option.
 *
 * @param mixed $in Raw input.
 * @return array
 */
function finishcoat_sanitize_settings( $in ) {
	$def = finishcoat_default_settings();
	$in  = is_array( $in ) ? $in : array();
	$out = array();

	$show        = isset( $in['show'] ) ? sanitize_key( (string) $in['show'] ) : $def['show'];
	$out['show'] = array_key_exists( $show, finishcoat_show_choices() ) ? $show : $def['show'];
	$out['skip'] = finishcoat_sanitize_ids( isset( $in['skip'] ) ? $in['skip'] : '' );

	$label        = isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : '';
	$out['label'] = mb_substr( trim( $label ), 0, 40 );
	foreach ( array_keys( finishcoat_color_fields() ) as $k ) {
		$c         = finishcoat_clean_color( isset( $in[ $k ] ) ? $in[ $k ] : '' );
		$out[ $k ] = '' !== $c ? $c : $def[ $k ];
	}
	$out['radius'] = finishcoat_clamp_int( isset( $in['radius'] ) ? $in['radius'] : null, 0, 24, $def['radius'] );

	$out['recipients'] = finishcoat_sanitize_recipients( isset( $in['recipients'] ) ? $in['recipients'] : '' );
	if ( '' === $out['recipients'] ) {
		$prev              = get_option( 'finishcoat_settings', array() );
		$prev              = is_array( $prev ) && isset( $prev['recipients'] ) ? finishcoat_sanitize_recipients( $prev['recipients'] ) : '';
		$out['recipients'] = '' !== $prev ? $prev : finishcoat_sanitize_recipients( $def['recipients'] );
		if ( isset( $in['recipients'] ) && function_exists( 'add_settings_error' ) ) {
			add_settings_error( 'finishcoat_settings', 'finishcoat_recipients', __( 'No valid email address was entered, so the old list was kept.', 'finishcoat' ) );
		}
	}

	foreach ( finishcoat_number_fields() as $k => $f ) {
		$out[ $k ] = finishcoat_clamp_int( isset( $in[ $k ] ) ? $in[ $k ] : null, $f[1], $f[2], $def[ $k ] );
	}
	foreach ( array( 'paused', 'credit', 'delete_data' ) as $k ) {
		$out[ $k ] = ! empty( $in[ $k ] );
	}
	return $out;
}

/**
 * Register the option with the Settings API.
 */
function finishcoat_settings_init() {
	register_setting(
		'finishcoat_settings_group',
		'finishcoat_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'finishcoat_sanitize_settings',
			'show_in_rest'      => false,
		)
	);
}
add_action( 'admin_init', 'finishcoat_settings_init' );

/*
 * ------------------------------------------------------------------------
 * Mail
 * ------------------------------------------------------------------------
 */

/**
 * Send one message per address, so a refused address cannot block the others.
 *
 * @param string[] $to      Addresses.
 * @param string   $subject Subject.
 * @param string   $html    HTML body (already escaped by the caller).
 * @return array{mailed: bool, results: array<string, array{ok: bool, error: string}>}
 */
function finishcoat_send_mail( $to, $subject, $html ) {
	$err   = '';
	$catch = function ( $e ) use ( &$err ) {
		$err = mb_substr( wp_strip_all_tags( is_wp_error( $e ) ? $e->get_error_message() : '' ), 0, 200 );
	};
	add_action( 'wp_mail_failed', $catch );
	$out = array(
		'mailed'  => false,
		'results' => array(),
	);
	foreach ( (array) $to as $addr ) {
		$err                     = '';
		$ok                      = (bool) wp_mail( $addr, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		$out['results'][ $addr ] = array(
			'ok'    => $ok,
			'error' => $err,
		);
		$out['mailed']           = $out['mailed'] || $ok;
	}
	remove_action( 'wp_mail_failed', $catch );
	return $out;
}

/**
 * Wrap an email body in a plain, readable layout.
 *
 * @param string $inner HTML, already escaped.
 * @return string
 */
function finishcoat_mail_layout( $inner ) {
	return '<!doctype html><html><body style="margin:0;padding:24px;background:#F5F2EA;color:#120A2E;font:15px/1.5 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">'
		. '<div style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E6E1D6;border-radius:6px;padding:20px 22px">'
		. $inner
		. '</div></body></html>';
}

/*
 * ------------------------------------------------------------------------
 * Admin notices queued for the next screen
 * ------------------------------------------------------------------------
 */

/**
 * Queue a notice for the current user, shown on the next screen load.
 *
 * @param string $type    success|error|warning|info.
 * @param string $message Plain text.
 */
function finishcoat_add_notice( $type, $message ) {
	$key    = 'finishcoat_notice_' . get_current_user_id();
	$list   = get_transient( $key );
	$list   = is_array( $list ) ? $list : array();
	$list[] = array(
		'type' => $type,
		'msg'  => (string) $message,
	);
	set_transient( $key, $list, 5 * MINUTE_IN_SECONDS );
}

/**
 * Print and clear queued notices.
 */
function finishcoat_print_notices() {
	$key  = 'finishcoat_notice_' . get_current_user_id();
	$list = get_transient( $key );
	if ( ! is_array( $list ) ) {
		return;
	}
	delete_transient( $key );
	foreach ( $list as $n ) {
		$type = isset( $n['type'] ) && in_array( $n['type'], array( 'success', 'error', 'warning', 'info' ), true ) ? $n['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . nl2br( esc_html( isset( $n['msg'] ) ? (string) $n['msg'] : '' ) ) . '</p></div>';
	}
}

/*
 * ------------------------------------------------------------------------
 * Menu and screens
 * ------------------------------------------------------------------------
 */

/**
 * The admin menu parent: the notes list.
 *
 * @return string
 */
function finishcoat_menu_parent() {
	return 'edit.php?post_type=finishcoat_note';
}

/**
 * URL of the Settings screen.
 *
 * @return string
 */
function finishcoat_settings_url() {
	return add_query_arg( 'page', 'finishcoat-settings', admin_url( finishcoat_menu_parent() ) );
}

/**
 * Whether the current admin screen is one of the plugin's own.
 *
 * @return bool
 */
function finishcoat_is_own_screen() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return false;
	}
	return 'finishcoat_note' === $screen->post_type || false !== strpos( (string) $screen->id, 'finishcoat-' );
}

/**
 * Menus: Client notes (with the waiting count), Notes, Photos, Settings.
 */
function finishcoat_admin_menu() {
	$parent = finishcoat_menu_parent();
	$n      = current_user_can( 'edit_others_posts' ) ? finishcoat_waiting_count() : 0;
	$bubble = '';
	if ( $n > 0 ) {
		/* translators: %d: number of notes waiting. */
		$sr     = sprintf( _n( '%d waiting', '%d waiting', $n, 'finishcoat' ), $n );
		$bubble = ' <span class="awaiting-mod count-' . absint( $n ) . '"><span class="pending-count" aria-hidden="true">' . absint( $n ) . '</span><span class="screen-reader-text">' . esc_html( $sr ) . '</span></span>';
	}
	add_menu_page( __( 'Client notes', 'finishcoat' ), esc_html__( 'Client notes', 'finishcoat' ) . $bubble, 'edit_others_posts', $parent, '', 'dashicons-format-chat', 26 );
}
add_action( 'admin_menu', 'finishcoat_admin_menu', 9 );

/**
 * Photos and Settings, after the notes list that WordPress adds for the post type.
 */
function finishcoat_admin_submenus() {
	$parent = finishcoat_menu_parent();
	add_submenu_page( $parent, __( 'Client photos', 'finishcoat' ), __( 'Photos', 'finishcoat' ), 'edit_others_posts', 'finishcoat-photos', 'finishcoat_photos_page' );
	add_submenu_page( $parent, __( 'Finishcoat settings', 'finishcoat' ), __( 'Settings', 'finishcoat' ), 'manage_options', 'finishcoat-settings', 'finishcoat_settings_page' );
	do_action( 'finishcoat_admin_menu', $parent );
}
add_action( 'admin_menu', 'finishcoat_admin_submenus', 11 );

/**
 * Admin styles and the small copy-link script, on the plugin's own screens only.
 */
function finishcoat_admin_assets() {
	if ( ! finishcoat_is_own_screen() ) {
		return;
	}
	wp_enqueue_style( 'finishcoat-admin', FINISHCOAT_URL . 'assets/finishcoat-admin.css', array(), FINISHCOAT_VERSION );
	wp_enqueue_script( 'finishcoat-admin', FINISHCOAT_URL . 'assets/finishcoat-admin.js', array(), FINISHCOAT_VERSION, array( 'in_footer' => true ) );
	wp_localize_script(
		'finishcoat-admin',
		'finishcoatAdmin',
		array(
			'copied' => __( 'Copied', 'finishcoat' ),
			'failed' => __( 'Select the link and copy it', 'finishcoat' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'finishcoat_admin_assets' );

/**
 * One checkbox row.
 *
 * @param array  $s     Settings.
 * @param string $key   Setting key.
 * @param string $title Row title.
 * @param string $label Checkbox label.
 * @param string $help  Help text.
 */
function finishcoat_checkbox_row( $s, $key, $title, $label, $help = '' ) {
	echo '<tr><th scope="row">' . esc_html( $title ) . '</th><td><label><input type="checkbox" name="finishcoat_settings[' . esc_attr( $key ) . ']" value="1"' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	if ( '' !== $help ) {
		echo '<p class="description">' . esc_html( $help ) . '</p>';
	}
	echo '</td></tr>';
}

/**
 * The Settings screen.
 */
function finishcoat_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = finishcoat_settings();
	echo '<div class="wrap finishcoat-wrap"><h1>' . esc_html__( 'Finishcoat settings', 'finishcoat' ) . '</h1>';
	finishcoat_print_notices();
	settings_errors();

	echo '<div class="finishcoat-cols"><div class="finishcoat-main">';
	finishcoat_codes_section();
	echo '<form method="post" action="options.php">';
	settings_fields( 'finishcoat_settings_group' );

	echo '<h2>' . esc_html__( 'Suggestions tab', 'finishcoat' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row">' . esc_html__( 'Show the tab to', 'finishcoat' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Show the tab to', 'finishcoat' ) . '</legend>';
	foreach ( finishcoat_show_choices() as $k => $label ) {
		echo '<label><input type="radio" name="finishcoat_settings[show]" value="' . esc_attr( $k ) . '"' . checked( $s['show'], $k, false ) . '> ' . esc_html( $label ) . '</label><br>';
	}
	echo '<p class="description">' . esc_html__( 'Whoever sees the tab still needs a review code to read or send notes. Logged-in editors always see it and need no code.', 'finishcoat' ) . '</p></fieldset></td></tr>';

	echo '<tr><th scope="row"><label for="finishcoat_skip">' . esc_html__( 'Skip these pages', 'finishcoat' ) . '</label></th><td>';
	echo '<input type="text" class="regular-text" id="finishcoat_skip" name="finishcoat_settings[skip]" value="' . esc_attr( $s['skip'] ) . '" placeholder="12, 34">';
	echo '<p class="description">' . esc_html__( 'Page or post IDs, separated by commas. The tab does not load there.', 'finishcoat' ) . '</p>';
	echo '<p class="description">' . esc_html__( 'Shortcodes: [finishcoat] loads the tab on a page, and [finishcoat board="yes"] also shows the board of every note at that spot.', 'finishcoat' ) . '</p></td></tr>';

	echo '<tr><th scope="row"><label for="finishcoat_label">' . esc_html__( 'Tab label', 'finishcoat' ) . '</label></th><td>';
	echo '<input type="text" class="regular-text" id="finishcoat_label" name="finishcoat_settings[label]" maxlength="40" value="' . esc_attr( $s['label'] ) . '" placeholder="' . esc_attr__( 'Suggestions', 'finishcoat' ) . '"></td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Colors', 'finishcoat' ) . '</th><td class="finishcoat-colors">';
	foreach ( finishcoat_color_fields() as $k => $label ) {
		echo '<label><input type="color" name="finishcoat_settings[' . esc_attr( $k ) . ']" value="' . esc_attr( strtolower( $s[ $k ] ) ) . '"> ' . esc_html( $label ) . '</label>';
	}
	echo '<p class="description">' . esc_html__( 'The panel uses your theme\'s fonts.', 'finishcoat' ) . '</p></td></tr>';

	echo '<tr><th scope="row"><label for="finishcoat_radius">' . esc_html__( 'Corner radius (px)', 'finishcoat' ) . '</label></th><td>';
	echo '<input type="number" class="small-text" id="finishcoat_radius" name="finishcoat_settings[radius]" min="0" max="24" value="' . esc_attr( (string) $s['radius'] ) . '"></td></tr>';
	finishcoat_checkbox_row( $s, 'credit', __( 'Credit', 'finishcoat' ), __( 'Show "Made with Finishcoat" in the panel', 'finishcoat' ), __( 'Off unless you turn it on.', 'finishcoat' ) );
	echo '</table>';

	echo '<h2>' . esc_html__( 'Email', 'finishcoat' ) . '</h2><table class="form-table" role="presentation">';
	echo '<tr><th scope="row"><label for="finishcoat_recipients">' . esc_html__( 'Send new notes to', 'finishcoat' ) . '</label></th><td>';
	echo '<input type="text" class="regular-text" id="finishcoat_recipients" name="finishcoat_settings[recipients]" value="' . esc_attr( $s['recipients'] ) . '">';
	echo '<p class="description">' . esc_html__( 'Every client note and reply is emailed here through your site\'s own mail. Separate addresses with commas.', 'finishcoat' ) . '</p></td></tr></table>';

	echo '<h2>' . esc_html__( 'Limits', 'finishcoat' ) . '</h2><table class="form-table" role="presentation">';
	foreach ( finishcoat_number_fields() as $k => $f ) {
		echo '<tr><th scope="row"><label for="finishcoat_' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="finishcoat_' . esc_attr( $k ) . '" name="finishcoat_settings[' . esc_attr( $k ) . ']" min="' . esc_attr( (string) $f[1] ) . '" max="' . esc_attr( (string) $f[2] ) . '" value="' . esc_attr( (string) $s[ $k ] ) . '">';
		echo '<p class="description">' . esc_html( $f[3] ) . '</p></td></tr>';
	}
	finishcoat_checkbox_row( $s, 'paused', __( 'Pause', 'finishcoat' ), __( 'Pause new notes', 'finishcoat' ), __( 'Clients can still read the notes. Logged-in editors can still reply.', 'finishcoat' ) );
	echo '</table>';

	echo '<h2>' . esc_html__( 'When the plugin is deleted', 'finishcoat' ) . '</h2><table class="form-table" role="presentation">';
	finishcoat_checkbox_row( $s, 'delete_data', __( 'Data', 'finishcoat' ), __( 'Delete all notes and photos when the plugin is deleted', 'finishcoat' ), __( 'Off by default, so notes and photos stay if you remove the plugin and add it again.', 'finishcoat' ) );
	echo '</table>';

	do_action( 'finishcoat_settings_sections', $s );
	submit_button();
	echo '</form>';

	echo '<h2>' . esc_html__( 'Test email', 'finishcoat' ) . '</h2>';
	echo '<p>' . esc_html__( 'Sends a short test message to each address above and shows what happened.', 'finishcoat' ) . '</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="finishcoat_test_mail">';
	wp_nonce_field( 'finishcoat_test_mail' );
	submit_button( __( 'Send test email', 'finishcoat' ), 'secondary', 'submit', false );
	echo '</form>';
	echo '</div>';

	echo '<aside class="finishcoat-help" aria-labelledby="finishcoat-help-h"><h2 id="finishcoat-help-h">' . esc_html__( 'Help', 'finishcoat' ) . '</h2>';
	echo '<p>' . esc_html__( 'Questions or ideas?', 'finishcoat' ) . ' <a href="mailto:finishcoat@wilderlabs.studio">' . esc_html__( 'Email finishcoat@wilderlabs.studio', 'finishcoat' ) . '</a></p>';
	echo '<p><a href="https://wordpress.org/support/plugin/finishcoat/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'WordPress.org support forum', 'finishcoat' ) . '</a></p>';
	echo '<p class="finishcoat-pro-line"><a href="https://wilderlabs.studio/finishcoat/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Finishcoat Pro', 'finishcoat' ) . '</a> ' . esc_html__( 'puts suggested photos in place for you to approve.', 'finishcoat' ) . '</p>';
	echo '</aside></div></div>';
}

/**
 * Send a test email to each recipient and report per address.
 */
function finishcoat_handle_test_mail() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'finishcoat' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'finishcoat_test_mail' );
	$to = finishcoat_recipients();
	if ( ! $to ) {
		finishcoat_add_notice( 'error', __( 'There are no addresses to send to.', 'finishcoat' ) );
	} else {
		$site = finishcoat_site_name();
		$html = finishcoat_mail_layout(
			'<p>' . esc_html(
				/* translators: %s: site name. */
				sprintf( __( 'This is a test from the Finishcoat settings on %s.', 'finishcoat' ), $site )
			) . '</p><p>' . esc_html__( 'If you got it, client note emails will reach this address.', 'finishcoat' ) . '</p>'
		);
		$res = finishcoat_send_mail(
			$to,
			/* translators: %s: site name. */
			sprintf( __( '[%s] Finishcoat test email', 'finishcoat' ), $site ),
			$html
		);
		$lines = array();
		$all   = true;
		foreach ( $res['results'] as $addr => $r ) {
			$all = $all && $r['ok'];
			if ( $r['ok'] ) {
				/* translators: %s: email address. */
				$lines[] = sprintf( __( '%s: sent', 'finishcoat' ), $addr );
			} else {
				/* translators: 1: email address, 2: error message or empty. */
				$lines[] = sprintf( __( '%1$s: failed %2$s', 'finishcoat' ), $addr, '' !== $r['error'] ? '(' . $r['error'] . ')' : '' );
			}
		}
		$head = $all ? __( 'Test email handed to the mail server.', 'finishcoat' ) : __( 'Some test emails failed.', 'finishcoat' );
		finishcoat_add_notice( $all ? 'success' : 'error', $head . "\n" . implode( "\n", $lines ) );
	}
	wp_safe_redirect( finishcoat_settings_url() );
	exit;
}
add_action( 'admin_post_finishcoat_test_mail', 'finishcoat_handle_test_mail' );

/*
 * ------------------------------------------------------------------------
 * The one notice about Pro: after 14 days, on the plugin's own screens, dismissible for good
 * ------------------------------------------------------------------------
 */

/**
 * Show the Pro notice on the plugin's screens once the plugin has been in use for 14 days.
 */
function finishcoat_pro_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! finishcoat_is_own_screen() ) {
		return;
	}
	$since = (int) get_option( 'finishcoat_installed', 0 );
	if ( ! $since || time() - $since < 14 * DAY_IN_SECONDS || get_user_meta( get_current_user_id(), 'finishcoat_pro_notice_dismissed', true ) ) {
		return;
	}
	$dismiss = wp_nonce_url( add_query_arg( 'action', 'finishcoat_dismiss_notice', admin_url( 'admin-post.php' ) ), 'finishcoat_dismiss_notice' );
	echo '<div class="notice notice-info finishcoat-pro-notice"><p>';
	echo esc_html__( 'Thanks for using Finishcoat. Finishcoat Pro, a separate plugin, puts suggested photos in place on the page for you to approve.', 'finishcoat' ) . ' ';
	echo '<a href="https://wilderlabs.studio/finishcoat/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Learn more', 'finishcoat' ) . '</a> &middot; ';
	echo '<a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'finishcoat' ) . '</a></p></div>';
}
add_action( 'admin_notices', 'finishcoat_pro_notice' );

/**
 * Dismiss the Pro notice for the current user.
 */
function finishcoat_handle_dismiss_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'finishcoat' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'finishcoat_dismiss_notice' );
	update_user_meta( get_current_user_id(), 'finishcoat_pro_notice_dismissed', 1 );
	$back = wp_get_referer();
	wp_safe_redirect( $back ? $back : admin_url( finishcoat_menu_parent() ) );
	exit;
}
add_action( 'admin_post_finishcoat_dismiss_notice', 'finishcoat_handle_dismiss_notice' );

/**
 * Suggest a paragraph for the site's privacy policy (Settings > Privacy).
 */
function finishcoat_privacy_policy() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$text  = '<p>' . esc_html__( 'If you have a review code and leave a note on this site with Finishcoat, we store the name you type, your message, the page and the spot on the page it is about, the label of your review code, and any photos you send. Display copies of photos have location and camera data removed. The original files are kept so the site owner can download them. Your IP address is used only as a one-way hash to limit how many notes can be sent in an hour, and is not stored with your note.', 'finishcoat' ) . '</p>';
	$text .= '<p>' . esc_html__( 'Your browser keeps your name and your review code (for 90 days) so you do not have to type them again. Notes and photos stay on this site until the site owner deletes them. Nothing is sent to other services.', 'finishcoat' ) . '</p>';
	wp_add_privacy_policy_content( 'Finishcoat', wp_kses_post( $text ) );
}
add_action( 'admin_init', 'finishcoat_privacy_policy' );
