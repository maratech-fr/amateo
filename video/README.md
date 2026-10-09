# video/ — video promotionnelle (Remotion)

Projet **isole** a la racine du depot (soeur de `backend/`, `engine/`, `frontend/`, `landing/`),
qui fabrique la video promotionnelle de 60 s par le code. **Zero dependance** avec `frontend/` ou
`landing/` : meme logique d'isolement que `landing/` (cf. `.claude/rules/landing.md`) — on duplique
par convention (couleurs, police, marque), on ne partage aucune brique.

Source de verite du contenu : `business/video_promo/03-prompts.md` (§1 prompt maitre, §4bis
storyboard v2). Ce dossier est **le code**, pas la source de verite editoriale.

> **Etat (ce run) : etape 3 — le BROUILLON MONTE.** Les 12 plans sont montes (captures reelles +
> motion design + animation du logo), sous-titres incrustes + VTT, et une **piste de clics** au
> tempo (120 BPM, silence 0:36-0:38) pour juger le rythme. **Ni musique ni bruitages (SFX)
> telecharges** dans ce run (poses a l'etape 4 ; gains + mapping SFX par plan DEJA en donnees dans
> `src/audio-levels.ts`). Livrables : `out/stills/planNN.png` (1 image/plan), `out/stills-sheet.png`
> (planche des 12) et `out/draft-720p.mp4` (60,0 s, 1280x720, avec la piste de clics). Le fondateur
> commente le brouillon plan par plan (validation n°2) avant l'etape 4.

## Les commandes

```bash
npm install          # dependances (Remotion, React, Playwright) — une fois
npm run capture      # CAPTURE : pilote l'app de demo (bac a sable) -> out/captures/ (voir « Capture »)
npm run logo         # exporte les images du logo (orbite + creneaux) -> out/logo/ (hors ligne)
npm run studio       # RELECTURE : Remotion Studio dans le navigateur (frise, scrub)
npm run draft        # BROUILLON 720p (avec piste de clics) -> out/draft-720p.mp4
npm run stills       # 12 images (une par plan) + planche -> out/stills/ + out/stills-sheet.png
npm run render       # RENDU plein format -> out/amateo-promo-60s.mp4 (etape 4)
```

Commandes d'appoint :

```bash
npm run stage-media  # (re)cree le pont public/media -> ../out + genere la piste de clics
npm run click        # (re)genere SEULEMENT la piste de clics -> out/audio/click-120.wav
npm run vtt          # genere out/amateo-promo.fr.vtt depuis les sous-titres
npm run still        # rend une image fixe de controle (plan 1) -> out/stills/plan01.png
npm run typecheck    # tsc --noEmit
```

> **Medias & Remotion** : tout ce qui se regenere (captures, images du logo, piste audio) vit dans
> `out/` (gitignore). Un lien symbolique `public/media -> ../out` (cree par `stage-media`, inclus
> dans `draft`/`stills`/`render`) les expose a `staticFile` — Remotion sert `public/`. Chromium hors
> ligne : passer `--browser-executable=$PW_CHROME` (binaire du cache Playwright) aux rendus.

## Choix du logo (plans 3 et 12)

`src/logo.ts` porte `LOGO_ANIMATION = "orbite" | "creneaux"` (**defaut « orbite »**, decision
fondateur 2026-10-09). Les deux animations existantes du graphiste sont exportees image par image
par `npm run logo` ; le plan joue l'INTRO (0 -> 1,5 s) puis TIENT le logo complet (pas de sortie qui
vide l'ecran). « orbite » = **fond nuit** (coupure nette sur l'impact 0:10 / l'appel final 0:54,
contraste assume) ; « creneaux » = fond papier. Pour basculer : changer la seule constante.

## Structure

```
video/
  package.json  tsconfig.json  remotion.config.ts  .gitignore  README.md
  public/
    fonts/  bricolage-latin.woff2               # police d'affichage (copie, voir « Provenance »)
    logo/   amateo-motion-01-orbite.html        # animation du logo « Orbite » (copie, voir « Provenance »)
            amateo-motion-02-creneaux.html      # animation du logo « Creneaux » (copie)
    media/  -> ../out (lien, gitignore)          # pont pour staticFile (cree par stage-media)
  src/
    brand.ts      # marque en VARIABLES (miroir de landing/config.js) + palette + polices
    logo.ts       # LOGO_ANIMATION (orbite|creneaux), fond nuit, nb d'images d'intro
    media.ts      # chemins staticFile des medias regenerables (captures, logo, audio) via public/media
    timing.ts     # BPM=120, FPS=60, 3600 images ; frontieres de plan CALCULEES (jamais en dur)
    script.ts     # les 12 plans + textes a l'ecran (mot pour mot du §4bis) + sous-titres
    audio-levels.ts  # gains musique/SFX + mapping SFX par plan (§4bis) + piste de clics (DONNEES)
    fonts.ts      # chargement de la police locale (delayRender)
    Root.tsx index.ts        # composition « AmateoPromo » 1920x1080 @ 60 i/s, 60,0 s
    AmateoPromo.tsx Scene.tsx Subtitles.tsx BrandMark.tsx
    scenes/       # un composant par type de plan : Still (zoom/annotations), LogoScene, MotionCharge,
                  # FranceMap, ThemeSwitch, GenerationCascade, MoveLockClip, Plan05Wishes, Plan09Matches
    vtt.ts        # generateur WebVTT (meme source que les sous-titres incrustes)
  scripts/      # make-vtt.ts · make-click-track.cjs · stage-media.cjs · render-stills.cjs
  capture/        # scripts Playwright (voir « Capture »)
  out/            # TOUT ce qui se regenere (gitignore) : captures, images du logo, audio, rendus, vtt, stills, planche
```

## Regles tenues (prompt maitre)

- **Marque en variables** (`src/brand.ts`, regle 3) : le nom du produit, l'URL du site et le
  contact vivent la, et **nulle part ailleurs** — ni dans le script, ni dans les composants, ni
  dans ce README. Palette et polices y sont aussi (les trois arcs du logo, les jetons de la vitrine).
- **Logo jamais redessine** (regle 4) : les plans d'ouverture/fermeture viennent de l'animation
  existante, exportee image par image (`npm run logo`).
- **Textes = une seule source** (`src/script.ts`, regle 6) : sous-titres incrustes ET WebVTT en
  sortent. Au plus 2 lignes, police d'affichage, encre sur papier (contraste eleve), zone de
  securite 96 px. Le plan 8 (bascule de theme) n'a aucun texte (respiration).
- **Tempo** (regle 5) : 1 mesure (120 images) = 2 s a 120 BPM ; chaque frontiere de plan est
  **calculee** depuis les mesures (`src/timing.ts`), jamais un numero d'image en dur.
- **Format** : 1920x1080, 60 i/s, 60,0 s (3600 images).

## Capture (club de DEMONSTRATION uniquement)

On filme **le club de demonstration** (« Demo Basket Club », identites FICTIVES) — jamais un club
reel. Prerequis : la stack dev du depot doit tourner (`make start`), front sur `:5173`, API sur
`:8080`, Mailpit sur `:8025`, et le Chromium de Playwright present dans `~/.cache/ms-playwright`.

Le lanceur `capture/run.sh` **possede le cycle complet** et doit etre lance SOUS le wrapper bac a
sable (UNE seule bascule de toute la stack vers `amateo_dev`) :

```bash
backend/scripts/with-sandbox.sh bash video/capture/run.sh          # run COMPLET
PLANS="P04 P07" backend/scripts/with-sandbox.sh bash video/capture/run.sh   # filtre : SEULEMENT ces plans
```

Ce qu'il fait, dans l'ordre :

1. **Garde** : abandonne si la base reellement visee n'est pas `amateo_dev` (jamais d'ecriture sur
   la base de jeu du fondateur).
2. **Reconstruction demo** : arret du worker + terminaison des sessions, `db-empty`, puis
   `seed-demo` + `seed-holidays`, puis redemarrage moteur/worker. (Jamais `seed-demo` et
   `seed-bccl` dans la meme base.)
3. **Etat a filmer** (idempotent, cree par la capture) : periode de vacances + campagne de
   doleances + reponses publiques + mutualisation (P05) ; membre lecture seule (P10) ; **creneau
   de secours** pour que le plan plein laisse une case vide a viser (P07, cf. plus bas).
4. **Captures** : `capture-app.cjs` (ecrans de l'app ; clip pour ce qui bouge, PNG HD pour un fond
   de zoom), `capture-logo.cjs` (images du logo), `make-noms-visibles.sh`, `contact-sheet.cjs`.
5. **Restauration CI, meme en cas d'echec** (trap) : arret worker + terminaison des sessions +
   `db-empty` + `seed-bccl` + `seed-holidays` — le bac a sable est laisse dans l'etat de la CI
   (BCCL seul) pour que Behat reste vert.

**Filtre de plans** (`PLANS="P04 P07"`) : ne (re)capture QUE ces plans + leurs prerequis — P07 et
P04 exigent un planning genere (P06), donc la generation tourne ; P04 (contraintes EDITABLES) se
filme APRES generation et AVANT validation (seul etat atteignable+editable) ; la mise en scene
inutile (doleances/membre), le logo, `noms-visibles.md` et la planche contact ne sont PAS
regeneres (deja valides). Sans filtre = run complet.

**Pieges de capture resolus** (2026-10-09) : le club demo est genere PILE a capacite (81/81) → zero
case vide → on ajoute **un creneau de secours** avant la generation pour que P07 ait une case
« Placer ici » (le deplacement passe sous le verdict du moteur : on prend une seance DEVERROUILLEE
et NON reservee, et on reessaie si le moteur refuse). La bascule de theme (P08) est filmee en
thème clair → sombre ; `noms-visibles.md` liste tout nom affiche (validation n°1, regle 2).

Connexion : un jeton JWT est **fabrique a l'execution** (`lexik:jwt:generate-token`) pour le
gestionnaire de demonstration (parametre backend `app.demo_bccl_email`) — aucun mot de passe ni
secret ecrit dans un fichier. Le compte de demo est garde par une fenetre d'activation
(`UserChecker`) **au login seulement** ; un JWT console utilise en cookie Bearer sur `/api` n'y est
pas soumis (le pare-feu `^/api` n'a pas de `user_checker`).

Sorties dans `out/` (gitignore) : `captures/` (nommees par plan : `P04-…`, `P05a-…`…), `logo/`,
`contact-sheet.png`, `noms-visibles.md`, `diag.json`.

### A valider AVANT montage

`out/contact-sheet.png` (copie dans `captures/video-contact-sheet.png` a la racine) + 
`out/noms-visibles.md` : le fondateur confirme qu'aucun nom reel n'apparait. On ne monte rien avant.

## Licence Remotion

Remotion est **gratuit** pour « a for-profit organization with up to 3 employees », les individus,
les associations et l'evaluation (fichier `LICENSE.md` du depot Remotion). L'editeur du produit est
une **micro-entreprise d'une seule personne** : il releve donc de la licence gratuite, aucune
licence payante requise a cette taille. Au-dela de 3 salaries, une **Company License** devient
necessaire (~25 $/mois/poste). A reverifier au moment d'une diffusion ou d'une croissance :
<https://www.remotion.dev/docs/license> et <https://www.remotion.pro/faq>.

## Provenance des fichiers copies (regenerables depuis le depot)

- `public/fonts/bricolage-latin.woff2` : copie de `landing/assets/fonts/bricolage-latin.woff2`
  (police **Bricolage Grotesque**, licence OFL — `OFL-bricolage-grotesque.txt` a cote). Copiee
  (pas importee) pour garder `video/` autonome et le rendu hors ligne.
- `public/logo/amateo-motion-01-orbite.html` : copie de
  `business/7-marque/motion-design/amateo-motion-01-orbite.html` (animation **« 01 · Orbite »**,
  fond nuit — celle choisie pour les plans 3 et 12, decision fondateur 2026-10-09). Meme moteur et
  meme API d'export (`?export`, `window.__ready`, `window.__seek(t)`, `window.__film`) que la 02.
- `public/logo/amateo-motion-02-creneaux.html` : copie de
  `business/7-marque/motion-design/amateo-motion-02-creneaux.html` (animation **« 02 · Creneaux »**,
  fond papier — variante gardee derriere `LOGO_ANIMATION`). `business/` est hors depot (gitignore) ;
  les copies vivent ici pour que les animations du logo soient regenerables depuis le depot
  (regle 4). Le logo n'est **jamais** redessine — on exporte l'animation existante du graphiste.
- La silhouette de France du plan 11 (`src/scenes/FranceMap.tsx`) est un contour metropolitain
  SIMPLIFIE, saisi a la main depuis des points geographiques PUBLICS (longitude/latitude de reperes
  cotiers/frontaliers) — pas un SVG tiers ; equivalent a un derive simplifie de Natural Earth
  (domaine public). Provenance detaillee en tete du fichier.

## Prerequis techniques

Node 20+ (teste sur 24.x), npm. `npm install` ne telecharge pas de navigateur (Playwright reutilise
le Chromium deja present dans `~/.cache/ms-playwright`). Le rendu Remotion utilise un Chromium
headless : en l'absence de telechargement reseau, pointer sur le cache via `--browser-executable`
(variable `PW_CHROME` pour les scripts de capture).
