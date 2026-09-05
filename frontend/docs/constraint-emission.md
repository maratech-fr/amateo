# Émission des contraintes (frontend) + alignement 3 couches

Last verified @ 2026-09-05 (rotation `documentation-update`, zone non touchée par cette PR —
contrôle de fraîcheur). Re-confronté au code : `resolveTravelRuleIntensity`
(`ScheduleConstraintBuilder.php:965`, repli `TeamLinkIntensity::PREFERRED`) toujours le seul point
de résolution de l'intensité `travelTime` ✓ ; `forcedDays` toujours câblé sur les 3 couches
(`ConstraintValidationService.php:71-79`, `ConstraintConfigValidator.php:74`,
`ConstraintsStep.tsx:365-366`, `engine/app/solver/constraints/targeting.py:74`) ✓ ; la famille
`FACILITY_CAPACITY` toujours retirée du moteur, le commentaire au passé toujours à
`engine/app/main.py:487-489` ✓. Rien de faux trouvé cette passe.

> **But** : (1) lister ce que le **wizard émet** réellement, et (2) mettre les **3 couches côte à côte**
> (frontend → backend → engine) pour repérer les **scissions** et les **angles morts** — les cas où
> « ce que le front veut » n'est **pas** écrit par le backend ou **pas** compris par l'engine.
>
> Compléments : `engine/docs/constraint-vocabulary.md` (tout ce que l'engine comprend) ·
> `backend/docs/constraint-coverage.md` (besoins gestionnaire couverts) ·
> `docs/architecture/constraint-matrix.md` (verrou de test de l'offre wizard↔engine).

## 1. Ce que le wizard émet (`ConstraintsStep.tsx` → `POST/PUT /api/constraints`)

| Écran / mode | `config` émise | ruleType | Exemple BCCL |
|---|---|---|---|
| **TIME** « Pas avant / Pas après » | `minStartTime` et/ou `maxStartTime` | sélecteur (défaut PREFERRED) | EMB max 17h30 |
| **TIME** « Fini avant » | `maxEndTime` (fin = début + durée) | **HARD** (épinglé) | U15 fini avant 20h30 |
| **DAY** « à éviter » | `forbiddenDays` | sélecteur | SM2 évite vendredi |
| **DAY** « uniquement » | `allowedDays` (whitelist) | **HARD** (épinglé) | Vétérans vendredi uniquement |
| **FACILITY** « préfère » | `preferredVenueId` | sélecteur | Matéo préféré Régionales |
| **FACILITY** « évite » | `forbiddenVenueId` | sélecteur | Vétérans interdits |
| **FACILITY** « impose » | `forcedVenueId` | **HARD** (épinglé) | SM4 → Jean Vilar |
| **FACILITY** « au moins N » | `minAtVenueId` + `minAtVenueCount` (défaut 1) | **HARD** (épinglé) | au moins 1 séance à Armand |
| **COACH_AVAILABILITY** « indisponible » | `unavailableDays` (+ `fromTime`/`untilTime` optionnels, Lot C) — la **cible est le `scope`** (`scope: "COACH"` + `scopeTargetId`), jamais une clé du `config` | **HARD** (épinglé) | Lionel indispo vendredi ; indispo mardi à partir de 20:00 |
| **COACH_AVAILABILITY** « disponible uniquement » | `availableDays` (whitelist) (+ `fromTime`/`untilTime` optionnels) — cible = `scope` idem | **HARD** (épinglé) | coach dispo seulement le mardi de 20:00 à 22:00 |
| **Cible** | `targetTag` si groupe (sinon `scope`/`scopeTargetId`) | — | groupe FEMININE / REGIONAL |
| **Onglet « Réserver »** | *pas une contrainte* → entité **`Reservation`** persistée serveur (`POST`/`DELETE /api/reservations`), stratifiée par `calendarEntryId` (NULL = plan de base, sinon overlay de période — même stratification que les contraintes). Le backend la sérialise en verrou **HARD** dans les `slotTemplates` du payload moteur (`ScheduleConstraintBuilder`). Verrouille le **créneau entier**, divisible ou non (ALIGN-07) ; partager = co-épingler les N équipes (picker borné à `capacity`) | — | épingle 1 séance ; SM1 seul, ou SM1+SM2 co-épinglés |
| **Écran Gymnases** (hors onglet contraintes) | *aucune contrainte* — `canSplit` devient `trainingSlots[].capacity` côté backend (`canSplit ? capacity : 1`) | — | ADN divisible |
| **Classement équipes** (hors onglet) | `PRIORITY_TIER` **sans** `orToolsWeight` (poids fixes codés en dur côté engine) | — | rangs S/A/B/C/D |

> ⚠️ `ScheduleSlotTemplate` ne porte **plus** les réservations : il ne stocke que les
> **résultats** du solveur, liés à un `Schedule`. L'ancien flux « store client → template au
> lancement » a été supprimé (B2).

## 1bis. Ce que le wizard émet en **mode période** (#8)

En mode période, le wizard écrit sur trois canaux **qui ne sont pas des contraintes** mais qui
modifient la grille et le périmètre servis au solveur. Les ignorer donne une lecture fausse du
tableau d'alignement ci-dessous : une contrainte peut être parfaitement alignée sur les trois
couches et rester sans effet parce que son gymnase est désactivé pour la période.

| Canal | Ce que le front écrit | Effet |
|---|---|---|
| **Grille de la période** | `VenueTrainingSlot` propres au plan de période (création / édition / suppression) | La période **possède** sa grille — copie du modèle de saison prise à la naissance du plan ; l'overlay ne s'unit **jamais** aux créneaux de la saison |
| **`VenuePeriodOverride`** | `mode: "DISABLED"` (état persisté, table *sparse* — pas de ligne = hériter) + deux **actions** atomiques `reset-grid` (« reprendre la grille ») et `clear-grid` (« vider ») | `DISABLED` retire le gymnase de la période **sans toucher sa grille** ; les deux actions sont destructives et emportent les réservations du gymnase en cascade |
| **`ConstraintPeriodOverride`** | Bascule d'une contrainte **permanente héritée**, uniquement quand elle **dévie du défaut intelligent** | Active/désactive la contrainte **pour la fenêtre** ; le plan de base et le `Constraint.isActive` ne sont jamais modifiés |
| **`TeamPeriodOverride`** | Activation / désactivation d'une équipe pour la fenêtre | Une contrainte TEAM d'une équipe désactivée est **non applicable** et retirée du payload côté serveur |

Détail des écrans et des arbitrages : `frontend-wizard.md`.

## 2. Table d'alignement 3 couches

Colonnes : le **front** l'émet-il ? · le **backend** le transmet/transforme-t-il ? · l'**engine** l'honore-t-il ?

| Clé / notion | Frontend | Backend | Engine | Verdict |
|---|---|---|---|---|
| `minStartTime` / `maxStartTime` | ✅ TIME | passe | ✅ fenêtre dure / bonus soft | ✅ **aligné** |
| `forbiddenDays` | ✅ « à éviter » | passe | ✅ dur / soft | ✅ **aligné** |
| `allowedDays` | ✅ « uniquement » | passe | ✅ whitelist (interdit le complément) | ✅ **aligné** *(depuis ENG-16)* |
| `preferredVenueId` | ✅ « préfère » | HARD→forcé + exclusivité tag | ✅ +60 soft / forcé | ✅ **aligné** |
| `forbiddenVenueId` | ✅ « évite » | passe | ✅ interdit / −60 soft | ✅ **aligné** |
| `forcedVenueId` | ✅ « impose » | + exclusivité tag | ✅ salle forcée | ✅ **aligné** |
| `unavailableDays` | ✅ coach « indisponible » | passe | ✅ union, dur | ✅ **aligné** |
| `availableDays` (coach « disponible **uniquement** ») | ✅ coach *(depuis ALIGN)* | passe | ✅ whitelist (intersection) | ✅ **aligné** |
| `maxTeams` / famille `FACILITY_CAPACITY` | ❌ jamais émis (l'écran Gymnases n'émet pas de contrainte) | ❌ famille **retirée** le 2026-08-08 (SEC-13 PR C) — absente de la liste blanche | ❌ retirée du moteur le même jour (`main.py:487-489`, commentaire au passé) | ✅ **sans objet** : la divisibilité voyage **uniquement** par `trainingSlots[].capacity` (`canSplit ? capacity : 1`). La famille était honorée par le moteur alors qu'aucun chemin UI ne pouvait la créer — zéro ligne en base |
| `venue_closed` (période) | ✅ (cockpit) | → **retrait des créneaux** du gymnase les jours fermés (`VenueClosureDays`, P2-5 5b #263) | ✅ | ✅ **aligné** *(plus d'expansion `forbiddenVenueId` : `expandClosedVenues` supprimé — le créneau fermé est retiré du payload à la source)* |
| `targetTag` (groupe) | ✅ | → N contraintes TEAM | ✅ (par équipe) | ✅ **aligné** |
| `orToolsWeight` (tier) | ❌ jamais émis (nulle part dans `frontend/src`) | ❌ ne l'envoie pas (retiré volontairement) | poids **fixes codés en dur** (`LEVEL_2_OBJECTIVE_WEIGHTS`) | ✅ **sans objet** : la priorité S≫A≫B≫C≫D est garantie côté engine sans transport du poids |
| **`forcedDays`** (« au moins une séance tel jour ») | ✅ mode « au moins une » *(depuis ALIGN-09, HARD seul — pas de sélecteur de règle)* | passe | ✅ compris (dur, somme sur l'UNION par équipe) | ✅ **aligné** *(ALIGN-09)* |
| **`preferredDays`** | ❌ non émis *(DÉCISION FERMÉE ALIGN-09 : reste engine-only)* | — | ✅ (objectif) | 🟠 **scission A** (racine d'ENG-10) |
| **`maxEndTime`** (« Fini avant X h ») | ✅ « Fini avant » | passe | ✅ fenêtre dure (fin ≤ borne) | ✅ **aligné** *(ALIGN-04)* |
| **`minAtVenueId`** + `minAtVenueCount` (« au moins N à ») | ✅ « au moins N » | passe (validation fail-fast) | ✅ plancher dur, fail-soft si inatteignable | ✅ **aligné** *(ALIGN-05)* |
| **`spacing`** (espacer les jours) | *implicite* (aucune saisie) | — | ✅ malus soft jours consécutifs | ✅ **aligné** *(ALIGN-06, règle implicite)* |
| **`maxConsecutiveDays`** (« pas N jours d'affilée », **dur ou soft**) | ✅ panneau Bien-être (3 crans : Inactive / Objectif / Obligatoire) | passe dans `implicitRules` — **omis quand la règle est OFF** | ✅ contrainte dure ou malus −6 | ✅ **aligné** *(P2-42, 2026-08-19 — l'angle mort triple d'ALIGN-08 est fermé)* |
| **`travelTime`** (trajet entre gymnases — départage « moindre trajet » + battement) | ACTIVATION *implicite* (aucune saisie ConstraintsStep — DÉRIVÉE de la présence de matrice `venue_travel_time`, saisie/autofill sur l'écran Gymnases) ; INTENSITÉ **choisie** via `TravelRuleNotice` (sélecteur Préféré/Obligatoire, écrit `VenueTravelRuleSetting`) | passe dans `implicitRules`, **omis** sans matrice — intensité = réglage stocké **?? PREFERRED** (`ScheduleConstraintBuilder::resolveTravelRuleIntensity`) | ✅ départage soft + battement PREFERRED/MANDATORY | ✅ **aligné** *(P2-53 RMM-8, 4 PR, livré le 2026-08-26 — le levier Obligatoire de PR-4 ferme le dernier écart)* |

## 3. Synthèse — scissions & angles morts

- **Aligné** : tout ce que le wizard émet est écrit par le backend et honoré par l'engine. Les scissions historiques « déclaré ≠ effectif » (ENG-10/11/12/13 offre↔engine, **ENG-16** forcedDays↔allowedDays) sont **corrigées** et verrouillées par `constraint_matrix.py`.
- **🟠 Scission A — l'engine sait, le front n'émet pas** : `preferredDays` seul reste dans ce cas — DÉCISION FERMÉE (ALIGN-09, 2026-08-23, voir état des lieux) de ne pas l'exposer. *(`forcedDays` et `availableDays` — coach « disponible uniquement » — ont été **exposés/alignés**.)*
- **✅ Angles morts résorbés (2026-07-08)** : `maxEndTime` (**ALIGN-04**, mode « Fini avant »), **minimum de séances par gymnase** `minAtVenueId` (**ALIGN-05**, mode « au moins N »), **espacement** `spacing` (**ALIGN-06**, règle implicite soft) sont désormais câblés sur les 3 couches et verrouillés (matrice engine + offre wizard). Reste **🔴 `max_consecutive_days`** (écart **dur** « pas 3 d'affilée ») — le soft `spacing` ne le garantit pas.
- **✅ `travelTime` — le front active ET règle désormais** (P2-53 RMM-8, 4 PR, livré le 2026-08-26) : l'activation reste dérivée de la matrice, mais l'intensité (Préféré/Obligatoire) est un vrai levier depuis PR-4 (`VenueTravelRuleSetting`) — l'engine comprenait déjà MANDATORY depuis PR-2, le front peut maintenant le poser. Plus un angle mort.

> **Où le vérifier automatiquement — deux verrous complémentaires, aucun ne couvre tout :**
>
> 1. `constraint_matrix.py` + son test figent l'**offre** wizard↔engine (colonnes Frontend↔Engine, cellules **offertes**).
> 2. **Depuis SEC-13 (2026-08-08)** : la **liste blanche `config`** est la source unique des clés acceptées (`backend/src/Service/ConstraintConfigValidator.php`, `SPEC`) — une clé hors liste est refusée en **422 à l'écriture**, et le job CI **dédié et bloquant `engine-semantics`** (`--group contract`) exige, pour chaque clé moteur, la **preuve qu'elle CHANGE le résultat du solveur** (`backend/tests/CrossStack/ConstraintKeysAreHonouredByEngineTest.php`). C'est le seul verrou qui prouve l'**effet**, pas seulement l'offre. Localement : `make -C backend tests-engine-semantics`.
>
> Restent hors verrou automatique : la couche **backend** (targetTag→N TEAM, `venue_closed`→retrait de créneaux, HARD `preferredVenueId`→forcé) et les **angles morts** ci-dessus — c'est le rôle de l'**axe « alignement contraintes » de l'audit** (`/audit`) de les contre-vérifier bout-en-bout à chaque édition.
