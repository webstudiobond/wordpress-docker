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
