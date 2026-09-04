# Amateo — Operational Index

> Canonical agent cheat-sheet. **Règles toujours actives seulement** — le détail vit dans `docs/`,
> les conventions par zone dans `.claude/rules/` (chargées quand la zone est touchée), les
> procédures événementielles dans `.claude/skills/`. Si un fait est évident depuis les noms de
> fichiers, il n'est pas ici ; si c'est du récit (pourquoi, quand, qui a mordu), il vit dans
> `specs/courantes/etat-des-lieux.md` ou le doc canonique — jamais ici.
> Read order: **this file → `docs/project-map.md` → `specs/courantes/`**.

## 1. What this is

Amateo (édité par Maratech — les deux noms sont une **variable, jamais un littéral** :
`App\Service\ProductIdentity` / `shared/lib/product.ts`) generates per-club, per-season training
schedules for basketball clubs (FFBB). A constraint solver (OR-Tools CP-SAT) places teams into
venue time-slots under hard rules + a soft scoring objective. **Backend** orchestrates/persists/
exposes the API, **engine** solves, **frontend** renders (wizard → generate → work-loop).

## 2. Stack & zones

| Zone | Lang / Runtime | Entry point | Role |
|------|----------------|-------------|------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 | `public/index.php` | API, persistence, async orchestration |
| `engine/` | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` | Schedule solver (`POST /generate` · `/place-matches` · `/validate-assignments`) |
| `frontend/` | TS · React 19 · Vite · Tailwind 4 | `src/main.tsx` | UI — auth · planning work-loop · data-entry wizard |
| `landing/` | HTML/CSS statique (zéro build) | `index.html` | Page de vente publique — **hors app**, aucun lien avec `frontend/` ; marque/liens dans `config.js` seul |
| `system-pages/` | HTML/CSS statique (zéro build) | `503.html` | Pages servies **quand l'app est morte** (503 subie + maintenance) — par **Caddy**, hors Docker ; frère de `landing/`, marque jamais en littéral (`.claude/rules/system-pages.md`) |
| `specs/` | Markdown | `specs/README.md` | Living specs (initiales/courantes/evolution) |

**Boundaries (critical — never cross):** `frontend → backend` via `/api/*` · `backend → engine` via
`POST http://engine:8000/generate` · `backend → frontend` via Mercure SSE topic
`club:{clubId}:schedule:{scheduleId}` · **engine is reactive, it NEVER calls the backend** ·
**frontend NEVER calls the engine directly** — et **aucun proxy `/engine` nulle part, ne jamais en
(ré)introduire** (l'ancien exposait le solveur SANS authentification) : pour debugger,
`docker compose exec engine …` fait le travail.

## 3. Key commands

Backend, engine and frontend tooling run **inside Docker** (Makefiles wrap Docker Compose).

```bash
make start | stop | install | test | lint     # root orchestration
make bootstrap              # JWT keypair + create/migrate dev DB — re-run after a pull adds migrations
cd backend && make test     # PHPStan(lvl8) + CS-Fixer + PHPUnit testsuite Unit SEULEMENT (§10.1)
cd backend && make tests-complete             # miroir exact de la CI — à passer AVANT tout push backend
cd backend && make behat    # tests fonctionnels Gherkin FR (API réelle) — `with-sandbox.sh` en mode play
cd engine  && make test     # ruff (+format --check) + mypy + bandit + pytest   |  make format
make -C frontend dev        # Dockerized Vite :5173 (proxies /api, /exports, /.well-known/mercure)
make -C frontend e2e        # Playwright entièrement dockerisé — exige stack + dev lancés
```

## 4. CI — ce qui gate quoi

Graphe des jobs, rôles et pièges : **`docs/testing/testing-strategy.md` §1** (canonique). Qui teste
quoi, par axe, et les angles morts : `docs/testing/test-coverage-map.md`.

- **Bloquant = step NOMMÉ du job `blocking-tests` dans `.github/workflows/ci.yml`** — jamais
  l'annotation `#[Group('phase1')]` (bien plus de fichiers la portent que le job n'a de steps).
  Un test annoté mais non listé tourne dans `unit-tests` : après le gate, sans bloquer
  `build-docker`. « X est bloquant » se vérifie dans `ci.yml`.
- La liste de « quels tests gatent » vit dans **`docs/testing/blocking-tests.md`** — maison
  unique, gardée par `BlockingTestsListMatchesCiTest` (⇄ `ci.yml`, les deux sens). Ce que chaque
  test garde en détail : **son propre docblock**.
- Jobs sans `needs` mais **required checks de `main`** : `rector` (style gate) ·
  `dependency-audit` · `secrets-scan` · `semgrep` · `smoke-tests` (4 smokes sémantiques) ·
  `engine-semantics` (groupe `contract` cross-stack) · `functional-tests` (Behat, Gherkin FR —
  required check à ajouter côté GitHub, comme `engine-semantics`). `build-docker` needs
  **[blocking-tests, engine-tests] only**.

## 5. Conventions (core — détail par zone dans `.claude/rules/`)

- **Backend** : PHPStan lvl 8 · CS-Fixer risky · **Rector PHP 8.4 FAIT convention** (`src/` ET
  `tests/`) · **Symfony LTS 7.4** (dérive → `composer update`, JAMAIS un pin) · PHPUnit 11.
- **Engine** : ruff — **`ruff format` fait convention, gardé en CI** · mypy `strict` · pytest +
  goldens + invariants + hypothesis.

## 6. Critical invariants (le doc pointé fait foi)

- **Multi-tenant, 3 couches** : Doctrine `TenantFilter` + listener **priority 7, APRÈS le
  firewall — ne jamais le remonter** ; **PostgreSQL RLS ACTIVE** ; Club/User scopés dans leurs
  providers ; le listener retourne immédiatement sur `/api/admin/**`. → `backend/docs/TENANT.md`
  + `docs/security/rls.md`.
- **JWT applicatif en cookie httpOnly** — `Secure` piloté par `JWT_COOKIE_SECURE`, **jamais
  `isSecure()`** ; Bearer accepté pour scripts/smokes. → `docs/security/jwt-cookie.md`.
- **Superadmin SA0** : identité globale séparée, firewall stateful `/api/admin/**`, TOTP ; un JWT
  club ne franchit jamais ce firewall, la session admin ne pose jamais `app.club_id`. →
  `specs/courantes/superadmin-auth.md`.
- **Pages publiques à token** (coach-wish, club-approval) : le token EST l'identité, 404
  byte-identique, rate-limit IP ; le contrôleur pose lui-même `app.club_id` (relâché en
  `finally`). → `docs/security/rls.md`.
- **Concurrence** : `ClubGenerationLock` Redis + verrou asyncio par club côté engine ; placement
  matchs = rail **synchrone** avec son propre `MatchPlacementLock` (ADR-0003).
- **Génération async** : controller → Messenger (Redis) → handler (snapshot figé → POST engine →
  import → Mercure). Worker = conteneur `messenger-worker`.
- **Grille de gymnase possédée par la période** (ADR-0002) : slots d'une période = **copie**
  prise à la naissance du plan, jamais d'union avec la saison ; overrides sparse ; pin orphelin
  → 422 (`OrphanPinGuard`).
- **Le socle commande les plans de période** (ADR-0002) : (1) **aucune génération de période sans
  socle EN VIGUEUR** (`SocleGuard`) — « et si le socle n'a pas de version ? » est un cas
  IMPOSSIBLE, jamais un repli à écrire ; (2) **valider/rouvrir le socle DÉTRUIT les plans de
  période FUTURS** (l'entrée de calendrier survit) — ⚠ critère = la **DATE** (`startDate >
  today`), PAS l'avancement : un plan futur DÉJÀ généré est balayé aussi. Corollaire : la grille
  copiée ne peut périmer en silence que pour une période **déjà commencée**.
- **Contrat backend⇄engine** : Pydantic ⇄ payload, `engine/CONTRACT_VERSION` (**2.20**, un seul
  contrat pour `/generate` · `/place-matches` · `/validate-assignments`), **sync manuelle, pas de
  codegen** — gardé par les 3 `*ContractSchemaTest`.
- **FFBB outbound** : hosts hard-codés (SSRF-safe), best-effort, le frontend n'appelle jamais FFBB.
  → `backend/docs/ffbb-api.md`.
- **Solveur** : single-pass, **aucun fallback de relaxation**, budget adaptatif 60/180/600 s,
  INFEASIBLE → `status="failed"` + diagnostics ; un verrou HARD est souverain mais diagnostiqué.
  → `docs/architecture/adr-0001-single-pass-solve.md`.

## 7. Workflow rules (orchestrator)

Agents/skills = **manuels, déclenchés par le user** (exception : hook `code-review-graph`).
⚠ **Les subagents ne reçoivent PAS ce fichier** — leurs définitions leur ordonnent de le lire en
première action. **Git (non négociable)** : **JAMAIS de commit sur `main`** (docs/specs compris) —
branche puis PR ; **JAMAIS de merge sans le GO explicite du user** ; push libre, stop à « PR ready ».

**Deux lanes — choisir AVANT de commencer et le dire** :
- **Full** (défaut : feature, comportement/API/schéma, axe §7.1).
- **Light** (TOUTES : ≤2 fichiers, zéro comportement/API/schéma, zéro axe) : implémenter →
  tests verts → `documentation-update` → PR → GO user.

**Cycle full lane** :
0. **Lire le CODE avant d'analyser** — tout constat vérifié (grep/read/test) et cité
   `fichier:ligne`, jamais de mémoire ni depuis un doc ; jamais « vérifié » sur balayage partiel.
1. **Need validation** : besoin en 3-6 lignes + ambiguïtés + ce que je ne ferai PAS, chaque
   constat adossé au code lu. **User valide — pas de `/plan` avant.**
2. `/plan` (agent `planner`, porte la checklist §9) ; optionnel `contrarian-review`. User valide.
3. Implémenter **strictement dans le scope** (agent `coder`, zéro refactor opportuniste).
4. **NR obligatoire si axe §7.1 touché — même PR.** ⚠ `phase1` ne gate pas (§4) : un NR qui doit
   gater = step `ci.yml` **ET** ligne `docs/testing/blocking-tests.md`, même PR — sinon le dire.
5. **Tests verts en local avant de proposer le merge** : `/validation-runner` (suite ciblée +
   contrats cross-zone + **`make -C backend behat` (feature génération de saison) obligatoire si
   engine/backend touché**, `COMPLETED` attendu).
6. Résumé + **`documentation-update` (avant CHAQUE PR, les deux lanes)** — « rien d'impacté » se
   conclut en regardant, jamais en supposant.
7. **`/code-review` : le fondateur seul le déclenche.** **`/security-review` RESTE systématique**
   si la PR touche auth/données/intégrations externes. Répondre à une revue : skill
   `review-response` (plafond 4 rounds, GO fondateur dès le round 2).
8. PR → **GO explicite du user** → merge.

### 7.1 Structuring axes (liste fermée — NR requis si touché ; l'étendre = décision user)

tenant isolation · generation pipeline (controller→messenger→engine→import→Mercure) ·
**constraint semantics** (une contrainte saisie doit être honorée par le solveur — smoke
sémantique, pas juste COMPLETED) · planning lifecycle (plan SEASON pointé = calendrier ;
valider/rouvrir + verrous — ADR-0002) · **périmètre engagé** (équipe en compétition : ni
suppression ni changement de niveau) · backend↔engine contract (CONTRACT_VERSION) ·
auth & memberships.

## 8. Documentation rules

`CLAUDE.md` = index court ; `docs/` = détail ; **one canonical home, no duplication**. Root
`AGENTS.md` pointe ici ; `<zone>/AGENTS.md` = détail de zone. Mise à jour via le skill
`documentation-update` avant chaque PR. Décisions structurantes → ADR
(`docs/architecture/adr-index.md`).

**Les deux fichiers de suivi — ne jamais les confondre** : `specs/evolution/roadmap.md` =
**l'ouvert seulement** ; `specs/courantes/etat-des-lieux.md` = **le livré + les décisions fermées**.
Un item livré **quitte** la roadmap (jamais en ✅). « Est-ce que X est fait ? » se répond dans
l'état des lieux.

## 9. Scope checklist

La checklist de cadrage (zone, dossiers autorisés/interdits, fichiers probables, doc à mettre à
jour, axes §7.1 → NR, feature Behat `make -C backend behat` si engine/backend) **vit dans
`.claude/agents/planner.md`** — maison unique, c'est l'agent qui l'exécute. Tout plan produit doit
la remplir littéralement.

## 10. Gotchas (top)

1. ⚠ **`make phpunit` = `--group phase1` seul · `make test` = testsuite `Unit` seule** — or la CI
   `unit-tests` lance `phpunit tests/` ENTIER. **Avant de pousser :
   `make -C backend tests-complete`** (miroir CI — détail : `backend/docs/commands.md`). Chaque
   dossier de `tests/` est dans une testsuite (garde `TestsuitesCoverEveryTestDirectoryTest`).
2. `contracts/` et `tests/` racine = placeholders vides (les tests cross-stack vivent dans
   `backend/tests/`).
3. Tenant résolu côté serveur depuis le JWT : le front n'envoie **aucun** header `X-Club-Id`.

**Pointers:** `docs/project-map.md` (**la carte** — zones, ops, sécurité, tout le reste) ·
`docs/glossary.md` · `docs/testing/testing-strategy.md` · `specs/evolution/roadmap.md`
(**l'ouvert**) · `specs/courantes/etat-des-lieux.md` (**le livré**) ·
`docs/architecture/adr-index.md` · `backend/docs/commands.md` · ops : `docs/ops/` · sécurité :
`docs/security/` · clés `config` d'une contrainte : `backend/docs/constraint-config-keys.md`.
