# Chantier « déplacer verrouille » : déplacer un créneau le verrouille (troisième sorte « déplacé manuellement »)

**Statut : la partie B et le chantier D1 sont livrés ; ce plan est désormais D3, CONDITIONNÉ à un signal terrain (un déplacement perdu malgré l'avertissement de D1), roadmap P4-607 ; suspendu, 2026-11-18.** D1 = avertissement avant « Régénérer » quand la version de départ est « modifiée à la main » + refus serveur de déplacer une séance verrouillée tenue par une réservation de son plan (règle et exemple : [`etat-des-lieux.md`](../courantes/etat-des-lieux.md), décision du 2026-11-18). La décision Q2 ci-dessous (retrait de la réservation avec confirmation) est REMPLACÉE par ce refus ; les passages du plan qui la supposent (A, aide T3 « Le déplacer retire la réservation ») sont caducs.

**Ce qui existe déjà (B).** Une équipe ne peut pas avoir plus de réservations plus séances verrouillées (version chargée) que de séances par semaine : le Récap bloque, `/generate` et `/regenerate` refusent en 422 `blocker`, pas `/fill`. Règle, exemple chiffré, décisions et limites : [`etat-des-lieux.md`](../courantes/etat-des-lieux.md) §2 « Un verrou compte comme une séance », limites ouvertes en roadmap (P4-603 à P4-605). Le comptage vit dans `GenerationBlockersGuard::overBookedTeamBlockers` (verrous lus sur le payload, séances HARD de `slotTemplates`). **Ce que A ajoute** : le troisième type de séance comptée, « déplacé manuellement », qui s'ajoute aux réservations et aux séances verrouillées.

**B tel que livré est plus étroit que le B de ce plan** : il ne touche ni le sélecteur de réservation, ni l'écran des contraintes, ni le budget (`SoloReservationBudget`), et n'a créé ni `TeamReservedSlots`, ni `ReservedSlot`, ni `ActiveSharedBlocks`, ni `BlockerScope`. Les passages ci-dessous qui supposent ces objets (§4 `ReservedSlot.kind`, `SoloBudget` et `GET /api/team_solo_budgets`, §7 « Ce que D ajoute aux phrases de B », lignes de §5 et §6 qui parlent du sélecteur) sont à réécrire contre le code livré au moment de planifier A. Les numéros de ligne cités dans les constats datent de la base `origin/lot/double-contexte` 717a712ae : à relire.

**Les questions sur les équipes en groupe** (présence d'un membre par une séance libre, remède d'un Récap quand l'équipe tient trop de créneaux, plafond des cases de groupe) vivent désormais dans une seule ligne de roadmap : P4-603 (« chantier groupes »).

## Pour reprendre

Relire ce plan contre le code livré, faire une version courte, relue par `smith`, validée entière par le fondateur avant `tank` / `neo`. Le corps est un extrait de la v12.

## Décisions du fondateur qui concernent A

- Découpage : B (livré) puis D (déplacer verrouille, troisième sorte « déplacé manuellement »).
- Principe : le moteur ne décide rien à la place du gestionnaire (pas de greffe silencieuse d'un groupe sur un créneau verrouillé pour une seule équipe).
- Règle de comptage : le groupe est une séance de l'équipe, la règle s'applique partout (choix du 2026-11-17).
- ENG-60 passe en contrat 1.14 après PR2.
- Temps Behat (Q-B1) : aucun scénario déjà mergé n'est retouché ; premier repli, S7 de la PR 2 naît en PHPUnit ; second repli, le plafond remonte sur ligne datée, une fois l'excès mesuré.

---

# Corps du plan (extrait de la v12 : ce qui concerne A)

**Besoin** : déplacer un match ou une séance doit verrouiller le créneau, et la règle des créneaux réservés compte une troisième sorte, « déplacé manuellement ».

## 1. Besoin reformulé

Les déplacements à la main restent libres et ne verrouillent rien sur le moment. Chaque séance déplacée est mémorisée (`moved_manually`, `moved_from`). Au lancement d'une génération (« Régénérer » saison, génération d'une période, « Combler automatiquement »), ces séances deviennent des verrous ordinaires (HARD, origine MANUAL, cause `manual_move`). Pas de case à cocher.

Décisions du fondateur, non rouvertes :
- **Verrou différé au lancement.**
- **Q1** : si la génération est refusée, les verrous posés restent.
- **Q2 (déplacement) — REMPLACÉE le 2026-11-18** par le refus livré en D1 (422 `source_reserved` : une séance verrouillée tenue par une réservation de son plan ne se déplace pas, la `Reservation` n'est jamais retirée par un déplacement). Texte d'origine, pour mémoire : déplacer un créneau réservé ne modifie que la version chargée ; seule la `Reservation` de l'équipe déplacée est retirée, avec confirmation et trace.
- **Q3** : l'annulation d'un « Prendre sa place » laisse l'équipe évincée libre.
- **A (CADUQUE pour une séance réservée : D1 la refuse, voir Q2)** : case libre, le serveur refuse d'abord (« ce déplacement retire 1 réservation »), sans calcul ; confirmation ; le calcul tourne une fois. Case occupée, la vérification existante rapporte aussi la réservation : un message, une confirmation.
- **C** : une séance déplacée devenue verrou peut faire refuser « Régénérer » pour une cause que le Récap n'avait pas montrée. Accepté.
- **B, règle unique, version chargée seule.** Les **créneaux réservés** d'une équipe sont : (1) ses réservations, (2) ses séances verrouillées (HARD d'origine autre que RESERVATION), (3) ses séances déplacées à la main. La PR B livre (1) et (2) ; la PR D ajoute (3). Chaque créneau compte une fois ; la séance HARD d'origine RESERVATION est la réservation elle-même. **Deux garde-fous seulement** : l'écran des contraintes et le Récap (422 `blocker` avant le moteur, trois guichets). Pas de refus du cadenas, pas de régénération obligatoire, pas de visibilité de ce qu'une réservation remplace (roadmap, §12).

Définitions :
- **Case d'origine** : la case de la séance à son premier déplacement depuis la naissance de la version. A→B→C : l'origine reste A ; retour en A = mémoire effacée.
- **Séance posée** (« Placer », « Remettre ») : pas d'origine, toujours « déplacée ».
- **Séance réservée**, deux formes : (a) HARD d'origine RESERVATION ; (b) NONE posée sur une réservation créée après la génération.
- **Créneau réservé** (ou **créneau tenu**) : un couple (équipe, case), case = gymnase + jour + heure `H:i`. Priorité d'étiquette réservation > verrou > déplacement.
- **Séance verrouillée** : toute séance HARD de la version chargée dont l'origine n'est pas RESERVATION (cadenas, copie, verrou hérité, origine inconnue ; constats 35 à 37).

## 2. Constats vérifiés dans le code

1. **Quatre gestes, un service.** `MoveSlotService::move` `:121` (écriture `:248-268`), `place` `:306` (`:371-385`, sans transaction), `moveGroup` `:429`, `swap` `:632`. Aucun n'écrit de mémoire par séance.
2. **Les gestes ne connaissent que le verrou Redis** (`:130`, `:308`, `:442`, `:649`). La version chargée est vérifiée par le contrôleur, hors verrou (`ManualEditController.php:158`, `:1064-1078`).
3. Le worker lit la version source à son démarrage (`GenerateScheduleHandler.php:239-255`, `:263-269`, `:528-539`).
4. **Trois guichets.** `RegenerateController.php:71` (refus d'état `:73-119`, contenu `:123-140`, transaction `:164-178`). `GenerateScheduleController.php:64` (`:66-101`, `:107-140`, `:157-179`). `FillPeriodPlanController.php:86` (`:88-148`, `:152-176`, `:188-211`).
5. `ManualEditService::applyLock` (`:29-52`) pose l'origine MANUAL (`:36`), écrit `LOCK_CHANGED`.
6. Le payload de saison est caché, séances de la version chargée comprises (`ScheduleConstraintBuilder.php:243-250`, `:289`) ; purge en fin de requête (`CacheInvalidationListener.php:67-71`, `:89-106`).
7. Le builder saute un HARD d'origine RESERVATION et envoie la `Reservation` (`ScheduleConstraintBuilder.php:707-723`). En période, la greffe reprend tout HARD de la version chargée, écarte les gymnases désactivés et dédoublonne par placement (`:1092`, `:1109-1121`).
8. Anomalie non jouée : `move()` ne touche ni au verrou ni à la `Reservation`.
9. `ReservationRemoval::remove` purge les HARD de la même case dans toutes les versions (`:45-48`, `:62-84`). Inutilisable pour Q2 (déplacement).
10. Séances de groupe nées d'une mutualisation au planning : HARD/MANUAL et liées au bloc par `sharedTrainingBlockId` (`MutualizeSlotService.php:250-252`, `:725`).
11. Le comblement épingle toute la version source, séances NONE comprises (`GenerationBlockersGuard.php:76-81`, `GenerateScheduleHandler.php:536-539`).
12. L'import réutilise une ligne NONE au même placement (`ScheduleResultImporter.php:64-78`).
13. **Écran planning.** Cible libre : appel réel direct (`useRetouchGestures.ts:317-357`). Cible occupée : essai existant (`:530-549`), échange (`:702`), confirmation en appel réel (`:566-577`).
14. Copies : `PeriodPlanSiblingCopier.php:147-162` (copie des occupations `:233-240`), `PeriodPlanTranscriber.php:208-226`, `SeasonDataPurger.php:136`.
15. Tests en gate (`ci.yml`) : `SlotMoveVerdictTest` `:831`, `SlotPlacementVerdictTest` `:837`, `SlotMoveGroupVerdictTest` `:842`, `SlotSwapVerdictTest` `:850`, `MutualizeSlotApiTest` `:869`, `GenerationRefusesARecapBlockerTest` `:899`, `LoadedVersionIsTheOnlyEditableTest` `:908`, `MemberWriteSweepTest` `:928`, `LockOriginProvenanceTest` `:956`.
19. `structureHash` ne compte que les HARD (`ScheduleConstraintBuilder.php:194`).
20. `inFlightInSeason` compte toute version en vol de la saison (`ScheduleCapabilityResolver.php:96-103`).
21. « Charger » de saison restaure la photo, réservations comprises (`RegenerateFromVersionController.php:116-131`).
22. Hors garde : le cadenas (`ManualEditController.php:133`) et « Charger les verrous » (`LoadLocksController.php:101-103`).
23. Routes : `/api/schedule-slots/{id}/move`, `/api/schedules/{id}/place-slot`, `/api/schedule-slots/move-group`, `/api/schedule-slots/swap`.
24. Refus 422 à l'écran : aucun des lancements ne relit les séances (`planning/queries.ts:723-737`, `:747-760`, `PlanningPage.tsx:1141-1148`).
35. **La transcription verrouille tout.** `PeriodPlanTranscriber.php:208-226` : copie « à l'identique », HARD, origine RESERVATION si une réservation du plan coïncide, sinon MANUAL ; aucun `sharedTrainingBlockId` posé. Marqueur sur la version (`:67`, `:172`), lu par aucun écran. Automatique sur une fermeture vierge (`GenerateStep.tsx:239-271`), manuelle ailleurs (`wizard/texts.ts:120`, `:124`).
36. **La copie de période sœur recopie le verrou à l'identique** (`PeriodPlanSiblingCopier::copySlots` : niveau ET origine, verbatim, jamais deviné). Ni elle ni la transcription ne recopient `sharedTrainingBlockId`.
37. **Le marqueur se perd à la première régénération** (`ScheduleResultImporter.php:174-186`).
42. **Patrons Behat voisins.** `SmallClubFixtureTrait.php:213-245`, `:260-266`, `:373-386` ; `TrainingBlockContext.php:289-362`, `:632-653`, période transcrite sans calcul `:234-287`. Suites : `behat.dist.php`, `boucle-travail` `:68`, `bloc` `:123-125`, `verrou-periode` `:148`.
43. **Second scénario de `un-verrou-de-periode…`** (`PeriodLockSmallClubContext.php:71-114`) : toutes les séances de V1 finissent verrouillées.
48. **Un groupe dont les membres sont épinglés à part ne fait pas échouer la génération : le moteur le sépare et le dit.** Lu, non joué. Porte la décision 4a et le bloquant 2a.
    - Le moteur crée une décision de bloc par case candidate ; un membre sans variable ni verrou rend la case impossible (`engine/app/solver/constraints/targeting.py`, `add_shared_block_constraints` `:721`).
    - Après un INFEASIBLE et un diagnostic conclu, si des verrous existent, une passe de plus lève les règles de demande du noyau (`engine/app/main.py:447`, `_best_effort_solve` `:1068`), `shared_block:` compris (`:1065`).
    - Seul le bloc sur-épinglé est exclu (`main.py:1150`). La sonde de causalité libère les verrous et accepte de lever la règle si le problème devient faisable (`main.py:1200`).
    - Le planning sort `completed` ; le bloc sacrifié est nommé par un `rule_sacrificed`, WARNING (`engine/app/solver/result_builder/diagnostics/shared_blocks.py:215-221`, `:669-726`).
    - `frozen_occupancy` ne concerne que la vérification d'un geste (`targeting.py:860`).
51. **Cadenas.** Une séance à la fois (`useLockControls.ts:37-50`) ; seule la mutualisation pose `sharedTrainingBlockId` (constat 10).
52. **Version chargée du plan de saison** : `season.live_context_schedule_id` s'il pointe une version COMPLETED du plan, sinon la dernière COMPLETED (`SchedulePlanProvisioner.php:289-311`). `TrainingBlockContext` insère dans la dernière COMPLETED (`:308-330`, `:632-653`) sans lire `live_context_schedule_id`. Un cadenas sur une version non chargée répond 409 `not_loaded_version` (`ManualEditController.php:158`, `:1071-1078`).
53. **Plafond de temps.** Verdict sur la VM seulement (`scripts/suite-time.py:6-7`), dernier acte du job Behat (`ci.yml:2110`) ; relèvement sur ligne datée (`suite-time.py:10-14`). Le « Lot Gate » (`ci.yml:2249-2250`) ne joue pas Behat mais joue la liste bloquante (constat 83). Une suite seule : `make -C backend behat SUITE=<nom>` (`backend/Makefile:159-176`).
58. Nettoyage de `TrainingBlockContext` (`:540-600`) : réservations des équipes jetables (`:548`), équipes, gymnase, entrée de période, séances insérées, pointeur reposé. Aucune ligne ne retire des créneaux de gymnase.
63. **Une suite ou une feature seule sur la stack d'épreuve** : `vendor/bin/behat --suite=<nom>` dans le conteneur PHP (`docs/testing/tests-par-parcours.md:396`).
64. **Pour le moteur, une réservation et un verrou sont la même épingle** (`ScheduleConstraintBuilder.php:704-730`). Un partenaire de groupe libre reste candidat sur la case où un membre est épinglé et l'y rejoint (`engine/app/solver/model.py:207-216`). Lu, non joué.
65. **Le moteur ne lit pas les séances libres comme des contraintes** (`engine/app/solver/model.py:256`).
76. Routes des gestes de sortie : cadenas posé ou ôté `POST /api/schedule-slots/{id}/manual-edit/lock` (`ManualEditController.php:92-140` : aucune lecture des groupes, refus seulement si une génération tourne), poser une séance `POST /api/schedules/{id}/place-slot` (`:294`).
77. **La reprise automatique d'une fermeture vierge.** Effet déclenché à l'arrivée sur l'étape quand le plan n'a aucune version (`GenerateStep.tsx:246-271`) ; un 409 « déjà versionné » est bénin (`:260-263`).
83. **Le « Lot Gate » joue la liste bloquante par alias** (`ci.yml:2404`, ancre `:575-578`).
88. **Fichiers partagés avec les autres PR du lot.**
    - **ENG-60** (non mergée, non rebasée) — avec B : `.github/workflows/ci.yml`, `docs/testing/blocking-tests.md`, `specs/courantes/openapi-snapshot.json` et son `.meta.md`. Avec D en plus : `la-boucle-de-travail-du-gestionnaire.feature`, `tests/Behat/SeasonWorkLoopContext.php`, `MoveSlotService.php`. Lus ici et modifiés par ENG-60 : `ScheduleConstraintBuilder.php`, `TeamStateProcessor.php`, `Dto/TeamInput.php`, `CascadePlan.php`, `SharedBlockPreSolveMirrorParityTest.php`. ENG-60 porte une migration : celle de D prend un horodatage postérieur. Liste confirmée par `smith`.
    - **PR 2** (aucune branche ; trois PR 2a, 2b, 2c d'après son plan) — avec B et D : `ci.yml` et `blocking-tests.md` (2c), `behat.dist.php` (2b), le snapshot OpenAPI, `docs/glossary.md` (2a), `specs/courantes/etat-des-lieux.md`, `specs/evolution/roadmap.md`, `docs/testing/test-coverage-map.md`, `backend/docs/error-copy.md` ; avec D : `engine/docs/constraint-vocabulary.md` (2c).
93. **Temps Behat du lot.** Registre unique prévu `.next/budget-temps-lot-double-contexte.md`, tenu par l'orchestrateur, absent du disque (`plan-pr2-double-contexte-v11-2026-11-17.md:702`). Enveloppes : B 15 s, D 25 s. **Décision du fondateur (Q-B1, `:15`, `:700`, `:887`)** : aucun scénario déjà mergé n'est retouché ; premier repli, S7 de la PR 2 naît en PHPUnit ; second repli, le plafond remonte sur ligne datée, une fois l'excès mesuré ; les enveloppes de ce chantier ne se déplacent pas sans le fondateur.

## 3. Conception

### 3.1 Mémoire et marquage [D]
- Deux colonnes sur `schedule_slot_template` : `moved_manually` (booléen, défaut faux), `moved_from` (JSON nul). Non exposées par l'API. Migration additive.
- **Seule une séance non verrouillée est marquée**, par l'un des quatre gestes, jamais en essai.
- Échange : chaque côté non verrouillé est marqué. Évincée : supprimée.
- Verrouiller ou déverrouiller à la main efface la mémoire.
- La copie de plan frère recopie la mémoire. L'import la remet à zéro sur une ligne réutilisée.

### 3.2 Verrouillage au lancement [D]
Règle : **un refus 403/404/409 ne verrouille rien ; un refus 422 laisse les verrous posés (Q1).**
1. Refus d'accès et d'état actuels, inchangés.
2. **Transaction T1 (neuve)** : `lockPlanScope` ; assertions d'état du guichet ; `ManualMoveMemory::lockMovedSlotsOfLoadedVersion($planId)` ; commit.
3. Si au moins un verrou posé : `CacheInvalidationListener::flushInvalidations()` après le commit.
4. Refus de contenu (422), dont le blocage « un verrou compte comme une séance » (livré) : ils lisent l'état verrouillé.
5. **Transaction T2 (l'existante)** : verrou, assertions, second passage du balayage, PENDING, commit ; purge du cache avant le dispatch si le second passage a verrouillé.

Par guichet : `/regenerate` et `/fill` rejouent leurs assertions ; `/generate` saison rejoue `assertSeasonPlanNotChosen` et « déjà générée » ; `/generate` période prend `lockPlanScope` en T1 et T2.

Le balayage : séances marquées et NONE de la version chargée, ordre `id ASC` ; `applyLock(HARD, ['cause' => 'manual_move'], flush: false)` puis mémoire effacée ; si une `Reservation` de la même équipe, même case, même plan coïncide, l'origine devient RESERVATION.

Limites assumées : un déplacement validé entre T1 et T2 est verrouillé sans avoir été lu par les refus de contenu ; un refus d'état en T2 laisse les verrous de T1 ; le second passage n'est pas prouvable par test.

### 3.3 Garde côté geste [D]
Dans la transaction d'écriture des quatre gestes : `lockPlanScope` ; version chargée revérifiée sous le verrou ; refus `ScheduleGenerationInProgressException` si `inFlightInSeason`. `place()` gagne une transaction.

### 3.4 Séance réservée déplacée (Q2 déplacement, décision A) [D]
- **Décompte avant le moteur.** Le serveur compte les `Reservation` du même plan que chaque séance écrite laisse derrière elle. Vaut pour l'essai et l'appel réel.
- **Verdict sans les réservations quittées** (`MoveSlotService.php:1348`, `:1411-1438`).
- **Cible libre** (`/move`, `/move-group`) : sans `releaseReservations: true` et décompte > 0 → 409 `reservation_release_unconfirmed`, rien écrit, moteur non appelé. L'écran ouvre T1 puis renvoie le geste avec le drapeau.
- **Cible occupée et échange** : l'essai existant porte `releasedReservations` ; un seul dialogue, un seul appel réel avec le drapeau.
- **Appel réel confirmé**, sous le verrou du §3.3 : réservations relues ; la séance garde son niveau (HARD → origine MANUAL ; NONE → marquée) ; chaque réservation retirée par `ReservationRemoval::removeOnly` ; trace `ENTITY_DELETED` `{cause: 'manual_move', slotId}` ; la réponse porte le nombre réellement retiré.
- Groupe : chaque équipe déplacée perd sa propre réservation, aucune autre.
- **Seule la version chargée est touchée.**

### 3.5 Écran après un refus 422 [D]
L'écran relit les séances de la version chargée : le cadenas de T1 apparaît sans rechargement.

## 4. Contrats nouveaux

**PR D**
- **Entité `ScheduleSlotTemplate`** : `isMovedManually(): bool`, `setMovedManually(bool): self`, `getMovedFrom(): ?array{venueId: string, dayOfWeek: int, startTime: string}`, `setMovedFrom(?array): self`.
- **`App\Service\ManualMoveMemory`** : `recordMove(ScheduleSlotTemplate $slot, string $fromVenueId, int $fromDayOfWeek, DateTimeImmutable $fromStartTime): void` ; `recordPlacement(ScheduleSlotTemplate $slot): void` ; `forget(ScheduleSlotTemplate $slot): void` ; `lockMovedSlotsOfLoadedVersion(string $schedulePlanId): int`.
- **`App\Service\MovedReservationRelease`** : `leftBehind(Schedule $schedule, array $movingSlots): array` (`list<Reservation>`) ; `release(Schedule $schedule, array $departures): int`, `$departures` = `list<array{slot: ScheduleSlotTemplate, venueId: string, dayOfWeek: int, startTime: DateTimeImmutable}>`.
- **`ReservationRemoval::removeOnly(Reservation $reservation): void`**.
- **`App\Exception\VersionNotEditableException`** : `__construct(public readonly string $code)`.
- **`ReservedSlot.kind`** gagne `'manual_move'` ; **`SoloBudget`** et **`GET /api/team_solo_budgets`** gagnent `manualMoveCount: int` (entre dans `individualUsed`) ; une séance déplacée est un créneau tenu (présence tenue, `groupLockedCount`, `groupLockHeld`) ; `offerableGroups` ajoute `manualMoveCount` à sa somme ; `WIZARD_TEXTS.reservedBreakdown(reserved, locked, group?, moved?)`.
- **`POST /api/schedule-slots/{id}/move`, `/move-group`, `/swap`** : corps `releaseReservations?: true` ; réponses `releasedReservations: int` si > 0 ; refus `409 {code: "reservation_release_unconfirmed", releasedReservations: int, error: string}`.
- **`POST /api/schedules/{id}/place-slot`** : `undo?: true`.
- **Types écran planning** : `MoveResult.releasedReservations?: number`, `PlaceSlotBody.undo?: true`, `SlotMovePatch.releaseReservations?: true`, `SwapBody.releaseReservations?: true`, erreur `ReservationReleaseUnconfirmedError { releasedReservations: number }`, prop `EvictConfirmDialog.releasedReservations?: number`.
- **Traces** : `LOCK_CHANGED {before: "NONE", after: "HARD", cause: "manual_move"}` ; `ENTITY_DELETED` sur `Reservation` `{cause: "manual_move", slotId}`.
- **Textes** : `PLANNING_TEXTS.moveReservedTitle(n)`, `moveReservedDescription(n)`, `releasedReservationsSuffix(n)`, `evictReleasedReservations(n)`.

Aucun endpoint, champ de payload moteur ou sujet Mercure nouveau, dans aucune des deux PR.

## 5. Carte des conséquences

| Comportement changé | Qui l'écrit | Qui le lit | Garde avant | Garde après |
|---|---|---|---|---|
| Mémoire « déplacée » [D] | `MoveSlotService`, `PeriodPlanSiblingCopier` | `ManualMoveMemory`, `TeamReservedSlots` | gestionnaire, version chargée, verdict | écran et Récap la comptent dès le geste |
| **Un seul membre d'un groupe déplacé à part** [D] | `MoveSlotService` | `TeamReservedSlots` (sorte `manual_move`) | — | compté en plus dès le geste ; le verrou de T1 reste |
| Séance marquée, encore NONE [D] | — | les autres blocages du Récap la lisent comme libre (`SecondSessionSameDayDetector.php:78`, `OrphanPinGuard.php:205-216`) | — | T1 la verrouille ; un 422 peut nommer ce que le Récap n'a pas montré (C) |
| Verrou au lancement [D] | les trois guichets par `applyLock` | payload saison, greffe période, `OrphanPinGuard`, import, `ClosurePinRemover`, `TeamReservedSlots` | refus d'accès et d'état | refus de contenu, puis moteur |
| Verrou contre une règle ajoutée depuis [D] | T1 | moteur | — | « la réservation gagne » |
| Empreinte du plan [D] | T1 | bandeau (`staleness.ts:37-38`) | — | texte inchangé |
| Cache purgé dans la requête [D] | les trois guichets | `buildForClubSeason`, le worker | commit | — |
| Geste refusé si génération en file [D] | `MoveSlotService` | écran (`useRetouchGestures.ts:516-519`) | `lockPlanScope` | refus plus large que le verrou |
| `Reservation` retirée au déplacement [D] | `MovedReservationRelease` | Récap, étape Réservations, payload, import, photo, budget | 409 puis confirmation, ou essai puis dialogue | toast, trace |
| Origine MANUAL après ce déplacement [D] | `MovedReservationRelease` | fiche, `TeamReservedSlots` | — | — |
| Autres versions du plan [D] | personne | gardent leur séance | — | « Charger » : le compte change |
| Phrases de B complétées par « déplacé(e) » [D] | `GenerationBlockersGuard`, gardes d'écriture, `wizard/texts.ts`, `groupReservation.ts` | Récap, guichets, sélecteur, A4, A5, A3 | merge de B | — |
| Séances relues après un 422 [D] | écran | gestionnaire | — | — |

**Effet sur la PR 1, mergée** [D] :
- `engine/docs/constraint-vocabulary.md:436-441` reste vrai. D y ajoute, après `:441` : « Une séance collée à la main est un verrou à la régénération : elle reste. Si sa voisine est libre, c'est la voisine qui paie 50 et qui est écartée quand une place espacée existe (libre × verrou). Si la voisine est verrouillée ou réservée : coût 0, silence. »
- Dans le plan archivé `plan-pr1-double-contexte-v9-2026-11-17.md`, deviennent fausses au merge de D : `:111`, `:202`, `:203`, la dernière phrase de `:291`, `:640`. Rien à corriger dans le dépôt.
- `specs/courantes/etat-des-lieux.md:501` reste vrai.

## 6. Parcours de bout en bout

Tous lus dans le code, aucun joué.

1. **Déplacer** [D]. U13 (lundi 18:00, Platane) → « Déplacer » → mercredi 18:00 libre. « Créneau déplacé. » Aucun cadenas.
2. **Déplacer encore** [D]. U13 → jeudi 18:00. L'origine reste lundi 18:00.
3. **Annuler** [D]. U13 revient mercredi, « Dernier geste annulé. » Elle reste « déplacée ».
4. **Geste inverse complet** [D]. Je remets U13 lundi 18:00 : plus rien n'est retenu.
5. **Régénérer** [D] (U13 laissée mercredi). Le cadenas apparaît ; la nouvelle version porte U13 mercredi, « Épinglé manuellement ».
6. **Refus (Q1)** [D]. « Régénérer » refusé 422 : motif affiché, séances relues, U13 porte son cadenas.
7. **Déverrouiller** [D]. U13 redevient libre et n'est plus « déplacée ».
8. **Pendant la file d'attente** [D]. Déplacement de U11 avant le démarrage du calcul : « Une génération est en cours pour ce club — réessayez ensuite. »
9. **Séance réservée, cible libre (A)** [D]. SM1 (lundi 20:30) → clic mardi 20:30 → dialogue T1. « Annuler » : rien ne bouge. « Déplacer » : le calcul tourne une fois, « Créneau déplacé — 1 réservation retirée. » Forme (a) : cadenas gardé. Forme (b) : pas de cadenas avant la génération. « Annuler le dernier geste » : SM1 revient lundi, sans réservation. Les autres versions gardent SM1 au lundi.
10. **Séance réservée, cible occupée (A)** [D]. SM1 → mardi 20:30 occupé par U17 → un seul dialogue. « SM1 déplacée — U17 est à replacer — 1 réservation retirée. » Idem pour l'échange.
11. **Variante période** [D]. Mêmes gestes sur la version chargée d'un plan de période ; seule une réservation de cette période est retirée.
12. **Variantes de geste** [D]. Échange : les deux côtés libres retenus. Groupe réservé de 3 équipes → T1 au pluriel. Évincée : libre ; « Remettre » la retient ; « Annuler » la repose sans la retenir (Q3).
    - **Un seul membre d'un groupe déplacé à part** : U11-1 (1 séance, en groupe le mercredi avec U11-2) déplacée seule au jeudi. Récap : « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée ou déplacée) : déverrouillez ou remettez à sa place 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » **Inverse** : je la remets au mercredi, Récap vert.
13. **Déplacé, puis le créneau disparaît (C)** [D]. Période : « Régénérer » refusé, la phrase nomme le gymnase, le jour et U13 (`OrphanPinGuard.php:214-215`). Saison : aucun refus.
14. **Déplacé, puis une règle la contredit** [D]. « Régénérer » aboutit, U13 reste mercredi, l'écart est nommé.
17. **Recharger une version** [D]. SM1, 1 séance, réservée lundi. En version 2 je la déplace au mardi (réservation retirée). Je charge la version 1 puis la version 2 : la réservation du lundi revient. Récap : « SM1 a 2 séances comptées pour 1 déclarée (1 réservation, 1 séance verrouillée ou déplacée) : … ». Remède : retirer la réservation, ou remettre SM1 au lundi.

## 7. Textes, mot pour mot

### PR D

**Planning (inchangés depuis la v3).**
- **T1, 1 réservation.** Titre : « Déplacer ce créneau réservé ? » Corps : « Une réservation de gymnase tient cette équipe à ce créneau. Si vous déplacez la séance, la réservation est retirée et la séance restera à sa nouvelle place à la prochaine génération. Les autres versions du planning ne changent pas. « Annuler le dernier geste » ne recrée pas la réservation. » Boutons : « Déplacer » / « Annuler ».
- **T1, N ≥ 2.** Titre : « Déplacer ces créneaux réservés ? » Corps : « Ce déplacement retire {N} réservations de gymnase : les séances resteront à leur nouvelle place à la prochaine génération. Les autres versions du planning ne changent pas. « Annuler le dernier geste » ne recrée pas les réservations. »
- **Paragraphe des dialogues d'éviction et d'échange** : « Ce déplacement retire aussi 1 réservation de gymnase. « Annuler le dernier geste » ne la recrée pas. » / « Ce déplacement retire aussi {N} réservations de gymnase. « Annuler le dernier geste » ne les recrée pas. »
- **T2, suffixe de toast** : « — 1 réservation retirée » / « — {N} réservations retirées », avant « — N compromis ». Exemples : « Créneau déplacé — 1 réservation retirée. » ; « SM1 déplacée — U17 est à replacer — 1 réservation retirée. » ; « Groupe déplacé — 3 réservations retirées. » ; « Séances échangées — 2 réservations retirées — 2 compromis ».
- **T3, aide de la fiche (CADUQUE : D1 dit « ne se déplace pas d'ici, retirez d'abord la réservation »)**, forme (a) : « Ce créneau est réservé auprès du gymnase. Le déplacer retire la réservation. »
- **Refus 409** : « Ce déplacement retire 1 réservation de gymnase : confirmez-le. » / « Ce déplacement retire {N} réservations de gymnase : confirmez-le. »

**Ce que D ajoute aux phrases de B.**
- Sélecteur, troisième sorte du détail : « 1 déplacé manuellement » / « 2 déplacés manuellement ». Exemples : « Tous ses créneaux sont pris : 1 réservé · 1 verrouillé · 1 déplacé manuellement » ; « 1 créneau de trop, groupe compris : 1 en groupe · 1 réservé · 1 déplacé manuellement ».
- Sélecteur, séance du groupe : « Tous ses créneaux sont placés — la séance du groupe {nom} est verrouillée ou déplacée : libérez-la au planning pour réserver le groupe ailleurs » ; « — 2 séances du groupe {nom} sont verrouillées ou déplacées : libérez-en une au planning pour réserver le groupe ailleurs ».
- Option de groupe : « {équipe} a déjà toutes ses séances de la semaine, dont 1 verrouillée ou déplacée : libérez-la au planning. » ; « … dont 2 verrouillées ou déplacées : libérez-en une au planning. » ; « Sa séance commune est verrouillée ou déplacée au planning : libérez-la pour réserver le groupe ailleurs. » ; « Ses 2 séances communes sont déjà posées, dont 1 verrouillée ou déplacée au planning : libérez-la pour réserver le groupe ailleurs. » ; « … dont 2 verrouillées ou déplacées au planning : libérez-en une pour réserver le groupe ailleurs. »
- Règle f : détail « (1 verrouillé, 1 déplacé manuellement) » ; fin : « Retirez une réservation, déverrouillez une séance ou remettez la séance déplacée à sa place, ou augmentez son nombre de séances. »
- Déclaration de groupe : « Cette mutualisation ferait passer des créneaux réservés, verrouillés ou déplacés au-dessus de ce qui reste hors groupe pour : U13-1. Retirez une réservation, déverrouillez une séance ou remettez la séance déplacée à sa place avant de mutualiser. »
- Séances communes déjà tenues : « … dont 1 verrouillée(s) ou déplacée(s) au planning : … Retirez une séance mutualisée, ou déverrouillez-en ou remettez-en une à sa place au planning, avant d'en ajouter une autre. »
- Plafond d'un membre : « … Retirez une réservation, déverrouillez une séance ou remettez sa séance déplacée à sa place, ou augmentez son nombre de séances. »
- Récap et guichets : le texte ne sépare pas verrouillée de déplacée. La famille 1 ne change pas. La famille 2 devient :
  - détail : « 1 séance verrouillée ou déplacée » / « 2 séances verrouillées ou déplacées » ;
  - remède, seuls des verrous ou déplacements : « déverrouillez ou remettez à sa place 1 séance » / « déverrouillez ou remettez à leur place 2 séances » ;
  - les deux, N = 1 : « retirez une réservation, déverrouillez une séance ou remettez une séance déplacée à sa place » ;
  - les deux, N ≥ 2 : « retirez, déverrouillez ou remettez-en 2 à leur place » ;
  - branche Q2 avec N ≥ 1 : « déverrouillez ou remettez d'abord à sa place 1 séance, puis retirez-lui 1 séance de groupe (…) » ;
  - « supprimez le groupe » : mêmes conditions ;
  - exemples : « U15-1 a 3 séances comptées pour 2 déclarées (1 séance de groupe, 1 réservation, 1 séance verrouillée ou déplacée) : retirez une réservation, déverrouillez une séance ou remettez une séance déplacée à sa place, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » ; « U13F a 2 séances comptées pour 1 déclarée (2 séances verrouillées ou déplacées) : déverrouillez ou remettez à sa place 1 séance, ou augmentez le nombre de séances de l'équipe. » ; « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée ou déplacée) : déverrouillez ou remettez à sa place 1 séance, supprimez le groupe, ou … ».
- Ligne d'aide de période : inchangée.

**Inchangés (les deux PR)** : « Créneau déplacé. », « Dernier geste annulé. », la confirmation de déverrouillage (`planning/texts.ts:44-46`), le refus « génération en cours », le bandeau de péremption, les étiquettes de la fiche du planning, le blocage « Groupe … réservé sur N cases », « {équipe} : en pause sur cette période, reste membre du groupe » (`wizard/texts.ts:312`), « {équipe} est en pause cette période. », le bandeau d'un gymnase désactivé (`SlotReservationModal.tsx:684`), le refus de réservation de groupe sans verrou. Aucun texte pour le marquage au planning. **Supprimé en B** : « · indisponible » (`wizard/texts.ts:46`).

## 8. Comportement → scénario Behat (brief de `tank`, rouges avant `neo`)

**Décors.** Aucune génération de plus. Aucune version fabriquée en SQL dans un pas Behat.
- Les gestes avec calcul se jouent là où une génération est déjà payée : `la-boucle…` et `un-verrou-de-periode…`.
- La feature neuve se joue sur le club du bac à sable, patron `TrainingBlockContext`.
- **Règle du décor, version chargée** : tout pas qui insère une séance au planning de saison l'insère dans la version que rend `lockSourceScheduleIdOfPlan(plan SEASON)` (constat 52). Le pas neuf lève une `RuntimeException` qui nomme les deux identifiants s'ils diffèrent de « la dernière COMPLETED ».
- Le gymnase jetable reçoit des créneaux par l'API.
- **Nettoyage** (`TrainingBlockContext::nettoyer`, `:540-600`) : il retire aussi la réservation individuelle du scénario 1, la réservation de période du scénario 2 et les créneaux ajoutés au gymnase jetable.
- **Aucun pas Behat n'appelle `/regenerate`, `/generate` ou `/fill` sur le club du bac à sable.**

### PR D

**`la-boucle-de-travail-du-gestionnaire.feature:12`, suite `boucle-travail`.** Pas neufs entre la ligne 19 et la ligne 20 :
1. « Et une équipe du socle a une réservation sur la case où elle s'entraîne »
2. « Et je déplace une séance libre du socle vers une case libre : le budget de son équipe compte 1 créneau déplacé manuellement »
3. « Et je déplace une autre séance libre, puis je la remets à sa case d'origine : le budget de son équipe ne compte aucun créneau déplacé »
4. « Et je déplace la séance réservée vers une case libre sans confirmer : le déplacement est refusé et annonce 1 réservation »
5. « Et je confirme : le déplacement annonce 1 réservation retirée et le récapitulatif ne la liste plus »
6. Les trois pas existants (lignes 20 à 22), puis les deux pas de 1b (lignes 23-24), inchangés.
7. Juste après le « Alors » existant (ligne 26), avant les pas de 1b des lignes 27 à 30 : « Et la séance déplacée est à sa case d'arrivée, verrouillée ; celle remise à sa place n'est pas verrouillée » ; « Et la séance réservée déplacée est à sa case d'arrivée, verrouillée, et son ancienne case n'est plus réservée ».

Règles du décor : aucune équipe déplacée ou réservée n'est SM1, ni SF1, ni membre d'un groupe ; chaque case d'arrivée est libre, sans réservation, un jour où l'équipe ne s'entraîne pas (`MoveSlotService.php:337-338`) ; deux filtres SQL ajoutés aux pas existants (`SeasonWorkLoopContext.php:115-164`, `:166-202`) ; l'équipe verrouillée par le pas de la ligne 22 n'est ni déplacée, ni réservée, ni membre d'un groupe. ENG-60 ajoute 15 lignes à cette feature et 123 à ce contexte : positions à recaler si elle merge avant D.

**`un-verrou-de-periode-survit-a-la-regeneration.feature:9`, suite `verrou-periode`** : avant la première régénération, « Et une troisième séance libre de cette version, d'une autre équipe hors de tout groupe, déplacée à la main vers une case libre » ; après chacune, « Et la séance déplacée est à sa case d'arrivée, verrouillée ».

| # | Décision | Scénario |
|---|---|---|
| S1 | Déplacé, régénéré : à sa case d'arrivée, verrouillé. Remis à sa case d'origine : pas verrouillé. | `la-boucle…`, pas 2, 3, première assertion du pas 7. |
| S2 | Séance réservée déplacée, forme (b), cible libre (A) : refus immédiat sans confirmation, réservation retirée, séance à sa nouvelle case après régénération. | `la-boucle…`, pas 1, 4, 5, seconde assertion du pas 7. |
| S3 | Même promesse sur un plan de période. | `un-verrou-de-periode…:9`, deux pas ajoutés. |
| S4 | Une séance déplacée prend un créneau réservable ; la remettre le rend. | `la-boucle…`, assertions de budget des pas 2 et 3. |
| S20 | Les phrases de B portent « déplacé(e) » ; la troisième sorte est un créneau tenu : elle se classe, compte dans la présence et dans les cases tenues du groupe comme un verrou ; un membre de groupe déplacé à part est compté en plus ; l'option de groupe compte aussi les séances déplacées. | A4 et A5 rouverts ; `groupReservation.test.ts` (un cas) ; A2 (k). |

**Tests sous Behat de la PR D, nommés :**

- **A1, logique à nombreux cas.** Neuf : `backend/tests/Integration/Service/ManualMoveMemoryTest.php`. A→B marque avec origine A ; B→C garde A ; C→A efface. Échange : deux côtés libres marqués ; ré-échange efface. Séance verrouillée déplacée (groupe compris) : non marquée. Posée : marquée sans origine ; avec `undo` : non marquée. Verrouiller ou déverrouiller efface. Essai : n'écrit rien. Copie de plan frère : recopie. Import : remet à zéro. Balayage : ordre `id ASC` ; un second appel verrouille une séance marquée depuis le premier.
- **A2, non-régression d'axes (planning lifecycle, generation pipeline, tenant isolation), en gate.** Neuf : `backend/tests/Integration/Api/MovedSlotsLockAtGenerationTest.php`, `#[Group('phase1')]`, ajouté au step « Blocking suite » et à `blocking-tests.md`.
  - (a) `/regenerate` verrouille les séances marquées de la version chargée même si on en regarde une autre ; trace ; mémoire effacée.
  - (b) `/generate` d'une période verrouille celles de la version chargée du plan.
  - (c) `/fill` : la séance déplacée sort d'origine MANUAL avec sa trace.
  - (d) Refus 403/404/409 (autre club, Membre, version en vigueur, non chargée, génération en cours) : rien verrouillé.
  - (e) Refus 422 du Récap : le verrou reste.
  - (f) Séance marquée sur la case d'une réservation du même plan : origine RESERVATION, une épingle. Réservation d'une autre saison ou d'un autre plan : origine MANUAL.
  - (g) Cache chaud avant le lancement : le payload porte l'épingle.
  - (h) Version PENDING → les quatre routes répondent 409 `generation_in_progress`.
  - (i) Q2 (déplacement) sur une séance HARD/RESERVATION : sans confirmation 409, `releasedReservations: 1`, rien écrit, moteur non appelé ; avec : réservation retirée, origine MANUAL, trace ; même jour autre heure : accepté ; l'essai rend `releasedReservations: 1` ; appel réel avec `evictSlotId` et le drapeau en un appel ; autre version intacte ; case bloc-complète : les réservations des équipes non déplacées restent.
  - (j) Déplacer une séance de période ne retire jamais la réservation de saison de la même case, et inversement.
  - (k) Un seul membre d'une séance de groupe née d'une génération, équipe à 1 séance, déplacé seul puis « Régénérer » : T1 verrouille, le Récap refuse 422 « 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée ou déplacée) », le verrou reste. Si `move` refuse ce déplacement en amont, `tank` le dit et le cas se pose en base.
- **A3 [D].** `frontend/src/features/planning/lib/useRetouchGestures.test.tsx`, quatre cas : le refus `reservation_release_unconfirmed` ouvre T1 avec le nombre reçu, « Annuler » n'envoie rien, « Déplacer » renvoie avec le drapeau ; cible occupée, l'essai à `releasedReservations: 1` affiche le paragraphe, un seul appel ; « Annuler le dernier geste » d'un « Prendre sa place » envoie `undo: true` ; un refus 422 de « Régénérer » relit les séances. `SlotReservationModal.test.tsx` : un cas, détail « déplacé manuellement ». `groupReservation.test.ts` : un cas, `manualMoveCount` compté.
- **Comptage complété** : la troisième sorte s'ajoute aux cas de `GenerationRefusesARecapBlockerTest` (comptage des séances verrouillées), phrases « verrouillée ou déplacée » ; la famille 1 (réservations seules) reste inchangée.

**Preuve = captures (`switch`), PR D, sans test** : T1 (1 et N), dialogue d'éviction avec paragraphe, toast T2, aide T3, fiche après régénération ; sélecteur avec « déplacé manuellement ».

## 9. Risques

1. **Cache périmé au lancement** [D] : gardé par A2 (g).
5. **File bloquée** [D] : une version PENDING orpheline bloque les déplacements jusqu'au chien de garde.
6. **Fenêtre T1-T2** [D] : second passage non testé.
7. **Refus 422 jamais annoncé par le Récap** et **verrou qui l'emporte sur une règle ajoutée depuis** (C).
8. **Saison, créneau supprimé sous une séance déplacée** : aucun refus (C).
9. **Décor de S1/S2** : l'ordre des pas n'a pas été joué.
10. **Phrase du guichet égale à celle du Récap** [D] : tenue parce que le texte ne sépare pas verrouillée de déplacée.
20. **Marge Behat du lot** : la somme des enveloppes est de l'ordre de la marge ; après B et D, seul le plafond sur ligne datée reste (Q-B1).
21. **Deux PR** : la famille 2 du Récap change entre B et D ; A4 et A5 sont rouverts.

## 10. Non vérifié

- Rien n'a été exécuté. Aucun geste n'a été joué à l'écran.
- **La commande Behat à chemin de feature ou de scénario** sur la stack d'épreuve : non essayée.
- Le refus effectif, par le moteur, d'un déplacement jugé contre sa propre réservation.
- L'infaisabilité du décor par le seul créneau libre (constat 17).
- Le texte du diagnostic « la réservation gagne » (parcours 14).
- Acceptation par `ReservationStateProcessor` d'une réservation d'équipe sur la case où elle siège, socle rouvert.
- Garde du retrait par l'API ; couverture de `MemberWriteSweepTest` pour les routes de retouche.
- **Si `move` accepte de déplacer seul un membre d'une séance de groupe née d'une génération** (A2 (k)).
- Si la suppression de l'entrée de période emporte les réservations du plan, et celle du gymnase ses créneaux.
- Si `live_context_schedule_id` du seed pointe la dernière version COMPLETED.
- La durée des pas Behat ajoutés ; les temps des suites depuis les merges de 1a, PR 6 et 1b.

## 11. Détails tranchés par moi (contestables)

1. Mémoire en deux colonnes sur la séance, non exposée par l'API.
2. Deux transactions par guichet, second passage non testé.
3. Garde côté geste sous `lockPlanScope`, version chargée revérifiée.
4. `removeOnly` vit dans `ReservationRemoval`.
5. Une seule formulation de T1 pour les formes (a) et (b).
6. Si le nombre de réservations relu diffère du nombre confirmé, le serveur retire ce qu'il trouve et le toast dit le nombre réel.
7. L'écran relit les séances après un refus 422.
14. [D] Le Récap dit « verrouillée ou déplacée » sans les séparer ; le sélecteur les sépare.
32. **B dit « verrouillée », D dit « verrouillée ou déplacée ».**

Lignes de roadmap à ouvrir quand A sera planifié (premier numéro libre au moment de l'ouverture, selon `RoadmapIdentityTest`) :

Neuves, PR D (4 lignes) :
- « Évincer ou retirer une séance libre posée sur sa réservation laisse la réservation. »
- « Poser ou ôter un cadenas, retirer une séance ou « Charger les verrous » entre le clic « Régénérer » et le démarrage du calcul : ces gestes ne regardent que le verrou du calcul en cours. »
- « Le Récap n'annonce pas les séances déplacées qui seront verrouillées au lancement, hors dépassement du nombre de séances. » (C)
- « En saison, une épingle sur un créneau supprimé ne refuse pas la génération. » (C)

Citée, non modifiée : P4-462 (fiche tronquée quand « Déplacer » est armé).
