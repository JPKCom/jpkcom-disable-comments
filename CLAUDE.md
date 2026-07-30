# JPKCom Disable Comments – Developer Reference

## Plugin Overview

Globally disables WordPress comment functionality: removes comment and trackback support from all post types, closes comments and pings, hides the admin Comments screen plus the menu and admin-bar entries, keeps the dashboard free of comment counts and moderation lists, 404s the comment feeds, removes the REST API comment endpoints and reports `comment_status`/`ping_status` as closed in REST responses.

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
├── rest_api_init  → attach that filter to rest_prepare_<post_type> for all types
├── wp_loaded      → strip comments/trackbacks support from all post types
├── admin_init     → redirect edit-comments.php
├── comments_open / pings_open  → __return_false        (PHP_INT_MAX)
├── comments_array → __return_empty_array               (PHP_INT_MAX)
├── wp_count_comments   → zeroed count object           (PHP_INT_MAX)
├── comments_pre_query  → [] / 0, short-circuits every comment query
├── feed_links_show_comments_feed → __return_false
├── template_redirect  → 404 for is_comment_feed() (prio 0)
├── admin_menu     → remove_menu_page( 'edit-comments.php' )
├── admin_bar_menu → remove_node( 'comments' ) (prio 999)
└── rest_endpoints → unset /wp/v2/comments routes
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
| `comments_pre_query` | filter (`PHP_INT_MAX`) | Short-circuits: `0` for counting queries, `[]` otherwise |
| `feed_links_show_comments_feed` | filter | Removes the comment feed discovery links |
| `template_redirect` | action (prio 0) | 404 for `is_comment_feed()` |
| `admin_menu` | action | Removes the Comments admin menu entry |
| `admin_bar_menu` | action (prio 999) | Removes the Comments node from the admin bar |
| `rest_endpoints` | filter | Removes `/wp/v2/comments` and `/wp/v2/comments/*` routes (iterated via `array_keys()`) |

---

## Four core details this plugin exists around

Each was measured against WordPress 7.0.2 for 1.0.9; the wrong assumption is noted so it is not reintroduced.

**`comments_array` does not cover the feeds.** Comment feeds are assembled by `WP_Query` straight through `$wpdb` (`class-wp-query.php`, the `is_comment_feed` branches around lines 2815 and 3475) and only expose the `comment_feed_*` filters. Up to 1.0.8 `/comments/feed/` and `<post>/feed/` therefore kept serving approved comments — 200 with the comment text in the body — while the theme showed none. The feeds are now 404'd outright; `/feed/` is untouched because `is_comment_feed()` is false there.

**`dashboard_recent_comments` is not a widget.** It has not been one since WordPress 3.8 — `wp_dashboard_setup()` registers `dashboard_activity`, and recent comments are rendered inside it by `wp_dashboard_recent_comments()`. On top of that, `admin_init` (`wp-admin/admin.php:180`) runs before `wp_dashboard_setup()` (`wp-admin/index.php:15`), so calling `remove_meta_box()` there only plants `false` markers at all four priorities. The dashboard is cleared instead by zeroing `wp_count_comments()` (which "At a Glance" reads) and short-circuiting `comments_pre_query` (which the Activity widget uses).

**Support removal belongs on `wp_loaded`, not `admin_init`.** `wp-settings.php` fires `init` (771) then `wp_loaded` (793); `admin_init` and `rest_api_init` — the latter via `parse_request` — both come later. Bound to `admin_init` the removal never reached REST or front-end requests.

**Removing support does not clean up the REST schema for `post`, `page` and `attachment`.** `WP_REST_Posts_Controller::get_item_schema()` holds a hardcoded `$fixed_schemas` list for those three in which `comments` is always present; the `post_type_supports()` check applies only to *other* post types (the `elseif` branch). So `comment_status`/`ping_status` stay in the schema and remain writable no matter what. `jpkcom_disable_comments_close_rest_status()` therefore rewrites the *response* to `closed`. The stored value is deliberately left alone — a REST write can still set `open`, but it is inert, because `comments_open` is filtered false and the response reports closed regardless.

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
- Updater: SHA256 verification + URL validation (audited separately)

---

## Tests

`tests/test-hooks.php` runs standalone: it stubs the WordPress functions the main file touches at load time, requires the plugin, then invokes the recorded callbacks. It asserts the hook names and priorities plus the behaviour of `jpkcom_disable_comments_close_rest_status()`, the `wp_count_comments` and `comments_pre_query` callbacks, the support-removal loop (including a post type with `trackbacks` but no `comments`) and the `rest_endpoints` pruning. 30 cases; 13 of them fail against 1.0.8. CI runs it on every pull request and push to `main`.

```bash
php tests/test-hooks.php   # exit 0 = green
```

The plugin is a pure set of hook registrations, so the suite covers the registration surface and the callback logic. What it cannot cover — that WordPress calls these hooks where expected — was verified by hand against a WordPress 7.0.2 instance.

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_DISABLE_COMMENTS_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Run `php tests/test-hooks.php`
4. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
