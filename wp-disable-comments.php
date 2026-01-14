<?php

/*
 * Plugin Name:     Disable Comments
 * Plugin URI:      https://github.com/wvnderlab-agency/wp-disable-comments/
 * Author:          Wvnderlab Agency
 * Author URI:      https://wvnderlab.com
 * Text Domain:     wvnderlab-disable-comments
 * Version:         0.1.1
 */

/*
 *  ################
 *  ##            ##    Copyright (c) 2025 Wvnderlab Agency
 *  ##
 *  ##   ##  ###  ##    ✉️ moin@wvnderlab.com
 *  ##    #### ####     🔗 https://wvnderlab.com
 *  #####  ##  ###
 */

declare(strict_types=1);

namespace WvnderlabAgency\DisableComments;

use WP_Admin_Bar;
use WP_Comment;

defined( 'ABSPATH' ) || die;

// Return early if running in WP-CLI context.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}

/**
 * Filter: Disable Comments Enabled
 *
 * @param bool $enabled Whether to enable the disable comments functionality. Default true.
 * @return bool
 */
if ( ! apply_filters( 'wvnderlab/disable-comments/enabled', true ) ) {
	return;
}

// Close Comments Sitewide.
add_filter( 'comments_open', '__return_false', PHP_INT_MAX );
// Close Pings Sitewide.
add_filter( 'pings_open', '__return_false', PHP_INT_MAX );
// Remove Comments from RSS Feeds.
add_action( 'do_feed_rss2_comments', '__return_false', PHP_INT_MAX );
add_action( 'do_feed_atom_comments', '__return_false', PHP_INT_MAX );
add_filter( 'feed_links_show_comments_feed', '__return_false', PHP_INT_MAX );
// Remove Comments from REST API.
add_filter( 'rest_comment_collection_params', '__return_empty_array', PHP_INT_MAX );
add_filter( 'rest_prepare_comment', '__return_null', PHP_INT_MAX );

/**
 * Clear Comments Array
 *
 * @link   https://developer.wordpress.org/reference/hooks/comments_array/
 * @hooked filter comments_array
 *
 * @param array<int,WP_Comment> $comments The comments array.
 * @return array
 */
function clear_comments_array( array $comments ): array {

	return is_admin()
		? $comments
		: array();
}

add_filter( 'comments_array', __NAMESPACE__ . '\\clear_comments_array' );

/**
 * Close Comment and Ping Status on Post Insert
 *
 * @link   https://developer.wordpress.org/reference/hooks/wp_insert_post/
 * @hooked action wp_insert_post
 *
 * @param int $post_id The post ID.
 * @return void
 */
function close_comment_and_ping_status( int $post_id ): void {
	if ( wp_is_post_revision( $post_id ) ) {

		return;
	}

	$post = get_post( $post_id );

	// return early if comments and pings are already closed.
	if ( $post && 'closed' === $post->comment_status && 'closed' === $post->ping_status ) {

		return;
	}

	// temporarily remove this action to avoid infinite loop.
	remove_action( 'wp_insert_post', __NAMESPACE__ . '\\close_comment_and_ping_status', PHP_INT_MAX );

	wp_update_post(
		array(
			'ID'             => $post_id,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		)
	);

	// re-add the action.
	add_action( 'wp_insert_post', __NAMESPACE__ . '\\close_comment_and_ping_status', PHP_INT_MAX );
}

add_action( 'wp_insert_post', __NAMESPACE__ . '\\close_comment_and_ping_status', PHP_INT_MAX );

/**
 * Disable or redirects any comments page requests.
 *
 * @link   https://developer.wordpress.org/reference/hooks/template_redirect/
 * @hooked action template_redirect
 *
 * @return void
 */
function disable_or_redirect_comments_feed(): void {
	// return early if not a comments feed.
	if ( ! is_comment_feed() ) {
		return;
	}

	// return early if in admin, ajax, cron, rest api or wp-cli context.
	if (
		is_admin()
		|| wp_doing_ajax()
		|| wp_doing_cron()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
	) {
		return;
	}

	/**
	 * Filter: Disable Comments Feed Status Code
	 *
	 * Supported:
	 * - 301 / 302 / 307 / 308  → redirect
	 * - 404 / 410              → no redirect, proper error response
	 *
	 * @param int $status_code The HTTP status code for the redirect. Default is 404 (Not Found).
	 */
	$status_code = (int) apply_filters(
		'wvnderlab/disable-comments/status-code',
		404
	);

	// Handle 404 and 410 status codes separately.
	if ( in_array( $status_code, array( 404, 410 ), true ) ) {
		global $wp_query;

		$wp_query->set_404();
		status_header( $status_code );
		nocache_headers();

		$template = get_query_template( '404' );

		if ( $template ) {
			include $template;
		} else {
			wp_die(
				esc_html__( '404 Not Found', 'wvnderlab-disable-comments' ),
				esc_html__( 'Not Found', 'wvnderlab-disable-comments' ),
				array( 'response' => esc_html( $status_code ) )
			);
		}

		exit;
	}

	// Ensure the status code is a valid redirect code.
	if ( $status_code < 300 || $status_code > 399 ) {
		$status_code = 301;
	}

	/**
	 * Filter: Disable Comments Feed Redirect URL
	 *
	 * Allows modification of the redirect URL for disabled comment feeds.
	 *
	 * @param string $redirect_url The URL to redirect to. Default is the homepage.
	 */
	$redirect_url = (string) apply_filters(
		'wvnderlab/disable-comments/redirect-url',
		home_url()
	);

	// Ensure the redirect URL is not empty.
	if ( empty( $redirect_url ) ) {
		$redirect_url = home_url();
	}

	wp_safe_redirect( $redirect_url, $status_code );

	exit;
}

add_action( 'template_redirect', __NAMESPACE__ . '\\disable_or_redirect_comments_feed', PHP_INT_MIN );

/**
 * Redirect Comments Templates
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_init/
 * @hooked action admin_init
 *
 * @return void
 * @global string $pagenow The current admin page.
 */
function redirect_comments_templates(): void {
	global $pagenow;

	if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php' ), true ) ) {
		$admin_url = admin_url();

		wp_safe_redirect( $admin_url, 301 );
		exit;
	}
}

add_action( 'admin_init', __NAMESPACE__ . '\\redirect_comments_templates' );

/**
 * Remove Comments Admin Bar Node
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_bar_menu/
 * @hooked action admin_bar_menu
 *
 * @param WP_Admin_Bar $admin_bar The admin bar object.
 * @return void
 */
function remove_comments_admin_bar_node( WP_Admin_Bar $admin_bar ): void {
	if ( is_admin_bar_showing() ) {
		$admin_bar->remove_node( 'comments' );
	}
}

add_action( 'admin_bar_menu', __NAMESPACE__ . '\\remove_comments_admin_bar_node', PHP_INT_MAX );

/**
 * Remove REST Comments Endpoint
 *
 * @link   https://developer.wordpress.org/reference/hooks/rest_endpoints/
 * @hooked filter rest_endpoints
 *
 * @param array<string,mixed> $endpoints The REST API endpoints.
 * @return array<string,mixed>
 */
function remove_comments_endpoint( array $endpoints ): array {
	if ( isset( $endpoints['/wp/v2/comments'] ) ) {
		unset( $endpoints['/wp/v2/comments'] );
	}

	return $endpoints;
}

add_filter( 'rest_endpoints', __NAMESPACE__ . '\\remove_comments_endpoint' );

/**
 * Remove Comments Menu Page
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_menu/
 * @hooked action admin_menu
 *
 * @return void
 */
function remove_comments_menu_page(): void {
	remove_menu_page( 'edit-comments.php' );
}

add_action( 'admin_menu', __NAMESPACE__ . '\\remove_comments_menu_page', PHP_INT_MAX );

/**
 * Remove Comments Metaboxes
 *
 * @link   https://developer.wordpress.org/reference/hooks/add_meta_boxes/
 * @hooked action add_meta_boxes
 *
 * @return void
 */
function remove_comments_metaboxes(): void {
	remove_meta_box( 'commentstatusdiv', null, 'normal' );
	remove_meta_box( 'commentsdiv', null, 'normal' );
}

add_action( 'add_meta_boxes', __NAMESPACE__ . '\\remove_comments_metaboxes', PHP_INT_MAX );

/**
 * Remove Comments Post Types Support
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_init/
 * @hooked action admin_init
 *
 * @return void
 */
function remove_comments_post_types_support(): void {
	$post_types = get_post_types( array( 'public' => true ) );

	foreach ( $post_types as $post_type ) {
		if ( post_type_supports( $post_type, 'comments' ) ) {
			remove_post_type_support( $post_type, 'comments' );
		}
		if ( post_type_supports( $post_type, 'trackbacks' ) ) {
			remove_post_type_support( $post_type, 'trackbacks' );
		}
	}
}

add_action( 'admin_init', __NAMESPACE__ . '\\remove_comments_post_types_support', PHP_INT_MAX );

/**
 * Remove Dashboard Recent Comments Metaboxes
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_init/
 * @hooked action admin_init
 *
 * @return void
 */
function remove_dashboard_metaboxes(): void {
	remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
}

add_action( 'admin_init', __NAMESPACE__ . '\\remove_dashboard_metaboxes', PHP_INT_MAX );

/**
 * Remove XMLRPC Comments Methods
 *
 * @link   https://developer.wordpress.org/reference/hooks/xmlrpc_methods/
 * @hooked filter xmlrpc_methods
 *
 * @param array<string,string> $methods The XMLRPC methods.
 * @return array<string,string>
 */
function remove_xmlrpc_comments_methods( array $methods ): array {
	unset(
		// WordPress API.
		$methods['wp.getComments'],
		$methods['wp.getComment'],
		$methods['wp.deleteComment'],
		$methods['wp.editComment'],
		$methods['wp.newComment']
	);

	return $methods;
}

add_filter( 'xmlrpc_methods', __NAMESPACE__ . '\\remove_xmlrpc_comments_methods', PHP_INT_MAX );

/**
 * Unregister Comment Blocks
 *
 * @link   https://developer.wordpress.org/reference/hooks/admin_print_scripts/
 * @hooked action admin_print_scripts
 *
 * @return void
 */
function unregister_comment_blocks(): void {
	$blocks = array(
		'core/comment-author-avatar',
		'core/comment-author-name',
		'core/comment-content',
		'core/comment-date',
		'core/comment-edit-link',
		'core/comment-reply-link',
		'core/comment-template',
		'core/comments',
		'core/comments-pagination',
		'core/comments-pagination-next',
		'core/comments-pagination-numbers',
		'core/comments-pagination-previous',
		'core/comments-title',
		'core/latest-comments',
		'core/post-comments-count',
		'core/post-comments-form',
		'core/post-comments-link',
	);

	echo '<script type="text/javascript">';
	echo "addEventListener('DOMContentLoaded', function() {";
	echo 'window.wp.domReady( function() {';
	foreach ( $blocks as $block ) {
		echo "window.wp.blocks.unregisterBlockType( '" . esc_js( $block ) . "' );";
	}
	echo '} );';
	echo '} );';
	echo '</script>';
}

add_action( 'admin_print_scripts', __NAMESPACE__ . '\\unregister_comment_blocks', PHP_INT_MAX );

/**
 * Unregister Comments Widget
 *
 * @link   https://developer.wordpress.org/reference/hooks/widgets_init/
 * @hooked action widgets_init
 *
 * @return void
 */
function unregister_comments_widgets(): void {
	unregister_widget( 'WP_Widget_Recent_Comments' );
}

add_action( 'widgets_init', __NAMESPACE__ . '\\unregister_comments_widgets', PHP_INT_MAX );
