package syncer

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"slices"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/webstudiobond/wp-guard/internal/manifest"
)

func createTestDonor(t *testing.T, version string) string {
	t.Helper()
	dir := t.TempDir()

	incDir := filepath.Join(dir, "wp-includes")
	if err := os.MkdirAll(incDir, 0o750); err != nil {
		t.Fatalf("failed to create wp-includes: %v", err)
	}
	versionContent := "<?php\n$wp_version = '" + version + "';\n"
	if err := os.WriteFile(filepath.Join(incDir, "version.php"), []byte(versionContent), 0o600); err != nil {
		t.Fatalf("failed to write version.php: %v", err)
	}

	adminDir := filepath.Join(dir, "wp-admin")
	if err := os.MkdirAll(adminDir, 0o750); err != nil {
		t.Fatalf("failed to create wp-admin: %v", err)
	}
	if err := os.WriteFile(filepath.Join(adminDir, "index.php"), []byte("<?php // admin"), 0o600); err != nil {
		t.Fatalf("failed to write admin index: %v", err)
	}

	contentDir := filepath.Join(dir, "wp-content")
	if err := os.MkdirAll(contentDir, 0o750); err != nil {
		t.Fatalf("failed to create wp-content: %v", err)
	}
	if err := os.WriteFile(filepath.Join(contentDir, "index.php"), []byte("<?php // content"), 0o600); err != nil {
		t.Fatalf("failed to write content index: %v", err)
	}

	if err := os.WriteFile(filepath.Join(dir, "index.php"), []byte("<?php // root index"), 0o600); err != nil {
		t.Fatalf("failed to write root index: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "wp-login.php"), []byte("<?php // login"), 0o600); err != nil {
		t.Fatalf("failed to write wp-login: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "wp-config-sample.php"), []byte("<?php // sample"), 0o600); err != nil {
		t.Fatalf("failed to write wp-config-sample: %v", err)
	}

	return dir
}

func createTestSbsLoader(t *testing.T, targetDir, contentDirName string) string {
	t.Helper()
	muDir := filepath.Join(targetDir, contentDirName, "mu-plugins")
	if err := os.MkdirAll(muDir, 0o750); err != nil {
		t.Fatalf("failed creating muDir: %v", err)
	}
	loaderPath := filepath.Join(muDir, "sbs-loader.php")
	data := []byte("<?php // sbs-loader canonical test fixture\n")
	if err := os.WriteFile(loaderPath, data, 0o600); err != nil {
		t.Fatalf("failed creating test loader: %v", err)
	}
	hash, err := manifest.ComputeFileHash(loaderPath)
	if err != nil {
		t.Fatalf("failed computing test loader hash: %v", err)
	}
	return hash
}

func TestResolveContentDir(t *testing.T) {
	tests := []struct {
		name        string
		envVal      string
		fallbackVal string
		want        string
	}{
		{
			name:        "default fallback when empty",
			envVal:      "",
			fallbackVal: "",
			want:        "wp-content",
		},
		{
			name:        "use envVal if provided",
			envVal:      "custom-content",
			fallbackVal: "ignored",
			want:        "custom-content",
		},
		{
			name:        "trim slashes and spaces",
			envVal:      "  /my-app/  ",
			fallbackVal: "",
			want:        "my-app",
		},
		{
			name:        "fallback when envVal is empty",
			envVal:      "",
			fallbackVal: "site-content",
			want:        "site-content",
		},
		{
			name:        "sanitize dot directories",
			envVal:      ".",
			fallbackVal: "..",
			want:        "wp-content",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := ResolveContentDir(tc.envVal, tc.fallbackVal)
			if got != tc.want {
				t.Fatalf("expected %s, got %s", tc.want, got)
			}
		})
	}
}

func TestCompareVersions(t *testing.T) {
	tests := []struct {
		name string
		v1   string
		v2   string
		want int
	}{
		{
			name: "equal versions",
			v1:   "6.1.1",
			v2:   "6.1.1",
			want: 0,
		},
		{
			name: "v1 is greater",
			v1:   "6.7.1",
			v2:   "6.7.0",
			want: 1,
		},
		{
			name: "v1 is lower",
			v1:   "6.5.4",
			v2:   "6.6.0",
			want: -1,
		},
		{
			name: "different component count",
			v1:   "6.8.1",
			v2:   "6.8",
			want: 1,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := CompareVersions(tc.v1, tc.v2)
			if got != tc.want {
				t.Fatalf("expected %d, got %d", tc.want, got)
			}
		})
	}
}

func TestCopyFileAndDir(t *testing.T) {
	ctx := context.Background()
	srcDir := t.TempDir()
	dstDir := t.TempDir()

	subDir := filepath.Join(srcDir, "sub")
	if err := os.MkdirAll(subDir, 0o750); err != nil {
		t.Fatalf("failed to create subDir: %v", err)
	}
	fileA := filepath.Join(subDir, "a.txt")
	if err := os.WriteFile(fileA, []byte("content A"), 0o600); err != nil {
		t.Fatalf("failed to write fileA: %v", err)
	}

	symlinkPath := filepath.Join(srcDir, "link_to_a")
	if err := os.Symlink(fileA, symlinkPath); err != nil {
		t.Fatalf("failed creating symlink: %v", err)
	}

	targetSub := filepath.Join(dstDir, "target")
	if err := CopyDirRecursive(ctx, srcDir, targetSub); err != nil {
		t.Fatalf("CopyDirRecursive failed: %v", err)
	}

	copiedFile := filepath.Join(targetSub, "sub", "a.txt")
	data, err := os.ReadFile(filepath.Clean(copiedFile))
	if err != nil || string(data) != "content A" {
		t.Fatalf("expected copied content A, got %s (err: %v)", string(data), err)
	}

	fileStat, err := os.Lstat(copiedFile)
	if err != nil {
		t.Fatalf("failed to stat copied file: %v", err)
	}
	if fileStat.Mode().Perm() != 0o640 {
		t.Fatalf("expected file permission 0640, got %v", fileStat.Mode().Perm())
	}

	dirStat, err := os.Lstat(filepath.Join(targetSub, "sub"))
	if err != nil {
		t.Fatalf("failed to stat copied sub dir: %v", err)
	}
	if dirStat.Mode().Perm() != 0o750 {
		t.Fatalf("expected dir permission 0750, got %v", dirStat.Mode().Perm())
	}

	copiedLink := filepath.Join(targetSub, "link_to_a")
	if _, err := os.Lstat(copiedLink); !os.IsNotExist(err) {
		t.Fatalf("expected symlink to be skipped, but exists")
	}

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := CopyDirRecursive(canceledCtx, srcDir, dstDir); err == nil {
		t.Fatalf("expected cancellation error, got nil")
	}
}

func TestAtomicSyncDir(t *testing.T) {
	ctx := context.Background()
	tmpRoot := t.TempDir()

	srcDir := filepath.Join(tmpRoot, "src_admin")
	if err := os.MkdirAll(srcDir, 0o750); err != nil {
		t.Fatalf("failed creating src_admin: %v", err)
	}
	if err := os.WriteFile(filepath.Join(srcDir, "tool.php"), []byte("new-tool"), 0o600); err != nil {
		t.Fatalf("failed writing tool.php: %v", err)
	}

	targetDir := filepath.Join(tmpRoot, "target_admin")
	if err := os.MkdirAll(targetDir, 0o750); err != nil {
		t.Fatalf("failed creating target_admin: %v", err)
	}
	if err := os.WriteFile(filepath.Join(targetDir, "old.php"), []byte("old-content"), 0o600); err != nil {
		t.Fatalf("failed writing old.php: %v", err)
	}

	if err := AtomicSyncDir(ctx, srcDir, targetDir); err != nil {
		t.Fatalf("AtomicSyncDir failed: %v", err)
	}

	if _, err := os.Stat(filepath.Join(targetDir, "old.php")); !os.IsNotExist(err) {
		t.Fatalf("expected old.php to be replaced, but it exists")
	}

	data, err := os.ReadFile(filepath.Clean(filepath.Join(targetDir, "tool.php")))
	if err != nil || string(data) != "new-tool" {
		t.Fatalf("expected new-tool, got %s (err: %v)", string(data), err)
	}

	if err := AtomicSyncDir(ctx, filepath.Join(tmpRoot, "non_existent"), targetDir); err != nil {
		t.Fatalf("expected non-existent source to return nil, got %v", err)
	}
}

func TestCleanStaleTempDirs(t *testing.T) {
	tmpDir := t.TempDir()

	staleDirs := []string{
		".wp-admin.tmp_1234abcd",
		".wp-includes.bak_5678ef01",
	}
	for _, d := range staleDirs {
		if err := os.MkdirAll(filepath.Join(tmpDir, d), 0o750); err != nil {
			t.Fatalf("failed creating stale dir %s: %v", d, err)
		}
	}

	validDir := filepath.Join(tmpDir, "wp-content")
	if err := os.MkdirAll(validDir, 0o750); err != nil {
		t.Fatalf("failed creating valid dir: %v", err)
	}

	if err := CleanStaleTempDirs(tmpDir); err != nil {
		t.Fatalf("CleanStaleTempDirs failed: %v", err)
	}

	for _, d := range staleDirs {
		if _, err := os.Stat(filepath.Join(tmpDir, d)); !os.IsNotExist(err) {
			t.Fatalf("expected stale dir %s to be deleted", d)
		}
	}

	if _, err := os.Stat(validDir); err != nil {
		t.Fatalf("expected valid dir to remain intact")
	}
}

func TestSyncCustomContentDir(t *testing.T) {
	ctx := context.Background()

	t.Run("default content directory no-op", func(t *testing.T) {
		dir := t.TempDir()
		if err := SyncCustomContentDir(ctx, dir, "wp-content"); err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
	})

	t.Run("rename when target custom content does not exist", func(t *testing.T) {
		dir := t.TempDir()
		wpContent := filepath.Join(dir, "wp-content")
		if err := os.MkdirAll(wpContent, 0o750); err != nil {
			t.Fatalf("failed creating wp-content: %v", err)
		}
		if err := os.WriteFile(filepath.Join(wpContent, "style.css"), []byte("body{}"), 0o600); err != nil {
			t.Fatalf("failed writing style.css: %v", err)
		}

		if err := SyncCustomContentDir(ctx, dir, "app"); err != nil {
			t.Fatalf("SyncCustomContentDir failed: %v", err)
		}

		if _, err := os.Stat(wpContent); !os.IsNotExist(err) {
			t.Fatalf("expected wp-content to be renamed")
		}
		if _, err := os.Stat(filepath.Join(dir, "app", "style.css")); err != nil {
			t.Fatalf("expected app/style.css to exist")
		}
	})

	t.Run("merge when target custom content already exists", func(t *testing.T) {
		dir := t.TempDir()
		wpContent := filepath.Join(dir, "wp-content")
		appContent := filepath.Join(dir, "app")
		if err := os.MkdirAll(wpContent, 0o750); err != nil {
			t.Fatalf("failed creating wp-content: %v", err)
		}
		if err := os.MkdirAll(appContent, 0o750); err != nil {
			t.Fatalf("failed creating appContent: %v", err)
		}

		if err := os.WriteFile(filepath.Join(wpContent, "plugin.php"), []byte("plugin"), 0o600); err != nil {
			t.Fatalf("failed writing plugin.php: %v", err)
		}
		if err := os.WriteFile(filepath.Join(appContent, "existing.php"), []byte("existing"), 0o600); err != nil {
			t.Fatalf("failed writing existing.php: %v", err)
		}

		if err := SyncCustomContentDir(ctx, dir, "app"); err != nil {
			t.Fatalf("SyncCustomContentDir failed: %v", err)
		}

		if _, err := os.Stat(wpContent); !os.IsNotExist(err) {
			t.Fatalf("expected wp-content to be removed")
		}
		if _, err := os.Stat(filepath.Join(appContent, "plugin.php")); err != nil {
			t.Fatalf("expected merged plugin.php to exist")
		}
		if _, err := os.Stat(filepath.Join(appContent, "existing.php")); err != nil {
			t.Fatalf("expected existing.php to remain")
		}
	})
}

func TestSyncRootFiles(t *testing.T) {
	ctx := context.Background()
	srcDir := t.TempDir()
	dstDir := t.TempDir()

	if err := os.WriteFile(filepath.Join(srcDir, "index.php"), []byte("new index"), 0o600); err != nil {
		t.Fatalf("failed creating index.php: %v", err)
	}
	if err := os.WriteFile(filepath.Join(srcDir, "wp-login.php"), []byte("login"), 0o600); err != nil {
		t.Fatalf("failed creating wp-login.php: %v", err)
	}
	if err := os.WriteFile(filepath.Join(srcDir, "wp-config-sample.php"), []byte("sample"), 0o600); err != nil {
		t.Fatalf("failed creating wp-config-sample.php: %v", err)
	}
	if err := os.WriteFile(filepath.Join(srcDir, "readme.html"), []byte("readme"), 0o600); err != nil {
		t.Fatalf("failed creating readme.html: %v", err)
	}
	if err := os.WriteFile(filepath.Join(srcDir, "license.txt"), []byte("license"), 0o600); err != nil {
		t.Fatalf("failed creating license.txt: %v", err)
	}

	if err := os.WriteFile(filepath.Join(dstDir, "wp-config.php"), []byte("production config"), 0o600); err != nil {
		t.Fatalf("failed creating target wp-config.php: %v", err)
	}

	if err := SyncRootFiles(ctx, srcDir, dstDir); err != nil {
		t.Fatalf("SyncRootFiles failed: %v", err)
	}

	data, err := os.ReadFile(filepath.Clean(filepath.Join(dstDir, "wp-config.php")))
	if err != nil || string(data) != "production config" {
		t.Fatalf("wp-config.php was modified unexpectedly: %s", string(data))
	}

	if _, err := os.Stat(filepath.Join(dstDir, "index.php")); err != nil {
		t.Fatalf("expected index.php to be copied to dstDir")
	}

	if _, err := os.Stat(filepath.Join(dstDir, "wp-config-sample.php")); !os.IsNotExist(err) {
		t.Fatalf("expected wp-config-sample.php to be skipped")
	}
	if _, err := os.Stat(filepath.Join(dstDir, "readme.html")); !os.IsNotExist(err) {
		t.Fatalf("expected readme.html to be skipped")
	}
	if _, err := os.Stat(filepath.Join(dstDir, "license.txt")); !os.IsNotExist(err) {
		t.Fatalf("expected license.txt to be skipped")
	}
}

func TestVerifySbsLoader_Integrity(t *testing.T) {
	t.Run("missing loader is allowed when mu-plugins unused", func(t *testing.T) {
		tmpDir := t.TempDir()
		if err := VerifySbsLoader(tmpDir, "wp-content", "expected_hash"); err != nil {
			t.Fatalf("expected missing loader to be allowed, got %v", err)
		}
	})

	t.Run("valid canonical loader succeeds", func(t *testing.T) {
		tmpDir := t.TempDir()
		expectedHash := createTestSbsLoader(t, tmpDir, "wp-content")
		if err := VerifySbsLoader(tmpDir, "wp-content", expectedHash); err != nil {
			t.Fatalf("expected valid loader to succeed, got %v", err)
		}
	})

	t.Run("tampered loader returns integrity mismatch", func(t *testing.T) {
		tmpDir := t.TempDir()
		expectedHash := createTestSbsLoader(t, tmpDir, "wp-content")
		loaderPath := filepath.Join(tmpDir, "wp-content", "mu-plugins", "sbs-loader.php")
		if err := os.WriteFile(loaderPath, []byte("<?php // tampered"), 0o600); err != nil {
			t.Fatalf("failed tampering loader: %v", err)
		}

		err := VerifySbsLoader(tmpDir, "wp-content", expectedHash)
		if err == nil || !strings.Contains(err.Error(), "integrity mismatch") {
			t.Fatalf("expected integrity mismatch error, got %v", err)
		}
	})

	t.Run("empty expected hash returns error", func(t *testing.T) {
		tmpDir := t.TempDir()
		createTestSbsLoader(t, tmpDir, "wp-content")
		err := VerifySbsLoader(tmpDir, "wp-content", "")
		if err == nil || !strings.Contains(err.Error(), "expected sbs-loader hash cannot be empty") {
			t.Fatalf("expected empty hash error, got %v", err)
		}
	})
}

func TestVerifySbsLoader_ModeErrors(t *testing.T) {
	t.Run("directory instead of regular file returns error", func(t *testing.T) {
		tmpDir := t.TempDir()
		loaderDir := filepath.Join(tmpDir, "wp-content", "mu-plugins", "sbs-loader.php")
		if err := os.MkdirAll(loaderDir, 0o750); err != nil {
			t.Fatalf("failed creating dir: %v", err)
		}

		err := VerifySbsLoader(tmpDir, "wp-content", "expected_hash")
		if err == nil || !strings.Contains(err.Error(), "not a regular file") {
			t.Fatalf("expected not regular file error, got %v", err)
		}
	})

	t.Run("symlink returns not regular file error", func(t *testing.T) {
		tmpDir := t.TempDir()
		realFile := filepath.Join(tmpDir, "real_loader.php")
		if err := os.WriteFile(realFile, []byte("<?php // real"), 0o600); err != nil {
			t.Fatalf("failed writing real file: %v", err)
		}
		muDir := filepath.Join(tmpDir, "wp-content", "mu-plugins")
		if err := os.MkdirAll(muDir, 0o750); err != nil {
			t.Fatalf("failed creating muDir: %v", err)
		}
		loaderPath := filepath.Join(muDir, "sbs-loader.php")
		if err := os.Symlink(realFile, loaderPath); err != nil {
			t.Fatalf("failed creating symlink: %v", err)
		}

		err := VerifySbsLoader(tmpDir, "wp-content", "expected_hash")
		if err == nil || !strings.Contains(err.Error(), "not a regular file") {
			t.Fatalf("expected symlink rejection error, got %v", err)
		}
	})
}

func TestVerifySbsLoader_IOPermissionErrors(t *testing.T) {
	t.Run("unreadable file returns error during hash computation", func(t *testing.T) {
		tmpDir := t.TempDir()
		expectedHash := createTestSbsLoader(t, tmpDir, "wp-content")
		loaderPath := filepath.Join(tmpDir, "wp-content", "mu-plugins", "sbs-loader.php")
		if err := os.Chmod(loaderPath, 0o000); err != nil {
			t.Fatalf("failed chmod: %v", err)
		}
		t.Cleanup(func() {
			if err := os.Chmod(loaderPath, 0o600); err != nil && !os.IsNotExist(err) {
				t.Logf("cleanup chmod error: %v", err)
			}
		})

		err := VerifySbsLoader(tmpDir, "wp-content", expectedHash)
		if err == nil {
			t.Fatalf("expected error on unreadable file")
		}
	})

	t.Run("unreadable parent directory returns lstat error", func(t *testing.T) {
		tmpDir := t.TempDir()
		forbiddenDir := filepath.Join(tmpDir, "forbidden")
		if err := os.MkdirAll(forbiddenDir, 0o000); err != nil {
			t.Fatalf("failed creating forbidden dir: %v", err)
		}
		t.Cleanup(func() {
			if err := os.Chmod(forbiddenDir, 0o600); err != nil && !os.IsNotExist(err) {
				t.Logf("cleanup chmod error: %v", err)
			}
		})

		err := VerifySbsLoader(forbiddenDir, "wp-content", "expected_hash")
		if err == nil {
			t.Fatalf("expected error when parent directory cannot be traversed")
		}
	})
}

func TestVerifyAndRepairCore_Intact(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()

	if err := CopyDirRecursive(ctx, donorDir, targetDir); err != nil {
		t.Fatalf("setup copy failed: %v", err)
	}

	m, err := manifest.Generate(ctx, donorDir, "", "")
	if err != nil {
		t.Fatalf("manifest generate failed: %v", err)
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}

	repaired, count, err := VerifyAndRepairCore(ctx, cfg, m)
	if err != nil {
		t.Fatalf("VerifyAndRepairCore failed: %v", err)
	}
	if len(repaired) != 0 {
		t.Fatalf("expected 0 repairs, got %d: %v", len(repaired), repaired)
	}
	if count != len(m.CoreFiles) {
		t.Fatalf("expected count %d, got %d", len(m.CoreFiles), count)
	}
}

func TestVerifyAndRepairCore_UserFilePreserved(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()

	if err := CopyDirRecursive(ctx, donorDir, targetDir); err != nil {
		t.Fatalf("setup copy failed: %v", err)
	}

	m, err := manifest.Generate(ctx, donorDir, "", "")
	if err != nil {
		t.Fatalf("manifest generate failed: %v", err)
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}

	userRoot := filepath.Join(targetDir, "user_note.txt")
	if writeErr := os.WriteFile(userRoot, []byte("user note"), 0o600); writeErr != nil {
		t.Fatalf("failed creating user root file: %v", writeErr)
	}

	repaired, _, repairErr := VerifyAndRepairCore(ctx, cfg, m)
	if repairErr != nil {
		t.Fatalf("VerifyAndRepairCore failed: %v", repairErr)
	}
	if len(repaired) != 0 {
		t.Fatalf("expected 0 repairs, got %v", repaired)
	}
	data, readErr := os.ReadFile(filepath.Clean(userRoot))
	if readErr != nil || string(data) != "user note" {
		t.Fatalf("expected user root file to remain intact")
	}
}

func mutateModifiedAdmin(t *testing.T, targetDir string) {
	t.Helper()
	adminIndex := filepath.Join(targetDir, "wp-admin", "index.php")
	if err := os.WriteFile(adminIndex, []byte("tampered content"), 0o600); err != nil {
		t.Fatalf("failed tampering admin index: %v", err)
	}
	rootLogin := filepath.Join(targetDir, "wp-login.php")
	if err := os.Remove(rootLogin); err != nil {
		t.Fatalf("failed removing wp-login: %v", err)
	}
}

func verifyRepairedAdmin(t *testing.T, targetDir string) {
	t.Helper()
	adminIndex := filepath.Join(targetDir, "wp-admin", "index.php")
	data, err := os.ReadFile(filepath.Clean(adminIndex))
	if err != nil || string(data) != "<?php // admin" {
		t.Fatalf("expected repaired admin index, got %s", string(data))
	}
	rootLogin := filepath.Join(targetDir, "wp-login.php")
	if _, err := os.Stat(rootLogin); err != nil {
		t.Fatalf("expected wp-login.php to be restored")
	}
}

func mutateRogueAdmin(t *testing.T, targetDir string) {
	t.Helper()
	rogueAdmin := filepath.Join(targetDir, "wp-admin", "wso_shell.php")
	if err := os.WriteFile(rogueAdmin, []byte("<?php // rogue"), 0o600); err != nil {
		t.Fatalf("failed creating rogue admin file: %v", err)
	}
}

func verifyRogueAdmin(t *testing.T, targetDir string) {
	t.Helper()
	rogueAdmin := filepath.Join(targetDir, "wp-admin", "wso_shell.php")
	if _, err := os.Stat(rogueAdmin); !os.IsNotExist(err) {
		t.Fatalf("expected rogue admin file to be wiped out after atomic sync")
	}
}

func mutateRogueIncludes(t *testing.T, targetDir string) {
	t.Helper()
	rogueInc := filepath.Join(targetDir, "wp-includes", "backdoor.php")
	if err := os.WriteFile(rogueInc, []byte("<?php // backdoor"), 0o600); err != nil {
		t.Fatalf("failed creating rogue includes file: %v", err)
	}
}

func verifyRogueIncludes(t *testing.T, targetDir string) {
	t.Helper()
	rogueInc := filepath.Join(targetDir, "wp-includes", "backdoor.php")
	if _, err := os.Stat(rogueInc); !os.IsNotExist(err) {
		t.Fatalf("expected rogue includes file to be wiped out after atomic sync")
	}
}

func mutateTamperedIncludes(t *testing.T, targetDir string) {
	t.Helper()
	incVersion := filepath.Join(targetDir, "wp-includes", "version.php")
	if err := os.WriteFile(incVersion, []byte("<?php // corrupted"), 0o600); err != nil {
		t.Fatalf("failed corrupting version.php: %v", err)
	}
}

func verifyTamperedIncludes(t *testing.T, targetDir string) {
	t.Helper()
	incVersion := filepath.Join(targetDir, "wp-includes", "version.php")
	data, err := os.ReadFile(filepath.Clean(incVersion))
	if err != nil || !strings.Contains(string(data), "$wp_version = '6.6.1'") {
		t.Fatalf("expected restored version.php, got %s", string(data))
	}
}

func mutateMissingAdmin(t *testing.T, targetDir string) {
	t.Helper()
	if err := os.RemoveAll(filepath.Join(targetDir, "wp-admin")); err != nil {
		t.Fatalf("failed removing wp-admin: %v", err)
	}
}

func verifyRestoredAdmin(t *testing.T, targetDir string) {
	t.Helper()
	adminIndex := filepath.Join(targetDir, "wp-admin", "index.php")
	if _, err := os.Stat(adminIndex); err != nil {
		t.Fatalf("expected wp-admin to be restored: %v", err)
	}
}

func TestVerifyAndRepairCore_CompromisedScenarios(t *testing.T) {
	ctx := context.Background()
	tests := []struct {
		mutate func(t *testing.T, targetDir string)
		verify func(t *testing.T, targetDir string)
		name   string
	}{
		{
			mutate: mutateModifiedAdmin,
			verify: verifyRepairedAdmin,
			name:   "repairs modified admin index and missing root login",
		},
		{
			mutate: mutateRogueAdmin,
			verify: verifyRogueAdmin,
			name:   "rogue file in wp-admin triggers atomic replacement",
		},
		{
			mutate: mutateRogueIncludes,
			verify: verifyRogueIncludes,
			name:   "rogue file in wp-includes triggers atomic replacement",
		},
		{
			mutate: mutateTamperedIncludes,
			verify: verifyTamperedIncludes,
			name:   "tampered file in wp-includes triggers atomic replacement",
		},
		{
			mutate: mutateMissingAdmin,
			verify: verifyRestoredAdmin,
			name:   "missing wp-admin directory triggers atomic replacement",
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			donorDir := createTestDonor(t, "6.6.1")
			targetDir := t.TempDir()

			if err := CopyDirRecursive(ctx, donorDir, targetDir); err != nil {
				t.Fatalf("setup copy failed: %v", err)
			}

			m, err := manifest.Generate(ctx, donorDir, "", "")
			if err != nil {
				t.Fatalf("manifest generate failed: %v", err)
			}

			cfg := &Config{
				SourceDir: donorDir,
				TargetDir: targetDir,
			}

			tc.mutate(t, targetDir)

			repaired, _, err := VerifyAndRepairCore(ctx, cfg, m)
			if err != nil {
				t.Fatalf("VerifyAndRepairCore failed: %v", err)
			}

			expectedRepaired := []string{"wp-admin", "wp-includes", "root"}
			if !slices.Equal(repaired, expectedRepaired) {
				t.Fatalf("expected %v repaired components, got %v", expectedRepaired, repaired)
			}

			tc.verify(t, targetDir)
		})
	}
}

func TestRunCleanInstall(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.2")
	targetDir := t.TempDir()
	createTestSbsLoader(t, targetDir, "custom-app")
	var logBuf bytes.Buffer

	cfg := &Config{
		SourceDir:      donorDir,
		TargetDir:      targetDir,
		ContentDirName: "custom-app",
		Output:         &logBuf,
	}

	res, err := Run(ctx, cfg)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}
	if res.Action != "clean_install" {
		t.Fatalf("expected clean_install, got %s", res.Action)
	}

	if _, err := os.Stat(filepath.Join(targetDir, "index.php")); err != nil {
		t.Fatalf("expected index.php in targetDir")
	}
	if _, err := os.Stat(filepath.Join(targetDir, "custom-app", "mu-plugins", "sbs-loader.php")); err != nil {
		t.Fatalf("expected sbs-loader in custom-app")
	}
}

func TestRunUpgrade(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.7.0")
	targetDir := createTestDonor(t, "6.6.3")
	createTestSbsLoader(t, targetDir, "wp-content")
	var logBuf bytes.Buffer

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
		Output:    &logBuf,
	}

	res, err := Run(ctx, cfg)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}
	if res.Action != "upgraded" {
		t.Fatalf("expected upgraded, got %s", res.Action)
	}
	if res.SourceVersion != "6.7.0" || res.TargetVersion != "6.6.3" {
		t.Fatalf("unexpected versions: %v", res)
	}

	versionData, err := os.ReadFile(filepath.Clean(filepath.Join(targetDir, "wp-includes", "version.php")))
	if err != nil || !bytes.Contains(versionData, []byte("6.7.0")) {
		t.Fatalf("expected target version to be 6.7.0")
	}
}

func TestRunRollback(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.5.0")
	targetDir := createTestDonor(t, "6.6.4")
	createTestSbsLoader(t, targetDir, "wp-content")
	var logBuf bytes.Buffer

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
		Output:    &logBuf,
	}

	res, err := Run(ctx, cfg)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}
	if res.Action != "rolled_back" {
		t.Fatalf("expected rolled_back, got %s", res.Action)
	}
}

func TestRunUpToDateWithManifest(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.5")
	targetDir := createTestDonor(t, "6.6.5")
	createTestSbsLoader(t, targetDir, "wp-content")
	manifestPath := filepath.Join(t.TempDir(), "core-manifest.json")

	m, genErr := manifest.Generate(ctx, donorDir, "", "")
	if genErr != nil {
		t.Fatalf("manifest generate failed: %v", genErr)
	}
	if saveErr := manifest.Save(manifestPath, m); saveErr != nil {
		t.Fatalf("manifest save failed: %v", saveErr)
	}

	tamperedLogin := filepath.Join(targetDir, "wp-login.php")
	if err := os.WriteFile(tamperedLogin, []byte("tampered login"), 0o600); err != nil {
		t.Fatalf("failed tampering wp-login.php: %v", err)
	}

	var logBuf bytes.Buffer
	cfg := &Config{
		SourceDir:    donorDir,
		TargetDir:    targetDir,
		ManifestPath: manifestPath,
		Output:       &logBuf,
	}

	res, err := Run(ctx, cfg)
	if err != nil {
		t.Fatalf("Run failed: %v", err)
	}
	if res.Action != "repaired" {
		t.Fatalf("expected repaired action, got %s", res.Action)
	}
	if len(res.RepairedFiles) != 3 {
		t.Fatalf("expected 3 repaired components, got %v", res.RepairedFiles)
	}

	res2, err := Run(ctx, cfg)
	if err != nil {
		t.Fatalf("second Run failed: %v", err)
	}
	if res2.Action != "up_to_date" {
		t.Fatalf("expected up_to_date on clean target, got %s", res2.Action)
	}
}

func TestRunMissingDonor(t *testing.T) {
	ctx := context.Background()
	cfg := &Config{
		SourceDir: filepath.Join(t.TempDir(), "non_existent"),
		TargetDir: t.TempDir(),
	}
	_, err := Run(ctx, cfg)
	if err == nil {
		t.Fatalf("expected error on missing donor, got nil")
	}
}

type failWriter struct{}

func (failWriter) Write(_ []byte) (int, error) {
	return 0, errors.New("write failure")
}

func TestLogMsg(t *testing.T) {
	t.Parallel()

	logMsg(nil, "format")
	logMsg(failWriter{}, "format")
}

func TestCompareParts(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name string
		p1   string
		p2   string
		want int
	}{
		{name: "equal numbers", p1: "10", p2: "10", want: 0},
		{name: "lesser number", p1: "5", p2: "10", want: -1},
		{name: "greater number", p1: "15", p2: "10", want: 1},
		{name: "equal non-numbers", p1: "cat", p2: "cat", want: 0},
		{name: "lesser non-number", p1: "dog", p2: "fox", want: -1},
		{name: "greater non-number", p1: "zebra", p2: "yak", want: 1},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			got := compareParts(tt.p1, tt.p2)
			if got != tt.want {
				t.Fatalf("compareParts(%q, %q) = %d, want %d", tt.p1, tt.p2, got, tt.want)
			}
		})
	}
}

func TestResolveWorkerCount(t *testing.T) {
	t.Parallel()

	got := resolveWorkerCount()
	if got < 1 || got > 8 {
		t.Fatalf("resolveWorkerCount() = %d, expected between 1 and 8", got)
	}
}

func TestCopyFileErrors(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	validSrc := filepath.Join(tempDir, "src.txt")
	if err := os.WriteFile(validSrc, []byte("content"), 0o600); err != nil {
		t.Fatalf("failed writing src: %v", err)
	}

	if err := CopyFile(filepath.Join(tempDir, "missing.txt"), filepath.Join(tempDir, "dst.txt"), 0o640); err == nil {
		t.Fatalf("expected error on missing source file")
	}

	if err := CopyFile(validSrc, "/dev/null/forbidden/dst.txt", 0o640); err == nil {
		t.Fatalf("expected error on invalid dst directory")
	}

	readOnlyDir := filepath.Join(tempDir, "ro_dst")
	if err := os.Mkdir(readOnlyDir, 0o500); err != nil {
		t.Fatalf("failed creating ro_dst: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(readOnlyDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	if err := CopyFile(validSrc, filepath.Join(readOnlyDir, "dst.txt"), 0o640); err == nil {
		t.Fatalf("expected error writing in readonly directory")
	}

	srcDir := filepath.Join(tempDir, "src_dir")
	if err := os.Mkdir(srcDir, 0o750); err != nil {
		t.Fatalf("failed creating src_dir: %v", err)
	}
	if err := CopyFile(srcDir, filepath.Join(tempDir, "dst_from_dir.txt"), 0o640); err == nil {
		t.Fatalf("expected error when source is a directory")
	}
}

func TestCopyDirRecursiveErrors(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	tempDir := t.TempDir()

	if err := CopyDirRecursive(ctx, filepath.Join(tempDir, "missing"), filepath.Join(tempDir, "dst")); err == nil {
		t.Fatalf("expected error on missing source")
	}

	srcFile := filepath.Join(tempDir, "single_file.txt")
	if err := os.WriteFile(srcFile, []byte("single"), 0o600); err != nil {
		t.Fatalf("failed writing single file: %v", err)
	}
	dstFile := filepath.Join(tempDir, "copied_single.txt")
	if err := CopyDirRecursive(ctx, srcFile, dstFile); err != nil {
		t.Fatalf("expected regular file copy to succeed: %v", err)
	}

	validDir := filepath.Join(tempDir, "valid_src")
	if err := os.Mkdir(validDir, 0o750); err != nil {
		t.Fatalf("failed creating valid_src: %v", err)
	}
	if err := CopyDirRecursive(ctx, validDir, "/dev/null/forbidden/target"); err == nil {
		t.Fatalf("expected error on uncreatable target directory")
	}

	unreadableDir := filepath.Join(tempDir, "unreadable_src")
	if err := os.Mkdir(unreadableDir, 0o000); err != nil {
		t.Fatalf("failed creating unreadable dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadableDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	if err := CopyDirRecursive(ctx, unreadableDir, filepath.Join(tempDir, "dst_unreadable")); err == nil {
		t.Fatalf("expected error reading unreadable source directory")
	}
}

func TestActivateSwappedDir(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	targetDir := filepath.Join(tempDir, "target")
	if err := os.Mkdir(targetDir, 0o750); err != nil {
		t.Fatalf("failed creating target: %v", err)
	}
	if err := os.WriteFile(filepath.Join(targetDir, "f.txt"), []byte("orig"), 0o600); err != nil {
		t.Fatalf("failed writing f.txt: %v", err)
	}

	bakDir := filepath.Join(tempDir, "backup")
	missingTmp := filepath.Join(tempDir, "missing_tmp")

	if err := activateSwappedDir(missingTmp, targetDir, bakDir); err == nil {
		t.Fatalf("expected error when tmpDir is missing")
	}
	if _, err := os.Stat(filepath.Join(targetDir, "f.txt")); err != nil {
		t.Fatalf("expected backup to be restored to targetDir: %v", err)
	}
}

func TestCleanStaleTempDirsErrors(t *testing.T) {
	t.Parallel()

	if err := CleanStaleTempDirs(filepath.Join(t.TempDir(), "nonexistent")); err == nil {
		t.Fatalf("expected error when targetDir does not exist")
	}
}

func TestCopyItemIfNotExists(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	tempDir := t.TempDir()

	srcFile := filepath.Join(tempDir, "src.txt")
	if err := os.WriteFile(srcFile, []byte("val"), 0o600); err != nil {
		t.Fatalf("failed writing src.txt: %v", err)
	}
	dstFile := filepath.Join(tempDir, "dst.txt")

	if err := copyItemIfNotExists(ctx, srcFile, dstFile, false); err != nil {
		t.Fatalf("expected file copy to succeed: %v", err)
	}
	if err := copyItemIfNotExists(ctx, srcFile, dstFile, false); err != nil {
		t.Fatalf("expected existing file to be no-op: %v", err)
	}

	srcSubDir := filepath.Join(tempDir, "src_sub")
	if err := os.Mkdir(srcSubDir, 0o750); err != nil {
		t.Fatalf("failed creating src_sub: %v", err)
	}
	dstSubDir := filepath.Join(tempDir, "dst_sub")
	if err := copyItemIfNotExists(ctx, srcSubDir, dstSubDir, true); err != nil {
		t.Fatalf("expected dir copy to succeed: %v", err)
	}
	if err := copyItemIfNotExists(ctx, srcSubDir, dstSubDir, true); err != nil {
		t.Fatalf("expected existing dir to be no-op: %v", err)
	}
}

func TestMergeContentDirsErrors(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	tempDir := t.TempDir()

	unreadableDir := filepath.Join(tempDir, "unreadable_merge")
	if err := os.Mkdir(unreadableDir, 0o000); err != nil {
		t.Fatalf("failed creating unreadable dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadableDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	if err := mergeContentDirs(ctx, unreadableDir, tempDir); err == nil {
		t.Fatalf("expected error when source directory cannot be read")
	}

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	validSrc := filepath.Join(tempDir, "valid_merge_src")
	if err := os.Mkdir(validSrc, 0o750); err != nil {
		t.Fatalf("failed creating valid_merge_src: %v", err)
	}
	if err := os.WriteFile(filepath.Join(validSrc, "a.php"), []byte("<?php"), 0o600); err != nil {
		t.Fatalf("failed writing a.php: %v", err)
	}
	if err := mergeContentDirs(canceledCtx, validSrc, tempDir); err == nil {
		t.Fatalf("expected error on cancelled context during mergeContentDirs")
	}
}

func TestVerifyCoreIntegrityManifestFileChecks(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	tempDir := t.TempDir()

	cfgEmpty := &Config{ManifestPath: ""}
	repaired, count, err := verifyCoreIntegrity(ctx, cfgEmpty)
	if err != nil || len(repaired) != 0 || count != 0 {
		t.Fatalf("expected empty result for empty manifest path")
	}

	cfgMissing := &Config{ManifestPath: filepath.Join(tempDir, "nonexistent.json")}
	repaired, count, err = verifyCoreIntegrity(ctx, cfgMissing)
	if err != nil || len(repaired) != 0 || count != 0 {
		t.Fatalf("expected empty result for missing manifest")
	}

	dirManifest := filepath.Join(tempDir, "dir_manifest")
	if mkErr := os.Mkdir(dirManifest, 0o750); mkErr != nil {
		t.Fatalf("failed creating dir manifest: %v", mkErr)
	}
	cfgDir := &Config{ManifestPath: dirManifest}
	repaired, count, err = verifyCoreIntegrity(ctx, cfgDir)
	if err != nil || len(repaired) != 0 || count != 0 {
		t.Fatalf("expected empty result when manifest is a directory")
	}

	corruptManifest := filepath.Join(tempDir, "corrupted.json")
	if writeErr := os.WriteFile(corruptManifest, []byte("{bad-json"), 0o600); writeErr != nil {
		t.Fatalf("failed writing corrupt manifest: %v", writeErr)
	}
	cfgCorrupt := &Config{ManifestPath: corruptManifest}
	_, _, err = verifyCoreIntegrity(ctx, cfgCorrupt)
	if err == nil {
		t.Fatalf("expected error loading corrupted manifest")
	}
}

func TestVerifyCoreIntegrityEdgeCases(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	tempDir := t.TempDir()

	forbiddenParent := filepath.Join(tempDir, "forbidden_manifest_parent")
	if mkErr := os.Mkdir(forbiddenParent, 0o000); mkErr != nil {
		t.Fatalf("failed creating forbidden dir: %v", mkErr)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(forbiddenParent, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
	})
	cfgStatErr := &Config{ManifestPath: filepath.Join(forbiddenParent, "manifest.json")}
	if _, _, err := verifyCoreIntegrity(ctx, cfgStatErr); err == nil {
		t.Fatalf("expected stat error on forbidden parent")
	}

	emptyManifestFile := filepath.Join(tempDir, "empty_manifest.json")
	if writeErr := os.WriteFile(emptyManifestFile, []byte(`{"wordpress_version":"6.6.1","core_files":{}}`), 0o600); writeErr != nil {
		t.Fatalf("failed writing empty manifest: %v", writeErr)
	}
	cfgEmptyCore := &Config{ManifestPath: emptyManifestFile}
	repaired, count, err := verifyCoreIntegrity(ctx, cfgEmptyCore)
	if err != nil || len(repaired) != 0 || count != 0 {
		t.Fatalf("expected empty result for empty CoreFiles, got repaired=%v count=%d err=%v", repaired, count, err)
	}
}

func TestCheckInstallationStateBranches(t *testing.T) {
	t.Parallel()

	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()

	incDir := filepath.Join(targetDir, "wp-includes")
	if err := os.MkdirAll(incDir, 0o750); err != nil {
		t.Fatalf("failed creating target wp-includes: %v", err)
	}
	unreadableVersion := filepath.Join(incDir, "version.php")
	if err := os.WriteFile(unreadableVersion, []byte("data"), 0o000); err != nil {
		t.Fatalf("failed writing unreadable version.php: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadableVersion); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	_, _, _, err := checkInstallationState(donorDir, targetDir)
	if err == nil {
		t.Fatalf("expected error when target version.php is unreadable")
	}
}

func TestRunEdgeCases(t *testing.T) {
	t.Parallel()

	donorDir := createTestDonor(t, "6.6.1")
	tempDir := t.TempDir()

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	cfgCanceled := &Config{
		SourceDir: donorDir,
		TargetDir: tempDir,
	}
	if _, err := Run(canceledCtx, cfgCanceled); err == nil {
		t.Fatalf("expected error on canceled context in Run")
	}

	donorFile := filepath.Join(tempDir, "donor_is_file.txt")
	if err := os.WriteFile(donorFile, []byte("donor"), 0o600); err != nil {
		t.Fatalf("failed writing file: %v", err)
	}
	cfgFileDonor := &Config{
		SourceDir: donorFile,
		TargetDir: tempDir,
	}
	if _, err := Run(context.Background(), cfgFileDonor); err == nil {
		t.Fatalf("expected error when sourceDir is a regular file")
	}

	noOutTarget := t.TempDir()
	createTestSbsLoader(t, noOutTarget, "wp-content")
	cfgNoOutput := &Config{
		SourceDir: donorDir,
		TargetDir: noOutTarget,
		Output:    nil,
	}
	res, err := Run(context.Background(), cfgNoOutput)
	if err != nil || res.Action != "clean_install" {
		t.Fatalf("expected successful clean install with nil output, got %v (err: %v)", res, err)
	}
}

func TestAtomicSyncDirErrors(t *testing.T) {
	t.Parallel()

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := AtomicSyncDir(canceledCtx, t.TempDir(), t.TempDir()); err == nil {
		t.Fatalf("expected error on canceled context in AtomicSyncDir")
	}

	tempDir := t.TempDir()
	srcFile := filepath.Join(tempDir, "file_not_dir.txt")
	if err := os.WriteFile(srcFile, []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing file: %v", err)
	}
	if err := AtomicSyncDir(context.Background(), srcFile, tempDir); err != nil {
		t.Fatalf("expected non-directory source to return nil: %v", err)
	}
}

func TestSyncRootFilesErrors(t *testing.T) {
	t.Parallel()

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := SyncRootFiles(canceledCtx, t.TempDir(), t.TempDir()); err == nil {
		t.Fatalf("expected error on canceled context in SyncRootFiles")
	}

	if err := SyncRootFiles(context.Background(), filepath.Join(t.TempDir(), "nonexistent"), t.TempDir()); err == nil {
		t.Fatalf("expected error on nonexistent source in SyncRootFiles")
	}

	donorDir := createTestDonor(t, "6.6.1")
	if err := SyncRootFiles(context.Background(), donorDir, "/dev/null/forbidden"); err == nil {
		t.Fatalf("expected error copying root files to readonly target")
	}
}

func TestActivateSwappedDirBranches(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	tmpDir := filepath.Join(tempDir, "tmp_source")
	if err := os.Mkdir(tmpDir, 0o750); err != nil {
		t.Fatalf("failed creating tmp_source: %v", err)
	}
	if err := os.WriteFile(filepath.Join(tmpDir, "content.txt"), []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing content: %v", err)
	}

	newTarget := filepath.Join(tempDir, "new_target")
	bakDir := filepath.Join(tempDir, "bak_dir")
	if err := activateSwappedDir(tmpDir, newTarget, bakDir); err != nil {
		t.Fatalf("expected success when target does not exist: %v", err)
	}

	existingTarget := filepath.Join(tempDir, "existing_target")
	if err := os.Mkdir(existingTarget, 0o750); err != nil {
		t.Fatalf("failed creating existing_target: %v", err)
	}
	anotherTmp := filepath.Join(tempDir, "another_tmp")
	if err := os.Mkdir(anotherTmp, 0o750); err != nil {
		t.Fatalf("failed creating another_tmp: %v", err)
	}
	if err := activateSwappedDir(anotherTmp, existingTarget, "/dev/null/forbidden/bak"); err == nil {
		t.Fatalf("expected backup rename error")
	}
}

func TestSyncCustomContentDirBranches(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	emptyDir := t.TempDir()
	if err := SyncCustomContentDir(ctx, emptyDir, "custom"); err != nil {
		t.Fatalf("expected success when default content dir does not exist: %v", err)
	}

	if err := SyncCustomContentDir(ctx, "/dev/null/forbidden", "app"); err == nil {
		t.Fatalf("expected error renaming wp-content in readonly directory")
	}

	symlinkTarget := t.TempDir()
	targetDir := t.TempDir()
	wpContentSymlink := filepath.Join(targetDir, "wp-content")
	if err := os.Symlink(symlinkTarget, wpContentSymlink); err != nil {
		t.Fatalf("setup symlink failed: %v", err)
	}
	if err := SyncCustomContentDir(ctx, targetDir, "custom"); err == nil {
		t.Fatalf("expected error when wp-content is a symlink")
	}

	targetDir2 := t.TempDir()
	wpContentDir := filepath.Join(targetDir2, "wp-content")
	if err := os.MkdirAll(wpContentDir, 0o750); err != nil {
		t.Fatalf("setup wp-content failed: %v", err)
	}
	customSymlink := filepath.Join(targetDir2, "custom")
	if err := os.Symlink(symlinkTarget, customSymlink); err != nil {
		t.Fatalf("setup custom symlink failed: %v", err)
	}
	if err := SyncCustomContentDir(ctx, targetDir2, "custom"); err == nil {
		t.Fatalf("expected error when custom content dir is a symlink")
	}
}

func TestVerifyAndRepairCoreFailures(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()
	if err := CopyDirRecursive(ctx, donorDir, targetDir); err != nil {
		t.Fatalf("setup copy failed: %v", err)
	}

	m, err := manifest.Generate(ctx, donorDir, "", "")
	if err != nil {
		t.Fatalf("manifest generate failed: %v", err)
	}

	rootLogin := filepath.Join(targetDir, "wp-login.php")
	if err := os.WriteFile(rootLogin, []byte("tampered"), 0o600); err != nil {
		t.Fatalf("tamper write failed: %v", err)
	}
	if err := os.Chmod(rootLogin, 0o400); err != nil {
		t.Fatalf("chmod rootLogin failed: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Chmod(rootLogin, 0o600); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup chmod error: %v", err)
		}
		if err := os.Remove(rootLogin); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}

	if _, _, err := VerifyAndRepairCore(ctx, cfg, m); err == nil {
		t.Fatalf("expected repair failure when target file is readonly")
	}
}

func TestRunFailureBranches(t *testing.T) {
	t.Parallel()

	ctx := context.Background()
	donorDir := createTestDonor(t, "6.6.1")

	cfgBadInstall := &Config{
		SourceDir: donorDir,
		TargetDir: "/dev/null/forbidden",
	}
	if _, err := Run(ctx, cfgBadInstall); err == nil {
		t.Fatalf("expected error on clean install failure")
	}

	targetDir := createTestDonor(t, "6.5.0")
	createTestSbsLoader(t, targetDir, "wp-content")
	corruptManifest := filepath.Join(t.TempDir(), "corrupt.json")
	if err := os.WriteFile(corruptManifest, []byte("{bad"), 0o600); err != nil {
		t.Fatalf("failed writing corrupt manifest: %v", err)
	}

	cfgCorruptManifest := &Config{
		SourceDir:    donorDir,
		TargetDir:    targetDir,
		ManifestPath: corruptManifest,
	}
	targetVersionPath := filepath.Join(targetDir, "wp-includes", "version.php")
	if err := os.WriteFile(targetVersionPath, []byte("<?php\n$wp_version = '6.6.1';\n"), 0o600); err != nil {
		t.Fatalf("failed aligning versions: %v", err)
	}

	if _, err := Run(ctx, cfgCorruptManifest); err == nil {
		t.Fatalf("expected error when manifest verification fails during Run")
	}

	brokenDonor := createTestDonor(t, "6.8.0")
	brokenAdminFile := filepath.Join(brokenDonor, "wp-admin", "unreadable.php")
	if err := os.WriteFile(brokenAdminFile, []byte("data"), 0o000); err != nil {
		t.Fatalf("failed writing unreadable admin file: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(brokenAdminFile); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	cfgMismatchErr := &Config{
		SourceDir: brokenDonor,
		TargetDir: targetDir,
	}
	if _, err := Run(ctx, cfgMismatchErr); err == nil {
		t.Fatalf("expected error when upgrade fails due to unreadable admin file")
	}
}

func mockFailingCopyDir(_ context.Context, _, _ string) error {
	return errors.New("simulated copy dir error")
}

func mockFailingSyncCustomContent(_ context.Context, _, _ string) error {
	return errors.New("simulated sync custom content error")
}

func mockFailingVerifyLoader(_ context.Context, _ *Config, _ string) error {
	return errors.New("simulated verify loader error")
}

func mockFailingCleanStaleTempDirs(_ string) error {
	return errors.New("simulated clean stale temp dirs error")
}

func mockFailingAtomicSyncDir(_ context.Context, _, _ string) error {
	return errors.New("simulated atomic sync dir error")
}

func mockFailingSyncRootFiles(_ context.Context, _, _ string) error {
	return errors.New("simulated sync root files error")
}

func mockFailingVerifyCoreIntegrity(_ context.Context, _ *Config) (repaired []string, checked int, err error) {
	return nil, 0, errors.New("simulated verify core integrity error")
}

func mockAtomicSyncOnlyAdminFails(_ context.Context, src, _ string) error {
	if strings.Contains(src, "wp-includes") {
		return errors.New("simulated wp-includes atomic sync error")
	}
	return nil
}

func TestHandleCleanInstallErrors(t *testing.T) {
	origCopy := copyDirRecursiveFn
	origSync := syncCustomContentDirFn
	origLoader := verifySbsLoaderFn
	defer func() {
		copyDirRecursiveFn = origCopy
		syncCustomContentDirFn = origSync
		verifySbsLoaderFn = origLoader
	}()

	tests := []struct {
		setup func()
		name  string
	}{
		{
			name: "copy dir failure",
			setup: func() {
				copyDirRecursiveFn = mockFailingCopyDir
				syncCustomContentDirFn = defaultSyncCustomContentDir
				verifySbsLoaderFn = defaultVerifySbsLoader
			},
		},
		{
			name: "sync custom content failure",
			setup: func() {
				copyDirRecursiveFn = defaultCopyDirRecursive
				syncCustomContentDirFn = mockFailingSyncCustomContent
				verifySbsLoaderFn = defaultVerifySbsLoader
			},
		},
		{
			name: "verify sbs loader failure",
			setup: func() {
				copyDirRecursiveFn = defaultCopyDirRecursive
				syncCustomContentDirFn = defaultSyncCustomContentDir
				verifySbsLoaderFn = mockFailingVerifyLoader
			},
		},
	}

	cfg := &Config{
		SourceDir: t.TempDir(),
		TargetDir: t.TempDir(),
	}
	ctx := context.Background()

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			tc.setup()
			if _, err := handleCleanInstall(ctx, cfg, "6.6.1"); err == nil {
				t.Fatalf("expected error in handleCleanInstall")
			}
		})
	}
}

func TestHandleVersionMismatchErrors(t *testing.T) {
	origClean := cleanStaleTempDirsFn
	origAtomic := atomicSyncDirFn
	origSyncRoot := syncRootFilesFn
	defer func() {
		cleanStaleTempDirsFn = origClean
		atomicSyncDirFn = origAtomic
		syncRootFilesFn = origSyncRoot
	}()

	tests := []struct {
		setup func()
		name  string
	}{
		{
			name: "clean stale temp failure",
			setup: func() {
				cleanStaleTempDirsFn = mockFailingCleanStaleTempDirs
				atomicSyncDirFn = defaultAtomicSyncDir
				syncRootFilesFn = defaultSyncRootFiles
			},
		},
		{
			name: "atomic sync wp-admin failure",
			setup: func() {
				cleanStaleTempDirsFn = defaultCleanStaleTempDirs
				atomicSyncDirFn = mockFailingAtomicSyncDir
				syncRootFilesFn = defaultSyncRootFiles
			},
		},
		{
			name: "sync root files failure",
			setup: func() {
				cleanStaleTempDirsFn = defaultCleanStaleTempDirs
				atomicSyncDirFn = defaultAtomicSyncDir
				syncRootFilesFn = mockFailingSyncRootFiles
			},
		},
	}

	cfg := &Config{
		SourceDir: t.TempDir(),
		TargetDir: t.TempDir(),
	}
	ctx := context.Background()

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			tc.setup()
			if _, err := handleVersionMismatch(ctx, cfg, "6.6.1", "6.5.0"); err == nil {
				t.Fatalf("expected error in handleVersionMismatch")
			}
		})
	}
}

func TestHandleVersionMismatchIncludesError(t *testing.T) {
	origAtomic := atomicSyncDirFn
	defer func() { atomicSyncDirFn = origAtomic }()
	atomicSyncDirFn = mockAtomicSyncOnlyAdminFails

	cfg := &Config{
		SourceDir: t.TempDir(),
		TargetDir: t.TempDir(),
	}
	if _, err := handleVersionMismatch(context.Background(), cfg, "6.6.1", "6.5.0"); err == nil {
		t.Fatalf("expected error when wp-includes sync fails")
	}
}

func TestRunLifecycleHookErrors(t *testing.T) {
	origSync := syncCustomContentDirFn
	origLoader := verifySbsLoaderFn
	origVerify := verifyCoreIntegrityFn
	defer func() {
		syncCustomContentDirFn = origSync
		verifySbsLoaderFn = origLoader
		verifyCoreIntegrityFn = origVerify
	}()

	donorDir := createTestDonor(t, "6.6.1")
	targetDir := createTestDonor(t, "6.6.1")

	tests := []struct {
		setup func()
		name  string
	}{
		{
			name: "run sync custom content failure",
			setup: func() {
				syncCustomContentDirFn = mockFailingSyncCustomContent
				verifySbsLoaderFn = defaultVerifySbsLoader
				verifyCoreIntegrityFn = defaultVerifyCoreIntegrity
			},
		},
		{
			name: "run verify loader failure",
			setup: func() {
				syncCustomContentDirFn = defaultSyncCustomContentDir
				verifySbsLoaderFn = mockFailingVerifyLoader
				verifyCoreIntegrityFn = defaultVerifyCoreIntegrity
			},
		},
		{
			name: "run verify integrity failure",
			setup: func() {
				syncCustomContentDirFn = defaultSyncCustomContentDir
				verifySbsLoaderFn = defaultVerifySbsLoader
				verifyCoreIntegrityFn = mockFailingVerifyCoreIntegrity
			},
		},
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}
	ctx := context.Background()

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			tc.setup()
			if _, err := Run(ctx, cfg); err == nil {
				t.Fatalf("expected error in Run")
			}
		})
	}
}

func TestVerifyAndRepairCoreCancelled(t *testing.T) {
	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	cfg := &Config{}
	m := &manifest.Manifest{}

	if _, _, err := VerifyAndRepairCore(canceledCtx, cfg, m); err == nil {
		t.Fatalf("expected error on cancelled context in VerifyAndRepairCore")
	}
}

func TestAuditCoreFilesIntegrityCancelled(t *testing.T) {
	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	m := &manifest.Manifest{
		CoreFiles: map[string]string{
			"wp-login.php": "fakehash",
			"index.php":    "fakehash2",
		},
	}

	admin, inc, root := auditCoreFilesIntegrity(canceledCtx, t.TempDir(), m)
	if admin || inc || root {
		t.Fatalf("expected all false on cancelled context")
	}
}

func TestAuditCoreFilesIntegrityCancelledWorker(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())

	m := &manifest.Manifest{
		CoreFiles: make(map[string]string, 200),
	}
	for i := range 200 {
		m.CoreFiles[fmt.Sprintf("file_%d.php", i)] = "fakehash"
	}

	go func() {
		time.Sleep(500 * time.Microsecond)
		cancel()
	}()

	admin, inc, root := auditCoreFilesIntegrity(ctx, t.TempDir(), m)
	_ = admin || inc || root
}

type scanCanceller struct {
	cancel context.CancelFunc
}

func (s *scanCanceller) scanAndCancel(_, _ string, _ map[string]string) (bool, error) {
	s.cancel()
	return false, nil
}

func TestVerifyAndRepairCoreCancelledDuringAudit(t *testing.T) {
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()
	if err := CopyDirRecursive(context.Background(), donorDir, targetDir); err != nil {
		t.Fatalf("setup copy failed: %v", err)
	}
	m, err := manifest.Generate(context.Background(), donorDir, "", "")
	if err != nil {
		t.Fatalf("manifest generate failed: %v", err)
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}

	ctx, cancel := context.WithCancel(context.Background())
	origScan := scanRogueFilesFn
	defer func() { scanRogueFilesFn = origScan }()

	canceller := &scanCanceller{cancel: cancel}
	scanRogueFilesFn = canceller.scanAndCancel

	if _, _, err := VerifyAndRepairCore(ctx, cfg, m); err == nil {
		t.Fatalf("expected error when context is cancelled during audit")
	}
}

func TestCheckInstallationStateTargetReadError(t *testing.T) {
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()

	incDir := filepath.Join(targetDir, "wp-includes")
	if err := os.MkdirAll(incDir, 0o750); err != nil {
		t.Fatalf("failed creating incDir: %v", err)
	}
	unreadableVersion := filepath.Join(incDir, "version.php")
	if err := os.WriteFile(unreadableVersion, []byte("<?php"), 0o000); err != nil {
		t.Fatalf("failed writing unreadable target version: %v", err)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(unreadableVersion, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
		if rmErr := os.Remove(unreadableVersion); rmErr != nil {
			t.Logf("cleanup remove error: %v", rmErr)
		}
	})

	if _, _, _, err := checkInstallationState(donorDir, targetDir); err == nil {
		t.Fatalf("expected error when target version is unreadable")
	}
}

func TestMergeContentDirsCopyError(t *testing.T) {
	ctx := context.Background()
	srcDir := t.TempDir()

	if err := os.WriteFile(filepath.Join(srcDir, "sub_file"), []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing src file: %v", err)
	}
	readOnlyDst := filepath.Join(t.TempDir(), "ro_dst")
	if err := os.Mkdir(readOnlyDst, 0o500); err != nil {
		t.Fatalf("failed creating ro_dst: %v", err)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(readOnlyDst, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
	})

	if err := mergeContentDirs(ctx, srcDir, readOnlyDst); err == nil {
		t.Fatalf("expected error when copyItemIfNotExists fails")
	}
}

func TestSyncCustomContentDirRenameError(t *testing.T) {
	ctx := context.Background()
	parentDir := t.TempDir()
	wpContent := filepath.Join(parentDir, "wp-content")
	if err := os.Mkdir(wpContent, 0o750); err != nil {
		t.Fatalf("failed creating wp-content: %v", err)
	}

	origRename := renameFn
	defer func() { renameFn = origRename }()
	renameFn = mockFailingRename

	if err := SyncCustomContentDir(ctx, parentDir, "custom"); err == nil {
		t.Fatalf("expected error renaming in custom content directory")
	}
}

func TestAtomicSyncDirStatError(t *testing.T) {
	parent := filepath.Join(t.TempDir(), "forbidden_atomic_parent")
	if err := os.Mkdir(parent, 0o000); err != nil {
		t.Fatalf("failed creating forbidden dir: %v", err)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(parent, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
	})

	child := filepath.Join(parent, "source")
	if err := AtomicSyncDir(context.Background(), child, t.TempDir()); err == nil {
		t.Fatalf("expected stat error in AtomicSyncDir")
	}
}

func TestCheckInstallationStateMissingDonorVersion(t *testing.T) {
	donorDir := t.TempDir()
	targetDir := t.TempDir()

	if _, _, _, err := checkInstallationState(donorDir, targetDir); err == nil {
		t.Fatalf("expected error when donor version.php is missing")
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}
	if _, err := Run(context.Background(), cfg); err == nil {
		t.Fatalf("expected error from Run when donor version.php is missing")
	}
}

type mockFailingWriter struct{}

func (m *mockFailingWriter) Write(_ []byte) (int, error) {
	return 0, errors.New("simulated file write failure")
}

func (m *mockFailingWriter) Close() error {
	return nil
}

type mockFailingCloser struct{}

func (m *mockFailingCloser) Write(p []byte) (int, error) {
	return len(p), nil
}

func (m *mockFailingCloser) Close() error {
	return errors.New("simulated file close failure")
}

func mockOpenFileFailingWrite(_ string, _ int, _ os.FileMode) (fileWriter, error) {
	return &mockFailingWriter{}, nil
}

func mockOpenFileFailingClose(_ string, _ int, _ os.FileMode) (fileWriter, error) {
	return &mockFailingCloser{}, nil
}

func mockOpenFileFailingOpen(_ string, _ int, _ os.FileMode) (fileWriter, error) {
	return nil, errors.New("simulated open file failure")
}

type mockFailingSourceReader struct {
	io.Reader
}

func (m *mockFailingSourceReader) Close() error {
	return errors.New("simulated source close error")
}

func mockOpenSourceFailingClose(_ string) (fileReader, error) {
	return &mockFailingSourceReader{Reader: strings.NewReader("sample donor file")}, nil
}

func mockFailingChmod(_ string, _ os.FileMode) error {
	return errors.New("simulated chmod failure")
}

func mockFailingRename(_, _ string) error {
	return errors.New("simulated rename failure")
}

func mockFailingRemoveAll(_ string) error {
	return errors.New("simulated remove all failure")
}

func TestDefaultFileOperations(t *testing.T) {
	tempDir := t.TempDir()
	if _, err := defaultOpenFile(filepath.Join(tempDir, "missing_dir", "file"), os.O_RDONLY, 0o600); err == nil {
		t.Fatalf("expected error from defaultOpenFile on uncreatable path")
	}
	if err := defaultRename(filepath.Join(tempDir, "nonexistent"), filepath.Join(tempDir, "target")); err == nil {
		t.Fatalf("expected error from defaultRename on nonexistent source")
	}
	forbidden := filepath.Join(tempDir, "forbidden")
	if err := os.Mkdir(forbidden, 0o000); err != nil {
		t.Fatalf("failed creating forbidden dir: %v", err)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(forbidden, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
	})
	if err := defaultRemoveAll(filepath.Join(forbidden, "sub")); err == nil {
		t.Fatalf("expected error from defaultRemoveAll on unsearchable ancestor path")
	}

	realTarget := filepath.Join(tempDir, "real_target.txt")
	if err := os.WriteFile(realTarget, []byte("data"), 0o600); err != nil {
		t.Fatalf("failed creating real target: %v", err)
	}
	symPath := filepath.Join(tempDir, "sym_target.txt")
	if err := os.Symlink(realTarget, symPath); err != nil {
		t.Fatalf("failed creating symlink: %v", err)
	}
	if _, err := defaultOpenFile(symPath, os.O_WRONLY|syscall.O_NOFOLLOW, 0o600); err == nil {
		t.Fatalf("expected error from defaultOpenFile on symlink with O_NOFOLLOW")
	}

	fSrc, err := defaultOpenSource(realTarget)
	if err != nil {
		t.Fatalf("expected defaultOpenSource to succeed: %v", err)
	}
	if closeErr := fSrc.Close(); closeErr != nil {
		t.Fatalf("expected fSrc.Close to succeed: %v", closeErr)
	}

	if _, err := defaultOpenSource(filepath.Join(tempDir, "missing.txt")); err == nil {
		t.Fatalf("expected error from defaultOpenSource on missing file")
	}

	if err := defaultChmod(realTarget, 0o640); err != nil {
		t.Fatalf("expected defaultChmod to succeed: %v", err)
	}

	if err := defaultChmod(filepath.Join(tempDir, "missing.txt"), 0o640); err == nil {
		t.Fatalf("expected error from defaultChmod on missing file")
	}
}

func TestCopyFileWriterErrors(t *testing.T) {
	tempDir := t.TempDir()
	src := filepath.Join(tempDir, "src.txt")
	if err := os.WriteFile(src, []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing src: %v", err)
	}

	origOpen := openFileFn
	origOpenSource := openSourceFn
	origChmod := chmodFn
	defer func() {
		openFileFn = origOpen
		openSourceFn = origOpenSource
		chmodFn = origChmod
	}()

	openFileFn = mockOpenFileFailingOpen
	if err := CopyFile(src, filepath.Join(tempDir, "dst1.txt"), 0o640); err == nil {
		t.Fatalf("expected error when openFileFn fails")
	}

	openFileFn = mockOpenFileFailingWrite
	if err := CopyFile(src, filepath.Join(tempDir, "dst2.txt"), 0o640); err == nil {
		t.Fatalf("expected error when write fails")
	}

	openFileFn = mockOpenFileFailingClose
	if err := CopyFile(src, filepath.Join(tempDir, "dst3.txt"), 0o640); err == nil {
		t.Fatalf("expected error when close fails")
	}

	openFileFn = defaultOpenFile
	openSourceFn = mockOpenSourceFailingClose
	if err := CopyFile(src, filepath.Join(tempDir, "dst4.txt"), 0o640); err == nil {
		t.Fatalf("expected error when openSourceFn.Close fails")
	}

	openSourceFn = defaultOpenSource
	chmodFn = mockFailingChmod
	if err := CopyFile(src, filepath.Join(tempDir, "dst5.txt"), 0o640); err == nil {
		t.Fatalf("expected error when chmodFn fails")
	}
}

type mockRenameTracker struct {
	calls []string
}

func (m *mockRenameTracker) renameFailingOnTmp(oldpath, newpath string) error {
	m.calls = append(m.calls, oldpath)
	if strings.Contains(oldpath, "tmp") {
		return errors.New("simulated tmp rename failure")
	}
	return defaultRename(oldpath, newpath)
}

func (m *mockRenameTracker) renameAlwaysFailing(oldpath, _ string) error {
	m.calls = append(m.calls, oldpath)
	return errors.New("simulated persistent rename failure")
}

func TestActivateSwappedDirBackupErrors(t *testing.T) {
	tempDir := t.TempDir()

	origRename := renameFn
	origRemoveAll := removeAllFn
	defer func() {
		renameFn = origRename
		removeAllFn = origRemoveAll
	}()

	targetDir := filepath.Join(tempDir, "target")
	if err := os.Mkdir(targetDir, 0o750); err != nil {
		t.Fatalf("failed creating target dir: %v", err)
	}
	tmpDir := filepath.Join(tempDir, "tmp")
	if err := os.Mkdir(tmpDir, 0o750); err != nil {
		t.Fatalf("failed creating tmp dir: %v", err)
	}
	bakDir := filepath.Join(tempDir, "bak")

	renameFn = mockFailingRename
	removeAllFn = mockFailingRemoveAll
	if err := activateSwappedDir(tmpDir, targetDir, bakDir); err == nil {
		t.Fatalf("expected error when target backup rename and removeAll both fail")
	}

	trackerAll := &mockRenameTracker{}
	renameFn = trackerAll.renameAlwaysFailing
	removeAllFn = defaultRemoveAll
	if err := activateSwappedDir(tmpDir, targetDir, bakDir); err == nil {
		t.Fatalf("expected error when target backup rename fails but removeAll succeeds")
	}

	if err := defaultRename(targetDir, bakDir); err != nil {
		t.Fatalf("setup rename failed: %v", err)
	}
	trackerTmp := &mockRenameTracker{}
	renameFn = trackerTmp.renameFailingOnTmp
	removeAllFn = mockFailingRemoveAll
	if err := activateSwappedDir(tmpDir, targetDir, bakDir); err == nil {
		t.Fatalf("expected error when activate fails and cleanup removeAll fails")
	}
}

func TestActivateSwappedDirRestoreAndCleanupErrors(t *testing.T) {
	tempDir := t.TempDir()

	origRename := renameFn
	origRemoveAll := removeAllFn
	defer func() {
		renameFn = origRename
		removeAllFn = origRemoveAll
	}()

	trackerAll := &mockRenameTracker{}
	targetDirForRestore := filepath.Join(tempDir, "target_restore_fail")
	bakDirForRestore := filepath.Join(tempDir, "bak_restore_fail")
	if err := os.Mkdir(bakDirForRestore, 0o750); err != nil {
		t.Fatalf("failed creating bakDirForRestore: %v", err)
	}
	tmpDirForRestore := filepath.Join(tempDir, "tmp_restore_fail")
	if err := os.Mkdir(tmpDirForRestore, 0o750); err != nil {
		t.Fatalf("failed creating tmpDirForRestore: %v", err)
	}
	renameFn = trackerAll.renameAlwaysFailing
	removeAllFn = defaultRemoveAll
	if err := activateSwappedDir(tmpDirForRestore, targetDirForRestore, bakDirForRestore); err == nil {
		t.Fatalf("expected error when activate fails and restore fails")
	}

	targetNoBak := filepath.Join(tempDir, "target_no_bak")
	if err := os.Mkdir(targetNoBak, 0o750); err != nil {
		t.Fatalf("failed creating target: %v", err)
	}
	tmpNoBak := filepath.Join(tempDir, "tmp_no_bak")
	if err := os.Mkdir(tmpNoBak, 0o750); err != nil {
		t.Fatalf("failed creating tmp: %v", err)
	}
	missingBak := filepath.Join(tempDir, "missing_bak")
	renameFn = mockFailingRename
	removeAllFn = defaultRemoveAll
	if err := activateSwappedDir(tmpNoBak, targetNoBak, missingBak); err == nil {
		t.Fatalf("expected error when swap fails without backup")
	}

	targetBakDone := filepath.Join(tempDir, "target_bak_done")
	tmpBakDone := filepath.Join(tempDir, "tmp_bak_done")
	if err := os.Mkdir(tmpBakDone, 0o750); err != nil {
		t.Fatalf("failed creating tmpBakDone: %v", err)
	}
	bakDone := filepath.Join(tempDir, "bak_done")
	if err := os.Mkdir(bakDone, 0o750); err != nil {
		t.Fatalf("failed creating bakDone: %v", err)
	}
	renameFn = defaultRename
	removeAllFn = mockFailingRemoveAll
	if err := activateSwappedDir(tmpBakDone, targetBakDone, bakDone); err == nil {
		t.Fatalf("expected error when removing bakDir fails")
	}
}

func TestAtomicSyncDirRemoveAllError(t *testing.T) {
	origRemoveAll := removeAllFn
	defer func() { removeAllFn = origRemoveAll }()
	removeAllFn = mockFailingRemoveAll

	srcDir := filepath.Join(t.TempDir(), "unreadable_src")
	if err := os.Mkdir(srcDir, 0o750); err != nil {
		t.Fatalf("failed creating srcDir: %v", err)
	}
	sub := filepath.Join(srcDir, "sub")
	if err := os.Mkdir(sub, 0o000); err != nil {
		t.Fatalf("failed creating sub: %v", err)
	}
	t.Cleanup(func() {
		if chmodErr := os.Chmod(sub, 0o600); chmodErr != nil {
			t.Logf("cleanup chmod error: %v", chmodErr)
		}
	})

	targetDir := filepath.Join(t.TempDir(), "target")
	if err := AtomicSyncDir(context.Background(), srcDir, targetDir); err == nil {
		t.Fatalf("expected error from AtomicSyncDir when CopyDirRecursive fails and removeAll fails")
	}
}

func TestCleanStaleTempDirsRemoveError(t *testing.T) {
	tempDir := t.TempDir()
	staleDir := filepath.Join(tempDir, ".wp-admin.tmp_12345")
	if err := os.Mkdir(staleDir, 0o750); err != nil {
		t.Fatalf("failed creating stale dir: %v", err)
	}

	origRemoveAll := removeAllFn
	defer func() { removeAllFn = origRemoveAll }()
	removeAllFn = mockFailingRemoveAll

	if err := CleanStaleTempDirs(tempDir); err == nil {
		t.Fatalf("expected error when removing stale temp dir fails")
	}
}

func TestMergeContentDirsRemoveError(t *testing.T) {
	srcDir := t.TempDir()
	dstDir := t.TempDir()

	origRemoveAll := removeAllFn
	defer func() { removeAllFn = origRemoveAll }()
	removeAllFn = mockFailingRemoveAll

	if err := mergeContentDirs(context.Background(), srcDir, dstDir); err == nil {
		t.Fatalf("expected error when removeAllFn fails in mergeContentDirs")
	}
}

func TestCopyFileSymlinkHandling(t *testing.T) {
	tempDir := t.TempDir()
	src := filepath.Join(tempDir, "src.txt")
	if err := os.WriteFile(src, []byte("payload"), 0o600); err != nil {
		t.Fatalf("failed writing src: %v", err)
	}

	targetOutside := filepath.Join(tempDir, "outside.txt")
	if err := os.WriteFile(targetOutside, []byte("orig"), 0o600); err != nil {
		t.Fatalf("failed writing targetOutside: %v", err)
	}

	symDst := filepath.Join(tempDir, "sym_dst.txt")
	if err := os.Symlink(targetOutside, symDst); err != nil {
		t.Fatalf("failed creating symlink: %v", err)
	}

	if err := CopyFile(src, symDst, 0o640); err != nil {
		t.Fatalf("CopyFile through symlink failed: %v", err)
	}

	outsideData, err := os.ReadFile(filepath.Clean(targetOutside))
	if err != nil || string(outsideData) != "orig" {
		t.Fatalf("outside target modified through symlink!")
	}

	symData, err := os.ReadFile(filepath.Clean(symDst))
	if err != nil || string(symData) != "payload" {
		t.Fatalf("destination does not contain payload: %s", string(symData))
	}

	symDstErr := filepath.Join(tempDir, "sym_err.txt")
	if err := os.Symlink(targetOutside, symDstErr); err != nil {
		t.Fatalf("failed creating second symlink: %v", err)
	}

	origRemove := removeFn
	defer func() { removeFn = origRemove }()
	removeFn = mockFailingRemoveFile

	if err := CopyFile(src, symDstErr, 0o640); err == nil {
		t.Fatalf("expected error when removeFn fails on symlink")
	}
}

func mockFailingScanRogueAdmin(targetSubDir, _ string, _ map[string]string) (bool, error) {
	if strings.Contains(targetSubDir, "wp-admin") {
		return false, errors.New("simulated scan rogue admin error")
	}
	return false, nil
}

func mockFailingScanRogueIncludes(targetSubDir, _ string, _ map[string]string) (bool, error) {
	if strings.Contains(targetSubDir, "wp-includes") {
		return false, errors.New("simulated scan rogue includes error")
	}
	return false, nil
}

func mockFailingMkdirTemp(_, _ string) (string, error) {
	return "", errors.New("simulated mkdir temp error")
}

func mockFailingRemoveFile(_ string) error {
	return errors.New("simulated remove error")
}

func mockFailingAtomicSyncAdmin(_ context.Context, src, _ string) error {
	if strings.Contains(src, "wp-admin") {
		return errors.New("simulated atomic sync admin error")
	}
	return nil
}

func TestScanRogueFilesBranches_Valid(t *testing.T) {
	tempDir := t.TempDir()

	resNonExist, errNonExist := defaultScanRogueFiles(filepath.Join(tempDir, "missing"), "wp-admin", nil)
	if errNonExist != nil || resNonExist {
		t.Fatalf("expected false, nil for nonexistent dir")
	}

	fileTarget := filepath.Join(tempDir, "file.txt")
	if err := os.WriteFile(fileTarget, []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing file: %v", err)
	}
	resFile, errFile := defaultScanRogueFiles(fileTarget, "wp-admin", nil)
	if errFile != nil || !resFile {
		t.Fatalf("expected true, nil for non-directory target")
	}

	symlinkInsideDir := filepath.Join(tempDir, "rogue_sym_dir")
	if err := os.MkdirAll(symlinkInsideDir, 0o750); err != nil {
		t.Fatalf("failed creating dir: %v", err)
	}
	symlinkPath := filepath.Join(symlinkInsideDir, "symlink.txt")
	if err := os.Symlink(fileTarget, symlinkPath); err != nil {
		t.Fatalf("failed creating symlink: %v", err)
	}
	resSym, errSym := defaultScanRogueFiles(symlinkInsideDir, "wp-admin", map[string]string{})
	if errSym != nil || !resSym {
		t.Fatalf("expected true, nil when directory contains a symlink")
	}
}

func TestScanRogueFilesBranches_Errors(t *testing.T) {
	tempDir := t.TempDir()

	unreadableDir := filepath.Join(tempDir, "unreadable_rogue")
	if err := os.Mkdir(unreadableDir, 0o000); err != nil {
		t.Fatalf("failed creating unreadable dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadableDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	_, errUnread := defaultScanRogueFiles(unreadableDir, "wp-admin", nil)
	if errUnread == nil {
		t.Fatalf("expected error from unreadable directory in defaultScanRogueFiles")
	}

	forbiddenParent := filepath.Join(tempDir, "forbidden_parent")
	if err := os.Mkdir(forbiddenParent, 0o000); err != nil {
		t.Fatalf("failed creating forbidden parent: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Chmod(forbiddenParent, 0o600); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup chmod error: %v", err)
		}
		if err := os.Remove(forbiddenParent); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	forbiddenChild := filepath.Join(forbiddenParent, "wp-admin")
	_, statErr := defaultScanRogueFiles(forbiddenChild, "wp-admin", nil)
	if statErr == nil {
		t.Fatalf("expected stat error on forbidden parent child in defaultScanRogueFiles")
	}
}

func TestVerifyAndRepairCoreErrorBranches(t *testing.T) {
	donorDir := createTestDonor(t, "6.6.1")
	targetDir := t.TempDir()
	if err := CopyDirRecursive(context.Background(), donorDir, targetDir); err != nil {
		t.Fatalf("setup copy failed: %v", err)
	}
	m, err := manifest.Generate(context.Background(), donorDir, "", "")
	if err != nil {
		t.Fatalf("manifest generate failed: %v", err)
	}

	cfg := &Config{
		SourceDir: donorDir,
		TargetDir: targetDir,
	}

	origScan := scanRogueFilesFn
	origAtomic := atomicSyncDirFn
	origSyncRoot := syncRootFilesFn
	defer func() {
		scanRogueFilesFn = origScan
		atomicSyncDirFn = origAtomic
		syncRootFilesFn = origSyncRoot
	}()

	scanRogueFilesFn = mockFailingScanRogueAdmin
	if _, _, err := VerifyAndRepairCore(context.Background(), cfg, m); err == nil {
		t.Fatalf("expected error when scanRogueAdmin fails")
	}

	scanRogueFilesFn = mockFailingScanRogueIncludes
	if _, _, err := VerifyAndRepairCore(context.Background(), cfg, m); err == nil {
		t.Fatalf("expected error when scanRogueIncludes fails")
	}

	scanRogueFilesFn = defaultScanRogueFiles

	adminIndex := filepath.Join(targetDir, "wp-admin", "index.php")
	if err := os.WriteFile(adminIndex, []byte("bad"), 0o600); err != nil {
		t.Fatalf("failed modifying admin index: %v", err)
	}
	atomicSyncDirFn = mockFailingAtomicSyncAdmin
	if _, _, err := VerifyAndRepairCore(context.Background(), cfg, m); err == nil {
		t.Fatalf("expected error when atomicSyncAdmin fails")
	}

	atomicSyncDirFn = mockAtomicSyncOnlyAdminFails
	incVersion := filepath.Join(targetDir, "wp-includes", "version.php")
	if err := os.WriteFile(incVersion, []byte("bad"), 0o600); err != nil {
		t.Fatalf("failed modifying inc version: %v", err)
	}
	if _, _, err := VerifyAndRepairCore(context.Background(), cfg, m); err == nil {
		t.Fatalf("expected error when atomicSyncIncludes fails")
	}

	atomicSyncDirFn = defaultAtomicSyncDir
	syncRootFilesFn = mockFailingSyncRootFiles
	if _, _, err := VerifyAndRepairCore(context.Background(), cfg, m); err == nil {
		t.Fatalf("expected error when syncRootFiles fails")
	}
}

func TestAtomicSyncDirMkdirTempError(t *testing.T) {
	origMkdirTemp := mkdirTempFn
	defer func() { mkdirTempFn = origMkdirTemp }()
	mkdirTempFn = mockFailingMkdirTemp

	srcDir := t.TempDir()
	targetDir := filepath.Join(t.TempDir(), "target")
	if err := AtomicSyncDir(context.Background(), srcDir, targetDir); err == nil {
		t.Fatalf("expected error from AtomicSyncDir when mkdirTempFn fails")
	}
}

func TestDefaultAdaptersCoverage(t *testing.T) {
	if err := defaultRemove(filepath.Join(t.TempDir(), "nonexistent")); err == nil {
		t.Fatalf("expected error from defaultRemove on missing file")
	}

	if _, err := defaultMkdirTemp("/dev/null/forbidden", "test_*"); err == nil {
		t.Fatalf("expected error from defaultMkdirTemp on invalid path")
	}
}

func TestDefaultVerifySbsLoader(t *testing.T) {
	ctx := context.Background()
	tempDir := t.TempDir()

	validTarget := t.TempDir()
	validHash := createTestSbsLoader(t, validTarget, "sbs-content")

	validManifestPath := filepath.Join(tempDir, "valid-manifest.json")
	validManifest := &manifest.Manifest{
		WordPressVersion: "7.1.3",
		SbsLoaderHash:    validHash,
	}
	if err := manifest.Save(validManifestPath, validManifest); err != nil {
		t.Fatalf("failed saving valid manifest: %v", err)
	}

	emptyHashManifestPath := filepath.Join(tempDir, "empty-hash-manifest.json")
	emptyHashManifest := &manifest.Manifest{
		WordPressVersion: "7.1.3",
	}
	if err := manifest.Save(emptyHashManifestPath, emptyHashManifest); err != nil {
		t.Fatalf("failed saving empty hash manifest: %v", err)
	}

	corruptManifestPath := filepath.Join(tempDir, "corrupt-manifest.json")
	if err := os.WriteFile(corruptManifestPath, []byte("{invalid json"), 0o600); err != nil {
		t.Fatalf("failed writing corrupt manifest: %v", err)
	}

	dirManifestPath := filepath.Join(tempDir, "dir-manifest")
	if err := os.Mkdir(dirManifestPath, 0o750); err != nil {
		t.Fatalf("failed creating dir manifest: %v", err)
	}

	symlinkManifestPath := filepath.Join(tempDir, "symlink-manifest.json")
	if err := os.Symlink(validManifestPath, symlinkManifestPath); err != nil {
		t.Fatalf("failed creating symlink manifest: %v", err)
	}

	tamperedTarget := t.TempDir()
	createTestSbsLoader(t, tamperedTarget, "sbs-content")
	tamperedLoader := filepath.Join(tamperedTarget, "sbs-content", "mu-plugins", "sbs-loader.php")
	if err := os.WriteFile(tamperedLoader, []byte("<?php // tampered"), 0o600); err != nil {
		t.Fatalf("failed tampering loader: %v", err)
	}

	missingLoaderTarget := t.TempDir()

	forbiddenDir := filepath.Join(tempDir, "forbidden")
	if err := os.Mkdir(forbiddenDir, 0o000); err != nil {
		t.Fatalf("failed creating forbidden dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Chmod(forbiddenDir, 0o600); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup chmod error: %v", err)
		}
	})
	unreadableManifestPath := filepath.Join(forbiddenDir, "unreadable.json")

	tests := []struct {
		cfg     *Config
		name    string
		wantErr bool
	}{
		{
			name: "empty manifest path skips verification",
			cfg: &Config{
				ManifestPath: "",
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "nonexistent manifest path skips verification",
			cfg: &Config{
				ManifestPath: filepath.Join(tempDir, "nonexistent.json"),
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "directory manifest path skips verification",
			cfg: &Config{
				ManifestPath: dirManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "symlink manifest path skips verification",
			cfg: &Config{
				ManifestPath: symlinkManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "manifest with empty loader hash skips verification",
			cfg: &Config{
				ManifestPath: emptyHashManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "valid manifest with matching loader succeeds",
			cfg: &Config{
				ManifestPath: validManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: false,
		},
		{
			name: "corrupt manifest returns error",
			cfg: &Config{
				ManifestPath: corruptManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: true,
		},
		{
			name: "unreadable manifest path returns error",
			cfg: &Config{
				ManifestPath: unreadableManifestPath,
				TargetDir:    validTarget,
			},
			wantErr: true,
		},
		{
			name: "missing sbs-loader file is allowed when unused",
			cfg: &Config{
				ManifestPath: validManifestPath,
				TargetDir:    missingLoaderTarget,
			},
			wantErr: false,
		},
		{
			name: "tampered sbs-loader file returns integrity mismatch error",
			cfg: &Config{
				ManifestPath: validManifestPath,
				TargetDir:    tamperedTarget,
			},
			wantErr: true,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			err := defaultVerifySbsLoader(ctx, tc.cfg, "sbs-content")
			if (err != nil) != tc.wantErr {
				t.Fatalf("defaultVerifySbsLoader() error = %v, wantErr = %v", err, tc.wantErr)
			}
		})
	}
}
