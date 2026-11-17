# Chantier « déplacer verrouille » : créneaux réservés à deux sortes, puis troisième sorte

**Statut : plan non commencé, suspendu en attente des réponses du fondateur, 2026-11-17.**

**Besoin** : déplacer un match ou une séance doit verrouiller le créneau, et la règle des créneaux réservés doit distinguer deux sortes (puis une troisième, « déplacé manuellement »), y compris pour les équipes en groupe.

## Pour reprendre

Relire les questions ci-dessous, trancher, puis faire une version courte du plan, relue par `smith`, validée entière par le fondateur avant `tank` / `neo`. Le corps (v12, la v13 n'existant pas) est conservé tel quel plus bas ; les passages marqués ⟦QO-1⟧, ⟦QO-2⟧, ⟦QO-3⟧ sont écrits sur les recommandations.

## Décisions du fondateur déjà prises

- 4a : période copiée née bloquée acceptée.
- 4b : l'option de réservation de groupe est GRISÉE avec sa raison.
- 4c : gymnases désactivés sortis du plan (ligne de roadmap).
- Q2 OUI : le Récap bloque quand les séances communes d'un groupe dépassent les séances déclarées d'un membre.
- Retrait des lignes de roadmap P4-586 et P4-452 dans la PR B (rien sous la tablette) ; lignes de roadmap neuves sans numéro fixe.
- Découpage : B (règle des créneaux réservés à deux sortes, sans trace de `manual_move`) puis D (déplacer verrouille, troisième sorte « déplacé manuellement »).
- ENG-60 passe en contrat 1.14 après PR2.

## Questions ouvertes (à trancher)

**QO-1, une séance simplement placée prouve-t-elle la présence d'un membre de groupe ?** Cas : U17-1 et U17-2, 1 séance chacune, 1 séance commune, cadenas lundi et jeudi : aucune case commune possible.
- A (reco) : elle ne compte que si l'équipe a encore une séance à donner.
- B : seuls les créneaux tenus (réservation ou cadenas) prouvent la présence (règle simple, mais verrouiller une seule équipe sur la séance commune devient bloqué).
- C : garder la v11 (laisse passer un groupe que le moteur casse).

**QO-2, remède de Q2 quand l'équipe tient déjà plus de créneaux que de séances (période copiée).**
- A (reco) : la phrase du Récap dit l'ordre « déverrouillez d'abord N de ses séances, puis retirez-lui 1 séance de groupe… » avec N recalculé (`smith` : sous-comptait quand un verrou à part existe ; N = I + max(0, G − S)).
- B : assouplir la garde du crayon (`SharedTrainingBlockStateProcessor.php:243-251`).
- C : garder la phrase validée et le refus.

**QO-3, un groupe ne reçoit jamais plus de cases « de groupe » que de séances communes ; les créneaux en plus comptent comme individuels.** Oui (reco) / non.

Contestables sans question : grisé appliqué aussi aux trois raisons existantes ; retrait de « sortez-en l'équipe » ; ordre d'attribution d'une case à un groupe (limite acceptée : l'attribution « premier par identifiant » peut bloquer à tort un cas que le moteur sait faire).

## Ce que la relecture adverse a trouvé en dernier

Relecture de la v12 : le fond tient. À corriger en v13 :
- la phrase QO-2 sous-compte avec un verrou à part ;
- la variante « période reprise après la baisse » est incomplète ;
- attribution à un seul groupe ;
- compte des cases tenues par des verrous à définir après dédoublonnage ;
- garde Σ ≤ S face aux membres en pause ;
- décor de S32 ;
- règle de numérotation de la roadmap (`RoadmapIdentityTest` : successeur du plus grand numéro jamais attribué, un identifiant retiré ne revient pas).

---

# Corps du plan (v12, copié tel quel)

# PLAN v12 — Lot « double contexte », chantier « déplacer verrouille »

MORPHEUS, 2026-11-17. Lecture seule : rien lancé, aucun fichier écrit, aucun test joué. Lane Full. Révision de `/home/marabou/projects/scheduler/.next/plan-deplacer-verrouille-v11-2026-11-17.md` après la relecture de `smith` (« plan à réviser », deux bloquants).

**Base lue : `origin/lot/double-contexte` 717a712ae** (PR 1a, PR 6 et PR 1b mergées ; `engine/CONTRACT_VERSION` = 1.11), par `git show`. ENG-60 (branche `lot-dc/eng60`, non rebasée) et PR 2 (aucune branche) déplaceront encore des lignes.

**Recommandation : NO-GO tant que les trois questions de la fin ne sont pas répondues par le fondateur, puis GO pour deux PR (B puis D) après une relecture de `smith` sur cette v12.** Le plan est écrit sur mes recommandations (QO-1 option A, QO-2 option A, QO-3 oui) ; chaque passage qui en dépend porte la marque ⟦QO-1⟧, ⟦QO-2⟧ ou ⟦QO-3⟧. `tank` n'écrit aucun rouge marqué avant la réponse. Backend + frontend, aucun code moteur, contrat backend↔moteur non touché.

**Statut des exemples.** Aucun geste n'a été joué à l'écran. Chaque exemple est « lu » (chemin lu dans le code, cité `fichier:ligne`) ou « non vérifié » (§11).

## Avant le plan : ce que la relecture de `smith` change

- **2a (bloquant), présence par une séance libre.** La v11 comptait « présente » une équipe par une séance non verrouillée même quand elle n'a plus de séance à donner. Corrigé ⟦QO-1⟧ : une séance libre ne prouve la présence d'un membre que s'il lui reste une séance après ses propres créneaux tenus. Le cas de `smith` (U17-1 cadenas lundi, U17-2 cadenas jeudi, 1 séance chacune) est désormais bloqué, les deux nommées.
- **2b (bloquant), remède de Q2 refusé par le serveur.** Relu : le crayon du groupe envoie un `PUT` qui rejoue la garde que la PR B élargit (constat 94). Quand l'équipe tient aussi plus de créneaux qu'elle n'a de séances, « baissez les séances communes » répond 422. Corrigé ⟦QO-2⟧ : dans ce cas la phrase dit l'ordre, « déverrouillez d'abord 1 séance, puis … ». La phrase validée par le fondateur reste mot pour mot quand l'équipe ne tient pas plus de créneaux que de séances (période générée sans reprise ; période reprise après la baisse).
- **2c, séance commune tenue par des verrous.** L'option de groupe et la garde serveur comptent désormais les cases où le groupe est tenu par des séances verrouillées (constat 97).
- **2d, équipe dans deux groupes.** Règle écrite : une case vaut une séance pour un seul des groupes d'une équipe, comme le blocage existant le dit déjà (constat 98).
- **2e, groupe de trois.** « Sortez-en l'équipe » est retiré de toutes les phrases : ce geste peut être refusé par la garde élargie. Reste « supprimez le groupe », vrai dans tous les cas où il est écrit.
- **3, « griser » (4b A, tranché).** Fait : le groupe indisponible reste dans la liste, grisé, avec sa raison en sous-ligne. `shared/components/ui/team-select.tsx` entre dans le périmètre de B (constat 96).
- **5, tests.** Pas 5 du scénario 1 corrigé ; lignes ajoutées au tableau (S29 à S34) ; `RoadmapIdentityTest` hors liste bloquante, dit ; le test du seed gagne une condition pour Q2 ; temps Behat : décision prise (Q-B1), dite.
- **6, mineurs.** P4-601 : seconde plage corrigée ; roadmap de B réduite de 21 à 9 lignes neuves ; tête du plan : les questions sont posées comme questions.
- **Tranché par le fondateur, plus aucune question** : 4a ; 4b (A, grisé) ; 4c ; Q2 (le Récap bloque) ; retrait de P4-586 et P4-452 dans la PR B ; numéros de roadmap libres (« premier numéro libre au merge ») ; « rien sous la tablette » (aucun travail ni défaut sous la tablette) ; temps Behat (Q-B1 : on saute le repli sur la PR 1a ; reste S7 en PHPUnit, puis le plafond sur ligne datée).
- **Trouvé en relisant, non demandé** : l'exemple « deux cases qui réunissent le groupe : Récap vert » de la v11 était faux. Deux membres épinglés ensemble sur 2 cases pour 1 séance commune sont déjà refusés par le blocage existant « réservé sur 2 cases » (constat 92 corrigé). Parcours 22 et A4 (v) réécrits.
- **Aucune PR ouverte** à la lecture de la v11 (`gh pr list` : vide) ; non relu en v12.

## Découpage tranché par le fondateur

- **PR B, la première** : la règle des créneaux réservés à deux sortes (réservations + séances verrouillées), écran des contraintes + Récap, l'alignement du budget sur les membres actifs, l'option de groupe grisée quand un membre est au plafond par ses verrous ou quand la séance commune est tenue par des verrous, le blocage « séances communes au-dessus des séances d'un membre ». Sans migration. **Aucune trace de `manual_move`, de `manualMoveCount` ni du mot « déplacé(e) ».** Sa documentation porte le retrait de P4-586 et P4-452.
- **PR D, après le merge de B** : déplacer. Mémoire, verrou au lancement, séance réservée déplacée, troisième sorte « déplacé manuellement », et les mots « déplacé(e) » ajoutés aux phrases de B.
- Chaque section porte [B] ou [D]. Rien n'atteint les clubs avant la PR finale lot→main.

## À lire en premier

- **Décision du fondateur (2026-11-17, choix 1) : sa règle de comptage s'applique partout, sans exception.** Le groupe est une séance de l'équipe. Séances comptées = séances de groupe + créneaux réservés ou verrouillés hors groupe. Plus de séances comptées que de séances déclarées : le Récap refuse.
- **Son principe.** Le moteur ne décide rien à la place du gestionnaire : pas de greffe silencieuse d'un groupe sur un créneau verrouillé pour une seule équipe.
- **Le cas qui change.** U11-1 a 1 séance, en groupe avec U11-2, libre au jeudi. Je verrouille U11-1 seule le mercredi : « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » Génération refusée.
- **Un verrou n'est « de groupe »** que si la séance est marquée comme telle ou si sa case réunit le groupe, dans la limite des séances communes du groupe ⟦QO-3⟧, et une case ne vaut que pour un seul des groupes d'une équipe.
- **Une séance libre ne prouve la présence d'un membre que s'il lui reste une séance à donner** ⟦QO-1⟧.
- **Seuls les membres actifs de la période comptent.** Un groupe à moins de 2 membres actifs n'existe pas.
- **C'est un refus de produit, pas une certitude d'échec** (constat 64).
- **Ce que la règle bloque de neuf, y compris sur des données vertes aujourd'hui** (§3.7) : un membre de groupe verrouillé à part ; un membre de groupe avec des réservations à son seul nom ; une période copiée après une réduction (accepté) ; une période copiée d'une saison dont un groupe a été séparé par le calcul (4a, accepté) ; un groupe dont les séances communes dépassent les séances d'un membre (Q2, décidé) ; deux membres verrouillés chacun sur une case différente alors que chacun n'a plus de séance à donner (2a).
- Constats 1 à 15, 18, 20 à 24, 28, 29, 33 repris sans relecture ; relus ou corrigés pour cette v12 : 54, 59, 72, 73, 79, 80, 81, 89, 90, 91, 92, 93 ; 94 à 98 neufs.

## 1. Besoin reformulé

Les déplacements à la main restent libres et ne verrouillent rien sur le moment. Chaque séance déplacée est mémorisée (`moved_manually`, `moved_from`). Au lancement d'une génération (« Régénérer » saison, génération d'une période, « Combler automatiquement »), ces séances deviennent des verrous ordinaires (HARD, origine MANUAL, cause `manual_move`). Pas de case à cocher.

Décisions du fondateur, non rouvertes :
- **Verrou différé au lancement.**
- **Q1** : si la génération est refusée, les verrous posés restent.
- **Q2 (déplacement)** : déplacer un créneau réservé ne modifie que la version chargée. Seule la `Reservation` de l'équipe déplacée est retirée, avec confirmation et trace.
- **Q3** : l'annulation d'un « Prendre sa place » laisse l'équipe évincée libre.
- **A** : case libre, le serveur refuse d'abord (« ce déplacement retire 1 réservation »), sans calcul ; confirmation ; le calcul tourne une fois. Case occupée, la vérification existante rapporte aussi la réservation : un message, une confirmation.
- **C** : une séance déplacée devenue verrou peut faire refuser « Régénérer » pour une cause que le Récap n'avait pas montrée. Accepté.
- **B, règle unique, version chargée seule.** Les **créneaux réservés** d'une équipe sont : (1) ses réservations, (2) ses séances verrouillées (HARD d'origine autre que RESERVATION), (3) ses séances déplacées à la main. La PR B livre (1) et (2) ; la PR D ajoute (3). Chaque créneau compte une fois ; la séance HARD d'origine RESERVATION est la réservation elle-même. **Deux garde-fous seulement** : l'écran des contraintes et le Récap (422 `blocker` avant le moteur, trois guichets). Pas de refus du cadenas, pas de régénération obligatoire, pas de visibilité de ce qu'une réservation remplace (roadmap, §12).
- **Groupes : la règle de comptage s'applique partout (choix 1, 2026-11-17).** Séances de groupe + créneaux réservés ou verrouillés hors groupe ne dépassent pas les séances déclarées ; sinon le Récap refuse, comme l'écran.
- **Séances communes au-dessus des séances d'un membre (2026-11-17) : le Récap bloque.** Phrase validée (cas sans créneau tenu en trop) : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. »
- **Membres actifs seulement.** Un membre en pause ne compte pas ; un groupe à moins de 2 membres actifs n'existe pas.
- **Verrous issus d'une copie, option (X)** : ils comptent, libellé vrai « verrouillé ».
- **Période copiée née bloquée après une réduction : acceptée.** Les deux membres sont nommés ; le Récap dit le remède.
- **4a : période copiée d'une saison dont un groupe a été séparé par le calcul : acceptée, même traitement.** Rien n'est créé à la copie : ni lien de groupe, ni exception, ni déverrouillage.
- **4b (« 4b A ») : l'option de réservation de groupe compte aussi les verrous ; elle est grisée dans la liste, avec sa raison.**
- **4c : les gymnases désactivés sortent du plan.** Le budget garde son compte d'aujourd'hui ; P4-601 est complétée.
- **Roadmap : P4-586 et P4-452 sont retirées** ; **rien sous la tablette** : aucun travail ni défaut sous la largeur de la tablette n'est ouvert ni traité.
- **Temps Behat (Q-B1)** : le repli sur la PR 1a est sauté ; S7 de la PR 2 naît en PHPUnit ; ensuite seulement le plafond remonte, sur ligne datée.
- **Pas de mesure en production.** **Deux PR, B d'abord.**

Définitions :
- **Case d'origine** : la case de la séance à son premier déplacement depuis la naissance de la version. A→B→C : l'origine reste A ; retour en A = mémoire effacée.
- **Séance posée** (« Placer », « Remettre ») : pas d'origine, toujours « déplacée ».
- **Séance réservée**, deux formes : (a) HARD d'origine RESERVATION ; (b) NONE posée sur une réservation créée après la génération.
- **Créneau réservé** (ou **créneau tenu**) : un couple (équipe, case), case = gymnase + jour + heure `H:i`. Priorité d'étiquette réservation > verrou > déplacement.
- **Séance verrouillée** : toute séance HARD de la version chargée dont l'origine n'est pas RESERVATION (cadenas, copie, verrou hérité, origine inconnue ; constats 35 à 37).
- **Membre actif** d'un groupe : équipe sans `TeamPeriodOverride` à `isActive = false` pour le plan ; au socle, tous les membres (constats 49, 68).
- **Groupe effectif** : groupe de la portée qui garde au moins 2 membres actifs. C'est le groupe que le moteur reçoit.
- **Séances déclarées** S : nombre de séances par semaine de l'équipe (override de période compris). **Créneaux tenus** H : nombre de ses créneaux réservés, de groupe ou non.
- **Présence tenue** sur une case : l'équipe y a un créneau tenu. **Présence libre** ⟦QO-1⟧ : l'équipe y a une séance non verrouillée de la version chargée **et** H < S (il lui reste une séance à donner).
- **Groupe réuni sur une case** : tous les membres actifs d'un groupe effectif y sont présents (présence tenue ou libre). **Groupe tenu sur une case** : tous y ont une présence tenue.
- **Attribution d'une case à un groupe** (2d) : une case vaut une séance pour un seul des groupes d'une même équipe. Les cases sont attribuées dans cet ordre : séances marquées `sharedTrainingBlockId` ; cases où les réservations sont exactement les membres (règle d'aujourd'hui) ; cases où le groupe est tenu ; cases où il est réuni par une présence libre. À rang égal : jour, heure, gymnase. Si deux groupes qui partagent une équipe sont réunis sur la même case, le premier par identifiant l'obtient.
- **Plafond des créneaux de groupe** ⟦QO-3⟧ : un groupe ne reçoit jamais plus de cases qu'il n'a de séances communes. Les créneaux sur une case non attribuée sont **individuels**.
- **Séances comptées** C = séances communes des groupes effectifs de l'équipe (B) + ses créneaux individuels (I). **Créneaux de groupe tenus** G : ses créneaux sur des cases attribuées à un de ses groupes. H = I + G.
- **Supprimer le groupe suffit** quand l'équipe est membre d'un seul groupe effectif et I + G ≤ S.
- **Cases d'un groupe tenues par des verrous** (2c) : cases où le groupe est tenu et où au moins un membre l'est par une séance verrouillée.

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
16. **Le Récap ne compte que les réservations, groupe ou non.** `overBookedTeamBlockers` (`GenerationBlockersGuard.php:262-293`) : sortie immédiate sans réservation `:264-266`, compte `:268-271`, seuil `sessionsPerWeek` du payload `:278-282`. Le repli `overBookedOnEntities` (`:131-139`) lit `Team::getSessionsPerWeek()` et ignore l'override de période.
17. **Décor de `la-boucle…`.** Le pas SM1 crée une contrainte HARD `minStartTime 20:30` (`SeasonWorkLoopContext.php:98-113`). Deux pièges de sélection (`:115-164`, `:166-202`).
18. **Récap affiché.** `ValidateConstraintsController.php:159` et `:162` construisent le payload ; `:222` appelle `blockersFor`, `:223` `overBookedOnEntities` sans payload. Réservations de la portée : `:149`, `:434-456`. Règles ignorées de 1b : `:250-253`.
19. `structureHash` ne compte que les HARD (`ScheduleConstraintBuilder.php:194`).
20. `inFlightInSeason` compte toute version en vol de la saison (`ScheduleCapabilityResolver.php:96-103`).
21. « Charger » de saison restaure la photo, réservations comprises (`RegenerateFromVersionController.php:116-131`).
22. Hors garde : le cadenas (`ManualEditController.php:133`) et « Charger les verrous » (`LoadLocksController.php:101-103`).
23. Routes : `/api/schedule-slots/{id}/move`, `/api/schedules/{id}/place-slot`, `/api/schedule-slots/move-group`, `/api/schedule-slots/swap`.
24. Refus 422 à l'écran : aucun des lancements ne relit les séances (`planning/queries.ts:723-737`, `:747-760`, `PlanningPage.tsx:1141-1148`).
25. **Le budget ne lit que des réservations.** `SoloReservationBudget::budgetFor` (`:121-153`) : S `:124`, B et `inBlock` `:126-140`, `individualUsed` `:142-149`, `residual = max(0, S − B)` `:150`. Portée : `:161-188`, `:195-212`.
26. **Consommateurs du budget** : `ReservationGroupOccupancy.php:365`, `SharedTrainingBlockStateProcessor.php:229`, `TeamSoloBudgetStateProvider.php:77`, `MutualizeSlotService.php:706` (appelé `:203`), `ParcImportAnalyzer.php:666`, `:739`, `ParcImportExecutor.php:498` (compare `block > effective`, `:499`), `BcclSeederIdempotenceTest.php:1555` (constat 81).
27. **Plafond par membre d'une réservation de groupe** : `ReservationGroupOccupancy.php:197-199` compare `teamReservationCount + 1` à S ; `teamReservationCount` (`:627-636`) compte toutes les `Reservation` de l'équipe.
28. **La version chargée se lit sans écrire.** `SchedulePlanProvisioner::lockSourceScheduleIdOfPlan` (`:282-312`), `loadedVersionIdOfSeason` (`:315-323`). SQL brut sans filtre club (`:279-280`).
29. `EffectiveTeamSessions::perWeek` (`:24-36`) : l'override de période l'emporte ; la pause n'y est pas lue.
30. **Mutualiser depuis la fiche passe par le budget.** `assertSumWithinBudget` (`MutualizeSlotService.php:704-717`).
31. **Écran des contraintes.** `SlotReservationModal.tsx:231` (équipes en pause non offertes), `:234`, `:248-258`, `:267-274` (suffixe « — passez par le groupe ») ; `reservationSlots.ts:209` ; textes `wizard/texts.ts:10-11`, `:19` ; `staleTime` 30 s (`wizard/queries.ts:954-961`, clé `:956`) ; invalidation du budget `:970`.
32. Le front ne redérive pas la sur-réservation (`useStepValidation.ts:319`, `RecapStep.tsx:213`, `:451`).
33. Étiquettes de la fiche du planning (`SlotDetail.tsx:104-106`).
34. `GenerationRefusesARecapBlockerTest` est en gate (`ci.yml:899`, `blocking-tests.md:221`).
35. **La transcription verrouille tout.** `PeriodPlanTranscriber.php:208-226` : copie « à l'identique », HARD, origine RESERVATION si une réservation du plan coïncide, sinon MANUAL ; aucun `sharedTrainingBlockId` posé. Marqueur sur la version (`:67`, `:172`), lu par aucun écran. Automatique sur une fermeture vierge (`GenerateStep.tsx:239-271`), manuelle ailleurs (`wizard/texts.ts:120`, `:124`).
36. **La copie de période sœur verrouille aussi** : `PeriodPlanSiblingCopier.php:147-162`. Ni elle ni la transcription ne recopient `sharedTrainingBlockId`.
37. **Le marqueur se perd à la première régénération** (`ScheduleResultImporter.php:174-186`).
38. **`blockersFor(array $payload, array $reservations, array $constraints, bool $forPeriod)`** (`GenerationBlockersGuard.php:102`) : deux appelants, `:83` et `ValidateConstraintsController.php:222`. **`overBookedOnEntities(array $reservations, string $clubId, string $seasonId)`** (`:131`), appelé `ValidateConstraintsController.php:223`. `firstBlockerMessage` (`:49-90`) connaît club, saison, plan et sélection (`:54-73`).
39. **Période sans plan** : `ValidateConstraintsController.php:448-450` rend `[]`, aucun payload (`:155-160`), `:223` appelle `overBookedOnEntities`.
40. **`firstBlockerMessage` avale toute exception** (`GenerationBlockersGuard.php:86-90`).
41. **Décor des tests existants.** `seedGroup` (`GenerationRefusesARecapBlockerTest.php:357-412`) : deux équipes à 1 séance (`:390`), groupe du socle à 1 séance commune (`:408`, `:416`). `seedPeriod(planGroup: false)` supprime tous les groupes de la saison (`:438-446`). Deux assertions épinglent « U11 A a 2 réservations pour 1 séance par semaine » : `:266` (période, hors groupe) et `:326` (socle, équipe du groupe). `SharedTrainingBlockStateProcessorTest.php:174`, `:192`.
42. **Patrons Behat voisins.** `SmallClubFixtureTrait.php:213-245`, `:260-266`, `:373-386` ; `TrainingBlockContext.php:289-362`, `:632-653`, période transcrite sans calcul `:234-287`. Suites : `behat.dist.php`, `boucle-travail` `:68`, `bloc` `:123-125`, `verrou-periode` `:148`.
43. **Second scénario de `un-verrou-de-periode…`** (`PeriodLockSmallClubContext.php:71-114`) : toutes les séances de V1 finissent verrouillées.
44. **Suite Behat** : plafond 950 s (`test-time-ceiling.json`). Médiane 830 s sur 8 runs de la VM d'après le plan PR 2 (non remesurée par moi). Détail au constat 93.
45. **Les deux rails « mutualiser ».** `/mutualize` connaît `$anchor` et `$toMove` (`MutualizeSlotService.php:153-197`, garde `:203`). `mutualizeAtCase` refuse toute séance simple verrouillée (`:348-351`).
46. La garde d'occupation dérive « case bloc-complète » des seules réservations, par égalité d'ensembles (`ReservationGroupOccupancy.php:79-111`).
47. `sayingThePinsComeFromTheSource` (`GenerationBlockersGuard.php:176-187`) : aucune phrase du §7 ne contient « Retirez une réservation ou ajustez ».
48. **Un groupe dont les membres sont épinglés à part ne fait pas échouer la génération : le moteur le sépare et le dit.** Lu, non joué. Porte la décision 4a et le bloquant 2a.
    - Le moteur crée une décision de bloc par case candidate ; un membre sans variable ni verrou rend la case impossible (`engine/app/solver/constraints/targeting.py`, `add_shared_block_constraints` `:721`).
    - Après un INFEASIBLE et un diagnostic conclu, si des verrous existent, une passe de plus lève les règles de demande du noyau (`engine/app/main.py:447`, `_best_effort_solve` `:1068`), `shared_block:` compris (`:1065`).
    - Seul le bloc sur-épinglé est exclu (`main.py:1150`). La sonde de causalité libère les verrous et accepte de lever la règle si le problème devient faisable (`main.py:1200`).
    - Le planning sort `completed` ; le bloc sacrifié est nommé par un `rule_sacrificed`, WARNING (`engine/app/solver/result_builder/diagnostics/shared_blocks.py:215-221`, `:669-726`).
    - `frozen_occupancy` ne concerne que la vérification d'un geste (`targeting.py:860`).
49. **Pause.** Source : `TeamPeriodOverride.isActive = false` (`PeriodConstraintSelector.php:85-88`). Le payload retire l'équipe (`ScheduleConstraintBuilder.php:470-474`), la retire des groupes et abandonne un groupe tombé sous 2 membres actifs (`:1152-1185`). La transcription ne copie pas ses séances (`PeriodPlanTranscriber.php:182-186`). `SoloReservationBudget::blocksInScope` (`:161-188`) garde le membre en pause ; l'écran dit « en pause sur cette période, reste membre du groupe » (`wizard/texts.ts:312`).
50. **Réduction.** La transcription retire les dernières séances de la semaine, équipe par équipe, sans regarder les groupes (`PeriodPlanTranscriber.php:36-38`, `:163`, `:192-196`, `:258-287`), et les rend « à replacer ».
51. **Cadenas.** Une séance à la fois (`useLockControls.ts:37-50`) ; seule la mutualisation pose `sharedTrainingBlockId` (constat 10).
52. **Version chargée du plan de saison** : `season.live_context_schedule_id` s'il pointe une version COMPLETED du plan, sinon la dernière COMPLETED (`SchedulePlanProvisioner.php:289-311`). `TrainingBlockContext` insère dans la dernière COMPLETED (`:308-330`, `:632-653`) sans lire `live_context_schedule_id`. Un cadenas sur une version non chargée répond 409 `not_loaded_version` (`ManualEditController.php:158`, `:1071-1078`).
53. **Plafond de temps.** Verdict sur la VM seulement (`scripts/suite-time.py:6-7`), dernier acte du job Behat (`ci.yml:2110`) ; relèvement sur ligne datée (`suite-time.py:10-14`). Le « Lot Gate » (`ci.yml:2249-2250`) ne joue pas Behat mais joue la liste bloquante (constat 83). Une suite seule : `make -C backend behat SUITE=<nom>` (`backend/Makefile:159-176`).
54. **Gymnases désactivés.** Le budget ne les écarte pas (`SoloReservationBudget.php:195-212`). Le Récap et les guichets retirent leurs réservations (`ValidateConstraintsController.php:452-455`, `GenerationBlockersGuard.php:70-73`) ; le payload aussi (`ScheduleConstraintBuilder.php:512`), la greffe des verrous aussi (`:1114-1116`). La garde des épingles orphelines les saute (`OrphanPinGuard.php:186-197`, `:211-213`, `:220-222`). Source : `PlanVenueClosures::effectiveStateForEntry` (`PeriodConstraintSelector.php:101-102`). **La ligne P4-601 (`roadmap.md:275`) cite deux plages périmées** : `ValidateConstraintsController.php:444-447` (bonne plage `:452-455`) et `GenerationBlockersGuard.php:68-71` (bonne plage `:70-73`).
55. `assertBlockReservationAllowed` ne reçoit ni club ni saison (`ReservationGroupOccupancy.php:169-176`) ; le `SharedTrainingBlock` reçu les porte.
56. `GET /api/team_solo_budgets` refuse 422 un plan d'un autre club (`TeamSoloBudgetStateProvider.php:68-73`).
57. `GenerationRefusesARecapBlockerTest` porte `#[Group('phase1')]` sur la classe (`:57`) ; le step « Blocking suite » (`ci.yml:578`) lance `phpunit --group phase1 $FILES` (`:1372`). `SharedBlockPreSolveMirrorParityTest` joue des payloads contre le vrai moteur, listé `ci.yml:1198`.
58. Nettoyage de `TrainingBlockContext` (`:540-600`) : réservations des équipes jetables (`:548`), équipes, gymnase, entrée de période, séances insérées, pointeur reposé. Aucune ligne ne retire des créneaux de gymnase.
59. **Le bloc sur-épinglé est déjà refusé par le Récap.** `PreSolvePreventionWarnings::sharedBlockWithoutCommonServableCase` (`:1074`) lit les épingles HARD du payload (`:1223-1237`), réservations et verrous confondus, et dit « Groupe … : réservé sur N cases pour … — la génération échouera. Retirez une réservation ou ajustez les séances communes dans l'assistant. » (`:1165-1167`). Elle passe **avant** la sur-réservation (`GenerationBlockersGuard.php:114`). Pour des membres épinglés à part, elle se tait.
60. Un fichier de test seul se joue par `make -C backend phpunit F=…` (`backend/Makefile:184-185`).
61. `TeamPeriodOverride` porte `clubId`, `seasonId` et `schedulePlanId` (`Entity/TeamPeriodOverride.php:37`, `:40`, `:50`). Le sélecteur de période les lit par `schedulePlanId` seul (`PeriodConstraintSelector.php:85`).
62. **Ce que voit le gestionnaire d'un groupe séparé par le moteur.** Message repris tel quel (`DiagnosticMessageBuilder.php:63`), affiché au panneau des diagnostics (`DiagnosticsPanel.tsx:271`, `:298`) ; texte en `shared_blocks.py:718-725`.
63. **Une suite ou une feature seule sur la stack d'épreuve** : `vendor/bin/behat --suite=<nom>` dans le conteneur PHP (`docs/testing/tests-par-parcours.md:396`).
64. **Pour le moteur, une réservation et un verrou sont la même épingle** (`ScheduleConstraintBuilder.php:704-730`). Un partenaire de groupe libre reste candidat sur la case où un membre est épinglé et l'y rejoint (`engine/app/solver/model.py:207-216`). Lu, non joué.
65. **Le moteur ne lit pas les séances libres comme des contraintes** (`engine/app/solver/model.py:256`).
66. **Le sélecteur n'a pas d'état « dépassé ».** Une équipe est « à 0 » dès que `residual − individualUsed − brouillon <= 0` (`SlotReservationModal.tsx:249-258`), un seul texte (`wizard/texts.ts:11`). `GET /api/team_solo_budgets` rend déjà `effectiveSessions` et `blockSessions` (`TeamSoloBudgetResource.php:36`, `:40`).
67. **La phrase actuelle du Récap chiffre l'excédent** (`GenerationBlockersGuard.php:284-290`). La règle f compare `individualUsed + 1` au résidu (`ReservationGroupOccupancy.php:373-375`) ; son docblock (`:40-44`) dit « que le moteur rendrait INFEASIBLE », ce que le constat 64 contredit.
68. **Qui sort du roster du payload.** Saison : personne (`ScheduleConstraintBuilder.php:283`, `:1284-1295`). Période : les seules équipes en pause (`:418`, `:470-474`). Supprimer une équipe supprime ses groupes entiers (`Deletion/SharedTrainingBlockPruneStep.php:16`, `CascadePlan.php:94`).
69. **Le groupe que le moteur reçoit.** `serializeSharedBlocks` (`ScheduleConstraintBuilder.php:1152-1185`, appelée `:792`) : membres gardés s'ils sont au roster (`:1169-1171`), groupe abandonné sous 2 membres (`:1174-1176`). Saison (`:297`) et période (`:516`).
70. **Le groupe que le budget croit.** `blocksInScope` (`SoloReservationBudget.php:161-188`) lit toutes les lignes membres sans filtre (`:181-183`). Le groupe hypothétique d'une déclaration n'est pas filtré non plus (`:100`).
71. **Autres lecteurs.** `ReservationGroupOccupancy::blockMemberSetsInScope` (`:594-602`) lit tous les membres. Le sélecteur lit `inBlock` du serveur (`SlotReservationModal.tsx:270`, `:272`). L'écran refuse déjà de réserver un groupe dont un membre est en pause (`wizard/lib/groupReservation.ts:94-97`).
72. **Baisser les séances d'une équipe n'est gardé nulle part contre ses groupes.** Période : champ « Séances de {équipe} cette période » (`PeriodTeams.tsx:357`, `:209-216`), écrit par `TeamPeriodOverrideStateProcessor.php:90`, `:104` ; bornes 1 à 7 (`Dto/TeamPeriodOverrideInput.php:26`). Saison : `TeamStateProcessor.php:170` (relu : `setSessionsPerWeek` sans lecture des groupes), borne `Positive` (`Dto/TeamInput.php:37`) ; fichiers modifiés par ENG-60. La garde « total des séances communes ≤ séances de l'équipe » ne vit qu'à l'écriture du groupe (`SharedTrainingBlockStateProcessor.php:234-238`), de la mutualisation (`MutualizeSlotService.php:708`) et de l'import du parc (`ParcImportExecutor.php:499`).
73. **Le geste pour modifier ou supprimer le groupe d'une période existe.** Ligne de l'équipe → « Liens de {équipe} » (`PeriodTeams.tsx:363`) → modale (`:374-385`) → panneau ancré au plan (`TeamLinksModal.tsx:119`). Crayon (`SharedTrainingBlockPanel.tsx:215`) : équipes à cocher (`:193`), liste « séances communes » de 1 au minimum des séances effectives des membres (`:98-102`, `:280`), envoi `PUT /api/shared_training_blocks/{id}` (`:162`). Poubelle (`:218`) avec confirmation, `DELETE`. Bornes : séances communes ≥ 1 (`Dto/SharedTrainingBlockInput.php:37-40`), 2 à 10 équipes (`:30`). La fiche ouverte ne peut pas décocher sa propre équipe (`:188`, `:193`). **Le `DELETE` ne passe par aucune garde de budget** ; en période il retire aussi les réservations de groupe et, par cascade, les séances liées (`SharedTrainingBlockStateProcessor.php:137-156`). En saison, la même modale s'ouvre depuis l'étape Équipes (`TeamsStep.tsx:1038`).
74. **Naissance d'un plan de période.** Une fermeture reçoit une copie des groupes du socle (`SchedulePlanProvisioner.php:1238-1242`, `copySocleSharedBlocks` `:1329-1371`). Des vacances n'en copient aucun (`:1239-1240`).
75. **Le Récap ne nomme que les équipes du payload** (`GenerationBlockersGuard.php:274`). Sans payload, il lit toutes les équipes du club (`:133-136`).
76. Routes des gestes de sortie : cadenas posé ou ôté `POST /api/schedule-slots/{id}/manual-edit/lock` (`ManualEditController.php:92-140` : aucune lecture des groupes, refus seulement si une génération tourne), poser une séance `POST /api/schedules/{id}/place-slot` (`:294`).
77. **La reprise automatique d'une fermeture vierge.** Effet déclenché à l'arrivée sur l'étape quand le plan n'a aucune version (`GenerateStep.tsx:246-271`) ; un 409 « déjà versionné » est bénin (`:260-263`).
78. **Un groupe séparé en saison naît séparé et verrouillé dans la copie (4a).** Enchaînement lu (constats 48, 35, 74), non joué.
79. **L'option de groupe du sélecteur ne compte que des réservations.** `offerableGroups` : `counts = teamReservationCount(reservations)` (`groupReservation.ts:88`) ; séances communes déjà posées par cases réservées complètes (`:99`, `completedGroupCaseCount` `:56-66`, égalité d'ensembles) ; membre au plafond si `counts ≥ effectiveSessions` (`:107-111`), raison `:113`. Appelé par `SlotReservationModal.tsx:296` avec le brouillon. Le module se déclare garde-fou d'ergonomie, pas miroir (`:9-21`).
80. **Aujourd'hui un groupe non posable sort de la liste et est nommé dessous.** `SlotReservationModal.tsx:298` ne garde que les groupes posables ; `:741-749` écrit « {groupe} — {raison} · indisponible » (`wizard/texts.ts:46`), relié par `aria-describedby` (`:727`, identifiant `:134`). Le sélecteur ne s'affiche que s'il reste une équipe ou un groupe **posable** (`:724`) : sinon « Aucune équipe disponible » et les raisons ne sont pas montrées.
81. **Le seed est gardé par un test qui lit le budget.** `BcclSeederIdempotenceTest::assertSeededInvariantsHoldAcrossEveryScope` (`:1523`, appelé `:98`) : pour le socle et chaque plan non SEASON, `individualUsed > residual` est une violation (`:1553-1568`) ; docblock « à RAPPORTER, pas à corriger » (`:1519-1520`). **Il ne teste pas `block > effective`** (Q2). Groupe `integration` (`:60`), hors liste bloquante. Les groupes du seed : socle à 1 séance commune (`BcclSeeder.php:1713`), plans à `$group['k']` (`:2206`), incident à 1 (`:2653`). Si une équipe du seed a moins de séances que ses séances communes : non vérifié.
82. **Gymnase désactivé : trois lectures qui ne s'accordent pas, rien de joué.** Geste : `PeriodVenues.tsx:379`. Le code dit que la désactivation conserve grille, réservations et verrous et que le calcul ne les voit pas (`OrphanPinGuard.php:186-197`) ; son commentaire dit que le Récap annonce le surplus (`:193-195`), alors que le Récap le retire de son périmètre (constat 54). L'écran de réservation dit « Retirez les réservations ci-dessus pour débloquer la génération » (`SlotReservationModal.tsx:684`).
83. **Le « Lot Gate » joue la liste bloquante par alias** (`ci.yml:2404`, ancre `:575-578`).
84. **Le contrat OpenAPI figé porte le budget.** `specs/courantes/openapi-snapshot.json` contient `TeamSoloBudget` ; gardé par `OpenApiSnapshotMatchesTheLiveContractTest`, `OpenApiSnapshotMetaMatchesSnapshotTest` et `TsFieldsMatchOpenApiSchemaTest` (`backend/tests/CrossStack/`).
85. **La réservation de groupe ne lit aucune pause côté serveur.** Seule garde : `assertBlockReservationAllowed` (`GroupReservationController.php:236`).
86. **Texte actuel du plafond par membre** : « L'équipe « %s » atteint déjà son nombre de séances par semaine (%d) : cette séance mutualisée le dépasserait. Réduisez ses autres réservations, ou augmentez son nombre de séances. » (`ReservationGroupOccupancy.php:199`).
87. **`la-boucle…` après 1a et 1b.** Scénario `:12` ; « Étant donné » `:13` ; six pas de 1a `:14-19` ; trois pas existants `:20-22` ; deux pas de 1b `:23-24` ; « Quand je régénère » `:25` ; « Alors » `:26` ; quatre pas de 1b `:27-30`. `un-verrou-de-periode…` : scénarios `:9` et `:17`.
88. **Fichiers partagés avec les autres PR du lot.**
    - **ENG-60** (non mergée, non rebasée) — avec B : `.github/workflows/ci.yml`, `docs/testing/blocking-tests.md`, `specs/courantes/openapi-snapshot.json` et son `.meta.md`. Avec D en plus : `la-boucle-de-travail-du-gestionnaire.feature`, `tests/Behat/SeasonWorkLoopContext.php`, `MoveSlotService.php`. Lus ici et modifiés par ENG-60 : `ScheduleConstraintBuilder.php`, `TeamStateProcessor.php`, `Dto/TeamInput.php`, `CascadePlan.php`, `SharedBlockPreSolveMirrorParityTest.php`. ENG-60 porte une migration : celle de D prend un horodatage postérieur. Liste confirmée par `smith`.
    - **PR 2** (aucune branche ; trois PR 2a, 2b, 2c d'après son plan) — avec B et D : `ci.yml` et `blocking-tests.md` (2c), `behat.dist.php` (2b), le snapshot OpenAPI, `docs/glossary.md` (2a), `specs/courantes/etat-des-lieux.md`, `specs/evolution/roadmap.md`, `docs/testing/test-coverage-map.md`, `backend/docs/error-copy.md` ; avec D : `engine/docs/constraint-vocabulary.md` (2c).
89. **Roadmap de la branche de lot.** Titre `# Roadmap (180) — ce qui reste à faire`. `RoadmapIdentityTest` (`backend/tests/Unit/Documentation/`, groupe `phase1`) garde l'unicité des identifiants, le compteur du titre et qu'un identifiant retiré ne revient pas. **Il n'est pas dans la liste bloquante** : il tourne dans `backend-tests`, PR vers `main` seulement (`ci.yml:1404-1427`), et dans `make -C backend tests-complete`. P4-586 `:117`, P4-452 `:190` ; aucun autre fichier ne les cite. Plus grand numéro pris : P4-601.
90. **Le geste de Q2, lu ligne à ligne, non joué.** Champ « Séances de {équipe} cette période », bornes 1 à 7 (`PeriodTeams.tsx:348-357`, `:209-216`) ; le serveur écrit sans lire les groupes (`TeamPeriodOverrideStateProcessor.php:90`, `:104`). Sortie : « Liens de {équipe} » (`:363`) → crayon (`SharedTrainingBlockPanel.tsx:215`) → la liste « séances communes » est bornée au minimum des séances effectives (`:100`, `:102`) → enregistrer (`:162`). **Ce que le serveur fait de cet enregistrement : constat 94.**
91. **Ce que « griser » demande.** `ListboxOption` porte `sub` et `disabled` (`shared/components/ui/listbox.tsx:21-24`), rendus avec `aria-disabled` et la sous-ligne (`:608-638`). `teamOption` les passe (`team-select.tsx:73`) ; les options de groupe ne portent que `{ value, label }` (`:45`, `:83`). Détail au constat 96.
92. **Deux membres épinglés ensemble sur plus de cases que de séances communes : déjà refusé.** Le blocage du constat 59 compte les cases où tous les membres sont épinglés (`PreSolvePreventionWarnings.php:1165`) : un groupe à 1 séance commune verrouillé ensemble sur 2 cases rend « réservé sur 2 cases pour 1 séance commune ». Le plafond des créneaux de groupe ne change donc le verdict du Récap que si une des réunions passe par une présence libre ; il change toujours le compte de l'écran. Lu, non joué.
93. **Temps Behat du lot.** Registre unique prévu `.next/budget-temps-lot-double-contexte.md`, tenu par l'orchestrateur, absent du disque (`plan-pr2-double-contexte-v11-2026-11-17.md:702`). Enveloppes : B 15 s, D 25 s. **Décision du fondateur (Q-B1, `:15`, `:700`, `:887`)** : aucun scénario déjà mergé n'est retouché ; premier repli, S7 de la PR 2 naît en PHPUnit ; second repli, le plafond remonte sur ligne datée, une fois l'excès mesuré ; les enveloppes de ce chantier ne se déplacent pas sans le fondateur.
94. **Le `PUT` d'un groupe rejoue toutes les gardes de la déclaration.** `updateEntityFromInput` appelle `assertBlockValid` (`SharedTrainingBlockStateProcessor.php:115-121`), qui refuse dans cet ordre : `block > effective` (`:234-238`), puis `individualUsed > residual` pour chaque membre du groupe écrit (`:243-251`, « Cette mutualisation ferait passer… »), puis plus de cases réservées que de séances communes (`:253-255`, par `groupCompleteCaseCount`). Baisser les séances communes ou retirer un membre passe par là. **Conséquence après B** : dès qu'un membre tient un créneau individuel au-dessus de son reste, tout `PUT` sur son groupe est refusé, même s'il n'aggrave rien.
95. **Le cadenas se pose et s'ôte sans regarder les groupes** (constat 76) : le geste « déverrouillez d'abord » du §3.6 est toujours accepté sur la version chargée, hors génération en cours.
96. **`mutualisationGroups` n'a qu'un consommateur applicatif** : `SlotReservationModal.tsx:735`. Tests qui le touchent : `shared/components/ui/team-select.test.tsx:37-51`, et `SlotReservationModal.test.tsx:304-311`, qui attend aujourd'hui que le groupe **ne soit pas** une option et que « indisponible » soit écrit. Une liste d'objets dans une ressource API existe déjà (`ApiResource/CoachWishResource.php:72`).
97. **Les séances communes « déjà posées » ne comptent que des réservations, des deux côtés.** Serveur : `groupCompleteCaseCount` (`ReservationGroupOccupancy.php:315-323`), lu par la garde de réservation de groupe (`:185-187`, texte « Cette mutualisation a déjà ses %d séance(s) commune(s) placée(s)… ») et par le `PUT` (constat 94). Écran : `completedGroupCaseCount` (constat 79).
98. **Une équipe peut être membre de plusieurs groupes** (`Dto/SharedTrainingBlockInput.php:24-26`, `SharedTrainingBlockStateProcessor.php:30-32`). Le blocage existant dit déjà la règle : « Des groupes qui partagent une équipe ne siègent pas ensemble : une case vaut une séance pour l'un d'eux » (`PreSolvePreventionWarnings.php:1134`, phrase `:1149`).

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
4. Refus de contenu (422), dont le blocage du §3.6 : ils lisent l'état verrouillé.
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

### 3.6 Règle unique des créneaux réservés [B]

**Une maison** : `App\Service\TeamReservedSlots`, lecture seule. Pour une portée, elle rend les créneaux réservés de chaque équipe :
- (1) les `Reservation` de la portée qui ont une équipe → sorte `reservation` ;
- (2) les séances de la **version chargée**, HARD, origine autre que RESERVATION → sorte `lock` ;
- [D] (3) les séances de la version chargée, NONE et `moved_manually` → sorte `manual_move`. Absente de B.
- Version chargée : `loadedVersionIdOfSeason` pour le socle, `lockSourceScheduleIdOfPlan` pour une période. Sans version : réservations seules.
- **Une fois par (équipe, case).** Une séance HARD/RESERVATION n'entre jamais.
- **Requêtes filtrées par `clubId` ET `seasonId` explicites : réservations, séances, groupes et mises en pause.** La recherche de la version chargée s'appuie sur RLS seule (constat 28) : le filtre explicite fait qu'une version d'un autre club ne rend aucune ligne.
- **`planId` nul veut toujours dire « socle ».** La portée « période sans plan » ne se passe jamais à la maison : l'appelant la coupe avant l'appel (constat 39 ; A4 (j)).
- **Gymnases désactivés : la maison ne filtre rien (4c).** Le budget compte tout ce que la portée contient ; **le Récap garde son périmètre d'aujourd'hui** : réservations déjà filtrées par l'appelant, autres créneaux retenus seulement si leur gymnase est dans le payload. P4-601 complétée (§12).

**Le groupe qui compte est celui que le moteur reçoit.**
- **Le défaut.** En période, le payload retire d'un groupe ses membres en pause et abandonne le groupe sous 2 membres (constat 69). Le budget garde le membre en pause (constat 70).
- **Le comportement.** Seuls les membres actifs comptent. Un groupe à moins de 2 membres actifs n'existe pas : ni séance de groupe comptée, ni « hors groupe », ni « passez par le groupe ».
- **Une seule cause de sortie** : la pause de période (constat 68). Au socle rien ne change.
- **Une maison neuve** : `App\Service\ActiveSharedBlocks` (§4). `SoloReservationBudget::blocksInScope` devient un appel à cette maison ; `TeamReservedSlots` y lit les membres actifs. Les `TeamPeriodOverride` s'y lisent par `schedulePlanId` **avec** `clubId` et `seasonId` (constat 61).
- **Le groupe hypothétique d'une déclaration** (`SoloReservationBudget.php:100`) passe par le même filtre.
- **`ScheduleConstraintBuilder` n'est pas modifié.** Deux implémentations de la même règle, tenues par un **test de parité en gate** (A6).
- **Le Récap ne nomme jamais une équipe en pause.**
- **Les autres consommateurs du budget suivent** (constat 26). L'import du parc écrit au socle : inchangé.

**Groupe mutualisé = une équipe.** Un créneau réservé d'une équipe membre d'un groupe effectif est **de groupe**, donc jamais individuel, quand sa case est **attribuée** à un de ses groupes (§1) :
- (i) la séance porte `sharedTrainingBlockId` ;
- (ii) sa case **réunit le groupe** : tous les membres actifs y sont présents. Inclusion, pas égalité.

**Présence (2a, ⟦QO-1⟧ option A).** Un membre est présent sur une case par un créneau tenu, ou par une séance non verrouillée de la version chargée **s'il lui reste une séance à donner** (H < S, H = tous ses créneaux tenus).
- Pourquoi : le moteur ne lit pas les séances libres (constat 65). Un membre dont toutes les séances sont épinglées ailleurs ne viendra jamais sur la case, même si le planning chargé l'y montre encore.
- **Le cas de `smith`, lu, non joué.** Saison, U17-1 et U17-2 à 2 séances, groupe à 2 séances communes, placées ensemble lundi et jeudi. Crayon du groupe : séances communes à 1 (accepté, aucun verrou). Étape Équipes : chaque équipe à 1 séance (`TeamStateProcessor.php:170`, aucune garde). Cadenas sur U17-1 le lundi, sur U17-2 le jeudi. U17-2 est au lundi par une séance libre, mais H = 1 = S : elle n'y est pas présente. Le lundi de U17-1 est individuel : 2 comptées pour 1. Idem pour U17-2 le jeudi. **Les deux sont nommées.** En v11 le Récap était vert et le moteur aurait séparé le groupe (constat 48).
- **Sortie** : j'ôte un seul des deux cadenas (celui de U17-1). U17-1 a H = 0 : sa séance libre du jeudi prouve sa présence, le jeudi réunit le groupe. Récap vert ; le moteur l'y amène (constat 64). Ou : supprimer le groupe.
- **Ce qui ne change pas** : U11-1 verrouillée le mercredi, U11-2 simplement placée le mercredi et sans autre créneau tenu : groupe réuni, Récap vert.

**Attribution et équipe dans deux groupes (2d).** Une case vaut une séance pour un seul des groupes d'une équipe (constat 98).
- Exemple (lu sur les définitions, non joué) : T (2 séances) est dans G1 avec P et dans G2 avec Q, 1 séance commune chacun. T, P et Q sont verrouillées sur la même case du mercredi. G1 obtient la case (premier identifiant) : T et P y sont « de groupe ». Q n'y est pas « de groupe » : son verrou est individuel. Si Q a 1 séance : « Q a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) ». C'est vrai au moteur : Q est épinglée, T ne peut pas servir deux séances communes sur la même case.
- Le remède « supprimez le groupe » n'est jamais écrit pour une équipe membre de plusieurs groupes effectifs.

**Plafond des créneaux de groupe (point 55, ⟦QO-3⟧).** Un groupe ne reçoit jamais plus de cases qu'il n'a de séances communes ; les créneaux sur une case non attribuée sont individuels, avec leur sorte.
- Exemple (lu, non joué) : U17-1 (2 séances) verrouillée lundi et jeudi ; U17-2 (2 séances, aucun créneau tenu) y est par deux séances libres ; groupe à 1 séance commune. Lundi est la séance de groupe ; jeudi compte comme 1 séance verrouillée : 2 pour 2, vert. U17-1 passée à 1 séance : 2 pour 1, bloquée.
- Si les deux membres sont **verrouillés ensemble** sur 2 cases pour 1 séance commune, le blocage existant parle d'abord : « Groupe U17-1, U17-2 : réservé sur 2 cases pour 1 séance commune — … » (constat 92). Le plafond n'y change que le compte de l'écran.
- Pourquoi : sans lui, une équipe à 1 séance tenue par 2 créneaux « de groupe » passerait la règle, alors qu'une équipe hors groupe dans la même situation est bloquée.

Sinon le créneau est individuel et compte en plus de la séance de groupe. **Un verrou posé sur un seul membre d'un groupe réuni nulle part est individuel.**

**Le compte, partout.** C = B + I. Le Récap bloque si C > S. Quand B ≤ S c'est le calcul de l'écran, `individualUsed > S − B`.

**Pourquoi : décision de produit du fondateur (2026-11-17), pas une certitude d'échec.**
- Le moteur ne distingue pas une réservation d'un verrou (constat 64) et saurait souvent produire.
- On refuse. Pas de greffe silencieuse d'un groupe sur un créneau verrouillé pour une seule équipe.
- **Exemple.** U11-1 a 1 séance, en groupe avec U11-2, libre au jeudi. Je verrouille U11-1 seule le mercredi : 2 pour 1, bloqué.
- **Exemple.** U15-1 déclare 2 séances dont 1 en groupe ; mardi verrouillé seule, jeudi réservé à son seul nom : 3 pour 2.
- **Ce que le Récap ne fait pas.** Il ne suppose pas où le groupe se réunira.

Les dix cas :
- **Cadenas sur un seul membre, partenaire sur la case** (verrouillé, réservé, ou simplement placé avec une séance à donner) → de groupe. Pas de blocage.
- **Cadenas sur un seul membre, partenaire sur la case par une séance libre mais sans séance à donner** (2a) → individuel.
- **Cadenas sur un seul membre, partenaire ailleurs, groupe réuni nulle part** → individuel. Bloqué si l'équipe n'a pas de séance hors groupe pour le porter (U11-1 : 2 pour 1) ; non bloqué sinon (U15-1, 2 séances dont 1 en groupe : 2 pour 2).
- **Même décor, partenaire dont toutes les séances sont réservées ailleurs** → même compte ; le partenaire est compté pour lui-même.
- **Membre en pause dans une période copiée** (groupe de 2) → le groupe n'existe pas : 1 pour 1.
- **Réduction qui retire la séance de groupe d'un seul membre** → les deux membres bloqués (accepté).
- **Groupe séparé par le calcul en saison, puis copié (4a)** → les deux membres bloqués à la naissance (accepté).
- **Groupe réuni ailleurs dans la version chargée** (parcours 22) → individuel.
- **Séances communes au-dessus des séances d'un membre (Q2, décidé)** → B > S, bloqué même sans créneau réservé.
- **Groupe de trois dont un membre est absent de la case** (2e) → les deux membres présents sont individuels et nommés ; le remède écrit est « supprimez le groupe ».

**Cas « réduction », vérifié dans le code.**
- **Ce qui arrive.** L'override baisse les séances (constat 72) ; la reprise retire les dernières séances de la semaine sans regarder le groupe (constat 50). Si la séance retirée est la séance commune, le membre réduit reste verrouillé sur sa séance solo et son partenaire seul sur l'ancienne case commune. Si c'est la séance solo, le groupe reste réuni : rien n'est bloqué.
- **Ce que dit le Récap** (U15-1 : lundi seule, mercredi en groupe ; passe à 1 séance) : « U15-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » et la même phrase pour U15-2.
- **Les gestes de sortie, tous existants** :
  - supprimer le groupe de la période : « Liens » → poubelle (constat 73). Un geste, les deux passent à 1 pour 1 ;
  - déverrouiller la séance de chaque membre nommé (constat 76), puis régénérer ;
  - remonter U15-1 à 2 séances **et** reposer sa séance du mercredi (« à replacer ») : U15-1 a alors une séance à donner, sa séance libre du mercredi réunit le groupe.
- **Baisser les séances communes** : possible à partir de 2 (constat 73), et seulement si aucun membre ne tient de créneau individuel en trop (constat 94).
- **Sortir une équipe du groupe** : n'est proposé par aucune phrase (2e, point 51).
- **Aucune garde neuve à l'écriture** : baisser les séances d'une équipe reste accepté.

**Cas « groupe séparé en saison » (4a), lu, non joué.**
- **Ce qui arrive.** En saison, le calcul a séparé le groupe et l'a dit (panneau des diagnostics, constat 62) : U13-1 mardi, U13-2 jeudi. Une fermeture reçoit le groupe (constat 74) puis la copie (constat 77) : U13-1 verrouillée mardi, U13-2 verrouillée jeudi.
- **Ce que dit le Récap de la période** : la phrase ci-dessus pour U13-1 et pour U13-2.
- **Rien n'est créé à la copie** : la transcription n'est pas modifiée.
- **Sorties** : les mêmes que pour la réduction. Déverrouiller puis régénérer peut redonner un groupe séparé : non vérifié.

**Cas « séances communes au-dessus des séances d'un membre » (Q2, décidé), lu, non joué.**
- **Ce qui arrive.** U17-1 et U17-2, groupe à 2 séances communes. Je passe U17-1 à 1 séance (constat 90). Rien ne refuse l'écriture.
- **Quand U17-1 ne tient pas plus de créneaux que de séances** (période générée sans reprise ; période reprise **après** la baisse, où la reprise n'a copié qu'une séance de U17-1) : **phrase validée, mot pour mot.** « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. » Sortie : « Liens de U17-1 » → crayon → « séances communes » : 1 → enregistrer. Vérifié sur la garde (constat 94) : dans la période reprise après la baisse, U17-1 tient le lundi (de groupe), U17-2 tient lundi (de groupe) et jeudi (individuel, 1 pour 1 de reste) : accepté. Récap vert.
- **Quand U17-1 tient plus de créneaux que de séances** (2b : période reprise **avant** la baisse, les 2 séances communes sont arrivées verrouillées ; ou saison avec deux cadenas) ⟦QO-2⟧ option A : **« baissez les séances communes » seul est refusé 422** (après la baisse, le second verrou devient individuel, 1 pour 0 de reste). La phrase dit donc l'ordre : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. »
  - Geste : au planning de la période, j'ôte le cadenas d'une des deux séances de U17-1 (constat 95). Puis crayon → séances communes : 1 → enregistrer : accepté (U17-1 : 1 créneau, de groupe ; U17-2 : lundi de groupe, jeudi individuel pour 1 de reste). Récap vert.
  - Dans le mauvais ordre : le crayon répond le refus du §7 « … Retirez une réservation ou déverrouillez une séance avant de mutualiser. » ; rien n'est écrit.
  - « Supprimez le groupe » après le cadenas ôté : 1 verrou pour 1 séance, vert. Sans le cadenas ôté : le `DELETE` passe, puis « U17-1 a 2 séances comptées pour 1 déclarée (2 séances verrouillées) : déverrouillez 1 séance, ou … ».
  - Nombre à libérer d'abord : N = I + max(0, G − S).
- **Vaut au socle** : même règle si les séances de l'équipe sont baissées à l'étape Équipes (constat 72).
- **Le sélecteur de réservation ne dit rien de ce cas** : seul le Récap le porte.

**Le remède « supprimez le groupe » dit par le Récap.** La phrase l'ajoute quand ce geste suffit : l'équipe est membre d'un seul groupe effectif et I + G ≤ S. Le `DELETE` n'a aucune garde (constat 73). Dans les autres cas elle ne le dit pas. **« Sortez-en l'équipe » n'est plus écrit nulle part (2e).**
- Exemple où elle le dit : U11-1, 1 séance, verrouillée seule (I = 1, G = 0).
- Exemple où elle ne le dit pas : U11 A, 1 séance, verrouillée le mercredi avec son groupe et le lundi seule (I = 1, G = 1).
- **Groupe de trois (2e), lu, non joué.** U11-1, U11-2, U11-3, 1 séance chacune, 1 séance commune. U11-1 et U11-2 verrouillées ensemble le mercredi ; U11-3 verrouillée le jeudi (réactivée après la copie et posée à part). Les trois sont nommées avec « déverrouillez 1 séance, supprimez le groupe, ou … ». Supprimer le groupe : trois fois 1 pour 1, vert. Sortir U11-3 du groupe débloquerait U11-1 et U11-2 (le `PUT` {U11-1, U11-2} passe) mais n'est pas proposé ; sortir U11-1 depuis la fiche de U11-2 serait refusé (constat 94). Limite écrite, point 51.

Limites assumées :
- un cadenas est « de groupe » si le partenaire est simplement placé sur la case avec une séance à donner ; si le partenaire est déplacé ou épinglé ailleurs ensuite, le cadenas redevient individuel et le Récap bloque ;
- (ii) rend aussi « de groupe » une réservation au seul nom d'une équipe si la version chargée y montre tout le groupe, dans la limite du plafond ;
- le bloc sur-épinglé reste refusé d'abord par le blocage existant (constat 59) ;
- **baisser les séances communes sous le nombre de cases où le groupe est tenu par des verrous n'est pas refusé par le crayon** (la garde `:253-255` ne compte que des réservations) : le Récap le refuse ensuite par « réservé sur N cases ». P4-459 complétée ;
- `ReservationGroupOccupancy` garde sa propre lecture des membres, sans filtre de pause (constat 71). Roadmap ;
- le docblock de `ReservationGroupOccupancy.php:40-44` n'est pas réécrit ;
- l'état « dépassé » du sélecteur ne chiffre pas un excédent dû aux seules séances communes (Q2) ;
- une équipe membre de plusieurs groupes effectifs ne reçoit jamais le remède « supprimez le groupe » ;
- le Récap ne propose jamais de sortir un membre d'un groupe.

**Créneaux libres** (réservation sans équipe) : jamais comptés.

**a) Écran des contraintes.**
- `SoloReservationBudget::budgetFor` : `individualUsed` = créneaux réservés individuels ; B et `inBlock` se lisent sur les groupes effectifs. `residual` reste `max(0, S − B)`. La formule de l'écran ne change pas (`reservationSlots.ts:209`).
- `SoloBudget` et `TeamSoloBudgetResource` gagnent le détail par sorte, `groupLockedCount` et `groupLockHeld` (§4).
- **Équipe dont le seul partenaire est en pause** : `inBlock` faux, « reste 1 créneau » sans « hors groupe ».
- **État « dépassé » neuf au sélecteur** : quand `individualUsed > residual`, l'équipe reste listée, désactivée, « N créneau(x) de trop » (§7).
- **Option de réservation de groupe (4b, grisée).** `offerableGroups` rend toujours `offerable` et `blocked`. `SlotReservationModal` passe **les deux** à `TeamSelect` : un groupe bloqué est une option de la section « Entraînements mutualisés », grisée, non sélectionnable, sa raison en sous-ligne. La liste écrite sous le sélecteur (`:741-749`), son `aria-describedby` (`:727`, `:134`) et le mot « · indisponible » (`wizard/texts.ts:46`) disparaissent : la sous-ligne d'une option est déjà reliée à l'option (`listbox.tsx:597`). Le sélecteur s'affiche dès qu'il a une option, grisée comprise (`:724`).
  - Les quatre raisons passent par ce rendu : membre en pause, séances communes posées, membre au plafond, et la raison neuve ci-dessous.
  - **Membre au plafond** (`groupReservation.ts:107-111`) : compare, pour chaque membre, `réservations du brouillon + lockedCount + groupLockedCount` à `effectiveSessions`.
  - **Séance commune tenue par des verrous (2c).** Le contrôle « séances communes déjà posées » (`:99`) compare `completedGroupCaseCount(brouillon) + cases du groupe tenues par des verrous` aux séances communes. Le second terme vient du serveur (`groupLockHeld`, lu sur la ligne de budget d'un membre). Exemple (lu, non joué) : U15-1 et U15-2 à 2 séances, 1 séance commune, verrouillées ensemble le mercredi. J'ouvre le jeudi : le groupe est grisé, « Sa séance commune est verrouillée au planning : déverrouillez-la pour réserver le groupe ailleurs. » En v11 il était offert, accepté par le serveur, puis le Récap bloquait « réservé sur 2 cases ».
  - Ordre des raisons : pause, séances communes posées ou tenues, membre au plafond.
  - Le serveur reste juge (ci-dessous).
  - Limite : les comptes de verrous viennent du serveur, pas du brouillon.
- **Garde de réservation individuelle (règle f)** : compare `individualUsed + 1` au résidu, sans dédoublonner la case visée (§12, 21).
- **Garde de réservation de groupe, séances communes déjà tenues (2c)** (`ReservationGroupOccupancy.php:185-187`) : `groupCompleteCaseCount + cases tenues par des verrous + 1 > séances communes` → 422 (§7). Sans verrou : résultat et texte d'aujourd'hui.
- **Plafond par membre d'une réservation de groupe** (constat 27) : tous les créneaux réservés du membre, une fois par case, plus 1, contre S. Club et saison viennent de `$block` (constat 55).
- **Déclaration et modification de groupe** (`SharedTrainingBlockStateProcessor.php:229-251`) : lit le compte élargi avec le groupe hypothétique. Vaut pour le `PUT` (constat 94).
  - Deux équipes à 1 séance verrouillées sur deux cases : **refusée**, les deux nommées (§7). Sur la même case : acceptée.
  - Exemple à 2 séances : U13-1 verrouillée mercredi avec U13-2 et lundi seule, plus 1 réservation jeudi à son seul nom ; déclarer le groupe à 1 séance commune laisse 2 créneaux individuels pour 1 de reste : refusée. Sans la réservation : acceptée.
- **Mutualiser depuis la fiche** (`MutualizeSlotService.php:203`) : `forTeamsWithBlockSubstituted` reçoit les identifiants de la séance source et des séances remplacées ; elles sortent du compte.
- **Import du parc** : hérite du compte sans changement de code.
- Les gestes du planning qui changent le compte invalident la requête du budget.
- Une panne de `TeamReservedSlots` ou d'`ActiveSharedBlocks` fait échouer la requête du budget : l'écran n'offre aucune équipe.

**b) Récap.**
- **Signatures.** `blockersFor` reçoit la portée (`BlockerScope`) à la place de `bool $forPeriod` ; `overBookedOnEntities` la reçoit à la place de `string $clubId, string $seasonId`. `firstBlockerMessage` garde sa signature.
- **La sortie immédiate `:264-266` est retirée.**
- **La règle.** Le Récap bloque si B + I > S, sur les équipes du payload, avec le périmètre de réservations de l'appelant, les créneaux dont le gymnase est dans le payload, et les groupes effectifs. La présence libre se lit sur ce même périmètre.
- **Le texte reste vrai dans chaque cas** : équipe sans groupe effectif, réservations seules → phrase d'aujourd'hui, mot pour mot ; tout autre cas → « C séances comptées pour S déclarée(s) ». Créneaux individuels à libérer : `I − max(0, S − B)`. Séances de groupe en trop : `max(0, B − S)`.
- **Sans payload** : même calcul sur les équipes actives du club ; override de période lu ; aucun filtre de gymnase.
- **Période sans plan** : l'appelant ne consulte pas la règle.
- Refus 422 `blocker` avant le moteur sur les trois guichets.
- [D] Le Récap compte les séances déplacées encore NONE ; son texte est identique avant et après T1.
- **Panne** : `firstBlockerMessage` l'avale, journalise, la génération part (constat 40).
- **Comblement** : la règle sous-compte pour `/fill` (constat 11). Roadmap.

**Période, socle, versions, copies.**
- Deux mondes : un verrou du socle ne compte jamais dans une période, et inversement.
- En période, S est l'override s'il existe ; les groupes sont ceux de la période, membres actifs seulement.
- Seule la version chargée compte.
- **Période copiée (X).** Les séances copiées comptent. Pour réserver ailleurs une équipe entièrement verrouillée, on déverrouille d'abord une séance. La copie seule ne bloque ni une équipe hors groupe, ni un groupe copié réuni, ni l'équipe dont le partenaire est en pause.
- **Ce qui bloque dans une période copiée** : baisser les séances d'une équipe après la copie (équipe en groupe comprise) ; une réduction qui retire la séance commune d'un seul membre ; un groupe déjà séparé dans la version de saison (4a) ; réactiver après la copie un membre qui était en pause.
- Les deux ordres : verrouiller puis réserver → l'écran refuse ; réserver puis verrouiller, ou charger une version → seul le Récap le voit, et le sélecteur affiche « de trop ».

### 3.7 Clubs déjà en surplus à la livraison

Aucune mesure en production. Seraient bloqués à leur prochaine génération, avec la phrase du §7 :
- **Avec verrou, équipe sans groupe** : plus de créneaux réservés que de séances.
- **Avec verrou, membre d'un groupe** : un membre verrouillé à part de son groupe, sans séance hors groupe pour porter ce verrou ; ou deux membres verrouillés chacun sur une case différente sans séance à donner (2a).
- **Sans aucun verrou, et vert aujourd'hui.** U15-1 a 2 séances dont 1 en groupe et 2 réservations à son seul nom : « U15-1 a 3 séances comptées pour 2 déclarées (1 séance de groupe, 2 réservations) : retirez 1 réservation, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » Rare : la règle f refuse la seconde réservation à l'écriture.
- **Périodes déjà copiées après une réduction** ; **périodes déjà copiées d'une saison dont un groupe était séparé (4a)** : les deux membres du groupe.
- **Séances communes au-dessus des séances d'un membre (Q2)** : tout club où une équipe a été baissée sous les séances communes de son groupe.
- Le remède est dans la phrase. Aucune migration, aucun rattrapage de données.
- **Le seed n'est pas touché.** Le test du constat 81 lit le nouveau compte et gagne la condition de Q2 (§8) ; s'il rougit, on rapporte, on ne corrige pas le seed.

## 4. Contrats nouveaux

**PR B**
- **`App\Service\ReservedSlot`** (valeur) : `teamId: string`, `venueId: string`, `dayOfWeek: int`, `startTime: string` (`H:i`), `kind: 'reservation'|'lock'`, `inGroup: bool`, `blockId: ?string` (le groupe auquel la case est attribuée ; nul pour un créneau individuel).
- **`App\Service\BlockerScope`** (valeur) : `clubId: string`, `seasonId: string`, `planId: ?string`, `forPeriod: bool`. Fabriques `BlockerScope::season(string $clubId, string $seasonId): self` ; `BlockerScope::period(string $clubId, string $seasonId, ?string $planId): self`. `forPeriod` vrai et `planId` nul = période sans plan : `GenerationBlockersGuard` rend `[]` pour cette famille sans appeler `TeamReservedSlots`.
- **`App\Service\ActiveSharedBlocks`** :
  - `forScope(string $clubId, string $seasonId, ?string $planId): array` — `list<array{blockId: string, commonSessions: int, members: array<string, true>, declaredCount: int}>`, triée par `blockId` ; `members` = membres actifs ; un groupe à moins de 2 membres actifs est absent ; `$planId` nul = socle ;
  - `pausedTeamIds(string $clubId, string $seasonId, ?string $planId): array` — `array<string, true>` ; vide au socle ;
  - `keepActive(array $memberIds, array $pausedTeamIds): ?array` — statique, pure : `list<string>` triée, ou `null` sous 2.
- **`App\Service\TeamReservedSlots`** :
  - `forScope(string $clubId, string $seasonId, ?string $planId, ?array $reservations = null, array $excludedSlotIds = [], ?array $blocks = null): array` — `list<ReservedSlot>` ; `$reservations` non nul = périmètre imposé ; `$blocks` non nul = jeu de groupes imposé (groupe hypothétique) ; présence, attribution et plafond y sont appliqués ;
  - `lockHeldGroupCases(string $clubId, string $seasonId, ?string $planId): array` — `array<string, int>`, identifiant de groupe → nombre de cases où ce groupe est tenu avec au moins une séance verrouillée ; groupes sans telle case absents ;
  - `loadedVersionId(string $seasonId, ?string $planId): ?string`.
- **`SoloBudget`** : `reservedCount: int`, `lockedCount: int` (individuels) ; `individualUsed` = leur somme ; `reservedTotal: int` (H, individuels et de groupe) ; `groupLockedCount: int` (créneaux de l'équipe attribués à un groupe et tenus par une séance verrouillée) ; `groupLockHeld: array<string, int>` (pour les seuls groupes de l'équipe). `block`, `effective`, `inBlock` existent déjà.
- **`SoloReservationBudget`** : `forTeamsWithBlockSubstituted(…, array $excludedSlotIds = [])` ; `forScopeWith(string $clubId, string $seasonId, ?string $planId, array $reservations): array` — `list<SoloBudget>`.
- **`GenerationBlockersGuard`** : `blockersFor(array $payload, array $reservations, array $constraints, BlockerScope $scope): array` ; `overBookedOnEntities(array $reservations, BlockerScope $scope): array`.
- **`GET /api/team_solo_budgets`** : chaque ligne gagne `reservedCount: int`, `lockedCount: int`, `groupLockedCount: int`, `groupLockHeld: list<{blockId: string, cases: int}>` (liste vide si aucun). Les champs existants gardent leur nom ; `blockSessions` et `inBlock` ne comptent plus un groupe sans 2 membres actifs. Le contrat figé suit.
- **Type écran `TeamSoloBudget`** (`wizard/api.ts:570-583`) : `reservedCount: number`, `lockedCount: number`, `groupLockedCount: number`, `groupLockHeld: { blockId: string; cases: number }[]`, obligatoires.
- **`offerableGroups(groups, teams, reservations, slotEmptyInDraft, budgetByTeam, pausedTeamIds?)`** : signature inchangée ; lit `lockedCount`, `groupLockedCount` et `groupLockHeld` (sur la première ligne de budget trouvée parmi les membres).
- **`TeamSelectProps.mutualisationGroups?: { value: string; label: string; sub?: string; disabled?: boolean }[]`** : `sub` et `disabled` passés tels quels à l'option de `Listbox`.
- **Textes** : `WIZARD_TEXTS.reservedBreakdown(reserved: number, locked: number, group?: number)`, `allSlotsTaken`, `allOutOfGroupSlotsTaken`, `slotsOverBudget(excess: number, inGroup: boolean)`, `groupSessionLocked(name: string, n: number)`, `periodLockedHint`, `groupMemberSessionsLocked(name: string, locked: number)`, `groupCommonSessionsLockHeld(common: number, locked: number)`. `WIZARD_TEXTS.unavailableTag` est supprimé.

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
| Compte des créneaux réservés [B] | `TeamReservedSlots` (lecture) | `SoloReservationBudget.php:121`, `GenerationBlockersGuard.php:131`, `:262` | filtre club + saison ; « période sans plan » coupée par l'appelant | — |
| `individualUsed` élargi [B] | `SoloReservationBudget` | `ReservationGroupOccupancy.php:370-375`, `SharedTrainingBlockStateProcessor.php:243-251` (création **et** `PUT`, `:115-121`), `MutualizeSlotService.php:712-716`, `TeamSoloBudgetStateProvider.php:77`, `reservationSlots.ts:209`, `SlotReservationModal.tsx:256`, `ParcImportAnalyzer.php:666`, `:739`, `ParcImportExecutor.php:498`, `BcclSeederIdempotenceTest.php:1555-1556` | tenant, portée | refus 422 à l'écriture ; le test du seed lit le nouveau compte |
| **Groupes lus sur les membres actifs** [B] | `ActiveSharedBlocks` ; pause écrite par `TeamPeriodOverrideStateProcessor.php:89`, `:103` (inchangé) | `SoloReservationBudget` (B, `inBlock`, groupe hypothétique `:100`), `TeamReservedSlots`, Récap sans payload, `SlotReservationModal.tsx:270`, `:272` | filtre club + saison sur les pauses | égal au payload (`ScheduleConstraintBuilder.php:1152-1185`), tenu par A6 |
| **Équipe dont le seul partenaire est en pause** [B] | `PeriodTeams.tsx:313` (inchangé) | écran : « reste 1 créneau » ; règle f : réservation individuelle acceptée ; Récap : comptée sans groupe | le budget lui compte aujourd'hui une séance de groupe que le moteur n'a pas | une période copiée avec un membre en pause n'est pas bloquée à sa naissance |
| **Séance de groupe reconnue : marquée, ou case attribuée au groupe** [B] | `TeamReservedSlots` | les mêmes lecteurs | lit toutes les séances de la version chargée | un verrou sur un seul membre d'un groupe réuni nulle part compte en plus |
| **Présence libre conditionnelle (2a)** ⟦QO-1⟧ [B] | `TeamReservedSlots` ; les gestes qui y mènent : crayon du groupe, `TeamStateProcessor.php:170` ou `TeamPeriodOverrideStateProcessor.php:90`, cadenas `ManualEditController.php:92` (tous inchangés) | budget, Récap, trois guichets, sélecteur, option de groupe | v11 : Récap vert, le moteur sépare le groupe (`rule_sacrificed`, constat 48) | 422 `blocker`, les deux membres nommés ; sortie : ôter un cadenas ou supprimer le groupe |
| **Une case = un seul des groupes d'une équipe (2d)** [B] | `TeamReservedSlots` | les mêmes lecteurs | le blocage existant applique déjà la règle (`PreSolvePreventionWarnings.php:1134`) | le membre du groupe non servi est compté en individuel |
| **Plafond des créneaux de groupe (point 55)** ⟦QO-3⟧ [B] | `TeamReservedSlots` | budget (`individualUsed`, `groupLockedCount`), Récap, sélecteur, règle f | groupe épinglé ensemble sur trop de cases : refusé d'abord par `PreSolvePreventionWarnings.php:1165-1167` | une case de plus que les séances communes compte comme individuelle |
| **Cadenas sur un seul membre, groupe réuni nulle part (cas U11)** [B] | cadenas, copie de période, « Charger » | Récap et trois guichets ; sélecteur | aujourd'hui : la génération part, le moteur amène le partenaire (constat 64) | 422 `blocker` ; sortie : ôter le cadenas, supprimer le groupe ou augmenter les séances |
| **Réduction qui retire la séance commune d'un seul membre** [B] | `PeriodTeams.tsx:357` puis `PeriodPlanTranscriber.php:258-287` (inchangés) | Récap de la période, « Régénérer », « Combler » | aujourd'hui : rien | les deux membres nommés dès la naissance |
| **Groupe séparé en saison, puis copié (4a)** [B] | calcul de saison, puis `PeriodPlanTranscriber.php:208-226` et `GenerateStep.tsx:246-271` (inchangés) | Récap de la période, guichets, sélecteur | aujourd'hui : rien | les deux membres nommés ; rien créé à la copie |
| **Membre réactivé après la copie** [B] | `PeriodTeams.tsx:313` (inchangé) | Récap de la période | — | le groupe redevient effectif ; l'autre membre est nommé |
| **Séances communes au-dessus des séances d'un membre (Q2)** [B] | `PeriodTeams.tsx:357` → `TeamPeriodOverrideStateProcessor.php:90`, `:104` ; saison `TeamStateProcessor.php:170` (inchangés) | Récap (`RecapStep.tsx:451`) et trois guichets | aujourd'hui : rien avant le moteur | 422 `blocker` ; sortie : crayon (`SharedTrainingBlockPanel.tsx:215`, `:280`, `:162`) ou remonter les séances |
| **Q2 quand l'équipe tient plus de créneaux que de séances (2b)** ⟦QO-2⟧ [B] | les mêmes, après une reprise ou des cadenas | Récap et guichets ; le crayon (`SharedTrainingBlockStateProcessor.php:243-251`) | v11 : la phrase envoyait vers un `PUT` refusé 422 | la phrase dit « déverrouillez d'abord N séance(s), puis … » ; le cadenas s'ôte sans garde (`ManualEditController.php:92-140`) ; le `PUT` passe ensuite |
| **Le Récap dit « supprimez le groupe » quand ce geste suffit ; jamais « sortez-en l'équipe » (2e)** [B] | `GenerationBlockersGuard` | `RecapStep.tsx:451`, guichets | un seul groupe effectif, I + G ≤ S | geste : `SharedTrainingBlockPanel.tsx:218`, `DELETE` sans garde de budget |
| **Déclarer ou modifier un groupe quand un membre tient un créneau individuel en trop** [B] | `SharedTrainingBlockStateProcessor.php:243-251` | écran des groupes (`SharedTrainingBlockPanel.tsx:162-171`) | — | refus 422, équipes nommées ; vaut pour baisser les séances communes et retirer un membre |
| **Option de groupe grisée dans la liste, avec sa raison (4b)** [B] | `SlotReservationModal.tsx:296-298`, `:724-749` ; `team-select.tsx:45`, `:83` | gestionnaire ; `SlotReservationModal.test.tsx:304-311`, `team-select.test.tsx:37-51` | aujourd'hui : le groupe sort de la liste, raison dessous, et rien n'est montré s'il ne reste aucune option posable | les quatre raisons en sous-ligne d'une option grisée ; le sélecteur s'ouvre même si tout est grisé |
| **Membre au plafond par ses verrous (4b)** [B] | `groupReservation.ts:107-111` | `SlotReservationModal.tsx:296` | aujourd'hui : l'option est offerte | grisée ; l'appel direct est refusé (`ReservationGroupOccupancy.php:197-199`) |
| **Séance commune tenue par des verrous (2c)** [B] | `groupReservation.ts:99` ; `ReservationGroupOccupancy.php:185-187` | sélecteur ; `GroupReservationController.php:236`, `ParcImportExecutor.php:579` | v11 : option offerte, serveur accepte, le Récap bloque ensuite « réservé sur 2 cases » | grisée ; 422 à l'appel direct |
| Bloc sur-épinglé [B : inchangé] | — | `PreSolvePreventionWarnings.php:1165-1167` par `GenerationBlockersGuard.php:114` | — | refus existant, premier dans l'ordre |
| **Gymnases désactivés [B : périmètres inchangés]** | — | budget : compte tout (`SoloReservationBudget.php:195-212`) ; Récap : gymnases du payload (`ValidateConstraintsController.php:452-455`, `GenerationBlockersGuard.php:70-73`) | — | l'écart reste ; P4-601 complétée |
| Plafond par membre [B] | `ReservationGroupOccupancy.php:197-199` | `GroupReservationController.php:236`, `ParcImportExecutor.php:579` | exclusivité, séances communes tenues | refus 422 |
| Sur-réservation du Récap, règle de comptage [B] | `GenerationBlockersGuard` | `ValidateConstraintsController.php:222-223` → `RecapStep.tsx:451` ; guichets | refus d'état, T1 [D] | 422 `blocker`, moteur non appelé |
| **Membre d'un groupe, réservations seules, sans verrou** [B] | données existantes | Récap et guichets | aujourd'hui vert (constat 16) | refusé si séances de groupe + réservations individuelles dépassent le volume |
| État « dépassé » du sélecteur [B] | `SlotReservationModal.tsx` | gestionnaire | — | équipe désactivée, texte « de trop » |
| Signatures de `blockersFor` et `overBookedOnEntities` [B] | `GenerationBlockersGuard.php:102`, `:131` | `:83`, `ValidateConstraintsController.php:222-223`, `GenerationRefusesARecapBlockerTest.php:323` | — | — |
| Champs neufs de `GET /api/team_solo_budgets` [B] | `TeamSoloBudgetResource` | `wizard/api.ts:570-583`, `openapi-snapshot.json`, mocks de budget | `TsFieldsMatchOpenApiSchemaTest`, `OpenApiSnapshotMatchesTheLiveContractTest` | — |
| Séances copiées d'une période comptées [B] | `PeriodPlanTranscriber`, `PeriodPlanSiblingCopier` (inchangés) | écran et Récap de la période, garde de groupe | — | réserver ailleurs demande de déverrouiller d'abord |
| Override baissé après copie [B] | écran des équipes de période | Récap de la période | — | génération refusée, équipe nommée |
| Cadenas posé ou ôté, version chargée [B] | `ManualEditController.php:92`, `LoadLocksController`, `RegenerateFromVersionController` | budget et Récap, à la requête suivante | verrou Redis | aucune garde au geste (roadmap) |
| Budget invalidé depuis le planning [B] | `planning/queries.ts` (`useLockSlot` `:276`, mutualisation `:301`, `:462`) | `useTeamSoloBudgets` (`wizard/queries.ts:956`) | — | sans cela, 30 s de compte périmé |
| Panne de la lecture [B] | — | guichets : génération non bloquée ; écran : aucune équipe offerte | — | journal d'erreur |
| Garde d'occupation des réservations de groupe [B : inchangée] | — | `ReservationGroupOccupancy.php:594-602` | — | lit encore tous les membres |
| **Test du seed : condition Q2 ajoutée** [B] | `tank`, `BcclSeederIdempotenceTest.php:1553-1568` | `make -C backend tests-complete`, `backend-tests` à la PR finale | — | un seed où une équipe a moins de séances que ses séances communes est rapporté |
| **Roadmap : P4-586 et P4-452 retirées** [B, documentation] | PR B (`roadmap.md:117`, `:190`, titre `:1`) | `RoadmapIdentityTest` (hors liste bloquante, `ci.yml:1404-1427`) | — | compteur égal au nombre de lignes |
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
| Moteur | rien | reçoit des HARD comme aujourd'hui | — | — |

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
15. **Verrouiller, puis réserver : l'écran refuse** [B]. U13F a 1 séance, lundi. Je la verrouille. Étape Réservations, jeudi 18:00 : U13F listée, désactivée, « Tous ses créneaux sont pris : 1 verrouillé ». Appel direct : 422 (§7).
    - **Inverse** : j'ôte le cadenas → « reste 1 créneau ».
    - **Variante même case** : U13F verrouillée lundi, la réserver ce même lundi est refusé ; j'ôte le cadenas, je réserve.
    - [D] **Variante déplacement** : du lundi au mercredi → « Tous ses créneaux sont pris : 1 déplacé manuellement ». Remise au lundi → « reste 1 créneau ».
16. **Réserver puis verrouiller : le Récap garde la porte** [B]. U13F, 1 séance, lundi. Je la réserve le jeudi (accepté). Je verrouille sa séance du lundi (accepté). Sélecteur : « 1 créneau de trop : 1 réservé · 1 verrouillé ». Récap : « U13F a 2 séances comptées pour 1 déclarée (1 réservation, 1 séance verrouillée) : retirez une réservation ou déverrouillez une séance, ou augmentez le nombre de séances de l'équipe. » « Régénérer » : refus, même phrase, aucune version créée.
    - **Inverse** : j'ôte le cadenas, ou je retire la réservation, ou je passe U13F à 2 séances : Récap vert.
17. **Recharger une version** [D]. SM1, 1 séance, réservée lundi. En version 2 je la déplace au mardi (réservation retirée). Je charge la version 1 puis la version 2 : la réservation du lundi revient. Récap : « SM1 a 2 séances comptées pour 1 déclarée (1 réservation, 1 séance verrouillée ou déplacée) : … ». Remède : retirer la réservation, ou remettre SM1 au lundi.
18. **Réservation et sa séance** [B]. U15, 1 séance, réservée mardi ; après génération sa séance est HARD d'origine RESERVATION. Écran : « Tous ses créneaux sont placés ». Récap vert.
19. **Période copiée (X)** [B]. Fermeture de février, première version reprise du planning de saison. U13F (1 séance, lundi 18:00) y arrive verrouillée.
    - Jeudi 18:00 : U13F listée, désactivée, « Tous ses créneaux sont pris : 1 verrouillé » ; ligne d'aide de période.
    - Je déverrouille sa séance du lundi → « reste 1 créneau ». Je la réserve le jeudi : accepté.
    - **Inverse** : je retire la réservation et je reverrouille le lundi.
    - **Variante période générée sans reprise** : rien n'est verrouillé.
    - **Variante socle** : un cadenas du socle ne compte pas dans la période, et inversement.
    - **Variante séances baissées après la copie** : U15 a 2 séances copiées ; je la passe à 1. Sélecteur : « 1 créneau de trop : 2 verrouillés ». Récap : « U15 a 2 séances comptées pour 1 déclarée (2 séances verrouillées) : déverrouillez 1 séance, ou augmentez le nombre de séances de l'équipe. » Baisser **avant** la copie ne bloque pas une équipe sans groupe.
    - **Variante régénérée** : les séances restent verrouillées (constat 37).
    - Pas de variante « gymnase désactivé » (4c).
20. **Groupe mutualisé** [B]. U11-1 et U11-2, 1 séance chacune, mutualisées au planning le mercredi. Écran : aucune des deux n'est proposée seule. Récap vert.
    - **Variante période copiée** : les deux séances copiées sont sur la même case : Récap vert. Ligne d'équipe : « Tous ses créneaux sont placés — la séance du groupe U11-1 + U11-2 est verrouillée : déverrouillez-la au planning pour réserver le groupe ailleurs ».
    - **Variante option de groupe (4b, 2c)** : même période, j'ouvre le jeudi 18:00, case libre. Dans la liste, section « Entraînements mutualisés », « U11-1 + U11-2 » est grisé, non sélectionnable, sous-ligne « Sa séance commune est verrouillée au planning : déverrouillez-la pour réserver le groupe ailleurs. » **Inverse** : j'ôte le cadenas de U11-1 seule, je rouvre : le groupe reste grisé, « U11-2 a déjà toutes ses séances de la semaine, dont 1 verrouillée : déverrouillez-la au planning. » J'ôte celui de U11-2 : le groupe est sélectionnable ; je le pose, accepté. **Appel direct** sans déverrouiller : 422 « séances communes déjà placées, dont 1 verrouillée » (§7).
    - **Variante équipes à 2 séances (le cas 2c)** : U15-1 et U15-2 à 2 séances, 1 séance commune, verrouillées ensemble le mercredi. Jeudi : groupe grisé, même sous-ligne. Appel direct : 422.
    - **Variante déclarer un groupe dans une période copiée** : U13-1 (lundi) et U13-2 (mardi), 1 séance chacune, verrouillées. Nouveau groupe → enregistrer : **refusé**, « Cette mutualisation ferait passer des créneaux réservés ou verrouillés au-dessus de ce qui reste hors groupe pour : U13-1, U13-2. Retirez une réservation ou déverrouillez une séance avant de mutualiser. » **Pour y arriver** : je déverrouille les deux séances, je déclare, je régénère. **Inverse** : poubelle du groupe.
21. **Créneau libre** [B]. « Loto du club » posé sans équipe : aucun compte ne change.
22. **Membre d'un groupe : le groupe est une séance** [B]. U15-1 déclare 2 séances dont 1 en groupe avec U15-2. Je la réserve le jeudi à son seul nom (accepté). Je verrouille sa séance du mardi, où U15-2 n'est pas (accepté).
    - Sélecteur : « 1 créneau de trop, groupe compris : 1 en groupe · 1 réservé · 1 verrouillé ».
    - Récap : « U15-1 a 3 séances comptées pour 2 déclarées (1 séance de groupe, 1 réservation, 1 séance verrouillée) : retirez une réservation ou déverrouillez une séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » « Régénérer » : refus.
    - Même résultat que la version chargée montre le groupe réuni un autre jour ou nulle part.
    - **Inverse** : j'ôte le cadenas du mardi, ou je retire la réservation du jeudi : Récap vert.
    - **Variante sans aucun verrou** : 2 réservations à son seul nom : « … (1 séance de groupe, 2 réservations) : retirez 1 réservation, supprimez le groupe, ou … ».
    - **Variante 0 séance hors groupe, réservations** : U11 A, 1 séance prise par son groupe, 2 réservations : « U11 A a 3 séances comptées pour 1 déclarée (1 séance de groupe, 2 réservations) : retirez 2 réservations, ou augmentez le nombre de séances de l'équipe. »
    - **Variante 0 séance hors groupe, verrou** : U11 A a 2 séances copiées, mercredi avec son groupe et lundi seule, puis passe à 1 séance : « U11 A a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, ou … ».
    - **Variante deux cases qui réunissent le groupe (point 55, corrigée)** ⟦QO-3⟧ : U17-1 (2 séances) verrouillée lundi et jeudi ; U17-2 (2 séances, rien de tenu) y est par deux séances libres ; 1 séance commune. Récap vert (1 séance de groupe + 1 verrouillée = 2 pour 2). Sélecteur de U17-1 : « Tous ses créneaux hors groupe sont pris : 1 verrouillé ». Je passe U17-1 à 1 séance : « U17-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, ou … ». **Inverse** : je la remonte à 2. **Si U17-2 est verrouillée aussi sur les deux cases** : le Récap dit d'abord « Groupe U17-1, U17-2 : réservé sur 2 cases pour 1 séance commune — … » (constat 92).
    - **Variante période** : même compte sur la version chargée de la période, S = override.
23. **Cadenas sur un seul membre d'un groupe** [B]. U11-1 et U11-2, 1 séance chacune, en groupe, placées ensemble le mercredi par la génération. Je verrouille la carte de U11-1 seule. Récap vert, « Régénérer » part.
    - **Inverse** : j'ôte le cadenas, rien ne change au Récap.
    - **Variante partenaire ailleurs (le cas du fondateur)** : U11-2 est au jeudi, libre. Je verrouille U11-1 le mercredi. Sélecteur : « 1 créneau de trop, groupe compris : 1 en groupe · 1 verrouillé ». Récap : « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » « Régénérer » : refus, aucune version créée.
      - **Inverse** : j'ôte le cadenas. **Autres sorties** : U11-1 à 2 séances ; ou poubelle du groupe.
    - **Variante chacun verrouillé sur une case différente (2a)** ⟦QO-1⟧ : U17-1 et U17-2, 1 séance chacune, 1 séance commune, la version chargée les montre ensemble lundi et jeudi (constat 72 pour la baisse). Cadenas de U17-1 le lundi, de U17-2 le jeudi. Récap : « U17-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou … » et la même phrase pour U17-2. « Régénérer » : refus. **Inverse** : j'ôte le cadenas de U17-1 : Récap vert pour les deux, « Régénérer » part.
    - **Variante équipe qui a une séance hors groupe** : U15-1, 2 séances dont 1 en groupe, verrouillée seule le mardi : 2 pour 2, vert.
    - **Variante période** : même geste, même résultat.
24. **Membre en pause** [B]. Fermeture de février. Avant la reprise, je décoche U11-2. La reprise ne copie que U11-1, verrouillée le mercredi.
    - Récap de la période : vert (1 séance verrouillée pour 1 déclarée, sans groupe).
    - Sélecteur : U11-1 « Tous ses créneaux sont pris : 1 verrouillé », sans « hors groupe ».
    - **Inverse** : je recoche U11-2. Le groupe redevient effectif ; U11-1 est verrouillée seule : « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou … ». J'ôte le cadenas : vert.
    - **Variante groupe de trois** : U11-3 aussi membre, active. U11-1 et U11-3 copiées ensemble le mercredi : de groupe, vert.
    - **Variante socle** : pas de pause au socle.
25. **Membres d'un groupe bloqués dès la naissance de la période, ou par une baisse de séances** [B].
    - **Par une réduction.** U15-1 (lundi seule, mercredi avec U15-2) passe à 1 séance avant la reprise. La reprise retire son mercredi.
      - Récap : « U15-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » et la même phrase pour U15-2. « Régénérer » et « Combler automatiquement » : refus.
      - **Sortie en un geste** : « Liens » de U15-2 → poubelle → confirmer. Vert.
      - **Sortie en gardant le groupe** : j'ôte les deux cadenas, puis « Régénérer ».
      - **Inverse de la réduction** : je remonte U15-1 à 2 séances ; U15-2 reste nommée tant que la séance du mercredi de U15-1 n'est pas reposée.
      - **Variante séance commune en début de semaine** : la reprise retire le mercredi solo, le groupe reste réuni le lundi, vert.
    - **Par un groupe séparé en saison (4a).** U13-1 mardi, U13-2 jeudi, 1 séance chacune, copiées verrouillées.
      - Récap : la phrase ci-dessus pour les deux. Refus des deux lancements.
      - Sélecteur, option de groupe sur une case libre : grisée, « U13-1 a déjà toutes ses séances de la semaine, dont 1 verrouillée : déverrouillez-la au planning. »
      - **Sortie en un geste** : poubelle du groupe. **Inverse** : aucun geste ne « rend » la séparation ; recréer le groupe est refusé tant que les deux équipes sont verrouillées à part.
      - **Variante saison** : les deux séances ne sont pas verrouillées : Récap de saison vert. **Variante vacances** : aucun groupe copié, rien de bloqué.
    - **Par un groupe de trois dont un membre est ailleurs (2e).** U11-1 et U11-2 verrouillées ensemble le mercredi, U11-3 verrouillée le jeudi, 1 séance chacune. Les trois nommées, « … : déverrouillez 1 séance, supprimez le groupe, ou … ». **Sortie** : poubelle du groupe, vert. Sortir U11-3 du groupe n'est pas proposé.
    - **Par des séances communes en trop (Q2, décidé).** U17-1 et U17-2, groupe à 2 séances communes, période générée sans reprise. Je tape 1 dans « Séances de U17-1 cette période ».
      - Récap : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. » U17-2 n'est pas nommée. « Régénérer » : refus.
      - **Sortie** : « Liens de U17-1 » → crayon → « séances communes » : 1 → enregistrer. Vert.
      - **Inverse** : je remets U17-1 à 2 séances : vert.
      - **Variante période reprise après la baisse** : la reprise n'a copié qu'une séance de U17-1. Même phrase validée ; le crayon est accepté ; vert.
      - **Variante période reprise avant la baisse (2b)** ⟦QO-2⟧ : les 2 séances communes sont arrivées verrouillées, puis je passe U17-1 à 1 séance. Récap : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. » J'ôte le cadenas d'une séance de U17-1 au planning, puis crayon → 1 → enregistrer : accepté, vert. **Mauvais ordre** : le crayon répond le refus du §7, rien n'est écrit. **Inverse** : U17-1 remise à 2 séances : vert sans autre geste.
      - **Variante saison** : étape Équipes, U17-1 à 1 séance : même phrase au Récap de saison.
      - **Variante avec un verrou à part** : « U17-1 a 3 séances comptées pour 1 déclarée (2 séances de groupe, 1 séance verrouillée) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. »

## 7. Textes, mot pour mot

### PR B

**Sélecteur de réservation, équipes.** Le détail liste les sortes non nulles, dans cet ordre, séparées par « · » :
- singulier : « 1 réservé », « 1 verrouillé » ; pluriel : « 2 réservés », « 2 verrouillés ».
- **Encore réservable, sans verrou** : inchangé, « reste 1 créneau » / « reste 2 créneaux », sous-ligne « hors groupe » si membre d'un groupe effectif.
- **Encore réservable, avec verrou** : « reste 1 créneau » ; sous-ligne « 1 réservé · 1 verrouillé ». Membre d'un groupe : « hors groupe · 1 verrouillé ».
- **0 restant, réservations seules** : inchangé, « Tous ses créneaux sont placés » (suffixe « — passez par le groupe {nom} » inchangé, seulement pour un groupe effectif).
- **0 restant, équipe hors groupe, avec verrou** : « Tous ses créneaux sont pris : 1 réservé · 1 verrouillé ».
- **0 restant hors groupe, membre d'un groupe, avec verrou individuel** : « Tous ses créneaux hors groupe sont pris : 1 verrouillé ». Sans « passez par le groupe ».
- **Dépassé**, équipe désactivée :
  - hors groupe : « 1 créneau de trop : 1 réservé · 1 verrouillé » ; pluriel « 2 créneaux de trop : 3 réservés » ;
  - membre d'un groupe : « 1 créneau de trop, groupe compris : 1 en groupe · 1 réservé · 1 verrouillé » ; cas U11 : « 1 créneau de trop, groupe compris : 1 en groupe · 1 verrouillé » ; pluriel « 2 créneaux de trop, groupe compris : 1 en groupe · 2 réservés » ; « 2 en groupe » ;
  - « de trop » = `individualUsed − residual` ; « N en groupe » = `blockSessions` ;
  - jamais de suffixe « passez par le groupe » ; vaut aussi pour des réservations seules.
- **Membre dont le groupe a une séance verrouillée** (`groupLockedCount` ≥ 1), à 0 restant : « Tous ses créneaux sont placés — la séance du groupe {nom} est verrouillée : déverrouillez-la au planning pour réserver le groupe ailleurs » ; à partir de 2 : « Tous ses créneaux sont placés — 2 séances du groupe {nom} sont verrouillées : déverrouillez-en une au planning pour réserver le groupe ailleurs ». En état dépassé, la ligne « de trop » l'emporte.
- **Ligne d'aide, portée période, quand au moins une équipe de la liste a un créneau verrouillé** : « Dans une période, les séances reprises du planning de saison arrivent verrouillées. Pour réserver une équipe ailleurs, déverrouillez d'abord une de ses séances au planning. »
- **Séances communes en trop (Q2)** : aucun texte au sélecteur.

**Sélecteur de réservation, option de groupe (4b).** Le groupe indisponible est une option grisée de la section « Entraînements mutualisés » ; sa raison est la sous-ligne ; plus de liste sous le sélecteur, plus de « · indisponible ». Raisons :
- membre en pause : inchangé, « {équipe} est en pause cette période. » ;
- séances communes posées, réservations seules : inchangé, « Sa séance commune est déjà posée. » / « Ses 2 séances communes sont déjà posées. » ;
- **1 séance commune, tenue par des séances verrouillées (2c)** : « Sa séance commune est verrouillée au planning : déverrouillez-la pour réserver le groupe ailleurs. » ;
- **2 séances communes ou plus, toutes tenues, dont 1 par des verrous** : « Ses 2 séances communes sont déjà posées, dont 1 verrouillée au planning : déverrouillez-la pour réserver le groupe ailleurs. » ; **dont 2 ou plus** : « Ses 2 séances communes sont déjà posées, dont 2 verrouillées au planning : déverrouillez-en une pour réserver le groupe ailleurs. » ;
- membre au plafond sans verrou : inchangé, « {équipe} a déjà toutes ses séances de la semaine. » ;
- membre au plafond, 1 verrou compté : « {équipe} a déjà toutes ses séances de la semaine, dont 1 verrouillée : déverrouillez-la au planning. » ; 2 ou plus : « … dont 2 verrouillées : déverrouillez-en une au planning. » ;
- le nombre « verrouillée(s) » d'un membre est `lockedCount + groupLockedCount` ; le premier membre trouvé est nommé.

**Refus serveur de la réservation individuelle (règle f).**
- Réservations seules : inchangé (`ReservationGroupOccupancy.php:374`). Équipe sans séance hors groupe : inchangé (`:370-371`).
- Avec verrou : « L'équipe « U13F » a déjà 1 créneau pris sur 1 possible (1 verrouillé) : elle ne peut pas en réserver davantage. Retirez une réservation ou déverrouillez une séance, ou augmentez son nombre de séances. » Pluriel : « a déjà 2 créneaux pris sur 2 possibles (1 réservé, 1 verrouillé) ».

**Refus de déclaration ou de modification de groupe, et de mutualisation depuis la fiche.**
- Réservations seules : inchangés (`SharedTrainingBlockStateProcessor.php:250`, `MutualizeSlotService.php:714`).
- Avec verrou, écran des groupes : « Cette mutualisation ferait passer des créneaux réservés ou verrouillés au-dessus de ce qui reste hors groupe pour : U13-1. Retirez une réservation ou déverrouillez une séance avant de mutualiser. » Plusieurs : « pour : U13-1, U13-2. » Même phrase quand le crayon baisse les séances communes ou retire un membre.
- Planning : même phrase sans « pour : … ».

**Refus de réservation de groupe, séances communes déjà tenues (2c).** Réservations seules : inchangé (`ReservationGroupOccupancy.php:186`). Avec au moins une case tenue par des verrous : « Cette mutualisation a déjà ses 1 séance(s) commune(s) placée(s), dont 1 verrouillée(s) au planning : vous ne pouvez pas en réserver davantage. Retirez une séance mutualisée ou déverrouillez-en une au planning avant d'en ajouter une autre. »

**Refus de réservation de groupe, plafond d'un membre.** Réservations seules : inchangé (constat 86). Avec verrou : « L'équipe « U11-1 » atteint déjà son nombre de séances par semaine (1) : cette séance mutualisée le dépasserait. Retirez une réservation ou déverrouillez une séance, ou augmentez son nombre de séances. »

**Blocage du Récap et des trois guichets.** Deux familles.

*Famille 1, inchangée, mot pour mot* — équipe sans groupe effectif, réservations seules : « U13F a 2 réservations pour 1 séance par semaine : retirez 1 réservation, ou augmentez le nombre de séances de l'équipe. »

*Famille 2* — tout autre cas :

« {équipe} a {C} séances comptées pour {S} déclarée(s) ({détail}) : {remède}[, supprimez le groupe], ou augmentez le nombre de séances de l'équipe. »

- **C** = séances de groupe + créneaux individuels. **S** = séances par semaine (override compris).
- Accords : « 2 séances comptées pour 1 déclarée » ; « 3 séances comptées pour 2 déclarées ».
- **Détail**, sortes non nulles, séparées par une virgule : « 1 séance de groupe » / « 2 séances de groupe » ; « 1 réservation » / « 2 réservations » ; « 1 séance verrouillée » / « 2 séances verrouillées ».
- **Remède quand les séances de groupe tiennent dans les séances déclarées**, N = créneaux individuels à libérer :
  - seules des réservations : « retirez 1 réservation » / « retirez 2 réservations » ;
  - seuls des verrous : « déverrouillez 1 séance » / « déverrouillez 2 séances » ;
  - les deux, N = 1 : « retirez une réservation ou déverrouillez une séance » ;
  - les deux, N ≥ 2 : « retirez ou déverrouillez-en 2 ».
- **« supprimez le groupe »**, présent seulement si l'équipe est membre d'un seul groupe effectif et que I + G ≤ S, quel que soit le nombre d'équipes du groupe.
- **Remède quand les séances de groupe dépassent les séances déclarées (Q2)**, G' = B − S, N = I + max(0, G − S) :
  - N = 0, un seul groupe (**phrase validée par le fondateur**) : « retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe) » ; pluriel « retirez-lui 2 séances de groupe (…) » ;
  - N = 0, plusieurs groupes : « retirez-lui 1 séance de groupe (baissez les séances communes d'un de ses groupes, ou supprimez-en un) » ;
  - **N ≥ 1 (2b)** ⟦QO-2⟧ : le remède individuel précédé de « d'abord » pour N créneaux, puis « , puis retirez-lui 1 séance de groupe (…) ». Formes : « déverrouillez d'abord 1 séance, puis … » ; « retirez d'abord 1 réservation, puis … » ; « retirez d'abord une réservation ou déverrouillez d'abord une séance, puis … » ; « retirez ou déverrouillez-en d'abord 2, puis … » ;
  - dans cette branche, « supprimez le groupe » n'est pas répété hors de la parenthèse.

Cas par cas :
- **Le cas du fondateur** : « U11-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. » Groupe de trois équipes : même phrase.
- Membre d'un groupe, réservation + verrou : « U15-1 a 3 séances comptées pour 2 déclarées (1 séance de groupe, 1 réservation, 1 séance verrouillée) : retirez une réservation ou déverrouillez une séance, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. »
- Membre d'un groupe, réservations seules : « U15-1 a 3 séances comptées pour 2 déclarées (1 séance de groupe, 2 réservations) : retirez 1 réservation, supprimez le groupe, ou … ».
- 0 séance hors groupe, 1 réservation : « U11 A a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 réservation) : retirez 1 réservation, supprimez le groupe, ou … ».
- Même équipe, 2 réservations : « U11 A a 3 séances comptées pour 1 déclarée (1 séance de groupe, 2 réservations) : retirez 2 réservations, ou augmentez le nombre de séances de l'équipe. »
- Même équipe, réservation et verrou : « … (1 séance de groupe, 1 réservation, 1 séance verrouillée) : retirez ou déverrouillez-en 2, ou … ».
- Verrou de groupe tenu et verrou individuel : « U11 A a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, ou … » (sans « supprimez le groupe » : I + G = 2 > S).
- Réduction, groupe séparé en saison, deux membres verrouillés chacun à part (2a), groupe de trois (2e) : « U15-1 a 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe, ou … » ; même phrase pour chaque autre membre nommé.
- Partenaire en pause, 1 réservation + 1 verrou : « U11-1 a 2 séances comptées pour 1 déclarée (1 réservation, 1 séance verrouillée) : retirez une réservation ou déverrouillez une séance, ou … ».
- Équipe hors groupe, verrous seuls : « U15 a 2 séances comptées pour 1 déclarée (2 séances verrouillées) : déverrouillez 1 séance, ou … ».
- Pluriels : « U17 a 5 séances comptées pour 3 déclarées (2 séances de groupe, 2 réservations, 1 séance verrouillée) : retirez ou déverrouillez-en 2, supprimez le groupe, ou augmentez le nombre de séances de l'équipe. »
- Q2, rien de tenu en trop : la phrase validée (parcours 25).
- Q2, deux séances communes verrouillées pour 1 séance déclarée ⟦QO-2⟧ : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. »
- Q2, avec un verrou à part ⟦QO-2⟧ : « U17-1 a 3 séances comptées pour 1 déclarée (2 séances de groupe, 1 séance verrouillée) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. »

Comblement : la réécriture de `:176-187` ne touche aucune de ces phrases (constat 47).

### PR D

**Planning (inchangés depuis la v3).**
- **T1, 1 réservation.** Titre : « Déplacer ce créneau réservé ? » Corps : « Une réservation de gymnase tient cette équipe à ce créneau. Si vous déplacez la séance, la réservation est retirée et la séance restera à sa nouvelle place à la prochaine génération. Les autres versions du planning ne changent pas. « Annuler le dernier geste » ne recrée pas la réservation. » Boutons : « Déplacer » / « Annuler ».
- **T1, N ≥ 2.** Titre : « Déplacer ces créneaux réservés ? » Corps : « Ce déplacement retire {N} réservations de gymnase : les séances resteront à leur nouvelle place à la prochaine génération. Les autres versions du planning ne changent pas. « Annuler le dernier geste » ne recrée pas les réservations. »
- **Paragraphe des dialogues d'éviction et d'échange** : « Ce déplacement retire aussi 1 réservation de gymnase. « Annuler le dernier geste » ne la recrée pas. » / « Ce déplacement retire aussi {N} réservations de gymnase. « Annuler le dernier geste » ne les recrée pas. »
- **T2, suffixe de toast** : « — 1 réservation retirée » / « — {N} réservations retirées », avant « — N compromis ». Exemples : « Créneau déplacé — 1 réservation retirée. » ; « SM1 déplacée — U17 est à replacer — 1 réservation retirée. » ; « Groupe déplacé — 3 réservations retirées. » ; « Séances échangées — 2 réservations retirées — 2 compromis ».
- **T3, aide de la fiche**, forme (a) : « Ce créneau est réservé auprès du gymnase. Le déplacer retire la réservation. »
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
- **Rouges marqués ⟦QO-n⟧ : pas avant la réponse du fondateur.**

### PR B

**Feature neuve `backend/features/les-creneaux-reserves-ne-depassent-pas-les-seances.feature`**, suite `bloc` (`behat.dist.php:123-125`), pas ajoutés à `tests/Behat/TrainingBlockContext.php`.

Scénario 1 — « Au planning de saison, un cadenas prend un créneau réservable » :
- Étant donné le club de démonstration et son planning de saison
- Et une équipe jetable à 1 séance, placée le mardi dans un gymnase jetable ouvert mardi et mercredi
- Quand je verrouille sa séance
- Alors il ne lui reste aucun créneau à réserver, avec 1 verrouillé
- Et la réserver le mercredi est refusé : 1 créneau pris sur 1 possible, dont 1 verrouillé
- Quand j'ôte le cadenas
- Alors il lui reste 1 créneau à réserver
- Quand je la réserve le mercredi puis je verrouille sa séance du mardi
- Alors le récapitulatif de la saison bloque en nommant l'équipe, 2 séances comptées pour 1 déclarée
- Et son budget compte 1 créneau de plus que ce qui lui reste
- Quand j'ôte le cadenas
- Alors le récapitulatif de la saison ne nomme plus cette équipe

Scénario 2 — « Dans une période reprise du planning de saison, les séances copiées comptent » :
- Étant donné le club de démonstration et un plan de période généré *(pas existant : reprise sans calcul)*
- Et une équipe dont toutes les séances copiées sont verrouillées
- Alors il ne lui reste aucun créneau à réserver dans la période, toutes ses séances comptées comme verrouillées
- Et la réserver sur une case libre de la période est refusé
- Et le récapitulatif de la période ne nomme pas cette équipe
- Quand je déverrouille une de ses séances
- Alors il lui reste 1 créneau à réserver dans la période
- Quand je la réserve sur une case libre de la période
- Alors la réservation est acceptée
- Et le budget de la saison de cette équipe n'a pas changé

**Choix de l'équipe du scénario 2**, par SQL, les quatre critères ensemble : membre d'aucun groupe du plan de période ; aucune réservation du plan de période ; nombre de séances HARD d'origine MANUAL dans la version de période égal à son nombre de séances ; active pour la période. Aucune équipe trouvée : `RuntimeException` qui nomme le critère manquant.

**`un-verrou-de-periode-survit-a-la-regeneration.feature:17`, suite `verrou-periode`** : aucun pas ajouté, témoin de (X), rejoué tel quel après `neo`.

| # | Décision | Scénario |
|---|---|---|
| S5 | Un cadenas prend un créneau réservable ; l'écran et l'API refusent ; l'ôter le rend. | Feature neuve, scénario 1, pas 3 à 7. |
| S6 | Réserver puis verrouiller : le Récap bloque « 2 séances comptées pour 1 déclarée » ; le budget porte le dépassement ; ôter le cadenas débloque. | Feature neuve, scénario 1, pas 8 à 12. Trois guichets : A4 (a). |
| S7 | Une réservation et sa séance née de la génération comptent une fois. | A5 et A4 (g). |
| S8 | Période copiée (X) : les séances copiées comptent ; déverrouiller libère ; période et socle ne se mélangent pas. | Feature neuve, scénario 2. |
| S8 bis | Une période copiée entièrement verrouillée, sans groupe séparé, se régénère. | `un-verrou-de-periode…:17`, inchangé, rejoué. |
| S9 | Groupe mutualisé = une équipe : séance marquée, case qui réunit le groupe. | A5, A4 (h). `l-unite-de-placement-est-le-bloc.feature` rejouée. |
| S10 | Charger une autre version change le compte. | A4 (c). |
| S11 | Membre d'un groupe : le groupe est une séance ; vrai avec 0 séance hors groupe et sans aucun verrou. | A4 (d), (e). |
| S12 | Séances baissées après la copie : la période bloque. | A4 (i). |
| S13 | Période sans plan : rien compté. | A4 (j). |
| S14 | Cadenas sur un seul membre. Partenaire sur la case : pas de blocage. Groupe réuni nulle part : l'équipe à 1 séance est refusée ; celle qui a une séance hors groupe passe. | A4 (m), A5. |
| S15 | Membres actifs seulement ; un groupe à moins de 2 membres actifs n'existe pas ; réactiver fait revivre le groupe. Budget et payload disent le même groupe. | A4 (n), A5, A6. |
| S16 | Membres verrouillés à part après une réduction : chacun nommé ; trois guichets fermés ; supprimer le groupe débloque. | A4 (o), A5. |
| S17 | Gymnase désactivé : le Récap garde son périmètre ; le budget le sien. | A4 (p), A5. |
| S18 | Membre dont la séance commune est verrouillée : l'écran dit de déverrouiller, pas « passez par le groupe ». | A3 [B], cas 4 ; A5 (`groupLockedCount`). |
| S19 | Déclarer un groupe de deux équipes verrouillées sur deux cases est refusé, les deux nommées ; sur la même case, accepté. | A5 (budget). |
| S21 | Équipe dépassée : désactivée, « N de trop », groupe compris. | A3 [B], cas 5. |
| S22 | Équipe sans groupe effectif, réservations seules : la phrase d'aujourd'hui ne change pas d'un mot. | A4 (q), `testOverbookingIsReadOnThePeriodVolume` inchangé. |
| S23 | Équipe dont le seul partenaire est en pause : l'écran lui rend son créneau ; la réserver seule est accepté. | A5 ; A3 [B], cas 6. |
| S24 (Q2) | Séances de groupe au-dessus des séances déclarées : Récap et guichets bloquent, phrase validée ; baisser les séances communes débloque. | A4 (s). |
| S25 (4a) | Période copiée d'une saison dont un groupe est séparé : les deux membres nommés, guichets fermés ; supprimer le groupe débloque ; la copie ne crée rien. | A4 (t), A5. |
| S26 (4b) | Un groupe dont un membre a toutes ses séances prises, verrous compris, est une option grisée avec sa raison ; déverrouiller le rend ; l'appel direct est refusé. | A3 [B], `groupReservation.test.ts`, `team-select.test.tsx` ; A5 (plafond par membre). |
| S27 | Le Récap dit « supprimez le groupe » quand ce geste suffit, quel que soit le nombre d'équipes, se tait sinon, et ne dit jamais « sortez-en l'équipe ». | A4 (u). |
| S28 (point 55) ⟦QO-3⟧ | Un groupe ne reçoit pas plus de cases qu'il n'a de séances communes ; la case en plus compte comme individuelle ; le groupe épinglé ensemble sur trop de cases reste refusé d'abord par le blocage existant. | A5 ; A4 (v). |
| S29 (2a) ⟦QO-1⟧ | Une séance libre ne prouve la présence d'un membre que s'il lui reste une séance : deux membres verrouillés chacun sur une case différente, sans séance à donner, sont tous deux nommés et la génération est refusée ; ôter un seul cadenas débloque. | A4 (w) ; A5. |
| S30 (2b) ⟦QO-2⟧ | Q2 quand l'équipe tient plus de créneaux que de séances : la phrase dit « d'abord » ; baisser les séances communes avant de déverrouiller est refusé 422 ; après, accepté, et le Récap est vert. | A4 (s) complété ; A5 (garde du `PUT`). |
| S31 (2c) | Un groupe dont la séance commune est tenue par des séances verrouillées est grisé avec sa raison ; l'appel direct est refusé ; déverrouiller les deux séances le rend. | `groupReservation.test.ts` ; A5 (garde de réservation de groupe). |
| S32 (2d) | Une case qui réunit deux groupes d'une même équipe vaut pour un seul ; le membre de l'autre groupe est compté en individuel ; pas de remède de groupe pour l'équipe commune. | A5 ; A4 (u). |
| S33 (2e) | Groupe de trois dont un membre est ailleurs : les membres présents sont nommés avec « supprimez le groupe » ; supprimer le groupe débloque. | A4 (x). |
| S34 (grisé) | Une option de groupe grisée n'est pas sélectionnable et porte sa raison ; le sélecteur s'ouvre même si toutes ses options sont grisées. | `team-select.test.tsx` ; A3 [B], cas 7 et 8. |

**Tests sous Behat de la PR B, nommés :**

- **A6, parité du groupe entre le budget et le payload, non-régression de sémantique des contraintes, en gate.** Neuf : `backend/tests/Integration/Service/ActiveSharedBlocksMatchesPayloadTest.php`, `#[Group('phase1')]`, ajouté au step « Blocking suite » (`ci.yml:578`) et à `blocking-tests.md`. Aucun appel au moteur. Les groupes de `buildForPeriodPlan` / `buildForClubSeason` sont égaux à ceux d'`ActiveSharedBlocks::forScope` : période, groupe de 2, un membre en pause (aucun groupe) ; période, groupe de 3, un membre en pause (même groupe à 2, `declaredCount` 3) ; période sans pause ; socle ; vacances (vide). **Falsification exigée** : filtre retiré → rouge des deux premiers décors.
- **A3 [B], ce que seul l'écran porte.**
  - `frontend/src/features/wizard/steps/SlotReservationModal.test.tsx`, huit cas : (1) équipe à `residual 1`, `individualUsed 1`, `lockedCount 1` : désactivée, `WIZARD_TEXTS.allSlotsTaken` et le détail ; (2) équipe encore réservable avec `lockedCount 1` : détail en sous-ligne ; (3) portée période avec une équipe verrouillée : `WIZARD_TEXTS.periodLockedHint`, absent en saison ; (4) membre à `groupLockedCount 1` : `WIZARD_TEXTS.groupSessionLocked`, aucun « passez par le groupe » ; (5) membre à `residual 1`, `individualUsed 2`, `blockSessions 1`, `reservedCount 1`, `lockedCount 1` : désactivée, `WIZARD_TEXTS.slotsOverBudget(1, true)` ; (6) équipe d'un groupe de l'écran mais à `inBlock false`, `residual 1`, `individualUsed 0` : offerte, sans « hors groupe » ; (7) case libre, groupe dont un membre a `effectiveSessions 1`, `groupLockedCount 1`, `groupLockHeld` vide : le groupe **est** une option, `aria-disabled`, sous-ligne `WIZARD_TEXTS.groupMemberSessionsLocked(nom, 1)`, la choisir n'ajoute rien au brouillon ; (8) aucune équipe offrable et un seul groupe, bloqué : le sélecteur est affiché (pas « Aucune équipe disponible ») et l'option grisée porte sa raison.
  - **Test existant réécrit par `tank`** : `SlotReservationModal.test.tsx:304-311` (« un bloc ayant atteint ses séances communes n'est PAS offert ») : le groupe devient une option grisée dont la sous-ligne contient « séance commune est déjà posée » ; l'assertion « indisponible » disparaît.
  - `frontend/src/shared/components/ui/team-select.test.tsx`, un cas : une entrée de `mutualisationGroups` avec `disabled: true` et `sub` rend une option `aria-disabled` décrite par sa sous-ligne ; cliquer n'appelle pas `onValueChange`.
  - `frontend/src/features/wizard/lib/groupReservation.test.ts`, sept cas : membre à 0 réservation, `groupLockedCount 1`, 1 séance → `blocked`, raison au singulier ; membre à 1 réservation, `lockedCount 1`, 2 séances → `blocked` ; membre à 2 séances, `lockedCount 1`, aucune réservation → `offerable` ; membre au plafond sans verrou → raison d'aujourd'hui ; **groupe à 1 séance commune, `groupLockHeld` 1 → `blocked`, `groupCommonSessionsLockHeld(1, 1)`** ; **groupe à 2 séances communes, `groupLockHeld` 1, aucune case réservée → `offerable`** ; **groupe à 2 séances communes, 1 case réservée complète + `groupLockHeld` 1 → `blocked`, `groupCommonSessionsLockHeld(2, 1)`**.
  - requêtes du planning : un cadenas posé invalide `["wizard", "team_solo_budgets"]` (un cas, fichier à nommer par `tank`).
- **A4, non-régression d'axes (generation pipeline, constraint semantics), en gate.** Cas ajoutés à `backend/tests/Integration/Api/GenerationRefusesARecapBlockerTest.php` (`ci.yml:899`).
  - (a) fournisseur sur les trois guichets : équipe hors groupe à 1 séance, 1 réservation + 1 séance verrouillée un autre jour → 422 `blocker`, « 2 séances comptées pour 1 déclarée (1 réservation, 1 séance verrouillée) », aucune version créée, moteur non appelé ;
  - (b) témoin : la même équipe à 2 séances démarre ;
  - (c) le verrou porté par une version non chargée ne bloque pas ; après « Charger », il bloque ;
  - (d) membre d'un groupe, 2 séances dont 1 en groupe, 1 réservation à son seul nom + 1 verrou sur une case où son partenaire n'est pas → « 3 séances comptées pour 2 déclarées (1 séance de groupe, 1 réservation, 1 séance verrouillée) : retirez une réservation ou déverrouillez une séance, supprimez le groupe » ; sans verrou, 2 réservations → « … (1 séance de groupe, 2 réservations) : retirez 1 réservation, supprimez le groupe » ; témoins : groupe réservé sur 1 case sans réservation individuelle ; 1 réservation individuelle seule ;
  - (e) 0 séance hors groupe : « 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 réservation) : retirez 1 réservation, supprimez le groupe » et « 3 … (1 séance de groupe, 2 réservations) : retirez 2 réservations, ou augmentez » ; réservation + verrou : « retirez ou déverrouillez-en 2 » ;
  - (f) témoin de non-perte : un groupe réservé sur 2 cases pour 1 séance commune reste refusé, **et la première phrase rendue reste celle du blocage existant** ;
  - (g) séance HARD/RESERVATION sur la case de sa réservation : aucun blocage ;
  - (h) deux membres verrouillés sur la même case : séance de groupe, aucun blocage ;
  - (i) période : 2 séances verrouillées, override à 1 → « 2 séances comptées pour 1 déclarée (2 séances verrouillées) : déverrouillez 1 séance » ; la saison ne bloque pas ;
  - (j) période sans plan : `overBookedOnEntities` rend `[]` même si le socle porte un surplus ;
  - (k) verrous seuls, aucune réservation dans la portée : le blocage est vu ;
  - (l) sans payload, sur une période : override lu, équipe en pause non nommée ;
  - (m) **cadenas sur un seul membre**. Partenaire sur la case, non verrouillé, sans autre créneau tenu : aucun blocage, `/regenerate` part. **Partenaire ailleurs et libre, équipe à 1 séance (cas U11)**, trois guichets : 422, phrase du fondateur, aucune version créée. Cadenas ôté : la génération part. Équipe à 2 séances dont 1 en groupe : aucun blocage ;
  - (n) **membre en pause**, période copiée, groupe de 2 : aucun blocage ; réactivé : l'autre est nommé ; partenaire en pause avec 1 réservation + 1 verrou : « (1 réservation, 1 séance verrouillée) » ;
  - (o) **réduction** (transcription réelle) : les deux équipes nommées ; `/generate` de la période et `/fill` refusent ; groupe supprimé : le guichet accepte ; variante séance commune en début de semaine : aucun blocage ; au socle, verrous posés à la main : `/regenerate` refuse ;
  - (p) **gymnase désactivé pour la période** : une séance verrouillée dans ce gymnase n'est pas comptée par le Récap ;
  - (q) **équipe hors de tout groupe, 2 réservations pour 1 séance, aucun verrou** : phrase d'aujourd'hui, octet pour octet ;
  - (s) **séances communes en trop (Q2)** : période, groupe de 2 à 2 séances communes, un membre dont l'override passe à 1, sans verrou ni réservation → phrase validée sur le Récap et `/generate` de la période, aucune version créée ; l'autre membre n'est pas nommé. Séances communes remises à 1 par `PUT` : accepté, aucun blocage. Même décor au socle : `/regenerate` refuse. Groupe de 3 : même parenthèse. Équipe dans deux groupes : « (baissez les séances communes d'un de ses groupes, ou supprimez-en un) ». **Complément ⟦QO-2⟧** : groupe à 2 séances communes copié (deux cases verrouillées), membre baissé à 1 → « … (2 séances de groupe) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (…) » ; `PUT` à 1 séance commune avant de déverrouiller → 422, phrase « Cette mutualisation ferait passer… » nommant l'équipe, rien écrit ; une séance du membre déverrouillée puis le même `PUT` → accepté ; Récap sans blocage, le guichet accepte. Variante avec 1 verrou à part : « déverrouillez d'abord 1 séance, puis … » ;
  - (t) **groupe séparé en saison, puis copié (4a)** : version de saison où les deux membres d'un groupe à 1 séance commune sont sur deux cases, non verrouillés (posée par le décor, sans moteur) ; transcription réelle. Récap de la saison : aucune équipe nommée. Récap de la période : les deux nommées ; `/generate` et `/fill` refusent ; les séances copiées ne portent aucun `sharedTrainingBlockId` ; groupe supprimé : le guichet accepte ;
  - (u) **remède de groupe** : groupe de 3 équipes, un membre verrouillé à part → « supprimez le groupe », **sans « sortez-en l'équipe »** ; verrou de groupe tenu + verrou individuel (I + G > S) → pas de remède de groupe ; équipe membre de deux groupes effectifs → pas de remède de groupe ; **case qui réunit deux groupes partageant une équipe (2d)** : le membre du second groupe, à 1 séance, est nommé « (1 séance de groupe, 1 séance verrouillée) » ;
  - (v) **plafond (point 55)** ⟦QO-3⟧ : groupe à 1 séance commune, un membre verrouillé sur 2 cases où l'autre est par des séances libres avec des séances à donner. Membre verrouillé à 2 séances : aucune phrase de la famille 2. À 1 séance : « 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, ou augmentez », sans remède de groupe. **Les deux membres verrouillés ensemble sur 2 cases** : la première phrase rendue est celle du blocage existant « réservé sur 2 cases » ;
  - (w) **présence libre conditionnelle (2a)** ⟦QO-1⟧, trois guichets : deux membres à 1 séance, 1 séance commune, la version chargée les porte ensemble sur deux cases, chacun verrouillé sur une case différente → 422, les deux nommés, « 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance, supprimez le groupe », aucune version créée. Un seul cadenas ôté : aucun blocage, le guichet accepte ;
  - (x) **groupe de trois dont un membre est ailleurs (2e)** : deux membres verrouillés ensemble, le troisième verrouillé sur une autre case, 1 séance chacun → les trois nommés avec « supprimez le groupe » ; groupe supprimé : aucun blocage.
  - **Tests existants dont la phrase ou l'appel change** : `testOverbookingStaysVisibleOnEntitiesWithoutAPayload` (`:317-327`) : la phrase `:326` devient « U11 A a 3 séances comptées pour 1 déclarée (1 séance de groupe, 2 réservations) : retirez 2 réservations, ou augmentez le nombre de séances de l'équipe. », l'appel `:323` change de signature : **`tank` le réécrit**. `testOverbookingIsReadOnThePeriodVolume` (`:244-267`) : phrase `:266` inchangée. `SharedTrainingBlockStateProcessorTest.php:174`, `:192` : inchangés.
- **A5, logique à nombreux cas et abus, en gate.** Neuf : `backend/tests/Integration/Service/TeamReservedSlotsTest.php`, `#[Group('phase1')]`, ajouté au step « Blocking suite » et à `blocking-tests.md` (axe tenant isolation).
  - deux sortes ; HARD/RESERVATION jamais compté ; UNKNOWN compté comme `lock` ;
  - même case, même équipe : réservation + verrou = 1, étiquette `reservation` ;
  - séance à `sharedTrainingBlockId` : `inGroup`, `blockId` renseigné ;
  - case qui réunit le groupe, un membre par un cadenas, l'autre par une réservation à son seul nom : `inGroup` pour les deux ;
  - **cadenas sur un seul membre, l'autre présent par une séance non verrouillée et sans autre créneau tenu : `inGroup`** ; ⟦QO-1⟧ **le même, l'autre ayant toutes ses séances tenues ailleurs : individuel** ; **l'autre à 2 séances dont 1 tenue ailleurs : `inGroup`** ;
  - cadenas sur un seul membre, l'autre ailleurs et libre : individuel ; `individualUsed` 1, `residual` 0 (équipe à 1 séance) ; `individualUsed` 1, `residual` 1 (2 séances dont 1 en groupe) ;
  - l'autre membre a toutes ses séances réservées ailleurs : le verrou est individuel ; la réservation de l'autre aussi ;
  - groupe réuni sur une autre case de la version chargée : le verrou est individuel ; `individualUsed` 2, `residual` 1 ;
  - **plafond** ⟦QO-3⟧ : groupe à 1 séance commune réuni sur 2 cases → la première (jour, heure, gymnase) est `inGroup`, la seconde non ; `groupLockedCount` 1, `lockedCount` 1 ; groupe à 2 séances communes réuni sur 2 cases → les deux `inGroup` ; une case réservée complète passe avant une case réunie par des verrous ;
  - **une case, deux groupes (2d)** : T dans G1 avec P et dans G2 avec Q, tous trois verrouillés sur la même case → `blockId` = G1 pour T et P, le créneau de Q est individuel ;
  - **cases tenues par des verrous (2c)** : `lockHeldGroupCases` rend 1 pour un groupe dont les deux membres sont verrouillés sur la même case ; 0 si un des deux y est par une séance libre ; 0 pour une case tenue par des réservations seules ; 1 pour une case tenue par une réservation et un verrou ;
  - **membre en pause** (période), groupe de 2 : l'autre a `block` 0, `inBlock` faux ; groupe de trois dont un en pause, les deux actifs sur la case : `inGroup`, `block` 1 ; pause levée : `block` 1 pour tous ;
  - **réduction** : transcription réelle : les deux verrous individuels, `individualUsed` 1 et `residual` 0 pour les deux ;
  - **groupe séparé en saison (4a)** : transcription réelle : deux verrous individuels ;
  - réservation à son seul nom, groupe réuni nulle part : individuelle ; réservation sans équipe : ignorée ;
  - socle, période, aucune version : périmètres ; version reprise : toutes les séances copiées sortent en `lock` ;
  - **gymnase désactivé pour la période** : la maison rend ses réservations et ses séances verrouillées ; le budget les compte ;
  - `excludedSlotIds` : la séance sort du compte ;
  - **abus, club étranger**, quatre chemins : `forScope(clubA, saisonA, planDuClubB)` ne rend ni réservation ni séance du club B ; une version du club B passée comme version chargée ne rend aucune séance (filtre tenant inactif) ; une pause posée par le club B sur le même identifiant de plan n'est pas lue ; `GET /api/team_solo_budgets` du club A avec le plan du club B est refusé ;
  - budget : `individualUsed` = somme des sortes individuelles ; `reservedTotal` ; `groupLockedCount` ; `groupLockHeld` ; mutualiser depuis la fiche une séance déjà verrouillée n'est pas refusé ; déclarer un groupe de deux équipes à 1 séance verrouillées sur deux cases est refusé, les deux nommées ; sur la même case : accepté ; l'exemple du §3.6 a refusé, le même sans la réservation accepté ; déclarer en période un groupe dont un membre est en pause ne compte aucune séance de groupe pour l'autre ; **`PUT` qui baisse les séances communes d'un groupe dont un membre tient un créneau individuel en trop : refusé ; le même après déverrouillage : accepté** ⟦QO-2⟧ ;
  - règle f : équipe dont le seul partenaire est en pause : réservation individuelle acceptée ;
  - **garde de réservation de groupe, et abus du sélecteur contourné (4b, 2c)** : groupe dont la séance commune est tenue par des verrous (deux membres à 2 séances, même case) → réservation de groupe sur une autre case refusée 422, phrase « dont 1 verrouillée(s) au planning » ; membre dont toutes les séances sont verrouillées à part → refusée, phrase du plafond par membre ; sans verrou : résultats et textes d'aujourd'hui.
- **Test existant complété, hors gate : le seed.** `backend/tests/Integration/Seed/BcclSeederIdempotenceTest.php`, garde `:1523-1568` : `tank` ajoute à la facette 1 la condition `block > effective` comme violation, même message, même consigne « à rapporter ». Joué par `make -C backend tests-complete`. `BcclSeeder` n'est pas modifié. Rouge : retour en validation (§9).
- **Test existant qui lit la roadmap** : `RoadmapIdentityTest`, hors liste bloquante (constat 89). `neo` le joue seul (`make -C backend phpunit F=tests/Unit/Documentation/RoadmapIdentityTest.php`) après avoir pris ses numéros sur le lot rebasé, puis par `tests-complete`.

**Preuve = captures (`switch`), PR B, sans test** : sélecteur de réservation dans ses états du §7, état « de trop » (hors groupe, « groupe compris », cas U11), équipe dont le partenaire est en pause, ligne d'aide de période, ligne « séance du groupe verrouillée », **liste ouverte avec un groupe grisé pour chacune des raisons (pause, séance commune posée, séance commune verrouillée, membre au plafond avec 1 et 2 verrous), un groupe posable à côté d'un groupe grisé, et la liste dont toutes les options sont grisées** ; Récap avec la famille 1 et la famille 2 (cas U11, deux équipes sur deux lignes, 0 séance hors groupe, verrous seuls, phrase validée de Q2, phrase « d'abord »).

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
- **A4 et A5 complétés** : troisième sorte dans chaque famille de cas, phrases « verrouillée ou déplacée » ; la famille 1 reste inchangée.

**Preuve = captures (`switch`), PR D, sans test** : T1 (1 et N), dialogue d'éviction avec paragraphe, toast T2, aide T3, fiche après régénération ; sélecteur avec « déplacé manuellement ».

**Non-régression de sémantique des contraintes** : A4, A6, S8 bis (B) ; S1, S3 (D).

## 9. Checklist

- **Zones** : backend, frontend, plus un fichier de doc sous `engine/docs/` [D].
- **Autorisés** : `GenerationBlockersGuard.php` (signatures, sortie `:264-266` retirée, phrases) ; `ValidateConstraintsController.php:222-223` et la construction de la portée ; les trois guichets pour T1/T2 [D] seulement ; `SoloReservationBudget.php`, `SoloBudget.php`, `ReservationGroupOccupancy.php` (`:185-187`, `:197-199`, `:365-375`), `SharedTrainingBlockStateProcessor.php` (`:229-251`), `TeamSoloBudgetResource.php`, `MutualizeSlotService.php` (`:704-717` et `:203`) ; côté écran `features/wizard/`, dont `lib/groupReservation.ts` pour les seuls contrôles « séances communes posées » (`:99-102`) et « membre au plafond » (`:104-115`) ; **`shared/components/ui/team-select.tsx` pour les deux champs optionnels de `mutualisationGroups` (`:45`, `:83`), rien d'autre**.
- **Interdits** : code de `engine/`, `landing/`, `system-pages/`, `BcclSeeder`, `GenerateScheduleHandler.php`, `ScheduleConstraintBuilder.php` (lu, tenu par A6), `SchedulePlanProvisioner.php`, `PeriodPlanTranscriber.php`, `GenerateStep.tsx`, `PeriodConstraintSelector.php`, `PlanVenueClosures`, `PeriodVenues.tsx` (4c), `TeamPeriodOverrideStateProcessor.php` et `TeamStateProcessor.php` (aucune garde à la baisse des séances), `PeriodPlanSiblingCopier.php` (sauf la recopie de la mémoire [D], boucle `:147-162`), `OrphanPinGuard.php`, `StructureSnapshotter.php`, `ScheduleSlotTemplateResource.php`, `LoadLocksController.php`, `ReservationRemoval::remove`, `ParcImport/*`, `PayloadCapacityMirror`, `PreSolvePreventionWarnings.php`, `DiagnosticMessageBuilder.php`, `DiagnosticsPanel.tsx`, `SharedTrainingBlockPanel.tsx`, `PeriodTeams.tsx`, `shared/components/ui/listbox.tsx`, le reste de `team-select.tsx`, dans `groupReservation.ts` la pause et `postedGroupOnSlot`, `SlotDetail.tsx` pour ses étiquettes d'origine, le docblock de tête, `groupCompleteCaseCount`, la garde `:253-255` du `PUT` et `blockMemberSetsInScope` de `ReservationGroupOccupancy.php` / du processor, `GroupReservationController.php`, module matchs, occupations de gymnase (PR 6), `test-time-ceiling.json`, tout code lié à P4-586 et P4-452, **tout travail sous la largeur de la tablette**.
- **Fichiers probables, PR B** : neufs `Service/TeamReservedSlots.php`, `ReservedSlot.php`, `BlockerScope.php`, `ActiveSharedBlocks.php` ; `Service/SoloReservationBudget.php`, `SoloBudget.php`, `ReservationGroupOccupancy.php`, `GenerationBlockersGuard.php`, `MutualizeSlotService.php` ; `State/Processor/SharedTrainingBlockStateProcessor.php` ; `ApiResource/TeamSoloBudgetResource.php` ; `Controller/ValidateConstraintsController.php` ; `behat.dist.php` ; `.github/workflows/ci.yml` ; `specs/courantes/openapi-snapshot.json` et `.meta.md` ; frontend `features/wizard/api.ts`, `texts.ts`, `lib/groupReservation.ts`, `steps/SlotReservationModal.tsx`, `shared/components/ui/team-select.tsx`, `features/planning/queries.ts`. **Aucune migration.**
- **Fichiers probables, PR D** : une migration ; `Entity/ScheduleSlotTemplate.php` ; neufs `Service/ManualMoveMemory.php`, `MovedReservationRelease.php`, `Exception/VersionNotEditableException.php` ; `Service/ReservationRemoval.php`, `MoveSlotService.php`, `ManualEditService.php`, `PeriodPlanSiblingCopier.php`, `ScheduleResultImporter.php` ; `Service/TeamReservedSlots.php`, `ReservedSlot.php`, `SoloBudget.php`, `SoloReservationBudget.php`, `GenerationBlockersGuard.php`, `ReservationGroupOccupancy.php`, `SharedTrainingBlockStateProcessor.php`, `MutualizeSlotService.php`, `TeamSoloBudgetResource.php` ; `Controller/ManualEditController.php`, `RegenerateController.php`, `GenerateScheduleController.php`, `FillPeriodPlanController.php` ; `OpenApi/PathContributor/ManualEditPaths.php` ; `openapi-snapshot.json` et son `.meta.md` ; `.github/workflows/ci.yml` ; frontend `features/planning/api.ts`, `lib/useRetouchGestures.ts`, `PlanningPage.tsx`, `EvictConfirmDialog.tsx`, `SlotDetail.tsx` (aide T3 seule), `planning/texts.ts`, `wizard/api.ts`, `wizard/texts.ts`, `wizard/lib/groupReservation.ts`, `SlotReservationModal.tsx`.
- **Fichiers de tests probables** : B — feature neuve, `tests/Behat/TrainingBlockContext.php`, A5, A6 (neufs), `GenerationRefusesARecapBlockerTest.php`, `BcclSeederIdempotenceTest.php` (une condition), `SlotReservationModal.test.tsx`, `groupReservation.test.ts`, `team-select.test.tsx`, le test des requêtes du planning. D — les deux features modifiées, `tests/Behat/SeasonWorkLoopContext.php`, `PeriodLockSmallClubContext.php`, A1, A2 (neufs), A4 et A5 complétés, `useRetouchGestures.test.tsx`, `SlotReservationModal.test.tsx`, `groupReservation.test.ts`. Adaptation mécanique par `neo` : les mocks de `TeamSoloBudget` gagnent les quatre champs obligatoires (`ConstraintsStep.test.tsx`, `reservationSlots.test.ts`, et tout fichier qui en fabrique). À relire par `tank` : `RegenerateTest.php`, les quatre `Slot*VerdictTest`, `MutualizeSlotApiTest.php`, `TeamSoloBudgetApiTest.php`, `SharedTrainingBlockStateProcessorTest.php`, `ReservationApiTest.php`, `GroupReservationApiTest.php`, `ParcImportAnalyzeTest.php`, `ValidateConstraintsTest.php`, et tout test qui pose une pause sur un membre de groupe, baisse les séances d'un membre de groupe, ou modifie un groupe par `PUT` dans un décor qui porte des séances verrouillées.
- **Documentation** :
  - ADR-0002 (amendement : créneaux réservés lus sur la version chargée ; règle de comptage, sa raison de produit, « membres actifs seulement », présence, attribution, plafond, séances communes en trop [B] ; verrou différé [D]) ;
  - ADR-0004 (séances copiées comptées ; période copiée née bloquée) [B] ;
  - `docs/glossary.md` (« créneau réservé », « séance verrouillée », « séances comptées », « groupe effectif », « présence » [B], « déplacé manuellement » [D]) ;
  - `docs/testing/blocking-tests.md` (A5, A6 [B], A2 [D], ligne `:221` complétée) ; `docs/testing/test-coverage-map.md` ;
  - `engine/docs/constraint-vocabulary.md` (phrase après `:441`) [D] ;
  - `backend/docs/error-copy.md` si les phrases du §7 y sont recensées ;
  - `.claude/rules/frontend.md` si la règle du sélecteur d'équipe y décrit `mutualisationGroups` (à regarder par `documentation-update`) ;
  - `specs/courantes/openapi-snapshot.json` et `.meta.md` (régénérés, B et D) ;
  - table feature↔zone de `.claude/skills/validation-runner/SKILL.md` ;
  - `specs/courantes/etat-des-lieux.md` : les décisions du 2026-11-17 (règle de comptage, 4a, 4b grisé, 4c, Q2, et les réponses à QO-1, QO-2, QO-3), la décision « rien sous la tablette » avec ses deux lignes retirées [B] ;
  - **`specs/evolution/roadmap.md`, PR B** : retirer P4-586 (`:117`) et P4-452 (`:190`) ; compléter P4-601 et P4-459 ; ajouter les 9 lignes neuves, chacune « premier numéro libre au merge » ; ajuster le compteur du titre. **PR D** : ses quatre lignes, même règle.
- **Retour en validation si** :
  - A6 ne peut pas être vert sans modifier `ScheduleConstraintBuilder` ;
  - `BcclSeederIdempotenceTest` rougit, sur l'ancienne condition ou sur la neuve (`block > effective`) : on rapporte la portée, l'équipe et son compte ; on ne touche pas au seed ;
  - `un-verrou-de-periode…:17`, `l-unite-de-placement-est-le-bloc.feature`, `plan-de-periode-en-overlay.feature` ou `une-fermeture-adapte-le-planning.feature` rougit parce que son décor porte un groupe non réuni dans la version chargée, une réduction, ou un membre sous les séances communes de son groupe ;
  - le témoin A4 (f) rougit, ou sa première phrase n'est plus celle du blocage existant ;
  - A4 (q) ne peut pas rester octet pour octet ;
  - A4 (t) ne peut pas être monté sans modifier `PeriodPlanTranscriber` ;
  - A4 (s) : le décor ne peut pas baisser les séances d'un membre sous les séances communes de son groupe ; **ou le `PUT` après déverrouillage est encore refusé** (le remède de QO-2 serait faux) ;
  - **A4 (w) : après un seul cadenas ôté le Récap nomme encore une équipe** ;
  - un test existant de réservation, de groupe, de mutualisation ou d'import rougit au-delà d'une réécriture de phrase, pour une des causes du §3.7 ou parce qu'un `PUT` de groupe y est refusé par la garde élargie ;
  - le Récap du club du bac à sable n'est pas lisible pour l'équipe jetable, ou il est rouge sur une équipe du seed ;
  - la version chargée du plan de saison du seed ne peut pas recevoir le décor ;
  - **le champ `groupLockHeld` (liste d'objets) ne passe pas les trois gardes du contrat OpenAPI** ;
  - **`switch` montre que la sous-ligne d'une option grisée ne tient pas dans la liste** (raison la plus longue) ;
  - [D] le pas neuf de S2 ne peut pas réserver une équipe sur la case où elle siège, socle rouvert ;
  - [D] un déplacement du décor est refusé `baseline_infeasible` alors qu'il précède la contrainte SM1 ;
  - [D] le verdict refuse encore un déplacement de séance réservée une fois ses réservations écartées ;
  - [D] `flushInvalidations()` après commit ne rend pas le payload frais ;
  - [D] la garde du §3.3 fait rougir un test en gate au-delà d'un ajustement mécanique de décor ;
  - [D] `removeOnly` laisse une case bloc-complète dans un état que le Récap refuse ;
  - la mesure Behat dépasse l'enveloppe après les replis internes ci-dessous.
- **Aucun refactoring hors périmètre.** `blocksInScope` change de source, pas de forme ; `offerableGroups` garde sa signature ; `TeamSelect` gagne deux champs optionnels, aucun autre consommateur n'est touché ; `Listbox` n'est pas modifié.
- **Exécutants.**
  - PR B : `tank` (rouges S5 à S19, S21 à S34, A3 [B], A4, A5, A6, la condition du seed) puis `neo`.
  - PR D : `tank` (rouges S1 à S4, S20, A1, A2, A3 [D], A4 et A5 troisième sorte) puis `neo`.
- **Contre-revue** : `smith` obligatoire avant la validation du fondateur, sur cette v12, après les réponses aux trois questions. Points nommés : la règle de présence (QO-1), le remède ordonné de Q2 (QO-2), l'attribution d'une case à un seul groupe, le compte des cases tenues par des verrous, le retrait de « sortez-en l'équipe ».
- **Axes §7.1 touchés → NR** :
  - constraint semantics : A4 (gate), A6 (gate, neuf) [B] ; S1, S3 [D] ;
  - tenant isolation : A5 (gate, neuf) [B] ; A2 (d), (j) [D] ;
  - generation pipeline, planning lifecycle : S8 bis, A4 (s), (t), (w) [B] ; A2 (gate) [D] ;
  - périmètre engagé, contrat backend↔moteur, auth : non touchés.
  - Les fichiers ajoutés au step `ci.yml:578` gatent aussi les PR vers le lot (constat 83).
- **Tests d'abus hérités du cadrage** : club étranger → A5 (quatre chemins) [B], A2 (d), (j) [D] ; Membre → A2 (d) et `MemberWriteSweepTest` ; version non chargée → `LoadedVersionIsTheOnlyEditableTest`, A4 (c) [B], A2 (d) [D] ; retrait de réservation sans confirmation → A2 (i) [D] ; réservation posée par l'API en contournant le sélecteur → feature neuve, scénario 1, pas 5 [B] ; réservation de groupe posée par l'API en contournant l'option grisée → A5, garde de réservation de groupe et plafond par membre [B].
- **`/security-review`** : oui pour B (isolation entre clubs : lecture de séances par version, de groupes et de pauses par plan) ; oui pour D (données d'un club écrites par les guichets de génération).
- **e2e / Behat impactés** :
  - e2e : `frontend/tests/e2e/visual-reference.spec.ts` (« wizard contraintes », sélecteur fermé) : pas de re-baseline attendue, à confirmer par `switch`. `member-read-only.spec.ts` : aucun écran d'écriture neuf. `mutualize.spec.ts` : à rejouer. `a11y-contrast.spec.ts` (`:380`, modale de réservation) : à rejouer, une option grisée de plus y figure.
  - Behat neuve : `les-creneaux-reserves-ne-depassent-pas-les-seances.feature` [B] ; modifiées : `la-boucle-de-travail-du-gestionnaire.feature`, `un-verrou-de-periode-survit-a-la-regeneration.feature` [D].
  - Behat à rejouer sans modification : `l-unite-de-placement-est-le-bloc.feature`, `un-verrou-est-souverain.feature`, `plan-de-periode-en-overlay.feature`, `une-fermeture-adapte-le-planning.feature`, `retirer-une-seance-placee.feature`, `generation-du-planning-de-saison.feature`, `l-import-du-parc-en-un-geste.feature`, `une-equipe-s-entraine-deux-fois-le-meme-jour.feature`.
- **Feature qui prouve la zone** : la feature neuve (B) ; `la-boucle-de-travail-du-gestionnaire.feature` (D).
- **Contrôle visuel `switch`** (clair et sombre, 1280 px ; bureau seulement) :
  - B — étape Réservations, sélecteur ouvert : équipe réservable sans détail, avec détail, à 0 « placés », à 0 « pris », « hors groupe sont pris », « 1 créneau de trop », « groupe compris » (dont le cas U11), « séance du groupe verrouillée », partenaire en pause, **groupe grisé pour chaque raison, liste entièrement grisée**, période copiée avec la ligne d'aide, saison. Récap : famille 1 ; famille 2 avec le cas U11, deux équipes sur deux lignes, 0 séance hors groupe, verrous seuls, phrase validée de Q2, phrase « d'abord ».
  - D — planning : T1 à 1 et à 3 réservations, dialogue d'éviction, fiche d'une séance réservée (T3), toast T2, fiche après régénération ; sélecteur avec « déplacé manuellement ».
- **Plafond de temps.** Behat : plafond 950 s.
  1. **Mesure** : stack d'épreuve, dans le conteneur PHP, `vendor/bin/behat --suite=<suite> <feature>[:ligne]` (constat 63), deux passages, on retient le plus court. On mesure les scénarios ajoutés ou modifiés.
  2. **PR B** : la feature neuve seule, après le vert de `neo`. **PR D** : `la-boucle…:12` et `un-verrou-de-periode…:9`, avant les rouges et après le vert ; charge = somme des deux écarts.
  3. **Le corps de la PR porte la ligne** : « Behat, stack d'épreuve, scénarios ajoutés ou modifiés, meilleur de deux passages : <feature>[:ligne] A→B s, …, charge +N s. » L'orchestrateur l'inscrit au registre du lot (constat 93).
  4. **Enveloppe : 15 s pour B, 25 s pour D.** Au-delà, avant de proposer le merge : le scénario 1 de la feature neuve passe en A4/A5 (B) ; S3 se réduit à son assertion finale, puis les assertions de budget de `la-boucle…` passent en A5 (D). Ces replis ne touchent que des scénarios de ce chantier, avant leur merge.
  5. **Après le merge de B et D, aucun repli ne retouche un scénario mergé : c'est la décision du fondateur (Q-B1).** Si la suite dépasse à la PR finale, le seul recours restant est le plafond relevé sur sa ligne datée (`scripts/suite-time.py:10-14`), une fois l'excès mesuré. Ce plan ne demande pas cette ligne.
  - backend (1000 s, médiane 577 s d'après le plan PR 2) : quatre fichiers neufs et une quarantaine de cas, sans appel au moteur ; frontend (230 s, médiane 129 s) : vingt-deux cas ; engine et e2e : rien.
- **Régime CI** : PR vers `lot/double-contexte`, seul « Lot Gate » (liste bloquante comprise). Avant chaque merge, en local : `/validation-runner`, `make -C backend tests-complete` (il joue le test du seed, `RoadmapIdentityTest` et les trois tests du contrat OpenAPI, qu'aucun job du lot ne joue), `make -C backend rector`, `make -C frontend test` et `lint` ; sur la stack d'épreuve : `bloc` et `verrou-periode` (B), `boucle-travail` et `verrou-periode` (D), plus les features « à rejouer » qui posent un groupe dans une période.
- **Ordre dans le lot et fichiers partagés** (constat 88) : l'ordre des PR restantes appartient à l'orchestrateur. **B ne s'ouvre jamais en même temps que** ENG-60, 2a, 2b ou 2c ; **D** ajoute `la-boucle…`, `SeasonWorkLoopContext.php`, `MoveSlotService.php` (ENG-60) et `engine/docs/constraint-vocabulary.md` (2c). Avant d'ouvrir, `git diff --name-only origin/lot/double-contexte...HEAD` contre `gh pr diff <n> --name-only` des PR ouvertes de même cible. Si ENG-60 ou une PR 2 merge avant B, les lignes de ce plan se recalent avant les rouges.
- **Découpage** : deux PR, tranché. B s'ouvre la première ; D attend le merge de B, rebasée et re-validée. Raison réelle : PR trop grosse à relire en une fois, et B se merge seule.
- **Plan court** : ce plan dépasse une page par PR. La roadmap de B passe de 21 à 9 lignes neuves ; rien d'autre n'est retiré du périmètre en v12.

## 10. Risques

1. **Cache périmé au lancement** [D] : gardé par A2 (g).
2. **Clubs bloqués à la livraison** (§3.7). Pas de mesure en production.
3. **Compte élargi dans des gardes que personne n'a demandées** : import du parc, réservation de groupe, déclaration et modification de groupe.
4. **Version plus ancienne chargée après coup.** Période : une séance HARD/RESERVATION dont la réservation a été retirée reste épinglée et n'est pas comptée. Roadmap.
5. **File bloquée** [D] : une version PENDING orpheline bloque les déplacements jusqu'au chien de garde.
6. **Fenêtre T1-T2** [D] : second passage non testé.
7. **Refus 422 jamais annoncé par le Récap** et **verrou qui l'emporte sur une règle ajoutée depuis** (C).
8. **Saison, créneau supprimé sous une séance déplacée** : aucun refus (C).
9. **Décor de S1/S2** : l'ordre des pas n'a pas été joué.
10. **Phrase du guichet égale à celle du Récap** [D] : tenue parce que le texte ne sépare pas verrouillée de déplacée.
11. **Coût de lecture** : le budget et le Récap lisent toutes les séances de la version chargée, les groupes et les pauses du plan. Non mesuré.
12. **Un cadenas est « de groupe » par la seule présence du partenaire, et cette présence dépend de ce que le partenaire tient ailleurs.** Le même cadenas change de classe quand le partenaire est déplacé, verrouillé ou réservé ailleurs. La cause n'est pas nommée par la phrase.
13. **Période copiée née bloquée** (réduction, groupe séparé en saison, membre réactivé). La phrase dit « supprimez le groupe » ; elle ne dit pas que ce seul geste règle les deux équipes.
14. **Panne de la lecture** : la génération part sans la garde ; l'écran n'offre plus aucune équipe.
15. **Comblement** : la règle sous-compte pour `/fill`.
16. **Bac à sable** : aucun pas ne doit lancer de génération sur le club du seed.
17. **Libellé « verrouillé »** : il ne dit pas d'où vient le verrou.
18. **Deux lectures de « qui est en pause »**. Tenu par A6.
19. **Gymnase désactivé : l'écran et le Récap ne comptent pas la même chose, et B élargit l'écart aux verrous.** P4-601.
20. **Marge Behat du lot** : la somme des enveloppes est de l'ordre de la marge ; après B et D, seul le plafond sur ligne datée reste (Q-B1).
21. **Deux PR** : la famille 2 du Récap change entre B et D ; A4 et A5 sont rouverts.
22. **Le budget change pour des équipes qui n'ont aucun verrou** (membres actifs).
23. **Décors Behat existants** : non relus pour un groupe non réuni, une réduction, un membre sous ses séances communes (§11).
24. **Un refus que le moteur n'aurait pas opposé.** Assumé par la décision.
25. **État « de trop » visible pour des données d'aujourd'hui.**
26. **Séances communes en trop (Q2) : seul le Récap le dit.**
27. **Déclarer un groupe dans une période copiée demande deux temps.**
28. **Une saison au groupe séparé rend bloquée chaque fermeture copiée d'elle (4a).**
29. **L'option de groupe lit des comptes du serveur, pas du brouillon.** Rattrapé par le 422.
30. **Le remède « supprimez le groupe » est dit même quand le gestionnaire tient au groupe.** En période il retire aussi les réservations et séances liées.
31. **Fichiers partagés** : `openapi-snapshot.json` se régénère, il ne se fusionne pas.
32. **Q2 quand l'équipe tient trop de créneaux : deux gestes dans un ordre imposé.** Le mauvais ordre répond un refus dont le mot « mutualisation » ne décrit pas le geste (baisser des séances communes). La phrase du Récap porte l'ordre ; le refus reste lisible mais imparfait.
33. **Le plafond et l'attribution dépendent d'un ordre (jour, heure, gymnase, identifiant de groupe) que l'écran ne montre pas.**
34. **Le grisé change aussi l'affichage de trois raisons existantes** (pause, séances communes posées, membre au plafond sans verrou), qui quittent le dessous du sélecteur pour la liste. Un test existant est réécrit. À voir sur captures.
35. **Roadmap** : `RoadmapIdentityTest` ne gate pas les PR vers le lot ; une collision de numéro entre deux PR du lot ne se voit qu'au `tests-complete` local ou à la PR finale.
36. **La garde élargie du `PUT` ferme le crayon du groupe à toute équipe déjà en dépassement** (constat 94) : retirer un membre, baisser les séances communes, renommer le groupe sont refusés tant qu'un membre tient un créneau individuel en trop. Le Récap ne propose que des gestes qui passent ; un gestionnaire qui tente autre chose lit le refus du §7.
37. **Le crayon accepte encore de baisser les séances communes sous le nombre de cases où le groupe est tenu par des verrous** : le Récap le refuse ensuite par « réservé sur N cases », phrase qui parle de réservations.
38. **La règle de présence ajoute une condition que le gestionnaire ne voit pas** (« il lui reste une séance à donner »). Deux écrans identiques peuvent donner deux verdicts selon ce que le partenaire tient ailleurs.
39. **Trois réponses du fondateur conditionnent des rouges** : toute réponse autre que la recommandation demande une passe de plan sur les sections marquées.

## 11. Non vérifié

- Rien n'a été exécuté. Aucun geste n'a été joué à l'écran.
- **Le geste de Q2 à l'écran**, et ce que le moteur fait aujourd'hui de ce cas : non joués.
- **Le cas de `smith` (2a)** : le Récap et la séparation par le moteur sont lus (constats 48, 65), non joués.
- **Que le `PUT` passe après un cadenas ôté** (2b) : calculé sur la garde lue (constat 94) et sur les définitions de ce plan, non joué ; A4 (s) et A5 le prouvent.
- **Que le crayon ouvre la liste « séances communes » sur 1 quand un membre est à 1 séance** : lu (`SharedTrainingBlockPanel.tsx:98-102`), non joué.
- **Que deux membres d'un groupe à 1 séance commune se retrouvent ensemble sur 2 cases** : raisonnement.
- **Qu'une liste d'objets sur `TeamSoloBudgetResource` passe les gardes OpenAPI** : la forme existe ailleurs (constat 96), non essayée ici.
- **Que le seed ne porte aucune équipe sous ses séances communes** (constat 81).
- **Les décors des features Behat existantes** : non relus pour un groupe non réuni, une réduction ou un membre sous ses séances communes.
- **Le Récap du bac à sable avec la règle** : non calculé.
- **Qu'un calcul de saison sépare réellement un groupe** (constat 48) : lu ; A4 (t) pose la version à la main.
- **Si déverrouiller puis régénérer une période née d'un groupe séparé redonne un groupe séparé.**
- **Si reposer la séance « à replacer » d'un membre réduit sur la case de son partenaire est accepté.**
- **Si déplacer un membre libre sur la case de son partenaire verrouillé est accepté** : le plan ne le donne nulle part comme remède.
- **Si la validation d'une version de période consulte le Récap.**
- **Gymnase désactivé** : rien de joué (constat 82).
- **Quels ordres de gestes mènent à l'état « sans verrou » du §3.7.**
- **Le bloc sur-épinglé par un verrou et une réservation est bien dans le payload comme deux épingles HARD** : lu.
- **La commande Behat à chemin de feature ou de scénario** sur la stack d'épreuve : non essayée.
- **La place disponible** pour le texte « de trop » le plus long et pour la sous-ligne d'un groupe grisé : `switch`.
- Le refus effectif, par le moteur, d'un déplacement jugé contre sa propre réservation.
- L'infaisabilité du décor par le seul créneau libre (constat 17).
- Le texte du diagnostic « la réservation gagne » (parcours 14).
- Acceptation par `ReservationStateProcessor` d'une réservation d'équipe sur la case où elle siège, socle rouvert.
- Garde du retrait par l'API ; couverture de `MemberWriteSweepTest` pour les routes de retouche.
- La phrase exacte de A4 (f).
- La signature de `PlanVenueClosures::effectiveStateForEntry` au-delà de son appel.
- **Si `move` accepte de déplacer seul un membre d'une séance de groupe née d'une génération** (A2 (k)).
- Si la suppression de l'entrée de période emporte les réservations du plan, et celle du gymnase ses créneaux.
- Si `live_context_schedule_id` du seed pointe la dernière version COMPLETED.
- **Le contenu réel des PR 2** ; l'état d'ENG-60 après son rebase ; l'absence de PR ouverte (lue en v11, non relue).
- **Si `.claude/rules/frontend.md` décrit `mutualisationGroups`** : seules ses lignes `:140-150` ont été relues.
- Lignes non recalées : `engine/app/main.py:664-670`, `engine/app/solver/constraints/common.py:184-192`.
- La durée des pas Behat ajoutés ; les temps des suites depuis les merges de 1a, PR 6 et 1b.
- Si l'étape Réservations est ouverte en écriture quand la version chargée est en vigueur.
- Le fichier de test des requêtes du planning. `ReservationGrid.tsx` et `ConstraintsStep.tsx` n'ont pas été lus.
- Constats 1 à 15, 18, 20 à 24, 28, 29, 33 : repris sans relecture de leur contenu.

## 12. Détails tranchés par moi (contestables) et hors périmètre

1. Mémoire en deux colonnes sur la séance, non exposée par l'API.
2. Deux transactions par guichet, second passage non testé.
3. Garde côté geste sous `lockPlanScope`, version chargée revérifiée.
4. `removeOnly` vit dans `ReservationRemoval`.
5. Une seule formulation de T1 pour les formes (a) et (b).
6. Si le nombre de réservations relu diffère du nombre confirmé, le serveur retire ce qu'il trouve et le toast dit le nombre réel.
7. L'écran relit les séances après un refus 422.
8. Une maison neuve `TeamReservedSlots`, lue par le budget et par le Récap.
9. Un verrou d'origine inconnue compte comme « verrouillé ».
10. Dédoublonnage par (équipe, case).
11. **Une case « réunit le groupe » par présence, toutes sources confondues, et par inclusion ; une séance non verrouillée n'y compte que si l'équipe a encore une séance à donner.** La seconde moitié est la question QO-1.
12. « N'est plus proposée » = listée mais désactivée avec le détail.
13. **Le texte d'aujourd'hui reste mot pour mot dans un seul cas : équipe sans groupe effectif, réservations seules** (famille 1).
14. [D] Le Récap dit « verrouillée ou déplacée » sans les séparer ; le sélecteur les sépare.
15. Le sélecteur dit « verrouillé », sans « manuellement ».
16. `individualUsed` garde son nom dans l'API.
17. Le plafond par membre lit le total des créneaux réservés contre S.
18. L'import du parc n'est pas modifié.
19. `overBookedOnEntities` lit l'override de période et écarte les équipes en pause.
20. La grille de l'étape Réservations ne montre pas les cases tenues par un verrou.
21. Les deux gardes d'écriture comptent « existant + 1 » sans regarder si la case visée est déjà celle d'un verrou de l'équipe. Exemple : U13F verrouillée lundi ; la réserver ce même lundi est refusé, il faut ôter le cadenas d'abord.
22. La ligne d'aide de période s'affiche dès qu'une équipe de la liste a un créneau verrouillé.
23. `BlockerScope` remplace le booléen `$forPeriod` et le couple club/saison.
24. A5 entre dans le gate.
25. La feature neuve rejoint la suite `bloc` et `TrainingBlockContext`.
26. Aucun appel de guichet de génération en Behat sur le club du bac à sable.
27. **Une équipe verrouillée à part de son groupe, mais qui a une séance hors groupe pour porter ce verrou, n'est pas bloquée.** Exemple : U15-1, 2 séances dont 1 en groupe, verrouillée seule le mardi : 2 pour 2.
28. **Réduction et groupe séparé en saison : ni garde à l'écriture des séances, ni correction de la reprise.** Décision du fondateur.
29. **Gymnases désactivés : le Récap applique aux séances verrouillées le périmètre qu'il applique déjà aux réservations ; le budget compte tout.**
30. **`blocksInScope` lit désormais les groupes effectifs.**
31. **Texte « la séance du groupe {nom} est verrouillée »** à la place de « passez par le groupe ».
32. **B dit « verrouillée », D dit « verrouillée ou déplacée ».**
33. **Enveloppe Behat : 15 s pour B, 25 s pour D.**
34. **A6 est un test de parité sans moteur**, dans un fichier neuf du gate.
35. **Déclarer un groupe sur deux équipes verrouillées à part est refusé.** Ce qui ferait basculer : préférer accepter la déclaration et laisser le Récap refuser la génération.
36. **S14 à S16, S24, S25, S27 à S33 restent des cas de A4 et A5, pas des scénarios Behat neufs** (logique à nombreux cas).
37. **Une seule famille neuve de phrase au Récap.**
38. **La phrase garde « , ou augmentez le nombre de séances de l'équipe ».**
39. **Le remède chiffre ce qu'il faut libérer**, sauf le cas mixte à 1.
40. **Le détail dit « 1 séance de groupe »** au Récap ; le sélecteur dit « 1 en groupe ».
41. Texte de l'état dépassé du sélecteur (§7). 42. Il vaut aussi pour des réservations seules. 43. La ligne « de trop » l'emporte.
44. Le docblock de `ReservationGroupOccupancy.php:40-44` n'est pas réécrit.
45. **`ScheduleConstraintBuilder` n'appelle pas la maison neuve : deux implémentations et un test de parité.**
46. La garde d'occupation des réservations de groupe n'est pas alignée (`:594-602`). Roadmap.
47. Le sélecteur n'a pas de texte pour les séances communes en trop (Q2).
48. **Les variantes de la phrase de Q2 sont dérivées par moi** : plusieurs groupes, pluriel, et la forme « d'abord … puis » (QO-2). La phrase validée reste mot pour mot dans son cas.
49. **« Griser » est fait à la lettre, et pour les quatre raisons** : un groupe indisponible est une option grisée de la liste, sa raison en sous-ligne ; la liste sous le sélecteur et « · indisponible » disparaissent. Exemple : parcours 20. Ce qui ferait basculer : vouloir griser la seule raison neuve et garder les trois autres sous le sélecteur, au prix de deux rendus pour la même chose.
50. **Le remède de groupe n'est dit que quand il suffit (un seul groupe effectif, I + G ≤ S).**
51. **« Sortez-en l'équipe » n'est écrit nulle part.** La v11 l'écrivait à partir de 3 équipes ; ce geste est refusé par la garde élargie quand l'équipe retirée n'est pas celle qui manque (2e). Exemple : U11-1 et U11-2 ensemble, U11-3 ailleurs : sortir U11-1 depuis la fiche de U11-2 répond 422. Ce qui ferait basculer : vouloir que le Récap nomme le membre absent à sortir (« sortez-en U11-3 »), au prix d'un calcul de plus et d'un remède qui n'est pas vrai dans tous les décors.
52. La raison « membre au plafond » nomme le premier membre trouvé et le nombre de ses verrous.
53. `lockedCount` et `groupLockedCount` sont les deux comptes par équipe ; `groupLockHeld` est le compte par groupe.
54. A4 (t) pose la version de saison séparée à la main, sans moteur.
55. **Plafond des créneaux de groupe** : question QO-3.
56. Le retrait de P4-586 et P4-452 se fait par suppression de ligne, et la décision se consigne dans l'état des lieux.
57. P4-601 et P4-459 sont complétées, pas doublées.
58. **Une case vaut pour un seul des groupes d'une équipe ; le premier par identifiant l'obtient ; ordre d'attribution : séances marquées, cases réservées complètes, cases tenues, cases réunies par une présence libre** (2d). Exemple : §3.6. Ce qui ferait basculer : vouloir le choix qui bloque le moins d'équipes, au prix d'un calcul plus lourd.
59. **Quand la séance commune d'un groupe est tenue par des verrous, la raison affichée est celle du groupe (« Sa séance commune est verrouillée au planning… »), avant la raison du membre au plafond.** Exemple : parcours 20.
60. **Les cases d'un groupe tenues par des verrous sont exposées par groupe sur la ligne de budget de chaque membre** (`groupLockHeld`), pas sur la ressource des groupes : la requête du budget est déjà celle que les gestes du planning invalident.
61. **La garde `:253-255` du `PUT` n'est pas élargie aux verrous** (risque 37) : hors des points de `smith`, et le Récap rattrape.
62. **Le test du seed gagne la condition `block > effective`** plutôt qu'un contrôle manuel : c'est le seul endroit qui lit tout le seed, et il rapporte sans corriger.
63. **Le nombre à libérer d'abord dans la branche Q2 est N = I + max(0, G − S).**
64. **Le sélecteur s'affiche même si toutes ses options sont grisées** : sinon les raisons ne seraient montrées nulle part.

Lignes de roadmap. Chaque ligne neuve porte « **premier numéro libre au merge** » : le numéro se lit sur la branche de lot rebasée (P4-593 à P4-601 sont pris ; PR 2 et ENG-60 en prendront). `RoadmapIdentityTest` refuse un doublon, mais seulement en `tests-complete` local ou à la PR finale (constat 89).

Retirées, PR B (« rien sous la tablette ») : **P4-586** et **P4-452**.

Complétées, PR B :
- **P4-601** : ajouter que depuis la règle des créneaux réservés les **séances verrouillées** d'un gymnase désactivé comptent aussi à l'écran ; les trois preuves à faire d'abord, en jouant le cas : (1) désactiver, à l'écran des gymnases d'une période, un gymnase qui porte une réservation et une séance verrouillée ; (2) la génération qui suit ; (3) la réactivation. Exemple : U13F, 1 séance, réservée au Platane ; le Platane est désactivé pour février : l'écran dit « Tous ses créneaux sont placés » et refuse de la réserver ailleurs. Ajouter : le commentaire de la garde des épingles orphelines dit que le Récap annonce ce surplus, le Récap le retire de son périmètre. **Corriger les deux plages citées** : `ValidateConstraintsController.php:452-455` (au lieu de `:444-447`) et `GenerationBlockersGuard.php:70-73` (au lieu de `:68-71`).
- **P4-459** : ajouter que le blocage « Groupe … : réservé sur N cases » dit « réservé » et « Retirez une réservation » même quand les cases sont tenues par des séances verrouillées, et que le crayon du groupe laisse baisser les séances communes sous ce nombre de cases.

Neuves, PR B (9 lignes) :
- « Refuser le cadenas quand l'équipe a déjà autant de séances comptées que de séances déclarées, groupe compris : c'est le geste fautif, refusé seulement à la génération. »
- « Rendre la régénération obligatoire après un changement de contrainte. »
- « Montrer, quand on réserve, quelle séance du planning chargé cette réservation remplace. »
- « Copie d'une période sans verrous : la reprise du planning de saison ne verrouille plus les séances copiées (ADR-0004 et ADR-0002). »
- « La reprise du planning de saison ignore les groupes : elle retire la dernière séance d'une équipe réduite même si c'est sa séance de groupe, et copie un groupe séparé par le calcul en deux verrous à part ; les membres naissent bloqués dans la période. »
- « Baisser les séances d'une équipe, en saison ou en période, n'est gardé nulle part contre les séances communes de ses groupes : le Récap le refuse à la génération, l'écran des équipes et le sélecteur de réservation ne disent rien au moment du geste. »
- « La garde des réservations de groupe lit encore les membres en pause : un groupe de trois dont un membre est en pause ne se réserve pas en groupe. »
- « Le comblement épingle aussi les séances libres de la version source : la règle des créneaux réservés ne les compte pas. »
- « En période, une séance verrouillée d'origine réservation dont la réservation n'existe plus reste épinglée et n'est pas comptée parmi les créneaux réservés. »

Neuves, PR D (4 lignes) :
- « Évincer ou retirer une séance libre posée sur sa réservation laisse la réservation. »
- « Poser ou ôter un cadenas, retirer une séance ou « Charger les verrous » entre le clic « Régénérer » et le démarrage du calcul : ces gestes ne regardent que le verrou du calcul en cours. »
- « Le Récap n'annonce pas les séances déplacées qui seront verrouillées au lancement, hors dépassement du nombre de séances. » (C)
- « En saison, une épingle sur un créneau supprimé ne refuse pas la génération. » (C)

Citée, non modifiée : P4-462 (fiche tronquée quand « Déplacer » est armé).

Laissé hors périmètre, volontairement : le refus du cadenas, la régénération obligatoire, la visibilité de ce qu'une réservation remplace, la copie de période sans verrous, toute création de lien de groupe à la copie, l'étiquette de la fiche du planning, P4-462, les gestes pendant la file d'attente, l'annonce générale des séances déplacées dans le Récap, l'épingle sans créneau en saison, la grille de réservation, les messages de l'import du parc, le sous-compte du comblement, la séance d'origine réservation orpheline en période, une garde à la baisse des séances, la reprise aveugle aux groupes, l'alignement de la garde des réservations de groupe sur les membres actifs, le texte du sélecteur pour des séances communes en trop, la garde du crayon contre les cases tenues par des verrous, un remède « sortez du groupe l'équipe absente », un refus du crayon limité à ce qui aggrave, tout ce qui touche les gymnases désactivés, le libellé du blocage « réservé sur N cases », le commentaire de la règle du budget solo, tout rattrapage des données déjà en surplus, toute modification du seed, tout code derrière P4-586 et P4-452, tout écran sous la largeur de la tablette.

## 13. Fichiers lus en v12

Base `origin/lot/double-contexte` 717a712ae, par `git show`. Rien extrait sur disque.
- `/home/marabou/projects/scheduler/.next/plan-deplacer-verrouille-v11-2026-11-17.md` (entier) ; le rapport de `smith` sur la v11 (entier, extrait du journal de l'agent)
- `/home/marabou/projects/scheduler/.next/plan-pr2-double-contexte-v11-2026-11-17.md` (recherche « repli », « registre », « Q-B1 » : `:15`, `:698-725`, `:887`, `:906`)
- `.claude/rules/backend.md` (entier), `frontend.md` (`:140-150`) ; `engine.md` et le reste de `frontend.md` repris de la v11
- `backend/src/State/Processor/SharedTrainingBlockStateProcessor.php` (entier), `TeamStateProcessor.php` (`:150-185`)
- `backend/src/Service/SoloReservationBudget.php` (entier), `ReservationGroupOccupancy.php` (`:1-435`), `GenerationBlockersGuard.php` (`:45-140`, `:255-296`), `PreSolvePreventionWarnings.php` (`:1074-1175`, `:1215-1245`), `PeriodPlanTranscriber.php` (`:150-230`)
- `backend/src/Dto/SharedTrainingBlockInput.php` (entier), `backend/src/ApiResource/TeamSoloBudgetResource.php` (entier), `backend/src/Controller/ManualEditController.php` (`:88-165`), `ValidateConstraintsController.php` (`:444-458`)
- `backend/tests/Integration/Seed/BcclSeederIdempotenceTest.php` (`:1510-1572`), `backend/src/Seed/BcclSeeder.php` (ancres des séances communes)
- `.github/workflows/ci.yml` (`:570-580`, `:895-901`, `:1366-1374`, `:1398-1430`, `:2400-2406`)
- `frontend/src/features/wizard/lib/groupReservation.ts` (entier), `steps/SlotReservationModal.tsx` (`:220-310`, `:715-760`), `SharedTrainingBlockPanel.tsx` (`:80-300`), `SlotReservationModal.test.tsx` (`:296-312`)
- `frontend/src/shared/components/ui/team-select.tsx` (entier), `listbox.tsx` (`:9-30`, ancres `disabled` et `sub`)
- `specs/evolution/roadmap.md` (titre, lignes P4-459, P4-601, mentions de 360 px)
- Tout le reste : repris de la v11, sans relecture.

## 14. Changements depuis la version précédente (v11)

| Correction reçue (`smith`, v11) | Section modifiée | État |
|---|---|---|
| 1. La tête dit « aucune question » alors que trois points sont renvoyés au fondateur | tête (recommandation NO-GO jusqu'aux réponses) ; « Avant le plan » ; bloc final « Questions réellement ouvertes » | appliquée : les questions sont posées comme questions ; les points déjà tranchés (tablette, grisé, Behat) sont dits tranchés |
| 2a (bloquant). Présence par une séance libre d'une équipe qui n'a plus de séance à donner | §1 (présence tenue, présence libre) ; « À lire en premier » ; §3.6 (bloc « Présence », cas de `smith`, dix cas, limites) ; §5 (une ligne) ; parcours 23 (variante) et 25 ; §7 (cas par cas) ; S29, A4 (w), A5 (cas réécrit et deux ajoutés) ; §9 (retour en validation) ; risques 12, 38 ; point 11 ; QO-1 | appliquée sur ma recommandation, **posée en question** |
| 2b (bloquant). Remède de Q2 refusé par le serveur en période copiée | constats 73, 90, 94, 95 ; §3.6 (cas Q2 réécrit en deux branches, « baisser les séances communes ») ; §5 (deux lignes) ; parcours 25 (variantes « reprise après » et « reprise avant la baisse ») ; §7 (branche N ≥ 1, deux cas réécrits, refus du crayon) ; S30, A4 (s) complété, A5 (garde du `PUT`) ; A4 (v) : l'enchaînement de Q2 en est sorti ; risques 32, 36 ; points 48, 63 ; QO-2 | appliquée sur ma recommandation, **posée en question** |
| 2c. L'option de groupe ne voit pas une séance commune tenue par des verrous | §1 (définition) ; constat 97 ; §3.6 a (option de groupe, garde de réservation de groupe) ; §4 (`lockHeldGroupCases`, `groupLockHeld`, texte) ; §5 (une ligne) ; parcours 20 (variante réécrite et variante 2c) ; §7 (trois raisons, un refus serveur, D) ; S31, `groupReservation.test.ts` (trois cas), A5 ; points 59, 60 | appliquée |
| 2d. Équipe dans deux groupes : compte non défini | §1 (attribution) ; constat 98 ; §3.6 (bloc « Attribution ») ; §4 (`ReservedSlot.blockId`) ; §5 ; §7 (Q2, plusieurs groupes) ; S32, A4 (u), A5 ; point 58 | appliquée |
| 2e. Groupe de trois : « sortez-en l'équipe » refusé, le bon geste non proposé | §3.6 (remède, cas du groupe de trois, limites) ; §5 ; parcours 25 (variante) ; §7 (« supprimez le groupe » pour toute taille) ; S27 réécrit, S33, A4 (u), (x) ; point 51 | appliquée par **retrait** de « sortez-en l'équipe » ; le geste « sortir le membre absent » n'est pas proposé (limite écrite, point 51) |
| 3. « Griser » : la v11 ne grise pas | « Avant le plan » ; §1 (4b) ; constats 80, 91, 96 ; §3.6 a ; §4 (`mutualisationGroups`, `unavailableTag` supprimé) ; §5 ; parcours 20 ; §7 ; S26, S34, A3 (cas 7, 8, test réécrit `:304-311`), `team-select.test.tsx` ; captures ; §9 (`team-select.tsx` passe des interdits aux autorisés, `listbox.tsx` interdit, a11y à rejouer) ; risque 34 ; points 49, 64 | appliquée : grisé fait, pour les quatre raisons |
| 4. Remède à 2 équipes vrai ; à 3 et plus, voir 2e | §3.6, §7 | confirmé ; voir 2e |
| 5. Pas 5 du scénario 1 attend un créneau nommé que le texte ne nomme pas | §8, scénario 1, pas 5 | appliquée |
| 5. Lignes manquantes au tableau pour 2a, 2b, 2d, 2e | §8 (S29 à S33, plus S34 pour le grisé) | appliquée |
| 5. `RoadmapIdentityTest` n'est pas dans la liste bloquante | constat 89 ; §5 ; §8 ; §9 (régime CI) ; risque 35 ; §12 ; §11 (ligne « non cherchée » retirée) | appliquée |
| 5. `BcclSeederIdempotenceTest` ne teste pas Q2 | constat 81 ; §3.7 ; §5 ; §8 (test complété) ; §9 (fichiers de tests, retour en validation, `BcclSeederIdempotenceTest` sort des interdits pour une condition) ; point 62 | appliquée : `tank` ajoute la condition `block > effective` |
| 5. Temps Behat : la décision est à prendre maintenant | §1 ; constat 93 ; §9 « Plafond de temps » (point 5) ; risque 20 | appliquée : décision Q-B1 écrite ; les replis internes de B et D, avant leur merge, restent |
| 6. P4-601 cite `ValidateConstraintsController.php:444-447` | constat 54 ; §12 (P4-601) | appliquée |
| 6. 21 lignes de roadmap neuves en B, c'est trop | §9 (documentation) ; §12 | appliquée : 9 lignes ; deux fusions (reprise et groupes ; baisse des séances) |
| 6. Portée de « rien sous la tablette » et rendu de 4b renvoyés au fondateur | §1 ; §9 (interdits) ; §12 | appliquée : tranchés, plus aucune question |

Ajouts de cette passe, non demandés :
- **Exemple faux de la v11 corrigé** : deux membres verrouillés ensemble sur 2 cases pour 1 séance commune ne donnent pas un Récap vert ; le blocage existant « réservé sur 2 cases » parle d'abord (constat 92, §3.6, parcours 22, A4 (v), A5).
- Constats 94 à 98 ; constat 76 complété (le cadenas s'ôte sans garde).
- Risques 36 à 39 ; points 58 à 64.
- `ReservedSlot.blockId` ; paramètre `$blocks` de `TeamReservedSlots::forScope` pour le groupe hypothétique.
- Tête de `§13` : la liste des fichiers lus en v11 n'est plus recopiée.

Correction non appliquée : aucune. Appliquée autrement que suggéré : 2e (retrait de la formule plutôt qu'ajout du nom du membre absent).

Sections non modifiées : §3.1 à §3.5 ; constats 1 à 53, 55 à 58, 60 à 71, 74, 75, 77, 78, 82 à 88 ; parcours 1 à 19, 21, 24 ; textes du planning (T1, T2, T3, 409) ; scénario 2 de la feature neuve ; A1 ; A2 ; A6 ; pas Behat de D.

Décisions retirées :
- **« Présence : réservation, ou séance de la version chargée, verrouillée ou non »** sans condition (v11, §1, point 11) : laissait passer un groupe que le moteur sépare (2a).
- **« Supprimez le groupe ou sortez-en l'équipe » à partir de 3 équipes**, et « sortez-en l'équipe » dans la parenthèse de Q2 (v11, §3.6, §7, point 51) : geste refusé par la garde élargie dans le cas 2e.
- **« L'option de groupe indisponible n'est pas grisée : absente de la liste et nommée dessous »** (v11, interprétation (b), point 49) : le fondateur a dit « griser ».
- **« `shared/components/ui/team-select.tsx` interdit »** : entre dans le périmètre pour deux champs.
- **« `BcclSeederIdempotenceTest` : ni seed ni test touchés »** : le test gagne une condition ; le seed reste interdit.
- **« Q2 en période copiée : baisser les séances communes, puis une seconde phrase demande de déverrouiller »** (v11, §3.6 ligne 297, parcours 25, risque 32, A4 (v)) : le premier geste est refusé 422.
- **« Deux cases qui réunissent le groupe, équipes à 2 séances : Récap vert »** quand les deux membres sont verrouillés (v11, parcours 22, A4 (v)) : faux, constat 92.
- **Douze lignes de roadmap de la v11** : les deux « À vérifier » (déverrouiller une séance réservée ; double épingle) faute de preuve ; grille des réservations, messages de l'import du parc, étiquette « Épinglé manuellement », verrou transformé en réservation, Récap qui ne dit pas que supprimer règle les deux, équipe dans plusieurs groupes, docblock du budget solo, sélecteur non grisé : limites acceptées déjà écrites au §12, ou devenues sans objet ; deux autres fondues dans des lignes gardées.
- **« Aucune question au fondateur n'est ouverte »** et le bloc « trois points à porter par l'orchestrateur » : remplacés par les questions ci-dessous.
- **« Le registre dit la marge atteinte sur le papier : l'orchestrateur le dit au fondateur avant la PR finale »** (v11, §9) : remplacé par la décision Q-B1.
- Retraits des versions antérieures : inchangés, voir le §14 de la v11.

---

# Questions réellement ouvertes (au fondateur, en une fois)

**QO-1 — Quand une séance simplement placée prouve-t-elle qu'un membre du groupe est là ?** (bloquant 2a)

Le cas, gestes lus dans le code, non joués : saison, U17-1 et U17-2, 1 séance chacune, 1 séance commune ; le planning chargé les montre encore ensemble lundi et jeudi. Je pose un cadenas sur U17-1 le lundi et un sur U17-2 le jeudi. Aucune case commune n'est possible : le moteur séparerait le groupe avec un simple avertissement.
- **Option A (ma recommandation, écrite dans le plan)** : une séance non verrouillée ne compte que si l'équipe a encore une séance à donner. Ici : les deux équipes sont nommées, « 2 séances comptées pour 1 déclarée », génération refusée ; j'ôte un seul cadenas, tout repasse au vert. Le geste courant ne change pas : je verrouille U11-1 le mercredi alors que U11-2 y est simplement placée et n'a rien d'autre de tenu → vert.
- **Option B** : seuls les créneaux tenus (réservation ou cadenas) prouvent la présence. Règle plus simple à dire. Mais le geste courant devient bloqué : verrouiller U11-1 seule sur la séance commune du mercredi donne « 2 séances comptées pour 1 déclarée » tant que U11-2 n'est pas verrouillée aussi.
- **Option C** : garder la v11 (toute séance placée compte). Le cas ci-dessus passe au Récap et le moteur sépare le groupe : contraire au choix 1. Non recommandée.
- Ce qui ferait basculer vers B : préférer une règle qui se dit en une phrase, au prix d'un blocage sur un geste fréquent.

**QO-2 — Le remède de Q2 quand l'équipe tient déjà plus de créneaux que de séances** (bloquant 2b)

Le cas, lu, non joué : fermeture reprise du planning de saison, U17-1 et U17-2 avec 2 séances communes arrivées verrouillées lundi et jeudi ; ensuite je passe U17-1 à 1 séance. La phrase validée dit « baissez les séances communes du groupe, ou supprimez le groupe ». Or le crayon répond 422 (le second verrou deviendrait un créneau individuel en trop), et supprimer le groupe laisse 2 verrous pour 1 séance. La phrase validée reste vraie, mot pour mot, quand U17-1 ne tient pas plus de créneaux que de séances (période générée sans reprise ; reprise faite après la baisse).
- **Option A (ma recommandation, écrite dans le plan)** : dans ce seul cas, la phrase dit l'ordre : « U17-1 a 2 séances comptées pour 1 déclarée (2 séances de groupe) : déverrouillez d'abord 1 séance, puis retirez-lui 1 séance de groupe (baissez les séances communes du groupe, ou supprimez le groupe), ou augmentez le nombre de séances de l'équipe. » Geste : cadenas ôté sur une séance de U17-1 au planning (toujours accepté), puis crayon → 1 séance commune (accepté) → vert. Aucune garde ne change.
- **Option B** : garder la phrase validée telle quelle et assouplir le crayon : il ne refuse plus un changement qui n'aggrave rien. Le gestionnaire baisse les séances communes (accepté), puis lit une seconde phrase « déverrouillez 1 séance ». Coût : une garde d'écriture existante change de règle, aussi pour des cas sans verrou.
- **Option C** : garder la phrase validée et le refus. Le premier geste conseillé échoue. Non recommandée.
- Ce qui ferait basculer vers B : tenir à une seule phrase de Q2 dans tous les cas.

**QO-3 — Plafond des créneaux de groupe (point 55) : à confirmer.**

Le brief que j'ai reçu le dit « tranché (Q-B1) ». La décision Q-B1 que je lis dans le plan PR 2 v11 porte sur le plafond de **temps** Behat, pas sur cette règle de comptage. Je n'ai donc pas la preuve que le fondateur a validé celle-ci, et je la repose.
- La règle : un groupe ne reçoit jamais plus de cases « de groupe » qu'il n'a de séances communes ; les créneaux en plus comptent comme individuels.
- Exemple (lu, non joué) : U17-1 verrouillée lundi et jeudi, U17-2 simplement placée aux deux, 1 séance commune. Avec la règle : lundi est la séance de groupe, jeudi 1 séance verrouillée ; U17-1 à 2 séances → vert ; à 1 séance → « 2 séances comptées pour 1 déclarée (1 séance de groupe, 1 séance verrouillée) : déverrouillez 1 séance ». Sans la règle : U17-1 à 1 séance garde 2 cadenas « de groupe » et passe au vert, alors qu'une équipe hors groupe avec 2 cadenas pour 1 séance est bloquée.
- **Ma recommandation : oui.** L'attribution d'une case à un seul groupe (2d) et le compte de l'écran s'appuient dessus. Si la réponse est non, les passages marqués ⟦QO-3⟧ sortent et deviennent une limite écrite.

**À l'orchestrateur, sans décision du fondateur :**
- Le registre du temps Behat `.next/budget-temps-lot-double-contexte.md` n'existe toujours pas sur disque ; B (15 s) et D (25 s) s'y inscrivent.
- L'absence de PR ouverte et l'état d'ENG-60 n'ont pas été relus en v12.
- Contestables par le fondateur sans être des questions : le grisé appliqué aussi aux trois raisons existantes (point 49), le retrait de « sortez-en l'équipe » (point 51), l'ordre d'attribution d'une case à un groupe (point 58).

**Recommandation finale : NO-GO en l'état (trois réponses attendues), GO pour B puis D dès les réponses reçues et la relecture de `smith` faite.** Laissé hors périmètre, volontairement : toute garde à la baisse des séances, toute modification de la reprise de période, l'élargissement de la garde du crayon aux cases tenues par des verrous, et tout ce qui touche les gymnases désactivés.