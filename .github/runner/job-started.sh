#!/usr/bin/env bash
# Hook « job started » du runner auto-hébergé (ACTIONS_RUNNER_HOOK_JOB_STARTED).
# Copié dans la VM sous /opt/amateo-ci/hooks/ (root, 755) — procédure : docs/ops/ci-runner.md.
#
# Rattrape ce qu'un job TUÉ a laissé sur le démon partagé (runner arrêté avant son hook
# « job completed ») : projets compose `ci-*`, volumes et réseaux orphelins, tags `scan-*`,
# fichiers root dans le workspace et le dossier temporaire.
#
# Forme imposée : `set -u` seul, JAMAIS `-e` — un hook qui sort non nul fait échouer le JOB
# (un `docker run alpine:3` en 429 Docker Hub suffirait). Chaque geste Docker est suffixé
# `|| true` et le trap force la sortie à 0.
set -u
trap 'exit 0' EXIT

now=$(date +%s)
max_age=$((3 * 3600))

# Âge (secondes) d'un horodatage Docker ; vide si illisible.
age_of() {
  local ts
  ts=$(date -d "$1" +%s 2>/dev/null) || return 1
  echo $((now - ts))
}

# 1. Projets compose `ci-*` dont le plus vieux conteneur a plus de 3 h.
projects=$(docker ps -a --format '{{.Label "com.docker.compose.project"}}' 2>/dev/null | grep '^ci-' | sort -u || true)
for project in $projects; do
  oldest=0
  for id in $(docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null || true); do
    created=$(docker inspect -f '{{.Created}}' "$id" 2>/dev/null) || continue
    age=$(age_of "$created") || continue
    [ "$age" -gt "$oldest" ] && oldest=$age
  done
  if [ "$oldest" -gt "$max_age" ]; then
    docker compose -p "$project" down -v --rmi local --remove-orphans >/dev/null 2>&1 || true
    docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null | xargs -r docker rm -f >/dev/null 2>&1 || true
  fi
done

# 2. Volumes `ci-*` sans conteneur (dangling) et vieux de plus de 3 h — l'âge protège un job
#    voisin qui vient de créer ses volumes et pas encore ses conteneurs.
for volume in $(docker volume ls -q --filter dangling=true --filter label=com.docker.compose.project 2>/dev/null || true); do
  project=$(docker volume inspect -f '{{index .Labels "com.docker.compose.project"}}' "$volume" 2>/dev/null) || continue
  case "$project" in ci-*) ;; *) continue ;; esac
  created=$(docker volume inspect -f '{{.CreatedAt}}' "$volume" 2>/dev/null) || continue
  age=$(age_of "$created") || continue
  [ "$age" -gt "$max_age" ] && { docker volume rm "$volume" >/dev/null 2>&1 || true; }
done

# 3. Réseaux `ci-*` vieux de plus de 3 h (un réseau encore utilisé refuse `network rm`).
for network in $(docker network ls -q --filter label=com.docker.compose.project 2>/dev/null || true); do
  project=$(docker network inspect -f '{{index .Labels "com.docker.compose.project"}}' "$network" 2>/dev/null) || continue
  case "$project" in ci-*) ;; *) continue ;; esac
  created=$(docker network inspect -f '{{.Created}}' "$network" 2>/dev/null) || continue
  age=$(age_of "$created") || continue
  [ "$age" -gt "$max_age" ] && { docker network rm "$network" >/dev/null 2>&1 || true; }
done

# 4. Tags de scan `scan-<run>-<attempt>/…` posés il y a plus de 3 h (LastTagTime : une image
#    réutilisée du cache garde une date de création ancienne, pas de pose de tag).
for ref in $(docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep '^scan-' || true); do
  tagged=$(docker image inspect -f '{{.Metadata.LastTagTime}}' "$ref" 2>/dev/null) || continue
  age=$(age_of "$tagged") || continue
  [ "$age" -gt "$max_age" ] && { docker rmi -f "$ref" >/dev/null 2>&1 || true; }
done

# 5. Fichiers root laissés par un job tué (Trivy, pdf-worker) : rendus à l'uid du service via
#    un conteneur — le hook tourne sous l'uid non root du runner, un chown direct échouerait.
#    `[ -d … ]` d'abord : un bind-mount sur un dossier absent le créerait en root.
[ -d "${GITHUB_WORKSPACE:-}" ] && docker run --rm -v "${GITHUB_WORKSPACE:-}:/w" alpine:3 chown -R "$(id -u):$(id -g)" /w >/dev/null 2>&1 || true
[ -d "${RUNNER_TEMP:-${GITHUB_WORKSPACE:-}/../../_temp}" ] && docker run --rm -v "${RUNNER_TEMP:-${GITHUB_WORKSPACE:-}/../../_temp}:/w" alpine:3 chown -R "$(id -u):$(id -g)" /w >/dev/null 2>&1 || true

exit 0
