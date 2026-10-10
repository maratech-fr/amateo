#!/usr/bin/env bash
# Lecture seule de la base de PRODUCTION depuis un poste d'exploitation.
#
# Trois barrières, de la plus dure à la plus molle (docs/ops/prod-stack.md
# § Accès opérateur, docs/security/rls.md) :
#   1. BASE   — le rôle `amateo_read` : liste blanche de SELECT, aucun DML,
#               aucun secret. C'est la vraie frontière.
#   2. SSH    — un compte Unix dédié `amateo-tunnel` (sans shell, HORS groupe
#               docker) et un bloc sshd `Match User amateo-tunnel`
#               (`AllowTcpForwarding local`, `AllowStreamLocalForwarding no`,
#               `PermitOpen 127.0.0.1:5432`, `PermitListen none`, `ForceCommand
#               /bin/false`) : la clé `amateo-prod-read` n'ouvre QUE ce tunnel —
#               ni shell, ni docker, ni forward de socket Unix vers docker.sock
#               (docs/ops/deploy.md §1.8).
#   3. OUTIL  — ce script : il ne parle à la prod QUE par ce tunnel, en
#               `amateo_read`, en transaction read-only, et refuse par
#               courtoisie tout ce qui n'est pas SELECT/WITH/SET/SHOW/\d.
#
# AUCUN secret ne vit ici : le mot de passe de `amateo_read` est lu dans
# ~/.pgpass-amateo (chmod 600), jamais passé en argument. Aucun fichier de
# sortie n'est écrit : le résultat part sur stdout.
#
# Pré-requis (gestes fondateur, une fois — docs/ops/deploy.md §1.8) :
#   - le compte Unix `amateo-tunnel` + le bloc sshd `Match User amateo-tunnel`
#     sur la VM, et la clé `amateo-prod-read` (À PHRASE DE PASSE) dans le SEUL
#     authorized_keys de ce compte ;
#   - l'entrée `Host amateo-prod-read` dans ~/.ssh/config (User amateo-tunnel,
#     IdentityAgent ~/.ssh/agent-prodread.sock) ;
#   - ~/.pgpass-amateo (chmod 600) avec la ligne
#       127.0.0.1:15432:amateo:amateo_read:<mot de passe>
# Avant chaque enquête, le fondateur DÉVERROUILLE la clé dans l'agent DÉDIÉ, pour
# une durée limitée :
#   SSH_AUTH_SOCK=~/.ssh/agent-prodread.sock ssh-add -t 1h ~/.ssh/amateo_prod_read
# Clé verrouillée (ou absente de cet agent) = ce script échoue net, aucune
# session n'atteint la prod.
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
readonly SSH_KEY="${HOME}/.ssh/amateo_prod_read"
readonly SSH_AGENT_SOCK="${HOME}/.ssh/agent-prodread.sock"
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

# ── Barrière 3 : garde de COURTOISIE — PAS une barrière de sécurité ──
# On ne regarde QUE le premier mot-clé : ça arrête une faute de frappe (un UPDATE
# collé par mégarde), pas un acte hostile — « SELECT … ; UPDATE … » passerait
# cette garde. La vraie frontière est la base : amateo_read n'a aucun droit d'écriture.
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

# ── Interrupteur : la clé dédiée (à phrase de passe) doit être DÉVERROUILLÉE ──
# dans l'agent DÉDIÉ (socket fixe — jamais l'agent ambiant : lui seul doit porter
# cette clé). Clé absente de cet agent = aucune session ne peut atteindre la prod.
[[ -S "$SSH_AGENT_SOCK" ]] || die "agent SSH dédié absent (${SSH_AGENT_SOCK}) : le démarrer puis \`SSH_AUTH_SOCK=${SSH_AGENT_SOCK} ssh-add -t 1h ~/.ssh/amateo_prod_read\` — cf. docs/ops/deploy.md §1.8"
[[ -f "${SSH_KEY}.pub" ]] || die "clé publique absente : ${SSH_KEY}.pub — cf. docs/ops/deploy.md §1.8"
key_fpr="$(ssh-keygen -lf "${SSH_KEY}.pub" | awk '{print $2}')"
if ! SSH_AUTH_SOCK="$SSH_AGENT_SOCK" ssh-add -l 2>/dev/null | awk '{print $2}' | grep -qxF "$key_fpr"; then
  die "clé amateo-prod-read verrouillée dans l'agent dédié : demander au fondateur \`SSH_AUTH_SOCK=${SSH_AGENT_SOCK} ssh-add -t 1h ~/.ssh/amateo_prod_read\`"
fi

# ── S'assurer de l'image psql AVANT d'ouvrir le tunnel ──
# Un premier `docker pull` peut durer plus que `UnusedConnectionTimeout 1m` côté VM
# et couperait le tunnel pendant le téléchargement — on le fait donc en amont.
docker image inspect "$PSQL_IMAGE" >/dev/null 2>&1 || docker pull "$PSQL_IMAGE"

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
  -o BatchMode=yes \
  -o ExitOnForwardFailure=yes \
  -o ServerAliveInterval=30 \
  -o ServerAliveCountMax=2 \
  -L "${LOCAL_PORT}:127.0.0.1:5432" \
  "$SSH_ALIAS" \
  || die "tunnel SSH refusé — vérifier l'alias « ${SSH_ALIAS} » (~/.ssh/config), que la clé est déverrouillée (\`ssh-add -t 1h ~/.ssh/amateo_prod_read\`) et autorisée sur la VM (docs/ops/deploy.md §1.8)"

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

# `timeout` borne la phase psql (le tunnel ne survit pas à une session qui traîne) ;
# les `statement_timeout`/`lock_timeout` de PGOPTIONS doublent côté serveur les
# réglages portés par le rôle amateo_read (migration) — défense en profondeur.
printf '%s\n%s\n' "$preamble" "$query" | timeout 600 docker run --rm -i --network host \
  -e PGPASSFILE=/root/.pgpass \
  -e "PGOPTIONS=-c default_transaction_read_only=on -c statement_timeout=60s -c lock_timeout=5s" \
  -v "${PGPASS_HOST}:/root/.pgpass:ro" \
  "$PSQL_IMAGE" \
  psql "${psql_args[@]}"
