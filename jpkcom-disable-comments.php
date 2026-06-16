<?php
/*
Plugin Name: JPKCom Disable Comments
Plugin URI: https://github.com/JPKCom/jpkcom-disable-comments
Description: Globally disable comments functionality.
Version: 1.0.2
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Comments, Plugin
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( constant_name: 'WPINC' ) ) {
  die;
}


/**
 * Plugin Constants
 */
if ( ! defined( 'JPKCOM_DISABLE_COMMENTS_VERSION' ) ) {
    define( 'JPKCOM_DISABLE_COMMENTS_VERSION', '1.0.2' );
}


/**
 * Initialize Plugin Updater
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 */
add_action( 'init', static function (): void {
    $updater_file = plugin_dir_path( __FILE__ ) . 'includes/class-plugin-updater.php';

    if ( file_exists( $updater_file ) ) {
        require_once $updater_file;

        if ( class_exists( 'JPKComDisableCommentsGitUpdate\\JPKComGitPluginUpdater' ) ) {
            new \JPKComDisableCommentsGitUpdate\JPKComGitPluginUpdater(
                plugin_file: __FILE__,
                current_version: JPKCOM_DISABLE_COMMENTS_VERSION,
                manifest_url: 'https://jpkcom.github.io/jpkcom-disable-comments/plugin_jpkcom-disable-comments.json'
            );
        }
    }
}, 5 );

add_action( 'admin_init', function (): void {
    global $pagenow;

    if ( $pagenow === 'edit-comments.php' ) {
        wp_safe_redirect( admin_url() );
        exit;
    }

    remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );

    foreach ( get_post_types() as $post_type ) {
        if ( post_type_supports( $post_type, 'comments' ) ) {
            remove_post_type_support( $post_type, 'comments' );
            remove_post_type_support( $post_type, 'trackbacks' );
        }
    }
} );

add_filter( 'comments_open', '__return_false', 20, 2 );
add_filter( 'pings_open', '__return_false', 20, 2 );
add_filter( 'comments_array', '__return_empty_array', 10, 2 );

add_action( 'admin_menu', function (): void {
    remove_menu_page( 'edit-comments.php' );
} );

add_action( 'admin_bar_menu', function ( WP_Admin_Bar $wp_admin_bar ): void {
    $wp_admin_bar->remove_node( 'comments' );
}, 999 );

add_filter( 'rest_endpoints', function ( array $endpoints ): array {

    if ( isset( $endpoints['/wp/v2/comments'] ) ) {
        unset( $endpoints['/wp/v2/comments'] );
    }

    foreach ( $endpoints as $route => $details ) {
        if ( str_starts_with( haystack: $route, needle: '/wp/v2/comments/' ) ) {
            unset( $endpoints[ $route ] );
        }
    }

    return $endpoints;
} );

