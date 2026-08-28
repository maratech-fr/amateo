#!/usr/bin/env bash
set -euo pipefail

API_BASE="http://localhost:8080/api"
CLUB_ID="77e1e118-e702-4839-8a9c-7c34187541e6"
# P3-4/SEC : plus jamais de jeton en dur dans un script versionné (Gitleaks l'a
# épinglé dans l'historique). Fournir SCHEDULER_TOKEN, ou SCHEDULER_EMAIL +
# SCHEDULER_PASSWORD pour un login à la volée.
TOKEN="${SCHEDULER_TOKEN:-}"
if [[ -z "$TOKEN" && -n "${SCHEDULER_EMAIL:-}" ]]; then
  # SEC-16 (audit) : /api/login rend 204 et pose le JWT en cookie httpOnly — le
  # corps ne le porte plus. Un script n'est pas un navigateur : on le lit dans
  # Set-Cookie et on continue en Bearer (extracteur resté actif pour l'outillage).
  TOKEN=$(curl -si -X POST "$API_BASE/login" -H 'Content-Type: application/json'     -d "{\"email\":\"$SCHEDULER_EMAIL\",\"password\":\"$SCHEDULER_PASSWORD\"}"     | grep -oiP 'set-cookie: *BEARER=\K[^;]+' | head -1)
fi
SCHEDULE_ID=""
CLUB_ID_ARG=""
POLL_INTERVAL=5
TIMEOUT_SECONDS="${TIMEOUT_SECONDS:-650}"
# Watchdog: how long the schedule may stay PENDING (never reaching GENERATING)
# before we bail with a targeted diagnostic. A stuck PENDING almost always means
# the messenger-worker is not consuming the queue (dead / crash-looping on a
# wiped Redis consumer group), NOT a slow solver — so it deserves a fast, loud
# failure instead of waiting out the full TIMEOUT_SECONDS in silence.
PENDING_TIMEOUT_SECONDS="${PENDING_TIMEOUT_SECONDS:-90}"

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[1;33m'
BLUE=$'\033[0;34m'
NC=$'\033[0m'

SCHEDULE_NAME="Planning Test $(date +%Y-%m-%d_%H:%M:%S)"

usage() {
  cat <<EOF
Usage: $(basename "$0") [OPTIONS]

Options:
  --schedule-id ID   Utilise un schedule existant (skip la création)
  --club-id ID       Crée un nouveau schedule pour ce club
  --name NAME        Nom du schedule (avec --club-id uniquement)
  --token TOKEN      JWT Bearer token (surcharge la variable TOKEN hardcodée)
  --help, -h         Show this help

Exemples:
  $(basename "$0") --schedule-id a1b2c3d4-e5f6-7890-abcd-ef1234567890
  $(basename "$0") --club-id 11111111-1111-1111-1111-111111111111 --name "Planning 2025-26"
EOF
}

die() {
  printf '%bErreur:%b %s\n' "$RED" "$NC" "$1" >&2
  exit 1
}

info() {
  printf '%b%s%b\n' "$GREEN" "$1" "$NC"
}

warn() {
  printf '%b%s%b\n' "$YELLOW" "$1" "$NC"
}

http_request() {
  local method="$1"
  local url="$2"
  local data="${3-}"
  local extra_headers="${4-}"
  local body_file err_file
  body_file=$(mktemp)
  err_file=$(mktemp)

  local curl_opts=(-sS -o "$body_file" -w '%{http_code}' -X "$method")
  if [[ -n "${TOKEN-}" ]]; then
    curl_opts+=(-H "Authorization: Bearer $TOKEN")
  fi
  if [[ -n "${extra_headers-}" ]]; then
    while IFS= read -r h; do
      [[ -n "$h" ]] && curl_opts+=(-H "$h")
    done <<<"$extra_headers"
  fi
  if [[ -n "${data-}" ]]; then
    curl_opts+=(-H 'Content-Type: application/json' --data "$data")
  fi

  if ! HTTP_STATUS=$(curl "${curl_opts[@]}" "$url" 2>"$err_file"); then
    local err_msg
    err_msg=$(<"$err_file")
    rm -f "$body_file" "$err_file"
    die "Backend unreachable while calling $method $url: ${err_msg:-curl failed}"
  fi

  HTTP_BODY=$(<"$body_file")
  rm -f "$body_file" "$err_file"
}

extract_id() {
  python3 -c '
import json
import sys

data = json.load(sys.stdin)
if not isinstance(data, dict):
    raise SystemExit(1)

value = data.get("id")
if value in (None, "") and "@id" in data:
    value = str(data["@id"]).rstrip("/").split("/")[-1]

if value in (None, ""):
    raise SystemExit(1)

print(value)
'
}

extract_field_from_json() {
  local json="$1"
  local field="$2"
  python3 -c '
import json
import sys

field = sys.argv[1]
data = json.load(sys.stdin)
if not isinstance(data, dict):
    raise SystemExit(1)

value = data.get(field)
if value is None:
    raise SystemExit(1)

if isinstance(value, dict):
    for key in ("name", "label", "title", "value", "code", "id", "@id"):
        nested = value.get(key)
        if nested not in (None, ""):
            value = nested
            break

if value is None:
    raise SystemExit(1)

print(value)
' "$field" <<<"$json"
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --schedule-id)
      [[ $# -ge 2 ]] || die "--schedule-id requires a value"
      SCHEDULE_ID="$2"
      shift 2
      ;;
    --schedule-id=*)
      SCHEDULE_ID="${1#*=}"
      shift
      ;;
    --club-id)
      [[ $# -ge 2 ]] || die "--club-id requires a value"
      CLUB_ID_ARG="$2"
      shift 2
      ;;
    --club-id=*)
      CLUB_ID_ARG="${1#*=}"
      shift
      ;;
    --name)
      [[ $# -ge 2 ]] || die "--name requires a value"
      SCHEDULE_NAME="$2"
      shift 2
      ;;
    --name=*)
      SCHEDULE_NAME="${1#*=}"
      shift
      ;;
    --token)
      [[ $# -ge 2 ]] || die "--token requires a value"
      TOKEN="$2"
      shift 2
      ;;
    --token=*)
      TOKEN="${1#*=}"
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      die "Unknown option: $1"
      ;;
  esac
done

# La garde vit APRÈS le parsing : --token (smoke-solver) surcharge TOKEN.
if [[ -z "$TOKEN" ]]; then
  echo "Token requis : --token, SCHEDULER_TOKEN, ou SCHEDULER_EMAIL/SCHEDULER_PASSWORD." >&2
  exit 1
fi

# Fail-closed sandbox guard (P4-141): creating + generating a schedule mutates
# the app's database — refuse anything but the AI sandbox (amateo_dev) or *_test.
# Placed after --help/token handling so a bare `--help` never needs the stack.
# (Under generate-schedule-test.sh the guard is a no-op via SANDBOX_GUARD_SELFTEST.)
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib/sandbox-guard.sh"

if [[ -n "$SCHEDULE_ID" && -n "$CLUB_ID_ARG" ]]; then
  die "--schedule-id et --club-id sont mutuellement exclusifs"
fi

if [[ -n "$SCHEDULE_ID" ]]; then
  info "Utilisation du schedule existant: $SCHEDULE_ID"
else
  local_club_id="${CLUB_ID_ARG:-$CLUB_ID}"
  schedule_payload=$(python3 -c 'import json,sys; print(json.dumps({"name": sys.argv[1], "status": "DRAFT"}, ensure_ascii=False))' "$SCHEDULE_NAME")

  info "Création du schedule: $SCHEDULE_NAME"
  http_request POST "$API_BASE/schedules" "$schedule_payload" "X-Club-Id: $local_club_id"
  case "$HTTP_STATUS" in
    200|201) ;;
    404) die "Endpoint /schedules introuvable (HTTP 404). Le backend est-il bien démarré ?" ;;
    *) die "Échec de création du schedule (HTTP $HTTP_STATUS): $HTTP_BODY" ;;
  esac

  if ! SCHEDULE_ID=$(extract_id <<<"$HTTP_BODY"); then
    die "Réponse JSON invalide lors de la création du schedule"
  fi
  info "Schedule créé: $SCHEDULE_ID"
fi

info "Déclenchement de la génération"
http_request POST "$API_BASE/schedules/$SCHEDULE_ID/generate"
case "$HTTP_STATUS" in
  200|202) ;;
  404) die "Endpoint /schedules/$SCHEDULE_ID/generate introuvable (HTTP 404)" ;;
  *) die "Échec du déclenchement (HTTP $HTTP_STATUS): $HTTP_BODY" ;;
esac

info "Génération lancée"

deadline=$((SECONDS + TIMEOUT_SECONDS))
pending_deadline=$((SECONDS + PENDING_TIMEOUT_SECONDS))
saw_progress=0
attempt=0
current_status=""
schedule_score=""

while :; do
  if [[ "$SECONDS" -ge "$deadline" ]]; then
    die "Timeout après ~10 minutes en attendant la génération"
  fi

  attempt=$((attempt + 1))
  http_request GET "$API_BASE/schedules/$SCHEDULE_ID"
  case "$HTTP_STATUS" in
    200)
      ;;
    404)
      die "Schedule $SCHEDULE_ID introuvable pendant le polling (HTTP 404)"
      ;;
    *)
      die "Échec du polling (HTTP $HTTP_STATUS): $HTTP_BODY"
      ;;
  esac

  if ! current_status=$(extract_field_from_json "$HTTP_BODY" "status"); then
    die "Réponse JSON invalide pendant le polling"
  fi

  schedule_score=$(python3 -c '
import json
import sys

data = json.load(sys.stdin)
value = data.get("score")
if value in (None, ""):
    print("")
else:
    print(value)
' <<<"$HTTP_BODY")

  if [[ -n "$schedule_score" ]]; then
    printf '%b[%d]%b Status: %s | Score: %s\n' "$BLUE" "$attempt" "$NC" "$current_status" "$schedule_score"
  else
    printf '%b[%d]%b Status: %s\n' "$BLUE" "$attempt" "$NC" "$current_status"
  fi

  # Once the worker has picked the message up, PENDING is behind us — from here
  # only the (legitimately slow) solver governs, under the hard TIMEOUT cap.
  case "$current_status" in
    GENERATING)
      saw_progress=1
      ;;
    COMPLETED|FAILED)
      saw_progress=1
      break
      ;;
  esac

  # Watchdog: still PENDING and never progressed after PENDING_TIMEOUT_SECONDS.
  # Two very different causes — DIAGNOSE before acting (review finding: a blind
  # stream wipe would destroy legitimately queued generations):
  #   (a) worker busy solving ANOTHER schedule (single worker, solves can take
  #       minutes) → just wait / raise PENDING_TIMEOUT_SECONDS;
  #   (b) worker dead or crash-looping (NOGROUP after a Redis wipe) → restart.
  if [[ "$saw_progress" -eq 0 && "$SECONDS" -ge "$pending_deadline" ]]; then
    die "Génération jamais démarrée : statut toujours '$current_status' après ${PENDING_TIMEOUT_SECONDS}s (jamais passé à GENERATING).
  DIAGNOSTIC (dans l'ordre — ne rien supprimer à l'aveugle) :
  1. Worker occupé par un autre solve ?   docker compose exec -T redis redis-cli XPENDING messages symfony
     → 'pending: 1' + logs engine actifs = solve en cours : ATTENDRE ou relancer avec PENDING_TIMEOUT_SECONDS plus grand.
  2. Worker mort / crash-loop ?           docker compose logs --tail=50 messenger-worker   (chercher 'NOGROUP')
     → redémarrer :  docker compose restart messenger-worker
  3. DERNIER RECOURS (détruit les générations en file — schedules resteront PENDING) :
     docker compose stop messenger-worker && docker compose exec -T redis redis-cli DEL messages messages__queue && docker compose start messenger-worker"
  fi

  if [[ "$SECONDS" -ge "$deadline" ]]; then
    die "Timeout après ~10 minutes en attendant la génération"
  fi
  sleep "$POLL_INTERVAL"
done

if [[ "$current_status" == "COMPLETED" ]]; then
  info "PLANNING COMPLETÉ"
  info "Génération terminée. Consultez les rapports dans le répertoire de lots."
  exit 0
fi

exit 1
