<?php

declare(strict_types=1);

if (!class_exists('WP_Block_Type_Registry')) {
    /**
     * Test stub providing registered Gutenberg block types and categories.
     */
    class WP_Block_Type_Registry
    {
        private static ?self $instance = null;

        public static function get_instance(): self
        {
            return self::$instance ??= new self();
        }

        /**
         * @return array<string, object>
         */
        public function get_all_registered(): array
        {
            return [
                'core/paragraph' => new \stdClass(),
                'core/heading' => new \stdClass(),
            ];
        }

        public function get_registered(string $name): ?object
        {
            if ($name === 'core/heading') {
                return (object) ['category' => 'text'];
            }

            return null;
        }
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool
    {
        return true;
    }
}
