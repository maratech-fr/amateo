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
# the season plan pointer if needed (dev DB only), creates its OWN throwaway
# teams + venue + window + fixtures + rotation, and cleans them up afterwards.
#
# Why throwaway teams AND a throwaway venue (not the club's first team/venue):
# the dev seed now carries the club's REAL weekend match layout — Saturday/Sunday
# access windows on real venues, match habits on real teams, and A/B rotations on
# real venues. Reusing a real team could give the home match a competing Saturday
# habit whose (venue, kickoff) attraction ties with our rotation; reusing a real
# venue could collide with a seeded rotation at our kickoff (409). Owning brand-new
# resources keeps assertions 1 & 3 deterministic: the ONLY pull toward 15:30 is our
# rotation on OUR venue, so (our venue, 15:30) is the unique objective maximum even
# with the seeded Saturday windows widening the domain.
#
# Assertion 2 is club-wide: `no_access_window` fires only when NO club venue has a
# match window that day. The seed opens a Sunday window (Matéo), so the smoke saves
# then deletes every Sunday window of the club before placing, and the trap recreates
# them. On a club WITHOUT the weekend data this is a no-op — green either way (CI runs
# this on a fresh seed).
#
# Usage: backend/scripts/smoke-place-matches.sh
# Exit: 0 = all three assertions hold, 1 = any failure.
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

# Next Saturday / Sunday (dates in the future keep the data obviously smoke-ish).
SATURDAY=$(date -d "next saturday" +%Y-%m-%d)
SUNDAY=$(date -d "$SATURDAY + 1 day" +%Y-%m-%d)

# Recreate the club Sunday windows we removed for assertion 2 (see below).
restore_sunday_windows() {
  [ -n "${SUNDAY_WINDOWS:-}" ] || return 0
  while IFS='|' read -r _ vid st en; do
    [ -n "$vid" ] || continue
    curl -s -X POST "$API_BASE/venue_match_windows" "${auth[@]}" \
      -d "{\"venueId\":\"$vid\",\"dayOfWeek\":7,\"startTime\":\"$st\",\"endTime\":\"$en\"}" >/dev/null || true
  done <<< "$SUNDAY_WINDOWS"
}

cleanup() {
  # Fixtures FIRST: a team with a fixture is "engaged" and refuses deletion.
  for id in ${FX_SAT:-} ${FX_SUN:-}; do curl -s -X DELETE "$API_BASE/fixtures/$id" "${auth[@]}" >/dev/null || true; done
  [ -n "${ROTATION_ID:-}" ] && curl -s -X DELETE "$API_BASE/match_slot_rotations/$ROTATION_ID" "${auth[@]}" >/dev/null || true
  [ -n "${WINDOW_ID:-}" ] && curl -s -X DELETE "$API_BASE/venue_match_windows/$WINDOW_ID" "${auth[@]}" >/dev/null || true
  for id in ${TEAM_ID:-} ${SECOND_TEAM_ID:-}; do [ "$id" != "null" ] && curl -s -X DELETE "$API_BASE/teams/$id" "${auth[@]}" >/dev/null || true; done
  [ -n "${VENUE_ID:-}" ] && [ "${VENUE_ID:-}" != "null" ] && curl -s -X DELETE "$API_BASE/venues/$VENUE_ID" "${auth[@]}" >/dev/null || true
  restore_sunday_windows
  # A pointer WE settled would 409 the weekly smoke's schedule creation — undo it.
  if [ "$POINTER_SET_BY_SMOKE" = 1 ]; then
    psql_dev "UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null || true
  fi
}
trap cleanup EXIT

# Throwaway teams + venue (see the header): the smoke owns every resource the
# assertions depend on, so the seeded weekend layout on real teams/venues cannot
# perturb them. Created AFTER the trap so a mid-way failure still cleans up.
info "creating two throwaway teams + a throwaway venue"
CAT=$(curl -sf "$API_BASE/sport_categories" "${auth[@]}" | jget member.0.id)
[ -n "$CAT" ] && [ "$CAT" != "null" ] || die "no sport category to build a throwaway team"
TEAM_ID=$(curl -sf -X POST "$API_BASE/teams" "${auth[@]}" \
  -d "{\"name\":\"Smoke Place A\",\"sportCategoryId\":\"$CAT\",\"priorityTierId\":1}" | jget id)
SECOND_TEAM_ID=$(curl -sf -X POST "$API_BASE/teams" "${auth[@]}" \
  -d "{\"name\":\"Smoke Place B\",\"sportCategoryId\":\"$CAT\",\"priorityTierId\":1}" | jget id)
VENUE_ID=$(curl -sf -X POST "$API_BASE/venues" "${auth[@]}" \
  -d "{\"name\":\"Smoke Place Gym\",\"source\":\"manual\"}" | jget id)
[ -n "$TEAM_ID" ] && [ "$TEAM_ID" != "null" ] || die "throwaway team A creation failed"
[ -n "$SECOND_TEAM_ID" ] && [ "$SECOND_TEAM_ID" != "null" ] || die "throwaway team B creation failed"
[ -n "$VENUE_ID" ] && [ "$VENUE_ID" != "null" ] || die "throwaway venue creation failed"

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

# Assertion 2 needs the Sunday match to have NO access window ANYWHERE in the club
# (no_access_window is club-wide). Save then delete every Sunday (dayOfWeek=7)
# window of the club; the trap recreates them. No-op on a club without weekend data.
SUNDAY_WINDOWS=$(curl -sf "$API_BASE/venue_match_windows?itemsPerPage=100" "${auth[@]}" | python3 -c "import json,sys
d=json.load(sys.stdin)
rows=d.get('member', d if isinstance(d,list) else [])
for w in rows:
    if w.get('dayOfWeek')==7:
        print('%s|%s|%s|%s' % (w['id'], w['venueId'], w['startTime'], w['endTime']))")
if [ -n "$SUNDAY_WINDOWS" ]; then
  info "removing the club's Sunday access window(s) for assertion 2 (restored on exit)"
  while IFS='|' read -r wid _ _ _; do
    [ -n "$wid" ] && curl -s -X DELETE "$API_BASE/venue_match_windows/$wid" "${auth[@]}" >/dev/null || true
  done <<< "$SUNDAY_WINDOWS"
fi

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
