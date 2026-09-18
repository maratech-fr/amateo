#!/usr/bin/env bash
# migration-rollback-drill.sh — INF-05
#
# Répète, sur une base JETABLE, le cycle up → down → up d'une migration et VÉRIFIE
# que le rollback ramène le schéma à l'identique. C'est le filet qui manquait : une
# migration dont le `down()` est faux (ou oublié) ne se découvrait qu'en catastrophe,
# le jour d'un rollback de prod. Ce drill le prouve AVANT le merge.
#
# Déroulé :
#   1. crée une base TEMPORAIRE « amateo_migration_drill » (DROP IF EXISTS + CREATE) ;
#   2. `doctrine:migrations:migrate` jusqu'à la cible → `pg_dump --schema-only` (dump A) ;
#   3. `migrate prev` (rollback d'un cran) ;
#   4. `migrate` re-jusqu'à la cible → `pg_dump --schema-only` (dump B) ;
#   5. `diff A B` = le VERDICT : identique ⇒ rollback propre, différent ⇒ échec.
#   Un `trap EXIT INT TERM` DÉTRUIT la base jetable, quoi qu'il arrive.
#
# Pourquoi PAS via backend/scripts/with-sandbox.sh : ce wrapper suspend le mode play
# (bascule .env.local + redémarrages de workers) pour viser le bac à sable partagé.
# Ici c'est inutile ET indésirable : chaque commande console porte SA cible EXPLICITE
# (DATABASE_URL *et* DATABASE_ADMIN_URL — les migrations épinglent `connection: admin`
# dans doctrine.yaml), une base jetable créée puis détruite par ce script. Il ne touche
# donc JAMAIS amateo_local ni le bac à sable, et refuse par précaution toute URL qui
# viserait une base « local ». Le va-et-vient de with-sandbox n'ajouterait que du bruit.
#
# Pourquoi PAS en CI : il DROP/CREATE une base et pilote des migrations up/down — c'est
# une vérification MANUELLE, pré-merge, jouée par un humain sur la stack de dev, pas un
# gate automatisé. (La CI garde son propre `migrate` sur base neuve.)
#
# Usage :
#   backend/scripts/migration-rollback-drill.sh              # cible = latest
#   backend/scripts/migration-rollback-drill.sh --version 20260101120000
#
# Exit : 0 = rollback propre OU migration déclarée IRRÉVERSIBLE (cas légitime, pas un
#            échec) ; 1 = schéma divergent après up/down/up, ou toute autre erreur.

set -euo pipefail

DRILL_DB="amateo_migration_drill"
TARGET="latest"

usage() {
  sed -n '2,33p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --version)
      TARGET="${2:?--version exige un numéro de version}"
      shift 2
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    *)
      echo "argument inconnu : $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE=(docker compose -f "$REPO_ROOT/docker-compose.yml")
[[ -f "$REPO_ROOT/.env" ]] && COMPOSE=(docker compose --env-file "$REPO_ROOT/.env" -f "$REPO_ROOT/docker-compose.yml")

_die() {
  printf '\033[0;31mFAIL:\033[0m %s\n' "$1" >&2
  exit 1
}

# L'URL admin de RÉFÉRENCE vient de backend/.env — JAMAIS de .env.local (qui, en mode
# play, vise amateo_local : la base de jeu du fondateur, hors limites pour ce drill).
BASE_ADMIN_URL="$(awk -F= '$1=="DATABASE_ADMIN_URL"{sub(/^[^=]*=/,"");gsub(/"/,"");print}' "$REPO_ROOT/backend/.env" | tail -1)"
[[ -n "$BASE_ADMIN_URL" ]] || _die "DATABASE_ADMIN_URL introuvable dans backend/.env."

# Fail-closed : aucune URL visant une base « local » n'est tolérée (ni la source, ni la
# cible dérivée) — le drill ne doit jamais s'approcher d'amateo_local.
case "$BASE_ADMIN_URL" in
  *local*) _die "l'URL admin de backend/.env vise une base « local » — refus (le drill ne touche jamais amateo_local)." ;;
esac

# La cible du drill : la MÊME connexion (user/mot de passe/hôte/port), base jetable.
DRILL_URL="$(printf '%s' "$BASE_ADMIN_URL" | sed -E 's#/[^/?]+(\?|$)#/'"$DRILL_DB"'\1#')"
case "$DRILL_URL" in
  *local*) _die "l'URL de drill contient « local » — refus." ;;
esac

# Le propriétaire (pour psql/pg_dump DANS le conteneur postgres, auth locale trust).
DB_OWNER="$(printf '%s' "$BASE_ADMIN_URL" | sed -E 's#^[a-z]+://([^:@/]+):.*#\1#')"
[[ -n "$DB_OWNER" ]] || _die "propriétaire de base non résolu depuis l'URL admin."

psql_postgres() { "${COMPOSE[@]}" exec -T postgres psql -v ON_ERROR_STOP=1 -U "$DB_OWNER" -d postgres "$@"; }
pg_dump_drill() { "${COMPOSE[@]}" exec -T postgres pg_dump --schema-only -U "$DB_OWNER" "$DRILL_DB"; }
# Chaque commande console porte SA cible explicite (DATABASE_URL + DATABASE_ADMIN_URL).
console() { "${COMPOSE[@]}" exec -T -e APP_ENV=dev -e DATABASE_URL="$DRILL_URL" -e DATABASE_ADMIN_URL="$DRILL_URL" php-fpm php bin/console "$@"; }

DUMP_A=""
DUMP_B=""
cleanup() {
  psql_postgres -c "DROP DATABASE IF EXISTS \"$DRILL_DB\";" >/dev/null 2>&1 || true
  [[ -n "$DUMP_A" ]] && rm -f "$DUMP_A"
  [[ -n "$DUMP_B" ]] && rm -f "$DUMP_B"
}
trap cleanup EXIT INT TERM

echo "==> Base jetable « $DRILL_DB » (propriétaire $DB_OWNER)."
psql_postgres -c "DROP DATABASE IF EXISTS \"$DRILL_DB\";" >/dev/null
psql_postgres -c "CREATE DATABASE \"$DRILL_DB\" OWNER \"$DB_OWNER\";" >/dev/null

echo "==> 1/4 migrate → $TARGET"
console doctrine:migrations:migrate "$TARGET" -n

DUMP_A="$(mktemp "${TMPDIR:-/tmp}/drill.A.XXXXXX.sql")"
pg_dump_drill >"$DUMP_A"

echo "==> 2/4 migrate prev (rollback d'un cran)"
set +e
PREV_OUT="$(console doctrine:migrations:migrate prev -n 2>&1)"
PREV_RC=$?
set -e
printf '%s\n' "$PREV_OUT"

if [[ $PREV_RC -ne 0 ]]; then
  # Un down() qui lève IrreversibleMigration est un choix ASSUMÉ (pas un bug) : le drill
  # ne s'applique pas, ce n'est pas un échec.
  if printf '%s' "$PREV_OUT" | grep -qiE 'irreversible'; then
    echo "==> VERDICT : migration IRRÉVERSIBLE (down() refuse le rollback) — drill non applicable, ce n'est PAS un échec."
    exit 0
  fi
  _die "le rollback (migrate prev) a échoué pour une autre raison — voir la sortie ci-dessus."
fi

echo "==> 3/4 migrate → $TARGET (ré-application)"
console doctrine:migrations:migrate "$TARGET" -n

DUMP_B="$(mktemp "${TMPDIR:-/tmp}/drill.B.XXXXXX.sql")"
pg_dump_drill >"$DUMP_B"

echo "==> 4/4 diff des schémas (avant / après up→down→up)"
if diff -u "$DUMP_A" "$DUMP_B"; then
  echo "==> VERDICT : rollback+ré-application PROPRE — le schéma revient à l'identique."
  exit 0
fi

_die "le schéma après up→down→up DIFFÈRE (diff ci-dessus) — le down() de la migration est incomplet."
