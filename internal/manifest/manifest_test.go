package manifest

import (
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func createTestDonorFixture(t *testing.T) (donorDir, muDir string) {
	t.Helper()

	donorDir = t.TempDir()
	muDir = t.TempDir()

	wpIncludes := filepath.Join(donorDir, "wp-includes")
	if err := os.MkdirAll(wpIncludes, 0o750); err != nil {
		t.Fatalf("failed to create wp-includes: %v", err)
	}
	versionFile := filepath.Join(wpIncludes, "version.php")
	if err := os.WriteFile(versionFile, []byte("<?php\n$wp_version = '7.1.3';\n"), 0o600); err != nil {
		t.Fatalf("failed to write version.php: %v", err)
	}

	rootFile := filepath.Join(donorDir, "index.php")
	if err := os.WriteFile(rootFile, []byte("<?php // silence"), 0o600); err != nil {
		t.Fatalf("failed to write index.php: %v", err)
	}

	if err := os.WriteFile(filepath.Join(donorDir, "readme.html"), []byte("readme"), 0o600); err != nil {
		t.Fatalf("failed to write readme.html: %v", err)
	}
	if err := os.WriteFile(filepath.Join(donorDir, "license.txt"), []byte("license"), 0o600); err != nil {
		t.Fatalf("failed to write license.txt: %v", err)
	}
	if err := os.WriteFile(filepath.Join(donorDir, "wp-config-sample.php"), []byte("sample"), 0o600); err != nil {
		t.Fatalf("failed to write wp-config-sample.php: %v", err)
	}

	wpContent := filepath.Join(donorDir, "wp-content")
	if err := os.MkdirAll(wpContent, 0o750); err != nil {
		t.Fatalf("failed to create wp-content: %v", err)
	}
	ignoredContentFile := filepath.Join(wpContent, "ignored.php")
	if err := os.WriteFile(ignoredContentFile, []byte("<?php // ignored"), 0o600); err != nil {
		t.Fatalf("failed to write ignored.php: %v", err)
	}

	muFile := filepath.Join(muDir, "wp-notify.php")
	if err := os.WriteFile(muFile, []byte("<?php // notify"), 0o600); err != nil {
		t.Fatalf("failed to write wp-notify.php: %v", err)
	}

	return donorDir, muDir
}

func TestExtractWpVersion(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	validFile := filepath.Join(tempDir, "version.php")
	if err := os.WriteFile(validFile, []byte("<?php\n$wp_version = '7.1.1';\n"), 0o600); err != nil {
		t.Fatalf("failed to create test file: %v", err)
	}

	invalidContentFile := filepath.Join(tempDir, "bad_version.php")
	if err := os.WriteFile(invalidContentFile, []byte("<?php\n// no version here\n"), 0o600); err != nil {
		t.Fatalf("failed to create bad test file: %v", err)
	}

	missingFile := filepath.Join(tempDir, "non_existent.php")

	tests := []struct {
		name        string
		path        string
		wantVersion string
		wantErr     bool
	}{
		{
			name:        "valid version",
			path:        validFile,
			wantVersion: "7.1.1",
			wantErr:     false,
		},
		{
			name:        "invalid content",
			path:        invalidContentFile,
			wantVersion: "",
			wantErr:     true,
		},
		{
			name:        "missing file",
			path:        missingFile,
			wantVersion: "",
			wantErr:     true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			got, err := ExtractWpVersion(tt.path)
			if tt.wantErr {
				if err == nil {
					t.Errorf("ExtractWpVersion() expected error, got nil")
				}
				return
			}
			if err != nil {
				t.Fatalf("ExtractWpVersion() unexpected error: %v", err)
			}
			if got != tt.wantVersion {
				t.Errorf("ExtractWpVersion() got %q, want %q", got, tt.wantVersion)
			}
		})
	}
}

func TestComputeFileHash(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	testFile := filepath.Join(tempDir, "test.txt")
	if err := os.WriteFile(testFile, []byte("hello-guard"), 0o600); err != nil {
		t.Fatalf("failed to write test file: %v", err)
	}

	hash, err := ComputeFileHash(testFile)
	if err != nil {
		t.Fatalf("ComputeFileHash() unexpected error: %v", err)
	}

	expectedHash := "8a1c8795d84303c859a4be580c3764679bdb28d7ad0c41e2813335daca549239"
	if hash != expectedHash {
		t.Errorf("ComputeFileHash() = %s, want %s", hash, expectedHash)
	}

	_, missingErr := ComputeFileHash(filepath.Join(tempDir, "missing.txt"))
	if missingErr == nil {
		t.Errorf("ComputeFileHash() expected error for missing file")
	}
}

func TestGenerate(t *testing.T) {
	t.Parallel()

	donorDir, muDir := createTestDonorFixture(t)
	ctx := context.Background()

	m, err := Generate(ctx, donorDir, muDir)
	if err != nil {
		t.Fatalf("Generate() unexpected error: %v", err)
	}

	if m.WordPressVersion != "7.1.3" {
		t.Errorf("m.WordPressVersion = %q, want %q", m.WordPressVersion, "7.1.3")
	}
	if _, exists := m.CoreFiles["wp-includes/version.php"]; !exists {
		t.Errorf("m.CoreFiles missing wp-includes/version.php")
	}
	if _, exists := m.CoreFiles["index.php"]; !exists {
		t.Errorf("m.CoreFiles missing index.php")
	}
	if _, exists := m.CoreFiles["readme.html"]; exists {
		t.Errorf("m.CoreFiles unexpectedly includes readme.html")
	}
	if _, exists := m.CoreFiles["license.txt"]; exists {
		t.Errorf("m.CoreFiles unexpectedly includes license.txt")
	}
	if _, exists := m.CoreFiles["wp-config-sample.php"]; exists {
		t.Errorf("m.CoreFiles unexpectedly includes wp-config-sample.php")
	}
	if _, exists := m.CoreFiles["wp-content/ignored.php"]; exists {
		t.Errorf("m.CoreFiles unexpectedly includes wp-content/ignored.php")
	}
	if _, exists := m.MuPlugins["wp-notify.php"]; !exists {
		t.Errorf("m.MuPlugins missing wp-notify.php")
	}
}

func TestIsIgnoredDonorFile(t *testing.T) {
	t.Parallel()

	tests := []struct {
		relPath string
		want    bool
	}{
		{relPath: "readme.html", want: true},
		{relPath: "license.txt", want: true},
		{relPath: "wp-config-sample.php", want: true},
		{relPath: "wp-config.php", want: true},
		{relPath: "index.php", want: false},
		{relPath: "wp-login.php", want: false},
		{relPath: "wp-includes/license.txt", want: false},
		{relPath: "wp-admin/readme.html", want: false},
	}

	for _, tt := range tests {
		t.Run(tt.relPath, func(t *testing.T) {
			t.Parallel()
			if got := isIgnoredDonorFile(tt.relPath); got != tt.want {
				t.Errorf("isIgnoredDonorFile(%q) = %v, want %v", tt.relPath, got, tt.want)
			}
		})
	}
}

func TestSaveAndLoad(t *testing.T) {
	t.Parallel()

	donorDir, muDir := createTestDonorFixture(t)
	ctx := context.Background()

	m, err := Generate(ctx, donorDir, muDir)
	if err != nil {
		t.Fatalf("Generate() unexpected error: %v", err)
	}

	manifestPath := filepath.Join(t.TempDir(), "sub", "core-manifest.json")
	if saveErr := Save(manifestPath, m); saveErr != nil {
		t.Fatalf("Save() unexpected error: %v", saveErr)
	}

	loaded, loadErr := Load(manifestPath)
	if loadErr != nil {
		t.Fatalf("Load() unexpected error: %v", loadErr)
	}

	if loaded.WordPressVersion != m.WordPressVersion {
		t.Errorf("loaded.WordPressVersion = %q, want %q", loaded.WordPressVersion, m.WordPressVersion)
	}
	if len(loaded.CoreFiles) != len(m.CoreFiles) {
		t.Errorf("loaded.CoreFiles count = %d, want %d", len(loaded.CoreFiles), len(m.CoreFiles))
	}
	if len(loaded.MuPlugins) != len(m.MuPlugins) {
		t.Errorf("loaded.MuPlugins count = %d, want %d", len(loaded.MuPlugins), len(m.MuPlugins))
	}
}

func TestGenerateErrors(t *testing.T) {
	t.Parallel()

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()

	tests := []struct {
		ctx      context.Context
		name     string
		donorDir string
		muDir    string
	}{
		{
			name:     "canceled context",
			ctx:      canceledCtx,
			donorDir: t.TempDir(),
			muDir:    t.TempDir(),
		},
		{
			name:     "missing version file in donor",
			ctx:      context.Background(),
			donorDir: t.TempDir(),
			muDir:    t.TempDir(),
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			t.Parallel()
			_, err := Generate(tt.ctx, tt.donorDir, tt.muDir)
			if err == nil {
				t.Fatalf("Generate() expected error for case %s, got nil", tt.name)
			}
		})
	}
}

func TestLoadCorrupted(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	badFile := filepath.Join(tempDir, "corrupted.json")
	if err := os.WriteFile(badFile, []byte("{not-valid-json"), 0o600); err != nil {
		t.Fatalf("failed to write corrupted file: %v", err)
	}

	_, err := Load(badFile)
	if err == nil {
		t.Errorf("Load() expected error on corrupted JSON")
	}

	_, missingErr := Load(filepath.Join(tempDir, "nonexistent.json"))
	if missingErr == nil {
		t.Errorf("Load() expected error on nonexistent JSON")
	}
}

func TestLoadInvalidPaths(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()

	tests := []struct {
		name    string
		jsonDoc string
	}{
		{
			name:    "core files contains path traversal",
			jsonDoc: `{"wordpress_version":"6.7.0","core_files":{"../escape.php":"hash"},"mu_plugins":{}}`,
		},
		{
			name:    "mu plugins contains path traversal",
			jsonDoc: `{"wordpress_version":"6.7.0","core_files":{},"mu_plugins":{"../escape_mu.php":"hash"}}`,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			t.Parallel()
			filePath := filepath.Join(tempDir, tc.name+".json")
			if err := os.WriteFile(filePath, []byte(tc.jsonDoc), 0o600); err != nil {
				t.Fatalf("failed writing test file: %v", err)
			}

			_, err := Load(filePath)
			if err == nil || !errors.Is(err, ErrInvalidManifestPath) {
				t.Fatalf("expected ErrInvalidManifestPath, got %v", err)
			}
		})
	}
}

func TestSaveErrors(t *testing.T) {
	t.Parallel()

	m := &Manifest{WordPressVersion: "7.1.3"}
	badPath := "/dev/null/forbidden/manifest.json"
	if err := Save(badPath, m); err == nil {
		t.Fatalf("Save() expected error on invalid path, got nil")
	}
}

func TestLoadEmptyJson(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	emptyPath := filepath.Join(tempDir, "empty.json")
	if err := os.WriteFile(emptyPath, []byte("{}\n"), 0o600); err != nil {
		t.Fatalf("failed writing empty json: %v", err)
	}

	m, err := Load(emptyPath)
	if err != nil {
		t.Fatalf("Load() unexpected error: %v", err)
	}
	if m.CoreFiles == nil || m.MuPlugins == nil {
		t.Fatalf("expected non-nil maps for empty json")
	}
}

func TestScanMuPluginsEdgeCases(t *testing.T) {
	t.Parallel()

	res, err := scanMuPlugins("")
	if err != nil || len(res) != 0 {
		t.Fatalf("expected empty map for empty muDir, got %v (err: %v)", res, err)
	}

	resNonExistent, errNonExistent := scanMuPlugins(filepath.Join(t.TempDir(), "non_existent"))
	if errNonExistent != nil || len(resNonExistent) != 0 {
		t.Fatalf("expected empty map for non-existent muDir, got %v (err: %v)", resNonExistent, errNonExistent)
	}

	tempDir := t.TempDir()
	filePath := filepath.Join(tempDir, "regular_file.txt")
	if err := os.WriteFile(filePath, []byte("file"), 0o600); err != nil {
		t.Fatalf("failed writing file: %v", err)
	}

	resFile, errFile := scanMuPlugins(filePath)
	if errFile != nil || len(resFile) != 0 {
		t.Fatalf("expected empty map when muDir is a file, got %v (err: %v)", resFile, errFile)
	}

	subDir := filepath.Join(tempDir, "mu")
	if err := os.MkdirAll(subDir, 0o750); err != nil {
		t.Fatalf("failed creating subDir: %v", err)
	}
	if err := os.MkdirAll(filepath.Join(subDir, "nested_dir"), 0o750); err != nil {
		t.Fatalf("failed creating nested dir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(subDir, "readme.txt"), []byte("docs"), 0o600); err != nil {
		t.Fatalf("failed writing txt file: %v", err)
	}

	resSub, errSub := scanMuPlugins(subDir)
	if errSub != nil || len(resSub) != 0 {
		t.Fatalf("expected non-php entries to be skipped, got %v", resSub)
	}
}

func mockScanCoreFilesError(_ context.Context, _ string) (map[string]string, error) {
	return nil, errors.New("mock scan core files error")
}

func mockScanCoreFilesEmpty(_ context.Context, _ string) (map[string]string, error) {
	return make(map[string]string), nil
}

func mockScanMuPluginsError(_ string) (map[string]string, error) {
	return nil, errors.New("mock scan mu-plugins error")
}

func TestGenerateMockErrors(t *testing.T) {
	donorDir, muDir := createTestDonorFixture(t)
	ctx := context.Background()

	scanCoreFilesFn = mockScanCoreFilesError
	_, err := Generate(ctx, donorDir, muDir)
	scanCoreFilesFn = defaultScanCoreFiles
	if err == nil {
		t.Fatalf("expected error from mockScanCoreFilesError")
	}

	scanCoreFilesFn = mockScanCoreFilesEmpty
	_, errEmpty := Generate(ctx, donorDir, muDir)
	scanCoreFilesFn = defaultScanCoreFiles
	if !errors.Is(errEmpty, ErrEmptyDonorDir) {
		t.Fatalf("expected ErrEmptyDonorDir, got %v", errEmpty)
	}

	scanMuPluginsFn = mockScanMuPluginsError
	_, errMu := Generate(ctx, donorDir, muDir)
	scanMuPluginsFn = defaultScanMuPlugins
	if errMu == nil {
		t.Fatalf("expected error from mockScanMuPluginsError")
	}
}

func TestComputeFileHashDirError(t *testing.T) {
	t.Parallel()

	dir := t.TempDir()
	_, err := ComputeFileHash(dir)
	if err == nil || !errors.Is(err, ErrNotRegularFile) {
		t.Fatalf("expected ErrNotRegularFile when hashing a directory, got %v", err)
	}

	regularFile := filepath.Join(dir, "target.txt")
	if writeErr := os.WriteFile(regularFile, []byte("target"), 0o600); writeErr != nil {
		t.Fatalf("failed writing target file: %v", writeErr)
	}
	symlinkFile := filepath.Join(dir, "symlink.txt")
	if symErr := os.Symlink(regularFile, symlinkFile); symErr != nil {
		t.Fatalf("failed creating symlink: %v", symErr)
	}
	_, symlinkErr := ComputeFileHash(symlinkFile)
	if symlinkErr == nil || !errors.Is(symlinkErr, ErrNotRegularFile) {
		t.Fatalf("expected ErrNotRegularFile when hashing a symlink, got %v", symlinkErr)
	}
}

func TestScanCoreFilesBranches(t *testing.T) {
	t.Parallel()

	donorDir, _ := createTestDonorFixture(t)

	symlinkPath := filepath.Join(donorDir, "symlink_test")
	if err := os.Symlink(filepath.Join(donorDir, "index.php"), symlinkPath); err != nil {
		t.Fatalf("failed creating symlink: %v", err)
	}

	files, err := scanCoreFiles(context.Background(), donorDir)
	if err != nil {
		t.Fatalf("scanCoreFiles failed: %v", err)
	}
	if _, exists := files["symlink_test"]; exists {
		t.Fatalf("expected symlink to be skipped in scanCoreFiles")
	}

	canceledCtx, cancel := context.WithCancel(context.Background())
	cancel()
	_, cancelErr := scanCoreFiles(canceledCtx, donorDir)
	if cancelErr == nil {
		t.Fatalf("expected cancel error from scanCoreFiles")
	}

	_, missingErr := scanCoreFiles(context.Background(), filepath.Join(t.TempDir(), "nonexistent"))
	if missingErr == nil {
		t.Fatalf("expected error on missing donorDir in scanCoreFiles")
	}

	unreadableDir := t.TempDir()
	badFile := filepath.Join(unreadableDir, "unreadable.php")
	if err := os.WriteFile(badFile, []byte("data"), 0o000); err != nil {
		t.Fatalf("failed writing unreadable file: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(badFile); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})
	if _, err := scanCoreFiles(context.Background(), unreadableDir); err == nil {
		t.Fatalf("expected error from unreadable file in scanCoreFiles")
	}

	blockedDir := t.TempDir()
	nestedBlocked := filepath.Join(blockedDir, "blocked_sub")
	if err := os.Mkdir(nestedBlocked, 0o000); err != nil {
		t.Fatalf("failed creating blocked sub: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(nestedBlocked); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})
	if _, err := scanCoreFiles(context.Background(), blockedDir); err == nil {
		t.Fatalf("expected error from blocked sub in scanCoreFiles")
	}
}

func TestScanMuPluginsErrors(t *testing.T) {
	t.Parallel()

	tempDir := t.TempDir()
	forbiddenParent := filepath.Join(tempDir, "forbidden_parent")
	if err := os.Mkdir(forbiddenParent, 0o000); err != nil {
		t.Fatalf("failed creating forbidden parent: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(forbiddenParent); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	inaccessibleMu := filepath.Join(forbiddenParent, "mu-plugins")
	if _, err := scanMuPlugins(inaccessibleMu); err == nil {
		t.Fatalf("expected stat error on inaccessible mu-plugins")
	}

	unreadableDir := filepath.Join(tempDir, "unreadable_mu")
	if err := os.Mkdir(unreadableDir, 0o000); err != nil {
		t.Fatalf("failed creating unreadable dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadableDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	_, err := scanMuPlugins(unreadableDir)
	if err == nil {
		t.Fatalf("expected error reading unreadable muDir")
	}

	validMuDir := t.TempDir()
	unreadablePhp := filepath.Join(validMuDir, "wp-broken.php")
	if err := os.WriteFile(unreadablePhp, []byte("<?php"), 0o000); err != nil {
		t.Fatalf("failed creating unreadable php file: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(unreadablePhp); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	_, hashErr := scanMuPlugins(validMuDir)
	if hashErr == nil {
		t.Fatalf("expected error hashing unreadable php file in scanMuPlugins")
	}
}

func TestSaveMoreErrors(t *testing.T) {
	t.Parallel()

	m := &Manifest{WordPressVersion: "7.1.4"}
	tempDir := t.TempDir()

	readOnlyDir := filepath.Join(tempDir, "readonly")
	if err := os.Mkdir(readOnlyDir, 0o500); err != nil {
		t.Fatalf("failed creating readonly dir: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Remove(readOnlyDir); err != nil && !os.IsNotExist(err) {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	if err := Save(filepath.Join(readOnlyDir, "manifest.json"), m); err == nil {
		t.Fatalf("expected error writing in readonly directory")
	}

	existingDir := filepath.Join(tempDir, "existing_target_dir")
	if err := os.Mkdir(existingDir, 0o750); err != nil {
		t.Fatalf("failed creating existing target dir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(existingDir, "keep.txt"), []byte("data"), 0o600); err != nil {
		t.Fatalf("failed creating keep.txt: %v", err)
	}

	if err := Save(existingDir, m); err == nil {
		t.Fatalf("expected error when targetPath is an existing directory")
	}
}

func mockFailingMarshalIndent(_ any, _, _ string) ([]byte, error) {
	return nil, errors.New("simulated marshal error")
}

func TestSaveMarshalError(t *testing.T) {
	orig := marshalIndentFn
	defer func() { marshalIndentFn = orig }()
	marshalIndentFn = mockFailingMarshalIndent

	m := &Manifest{WordPressVersion: "7.1.4"}
	if err := Save(filepath.Join(t.TempDir(), "manifest.json"), m); err == nil {
		t.Fatalf("expected error from failing marshal")
	}
}

func mockFailingRemove(_ string) error {
	return errors.New("simulated remove error")
}

func TestSaveRenameAndRemoveError(t *testing.T) {
	orig := removeFn
	defer func() { removeFn = orig }()
	removeFn = mockFailingRemove

	existingDir := filepath.Join(t.TempDir(), "occupied_dir")
	if err := os.Mkdir(existingDir, 0o750); err != nil {
		t.Fatalf("failed creating existing target dir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(existingDir, "keep.txt"), []byte("data"), 0o600); err != nil {
		t.Fatalf("failed creating keep.txt: %v", err)
	}

	m := &Manifest{WordPressVersion: "7.1.5"}
	if err := Save(existingDir, m); err == nil {
		t.Fatalf("expected error when rename fails and removeFn fails")
	}
}

func TestScanMuPluginsStatError(t *testing.T) {
	tempDir := t.TempDir()
	parent := filepath.Join(tempDir, "no_exec_parent")
	if err := os.Mkdir(parent, 0o000); err != nil {
		t.Fatalf("failed creating no_exec_parent: %v", err)
	}
	t.Cleanup(func() {
		if err := os.Chmod(parent, 0o600); err != nil {
			t.Logf("cleanup chmod error: %v", err)
		}
		if err := os.Remove(parent); err != nil {
			t.Logf("cleanup remove error: %v", err)
		}
	})

	child := filepath.Join(parent, "mu")
	if _, err := scanMuPlugins(child); err == nil {
		t.Fatalf("expected error stating child in unsearchable directory")
	}
}

func TestDefaultRemoveError(t *testing.T) {
	if err := defaultRemove(filepath.Join(t.TempDir(), "nonexistent")); err == nil {
		t.Fatalf("expected error from defaultRemove on nonexistent file")
	}
}

type mockFailingCloseReader struct {
	io.Reader
}

func (m *mockFailingCloseReader) Close() error {
	return errors.New("simulated close error")
}

func mockOpenFileFailingClose(_ string) (fileReader, error) {
	return &mockFailingCloseReader{Reader: strings.NewReader("sample data")}, nil
}

type mockFailingReadReader struct{}

func (m *mockFailingReadReader) Read(_ []byte) (int, error) {
	return 0, errors.New("simulated read error")
}

func (m *mockFailingReadReader) Close() error {
	return nil
}

func mockOpenFileFailingRead(_ string) (fileReader, error) {
	return &mockFailingReadReader{}, nil
}

func mockOpenFileFailingOpen(_ string) (fileReader, error) {
	return nil, errors.New("simulated open error")
}

func TestComputeFileHashMockErrors(t *testing.T) {
	tempFile := filepath.Join(t.TempDir(), "target.txt")
	if err := os.WriteFile(tempFile, []byte("content"), 0o600); err != nil {
		t.Fatalf("failed creating target file: %v", err)
	}

	origOpen := openFileFn
	defer func() { openFileFn = origOpen }()

	openFileFn = mockOpenFileFailingOpen
	if _, err := ComputeFileHash(tempFile); err == nil {
		t.Fatalf("expected error when openFileFn fails")
	}

	openFileFn = mockOpenFileFailingRead
	if _, err := ComputeFileHash(tempFile); err == nil {
		t.Fatalf("expected error when io.Copy fails")
	}

	openFileFn = mockOpenFileFailingClose
	if _, err := ComputeFileHash(tempFile); err == nil {
		t.Fatalf("expected error when file.Close fails")
	}
}

func TestDefaultOpenFile(t *testing.T) {
	tempFile := filepath.Join(t.TempDir(), "target.txt")
	if err := os.WriteFile(tempFile, []byte("data"), 0o600); err != nil {
		t.Fatalf("failed writing temp file: %v", err)
	}

	f, err := defaultOpenFile(tempFile)
	if err != nil {
		t.Fatalf("expected defaultOpenFile to succeed: %v", err)
	}
	if closeErr := f.Close(); closeErr != nil {
		t.Fatalf("expected f.Close to succeed: %v", closeErr)
	}

	if _, err := defaultOpenFile(filepath.Join(t.TempDir(), "missing.txt")); err == nil {
		t.Fatalf("expected error from defaultOpenFile on missing file")
	}
}
