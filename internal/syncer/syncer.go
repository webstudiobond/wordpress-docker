// Package syncer manages WordPress core file synchronization, atomic directory updates, and integrity repairs.
package syncer

import (
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/webstudiobond/wp-guard/internal/manifest"
)

const (
	adminDir    = "wp-admin"
	includesDir = "wp-includes"
)

// Syncer internal patterns and lifecycle error definitions.
var (
	staleTempDirRegex = regexp.MustCompile(`^\.(wp-admin|wp-includes)\.(tmp|bak)_.*$`)
	ErrSourceNotFound = errors.New("donor source directory does not exist")
	ErrVersionMissing = errors.New("unable to determine donor wordpress version")
)

// Config configures runtime parameters for the WordPress core synchronizer.
type Config struct {
	Output         io.Writer
	SourceDir      string
	TargetDir      string
	ManifestPath   string
	ContentDirName string
}

// SyncResult summarizes the outcome of a synchronization or repair cycle.
type SyncResult struct {
	Action        string
	SourceVersion string
	TargetVersion string
	RepairedFiles []string
	FilesChecked  int
}

func logMsg(w io.Writer, format string, a ...any) {
	if w != nil {
		if _, err := fmt.Fprintf(w, format, a...); err != nil {
			return
		}
	}
}

// ResolveContentDir returns a sanitized WordPress content directory name derived from environment variables.
func ResolveContentDir(envVal, fallbackVal string) string {
	candidate := strings.TrimSpace(envVal)
	if candidate == "" {
		candidate = strings.TrimSpace(fallbackVal)
	}

	if candidate != "" {
		sanitized := filepath.Base(filepath.Clean(strings.Trim(candidate, "/\\ \t\n\r\000\x0B")))
		if sanitized != "" && sanitized != "." && sanitized != ".." && sanitized != adminDir && sanitized != includesDir {
			return sanitized
		}
	}

	return "wp-content"
}

func compareParts(p1, p2 string) int {
	n1, err1 := strconv.Atoi(p1)
	n2, err2 := strconv.Atoi(p2)

	if err1 == nil && err2 == nil {
		if n1 < n2 {
			return -1
		}
		if n1 > n2 {
			return 1
		}
		return 0
	}

	if p1 < p2 {
		return -1
	}
	if p1 > p2 {
		return 1
	}
	return 0
}

// CompareVersions compares two version strings numerically and returns -1, 0, or 1.
func CompareVersions(v1, v2 string) int {
	parts1 := strings.Split(v1, ".")
	parts2 := strings.Split(v2, ".")
	maxLen := max(len(parts1), len(parts2))

	for i := range maxLen {
		var p1, p2 string
		if i < len(parts1) {
			p1 = parts1[i]
		}
		if i < len(parts2) {
			p2 = parts2[i]
		}

		if diff := compareParts(p1, p2); diff != 0 {
			return diff
		}
	}

	return 0
}

// CopyFile copies a single file preserving permissions and ensuring data durability.
func CopyFile(src, dst string, perm os.FileMode) (retErr error) {
	cleanedSrc := filepath.Clean(src)
	cleanedDst := filepath.Clean(dst)

	dstInfo, lstatErr := os.Lstat(cleanedDst)
	if lstatErr == nil {
		if dstInfo.Mode()&os.ModeSymlink != 0 || !dstInfo.Mode().IsRegular() {
			if rmErr := removeFn(cleanedDst); rmErr != nil {
				return fmt.Errorf("failed to remove existing symlink/non-regular file %s: %w", cleanedDst, rmErr)
			}
		}
	}

	srcFile, err := openSourceFn(cleanedSrc)
	if err != nil {
		return fmt.Errorf("failed to open source file %s: %w", cleanedSrc, err)
	}
	defer func() {
		if closeErr := srcFile.Close(); closeErr != nil && retErr == nil {
			retErr = fmt.Errorf("failed closing source file %s: %w", cleanedSrc, closeErr)
		}
	}()

	if mkErr := os.MkdirAll(filepath.Dir(cleanedDst), 0o750); mkErr != nil {
		return fmt.Errorf("failed to create directory for %s: %w", cleanedDst, mkErr)
	}

	out, err := openFileFn(cleanedDst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC|syscall.O_NOFOLLOW, perm)
	if err != nil {
		return fmt.Errorf("failed to create target file %s: %w", cleanedDst, err)
	}

	_, writeErr := io.Copy(out, srcFile)
	closeErr := out.Close()

	if writeErr != nil {
		return fmt.Errorf("failed to write target file %s: %w", cleanedDst, writeErr)
	}
	if closeErr != nil {
		return fmt.Errorf("failed to close target file %s: %w", cleanedDst, closeErr)
	}

	if chmodErr := chmodFn(cleanedDst, perm); chmodErr != nil {
		return fmt.Errorf("failed to enforce permissions on target file %s: %w", cleanedDst, chmodErr)
	}

	return nil
}

// CopyDirRecursive recursively copies all files and directories from src to dst, skipping symlinks.
func CopyDirRecursive(ctx context.Context, src, dst string) error {
	if err := ctx.Err(); err != nil {
		return fmt.Errorf("copy directory context cancelled: %w", err)
	}

	cleanedSrc := filepath.Clean(src)
	cleanedDst := filepath.Clean(dst)

	srcInfo, err := os.Lstat(cleanedSrc)
	if err != nil {
		return fmt.Errorf("failed to stat source %s: %w", cleanedSrc, err)
	}

	if srcInfo.Mode()&os.ModeSymlink != 0 {
		return nil
	}

	if !srcInfo.IsDir() {
		return CopyFile(cleanedSrc, cleanedDst, 0o640)
	}

	if mkErr := os.MkdirAll(cleanedDst, 0o750); mkErr != nil {
		return fmt.Errorf("failed to create target directory %s: %w", cleanedDst, mkErr)
	}

	entries, err := os.ReadDir(cleanedSrc)
	if err != nil {
		return fmt.Errorf("failed reading directory %s: %w", cleanedSrc, err)
	}

	for _, entry := range entries {
		srcChild := filepath.Join(cleanedSrc, entry.Name())
		dstChild := filepath.Join(cleanedDst, entry.Name())
		if err := CopyDirRecursive(ctx, srcChild, dstChild); err != nil {
			return err
		}
	}

	return nil
}

func activateSwappedDir(tmpDir, cleanedTarget, bakDir string) error {
	if _, err := os.Lstat(cleanedTarget); err == nil {
		if err := renameFn(cleanedTarget, bakDir); err != nil {
			if rmErr := removeAllFn(tmpDir); rmErr != nil {
				return fmt.Errorf("failed to backup existing directory %s: %w (cleanup failed: %w)", cleanedTarget, err, rmErr)
			}
			return fmt.Errorf("failed to backup existing directory %s: %w", cleanedTarget, err)
		}
	}

	if err := renameFn(tmpDir, cleanedTarget); err != nil {
		if _, bakErr := os.Lstat(bakDir); bakErr == nil {
			if restoreErr := renameFn(bakDir, cleanedTarget); restoreErr != nil {
				return fmt.Errorf("failed to restore backup directory %s: %w (activation error: %w)", cleanedTarget, restoreErr, err)
			}
		}
		if rmErr := removeAllFn(tmpDir); rmErr != nil {
			return fmt.Errorf("failed to activate new directory %s: %w (cleanup failed: %w)", cleanedTarget, err, rmErr)
		}
		return fmt.Errorf("failed to activate new directory %s: %w", cleanedTarget, err)
	}

	if _, err := os.Lstat(bakDir); err == nil {
		if rmErr := removeAllFn(bakDir); rmErr != nil {
			return fmt.Errorf("failed to remove backup directory %s: %w", bakDir, rmErr)
		}
	}

	return nil
}

// AtomicSyncDir swaps targetDir with the contents of srcDir using intermediate temporary directories.
func AtomicSyncDir(ctx context.Context, srcDir, targetDir string) error {
	if err := ctx.Err(); err != nil {
		return fmt.Errorf("atomic sync context cancelled: %w", err)
	}

	cleanedSrc := filepath.Clean(srcDir)
	cleanedTarget := filepath.Clean(targetDir)

	stat, err := os.Lstat(cleanedSrc)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("failed to stat source directory %s: %w", cleanedSrc, err)
	}
	if stat.Mode()&os.ModeSymlink != 0 || !stat.IsDir() {
		return nil
	}

	parentDir := filepath.Dir(cleanedTarget)
	baseName := filepath.Base(cleanedTarget)

	tmpDir, err := mkdirTempFn(parentDir, fmt.Sprintf(".%s.tmp_*", baseName))
	if err != nil {
		return fmt.Errorf("failed to create temporary sync directory: %w", err)
	}
	bakDir := filepath.Join(parentDir, fmt.Sprintf(".%s.bak_%d", baseName, time.Now().UnixNano()))

	if err := CopyDirRecursive(ctx, cleanedSrc, tmpDir); err != nil {
		if rmErr := removeAllFn(tmpDir); rmErr != nil {
			return fmt.Errorf("failed to populate temporary directory %s: %w (cleanup failed: %w)", tmpDir, err, rmErr)
		}
		return fmt.Errorf("failed to populate temporary directory %s: %w", tmpDir, err)
	}

	return activateSwappedDir(tmpDir, cleanedTarget, bakDir)
}

// CleanStaleTempDirs removes any leftover temporary and backup directories from interrupted sync operations.
func CleanStaleTempDirs(targetDir string) error {
	cleaned := filepath.Clean(targetDir)
	entries, err := os.ReadDir(cleaned)
	if err != nil {
		return fmt.Errorf("failed to read target directory %s: %w", cleaned, err)
	}

	for _, entry := range entries {
		if staleTempDirRegex.MatchString(entry.Name()) {
			fullPath := filepath.Join(cleaned, entry.Name())
			if err := removeAllFn(fullPath); err != nil {
				return fmt.Errorf("failed removing stale temporary directory %s: %w", fullPath, err)
			}
		}
	}

	return nil
}

func copyItemIfNotExists(ctx context.Context, src, dst string, isDir bool) error {
	if _, err := os.Lstat(dst); os.IsNotExist(err) {
		if isDir {
			return CopyDirRecursive(ctx, src, dst)
		}
		return CopyFile(src, dst, 0o640)
	}
	return nil
}

func mergeContentDirs(ctx context.Context, srcDir, dstDir string) error {
	entries, err := os.ReadDir(srcDir)
	if err != nil {
		return fmt.Errorf("failed reading source content directory: %w", err)
	}

	for _, entry := range entries {
		if ctx.Err() != nil {
			return fmt.Errorf("content merge cancelled: %w", ctx.Err())
		}
		src := filepath.Join(srcDir, entry.Name())
		dst := filepath.Join(dstDir, entry.Name())
		if err := copyItemIfNotExists(ctx, src, dst, entry.IsDir()); err != nil {
			return err
		}
	}

	if err := removeAllFn(srcDir); err != nil {
		return fmt.Errorf("failed removing source content directory %s: %w", srcDir, err)
	}
	return nil
}

// SyncCustomContentDir renames or merges default wp-content into the user's custom content directory.
func SyncCustomContentDir(ctx context.Context, targetDir, contentDirName string) error {
	defaultDirName := ResolveContentDir("", "")
	if contentDirName == defaultDirName {
		return nil
	}

	cleanedTarget := filepath.Clean(targetDir)
	wpContentDir := filepath.Join(cleanedTarget, defaultDirName)
	customContentDir := filepath.Join(cleanedTarget, contentDirName)

	if stat, err := os.Lstat(wpContentDir); err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("failed to stat default content directory %s: %w", wpContentDir, err)
	} else if stat.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("default content directory %s must not be a symlink", wpContentDir)
	}

	if stat, err := os.Lstat(customContentDir); os.IsNotExist(err) {
		if renameErr := renameFn(wpContentDir, customContentDir); renameErr != nil {
			return fmt.Errorf("failed to rename %s to %s: %w", wpContentDir, customContentDir, renameErr)
		}
		return nil
	} else if err == nil && stat.Mode()&os.ModeSymlink != 0 {
		return fmt.Errorf("custom content directory %s must not be a symlink", customContentDir)
	}

	return mergeContentDirs(ctx, wpContentDir, customContentDir)
}

// SyncRootFiles copies top-level WordPress donor files to targetDir, excluding directories and wp-config files.
func SyncRootFiles(ctx context.Context, sourceDir, targetDir string) error {
	if err := ctx.Err(); err != nil {
		return fmt.Errorf("sync root files context cancelled: %w", err)
	}

	cleanedSrc := filepath.Clean(sourceDir)
	cleanedDst := filepath.Clean(targetDir)
	defaultContentName := ResolveContentDir("", "")

	entries, err := os.ReadDir(cleanedSrc)
	if err != nil {
		return fmt.Errorf("failed to read source directory %s: %w", cleanedSrc, err)
	}

	for _, entry := range entries {
		name := entry.Name()
		if entry.IsDir() || name == defaultContentName || name == adminDir || name == includesDir || strings.HasPrefix(name, "wp-config") || name == "readme.html" || name == "license.txt" {
			continue
		}

		srcPath := filepath.Join(cleanedSrc, name)
		dstPath := filepath.Join(cleanedDst, name)
		if err := CopyFile(srcPath, dstPath, 0o640); err != nil {
			return err
		}
	}

	return nil
}

type fileCheckTask struct {
	relPath      string
	expectedHash string
}

func resolveWorkerCount() int {
	return min(max(runtime.NumCPU()*2, 1), 8)
}

func scanRogueFiles(targetSubDir, prefix string, coreFiles map[string]string) (bool, error) {
	cleaned := filepath.Clean(targetSubDir)
	stat, err := os.Lstat(cleaned)
	if err != nil {
		if os.IsNotExist(err) {
			return false, nil
		}
		return false, fmt.Errorf("failed stating %s: %w", cleaned, err)
	}
	if stat.Mode()&os.ModeSymlink != 0 || !stat.IsDir() {
		return true, nil
	}

	hasRogue := false
	err = filepath.Walk(cleaned, func(path string, info os.FileInfo, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		if info.Mode()&os.ModeSymlink != 0 {
			hasRogue = true
			return filepath.SkipAll
		}
		if info.IsDir() {
			return nil
		}
		rel := strings.TrimPrefix(path, cleaned)
		rel = strings.TrimPrefix(rel, string(filepath.Separator))
		fullRel := prefix + "/" + filepath.ToSlash(rel)
		if _, exists := coreFiles[fullRel]; !exists {
			hasRogue = true
			return filepath.SkipAll
		}
		return nil
	})
	if err != nil {
		return false, fmt.Errorf("failed walking %s: %w", cleaned, err)
	}
	return hasRogue, nil
}

func recordMismatch(relPath string, adminFlag, includesFlag, rootFlag *atomic.Bool) {
	switch {
	case strings.HasPrefix(relPath, adminDir+"/"):
		adminFlag.Store(true)
	case strings.HasPrefix(relPath, includesDir+"/"):
		includesFlag.Store(true)
	default:
		rootFlag.Store(true)
	}
}

func isFileIntegrityCompromised(targetPath, expectedHash string) bool {
	stat, statErr := os.Lstat(targetPath)
	if statErr != nil || stat.Mode()&os.ModeSymlink != 0 || !stat.Mode().IsRegular() {
		return true
	}
	hash, hashErr := manifest.ComputeFileHash(targetPath)
	return hashErr != nil || hash != expectedHash
}

func auditCoreFilesIntegrity(ctx context.Context, targetDir string, m *manifest.Manifest) (adminMismatch, includesMismatch, rootMismatch bool) {
	workers := resolveWorkerCount()
	tasks := make(chan fileCheckTask, workers*4)

	var adminFlag, includesFlag, rootFlag atomic.Bool

	var wg sync.WaitGroup
	for range workers {
		wg.Go(func() {
			for task := range tasks {
				if ctx.Err() != nil {
					continue
				}
				targetPath := filepath.Join(targetDir, filepath.FromSlash(task.relPath))
				if isFileIntegrityCompromised(targetPath, task.expectedHash) {
					recordMismatch(task.relPath, &adminFlag, &includesFlag, &rootFlag)
				}
			}
		})
	}

	go func() {
		defer close(tasks)
		for relPath, expectedHash := range m.CoreFiles {
			select {
			case <-ctx.Done():
				return
			case tasks <- fileCheckTask{relPath: relPath, expectedHash: expectedHash}:
			}
		}
	}()

	wg.Wait()

	return adminFlag.Load(), includesFlag.Load(), rootFlag.Load()
}

// VerifyAndRepairCore checks all files against the manifest using worker goroutines and restores damaged files.
func VerifyAndRepairCore(ctx context.Context, cfg *Config, m *manifest.Manifest) (repaired []string, totalChecked int, err error) {
	if ctxErr := ctx.Err(); ctxErr != nil {
		return nil, 0, fmt.Errorf("verification cancelled: %w", ctxErr)
	}

	wpAdminNeedsSync, err := scanRogueFilesFn(filepath.Join(cfg.TargetDir, adminDir), adminDir, m.CoreFiles)
	if err != nil {
		return nil, 0, err
	}

	wpIncludesNeedsSync, err := scanRogueFilesFn(filepath.Join(cfg.TargetDir, includesDir), includesDir, m.CoreFiles)
	if err != nil {
		return nil, 0, err
	}

	adminMismatch, includesMismatch, rootMismatch := auditCoreFilesIntegrity(ctx, cfg.TargetDir, m)
	if ctxErr := ctx.Err(); ctxErr != nil {
		return nil, 0, fmt.Errorf("verification cancelled: %w", ctxErr)
	}

	isCompromised := wpAdminNeedsSync || wpIncludesNeedsSync || adminMismatch || includesMismatch || rootMismatch
	if !isCompromised {
		return nil, len(m.CoreFiles), nil
	}

	if syncErr := atomicSyncDirFn(ctx, filepath.Join(cfg.SourceDir, adminDir), filepath.Join(cfg.TargetDir, adminDir)); syncErr != nil {
		return nil, 0, syncErr
	}

	if syncErr := atomicSyncDirFn(ctx, filepath.Join(cfg.SourceDir, includesDir), filepath.Join(cfg.TargetDir, includesDir)); syncErr != nil {
		return nil, 0, syncErr
	}

	if rootErr := syncRootFilesFn(ctx, cfg.SourceDir, cfg.TargetDir); rootErr != nil {
		return nil, 0, rootErr
	}

	return []string{adminDir, includesDir, "root"}, len(m.CoreFiles), nil
}

type fileWriter interface {
	Write([]byte) (int, error)
	Close() error
}

type fileReader interface {
	io.Reader
	io.Closer
}

func defaultOpenFile(name string, flag int, perm os.FileMode) (fileWriter, error) {
	f, err := os.OpenFile(filepath.Clean(name), flag, perm)
	if err != nil {
		return nil, fmt.Errorf("%w", err)
	}
	return f, nil
}

func defaultOpenSource(name string) (fileReader, error) {
	f, err := os.Open(filepath.Clean(name))
	if err != nil {
		return nil, fmt.Errorf("%w", err)
	}
	return f, nil
}

func defaultChmod(name string, mode os.FileMode) error {
	if err := os.Chmod(filepath.Clean(name), mode); err != nil {
		return fmt.Errorf("%w", err)
	}
	return nil
}

func defaultRename(oldpath, newpath string) error {
	if err := os.Rename(oldpath, newpath); err != nil {
		return fmt.Errorf("%w", err)
	}
	return nil
}

func defaultRemoveAll(path string) error {
	if err := os.RemoveAll(path); err != nil {
		return fmt.Errorf("%w", err)
	}
	return nil
}

func defaultRemove(path string) error {
	if err := os.Remove(path); err != nil {
		return fmt.Errorf("%w", err)
	}
	return nil
}

func defaultMkdirTemp(dir, pattern string) (string, error) {
	tmp, err := os.MkdirTemp(dir, pattern)
	if err != nil {
		return "", fmt.Errorf("%w", err)
	}
	return tmp, nil
}

func defaultScanRogueFiles(targetSubDir, prefix string, coreFiles map[string]string) (bool, error) {
	return scanRogueFiles(targetSubDir, prefix, coreFiles)
}

func defaultCopyDirRecursive(ctx context.Context, src, dst string) error {
	return CopyDirRecursive(ctx, src, dst)
}

func defaultSyncCustomContentDir(ctx context.Context, targetDir, contentDirName string) error {
	return SyncCustomContentDir(ctx, targetDir, contentDirName)
}

func defaultVerifySbsLoader(_ context.Context, cfg *Config, contentDirName string) error {
	if cfg.ManifestPath == "" {
		return nil
	}

	stat, err := os.Lstat(filepath.Clean(cfg.ManifestPath))
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("failed stating manifest %s: %w", cfg.ManifestPath, err)
	}
	if stat.Mode()&os.ModeSymlink != 0 || stat.IsDir() {
		return nil
	}

	m, loadErr := manifest.Load(cfg.ManifestPath)
	if loadErr != nil {
		return fmt.Errorf("%w", loadErr)
	}
	if m == nil || m.SbsLoaderHash == "" {
		return nil
	}

	return VerifySbsLoader(cfg.TargetDir, contentDirName, m.SbsLoaderHash)
}

func defaultCleanStaleTempDirs(targetDir string) error {
	return CleanStaleTempDirs(targetDir)
}

func defaultAtomicSyncDir(ctx context.Context, srcDir, targetDir string) error {
	return AtomicSyncDir(ctx, srcDir, targetDir)
}

func defaultSyncRootFiles(ctx context.Context, sourceDir, targetDir string) error {
	return SyncRootFiles(ctx, sourceDir, targetDir)
}

func defaultVerifyCoreIntegrity(ctx context.Context, cfg *Config) (repaired []string, checked int, err error) {
	return verifyCoreIntegrity(ctx, cfg)
}

var (
	openFileFn             = defaultOpenFile
	openSourceFn           = defaultOpenSource
	chmodFn                = defaultChmod
	renameFn               = defaultRename
	removeAllFn            = defaultRemoveAll
	removeFn               = defaultRemove
	mkdirTempFn            = defaultMkdirTemp
	scanRogueFilesFn       = defaultScanRogueFiles
	copyDirRecursiveFn     = defaultCopyDirRecursive
	syncCustomContentDirFn = defaultSyncCustomContentDir
	verifySbsLoaderFn      = defaultVerifySbsLoader
	cleanStaleTempDirsFn   = defaultCleanStaleTempDirs
	atomicSyncDirFn        = defaultAtomicSyncDir
	syncRootFilesFn        = defaultSyncRootFiles
	verifyCoreIntegrityFn  = defaultVerifyCoreIntegrity
)

func handleCleanInstall(ctx context.Context, cfg *Config, donorVersion string) (*SyncResult, error) {
	logMsg(cfg.Output, "[wp-guard] Initializing clean WordPress installation (version %s)...\n", donorVersion)
	if err := copyDirRecursiveFn(ctx, cfg.SourceDir, cfg.TargetDir); err != nil {
		return nil, err
	}

	contentDirName := ResolveContentDir(cfg.ContentDirName, "")
	if err := syncCustomContentDirFn(ctx, cfg.TargetDir, contentDirName); err != nil {
		return nil, err
	}
	if err := verifySbsLoaderFn(ctx, cfg, contentDirName); err != nil {
		return nil, err
	}

	logMsg(cfg.Output, "[wp-guard] Clean installation complete (version %s).\n", donorVersion)
	return &SyncResult{
		Action:        "clean_install",
		SourceVersion: donorVersion,
	}, nil
}

func handleVersionMismatch(ctx context.Context, cfg *Config, donorVersion, targetVersion string) (*SyncResult, error) {
	action := "Upgrading"
	actionResult := "upgraded"
	if CompareVersions(donorVersion, targetVersion) < 0 {
		action = "Rolling back"
		actionResult = "rolled_back"
	}
	logMsg(cfg.Output, "[wp-guard] %s WordPress core from %s to %s...\n", action, targetVersion, donorVersion)

	if err := cleanStaleTempDirsFn(cfg.TargetDir); err != nil {
		return nil, err
	}

	if err := atomicSyncDirFn(ctx, filepath.Join(cfg.SourceDir, adminDir), filepath.Join(cfg.TargetDir, adminDir)); err != nil {
		return nil, err
	}
	if err := atomicSyncDirFn(ctx, filepath.Join(cfg.SourceDir, includesDir), filepath.Join(cfg.TargetDir, includesDir)); err != nil {
		return nil, err
	}

	if err := syncRootFilesFn(ctx, cfg.SourceDir, cfg.TargetDir); err != nil {
		return nil, err
	}

	logMsg(cfg.Output, "[wp-guard] WordPress core synchronized successfully to %s.\n", donorVersion)
	return &SyncResult{
		Action:        actionResult,
		SourceVersion: donorVersion,
		TargetVersion: targetVersion,
	}, nil
}

func verifyCoreIntegrity(ctx context.Context, cfg *Config) (repaired []string, checked int, err error) {
	if cfg.ManifestPath == "" {
		return nil, 0, nil
	}

	stat, err := os.Lstat(filepath.Clean(cfg.ManifestPath))
	if err != nil {
		if os.IsNotExist(err) {
			return nil, 0, nil
		}
		return nil, 0, fmt.Errorf("failed stating manifest %s: %w", cfg.ManifestPath, err)
	}
	if stat.Mode()&os.ModeSymlink != 0 || stat.IsDir() {
		return nil, 0, nil
	}

	m, loadErr := manifest.Load(cfg.ManifestPath)
	if loadErr != nil {
		return nil, 0, fmt.Errorf("%w", loadErr)
	}
	if m == nil || len(m.CoreFiles) == 0 {
		return nil, 0, nil
	}

	return VerifyAndRepairCore(ctx, cfg, m)
}

func checkInstallationState(sourceDir, targetDir string) (donorVersion, targetVersion string, isNewInstall bool, err error) {
	donorVersionPath := filepath.Join(sourceDir, includesDir, "version.php")
	donorVersion, err = manifest.ExtractWpVersion(donorVersionPath)
	if err != nil {
		return "", "", false, fmt.Errorf("%w: %w", ErrVersionMissing, err)
	}

	targetVersionPath := filepath.Join(targetDir, includesDir, "version.php")
	if stat, statErr := os.Lstat(targetVersionPath); statErr == nil && stat.Mode()&os.ModeSymlink == 0 {
		extracted, extractErr := manifest.ExtractWpVersion(targetVersionPath)
		if extractErr != nil && !errors.Is(extractErr, manifest.ErrVersionNotFound) {
			return "", "", false, fmt.Errorf("failed reading target version: %w", extractErr)
		}
		targetVersion = extracted
	}

	indexPath := filepath.Join(targetDir, "index.php")
	stat, indexStatErr := os.Lstat(indexPath)
	isNewInstall = (os.IsNotExist(indexStatErr) || errors.Is(indexStatErr, syscall.ENOTDIR) || (stat != nil && stat.Mode()&os.ModeSymlink != 0)) && targetVersion == ""

	return donorVersion, targetVersion, isNewInstall, nil
}

// Run executes the full synchronization lifecycle for WordPress core files and platform components.
func Run(ctx context.Context, cfg *Config) (*SyncResult, error) {
	if err := ctx.Err(); err != nil {
		return nil, fmt.Errorf("sync execution context cancelled: %w", err)
	}

	out := cfg.Output
	if out == nil {
		out = io.Discard
	}
	cfg.Output = out

	sourceStat, err := os.Lstat(filepath.Clean(cfg.SourceDir))
	if err != nil || sourceStat.Mode()&os.ModeSymlink != 0 || !sourceStat.IsDir() {
		logMsg(out, "[wp-guard] Source directory %s not found. Skipping synchronization.\n", cfg.SourceDir)
		return nil, ErrSourceNotFound
	}

	donorVersion, targetVersion, isNewInstall, err := checkInstallationState(cfg.SourceDir, cfg.TargetDir)
	if err != nil {
		return nil, err
	}

	if isNewInstall {
		return handleCleanInstall(ctx, cfg, donorVersion)
	}

	contentDirName := ResolveContentDir(cfg.ContentDirName, "")
	if syncErr := syncCustomContentDirFn(ctx, cfg.TargetDir, contentDirName); syncErr != nil {
		return nil, syncErr
	}
	if loaderErr := verifySbsLoaderFn(ctx, cfg, contentDirName); loaderErr != nil {
		return nil, loaderErr
	}

	if targetVersion != "" && donorVersion != targetVersion {
		return handleVersionMismatch(ctx, cfg, donorVersion, targetVersion)
	}

	repaired, checked, verifyErr := verifyCoreIntegrityFn(ctx, cfg)
	if verifyErr != nil {
		return nil, verifyErr
	}
	if len(repaired) > 0 {
		logMsg(cfg.Output, "[wp-guard] Integrity verification repaired all core files from donor.\n")
		return &SyncResult{
			Action:        "repaired",
			SourceVersion: donorVersion,
			TargetVersion: targetVersion,
			RepairedFiles: repaired,
			FilesChecked:  checked,
		}, nil
	}

	logMsg(out, "[wp-guard] WordPress core is up-to-date (version %s).\n", donorVersion)
	return &SyncResult{
		Action:        "up_to_date",
		SourceVersion: donorVersion,
		TargetVersion: targetVersion,
	}, nil
}
