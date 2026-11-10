.DEFAULT_GOAL := help

# Quelle stack pour cette copie ? (backend/scripts/lib/stack-resolve.sh) : principal, épreuve, nu, unknown.
STACK_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))..)
STACK_INFO := $(shell sh $(STACK_ROOT)/backend/scripts/lib/stack-resolve.sh $(STACK_ROOT) 2>/dev/null | sed 's/ /@@/g')
stack_field = $(subst @@, ,$(patsubst $(1)=%,%,$(filter $(1)=%,$(STACK_INFO))))
STACK_MODE := $(call stack_field,STACK_MODE)
STACK_COMPOSE := $(call stack_field,COMPOSE)
TEST_PROJECT := $(call stack_field,TEST_PROJECT)
STACK_GOALS := $(or $(MAKECMDGOALS),help)
FIG := $(STACK_COMPOSE)
# Arguments de `make e2e` : SPEC (chemins relatifs à /app/frontend), GREP (regex Playwright),
# REPEAT, RETRIES, IGNORE_SNAPSHOTS=1. REPEAT et RETRIES sont bornés AU CHARGEMENT, avant la
# garde des worktrees et toute recette : une valeur refusée n'allume rien. Contrôle dès que la
# variable a une origine (ligne de commande, environnement, fichier) — `REPEAT=11 make e2e` par
# l'environnement ne contourne pas le plafond ; une valeur vide explicite est refusée. Liste
# blanche : un seul mot, PUIS la liste en motifs et la valeur en texte (la forme inverse
# `$(filter $(REPEAT),1 …)` laisserait passer `1 2` et `%`).
E2E_REPEAT_REFUSED = REPEAT=$(REPEAT) : valeur refusée — un entier de 1 à 10 attendu (plafond de 10 répétitions par spec en local, décision fondateur 2026-11-10 ; au-delà, mesurer sur la CI)
E2E_RETRIES_REFUSED = RETRIES=$(RETRIES) : valeur refusée — 0, 1 ou 2 attendu (la CI joue 2)
ifneq ($(origin REPEAT),undefined)
ifneq ($(words $(REPEAT)),1)
$(error $(E2E_REPEAT_REFUSED))
endif
ifeq ($(filter 1 2 3 4 5 6 7 8 9 10,$(REPEAT)),)
$(error $(E2E_REPEAT_REFUSED))
endif
endif
ifneq ($(origin RETRIES),undefined)
ifneq ($(words $(RETRIES)),1)
$(error $(E2E_RETRIES_REFUSED))
endif
ifeq ($(filter 0 1 2,$(RETRIES)),)
$(error $(E2E_RETRIES_REFUSED))
endif
endif
# Refus AVANT toute recette : un `make -n` les prouve aussi, et rien ne démarre à moitié.
ifeq ($(filter principal epreuve nu,$(STACK_MODE)),)
ifneq ($(filter-out help,$(STACK_GOALS)),)
$(error $(or $(call stack_field,STACK_REASON),stack-resolve : dépôt non identifié (git absent ou dossier hors dépôt) — aucune cible Docker))
endif
endif
ifeq ($(STACK_MODE),nu)
STACK_REFUSED := $(filter-out install test coverage lint help,$(STACK_GOALS))
ifneq ($(STACK_REFUSED),)
$(error $(firstword $(STACK_REFUSED)) : indisponible en worktree nu — stack d'épreuve (make epreuve-up WT=…) ou dépôt principal)
endif
ifneq ($(call stack_field,STACK_ENV_OK),1)
ifneq ($(filter-out help,$(STACK_GOALS)),)
$(error .env sans AMATEO_STACK=$(TEST_PROJECT) exact : make -C backend test-up)
endif
endif
endif
ROOT_ENV := ../.env

# Compte superadmin JETABLE du run e2e — recréé à chaque `make e2e`, jamais réutilisé
# ailleurs. Il n'a rien de secret : il ne vit que dans la base de DEV, aux côtés des
# fixtures. Le mot de passe satisfait `PasswordPolicy` (12+, majuscule, chiffre, symbole).
E2E_SA_EMAIL := e2e-admin@clubscheduler.test
E2E_SA_PASSWORD := E2eAdminPass!2026

$(ROOT_ENV):
	$(MAKE) -C .. .env

exec: $(ROOT_ENV) ## Enter the Dockerized Node.js tooling environment
	$(FIG) --profile tools run --rm --build --no-deps frontend-tooling sh

start: ## Start the full Docker stack
	$(FIG) up -d

logs: ## Follow frontend service logs
	$(FIG) --profile dev logs -f frontend frontend-dev

stop: ## Stop the frontend service
	$(FIG) --profile dev stop frontend frontend-dev

shell: ## Open a shell in the frontend service container
	$(FIG) exec frontend sh

status: ## Show frontend service status
	$(FIG) --profile dev ps frontend frontend-dev

install: $(ROOT_ENV) ## Build the Dockerized Node.js tooling environment
	$(FIG) --profile tools build frontend-tooling

build: $(ROOT_ENV) ## Build the production frontend image
	$(FIG) build frontend

dev: $(ROOT_ENV) ## Start Dockerized Vite with hot reload
	$(FIG) --profile dev up --build frontend-dev

# `tsc -b`, PAS `tsc --noEmit` : le tsconfig racine est un fichier SOLUTION
# ("files": [] + references), et --noEmit n'y voit alors AUCUN fichier — il sortait 0
# sans rien vérifier, pendant que la CI (qui fait bien `tsc -b`) tombait sur les erreurs.
# --force : sans lui, le tsbuildinfo peut court-circuiter la vérification.
# `lint:deps` = knip (même script/knip.json que le job CI « Dependency check (knip) »,
# jusqu'ici CI-only) : il rougit sur une dépendance déclarée mais jamais importée.
lint: install ## Run ESLint + TypeScript + knip (dependency hygiene) checks in Docker
	$(FIG) --profile tools run --rm --no-deps frontend-tooling sh -lc 'npm run lint && npx tsc -b --force && npm run lint:deps'

test: ## Run frontend checks and tests in Docker
	$(MAKE) lint
	$(FIG) --profile tools run --rm --no-deps frontend-tooling npm run test -- --maxWorkers=2

# P4-166 — couverture de code + cliquet, miroir du job CI frontend-coverage. Le plancher
# est lu de ../coverage-floor.json par vitest.config.ts (thresholds.lines) ; `install`
# rebâtit l'image tooling (qui COPIE le code ET coverage-floor.json). Séparé de `test` :
# `npm run test` reste sans couverture. `.PHONY` — vitest écrit un dossier `coverage/`
# homonyme sur l'hôte (via le job CI / npm run direct), qui ferait croire la cible à jour.
.PHONY: coverage
coverage: install ## Couverture de code + cliquet (plancher lu de ../coverage-floor.json, B3)
	$(FIG) --profile tools run --rm --no-deps frontend-tooling npm run test:coverage -- --maxWorkers=2

# P4-33 — e2e Playwright dockerisé : plus AUCUN Node requis sur l'hôte. Exige la
# stack complète (`make start` à la racine) + le Vite dev (`make dev` ici) :
# les specs s'exécutent contre http://frontend-dev:5173 (code vivant), Mailpit
# est joint en interne (voir le service `e2e` du compose). PUPPETEER_SKIP_DOWNLOAD :
# le postinstall de puppeteer (dép. transitive) cassait sur un ~/.cache pollué —
# ici il n'a rien à télécharger, Chromium vient de l'image.
# ⚠ Le self-heal du globalSetup (compose up -d --wait) est SAUTÉ dès que
# E2E_BASE_URL est posé — or le run dockerisé le pose toujours. Il vit donc ICI,
# côté hôte (le conteneur e2e n'a pas de socket docker) : un messenger-worker
# mort (time-limit horaire) laissait sinon toute génération en PENDING.
# Les arguments voyagent EXPORTÉS par make et passés au conteneur par `-e NOM` sans valeur :
# jamais `-e E2E_GREP='$(GREP)'`, qu'une apostrophe dans le motif casserait côté hôte. Le sh
# du conteneur les développe ; vide ou absent, rien n'est ajouté à `npx playwright test`.
e2e: export E2E_SPEC := $(SPEC)
e2e: export E2E_GREP := $(GREP)
e2e: export E2E_REPEAT := $(REPEAT)
e2e: export E2E_RETRIES := $(RETRIES)
e2e: export E2E_IGNORE_SNAPSHOTS := $(IGNORE_SNAPSHOTS)
e2e: $(ROOT_ENV) ## Run Playwright e2e tests fully inside Docker (stack + make dev required) — SPEC=… GREP=… REPEAT=1..10 RETRIES=0..2 IGNORE_SNAPSHOTS=1
	$(FIG) up -d --wait
	# Fail-closed sandbox guard (P4-141) — the e2e suite mutates the live dev DB
	# (superadmin reset + fixtures + clubs). Refuse to run against anything but the
	# AI sandbox (amateo_dev) or a *_test DB, so `make play` mode (amateo_local) is
	# never wiped. Exit 1 aborts the target before any mutation.
	@bash ../backend/scripts/lib/sandbox-guard.sh || exit 1
	# Un volume nommé neuf naît possédé par root : la pré-étape le rend à l'UID
	# hôte, sans quoi npm ci refuse d'écrire. L'UID/GID est développé CÔTÉ HÔTE (guillemets
	# doubles), miroir du job CI : sous apostrophes, `$${USER_ID:-1000}` était développé dans
	# le conteneur root, où USER_ID n'existe pas (toujours 1000) ; et `id -u` sous apostrophes
	# y vaudrait 0.
	$(FIG) --profile tools run --rm --no-deps --user root e2e sh -lc "chown -R $$(id -u):$$(id -g) /app/frontend/node_modules"
	# Préflight SUPERADMIN — le seul parcours e2e qui franchit /admin (modale d'actions,
	# `modal-reachability.spec.ts`). Il vit ICI, côté hôte, pour la même raison que le
	# `up -d --wait` ci-dessus : le conteneur e2e n'a pas de socket docker.
	#
	# ⚠ On RECRÉE le compte à chaque run, et c'est ce qui rend le secret connaissable :
	# `app:superadmin:create` n'imprime la clé TOTP qu'à la création (« it will not be
	# displayed again »). Un compte laissé en place rendrait le secret irrécupérable et le
	# spec se skiperait en silence.
	#
	# ⚠ AUCUNE porte dev n'est ajoutée côté backend pour ça. Une route `/api/dev/*` délivrant
	# une session superadmin aurait été plus simple — et aurait mis la compromission complète
	# de la surface cross-tenant derrière un seul `APP_DEBUG` mal réglé. Le TOTP est calculé
	# dans le test (RFC 6238), le backend ne connaît aucun contournement.
	# ⚠ `admin_auth` est borné à 5 tentatives / 15 min PAR IP (rate_limiter.yaml). Le
	# conteneur e2e a une IP stable : à partir du 6e run dans le quart d'heure, la connexion
	# superadmin échoue et le spec rougit en désignant l'écran TOTP — ça RESSEMBLE à une
	# régression alors que c'est le quota. On repart d'un compteur propre.
	@$(FIG) exec -T php-fpm php bin/console cache:pool:clear cache.rate_limiter >/dev/null
	@$(FIG) exec -T postgres psql -U amateo_owner -d amateo_dev -c \
		"DELETE FROM super_admin WHERE email='$(E2E_SA_EMAIL)'" >/dev/null
	# mktemp : deux runs e2e parallèles ne se lisent pas leur clé TOTP.
	@sa_file=$$(mktemp); trap 'rm -f "$$sa_file"' EXIT; \
	printf '$(E2E_SA_PASSWORD)\n$(E2E_SA_PASSWORD)\n' | $(FIG) exec -T php-fpm \
		php bin/console app:superadmin:create $(E2E_SA_EMAIL) > "$$sa_file" || exit 1; \
	$(FIG) --profile tools run --rm --no-deps \
		-e E2E_SUPERADMIN_EMAIL='$(E2E_SA_EMAIL)' \
		-e E2E_SUPERADMIN_PASSWORD='$(E2E_SA_PASSWORD)' \
		-e E2E_SUPERADMIN_TOTP_SECRET="$$(sed -n 's/.*setup key: *//p' "$$sa_file" | tr -d '\r\n')" \
		-e E2E_SPEC -e E2E_GREP -e E2E_REPEAT -e E2E_RETRIES -e E2E_IGNORE_SNAPSHOTS \
		e2e sh -lc 'PUPPETEER_SKIP_DOWNLOAD=1 npm ci --legacy-peer-deps && npx playwright test $$E2E_SPEC $${E2E_GREP:+-g "$$E2E_GREP"} $${E2E_REPEAT:+--repeat-each "$$E2E_REPEAT"} $${E2E_RETRIES:+--retries "$$E2E_RETRIES"} $${E2E_IGNORE_SNAPSHOTS:+--ignore-snapshots}'

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
