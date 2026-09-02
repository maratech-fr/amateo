# Matrice contrainte UI ↔ engine

> **Règle de maintenance (P0.1 audit 2026-07-06)** : toute évolution de l'offre du wizard
> (`FAMILIES`/`RULES`/configs de `ConstraintsStep.tsx`) exige de mettre à jour
> **`engine/tests/semantic/constraint_matrix.py`** (la représentation machine, source du test
> paramétré `test_constraint_matrix.py`) **et** ce document. Le test Vitest
> `ConstraintsStep.test.tsx` fige l'offre côté UI — les deux verrous se tiennent.
> Origine : ENG-10/11/12/13 — le motif « contrainte saisie ≠ contrainte honorée » renaissait à
> chaque nouvelle option UI non câblée côté solveur.

Statuts : **dure** = jamais violée *par le solveur* (sur-contraint → non placé + diagnostic, jamais une
violation silencieuse — mais lire la section « Le verrou HARD est SOUVERAIN » plus bas : face à un
**verrou**, « dure » ne tient pas) · **soft** = orientée par l'objectif, ne bloque jamais la
faisabilité · **warning** = diagnostic `constraint_not_honored` explicite · **non proposé** = absent
de l'UI (verrouillé par le test Vitest).

## Offre du wizard (après P0.1)

| Famille · config | HARD (Obligatoire) | LOCK (Verrouillé) | PREFERRED (Préféré) |
|---|---|---|---|
| TIME `minStartTime`/`maxStartTime` | dure | dure (fenêtre figée) | soft |
| TIME `maxEndTime` | dure — mode **« Fini avant »** (fin = début + durée du créneau), toujours HARD (pas de sélecteur) *(ALIGN-04)* | — | — *(le chemin soft `preferredTime` ne lit que min/maxStartTime → une préférence serait un placebo)* |
| DAY `forbiddenDays` | dure | dure | **soft « éviter ces jours »** *(fix ENG-10 — était un placebo)* |
| DAY `allowedDays` | dure — mode **« uniquement »** (whitelist : l'engine interdit tous les autres jours), toujours HARD (pas de sélecteur) | — | — |
| FACILITY `preferredVenueId` | dure (salle forcée) | **dure** *(fix ENG-12 — était mort)* | soft |
| FACILITY `forcedVenueId` | dure — mode **« impose »** (doit se dérouler ici), toujours HARD (pas de sélecteur) | — | — |
| FACILITY `minAtVenueId` + `minAtVenueCount` | dure — mode **« au moins N »** (plancher de séances dans ce gymnase, ≠ forçage), toujours HARD (pas de sélecteur) *(ALIGN-05)* ; plancher inatteignable → **fail-soft** (diagnostic `venue_minimum_unreachable` ERROR, pas INFEASIBLE) ; **les jours déjà VERROUILLÉS de l'équipe à ce gymnase créditent le plancher** (P4-97 — une demande satisfaite par ses réservations ne réclame plus de place libre, ni au moteur ni au miroir pré-solve) ; le backend refuse `N > séances/semaine` avant génération | — | — |
| FACILITY `forbiddenVenueId` | dure | dure | **soft « éviter ce gymnase »** *(fix ENG-11 — était escaladé en dur → INFEASIBLE possible sur une préférence)* |
| COACH_AVAILABILITY `unavailableDays` | mode « indisponible » — dure + **union multi-contraintes** *(fix ENG-13)* | — l'UI force **Obligatoire** | — |
| COACH_AVAILABILITY `availableDays` | mode « disponible uniquement » — dure (whitelist, **intersection** multi) *(ALIGN — l'UI expose la capacité engine)* | — l'UI force **Obligatoire** | — |
| COACH_AVAILABILITY `fromTime` / `untilTime` | **fenêtre horaire** sur les jours listés (lot C #195, contrat 2.0→2.1) — dure. Absente = journée entière ; `fromTime` bloque `[from, 24:00)`, `untilTime` bloque `[00:00, until)`. Malformée ou inversée → repli journée entière (conservateur) | — l'UI force **Obligatoire** | — |

- **BONUS retiré de l'offre** *(ENG-12 : aucune sémantique définie nulle part)*. Les lignes BONUS
  déjà en base sont **normalisées en PREFERRED par l'engine** (honorées soft, jamais droppées).
- **Cibles** : équipe (TEAM) · groupe (tag → expansion backend en N contraintes TEAM) ·
  **« Toutes les équipes » (CLUB) → expansion backend en N contraintes TEAM** *(fix P0.1 — la case
  était un no-op silencieux)*. Une contrainte TIME/DAY/FACILITY sans cible qui atteindrait quand
  même l'engine produit un **warning** (filet).
- COACH_AVAILABILITY non-HARD reçu (legacy) : appliqué dur + diagnostic INFO.

## Vocabulaire compris par l'engine mais jamais émis par le wizard (« non proposé »)

`forcedDays` (engine-only : « au moins une séance ces jours-là » — ≠ « uniquement » ; le wizard émet `allowedDays`, cf. ENG-16) · `preferredDays` (lu par l'objectif, jamais émis — la racine d'ENG-10) ·
`slotTemplates` (verrou HARD), hors matrice constraints.

> **MàJ 2026-07-08** : `allowedDays` et `forcedVenueId` sont **émis par le wizard**
> (modes « uniquement »/« impose », toujours HARD) pour que l'édition des contraintes fixtures
> (`SM4 → Jean Vilar`, `Veterans vendredi uniquement`) fasse un aller-retour fidèle sans
> rétrograder en préférence. Les deux cellules passent `NOT_OFFERED → HONORED_HARD`.
> **Correctif ENG-16** : « uniquement » émet `allowedDays` (whitelist réelle), **pas** `forcedDays`
> (qui ne veut dire QUE « au moins une séance ces jours-là » et laissait les autres jours ouverts).
>
> **MàJ 2026-07-08 (angles morts d'alignement)** : trois capacités engine désormais alignées.
> `maxEndTime` (**ALIGN-04**, mode « Fini avant ») et `minAtVenueId`+`minAtVenueCount` (**ALIGN-05**,
> mode « au moins N ») deviennent **émis par le wizard** (toujours HARD). **ALIGN-06** ajoute une
> **règle implicite soft** : espacement des jours d'entraînement (poids `spacing = −2`, malus sur
> deux séances consécutives d'une même équipe) — activée pour toutes les équipes, ne bloque jamais
> (soft). `SCORE_FORMULA_VERSION` **bumpé V6→V7** (nouveau poids `spacing`).

## Le verrou HARD est SOUVERAIN — et depuis P2-9 il le dit (2026-07-28)

Un créneau **verrouillé** (onglet « Réserver », verrou manuel → `slotTemplates` `lockLevel=HARD`) est
**pré-placé HORS du solveur** : `model.py` ne crée jamais la variable `x[équipe, gymnase, jour, heure]`
correspondante. Or **toute** contrainte de cette matrice agit en forçant cette variable à 0 — sans
variable, il n'y a rien à forcer. La contrainte n'est pas *battue*, elle est **INATTEIGNABLE**.

Mesuré avant correctif, même payload, seule différence le verrou : sans verrou, SM1 est placée mardi
(coach indisponible le samedi, respecté) ; avec verrou, SM1 est placée **samedi**, `diagnostics` vide,
statut `completed`. Le produit affirmait avoir respecté une contrainte qu'il avait laissé tomber.

- **Ce qui n'a PAS changé** : le verrou reste souverain (décision fondateur **ALIGN-07**, non
  rouverte). Il prime sur tout, y compris une contrainte « dure » de la matrice ci-dessus.
- **Ce qui a changé** : le silence. `diagnose_locked_slot_violations`
  (`engine/app/solver/constraints.py`, appelée depuis `main.py`) croise les verrous avec les
  contraintes **SAISIES par le gestionnaire** — indisponibilité coach, fenêtres horaires, règles de
  jours (unies par équipe), gymnase interdit **et gymnase imposé** — et émet un `constraint_not_honored`
  **INFO** qui nomme la contrainte, l'équipe, le coach, le gymnase (imposé **et** réellement utilisé),
  le jour et l'heure. INFO et jamais ERROR : le gestionnaire a le droit d'épingler, il a le droit de
  savoir ce que son épingle a écrasé. La détection **réplique exactement** les règles d'application
  (intervalle coach comparé au début de créneau, min/max start des fenêtres, paire équipe+gymnase des
  interdits, écart équipe→gymnase imposé des forçages) — toute dérive entre les deux ferait mentir le
  diagnostic sur ce que le solveur a réellement fait.
- **`forced_venues` était le second angle mort** (fix `fix/forced-venues-lock-silence`) : une équipe
  forcée « toutes séances à GYMA » (`add_forced_venue_constraints`) qu'un verrou HARD pose à GYMB
  atterrissait à GYMB, `completed`, **zéro diagnostic** — le miroir exact du gymnase interdit, oublié.
  `venue_minimums` (`minAtVenueId`), lui, est **délibérément EXCLU** du diagnostic de verrou : il est
  appliqué en dur avec trois seules issues (honoré · INFEASIBLE→`failed` · inatteignable→ERROR
  `venue_minimum_unreachable`), donc il ne peut jamais dériver en silence — prétendre le surveiller
  serait précisément le mensonge que le docstring interdit.
- **Périmètre volontaire** : uniquement le SAISI. Les règles **structurelles** qu'un verrou contourne
  aussi (un coach dans deux gymnases à la même heure) décrivent une impossibilité physique, pas une
  préférence : elles bloqueront la génération au lieu d'avertir, dans un lot dédié.
- **Second effet ALIGN-07** : un verrou HARD prend le **créneau entier**, divisible ou non
  (`blocked_venue_slots`, `model.py`) — partager un créneau `capacity>1` se déclare en **co-épinglant**
  les N équipes. Détail : `backend/docs/constraint-coverage.md`.

Verrous de non-régression : `engine/tests/semantic/test_hard_lock_announces_violations.py` (avec un
TÉMOIN explicite — sans lui, constater que SM1 joue le samedi n'accuserait pas le verrou ; couvre
désormais le gymnase imposé) et `engine/tests/semantic/test_hard_lock_divisible_slot.py`.

**La matrice machine porte une dimension `lock_silence`** (`constraint_matrix.py`, **obligatoire, sans
défaut** : une cellule qui l'oublie échoue à la construction, donc la suite entière rougit) qui classe
chaque cellule face à un verrou : **DIAGNOSED** (le contournement DOIT produire un `constraint_not_honored`
qui nomme la règle — coach, fenêtres, jours, gymnase interdit, gymnase imposé), **UNBYPASSABLE** (ne peut
pas dériver en silence, `venue_minimums` en tête, avec la RAISON portée par la cellule), **SOFT** (famille
soft, ne promet rien). Le test généré ne vérifie pas l'étiquette mais le **comportement** : pour chaque
cellule DIAGNOSED il rejoue un scénario verrou-contre-règle et exige un diagnostic qui nomme la règle —
marquer une famille non-diagnostiquée (ex. `venue_minimums`) DIAGNOSED fait rougir la CI.

⚠ **La souveraineté du verrou vaut aussi contre les INVARIANTS, et il a fallu le leur apprendre**
(P4-81, 2026-08-11). `test_no_venue_double_booking` affirmait `len(team_ids) <= 1` en dur : il
ignorait la capacité du créneau **et** le fait qu'un co-épinglage HARD au-delà de la capacité est
honoré. Il rougissait donc au hasard des tirages hypothesis, sur un required check, bloquant des PR
étrangères au moteur. L'invariant porte désormais sur **ce que le SOLVEUR décide** — même doctrine
que le jumeau coach — et gagne au passage la garantie inverse : sur un créneau verrouillé, tous les
occupants doivent être des épingles, faute de quoi `blocked_venue_slots` (`model.py:67`) aurait été
contourné. Deux cas **déterministes** gardent l'ensemble (`tests/invariants/test_invariants.py`) :
le contre-exemple réel de la CI, et un montage qui force la main du solveur — ce dernier parce que
**aucune fixture aléatoire n'atteignait ce filet**, mesuré en désarmant `blocked_venue_slots`.

## Règles structurelles JAMAIS saisies — et ce que l'écran en montre (P4-55, 2026-08-11)

`add_level_1_hard_constraints` (`engine/app/solver/`constraints/common.py` (`_record_closure`)`) pose une douzaine de
règles que **personne n'entre nulle part**. Elles ne sont ni dans le wizard, ni dans le
`config` d'une contrainte, ni dans le payload : elles sont le modèle lui-même. Le gestionnaire
ne savait donc pas ce qu'il obtient gratuitement, ni pourquoi un placement « qui aurait dû
passer » est refusé.

**Six sont montrées** dans un encart replié, **lecture seule**, en tête de l'étape Contraintes
(`frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx`) :

| Affiché | Fonction moteur | Nuance qui compte |
|---|---|---|
| Un gymnase ne dépasse jamais sa capacité | `add_room_at_most_one:284` | « au plus la CAPACITÉ », pas « une seule équipe » — la capacité se règle par créneau |
| Un coach n'est jamais dans deux gymnases à la fois | `add_coach_at_most_one:311` | **venue-aware** : le MÊME gymnase est AUTORISÉ (D-14, arbitrage fondateur 2026-08-09) |
| Une personne ne peut pas encadrer et jouer en même temps | `add_coach_player_non_overlap:374` | coach-joueur, les deux rôles |
| Une équipe n'a jamais deux séances en même temps | `add_team_no_overlap:745` | — |
| Au plus une séance par jour et par équipe | `add_one_session_per_day_constraints` | **sans exception** depuis le retrait du levier mort (P4-79, voir ci-dessous) |
| Chaque coach garde un jour de repos | `add_coach_rest_day_constraints:452` | lundi→vendredi ; le week-end ne compte pas |

⚑ **Un créneau VERROUILLÉ est un FAIT du planning, pas une exception aux règles** (spécification fondateur
2026-08-15, P4-97 + P4-97 bis) : il est **imposé** (le solveur ne le déplace ni ne le supprime) **et il
compte dans TOUTES les règles** — il occupe la personne, l'équipe, le gymnase, le jour, la chaîne. Tout
placement LIBRE doit être compatible avec lui. Deux verrous qui se contredisent ENTRE EUX (choix du
gestionnaire) ne rendent jamais la génération infaisable : le planning sort, la violation est
**diagnostiquée**. Historique du défaut : `_extract_hard_locks` retire les créneaux verrouillés des
variables du modèle, donc toute règle qui n'itère que sur les variables était aveugle — corrigé en deux
passes (repos coach, distribution salariés, enchaînements, plancher « au moins N à V » ; puis capacité,
coach mono-gymnase, coach-joueur, une séance/jour). Trouvé sur données réelles : une coach jouait dans un
gymnase pendant qu'elle en coachait un autre à la même heure, et une équipe avait deux séances le même
jour — planning COMPLETED, aucun diagnostic.

Nuance bloc (2026-09-02) : pour la **capacité gymnase**, un verrou d'un membre de bloc partagé compte
comme l'occupation UNIQUE du bloc — ses partenaires libres peuvent rejoindre la case (eux seuls),
sinon la transcription du socle rendait tout comblement infaisable
(`engine/tests/semantic/test_fill_pinned_block_partner.py`).

**Depuis P2-28 (2026-08-14), les règles se rangent en DEUX FAMILLES** — né de la reproduction du
planning réel BCCL (P5-13) : le planning du club, 100 % verrouillé, était INFEASIBLE parce que deux
règles « de bon sens » sont plus strictes que la réalité (un coach-joueur enchaîne 3 créneaux dont
une séance JOUÉE ; deux coachs-joueurs sont présents les 5 soirs).

- **Règles du produit — immuables** (rien à régler) : capacité, coach mono-gymnase (D-14),
  coach-joueur non simultané, équipe non dédoublée, une séance/jour. Le modèle lui-même.
- **Règles de bien-être — RÉGLABLES PAR PORTÉE** (bloc **introduit au contrat 2.7** — la
  version COURANTE se lit dans `engine/CONTRACT_VERSION`, jamais ici ; bloc optionnel
  `implicitRules` ; entité `ImplicitRuleSetting`, absence de ligne = défaut) : **jour de repos
  coach** (`coachRestDay`, seuil `minRestDays` 1-4, défaut 1), **distribution des salariés**
  (`salarieDistribution`), **jamais N créneaux dos-à-dos** (`maxConsecutiveSessions`, seuil
  `maxConsecutive` 2-6, défaut 3 — coaché ET joué confondus), **âge croissant** (`ageAscending`).
  Intensité **HARD** (bloque, comportement historique = défaut) ou **PREFERRED** (le solveur vise
  la règle via un littéral de violation AGRÉGÉ par entité, poids −6, preuve d'empilement dans
  `objective.py` — jamais un terme par occurrence, qui pouvait supprimer des séances). Un cran
  DÉSACTIVÉE est une **extension future**, coupée du lot sur contrarian-review.
  ⚠ **Corrigé le 2026-08-18 (bien-être PAR PÉRIODE, PR1 backend)** : ce bloc était décrit
  « réglable par club+saison » sans nuance — depuis `schedule_plan_id` sur `ImplicitRuleSetting`
  (ADR-0002 inv. 5), la portée réelle est **le plan** : NULL = la saison (base + repli des plans
  nés avant la fonctionnalité), un plan de période reçoit à sa NAISSANCE une **copie
  matérialisée** de ses 4 lignes — patron de la copie de grille (#8) — et une modification de la
  saison POSTÉRIEURE à la naissance ne redescend plus dans sa copie. Détail : ADR-0002.
- **Violation TOUJOURS diagnostiquée**, quel que soit le cran (exigence fondateur) : type
  `implicit_rule_not_honored` + `ruleKey`, détection post-solve inconditionnelle au **même grain
  que la pose** (coach MAIN — les ASSISTANT ne comptent pas — + séances jouées), textes
  différenciés : « règle assouplie par vous » (PREFERRED, informatif) vs « le solveur n'a pas pu
  honorer » (HARD contourné par un verrou, alerte). Dédoublonné avec `coach_overload`.
  La parité génération ⇄ verdict tient : `/validate-assignments` reçoit le même bloc.

✦ **Le levier `allowMultipleSessionsPerDay` a été RETIRÉ de bout en bout le 2026-08-12 (P4-79)** :
il valait `false` partout (aucune route, aucun écran ne l'écrivait), la branche d'exemption du
moteur était morte. Le schéma REFUSE désormais le champ (`extra_forbidden`) — la porte est fermée,
pas seulement inutilisée. Si le terrain demande un jour le double-entraînement le même jour, il se
reconstruira proprement (champ d'API, case sur la fiche équipe, encart des règles implicites).

⚠ **Le docstring d'`add_level_1_hard_constraints` a menti longtemps** : il décrivait un
« two-pass fallback » abandonnant repos-coach et distribution-salariés sur INFEASIBLE. Ce chemin
n'existe pas — ADR-0001 pose un solve **single-pass sans relaxation**. Corrigé au même lot.

**Le garde anti-mensonge, dans les deux zones** : `ConstraintsStep.test.tsx` gèle le texte des
règles côté écran, et `engine/tests/semantic/test_implicit_rules_are_still_applied.py` (muté à
P2-28 PR 1) vérifie que les fonctions sont **appelées selon le réglage — défaut = toutes en
HARD** et qu'une règle en PREFERRED reste **diagnostiquée**. L'inventaire cross-stack
(`ImplicitConstraintConfig` ⇄ `engine/implicit_rules.json`, RULESET 2.4, 12 règles avec leur
famille) est comparé par `ImplicitRulesMatchEngineTest` ; le réglage stocké ⇄ le bloc payload par
`ImplicitRulePayloadParityTest` (step bloquant). L'onglet UI de réglage arrive en P2-28 PR 3 —
d'ici là, le réglage se fait par l'API.

## Verrous

| Verrou | Fichier |
|---|---|
| Matrice machine (source du test) | `engine/tests/semantic/constraint_matrix.py` |
| Test sémantique paramétré (NR §7.1) | `engine/tests/semantic/test_constraint_matrix.py` |
| Gel de l'offre UI | `frontend/src/features/wizard/steps/ConstraintsStep.test.tsx` |
| Expansion CLUB→équipes | `backend/tests/Unit/Service/ScheduleConstraintBuilderTest.php` |

Contrat backend↔engine **inchangé** (config = dict opaque, warnings via `diagnostics` existants) —
pas de bump `CONTRACT_VERSION`. **`SCORE_FORMULA_VERSION` actuel : V7** (`engine/app/solver/objective.py`)
— V5→V6 : nouveau poids `avoided_venue = −60` (vrai malus sur le créneau du gymnase évité — un
bonus-complément sur les autres gymnases biaisait l'arbitrage inter-équipes) ; V6→V7 : poids
`spacing` (ALIGN-06). Sémantiques d'agrégation : indispos coach =
**union des blacklists ∩ des whitelists** ; plusieurs « éviter tel jour » soft = **union par équipe**
(deux compléments indépendants s'annulaient) ; double règle de gymnase sur une équipe : les
PREFERRED se **cumulent en ensemble** (bonus si la séance tombe dans l'un d'eux — PR B 2026-08-06),
seules les règles DURES (`forced_venues`) restent last-wins avec diagnostic INFO.
