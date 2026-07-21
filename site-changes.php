<?php
/**
 * "Site changes" — a central, read-only audit view of every front-end edit
 * across the whole site, gathered from the structured notes recorded by
 * jfect_record_edit(). Tools -> Site changes.
 *
 * @package JFECT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The capability required to view the audit screen: owners and agencies
 * (editors + admins), not every contributor.
 */
function jfect_site_changes_cap() {
	return 'edit_others_posts';
}

/**
 * Build the WP_Comment_Query args for the active filters, shared by the list
 * table and the CSV export so the two never drift. Returns the base args
 * (no number/offset); callers add paging.
 */
function jfect_changes_query_args() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$f_user  = isset( $_GET['jfect_user'] ) ? absint( $_GET['jfect_user'] ) : 0;
	$f_post  = isset( $_GET['jfect_post'] ) ? absint( $_GET['jfect_post'] ) : 0;
	$f_block = isset( $_GET['jfect_block'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_block'] ) ) : '';
	$f_from  = isset( $_GET['jfect_from'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_from'] ) ) : '';
	$f_to    = isset( $_GET['jfect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_to'] ) ) : '';
	$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$meta_query = array( array( 'key' => 'jfect_edit', 'value' => '1' ) );
	if ( '' !== $f_block ) {
		$meta_query[] = array( 'key' => 'jfect_block_type', 'value' => $f_block );
	}
	if ( $f_post ) {
		$meta_query[] = array( 'key' => 'jfect_post_id', 'value' => $f_post, 'type' => 'NUMERIC' );
	}

	$args = array(
		'type'       => 'note',
		'status'     => 'approve',
		'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- filtering a small note subset, paginated.
		'orderby'    => 'comment_date_gmt',
		'order'      => 'DESC',
	);
	if ( $f_user ) {
		$args['user_id'] = $f_user;
	}
	if ( '' !== $search ) {
		$args['search'] = $search;
	}

	$date_query = array();
	if ( '' !== $f_from ) {
		$date_query['after'] = $f_from . ' 00:00:00';
	}
	if ( '' !== $f_to ) {
		$date_query['before'] = $f_to . ' 23:59:59';
	}
	if ( $date_query ) {
		$date_query['inclusive'] = true;
		$args['date_query']      = array( $date_query );
	}

	return $args;
}

/* -------------------------------------------------------------------------- */
/* CSV export of the currently-filtered edits.                                 */
/* -------------------------------------------------------------------------- */

add_action( 'admin_post_jfect_export_changes', 'jfect_export_site_changes' );

/**
 * Make a value safe to write into a CSV cell, neutralising spreadsheet formula
 * injection (leading =, +, -, @).
 */
function jfect_csv_cell( $value ) {
	$value = (string) $value;
	if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
		$value = "'" . $value;
	}
	return $value;
}

/**
 * Stream every edit matching the active filters as a CSV download.
 */
function jfect_export_site_changes() {
	if ( ! current_user_can( jfect_site_changes_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to export.', 'jamies-front-end-editor-for-content-teams' ) );
	}
	check_admin_referer( 'jfect_export' );

	$args = jfect_changes_query_args();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="site-changes-' . gmdate( 'Y-m-d' ) . '.csv"' );

	echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads accents correctly. phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a download, not touching the filesystem.
	fputcsv( $out, array( 'Date', 'Page', 'Page ID', 'Post type', 'User', 'Block type', 'Old', 'New', 'Note' ) );

	$batch  = 500;
	$offset = 0;
	do {
		$rows = get_comments( array_merge( $args, array( 'number' => $batch, 'offset' => $offset ) ) );
		foreach ( $rows as $c ) {
			$pid = (int) get_comment_meta( $c->comment_ID, 'jfect_post_id', true );
			if ( ! $pid ) {
				$pid = (int) $c->comment_post_ID;
			}
			$post  = $pid ? get_post( $pid ) : null;
			$title = $post ? get_the_title( $post ) : '(deleted)';
			$ptype = $post ? $post->post_type : '';

			$uid   = (int) get_comment_meta( $c->comment_ID, 'jfect_user_id', true );
			$uname = '';
			if ( $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) {
					$uname = $u->display_name;
				}
			}
			if ( '' === $uname ) {
				$uname = $c->comment_author;
			}

			fputcsv( $out, array(
				jfect_csv_cell( $c->comment_date ),
				jfect_csv_cell( wp_strip_all_tags( $title ) ),
				jfect_csv_cell( $pid ),
				jfect_csv_cell( $ptype ),
				jfect_csv_cell( $uname ),
				jfect_csv_cell( get_comment_meta( $c->comment_ID, 'jfect_block_type', true ) ),
				jfect_csv_cell( get_comment_meta( $c->comment_ID, 'jfect_old', true ) ),
				jfect_csv_cell( get_comment_meta( $c->comment_ID, 'jfect_new', true ) ),
				jfect_csv_cell( wp_strip_all_tags( $c->comment_content ) ),
			) );
		}
		$offset += $batch;
	} while ( count( $rows ) === $batch );

	exit; // php://output closes with the request; no fclose needed.
}

/* -------------------------------------------------------------------------- */
/* One-time backfill: tag pre-0.8 front-end notes with structured meta so they */
/* appear in the audit view alongside new ones.                                */
/* -------------------------------------------------------------------------- */

add_action( 'admin_init', 'jfect_maybe_backfill_notes' );

/**
 * Backfill jfect_* comment meta onto existing front-end notes, once.
 */
function jfect_maybe_backfill_notes() {
	if ( get_option( 'jfect_backfill_v1' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return; // Let an admin's first visit trigger it; avoids running for everyone.
	}

	$offset = 0;
	$batch  = 200;

	do {
		$comments = get_comments( array(
			'type'    => 'note',
			'number'  => $batch,
			'offset'  => $offset,
			'orderby' => 'comment_ID',
			'order'   => 'ASC',
		) );

		foreach ( $comments as $c ) {
			if ( get_comment_meta( $c->comment_ID, 'jfect_edit', true ) ) {
				continue; // Already tagged (a new note).
			}
			if ( false === strpos( $c->comment_content, 'from front end' ) ) {
				continue; // Not one of ours.
			}

			$block_type = 'content';
			if ( preg_match( '/^Edited (\w+) from front end/', $c->comment_content, $m ) ) {
				$block_type = $m[1];
			} elseif ( false !== strpos( $c->comment_content, 'image' ) ) {
				$block_type = 'image';
			} elseif ( false !== strpos( $c->comment_content, 'button' ) ) {
				$block_type = 'button';
			}

			add_comment_meta( $c->comment_ID, 'jfect_edit', 1 );
			add_comment_meta( $c->comment_ID, 'jfect_post_id', (int) $c->comment_post_ID );
			add_comment_meta( $c->comment_ID, 'jfect_block_type', $block_type );
			add_comment_meta( $c->comment_ID, 'jfect_user_id', (int) $c->user_id );

			if ( preg_match( '/: "(.*)" \x{2192} "(.*)"\s*$/u', $c->comment_content, $mm ) ) {
				add_comment_meta( $c->comment_ID, 'jfect_old', $mm[1] );
				add_comment_meta( $c->comment_ID, 'jfect_new', $mm[2] );
			}
		}

		$offset += $batch;
	} while ( count( $comments ) === $batch );

	update_option( 'jfect_backfill_v1', 1 );
}

/* -------------------------------------------------------------------------- */
/* Admin menu + page                                                          */
/* -------------------------------------------------------------------------- */

add_action( 'admin_menu', function () {
	add_management_page(
		__( 'Site changes', 'jamies-front-end-editor-for-content-teams' ),
		__( 'Site changes', 'jamies-front-end-editor-for-content-teams' ),
		jfect_site_changes_cap(),
		'jfect-site-changes',
		'jfect_render_site_changes_page'
	);
} );

/**
 * Distinct comment-meta values for building filter dropdowns.
 *
 * @param string $key Meta key.
 * @return array
 */
function jfect_distinct_meta_values( $key ) {
	global $wpdb;
	$values = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"SELECT DISTINCT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s ORDER BY meta_value ASC",
		$key
	) );
	return array_filter( (array) $values, 'strlen' );
}

/**
 * Render the Site changes admin page: filters + the list table.
 */
function jfect_render_site_changes_page() {
	if ( ! current_user_can( jfect_site_changes_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'jamies-front-end-editor-for-content-teams' ) );
	}

	require_once __DIR__ . '/class-jfect-site-changes-table.php';

	$table = new JFECT_Site_Changes_Table();
	$table->prepare_items();

	// Current filter values (read-only screen, so plain GET is fine).
	$f_user  = isset( $_GET['jfect_user'] ) ? absint( $_GET['jfect_user'] ) : 0;                          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$f_post  = isset( $_GET['jfect_post'] ) ? absint( $_GET['jfect_post'] ) : 0;                          // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$f_block = isset( $_GET['jfect_block'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_block'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$f_from  = isset( $_GET['jfect_from'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_from'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$f_to    = isset( $_GET['jfect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['jfect_to'] ) ) : '';       // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';            // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	// Export link carries the active filters and a nonce.
	$export_url = wp_nonce_url(
		add_query_arg(
			array_filter( array(
				'action'      => 'jfect_export_changes',
				'jfect_user'  => $f_user ? $f_user : null,
				'jfect_post'  => $f_post ? $f_post : null,
				'jfect_block' => '' !== $f_block ? $f_block : null,
				'jfect_from'  => '' !== $f_from ? $f_from : null,
				'jfect_to'    => '' !== $f_to ? $f_to : null,
				's'           => '' !== $search ? $search : null,
			) ),
			admin_url( 'admin-post.php' )
		),
		'jfect_export'
	);

	$user_ids  = array_map( 'intval', jfect_distinct_meta_values( 'jfect_user_id' ) );
	$post_ids  = array_map( 'intval', jfect_distinct_meta_values( 'jfect_post_id' ) );
	$block_all = jfect_distinct_meta_values( 'jfect_block_type' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Site changes', 'jamies-front-end-editor-for-content-teams' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Every front-end edit made across your site: what changed, where, by whom, and when.', 'jamies-front-end-editor-for-content-teams' ); ?></p>

		<?php
		/**
		 * Extension point just below the heading (used by the repo-only demo
		 * seeder, which is not shipped to WordPress.org).
		 */
		do_action( 'jfect_site_changes_after_heading' );
		?>

		<form method="get">
			<input type="hidden" name="page" value="jfect-site-changes" />
			<div class="tablenav top">
				<div class="alignleft actions">
					<label class="screen-reader-text" for="jfect_user"><?php esc_html_e( 'Filter by user', 'jamies-front-end-editor-for-content-teams' ); ?></label>
					<select name="jfect_user" id="jfect_user">
						<option value="0"><?php esc_html_e( 'All users', 'jamies-front-end-editor-for-content-teams' ); ?></option>
						<?php foreach ( $user_ids as $uid ) :
							$u = get_userdata( $uid );
							if ( ! $u ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $uid ); ?>" <?php selected( $f_user, $uid ); ?>><?php echo esc_html( $u->display_name ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="jfect_post"><?php esc_html_e( 'Filter by page', 'jamies-front-end-editor-for-content-teams' ); ?></label>
					<select name="jfect_post" id="jfect_post">
						<option value="0"><?php esc_html_e( 'All pages', 'jamies-front-end-editor-for-content-teams' ); ?></option>
						<?php foreach ( $post_ids as $pid ) :
							$title = get_the_title( $pid );
							if ( '' === $title ) {
								/* translators: %d: ID of a page that no longer exists. */
							$title = sprintf( __( '#%d (deleted)', 'jamies-front-end-editor-for-content-teams' ), $pid );
							}
							?>
							<option value="<?php echo esc_attr( $pid ); ?>" <?php selected( $f_post, $pid ); ?>><?php echo esc_html( wp_strip_all_tags( $title ) ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="jfect_block"><?php esc_html_e( 'Filter by block type', 'jamies-front-end-editor-for-content-teams' ); ?></label>
					<select name="jfect_block" id="jfect_block">
						<option value=""><?php esc_html_e( 'All block types', 'jamies-front-end-editor-for-content-teams' ); ?></option>
						<?php foreach ( $block_all as $bt ) : ?>
							<option value="<?php echo esc_attr( $bt ); ?>" <?php selected( $f_block, $bt ); ?>><?php echo esc_html( ucfirst( $bt ) ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="jfect_from"><?php esc_html_e( 'From date', 'jamies-front-end-editor-for-content-teams' ); ?></label>
					<input type="date" name="jfect_from" id="jfect_from" value="<?php echo esc_attr( $f_from ); ?>" />
					<label class="screen-reader-text" for="jfect_to"><?php esc_html_e( 'To date', 'jamies-front-end-editor-for-content-teams' ); ?></label>
					<input type="date" name="jfect_to" id="jfect_to" value="<?php echo esc_attr( $f_to ); ?>" />

					<?php submit_button( __( 'Filter', 'jamies-front-end-editor-for-content-teams' ), '', 'filter_action', false ); ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=jfect-site-changes' ) ); ?>"><?php esc_html_e( 'Reset', 'jamies-front-end-editor-for-content-teams' ); ?></a>
				</div>
				<div class="alignright actions">
					<a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'jamies-front-end-editor-for-content-teams' ); ?></a>
				</div>
			</div>

			<?php $table->search_box( __( 'Search changes', 'jamies-front-end-editor-for-content-teams' ), 'jfect-search' ); ?>
			<?php $table->display(); ?>
		</form>
	</div>
	<?php
}
