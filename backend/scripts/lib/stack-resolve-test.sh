#!/usr/bin/env bash
# Promesse : une cible Docker lancée depuis une copie de travail ne touche QUE la stack de cette
# copie — jamais celle du fondateur, jamais le code d'une autre copie — et, si la copie n'est pas
# identifiable, elle ne touche rien.
#
# Banc HERMÉTIQUE : un vrai dépôt git temporaire + de vrais worktrees + les VRAIS Makefiles et
# scripts du dépôt (recopiés), et un FAUX `docker` en tête de PATH qui journalise ses appels et
# rend les labels voulus. Aucun conteneur n'est démarré, arrêté ni interrogé.
#
# Les refus se prouvent par une exécution RÉELLE (faux docker), pas par `make -n` : `-n` n'exécute
# pas une ligne de recette, un refus porté par la recette y serait vert sans rien refuser.
#
# Volet statique (compose, .env*, grep des recettes) : Unit/Dependency/ComposeStackParametrizationTest
# et Unit/Scripts/WorktreeTestRoutingTest.
set -uo pipefail

SRC=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd -P)
RESOLVE_REL=backend/scripts/lib/stack-resolve.sh
UNKNOWN_MSG='stack-resolve : dépôt non identifié (git absent ou dossier hors dépôt) — aucune cible Docker'

unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE MAKEFLAGS MFLAGS MAKELEVEL SANDBOX_GUARD_LOADED \
  SANDBOX_GUARD_COMPOSE MUTATION_CONFIRM_COMPOSE SANDBOX_DB COMPOSE_PROJECT_NAME AMATEO_STACK APP_ENV 2>/dev/null
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null

failures=0
passes=0
pass() { passes=$((passes + 1)); }
fail() {
  failures=$((failures + 1))
  printf 'FAIL: %s\n' "$1" >&2
}
check() { # check <libellé> <commande…>
  local label=$1
  shift
  if "$@"; then pass; else fail "$label"; fi
}
section() { printf -- '--- %s\n' "$1"; }

tmp=$(mktemp -d)
tmp=$(cd "$tmp" && pwd -P)
trap 'rm -rf "$tmp"' EXIT
bin="$tmp/fake-bin"
mkdir -p "$bin"
export FAKE_DOCKER_LOG="$tmp/docker.log"
export FAKE_DOCKER_STATE="$tmp/epreuve-working-dir"

# ---------------------------------------------------------------------------- faux docker
cat >"$bin/docker" <<'EOF'
#!/usr/bin/env bash
# Faux docker : journalise, rend le label working_dir du projet amateo-epreuve, et imite juste
# assez `up`/`down` pour que l'occupation de la stack d'épreuve évolue comme en vrai.
#
# Balayage (section F) : FAKE_DOCKER_PROJECTS nomme un fichier « <type> <projet> [<nom>] » (type :
# container, volume, network, image). `ps -a` / `volume ls` / `network ls` au format
# '{{.Label "com.docker.compose.project"}}' rendent un projet par objet ; filtrés par
# `label=com.docker.compose.project=<p>` en `-q`, ils rendent les objets de <p> (ctr-<p>-<nom>,
# <p>_<nom>) ; `images -q --filter reference=<p>-*` rend <p>-<nom>. Les retraits (rm, volume rm,
# network rm, rmi, builder prune…) sont seulement journalisés : rien n'est jamais retiré.
printf '%s\n' "$*" >>"${FAKE_DOCKER_LOG:?}"
all="$*"
if [[ -n "${FAKE_DOCKER_FAIL_ON:-}" && "$all" == *"$FAKE_DOCKER_FAIL_ON"* ]]; then
  exit 1
fi
objects() { # objects <type> [<projet>] : lignes « projet nom » du fichier d'état
  [[ -n "${FAKE_DOCKER_PROJECTS:-}" && -s "$FAKE_DOCKER_PROJECTS" ]] || return 0
  awk -v t="$1" -v p="${2:-}" '$1 == t && (p == "" || $2 == p) { print $2, ($3 == "" ? "x" : $3) }' "$FAKE_DOCKER_PROJECTS"
}
label_filter() { # projet d'un --filter label=com.docker.compose.project=<p>, vide sinon
  local prev="" a
  for a in "$@"; do
    case "$a" in --filter=label=com.docker.compose.project=*)
      printf '%s' "${a#--filter=label=com.docker.compose.project=}"
      return
      ;;
    esac
    if [[ "$prev" == "--filter" || "$prev" == "-f" ]]; then
      case "$a" in label=com.docker.compose.project=*)
        printf '%s' "${a#label=com.docker.compose.project=}"
        return
        ;;
      esac
    fi
    prev=$a
  done
}
quiet() { [[ " $all " == *" -q "* || " $all " == *" -aq "* || " $all " == *" -qa "* || " $all " == *" --quiet "* ]]; }
lists_projects() { [[ "$all" == *'"com.docker.compose.project"'* ]]; }
listing() { # listing <type> <préfixe d'id> <séparateur> : réponse d'un ps -a / volume ls / network ls
  local type=$1 pre=$2 sep=$3 p
  p=$(label_filter "$@")
  if [[ -n "$p" ]] && quiet; then
    objects "$type" "$p" | while read -r proj name; do printf '%s%s%s%s\n' "$pre" "$proj" "$sep" "$name"; done
  elif [[ -z "$p" ]] && lists_projects; then
    objects "$type" | while read -r proj _; do printf '%s\n' "$proj"; done
  fi
}
case "${1:-}" in
  ps)
    [[ "${FAKE_DOCKER_PS_FAIL:-0}" == "1" ]] && exit 1
    if [[ "$all" == *amateo-epreuve* ]] && ! quiet; then
      if [[ -s "${FAKE_DOCKER_STATE:?}" ]]; then cat "$FAKE_DOCKER_STATE"; fi
      exit 0
    fi
    listing container ctr- - "$@"
    exit 0
    ;;
  volume)
    [[ "${2:-}" == ls ]] && listing volume '' _ "$@"
    exit 0
    ;;
  network)
    if [[ "${2:-}" == ls ]]; then
      listing network '' _ "$@"
      exit 0
    fi
    # Le réseau du fondateur : seuls SES conteneurs y sont.
    printf 'amateo-postgres amateo-php-fpm\n'
    exit 0
    ;;
  images | image)
    if [[ "${1}" == images || "${2:-}" == ls ]]; then
      for a in "$@"; do
        case "$a" in reference=*-\* | --filter=reference=*-\*)
          ref=${a#--filter=}
          ref=${ref#reference=}
          objects image "${ref%-\*}" | while read -r proj name; do printf '%s-%s\n' "$proj" "$name"; done
          ;;
        esac
      done
    fi
    exit 0
    ;;
  inspect)
    if [[ "$all" == *amateo-postgres* ]]; then printf '172.18.0.2\n'; else printf '172.31.0.2\n'; fi
    exit 0
    ;;
  compose)
    if [[ "$all" == *"-p amateo-epreuve"* ]]; then
      file=""
      prev=""
      for arg in "$@"; do
        if [[ "$prev" == "-f" && -z "$file" ]]; then file=$arg; fi
        prev=$arg
      done
      case " $all " in
        *" up "*) dirname "$file" >"$FAKE_DOCKER_STATE" ;;
        *" down "* | *" down") : >"$FAKE_DOCKER_STATE" ;;
      esac
    fi
    case "$all" in
      *pg_database*) printf 'amateo_dev\namateo_test\npostgres\n' ;;
      *psql*current_database*) printf 'amateo_dev\n' ;;
      *current_database*) printf ' db\n----\namateo_dev\n' ;;
      *" ps -q"* | *" ps --quiet"*) printf 'fakecontainerid\n' ;;
      *up-to-date*) : ;;
    esac
    exit 0
    ;;
esac
exit 0
EOF
chmod +x "$bin/docker"

# Un PATH sans git (git réellement ABSENT, pas un git qui échoue).
nogit="$tmp/no-git-bin"
mkdir -p "$nogit"
ln -s "$bin/docker" "$nogit/docker"
for tool in sh bash env sed awk grep cat printf basename dirname head tail tr cut sha1sum test id mktemp wc \
  pwd readlink realpath rm cp mv mkdir touch make date sleep sort uname; do
  path=$(command -v "$tool" 2>/dev/null) || continue
  [[ "$path" == /* ]] && ln -s "$path" "$nogit/$tool"
done

# ---------------------------------------------------------------------------- banc git
git_q() { git -c user.name=t -c user.email=t@t.t "$@" >/dev/null 2>&1; }

seed_repo() { # seed_repo <dossier> : un dépôt qui porte les vrais Makefiles et scripts
  local dest=$1 f
  mkdir -p "$dest"
  for f in Makefile backend/Makefile engine/Makefile frontend/Makefile docker-compose.yml \
    docker-compose.test.yml docker-compose.worktree.yml .env.dist .env.epreuve.dist .gitignore backend/.env \
    backend/scripts/with-sandbox.sh; do
    if [[ -f "$SRC/$f" ]]; then
      mkdir -p "$dest/$(dirname "$f")"
      cp -p "$SRC/$f" "$dest/$f"
    fi
  done
  mkdir -p "$dest/backend/scripts/lib"
  cp -p "$SRC"/backend/scripts/lib/*.sh "$dest/backend/scripts/lib/"
  git_q -C "$dest" init -b main
  git_q -C "$dest" add -f .
  git_q -C "$dest" commit -m seed
}

MAIN="$tmp/main"
seed_repo "$MAIN"
cp "$MAIN/.env.dist" "$MAIN/.env"
add_wt() { git_q -C "$MAIN" worktree add -b "branche-$(basename "$1")" "$1"; }

WT_A="$tmp/wt-a"
WT_B="$tmp/wt-b"
WT_C="$tmp/wt-c"
WT_D="$tmp/wt-d"
WT_E="$tmp/wt-e"
WT_SCHED="$tmp/nomme/scheduler"
mkdir -p "$tmp/nomme"
for w in "$WT_A" "$WT_B" "$WT_C" "$WT_D" "$WT_E" "$WT_SCHED"; do add_wt "$w"; done

FOREIGN="$tmp/autre-depot"
seed_repo "$FOREIGN"
FOREIGN_WT="$tmp/autre-depot-wt"
git_q -C "$FOREIGN" worktree add -b branche-etrangere "$FOREIGN_WT"

hash_of() { printf '%s' "$1" | sha1sum | cut -c1-12; }
H_A=$(hash_of "$WT_A")

write_nu_env() { # write_nu_env <worktree> : le .env qu'attend le mode nu
  cat "$MAIN/.env.dist" >"$1/.env"
  printf 'AMATEO_STACK=amateo-test-%s\n' "$(hash_of "$1")" >>"$1/.env"
}
set_label() { printf '%s\n' "$1" >"$FAKE_DOCKER_STATE"; }
clear_label() { : >"$FAKE_DOCKER_STATE"; }
reset_log() { : >"$FAKE_DOCKER_LOG"; }
clear_label
reset_log

PRINCIPAL_COMPOSE="docker compose --env-file $MAIN/.env -f $MAIN/docker-compose.yml"
EPREUVE_COMPOSE="docker compose -p amateo-epreuve --env-file $WT_A/.env -f $WT_A/docker-compose.yml -f $WT_A/docker-compose.worktree.yml"
NU_COMPOSE="docker compose -p amateo-test-$H_A --env-file $WT_A/.env -f $WT_A/docker-compose.yml -f $WT_A/docker-compose.worktree.yml -f $WT_A/docker-compose.test.yml"

# ---------------------------------------------------------------------------- outils d'assertion
resolve_out=""
resolve_rc=0
resolve() { # resolve <root> [faire avec PATH…]
  resolve_out=$(PATH="$bin:$PATH" sh "$MAIN/$RESOLVE_REL" "$1" 2>/dev/null)
  resolve_rc=$?
}
field() { # valeur d'une ligne KEY= (guillemets d'un éventuel format eval-able retirés)
  printf '%s\n' "$resolve_out" | sed -n "s/^$1=//p" | sed "s/^['\"]//; s/['\"]\$//"
}
assert_mode() { # assert_mode <libellé> <mode attendu>
  if [[ "$resolve_rc" -ne 0 ]]; then
    fail "$1 : stack-resolve doit sortir 0, a rendu $resolve_rc"
  elif [[ "$(field STACK_MODE)" != "$2" ]]; then
    fail "$1 : STACK_MODE attendu « $2 », obtenu « $(field STACK_MODE) »"
  else
    pass
  fi
}
assert_field() { # assert_field <libellé> <clé> <valeur>
  if [[ "$(field "$2")" == "$3" ]]; then
    pass
  else
    fail "$1 : $2 attendu « $3 », obtenu « $(field "$2") »"
  fi
}

mk_out=""
mk_rc=0
mk() { # exécution réelle sous faux docker ; journal remis à zéro
  reset_log
  mk_out=$(PATH="$bin:$PATH" make "$@" 2>&1)
  mk_rc=$?
}
mkn() { # make -n sous faux docker
  mk_out=$(PATH="$bin:$PATH" make -n "$@" 2>&1)
  mk_rc=$?
}
compose_lines() { # lignes de docker compose du make -n, commentaires de recette exclus
  printf '%s\n' "$mk_out" | grep -F 'docker compose' | grep -vE '^[[:space:]]*#'
}
assert_all_compose() { # assert_all_compose <libellé> <préfixe exact attendu> [<sous-chaîne interdite>]
  local label=$1 want=$2 forbid=${3:-} lines
  if [[ "$mk_rc" -ne 0 ]]; then
    fail "$label : make -n a échoué ($mk_rc) : $(printf '%s' "$mk_out" | tail -n 2)"
    return
  fi
  lines=$(compose_lines)
  if [[ -z "$lines" ]]; then
    fail "$label : aucune ligne docker compose (témoin vide)"
    return
  fi
  local bad
  bad=$(printf '%s\n' "$lines" | grep -vF -- "$want" | head -n 1)
  if [[ -n "$bad" ]]; then
    fail "$label : ligne hors stack attendue : $bad"
    return
  fi
  if [[ -n "$forbid" ]] && printf '%s\n' "$lines" | grep -qF -- "$forbid"; then
    fail "$label : « $forbid » ne doit pas apparaître"
    return
  fi
  pass
}
assert_php_exec_is_test() { # assert_php_exec_is_test <libellé> : chaque exec php/composer force APP_ENV=test
  local label=$1 lines bad
  lines=$(compose_lines | grep -E ' php-fpm (php|composer) ')
  if [[ -z "$lines" ]]; then
    fail "$label : aucune exécution php/composer (témoin vide)"
    return
  fi
  bad=$(printf '%s\n' "$lines" | grep -vF -- '-e APP_ENV=test' | head -n 1)
  if [[ -n "$bad" ]]; then fail "$label : APP_ENV=test non forcé : $bad"; else pass; fi
}
no_compose_called() { ! grep -q '^compose' "$FAKE_DOCKER_LOG"; }
assert_refused() { # assert_refused <libellé> <message exact attendu, vide = tout message>
  local label=$1 msg=${2:-}
  if [[ "$mk_rc" -eq 0 ]]; then
    fail "$label : devait être refusé, a réussi"
    return
  fi
  if ! no_compose_called; then
    fail "$label : refus APRÈS un appel compose : $(grep -m1 '^compose' "$FAKE_DOCKER_LOG")"
    return
  fi
  if [[ -n "$msg" ]] && ! printf '%s' "$mk_out" | grep -qF -- "$msg"; then
    fail "$label : message attendu « $msg », obtenu : $(printf '%s' "$mk_out" | tail -n 2)"
    return
  fi
  pass
}
log_has() { grep -qF -- "$1" "$FAKE_DOCKER_LOG"; }
log_lacks() { ! grep -qF -- "$1" "$FAKE_DOCKER_LOG"; }
env_has_exact() { grep -qxF -- "$2" "$1/.env" 2>/dev/null; }

# ============================================================================== A. résolution
section "A. stack-resolve : les quatre états et unknown"

check "stack-resolve.sh doit exister" test -f "$SRC/$RESOLVE_REL"

clear_label
resolve "$MAIN"
assert_mode "principal" principal
assert_field "principal" STACK_ROOT "$MAIN"
assert_field "principal" COMPOSE "$PRINCIPAL_COMPOSE"

set_label "$WT_A"
resolve "$WT_A"
assert_mode "épreuve (label == worktree)" epreuve
assert_field "épreuve" STACK_ROOT "$WT_A"
assert_field "épreuve" COMPOSE "$EPREUVE_COMPOSE"

# La stack d'épreuve appartient à un AUTRE worktree : celui-ci reste nu.
resolve "$WT_B"
assert_mode "nu (label == autre worktree)" nu

clear_label
resolve "$WT_A"
assert_mode "nu (aucun label)" nu
assert_field "nu" STACK_ROOT "$WT_A"
assert_field "nu" COMPOSE "$NU_COMPOSE"
assert_field "nu" TEST_PROJECT "amateo-test-$H_A"

# Le hook pre-commit de git exporte GIT_DIR : la détection ne doit pas s'y laisser prendre.
resolve_out=$(GIT_DIR="$MAIN/.git" GIT_WORK_TREE="$MAIN" PATH="$bin:$PATH" sh "$MAIN/$RESOLVE_REL" "$WT_A" 2>/dev/null)
resolve_rc=$?
assert_mode "nu même avec GIT_DIR/GIT_WORK_TREE hérités" nu

# Sans argument : la racine se déduit de l'emplacement du script.
resolve_out=$(cd / && PATH="$bin:$PATH" sh "$WT_A/$RESOLVE_REL" 2>/dev/null)
resolve_rc=$?
assert_mode "racine déduite du script" nu
assert_field "racine déduite du script" STACK_ROOT "$WT_A"

resolve_out=$(PATH="$nogit" sh "$MAIN/$RESOLVE_REL" "$MAIN" 2>/dev/null)
resolve_rc=$?
assert_mode "git absent" unknown

resolve "$WT_A/backend"
assert_mode "toplevel ≠ root (sous-dossier)" unknown

NOT_REPO="$tmp/hors-depot"
mkdir -p "$NOT_REPO"
resolve "$NOT_REPO"
assert_mode "dossier hors dépôt (sortie git vide)" unknown

resolve_out=$(FAKE_DOCKER_PS_FAIL=1 PATH="$bin:$PATH" sh "$MAIN/$RESOLVE_REL" "$WT_A" 2>/dev/null)
resolve_rc=$?
assert_mode "docker ps en échec : on ne sait pas qui occupe, donc unknown" unknown

resolve "$WT_SCHED"
assert_mode "worktree nommé scheduler (projet compose par défaut du fondateur)" unknown

# ============================================================================== B. routage (make -n)
section "B. routage des cibles (make -n)"

write_nu_env "$WT_A"
clear_label

backend_closed="install tests-complete test phpunit phpstan cs cs-fix rector db-init-test db-empty-test purge-test-cache coverage jwt-keys lint composer-unused test-up test-down test-destroy test-check"
for target in $backend_closed; do
  mkn -C "$WT_A/backend" "$target"
  assert_all_compose "nu backend/$target" "$NU_COMPOSE"
done
# Celles qui exécutent du php/composer : APP_ENV=test forcé (EXEC et EXECROOT).
for target in install tests-complete test phpunit phpstan cs cs-fix rector db-init-test db-empty-test purge-test-cache coverage jwt-keys lint composer-unused; do
  mkn -C "$WT_A/backend" "$target"
  assert_php_exec_is_test "nu backend/$target"
done
# Le forçage résiste à une surcharge en ligne de commande.
mkn -C "$WT_A/backend" rector APP_ENV=dev
assert_php_exec_is_test "nu backend/rector APP_ENV=dev"
# EXECROOT nommément : db-empty-test est sa seule cible de la liste fermée.
mkn -C "$WT_A/backend" db-empty-test
if compose_lines | grep -F 'doctrine:database:drop' | grep -qF -- "$NU_COMPOSE exec -e APP_ENV=test"; then
  pass
else
  fail "nu : EXECROOT doit porter la compose privée et -e APP_ENV=test (db-empty-test)"
fi

for target in install test coverage lint; do
  mkn -C "$WT_A/engine" "$target"
  assert_all_compose "nu engine/$target" "$NU_COMPOSE"
done
for target in install test coverage lint; do
  mkn -C "$WT_A/frontend" "$target"
  assert_all_compose "nu frontend/$target" "$NU_COMPOSE"
done
for target in install test lint; do
  mkn -C "$WT_A" "$target"
  assert_all_compose "nu racine/$target" "$NU_COMPOSE"
done

# Épreuve : tout passe, sur le projet littéral, sans la compose privée.
set_label "$WT_A"
for spec in "backend test" "backend tests-complete" "backend db-empty" "backend db-init" "backend seed-bccl" "backend behat" \
  "backend tests-engine-semantics" "engine test" "frontend e2e" "frontend dev" ". start" ". restart" ". status"; do
  read -r dir target <<<"$spec"
  mkn -C "$WT_A/$dir" "$target"
  assert_all_compose "épreuve $dir/$target" "$EPREUVE_COMPOSE" "docker-compose.test.yml"
done
clear_label

# Principal : le défaut du fondateur, sans -p, sans compose privée.
for spec in "backend test" "backend tests-complete" "backend db-empty" "backend seed-demo" "backend behat" "engine test" \
  "frontend lint" "frontend dev" ". start" ". restart" ". play"; do
  read -r dir target <<<"$spec"
  mkn -C "$MAIN/$dir" "$target"
  assert_all_compose "principal $dir/$target" "$PRINCIPAL_COMPOSE" " -p "
done
mkn -C "$MAIN/backend" phpunit
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "principal : make -n phpunit doit rester permis"; fi

# ============================================================================== C. refus (exécution réelle)
section "C. refus (exécution réelle sous faux docker)"

# C.1 — cibles hors liste fermée, en nu.
assert_nu_refuses() { # assert_nu_refuses <sous-dossier> <cible…>
  local dir=$1 target
  shift
  for target in "$@"; do
    mk -C "$WT_A/$dir" "$target"
    assert_refused "nu $dir/$target" "$target : indisponible en worktree nu"
  done
}
write_nu_env "$WT_A"
clear_label
assert_nu_refuses backend play-env db-empty db-init seed-demo seed-bccl seed-holidays seed-league behat tests-engine-semantics
assert_nu_refuses . play db-empty start restart stop clean-volumes reset bootstrap
assert_nu_refuses frontend start dev e2e stop
assert_nu_refuses engine exec format
check "nu : play-env refusé ne doit pas écrire backend/.env.local" test ! -e "$WT_A/backend/.env.local"

# C.2 — .env du mode nu : sans le bon AMATEO_STACK, un conteneur retomberait sur amateo_network.
rm -f "$WT_A/.env"
mk -C "$WT_A/backend" test
assert_refused "nu sans .env" "AMATEO_STACK"
printf 'AMATEO_STACK=amateo-test-000000000000\n' >"$WT_A/.env"
mk -C "$WT_A/backend" test
assert_refused "nu avec AMATEO_STACK d'un autre worktree" "AMATEO_STACK"
printf 'AMATEO_STACK=amateo-test-%sx\n' "$H_A" >"$WT_A/.env"
mk -C "$WT_A/backend" test
assert_refused "nu avec AMATEO_STACK presque exact" "AMATEO_STACK"
cat "$MAIN/.env.dist" >"$WT_A/.env"
mk -C "$WT_A/engine" test
assert_refused "nu engine/test sans AMATEO_STACK" "AMATEO_STACK"
mk -C "$WT_A/frontend" lint
assert_refused "nu frontend/lint sans AMATEO_STACK" "AMATEO_STACK"

# C.3 — sauf test-up, qui le réécrit toujours (absent, puis faux).
rm -f "$WT_A/.env"
mk -C "$WT_A/backend" test-up
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "test-up sans .env doit réussir : $(printf '%s' "$mk_out" | tail -n 2)"; fi
check "test-up sans .env : AMATEO_STACK exact" env_has_exact "$WT_A" "AMATEO_STACK=amateo-test-$H_A"
check "test-up : les clés requises par la compose sont là" grep -q '^POSTGRES_DB=' "$WT_A/.env"
check "test-up démarre la compose privée" log_has "compose -p amateo-test-$H_A --env-file $WT_A/.env -f $WT_A/docker-compose.yml -f $WT_A/docker-compose.worktree.yml -f $WT_A/docker-compose.test.yml up"
printf 'AMATEO_STACK=amateo-epreuve\n' >"$WT_A/.env"
cat "$MAIN/.env.dist" >>"$WT_A/.env"
mk -C "$WT_A/backend" test-up
check "test-up réécrit un AMATEO_STACK faux" env_has_exact "$WT_A" "AMATEO_STACK=amateo-test-$H_A"
if [[ "$(grep -c '^AMATEO_STACK=' "$WT_A/.env")" == "1" ]]; then pass; else fail "test-up : AMATEO_STACK doit apparaître une seule fois"; fi
mk -C "$WT_A/backend" test-down
check "test-down arrête la compose privée" grep -qE "^compose -p amateo-test-$H_A .* down" "$FAKE_DOCKER_LOG"
mk -C "$WT_A/backend" test-destroy
check "test-destroy détruit les volumes privés" grep -qE "compose -p amateo-test-$H_A .* down .*(-v|--volumes)" "$FAKE_DOCKER_LOG"

# C.4 — création de .env refusée hors principal.
rm -f "$WT_A/.env"
mk -C "$WT_A" .env
assert_refused "règle .env en nu"
check "règle .env en nu : aucun .env créé" test ! -e "$WT_A/.env"
mk -C "$WT_A/frontend" ../.env
check "règle ../.env du frontend en nu : aucun .env créé" test ! -e "$WT_A/.env"
set_label "$WT_A"
mk -C "$WT_A" .env
assert_refused "règle .env en épreuve"
clear_label
rm -f "$MAIN/.env"
mkn -C "$MAIN" .env
if [[ "$mk_rc" -eq 0 ]] && printf '%s' "$mk_out" | grep -qF 'cp .env.dist .env'; then pass; else fail "principal : la règle .env reste permise"; fi
cp "$MAIN/.env.dist" "$MAIN/.env"

# C.5 — play-env refusé en épreuve aussi.
set_label "$WT_A"
cat "$MAIN/.env.dist" >"$WT_A/.env"
mk -C "$WT_A/backend" play-env
if [[ "$mk_rc" -ne 0 ]]; then pass; else fail "épreuve : play-env doit être refusé"; fi
check "épreuve : play-env n'écrit pas backend/.env.local" test ! -e "$WT_A/backend/.env.local"
clear_label

# C.6 — unknown : toute cible Docker refusée, message exact.
write_nu_env "$WT_A"
FAKE_DOCKER_PS_FAIL=1 mk -C "$WT_A/backend" test
assert_refused "unknown (docker ps KO) backend/test" "$UNKNOWN_MSG"
FAKE_DOCKER_PS_FAIL=1 mk -C "$WT_A" test
assert_refused "unknown (docker ps KO) racine/test" "$UNKNOWN_MSG"
FAKE_DOCKER_PS_FAIL=1 mk -C "$WT_A/engine" test
assert_refused "unknown (docker ps KO) engine/test" "$UNKNOWN_MSG"
FAKE_DOCKER_PS_FAIL=1 mk -C "$WT_A/frontend" test
assert_refused "unknown (docker ps KO) frontend/test" "$UNKNOWN_MSG"
cp -a "$MAIN/backend" "$NOT_REPO/backend"
cp -p "$MAIN/docker-compose.yml" "$MAIN/.env" "$NOT_REPO/"
mk -C "$NOT_REPO/backend" test
assert_refused "copie hors dépôt backend/test" "$UNKNOWN_MSG"

# C.7 — worktree nommé scheduler : refus nommé, sur chaque Makefile.
for dir in backend engine frontend .; do
  mk -C "$WT_SCHED/$dir" test
  assert_refused "worktree scheduler $dir/test" "worktree nommé “scheduler” : refusé"
done

# ============================================================================== D. epreuve-up
section "D. epreuve-up (exécution réelle sous faux docker)"
clear_label

assert_no_mount() { # assert_no_mount <libellé>
  if [[ "$mk_rc" -eq 0 ]]; then
    fail "$1 : devait être refusé"
    return
  fi
  if log_has ' up '; then
    fail "$1 : un montage a été lancé malgré le refus"
    return
  fi
  pass
}

# D.1 — preuve : chaque dossier qui n'est pas un worktree de CE dépôt est refusé.
mk -C "$MAIN" epreuve-up
assert_no_mount "WT absent"
mk -C "$MAIN" epreuve-up "WT=$MAIN"
assert_no_mount "WT = le dépôt principal"
mk -C "$MAIN" epreuve-up "WT=$WT_A/backend"
assert_no_mount "WT = sous-dossier d'un worktree"
mk -C "$MAIN" epreuve-up "WT=$FOREIGN_WT"
assert_no_mount "WT = worktree d'un autre dépôt"
mk -C "$MAIN" epreuve-up "WT=$WT_SCHED"
assert_no_mount "WT = worktree nommé scheduler"
mk -C "$MAIN" epreuve-up "WT=$tmp/nexiste-pas"
assert_no_mount "WT inexistant"
reset_log
mk_out=$(PATH="$nogit" make -C "$MAIN" epreuve-up "WT=$WT_A" 2>&1)
mk_rc=$?
assert_no_mount "git absent : les lignes de preuve sont vides"
check "un refus laisse la stack libre" test ! -s "$FAKE_DOCKER_STATE"

# D.2 — montage complet, avec slash final (WT normalisé).
rm -f "$WT_A/.env"
mk -C "$MAIN" epreuve-up "WT=$WT_A/"
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "epreuve-up doit réussir : $(printf '%s' "$mk_out" | tail -n 3)"; fi
if [[ "$(cat "$FAKE_DOCKER_STATE")" == "$WT_A" ]]; then
  pass
else
  fail "slash final : label attendu $WT_A, obtenu « $(cat "$FAKE_DOCKER_STATE") »"
fi
check "montage : test-down du worktree d'abord" log_has "compose -p amateo-test-$H_A"
check "montage : .env issu de .env.epreuve.dist" env_has_exact "$WT_A" "AMATEO_STACK=amateo-epreuve"
check "montage : seed-bccl" log_has "app:bccl:seed"
check "montage : vacances" log_has "app:school-holidays:seed"
check "montage : fériés" log_has "app:public-holidays:seed"
check "montage : ligue" log_has "app:league-windows:seed"
check "montage : jamais seed-demo avec seed-bccl" log_lacks "app:demo:seed"
check "montage : clés JWT" log_has "lexik:jwt:generate-keypair"
stray=$(grep '^compose' "$FAKE_DOCKER_LOG" | grep -vE -- "-p (amateo-epreuve|amateo-test-$H_A) " | head -n 1)
if [[ -z "$stray" ]]; then pass; else fail "montage : appel compose hors projet (stack du fondateur ?) : $stray"; fi

# D.3 — même worktree, montage terminé : statut, aucun effet.
sum_before=$(sha1sum "$WT_A/.env" | cut -d' ' -f1)
mk -C "$MAIN" epreuve-up "WT=$WT_A"
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "second epreuve-up : statut attendu, a échoué"; fi
check "second epreuve-up : aucun up" log_lacks " up "
check "second epreuve-up : aucun seed" log_lacks "app:bccl:seed"
if [[ "$(sha1sum "$WT_A/.env" | cut -d' ' -f1)" == "$sum_before" ]]; then pass; else fail "second epreuve-up : .env modifié"; fi

# D.4 — sauf RESEED=1.
mk -C "$MAIN" epreuve-up "WT=$WT_A" RESEED=1
check "RESEED=1 rejoue le seed" log_has "app:bccl:seed"

# D.5 — occupée par un autre.
mk -C "$MAIN" epreuve-up "WT=$WT_B"
assert_no_mount "stack occupée"
if printf '%s' "$mk_out" | grep -qF "stack d'épreuve OCCUPÉE par $WT_A (branche-wt-a) — make epreuve-down d'abord"; then
  pass
else
  fail "stack occupée : message attendu, obtenu : $(printf '%s' "$mk_out" | tail -n 2)"
fi
if [[ "$(cat "$FAKE_DOCKER_STATE")" == "$WT_A" ]]; then pass; else fail "stack occupée : l'occupant a changé"; fi

# D.6 — les autres cibles, toutes sur le projet littéral.
mk -C "$MAIN" epreuve-status
if [[ "$mk_rc" -eq 0 ]] && printf '%s' "$mk_out" | grep -qF "$WT_A"; then pass; else fail "epreuve-status doit nommer l'occupant"; fi
mkn -C "$MAIN" epreuve-dev "WT=$WT_A"
if [[ "$mk_rc" -eq 0 ]] && compose_lines | grep -F -- '-p amateo-epreuve' | grep -qF -- '--profile dev'; then
  pass
else
  fail "epreuve-dev : doit lancer le profil dev sur -p amateo-epreuve"
fi
mk -C "$MAIN" epreuve-check "WT=$WT_A"
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "epreuve-check sur une stack saine : $(printf '%s' "$mk_out" | tail -n 3)"; fi
check "epreuve-check interroge la base réelle" log_has "current_database"
mk -C "$MAIN" epreuve-down
check "epreuve-down vise le projet littéral" grep -qE '^compose -p amateo-epreuve .*down' "$FAKE_DOCKER_LOG"

# D.7 — montage interrompu : pas de marque → refus demandant RESEED=1 ou epreuve-destroy.
clear_label
FAKE_DOCKER_FAIL_ON=app:bccl:seed mk -C "$MAIN" epreuve-up "WT=$WT_C"
if [[ "$mk_rc" -ne 0 ]]; then pass; else fail "un seed en échec doit faire échouer epreuve-up"; fi
if [[ "$(cat "$FAKE_DOCKER_STATE")" == "$WT_C" ]]; then pass; else fail "banc : montage interrompu, stack attendue occupée par WT_C"; fi
mk -C "$MAIN" epreuve-up "WT=$WT_C"
assert_no_mount "même worktree sans marque"
if printf '%s' "$mk_out" | grep -qF 'RESEED=1' && printf '%s' "$mk_out" | grep -qF 'epreuve-destroy'; then
  pass
else
  fail "sans marque : le refus doit nommer RESEED=1 et epreuve-destroy : $(printf '%s' "$mk_out" | tail -n 2)"
fi
mk -C "$MAIN" epreuve-up "WT=$WT_C" RESEED=1
if [[ "$mk_rc" -eq 0 ]]; then pass; else fail "RESEED=1 après interruption doit terminer le montage"; fi
mk -C "$MAIN" epreuve-up "WT=$WT_C"
check "marque posée par RESEED=1 : second appel sans effet" log_lacks "app:bccl:seed"

# D.8 — destroy : volumes, .env retiré, marque effacée (un remontage rejoue le seed).
mk -C "$MAIN" epreuve-destroy "WT=$WT_C"
check "epreuve-destroy détruit les volumes" grep -qE '^compose -p amateo-epreuve .*down .*(-v|--volumes)' "$FAKE_DOCKER_LOG"
check "epreuve-destroy retire le .env du worktree" test ! -e "$WT_C/.env"
clear_label
mk -C "$MAIN" epreuve-up "WT=$WT_C"
check "après destroy, la marque est partie : remontage complet" log_has "app:bccl:seed"
clear_label

# ============================================================================== E. scripts mutateurs
section "E. sandbox-guard, mutation-confirm, with-sandbox suivent la stack résolue"

run_tool() { # run_tool <commande…> : journal remis à zéro, faux docker
  reset_log
  mk_out=$(PATH="$bin:$PATH" "$@" 2>&1)
  mk_rc=$?
}
assert_log_compose() { # assert_log_compose <libellé> <préfixe exact, sans "docker ">
  local label=$1 want=$2 lines bad
  lines=$(grep '^compose' "$FAKE_DOCKER_LOG")
  if [[ -z "$lines" ]]; then
    fail "$label : aucun appel compose (rc=$mk_rc)"
    return
  fi
  bad=$(printf '%s\n' "$lines" | grep -vF -- "$want" | head -n 1)
  if [[ -n "$bad" ]]; then fail "$label : appel hors stack : $bad"; else pass; fi
}
EP_D="compose -p amateo-epreuve --env-file $WT_D/.env -f $WT_D/docker-compose.yml -f $WT_D/docker-compose.worktree.yml"
PR_D="compose --env-file $MAIN/.env -f $MAIN/docker-compose.yml"
H_E=$(hash_of "$WT_E")
NU_E="compose -p amateo-test-$H_E --env-file $WT_E/.env -f $WT_E/docker-compose.yml -f $WT_E/docker-compose.worktree.yml -f $WT_E/docker-compose.test.yml"

cat "$MAIN/.env.dist" >"$WT_D/.env"
set_label "$WT_D"
run_tool bash "$WT_D/backend/scripts/lib/sandbox-guard.sh"
assert_log_compose "sandbox-guard en épreuve" "$EP_D"
run_tool bash "$WT_D/backend/scripts/lib/mutation-confirm.sh" etiquette
assert_log_compose "mutation-confirm en épreuve" "$EP_D"
run_tool bash "$WT_D/backend/scripts/with-sandbox.sh" true
assert_log_compose "with-sandbox en épreuve" "$EP_D"
clear_label

run_tool bash "$MAIN/backend/scripts/lib/sandbox-guard.sh"
assert_log_compose "sandbox-guard en principal" "$PR_D"
run_tool bash "$MAIN/backend/scripts/lib/mutation-confirm.sh" etiquette
assert_log_compose "mutation-confirm en principal" "$PR_D"
run_tool bash "$MAIN/backend/scripts/with-sandbox.sh" true
assert_log_compose "with-sandbox en principal" "$PR_D"

write_nu_env "$WT_E"
run_tool bash "$WT_E/backend/scripts/lib/sandbox-guard.sh"
assert_log_compose "sandbox-guard en nu" "$NU_E"

for tool in "lib/sandbox-guard.sh" "lib/mutation-confirm.sh etiquette" "with-sandbox.sh true"; do
  read -r script arg <<<"$tool"
  # shellcheck disable=SC2086
  FAKE_DOCKER_PS_FAIL=1 run_tool bash "$WT_E/backend/scripts/$script" $arg
  assert_refused "unknown : $script" ""
done

# ============================================================================== F. balayage
# Promesse : `stack-sweep.sh` retire les stacks amateo-test-*/agent-* dont le worktree a disparu,
# jamais celle d'un worktree vivant (chemin brut ET physique, basename), ni `scheduler`, ni
# `amateo-epreuve`, ni la copie courante ; il ne prune que le cache de build, et ne fait jamais
# échouer `tests-complete`, qu'il ouvre dans les trois modes. Volet statique (recette, prérequis) :
# Unit/Scripts/WorktreeTestRoutingTest::testSweepTargetRetiresProjectsWithoutAWorktree et
# ::testTestsCompleteSweepsFirst.
section "F. stack-sweep : balayage des stacks orphelines (exécution réelle sous faux docker)"

SWEEP_REL=backend/scripts/lib/stack-sweep.sh
export FAKE_DOCKER_PROJECTS="$tmp/docker-projects"
ORPH=amateo-test-0123456789ab
check "stack-sweep.sh doit exister" test -f "$SRC/$SWEEP_REL"

projects() { printf '%s\n' "$@" >"$FAKE_DOCKER_PROJECTS"; } # projects "<type> <projet> [<nom>]"…
full() { printf 'container %s php-fpm\nvolume %s pgdata\nnetwork %s default\nimage %s php-fpm\n' "$1" "$1" "$1" "$1"; }
paused() { printf 'volume %s pgdata\nimage %s php-fpm\n' "$1" "$1"; } # après test-down : volumes et images
sweep_out=""
sweep_err=""
sweep_rc=0
sweep() { # sweep <racine> [<projet de test>] ; SWEEP_PATH remplace le PATH (git absent)
  reset_log
  sweep_out=$(PATH="${SWEEP_PATH:-$bin:$PATH}" sh "$MAIN/$SWEEP_REL" "$@" 2>"$tmp/sweep.err")
  sweep_rc=$?
  sweep_err=$(cat "$tmp/sweep.err")
}
REMOVAL='^(rm|container rm|volume rm|network rm|rmi|image rm) |^compose .* down'
touched() { grep -E -- "$REMOVAL" "$FAKE_DOCKER_LOG" | grep -qF -- "$1"; }
assert_sweep_ran() { # assert_sweep_ran <libellé> : exit 0 et au moins un message du balayage
  if [[ "$sweep_rc" -ne 0 ]]; then
    fail "$1 : stack-sweep doit sortir 0, a rendu $sweep_rc : $(printf '%s' "$sweep_err" | tail -n 2)"
  elif ! printf '%s\n' "$sweep_err" | grep -q '^test-sweep : '; then
    fail "$1 : aucun message « test-sweep : » sur stderr (témoin vide)"
  else
    pass
  fi
}
assert_err_line() { # assert_err_line <libellé> <ligne exacte attendue sur stderr>
  if printf '%s\n' "$sweep_err" | grep -qxF -- "$2"; then pass; else fail "$1 : ligne attendue « $2 », stderr : $(printf '%s' "$sweep_err" | tail -n 3)"; fi
}
assert_removed() { # assert_removed <libellé> <projet> : conteneurs, volumes, réseau, images, par label
  local label=$1 p=$2 kind
  for kind in "^(rm|container rm) (-f|--force) .*ctr-$p-" "^volume rm .*${p}_" "^network rm .*${p}_" "^(rmi|image rm) .*$p-"; do
    if ! grep -qE -- "$kind" "$FAKE_DOCKER_LOG"; then
      fail "$label : $p non retiré (attendu une ligne $kind)"
      return
    fi
  done
  if ! log_has "label=com.docker.compose.project=$p"; then
    fail "$label : objets de $p non cherchés par leur label compose"
    return
  fi
  if ! log_has "reference=$p-*"; then
    fail "$label : images de $p non cherchées par reference=$p-*"
    return
  fi
  assert_err_line "$label" "test-sweep : retrait de $p (worktree disparu)"
}
assert_kept() { # assert_kept <libellé> <projet>
  if touched "$2"; then
    fail "$1 : $2 retiré alors qu'il doit survivre : $(grep -E -- "$REMOVAL" "$FAKE_DOCKER_LOG" | grep -F -- "$2" | head -n 1)"
  elif printf '%s\n' "$sweep_err" | grep -qF -- "retrait de $2 "; then
    fail "$1 : $2 annoncé retiré"
  else
    pass
  fi
}
assert_nothing_removed() { # assert_nothing_removed <libellé> : aucun retrait, aucun prune
  if grep -qE -- "$REMOVAL|prune" "$FAKE_DOCKER_LOG"; then
    fail "$1 : retrait ou prune inattendu : $(grep -E -- "$REMOVAL|prune" "$FAKE_DOCKER_LOG" | head -n 1)"
  else
    pass
  fi
}

# F1 — un orphelin (hash d'aucun worktree) est retiré en entier, par label.
projects "$(full "$ORPH")"
sweep "$MAIN"
assert_sweep_ran "F1"
assert_removed "F1 orphelin" "$ORPH"
assert_err_line "F1 compte" "test-sweep : 1 stack orpheline retirée"

# F2 — la stack d'un worktree vivant (pausée par test-down ou epreuve-up) survit ; témoin : l'orphelin part.
projects "$(paused "amateo-test-$H_A")" "$(full "$ORPH")"
sweep "$MAIN"
assert_sweep_ran "F2"
assert_kept "F2 worktree vivant" "amateo-test-$H_A"
assert_removed "F2 témoin" "$ORPH"
projects "$(paused "amateo-test-$H_A")"
sweep "$MAIN"
assert_sweep_ran "F2 sans orphelin"
if grep -qE -- "$REMOVAL" "$FAKE_DOCKER_LOG"; then fail "F2 sans orphelin : retrait inattendu"; else pass; fi
assert_err_line "F2 sans orphelin" "test-sweep : aucune stack orpheline"

# F3a — worktree ajouté via un lien symbolique : la stack née de son chemin physique survit.
mkdir -p "$tmp/reel-s"
ln -s "$tmp/reel-s" "$tmp/lien"
add_wt "$tmp/lien/wt-s"
H_S=$(hash_of "$tmp/reel-s/wt-s")
projects "$(full "amateo-test-$H_S")" "$(full "$ORPH")"
sweep "$MAIN"
assert_sweep_ran "F3a"
assert_kept "F3a worktree ajouté via un lien" "amateo-test-$H_S"
assert_removed "F3a témoin" "$ORPH"

# F3b — parent déplacé derrière un lien APRÈS l'ajout : git rend le chemin brut, stack-resolve le
# physique ; les deux stacks survivent. Une version ne gardant que le hash brut rend F3b rouge.
mkdir -p "$tmp/real"
add_wt "$tmp/real/wt-m"
mv "$tmp/real" "$tmp/real2"
ln -s "$tmp/real2" "$tmp/real"
H_RAW=$(hash_of "$tmp/real/wt-m")
H_PHYS=$(hash_of "$tmp/real2/wt-m")
if [[ "$H_RAW" != "$H_PHYS" ]] && git -C "$MAIN" worktree list --porcelain | grep -qxF "worktree $tmp/real/wt-m"; then
  pass
else
  fail "F3b banc : git doit lister le chemin brut $tmp/real/wt-m, de hash distinct du physique"
fi
projects "$(full "amateo-test-$H_RAW")" "$(full "amateo-test-$H_PHYS")" "$(full "$ORPH")"
sweep "$MAIN"
assert_sweep_ran "F3b"
assert_kept "F3b hash du chemin brut" "amateo-test-$H_RAW"
assert_kept "F3b hash du chemin physique" "amateo-test-$H_PHYS"
assert_removed "F3b témoin" "$ORPH"

# F4 — la stack du fondateur et l'épreuve (4 volumes sans conteneur) ne sont jamais touchées.
projects "$(full scheduler)" "volume amateo-epreuve pgdata" "volume amateo-epreuve redis" \
  "volume amateo-epreuve mercure_data" "volume amateo-epreuve mercure_config" "image amateo-epreuve php-fpm" "$(full "$ORPH")"
sweep "$MAIN"
assert_sweep_ran "F4"
assert_kept "F4 stack du fondateur" scheduler
assert_kept "F4 stack d'épreuve" amateo-epreuve
assert_removed "F4 témoin" "$ORPH"

# F5 — la copie courante (TEST_PROJECT) est gardée même si git ne la liste pas.
projects "$(full amateo-test-fedcba987654)" "$(full "$ORPH")"
sweep "$MAIN" amateo-test-fedcba987654
assert_sweep_ran "F5"
assert_kept "F5 copie courante" amateo-test-fedcba987654
assert_removed "F5 témoin" "$ORPH"

# F6 — agent-* : retiré sans worktree, gardé si le basename d'un worktree vivant répond.
add_wt "$tmp/agent-keep"
projects "$(full agent-zz)" "$(full agent-keep)"
sweep "$MAIN"
assert_sweep_ran "F6"
assert_removed "F6 agent-* sans worktree" agent-zz
assert_kept "F6 agent-* au basename vivant" agent-keep

# F7 — liste des worktrees indisponible : message, aucun retrait, aucun prune, exit 0.
UNAVAILABLE='test-sweep : liste des worktrees indisponible, aucun retrait'
projects "$(full "$ORPH")"
SWEEP_PATH=$nogit sweep "$MAIN"
assert_sweep_ran "F7 git absent"
assert_err_line "F7 git absent" "$UNAVAILABLE"
assert_nothing_removed "F7 git absent"
sweep "$NOT_REPO"
assert_sweep_ran "F7 dossier hors dépôt"
assert_err_line "F7 dossier hors dépôt" "$UNAVAILABLE"
assert_nothing_removed "F7 dossier hors dépôt"
FAKE_DOCKER_PS_FAIL=1 sweep "$MAIN"
assert_sweep_ran "F7 docker ps en échec"
assert_err_line "F7 docker ps en échec" "$UNAVAILABLE"
assert_nothing_removed "F7 docker ps en échec"

# F9 — seul le cache de build se prune : --force, --filter until=, jamais --all ; aucun autre prune.
projects "$(full "$ORPH")"
sweep "$MAIN"
prune_line=$(grep -E '^builder prune' "$FAKE_DOCKER_LOG" | head -n 1)
if [[ "$prune_line" == *--force* && "$prune_line" == *"--filter until=168h"* && "$prune_line" != *--all* && "$prune_line" != *" -a"* ]]; then
  pass
else
  fail "F9 : builder prune --force --filter until=168h sans --all attendu, obtenu « $prune_line »"
fi
other_prune=$(grep -E 'prune' "$FAKE_DOCKER_LOG" | grep -vE '^builder prune' | head -n 1)
if [[ -z "$other_prune" ]]; then pass; else fail "F9 : prune interdit : $other_prune"; fi
assert_err_line "F9 annonce" "test-sweep : cache de build — docker builder prune (until=168h)"
SWEEP_CACHE_AGE=24h sweep "$MAIN"
check "F9 SWEEP_CACHE_AGE surcharge l'âge" grep -qE '^builder prune .*--filter until=24h' "$FAKE_DOCKER_LOG"

# F10 — DRY_RUN=1 : rien retiré, rien pruné, lignes [simulation].
projects "$(full "$ORPH")"
DRY_RUN=1 sweep "$MAIN"
assert_sweep_ran "F10"
assert_nothing_removed "F10"
assert_err_line "F10 retrait simulé" "test-sweep : [simulation] retirerait $ORPH"
assert_err_line "F10 prune simulé" "test-sweep : [simulation] docker builder prune (until=168h) non lancé"

# F12 — un retrait en échec est dit, n'arrête ni le balayage (projet suivant, prune) ni la suite.
projects "$(full agent-zz)" "$(full "$ORPH")"
FAKE_DOCKER_FAIL_ON='volume rm' sweep "$MAIN"
assert_sweep_ran "F12"
assert_err_line "F12 retrait incomplet" "test-sweep : $ORPH : retrait incomplet, à reprendre à la main (make -C backend test-sweep)"
check "F12 projet suivant traité (agent-zz)" grep -qE '^network rm .*agent-zz_' "$FAKE_DOCKER_LOG"
check "F12 projet suivant traité ($ORPH)" grep -qE "^network rm .*${ORPH}_" "$FAKE_DOCKER_LOG"
check "F12 prune lancé malgré l'échec" grep -qE '^builder prune' "$FAKE_DOCKER_LOG"

# F11 — tests-complete commence par le balayage, dans les trois modes.
first_recipe_line() { printf '%s\n' "$mk_out" | grep -vE '^[[:space:]]*#' | grep -vE '^make(\[[0-9]+\])?: ' | sed -n 1p; }
assert_sweeps_first() { # assert_sweeps_first <libellé>
  local first
  first=$(first_recipe_line)
  if [[ "$mk_rc" -eq 0 && "$first" == *stack-sweep.sh* ]]; then
    pass
  else
    fail "$1 : première ligne de tests-complete attendue stack-sweep.sh, obtenue « $first » (rc=$mk_rc)"
  fi
}
projects ""
clear_label
mkn -C "$MAIN/backend" tests-complete
assert_sweeps_first "F11 principal"
write_nu_env "$WT_A"
mkn -C "$WT_A/backend" tests-complete
assert_sweeps_first "F11 nu"
set_label "$WT_A"
mkn -C "$WT_A/backend" tests-complete
assert_sweeps_first "F11 épreuve"
clear_label
# Exécution réelle en nu : le listage du balayage (`volume ls`, que lui seul fait) précède le premier up.
write_nu_env "$WT_A"
mk -C "$WT_A/backend" tests-complete
sweep_at=$(grep -nE '^volume ls' "$FAKE_DOCKER_LOG" | head -n 1 | cut -d: -f1)
up_at=$(grep -nE '^compose .* up( |$)' "$FAKE_DOCKER_LOG" | head -n 1 | cut -d: -f1)
if [[ "$mk_rc" -eq 0 && -n "$sweep_at" && -n "$up_at" && "$sweep_at" -lt "$up_at" ]]; then
  pass
else
  fail "F11 nu réel : balayage (ligne ${sweep_at:-absente}) avant le premier up (ligne ${up_at:-absente}), rc=$mk_rc"
fi
# F12 (suite) — un retrait en échec ne fait pas échouer tests-complete.
projects "$(full "$ORPH")"
FAKE_DOCKER_FAIL_ON='volume rm' mk -C "$WT_A/backend" tests-complete
if [[ "$mk_rc" -eq 0 ]] && log_has "volume rm" && log_has "phpunit tests/"; then
  pass
else
  fail "F12 : tests-complete doit aller au bout malgré un retrait en échec (rc=$mk_rc)"
fi

# F8 — entrée prunable (dossier supprimé sans `git worktree remove`) : sa stack est retirée. En
# dernier : WT_E disparaît. Les trois orphelins disent le pluriel.
rm -rf "$WT_E"
projects "$(full "amateo-test-$H_E")" "$(full "$ORPH")" "$(full agent-zz)"
sweep "$MAIN"
assert_sweep_ran "F8"
assert_removed "F8 worktree supprimé à la main" "amateo-test-$H_E"
assert_err_line "F8 compte" "test-sweep : 3 stacks orphelines retirées"

# ==============================================================================
printf '%d vérifications passées, %d échouées\n' "$passes" "$failures"
if [[ "$failures" -ne 0 ]]; then
  exit 1
fi
printf 'PASS: All tests passed\n'
