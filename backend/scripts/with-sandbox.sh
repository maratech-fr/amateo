#!/usr/bin/env bash
# with-sandbox.sh — wrapper OPT-IN (P4-141 addendum).
#
# Exécute une commande dev mutatrice dans le bac à sable de l'IA (amateo_dev)
# MÊME quand la stack est en mode play (base de JEU du fondateur, amateo_local),
# puis RESTAURE le mode play à la sortie — succès, échec, ou Ctrl-C.
#
# Sans ce wrapper, un script mutateur lancé en mode play MEURT toujours sur la
# garde (backend/scripts/lib/sandbox-guard.sh) : le fail-closed reste le DÉFAUT.
# Ce wrapper est l'échappatoire DÉLIBÉRÉE et EXPLICITE, jamais le comportement
# par défaut — c'est le fait de l'invoquer qui vaut opt-in.
#
# Usage :  backend/scripts/with-sandbox.sh <commande…>
#   ex.  backend/scripts/with-sandbox.sh make -C backend behat
#
# Garanties :
#   - Si le mode play était actif (backend/.env.local présent), il est suspendu
#     pour la commande puis rétabli à la sortie. Le .env.local ORIGINAL est
#     sauvegardé et restauré À L'IDENTIQUE (jamais régénéré depuis le template :
#     le fondateur a pu l'éditer).
#   - Les workers long-lived (messenger-worker, cron-runner) sont redémarrés aux
#     deux bascules — ils tiennent la config DB en mémoire.
#   - Le schéma du bac à sable (amateo_dev) est mis à niveau AVANT la commande si
#     des migrations manquent (INF-06), bruyamment — plus de Behat rouge inexpliqué
#     sur un sandbox en retard. Une seule vérification, jamais de boucle d'attente.
#     Durcissement INF-06 : avant tout create/migrate, la base RÉELLEMENT visée est
#     prouvée == amateo_dev (via current_database()) ; sinon ABANDON bruyant et rien
#     n'est écrit — parade au bind-mount WSL figé qui sert encore .env.local (play).
#   - La restauration passe par un trap sur EXIT INT TERM : une commande qui
#     échoue ou est interrompue laisse quand même le fondateur en mode play.
#   - Ce wrapper NE SOURCE PAS la garde (elle le tuerait avant qu'il puisse
#     basculer) ; la commande wrappée la source comme d'habitude et, pointée sur
#     amateo_dev, passe.

set -uo pipefail

if [[ $# -eq 0 ]]; then
  echo "usage: with-sandbox.sh <commande…>" >&2
  exit 2
fi

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ENV_LOCAL="$REPO_ROOT/backend/.env.local"
BACKUP=""
_restored=0

compose() {
  local args=(-f "$REPO_ROOT/docker-compose.yml")
  [[ -f "$REPO_ROOT/.env" ]] && args=(--env-file "$REPO_ROOT/.env" "${args[@]}")
  docker compose "${args[@]}" "$@"
}

restart_workers() {
  compose restart messenger-worker cron-runner >/dev/null 2>&1 || true
}

dev_console() {
  compose exec -T -e APP_ENV=dev php-fpm php bin/console "$@"
}

# Résolution de la base RÉELLEMENT visée par l'app (via `current_database()`, donc honorant toute
# la précédence dotenv Symfony, .env.local compris) — même idiome que sandbox-guard.sh. Vide si la
# stack est à l'arrêt ou la résolution échoue.
resolve_target_db() {
  dev_console dbal:run-sql "SELECT current_database() AS db" 2>/dev/null \
    | sed -E 's/[[:space:]]//g' | grep -vE '^-*$' | grep -vxE 'db' | head -1
}

# INF-06 (durcissement) — on NE crée/migre JAMAIS sans avoir d'abord prouvé que la base visée est
# bien le bac à sable amateo_dev. Le piège : sous WSL, un bind-mount figé peut continuer à servir
# backend/.env.local (mode play) À L'INTÉRIEUR du conteneur même après qu'on l'a retiré côté hôte —
# la migration partirait alors sur amateo_local, la base de JEU du fondateur. Toute autre cible =
# ABANDON bruyant (exit non nul ; le trap restore rétablit le mode play). Aucune boucle d'attente.
assert_target_is_sandbox() {
  local db
  db="$(resolve_target_db || true)"
  if [[ -z "$db" ]]; then
    echo "==> with-sandbox: ABANDON — base visée non résolue (stack à l'arrêt ? make start). Aucune migration lancée." >&2
    exit 1
  fi
  if [[ "$db" != "amateo_dev" ]]; then
    echo "==> with-sandbox: ABANDON — la base visée est « $db », PAS amateo_dev. Aucune création ni migration lancée." >&2
    echo "    Cause probable : bind-mount WSL figé servant encore backend/.env.local (mode play) dans le conteneur." >&2
    echo "    Rien n'a été écrit. Redémarre la stack (make stop && make start) pour purger le montage, puis relance." >&2
    exit 1
  fi
}

# INF-06 — le bac à sable (amateo_dev) est JETABLE mais SURVIT entre les runs : après un pull
# qui ajoute des migrations, un Behat lancé dessus échouait en masse sans rien dire (schéma en
# retard). On remet le bac à sable à niveau AVANT la commande, bruyamment, en nommant ce qui est
# fait — équivalent `make -C backend db-init` (create --if-not-exists + migrate). Aucune boucle
# d'attente : une seule vérification, puis la migration si besoin (garde-fou boucles bornées).
ensure_sandbox_migrated() {
  echo "==> with-sandbox: vérification du schéma du bac à sable (amateo_dev)…" >&2
  # Garde-fou INF-06 : prouver la cible AVANT tout create/migrate (bind-mount WSL figé).
  assert_target_is_sandbox
  # `up-to-date` sort 0 si à jour, non-zéro sinon (y compris base absente → on crée puis migre).
  if dev_console doctrine:migrations:up-to-date --no-interaction >/dev/null 2>&1; then
    echo "==> with-sandbox: bac à sable à jour, aucune migration à appliquer." >&2
    return 0
  fi
  echo "==> with-sandbox: bac à sable EN RETARD — création si besoin + migration (make -C backend db-init)…" >&2
  dev_console doctrine:database:create --if-not-exists --connection admin >&2 || true
  dev_console doctrine:migrations:migrate --no-interaction >&2
  echo "==> with-sandbox: schéma du bac à sable mis à niveau." >&2
}

restore() {
  [[ "$_restored" == "1" ]] && return 0
  _restored=1
  if [[ -n "$BACKUP" && -f "$BACKUP" ]]; then
    mv -f "$BACKUP" "$ENV_LOCAL"
    restart_workers
    echo "==> with-sandbox: mode play RESTAURÉ (backend/.env.local remis à l'identique)." >&2
  fi
}

if [[ -f "$ENV_LOCAL" ]]; then
  BACKUP="$(mktemp "${TMPDIR:-/tmp}/env.local.play.XXXXXX")"
  cp -p "$ENV_LOCAL" "$BACKUP"
  trap restore EXIT INT TERM
  rm -f "$ENV_LOCAL"
  restart_workers
  echo "==> with-sandbox: mode play suspendu — bascule vers le bac à sable amateo_dev pour la commande." >&2
else
  echo "==> with-sandbox: déjà en bac à sable (aucun backend/.env.local) — exécution directe." >&2
fi

# Pointé sur amateo_dev dans les deux cas — on garantit son schéma avant la commande.
ensure_sandbox_migrated

"$@"
rc=$?
exit "$rc"
