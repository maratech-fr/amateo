#!/usr/bin/env bash
set -u

# Hook PostToolUse (matcher Edit|Write|Bash) : met à jour l'index de
# code-review-graph sans jamais faire attendre l'outil.
# Contexte : un état INCOMPLETE transforme `update` en reconstruction complète
# (environ 146 s). Lancée en avant-plan, le timeout du hook la coupait : l'état
# restait INCOMPLETE et tout recommençait à l'appel suivant.
#
# - détaché (setsid, nohup, sorties fermées) : le timeout du hook ne l'atteint pas ;
# - `flock -n` : verrou pris = on sort sans rien faire, l'appel suivant rattrapera ;
# - `flock -o` : le verrou n'est tenu que par flock, jamais hérité par `update` ni
#   ses ouvriers, et il tombe dès que `update` rend la main.
# --repo vise le dépôt principal, y compris depuis un worktree.
# Rend toujours 0.

REPO="/home/marabou/projects/scheduler"
LOCK="$REPO/.code-review-graph/update.lock"

cat >/dev/null 2>&1 || true
git rev-parse --git-dir >/dev/null 2>&1 || exit 0

setsid nohup flock -n -o "$LOCK" \
    code-review-graph update --skip-flows --repo "$REPO" \
    </dev/null >/dev/null 2>&1 &

exit 0
