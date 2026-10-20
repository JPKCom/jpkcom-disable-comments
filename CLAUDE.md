# JPKCom Disable Comments – Developer Reference

## Plugin Overview

Globally disables WordPress comment functionality: removes comment and trackback support from all post types, closes comments and pings, hides the admin Comments screen plus the menu and admin-bar entries, keeps the dashboard free of comment counts and moderation lists, 404s the comment feeds, reports zero comments per post, answers the REST API comment endpoints with a 404 for everything except block-editor Notes, and reports `comment_status`/`ping_status` as closed in REST responses. Notes (comments of type `note`, WordPress 6.9+) keep working.

- **Text Domain:** `jpkcom-disable-comments` (used by `esc_html__()` in the feed `wp_die()`; no header declared, defaults to slug)
- **Min PHP:** 8.3 | **Min WP:** 6.9
- **Network:** not network-only (no `Network:` header)

---

## Architecture

```
Main file (jpkcom-disable-comments.php)
├── declare(strict_types=1)
├── Plugin header
├── JPKCOM_DISABLE_COMMENTS_VERSION constant
├── init @ priority 5: boot JPKComGitPluginUpdater
├── jpkcom_disable_comments_close_rest_status()  ← named, function_exists-guarded
├── jpkcom_disable_comments_is_note_request()   ← named, function_exists-guarded
├── jpkcom_disable_comments_is_note_query()     ← named, function_exists-guarded
├── rest_api_init  → attach that filter to rest_prepare_<post_type> for all types
├── wp_loaded      → strip comments/trackbacks support from all post types
├── admin_init     → redirect edit-comments.php
├── comments_open / pings_open  → __return_false        (PHP_INT_MAX)
├── comments_array → __return_empty_array               (PHP_INT_MAX)
├── wp_count_comments   → zeroed count object           (PHP_INT_MAX)
├── comments_pre_query  → [] / 0, short-circuits every non-note comment query
├── get_comments_number → __return_zero                   (PHP_INT_MAX)
├── feed_links_show_comments_feed → __return_false
├── template_redirect  → 404 for is_comment_feed() (prio 0)
├── admin_menu     → remove_menu_page( 'edit-comments.php' )
├── admin_bar_menu → remove_node( 'comments' ) (prio 999)
└── rest_pre_dispatch → 404 rest_no_route for non-note requests on /wp/v2/comments* (PHP_INT_MAX, 3 args)
```

---

## Behaviour

| Hook | Type | Effect |
|------|------|--------|
| `rest_api_init` | action | Attaches the REST status rewrite to `rest_prepare_<post_type>` for every post type |
| `wp_loaded` | action | Removes `comments` and `trackbacks` support from every post type, each checked independently |
| `admin_init` | action | Redirects the comments screen to the dashboard |
| `comments_open`, `pings_open` | filter (`PHP_INT_MAX`) | Force closed |
| `comments_array` | filter (`PHP_INT_MAX`) | Always returns an empty array |
| `wp_count_comments` | filter (`PHP_INT_MAX`) | Reports every bucket as zero |
| `comments_pre_query` | filter (`PHP_INT_MAX`, 2 args) | Short-circuits: `0` for counting queries, `[]` otherwise — except queries whose types are exactly `note`, which run untouched |
| `get_comments_number` | filter (`PHP_INT_MAX`) | `__return_zero` |
| `feed_links_show_comments_feed` | filter | Removes the comment feed discovery links |
| `template_redirect` | action (prio 0) | 404 for `is_comment_feed()` |
| `admin_menu` | action | Removes the Comments admin menu entry |
| `admin_bar_menu` | action (prio 999) | Removes the Comments node from the admin bar |
| `rest_pre_dispatch` | filter (`PHP_INT_MAX`, 3 args) | On `/wp/v2/comments` and everything below it (case-insensitive): a non-note request gets `rest_no_route` / 404 / core's message as a `WP_REST_Response`, overriding any earlier result; a note request gets the incoming value back |

A **note request** is the collection route with `type` exactly `note` (GET and POST), or the item route `/wp/v2/comments/<id>` for an existing comment of type `note` (any method). Everything else — no `type`, `type=comment`/`pingback`/`all`, `NOTE`, `note,comment`, `type[]=note`, a regular, pingback or unknown ID, ID 0, sub-routes, `OPTIONS` — gets the 404. Core's own permission checks still decide who may read or write notes.

**Deliberate, documented:**
- **Query-string comment feeds** (`?feed=comments-rss2`, `?p=N&feed=rss2`) are answered with the 404 by the `template_redirect` guard at priority 0, before `redirect_canonical()` (priority 10) would 301 them to the pretty URL — which would 404 too. Owner decision 2026-10-20: keep.
- **Route discovery:** the REST index at `/wp-json/` lists the comment routes again since 1.0.11. That is inherent to keeping them registered for Notes; every non-note request on them still gets the 404.
- **`OPTIONS`** on the comment routes answers the 404 since 1.0.11. With the routes removed (≤ 1.0.10) core's OPTIONS handler (`rest_handle_options_request()`, `rest_pre_dispatch` priority 10) found no route and answered 200 with an empty body.

---

## Core details this plugin exists around

The first four were measured against a running WordPress 7.0.2 for 1.0.9. The other three were read in the WordPress 7.1.3 source for 1.0.11 and have not yet been measured on a running site. The wrong assumption is noted so it is not reintroduced.

**`comments_array` does not cover the feeds.** Comment feeds are assembled by `WP_Query` straight through `$wpdb` (`class-wp-query.php`, the `is_comment_feed` branches around lines 2815 and 3475) and only expose the `comment_feed_*` filters. Up to 1.0.8 `/comments/feed/` and `<post>/feed/` therefore kept serving approved comments — 200 with the comment text in the body — while the theme showed none. The feeds are now 404'd outright; `/feed/` is untouched because `is_comment_feed()` is false there.

**`dashboard_recent_comments` is not a widget.** It has not been one since WordPress 3.8 — `wp_dashboard_setup()` registers `dashboard_activity`, and recent comments are rendered inside it by `wp_dashboard_recent_comments()`. On top of that, `admin_init` (`wp-admin/admin.php:180`) runs before `wp_dashboard_setup()` (`wp-admin/index.php:15`), so calling `remove_meta_box()` there only plants `false` markers at all four priorities. The dashboard is cleared instead by zeroing `wp_count_comments()` (which "At a Glance" reads) and short-circuiting `comments_pre_query` (which the Activity widget uses).

**Support removal belongs on `wp_loaded`, not `admin_init`.** `wp-settings.php` fires `init` (771) then `wp_loaded` (793); `admin_init` and `rest_api_init` — the latter via `parse_request` — both come later. Bound to `admin_init` the removal never reached REST or front-end requests.

**Removing support does not clean up the REST schema for `post`, `page` and `attachment`.** `WP_REST_Posts_Controller::get_item_schema()` holds a hardcoded `$fixed_schemas` list for those three in which `comments` is always present; the `post_type_supports()` check applies only to *other* post types (the `elseif` branch). So `comment_status`/`ping_status` stay in the schema and remain writable no matter what. `jpkcom_disable_comments_close_rest_status()` therefore rewrites the *response* to `closed`. The stored value is deliberately left alone — a REST write can still set `open`, but it is inert, because `comments_open` is filtered false and the response reports closed regardless.

**Notes need the comment routes, not open comments.** Notes are comments of type `note`, created and read through `/wp/v2/comments` by logged-in users who can edit the post. Core lets them bypass `comments_open()` — `create_item_permissions_check()` returns for a note (7.1.3 `class-wp-rest-comments-controller.php:595`) before the `comments_open` check (`:633`) — and gates them on the post type's `editor` support with `notes` (`check_post_type_supports_notes()`, `:2173`), not on `comments` support. Up to 1.0.10 they broke only because `rest_endpoints` removed the routes and `comments_pre_query` emptied every query, note queries included. `WP_Comment_Query` leaves notes out of every query that does not request `all` or `note` (`class-wp-comment-query.php:782-789`), so passing note-only queries through exposes nothing else.

**`rest_pre_dispatch` sees the request before routing.** `dispatch()` applies the filter (`class-wp-rest-server.php:1079`) and only then calls `match_request_to_handler()` (`:1096`), so URL parameters such as `id` are not set yet; a batch sub-request is even passed with them cleared (`:1860-1866`). The gate therefore parses `get_route()` itself, the way core matches it: `@^…$@i` — case-insensitive, and `$` also matches before a trailing newline, so `/wp/v2/comments\n` reaches the controller and has to be gated too. `get_comment()` of an empty ID falls back to the global `$comment`, hence the `id > 0` check. The error goes out as `rest_convert_error_to_response()`, not as a `WP_Error`: in a batch the pre-dispatch result is passed to `rest_post_dispatch` unconverted (`:1894`), where `rest_send_allow_header()` calls `get_matched_route()` on it — a fatal on `WP_Error`. The message uses core's string in the default text domain, so it is byte-identical to the missing-route answer in every locale.

**Comment counts reach the feeds through `get_comments_number()`.** `/feed/` prints `<comments>`, `<wfw:commentRss>` and `<slash:comments>` when `get_comments_number() || comments_open()` (7.1.3 `feed-rss2.php:91`, `:112-114`), Atom the `replies` links and `<thr:total>` (`feed-atom.php:98-102`). With comments closed, a post with old approved comments kept all of them, pointing at comment feeds that answer 404. Filtering `get_comments_number` to `0` removes them and the themes' "N comments". Notes are unaffected — core excludes them from `comment_count` (`comment.php:3138`).

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_DISABLE_COMMENTS_VERSION` | matches the header `Version:` | Plugin version (sync with header/README/phpdoc.xml) |

---

## File Structure

```
jpkcom-disable-comments/
├── jpkcom-disable-comments.php   ← Main: header, constant, filters/actions, updater bootstrap
├── includes/
│   └── class-plugin-updater.php  ← GitHub auto-updater (namespace: JPKComDisableCommentsGitUpdate)
├── .github/workflows/release.yml ← Build ZIP, manifest, PHPDoc, deploy to gh-pages (on tag push)
├── tests/
│   ├── test-hooks.php            ← Standalone hook/callback suite
│   └── test-claims.php           ← Re-measures the suite figures stated in CLAUDE.md
├── phpdoc.xml                    ← phpDocumentor config
├── README.md                     ← Public readme (source for the WP plugin modal)
├── CLAUDE.md                     ← This file
├── LICENSE                       ← GPL-2.0-or-later
└── .gitignore
```

---

## Plugin Updater

- **Namespace:** `JPKComDisableCommentsGitUpdate\JPKComGitPluginUpdater`
- **Manifest URL:** `https://jpkcom.github.io/jpkcom-disable-comments/plugin_jpkcom-disable-comments.json`
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`. Checksum verification is **mandatory**: a missing or unfetchable `checksum_sha256` aborts the update instead of installing unverified code. The verified temp file is returned from `upgrader_pre_download`, so WordPress installs exactly the bytes that were hashed (no second download). Failed manifest fetches are negatively cached for 1 h.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

**Actions are pinned to commit SHAs.** Every `uses:` line in `.github/workflows/` references a 40-character commit SHA instead of a tag (`@v4`), with the version as a trailing comment. A tag is a movable pointer and can be repointed; a SHA cannot. Since the release workflow builds the plugin ZIP **and** the SHA256 checksum the auto-updater trusts, a compromised action would ship a tampered ZIP together with a matching checksum — the checksum secures the transport, the pinning secures the build. `.github/dependabot.yml` keeps the pins current weekly in one combined PR; when updating, always change the SHA *and* the version comment together.

**CI** (`.github/workflows/ci.yml`) runs on every pull request *and* on every push to `main` — a required status check only covers pull requests, so a direct push with bypass rights would otherwise skip the checks entirely. It runs `php -l` over all PHP files; flags invalid named arguments to internal PHP functions (catches `sprintf(format:, values:)` → `ArgumentCountError`, which `php -l` does not see); validates the YAML of every `.github` file; asserts every action is pinned to a 40-character commit SHA; and executes `tests/test-*.php` where present.

**Dependabot auto-merge** (`.github/workflows/dependabot-auto-merge.yml`) merges only `semver-patch` and `semver-minor`, and only PRs from `dependabot[bot]` in this repo — never from forks. Major updates get a comment and stay manual. Two repo settings are prerequisites, otherwise this is useless or outright dangerous: "Allow auto-merge" must be enabled, and branch protection must list `CI / Lint & Guards` as a **required status check** — without it `gh pr merge --auto` merges *immediately*, since there is nothing left to wait for. Together with `cooldown: default-days: 7` no action release is adopted during its first week.

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file
- Typed closures throughout; `wp_safe_redirect()` for the admin redirect
- Comment feeds return 404 rather than serving stored comments — the data path that mattered
- The REST gate fails closed: anything on `/wp/v2/comments*` that is not provably a note request gets the 404; the note decision uses core's own strict `'note' ===` comparison and the stored `comment_type`
- Updater: SHA256 verification + URL validation (audited separately)

---

## Tests

`tests/test-hooks.php` runs standalone: it stubs the WordPress functions the main file touches at load time, requires the plugin, then invokes the recorded callbacks. It asserts the hook names and priorities plus the behaviour of `jpkcom_disable_comments_close_rest_status()`, the `wp_count_comments` and `comments_pre_query` callbacks (including the note-only pass-through), the `get_comments_number` filter, the support-removal loop (including a post type with `trackbacks` but no `comments`) and the `rest_pre_dispatch` gate — note and non-note requests, the 404's exact shape, case, trailing-slash and trailing-newline spellings. 90 cases. Measured with this suite against the released files: against 1.0.8, 22 of the 90 cases run and 18 fail; against 1.0.10, 46 of the 90 cases run and 10 fail. A case that exercises a hook or function the release does not register is skipped there and counts in neither figure: in both releases the 43 gate-behaviour cases (no `rest_pre_dispatch` gate) and the `get_comments_number` value, against 1.0.8 also the `wp_count_comments`, `comments_pre_query` and REST-rewrite behaviour.

`tests/test-claims.php` keeps those figures true. It re-runs the suite (once as is, once with the `rest_pre_dispatch` registration dropped, and once against the files of each `v*` tag named above) and fails when a stated figure no longer matches, or when this section or the header of `tests/test-hooks.php` contains any other number (code, versions and the HTTP status 404 aside). The release comparisons need the tags; in CI's shallow checkout they are reported as skipped. CI runs both files on every pull request and push to `main`.

```bash
php tests/test-hooks.php    # exit 0 = green
php tests/test-claims.php   # the figures above still hold
```

The plugin is a pure set of hook registrations, so the suite covers the registration surface and the callback logic. What it cannot cover — that WordPress calls these hooks where expected — was verified by hand against a WordPress 7.0.2 instance for 1.0.9. The 1.0.11 changes have **not yet been verified on a running site**: Notes being created, listed and resolved through the gate, the exact bytes of the 404, and `/feed/` without `<comments>` and `<slash:comments>`. So far they rest only on this suite and on reading the 7.1.3 source. Once it has been measured, replace these sentences with what was measured.

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_DISABLE_COMMENTS_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Run `php tests/test-hooks.php` and `php tests/test-claims.php` (after adding cases, update the figures in **Tests**)
4. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
