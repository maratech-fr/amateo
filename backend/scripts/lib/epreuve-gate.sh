#!/usr/bin/env bash
# epreuve-gate.sh <worktree> <RESEED> <racine appelante> — epreuve-up a-t-il le droit de monter ?
# Exit 0 : monter. Exit 10 : déjà monté pour ce worktree, rien à faire. Exit 1 : refus nommé.
set -uo pipefail

wt=$1
reseed=$2
caller=$3
lib="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

refuse() {
  printf 'epreuve-up : %s\n' "$1" >&2
  exit 1
}
git_of() { env -u GIT_DIR -u GIT_WORK_TREE git -C "$@"; }

[[ -n "$wt" ]] || refuse 'WT obligatoire (make epreuve-up WT=<worktree>)'
[[ -d "$wt" ]] || refuse "$wt n'existe pas"

resolved=$(sh "$lib/stack-resolve.sh" "$wt")
mode=$(printf '%s\n' "$resolved" | sed -n 's/^STACK_MODE=//p')
case "$mode" in
  principal) refuse "$wt est le dépôt principal : la stack d'épreuve ne monte qu'un worktree" ;;
  nu | epreuve) ;;
  *) refuse "$(printf '%s\n' "$resolved" | sed -n 's/^STACK_REASON=//p')" ;;
esac

wt_common=$(git_of "$wt" rev-parse --path-format=absolute --git-common-dir 2>/dev/null) || wt_common=''
caller_common=$(git_of "$caller" rev-parse --path-format=absolute --git-common-dir 2>/dev/null) || caller_common=''
[[ -n "$wt_common" && "$wt_common" == "$caller_common" ]] || refuse "$wt n'est pas un worktree de ce dépôt"

# Une branche d'avant ce rail lancerait ses cibles sur le projet par défaut, celui du fondateur.
for needed in backend/scripts/lib/stack-resolve.sh .env.epreuve.dist docker-compose.worktree.yml; do
  [[ -f "$wt/$needed" ]] || refuse "$wt ne porte pas $needed : rebase sa branche sur main"
done

if ! occupant=$(docker ps -a --filter label=com.docker.compose.project=amateo-epreuve \
  --format '{{.Label "com.docker.compose.project.working_dir"}}' | sed -n 1p); then
  refuse "docker ps a échoué : occupation inconnue"
fi

if [[ -n "$occupant" && "$occupant" != "$wt" ]]; then
  branch=$(git_of "$occupant" branch --show-current 2>/dev/null || true)
  refuse "stack d'épreuve OCCUPÉE par $occupant ($branch) — make epreuve-down d'abord"
fi

[[ "$reseed" == "1" ]] && exit 0
[[ -z "$occupant" ]] && exit 0

mark="$(git_of "$wt" rev-parse --absolute-git-dir)/amateo-epreuve-mounted"
if [[ -f "$mark" ]]; then
  printf 'stack d'\''épreuve déjà montée sur %s — rien à faire (RESEED=1 pour rejouer le seed)\n' "$wt"
  exit 10
fi
refuse "montage interrompu sur $wt : relance avec RESEED=1, ou make epreuve-destroy"
