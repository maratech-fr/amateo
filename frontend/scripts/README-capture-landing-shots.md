# Reprise des captures de la vitrine (P5-27)

`capture-landing-shots.mjs` reprend les **4 visuels de `landing/`** en **clair ET en sombre**
(8 fichiers), sur l'app réelle, de façon reproductible.

> **Ce n'est pas un test.** Il vit dans `frontend/scripts/` (hors `frontend/tests/e2e/`, le
> `testDir` de `playwright.config.ts`) pour **ne jamais tourner en CI** — aucune CI ne couvre
> `landing/`, la seule preuve est une passe manuelle navigateur + captures (`.claude/rules/landing.md`).
> Il n'écrit **que** dans un dossier gitignoré ; il **ne touche ni `landing/` ni `frontend/src/`**.
> La copie vers `landing/assets/` est un geste **manuel**, après validation (dernière section).

## Cible : le club BCCL de développement (décision fondateur 2026-10-06, option A)

Les captures se font sur le **club BCCL de DÉVELOPPEMENT** du bac à sable — `app:bccl:seed`
(profil dev) : club **« B CHARPENNES CROIX LUIZET »** (ARA0069036), **planning de saison validé**,
gestionnaire fictif par défaut `dev-bccl@amateo.local` / `charge-load-test-pwd`
(`BcclSeedProfile::dev()`). **Pas** le club démo : sa remise à zéro le laisse avant génération et
sans matchs.

Le club dev est un **vrai club (non-démo)** : on **n'y pose JAMAIS d'horloge simulée** (règle
fondateur 2026-10-02). Les écrans matchs utilisent l'**horloge réelle** ; `MATCHS_WEEKEND` choisit
le week-end affiché.

**Anonymisation à l'écran OBLIGATOIRE** (`.claude/rules/landing.md` §captures) — appliquée par le
script avant **chaque** capture, même sans réglage :

- le nom du club et son code FFBB sont remplacés dans le DOM (table par défaut :
  `B CHARPENNES CROIX LUIZET` → `Démo Basket Club`, `ARA0069036` → `ARA9999999`, insensible à la
  casse) ;
- le **blason du club** est retiré de l'en-tête — l'app retombe sur le monogramme produit neutre
  qu'elle affiche quand un club n'a pas de logo (patron P5-26) ;
- `SCRUB_FILE` (optionnel, local, jamais commité) **complète/surcharge** cette table pour un nom
  supplémentaire repéré sur une capture.

Coachs = surnoms fictifs du seed. Gymnases et clubs adverses = données publiques autorisées.

## Ce qu'il produit

| Écran | Route | Fichier clair | Fichier sombre | Dimensions (viewport) |
|-------|-------|---------------|----------------|-----------------------|
| Planning généré | `/planning` | `planning.png` | `planning-dark.png` | 1080 × 608 |
| Calendrier matchs (week-end) | `/matchs?semaine=…` | `matchs.jpg` | `matchs-dark.jpg` | 1080 × 585 |
| Importer | `/matchs/importer` | `matchs-importer.jpg` | `matchs-importer-dark.jpg` | 1080 × 750 |
| Conflits (regroupés par coach) | `/matchs/conflits?pivot=coach` | `matchs-conflits.jpg` | `matchs-conflits-dark.jpg` | 1080 × 750 |

`deviceScaleFactor: 2` ⇒ images de **2160 px** de large (ordre de grandeur des assets actuels).
JPEG qualité 80, PNG pour le hero. Le nom sombre colle `-dark` avant l'extension — la vitrine
dérive ce nom toute seule (`landing/index.html`), **aucun code vitrine à éditer**.

## Variables d'environnement

| Variable | Rôle | Défaut |
|----------|------|--------|
| `DEMO_EMAIL` | **Requis.** Login du gestionnaire du club BCCL dev. | — |
| `DEMO_PASSWORD` | **Requis.** Son mot de passe. **Jamais en dur, jamais commité.** | — |
| `BASE_URL` | Cible. | `http://localhost:5173` |
| `OUT_DIR` | Dossier de sortie (relatif = depuis la racine du dépôt). | `captures/landing-shots/` |
| `IMPORT_FBI` | Chemin d'un export FBI `.xlsx` à importer avant les captures matchs. Vide ⇒ pas d'import. | aucun |
| `SCRUB_FILE` | JSON local `{ "À remplacer": "Valeur démo" }` **fusionné** par-dessus la table par défaut. | aucun |
| `MATCHS_WEEKEND` | Samedi ISO (`YYYY-MM-DD`) du week-end à afficher pour le calendrier matchs. | `2026-11-14` |

## 1. Préparer les données (écrit en base dev — annoncer au fondateur avant)

Les commandes ci-dessous **écrivent en base**. En **mode play** (base de jeu du fondateur
`amateo_local`), une commande mutatrice est tuée par le garde bac-à-sable : on les passe par
`backend/scripts/with-sandbox.sh` pour jouer dans le bac à sable `amateo_dev`.

> ⚠ `with-sandbox.sh` **bascule toute la stack et redémarre les workers** — ne jamais le lancer
> pendant que le fondateur teste ; l'annoncer avant.

```bash
# (a) Repartir d'une base propre, puis (re)semer le club BCCL dev (create-only).
#     db-empty AVANT seed-bccl : avertir, c'est destructif pour la base dev.
backend/scripts/with-sandbox.sh make -C backend CONFIRM=yes db-empty
backend/scripts/with-sandbox.sh make -C backend CONFIRM=yes seed-bccl
```

Le seed pose le club, les équipes, les coachs (surnoms fictifs), le logo club, le **planning de
saison validé** et la répartition WE des matchs — mais **aucune rencontre** (`app:bccl:seed` ne
sème que des créneaux idéaux). Les rencontres arrivent de l'**import FBI** ci-dessous.

Fichier d'import recommandé : **`backend/tests/Fixtures/fbi/rechercherRencontre.xlsx`**
(124 rencontres, saison 2026-27). Pas d'horloge simulée à poser : l'horloge réelle est sur la
saison en cours, d'où le défaut `MATCHS_WEEKEND=2026-11-14` (ajuster si ce samedi est pauvre).

## 2. Lancer la stack dev puis le script

```bash
make -C frontend dev           # Vite dockerisé :5173 (proxie /api) — cible par défaut du script
cd frontend
DEMO_EMAIL='dev-bccl@amateo.local' DEMO_PASSWORD='charge-load-test-pwd' \
  IMPORT_FBI=../backend/tests/Fixtures/fbi/rechercherRencontre.xlsx \
  node scripts/capture-landing-shots.mjs
# en figeant un autre week-end et avec un scrub complémentaire :
DEMO_EMAIL='dev-bccl@amateo.local' DEMO_PASSWORD='charge-load-test-pwd' \
  IMPORT_FBI=../backend/tests/Fixtures/fbi/rechercherRencontre.xlsx \
  MATCHS_WEEKEND=2026-11-21 SCRUB_FILE=../captures/scrub.json \
  node scripts/capture-landing-shots.mjs
```

Alternative à `:5173` : le service `frontend` Nginx `:8081` sert un `dist` **cuit** — pour l'y
viser, rebâtir d'abord (`docker compose build frontend && docker compose up -d --force-recreate
frontend`) puis `BASE_URL=http://localhost:8081`. En dev courant, `:5173` évite ce piège.

Le script, pour chaque thème (clair puis sombre) : pose `localStorage["cs-theme"]` avant le
premier rendu (pas de flash, pas de clic), se connecte, puis pour chaque écran fixe le viewport,
navigue, **attend la stabilité** (réseau calme + plus aucun spinner `aria-label="Chargement"`),
**anonymise le DOM** (remplacements + blason club retiré), et capture. Si `IMPORT_FBI` est fourni,
l'import FBI passe **une seule fois** avant la boucle, via l'UI `/matchs/importer` (dépôt du
fichier → appariements proposés par l'écran appliqués tels quels → « Importer » ; idempotent si un
dépôt existe déjà). Sortie : 8 fichiers dans `OUT_DIR`.

## 3. Vérifier, puis copier à la main

Avant toute copie, **ouvrir les 8 images** et vérifier les règles d'anonymisation fermées
(`.claude/rules/landing.md`) : en-tête **« Démo Basket Club »** (jamais un club réel), **blason
club absent**, **aucune personne réelle** (surnoms de démo admis), données publiques seulement, et
**aucun `ARA0069036`** résiduel. C'est LA surface RGPD du lot.

```bash
# UNIQUEMENT après validation visuelle (GO fondateur sur les 8 captures) :
cp captures/landing-shots/*.png captures/landing-shots/*.jpg landing/assets/
```

La copie remplace les versions claires existantes et ajoute les 4 `*-dark`. Recaler ensuite si
besoin les `alt`/`width`/`height` des `<img>` de `landing/index.html` (hors périmètre de ce
script). Vérifier enfin dans un vrai navigateur : bascule du bouton clair↔sombre (les 4 images
changent), aucun repli `onerror` déclenché (les 4 `-dark` en 200 dans l'onglet réseau).
