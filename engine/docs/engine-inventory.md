# Engine Inventory — Backward Spec

Last verified @ 2026-08-29 (ENG-39 fermé sans livraison le 2026-08-29, décision fondateur — `result_builder/diagnostics.py` reste à 1525 l., détail et preuves : `specs/courantes/etat-des-lieux.md` §2. Corrigé ce jour : §« Hard locks » citait encore `result_builder.py` — le monolithe a disparu au paquet depuis ENG-39, le diagnostic over-capacity vit dans `result_builder/diagnostics.py::_diagnose_conflicts`, corrigé. Non re-vérifié au-delà de ce point et de la ligne §1 sur le paquet `result_builder/` (déjà à jour, laissée telle quelle) : le reste de l'inventaire n'a pas été confronté ligne à ligne cette passe.)

> Inventaire BACKWARD de l'existant engine. Reflète le code lu au SHA ci-dessus, pas les features futures.
> Source de vérité : `engine/app/main.py`, `engine/app/schemas/input_schema.py`, `engine/app/schemas/output_schema.py`, `engine/app/solver/{model,constraints,objective,result_builder}.py`, `engine/app/core/config.py`.

---

## 1. Architecture Engine

- **Runtime** : Python 3.12.
- **Framework HTTP** : FastAPI (app construite dans `engine/app/main.py` via `get_settings()` → `app_name`/`app_version`).
- **Solver** : Google OR-Tools CP-SAT (`from ortools.sat.python import cp_model`).
- **Validation** : Pydantic v2 (`BaseModel`, `ConfigDict`, `Field`, `populate_by_name=True`).
- **Settings** : `pydantic-settings` (`engine/app/core/config.py`), prefix env `ENGINE_`, `.env` lu. Defaults : `app_name="engine"`, `app_version="1.0"`, `contract_version="2.0"`, `environment="dev"`, `log_level="info"`.
- **Contract version** : lu depuis `engine/CONTRACT_VERSION` (**fichier = `2.16`** — source de vérité, `read_contract_version()` dans `main.py`). ⚑ **Plus de fallback depuis le 2026-08-19 (audit ENG-35)** : le fichier manquant lève une `RuntimeError`, il n'est PAS remplacé par un défaut. L'ancien repli rendait « 2.0 », or le garde de contrat est MAJOR-only — un build amputé de son fichier passait donc le handshake et résolvait des payloads 2.12 en se croyant d'accord. Le réglage `settings.contract_version` a été supprimé avec lui (il n'existait que pour ce repli). Gardé par `tests/test_contract_version_doc_sync.py`. Bumps depuis 2.0 : **2.1** = fenêtres horaires d'indisponibilité coach (lot C, #195) ; **2.2** = second problème `/place-matches` (P1-4 PR D, ADR-0003) ; **2.3** = retrait de `maxDaysOverrideConfirmed` (P4-51 : un drapeau que rien ne lisait, schéma `forbid` donc rupture de recevabilité) ; **2.4** = troisième endpoint `/validate-assignments` (P2-2 F2a : verdict moteur sur un candidat, baseline figée via `add_fixed_slots`) ; **2.5** = retrait de `allowMultipleSessionsPerDay` (P4-79 : jumeau de 2.3 — un levier de solveur que rien n'écrivait, schéma `forbid` donc rupture de recevabilité) ; **2.7** = bloc optionnel `implicitRules` (P2-28 PR 1 : intensité HARD/PREFERRED + seuils des 4 règles de bien-être, diagnostic `implicit_rule_not_honored` + `ruleKey`, retrait du type mort `coach_no_rest_day`) ; **2.8** = cause STRUCTURÉE d'une séance manquante (P4-99 PR-1 : `session_below_effective_min` porte `causes[]` = `{kind, constraintId, label, count}` MESURÉE à la pose + `openCandidates`) ; **2.9** = retrait des champs morts `temporaryLock`/`temporaryLockFor`/`temporaryMinSessionsOverride` (jamais lus par le solveur, schéma `forbid` → leur présence dans un vieux payload devient un 422 : bump requis) ; **2.10** = compromis nommés sur `/validate-assignments` (P2-32 PR A : `reference` optionnel en entrée, `compromises[]` en sortie sur un candidat ACCEPTÉ — voir §POST /validate-assignments) ; **2.11** = champ optionnel `previousAssignments` (P3-21 PR A : terme de stabilité de génération plié dans la phase 2, INERTE tant que le backend ne l'émet pas — absent/vide ⇒ payload byte-identique, goldens et score inchangés) ; **2.12** = bloc optionnel `sharedTrainings` (P2-27 PR A : mutualisation — N équipes déclarées ensemble partagent EXACTEMENT K séances communes, réifiées dans les deux sens ; diagnostic `shared_training_not_honored` ; absent/vide ⇒ payload byte-identique) ; **2.13** = bloc optionnel `maxConsecutiveDays` dans `implicitRules` (P2-42 : « cette ÉQUIPE ne s'entraîne pas N jours d'affilée », 5e règle de bien-être et **première dont l'intensité par défaut est `OFF`** — absente du payload, elle n'est PAS appliquée, au contraire des quatre autres qui retombent sur HARD ; absent/vide ⇒ payload byte-identique, goldens et score inchangés) ; **2.14** = bloc optionnel `teamLinks` (lot PASSERELLES PR-1 : passerelles déclarées entre deux équipes {id, teamAId, teamBId, intensity PREFERRED/MANDATORY}, ACCEPTÉ mais **NON consommé** — la consommation entraînement est PR-2 ; absent/vide ⇒ payload byte-identique, goldens et score inchangés) ; **2.15** = bloc optionnel `slotRotations` (RMM-5 rotation A/B PR-2 : créneaux de match partagés {venueId, dayOfWeek, kickoff, teamIds} CONSOMMÉS en SOFT par `/place-matches` — attraction au créneau + protection de fenêtre, à parité stricte des habitudes ; absent/vide ⇒ payload byte-identique, goldens inchangés) ; **2.16** = bloc optionnel `venueTravelTimes` (matrice de trajet entre gymnases : {venueAId, venueBId, drivingMinutes?, walkingMinutes?}) + champ `isVehicled` sur le coach + règle implicite `travelTime` (P2-53 RMM-8 PR-2 : le trajet est CONSOMMÉ par le solveur d'entraînement — DÉPARTAGE « moindre trajet » en sous-bande de phase 2 [sous placement et chaînage, jamais dominant] + BATTEMENT insuffisant PREFERRED [compromis `travel_time`] / MANDATORY [interdit dur + diagnostic `travel_time_infeasible`] ; barème voiture/à pied selon `isVehicled`, à pied d'office pour une passerelle ; défaut 20 min ; OPT-IN à la présence de matrice ; absent/vide ⇒ payload byte-identique, goldens inchangés). — **UN SEUL contrat pour les TROIS endpoints**, tous vérifient le même MAJOR.
- **Structure interne** :
  - `app/main.py` — endpoints FastAPI + pipeline solver.
  - `app/core/config.py` — settings.
  - `app/schemas/input_schema.py` — `ScheduleInputSchema`.
  - `app/schemas/output_schema.py` — `ScheduleOutputSchema`.
  - `app/solver/model.py` — `ScheduleCpModel` (variables booléennes `x[team, venue, day, slot]`).
  - `app/solver/constraints/` — **paquet** (ENG-32, 2026-08-24 — l'ancien monolithe de 3 870 l. découpé par métier, surface d'import inchangée) : `parsing.py` (lecture du payload + règles implicites) · `structural.py` (overlap/capacité/verrous) · `wellness.py` (bien-être) · `targeting.py` (fenêtres/gymnases/mutualisation/passerelles) · `diagnostics.py` (explications post-solve) · `common.py` (types, constantes, normalisation) · `__init__.py` (façade de ré-export **+ l'orchestrateur `add_level_1_hard_constraints`** — il y vit par contrainte de COUTURE DE TEST : le test des règles implicites patche les poseurs via le namespace du paquet).
  - `app/solver/objective/` — objectif Level-2 (poids fixes T24), **paquet depuis ENG-39** : `weights` (tables/alias) → `normalise` (lecteurs) → `terms` (les `add_*`) ; l'agrégateur `__init__` ré-exporte tout (imports inchangés).
  - `app/solver/result_builder/` — solution → `ScheduleOutputSchema` + diagnostics, **paquet depuis ENG-39** : `helpers` → `slots` + `diagnostics` (les 13 `_diagnose_*`) ; l'agrégateur `__init__` ré-exporte tout (imports inchangés).
  - `app/solver/match_placement.py` — le SECOND problème (placement de matchs datés, ADR-0003).
  - `app/schemas/match_input_schema.py` / `match_output_schema.py` — ses schémas dédiés.
- **Port** : 8000 (conteneur Docker `engine`).
- **Commandes** : tout via `engine/Makefile` dans le conteneur (`make test`, `make lint`, `make exec`).

---

## 2. Endpoints Engine

**Cinq** endpoints exposés par `app/main.py` :

| Endpoint | Méthode | Rôle | Response model |
|----------|---------|------|----------------|
| `/` | GET | Health + `contract_version` | `{"status":"ok","contract_version":...}` |
| `/health` | GET | Health simple | `{"status":"ok"}` |
| `/generate` | POST | **Principal** — résout un planning hebdomadaire | `ScheduleOutputSchema` |
| `/place-matches` | POST | **Second problème** — place des matchs DATÉS (P1-4 PR D, ADR-0003) | `MatchPlacementOutputSchema` |
| `/validate-assignments` | POST | **Verdict sur UN candidat** (P2-2 F2a) — « puis-je mettre cette équipe sur ce créneau ? ». Baseline **entièrement figée** via `add_fixed_slots`, candidat épinglé à part : le solve du verdict ne fait qu'un test de faisabilité. ⚠ **Le gel EST le verdict** — baseline non figée, le solveur déplace la séance en conflit et rend `valid=True` (falsifié). Mono-candidat ⇒ 1 worker ⇒ déterministe. Budget 2 s par défaut, plafond 10 s ; mesuré **~500 ms** sur 49 équipes (le build du modèle domine, pas le solve). Un « non » **nomme les règles cassées** (`diagnose_candidate_conflicts`) ; `baseline_infeasible` distingue une baseline déjà invalide d'un conflit non nommé. Un « oui » (P2-32) déclenche **jusqu'à deux solves de plus** pour nommer les **compromis** — voir §POST /validate-assignments | `ValidateAssignmentOutputSchema` |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine | `JSONResponse` (200 synchronized / 409 desynchronized) |

### POST /place-matches

Le **second problème CP-SAT**, distinct du solve hebdomadaire (ADR-0003 ; comportement produit :
[`module-matchs.md`](../../specs/courantes/module-matchs.md) §Solveur de placement). Ce qui est propre à l'engine :

- **Handler** : `place_matches(input_data: MatchPlacementInputSchema)` (`main.py:766`). Même
  garde de contrat que `/generate` — MAJOR seul, 422 sinon : **un seul `CONTRACT_VERSION` pour les
  trois endpoints**.
- **Verrou par club PRÉFIXÉ** (`f"matches:{club_id}"`) : un solve hebdomadaire long ne bloque pas
  un placement de 3 s, alors qu'un même verrou l'aurait fait. ⚠ **Et le sémaphore l'est aussi
  depuis AUD-ENG-30** — la phrase « le sémaphore global `_solve_semaphore` borne quand même le
  CPU » était vraie avant, et fausse depuis : c'est `_placement_semaphore` qui borne ce rail.
  Un verrou préfixé sous un sémaphore partagé ne sert à rien, les deux protections se
  contredisaient et c'est la plus discrète qui gagnait.

**TROIS budgets de concurrence, un par rail** (`main.py:130-139`, réglages dans
`app/core/config.py`) — la règle est « un budget PROPRE, jamais un budget plus large » :

| Rail | Sémaphore | Défaut | Pourquoi séparé |
|---|---|---|---|
| `/generate` | `_solve_semaphore` | 1 | un solve peut tenir 600 s ; deux en parallèle sont exclus **exprès** |
| `/place-matches` | `_placement_semaphore` | 1 | AUD-ENG-30 — synchrone (ADR-0003), le gestionnaire attend la réponse HTTP |
| `/validate-assignments` | `_verdict_semaphore` | 1 | **AUD-ENG-33** — budgets asymétriques : le placement dispose de 30 s de solveur quand le verdict abandonne à **20 s** côté client (`MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`, calé sur 9-9,6 s mesurés sur le club réel). Un placement du club A affamait le verdict LÉGAL du club B |

⚠ **Résidu ASSUMÉ** : à 1, deux verdicts de deux clubs se sérialisent encore — sur la mesure
connue (~10 s), deux verdicts empilés frôlent les 20 s. Monter à 2 doublerait le CPU pour une
classe d'incident jamais observée. Les deux tests jumeaux de `tests/test_runtime.py` gardent la
propriété **et** sa borne : l'un exerce l'endpoint verdict pendant qu'un placement tient son
jeton, l'autre vérifie que deux placements restent sérialisés.
- **Solve** : `solve_match_placement(input_data)` dans un thread worker
  (`app/solver/match_placement.py`). Best-effort à poids dominant : aucune HARD violée, le
  non-plaçable ressort **nommé**.
- **Schémas dédiés** : `app/schemas/match_input_schema.py` / `match_output_schema.py` (§3 bis).

### POST /generate

- **Handler** : `generate_schedule(input_data: ScheduleInputSchema)`. **ENG-14** : rejette (422) un payload dont le **MAJOR de contrat** diffère de `read_contract_version()` (ex. `version` "1.x" alors que l'engine parle "2.x") — garde-fou du contrat manuel backend↔engine avant tout solve. **ENG-06** : un handler d'exception global (`_unhandled_exception_handler`) logge toute erreur non gérée (traceback serveur) et renvoie un 500 JSON propre sans fuite.
- **Isolation** : acquiert un `asyncio.Lock` par `club_id` (voir §5) avant de lancer `build_schedule`.
- **Pipeline** (`build_schedule` → `_solve`) :
  0. `build_schedule` lance `_solve` dans un **thread worker** (`await asyncio.to_thread(...)`) sous un `_solve_semaphore` global (`ENGINE_MAX_CONCURRENT_SOLVES`, défaut 1) : la boucle d'événements reste réactive pendant un solve (`/health` répond), la contention CPU reste bornée (ENG-03 corrigé).
  1. `input_data.model_dump(by_alias=True)` → dict.
  2. `build_model(data)` — crée `ScheduleCpModel`, variables `x`, extrait HARD locks.
  3. `parse_v2_constraints(data["constraints"])` — règle v2 → collections solver.
  4. Calcul `hard_satisfied_team_ids` (teams dont `sessionsPerWeek` est couvert par locks HARD → exclus du penalty unplaced).
  5. `adjusted_min_by_team` — min sessions mis à 0 pour teams sans assignments disponibles ou en conflit forcedDays/forbiddenDays.
  6. Construction `assignments` avec start/end pour contraintes consécutives.
  7. `add_level_1_hard_constraints(...)` — toutes les contraintes hard en un seul pass.
  8. `add_time_window_constraints(...)` — TIME/DAY hard windows + conflits.
  8 bis. `add_venue_minimum_constraints(...)` — planchers `minAtVenueId` (ALIGN-05) + diagnostics `venue_minimum_unreachable` quand le plancher est prouvablement inatteignable.
  8 ter. **`diagnose_locked_slot_violations(...)` — P2-9 volet 1 (livré 2026-07-28, PR #317).** Un verrou HARD est pré-placé hors solveur : `model.py` ne crée **pas** sa variable `x[...]`, donc aucune contrainte (qui s'applique en forçant cette variable à 0) ne peut l'atteindre — le verrou ne bat pas la contrainte, il la rend inatteignable. Cette fonction recroise `model.locked_slots` avec les contraintes **saisies** — indisponibilité coach (intervalle testé sur l'heure de début, pour chaque coach requis), fenêtres `minStartTime`/`maxStartTime`/`maxEndTime` (cette dernière mesurée sur la durée **du verrou**, pas du créneau de grille), règles DAY évaluées sur l'**UNION par équipe** (dont `forcedDays`, qu'un verrou posé un autre jour peut rendre insatisfaisable), paires (équipe, gymnase) interdites — et émet un `constraint_not_honored` de sévérité **INFO** par (contrainte, équipe, verrou), en nommant la règle réellement fautive. Le verrou reste **SOUVERAIN** : ALIGN-07 n'est pas rouvert, seul le silence disparaît. Hors périmètre volontaire : les règles structurelles (coach dans deux gymnases à la même heure) doivent bloquer, pas avertir. Gardé par `engine/tests/semantic/test_hard_lock_announces_violations.py` — axe structurant « sémantique des contraintes » (CLAUDE.md §7.1). ⚑ **Portée ÉTENDUE le 2026-08-12** : le **gymnase imposé** (`forced_venues`) entre dans le diagnostic — un verrou plaçant une équipe hors de son gymnase imposé était **totalement silencieux** (`COMPLETED`, zéro diagnostic), confirmé par reproduction. ⚠ **`venue_minimums` reste délibérément EXCLU** : appliquée en dur, ses seules issues sont honoré / `failed` / `venue_minimum_unreachable` **ERROR** — elle ne peut pas dériver en silence, et la déclarer surveillée serait précisément le mensonge que le docstring interdit (« *any drift between the two would make this lie about what the solver did* »). **Le trou est désormais structurellement fermé** : `constraint_matrix.py` porte une dimension **`lock_silence`** (`DIAGNOSED` / `UNBYPASSABLE` + raison / `SOFT`), **obligatoire et sans défaut** — une cellule qui l'oublie lève `TypeError` et fait rougir la suite entière. Le test généré rejoue un scénario **verrou-contre-règle par cellule** : classer une famille « diagnostiquée » sans qu'elle le soit **échoue** (falsifié).
  9. `remaining_sessions` : `sum(team_vars) <= max(0, sessionsPerWeek - locked_count)`.
  10. Termes soft : `add_preferred_day_bonus` + `add_preferred_time_bonus` + `add_match_day_rest_bonus` + `add_spacing_penalty` (plus les termes `preferred` / `avoided_venue` construits inline), puis `add_level_2_objective(..., apply_chaining=False)` — objectif Level-2 **placement seul** (les termes de chaînage sont construits mais exclus de l'objectif de phase 1).
  11. **Solve en 2 phases** (voir ci-dessous) → `(status, solver, model, conflicts)`.
  12. `build_result(..., constraint_version=read_contract_version())` → dict → `ScheduleOutputSchema.model_validate(...)`.
- **Solve en 2 phases** (`_solve`) :
  - **Timeout adaptatif** (`_adaptive_timeout`) : `complexity = n_teams * n_venues` → ≤50 : 60 s · ≤200 : 180 s · sinon 600 s ; plafonné par `input_data.solver_timeout_seconds` (le budget payload reste le plafond dur).
  - **Phase 1 — placement** : `CpSolver` avec `max_time_in_seconds = timeout adaptatif`, `random_seed = input_data.solver_seed`, `num_search_workers = workers adaptatifs` (1 ou 8 selon la complexité, cf. §5). Objectif = placement uniquement (sans chaînage), pour ne pas polluer la preuve d'optimalité.
  - **Phase 2 — chaînage + stabilité** (uniquement si phase 1 OPTIMAL/FEASIBLE et **termes de chaînage OU termes de stabilité** présents) : verrouille la qualité de placement (`placement_expression >= optimum phase 1`), **warm-start** via `AddHint` sur la solution de phase 1. **Sans stabilité** (chemin historique, `previousAssignments` absent/vide) : maximise `placement + chaining` — byte-identique à avant P3-21. **Avec stabilité** (P3-21, contrat 2.11, `build_stability_terms(model.x, previousAssignments)`) : maximise `placement + CHAINING_STABILITY_MULTIPLIER(4096) × chaining + stability` — séparation **lexicographique** prouvée par construction (masse MAX de stabilité = `STABILITY_TERM_WEIGHT(1) × cap(2000) = 2000 < 4096` = plus petit incrément de chaînage amplifié) : un seul point de chaînage prime toute la stabilité empilée, donc la stabilité ne départage que les ex æquo EXACTS de (placement, chaînage) — elle n'arbitre ni le score, ni le chaînage, ni ne fait tomber une séance (le placement reste verrouillé à l'optimum de phase 1). Une clé de créneau **HARD** n'a pas de variable dans `model.x` (§5) ⇒ jamais payée deux fois. Cap dur `CHAINING_PHASE_MAX_SECONDS = 10 s` (best-effort : si le cap tombe, le résultat de phase 1 est conservé). **Score rapporté** : quand la stabilité a joué, `_solve` recalcule le score aux poids d'ORIGINE (placement + chaînage naturel, stabilité EXCLUE — `model.reported_score_override`, lu par `result_builder.build_result` à la place de `solver.ObjectiveValue()`) pour que `SCORE_FORMULA_VERSION` reste inchangé ; sans stabilité, `reported_score_override` reste `None` et `ObjectiveValue()` est lu tel quel (byte-identique). **Inerte au 2026-08-17** : le backend n'émet pas encore `previousAssignments` (PR B, roadmap) — le chemin `previousAssignments` existe côté moteur mais n'est jamais emprunté en production.
- **Pas de fallback de relaxation** : toutes les contraintes HARD restent actives dans les deux phases. Si INFEASIBLE, `build_result` produit `status="failed"` avec diagnostics de conflit — pas de relaxation silencieuse. Le message d'échec (`_infeasible_message`) compte les **places** (capacités dédupliquées par triplet, miroir de `model.slot_capacities`) et non les créneaux, et nomme le gymnase dont les « au moins » dépassent les places non verrouillées (`_saturated_venue_minimum`, PR A 2026-08-06).

### POST /validate-assignments

Le verdict F2a (§ci-dessus) plus, depuis **P2-32 PR A (2026-08-16, contrat 2.10)**, les
**compromis nommés** d'un candidat ACCEPTÉ. Ce qui change, propre à l'engine (comportement
produit côté backend/front : `backend-inventory.md` §route `move`/`place-slot`) :

- **Périmètre** : un compromis est le delta de confort d'un déplacement, **jamais** un verdict —
  le booléen `valid` continue de venir SEUL du test de faisabilité HARD (`_apply_hard` sur le
  candidat épinglé). Les compromis ne sont calculés **qu'après** un `valid=True`, dans
  `_compromises_for` (`validate_assignments.py`) ; le chemin refus n'appelle jamais le solveur une
  deuxième fois.
- **Deux états FIGÉS, évalués par LE SOLVEUR** (`_evaluate_state`) : le modèle est reconstruit à
  chaque appel (mêmes builders HARD que `/generate`), **toutes** les variables hors des slots
  épinglés sont forcées à 0 (`model.Add(var == 0)`) — sans quoi un `Maximize` placerait des
  séances fantômes pour gonfler le score de confort — puis on ajoute les MÊMES termes soft que
  `/generate` (préférences gymnase/jour/heure, repos après match, spacing, plafond de jours coach,
  règles implicites, chaînage) et on **maximise**. Aucune recherche de placement : tout est déjà
  épinglé, la maximisation ne fait que résoudre les littéraux réifiés (dont le littéral `chained`,
  qui a besoin de l'objectif pour se réifier — cf. `objective.py`).
  - **« avant »** = baseline gelée + `reference` épinglé (nouveau champ d'entrée optionnel,
    `CandidateAssignmentSchema | None`) — le backend le pose au placement d'ORIGINE de la source
    pour un déplacement ; absent pour une création à la dérive (`place()`), auquel cas « avant » =
    baseline nue.
  - **« après »** = baseline gelée + candidat épinglé.
- **Le delta** (`compute_compromises`, `solver/compromise.py`) : pour chaque terme soft, replié par
  clé LOGIQUE (équipe/gymnase/jour/coach — les familles per-slot s'agrègent PAR ÉQUIPE pour éviter
  le double-compte d'un déplacement au sein des créneaux déjà préférés), on compte les termes
  HONORÉS avant/après. Le compte baisse → `broken` ; monte → `gained` ; égal → silence (pas
  d'entrée). **8 familles fermées** (étendre = décision fondateur) : `chaining`,
  `venue_preference`, `day_preference`, `time_preference`, `match_rest`, `spacing`,
  `coach_day_cap`, `implicit_rule` (sous-genre porté par `detail`, jamais exposé tel quel :
  `coach_rest`/`salarie`/`chain`/`age`). `missing_session`, les tiers de priorité S/A/B/C/D et
  **tout poids/somme en sont volontairement EXCLUS** (décision P5-14b, « jamais un score /100 »).
- **Message français, zéro identifiant interne** : `_message()` résout équipe/coach/gymnase via
  les tables de noms du club envoyées dans le payload — un lookup manqué retombe sur un libellé
  générique (« une équipe », « un coach », « un gymnase »), **jamais l'id brut** (nit corrigé en
  revue de sécurité avant merge, commit `187bb706`).
- **Coût** : un candidat ACCEPTÉ passe de 1 à **3 solves** (verdict + avant + après), chacun sous
  le même budget court que le verdict (`solverTimeoutSeconds`, 2 s par défaut) et le même régime
  déterministe (mono-candidat, 1 worker). Le timeout **HTTP** côté backend est monté à **20 s**
  (`MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`, voir `backend-inventory.md`) — le budget
  **solveur** par solve, lui, ne change pas.
- **Le calcul des compromis est AU MIEUX, DOUBLEMENT protégé (incident terrain 2026-08-17)** —
  un verdict `valid=True` est TRANCHÉ dès `_apply_hard` ; l'habillage explicatif (les 2 solves de
  compromis) ne doit jamais le faire échouer ni le retarder au point de dépasser le timeout
  transport du backend :
  - **contre la PANNE** : `_compromises_for` est appelé sous `try/except Exception` — toute levée
    (budget épuisé sans solution à lire, bug) répond quand même le verdict, avec `compromises: []`
    et un `logger.warning`. La FORME de la réponse ne change pas (mêmes clés), seul le contenu de
    `compromises` se vide.
  - **contre la LENTEUR** : `COMPROMISE_ELAPSED_BUDGET_SECONDS = 8.0` — passé ce temps DÉJÀ
    consommé par le verdict (`time.monotonic()` depuis l'entrée de `validate_assignment`), le
    calcul des compromis n'est même pas ENTAMÉ (compromis vides, verdict inchangé). Sans ce garde,
    un club qui grossit rallonge silencieusement la réponse jusqu'à retoucher le plafond transport
    — le geste échouerait de nouveau alors qu'il est LÉGAL.
  - Falsifié par `engine/tests/test_validate_compromise_failure_is_best_effort.py` (les deux
    gardes séparément) — **forme de réponse inchangée, `CONTRACT_VERSION` reste 2.12** (aucun
    champ/type/sémantique de contrat ne bouge, seul le contenu dégrade).
- **Maison unique génération⇄évaluation (D-6)** : `add_venue_preference_bonus` (bonus `preferred`
  + malus `avoided_venue`) était assemblé inline dans `main.build_schedule` — **extrait** tel quel
  vers `objective.py` pour être appelé aussi par `_evaluate_state`, sans dupliquer la logique. Les
  autres builders soft (`add_coach_rest_day_constraints`, `add_salarie_distribution_constraints`,
  `add_max_consecutive_sessions_constraints`, `add_age_ascending_constraints`) gagnent un
  paramètre `soft_term_info_out`/`info_out` du même patron : optionnel, défaut `None`, n'ajoute
  **aucune** variable ni contrainte au modèle — le chemin `/generate` (qui ne le passe jamais) est
  byte-identique, goldens inchangés.
- **Tests** : falsification par famille + cas piège chaining (le littéral `chained` exige
  `apply_chaining=True` dans `_evaluate_state`, sans quoi il ne se réifie jamais)
  (`engine/tests/test_validate_compromises.py`), parité sémantique génération⇄évaluation
  (`engine/tests/semantic/test_compromise_parity.py` — le même candidat honore/casse les mêmes
  préférences qu'un `/generate` équivalent).
- **P2-27 (contrat 2.12)** : `ValidateAssignmentsInputSchema` gagne le même bloc `sharedTrainings`
  que `/generate` (parité génération⇄verdict, absent/vide ⇒ aucun effet). ⚠ **Le solveur seul ne
  peut PAS refuser un déplacement qui SORT une équipe d'une case commune** : la baseline retire la
  source mais laisse sa variable d'ancienne case LIBRE — sans plafond de séances, le solveur la
  replace sur cette même case pour tenir `== K` et conclut « oui » à tort. `validate_assignment`
  juge donc l'**état concret proposé** (baseline sans la source + candidat) de façon déterministe,
  AVANT tout solve, en miroir de `add_shared_training_constraints` (`_shared_training_move_violation`,
  `validate_assignments.py`) : seuls les groupes dont l'équipe DÉPLACÉE est membre sont évalués (un
  AUTRE groupe déjà rompu — ex. déclaration ajoutée après génération — n'entre jamais en jeu). Un
  refus porte `"rule": "shared_training_broken"`, message français nommant les équipes.

---

## 3. Schemas Pydantiques

### ScheduleInputSchema (`engine/app/schemas/input_schema.py`)

Version contrat active : **`"2.16"`** (fichier `CONTRACT_VERSION`, source de vérité). ⚠ Le default Pydantic du champ `version` vaut **`"2.0"`** (`input_schema.py:149`) et n'a jamais suivi les bumps : c'est un repli pour un payload qui n'annonce rien, pas la version parlée. `ConfigDict(extra="forbid", populate_by_name=True)`.

**Bornes A10** (#156, anti-bombe de génération) : la plupart des listes portent un `max_length` (rejet **422** avant CP-SAT) — `teams` ≤200 · `venues` ≤50 · `coaches` ≤200 · `slot_templates` ≤2000 · `priority_tiers` ≤20 · `trainingSlots` ≤1000/gymnase ; plus un `model_validator` bornant le **total** des créneaux à ≤3000 (empêche 50×1000). **`constraints` n'a PAS de cap engine** (ENG-23 corrigé) : le backend éclate 1 règle CLUB en N rangées/équipe, donc la taille étendue = brut(≤500)×équipes(≤200) — aucun nombre fixe ne peut à la fois borner une bombe et ne jamais faux-bloquer un club légitime ; les vraies bornes sont le cap **brut** backend (≤500) + la limite de body nginx (20 m) + le timeout solveur. Le backend (`GenerationComplexityGuard`) pré-vérifie teams/venues/coaches/contraintes permanentes/total créneaux (=3000) **plus** `teams×venues` ≤2000, **avant dispatch**. ⚠ Ce durcissement de validation (#156) n'a **pas** bumpé `CONTRACT_VERSION` : politique — un `max_length` resserre l'enveloppe acceptée sans changer forme/type ni MAJOR ; un bump n'est requis que pour un changement de forme/sémantique (champ/type/alias). Les bumps depuis 2.0 : **2.1** (#195, fenêtres horaires coach — nouveaux champs), **2.2** (P1-4 PR D, second endpoint `/place-matches` — nouveaux schémas) et **2.3** (P4-51, retrait de `maxDaysOverrideConfirmed` — un champ que rien ne lisait ; schéma `forbid`, donc sa présence dans un vieux payload devient un 422 : rupture de recevabilité, bump requis).

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `version` | — | `str` | `"2.0"` (repli — cf. ci-dessus) |
| `club_id` | `clubId` | `str` | requis |
| `season_id` | `seasonId` | `str` | requis |
| `schedule_name` | `scheduleName` | `str \| None` | `None` |
| `solver_seed` | `solverSeed` | `int` | `42` |
| `solver_timeout_seconds` | `solverTimeoutSeconds` | `int` | `650` |
| `venues` | — | `list[VenueSchema]` | `[]` |
| `teams` | — | `list[TeamSchema]` | `[]` |
| `coaches` | — | `list[CoachSchema]` | `[]` |
| `constraints` | — | `list[ConstraintV2Schema]` | `[]` |
| `slot_templates` | `slotTemplates` | `list[ScheduleSlotTemplateSchema]` | `[]` |
| `priority_tiers` | `priorityTiers` | `list[PriorityTierSchema]` | `[]` |
| `previous_assignments` | `previousAssignments` | `list[PreviousAssignmentSchema]` | `[]` (P3-21, contrat 2.11) |

Sous-schemas clés :
- **PreviousAssignmentSchema** (P3-21, contrat 2.11) : `teamId`, `venueId`, `dayOfWeek` (1-7), `startTime` (str `"19:00"`) — un placement de la génération PRÉCÉDENTE, pour le terme de **stabilité** (§POST /generate, §5 Solver). Cap `max_length` = `MAX_SLOT_TEMPLATES` (2000, même ordre de grandeur qu'un placement par séance). Patron `implicitRules` : absent/vide ⇒ chemin byte-identique. **INERTE au 2026-08-17** — le backend ne l'émet pas encore (PR B, roadmap).
- **VenueSchema** : `id`, `name`, `isExternal`, `color`, `latitude`, `longitude`, `source`, `externalRef`, `isActive`, `parentVenueId`, `trainingSlots: list[VenueTrainingSlotSchema]`.
- **VenueTrainingSlotSchema** : `dayOfWeek`, `startTime` (str `"19:00"`), `durationMinutes`, `capacity` (≥1, default 1).
- **TeamSchema** : `id`, `sportCategoryId`, `ageMin`, `ageMax`, `priorityTierId`, `name`, `gender`, `level`, `sessionsPerWeek`, `minSessionsOverride`, `matchDay`, `forcedVenueId`, `isActive`, `parentTeamId`, `ffbbTeamId`, `tags`.
- **CoachSchema** : `id`, `firstName`, `lastName`, `email`, `phone`, `maxDaysOverride`, `maxDaysOverrideConfirmed`, `acceptableLateMinutes`, `isActive`, `parentCoachId`, `isEmployee`.
- **ConstraintV2Schema** : unifié v2/legacy. `ConfigDict(extra="ignore")`. Champs v2 : `scope`, `scopeTargetId`, `family`, `ruleType`, `name`, `config`, `sortOrder`, `isActive`. Champs legacy v1 : `teamId`, `type`, `severity`, `value`, `metadata`.
- **ScheduleSlotTemplateSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `pendingConstraintSuggestion`.
- **PriorityTierSchema** : `id`, `label`, `orToolsWeight`, `defaultMinSessions`.

### Schémas du placement de matchs (`match_input_schema.py` / `match_output_schema.py`)

Contrat **2.11** (le MÊME que `/generate` — un seul contrat pour les deux endpoints), les schémas hebdomadaires n'étant pas réutilisés
(le problème n'a ni créneau récurrent ni séance) :

- **`MatchPlacementInputSchema`** : `version`, `clubId`, `seasonId`, `matches`, `venues`, `teams`,
  `coaches`… Sous-schémas : **`MatchVenueSchema`** (`matchWindows: list[MatchAccessWindowSchema]`
  = jour + plage `start`/`end` d'accès à la salle, `unavailabilities` datées),
  **`MatchTeamSchema`** (`leagueWindows: list[LeagueKickoffWindowSchema]` = jour +
  `kickoffMin`/`kickoffMax` imposés par la ligue, `habits: list[TeamHabitSchema]` ≤7 = jour +
  heure-point + gymnase optionnel, `coaches: list[TeamCoachRefSchema]` ≤20 avec `role`
  MAIN/ASSISTANT).
- **`MatchPlacementOutputSchema`** : `status`, `placements: list[MatchPlacementSchema]`
  (`matchId`, `venueId`, `kickoff`), **`unplaced: list[UnplacedMatchSchema]`** (`matchId`,
  `reason`, `message` — le non-plaçable sort NOMMÉ, c'est le produit), `diagnostics`
  (mêmes `DiagnosticSchema` que le solve hebdo), `metrics`.

### ScheduleOutputSchema (`engine/app/schemas/output_schema.py`)

`ConfigDict(extra="forbid", populate_by_name=True)`.

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `status` | — | `Literal["queued","generating","completed","failed"]` | requis |
| `score` | — | `int \| None` | `None` |
| `metrics` | — | `SolverMetricsSchema` | requis |
| `unplaced` | — | `list[str]` | `[]` |
| `slots` | — | `list[ScheduleSlotSchema]` | `[]` |
| `diagnostics` | — | `list[DiagnosticSchema]` | `[]` |

- **SolverMetricsSchema** : `solverVersion: str`, `nbVariables: int`, `nbConstraints: int`, `wallTimeMs: int`, plus les identifiants de déterminisme (optionnels, `None` accepté pour les anciens payloads) : `scoreFormulaVersion: str | None` (formule T24 qui a produit le score) et `constraintVersion: str | None` (version de contrat backend↔engine).
- **ScheduleSlotSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `pendingConstraintSuggestion`.
- **DiagnosticSchema** : `id`, `type`, `severity`, `ruleKey` (règle implicite concernée, `implicit_rule_not_honored`), `teamId`, `coachId`, `venueId`, `dayOfWeek`, `startTime`, `durationMinutes`, `message`, `suggestions: list[str]`, `causes: list[DiagnosticCauseSchema]` (P4-99, renseigné UNIQUEMENT par `session_below_effective_min`), `openCandidates: int | None` (créneaux libres restés ouverts — même diagnostic), `createdAt`.
- **DiagnosticCauseSchema** (contrat 2.8, P4-99) : `kind` (Literal fermé — `hard_lock`, `venue_forbidden`, `coach_unavailability`, `time_window`, `day_conflict`, `day_forbidden`, `forced_venue_elsewhere`), `constraintId: str | None`, `label: str | None`, `count: int`. MESURÉE à la pose des contraintes (jamais reconstituée après coup) via `model.candidate_closures` (par variable) + `model.lock_removed_candidates` (candidats sans variable retirés par un verrou).
  - Types valides — la liste FAIT foi, c'est un `Literal` fermé (`output_schema.py:62-75`, D-41 : un type hors énumération est refusé à la construction) : `coach_overload`, `conflict`, `constraint_not_honored`, `day_constraint_conflict`, `implicit_rule_not_honored`, `session_below_effective_min`, `shared_training_not_honored`, `soft_lock_moved`, `unplaced`, `unplaced_match`, `unused_slot`, `venue_minimum_unreachable`. `shared_training_not_honored` (P2-27, contrat 2.12) — `_diagnose_shared_trainings` : sur INFEASIBLE, cause CERTAINE nommée quand aucune fenêtre de gymnase n'a une capacité ≥ la taille du groupe (le groupe ne pourra JAMAIS partager de case) ; sur un solve abouti, défense en profondeur — le nombre RÉEL de séances communes dans les slots finaux, s'il diverge du `K` déclaré, est signalé (la contrainte étant dure, ce cas ne devrait pas survenir). ⚠ Cette ligne a dérivé jusqu'au 2026-08-15 : elle citait `coach_no_rest_day` (type mort, retiré au contrat 2.7) et omettait `implicit_rule_not_honored` et `unplaced_match`. Catalogue commenté (causes + action corrective) : `engine/docs/solver-errors.md`.
  - **`constraint_not_honored`** (`_not_honored_warning`, `constraints/common.py`) : émis quand une contrainte saisie ne peut pas être honorée. **Deux producteurs** — (1) `parse_v2_constraints` au parse, en `WARNING`, quand la règle n'est pas traduisible en terme solver (sans équipe cible, dispo coach reçue en non-HARD, règle de gymnase écrasée) — audit P0.1, traçabilité UI↔engine ; (2) `diagnose_locked_slot_violations` après construction du modèle, en **INFO**, quand un verrou HARD a rendu la contrainte inatteignable (P2-9, cf. §2). Les deux rejoignent `diagnostics[]` via `main.py`. Cf. `docs/architecture/constraint-matrix.md` et `engine/docs/constraint-vocabulary.md`.

---

## 4. Contraintes

### 4.1 Niveaux de règle (`ruleType`)

| Niveau | Sémantique | Traitement solver |
|--------|-----------|-------------------|
| `HARD` | Impératif — faisabilité | Contrainte CP-SAT (`model.Add(...)`) |
| `PREFERRED` | Souhait — optimisation | Bonus objectif Level-2 (pas de contrainte hard) |
| `LOCK` | Règle « figée » | Traité **exactement comme `HARD`** : TIME/DAY → `time_windows` ; FACILITY → `forced_venues` / `venue_minimums`. La collection `fixed_slots` n'est alimentée par **aucune** branche de `parse_v2_constraints` (chemin résiduel). ⚠ Ne pas confondre avec `slotTemplates[].lockLevel`, autre mécanisme (cf. §5 Hard locks) |

> `BONUS` **n'existe plus** (audit P0.1 ENG-12) : l'UI ne le propose plus ; les lignes legacy `BONUS` sont normalisées en `PREFERRED` à l'entrée de `parse_v2_constraints` (`constraints.py` — plus honnête que de les dropper en silence).

### 4.2 Family & Scope

- **`family`** : catégorie de règle. Valeurs reconnues (`_KNOWN_FAMILIES`, `constraints/parsing.py`) : `TIME`, `DAY`, `FACILITY`, `COACH_AVAILABILITY`. Types legacy reconnus (`_KNOWN_TYPES`) : `TEAM_COACH`, `COACH_PLAYER_UNAVAILABILITY`, `PRIORITY_TIER`. Une contrainte dont **ni** la famille **ni** le type n'est reconnu est loggée comme dérive de contrat.
- **`scope`** : cible de la règle. Valeur vue : `TEAM`. (D'autres scopes peuvent exister mais ne sont pas traités différemment dans le code lu.)
- **`scopeTargetId`** : ID de la cible (team, coach, venue selon family/scope).

### 4.3 Mapping `parse_v2_constraints` (constraints[] → collections solver)

| Condition de match | Collection alimentée |
|--------------------|---------------------|
| `ruleType == "LOCK"` + `family in ("TIME","DAY")` | `time_windows` (traité comme `HARD` par `add_time_window_constraints`) |
| `ruleType == "LOCK"` + `family == "FACILITY"` | même traitement que `HARD` (`forced_venues` / `venue_minimums`) |
| `type == "TEAM_COACH"` (legacy) | `team_coach_map[teamId]` → coachIds (MAIN seuls — un ASSISTANT n'est pas une ressource exclusive). **Posée sur le modèle** (`model.team_coach_map`, `main.py`) : depuis ENG-17 (2026-08-07) c'est elle qui nomme le `coachId` des créneaux GÉNÉRÉS — avant, seuls les `slotTemplates` étaient consultés et les diagnostics coach restaient muets sur le chemin dominant |
| `type == "COACH_PLAYER_UNAVAILABILITY"` (legacy) | `team_player_map[teamId]` → coachIds |
| `family == "COACH_AVAILABILITY"` | `coach_unavailability[scopeTargetId]` → `unavailableDays` |
| `family == "FACILITY"` + `preferredVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `preferredVenueId` |
| `family == "FACILITY"` + `forcedVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `forcedVenueId` |
| `family == "FACILITY"` + `preferredVenueId` + `PREFERRED` + `scope=TEAM` | `preferred_venues[scopeTargetId]` → **ensemble** de gymnases (PR B 2026-08-06 : les préférences se CUMULENT, bonus si la séance tombe dans l'un d'eux ; le last-wins + INFO ne reste que sur `forced_venues`) |
| `family == "FACILITY"` + `forbiddenVenueId` | `forbidden_assignments` → `[{scope_target_id, venue_id}]` |
| `family == "FACILITY"` + `forbiddenVenueId` + `PREFERRED` + cible | `avoided_venues` → `[{scope_target_id, venue_id}]` (malus objectif, poids `avoided_venue`). **Même clé** que l'interdiction dure : c'est le `ruleType` qui décide dur/soft (il n'existe **pas** de clé `avoidedVenueId`) |
| `family == "FACILITY"` + `minAtVenueId` (+ `minAtVenueCount`, défaut 1) + HARD/LOCK + `scope=TEAM` | `venue_minimums` → plancher `somme(vars équipe@gymnase) ≥ N` (ALIGN-05) |
| contrainte reconnue mais inapplicable (sans équipe cible, dispo coach reçue en non-HARD, règle de gymnase écrasée par une autre) | `parse_warnings` → diagnostics `constraint_not_honored` |
| `type == "PRIORITY_TIER"` (legacy) | `priority_tiers[tierId]` = `defaultMinSessions` |
| `family in ("TIME","DAY")` | `time_windows` (traité par `add_time_window_constraints`) |

### 4.4 Contraintes Hard Level-1 (`add_level_1_hard_constraints`)

Familles de contraintes comptées dans `HardConstraintStats` (liste exhaustive : dataclass dans `app/solver/constraints/common.py`) :

| # | Nom | Rôle |
|---|-----|------|
| 1 | `room_at_most_one` | Une salle accueille ≤ `capacity` équipes par créneau |
| 2 | `coach_at_most_one` | Un coach encadre ≤ 1 équipe par créneau (time_key + interval overlap) |
| 3 | `coach_player_non_overlap` | Un coach-joueur ne peut pas être aux deux endroits simultanément |
| 3b | `coach_rest_day` | Chaque coach a ≥ 1 jour de repos (Mon-Fri) — skip si `maxDaysOverride ≤ 4` |
| 3c | `salarie_distribution` | ≥ 1 coach salarié (`isEmployee=True`) présent chaque jour Mon-Fri — skip si < 2 salariés |
| 3d | `max_consecutive_sessions` | Un coach ne peut pas être dans les 3 slots d'un triple consécutif (cross-venue) |
| 4 | `team_no_overlap` | Une équipe ne peut pas avoir 2 sessions au même créneau |
| 5 | `fixed_slots` | Slots pré-placés (LOCK) forcés à 1 |
| 6 | `forbidden_assignments` | Variables interdites forcées à 0 (ID ou pair team+venue) |
| 7 | `coach_unavailability` | Slots coach indisponible forcés à 0 |
| 8 | `min_sessions` | **Câblé SOFT-ONLY (ENG-18)** : `_solve` passe un plancher **0** pour chaque équipe, donc aucune contrainte dure n'est posée. La cible est portée par le bonus objectif `session_count` + les diagnostics `session_below_effective_min`. La fonction reste *capable* d'un plancher dur, non utilisé en production |
| 9 | `forced_venues` | Si salle forcée, autres salles exclues (forcées à 0) |
| 10 | `one_session_per_day` | ≤ 1 session/jour/équipe (sans exception — le drapeau `allowMultipleSessionsPerDay`, jamais écrit, retiré en P4-79) |
| 11 | `age_ascending` | Teams plus jeunes entraînées plus tôt (même venue+jour) — exempt si `ageMin=None` ou HARD-locked |
| 12 | `max_consecutive_days` | P2-42 — une ÉQUIPE ne s'entraîne pas `maxConsecutiveDays` jours de suite (défaut 3, bornes 2-5) ; posé seulement si la règle est HARD (opt-in, naît `OFF`) |
| 13 | `shared_training` | P2-27 — mutualisation : chaque groupe déclaré (`sharedTrainings`) partage EXACTEMENT `commonSessions` séances communes. Pour chaque case candidate `(venue, day, start)`, un littéral `y_s` est réifié **dans les deux sens** (présence de tous les membres ⇔ `y_s`), puis `Σ_s y_s == K` ; un membre HARD-verrouillé sur `s` compte comme présence constante (crédité, pas ignoré — leçon P4-97). Vide ⇒ aucune pose, chemin byte-identique |
| 14 | `team_link` | Lot PASSERELLES — deux équipes déclarées `MANDATORY` ne se chevauchent JAMAIS (`var_a + var_b ≤ 1`) ; 0 si `teamLinks` absent/vide ou tout `PREFERRED` (le PREFERRED est un malus objectif, pas une contrainte dure) |
| 15 | `travel_time` | P2-53 RMM-8 PR-2 — règle `travelTime` **MANDATORY** seule : interdit dur un enchaînement cross-gymnase dont le battement est plus court que le barème (voiture/à pied selon `isVehicled`, ou à pied pour une passerelle) ; 0 si la règle est inactive, `PREFERRED`, ou `venueTravelTimes` vide. Résidu possible SEULEMENT entre deux verrous HARD contradictoires, ANNONCÉ par le diagnostic `travel_time_infeasible` (`_diagnose_travel_times`), jamais un INFEASIBLE muet |

Stubs (toujours satisfaits, 0 contraintes, **DISTINCTS** du `travel_time` ci-dessus — même sujet,
mécanismes non reliés) : `travel_feasibility_stub`, `required_bridge_stub` (`ImplicitConstraint`
catalogue « extensions futures », `engine/implicit_rules.json` — gouvernance séparée de
`implicitRules.travelTime`, jamais câblée).

### 4.5 Time windows (`add_time_window_constraints`)

- `family == "TIME"` + `ruleType == "HARD"` : force `var == 0` si `startTime` hors `[minStartTime, maxStartTime]`.
- `family == "TIME"` + `maxEndTime` (HARD only, ALIGN-04) : force `var == 0` si `début du créneau + sa durée > maxEndTime`. Le chemin soft (`add_preferred_time_bonus`) ne lit **que** min/maxStartTime.
- `family == "DAY"` + `ruleType == "HARD"` : `forcedDays` (≥ 1 session sur ces jours), `forbiddenDays` (vars à 0), `allowedDays` (liste blanche : tout jour praticable hors liste est interdit ; liste vide = « non configuré », aucune restriction).
- `family == "TIME"`/`"DAY"` + `ruleType == "PREFERRED"` : **bonus soft dans l'objectif** (`add_preferred_time_bonus` / `add_preferred_day_bonus`, poids `preferred_time`/`preferred_day` = 30) — pas de contrainte hard. Cf. commentaire `constraints.py` « PREFERRED TIME is a soft bonus handled in the objective ».
- Conflit → diagnostic `day_constraint_conflict` (severity ERROR), toutes vars team à 0. **Deux formes** : `forcedDays ∩ forbiddenDays` non vide, OU une liste blanche `allowedDays` dont **tous** les jours sont explicitement interdits (les deux sont testées contre le `forbiddenDays` d'origine, pas contre le complément de la whitelist, pour que le diagnostic soit explicite).

---

## 5. Solver

- **Bibliothèque** : Google OR-Tools CP-SAT (`cp_model.CpModel`, `cp_model.CpSolver`).
- **Variables** : booléennes `x[team_id, venue_id, day_of_week, slot_start]` (type `SlotKey = tuple[str, str, int, str]`).
- **Granularité** : `SLOT_MINUTES = 15` (model.py).
- **Durée session default** : `DEFAULT_SESSION_MINUTES = 90`.
- **Timeout solver** : adaptatif (`_adaptive_timeout`, voir §2) — `n_teams × n_venues` ≤50 : 60 s · ≤200 : 180 s · sinon 600 s, plafonné par `solver_timeout_seconds` du payload (default **650 s** dans `ScheduleInputSchema`). Phase 2 (chaînage) plafonnée en plus par `CHAINING_PHASE_MAX_SECONDS = 10`.
- **Seed** : `solver.parameters.random_seed = input_data.solver_seed` (default 42) — les deux phases.
- **Déterminisme (ENG-25, 2026-08-07)** : les agrégations par équipe itèrent sur des clés `str`,
  dont le hash est randomisé PAR PROCESSUS. `add_preferred_day_bonus` **trie** désormais
  (`objective.py`) — sans quoi l'ordre d'ajout des termes soft, donc le chemin de recherche de
  CP-SAT, changeait d'un run à l'autre : même payload, même `solverSeed`, planning différent (de
  valeur d'objectif identique). ⚠ `PYTHONHASHSEED` n'est **délibérément pas figé** : ce serait
  traiter le symptôme, et le figer désarme la protection contre les collisions de hash. L'ordre se
  décide là où il compte. Gardé par `tests/test_deterministic_term_order.py`.
- **Harnais de test (ENG-26, 2026-08-07)** : `tests/support/pipeline.py` annonce la version lue
  depuis `CONTRACT_VERSION`. Elle était figée à `"1.0"` — un payload que `POST /generate` refuse en
  422 : `solve_payload` court-circuitant la couche FastAPI, le garde ne tournait jamais, et toute la
  suite sémantique validait une enveloppe que personne n'accepterait en production. Gardé par
  `tests/test_harness_speaks_the_real_contract.py`.
- **Workers** : `num_search_workers` **adaptatif** (`_adaptive_workers`, main.py) — complexité `n_teams×n_venues` ≤200 → **1** (déterministe, dont dépendent les goldens petits) · else → **8** (le worker unique trouve l'optimum en ~2s sur les problèmes denses riches en soft mais ne le prouve pas — 612s de blocage sur BCCL ; le portfolio 8 workers ferme la preuve en ~2s, même valeur d'objectif, assignation non-déterministe mais valeur stable). Appliqué aux deux phases.
  - ⚠️ **Réconciliation spec** : `specs/initiales/…contraintes_v2.md §2` promet « même entrée + même `solver_seed` + même version → planning **exactement** identique ». Depuis les workers adaptatifs, cette garantie n'est plus **exacte** qu'en dessous du seuil (≤200 complexité, 1 worker) ; au-dessus, seule la **valeur d'objectif** (score) est reproductible, pas l'arrangement exact (décision produit 2026-07-07, cf. roadmap §1 — le gestionnaire ajuste de toute façon). Les initiales étant gelées, la réconciliation vit ici.
- **Objectif Level-2** : `SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V10"`. Maximise somme pondérée.
  ⚑ **Principe fondateur gravé en V10 (2026-08-15) : LE REMPLISSAGE PRIME SUR LE CONFORT.** Une séance
  placée — même dans un gymnase, un jour ou à une heure non préférés — vaut TOUJOURS mieux qu'un trou ;
  le confort ne sert qu'à départager des solutions qui placent le MÊME nombre de séances. Deux garde-fous :
  **(1)** tout poids de confort reste sous **21** (valeur minimale d'une séance : tier D 1 + session_count 20),
  cumul compris (10+5+5 = 20 < 21) ; **(2)** `missing_session` **−1000 PAR séance manquante** sous le quota —
  avant V10, seule l'équipe à ZÉRO séance coûtait quelque chose, et une préférence à 60 rachetait une séance
  à 21 (vécu : le club réel rendait OPTIMAL à 89/90). Poids fixes (`LEVEL_2_OBJECTIVE_WEIGHTS`, objective.py — source de vérité, ne pas figer d'autres valeurs ici) :

| Critère | Poids |
|---------|-------|
| Tier S | 10 000 |
| Tier A | 1 000 |
| Tier B | 100 |
| `session_count` | 20 |
| `preferred` | **10** (V10 — était 60) |
| `avoided_venue` | **−10** (V10 — était −60 ; même seuil : fuir un gymnase ne doit pas coûter une séance) |
| `preferred_day` | **5** (V10 — était 30) |
| `preferred_time` | **5** (V10 — était 30) |
| Tier C | 10 |
| Tier D | 1 |
| **`missing_session`** | **−1000 PAR séance sous le quota** (V10, nouveau — domine tout cumul de confort) |
| `rest` | 3 |
| `spacing` | −2 (malus soft, ALIGN-06 : deux séances d'une même équipe sur des jours consécutifs) |

- **Contraintes v2 effectives** (série ENGINE, 2026-07-03) : `parse_v2_constraints` → `ParsedConstraints` (TypedDict). Indispo coach par jour (COACH_AVAILABILITY `unavailableDays`/`availableDays`, jours int — la CIBLE est le `scopeTargetId`, `config.coachId` supprimé le 2026-08-08) appliquée ; ~~FACILITY_CAPACITY (`maxTeams`)~~ **famille retirée le 2026-08-08** (SEC-13 PR C : honorée par le moteur, créable par personne — la capacité vit sur `trainingSlots[].capacity`) ; LOCK TIME/DAY = HARD ; `allowedDays` = whitelist ; `forcedDays`/`forbiddenDays`/min-maxStartTime HARD. `preferred_time` (soft) + repos lendemain de match (règle implicite, `matchDay` → jour+1 libre, poids `rest`).

- `UNPLACED_PENALTY = 100 000` (par team non placée, sauf `hard_satisfied_team_ids`).
- **Chaining bonus** (phase 2 uniquement) : `CHAINING_TIER_WEIGHTS = {S:8, A:6, B:4, C:2, D:1}` — bonus entier pour sessions back-to-back même venue même jour, une par PERSONNE présente aux deux séances (coach OU joueur de l'équipe, via `team_player_map` ; `set` → une personne compte une fois par paire), poids du tier le plus haut de la paire. Plafond **par personne** = 8 par construction : < 21 (valeur minimale d'une session placée, plancher `missing_session` −1000 en sus → coût de suppression ≥ 1021) pour ne jamais sacrifier un placement même en empilant k personnes distinctes (k×poids ≤ 8k, k restant une poignée), et ≤ 8 (écart C−D = 9) pour un terme isolé ne jamais voler un slot à un tier supérieur.
- **Stability term — convergence moteur** (P3-21, contrat 2.11, `objective.py`, phase 2 uniquement, **inerte au 2026-08-17** — voir §POST /generate) : `STABILITY_TERM_WEIGHT = 1` par variable `model.x[team, venue, day, start]` dont la clé figure dans `previousAssignments` (`build_stability_terms`, dédup par clé, clé normalisée exactement comme `model.x` via `_format_time(_time_to_minutes(...))`). `CHAINING_STABILITY_MULTIPLIER = 4096` sépare **lexicographiquement** stabilité et chaînage dans l'objectif (`placement + 4096 × chaining + stability`) : la masse MAX de stabilité (poids 1 × cap `previousAssignments` 2000 = 2000) reste sous le poids d'un seul point de chaînage amplifié — la stabilité ne peut donc renverser AUCUN arbitrage de chaînage, seulement départager des ex æquo exacts. Granularité **par séance**, aucun réglage club. Non appliqué à `/validate-assignments` ni `/place-matches` (déplacement manuel = volonté explicite, jamais une convergence).
- **Hard locks** : `HARD_LOCK_LEVEL = "HARD"` (model.py). Slots `lockLevel == "HARD"` → variable forcée à 1, venue bloquée pour autres teams sur ces créneaux. `blocked_venue_slots` retire le `(venue,day,start)` pour **toutes** les autres équipes : un verrou prend le **créneau entier**, même divisible (`capacity>1`) — ALIGN-07, comportement assumé (décision gestionnaire). Partager un créneau divisible = co-épingler explicitement les N équipes ; le diagnostic over-capacity (`result_builder/diagnostics.py::_diagnose_conflicts`) ne se déclenche qu'au-delà de `capacity`. Gardé par `engine/tests/semantic/test_hard_lock_divisible_slot.py`.

### Per-club asyncio locks

- `_club_locks: dict[str, asyncio.Lock]` + `_club_locks_guard: asyncio.Lock` (module-level, `main.py`).
- `get_club_lock(club_id)` : crée/récupère un `asyncio.Lock` par `club_id` sous le guard.
- `generate_schedule` : `async with lock: await build_schedule(input_data)` — empêche la génération concurrente pour le même club. Différents clubs peuvent être résolus en parallèle.

---

## 6. Communication Backend ↔ Engine

- **Backend → Engine** : HTTP POST `http://engine:8000/generate` depuis `GenerateScheduleHandler` (backend Symfony). Payload = `ScheduleInputSchema` (tout le contexte : venues, teams, coaches, constraints, slotTemplates, priorityTiers).
- **Engine → Backend** : **jamais**. L'engine est purement réactif — il ne contacte pas le backend.
- **Frontend → Engine** : **jamais directement**. Le frontend passe toujours par le backend (`/api/*`).
- **Réponse** : `ScheduleOutputSchema` retourné au backend, qui persiste les slots et publie sur Mercure.
- **Isolation tenant** : `clubId` + `seasonId` dans le payload ; lock asyncio par `club_id`.
- **Endpoint auxiliaire** : `POST /implicit-constraints` permet au backend de vérifier la synchronisation des règles implicites (200 synchronized / 409 desynchronized avec `missing_in_engine` / `missing_in_backend`).

---

## 7. Tests & Fixtures

- **Fixtures golden** (`engine/tests/fixtures/`) : scénarios JSON (liste : `ls engine/tests/fixtures/`) — dont `simple_club`, `medium_club`, `dense_club`, `bccl_regression`, `impossible`, `age_order_club`, `consecutive_emerick`, `no_rest_enzo`, `overlap_anna`, `overlap_nicolas`, `score_hard_only_teams`, `vacation_week`.
- **Suites** (emplacements — liste vivante via `ls engine/tests/`) : `tests/golden/`, `tests/invariants/`, `tests/perf/`, **`tests/semantic/`** (matrice de contraintes audit P0.1 — `constraint_matrix.py` = source unique UI↔engine, `test_constraint_matrix.py`, `test_diagnostics.py`, `test_features.py`, `test_semantic_smoke.py`, `test_hard_lock_divisible_slot.py` = ALIGN-07, `test_hard_lock_announces_violations.py` = P2-9 volet 1), `tests/test_result_builder.py`, plus tests spécialisés (age order, chaining bonus, coach rest day, salarié distribution, max consecutive sessions, adaptive timeout, capacity slots, time/day constraints, objective, generate contract…).
- **Toolchain tests** : `pytest` + `pytest-timeout` + `hypothesis`.