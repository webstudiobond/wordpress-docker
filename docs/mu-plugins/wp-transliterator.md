# Slug & Filename Transliterator

* **Slug:** `transliterator`
* **Source:** `mu-plugins/sbs-platform/wp-transliterator/` (entrypoint: `wp-transliterator.php`)
* **Activation:** `WP_ENABLED_MU_PLUGINS=transliterator`

## Overview

Transliterates non-Latin characters in post/page slugs and media filenames:

* Filters `sanitize_title` strictly during the `'save'` context, converting Cyrillic, Greek, Georgian, Armenian, and Hebrew characters to clean Latin URL slugs without modifying existing database slugs or altering frontend route lookups.
* Filters `sanitize_file_name` to normalize uploaded media filenames, resolving macOS NFD decomposed Unicode sequences and stripping European diacritics via `remove_accents()`.
* Runs entirely in memory using compiled PHP class constant translation maps (`universal` multilingual, `uk`, `ISO9`, `bel`, `bg_BG`, `mk_MK`, `sr_RS`, `kk`, `el`, `hy`, `ka_GE`, `he_IL`) with zero database queries.

## Configuration (`wordpress.env`)

* `WP_TRANSLIT_TABLE=universal`
  Specifies the character conversion table: `universal` (comprehensive multi-language conversion, default), `auto` (dynamically selects table based on WordPress locale via `get_locale()`), or explicit table codes (`uk`, `ISO9`, `bel`, `bg_BG`, `mk_MK`, `sr_RS`, `kk`, `el`, `hy`, `ka_GE`, `he_IL`).
  Example: `WP_TRANSLIT_TABLE='universal'`

* `WP_TRANSLIT_SLUGS=true`
  Transliterates newly saved post, page, and taxonomy term slugs. Set to `false` to disable.
  Example: `WP_TRANSLIT_SLUGS='true'`

* `WP_TRANSLIT_FILENAMES=true`
  Transliterates newly uploaded media filenames. Set to `false` to disable.
  Example: `WP_TRANSLIT_FILENAMES='true'`

* `WP_TRANSLIT_OVERRIDES=`
  Comma-separated list of custom `character:replacement` rules applied over the chosen table.
  Example: `WP_TRANSLIT_OVERRIDES='ц:ts,Ц:TS,щ:shch,Щ:SHCH'`
