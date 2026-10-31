#!/bin/sh
# stack-resolve.sh [racine] — à quelle stack Docker cette copie de travail a-t-elle droit ?
#
# Sortie (toujours exit 0, une clé par ligne) : STACK_MODE, STACK_ROOT, COMPOSE, TEST_PROJECT,
# STACK_ENV_OK, STACK_REASON. Tout consommateur refuse Docker si STACK_MODE=unknown.
#   principal : le dépôt principal, projet par défaut (la stack du fondateur, la CI).
#   epreuve   : un worktree qui occupe la stack d'épreuve (label working_dir du projet).
#   nu        : un autre worktree, sa compose privée sans port publié.
#   unknown   : non identifiable, donc rien.
# STACK_ENV_OK : en nu, le .env porte exactement l'AMATEO_STACK de cette copie.
# GIT_COMMON_DIR : en worktree, le dépôt à monter en lecture seule (docker-compose.worktree.yml).
set -eu

PROJECT_EPREUVE=amateo-epreuve
UNKNOWN_REASON='stack-resolve : dépôt non identifié (git absent ou dossier hors dépôt) — aucune cible Docker'

if [ $# -ge 1 ]; then
  ROOT=$1
else
  ROOT=$(dirname "$0")/../../..
fi

GIT_COMMON_DIR=''
emit() { # emit <mode> <compose> <projet de test> <env ok> <raison>
  printf 'STACK_MODE=%s\nSTACK_ROOT=%s\nCOMPOSE=%s\nTEST_PROJECT=%s\nSTACK_ENV_OK=%s\nSTACK_REASON=%s\nGIT_COMMON_DIR=%s\n' \
    "$1" "$ROOT" "$2" "$3" "$4" "$5" "$GIT_COMMON_DIR"
  exit 0
}
unknown() { emit unknown '' '' 0 "$1"; }

if [ -d "$ROOT" ]; then
  ROOT=$(cd "$ROOT" && pwd -P)
else
  unknown "$UNKNOWN_REASON"
fi

# Le hook pre-commit exporte GIT_DIR/GIT_WORK_TREE : ils fausseraient toute la détection.
if ! git_paths=$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$ROOT" rev-parse --path-format=absolute \
  --show-toplevel --git-dir --git-common-dir 2>/dev/null); then
  unknown "$UNKNOWN_REASON"
fi
toplevel=$(printf '%s\n' "$git_paths" | sed -n 1p)
git_dir=$(printf '%s\n' "$git_paths" | sed -n 2p)
common_dir=$(printf '%s\n' "$git_paths" | sed -n 3p)

if [ -z "$toplevel" ] || [ -z "$git_dir" ] || [ -z "$common_dir" ] || [ "$toplevel" != "$ROOT" ]; then
  unknown "$UNKNOWN_REASON"
fi

if [ "$git_dir" = "$common_dir" ]; then
  emit principal "docker compose --env-file $ROOT/.env -f $ROOT/docker-compose.yml" '' 1 ''
fi

# Un worktree nommé scheduler hériterait, au moindre `docker compose` sans -p, du projet du fondateur.
if [ "$(basename "$ROOT")" = scheduler ]; then
  unknown 'worktree nommé “scheduler” : refusé'
fi

GIT_COMMON_DIR=$common_dir
WORKTREE_FILE="-f $ROOT/docker-compose.worktree.yml"

if ! occupant=$(docker ps -a --filter "label=com.docker.compose.project=$PROJECT_EPREUVE" \
  --format '{{.Label "com.docker.compose.project.working_dir"}}' 2>/dev/null); then
  unknown "$UNKNOWN_REASON"
fi
occupant=$(printf '%s\n' "$occupant" | sed -n 1p)

hash=$(printf '%s' "$ROOT" | sha1sum | cut -c1-12)
test_project="amateo-test-$hash"
if [ -z "$hash" ]; then
  unknown "$UNKNOWN_REASON"
fi

if [ "$occupant" = "$ROOT" ]; then
  emit epreuve "docker compose -p $PROJECT_EPREUVE --env-file $ROOT/.env -f $ROOT/docker-compose.yml $WORKTREE_FILE" \
    "$test_project" 1 ''
fi

env_ok=0
if grep -qxF "AMATEO_STACK=$test_project" "$ROOT/.env" 2>/dev/null; then
  env_ok=1
fi
emit nu "docker compose -p $test_project --env-file $ROOT/.env -f $ROOT/docker-compose.yml $WORKTREE_FILE -f $ROOT/docker-compose.test.yml" \
  "$test_project" "$env_ok" ''
