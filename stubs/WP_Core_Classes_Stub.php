<?php

declare(strict_types=1);

if (!class_exists('WP_REST_Request_Stub')) {
    /**
     * Test stub providing get_route method for REST dispatch testing.
     */
    class WP_REST_Request_Stub
    {
        public function __construct(public string $route = '')
        {
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}

if (!class_exists('WP_Query_Stub')) {
    /**
     * Test stub providing set_404 method for template redirect testing.
     */
    class WP_Query_Stub
    {
        public bool $is404 = false;

        public function set_404(): void
        {
            $this->is404 = true;
        }
    }
}
