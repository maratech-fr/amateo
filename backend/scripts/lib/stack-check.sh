#!/usr/bin/env bash
# stack-check.sh snapshot <racine> | check <racine> — la stack de cette copie est-elle bien isolée ?
# check prouve par le réel : autre IP postgres que le fondateur, bonne base, pas de base du
# fondateur, réseau du fondateur sans nous, pas de .env.local. Un changement d'état de la stack du
# fondateur depuis le snapshot est SIGNALÉ, jamais un échec (il a le droit de la manipuler).
set -uo pipefail

action=${1:?usage: stack-check.sh snapshot|check <racine>}
root=${2:-}
lib="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
failed=0

die() {
  printf 'FAIL: stack-check: %s\n' "$1" >&2
  exit 1
}
ko() {
  printf 'FAIL: stack-check: %s\n' "$1" >&2
  failed=1
}

[[ -n "$root" && -d "$root" ]] || die "racine absente : « $root »"
snapshot="$(env -u GIT_DIR -u GIT_WORK_TREE git -C "$root" rev-parse --absolute-git-dir 2>/dev/null)/amateo-founder-stack.snapshot"
founder_state() {
  docker ps -a --filter label=com.docker.compose.project=scheduler --format '{{.Names}} {{.State}}' | sort
}

if [[ "$action" == "snapshot" ]]; then
  founder_state >"$snapshot"
  exit 0
fi
[[ "$action" == "check" ]] || die "action inconnue : $action"

resolved=$(sh "$lib/stack-resolve.sh" "$root")
mode=$(printf '%s\n' "$resolved" | sed -n 's/^STACK_MODE=//p')
compose=$(printf '%s\n' "$resolved" | sed -n 's/^COMPOSE=//p')
case "$mode" in
  epreuve)
    stack=amateo-epreuve
    app_env=dev
    want_db=amateo_dev
    ;;
  nu)
    stack=$(printf '%s\n' "$resolved" | sed -n 's/^TEST_PROJECT=//p')
    app_env=test
    want_db=amateo_test
    ;;
  *) die "$root n'est ni sur la stack d'épreuve ni en worktree nu (mode « $mode »)" ;;
esac

ip_of() {
  docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$1" 2>/dev/null
}
own_ip=$(ip_of "$stack-postgres")
founder_ip=$(ip_of amateo-postgres)
[[ -n "$own_ip" ]] || ko "conteneur $stack-postgres introuvable"
[[ "$own_ip" != "$founder_ip" ]] || ko "même IP postgres que le fondateur ($own_ip)"

# shellcheck disable=SC2086 # COMPOSE est une ligne de commande, le découpage est voulu.
db=$($compose exec -T -e APP_ENV="$app_env" php-fpm php bin/console dbal:run-sql "SELECT current_database() AS db" 2>/dev/null \
  | sed -E 's/[[:space:]]//g' | grep -vE '^-*$' | grep -vxE 'db' | head -n 1)
[[ "$db" == "$want_db" ]] || ko "base visée « $db », attendue « $want_db »"

# shellcheck disable=SC2086
databases=$($compose exec -T postgres psql -U amateo_owner -d postgres -tAc "SELECT datname FROM pg_database" 2>/dev/null)
[[ -n "$databases" ]] || ko "liste des bases illisible"
if printf '%s\n' "$databases" | grep -qx amateo_local; then
  ko "amateo_local existe dans cette stack : on regarde les données du fondateur"
fi

if docker network inspect amateo_network -f '{{range .Containers}}{{.Name}} {{end}}' 2>/dev/null | grep -qF "$stack-"; then
  ko "des conteneurs $stack-* sont sur le réseau du fondateur"
fi

[[ ! -e "$root/backend/.env.local" ]] || ko "$root/backend/.env.local existe : retire-le"

if [[ -f "$snapshot" ]] && ! diff <(founder_state) "$snapshot" >/dev/null; then
  printf 'SIGNAL: la stack du fondateur a changé depuis le montage (pas un échec) :\n' >&2
  diff <(founder_state) "$snapshot" >&2 || true
fi

[[ "$failed" -eq 0 ]] || exit 1
printf 'stack-check: %s isolée (base %s, IP %s)\n' "$stack" "$db" "$own_ip"
