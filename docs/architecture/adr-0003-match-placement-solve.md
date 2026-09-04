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
`CONTRACT_VERSION` couvre les deux endpoints : bump **2.1 → 2.2** (ajout = MINOR ; le check MAJOR-only de
l'engine laisse passer, un backend 2.2 face à un engine 2.1 tombe en 404 propre sur la route absente).
Gardé par `ContractSchemaTest` (2.2) + `MatchPlacementContractSchemaTest` (phase1 + contract).

### 2. Rail SYNCHRONE — pas de Messenger, pas de Mercure

Le problème est minuscule pour CP-SAT (~10⁴ booléens : ~124 matchs × ~80 candidats) : solve mesuré en
secondes. Le rail asynchrone du planning existe pour des solves de 650 s ; aucun de ses coûts (message,
statut, topic, watchdog) n'est justifié ici — et le topic Mercure durci est façonné sur un `Schedule`
qu'un placement n'a pas. `POST /api/fixtures/place` répond dans la requête ; anti-double-clic par
`MatchPlacementLock` (Redis, préfixe dédié — ne partage PAS le verrou de génération : données disjointes).
**Seuil de bascule** : si un club réel dépasse ~20 s de solve, on repasse en rail async (décision à
re-poser) — le contrat engine ne changerait pas.

### 3. Best-effort « placement optionnel à poids dominant » — articulation avec ADR-0001

Chaque match plaçable porte un booléen `is_placed`, l'objectif maximise `10 000 × Σ is_placed + SOFT`.
**Aucune contrainte HARD n'est jamais violée dans la sortie** : un match sans candidat licite reste
non placé et sort NOMMÉ (`no_access_window` · `no_league_intersection` · `venue_unavailable` ·
`venue_full`). Ce n'est pas la relaxation silencieuse qu'interdit ADR-0001 — rien n'est relâché,
l'impossible est épelé : le « non-placé expliqué » EST le produit (le signal dérogation-tôt).
Invariant gardé par `assert_no_hard_violation` (tests sémantiques).

### 4. Budget fixe, déterminisme, poids documentés

30 s par défaut (plafond payload 60 s), **1 worker** (bit-stable — les golden en dépendent), seed 42.
Candidats au pas de **15 min** dans (accès ∩ ligue), l'empreinte 2h15 entière dans la fenêtre d'accès
(l'échauffement occupe la salle). Poids SOFT (produit, golden-épinglés) : conflit coach MAIN −60 ·
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
(DOC-2) n'est ni ancre ni plaçable : ignoré du payload.

**Amendement 2026-08-03 (PR E1, bug attrapé par `smoke-place-matches.sh`)** : dans le modèle, les
ancres FIXED **élaguent les candidats** qu'elles couvrent au lieu d'entrer dans le NoOverlap comme
intervalles fixes. La boucle manuelle ne bloque jamais une collision (décision fondateur — le
diagnostic alerte), donc deux ancres manuelles PEUVENT se chevaucher : en intervalles fixes, ce
chevauchement rendait le modèle entier INFAISABLE et tout ressortait `venue_full`. NR :
`test_colliding_fixed_anchors_never_sink_the_whole_solve`. Le geste UI : cadenas (re-stamp MANUAL) /
« rendre au solveur » (SOLVER, accepté par le serveur SEULEMENT à placement inchangé — 422 sinon :
on ne peut pas étiqueter SOLVER un placement qu'on vient de choisir à la main).

### 6. Le backend PROJETTE, l'engine reste plat

Les règles métier ne traversent pas la frontière : occupations d'entraînement **datées** projetées par
`TrainingCalendarContext` + `EffectiveScheduleResolver` (ADR-0002 jamais ré-implémenté côté engine),
estimation d'heure extérieure par le MÊME `AwayKickoffEstimator` que le radar, enveloppe ligue résolue
par `LeagueEnvelopeResolver` (portage serveur de la jointure tolérante d'`envelope.ts` — non résolue =
aucun HARD + diagnostic INFO ; durcissement = dette roadmap (iv), PR E).

## Conséquences

- L'engine porte deux problèmes : gabarit hebdo (gros, async) et placement daté (petit, sync).
- Tout changement de poids est un changement de PRODUIT : golden à ré-épingler consciemment.
- NR : sémantique (`test_match_placement_semantics.py`), golden, contrat (phase1), feature Behat
  `placement-des-matchs.feature` (sens du placement de bout en bout, a remplacé
  `smoke-place-matches.sh` — P4-165), feature `generation-du-planning-de-saison.feature` (le
  planning hebdo survit au 2.2).
