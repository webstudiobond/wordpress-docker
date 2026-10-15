# Article Reading Time Shortcode

* **Slug:** `reading-time`
* **Source:** `examples/data/wp-content/mu-plugins/wp-reading-time.php.example`

## Overview

Calculates post reading time for single post templates (Elementor Single Post layouts, block templates, custom PHP themes):

* Reads raw `post_content` directly without firing nested `the_content` filters, preventing infinite recursion and performance bottlenecks.
* Strips HTML `<script>`, `<style>`, and shortcode tags, while maintaining word boundaries between adjacent markup tags.
* Counts words using Unicode character classes (`\p{L}`, `\p{N}`), supporting Latin, Cyrillic, Greek, Asian, and Arabic scripts accurately.
* Rounds reading duration up to the nearest whole minute (minimum: `1 min`).
* Outputs plain text by default, with optional BEM markup (`<span class="reading-time">`), inline SVG timer icon, and scoped styling.

## Shortcode Attributes (`[reading_time]`)

This plugin requires no `wordpress.env` variables. Customize output directly via shortcode attributes:

* `wpm="200"` — reading speed in words per minute (default: `200`).
* `approx="true"` — prepends an approximation tilde `~` (default: `true`).
* `label="min"` — invariant text suffix (e.g., `min`, `mins`).
* `min1="minute"` and `min2="minutes"` — 2-form pluralization (e.g., English `minute|minutes`, Spanish `minuto|minutos`, German `Minute|Minuten`).
* `plural="minuta|minuty|minut"` — 3-form pluralization for languages with 3 plural forms like Polish (or via attributes `min1`, `min2`, `min3`).
* `icon="true"` — prepends an inline SVG stopwatch icon.
* `prefix="Read time:"` — custom text placed before the reading time.
* `postfix=""` — custom text placed after the reading time.
* `html="true"` — wraps output in structured `<span class="reading-time">` tags.
* `class="custom-class"` — appends custom CSS classes to the container wrapper.

## Usage Examples

* Plain text default:
  `[reading_time]`
  Output: `~ 3 min`

* Explicit English pluralization:
  `[reading_time approx="false" min1="minute" min2="minutes"]`
  Output: `3 minutes`

* Full BEM wrapper with SVG icon:
  `[reading_time icon="true" prefix="Estimated:" min1="minute" min2="minutes"]`
  Output: `<span class="reading-time"><svg class="icon reading-time__icon" ...></svg> <span class="reading-time__prefix">Estimated:</span> <span class="reading-time__value">~ 3 minutes</span></span>`
