#!/bin/sh
# stack-sweep.sh <racine> [<projet de test>] — retire les stacks de test dont le worktree a disparu.
#
# Candidats : projets compose `amateo-test-<12 hex>` et `agent-*` (labels des conteneurs, volumes,
# réseaux). Gardés : le hash du chemin brut ET physique de chaque worktree listé par git (le nom
# qu'écrit stack-resolve.sh), leur basename (`agent-*`), le projet de test courant, `amateo-epreuve`
# et le basename de la racine. Une entrée `prunable` dont le dossier n'existe plus ne protège rien.
# Retrait par label, jamais par compose ; puis seul le cache de build de plus de SWEEP_CACHE_AGE
# (défaut 168h) est purgé. Jamais volume/system/image prune.
#
# Env : DRY_RUN=1 montre sans retirer. Sort TOUJOURS 0 (ne fait jamais échouer tests-complete) ;
# messages sur stderr. Liste des worktrees ou de docker indisponible = aucun retrait.
set -u

ROOT=${1:-}
TEST_PROJECT=${2:-}
DRY_RUN=${DRY_RUN:-}
AGE=${SWEEP_CACHE_AGE:-168h}
LABEL='{{.Label "com.docker.compose.project"}}'

say() { printf 'test-sweep : %s\n' "$1" >&2; }
unavailable() {
  say 'liste des worktrees indisponible, aucun retrait'
  exit 0
}
hash_of() { printf '%s' "$1" | sha1sum | cut -c1-12; }

[ -n "$ROOT" ] && [ -d "$ROOT" ] || unavailable
# Le hook pre-commit exporte GIT_DIR/GIT_WORK_TREE : ils fausseraient la liste.
wt_list=$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$ROOT" worktree list --porcelain 2>/dev/null) || unavailable
[ -n "$wt_list" ] || unavailable

keep="amateo-epreuve
$(basename "$ROOT")
$(basename "$(cd "$ROOT" && pwd -P)")"
[ -z "$TEST_PROJECT" ] || keep="$keep
$TEST_PROJECT"
protect() { # protect <chemin> : hash et basename du chemin
  keep="$keep
amateo-test-$(hash_of "$1")
$(basename "$1")"
}
wt=''
prunable=0
flush() {
  [ -n "$wt" ] || return 0
  if [ -d "$wt" ]; then
    protect "$wt"
    phys=$(cd "$wt" 2>/dev/null && pwd -P) && protect "$phys"
  elif [ "$prunable" -eq 0 ]; then
    protect "$wt"
  fi
  wt=''
  prunable=0
}
while IFS= read -r line; do
  case "$line" in
    'worktree '*)
      flush
      wt=${line#worktree }
      ;;
    prunable | 'prunable '*) prunable=1 ;;
  esac
done <<EOF
$wt_list
EOF
flush

listed=$(docker ps -a --format "$LABEL" 2>/dev/null) || unavailable
more=$(docker volume ls --format "$LABEL" 2>/dev/null) || unavailable
listed="$listed
$more"
more=$(docker network ls --format "$LABEL" 2>/dev/null) || unavailable
listed="$listed
$more"

orphans=''
for p in $(printf '%s\n' "$listed" | grep -E '^(amateo-test-[0-9a-f]{12}|agent-[A-Za-z0-9][A-Za-z0-9._-]*)$' | sort -u); do
  printf '%s\n' "$keep" | grep -qxF -- "$p" && continue
  orphans="$orphans $p"
done

remove() { # remove <projet> : conteneurs, volumes, réseaux, images ; un échec est dit, jamais fatal
  p=$1
  f="label=com.docker.compose.project=$p"
  ok=1
  say "retrait de $p (worktree disparu)"
  if ids=$(docker ps -aq --filter "$f"); then
    [ -z "$ids" ] || docker rm -f $ids >/dev/null || ok=0
  else
    ok=0
  fi
  if ids=$(docker volume ls -q --filter "$f"); then
    [ -z "$ids" ] || docker volume rm $ids >/dev/null || ok=0
  else
    ok=0
  fi
  if ids=$(docker network ls -q --filter "$f"); then
    [ -z "$ids" ] || docker network rm $ids >/dev/null || ok=0
  else
    ok=0
  fi
  # Par nom, pas par id : deux stacks nées du même build partagent l'id de leurs images.
  if ids=$(docker images --filter "reference=$p-*" --format '{{.Repository}}:{{.Tag}}'); then
    ids=$(printf '%s\n' "$ids" | sort -u)
    [ -z "$ids" ] || docker rmi $ids >/dev/null || ok=0
  else
    ok=0
  fi
  [ "$ok" -eq 1 ] && return 0
  say "$p : retrait incomplet, à reprendre à la main (make -C backend test-sweep)"
  return 1
}

if [ -z "$orphans" ]; then
  say 'aucune stack orpheline'
elif [ "$DRY_RUN" = 1 ]; then
  for p in $orphans; do say "[simulation] retirerait $p"; done
else
  # Compte = stacks retirées en entier ; une stack au retrait incomplet est dite à part.
  removed=0
  for p in $orphans; do remove "$p" && removed=$((removed + 1)); done
  if [ "$removed" -le 1 ]; then
    say "$removed stack orpheline retirée"
  else
    say "$removed stacks orphelines retirées"
  fi
fi

if [ "$DRY_RUN" = 1 ]; then
  say "[simulation] docker builder prune (until=$AGE) non lancé"
else
  say "cache de build — docker builder prune (until=$AGE)"
  docker builder prune --force --filter "until=$AGE" >&2 || :
fi
exit 0
