# YouTube Channel Statistics & oEmbed Tools

* **Slug:** `youtube`
* **Source:** `mu-plugins/sbs-platform/wp-youtube/` (entrypoint: `wp-youtube.php`)
* **Activation:** `WP_ENABLED_MU_PLUGINS=youtube`

## Overview

Enhances YouTube oEmbed embeds and provides cached channel statistics shortcodes:

* Automatically replaces standard `youtube.com/embed/` URLs with privacy-preserving `youtube-nocookie.com/embed/` endpoints.
* Strips outdated `feature=oembed` parameters and injects clean player settings (`rel=0&playsinline=1&iv_load_policy=3`).
* Registers shortcodes `[ytv]` (views), `[yts]` (subscribers), and `[ytc]` (video count), pulling stats from the YouTube Data API v3 and caching results in WordPress transients (`_transient_wp_youtube_stats_*`) for 12 hours.
* Securely reads API credentials from Docker Secrets (`/run/secrets/wp_youtube_api_key`, `/run/secrets/wp_youtube_channel_id`) or environment variables.

## Configuration (`wordpress.env`)

* `WP_YOUTUBE_OEMBED_CLEANUP=true`
  Applies query string cleanup to YouTube oEmbed iframes. Set to `false` to disable.
  Example: `WP_YOUTUBE_OEMBED_CLEANUP='true'`

* `WP_YOUTUBE_NOCOOKIE=true`
  Rewrites YouTube iframe sources to `youtube-nocookie.com`. Set to `false` to disable.
  Example: `WP_YOUTUBE_NOCOOKIE='true'`

* `WP_YOUTUBE_OEMBED_PARAMS=rel=0&playsinline=1&iv_load_policy=3`
  Custom URL query parameters appended to embedded YouTube videos.
  Example: `WP_YOUTUBE_OEMBED_PARAMS='rel=0&playsinline=1&modestbranding=1'`

* `WP_YOUTUBE_SHORTCODES=false`
  Registers shortcodes for channel stats: `[ytv]`, `[yts]`, `[ytc]`. Set to `true` to enable.
  Example: `WP_YOUTUBE_SHORTCODES='true'`

* `WP_YOUTUBE_API_KEY=`
  YouTube Data API v3 key (fallback when `secrets/wp_youtube_api_key.txt` is not mounted).
  Example: `WP_YOUTUBE_API_KEY='AIzaSy...'`

* `WP_YOUTUBE_CHANNEL_ID=`
  YouTube Channel ID (fallback when `secrets/wp_youtube_channel_id.txt` is not mounted).
  Example: `WP_YOUTUBE_CHANNEL_ID='UC_x5XG1OV2P6uZZ5FSM9Ttw'`
