#!/usr/bin/env bash
# audit-retry.sh — relance un audit de dépendances UNIQUEMENT quand son endpoint est
# indisponible (timeout réseau, 5xx, DNS), jamais quand il a trouvé une vulnérabilité.
#
# Pourquoi (P4-171, 2026-09-04) : `dependency-audit` est un required check de `main`, et
# la PR #841 a demandé trois reruns manuels pour une panne du registre npm (attempt 1
# « npm warn audit network timeout », attempt 2 « 503 Service Unavailable - POST
# https://registry.npmjs.org/-/npm/v1/security/advisories/bulk »), sans aucune
# vulnérabilité en jeu. `composer audit` et `pip-audit` interrogent eux aussi un
# service distant et vivent le même risque.
#
# Le piège que ce script refuse : un retry qui avale un vrai `exit 1` d'audit rendrait
# le gate aveugle. Donc : la commande échoue ET sa sortie porte une signature de
# panne réseau → on réessaie (3 tentatives, pauses 10 s puis 30 s) ; sinon on rend
# son code de sortie tel quel, tout de suite.
#
# Usage : .github/scripts/audit-retry.sh <commande…>
# Env  : AUDIT_RETRY_SLEEPS="10 30" (surchargeable pour les tests : "0 0").
set -uo pipefail

if [[ $# -eq 0 ]]; then
  echo "usage: audit-retry.sh <commande…>" >&2
  exit 2
fi

# Signatures d'un endpoint indisponible — npm, composer (curl), pip-audit (requests/urllib3).
TRANSIENT='network timeout|ETIMEDOUT|ECONNRESET|ECONNREFUSED|EAI_AGAIN|ENOTFOUND|audit endpoint returned an error|Service Unavailable|Bad Gateway|Gateway Time-?out|HTTP/[0-9.]+ 5[0-9][0-9]| 5[0-9][0-9] (Service|Bad|Gateway|Internal)|curl error [0-9]+|Could not resolve host|Connection (refused|reset|timed out)|Read timed out|Max retries exceeded|Temporary failure in name resolution|fetch failed'

read -r -a sleeps <<< "${AUDIT_RETRY_SLEEPS:-10 30}"
attempts=$(( ${#sleeps[@]} + 1 ))

for (( i = 1; i <= attempts; i++ )); do
  output="$("$@" 2>&1)"
  code=$?
  printf '%s\n' "$output"
  if [[ $code -eq 0 ]]; then
    exit 0
  fi
  if ! grep -qiE "$TRANSIENT" <<< "$output"; then
    echo "audit-retry: échec d'audit (code $code) sans signature réseau — pas de retry, le verdict est celui de l'outil." >&2
    exit "$code"
  fi
  if (( i == attempts )); then
    echo "audit-retry: endpoint indisponible après $attempts tentatives — abandon (code $code)." >&2
    exit "$code"
  fi
  echo "audit-retry: endpoint indisponible (tentative $i/$attempts), nouvel essai dans ${sleeps[i-1]} s." >&2
  sleep "${sleeps[i-1]}"
done
