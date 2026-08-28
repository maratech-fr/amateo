#!/usr/bin/env bash
# Coach-wishes smoke (#10) — SEMANTIC end-to-end of the solicitation rail, the
# ONLY unauthenticated /api write path:
#   1. a HOLIDAY period + a campaign (weeks × teams × deadline) mint one
#      plaintext token per coach;
#   2. send-links stamps sentAt (email rail exercised, best-effort);
#   3. the PUBLIC page pre-fills from the bare token (no JWT);
#   4. a submission within the token's perimeter persists a CoachWish the
#      manager can read back.
#
# Self-sufficient for dev/CI: mints a token, creates its own coach/period/
# campaign, cleans up afterwards.
#
# Usage: backend/scripts/smoke-coach-wishes.sh
# Exit: 0 = all assertions hold, 1 = any failure.
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
jget() { python3 -c "import json,sys
d=json.load(sys.stdin)
for k in sys.argv[1].split('.'):
    d=d[int(k)] if k.lstrip('-').isdigit() else d.get(k) if isinstance(d,dict) else None
    if d is None: break
print(d if d is not None else 'null')" "$1"; }

dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): refuse any DB but the AI sandbox / *_test.
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

info "minting a dev token for $USER_EMAIL"
TOKEN=$(php "php bin/console lexik:jwt:generate-token $USER_EMAIL --ttl=3600 --user-class='App\\Entity\\User'" | tr -d '[:space:]')
[ -n "$TOKEN" ] || die "could not mint a JWT (run smoke-solver.sh once to seed the dev fixtures)"
auth=(-H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json")

TEAM_ID=$(curl -sf "$API_BASE/teams?itemsPerPage=1" "${auth[@]}" | jget member.0.id)
[ -n "$TEAM_ID" ] && [ "$TEAM_ID" != "null" ] || die "dev club has no team"

UID_SUFFIX=$(date +%s)
cleanup() {
  [ -n "${CAMPAIGN_ID:-}" ] && curl -s -X DELETE "$API_BASE/coach_wish_campaigns/$CAMPAIGN_ID" "${auth[@]}" >/dev/null || true
  [ -n "${ENTRY_ID:-}" ] && curl -s -X DELETE "$API_BASE/calendar_entries/$ENTRY_ID" "${auth[@]}" >/dev/null || true
  [ -n "${COACH_ID:-}" ] && curl -s -X DELETE "$API_BASE/coaches/$COACH_ID" "${auth[@]}" >/dev/null || true
}
trap cleanup EXIT

info "creating a coach with an email (the solicitation target)"
COACH_ID=$(curl -sf -X POST "$API_BASE/coaches" "${auth[@]}" \
  -d "{\"firstName\":\"Smoke\",\"lastName\":\"Coach\",\"email\":\"smoke-coach-$UID_SUFFIX@smoke.fr\",\"isActive\":true}" | jget id)
[ -n "$COACH_ID" ] && [ "$COACH_ID" != "null" ] || die "coach creation failed"

# Attach the coach to the team so the campaign retains him.
LINK_HTTP=$(curl -s -o /tmp/smoke-cw-link.json -w '%{http_code}' -X POST "$API_BASE/team_coaches" "${auth[@]}" \
  -d "{\"teamId\":\"$TEAM_ID\",\"coachId\":\"$COACH_ID\",\"role\":\"MAIN\"}")
[ "$LINK_HTTP" = "201" ] || die "team-coach link failed (HTTP $LINK_HTTP)"

# A HOLIDAY period (campaigns are mother-holiday only) starting on a future Monday.
MONDAY=$(date -d "next monday + 28 days" +%Y-%m-%d)
END=$(date -d "$MONDAY + 13 days" +%Y-%m-%d)
DEADLINE=$(date -d "$MONDAY - 3 days" +%Y-%m-%d)

info "creating a HOLIDAY period $MONDAY → $END"
ENTRY_ID=$(curl -sf -X POST "$API_BASE/calendar_entries" "${auth[@]}" \
  -d "{\"kind\":\"period\",\"periodType\":\"holiday\",\"title\":\"Smoke vacances\",\"startDate\":\"$MONDAY\",\"endDate\":\"$END\"}" | jget id)
[ -n "$ENTRY_ID" ] && [ "$ENTRY_ID" != "null" ] || die "calendar entry creation failed"

info "opening the campaign (weeks × team × deadline $DEADLINE)"
curl -sf -X POST "$API_BASE/coach_wish_campaigns" "${auth[@]}" \
  -d "{\"calendarEntryId\":\"$ENTRY_ID\",\"deadline\":\"$DEADLINE\",\"weeks\":[\"$MONDAY\"],\"teamIds\":[\"$TEAM_ID\"]}" > /tmp/smoke-cw-campaign.json \
  || die "campaign creation failed"
CAMPAIGN_ID=$(jget id < /tmp/smoke-cw-campaign.json)
WISH_TOKEN=$(python3 -c "
import json
d=json.load(open('/tmp/smoke-cw-campaign.json'))
coaches=[c for c in d.get('coaches',[]) if c.get('coachId')=='$COACH_ID']
print(coaches[0]['token'] if coaches else '')")
[ -n "$WISH_TOKEN" ] || die "no token minted for the smoke coach (campaign retained: $(jget coaches < /tmp/smoke-cw-campaign.json))"

info "send-links (email rail — mailpit)"
SENT=$(curl -sf -X POST "$API_BASE/coach_wish_campaigns/$CAMPAIGN_ID/send-links" "${auth[@]}" -d '{}' | jget sent)
[ "$SENT" -ge 1 ] 2>/dev/null || die "send-links sent nothing (sent=$SENT)"

info "PUBLIC page: pre-fill from the bare token (no JWT)"
PUBLIC=$(curl -sf "$API_BASE/coach-wishes/public/$WISH_TOKEN") || die "public GET failed"
FIRSTNAME=$(echo "$PUBLIC" | jget coachFirstName)
[ "$FIRSTNAME" = "Smoke" ] || die "public page did not recognize the coach (got: $FIRSTNAME)"

info "PUBLIC submission: 2 sessions wanted, Wednesday unavailable"
SUBMIT_HTTP=$(curl -s -o /tmp/smoke-cw-submit.json -w '%{http_code}' -X POST "$API_BASE/coach-wishes/public/$WISH_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"submissions\":[{\"teamId\":\"$TEAM_ID\",\"weekStart\":\"$MONDAY\",\"slotsWanted\":2,\"unavailableDays\":[3],\"comment\":\"smoke\"}]}")
[ "$SUBMIT_HTTP" = "200" ] || die "public submission returned HTTP $SUBMIT_HTTP: $(cat /tmp/smoke-cw-submit.json)"

info "manager side: the wish is readable"
WISHES=$(curl -sf "$API_BASE/coach_wishes?calendarEntryId=$ENTRY_ID" "${auth[@]}")
GOT=$(python3 -c "
import json
d=json.load(open('/dev/stdin'))
rows=d.get('member', d if isinstance(d,list) else [])
match=[w for w in rows if w.get('teamId')=='$TEAM_ID' and w.get('slotsWanted')==2 and 3 in (w.get('unavailableDays') or [])]
print('yes' if match else 'no')" <<<"$WISHES")
[ "$GOT" = "yes" ] || die "submitted wish not found on the manager side"

ok "campaign → token → public pre-fill → submission → persisted wish: the solicitation rail works end-to-end"
