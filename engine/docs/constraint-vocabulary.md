# Vocabulaire des contraintes — ce que l'engine comprend

Last verified @ 2026-09-04 (rotation fraîcheur, `documentation-update` — zone non touchée par la
PR D4). Re-confronté : `ScheduleConstraintBuilder::withSocleReferenceAssignments`
(`backend/src/Service/ScheduleConstraintBuilder.php:720`), `add_socle_reference_bonus` +
`SOCLE_REFERENCE_TIER_WEIGHTS` (`engine/app/solver/objective/weights.py:24-250`),
`SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V13"` (`weights.py:31`), `engine/CONTRACT_VERSION`
toujours `2.20` ✓. Reste du document non re-vérifié cette passe — historique :
`git log -p --follow engine/docs/constraint-vocabulary.md`.

> **But** : lister **exhaustivement** tout le vocabulaire (familles + clés de `config`) que le
> solveur CP-SAT (`engine/app/solver`) sait **parser et appliquer**. Source de vérité côté engine.
> Chaque entrée donne le **mécanisme** (dur/soft), le **ruleType** qui l'active, et un **exemple BCCL**.
>
> Une contrainte arrive sous la forme `{ scope, scopeTargetId, family, ruleType, config }`.
> Le backend (`ScheduleConstraintBuilder`) sérialise, l'engine (`parse_v2_constraints`,
> `add_time_window_constraints`, `objective.py`) lit. **Toute clé absente de ce document n'est PAS
> comprise** (elle est ignorée sans erreur).

## Portée & type de règle (communs à toutes les familles)

| Champ | Valeurs | Effet |
|---|---|---|
| `scope` | `CLUB` · `TEAM` · `COACH` · `FACILITY` | cible de la règle |
| `scopeTargetId` | uuid | l'équipe / coach / gymnase visé (null si CLUB) |
| `config.targetTag` | tag système (`JEUNE`, `SENIOR`, `EMB`, `U9`…`U21`, `FEMININE`, `MASCULINE`, `REGIONAL`, `DEPARTEMENTAL`, `LOISIR_ADULTE`…) | **CLUB + targetTag** → le backend **éclate** en N contraintes `TEAM` (une par équipe du tag). Une règle sans cible qui atteindrait l'engine → **warning** (`constraint_not_honored`) |
| `ruleType` | `HARD` · `LOCK` · `PREFERRED` · `BONUS` | `HARD`/`LOCK` = **dur** (jamais violé ; sur-contraint → équipe non placée + diagnostic). `PREFERRED` = **soft** (oriente l'objectif, ne bloque jamais). `BONUS` = normalisé en `PREFERRED`. |

---

## Famille TIME — heures de début

| Clé | Sens | Dur (HARD/LOCK) | Soft (PREFERRED) |
|---|---|---|---|
| `minStartTime` (`"HH:MM"`) | ne pas **commencer avant** | fenêtre dure (créneaux plus tôt interdits) | bonus objectif (préfère plus tard) |
| `maxStartTime` (`"HH:MM"`) | ne pas **commencer après** | fenêtre dure | bonus objectif (préfère plus tôt) |
| `maxEndTime` (`"HH:MM"`) | la séance doit **finir avant** (fin = début + durée du créneau) | fenêtre dure (créneaux dont la fin dépasse interdits) | — **HARD-only** : le chemin soft `add_preferred_time_bonus` ne lit que min/maxStartTime |

**`maxEndTime`** (ALIGN-04) est calculé par créneau : `slot_start + slot_duration > maxEnd → var = 0`. Le wizard l'émet en mode « Fini avant » (toujours HARD).

**Exemples BCCL**
- `EMB (U9/U11) - Début au premier créneau (max 17h30)` → `{ family:"TIME", ruleType:"HARD", config:{ maxStartTime:"17:30", targetTag:"EMB" } }`
- `Adultes - Début minimum 18h50` → `{ TIME, HARD, { minStartTime:"18:50", targetTag:"SENIOR" } }`
- `U13 - Début préféré avant 19h00` → `{ TIME, PREFERRED, { maxStartTime:"19:00", targetTag:"U13" } }` (soft)

---

## Famille DAY — jours de la semaine (1 = lundi … 7 = dimanche)

| Clé | Sens | Mécanisme |
|---|---|---|
| `forbiddenDays` (`[int]`) | **éviter** ces jours | `HARD` → jours interdits (dur) · `PREFERRED` → malus soft « éviter ces jours » |
| `allowedDays` (`[int]`) | **uniquement** ces jours (whitelist) | l'engine **interdit tout jour hors liste**. Toujours dur. (liste vide = « non configuré », aucune restriction) |
| `forcedDays` (`[int]`) | **au moins une** séance ces jours-là | pose `somme(vars de ces jours) ≥ 1`. **N'interdit PAS** les autres jours. **exposé au wizard depuis 2026-08-23 (ALIGN-09)** (le wizard émet `allowedDays` pour « uniquement », cf. audit ENG-16) |
| `preferredDays` (`[int]`) | préférer ces jours | bonus objectif. **Engine-only** (jamais émis par le wizard) |

> **Piège** : `allowedDays` (« uniquement ») ≠ `forcedDays` (« au moins un »). « Vétérans le vendredi
> **uniquement** » = `allowedDays:[5]` (sinon la 2ᵉ séance d'une équipe multi-séances pourrait tomber
> un autre jour). Contradiction `allowedDays ∩ forbiddenDays` couvrant tout → équipe à 0 séance +
> diagnostic `day_constraint_conflict` explicite.

**Exemples BCCL**
- `Veterans - Vendredi uniquement` → `{ DAY, HARD, { allowedDays:[5] } }`
- `U9M1 - Pas d'entraînement le mercredi` → `{ DAY, HARD, { forbiddenDays:[3] } }`
- `SM2 - Évite le vendredi` → `{ DAY, PREFERRED, { forbiddenDays:[5] } }` (soft)

---

## Famille FACILITY — gymnases

| Clé | Sens | Dur (HARD/LOCK) | Soft (PREFERRED) |
|---|---|---|---|
| `forcedVenueId` (uuid) | **imposer** ce gymnase | l'équipe ne joue QUE là (tous les autres interdits) | — |
| `preferredVenueId` (uuid) | ce gymnase | **HARD/LOCK = forcé** (comme `forcedVenueId`) | bonus objectif **+60** par séance dans ce gymnase |
| `forbiddenVenueId` (uuid) | **éviter** ce gymnase | assignation interdite (dur) | malus objectif **−60** (soft « évite ») |
| `minAtVenueId` (uuid) + `minAtVenueCount` (int, défaut 1) | **au moins N** séances dans ce gymnase (plancher, ≠ forçage) | pose `somme(vars de l'équipe dans ce gymnase) ≥ N` ; les autres séances restent libres | — **HARD-only** |

- **`minAtVenueId`** (ALIGN-05) est un **plancher**, pas un forçage : contrairement à `forcedVenueId` (TOUTES les séances), il garantit `≥ N` séances ici et laisse le reste libre. **Fail-soft** : si l'équipe a moins de **jours distincts** disponibles dans ce gymnase que `N` (elle joue ≤ 1 séance/jour, donc deux créneaux le même jour ne comptent que pour une séance), l'engine **n'ajoute pas** la contrainte et émet un diagnostic `venue_minimum_unreachable` (sévérité ERROR) au lieu d'un INFEASIBLE. Le backend refuse en amont `N > séances/semaine de l'équipe` (fail-fast avant génération).
- **Parité génération⇄verdict (P4-152)** : `/validate-assignments` pose la même contrainte HARD
  dans `_apply_hard` (`add_venue_minimum_constraints`) — mais poser le HARD seul ne suffit **pas**
  pour NOMMER un refus : les créneaux non-baseline du verdict restent libres, donc le solveur
  placerait une séance fantôme ailleurs dans le gymnase pour tenir `somme ≥ N` et répondrait
  « valide » à tort (même faille que le trajet, ENG-36). Le miroir déterministe
  `_venue_minimum_move_violation` juge donc l'état concret AVANT le solve et refuse, motif NOMMÉ
  `venue_minimum_infeasible` (gymnase, équipe, plancher exigé, état résultant). **Garde
  anti-enfermement** : il ne refuse QUE si le plancher était **satisfait avant** le déplacement
  (`current_at_venue >= minimum and final_at_venue < minimum`) — un plancher **déjà cassé** (planning
  généré avant la pose de la contrainte, ou créneau supprimé depuis) laisse passer le déplacement,
  sinon le gestionnaire serait enfermé sans pouvoir rien bouger. Deux gardes distincts, aucun
  redondant : retirer la pose HARD fait rougir `test_hard_layer_parity_registry.py` (registre de
  parité) sans faire rougir le NR (le miroir refuse encore) ; désactiver le miroir fait rougir
  `test_validate_venue_minimum.py` sans faire rougir le registre (la pose HARD reste là).
- **Exclusivité groupe** : `CLUB + targetTag + (forcedVenueId ou preferredVenueId HARD)` → le backend force le tag ET **interdit le gymnase hors tag** → gymnase **réservé** au groupe.
- **Fermeture datée** (`config.type = "venue_closed"`, période cockpit) → le backend l'**étend** en `forbiddenVenueId` HARD par équipe sur la fenêtre.

**Exemples BCCL**
- `SM4 - Jean Vilar obligatoire` → `{ FACILITY, HARD, scope:"TEAM", scopeTargetId:<SM4>, config:{ forcedVenueId:<Jean Vilar> } }`
- `Camus - Réservé Loisir 1 exclusivement` → `{ FACILITY, HARD, TEAM:<Loisir 1>, { forcedVenueId:<Camus> } }`
- `Jean Vilar - Pas équipes féminines` → `{ FACILITY, HARD, CLUB, { forbiddenVenueId:<Jean Vilar>, targetTag:"FEMININE" } }`
- `Matéo - Préféré équipes régionales` → `{ FACILITY, PREFERRED, CLUB, { preferredVenueId:<Matéo>, targetTag:"REGIONAL" } }` (soft, +60)

---

## Famille COACH_AVAILABILITY — disponibilité coach (toujours dure)

| Clé | Sens | Mécanisme |
|---|---|---|
| `unavailableDays` (`[int]`) | **indisponible** ces jours | jours interdits pour toute équipe du coach. **UNION** si plusieurs contraintes sur le même coach |
| `availableDays` (`[int]`) | **disponible uniquement** ces jours | whitelist. **INTERSECTION** si plusieurs contraintes |
| `fromTime` / `untilTime` (`"HH:MM"`, optionnels) | **fenêtre horaire** sur ces jours (Lot C) | absent = journée entière (comportement legacy). Bloque un créneau dont le **début** ∈ `[from, until)` sur le jour visé. En interne : la disponibilité est un ensemble d'**intervalles bloqués `(jour, from, to)`** avec sémantique UNION (par De Morgan, couvre à la fois l'UNION des indispos et l'INTERSECTION des whitelists) |

> Une dispo coach reçue en non-HARD est **appliquée dur quand même** + diagnostic INFO (une personne
> ne peut pas être à deux endroits).
>
> **Piège (whitelist INTERSECT, ENG-13)** : deux règles **« disponible uniquement »** sur le **même
> coach le même jour** s'**intersectent** (les compléments s'unissent) → ex. `dispo lundi 17:00-19:00`
> **+** `dispo lundi 19:00-21:00` ne donne **pas** « dispo 17-21 » mais **lundi entièrement bloqué**
> (aucune heure n'est dans les deux fenêtres). Cohérent avec le jour-seul (`dispo lundi` + `dispo mardi`
> = ∅). Pour un même jour, **une seule** fenêtre « disponible uniquement » ; utiliser `unavailableDays`
> (UNION) pour cumuler des indisponibilités. **Défensif** : une fenêtre inversée (`from ≥ to`, ex. un
> overnight `20:00-08:00` que le modèle plat ne wrappe pas) ou une heure malformée retombe sur **journée
> entière bloquée** (l'indispo est honorée, jamais silencieusement perdue ni crash du solve).

> **La cible est le `scope`, jamais le `config` (SEC-13 PR B, 2026-08-08).** La clé `coachId` a été
> **supprimée** : elle valait exactement `scopeTargetId`, et un doublon de cible est une occasion de
> divergence (deux sources pour la même vérité). Elle est absente de la liste blanche — un `config`
> qui la porte est refusé en **422** à l'écriture.

**Exemple BCCL**
- `Lionel - Indisponible le vendredi` → `{ COACH_AVAILABILITY, HARD, scope:"COACH", scopeTargetId:<Lionel>, config:{ unavailableDays:[5] } }`

---

## ~~Famille FACILITY_CAPACITY~~ — RETIRÉE le 2026-08-08 (SEC-13 PR C)

La famille est **supprimée des trois couches**. Le moteur rabotait la capacité d'un gymnase à `maxTeams`
(`min(capacité du créneau, maxTeams)`) — un mécanisme réel, mais **aucun chemin UI ne pouvait créer la
contrainte** et **zéro ligne n'existait en base** : du code honoré que personne ne pouvait atteindre.
Elle est absente de la liste blanche `config` (une écriture est refusée en 422) et il ne reste dans le
moteur qu'un commentaire au passé (`app/main.py:291-294`).

**La divisibilité d'un gymnase n'a jamais transité par cette famille** : elle est saisie à l'**écran
Gymnases** (`canSplit`) et voyage dans `trainingSlots[].capacity` (`canSplit ? capacity : 1`) — c'est
toujours le cas, et c'est le seul chemin.

---

## `type: "PRIORITY_TIER"` — poids de priorité (rang S/A/B/C/D)

Envoyé par le backend depuis les `PriorityTier` : seuls `metadata.id`, `label` et
`defaultMinSessions` partent — le backend n'envoie **pas** `orToolsWeight` (retiré volontairement :
les poids S=10000 · A=1000 · B=100 · C=10 · D=1 sont **codés en dur** côté engine dans
`LEVEL_2_OBJECTIVE_WEIGHTS`). Le poids exponentiel garantit qu'un rang
supérieur l'emporte dans l'objectif. Le **minimum de séances** du rang est une **cible soft**
(bonus objectif), pas un plancher dur (audit ENG-18).

---

## Règles implicites (toujours appliquées, sans config)

| Règle | Effet |
|---|---|
| `VENUE_AT_MOST_ONE` / capacité | jamais 2 équipes sur le même créneau d'un gymnase non divisible |
| `TEAM_NO_OVERLAP` | une équipe jamais 2 séances en même temps |
| `COACH_NO_OVERLAP` | un coach jamais sur 2 séances simultanées |
| `COACH_PLAYER_NO_OVERLAP` | un coach qui **joue** aussi n'est jamais convoqué à 2 séances simultanées (ex. Mathis coach U13M2 + joueur U21M1). **Exemption séance de bloc** : sur une case où une séance de bloc est ACTIVE, les deux équipes s'entraînent physiquement ENSEMBLE — la personne double-rôle n'y tient qu'un rôle à la fois, l'anti-chevauchement s'y efface (borne `≤ 1 + Σb`). L'exemption exige la MÊME case (même gymnase + même heure de début) ET une séance de bloc active : une coïncidence solo (case sans séance de bloc), un chevauchement à débuts différents ou un autre gymnase restent des conflits |
| `MIN_SESSIONS` | chaque équipe vise son nombre de séances/semaine (**cible soft**, cf. ENG-18) |
| `COACH_REST_DAY` | **dur** : chaque coach a ≥ 1 jour de repos du lundi au vendredi (≤ 4 jours travaillés). Ignoré pour un coach dont le `maxDaysOverride` est déjà ≤ 4 |
| `SALARIE_DISTRIBUTION` | **dur** : au moins un coach salarié (`isEmployee`) présent chaque jour lun-ven. Inactif si le club compte moins de 2 salariés |
| `MAX_CONSECUTIVE_SESSIONS` | **dur** : une même personne n'est jamais sur les 3 créneaux d'un enchaînement A→B→C le même jour, **tous gymnases confondus** |
| `ONE_SESSION_PER_DAY` | **dur** : ≤ 1 séance par jour et par équipe, sauf `allowMultipleSessionsPerDay` |
| `AGE_ASCENDING` | **dur** : à gymnase et jour égaux, une équipe plus jeune ne passe pas après une plus âgée. Exempt si `ageMin` est absent (Loisir, Baby) ou si l'équipe est verrouillée en HARD |
| `MAX_CONSECUTIVE_DAYS` | **dur ou soft, au choix du club** : une ÉQUIPE ne s'entraîne pas `maxConsecutiveDays` jours de suite (défaut 3, bornes 2-5). ⚠ À ne pas confondre avec `MAX_CONSECUTIVE_SESSIONS`, presque homonyme : celle-là vise une PERSONNE sur des créneaux dos-à-dos DANS UNE JOURNÉE. **Seule règle dont l'absence du payload signifie NON APPLIQUÉE** — les autres retombent sur HARD (P2-42, contrat 2.13) |
| jour de repos après match | bonus soft (`add_match_day_rest_bonus`) : préfère laisser le lendemain d'un match libre |
| espacement des jours (`spacing`) | **bonus soft** (`add_spacing_penalty`, poids `−2`) : malus sur deux séances d'une même équipe sur des jours consécutifs (jour, jour+1) — préfère espacer, ne bloque jamais (ALIGN-06) |

## Passerelles (`teamLinks`) — anti-chevauchement d'ENTRAÎNEMENT entre deux équipes

Bloc d'entrée `teamLinks[]` (couple `{teamAId, teamBId, intensity}`, lot PASSERELLES). L'`intensity`
gouverne **UNIQUEMENT** le solveur d'entraînement — le rail matchs garde sa pénalité SOFT propre,
insensible à ce réglage (arbitrage fondateur n°1). Deux régimes :

| `intensity` | Effet moteur | Où |
|---|---|---|
| `MANDATORY` | **dur** : les séances des deux équipes ne se chevauchent JAMAIS dans le temps (`var_a + var_b ≤ 1`) — plus strict que la tolérance coach D-14 (`same_venue_allowed=False`) | `add_team_link_constraints` |
| `PREFERRED` (défaut) | **soft** : malus `−TEAM_LINK_TIER_WEIGHTS[tier]` par chevauchement, `tier` = la PLUS HAUTE des deux équipes (S 8 · A 6 · B 4 · C 2 · D 1) — deux fanions qui coïncident coûtent plus que deux réserves ; oriente sans jamais SUPPRIMER une séance | `objective.add_team_link_penalty` |

- **Exemption unique** : une séance **mutualisée DÉCLARÉE** (même case, bloc `sharedBlocks`
  partagé — `team_share_declared_pairs`, P2-51 arbitrage n°6) n'est jamais comptée comme
  chevauchement — dans les deux régimes.
- **La simultanéité n'est jamais une décision du solveur** : sur `MANDATORY`, le seul chevauchement
  résiduel possible est **deux verrous HARD** que le gestionnaire a posés lui-même (aucune contrainte
  entre deux constantes — poser `1+1 ≤ 1` rendrait INFEASIBLE muet) ; sur `PREFERRED`, c'est un malus
  que le maximiseur a **payé** faute de mieux. Dans les deux cas le résidu est **diagnostiqué**
  (`result_builder._diagnose_team_links` → `team_link_not_honored`, ERROR, nommant les deux équipes),
  jamais avalé.
- `teamLinks` vide (ou aucune passerelle du régime visé) ⇒ chemin byte-identique, goldens inchangés.

## Bloc de mutualisation (`sharedBlocks`) — un ensemble d'équipes qui se comporte comme UNE équipe (P2-51)

Bloc d'entrée `sharedBlocks[]` (`{id, teamIds 2..10, commonSessions≥1}`, cap 50 blocs) — **SEULE
notion de mutualisation depuis le retrait du modèle groupe {équipes, K} (`sharedTrainings`, P2-27)
par PR-7 (2026-08-31)** : l'ancien exact-K par co-présence (`add_shared_training_constraints`) est
supprimé du solveur, du contrat et de l'écran. Un bloc arbitre le besoin terrain (mutualisations
imbriquées) sans le double-comptage que le groupe K portait sur les groupes recouvrants : ses
séances **lui appartiennent**, exactement comme celles d'une équipe — le solveur les PLACE, il ne
les DÉDUIT pas d'une co-présence.

**Modélisation retenue — le LIAGE, et pourquoi.** Pour chaque case candidate `(gymnase, jour,
heure)` où tous les membres ont une variable ou un verrou, une variable de DÉCISION propre au bloc
`b[case]` est créée, reliée à chaque membre par l'implication **UNIDIRECTIONNELLE**
`x[membre, case] ≥ b[case]` (« si le bloc tient sa séance ici, tous les membres y sont »), puis
`Σ b == commonSessions`. **`b` n'est PAS réifié depuis la co-présence** (pas de `b ⇔ tous
présents`, contrairement à `y_s` de l'ancien modèle groupe `sharedTrainings`, retiré par PR-7) —
c'est ce refus, précisément, qui dissolvait le mur du double-comptage de ce modèle (il comptait
`y_s` pour CHAQUE groupe imbriqué candidat sur la même case, faisant compter une case deux fois
pour deux groupes qui se recouvrent) : deux blocs qui partagent une équipe ont des `b`
INDÉPENDANTS, leurs séances sont distinctes par construction. Et comme `b ⟹ x=1`, une séance de
bloc EST une séance `x` normale du membre : elle **consomme gratuitement** une de ses
séances/semaine, compte pour `one_session_per_day`, le repos coach, les enchaînements et
l'objectif de placement — tous déjà exprimés sur `x`, aucun crédit à câbler à la main (contrairement
à une pseudo-équipe découplée qui devrait faire créditer chacun de ces postes séparément). La seule
chirurgie requise est la **capacité de gymnase** : une séance de bloc réunissant `n` membres libres
sur une case n'y occupe qu'**UNE** place, pas `n` — `add_shared_block_constraints` enregistre le
dé-comptage `(n_libres−1)·b`, que `add_room_at_most_one` soustrait (`shared_block_room_relief`,
patron du crédit des verrouillés P4-97). Depuis le 2026-09-02, ce dé-comptage couvre aussi le
**partenaire VERROUILLÉ** : un membre du bloc épinglé en HARD sur une case (transcription du
socle) laisse la place aux membres libres du même bloc — et à eux seuls — aux deux étages
(candidats de `model.py`, balayage par sous-départs de `structural.py`) ; gardé par
`tests/semantic/test_fill_pinned_block_partner.py`. Une garde de distinctness inter-blocs
(`Σ_{blocs ∋ membre} b[membre, case] ≤ 1`) empêche deux blocs partageant un membre de s'effondrer
sur la MÊME case (sinon une séance physique compterait pour deux blocs).

| Où | Effet |
|---|---|
| `add_shared_block_constraints` (`targeting.py`, posé en tête d'`add_level_1_hard_constraints`, AVANT capacité) | liage `x ≥ b` par membre, `Σ b == commonSessions` par bloc, distinctness inter-blocs |
| `add_room_at_most_one` | dé-compte `(n_libres−1)·b` — une séance de bloc = une occupation |
| `team_share_declared_pairs` | co-présence des membres exemptée de l'anti-chevauchement passerelle (§ci-dessus) |
| `shared_block_case_bvars` → `add_coach_player_non_overlap` | co-présence des membres exemptée de l'anti-chevauchement coach-joueur/joueur-joueur QUAND la séance de bloc de la case est active (borne `≤ 1 + Σb`) — voir `COACH_PLAYER_NO_OVERLAP` ci-dessus |
| Diagnostic post-solve (`_diagnose_shared_blocks`) | `shared_block_not_honored` — INFEASIBLE : moins de cases communes candidates que de séances demandées (cause certaine) ; solve abouti : défense en profondeur si le compte réel diverge |
| Sur-capacité gymnase (post-solve) | attribuée **PAR CASE** (multi-appartenance permise, `_fold_case_occupant_identity`) — jamais « premier bloc gagne » via une carte globale, contrairement au groupe historique (unicité un-groupe-par-équipe) |
| `/validate-assignments` | miroir déterministe `_shared_block_move_violation` (D11) — refuse NOMMÉ `shared_block_broken` un déplacement qui RETIRE un membre d'une séance de bloc jusque-là honorée ; **garde anti-enfermement** (patron `_venue_minimum_move_violation`/P4-152) : un bloc DÉJÀ cassé dans la baseline ne bloque pas les déplacements |

`sharedBlocks` vide/absent ⇒ `add_shared_block_constraints` retourne 0 sans poser de variable,
chemin byte-identique, goldens inchangés (aucun golden avec bloc). Les 3 gestes (déclarer, poser,
déplacer le bloc entier) sont livrés — `POST /api/schedule-slots/move-group` (D11, contrat 2.18)
consomme le miroir `_shared_block_move_violation` ci-dessus.

## Trajet entre gymnases (`travelTime`) — départage + battement (P2-53 RMM-8)

Bloc d'entrée `venueTravelTimes[]` (matrice `{venueAId, venueBId, drivingMinutes?, walkingMinutes?}`,
club+saison, symétrique) + règle implicite `implicitRules.travelTime`. **OPT-IN à la PRÉSENCE de
matrice** : le backend n'émet la règle active que si le club a saisi au moins une ligne (précédent
`maxConsecutiveDays`) — absent ⇒ payload byte-identique, ni départage ni battement. **Consommé par
`/generate` (solveur d'entraînement) ET `/validate-assignments` (le VERDICT d'un déplacement manuel,
P2-55/ENG-36)** — `/place-matches` ne reçoit pas ce bloc. **Parité génération⇄verdict** : le verdict
applique le même `_apply_hard` (matrice passée à `add_level_1_hard_constraints`) — sous `MANDATORY` un
déplacement créant un battement trop court est **refusé et NOMMÉ** par le miroir déterministe
`_travel_time_move_violation` (`rule: travel_time_infeasible`, réutilise le prédicat unique
`iter_travel_pairs_from_placements` de `travel.py` — ENG-37 résorbé côté verdict) ; sous `PREFERRED`
il est accepté mais le **compromis famille `travel_time` est nommé** dans la sortie.

Deux « voyageurs » relient deux séances enchaînées le même jour à des gymnases différents et non
chevauchantes :
- un **coach commun** aux deux séances — barème **voiture** s'il est `isVehicled`, **à pied** sinon ;
- une **passerelle** (`teamLinks`) — barème **à pied d'office** (joueurs partagés jamais modélisés
  individuellement).

Le barème appliqué est la colonne (voiture/à pied) du couple de gymnases dans `venueTravelTimes` ;
un couple/mode jamais arbitré (colonne `null` ou couple absent de la matrice) retombe sur
`travelTime.defaultMinutes` (défaut **20**, réglable 0-600).

Deux termes, jamais le même rôle :

| Terme | Mécanisme | Poids/portée |
|---|---|---|
| **Départage « moindre trajet »** | s'applique dès que la règle est active, quel que soit le cran ; préfère l'enchaînement au barème le plus court | malus faible, `1 × palier(barème)` (palier 1 ≤15 min, 2 ≤40 min, 3 au-delà) — vit dans la SOUS-BANDE de phase 2, SOUS le placement (verrouillé à l'optimum de phase 1) ET sous le chaînage (×4096) : ne départage QUE des ex æquo exacts, jamais dominant |
| **Battement insuffisant** (`gap` entre fin de A et début de B < barème) | `PREFERRED` (défaut) → violation SOFT, compromis nommé famille `travel_time` ; `MANDATORY` → interdit dur | `PREFERRED` : malus `−6` (même masse que les règles de bien-être PREFERRED) ; `MANDATORY` : `add_travel_time_hard_constraints`, patron passerelle MANDATORY — un résidu ne peut survenir qu'entre deux séances VERROUILLÉES contradictoires, ANNONCÉ par le diagnostic `travel_time_infeasible` (jamais INFEASIBLE muet) |

Le vocabulaire d'intensité est `PREFERRED`/`MANDATORY` (patron passerelle), **pas** `HARD`/`PREFERRED`
comme les 5 règles de bien-être : le trajet suggère ou interdit, il ne « durcit » pas une préférence
de confort. Le MÊME gymnase n'est jamais concerné (l'exemption coach-coach même-gymnase D-14 reste
intacte). `venueTravelTimes` absent/vide OU règle inactive ⇒ aucune variable posée, chemin
byte-identique, goldens inchangés.

## Référence socle du comblement (`socleReferenceAssignments`) — bonus de PLACEMENT par tier (PR-3)

Bloc d'entrée `socleReferenceAssignments[]` (`{teamId, dayOfWeek, startTime}`, **sans** `venueId`,
cap `MAX_SLOT_TEMPLATES`) — émis **uniquement en comblement** (`ScheduleConstraintBuilder::
withSocleReferenceAssignments`, backend) : les placements de la version **pointée** du plan SEASON
(le socle), injectés APRÈS le hash de snapshot comme `previousAssignments` (préférence de
convergence, jamais une donnée de structure).

`add_socle_reference_bonus` (`engine/app/solver/objective/terms.py`) ajoute, dans l'objectif de
**PLACEMENT** (phase 1, patron du malus passerelle `extra_placement_terms` — **pas** le tie-break
de phase 2 comme `build_stability_terms`), un bonus `+SOCLE_REFERENCE_TIER_WEIGHTS[tier]` sur
chaque variable `model.x[(team, venue, day, start)]` dont `(team, day, start)` — **gymnase
ignoré** — matche une entrée du bloc. Poids par tier (`weights.py`) : `S=20 · A=18 · B=16 · C=14 ·
D=12` — le club tient plus fort l'horaire de socle d'une équipe fanion qu'une équipe secondaire.
Un créneau HARD n'a pas de variable dans `model.x` → naturellement ignoré, la référence n'oriente
que les trous du comblement. `socleReferenceAssignments` absent/vide ⇒ `[]`, chemin
byte-identique (le backend ne l'émet qu'en comblement, en génération pleine il est absent).

`SCORE_FORMULA_VERSION` = **`T24_LEVEL_2_FIXED_WEIGHTS_V13`** (V12 sans le terme, INERTE tant
qu'aucune référence n'est émise). `sharedBlocks`/`slotTemplates` — miroir `MAX_SLOT_TEMPLATES` sur
le cap de la liste. Contrat backend⇄engine **2.20**. Gardé côté backend par
`CrossStack/SocleReferencePayloadParityTest`.

## Ce qu'un verrou HARD écrase (P2-9)

Un créneau `slotTemplates[].lockLevel = "HARD"` est pré-placé **hors du solveur** : `model.py` ne crée
jamais la variable `x[équipe, gymnase, jour, début]` correspondante. Or **toute** clé de ce document
s'applique en forçant cette variable à 0 — sans variable, il n'y a rien à forcer. Le verrou ne « gagne »
donc pas contre la contrainte : il la rend **inatteignable**. Le verrou reste **souverain** (ALIGN-07,
décision fondateur non rouverte).

Ce qui a changé, c'est le silence. `diagnose_locked_slot_violations` (`constraints.py`) recroise chaque
verrou avec les contraintes **saisies** et émet un `constraint_not_honored` de sévérité **INFO** par
(contrainte, équipe, verrou), en nommant la règle réellement fautive :

| Vérification | Miroir exact de l'application |
|---|---|
| `COACH_AVAILABILITY` | intervalle bloqué `(jour, from, to)` testé sur l'**heure de début** du verrou, pour **chaque** coach requis de l'équipe |
| `minStartTime` / `maxStartTime` | comparaison sur l'heure de début |
| `maxEndTime` | `début + durée **DU VERROU**` (pas celle du créneau de grille : un verrou de 120 min sur un créneau de 90 déborde réellement) |
| `forbiddenDays` / `allowedDays` | évalués sur l'**UNION par équipe** — la seule sémantique que le solveur applique ; les règles nommées sont celles qui excluent effectivement le jour une fois l'union faite |
| `forcedDays` | un verrou posé un **autre** jour peut consommer le créneau qui aurait satisfait l'exigence → avertissement dédié |
| `forbiddenVenueId` | paire (équipe, gymnase), ce qui couvre aussi les fermetures de gymnase étendues par le backend |

> **Hors périmètre volontaire** : les règles implicites **structurelles** (un coach dans deux gymnases à
> la même heure) ne sont pas couvertes ici. Elles décrivent une impossibilité physique, pas une
> préférence : elles doivent bloquer la génération, pas produire un avertissement.

## Ce que l'engine NE comprend PAS (à ce jour)

*Rien à ce jour.* Le seul manque que cette section listait — « pas N jours d'affilée » — **est
modélisé depuis P2-42** (`add_max_consecutive_days_constraints`,
`engine/app/solver/constraints/wellness.py` (`add_max_consecutive_days_constraints`), réglable HARD/PREFERRED/OFF, défaut 3 jours, bornes 2-5) :
voir la ligne `MAX_CONSECUTIVE_DAYS` du tableau des règles implicites ci-dessus. La section reste
en place — un nouveau manque constaté s'y écrit, il ne se dilue pas dans le reste du document.

**Verrous** : `engine/tests/semantic/constraint_matrix.py` (matrice UI↔engine) + `docs/architecture/constraint-matrix.md` (jumeau humain de l'**offre du wizard**). Ce document-ci couvre le vocabulaire **engine complet**, y compris ce que le wizard n'émet pas encore.
