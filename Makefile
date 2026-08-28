.DEFAULT_GOAL := help

DOCKER_COMPOSE := docker compose --env-file .env
# php-fpm and engine belong here: `make install` execs into both. nginx must NOT —
# its healthcheck curls /api/health, which needs vendor/ that install has yet to write.
INFRA_SERVICES := postgres redis mailpit mercure php-fpm engine

# The PHP containers run as ${USER_ID:-1000}. A shell export beats --env-file, so this
# covers .env files created before the rule below started writing the ids.
export USER_ID := $(shell id -u)
export GROUP_ID := $(shell id -g)

.env:
	cp .env.dist .env
	printf 'USER_ID=%s\nGROUP_ID=%s\n' "$$(id -u)" "$$(id -g)" >> .env

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
# gitignoré), la crée/migre et — SEULEMENT si le club de démo BCCL est ABSENT —
# la seede ; `make sandbox` retire la surcharge et revient à amateo_dev. Les
# workers long-lived sont redémarrés car ils tiennent la config DB en mémoire.
# ⚠ NON DESTRUCTEUR : `seed-bccl-if-absent` ne touche à rien si le club existe
# déjà — le travail du fondateur sur BCCL survit à un re-`make play`. Le geste
# de RESET explicite reste `make -C backend seed-bccl` (créer OU reset, voulu).
play: .env ## Bascule vers la base de JEU du fondateur (amateo_local) + seed BCCL si absent
	$(MAKE) -C backend play-env
	$(MAKE) -C backend db-init
	$(MAKE) -C backend seed-bccl-if-absent
	$(DOCKER_COMPOSE) restart messenger-worker cron-runner

sandbox: .env ## Revient au bac à sable de l'IA (amateo_dev) : retire backend/.env.local
	$(MAKE) -C backend sandbox-env
	$(DOCKER_COMPOSE) restart messenger-worker cron-runner

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

# Prod secrets rail — the filled .env.prod is NEVER committed; its GPG-encrypted
# copy .env.prod.gpg IS (repo = source of truth, decoded onto the VM by the
# deploy job — docs/ops/deploy.md). Symmetric AES256, one passphrase: founder's
# password manager + GitHub Actions secret ENV_GPG_PASSPHRASE.
# gpg runs on the HOST — assumed exception to "everything in Docker": it is
# standard on any Linux/WSL box and on ubuntu-latest, a container would add an
# image for a one-liner. Non-interactive when ENV_GPG_PASSPHRASE is set (CI),
# interactive pinentry otherwise. The passphrase travels over stdin only —
# never as a command-line argument (visible in `ps`) — and is never echoed.
env-encode@%: ## Encrypt .env.<env> into the committed .env.<env>.gpg (e.g. env-encode@prod)
	@test -f .env.$* || { echo "ERROR: .env.$* not found — nothing to encrypt (start from .env.$*.dist or make env-decode@$*)" >&2; exit 1; }
	@if [ -n "$$ENV_GPG_PASSPHRASE" ]; then \
		printf '%s' "$$ENV_GPG_PASSPHRASE" | gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 --symmetric --cipher-algo AES256 -o .env.$*.gpg .env.$*; \
	else \
		gpg --yes --symmetric --cipher-algo AES256 -o .env.$*.gpg .env.$*; \
	fi
	@echo ".env.$*.gpg written — commit it; .env.$* itself stays untracked"

env-decode@%: ## Decrypt .env.<env>.gpg into .env.<env> (overwrites — the .gpg is the truth)
	@test -f .env.$*.gpg || { echo "ERROR: .env.$*.gpg not found — nothing to decrypt" >&2; exit 1; }
	@if [ -n "$$ENV_GPG_PASSPHRASE" ]; then \
		printf '%s' "$$ENV_GPG_PASSPHRASE" | gpg --batch --yes --pinentry-mode loopback --passphrase-fd 0 -o .env.$* --decrypt .env.$*.gpg; \
	else \
		gpg --yes -o .env.$* --decrypt .env.$*.gpg; \
	fi
	@chmod 600 .env.$*
	@echo ".env.$* decrypted (chmod 600) — never commit it"

# Release helper — the normal path is `git tag vX.Y.Z && git push origin vX.Y.Z`
# (the tag push triggers .github/workflows/deploy.yml by itself). This target
# is the manual/hotfix path: refuses an out-of-sync HEAD, dispatches, then
# follows the run it just created (fails red if the run fails).
deploy: ## Deploy VERSION=vX.Y.Z (or origin/main HEAD if omitted) via the deploy workflow
	bash scripts/deploy.sh $(VERSION)

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_.@%-]+:.*?## / {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
