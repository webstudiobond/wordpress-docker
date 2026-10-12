<?php

declare(strict_types=1);

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @var array<string, list<string>>
         */
        public array $errors = [];

        /**
         * @var array<string, mixed>
         */
        public array $error_data = [];

        public function __construct(
            string|int $code = '',
            string $message = '',
            mixed $data = ''
        ) {
            if ($code !== '') {
                $codeKey = (string) $code;
                $this->errors[$codeKey][] = $message;
                if ($data !== '') {
                    $this->error_data[$codeKey] = $data;
                }
            }
        }

        public function get_error_code(): string|int
        {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message(string|int $code = ''): string
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->errors[(string) $code][0] ?? '';
        }

        public function get_error_data(string|int $code = ''): mixed
        {
            if ($code === '') {
                $code = $this->get_error_code();
            }
            return $this->error_data[(string) $code] ?? null;
        }
    }
}
