<?php
/**
 * Plugin Name: Jamie's Front-End Editor for Content Teams
 * Description: Simple front-end text editing for your team. Click any text block to edit it on the live site — block markup is preserved.
 * Version: 0.2
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Jamie Marsland
 * Author URI: https://profiles.wordpress.org/jamiemarsland/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jamies-front-end-editor-for-content-teams
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All block types that can be offered as editable.
 */
define( 'JFECT_SUPPORTED_BLOCKS', array(
	'core/paragraph'    => 'Paragraph',
	'core/heading'      => 'Heading',
	'core/verse'        => 'Verse',
	'core/preformatted' => 'Preformatted',
	'core/list-item'    => 'List Item',
) );

/**
 * Default editable blocks (used before settings are saved).
 */
define( 'JFECT_DEFAULT_BLOCKS', array(
	'core/paragraph',
	'core/heading',
) );

/**
 * Default editable post types (used before settings are saved).
 */
define( 'JFECT_DEFAULT_POST_TYPES', array(
	'post',
	'page',
) );

/**
 * Get the list of editable block types from settings.
 */
function jfect_get_editable_blocks() {
	static $blocks = null;

	if ( $blocks !== null ) {
		return $blocks;
	}

	$saved = get_option( 'jfect_editable_blocks' );
	$blocks = is_array( $saved ) ? $saved : JFECT_DEFAULT_BLOCKS;
	return $blocks;
}

/**
 * Get the list of post types that support front-end editing.
 */
function jfect_get_editable_post_types() {
	static $post_types = null;

	if ( $post_types !== null ) {
		return $post_types;
	}

	$saved      = get_option( 'jfect_editable_post_types' );
	$post_types = is_array( $saved ) ? $saved : JFECT_DEFAULT_POST_TYPES;
	return $post_types;
}

/**
 * Post types eligible to be offered in the settings screen —
 * public, UI-visible post types.
 */
function jfect_get_available_post_types() {
	return get_post_types( array(
		'public'  => true,
		'show_ui' => true,
	), 'objects' );
}

/**
 * Settings page.
 */
/**
 * Hide "Original block deleted." message in the editor collaboration sidebar.
 */
add_action( 'enqueue_block_editor_assets', function () {
	wp_add_inline_style( 'wp-edit-post', '.editor-collab-sidebar-panel__skip-to-comment + p { display: none; }' );
} );

add_action( 'admin_menu', function () {
	add_options_page(
		"Jamie's Front-End Editor",
		'Front-End Editor',
		'manage_options',
		'jamies-front-end-editor-for-content-teams',
		'jfect_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'jfect_settings', 'jfect_editable_blocks', array(
		'type'              => 'array',
		'sanitize_callback' => 'jfect_sanitize_blocks',
		'default'           => JFECT_DEFAULT_BLOCKS,
	) );

	register_setting( 'jfect_settings', 'jfect_restricted_roles', array(
		'type'              => 'array',
		'sanitize_callback' => 'jfect_sanitize_roles',
		'default'           => array(),
	) );

	register_setting( 'jfect_settings', 'jfect_editable_post_types', array(
		'type'              => 'array',
		'sanitize_callback' => 'jfect_sanitize_post_types',
		'default'           => JFECT_DEFAULT_POST_TYPES,
	) );
} );

/**
 * Get roles that are restricted to frontend-only editing.
 */
function jfect_get_restricted_roles() {
	$saved = get_option( 'jfect_restricted_roles' );
	return is_array( $saved ) ? $saved : array();
}

function jfect_sanitize_roles( $input ) {
	if ( ! is_array( $input ) ) {
		return array();
	}

	$valid_roles = array_keys( wp_roles()->roles );
	return array_values( array_intersect( $input, $valid_roles ) );
}

/**
 * Check if the current user has a restricted role.
 */
function jfect_is_user_restricted() {
	if ( ! is_user_logged_in() ) {
		return false;
	}

	$restricted = jfect_get_restricted_roles();
	if ( empty( $restricted ) ) {
		return false;
	}

	$user = wp_get_current_user();
	return ! empty( array_intersect( $user->roles, $restricted ) );
}

/**
 * Redirect restricted users away from wp-admin.
 * Allows REST API, admin-ajax.php, and admin-post.php through.
 */
add_action( 'admin_init', function () {
	if ( ! jfect_is_user_restricted() ) {
		return;
	}

	// Allow AJAX requests.
	if ( wp_doing_ajax() ) {
		return;
	}

	// Allow admin-post.php (form handlers).
	if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && 'admin-post.php' === basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) ) ) {
		return;
	}

	// Allow the settings page itself for admins (they won't be restricted, but just in case).
	// For restricted users, redirect to the home page.
	wp_safe_redirect( home_url( '/' ) );
	exit;
}, 1 );

/**
 * Hide the admin bar and add body class for restricted users.
 */
add_action( 'init', function () {
	if ( jfect_is_user_restricted() ) {
		show_admin_bar( false );
	}
} );


function jfect_sanitize_blocks( $input ) {
	if ( ! is_array( $input ) ) {
		return JFECT_DEFAULT_BLOCKS;
	}

	return array_values( array_intersect( $input, array_keys( JFECT_SUPPORTED_BLOCKS ) ) );
}

function jfect_sanitize_post_types( $input ) {
	if ( ! is_array( $input ) ) {
		return JFECT_DEFAULT_POST_TYPES;
	}

	$valid_post_types = array_keys( jfect_get_available_post_types() );
	return array_values( array_intersect( $input, $valid_post_types ) );
}

function jfect_settings_page() {
	$editable        = jfect_get_editable_blocks();
	$restricted      = jfect_get_restricted_roles();
	$all_roles       = wp_roles()->roles;
	$editable_types  = jfect_get_editable_post_types();
	$available_types = jfect_get_available_post_types();
	?>
	<div class="wrap">
		<h1>Jamie's Front-End Editor for Content Teams</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'jfect_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__('Editable post types', 'jamies-front-end-editor-for-content-teams') ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;"><?php echo esc_html__('Which post types can be edited from the front end.', 'jamies-front-end-editor-for-content-teams') ?></p>
							<?php foreach ( $available_types as $post_type_slug => $post_type_obj ) : ?>
								<label style="display:block;margin-bottom:8px;">
									<input
										type="checkbox"
										name="jfect_editable_post_types[]"
										value="<?php echo esc_attr( $post_type_slug ); ?>"
										<?php checked( in_array( $post_type_slug, $editable_types, true ) ); ?>
									/>
									<?php echo esc_html( $post_type_obj->labels->singular_name ); ?>
									<code style="color:#666;margin-left:4px;"><?php echo esc_html( $post_type_slug ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Editable blocks', 'jamies-front-end-editor-for-content-teams') ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;"><?php echo esc_html__('Which block types can be edited from the front end.', 'jamies-front-end-editor-for-content-teams') ?></p>
							<?php foreach ( JFECT_SUPPORTED_BLOCKS as $block_name => $label ) : ?>
								<label style="display:block;margin-bottom:8px;">
									<input
										type="checkbox"
										name="jfect_editable_blocks[]"
										value="<?php echo esc_attr( $block_name ); ?>"
										<?php checked( in_array( $block_name, $editable, true ) ); ?>
									/>
									<?php echo esc_html( $label ); ?>
									<code style="color:#666;margin-left:4px;"><?php echo esc_html( $block_name ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Frontend-only roles', 'jamies-front-end-editor-for-content-teams') ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;"><?php echo esc_html__('These roles will be redirected away from wp-admin and can only edit via the front end. The admin bar will be hidden for them. REST API access is preserved so saves still work.', 'jamies-front-end-editor-for-content-teams') ?></p>
							<?php foreach ( $all_roles as $role_slug => $role_data ) : ?>
								<?php if ( $role_slug === 'administrator' ) continue; // Never restrict admins. ?>
								<label style="display:block;margin-bottom:8px;">
									<input
										type="checkbox"
										name="jfect_restricted_roles[]"
										value="<?php echo esc_attr( $role_slug ); ?>"
										<?php checked( in_array( $role_slug, $restricted, true ) ); ?>
									/>
									<?php echo esc_html( translate_user_role( $role_data['name'] ) ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Register script module and styles.
 */
add_action( 'init', function () {
	wp_register_script_module(
		'jamies-front-end-editor-for-content-teams/view',
		plugins_url( 'view.js', __FILE__ ),
		array( '@wordpress/interactivity' ),
		'1.0.0'
	);
} );

/**
 * Track whether we should render editing UI on this request.
 */
function jfect_should_render() {
	static $should = null;

	if ( $should !== null ) {
		return $should;
	}

	if ( ! is_singular() || ! is_user_logged_in() ) {
		$should = false;
		return false;
	}

	$post_id = get_queried_object_id();
	if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
		$should = false;
		return false;
	}

	if ( ! in_array( get_post_type( $post_id ), jfect_get_editable_post_types(), true ) ) {
		$should = false;
		return false;
	}

	$should = true;
	return true;
}

/**
 * Build a lookup of editable blocks from the post content.
 * Uses blockName + innerHTML as a signature, with a counter per signature
 * to handle duplicate blocks.
 *
 * Returns an array of [ signature => [ flat_index, flat_index, ... ] ]
 */
function jfect_get_post_block_map() {
	static $map = null;

	if ( $map !== null ) {
		return $map;
	}

	$map     = array();
	$post_id = get_queried_object_id();
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return $map;
	}

	$blocks = parse_blocks( $post->post_content );
	$flat   = array();
	jfect_flatten_blocks( $blocks, $flat );

	foreach ( $flat as $index => $entry ) {
		$block = $entry['block'];
		if ( ! in_array( $block['blockName'], jfect_get_editable_blocks(), true ) ) {
			continue;
		}

		$sig = $block['blockName'] . '::' . trim( $block['innerHTML'] );

		if ( ! isset( $map[ $sig ] ) ) {
			$map[ $sig ] = array();
		}
		$map[ $sig ][] = $index;
	}

	return $map;
}

/**
 * Enqueue assets and set Interactivity API state for authorized users.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! jfect_should_render() ) {
		return;
	}

	wp_enqueue_script_module( 'jamies-front-end-editor-for-content-teams/view' );
	wp_enqueue_style(
		'jamies-front-end-editor-for-content-teams',
		plugins_url( 'style.css', __FILE__ ),
		array(),
		'1.0.0'
	);

	wp_interactivity_state( 'jamies-front-end-editor-for-content-teams', array(
		'postId'    => get_queried_object_id(),
		'restNonce' => wp_create_nonce( 'wp_rest' ),
		'endpoint'  => rest_url( 'jfect/v1/update-block' ),
		'isEditing' => false,
		'isSaving'  => false,
		'message'   => '',
	) );
} );

/**
 * Use the render_block filter to wrap editable blocks.
 *
 * We match each rendered block against the pre-built map of the post's
 * own blocks using blockName + innerHTML as the key. This correctly
 * handles nested blocks (inside covers, groups, columns, etc.) and
 * ignores template blocks (header, footer, nav) that aren't in the post.
 */
add_filter( 'render_block', function ( $block_content, $block ) {
	if ( ! jfect_should_render() ) {
		return $block_content;
	}

	if ( ! in_array( $block['blockName'], jfect_get_editable_blocks(), true ) ) {
		return $block_content;
	}

	$map = jfect_get_post_block_map();
	$sig = $block['blockName'] . '::' . trim( $block['innerHTML'] );

	if ( ! isset( $map[ $sig ] ) || empty( $map[ $sig ] ) ) {
		// This block isn't from the post content (it's from the template).
		return $block_content;
	}

	// Take the next available flat index for this signature (handles duplicates).
	$flat_index = array_shift( $map[ $sig ] );

	$context = esc_attr( wp_json_encode( array( 'blockIndex' => $flat_index ) ) );

	// $context is escaped above with esc_attr(); $block_content is WordPress core's
	// already-rendered block output for this block and must be passed through as-is.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return sprintf(
		'<div class="fie-block" data-wp-interactive="jamies-front-end-editor-for-content-teams" data-wp-context=\'%s\' data-wp-on--click="actions.editBlock" data-wp-class--fie-active="context.active" data-wp-watch="callbacks.syncEditable">%s</div>',
		$context,
		$block_content
	);
}, 10, 2 );

/**
 * Add an "Edit with Frontend" link to the WordPress admin bar when viewing
 * a singular post/page that has at least one editable block on it.
 */
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
	if ( ! jfect_should_render() ) {
		return;
	}

	$map = jfect_get_post_block_map();
	if ( empty( $map ) ) {
		return;
	}

	$wp_admin_bar->add_node( array(
		'id'    => 'jfect-edit-frontend',
		'title' => esc_html__( 'Edit with Frontend Editor', 'jamies-front-end-editor-for-content-teams' ),
		'href'  => '#',
		'meta'  => array(
			'class' => 'jfect-edit-frontend-toolbar-item',
			'title' => esc_attr__( 'Jump into front-end editing', 'jamies-front-end-editor-for-content-teams' ),
		),
	) );
}, 100 );

/**
 * Add the toolbar to wp_footer.
 */
add_action( 'wp_footer', function () {
	if ( ! jfect_should_render() ) {
		return;
	}
	?>
	<div data-wp-interactive="jamies-front-end-editor-for-content-teams" class="fie-toolbar-wrap">
		<div class="fie-toolbar" data-wp-bind--hidden="!state.isEditing">
			<div class="fie-toolbar-inner">
				<span class="fie-toolbar-label"><?php echo esc_html__( 'Editing', 'jamies-front-end-editor-for-content-teams' ); ?></span>
				<button class="fie-btn fie-btn-save" data-wp-on--click="actions.save" data-wp-bind--disabled="state.isSaving"><?php echo esc_html__( 'Save', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				<button class="fie-btn fie-btn-cancel" data-wp-on--click="actions.cancel" data-wp-bind--disabled="state.isSaving"><?php echo esc_html__( 'Cancel', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				<span class="fie-message" data-wp-text="state.message"></span>
			</div>
		</div>
	</div>
	<?php
} );

/**
 * Show a user bar with logout link for restricted users on every page.
 */
add_action( 'wp_footer', function () {
	if ( ! jfect_is_user_restricted() ) {
		return;
	}

	// Enqueue styles even on non-singular pages.
	wp_enqueue_style(
		'jamies-front-end-editor-for-content-teams',
		plugins_url( 'style.css', __FILE__ ),
		array(),
		'1.0.0'
	);
	?>
	<details class="fie-user-fab">
		<summary class="fie-fab-toggle" title="Account">&#9881;</summary>
		<div class="fie-fab-menu">
			<span class="fie-fab-greeting"><?php echo esc_html__( 'Hi, ', 'jamies-front-end-editor-for-content-teams' ); ?><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="fie-fab-logout"><?php echo esc_html__( 'Log out', 'jamies-front-end-editor-for-content-teams' ); ?></a>
		</div>
	</details>
	<?php
} );

/**
 * REST endpoint: receives a block index and new inner HTML,
 * parses the post's raw block content, updates that block, and saves.
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'jfect/v1', '/update-block', array(
		'methods'             => 'POST',
		'callback'            => 'jfect_update_block',
		'permission_callback' => function ( $request ) {
			$post_id = absint( $request->get_param( 'postId' ) );
			return $post_id
				&& current_user_can( 'edit_post', $post_id )
				&& in_array( get_post_type( $post_id ), jfect_get_editable_post_types(), true );
		},
		'args'                => array(
			'postId'     => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'blockIndex' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'newContent' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			),
		),
	) );
} );

/**
 * Handle the block update.
 */
function jfect_update_block( $request ) {
	$post_id     = $request->get_param( 'postId' );
	$block_index = $request->get_param( 'blockIndex' );
	$new_content = $request->get_param( 'newContent' );

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	$blocks = parse_blocks( $post->post_content );

	$flat = array();
	jfect_flatten_blocks( $blocks, $flat );

	if ( ! isset( $flat[ $block_index ] ) ) {
		return new WP_Error( 'invalid_block', 'Block index out of range.', array( 'status' => 400 ) );
	}

	$target = $flat[ $block_index ];

	if ( ! in_array( $target['block']['blockName'], jfect_get_editable_blocks(), true ) ) {
		return new WP_Error( 'not_editable', 'This block type is not editable.', array( 'status' => 400 ) );
	}

	// The block's innerHTML contains the full inner markup, e.g.:
	//   <p class="...">Old text</p>
	// We need to replace only the text content inside the tag, preserving
	// the opening and closing tags with their attributes.
	$old_inner = $target['ref']['innerHTML'];
	$trimmed   = trim( $old_inner );

	if ( preg_match( '/^(<[^>]+>)(.*?)(<\/[a-z0-9]+>)$/s', $trimmed, $m ) ) {
		// Has a wrapping tag — preserve it, replace only the inner text.
		$new_inner = "\n" . $m[1] . $new_content . $m[3] . "\n";
	} else {
		// No wrapping tag (plain text innerHTML) — replace directly.
		$new_inner = "\n" . $new_content . "\n";
	}

	// Extract old text for the edit log.
	if ( preg_match( '/^(<[^>]+>)(.*?)(<\/[a-z0-9]+>)$/s', $trimmed, $old_m ) ) {
		$old_text = $old_m[2];
	} else {
		$old_text = $trimmed;
	}

	$target['ref']['innerHTML']    = $new_inner;
	$target['ref']['innerContent'] = array( $new_inner );

	// Serialize blocks back to post content.
	$updated_content = serialize_blocks( $blocks );

	$result = wp_update_post( array(
		'ID'           => $post_id,
		'post_content' => $updated_content,
	), true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Record the edit as a native WordPress block note.
	$user       = wp_get_current_user();
	$block_type = str_replace( 'core/', '', $target['block']['blockName'] );
	$old_short  = mb_strimwidth( wp_strip_all_tags( $old_text ), 0, 80, '…' );
	$new_short  = mb_strimwidth( wp_strip_all_tags( $new_content ), 0, 80, '…' );

	$note_content = sprintf(
		'Edited %s from front end: "%s" → "%s"',
		$block_type,
		$old_short,
		$new_short
	);

	wp_insert_comment( array(
		'comment_post_ID'      => $post_id,
		'comment_content'      => $note_content,
		'comment_type'         => 'note',
		'user_id'              => $user->ID,
		'comment_author'       => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_approved'     => 1,
		'comment_parent'       => 0,
	) );

	return array( 'success' => true );
}

/**
 * Flatten nested blocks into an ordered list matching render_block's traversal.
 * Each entry holds a reference to the block in the original tree so we can modify it.
 */
function jfect_flatten_blocks( &$blocks, &$flat ) {
	foreach ( $blocks as &$block ) {
		$flat[] = array(
			'block' => $block,
			'ref'   => &$block,
		);
		if ( ! empty( $block['innerBlocks'] ) ) {
			jfect_flatten_blocks( $block['innerBlocks'], $flat );
		}
	}
}

