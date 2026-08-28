#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
SCRIPT="$SCRIPT_DIR/generate-schedule.sh"

# This is a hermetic self-test: it drives generate-schedule.sh with a FAKE curl
# and NO real backend, so there is no database to resolve and nothing to mutate.
# SANDBOX_GUARD_SELFTEST makes the fail-closed sandbox guard (P4-141) a no-op —
# here and, because it is exported, in the generate-schedule.sh child too.
export SANDBOX_GUARD_SELFTEST=1
source "$SCRIPT_DIR/lib/sandbox-guard.sh"

pass() {
  printf 'PASS: %s\n' "$1"
}

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

[[ -f "$SCRIPT" ]] || fail "script missing"
[[ -x "$SCRIPT" ]] || fail "script not executable"
pass "script exists and is executable"

help_output=$("$SCRIPT" --help)
[[ "$help_output" == *"Usage:"* ]] || fail "--help does not print usage"
pass "--help works"

tmp_dir=$(mktemp -d)
trap 'rm -rf "$tmp_dir"' EXIT

cat >"$tmp_dir/curl" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

state_file="${FAKE_CURL_STATE_FILE:?}"
capture_file="${FAKE_CURL_CAPTURE_FILE:?}"
count=0
if [[ -f "$state_file" ]]; then
  count=$(<"$state_file")
fi
count=$((count + 1))
printf '%s' "$count" >"$state_file"

output_file=""
method="GET"
url=""
request_data=""
args=("$@")
for ((i = 0; i < ${#args[@]}; i++)); do
  case "${args[$i]}" in
    -o)
      output_file="${args[$((i + 1))]}"
      i=$((i + 1))
      ;;
    -X)
      method="${args[$((i + 1))]}"
      i=$((i + 1))
      ;;
    --data)
      request_data="${args[$((i + 1))]}"
      i=$((i + 1))
      ;;
    http://*|https://*)
      url="${args[$i]}"
      ;;
  esac
done

if [[ -n "$request_data" ]]; then
  printf '%s\n' "$request_data" >"$capture_file"
fi

case "$count" in
  1)
    body='{"id":"sched-test-123"}'
    code='201'
    ;;
  2)
    body=''
    code='202'
    ;;
  3)
    body='{"status":"COMPLETED","score":99}'
    code='200'
    ;;
  4)
    body='{"hydra:member":[{"teamId":"team-1","dayOfWeek":2,"startTime":"20:30:00","durationMinutes":90,"venueId":"room-1"}]}'
    code='200'
    ;;
  *)
    body='{}'
    code='200'
    ;;
esac

if [[ -n "$output_file" ]]; then
  printf '%s' "$body" >"$output_file"
fi
printf '%s' "$code"
EOF

chmod +x "$tmp_dir/curl"

state_file="$tmp_dir/state"
capture_file="$tmp_dir/capture.json"
FAKE_CURL_STATE_FILE="$state_file" FAKE_CURL_CAPTURE_FILE="$capture_file" PATH="$tmp_dir:$PATH" "$SCRIPT" --name "Nom personnalisé" >/dev/null

[[ -f "$capture_file" ]] || fail "--name request payload not captured"
grep -q 'Nom personnalisé' "$capture_file" || fail "--name value not propagated"
pass "--name parsing works"

printf 'PASS: All tests passed\n'
