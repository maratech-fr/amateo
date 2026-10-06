# Reprise des captures de la vitrine (P5-27)

`capture-landing-shots.mjs` reprend les **4 visuels de `landing/`** en **clair ET en sombre**
(8 fichiers), sur l'app réelle, de façon reproductible.

> **Ce n'est pas un test.** Il vit dans `frontend/scripts/` (hors `frontend/tests/e2e/`, le
> `testDir` de `playwright.config.ts`) pour **ne jamais tourner en CI** — aucune CI ne couvre
> `landing/`, la seule preuve est une passe manuelle navigateur + captures (`.claude/rules/landing.md`).
> Il n'écrit **que** dans un dossier gitignoré ; il **ne touche ni `landing/` ni `frontend/src/`**.
> La copie vers `landing/assets/` est un geste **manuel**, après validation (dernière section).

## Ce qu'il produit

| Écran | Route | Fichier clair | Fichier sombre | Dimensions (viewport) |
|-------|-------|---------------|----------------|-----------------------|
| Planning généré | `/planning` | `planning.png` | `planning-dark.png` | 1080 × 608 |
| Calendrier matchs (week-end) | `/matchs` | `matchs.jpg` | `matchs-dark.jpg` | 1080 × 585 |
| Importer | `/matchs/importer` | `matchs-importer.jpg` | `matchs-importer-dark.jpg` | 1080 × 750 |
| Conflits (regroupés par coach) | `/matchs/conflits?pivot=coach` | `matchs-conflits.jpg` | `matchs-conflits-dark.jpg` | 1080 × 750 |

`deviceScaleFactor: 2` ⇒ images de **2160 px** de large (ordre de grandeur des assets actuels).
JPEG qualité 80, PNG pour le hero. Le nom sombre colle `-dark` avant l'extension — la vitrine
dérive ce nom toute seule (`landing/index.html`), **aucun code vitrine à éditer**.

## Variables d'environnement

| Variable | Rôle | Défaut |
|----------|------|--------|
| `DEMO_EMAIL` | **Requis.** Login du gestionnaire du club démo. | — |
| `DEMO_PASSWORD` | **Requis.** Son mot de passe. **Jamais en dur, jamais commité.** | — |
| `BASE_URL` | Cible. | `http://localhost:5173` |
| `OUT_DIR` | Dossier de sortie (relatif = depuis la racine du dépôt). | `captures/landing-shots/` |
| `SCRUB_FILE` | JSON local `{ "Vrai Nom": "Nom Démo" }` de remplacements DOM (patron P5-26). | aucun |
| `MATCHS_WEEKEND` | Samedi ISO (`YYYY-MM-DD`) à épingler pour le calendrier matchs. | auto |

## 1. Préparer les données (écrit en base dev — annoncer au fondateur avant)

Les commandes ci-dessous **écrivent en base**. Elles s'exécutent dans le conteneur `php-fpm`.
En **mode play** (base de jeu du fondateur `amateo_local`), une commande mutatrice est tuée par
le garde bac-à-sable : la passer par `backend/scripts/with-sandbox.sh` pour jouer dans le bac à
sable `amateo_dev`.

```bash
# (Re)semer le club « Démo Basket Club » — planning COMPLETED + matchs WE + domiciles fédéraux.
docker compose exec php-fpm php bin/console app:demo:seed --password='<min 12 car.>'
#   …ou, pour cibler le bac à sable depuis le mode play :
backend/scripts/with-sandbox.sh docker compose exec php-fpm php bin/console app:demo:seed --password='…'

# Poser une date en pleine saison (calendrier WE riche) — horloge simulée, démo/dev UNIQUEMENT.
#   --club accepte l'UUID ou le code club FFBB du club démo.
docker compose exec php-fpm php bin/console app:club:clock --club=<id|code> --date=2025-11-14
```

Choisir une `--date` sur laquelle le calendrier des matchs est bien rempli (un vendredi/week-end
de saison). Reporter ce samedi dans `MATCHS_WEEKEND` si l'auto ne tombe pas sur le bon week-end.

Si l'écran **Conflits** du club démo est vide, repli assumé (règle fermée) : prise sur une stack
réelle avec `SCRUB_FILE` pour remplacer tout nom réel par un nom de démo dans le DOM.

## 2. Lancer la stack dev puis le script

```bash
make -C frontend dev           # Vite dockerisé :5173 (proxie /api) — cible par défaut du script
cd frontend
DEMO_EMAIL='demo@…' DEMO_PASSWORD='…' node scripts/capture-landing-shots.mjs
# avec un week-end épinglé et un scrub :
DEMO_EMAIL='demo@…' DEMO_PASSWORD='…' MATCHS_WEEKEND=2025-11-15 SCRUB_FILE=../captures/scrub.json \
  node scripts/capture-landing-shots.mjs
```

Alternative à `:5173` : le service `frontend` Nginx `:8081` sert un `dist` **cuit** — pour l'y
viser, rebâtir d'abord (`docker compose build frontend && docker compose up -d --force-recreate
frontend`) puis `BASE_URL=http://localhost:8081`. En dev courant, `:5173` évite ce piège.

Le script, pour chaque thème (clair puis sombre) : pose `localStorage["cs-theme"]` avant le
premier rendu (pas de flash, pas de clic), se connecte, puis pour chaque écran fixe le viewport,
navigue, **attend la stabilité** (réseau calme + plus aucun spinner `aria-label="Chargement"`),
applique le scrub DOM si fourni, et capture. Sortie : 8 fichiers dans `OUT_DIR`.

## 3. Vérifier, puis copier à la main

Avant toute copie, **ouvrir les 8 images** et vérifier les règles d'anonymisation fermées
(`.claude/rules/landing.md`) : en-tête « Démo Basket Club » (jamais un club réel), **aucune
personne réelle** (un nom de démo générique est admis), données publiques seulement. C'est LA
surface RGPD du lot.

```bash
# UNIQUEMENT après validation visuelle (GO fondateur sur les 8 captures) :
cp captures/landing-shots/*.png captures/landing-shots/*.jpg landing/assets/
```

La copie remplace les versions claires existantes et ajoute les 4 `*-dark`. Recaler ensuite si
besoin les `alt`/`width`/`height` des `<img>` de `landing/index.html` (hors périmètre de ce
script). Vérifier enfin dans un vrai navigateur : bascule du bouton clair↔sombre (les 4 images
changent), aucun repli `onerror` déclenché (les 4 `-dark` en 200 dans l'onglet réseau).
