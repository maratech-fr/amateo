#!/usr/bin/env bash
# Runs ON the production VM (uploaded by .github/workflows/deploy.yml, executed
# as a FILE — never piped into `bash -s`: `docker compose run` keeps stdin
# attached and would eat the rest of a piped script, turning a half-run deploy
# green). Every compose run/exec below still takes </dev/null as belt-and-braces.
#
# Fail-closed sequence (existing install):
#   1. docker daemon reachable, else abort (so a daemon error can never be
#      mistaken for a first install)
#   2. postgres DATA VOLUME exists → the pre-migration dump is MANDATORY:
#      run --rm --no-deps on the CURRENT images; failure aborts, prod untouched
#   3. pull the new images (VERSION as process env; .env.prod not touched)
#   4. migrate via the NEW php image, --no-deps (the OLD postgres/redis keep
#      running untouched — without --no-deps compose would recreate them on
#      the new images BEFORE the migration proved itself)
#   5. up -d --wait + health probes
#   6. only then pin VERSION into .env.prod
#
# First install (no postgres data volume): nothing to protect — pull,
# up -d --wait (init scripts create the cluster), then migrate via exec.
#
# Convention: migrations are backward-compatible one release back
# (docs/ops/deploy.md) — old code runs on the new schema for the seconds
# between migrate and the roll.
set -euo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH not set}"
: "${VERSION:?VERSION not set}"
# Defense in depth: the workflow already regex-validates VERSION.
[[ "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || { echo "FATAL: invalid VERSION '$VERSION'" >&2; exit 1; }

cd "$DEPLOY_PATH"
export VERSION  # process env beats --env-file in compose interpolation
COMPOSE=(docker compose -f docker-compose.prod.yml --env-file .env.prod)

# Any failure past the pull may leave new containers running while .env.prod
# still pins the previous version — say it loudly instead of dying silent.
trap 'echo "FATAL: deploy ${VERSION} FAILED (line $LINENO). La stack peut tourner partiellement en ${VERSION} alors que .env.prod pinne encore l ancienne version — relancer ce deploy, ou redeployer le tag precedent (docs/ops/deploy.md, rollback)." >&2' ERR

echo "==> Deploying $VERSION to $DEPLOY_PATH"

# 1. Daemon reachable — a docker error must abort, never masquerade as a
# first install.
docker info >/dev/null

# 2. First-install detection on the DATA VOLUME (not on containers: a routine
# `compose down` removes containers but keeps volumes — the data still needs
# its pre-migration dump). The project name comes from compose ITSELF
# (`config` prints the normalized `name:`), never re-derived by hand: a dot
# in the directory name or a COMPOSE_PROJECT_NAME in .env.prod would
# otherwise make a live install look like a first install and skip the dump.
PROJECT_NAME=$("${COMPOSE[@]}" config 2>/dev/null | grep -m1 '^name:' | awk '{print $2}')
: "${PROJECT_NAME:?FATAL: could not resolve the compose project name}"
if docker volume inspect "${PROJECT_NAME}_postgres_data" >/dev/null 2>&1; then
  FIRST_INSTALL=0
else
  FIRST_INSTALL=1
fi

if [ "$FIRST_INSTALL" = "0" ]; then
  # The dump's dependencies must be UP even after a maintenance `compose
  # down`/crash — started on the CURRENT images (env -u VERSION), a no-op
  # when they are already running (same config hash).
  echo "==> Ensuring backup dependencies are up (current images)"
  env -u VERSION docker compose -f docker-compose.prod.yml --env-file .env.prod \
    up -d --wait postgres redis mercure </dev/null

  echo "==> Pre-migration backup (mandatory — failure aborts the deploy)"
  # env -u VERSION: dump with the CURRENT images (.env.prod's pinned version),
  # the new ones aren't pulled yet. --no-deps: never touch running services.
  env -u VERSION docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm --no-deps --no-TTY php-fpm \
    php bin/console app:db:backup --force </dev/null

  echo "==> Pulling images ($VERSION)"
  "${COMPOSE[@]}" pull --quiet </dev/null

  echo "==> Running migrations (new image, --no-deps: old stack untouched on failure)"
  "${COMPOSE[@]}" run --rm --no-deps --no-TTY php-fpm \
    php bin/console doctrine:migrations:migrate --no-interaction </dev/null

  echo "==> Recreating services"
  "${COMPOSE[@]}" up -d --wait </dev/null
else
  echo "==> First install (no ${PROJECT_NAME}_postgres_data volume) — bootstrap order"
  echo "==> Pulling images ($VERSION)"
  "${COMPOSE[@]}" pull --quiet </dev/null
  # Infra first, migrate BEFORE the full stack: cron-runner's healthcheck
  # needs a successful app:jobs:run-due tick, which needs the migrated
  # schema — a whole-stack `up --wait` on an empty DB would flap cron-runner
  # unhealthy and abort the very first deploy.
  echo "==> Starting infra (postgres init scripts run here)"
  "${COMPOSE[@]}" up -d --wait postgres redis mercure </dev/null
  echo "==> Running migrations (fresh schema)"
  "${COMPOSE[@]}" run --rm --no-deps --no-TTY php-fpm \
    php bin/console doctrine:migrations:migrate --no-interaction </dev/null
  echo "==> Starting the full stack"
  "${COMPOSE[@]}" up -d --wait </dev/null
fi

# 5. Health through the edge. Accept optional quotes around the value.
FRONTEND_PORT=$(grep -oP "^FRONTEND_PORT=[\"']?\K[0-9]+" .env.prod | head -1 || true)
FRONTEND_PORT=${FRONTEND_PORT:-8081}
echo "==> Health probe (:${FRONTEND_PORT})"
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/health" >/dev/null
curl -fsS "http://127.0.0.1:${FRONTEND_PORT}/api/health" >/dev/null

# 6. Persist the version only now — a later manual `up -d` stays on it.
if grep -q '^VERSION=' .env.prod; then
  sed -i "s|^VERSION=.*|VERSION=${VERSION}|" .env.prod
else
  # A hand-edited .env.prod may lack a trailing newline — never glue
  # VERSION onto the last line.
  [ -n "$(tail -c1 .env.prod)" ] && echo >> .env.prod
  printf 'VERSION=%s\n' "$VERSION" >> .env.prod
fi

echo "==> Deploy $VERSION OK"

# Nettoyage des images Docker inutilisées — SEULEMENT ici, après les sondes de
# santé et la version persistée (deploy RÉUSSI). Sinon chaque release empile ses
# 6 images et sature le disque de la VM (42 images → 9 Go récupérés à la main une
# fois). `-a` : toute image qu'AUCUN conteneur n'utilise ; `until=168h` : et âgée
# de plus de 7 jours. Les images des conteneurs EN PLACE et les volumes de données
# ne sont JAMAIS touchés. Un rollback ne dépend pas des images locales — il les
# re-pull depuis ghcr (docs/ops/deploy.md, « Revenir en arrière ») ; garder 7 j
# rend juste un rollback récent plus rapide. ⚠ set -e + trap ERR : un échec du
# prune ne DOIT PAS faire échouer un deploy déjà réussi → `|| echo …` neutralise.
# `tail -1` : ne garder que « Total reclaimed space: … » (pipefail préserve un
# échec du prune, que le `||` rattrape alors).
docker image prune -af --filter "until=168h" | tail -1 \
  || echo "==> ⚠ nettoyage des images échoué (sans effet sur le déploiement)" >&2

# Anti-oubli : si l'interrupteur de MAINTENANCE (docs/ops/Caddyfile.example) est
# encore armé, le dire fort. C'est un RAPPEL, pas une garantie — la vraie garantie
# qu'une fenêtre de maintenance n'a pas été oubliée, c'est le 503 qu'une sonde voit.
#   · PAS d'auto-suppression : une fenêtre peut délibérément durer plus qu'un deploy ;
#   · PAS d'exit non-zéro : le deploy, lui, a bel et bien réussi.
if [ -e "$DEPLOY_PATH/maintenance.on" ]; then
  echo "==> ⚠ RAPPEL : le témoin de maintenance ($DEPLOY_PATH/maintenance.on) est TOUJOURS présent." >&2
  echo "==>   Le site app. répond 503 « maintenance ». Retirer le témoin pour rouvrir : rm '$DEPLOY_PATH/maintenance.on'" >&2
fi
