// Package main provides the wp-guard command-line utility for WordPress core synchronization and security.
package main

import (
	"bufio"
	"context"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"syscall"

	"github.com/webstudiobond/wp-guard/internal/manifest"
	"github.com/webstudiobond/wp-guard/internal/superadmin"
	"github.com/webstudiobond/wp-guard/internal/syncer"
)

func printMsg(w io.Writer, format string, a ...any) {
	if _, err := fmt.Fprintf(w, format, a...); err != nil {
		return
	}
}

func printLine(w io.Writer, a ...any) {
	if _, err := fmt.Fprintln(w, a...); err != nil {
		return
	}
}

const (
	defaultDonorDir      = "/usr/src/wordpress"
	defaultMuDir         = "/usr/local/share/wordpress/mu-plugins"
	defaultSbsLoaderPath = "/usr/local/share/wordpress/sbs-loader.php"
	defaultManifestPath  = "/usr/local/share/wordpress/core-manifest.json"
)

var (
	manifestDonorDir      = defaultDonorDir
	manifestMuDir         = defaultMuDir
	manifestSbsLoaderPath = defaultSbsLoaderPath
	manifestOutputPath    = defaultManifestPath
)

func getEnvOrDefault(envKey, defaultVal string) string {
	if val, ok := os.LookupEnv(envKey); ok && val != "" {
		return val
	}
	return defaultVal
}

func runSyncCommand(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	fs := flag.NewFlagSet("sync", flag.ContinueOnError)
	fs.SetOutput(stderr)

	sourceDir := fs.String("source", getEnvOrDefault("WP_DONOR_DIR", defaultDonorDir), "Donor WordPress source directory")
	targetDir := fs.String("target", getEnvOrDefault("WP_WEB_DIR", "/var/www/html"), "Target WordPress web directory")
	manifestPath := fs.String("manifest", getEnvOrDefault("WP_MANIFEST_PATH", defaultManifestPath), "Path to core integrity manifest")
	contentDir := fs.String("content-dir", getEnvOrDefault("WP_CONTENT_DIR", getEnvOrDefault("WP_CONTENT_FOLDERNAME", "wp-content")), "Custom content directory name")

	if err := fs.Parse(args); err != nil {
		return 2
	}

	cfg := &syncer.Config{
		Output:         stdout,
		SourceDir:      *sourceDir,
		TargetDir:      *targetDir,
		ManifestPath:   *manifestPath,
		ContentDirName: *contentDir,
	}

	if _, err := syncer.Run(ctx, cfg); err != nil {
		printMsg(stderr, "[wp-guard] %v\n", err)
		return 1
	}

	return 0
}

func runManifestCommand(ctx context.Context, args []string, stdout, stderr io.Writer) int {
	subArgs := args
	if len(args) > 0 && args[0] == "generate" {
		subArgs = args[1:]
	}
	if len(subArgs) > 0 {
		printMsg(stderr, "[wp-guard] manifest generate takes no options\n")
		return 2
	}

	printMsg(stdout, "[wp-guard] Generating manifest from %s...\n", manifestDonorDir)
	m, err := manifest.Generate(ctx, manifestDonorDir, manifestMuDir, manifestSbsLoaderPath)
	if err != nil {
		printMsg(stderr, "[wp-guard] %v\n", err)
		return 1
	}

	if err := manifest.Save(manifestOutputPath, m); err != nil {
		printMsg(stderr, "[wp-guard] %v\n", err)
		return 1
	}

	printMsg(stdout, "[wp-guard] Manifest successfully generated with %d core files to %s\n", len(m.CoreFiles), manifestOutputPath)
	return 0
}

func runSuperadminCommand(ctx context.Context, args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	subArgs := args
	if len(args) > 0 && args[0] == "hash" {
		subArgs = args[1:]
	}

	fs := flag.NewFlagSet("superadmin", flag.ContinueOnError)
	fs.SetOutput(stderr)

	saltFile := fs.String("salt-file", "/run/secrets/auth_salt", "Path to authentication salt secret file")

	if err := fs.Parse(subArgs); err != nil {
		return 2
	}

	reader := bufio.NewReader(stdin)

	printMsg(stderr, "Enter superadmin User ID: ")
	idStr, err := reader.ReadString('\n')
	if err != nil && idStr == "" {
		printMsg(stderr, "[wp-guard] failed reading user ID: %v\n", err)
		return 1
	}

	printMsg(stderr, "Enter superadmin User Login: ")
	loginStr, err := reader.ReadString('\n')
	if err != nil && loginStr == "" {
		printMsg(stderr, "[wp-guard] failed reading user login: %v\n", err)
		return 1
	}

	activeSalt, err := superadmin.ReadSaltFromFile(ctx, *saltFile)
	if err != nil {
		printMsg(stderr, "[wp-guard] %v\n", err)
		return 1
	}

	hash, err := superadmin.ComputeHash(idStr, loginStr, activeSalt)
	if err != nil {
		printMsg(stderr, "[wp-guard] %v\n", err)
		return 1
	}

	printLine(stdout, hash)
	return 0
}

// RunCLI executes command-line parsing and runs the requested subcommand with isolated I/O streams.
func RunCLI(ctx context.Context, args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	if len(args) == 0 {
		return runSyncCommand(ctx, nil, stdout, stderr)
	}

	switch args[0] {
	case "sync":
		return runSyncCommand(ctx, args[1:], stdout, stderr)
	case "manifest":
		return runManifestCommand(ctx, args[1:], stdout, stderr)
	case "superadmin":
		return runSuperadminCommand(ctx, args[1:], stdin, stdout, stderr)
	case "help", "-h", "--help":
		printUsage(stdout)
		return 0
	default:
		printMsg(stderr, "[wp-guard] unknown command: %s\n", args[0])
		printUsage(stderr)
		return 1
	}
}

func printUsage(out io.Writer) {
	printMsg(out, `Usage: wp-guard <command> [options]

Commands:
  sync                  Synchronize and verify WordPress core files (default)
  manifest generate     Generate core integrity manifest JSON
  superadmin            Generate HMAC-SHA512 hash for Docker Secrets

Options for sync:
  --source <dir>        Donor WordPress directory (default: /usr/src/wordpress)
  --target <dir>        Live WordPress directory (default: /var/www/html)
  --manifest <path>     Manifest JSON path (default: /usr/local/share/wordpress/core-manifest.json)
  --content-dir <name>  Custom content directory name (default: wp-content)

Options for superadmin:
  --salt-file <path>    Salt secret path (default: /run/secrets/auth_salt)
`)
}

func runMain() int {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()

	return RunCLI(ctx, os.Args[1:], os.Stdin, os.Stdout, os.Stderr)
}

var osExit = os.Exit

func main() {
	osExit(runMain())
}
