<?php
/**
 * Regression tests for the hook surface of jpkcom-disable-comments.
 *
 * Runs standalone (no WordPress): the WordPress functions the plugin file
 * touches at load time are stubbed, the plugin file is required, and the
 * recorded callbacks are then invoked directly.
 *
 * Against 1.0.10 the Notes gate, note-query pass-through and comment-count
 * cases are red; against 1.0.8 nearly every registration case is red as well
 * (support removal, priorities, dashboard, feeds, REST rewrite). The measured
 * figures are in CLAUDE.md (Tests); tests/test-claims.php re-measures them.
 *
 * @package JPKCom_Disable_Comments
 * @since 1.0.9
 * @since 1.0.11 Notes gate (including an `id` parameter that overrides the route ID),
 *               note-query pass-through, comment counts.
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
    define( constant_name: 'WPINC', value: true );
}

/** Recorded hook registrations: $GLOBALS['jpkcom_hooks'][type][hook][] = [cb, priority, accepted_args]. */
$GLOBALS['jpkcom_hooks'] = array();

/** Post types the stubbed registry knows about, with their supports. */
$GLOBALS['jpkcom_supports'] = array();

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['action'][ $hook ][] = array( $callback, $priority, $accepted_args );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks']['filter'][ $hook ][] = array( $callback, $priority, $accepted_args );
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

        private int $status;

        public function __construct( mixed $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function set_data( mixed $data ): void {
            $this->data = $data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}

if ( ! class_exists( class: 'WP_REST_Request' ) ) {
    /**
     * Minimal request: method, route and the parameters by source.
     *
     * get_param() follows core's precedence (7.1.3 class-wp-rest-request.php
     * get_parameter_order(), get_param()): JSON body (when it is JSON), then the
     * form body for POST, PUT, PATCH and DELETE only, then the query string,
     * then the URL parameters; a null value counts as absent (core's isset()).
     * On rest_pre_dispatch the URL parameters are still empty.
     */
    class WP_REST_Request {
        /**
         * @param array<string,mixed> $params Query-string (GET) parameters.
         * @param array<string,mixed> $json   JSON body parameters.
         * @param array<string,mixed> $body   Form body (POST) parameters.
         * @param array<string,mixed> $url    URL parameters from route matching.
         */
        public function __construct(
            private string $method = 'GET',
            private string $route = '',
            private array $params = array(),
            private array $json = array(),
            private array $body = array(),
            private array $url = array()
        ) {
        }

        public function get_method(): string {
            return $this->method;
        }

        public function get_route(): string {
            return $this->route;
        }

        public function get_param( string $key ): mixed {
            $order = array( $this->json );

            if ( in_array( $this->method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
                $order[] = $this->body;
            }

            $order[] = $this->params;
            $order[] = $this->url;

            foreach ( $order as $source ) {
                if ( isset( $source[ $key ] ) ) {
                    return $source[ $key ];
                }
            }

            return null;
        }
    }
}

if ( ! class_exists( class: 'WP_Error' ) ) {
    class WP_Error {
        public function __construct( public string $code = '', public string $message = '', public mixed $data = '' ) {
        }
    }
}

if ( ! class_exists( class: 'WP_Comment' ) ) {
    class WP_Comment {
        public function __construct( public string $comment_ID = '0', public string $comment_type = 'comment' ) {
        }
    }
}

/** Comments the stubbed get_comment() knows, by ID. */
$GLOBALS['jpkcom_comments'] = array();

/** What get_comment() falls back to for an empty ID - core returns the global $comment. */
$GLOBALS['jpkcom_global_comment'] = null;

if ( ! function_exists( function: 'get_comment' ) ) {
    function get_comment( mixed $comment = null ): ?WP_Comment {
        if ( empty( $comment ) ) {
            return $GLOBALS['jpkcom_global_comment'];
        }

        return $GLOBALS['jpkcom_comments'][ (int) $comment ] ?? null;
    }
}

if ( ! function_exists( function: '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( function: '__return_zero' ) ) {
    function __return_zero(): int {
        return 0;
    }
}

if ( ! function_exists( function: 'rest_convert_error_to_response' ) ) {
    /** Mirrors core for a single-code error: status from the error data, code/message/data as body. */
    function rest_convert_error_to_response( WP_Error $error ): WP_REST_Response {
        $status = is_array( $error->data ) && isset( $error->data['status'] ) ? (int) $error->data['status'] : 500;

        return new WP_REST_Response(
            array(
                'code'    => $error->code,
                'message' => $error->message,
                'data'    => $error->data,
            ),
            $status
        );
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

/*
 * 1.0.10 short-circuited every query, note queries included, so the block
 * editor's Notes panel stayed empty. A query passes only when the requested
 * types are exactly `note` - core's own reading of type + type__in
 * (WP_Comment_Query::get_comment_ids(), where '' means "all").
 */
if ( $pre_query !== array() ) {
    $passes = static function ( array $vars ) use ( $pre_query ): mixed {
        $query             = new WP_Comment_Query();
        $query->query_vars = $vars + array( 'count' => false, 'type' => '', 'type__in' => '' );

        return $pre_query[0][0]( null, $query );
    };

    jpkcom_check( 'a note query (type note) runs', $passes( array( 'type' => 'note' ) ) === null );
    jpkcom_check( 'a note query (type__in [note]) runs', $passes( array( 'type__in' => array( 'note' ) ) ) === null );
    jpkcom_check( 'a note query (type [note]) runs', $passes( array( 'type' => array( 'note' ) ) ) === null );
    jpkcom_check(
        'a note count query runs, so the real note count comes back',
        $passes( array( 'type' => 'note', 'count' => true ) ) === null
    );

    foreach (
        array(
            'no type (all types)'           => array(),
            'type all'                      => array( 'type' => 'all' ),
            'type comment'                  => array( 'type' => 'comment' ),
            'type pings'                    => array( 'type' => 'pings' ),
            'type NOTE (case variant)'      => array( 'type' => 'NOTE' ),
            'type "note,comment" (string)'  => array( 'type' => 'note,comment' ),
            'type__in [note, comment]'      => array( 'type__in' => array( 'note', 'comment' ) ),
            'type note + type__in [comment]' => array( 'type' => 'note', 'type__in' => array( 'comment' ) ),
            'type__in [note, all]'          => array( 'type__in' => array( 'note', 'all' ) ),
        ) as $label => $vars
    ) {
        jpkcom_check( sprintf( 'stays short-circuited: %s', $label ), $passes( $vars ) === array() );
    }

    jpkcom_check(
        'a non-note count query still returns 0',
        $passes( array( 'type' => 'comment', 'count' => true ) ) === 0
    );

    $earlier             = new WP_Comment_Query();
    $earlier->query_vars = array( 'count' => false, 'type' => 'note', 'type__in' => '' );
    jpkcom_check(
        'a note query keeps a value an earlier filter supplied',
        $pre_query[0][0]( array( 'kept' ), $earlier ) === array( 'kept' )
    );
}

/*
 * Comment counts: /feed/ items carried <comments>, <wfw:commentRss> and
 * <slash:comments> (feed-rss2.php gates them on get_comments_number() ||
 * comments_open()), Atom entries the replies links and <thr:total>, and themes
 * kept printing "N comments" for old comments.
 */
$number = jpkcom_hooked( 'filter', 'get_comments_number' );
jpkcom_check(
    'get_comments_number is filtered at PHP_INT_MAX',
    $number !== array() && $number[0][1] === PHP_INT_MAX,
    $number === array() ? 'not registered' : sprintf( 'priority %d', $number[0][1] )
);

if ( $number !== array() ) {
    jpkcom_check( 'get_comments_number reports 0', call_user_func( $number[0][0], '5', 7 ) === 0 );
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

/*
 * Up to 1.0.10 the /wp/v2/comments routes were removed on rest_endpoints, which
 * also broke block-editor Notes (comments of type `note`, read and written
 * through those routes). The routes now stay registered and a rest_pre_dispatch
 * gate answers every request that is not a note request exactly as a missing
 * route does.
 */
jpkcom_check(
    'the comment routes stay registered (rest_endpoints is not pruned)',
    jpkcom_hooked( 'filter', 'rest_endpoints' ) === array(),
    'Notes cannot reach /wp/v2/comments'
);

$gate = jpkcom_hooked( 'filter', 'rest_pre_dispatch' );
jpkcom_check(
    'a rest_pre_dispatch gate is registered at PHP_INT_MAX with 3 arguments',
    $gate !== array() && $gate[0][1] === PHP_INT_MAX && $gate[0][2] === 3,
    $gate === array() ? 'not registered' : sprintf( 'priority %d, %d args', $gate[0][1], $gate[0][2] )
);

jpkcom_check(
    'the note-request check is a named, reusable function',
    function_exists( function: 'jpkcom_disable_comments_is_note_request' )
);
jpkcom_check(
    'the note-query check is a named, reusable function',
    function_exists( function: 'jpkcom_disable_comments_is_note_query' )
);

if ( $gate !== array() ) {
    $GLOBALS['jpkcom_comments'] = array(
        12 => new WP_Comment( '12', 'comment' ),
        13 => new WP_Comment( '13', 'note' ),
        14 => new WP_Comment( '14', 'pingback' ),
        15 => new WP_Comment( '15', '' ),
    );
    /* get_comment( 0 ) falls back to the global $comment in core. */
    $GLOBALS['jpkcom_global_comment'] = $GLOBALS['jpkcom_comments'][13];

    $dispatch = static function ( string $method, string $route, array $params = array(), mixed $result = null ) use ( $gate ): mixed {
        return $gate[0][0]( $result, null, new WP_REST_Request( $method, $route, $params ) );
    };

    $is_404 = static function ( mixed $response ): bool {
        if ( ! $response instanceof WP_REST_Response ) {
            return false;
        }

        $data = $response->get_data();

        return $response->get_status() === 404
            && ( $data['code'] ?? '' ) === 'rest_no_route'
            && ( $data['message'] ?? '' ) === 'No route was found matching the URL and request method.'
            && ( $data['data'] ?? null ) === array( 'status' => 404 );
    };

    /* The old route-pruning cases, now answered by the gate. */
    jpkcom_check( 'collection request without type: rest_no_route 404', $is_404( $dispatch( 'GET', '/wp/v2/comments' ) ) );
    jpkcom_check( 'item request for a regular comment: rest_no_route 404', $is_404( $dispatch( 'GET', '/wp/v2/comments/12' ) ) );
    jpkcom_check( 'other comment sub-routes: rest_no_route 404', $is_404( $dispatch( 'GET', '/wp/v2/comments/12/extra' ) ) );
    jpkcom_check(
        'unrelated routes pass untouched',
        $dispatch( 'GET', '/wp/v2/posts' ) === null
            && $dispatch( 'GET', '/wp/v2/commentsfoo' ) === null
            && $dispatch( 'GET', '/myplugin/v1/wp/v2/comments' ) === null
    );

    /*
     * The gate returns a response, not a WP_Error: in a batch request core hands
     * a rest_pre_dispatch result straight to rest_post_dispatch, where
     * rest_send_allow_header() calls get_matched_route() on it.
     */
    jpkcom_check(
        'the 404 is a WP_REST_Response (batch-safe), not a WP_Error',
        $dispatch( 'GET', '/wp/v2/comments' ) instanceof WP_REST_Response
    );

    /* Note requests pass. */
    jpkcom_check( 'GET collection type=note passes', $dispatch( 'GET', '/wp/v2/comments', array( 'type' => 'note', 'post' => 5 ) ) === null );
    jpkcom_check( 'POST collection type=note passes', $dispatch( 'POST', '/wp/v2/comments', array( 'type' => 'note', 'post' => 5 ) ) === null );
    foreach ( array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) as $method ) {
        jpkcom_check(
            sprintf( '%s on a note item passes', $method ),
            $dispatch( $method, '/wp/v2/comments/13' ) === null
        );
    }
    jpkcom_check(
        'a note request keeps a result an earlier filter supplied',
        $dispatch( 'GET', '/wp/v2/comments/13', array(), 'earlier' ) === 'earlier'
    );

    /* Everything else is the 404 - Review Focus 1. */
    foreach (
        array(
            'GET type=comment'              => array( 'GET', '/wp/v2/comments', array( 'type' => 'comment' ) ),
            'GET type=pingback'             => array( 'GET', '/wp/v2/comments', array( 'type' => 'pingback' ) ),
            'GET type=all'                  => array( 'GET', '/wp/v2/comments', array( 'type' => 'all' ) ),
            'GET type="note,comment"'       => array( 'GET', '/wp/v2/comments', array( 'type' => 'note,comment' ) ),
            'GET type[]=note (array)'       => array( 'GET', '/wp/v2/comments', array( 'type' => array( 'note' ) ) ),
            'GET type=NOTE (case variant)'  => array( 'GET', '/wp/v2/comments', array( 'type' => 'NOTE' ) ),
            'GET type="" (empty)'           => array( 'GET', '/wp/v2/comments', array( 'type' => '' ) ),
            'POST without type'             => array( 'POST', '/wp/v2/comments', array( 'post' => 5, 'content' => 'x' ) ),
            'POST type=comment'             => array( 'POST', '/wp/v2/comments', array( 'type' => 'comment', 'post' => 5 ) ),
            'GET pingback item'             => array( 'GET', '/wp/v2/comments/14' ),
            'GET legacy empty-type item'    => array( 'GET', '/wp/v2/comments/15' ),
            'GET unknown item'              => array( 'GET', '/wp/v2/comments/999' ),
            'GET item 0 (global fallback)'  => array( 'GET', '/wp/v2/comments/0' ),
            'regular item claiming type=note' => array( 'GET', '/wp/v2/comments/12', array( 'type' => 'note' ) ),
            'DELETE regular item'           => array( 'DELETE', '/wp/v2/comments/12' ),
            'POST regular item'             => array( 'POST', '/wp/v2/comments/12', array( 'type' => 'note' ) ),
            'OPTIONS collection'            => array( 'OPTIONS', '/wp/v2/comments' ),
        ) as $label => $case
    ) {
        jpkcom_check( sprintf( '404: %s', $label ), $is_404( $dispatch( ...$case ) ) );
    }

    jpkcom_check(
        'a non-note request overrides a result an earlier filter supplied (e.g. the OPTIONS handler)',
        $is_404( $dispatch( 'OPTIONS', '/wp/v2/comments', array(), new WP_REST_Response( array( 'endpoints' => array() ) ) ) )
    );

    /* Spellings core still routes to the comments controller - Review Focus 3. */
    foreach (
        array(
            'upper-case collection'         => array( 'GET', '/WP/V2/COMMENTS' ),
            'mixed-case item'               => array( 'GET', '/wp/v2/Comments/12' ),
            'collection, trailing slash'    => array( 'GET', '/wp/v2/comments/' ),
            'item, trailing slash'          => array( 'GET', '/wp/v2/comments/12/' ),
            'collection, trailing newline'  => array( 'GET', "/wp/v2/comments\n" ),
            'item, trailing newline'        => array( 'GET', "/wp/v2/comments/12\n" ),
            'item with leading zeros'       => array( 'GET', '/wp/v2/comments/012' ),
        ) as $label => $case
    ) {
        jpkcom_check( sprintf( '404: %s', $label ), $is_404( $dispatch( ...$case ) ) );
    }

    jpkcom_check( 'upper-case collection with type=note passes', $dispatch( 'GET', '/WP/V2/COMMENTS', array( 'type' => 'note' ) ) === null );
    jpkcom_check( 'upper-case note item passes', $dispatch( 'GET', '/WP/V2/COMMENTS/13' ) === null );
    jpkcom_check( 'note item with leading zeros passes', $dispatch( 'GET', '/wp/v2/comments/013' ) === null );

    /*
     * The comments controller does not use the ID in the route: it reads
     * $request['id'] (7.1.3 class-wp-rest-comments-controller.php:435, :480,
     * :873, :918, :1042, :1066), and get_param() prefers the JSON body, the form
     * body and the query string to the URL parameters. A note's route with an
     * `id` parameter naming a regular comment would load and return - or update,
     * or delete - that comment. The gate must refuse it.
     */
    $request = static fn ( string $method, string $route, array $params = array(), array $json = array(), array $body = array() ): WP_REST_Request
        => new WP_REST_Request( $method, $route, $params, $json, $body );

    foreach (
        array(
            'note item, query id of a regular comment'       => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '12' ) ),
            'note item, query id via ?rest_route spelling'   => $request( 'GET', '/wp/v2/comments/13', array( 'rest_route' => '/wp/v2/comments/13', 'id' => '12' ) ),
            'note item, JSON body id (GET)'                  => $request( 'GET', '/wp/v2/comments/13', array(), array( 'id' => 12 ) ),
            'note item, JSON body id (PUT)'                  => $request( 'PUT', '/wp/v2/comments/13', array(), array( 'id' => 12, 'content' => 'x' ) ),
            'note item, POST body id'                        => $request( 'POST', '/wp/v2/comments/13', array(), array(), array( 'id' => '12', 'content' => 'x' ) ),
            'note item, DELETE body id'                      => $request( 'DELETE', '/wp/v2/comments/13', array(), array(), array( 'id' => '12' ) ),
            'note item, JSON id wins over a matching query id' => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '13' ), array( 'id' => 12 ) ),
            'note item, query id of an unknown comment'      => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '999' ) ),
            'note item, non-numeric id'                      => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '13abc' ) ),
            'note item, fractional id'                       => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '13.5' ) ),
            'note item, array id'                            => $request( 'GET', '/wp/v2/comments/13', array( 'id' => array( '13' ) ) ),
            'note item, boolean id'                          => $request( 'GET', '/wp/v2/comments/13', array(), array( 'id' => true ) ),
        ) as $label => $case
    ) {
        jpkcom_check( sprintf( '404: %s', $label ), $is_404( $gate[0][0]( null, null, $case ) ) );
    }

    foreach (
        array(
            'note item, query id equal to the route ID'      => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '13' ) ),
            'note item, JSON id equal to the route ID (PUT)' => $request( 'PUT', '/wp/v2/comments/13', array(), array( 'id' => 13, 'content' => 'x' ) ),
            'note item, POST body id equal to the route ID'  => $request( 'POST', '/wp/v2/comments/13', array(), array(), array( 'id' => '13' ) ),
            'note item, JSON id wins: equal, query id differs' => $request( 'GET', '/wp/v2/comments/13', array( 'id' => '12' ), array( 'id' => 13 ) ),
            'GET note item, form-body id core does not read' => $request( 'GET', '/wp/v2/comments/13', array(), array(), array( 'id' => '12' ) ),
        ) as $label => $case
    ) {
        jpkcom_check( sprintf( 'passes: %s', $label ), $gate[0][0]( null, null, $case ) === null );
    }

    if ( function_exists( function: 'jpkcom_disable_comments_is_note_request' ) ) {
        jpkcom_check(
            'is_note_request: true for a note item, false for a regular one',
            jpkcom_disable_comments_is_note_request( new WP_REST_Request( 'GET', '/wp/v2/comments/13' ) )
                && ! jpkcom_disable_comments_is_note_request( new WP_REST_Request( 'GET', '/wp/v2/comments/12' ) )
        );
    }

    if ( function_exists( function: 'jpkcom_disable_comments_is_note_query' ) ) {
        $note_query             = new WP_Comment_Query();
        $note_query->query_vars = array( 'type' => 'note', 'type__in' => '' );
        $all_query              = new WP_Comment_Query();
        $all_query->query_vars  = array( 'type' => '', 'type__in' => '' );

        jpkcom_check(
            'is_note_query: true for type note, false for all types',
            jpkcom_disable_comments_is_note_query( $note_query ) && ! jpkcom_disable_comments_is_note_query( $all_query )
        );
    }
}

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
