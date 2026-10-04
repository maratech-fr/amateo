# Audit ClubScheduler — édition 2026-08-08

| Méta | Valeur |
|---|---|
| Date | 2026-08-08 |
| Modèle | `claude-fable-5` (Fable 5, Anthropic) |
| HEAD | `0feabea5` (branche `a11y/p4-72-taille-texte`, working tree propre — pas `main` : la branche porte le seul commit P4-72, textes < 12 px) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, UX) + checks directs (supply chain ×3 exécutés, Mercure, secrets, prod-readiness, RGPD, cyber A1–A19) + smoke-solver EXÉCUTÉ + **parcours navigateur EXÉCUTÉ (1re fois)** + vérification contradictoire manuelle |
| Édition précédente | `AUDIT-2026-07-19-claude-fable-5.md` (HEAD `1484925`) — depuis : **218 commits** (JWT cookie httpOnly, Mercure consommé côté front, SEC-13 whitelist config + job CI `engine-semantics`, SEC-17/18, BCK-11 RLS, démo BCCL + horloge simulée, approbation club par token, compose prod + non-root, code-splitting, purge docs mortes) |

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + 8 sondages (6 EXACT, 2 MENSONGES contre-vérifiés à la main) |
| Besoin produit | ✅ couvert | roadmap/état-des-lieux vs livré |
| Code backend | ✅ couvert | statique, RLS relu au SQL (migration BCK-11), chaîne SEC-13 suivie de bout en bout |
| Code engine | ✅ couvert | statique, parité liste-blanche⇄moteur vérifiée clé par clé, déterminisme confirmé au code |
| Code frontend | ✅ couvert | statique + `tsc -b` (0 erreur) + `vitest run` (**967 verts / 124 fichiers**) exécutés |
| Supply chain | ✅ couvert | `npm audit --omit=dev` + `composer audit` + `pip-audit` (via freeze, conteneur non-root oblige) : **0 vuln** |
| Cybersécurité — surface d'attaque | ✅ couvert | A1–A18 verdictés + **nouvelle ligne A19** (approbation club par token) |
| Infra / Mercure | ✅ couvert | compose dev lu + **`docker-compose.prod.yml` lu (mem_limit partout, restart, healthchecks)** — INF-03 fermé |
| Prod-readiness / observabilité | ✅ couvert | stack prod + runbooks `docs/ops/` indexés depuis CLAUDE.md (DOC-22 fermé) ; Sentry/backups inchangés depuis leur preuve du 07-19 |
| RGPD | ✅ couvert | spot-check : purges/effacement/export toujours en place (`SeasonDataPurger`, `ErasedClubPurger`, `AccountErasureService`, `PurgeInactiveUsersCommand`) |
| Performance mesurée | ✅ couvert | smoke-solver COMPLETED (score 21578, **~10,5 s wall**, worker relancé pour l'occasion) |
| UX-Cohérence | ✅ couvert | statique (21 primitives, comptes reproductibles) |
| UX-Simplicité & Intuitivité | ✅ **couvert (1re fois en dynamique)** | proxys statiques **+ parcours navigateur exécuté** (login → cockpit → planning → wizard → matchs, captures) |
| Inclusivité / a11y | 🟡 partiel | statique + focus clavier sondé en vrai ; **mesure de contraste exécutée mais partiellement fiable** (fonds semi-transparents faussent la conversion canvas — voir annexe) |
| Coûts / scalabilité financière | ❌ non couvert (pas de données réelles) | ligne permanente — aucune donnée facturation/infra prod |
| Restauration après corruption | ✅ couvert (exécuté 07-19, mécanisme inchangé) | restore-check prouvé à l'édition précédente ; rien n'a bougé dans `app:db:*` |
| Comportement offline / latence front | ❌ non couvert | jamais ouvert |
| Montée de version sur données réelles (migrations up/down sur dump) | ❌ non couvert | candidat 07-19, toujours pas exécuté |
| **Tenue sous charge API** (N gestionnaires simultanés, rate-limits réels sous concurrence) | ❌ non couvert (**nouveau candidat 2 bis**) | rendu visible et redevable — jamais mesuré |

> **Posture** : barre = **application commercialisable** (cible mi-2027). Sévérité assumée ; « ça tourne » n'est jamais un signal de réussite.

---

## Synthèse des notes

| Critère | 2026-07-19 | **2026-08-08** |
|---|---|---|
| 1. Documentation | 83 | **81** |
| 2. Pertinence du besoin | 90 | **91** |
| 3a. Code backend | 86 | **85** |
| 3b. Code engine | 82 | **86** |
| 3c. Code frontend | 77 | **84** |
| 4. Supply chain | 96 | **96** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 83 | **85** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 81·.10 + 91·.10 + 85·.25 + 86·.20 + 84·.15 + 96·.05 + 90·.075 + 74·.075 = **85,4** ; malus transversal **0** (les quatre motifs du −1 précédent sont fermés : compose prod avec `mem_limit`, JWT hors localStorage, config prod d'orchestration livrée, runbooks indexés — réserve résiduelle : dumps toujours on-host, hook off-site à configurer) → **85**.

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 07-19 | **08-08** | Plafond appliqué |
|---|---|---|---|
| UX-Cohérence | 80 | **82** | aucun finding ≥ Moyen côté gestionnaire ; résidus Faibles (UXC-11/13, empty states inline) |
| UX-Simplicité & Intuitivité | 74 | **75** | UXS-04 (« Dans **-35 j** » affiché au gestionnaire) Moyen confirmé en NAVIGATEUR → plafond 75 |
| Inclusivité / a11y | 72 | **74** | A11Y-12 (cibles ~16 px) Moyen confirmé → plafond 75 ; A11Y-06 largement résorbé |
| **Score UX général** | 72 | **74** | = le PLUS BAS des sous-axes |

**Lecture rapide.** Édition de **solde massif** : sur les 19 findings ouverts/partiels de l'édition précédente, **15 sont corrigés ou fermés avec preuve** — dont les trois dettes structurelles du frontend qui dataient de 4 éditions (FRT-04 Mercure enfin consommé par un EventSource ref-compté, FRT-10 découpé en 17 routes lazy, SEC-16 JWT en cookie httpOnly fail-secure), la défense en profondeur backend au complet (SEC-13 whitelist `config` **prouvée par effet contre le vrai moteur** en job CI bloquant, SEC-17/18, BCK-11 sous RLS FORCE), et l'engine dans son meilleur état (ENG-17/25/26/27 soldés, ALIGN-07 fermé par ruling fondateur avec 3 tests NR). Le motif récurrent « déclaré ≠ effectif » ne survit plus que sur **deux cas neufs et mineurs** : BCK-12 (un enum de contrainte fautif retombe en silence sur un défaut) et le couple documentaire DOC-26/DOC-27 — car c'est **la doc qui ment cette fois, pas le code** : la liste canonique des blocking-tests de CLAUDE.md §4 est fausse dans les deux sens, et 2 des 3 docs d'alignement contraintes décrivent des clés que SEC-13 vient de supprimer. Le parcours navigateur — ouvert pour la première fois — a rendu un vrai défaut invisible au grep : le cockpit affiche « Vacances d'Été · Dans **-35 j** » pour une période déjà commencée.

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| SEC-13 | ConstraintInput.config non validé | backend | Faible | **contre-vérifié à la main** | **corrigé** — liste blanche noms+types (`ConstraintConfigValidator.php:54-94`), branchée création ET PUT contre la famille finale (`ConstraintStateProcessor.php:89,132-136`), 422 nommant la clé ; **prouvée par effet contre le vrai moteur** (`ConstraintKeysAreHonouredByEngineTest`, job CI dédié bloquant `engine-semantics`, `ci.yml:499`) ; `coachId` supprimé (doublon du scope), FACILITY_CAPACITY retirée, ConstraintSerializer supprimée |
| SEC-16 | JWT en localStorage | frontend | Moyenne | **contre-vérifié à la main** | **corrigé** — cookie httpOnly `BEARER` SameSite=Strict path=/api (`lexik_jwt_authentication.yaml:10-21`), `secure` fail-secure (défaut `'true'` `services.yaml:32`, `false` confiné `.env.dev`/`.env.test`), jamais `isSecure()` ; front = booléen seul, migration persist v2 **efface** le token legacy (`authStore.ts:42-49`) ; NR `JwtCookieContractTest` phase1 |
| SEC-17 | Listener tenant sans skip `/api/admin` | backend | Faible | confirmé | **corrigé** — retour immédiat sur `/api/admin` (`TenantFilterListener.php:70-72`), rationnel en commentaire |
| SEC-18 | CSRF admin opt-in | backend | Faible | confirmé | **corrigé** — `AdminCsrfListener` central prio 6, toute méthode non sûre sous `/api/admin`, exemptions = les 2 portes de connexion (`AdminCsrfListener.php:38,52-55,84-94`) |
| BCK-04 | God-services (builder/provisioner) | backend | Moyenne | confirmé | **fermé (décision fondatrice)** — `etat-des-lieux.md:196` (2026-08-07) : discipline, pas dette ; critères de réouverture écrits. **Tendance notée** : builder 980 l. (+85), provisioner 983 l. (+136), `BcclSeeder` naît à 1161 l. |
| BCK-07 | Check club sauté si `$clubId` null | backend | Mineure | confirmé | **fermé (accepté)** — sans club résolu, aucun GUC posé ⇒ RLS FORCE refuse tout ; méta-test dynamique (`RlsIsolationTest.php:66-84`) |
| BCK-10 | requireActiveAdmin sans clubId | backend | Faible | confirmé | **partiel** — l'adhésion suit le club de la requête (`MembershipController.php:99-113`) ; résidu multi-club : `findOneBy` sans ordre (`TenantFilterListener.php:232-240`), tracé P4-8 |
| BCK-11 | `team_tag_assignment` hors RLS | backend | Faible | **contre-vérifié à la main** | **corrigé** — `Version20260807170000.php:42-62` : club_id + backfill + purge orphelines + NOT NULL + ENABLE + **FORCE** + policy ; couvert par le méta-test dynamique |
| ENG-17 | Diagnostics coach inertes (TEAM_COACH) | engine | Moyenne | confirmé | **corrigé** — `team_coach_map` posée par `_solve` (`main.py:288`), lue par `build_result` (`result_builder.py:79-83,1010-1039`) ; test `test_coach_in_output.py` |
| ENG-25 | Déterminisme inter-process (hash) | engine | Mineure | confirmé | **corrigé** — `sorted(...)` au site (`objective.py:425`) + test de propriété `test_deterministic_term_order.py` ; balayage : aucun autre foyer |
| ENG-26 | Harnais test version=1.0 | engine | Mineure | confirmé | **corrigé** — `read_contract_version()` (`pipeline.py:145`) + garde `test_harness_speaks_the_real_contract.py` |
| ENG-27 | maxTeams=0 ignoré < 2 candidats | engine | Mineure | confirmé | **obsolète** — famille FACILITY_CAPACITY retirée des deux côtés ; capacité = `trainingSlots.capacity` bornée `ge=1` (`input_schema.py:34`) |
| ALIGN-07 | Verrou HARD consomme le créneau entier d'un gym divisible | engine | Moyenne | confirmé | **fermé (décision fondateur 2026-07-25)** — comportement voulu, gelé par 3 NR (`test_hard_lock_divisible_slot.py:43-104` : partage explicite par double pin possible, diagnostic capacity-aware) ; passe en décision fermée |
| FRT-04 | Pas de Mercure (polling) | frontend | Moyenne | confirmé | **corrigé** — EventSource singleton ref-compté (`scheduleStream.ts:79-134`), auth hub par cookie httpOnly via `GET /api/mercure/auth`, réception → invalidation react-query, retry 10 s ré-authentifiant ; polling conservé en fallback assumé (2,5 s déconnecté / 15 s connecté) |
| FRT-09 | Schedules fantômes au retry | frontend | Faible | confirmé | **ouvert (résidu)** — retry saison crée une version neuve à chaque échec (`GenerateStep.tsx:204` → `queries.ts:614-617`) ; mode période réutilise l'overlay ; pas de corruption (409 socle) |
| FRT-10 | 0 code-splitting (bundle 763 KB) | frontend | Moyenne | confirmé | **corrigé** — 17 routes en `lazy()` (`router.tsx:56-144`) ; chunk principal 292 K, total 1,1 M réparti |
| FRT-18 | Messages serveur bruts en toast | frontend | Mineure | confirmé | **corrigé (résidu Faible)** — corps serveur repris **seulement en 4xx** (`errorMessage.ts:32`), 5xx génériques ; résidu : fallback `(429)` expose le code au lieu d'un « trop de requêtes » |
| DOC-04 | Inventaires 2e rang périmés (stamps non fiables) | doc | Moyenne | confirmé | **partiel (5e récidive)** — `engine-inventory` désormais **gardé par test** (`test_contract_version_doc_sync.py:32`, cite 2.2 : corrigé pour CE fichier) ; MAIS `backend-inventory.md:6` figé au 07-25 (218 commits de retard : ignore cookie JWT, démo, ClubCreationRequest, whitelist SEC-13) et `superadmin-auth`/`frontend-spec` édités SANS bump du stamp |
| DOC-14 | backend-inventory ment | doc | Élevée→Moyenne | confirmé | **absorbé par DOC-04** (même fichier, même motif) |
| DOC-16 | testing-strategy incomplet | doc | Élevée | confirmé | **ouvert, absorbé par DOC-26** — la liste re-drifte encore (renvoie à CLAUDE.md §4 « canonique »… lui-même faux) |
| DOC-19 | engine-inventory (version contrat) | doc | Moyenne | confirmé | **corrigé** — mécanisme anti-mensonge en place et opérant (2.2 partout) |
| DOC-20/25 | Docs morts groupés | doc | Mineure | confirmé | **corrigé** — `cleanup-candidates.md` et `plan-de-test-post-36.md` **supprimés** ; pointeurs propres |
| DOC-21 | commands.md ment sur les cibles Make | doc | Moyenne | **contre-vérifié** | **corrigé (reliquat Mineur)** — `commands.md:12-14` ↔ `Makefile:33-60` exacts ; reliquat : cible `tests-engine-semantics` (`Makefile:52-55`) documentée nulle part |
| DOC-22 | Sentry/backups invisibles des index | doc | Moyenne | confirmé | **corrigé** — CLAUDE.md « Pointers » cite `docs/ops/backup-restore.md`, `prod-stack.md`, `deploy.md`, `docs/security/` |
| DOC-23 | superadmin-auth en retard | doc | Moyenne | confirmé | **corrigé sur le fond** (contenu recalé SEC-18) ; le stamp non bumpé rejoint DOC-04 |
| DOC-24 | Table d'alignement périmée (`venue_closed`) | doc | Moyenne | confirmé | **partiel** — `constraint-coverage.md:41` à jour (daté 2026-08-08) ; les 2 autres couches re-périmées sur un AUTRE sujet → **DOC-27** |
| INF-03 | Limites RAM absentes | infra | Mineure | **contre-vérifié à la main** | **corrigé** — `docker-compose.prod.yml` : `mem_limit` sur tous les services (64m→1g, dimensionnés et commentés), `restart: unless-stopped`, healthchecks |
| UXC-07 | « salle » vs « gymnase » | ux | Faible | confirmé | **partiel** — ClubPage purgé ; résidu **confiné au module matchs** → UXC-13 |
| UXC-08 | tu/vous mélangés | ux | Faible | confirmé | **partiel** — 3 chaînes restantes (6 à la baseline), concentrées dans GenerateStep → UXC-11 |
| UXC-09 | plan/planning · green-600 en dur | ux | Faible | confirmé | **partiel** — plan/planning déplacé, toujours dans le même écran (→ UXC-11) ; `text-green-600` (`new-password-fields.tsx:47`) intact |
| UXC-10 | Empty states inline | ux | Faible | confirmé | **partiel** — primitives `EmptyHint`/`EmptyBlock` créées (13 importeurs) ; ~14 sites inline restants côté gestionnaire (RadarPanel:703, SlotReservationModal:264…) |
| UXS-03 | Composants > 400 l. | ux | Moyenne | confirmé | **fermé (décision fondatrice)** — même ruling que BCK-04 ; 7 fichiers > 500 l., stable |
| A11Y-06 | Texte < 12 px | ux | Moyenne | confirmé | **largement corrigé** — 15 occ / 6 fichiers (24/13 à la baseline), toutes en grilles denses assumées (commit P4-72) ; résidu : 2 × `text-[9px]` sous le plancher 10 px des grilles → A11Y-14 |
| A11Y-10 | ResourceFilter sans nom + backdrop focusable | ux | Moyenne | confirmé | **corrigé** — `aria-label` périmétré (`ResourceFilter.tsx:96`), backdrop `tabIndex={-1}` (`:80`) |
| A11Y-11 | Erreur = bordure seule | ux | Moyenne | confirmé | **corrigé** — message texte `role="alert"` + `aria-invalid` aux 3 sites (CoachesStep:268-272, TeamsStep:751-760, VenuesStep:412-416) ; résidu `aria-describedby` → A11Y-13 |
| FRT-02 | Query error avalée (résidu PlanningPage) | frontend | Faible | non re-vérifié | **corrigé pour l'essentiel (07-19)** — résidu in-place non re-contrôlé cette édition |

**Bilan reprise : 20 corrigés/fermés/obsolètes** (dont les 3 dettes frontend historiques et toute la défense en profondeur backend) · 8 partiels · 2 ouverts (FRT-09 résiduel, DOC-16 absorbé) · aucune régression sur un finding corrigé.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| **DOC-26** | **La liste canonique des blocking-tests ment dans les deux sens** : `TeamTagScopeTest` annoncé bloquant par CLAUDE.md §4 mais **aucun step CI ne le lance** (il tourne noyé dans `unit-tests` — exactement le piège que la CI documente elle-même en `ci.yml:317-321`) ; inversement `CoachDoubleBookingTest` (`ci.yml:312`) et `ScheduleConstraintBuilderOverlayTest` (`ci.yml:321`) sont bloquants SANS être listés. `testing-strategy.md:38` déclare cette liste « canonique » | doc | **Élevée** | **confirmé (contre-vérifié à la main)** | nouveau |
| **DOC-27** | **Table d'alignement contraintes périmée post-SEC-13 sur 2 couches / 3** : `constraint-emission.md:23-24` documente `coachId` émis (le front ne l'émet plus — `ConstraintsStep.test.tsx:264`) et `:64` FACILITY_CAPACITY honorée ; `constraint-vocabulary.md:86,104,108-115` (« source de vérité engine ») garde `coachId` vivant et une section FACILITY_CAPACITY entière — famille retirée (`etat-des-lieux.md:195`) | doc | **Élevée** | **confirmé (contre-vérifié à la main)** | nouveau |
| **BCK-12** | Fallback silencieux des enums de contrainte : `tryFrom($value ?? '') ?? CLUB/TIME/HARD` (`ConstraintStateProcessor.php:197-210`) — un `family` fautif devient TIME, un `scope` fautif devient CLUB **en silence**. Le motif « déclaré ≠ effectif » que SEC-13 vient de tuer sur `config`, survivant sur les 3 champs voisins. Mitigation : le config est validé contre la famille retombée (la plupart des typos → 422 par ricochet) ; correctif = 422 sur `tryFrom` null | backend | **Moyenne** | confirmé code-lu | nouveau |
| **UXS-04** | Le cockpit affiche « Vacances d'Été · Dans **-35 j** · pas de planning » pour une période déjà commencée — `RadarPanel.tsx:557` et `:674` interpolent `daysUntil()` sans garde de négatif (la carte indispos `:628` a la garde `started`, pas ses deux voisines). **Vu en navigateur sur données réelles BCCL** | ux | **Moyenne** | **confirmé (navigateur + code)** | nouveau |
| **A11Y-12** | Cibles cliquables ~16 px (< 24 px WCAG 2.5.8) : boutons nus à icône `size-4` sans padding — `SlotDetail.tsx:47`, `PlacementPanel.tsx:126`, `ConstraintsStep.tsx:682,685`, `SlotReservationModal.tsx:187` (aria-labels présents) | ux | **Moyenne** | confirmé | nouveau |
| **FRT-19** | Types API 100 % manuels, aucun codegen OpenAPI : chaque feature écrit ses interfaces (`wizard/api.ts` 446 l., `matches/api.ts` 550 l.) alors qu'API Platform expose un schéma — la dérive de contrat back↔front est invisible au typecheck, rattrapée seulement par la normalisation défensive et les e2e | frontend | Moyenne | confirmé | nouveau |
| **ENG-28** | La suite invariants/hypothesis roule un `_run_pipeline` local divergent de prod (pas de `parse_v2_constraints`, coach depuis slotTemplates — `tests/invariants/test_invariants.py:31-36`, « deferred to E1 » assumé) ; trou limité : le golden BCCL passe par le vrai `build_schedule` | engine | Faible | confirmé code-lu | nouveau |
| **ENG-29** | Le harnais `coach_availability()` injecte encore `config["coachId"]` (`pipeline.py:106`) — clé que la whitelist SEC-13 refuse désormais : le harnais « contract-accurate » émet un config que l'API 422rait. Zéro effet solveur | engine | Faible | confirmé code-lu | nouveau |
| **ENG-30** | Sémaphore global partagé (`max_concurrent_solves=1`) : le rail **synchrone** `/place-matches` attend derrière un solve saison de ~600 s (`main.py:534`) → timeout HTTP côté gestionnaire. Tradeoff documenté, ligne de dette | engine | Faible | confirmé code-lu | nouveau |
| **ENG-31** | Chemins morts : `parsed["fixed_slots"]` jamais alimenté mais câblé (`constraints.py:1709`, `main.py:375`) ; vestiges two-pass (`skip_rest_day_and_distribution`) | engine | Mineure | confirmé code-lu | nouveau |
| **BCK-13** | UUID de gymnase du `config` validé en forme seulement (`ConstraintConfigValidator.php:77-80,201-204`) — un UUID étranger/inexistant s'enregistre (aucune fuite, RLS ; contrainte potentiellement inopérante) | backend | Faible | confirmé code-lu | nouveau |
| **FRT-20** | Tests d'écrans qui mockent les hooks porteurs (17× auth/queries, 15× ./queries…) au lieu du patron §7.2 « mocker l'API voisine, monter le vrai hook » — filet inégal selon les features | frontend | Faible | confirmé | nouveau |
| **DOC-28** | `frontend-spec.md` s'auto-contredit : « aucun jeton (SEC-16) » (`:451`) vs conseils `persist`/`s.token` (`:480-481`) | doc | Mineure | confirmé | nouveau |
| **DOC-29** | `testing-strategy.md` et `project-map.md` sans aucun stamp de vérification (project-map ignore cookie JWT et démo) | doc | Faible | confirmé | nouveau |
| **DOC-30** | Artefact ajouté dans `specs/initiales/` déclarées figées (`rechercherRencontre.xlsx`, commit `b783a84f`) | doc | Faible | confirmé | nouveau |
| **UXC-11** | GenerateStep, un seul écran incohérent : « plan » (`:215`) vs « planning » (`:236`) ET vouvoiement (`:172-173,215`) vs tutoiement (`:199-200`) — concentre les derniers restes UXC-07/08/09 | ux | Faible | confirmé | nouveau |
| **UXC-12** | Console superadmin hors design system : ~50 couleurs palette en dur + 7 réimplémentations loading/error (persona fondateur, pondéré en conséquence) | ux | Faible | confirmé | nouveau |
| **UXC-13** | « salle » résiduel confiné au module matchs (`MatchesPage.tsx:147,318`, `diagnostic.ts:20`, `cockpit/queries.ts:264`) vs « gymnase » partout ailleurs | ux | Faible | confirmé | nouveau |
| **A11Y-13** | Messages d'erreur de champ non reliés par `aria-describedby` (CoachesStep:268, TeamsStep:751,756, VenuesStep:412) — A11Y-11 corrigé à moitié du chemin (annonce par interruption, pas à la relecture du champ) | ux | Faible | confirmé | nouveau |
| **A11Y-14** | 2 × `text-[9px]` sous le plancher 10 px que les grilles se sont donné (`MonthCalendar.tsx:112` « Férié », `VenueAvailabilityGrid.tsx:126`) | ux | Faible | confirmé | nouveau |

---

## Tableau de posture cybersécurité (A1–A19)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant (club_id) | **protégé** | RLS FORCE auto-gardé (méta-test dynamique `RlsIsolationTest.php:66-84`) ; `team_tag_assignment` rejoint le régime (`Version20260807170000.php:56-62`) ; skip admin SEC-17 (`TenantFilterListener.php:70-72`) | — |
| A2 | Brute-force /login | **protégé** | `security.yaml:31-32` throttle 5 ; admin : throttle IP password+totp | — |
| A3 | Énumération de comptes | **protégé** | register 202 uniforme (inchangé) ; login 204 sans corps | — |
| A4 | Falsification JWT | **protégé** | RS256 lexik, `git ls-files backend/config/jwt` **vide** (re-vérifié) ; admin = session+TOTP | — |
| A5 | Escalade de privilège | **protégé** | NR `GlobalReferenceTablesReadOnlyTest` + `ManagementRoleTest` (inchangés, en CI bloquante) | — |
| A6 | Mass-assignment | **protégé** | billing hors `ClubInput` + NR ; **BCK-12** est un fallback d'enum, pas un mass-assignment (aucun champ sensible bindable) | résidu BCK-12 (sémantique, pas privilège) |
| A7 | Injection SQL | **protégé** | Doctrine paramétré ; GUC via `set_config` paramétré (inchangé) ; nouvelles migrations relues (BCK-11 : SQL statique) | — |
| A8 | XSS stockée/reflétée | **protégé** | **0** `dangerouslySetInnerHTML` (re-vérifié par l'agent frontend) ; logos allowlist binaire sans SVG | — |
| A9 | CSRF | **protégé** | ⚠ surface CHANGÉE : le JWT est désormais un cookie ambiant — défense = `samesite: strict` + `path=/api` (`lexik_jwt_authentication.yaml:12-13`) ; admin : CSRF **central** (`AdminCsrfListener.php:84-94`) — SEC-18 fermé | — |
| A10 | DoS bombe de génération | **protégé** | caps backend pré-dispatch (`GenerationComplexityGuard.php:29-34` : 200 équipes/50 gyms/3000 slots/500 contraintes/produit 2000) + caps enveloppe engine (`input_schema.py:13-23`) + timeout adaptatif | — |
| A11 | Spam routes anonymes | **protégé** | `rate_limiter.yaml` register/verify/reset + **`club_approval_public` 20/15 min** (`rate_limiter.yaml:30-33`) | — |
| A12 | SSRF | **protégé** | hosts **const** re-vérifiés (`FfbbApiClient.php:24`, `FfbbLogoFetcher.php:23`), `max_redirects=0` aux 3 sites (`FfbbApiClient.php:187,205`, `FfbbLogoFetcher.php:49`) | — |
| A13 | Abus upload logo | **protégé** | re-vérifié : allowlist png/jpeg/webp + `finfo` sur les OCTETS + 500 KB (`ClubLogoController.php:26-32,62,111`) ; SVG absent | — |
| A14 | Fuite Mercure | **protégé** | hub non-anonyme, secret dédié, CORS borné, port 127.0.0.1 (compose relu) ; abo front via cookie httpOnly rendu par `/api/mercure/auth` (topic scopé club), sélecteur club validé UUID canonique (fix trouvé en PR #423) | — |
| A15 | Exposition de secrets | **protégé** | `.env.prod` = template 0 secret (en-tête relu) ; clés JWT non trackées (`git ls-files` re-exécuté) | — |
| A16 | Erreurs verboses | **protégé** | `APP_DEBUG=0` (`.env.prod:11`, relu) ; front : corps serveur 5xx jamais repris (`errorMessage.ts:32`) ; résidu Faible : code `(429)` visible | — |
| A17 | Clickjacking / en-têtes | **protégé** | `security-headers.conf` (XFO DENY, nosniff, Referrer, HSTS) + `csp.conf` sans hôte tiers, **inclus dans nginx.prod.conf** (`nginx.prod.conf:23,33-34`, relu) | — |
| A18 | Dépendance vulnérable | **protégé** | **0 vuln aux 3 audits exécutés ce jour** (npm --omit=dev · composer audit · pip-audit sur freeze) + gate CI + Dependabot actif (nanoid bumpé le 08-07) | — |
| **A19** | **Usurpation d'approbation de club** (token public deviné/rejoué, spam de demandes, double création) — *nouvelle ligne : la route `POST /api/club-approvals/{token}` CRÉE un club sans JWT* | **protégé** | token 32 octets `random_bytes` + index unique (`ClubCreationRequest.php:39,74`), forme `^[0-9a-f]{64}$` validée, **404 byte-identique** inconnu/malformé/déjà décidé (`ClubApprovalController.php:100-117`), rate-limit IP AVANT résolution (`:48,72`), `pg_advisory_xact_lock` anti-double-création (`ClubApprovalService.php:128-142`) ; micro-oracle 410 expiré vs 404 (négligeable : exige un token réel) | — |

**Bilan cyber : 19 protégé · 0 partiel · 0 absent · 0 non vérifié.** La surface a BOUGÉ (cookie ambiant, route publique créatrice de club, module démo) et reste tenue : l'horloge démo n'a **aucune route HTTP d'écriture** (commandes CLI seules ; `DevClockController` gardé `kernel.debug` → 404 prod), et le cookie JWT est SameSite=Strict fail-secure. Vs 07-19 : 18/0/0 → 19/0/0 avec une ligne de plus.

---

## Détail par critère

### 1. Documentation — 81/100 (83) — exactitude 78 · structure 88 · utilité IA 76 · cycle specs 90
**Forces.** L'état des lieux est **exemplaire** : à jour au 2026-08-08, les 218 commits absorbés (Mercure, JWT cookie, démo, SEC-13/17/18, admin), compteur roadmap exact et auto-vérifiable. Docs mortes purgées. Graduations faites (superadmin, matchs, démo). Et la **première défense mécanique anti-DOC-04 fonctionne** : `engine-inventory` ne peut plus mentir sur la version de contrat (test dédié, 2.2 partout).
**Faiblesses.** Les 2 mensonges restants touchent des points chauds : **DOC-26** — le fichier le plus lu (CLAUDE.md §4) ment sur la liste bloquante dans les deux sens, et testing-strategy s'y réfère comme canonique ; **DOC-27** — l'outil anti-scission (table d'alignement 3 couches) re-périmé par SEC-13 sur 2 couches. `backend-inventory` rote pour la 5e fois (218 commits de retard) : le motif DOC-04 ne se ferme QUE là où un test le garde — c'est la leçon d'engine-inventory à généraliser.

### 2. Pertinence du besoin — 91/100 (90)
La **démo vendeur** (club BCCL anonymisé, horloge simulée par club, reset d'un geste, création prospect par code FFBB) est un outil commercial réel — première brique explicitement tournée vers la vente. L'approbation de club par mail institutionnel FFBB ferme le trou d'enrôlement. La collecte coach par lien tokenisé (différenciateur déclaré) est en place. Viabilité : stack prod livrée (compose immuable, deploy runbook, non-root). Reste : aucune donnée d'usage réel hors BCCL, coûts jamais chiffrés.

### 3a. Backend — 85/100 (86) — correction+sécurité 90 · archi 78 · tests 85 · robustesse 85
**Forces.** Les 7 findings repris sont corrigés ou acceptés avec preuve. SEC-13 est la pièce maîtresse : whitelist noms+types, validée à l'écriture ET au PUT contre la famille finale, **prouvée par effet contre le vrai moteur en job CI bloquant dédié** — le niveau d'exigence le plus haut vu sur ce projet. Méta-tests structurels rares à ce niveau (RLS dynamique par énumération `pg_attribute`, complétude d'interface, hygiène d'env). Approbation club : rate-limit avant résolution, 404 byte-identique, advisory lock anti-double-création. Démo confinée (CLI only).
**Faiblesses.** **BCK-12** : le motif « déclaré ≠ effectif » survit sur `scope`/`family`/`ruleType` (fallback enum silencieux) — correctif d'une ligne, sans test aujourd'hui. BCK-13 (UUID venue en forme seule). Les deux god-services croissent encore (+~10 %/3 sem.) dont l'exception nommée ; `BcclSeeder` naît à 1161 l. Le point global sur la note : la baisse d'un point vs 07-19 tient à BCK-12 (nouveau survivant du motif le plus dangereux du produit), pas à une régression.

### 3b. Engine — 86/100 (82) — correction+sécurité 88 · archi 82 · tests 85 · robustesse 88
**Forces.** Meilleur état des trois dernières éditions : ENG-17/25/26/27 soldés et vérifiés, ALIGN-07 fermé par ruling avec NR sémantiques (partage explicite par double pin, diagnostic capacity-aware). Parité liste-blanche⇄moteur **exacte** (relevé clé par clé). Déterminisme confirmé au code (`1 if n·v ≤ 200 else 8`, seed aux deux phases) ; rejouable depuis le snapshot persisté (payload+hash+seed+version). Diagnostics INFEASIBLE différenciés et actionnables (demande/offre chiffrée, gymnase saturé, règle fautive nommée).
**Faiblesses.** Dette de fidélité de test : ENG-28 (invariants sur pipeline divergent — golden réel limite le trou), ENG-29 (harnais émet un `coachId` que l'API refuserait). ENG-30 : `/place-matches` synchrone derrière le sémaphore d'un solve de 600 s. `constraints.py` 2261 l. avec couches d'alias historiques et chemins morts (ENG-31).

### 3c. Frontend — 84/100 (77) — correction+sécurité 88 · archi 80 · tests 81 · robustesse 86
**Forces.** Les 3 dettes structurelles de 4 éditions sont fermées le même mois : **Mercure consommé** (EventSource ref-compté, auth cookie httpOnly, invalidation react-query, fallback polling qui ne meurt jamais), **17 routes lazy** (chunk principal 292 K), **JWT hors localStorage** (booléen seul, migration qui efface le legacy). `tsc` 0 ; **967 tests verts / 124 fichiers** (+484 depuis 07-19). Hygiène : 0 innerHTML, 1 seul `any` hors tests, garde CSP Sentry testée.
**Faiblesses.** FRT-19 (types manuels sans codegen — la dérive de contrat est invisible au typecheck), FRT-20 (hook-mocking répandu), FRT-09 résiduel (versions FAILED accumulées au retry saison), résidu 429 non traduit.

### 4. Supply chain — 96/100 (96)
0 vuln aux 3 audits exécutés (pip-audit via freeze — le conteneur non-root SEC-15 ne permet plus d'installer dedans, c'est une bonne nouvelle qui complique l'audit) ; gate CI + Gitleaks + Semgrep + Trivy ; Dependabot actif (nanoid bumpé la veille).

### 5. Performance — 90/100 (90)
smoke-solver COMPLETED, score 21578, **~10,5 s wall** (create→generate→poll). Nota : le worker Messenger était éteint au démarrage de l'audit (exit 0, arrêt propre du `--time-limit`) — en dev c'est bénin ; en prod le `restart: unless-stopped` du compose prod couvre ce cas.

### Infra / Prod-readiness / RGPD
**Fermés cette édition** : INF-03 (mem_limit partout, dimensionnés et commentés), config prod d'orchestration (`docker-compose.prod.yml` + runbooks indexés), images non-root, failure transport sur stream Redis dédié. **Restent** : dumps on-host (hook off-site à configurer), et la ligne coûts toujours vide. RGPD : mécanique intacte (spot-check purges/effacement/export).

### UX (axes additifs — détail)
**Cohérence 82.** Primitives consolidées (21 modules ui, EmptyHint 13 importeurs, button 54) ; coach/entraîneur et créneau/slot **unifiés** ; zéro modale ad hoc, zéro placeholder-only. Résidus Faibles : GenerateStep incohérent à lui seul (UXC-11), « salle » confiné aux matchs (UXC-13), ~14 empty states inline, console admin = 2e système de style (UXC-12, persona fondateur).
**Simplicité 75.** Jargon : propre (RULE_LABEL traduit tout ; socle/overlay/payload invisibles). **Parcours navigateur exécuté** : login→cockpit→planning→wizard→matchs fluides, une action principale par écran, focus visible (ring oklch). MAIS **UXS-04 confirmé en vrai** : « Dans -35 j » sur le cockpit — exactement le genre de défaut que 4 éditions de grep n'ont jamais vu → plafond 75.
**Inclusivité 74.** A11Y-10/11 corrigés au site près, A11Y-06 résorbé aux grilles denses assumées. Nouveaux : A11Y-12 (5 cibles ~16 px, Moyen → plafond 75), A11Y-13 (describedby), A11Y-14 (2 × 9px). Mesure de contraste dynamique tentée mais partiellement fiable (fonds alpha) — les paires amber-sur-amber 10 px du calendrier restent à mesurer proprement. Score général UX = **74**.

---

## Avis global + axes priorisés

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| Config prod d'orchestration : compose prod, mem_limit, hook off-site | ~~P1~~ | — | ✅ compose prod + mem_limit livrés (INF-03) ; ⚠ hook off-site backups **toujours à configurer** (reste en P2 ci-dessous) |
| ALIGN-07 réservation HARD gym divisible | ~~P1~~ | — | ⛔→✅ **fermé par ruling fondateur** (comportement voulu, 3 NR sémantiques — le partage explicite par double pin est LA voie) |
| Doc drift bundle (DOC-21/16/19/24/22/23) | ~~P1~~ | — | ✅ 4 des 6 fermés (21/19/22/23) ; 16/24 absorbés par les nouveaux DOC-26/27 ⬜ |
| FRT-04 Mercure côté front | ~~P2~~ | — | ✅ EventSource + fallback |
| FRT-10 code-splitting | ~~P2~~ | — | ✅ 17 routes lazy |
| SEC-16 JWT hors localStorage | ~~P2~~ | — | ✅ cookie httpOnly fail-secure |
| A11Y-10/11 champ + messages d'erreur | ~~P2~~ | — | ✅ (résidu describedby → A11Y-13) |
| ENG-17 coachId de sortie · ENG-26 harnais | ~~P2~~ | — | ✅ les deux |
| UXS-03 découpage composants | ~~P2~~ | — | ⛔ **décision fondatrice** : discipline, pas dette (critères de réouverture écrits) |
| **DOC-26** — resynchroniser la liste bloquante CLAUDE.md §4 ↔ CI (ajouter `TeamTagScopeTest` en step OU le retirer de la liste ; lister CoachDoubleBooking + BuilderOverlay), et envisager un test qui DIFFE la liste §4 contre les steps du job (le patron engine-inventory) | **P1** | S | ⬜ nouveau |
| **DOC-27** — passe `documentation-update` sur `constraint-emission.md` + `constraint-vocabulary.md` (retirer coachId + FACILITY_CAPACITY) | **P1** | S | ⬜ nouveau |
| **BCK-12** — 422 sur `tryFrom` null quand `family`/`scope`/`ruleType` est fourni + test qui rougit | **P1** | S | ⬜ nouveau |
| **UXS-04** — garde de négatif sur les 2 cartes radar (« Commencées depuis N j » ou masquer) | **P1** | S | ⬜ nouveau (vu en navigateur) |
| A11Y-12 cibles < 24 px (padding sur les 5 boutons) · A11Y-13 describedby · A11Y-14 deux 9px | P2 | S | ⬜ nouveau |
| Hook off-site backups (résidu du P0 07-19 : dumps on-host) | P2 | S | ⬜ |
| FRT-19 codegen OpenAPI → types front (ou test de dérive contractuelle front↔snapshot) | P2 | M | ⬜ nouveau |
| DOC-04 généraliser le patron anti-mensonge (test qui garde `backend-inventory` comme `engine-inventory`) — 5e récidive, les stamps ne suffisent pas | P2 | M | ⬜ |
| ENG-28 invariants sur le vrai pipeline · ENG-29 harnais sans coachId | P2 | S/M | ⬜ nouveau |
| ENG-30 file dédiée ou éviction de priorité pour `/place-matches` | P3 | M | ⬜ nouveau |
| FRT-09 résidu retry saison · FRT-20 hook-mocking · UXC-11/12/13 · BCK-13 · ENG-31 · DOC-28/29/30 | P3 (fond de sac) | S | ⬜ |

## Features intéressantes à développer (valeur/effort)
- **Diff automatique liste bloquante ↔ CI** (sur DOC-26) : un test PHP qui parse `ci.yml` et compare aux `#[Group('phase1')]` annoncés — 50 lignes, tue définitivement la classe de mensonge la plus récurrente du projet (le patron `test_contract_version_doc_sync` a déjà prouvé que ça marche).
- **Restauration en un clic** (reprise 07-19, toujours valable) : le restore-check prouve le dump ; un job « recette depuis le dump N » transformerait la preuve en outil d'exploitation.
- **Parcours démo scénarisé** : la démo BCCL + horloge simulée existent — un « tour guidé » du cockpit au planning généré capitaliserait l'outil vendeur qui vient de naître.

## Annexe méthodologie
**Exécuté vs statique.** Exécuté : `tsc -b` (0 erreur), `vitest run` (967 verts/124 fichiers), `npm audit --omit=dev` + `composer audit` + `pip-audit` (0 vuln), smoke-solver (COMPLETED 21578, ~10,5 s — worker Messenger relancé pour l'occasion), **parcours navigateur** (chromium projet piloté par script — le MCP Playwright refuse à tort, mémoire projet confirmée — sur `:5173` : login réel <adresse gestionnaire dev>, captures cockpit/planning/wizard/matchs, sonde de focus clavier, tentative de mesure de contraste), `docker compose ps`, `git ls-files`, greps ciblés. Statique : backend/engine/doc/UX (lecture de code, lignes citées).
**Contre-vérifiés à la main (Étape 3)** : DOC-26 (grep `ci.yml` + CLAUDE.md moi-même), DOC-27 (grep des 3 docs), SEC-16 (lexik yaml relu), A12/A13 (hosts const + finfo relus), A17 (headers prod relus), A19 (contrôleur + rate-limiter relus), INF-03 (compose prod relu), UXS-04 (capture + `RadarPanel.tsx:557` relu), BCK-11 (migration citée), smoke + supply exécutés directement.
**Confiance par axe** : Frontend/Supply/Perf **élevée** (exécutés) ; UX-Simplicité **élevée** (parcours exécuté, 1re fois) ; Cyber **élevée** (spot-checks directs re-exécutés sur chaque ligne changée) ; Backend/Engine/Doc/UX-Cohérence **moyenne** (lecture de code) ; Inclusivité **moyenne** (statique solide, contraste dynamique non fiable) ; Besoin **moyenne**. Aucune note à confiance faible.
**Limites.** (1) La mesure de contraste navigateur est fausse sur fonds semi-transparents (conversion canvas sans composition alpha) : les ratios < 1,5 rapportés sont des artefacts, PAS des findings — seules les paires amber 10 px du calendrier méritent une vraie mesure (outil dédié, prochaine édition). (2) HEAD est une branche a11y à +1 commit de main, pas main. (3) Migrations up/down sur dump réel et charge API : toujours jamais exécutées. (4) L'e2e Playwright complet n'a pas été relancé (le dist du conteneur :8081 est cuit — le parcours a été fait sur le Vite dev, code vivant).
**Auto-question de biais.** (1) 218 commits dont beaucoup répondent à MES éditions précédentes — risque de sur-créditer la correction ; contre-mesure : chaque « corrigé » exige une ligne de code citée, et les deux Élevées de cette édition sont précisément dans ce qui vient d'être livré (la doc de SEC-13 et la liste bloquante). (2) Le navigateur ouvre un angle neuf mais je n'ai regardé que 5 écrans en happy path — le wizard profond (contraintes, récap) et les états d'erreur restent non parcourus. (3) Sur-poids persistant du greppable : UXS-04 prouve qu'un défaut visible en 10 secondes de navigateur peut survivre à 4 éditions de grep — c'est un argument pour rendre le parcours navigateur systématique, pas pour me féliciter de l'avoir enfin fait.
