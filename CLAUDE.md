# Amateo — Operational Index

> Canonical agent cheat-sheet. **Règles toujours actives seulement** — le détail vit dans `docs/`,
> les conventions par zone dans `.claude/rules/` (chargées quand la zone est touchée), les
> procédures événementielles dans `.claude/skills/`. Si un fait est évident depuis les noms de
> fichiers, il n'est pas ici ; si c'est du récit (pourquoi, quand, qui a mordu), il vit dans
> `specs/courantes/etat-des-lieux.md` ou le doc canonique — jamais ici.
> Read order: **this file → `docs/project-map.md` → `specs/courantes/`**.

## 1. What this is

Amateo (edited by Maratech — product/publisher name is a single variable, never a literal:
`App\Service\ProductIdentity` backend, `shared/lib/product.ts` frontend, P5-15) generates
per-club, per-season training schedules for basketball clubs (FFBB).
A constraint solver (OR-Tools CP-SAT) places teams into venue time-slots under hard rules + a soft
scoring objective. **Backend** orchestrates/persists/exposes the API, **engine** solves,
**frontend** renders (wizard to enter data → generate → work-loop to adjust/regenerate).

## 2. Stack & zones

| Zone | Lang / Runtime | Entry point | Role |
|------|----------------|-------------|------|
| `backend/` | PHP 8.4 · Symfony 7.4 · API Platform 4.3 · Doctrine ORM 3.6 | `public/index.php` | API, persistence, async orchestration |
| `engine/` | Python 3.12 · FastAPI · OR-Tools CP-SAT | `app/main.py` | Schedule solver (`POST /generate`, `POST /place-matches`) |
| `frontend/` | TS · React 19 · Vite · Tailwind 4 | `src/main.tsx` | UI — auth · planning work-loop · data-entry wizard |
| `landing/` | HTML/CSS statique (zéro build) | `index.html` | Page de vente publique — **hors app**, aucun lien avec `frontend/` ; marque/liens dans `config.js` seul |
| `system-pages/` | HTML/CSS statique (zéro build) | `503.html` | Pages servies **quand l'app est morte** (503 subie + maintenance) — par **Caddy**, hors Docker ; frère de `landing/`, marque jamais en littéral (`.claude/rules/system-pages.md`) |
| `specs/` | Markdown | `specs/README.md` | Living specs (initiales/courantes/evolution) |

**Boundaries (critical — never cross):** `frontend → backend` via `/api/*` · `backend → engine` via
`POST http://engine:8000/generate` · `backend → frontend` via Mercure SSE topic
`club:{clubId}:schedule:{scheduleId}` · **engine is reactive, it NEVER calls the backend** ·
**frontend NEVER calls the engine directly** — et **aucun proxy `/engine` n'existe nulle part** :
celui du nginx DEV a été SUPPRIMÉ le 2026-07-31 parce qu'il exposait le solveur **sans
authentification** à quiconque atteignait le port 8081, tunnel de démo compris. Ne pas le rétablir
« pour debugger » : `docker compose exec engine …` fait le travail (`docker/frontend/nginx.conf:96-102`).

## 3. Key commands

Backend, engine and frontend tooling run **inside Docker** (Makefiles wrap Docker Compose).

```bash
make start | stop | install | test | lint     # root orchestration
make bootstrap              # JWT keypair + create/migrate dev DB — re-run after a pull adds migrations
cd backend && make test     # PHPStan(lvl8) + CS-Fixer + PHPUnit testsuite Unit SEULEMENT (§10.2)
cd backend && make tests-complete             # miroir exact de la CI — à passer AVANT tout push backend
cd engine  && make test     # ruff (+format --check) + mypy + bandit + pytest   |  make format
make -C frontend dev        # Dockerized Vite :5173 (proxies /api, /exports, /.well-known/mercure)
make -C frontend e2e        # Playwright entièrement dockerisé — exige stack + dev lancés
```

## 4. CI — ce qui gate quoi

Graphe des jobs, rôles et pièges : **`docs/testing/testing-strategy.md` §1** (canonique).
Ce qu'il faut retenir en toute session :

- **Bloquant = step NOMMÉ du job `blocking-tests` dans `.github/workflows/ci.yml`** — jamais
  l'annotation `#[Group('phase1')]` (la portent bien plus de fichiers `backend/tests/` que le job
  n'a de steps nommés). Un test annoté mais non listé tourne dans `unit-tests` : après le gate,
  sans bloquer `build-docker`. Toute
  affirmation « X est bloquant » se vérifie dans `ci.yml`. *(Le dernier cas ouvert,
  `TeamTagScopeTest`, est devenu un step le 2026-08-11 — DOC-3 fermée.)*
- La liste ci-dessous est **la maison unique** de « quels tests gatent » (copie supprimée de
  testing-strategy après dérive, DOC-26) ; `BlockingTestsListMatchesCiTest` la compare à `ci.yml`
  dans les deux sens. Ce que chaque test garde en détail : **son propre docblock**.
- Jobs sans `needs` mais **required checks de `main`** : `rector` (style gate) ·
  `dependency-audit` · `secrets-scan` · `semgrep` · `smoke-tests` (5 smokes sémantiques) ·
  `engine-semantics` (groupe `contract` cross-stack). `build-docker` needs
  **[blocking-tests, engine-tests] only**.

**blocking-tests** (must pass first — steps du job, un par ligne, tag = ce qui est gardé) :
`Unit/Entity/UserInterfaceContractTest` (boot conteneur : `eraseCredentials`) ·
`Unit/NoMergeConflictMarkerTest` (aucun marqueur de conflit Git commité — dépôt entier, `git grep` sur les fichiers SUIVIS ; écrit après que trois marqueurs de `stash pop` ont atteint `main` dans un journal Markdown, invisibles au linter comme à la revue) ·
`Security/TenantIsolationTest` (isolation club) ·
`Security/SeasonIsolationTest` (scoping saison + `X-Season-Id`) ·
`Security/SeasonReadonlyTest` (saison archivée → 409) ·
`Security/MatchTenantIsolationTest` (entités match tenant+season) ·
`Security/TenantCacheIsolationTest` (cache scopé club) ·
`Queue/ConcurrentGenerationTest` (verrou de génération) ·
`CrossStack/ContractSchemaTest` (contrat backend⇄engine) ·
`CrossStack/PayloadVersionMatchesContractVersionTest` (la version que le payload s'attribue == `engine/CONTRACT_VERSION`, égalité STRICTE — la dérive avait vécu deux bumps en silence) ·
`CrossStack/ValidateAssignmentsContractSchemaTest` (contrat du verdict `/validate-assignments`) ·
`Security/RlsIsolationTest` (RLS en base) ·
`Security/ClubAccessTest` + `Security/UserSelfOnlyTest` + `Security/ImportAuthorizationTest` (lockdown API tenant) ·
`Security/MercureHardeningTest` (Mercure durci) ·
`Security/ManagementRoleTest` (écriture = management par défaut) ·
`Security/ApiRateLimitTest` (throttle par user) ·
`Security/ClubQuotaTest` (caps métier PAR CLUB, les 4 routes de solve — dont `/fill`, le comblement de période P2-44) ·
`Security/SuperAdminAccessTest` (frontière SA0) ·
`Security/EngagedTeamGuardTest` (périmètre engagé) ·
`Security/PeriodPlanBirthTest` (naissance du plan — ADR-0002 ; depuis P2-38, garde aussi le refus
409 `window_already_planned` dans les deux sens quand deux plans de période se chevauchent) ·
`Security/PeriodCopyBirthTest` (l'adaptation naît comme une COPIE du socle : la V1 d'un plan de
période transcrit la version POINTÉE du socle filtrée des réglages/fermetures — falsifié dans les
deux sens ; séance saine copiée+verrouillée, jour fermé/gymnase désactivé/équipe réduite « à
replacer » avec leur raison, réduction déterministe [dernières de la semaine], plan déjà versionné
refusé 409, route sous les gardes rôle+tenant) ·
`Security/SeasonVersionUniquenessTest` (socle en vigueur unique) ·
`Security/SeasonPlanInForceTest` (SocleGuard, défense en profondeur) ·
`Security/PeriodGatePayloadParityTest` (gate pré-solve == payload) ·
`Security/RecapCapacityWarningTest` (capacité du récap lue du payload) ·
`Security/CoachDoubleBookingTest` (verrous HARD dédoublant un coach) ·
`Integration/ScheduleConstraintBuilderOverlayTest` (le build overlay n'écrit pas) ·
`Security/PlanEntitlementsTest` (crédits Découverte + caps payants) ·
`Security/MemberRoleTest` (rôles Gestionnaire/Membre, dernier gestionnaire) ·
`Security/ScheduleCapabilityParityTest` (capacité affichée == verdict) ·
`Security/TeamTagScopeTest` (portée d'un tag = ce que le solveur applique) ·
`Integration/Service/LockOriginProvenanceTest` (origine d'un verrou VRAIE : réservation → RESERVATION, épinglage → MANUAL, indécidable → UNKNOWN, jamais deviné) ·
`Security/SlotMoveVerdictTest` (déplacer un créneau passe sous le verdict moteur : refus = planning intact, source hors baseline, 409 pendant une génération) ·
`Security/SlotPlacementVerdictTest` (placer une séance à la dérive passe sous le verdict moteur : refus = rien créé, gardes 409, tenant) ·
`Security/SlotMoveGridParityTest` (garde précoce de move sur la grille : ce que l'écran Gymnases offre est accepté, ce qu'il n'offre pas — triplet inexistant, jour fermé — est refusé SANS appel moteur, et la durée écrite est TOUJOURS celle de l'emplacement, jamais celle qui voyageait avec l'équipe ; compteur de requêtes, saison ET période) ·
`Integration/Service/ConstraintChangeStaleScheduleTest` (une contrainte modifiée APRÈS génération marque les plannings COMPLETED du club+saison comme périmés — listener d'entité, tout writer, frontière saison — et un import les démarque) ·
`Integration/Service/ResourceChangeStaleScheduleTest` (une RESSOURCE du club modifiée — gymnase/coach/créneau/grille/réservation/override/tag/calendrier — marque les plannings périmés ; périmètre dérivé de `schedule_plan_id` : ADR-0002, la grille saison ne périme pas les copies de période et inversement ; un import démarque) ·
`Integration/Service/DeletionImpactParityTest` (supprimer une salle/équipe/coach : ce qui est ANNONCÉ == ce qui est DÉTRUIT — `CascadePlan` est la maison unique, exécutée par le deleter et comptée par l'impact ; une destruction sans annonce rougit, un DQL qui contourne le plan aussi, et le match déjà déclaré perd sa salle SANS disparaître — DOC-2) ·
`Security/PasswordResetEnumerationTest` (parité anti-énumération du rail mot de passe : hash factice, mail par le bus, 429 reset) ·
`Security/RegisterTurnstileTest` (Turnstile sur le register : inerte sans secret configuré, 403 fail-closed sur verdict Cloudflare, fail-open sur panne transport, 403 identique email frais vs connu, rate-limit register intact et prioritaire) ·
`CrossStack/ImplicitRulePayloadParityTest` (règles implicites bien-être : ce qui est STOCKÉ **dans la portée du plan** == le bloc `implicitRules` que SON payload émet, défauts compris — un plan de période émet sa COPIE prise à sa naissance et une modification de saison ne redescend PAS ; un plan né avant la copie retombe sur le bloc saison ; falsifié dans les deux sens) ·
`CrossStack/PreviousAssignmentsPayloadParityTest` (placement précédent ÉMIS au moteur == placements de la version source en base — falsifié dans les deux sens ; source explicite / repli dernière COMPLETED / première génération = pas de bloc / overlay = sa propre lignée jamais le socle / HARD inclus ; une dernière COMPLETED née d'une transcription (P2-44 PR-4, ADR-0004) compte comme n'importe quelle COMPLETED : ses placements, et EUX seuls, sont émis ; ET le précédent n'entre pas dans le hash de snapshot — pas de dérive `snapshotHash` ⇄ `currentStructureHash`) ·
`CrossStack/SharedTrainingPayloadParityTest` (mutualisation : ce qui est STOCKÉ — groupes {équipes, K} ancrés au plan — == le bloc `sharedTrainings` émis au moteur, socle ET période, portée dérivée du plan ; falsifié dans les deux sens — un groupe stocké apparaît, un groupe d'une autre portée ne fuit pas) ·
`CrossStack/TeamLinkPayloadParityTest` (passerelles : ce qui est STOCKÉ — {teamAId, teamBId, intensity} de club+saison (STRUCTURE, patron Team/Coach) — == le bloc `teamLinks` émis au moteur, socle ET période, FILTRÉ au roster ; falsifié dans les deux sens — une passerelle stockée apparaît avec son intensité, un lien nommant une équipe désactivée pour la période ne fuit pas) ·
`CrossStack/SlotRotationPayloadParityTest` (RMM-5 rotation A/B : ce qui est STOCKÉ — créneaux de match partagés {venue, jour, heure, membres} de club+saison — == le bloc `slotRotations` émis au moteur `/place-matches`, ET la SUPPLÉANCE retire l'habitude d'un membre le MÊME jour que sa rotation, jamais celle d'un autre jour ; falsifié dans les deux sens — une rotation stockée apparaît triée, une rotation d'un autre club ne fuit pas [RLS], l'habitude même-jour supplantée / autre jour conservée, sans rotation bloc [] + habitudes intactes) ·
`Security/SocleDeviationParityTest` (les écarts NOMMÉS d'une version de FERMETURE vs le socle pointé, route de lecture `GET /schedules/{id}/socle-deviation` : séances DÉPLACÉES (appariement chronologique socle→période) et NON REPLACÉES (reliquat du socle, raison SERVIE par la sélection — team_reduced/venue_disabled/venue_closed, `null` quand inexpliquée, jamais fabriquée) ; ni les inchangées ni les nouvelles ne sont rapportées, une équipe désactivée n'a aucun écart ; falsifié dans les deux sens, plus 422 saison/vacances, 409 non-COMPLETED/socle non pointé, tenant, lecture ouverte au Membre) ·
`Security/PlannedWindowsParityTest` (P2-38 prévention : la route de lecture `GET /api/planned-windows` SERT exactement les fenêtres que la garde d'écriture refuse en 409 `window_already_planned` — même prédicat par CONSTRUCTION, foyer unique `governingWindows`, prouvé par le comportement et falsifié dans les DEUX sens : plage servie → POST chevauchant refusé, fenêtre non servie → POST accepté ; plus la FAMILLE ni servie ni refusée, l'isolation saison/club [404, jamais un oracle], le chevauchement PARTIEL aux bornes, le chemin pending [`seasonId` sans entrée] et la lecture ouverte au Membre) ·
`Security/MatchVisitDeltaParityTest` (RMM-3, le gardien à l'ouverture du module matchs : l'EMPREINTE d'un conflit est son identité stable — `POST /api/matches/module-visit` stampe une référence de visite PAR UTILISATEUR et sert le delta « depuis ta dernière visite ». Falsifié dans les DEUX sens : une nature changée [le match A passe d'un conflit avec B à un conflit avec C ; MATCH_MATCH→MATCH_TRAINING] est SIGNALÉE, une sévérité/segment qui bougent seuls ou un COMPETITION_INCOMPLETE 9/22→15/22 ne le sont PAS ; nouveaux matchs comptés depuis la référence ; planningChanged dans les deux sens [nouvelle COMPLETED sans repointage / pointeur qui bouge / repointage identique → false] ; isolation USER (le stamp d'Anna n'éteint pas le delta de Mateo) + club [404] + saison [`X-Season-Id`] ; F5 dans la grâce → référence intacte, badges re-servis ; première visite muette ; Membre autorisé, saison archivée → stamp écrit quand même).
Detail: `docs/testing/testing-strategy.md`.

## 5. Conventions (core — détail par zone dans `.claude/rules/`)

- **Backend** : PHPStan level 8 (ext Doctrine+Symfony) · CS-Fixer `@Symfony`+`@PHP84Migration`+risky ·
  **Rector cible PHP 8.4 et son style FAIT convention** (`src/` ET `tests/` — `!$x instanceof Foo`,
  pas `null === $x`) · stack **Symfony LTS 7.4** (dérive → `composer update`, JAMAIS un pin —
  détail piège Flex/lock : `.claude/rules/backend.md`) · PHPUnit 11 via `vendor/bin/phpunit`.
- **Engine** : ruff (line 120, py312, double quotes) — **`ruff format` fait convention et est gardé
  en CI** · mypy `strict` + plugin pydantic · pytest + golden fixtures + invariants + hypothesis.

## 6. Critical invariants (une ligne chacun — le doc pointé fait foi)

- **Multi-tenant, 3 couches** : Doctrine `TenantFilter` + listener **priority 7, APRÈS le firewall
  (ne jamais le remonter — fuite historique)** ; **PostgreSQL RLS ACTIVE** (`app_user`, GUC
  `app.club_id`, 2 tables hybrides `club_user`/`coach_wish_token` ; porte admin = policies
  `admin_all` par rôle, survit au PG managé sans `BYPASSRLS`) ; Club/User scopés dans leurs
  providers. Le listener retourne immédiatement sur `/api/admin/**`. → `backend/docs/TENANT.md` +
  `docs/security/rls.md`.
- **JWT applicatif en cookie httpOnly** (`BEARER`, `path=/api`, `SameSite=Strict`, `Secure` piloté
  par `JWT_COOKIE_SECURE` — jamais `isSecure()`) ; Bearer accepté pour scripts/smokes. →
  `docs/security/jwt-cookie.md`.
- **Superadmin SA0** : identité globale séparée, firewall stateful `/api/admin/**`, TOTP obligatoire,
  CSRF central ; un JWT club ne franchit jamais ce firewall, la session admin ne pose jamais
  `app.club_id`. → `specs/courantes/superadmin-auth.md`.
- **Pages publiques à token** (coach-wish, club-approval) : le token EST l'identité, 404
  byte-identique, rate-limit IP ; le contrôleur pose lui-même `app.club_id` (relâché en `finally`).
- **Concurrence** : `ClubGenerationLock` Redis + verrou asyncio par club côté engine ; placement
  matchs = rail **synchrone** avec son propre `MatchPlacementLock` (ADR-0003).
- **Génération async** : controller → Messenger (Redis) → handler (snapshot figé → POST engine →
  import → Mercure). Worker = conteneur `messenger-worker`.
- **Grille de gymnase possédée par la période** (ADR-0002) : les slots d'une période sont une
  **copie** prise à la naissance du plan — jamais d'union avec la saison ; overrides sparse ;
  pin orphelin → 422 (`OrphanPinGuard`).
- **Le socle commande les plans de période — deux invariants à connaître AVANT de cadrer** (ADR-0002) :
  (1) **aucune génération de période sans socle EN VIGUEUR** — `SocleGuard` garde les 4 contrôleurs de
  solve *et* `PeriodPlanTranscriber` (`Security/SeasonPlanInForceTest`) : si l'on est sur l'étape
  Génération d'une période, le socle a **forcément** une version pointée, donc « et si le socle n'a
  pas de version ? » est un cas IMPOSSIBLE, jamais un repli à écrire.
  (2) **valider ou rouvrir le socle DÉTRUIT les plans de période futurs** — versions, plan et réglages
  ancrés (grille copiée comprise) ; l'**entrée de calendrier survit** et la période retombe « à traiter ».
  ⚠ Le critère est la **DATE** (`startDate > today`), PAS l'avancement : `findWithPlanNotStarted` ne
  signifie pas « plan sans version » — un plan futur DÉJÀ généré est balayé lui aussi. Corollaire : la
  grille copiée ne peut périmer en silence que pour une période **déjà commencée**.
- **Contrat backend⇄engine** : schemas Pydantic ⇄ payload, version `engine/CONTRACT_VERSION`
  (**2.15**, un seul contrat pour les 3 endpoints `/generate` · `/place-matches` · `/validate-assignments`),
  **sync manuelle, pas de codegen** — gardé par `ContractSchemaTest` +
  `MatchPlacementContractSchemaTest` + `ValidateAssignmentsContractSchemaTest`.
- **FFBB outbound** : hosts hard-codés (SSRF-safe), best-effort, le frontend n'appelle jamais FFBB.
  → `backend/docs/ffbb-api.md`.
- **Solveur** : single-pass, **aucun fallback de relaxation**, budget adaptatif 60/180/600 s,
  workers adaptatifs 1/8, INFEASIBLE → `status="failed"` + diagnostics ; un verrou HARD est
  souverain mais diagnostiqué. → `docs/architecture/adr-0001-single-pass-solve.md`.

## 7. Workflow rules (orchestrator)

All custom agents/skills are **manual / user-triggered** (exception documentée : hook
`code-review-graph`). ⚠ **Les subagents ne reçoivent PAS ce fichier** — leurs définitions
(`.claude/agents/*.md`) leur ordonnent de le lire en première action.

**Git discipline (non-negotiable).** **NEVER commit on `main`** — branch first (docs & specs
included), PR ensuite. **NEVER merge without the user's explicit go.** Push freely, stop at
« PR ready ». Applies to every change, doc-only ones too.

**Two lanes — pick BEFORE starting and say which:**
- **Full lane** (default: feature, behaviour/API/schema change, structuring axis §7.1).
- **Light lane** (ALL true: ≤2 files, no behaviour/API/schema change, no axis) : implement →
  tests verts → `documentation-update` → PR → user go.

**Full lane cycle:**
0. **Lire le CODE avant d'analyser.** Tout constat sur l'existant se vérifie dans le code
   (grep/read/test), cité `fichier:ligne` — jamais de mémoire ni depuis un doc (la doc retarde
   toujours sur le code). Jamais « vérifié » sur un balayage partiel.
1. **Need validation** : besoin reformulé en 3-6 lignes + ambiguïtés + ce que je ne ferai PAS,
   chaque constat adossé au code lu. **User valide — pas de `/plan` avant.**
2. `/plan` (agent `planner` — il porte la checklist de cadrage §9). Optionnel `contrarian-review`.
   User valide le plan.
3. Implémenter **strictement dans le scope** (agent `coder` — no opportunistic refactor).
4. **Non-régression obligatoire si axe §7.1 touché** — dans la même PR. ⚠ Annoter `phase1` ne
   gate pas (§4) : si le NR doit gater, ajouter son **step à `ci.yml` ET sa ligne à la liste §4**
   dans la même PR ; sinon le dire explicitement.
5. **Tests verts en local avant de proposer le merge** : `/validation-runner` (suite ciblée de la
   zone + tests de contrat cross-zone + **smoke-solver obligatoire si engine/backend touché** —
   `backend/scripts/smoke-solver.sh`, planning `COMPLETED` attendu).
6. Résumé + **`documentation-update` (exécuté, avant CHAQUE PR, les deux lanes)** — « rien
   d'impacté » est une conclusion qu'on atteint en regardant, jamais une hypothèse.
7. **`/code-review` est SORTI du cycle** (décision fondateur 2026-08-05) — seul le fondateur le
   déclenche. **`/security-review` RESTE systématique** dès que la PR touche auth/données/
   intégrations externes. Répondre à une revue : skill **`review-response`** (règle, consommateurs,
   cadence — plafond 4 rounds, GO fondateur dès le round 2).
8. PR → **user's explicit go** → merge.

### 7.1 Structuring axes (closed list — NR test required when touched)

tenant isolation (filter/listener/voters) · generation pipeline
(controller→messenger→engine→import→Mercure) · **constraint semantics** (une contrainte saisie
doit être honorée par le solveur — smoke sémantique, pas juste COMPLETED) · planning lifecycle
(plan SEASON pointé = calendrier ; valider/rouvrir + verrous — ADR-0002) · **périmètre engagé**
(équipe en compétition : ni suppression ni changement de niveau) · backend↔engine contract
(schemas/CONTRACT_VERSION) · auth & memberships (register/login/approval/roles).
Extending this list = user decision.

## 8. Documentation rules

`CLAUDE.md` = index court ; `docs/` = détail ; **one canonical home, no duplication**. Root
`AGENTS.md` pointe ici ; `backend/AGENTS.md`, `engine/AGENTS.md` & **`frontend/AGENTS.md`**
(le plus fourni des trois) = détail de zone ; `landing/` n'en a pas — tout tient dans sa règle. Mise à jour via
le skill `documentation-update` avant chaque PR. Décisions structurantes → ADR
(`docs/architecture/adr-index.md`).

**Les deux fichiers de suivi — ne jamais les confondre** : `specs/evolution/roadmap.md` =
**l'ouvert seulement** ; `specs/courantes/etat-des-lieux.md` = **le livré + les décisions fermées**.
Un item livré **quitte** la roadmap (jamais en ✅). « Est-ce que X est fait ? » se répond dans
l'état des lieux.

## 9. Scope checklist

La checklist de cadrage (zone, dossiers autorisés/interdits, fichiers probables, doc à mettre à
jour, axes §7.1 → NR, smoke-solver si engine/backend) **vit dans `.claude/agents/planner.md`** —
maison unique, c'est l'agent qui l'exécute. Tout plan produit doit la remplir littéralement.

## 10. Gotchas (top)

1. Tout le tooling tourne dans Docker ; l'hôte n'a besoin que de Docker, Compose et Make.
2. ⚠ **`make phpunit` = `--group phase1` seul · `make test` = testsuite `Unit` seule** — or la CI
   `unit-tests` lance `phpunit tests/` ENTIER (7 dossiers hors testsuites : `Api/`, `Command/`,
   `Double/`, `EventListener/`, `MessageHandler/`, `OpenApi/`, `Validator/`). **Avant de pousser :
   `make -C backend tests-complete`** (miroir CI, enchaîne `db-init-test`).
3. `contracts/` et `tests/` racine = placeholders vides (les tests cross-stack vivent dans
   `backend/tests/`).
4. Frontend rebuilt + **actif** — tenant résolu côté serveur depuis le JWT : le front n'envoie
   **aucun** header `X-Club-Id`.

**Pointers:** `docs/project-map.md` · `docs/glossary.md` · `docs/testing/testing-strategy.md` ·
`specs/evolution/roadmap.md` (**l'ouvert**) · `specs/courantes/etat-des-lieux.md` (**le livré**) ·
`docs/architecture/adr-index.md` · `specs/README.md` · `backend/docs/commands.md` ·
`backend/docs/ffbb-api.md` · ops : `docs/ops/` (`backup-restore.md` · `prod-stack.md` ·
`deploy.md` · `load-test.md` · `observability.md`) · sécurité : `docs/security/` (`rls.md` · `mercure.md` · `jwt-cookie.md` · `rgpd.md` ·
`scanners.md` · `turnstile.md`) · clés `config` d'une contrainte : `backend/docs/constraint-config-keys.md` ·
archives : `docs/archive/`
