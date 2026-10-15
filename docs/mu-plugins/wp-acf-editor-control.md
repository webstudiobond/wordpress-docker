# ACF & Gutenberg Visibility Sync

* **Slug:** `acf-editor-control`
* **Source:** `examples/data/wp-content/mu-plugins/wp-acf-editor-control.php.example`

## Overview

Synchronizes Advanced Custom Fields (ACF) screen settings with the WordPress Block Editor (Gutenberg):

* Native ACF behavior: selecting *Hide on screen -> Content Editor* within an ACF Field Group hides the Classic Editor, but does not disable the Gutenberg Block Editor.
* Automatically enforces Gutenberg lockdown (`use_block_editor_for_post` filter) on specific post types, page templates, and individual post IDs where active ACF field groups have the Content Editor hidden.
* Supports multilingual Polylang setups by resolving translated post IDs and applying rules across all language variations.
* Caches resolved rules in `wp_options` (`wp_acf_editor_control_rules`) on ACF field group save hooks (`acf/update_field_group`, `acf/trash_field_group`, `acf/untrash_field_group`), executing with zero extra database queries during normal page edits.

## Configuration

This plugin requires no environment variables in `wordpress.env`. Configuration is managed directly within ACF Field Group settings in the WordPress dashboard:

* Edit any ACF Field Group.
* Under **Settings** -> **Hide on screen**, check **Content Editor**.
* Save the Field Group. Gutenberg is automatically disabled for matching post types, IDs, or templates.
