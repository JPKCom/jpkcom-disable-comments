<?php
/**
 * Regression tests for the hook surface of jpkcom-disable-comments.
 *
 * Runs standalone (no WordPress): the WordPress functions the plugin file
 * touches at load time are stubbed, the plugin file is required, and the
 * recorded callbacks are then invoked directly.
 *
 * Every case below is red against 1.0.8.
 *
 * @package JPKCom_Disable_Comments
 * @since 1.0.9
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    define( constant_name: 'WPINC', value: true );
}

/** Recorded hook registrations: $GLOBALS['jpkcom_hooks'][type][hook][] = [cb, priority]. */
$GLOBALS['jpkcom_hooks'] = array();

/** Post types the stubbed registry knows about, with their supports. */
$GLOBALS['jpkcom_supports'] = array();

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['action'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['filter'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string {
        return dirname( path: $file ) . DIRECTORY_SEPARATOR;
    }
}

if ( ! function_exists( function: 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( function: '__return_false' ) ) {
    function __return_false(): bool {
        return false;
    }
}

if ( ! function_exists( function: '__return_empty_array' ) ) {
    function __return_empty_array(): array {
        return array();
    }
}

if ( ! function_exists( function: 'get_post_types' ) ) {
    function get_post_types(): array {
        return array_keys( $GLOBALS['jpkcom_supports'] );
    }
}

if ( ! function_exists( function: 'post_type_supports' ) ) {
    function post_type_supports( string $post_type, string $feature ): bool {
        return ! empty( $GLOBALS['jpkcom_supports'][ $post_type ][ $feature ] );
    }
}

if ( ! function_exists( function: 'remove_post_type_support' ) ) {
    function remove_post_type_support( string $post_type, string $feature ): void {
        unset( $GLOBALS['jpkcom_supports'][ $post_type ][ $feature ] );
    }
}

if ( ! class_exists( class: 'WP_Comment_Query' ) ) {
    class WP_Comment_Query {
        /** @var array<string,mixed> */
        public array $query_vars = array();
    }
}

if ( ! class_exists( class: 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        /** @var mixed */
        private mixed $data;

        public function __construct( mixed $data = null ) {
            $this->data = $data;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function set_data( mixed $data ): void {
            $this->data = $data;
        }
    }
}

require_once dirname( path: __DIR__ ) . '/jpkcom-disable-comments.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

/**
 * Fetch the registered callbacks for a hook.
 *
 * @param string $type action|filter
 * @param string $hook Hook name.
 * @return array<int,array{0:callable,1:int}>
 */
function jpkcom_hooked( string $type, string $hook ): array {
    return $GLOBALS['jpkcom_hooks'][ $type ][ $hook ] ?? array();
}

echo "jpkcom-disable-comments: hook regressions\n";

/*
 * 1.0.8 removed comment support on admin_init, which never runs for REST
 * requests - /wp/v2/posts kept reporting comment_status "open".
 */
jpkcom_check(
    'comment support is removed on wp_loaded, not admin_init',
    jpkcom_hooked( 'action', 'wp_loaded' ) !== array(),
    'nothing registered on wp_loaded'
);

$admin_init_callbacks = jpkcom_hooked( 'action', 'admin_init' );
jpkcom_check(
    'admin_init carries only the comments-screen redirect',
    count( $admin_init_callbacks ) === 1,
    sprintf( '%d callbacks on admin_init', count( $admin_init_callbacks ) )
);

/*
 * 1.0.8 nested the trackbacks removal inside the comments check, so a post type
 * declaring trackbacks without comments kept them.
 */
$GLOBALS['jpkcom_supports'] = array(
    'post'      => array( 'comments' => true, 'trackbacks' => true, 'editor' => true ),
    'pingsonly' => array( 'trackbacks' => true ),
    'page'      => array( 'editor' => true ),
);

foreach ( jpkcom_hooked( 'action', 'wp_loaded' ) as $entry ) {
    $entry[0]();
}

jpkcom_check(
    'removes comments support',
    ! post_type_supports( 'post', 'comments' )
);
jpkcom_check(
    'removes trackbacks support alongside comments',
    ! post_type_supports( 'post', 'trackbacks' )
);
jpkcom_check(
    'removes trackbacks from a post type that has no comments support',
    ! post_type_supports( 'pingsonly', 'trackbacks' ),
    'nested guard skipped it'
);
jpkcom_check(
    'leaves unrelated supports alone',
    post_type_supports( 'post', 'editor' ) && post_type_supports( 'page', 'editor' )
);

/* 1.0.8 used priority 20, which a later filter could override. */
foreach ( array( 'comments_open', 'pings_open', 'comments_array' ) as $hook ) {
    $entries = jpkcom_hooked( 'filter', $hook );
    jpkcom_check(
        sprintf( '%s is filtered at PHP_INT_MAX', $hook ),
        $entries !== array() && $entries[0][1] === PHP_INT_MAX,
        $entries === array() ? 'not registered' : sprintf( 'priority %d', $entries[0][1] )
    );
}

/*
 * 1.0.8 called remove_meta_box( 'dashboard_recent_comments', ... ) on
 * admin_init. That widget has not existed since WP 3.8 and admin_init runs
 * before wp_dashboard_setup() anyway, so the dashboard kept showing the comment
 * count and the full moderation list.
 */
$count_entries = jpkcom_hooked( 'filter', 'wp_count_comments' );
jpkcom_check(
    'wp_count_comments is filtered',
    $count_entries !== array(),
    'At a Glance would still render a comment count'
);

if ( $count_entries !== array() ) {
    $counts = $count_entries[0][0]( array(), 0 );
    $zeroed = is_object( $counts );

    foreach ( array( 'approved', 'moderated', 'spam', 'trash', 'post-trashed', 'total_comments' ) as $field ) {
        $zeroed = $zeroed && isset( $counts->$field ) && $counts->$field === 0;
    }

    jpkcom_check( 'wp_count_comments reports every bucket as zero', $zeroed );
    jpkcom_check(
        'the count object is truthy so core does not fall through to its own query',
        ! empty( $counts )
    );
}

$pre_query = jpkcom_hooked( 'filter', 'comments_pre_query' );
jpkcom_check(
    'comments_pre_query is short-circuited',
    $pre_query !== array(),
    'the dashboard Activity widget would still query comments'
);

if ( $pre_query !== array() ) {
    $listing        = new WP_Comment_Query();
    $listing->query_vars = array( 'count' => false );

    $counting            = new WP_Comment_Query();
    $counting->query_vars = array( 'count' => true );

    jpkcom_check(
        'comments_pre_query returns an empty array for listing queries',
        $pre_query[0][0]( null, $listing ) === array()
    );
    jpkcom_check(
        'comments_pre_query returns 0 for counting queries',
        $pre_query[0][0]( null, $counting ) === 0,
        'WP_Comment_Query expects an int when count is set'
    );
}

/* 1.0.8 left the comment feeds serving approved comments. */
jpkcom_check(
    'comment feed discovery links are removed',
    jpkcom_hooked( 'filter', 'feed_links_show_comments_feed' ) !== array()
);
jpkcom_check(
    'a template_redirect guard is registered for the comment feed',
    jpkcom_hooked( 'action', 'template_redirect' ) !== array(),
    '/comments/feed/ would still be served'
);

/*
 * Removing post type support does not reach the REST schema for post, page and
 * attachment: get_item_schema() carries a hardcoded $fixed_schemas list in which
 * 'comments' is always present, so /wp/v2/posts kept reporting the stored
 * comment_status - "open" on a site that refuses comments.
 */
jpkcom_check(
    'a REST status rewrite is registered on rest_api_init',
    jpkcom_hooked( 'action', 'rest_api_init' ) !== array(),
    'REST would keep reporting the stored comment_status'
);

jpkcom_check(
    'the REST rewrite is a named, reusable function',
    function_exists( function: 'jpkcom_disable_comments_close_rest_status' )
);

if ( function_exists( function: 'jpkcom_disable_comments_close_rest_status' ) ) {
    $response = jpkcom_disable_comments_close_rest_status(
        new WP_REST_Response(
            array(
                'id'             => 7,
                'comment_status' => 'open',
                'ping_status'    => 'open',
                'title'          => 'kept',
            )
        )
    );
    $data = $response->get_data();

    jpkcom_check( 'comment_status is reported as closed', ( $data['comment_status'] ?? '' ) === 'closed' );
    jpkcom_check( 'ping_status is reported as closed', ( $data['ping_status'] ?? '' ) === 'closed' );
    jpkcom_check( 'other fields are untouched', ( $data['title'] ?? '' ) === 'kept' && ( $data['id'] ?? 0 ) === 7 );

    $without = jpkcom_disable_comments_close_rest_status( new WP_REST_Response( array( 'id' => 8 ) ) );
    jpkcom_check(
        'absent status fields are not invented',
        ! array_key_exists( 'comment_status', $without->get_data() )
    );

    jpkcom_check(
        'a non-response value passes through unchanged',
        jpkcom_disable_comments_close_rest_status( 'passthrough' ) === 'passthrough'
    );
}

/* Behaviour that must not regress. */
jpkcom_check( 'admin menu entry is removed', jpkcom_hooked( 'action', 'admin_menu' ) !== array() );
jpkcom_check( 'admin bar node is removed', jpkcom_hooked( 'action', 'admin_bar_menu' ) !== array() );

$rest = jpkcom_hooked( 'filter', 'rest_endpoints' );
jpkcom_check( 'rest_endpoints is filtered', $rest !== array() );

if ( $rest !== array() ) {
    $endpoints = $rest[0][0](
        array(
            '/wp/v2/comments'          => 'a',
            '/wp/v2/comments/(?P<id>)' => 'b',
            '/wp/v2/posts'             => 'c',
            '/wp/v2/commentsfoo'       => 'd',
        )
    );

    jpkcom_check( 'removes the comments collection route', ! isset( $endpoints['/wp/v2/comments'] ) );
    jpkcom_check( 'removes comment sub-routes', ! isset( $endpoints['/wp/v2/comments/(?P<id>)'] ) );
    jpkcom_check( 'keeps unrelated routes', isset( $endpoints['/wp/v2/posts'] ) && isset( $endpoints['/wp/v2/commentsfoo'] ) );
}

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
