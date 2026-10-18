<?php

declare(strict_types=1);

if (!function_exists('unregister_widget')) {
    function unregister_widget(string $widgetClass): void
    {
    }
}

if (!function_exists('remove_action')) {
    function remove_action(string $tag, callable|string $callback, int $priority = 10): bool
    {
        return true;
    }
}

if (!function_exists('remove_meta_box')) {
    function remove_meta_box(string $id, string|object $screen, string $context): void
    {
    }
}

if (!function_exists('wp_add_dashboard_widget')) {
    function wp_add_dashboard_widget(string $widgetId, string $widgetName, callable $callback): void
    {
        $callback();
    }
}
