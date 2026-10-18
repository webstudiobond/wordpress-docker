<?php

declare(strict_types=1);

if (!class_exists('WP_Admin_Bar_Stub')) {
    /**
     * Test stub providing WordPress admin bar node management methods.
     */
    class WP_Admin_Bar_Stub
    {
        /**
         * @var list<array<string, mixed>>
         */
        public array $addedNodes = [];

        /**
         * @var array<string, object>
         */
        public array $nodes = [];

        /**
         * @param array<string, mixed> $node
         */
        public function add_node(array $node): void
        {
            $this->addedNodes[] = $node;
            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                $this->nodes[$id] = (object) $node;
            }
        }

        public function remove_node(string $id): void
        {
            unset($this->nodes[$id]);
        }

        /**
         * @return array<string, object>
         */
        public function get_nodes(): array
        {
            return $this->nodes;
        }

        public function get_node(string $id): ?object
        {
            return $this->nodes[$id] ?? null;
        }
    }
}

if (!class_exists('WP_Admin_Bar_Remove_Only_Stub')) {
    /**
     * Test stub providing only remove_node method to test fallback guards.
     */
    class WP_Admin_Bar_Remove_Only_Stub
    {
        public function remove_node(string $id): void
        {
        }
    }
}
