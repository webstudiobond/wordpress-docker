package syncer

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/webstudiobond/wp-guard/internal/manifest"
)

// DefaultSbsLoaderTemplate defines the canonical source code template for sbs-loader.php.
const DefaultSbsLoaderTemplate = `<?php

/**
 * Plugin Name: SBS Stack Platform
 * Description: Dynamically loads configured immutable SBS platform MU-plugins from the read-only container layer.
 * Version: 1.0.0
 * Author: underhax
 */

declare(strict_types=1);

/**
 * Dynamically resolves and loads platform MU-plugins from the immutable system directory.
 */
final class SbsPlatformLoader
{
    private const string DEFAULT_PLATFORM_DIR = '/usr/local/share/wordpress/mu-plugins';

    /**
     * @var list<string>
     */
    private const array DEFAULT_SLUGS = ['notify'];

    /**
     * @param (callable(string): (string|false))|null $envGetter
     * @param (callable(string): bool)|null $dirChecker
     * @param (callable(string): bool)|null $fileChecker
     * @param (callable(string): void)|null $fileIncluder
     */
    public function __construct(
        private readonly mixed $envGetter = null,
        private readonly mixed $dirChecker = null,
        private readonly mixed $fileChecker = null,
        private readonly mixed $fileIncluder = null
    ) {
    }

    /**
     * @return list<string>
     */
    public function resolveEnabledSlugs(): array
    {
        $rawEnv = $this->getEnv('WP_ENABLED_MU_PLUGINS');
        if ($rawEnv !== false && trim($rawEnv) === 'none') {
            return [];
        }

        if ($rawEnv === false || trim($rawEnv) === '') {
            return self::DEFAULT_SLUGS;
        }

        $tokens = explode(',', $rawEnv);
        $result = [];
        foreach ($tokens as $token) {
            $trimmed = strtolower(trim($token));
            if (
                $trimmed !== ''
                && $trimmed !== 'all'
                && $trimmed !== 'none'
                && preg_match('/^[a-z-]+$/', $trimmed) === 1
            ) {
                $result[] = $trimmed;
            }
        }

        return array_values(array_unique($result));
    }

    public function resolvePlatformDir(): string
    {
        $platformDir = $this->getEnv('SBS_PLATFORM_MU_DIR');
        if ($platformDir === false || trim($platformDir) === '') {
            return self::DEFAULT_PLATFORM_DIR;
        }

        return trim($platformDir);
    }

    public function load(): void
    {
        $enabledSlugs = $this->resolveEnabledSlugs();
        if ($enabledSlugs === []) {
            return;
        }

        $platformDir = $this->resolvePlatformDir();
        if (!$this->isDir($platformDir)) {
            return;
        }

        foreach ($enabledSlugs as $slug) {
            $filePath = $platformDir . '/wp-' . $slug . '.php';
            if ($this->isFile($filePath)) {
                $this->loadFile($filePath);
            }
        }
    }

    private function getEnv(string $name): string|false
    {
        if (is_callable($this->envGetter)) {
            return ($this->envGetter)($name);
        }

        return getenv($name, true);
    }

    private function isDir(string $path): bool
    {
        if (is_callable($this->dirChecker)) {
            return ($this->dirChecker)($path);
        }

        return is_dir($path);
    }

    private function isFile(string $path): bool
    {
        if (is_callable($this->fileChecker)) {
            return ($this->fileChecker)($path);
        }

        return is_file($path);
    }

    private function loadFile(string $path): void
    {
        if (is_callable($this->fileIncluder)) {
            ($this->fileIncluder)($path);
            return;
        }

        require_once $path;
    }
}

(new SbsPlatformLoader())->load();
`

func resolveTemplateLoaderHash() string {
	templateHash := sha256.Sum256([]byte(DefaultSbsLoaderTemplate))
	return hex.EncodeToString(templateHash[:])
}

// VerifySbsLoader verifies that sbs-loader.php is present, regular, and matches the expected integrity hash.
func VerifySbsLoader(targetDir, contentDirName string) error {
	cleanedTarget := filepath.Clean(targetDir)
	relPath := filepath.ToSlash(filepath.Join(contentDirName, "mu-plugins", "sbs-loader.php"))
	loaderPath := filepath.Join(cleanedTarget, relPath)

	stat, err := os.Lstat(loaderPath)
	if err != nil {
		if os.IsNotExist(err) {
			return fmt.Errorf("sbs-loader.php is missing (please install canonical file with 0:%d 0640 permissions)", os.Getgid())
		}
		return fmt.Errorf("failed to lstat sbs-loader.php: %w", err)
	}

	if !stat.Mode().IsRegular() {
		return errors.New("sbs-loader.php is not a regular file")
	}

	expectedHash := resolveTemplateLoaderHash()
	actualHash, hashErr := manifest.ComputeFileHash(loaderPath)
	if hashErr != nil {
		return fmt.Errorf("failed computing hash for sbs-loader.php: %w", hashErr)
	}

	if actualHash != expectedHash {
		return fmt.Errorf("sbs-loader.php integrity mismatch (expected %s, got %s)", expectedHash, actualHash)
	}

	return nil
}
