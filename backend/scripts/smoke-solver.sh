#!/usr/bin/env bash
# Solver smoke-test — end-to-end verification that the CP-SAT solver responds
# and a schedule is produced. Diagnostics/warnings in the result are OK;
# the pass criterion is a schedule reaching status COMPLETED.
#
# Self-sufficient for local dev: ensures the JWT keypair, dev fixtures,
# messenger-worker and a fresh token, then drives generate-schedule.sh.
# Run this whenever a change touches engine/ or backend/ (see CLAUDE.md §7).
#
# Usage: backend/scripts/smoke-solver.sh
# Exit: 0 = schedule COMPLETED, 1 = any failure.
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../.." && pwd)
COMPOSE="$ROOT/docker-compose.yml"
API_BASE="http://localhost:8080/api"
USER_EMAIL="mara.mb@bccl.fr"
TOKEN_TTL=31536000 # 1 year

GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; YEL=$'\033[1;33m'; NC=$'\033[0m'
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$1"; }
ok()   { printf '%bPASS:%b %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%bWARN:%b %s\n' "$YEL" "$NC" "$1"; }
die()  { printf '%bFAIL:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }
BLUE=$'\033[0;34m'

dc()  { docker compose -f "$COMPOSE" "$@"; }
php() { dc exec -T -e APP_ENV=dev php-fpm sh -c "cd /app/backend && $1" 2>&1 | grep -vE '^\[debug\]|Notified event' || true; }

# 1. Stack must be up
dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): refuse to run against anything but the AI
# sandbox (amateo_dev) or a *_test DB — exports SANDBOX_DB for the psql helper.
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

# 2. JWT keypair (token minting needs it)
if ! php 'test -f config/jwt/private.pem && echo yes' | grep -q yes; then
  warn "JWT keypair missing — generating"
  php 'php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction' >/dev/null
fi

# 3. Dev fixtures — need a seeded club the smoke USER belongs to. Multiple
#    clubs exist in fixtures (BCCL + the demo club), so pick the club tied to
#    USER_EMAIL's membership — a bare "club LIMIT 1" can return a club the
#    token user isn't a member of, yielding a 403 at generate time.
club_for_user() {
  php "php bin/console dbal:run-sql \"SELECT c.id FROM club c JOIN club_user cu ON cu.club_id = c.id JOIN app_user u ON u.id = cu.user_id WHERE u.email = '$USER_EMAIL' LIMIT 1\"" \
    | grep -oiE '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}' | head -1 || true
}
CLUB_ID=$(club_for_user)
if [[ -z "$CLUB_ID" ]]; then
  warn "no club for $USER_EMAIL — loading fixtures"
  dc exec -T -e APP_ENV=dev -u 1000:1000 php-fpm sh -c 'cd /app/backend && php bin/console doctrine:fixtures:load --no-interaction' >/dev/null
  CLUB_ID=$(club_for_user)
fi
[[ -n "$CLUB_ID" ]] || die "could not resolve a club id (fixtures failed?)"
ok "club id: $CLUB_ID"

# 3bis. A club whose SEASON plan POINTS a version refuses a new one (409, ADR-0002
# inv. 1: « rouvrez-le avant d'en préparer un autre »). That is the normal state
# after anything validated the socle — the Playwright suite does it in CI, a
# founder does it in dev. The smoke re-opens for the duration and RESTORES the
# pointer on exit: it must never depend on what ran before it, nor leave the club
# in another state than it found it.
psql_dev() { dc exec -T postgres psql -U amateo_owner -d "$SANDBOX_DB" -tA -c "$1"; }
RESTORE_CHOSEN=$(psql_dev "SELECT chosen_schedule_id FROM schedule_plan WHERE club_id='$CLUB_ID' AND type='SEASON' LIMIT 1" | tr -d '[:space:]')
if [[ -n "$RESTORE_CHOSEN" ]]; then
  info "season plan in force — re-opening for the smoke (restored on exit)"
  psql_dev "UPDATE schedule_plan SET chosen_schedule_id=NULL WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null
fi
restore_pointer() {
  [[ -n "$RESTORE_CHOSEN" ]] || return 0
  # Only re-point a version that still EXISTS: the run may have replaced it, and
  # pointing a deleted id would fail on the FK and leave the club re-opened —
  # a state the caller never asked for. Fall back on the freshest COMPLETED
  # version of the season plan: what matters to the next smoke is « socle in
  # force », not which exact version carries it.
  local target
  target=$(psql_dev "SELECT id FROM schedule WHERE id='$RESTORE_CHOSEN'" | tr -d '[:space:]')
  if [[ -z "$target" ]]; then
    target=$(psql_dev "SELECT s.id FROM schedule s JOIN schedule_plan p ON p.id=s.schedule_plan_id WHERE s.club_id='$CLUB_ID' AND p.type='SEASON' AND s.status='COMPLETED' ORDER BY s.created_at DESC LIMIT 1" | tr -d '[:space:]')
  fi
  [[ -n "$target" ]] && psql_dev "UPDATE schedule_plan SET chosen_schedule_id='$target' WHERE club_id='$CLUB_ID' AND type='SEASON'" >/dev/null || true
}
trap restore_pointer EXIT

# 4. messenger-worker up and consuming (async generation)
dc up -d messenger-worker >/dev/null 2>&1 || true
dc restart messenger-worker >/dev/null 2>&1 || true # fresh consumer — avoids stuck-queue flakiness

# 5. Recover from stale php-fpm upstream IP (nginx 502 after stack changes)
code=$(curl -s -o /dev/null -w '%{http_code}' "$API_BASE/schedules" || echo 000)
if [[ "$code" == "502" ]]; then
  warn "nginx 502 (stale upstream) — restarting nginx"
  dc restart nginx >/dev/null 2>&1 || true
  sleep 3
fi

# 6. Fresh dev token (valid regardless of keypair regeneration above)
TOKEN=$(php "php bin/console lexik:jwt:generate-token $USER_EMAIL --ttl=$TOKEN_TTL --user-class='App\\Entity\\User'" | tr -d '[:space:]')
[[ "$TOKEN" =~ ^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$ ]] || die "could not mint dev token"

# 7. Drive the real e2e: create -> generate -> poll to terminal status
info "generating schedule for club $CLUB_ID"
out=$("$SCRIPT_DIR/generate-schedule.sh" --club-id "$CLUB_ID" --token "$TOKEN" 2>&1 | sed 's/\x1b\[[0-9;]*m//g') || true
printf '%s\n' "$out" | tail -8

# 8. Assert
if printf '%s' "$out" | grep -q 'COMPLETED'; then
  # Un score NÉGATIF est légitime (pénalités PRÉFÉRÉ des règles de bien-être,
  # P2-28 ; le −30 du seed BCCL = artefact P4-97 documenté). Sans le `-?` et le
  # `|| true`, grep sortait en échec sur « Score: -30 » et `set -e` tuait le
  # script APRÈS un COMPLETED pourtant conforme — un faux rouge de CI.
  score=$(printf '%s' "$out" | grep -oE 'Score: -?[0-9]+' | tail -1 || true)
  ok "solver responded, schedule COMPLETED (${score:-no score}). Diagnostics/warnings are acceptable."
  exit 0
fi
die "no COMPLETED schedule — solver did not produce a plan (see output above)"
