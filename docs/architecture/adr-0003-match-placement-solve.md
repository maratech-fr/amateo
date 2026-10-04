# ADR-0003 — Le solve de placement des matchs (P1-4 PR D)

**Date** : 2026-08-03 · **Statut** : accepté (décisions fondateur du cadrage
[`docs/archive/p1-4-cadrage-module-matchs.md`](../archive/p1-4-cadrage-module-matchs.md) §7,
validées le 2026-08-03).

## Contexte

Le module matchs (P1-4) doit placer les matchs domicile — heure + salle sur des **dates réelles imposées
par la fédération** — sous les contraintes de capacité (fenêtres d'accès match, indisponibilités), les
fenêtres ligue et les préférences (habitudes, passerelles, coachs). L'objectif produit : « une tâche de
3 jours pleins qui doit passer à 3 heures ». Le solve hebdo (`/generate`) raisonne en semaine-type sans
dates : forcer les matchs dedans aurait tordu les deux problèmes.

## Décisions

### 1. Un SECOND problème solveur, endpoint et schémas séparés, UN seul contrat

`POST /place-matches` avec `match_input_schema.py`/`match_output_schema.py`, solveur
`app/solver/match_placement.py` — le solve hebdo est **intouché** (mêmes fichiers, mêmes golden). Un seul
`CONTRACT_VERSION` couvre les trois endpoints (`/generate` · `/place-matches` · `/validate-assignments`),
gardé MAJOR-only côté engine (`engine/CONTRACT_VERSION`) — un bump ajout reste MINOR. Gardé par
`ContractSchemaTest` + `MatchPlacementContractSchemaTest` (phase1 + contract).

### 2. Rail ASYNCHRONE (amendement 2026-10-04) — Messenger, patron de la génération

`POST /api/fixtures/place` enfile désormais un `PlaceMatchesMessage` et répond **202** avec
`{runId, status}` ; `PlaceMatchesHandler` (transport `async` partagé avec la génération) solve,
applique le résultat, décompte le crédit Découverte au SUCCÈS et publie la bascule terminale
(COMPLETED/FAILED) sur le 3ᵉ topic Mercure fixe par club `club:{clubId}:placement`
(`App\Mercure\MercureTopic::forPlacement`). `GET /api/fixtures/placement-run` rend le dernier run
du club+saison courants (lecture seule, ouverte à tout membre). Anti-double-clic PAR CLUB inchangé
(`MatchPlacementLock`, Redis, préfixe dédié) — **pris par le contrôleur**, tenu pendant tout le run,
**relâché par le worker** (token porté par le message) ; TTL = le budget du run entier
(`PlaceMatchesMessage::budgetSecondsFor`, §4). Motif du basculement : le solve est désormais
découpé **semaine ISO par semaine ISO** côté engine (§4, ENG-50) — un lot réel (141 domiciles)
dépasse en pratique tout budget tenable dans la requête HTTP d'un gestionnaire, et la mémoire du
solve (ENG-49) doit pouvoir être rendue au système entre deux semaines sans tenir le processus
uvicorn du bout en bout.

**Concurrence inter-clubs** : l'engine tient en plus un sémaphore GLOBAL, tous clubs confondus,
`max_concurrent_placements = 1` (`engine/app/core/config.py`, acquis dans `engine/app/main.py` autour du
solve). Le verrou club de `MatchPlacementLock` n'isole PAS deux clubs l'un de l'autre : ils partagent ce
jeton unique, et le second appel attend derrière le solve du premier — l'attente ne pèse plus que sur le
worker Messenger, jamais sur une requête HTTP d'un gestionnaire.

### 3. Best-effort « placement optionnel à poids dominant » — articulation avec ADR-0001

Chaque match plaçable porte un booléen `is_placed`, l'objectif maximise `10 000 × Σ is_placed + SOFT`.
**Aucune contrainte HARD n'est jamais violée dans la sortie** : un match sans candidat licite reste
non placé et sort NOMMÉ (`no_access_window` · `no_league_intersection` · `team_venue_forbidden` ·
`club_rule_no_slot` · `venue_unavailable` · `venue_full` · `not_selected`) — `club_rule_no_slot`
(P4-272 ③) marque un domaine par ailleurs licite vidé par les seules règles de match HARD du club ;
`team_venue_forbidden` (P4-272 ④) marque un domaine vidé par les seuls gymnases que l'équipe
s'interdit (`teams[].forbiddenVenueIds`, scope TEAM HARD) : les créneaux d'un gymnase sont d'abord
filtrés par les règles de club HARD, PUIS le gymnase est écarté du domaine s'il est interdit —
un créneau qui survit au premier filtre mais tombe sur ce second rend `team_venue_forbidden`,
testée AVANT le repli `club_rule_no_slot` (`_candidate_kickoffs`,
`engine/app/solver/match_placement.py`). Ce n'est pas la
relaxation silencieuse qu'interdit ADR-0001 — rien n'est relâché, l'impossible est épelé : le
« non-placé expliqué » EST le produit (le signal dérogation-tôt). Invariant gardé par
`assert_no_hard_violation` (tests sémantiques).

`venue_full` et `not_selected` se distinguent post-solve, sur l'occupation FINALE (placements retenus +
ancres fixes) : `venue_full` quand plus AUCUN créneau licite du match n'est libre ce jour-là (le gymnase
est réellement saturé) ; `not_selected` quand il en restait au moins un — le solve ne l'a simplement pas
retenu dans son budget, message « relancez le placement ». Reclassification pure, falsifiable dans les
deux sens sans avoir à saturer un budget pour de vrai (`_remaining_reason`,
`engine/app/solver/match_placement.py`).

### 4. Budget PAR SEMAINE (amendement 2026-10-04), déterminisme, poids documentés

Le moteur découpe désormais le placement **semaine ISO par semaine ISO** (`_partition_by_iso_week`,
`engine/app/solver/match_placement.py`, ENG-50) : chaque semaine (matchs TO_PLACE/FIXED/AWAY de la
semaine + ses `training_occupancies`) est solvée isolément, puis les résultats sont concaténés
(`_merge_week_results` — placements/non-plaçables/diagnostics en ordre stable, métriques SOMMÉES,
`status="failed"` seulement si TOUTES les semaines tentées ont échoué). `solverTimeoutSeconds` du
payload (`MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS = 35`) est désormais le budget **DE CHAQUE
SEMAINE**, pas un budget de bout en bout — un lot de N semaines ISO distinctes à placer consomme
jusqu'à `N × 35 s` de solve. `BUILD_BUDGET_SECONDS = 10.0` reste le budget de CONSTRUCTION de
**chaque sous-build** hebdomadaire (inchangé dans sa valeur, réappliqué par semaine). Le TTL du
verrou `MatchPlacementLock` et le timeout HTTP backend→engine suivent la même formule
(`PlaceMatchesMessage::budgetSecondsFor` : `N × (35 + 15) s + 60 s` de marge, `35` = budget/semaine,
`15` = `ENGINE_PER_WEEK_OVERHEAD_SECONDS`, `60` = `LOCK_TTL_MARGIN_SECONDS`) — détail complet dans
`specs/courantes/module-matchs.md` §3. Le solve lui-même vit dans un **processus fils jetable**
(ENG-49, `ProcessPoolExecutor(max_workers=1, max_tasks_per_child=1, mp_context=spawn)`,
`engine/app/main.py`) : toute sa mémoire native meurt avec le fils à chaque placement, et
`malloc_trim(0)` côté parent rend le reste des arènes glibc de l'orchestration FastAPI — un fils tué
(OOM) casse le pool (`BrokenProcessPool`), recréé pour que le placement suivant reparte sain. **1
worker** (bit-stable — les golden en dépendent), seed 42. Candidats au pas de **15 min** dans
(accès ∩ ligue).

**Warm-start glouton** (P4-240) : avant le solve, un premier-ajustement déterministe, matchs triés
(date, équipe), choisit pour chacun le candidat préféré — créneau idéal (habitude), sinon le
placement SOLVER courant s'il reste libre, sinon le premier créneau licite libre — et le donne à CP-SAT
comme UN seul jeu de hints (`add_hint`). Il absorbe l'ancien hint de stabilité : jamais deux hints
contradictoires sur le même match. Le poids `W_STABILITY` de la stabilité de re-solve (ci-dessous) est
inchangé ; seule sa manière d'entrer dans le modèle change, via l'une des trois branches du greedy plutôt
qu'un `add_hint` isolé.

La salle est tenue pour le **match seul** (`[coup d'envoi, coup d'envoi + matchMinutes]`), alignée sur
la règle que le radar de conflits applique déjà à l'occupation de salle. Une **personne** est un coach
OU une joueuse active de l'équipe (`teams[].players` — ids `CoachPlayerMembership` actifs, additif au
contrat, P4-240 ③ décision A) ; une joueuse pèse comme un coach MAIN. Le solveur **ignore toute
empreinte personne d'un match EXTÉRIEUR** (P4-240 ③, décision B — « c'est la vie » ; le solveur ne peut
de toute façon pas déplacer un extérieur puisque son heure est imposée par l'adversaire, et le radar §2
reste la seule source qui signale une indisponibilité réelle liée à un extérieur) : la fenêtre
**personne** (coach/joueuse, passerelle `NOT_SIMULTANEOUS`, entraînements projetés) ne provient donc
plus que des ancres FIXED (matchs à domicile déjà posés) et des entraînements projetés, et vaut toujours
`[coup d'envoi, coup d'envoi + matchMinutes]` — la fenêtre salle, sans trajet ni échauffement. Un match
AWAY reste émis au contrat (`roundTripMinutes` transporté, plus consommé) : il libère la protection
d'habitude de son équipe ce jour-là. Décision fondateur : « on s'échauffe sur le côté pendant le
match précédent ; deux matchs qui s'enchaînent, c'est OK et très courant » — le solveur n'interdit donc
pas l'enchaînement fédéral à 2 h que le radar accepte déjà.

Les durées (`matchMinutes`/`warmupMinutes`) sont **par équipe**, résolues côté backend par
`MatchDurationResolver` (override de catégorie sinon défaut de famille 75/90/105 min, échauffement
30 min — `MatchDurationProfile::fallback()` = 105/30 pour une catégorie sans famille) et portées par le
contrat (`teams[].matchMinutes`/`warmupMinutes`, Pydantic optionnels par défaut 105/30 — un payload
absent de ces champs garde l'ancien comportement). `warmupMinutes` reste au schéma mais n'est plus lu par
AUCUNE fenêtre du solveur (ni salle ni personne, depuis la décision B) : un match extérieur ne projetant
plus de fenêtre personne, il n'y a plus de trajet ni d'échauffement à y porter. Un match « enchaîné »
(BACK_TO_BACK, SOFT) est celui dont le suivant démarre exactement à la fin du match précédent
(`Δkickoff` variable selon les durées, plus une constante 2h15). **Asymétrie délibérée** : le radar de
conflits a cessé d'émettre la famille passerelle (`TEAM_LINK_OVERLAP`) mais le solveur GARDE son malus
SOFT `NOT_SIMULTANEOUS` (−40) — une préférence souple ne bloque jamais un placement, la retirer serait un
recul silencieux si la famille revenait un jour au radar.

Poids SOFT (produit, golden-épinglés) : conflit personne (coach MAIN ou joueuse active) −60 ·
indisponibilité de coach violée −60 (`W_COACH_UNAVAILABLE`, P4-272 ⑤ — MÊME poids qu'un conflit
personne, jamais une raison `unplaced` ni un élagage de domaine : une indisponibilité de coach est
TOUJOURS SOFT, PREFERRED forcé côté `MatchConstraint`, HARD refusé) · passerelle NOT_SIMULTANEOUS
violée −40 · règle de match CLUB PREFERRED violée −30 (`W_CLUB_RULE`,
P4-272 ③ — entre l'habitude et la passerelle ; une règle HARD, elle, n'entre jamais dans l'objectif,
elle élague le domaine, §3 ci-dessus) · habitude heure +15 / gymnase +5 (le jour est constant) ·
fenêtre habituelle protégée −25 · BACK_TO_BACK enchaîné +15 · coach ASSISTANT −10 · stabilité re-solve
+8 (+ hint) · compactage −1 **par pas de 15 min** de trou (jamais par minute — un trou de 6 h ne doit
pas renverser un conflit de coach). **La protection de fenêtre d'habitude ne s'applique jamais au
créneau idéal PROPRE de l'équipe candidate** (`is_own_ideal`, P4-271, 2026-09-29 — la « rotation A/B »
d'origine, RMM-5, a été retirée : deux créneaux idéaux physiquement identiques, l'ex-alternance A/B,
protègent désormais la MÊME fenêtre plutôt que deux mécanismes séparés) : sans cette exception, le
bonus d'habitude d'une équipe perdrait toujours face à la protection posée par une AUTRE équipe dont
le créneau idéal coïncide (même gymnase+jour+heure).

### 5. Ancres : `Fixture.placementSource` (MANUAL | SOLVER)

Le marqueur qui rend le re-solve possible. MANUAL (posé par tout geste API du gestionnaire) et
SUBMITTED/VALIDATED = **FIXED** : consomment leur créneau, ne bougent JAMAIS. SOLVER = re-plaçable
(bonus de stabilité). Écriture directe en `PLACED` (patron du planning : le solveur écrit, la boucle
manuelle ajuste) ; l'applier recharge chaque fixture et n'écrit que si le solveur y est encore autorisé —
un geste manuel pendant les secondes du solve gagne toujours. Un match déposé qui a PERDU sa salle
n'est ni ancre ni plaçable : ignoré du payload.

Dans le modèle, les ancres FIXED **élaguent les candidats** qu'elles couvrent au lieu d'entrer dans le
NoOverlap comme intervalles fixes. La boucle manuelle ne bloque jamais une collision (décision
fondateur — le diagnostic alerte), donc deux ancres manuelles PEUVENT se chevaucher : en intervalles
fixes, ce chevauchement rendait le modèle entier INFAISABLE et tout ressortait `venue_full`. NR :
`test_colliding_fixed_anchors_never_sink_the_whole_solve`. Le geste UI : cadenas (re-stamp MANUAL) /
« rendre au solveur » (SOLVER, accepté par le serveur SEULEMENT à placement inchangé — 422 sinon :
on ne peut pas étiqueter SOLVER un placement qu'on vient de choisir à la main).

**Une fenêtre calendaire optionnelle produit des ancres SUPPLÉMENTAIRES (P4-240 ④)** :
`PlaceMatchesController` accepte un corps `{from, to}` optionnel (dates incluses, 422 si invalide),
transmis à `MatchPlacementPayloadBuilder::build()`. Sans corps, le payload est identique à l'octet.
Avec une fenêtre, un match HORS fenêtre bascule dans la branche d'ancrage même s'il aurait été
TO_PLACE sans elle : posé (venue+kickoff, solveur compris) → FIXED, protégé au même titre qu'un
placement manuel ; non posé → absent du payload ; un extérieur hors fenêtre est omis (son empreinte
personne est déjà ignorée depuis la décision B, il ne portait que sa date). C'est le mécanisme
derrière « Placer ce week-end » (`specs/courantes/module-matchs.md` §3/§5) : résoudre une seule
semaine sans jamais remettre en jeu un placement déjà posé ailleurs, gardé par le scénario Behat
« placer un week-end ne déplace pas un match posé ailleurs ». **En offre Découverte** (mode restreint,
`PlanEntitlements::outputBudget()['restricted']`, club non démo, pool > 0), c'est la SEULE fenêtre
admise : le contrôleur refuse 403 un appel sans fenêtre ou dont l'écart `from`→`to` dépasse 6 jours
(une semaine calendaire) — défense serveur derrière le bouton global désactivé côté front, 1 clic =
1 crédit inchangé (`CreditBudgetSubscriber`).

### 6. Le backend PROJETTE, l'engine reste plat

Les règles métier ne traversent pas la frontière : occupations d'entraînement **datées** projetées par
`TrainingCalendarContext` + `EffectiveScheduleResolver` (ADR-0002 jamais ré-implémenté côté engine),
estimation d'heure extérieure par le MÊME `AwayKickoffEstimator` que le radar, enveloppe ligue résolue
par `LeagueEnvelopeResolver` (portage serveur de la jointure tolérante d'`envelope.ts` — non résolue =
aucun HARD + diagnostic INFO ; durcissement = dette roadmap).

## Conséquences

- L'engine porte deux problèmes : gabarit hebdo (gros, async) et placement daté (petit, sync).
- Tout changement de poids est un changement de PRODUIT : golden à ré-épingler consciemment.
- NR : sémantique (`test_match_placement_semantics.py`), golden, contrat (phase1), feature Behat
  `placement-des-matchs.feature` (sens du placement de bout en bout), feature
  `generation-du-planning-de-saison.feature` (le planning hebdo n'est jamais affecté par une évolution
  du contrat de placement), `CrossStack/MatchPlacementSemanticsGateTest` (**bloquant**, P4-240 — le
  contrat de placement contre le VRAI engine : tout ce qui est plaçable est placé, le vocabulaire des
  cinq raisons reste exact).
