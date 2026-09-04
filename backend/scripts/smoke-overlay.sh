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

# Poll a schedule (version) id until COMPLETED (dies on FAILED or timeout). Async rail: the
# messenger worker imports the engine output; both scenarios below wait on it the same way.
poll_completed() {
  local sched="$1" label="$2" status
  for _ in $(seq 1 60); do
    status=$(curl -sf "$API_BASE/schedules/$sched" "${auth[@]}" | jget status)
    case "$status" in
      COMPLETED) ok "$label"; return 0 ;;
      FAILED) die "$label — generation FAILED (see schedule diagnostics)" ;;
    esac
    sleep 2
  done
  die "$label — did not finish in 120s (status=$status)"
}

dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): exports SANDBOX_DB, refuses non-sandbox DBs.
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

info "minting a dev token for $USER_EMAIL"
TOKEN=$(php "php bin/console lexik:jwt:generate-token $USER_EMAIL --ttl=3600 --user-class='App\\Entity\\User'" | tr -d '[:space:]')
[ -n "$TOKEN" ] || die "could not mint a JWT (run `make -C backend behat` once — it seeds the dev fixtures)"
auth=(-H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json")

CLUB_ID=$(curl -sf "$API_BASE/me" "${auth[@]}" | jget club.id)
[ -n "$CLUB_ID" ] && [ "$CLUB_ID" != "null" ] || die "no club for the smoke user"

# A secondary plan requires the SEASON plan to point a version (SocleGuard).
CHOSEN=$(psql_dev "SELECT chosen_schedule_id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON' LIMIT 1")
POINTER_SET_BY_SMOKE=0
if [ -z "$CHOSEN" ]; then
  info "settling the season plan pointer (restored on exit)"
  SCHEDULE_ID=$(psql_dev "SELECT id FROM schedule WHERE club_id='$CLUB_ID' AND status='COMPLETED' AND schedule_plan_id=(SELECT id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON') ORDER BY created_at DESC LIMIT 1")
  [ -n "$SCHEDULE_ID" ] || die "no COMPLETED season schedule — run `make -C backend behat` first"
  psql_dev "UPDATE schedule_plan SET chosen_schedule_id='$SCHEDULE_ID' WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null
  POINTER_SET_BY_SMOKE=1
fi

cleanup() {
  [ -n "${ENTRY_ID:-}" ] && curl -s -X DELETE "$API_BASE/calendar_entries/$ENTRY_ID" "${auth[@]}" >/dev/null || true
  [ -n "${ENTRY2_ID:-}" ] && curl -s -X DELETE "$API_BASE/calendar_entries/$ENTRY2_ID" "${auth[@]}" >/dev/null || true
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
poll_completed "$SCHEDULE_ID" "overlay generation COMPLETED — the cockpit rail works end-to-end"

# ── Scenario 2 — FILL honours a shared block whose partner is HARD-pinned (PR-1 sweep bloc-aware) ──
#
# The transcription (« Partir du planning de saison ») pins the socle's copied sessions HARD, and
# the closure plan carries a COPY of the socle's shared blocks. We free ONE member of such a block
# (delete its transcribed slot → a hole), leaving its partner HARD-pinned on the block's case, then
# FILL. The block forces co-presence: the freed member must land back on the pinned partner's exact
# case. Before the bloc-aware sweep, the freed member's variable on that case was killed (the pin
# occupies it) and the fill went INFEASIBLE — so this AFFIRMS the two partners co-locate, not just
# that generation reached COMPLETED.

info "scenario 2: a fresh CLOSURE period whose plan copies the socle shared blocks"
START2=$(date -d "next monday + 63 days" +%Y-%m-%d)
END2=$(date -d "$START2 + 4 days" +%Y-%m-%d)
ENTRY2_BODY=$(curl -s -X POST "$API_BASE/calendar_entries" "${auth[@]}" \
  -d "{\"kind\":\"period\",\"periodType\":\"closure\",\"title\":\"Smoke overlay fill\",\"startDate\":\"$START2\",\"endDate\":\"$END2\"}")
ENTRY2_ID=$(printf '%s' "$ENTRY2_BODY" | jget id)
[ -n "$ENTRY2_ID" ] && [ "$ENTRY2_ID" != "null" ] || die "second calendar entry failed — response: $ENTRY2_BODY"

PLAN2_ID=$(curl -s -X POST "$API_BASE/schedule_plans" "${auth[@]}" -d "{\"calendarEntryId\":\"$ENTRY2_ID\"}" | jget id)
[ -n "$PLAN2_ID" ] && [ "$PLAN2_ID" != "null" ] || die "second period plan creation failed"

info "transcribing from the socle (copied sessions locked HARD → version V1)"
V1_ID=$(curl -s -X POST "$API_BASE/schedule_plans/$PLAN2_ID/transcribe-from-socle" "${auth[@]}" | jget id)
[ -n "$V1_ID" ] && [ "$V1_ID" != "null" ] || die "transcription failed (no V1 id)"
poll_completed "$V1_ID" "transcription produced a COMPLETED V1"

# Pick a shared block of THIS plan whose two members (each in exactly ONE block, to keep the fill
# unambiguous) are transcribed on the SAME case in V1. Keep one member pinned, free the other.
TARGET=$(psql_dev "WITH pbt AS (
    SELECT t.block_id, t.team_id
    FROM shared_training_block_team t
    JOIN shared_training_block b ON b.id = t.block_id
    WHERE b.schedule_plan_id = '$PLAN2_ID'
  ), single AS (
    SELECT team_id FROM pbt GROUP BY team_id HAVING COUNT(DISTINCT block_id) = 1
  )
  SELECT keep.team_id, drop_.team_id, keep.venue_id, keep.day_of_week,
         to_char(keep.start_time, 'HH24:MI'), drop_.id
  FROM pbt ta
  JOIN pbt tb ON tb.block_id = ta.block_id AND tb.team_id <> ta.team_id
  JOIN single sa ON sa.team_id = ta.team_id
  JOIN single sb ON sb.team_id = tb.team_id
  JOIN schedule_slot_template keep ON keep.schedule_id = '$V1_ID' AND keep.team_id = ta.team_id
  JOIN schedule_slot_template drop_ ON drop_.schedule_id = '$V1_ID' AND drop_.team_id = tb.team_id
    AND drop_.venue_id = keep.venue_id AND drop_.day_of_week = keep.day_of_week
    AND drop_.start_time = keep.start_time
  ORDER BY keep.team_id, drop_.team_id
  LIMIT 1")
[ -n "$TARGET" ] || die "no shared block with two co-present transcribed members found for plan $PLAN2_ID"
IFS='|' read -r KEEP_TEAM FREE_TEAM CASE_VENUE CASE_DAY CASE_START FREE_SLOT_ID <<<"$TARGET"
info "block case: keep $KEEP_TEAM pinned, free $FREE_TEAM on ($CASE_VENUE, day $CASE_DAY, $CASE_START)"

psql_dev "DELETE FROM schedule_slot_template WHERE id = '$FREE_SLOT_ID'" >/dev/null || die "could not free the partner's slot"

info "filling (V1 pinned HARD, the freed member is a hole the block must co-place)"
FILL_HTTP=$(curl -s -o /tmp/smoke-fill.$$ -w '%{http_code}' -X POST "$API_BASE/schedules/$V1_ID/fill" "${auth[@]}")
V2_ID=$(jget id </tmp/smoke-fill.$$ 2>/dev/null); rm -f /tmp/smoke-fill.$$
[ "$FILL_HTTP" = "202" ] || die "fill returned HTTP $FILL_HTTP (expected 202)"
[ -n "$V2_ID" ] && [ "$V2_ID" != "null" ] || die "fill did not return a V2 id"
poll_completed "$V2_ID" "fill reached COMPLETED"

# THE assertion: the freed member and its pinned partner share one (venue, day, start) case in V2.
COPRESENT=$(psql_dev "SELECT COUNT(*) FROM schedule_slot_template a
  JOIN schedule_slot_template b ON b.schedule_id = a.schedule_id AND b.venue_id = a.venue_id
    AND b.day_of_week = a.day_of_week AND b.start_time = a.start_time
  WHERE a.schedule_id = '$V2_ID' AND a.team_id = '$KEEP_TEAM' AND b.team_id = '$FREE_TEAM'")
[ "${COPRESENT:-0}" -ge 1 ] || die "the freed block member did NOT co-locate with its HARD-pinned partner (sweep not bloc-aware)"
ok "fill co-places a freed block member on its HARD-pinned partner's case — the bloc-aware sweep holds"
exit 0
