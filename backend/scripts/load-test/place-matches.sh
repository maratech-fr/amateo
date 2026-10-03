#!/usr/bin/env bash
# Sister of generate-schedule.sh for the PLACEMENT rail (POST /api/fixtures/place,
# « Placer automatiquement »). Logs in with a load-test manager account, posts a
# phase window {from,to} (dates AAAA-MM-JJ), and reports HTTP code, wall time and
# placed/skipped/unplaced counts. It MEASURES; it changes no quota or budget.
#
#   SCHEDULER_EMAIL=... SCHEDULER_PASSWORD=... \
#     place-matches.sh --club-id ID --from 2026-09-05 --to 2026-11-29
#
# A 409 (socle / season plan / lock), 429 (rate limit) or 502 (engine) is a
# SIGNAL about capacity under load, NOT a harness failure — it is reported, not
# hidden, and the script still exits 0 so a burst keeps its other clubs. Only a
# transport failure (backend unreachable) or a refused login exits non-zero.
set -euo pipefail

API_BASE="http://localhost:8080/api"

TOKEN="${SCHEDULER_TOKEN:-}"
CLUB_ID=""
FROM=""
TO=""

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[1;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'

die()  { printf '%bErreur:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }
info() { printf '%b%s%b\n' "$GREEN" "$1" "$NC"; }
warn() { printf '%b%s%b\n' "$YELLOW" "$1" "$NC"; }

usage() {
  cat <<EOF
Usage: $(basename "$0") [OPTIONS]

Options:
  --club-id ID       Club dont on place les matchs (obligatoire)
  --from AAAA-MM-JJ  Début de la fenêtre de phase (obligatoire)
  --to AAAA-MM-JJ    Fin de la fenêtre de phase (obligatoire)
  --token TOKEN      JWT Bearer (sinon SCHEDULER_EMAIL/SCHEDULER_PASSWORD)
  --help, -h         Affiche cette aide

La réponse de /api/fixtures/place est JSON { placed, skipped, unplaced[], ... }.
EOF
}

# Login à la volée (mêmes règles que generate-schedule.sh) : /api/login rend 204 +
# cookie httpOnly BEARER ; un login refusé sort BRUYAMMENT (sinon log vide, cause
# invisible). Placé AVANT le parsing pour que --token garde le dernier mot.
if [[ -z "$TOKEN" && -n "${SCHEDULER_EMAIL:-}" ]]; then
  login_headers=$(mktemp)
  login_code=$(curl -s -o /dev/null -D "$login_headers" -w '%{http_code}' \
    -X POST "$API_BASE/login" -H 'Content-Type: application/json' \
    -d "{\"email\":\"$SCHEDULER_EMAIL\",\"password\":\"$SCHEDULER_PASSWORD\"}" || echo 000)
  TOKEN=$(grep -oiP 'set-cookie: *BEARER=\K[^;]+' "$login_headers" | head -1 || true)
  rm -f "$login_headers"
  if [[ -z "$TOKEN" ]]; then
    die "login refusé pour $SCHEDULER_EMAIL (HTTP $login_code)"
  fi
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
    --club-id)   [[ $# -ge 2 ]] || die "--club-id requires a value"; CLUB_ID="$2"; shift 2 ;;
    --club-id=*) CLUB_ID="${1#*=}"; shift ;;
    --from)      [[ $# -ge 2 ]] || die "--from requires a value"; FROM="$2"; shift 2 ;;
    --from=*)    FROM="${1#*=}"; shift ;;
    --to)        [[ $# -ge 2 ]] || die "--to requires a value"; TO="$2"; shift 2 ;;
    --to=*)      TO="${1#*=}"; shift ;;
    --token)     [[ $# -ge 2 ]] || die "--token requires a value"; TOKEN="$2"; shift 2 ;;
    --token=*)   TOKEN="${1#*=}"; shift ;;
    --help|-h)   usage; exit 0 ;;
    *)           die "Unknown option: $1" ;;
  esac
done

[[ -n "$TOKEN" ]]   || die "Token requis : --token, SCHEDULER_TOKEN, ou SCHEDULER_EMAIL/SCHEDULER_PASSWORD."
[[ -n "$CLUB_ID" ]] || die "--club-id requis"
[[ "$FROM" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "--from doit être AAAA-MM-JJ"
[[ "$TO"   =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "--to doit être AAAA-MM-JJ"

# Fail-closed sandbox guard (P4-141): placer des matchs MUTE la base — refuse tout
# sauf le bac à sable IA (amateo_dev) ou *_test. Après --help/token comme ses sœurs.
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../lib/sandbox-guard.sh"

body=$(python3 -c 'import json,sys; print(json.dumps({"from": sys.argv[1], "to": sys.argv[2]}))' "$FROM" "$TO")

info "Placement $CLUB_ID — fenêtre $FROM → $TO"
body_file=$(mktemp)
start=$(date +%s)
code=$(curl -sS -o "$body_file" -w '%{http_code}' \
  -X POST "$API_BASE/fixtures/place" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Club-Id: $CLUB_ID" \
  -H 'Content-Type: application/json' \
  --data "$body" 2>/dev/null) || { rm -f "$body_file"; die "Backend injoignable sur POST /fixtures/place"; }
end=$(date +%s)
resp=$(<"$body_file"); rm -f "$body_file"

# placed / skipped / unplaced lus dans la réponse JSON (absents si 4xx/5xx).
read -r placed skipped unplaced < <(python3 -c '
import json, sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
if not isinstance(d, dict):
    d = {}
u = d.get("unplaced")
print(d.get("placed", "-"), d.get("skipped", "-"), len(u) if isinstance(u, list) else "-")
' <<<"$resp")

# Ligne machine-lisible consommée par run-load-test.sh (préfixe stable).
printf 'PLACE_RESULT code=%s e2e_s=%s placed=%s skipped=%s unplaced=%s\n' \
  "$code" "$((end - start))" "$placed" "$skipped" "$unplaced"

case "$code" in
  200|201) info "Placement OK (HTTP $code) : placed=$placed skipped=$skipped unplaced=$unplaced en $((end - start))s" ;;
  409|429|502) warn "SIGNAL de charge (HTTP $code) — pas un échec du harnais : $resp" ;;
  *) warn "Réponse inattendue (HTTP $code) : $resp" ;;
esac

# Exit 0 même sur signal de charge (409/429/502) : le harnais mesure, il ne juge
# pas. Seuls un transport mort ou un login refusé (plus haut) sortent non-zéro.
exit 0
