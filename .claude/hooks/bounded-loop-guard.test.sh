#!/usr/bin/env bash
set -euo pipefail

# Table de cas pour bounded-loop-guard.sh : chaque cas rejoue un JSON de hook
# sur stdin et vérifie le code retour attendu (0 = laisse passer, 2 = bloque).

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/bounded-loop-guard.sh"

fail=0

# check <attendu> <libellé> <commande-bash>
check() {
  local expected="$1" label="$2" command="$3"
  local payload rc
  payload="$(python3 -c 'import json,sys; print(json.dumps({"tool_input":{"command":sys.argv[1]}}))' "$command")"
  set +e
  printf '%s' "$payload" | bash "$GUARD" >/dev/null 2>&1
  rc=$?
  set -e
  if [ "$rc" -ne "$expected" ]; then
    echo "FAIL: $label — attendu exit $expected, obtenu $rc"
    fail=1
  else
    echo "OK  : $label (exit $rc)"
  fi
}

check 2 "until sans timeout"                     "until grep -q done log.txt; do sleep 20; done"
check 0 "timeout 4h + until"                      "timeout 4h bash -c 'until grep -q done log.txt; do sleep 5; done'"
check 2 "timeout 5h + until (> 4 h)"              "timeout 5h bash -c 'until grep -q done log.txt; do sleep 5; done'"
check 0 "timeout 30m + until"                     "timeout 30m bash -c 'until grep -q done log.txt; do sleep 5; done'"
check 0 "timeout 240m + while (= 4 h)"            "timeout 240m bash -c 'while true; do sleep 5; done'"
check 0 "timeout 14400s + while"                  "timeout 14400s bash -c 'while true; do sleep 5; done'"
check 0 "timeout 14400 + while (sans suffixe)"    "timeout 14400 bash -c 'while true; do sleep 5; done'"
check 2 "while sans timeout"                       "while true; do sleep 20; done"
check 0 "boucle for in"                            "for i in \$(seq 1 3); do echo \$i; done"
check 0 "git commit avec 'while' en littéral"      "git commit -m \"while loop fix\""
check 0 "grep while (pas de boucle)"               "grep while script.sh"
check 0 "commande banale"                          "ls -la /tmp"
check 0 "echo banal avec le mot until"             "echo until we meet again"

if [ "$fail" -ne 0 ]; then
  echo "--- ÉCHEC : au moins un cas a échoué."
  exit 1
fi
echo "--- Tous les cas passent."
