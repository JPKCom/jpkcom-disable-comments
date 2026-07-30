<?php
/*
Plugin Name: JPKCom Disable Comments
Plugin URI: https://github.com/JPKCom/jpkcom-disable-comments
Description: Globally disable comments functionality.
Version: 1.0.9
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Comments, Plugin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.9
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
  die;
}


/**
 * Plugin Constants
 *
 * @since 1.0.2
 */
if ( ! defined( 'JPKCOM_DISABLE_COMMENTS_VERSION' ) ) {
    define( 'JPKCOM_DISABLE_COMMENTS_VERSION', '1.0.9' );
}


/**
 * Initialize Plugin Updater
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 1.0.2
 *
 * @return void
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

if ( ! function_exists( function: 'jpkcom_disable_comments_close_rest_status' ) ) {
    /**
     * Report comments and pings as closed in a REST post response.
     *
     * Removing post type support is not enough for `post`, `page` and
     * `attachment`: `WP_REST_Posts_Controller::get_item_schema()` carries a
     * hardcoded `$fixed_schemas` list for those three in which `comments` is
     * always present - the `post_type_supports()` check applies only to other
     * post types. The schema therefore keeps `comment_status` and `ping_status`,
     * and the response kept reporting the stored value, typically `open`, on a
     * site that refuses comments.
     *
     * The fields are rewritten rather than removed, so the block editor and any
     * other consumer keep the response shape they expect.
     *
     * A REST write can still store `comment_status: open` - the schema stays
     * writable - but the value is inert: `comments_open` is filtered to false,
     * and this filter reports the field as closed regardless.
     *
     * @since 1.0.9
     *
     * @param mixed $response Prepared response, a WP_REST_Response in practice.
     * @return mixed The response with both status fields set to closed.
     */
    function jpkcom_disable_comments_close_rest_status( $response ) {
        if ( ! $response instanceof WP_REST_Response ) {
            return $response;
        }

        $data = $response->get_data();

        if ( ! is_array( $data ) ) {
            return $response;
        }

        foreach ( array( 'comment_status', 'ping_status' ) as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $data[ $field ] = 'closed';
            }
        }

        $response->set_data( $data );

        return $response;
    }
}

/**
 * Apply the REST status rewrite to every post type.
 *
 * Registered on rest_api_init because the filter name is per post type and all
 * post types are registered by then.
 *
 * @since 1.0.9
 *
 * @return void
 */
add_action( 'rest_api_init', static function (): void {
    foreach ( get_post_types() as $post_type ) {
        add_filter( 'rest_prepare_' . $post_type, 'jpkcom_disable_comments_close_rest_status', PHP_INT_MAX );
    }
} );

/**
 * Remove comment and trackback support from every post type.
 *
 * Bound to `wp_loaded`, not `admin_init`. Every post type is registered by then
 * because `init` has completed (`wp-settings.php` fires `init`, then
 * `wp_loaded`), while both `admin_init` and `rest_api_init` fire later. On
 * `admin_init` the removal never reached REST requests, so `/wp/v2/posts` kept
 * reporting `comment_status: open` and the REST schema kept accepting writes to
 * it - the runtime block came solely from the `comments_open` filter below.
 *
 * The two supports are checked independently: a post type may declare
 * `trackbacks` without `comments`, and nesting the second check inside the
 * first left those untouched.
 *
 * @since 1.0.0
 * @since 1.0.9 Moved from admin_init to wp_loaded; supports checked separately.
 *
 * @return void
 */
add_action( 'wp_loaded', static function (): void {
    foreach ( get_post_types() as $post_type ) {
        if ( post_type_supports( $post_type, 'comments' ) ) {
            remove_post_type_support( $post_type, 'comments' );
        }

        if ( post_type_supports( $post_type, 'trackbacks' ) ) {
            remove_post_type_support( $post_type, 'trackbacks' );
        }
    }
} );

/**
 * Redirect the comments screen to the dashboard.
 *
 * @since 1.0.0
 *
 * @return void
 */
add_action( 'admin_init', static function (): void {
    global $pagenow;

    if ( $pagenow === 'edit-comments.php' ) {
        wp_safe_redirect( admin_url() );
        exit;
    }
} );

/**
 * Force comments and pings closed and return an empty comments array.
 *
 * PHP_INT_MAX so that a theme or plugin registering a later filter cannot
 * re-open them.
 *
 * @since 1.0.0
 * @since 1.0.9 Raised from priority 20 to PHP_INT_MAX.
 */
add_filter( 'comments_open', '__return_false', PHP_INT_MAX, 2 );
add_filter( 'pings_open', '__return_false', PHP_INT_MAX, 2 );
add_filter( 'comments_array', '__return_empty_array', PHP_INT_MAX, 2 );

/**
 * Report zero comments everywhere.
 *
 * The dashboard's "At a Glance" widget builds its comment line from
 * `wp_count_comments()`; with a non-zero count it rendered a link to
 * `edit-comments.php`, which this plugin redirects away - a dead end.
 *
 * @since 1.0.9
 *
 * @param array|object $counts  Counts assembled so far, empty by default.
 * @param int|string   $post_id Post ID the counts were requested for.
 * @return object Zeroed count object in the shape core expects.
 */
add_filter( 'wp_count_comments', static function ( $counts, $post_id ): object {
    return (object) array(
        'approved'       => 0,
        'moderated'      => 0,
        'spam'           => 0,
        'trash'          => 0,
        'post-trashed'   => 0,
        'total_comments' => 0,
    );
}, PHP_INT_MAX, 2 );

/**
 * Short-circuit every comment query.
 *
 * `comments_array` only covers the template path. The dashboard Activity widget
 * queries comments straight through `WP_Comment_Query`, which is why the full
 * moderation list - including the all/pending/approved/spam/trash filter bar -
 * still appeared there while comments were supposedly disabled.
 *
 * Returns `0` for counting queries and an empty array otherwise, which is what
 * `WP_Comment_Query` expects from this filter.
 *
 * @since 1.0.9
 *
 * @param array|int|null   $comment_data Short-circuit value, null by default.
 * @param WP_Comment_Query $query        The query instance.
 * @return array|int Empty result set.
 */
add_filter( 'comments_pre_query', static function ( $comment_data, WP_Comment_Query $query ) {
    return empty( $query->query_vars['count'] ) ? array() : 0;
}, PHP_INT_MAX, 2 );

/**
 * Drop the comment feeds.
 *
 * Comment feeds are assembled by `WP_Query` directly via `$wpdb` (only the
 * `comment_feed_*` filters apply), so they bypass both `comments_array` and
 * `comments_pre_query`. Approved comments therefore stayed publicly readable at
 * `/comments/feed/` and `<post>/feed/` after comments had been switched off.
 *
 * `feed_links_show_comments_feed` removes the discovery links, the
 * `template_redirect` guard stops the URLs from being served at all. The regular
 * post feed at `/feed/` is unaffected - `is_comment_feed()` is false there.
 *
 * @since 1.0.9
 *
 * @return void
 */
add_filter( 'feed_links_show_comments_feed', '__return_false' );

add_action( 'template_redirect', static function (): void {
    if ( is_comment_feed() ) {
        wp_die(
            esc_html__( 'Comments are disabled.', 'jpkcom-disable-comments' ),
            esc_html__( 'Not Found', 'jpkcom-disable-comments' ),
            array( 'response' => 404 )
        );
    }
}, 0 );

/**
 * Remove the Comments entry from the admin menu.
 *
 * @since 1.0.0
 *
 * @return void
 */
add_action( 'admin_menu', static function (): void {
    remove_menu_page( 'edit-comments.php' );
} );

/**
 * Remove the Comments node from the admin bar.
 *
 * @since 1.0.0
 *
 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
 * @return void
 */
add_action( 'admin_bar_menu', static function ( WP_Admin_Bar $wp_admin_bar ): void {
    $wp_admin_bar->remove_node( 'comments' );
}, 999 );

/**
 * Remove the REST API comment endpoints.
 *
 * @since 1.0.0
 *
 * @param array $endpoints The registered REST API endpoints.
 * @return array The endpoints without comment routes.
 */
add_filter( 'rest_endpoints', static function ( array $endpoints ): array {

    if ( isset( $endpoints['/wp/v2/comments'] ) ) {
        unset( $endpoints['/wp/v2/comments'] );
    }

    foreach ( array_keys( $endpoints ) as $route ) {
        if ( str_starts_with( haystack: $route, needle: '/wp/v2/comments/' ) ) {
            unset( $endpoints[ $route ] );
        }
    }

    return $endpoints;
} );
