#!/usr/bin/env bash
# Sister of generate-schedule.sh for the PLACEMENT rail (POST /api/fixtures/place,
# « Placer automatiquement »). Logs in with a load-test manager account, posts a
# phase window {from,to} (dates AAAA-MM-JJ) and — the rail being ASYNCHRONOUS now
# (202 + run id, ADR-0003) — polls GET /api/fixtures/placement-run until the run is
# terminal, reporting the POST code, the terminal run status, the END-TO-END wall
# time (queue + solve) and placed/skipped/unplaced. It MEASURES; it changes no quota.
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

POST /api/fixtures/place répond 202 { runId, status } ; le résultat (placed,
skipped, unplaced[]) est lu dans le run via GET /api/fixtures/placement-run.
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

# Borne du sondage de l'état terminal du run (file d'attente + solve). Généreuse :
# le budget moteur est de 35 s par semaine ISO et une phase en porte une quinzaine,
# auxquels s'ajoute l'attente derrière les autres clubs (worker unique, solve global
# sérialisé). Au-delà, on NOMME un TIMEOUT plutôt que de boucler sans fin.
POLL_TIMEOUT_SECONDS="${PLACE_POLL_TIMEOUT_SECONDS:-1800}"
POLL_INTERVAL_SECONDS=3

body=$(python3 -c 'import json,sys; print(json.dumps({"from": sys.argv[1], "to": sys.argv[2]}))' "$FROM" "$TO")

info "Placement $CLUB_ID — fenêtre $FROM → $TO"
body_file=$(mktemp)
start=$(date +%s)
http=$(curl -sS -o "$body_file" -w '%{http_code}' \
  -X POST "$API_BASE/fixtures/place" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Club-Id: $CLUB_ID" \
  -H 'Content-Type: application/json' \
  --data "$body" 2>/dev/null) || { rm -f "$body_file"; die "Backend injoignable sur POST /fixtures/place"; }
resp=$(<"$body_file"); rm -f "$body_file"

# Extrait un champ d'un objet JSON de résultat de placement (placed/skipped + len(unplaced)).
counts_of() { python3 -c '
import json, sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
if not isinstance(d, dict):
    d = {}
u = d.get("unplaced")
print(d.get("placed", "-"), d.get("skipped", "-"), len(u) if isinstance(u, list) else "-")
'; }

run_status="-"; placed="-"; skipped="-"; unplaced="-"
case "$http" in
  202)
    # Rail ASYNCHRONE (ADR-0003) : 202 { runId, status }. On attend l'état terminal du
    # run via GET /api/fixtures/placement-run (dernier run du club+saison courants), puis
    # on lit placed/skipped/unplaced dans son `result`. L'e2e couvre file + solve.
    run_id=$(python3 -c '
import json, sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
print(d.get("runId", "") if isinstance(d, dict) else "")
' <<<"$resp")
    if [[ -z "$run_id" ]]; then
      run_status="NO_RUN_ID"
    else
      deadline=$((start + POLL_TIMEOUT_SECONDS))
      while :; do
        run_body=$(curl -sS \
          -X GET "$API_BASE/fixtures/placement-run" \
          -H "Authorization: Bearer $TOKEN" \
          -H "X-Club-Id: $CLUB_ID" 2>/dev/null || echo '{}')
        # On ne lit QUE le run attendu (id), pour ne jamais confondre avec un run antérieur.
        read -r run_status placed skipped unplaced < <(RUN_ID="$run_id" python3 -c '
import json, os, sys
try:
    d = json.loads(sys.stdin.read() or "{}")
except Exception:
    d = {}
run = d.get("run") if isinstance(d, dict) else None
want = os.environ.get("RUN_ID", "")
if not isinstance(run, dict) or run.get("id") != want:
    print("PENDING - - -"); raise SystemExit
status = run.get("status") or "PENDING"
res = run.get("result") if isinstance(run.get("result"), dict) else {}
u = res.get("unplaced")
print(status, res.get("placed", "-"), res.get("skipped", "-"), len(u) if isinstance(u, list) else "-")
' <<<"$run_body")
        [[ "$run_status" == "COMPLETED" || "$run_status" == "FAILED" ]] && break
        if (( $(date +%s) >= deadline )); then run_status="TIMEOUT"; break; fi
        sleep "$POLL_INTERVAL_SECONDS"
      done
    fi
    ;;
  200|201)
    # 200 synchrone restant = « aucun match à placer » (corps direct, pas de run).
    read -r placed skipped unplaced < <(counts_of <<<"$resp")
    run_status="COMPLETED"
    ;;
  *) ;; # 409/429/502 : signal de charge, pas de run à attendre.
esac
end=$(date +%s)

# Ligne machine-lisible consommée par run-load-test.sh (préfixe + champs stables).
printf 'PLACE_RESULT http=%s run=%s e2e_s=%s placed=%s skipped=%s unplaced=%s\n' \
  "$http" "$run_status" "$((end - start))" "$placed" "$skipped" "$unplaced"

case "$http" in
  202)
    case "$run_status" in
      COMPLETED) info "Placement OK (202 → run COMPLETED) : placed=$placed skipped=$skipped unplaced=$unplaced en $((end - start))s" ;;
      FAILED)    warn "Run FAILED (202 → le solveur a échoué) — signal de charge en $((end - start))s" ;;
      TIMEOUT)   warn "Run NON TERMINÉ dans ${POLL_TIMEOUT_SECONDS}s (202 → TIMEOUT) — signal de charge" ;;
      *)         warn "Run d'état « $run_status » (202) : $resp" ;;
    esac ;;
  200|201)     info "Placement OK (HTTP $http) : placed=$placed skipped=$skipped unplaced=$unplaced en $((end - start))s" ;;
  409|429|502) warn "SIGNAL de charge (HTTP $http) — pas un échec du harnais : $resp" ;;
  *)           warn "Réponse inattendue (HTTP $http) : $resp" ;;
esac

# Exit 0 même sur signal de charge (409/429/502) ou run FAILED/TIMEOUT : le harnais
# mesure, il ne juge pas. Seuls un transport mort ou un login refusé sortent non-zéro.
exit 0
