# Post & Page Duplicator

* **Slug:** `post-duplicator`
* **Source:** `mu-plugins/sbs-platform/wp-post-duplicator/` (entrypoint: `wp-post-duplicator.php`)
* **Activation:** `WP_ENABLED_MU_PLUGINS=post-duplicator`

## Overview

Provides native post and page duplication without third-party plugins:

* Appends a *Duplicate* row action link to admin post lists and a *Copy to a new draft* button to the admin bar when viewing or editing existing content.
* Deep-clones post content, excerpt, post parent, menu order, format, taxonomy terms (categories, tags, custom taxonomies), and metadata (including complex serialized ACF fields and page builder layouts).
* Preserves Polylang language assignments while stripping translation group synchronization IDs and edit locks to prevent overwriting existing multilingual links or slugs.
* Securely verifies admin nonces (`wp-post-duplicator-action_{ID}`) and enforces user capability checks before performing cloning operations.

## Configuration (`wordpress.env`)

* `WP_DUPLICATOR_POST_TYPES=post,page`
  Comma-separated list of post type slugs eligible for duplication, or `none` to disable.
  Example: `WP_DUPLICATOR_POST_TYPES='post,page,product,portfolio'`

* `WP_DUPLICATOR_STATUS=draft`
  Post status assigned to newly cloned items: `draft`, `pending`, `private`, or `publish`.
  Example: `WP_DUPLICATOR_STATUS='draft'`

* `WP_DUPLICATOR_KEEP_AUTHOR=true`
  Whether the cloned post keeps the original author (`true`) or assigns authorship to the user performing the duplication (`false`).
  Example: `WP_DUPLICATOR_KEEP_AUTHOR='false'`

* `WP_DUPLICATOR_CAPABILITY=edit_posts`
  Baseline capability required to access duplication actions (in addition to per-post `edit_post` verification).
  Example: `WP_DUPLICATOR_CAPABILITY='edit_pages'`

### Discovering Registered Post Types

Retrieve all public and custom post types registered on the site:

```bash
docker compose -f /home/${SITE_USER}/docker-compose.yaml run --rm --no-deps wp-cli post-type list --field=name
```
