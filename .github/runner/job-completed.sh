#!/usr/bin/env bash
# Hook « job completed » du runner auto-hébergé (ACTIONS_RUNNER_HOOK_JOB_COMPLETED).
# Copié dans la VM sous /opt/amateo-ci/hooks/ (root, 755) — procédure : docs/ops/ci-runner.md.
#
# Rejoue le démontage du projet compose du job (doublon du composite ci-stack-down, qui ne
# tourne pas si le job est annulé avant son dernier step), retire ses tags de scan, puis rend
# le workspace et le dossier temporaire à l'uid du service (fichiers root : Trivy, pdf-worker),
# sans quoi le `git clean` du checkout suivant casse.
#
# Forme imposée : `set -u` seul, JAMAIS `-e` — un hook qui sort non nul fait échouer le JOB
# (un `docker run alpine:3` en 429 Docker Hub suffirait). Chaque geste Docker est suffixé
# `|| true` et le trap force la sortie à 0.
set -u
trap 'exit 0' EXIT

# Même nom que l'env COMPOSE_PROJECT_NAME des jobs de ci.yml.
project="ci-${GITHUB_RUN_ID:-none}-${GITHUB_RUN_ATTEMPT:-none}-${GITHUB_JOB:-none}"
ws="${GITHUB_WORKSPACE:-}"

if [ -f "$ws/docker-compose.yml" ] && [ -f "$ws/docker-compose.test.yml" ]; then
  docker compose -p "$project" -f "$ws/docker-compose.yml" -f "$ws/docker-compose.test.yml" down -v --rmi local --remove-orphans >/dev/null 2>&1 || true
else
  docker compose -p "$project" down -v --rmi local --remove-orphans >/dev/null 2>&1 || true
fi
docker ps -aq --filter "label=com.docker.compose.project=$project" 2>/dev/null | xargs -r docker rm -f >/dev/null 2>&1 || true

# Tags de scan du run (job build-docker).
docker images --format '{{.Repository}}:{{.Tag}}' 2>/dev/null | grep "^scan-${GITHUB_RUN_ID:-none}-${GITHUB_RUN_ATTEMPT:-none}/" | xargs -r docker rmi -f >/dev/null 2>&1 || true

# Fichiers root rendus à l'uid du service via un conteneur — le hook tourne sous l'uid non
# root du runner, un chown direct échouerait en silence.
[ -d "${GITHUB_WORKSPACE:-}" ] && docker run --rm -v "${GITHUB_WORKSPACE:-}:/w" alpine:3 chown -R "$(id -u):$(id -g)" /w >/dev/null 2>&1 || true
[ -d "${RUNNER_TEMP:-${GITHUB_WORKSPACE:-}/../../_temp}" ] && docker run --rm -v "${RUNNER_TEMP:-${GITHUB_WORKSPACE:-}/../../_temp}:/w" alpine:3 chown -R "$(id -u):$(id -g)" /w >/dev/null 2>&1 || true

exit 0
