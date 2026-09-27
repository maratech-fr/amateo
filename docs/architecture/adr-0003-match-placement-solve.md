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

### 2. Rail SYNCHRONE — pas de Messenger, pas de Mercure

Le problème est minuscule pour CP-SAT (~10⁴ booléens : ~124 matchs × ~80 candidats) : solve mesuré en
secondes sur un club réel, assumé jusqu'à un budget de 60 s de bout en bout (§4). Le rail asynchrone du
planning existe pour des solves de plusieurs centaines de secondes ; aucun de ses coûts (message, statut,
topic, watchdog) n'est justifié ici — et le topic Mercure durci est façonné sur un `Schedule` qu'un
placement n'a pas. `POST /api/fixtures/place` répond dans la requête ; anti-double-clic PAR CLUB par
`MatchPlacementLock` (Redis, préfixe dédié — ne partage PAS le verrou de génération : données disjointes).

**Concurrence inter-clubs** : l'engine tient en plus un sémaphore GLOBAL, tous clubs confondus,
`max_concurrent_placements = 1` (`engine/app/core/config.py`, acquis dans `engine/app/main.py` autour du
solve). Le verrou club de `MatchPlacementLock` n'isole PAS deux clubs l'un de l'autre : ils partagent ce
jeton unique, et le second appel attend derrière le solve du premier. Si cette attente plus son propre
solve dépasse le timeout HTTP du contrôleur (`PlaceMatchesController::HTTP_TIMEOUT_SECONDS`, 90 s), il
reçoit un 502 propre (« Le solveur n'a pas répondu — réessayez. ») — rien n'est écrit, l'applier ne tourne
jamais sur un appel qui a levé une exception de transport. **Seuil de bascule vers l'async** : à re-poser
sur mesure si un club réel dépasse en pratique le budget de 60 s — le contrat engine ne changerait pas.

### 3. Best-effort « placement optionnel à poids dominant » — articulation avec ADR-0001

Chaque match plaçable porte un booléen `is_placed`, l'objectif maximise `10 000 × Σ is_placed + SOFT`.
**Aucune contrainte HARD n'est jamais violée dans la sortie** : un match sans candidat licite reste
non placé et sort NOMMÉ (`no_access_window` · `no_league_intersection` · `venue_unavailable` ·
`venue_full` · `not_selected`). Ce n'est pas la relaxation silencieuse qu'interdit ADR-0001 — rien n'est
relâché, l'impossible est épelé : le « non-placé expliqué » EST le produit (le signal dérogation-tôt).
Invariant gardé par `assert_no_hard_violation` (tests sémantiques).

`venue_full` et `not_selected` se distinguent post-solve, sur l'occupation FINALE (placements retenus +
ancres fixes) : `venue_full` quand plus AUCUN créneau licite du match n'est libre ce jour-là (le gymnase
est réellement saturé) ; `not_selected` quand il en restait au moins un — le solve ne l'a simplement pas
retenu dans son budget, message « relancez le placement ». Reclassification pure, falsifiable dans les
deux sens sans avoir à saturer un budget pour de vrai (`_remaining_reason`,
`engine/app/solver/match_placement.py`).

### 4. Budget fixe, déterminisme, poids documentés

60 s de bout en bout (`solverTimeoutSeconds` du payload, `MatchPlacementPayloadBuilder`) — porté de 30 s
par P4-240 pour absorber un gros lot (une fixture réelle à 141 matchs domicile) sans changer de rail. La
chaîne de timeouts qui l'encadre (verrou `MatchPlacementLock` 120 s, HTTP contrôleur 90 s, nginx
fastcgi/proxy 120 s, PHP `max_execution_time` 120 s, client frontend `ky` 120 s sur cet appel seul) est
détaillée dans `specs/courantes/module-matchs.md` §3. **1 worker** (bit-stable — les golden en dépendent),
seed 42. Candidats au pas de **15 min** dans (accès ∩ ligue).

**Warm-start glouton** (P4-240) : avant le solve, un premier-ajustement déterministe, matchs triés
(date, équipe), choisit pour chacun le candidat préféré — créneau d'habitude/rotation, sinon le
placement SOLVER courant s'il reste libre, sinon le premier créneau licite libre — et le donne à CP-SAT
comme UN seul jeu de hints (`add_hint`). Il absorbe l'ancien hint de stabilité : jamais deux hints
contradictoires sur le même match. Le poids `W_STABILITY` de la stabilité de re-solve (ci-dessous) est
inchangé ; seule sa manière d'entrer dans le modèle change, via l'une des trois branches du greedy plutôt
qu'un `add_hint` isolé.

La salle est tenue pour le **match seul** (`[coup d'envoi, coup d'envoi + matchMinutes]`), alignée sur
la règle que le radar de conflits applique déjà à l'occupation de salle. La fenêtre **personne** (coach,
passerelle `NOT_SIMULTANEOUS`, entraînements projetés) est
`[coup d'envoi − travelOut, coup d'envoi + matchMinutes + travelBack]` (`travelOut`/`travelBack` = la
moitié du trajet aller-retour AWAY, D3 ; nuls à domicile, où elle vaut donc la fenêtre salle) — réplique
exacte de `MatchFootprint::personConflictOccupancy` côté radar (`module-matchs.md` §2) : une personne
engagée deux fois n'a plus qu'à ARRIVER au coup d'envoi du second engagement. Décision fondateur : « on
s'échauffe sur le côté pendant le match précédent ; deux matchs qui s'enchaînent, c'est OK et très
courant » — le solveur n'interdit donc plus l'enchaînement fédéral à 2 h que le radar accepte déjà.

Les durées (`matchMinutes`/`warmupMinutes`) sont **par équipe**, résolues côté backend par
`MatchDurationResolver` (override de catégorie sinon défaut de famille 75/90/105 min, échauffement
30 min — `MatchDurationProfile::fallback()` = 105/30 pour une catégorie sans famille) et portées par le
contrat (`teams[].matchMinutes`/`warmupMinutes`, Pydantic optionnels par défaut 105/30 — un payload
absent de ces champs garde l'ancien comportement). `warmupMinutes` reste au schéma mais n'est plus lu par
aucune fenêtre du solveur : l'échauffement s'y projette côté PERSONNE via `travelOut`/`travelBack` (nuls
à domicile), pas via une fenêtre dédiée. Un match « enchaîné » (BACK_TO_BACK, SOFT) est celui dont le
suivant démarre exactement à la fin du match précédent (`Δkickoff` variable selon les durées, plus une
constante 2h15). **Asymétrie délibérée** : le radar de conflits a cessé d'émettre la famille passerelle
(`TEAM_LINK_OVERLAP`) mais le solveur GARDE son malus SOFT `NOT_SIMULTANEOUS` (−40) — une préférence
souple ne bloque jamais un placement, la retirer serait un recul silencieux si la famille revenait un
jour au radar.

Poids SOFT (produit, golden-épinglés) : conflit coach MAIN −60 ·
passerelle NOT_SIMULTANEOUS violée −40 · habitude heure +15 / gymnase +5 (le jour est constant) ·
fenêtre habituelle protégée −25 · **rotation A/B — attraction heure +15 / gymnase +5 · fenêtre de
rotation protégée −25** (RMM-5 : extension à parité stricte du mécanisme d'habitude, le créneau
partagé attire le domicile d'un membre son jour de rotation et se défend les dates où aucun membre
ne joue ; la suppléance backend garantit qu'un membre reçoit rotation OU habitude, jamais les deux) ·
BACK_TO_BACK enchaîné +15 · coach ASSISTANT −10 · stabilité re-solve
+8 (+ hint) · compactage −1 **par pas de 15 min** de trou (jamais par minute — un trou de 6 h ne doit
pas renverser un conflit de coach).

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
