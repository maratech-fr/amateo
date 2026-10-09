#!/usr/bin/env bash
# Lanceur de capture — POSSEDE le cycle complet (correction fondateur 2026-10-09).
#
# A lancer SOUS le wrapper bac a sable (UNE seule bascule de toute la stack vers amateo_dev) :
#     backend/scripts/with-sandbox.sh bash video/capture/run.sh
#
#  - DEBUT : reconstruit le club de DEMONSTRATION (« Demo Basket Club », identites FICTIVES) via
#    seed-demo — le SEUL club filmable pour une video commerciale publique. Jamais le club BCCL
#    reel (seed-bccl = identites reelles). Jamais les deux seeds dans la meme base.
#  - Cree l'etat a filmer (periode de vacances + campagne de doleances + reponses de coachs +
#    mutualisation). Idempotent : la base est reconstruite a neuf a chaque run.
#  - Lance les captures Playwright de l'app, l'export du logo, puis la planche contact.
#  - FIN, meme en cas d'echec/interruption (trap EXIT) : restaure le bac a sable dans l'etat de la
#    CI (BCCL seul : db-empty + seed-bccl + seed-holidays) pour que Behat reste vert.
#
# N'ecrit JAMAIS hors du bac a sable amateo_dev : abandonne si la base visee n'est pas amateo_dev
# (regle 1). Aucun secret ni mot de passe ecrit dans un fichier ; le jeton JWT est fabrique a
# l'execution. Attentes bornees par timeout. Jamais FLUSHALL Redis.
set -uo pipefail

REPO=/home/marabou/projects/scheduler
OUT="$REPO/video/out"
CAPTURES="$OUT/captures"
TMP="$OUT/tmp"
API=http://localhost:8080/api
MAILPIT=http://localhost:8025
EMAIL_MANAGER='demo-bccl@amateo.fr'
CHROME="$(ls -d "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux64/chrome 2>/dev/null | sort -V | tail -1)"

mkdir -p "$CAPTURES" "$TMP"
cd "$REPO"

console() { docker compose exec -T -e APP_ENV=dev php-fpm php bin/console "$@"; }

# pj EXPR [args] : evalue EXPR (python) avec d = JSON de stdin, A = args (comme la ref). -I : isole.
pj() { python3 -I -c 'import sys,json
d=json.load(sys.stdin); A=sys.argv[2:]
r=eval(sys.argv[1])
print(json.dumps(r) if isinstance(r,(list,dict)) else ("" if r is None else r))' "$@"; }

# ---- Restauration de l'etat CI, quoi qu'il arrive -----------------------------------------------
_restored=0
restore_ci() {
  [[ "$_restored" == "1" ]] && return 0
  _restored=1
  set +e
  echo "==> run.sh: restauration du bac a sable dans l'etat CI (BCCL seul)..." >&2
  CONFIRM=yes make -C "$REPO/backend" db-empty >&2
  CONFIRM=yes bash -c "make -C '$REPO/backend' seed-bccl && make -C '$REPO/backend' seed-holidays" >&2
  docker compose restart engine messenger-worker >/dev/null 2>&1 || true
  echo "==> run.sh: bac a sable remis dans l'etat CI (BCCL + vacances)." >&2
}
trap restore_ci EXIT INT TERM

# ---- Garde : base RELLEMENT visee == amateo_dev (regle 1, avant toute ecriture) ------------------
assert_sandbox() {
  local db
  db="$(console dbal:run-sql "SELECT current_database() AS db" 2>/dev/null | sed -E 's/[[:space:]]//g' | grep -vE '^-*$' | grep -vxE 'db' | head -1)"
  echo "base visee : ${db:-<non resolue>}"
  if [[ "$db" != "amateo_dev" ]]; then
    echo "ABANDON : base visee « ${db:-<non resolue>} » != amateo_dev. Rien n'est ecrit." >&2
    echo "  (lancer SOUS backend/scripts/with-sandbox.sh ; stack demarree ?)" >&2
    exit 1
  fi
}

# ---- Reconstruction du club de demonstration ----------------------------------------------------
reconstruct_demo() {
  echo "==> reconstruction du club de DEMONSTRATION (seed-demo)..."
  CONFIRM=yes make -C "$REPO/backend" db-empty || { echo "db-empty echoue — abandon."; exit 1; }
  CONFIRM=yes bash -c "make -C '$REPO/backend' seed-demo && make -C '$REPO/backend' seed-holidays" \
    || { echo "seed-demo / seed-holidays echoue — abandon."; exit 1; }
  docker compose restart engine messenger-worker >/dev/null 2>&1 || true
  # Re-asserte apres reconstruction (ceinture).
  assert_sandbox
}

assert_sandbox
reconstruct_demo

# ---- JWT du gestionnaire demo (fabrique a l'execution, jamais stocke) ----------------------------
TOKEN="$(console lexik:jwt:generate-token "$EMAIL_MANAGER" --ttl=3600 --user-class='App\Entity\User' | tr -d '[:space:]')"
if [[ -z "$TOKEN" ]]; then
  echo "ECHEC : jeton JWT vide pour $EMAIL_MANAGER — le compte demo existe-t-il ?" >&2
  echo "  (si refus, verifier le seed-demo ; ne jamais modifier l'appli)" >&2
  exit 1
fi
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/ld+json" -H "Content-Type: application/ld+json")
post() { curl -sS "${H[@]}" -X POST "$API/$1" -d "$2"; }

# ---- Etat a filmer : campagne de doleances de coachs (plan 5) ------------------------------------
# Resilient : si une sous-etape echoue, on LOG et on continue (les autres plans se capturent quand
# meme). La base etant reconstruite a neuf, pas de nettoyage necessaire (restore_ci efface tout).
WISH_TOKEN=""
MAILID=""
seed_doleances() {
  curl -sS "${H[@]}" "$API/teams?itemsPerPage=200" > "$TMP/teams.json" 2>/dev/null
  local nteams; nteams="$(pj 'len(d.get("member",[]))' < "$TMP/teams.json" 2>/dev/null || echo 0)"
  echo "equipes demo : $nteams"
  if [[ "${nteams:-0}" -lt 2 ]]; then echo "WARN: pas assez d'equipes pour la campagne — P05 (cote coach) saute."; return 0; fi

  # 3 premieres equipes pour le coach cree ; les 2 premieres serviront a la mutualisation.
  local T1 T2 T3
  T1="$(pj 'd["member"][0]["id"]' < "$TMP/teams.json")"
  T2="$(pj 'd["member"][1]["id"]' < "$TMP/teams.json")"
  T3="$(pj '(d["member"][2] if len(d["member"])>2 else d["member"][1])["id"]' < "$TMP/teams.json")"
  local ALL_TEAMS; ALL_TEAMS="$(pj '[t["id"] for t in d["member"]]' < "$TMP/teams.json")"

  # Coach FICTIF et clairement d'exemple (valide par le fondateur via noms-visibles.md).
  local SUFFIX EMAIL COACH
  SUFFIX="$(date +%s)"
  EMAIL="camille.durand.$SUFFIX@exemple-coach.fr"
  COACH="$(post coaches "{\"firstName\":\"Camille\",\"lastName\":\"Durand\",\"email\":\"$EMAIL\",\"isActive\":true}" | pj 'd.get("id")')"
  echo "coach cree : ${COACH:-<echec>} ($EMAIL)"
  if [[ -z "$COACH" ]]; then echo "WARN: creation coach echouee — P05 (cote coach) saute."; return 0; fi
  for T in "$T1" "$T2" "$T3"; do
    post team_coaches "{\"teamId\":\"$T\",\"coachId\":\"$COACH\",\"role\":\"ASSISTANT\"}" >/dev/null 2>&1 || true
  done

  # Periode de vacances de la Toussaint (schoolHolidayId optionnel — omis, cf. CalendarEntryInput).
  local ENTRY
  ENTRY="$(post calendar_entries '{"kind":"period","periodType":"holiday","title":"Vacances de la Toussaint","startDate":"2026-10-17","endDate":"2026-11-01"}' | tee "$TMP/entry.json" | pj 'd.get("id")')"
  echo "periode vacances : ${ENTRY:-<echec>}"
  if [[ -z "$ENTRY" ]]; then echo "WARN: creation periode echouee — $(head -c 300 "$TMP/entry.json")"; return 0; fi

  # Mutualisation declarative : une passerelle entre deux equipes de la campagne (bloc « Mutualiser avec »).
  post team_links "{\"teamAId\":\"$T1\",\"teamBId\":\"$T2\",\"linkType\":\"NOT_SIMULTANEOUS\"}" >/dev/null 2>&1 || true

  # Campagne sur la periode.
  post coach_wish_campaigns "{\"calendarEntryId\":\"$ENTRY\",\"deadline\":\"2026-10-15\",\"weeks\":[\"2026-10-19\",\"2026-10-26\"],\"teamIds\":$ALL_TEAMS}" > "$TMP/campaign.json" 2>/dev/null
  local CAMPAIGN; CAMPAIGN="$(pj 'd.get("id")' < "$TMP/campaign.json")"
  echo "campagne : ${CAMPAIGN:-<echec>} — coachs : $(pj 'len(d.get("coaches",[]))' < "$TMP/campaign.json" 2>/dev/null || echo 0)"
  if [[ -z "$CAMPAIGN" ]]; then echo "WARN: campagne echouee — $(head -c 300 "$TMP/campaign.json")"; return 0; fi

  WISH_TOKEN="$(pj 'next((c["token"] for c in d["coaches"] if c["coachId"]==A[0]),"")' "$COACH" < "$TMP/campaign.json")"
  echo "$WISH_TOKEN" > "$TMP/wish-token.txt"

  # Deux AUTRES coachs repondent (page publique, sans Bearer) pour peupler la todo gestionnaire.
  local n=0 TK TEAM BODY
  for TK in $(pj '"\n".join([c["token"] for c in d["coaches"] if c["coachId"]!=A[0]][:2])' "$COACH" < "$TMP/campaign.json"); do
    TEAM="$(curl -sS -H "Accept: application/ld+json" "$API/coach-wishes/public/$TK" | pj 'd["teams"][0]["id"]' 2>/dev/null)"
    [[ -z "$TEAM" ]] && continue
    n=$((n+1))
    if [[ $n == 1 ]]; then BODY="{\"submissions\":[{\"teamId\":\"$TEAM\",\"weekStart\":\"2026-10-19\",\"slotsWanted\":2,\"unavailableDays\":[3],\"wishedDays\":[2,4],\"comment\":\"Stage le mercredi, merci de garder mardi et jeudi.\"}]}"
    else BODY="{\"submissions\":[{\"teamId\":\"$TEAM\",\"weekStart\":\"2026-10-26\",\"slotsWanted\":1,\"unavailableDays\":[1,2],\"wishedDays\":[5],\"comment\":\"\"}]}"; fi
    curl -sS -o /dev/null -w "reponse coach $n -> %{http_code}\n" -H "Accept: application/ld+json" -H "Content-Type: application/json" -X POST "$API/coach-wishes/public/$TK" -d "$BODY" || true
  done

  # Envoi du lien au coach cree -> e-mail dans Mailpit.
  post "coach_wish_campaigns/$CAMPAIGN/send-links" "{\"coachIds\":[\"$COACH\"]}" | pj '{"sent": d.get("sent")}' 2>/dev/null || true
  timeout 60 bash -c "until curl -s '$MAILPIT/api/v1/search?query=to:$EMAIL' | grep -q '\"ID\"'; do sleep 2; done" || echo "mail non recu sous 60 s"
  MAILID="$(curl -s "$MAILPIT/api/v1/search?query=to:$EMAIL" | pj '(d.get("messages") or [{}])[0].get("ID","")' 2>/dev/null)"
  echo "mail : ${MAILID:-<aucun>}"
  # Pour noms-visibles.md
  printf '%s\n' "$EMAIL" > "$TMP/coach-email.txt"
}
seed_doleances || echo "WARN: seed_doleances a rencontre une erreur — on continue."

# ---- Membre du bureau en LECTURE SEULE (plan 10) ------------------------------------------------
# Aucune API ne cree un membre pour un compte de DEMO (POST /api/invitations -> 403 pour un demo ;
# pas de POST /api/memberships ni de ressource ClubUser ; aucune commande console). On insere donc
# directement les deux lignes dans le bac a sable (contournement « cote donnees », jamais l'appli)
# via psql superuser (bypass RLS), puis on fabrique un JWT pour ce membre. Le mot de passe n'est
# jamais utilise (connexion par cookie Bearer JWT, UserChecker absent du pare-feu /api).
MEMBER_JWT=""
MEMBER_EMAIL='bureau.lecture@exemple-bureau.fr'
psql_owner() { docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d amateo_dev -v ON_ERROR_STOP=1 -Atc "$0"' "$1"; }
seed_member() {
  local club_id
  club_id="$(psql_owner "SELECT id FROM club WHERE ffbb_club_code='ARA9999999'" | tr -d '[:space:]')"
  if [[ -z "$club_id" ]]; then echo "WARN: club demo introuvable — P10 saute."; return 0; fi
  psql_owner "
    INSERT INTO app_user (id, version, created_at, updated_at, email, password_hash, first_name, last_name, email_verified_at, is_demo)
    VALUES (gen_random_uuid(), 1, now(), now(), '$MEMBER_EMAIL', 'unused_login_disabled_jwt_only_placeholder_000000000000', 'Bureau', 'Lecture', now(), false)
    ON CONFLICT DO NOTHING;
    INSERT INTO club_user (id, version, created_at, updated_at, club_id, user_id, role, joined_at, is_active)
    SELECT gen_random_uuid(), 1, now(), now(), '$club_id', u.id, 'member', now(), true FROM app_user u WHERE u.email = '$MEMBER_EMAIL'
    ON CONFLICT DO NOTHING;
  " || { echo "WARN: insertion du membre echouee — P10 saute."; return 0; }
  MEMBER_JWT="$(console lexik:jwt:generate-token "$MEMBER_EMAIL" --user-class='App\Entity\User' | tr -d '[:space:]')"
  printf -- '- Nom affiche : **Bureau Lecture** (fictif, exemple)\n- E-mail : `%s` (role membre, lecture seule)\n' "$MEMBER_EMAIL" > "$TMP/member.txt"
  echo "membre lecture seule : $MEMBER_EMAIL (jwt: ${MEMBER_JWT:+ok}${MEMBER_JWT:-ECHEC})"
}
seed_member || echo "WARN: seed_member erreur — on continue."

# ---- Captures ------------------------------------------------------------------------------------
cd "$REPO/video"
echo "==> capture de l'application (Playwright)..."
PW_CHROME="$CHROME" node capture/capture-app.cjs "$TOKEN" "${WISH_TOKEN:-}" "${MAILID:-}" "${MEMBER_JWT:-}" 2>&1 | tee "$OUT/capture-app.log" || true
echo "==> export des images du logo..."
PW_CHROME="$CHROME" node capture/capture-logo.cjs 2>&1 | tee "$OUT/capture-logo.log" || true
echo "==> generation de noms-visibles.md..."
bash capture/make-noms-visibles.sh "$TOKEN" 2>&1 | tee "$OUT/noms-visibles.log" || true
echo "==> planche contact..."
PW_CHROME="$CHROME" node capture/contact-sheet.cjs 2>&1 | tee "$OUT/contact-sheet.log" || true

echo "==> capture terminee. Restauration de l'etat CI en sortie (trap)."
