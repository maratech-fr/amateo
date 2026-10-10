#!/usr/bin/env bash
# Lecture seule de la base de PRODUCTION depuis un poste d'exploitation.
#
# Trois barrières, de la plus dure à la plus molle (docs/ops/prod-stack.md
# § Accès opérateur, docs/security/rls.md) :
#   1. BASE   — le rôle `amateo_read` : liste blanche de SELECT, aucun DML,
#               aucun secret. C'est la vraie frontière.
#   2. SSH    — une clé dédiée (`amateo-prod-read`) restreinte côté VM à
#               `restrict,port-forwarding,permitopen="127.0.0.1:5432",
#               command="/bin/false"` : elle n'ouvre QUE le tunnel, ni shell
#               ni docker.
#   3. OUTIL  — ce script : il ne parle à la prod QUE par ce tunnel, en
#               `amateo_read`, en transaction read-only, et refuse par
#               courtoisie tout ce qui n'est pas SELECT/WITH/SET/SHOW/\d.
#
# AUCUN secret ne vit ici : le mot de passe de `amateo_read` est lu dans
# ~/.pgpass-amateo (chmod 600), jamais passé en argument. Aucun fichier de
# sortie n'est écrit : le résultat part sur stdout.
#
# Pré-requis (gestes fondateur, une fois — docs/ops/deploy.md §1.8) :
#   - la clé SSH dédiée posée dans authorized_keys de la VM (restreinte) ;
#   - l'entrée `Host amateo-prod-read` dans ~/.ssh/config ;
#   - ~/.pgpass-amateo (chmod 600) avec la ligne
#       127.0.0.1:15432:amateo:amateo_read:<mot de passe>
#
# Usage :
#   scripts/prod-read.sh [--club <uuid|nom>] [--csv] "<requête SQL>"
#   scripts/prod-read.sh [--club <uuid|nom>] [--csv] < requete.sql
#   echo "SELECT count(*) FROM club;" | scripts/prod-read.sh
#
# Exemples :
#   scripts/prod-read.sh "SELECT id, name FROM club ORDER BY name;"
#   scripts/prod-read.sh --club "BCCL" "SELECT count(*) FROM team;"
#   scripts/prod-read.sh --csv --club 550e8400-e29b-41d4-a716-446655440000 \
#       "SELECT name FROM team_tag;"
set -euo pipefail

readonly SSH_ALIAS="amateo-prod-read"
readonly LOCAL_PORT=15432
readonly PGPASS_HOST="${HOME}/.pgpass-amateo"
readonly PSQL_IMAGE="postgres:16-alpine"
readonly UUID_RE='^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'

usage() {
  sed -n '2,33p' "$0" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

die() {
  echo "prod-read: $*" >&2
  exit 1
}

club=""
csv=0
query=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --club)
      [[ $# -ge 2 ]] || die "--club exige une valeur (uuid ou nom de club)"
      club="$2"
      shift 2
      ;;
    --csv)
      csv=1
      shift
      ;;
    -h | --help)
      usage 0
      ;;
    --)
      shift
      break
      ;;
    -*)
      die "option inconnue : $1"
      ;;
    *)
      [[ -z "$query" ]] || die "requête déjà fournie (une seule attendue) : '$1'"
      query="$1"
      shift
      ;;
  esac
done

[[ -n "$query" ]] || query="$(cat)"

# ── Barrière 3 : garde de COURTOISIE (la vraie barrière reste la base) ──
# On ne regarde que le premier mot-clé : lecture seule par intention.
keyword="$(printf '%s' "$query" | sed -E 's/^[[:space:]]+//' | awk 'NR==1{print tolower($1)}')"
case "$keyword" in
  select | with | set | show) ;;
  '\d'*) ;; # \d, \d+, \dt, \dn, … : description de schéma, lecture seule
  *)
    die "refusé (garde de courtoisie) : seuls SELECT / WITH / SET / SHOW / \\d sont permis — reçu « ${keyword:-<vide>} ». La base refuse de toute façon toute écriture via amateo_read."
    ;;
esac

# ── Pré-requis locaux ──
command -v ssh >/dev/null 2>&1 || die "ssh introuvable"
command -v docker >/dev/null 2>&1 || die "docker introuvable"
[[ -f "$PGPASS_HOST" ]] || die "mot de passe absent : créer ${PGPASS_HOST} (chmod 600) — cf. docs/ops/deploy.md §1.8"
perms="$(stat -c '%a' "$PGPASS_HOST" 2>/dev/null || stat -f '%Lp' "$PGPASS_HOST")"
[[ "$perms" == "600" ]] || die "${PGPASS_HOST} doit être en chmod 600 (actuel : ${perms})"

# ── Barrière 2 : le tunnel SSH, via un socket de contrôle pour le refermer ──
control_dir="$(mktemp -d "${TMPDIR:-/tmp}/prod-read.XXXXXX")"
control_sock="${control_dir}/ctrl.sock"

cleanup() {
  if [[ -S "$control_sock" ]]; then
    ssh -S "$control_sock" -O exit "$SSH_ALIAS" >/dev/null 2>&1 || true
  fi
  rm -rf "$control_dir"
}
trap cleanup EXIT

ssh -fN -M -S "$control_sock" \
  -o ExitOnForwardFailure=yes \
  -L "${LOCAL_PORT}:127.0.0.1:5432" \
  "$SSH_ALIAS" \
  || die "tunnel SSH refusé — vérifier l'alias « ${SSH_ALIAS} » (~/.ssh/config) et la clé dédiée (docs/ops/deploy.md §1.8)"

# ── Préambule optionnel : poser le club (AIDE, pas une frontière — rls.md) ──
preamble=""
if [[ -n "$club" ]]; then
  if [[ "$club" =~ $UUID_RE ]]; then
    preamble="SELECT set_config('app.club_id', :'club_ref', false);"
  else
    # `club` est hors RLS (table sans club_id) → résoluble par nom quel que soit
    # le contexte ; une absence de correspondance laisse le contexte vide (0 ligne).
    preamble="SELECT set_config('app.club_id', (SELECT id::text FROM club WHERE name = :'club_ref'), false) AS club_id_pose;"
  fi
fi

# ── Barrière 1 : amateo_read + transaction read-only ──
psql_args=(
  "host=127.0.0.1 port=${LOCAL_PORT} dbname=amateo user=amateo_read sslmode=disable"
  -v ON_ERROR_STOP=1
)
[[ -n "$club" ]] && psql_args+=(-v "club_ref=${club}")
[[ "$csv" -eq 1 ]] && psql_args+=(--csv)
psql_args+=(-f -)

printf '%s\n%s\n' "$preamble" "$query" | docker run --rm -i --network host \
  -e PGPASSFILE=/root/.pgpass \
  -e "PGOPTIONS=-c default_transaction_read_only=on" \
  -v "${PGPASS_HOST}:/root/.pgpass:ro" \
  "$PSQL_IMAGE" \
  psql "${psql_args[@]}"
