.PHONY: php-lint phpcs phpcs-fix phpstan php-test php-verify php-coverage go-check go-test go-vulncheck go-verify go-coverage go-build docker-lint compose-validate security-trivy secrets-scan verify clean coverage

php-lint:
	find . -path ./vendor -prune -o -type f \( -name "*.php" -o -name "*.php.example" \) -print0 | xargs -0 -n1 php -l

phpcs:
	phpcs

phpcs-fix:
	phpcbf

phpstan:
	phpstan analyse --debug --no-progress

php-test:
	phpunit --no-coverage

php-verify: php-lint phpcs phpstan php-test

php-coverage:
	phpunit --coverage-text

go-check:
	@test -z "$$(gofmt -s -l .)" || (echo "Unformatted files found. Run 'gofmt -s -w .' to fix them." && false)
	golangci-lint run ./...
	go build ./...

go-test:
	go test -v -race ./...

go-coverage:
	go test -v -race -coverprofile=coverage.out ./...
	go tool cover -func=coverage.out

go-vulncheck:
	govulncheck ./...

go-verify: go-check go-test go-vulncheck

go-build:
	CGO_ENABLED=0 go build -ldflags="-s -w" -trimpath -o bin/wp-guard ./cmd/wp-guard

docker-lint:
	hadolint Dockerfile

compose-validate:
	@trap 'rm -f .env; rm -rf secrets' EXIT; \
	cp examples/.env.example .env; \
	mkdir -p secrets; \
	for s in db_name db_user db_password db_root_password table_prefix \
	         auth_key secure_auth_key logged_in_key nonce_key \
	         auth_salt secure_auth_salt logged_in_salt nonce_salt \
	         smtp_host smtp_port smtp_mail smtp_password; do \
	    touch "secrets/$${s}.txt"; \
	done; \
	docker compose -f docker-compose.yaml config --quiet; \
	docker compose -f docker-compose.dev.yaml config --quiet

security-trivy:
	trivy fs --severity CRITICAL,HIGH .

secrets-scan:
	gitleaks detect --source . --no-git --no-color -v --redact

verify: docker-lint php-verify go-verify security-trivy secrets-scan

coverage: php-coverage go-coverage

clean:
	rm -rf .phpunit.cache .phpcs-cache coverage coverage.xml coverage.html *.out .gocache bin/
