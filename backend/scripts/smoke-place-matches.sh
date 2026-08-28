#!/usr/bin/env bash
# Match-placement smoke-test (P1-4 PR D) — SEMANTIC end-to-end verification of
# POST /api/fixtures/place: not just a 200, the MEANING —
#   1. a Saturday home match with a Saturday 14:00-18:00 access window comes
#      back PLACED with a kickoff inside 14:30-16:15 (footprint fits), and
#   2. a Sunday home match (no Sunday window) comes back UNPLACED with the
#      named reason `no_access_window` (the ask-your-derogation-early signal), and
#   3. (RMM-5) with a shared match slot declared (Saturday 15:30 on that venue,
#      home team a member), the Saturday placement lands ON the slot — venue +
#      15:30 — proving the SOFT rotation attraction fires end-to-end.
#
# Self-sufficient for local dev: mints a token for the fixtures user, settles
# the season plan pointer if needed (dev DB only), creates its own venue window
# and fixtures, and cleans them up afterwards.
#
# Usage: backend/scripts/smoke-place-matches.sh
# Exit: 0 = both assertions hold, 1 = any failure.
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

# JSON extraction without a jq dependency: python3 is everywhere.
jget() { python3 -c "import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d=d[int(k)] if k.lstrip('-').isdigit() else d.get(k) if isinstance(d,dict) else None
    if d is None: break
print(d if d is not None else 'null')" "$1"; }
unplaced_reason() { python3 -c "import json,sys
d=json.load(sys.stdin)
print(next((u['reason'] for u in d.get('unplaced',[]) if u['matchId']==sys.argv[1]),'null'))" "$1"; }
dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): exports SANDBOX_DB, refuses non-sandbox DBs.
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

info "minting a dev token for $USER_EMAIL"
TOKEN=$(php "php bin/console lexik:jwt:generate-token $USER_EMAIL --ttl=3600 --user-class='App\\Entity\\User'" | tr -d '[:space:]')
[ -n "$TOKEN" ] || die "could not mint a JWT (run smoke-solver.sh once to seed the dev fixtures)"
auth=(-H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json")

CLUB_ID=$(curl -sf "$API_BASE/me" "${auth[@]}" | jget club.id)
[ -n "$CLUB_ID" ] && [ "$CLUB_ID" != "null" ] || die "no club for the smoke user"

# The match module is gated on a chosen season plan; in dev, point it at the
# latest COMPLETED schedule when unset (smoke-solver leaves one behind).
CHOSEN=$(psql_dev "SELECT chosen_schedule_id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON' LIMIT 1")
POINTER_SET_BY_SMOKE=0
if [ -z "$CHOSEN" ]; then
  info "settling the season plan pointer (dev only, restored on exit)"
  SCHEDULE_ID=$(psql_dev "SELECT id FROM schedule WHERE club_id='$CLUB_ID' AND status='COMPLETED' ORDER BY created_at DESC LIMIT 1")
  [ -n "$SCHEDULE_ID" ] || die "no COMPLETED schedule — run smoke-solver.sh first"
  psql_dev "UPDATE schedule_plan SET chosen_schedule_id='$SCHEDULE_ID' WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null
  POINTER_SET_BY_SMOKE=1
fi

TEAMS=$(curl -sf "$API_BASE/teams?itemsPerPage=2" "${auth[@]}")
TEAM_ID=$(echo "$TEAMS" | jget member.0.id)
SECOND_TEAM_ID=$(echo "$TEAMS" | jget member.1.id)
VENUE_ID=$(curl -sf "$API_BASE/venues?itemsPerPage=1" "${auth[@]}" | jget member.0.id)
[ "$TEAM_ID" != "null" ] && [ "$VENUE_ID" != "null" ] || die "dev club has no team/venue"
[ "$SECOND_TEAM_ID" != "null" ] || die "dev club needs a 2nd team for the rotation volet"

# Next Saturday / Sunday (dates in the future keep the data obviously smoke-ish).
SATURDAY=$(date -d "next saturday" +%Y-%m-%d)
SUNDAY=$(date -d "$SATURDAY + 1 day" +%Y-%m-%d)

cleanup() {
  for id in ${FX_SAT:-} ${FX_SUN:-}; do curl -s -X DELETE "$API_BASE/fixtures/$id" "${auth[@]}" >/dev/null || true; done
  [ -n "${ROTATION_ID:-}" ] && curl -s -X DELETE "$API_BASE/match_slot_rotations/$ROTATION_ID" "${auth[@]}" >/dev/null || true
  [ -n "${WINDOW_ID:-}" ] && curl -s -X DELETE "$API_BASE/venue_match_windows/$WINDOW_ID" "${auth[@]}" >/dev/null || true
  # A pointer WE settled would 409 the weekly smoke's schedule creation — undo it.
  if [ "$POINTER_SET_BY_SMOKE" = 1 ]; then
    psql_dev "UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null || true
  fi
}
trap cleanup EXIT

info "creating a Saturday 14:00-18:00 access window + two home fixtures"
WINDOW_ID=$(curl -sf -X POST "$API_BASE/venue_match_windows" "${auth[@]}" \
  -d "{\"venueId\":\"$VENUE_ID\",\"dayOfWeek\":6,\"startTime\":\"14:00\",\"endTime\":\"18:00\"}" | jget id)
FX_SAT=$(curl -sf -X POST "$API_BASE/fixtures" "${auth[@]}" \
  -d "{\"teamId\":\"$TEAM_ID\",\"matchDate\":\"$SATURDAY\",\"homeAway\":\"HOME\",\"opponentLabel\":\"Smoke Sat\"}" | jget id)
FX_SUN=$(curl -sf -X POST "$API_BASE/fixtures" "${auth[@]}" \
  -d "{\"teamId\":\"$TEAM_ID\",\"matchDate\":\"$SUNDAY\",\"homeAway\":\"HOME\",\"opponentLabel\":\"Smoke Sun\"}" | jget id)
[ -n "$WINDOW_ID" ] && [ -n "$FX_SAT" ] && [ -n "$FX_SUN" ] || die "seeding failed"

# RMM-5 rotation volet — declare a shared match slot on THAT venue, Saturday 15:30,
# with the home team as a member: the SOFT rotation attraction must pull the home
# placement ONTO the slot (day + hour + venue), not just anywhere in the window.
info "declaring a Saturday 15:30 rotation on the venue (members: home team + one more)"
ROTATION_ID=$(curl -sf -X POST "$API_BASE/match_slot_rotations" "${auth[@]}" \
  -d "{\"venueId\":\"$VENUE_ID\",\"dayOfWeek\":6,\"kickoffTime\":\"15:30\",\"teamIds\":[\"$TEAM_ID\",\"$SECOND_TEAM_ID\"]}" | jget id)
[ -n "$ROTATION_ID" ] && [ "$ROTATION_ID" != "null" ] || die "rotation declaration failed"

info "POST /api/fixtures/place"
RESULT=$(curl -sf -X POST "$API_BASE/fixtures/place" "${auth[@]}") || die "placement call failed"

# Assertion 1 — the Saturday match is PLACED inside the window.
SAT=$(curl -sf "$API_BASE/fixtures/$FX_SAT" "${auth[@]}")
STATUS=$(echo "$SAT" | jget status); KICK=$(echo "$SAT" | jget kickoffTime); SRC=$(echo "$SAT" | jget placementSource)
[ "$STATUS" = "PLACED" ] || die "Saturday match not PLACED (status=$STATUS)"
[ "$SRC" = "SOLVER" ] || die "Saturday match not marked SOLVER (source=$SRC)"
[[ "$KICK" > "14:29" && "$KICK" < "16:16" ]] || die "kickoff $KICK outside the 14:30-16:15 legal range"

# Assertion 2 — the Sunday match is named, not silently dropped.
REASON=$(echo "$RESULT" | unplaced_reason "$FX_SUN")
[ "$REASON" = "no_access_window" ] || die "Sunday match reason=$REASON (expected no_access_window)"

# Assertion 3 — the rotation volet: the home placement lands ON the slot (venue +
# 15:30), not merely somewhere legal. The rotation attraction is the only pull
# toward 15:30 here (no habit seeded), so this proves the SOFT term fires.
VENUE_PLACED=$(echo "$SAT" | jget venueId)
[[ "$KICK" == "15:30"* ]] || die "rotation volet: kickoff $KICK not attracted to the 15:30 slot"
[ "$VENUE_PLACED" = "$VENUE_ID" ] || die "rotation volet: placed on $VENUE_PLACED, not the slot venue"

ok "solver placed Saturday at $KICK on the rotation slot, and NAMED the Sunday impossibility"
