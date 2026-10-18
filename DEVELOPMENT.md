# Hardened WordPress Docker Development Guide

This guide covers local development, code validation, testing standards, build workflows, and architecture for the hardened WordPress container stack and its companion components.

---

## 1. Prerequisites

Ensure the following runtimes and tools are installed prior to local development and validation:

### Core Runtimes & Infrastructure
- **Go 1.27+** (defined in `go.mod`)
- **PHP 8.5+** (with `mbstring`, `dom`, `xml` extensions; `pcov` for code coverage)
- **Composer 2+**
- **Docker & Docker Compose v2**
- **GNU Make**
- **Git**

### Code Quality & Security Tools
- **Go:** `golangci-lint`, `govulncheck`
- **PHP:** `phpstan`, `phpcs`, `phpunit` (managed via `composer install`)
- **Containers & Security:** `hadolint`, `trivy`, `gitleaks`

---

## 2. Repository Initialization

1. **Clone the repository:**
   ```bash
   git clone git@github.com:webstudiobond/wordpress-docker.git
   cd wordpress-docker
   ```

2. **Install PHP development dependencies:**
   ```bash
   composer install --prefer-dist --no-interaction
   ```

3. **Verify tooling availability:**
   ```bash
   go version
   php -v
   composer --version
   golangci-lint --version
   phpstan --version
   phpcs --version
   ./vendor/bin/phpunit --version
   hadolint --version
   trivy --version
   gitleaks version
   ```

---

## 3. Code Validation & Testing

The project enforces strict code quality and security standards across all Go source files, PHP components, container manifests, and Dockerfiles. All validation workflows are centralized in the `Makefile`.

### 3.1 Go Validation (`wp-guard`)

1. **Formatting, Linting & Compilation Verification:**
   Validates Go code formatting via `gofmt -s`, executes all linters configured in `.golangci.yml` (including `wrapcheck`, `gosec`, `govet`, `revive`), and verifies package compilation:
   ```bash
   make go-check
   ```

2. **Unit Tests (with Race Detector):**
   Executes all unit tests with data race detection enabled (`-race`):
   ```bash
   make go-test
   ```

3. **Code Coverage:**
   Executes unit tests and displays per-function coverage statistics (target: **100.0%**):
   ```bash
   make go-coverage
   ```

4. **Vulnerability Scanning:**
   Analyzes Go source code and dependencies for known CVEs using `govulncheck`:
   ```bash
   make go-vulncheck
   ```

5. **Sequential Go Quality Gate:**
   Executes all Go validation checks (`go-check`, `go-test`, `go-vulncheck`):
   ```bash
   make go-verify
   ```

---

### 3.2 PHP Validation (`sbs-loader` & Platform Plugins)

1. **Syntax Linting:**
   Lints all PHP source and example files with `php -l`:
   ```bash
   make php-lint
   ```

2. **Coding Standards (PSR-12):**
   Analyzes PHP code against strict PSR-12 standards without rule exclusions:
   ```bash
   make phpcs
   ```
   To automatically fix styling violations:
   ```bash
   make phpcs-fix
   ```

3. **Static Analysis (PHPStan Level 9):**
   Performs strict static analysis with maximum type safety:
   ```bash
   make phpstan
   ```

4. **Unit Tests (PHPUnit):**
   Executes the PHPUnit test suite:
   ```bash
   make php-test
   ```
   To generate text coverage statistics:
   ```bash
   make php-coverage
   ```

5. **Sequential PHP Quality Gate:**
   Executes all PHP validation checks (`php-lint`, `phpcs`, `phpstan`, `php-test`):
   ```bash
   make php-verify
   ```

---

### 3.3 Container & Security Validation

1. **Dockerfile Linting:**
   Audits `Dockerfile` for best practices and security rules using `hadolint`:
   ```bash
   make docker-lint
   ```

2. **Compose Configuration Validation:**
   Statically validates `docker-compose.yaml` and `docker-compose.dev.yaml` with mock secrets:
   ```bash
   make compose-validate
   ```

3. **Filesystem Security Scanning:**
   Scans repository filesystem for `CRITICAL` and `HIGH` severity vulnerabilities using `trivy`:
   ```bash
   make security-trivy
   ```

4. **Secret Leak Detection:**
   Audits the repository for committed keys, tokens, or credentials using `gitleaks`:
   ```bash
   make secrets-scan
   ```

5. **Comprehensive Project Verification:**
   Executes the complete CI quality gate (`docker-lint`, `compose-validate`, `php-verify`, `go-verify`, `security-trivy`, `secrets-scan`). **Run this command prior to opening a pull request or releasing:**
   ```bash
   make verify
   ```

6. **Clean Artifacts:**
   Removes test caches, coverage profiles, and compiled binaries:
   ```bash
   make clean
   ```

---

## 4. Build Workflows

### 4.1 Local Binary Compilation (`wp-guard`)

To build the standalone `wp-guard` binary locally:
```bash
make go-build
```
This produces `bin/wp-guard` with stripped symbol tables and DWARF debug info (`-ldflags="-s -w" -trimpath`).

### 4.2 Cross-Compilation

To cross-compile `wp-guard` for other target architectures:
```bash
# Linux AMD64:
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -ldflags="-s -w" -trimpath -o bin/wp-guard-amd64 ./cmd/wp-guard

# Linux ARM64:
CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -ldflags="-s -w" -trimpath -o bin/wp-guard-arm64 ./cmd/wp-guard
```

### 4.3 Container Image Building

1. **Build the production/development FPM image:**
   ```bash
   docker compose -f docker-compose.dev.yaml build
   ```

2. **Build the WP-CLI utility image:**
   ```bash
   docker compose -f docker-compose.dev.yaml --profile tools build wp-cli
   ```

---

## 5. Makefile Targets Reference

| Target | Description |
| :--- | :--- |
| `make go-check` | Validates Go formatting with `gofmt -s`, runs `golangci-lint`, and verifies compilation. |
| `make go-test` | Executes Go unit tests with race detection enabled (`-race`). |
| `make go-coverage` | Generates a Go code coverage profile and displays per-function statistics. |
| `make go-vulncheck` | Analyzes Go source code and dependencies for known CVEs using `govulncheck`. |
| `make go-verify` | Sequentially executes `go-check`, `go-test`, and `go-vulncheck`. |
| `make go-build` | Compiles the standalone `wp-guard` binary to `bin/wp-guard`. |
| `make composer-install` | Installs project dependencies via Composer. |
| `make composer-update` | Updates dependencies to latest allowable versions. |
| `make composer-outdated` | Checks for newer releases of direct dependencies (`composer.json`). |
| `make composer-audit` | Audits installed Composer packages for security vulnerabilities. |
| `make php-lint` | Verifies syntax across all `.php` and `.php.example` files using `php -l`. |
| `make phpcs` | Analyzes PHP code against strict PSR-12 rules via PHP_CodeSniffer. |
| `make phpcs-fix` | Automatically formats PHP code in-place using `phpcbf`. |
| `make phpstan` | Performs static analysis at Level 9 using PHPStan. |
| `make php-test` | Executes PHPUnit unit tests without coverage overhead. |
| `make php-coverage` | Runs PHPUnit and outputs text coverage statistics. |
| `make php-verify` | Sequentially executes `php-lint`, `phpcs`, `phpstan`, and `php-test`. |
| `make docker-lint` | Lints `Dockerfile` for security and best practices via `hadolint`. |
| `make compose-validate` | Statically validates `docker-compose.yaml` and `docker-compose.dev.yaml` configurations. |
| `make security-trivy` | Scans repository filesystem for `CRITICAL` and `HIGH` severity vulnerabilities using `trivy`. |
| `make secrets-scan` | Scans repository for committed secrets and credentials using `gitleaks`. |
| `make verify` | Full quality gate executing `docker-lint`, `compose-validate`, `php-verify`, `go-verify`, `security-trivy`, and `secrets-scan`. **Must be executed prior to opening PR.** |
| `make clean` | Removes test coverage reports, caches, and build binaries. |
