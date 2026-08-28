#!/usr/bin/env bash
# Period-overlay smoke (ADR-0002) — SEMANTIC end-to-end of the cockpit rail:
# create a CLOSURE period on the club calendar → its plan is born from the
# Adapter gesture (POST /schedule_plans) → a version is created under that plan
# → generation runs the OVERLAY build (the period's own grid, never a union
# with the season) → the schedule reaches COMPLETED.
#
# Self-sufficient for dev/CI: mints a token, settles the season plan pointer if
# needed (a secondary plan requires the socle in force), and cleans up after
# itself (the period deletion cascades its plan and versions; the pointer it
# set is restored).
#
# Usage: backend/scripts/smoke-overlay.sh
# Exit: 0 = COMPLETED reached, 1 = any failure.
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
COMPOSE="$ROOT/docker-compose.yml"
API_BASE="http://localhost:8080/api"
USER_EMAIL="mara.mb@bccl.fr"

GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$1"; }
ok()   { printf '%bPASS:%b %s\n' "$GREEN" "$NC" "$1"; }
die()  { printf '%bFAIL:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

dc()  { docker compose -f "$COMPOSE" "$@"; }
php() { dc exec -T -e APP_ENV=dev php-fpm sh -c "cd /app/backend && $1" 2>&1 | grep -vE '^\[debug\]|Notified event' || true; }
psql_dev() { dc exec -T postgres psql -U amateo_owner -d "$SANDBOX_DB" -tA -c "$1"; }
jget() { python3 -c "import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d=d[int(k)] if k.lstrip('-').isdigit() else d.get(k) if isinstance(d,dict) else None
    if d is None: break
print(d if d is not None else 'null')" "$1"; }

dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): exports SANDBOX_DB, refuses non-sandbox DBs.
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

info "minting a dev token for $USER_EMAIL"
TOKEN=$(php "php bin/console lexik:jwt:generate-token $USER_EMAIL --ttl=3600 --user-class='App\\Entity\\User'" | tr -d '[:space:]')
[ -n "$TOKEN" ] || die "could not mint a JWT (run smoke-solver.sh once to seed the dev fixtures)"
auth=(-H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json")

CLUB_ID=$(curl -sf "$API_BASE/me" "${auth[@]}" | jget club.id)
[ -n "$CLUB_ID" ] && [ "$CLUB_ID" != "null" ] || die "no club for the smoke user"

# A secondary plan requires the SEASON plan to point a version (SocleGuard).
CHOSEN=$(psql_dev "SELECT chosen_schedule_id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON' LIMIT 1")
POINTER_SET_BY_SMOKE=0
if [ -z "$CHOSEN" ]; then
  info "settling the season plan pointer (restored on exit)"
  SCHEDULE_ID=$(psql_dev "SELECT id FROM schedule WHERE club_id='$CLUB_ID' AND status='COMPLETED' AND schedule_plan_id=(SELECT id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON') ORDER BY created_at DESC LIMIT 1")
  [ -n "$SCHEDULE_ID" ] || die "no COMPLETED season schedule — run smoke-solver.sh first"
  psql_dev "UPDATE schedule_plan SET chosen_schedule_id='$SCHEDULE_ID' WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null
  POINTER_SET_BY_SMOKE=1
fi

cleanup() {
  [ -n "${ENTRY_ID:-}" ] && curl -s -X DELETE "$API_BASE/calendar_entries/$ENTRY_ID" "${auth[@]}" >/dev/null || true
  if [ "$POINTER_SET_BY_SMOKE" = 1 ]; then
    psql_dev "UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null || true
  fi
}
trap cleanup EXIT

# A short future closure period (dates obviously smoke-ish).
# ⚠ The window must stay CLEAR of every period plan the dev seed now ships
# (reprises 2026-08-17→30 and the Matéo adaptation 2026-09-07→27, BcclSeeder
# sections 12-13): PeriodWindowUniquenessGuard 409s any overlap at plan birth.
# "+49 days" lands mid-October today and keeps sliding forward with real time,
# past every seeded window.
START=$(date -d "next monday + 49 days" +%Y-%m-%d)
END=$(date -d "$START + 4 days" +%Y-%m-%d)

info "creating a CLOSURE period $START → $END"
ENTRY_BODY=$(curl -s -X POST "$API_BASE/calendar_entries" "${auth[@]}" \
  -d "{\"kind\":\"period\",\"periodType\":\"closure\",\"title\":\"Smoke overlay\",\"startDate\":\"$START\",\"endDate\":\"$END\"}")
ENTRY_ID=$(printf '%s' "$ENTRY_BODY" | jget id)
[ -n "$ENTRY_ID" ] && [ "$ENTRY_ID" != "null" ] || die "calendar entry creation failed — response: $ENTRY_BODY"

info "adapting: the period plan is born from the gesture (POST /schedule_plans)"
PLAN_BODY=$(curl -s -X POST "$API_BASE/schedule_plans" "${auth[@]}" -d "{\"calendarEntryId\":\"$ENTRY_ID\"}")
PLAN_ID=$(printf '%s' "$PLAN_BODY" | jget id)
[ -n "$PLAN_ID" ] && [ "$PLAN_ID" != "null" ] || die "period plan creation failed — response: $PLAN_BODY"

info "creating an overlay version under plan $PLAN_ID"
SCHEDULE_ID=$(curl -sf -X POST "$API_BASE/schedules" "${auth[@]}" -d "{\"schedulePlanId\":\"$PLAN_ID\",\"status\":\"DRAFT\"}" | jget id)
[ -n "$SCHEDULE_ID" ] && [ "$SCHEDULE_ID" != "null" ] || die "overlay version creation failed"

info "generating (async — overlay build, the period's OWN grid)"
HTTP=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API_BASE/schedules/$SCHEDULE_ID/generate" "${auth[@]}")
[ "$HTTP" = "202" ] || die "generate returned HTTP $HTTP (expected 202)"

info "polling for COMPLETED (max 120s)"
for _ in $(seq 1 60); do
  STATUS=$(curl -sf "$API_BASE/schedules/$SCHEDULE_ID" "${auth[@]}" | jget status)
  case "$STATUS" in
    COMPLETED) ok "overlay generation COMPLETED — the cockpit rail works end-to-end"; exit 0 ;;
    FAILED) die "overlay generation FAILED (see schedule diagnostics)" ;;
  esac
  sleep 2
done
die "overlay generation did not finish in 120s (status=$STATUS)"
