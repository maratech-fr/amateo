#!/usr/bin/env bash
# Fail-closed sandbox guard — the heart of the 3-database split (P4-141).
#
# SOURCE this at the top of every MUTATING dev script (smoke/e2e/demo/load).
# It resolves the database the running app ACTUALLY targets (via the live
# `current_database()`, so it honours the whole Symfony dotenv precedence —
# including a gitignored backend/.env.local put in place by `make play`) and
# REFUSES to let the script proceed unless that database is the AI sandbox
# (`amateo_dev`) or a test database (`*_test`).
#
# Why it exists: the mutating scripts purge/seed at will (`doctrine:fixtures:load`,
# club creation, deletions). One dev database used to be BOTH the founder's play
# base AND the AI's playground — a single smoke could wipe the founder's data.
# The split gives the founder `amateo_local` (via `make play`); this guard is
# what makes the wipe IMPOSSIBLE: pointed at `amateo_local` (or prod `amateo`,
# or any unknown name), the script dies BEFORE its first mutation.
#
# Contract:
#   - Source it (`. "$SCRIPT_DIR/lib/sandbox-guard.sh"`); do not exec it from a
#     script (executed standalone it still works as a yes/no gate — exit 0 =
#     allowed, exit 1 = refused — which is how the Makefiles consume it).
#   - On success it EXPORTS `SANDBOX_DB` (the resolved name); psql helpers must
#     target "$SANDBOX_DB" instead of a hardcoded database name.
#   - On refusal it prints a clear message and exits 1, killing the sourcing
#     script before anything is written.
#   - Override the resolution env with SANDBOX_GUARD_APP_ENV (default: dev).
#   - Self-test hook: SANDBOX_GUARD_SELFTEST=1 skips the live resolution (used by
#     generate-schedule-test.sh, which drives generate-schedule.sh with a FAKE
#     curl and never reaches a real database).

# Idempotent: a parent may have sourced us already (exported SANDBOX_DB inherited).
if [[ -n "${SANDBOX_GUARD_LOADED:-}" ]]; then
  return 0 2>/dev/null || exit 0
fi
SANDBOX_GUARD_LOADED=1

_sandbox_die() {
  printf '\033[0;31mFAIL:\033[0m sandbox-guard: %s\n' "$1" >&2
  exit 1
}

# Self-test bypass — see the docblock. Safe because that run has no real backend.
if [[ "${SANDBOX_GUARD_SELFTEST:-0}" == "1" ]]; then
  SANDBOX_DB="selftest_test"
  export SANDBOX_DB
  return 0 2>/dev/null || exit 0
fi

# Resolve our own compose file from THIS file's location (backend/scripts/lib/…),
# so the guard is independent of whatever helpers the sourcing script defines.
_SANDBOX_GUARD_COMPOSE="${SANDBOX_GUARD_COMPOSE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/docker-compose.yml}"
_SANDBOX_GUARD_ENV="${SANDBOX_GUARD_APP_ENV:-dev}"

# Ground truth: ask the live app connection which database it is on. This reflects
# real env vars + dotenv precedence (.env.local wins), exactly what the app writes.
_sandbox_resolve() {
  docker compose -f "$_SANDBOX_GUARD_COMPOSE" exec -T -e APP_ENV="$_SANDBOX_GUARD_ENV" php-fpm \
    sh -c 'cd /app/backend && php bin/console dbal:run-sql "SELECT current_database() AS db"' 2>/dev/null \
    | sed -E 's/[[:space:]]//g' | grep -vE '^-*$' | grep -vxE 'db' | head -1
}

SANDBOX_DB="$(_sandbox_resolve || true)"

[[ -n "$SANDBOX_DB" ]] || _sandbox_die "cible non résolue — la stack est-elle démarrée (make start) ? Refus par précaution (fail-closed)."

if [[ "$SANDBOX_DB" == "amateo_dev" || "$SANDBOX_DB" == *_test ]]; then
  export SANDBOX_DB
  printf '\033[0;32m==>\033[0m sandbox-guard: cible autorisée « %s »\n' "$SANDBOX_DB" >&2
else
  _sandbox_die "cible refusée « $SANDBOX_DB » — seuls le bac à sable amateo_dev et les bases *_test sont permis (jamais amateo_local ni la prod). Reviens au bac à sable avec « make sandbox »."
fi
