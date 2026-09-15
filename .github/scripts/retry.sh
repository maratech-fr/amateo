#!/usr/bin/env bash
# retry.sh — relance une commande jusqu'à sa réussite, sur N tentatives, avec backoff.
#
# ⚠ À NE PAS confondre avec `audit-retry.sh`, son voisin : celui-là ne réessaie QUE sur une
# signature de panne réseau, parce qu'un audit de dépendances porte un VERDICT métier qu'un
# retry aveugle rendrait faux (un `exit 1` avalé = gate aveugle). Ici il n'y a aucun verdict à
# préserver : on enveloppe des étapes d'INFRA CI (docker pull, docker compose up/build) où un
# échec est presque toujours transitoire — Docker Hub injoignable, BuildKit lent à démarrer
# (runs rouges e2e du 2026-09-15) — et où la seule bonne réponse est « réessaie ». Donc on
# réessaie sur TOUT code de sortie non nul.
#
# Usage : .github/scripts/retry.sh <tentatives> <commande…>
# Env   : RETRY_SLEEPS="10 20 40" — pauses entre tentatives (surchargeable pour les tests :
#         "0 0"). La dernière pause est réutilisée si <tentatives> dépasse la liste.
set -uo pipefail

if [[ $# -lt 2 ]]; then
  echo "usage: retry.sh <tentatives> <commande…>" >&2
  exit 2
fi

attempts=$1
shift
if ! [[ $attempts =~ ^[1-9][0-9]*$ ]]; then
  echo "retry.sh: <tentatives> doit être un entier positif, reçu « $attempts »." >&2
  exit 2
fi

read -r -a sleeps <<< "${RETRY_SLEEPS:-10 20 40 60 90}"
last_sleep="${sleeps[${#sleeps[@]} - 1]:-30}"

code=0
for (( i = 1; i <= attempts; i++ )); do
  # ⚠ On lance la commande PUIS on lit `$?` : `if "$@"; then` remettrait `$?` à 0 (le `if`
  # a « réussi » structurellement), et le code d'échec réel serait perdu.
  "$@"
  code=$?
  if (( code == 0 )); then
    exit 0
  fi
  if (( i == attempts )); then
    echo "retry.sh: « $* » a échoué après $attempts tentatives (code $code) — abandon." >&2
    exit "$code"
  fi
  pause="${sleeps[i - 1]:-$last_sleep}"
  echo "retry.sh: « $* » a échoué (tentative $i/$attempts, code $code), nouvel essai dans ${pause}s." >&2
  sleep "$pause"
done
