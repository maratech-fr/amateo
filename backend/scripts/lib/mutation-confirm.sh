#!/usr/bin/env bash
# mutation-confirm.sh — garde-fou des CIBLES MAKE mutatrices (P4-145).
#
# Le garde fail-closed sandbox-guard.sh protège les SCRIPTS de l'IA (smoke/e2e/
# démo) : il MEURT sur toute cible ≠ amateo_dev/*_test. Mais les cibles Make
# destructrices (`make fixtures`, `make db-reset`, `make seed-bccl`) sont AUSSI
# les gestes LÉGITIMES du fondateur sur sa base de jeu (amateo_local) — un refus
# sec y serait faux. Il faut un TROISIÈME comportement que la garde n'offre pas :
# la CONFIRMATION. D'où cette lib séparée (on NE source PAS sandbox-guard.sh, qui
# tuerait le geste avant toute question).
#
# Usage (ligne de recette, cwd = backend/) :  scripts/lib/mutation-confirm.sh <label>
#   Un exit ≠ 0 avorte la recette Make (rien n'est muté).
#
# Résout la base RÉELLEMENT visée via `current_database()` sur la connexion
# applicative live — donc respecte toute la précédence dotenv (backend/.env.local
# du mode play compris), exactement comme sandbox-guard.sh. Puis :
#   - amateo_dev / *_test  → passe SILENCIEUSEMENT (cas nominal : bac à sable, tests).
#   - amateo (prod exacte)  → REFUS sec (jamais de mutation dev sur la prod).
#   - toute AUTRE base (amateo_local = base de jeu, ou nom inattendu) →
#     CONFIRMATION interactive nommant la base et ce qui va être détruit.
#
# Contournement non interactif (CI, automatisation, chemins non destructeurs
# comme `seed-bccl-if-absent`) : CONFIRM=yes. Il NE lève PAS le refus prod.

set -euo pipefail

LABEL="${1:-cette opération}"

RED=$'\033[0;31m'; YEL=$'\033[1;33m'; GRN=$'\033[0;32m'; NC=$'\033[0m'

COMPOSE="${MUTATION_CONFIRM_COMPOSE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/docker-compose.yml}"
APP_ENV_RESOLVE="${MUTATION_CONFIRM_APP_ENV:-dev}"

resolve_db() {
  docker compose -f "$COMPOSE" exec -T -e APP_ENV="$APP_ENV_RESOLVE" php-fpm \
    sh -c 'cd /app/backend && php bin/console dbal:run-sql "SELECT current_database() AS db"' 2>/dev/null \
    | sed -E 's/[[:space:]]//g' | grep -vE '^-*$' | grep -vxE 'db' | head -1
}

DB="$(resolve_db || true)"

if [[ -z "$DB" ]]; then
  printf '%sFAIL:%s mutation-confirm: base non résolue — la stack est-elle démarrée (make start) ? Refus par précaution.\n' "$RED" "$NC" >&2
  exit 1
fi

case "$DB" in
  amateo_dev|*_test)
    exit 0 ;; # bac à sable / test → nominal, aucune question
  amateo)
    printf '%sFAIL:%s mutation-confirm: « %s » vise la base de PRODUCTION (amateo) — refus sec.\n' "$RED" "$NC" "$LABEL" >&2
    exit 1 ;;
esac

# Toute autre base : geste potentiellement destructeur sur des données précieuses.
printf '%s⚠  « %s » va MUTER/PURGER la base « %s ».%s\n' "$YEL" "$LABEL" "$DB" "$NC" >&2
if [[ "$DB" == "amateo_local" ]]; then
  printf '   C\047est ta base de JEU du fondateur — son contenu sera détruit.\n' >&2
fi

if [[ "${CONFIRM:-}" == "yes" ]]; then
  printf '%s==>%s CONFIRM=yes — confirmation non interactive, on continue.\n' "$GRN" "$NC" >&2
  exit 0
fi

if [[ ! -t 0 ]]; then
  printf '%sFAIL:%s mutation-confirm: pas de terminal pour confirmer et CONFIRM=yes absent — refus (rien touché).\n' "$RED" "$NC" >&2
  exit 1
fi

printf '   Tape %s'\''oui'\''%s pour confirmer la destruction de « %s » : ' "$YEL" "$NC" "$DB" >&2
read -r answer
if [[ "$answer" == "oui" ]]; then
  exit 0
fi

printf 'Annulé — rien n\047a été touché.\n' >&2
exit 1
