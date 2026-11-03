# Captures d'écran du contrôle visuel (SWITCH)

`capture-screens.mjs` capture une liste d'écrans de l'app réelle, décrite dans un fichier JSON :
un PNG par écran, **seulement une fois l'écran rendu**. C'est l'outil de l'agent SWITCH
(`.claude/agents/switch.md`, étape 4) : il remplace le script Playwright réécrit à chaque passage.

> **Ce n'est pas un test.** Il vit dans `frontend/scripts/` (hors `frontend/tests/e2e/`, le
> `testDir` de `playwright.config.ts`) : il ne tourne **jamais en CI**. Il n'écrit que les PNG du
> dossier de sortie (et, si demandé, un fichier de session). Aucun PNG ne se commite.

## Lancer

```bash
make epreuve-status                         # libre ? sinon attendre
make epreuve-up WT=<worktree>               # stack d'épreuve :8181 (+ Mailpit :18025)
cd frontend && node scripts/capture-screens.mjs <spec.json>
make epreuve-down
```

Chromium vient du `@playwright/test` du projet. Dans un worktree sans `frontend/node_modules`, le
script prend celui du dépôt principal.

## Le fichier JSON

Exemple complet : [`capture-screens.example.json`](capture-screens.example.json).

| Clé | Rôle | Défaut |
|-----|------|--------|
| `baseUrl` | Cible (surchargée par `CAPTURE_BASE_URL`). | `http://localhost:8181` (épreuve) |
| `outDir` | **Requis.** Dossier des PNG ; relatif = depuis la racine du dépôt. | — |
| `auth.mode` | `login` · `register` · `state` · `none` (ci-dessous). | `login` |
| `auth.saveStateTo` | (`login`/`register`) enregistre la session pour la réutiliser (`state`). | aucun |
| `viewport` | `{ "width", "height" }` par défaut. | 1280 × 800 |
| `theme` | `light` ou `dark` par défaut. | `light` |
| `screens[]` | `{ name, path, viewport?, theme?, waitFor?, fullPage? }` | — |

- `name` : nom du fichier (`<name>.png`), lettres/chiffres/`.`/`_`/`-`, unique.
- `path` : route de l'app, commence par `/` (`/club`, `/matchs?semaine=2026-11-14`…).
- `waitFor` : sélecteur Playwright d'un **témoin** qui doit être visible avant la capture
  (`h1`, `text=Conflits`, `[data-slot-id]`…). À donner dès qu'un écran peut s'afficher vide.
- `fullPage` : `true` = page entière plutôt que le seul viewport.

### Connexion

- `login` : formulaire `/login` avec `CAPTURE_EMAIL` / `CAPTURE_PASSWORD` (variables
  d'environnement — **jamais** dans le JSON ni dans le dépôt).
- `register` : crée un compte de test **neuf** sur un club **fictif** (`auth.clubName`, défaut
  « Club Test Capture ») — inscription réelle, mail lu dans Mailpit (`auth.mailpitUrl`, défaut
  `http://localhost:18025`), club approuvé par le relais dev. Même parcours que
  `registerAndVerify` (`tests/e2e/support.ts`). L'email créé est affiché.
- `state` : réutilise une session enregistrée (`auth.file`) — passages suivants sur le même club.
- `none` : sans session (pages publiques).

Jamais un nom de club réel dans un JSON commité : le dépôt est public.

## « Rendu » — ce que l'outil attend

Avant chaque capture, deux constats « prêt » à 400 ms d'écart (le voile de page n'apparaît qu'à
250 ms), dans la limite de 20 s :

- pas de voile `ActionVeil` (`data-testid="action-veil"`, même règle que `settleVeil`) ;
- aucun spinner `aria-label="Chargement"` visible ;
- aucun texte d'attente « Chargement… » (barre de navigation, « Chargement des offres… ») ;
- du texte **visible** à l'écran (un `sr-only` ne compte pas) ;

puis le témoin `waitFor`, puis : pas de renvoi vers `/login`. Jamais `networkidle` (le flux
Mercure ne retombe pas).

## Erreurs nommées

Un écran qui n'est pas rendu n'est **pas** capturé : `✗ <écran> : <raison>` (navigation
impossible, HTTP 4xx/5xx, voile levé, spinner, indicateur de chargement, écran vide, témoin
absent, renvoi vers `/login`). Les autres écrans sont tentés quand même ; le script sort en code 1
avec la liste. Une redirection (ex. `/planning` → `/wizard` sur un club neuf) est capturée et
signalée `(redirigé vers …)`.

## Ce que l'outil ne fait pas

Cliquer, remplir un formulaire, ouvrir une modale, semer des données métier. Pour un tel geste,
SWITCH écrit un script dédié et dit lequel (voir `.claude/agents/switch.md`).
