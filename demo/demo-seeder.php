<?php
/**
 * Repo-only demo-data seeder for local demos (e.g. `pressship demo`).
 *
 * This file is listed in .pressshipignore, so it is NOT included in the
 * WordPress.org release zip. The main plugin only loads it when the file is
 * present on disk, so none of this ships to production.
 *
 * It adds a "Load demo data" button to Tools -> Site changes that creates a few
 * sample users, pages and ~55 example front-end edits.
 *
 * @package JFECT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the demo button (and a success notice after seeding) below the heading.
 */
add_action( 'jfect_site_changes_after_heading', function () {
	if ( ! current_user_can( jfect_site_changes_cap() ) ) {
		return;
	}

	if ( isset( $_GET['jfect_seeded'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( sprintf(
				/* translators: %d: number of demo edits created. */
				'Added %d demo edits (plus sample users and pages).',
				absint( $_GET['jfect_seeded'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			) )
			. '</p></div>';
	}

	$url = wp_nonce_url( admin_url( 'admin-post.php?action=jfect_seed_demo' ), 'jfect_seed_demo' );
	echo '<p><a class="button" href="' . esc_url( $url ) . '">Load demo data</a> '
		. '<span class="description">Adds sample users and ~55 example edits. Local demo tool, not shipped.</span></p>';
} );

/**
 * Handle the seed action.
 */
add_action( 'admin_post_jfect_seed_demo', function () {
	if ( ! current_user_can( jfect_site_changes_cap() ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'jfect_seed_demo' );
	$n = jfect_seed_demo_data();
	wp_safe_redirect( add_query_arg(
		array( 'page' => 'jfect-site-changes', 'jfect_seeded' => (int) $n ),
		admin_url( 'tools.php' )
	) );
	exit;
} );

/**
 * Create sample users, pages and ~55 front-end edits. Idempotent for users and
 * pages; returns the number of edits created this run.
 */
function jfect_seed_demo_data() {
	$users = array();
	$admin = get_user_by( 'login', 'admin' );
	if ( ! $admin ) {
		$admin = wp_get_current_user();
	}
	if ( $admin && $admin->ID ) {
		$users[] = array( 'id' => $admin->ID, 'name' => $admin->display_name );
	}

	$demo_users = array(
		array( 'sarah_editor', 'Sarah Chen', 'editor', 'sarah@example.com' ),
		array( 'tom_author', 'Tom Rivera', 'author', 'tom@example.com' ),
		array( 'mia_contrib', 'Mia Novak', 'contributor', 'mia@example.com' ),
	);
	foreach ( $demo_users as $du ) {
		$u   = get_user_by( 'login', $du[0] );
		$uid = $u ? $u->ID : wp_insert_user( array(
			'user_login'   => $du[0],
			'user_pass'    => wp_generate_password( 16 ),
			'display_name' => $du[1],
			'user_email'   => $du[3],
			'role'         => $du[2],
		) );
		if ( ! is_wp_error( $uid ) && $uid ) {
			$users[] = array( 'id' => (int) $uid, 'name' => $du[1] );
		}
	}
	if ( empty( $users ) ) {
		return 0;
	}

	$existing = array();
	foreach ( get_posts( array( 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'any' ) ) as $pg ) {
		$existing[ $pg->post_title ] = $pg->ID;
	}
	$pages = array();
	foreach ( array( 'About us', 'Our services', 'Pricing', 'Careers', 'Contact' ) as $pt ) {
		if ( isset( $existing[ $pt ] ) ) {
			$pages[] = $existing[ $pt ];
			continue;
		}
		$content = '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $pt ) . '</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Placeholder copy for the ' . esc_html( strtolower( $pt ) ) . ' page.</p><!-- /wp:paragraph -->';
		$pid = wp_insert_post( array(
			'post_title'   => $pt,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		) );
		if ( ! is_wp_error( $pid ) ) {
			$pages[] = $pid;
		}
	}
	if ( empty( $pages ) ) {
		return 0;
	}

	$pairs = array(
		array( 'paragraph', 'We help teams ship faster.', 'We help teams ship better software, faster.' ),
		array( 'heading', 'Our mission', 'What drives us' ),
		array( 'paragraph', 'Contact us today.', 'Get in touch with our team today.' ),
		array( 'paragraph', 'Trusted by hundreds.', 'Trusted by over 500 companies worldwide.' ),
		array( 'heading', 'Pricing', 'Simple, honest pricing' ),
		array( 'paragraph', 'Free trial available.', 'Start your 14-day free trial, no card needed.' ),
		array( 'paragraph', 'We are hiring.', 'We are hiring across engineering and design.' ),
		array( 'paragraph', 'Fast support.', 'Friendly support, replies within the hour.' ),
	);
	$img = array(
		'Replaced an image from the front end',
		'Updated image alt text from the front end',
		'Removed an image from the front end',
	);

	$now     = current_time( 'timestamp' );
	$offset  = (int) ( (float) get_option( 'gmt_offset' ) * 3600 );
	$created = 0;

	for ( $i = 0; $i < 55; $i++ ) {
		$u   = $users[ array_rand( $users ) ];
		$pid = $pages[ array_rand( $pages ) ];
		$ts  = $now - wp_rand( 0, 21 * 24 * 3600 );
		$loc = gmdate( 'Y-m-d H:i:s', $ts );
		$gmt = gmdate( 'Y-m-d H:i:s', $ts - $offset );
		$r   = wp_rand( 0, 9 );
		$old = '';
		$new = '';
		if ( $r < 7 ) {
			$p    = $pairs[ array_rand( $pairs ) ];
			$bt   = $p[0];
			$old  = $p[1];
			$new  = $p[2];
			$note = sprintf( 'Edited %s from front end: "%s" %s "%s"', $bt, $old, "\xe2\x86\x92", $new );
		} elseif ( $r < 9 ) {
			$bt   = 'image';
			$note = $img[ array_rand( $img ) ];
		} else {
			$bt   = 'button';
			$note = 'Edited a button from the front end';
		}

		$cid = wp_insert_comment( array(
			'comment_post_ID'      => $pid,
			'comment_content'      => $note,
			'comment_type'         => 'note',
			'user_id'              => $u['id'],
			'comment_author'       => $u['name'],
			'comment_author_email' => '',
			'comment_approved'     => 1,
			'comment_date'         => $loc,
			'comment_date_gmt'     => $gmt,
		) );
		if ( $cid ) {
			add_comment_meta( $cid, 'jfect_edit', 1 );
			add_comment_meta( $cid, 'jfect_post_id', (int) $pid );
			add_comment_meta( $cid, 'jfect_block_type', $bt );
			add_comment_meta( $cid, 'jfect_user_id', (int) $u['id'] );
			if ( '' !== $old ) {
				add_comment_meta( $cid, 'jfect_old', $old );
			}
			if ( '' !== $new ) {
				add_comment_meta( $cid, 'jfect_new', $new );
			}
			$created++;
		}
	}

	update_option( 'jfect_backfill_v1', 1 );
	return $created;
}
