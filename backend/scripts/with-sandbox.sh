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
#   ex.  backend/scripts/with-sandbox.sh ./scripts/smoke-solver.sh
#
# Garanties :
#   - Si le mode play était actif (backend/.env.local présent), il est suspendu
#     pour la commande puis rétabli à la sortie. Le .env.local ORIGINAL est
#     sauvegardé et restauré À L'IDENTIQUE (jamais régénéré depuis le template :
#     le fondateur a pu l'éditer).
#   - Les workers long-lived (messenger-worker, cron-runner) sont redémarrés aux
#     deux bascules — ils tiennent la config DB en mémoire.
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

"$@"
rc=$?
exit "$rc"
