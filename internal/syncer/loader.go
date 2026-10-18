package syncer

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/webstudiobond/wp-guard/internal/manifest"
)

// VerifySbsLoader verifies the integrity of sbs-loader.php against the expected hash if the loader is present.
func VerifySbsLoader(targetDir, contentDirName, expectedHash string) error {
	cleanedTarget := filepath.Clean(targetDir)
	relPath := filepath.ToSlash(filepath.Join(contentDirName, "mu-plugins", "sbs-loader.php"))
	loaderPath := filepath.Join(cleanedTarget, relPath)

	stat, err := os.Lstat(loaderPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("failed to lstat sbs-loader.php: %w", err)
	}

	if !stat.Mode().IsRegular() {
		return errors.New("sbs-loader.php is not a regular file")
	}

	if expectedHash == "" {
		return errors.New("expected sbs-loader hash cannot be empty")
	}

	actualHash, hashErr := manifest.ComputeFileHash(loaderPath)
	if hashErr != nil {
		return fmt.Errorf("failed computing hash for sbs-loader.php: %w", hashErr)
	}

	if actualHash != expectedHash {
		return fmt.Errorf("sbs-loader.php integrity mismatch (expected %s, got %s)", expectedHash, actualHash)
	}

	return nil
}
