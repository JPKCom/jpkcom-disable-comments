<?php
/*
Plugin Name: JPKCom Disable Comments
Plugin URI: https://github.com/JPKCom/jpkcom-disable-comments
Description: Globally disable comments functionality.
Version: 1.0.1
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Comments, Plugin
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 1.0.1
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: JPKCom/jpkcom-disable-comments
Primary Branch: main
*/

if ( ! defined( constant_name: 'WPINC' ) ) {
  die;
}

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

