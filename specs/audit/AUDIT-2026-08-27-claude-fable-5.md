# Audit ClubScheduler (Amateo) — édition 2026-08-27

| Méta | Valeur |
|---|---|
| Date | 2026-08-27 |
| Modèle | `claude-fable-5` (Fable 5, Anthropic) |
| HEAD | `f4cc22fa` (branche `chore/extract-blocking-tests-list` — main + 4 commits doc du jour, zéro code applicatif d'écart) |
| Méthode | 5 agents d'analyse parallèles (doc, backend, engine, frontend, UX) + checks directs (supply chain ×3 exécutés, Mercure, secrets, prod-readiness, RGPD, cyber A1–A21) + smoke-solver EXÉCUTÉ + onboarding-smoke EXÉCUTÉ + **restore drill EXÉCUTÉ (1re fois, nouvel axe)** + **parcours navigateur EXÉCUTÉ (register→verify→login→wizard→matchs, clavier, 2 thèmes — l'axe dynamique rouvre)** + vérification contradictoire manuelle (ENG-36, FRT-27, UXC-18/19, UXS-05, DOC-34, god-services re-mesurés) |
| Édition précédente | `AUDIT-2026-08-19-claude-opus-5.md` (HEAD `08c3c38b`) — depuis : **114 commits** (programme RMM module matchs COMPLET : gardien de visite, rail 5 étapes, réconciliation FBI xlsx+API, rotation A/B contrat 2.15, échéances ligue/comité, dépointage venue_lost P2-52 ; matrice de trajet P2-53 contrat 2.16 avec géocodage BAN + autofill IGN ; refactor engine monolithe→paquet ; forcedDays livré ; P4-126 422 parlants ; pages système 503 ; CLAUDE.md 238→179 l.) |

---

## Tableau de couverture

| Axe | Couverture | Détail |
|---|---|---|
| Documentation | ✅ couvert | statique + 6 sondages (6/6 EXACT) ; DOC-31/32/33 re-vérifiés un à un ; SHA snapshot re-calculé conforme |
| Besoin produit | ✅ couvert | roadmap/état-des-lieux vs livré ; 114 commits tous tracés |
| Code backend | ✅ couvert | statique ; RLS relu aux migrations des 6 tables nouvelles ; surface matchs + trajet suivie de bout en bout |
| Code engine | ✅ couvert | statique ; contrat 2.15/2.16 suivi parse→pose→diagnostic ; 13 clés parsées toutes appliquées ; déterminisme relu |
| Code frontend | ✅ couvert | `tsc -b --force` EXÉCUTÉ (0 erreur) + `vitest run` EXÉCUTÉ (**2184 tests / 237 fichiers, 100 % verts au run 1**, image tooling rebâtie avant) |
| Supply chain | ✅ couvert | npm `--omit=dev` + composer audit + pip-audit exécutés : **0 vuln ×3** |
| Cybersécurité — surface d'attaque | ✅ couvert | A1–A20 re-verdictés sur la surface neuve + **nouvelle ligne A21** (empoisonnement communautaire) — premier verdict non-protégé de la série |
| Infra / Mercure | ✅ couvert | compose dev relu (hub non-anonyme, CORS borné, 127.0.0.1) ; prod inchangé depuis 08-19 (seul deploy.yml +8 l. system-pages, relu) |
| Prod-readiness / observabilité | ✅ couvert | Sentry inchangé ; `.env.prod` template re-lu (0 secret) ; **pages système 503/maintenance livrées** (Caddy, hors Docker) |
| RGPD | ✅ couvert (statique) | mécanismes inchangés ; périmètre PII étendu d'un champ : `Coach.isVehicled` (mode de déplacement — bool, jamais au-delà du moteur) |
| Performance solveur | ✅ couvert | `smoke-solver.sh` EXÉCUTÉ : PASS, COMPLETED score **21640** (21646 au 08-19 — stabilité remarquable) ; `onboarding-smoke.sh` PASS (COMPLETED 9025) |
| Alignement contraintes 3 couches | ✅ couvert | ALIGN-08/09 résorbés vérifiés ; spot-check indépendant `forcedDays`/`maxConsecutiveDays`/`travelTime` × 3 couches : 3/3 alignés côté GÉNÉRATION — la scission trouvée est côté VERDICT (ENG-36) |
| **Restauration de backup (restore drill)** | ✅ couvert — **NOUVEL AXE** | pg_dump (1,07 Mo) → restauration dans une base temporaire → parité complète : 66 tables, 74 clubs, **91 policies RLS restaurées, flags FORCE identiques** → drop. La chaîne backup→restore fonctionne |
| UX-Cohérence | ✅ couvert | collecte statique exhaustive (primitives, tokens, terminologie) |
| UX-Simplicité / Intuitivité | ✅ couvert | proxys statiques + **parcours navigateur réel** (register multi-étapes → vérif email Mailpit → approbation → wizard → gate matchs → cockpit) — l'axe dynamique rouvre après 2 éditions |
| Inclusivité-a11y | 🟡 partiel | statique + **clavier réel** (2 trails de focus relevés, anneau visible partout) + 2 thèmes capturés ; pas de lecteur d'écran |
| Coûts / scalabilité financière | ⬜ non couvert (pas de données réelles) | ligne permanente — aucune donnée de facturation/infra prod. À noter : un harnais `backend/scripts/load-test/` existe (P5-4, #542) mais n'a pas été lancé cette édition |

---

## Synthèse des notes

| Critère | 2026-08-19 | **2026-08-27** |
|---|---|---|
| 1. Documentation | 89 | **90** |
| 2. Pertinence du besoin | 92 | **93** |
| 3a. Code backend | 82 | **84** |
| 3b. Code engine | 91 | **87** |
| 3c. Code frontend | 86 | **86** |
| 4. Supply chain | 96 | **96** |
| 5. Performance solveur | 90 | **90** |
| **État global (pondéré)** | 87 | **87** |

Pondération inchangée : doc 10 % · besoin 10 % · backend 25 % · engine 20 % · frontend 15 % · supply 5 % · perf 7,5 % · UX 7,5 %.
Calcul = 90·.10 + 93·.10 + 84·.25 + 87·.20 + 86·.15 + 96·.05 + 90·.075 + 75·.075 = **86,8** → **87**. Malus transversal 0.

⚠ L'engine **descend** (91 → 87) pendant que le backend remonte : la scission génération⇄verdict sur la règle de trajet (ENG-36, **Élevée confirmée** — le premier finding ≥ Élevée depuis deux éditions) est exactement le motif « déclaré ≠ effectif » que la série traque, revenu par la porte du rail verdict. Le backend remonte (82 → 84) parce que ses quatre findings ouverts sont soldés avec preuve en base et que la surface neuve est née propre — mais l'architecture continue de se dégrader (god-services, voir registre).

### Score UX (axe additif — noté À PART, sévérité extrême)

| Sous-axe | 08-19 | **08-27** | Plafond appliqué |
|---|---|---|---|
| UX-Cohérence | 84 | **75** | **UXC-18 + UXC-19 (Moyens confirmés)** → plafond 75 |
| UX-Simplicité & Intuitivité | 80 (partiel) | **75** | **UXS-05 (Moyen confirmé)** → plafond 75 ; axe redevenu COUVERT (navigateur) |
| Inclusivité / a11y | 78 (partiel) | **80** | aucun ≥ Moyen ; résidus Faibles (A11Y-16/17/18/19) ; clavier vérifié en réel |
| **Score UX général** | 78 | **75** | = le PLUS BAS des sous-axes couverts |

**Lecture rapide.** Édition du **retour du registre qui mord** : après une édition 08-19 à zéro nouveau ≥ Élevée, celle-ci en produit un (ENG-36) — et c'est la plus grosse livraison du delta (la matrice de trajet) qui le porte. Le motif est instructif : la règle `travelTime` est **irréprochable côté génération** (opt-in strict, MANDATORY dur, PREFERRED soft, diagnostics français, falsifiée dans les deux sens) mais **absente du verdict `/validate-assignments`** — un déplacement manuel peut créer l'enchaînement que la génération interdit, et l'app dit « valide ». En face, la moisson de corrections est la plus large de la série : **21 findings de l'édition précédente corrigés ou fermés** (BCK-14/15/16/17, ENG-32/33/35, FRT-23/24/25/26, DOC-31/32, UXC-11/14/15/16/17, A11Y-15, ALIGN-08/09), plusieurs tagués `AUD-*` dans le code. Le frontend franchit 2184 tests (+561) verts au premier run. La nouvelle surface UX (module matchs) est au-dessus du niveau moyen du dépôt en a11y mais introduit trois défauts Moyens de cohérence/simplicité qui plafonnent le score UX à 75. Les deux chantiers structurels restent : **la parité du verdict** (ENG-36/38 — il juge sans connaître deux familles de règles) et **les god-services backend** qui enflent à chaque lot (+333/+803 lignes, 44 fichiers > 300).

---

## Registre des findings

### Findings de l'édition précédente — statuts

| ID | Titre | Zone | Gravité | Vérif | **Statut** |
|---|---|---|---|---|---|
| DOC-31 | Garde de fraîcheur ampute les docs de zone | doc | Moyenne | contre-vérifié | **corrigé + MÉCANISÉ** — `DocStampFreshnessTest.php:53-61` : WATCHED en globs de zone (`backend/docs/*.md`, `engine/`, `frontend/`), finding cité :42-47, P4-120 soldée ; `constraint-emission.md:3` et `constraint-vocabulary.md:3` stampés |
| DOC-32 | CLAUDE.md décrit un proxy /engine disparu | doc | Faible | confirmé | **corrigé** — CLAUDE.md affirme l'inverse (« aucun proxy /engine nulle part ») et le code le confirme (`vite.config.ts:58`, `nginx.conf:96`, sentinelles DOC-32) |
| DOC-33 | Stamps-fleuves ~9 Ko | doc | Faible | confirmé | **partiel** — plus d'empilement vertical (1 ligne par doc), MAIS la ligne d'`engine-inventory.md:3` fait ~1,5 Ko avec deux « passe précédente » (fleuve devenu horizontal → DOC-35) et le motif renaît à plus grande échelle dans le meta-changelog OpenAPI (→ DOC-34) |
| BCK-15 | Cascade TEAM/COACH jamais exécutée en base | backend | Moyenne | contre-vérifié | **corrigé** — `DeletionImpactParityTest.php` (860 l.) : `forTeam` l.282 + **exécution réelle** `purgeChildrenOfTeam` + assertions en base l.294-305 (duo/trio de mutualisation tués, groupe tiers survit) ; `forCoach` l.423-432 ; rotations l.455-478 |
| BCK-14 | Repli enum silencieux CalendarEntry | backend | Faible | confirmé | **corrigé** — `CalendarEntryStateProcessor.php:572,581,590` : `tryFrom(...) ?? throw $this->unknownEnumValue(...)` ×3 ; test dédié `CalendarEntryEnumParsingTest` |
| BCK-17 | Résidus rigueur tenant (seasonId seul, fail-open) | backend | Faible | confirmé | **corrigé + tagué** — `DeletionImpactCounter.php:150-158` clubId explicite (commentaire AUD-BCK-17) ; `denyForeignClub` → 400 fail-closed ; patron propagé aux contrôleurs neufs |
| BCK-16 | N+1 SharedTrainingGroupStateProvider | backend | Info | confirmé | **corrigé** — `IN (:groupIds)` batch (`:99`) ; même idiome dans le provider rotation |
| ENG-32 | constraints.py 3581 lignes | engine | Faible | confirmé | **corrigé (refactor)** — paquet `app/solver/constraints/` (7 modules 388-841 l., orchestrateur documenté) ; résidu : `result_builder.py` 1931 / `objective.py` 1524 → ENG-39 Info |
| ENG-33 | Sémaphore partagé placement⇄verdict | engine | Faible | confirmé | **corrigé** — `_verdict_semaphore` dédié (`main.py:142`), réglage justifié et résidu assumé (`core/config.py:39-59`) : trois rails, trois budgets |
| ENG-35 | Repli silencieux contract_version 2.0 | engine | Info | confirmé | **corrigé** — `read_contract_version()` lève `RuntimeError` si le fichier manque (`main.py:175-182`), raisonnement écrit |
| ENG-34 | Limites déterminisme assumées (8 workers >200) | engine | Info | confirmé | **inchangé, assumé** — politique intacte (`main.py:445-451`), goldens larges = score+compte |
| FRT-25 | Flakiness vitest seuil 5000 ms | frontend | Faible | exécuté | **corrigé** — `vitest.config.ts:25` `testTimeout: 15_000` ; run 2184 tests sans un timeout |
| FRT-24 | Phrase tournante dans aria-live | frontend | Info | confirmé | **corrigé** — seul le titre stable est en `role="status"`, la phrase tournante `aria-hidden` (`GenerationWaiting.tsx:40-45`) |
| FRT-23 | ExportMenu role=menu sans sémantique | frontend | Faible | confirmé | **corrigé** — disclosure `role="group"`+`aria-expanded` ; et nouvelle primitive `ui/menu.tsx` = vrai pattern APG (flèches cycliques, Escape, focus) |
| FRT-26 | scope="colgroup" au lieu de rowgroup | frontend | Info | confirmé | **corrigé** — `ClubViewTable.tsx:169` `scope="rowgroup"`, 0 colgroup résiduel |
| FRT-21 | Frontières internes non policées | frontend | Faible | confirmé | **partiel** — shared→features **prod = 0** + règle ESLint `no-restricted-imports` (`eslint.config.js:92-103`, P4-123) ; MAIS cycles inter-features sans garde : wizard⇄cockpit 22/6, wizard⇄planning 12/6, planning⇄cockpit 5/19 |
| FRT-22 | Types API tripliqués à typage inégal | frontend | Faible | confirmé | **partiel** — Team/Venue/Coach toujours ×3 ; wizard a gagné l'enum `TeamLevel`, mais `matches/api.ts:218` garde `level: string \| null` hors filet |
| FRT-20 | Hook-mocking au lieu du patron API | frontend | Faible | confirmé | **ouvert, s'étend avec la surface** — 38 fichiers mockent api/hooks ; le neuf hérite (`TravelMatrixModal.test.tsx:16-21` mocke `../queries` entier) |
| UXC-10 | Empty states inline | ux | Faible | confirmé | **ouvert** (~17 hors admin, dont 3 sur la surface neuve) ; plusieurs sont des « aucun résultat de filtre » défendables |
| UXC-11 | Tutoiements résiduels | ux | Faible | confirmé | **corrigé** — grep tutoiement sur strings visibles = zéro |
| UXC-12 | Console superadmin hors design system | ux | Faible | confirmé | **ouvert, grossi** (563 occ. de couleurs brutes dans `features/admin/`) — persona fondateur, pondération faible maintenue |
| UXC-14 | Amber/green en dur rail cockpit | ux | Faible | confirmé | **corrigé (surface club)** — tokens `--warning`/`--success` posés aux 5 sites (`MonthCalendar.tsx:100,123`, `WindowAlreadyPlannedNotice.tsx:16`, `new-password-fields.tsx:47`) |
| UXC-15 | Tooltip tutoiement+jargon | ux | Faible | confirmé | **corrigé** — `DayDialog.tsx:259` vouvoiement, zéro jargon |
| UXC-16 | « verrou HARD » exposé | ux | Faible | confirmé | **corrigé** — HARD n'existe plus qu'en commentaire ; `SlotDetail.tsx:87` traduit « obligatoire » |
| UXC-17 | EmptyState local non promu | ux | Info | confirmé | **corrigé** — promu `ui/empty-hint.tsx:35`, consommé par PlanningPage et MatchesPage |
| A11Y-15 | Cible 16 px SlotReservationModal | ux | Faible | confirmé | **corrigé** — `p-1`+`size-4` → 24×24 px (le minimum exact WCAG 2.5.8) |
| A11Y-16 | text-[10px] généralisé | ux | Info | confirmé | **ouvert, assumé** — 22 occ./7 fichiers, dont 6 NEUVES (matches `WeekendGrid` ×3, `TypicalWeekendGrid` ×2, `ConflictRadar.tsx:177` 0.65rem) |
| ALIGN-08 | « Pas 3 entraînements d'affilée » angle mort triple | align | Moyenne | contre-vérifié | **corrigé (P2-42 livré 2026-08-19)** — règle implicite `maxConsecutiveDays` DURE : parse (`parsing.py:66-90`, OFF par défaut) → pose fenêtre glissante (`wellness.py:494-529`, HARD `sum(fenêtre) <= n-1`) ; coverage doc :96 barré |
| ALIGN-09 | forcedDays : l'engine sait, le wizard n'expose pas | align | Faible | confirmé | **corrigé (livré 2026-08-23)** — mode wizard « au moins une séance tel jour », engine `model.Add(sum(forced_day_vars) >= 1)` (`targeting.py:213`) ; **décision fermée** : `preferredDays` volontairement non exposé (état des lieux) |

**Bilan reprise : 21 corrigés/fermés** (dont ALIGN-08/09 par livraison produit) · 4 partiels (DOC-33, FRT-21/22, et FRT-20 qui s'étend) · 3 ouverts (UXC-10, UXC-12, A11Y-16 assumé) · aucune régression sur un finding corrigé.

### Nouveaux findings (cette édition)

| ID | Titre | Zone | Gravité | Vérif | Statut |
|---|---|---|---|---|---|
| **ENG-36** | **La règle travelTime est absente du verdict `/validate-assignments`** : le backend envoie la matrice (`MoveSlotService.php:427-429`), le schema l'accepte (`validate_input_schema.py:89`, annoté « ACCEPTÉ mais NON consommé ») mais `_apply_hard` (`solver/validate_assignments.py:236-252`) ne passe jamais `venue_travel_times` — sous **Obligatoire (MANDATORY)**, un déplacement manuel créant un enchaînement au battement interdit DUR est jugé « valide ». La plomberie côté pose existe et attend (`travel.py:298-299` documente le chemin verdict, `compromise.py:161` sait rendre FAMILY_TRAVEL) ; 0 test verdict×travel. Même motif que les miroirs sharedTrainings/teamLinks déjà comblés — scission génération⇄verdict sur l'axe « constraint semantics » (§7.1) | engine | **Élevée** | **confirmé (contre-vérifié à la main : grep travel = 0 hit dans le module verdict)** | nouveau |
| **BCK-19** | **God-services : l'aggravation continue malgré la reco P2 du 08-19** — `CustomRoutesOpenApiFactory` 2513→**2846** (+333), `BcclSeeder` 1482→**2285** (+803), `FbiFixtureImporter` **1445** et `ScheduleConstraintBuilder` **1413** entrent en zone rouge, 38→**44** fichiers src/ > 300 l. Croissance mécanique à chaque lot (sa doc OpenAPI + son seed), aucun démembrement engagé | backend | **Moyenne** | **confirmé (wc -l re-exécuté)** | nouveau (tendance BCK-04) |
| **FRT-27** | **Saisie de cellule de la matrice de trajet en échec silencieux** : `commitCell` (`TravelMatrixModal.tsx:192-204`) mutate sans `onError`, et les hooks `useCreateVenueTravelTime`/`useUpdateVenueTravelTime` (`wizard/queries.ts:170-183`) n'en ont pas — contrairement au standard matches (toast systématique). Un 422/409/réseau sur une minute saisie ⇒ rien à l'écran, valeur affichée ≠ serveur jusqu'au refetch ; la saisie hors bornes est aussi restaurée sans message (`:93-96`) | frontend | **Moyenne** | **confirmé (contre-vérifié à la main)** | nouveau |
| **DOC-34** | **`openapi-snapshot.meta.md` = journal non borné de 61 Ko** (101 entrées datées du 07-06 au 08-26) dans un fichier dont le rôle est date+SHA+delta — le motif DOC-33 réincarné à plus grande échelle, doublonnant la vocation d'`etat-des-lieux.md` | doc | **Moyenne** | **confirmé (wc -c re-exécuté)** | nouveau |
| **UXC-18** | **4 mots pour le même concept dans le module matchs** : rail « **Litiges** (n) » (`loopSteps.ts:120`), carte « **Diagnostic** » (`ConflictRadar.tsx:124`), corps « **conflit** » (`:90,132`), bandeau « n **signalements** hors semaine — voir les litiges » (`MatchesPage.tsx:478`) — un gestionnaire lambda doit apprendre que c'est la même chose | ux | **Moyenne** | **confirmé** | nouveau |
| **UXC-19** | **Dates ISO brutes exposées au gestionnaire** : `2026-09-12` tel quel à `PlacementPanel.tsx:146` (ligne « Date ») et `UnplacedList.tsx:45`, alors que `frDate` est utilisé juste à côté (`AwayList.tsx:59`, `FbiEntryList.tsx:122,192`) | ux | **Moyenne** | **confirmé** | nouveau |
| **UXS-05** | **Échec de chargement rendu comme vide sur MatchesPage** : seul `conflicts.isError` est géré (`:303`) ; un échec fixtures/teams/venues affiche l'EmptyState « Aucun match importé » (`:343`) — échec confondu avec vide, contraire à la doctrine du dépôt (« CHARGER ≠ ÉCHOUER », `readState`) | ux | **Moyenne** | **confirmé** | nouveau |
| BCK-18 | **Empoisonnement communautaire des échéances** : `shared_competition_deadline` écrit last-write-wins par tout gestionnaire de tout club (`CompetitionEntryDeadlinesController.php:127-131`), sans provenance ni historique — une date fausse est servie en pré-remplissage à tous. Atténué : valeur club souveraine, management-gated, GRANT sans DELETE ; mais l'abus est indiagnosticable (aucune trace de qui a posé quoi) | backend | Faible | confirmé | nouveau |
| BCK-22 | **Rafale IGN synchrone dans la requête** : l'autofill fait jusqu'à 240 appels sortants (120 paires × 2 profils, fenêtres de 8) dans la requête HTTP, sans timeout global — latence utilisateur potentielle > 30 s (timeout par appel 5 s). Cap + rate-limit 10/h/user posés, mais l'expérience du pire cas n'est pas bornée | backend | Faible | confirmé | nouveau |
| ENG-37 | **Géométrie du trajet dupliquée** : le diagnostic (`result_builder.py:~1195-1204`) ré-implémente `_too_tight`/`_baro` au lieu de consommer la « SOURCE UNIQUE » de `travel.py:172-174` — une évolution du prédicat fait diverger pose et diagnostic en silence | engine | Faible | confirmé | nouveau |
| ENG-38 | **`venue_minimums` absent du verdict** : le plancher « au moins N séances à V » (HARD dans /generate) n'est ni posé ni miroité dans `_apply_hard` — antérieur à 2.16, même classe qu'ENG-36 ; le verdict accumule des asymétries sans registre de ce qu'il ne juge PAS | engine | Faible | confirmé | nouveau |
| ENG-39 | Résidu ENG-32 : `result_builder.py` 1931 l., `objective.py` 1524 l. | engine | Info | confirmé | nouveau |
| FRT-28 | Types manuels des nouvelles API (deadline-outlook, validate-impact, ffbb/rencontres, venue_travel_*) sans filet de forme côté front — dérive détectable seulement à l'usage (recoupe FRT-22) | frontend | Faible | confirmé | nouveau |
| FRT-29 | 6 warnings `react-refresh/only-export-components` (ModuleVisitBanner, ImplicitRulesPanel ×3, modal) — bruit DX, HMR dégradé | frontend | Info | exécuté | nouveau |
| BCK-20 | PUT partiel `VenueTravelTimeInput` : null = inchangé → impossible de vider UNE colonne d'un barème (seul le DELETE de la ligne existe) | backend | Mineure | confirmé | nouveau |
| BCK-21 | `GET /validate-impact` : chemins 404/400/403 du contrôleur sans test nommé (le prédicat est couvert par ailleurs) | backend | Mineure | confirmé | nouveau |
| UXS-06 | Étape « Contraintes » affichée ✓ sur un club VIERGE pendant que Gymnases/Coachs sont verrouillées (constaté au parcours navigateur, wizard neuf) — « fait » avant les préalables déroute | ux | Faible | confirmé (navigateur) | nouveau |
| A11Y-17 | `AlertTriangle text-warning` « hors fenêtre ligue » sans nom accessible ni texte (`WeekendGrid.tsx:138`), alors que le `Lock` voisin :137 est labellisé | ux | Faible | confirmé | nouveau |
| A11Y-18 | État « fait » porté par le seul emoji ✅ (`DayDialog.tsx:461`, `RadarPanel.tsx:574`) là où les autres états ont un texte | ux | Faible | confirmé | nouveau |
| A11Y-19 | Étape `locked` du StepRail : bouton disabled + icône `Lock` sans explication du pourquoi (et `Lock` sans `aria-hidden`, `step-rail.tsx:71-74`) | ux | Faible | confirmé | nouveau |
| ALIGN-10 | Angle mort persistant : « minimum de séances GARANTI » — `MIN_SESSIONS` reste une cible soft (`constraint-coverage.md:75,99`, hérite d'ENG-18) ; à trancher plancher dur (risque INFEASIBLE) ou abandon documenté | align | Faible | confirmé (coverage doc) | nouveau (rendu redevable) |
| DOC-35 | Stamp-fleuve horizontal : la ligne unique d'`engine-inventory.md:3` (~1,5 Ko) empile deux passes précédentes — résidu DOC-33 | doc | Faible | confirmé | nouveau |
| DOC-36 | CLAUDE.md §2 : la ligne engine du tableau omet `/validate-assignments` (le §6 et le code en comptent trois) | doc | Mineure | confirmé | nouveau |

**1 nouveau ≥ Élevée (ENG-36)** — la série de deux éditions sans s'arrête ; les 5 Moyennes sont contre-vérifiées à la main.

---

## Tableau de posture cybersécurité (A1–A21)

| # | Attaque | Verdict | Preuve `fichier:ligne` | SEC- |
|---|---|---|---|---|
| A1 | Accès cross-tenant | **protégé** | 6 tables neuves avec `club_id NOT NULL` + ENABLE+FORCE RLS + policies tenant_isolation/admin_all **dans la migration** (`Version20260824120000/130000`, `…0825120000`, `…0826130000/140000`) ; entités toutes `TenantOwnedInterface` ; exception ASSUMÉE : `shared_competition_deadline` globale par conception (GRANT sans DELETE, `EntryDeadlineShareTest`) ; venues/teams re-résolus tenant-scoped dans les processors neufs | — |
| A2 | Brute-force /login | **protégé** | inchangé (`security.yaml:31-32` max 5 ; admin 5/15 min) | — |
| A3 | Énumération de comptes | **protégé** | inchangé — `PasswordResetEnumerationTest` + `RegisterTurnstileTest` bloquants ; vérifié en réel au parcours : register → 202 neutre + page d'attente | — |
| A4 | Falsification JWT | **protégé** | RS256 ; `git ls-files backend/config/jwt` = **0** (re-exécuté) | — |
| A5 | Escalade de privilège | **protégé** | écriture=management central (`AbstractStateProcessor.php:89`) ; surface neuve : `assertManager()` en tête des routes FFBB/autofill/deadlines, lecture membre explicite (validate-impact, deadline-outlook) | — |
| A6 | Mass-assignment | **protégé** | DTO neufs sans clubId/seasonId bindable (`VenueTravelTimeInput` = venueAId/venueBId/minutes 1-240 ; `VenueTravelRuleSettingInput` = intensity seule, `Assert\Choice` dérivé d'enum) | — |
| A7 | Injection SQL | **protégé** | Doctrine paramétré ; SQL neuf relu (index unique partiel `uniq_fixture_ffbb_rencontre`, UPDATE DQL du marker paramétré) | — |
| A8 | XSS stockée/reflétée | **protégé** | `dangerouslySetInnerHTML` : **0 en source** (1 hit = commentaire de test anti-régression, `FeedbackSection.test.tsx:157`) — re-vérifié | — |
| A9 | CSRF | **protégé** | cookie `SameSite=Strict` re-vérifié (`JwtCookieFactory.php:54,71`, `lexik_jwt_authentication.yaml:12`) | — |
| A10 | DoS bombe de génération | **protégé** | inchangé (ComplexityGuard + caps engine) ; surface neuve bornée : autofill cap **120 paires vérifié AVANT tout appel réseau** (`VenueTravelTimeAutofillService:27,86-87`) + rate-limit 10/h/user consommé APRÈS résolution de contexte (`VenueTravelTimeAutofillController:59-63`) ; matrice cap 150 côté engine (`input_schema.py:270-284`) | — |
| A11 | Spam routes anonymes | **protégé** | inchangé ; seule route « publique » neuve = `DevDemoRegisterController` : **kernel.debug → 404 en prod** + ProdSecretGuard + email démo seul + mot de passe VÉRIFIÉ + rate-limit register + audit trail (docblock :32-59) | — |
| A12 | SSRF | **protégé** | surface sortante DOUBLÉE et tenue : `BanGeocodingClient.php:24` et `IgnRoutingClient.php:27` — hosts en dur, `max_redirects: 0`, timeout 5 s, query bornée 3-200 / coords range-validées formatées `%.6F` serveur (jamais une chaîne user dans l'URL) ; FFBB inchangé | — |
| A13 | Abus upload logo | **protégé** | inchangé (allowlist binaire + finfo + 500 KB) | — |
| A14 | Fuite Mercure | **protégé** | dev relu : non-anonyme, `cors_origins` bornés localhost, port 127.0.0.1 (`docker-compose.yml:329-336`) ; prod inchangé depuis 08-19 (`MercureHardeningTest` bloquant) | — |
| A15 | Exposition de secrets | **protégé** | `git ls-files` re-exécuté : 0 clé ; `.env.prod` re-lu = template (noms en commentaire seulement, `:15-19`) | — |
| A16 | Erreurs verboses | **protégé** | inchangé ; pages système 503/maintenance livrées (servies par Caddy hors Docker — l'écran blanc 50x de P5-16 a maintenant sa réponse d'edge) | — |
| A17 | Clickjacking / en-têtes | **protégé** | `csp.conf`/`security-headers.conf` : aucune modification substantielle depuis 08-19 (commits = commentaires DOC-32) | — |
| A18 | Dépendance vulnérable | **protégé** | **0 vuln aux 3 audits exécutés ce jour** (npm --omit=dev · composer · pip-audit, paquet local skippé comme toujours) | — |
| A19 | Usurpation d'approbation de club | **protégé** | inchangé (token 32 octets, 404 byte-identique, rate-limit IP) ; vérifié en réel : ARA inconnu → page « En attente du club » claire, approbation par relais dev 404-en-prod | — |
| A20 | Compromission chaîne de déploiement | **protégé** | seul changement depuis 08-19 : +8 l. system-pages dans `deploy.yml` (#695) — mêmes `$DEPLOY_PATH` validés en amont, single-quotes côté remote, dépôt atomique `.new`→bascule ; relu | — |
| **A21** | **Empoisonnement de données communautaires** — un gestionnaire authentifié d'un club X pousse une échéance fausse dans `shared_competition_deadline`, servie en pré-remplissage à tous les clubs — *nouvelle ligne : première table cross-tenant partagée du produit* | **partiel** | écriture management-gated (`CompetitionEntryDeadlinesController.php:127-131`), valeur club souveraine, GRANT sans DELETE (revue 2026-08-25 F-1) ; **manque : aucune provenance/trace → abus indiagnosticable, pas de rate-limit dédié à l'écriture partagée** | BCK-18 |
| **Bilan cyber : 20 protégé · 1 partiel · 0 absent · 0 non vérifié.** Première ligne non-« protégé » de la série — née avec la première donnée volontairement partagée entre tenants. Vs 08-19 : 20/0/0 → 20/1/0 (la surface a encore grandi : 2 hosts sortants, 6 tables, 1 route debug). |

---

## Détail par critère

### 1. Documentation — 90/100 (exactitude 95 · structure 82 · utilité IA 88 · cycle specs 97)
**Forces.** 6/6 sondages EXACT (contrat 2.16 ×3 endpoints ; priorité 7 + retour /api/admin ; zéro proxy /engine avec sentinelles ; boundaries au grep des imports ; Makefile ; marque hors littéral). Le cycle specs digère 114 commits sans un trou : chaque livraison RMM/P2-53/P4-12x tracée et datée dans l'état des lieux, compteur roadmap 47=47, initiales figées, **aucun livré résiduel en roadmap**. DOC-31 corrigé en globs de zone ; la compression CLAUDE.md 238→179 a tenu (liste blocking-tests dans sa maison unique gardée). Le refactor engine est documenté à l'unisson (chemins de modules vérifiés réels).
**Faiblesses.** DOC-34 (meta-changelog 61 Ko — la seule vraie tache structurelle) ; DOC-35 (stamp horizontal 1,5 Ko) ; DOC-36 (tableau §2 : 2 endpoints sur 3).

### 2. Pertinence du besoin — 93/100
Le programme RMM (10 lots) est CLOS : la boucle hebdo guidée par rail dérivé « premier trou », la réconciliation FBI à deux canaux, les échéances qui remontent au cockpit — tout cela répond à des verbatims BCCL datés. Deux angles morts d'alignement (ALIGN-08/09) fermés **par des livraisons produit**, pas par des notes. La matrice de trajet honore le retour terrain Vaulx (21 équipes, 4-5 gymnases). Réserve constante : P5-13 (reproduire les 3 plannings réels) reste le juge de paix, et la landing n'est toujours pas publiée (le geste d'ops attend).

### 3a. Code backend — 84/100 (correction+sécu 90 · architecture 72 · tests 86 · robustesse 84)
**Forces.** Les 4 findings ouverts soldés avec preuve en base et tags `AUD-*` dans le code. Discipline RLS devenue réflexe (6 tables neuves parfaites dès la migration). Parité par construction généralisée (`OrphanedFixtureFinder` sert l'annonce ET la gâchette ; `FixtureVenueLossMarker` foyer unique). Clients sortants exemplaires. Les revues sécurité internes (F-1/F-2) sont intégrées au flux et citées dans le code. 32 fichiers de test neufs, chemins 422/409/403 couverts.
**Faiblesses.** BCK-19 : l'architecture se dégrade PENDANT que tout le reste s'améliore — 2846 l. pour une factory OpenAPI, 2285 pour un seeder, 44 fichiers > 300 l., et la croissance est mécanique (chaque lot nourrit les mêmes fichiers). BCK-18 (provenance absente de la première table partagée), BCK-22 (rafale IGN synchrone), dépointage par UPDATE DQL hors verrou optimiste (assumé, documenté).

### 3b. Code engine — 87/100 (correction+sécu 82 · architecture 90 · tests 90 · robustesse 90)
**Forces.** Le refactor monolithe→paquet est propre (7 modules, DAG documenté, seam de test explicité) et les 3 findings fermés le sont avec le raisonnement écrit à côté du réglage. La règle travelTime côté génération est un modèle : opt-in strict falsifié dans les deux sens, MANDATORY dur / PREFERRED −6 / départage en sous-bande lexicographique prouvée non-dominante, diagnostics français nommant le coach. 13 clés parsées, 13 appliquées, zéro orpheline. Harnais = chemin prod.
**Faiblesses.** ENG-36 (Élevée) : le verdict ne connaît pas la règle que la génération vient d'apprendre — et ENG-38 montre que c'est structurel (venue_minimums manquait déjà) : `/validate-assignments` reconstruit sa parité règle par règle au lieu d'en hériter, sans registre de ce qu'il ne juge pas. ENG-37 (géométrie dupliquée entre pose et diagnostic). Deux fichiers > 1500 l. subsistent.

### 3c. Code frontend — 86/100 (correction+sécu 88 · architecture 84 · tests 85 · robustesse 86)
**Forces.** Exécuté : tsc 0 erreur, **2184 tests / 237 fichiers 100 % verts au run 1** (+561 tests en 8 jours, ratio quasi 1:1 sur matches). 4 findings a11y/flakiness soldés avec l'ID d'audit en commentaire. Frontière shared→features fermée ET outillée (ESLint). Primitives à contrat écrit (StepRail, Menu APG, Modal focus-trap). La discipline d'erreur des mutations matches est exemplaire (toasts FR spécifiques + invalidations croisées).
**Faiblesses.** FRT-27 : le même dépôt qui fait des toasts partout a laissé les 3 hooks de la matrice sans `onError` — échec silencieux sur un écran de saisie. Cycles inter-features toujours sans garde (la règle ESLint s'arrête à shared/). Triplication Team/Venue/Coach persistante, `level: string` hors filet. Hook-mocking qui s'étend avec la surface.

### 4. Supply chain — 96/100
0 vulnérabilité aux trois audits exécutés. Retenue inchangée : pas de SBOM, pip-audit ne couvre pas le paquet local.

### 5. Performance solveur — 90/100
`smoke-solver.sh` : PASS, COMPLETED score 21640 — à 6 points du run du 08-19 (21646) sur la même graine de données : la stabilité inter-éditions du solveur est elle-même un signal de santé. `onboarding-smoke.sh` : club minimal généré (9025) en 2 polls. Harnais de charge présent (`backend/scripts/load-test/`) mais non lancé — l'axe coûts reste non couvert.

### Cybersécurité — voir tableau A1–A21 : 20 protégé · 1 partiel
La surface a grandi sur trois fronts (2 hosts sortants gouvernementaux, 6 tables RLS, 1 route debug d'effet démo) sans qu'aucune ligne existante ne se dégrade. La ligne partielle (A21) naît avec la première donnée **volontairement** partagée entre clubs — le produit entre dans une classe de risque nouvelle (l'intégrité communautaire) qui appelle une provenance, pas un pare-feu.

### RGPD — couvert, sans finding nouveau
Mécanismes inchangés (effacement, export, purges, audit trail 12 mois). Périmètre PII étendu d'un champ : `Coach.isVehicled` (mode de déplacement d'une personne physique — booléen, consommé par le solveur seul, couvert par l'effacement coach existant). Les coordonnées de gymnases sont des données d'établissement, pas des PII. La table partagée d'échéances ne porte volontairement AUCUNE provenance (choix RGPD explicite) — c'est le revers de BCK-18, le compromis est documenté ici.

### UX — cohérence 75 · simplicité 75 · inclusivité 80 → général 75
**Le paradoxe de l'édition** : la surface neuve (module matchs, matrice) est la MIEUX construite du dépôt en primitives et en a11y (StepRail avec nom accessible enrichi, badges icône+texte partout, consentement d'autofill explicite, placement en 2 clics avec habitude préremplie, rail « premier trou » à 0 clic) — et c'est pourtant elle qui porte les trois Moyens qui plafonnent le score : 4 synonymes pour « litige » (UXC-18), des dates ISO brutes au milieu de dates françaises (UXC-19), un échec de chargement déguisé en « Aucun match importé » (UXS-05). Le **parcours navigateur réel** (rouvert après 2 éditions) a validé le rail d'entrée : register multi-étapes avec checklist de mot de passe vivante, erreur inline + toast actionnables, page d'attente d'approbation claire, gate « Lancez votre première génération d'abord » en toast explicite, focus clavier visible et logique partout, thème clair cohérent. Résidu dynamique : « Contraintes ✓ » sur club vierge (UXS-06).

---

## Avis global + axes priorisés

**L'édition du verdict aveugle.** Deux éditions durant, le motif « déclaré ≠ effectif » avait été chassé du code par des gardes structurelles — cette édition le retrouve, réfugié dans le seul endroit qui n'a pas de garde : la **parité génération⇄verdict**. La règle de trajet est parfaite à la génération et invisible au verdict (ENG-36) ; venue_minimums l'était déjà (ENG-38) ; et rien ne liste ce que `/validate-assignments` ne juge pas. La leçon des blocking-tests s'applique verbatim : il faut un **registre diffé par un test** — chaque contrainte HARD de /generate doit être posée, miroitée, ou EXPLICITEMENT déclarée hors-verdict. En face, la moisson est la plus large de la série (21 findings fermés, 2 par livraison produit), le frontend passe un cap d'échelle testée, et la posture cyber absorbe une surface qui double ses hosts sortants. Les deux dettes qui traversent les éditions se nomment toujours pareil : **god-services backend** (la reco du 08-19 n'a pas mordu — +1136 lignes sur les deux mêmes fichiers) et **cycles inter-features frontend**.

| Reco | Priorité | Effort | Traité |
|---|---|---|---|
| BCK-15 exécuter forTeam/forCoach en base + PruneStep | ~~P1~~ | — | ✅ (test le plus riche du dossier) |
| DOC-31 étendre WATCHED aux docs de zone | ~~P1~~ | — | ✅ mécanisé (globs) |
| ALIGN-08 cadrer « pas 3 d'affilée » dur | ~~P1~~ | — | ✅ livré (P2-42, wellness.py:494) |
| BCK-14 propager le throw enum à CalendarEntry | ~~P2~~ | — | ✅ |
| ENG-33 budget/sémaphore propre au rail verdict | ~~P2~~ | — | ✅ (3 rails, 3 budgets) |
| FRT-25 testTimeout vitest | ~~P2~~ | — | ✅ (15 s, 0 timeout sur 2184) |
| FRT-21 règles ESLint de frontière | ~~P2~~ | — | 🟡 **partiel** — shared↛features fermé + outillé ; les cycles inter-features restent ⬜ |
| FRT-22 dédupliquer les types API | P2 | M | ⬜ (reconduit ; `level: string` toujours hors filet) |
| Hook off-site backups (traverse les éditions depuis le 07-19) | P2 | S | ⬜ — le restore drill de cette édition prouve que la chaîne locale marche ; il manque toujours l'off-site |
| Geler CustomRoutesOpenApiFactory (découpe par domaine) | ~~P2~~ → **P1** | M | ⬜ **aggravé** (+333 l. depuis la reco ; BcclSeeder +803) — remonté P1 : la tendance ne s'inversera pas seule |
| **ENG-36 — passer `venue_travel_times` à `_apply_hard` + test verdict×travel (MANDATORY refusé, PREFERRED nommé en compromis)** | **P1** | S | ⬜ nouveau |
| **Registre de parité du verdict** : test diffant les familles HARD de /generate contre celles posées/miroitées par /validate-assignments, exceptions nominatives (patron BlockingTestsListMatchesCi) — solde ENG-38 et empêche le prochain ENG-36 | **P1** | M | ⬜ nouveau |
| **FRT-27 — onError (toast apiErrorMessage) sur les 3 hooks de la matrice** | **P1** | S | ⬜ nouveau |
| UXS-05 — readState/LoadErrorHint sur fixtures/teams/venues de MatchesPage | P2 | S | ⬜ nouveau |
| UXC-18 — trancher UN mot (« litige » ?) pour le concept litiges/diagnostic/conflit/signalement | P2 | S | ⬜ nouveau |
| UXC-19 — frDate sur PlacementPanel:146 et UnplacedList:45 | P2 | S | ⬜ nouveau |
| DOC-34 — borner le meta-changelog OpenAPI (garder N dernières entrées, l'historique vit dans git) | P2 | S | ⬜ nouveau |
| BCK-18 — provenance minimale sur l'échéance partagée (au moins un booléen « posé par mon club » + date, sinon décision fermée documentée) | P2 | S | ⬜ nouveau |
| ENG-37 — faire du diagnostic le 4ᵉ consommateur de la source unique travel | P3 | S | ⬜ nouveau |
| BCK-22 — timeout global d'autofill (ou bascule async si > seuil) | P3 | S | ⬜ nouveau |
| ALIGN-10 — trancher le plancher dur MIN_SESSIONS ou documenter l'abandon | P3 (produit) | M | ⬜ nouveau (hérite ENG-18) |
| Fond de sac : DOC-35/36 · BCK-20/21 · ENG-39 · FRT-28/29 · UXC-10/12 · UXS-06 · A11Y-16/17/18/19 · FRT-20 | P3 | S | ⬜ |

## Features intéressantes à développer (valeur/effort)

1. **Publier la landing** (P5-5) — troisième édition d'affilée où il ne manque QUE le geste d'ops (VM, DNS, Caddy — les pages 503 sont même prêtes) ; le levier commercial dort.
2. **Le registre de parité du verdict** (reco P1 ci-dessus) — pas une feature visible, mais la seule chose qui empêche chaque prochaine règle de reproduire ENG-36.
3. **Volet MATCHS de la matrice de trajet** (P2-54/RMM-9, `gestion-matchs-ffbb.md` §7) — l'empreinte AWAY siège↔ville adverse ; la matrice et l'écran existent, le solveur de placement est le seul à ne pas en profiter.
4. **Lancer le harnais de charge** (`backend/scripts/load-test/`) sur un scénario multi-club — il existe depuis P5-4 et n'a jamais nourri l'axe coûts ; premier pas vers la ligne permanente du tableau de couverture.
5. **Exploiter le mode démo en rendez-vous** — le rail complet existe (formulaire → club du prospect sous 4 refus) ; c'est un outil de vente livré qui n'a pas encore servi.

---

## Annexe méthodologie

**Exécuté** : `tsc -b --force` (0 erreur) + `vitest run` (2184 tests / 237 fichiers, 100 % verts run 1, image tooling rebâtie avant — via l'agent frontend) ; `npm audit --omit=dev` + `composer audit` + `pip-audit` (0 vuln ×3) ; `smoke-solver.sh` (PASS, 21640) ; `onboarding-smoke.sh` (PASS, 9025) ; **restore drill** (pg_dump 1,07 Mo → base temporaire → parité 66 tables/74 clubs/91 policies/flags FORCE → drop, tout nettoyé) ; **parcours navigateur** ×2 (chromium du projet — le MCP refait son faux « chrome pas installé » : register complet avec vérif Mailpit, approbation par relais dev, wizard, gate matchs, cockpit, 2 trails de focus clavier, 2 thèmes, 15 captures). **Statique** : backend, engine, doc, UX (agents lecture seule).

**Contre-vérifiés à la main (Étape 3)** : ENG-36 (grep travel = 0 hit dans `solver/validate_assignments.py`, schema :89 accepté-non-consommé lu) ; FRT-27 (hooks `wizard/queries.ts:170-183` lus, 0 onError) ; DOC-34 (`wc -c` = 61 359, 101 entrées datées) ; UXC-19 (PlacementPanel:146, UnplacedList:45 lus) ; UXS-05 (MatchesPage:303/343 lus) ; UXC-18 (les 4 libellés lus à leurs lignes) ; BCK-19 (`wc -l` re-exécuté : 2846/2285/1445/1413, 44 fichiers) ; alignement 3 couches (spot-check indépendant forcedDays/maxConsecutiveDays/travelTime aux 3 couches) ; A12 (les 2 clients géo lus en entier).

**Limites** : pas de lecteur d'écran (l'axe inclusivité reste partiel malgré le clavier réel) ; pas de phpunit/pytest lancés par l'audit lui-même (la CI de main était verte, et le smoke + onboarding exercent les deux stacks en vrai) ; le parcours navigateur s'est arrêté au wizard vierge (pas de génération complète au navigateur — elle est couverte par l'onboarding-smoke API) ; A20 vérifié sur le code du workflow, pas sur une exécution (toujours aucune VM) ; le harnais de charge existe mais n'a pas été lancé (coût machine locale).

**Confiance par axe** : élevée = frontend (exécuté), supply chain (exécuté ×3), perf (exécuté ×2), restore drill (exécuté), UX-simplicité (parcours réel + proxys), cyber (preuves recoupées + 2 smokes + parcours) · moyenne = backend, engine, doc, UX-cohérence (lecture de code, recoupée entre agents et contre-vérifiée sur toutes les Moyennes/Élevées) · faible = inclusivité dynamique (clavier seul, pas de SR).

**Auto-question de biais (honnête)** : (1) le poids d'ENG-36 repose sur ma lecture du flux MoveSlot→verdict — je n'ai pas REJOUÉ un déplacement violant en vrai ; un chemin de garde côté backend que je n'aurais pas vu affaiblirait la gravité (je n'en ai pas trouvé, et le commentaire du schema avoue « non consommé »). (2) Sur-poids du greppable, toujours : les verdicts cyber restent des lectures de constantes, pas des attaques simulées. (3) Le parcours navigateur s'est fait sur un club VIERGE — les écrans denses (planning généré, réconciliation avec écarts réels) n'ont été jugés qu'en statique ; les trois Moyens UX du module matchs pourraient être plus OU moins gênants en situation pleine. (4) Biais de continuité assumé : barème et pondérations repris tels quels. (5) Données manquantes : coûts réels, comportement multi-clubs simultanés, et le harnais de charge jamais couplé à l'audit.
