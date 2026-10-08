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
        function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
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
        function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
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
