#!/usr/bin/env bash
# Multi-club load-measurement harness — fires N concurrent schedule generations
# at the dev stack (optionally recreated with the PROD memory limits) and reports
# end-to-end timings, solver wall-time, queue waiting, RAM peaks vs limits, and
# OOM kills. It MEASURES; it changes no quota, budget or worker count.
#
#   backend/scripts/load-test/run-load-test.sh [--clubs N] [--rounds R] [--no-limits]
#
# Two serializers stand between "N clubs at once" and "N solves at once", by
# DESIGN — the report restates them so a slow lot is not misread as a bug:
#   1. a SINGLE messenger-worker consumes the async queue one message at a time;
#   2. the engine caps itself at max_concurrent_solves=1 globally.
# So generations queue up: club i's end-to-end legitimately includes the wait
# behind clubs 1..i-1. "Wait" in the report = end-to-end − solver wall-time.
#
# Exit: 0 = every club reached COMPLETED across every round, 1 = any failure.
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$SCRIPT_DIR/../../.." && pwd)
COMPOSE_BASE="$ROOT/docker-compose.yml"
COMPOSE_LOAD="$ROOT/docker-compose.load.yml"
GEN_SCRIPT="$ROOT/backend/scripts/generate-schedule.sh"
API_BASE="http://localhost:8080/api"
# redis://redis:6379/messages (backend/.env MESSENGER_TRANSPORT_DSN) — verified
# against the live stream on the first sample below.
STREAM="messages"
# Fixed password of every load-test manager account (BcclSeedProfile::loadTest()).
MANAGER_PASSWORD="charge-load-test-pwd"
# The services whose RAM the overlay caps (and that we sample / inspect).
SERVICES=(php-fpm postgres redis messenger-worker engine pdf-worker mercure)

CLUBS=5
ROUNDS=1
LIMITS=1

GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; YEL=$'\033[1;33m'; BLUE=$'\033[0;34m'; NC=$'\033[0m'
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$1"; }
ok()   { printf '%bOK:%b %s\n' "$GREEN" "$NC" "$1"; }
warn() { printf '%bWARN:%b %s\n' "$YEL" "$NC" "$1"; }
die()  { printf '%bFAIL:%b %s\n' "$RED" "$NC" "$1" >&2; exit 1; }

usage() {
  cat <<EOF
Usage: $(basename "$0") [OPTIONS]

  --clubs N     Number of clubs generating at once (default 5, 1..99)
  --rounds R    Repeat the burst R times (default 1)
  --no-limits   Do NOT apply the prod memory-limit overlay (measure uncapped)
  --help, -h    Show this help
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --clubs)     [[ $# -ge 2 ]] || die "--clubs requires a value"; CLUBS="$2"; shift 2 ;;
    --clubs=*)   CLUBS="${1#*=}"; shift ;;
    --rounds)    [[ $# -ge 2 ]] || die "--rounds requires a value"; ROUNDS="$2"; shift 2 ;;
    --rounds=*)  ROUNDS="${1#*=}"; shift ;;
    --no-limits) LIMITS=0; shift ;;
    --help|-h)   usage; exit 0 ;;
    *)           die "Unknown option: $1" ;;
  esac
done

[[ "$CLUBS" =~ ^[0-9]+$ && "$CLUBS" -ge 1 && "$CLUBS" -le 99 ]] || die "--clubs must be 1..99"
[[ "$ROUNDS" =~ ^[0-9]+$ && "$ROUNDS" -ge 1 ]] || die "--rounds must be >= 1"

# Compose invocation: base always, overlay only when limits are on.
dc() {
  if [[ "$LIMITS" -eq 1 ]]; then
    docker compose -f "$COMPOSE_BASE" -f "$COMPOSE_LOAD" "$@"
  else
    docker compose -f "$COMPOSE_BASE" "$@"
  fi
}

# DATABASE_ADMIN_URL, read exactly like backend/Makefile (real env beats dotenv;
# .env.local overrides .env, last wins) — the seed bypasses RLS on it.
# `.env.local` is optional; the trailing `|| true` keeps a missing file from
# tripping `set -o pipefail` (cat would exit non-zero and abort the script).
DB_ADMIN_URL=$(cat "$ROOT/backend/.env" "$ROOT/backend/.env.local" 2>/dev/null \
  | awk -F= '$1=="DATABASE_ADMIN_URL" {sub(/^[^=]*=/,""); gsub(/"/,""); print}' | tail -n 1 || true)
[[ -n "$DB_ADMIN_URL" ]] || die "DATABASE_ADMIN_URL not found in backend/.env(.local)"

psql_admin() { dc exec -T -e DATABASE_URL="$DB_ADMIN_URL" postgres \
  psql -U clubscheduler -d "$SANDBOX_DB" -tA -c "$1"; }

# ---------------------------------------------------------------------------
# Pre-flight
# ---------------------------------------------------------------------------
dc ps php-fpm --format '{{.State}}' 2>/dev/null | grep -q running || die "stack down — run 'make start' first"

# Fail-closed sandbox guard (P4-141): this harness seeds throwaway clubs — refuse
# any DB but the AI sandbox (amateo_dev) or *_test. Exports SANDBOX_DB for psql_admin.
source "$SCRIPT_DIR/../lib/sandbox-guard.sh"

CGROUP_MEM=$(docker info --format '{{.MemoryLimit}}' 2>/dev/null || echo unknown)
LIMITS_NOTE=""
if [[ "$LIMITS" -eq 1 ]]; then
  if [[ "$CGROUP_MEM" != "true" ]]; then
    warn "host reports no cgroup memory-limit support (MemoryLimit=$CGROUP_MEM) — mem_limit will be IGNORED (WSL2?); measuring UNCAPPED"
    LIMITS_NOTE="requested, but host cgroup memory support = $CGROUP_MEM → limits IGNORED by Docker"
  else
    LIMITS_NOTE="applied (prod mem_limits via docker-compose.load.yml)"
  fi
  info "recreating stack WITH memory limits (docker-compose.load.yml)"
  dc up -d "${SERVICES[@]}" >/dev/null
  # Fresh consumer group avoids stuck-queue flakiness after the recreate.
  dc restart messenger-worker >/dev/null 2>&1 || true
else
  LIMITS_NOTE="disabled (--no-limits): dev stack, no mem_limit"
  info "running WITHOUT memory limits (--no-limits)"
fi

OUT_ROOT="$ROOT/var/load-test"
STAMP=$(date +%Y-%m-%d_%H-%M-%S)
OUT="$OUT_ROOT/$STAMP"
mkdir -p "$OUT"
REPORT="$OUT/report.md"
info "artifacts → $OUT"

# ---------------------------------------------------------------------------
# Seed N throwaway clubs (admin connection; dev-only command)
# ---------------------------------------------------------------------------
info "seeding $CLUBS load-test club(s)"
dc exec -T -e APP_ENV=dev -e DATABASE_URL="$DB_ADMIN_URL" php-fpm \
  php bin/console app:load-test:seed-clubs --count="$CLUBS" \
  || die "seeding failed"

# Resolve each club id by its deterministic slug.
declare -A CLUB_ID
for ((i = 1; i <= CLUBS; i++)); do
  id=$(psql_admin "SELECT id FROM club WHERE slug='club-charge-$i'" | tr -d '[:space:]')
  [[ -n "$id" ]] || die "could not resolve club id for club-charge-$i"
  CLUB_ID[$i]="$id"
done
ok "resolved $CLUBS club id(s)"

# Restart php-fpm so the freshly-registered dev command / seeded state is never
# served from a WSL2-frozen bind mount. That changes php-fpm's IP, so nginx must
# refresh its upstream or every request 502s on a stale address.
dc restart php-fpm >/dev/null 2>&1 || true
dc restart nginx >/dev/null 2>&1 || true
sleep 3
for _ in 1 2 3 4 5; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$API_BASE/schedules" || echo 000)
  [[ "$code" != "502" && "$code" != "000" ]] && break
  warn "nginx not ready (HTTP $code) — retrying"; dc restart nginx >/dev/null 2>&1 || true; sleep 3
done

# ---------------------------------------------------------------------------
# Container ids for sampling + inspection
# ---------------------------------------------------------------------------
declare -A CID
for svc in "${SERVICES[@]}"; do
  CID[$svc]=$(dc ps -q "$svc" 2>/dev/null | head -1)
done

# ---------------------------------------------------------------------------
# Background sampler: every 5s, docker stats CSV + messenger queue length.
# ---------------------------------------------------------------------------
STATS_CSV="$OUT/stats.csv"
QUEUE_CSV="$OUT/queue.csv"
echo "epoch,container,mem_usage,mem_perc,cpu_perc" >"$STATS_CSV"
echo "epoch,stream,xlen" >"$QUEUE_CSV"

sampler_ids=()
for svc in "${SERVICES[@]}"; do [[ -n "${CID[$svc]}" ]] && sampler_ids+=("${CID[$svc]}"); done

STREAM_VERIFIED=0
sampler() {
  while :; do
    local now; now=$(date +%s)
    docker stats --no-stream --format '{{.Name}},{{.MemUsage}},{{.MemPerc}},{{.CPUPerc}}' "${sampler_ids[@]}" 2>/dev/null \
      | while IFS=, read -r name mem memp cpu; do
          # mem_usage carries a comma-free "X / Y"; keep only the used side.
          printf '%s,%s,%s,%s,%s\n' "$now" "$name" "${mem%% /*}" "$memp" "$cpu" >>"$STATS_CSV"
        done
    local xlen
    xlen=$(dc exec -T redis redis-cli XLEN "$STREAM" 2>/dev/null | tr -d '[:space:]')
    [[ -z "$xlen" ]] && xlen="?"
    echo "$now,$STREAM,$xlen" >>"$QUEUE_CSV"
    sleep 5
  done
}
sampler & SAMPLER_PID=$!
# One-shot verification that the stream name is the real one (non-empty XLEN, or
# key exists). A wrong name would silently log "?" forever.
first_xlen=$(dc exec -T redis redis-cli EXISTS "$STREAM" 2>/dev/null | tr -d '[:space:]')
if [[ "$first_xlen" == "1" ]]; then STREAM_VERIFIED=1; fi

cleanup() { kill "$SAMPLER_PID" 2>/dev/null || true; }
trap cleanup EXIT

# ---------------------------------------------------------------------------
# The burst(s)
# ---------------------------------------------------------------------------
BURST_TIMEOUT=$((CLUBS * 700))
CLUBS_CSV="$OUT/clubs.csv"
echo "round,club,slug,club_id,start_epoch,end_epoch,e2e_s,exit_code,status,score" >"$CLUBS_CSV"

run_one_club() {
  local round="$1" i="$2" club_id="$3"
  local log="$OUT/round-${round}_club-${i}.log"
  local start end rc status score
  start=$(date +%s)
  SCHEDULER_EMAIL="charge-$i@clubscheduler.local" \
  SCHEDULER_PASSWORD="$MANAGER_PASSWORD" \
  TIMEOUT_SECONDS="$BURST_TIMEOUT" \
  PENDING_TIMEOUT_SECONDS="$BURST_TIMEOUT" \
    "$GEN_SCRIPT" --club-id "$club_id" >"$log" 2>&1 && rc=0 || rc=$?
  end=$(date +%s)
  status=$(grep -oE 'Status: [A-Z]+' "$log" | tail -1 | awk '{print $2}')
  [[ -z "$status" ]] && status="UNKNOWN"
  score=$(grep -oE 'Score: [0-9]+' "$log" | tail -1 | awk '{print $2}')
  [[ -z "$score" ]] && score="-"
  echo "$round,$i,club-charge-$i,$club_id,$start,$end,$((end - start)),$rc,$status,$score" >>"$CLUBS_CSV"
}

LOT_START=$(date +%s)
for ((r = 1; r <= ROUNDS; r++)); do
  info "round $r/$ROUNDS — launching $CLUBS concurrent generation(s)"
  pids=()
  for ((i = 1; i <= CLUBS; i++)); do
    run_one_club "$r" "$i" "${CLUB_ID[$i]}" &
    pids+=($!)
  done
  for p in "${pids[@]}"; do wait "$p" || true; done
  ok "round $r complete"
done
LOT_END=$(date +%s)
LOT_DURATION=$((LOT_END - LOT_START))

cleanup
trap - EXIT

# ---------------------------------------------------------------------------
# Post-lot facts from the DB (solver wall-time, final status, timestamps)
# ---------------------------------------------------------------------------
declare -A DB_WALL DB_STATUS
while IFS='|' read -r slug status wall; do
  [[ -z "$slug" ]] && continue
  # Keep the FIRST row per slug (query is ordered newest-first).
  if [[ -z "${DB_WALL[$slug]:-}" ]]; then
    DB_WALL[$slug]="${wall:-}"
    DB_STATUS[$slug]="$status"
  fi
done < <(psql_admin "SELECT c.slug, s.status, s.solver_wall_time_ms
                     FROM schedule s JOIN club c ON c.id = s.club_id
                     WHERE c.slug LIKE 'club-charge-%'
                     ORDER BY c.slug, s.created_at DESC" | tr -s ' ')

# OOM kills per container.
declare -A OOM
for svc in "${SERVICES[@]}"; do
  if [[ -n "${CID[$svc]}" ]]; then
    OOM[$svc]=$(docker inspect --format '{{.State.OOMKilled}}' "${CID[$svc]}" 2>/dev/null || echo "?")
  else
    OOM[$svc]="?"
  fi
done

# Peak RAM per container from the sampler CSV (used side already isolated).
declare -A PEAK
while IFS=, read -r _ name mem _ _; do
  [[ "$name" == "container" ]] && continue
  [[ -z "$name" ]] && continue
  # Normalise MiB/GiB to MiB for a comparable peak.
  local_mib=$(python3 - "$mem" <<'PY'
import re, sys
raw = sys.argv[1].strip()
m = re.match(r'([0-9.]+)\s*([KMG]i?B)', raw)
if not m:
    print(0); raise SystemExit
val = float(m.group(1)); unit = m.group(2)
factor = {'KiB': 1/1024, 'KB': 1/1024, 'MiB': 1, 'MB': 1, 'GiB': 1024, 'GB': 1024}.get(unit, 1)
print(round(val * factor, 1))
PY
)
  cur="${PEAK[$name]:-0}"
  greater=$(python3 -c "print(1 if float('$local_mib') > float('$cur') else 0)")
  [[ "$greater" == "1" ]] && PEAK[$name]="$local_mib"
done <"$STATS_CSV"

peak_queue=$(awk -F, 'NR>1 && $3 ~ /^[0-9]+$/ {if ($3>m) m=$3} END{print m+0}' "$QUEUE_CSV")

# ---------------------------------------------------------------------------
# Markdown report
# ---------------------------------------------------------------------------
completed=0; total_rows=0
{
  echo "# Load-test report — $STAMP"
  echo
  echo "- Clubs per burst: **$CLUBS**   Rounds: **$ROUNDS**"
  echo "- Memory limits: **$LIMITS_NOTE**"
  echo "- Host cgroup memory support (\`docker info .MemoryLimit\`): \`$CGROUP_MEM\`"
  echo "- Messenger stream: \`$STREAM\` (existence verified: $([[ "$STREAM_VERIFIED" -eq 1 ]] && echo yes || echo 'no / drained at first sample'))   peak queue length: **$peak_queue**"
  echo "- Lot wall-clock: **${LOT_DURATION}s**"
  echo
  echo "> Two serializers by design: a SINGLE messenger-worker (one async message"
  echo "> at a time) and the engine's global \`max_concurrent_solves=1\`. Generations"
  echo "> therefore queue; a club's wait = end-to-end − solver wall-time."
  echo
  echo "## Per club (all rounds)"
  echo
  echo "| Round | Club | Status (run/db) | End-to-end (s) | Solver wall (s) | Wait (s) | Score |"
  echo "|------:|:-----|:----------------|---------------:|----------------:|---------:|:------|"
  while IFS=, read -r round club slug club_id start end e2e rc status score; do
    [[ "$round" == "round" ]] && continue
    total_rows=$((total_rows + 1))
    [[ "$status" == "COMPLETED" ]] && completed=$((completed + 1))
    wall_ms="${DB_WALL[$slug]:-}"
    if [[ -n "$wall_ms" && "$wall_ms" =~ ^[0-9]+$ ]]; then
      wall_s=$(python3 -c "print(round($wall_ms/1000,1))")
      wait_s=$(python3 -c "print(round($e2e - $wall_ms/1000,1))")
    else
      wall_s="-"; wait_s="-"
    fi
    echo "| $round | $slug | $status / ${DB_STATUS[$slug]:-?} | $e2e | $wall_s | $wait_s | $score |"
  done <"$CLUBS_CSV"
  echo
  # Throughput: completed generations per hour over the lot.
  if [[ "$LOT_DURATION" -gt 0 ]]; then
    thr=$(python3 -c "print(round($completed*3600/$LOT_DURATION,1))")
  else
    thr="-"
  fi
  echo "- **$completed / $total_rows** generation(s) COMPLETED"
  echo "- Throughput: **$thr** completed generation(s) / hour"
  echo
  echo "## Peak RAM per container vs limit"
  echo
  echo "| Container | Peak RAM (MiB) | Prod limit | OOMKilled |"
  echo "|:----------|---------------:|:-----------|:----------|"
  declare -A LIMIT_MB=( [php-fpm]=1024 [postgres]=512 [redis]=256 [messenger-worker]=384 [engine]=512 [pdf-worker]=512 [mercure]=128 )
  for svc in "${SERVICES[@]}"; do
    cname=$(docker inspect --format '{{.Name}}' "${CID[$svc]}" 2>/dev/null | sed 's#^/##')
    [[ -z "$cname" ]] && cname="$svc"
    peak="${PEAK[$cname]:-?}"
    lim="${LIMIT_MB[$svc]} MiB"
    [[ "$LIMITS" -eq 0 || "$CGROUP_MEM" != "true" ]] && lim="${LIMIT_MB[$svc]} MiB (not enforced)"
    echo "| $svc | $peak | $lim | ${OOM[$svc]} |"
  done
  echo
  echo "## Artifacts"
  echo
  echo "- Per-club timings: \`clubs.csv\`"
  echo "- Resource samples (5s): \`stats.csv\`"
  echo "- Queue length (5s): \`queue.csv\`"
  echo "- Per-run logs: \`round-*_club-*.log\`"
} >"$REPORT"

echo
info "===== REPORT ====="
cat "$REPORT"
echo
if [[ "$LIMITS" -eq 1 ]]; then
  warn "Teardown reminder: drop the load overlay with — docker compose -f docker-compose.yml up -d"
fi

[[ "$completed" -eq "$total_rows" && "$total_rows" -gt 0 ]] || die "not every generation reached COMPLETED (see report)"
ok "every generation COMPLETED"
