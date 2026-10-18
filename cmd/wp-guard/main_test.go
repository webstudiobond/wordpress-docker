package main

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/webstudiobond/wp-guard/internal/manifest"
)

var (
	testExited   bool
	testExitCode int
)

func mockExit(code int) {
	testExited = true
	testExitCode = code
}

type failWriter struct{}

func (failWriter) Write([]byte) (int, error) {
	return 0, errors.New("write failure")
}

func createTestDonorDir(t *testing.T, version string) string {
	t.Helper()
	dir := t.TempDir()

	incDir := filepath.Join(dir, "wp-includes")
	if err := os.MkdirAll(incDir, 0o750); err != nil {
		t.Fatalf("failed creating wp-includes: %v", err)
	}
	versionContent := "<?php\n$wp_version = '" + version + "';\n"
	if err := os.WriteFile(filepath.Join(incDir, "version.php"), []byte(versionContent), 0o600); err != nil {
		t.Fatalf("failed creating version.php: %v", err)
	}

	if err := os.WriteFile(filepath.Join(dir, "index.php"), []byte("<?php // index"), 0o600); err != nil {
		t.Fatalf("failed creating index.php: %v", err)
	}

	return dir
}

func TestRunCLI_Help(t *testing.T) {
	ctx := context.Background()
	var stdout, stderr bytes.Buffer

	code := RunCLI(ctx, []string{"help"}, nil, &stdout, &stderr)
	if code != 0 {
		t.Fatalf("expected exit code 0, got %d", code)
	}
	if !strings.Contains(stdout.String(), "Usage: wp-guard") {
		t.Fatalf("expected usage message in stdout")
	}
}

func TestRunCLI_UnknownCommand(t *testing.T) {
	ctx := context.Background()
	var stdout, stderr bytes.Buffer

	code := RunCLI(ctx, []string{"unknown-cmd"}, nil, &stdout, &stderr)
	if code != 1 {
		t.Fatalf("expected exit code 1, got %d", code)
	}
	if !strings.Contains(stderr.String(), "unknown command") {
		t.Fatalf("expected unknown command message in stderr")
	}
}

func TestRunCLI_Sync(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonorDir(t, "6.6.2")
	targetDir := t.TempDir()

	muDir := filepath.Join(targetDir, "wp-content", "mu-plugins")
	if err := os.MkdirAll(muDir, 0o750); err != nil {
		t.Fatalf("failed creating muDir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(muDir, "sbs-loader.php"), []byte("<?php // sbs-loader"), 0o600); err != nil {
		t.Fatalf("failed creating test loader: %v", err)
	}

	tests := []struct {
		name     string
		source   string
		target   string
		extra    []string
		wantCode int
	}{
		{
			name:     "clean sync",
			source:   donorDir,
			target:   targetDir,
			wantCode: 0,
		},
		{
			name:     "invalid flag",
			extra:    []string{"--invalid-flag"},
			wantCode: 2,
		},
		{
			name:     "missing source",
			source:   "/non/existent/path",
			wantCode: 1,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			var stdout, stderr bytes.Buffer
			args := []string{"sync"}
			if tt.source != "" {
				args = append(args, "--source", tt.source)
			}
			if tt.target != "" {
				args = append(args, "--target", tt.target)
			}
			args = append(args, tt.extra...)

			code := RunCLI(ctx, args, nil, &stdout, &stderr)
			if code != tt.wantCode {
				t.Fatalf("expected exit code %d, got %d. stderr: %s", tt.wantCode, code, stderr.String())
			}
			if tt.wantCode == 0 && !strings.Contains(stdout.String(), "Clean installation complete") {
				t.Fatalf("expected clean installation output, got: %s", stdout.String())
			}
		})
	}
}

func TestRunCLI_DefaultSync(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonorDir(t, "6.6.3")
	targetDir := t.TempDir()

	muDir := filepath.Join(targetDir, "wp-content", "mu-plugins")
	if err := os.MkdirAll(muDir, 0o750); err != nil {
		t.Fatalf("failed creating muDir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(muDir, "sbs-loader.php"), []byte("<?php // sbs-loader"), 0o600); err != nil {
		t.Fatalf("failed creating test loader: %v", err)
	}

	t.Setenv("WP_DONOR_DIR", donorDir)
	t.Setenv("WP_WEB_DIR", targetDir)

	var stdout, stderr bytes.Buffer
	code := RunCLI(ctx, nil, nil, &stdout, &stderr)
	if code != 0 {
		t.Fatalf("expected exit code 0, got %d. stderr: %s", code, stderr.String())
	}

	if !strings.Contains(stdout.String(), "Clean installation complete") {
		t.Fatalf("expected clean installation output, got: %s", stdout.String())
	}
}

func TestRunCLI_Manifest(t *testing.T) {
	ctx := context.Background()
	donorDir := createTestDonorDir(t, "6.6.4")
	outPath := filepath.Join(t.TempDir(), "test-manifest.json")
	loaderPath := filepath.Join(t.TempDir(), "sbs-loader.php")
	if err := os.WriteFile(loaderPath, []byte("<?php // test loader"), 0o600); err != nil {
		t.Fatalf("failed creating test loader: %v", err)
	}
	loaderOutPath := filepath.Join(t.TempDir(), "test-loader-manifest.json")
	noSubcommandPath := filepath.Join(t.TempDir(), "test-no-subcommand-manifest.json")

	tests := []struct {
		name           string
		donor          string
		mu             string
		loader         string
		output         string
		extraArgs      []string
		wantCode       int
		omitSubcommand bool
		wantLoader     bool
	}{
		{
			name:       "valid generate without sbs loader",
			donor:      donorDir,
			loader:     "",
			output:     outPath,
			wantCode:   0,
			wantLoader: false,
		},
		{
			name:       "valid generate with sbs loader",
			donor:      donorDir,
			loader:     loaderPath,
			output:     loaderOutPath,
			wantCode:   0,
			wantLoader: true,
		},
		{
			name:           "valid generate without subcommand",
			donor:          donorDir,
			loader:         loaderPath,
			output:         noSubcommandPath,
			omitSubcommand: true,
			wantCode:       0,
			wantLoader:     true,
		},
		{
			name:      "extra options return error",
			donor:     donorDir,
			output:    outPath,
			extraArgs: []string{"--extra-flag"},
			wantCode:  2,
		},
		{
			name:     "missing donor returns error",
			donor:    "/non/existent/donor",
			output:   outPath,
			wantCode: 1,
		},
		{
			name:     "invalid output path returns error",
			donor:    donorDir,
			output:   "/dev/null/manifest.json",
			wantCode: 1,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			oldDonor := manifestDonorDir
			oldMu := manifestMuDir
			oldLoader := manifestSbsLoaderPath
			oldOutput := manifestOutputPath
			t.Cleanup(func() {
				manifestDonorDir = oldDonor
				manifestMuDir = oldMu
				manifestSbsLoaderPath = oldLoader
				manifestOutputPath = oldOutput
			})

			manifestDonorDir = tt.donor
			manifestMuDir = tt.mu
			manifestSbsLoaderPath = tt.loader
			manifestOutputPath = tt.output

			var stdout, stderr bytes.Buffer
			cmdArgs := []string{"manifest"}
			if !tt.omitSubcommand {
				cmdArgs = append(cmdArgs, "generate")
			}
			cmdArgs = append(cmdArgs, tt.extraArgs...)

			code := RunCLI(ctx, cmdArgs, nil, &stdout, &stderr)
			if code != tt.wantCode {
				t.Fatalf("expected exit code %d, got %d. stderr: %s", tt.wantCode, code, stderr.String())
			}
			if tt.wantCode == 0 {
				m, err := manifest.Load(tt.output)
				if err != nil {
					t.Fatalf("expected valid manifest at %s: %v", tt.output, err)
				}
				if tt.wantLoader && m.SbsLoaderHash == "" {
					t.Fatalf("expected non-empty SbsLoaderHash in manifest")
				}
				if !tt.wantLoader && m.SbsLoaderHash != "" {
					t.Fatalf("expected empty SbsLoaderHash in manifest")
				}
			}
		})
	}
}

func mockComputeSuperadminHashError(_, _, _ string) (string, error) {
	return "", errors.New("simulated hash computation error")
}

func TestRunCLI_Superadmin(t *testing.T) {
	ctx := context.Background()
	saltFile := filepath.Join(t.TempDir(), "auth_salt.txt")
	if err := os.WriteFile(saltFile, []byte("secret_salt\n"), 0o600); err != nil {
		t.Fatalf("failed creating test salt file: %v", err)
	}
	emptySaltFile := filepath.Join(t.TempDir(), "empty_salt.txt")
	if err := os.WriteFile(emptySaltFile, []byte("   \n"), 0o600); err != nil {
		t.Fatalf("failed creating empty test salt file: %v", err)
	}

	tests := []struct {
		name         string
		saltPath     string
		input        string
		wantHash     string
		extraArgs    []string
		wantCode     int
		mockHashFail bool
	}{
		{
			name:     "valid interactive input with salt file",
			saltPath: saltFile,
			input:    "1\nadmin\n",
			wantHash: "149f4870f5abf72f266229505f4cc293b1a436d94ade38ebb957195b7bceb58a9029943e6d14bcbd0d5a9c79930a30bec1e4667da4c5e2c3b0cd50e46f8fcd45",
			wantCode: 0,
		},
		{
			name:      "valid interactive with hash subcommand",
			extraArgs: []string{"hash"},
			saltPath:  saltFile,
			input:     "10\nadmin\n",
			wantHash:  "67e1a6a54773e01537fc6195cee1afe33b4291fff72701f7d23216f00728c80d459816011ce3d1ae0b8c23a95a24fcf84f136c6ef5b80a8cd0d4aa4a9de30b2a",
			wantCode:  0,
		},
		{
			name:     "valid interactive with crlf line endings",
			saltPath: saltFile,
			input:    "2\r\nadmin\r\n",
			wantHash: "dd595bcff6a7cc6461acc2ea0fa2a9c06fbd01f16400ee7c7133cf012120b00a18e7fea99d4250562f837d7169eee27e51156149e5984fb07881d2455736bfce",
			wantCode: 0,
		},
		{
			name:     "valid interactive with cr line endings and email login",
			saltPath: saltFile,
			input:    "5\ruser@example.com\r",
			wantHash: "d166e6aec55dc1cfd224f928be759e9ec0945761c2e2d15fb707c9ae30b4c9841801e2d132844ad68ba2a7defa26080ab82f2d6347fb808e2ef6c04235f65cac",
			wantCode: 0,
		},
		{
			name:     "valid interactive with retries on empty and invalid inputs",
			saltPath: saltFile,
			input:    "\n0\n4\n\nadmin\n",
			wantHash: "e5335fd37a119a4e78e463bbdbbabdd22cc4afe0269b14ab01de767ec81082d88e4ef7f15a64b13fec9253a8081ceebd51e715153a4e9f2022e8eb86d941948f",
			wantCode: 0,
		},
		{
			name:      "invalid flag arguments",
			extraArgs: []string{"--unknown-flag"},
			wantCode:  2,
		},
		{
			name:     "empty user ID input error",
			saltPath: saltFile,
			wantCode: 1,
		},
		{
			name:     "empty user login input error",
			saltPath: saltFile,
			input:    "1\n",
			wantCode: 1,
		},
		{
			name:     "missing salt file error",
			saltPath: "/non/existent/auth_salt",
			input:    "8\nadmin\n",
			wantCode: 1,
		},
		{
			name:     "empty salt file error",
			saltPath: emptySaltFile,
			input:    "7\nadmin\n",
			wantCode: 1,
		},
		{
			name:         "compute hash validation error",
			saltPath:     saltFile,
			input:        "9\nadmin\n",
			mockHashFail: true,
			wantCode:     1,
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			if tc.mockHashFail {
				oldCompute := computeSuperadminHash
				computeSuperadminHash = mockComputeSuperadminHashError
				t.Cleanup(func() {
					computeSuperadminHash = oldCompute
				})
			}

			var stdout, stderr bytes.Buffer
			stdin := strings.NewReader(tc.input)
			cmdArgs := append([]string{"superadmin"}, tc.extraArgs...)
			if tc.saltPath != "" {
				cmdArgs = append(cmdArgs, "--salt-file", tc.saltPath)
			}

			code := RunCLI(ctx, cmdArgs, stdin, &stdout, &stderr)
			if code != tc.wantCode {
				t.Fatalf("expected exit code %d, got %d (stderr: %s)", tc.wantCode, code, stderr.String())
			}
			if tc.wantHash != "" {
				got := strings.TrimSpace(stdout.String())
				if got != tc.wantHash {
					t.Fatalf("expected hash %s, got %s", tc.wantHash, got)
				}
			}
			if tc.wantCode == 0 && !strings.Contains(stderr.String(), "[wp-guard] Superadmin hash successfully generated.") {
				t.Fatalf("expected success confirmation in stderr, got: %s", stderr.String())
			}
		})
	}
}

type errorReader struct{}

func (errorReader) Read(_ []byte) (int, error) {
	return 0, errors.New("read error")
}

func TestReadLine(t *testing.T) {
	tests := []struct {
		name    string
		reader  io.Reader
		want    string
		wantErr bool
	}{
		{
			name:    "empty reader returns EOF",
			reader:  strings.NewReader(""),
			want:    "",
			wantErr: true,
		},
		{
			name:    "trailing bytes without newline at EOF",
			reader:  strings.NewReader("trailing"),
			want:    "trailing",
			wantErr: false,
		},
		{
			name:    "standard line feed",
			reader:  strings.NewReader("simple\n"),
			want:    "simple",
			wantErr: false,
		},
		{
			name:    "windows crlf line ending",
			reader:  strings.NewReader("windows\r\n"),
			want:    "windows",
			wantErr: false,
		},
		{
			name:    "terminal carriage return line ending",
			reader:  strings.NewReader("carriage\r"),
			want:    "carriage",
			wantErr: false,
		},
		{
			name:    "carriage return followed by non-newline byte",
			reader:  strings.NewReader("carriage\rnext\n"),
			want:    "carriage",
			wantErr: false,
		},
		{
			name:    "reader error propagates",
			reader:  errorReader{},
			want:    "",
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			scanner := bufio.NewScanner(tt.reader)
			scanner.Split(scanLineOrCR)
			got, err := readLine(scanner)
			if (err != nil) != tt.wantErr {
				t.Fatalf("readLine() error = %v, wantErr %v", err, tt.wantErr)
			}
			if got != tt.want {
				t.Fatalf("readLine() got = %q, want %q", got, tt.want)
			}
		})
	}
}

func TestScanLineOrCR_DirectBranches(t *testing.T) {
	tests := []struct {
		name        string
		wantToken   string
		data        []byte
		wantAdvance int
		atEOF       bool
		wantErr     bool
	}{
		{
			name:        "empty at eof",
			data:        []byte(""),
			atEOF:       true,
			wantAdvance: 0,
			wantToken:   "",
		},
		{
			name:        "cr at end not at eof asks for more",
			data:        []byte("abc\r"),
			atEOF:       false,
			wantAdvance: 0,
			wantToken:   "",
		},
		{
			name:        "no newline not at eof asks for more",
			data:        []byte("abc"),
			atEOF:       false,
			wantAdvance: 0,
			wantToken:   "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			adv, tok, err := scanLineOrCR(tt.data, tt.atEOF)
			if (err != nil) != tt.wantErr {
				t.Fatalf("scanLineOrCR error = %v, wantErr %v", err, tt.wantErr)
			}
			if adv != tt.wantAdvance {
				t.Fatalf("advance = %d, want %d", adv, tt.wantAdvance)
			}
			if string(tok) != tt.wantToken {
				t.Fatalf("token = %q, want %q", string(tok), tt.wantToken)
			}
		})
	}
}

func TestDefaultComputeSuperadminHash_Error(t *testing.T) {
	if _, err := defaultComputeSuperadminHash("", "", ""); err == nil {
		t.Fatalf("expected error from defaultComputeSuperadminHash with empty inputs")
	}
}

func TestPrintHelpers_ErrorPaths(_ *testing.T) {
	w := failWriter{}
	printMsg(w, "should not panic %s", "arg")
	printLine(w, "should not panic")
}

func TestGetEnvOrDefault(t *testing.T) {
	t.Setenv("TEST_WP_VAR", "custom_val")
	if got := getEnvOrDefault("TEST_WP_VAR", "default_val"); got != "custom_val" {
		t.Fatalf("expected custom_val, got %s", got)
	}
	if got := getEnvOrDefault("NON_EXISTENT_VAR_123", "default_val"); got != "default_val" {
		t.Fatalf("expected default_val, got %s", got)
	}
}

func TestRunMainAndMain(t *testing.T) {
	oldArgs := os.Args
	oldExit := osExit
	defer func() {
		os.Args = oldArgs
		osExit = oldExit
	}()

	os.Args = []string{"wp-guard", "help"}
	code := runMain()
	if code != 0 {
		t.Fatalf("runMain expected 0, got %d", code)
	}

	testExited = false
	testExitCode = -1
	osExit = mockExit

	main()
	if !testExited || testExitCode != 0 {
		t.Fatalf("main expected exit with 0, got called=%v code=%d", testExited, testExitCode)
	}
}
