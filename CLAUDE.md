# JPKCom Disable Comments – Developer Reference

## Plugin Overview

Globally disables WordPress comment functionality: removes comment support from all post types, closes comments/pings, hides the admin Comments screen and menu/admin-bar entries, and removes the REST API comment endpoints.

- **Text Domain:** none declared (defaults to slug `jpkcom-disable-comments`)
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
├── admin_init     → redirect edit-comments.php, remove dashboard widget,
│                     strip comments/trackbacks support from all post types
├── comments_open / pings_open  → __return_false
├── comments_array → __return_empty_array
├── admin_menu     → remove_menu_page( 'edit-comments.php' )
├── admin_bar_menu → remove_node( 'comments' ) (prio 999)
└── rest_endpoints → unset /wp/v2/comments routes
```

---

## Behaviour

| Hook | Type | Effect |
|------|------|--------|
| `admin_init` | action | Redirects the comments screen to the dashboard, removes the recent-comments widget, removes `comments`/`trackbacks` support from every post type |
| `comments_open`, `pings_open` | filter (prio 20) | Force closed |
| `comments_array` | filter | Always returns an empty array |
| `admin_menu` | action | Removes the Comments admin menu entry |
| `admin_bar_menu` | action (prio 999) | Removes the Comments node from the admin bar |
| `rest_endpoints` | filter | Removes `/wp/v2/comments` and `/wp/v2/comments/*` routes (iterated via `array_keys()`) |

---

## Constants

| Constant | Value | Purpose |
|----------|-------|---------|
| `JPKCOM_DISABLE_COMMENTS_VERSION` | `'1.0.2'` | Plugin version (sync with header/README/phpdoc.xml) |

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
- Shared JPKCom updater (downstream copy of upstream `jpkcom-post-filter`; do not edit per-plugin). SHA256 verification, `wp_safe_remote_get()`, URL validation, race-condition lock, 24 h cache, timing-safe `hash_equals()`.
- Hooks: `plugins_api`, `site_transient_update_plugins`, `upgrader_process_complete`, `upgrader_pre_download`.

---

## Release Workflow

Triggered by **pushing a `v*` tag**; the workflow creates the GitHub release automatically. Pipeline: setup PHP/Python/Pandoc/GraphViz → README metadata → slug-named ZIP → SHA256 → upload ZIP + `.sha256` → `plugin_<slug>.json` manifest → PHPDoc → deploy to `gh-pages`.

---

## Security Checklist

- `declare(strict_types=1)` in every PHP file
- Typed closures throughout; `wp_safe_redirect()` for the admin redirect
- Updater: SHA256 verification + URL validation (audited separately)

---

## Release Checklist

1. Bump version in: header `Version:` + `Stable tag:`, `JPKCOM_DISABLE_COMMENTS_VERSION`, `README.md`, `phpdoc.xml`
2. Add a `### x.y.z` block to `## Changelog` in `README.md`
3. Commit, tag `vx.y.z`, push the tag → the workflow builds and publishes everything
