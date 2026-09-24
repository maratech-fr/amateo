#!/usr/bin/env bash
# flaky-summary.sh — signale au résumé de job (`$GITHUB_STEP_SUMMARY`) les tests e2e « flaky »
# (perdus à un essai, verts au retry) d'un run Playwright.
#
# POURQUOI : Playwright sort 0 dès qu'un retry passe, donc un run flaky rend le job VERT et
# n'annonce RIEN — « 1 flaky » n'existe que dans le log brut que personne ne lit sur un job vert
# (flaky reproductible `matches.spec.ts:139`, 2026-09-24). Ce script rend le flaky VISIBLE sans
# le transformer en échec : il ne CHANGE jamais le verdict du job.
#
# NE FAIT JAMAIS ROUGIR : quoi qu'il lise (log absent, illisible, aucun flaky), il sort 0. Le
# step CI qui l'appelle est en `always()` pour parler aussi sur un job vert, et ne doit pas
# casser un job qui rougissait déjà pour une autre raison — d'où le contrat « exit 0 toujours ».
#
# Le reporter actif est `list` (voir frontend/playwright.config.ts) : PAS de rapport JSON à
# lire. On parse donc le résumé texte de fin de suite, qui imprime « N flaky » suivi de la
# liste indentée des tests concernés, puis « M passed (durée) ».
#
# Usage : .github/scripts/flaky-summary.sh <fichier-log-playwright>
#         (émet le markdown sur stdout ; l'appelant redirige vers "$GITHUB_STEP_SUMMARY".)
set -uo pipefail

log="${1:-}"
if [[ -z "$log" || ! -f "$log" ]]; then
  exit 0 # pas de log à lire — rien à dire, et surtout rien à casser
fi

# FORCE_COLOR peut être posé en CI : on retire les codes couleur ANSI avant de parser.
clean="$(sed -r 's/\x1B\[[0-9;]*[a-zA-Z]//g' "$log")"

# La ligne « N flaky » (seule sur sa ligne) porte le compte. Absente ou nulle → on se tait :
# un job vert non flaky ne doit rien afficher de bruyant.
count="$(printf '%s\n' "$clean" | sed -nE 's/^[[:space:]]*([0-9]+) flaky[[:space:]]*$/\1/p' | head -n1)"
if [[ -z "$count" || "$count" = "0" ]]; then
  exit 0
fi

# Les lignes indentées entre « N flaky » et le prochain total (« M passed/skipped/… ») nomment
# les tests flaky. On ne dépend pas du glyphe « › » : on borne sur la ligne de total suivante.
tests="$(printf '%s\n' "$clean" | awk '
  /^[[:space:]]*[0-9]+ flaky[[:space:]]*$/ { inblock = 1; next }
  inblock && /^[[:space:]]*[0-9]+ (passed|skipped|failed|interrupted|did not run)/ { inblock = 0 }
  inblock && NF { sub(/^[[:space:]]+/, ""); print "- `" $0 "`" }
')"

{
  echo "## E2E — tests flaky (verts au retry)"
  echo ""
  echo "⚠️ **${count} test(s) flaky** — la suite est VERTE, mais au moins un essai a été perdu puis rejoué avec succès. La trace de l'essai perdu est dans l'artefact \`playwright-results\` (uploadé même sur un job vert). Le job n'est PAS gaté sur ce flaky : il est rendu visible, pas transformé en échec."
  if [[ -n "$tests" ]]; then
    echo ""
    printf '%s\n' "$tests"
  fi
}

exit 0
