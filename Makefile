.DEFAULT_GOAL := help

# Quelle stack pour cette copie ? (backend/scripts/lib/stack-resolve.sh) : principal, épreuve, nu, unknown.
STACK_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
STACK_INFO := $(shell sh $(STACK_ROOT)/backend/scripts/lib/stack-resolve.sh $(STACK_ROOT) 2>/dev/null | sed 's/ /@@/g')
stack_field = $(subst @@, ,$(patsubst $(1)=%,%,$(filter $(1)=%,$(STACK_INFO))))
STACK_MODE := $(call stack_field,STACK_MODE)
STACK_COMPOSE := $(call stack_field,COMPOSE)
TEST_PROJECT := $(call stack_field,TEST_PROJECT)
STACK_GOALS := $(or $(MAKECMDGOALS),help)
DOCKER_COMPOSE := $(STACK_COMPOSE)
# Refus AVANT toute recette : un `make -n` les prouve aussi, et rien ne démarre à moitié.
ifeq ($(filter principal epreuve nu,$(STACK_MODE)),)
ifneq ($(filter-out help deploy,$(STACK_GOALS)),)
$(error $(or $(call stack_field,STACK_REASON),stack-resolve : dépôt non identifié (git absent ou dossier hors dépôt) — aucune cible Docker))
endif
endif
ifeq ($(STACK_MODE),nu)
STACK_REFUSED := $(filter-out install test lint help deploy epreuve-up epreuve-dev epreuve-status epreuve-down epreuve-destroy epreuve-check,$(STACK_GOALS))
ifneq ($(STACK_REFUSED),)
$(error $(firstword $(STACK_REFUSED)) : indisponible en worktree nu — stack d'épreuve (make epreuve-up WT=…) ou dépôt principal)
endif
ifneq ($(call stack_field,STACK_ENV_OK),1)
ifneq ($(filter-out help deploy epreuve-up epreuve-dev epreuve-status epreuve-down epreuve-destroy epreuve-check,$(STACK_GOALS)),)
$(error .env sans AMATEO_STACK=$(TEST_PROJECT) exact : make -C backend test-up)
endif
endif
endif
# php-fpm and engine belong here: `make install` execs into both. nginx must NOT —
# its healthcheck curls /api/health, which needs vendor/ that install has yet to write.
INFRA_SERVICES := postgres redis mailpit mercure php-fpm engine

# The PHP containers run as ${USER_ID:-1000}. A shell export beats --env-file, so this
# covers .env files created before the rule below started writing the ids.
export USER_ID := $(shell id -u)
export GROUP_ID := $(shell id -g)

.env:
ifeq ($(STACK_MODE),principal)
	cp .env.dist .env
	printf 'USER_ID=%s\nGROUP_ID=%s\n' "$$(id -u)" "$$(id -g)" >> .env
else
	@echo ".env : refusé hors dépôt principal — make -C backend test-up ou make epreuve-up WT=…" >&2
	@exit 1
endif

.installed: .env
	$(DOCKER_COMPOSE) build
	$(DOCKER_COMPOSE) up -d --wait $(INFRA_SERVICES)
	$(MAKE) install
	$(MAKE) bootstrap
	touch .installed

bootstrap: .env ## Generate JWT keys + create/migrate the dev DB (idempotent; repairs a stale DB)
	$(MAKE) -C backend jwt-keys
	$(MAKE) -C backend db-init

start: .env .installed ## Start all Docker services, install dependencies on first run
	$(DOCKER_COMPOSE) up -d --wait

stop: ## Stop all Docker services
	$(DOCKER_COMPOSE) down

# Un redémarrage NE DOIT PAS installer. `start` dépend de `.installed` (build +
# install backend/engine + bootstrap au premier run) ; quand l'install engine
# échoue (ex. « Errno 13 /home/engine »), l'ancien `restart: stop start` mourait
# APRÈS le `stop` et laissait nginx/cron-runner/frontend/pdf-worker DOWN — le smoke
# suivant échouait alors sur « Backend unreachable :8080 », symptôme trompeur.
# `restart` se contente donc de down + up --wait sur les images DÉJÀ construites
# (un vrai premier run passe par `make start`, qui installe).
restart: .env ## Restart all services (ne réinstalle pas — un redémarrage n'installe jamais)
	$(DOCKER_COMPOSE) down
	$(DOCKER_COMPOSE) up -d --wait

# --- P4-141 : mode play (fondateur) vs bac à sable (IA) ----------------------
# `amateo_dev` = bac à sable de l'IA (défaut committé). `amateo_local` = base de
# JEU du fondateur, que les scripts mutateurs de l'IA REFUSENT (sandbox-guard).
# `make play` bascule toute la stack dev vers amateo_local (backend/.env.local,
# gitignoré), la crée/migre puis la seede ; `make sandbox` retire la surcharge et
# revient à amateo_dev. Les workers long-lived sont redémarrés car ils tiennent la
# config DB en mémoire.
# ⚠ NON DESTRUCTEUR : `seed-bccl` (club réel) est create-only, no-op s'il existe
# déjà ; `IF_ABSENT=1 seed-demo` ne seede la démo que si elle est absente. Le
# travail du fondateur survit donc à un re-`make play`. Le RESET explicite de la
# démo reste `make -C backend seed-demo` (créer OU reset, voulu) ; vider la base
# entière = `make db-empty` / `make reset`.
play: .env ## Bascule vers la base de JEU du fondateur (amateo_local) + seed BCCL/démo si absents + vacances + catalogue ligue
	$(MAKE) -C backend play-env
	$(MAKE) -C backend db-init
	$(MAKE) -C backend CONFIRM=yes seed-bccl
	$(MAKE) -C backend CONFIRM=yes IF_ABSENT=1 seed-demo
	$(MAKE) -C backend seed-holidays
	$(MAKE) -C backend seed-league
	$(DOCKER_COMPOSE) restart messenger-worker cron-runner

sandbox: .env ## Revient au bac à sable de l'IA (amateo_dev) : retire backend/.env.local
	$(MAKE) -C backend sandbox-env
	$(DOCKER_COMPOSE) restart messenger-worker cron-runner

# Vide la base ACTUELLEMENT visée (drop + create + migrate, aucun seed). En mode
# play c'est amateo_local ; en bac à sable, amateo_dev — la garde interactive
# (mutation-confirm) nomme la base avant de détruire.
db-empty: .env ## Vide (drop+create+migrate) la base dev ACTUELLEMENT visée — aucun seed
	$(MAKE) -C backend db-empty

# `reset` = composition littérale : vide la base visée PUIS `make play`. En bac à
# sable, cela vide amateo_dev puis bascule + seede amateo_local (amateo_dev reste
# alors vide) ; en mode play, cela vide amateo_local puis la re-seede à neuf.
reset: .env ## Vide la base visée puis rebascule en mode play (db-empty + play)
	$(MAKE) db-empty
	$(MAKE) play

install: .env ## Install backend and engine development dependencies
	$(MAKE) -C backend install
	$(MAKE) -C engine install

reinstall: .env ## Force reinstall dependencies
	rm -f .installed
	$(MAKE) .installed

# `docker compose build` seul ne RECRÉE pas les conteneurs vivants : ils continuent
# de servir l'ancienne image (piège vécu le 2026-08-18 — :8081 servait un dist périmé
# malgré un build frais). D'où le `up -d` qui suit : les services dont l'image a
# changé sont recréés, les autres ne bougent pas.
build: .env ## Build all Docker images AND recreate the running containers on them
	$(DOCKER_COMPOSE) build
	$(DOCKER_COMPOSE) up -d

rebuild: .env ## Rebuild all Docker images without cache AND recreate the containers
	$(DOCKER_COMPOSE) build --no-cache
	$(DOCKER_COMPOSE) up -d

status: .env ## Show Docker services status
	$(DOCKER_COMPOSE) ps

exec: .env ## Open shell in container (usage: make exec SERVICE=php-fpm)
	@if [ -z "$(SERVICE)" ]; then echo "Usage: make exec SERVICE=<service>"; exit 1; fi
	$(DOCKER_COMPOSE) exec $(SERVICE) sh

logs: .env ## Show Docker logs
	$(DOCKER_COMPOSE) logs -f

logs-service: .env ## Show Docker logs for one service (usage: make logs-service SERVICE=php-fpm)
	@if [ -z "$(SERVICE)" ]; then echo "Usage: make logs-service SERVICE=<service>"; exit 1; fi
	$(DOCKER_COMPOSE) logs -f $(SERVICE)

test: .env ## Run all tests
	$(MAKE) -C backend test
	$(MAKE) -C engine test
	$(MAKE) -C frontend test

lint: .env ## Run all linters
	$(MAKE) -C backend lint
	$(MAKE) -C engine lint
	$(MAKE) -C frontend lint

health: ## Check API health
	@curl -s http://localhost:8080/api/health | python3 -m json.tool || true

clean: .env ## Stop services and remove containers/networks
	$(DOCKER_COMPOSE) down --remove-orphans

clean-volumes: .env ## Stop services and remove containers/networks/volumes
	$(DOCKER_COMPOSE) down --remove-orphans --volumes
	rm -f .installed

reset-install: ## Force next make start to reinstall dependencies
	rm -f .installed

services: .env ## List Docker Compose services
	$(DOCKER_COMPOSE) config --services

# Release helper. Pushing a tag (`git tag vX.Y.Z && git push origin vX.Y.Z`) only
# BUILDS and publishes the images via .github/workflows/deploy.yml — it never
# rolls the VM (« tag ≠ déploiement »). THIS target is what actually DEPLOYS: it
# dispatches deploy.yml (workflow_dispatch), refuses an out-of-sync HEAD, then
# follows the run it just created (fails red if the run fails).
deploy: ## Deploy VERSION=vX.Y.Z (or origin/main HEAD if omitted) via the deploy workflow
	bash scripts/deploy.sh $(VERSION)

# --- Stack d'épreuve : UNE stack complète et seedée pour un worktree, à côté de celle du fondateur.
# Toujours `-p amateo-epreuve` en toutes lettres, jamais la compose de la copie appelante, qui peut
# être le dépôt principal. Occupation = label working_dir du projet.
EPREUVE_OCCUPANT = docker ps -a --filter label=com.docker.compose.project=amateo-epreuve --format '{{.Label "com.docker.compose.project.working_dir"}}' | sed -n 1p
EPREUVE_LIB := $(STACK_ROOT)/backend/scripts/lib
WT_ABS = $(if $(WT),$(abspath $(WT)))

.PHONY: epreuve-up epreuve-dev epreuve-status epreuve-down epreuve-destroy epreuve-check
epreuve-up: ## Monte la stack d'épreuve sur un worktree (WT=… [RESEED=1]) : infra, install, seed, vérification
	@set -e; wt='$(WT_ABS)'; rc=0; bash $(EPREUVE_LIB)/epreuve-gate.sh "$$wt" '$(RESEED)' '$(STACK_ROOT)' || rc=$$?; \
	[ "$$rc" -ne 10 ] || exit 0; [ "$$rc" -eq 0 ] || exit "$$rc"; \
	mark="$$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$$wt" rev-parse --absolute-git-dir)/amateo-epreuve-mounted"; rm -f "$$mark"; \
	occupant="$$($(EPREUVE_OCCUPANT))"; \
	[ "$$occupant" = "$$wt" ] || $(MAKE) -C "$$wt/backend" test-down; \
	cp "$$wt/.env.epreuve.dist" "$$wt/.env"; printf 'USER_ID=%s\nGROUP_ID=%s\nAMATEO_GIT_COMMON_DIR=%s\n' "$$(id -u)" "$$(id -g)" "$$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$$wt" rev-parse --path-format=absolute --git-common-dir)" >> "$$wt/.env"; \
	bash $(EPREUVE_LIB)/stack-check.sh snapshot "$$wt"; \
	dc="docker compose -p amateo-epreuve --env-file $$wt/.env -f $$wt/docker-compose.yml -f $$wt/docker-compose.worktree.yml"; \
	[ "$$occupant" != "$$wt" ] || $$dc stop messenger-worker cron-runner pdf-worker frontend nginx; \
	$$dc up -d --wait $(INFRA_SERVICES); \
	$(MAKE) -C "$$wt/backend" install; \
	$(MAKE) -C "$$wt/backend" jwt-keys; \
	$(MAKE) -C "$$wt/backend" db-empty; \
	$(MAKE) -C "$$wt/backend" seed-bccl; \
	$(MAKE) -C "$$wt/backend" seed-holidays; \
	$(MAKE) -C "$$wt/backend" seed-league; \
	$$dc up -d --wait; \
	if ! bash $(EPREUVE_LIB)/stack-check.sh check "$$wt"; then $$dc down --remove-orphans; rm -f "$$wt/.env"; exit 1; fi; \
	touch "$$mark"; \
	echo "stack d'épreuve montée sur $$wt : http://localhost:8181"; \
	echo ":8181 partage le cookie de localhost avec :8081 — ouvre-le dans une fenêtre privée"

epreuve-dev: ## Vite sur la stack d'épreuve (:5273), WT=… ou l'occupant
	@wt='$(WT_ABS)'; wt="$${wt:-$$($(EPREUVE_OCCUPANT))}"; [ -n "$$wt" ] || { echo "stack d'épreuve libre : make epreuve-up WT=… d'abord" >&2; exit 1; }; \
	docker compose -p amateo-epreuve --env-file "$$wt/.env" -f "$$wt/docker-compose.yml" -f "$$wt/docker-compose.worktree.yml" --profile dev up -d --build frontend-dev

epreuve-status: ## Qui occupe la stack d'épreuve, et dans quel état
	@wt="$$($(EPREUVE_OCCUPANT))"; \
	if [ -z "$$wt" ]; then echo "stack d'épreuve libre"; exit 0; fi; \
	echo "stack d'épreuve occupée par $$wt ($$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$$wt" branch --show-current 2>/dev/null))"; \
	docker ps -a --filter label=com.docker.compose.project=amateo-epreuve --format '{{.Names}} {{.Status}}'

epreuve-check: ## Prouve que la stack d'épreuve est isolée de celle du fondateur (WT=… ou l'occupant)
	@wt='$(WT_ABS)'; wt="$${wt:-$$($(EPREUVE_OCCUPANT))}"; [ -n "$$wt" ] || { echo "stack d'épreuve libre (-p amateo-epreuve sans conteneur)" >&2; exit 1; }; \
	bash $(EPREUVE_LIB)/stack-check.sh check "$$wt"

epreuve-down: ## Arrête la stack d'épreuve et la libère (volumes gardés)
	@wt="$$($(EPREUVE_OCCUPANT))"; [ -n "$$wt" ] || { echo "stack d'épreuve déjà libre"; exit 0; }; \
	base="$$wt"; [ -f "$$base/docker-compose.yml" ] || base='$(STACK_ROOT)'; \
	[ -f "$$base/.env" ] || cp "$$base/.env.epreuve.dist" "$$base/.env"; \
	docker compose -p amateo-epreuve --env-file "$$base/.env" -f "$$base/docker-compose.yml" --profile dev down --remove-orphans; \
	rm -f "$$wt/.env"; rm -f "$$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$$wt" rev-parse --absolute-git-dir 2>/dev/null)/amateo-epreuve-mounted"

epreuve-destroy: ## Détruit la stack d'épreuve, volumes compris (WT=… nettoie aussi ce worktree)
	@wt="$$($(EPREUVE_OCCUPANT))"; given='$(WT_ABS)'; \
	if [ -n "$$wt" ] && [ -n "$$given" ] && [ "$$wt" != "$$given" ]; then echo "stack d'épreuve occupée par $$wt, pas par $$given" >&2; exit 1; fi; \
	wt="$${wt:-$$given}"; base="$${wt:-$(STACK_ROOT)}"; [ -f "$$base/docker-compose.yml" ] || base='$(STACK_ROOT)'; \
	had_env=1; [ -f "$$base/.env" ] || { had_env=0; cp "$$base/.env.epreuve.dist" "$$base/.env"; }; \
	docker compose -p amateo-epreuve --env-file "$$base/.env" -f "$$base/docker-compose.yml" --profile dev down --remove-orphans --volumes; \
	[ "$$had_env" = 1 ] || rm -f "$$base/.env"; \
	if [ -n "$$wt" ]; then rm -f "$$wt/.env"; rm -f "$$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$$wt" rev-parse --absolute-git-dir 2>/dev/null)/amateo-epreuve-mounted"; fi

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_.@%-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
