.DEFAULT_GOAL := help

FIG := docker compose -f ../docker-compose.yml
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
lint: install ## Run ESLint + TypeScript checks in Docker
	$(FIG) --profile tools run --rm --no-deps frontend-tooling sh -lc 'npm run lint && npx tsc -b --force'

test: ## Run frontend checks and tests in Docker
	$(MAKE) lint
	$(FIG) --profile tools run --rm --no-deps frontend-tooling npm run test -- --maxWorkers=2

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
e2e: $(ROOT_ENV) ## Run Playwright e2e tests fully inside Docker (stack + `make dev` required)
	$(FIG) up -d --wait
	# Fail-closed sandbox guard (P4-141) — the e2e suite mutates the live dev DB
	# (superadmin reset + fixtures + clubs). Refuse to run against anything but the
	# AI sandbox (amateo_dev) or a *_test DB, so `make play` mode (amateo_local) is
	# never wiped. Exit 1 aborts the target before any mutation.
	@bash ../backend/scripts/lib/sandbox-guard.sh || exit 1
	# Un volume nommé neuf naît possédé par root : la pré-étape le rend à l'UID
	# hôte, sans quoi npm ci (exécuté en $${USER_ID:-1000}) refuse d'écrire.
	$(FIG) --profile tools run --rm --no-deps --user root e2e sh -lc 'chown -R $${USER_ID:-1000}:$${GROUP_ID:-1000} /app/frontend/node_modules'
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
	@printf '$(E2E_SA_PASSWORD)\n$(E2E_SA_PASSWORD)\n' | $(FIG) exec -T php-fpm \
		php bin/console app:superadmin:create $(E2E_SA_EMAIL) > /tmp/amateo-e2e-sa.txt
	$(FIG) --profile tools run --rm --no-deps \
		-e E2E_SUPERADMIN_EMAIL='$(E2E_SA_EMAIL)' \
		-e E2E_SUPERADMIN_PASSWORD='$(E2E_SA_PASSWORD)' \
		-e E2E_SUPERADMIN_TOTP_SECRET="$$(sed -n 's/.*setup key: *//p' /tmp/amateo-e2e-sa.txt | tr -d '\r\n')" \
		e2e sh -lc 'PUPPETEER_SKIP_DOWNLOAD=1 npm ci --legacy-peer-deps && npx playwright test'
	@rm -f /tmp/amateo-e2e-sa.txt

help: ## Display this help
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
