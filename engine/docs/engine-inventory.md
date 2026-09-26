# Engine Inventory — Backward Spec

Last verified @ 2026-09-26 (passe « présent » `documentation-update`, brief engine 2/4).
Re-confronté au code : `CONTRACT_VERSION` = **2.23** (`engine/CONTRACT_VERSION`) ✓ ; les **six
endpoints** inchangés (`/`, `/health`, `/generate`, `/place-matches`, `/validate-assignments`,
`/implicit-constraints`, `engine/app/main.py:775-885`) ✓ ; `DEFAULT_MATCH_MIN=105`/
`DEFAULT_WARMUP_MIN=30` dans `match_placement.py:38-39` ✓ ; `ConstraintRuleType` ne porte que
HARD/PREFERRED/LOCK (`backend/src/Enum/ConstraintRuleType.php`, `BONUS` absent) ✓ ;
`PLACEMENT_PROXIMITY_WEIGHT = 9` dans `app/solver/objective/weights.py:191` ✓ ;
`previousAssignments` est bien ÉMIS par le backend en régénération
(`backend/src/Service/ScheduleConstraintBuilder.php:678`) — corrige une mention « inerte » du
§3 ✓ ; le payload `/generate` n'a **aucune** clé racine `priorityTiers` peuplée, les tiers
voyagent en contraintes `PRIORITY_TIER` (`ScheduleConstraintBuilder.php:570`) — corrige le §6 ✓ ;
`socleReferenceAssignments`/`SOCLE_REFERENCE_TIER_WEIGHTS` confirmés (`input_schema.py:238,354`,
`objective/weights.py:280-289`) ✓ ; durées de match par équipe et `roundTripMinutes` confirmés
(`match_input_schema.py`, `match_placement.py`) ✓. Reste de l'inventaire (détail des sections
sous la ligne 40) non re-sondé cette passe — voir `git log -p --follow` pour sa dernière
vérification.

> Inventaire BACKWARD de l'existant engine. Reflète le code lu au SHA ci-dessus, pas les features futures.
> Source de vérité : `engine/app/main.py`, `engine/app/schemas/input_schema.py`, `engine/app/schemas/output_schema.py`, `engine/app/solver/{model,constraints,objective,result_builder}.py`, `engine/app/core/config.py`.

---

## 1. Architecture Engine

- **Runtime** : Python 3.12.
- **Framework HTTP** : FastAPI (app construite dans `engine/app/main.py` via `get_settings()` → `app_name`/`app_version`).
- **Solver** : Google OR-Tools CP-SAT (`from ortools.sat.python import cp_model`).
- **Validation** : Pydantic v2 (`BaseModel`, `ConfigDict`, `Field`, `populate_by_name=True`).
- **Settings** : `pydantic-settings` (`engine/app/core/config.py`), prefix env `ENGINE_`, `.env` lu. Defaults : `app_name="engine"`, `app_version="1.0"`, `contract_version="2.0"`, `environment="dev"`, `log_level="info"`.
- **Contract version** : lu depuis `engine/CONTRACT_VERSION` (**fichier = `2.23`** — source de vérité, `read_contract_version()` dans `main.py`). Un fichier manquant lève une `RuntimeError`, il n'est **jamais** remplacé par un défaut : le garde de contrat est MAJOR-only, un build amputé de son fichier passerait sinon le handshake et résoudrait un payload d'une AUTRE version mineure en se croyant d'accord. Gardé par `tests/test_contract_version_doc_sync.py`. **Politique de bump** : un changement de FORME ou de SÉMANTIQUE (champ/type/alias ajouté, retiré ou dont le sens change) bump le contrat ; un simple resserrage d'ENVELOPPE (`max_length` qui rétrécit ce qu'on acceptait déjà, sans toucher forme ni sémantique) ne bump pas. **UN SEUL `CONTRACT_VERSION` pour les TROIS endpoints** `/generate` · `/place-matches` · `/validate-assignments`, tous vérifient le même MAJOR — l'historique des bumps (ce que chaque version a changé) vit dans `git log -p --follow engine/CONTRACT_VERSION` et le journal `specs/courantes/etat-des-lieux.md` §3.
- **Structure interne** :
  - `app/main.py` — endpoints FastAPI + pipeline solver.
  - `app/core/config.py` — settings.
  - `app/schemas/input_schema.py` — `ScheduleInputSchema`.
  - `app/schemas/output_schema.py` — `ScheduleOutputSchema`.
  - `app/solver/model.py` — `ScheduleCpModel` (variables booléennes `x[team, venue, day, slot]`).
  - `app/solver/constraints/` — **paquet**, découpé par métier : `parsing.py` (lecture du payload + règles implicites) · `structural.py` (overlap/capacité/verrous) · `wellness.py` (bien-être) · `targeting.py` (fenêtres/gymnases/mutualisation/passerelles) · `travel.py` (trajet — battement/départage, ré-exporté par `__init__.py`) · `diagnostics.py` (explications post-solve) · `common.py` (types, constantes, normalisation) · `__init__.py` (façade de ré-export **+ l'orchestrateur `add_level_1_hard_constraints`** — il y vit par contrainte de COUTURE DE TEST : le test des règles implicites patche les poseurs via le namespace du paquet).
  - `app/solver/objective/` — objectif Level-2 (poids fixes T24), **paquet depuis ENG-39** : `weights` (tables/alias) → `normalise` (lecteurs) → `terms` (les `add_*`) ; l'agrégateur `__init__` ré-exporte tout (imports inchangés).
  - `app/solver/result_builder/` — solution → `ScheduleOutputSchema` + diagnostics, **paquet depuis ENG-39** : `helpers` → `slots` + `diagnostics` (les 13 `_diagnose_*`) ; l'agrégateur `__init__` ré-exporte tout (imports inchangés).
  - `app/solver/match_placement.py` — le SECOND problème (placement de matchs datés, ADR-0003).
  - `app/schemas/match_input_schema.py` / `match_output_schema.py` — ses schémas dédiés.
- **Port** : 8000 (conteneur Docker `engine`).
- **Commandes** : tout via `engine/Makefile` dans le conteneur (`make test`, `make lint`, `make exec`).

---

## 2. Endpoints Engine

Les endpoints exposés par `app/main.py` (santé + les trois du contrat) :

| Endpoint | Méthode | Rôle | Response model |
|----------|---------|------|----------------|
| `/` | GET | Health + `contract_version` | `{"status":"ok","contract_version":...}` |
| `/health` | GET | Health simple | `{"status":"ok"}` |
| `/generate` | POST | **Principal** — résout un planning hebdomadaire | `ScheduleOutputSchema` |
| `/place-matches` | POST | **Second problème** — place des matchs DATÉS (ADR-0003) | `MatchPlacementOutputSchema` |
| `/validate-assignments` | POST | **Verdict sur N candidats sous UN verdict** (contrat 2.18) — « puis-je poser ces N déplacements ? » (N=1 pour le rail `/move`/`/place-slot`, N=membres d'un bloc pour `/move-group`). Baseline **entièrement figée** via `add_fixed_slots`, les N candidats épinglés à part : le solve du verdict ne fait qu'un test de faisabilité sur l'**état final** (jamais N jugements séquentiels d'un état intermédiaire faux). ⚠ **Le gel EST le verdict** — baseline non figée, le solveur déplace la séance en conflit et rend `valid=True` (falsifié). 1 seul worker (déterministe) quel que soit N. Budget 2 s par défaut, plafond 10 s ; mesuré **~500 ms** sur 49 équipes (le build du modèle domine, pas le solve). Un « non » **nomme les règles cassées** (`diagnose_candidate_conflicts`, chaque candidat diagnostiqué contre la baseline augmentée des AUTRES candidats) ; `baseline_infeasible` distingue une baseline déjà invalide d'un conflit non nommé. Un « oui » déclenche **jusqu'à deux solves de plus** pour nommer les **compromis** — voir §POST /validate-assignments | `ValidateAssignmentOutputSchema` |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine | `JSONResponse` (200 synchronized / 409 desynchronized) |

### POST /place-matches

Le **second problème CP-SAT**, distinct du solve hebdomadaire (ADR-0003 ; comportement produit :
[`module-matchs.md`](../../specs/courantes/module-matchs.md) §Solveur de placement). Ce qui est propre à l'engine :

- **Handler** : `place_matches(input_data: MatchPlacementInputSchema)` (`main.py:766`). Même
  garde de contrat que `/generate` — MAJOR seul, 422 sinon : **un seul `CONTRACT_VERSION` pour les
  trois endpoints**.
- **Verrou par club PRÉFIXÉ** (`f"matches:{club_id}"`) : un solve hebdomadaire long ne bloque pas
  un placement de 3 s, alors qu'un même verrou l'aurait fait. Le rail a son **propre** sémaphore
  de concurrence, `_placement_semaphore` — pas le `_solve_semaphore` global de `/generate` : un
  verrou préfixé sous un sémaphore partagé n'aurait aucun effet propre.
- **Budget de CONSTRUCTION du modèle** (`match_placement.py`, `BUILD_BUDGET_SECONDS = 10.0`,
  ENG-40, contrat 2.22) : le `max_time_in_seconds` du `CpSolver` ne borne que le SOLVE — les
  boucles chaudes qui bâtissent candidats/no-overlap/passerelles sont O(matchs²)/O(candidats²) et
  pouvaient tourner des minutes AVANT que le solveur ne démarre. `solve_match_placement` (point
  d'entrée public) enveloppe `_place_matches` (le vrai bâtisseur) : `_ensure_budget()` vérifie
  l'horloge à chaque itération des boucles chaudes et lève `_BuildBudgetExceeded` (forme + nombre
  de candidats déjà construits) au-delà du budget ; l'appelant traduit en `status="failed"` +
  diagnostic `placement_problem_too_large` (sévérité `error`) plutôt qu'un hang silencieux. Gardé
  par `engine/tests/perf/test_perf_place_matches.py` (marqueur `perf`, club synthétique volumineux,
  main only — voir `docs/testing/testing-strategy.md` §1).

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
- **Durées PAR ÉQUIPE et fenêtres personne/salle** (`match_placement.py`) : chaque équipe porte
  `matchMinutes`/`warmupMinutes` (défauts Pydantic **105/30**, résolus côté backend par
  `MatchDurationResolver` — catégorie sinon défaut de famille 75/90/105). La **salle** ne tient
  que le MATCH `[kickoff, kickoff + matchMinutes]` — l'échauffement ne l'occupe plus (décision
  fondateur : « on s'échauffe sur le côté pendant le match précédent »). La fenêtre **personne**
  (coach / lien `NOT_SIMULTANEOUS`) est, elle aussi, sans échauffement : `[kickoff − travelOut,
  kickoff + matchMinutes + travelBack]`, qui se réduit à `[kickoff, kickoff + matchMinutes]` à
  domicile (pas de trajet). `warmupMinutes` reste au schéma (pas de bump) mais n'est plus lu par
  aucune fenêtre du solveur.
- **`roundTripMinutes`** (`matches[]`, AWAY seulement, `int` 0-1440, défaut 0) : le trajet
  aller-retour vers l'adversaire, projeté par la maison unique
  `App\Service\OpponentTravelProjection` (partagée avec le radar de conflits côté backend).
  Étend la fenêtre personne du coach — moitié avant le coup d'envoi (`travelOut`), moitié après
  le match (`travelBack`) —, réplique exacte de `MatchFootprint::personConflictOccupancy`. Défaut
  0 ⇒ aucune extension (match non-AWAY ou trajet inconnu).
- **`slotRotations`** (RMM-5, `venueId`/`dayOfWeek`/`kickoff`/`teamIds`) : un créneau de match
  PARTAGÉ tourne entre équipes membres (rareté des créneaux — la case SM1/SM2 20:30, semaine A
  une équipe reçoit, semaine B l'autre). CONSOMMÉ en SOFT (jamais HARD) : le match HOME d'un
  membre ce jour-là est ATTIRÉ vers `(kickoff, venueId)`, à parité stricte avec les termes
  d'habitude, et la fenêtre de la rotation est protégée les dates où aucun membre n'y joue. Le
  backend retire l'habitude du même jour pour un membre en rotation (suppléance) : un membre
  reçoit soit la rotation, soit son habitude ce jour-là, jamais les deux.
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
  5. `min_by_team` — dict de ZÉROS pour chaque équipe (le minimum de séances est SOFT-ONLY, porté par l'objectif `session_count:20` + diagnostics WARNING, jamais un plancher dur). Ce dict n'existe QUE pour la parité de SOURCE avec le verdict (`_apply_hard`, qui construit exactement la même expression) — `test_hard_layer_parity_registry.py` compare les deux textes, `DECLARED_ARG_DIVERGENCES` est **vide** (ENG-41, l'ancienne exemption sur `min_sessions_by_team` reposait sur un fait faux : les deux côtés produisaient déjà des zéros, le code qui laissait croire à un calcul réel — boucle de disponibilités, conflit de jour — était mort).
  6. Construction `assignments` avec start/end pour contraintes consécutives.
  7. `add_level_1_hard_constraints(...)` — toutes les contraintes hard en un seul pass.
  8. `add_time_window_constraints(...)` — TIME/DAY hard windows + conflits.
  8 bis. `add_venue_minimum_constraints(...)` — planchers `minAtVenueId` (ALIGN-05) + diagnostics `venue_minimum_unreachable` quand le plancher est prouvablement inatteignable.
  8 ter. **`diagnose_locked_slot_violations(...)`.** Un verrou HARD est pré-placé hors solveur : `model.py` ne crée **pas** sa variable `x[...]`, donc aucune contrainte (qui s'applique en forçant cette variable à 0) ne peut l'atteindre — le verrou ne bat pas la contrainte, il la rend inatteignable. Cette fonction recroise `model.locked_slots` avec les contraintes **saisies** — indisponibilité coach (intervalle testé sur l'heure de début, pour chaque coach requis), fenêtres `minStartTime`/`maxStartTime`/`maxEndTime` (cette dernière mesurée sur la durée **du verrou**, pas du créneau de grille), règles DAY évaluées sur l'**UNION par équipe** (dont `forcedDays`, qu'un verrou posé un autre jour peut rendre insatisfaisable), paires (équipe, gymnase) interdites — et émet un `constraint_not_honored` de sévérité **INFO** par (contrainte, équipe, verrou), en nommant la règle réellement fautive. Le verrou reste **SOUVERAIN** : ALIGN-07 n'est pas rouvert, seul le silence disparaît. Hors périmètre volontaire : les règles structurelles (coach dans deux gymnases à la même heure) doivent bloquer, pas avertir. Gardé par `engine/tests/semantic/test_hard_lock_announces_violations.py` — axe structurant « sémantique des contraintes » (CLAUDE.md §7.1). Le **gymnase imposé** (`forced_venues`) entre aussi dans le diagnostic — sans quoi un verrou plaçant une équipe hors de son gymnase imposé restait totalement silencieux. ⚠ **`venue_minimums` reste délibérément EXCLU** : appliquée en dur, ses seules issues sont honoré / `failed` / `venue_minimum_unreachable` **ERROR** — elle ne peut pas dériver en silence, et la déclarer surveillée serait précisément le mensonge que le docstring interdit (« *any drift between the two would make this lie about what the solver did* »). `constraint_matrix.py` porte une dimension **`lock_silence`** (`DIAGNOSED` / `UNBYPASSABLE` + raison / `SOFT`), **obligatoire et sans défaut** — une cellule qui l'oublie lève `TypeError` et fait rougir la suite entière, ce qui ferme structurellement le trou. Le test généré rejoue un scénario **verrou-contre-règle par cellule** : classer une famille « diagnostiquée » sans qu'elle le soit **échoue** (falsifié).
  9. `remaining_sessions` : `sum(team_vars) <= max(0, sessionsPerWeek - locked_count)`.
  10. Termes soft : `add_preferred_day_bonus` + `add_preferred_time_bonus` + `add_match_day_rest_bonus` + `add_spacing_penalty` (plus les termes `preferred` / `avoided_venue` construits inline), puis `add_level_2_objective(..., apply_chaining=False)` — objectif Level-2 **placement seul** (les termes de chaînage sont construits mais exclus de l'objectif de phase 1).
  11. **Solve en 2 phases** (voir ci-dessous) → `(status, solver, model, conflicts)`.
  12. `build_result(..., constraint_version=read_contract_version())` → dict → `ScheduleOutputSchema.model_validate(...)`.
- **Solve en 2 phases** (`_solve`) :
  - **Timeout adaptatif** (`_adaptive_timeout`) : `complexity = n_teams * n_venues` → ≤50 : 60 s · ≤200 : 180 s · sinon 600 s ; plafonné par `input_data.solver_timeout_seconds` (le budget payload reste le plafond dur).
  - **Phase 1 — placement** : `CpSolver` avec `max_time_in_seconds = timeout adaptatif`, `random_seed = input_data.solver_seed`, `num_search_workers = workers adaptatifs` (1 ou 8 selon la complexité, cf. §5). Objectif = placement uniquement (sans chaînage), pour ne pas polluer la preuve d'optimalité.
  - **Phase 2 — chaînage + stabilité** (uniquement si phase 1 OPTIMAL/FEASIBLE et **termes de chaînage OU termes de stabilité** présents) : verrouille la qualité de placement (`placement_expression >= optimum phase 1`), **warm-start** via `AddHint` sur la solution de phase 1. **Sans stabilité** (`previousAssignments` absent/vide) : maximise `placement + chaining`. **Avec stabilité** (contrat 2.11, `build_stability_terms(model.x, previousAssignments)`) : maximise `placement + CHAINING_STABILITY_MULTIPLIER(4096) × chaining + stability` — séparation **lexicographique** prouvée par construction (masse MAX de stabilité = `STABILITY_TERM_WEIGHT(1) × cap(2000) = 2000 < 4096` = plus petit incrément de chaînage amplifié) : un seul point de chaînage prime toute la stabilité empilée, donc la stabilité ne départage que les ex æquo EXACTS de (placement, chaînage) — elle n'arbitre ni le score, ni le chaînage, ni ne fait tomber une séance (le placement reste verrouillé à l'optimum de phase 1). Une clé de créneau **HARD** n'a pas de variable dans `model.x` (§5) ⇒ jamais payée deux fois. Cap dur `CHAINING_PHASE_MAX_SECONDS = 10 s` (best-effort : si le cap tombe, le résultat de phase 1 est conservé). **Score rapporté** : quand la stabilité a joué, `_solve` recalcule le score aux poids d'ORIGINE (placement + chaînage naturel, stabilité EXCLUE — `model.reported_score_override`, lu par `result_builder.build_result` à la place de `solver.ObjectiveValue()`) pour que `SCORE_FORMULA_VERSION` reste inchangé ; sans stabilité, `reported_score_override` reste `None` et `ObjectiveValue()` est lu tel quel (byte-identique). `previousAssignments` est ÉMIS par le backend (`GenerateScheduleHandler`, version REGARDÉE en régénérant). **La PROXIMITÉ pèse AUSSI dans la phase 1** : les mêmes clés (`build_stability_terms`, construit AVANT l'objectif) donnent `proximity_terms = [(var, PLACEMENT_PROXIMITY_WEIGHT = 9)]` repliés dans `extra_placement_terms` — une séance retrouvée tient à sa place même si la source score sous l'optimum ; une règle saisie ≥ 10 prime, une préférence isolée < 9 cède (preuve d'empilement dans `weights.py`, ADR-0001 amendé). Le score rapporté SOUSTRAIT la masse proximité (Σ 9 × valeur finale) en plus d'exclure la sous-bande : poids d'origine, `SCORE_FORMULA_VERSION` inchangé. Phase 2 (verrou, ×4096, sous-bande) byte-identique. **Le bonus de référence socle** (`socleReferenceAssignments`, comblement seul, §5 Solver) suit le même patron mais ne coexiste jamais avec la proximité (branches backend exclusives).
- **Pas de fallback de relaxation** : toutes les contraintes HARD restent actives dans les deux phases. Si INFEASIBLE, `build_result` produit `status="failed"` avec diagnostics de conflit — pas de relaxation silencieuse. Le message d'échec (`_infeasible_message`) compte les **places** (capacités dédupliquées par triplet, miroir de `model.slot_capacities`) et non les créneaux, et nomme le gymnase dont les « au moins » dépassent les places non verrouillées (`_saturated_venue_minimum`).

### POST /validate-assignments

Le verdict F2a (§ci-dessus) porte aussi les **compromis nommés** d'un verdict ACCEPTÉ (contrat
2.10). Ce qui est propre à l'engine (comportement produit côté backend/front :
`backend-inventory.md` §route `move`/`place-slot`) :

- **Périmètre** : un compromis est le delta de confort d'un déplacement, **jamais** un verdict —
  le booléen `valid` continue de venir SEUL du test de faisabilité HARD (`_apply_hard` sur les N
  candidats épinglés). Les compromis ne sont calculés **qu'après** un `valid=True`, dans
  `_compromises_for` (`validate_assignments.py`) ; le chemin refus n'appelle jamais le solveur une
  deuxième fois.
- **Deux états FIGÉS, évalués par LE SOLVEUR** (`_evaluate_state`) : le modèle est reconstruit à
  chaque appel (mêmes builders HARD que le verdict, `_apply_hard` — parité avec `/generate`
  gardée par `engine/tests/test_hard_layer_parity_registry.py`, `DECLARED_ASYMMETRIES`
  vide (aucune asymétrie connue), `add_venue_minimum_constraints`),
  **toutes** les variables hors des slots
  épinglés sont forcées à 0 (`model.Add(var == 0)`) — sans quoi un `Maximize` placerait des
  séances fantômes pour gonfler le score de confort — puis on ajoute les MÊMES termes soft que
  `/generate` (préférences gymnase/jour/heure, repos après match, spacing, plafond de jours coach,
  règles implicites, chaînage) et on **maximise**. Aucune recherche de placement : tout est déjà
  épinglé, la maximisation ne fait que résoudre les littéraux réifiés (dont le littéral `chained`,
  qui a besoin de l'objectif pour se réifier — cf. `objective.py`).
  - **« avant »** = baseline gelée + les `references` épinglées, une par candidat déplacé, appariées
    PAR INDEX (`references[i]` = l'origine de `candidates[i]`) — le backend les pose au placement
    d'ORIGINE de chaque source ; `references` VIDE pour une création à la dérive (`place()`),
    auquel cas « avant » = baseline nue.
  - **« après »** = baseline gelée + les N candidats épinglés.
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
  générique (« une équipe », « un coach », « un gymnase »), **jamais l'id brut**.
- **Coût** : un verdict ACCEPTÉ passe de 1 à **3 solves** (verdict + avant + après), chacun sous
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
    gardes séparément) — **la forme de la réponse ne change pas, aucun bump** (même clés,
    seul le contenu de `compromises` dégrade).
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
- **Mutualisation par bloc (contrat 2.19)** — `sharedBlocks` est la SEULE notion de mutualisation :
  `ValidateAssignmentsInputSchema` porte le même bloc que `/generate` (parité
  génération⇄verdict, absent/vide ⇒ aucun effet). ⚠ **Le solveur seul ne peut PAS refuser un
  déplacement qui SORT une équipe d'une case commune** : la baseline retire la source mais laisse sa
  variable d'ancienne case LIBRE — sans plafond de séances, le solveur la replace sur cette même
  case pour tenir `commonSessions` et conclut « oui » à tort. `validate_assignment` juge donc
  l'**état concret proposé** (baseline sans la source + candidat) de façon déterministe, AVANT tout
  solve, en miroir du liage `add_shared_block_constraints` (`_shared_block_move_violation`,
  `validate_assignments.py`). **Garde anti-enfermement** : un bloc DÉJÀ cassé dans la
  baseline ne bloque pas les déplacements — seul un bloc HONORÉ avant et rompu après refuse. Refus
  nommé `"rule": "shared_block_broken"`, message français nommant les équipes.
- **N déplacements sous UN verdict, sur l'ÉTAT FINAL (contrat 2.18)** :
  `candidates`/`references` deviennent des **LISTES** appariées par index (forme UNIQUE,
  aucun champ mort de compat — une liste à 1 élément EST le cas single, un seul chemin de code
  `validate_assignment`). Rail `/move`/`/place-slot` : N=1. Rail `POST /api/schedule-slots/move-group`
  (le déplacement d'un bloc de mutualisation entier) : N = les membres du bloc, les N sources déjà
  retirées de la baseline côté backend. Les **4 miroirs déterministes**
  (`_shared_block_move_violation`, `_team_link_move_violation`, `_travel_time_move_violation`,
  `_venue_minimum_move_violation`) sont tous généralisés au pluriel et jugent l'**état final
  proposé** (baseline − N sources + N candidats), **jamais N jugements séquentiels d'un état
  intermédiaire faux** : déplacer TOUS les membres d'un bloc vers une même case reste ACCEPTÉ (le
  bloc s'y reconstitue), en déplacer UN SEUL reste refusé `shared_block_broken`. Le refus HARD non
  nommé (`diagnose_candidate_conflicts`) diagnostique
  chaque candidat contre la baseline **augmentée des autres candidats du même geste**, pour nommer
  un conflit HARD *entre deux déplacements du même geste* (pas seulement candidat-contre-baseline).
  `_apply_hard` **INCHANGÉ** (le registre de parité `test_hard_layer_parity_registry` reste vert
  sans modification). Goldens `/generate` inchangés — le bump ne touche que le verdict.

---

## 3. Schemas Pydantiques

### ScheduleInputSchema (`engine/app/schemas/input_schema.py`)

Version contrat active : **`"2.23"`** (fichier `CONTRACT_VERSION`, source de vérité). Le default Pydantic du champ `version` vaut **`"2.23"`** lui aussi (`input_schema.py:321`, gardé par `test_schema_version_defaults_match_contract_version`, ENG-44) : c'est un repli pour un payload qui n'annonce rien — le backend l'envoie TOUJOURS, ce défaut n'est donc jamais la valeur du fil — aligné sur le contrat courant pour qu'aucun lecteur ne le prenne pour une version concurrente. `ConfigDict(extra="forbid", populate_by_name=True)`.

**Bornes A10** (anti-bombe de génération) : la plupart des listes portent un `max_length` (rejet **422** avant CP-SAT) — `teams` ≤200 · `venues` ≤50 · `coaches` ≤200 · `slot_templates` ≤2000 · `priority_tiers` ≤20 · `trainingSlots` ≤1000/gymnase ; plus un `model_validator` bornant le **total** des créneaux à ≤3000 (empêche 50×1000). **`constraints` est cappé par le PRODUIT ÉTENDU, pas un compte par règle** : `MAX_CONSTRAINTS_EXPANDED = 100_000` = brut(≤500)×équipes(≤200), parce que le backend éclate 1 règle CLUB en N rangées/équipe et qu'aucun compte fixe par règle ne peut à la fois borner une bombe et ne jamais faux-bloquer un club légitime — le produit étendu, lui, est une borne réelle et finie. Les vraies bornes amont restent aussi actives : cap **brut** backend (≤500) + la limite de body nginx (20 m) + le timeout solveur. Le backend (`GenerationComplexityGuard`) pré-vérifie teams/venues/coaches/contraintes permanentes/total créneaux (=3000) **plus** `teams×venues` ≤2000, **avant dispatch**. ⚠ Ce durcissement de validation n'a **pas** bumpé `CONTRACT_VERSION` : politique — un `max_length` resserre l'enveloppe acceptée sans changer forme/type ni MAJOR ; un bump n'est requis que pour un changement de forme/sémantique (champ/type/alias).

| Champ | Alias JSON | Type | Default |
|-------|-------------|------|---------|
| `version` | — | `str` | `"2.23"` (repli — cf. ci-dessus) |
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
| `priority_tiers` | `priorityTiers` | `list[PriorityTierSchema]` | `[]` (le backend ne le peuple jamais — les tiers voyagent en contraintes `PRIORITY_TIER`, §4.3) |
| `previous_assignments` | `previousAssignments` | `list[PreviousAssignmentSchema]` | `[]` (contrat 2.11) |
| `socle_reference_assignments` | `socleReferenceAssignments` | `list[SocleReferenceAssignmentSchema]` | `[]` (contrat 2.20) |

Sous-schemas clés :
- **PreviousAssignmentSchema** (contrat 2.11) : `teamId`, `venueId`, `dayOfWeek` (1-7), `startTime` (str `"19:00"`) — un placement de la génération PRÉCÉDENTE, pour le terme de **stabilité** (§POST /generate, §5 Solver). Cap `max_length` = `MAX_SLOT_TEMPLATES` (2000, même ordre de grandeur qu'un placement par séance). Patron `implicitRules` : absent/vide ⇒ chemin byte-identique. ÉMIS par le backend en régénération (`ScheduleConstraintBuilder::previousAssignments`) — absent seulement en toute première génération d'un plan.
- **SocleReferenceAssignmentSchema** (contrat 2.20) : `teamId`, `dayOfWeek` (1-7), `startTime` (str), SANS `venueId` — un placement de la version POINTÉE du socle, pour le bonus de comblement (§5 Solver « Socle reference bonus »). Cap `max_length` = `MAX_SLOT_TEMPLATES`. ÉMIS par le backend UNIQUEMENT en comblement (jamais en régénération complète — branche exclusive de `previousAssignments`).
- **VenueSchema** : `id`, `name`, `isExternal`, `color`, `latitude`, `longitude`, `source`, `externalRef`, `isActive`, `parentVenueId`, `trainingSlots: list[VenueTrainingSlotSchema]`.
- **VenueTrainingSlotSchema** : `dayOfWeek`, `startTime` (str `"19:00"`), `durationMinutes`, `capacity` (≥1, default 1).
- **TeamSchema** : `id`, `sportCategoryId`, `ageMin`, `ageMax`, `priorityTierId`, `name`, `gender`, `level`, `sessionsPerWeek`, `minSessionsOverride`, `matchDay`, `forcedVenueId`, `isActive`, `parentTeamId`, `ffbbTeamId`, `tags`.
- **CoachSchema** : `id`, `firstName`, `lastName`, `email`, `phone`, `maxDaysOverride`, `maxDaysOverrideConfirmed`, `acceptableLateMinutes`, `isActive`, `parentCoachId`, `isEmployee`.
- **ConstraintV2Schema** : unifié v2/legacy. `ConfigDict(extra="ignore")`. Champs v2 : `scope`, `scopeTargetId`, `family`, `ruleType`, `name`, `config`, `sortOrder`, `isActive`. Champs legacy v1 : `teamId`, `type`, `severity`, `value`, `metadata`.
- **ScheduleSlotTemplateSchema** : `id`, `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime` (time), `durationMinutes`, `lockLevel` (default `"NONE"`), `pendingConstraintSuggestion`.
- **PriorityTierSchema** : `id`, `label`, `orToolsWeight`, `defaultMinSessions`.

### Schémas du placement de matchs (`match_input_schema.py` / `match_output_schema.py`)

Contrat **2.23** (le MÊME que `/generate` — un seul contrat pour les trois endpoints), les schémas hebdomadaires n'étant pas réutilisés
(le problème n'a ni créneau récurrent ni séance) :

- **`MatchPlacementInputSchema`** : `version`, `clubId`, `seasonId`, `matches`, `venues`, `teams`,
  `coaches`, `teamLinks`, `slotRotations`, `trainingOccupancies`… Sous-schémas :
  **`MatchVenueSchema`** (`matchWindows: list[MatchAccessWindowSchema]`
  = jour + plage `start`/`end` d'accès à la salle, `unavailabilities` datées),
  **`MatchTeamSchema`** (`leagueWindows: list[LeagueKickoffWindowSchema]` ≤50
  (`MAX_LEAGUE_WINDOWS_PER_TEAM`, ENG-45 — miroir de `MAX_WINDOWS_PER_VENUE`, défense en
  profondeur au bord) = jour + `kickoffMin`/`kickoffMax` imposés par la ligue,
  `habits: list[TeamHabitSchema]` ≤7 = jour + heure-point + gymnase optionnel,
  `coaches: list[TeamCoachRefSchema]` ≤20 avec `role`
  MAIN/ASSISTANT, `matchMinutes`/`warmupMinutes` — durées résolues par le backend, défauts
  105/30, cf. §POST /place-matches), **`MatchSchema`** (un match daté : `kind`
  `TO_PLACE`/`FIXED`/`AWAY`, `venueId`/`kickoff` (requis si `FIXED`), `currentVenueId`/
  `currentKickoff` pour le hint de stabilité, `roundTripMinutes` — trajet AWAY, cf.
  §POST /place-matches), **`SlotRotationSchema`** (`venueId`/`dayOfWeek`/`kickoff`/`teamIds` ≤20 —
  rotation de créneau partagé, cf. §POST /place-matches).
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
- **DiagnosticSchema** : `id`, `type`, `severity`, `ruleKey` (règle implicite concernée, `implicit_rule_not_honored`), `teamId`, `coachId`, `venueId`, `dayOfWeek`, `startTime`, `durationMinutes`, `message`, `suggestions: list[str]`, `causes: list[DiagnosticCauseSchema]` (renseigné UNIQUEMENT par `session_below_effective_min`), `openCandidates: int | None` (créneaux libres restés ouverts — même diagnostic), `createdAt`.
- **DiagnosticCauseSchema** (contrat 2.8) : `kind` (Literal fermé — `hard_lock`, `venue_forbidden`, `coach_unavailability`, `time_window`, `day_conflict`, `day_forbidden`, `forced_venue_elsewhere`), `constraintId: str | None`, `label: str | None`, `count: int`. MESURÉE à la pose des contraintes (jamais reconstituée après coup) via `model.candidate_closures` (par variable) + `model.lock_removed_candidates` (candidats sans variable retirés par un verrou).
  - Types valides — la liste FAIT foi, c'est un `Literal` fermé (`output_schema.py:69-95`, D-41 : un type hors énumération est refusé à la construction) : `coach_overload`, `conflict`, `constraint_not_honored`, `day_constraint_conflict`, `implicit_rule_not_honored`, `session_below_effective_min`, `shared_block_not_honored`, `team_link_not_honored`, `travel_time_infeasible`, `soft_lock_moved`, `unplaced`, `unplaced_match`, `placement_problem_too_large`, `unused_slot`, `venue_minimum_unreachable`. `placement_problem_too_large` (contrat 2.22, ENG-40) : sévérité `error`, émis exclusivement par `solve_match_placement` (`match_placement.py`) quand la CONSTRUCTION du modèle dépasse `BUILD_BUDGET_SECONDS`, jamais par `/generate`. `shared_block_not_honored` (contrat 2.17, SEUL type de mutualisation — `sharedBlocks` remplace le groupe {équipes, K}) — `_diagnose_shared_blocks` : sur INFEASIBLE, cause CERTAINE nommée quand aucune case commune candidate ne peut réunir le bloc ; sur un solve abouti, défense en profondeur — le nombre RÉEL de séances communes du bloc, s'il diverge du déclaré, est signalé. Catalogue commenté (causes + action corrective) : `engine/docs/solver-errors.md`, dont le tableau documente chacune des 15 valeurs du `Literal`, `team_link_not_honored`/`travel_time_infeasible` compris.
  - **`constraint_not_honored`** (`_not_honored_warning`, `constraints/common.py`) : émis quand une contrainte saisie ne peut pas être honorée. **Deux producteurs** — (1) `parse_v2_constraints` au parse, en `WARNING`, quand la règle n'est pas traduisible en terme solver (sans équipe cible, dispo coach reçue en non-HARD, règle de gymnase écrasée) — audit P0.1, traçabilité UI↔engine ; (2) `diagnose_locked_slot_violations` après construction du modèle, en **INFO**, quand un verrou HARD a rendu la contrainte inatteignable (cf. §2). Les deux rejoignent `diagnostics[]` via `main.py`. Cf. `docs/architecture/constraint-matrix.md` et `engine/docs/constraint-vocabulary.md`.

---

## 4. Contraintes

### 4.1 Niveaux de règle (`ruleType`)

| Niveau | Sémantique | Traitement solver |
|--------|-----------|-------------------|
| `HARD` | Impératif — faisabilité | Contrainte CP-SAT (`model.Add(...)`) |
| `PREFERRED` | Souhait — optimisation | Bonus objectif Level-2 (pas de contrainte hard) |
| `LOCK` | Règle « figée » | Traité **exactement comme `HARD`** : TIME/DAY → `time_windows` ; FACILITY → `forced_venues` / `venue_minimums`. La collection `fixed_slots` n'est alimentée par **aucune** branche de `parse_v2_constraints` (chemin résiduel). ⚠ Ne pas confondre avec `slotTemplates[].lockLevel`, autre mécanisme (cf. §5 Hard locks) |

> `BONUS` **n'existe plus dans le produit** (ENG-12) :
> l'enum backend `ConstraintRuleType` ne compte plus que HARD/PREFERRED/LOCK — une écriture
> `ruleType: "BONUS"` rend 422 avant même d'atteindre le moteur. `parse_v2_constraints` n'a donc
> plus de normalisation `BONUS → PREFERRED` à faire (`rule_type` reste une chaîne libre côté
> Pydantic — c'est le backend, pas le moteur, qui ferme la porte).

### 4.2 Family & Scope

- **`family`** : catégorie de règle. Valeurs reconnues (`_KNOWN_FAMILIES`, `constraints/parsing.py`) : `TIME`, `DAY`, `FACILITY`, `COACH_AVAILABILITY`. Types legacy reconnus (`_KNOWN_TYPES`) : `TEAM_COACH`, `COACH_PLAYER_UNAVAILABILITY`, `PRIORITY_TIER`. Une contrainte dont **ni** la famille **ni** le type n'est reconnu est loggée comme dérive de contrat.
- **`scope`** : cible de la règle. Valeur vue : `TEAM`. (D'autres scopes peuvent exister mais ne sont pas traités différemment dans le code lu.)
- **`scopeTargetId`** : ID de la cible (team, coach, venue selon family/scope).

### 4.3 Mapping `parse_v2_constraints` (constraints[] → collections solver)

| Condition de match | Collection alimentée |
|--------------------|---------------------|
| `ruleType == "LOCK"` + `family in ("TIME","DAY")` | `time_windows` (traité comme `HARD` par `add_time_window_constraints`) |
| `ruleType == "LOCK"` + `family == "FACILITY"` | même traitement que `HARD` (`forced_venues` / `venue_minimums`) |
| `type == "TEAM_COACH"` (legacy) | `team_coach_map[teamId]` → coachIds (MAIN seuls — un ASSISTANT n'est pas une ressource exclusive). **Posée sur le modèle** (`model.team_coach_map`, `main.py`, ENG-17) : c'est elle qui nomme le `coachId` des créneaux GÉNÉRÉS, pas les `slotTemplates` seuls — sans quoi les diagnostics coach resteraient muets sur le chemin dominant |
| `type == "COACH_PLAYER_UNAVAILABILITY"` (legacy) | `team_player_map[teamId]` → coachIds |
| `family == "COACH_AVAILABILITY"` | `coach_unavailability[scopeTargetId]` → `unavailableDays` |
| `family == "FACILITY"` + `preferredVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `preferredVenueId` |
| `family == "FACILITY"` + `forcedVenueId` + `HARD` + `scope=TEAM` | `forced_venues[scopeTargetId]` = `forcedVenueId` |
| `family == "FACILITY"` + `preferredVenueId` + `PREFERRED` + `scope=TEAM` | `preferred_venues[scopeTargetId]` → **ensemble** de gymnases (les préférences se CUMULENT, bonus si la séance tombe dans l'un d'eux ; le last-wins + INFO ne reste que sur `forced_venues`) |
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
| 10 | `one_session_per_day` | ≤ 1 session/jour/équipe (sans exception — le drapeau `allowMultipleSessionsPerDay` n'est plus dans le contrat, jamais écrit avant son retrait) |
| 11 | `age_ascending` | Teams plus jeunes entraînées plus tôt (même venue+jour) — exempt si `ageMin=None` ou HARD-locked |
| 12 | `max_consecutive_days` | une ÉQUIPE ne s'entraîne pas `maxConsecutiveDays` jours de suite (défaut 3, bornes 2-5) ; posé seulement si la règle est HARD (opt-in, naît `OFF`) |
| 13 | `shared_block` | mutualisation par BLOC, **SEULE notion de mutualisation** (le groupe {équipes, K} `sharedTrainings` n'existe plus) : un bloc (`sharedBlocks`) se comporte comme UNE équipe, ses séances lui APPARTIENNENT. Modélisation **LIAGE** (posée en tête de `add_level_1_hard_constraints`, AVANT la capacité gymnase, `constraints/__init__.py`) : pour chaque case candidate, une variable de décision propre au bloc `b[case]` liée à chaque membre par `x[membre, case] ≥ b[case]` (UNIDIRECTIONNEL, **pas** de réification depuis la co-présence — ce qui dissolvait le double-comptage de l'ancien modèle groupe). Le liage donne gratis la sémantique membre (consomme une séance, `one_session_per_day`, repos coach, enchaînements, objectif — tous exprimés sur `x`) ; seule la capacité gymnase demande une chirurgie (`(n_libres−1)·b`, `shared_block_room_relief`, même patron que le crédit des verrouillés). Garde de distinctness inter-blocs. Vide ⇒ aucune pose, chemin byte-identique, aucun golden avec bloc |
| 14 | `team_link` | Lot PASSERELLES — deux équipes déclarées `MANDATORY` ne se chevauchent JAMAIS (`var_a + var_b ≤ 1`) ; 0 si `teamLinks` absent/vide ou tout `PREFERRED` (le PREFERRED est un malus objectif, pas une contrainte dure) |
| 15 | `travel_time` | règle `travelTime` **MANDATORY** seule : interdit dur un enchaînement cross-gymnase dont le battement est plus court que le barème (voiture/à pied selon `isVehicled`, ou à pied pour une passerelle) ; 0 si la règle est inactive, `PREFERRED`, ou `venueTravelTimes` vide. Résidu possible SEULEMENT entre deux verrous HARD contradictoires, ANNONCÉ par le diagnostic `travel_time_infeasible` (`_diagnose_travel_times`), jamais un INFEASIBLE muet |

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
- **Déterminisme (ENG-25)** : les agrégations par équipe itèrent sur des clés `str`,
  dont le hash est randomisé PAR PROCESSUS. `add_preferred_day_bonus` **trie**
  (`objective.py`) — sans quoi l'ordre d'ajout des termes soft, donc le chemin de recherche de
  CP-SAT, changeait d'un run à l'autre : même payload, même `solverSeed`, planning différent (de
  valeur d'objectif identique). ⚠ `PYTHONHASHSEED` n'est **délibérément pas figé** : ce serait
  traiter le symptôme, et le figer désarme la protection contre les collisions de hash. L'ordre se
  décide là où il compte. Gardé par `tests/test_deterministic_term_order.py`.
- **Harnais de test (ENG-26)** : `tests/support/pipeline.py` annonce la version lue
  depuis `CONTRACT_VERSION` — sans cela, `solve_payload` court-circuite la couche FastAPI et son
  garde de contrat ne tourne jamais, et toute la suite sémantique validerait une enveloppe que
  personne n'accepterait en production. Gardé par `tests/test_harness_speaks_the_real_contract.py`.
- **Workers** : `num_search_workers` **adaptatif** (`_adaptive_workers`, main.py) — complexité `n_teams×n_venues` ≤200 → **1** (déterministe, dont dépendent les goldens petits) · else → **8** (le worker unique trouve l'optimum en ~2s sur les problèmes denses riches en soft mais ne le prouve pas — 612s de blocage sur BCCL ; le portfolio 8 workers ferme la preuve en ~2s, même valeur d'objectif, assignation non-déterministe mais valeur stable). Appliqué aux deux phases.
  - ⚠️ **Réconciliation spec** : `specs/initiales/…contraintes_v2.md §2` promet « même entrée + même `solver_seed` + même version → planning **exactement** identique ». Avec les workers adaptatifs, cette garantie n'est **exacte** qu'en dessous du seuil (≤200 complexité, 1 worker) ; au-dessus, seule la **valeur d'objectif** (score) est reproductible, pas l'arrangement exact (le gestionnaire ajuste de toute façon — repro exacte au-delà du seuil : keep délibéré, `specs/evolution/roadmap.md` « Déterminisme exact du plan sur gros clubs »). Les initiales étant gelées, la réconciliation vit ici.
- **Objectif Level-2** : `SCORE_FORMULA_VERSION` **actuel = `T24_LEVEL_2_FIXED_WEIGHTS_V13`**
  (`weights.py` — V13 = bonus de comblement référencé au socle, §5 « Socle reference bonus »).
  Maximise somme pondérée.
  ⚑ **Principe fondateur : LE REMPLISSAGE PRIME SUR LE CONFORT.** Une séance
  placée — même dans un gymnase, un jour ou à une heure non préférés — vaut TOUJOURS mieux qu'un trou ;
  le confort ne sert qu'à départager des solutions qui placent le MÊME nombre de séances. Deux garde-fous :
  **(1)** tout poids de confort reste sous **21** (valeur minimale d'une séance : tier D 1 + session_count 20),
  cumul compris (10+5+5 = 20 < 21) ; **(2)** `missing_session` **−1000 PAR séance manquante** sous le quota
  — le garde-fou existe parce qu'un plancher qui ne coûtait que pour une équipe à ZÉRO séance laissait une
  préférence de confort racheter une séance manquante (mesuré sur le club réel : OPTIMAL à 89/90). Poids fixes (`LEVEL_2_OBJECTIVE_WEIGHTS`, objective.py — source de vérité, ne pas figer d'autres valeurs ici) :

| Critère | Poids |
|---------|-------|
| Tier S | 10 000 |
| Tier A | 1 000 |
| Tier B | 100 |
| `session_count` | 20 |
| `preferred` | **10** |
| `avoided_venue` | **−10** (même seuil que `preferred` : fuir un gymnase ne doit pas coûter une séance) |
| `preferred_day` | **5** |
| `preferred_time` | **5** |
| Tier C | 10 |
| Tier D | 1 |
| **`missing_session`** | **−1000 PAR séance sous le quota** (domine tout cumul de confort) |
| `rest` | 3 |
| `spacing` | −2 (malus soft, ALIGN-06 : deux séances d'une même équipe sur des jours consécutifs) |

- **Contraintes v2 effectives** : `parse_v2_constraints` → `ParsedConstraints` (TypedDict). Indispo coach par jour (COACH_AVAILABILITY `unavailableDays`/`availableDays`, jours int — la CIBLE est le `scopeTargetId`, `config.coachId` n'existe plus) appliquée ; `FACILITY_CAPACITY` (`maxTeams`) n'est plus une famille reconnue (la capacité vit sur `trainingSlots[].capacity`, dérivée côté backend) ; LOCK TIME/DAY = HARD ; `allowedDays` = whitelist ; `forcedDays`/`forbiddenDays`/min-maxStartTime HARD. `preferred_time` (soft) + repos lendemain de match (règle implicite, `matchDay` → jour+1 libre, poids `rest`).

- `UNPLACED_PENALTY = 100 000` (par team non placée, sauf `hard_satisfied_team_ids`).
- **Chaining bonus** (phase 2 uniquement) : `CHAINING_TIER_WEIGHTS = {S:8, A:6, B:4, C:2, D:1}` — bonus entier pour sessions back-to-back même venue même jour, une par PERSONNE présente aux deux séances (coach OU joueur de l'équipe, via `team_player_map` ; `set` → une personne compte une fois par paire), poids du tier le plus haut de la paire. Plafond **par personne** = 8 par construction : < 21 (valeur minimale d'une session placée, plancher `missing_session` −1000 en sus → coût de suppression ≥ 1021) pour ne jamais sacrifier un placement même en empilant k personnes distinctes (k×poids ≤ 8k, k restant une poignée), et ≤ 8 (écart C−D = 9) pour un terme isolé ne jamais voler un slot à un tier supérieur.
- **Proximity term** : `PLACEMENT_PROXIMITY_WEIGHT = 9` par variable dont la clé figure dans `previousAssignments` (dérivé de `build_stability_terms`, même dédup, HARD absent de `model.x` jamais compté), replié dans le PLACEMENT (phase 1, `extra_placement_terms`). Fenêtre prouvée dans `weights.py` : < `preferred` 10 (une règle saisie prime), < 21 (jamais de suppression), = C−D 9 (égalité exacte tranchée par la sous-bande phase 2 vers le précédent). EXCLU du score rapporté (soustrait dans `_solve`). Jamais coexistant avec le bonus socle (branches backend exclusives).
- **Socle reference bonus** : champ optionnel `socleReferenceAssignments` sur `/generate`
  (`SocleReferenceAssignmentSchema` : `teamId`/`dayOfWeek`/`startTime`, SANS `venueId` — le
  gymnase reste libre), émis par le backend UNIQUEMENT en comblement (placements de la version
  POINTÉE du socle). Chaque variable `model.x[(team, venue, day, start)]` dont `(team, day,
  start)` figure dans ce bloc porte `+SOCLE_REFERENCE_TIER_WEIGHTS[tier(team)]` dans le
  PLACEMENT (phase 1, `add_socle_reference_bonus`) : poids CROISSANT avec le tier (S=20, A=18,
  B=16, C=14, D=12 — `objective/weights.py`), toujours > `preferred` (10) et < 21 (jamais de
  suppression de séance). Absent/vide ⇒ payload byte-identique. Jamais coexistant avec
  `previousAssignments`/la proximité (branches backend exclusives).
- **Stability term — convergence moteur** (contrat 2.11, `objective.py`, sous-bande de phase 2 — voir §POST /generate) : `STABILITY_TERM_WEIGHT = 1` par variable `model.x[team, venue, day, start]` dont la clé figure dans `previousAssignments` (`build_stability_terms`, dédup par clé, clé normalisée exactement comme `model.x` via `_format_time(_time_to_minutes(...))`). `CHAINING_STABILITY_MULTIPLIER = 4096` sépare **lexicographiquement** stabilité et chaînage dans l'objectif (`placement + 4096 × chaining + stability`) : la masse MAX de stabilité (poids 1 × cap `previousAssignments` 2000 = 2000) reste sous le poids d'un seul point de chaînage amplifié — la stabilité ne peut donc renverser AUCUN arbitrage de chaînage, seulement départager des ex æquo exacts. Granularité **par séance**, aucun réglage club. Non appliqué à `/validate-assignments` ni `/place-matches` (déplacement manuel = volonté explicite, jamais une convergence).
- **Hard locks** : `HARD_LOCK_LEVEL = "HARD"` (model.py). Slots `lockLevel == "HARD"` → variable forcée à 1, venue bloquée pour autres teams sur ces créneaux. `blocked_venue_slots` retire le `(venue,day,start)` pour les autres équipes : un verrou prend le **créneau entier**, même divisible (`capacity>1`) — ALIGN-07, comportement assumé (décision gestionnaire). **Exception** : le **partenaire de bloc partagé** (`sharedBlocks`) d'un verrou épinglé sur la MÊME case n'est PAS retiré — et lui seul (une équipe hors bloc reste refusée) — sans quoi la transcription du socle (qui épingle en HARD) rendrait tout comblement INFEASIBLE dès qu'un bloc est transcrit. L'exception n'ouvre la case qu'au titre d'une séance de bloc ACTIVE (`x ≤ Σ b`, `structural.py`), et une case où tous les membres d'un ou plusieurs blocs sont épinglés est attribuée à AU MOINS l'un d'eux (`Σ b ≥ 1` par case, `targeting.py` — pas `== 1` par bloc : deux blocs imbriqués peuvent partager une case) — sinon le membre libre rejoindrait l'épingle comme simple voisin et le bloc se retrouverait partagé deux fois. Deux sites : la construction (`model.py`, `_block_partners`/`_hard_locks_by_case`) et le balayage par sous-départs (`constraints/structural.py`, verrous partenaires décomptés du `max_locked` sur la case exacte). Partager un créneau divisible hors bloc = co-épingler explicitement les N équipes ; le diagnostic over-capacity (`result_builder/diagnostics.py::_diagnose_conflicts`) ne se déclenche qu'au-delà de `capacity`. Gardé par `engine/tests/semantic/test_hard_lock_divisible_slot.py` et `engine/tests/semantic/test_fill_pinned_block_partner.py`.

### Per-club asyncio locks

- `_club_locks: dict[str, asyncio.Lock]` + `_club_locks_guard: asyncio.Lock` (module-level, `main.py`).
- `get_club_lock(club_id)` : crée/récupère un `asyncio.Lock` par `club_id` sous le guard.
- `generate_schedule` : `async with lock: await build_schedule(input_data)` — empêche la génération concurrente pour le même club. Différents clubs peuvent être résolus en parallèle.

---

## 6. Communication Backend ↔ Engine

- **Backend → Engine** : HTTP POST `http://engine:8000/generate` depuis `GenerateScheduleHandler` (backend Symfony). Payload = `ScheduleInputSchema` (tout le contexte : venues, teams, coaches, constraints, slotTemplates, implicitRules, sharedBlocks…). ⚠ Le champ racine `priorityTiers` du schéma (§3) n'est **jamais** peuplé par le backend : les tiers de priorité voyagent en contraintes `PRIORITY_TIER` (`ScheduleConstraintBuilder::serializePriorityTierConstraints`), pas en clé racine.
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