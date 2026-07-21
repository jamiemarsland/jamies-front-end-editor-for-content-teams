<?php
/**
 * The Site changes list table: one row per front-end edit, newest first,
 * with filters, search and pagination, backed by WP_Comment_Query.
 *
 * @package JFECT
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only audit table of front-end edits.
 */
class JFECT_Site_Changes_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => 'site_change',
			'plural'   => 'site_changes',
			'ajax'     => false,
		) );
	}

	/**
	 * Columns shown in the table.
	 */
	public function get_columns() {
		return array(
			'when'   => __( 'When', 'jamies-front-end-editor-for-content-teams' ),
			'page'   => __( 'Page', 'jamies-front-end-editor-for-content-teams' ),
			'who'    => __( 'Who', 'jamies-front-end-editor-for-content-teams' ),
			'block'  => __( 'Block', 'jamies-front-end-editor-for-content-teams' ),
			'change' => __( 'Change', 'jamies-front-end-editor-for-content-teams' ),
		);
	}

	/**
	 * Build the query from the active filters and fetch a page of edits.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'page' );

		$per_page = 25;
		$paged    = $this->get_pagenum();
		$offset   = ( $paged - 1 ) * $per_page;

		// Shared with the CSV export so the two never diverge.
		$args = jfect_changes_query_args();

		$total = (int) get_comments( array_merge( $args, array( 'count' => true ) ) );

		$this->items = get_comments( array_merge( $args, array(
			'number' => $per_page,
			'offset' => $offset,
		) ) );

		$this->set_pagination_args( array(
			'total_items' => $total,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		) );
	}

	/**
	 * Message when there are no edits to show.
	 */
	public function no_items() {
		esc_html_e( 'No front-end changes yet.', 'jamies-front-end-editor-for-content-teams' );
	}

	/**
	 * When: relative time, exact timestamp on hover.
	 */
	public function column_when( $item ) {
		$ts    = (int) mysql2date( 'U', $item->comment_date_gmt );
		$now   = (int) current_time( 'timestamp', true );
		$rel   = human_time_diff( $ts, $now );
		$exact = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->comment_date );

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( $exact ),
			$ts <= $now
				/* translators: %s: human-readable time difference, e.g. "2 hours". */
				? esc_html( sprintf( __( '%s ago', 'jamies-front-end-editor-for-content-teams' ), $rel ) )
				: esc_html( $exact )
		);
	}

	/**
	 * Page: post title linked to its editor, or a graceful "(deleted)".
	 */
	public function column_page( $item ) {
		$post_id = (int) get_comment_meta( $item->comment_ID, 'jfect_post_id', true );
		if ( ! $post_id ) {
			$post_id = (int) $item->comment_post_ID;
		}
		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return '<span style="color:#787c82;">' . esc_html__( '(deleted)', 'jamies-front-end-editor-for-content-teams' ) . '</span>';
		}

		$title = get_the_title( $post );
		if ( '' === $title ) {
			$title = __( '(no title)', 'jamies-front-end-editor-for-content-teams' );
		}
		$edit = get_edit_post_link( $post_id );
		$type = get_post_type_object( $post->post_type );
		$type_label = $type ? $type->labels->singular_name : $post->post_type;

		$name = $edit
			? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>'
			: esc_html( $title );

		return $name . '<br><span style="color:#787c82;font-size:12px;">' . esc_html( $type_label ) . '</span>';
	}

	/**
	 * Who: the editor's name (falls back to the stored author name).
	 */
	public function column_who( $item ) {
		$name = '';
		$uid  = (int) get_comment_meta( $item->comment_ID, 'jfect_user_id', true );
		if ( $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$name = $u->display_name;
			}
		}
		if ( '' === $name ) {
			$name = $item->comment_author;
		}
		return esc_html( $name ? $name : __( 'Unknown', 'jamies-front-end-editor-for-content-teams' ) );
	}

	/**
	 * Block: the edited block type.
	 */
	public function column_block( $item ) {
		$bt = get_comment_meta( $item->comment_ID, 'jfect_block_type', true );
		return $bt ? esc_html( ucfirst( $bt ) ) : '&mdash;';
	}

	/**
	 * Change: old -> new for text edits, otherwise the note summary.
	 */
	public function column_change( $item ) {
		$old = get_comment_meta( $item->comment_ID, 'jfect_old', true );
		$new = get_comment_meta( $item->comment_ID, 'jfect_new', true );

		if ( '' !== (string) $old || '' !== (string) $new ) {
			$old_s = $this->clip( $old );
			$new_s = $this->clip( $new );
			return sprintf(
				'<span title="%1$s" style="color:#b32d2e;text-decoration:line-through;">%2$s</span> <span style="color:#787c82;">&rarr;</span> <span title="%3$s" style="color:#1a7f37;">%4$s</span>',
				esc_attr( (string) $old ),
				esc_html( $old_s ),
				esc_attr( (string) $new ),
				esc_html( $new_s )
			);
		}

		// Non-text edits (image / button): show the human note.
		return '<span title="' . esc_attr( wp_strip_all_tags( $item->comment_content ) ) . '">' . esc_html( $this->clip( wp_strip_all_tags( $item->comment_content ), 90 ) ) . '</span>';
	}

	/**
	 * Fallback for any column without a dedicated method.
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Truncate a string for display.
	 */
	private function clip( $text, $len = 60 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '""';
		}
		return mb_strimwidth( $text, 0, $len, '…' );
	}
}
