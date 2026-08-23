#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Preuve des pages système (503 subie + maintenance choisie) servies par CADDY.
#
# Règle du dépôt : « non testé = inexistant ». Ce script est ENTIÈREMENT local,
# Docker seul — aucune dépendance à la stack applicative.
#
# ⚠ UNE SEULE SOURCE : la conf de test est DÉRIVÉE de docs/ops/Caddyfile.example
#   par `sed` (ports locaux, auto_https off, cible du proxy). On ne maintient
#   jamais une seconde conf qui dériverait de la vraie.
#
# Huit cas assertés au curl :
#   1. amont injoignable → GET /            → 503 + « Le gymnase est fermé »
#   2. amont injoignable → GET /config.js   → le VRAI config de la landing (marqueur
#      discriminant + Content-Type JS), et l'équivalent sous maintenance (cas 3ter)
#   3. témoin de maintenance posé → GET /    → 503 + « On refait le parquet » + Retry-After
#   4. témoin retiré, amont vivant → GET /   → 200, contenu de l'amont intact
#   5. amont vivant répondant 404 → GET /x   → 404 de l'amont, corps INTACT
#   6. dossier system-pages absent → GET /   → 5xx corps vide, jamais 200
#   7. maintenance ON, témoin vide (`touch`)  → /maintenance-until → 200 corps VIDE
#   8. maintenance ON, témoin ENRICHI         → /maintenance-until → 200 + horodatage
#   9. maintenance ON, GET /maintenance.on    → la PAGE (503), jamais le témoin en clair
#  10. maintenance OFF, /maintenance-until     → part à l'amont, le témoin NE FUIT PAS
#  11. poids des pages servies                 → chaque page < 40 Ko (garde de poids)
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CADDY_IMAGE="${CADDY_IMAGE:-caddy:2}"
NET="syspages-test-net-$$"
CADDY_CT="syspages-caddy-$$"
UP_CT="syspages-upstream-$$"
WORK="$(mktemp -d)"
STAGING="$WORK/deploy"          # monté sur /srv/clubscheduler dans le conteneur
APP_HOST="app.localhost"

PASS=0
FAIL=0

cleanup() {
  docker rm -f "$CADDY_CT" "$UP_CT" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT

fatal() { echo "FATAL: $*" >&2; exit 1; }

# ── 1. Dériver la conf de test depuis LA vraie (une seule source) ────────────
EXAMPLE="$ROOT/docs/ops/Caddyfile.example"
[ -f "$EXAMPLE" ] || fatal "introuvable : $EXAMPLE"

{
  cat <<'GLOBAL'
{
	auto_https off
	admin off
	persist_config off
}
GLOBAL
  # Adresses → HTTP local, matché par Host ; cible du proxy → conteneur upstream.
  # Rien d'autre n'est touché : root, handle_errors, matcher file, status 503,
  # header Retry-After restent EXACTEMENT ceux du modèle versionné.
  sed -E \
    -e 's#^amateo\.app \{#http://amateo.localhost {#' \
    -e 's#^www\.amateo\.app \{#http://www.localhost {#' \
    -e 's#^app\.amateo\.app \{#http://app.localhost {#' \
    -e 's#127\.0\.0\.1:8081#'"$UP_CT"':80#' \
    "$EXAMPLE"
} > "$WORK/Caddyfile"

# Conf de l'amont factice : / → 200 connu, /notfound → 404 à corps connu.
cat > "$WORK/upstream.Caddyfile" <<'UP'
{
	auto_https off
	admin off
	persist_config off
}
:80 {
	@nf path /notfound
	handle @nf {
		respond "UPSTREAM-CUSTOM-404-BODY" 404
	}
	handle {
		respond "UPSTREAM-ALIVE-HOMEPAGE" 200
	}
}
UP

# ── 2. Staging disque = ce que le deploy pose sous DEPLOY_PATH ────────────────
mkdir -p "$STAGING"
cp -r "$ROOT/landing" "$STAGING/landing"
cp -r "$ROOT/system-pages" "$STAGING/system-pages"

# ── 3. Valider la conf (prouve que `file_server { status }` passe dans ce Caddy) ─
echo "==> Caddy image : $CADDY_IMAGE"
docker run --rm -v "$WORK/Caddyfile:/etc/caddy/Caddyfile:ro" "$CADDY_IMAGE" \
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 \
  || fatal "la conf DÉRIVÉE ne valide pas — 'file_server { status }' est-il supporté par $CADDY_IMAGE ? (condition de retour en validation, PAS à contourner)"
docker run --rm -v "$EXAMPLE:/etc/caddy/Caddyfile:ro" "$CADDY_IMAGE" \
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 \
  || fatal "le MODÈLE docs/ops/Caddyfile.example ne valide pas dans $CADDY_IMAGE"

# ── 4. Réseau + conteneur Caddy (l'amont reste DOWN pour les cas 1/2/6) ──────
docker network create "$NET" >/dev/null
docker run -d --name "$CADDY_CT" --network "$NET" -p 127.0.0.1::80 \
  -v "$WORK/Caddyfile:/etc/caddy/Caddyfile:ro" \
  -v "$STAGING:/srv/clubscheduler:ro" \
  "$CADDY_IMAGE" >/dev/null

HOSTPORT="$(docker port "$CADDY_CT" 80/tcp | head -1 | sed 's/.*://')"
[ -n "$HOSTPORT" ] || fatal "port hôte de Caddy introuvable"
BASE="http://127.0.0.1:$HOSTPORT"

# Attendre que Caddy écoute (amont down → 502/503 attendu, mais il RÉPOND).
for i in $(seq 1 50); do
  code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $APP_HOST" "$BASE/" || true)"
  [ -n "$code" ] && [ "$code" != "000" ] && break
  sleep 0.2
done
[ "${code:-000}" != "000" ] || fatal "Caddy ne répond pas sur $BASE"

# ── Helpers d'assertion ──────────────────────────────────────────────────────
# get <path>  → remplit $STATUS, $BODY, $HEADERS
get() {
  local path="$1"
  local hf bf
  hf="$(mktemp)"; bf="$(mktemp)"
  STATUS="$(curl -s -o "$bf" -D "$hf" -w '%{http_code}' -H "Host: $APP_HOST" "$BASE$path" || true)"
  BODY="$(cat "$bf")"; HEADERS="$(cat "$hf")"
  rm -f "$hf" "$bf"
}
ok()   { PASS=$((PASS+1)); printf '  \033[32mPASS\033[0m %s\n' "$1"; }
ko()   { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n' "$1"; }
expect_status() { [ "$STATUS" = "$1" ] && return 0 || { echo "     status attendu=$1 obtenu=$STATUS" >&2; return 1; }; }
body_has()  { printf '%s' "$BODY" | grep -qF "$1"; }

echo "==> Cas 1/2/6 — amont INJOIGNABLE (conteneur upstream non démarré)"

# Cas 1 — GET / → 503 subie + copie du 503
get "/"
if expect_status 503 && body_has "Le gymnase est fermé"; then
  ok "1. GET / → 503 + « Le gymnase est fermé »"
else
  ko "1. GET / → 503 + « Le gymnase est fermé » (status=$STATUS)"
fi

# Cas 2 — GET /config.js → le config de la landing, même origine.
# ⚠ Le marqueur DOIT discriminer : `LANDING_CONFIG` seul est présent DANS
#   system-pages/503.html (son script de repli le teste), donc l'asserter revient
#   à lire la page d'erreur et à passer au vert sur une conf CASSÉE — c'est
#   exactement ce qui masquait le réordonnancement `rewrite` avant `handle`.
#   On assert donc l'AFFECTATION (présente dans le seul config.js), la valeur
#   qu'elle porte, le Content-Type JS, ET l'absence du marqueur de la page 503.
get "/config.js"
if body_has "window.LANDING_CONFIG = {" \
   && body_has "brand:" \
   && ! body_has "Le gymnase est fermé" \
   && expect_status 200 \
   && printf '%s' "$HEADERS" | grep -qiE '^Content-Type:[[:space:]]*(text|application)/javascript'; then
  ok "2. GET /config.js → VRAI config landing en 200 (le 503 est à la PAGE, pas au script)"
else
  ko "2. GET /config.js → VRAI config landing (status=$STATUS, ct=$(printf '%s' "$HEADERS" | grep -i '^Content-Type' | tr -d '\r'))"
fi

# Cas 6 — system-pages ABSENT (état d'avant le premier déploiement) → 5xx corps vide
mv "$STAGING/system-pages" "$STAGING/system-pages.hidden"
get "/"
if [ "${STATUS:0:1}" = "5" ] && [ "$STATUS" != "200" ] && ! body_has "Le gymnase est fermé"; then
  ok "6. system-pages absent → 5xx corps vide (status=$STATUS, jamais 200)"
else
  ko "6. system-pages absent → 5xx corps vide (status=$STATUS, body='${BODY:0:40}')"
fi
mv "$STAGING/system-pages.hidden" "$STAGING/system-pages"

echo "==> Démarrage de l'amont vivant"
docker run -d --name "$UP_CT" --network "$NET" \
  -v "$WORK/upstream.Caddyfile:/etc/caddy/Caddyfile:ro" \
  "$CADDY_IMAGE" >/dev/null
# Laisser la résolution DNS Docker prendre l'amont.
for i in $(seq 1 50); do
  get "/"; [ "$STATUS" = "200" ] && break; sleep 0.2
done

# Cas 4 — amont vivant → 200, contenu de l'amont intact
get "/"
if expect_status 200 && body_has "UPSTREAM-ALIVE-HOMEPAGE"; then
  ok "4. amont vivant → 200 + contenu amont intact"
else
  ko "4. amont vivant → 200 + contenu amont intact (status=$STATUS)"
fi

# Cas 5 — amont vivant répondant 404 → le 404 de l'amont, corps INTACT
# (falsification de « ne jamais remplacer une erreur de l'application »).
get "/notfound"
if expect_status 404 && body_has "UPSTREAM-CUSTOM-404-BODY" && ! body_has "Le gymnase est fermé"; then
  ok "5. amont 404 → 404 traversant, corps de l'amont INTACT"
else
  ko "5. amont 404 → 404 traversant, corps de l'amont INTACT (status=$STATUS, body='${BODY:0:40}')"
fi

# Cas 3 — témoin de maintenance posé → 503 + « On refait le parquet » + Retry-After
# (posé alors que l'amont est VIVANT : prouve que le matcher intercepte AVANT le proxy).
echo "==> Cas 3 — interrupteur de maintenance armé"
touch "$STAGING/maintenance.on"
get "/"
if expect_status 503 \
   && body_has "On refait le parquet" \
   && printf '%s' "$HEADERS" | grep -qiE '^Retry-After:[[:space:]]*600'; then
  ok "3. maintenance ON → 503 + « On refait le parquet » + Retry-After: 600"
else
  ko "3. maintenance ON → 503 + « On refait le parquet » + Retry-After (status=$STATUS)"
fi
# Cas 3ter — même preuve de marque sur la branche MAINTENANCE : c'est un bloc
# DISTINCT du handle_errors, il a son propre `route` et peut casser seul.
get "/config.js"
if body_has "window.LANDING_CONFIG = {" \
   && ! body_has "On refait le parquet" \
   && printf '%s' "$HEADERS" | grep -qiE '^Content-Type:[[:space:]]*(text|application)/javascript'; then
  ok "3ter. maintenance ON → GET /config.js → VRAI config landing, status=$STATUS"
else
  ko "3ter. maintenance ON → GET /config.js → VRAI config landing (status=$STATUS)"
fi

# Cas 7 — témoin VIDE (posé par `touch` ci-dessus) → /maintenance-until sert un corps
# VIDE en 200 : c'est la rétro-compat du runbook (touch nu) — la page, voyant le vide,
# NE montre aucun compteur. On prouve ici la MOITIÉ câblage ; la moitié JS (ligne masquée)
# est prouvée au navigateur.
get "/maintenance-until"
if expect_status 200 && [ -z "$BODY" ]; then
  ok "7. maintenance ON, témoin vide (touch) → /maintenance-until = 200 corps vide"
else
  ko "7. maintenance ON, témoin vide → 200 corps vide (status=$STATUS, body='${BODY:0:40}')"
fi

# Cas 8 — témoin ENRICHI : l'exploitant écrit l'heure de retour DANS le témoin. La
# source du compteur devient lisible same-origin, exactement telle qu'écrite.
STAMP="2099-01-02T20:30:00+00:00"
printf '%s' "$STAMP" > "$STAGING/maintenance.on"
get "/maintenance-until"
if expect_status 200 && body_has "$STAMP"; then
  ok "8. maintenance ON, témoin enrichi → /maintenance-until = 200 + horodatage servi"
else
  ko "8. maintenance ON, témoin enrichi → 200 + horodatage (status=$STATUS, body='${BODY:0:40}')"
fi

# Cas 9 — le CHEMIN BRUT du témoin n'est jamais servi en clair : GET /maintenance.on
# tombe sur le rewrite global → la PAGE de maintenance (503), pas l'horodatage. Seule
# /maintenance-until l'expose.
get "/maintenance.on"
if expect_status 503 && body_has "On refait le parquet" && ! body_has "$STAMP"; then
  ok "9. maintenance ON, GET /maintenance.on → la PAGE (503), jamais le témoin en clair"
else
  ko "9. maintenance ON, GET /maintenance.on → PAGE 503 sans l'horodatage (status=$STATUS)"
fi

rm -f "$STAGING/maintenance.on"
# Vérif de symétrie : le retrait rouvre immédiatement (sans reload).
get "/"
if expect_status 200 && body_has "UPSTREAM-ALIVE-HOMEPAGE"; then
  ok "3bis. maintenance OFF → 200 (réouverture immédiate, sans reload)"
else
  ko "3bis. maintenance OFF → 200 (status=$STATUS)"
fi

# Cas 10 — NON-FUITE : maintenance OFF (témoin retiré) + amont VIVANT. /maintenance-until
# n'est plus une route (elle vit DANS handle @maintenance) : la requête part au
# reverse_proxy comme n'importe quelle autre → corps de l'amont. Si la route avait fui
# hors du bloc maintenance, elle réécrirait vers /maintenance.on ABSENT → 404, PAS le
# corps de l'amont : l'assertion discrimine.
get "/maintenance-until"
if expect_status 200 && body_has "UPSTREAM-ALIVE-HOMEPAGE" && ! body_has "2099-01-02"; then
  ok "10. maintenance OFF → /maintenance-until part à l'amont (le témoin NE FUIT PAS)"
else
  ko "10. maintenance OFF → /maintenance-until ne doit PAS servir le témoin (status=$STATUS, body='${BODY:0:40}')"
fi

# Cas 11 — GARDE DE POIDS : chaque page servie < 40 Ko (40 × 1024 o). La borne dure de la
# règle est 100 Ko, mais les pages font ~13-16 Ko : un garde à 100 Ko ne se déclencherait
# JAMAIS. 40 Ko laisse ~2,5-3× de marge tout en restant un vrai signal. Falsifiable :
# gonfler une page au-delà du seuil fait rougir ce cas.
echo "==> Cas 11 — poids des pages servies (< 40 Ko chacune)"
MAX_BYTES=40960
for f in 503.html maintenance.html; do
  bytes="$(wc -c < "$STAGING/system-pages/$f")"
  if [ "$bytes" -lt "$MAX_BYTES" ]; then
    ok "11. $f = ${bytes} o (< ${MAX_BYTES} o)"
  else
    ko "11. $f = ${bytes} o (≥ ${MAX_BYTES} o — page trop lourde)"
  fi
done

echo
echo "==> Résultat : $PASS OK, $FAIL KO"
[ "$FAIL" -eq 0 ]
