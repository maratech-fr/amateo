# video/ — video promotionnelle (Remotion)

Projet **isole** a la racine du depot (soeur de `backend/`, `engine/`, `frontend/`, `landing/`),
qui fabrique la video promotionnelle de 60 s par le code. **Zero dependance** avec `frontend/` ou
`landing/` : meme logique d'isolement que `landing/` (cf. `.claude/rules/landing.md`) — on duplique
par convention (couleurs, police, marque), on ne partage aucune brique.

Source de verite du contenu : `business/video_promo/03-prompts.md` (§1 prompt maitre, §4bis
storyboard v2). Ce dossier est **le code**, pas la source de verite editoriale.

> **Etat (ce run) : etapes 1 et 2 seulement** — squelette de composition + capture + planche
> contact. **On s'arrete a la planche contact** : les noms visibles doivent etre valides AVANT tout
> montage (regle 2). Pas de musique, pas de bruitages (SFX), pas de montage final dans ce run.

## Les 3 commandes

```bash
npm install          # dependances (Remotion, React, Playwright) — une fois
npm run capture      # (1) CAPTURE : pilote l'app de demo + exporte le logo -> out/ (voir « Capture »)
npm run studio       # (2) RELECTURE : Remotion Studio dans le navigateur (frise, scrub)
npm run render       # (3) RENDU : exporte la video -> out/amateo-promo-60s.mp4
```

Commandes d'appoint :

```bash
npm run logo         # exporte SEULEMENT les images du logo (hors ligne, pas besoin du bac a sable)
npm run vtt          # genere out/amateo-promo.fr.vtt depuis les sous-titres
npm run still        # rend une image fixe de controle (plan 1) -> out/stills/plan01.png
npm run typecheck    # tsc --noEmit
```

## Structure

```
video/
  package.json  tsconfig.json  remotion.config.ts  .gitignore  README.md
  public/
    fonts/  bricolage-latin.woff2      # police d'affichage (copie, voir « Provenance »)
    logo/   amateo-motion-02-creneaux.html   # animation du logo (copie, voir « Provenance »)
  src/
    brand.ts      # marque en VARIABLES (miroir de landing/config.js) + palette + polices
    timing.ts     # BPM=120, FPS=60, 3600 images ; frontieres de plan CALCULEES (jamais en dur)
    script.ts     # les 12 plans + textes a l'ecran (mot pour mot du §4bis) + sous-titres
    fonts.ts      # chargement de la police locale (delayRender)
    Root.tsx index.ts        # composition « AmateoPromo » 1920x1080 @ 60 i/s, 60,0 s
    AmateoPromo.tsx Scene.tsx Subtitles.tsx BrandMark.tsx
    vtt.ts        # generateur WebVTT (meme source que les sous-titres incrustes)
  scripts/make-vtt.ts
  capture/        # scripts Playwright (voir « Capture »)
  out/            # TOUT ce qui se regenere (gitignore) : captures, images du logo, rendus, vtt, planche contact
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
backend/scripts/with-sandbox.sh bash video/capture/run.sh
```

Ce qu'il fait, dans l'ordre :

1. **Garde** : abandonne si la base reellement visee n'est pas `amateo_dev` (jamais d'ecriture sur
   la base de jeu du fondateur).
2. **Reconstruction demo** : `db-empty` puis `seed-demo` + `seed-holidays`, puis redemarrage du
   moteur et du worker. (Jamais `seed-demo` et `seed-bccl` dans la meme base.)
3. **Etat a filmer** (idempotent, cree par la capture) : periode de vacances + campagne de
   doleances de coachs + reponses publiques + mutualisation.
4. **Captures** : `capture-app.cjs` (ecrans de l'app, clip pour ce qui bouge, PNG HD pour un fond
   de zoom), `capture-logo.cjs` (images du logo), `make-noms-visibles.sh`, `contact-sheet.cjs`.
5. **Restauration CI, meme en cas d'echec** (trap) : `db-empty` + `seed-bccl` + `seed-holidays` —
   le bac a sable est laisse dans l'etat de la CI (BCCL seul) pour que Behat reste vert.

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
- `public/logo/amateo-motion-02-creneaux.html` : copie de
  `business/7-marque/motion-design/amateo-motion-02-creneaux.html`. `business/` est hors depot
  (gitignore) ; la copie vit ici pour que l'animation du logo soit regenerable depuis le depot
  (regle 4). Le logo n'est jamais redessine.

## Prerequis techniques

Node 20+ (teste sur 24.x), npm. `npm install` ne telecharge pas de navigateur (Playwright reutilise
le Chromium deja present dans `~/.cache/ms-playwright`). Le rendu Remotion utilise un Chromium
headless : en l'absence de telechargement reseau, pointer sur le cache via `--browser-executable`
(variable `PW_CHROME` pour les scripts de capture).
