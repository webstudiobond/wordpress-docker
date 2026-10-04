<?php

declare(strict_types=1);

namespace WordPressDocker\Tests;

use PHPUnit\Framework\TestCase;
use WpNotifyMailer;

final class WpNotifyTest extends TestCase
{
    private string $testTmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testTmpDir = sys_get_temp_dir() . '/wp_notify_test_' . bin2hex(random_bytes(6));
        mkdir($this->testTmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        putenv('NOTIFY_SOCKET_PATH');
        putenv('NOTIFY_MAX_ATTACHMENT_SIZE_MB');
        $this->removeDirectory($this->testTmpDir);
        parent::tearDown();
    }

    public function testEmptyRecipientsReturnsFalse(): void
    {
        $mailer = new WpNotifyMailer();

        $this->assertFalse($mailer->send('', 'Subject', 'Message'));
        $this->assertFalse($mailer->send('   ', 'Subject', 'Message'));
        $this->assertFalse($mailer->send([], 'Subject', 'Message'));
        $this->assertFalse($mailer->send(['   ', ''], 'Subject', 'Message'));
    }

    public function testMissingSocketFileLogsErrorAndReturnsFalse(): void
    {
        $nonExistentSocket = $this->testTmpDir . '/missing.sock';
        $mailer = new WpNotifyMailer(socketPath: $nonExistentSocket);

        $this->assertFalse($mailer->send('admin@example.com', 'Subject', 'Message'));
    }

    public function testJsonSerializationExceptionReturnsFalse(): void
    {
        $socketFile = $this->testTmpDir . '/dummy.sock';
        touch($socketFile);

        $mailer = new WpNotifyMailer(
            socketPath: $socketFile,
            transport: fn(): bool => true
        );

        $invalidUtf8Subject = "Test \xB1\x31";
        $this->assertFalse($mailer->send('admin@example.com', $invalidUtf8Subject, 'Message'));
    }

    public function testSuccessfulSendWithInjectedTransport(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;
        $capturedSocket = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload, &$capturedSocket): bool {
            $capturedSocket = $socket;
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(
            socketPath: $socketFile,
            transport: $transport
        );

        $result = $mailer->send(
            to: 'admin@example.com, security@example.org',
            subject: 'Security Alert',
            message: 'Suspicious activity detected'
        );

        $this->assertTrue($result);
        $this->assertSame($socketFile, $capturedSocket);
        $this->assertIsArray($capturedPayload);
        $this->assertSame(['admin@example.com', 'security@example.org'], $capturedPayload['to']);
        $this->assertSame('Security Alert', $capturedPayload['subject']);
        $this->assertSame('Suspicious activity detected', $capturedPayload['body_text']);
        $this->assertSame('', $capturedPayload['body_html']);
        $this->assertSame('', $capturedPayload['reply_to']);
        $this->assertSame([], $capturedPayload['attachments']);
    }

    public function testHtmlHeaderSetsHtmlBodyAndClearsTextBody(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $headers = "Content-Type: text/html; charset=UTF-8\r\nReply-To: support@example.com\r\n\r\n";
        $result = $mailer->send(
            to: ['user@example.net'],
            subject: 'Welcome HTML',
            message: '<h1>Welcome</h1>',
            headers: $headers
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertSame('', $capturedPayload['body_text']);
        $this->assertSame('<h1>Welcome</h1>', $capturedPayload['body_html']);
        $this->assertSame('support@example.com', $capturedPayload['reply_to']);
    }

    public function testArrayHeadersParsing(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $headers = [
            'content-type: text/html',
            'reply-to: contact@example.org',
            '',
        ];

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Array Headers',
            message: '<p>Body</p>',
            headers: $headers
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertSame('<p>Body</p>', $capturedPayload['body_html']);
        $this->assertSame('contact@example.org', $capturedPayload['reply_to']);
    }

    public function testAttachmentsProcessingWithValidFiles(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        $file1 = $this->testTmpDir . '/report.txt';
        file_put_contents($file1, 'Sample report content');

        $file2 = $this->testTmpDir . '/data.bin';
        file_put_contents($file2, "\x00\x01\x02\x03\x04");

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'With Attachments',
            message: 'See attached',
            attachments: [$file1, $file2]
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertIsArray($capturedPayload['attachments']);
        $this->assertCount(2, $capturedPayload['attachments']);

        $att1 = $capturedPayload['attachments'][0];
        $this->assertSame('report.txt', $att1['filename']);
        $this->assertSame(base64_encode('Sample report content'), $att1['content_base64']);

        $att2 = $capturedPayload['attachments'][1];
        $this->assertSame('data.bin', $att2['filename']);
        $this->assertSame(base64_encode("\x00\x01\x02\x03\x04"), $att2['content_base64']);
    }

    public function testSingleStringAttachment(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        $file = $this->testTmpDir . '/single.txt';
        file_put_contents($file, 'Single file');

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Single Attachment',
            message: 'Message body',
            attachments: $file
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertCount(1, $capturedPayload['attachments']);
        $this->assertSame('single.txt', $capturedPayload['attachments'][0]['filename']);
    }

    public function testSkippedInvalidAndOversizedAttachments(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        $oversizedFile = $this->testTmpDir . '/large.bin';
        $fp = fopen($oversizedFile, 'w');
        $this->assertIsResource($fp);
        fseek($fp, 2 * 1024 * 1024);
        fwrite($fp, "\x00");
        fclose($fp);

        $validFile = $this->testTmpDir . '/valid.txt';
        file_put_contents($validFile, 'Small file');

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(
            socketPath: $socketFile,
            maxAttachmentSizeMb: 1,
            transport: $transport
        );

        $attachments = [
            '',
            '   ',
            $this->testTmpDir . '/non_existent_file.pdf',
            $oversizedFile,
            $validFile,
        ];

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Filtering Test',
            message: 'Body',
            attachments: $attachments
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertCount(1, $capturedPayload['attachments']);
        $this->assertSame('valid.txt', $capturedPayload['attachments'][0]['filename']);
    }

    public function testZeroMaxAttachmentSizeDisablesLimit(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        $oversizedFile = $this->testTmpDir . '/unlimited.bin';
        file_put_contents($oversizedFile, str_repeat('A', 50000));

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(
            socketPath: $socketFile,
            maxAttachmentSizeMb: 0,
            transport: $transport
        );

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'No Size Limit',
            message: 'Body',
            attachments: [$oversizedFile]
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertCount(1, $capturedPayload['attachments']);
        $this->assertSame('unlimited.bin', $capturedPayload['attachments'][0]['filename']);
    }

    public function testMaxAttachmentSizeEnvResolution(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        putenv('NOTIFY_MAX_ATTACHMENT_SIZE_MB=1');

        $oversizedFile = $this->testTmpDir . '/env_oversized.bin';
        $fp = fopen($oversizedFile, 'w');
        $this->assertIsResource($fp);
        fseek($fp, 2 * 1024 * 1024);
        fwrite($fp, "\x00");
        fclose($fp);

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Env Limit Test',
            message: 'Body',
            attachments: [$oversizedFile]
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertSame([], $capturedPayload['attachments']);
    }

    public function testInvalidMaxAttachmentSizeEnvFallsBackToDefault(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        putenv('NOTIFY_MAX_ATTACHMENT_SIZE_MB=invalid_value');

        $file = $this->testTmpDir . '/normal.txt';
        file_put_contents($file, 'Content within default 10MB limit');

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(socketPath: $socketFile, transport: $transport);

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Invalid Env Test',
            message: 'Body',
            attachments: [$file]
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        $this->assertCount(1, $capturedPayload['attachments']);
    }

    public function testResolveSocketPathFromEnvironmentAndDefault(): void
    {
        $customSocket = $this->testTmpDir . '/custom.sock';
        touch($customSocket);
        putenv('NOTIFY_SOCKET_PATH=' . $customSocket);

        $capturedSocket = null;
        $transport = function (string $socket) use (&$capturedSocket): bool {
            $capturedSocket = $socket;
            return true;
        };

        $mailer = new WpNotifyMailer(transport: $transport);
        $this->assertTrue($mailer->send('admin@example.com', 'Subj', 'Msg'));
        $this->assertSame($customSocket, $capturedSocket);

        putenv('NOTIFY_SOCKET_PATH');
        $defaultMailer = new WpNotifyMailer();
        $this->assertFalse($defaultMailer->send('admin@example.com', 'Subj', 'Msg'));
    }

    public function testDefaultCurlExecutionReturnsFalseWhenSocketFails(): void
    {
        $dummySocket = $this->testTmpDir . '/dummy_not_a_server.sock';
        touch($dummySocket);

        $mailer = new WpNotifyMailer(socketPath: $dummySocket);

        $this->assertFalse($mailer->send('admin@example.com', 'Subject', 'Message'));
    }

    public function testGlobalWpMailFunctionDelegatesToMailer(): void
    {
        $this->assertFalse(wp_mail('', 'Subject', 'Message'));

        $nonExistent = $this->testTmpDir . '/absent.sock';
        putenv('NOTIFY_SOCKET_PATH=' . $nonExistent);

        $this->assertFalse(wp_mail('admin@example.com', 'Subject', 'Message'));
    }

    public function testMimeTypeFallbackWhenUndetected(): void
    {
        $socketFile = $this->testTmpDir . '/notify.sock';
        touch($socketFile);

        $file = $this->testTmpDir . '/fallback.unknown';
        file_put_contents($file, 'dummy content');

        /** @var array<string, mixed>|null $capturedPayload */
        $capturedPayload = null;

        $transport = function (string $socket, string $payload) use (&$capturedPayload): bool {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $capturedPayload = $decoded;
            return true;
        };

        $mailer = new WpNotifyMailer(
            socketPath: $socketFile,
            transport: $transport,
            mimeDetector: fn(string $path): false => false
        );

        $result = $mailer->send(
            to: 'admin@example.com',
            subject: 'Fallback MIME',
            message: 'Body',
            attachments: [$file]
        );

        $this->assertTrue($result);
        $this->assertIsArray($capturedPayload);
        /** @var list<array<string, string>> $attachments */
        $attachments = $capturedPayload['attachments'];
        $this->assertCount(1, $attachments);
        $this->assertSame('application/octet-stream', $attachments[0]['mime_type']);
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

            $fullPath = $path . '/' . $item;
            if (is_dir($fullPath)) {
                $this->removeDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
