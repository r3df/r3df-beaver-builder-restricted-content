<?php

/*
Plugin Name:	R3DF - Beaver Builder: Restricted Content
Description:    Role based content restrictions for Beaver Builder, with content teaser.
Plugin URI:		http://r3df.com/
Version: 		0.0.9 Alpha
Text Domain:	r3df-beaver-builder-restricted-content
Domain Path: 	/lang/
Author:         R3DF
Author URI:     http: //r3df.com
Author email:   plugin-support@r3df.com
Copyright: 		R-Cubed Design Forge
*/

/*  Copyright 2020 R-Cubed Design Forge

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License, version 2, as
	published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( __FILE__ === $_SERVER['SCRIPT_FILENAME'] ) {
	die();
}

// TODO - check for Beaver active...
// TODO - add functions to aid in cap removal/manipulation
// TODO - restrict settings page to same caps as edit

$r3df_beaver_builder_restricted_content = new R3DF_Beaver_Builder_Restricted_Content;


/**************************************************************************************************************
 * Class R3DF_Beaver_Builder_Restricted_Content
 **************************************************************************************************************
 *
 */
class R3DF_Beaver_Builder_Restricted_Content {

	/**
	 * @var array
	 */
	var $plugin = array();


	/**
	 * roles to treat as admin - allow to view everything
	 * @var array
	 */
	var $admin_roles = array( 'administrator' );

	/**
	 * content types to add restrictions to
	 * @var array
	 */
	var $restricted_content_types = array( 'post', 'page' );


	/**
	 * Store Builder template for no access response - can't load it on the_content filter, conflict with other plugins...
	 * @var string
	 */
	var $template = '';

	/**
	 * In the_content flag
	 * @var boolean
	 */
	var $in_the_content = false;

	/**
	 * Content has teaser flag
	 * @var boolean
	 */
	var $has_teaser = false;

	/**
	 * Teaser row has been found on the page flag
	 * @var boolean
	 */
	var $teaser_found = false;


	/**
	 * Array containing user ID's to read default restricted content templates
	 * @var array
	 */
	var $add_read_rc_template_caps = array( 'administrator' );

	/**
	 * Array containing user ID's to create default restricted content templates
	 * NOTE: new default restricted content templates should NEVER be added.
	 * This capability should not generally be added to any users
	 * @var array
	 */
	var $add_create_rc_template_caps = array();

	/**
	 * Array containing user ID's to edit default restricted content templates
	 * @var array
	 */
	var $add_edit_rc_template_caps = array( 'administrator' );

	/**
	 * Array containing user ID's to delete default restricted content templates
	 * NOTE: default restricted content templates should NEVER be deleted.
	 * This capability should not generally be added to any users
	 * @var array
	 */
	var $add_delete_rc_template_caps = array();

	/**
	 * Array containing user ID's to be removed from read default restricted content templates
	 * @var array
	 */
	var $remove_read_rc_template_caps = array();

	/**
	 * Array containing user ID's to be removed from create default restricted content templates
	 * @var array
	 */
	var $remove_create_rc_template_caps = array();

	/**
	 * Array containing user ID's to be removed from edit default restricted content templates
	 * @var array
	 */
	var $remove_edit_rc_template_caps = array();

	/**
	 * Array containing user ID's to be removed from edit default restricted content templates
	 * @var array
	 */
	var $remove_delete_rc_template_caps = array();



	/**
	 * Class Constructor
	 *
	 * @param array
	 */
	function __construct() {
		// **** Common front-end and admin ****//
		// Get the plugin version (added to css file loaded to clear browser caches on change)
		$this->plugin = get_file_data( __FILE__, array( 'Version' => 'Version' ) );

		// get options and load settings if options is set
		//  -> add default capabilities if not set
		// BLOOT add filters
		$options = get_option( 'r3df_bb_restricted_content' );
		if ( is_array( $options ) ) {
			// Set up admin roles to bypass restrictions
			$this->admin_roles = $options['admin-roles'];
			// Set up content types to be restricted
			$this->restricted_content_types = $options['content-types'];
			// Set up read permissions list
			$this->add_read_rc_template_caps = $options['read-permissions'];
			// Set up edit permissions list
			$this->add_edit_rc_template_caps = $options['edit-permissions'];
		} else {
			add_action( 'plugins_loaded', array( $this, 'add_rc_template_default_caps' ) );
		}
		add_action( 'plugins_loaded', array( $this, 'add_rc_template_default_caps' ) );

		// Setup access restrictions taxonomy
		add_action( 'init', array( $this, 'setup_access_restrictions_taxonomy' ) );

		// Add CPT for default restricted content templates
		add_action( 'init', array( $this, 'register_restricted_content_template_cpt' ) );

		// Add CPT's to builder post types to allow builder usage on them & remove from BB admin listings
		add_filter( 'fl_builder_post_types', array( $this, 'add_custom_post_types_to_bb' ) );
		add_filter( 'fl_builder_admin_settings_post_types', array( $this, 'hide_custom_post_types_in_bb_admin' ) );

		add_filter( 'template_include', array( $this, 'full_width_template' ), 1000 );

		if ( is_admin() ) {
			// **** Do admin stuff ****//

			// Add settings page
			add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );

		} else {
			// **** Do front-end stuff ****//

			// Restricted content filters - setup
			add_action( 'wp', array( $this, 'setup_restricted_content_filters' ) );


			// Beaver Teaser Editor Functions
			// save post meta for content with teaser
			add_action( 'fl_builder_after_save_layout', array( $this, 'set_teaser_post_meta' ), 10, 4 );

			// add teaser option to display section of rows
			add_filter( 'fl_builder_register_settings_form', array( $this, 'teaser_options' ), 10, 2 );

			// add teaser css
			add_filter( 'fl_builder_render_css', array( $this, 'add_teaser_css' ), 1000, 3 );

		}
	}

	/*****************************************************
	 * Custom Functions
	 *****************************************************
	 */


	/**
	 * Setup restricted content filters
	 *
	 */
	function setup_restricted_content_filters() {

		// Shortcode to restrict partial page content
		add_shortcode( 'bb-restricted-content', array( $this, 'restrict_page_content' ) );

		// Shortcode to mark restricted content note to be replaced by teaser - if exists
		add_shortcode( 'bb-restricted-note', array( $this, 'restricted_note' ) );

		// Load the & save template for restricted content
		//  -> can't do it in the_content filter function, calling do_shortcode on fl_builder_insert_layout in
		//     the_content filter causes page load issues when Yoast is active
		if ( ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_enabled() ) ) {
			$template       = get_page_by_path( 'bb-default-template', 'OBJECT', 'r3df-rc-template' );
			$this->template = do_shortcode( '[fl_builder_insert_layout id=' . $template->ID . ']' );
		} else {
			$template       = get_page_by_path( 'wp-default-template', 'OBJECT', 'r3df-rc-template' );
			$this->template = get_the_content( null, false, $template->ID );
		}

		$this->has_teaser = get_post_meta( get_the_ID(), 'r3df_has_beaver_teaser', true );
		if ( ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_enabled() ) && $this->has_teaser ) {

			// Flag start and end of content area - only filter content
			add_action( 'the_content', array( $this, 'content_start' ), 1 );
			add_action( 'the_content', array( $this, 'content_end' ), 10000000 );

			// Filter node visibility
			add_filter( 'fl_builder_is_node_visible', array( $this, 'filter_builder_node_visibility' ), 10, 2 );

		} else {
			// filter restricted content
			add_filter( 'the_content', array( $this, 'filter_restricted_content' ), 1000 );

			// Redirect if user does not have access privileges
			//add_action( 'template_redirect', array( $this, 'redirect_restricted_content' ) );
		}
	}



	// Builder teaser functions
	// ************************************

	/**
	 * Set post meta for content with teaser
	 *
	 * @param $post_id
	 * @param $publish
	 * @param $data
	 * @param $settings
	 */
	function set_teaser_post_meta( $post_id, $publish, $data, $settings ) {
		$teaser = false;
		foreach ( $data as $node_id => $node ) {
			if ( 'row' === $node->type ) {
				if ( 'yes' === $node->settings->teaser || 'both' === $node->settings->teaser ) {
					$teaser = true;
					update_post_meta( $post_id, 'r3df_has_beaver_teaser', true );
					break;
				}
			}
		}
		if ( ! $teaser ) {
			delete_post_meta( $post_id, 'r3df_has_beaver_teaser' );
		}
	}

	/**
	 * Add teaser options to row settings form - advanced -> visibility section
	 *
	 * @param $form
	 * @param $slug
	 *
	 * @return array
	 */
	function teaser_options( $form, $slug ) {

		// Rows only...
		if ( 'row' !== $slug ) {
			return $form;
		}

		$teaser = array(
			'type'    => 'select',
			'label'   => 'Teaser Row',
			'default' => 'no',
			'options' => array(
				'no'   => 'No',
				'yes'  => 'Yes',
				'both' => 'Both',
			),
		);
		$form['tabs']['advanced']['sections']['visibility']['fields']['teaser'] = $teaser;

		return $form;
	}

	/**
	 * Add css to mark teaser row
	 *  -> restricted to Builder Editor with .fl-builder-edit class
	 *
	 * @param $css
	 * @param $nodes
	 * @param $global_settings
	 *
	 * @return string
	 */
	function add_teaser_css( $css, $nodes, $global_settings ) {
		foreach ( $nodes['rows'] as $node_id => $row ) {
			if ( 'yes' === $row->settings->teaser ) {
				$css .= '.fl-builder-edit .fl-node-' . $node_id . ' { border: 5px dashed #1294e5; position: relative }';
				$css .= '.fl-builder-edit .fl-node-' . $node_id . ':before { content: "Teaser - Only"; position: absolute; left:0; top: 0; color: #ffffff; font-size: 16px; background-color: #1294e5; padding: 5px 8px; margin: 5px;}';
				$css .= 'body:not(.fl-builder-edit).customize-support .fl-node-' . $node_id . ' { display: none; }';
			} elseif ( 'both' === $row->settings->teaser ) {
				$css .= '.fl-builder-edit .fl-node-' . $node_id . ' { border: 5px dashed #0de861; position: relative }';
				$css .= '.fl-builder-edit .fl-node-' . $node_id . ':before { content: "Teaser - Both"; position: absolute; left:0; top: 0; color: #ffffff; font-size: 16px; background-color: #0de861; padding: 5px 8px; margin: 5px;}';
			}
		}
		return $css;
	}

	/**
	 * Mark start of the_content -> only filter nodes in content
	 *
	 * @param $content
	 *
	 * @return mixed
	 */
	function content_start( $content ) {
		$this->in_the_content = true;
		return $content;
	}

	/**
	 * Mark end of the_content -> only filter nodes in content
	 *
	 * @param $content
	 *
	 * @return mixed
	 */
	function content_end( $content ) {
		// If teaser was not found - error: load default template
		if ( ! $this->teaser_found && ! $this->can_user_access() ) {
			$content = $this->template;
		}
		$this->in_the_content = false;
		return $content;
	}

	/**
	 * Filter content nodes
	 *
	 * @param $is_visible
	 * @param $node
	 *
	 * @return bool
	 */
	function filter_builder_node_visibility( $is_visible, $node ) {

		// Check we are in the_content, and that builder is not in edit mode...
		if ( true !== $this->in_the_content || ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) ) {
			return $is_visible;
		}

		// Only filter rows
		if ( 'row' === $node->type ) {
			if ( $this->can_user_access() ) {
				if ( 'yes' === $node->settings->teaser ) {
					$this->teaser_found = true;
					return false;
				} elseif ( 'both' === $node->settings->teaser ) {
					$this->teaser_found = true;
				}
			} else {
				if ( 'yes' === $node->settings->teaser || 'both' === $node->settings->teaser ) {
					$this->teaser_found = true;
				} elseif ( $this->teaser_found ) {
					// hide regular content after teaser row
					return false;
				}
			}
		}

		return $is_visible;
	}



	// Restricted content message functions
	// ************************************

	/**
	 * bb-restricted-note Short Code processor
	 *
	 * @param $atts
	 * @param $content
	 *
	 * @return string
	 */
	function restricted_note( $atts = array(), $content = '' ) {

		$args = shortcode_atts(
			array(
				'role_slugs' => '',
			),
			$atts,
		);

		// BLOOT add custom field instead...
		// if there is a custom excerpt, replace the default note message.
		if ( has_excerpt() ) {
			$content = get_the_excerpt();
		}

		return $content;
	}


	/**
	 * Filter content if restricted
	 *  -> leave teaser content
	 *
	 * @param $content
	 *
	 * @return string
	 */
	function filter_restricted_content( $content ) {
		// if this is "Access not permitted" page, or in Beaver Editor, return without processing...
		$page = get_page_by_path( 'access-not-permitted', OBJECT, 'r3df-rc-template' );
		if ( get_queried_object_id() === $page->ID || ( class_exists( 'FLBuilderModel' ) && FLBuilderModel::is_builder_active() ) ) {
			return $content;
		}

		// let admin roles see everything...
		foreach ( $this->admin_roles as $admin_role ) {
			if ( current_user_can( $admin_role ) ) {
				return $content;
			}
		}

		if ( is_singular() && is_main_query() && ! $this->can_user_access() ) {
			remove_filter( 'the_content', array( $this, 'filter_restricted_content' ), 1000 );

			$content = $this->template;

			add_filter( 'the_content', array( $this, 'filter_restricted_content' ), 1000 );
		}

		return $content;
	}



	// Page redirect functions
	// ************************************

	/**
	 * Redirect if content is restricted
	 *  -> don't redirect if on no access page
	 *
	 */
	function redirect_restricted_content() {

		$page = get_page_by_path( 'access-not-permitted', OBJECT, 'r3df-rc-template' );
		if ( get_queried_object_id() === $page->ID ) {
			return;
		}

		if ( ! $this->can_user_access() ) {
			wp_redirect( get_permalink( $page->ID ) );
			exit();
		}
	}


	// Common functions
	// ************************************

	/**
	 * Check if user can access current page
	 *  -> allows admins to see all
	 *  -> if no roles provided, checks against access_restrictions taxonomy for current queried object
	 *
	 * @param $allowed_role_slugs
	 *
	 * @return boolean
	 */
	function can_user_access( $allowed_role_slugs = array() ) {

		// let admin roles see everything...
		foreach ( $this->admin_roles as $admin_role ) {
			if ( current_user_can( $admin_role ) ) {
				return true;
			}
		}

		if ( empty( $allowed_role_slugs ) ) {
			// get content based restrictions
			$terms = get_the_terms( get_queried_object_id(), 'access_restrictions' );
			if ( ! empty( $terms ) ) {
				$allowed_role_slugs = wp_list_pluck( $terms, 'slug' );
			}
		}

		if ( is_array( $allowed_role_slugs ) && ! empty( $allowed_role_slugs ) ) {
			// if array is returned, there are restrictions set...  Check them
			foreach ( $allowed_role_slugs as $allowed_role_slug ) {
				if ( current_user_can( $allowed_role_slug ) ) {
					// access allowed
					return true;
				}
			}
			// restrictions are set, but none matched user role block access
			return false;
		}

		// No restrictions on content, allow access
		return true;
	}



	/**
	 * bb-restricted-content Short Code processor
	 *
	 * @param $atts
	 * @param $content
	 *
	 * @return string
	 */
	function restrict_page_content( $atts = array(), $content = '' ) {

		$args = shortcode_atts(
			array(
				'role_slugs' => '',
			),
			$atts,
		);

		if ( ! empty( $args['role_slugs'] ) ) {
			$args['role_slugs'] = str_replace( ' ', '', $args['role_slugs'] );
			$role_slugs         = explode( ',', $args['roles'] );

			if ( $this->can_user_access( $role_slugs ) ) {
				return $content;
			}
		}

		return '';
	}


	/**
	 * Setup taxonomy for access restriction tagging
	 *  -> checks that roles are set as terms
	 */
	function setup_access_restrictions_taxonomy() {

		register_taxonomy(
			'access_restrictions',
			$this->restricted_content_types,
			array(
				'capabilities'      => array(
					'manage_terms' => '',
					'edit_terms'   => '',
					'delete_terms' => '',
					'assign_terms' => 'edit_posts',
				),
				'labels'            => array(
					'name'         => 'Restrict Content Access',
					'search_items' => 'Search Roles',
				),
				'public'            => true,
				'show_admin_column' => true,
				'show_in_nav_menus' => false,
				'show_tagcloud'     => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
			)
		);

		// get all roles from role wp object
		$roles = wp_roles()->roles;

		// build array of roles already set as terms for access_restrictions cpt
		$terms = get_terms(
			array(
				'taxonomy'   => 'access_restrictions',
				'hide_empty' => false,
			)
		);
		//$term_role_slugs = array();
		//foreach ( $terms as $key => $term_meta ) {
		//	$term_role_slugs[ $term_meta->term_id ] = $term_meta->slug;
		//}
		$term_role_slugs = wp_list_pluck( $terms, 'slug', 'term_id' );

		// check that role is set as term for access_restrictions
		$role_slugs = array();
		foreach ( $roles as $role_slug => $role_meta ) {
			$role_slugs[] = $role_slug;
			if ( false === in_array( $role_slug, $term_role_slugs, true ) ) {
				wp_insert_term(
					$role_meta['name'],
					'access_restrictions',
					array(
						'slug' => $role_slug,
					)
				);
			}
		}

		// check that terms are current roles - otherwise remove
		// BLOOT should we just hide from meta box instead of removing?
		foreach ( $term_role_slugs as $term_id => $term_role_slug ) {
			if ( false === in_array( $term_role_slug, $role_slugs, true ) ) {
				wp_delete_term( $term_id, 'access_restrictions' );
			}
		}
	}


	/**
	 * Setup CPT for Restricted Content Templates
	 *  -> coded setup allow fine control on access capabilities not possible with plugins like Toolset.
	 *
	 */
	function register_restricted_content_template_cpt() {

		$args = array(
			'label'                 => __( 'Restricted Content Templates', 'r3df-beaver-builder-restricted-content' ),
			'labels'                => array(
				'name'          => __( 'Restricted Content Templates', 'r3df-beaver-builder-restricted-content' ),
				'singular_name' => __( 'Restricted Content Template', 'r3df-beaver-builder-restricted-content' ),
				'all_items'     => __( 'All Items', 'r3df-beaver-builder-restricted-content' ),
				'menu_name'     => __( 'BB Restricted Content', 'r3df-beaver-builder-restricted-content' ),
			),
			'description'           => '',
			'public'                => false,
			'publicly_queryable'    => true,
			'show_ui'               => true,
			'show_in_rest'          => true,
			'rest_base'             => '',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'has_archive'           => false,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'delete_with_user'      => false,
			'exclude_from_search'   => true,
			'capability_type'       => 'post',
			'capabilities'          => array(
				'create_posts'       => 'create_rc_templates',
				'edit_post'          => 'edit_rc_template',
				'edit_posts'         => 'edit_rc_templates',
				'edit_others_posts'  => 'edit_other_rc_templates',
				'publish_posts'      => 'publish_rc_templates',
				'read_post'          => 'read_rc_template',
				'read_private_posts' => 'read_private_rc_templates',
				'delete_post'        => 'delete_rc_template',
			),
			'map_meta_cap'          => false,
			'hierarchical'          => true,
			'rewrite'               => array(
				'slug'       => 'r3df-rc-template',
				'with_front' => false,
			),
			'query_var'             => true,
			'menu_position'         => 40,
			'menu_icon'             => 'dashicons-hidden',
			'supports'              => array(
				'editor',
				'revisions',
				'author',
			),
		);

		register_post_type( 'r3df-rc-template', $args );


		// Maybe load default content
		$content_loaded = false;
		if ( ! get_page_by_path( 'wp-default-template', OBJECT, 'r3df-rc-template' ) ) {
			// Setup content for WP page
			$args = array(
				'post_name'      => 'wp-default-template',
				'post_title'     => 'WP Default Template',
				//'post_parent'    => $terms->post_parent,
				'post_status'    => 'publish',
				'post_type'      => 'r3df-rc-template',
				'post_author'    => get_current_user_id(),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_content'   => '<!-- wp:paragraph --><p>This content is restricted, please contact the site administrators to gain access.</p><!-- /wp:paragraph -->',
			);

			$default_wp_template = wp_insert_post( $args );
			$content_loaded      = true;
		}

		if ( ! get_page_by_path( 'bb-default-template', OBJECT, 'r3df-rc-template' ) ) {
			// Setup content for BB page
			$args = array(
				'post_name'      => 'bb-default-template',
				'post_title'     => 'BB Default Template',
				//'post_parent'    => $terms->post_parent,
				'post_status'    => 'publish',
				'post_type'      => 'r3df-rc-template',
				'post_author'    => get_current_user_id(),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_content'   => '<!-- wp:fl-builder/layout --><p>This content is restricted, please contact the site administrators to gain access.</p><!-- /wp:fl-builder/layout -->',
			);

			$default_bb_template = wp_insert_post( $args );
			// Enable BB on post
			if ( $default_bb_template ) {
				update_post_meta( $default_bb_template, '_fl_builder_enabled', true );
			}
			$content_loaded = true;
		}

		if ( ! get_page_by_path( 'access-not-permitted', OBJECT, 'r3df-rc-template' ) ) {
			// Setup content for BB page
			$args = array(
				'post_name'      => 'access-not-permitted',
				'post_title'     => 'Access not permitted',
				//'post_parent'    => $terms->post_parent,
				'post_status'    => 'publish',
				'post_type'      => 'r3df-rc-template',
				'post_author'    => get_current_user_id(),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_content'   => '<!-- wp:fl-builder/layout --><p>This content is restricted, please contact the site administrators to gain access.</p><!-- /wp:fl-builder/layout -->',
			);

			$default_template = wp_insert_post( $args );
			// Enable BB on post
			if ( $default_template ) {
				update_post_meta( $default_template, '_fl_builder_enabled', true );
			}
			$content_loaded = true;
		}

		// flush rewrite rules if we load content - simple way to limit update, but still make sure it gets done
		//  -> we should only ever load the default content once, so this should only happen once...
		if ( $content_loaded ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Add default capabilities to "default restricted content templates" cpt
	 *
	 */
	function add_rc_template_default_caps() {
		// BLOOT move to activation/deactivation
		// BLOOT remove unused defaults...
		// Manage capabilities for default restricted content templates
		// Only apply once - cap is saved in DB, so no need to redo every load.
		global $wp_roles;
		// Remove read from all roles
		$this->remove_rc_template_read_caps( array_keys( $wp_roles->roles ) );
		// Remove create from all roles
		$this->remove_rc_template_create_caps( array_keys( $wp_roles->roles ) );
		// Remove edit from all roles
		$this->remove_rc_template_edit_caps( array_keys( $wp_roles->roles ) );
		// Remove delete from all roles
		$this->remove_rc_template_delete_caps( array_keys( $wp_roles->roles ) );

		// Add read
		$this->add_rc_template_read_caps( $this->add_read_rc_template_caps );
		// Add create
		$this->add_rc_template_create_caps( $this->add_create_rc_template_caps );
		// Add edit
		$this->add_rc_template_edit_caps( $this->add_edit_rc_template_caps );
		// Add delete
		$this->add_rc_template_delete_caps( $this->add_delete_rc_template_caps );
	}

	/**
	 * Add "default restricted content templates" read capabilities to an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function add_rc_template_read_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->add_cap( 'read_rc_template' );
			$role_object->add_cap( 'read_private_rc_templates' );
		}
	}

	/**
	 * Add "default restricted content templates" create capabilities to an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function add_rc_template_create_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->add_cap( 'create_rc_templates' );
		}
	}

	/**
	 * Add "default restricted content templates" edit capabilities to an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function add_rc_template_edit_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->add_cap( 'edit_rc_template' );
			$role_object->add_cap( 'edit_rc_templates' );
			$role_object->add_cap( 'edit_other_rc_templates' );
			$role_object->add_cap( 'publish_rc_templates' );
		}
	}

	/**
	 * Add "default restricted content templates" delete capabilities to an object
	 *   - can be user_id or role
	 *
	 * NOTE: default restricted content templates should NEVER be deleted.
	 * This capability should not generally be added to any users
	 *
	 * @param $objects
	 */
	function add_rc_template_delete_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->add_cap( 'delete_rc_template' );
		}
	}

	/**
	 * Remove "default restricted content templates" read capabilities from an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function remove_rc_template_read_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->remove_cap( 'read_rc_template' );
			$role_object->remove_cap( 'read_private_rc_templates' );
		}
	}

	/**
	 * Remove "default restricted content templates" create capabilities from an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function remove_rc_template_create_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->remove_cap( 'create_rc_templates' );
		}
	}

	/**
	 * Remove "default restricted content templates" edit capabilities from an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function remove_rc_template_edit_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->remove_cap( 'edit_rc_template' );
			$role_object->remove_cap( 'edit_rc_templates' );
			$role_object->remove_cap( 'edit_other_rc_templates' );
			$role_object->remove_cap( 'publish_rc_templates' );
		}
	}

	/**
	 * Remove "default restricted content templates" delete capabilities from an object
	 *   - can be user_id or role
	 *
	 * @param $objects
	 */
	function remove_rc_template_delete_caps( $objects ) {
		foreach ( $objects as $object ) {
			if ( is_int( $object ) || ctype_digit( $object ) ) {
				$role_object = new WP_User( $object );
			} else {
				$role_object = get_role( $object );
			}
			$role_object->remove_cap( 'delete_rc_template' );
		}
	}


	/**
	 * Add cpt's to BB post types list
	 *
	 * @param $post_types
	 *
	 * @return array
	 */
	function add_custom_post_types_to_bb( $post_types ) {
		$post_types[] = 'r3df-rc-template';
		return $post_types;
	}

	/**
	 * Hide the cpt's from the BB admin so they can't be unselected
	 *
	 * @param $post_types
	 *
	 * @return mixed
	 */
	function hide_custom_post_types_in_bb_admin( $post_types ) {
		unset( $post_types['r3df-rc-template'] );
		return $post_types;
	}

	/**
	 * Full width Beaver template for CPT
	 *
	 * @param string $template
	 *
	 * @return string
	 */
	function full_width_template( $template ) {

		// check for template overrides
		if ( locate_template( array( 'single.php' ) ) !== $template ) {
			return $template;
		}

		if ( 'r3df-rc-template' === get_post_type() ) {
			$full_template = locate_template( array( 'tpl-full-width.php' ) );

			// check if we can find this template
			if ( ! empty( $full_template ) ) {
				$template = $full_template;
			}
		}

		return $template;
	}


	/*****************************************************
	 * Settings Page & Functions
	 *****************************************************
	 */

	/**
	 * Settings page instantiation
	 *
	 */
	function register_settings_page() {
		add_submenu_page( 'edit.php?post_type=r3df-rc-template', 'Restricted Content Settings', 'Settings', 'manage_options', 'r3df-bbrc_settings', array( $this, 'settings_page' ) );
	}

	/**
	 * Settings page main function
	 *
	 */
	function settings_page() {
		?>
		<div class="wrap">
			<h2>Beaver Builder Restricted Content Settings</h2>
			<form action="options.php" method="post">
				<?php settings_fields( 'r3df-bbrc' ); //Must match register_setting first parameter ?>
				<?php do_settings_sections( 'r3df-bbrc' ); //Must match add_settings_section last parameter & add_settings_field 4th parameter ?>
				<input class="button button-primary" name="Submit" type="submit" value="<?php esc_attr_e( 'Save Changes', 'r3df-beaver-builder-restricted-content' ); ?>"/>
			</form>
		</div>
	<?php }

	/**
	 * Register settings
	 *
	 */
	function register_settings() {
		$section_title = 'General Settings:';
		add_settings_section( 'r3df-bbrc' . '_general_settings', $section_title, array( $this, 'render_html_main_section_text' ), 'r3df-bbrc' );
		add_settings_field( 'admin-roles', 'Admin roles:', array( $this, 'render_html_admin_roles' ), 'r3df-bbrc', 'r3df-bbrc' . '_general_settings' );
		add_settings_field( 'content-types', 'Content types:', array( $this, 'render_html_content_types' ), 'r3df-bbrc', 'r3df-bbrc' . '_general_settings' );
		add_settings_field( 'read-permissions', 'Read Permissions:', array( $this, 'render_html_read_permissions' ), 'r3df-bbrc', 'r3df-bbrc' . '_general_settings' );
		add_settings_field( 'edit-permissions', 'Edit Permissions:', array( $this, 'render_html_edit_permissions' ), 'r3df-bbrc', 'r3df-bbrc' . '_general_settings' );

		// Set option in db
		register_setting( 'r3df-bbrc', 'r3df_bb_restricted_content', array( 'sanitize_callback' => array( $this, 'options_validate' ) ) );
	}

	/**
	 * Validate settings
	 *
	 * @param $input
	 *
	 * @return array
	 */
	function options_validate( $input ) {
		$newinput = array();

		// Block second pass - occurs when no option exists - https://core.trac.wordpress.org/ticket/21989
		// Initial update_option goes to add_option, validate called 2x
		if ( ! empty( $input['processed'] ) ) {
			return $input;
		}
		if ( ! get_option( 'r3df_bb_restricted_content' ) ) {
			$newinput['processed'] = true;
		}

		// Process settings from user
		$newinput['admin-roles'] = array();
		if ( ! empty( $input['admin-roles'] ) ) {
			foreach ( explode( ',', $input['admin-roles'] ) as $role ) {
				$newinput['admin-roles'][] = esc_html( $role );
			}
		}

		$newinput['content-types'] = array();
		if ( ! empty( $input['content-types'] ) ) {
			foreach ( explode( ',', $input['content-types'] ) as $type ) {
				$newinput['content-types'][] = esc_html( $type );
			}
		}

		$newinput['read-permissions'] = array();
		if ( ! empty( $input['read-permissions'] ) ) {
			foreach ( explode( ',', $input['read-permissions'] ) as $object ) {
				$newinput['read-permissions'][] = esc_html( $object );
			}
		}

		$newinput['edit-permissions'] = array();
		if ( ! empty( $input['edit-permissions'] ) ) {
			foreach ( explode( ',', $input['edit-permissions'] ) as $object ) {
				$newinput['edit-permissions'][] = esc_html( $object );
			}
		}

		// Update permissions...
		$options = get_option( 'r3df_bb_restricted_content' );
		if ( ! is_array( $options['read-permissions'] ) ) {
			$options['read-permissions'] = $this->add_read_rc_template_caps;
		}
		$objects_add = array_diff( $newinput['read-permissions'], $options['read-permissions'] );
		if ( ! empty( $objects_add ) ) {
			$this->add_rc_template_read_caps( $objects_add );
		}
		$objects_remove = array_diff( $options['read-permissions'], $newinput['read-permissions'] );
		if ( ! empty( $objects_remove ) ) {
			$this->remove_rc_template_read_caps( $objects_remove );
		}
		if ( ! is_array( $options['edit-permissions'] ) ) {
			$options['edit-permissions'] = $this->add_read_rc_template_caps;
		}
		$objects_add = array_diff( $newinput['edit-permissions'], $options['edit-permissions'] );
		if ( ! empty( $objects_add ) ) {
			$this->add_rc_template_edit_caps( $objects_add );
		}
		$objects_remove = array_diff( $options['edit-permissions'], $newinput['edit-permissions'] );
		if ( ! empty( $objects_remove ) ) {
			$this->remove_rc_template_edit_caps( $objects_remove );
		}

		return $newinput;
	}

	/**
	 * Render settings html
	 *
	 */
	function render_html_main_section_text() {
		//if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
		//	echo '<p style="color:#008000;font-weight:bold;">NOTE: These settings apply to all languages.</p>';
		//}
	}

	function render_html_admin_roles() {
		$options = get_option( 'r3df_bb_restricted_content' );

		$default = 'administrator';

		echo '<input id="admin-roles" name="' . 'r3df_bb_restricted_content' . '[admin-roles]" size="100" type="text" value="' . ( isset( $options['admin-roles'] ) ? esc_textarea( join( ',', $options['admin-roles'] ) ) : $default ) . '">';
	}

	function render_html_content_types() {
		$options = get_option( 'r3df_bb_restricted_content' );

		$default = 'page,post';

		echo '<input id="content-types" name="' . 'r3df_bb_restricted_content' . '[content-types]" size="100" type="text" value="' . ( isset( $options['content-types'] ) ? esc_textarea( join( ',', $options['content-types'] ) ) : $default ) . '">';
	}


	function render_html_read_permissions() {
		$options = get_option( 'r3df_bb_restricted_content' );

		$default = 'administrator';

		echo '<input id="read-permissions" name="' . 'r3df_bb_restricted_content' . '[read-permissions]" size="100" type="text" value="' . ( isset( $options['read-permissions'] ) ? esc_textarea( join( ',', $options['read-permissions'] ) ) : $default ) . '">';
	}

	function render_html_edit_permissions() {
		$options = get_option( 'r3df_bb_restricted_content' );

		$default = 'administrator';

		echo '<input id="edit-permissions" name="' . 'r3df_bb_restricted_content' . '[edit-permissions]" size="100" type="text" value="' . ( isset( $options['edit-permissions'] ) ? esc_textarea( join( ',', $options['edit-permissions'] ) ) : $default ) . '">';
	}


	/*****************************************************
	 * Utility functions
	 *****************************************************
	 */

}

