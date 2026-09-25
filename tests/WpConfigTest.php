<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\TestCase;

final class WpConfigTest extends TestCase
{
    private string $testTmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testTmpDir = sys_get_temp_dir() . '/wp_config_test_' . bin2hex(random_bytes(6));
        mkdir($this->testTmpDir . '/secrets', 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testTmpDir);
        parent::tearDown();
    }

    public function testMissingSecretsThrowsRuntimeException(): void
    {
        $sourceConfig = dirname(__DIR__) . '/examples/data/wp-config.php.example';
        $targetConfig = $this->testTmpDir . '/wp-config.php';
        copy($sourceConfig, $targetConfig);

        $command = sprintf(
            '%s -d display_errors=1 %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($targetConfig)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = [
            'PATH' => (string) getenv('PATH'),
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->testTmpDir, $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertNotSame(0, $exitCode);
        $output = $stdout . $stderr;
        $this->assertStringContainsString('Required Docker Secrets are missing or empty.', $output);
    }

    public function testExecutionWithValidSecretsAndMockSettings(): void
    {
        $sourceConfig = dirname(__DIR__) . '/examples/data/wp-config.php.example';
        $targetConfig = $this->testTmpDir . '/wp-config.php';
        copy($sourceConfig, $targetConfig);

        file_put_contents($this->testTmpDir . '/wp-settings.php', "<?php\necho 'WP_LOADED_OK';\n");

        $requiredSecrets = [
            'db_name' => 'test_db',
            'db_user' => 'test_user',
            'db_password' => 'secret_pass_123',
            'table_prefix' => 'wp_',
            'auth_key' => 'auth_key_test_val_12345678901234567890',
            'secure_auth_key' => 'secure_auth_key_test_val_1234567890',
            'logged_in_key' => 'logged_in_key_test_val_123456789012',
            'nonce_key' => 'nonce_key_test_val_1234567890123456',
            'auth_salt' => 'auth_salt_test_val_1234567890123456',
            'secure_auth_salt' => 'secure_auth_salt_test_val_12345678',
            'logged_in_salt' => 'logged_in_salt_test_val_1234567890',
            'nonce_salt' => 'nonce_salt_test_val_12345678901234',
        ];

        $env = [
            'PATH' => (string) getenv('PATH'),
        ];

        foreach ($requiredSecrets as $key => $val) {
            $filePath = $this->testTmpDir . '/secrets/' . $key . '.txt';
            file_put_contents($filePath, $val . "\r\n");
            $env['WORDPRESS_' . strtoupper($key) . '_FILE'] = $filePath;
        }

        $env['WORDPRESS_DEBUG'] = 'true';
        $env['WORDPRESS_DISABLE_CRON'] = 'true';
        $env['WORDPRESS_CLI_HOST'] = 'example.com';
        $env['WP_REDIS_DISABLED'] = 'true';

        $command = sprintf(
            '%s -d display_errors=1 %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($targetConfig)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->testTmpDir, $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "STDERR: {$stderr}");
        $this->assertStringContainsString('WP_LOADED_OK', $stdout);
    }

    public function testHelperFunctionsDirectly(): void
    {
        $helperScript = <<<'PHP'
<?php
declare(strict_types=1);

$configFile = $argv[1];
$secretFile = $argv[2];

$lines = file($configFile);
$functionCode = '';
$capture = false;
$braceCount = 0;

foreach ($lines as $line) {
    if (str_contains($line, 'function get_docker_secret') || str_contains($line, 'function getenv_docker')) {
        $capture = true;
    }
    if ($capture) {
        $functionCode .= $line;
        $braceCount += substr_count($line, '{') - substr_count($line, '}');
        if ($braceCount === 0 && str_contains($line, '}')) {
            $capture = false;
        }
    }
}

eval($functionCode);

putenv('WORDPRESS_TEST_SECRET_FILE=' . $secretFile);
putenv('TEST_DIRECT_ENV=hello_env');

$sec = get_docker_secret('test_secret');
$env1 = getenv_docker('TEST_DIRECT_ENV');
$env2 = getenv_docker('NON_EXISTENT_ENV', 'fallback_val');

echo json_encode([
    'secret' => $sec,
    'env1' => $env1,
    'env2' => $env2,
], JSON_THROW_ON_ERROR);
PHP;

        $secretPath = $this->testTmpDir . '/secrets/my_secret.txt';
        file_put_contents($secretPath, "my_secret_value\r\n");

        $runnerFile = $this->testTmpDir . '/helper_runner.php';
        file_put_contents($runnerFile, $helperScript);

        $configFile = dirname(__DIR__) . '/examples/data/wp-config.php.example';

        $command = sprintf(
            '%s %s %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($runnerFile),
            escapeshellarg($configFile),
            escapeshellarg($secretPath)
        );

        $output = shell_exec($command);
        $this->assertIsString($output);

        /** @var array{secret: string, env1: string, env2: string} $data */
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('my_secret_value', $data['secret']);
        $this->assertSame('hello_env', $data['env1']);
        $this->assertSame('fallback_val', $data['env2']);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }

        rmdir($path);
    }
}
