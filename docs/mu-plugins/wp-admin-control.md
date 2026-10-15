# Administrative Hardening, RBAC & Dashboard Sanitization

* **Slug:** `admin-control`
* **Source:** `examples/data/wp-content/mu-plugins/wp-admin-control.php.example`

## Overview

Comprehensive administrative hardening, zero-knowledge role separation, and dashboard interface sanitization:

* **Zero-Knowledge Super Administrator Cloaking:** Designates break-glass Super Administrator accounts via HMAC-SHA512 hashes stored in Docker Secrets (`/run/secrets/wp_super_admins`). Super administrators are completely invisible in user listings, aggregate counts, security plugin views (2FA / Passkeys / Remembered Devices), REST API endpoints, post author dropdowns, and frontend author archives. Standard administrators cannot view, edit, reassign, execute bulk actions against, or reset passwords for super-administrators.
* **Declarative Access & Route Enforcement:** Distinguishes cosmetic `hide` (declutters menus while keeping direct URL access intact) from server-side `block` (terminates requests on matching admin routes). Super-administrators bypass `block` rules, while matching `hide` rules still apply.
* **Content Access Control:** Restricts specific post, page, or custom post type IDs and slugs from edit screens, trash/delete operations, list views, and query loops for non-superadmin users.
* **Interface Sanitization:** Removes the Screen Options drawer, contextual Help tabs, unwanted Admin Bar nodes, dashboard widgets, footer notices, profile fields, legacy sidebar widgets, and Gutenberg block types.

## Super Administrator Setup

Generate hashes interactively inside the container using `wp-guard` without exposing passwords in shell history or process tables:

```bash
# Initialize secrets file with the first super administrator:
docker compose -f /home/${SITE_USER}/docker-compose.yaml exec wordpress \
  wp-guard superadmin | sudo tee /home/${SITE_USER}/secrets/wp_super_admins.txt > /dev/null

# Append an additional super administrator:
docker compose -f /home/${SITE_USER}/docker-compose.yaml exec wordpress \
  wp-guard superadmin | sudo tee -a /home/${SITE_USER}/secrets/wp_super_admins.txt > /dev/null

sudo chown root:${SITE_USER} /home/${SITE_USER}/secrets/wp_super_admins.txt
sudo chmod 0440 /home/${SITE_USER}/secrets/wp_super_admins.txt
```

Mount `wp_super_admins` under `secrets:` in `docker-compose.yaml`:

```yaml
secrets:
  wp_super_admins:
    file: ./secrets/wp_super_admins.txt
```

## Rule Engine Syntax

Format: `target1,target2|modifier1|modifier2; target3|modifier1`

* **Role Audience:** `role:editor,author` (matches users with specified roles), `!role:administrator` (inverts role check).
* **User ID Audience:** `user:2,5` (matches specific WordPress user IDs), `!user:1` (inverts user ID check).
* **Superadmin Exemption:** `superadmins` (super administrators only), `!superadmins` (all users except super administrators).
* **Global Default:** `all` (matches all authenticated users).

## Sidebar Menu & Route Control

* `WP_ADMIN_SIDEBAR_MENU_CONTROL=`
  Controls sidebar menu item visibility (`hide`) and server-side route access (`block`). Submenu targets use `parent:child`.
  Example: `WP_ADMIN_SIDEBAR_MENU_CONTROL='plugins.php,themes.php|block:!administrator; tools.php:import|hide:administrator; edit.php?post_type=page|block:!3'`

### Discovering Menu and Submenu Slugs

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval \
  'global $menu, $submenu; echo "Top-level:\n"; foreach ($menu as $m) { if (!empty($m[2])) echo "  {$m[2]} ({$m[0]})\n"; } echo "Submenu:\n"; foreach ($submenu as $p => $subs) { foreach ($subs as $s) { echo "  $p:{$s[2]} ({$s[0]})\n"; } }'
```

* `WP_ADMIN_SIDEBAR_MENU_ORDER=`
  Point-based numeric positioning for top-level admin menu items with collision-safe decimal offsets. Default core positions: `index.php:2`, `edit.php:5`, `upload.php:10`, `edit.php?post_type=page:20`, `edit-comments.php:25`, `themes.php:60`, `plugins.php:65`, `users.php:70`, `tools.php:75`, `options-general.php:80`.
  Example: `WP_ADMIN_SIDEBAR_MENU_ORDER='edit.php?post_type=page:6,upload.php:7,tools.php:90'`

* `WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER=`
  Inserts visual menu separators (`<li class="wp-menu-separator">`) immediately after specified menu item slugs.
  Example: `WP_ADMIN_SIDEBAR_MENU_SEPARATORS_AFTER='upload.php,edit.php?post_type=page'`

## Top-Bar (Toolbar) & Custom Menus

* `WP_ADMIN_TOPBAR_CUSTOM_MENU_{N}_*`
  Registers custom dropdown menus in the top Admin Bar (where `{N}` starts at `1`). Directives include `_ID` (optional node identifier), `_TITLE` (display text), `_ICON` (dashicons class, e.g. `dashicons-admin-tools`), `_TARGET` (audience expression, e.g. `role:administrator`), `_SCOPE` (`backend`, `frontend`, or `both`), and `_ITEMS` (semicolon-delimited list of items, e.g. `[Group] Item Title|URL; Another Link|https://example.com`).

* `WP_ADMIN_TOPBAR_MENU_ORDER=`
  Point-based numeric ordering for top-level toolbar nodes (`site-name`, `wp-logo`, `updates`, `comments`, `new-content`, `my-account`).
  Example: `WP_ADMIN_TOPBAR_MENU_ORDER='site-name:1,custom-cache:2,new-content:3'`

* `WP_ADMIN_TOPBAR_MENU_REMOVE=`
  Removes specific Admin Bar nodes by their node ID.
  Available core node IDs: `wp-logo`, `site-name`, `customize`, `updates`, `comments`, `new-content`, `edit`, `my-account`, `search`.
  Example: `WP_ADMIN_TOPBAR_MENU_REMOVE='wp-logo,comments,updates|!role:administrator'`

* `WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE=`
  Isolates the user profile menu in the topbar (removes user avatar/name node, strips profile link, preserves only Log Out).
  Example: `WP_ADMIN_TOPBAR_USER_PROFILE_REMOVE='!superadmins'`

* `WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE=`
  Removes items from the `+ New` dropdown menu by their sub-node IDs (`new-post`, `new-media`, `new-page`, `new-user`).
  Example: `WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_REMOVE='new-user,new-media|role:author'`

* `WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER=`
  Reorders items under the `+ New` dropdown.
  Example: `WP_ADMIN_TOPBAR_MENU_NEW_CONTENT_ORDER='new-page,new-post,new-media'`

## Content Object Access Control

* `WP_ADMIN_CONTENT_ACCESS_RULES=`
  Blocks editing, deleting, viewing in tables, and querying specific post/page IDs or slugs, while preserving public frontend routes.
  Example: `WP_ADMIN_CONTENT_ACCESS_RULES='2,3,4,89|!superadmins; 42,108|role:editor; slug:about-us|!superadmins; page:contact|!superadmins'`

## Interface, Privacy & Screen Options

* `WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP=`
  Hides the Screen Options drawer and contextual Help tabs for matching audience.
  Example: `WP_ADMIN_HIDE_SCREEN_OPTIONS_AND_HELP='!superadmins'`

* `WP_ADMIN_HIDE_SUPERADMIN_POSTS=false`
  Excludes posts authored by super-administrators from frontend query loops (`author__not_in`) and REST API collections (`author_exclude`). When `false`, posts authored by super-administrators remain visible on the site.
  Example: `WP_ADMIN_HIDE_SUPERADMIN_POSTS='true'`

* `WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS=true`
  Purges external Google Fonts styles and preconnect hints from the dashboard. Set to `false` to keep.
  Example: `WP_ADMIN_BLOCK_EXTERNAL_GOOGLE_FONTS='true'`

## Dashboard Widgets & Welcome Panel

* `WP_ADMIN_DASHBOARD_WIDGETS_REMOVE=`
  Removes standard dashboard widgets and the WordPress Welcome panel.
  Available core targets: `welcome-panel`, `dashboard_quick_press`, `dashboard_primary`, `dashboard_right_now`, `dashboard_activity`, `dashboard_site_health`.
  Example: `WP_ADMIN_DASHBOARD_WIDGETS_REMOVE='welcome-panel,dashboard_quick_press,dashboard_primary|!role:administrator'`

* `WP_ADMIN_DASHBOARD_WIDGET_{N}_*`
  Registers custom informational widgets on the admin dashboard (where `{N}` starts at `1`). Directives include `_TITLE` (widget heading), `_TARGET` (audience expression, e.g. `role:editor,author`), and `_CONTENT` (micro-markdown or plain text content).
  Example: `WP_ADMIN_DASHBOARD_WIDGET_1_TITLE='Editorial Guidelines'`

## Admin Footer Controls

* `WP_ADMIN_FOOTER_LEFT=`
  Custom text or `remove` for the left admin footer text (`admin_footer_text`).
  Example: `WP_ADMIN_FOOTER_LEFT='remove|!superadmins; © 2026 Example Corp|role:editor'`

* `WP_ADMIN_FOOTER_RIGHT=`
  Custom text or `remove` for the right admin footer version text (`update_footer`).
  Example: `WP_ADMIN_FOOTER_RIGHT='remove|!superadmins; v2.1.0|role:administrator'`

## Advanced Sanitization (Notices, Blocks, Fields, Widgets, TinyMCE)

* `WP_ADMIN_HIDE_NOTICES=`
  Suppresses non-inline admin notices, banners, and WordPress pointers (`wp-pointer`).
  Example: `WP_ADMIN_HIDE_NOTICES='!superadmins'`

* `WP_ADMIN_GUTENBERG_BLOCKS_REMOVE=`
  Restricts registered Gutenberg block types via `allowed_block_types_all`. Supports category targets (`category:theme`, `category:widgets`, `category:embed`), human-readable UI aliases (`loginout`, `query-loop`, `site-logo`, `time-to-read`, `template-part`), namespace wildcards (`woocommerce/*`, `plugin-namespace/*`), and explicit block slugs (`core/archives`, `core/calendar`).
  Example: `WP_ADMIN_GUTENBERG_BLOCKS_REMOVE='category:theme,archives,calendar,latest-comments,rss|!superadmins'`

### Discovering Registered Gutenberg Block Types

**Browser DevTools Console (Copies Full List to Clipboard):**
Open developer tools (F12) on any post or page edit screen and run:
```javascript
copy(wp.data.select('core/blocks').getBlockTypes().map(b => `${b.title} -> ${b.name} (${b.category})`).sort().join('\n'))
```
This automatically copies the entire alphabetically sorted list of blocks (`Title -> slug (category)`) directly to your clipboard. Paste it into any text editor to search for specific block titles (including custom third-party plugin blocks).

**WP-CLI Command (JSON for `jq` Filtering):**
```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval \
  '$out = []; foreach (WP_Block_Type_Registry::get_instance()->get_all_registered() as $n => $b) { $out[] = ["name" => $n, "title" => (string) ($b->title ?? ""), "category" => (string) ($b->category ?? "")]; } echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";' | jq .
```

* `WP_ADMIN_PROFILE_FIELDS_REMOVE=`
  Removes personal options, contact methods, name fields, and third-party plugin profile blocks on `profile.php`.
  Core targets: `syntax_highlighting`, `color_scheme`, `shortcuts`, `toolbar`, `user_login` (or `username`), `first_name`, `last_name`, `nickname`, `display_name`, `url` (or `website`), `description` (or `bio`), `profile_picture` (or `avatar`), `language`, `sessions`, `application_passwords`.
  Composite section targets: `about_yourself` (or `about`) removes both biographical info and profile picture and eliminates the section heading. When `description` is removed, the section heading is also automatically hidden if avatars are disabled or removed.
  Custom & plugin targets: arbitrary row wrappers (e.g. `user-xxx-wrap`), input names/IDs, or container IDs can also be specified directly.
  Example: `WP_ADMIN_PROFILE_FIELDS_REMOVE='syntax_highlighting,color_scheme,toolbar|!superadmins; user_login,url,description,profile_picture'`

### Discovering User Profile Fields & Contact Methods

**Browser Console Discovery (Recommended — Captures All Core & Plugin Fields Directly to Clipboard):**
Open developer tools (F12) on the WordPress user profile screen (`/wp-admin/profile.php`) and execute:
```javascript
copy((function() {
  const result = [];
  let section = 'General';
  const profileForm = document.querySelector('#your-profile') || document.querySelector('form[action*="profile.php"]') || document.body;
  profileForm.querySelectorAll('h2, h3, table.form-table tr, .application-passwords').forEach(el => {
    if (window.getComputedStyle(el).display === 'none') return;
    if (/^H[23]$/i.test(el.tagName)) {
      section = el.textContent.trim();
    } else if (el.tagName === 'TR') {
      const label = el.querySelector('th label')?.textContent.trim() || el.querySelector('th')?.textContent.trim() || '';
      const input = el.querySelector('input, select, textarea');
      const name = input?.getAttribute('name') || input?.id || '';
      const wrapMatch = el.className.match(/user-([a-z0-9_-]+)-wrap/);
      const field = wrapMatch ? wrapMatch[1] : (name || el.id || '');
      if (label || field) {
        result.push({ section: section, label: label.replace(/\s+/g, ' '), field: field });
      }
    } else if (el.classList.contains('application-passwords')) {
      result.push({ section: section, label: 'Application Passwords', field: 'application_passwords' });
    }
  });
  return JSON.stringify(result, null, 2);
})())
```
This automatically copies the structured JSON list of all rendered profile sections, field labels, and exact field names/selectors directly to your clipboard. Paste it into your text editor to search and configure exact targets.

* `WP_ADMIN_SIDEBAR_WIDGETS_REMOVE=`
  Unregisters legacy sidebar widgets via `unregister_widget()`.
  Supports shorthand names (`archives`, `calendar`, `categories`, `custom_html`, `meta`, `nav_menu`, `pages`, `recent_comments`, `recent_posts`, `rss`, `search`, `tag_cloud`, `text`) or full class names (e.g. `WP_Widget_Archives`).
  Example: `WP_ADMIN_SIDEBAR_WIDGETS_REMOVE='archives,calendar,meta,rss|!superadmins'`

### Discovering Registered Classic Sidebar Widgets

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli eval '
global $wp_widget_factory;
$widgets = [];
if (isset($wp_widget_factory->widgets) && is_iterable($wp_widget_factory->widgets)) {
  foreach ($wp_widget_factory->widgets as $class => $obj) {
    $widgets[] = [
      "slug"  => (string) ($obj->id_base ?? ""),
      "class" => (string) $class,
      "title" => (string) ($obj->name ?? ""),
    ];
  }
}
echo json_encode($widgets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
' | jq .
```

* `WP_ADMIN_TINYMCE_BUTTONS_REMOVE=`
  Strips toolbar buttons from the classic TinyMCE editor.
  Available core buttons: `bold`, `italic`, `bullist`, `numlist`, `blockquote`, `alignleft`, `aligncenter`, `alignright`, `link`, `unlink`, `wp_more`, `spellchecker`, `fullscreen`, `wp_adv`, `strikethrough`, `hr`, `forecolor`, `pastetext`, `removeformat`, `charmap`, `outdent`, `indent`, `undo`, `redo`, `wp_help`, `formatselect`, `fontselect`, `fontsizeselect`, `backcolor`.
  Example: `WP_ADMIN_TINYMCE_BUTTONS_REMOVE='fontselect,fontsizeselect,backcolor,fullscreen|!superadmins'`

### Discovering Registered TinyMCE Toolbar Buttons

**Browser DevTools Console (Copies Active Editor Buttons to Clipboard):**
Open developer tools (F12) on any classic post/page editor screen and execute:
```javascript
copy(Object.keys(window.tinymce?.activeEditor?.buttons || {}).sort())
```
This automatically copies the complete list of registered TinyMCE button slugs (core and plugin-registered) directly to your clipboard.

## Table Columns & Edit Meta Boxes

* `WP_ADMIN_HIDDEN_COLUMNS=`
  Removes table columns from post/page/user list screens.
  Available core columns: `author`, `categories`, `tags`, `comments`, `date`, `cb`.
  Example: `WP_ADMIN_HIDDEN_COLUMNS='author,tags,comments|role:editor; date|user:4'`

* `WP_ADMIN_HIDDEN_META_BOXES=`
  Removes meta boxes and Gutenberg sidebar panels from post and page edit screens.
  Available core identifiers: `tags`, `categories`, `featured_image`, `excerpt`, `discussion`, `page_attributes`, `slug`, `author`, `template`, `revisions`, `custom_fields`. Custom plugin meta box IDs or Gutenberg sidebar panels (e.g. `custom_meta_box`) are passed directly.
  Example: `WP_ADMIN_HIDDEN_META_BOXES='tags,featured_image,excerpt; custom_meta_box,custom_panel_id|!superadmins'`
