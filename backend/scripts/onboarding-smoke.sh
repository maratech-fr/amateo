#!/usr/bin/env bash
# Onboarding end-to-end smoke: register a brand-new club, enter the MINIMUM
# (1 team + 1 gym with a slot + 1 coach), generate, and assert a COMPLETED plan.
# Mirrors "I create my account, do the minimum, generate, and get my planning".
# Complements the Behat feature « génération du planning de saison » (which reuses the pre-seeded fixtures club).
set -euo pipefail

API="${API_BASE:-http://localhost:8080/api}"
MAILPIT="${MAILPIT_BASE:-http://localhost:8025}"
GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
ok()   { printf '%bPASS:%b %s\n' "$GREEN" "$NC" "$1"; }
die()  { printf '%bFAIL:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$1"; }

# Fail-closed sandbox guard (P4-141): this smoke registers a REAL club — refuse
# to run against anything but the AI sandbox (amateo_dev) or a *_test DB.
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

ARA="ONB$(date +%s)"
EMAIL="onb-$ARA@smoke.fr"
# A3: register defers everything to email verification — it returns a neutral 202
# (no token, no club yet). The club + JWT are materialised only by /register/verify,
# whose raw token arrives by email → pulled back out of Mailpit here. Since SEC-16
# the JWT itself comes back as an httpOnly cookie, not in the JSON body.
# Async generation needs a CONSUMING worker: a queued message nobody consumes
# leaves the schedule PENDING forever (the smoke then times out on a healthy
# solver). Same guarantee as the Behat features — every smoke stands alone.
docker compose -f "$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/docker-compose.yml" up -d messenger-worker >/dev/null 2>&1 || true

info "register new club $ARA (deferred verification)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/register" -H 'Content-Type: application/json' \
  -d "{\"email\":\"$EMAIL\",\"password\":\"Password123!\",\"firstName\":\"On\",\"lastName\":\"Board\",\"ara\":\"$ARA\",\"club_name\":\"Onb $ARA\",\"consent\":true}")
[[ "$CODE" == "202" ]] || die "register returned $CODE (expected 202)"

info "pull the verification link from Mailpit"
RAW=""
for i in $(seq 1 20); do
  MSGID=$(curl -s "$MAILPIT/api/v1/search?query=to:$EMAIL" | python3 -c 'import sys,json;m=json.load(sys.stdin).get("messages",[]);print(m[0]["ID"] if m else "")')
  if [[ -n "$MSGID" ]]; then
    RAW=$(curl -s "$MAILPIT/api/v1/message/$MSGID" | python3 -c 'import sys,json,re;t=json.load(sys.stdin).get("Text","");m=re.search(r"verify-email/([a-f0-9]{64})",t);print(m.group(1) if m else "")')
    [[ -n "$RAW" ]] && break
  fi
  sleep 1
done
[[ -n "$RAW" ]] || die "no verification email/token found in Mailpit"

info "verify email → materialise club + obtain JWT"
# SEC-16 (audit) : le JWT ne sort plus dans le corps de la réponse — il est posé
# en cookie httpOnly. Ce script n'est pas un navigateur : il le lit dans l'en-tête
# Set-Cookie et continue en Bearer (chemin resté ouvert pour les scripts d'ops).
TOKEN=$(curl -si -X POST "$API/register/verify" -H 'Content-Type: application/json' -d "{\"token\":\"$RAW\"}" \
  | grep -oiP 'set-cookie: *BEARER=\K[^;]+' | head -1)
[[ -n "$TOKEN" ]] || die "verify did not set the BEARER cookie"
H=(-H "Authorization: Bearer $TOKEN")
JC=(-H "Content-Type: application/json")

# P3-4: verification no longer materialises the club — the request awaits the
# club's approval (FFBB mail) or the superadmin. Approve via the dev relay
# (the real approval service, 404 in prod) so the smoke tests the AFTER-creation.
info "approve club creation request (dev relay)"
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/dev/approve-club-request" "${H[@]}")
[[ "$CODE" == "200" ]] || die "dev approval returned $CODE (expected 200)"

# Isolation: a fresh club must be empty.
COUNT=$(curl -s "$API/teams" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(len(d.get("member",d)))')
[[ "$COUNT" == "0" ]] || die "fresh club is not empty (isolation leak): $COUNT teams"
ok "fresh club is empty (isolation)"

info "enter minimal data"
CAT=$(curl -s "$API/sport_categories" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("member",d)[0]["id"])')
curl -s -o /dev/null "$API/teams" "${H[@]}" "${JC[@]}" -d "{\"name\":\"SM1\",\"sportCategoryId\":\"$CAT\",\"priorityTierId\":1}"
VEN=$(curl -s -X POST "$API/venues" "${H[@]}" "${JC[@]}" -d '{"name":"Gym A","source":"manual"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
curl -s -o /dev/null -X POST "$API/venue_training_slots" "${H[@]}" "${JC[@]}" -d "{\"venueId\":\"$VEN\",\"dayOfWeek\":1,\"startTime\":\"18:00\",\"durationMinutes\":90,\"capacity\":1}"
curl -s -o /dev/null -X POST "$API/coaches" "${H[@]}" "${JC[@]}" -d '{"firstName":"Jean"}'
ok "minimal data created (1 team, 1 gym+slot, 1 coach)"

info "create schedule + generate"
SID=$(curl -s -X POST "$API/schedules" "${H[@]}" "${JC[@]}" -d '{"name":"Mon planning","status":"DRAFT"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["id"])')
CODE=$(curl -s -o /dev/null -w '%{http_code}' -X POST "$API/schedules/$SID/generate" "${H[@]}")
[[ "$CODE" == "202" ]] || die "generate returned $CODE"

ONB=$(curl -s "$API/me" "${H[@]}" | python3 -c 'import sys,json;print(json.load(sys.stdin)["club"]["onboardingCompleted"])')
[[ "$ONB" == "True" ]] || die "onboardingCompleted not set on launch"
ok "onboarding completed on launch"

info "poll until COMPLETED"
for i in $(seq 1 60); do
  ST=$(curl -s "$API/schedules/$SID" "${H[@]}" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("status"),d.get("score"))')
  printf '  [%d] %s\n' "$i" "$ST"
  case "$ST" in
    COMPLETED*) ok "planning COMPLETED — onboarding works end-to-end"; exit 0;;
    FAILED*) die "generation FAILED";;
  esac
  sleep 5
done
die "timeout waiting for COMPLETED"
