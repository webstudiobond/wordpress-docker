<?php

declare(strict_types=1);

function ensure_wordpress_stubs(): void
{
    if (!function_exists('fastcgi_finish_request')) {
        function fastcgi_finish_request(): bool
        {
            return true;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $tag, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['test_registered_actions'][$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $acceptedArgs,
            ];
        }
    }
}

function ensure_wordpress_filter_stubs(): void
{
    if (!function_exists('add_filter')) {
        function add_filter(string $tag, callable|string $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['test_registered_filters'][$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
                'accepted_args' => $acceptedArgs,
            ];
        }
    }
}

function ensure_acf_stubs(): void
{
    if (!function_exists('acf_get_field_groups')) {
        /**
         * @return array<int, mixed>
         */
        function acf_get_field_groups(): array
        {
            $groups = $GLOBALS['test_acf_field_groups'] ?? [];
            return is_array($groups) ? array_values($groups) : [];
        }
    }

    if (!function_exists('pll_get_post_translations')) {
        /**
         * @return array<string, int>
         */
        function pll_get_post_translations(int $postId): array
        {
            $map = $GLOBALS['test_pll_translations'] ?? [];
            if (is_array($map) && isset($map[$postId]) && is_array($map[$postId])) {
                /** @var array<string, int> $translations */
                $translations = $map[$postId];
                return $translations;
            }
            return [];
        }
    }

    if (!function_exists('get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            $options = $GLOBALS['test_wp_options'] ?? [];
            if (is_array($options) && array_key_exists($option, $options)) {
                return $options[$option];
            }
            return $default;
        }
    }

    if (!function_exists('update_option')) {
        function update_option(string $option, mixed $value, bool $autoload = true): bool
        {
            $GLOBALS['test_wp_options'][$option] = $value;
            return $autoload;
        }
    }
}

function ensure_telemetry_stubs(): void
{
    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return (bool) ($GLOBALS['test_is_user_logged_in'] ?? false);
        }
    }

    if (!function_exists('wp_doing_ajax')) {
        function wp_doing_ajax(): bool
        {
            return (bool) ($GLOBALS['test_wp_doing_ajax'] ?? false);
        }
    }

    if (!function_exists('wp_is_json_request')) {
        function wp_is_json_request(): bool
        {
            return (bool) ($GLOBALS['test_wp_is_json_request'] ?? false);
        }
    }
}

function ensure_duplicator_stubs(): void
{
    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability, ?int $objectId = null): bool
        {
            $caps = $GLOBALS['test_user_caps'] ?? [];
            if (!is_array($caps)) {
                return false;
            }
            $key = $objectId !== null ? $capability . ':' . $objectId : $capability;
            return (bool) ($caps[$key] ?? $caps[$capability] ?? false);
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.com/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_nonce_url')) {
        function wp_nonce_url(string $actionUrl, string $action = '-1'): string
        {
            return $actionUrl . '&amp;_wpnonce=nonce_' . $action;
        }
    }

    if (!function_exists('check_admin_referer')) {
        function check_admin_referer(string $action = '-1'): bool
        {
            return (bool) ($GLOBALS['test_valid_nonce'][$action] ?? false);
        }
    }

    if (!function_exists('get_post')) {
        function get_post(int $postId): ?object
        {
            $posts = $GLOBALS['test_posts'] ?? [];
            if (is_array($posts) && isset($posts[$postId]) && is_object($posts[$postId])) {
                return $posts[$postId];
            }
            return null;
        }
    }

    if (!function_exists('wp_insert_post')) {
        /**
         * @param array<string, mixed> $postarr
         */
        function wp_insert_post(array $postarr): int
        {
            $GLOBALS['test_inserted_posts'][] = $postarr;
            return (int) ($GLOBALS['test_next_insert_id'] ?? 100);
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['test_current_user_id'] ?? 1);
        }
    }

    if (!function_exists('get_object_taxonomies')) {
        function get_object_taxonomies(string $objectType): mixed
        {
            return $GLOBALS['test_object_taxonomies'][$objectType] ?? [];
        }
    }

    if (!function_exists('wp_get_object_terms')) {
        /**
         * @param array<string, mixed> $args
         */
        function wp_get_object_terms(int $objectIds, string $taxonomies, array $args = []): mixed
        {
            $terms = $GLOBALS['test_object_terms'][$objectIds][$taxonomies] ?? [];
            return $args !== [] ? $terms : [];
        }
    }

    if (!function_exists('wp_set_object_terms')) {
        /**
         * @param array<int, mixed> $terms
         * @return array<int, mixed>
         */
        function wp_set_object_terms(int $objectId, array $terms, string $taxonomy, bool $append = false): array
        {
            $GLOBALS['test_set_terms'][] = [
                'object_id' => $objectId,
                'terms' => $terms,
                'taxonomy' => $taxonomy,
                'append' => $append,
            ];
            return $terms;
        }
    }

    if (!function_exists('get_post_meta')) {
        function get_post_meta(int $postId): mixed
        {
            return $GLOBALS['test_post_meta'][$postId] ?? [];
        }
    }

    if (!function_exists('add_post_meta')) {
        function add_post_meta(int $postId, mixed $metaKey, mixed $metaValue): int
        {
            $GLOBALS['test_added_post_meta'][] = [
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ];
            return 1;
        }
    }

    if (!function_exists('maybe_unserialize')) {
        function maybe_unserialize(mixed $data): mixed
        {
            if (is_string($data) && str_starts_with($data, 'a:')) {
                return @unserialize($data);
            }
            return $data;
        }
    }

    if (!function_exists('wp_slash')) {
        function wp_slash(mixed $value): mixed
        {
            if (is_array($value)) {
                return array_map('wp_slash', $value);
            }
            if (is_string($value)) {
                return addslashes($value);
            }
            return $value;
        }
    }

    if (!function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(string $location): bool
        {
            $GLOBALS['test_redirect_location'] = $location;
            return true;
        }
    }

    if (!function_exists('wp_die')) {
        /**
         * @param array<string, mixed> $args
         */
        function wp_die(string $message = '', string $title = '', array $args = []): void
        {
            $GLOBALS['test_wp_die_calls'][] = [
                'message' => $message,
                'title' => $title,
                'args' => $args,
            ];
        }
    }
}

function ensure_cleanup_stubs(): void
{
    ensure_acf_stubs();

    if (!function_exists('remove_action')) {
        /**
         * @param callable|array<int, mixed>|string $callback
         */
        function remove_action(string $tag, callable|array|string $callback, int $priority = 10): bool
        {
            $GLOBALS['test_removed_actions'][$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
            ];
            return true;
        }
    }

    if (!function_exists('remove_filter')) {
        function remove_filter(string $tag, callable|string $callback, int $priority = 10): bool
        {
            $GLOBALS['test_removed_filters'][$tag][] = [
                'callback' => $callback,
                'priority' => $priority,
            ];
            return true;
        }
    }

    if (!function_exists('is_admin')) {
        function is_admin(): bool
        {
            return (bool) ($GLOBALS['test_is_admin'] ?? false);
        }
    }

    if (!function_exists('wp_deregister_script')) {
        function wp_deregister_script(string $handle): void
        {
            $GLOBALS['test_deregistered_scripts'][] = $handle;
        }
    }
}
