# ClubScheduler — Frontend

> Interface web **React 19 · Vite · Tailwind 4**. Trois modes : authentification, **assistant de saisie** (wizard) et **boucle de travail** sur le planning.

## Rôle & périmètre

Le frontend est l'UI de la plateforme. Un gestionnaire de club y **saisit ses données** (équipes, gymnases, coachs, contraintes), **génère** un planning, puis l'**ajuste et régénère**. Servi en statique par Nginx en prod ; en dev, le serveur Vite tourne dans Docker.

**Frontières (à ne jamais franchir) :**
- Parle **uniquement** au backend via `/api/*`. **Ne contacte jamais l'engine directement** (la génération passe par `POST /api/schedules/{id}/generate`, le backend appelle l'engine).
- **N'envoie aucun header `X-Club-Id`** : le tenant est résolu côté serveur depuis le JWT (voir [`backend/docs/TENANT.md`](../backend/docs/TENANT.md)). Il envoie en revanche `X-Season-Id` **quand une saison est explicitement sélectionnée** (`seasonStore`) ; absent = saison courante dérivée côté serveur. Le serveur le valide dans tous les cas.
- URIs API en **`snake_case`** (`/api/team_coaches`, `/api/venue_training_slots`, `/api/priority_tiers`…).

## Commandes principales

```bash
# Aucun Node/npm requis sur l'hôte
make dev               # Vite Docker, http://localhost:5173
make build             # image frontend de production (tsc + Vite + Nginx)
make lint              # ESLint + TypeScript dans Docker
make test              # Vitest dans Docker (inclut lint + typecheck)
make coverage          # couverture + cliquet (suite complète instrumentée, ~4-5 min — hors boucle courte)
make exec              # shell dans l'image de tooling Node

# Les e2e Playwright restent pilotés par la CI.
```

## Recap projet

- **Entry point** : `src/main.tsx` → `src/app/` (`router.tsx`, `AppLayout.tsx`, `AuthGuard.tsx`).
- **Une feature = un dossier** `src/features/<x>/` avec `{api,queries,store}.ts` + ses composants. Features livrées :
  - **`auth`** — login / register (club ARA, statut pending/active) / `/me`. Token dans un store Zustand ; `AuthGuard` redirige (pas de token → `/login`, onboarding non terminé → `/wizard`).
  - **`planning`** — **boucle de travail** : `WeekGrid` (grille semaine par gymnase / coach / équipe), `PlanningToolbar` (sélecteur des **versions** du plan · Valider / Rouvrir · Régénérer · Charger une version · supprimer une version de travail), `ResourceFilter`, `SlotDetail` (lock/déplacer un créneau), `DiagnosticsPanel`, `ExportMenu` (export PDF, tous gymnases ou un seul). **ADR-0002 : le pointeur du plan se déplace en validant, et par rien d'autre** — il n'existe aucune action « définir principal ».
  - **`wizard`** — **saisie en 6 étapes** (`lib/steps.ts`) : Équipes → Gymnases → Coachs → Contraintes → Récapitulatif → **Génération**. Sauvegarde **au fil de l'eau, par entité** (POST/PUT/DELETE immédiats, mutations TanStack) — **pas** de draft-blob.
  - **`club`** — écran **Gestion du club** (`/club`) : **identité visuelle** (couleur d'accent + logo, cropper cercle zoom/cadrage, extraction 3 couleurs, `--accent` global AA via `shared/hooks/useApplyClubTheme`) **+ section « Demandes »** (approbation des adhésions `pending`, admin).
  - **`cockpit`** — **accueil temporel** (`/`) : bandeau planning socle (ouvrir/modifier/tous les plannings), calendrier mensuel des exceptions, radar d'overlays période/événement. Débloqué (sticky) dès `me.socleValidatedAt`.
  - **`matches`** — **module matchs**, deux routes sous `MatchesLayout` : `/matchs` (**la boucle guidée**, RMM-1) — un rail à 5 étapes DÉRIVÉES (jamais stockées) mène l'import FBI → le placement domicile (grille week-end, panneau **permanent** avec état vide, mode échange visible sur la grille + Échap) → le radar de conflits → **la vue de saisie FBI** (`FbiEntryList`, groupée par équipe, geste de masse borné au filtre affiché) ; `/matchs/configuration` (le SET-UP rare) — Engagements FFBB, Accès match, Habitudes & passerelles, image A/B. Détail métier complet : [`specs/courantes/module-matchs.md`](../specs/courantes/module-matchs.md).
  - **`season-transition`** — sélecteur de saison + bandeau d'anticipation de bascule (pivot 15 juillet), `transitionUiStore`.
  - **`coach-wishes`** — **doléances des coachs** (#10) : modale todo-list (équipe × semaine, coche « traité »), campagne **« Solliciter les coachs »** (liens personnels tokenisés, envoi par email, relance des silencieux), **page publique SANS login `/doleances/{token}`** (le coach saisit ses souhaits, seules les sections modifiées partent), badge radar « X/Y coachs ont répondu · N à traiter ». Contrat : [`specs/courantes/types-de-planning.md`](../specs/courantes/types-de-planning.md) §E5.
  - **`admin`** — **console superadmin** (`/admin`, `/admin/login`) : `AdminGuard` + `AdminShell`, santé des services et conteneurs, dépendances externes, journaux (audit · messenger failed · erreurs système). **Identité globale séparée** — client HTTP dédié (`adminApi`, préfixe `/api/admin`, cookie de session) qui ne lit jamais le store JWT club. Contrat : [`specs/courantes/superadmin-auth.md`](../specs/courantes/superadmin-auth.md).
  - **`legal`** — `/confidentialite` (politique de confidentialité, atteignable depuis le menu compte).
- **Partagé** : `src/shared/api/client.ts` (client **ky**, injecte le Bearer, **aucun** header tenant, 401 → logout), `shared/api/collection.ts` (unwrap JSON-LD `{member:[…]}` + pagination), `shared/components/ui/` (primitives).
- **Stack serveur-état/état-client** : TanStack Query 5 (serveur) + Zustand 5 (client). Statut de génération : **poll** du schedule (+ Mercure SSE côté backend).

## Points structurants (à comprendre avant de coder)

- **Deux modes, même app** : le **wizard** alimente le solveur (et, à l'étape Génération, affiche le planning inline une fois `COMPLETED`) ; la **boucle de travail** (`planning`) ajuste/verrouille/régénère un planning existant. Détail canonique : [`docs/frontend-wizard.md`](docs/frontend-wizard.md).
- **Onboarding guidé vs libre** : selon `me.club.onboardingCompleted` (nav verrouillée vers l'avant pour un club neuf ; reprise sur la 1re étape incomplète).
- **Contraintes ciblées par groupe** : l'écran Contraintes pose une contrainte CLUB + `config.targetTag` (ex. `JEUNE`) que le backend éclate en N contraintes d'équipe.
- **Bundle découpé par route** : toutes les routes sont en `lazy` react-router **sauf** `/login` et les gardes (`app/router.tsx`). Trois filets rendent ce découpage sûr et **aucun n'est optionnel** quand on ajoute une route — `errorElement` (sinon un chunk 404 remplace toute l'app par l'écran anglais du router, invisible de Sentry), `HydrateFallback` (sinon page blanche au F5 d'une route lazy) et un indicateur d'attente `useNavigation` (sinon un clic ne produit aucun retour).
- **Rendre un échec de lecture lisible** : `shared/lib/readState.ts` tranche « chargement / échec sans rien en cache / donnée exploitable ». Un `data ?? []` pendant le premier chargement fabrique un **vide crédible** qui fait re-saisir des données ou valider une période qu'on croit vide — ce piège a sa propre garde, utilisez-la.

## Pour aller plus loin (docs structurantes)

| Doc | Contenu |
|-----|---------|
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent : frontières, arborescence réelle, découpage lazy et ses trois filets, pièges (`tsc --noEmit`, `error.data`), a11y bloquante, primitives partagées, gotchas. |
| [`docs/constraint-emission.md`](docs/constraint-emission.md) | Ce que le wizard émet réellement + alignement 3 couches (frontend → backend → engine). |
| [`specs/courantes/superadmin-auth.md`](../specs/courantes/superadmin-auth.md) · [`types-de-planning.md`](../specs/courantes/types-de-planning.md) | Console superadmin (`/admin`) · doléances coachs (#10, dont `/doleances/{token}`). |
| [`docs/frontend-wizard.md`](docs/frontend-wizard.md) | Flux réel du wizard (6 étapes) + principes (save par entité, modes, reprise). |
| [`docs/frontend-spec.md`](docs/frontend-spec.md) · [`frontend-strategy.md`](docs/frontend-strategy.md) | Architecture (routes, state), stack figée, anti-patterns, mandat TDD. |
| [`docs/frontend-components.md`](docs/frontend-components.md) | Contrat pages/composants. |

## Stack

- **React** 19 · **TypeScript** ~6 · **Vite** · **Tailwind CSS** 4
- **Data** : TanStack Query 5 · **State** : Zustand 5 · **HTTP** : ky 2
- **Tests** : Vitest + Testing Library (unit/intégration) · Playwright (e2e)
- **Temps réel** : Mercure (SSE) via le backend
- **Ports** : 5173 (dev Vite) · 8081 (Nginx prod, sert `dist/`)
