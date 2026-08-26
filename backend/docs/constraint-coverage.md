# Couverture des contraintes — besoins gestionnaire

Last verified @ 2026-08-26 (rotation `documentation-update`, P2-53 PR-3 — zone non touchée par
cette PR mais nouveau besoin gestionnaire livré : **ligne ajoutée** Axe GYMNASE « Éviter
d'enchaîner deux gymnases trop éloignés » = règle implicite `travelTime`, statut 🟡 partiel — écran
livré, intensité fixée en dur à PREFERRED côté backend (`ScheduleConstraintBuilder.php:602-604`),
aucun rail wizard pour MANDATORY. Vérification précédente toujours valable : la ligne « Jour de
repos après un match » — `matchDay` émis est DÉRIVÉ de l'image A/B côté backend (`max` des jours
ISO des habitudes ∪ rotations, repli champ déclaré converti 0-based→ISO) via
`ScheduleConstraintBuilder::deriveMatchDay`, vérifié au code. Recalé ENG-32 : le monolithe
`constraints.py` est devenu le paquet `constraints/` — les références de ce fichier pointent
désormais fichier+fonction, stables au refactor. Recalé par la livraison ALIGN-09 : « au moins une
séance tel jour » passe 🟡 → ✅ — mode wizard, gate bloquant, sémantique « l'un de ces jours »
vérifiée au code (`constraints/targeting.py`, `add_time_window_constraints` — une somme sur
l'union par équipe). `forcedDays` était déjà prouvé décisif par le test sémantique CI ; la clé
héritée #120 est migrée (contraintes vives ET snapshots))

> **But** : liste **exhaustive** des besoins qu'un gestionnaire de club peut vouloir exprimer, et
> **ce que l'application couvre** aujourd'hui — pour voir clairement les cas couverts (✅), partiels
> (🟡) et **non couverts** (❌). Le vocabulaire moteur correspondant est détaillé dans
> `engine/docs/constraint-vocabulary.md`. Exemples pris sur le club de démo **BCCL**.
>
> Légende : ✅ couvert (dur ou soft explicite) · 🟡 partiel / approximé / non garanti · ❌ non couvert.

## Axe HORAIRE (heure de début)

| Besoin gestionnaire | Contrainte / mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Ne pas commencer avant X h » | TIME `minStartTime` (HARD) | ✅ | Adultes ≥ 18h50 |
| « Ne pas commencer après X h » | TIME `maxStartTime` (HARD) | ✅ | EMB ≤ 17h30 |
| « Préférer plus tôt / plus tard » | TIME `min/maxStartTime` (PREFERRED) | ✅ soft | U13 début préféré < 19h00 |
| **« Finir avant X h »** | TIME `maxEndTime` (HARD, mode « Fini avant ») — fin = début + durée du créneau | ✅ *(ALIGN-04)* | U15 « fini avant 20h30 » |

## Axe JOUR

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Pas d'entraînement tel jour » (dur) | DAY `forbiddenDays` (HARD) | ✅ | U9/U11 pas le mercredi |
| « Éviter tel jour » (préférence) | DAY `forbiddenDays` (PREFERRED) | ✅ soft | SM2 évite le vendredi |
| « Uniquement tel(s) jour(s) » | DAY `allowedDays` (whitelist, HARD) | ✅ | Vétérans le vendredi uniquement |
| **« Au moins une séance tel jour »** | DAY `forcedDays` (HARD — sémantique « l'un de ces jours » : UNE somme sur l'union par équipe) | ✅ *(ALIGN-09, 2026-08-23)* | mode wizard « au moins une » ; le gate pré-solve BLOQUE si aucun des jours imposés n'a de créneau candidat (décision fondateur : certitude arithmétique d'échec) et AVERTIT quand deux règles fusionnent |
| **« Espacer les séances d'un jour »** / « pas 2 jours d'affilée » | règle **implicite soft** `spacing` (poids −2, malus sur jours consécutifs) — activée pour toutes les équipes, ne bloque jamais | ✅ soft *(ALIGN-06)* | besoin BCCL « implicite » — préféré, pas garanti |
| **« Pas 3 entraînements d'affilée »** (dur) | règle implicite `maxConsecutiveDays` (5e règle bien-être, contrat 2.13) | ✅ | **Livrée le 2026-08-19 (P2-42 / ALIGN-08)** — réglable HARD (garantie) ou PREFERRED (objectif), seuil 2-5, **OFF par défaut** : un club l'active, sinon rien ne change. Prouvée par `engine/tests/semantic/test_consecutive_days.py` |

## Axe GYMNASE

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Cette équipe joue dans tel gymnase (obligatoire) » | FACILITY `forcedVenueId` (HARD) | ✅ | SM4 → Jean Vilar |
| « Réserver un gymnase à un groupe (exclusif) » | FACILITY `forcedVenueId`/`preferredVenueId` HARD + `targetTag` → interdit hors tag | ✅ | Camus réservé Loisir 1/2/3 |
| « Éviter tel gymnase » (dur) | FACILITY `forbiddenVenueId` (HARD) | ✅ | Vétérans interdits sur 5 gymnases |
| « Préférer tel gymnase » | FACILITY `preferredVenueId` (PREFERRED, +10 — recalé sous la valeur d'une séance nue depuis V10 « le remplissage prime », `engine/app/solver/objective.py`) | ✅ soft | Matéo préféré aux Régionales |
| « Pas ce type d'équipe dans ce gymnase » | FACILITY `forbiddenVenueId` + `targetTag` | ✅ | Jean Vilar pas de féminines |
| « Gymnase fermé sur une période » | période cockpit `venue_closed` → **retrait des créneaux** du gymnase les jours fermés (`VenueClosureDays`, 5b #263 ; l'ancienne expansion `forbiddenVenueId` est supprimée) — sur-ferme sur tout le bloc si un jour se répète, jamais sous-ferme | ✅ | (calendrier cockpit) |
| **« Au moins une séance dans tel gymnase »** | FACILITY `minAtVenueId` + `minAtVenueCount` (HARD, mode « au moins N ») — plancher, ≠ forçage ; les autres séances restent libres | ✅ *(ALIGN-05)* | « au moins 1 séance à Armand » ; fail-fast backend si N > séances/semaine |
| « Nb max d'équipes par créneau d'un gymnase » | **`VenueTrainingSlot.capacity`** par créneau (écran Gymnases, borné à 1 si `canSplit=false`) | ✅ | ADN divisible en 3. ⚠ La famille `FACILITY_CAPACITY` (rabot `maxTeams` sur TOUT un gymnase) a été retirée le 2026-08-08 : aucun chemin UI ne la créait |
| « Réserver un créneau à une équipe (verrou) » | onglet « Réserver » → `ScheduleSlotTemplate` `lockLevel=HARD` (pin durable, pas une contrainte) — verrouille le **créneau entier**, divisible ou non : l'équipe épinglée est **seule**, le solveur ne remplit jamais l'autre moitié. Partager = **explicite** : réserver les N équipes (la modal borne le picker à `capacity`) — décision gestionnaire | ✅ *(ALIGN-07)* | SM1 seul sur samedi 18h (cap 2) ; SM1+SM2 co-épinglés = partage assumé |
| **« Éviter d'enchaîner deux gymnases trop éloignés »** | règle implicite `travelTime` (matrice `venue_travel_time` renseignée sur l'écran Gymnases, autofill IGN ou saisie manuelle) — départage « moindre trajet » soft, jamais dominant | 🟡 partiel | **P2-53 RMM-8 (2026-08-26)** — écran livré (PR-3), intensité **fixée en dur à PREFERRED** à l'émission (`ScheduleConstraintBuilder.php:602-604`) : le moteur sait déjà consommer MANDATORY, mais aucun réglage wizard ne peut la basculer — reste le levier Obligatoire, `specs/evolution/roadmap.md` P2-53 |

## Axe COACH

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Coach indisponible tel jour » | COACH_AVAILABILITY `unavailableDays` (UNION, dur) | ✅ | Lionel indispo vendredi |
| « Coach disponible uniquement tel jour » | COACH_AVAILABILITY `availableDays` (INTERSECTION, dur) — mode « disponible uniquement » du wizard | ✅ *(aligné : le wizard l'expose désormais)* | coach dispo seulement le mardi |
| « Coach indispo/dispo sur une **plage horaire** tel jour » | COACH_AVAILABILITY `fromTime`/`untilTime` (Lot C, dur) | ✅ | dispo le mardi qu'à partir de 20h |
| « Un coach ne peut pas être sur 2 séances à la fois » | `COACH_NO_OVERLAP` (implicite) | ✅ | — |
| « Un coach qui joue aussi n'est pas convoqué en double » | `COACH_PLAYER_NO_OVERLAP` (implicite) | ✅ | Mathis coach U13M2 + joueur U21M1 ; Florian coach U18F3 + joueur Loisir 3 |

## Axe PRIORITÉ / RÉPARTITION

| Besoin | Mécanisme | Statut | Exemple BCCL |
|---|---|---|---|
| « Servir d'abord les équipes importantes » | tiers S=10000…D=1, poids **codés en dur** dans le moteur (`objective.py` — le champ `orToolsWeight` du payload est requis mais IGNORÉ) | ✅ soft | rangs S/A/B/C/D |
| « Garantir N séances/semaine par équipe » | `MIN_SESSIONS` — **cible soft**, pas un plancher dur | 🟡 | ⚠ « minimum » non garanti (audit ENG-18) |
| « Jamais 2 équipes sur le même créneau » | `VENUE_AT_MOST_ONE` / capacité (implicite) | ✅ | — |
| « Jour de repos après un match » | bonus soft `add_match_day_rest_bonus` ; le `matchDay` émis est DÉRIVÉ de l'image A/B côté backend (`ScheduleConstraintBuilder::deriveMatchDay`, RMM-5 PR-3) | ✅ soft | — |

> **`matchDay` DÉRIVÉ (RMM-5 PR-3, « le repos suit l'image »)** : le backend n'émet plus le champ
> déclaré brut. `matchDay = max(jours ISO des habitudes de l'équipe ∪ jours ISO des rotations dont
> elle est membre)` — le DERNIER jour de match de la semaine, car le repos qui compte est celui
> d'après lui. Sans image (ni habitude ni rotation), repli sur le champ déclaré `Team.matchDay`
> **converti 0-based → ISO (+1)** : le moteur calcule `rest_day = match_day % 7 + 1`, juste en ISO
> seulement (sans conversion, un samedi déclaré donnait un repos samedi au lieu de dimanche). Sans
> rien → `null`. La valeur émise reste ISO 1..7 ; conversion à l'émission seule, zéro migration, le
> champ déclaré survit en repli legacy.

## Angles morts traités (2026-07-08)

Les 3 angles morts historiques d'alignement sont désormais couverts :
- **ALIGN-04 « Finir avant X h »** → TIME `maxEndTime` (HARD, mode « Fini avant »).
- **ALIGN-05 « Au moins une séance dans tel gymnase »** → FACILITY `minAtVenueId` + `minAtVenueCount` (plancher HARD, fail-soft si inatteignable, fail-fast backend si N > séances/semaine).
- **ALIGN-06 espacement des séances** → règle implicite soft `spacing` (malus jours consécutifs, jamais bloquant).
- **ALIGN-07 verrou HARD sur créneau divisible** → **comportement assumé** : une réservation HARD prend le créneau entier même si `capacity>1` (`blocked_venue_slots`, model.py) ; le partage se déclare en co-épinglant les équipes (aucun diagnostic tant que N ≤ `capacity`). Gardé par `engine/tests/semantic/test_hard_lock_divisible_slot.py` (T1/T2/T3).

## Synthèse des trous restants (❌ / 🟡)

1. ~~« Pas 3 entraînements d'affilée » / écart dur~~ — **RÉSORBÉ le 2026-08-19** (P2-42) : la règle implicite `maxConsecutiveDays` pose la contrainte dure que le soft `spacing` ne garantissait pas. Le nudge `spacing` reste : il départage des ex æquo sur les PAIRES de jours, la règle garantit sur les suites — deux travaux différents.
2. **Minimum de séances garanti** (🟡) — `MIN_SESSIONS` est une cible soft ; à trancher si un plancher dur est voulu (risque d'INFEASIBLE si capacité insuffisante).
3. ~~« Au moins une séance tel jour »~~ — **RÉSORBÉ le 2026-08-23** (ALIGN-09) : mode wizard « au moins une », gate bloquant, clé héritée migrée.

> Détail moteur exhaustif (toutes les clés + mécanismes) : `engine/docs/constraint-vocabulary.md`.
> Offre réellement câblée dans le wizard : `docs/architecture/constraint-matrix.md`.
