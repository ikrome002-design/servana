# Servana by Citrus — developer task runner.
# Phase 1: `make test` runs the full quality gate. The Docker-backed targets
# (`up`, `fresh`) are stubbed and implemented in Phase 2 (Plan §27).

.PHONY: help up fresh test backend-test front-test

help:
	@echo "Servana make targets:"
	@echo "  make test    - run every quality gate (Pint, Larastan, Pest, ESLint, vue-tsc, Vitest, build)"
	@echo "  make up      - [Phase 2] start the docker compose dev stack"
	@echo "  make fresh   - [Phase 2] migrate:fresh --seed with demo tenants (local only)"

up:
	@echo "[Phase 2] 'make up' (docker compose dev stack) is not implemented yet."

fresh:
	@echo "[Phase 2] 'make fresh' (migrate:fresh --seed) needs Docker/Postgres — Phase 2."

backend-test:
	composer pint
	composer stan
	php artisan test

front-test:
	npm run lint
	npm run typecheck
	npm run test
	npm run build

test: backend-test front-test
	@echo "All Phase 1 quality gates passed."
