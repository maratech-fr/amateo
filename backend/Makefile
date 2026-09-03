.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
USERID := $(shell id -u)
GROUPID := $(shell id -g)
export USER_ID := $(USERID)
export GROUP_ID := $(GROUPID)
APP_ENV ?= $(shell awk -F= '$$1=="APP_ENV" {print $$2}' .env 2>/dev/null | tail -n 1)
APP_ENV ?= dev

ifdef APP_ENV
	ifeq ($(shell uname),Darwin)
		EXECROOT = $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
		EXEC = $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
	else
		EXECROOT = $(FIG) exec -e APP_ENV=$(APP_ENV) php-fpm
		EXEC = $(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm
	endif
else
	EXECROOT =
	EXEC =
endif

.PHONY: help ## Display this help
help:
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_.@%-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

.PHONY: install ## Install PHP dependencies
install:
	$(EXEC) composer install --no-interaction

.PHONY: test ## Run PHPStan, CS, and unit tests
test: APP_ENV = test
test:
	$(MAKE) APP_ENV=$(APP_ENV) phpstan
	$(MAKE) APP_ENV=$(APP_ENV) cs
	$(EXEC) php vendor/bin/phpunit --testsuite Unit

# `phpunit tests/` — le DOSSIER, exactement comme le job CI `Unit Tests`. Ne PAS
# repasser par --testsuite : les suites déclarées ne couvrent que Unit/Integration/
# Security/Queue/CrossStack, alors que tests/ contient aussi Api, Command, Double,
# EventListener, MessageHandler, OpenApi et Validator — 7 dossiers qu'aucune cible
# locale n'atteignait, et que seule la CI voyait (lot C2 : deux échecs y ont dormi).
.PHONY: tests-complete ## Run the FULL suite, exactly like CI (phpstan + cs + tous les tests)
tests-complete: APP_ENV = test
tests-complete: db-init-test
	$(MAKE) APP_ENV=$(APP_ENV) phpstan
	$(MAKE) APP_ENV=$(APP_ENV) cs
	$(EXEC) php vendor/bin/phpunit tests/ --exclude-group contract

.PHONY: tests-engine-semantics ## Les tests qui interrogent le VRAI moteur (job CI « Engine semantics »)
tests-engine-semantics: APP_ENV = test
tests-engine-semantics:
	$(EXEC) php vendor/bin/phpunit --group contract

.PHONY: phpunit ## Run blocking PHPUnit group
phpunit: APP_ENV = test
phpunit:
	$(EXEC) php vendor/bin/phpunit --group phase1

# RLS note: database create/drop and fixtures need the admin connection —
# the runtime amateo_app has no CREATEDB and its DELETEs would be silently
# scoped by the RLS policies (partial purge).
.PHONY: db-init-test ## Create and migrate the test database
db-init-test: APP_ENV = test
db-init-test:
	$(EXEC) php bin/console doctrine:database:create --if-not-exists --connection admin -e test
	$(EXEC) php bin/console doctrine:migrations:migrate --no-interaction -e test
	# Un phpunit tué (Ctrl-C, kill, gel WSL2) laisse sa transaction DAMA « idle in
	# transaction » : son verrou survit et TOUT run suivant gèle au premier accès à la
	# même table (constaté 2026-07-24 : RunAdminJobCommandTest, DELETE bloqué en boucle).
	# Postgres purge seul ces zombies après 60 s — DB de TEST uniquement.
	$(FIG) exec -T postgres psql -U amateo_owner -d postgres -c "ALTER DATABASE amateo_test SET idle_in_transaction_session_timeout = '60s'"

.PHONY: db-empty ## Drop, recreate, and migrate the dev database (empty — no seed)
db-empty: APP_ENV = dev
db-empty:
	@scripts/lib/mutation-confirm.sh db-empty
	$(EXECROOT) php bin/console doctrine:database:drop --force --if-exists --connection admin
	$(EXECROOT) php bin/console doctrine:database:create --connection admin
	$(EXECROOT) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: db-empty-test ## Drop, recreate, and migrate the test database (empty — no seed)
db-empty-test: APP_ENV = test
db-empty-test:
	$(EXECROOT) php bin/console doctrine:database:drop --force --if-exists --connection admin -e test
	$(EXECROOT) php bin/console doctrine:database:create --connection admin -e test
	$(EXECROOT) php bin/console doctrine:migrations:migrate --no-interaction -e test

.PHONY: migration-diff ## Generate a migration from the entity/schema diff
migration-diff:
	$(EXEC) php bin/console doctrine:migrations:diff --no-interaction

# doctrine_migrations.yaml pins `connection: admin` — migrations carry DDL and RLS
# policies, which the runtime amateo_app may not run. EXEC already injects APP_ENV,
# so this single target serves both dev and test.
.PHONY: migration-migrate ## Apply pending migrations (honours APP_ENV)
migration-migrate:
	$(EXEC) php bin/console doctrine:migrations:migrate --no-interaction

.PHONY: jwt-keys ## Generate the JWT keypair if absent (idempotent)
jwt-keys:
	$(EXEC) php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction

# Non-destructive counterpart to db-empty: never drops, safe to re-run.
.PHONY: db-init ## Create + migrate the dev database (idempotent)
db-init: APP_ENV = dev
db-init:
	$(EXEC) php bin/console doctrine:database:create --if-not-exists --connection admin
	$(MAKE) APP_ENV=$(APP_ENV) migration-migrate

# Real env var beats dotenv, so injecting DATABASE_URL=<admin url> makes the
# whole seed run (purge + inserts) bypass RLS. Symfony dotenv precedence:
# .env.local overrides .env — read both, last definition wins.
DB_ADMIN_URL = $(shell cat .env .env.local 2>/dev/null | awk -F= '$$1=="DATABASE_ADMIN_URL" {sub(/^[^=]*=/,""); gsub(/"/,""); print}' | tail -n 1)

# --- P4-141 : play mode (fondateur) vs bac à sable (IA) ----------------------
# `amateo_dev` = bac à sable de l'IA (défaut committé, seedé/purgé à volonté).
# `amateo_local` = base de JEU du fondateur : les scripts mutateurs de l'IA la
# REFUSENT (backend/scripts/lib/sandbox-guard.sh). `make play` (racine) bascule
# toute la stack dev vers amateo_local via backend/.env.local (gitignoré) ;
# `make sandbox` (racine) retire cette surcharge et revient à amateo_dev.
.PHONY: play-env ## (interne à `make play`) Écrit backend/.env.local → amateo_local
play-env:
	@printf '# Généré par `make play` (P4-141) — base de JEU du fondateur (amateo_local).\n# Gitignoré. `make sandbox` le supprime pour revenir au bac à sable amateo_dev.\nDATABASE_URL="postgresql://amateo_app:app_user_password@postgres:5432/amateo_local?serverVersion=16&charset=utf8"\nDATABASE_ADMIN_URL="postgresql://amateo_owner:clubscheduler_dev_password@postgres:5432/amateo_local?serverVersion=16&charset=utf8"\n' > .env.local
	@echo "backend/.env.local écrit — la stack dev vise amateo_local (mode play)."

.PHONY: sandbox-env ## (interne à `make sandbox`) Supprime backend/.env.local → retour amateo_dev
sandbox-env:
	@rm -f .env.local
	@echo "backend/.env.local supprimé — la stack dev revient au bac à sable amateo_dev."

# Mot de passe du gestionnaire de démo BCCL — NON secret (dev only, même statut
# que E2E_SA_PASSWORD côté frontend : il ne vit que dans la base de JEU). Requis
# à la première création, optionnel au reset. Surchargeable : make seed-demo DEMO_BCCL_PASSWORD=…
DEMO_BCCL_PASSWORD ?= DemoBccl!2026

# Club de DÉMONSTRATION permanent anonymisé (ARA9999999) — « créer OU RESET » qui
# PURGE le workspace (ErasedClubPurger) avant de re-seeder. Connexion admin
# (traverse la RLS). IF_ABSENT=1 ajoute --if-absent : no-op si le club existe déjà
# (le chemin NON destructeur de `make play`).
IF_ABSENT ?=
.PHONY: seed-demo ## Seed/reset the permanent anonymised demo club (IF_ABSENT=1 → no-op if present)
seed-demo: APP_ENV = dev
seed-demo:
	@scripts/lib/mutation-confirm.sh seed-demo
	$(FIG) exec -e APP_ENV=$(APP_ENV) -e DATABASE_URL='$(DB_ADMIN_URL)' -u $(USERID):$(GROUPID) php-fpm php bin/console app:demo:seed --password='$(DEMO_BCCL_PASSWORD)' $(if $(IF_ABSENT),--if-absent) --no-interaction

# Club dev BCCL RÉEL (identités réelles, sans flag démo) — CREATE-ONLY : la
# commande NE FAIT RIEN si le club existe déjà (no-op, jamais reset). Connexion
# admin (traverse la RLS), comme `seed-demo`.
.PHONY: seed-bccl ## Seed the real BCCL dev club (create-only, no-op if present, admin connection)
seed-bccl: APP_ENV = dev
seed-bccl:
	@scripts/lib/mutation-confirm.sh seed-bccl
	$(FIG) exec -e APP_ENV=$(APP_ENV) -e DATABASE_URL='$(DB_ADMIN_URL)' -u $(USERID):$(GROUPID) php-fpm php bin/console app:bccl:seed --no-interaction

# Données de référence des vacances : scolaires (vacances) ET fériés (jours
# fériés — 14 juillet, etc.). GLOBALES (non-tenant), pas de connexion admin
# requise. Idempotentes (source JSON offline). `make play` les rejoue pour que le
# calendrier du club dev ne montre pas un été vide.
.PHONY: seed-holidays ## Seed the school + public holiday reference data (global, non-tenant)
seed-holidays: APP_ENV = dev
seed-holidays:
	$(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm php bin/console app:school-holidays:seed --no-interaction
	$(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm php bin/console app:public-holidays:seed --no-interaction

# Catalogue des fenêtres de match de la ligue (créneaux WE autorisés par catégorie/
# niveau/genre). GLOBAL (non-tenant), pas de connexion admin requise. Idempotent
# (source JSON offline, upsert par clé naturelle). `make play` le rejoue pour que
# le module matchs connaisse les fenêtres de la ligue du club dev.
.PHONY: seed-league ## Seed the league match-window catalog (global, non-tenant)
seed-league: APP_ENV = dev
seed-league:
	$(FIG) exec -e APP_ENV=$(APP_ENV) -u $(USERID):$(GROUPID) php-fpm php bin/console app:league-windows:seed --no-interaction

# OPTIONNEL, jamais lancé automatiquement (P4-141) : supprime les bases dev
# héritées d'AVANT le découpage (clubscheduler / clubscheduler_test), restées
# inertes dans le volume. Aucune donnée à préserver (BCCL se reseede via
# `make seed-demo` / `make seed-bccl`). À lancer À LA MAIN uniquement.
.PHONY: db-drop-legacy ## (optionnel) Drop des bases dev héritées clubscheduler / clubscheduler_test
db-drop-legacy:
	$(FIG) exec -T postgres psql -U amateo_owner -d postgres -c "DROP DATABASE IF EXISTS clubscheduler"
	$(FIG) exec -T postgres psql -U amateo_owner -d postgres -c "DROP DATABASE IF EXISTS clubscheduler_test"

.PHONY: phpstan ## Run PHPStan
phpstan:
	$(EXEC) composer phpstan

.PHONY: cs ## Run PHP-CS-Fixer in dry-run mode
cs:
	$(EXEC) composer cs-fix -- --dry-run --diff

.PHONY: cs-fix ## Fix code style with PHP-CS-Fixer
cs-fix:
	$(EXEC) composer cs-fix

.PHONY: rector ## Run Rector in dry-run mode
rector:
	$(EXEC) composer rector -- --dry-run

.PHONY: lint ## Run PHPStan, CS, and Rector
lint:
	$(MAKE) phpstan
	$(MAKE) cs
	$(MAKE) rector

.PHONY: fix-perms ## Fix permissions on var/generate so host user can read reports
fix-perms:
	$(FIG) exec --user root php-fpm chown -R $(USERID):$(GROUPID) /app/backend/var/generate

.PHONY: exec ## Enter the php-fpm container
exec:
	@$(FIG) ps php-fpm | grep -q "Up" || $(FIG) up -d php-fpm >/dev/null
	$(EXEC) bash || $(EXEC) sh
