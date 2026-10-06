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
- **tous les blasons du club** sont retirés, où qu'ils soient (en-tête d'app, en-tête d'écran du
  Planning à côté du titre, page Club…) — ciblés par leur SOURCE `/api/clubs/{id}/logo` ; le logo
  fédéral d'un **adversaire** (`/api/opponents/{code}/logo`) est public et CONSERVÉ. L'app retombe
  sur le monogramme produit neutre là où un blason club a été retiré (patron P5-26) ;
- l'**horloge simulée DEV** de l'en-tête (badge « 06/10/2026 23:32 » à côté de « BÊTA », `DevClock`)
  est retirée du DOM — du chrome de développement qui n'a pas sa place sur une capture de vente ;
- `SCRUB_FILE` (optionnel, local, jamais commité) **complète/surcharge** cette table pour un nom
  supplémentaire repéré sur une capture.

Coachs = surnoms fictifs du seed. Gymnases et clubs adverses = données publiques autorisées.

## Ce qu'il produit

| Écran | Route | Fichier clair | Fichier sombre | Dimensions (viewport) |
|-------|-------|---------------|----------------|-----------------------|
| Planning généré | `/planning` | `planning.png` | `planning-dark.png` | 1080 × 608 |
| Calendrier matchs (week-end) | `/matchs?semaine=…` | `matchs.jpg` | `matchs-dark.jpg` | 1080 × 900 |
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
| `FORCE_IMPORT` | `1` ⇒ réimporte même si la saison a déjà des rencontres (sinon la garde d'idempotence saute l'import). | aucun |
| `DIVISION_MAP` | Objet JSON inline `{ "Division": "Nom d'équipe" }` **fusionné** par-dessus l'appariement BCCL par défaut. | appariement fondateur BCCL (ci-dessous) |
| `DIVISION_MAP_FILE` | Chemin d'un JSON local `{ "Division": "Nom d'équipe" }` **fusionné** par-dessus le défaut (avant `DIVISION_MAP`). | aucun |
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
applique les **préparations propres à l'écran** (ci-dessous), **anonymise le DOM** (remplacements
de texte + tous les blasons club retirés), et capture.

Préparations par écran :

- **Planning** : ouvre d'abord la **dernière version** si le bandeau « version antérieure » l'offre
  (sélection locale, aucune écriture), **masque** à l'écran les bandeaux d'état restants (« périmé »,
  « version antérieure » — décision 2026-10-06, capture marketing : on retire par leur texte les
  nœuds `NoticeBanner`, pas tout le DOM), **retire la pastille** « Diagnostics du système (N) ·
  M erreurs » (signal négatif sur une page de vente), puis **fait défiler la grille** jusqu'aux
  heures du soir (elle démarre à 09:00 et serait vide dans le cadre).
- **Calendrier matchs** : **avance** jusqu'à un week-end dont la grille porte **plusieurs cartes de
  match** (≥ 3 `[data-fixture-id]` — on juge sur les cartes rendues, pas sur la seule présence du
  conteneur : une semaine 100 % extérieurs ou sans domicile placé resterait un cadre vide) à partir
  de `MATCHS_WEEKEND`, via « Semaine suivante » (borné à 12 itérations) ; faute d'en trouver une à
  ≥ 3 cartes, **repli sur la mieux remplie vue** (retour en arrière via « Semaine précédente »,
  avertissement loggé). Puis **masque** les deux bandeaux d'état empilés au-dessus de la grille
  (« Depuis votre dernière visite : … » et « … restent à traiter (ni heure ni gymnase… ») — même
  technique que le Planning. (Le masquage a lieu **après** la navigation, qui re-rend React.) Le
  viewport (1080 × **900**) fait entrer la grille et ses matchs **sans** masquer les filtres.
- **Conflits** : si la saison n'a **aucun conflit**, la capture est laissée en l'état mais un
  **avertissement** est loggé ; sinon le **premier groupe** (accordéon) est **déplié** de façon
  robuste — idempotent (un groupe déjà ouvert n'est pas cliqué). L'état ouvert est porté par
  `?ouvert=<clé>` dans l'URL ; comme l'effet de re-synchro de la page peut faire **retomber** cette
  clé juste après le clic (groupe refermé), le script **re-tente** le clic (borné) après
  stabilisation, puis, en dernier recours, **recharge** la route avec `&ouvert=<clé>` (capturée à
  l'écriture de l'historique) — à la navigation la clé est préservée et le groupe s'ouvre d'office.
  Si le groupe reste malgré tout replié, un **avertissement bruyant** est loggé.

Si `IMPORT_FBI` est fourni, l'import FBI passe **une seule fois** avant la boucle, via l'UI
`/matchs/importer` (dépôt du fichier → appariement Division→équipe **explicite** → « Importer »).
Le bac à sable n'ayant pas les engagements FFBB, l'écran ne **propose aucune** équipe
(`suggestedTeamId: null` partout) : sans appariement, l'import ne crée **aucune** rencontre. Le
script pose donc, avant de cliquer « Importer », l'appariement validé par le fondateur pour le
BCCL (surchargeable par `DIVISION_MAP`/`DIVISION_MAP_FILE`) :

```json
{ "PNM": "SM1", "RM2": "SM2", "PRM": "SM3", "DM2": "SM4",
  "PNF": "SF1", "RF3": "SF2", "DF2": "SF3", "RMU21": "U21M1" }
```

Le nom à droite est le **libellé d'équipe du club** tel qu'il s'affiche dans le sélecteur
« Associer à… » (c.-à-d. `team.name` du seed). Pour chaque division visible : dans la table ⇒
l'équipe est sélectionnée (le script déplie chaque onglet de famille — Départemental / Régional /
Brassage… — et ouvre le sélecteur) ; hors table ⇒ laissée non associée (ses rencontres sont
ignorées, avec la confirmation « Importer quand même » gérée). Le script journalise les divisions
associées / ignorées / sans équipe correspondante, et **avertit (`⚠`)** si le rapport d'import ne
fait état d'**aucune rencontre créée**. **Idempotent sur la PRÉSENCE de rencontres** (plus sur la
date du dernier dépôt FBI) : si la saison a déjà des rencontres (l'état vide « Aucun match
importé » du calendrier est absent), l'import est sauté — `FORCE_IMPORT=1` passe outre.

**Appariement des salles** (après l'import, idempotent) : les rencontres FBI portent un **libellé
de salle** qui, tant qu'il n'est pas apparié à un gymnase du club, laisse les domiciles **sans
`venueId`** — invisibles sur la grille du Calendrier (bandeau « N libellés de salle non appariés —
M domiciles n'apparaissent pas »). Le script lit l'inventaire `GET /api/venues/fbi-labels`, et pour
chaque libellé **non apparié** `POST /api/venues/{venueId}/external-labels` `{ label }` (cookies de
la page connectée via `page.request` — JWT httpOnly, aucune en-tête spéciale) vers le gymnase
`suggestedVenueId` quand le serveur en propose un, sinon le **premier gymnase** du club. Il
journalise chaque appariement et **ne fait rien** si tout est déjà apparié. Sortie : 8 fichiers dans
`OUT_DIR`.

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
