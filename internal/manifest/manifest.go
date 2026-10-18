// Package manifest provides generation, persistence, and integrity verification for WordPress core files.
package manifest

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// Manifest validation errors and core version regex pattern.
var (
	wpVersionRegex         = regexp.MustCompile(`\$wp_version\s*=\s*['"]([^'"]+)['"]`)
	ErrVersionNotFound     = errors.New("wordpress version not found in version.php")
	ErrEmptyDonorDir       = errors.New("donor directory contains no files")
	ErrInvalidManifestPath = errors.New("manifest contains invalid relative path")
	ErrNotRegularFile      = errors.New("path is not a regular file")
)

// Manifest contains precomputed SHA-256 hashes of clean WordPress core files
// and platform must-use plugins to enable fast runtime integrity validation.
type Manifest struct {
	CoreFiles        map[string]string `json:"core_files"`
	MuPlugins        map[string]string `json:"mu_plugins"`
	WordPressVersion string            `json:"wordpress_version"`
	SbsLoaderHash    string            `json:"sbs_loader_hash,omitempty"`
}

// ComputeFileHash calculates the hex-encoded SHA-256 hash of a regular file on disk using streaming I/O.
func ComputeFileHash(path string) (resHash string, retErr error) {
	cleaned := filepath.Clean(path)
	stat, err := os.Lstat(cleaned)
	if err != nil {
		return "", fmt.Errorf("failed to lstat file %s: %w", cleaned, err)
	}
	if !stat.Mode().IsRegular() {
		return "", fmt.Errorf("file %s is not regular: %w", cleaned, ErrNotRegularFile)
	}

	file, err := openFileFn(cleaned)
	if err != nil {
		return "", fmt.Errorf("failed to open file %s: %w", cleaned, err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && retErr == nil {
			retErr = fmt.Errorf("failed closing file %s: %w", cleaned, closeErr)
		}
	}()

	hasher := sha256.New()
	if _, err := io.Copy(hasher, file); err != nil {
		return "", fmt.Errorf("failed to hash file %s: %w", cleaned, err)
	}

	return hex.EncodeToString(hasher.Sum(nil)), nil
}

// ExtractWpVersion parses the WordPress version string from a version.php file.
func ExtractWpVersion(versionFilePath string) (string, error) {
	cleaned := filepath.Clean(versionFilePath)
	content, err := os.ReadFile(cleaned)
	if err != nil {
		return "", fmt.Errorf("failed to read version file %s: %w", cleaned, err)
	}

	matches := wpVersionRegex.FindSubmatch(content)
	if len(matches) < 2 {
		return "", ErrVersionNotFound
	}

	return string(matches[1]), nil
}

func isIgnoredDonorFile(relPath string) bool {
	if strings.Contains(relPath, "/") {
		return false
	}
	switch relPath {
	case "readme.html", "license.txt":
		return true
	default:
		return strings.HasPrefix(relPath, "wp-config")
	}
}

func scanCoreFiles(ctx context.Context, donorDir string) (map[string]string, error) {
	coreFiles := make(map[string]string, 2048)
	cleanedDonor := filepath.Clean(donorDir)
	err := filepath.Walk(cleanedDonor, func(path string, info os.FileInfo, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		if ctx.Err() != nil {
			return fmt.Errorf("walk cancelled: %w", ctx.Err())
		}
		if info.IsDir() {
			if path != cleanedDonor && info.Name() == "wp-content" {
				return filepath.SkipDir
			}
			return nil
		}
		if !info.Mode().IsRegular() {
			return nil
		}

		relPath := strings.TrimPrefix(path, cleanedDonor)
		relPath = strings.TrimPrefix(relPath, string(filepath.Separator))
		relPath = filepath.ToSlash(relPath)

		if isIgnoredDonorFile(relPath) {
			return nil
		}

		hash, hashErr := ComputeFileHash(path)
		if hashErr != nil {
			return hashErr
		}
		coreFiles[relPath] = hash
		return nil
	})
	if err != nil {
		return nil, fmt.Errorf("failed walking donor directory %s: %w", donorDir, err)
	}

	return coreFiles, nil
}

func scanMuPlugins(muDir string) (map[string]string, error) {
	muPlugins := make(map[string]string, 32)
	if muDir == "" {
		return muPlugins, nil
	}

	cleaned := filepath.Clean(muDir)
	stat, statErr := os.Lstat(cleaned)
	if statErr != nil {
		if os.IsNotExist(statErr) {
			return muPlugins, nil
		}
		return nil, fmt.Errorf("failed stating mu-plugins directory %s: %w", cleaned, statErr)
	}
	if stat.Mode()&os.ModeSymlink != 0 || !stat.IsDir() {
		return muPlugins, nil
	}

	entries, readErr := os.ReadDir(cleaned)
	if readErr != nil {
		return nil, fmt.Errorf("failed reading mu-plugins directory %s: %w", cleaned, readErr)
	}

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".php") {
			continue
		}
		fullPath := filepath.Join(cleaned, entry.Name())
		hash, hashErr := ComputeFileHash(fullPath)
		if hashErr != nil {
			return nil, hashErr
		}
		muPlugins[entry.Name()] = hash
	}

	return muPlugins, nil
}

func defaultExtractVersion(versionFilePath string) (string, error) {
	return ExtractWpVersion(versionFilePath)
}

func defaultScanMuPlugins(muDir string) (map[string]string, error) {
	return scanMuPlugins(muDir)
}

func defaultScanCoreFiles(ctx context.Context, donorDir string) (map[string]string, error) {
	return scanCoreFiles(ctx, donorDir)
}

func defaultMarshalIndent(v any, prefix, indent string) ([]byte, error) {
	return json.MarshalIndent(v, prefix, indent)
}

func defaultRemove(path string) error {
	if err := os.Remove(path); err != nil {
		return fmt.Errorf("%w", err)
	}
	return nil
}

type fileReader interface {
	io.Reader
	io.Closer
}

func defaultOpenFile(name string) (fileReader, error) {
	f, err := os.Open(filepath.Clean(name))
	if err != nil {
		return nil, fmt.Errorf("%w", err)
	}
	return f, nil
}

var (
	openFileFn         = defaultOpenFile
	extractWpVersionFn = defaultExtractVersion
	scanMuPluginsFn    = defaultScanMuPlugins
	scanCoreFilesFn    = defaultScanCoreFiles
	marshalIndentFn    = defaultMarshalIndent
	removeFn           = defaultRemove
)

// Generate constructs a new Manifest by hashing all files in the donor and mu-plugin directories,
// and optionally computing the integrity hash for the canonical sbs-loader.php file.
func Generate(ctx context.Context, donorDir, muDir, sbsLoaderPath string) (*Manifest, error) {
	if err := ctx.Err(); err != nil {
		return nil, fmt.Errorf("manifest generation context cancelled: %w", err)
	}

	versionPath := filepath.Join(donorDir, "wp-includes", "version.php")
	version, err := extractWpVersionFn(versionPath)
	if err != nil {
		return nil, err
	}

	coreFiles, err := scanCoreFilesFn(ctx, donorDir)
	if err != nil {
		return nil, err
	}

	if len(coreFiles) == 0 {
		return nil, ErrEmptyDonorDir
	}

	muPlugins, err := scanMuPluginsFn(muDir)
	if err != nil {
		return nil, err
	}

	var sbsLoaderHash string
	if sbsLoaderPath != "" {
		hash, hashErr := ComputeFileHash(sbsLoaderPath)
		if hashErr != nil {
			return nil, fmt.Errorf("failed computing sbs-loader hash: %w", hashErr)
		}
		sbsLoaderHash = hash
	}

	return &Manifest{
		CoreFiles:        coreFiles,
		MuPlugins:        muPlugins,
		WordPressVersion: version,
		SbsLoaderHash:    sbsLoaderHash,
	}, nil
}

// Save marshals the Manifest to indented JSON format and writes it atomically to the target path.
func Save(targetPath string, m *Manifest) error {
	data, err := marshalIndentFn(m, "", "  ")
	if err != nil {
		return fmt.Errorf("failed to marshal manifest: %w", err)
	}
	data = append(data, '\n')

	cleaned := filepath.Clean(targetPath)
	dir := filepath.Dir(cleaned)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return fmt.Errorf("failed to create manifest parent directory %s: %w", dir, err)
	}

	tmpPath := cleaned + ".tmp"
	if err := os.WriteFile(tmpPath, data, 0o600); err != nil {
		return fmt.Errorf("failed to write temporary manifest %s: %w", tmpPath, err)
	}

	if err := os.Rename(tmpPath, cleaned); err != nil {
		if rmErr := removeFn(tmpPath); rmErr != nil {
			return fmt.Errorf("failed to rename manifest into place %s: %w (cleanup failed: %w)", cleaned, err, rmErr)
		}
		return fmt.Errorf("failed to rename manifest into place %s: %w", cleaned, err)
	}

	return nil
}

// Load reads and unmarshals an existing Manifest JSON file from disk, validating all internal paths.
func Load(sourcePath string) (*Manifest, error) {
	cleaned := filepath.Clean(sourcePath)
	data, err := os.ReadFile(cleaned)
	if err != nil {
		return nil, fmt.Errorf("failed to read manifest file %s: %w", cleaned, err)
	}

	var m Manifest
	if err := json.Unmarshal(data, &m); err != nil {
		return nil, fmt.Errorf("failed to parse manifest JSON %s: %w", cleaned, err)
	}

	if m.CoreFiles == nil {
		m.CoreFiles = make(map[string]string)
	}
	if m.MuPlugins == nil {
		m.MuPlugins = make(map[string]string)
	}

	for relPath := range m.CoreFiles {
		if !filepath.IsLocal(filepath.FromSlash(relPath)) {
			return nil, fmt.Errorf("%w: %s in core_files", ErrInvalidManifestPath, relPath)
		}
	}
	for relPath := range m.MuPlugins {
		if !filepath.IsLocal(filepath.FromSlash(relPath)) {
			return nil, fmt.Errorf("%w: %s in mu_plugins", ErrInvalidManifestPath, relPath)
		}
	}

	return &m, nil
}
