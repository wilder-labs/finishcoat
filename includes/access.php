<?php
/**
 * Review codes: who can read and write notes.
 *
 * A visitor sees the board and can send notes only with one of the site's review codes. Codes live in the
 * finishcoat_codes option, which only users with manage_options see on the Settings screen. They are never sent
 * to a page, written to a log or put in an error message. Wrong codes count against a per-visitor limit (a hashed
 * IP address, 10 an hour). Logged-in editors with the studio nonce need no code.
 *
 * @package Finishcoat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Letters and digits a code is made of: no 0, O, 1, I or L.
 *
 * @return string
 */
function finishcoat_code_alphabet() {
	return 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
}

/**
 * The most codes a site can hold, revoked ones included.
 *
 * @return int
 */
function finishcoat_code_max() {
	return 10;
}

/**
 * Wrong codes allowed per visitor per hour.
 *
 * @return int
 */
function finishcoat_code_fail_limit() {
	return 10;
}

/**
 * Random hex string.
 *
 * @param int $len Length (even).
 * @return string
 */
function finishcoat_random_hex( $len ) {
	try {
		return bin2hex( random_bytes( (int) ( $len / 2 ) ) );
	} catch ( Exception $e ) {
		return substr( md5( wp_generate_password( 32, true, true ) ) . md5( wp_generate_password( 32, true, true ) ), 0, $len );
	}
}

/**
 * A new random code, 8 characters, not already on the list.
 *
 * @param array $codes Existing codes.
 * @return string
 */
function finishcoat_code_generate( $codes = array() ) {
	$abc  = finishcoat_code_alphabet();
	$max  = strlen( $abc ) - 1;
	$have = array();
	foreach ( (array) $codes as $c ) {
		if ( is_array( $c ) && isset( $c['code'] ) ) {
			$have[] = (string) $c['code'];
		}
	}
	do {
		$out = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$out .= $abc[ random_int( 0, $max ) ];
		}
	} while ( in_array( $out, $have, true ) );
	return $out;
}

/**
 * A code as typed or pasted: uppercase, dashes and spaces dropped.
 *
 * @param mixed $raw Input.
 * @return string Up to 64 characters. It may still be a wrong code.
 */
function finishcoat_code_normalize( $raw ) {
	if ( ! is_scalar( $raw ) ) {
		return '';
	}
	return substr( strtoupper( (string) preg_replace( '/[\s\-]+/', '', (string) $raw ) ), 0, 64 );
}

/**
 * Whether a normalized string has the shape of a code.
 *
 * @param string $code Normalized code.
 * @return bool
 */
function finishcoat_code_valid( $code ) {
	return 1 === preg_match( '/^[' . finishcoat_code_alphabet() . ']{8}$/', (string) $code );
}

/**
 * A code as people see it: K7QM-4XRT.
 *
 * @param string $code Stored code.
 * @return string
 */
function finishcoat_code_format( $code ) {
	$code = (string) $code;
	return 8 === strlen( $code ) ? substr( $code, 0, 4 ) . '-' . substr( $code, 4 ) : $code;
}

/**
 * Clean a code label: plain text, at most 40 characters.
 *
 * @param mixed $raw Label.
 * @return string
 */
function finishcoat_code_clean_label( $raw ) {
	return trim( mb_substr( sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' ), 0, 40 ) );
}

/**
 * Clean a stored list of codes: { id, label, code, created, revoked }, at most 10.
 *
 * @param mixed $raw Stored list.
 * @return array
 */
function finishcoat_sanitize_codes( $raw ) {
	$out = array();
	$ids = array();
	foreach ( is_array( $raw ) ? $raw : array() as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$code = isset( $c['code'] ) ? (string) $c['code'] : '';
		$id   = isset( $c['id'] ) ? strtolower( (string) $c['id'] ) : '';
		if ( ! finishcoat_code_valid( $code ) || ! preg_match( '/^[a-f0-9]{8,32}$/', $id ) || in_array( $id, $ids, true ) ) {
			continue;
		}
		$label = finishcoat_code_clean_label( isset( $c['label'] ) ? $c['label'] : '' );
		$ids[] = $id;
		$out[] = array(
			'id'      => $id,
			'label'   => '' !== $label ? $label : __( 'Client', 'finishcoat' ),
			'code'    => $code,
			'created' => isset( $c['created'] ) ? max( 0, (int) $c['created'] ) : 0,
			'revoked' => isset( $c['revoked'] ) ? max( 0, (int) $c['revoked'] ) : 0,
		);
		if ( count( $out ) >= finishcoat_code_max() ) {
			break;
		}
	}
	return $out;
}

/**
 * A new code entry.
 *
 * @param array  $codes Existing codes, for uniqueness.
 * @param string $label Label.
 * @return array
 */
function finishcoat_code_new( $codes, $label ) {
	$label = finishcoat_code_clean_label( $label );
	return array(
		'id'      => finishcoat_random_hex( 12 ),
		'label'   => '' !== $label ? $label : __( 'Client', 'finishcoat' ),
		'code'    => finishcoat_code_generate( $codes ),
		'created' => time(),
		'revoked' => 0,
	);
}

/**
 * The site's codes. The first call on a new site makes one, labeled Client.
 *
 * @return array
 */
function finishcoat_codes() {
	$raw = get_option( 'finishcoat_codes', false );
	if ( false === $raw ) {
		$first = array( finishcoat_code_new( array(), __( 'Client', 'finishcoat' ) ) );
		add_option( 'finishcoat_codes', $first, '', false );
		return $first;
	}
	return finishcoat_sanitize_codes( $raw );
}

/**
 * Save the site's codes.
 *
 * @param array $codes Codes.
 */
function finishcoat_save_codes( $codes ) {
	update_option( 'finishcoat_codes', finishcoat_sanitize_codes( $codes ), false );
}

/**
 * The codes that open a project. Lite has one project; an add-on can give each project its own codes.
 *
 * @param string $slug Project slug.
 * @return array
 */
function finishcoat_project_codes( $slug ) {
	$codes = apply_filters( 'finishcoat_access_codes', finishcoat_codes(), $slug );
	return is_array( $codes ) ? $codes : array();
}

/*
 * ------------------------------------------------------------------------
 * The check
 * ------------------------------------------------------------------------
 */

/**
 * Transient key for this visitor's wrong codes (a hashed IP address, never the address itself).
 *
 * @return string
 */
function finishcoat_code_fail_key() {
	return 'finishcoat_cf_' . substr( hash_hmac( 'sha256', 'code|' . finishcoat_client_ip(), wp_salt( 'nonce' ) ), 0, 20 );
}

/**
 * This visitor's wrong codes in the current hour: [ count, start ].
 *
 * @return array{0: int, 1: int}
 */
function finishcoat_code_fails() {
	$v = get_transient( finishcoat_code_fail_key() );
	if ( ! is_array( $v ) || ! isset( $v[0], $v[1] ) || (int) $v[1] + HOUR_IN_SECONDS <= time() ) {
		return array( 0, 0 );
	}
	return array( (int) $v[0], (int) $v[1] );
}

/**
 * Count one wrong code. The hour runs from the first wrong code.
 */
function finishcoat_code_fail_add() {
	list( $n, $start ) = finishcoat_code_fails();
	$now               = time();
	$start             = $n ? $start : $now;
	set_transient( finishcoat_code_fail_key(), array( $n + 1, $start ), max( 1, $start + HOUR_IN_SECONDS - $now ) );
}

/**
 * What the last check found: '' (passed), 'required' (missing or wrong) or 'limited', plus the label of the code
 * that passed, for storing on what the request makes.
 *
 * @param array|null $set New state.
 * @return array{why: string, label: string}
 */
function finishcoat_code_state( $set = null ) {
	static $state = array(
		'why'   => '',
		'label' => '',
	);
	if ( is_array( $set ) ) {
		$state = array_merge( $state, $set );
	}
	return $state;
}

/**
 * The code sent with this request, normalized, or ''.
 *
 * @return string
 */
function finishcoat_request_code() {
	// phpcs:disable WordPress.Security.NonceVerification -- the review code is the check here.
	$raw = '';
	if ( isset( $_POST['code'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_POST['code'] ) );
	} elseif ( isset( $_GET['code'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_GET['code'] ) );
	}
	// phpcs:enable
	return finishcoat_code_normalize( $raw );
}

/**
 * Whether this request may use the project: a verified studio request, or an unrevoked code of the project
 * (compared in constant time). A wrong code counts against the visitor's hourly limit. Past the limit every code
 * is refused until the hour is up.
 *
 * @param string $slug   Project slug.
 * @param bool   $studio Verified studio request (logged in, editor, valid studio nonce).
 * @return bool
 */
function finishcoat_check_code( $slug, $studio ) {
	finishcoat_code_state(
		array(
			'why'   => '',
			'label' => '',
		)
	);
	if ( $studio ) {
		return (bool) apply_filters( 'finishcoat_access_check', true, $slug, true );
	}
	list( $fails ) = finishcoat_code_fails();
	if ( $fails >= finishcoat_code_fail_limit() ) {
		finishcoat_code_state( array( 'why' => 'limited' ) );
		return false;
	}
	$given = finishcoat_request_code();
	if ( '' === $given ) {
		finishcoat_code_state( array( 'why' => 'required' ) );
		return false;
	}
	// Compare keyed hashes, so every comparison has the same length, and look at every code.
	$key   = wp_salt( 'nonce' );
	$mine  = hash_hmac( 'sha256', $given, $key );
	$label = '';
	$hit   = false;
	foreach ( finishcoat_project_codes( $slug ) as $c ) {
		if ( ! is_array( $c ) || ! isset( $c['code'] ) ) {
			continue;
		}
		$same = hash_equals( hash_hmac( 'sha256', (string) $c['code'], $key ), $mine );
		if ( $same && empty( $c['revoked'] ) && ! $hit ) {
			$hit   = true;
			$label = isset( $c['label'] ) ? (string) $c['label'] : '';
		}
	}
	$hit = (bool) apply_filters( 'finishcoat_access_check', $hit, $slug, false );
	if ( ! $hit ) {
		finishcoat_code_fail_add();
		finishcoat_code_state( array( 'why' => 'required' ) );
		return false;
	}
	finishcoat_code_state( array( 'label' => $label ) );
	return true;
}

/**
 * Stop the request unless the check passes: 403 { ok: false, code_required: true } for a missing or wrong code,
 * 429 for too many wrong ones. Neither says which codes exist.
 *
 * @param string $slug   Project slug.
 * @param bool   $studio Verified studio request.
 */
function finishcoat_require_code( $slug, $studio ) {
	if ( finishcoat_check_code( $slug, $studio ) ) {
		return;
	}
	$st = finishcoat_code_state();
	if ( 'limited' === $st['why'] ) {
		finishcoat_json(
			array(
				'ok'            => false,
				'code_required' => true,
				'limited'       => true,
				'message'       => __( 'Too many tries. Try again in an hour.', 'finishcoat' ),
			),
			429
		);
	}
	finishcoat_json(
		array(
			'ok'            => false,
			'code_required' => true,
			'message'       => __( 'Enter your review code.', 'finishcoat' ),
		),
		403
	);
}

/**
 * Label of the code this request passed with, or ''.
 *
 * @return string
 */
function finishcoat_code_label() {
	$st = finishcoat_code_state();
	return (string) $st['label'];
}

/**
 * Store the code's label on a note or photo made with a code.
 *
 * @param int $id Post ID.
 */
function finishcoat_code_mark( $id ) {
	$label = finishcoat_code_label();
	if ( '' !== $label && $id ) {
		update_post_meta( (int) $id, '_finishcoat_code_label', wp_slash( $label ) );
	}
}

/**
 * The code label stored on a post, or ''.
 *
 * @param int $id Post ID.
 * @return string
 */
function finishcoat_code_label_of( $id ) {
	return (string) get_post_meta( (int) $id, '_finishcoat_code_label', true );
}

/**
 * The studio verified on this request: logged in, editor, valid studio nonce.
 *
 * @return bool
 */
function finishcoat_studio_request() {
	if ( ! finishcoat_is_studio() ) {
		return false;
	}
	// phpcs:disable WordPress.Security.NonceVerification -- this reads the nonce to verify it.
	$n = '';
	if ( isset( $_POST['nonce'] ) ) {
		$n = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
	} elseif ( isset( $_GET['nonce'] ) ) {
		$n = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
	}
	// phpcs:enable
	return '' !== $n && false !== wp_verify_nonce( $n, 'finishcoat_studio' );
}

/*
 * ------------------------------------------------------------------------
 * Settings: the Review codes box
 * ------------------------------------------------------------------------
 */

/**
 * The review link for a code.
 *
 * @param string $code Stored code.
 * @return string
 */
function finishcoat_code_link( $code ) {
	return add_query_arg( 'finishcoat', rawurlencode( (string) $code ), home_url( '/' ) );
}

/**
 * Hidden fields shared by every codes form.
 *
 * @param string $act Action.
 * @param string $id  Code ID, or ''.
 */
function finishcoat_codes_hidden( $act, $id = '' ) {
	echo '<input type="hidden" name="action" value="finishcoat_codes"><input type="hidden" name="do" value="' . esc_attr( $act ) . '">';
	if ( '' !== $id ) {
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
	}
	wp_nonce_field( 'finishcoat_codes' );
}

/**
 * The Review codes box on the Settings screen. Each row has its own small forms.
 */
function finishcoat_codes_section() {
	$codes  = finishcoat_codes();
	$post   = admin_url( 'admin-post.php' );
	$active = 0;
	foreach ( $codes as $c ) {
		if ( empty( $c['revoked'] ) ) {
			++$active;
		}
	}
	echo '<h2 id="finishcoat-codes">' . esc_html__( 'Review codes', 'finishcoat' ) . '</h2>';
	echo '<p>' . esc_html__( 'Clients need a code to read and send notes. Send each person or team the review link for their code: opening it saves the code in their browser for 90 days.', 'finishcoat' ) . '</p>';
	if ( ! $active ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'No code works right now, so only logged-in editors can see notes. Add a code below.', 'finishcoat' ) . '</p></div>';
	}
	if ( $codes ) {
		echo '<table class="widefat striped finishcoat-codes"><thead><tr>';
		foreach ( array( __( 'Label', 'finishcoat' ), __( 'Code', 'finishcoat' ), __( 'Created', 'finishcoat' ), __( 'Status', 'finishcoat' ), __( 'Actions', 'finishcoat' ) ) as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $codes as $c ) {
			$off = ! empty( $c['revoked'] );
			echo '<tr><td><form method="post" action="' . esc_url( $post ) . '" class="finishcoat-inline">';
			finishcoat_codes_hidden( 'rename', $c['id'] );
			echo '<label class="screen-reader-text" for="finishcoat-cl-' . esc_attr( $c['id'] ) . '">' . esc_html__( 'Label', 'finishcoat' ) . '</label>';
			echo '<input type="text" id="finishcoat-cl-' . esc_attr( $c['id'] ) . '" name="label" maxlength="40" value="' . esc_attr( $c['label'] ) . '"> ';
			echo '<button type="submit" class="button button-small">' . esc_html__( 'Rename', 'finishcoat' ) . '</button></form></td>';
			echo '<td><code class="' . ( $off ? 'finishcoat-revoked' : '' ) . '">' . esc_html( finishcoat_code_format( $c['code'] ) ) . '</code></td>';
			echo '<td>' . esc_html( $c['created'] ? wp_date( get_option( 'date_format' ), $c['created'] ) : '' ) . '</td>';
			echo '<td>' . esc_html(
				$off
					/* translators: %s: date the code was revoked. */
					? sprintf( __( 'Revoked %s', 'finishcoat' ), wp_date( get_option( 'date_format' ), $c['revoked'] ) )
					: __( 'Active', 'finishcoat' )
			) . '</td><td class="finishcoat-actions">';
			if ( ! $off ) {
				$link = finishcoat_code_link( $c['code'] );
				echo '<button type="button" class="button button-small finishcoat-copy" data-copy="' . esc_attr( $link ) . '">' . esc_html__( 'Copy review link', 'finishcoat' ) . '</button> ';
				echo '<form method="post" action="' . esc_url( $post ) . '" class="finishcoat-inline">';
				finishcoat_codes_hidden( 'regenerate', $c['id'] );
				echo '<button type="submit" class="button button-small" data-confirm="' . esc_attr__( 'Make a new code for this label? The old code and its link stop working.', 'finishcoat' ) . '">' . esc_html__( 'Regenerate', 'finishcoat' ) . '</button></form> ';
				echo '<form method="post" action="' . esc_url( $post ) . '" class="finishcoat-inline">';
				finishcoat_codes_hidden( 'revoke', $c['id'] );
				echo '<button type="submit" class="button button-small button-link-delete" data-confirm="' . esc_attr__( 'Revoke this code? Anyone using it is asked for a new one.', 'finishcoat' ) . '">' . esc_html__( 'Revoke', 'finishcoat' ) . '</button></form>';
			} else {
				echo '<form method="post" action="' . esc_url( $post ) . '" class="finishcoat-inline">';
				finishcoat_codes_hidden( 'remove', $c['id'] );
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Remove from the list', 'finishcoat' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
	if ( count( $codes ) < finishcoat_code_max() ) {
		echo '<form method="post" action="' . esc_url( $post ) . '" class="finishcoat-addcode">';
		finishcoat_codes_hidden( 'add' );
		echo '<label for="finishcoat-code-label">' . esc_html__( 'Label', 'finishcoat' ) . '</label> <input type="text" id="finishcoat-code-label" name="label" maxlength="40" placeholder="' . esc_attr__( 'Marketing lead', 'finishcoat' ) . '"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Add code', 'finishcoat' ) . '</button></form>';
		echo '<p class="description">' . esc_html__( 'The label shows next to the name on each note, so you know who sent what.', 'finishcoat' ) . '</p>';
	} else {
		echo '<p class="description">' . esc_html(
			/* translators: %d: most codes allowed. */
			sprintf( __( 'You have the most codes a site can hold (%d). Remove a revoked code to add another.', 'finishcoat' ), finishcoat_code_max() )
		) . '</p>';
	}
}

/**
 * Add, rename, regenerate, revoke or remove a code (admin-post action finishcoat_codes).
 */
function finishcoat_handle_codes() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'finishcoat' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'finishcoat_codes' );
	$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
	$label = finishcoat_code_clean_label( isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '' );
	$codes = finishcoat_codes();
	$i     = -1;
	foreach ( $codes as $k => $c ) {
		if ( '' !== $id && $c['id'] === $id ) {
			$i = (int) $k;
		}
	}
	if ( 'add' === $do ) {
		if ( count( $codes ) >= finishcoat_code_max() ) {
			finishcoat_add_notice( 'error', __( 'You have the most codes a site can hold.', 'finishcoat' ) );
		} else {
			$c       = finishcoat_code_new( $codes, $label );
			$codes[] = $c;
			/* translators: %s: code label. */
			finishcoat_add_notice( 'success', sprintf( __( 'Code added: %s. Copy its review link and send it to the client.', 'finishcoat' ), $c['label'] ) );
		}
	} elseif ( $i < 0 ) {
		finishcoat_add_notice( 'error', __( 'That code is gone.', 'finishcoat' ) );
	} elseif ( 'rename' === $do ) {
		if ( '' === $label ) {
			finishcoat_add_notice( 'error', __( 'Give the code a label.', 'finishcoat' ) );
		} else {
			$codes[ $i ]['label'] = $label;
			/* translators: %s: code label. */
			finishcoat_add_notice( 'success', sprintf( __( 'Code renamed: %s.', 'finishcoat' ), $label ) );
		}
	} elseif ( 'regenerate' === $do && empty( $codes[ $i ]['revoked'] ) ) {
		$codes[ $i ]['code']    = finishcoat_code_generate( $codes );
		$codes[ $i ]['created'] = time();
		/* translators: %s: code label. */
		finishcoat_add_notice( 'success', sprintf( __( 'New code made for %s. The old code stopped working. Send the new review link.', 'finishcoat' ), $codes[ $i ]['label'] ) );
	} elseif ( 'revoke' === $do ) {
		if ( empty( $codes[ $i ]['revoked'] ) ) {
			$codes[ $i ]['revoked'] = time();
		}
		/* translators: %s: code label. */
		finishcoat_add_notice( 'success', sprintf( __( 'Code revoked: %s.', 'finishcoat' ), $codes[ $i ]['label'] ) );
	} elseif ( 'remove' === $do && ! empty( $codes[ $i ]['revoked'] ) ) {
		array_splice( $codes, $i, 1 );
		finishcoat_add_notice( 'success', __( 'Revoked code removed from the list.', 'finishcoat' ) );
	} else {
		finishcoat_add_notice( 'error', __( 'Unknown action.', 'finishcoat' ) );
	}
	finishcoat_save_codes( $codes );
	wp_safe_redirect( finishcoat_settings_url() . '#finishcoat-codes' );
	exit;
}
add_action( 'admin_post_finishcoat_codes', 'finishcoat_handle_codes' );
