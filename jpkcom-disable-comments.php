<?php
/*
Plugin Name: JPKCom Disable Comments
Plugin URI: https://github.com/JPKCom/jpkcom-disable-comments
Description: Globally disable comments functionality.
Version: 1.0.11
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Comments, Plugin
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.11
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Update URI: https://github.com/JPKCom/jpkcom-disable-comments
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
    define( 'JPKCOM_DISABLE_COMMENTS_VERSION', '1.0.11' );
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

if ( ! function_exists( function: 'jpkcom_disable_comments_is_note_request' ) ) {
    /**
     * Whether a REST request on the comment routes is a block-editor Notes request.
     *
     * Notes (core since 6.9) are comments of type `note`, created and read
     * through `/wp/v2/comments`. A request counts as a note request when
     *
     * - it targets the collection route and its `type` parameter is exactly
     *   `note` - the same strict comparison core's controller makes
     *   (`'note' === $request['type']`), for GET and POST alike;
     * - it targets the item route `/wp/v2/comments/<id>` and that comment exists
     *   and is of type `note` (any method; core refuses to change a comment's
     *   type on update).
     *
     * The route is read from `get_route()` and matched the way core matches it:
     * case-insensitively, against the same `[\d]+` item pattern, and with `$`
     * accepting a trailing newline as core's `@^…$@i` does. A trailing slash is
     * tolerated as well. The ID is taken from the route because the URL
     * parameters are not populated yet on `rest_pre_dispatch`: `dispatch()`
     * matches the route only after the filter, and a batch sub-request is passed
     * with its URL parameters cleared.
     *
     * Core's own permission checks still decide who may read or write notes.
     *
     * @since 1.0.11
     *
     * @param WP_REST_Request $request The request about to be dispatched.
     * @return bool True for a note request, false for anything else.
     */
    function jpkcom_disable_comments_is_note_request( WP_REST_Request $request ): bool {
        $route = (string) $request->get_route();

        if ( 1 === preg_match( '#^/wp/v2/comments/?$#i', $route ) ) {
            return 'note' === $request->get_param( 'type' );
        }

        if ( 1 !== preg_match( '#^/wp/v2/comments/([\d]+)/?$#i', $route, $matches ) ) {
            return false;
        }

        $comment_id = (int) $matches[1];

        // get_comment() of an empty ID falls back to the global $comment.
        if ( $comment_id <= 0 ) {
            return false;
        }

        $comment = get_comment( $comment_id );

        return $comment instanceof WP_Comment && 'note' === $comment->comment_type;
    }
}

if ( ! function_exists( function: 'jpkcom_disable_comments_is_note_query' ) ) {
    /**
     * Whether a comment query asks for notes and nothing else.
     *
     * Mirrors how `WP_Comment_Query::get_comment_ids()` reads the requested
     * types: `type` and `type__in` are merged, and an empty string means "all
     * types". The query counts as a note query only when, after dropping those
     * empty entries, exactly `note` remains. `all`, `comment`, an empty type, a
     * mix such as `[ 'note', 'comment' ]` or a spelling core would not treat as a
     * note (`NOTE`, `note,comment`) do not qualify.
     *
     * @since 1.0.11
     *
     * @param WP_Comment_Query $query The query about to run.
     * @return bool True when only notes are requested.
     */
    function jpkcom_disable_comments_is_note_query( WP_Comment_Query $query ): bool {
        $types = array_merge(
            (array) ( $query->query_vars['type'] ?? '' ),
            (array) ( $query->query_vars['type__in'] ?? '' )
        );

        $types = array_values(
            array_unique(
                array_filter( $types, static fn ( mixed $type ): bool => '' !== $type )
            )
        );

        return array( 'note' ) === $types;
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
 * Queries for block-editor Notes and nothing else pass through untouched (see
 * `jpkcom_disable_comments_is_note_query()`), so the Notes panel can list notes
 * and core gets the real note count. Notes are not public: core excludes them
 * from every query that does not ask for them, and from `comment_count`.
 *
 * @since 1.0.9
 * @since 1.0.11 Note-only queries pass through.
 *
 * @param array|int|null   $comment_data Short-circuit value, null by default.
 * @param WP_Comment_Query $query        The query instance.
 * @return array|int|null Empty result set, or the incoming value for a note query.
 */
add_filter( 'comments_pre_query', static function ( $comment_data, WP_Comment_Query $query ) {
    if ( jpkcom_disable_comments_is_note_query( $query ) ) {
        return $comment_data;
    }

    return empty( $query->query_vars['count'] ) ? array() : 0;
}, PHP_INT_MAX, 2 );

/**
 * Report zero comments for every post.
 *
 * The feeds print their comment elements only when `get_comments_number() ||
 * comments_open()` (7.1.3 `feed-rss2.php:91`, `:112-114`; `feed-atom.php:98-102`),
 * so with comments closed a post that still has old approved comments kept
 * `<comments>`, `<wfw:commentRss>` and `<slash:comments>` in `/feed/` and the
 * `replies` links plus `<thr:total>` in the Atom feed - pointing at comment feeds
 * that answer 404. Themes likewise kept printing "N comments".
 *
 * Notes are unaffected: core leaves them out of `comment_count`
 * (`wp_update_comment_count_now()`, `comment.php:3138`).
 *
 * @since 1.0.11
 */
add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );

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
 * Close the REST comment routes to everything except block-editor Notes.
 *
 * Up to 1.0.10 the `/wp/v2/comments` routes were removed on `rest_endpoints`.
 * That also broke Notes, which core reads and writes through exactly those
 * routes. The routes now stay registered, and this gate answers every request
 * on them that is not a note request (see
 * `jpkcom_disable_comments_is_note_request()`) the way a missing route is
 * answered: `rest_no_route`, core's own message (default text domain, so the
 * translation is core's too) and status 404.
 *
 * A request is on the comment routes when its route is `/wp/v2/comments` or
 * lies below `/wp/v2/comments/`, compared case-insensitively as core does;
 * `?rest_route=` requests arrive with the same route. `/wp/v2/commentsfoo` and
 * other namespaces are not touched.
 *
 * PHP_INT_MAX, and a non-note request is answered even when an earlier filter
 * already produced a result - that includes core's OPTIONS handler at priority
 * 10, which would otherwise describe the route. For a note request the
 * incoming value is returned unchanged.
 *
 * The error is returned as a response, not as a `WP_Error`: in a batch request
 * core passes a `rest_pre_dispatch` result straight on to `rest_post_dispatch`,
 * where `rest_send_allow_header()` calls `get_matched_route()` on it, which a
 * `WP_Error` does not have. In a single request `dispatch()` returns the
 * response as is - the same bytes as core's own missing-route answer.
 *
 * Side effect: the route index at `/wp-json/` lists the comment routes again;
 * that is inherent to keeping them for Notes.
 *
 * @since 1.0.0
 * @since 1.0.11 Replaces the rest_endpoints removal; Notes requests pass.
 *
 * @param mixed                 $result  Response from an earlier filter, null by default.
 * @param WP_REST_Server|mixed  $server  Server instance.
 * @param WP_REST_Request|mixed $request The request about to be dispatched.
 * @return mixed The incoming value, or the 404 response.
 */
add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) {
    if ( ! $request instanceof WP_REST_Request ) {
        return $result;
    }

    if ( 1 !== preg_match( '#^/wp/v2/comments(?:/|$)#i', (string) $request->get_route() ) ) {
        return $result;
    }

    if ( jpkcom_disable_comments_is_note_request( $request ) ) {
        return $result;
    }

    return rest_convert_error_to_response(
        new WP_Error(
            'rest_no_route',
            // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string and domain, so the answer matches core's missing-route answer in every locale.
            __( 'No route was found matching the URL and request method.' ),
            array( 'status' => 404 )
        )
    );
}, PHP_INT_MAX, 3 );
