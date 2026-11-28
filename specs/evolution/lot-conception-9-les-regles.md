# Lot 9 · Les règles : plan d'exécution

**Statut : validé par le fondateur le 2026-11-27 (v4 sur 4, dernière version, après deux passes de `smith` ; décisions 1 à 6, §10). Tout le reste est tranché ou écrit en limite (§11).** Voie **Full**. Branche cible `lot/conception`, une PR ouverte à la fois, « Lot Gate » seul ; avant chaque merge : `make -C backend tests-complete` et les features et specs de la zone sur la stack d'épreuve. Exécutant de chaque PR : `neo`, après les rouges de `tank`. Fichier à créer par `trinity` : `specs/evolution/lot-conception-9-les-regles.md` (ce texte).

**Cadrage** : la conception validée en tient lieu (découpage §5, décision 3). Fiche : `specs/evolution/lot-conception-decoupage.md` § Lot 9. Cible : `conception-cible.md` §3, §11, §12, §13.3, §15, §16, §17, §26.

**Ce que ce plan suppose** : tête `01cc38b18` de `lot/conception`. Les `fichier:ligne` datent du 2026-11-26 et du 2026-11-27, **à relire à l'ouverture de chaque PR**. Les PR 2 à 6 passent après le lot 7 et la PR 7 du lot 5 (décision 3) : ceux-ci sont supposés livrés tels que leurs plans l'écrivent ; leur code n'a pas pu être lu.

**Non lu en entier** : `ScheduleConstraintBuilder`, `PlanStates`, `SeasonTransitionService`, `ConflictRadarLoader`, `PreSolvePreventionWarnings`, `SchedulePlanProvisioner`, `PlanComposition`, `OverlayManager`, `CoachDoubleBookingDetector`, `MoveSlotService` hors des plages citées ; `ClubRuleCoherenceChecker`, `LoadTestClubSeeder`, `PersonEraser`, `SeasonDataPurger`, `ErasedClubPurger`, `ClubProvisioner`, `frontend/src/features/matches/ConstraintsPage.tsx`, `SlotReservationModal.tsx` ; le contenu des tests trouvés par recherche.

**Deux changements se verront** : la règle de trajet devient propre à chaque plan ; la saison suivante reprend battement et temps par défaut.

---

## 1. Besoin reformulé

1. Un seul vocabulaire pour « obligatoire, souhaité, éteint ».
2. Le réglage de la règle de trajet devient une ligne des réglages de règles, propre à chaque plan ; sa table disparaît ; la saison suivante le reprend en entier.
3. Chaque sorte de règle de match se lit à un seul endroit.
4. L'indisponibilité d'un coach garde ses deux saisies.
5. Les contraintes, les passerelles et les liens coach-équipe sont rangés dans le plan de saison ; la case « active » d'une contrainte devient sa ligne dans le plan de saison, rangée avec les coches de période.
6. Le jour de match ne vit plus que dans l'habitude de match ; le gymnase imposé, dans la règle « impose ce gymnase ».
7. La demande au moteur est identique, club par club, sauf le point 2 pour la saison suivante. Contrat inchangé (1.12).

**Ce que vaut une règle éteinte dans le planning de saison** : chaque plan a ses coches (décision 6) ; une règle éteinte en saison n'éteint plus les plans de période.

## 2. Constats vérifiés dans le code

**Quatre vocabulaires de règle, un de verrou**
- `Enum/ConstraintRuleType.php`, `ImplicitRuleIntensity.php`, `VenueTravelRuleIntensity.php`, `TeamLinkIntensity.php`, `LockLevel.php` (SOFT « n'existe que pour être refusé », `:9-16`). Le moteur lit deux mots pour « dur » (`engine/app/schemas/input_schema.py:135`, `:191`, `:285`).
- Qui écrit une intensité : `ConstraintStateProcessor.php:130`, `:183`, `:444` ; `MatchConstraintStateProcessor.php:102`, `:158`, `:206` ; `TeamLinkStateProcessor.php:94` ; `ImplicitRuleSettingStateProcessor.php:124`, `:150` ; `VenueTravelRuleSettingStateProcessor.php:59-66` ; `CoachWishTransferService.php:242`, `:256`, `:269`, `:288` ; `DefaultConstraintSeeder.php:68` ; `BcclSeeder.php:1045`, `:1334`, `:2255`, `:2473` ; `LoadTestClubSeeder.php:685`, `:697`, `:714` ; recopies `SeasonTransitionService.php:420`, `:461`, `:526`, `:550`, `:565`, `PlanBirth.php:144`.
- Cinq unions côté écran : `frontend/src/features/wizard/api.ts:717`, `:754`, `:822`, `matches/api/teams.ts:118`, `matches/api/matchConstraints.ts:12`. Garde : `backend/tests/CrossStack/TsUnionsMatchPhpEnumsTest.php:41-73`.

**Le réglage de trajet**
- Une ligne par club et saison (`Entity/VenueTravelRuleSetting.php:31-78`) ; lu pour tous les plans (`ScheduleConstraintBuilder.php:522`, `:1381-1392`) ; émis seulement avec une matrice et hors « Ignorée » (`:524-534`).
- Empreinte à part, avec `MANDATORY` (`:197-212`, `:219-226`) ; onze points d'appel : `SchedulePlanProvisioner.php:971-973` ; `GenerateScheduleHandler.php:267`, `PeriodPlanTranscriber.php:243`, `PeriodPlanSiblingCopier.php:128`, `BcclSeeder.php:1904`, `:2380`, `LoadTestClubSeeder.php:637` ; `PruneUnreadSnapshotFieldsCommand.php:221`, `DedupeSnapshotPinsCommand.php:171`, `NormalizeTeamCoachSnapshotsCommand.php:181`, `PersonEraser.php:232`.
- Saison suivante : seul le cran est recopié (`SeasonTransitionService.php:560-567`), contre cran et seuils pour le bien-être (`:543-552`).
- Six parcours de `ImplicitRuleKey` : `ImplicitRuleResolver.php:42`, `:161`, `:139-148` (Récap, `RecapStep.tsx:216`), `ImplicitRuleSettingRepository.php:73`, `ImplicitRuleSettingStateProvider.php:54`, `BcclSeeder.php:1321`.
- Un plan de période sans aucune ligne suit la saison (`ImplicitRuleResolver.php:120-124`, `ImplicitRuleSettingRepository.php:71-103`).
- Écran : encart hors de l'ancre de période (`ConstraintsStep.tsx:821-825`) ; repère « Saison : … » (`ImplicitRulesPanel.tsx:101-110`, `texts/implicitRules.ts:15-16`, `:23`).

**Les règles de match** : colonnes typées et lien déclaré vers le gymnase (`Entity/MatchConstraint.php:69-96`) ; sept boucles de relecture (`MatchPlacementPayloadBuilder.php:152`, `:157-168`, `:174-189`, `:402-407` ; `ConflictRadarLoader.php:127-133`, `:138-148`, `:217-232` ; `ClubRuleCoherenceController.php:49`).

**L'indisponibilité d'un coach** : entraînement `ConstraintConfigValidator.php:93-98` ; matchs `MatchConstraintStateProcessor.php:183-206` ; doléance `CoachWish.php:98`, transférée `CoachWishTransferService.php:234-245` ; l'alerte de match ne lit que le module matchs (`ConflictRadarLoader.php:208-214`).

**Ce que la décision 5 déplace**
- Contrainte : rangée à la saison, froide du socle pour les permanentes (`Entity/Constraint.php:18`). Sept créateurs : `ConstraintStateProcessor.php:124`, `DefaultConstraintSeeder.php:62`, `SeasonTransitionService.php:518`, `CoachWishTransferService.php:296` (datée), `BcclSeeder.php:1039`, `:2248` (datée), `:2467` (datée).
- `DefaultConstraintSeeder` persiste sans flush (`:62-73`), appelé à la naissance du club (`ClubProvisioner.php:141`) ; `SoclePlan::idOf` lit le plan en SQL (`Plans/SoclePlan.php:36-41`) : un plan persisté et pas encore écrit lui échappe.
- Contrainte datée : attachée à une entrée de calendrier (`Constraint.php:82-89`) ; lue par le plan de son entrée et de l'entrée mère (`Entity/CalendarEntry.php:260-263`, `PeriodConstraintSelector.php:90-91`) ; une fermeture datée vaut pour tout plan dont la fenêtre la recoupe (`ConstraintRepository.php:64`). Détruire un plan de période laisse son entrée et ses contraintes datées (`OverlayManager.php:60-90`). Les permanentes se lisent par `ConstraintRepository.php:43`.
- Passerelle : `Entity/TeamLink.php:30`. Créateurs : `TeamLinkStateProcessor.php:47`, `SeasonTransitionService.php:412`, `BcclSeeder.php:1678`, `LoadTestClubSeeder.php:532`. Lecteurs : `ScheduleConstraintBuilder.php:903`, `:1082`, `MatchPlacementPayloadBuilder.php:133`, `CoachWishFormPresenter.php:93`, `CascadePlan.php:63-64`.
- Lien coach-équipe : principaux et adjoints dans la même table (`Entity/TeamCoach.php:16`) ; plusieurs principaux possibles ; le rôle se modifie (`TeamCoachStateProcessor.php:52-55`). Créateurs : `TeamCoachStateProcessor.php:45`, `SeasonTransitionService.php:348`, `BcclSeeder.php:957`, `LoadTestClubSeeder.php:546`. La demande lit ces liens en direct, par club et saison, pour le socle comme pour une période (`ScheduleConstraintBuilder.php:887`, `:903`).

**La case « active » et la coche de période, aujourd'hui**
- La case : `Constraint.php:91-92`. Aucun écran ne l'éteint (`wizard/api.ts:805`, `ConstraintsStep.tsx:460`) ; l'API le peut (`ConstraintStateProcessor.php:139`, `:213-215`).
- **Éteinte, une règle l'est dans tous les plans** : la sélection d'une période ne regarde pas la case (`PeriodConstraintSelector.php:136-147`), la demande l'émet avec `isActive` faux (`ScheduleConstraintBuilder.php:1819`, `:1844`), le moteur l'ignore. Une coche de période sur une règle éteinte est donc sans effet.
- **Douze lecteurs de la case** : `ScheduleConstraintBuilder.php:993`, `:1819`, `:1844` ; `CoachDoubleBookingDetector.php:366` ; `MoveSlotService.php:1118` ; `VenueClosureDays.php:302` ; `Controller/CalendarEntryConflictsController.php:122-126` ; `DevScheduleReportWriter.php:98` ; `AdminMonitoringService.php:292` ; `ConstraintResource.php:115` ; `SeasonTransitionService.php:531` ; `ConstraintRepository.php:97-104` (`findActiveByClub`, sans appelant : code mort).
- **Huit appels de `blockedIntervalsByCoach`** (`CoachDoubleBookingDetector.php:361`) : `MoveSlotService.php:975` et `PlacedSessionPersonConflictDetector.php:145` (toutes les contraintes du club et de la saison, datées comprises) ; `PlacedSessionPersonConflictDetector.php:230` ; `PlanHoldingInputs.php:60` (règles gardées par une période), `:63` (permanentes, pour le socle) ; `CoachDoubleBookingDetector.php:619`, `:693`, `:954` (listes reçues du Récap, `ValidateConstraintsController.php:205`, `:234`, et de `CoachInTwoPlacesGuard.php:54`).
- **Miroir côté écran** : `frontend/src/features/wizard/lib/coachDoubleBooking.ts:318-323` filtre sur `isActive`, lu par `SlotReservationModal.tsx:164` ; parité gardée par `tests/CrossStack/CoachDoubleBookingMirrorParityTest.php`.
- La coche de période : une ligne `plan_constraint` par plan de période et contrainte permanente (`Entity/PlanConstraint.php:26-64`), aucune pour le plan de saison (`Plans/PlanComposition.php:207-217`, `:446-448`).
- **« Défaut = nature du plan » est écrit à cinq endroits** : `PlanComposition::ruleDefaultOf` (`:233`, lu par la suppression d'une coche, `ConstraintPeriodOverrideStateProcessor.php:121`) ; `ruleLinesDifferingFromDefault` (`:249`, la liste des coches) ; `onScopeChanged` (`:283-297`) ; la garde de datation (`ConstraintStateProcessor.php:207`, par `PlanComposition.php:268-275`) ; l'écran (`frontend/src/features/wizard/steps/PeriodConstraints.tsx:139-146`, `:156`, miroir déclaré au registre `FrontRederivationRegistryTest`).
- **Les routes de la coche de période** : la création refuse le plan de saison (`ConstraintPeriodOverrideStateProcessor.php:85-88`) ; **la modification (`:103-107`), la suppression (`:115-122`) et la liste (`State/Provider/ConstraintPeriodOverrideStateProvider.php:59-82`) ne regardent pas la nature du plan**.
- `onScopeChanged`, la garde de datation et `removeRuleLines` (`:300-305`) parcourent toutes les lignes d'une contrainte, sans distinguer le plan.

**Catalogue et copies** : une famille de niveau plan part avec son plan (`Structure/FamilyCatalog.php:140-156`) et se recopie vers un plan sœur (`:180-190`) ; la copie d'une version lit une famille de niveau plan par son plan (`Plans/PlanStates.php:93-97`), n'y range jamais `schedulePlanId` (`:49`), **compare les lignes par leur identifiant** (`:344`, `:369`) et réinsère avec l'identifiant de la copie (`:686`). Garde du lot 4 : aucun `schedulePlanId` nul (`tests/Architecture/NoNullPlanBranchTest.php`, bloquant). Le décor de la référence 20 remplace des identifiants de contrainte après coup (plan du lot 5, §4, PR 1, écart b).

**Saison suivante** : `ensureLines` passe à la fin (`SeasonTransitionService.php:593`) et poserait chaque ligne de règle à son défaut.

**Les deux colonnes de l'équipe** : `Entity/Team.php:79`, `:83` ; repli `ScheduleConstraintBuilder.php:1571-1588` ; champ émis `:1547`, lu par le moteur `engine/app/solver/constraints/targeting.py:690` ; API seule (`Dto/TeamInput.php:50-56`, `TeamStateProcessor.php:110-111`, `:206-210`) ; production 0 jour de match en colonne (`conception-cible.md` §27), gymnase imposé non mesuré.

**Filet existant** : références 1 à 21 (bloquant) ; aucune ne porte une contrainte éteinte, une passerelle obligatoire, un trajet aux seuils réglés ; références 7 et 8 par la colonne `matchDay` (`tests/EngineRequestGroupRulesDecorTrait.php:73`), 20 par `forcedVenueId` (`tests/EngineRequestSocleNetDecorTrait.php:92`) ; parités bloquantes `.github/workflows/ci.yml:1019-1074`, `TravelRuleFingerprintTest` `:1457`, `Lot4MigrationChainTest` `:1473` ; plafonds backend 1000 s, Behat 950 s, e2e 635 s, front 230 s.

## 3. Conception : cinq réponses par objet

### 3.1 Un vocabulaire : `RuleIntensity`
1. **Le plus simple** : `HARD`, `PREFERRED`, `OFF` ; chaque écriture accepte son sous-ensemble.
2. **Supprime** : les quatre énumérations de règle, quatre unions côté écran.
3. **Un seul endroit** : `Enum/ImplicitRuleIntensity.php`, renommée.
4. **Suppose en dur** : trois crans ; noms de champs inchangés.
5. **Deuxième usage** : les règles du module salariés.

Une seule traduction vers le moteur, `RuleIntensity::engineValue()`, à trois sites : émission du trajet (`ScheduleConstraintBuilder.php:529`), des passerelles (`:1335`), et dans `fingerprint()`. `travelRuleOf`, `PlanStates::travelRuleIn`, `travelRuleOfVersion` rendent la même forme, vocabulaire rangé. Colonnes fermées par un CHECK ; la migration s'annule sur une valeur hors liste.

### 3.2 Le réglage de trajet : une ligne par plan dans `implicit_rule_setting`, hors de `ImplicitRuleKey`
1. **Le plus simple** : même table, clé de rangement `travelTime` hors de l'énumération : les six parcours ne la voient jamais.
2. **Supprime** : la table `venue_travel_rule_setting`, son entité, son dépôt, les cas à part de `PlanStates.php:293-322`, la branche `FamilyCatalog.php:209`, le bloc `SeasonTransitionService.php:554-567`.
3. **Un seul endroit** : `Entity/ImplicitRuleSetting.php:31`.
4. **Suppose en dur** : une ligne par plan ; absente d'un plan de période = celle du plan de saison ; absente là aussi = défauts.
5. **Deuxième usage** : variante de semaine, planning d'essai.

`findByPlanIndexed` (`:30-41`) ignore la ligne de trajet ; `PlanBirth.php:141-144` et `SeasonTransitionService.php:543-552` la recopient par sa clé brute, cran et seuils ; `materializeForPlan` la pose à la naissance d'un plan de période. La route de l'écran est gardée, avec le plan en paramètre.

### 3.3 Une lecture par sorte de règle de match
Trois lectures dans `MatchConstraintRepository`, sur les colonnes actuelles. La règle de match garde ses colonnes (écart E5) : un gymnase rangé dans un JSON perdrait sa déclaration de lien et la clé étrangère du lot 6 ; une interface commune n'aurait aucun appelant.

### 3.4 L'indisponibilité d'un coach
Deux saisies, une lecture par domaine. La doléance garde la sienne (écart E4).

### 3.5 Les règles, les passerelles et les coachs dans le plan de saison

**Colonne de plan sur trois tables**
1. **Le plus simple** : `schedule_plan_id` obligatoire sur `constraint`, `team_link`, `team_coach`, égal au plan de saison de la ligne ; clé étrangère vers le plan, club compris, en cascade.
2. **Supprime** : la lecture « par club et saison » de `PlanStates.php:95-97` pour ces familles.
3. **Un seul endroit** : la colonne existe sur les familles du lot 4 (`Entity/ImplicitRuleSetting.php:64-66`).
4. **Suppose en dur** : un plan de saison par saison ; seul lui porte ces lignes.
5. **Deuxième usage** : variante de semaine et planning d'essai, par la copie de naissance. Rien n'est construit.

**Trois points tranchés**

*(1) La contrainte datée.* Toute contrainte est rangée dans le plan de saison de sa saison ; une datée y est rangée aussi et reste attachée à son entrée de calendrier, qui dit quand elle vaut. Elle n'appartient jamais à un plan de période : celui-ci peut être détruit puis refait, la fermeture et la doléance transférée doivent lui survivre (`OverlayManager.php:60-90`). Un plan de vacances qui couvre la date la lit comme aujourd'hui, et la retrouve s'il est recréé. Variante écartée : colonne de plan vide pour une datée, qui rouvrirait le « plan nul » fermé par le lot 4.

*(2) Plusieurs coachs principaux.* Il n'y a pas « un » coach principal du plan : le plan de saison porte, par équipe, la liste de ses coachs principaux, une ligne par coach. Toutes les lignes de `team_coach` reçoivent le plan, adjoints compris, parce que le rôle se modifie (`TeamCoachStateProcessor.php:52-55`) ; l'adjoint reste libre. Un plan de période n'en porte pas : sa demande lit ceux de la saison, en direct aujourd'hui (`ScheduleConstraintBuilder.php:887`, `:903`), par la porte `InForce` une fois la PR 5 du lot 5 livrée.

*(3) Les copies par version du lot 5.*
- Les trois familles gardent leur nom et leurs champs froids dans la copie ; la capture les lit par leur plan, filtre de lignes froides compris.
- Deux changements de forme, appliqués aux copies existantes : une ligne de contrainte perd `isActive` ; la copie d'une version du plan de saison gagne les lignes de règle de ce plan.
- **Identifiant d'une ligne de règle du plan de saison : calculé de (plan, contrainte)**, jamais tiré au hasard. La migration, `ensureLines` et la conversion des copies appliquent la même formule ; une contrainte disparue que seule une copie porte y reçoit donc la même ligne qu'elle aurait eue. Sans cela chaque version du socle montrerait une différence née de la conversion.

**La case « active » devient la ligne du plan de saison**
1. **Le plus simple** : le plan de saison reçoit une ligne `plan_constraint` par contrainte permanente ; `applies` y vaut l'ancienne case ; la colonne `constraint.is_active` disparaît.
2. **Supprime** : `Constraint.isActive`, `ConstraintRepository::findActiveByClub`, la branche « pas de ligne pour le plan de saison » de `PlanComposition.php:214-216` et `:446-448`.
3. **Un seul endroit** : `Entity/PlanConstraint.php:62-64`.
4. **Suppose en dur** : une contrainte datée n'a pas de ligne et s'applique toujours ; pour ne plus l'appliquer, on la supprime.
5. **Deuxième usage** : la coche de règle du Wizard unique du lot 6.

Règles :
- **Un seul écrivain de la ligne du plan de saison** : le champ `isActive` de l'API des contraintes. Les routes de la coche de période ne la touchent jamais : création, modification, suppression et lecture d'une ligne du plan de saison y sont refusées, et leur liste ne la rend pas.
- `onScopeChanged` et la garde de datation ne parcourent que les lignes de période : changer la portée d'une règle ne touche pas la coche du socle.
- **Dater une règle permanente éteinte est refusé**, avec le vrai motif (§9) : `removeRuleLines` la rallumerait en silence, une datée étant toujours appliquée.
- Les douze lecteurs de la case lisent la ligne du plan de saison par une seule porte, `PlanComposition::appliedInSocle` ; une datée y passe toujours. Les huit appels de `blockedIntervalsByCoach` lui passent une liste déjà filtrée ; la fonction ne filtre plus.
- Le miroir côté écran ne change pas : il lit le champ `isActive` que l'API sert toujours.

**Chaque plan a ses coches** (décision 6)
- Une règle éteinte dans la saison et cochée dans une période : elle s'applique dans la période. La demande d'une période porte les lignes cochées avec `isActive` vrai ; la demande du socle porte `isActive` = la ligne du plan de saison.
- Conversion : toutes les lignes de période d'une règle éteinte passent à « décochée » ; même effet qu'avant, mais la demande de ces plans perd une ligne que le moteur ignorait.
- Défaut d'une ligne de période : nature du plan **et** ligne du plan de saison cochée ; les cinq endroits du §2 changent, écran compris.
- Éteindre ensuite une règle dans la saison : les lignes de période restées au défaut suivent, à la validation du planning de saison ; une ligne réglée autrement reste.
- Fichiers d'écran en PR 5 : `PeriodConstraints.tsx` et son test de parité.


### 3.6 Jour de match et gymnase imposé
Supprimer les deux colonnes ; la migration s'annule si une équipe porte un jour de match sans habitude, ou un gymnase imposé en colonne. La clé `teams[].forcedVenueId` reste dans la demande, toujours nulle ; le prochain changement de contrat la retire, avec sa lecture côté moteur (`targeting.py:690`).

### 3.7 Écarts à la fiche et à la cible, validés avec le plan

| # | La fiche ou la cible dit | Ce plan | Raison |
|---|---|---|---|
| E1 | « Quatre vocabulaires sur cinq » | Les quatre des règles ; `LockLevel` reste | Un verrou n'est pas l'intensité d'une règle |
| E2 | Un seul vocabulaire | Le moteur garde `MANDATORY` ; une traduction, trois sites | Le moteur ne change dans aucun lot |
| E3 | Règles « au plan, pour tous » | Le plan de saison possède les contraintes ; un plan de période coche celles-là et lit les datées de son entrée | L'identifiant d'une contrainte part au moteur |
| E4 | L'indisponibilité « lue par le transfert des doléances » | La doléance garde sa saisie | Une doléance est un souhait |
| E5 | « Une seule forme » de règle | La règle de match garde ses colonnes | §3.3 |
| E6 | « Rangé avec les autres réglages » | Même table, clé hors de `ImplicitRuleKey`, route d'écran gardée | §3.2 |
| E7 | Adjoints « structure de saison » | Leur ligne porte le plan de saison ; elle reste libre | §3.5, point 2 |
| E8 | Clés étrangères sur toute table transformée | Vers le plan seulement ; celle de `plan_constraint` vers la contrainte passe au lot 6 | Le décor de la référence 20 |

Hors lot : le plancher de séances d'une équipe (`Team.php:76`) et les méthodes sans appelant de `ConstraintRepository` autres que `findActiveByClub`, ligne de roadmap P4-615, « ce que le backend fait et que rien n'utilise ».

## 4. Découpage : six PR

### PR 1 · « Le filet du lot 9 » (aucun comportement ne change)
- Trois références captées sur le code d'avant : `socle-23-trajet-passerelle-regle-eteinte.json` (trajet obligatoire, battement 10, défaut 15, matrice ; passerelle obligatoire ; une contrainte permanente éteinte) ; `periode-24-vacances-regle-eteinte-cochee.json` (le même club ; **la règle éteinte y est cochée** : elle part avec `isActive` faux) ; `placement-25-regles-de-match-bornes-ouvertes.json`. Le numéro 22 est réservé par la PR 6 du lot 5.
- Chaîne de conversion : cas 23 et 24, une section de `tests/Fixtures/lot-4/decor-avant.sql` complétée par `tests/Fixtures/lot-9/complements.sql`.
- Deux scénarios de l'actuel : le trajet réglé depuis une période règle la saison ; la saison suivante garde le cran et repart à 20 minutes.
- Mesure des plafonds.
- Tests : lignes 1 à 5. `/security-review` : non.

**Livrée le 2026-11-28 (PR 1), sur `lot/conception`, non déployée.** Écarts au plan, tels que livrés :
- (a) Références 23, 24 et 25 dans `EngineRequestReferenceTest`, décor `tests/EngineRequestRulesNetDecorTrait.php` ; les références 1 à 21 restent identiques à l'octet.
- (b) Chaîne de conversion : les cas 23 et 24 sont dans `Lot4MigrationChainTest`, leurs lignes en plus de la section de base dans `tests/Fixtures/lot-9/complements.sql` (« -- @reference <nom> @base <section> »). La référence 25 n'est pas jouée par la chaîne : le plan ne le demande pas.
- (c) Deux scénarios de l'actuel : `plan-de-periode-en-overlay.feature` (le trajet réglé depuis une période règle la saison) ; `preparer-la-saison-suivante-reprend-la-structure.feature` et `SeasonTransitionServiceTest` (le cran est gardé, battement et temps par défaut repartent à 20 minutes).
- (d) À reprendre plus tard : en PR 3 l'étape « depuis les vacances » passera le `schedulePlanId`, et l'étape des 20 minutes passera à 10 et 15 ; les identifiants de la référence 24 sont écrits à deux endroits (le trait et `complements.sql`) ; en PR 5 la référence 24 perd la ligne de la règle éteinte.
- (e) Plafonds mesurés : aucun franchi.

### PR 2 · « Un seul vocabulaire d'intensité »
- Migration : garde (valeur hors liste), `MANDATORY` vers `HARD` dans `team_link` et `venue_travel_rule_setting`, copies converties, cinq CHECK.
- Code : `RuleIntensity`, remplacement mécanique, `engineValue()` aux trois sites, entrées d'API.
- Écran : `shared/lib/ruleIntensity.ts` ; `matches/TeamLinksSection.tsx:125-241`, `matches/lib/teamLinkLabel.ts:15`, `wizard/steps/ImplicitRulesPanel.tsx:42-45`, `:232-235`, types d'API.
- Tests : lignes 6 à 12. `/security-review` : non.

### PR 3 · « Le trajet rejoint les réglages de règles »
- Migration irréversible : (1) s'annule si un plan de période ne porte aucune ligne de bien-être ; (2) la ligne de la saison devient la ligne `travelTime` du plan de saison, même identifiant ; (3) chaque plan de période reçoit une ligne aux valeurs de sa saison ; (4) s'annule si le compte diffère ; (5) copies converties ; (6) table supprimée.
- Code : `ImplicitRuleSetting`, `ImplicitRuleSettingRepository`, lecteur et écrivain du trajet, `ScheduleConstraintBuilder` (`:325`, `:522`, `:1381`), `SchedulePlanProvisioner.php:971-973`, `PlanStates`, `PlanBirth.php:141-144`, `SeasonTransitionService.php:543-567`, `FamilyCatalog::coldOf`.
- Écran : l'encart reçoit le plan affiché, se place sous la phrase de période, montre le repère « Saison : … ».
- Tests : lignes 13 à 22. `/security-review` : oui.

### PR 4 · « Une lecture par sorte de règle de match » (aucun comportement ne change)
- Trois méthodes de dépôt ; `MatchPlacementPayloadBuilder`, `ConflictRadarLoader`, `ClubRuleCoherenceController` les appellent.
- Tests : lignes 23 et 24. `/security-review` : oui, courte.

### PR 5 · « Les règles, les passerelles et les coachs dans le plan de saison »
- **Migration irréversible**, dans cet ordre :
  1. s'annule si une saison porte une contrainte, une passerelle ou un lien coach-équipe sans plan de saison ;
  2. s'annule si une contrainte datée est éteinte ;
  3. `schedule_plan_id` ajoutée aux trois tables, remplie, rendue obligatoire, clé étrangère en cascade ;
  4. une ligne `plan_constraint` par contrainte permanente dans le plan de saison, identifiant calculé, `applies` = l'ancienne case ; s'annule si leur nombre diffère de celui des contraintes permanentes ;
  5. les lignes de période d'une contrainte éteinte passent à « décochée » ;
  6. copies des versions converties (§3.5, point 3) ;
  7. `constraint.is_active` supprimée.
- Code :
  - les quinze créateurs posent le plan ; `DefaultConstraintSeeder::seed` reçoit le plan de saison de `ClubProvisioner.php:141`, il ne le relit pas ;
  - `Family` des trois entités, `FamilyCatalog` (`coldOf`, `carriedByPlan`), `PlanStates` (capture par plan, insertion) ;
  - `PlanComposition` : lignes du plan de saison dans `ensureLines` et `constraintsOf`, `appliedInSocle`, `setSocleApplies`, `onScopeChanged` et la garde de datation bornées aux lignes de période ;
  - les douze lecteurs de la case et les huit appels de `blockedIntervalsByCoach` ; `ConstraintRepository::findActiveByClub` supprimée ;
  - `ConstraintResource`, `ConstraintStateProcessor` (champ `isActive`, refus de dater une règle éteinte) ;
  - `ConstraintPeriodOverrideStateProcessor` (`:85-88` texte revu, `:103-107`, `:115-122`) et `ConstraintPeriodOverrideStateProvider` (`:59-82`, lecture d'un élément) : garde « plan de saison » ;
  - `SeasonTransitionService.php:348`, `:412`, `:518`, `:531` : plan de la nouvelle saison ; **la coche du socle est recopiée après `ensureLines` (`:593`)**.
- Code, le défaut d’une coche de période : `ruleDefaultOf`, `ruleLinesDifferingFromDefault`, `ruleLinesOfConstraintDifferingFromDefault`, `onScopeChanged`, `PlanComposition::onSocleAppliesChanged`, appelée par la propagation à la validation du lot 5 ; `frontend/src/features/wizard/steps/PeriodConstraints.tsx:139-146` et l'entrée du registre de `FrontRederivationRegistryTest`.
- Tests : lignes 25 à 39, plus A1 à A3. `/security-review` : oui.

### PR 6 · « Jour de match et gymnase imposé : un seul porteur », et les documents
- Migration irréversible : garde d'annulation, suppression des deux colonnes et de l'index, retrait de `forcedVenueId` des copies.
- Code : `Team`, `TeamInput`, `TeamResource`, `TeamStateProcessor`, `ScheduleConstraintBuilder.php:1546-1588`, `SeasonTransitionService.php:330-332`, `CascadePlan.php:117`, `PlanStates`.
- La référence 20 change d'une valeur ; les décors 7 et 8 posent une habitude à la place de la colonne.
- Documents du lot (§12).
- Tests : lignes 40 à 46. `/security-review` : non.

## 5. Contrats nouveaux

```php
// PR 1
// tests/Fixtures/engine-requests/{socle-23-…, periode-24-…, placement-25-…}.json
// App\Tests\EngineRequestRulesNetDecorTrait ; tests/Fixtures/lot-9/complements.sql (« -- @reference <nom> @base <section> »)

// PR 2
enum App\Enum\RuleIntensity: string { case HARD = 'HARD'; case PREFERRED = 'PREFERRED'; case OFF = 'OFF'; }   // use HasValues
RuleIntensity::withoutOff(): list<string>     // ['HARD', 'PREFERRED']
RuleIntensity::engineValue(): string          // HARD -> 'MANDATORY' ; PREFERRED et OFF inchangés ; trajet et passerelles seulement
ScheduleConstraintBuilder::TRAVEL_DEFAULTS = ['defaultMinutes' => 20, 'intensity' => 'PREFERRED', 'toleranceMinutes' => 20]
ScheduleConstraintBuilder::fingerprint(array $payload, ?array $travelRule): string   // $travelRule en vocabulaire rangé, traduit ici seulement
// CHECK : constraint.rule_type, match_constraint.rule_type, team_link.training_intensity IN ('HARD','PREFERRED') ;
//         implicit_rule_setting.intensity, venue_travel_rule_setting.intensity IN ('HARD','PREFERRED','OFF')
// 'MANDATORY' -> 422 ; 'OFF' -> 422 sur /api/constraints, /api/match_constraints, /api/team_links
```
```ts
// shared/lib/ruleIntensity.ts
export type RuleIntensity = "HARD" | "PREFERRED" | "OFF";
export type ActiveRuleIntensity = Exclude<RuleIntensity, "OFF">;
```
```php
// PR 3
ImplicitRuleSetting::TRAVEL_KEY = 'travelTime'                 // clé de rangement, PAS un cas de ImplicitRuleKey
ImplicitRuleSetting::ruleKeyValue(): string ; ImplicitRuleSetting::isTravelRule(): bool
ImplicitRuleSetting::getRuleKey(): ImplicitRuleKey             // lève sur la ligne de trajet
ImplicitRuleSettingRepository::travelRuleOfPlan(string $clubId, string $seasonId, string $planId): ?ImplicitRuleSetting
ScheduleConstraintBuilder::travelRuleOf(string $planId): array{defaultMinutes: int, intensity: string, toleranceMinutes: int}
PlanStates::travelRuleIn(PlanState $state): array{defaultMinutes: int, intensity: string, toleranceMinutes: int}
// params : {toleranceMinutes: int 0..60, defaultMinutes: int 1..120}, clé absente = 20
// GET /api/venue_travel_rule_settings/travelTime[?schedulePlanId=uuid]
//   -> 200 {ruleKey: 'travelTime', intensity, toleranceMinutes: int, defaultMinutes: int, isDefault: bool} ; 400 identifiant mal formé
// PUT /api/venue_travel_rule_settings/travelTime  {schedulePlanId?: uuid, intensity, toleranceMinutes?: int, defaultMinutes?: int}
//   -> 200 ; 422 hors bornes ; 422 plan inconnu ou d'un autre club, même réponse ; 403 Membre ; 409 saison archivée
// GET|PUT /api/implicit_rule_settings/travelTime -> 404 ; table venue_travel_rule_setting supprimée
```
```ts
// TravelRuleNotice({ schedulePlanId }: { schedulePlanId: string | null })
// getTravelRuleSetting(schedulePlanId: string | null) ; useTravelRuleSetting(schedulePlanId, enabled)
// VenueTravelRuleSettingPayload { …, schedulePlanId?: string }
```
```php
// PR 4
MatchConstraintRepository::kickoffRules(): list<array{id: string, ruleType: string, daysOfWeek: list<int>, kickoffMin: ?string, kickoffMax: ?string}>
MatchConstraintRepository::forbiddenVenuesByTeam(): array<string, list<string>>
MatchConstraintRepository::coachUnavailabilities(): list<array{coachId: string, daysOfWeek: list<int>, kickoffMin: ?string, kickoffMax: ?string}>

// PR 5
// constraint, team_link, team_coach : + schedule_plan_id guid NOT NULL
//   FK (schedule_plan_id, club_id) -> schedule_plan (id, club_id) ON DELETE CASCADE ; index
// constraint : − is_active
Constraint::getSchedulePlanId(): string ; TeamLink::getSchedulePlanId(): string ; TeamCoach::getSchedulePlanId(): string
#[Family(Level::PLAN, copiedAtPlanBirth: false, cold: […], coldRows: […], ofSocle: true, copiedToNextSeason: true)]   // les trois entités
FamilyCatalog::carriedByPlan(): list<class-string>             // sans les familles ofSocle
FamilyCatalog::coldOf(SchedulePlanType $type): list<class-string>   // une famille ofSocle n'est rendue que pour le plan de saison
PlanConstraint::socleLineId(string $seasonPlanId, string $constraintId): string
//   identifiant calculé, le même en PHP et en SQL (md5 de « plan_constraint|<plan>|<contrainte> », mis en forme d'identifiant)
PlanComposition::constraintsOf(string $planId): array<string, PlanConstraint>   // aussi pour le plan de saison ; lève si une ligne manque
PlanComposition::appliedInSocle(string $seasonId, array $constraints): list<Constraint>
//   retire les permanentes dont la ligne du plan de saison est décochée ; une datée passe toujours ; ordre gardé
PlanComposition::setSocleApplies(Constraint $constraint, bool $applies): void   // seul écrivain de la ligne du plan de saison
DefaultConstraintSeeder::seed(Club $club, Season $season, SchedulePlan $seasonPlan): void
CoachDoubleBookingDetector::blockedIntervalsByCoach(array $constraints): array  // même signature ; ne filtre plus
// /api/constraints : isActive lu et écrit sur la ligne du plan de saison ; contrainte datée : toujours vrai, « faux » -> 422
//   PUT calendarEntryId sur une permanente éteinte -> 422
// /api/constraint_period_overrides : POST sur le plan de saison -> 422 (texte revu) ;
//   GET {id}, PUT {id}, DELETE {id} d'une ligne du plan de saison -> 404 « Ressource introuvable. » ; la liste ne la rend jamais
// /api/constraints, /api/team_links, /api/team_coaches : aucun champ de plan, ni lu ni écrit
// demande : constraints[].isActive = la ligne du plan de saison (socle) ; vrai (période)

// PR 5, le défaut d’une coche de période
PlanComposition::ruleDefaultOf(PlanConstraint $line): bool    // nature du plan ET ligne du plan de saison cochée
PlanComposition::onSocleAppliesChanged(Constraint $constraint, bool $previous): void
//   les lignes de période restées à l'ancien défaut passent au nouveau ; ne flush pas

// PR 6
// team : − match_day, − forced_venue_id, − idx_team_forced_venue
// /api/teams : matchDay et forcedVenueId absents de la réponse ; envoyés, ils sont ignorés
// demande : teams[].forcedVenueId toujours null ; teams[].matchDay = jour de l'habitude, sinon null
```

## 6. Conversion, irréversibilité, mesures

- **Migrations irréversibles** : PR 3, PR 5, PR 6 (aucun `down()`). Toutes entrent dans `LOT_MIGRATIONS`.
- **Copies des versions** : aucune en production quand les migrations tournent (lot 5, §6) ; les bases de travail en portent, chaque migration les convertit. Règle : une version sans différence avec l'état vivant avant n'en a pas après, y compris quand sa copie porte une contrainte supprimée depuis.
- **Preuve** : références 1 à 25 à l'octet (la 20 pour une valeur en PR 6 ; la 24 pour une ligne en PR 5) ; la chaîne et ses cas faussés ; copie de production n° 2, `diff -r` vide.

**Mesures à demander à `link`** (lecture seule, comptes seulement ; clubs de démonstration à part) :

| # | Mesure | Attendu |
|---|---|---|
| 1 | Équipes avec `match_day` non nul ; dont sans habitude | 0 et 0, sinon retour |
| 2 | Équipes avec `forced_venue_id` non nul | 0, sinon retour |
| 3 | Réglages de trajet par cran ; dont battement ou temps par défaut différents de 20 | à relever |
| 4 | Valeurs distinctes des cinq colonnes d'intensité | aucune hors liste, sinon retour |
| 5 | Plans de période par nombre de lignes de bien-être (0, 4, 5, autre) | 0 ligne : aucun, sinon retour |
| 6 | Contraintes éteintes : datées ; permanentes ; permanentes éteintes cochées dans au moins un plan de période | datées : 0, sinon retour ; les deux autres : à relever, c’est ce que la décision 6 touche |
| 7 | Saisons qui portent une contrainte, une passerelle ou un lien coach-équipe sans plan de saison | 0, sinon retour |
| 8 | Par table déplacée : lignes ; contraintes permanentes et datées ; liens principaux et adjoints | à relever |
| 9 | Coachs avec une indisponibilité d'entraînement et une de match | à relever |
| 10 | Versions en attente ou en cours, placements en cours | 0 |

## 7. Tableau comportement → scénario (brief de `tank`)

| # | PR | Comportement | Preuve, rouge avant `neo` |
|---|---|---|---|
| 1 | 1 | Trois demandes de plus figées, dont une règle éteinte émise avec `isActive` faux, au socle et dans une période où elle est cochée | `EngineRequestReferenceTest`, cas 23 à 25 (bloquant) |
| 2 | 1, puis chaque PR | Le décor d'avant, complété, donne les mêmes demandes après toutes les migrations | `Lot4MigrationChainTest`, cas 23 et 24 (bloquant), tenu par `tank` |
| 3 | 1 | Actuel : le trajet réglé depuis une période règle toute la saison | `plan-de-periode-en-overlay.feature`, scénario neuf : « Étant donné des vacances de Noël déjà créées et un temps de trajet saisi » / « Quand je règle le trajet entre gymnases sur « Obligatoire » depuis les vacances de Noël » / « Alors le trajet est « Obligatoire » pour le planning de saison » |
| 4 | 1 | Actuel : la saison suivante garde le cran et repart à 20 minutes | `preparer-la-saison-suivante-reprend-la-structure.feature`, étapes : « Et le trajet entre gymnases réglé sur « Obligatoire », battement 10 minutes, temps par défaut 15 minutes » / « Alors la saison suivante garde le trajet « Obligatoire » » / « Et son battement est de 20 minutes, son temps par défaut de 20 minutes » ; `SeasonTransitionServiceTest`, un cas |
| 5 | 1 | Mesure des plafonds | `make ci-mesure PART=origin/lot/conception` |
| 6 | 2 | Demande identique : le moteur reçoit toujours `MANDATORY` | Références 20, 23, 24 ; `TeamLinkPayloadParityTest`, `VenueTravelTimePayloadParityTest` (bloquants) |
| 7 | 2 | L'empreinte d'une version au trajet obligatoire est la même avant et après | `TravelRuleFingerprintTest` (bloquant), attentes inchangées, plus un cas à valeur figée |
| 8 | 2 | Conversion des passerelles, du trajet, des copies ; s'annule sur une valeur hors liste ; la base refuse ensuite | Chaîne, un cas faussé ; `tests/Integration/Migration/Lot9CopiesConversionTest.php`, cas « passerelle » et « trajet » |
| 9 | 2 | Passerelle et trajet obligatoires s'enregistrent et se relisent ; `MANDATORY` refusé | `tests/Api/RuleIntensityTest.php`, quatre cas |
| 10 | 2 | Abus : « éteint » refusé à chaque écriture d'une règle (trois API : 422 ; écriture directe : la base refuse) ; un transfert de doléance n'écrit que dur ou souhaité ; les graines passent sous les CHECK | `RuleIntensityTest`, trois cas ; `tests/Integration/RuleIntensityCheckTest.php`, un cas par table ; `CoachWishTransferServiceTest`, une assertion |
| 11 | 2 | L'écran et le serveur portent la même liste | `TsUnionsMatchPhpEnumsTest` (groupe `contract`) |
| 12 | 2 | Choisir « Obligatoire » pour une passerelle ou le trajet l'enregistre et le réaffiche | `HabitsLinksDialog.test.tsx`, `TeamLinksModal.test.tsx`, `TravelRuleNotice.test.tsx`, valeurs adaptées |
| 13 | 3 | Demande identique | Références 1 à 25 ; ligne 2 |
| 14 | 3 | Conversion du trajet ; s'annule si un plan de période n'a aucune ligne de bien-être, ou n'a pas reçu sa ligne | Chaîne, comptes, deux cas faussés ; `Lot9CopiesConversionTest`, cas « trajet par plan » |
| 15 | 3 | Le trajet réglé dans les vacances ne touche pas la saison ; réglé dans la saison après la création de Noël, il ne touche pas Noël ; des vacances créées ensuite reçoivent la règle du socle | Scénario de la ligne 3, réécrit : « Alors le trajet est « Obligatoire » pour les vacances de Noël » / « Et il reste « Souhaitée » pour le planning de saison ». Trois exemples |
| 16 | 3 | Rangé = émis, plan par plan ; une ligne obligatoire sans matrice n'émet rien ; le bloc de bien-être reste à cinq clés | `VenueTravelTimePayloadParityTest` (bloquant), deux cas ; `ImplicitRulePayloadParityTest` (bloquant) vert sans modification |
| 17 | 3 | Les réglages de bien-être ne voient pas la ligne de trajet (liste servie, route, Récap, repli d'un plan sans ligne, naissance d'un plan) | `tests/Integration/Service/TravelRuleStorageTest.php`, cinq cas |
| 18 | 3 | L'empreinte d'un plan lit sa propre règle | `TravelRuleFingerprintTest`, cas « période » |
| 19 | 3 | « Charger » et la copie d'un plan sœur ramènent la règle de trajet ; la saison suivante reprend cran, battement et temps par défaut | `charger-une-version-ramene-son-etat.feature` vert ; `PeriodPlanSiblingCopyTest`, une assertion ; ligne 4 réécrite : « Et son battement est de 10 minutes, son temps par défaut de 15 minutes » |
| 20 | 3 | Abus : plan d'un autre club, 422 identique à un plan inconnu ; Membre 403 ; saison archivée 409 ; hors bornes 422 | `VenueTravelRuleSettingApiTest`, cas ajoutés ; `MemberWriteSweepTest`, `SeasonReadonlyTest` verts |
| 21 | 3 | L'encart lit et écrit la règle du plan affiché ; en période il montre le repère de la saison ; absent sans temps de trajet ; lecture seule pour un Membre | `TravelRuleNotice.test.tsx`, trois cas ; `ConstraintsStep.test.tsx`, un cas |
| 22 | 3 | La famille supprimée ne laisse rien derrière | `FamilyDeclarationSweepTest`, `PurgeCompletenessTest`, `RlsIsolationTest` (bloquants) adaptés |
| 23 | 4 | Demande de placement identique | Références 5, 6, 16 à 19, 21, 25 ; les trois parités des règles de match |
| 24 | 4 | Radar, cohérence des règles de club, alerte de match inchangés | `placement-des-matchs.feature:136`, `les-conflits-d-un-match-disent-la-verite.feature:69-88`, `MatchCoachUnavailableRadarSourceTest`, `ClubRuleCoherenceCheckerTest`, `MatchTenantIsolationTest` : verts sans modification |
| 25 | 5 | Demande identique | Références 1 à 25 à l'octet (la 24 perd la ligne de la règle éteinte, rien d'autre) |
| 26 | 5 | Conversion : chaque ligne des trois tables porte le plan de saison de sa saison ; chaque contrainte permanente a sa ligne dans le plan de saison, cochée comme l'était sa case ; la colonne disparaît ; **une règle éteinte cochée dans une fermeture ne fait pas échouer la migration** | Chaîne, comptes par table et par cas (le cas 24 porte ce décor) |
| 27 | 5 | La conversion s'annule : saison sans plan de saison ; contrainte datée éteinte | Chaîne, deux cas faussés |
| 28 | 5 | Chacun des quinze créateurs range sa ligne dans le plan de saison ; une contrainte permanente créée a sa ligne dans chaque plan, à l'identifiant calculé pour le plan de saison ; une datée n'en a aucune ; à la naissance d'un club les règles semées ont leur ligne | `tests/Integration/Plans/SeasonPlanOwnershipTest.php`, un cas par créateur |
| 29 | 5 | Une contrainte datée survit à la destruction de son plan de vacances et se retrouve dans le plan recréé | `le-socle-commande-les-plans.feature`, étape : « Et la fermeture du Gymnase Nord du 23 décembre vaut toujours pour les vacances de Noël recréées » ; variante : une doléance transférée |
| 30 | 5 | Éteindre une règle du planning de saison par l'API : elle part au moteur éteinte, la rallumer la rallume ; **chaque lecture du planning cesse de la compter** : déplacement d'une séance (`MoveSlotService`), qui tient une séance un jour de match (`PlanHoldingInputs`), personnes à deux endroits (`PlacedSessionPersonConflictDetector`, deux lectures), Récap (`ValidateConstraintsController`), coach à deux endroits (`CoachInTwoPlacesGuard`) | `tests/Api/ConstraintActiveLineTest.php`, deux cas ; `tests/Integration/Plans/AppliedInSocleReadersTest.php`, un cas par appelant (six) ; `EngineRequestKeySourcesTest` (bloquant), source de `constraints[].isActive` déplacée |
| 31 | 5 | Une équipe à deux coachs principaux garde ses deux lignes ; passer un coach de principal à adjoint ne change pas son rangement et retire le froid | `SeasonPlanOwnershipTest`, deux cas ; référence 11 identique |
| 32 | 5 | Copie d'une version : mêmes familles et mêmes lignes pour les trois familles ; la copie d'un socle porte ses lignes de règle, à l'identifiant calculé ; une version sans différence avant la conversion n'en a pas après, **y compris quand sa copie porte une contrainte supprimée depuis** ; « Charger » ramène règles, coches, passerelles et coachs principaux | `PlanStatesTest`, trois cas ; `Lot9CopiesConversionTest`, cas « plan de saison » et « contrainte disparue » ; `charger-une-version-ramene-son-etat.feature` vert ; `LoadVersionTest` (bloquant) vert |
| 33 | 5 | Supprimer un plan de période n'emporte aucune contrainte, passerelle ni lien coach-équipe ; le copier vers son plan sœur non plus ; la saison et le club partent en entier | `supprimer-emporte-ce-qui-en-depend.feature`, étape ; `PeriodPlanSiblingCopyTest`, une assertion ; `PurgeCompletenessTest` (bloquant) |
| 34 | 5 | La saison suivante reprend règles, passerelles et coachs principaux dans son propre plan ; une règle éteinte en 2026-2027 l'est en 2027-2028 | `preparer-la-saison-suivante-reprend-la-structure.feature`, étape ; `SeasonTransitionServiceTest`, un cas ; référence 20 identique |
| 35 | 5 | Abus et isolation : aucune API ne laisse choisir le plan ; une ligne qui désigne le plan d'un autre club est refusée par la base, pour chacune des trois tables ; **les routes de la coche de période ne touchent jamais une ligne du plan de saison : créer 422, lire, modifier et supprimer 404, la liste ne la rend pas, avec ou sans filtre de plan** ; « éteindre » une contrainte datée 422 ; Membre 403 | `tests/Security/PlanCompositionTenantIsolationTest.php` (bloquant), trois cas ; `tests/Api/SocleRuleLineIsNotAPeriodSettingTest.php`, cinq cas ; `ConstraintActiveLineTest`, un cas ; `MemberWriteSweepTest` vert |
| 36 | 5 | Changer la portée d'une règle ne touche pas la coche du plan de saison ; dater une règle permanente éteinte est refusé avec son vrai motif ; rallumée, elle se date | `tests/Api/ConstraintScopeChangeTest.php` (bloquant), un cas ; `tests/Api/ConstraintDatingTest.php`, deux cas |
| 37 | 5 | Le miroir d'écran des indisponibilités rend les mêmes intervalles que le serveur, règle éteinte comprise | `CoachDoubleBookingMirrorParityTest`, décor adapté : le serveur reçoit la liste filtrée, l'écran la liste et son champ `isActive` |
| 38 | 5 | Aucune forme de « plan nul » ne revient ; une famille du socle ne dit pas « copiée à la naissance » | `NoNullPlanBranchTest` (bloquant) vert sans exception neuve ; `FamilyDeclarationSweepTest` (bloquant), un cas |
| 39 | 5 | Valider après avoir éteint une règle du planning de saison annonce « 1 règle ajoutée, retirée ou modifiée » | `le-socle-commande-les-plans.feature`, un exemple |
| A1 | 5 | Une fermeture créée après qu'une règle a été éteinte dans la saison naît avec cette règle décochée, et on peut l'y cocher : elle s'applique alors à cette fermeture seule | `plan-de-periode-en-overlay.feature` : « Étant donné la règle « SM1 pas avant 20:30 » éteinte dans le planning de saison » / « Quand je crée une fermeture du Gymnase Nord » / « Alors cette règle y est décochée » / « Quand je la coche pour cette fermeture » / « Alors la génération de la fermeture respecte « SM1 pas avant 20:30 » » |
| A2 | 5 | Éteindre une règle dans la saison puis valider décoche la règle dans un plan de vacances commencé qui la suivait ; un plan où elle avait été cochée à la main la garde ; la suppression d'une coche, la liste des coches, le changement de portée et la datation lisent le même défaut | `tests/Integration/Plans/RuleLineDefaultTest.php`, six cas |
| A3 | 5 | L'écran d'une période affiche décochée une règle éteinte dans la saison, comme le serveur | `PeriodConstraints.test.tsx`, un cas ; registre de `FrontRederivationRegistryTest` (groupe `contract`) ; conversion : chaîne, un cas (les lignes de période d'une règle éteinte passent à « décochée ») |

**Retirés par `tank`, avec leur filet** : `TeamMatchDayIsoMigrationTest` (ligne 41).

**Preuve = captures (`switch`)** : PR 3, onglet « Bien-être », en saison et dans des vacances, sans temps de trajet, en Membre. PR 5 : étape Contraintes d'une fermeture, avec une règle éteinte dans la saison.

## 8. Carte des conséquences

| Comportement | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Règle de trajet | `VenueTravelRuleSettingStateProcessor.php:64-76` | `ScheduleConstraintBuilder.php:522`, `SchedulePlanProvisioner.php:971-973` | Une ligne par saison | Une ligne par plan ; repli sur le socle |
| Trajet à la saison suivante | `SeasonTransitionService.php:560-567` | Demande du socle suivant | Cran seul | Cran et seuils |
| Empreinte | `fingerprint()` `:197-212` | Les onze appelants | `MANDATORY` passé par l'appelant | Valeur rangée, traduite dans `fingerprint()` |
| Bloc de bien-être, Récap, liste servie | `ImplicitRuleResolver.php:42`, `:139-148`, `:161` | Moteur, `RecapStep.tsx:216` | Cinq clés | Inchangé |
| Cran d'une passerelle | `TeamLinkStateProcessor.php:93-94` | `ScheduleConstraintBuilder.php:1335` | `MANDATORY` rangé | `HARD` rangé, `MANDATORY` émis |
| Règles de match | `MatchConstraintStateProcessor` | Placement, radar, cohérence | Sept boucles | Trois lectures |
| Rangement d'une contrainte | Les sept créateurs ; « Charger » (`PlanStates.php:679-700`) | `ScheduleConstraintBuilder.php:863` ; `PeriodConstraintSelector.php:136` ; `PlanComposition.php:472-476` ; `PlanHoldingInputs.php:45` ; `DevScheduleReportWriter.php:97` | Club et saison | Plan de saison ; les lectures par entrée, équipe, coach, gymnase ne changent pas |
| Règles semées à la naissance d'un club | `DefaultConstraintSeeder.php:62`, depuis `ClubProvisioner.php:141` | `SoclePlan.php:36-41` | Lit le plan en SQL | Reçoit le plan |
| Contrainte datée | `CoachWishTransferService.php:296`, cockpit, `BcclSeeder.php:2248`, `:2467` | `PeriodConstraintSelector.php:90-91`, `ConstraintRepository.php:64`, `VenueClosureDays.php:302`, `CalendarEntryConflictsController.php:122-126`, `ClosureWindowApplier.php:127`, `CalendarEntryStateProcessor.php:734`, `:1028` | Attachée à son entrée ; peut être éteinte par l'API | Rangée dans le plan de saison ; toujours appliquée |
| Rangement d'une passerelle | Les quatre créateurs | `ScheduleConstraintBuilder.php:903`, `:1082`, `MatchPlacementPayloadBuilder.php:133`, `CoachWishFormPresenter.php:93`, `CascadePlan.php:63-64` | Club et saison | Plan de saison ; mêmes lignes lues |
| Rangement d'un lien coach-équipe | Les quatre créateurs ; `TeamCoachStateProcessor.php:52-55` | 22 fichiers, dont `ScheduleConstraintBuilder.php:887`, `ConflictRadarLoader.php:79`, `MatchPlacementPayloadBuilder.php:136` | Club et saison | Plan de saison, adjoints compris |
| **Case « active »** | `ConstraintStateProcessor.php:139`, `:213-215` | Les douze lecteurs ; les huit appels de `blockedIntervalsByCoach` | Colonne de la contrainte, filtrée dans la fonction | Ligne du plan de saison, lue par `appliedInSocle` avant l'appel ; `findActiveByClub` supprimée |
| **Ligne du plan de saison par les routes de période** | `ConstraintPeriodOverrideStateProcessor.php:85-88`, `:103-107`, `:115-122` | `ConstraintPeriodOverrideStateProvider.php:59-82` | Création seule gardée | Création, lecture, modification, suppression, liste : toutes gardées |
| **Changement de portée, datation** | `PlanComposition.php:283-297`, `:268-275`, `:300-305` ; `ConstraintStateProcessor.php:207-210` | Lignes de règle | Toutes les lignes de la contrainte | Lignes de période seulement ; datation d'une règle éteinte refusée |
| Coche de période et son défaut | Route `constraint_period_overrides` | `PeriodConstraintSelector.php:136-142`, les cinq endroits du défaut | Nature du plan | Nature et ligne du plan de saison, cinq endroits et l'écran |
| Miroir d'écran des indisponibilités | — | `coachDoubleBooking.ts:318-323`, `SlotReservationModal.tsx:164` | Champ `isActive` de la contrainte | Même champ, servi depuis la ligne du plan de saison |
| Copie d'une version | `PlanStates::capture` `:82-101` | « Charger », différence de copies (`:344`, `:369`), porte `InForce` | Trois familles lues par club et saison | Lues par leur plan ; lignes de règle du socle en plus, identifiant calculé |
| Suppression d'un plan de période, copie sœur | `OverlayManager.php:98-106`, `FamilyCatalog.php:140-156`, `:180-190` | `PurgeCompletenessTest` | Trois familles hors des listes | Dans la première sans ligne à emporter ; hors de la seconde |
| Saison suivante | `SeasonTransitionService.php:348`, `:412`, `:518`, `:531`, `:593` | Nouvelle saison | Lignes à la saison, case recopiée | Lignes au plan de la nouvelle saison ; coche recopiée après `ensureLines` |
| Effacer une personne | `Structure/PersonEraser.php` | Copies, liens, contraintes de portée coach | Lit par coach | Inchangé |
| Purge d'une saison, d'un club | `SeasonDataPurger`, `ErasedClubPurger` | `PurgeCompletenessTest` | Niveau saison | Niveau plan ; la cascade vers le plan est le filet |
| Graines | `BcclSeeder.php:957`, `:1039`, `:1678`, `:2248`, `:2467` ; `LoadTestClubSeeder.php:532`, `:546` | Tests de graines | — | Posent le plan ; lignes par `ensureLines` |
| Console d'administration | — | `AdminMonitoringService.php:292` | Compte les cases actives | Compte les lignes cochées du plan de saison et les datées |
| Jour de match, gymnase imposé | `TeamStateProcessor.php:110-111`, `:206-210` | `ScheduleConstraintBuilder.php:1547`, `:1571-1588` | Colonne et autre porteur | Un porteur ; clé de la demande toujours nulle |
| Demande au moteur, contrat | `EngineRequestComposer` | Moteur | — | Identique, références 1 à 25 ; contrat 1.12 ; différences : la saison suivante d'un club aux seuils de trajet réglés  ; un plan de période où une règle éteinte était cochée |

## 9. Parcours de bout en bout

- **Régler le trajet d'un plan de vacances.** Wizard des vacances de Noël, étape Contraintes, onglet « Bien-être », encart « Trajet entre gymnases », « Obligatoire ». Seul Noël change. Inverse : « Souhaitée ». Variantes : aucun temps de trajet, pas d'encart ; Membre, lecture ; saison archivée, refus.
- **Régler la saison après la création de Noël.** Même encart dans le Wizard du planning de saison. Noël, déjà commencé, garde « Souhaitée ». Ce qui le dit : dans le Wizard de Noël, la phrase de période et le repère « Saison : Obligatoire, battement 20 min, temps par défaut 20 min ».
- **Préparer la saison suivante avec un battement à 10.** Geste inchangé ; la saison suivante garde 10.
- **Poser une fermeture le 23 décembre, puis valider un planning de saison modifié.** Le plan de Noël, pas commencé, est supprimé ; recréé, il retrouve la fermeture. Comme aujourd'hui.
- **Éteindre une règle du planning de saison.** Aucun écran ; par l'API. Inverse : la rallumer. Variante : la dater pendant qu'elle est éteinte, refusé (texte ci-dessous). L’effet sur les périodes : chaque plan a ses coches (décision 6).
- **Cocher ou décocher une règle dans une période.** Wizard de la période, étape Contraintes : geste inchangé.
- **Changer le coach principal, passerelle obligatoire, indisponibilité de match, imposer un gymnase, jour de match** : gestes inchangés.

**Textes qui changent, mot pour mot**

| Où | Texte |
|---|---|
| Encart de trajet, dans une période | Sous la phrase existante `periodScope` (`texts/implicitRules.ts:23`), inchangée |
| Repère dans l'encart, en période (neuf) | « Saison : {cran}, battement {n} min, temps par défaut {m} min » |
| Refus de l'API, contrainte datée | « Une contrainte datée ne se désactive pas : supprimez-la. » |
| Refus de l'API, datation d'une règle éteinte (`ConstraintStateProcessor.php:207-210`) | « Cette contrainte est éteinte dans le planning de saison : rallumez-la avant de la dater. » ; le texte existant « Cette contrainte a des décochages par plan : retirez-les avant de la dater. » reste pour les coches de période |
| Refus de l'API, coche de période visant le plan de saison (`ConstraintPeriodOverrideStateProcessor.php:87`) | « La coche d'une règle du planning de saison se règle sur la règle elle-même. » (remplace « Le planning de saison applique toutes ses règles : une règle ne s'y décoche pas. ») |
| Fenêtre de suppression d'un gymnase (`CascadePlan.php:117`) | « équipe qui perdra son gymnase imposé » / « équipes qui perdront leur gymnase imposé » : supprimé |
| Dialogue de validation du lot 5 | « 1 équipe change de catégorie, de niveau ou de rang » / « {n} équipes changent de catégorie, de niveau ou de rang » ; les autres lignes inchangées |

Les trois refus de l'API ne sont vus que par un script : aucun écran ne fait ces gestes.

## 10. Décisions du fondateur (2026-11-27)

1. Indisponibilité d'un coach : deux saisies gardées.
2. La règle de trajet est propre à chaque plan.
3. La PR 1 dès qu'une place se libère ; les PR 2 à 6 après le lot 7 et la PR 7 du lot 5, avant le lot 6.
4. La saison suivante reprend battement et temps par défaut.
5. Les contraintes, les passerelles et le coach principal passent au plan dans ce lot ; la case « active » est réunie avec la coche de période.
6. **Une règle éteinte dans le planning de saison : chaque plan a ses coches** (réponse A). Exemple : le planning de saison a 12 règles. Les vacances de Noël ont commencé ; « SM1 pas avant 20:30 » y est cochée. On éteint cette règle dans le planning de saison, on valide, puis on crée la fermeture du Gymnase Nord du 5 au 11 janvier : la fermeture naît avec 11 règles cochées et celle-ci décochée, et on peut la recocher pour cette semaine-là seule. À Noël, la règle est décochée à la validation du planning de saison si personne n'y avait touché, gardée si elle y avait été cochée exprès. Ce que cela coûte dans ce lot : le défaut d'une coche est écrit à cinq endroits, dont un écran, tous changés ; une conversion décoche, dans les périodes, les règles déjà éteintes en saison ; la PR 5 grossit. L'autre réponse (« la saison commande », la règle éteinte l'est partout) est écartée.

**Tranché par ce plan** (§3.5) : la contrainte datée ; plusieurs coachs principaux ; les copies par version ; un seul écrivain de la ligne du plan de saison ; dater une règle éteinte est refusé.

## 11. Limites écrites

1. Les écarts E1 à E8.
2. Ce plan est la dernière version.
3. La PR 5 est la plus grosse du lot. Si elle dépasse ce qui se relit, elle se scinde à l'ouverture, sans changer ce plan : les colonnes de plan, puis la ligne du plan de saison.
4. Le code des PR 4 à 7 du lot 5 n'existe pas encore : la porte `InForce`, la propagation des lignes de règle à la validation et la saison suivante lue sur le catalogue sont à relire avant d'ouvrir les PR 3 et 5. `onSocleAppliesChanged` s’accroche à cette propagation.
5. Un plan de période où une règle éteinte était cochée voit sa demande perdre une ligne que le moteur ignorait ; ses versions s'afficheront « à régénérer ». Attendu : aucun (mesure 6).
6. Deux lectures du planning prennent toutes les contraintes du club et de la saison, datées comprises, sans regarder la coche de période (`MoveSlotService.php:975`, `PlacedSessionPersonConflictDetector.php:145`) ; le miroir d'écran des indisponibilités lit la liste de la saison. Le plan garde ces trois comportements tels quels.
7. Une contrainte datée ne peut plus être éteinte, seulement supprimée ; la migration s'annule s'il en existe une éteinte.
8. Une contrainte datée est rangée dans un plan de saison qui ne l'applique pas : sa colonne de plan dit sa saison, pas qui la lit.
9. Les lectures par équipe, coach, gymnase et entrée de calendrier ne passent pas par le plan ; elles donnent les mêmes lignes tant qu'une saison n'a qu'un plan de saison.
10. Pas de clé étrangère de `plan_constraint` vers la contrainte dans ce lot.
11. L'identifiant calculé d'une ligne de règle du plan de saison n'a pas les marques de version d'un identifiant standard ; il est valide pour la colonne.
12. Le moteur garde deux mots pour « dur » ; la clé `teams[].forcedVenueId` reste, toujours nulle, jusqu'au prochain changement de contrat.
13. Un script qui envoie `matchDay`, `forcedVenueId` ou un plan à une API qui ne les lit pas n'est pas refusé : le champ est ignoré.
14. Une copie de version d'une base de travail qui portait un gymnase imposé en colonne le perd ; aucune en production.
15. Les migrations des PR 2, 3, 5 et 6 s'annulent sur des données hors attendu ; les mesures 1, 2, 4, 5, 6 et 7 se refont juste avant la mise en production.
16. Côté planning de saison, aucun texte ne dit qu'un plan de vacances commencé garde sa règle de trajet. Le Récap n'annonce pas un trajet « Ignorée », comme aujourd'hui.
17. La garde de la PR 3 porte sur zéro ligne de bien-être, pas sur cinq : un plan né avant la cinquième règle peut en porter quatre.
18. Non vérifié : que `member-read-only.spec.ts` couvre l'encart de trajet ; le coût de la chaîne par cas ; qu'une recherche Doctrine par clé accepte une énumération sur une colonne devenue texte ; ce que la purge de collecte fait des contraintes nées d'une doléance transférée ; l'ordre des purges de saison et de club pour une famille passée au niveau plan ; qu'aucune étape de suppression ne compte une ligne de règle du plan de saison comme un « réglage de période ».
19. Les sites à corriger en PR 2 et en PR 5 sont ceux que PHPStan refuse une fois les énumérations et `getIsActive()` supprimés ; les listes du §2 en donnent le compte attendu, douze et huit.

## 12. Checklist de cadrage

- **Zones** : `backend/`, `frontend/` (PR 2 et 3 ; PR 5), `docs/`, `specs/`. `.github/workflows/ci.yml` : aucun test neuf bloquant ; les cas d'isolation de la PR 5 entrent dans `PlanCompositionTenantIsolationTest` et `ConstraintScopeChangeTest`, déjà bloquants. `engine/`, `landing/`, `system-pages/` : aucun fichier.
- **Interdits** : `engine/**`, `engine/CONTRACT_VERSION`, les trois `*ContractSchemaTest`, les références 1 à 19, 21 et 22, `tests/Fixtures/lot-4/`, `Entity/MatchConstraint.php`, `Enum/ImplicitRuleKey.php`, `Enum/LockLevel.php`, la liste d'exceptions de `NoNullPlanBranchTest`, `frontend/src/features/wizard/lib/coachDoubleBooking.ts`, `scripts/remote-deploy.sh`, `.github/workflows/deploy.yml`, `docs/ops/deploy.md`, `frontend/src/features/matches/ConstraintsPage.tsx`.
- **Aucun refactoring hors périmètre** : confirmé. Non touchés : `LockLevel`, la doléance, le plancher de séances, la table et l'API des règles de match, les libellés, le nom de la ressource d'API du trajet et de celle des coches de période, les API des contraintes, passerelles et liens coach-équipe (aucun champ de plan), les lectures par équipe, coach, gymnase et entrée de calendrier, les trois comportements de la limite 6, les méthodes sans appelant de `ConstraintRepository` autres que `findActiveByClub`.
- **Axes §7.1 et NR** : *sens des contraintes* (10, 15, 16, 17, 24, 30, 36, 37, 42, 43, A1, A2) ; *chaîne de génération* (1, 2, 6, 13, 23, 25, 40) ; *cycle des plans* (7, 15, 18, 19, 29, 32, 33, 34, 39, 45) ; *isolation* (20, 22, 24, 35) ; *périmètre engagé*, *auth et adhésions* : non touchés ; *contrat avec le moteur* : non modifié. Non bloquants, et le plan le dit : `Lot9CopiesConversionTest`, `RuleIntensityCheckTest`, `TravelRuleStorageTest`, `SeasonPlanOwnershipTest`, `ConstraintActiveLineTest`, `AppliedInSocleReadersTest`, `SocleRuleLineIsNotAPeriodSettingTest`, `ConstraintDatingTest`, `RuleLineDefaultTest`.
- **Tests d'abus** : pas de section « Signaux » ; chemins nommés : lignes 10, 20, 24, 35, 36.
- **`/security-review`** : PR 3, PR 4, PR 5.
- **Contre-revue** : `smith`, deux passes faites ; pas de troisième version à revoir.
- **Behat touchés** : `plan-de-periode-en-overlay.feature` (un scénario neuf, un second pour la coche d’une règle éteinte en saison), `preparer-la-saison-suivante-reprend-la-structure.feature`, `le-socle-commande-les-plans.feature`, `supprimer-emporte-ce-qui-en-depend.feature`, `charger-une-version-ramene-son-etat.feature` (étapes) ; contextes `LoadVersionContext` (`:136`, `:154`, `:311`), `SeasonTransitionContext`, `DeletionCascadeContext`, et tout contexte qui écrit `constraint`, `team_link` ou `team_coach` en SQL brut, à relever par `tank` (non vérifié) ; verts sans modification : `placement-des-matchs`, `les-conflits-d-un-match-disent-la-verite`, `voeux-des-coachs`, `la-boucle-de-travail-du-gestionnaire`, `une-contrainte-saisie-est-honoree`, `une-regle-de-categorie-suit-ses-equipes`.
- **Playwright** : aucun spec ne nomme ces routes ni ces valeurs (recherche du 2026-11-26) ; `visual-reference.spec.ts` à rejouer en PR 3 ; `member-read-only.spec.ts` à confirmer.
- **Feature qui prouve la zone** : `generation-du-planning-de-saison.feature`, `placement-des-matchs.feature`, `le-socle-commande-les-plans.feature`.
- **Plafonds**, estimations non mesurées : backend +50 s (trois références, deux cas de chaîne, sept cas faussés, neuf tests d'intégration ou d'API), Behat +30 s (le scénario A1 appelle le moteur), front +1 s, e2e 0. Si backend déborde : un cas faussé par migration, la ligne 28 groupe les créateurs par table. Si Behat déborde : les lignes 15 et 29 gardent un exemple, A1 s'arrête à la coche sans génération. Au-delà, ligne datée du fondateur.
- **Contrôle visuel** : PR 3 ; PR 5.
- **Documentation** : au fil des PR : `backend/docs/constraints.md`, `constraint-coverage.md`, `generation-flow.md`, `backend-inventory.md`, `backend-controllers.md`, `backend/AGENTS.md`, `backend/docs/TENANT.md`, `RLS.md`, `docs/security/rls.md`, `docs/architecture/constraint-matrix.md`, `docs/glossary.md`, `frontend/docs/constraint-emission.md`, `frontend-wizard.md`, `openapi-snapshot.json`, `lot-conception-filet.md`. PR 5 : l'ADR du lot 5 qui remplace l'ADR-0002 (ce que le plan de saison porte, la contrainte datée, la ligne du plan de saison), l'ADR-0005 (trois familles changent de niveau), `CLAUDE.md` §6 si une ligne le demande. PR 6 : un ADR « un vocabulaire d'intensité, le trajet parmi les réglages » au prochain numéro libre, `adr-index.md`, fiche du lot 9 et §6 du découpage (écarts et six réponses), le renvoi « lot 9 » des plans des lots 4 et 5 (fermé), `etat-des-lieux.md`, `test-coverage-map.md`.
- **Retour au fondateur si** : une mesure 1, 2, 4, 5, 6 (datées) ou 7 hors attendu ; une référence autre que la 20 qui devrait changer (et la 24) ; `diff -r` non vide ; un plafond ; un lecteur de la case qui ne peut pas passer par `appliedInSocle`.

## 13. Exécution

**Plan validé par le fondateur le 2026-11-27. La PR 1 peut partir dès maintenant ; pas d'exécution des PR 2 à 6 avant la fin du lot 7, la PR 7 du lot 5 et les mesures de `link`.**

| Ordre | PR | Ce qu'elle livre | Ce qui la rend rouge |
|---|---|---|---|
| 1 | PR 1 · Le filet (dès qu'une place se libère) | Références 23 à 25, deux cas de chaîne, deux scénarios de l'actuel | Une référence 1 à 21 qui bouge |
| — | Lot 7 (sept PR restantes), puis lot 5 PR 4 à 7 | Hors de ce plan | Les références 23 à 25 doivent y rester vertes |
| 2 | PR 2 · Le vocabulaire | Une énumération, une union, une traduction | `MANDATORY` encore accepté ; `HARD` émis au moteur pour le trajet ou une passerelle ; une version périmée par la conversion ; « éteint » accepté sur une contrainte |
| 3 | PR 3 · Le trajet | Une ligne de réglage par plan, table supprimée, reprise entière à la saison suivante | Un bloc de bien-être à six clés ; un plan de période sans ligne qui ne suit plus la saison ; le trajet d'un plan qui change celui d'un autre |
| 4 | PR 4 · Les lectures de match | Trois lectures de dépôt | Une demande de placement qui change ; une alerte de match levée par une indisponibilité du Wizard |
| 5 | PR 5 · Le plan de saison porte ses règles | Colonne de plan sur trois tables ; la ligne du plan de saison à la place de la case « active » | Une référence qui change (hors la 24) ; une ligne au plan d'un autre club ; une contrainte datée perdue avec son plan de vacances ; une route de période qui touche ou montre une ligne du plan de saison ; un changement de portée qui rallume une règle ; une règle éteinte datée en silence ; une lecture du planning qui compte encore une règle éteinte ; une version qui montre une différence née de la conversion ; une migration qui échoue sur une règle éteinte cochée dans une fermeture ; une exception de plus dans `NoNullPlanBranchTest` |
| 6 | PR 6 · Les colonnes de l'équipe, les documents | Deux colonnes en moins, ADR, état des lieux | Une référence autre que la 20 qui change ; une migration qui passe malgré une équipe hors attendu |
| — | Lot 6 | Hors de ce plan | — |

**Laissé hors périmètre, volontairement** : `LockLevel`, la forme unique en base des règles de match, des contraintes propres à chaque plan de période, la clé étrangère vers la contrainte, une saisie unique de l'indisponibilité, l'écran qui éteint une règle du planning de saison (lot 6), les trois lectures de la limite 6, le plancher de séances, le vocabulaire du moteur.

## Changements depuis la version précédente

| Correction de `smith` | Vérifié dans le code | Section modifiée |
|---|---|---|
| 1 · Routes de la coche de période sans garde de plan de saison | Oui : `ConstraintPeriodOverrideStateProcessor.php:103-107`, `:115-122`, `ConstraintPeriodOverrideStateProvider.php:59-82` | §2, §3.5 (un seul écrivain), §4 PR 5, §5, §8 (ligne neuve), §9 (texte revu), ligne 35 |
| 2 · Le sens de `isActive` change ; la garde de migration 3 est piégée | Oui : `PeriodConstraintSelector.php:136-147`, `ScheduleConstraintBuilder.php:1819` | §2 (« éteinte, une règle l'est dans tous les plans »), §3.5 (« Chaque plan a ses coches »), §4 PR 5 (garde 3 retirée ; conversion à « décochée »), ligne 26, décision 6 étendue aux plans existants, référence 24 réécrite |
| 3 · Chaque plan a ses coches : cela casse « défaut = nature du plan » à cinq endroits | Oui, les cinq, dont `PeriodConstraints.tsx:139-146`, `:156` | §2, §3.5, §4 PR 5 (le défaut d’une coche, fichier d'écran compris), §5, lignes A1 à A3, décision 6 (son coût), §12 |
| 4 · Identifiant des lignes de règle dans les copies converties | Oui : `PlanStates.php:344`, `:369`, `:686` | §3.5 point 3 (identifiant calculé), §5 (`socleLineId`), lignes 28 et 32, limite 11 |
| 5 · Douzième lecteur, code mort, miroir d'écran | Oui : `CalendarEntryConflictsController.php:122-126` ; `findActiveByClub` sans appelant ; `coachDoubleBooking.ts:318-323` | §2, §3.5, §4 PR 5, §8, ligne 37, §12 (le fichier du miroir est interdit : il ne change pas) |
| 6 · Appelants de `blockedIntervalsByCoach` | Oui, les huit | §2, §5 (`appliedInSocle`), ligne 30 (un cas par appelant), limite 6 |
| 7 · Dater une règle permanente éteinte | Oui : `ConstraintStateProcessor.php:207-210`, `PlanComposition.php:300-305` | §3.5 (refus), §9 (texte), ligne 36 |
| Citations décalées | Oui | §2 : `ConstraintRepository.php:43`, `:64`, `:97-104` ; `PlanStates.php:49` |
| `DefaultConstraintSeeder` et `SoclePlan::idOf` | Oui : `:62-73`, `SoclePlan.php:36-41`, `ClubProvisioner.php:141` | §2, §4 PR 5, §5, §8, ligne 28 |
| Saison suivante : `ensureLines` pose le défaut | Oui : `SeasonTransitionService.php:593` | §2, §4 PR 5, §8, ligne 34 |
| Message `ConstraintPeriodOverrideStateProcessor.php:87` | Oui | §9 |
| §3.5 point 2 : la demande lit l'état vivant | Oui : `ScheduleConstraintBuilder.php:887`, `:903` | §2, §3.5 point 2 |

**Ajouté au-delà de la contre-revue, trouvé en la vérifiant** : `onScopeChanged` et la garde de datation parcourent toutes les lignes d'une contrainte et auraient touché la ligne du plan de saison (§3.5, §8, ligne 36).

**Décisions de la v3 retirées** : la garde de migration « contrainte éteinte cochée dans un plan de période » (piégée) ; `PlanComposition::appliedConstraints(planId)`, remplacée par `appliedInSocle` (les lecteurs lisaient la case de la saison, pas une coche de plan) ; « la PR 5 ne touche aucun fichier d'écran » (faux avec la décision 6)  ; l'option « naît cochée selon sa seule nature » de la v3, qui ne disait rien des plans existants ; le nom `periode-24-vacances-trajet-de-la-saison` (la référence porte désormais la règle éteinte cochée).

## Fichiers à relire en premier

- /home/marabou/projects/scheduler/specs/evolution/lot-conception-decoupage.md
- /home/marabou/projects/scheduler/specs/evolution/conception-cible.md
- /home/marabou/projects/scheduler/specs/evolution/lot-conception-4-le-plan-porte-ses-donnees.md
- /home/marabou/projects/scheduler/specs/evolution/lot-conception-5-le-socle-froid.md
- /home/marabou/projects/scheduler/backend/src/Entity/Constraint.php
- /home/marabou/projects/scheduler/backend/src/Entity/PlanConstraint.php
- /home/marabou/projects/scheduler/backend/src/Entity/TeamLink.php
- /home/marabou/projects/scheduler/backend/src/Entity/TeamCoach.php
- /home/marabou/projects/scheduler/backend/src/Entity/CalendarEntry.php
- /home/marabou/projects/scheduler/backend/src/Entity/ImplicitRuleSetting.php
- /home/marabou/projects/scheduler/backend/src/Entity/VenueTravelRuleSetting.php
- /home/marabou/projects/scheduler/backend/src/Entity/MatchConstraint.php
- /home/marabou/projects/scheduler/backend/src/Entity/Team.php
- /home/marabou/projects/scheduler/backend/src/Plans/PlanComposition.php
- /home/marabou/projects/scheduler/backend/src/Plans/PlanStates.php
- /home/marabou/projects/scheduler/backend/src/Plans/PlanBirth.php
- /home/marabou/projects/scheduler/backend/src/Plans/SoclePlan.php
- /home/marabou/projects/scheduler/backend/src/Structure/FamilyCatalog.php
- /home/marabou/projects/scheduler/backend/src/Structure/PersonEraser.php
- /home/marabou/projects/scheduler/backend/src/Repository/ConstraintRepository.php
- /home/marabou/projects/scheduler/backend/src/Repository/ImplicitRuleSettingRepository.php
- /home/marabou/projects/scheduler/backend/src/Repository/MatchConstraintRepository.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/ConstraintStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/ConstraintPeriodOverrideStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/State/Provider/ConstraintPeriodOverrideStateProvider.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/TeamCoachStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/TeamLinkStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/TeamStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/State/Processor/VenueTravelRuleSettingStateProcessor.php
- /home/marabou/projects/scheduler/backend/src/ApiResource/ConstraintResource.php
- /home/marabou/projects/scheduler/backend/src/Controller/CalendarEntryConflictsController.php
- /home/marabou/projects/scheduler/backend/src/Controller/ValidateConstraintsController.php
- /home/marabou/projects/scheduler/backend/src/Service/PeriodConstraintSelector.php
- /home/marabou/projects/scheduler/backend/src/Service/ScheduleConstraintBuilder.php
- /home/marabou/projects/scheduler/backend/src/Service/SchedulePlanProvisioner.php
- /home/marabou/projects/scheduler/backend/src/Service/SeasonTransitionService.php
- /home/marabou/projects/scheduler/backend/src/Service/OverlayManager.php
- /home/marabou/projects/scheduler/backend/src/Service/ImplicitRuleResolver.php
- /home/marabou/projects/scheduler/backend/src/Service/CoachDoubleBookingDetector.php
- /home/marabou/projects/scheduler/backend/src/Service/MoveSlotService.php
- /home/marabou/projects/scheduler/backend/src/Service/PlanHoldingInputs.php
- /home/marabou/projects/scheduler/backend/src/Service/PlacedSessionPersonConflictDetector.php
- /home/marabou/projects/scheduler/backend/src/Service/CoachInTwoPlacesGuard.php
- /home/marabou/projects/scheduler/backend/src/Service/VenueClosureDays.php
- /home/marabou/projects/scheduler/backend/src/Service/CoachWishTransferService.php
- /home/marabou/projects/scheduler/backend/src/Service/DefaultConstraintSeeder.php
- /home/marabou/projects/scheduler/backend/src/Service/ClubProvisioner.php
- /home/marabou/projects/scheduler/backend/src/Service/AdminMonitoringService.php
- /home/marabou/projects/scheduler/backend/src/Service/MatchPlacementPayloadBuilder.php
- /home/marabou/projects/scheduler/backend/src/Service/ConflictRadarLoader.php
- /home/marabou/projects/scheduler/backend/src/Deletion/CascadePlan.php
- /home/marabou/projects/scheduler/backend/src/Seed/BcclSeeder.php
- /home/marabou/projects/scheduler/backend/src/Seed/LoadTestClubSeeder.php
- /home/marabou/projects/scheduler/backend/tests/Architecture/NoNullPlanBranchTest.php
- /home/marabou/projects/scheduler/backend/tests/Integration/Migration/Lot4MigrationChainTest.php
- /home/marabou/projects/scheduler/backend/tests/Integration/EngineRequestKeySourcesTest.php
- /home/marabou/projects/scheduler/backend/tests/Integration/TravelRuleFingerprintTest.php
- /home/marabou/projects/scheduler/backend/tests/Security/PlanCompositionTenantIsolationTest.php
- /home/marabou/projects/scheduler/backend/tests/CrossStack/CoachDoubleBookingMirrorParityTest.php
- /home/marabou/projects/scheduler/backend/tests/CrossStack/FrontRederivationRegistryTest.php
- /home/marabou/projects/scheduler/backend/tests/EngineRequestDecorTrait.php
- /home/marabou/projects/scheduler/backend/tests/EngineRequestGroupRulesDecorTrait.php
- /home/marabou/projects/scheduler/backend/tests/EngineRequestSocleNetDecorTrait.php
- /home/marabou/projects/scheduler/frontend/src/features/wizard/steps/PeriodConstraints.tsx
- /home/marabou/projects/scheduler/frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx
- /home/marabou/projects/scheduler/frontend/src/features/wizard/steps/ConstraintsStep.tsx
- /home/marabou/projects/scheduler/frontend/src/features/wizard/steps/RecapStep.tsx
- /home/marabou/projects/scheduler/frontend/src/features/wizard/lib/coachDoubleBooking.ts
- /home/marabou/projects/scheduler/frontend/src/features/wizard/api.ts
- /home/marabou/projects/scheduler/frontend/src/features/matches/TeamLinksSection.tsx
- /home/marabou/projects/scheduler/engine/app/schemas/input_schema.py
- /home/marabou/projects/scheduler/engine/app/solver/constraints/targeting.py
- /home/marabou/projects/scheduler/test-time-ceiling.json