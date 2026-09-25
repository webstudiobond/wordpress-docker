.PHONY: php-lint phpcs phpcs-fix phpstan test php-verify coverage docker-lint compose-validate security-trivy verify

php-lint:
	find . -path ./vendor -prune -o -type f \( -name "*.php" -o -name "*.php.example" \) -print0 | xargs -0 -n1 php -l

phpcs:
	phpcs

phpcs-fix:
	phpcbf

phpstan:
	phpstan analyse --debug --no-progress

test:
	phpunit --no-coverage
	php build/wp-sync.php

php-verify: php-lint phpcs phpstan test

coverage:
	phpunit --coverage-text

docker-lint:
	hadolint Dockerfile

compose-validate:
	@trap 'rm -f .env; rm -rf secrets' EXIT; \
	cp examples/.env.example .env; \
	mkdir -p secrets; \
	for s in db_name db_user db_password db_root_password table_prefix \
	         auth_key secure_auth_key logged_in_key nonce_key \
	         auth_salt secure_auth_salt logged_in_salt nonce_salt; do \
	    touch "secrets/$${s}.txt"; \
	done; \
	docker compose -f docker-compose.yaml config --quiet; \
	docker compose -f docker-compose.dev.yaml config --quiet

security-trivy:
	trivy fs --severity CRITICAL,HIGH .

verify: docker-lint php-lint phpcs phpstan test compose-validate security-trivy
