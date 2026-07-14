<?php
/**
 * Plugin Name: Jamie's Front-End Editor for Content Teams
 * Description: Simple front-end text editing for your team. Click any text block to edit it on the live site — block markup is preserved.
 * Version: 0.4
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
 * CSS classes that lock a block (and everything inside it) from front-end editing.
 * Users add one of these via a block's Advanced > Additional CSS class(es).
 */
define( 'JFECT_LOCK_CLASSES', array( 'fie-no-edit', 'fie-lock' ) );

/**
 * Post meta key that locks an entire page/post from front-end editing.
 */
define( 'JFECT_PAGE_LOCK_META', '_jfect_lock_page' );

/**
 * Whether a parsed block is locked from front-end editing.
 *
 * Two ways to lock a block:
 *   1. WordPress's native block lock (the padlock: Options > Lock). Any lock
 *      restriction (move and/or remove) marks the block as read-only here.
 *   2. Adding one of the JFECT_LOCK_CLASSES CSS classes in the Advanced panel.
 *
 * Either way, the lock also applies to everything nested inside the block
 * (handled by ancestry propagation in jfect_flatten_blocks()).
 */
function jfect_block_is_locked( $block ) {
	// 1. Native block lock attribute, e.g. {"lock":{"move":true,"remove":true}}.
	if ( isset( $block['attrs']['lock'] ) && is_array( $block['attrs']['lock'] ) ) {
		foreach ( $block['attrs']['lock'] as $value ) {
			if ( $value ) {
				return true;
			}
		}
	}

	// 2. CSS class marker.
	$class = isset( $block['attrs']['className'] ) ? $block['attrs']['className'] : '';
	if ( '' !== $class ) {
		$classes = preg_split( '/\s+/', $class );
		foreach ( JFECT_LOCK_CLASSES as $lock ) {
			if ( in_array( $lock, $classes, true ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Whether an entire post is locked from front-end editing.
 */
function jfect_is_page_locked( $post_id ) {
	return (bool) get_post_meta( $post_id, JFECT_PAGE_LOCK_META, true );
}

/**
 * Get the list of editable block types from settings.
 *
 * Combines the built-in supported blocks the user has enabled with any extra
 * (non-core) block types they've opted into under the Advanced setting.
 */
function jfect_get_editable_blocks() {
	static $blocks = null;

	if ( $blocks !== null ) {
		return $blocks;
	}

	$saved = get_option( 'jfect_editable_blocks' );
	$core  = is_array( $saved ) ? $saved : JFECT_DEFAULT_BLOCKS;
	$extra = jfect_get_extra_blocks();

	$blocks = array_values( array_unique( array_merge( $core, $extra ) ) );
	return $blocks;
}

/**
 * Extra, user-enabled block types beyond the built-in supported set.
 */
function jfect_get_extra_blocks() {
	$saved = get_option( 'jfect_extra_blocks' );
	return is_array( $saved ) ? $saved : array();
}

/**
 * Sanitize the extra blocks list: only allow currently-registered block types,
 * and never the ones already offered in the primary supported list.
 */
function jfect_sanitize_extra_blocks( $input ) {
	if ( ! is_array( $input ) ) {
		return array();
	}

	$registered = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
	$supported  = array_keys( JFECT_SUPPORTED_BLOCKS );

	$clean = array();
	foreach ( $input as $name ) {
		$name = sanitize_text_field( $name );
		if ( in_array( $name, $registered, true ) && ! in_array( $name, $supported, true ) ) {
			$clean[] = $name;
		}
	}

	return array_values( array_unique( $clean ) );
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

	register_setting( 'jfect_settings', 'jfect_extra_blocks', array(
		'type'              => 'array',
		'sanitize_callback' => 'jfect_sanitize_extra_blocks',
		'default'           => array(),
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

function jfect_settings_page() {
	$editable    = jfect_get_editable_blocks();
	$restricted  = jfect_get_restricted_roles();
	$all_roles   = wp_roles()->roles;
	$extra_saved = jfect_get_extra_blocks();

	// Other registered block types, excluding the ones already offered above.
	$other_blocks = array();
	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
		if ( isset( JFECT_SUPPORTED_BLOCKS[ $name ] ) ) {
			continue;
		}
		$title                 = ( isset( $type->title ) && $type->title ) ? $type->title : $name;
		$other_blocks[ $name ] = $title;
	}
	asort( $other_blocks );
	?>
	<div class="wrap">
		<h1>Jamie's Front-End Editor for Content Teams</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'jfect_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Editable blocks</th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;">Which block types can be edited from the front end.</p>
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
					<th scope="row">Other blocks (advanced)</th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;max-width:640px;">
								Enable front-end editing for other block types on your site, such as blocks from your theme or other plugins. <strong>At your own risk:</strong> this works best for blocks that show their text in a normal heading or paragraph tag. Blocks that store their text differently may not save correctly. Editing simply does nothing on blocks that aren&rsquo;t compatible, so nothing will break.
							</p>
							<div style="max-height:260px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;padding:10px;background:#fff;max-width:640px;">
								<?php if ( empty( $other_blocks ) ) : ?>
									<p style="margin:0;color:#666;">No other block types are registered on this site.</p>
								<?php else : ?>
									<?php foreach ( $other_blocks as $block_name => $label ) : ?>
										<label style="display:block;margin-bottom:8px;">
											<input
												type="checkbox"
												name="jfect_extra_blocks[]"
												value="<?php echo esc_attr( $block_name ); ?>"
												<?php checked( in_array( $block_name, $extra_saved, true ) ); ?>
											/>
											<?php echo esc_html( $label ); ?>
											<code style="color:#666;margin-left:4px;"><?php echo esc_html( $block_name ); ?></code>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row">Frontend-only roles</th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;">These roles will be redirected away from wp-admin and can only edit via the front end. The admin bar will be hidden for them. REST API access is preserved so saves still work.</p>
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

		<hr />
		<h2>Locking content</h2>
		<p class="description" style="max-width:640px;">
			You can stop your team editing certain things:
		</p>
		<ul style="list-style:disc;margin-left:20px;max-width:640px;">
			<li><strong>Lock a whole page:</strong> open the page in the editor and tick <em>Lock this page</em> in the &ldquo;Front-End Editing&rdquo; box (right-hand sidebar).</li>
			<li><strong>Lock one block or section:</strong> select the block (for example a Group that wraps a section), open its <em>Options</em> menu (the three dots) and choose <em>Lock</em>. That block and everything inside it becomes read-only on the front end. You can also add the CSS class <code>fie-no-edit</code> in the Advanced panel instead.</li>
		</ul>
	</div>
	<?php
}

/**
 * Register the page-lock post meta (protected; edited via the meta box below).
 */
add_action( 'init', function () {
	register_meta( 'post', JFECT_PAGE_LOCK_META, array(
		'type'          => 'boolean',
		'single'        => true,
		'show_in_rest'  => false,
		'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		},
	) );
} );

/**
 * Add a "Front-End Editing" meta box with the page lock toggle.
 */
add_action( 'add_meta_boxes', function () {
	foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
		if ( 'attachment' === $post_type ) {
			continue;
		}
		add_meta_box(
			'jfect_lock_box',
			"Front-End Editing",
			'jfect_render_lock_box',
			$post_type,
			'side',
			'default'
		);
	}
} );

/**
 * Render the page-lock meta box.
 */
function jfect_render_lock_box( $post ) {
	wp_nonce_field( 'jfect_lock_save', 'jfect_lock_nonce' );
	$locked = jfect_is_page_locked( $post->ID );
	?>
	<label style="display:block;margin-bottom:8px;">
		<input type="checkbox" name="jfect_lock_page" value="1" <?php checked( $locked ); ?> />
		Lock this page (disable front-end editing)
	</label>
	<p class="description">
		<?php
		// Simple help text; only the <code> tag needs to survive.
		echo wp_kses( 'To lock just one section instead, use the block&rsquo;s <strong>Lock</strong> option (the padlock in its Options menu), or add the CSS class <code>fie-no-edit</code> to it. The lock also covers everything nested inside that block.', array( 'code' => array(), 'strong' => array() ) );
		?>
	</p>
	<?php
}

/**
 * Save the page-lock meta box.
 */
add_action( 'save_post', function ( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	// Only act when our meta box was actually submitted (keeps REST/autosaves from clearing it).
	if ( ! isset( $_POST['jfect_lock_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['jfect_lock_nonce'] ) ), 'jfect_lock_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! empty( $_POST['jfect_lock_page'] ) ) {
		update_post_meta( $post_id, JFECT_PAGE_LOCK_META, 1 );
	} else {
		delete_post_meta( $post_id, JFECT_PAGE_LOCK_META );
	}
} );

/**
 * Register script module and styles.
 */
add_action( 'init', function () {
	wp_register_script_module(
		'jamies-front-end-editor-for-content-teams/view',
		plugins_url( 'view.js', __FILE__ ),
		array( '@wordpress/interactivity' ),
		'1.2.0'
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

	// Whole page locked from front-end editing.
	if ( jfect_is_page_locked( $post_id ) ) {
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

		// Skip blocks locked directly or by a locked ancestor container.
		if ( ! empty( $entry['locked'] ) ) {
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

	$post_id = get_queried_object_id();

	// Is someone else already editing this post (front end or back end)?
	require_once ABSPATH . 'wp-admin/includes/post.php';
	$lock_user  = wp_check_post_lock( $post_id );
	$locked_by  = $lock_user ? get_the_author_meta( 'display_name', $lock_user ) : '';

	wp_interactivity_state( 'jamies-front-end-editor-for-content-teams', array(
		'postId'         => $post_id,
		'restNonce'      => wp_create_nonce( 'wp_rest' ),
		'endpoint'       => rest_url( 'jfect/v1/update-block' ),
		'lockEndpoint'   => rest_url( 'jfect/v1/lock' ),
		'unlockEndpoint' => rest_url( 'jfect/v1/unlock' ),
		'lockedByName'   => $locked_by,
		'isEditing'      => false,
		'isSaving'       => false,
		'message'        => '',
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

	// A fingerprint of the block's stored markup at render time, used to detect
	// concurrent edits: if the stored content changes before this client saves,
	// the fingerprints won't match and the save is rejected instead of clobbering.
	$base_hash = md5( trim( $block['innerHTML'] ) );

	$context = esc_attr( wp_json_encode( array(
		'blockIndex' => $flat_index,
		'base'       => $base_hash,
	) ) );

	// $context is escaped above with esc_attr(); $block_content is WordPress core's
	// already-rendered block output for this block and must be passed through as-is.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	return sprintf(
		'<div class="fie-block" data-wp-interactive="jamies-front-end-editor-for-content-teams" data-wp-context=\'%s\' data-wp-on--click="actions.editBlock" data-wp-on--keydown="actions.onKeydown" data-wp-class--fie-active="context.active" data-wp-watch="callbacks.syncEditable">%s</div>',
		$context,
		$block_content
	);
}, 10, 2 );

/**
 * Add the toolbar to wp_footer.
 */
add_action( 'wp_footer', function () {
	if ( ! jfect_should_render() ) {
		return;
	}
	?>
	<div data-wp-interactive="jamies-front-end-editor-for-content-teams" class="fie-toolbar-wrap">
		<div class="fie-lock-banner" data-wp-bind--hidden="!state.lockedByName">
			<strong data-wp-text="state.lockedByName"></strong> is currently editing this page &mdash; it&rsquo;s read-only until they&rsquo;re done.
		</div>
		<div class="fie-toolbar" data-wp-bind--hidden="!state.isEditing">
			<div class="fie-toolbar-inner">
				<span class="fie-toolbar-label">Editing</span>
				<button class="fie-btn fie-btn-save" data-wp-on--click="actions.save" data-wp-bind--disabled="state.isSaving">Save</button>
				<button class="fie-btn fie-btn-cancel" data-wp-on--click="actions.cancel" data-wp-bind--disabled="state.isSaving">Cancel</button>
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
			<span class="fie-fab-greeting">Hi, <?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="fie-fab-logout">Log out</a>
		</div>
	</details>
	<?php
} );

/**
 * REST endpoint: receives a block index and new inner HTML,
 * parses the post's raw block content, updates that block, and saves.
 */
add_action( 'rest_api_init', function () {
	$can_edit = function ( $request ) {
		$post_id = absint( $request->get_param( 'postId' ) );
		return $post_id && current_user_can( 'edit_post', $post_id );
	};

	register_rest_route( 'jfect/v1', '/update-block', array(
		'methods'             => 'POST',
		'callback'            => 'jfect_update_block',
		'permission_callback' => $can_edit,
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
			'baseHash'   => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );

	// Acquire or refresh the edit lock for this post.
	register_rest_route( 'jfect/v1', '/lock', array(
		'methods'             => 'POST',
		'callback'            => 'jfect_acquire_lock',
		'permission_callback' => $can_edit,
		'args'                => array(
			'postId' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );

	// Release the edit lock for this post (if held by the current user).
	register_rest_route( 'jfect/v1', '/unlock', array(
		'methods'             => 'POST',
		'callback'            => 'jfect_release_lock',
		'permission_callback' => $can_edit,
		'args'                => array(
			'postId' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		),
	) );
} );

/**
 * Acquire or refresh the WordPress post-edit lock from the front end.
 * Refuses if another user currently holds the lock.
 */
function jfect_acquire_lock( $request ) {
	$post_id = $request->get_param( 'postId' );

	require_once ABSPATH . 'wp-admin/includes/post.php';

	$lock_user = wp_check_post_lock( $post_id );
	if ( $lock_user ) {
		$name = get_the_author_meta( 'display_name', $lock_user );
		return new WP_Error(
			'locked',
			$name,
			array(
				'status'     => 423,
				'lockedBy'   => $name,
			)
		);
	}

	// Sets/refreshes the lock to the current user.
	wp_set_post_lock( $post_id );

	return array( 'success' => true );
}

/**
 * Release the post-edit lock, but only if the current user is the holder.
 */
function jfect_release_lock( $request ) {
	$post_id = $request->get_param( 'postId' );

	$lock = get_post_meta( $post_id, '_edit_lock', true );
	if ( $lock ) {
		$parts     = explode( ':', $lock );
		$lock_user = isset( $parts[1] ) ? (int) $parts[1] : 0;
		if ( $lock_user === get_current_user_id() ) {
			delete_post_meta( $post_id, '_edit_lock' );
		}
	}

	return array( 'success' => true );
}

/**
 * Handle the block update.
 */
function jfect_update_block( $request ) {
	$post_id     = $request->get_param( 'postId' );
	$block_index = $request->get_param( 'blockIndex' );
	$new_content = $request->get_param( 'newContent' );
	$base_hash   = $request->get_param( 'baseHash' );

	// contentEditable can wrap new lines in <div>/<p>, which are invalid inside a
	// paragraph or heading and break block validation. Flatten them to <br>.
	$new_content = preg_replace( '#<div>\s*<br\s*/?>\s*</div>#i', '<br>', $new_content );
	$new_content = preg_replace( '#<div[^>]*>#i', '<br>', $new_content );
	$new_content = str_ireplace( '</div>', '', $new_content );
	$new_content = preg_replace( '#<p[^>]*>#i', '<br>', $new_content );
	$new_content = str_ireplace( '</p>', '', $new_content );
	$new_content = preg_replace( '#^(?:\s*<br\s*/?>\s*)+#i', '', $new_content );
	$new_content = preg_replace( '#(?:\s*<br\s*/?>\s*)+$#i', '', $new_content );
	$new_content = trim( $new_content );

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
	}

	// Whole page locked from front-end editing.
	if ( jfect_is_page_locked( $post_id ) ) {
		return new WP_Error( 'page_locked', 'Front-end editing is locked for this page.', array( 'status' => 403 ) );
	}

	// Someone else is holding the edit lock (front end or back end).
	require_once ABSPATH . 'wp-admin/includes/post.php';
	$lock_user = wp_check_post_lock( $post_id );
	if ( $lock_user ) {
		$name = get_the_author_meta( 'display_name', $lock_user );
		return new WP_Error(
			'locked',
			sprintf( '%s is currently editing this page. Reload to see the latest.', $name ),
			array( 'status' => 423 )
		);
	}

	$blocks = parse_blocks( $post->post_content );

	$flat = array();
	jfect_flatten_blocks( $blocks, $flat );

	if ( ! isset( $flat[ $block_index ] ) ) {
		return new WP_Error( 'invalid_block', 'Block index out of range.', array( 'status' => 400 ) );
	}

	$target = $flat[ $block_index ];

	// Block (or a container around it) is locked from front-end editing.
	if ( ! empty( $target['locked'] ) ) {
		return new WP_Error( 'block_locked', 'This section is locked from front-end editing.', array( 'status' => 403 ) );
	}

	if ( ! in_array( $target['block']['blockName'], jfect_get_editable_blocks(), true ) ) {
		return new WP_Error( 'not_editable', 'This block type is not editable.', array( 'status' => 400 ) );
	}

	// Concurrent-edit guard: if the stored block changed since this client loaded
	// the page, reject the save instead of overwriting the other person's edit.
	if ( $base_hash && md5( trim( $target['ref']['innerHTML'] ) ) !== $base_hash ) {
		return new WP_Error(
			'conflict',
			'This content was just changed by someone else. Reload the page to see the latest version.',
			array( 'status' => 409 )
		);
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
 * Each entry holds a reference to the block in the original tree so we can modify it,
 * plus a 'locked' flag that is inherited from any locked ancestor (or the block itself).
 */
function jfect_flatten_blocks( &$blocks, &$flat, $parent_locked = false ) {
	foreach ( $blocks as &$block ) {
		$locked = $parent_locked || jfect_block_is_locked( $block );
		$flat[] = array(
			'block'  => $block,
			'ref'    => &$block,
			'locked' => $locked,
		);
		if ( ! empty( $block['innerBlocks'] ) ) {
			jfect_flatten_blocks( $block['innerBlocks'], $flat, $locked );
		}
	}
}

