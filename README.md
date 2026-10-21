# JPKCom Disable Comments

**Plugin Name:** JPKCom Disable Comments  
**Plugin URI:** https://github.com/JPKCom/jpkcom-disable-comments  
**Description:** Globally disable comments functionality.  
**Version:** 1.0.12  
**Author:** Jean Pierre Kolb <jpk@jpkc.com>  
**Author URI:** https://www.jpkc.com  
**Contributors:** JPKCom  
**Tags:** Comments, Plugin  
**Requires at least:** 6.9  
**Tested up to:** 7.1  
**Requires PHP:** 8.3  
**Stable tag:** 1.0.12  
**License:** GPL-2.0-or-later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html  
**Update URI:** https://github.com/JPKCom/jpkcom-disable-comments

Globally disable comments functionality.


## Description

Disables the WordPress core functionality for comments for the whole site.

Comment and trackback support is removed from every post type, comments and pings are forced closed, and the Comments screen, admin menu entry and admin-bar node are gone. Beyond that, the plugin closes the routes through which comments otherwise remain visible or reachable: the dashboard shows no comment count and no moderation list, the comment feeds (`/comments/feed/` and `<post>/feed/`) return 404 instead of serving stored comments, every post reports zero comments (so `/feed/` and the Atom feed carry no comment links or counts and themes print no "N comments"), the REST comment endpoints answer 404, and REST post responses report `comment_status` and `ping_status` as `closed`.

Block-editor Notes keep working. Notes (WordPress 6.9+) are stored as comments of type `note` and travel through the same REST routes, so the routes stay registered: a request for notes — the collection with `type=note`, or an existing note by its ID — is handed to WordPress, whose own permission checks decide who may read or write them; every other request on those routes gets the same `rest_no_route` 404 a missing route returns, including a note's route whose `id` parameter names a different comment (`/wp/v2/comments/<note ID>?id=<comment ID>`, or that `id` in the request body, which WordPress would load instead). Because the routes stay registered, the REST index at `/wp-json/` lists them.

Query-string spellings of the comment feeds (`?feed=comments-rss2`, `?p=123&feed=rss2`) are answered with the 404 directly rather than with WordPress' canonical redirect to a pretty URL that would 404 as well. This is deliberate.

Existing comments are not deleted — they stay in the database, out of reach of the front end, the feeds and the REST API. Deactivating the plugin makes them visible again.


### Documentation

**API Documentation:** Complete PHPDoc-generated API documentation is available at:
[https://jpkcom.github.io/jpkcom-disable-comments/docs/](https://jpkcom.github.io/jpkcom-disable-comments/docs/)


## Installation

1. In your admin panel, go to 'Plugins' > and click the 'Add New' button.
2. Click Upload Plugin and 'Choose File', then select the Plugin's .zip file. Click 'Install Now'.
3. Click 'Activate' to use the plugin right away.


## Changelog

### 1.0.12
* Added: a plugin icon. The update manifest now points WordPress to the JPKCom logo, so the Updates screen (Dashboard → Updates) shows it next to this plugin instead of the generic pattern wordpress.org generates from the slug

### 1.0.11
* Fixed: block-editor Notes stopped working with this plugin active. Notes are comments of type `note`, read and written through `/wp/v2/comments`, and both the removal of those routes and the short-circuit of every comment query took them down. The routes now stay registered and a `rest_pre_dispatch` gate answers every request that is not a note request — no `type`, `type=comment` or any other type, a regular or unknown comment ID — exactly as a missing route does (`rest_no_route`, 404, WordPress' own message). Note requests reach WordPress, whose permission checks are unchanged, and comment queries asking for notes alone are no longer short-circuited. Public comments, pings, the comment feeds, the dashboard and the admin screens stay disabled
* Security: with the comment routes registered again for Notes, the gate checks the comment WordPress will actually load, not just the ID in the URL. WordPress' comment endpoint takes the ID from an `id` parameter in the query string or the request body before the one in the URL, so a request on a note's route whose `id` parameter names a different comment (`/wp/v2/comments/<note ID>?id=<comment ID>`) gets the 404 as well. A regular comment cannot be read, changed or deleted through a note's route
* Changed: every post now reports zero comments (`get_comments_number`). A post with old approved comments kept `<comments>`, `<wfw:commentRss>` and `<slash:comments>` in `/feed/`, the `replies` links and `<thr:total>` in the Atom feed — pointing at comment feeds that answer 404 — and themes kept printing "N comments". Notes are not part of that count
* Changed: the REST index at `/wp-json/` lists the comment routes again, a side effect of keeping them for Notes; every non-note request on them still answers 404. An `OPTIONS` request on them now answers that 404 too, where the removed route used to yield an empty 200
* Docs: the query-string comment feeds (`?feed=comments-rss2`, `?p=123&feed=rss2`) answering 404 directly instead of WordPress' canonical redirect is documented as deliberate

### 1.0.10
* Security: the plugin header now carries `Update URI: https://github.com/JPKCom/jpkcom-disable-comments`. Without it, WordPress also asks wordpress.org for updates under this plugin's slug, so a same-named plugin published there with a higher version would be offered as an update of this one. With the header, wordpress.org answers nothing for this plugin and updates come only from this repository
* Changed: the updater keeps its manifest cache, its failure marker and its fetch lock in site transients. On multisite the network now fetches the manifest once instead of once per site; on a single site nothing changes. The old per-site entries expire on their own within a day
* Changed: the release workflow downloads phpDocumentor from a fixed GitHub release and verifies its SHA-256 before running it, instead of executing whatever phpdoc.org serves that day. No effect on the shipped plugin

### 1.0.9
* Fixed: existing comments were still publicly readable through the comment feeds. `/comments/feed/` and `<post>/feed/` are built by `WP_Query` directly via `$wpdb` and bypass the `comments_array` filter this plugin relied on, so approved comments stayed retrievable after comments had been switched off. Both now return 404 and the feed discovery links are gone; the regular post feed at `/feed/` is unaffected
* Fixed: the dashboard kept showing comments. `remove_meta_box( 'dashboard_recent_comments', … )` had no effect for two reasons — no widget of that name has existed since WordPress 3.8 (recent comments live inside the Activity widget), and `admin_init` runs before `wp_dashboard_setup()` anyway. "At a Glance" therefore still linked a comment count to the redirected comments screen, and the Activity widget still rendered the full moderation list. Replaced by zeroing `wp_count_comments()` and short-circuiting `comments_pre_query`
* Fixed: comment and trackback support is now removed on `wp_loaded` instead of `admin_init`, so it applies to REST and front-end requests too, not just wp-admin. The two supports are also checked independently — a post type declaring `trackbacks` without `comments` previously kept them
* Fixed: REST responses reported the stored `comment_status`, typically `open`, on a site that refuses comments. Removing post type support cannot change this for `post`, `page` and `attachment`: `WP_REST_Posts_Controller::get_item_schema()` carries a hardcoded `$fixed_schemas` list in which `comments` is always present. `comment_status` and `ping_status` are now reported as `closed`. The stored value is left alone, and the fields keep their place in the response so the block editor sees the shape it expects
* Changed: `comments_open`, `pings_open` and `comments_array` are filtered at `PHP_INT_MAX` instead of priority 20/10, so a theme or plugin hooking in later cannot re-open them
* Added: `tests/test-hooks.php` covers the hook surface and the new callbacks; CI runs it on every pull request and push to `main`

### 1.0.8
* Fixed: the update manifest no longer reports `network: true` for this plugin. The generator defaulted a missing `Network:` header to true, while WordPress' own default for a missing header is "not network-only". Metadata only — WordPress derives network-only from the plugin header via `is_network_only_plugin()`, not from the update manifest
* CI: the lint and guard workflow now also runs on pushes to `main`. It only covered pull requests, so a direct push with bypass rights skipped every check
* Changed: comments, workflow step names and CI output across the repository are now English throughout, and the developer notes in `CLAUDE.md` were translated and trimmed. No effect on the shipped plugin

### 1.0.7
* Changed: `Tested up to` raised to WordPress 7.1
* Changed: the bundled updater's runtime floor now matches the plugin's own minimum. It bailed out below WordPress 6.8 while the plugin header has required 6.9 for several releases, so the check could never fire on a supported installation
* CI: the release manifest's fallback values for `requires` and `tested` now say 6.9 and 7.1. They only apply when the README metadata cannot be read, but a stale fallback would have published a minimum the plugin no longer supports

### 1.0.6
* Added: plugin banners (`assets/banner-1544x500.avif`, `assets/banner-772x250.avif`) — a plain `#3c4955` surface with no lettering. The update manifest already advertised these two URLs, but nothing was published under them, so the plugin card in wp-admin had a broken banner

### 1.0.5
* CI: the release step no longer copies the staging directory into itself, so the ZIP has no empty `jpkcom-disable-comments/jpkcom-disable-comments/` folder
* CI: bumped the pinned GitHub Actions (checkout v7.0.1, setup-python v7.0.0, action-gh-release v3.0.2, fetch-metadata v3.1.0), still pinned to full commit SHAs
* CI: the release ZIP now excludes the development-only `tests/` and `tools/` directories
* CI: security and regression tests now run on every pull request, where a plugin has them

### 1.0.4
* Security: update packages are now verified *before* installation — the verified file is handed to WordPress instead of being downloaded a second time, so the bytes that were checked are the bytes that get installed
* Security: a missing or unfetchable SHA-256 checksum now aborts the update instead of installing unverified code (previously it silently skipped verification)
* Security: pinned every GitHub Action to a full commit SHA and added Dependabot with a 7-day cooldown, so a moved tag can no longer change the release build
* Security: tightened which download the updater claims, so sibling plugins cannot match each other's package
* Fixed: `sprintf()` calls in the updater bound named arguments to a variadic parameter, which raises `ArgumentCountError` on PHP 8.3
* Fixed: the "View Details" modal could fail with a `TypeError` when the manifest omitted `requires_plugins`
* Performance: a failed manifest fetch is now cached for an hour instead of being retried on every admin request
* Added: CI workflow on every pull request (PHP lint, named-argument check, YAML validation, action-pinning guard)

### 1.0.3
* Docs: linked the published PHPDoc API documentation

### 1.0.2
* Added secure self-hosted plugin updates via GitHub with SHA256 checksum verification
* Added an automated release workflow (builds the ZIP, generates the manifest and deploys to gh-pages on tag push)
* Raised the minimum WordPress version to 6.9 and "Tested up to" to WordPress 7.0
* Switched license metadata to the SPDX identifier `GPL-2.0-or-later` with the HTTPS license URI
* Added PHPDoc-generated API documentation, built and deployed to gh-pages on release
* Hardening: enabled `declare(strict_types=1)` and tidied the REST endpoint loop

### 1.0.1
* Removed REST API endpoint for comments

### 1.0.0
* Initial Release
