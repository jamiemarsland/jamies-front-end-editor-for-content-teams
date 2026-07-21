<?php
/**
 * Plugin Name: Jamie's Front-End Editor for Content Teams
 * Description: Front-end editing for your content team. Edit text, links, buttons and images on the live page, and see every change across your site in one place. No wp-admin needed, and block markup is preserved.
 * Version: 0.8
 * Requires at least: 6.5
 * Requires PHP: 8.0
 * Author: Jamie Marsland
 * Author URI: https://profiles.wordpress.org/jamiemarsland/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jamies-front-end-editor-for-content-teams
 *
 * @package Jamies_Front_End_Editor_For_Content_Teams
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The central "Site changes" audit screen (Tools -> Site changes).
require_once __DIR__ . '/site-changes.php';

// Repo-only demo-data seeder. This file is excluded from the WordPress.org zip,
// so it only loads on local checkouts (e.g. `pressship demo`), never in a
// shipped release.
if ( is_readable( __DIR__ . '/demo/demo-seeder.php' ) ) {
	require_once __DIR__ . '/demo/demo-seeder.php';
}

/**
 * All block types that can be offered as editable.
 */
define(
	'JFECT_SUPPORTED_BLOCKS',
	array(
		'core/paragraph'    => 'Paragraph',
		'core/heading'      => 'Heading',
		'core/verse'        => 'Verse',
		'core/preformatted' => 'Preformatted',
		'core/list-item'    => 'List Item',
	)
);

/**
 * Default editable blocks (used before settings are saved).
 */
define(
	'JFECT_DEFAULT_BLOCKS',
	array(
		'core/paragraph',
		'core/heading',
	)
);

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
 *
 * @param array $block Parsed block array, as returned by parse_blocks().
 * @return bool
 */
function jfect_block_is_locked( $block ) {
	// 1. Native block lock attribute: any lock restriction (move or remove) set to true.
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
 *
 * @param int $post_id Post ID.
 * @return bool
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

	if ( null !== $blocks ) {
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
 *
 * @param mixed $input Raw setting value submitted from the settings form.
 * @return string[]
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
add_action(
	'enqueue_block_editor_assets',
	function () {
		wp_add_inline_style( 'wp-edit-post', '.editor-collab-sidebar-panel__skip-to-comment + p { display: none; }' );
	}
);

add_action(
	'admin_menu',
	function () {
		add_options_page(
			__( "Jamie's Front-End Editor", 'jamies-front-end-editor-for-content-teams' ),
			__( 'Front-End Editor', 'jamies-front-end-editor-for-content-teams' ),
			'manage_options',
			'jamies-front-end-editor-for-content-teams',
			'jfect_settings_page'
		);
	}
);

/**
 * Load the block-picker assets on our settings page only.
 */
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( 'settings_page_jamies-front-end-editor-for-content-teams' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'jfect-admin',
			plugins_url( 'admin.css', __FILE__ ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'jfect-admin',
			plugins_url( 'admin.js', __FILE__ ),
			array(),
			'1.0.0',
			true
		);

		// Candidate blocks for the picker: everything registered except the primary set.
		$candidates = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( isset( JFECT_SUPPORTED_BLOCKS[ $name ] ) ) {
				continue;
			}
			$title        = ( isset( $type->title ) && $type->title ) ? $type->title : $name;
			$candidates[] = array(
				'name'  => $name,
				'title' => $title,
			);
		}
		usort(
			$candidates,
			function ( $a, $b ) {
				return strcasecmp( $a['title'], $b['title'] );
			}
		);

		wp_localize_script(
			'jfect-admin',
			'jfectPicker',
			array(
				'blocks'     => $candidates,
				'noneFound'  => __( 'No matching blocks', 'jamies-front-end-editor-for-content-teams' ),
				'removeText' => __( 'Remove', 'jamies-front-end-editor-for-content-teams' ),
			)
		);
	}
);

add_action(
	'admin_init',
	function () {
		register_setting(
			'jfect_settings',
			'jfect_editable_blocks',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'jfect_sanitize_blocks',
				'default'           => JFECT_DEFAULT_BLOCKS,
			)
		);

		register_setting(
			'jfect_settings',
			'jfect_restricted_roles',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'jfect_sanitize_roles',
				'default'           => array(),
			)
		);

		register_setting(
			'jfect_settings',
			'jfect_extra_blocks',
			array(
				'type'              => 'array',
				'sanitize_callback' => 'jfect_sanitize_extra_blocks',
				'default'           => array(),
			)
		);
	}
);

/**
 * Get roles that are restricted to frontend-only editing.
 */
function jfect_get_restricted_roles() {
	$saved = get_option( 'jfect_restricted_roles' );
	return is_array( $saved ) ? $saved : array();
}

/**
 * Sanitize the restricted-roles setting to currently valid WordPress roles.
 *
 * @param mixed $input Raw setting value submitted from the settings form.
 * @return string[]
 */
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
add_action(
	'admin_init',
	function () {
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
	},
	1
);

/**
 * Hide the admin bar and add body class for restricted users.
 */
add_action(
	'init',
	function () {
		if ( jfect_is_user_restricted() ) {
			show_admin_bar( false );
		}
	}
);


/**
 * Sanitize the editable-blocks setting to the built-in supported block list.
 *
 * @param mixed $input Raw setting value submitted from the settings form.
 * @return string[]
 */
function jfect_sanitize_blocks( $input ) {
	if ( ! is_array( $input ) ) {
		return JFECT_DEFAULT_BLOCKS;
	}

	return array_values( array_intersect( $input, array_keys( JFECT_SUPPORTED_BLOCKS ) ) );
}

/**
 * Render the plugin's Settings > Front-End Editor admin page.
 */
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

	// Translated display labels for the built-in supported blocks (keyed the
	// same as JFECT_SUPPORTED_BLOCKS). i18n functions require literal strings,
	// so this can't be built from the constant's values directly.
	$block_labels = array(
		'core/paragraph'    => __( 'Paragraph', 'jamies-front-end-editor-for-content-teams' ),
		'core/heading'      => __( 'Heading', 'jamies-front-end-editor-for-content-teams' ),
		'core/verse'        => __( 'Verse', 'jamies-front-end-editor-for-content-teams' ),
		'core/preformatted' => __( 'Preformatted', 'jamies-front-end-editor-for-content-teams' ),
		'core/list-item'    => __( 'List Item', 'jamies-front-end-editor-for-content-teams' ),
	);
	?>
	<div class="wrap">
		<h1><?php esc_html_e( "Jamie's Front-End Editor for Content Teams", 'jamies-front-end-editor-for-content-teams' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'jfect_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Editable blocks', 'jamies-front-end-editor-for-content-teams' ); ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'Which block types can be edited from the front end.', 'jamies-front-end-editor-for-content-teams' ); ?></p>
							<?php foreach ( JFECT_SUPPORTED_BLOCKS as $block_name => $label ) : ?>
								<label style="display:block;margin-bottom:8px;">
									<input
										type="checkbox"
										name="jfect_editable_blocks[]"
										value="<?php echo esc_attr( $block_name ); ?>"
										<?php checked( in_array( $block_name, $editable, true ) ); ?>
									/>
									<?php echo esc_html( isset( $block_labels[ $block_name ] ) ? $block_labels[ $block_name ] : $label ); ?>
									<code style="color:#666;margin-left:4px;"><?php echo esc_html( $block_name ); ?></code>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Other blocks (advanced)', 'jamies-front-end-editor-for-content-teams' ); ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;max-width:640px;">
								<?php
								echo wp_kses(
									sprintf(
										/* translators: %s: bolded "At your own risk:" warning label. */
										__( 'Enable front-end editing for other block types on your site, such as blocks from your theme or other plugins. %s this works best for blocks that show their text in a normal heading or paragraph tag. Blocks that store their text differently may not save correctly. Editing simply does nothing on blocks that aren&rsquo;t compatible, so nothing will break.', 'jamies-front-end-editor-for-content-teams' ),
										'<strong>' . esc_html__( 'At your own risk:', 'jamies-front-end-editor-for-content-teams' ) . '</strong>'
									),
									array( 'strong' => array() )
								);
								?>
							</p>
							<div class="jfect-block-picker">
								<div class="jfect-chips" id="jfect-chips">
									<?php foreach ( $extra_saved as $block_name ) : ?>
										<?php $label = isset( $other_blocks[ $block_name ] ) ? $other_blocks[ $block_name ] : $block_name; ?>
										<span class="jfect-chip">
											<span class="jfect-chip-label"><?php echo esc_html( $label ); ?></span>
											<code><?php echo esc_html( $block_name ); ?></code>
											<button type="button" class="jfect-chip-remove" aria-label="<?php esc_attr_e( 'Remove', 'jamies-front-end-editor-for-content-teams' ); ?>">&times;</button>
											<input type="hidden" name="jfect_extra_blocks[]" value="<?php echo esc_attr( $block_name ); ?>" />
										</span>
									<?php endforeach; ?>
								</div>
								<input type="text" id="jfect-block-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search blocks to enable (for example, Kadence)&hellip;', 'jamies-front-end-editor-for-content-teams' ); ?>" autocomplete="off" />
								<ul class="jfect-suggestions" id="jfect-suggestions" hidden></ul>
							</div>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Frontend-only roles', 'jamies-front-end-editor-for-content-teams' ); ?></th>
					<td>
						<fieldset>
							<p class="description" style="margin-bottom:10px;"><?php esc_html_e( 'These roles will be redirected away from wp-admin and can only edit via the front end. The admin bar will be hidden for them. REST API access is preserved so saves still work.', 'jamies-front-end-editor-for-content-teams' ); ?></p>
							<?php foreach ( $all_roles as $role_slug => $role_data ) : ?>
								<?php
								// Never restrict admins.
								if ( 'administrator' === $role_slug ) {
									continue;
								}
								?>
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
		<h2><?php esc_html_e( 'Locking content', 'jamies-front-end-editor-for-content-teams' ); ?></h2>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'You can stop your team editing certain things:', 'jamies-front-end-editor-for-content-teams' ); ?>
		</p>
		<ul style="list-style:disc;margin-left:20px;max-width:640px;">
			<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: bolded "Lock a whole page:" label, 2: italicized "Lock this page" checkbox name. */
					__( '%1$s open the page in the editor and tick %2$s in the &ldquo;Front-End Editing&rdquo; box (right-hand sidebar).', 'jamies-front-end-editor-for-content-teams' ),
					'<strong>' . esc_html__( 'Lock a whole page:', 'jamies-front-end-editor-for-content-teams' ) . '</strong>',
					'<em>' . esc_html__( 'Lock this page', 'jamies-front-end-editor-for-content-teams' ) . '</em>'
				),
				array(
					'strong' => array(),
					'em'     => array(),
				)
			);
			?>
			</li>
			<li>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: 1: bolded "Lock one block or section:" label, 2: italicized "Options" menu name, 3: italicized "Lock" option name, 4: the "fie-no-edit" CSS class name. */
					__( '%1$s select the block (for example a Group that wraps a section), open its %2$s menu (the three dots) and choose %3$s. That block and everything inside it becomes read-only on the front end. You can also add the CSS class %4$s in the Advanced panel instead.', 'jamies-front-end-editor-for-content-teams' ),
					'<strong>' . esc_html__( 'Lock one block or section:', 'jamies-front-end-editor-for-content-teams' ) . '</strong>',
					'<em>' . esc_html__( 'Options', 'jamies-front-end-editor-for-content-teams' ) . '</em>',
					'<em>' . esc_html__( 'Lock', 'jamies-front-end-editor-for-content-teams' ) . '</em>',
					'<code>fie-no-edit</code>'
				),
				array(
					'strong' => array(),
					'em'     => array(),
					'code'   => array(),
				)
			);
			?>
			</li>
		</ul>
	</div>
	<?php
}

/**
 * Register the page-lock post meta (protected; edited via the meta box below).
 */
add_action(
	'init',
	function () {
		register_meta(
			'post',
			JFECT_PAGE_LOCK_META,
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}
);

/**
 * Add a "Front-End Editing" meta box with the page lock toggle.
 */
add_action(
	'add_meta_boxes',
	function () {
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			add_meta_box(
				'jfect_lock_box',
				__( 'Front-End Editing', 'jamies-front-end-editor-for-content-teams' ),
				'jfect_render_lock_box',
				$post_type,
				'side',
				'default'
			);
		}
	}
);

/**
 * Render the page-lock meta box.
 *
 * @param WP_Post $post Current post object.
 */
function jfect_render_lock_box( $post ) {
	wp_nonce_field( 'jfect_lock_save', 'jfect_lock_nonce' );
	$locked = jfect_is_page_locked( $post->ID );
	?>
	<label style="display:block;margin-bottom:8px;">
		<input type="checkbox" name="jfect_lock_page" value="1" <?php checked( $locked ); ?> />
		<?php esc_html_e( 'Lock this page (disable front-end editing)', 'jamies-front-end-editor-for-content-teams' ); ?>
	</label>
	<p class="description">
		<?php
		// Simple help text; only the <code> and <strong> tags need to survive.
		echo wp_kses(
			sprintf(
				/* translators: 1: bolded "Lock" option name, 2: the "fie-no-edit" CSS class name. */
				__( 'To lock just one section instead, use the block&rsquo;s %1$s option (the padlock in its Options menu), or add the CSS class %2$s to it. The lock also covers everything nested inside that block.', 'jamies-front-end-editor-for-content-teams' ),
				'<strong>' . esc_html__( 'Lock', 'jamies-front-end-editor-for-content-teams' ) . '</strong>',
				'<code>fie-no-edit</code>'
			),
			array(
				'code'   => array(),
				'strong' => array(),
			)
		);
		?>
	</p>
	<?php
}

/**
 * Save the page-lock meta box.
 */
add_action(
	'save_post',
	function ( $post_id ) {
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
	}
);

/**
 * Register script module and styles.
 */
add_action(
	'init',
	function () {
		wp_register_script_module(
			'jamies-front-end-editor-for-content-teams/view',
			plugins_url( 'view.js', __FILE__ ),
			array( '@wordpress/interactivity' ),
			'1.9.0'
		);
	}
);

/**
 * Track whether we should render editing UI on this request.
 */
function jfect_should_render() {
	static $should = null;

	if ( null !== $should ) {
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
 * Whether inline image editing (replace / alt / remove) is available.
 */
function jfect_image_editing_enabled() {
	return (bool) apply_filters( 'jfect_image_editing_enabled', true );
}

/**
 * Whether inline button editing (text + link) is available.
 */
function jfect_button_editing_enabled() {
	return (bool) apply_filters( 'jfect_button_editing_enabled', true );
}

/**
 * Whether a block type is handled by the front-end editor: an editable text
 * block, or (when enabled) a core image or button block.
 *
 * @param string $block_name Block type name, e.g. "core/paragraph".
 * @return bool
 */
function jfect_is_handled_block( $block_name ) {
	if ( in_array( $block_name, jfect_get_editable_blocks(), true ) ) {
		return true;
	}
	if ( jfect_image_editing_enabled() && 'core/image' === $block_name ) {
		return true;
	}
	return jfect_button_editing_enabled() && 'core/button' === $block_name;
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

	if ( null !== $map ) {
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
		if ( ! jfect_is_handled_block( $block['blockName'] ) ) {
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
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! jfect_should_render() ) {
			return;
		}

		wp_enqueue_script_module( 'jamies-front-end-editor-for-content-teams/view' );
		wp_enqueue_style(
			'jamies-front-end-editor-for-content-teams',
			plugins_url( 'style.css', __FILE__ ),
			array(),
			'1.5.0'
		);

		// The WordPress media library, so images can be replaced on the front end.
		if ( jfect_image_editing_enabled() ) {
			wp_enqueue_media();
		}

		$post_id = get_queried_object_id();

		// Is someone else already editing this post (front end or back end)?
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$lock_user = wp_check_post_lock( $post_id );
		$locked_by = $lock_user ? get_the_author_meta( 'display_name', $lock_user ) : '';

		wp_interactivity_state(
			'jamies-front-end-editor-for-content-teams',
			array(
				'postId'         => $post_id,
				'restNonce'      => wp_create_nonce( 'wp_rest' ),
				'endpoint'       => rest_url( 'jfect/v1/update-block' ),
				'imageEndpoint'  => rest_url( 'jfect/v1/image' ),
				'buttonEndpoint' => rest_url( 'jfect/v1/button' ),
				'lockEndpoint'   => rest_url( 'jfect/v1/lock' ),
				'unlockEndpoint' => rest_url( 'jfect/v1/unlock' ),
				'lockedByName'   => $locked_by,
				'isEditing'      => false,
				'isSaving'       => false,
				'message'        => '',
			)
		);
	}
);

/**
 * Use the render_block filter to wrap editable blocks.
 *
 * We match each rendered block against the pre-built map of the post's
 * own blocks using blockName + innerHTML as the key. This correctly
 * handles nested blocks (inside covers, groups, columns, etc.) and
 * ignores template blocks (header, footer, nav) that aren't in the post.
 */
add_filter(
	'render_block',
	function ( $block_content, $block ) {
		if ( ! jfect_should_render() ) {
			return $block_content;
		}

		if ( ! jfect_is_handled_block( $block['blockName'] ) ) {
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

		$context = esc_attr(
			wp_json_encode(
				array(
					'blockIndex' => $flat_index,
					'base'       => $base_hash,
				)
			)
		);

		// Image blocks get a lighter wrapper: click to open the image controls
		// (replace / alt / remove), no contentEditable text flow.
		if ( 'core/image' === $block['blockName'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return sprintf(
				'<div class="fie-block fie-image-block" data-wp-interactive="jamies-front-end-editor-for-content-teams" data-wp-context=\'%s\' data-wp-on--click="actions.editImage">%s</div>',
				$context,
				$block_content
			);
		}

		// Button blocks: click to open the button editor (text + link).
		if ( 'core/button' === $block['blockName'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return sprintf(
				'<div class="fie-block fie-button-block" data-wp-interactive="jamies-front-end-editor-for-content-teams" data-wp-context=\'%s\' data-wp-on--click="actions.editButton">%s</div>',
				$context,
				$block_content
			);
		}

		// $context is escaped above with esc_attr(); $block_content is WordPress core's
		// already-rendered block output for this block and must be passed through as-is.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return sprintf(
			'<div class="fie-block" data-wp-interactive="jamies-front-end-editor-for-content-teams" data-wp-context=\'%s\' data-wp-on--click="actions.editBlock" data-wp-on--keydown="actions.onKeydown" data-wp-class--fie-active="context.active" data-wp-watch="callbacks.syncEditable">%s</div>',
			$context,
			$block_content
		);
	},
	10,
	2
);

/**
 * Add the toolbar to wp_footer.
 */
add_action(
	'wp_footer',
	function () {
		if ( ! jfect_should_render() ) {
			return;
		}
		?>
	<div data-wp-interactive="jamies-front-end-editor-for-content-teams" class="fie-toolbar-wrap">
		<div class="fie-lock-banner" data-wp-bind--hidden="!state.lockedByName">
			<strong data-wp-text="state.lockedByName"></strong>
			<?php
			esc_html_e( 'is currently editing this page — it&#8217;s read-only until they&#8217;re done.', 'jamies-front-end-editor-for-content-teams' );
			?>
		</div>
		<div class="fie-toolbar" hidden data-wp-bind--hidden="!state.isEditing">
			<div class="fie-toolbar-inner">
				<span class="fie-toolbar-label"><?php esc_html_e( 'Editing', 'jamies-front-end-editor-for-content-teams' ); ?></span>
				<button class="fie-btn fie-btn-save" data-wp-on--click="actions.save" data-wp-bind--disabled="state.isSaving"><?php esc_html_e( 'Save', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				<button class="fie-btn fie-btn-cancel" data-wp-on--click="actions.cancel" data-wp-bind--disabled="state.isSaving"><?php esc_html_e( 'Cancel', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				<span class="fie-message" data-wp-text="state.message"></span>
			</div>
		</div>

		<div class="fie-modal-overlay" hidden data-wp-bind--hidden="!state.linkOpen" data-wp-on--click="actions.modalBackdrop">
			<div class="fie-modal" role="dialog" aria-modal="true" aria-labelledby="fie-modal-title">
				<div class="fie-modal-head">
					<strong id="fie-modal-title" data-wp-text="state.linkTitle"><?php esc_html_e( 'Add link', 'jamies-front-end-editor-for-content-teams' ); ?></strong>
					<button type="button" class="fie-modal-x" data-wp-on--click="actions.closeLink" aria-label="<?php esc_attr_e( 'Close', 'jamies-front-end-editor-for-content-teams' ); ?>">&times;</button>
				</div>
				<label class="fie-modal-label" for="fie-link-url"><?php esc_html_e( 'Link URL', 'jamies-front-end-editor-for-content-teams' ); ?></label>
				<input type="url" id="fie-link-url" class="fie-link-input" placeholder="https://example.com" data-wp-on--keydown="actions.linkKeydown" />
				<label class="fie-modal-check"><input type="checkbox" class="fie-link-newtab" /> <?php esc_html_e( 'Open in a new tab', 'jamies-front-end-editor-for-content-teams' ); ?></label>
				<div class="fie-modal-actions">
					<button type="button" class="fie-btn fie-btn-remove" data-wp-on--click="actions.removeLink" data-wp-bind--hidden="!state.linkEditing"><?php esc_html_e( 'Remove link', 'jamies-front-end-editor-for-content-teams' ); ?></button>
					<span class="fie-modal-spacer"></span>
					<button type="button" class="fie-btn fie-btn-cancel" data-wp-on--click="actions.closeLink"><?php esc_html_e( 'Cancel', 'jamies-front-end-editor-for-content-teams' ); ?></button>
					<button type="button" class="fie-btn fie-btn-save" data-wp-on--click="actions.applyLink"><?php esc_html_e( 'Apply', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				</div>
			</div>
		</div>

		<div class="fie-modal-overlay" data-wp-bind--hidden="!state.buttonOpen" data-wp-on--click="actions.buttonBackdrop">
			<div class="fie-modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Edit button', 'jamies-front-end-editor-for-content-teams' ); ?>">
				<div class="fie-modal-head">
					<strong><?php esc_html_e( 'Edit button', 'jamies-front-end-editor-for-content-teams' ); ?></strong>
					<button type="button" class="fie-modal-x" data-wp-on--click="actions.closeButton" aria-label="<?php esc_attr_e( 'Close', 'jamies-front-end-editor-for-content-teams' ); ?>">&times;</button>
				</div>
				<label class="fie-modal-label" for="fie-btn-text"><?php esc_html_e( 'Button text', 'jamies-front-end-editor-for-content-teams' ); ?></label>
				<input type="text" id="fie-btn-text" class="fie-btn-text-input" placeholder="<?php esc_attr_e( 'Learn more', 'jamies-front-end-editor-for-content-teams' ); ?>" data-wp-on--keydown="actions.buttonKeydown" />
				<label class="fie-modal-label" for="fie-btn-url" style="margin-top:14px;"><?php esc_html_e( 'Link URL', 'jamies-front-end-editor-for-content-teams' ); ?></label>
				<input type="url" id="fie-btn-url" class="fie-btn-url-input" placeholder="https://example.com" data-wp-on--keydown="actions.buttonKeydown" />
				<label class="fie-modal-check"><input type="checkbox" class="fie-btn-newtab" /> <?php esc_html_e( 'Open in a new tab', 'jamies-front-end-editor-for-content-teams' ); ?></label>
				<div class="fie-modal-actions">
					<span class="fie-modal-spacer"></span>
					<button type="button" class="fie-btn fie-btn-cancel" data-wp-on--click="actions.closeButton"><?php esc_html_e( 'Cancel', 'jamies-front-end-editor-for-content-teams' ); ?></button>
					<button type="button" class="fie-btn fie-btn-save" data-wp-on--click="actions.applyButton"><?php esc_html_e( 'Save', 'jamies-front-end-editor-for-content-teams' ); ?></button>
				</div>
			</div>
		</div>
	</div>
		<?php
	}
);

/**
 * Show a user bar with logout link for restricted users on every page.
 */
add_action(
	'wp_footer',
	function () {
		if ( ! jfect_is_user_restricted() ) {
			return;
		}

		// Enqueue styles even on non-singular pages.
		wp_enqueue_style(
			'jamies-front-end-editor-for-content-teams',
			plugins_url( 'style.css', __FILE__ ),
			array(),
			'1.3.0'
		);
		?>
	<details class="fie-user-fab">
		<summary class="fie-fab-toggle" title="<?php esc_attr_e( 'Account', 'jamies-front-end-editor-for-content-teams' ); ?>">&#9881;</summary>
		<div class="fie-fab-menu">
			<span class="fie-fab-greeting">
				<?php
				/* translators: %s: current user's display name. */
				echo esc_html( sprintf( __( 'Hi, %s', 'jamies-front-end-editor-for-content-teams' ), wp_get_current_user()->display_name ) );
				?>
			</span>
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>" class="fie-fab-logout"><?php esc_html_e( 'Log out', 'jamies-front-end-editor-for-content-teams' ); ?></a>
		</div>
	</details>
		<?php
	}
);

/**
 * REST endpoint: receives a block index and new inner HTML,
 * parses the post's raw block content, updates that block, and saves.
 */
add_action(
	'rest_api_init',
	function () {
		$can_edit = function ( $request ) {
			$post_id = absint( $request->get_param( 'postId' ) );
			return $post_id && current_user_can( 'edit_post', $post_id );
		};

		register_rest_route(
			'jfect/v1',
			'/update-block',
			array(
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
			)
		);

		// Acquire or refresh the edit lock for this post.
		register_rest_route(
			'jfect/v1',
			'/lock',
			array(
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
			)
		);

		// Release the edit lock for this post (if held by the current user).
		register_rest_route(
			'jfect/v1',
			'/unlock',
			array(
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
			)
		);

		// Image operations: replace, alt text, or remove.
		register_rest_route(
			'jfect/v1',
			'/image',
			array(
				'methods'             => 'POST',
				'callback'            => 'jfect_update_image',
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
					'op'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'baseHash'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'id'         => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'url'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'alt'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Button: update its text and link.
		register_rest_route(
			'jfect/v1',
			'/button',
			array(
				'methods'             => 'POST',
				'callback'            => 'jfect_update_button',
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
					'text'       => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'        => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'newtab'     => array(
						'required' => false,
						'type'     => 'boolean',
					),
				),
			)
		);
	}
);

/**
 * Acquire or refresh the WordPress post-edit lock from the front end.
 * Refuses if another user currently holds the lock.
 *
 * @param WP_REST_Request $request REST request, expects a "postId" param.
 * @return array|WP_Error
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
				'status'   => 423,
				'lockedBy' => $name,
			)
		);
	}

	// Sets/refreshes the lock to the current user.
	wp_set_post_lock( $post_id );

	return array( 'success' => true );
}

/**
 * Release the post-edit lock, but only if the current user is the holder.
 *
 * @param WP_REST_Request $request REST request, expects a "postId" param.
 * @return array
 */
function jfect_release_lock( $request ) {
	$post_id = $request->get_param( 'postId' );

	$lock = get_post_meta( $post_id, '_edit_lock', true );
	if ( $lock ) {
		$parts     = explode( ':', $lock );
		$lock_user = isset( $parts[1] ) ? (int) $parts[1] : 0;
		if ( get_current_user_id() === $lock_user ) {
			delete_post_meta( $post_id, '_edit_lock' );
		}
	}

	return array( 'success' => true );
}

/**
 * Locate the flat index of the block a client means to edit.
 *
 * Blocks are addressed by a positional index baked into the page at load time.
 * If the page structure changes during a session (e.g. the user removes an
 * image, shifting every later block down one), those baked indices go stale.
 * To stay robust, we treat the block's content fingerprint (base hash) as its
 * real identity: use the given index when it still matches, otherwise find the
 * one block whose fingerprint matches. Returns the resolved index, or null when
 * no unambiguous match exists (a genuine concurrent change).
 *
 * @param array  $flat        Flattened blocks.
 * @param int    $block_index Client-supplied index (a hint).
 * @param string $base_hash   Fingerprint of the block's innerHTML at page load.
 * @param string $expect_name Optional block name the target must have.
 * @return int|null
 */
function jfect_resolve_index( $flat, $block_index, $base_hash, $expect_name = '' ) {
	$name_ok = function ( $t ) use ( $expect_name ) {
		return '' === $expect_name || ( isset( $t['block']['blockName'] ) && $t['block']['blockName'] === $expect_name );
	};

	// Prefer the supplied index when it still points at the expected content.
	if ( isset( $flat[ $block_index ] ) && $name_ok( $flat[ $block_index ] ) ) {
		$t = $flat[ $block_index ];
		if ( '' === $base_hash || md5( trim( $t['ref']['innerHTML'] ) ) === $base_hash ) {
			return $block_index;
		}
	}

	// Index drifted: fall back to locating the block by its fingerprint.
	if ( '' !== $base_hash ) {
		$found = null;
		$count = 0;
		foreach ( $flat as $i => $t ) {
			if ( $name_ok( $t ) && md5( trim( $t['ref']['innerHTML'] ) ) === $base_hash ) {
				$found = $i;
				++$count;
			}
		}
		if ( 1 === $count ) {
			return $found;
		}
	}

	return null;
}

/**
 * Record a front-end edit as a native WordPress note, plus structured comment
 * meta so the "Site changes" screen can query and filter every edit across the
 * site (rather than parsing note text). The human-readable note is kept as-is
 * for back-compat and the per-post view.
 *
 * @param int         $post_id    The edited post.
 * @param string      $block_type e.g. 'paragraph', 'heading', 'image', 'button'.
 * @param string      $note       Human-readable summary (the note body).
 * @param string|null $old        Old text, for text edits (optional).
 * @param string|null $new        New text, for text edits (optional).
 * @return int|false Comment ID, or false on failure.
 */
function jfect_record_edit( $post_id, $block_type, $note, $old = null, $new = null ) {
	$user       = wp_get_current_user();
	$comment_id = wp_insert_comment( array(
		'comment_post_ID'      => $post_id,
		'comment_content'      => $note,
		'comment_type'         => 'note',
		'user_id'              => $user->ID,
		'comment_author'       => $user->display_name,
		'comment_author_email' => $user->user_email,
		'comment_approved'     => 1,
		'comment_parent'       => 0,
	) );

	if ( ! $comment_id ) {
		return false;
	}

	add_comment_meta( $comment_id, 'jfect_edit', 1 );
	add_comment_meta( $comment_id, 'jfect_post_id', (int) $post_id );
	add_comment_meta( $comment_id, 'jfect_block_type', $block_type );
	add_comment_meta( $comment_id, 'jfect_user_id', (int) $user->ID );
	if ( null !== $old ) {
		add_comment_meta( $comment_id, 'jfect_old', mb_strimwidth( wp_strip_all_tags( (string) $old ), 0, 300, '…' ) );
	}
	if ( null !== $new ) {
		add_comment_meta( $comment_id, 'jfect_new', mb_strimwidth( wp_strip_all_tags( (string) $new ), 0, 300, '…' ) );
	}

	return $comment_id;
}

/**
 * Handle the block update.
 *
 * @param WP_REST_Request $request REST request with postId, blockIndex, newContent, baseHash params.
 * @return array|WP_Error
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
		return new WP_Error( 'not_found', __( 'Post not found.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 404 ) );
	}

	// Whole page locked from front-end editing.
	if ( jfect_is_page_locked( $post_id ) ) {
		return new WP_Error( 'page_locked', __( 'Front-end editing is locked for this page.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}

	// Someone else is holding the edit lock (front end or back end).
	require_once ABSPATH . 'wp-admin/includes/post.php';
	$lock_user = wp_check_post_lock( $post_id );
	if ( $lock_user ) {
		$name = get_the_author_meta( 'display_name', $lock_user );
		return new WP_Error(
			'locked',
			/* translators: %s: display name of the user currently editing the page. */
			sprintf( __( '%s is currently editing this page. Reload to see the latest.', 'jamies-front-end-editor-for-content-teams' ), $name ),
			array( 'status' => 423 )
		);
	}

	$blocks = parse_blocks( $post->post_content );

	$flat = array();
	jfect_flatten_blocks( $blocks, $flat );

	// Resolve the target by fingerprint, tolerating index drift from earlier edits
	// in this session (e.g. a removed image shifting later blocks).
	$resolved = jfect_resolve_index( $flat, $block_index, (string) $base_hash );
	if ( null === $resolved ) {
		if ( $base_hash ) {
			// The block's stored content no longer matches what this client loaded,
			// and it isn't found elsewhere: a genuine concurrent change.
			return new WP_Error(
				'conflict',
				__( 'This content was just changed by someone else. Reload the page to see the latest version.', 'jamies-front-end-editor-for-content-teams' ),
				array( 'status' => 409 )
			);
		}
		return new WP_Error( 'invalid_block', __( 'Block index out of range.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	$target = $flat[ $resolved ];

	// Block (or a container around it) is locked from front-end editing.
	if ( ! empty( $target['locked'] ) ) {
		return new WP_Error( 'block_locked', __( 'This section is locked from front-end editing.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}

	if ( ! in_array( $target['block']['blockName'], jfect_get_editable_blocks(), true ) ) {
		return new WP_Error( 'not_editable', __( 'This block type is not editable.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	// The block's innerHTML contains the full inner markup, e.g.:
	// <p class="...">Old text</p>
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

	$result = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $updated_content,
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Record the edit as a native WordPress block note, with structured meta.
	$block_type = str_replace( 'core/', '', $target['block']['blockName'] );
	$old_short  = mb_strimwidth( wp_strip_all_tags( $old_text ), 0, 80, '…' );
	$new_short  = mb_strimwidth( wp_strip_all_tags( $new_content ), 0, 80, '…' );

	$note_content = sprintf(
		/* translators: 1: block type name (e.g. "paragraph"), 2: previous text, 3: new text. */
		__( 'Edited %1$s from front end: "%2$s" → "%3$s"', 'jamies-front-end-editor-for-content-teams' ),
		$block_type,
		$old_short,
		$new_short
	);

	jfect_record_edit( $post_id, $block_type, $note_content, $old_text, $new_content );

	// Return the new fingerprint so the client can keep editing this block
	// (without a reload) instead of tripping the concurrent-edit guard next save.
	return array(
		'success' => true,
		'newBase' => md5( trim( $new_inner ) ),
	);
}

/**
 * REST callback: replace an image, set its alt text, or remove the image block.
 *
 * @param WP_REST_Request $request REST request with postId, blockIndex, op, baseHash, and (per op) id/url/alt params.
 * @return array|WP_Error
 */
function jfect_update_image( $request ) {
	$post_id     = absint( $request->get_param( 'postId' ) );
	$block_index = absint( $request->get_param( 'blockIndex' ) );
	$op          = sanitize_key( $request->get_param( 'op' ) );
	$base_hash   = sanitize_text_field( (string) $request->get_param( 'baseHash' ) );

	if ( ! jfect_image_editing_enabled() ) {
		return new WP_Error( 'disabled', __( 'Image editing is disabled.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', __( 'Post not found.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 404 ) );
	}
	if ( jfect_is_page_locked( $post_id ) ) {
		return new WP_Error( 'page_locked', __( 'Front-end editing is locked for this page.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/post.php';
	$lock_user = wp_check_post_lock( $post_id );
	if ( $lock_user ) {
		$name = get_the_author_meta( 'display_name', $lock_user );
		/* translators: %s: display name of the user currently editing the page. */
		return new WP_Error( 'locked', sprintf( __( '%s is currently editing this page. Reload to see the latest.', 'jamies-front-end-editor-for-content-teams' ), $name ), array( 'status' => 423 ) );
	}

	// Claim the lock for this user so a concurrent editor is blocked while we save.
	// Image ops don't use the text hash-guard, since it false-positives on images.
	wp_set_post_lock( $post_id );

	$blocks = parse_blocks( $post->post_content );
	$flat   = array();
	jfect_flatten_blocks( $blocks, $flat );

	// Resolve by fingerprint so image ops still hit the right block after earlier
	// edits in this session shifted the indices.
	$resolved = jfect_resolve_index( $flat, $block_index, $base_hash, 'core/image' );
	if ( null === $resolved ) {
		return new WP_Error( 'invalid_block', __( 'Could not find that image. Reload to see the latest.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 409 ) );
	}
	$block_index = $resolved;
	$target      = $flat[ $resolved ];

	if ( ! empty( $target['locked'] ) ) {
		return new WP_Error( 'block_locked', __( 'This section is locked from front-end editing.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}
	if ( 'core/image' !== $target['block']['blockName'] ) {
		return new WP_Error( 'not_image', __( 'That block is not an image.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	if ( 'remove' === $op ) {
		$counter = 0;
		$removed = jfect_remove_block_at( $blocks, $block_index, $counter );
		if ( null === $removed ) {
			return new WP_Error( 'remove_failed', __( 'Could not remove the image.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 500 ) );
		}
		$note = __( 'Removed an image from the front end', 'jamies-front-end-editor-for-content-teams' );
	} else {
		$new_inner = jfect_image_apply( $target['ref']['innerHTML'], $op, $request, $target['ref'] );
		if ( is_wp_error( $new_inner ) ) {
			return $new_inner;
		}
		$target['ref']['innerHTML']    = $new_inner;
		$target['ref']['innerContent'] = array( $new_inner );
		$note                          = ( 'alt' === $op ) ? __( 'Updated image alt text from the front end', 'jamies-front-end-editor-for-content-teams' ) : __( 'Replaced an image from the front end', 'jamies-front-end-editor-for-content-teams' );
	}

	$updated_content = serialize_blocks( $blocks );
	$result          = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $updated_content,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Record the change as a native WordPress block note, with structured meta.
	jfect_record_edit( $post_id, 'image', $note );

	$new_base = ( 'remove' === $op ) ? '' : md5( trim( $target['ref']['innerHTML'] ) );
	return array(
		'success' => true,
		'newBase' => $new_base,
	);
}

/**
 * Apply an image change (replace or alt) to a block's inner HTML, returning the
 * updated inner HTML. Also updates the block's stored attachment id on replace.
 *
 * @param string          $inner     Block's current innerHTML.
 * @param string          $op        Operation: "alt" or "replace".
 * @param WP_REST_Request $request   REST request carrying the new url/alt/id params.
 * @param array           $block_ref Reference to the block array, so attrs can be updated.
 * @return string|WP_Error Updated innerHTML, or an error.
 */
function jfect_image_apply( $inner, $op, $request, &$block_ref ) {
	// $inner is the block's own stored markup (already sanitized on the way in via
	// wp_kses_post/esc_url_raw), not raw user input. libxml >= 2.9 (bundled with the
	// PHP 8.0+ this plugin requires) disables external entity loading by default, so
	// this loadHTML() call is not XXE-exploitable.
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<meta charset="utf-8"><div id="fie-w">' . $inner . '</div>', LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$wrap  = $xpath->query( '//*[@id="fie-w"]' )->item( 0 );
	$img   = $wrap ? $xpath->query( './/img', $wrap )->item( 0 ) : null;
	if ( ! $img ) {
		return new WP_Error( 'no_img', __( 'No image found in this block.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	if ( 'alt' === $op ) {
		$img->setAttribute( 'alt', sanitize_text_field( (string) $request->get_param( 'alt' ) ) );
	} else {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'no_url', __( 'No image URL was provided.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
		}
		$id  = absint( $request->get_param( 'id' ) );
		$alt = sanitize_text_field( (string) $request->get_param( 'alt' ) );

		$img->setAttribute( 'src', $url );
		$img->setAttribute( 'alt', $alt );
		$img->removeAttribute( 'srcset' );
		$img->removeAttribute( 'sizes' );
		$img->removeAttribute( 'width' );
		$img->removeAttribute( 'height' );

		$class = trim( preg_replace( '/\bwp-image-\d+\b/', '', $img->getAttribute( 'class' ) ) );
		if ( $id ) {
			$class = trim( $class . ' wp-image-' . $id );
		}
		$img->setAttribute( 'class', trim( preg_replace( '/\s+/', ' ', $class ) ) );

		if ( $id ) {
			if ( ! isset( $block_ref['attrs'] ) || ! is_array( $block_ref['attrs'] ) ) {
				$block_ref['attrs'] = array();
			}
			$block_ref['attrs']['id'] = $id;
		}
	}

	$out = '';
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
	foreach ( $wrap->childNodes as $child ) {
		$out .= $dom->saveHTML( $child );
	}
	return $out;
}

/**
 * REST callback: update a button block's text and link.
 *
 * @param WP_REST_Request $request REST request with postId, blockIndex, text, url, newtab params.
 * @return array|WP_Error
 */
function jfect_update_button( $request ) {
	$post_id     = absint( $request->get_param( 'postId' ) );
	$block_index = absint( $request->get_param( 'blockIndex' ) );

	if ( ! jfect_button_editing_enabled() ) {
		return new WP_Error( 'disabled', __( 'Button editing is disabled.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	$post = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'not_found', __( 'Post not found.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 404 ) );
	}
	if ( jfect_is_page_locked( $post_id ) ) {
		return new WP_Error( 'page_locked', __( 'Front-end editing is locked for this page.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}

	require_once ABSPATH . 'wp-admin/includes/post.php';
	$lock_user = wp_check_post_lock( $post_id );
	if ( $lock_user ) {
		$name = get_the_author_meta( 'display_name', $lock_user );
		/* translators: %s: display name of the user currently editing the page. */
		return new WP_Error( 'locked', sprintf( __( '%s is currently editing this page. Reload to see the latest.', 'jamies-front-end-editor-for-content-teams' ), $name ), array( 'status' => 423 ) );
	}
	wp_set_post_lock( $post_id );

	$blocks = parse_blocks( $post->post_content );
	$flat   = array();
	jfect_flatten_blocks( $blocks, $flat );

	if ( ! isset( $flat[ $block_index ] ) ) {
		return new WP_Error( 'invalid_block', __( 'Block index out of range.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}
	$target = $flat[ $block_index ];

	if ( ! empty( $target['locked'] ) ) {
		return new WP_Error( 'block_locked', __( 'This section is locked from front-end editing.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 403 ) );
	}
	if ( 'core/button' !== $target['block']['blockName'] ) {
		return new WP_Error( 'not_button', __( 'That block is not a button.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	$new_inner = jfect_button_apply( $target['ref']['innerHTML'], $request );
	if ( is_wp_error( $new_inner ) ) {
		return $new_inner;
	}
	$target['ref']['innerHTML']    = $new_inner;
	$target['ref']['innerContent'] = array( $new_inner );

	$updated_content = serialize_blocks( $blocks );
	$result          = wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $updated_content,
		),
		true
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	jfect_record_edit( $post_id, 'button', 'Edited a button from the front end' );

	return array( 'success' => true );
}

/**
 * Apply new text / link to the anchor inside a button block's inner HTML.
 *
 * @param string          $inner   Block's current innerHTML.
 * @param WP_REST_Request $request REST request carrying the new text/url/newtab params.
 * @return string|WP_Error Updated innerHTML, or an error.
 */
function jfect_button_apply( $inner, $request ) {
	// $inner is the block's own stored markup (already sanitized on the way in via
	// wp_kses_post/esc_url_raw), not raw user input. libxml >= 2.9 (bundled with the
	// PHP 8.0+ this plugin requires) disables external entity loading by default, so
	// this loadHTML() call is not XXE-exploitable.
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<meta charset="utf-8"><div id="fie-w">' . $inner . '</div>', LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$wrap  = $xpath->query( '//*[@id="fie-w"]' )->item( 0 );
	$a     = $wrap ? $xpath->query( './/a', $wrap )->item( 0 ) : null;
	if ( ! $a ) {
		return new WP_Error( 'no_anchor', __( 'No button link found in this block.', 'jamies-front-end-editor-for-content-teams' ), array( 'status' => 400 ) );
	}

	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	if ( array_key_exists( 'text', $params ) ) {
		$text = sanitize_text_field( (string) $request->get_param( 'text' ) );
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
		while ( $a->firstChild ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
			$a->removeChild( $a->firstChild );
		}
		$a->appendChild( $dom->createTextNode( $text ) );
	}

	if ( array_key_exists( 'url', $params ) ) {
		$url = esc_url_raw( (string) $request->get_param( 'url' ) );
		if ( '' !== $url ) {
			$a->setAttribute( 'href', $url );
		} else {
			$a->removeAttribute( 'href' );
		}
	}

	$newtab = (bool) $request->get_param( 'newtab' );
	if ( $newtab ) {
		$a->setAttribute( 'target', '_blank' );
		$a->setAttribute( 'rel', 'noreferrer noopener' );
	} else {
		$a->removeAttribute( 'target' );
		$a->removeAttribute( 'rel' );
	}

	$out = '';
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property.
	foreach ( $wrap->childNodes as $child ) {
		$out .= $dom->saveHTML( $child );
	}
	return $out;
}

/**
 * Remove the block at a given flat index (pre-order, matching the flatten). Also
 * drops the matching null placeholder from a parent's innerContent so the block
 * serialises correctly. Returns the removed block, or null.
 *
 * @param array      $blocks       Blocks array to search (a top-level list or a set of innerBlocks).
 * @param int        $target       Flat index of the block to remove.
 * @param int        $counter      Running flat-index counter, shared across recursive calls.
 * @param array|null $parent_block Parent block being recursed into, if any.
 * @return array|null The removed block, or null if not found.
 */
function jfect_remove_block_at( &$blocks, $target, &$counter, &$parent_block = null ) {
	$total = count( $blocks );
	for ( $i = 0; $i < $total; $i++ ) {
		if ( $counter === $target ) {
			$removed = $blocks[ $i ];
			array_splice( $blocks, $i, 1 );
			if ( null !== $parent_block ) {
				jfect_remove_nth_inner_null( $parent_block, $i );
			}
			return $removed;
		}
		++$counter;
		if ( ! empty( $blocks[ $i ]['innerBlocks'] ) ) {
			$r = jfect_remove_block_at( $blocks[ $i ]['innerBlocks'], $target, $counter, $blocks[ $i ] );
			if ( null !== $r ) {
				return $r;
			}
		}
	}
	return null;
}

/**
 * Remove the nth null placeholder (nth inner block) from a block's innerContent.
 *
 * @param array $parent_block Parent block whose innerContent should be adjusted.
 * @param int   $n            Index of the null placeholder to remove.
 */
function jfect_remove_nth_inner_null( &$parent_block, $n ) {
	if ( empty( $parent_block['innerContent'] ) || ! is_array( $parent_block['innerContent'] ) ) {
		return;
	}
	$seen = -1;
	foreach ( $parent_block['innerContent'] as $k => $chunk ) {
		if ( null === $chunk ) {
			++$seen;
			if ( $seen === $n ) {
				array_splice( $parent_block['innerContent'], $k, 1 );
				return;
			}
		}
	}
}

/**
 * Flatten nested blocks into an ordered list matching render_block's traversal.
 * Each entry holds a reference to the block in the original tree so we can modify it,
 * plus a 'locked' flag that is inherited from any locked ancestor (or the block itself).
 *
 * @param array $blocks        Blocks array to flatten (a top-level list or a set of innerBlocks).
 * @param array $flat          Output list, built up by reference across recursive calls.
 * @param bool  $parent_locked Whether an ancestor of $blocks is already locked.
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

