# Lot 4 · Le plan porte ses données : plan d'exécution

**Statut : version finale (v3 sur 4, après deux passes de `smith`). Plan validé par le fondateur le 2026-11-22 avec sa
décision (§10) et l'écart E1 (§3.7). Aucune PR livrée ; le lot 4 s'exécute après le lot 3 entier, une fois les mesures
de `link` (§6) faites.** Voie **Full**. Branche cible : `lot/conception` (une PR ouverte à la fois ; « Lot Gate » seul :
`docs/ops/ci-runner.md` § Branche de lot ; avant chaque merge : `make -C backend tests-complete` + features et specs de la
zone sur la stack d'épreuve). Exécutant : `neo`, après les rouges de `tank`. Cadrage : la conception validée en tient lieu
(cible §3, §11, §12, §13, §27 de [`conception-cible.md`](conception-cible.md)). La fiche du lot est dans
[`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 4 ; ce fichier dit **comment**.

**Ce que ce plan suppose** : lots 0, 1, 2 dans le code (tête `70121b0cf` de `lot/conception`). **Lot 3 non construit** :
supposé livré tel que son plan l'écrit ([`lot-conception-3-personnes-et-lieux.md`](lot-conception-3-personnes-et-lieux.md)).
Les `fichier:ligne` sont ceux du 2026-11-22 : **à relire à l'ouverture de chaque PR**.

**Non lu en entier** : `SeasonTransitionService`, `PlanVenueClosures`, `PeriodPlanTranscriber` hors `:256-276`, les écrans
`Period*.tsx` hors `PeriodTeams.tsx:171-198`.

**Écart au découpage validé et décisions** : (1) la fiche dit « les contraintes appartiennent au plan » ; ici une
contrainte reste rangée à la saison et le plan porte la liste de celles qu'il applique (E1), écart validé par le
fondateur en validant ce plan ; (2) la décision du §10 (un plan de vacances commencé garde ce qu'il avait à sa création) ;
(3) une information sur la pré-sélection des équipes (§10), qui n'est pas un choix.

---

## 1. Besoin reformulé

1. Le socle cesse d'être « l'absence de plan » : toute donnée de planning porte l'identifiant de son plan.
2. Ce qu'un plan de période règle (qui participe, combien de séances, quels gymnases, quelles règles) est rangé dans le plan, en lignes complètes.
3. Un seul constructeur de la demande au moteur, une seule copie de naissance, un seul pointeur de version chargée ; la nature d'un plan se lit sur le plan.
4. La demande envoyée au moteur est identique, socle et périodes. **Le gestionnaire ne voit rien changer, sauf un point** (décision du §10) : un plan de vacances déjà commencé garde les séances, le plafond par jour et les règles de bien-être qu'il avait à sa création, et les équipes dont le socle a changé depuis y apparaissent avec un réglage propre. C'est le seul changement visible du lot hors information.

## 2. Constats vérifiés dans le code

**« Plan nul = socle »**
- Six familles ont `schedulePlanId` nullable, nul = socle : `Entity/VenueTrainingSlot.php:80`, `VenueOccupancy.php:75`, `Reservation.php:71`, `SharedTrainingBlock.php:73`, `SharedTrainingBlockTeam.php:44`, `ImplicitRuleSetting.php:71`.
- **Le compte de fichiers n'est pas fiable** (43 à 47 selon le motif) : seul un balayage fait foi. La branche prend des formes qu'un grep littéral ne voit pas, vérifiées :
  - valeur conditionnelle dans un critère : `MoveSlotService.php:1057` (`'schedulePlanId' => $period ? … : null`), `ScheduleResultImporter.php:234` (origine des verrous à l'import, en aval de la demande, donc hors des références) ;
  - variable mise à nul puis passée plus loin : `GenerationBlockersGuard.php:87`, `MutualizeSlotService.php:109`, `:334` ;
  - requête fabriquée : `StructureRestorer.php:294` (`sprintf('e.%s IS NULL', StructureAnchor::of(…))`) ;
  - « non nul = période » : `StructureRestorer.php:170`, `:191` (`IS NOT NULL`), `State/Processor/VenueTrainingSlotStateProcessor.php:269` (refuserait le libellé de groupe sur tout créneau du socle) ;
  - « NULL = la structure partagée » : `StructureAnchor.php:57-66`, `StructureSnapshotter.php:128-130` ;
  - paramètres nullables : 63 à 87 signatures `?string $schedulePlanId` ou `$planId` dans `backend/src` (87 occurrences dans 43 fichiers par mon grep, 63 par celui de `smith`).
  - Autres, vérifiés : `Controller/GroupReservationController.php:98`, `State/Provider/ImplicitRuleSettingStateProvider.php:46`, `SharedTrainingBlockStateProvider.php:62`, et la liste de la v1 (`ScheduleConstraintBuilder.php:273`, `:302`, `:305`, etc.).
- **Piège** : colonne obligatoire, un critère resté à nul rend une liste vide, sans erreur.
- Les deux unicités qui portent la colonne sont déjà `NULLS NOT DISTINCT` (`migrations/Version20261008100000.php:34`, `Version20260817130000.php:41`).
- **Six clés étrangères vers `schedule_plan` existent, toutes `ON DELETE CASCADE`** : `reservation` et `venue_training_slot` (`Version20260727120000.php:59`, `:85`), `venue_occupancy` (`Version20261117130000.php:40`), les trois tables d'exception (`Version20260807120000.php:34`, `:68`). `shared_training_block`, `shared_training_block_team`, `implicit_rule_setting` n'en ont pas. `SchedulePlanProvisioner::deletePeriodPlan` (`:418-426`) supprime le plan en SQL brut et compte sur ces cascades. Aucune clé vers `team`, `venue`, `constraint` ; aucune clé composée avec le club.

**« Charger » une version du socle** (`Service/StructureRestorer.php`)
- `wipeStructure` (`:259-275`) supprime en masse réservations et contraintes permanentes, équipes, créneaux permanents, gymnases, coachs, puis la photo est réinsérée avec ses identifiants ; les lignes de période dont la cible a disparu sont élaguées (`:160`, `:170`, `:191`).
- `hydrate` (`:303-311`) ignore en silence toute clé de la photo qui n'est pas un champ de l'entité.

**Deux constructeurs**
- `ScheduleConstraintBuilder::buildForClubSeason` (`:244`) et `buildForPeriodPlan` (`:390`, adaptateur `:358`), choisis par : `EngineRequestComposer.php:58`, `:141` ; `GenerationBlockersGuard.php:61`, `:72` ; `SchedulePlanProvisioner.php:1046`, `:1060` ; `ValidateConstraintsController.php:163`, `:166` ; `MoveSlotService.php:1416-1417` ; `PeriodPlanTranscriber.php:236` ; `PeriodPlanSiblingCopier.php:128` ; `BcclSeeder.php:1878`, `:2381`.
- Différences à garder à l'octet : verrous (`:297` contre `:509-512` et `:610-614`), cache (`:253-262`), règles implicites (`:750-754`), filtres après sérialisation (`:558-595`).

**Les trois tables d'exception**
- `TeamPeriodOverride` : participation, séances et plafond par jour (nul = valeur de saison lue en direct), origine (`Entity/TeamPeriodOverride.php:63-81`). « Une exception existe » décide de la transcription (`PeriodPlanTranscriber.php:258-275`), de l'écart au socle (`SocleDeviationCalculator.php:283`), du seuil à l'écran (`frontend/src/features/planning/lib/drift.ts:34-47`), du compte de la fenêtre de suppression (`Deletion/CascadePlan.php:79`).
- `ConstraintPeriodOverride` : un booléen ; sans ligne, **valeur calculée à chaque lecture depuis la portée de la contrainte** (`PeriodConstraintSelector.php:124-128` : fermeture, ou portée club). Or la portée d'une contrainte se modifie (`ConstraintStateProcessor.php:136`, `:141` « a PUT that widens ») et l'écran renvoie la portée à chaque modification (`ConstraintsStep.tsx:460`, `wizard/api.ts:767-769`) ; que le formulaire laisse la changer : non vérifié. Dater une contrainte est refusé dès qu'elle a une exception (`ConstraintStateProcessor.php:175-176`) ; rendre permanente une contrainte datée n'existe pas (`:169`, dit de `smith`).
- `VenuePeriodOverride` : mode (hors service ; « grille ressaisie », `PlanVenueClosures.php:233`, `:377`) et masque de jours ; compté par `CascadePlan.php:108`. La demande de placement ne la lit pas (`MatchPlacementPayloadBuilder.php:256-259`).
- L'identifiant d'une contrainte part au moteur (`ScheduleConstraintBuilder.php:1697`, `:1669`, `:1689`).

**Qui crée quoi**
- Équipes, sept endroits : `TeamStateProcessor.php:80`, `SeasonTransitionService.php:309`, `ParcImport/ParcImportExecutor.php:330`, `Basketball/FfbbTeamImporter.php:138`, `Basketball/FfbbExcelImporter.php:157`, `Seed/BcclSeeder.php:676`, `Seed/LoadTestClubSeeder.php:404`.
- Gymnases, cinq : `VenueStateProcessor.php:70`, `SeasonTransitionService.php:199`, `ParcImportExecutor.php:258`, `BcclSeeder.php:418`, `LoadTestClubSeeder.php:352`.
- Contraintes, sept : `ConstraintStateProcessor.php:95`, `DefaultConstraintSeeder.php:60`, `SeasonTransitionService.php:531`, `CoachWishTransferService.php:307`, `BcclSeeder.php:1023`, `:2254`, `:2469`.
- Séances et plafond sur l'équipe (`Entity/Team.php:74`, `:82`) ; 19 fichiers de `src`, 114 de test. Le serveur ne bloque que le plafond par jour quand le socle est validé (`TeamStateProcessor.php:175-177`) ; les séances s'écrivent sans garde (`:168-170`), verrou d'écran seulement (`TeamsStep.tsx:197-203`) ; l'import du parc les écrit (`ParcImportExecutor.php:309-310`).

**Drapeau, pointeurs, nature, copies, cycle**
- `SchedulePlan.teamSelectionInitialized` (`Entity/SchedulePlan.php:153-154`) : pré-sélection d'un plan de vacances (« Fanion et importantes ; à défaut le meilleur rang présent », `PeriodTeams.tsx:196-198`) semée par l'écran à la première ouverture de l'étape Équipes (`:192`), ou par le serveur si un transfert de doléances passe avant (`PlanTeamSelectionSeeder.php:111`). Miroir gardé par `periodSeedSelection.parity.test.ts` et `tests/CrossStack/PeriodSeedSelectionMirrorParityTest.php`.
- Deux pointeurs de version chargée (`Entity/Season.php:62-64`, `Entity/SchedulePlan.php:104-106`), réunis par un `CASE` (`SchedulePlanProvisioner.php:289-293`). L'étoile arrive à l'écran par `State/Provider/ScheduleStateProvider.php:88` ; autre lecteur `SecondSessionSameDayDetector.php:77`. `ScheduleResource.schedulePlanId` porte le vrai identifiant, lu par `frontend/src/features/cockpit/seasonPlannings.ts:49`.
- Nature : `SchedulePlan.type` (`:73-74`), recopiée du calendrier (`SchedulePlanProvisioner.php:1094-1100`, `:1198`). Douze lecteurs de `getPeriodType()` à trier (non vérifié), hors `PeriodConstraintSelector.php:67`, `:125`.
- Copies de naissance : `SchedulePlanProvisioner.php:1270-1286`, `:1296-1308`, `:1240-1242` et `:1329-1371` (fermeture seulement), `:1246` ; plan vers plan `PeriodPlanSiblingCopier.php:171-240`. Un plan ancien sans copie de règles de bien-être suit la saison (`ImplicitRuleResolver.php:111-117`).
- `OverlayManager::purgePlanAnchoredSettings` (`:105-115`) : liste tenue à la main ; le cache des demandes n'est purgé que sur les événements de l'ORM (`EventListener/CacheInvalidationListener.php:52-62`).
- Rouvrir le socle ne détruit que les plans de période à venir (`Controller/ReopenScheduleController.php:93-119`) ; un plan de période commencé se valide seul (`backend/features/un-planning-de-periode-commence-se-valide-seul.feature:9-15`).

**Filet**
- Références 1 à 8, `tests/Integration/EngineRequestReferenceTest.php` (bloquant). Décor écrit par les setters des entités (`backend/tests/EngineRequestDecorTrait.php:193`).
- Les tests de migration existants (`tests/Integration/Migration/`, six fichiers, dont `VenueUnavailabilityDropMigrationTest`) rejouent `down()` dans une transaction annulée (dit de `smith`, non relu) : inapplicable à des migrations sans `down()`.
- Plafonds : backend 1000 s, Behat 950 s, e2e 635 s, front 230 s (`test-time-ceiling.json`).

## 3. Conception : les cinq réponses par objet

### 3.1 Le socle a son plan (six tables)
1. **Le plus simple** : `schedule_plan_id` obligatoire ; les lignes du socle reçoivent le plan de saison ; **clé étrangère `ON DELETE CASCADE` vers le plan, gardée sur les trois tables qui l'ont et ajoutée aux trois qui ne l'ont pas**.
2. **Supprime** : toute forme de la branche « plan nul » ; la liste à la main de `OverlayManager.php:105-115`, remplacée par une lecture du catalogue des familles. **La purge par l'ORM passe d'abord** (elle vide le cache des demandes) ; la cascade en base reste le filet, et c'est elle qui sert `deletePeriodPlan` (`:418-426`).
3. **Un seul endroit** : la colonne existe. `team_link` et `venue_travel_rule_setting` ne la reçoivent pas (aucun lecteur).
4. **Suppose en dur** : un plan de saison par saison (`SchedulePlan.php:47`), créé par la migration s'il manque.
5. **Deuxième usage** : variante de semaine, planning d'essai.

**Trois gardes, parce qu'aucune ne suffit seule :**
- **Balayage du code** (`tests/Architecture/NoNullPlanBranchTest.php`), sur `backend/src` : interdit toute valeur `null`, même conditionnelle, affectée à une clé `schedulePlanId` ; tout `IS NULL` et `IS NOT NULL` sur `schedulePlanId`, `schedule_plan_id` ou sur l'ancre rendue par `StructureAnchor` ; tout paramètre, propriété ou retour `?string $schedulePlanId` ou `?string $planId`. Exceptions nommées dans le test : la classe de frontière `SoclePlan` et les entrées d'API des six familles (E2).
- **Type** : les accesseurs des six entités passent à `string` ; PHPStan niveau 8 refuse les comparaisons à `null` restantes.
- **Fil-piège à l'exécution**, en environnement de test seulement : toute requête SQL qui contient `schedule_plan_id IS NULL` ou `IS NOT NULL` lève. C'est la seule garde qui voit une variable mise à nul puis passée à une recherche. Elle vaut ce que les suites exercent.
La liste des sites à corriger en PR 2 est la sortie du balayage, pas un compte écrit ici.

### 3.2 Le pointeur unique de version chargée
`schedule_plan.lock_source_schedule_id` pour tous les plans ; `season.live_context_schedule_id` recopiée puis supprimée ; le `CASE` de `:289-293` disparaît. Nom de colonne inchangé.

### 3.3 La composition des équipes : `plan_team`
1. **Le plus simple** : une ligne par (plan, équipe), socle compris : participe, séances, plafond par jour, origine.
2. **Supprime** : `team_period_override`, les deux colonnes de `team`, le drapeau, `EffectiveTeamSessions`, le semis côté écran et son miroir.
3. **Un seul endroit** : la table d'exception, transformée.
4. **Suppose en dur** : une ligne par couple ; toute équipe a sa ligne dans chaque plan de sa saison.
5. **Deuxième usage** : la case « participe » du socle (lot 6).

- **Une seule porte pose les lignes** : `PlanComposition::ensureLines(seasonId)` crée, pour chaque plan de la saison, les lignes qui manquent, à la valeur par défaut de la nature, **sans jamais toucher une ligne existante**. Elle est appelée par chaque créateur nommé au §2 et par « Charger » après la réinsertion. Valeur par défaut d'une ligne de période : participe, séances et plafond du socle du moment.
- **Lire un plan où une ligne manque lève une erreur**, pour les trois familles (équipes, gymnases, contraintes) ; jamais un défaut silencieux.
- **« Une exception existe » devient « la ligne diffère de celle du socle »**, calculé à la lecture, à un seul endroit.
- **Pas de clé étrangère vers l'équipe** (ni vers le gymnase, ni vers la contrainte) : colonne nue déclarée `#[RefersTo(…, OnDelete::DELETE)]`, suppression par `CascadePlan`, comme aujourd'hui. Raison : « Charger » supprime puis réinsère ; une cascade viderait les réglages de chaque période.
- **« Charger »** : la photo gagne la famille `PlanTeam`, lignes du plan de saison seulement ; la restauration efface et réinsère celles-là, élague les lignes de période dont l'équipe disparaît, puis appelle `ensureLines`. La migration convertit les photos existantes ; une photo à l'ancien format est refusée.
- **Décision du §10 (un plan commencé garde ses valeurs de naissance)** : sur une ligne de période, séances et plafond sont des valeurs obligatoires, écrites à la naissance du plan et jamais relues sur le socle ; une ligne « diffère » quand ses valeurs ne sont pas celles du socle du moment ou que l'équipe ne participe pas ; les règles de bien-être des plans anciens sont matérialisées à la conversion.

### 3.4 Les gymnases d'une période : `plan_venue`
Une ligne par (plan de période, gymnase) : participe, « effet de fermeture levé », masque de jours. Pas de ligne pour le socle (aucun lecteur avant le lot 6). Supprime `venue_period_override`. Lignes posées par `ensureLines` (participe, sans masque).

### 3.5 Les règles appliquées par une période : `plan_constraint`
Une ligne par (plan de période, contrainte permanente) avec « s'applique ». Pas de ligne pour le socle, ni pour une contrainte datée. Supprime `constraint_period_override` et le défaut calculé à la lecture.
- Défaut écrit par `ensureLines`, lu sur la nature et la portée.
- **Changer la portée d'une contrainte réécrit ses lignes restées égales à l'ancien défaut** vers le nouveau ; une ligne que le gestionnaire a cochée ou décochée contre le défaut n'est pas touchée. C'est ce qui garde le comportement d'aujourd'hui, où le défaut se recalcule à chaque lecture.
- Dater une contrainte : refusé, même texte, si une ligne diffère du défaut ; sinon ses lignes sont supprimées.

### 3.6 Un constructeur, une copie de naissance, la nature
`buildForPlan(planId)` ; `PlanBirth::copy(source, cible)` lu sur le catalogue (naissance, plan à plan, « Reprendre la grille ») ; `SchedulePlanType` porte ses réponses.

### 3.7 Écarts à la cible
| # | Cible | Ce plan | Raison | Suite |
|---|---|---|---|---|
| E1 | « Les contraintes appartiennent au plan » | La contrainte reste à la saison ; le plan porte la liste de celles qu'il applique. **Écart au découpage validé, accepté par le fondateur en validant ce plan (2026-11-22).** | Son identifiant part au moteur | Lots 5 et 9 |
| E2 | Plus de « plan nul » | `schedulePlanId` reste `null` pour le socle à la frontière de l'API des six familles ; les trois routes d'exception restent. Une fonction, appelée depuis une trentaine de points d'entrée ; `ScheduleResource.schedulePlanId` non traduit | 39 fichiers d'écran, réécrits au lot 6 | Lot 6 |
| E3 | Passerelles et règle de trajet « au plan » | Inchangées | Aucun lecteur | Lots 5 et 9 |
| E4 | `Constraint.isActive` et « s'applique » fusionnés | Les deux restent | `isActive` part au moteur | Lot 9 |
| E5 | « Charger » unique | Adapté seulement | — | Lot 5 |
| E6 | Clés étrangères sur toute table créée | Vers le plan seulement | « Charger » efface et réinsère | Lot 5 |

## 4. Découpage : six PR

Ordre 1 → 2 → 3a → 3b → 4 → 5. Raison : chaque conversion prouvée seule, et la taille.

### PR 1 · « Le filet du lot » (aucun comportement ne change)
- Références captées sur le code d'avant : `socle-11-socle-complet.json` ; `periode-12-vacances-reglages.json` (équipe écartée, séances propres, plafond propre, règle d'équipe recochée, règle de club décochée, gymnase hors service, masque de jour, contrainte datée sur l'entrée mère, équipe créée après la naissance) ; `periode-13-fermeture-reglages.json` (groupes hérités, règle décochée, « grille ressaisie », contrainte et équipe créées après la naissance) ; `validation-14-deplacement-socle.json`, `validation-15-deplacement-periode.json`.
- **La chaîne de conversion, un seul test** (`tests/Integration/Migration/Lot4MigrationChainTest.php`) : une base dédiée, créée depuis un schéma figé d'avant le lot (`tests/Fixtures/lot-4/schema-avant.sql`, table des versions de migration comprise), reçoit le décor des quinze références écrit en SQL dans ce schéma (`decor-avant.sql`), joue toutes les migrations jusqu'à la tête, puis compose les quinze demandes avec le code de la tête et les compare aux fichiers de référence. **Choix contre la limite écrite** : la copie de production ne peut pas prouver ces conversions (aucun plan de période en cours, six plans passés dont on ne sait pas s'ils ont une demande enregistrable, mesure 10) ; seul ce décor porte les réglages de période. En PR 1 la chaîne joue zéro migration du lot ; **elle en joue une de plus à chaque PR, et c'est `tank` qui la tient verte**. Ajoutée au step `Blocking suite` et à `blocking-tests.md`. Son coût se mesure en PR 1 ; si la base dédiée ne peut pas être créée en CI ou coûte trop, repli écrit : la chaîne se joue sur la stack d'épreuve avant chaque merge, et le plan le dit comme limite.
- Première mesure des plafonds (`make ci-mesure PART=origin/lot/conception`).
- Tests : lignes 1 à 3. `/security-review` : non.

### PR 2 · « Le socle a son plan »
- Migration : plan de saison manquant créé ; plan de saison posé sur les lignes à plan nul ; NOT NULL ; clé en cascade ajoutée aux trois tables qui ne l'ont pas ; pointeur recopié puis colonne supprimée ; photos converties ; s'annule si un compte diffère ou si une unicité est violée. Règles de bien-être d'un plan ancien : matérialisées (décision du §10).
- Code : `SoclePlan` ; tous les sites que rend le balayage, dont `MoveSlotService.php:1057`, `ScheduleResultImporter.php:234`, `GenerationBlockersGuard.php:87`, `MutualizeSlotService.php:109`, `:334`, `StructureRestorer.php:170`, `:191`, `:294`, `VenueTrainingSlotStateProcessor.php:269`, `StructureAnchor`, `StructureSnapshotter.php:128-130` ; les quatre écrivains du pointeur, `ScheduleStateProvider.php:88`, `SecondSessionSameDayDetector.php:77` ; `SeasonTransitionService`, `ParcImportExecutor`/`Analyzer`, seeds ; niveaux `#[Family]` à `Level::PLAN` ; `OverlayManager.php:105-115` lu sur le catalogue ; le fil-piège.
- Tests : lignes 4 à 11. `/security-review` : oui.

### PR 3a · « Les équipes d'un plan »
- Migration : `plan_team` avec RLS ; socle = valeurs de l'équipe ; période = exception si elle existe, sinon valeur du socle du jour ; un plan de vacances jamais ouvert et **sans aucune version** reçoit la pré-sélection ; photos converties ; supprime `team_period_override`, les deux colonnes de `team`, le drapeau ; s'annule si `lignes ≠ équipes × plans`.
- Code : `PlanComposition`, les sept créateurs, `PeriodConstraintSelector.php:83-96`, `PeriodPlanTranscriber.php:258-275`, `SocleDeviationCalculator.php:283`, `MutualizeSlotService`, `CoachWishTransferService`, `TeamStateProcessor`, `TeamResource`, route d'exception, `CascadePlan.php:79`, photo et restauration, naissance d'un plan (pré-sélection posée par le serveur).
- Écran : `PeriodTeams.tsx` (retrait du semis), `periodSeedSelection.ts` (supprimé), `wizard/queries.ts`, `cockpit/api.ts`, `cockpit/queries.ts`. `periodSeedSelection.parity.*` et `PeriodSeedSelectionMirrorParityTest` supprimés par `tank`.
- **Documents dans cette PR** : amendement de l'ADR-0002 et corollaire de `CLAUDE.md` §6 (les réglages d'équipes d'une période sont figés à sa naissance).
- Tests : lignes 12 à 23. `/security-review` : oui.

### PR 3b · « Les gymnases d'une période »
- Migration : `plan_venue`, une ligne par (plan de période, gymnase) ; supprime `venue_period_override`.
- Code : `PlanVenueClosures`, `ClosurePinRemover.php:370`, `VenueClosureImpact.php:361`, `VenuePeriodGridActionController`, les cinq créateurs, route d'exception, `CascadePlan.php:108`, restauration.
- Tests : lignes 24 à 28. `/security-review` : oui.

### PR 4 · « Les règles appliquées, la nature sur le plan »
- Migration : `plan_constraint`, une ligne par (plan de période, contrainte permanente) = exception sinon défaut actuel ; supprime `constraint_period_override`.
- Code : réponses de nature sur `SchedulePlanType` ; `PeriodConstraintSelector` ; les créateurs de contraintes permanentes ; `ConstraintStateProcessor` (portée `:136`, datation `:175`) ; `Deletion/ScopedConstraintStep`, `VenueConfigConstraintStep` ; route d'exception ; restauration ; tri des douze lecteurs de `getPeriodType()`.
- Tests : lignes 29 à 34. `/security-review` : oui.

### PR 5 · « Un constructeur, une copie de naissance »
- `buildForPlan`, `selectForPlan`, `PlanBirth::copy` ; suppression des trois anciens constructeurs, des quatre routines de copie, de `PeriodPlanSiblingCopier::copyAnchoredFamilies`. Aucune migration. Reste des documents (§12).
- Tests : lignes 35 à 38. `/security-review` : à confirmer à l'ouverture.

## 5. Contrats nouveaux

```php
// PR 1
App\Tests\Integration\Migration\Lot4MigrationChainTest          // base dédiée ; #[Group('phase1')]

// PR 2
App\Plans\SoclePlan::idOf(string $seasonId): string                              // lève si absent
App\Plans\SoclePlan::fromApi(?string $requested, string $seasonId): string       // null -> plan de saison
App\Plans\SoclePlan::toApi(string $planId, string $seasonId): ?string            // plan de saison -> null
SchedulePlanProvisioner::lockSourceScheduleIdOfPlan(string $planId): ?string     // inchangée, un seul pointeur
SchedulePlanProvisioner::pointLockSource(string $planId, string $scheduleId): void   // seul écrivain, socle compris
FamilyCatalog::purgedWithPlan(): list<class-string>                              // enfants d'abord
App\Tests\Doctrine\NullPlanTripwireMiddleware                                    // when@test : lève sur « schedule_plan_id IS [NOT] NULL »
// schedule_plan : UNIQUE (id, club_id)  (pour les clés composées des tables neuves)
// schedule_plan_id NOT NULL sur les six tables ; FK (schedule_plan_id) -> schedule_plan (id) ON DELETE CASCADE,
//   gardée sur reservation, venue_training_slot, venue_occupancy ; ajoutée sur shared_training_block,
//   shared_training_block_team, implicit_rule_setting
// season.live_context_schedule_id supprimée ; ScheduleResource.isLiveContext, isLockSource, schedulePlanId inchangés

// PR 3a — plan_team : id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, team_id (colonne nue),
//   participates bool NOT NULL, sessions_per_week int NOT NULL, max_sessions_per_day smallint NOT NULL, source varchar(30) NULL
//   UNIQUE (schedule_plan_id, team_id) ; (schedule_plan_id, club_id) -> schedule_plan (id, club_id) ON DELETE CASCADE ; RLS
App\Entity\PlanTeam implements TenantOwnedInterface                               // #[Family(Level::PLAN)]
App\Plans\PlanComposition::ensureLines(string $seasonId): void                    // pose les lignes manquantes, ne touche aucune ligne existante
App\Plans\PlanComposition::teamsOf(string $planId): array<string, PlanTeam>       // clé = teamId ; lève si une équipe de la saison n'a pas de ligne
App\Plans\PlanComposition::teamLinesDifferingFromSocle(string $planId): array<string, PlanTeam>
SchedulePlanType::defaultTeamSelection(): App\Enum\PlanTeamDefault               // ALL | FLAGSHIP_AND_IMPORTANT
// /api/teams : mêmes champs sessionsPerWeek, maxSessionsPerDay, lus et écrits sur la ligne du socle
// /api/team_period_overrides : mêmes routes et champs ; GET ne rend que les lignes qui diffèrent du socle ;
//   POST sur un couple = mise à jour de sa ligne, même code de réponse ; DELETE = la ligne reprend les valeurs du socle
// GET /api/schedule_plans/{id} : teamSelectionInitialized disparaît

// PR 3b — plan_venue : mêmes colonnes de tête, venue_id (colonne nue), participates bool NOT NULL,
//   closure_lifted bool NOT NULL DEFAULT false, day_overrides json NULL ; UNIQUE (schedule_plan_id, venue_id) ; même clé composée
App\Entity\PlanVenue ; PlanComposition::venuesOf(string $planId): array<string, PlanVenue>     // lève si une ligne manque
// /api/venue_period_overrides : mêmes règles que la route des équipes

// PR 4 — plan_constraint : …, schedule_plan_id, constraint_id (colonne nue), applies bool NOT NULL ;
//   UNIQUE (schedule_plan_id, constraint_id) ; même clé composée
App\Entity\PlanConstraint ; PlanComposition::constraintsOf(string $planId): array<string, PlanConstraint>   // lève si une ligne manque
App\Plans\PlanComposition::onScopeChanged(Constraint $constraint, ConstraintScope $previous): void
SchedulePlanType::keepsSocleConstraintByDefault(ConstraintScope $scope): bool
SchedulePlanType::copiesSharedBlocksAtBirth(): bool
// /api/constraint_period_overrides : rendue si applies diffère du défaut de la nature ; POST = mise à jour

// PR 5
ScheduleConstraintBuilder::buildForPlan(string $planId, int $solverSeed = self::DEFAULT_SOLVER_SEED, ?string $versionId = null, ?PlanSelection $selection = null): array
PeriodConstraintSelector::selectForPlan(string $planId, ?array $teams = null): PlanSelection
//   PlanSelection = PeriodConstraintSelection renommée ; sessionOverrides et maxPerDayOverrides = les lignes qui diffèrent du socle
App\Plans\PlanBirth::copy(string $sourcePlanId, string $targetPlanId, ?string $venueId = null): void
#[Family(Level::PLAN, copiedAtPlanBirth: true)] ; FamilyCatalog::copiedAtPlanBirth(): list<class-string>
App\Tests\CreatesPlanCompositionTrait::newTeamInSocle(string $clubId, string $seasonId, string $name, int $sessionsPerWeek = 2): Team
```
`engine/CONTRACT_VERSION` : inchangé (1.12).

## 6. Conversion, irréversibilité, mise en production

- **Mesures à refaire par `link`** (lecture seule, démonstrations exclues, comptes et identifiants ; le fondateur débloque la clé SSH ; aucun accès tenté). « 10 plans, 274 + 129 + 26 » date du 2026-11-20.
  1. Saisons par club (attendu 1) ; saisons sans plan de saison (0).
  2. Plans par nature (4 + 6) ; plans dont la nature diffère de celle de leur entrée (0) ; plans de période en cours ou à venir (0 ; **sinon retour au fondateur**).
  3. Exceptions d'équipes : total (274) ; « ne participe pas », séances propres, plafond propre, origine ; orphelines ; égales à la valeur de saison.
  4. Exceptions de contraintes (129) : à vrai, à faux, orphelines, égales au défaut. Exceptions de gymnases (26) : par mode, avec masque, orphelines.
  5. Lignes à plan nul par table ; lignes portant déjà l'identifiant du plan de saison (0 ; sinon retour).
  6. Plans de vacances dont la pré-sélection n'a jamais été faite : avec au moins une version, sans aucune version ; plans de période sans ligne de règle de bien-être.
  7. `season.live_context_schedule_id` : renseignés ; incohérents ; `schedule_plan.lock_source_schedule_id` déjà renseigné sur un plan de saison (0).
  8. Équipes (94), gymnases, contraintes permanentes par saison.
  9. Les 2 photos : clés `schedulePlanId`, `sessionsPerWeek`, `maxSessionsPerDay`.
  10. Par plan de période : a-t-il une demande enregistrable ?
  11. Versions en attente ou en cours, placements en cours (0).
- **Migrations irréversibles** (retour = restauration de la sauvegarde, site fermé, décision 6 du lot 3) : suppression des trois tables d'exception, du drapeau, des deux colonnes de `team`, de `season.live_context_schedule_id` ; réécriture des photos. Aucun `down()`.
- **Dérogation** à « compatible une release en arrière » : même régime que le lot 3.
- **Preuve** : (1) en CI, les quinze références à l'octet, aucune PR 2 à 5 n'en modifie une ; (2) la chaîne de conversion sur base dédiée (§4, PR 1) ; (3) copie de production n° 2 avant la PR finale, toutes les migrations du lot conception ensemble, `diff -r` vide.

## 7. Tableau comportement → scénario (brief de `tank`)

| # | PR | Comportement | Preuve, rouge avant `neo` |
|---|---|---|---|
| 1 | 1 | Cinq demandes de plus figées à l'octet | `EngineRequestReferenceTest`, cas 11 à 15 (bloquant) |
| 2 | 1 | Une règle de saison créée après la naissance d'une fermeture entre dans sa demande ; une équipe créée après participe, fermeture comme vacances | Cas 12 et 13 ; `plan-de-periode-en-overlay.feature`, scénario neuf : « Étant donné une fermeture déjà créée » / « Quand j'ajoute la règle de saison « pas le vendredi » » / « Alors la génération de la fermeture respecte « pas le vendredi » » |
| 3 | 1, puis chaque PR | Le décor écrit dans le schéma d'avant, une fois toutes les migrations jouées, donne les quinze demandes de référence | `Lot4MigrationChainTest` (bloquant), tenu par `tank` à chaque PR |
| 4 | 2 | Demande identique | Les quinze références ; ligne 3 |
| 5 | 2 | La conversion crée le plan manquant, pose le plan de saison, déplace le pointeur, s'annule si une ligne portait déjà le plan de saison | `Lot4MigrationChainTest`, comptes après migration + un cas « décor faussé » |
| 6 | 2 | « Charger » une version du socle : (a) ramène grille et réservations sur le plan de saison ; (b) une photo convertie se charge ; (c) ne change aucun réglage d'un plan de période ; (d) une photo à l'ancien format est refusée | `tests/Integration/Service/StructureRestorerTest.php`, quatre cas ; `voeux-des-coachs.feature` reste vert |
| 7 | 2 | Aucune forme de « plan nul » ne reste : ni valeur nulle sur la clé, même conditionnelle, ni `IS NULL` / `IS NOT NULL` sur l'ancre, ni paramètre nullable hors frontière ; une requête qui cherche un plan nul lève en test | `tests/Architecture/NoNullPlanBranchTest.php` ; `tests/Integration/Doctrine/NullPlanTripwireTest.php` (le fil-piège se déclenche sur une requête fautive écrite exprès) |
| 8 | 2 | Une période ne lit jamais la grille, les réservations ni les groupes du socle, et inversement ; un créneau du socle accepte un libellé de groupe, un créneau de période le refuse ; un verrou importé garde son origine, socle comme période | `le-socle-commande-les-plans.feature`, étape : « Et modifier la grille du planning de saison ne change pas la grille des vacances » ; `tests/Api/VenueTrainingSlotGroupLabelTest.php`, deux cas ; `un-verrou-est-souverain.feature` et `un-verrou-de-periode-survit-a-la-regeneration.feature` restent verts |
| 9 | 2 | Frontière de l'API : `schedulePlanId: null` pour le socle en sortie, accepté en entrée, sur les six familles ; la version d'un planning de saison rend le vrai identifiant de son plan | `tests/Api/SoclePlanApiFrontierTest.php`, trois cas |
| 10 | 2 | L'étoile du socle suit « Charger » et chaque génération terminée ; une seule version se modifie (409 `not_loaded_version`) | `la-boucle-de-travail-du-gestionnaire.feature` reste vert ; `tests/Integration/Service/LoadedVersionPointerTest.php`, trois cas |
| 11 | 2 | Supprimer une période emporte tout ce que son plan porte et purge le cache des demandes ; « Préparer la saison suivante » range la grille reprise dans le plan de la nouvelle saison | `supprimer-emporte-ce-qui-en-depend.feature`, étape ; `PurgeCompletenessTest` (bloquant) ; `preparer-la-saison-suivante-reprend-la-structure.feature`, étape : « Et la grille reprise est celle du planning de saison 2027-2028 » |
| 12 | 3a | Demande identique | Références ; ligne 3 |
| 13 | 3a | La conversion crée une ligne par (plan, équipe), supprime table, colonnes et drapeau, convertit les photos, s'annule si le compte diffère | `Lot4MigrationChainTest`, comptes + un cas « décor faussé » |
| 14 | 3a | Écarter une équipe d'un plan de vacances, lui donner 1 séance, puis lui rendre ses réglages de saison | `plan-de-periode-en-overlay.feature` : « Quand j'écarte les U13 des vacances » / « Alors les U13 n'ont aucune séance dans le planning des vacances » / « Quand je rends aux U13 leurs réglages de saison » / « Alors les U13 ont leurs 2 séances ». Exemples : équipe réservée, libre, d'un groupe mutualisé |
| 15 | 3a | « Charger » : le socle retrouve ses séances par équipe ; la ligne du socle réinsérée n'est pas doublée ; une équipe ramenée reçoit sa ligne dans chaque plan de période ; une équipe retirée perd les siennes | `StructureRestorerTest`, quatre cas |
| 16 | 3a | **Décision du §10** : un plan de vacances garde ses séances et son plafond quand ceux du socle changent (API, import du parc, « Charger »), et ces équipes y apparaissent alors comme réglage propre | `plan-de-periode-en-overlay.feature`, scénario neuf : « Étant donné un plan de vacances où les SM1 ont 2 séances » / « Quand les SM1 passent à 3 séances dans le planning de saison » / « Alors les SM1 ont toujours 2 séances dans le plan de vacances » / « Et les SM1 y figurent parmi les équipes au réglage propre » |
| 17 | 3a | La transcription ne retire une séance qu'à une équipe dont la ligne de période a moins de séances que le socle ; `chosenSessions` nul quand la ligne égale le socle | `tests/Integration/Service/PeriodPlanTranscriberTest.php`, deux cas ; `SocleDeviationCalculatorTest`, un cas |
| 18 | 3a | Le plafond par jour reste refusé socle validé, même message ; les séances ne sont pas refusées par le serveur (comportement actuel) | `le-socle-commande-les-plans.feature` reste vert ; `tests/Api/TeamSessionsUnderValidatedSocleTest.php`, deux cas |
| 19 | 3a | Un plan de vacances naît avec « Fanion et importantes » cochées, à défaut le meilleur rang présent ; une fermeture avec tout ; « tout cocher » ensuite n'est jamais défait ; l'écran n'écrit rien à l'ouverture | `tests/Integration/Plans/PlanBirthTeamDefaultTest.php` ; `PeriodTeams.test.tsx` ; `voeux-des-coachs.feature` reste vert |
| 20 | 3a | Plan de vacances existant jamais ouvert : avec une version, il garde toutes ses équipes ; sans aucune version, il reçoit la pré-sélection | `Lot4MigrationChainTest`, deux plans de plus dans le décor |
| 21 | 3a | Chaque créateur d'équipe (sept) pose les lignes manquantes ; rappeler la porte ne change rien ; lire un plan où une ligne manque échoue haut et fort | `tests/Integration/Plans/PlanCompositionTest.php` |
| 22 | 3a | La fenêtre de suppression d'une équipe n'annonce que les réglages de période qui diffèrent du socle ; plan d'un club, équipe d'un autre (abus) | `DeletionImpactParityTest` (bloquant), un cas ; `tests/Security/PlanCompositionTenantIsolationTest.php`, ajouté au step `Blocking suite` et à `blocking-tests.md` |
| 23 | 3a | Publier deux fois le même réglage d'équipe pour un plan met à jour, sans erreur | `tests/Api/PeriodSettingUpsertTest.php`, cas « équipe » |
| 24 | 3b | Demande identique ; conversion des gymnases (comptes, cas faussé) | Références ; `Lot4MigrationChainTest` |
| 25 | 3b | Gymnase hors service pour une période puis remis ; jour rouvert par le masque ; « grille ressaisie » lève l'effet d'une fermeture | `une-fermeture-adapte-le-planning.feature`, `une-fermeture-se-prolonge-a-travers-les-vacances.feature` restent verts ; une étape : « Quand je remets le Gymnase Nord en service pour les vacances » |
| 26 | 3b | Lignes de gymnase : les cinq créateurs les posent ; « Charger » ne change aucun réglage existant, pose celles d'un gymnase ramené, élague celles d'un gymnase retiré ; une ligne manquante échoue haut et fort | `PlanCompositionTest`, `StructureRestorerTest`, trois cas |
| 27 | 3b | Fenêtre de suppression d'un gymnase : seuls les réglages qui diffèrent ; plan d'un club, gymnase d'un autre (abus) | `DeletionImpactParityTest` ; `PlanCompositionTenantIsolationTest`, un cas |
| 28 | 3b | Publier deux fois le même réglage de gymnase met à jour | `PeriodSettingUpsertTest`, cas « gymnase » |
| 29 | 4 | Demande identique ; conversion des règles (comptes, cas faussé) | Références ; `Lot4MigrationChainTest` |
| 30 | 4 | Décocher puis recocher une règle de saison dans une fermeture ; dans des vacances une règle d'équipe est décochée par défaut, une règle de club cochée | `plan-de-periode-en-overlay.feature`, plan de scénario à trois exemples ; `une-contrainte-saisie-est-honoree.feature` reste vert |
| 31 | 4 | Dater une contrainte : permis quand aucune période ne s'écarte du défaut, ses lignes disparaissent ; refusé, même texte, sinon | `tests/Api/ConstraintDatingTest.php`, deux cas |
| 32 | 4 | Élargir une règle d'équipe à tout le club la fait entrer dans un plan de vacances existant, sauf si le gestionnaire l'y avait décochée ; restreindre fait l'inverse | `tests/Api/ConstraintScopeChangeTest.php`, trois cas |
| 33 | 4 | Lignes de règle : chaque créateur de contrainte permanente les pose ; « Charger » ne change aucune coche existante, pose celles d'une règle ramenée, élague celles d'une règle retirée ; une ligne manquante échoue haut et fort ; plan d'un club, contrainte d'un autre (abus) | `PlanCompositionTest`, `StructureRestorerTest`, trois cas ; `PlanCompositionTenantIsolationTest`, un cas |
| 34 | 4 | Publier deux fois le même réglage de règle met à jour | `PeriodSettingUpsertTest`, cas « règle » |
| 35 | 5 | Demande identique par le constructeur unique, `/validate-assignments` compris | Les quinze références ; ligne 3 |
| 36 | 5 | Une fermeture naît avec grille, occupations, groupes mutualisés, règles ; des vacances sans groupes ; jamais les réservations | `tests/Integration/Plans/PlanBirthTest.php` |
| 37 | 5 | Une famille de niveau plan qui ne dit pas si elle se copie à la naissance fait échouer le balayage | `FamilyDeclarationSweepTest`, un cas |
| 38 | 5 | Copier un plan de période vers un autre donne les mêmes réglages | `tests/Integration/Service/PeriodPlanSiblingCopierTest.php`, un cas |

Restent verts sans modification : les trois `*ContractSchemaTest`, `un-planning-de-periode-commence-se-valide-seul.feature`, `l-unite-de-placement-est-le-bloc.feature`, `une-equipe-s-entraine-deux-fois-le-meme-jour.feature`, `l-import-du-parc-en-un-geste.feature`, `placement-des-matchs.feature`, `un-membre-lit-le-gestionnaire-ecrit.feature`. Déjà couverts : refus d'écrire d'un Membre et saison passée sur les routes d'exception (`MemberWriteSweepTest`, `SeasonReadonlyTest`, cités par `smith`) ; RLS de toute table neuve (`tests/Security/RlsIsolationTest.php:103`).
**Preuve = captures (`switch`)** : étape Équipes d'un plan de vacances neuf, avant et après la PR 3a ; fenêtres de suppression d'une équipe et d'un gymnase qui ont un réglage de période ; l'étape Équipes d'un plan de vacances après un changement de séances dans la saison.

## 8. Carte des conséquences

| Comportement | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Données du socle (six tables) | Processeurs, `ParcImportExecutor.php:1097-1145`, `SeasonTransitionService`, `StructureRestorer` | Constructeur `:273-305`, gardes de génération, providers, `StructureSnapshotter.php:128-130` | Plan nul | Plan de saison ; balayage, type, fil-piège |
| Origine d'un verrou importé | `ScheduleResultImporter.php:234` | Constructeur (verrous de la version) | Critère « plan nul si socle » | Critère par plan ; hors références, prouvé par les deux features de verrou |
| Libellé de groupe d'un créneau | `VenueTrainingSlotStateProcessor.php:269` | Écran | « Plan non nul = période » | Nature du plan |
| Version chargée du socle | `RegenerateFromVersionController`, `GenerateScheduleHandler`, `ValidateScheduleController` | `lockSourceScheduleIdOfPlan` `:282`, `ScheduleStateProvider.php:88`, `SecondSessionSameDayDetector.php:77`, `VersionEditability`, `OrphanPinGuard` | Colonne de la saison | Colonne du plan, même repli |
| Suppression d'un plan de période | `OverlayManager.php:105-115`, puis `deletePeriodPlan` `:418-426` en SQL brut | `PurgeCompletenessTest` | Liste à la main + six cascades | Catalogue par l'ORM d'abord (cache purgé), cascade sur toutes les tables du plan en filet |
| Séances et plafond d'une équipe | `TeamStateProcessor.php:168-177`, `ParcImportExecutor.php:309-310`, transfert de doléances | Constructeur `:1423`, `:1432`, 19 fichiers | Colonnes de l'équipe + exception | Ligne du plan ; ligne manquante = erreur |
| « Charger » une version du socle | `StructureRestorer.php:259-275` | Le gestionnaire | Efface et réinsère ; les exceptions survivent faute de clé | Idem ; lignes du socle dans la photo ; lignes de période gardées, élaguées, ou posées par `ensureLines` pour équipes, gymnases et règles ramenés |
| « Une exception de séances existe » | Route d'exception | `PeriodPlanTranscriber.php:258-275`, `SocleDeviationCalculator.php:283`, `drift.ts:34-47` | Présence d'une ligne | La ligne diffère du socle ; **une équipe dont le socle a changé depuis « diffère » sans geste du gestionnaire (décision du §10)** |
| Fenêtre de suppression | `CascadePlan.php:79`, `:108` | `delete-confirm`, `DeletionImpactParityTest` | Compte toutes les exceptions | Compte les lignes qui diffèrent ; supprime toutes les lignes |
| Règle appliquée à une période | Route d'exception ; création ; **changement de portée** `ConstraintStateProcessor.php:136` | `PeriodConstraintSelector.php:79-81`, `:124-128` | Exception, sinon calcul à chaque lecture | Ligne toujours présente ; réécrite au changement de portée si restée au défaut |
| Dater une contrainte | `ConstraintStateProcessor.php:175-176` | Écran | Refus si une exception existe | Refus si une ligne diffère du défaut ; sinon lignes supprimées |
| Pré-sélection d'équipes | Écran `PeriodTeams.tsx:192`, `PlanTeamSelectionSeeder` | Drapeau | À l'ouverture de l'étape | À la naissance du plan |
| Règles de bien-être d'un plan ancien | — | `ImplicitRuleResolver.php:111-117` | Suit la saison | Figées à la conversion (décision du §10) |
| Empreinte « structure modifiée » | `structureHashOfPlan` `:1036` | Bandeau « périmé » | Deux recettes | Une ; valeur identique |
| Demande de placement des matchs | `MatchPlacementPayloadBuilder` | Moteur | Ne lit pas les exceptions (`:256-259`) | Inchangée |

## 9. Parcours de bout en bout

- **Écarter une équipe d'un plan de vacances.** Étape Équipes : décocher U13 ; générer : U13 sans séance. Inverse : recocher. Variantes : équipe avec créneau réservé dans la période (comportement actuel, non vérifié) ; équipe d'un groupe mutualisé (groupe sous deux membres abandonné, `ScheduleConstraintBuilder.php:799-804`).
- **Séances propres à la période.** SM1 à 1 séance. Inverse : rendre les réglages de saison.
- **Changer les séances dans la saison.** Socle validé : l'écran grise le champ (`TeamsStep.tsx:197-203`) ; rouvrir, modifier, revalider. Effet sur un plan de vacances commencé : il garde ses séances (décision du §10).
- **Élargir une règle.** Modifier une règle d'équipe en règle de tout le club : elle entre dans les plans de vacances existants où personne ne l'avait décochée, comme aujourd'hui. Inverse : la restreindre l'en sort.
- **Charger une version du planning de saison.** Le socle retrouve grille, réservations, séances ; les plans de période gardent leurs réglages. Inverse : charger l'autre version.
- **Gymnase hors service pendant une fermeture**, puis remis ; jour rouvert. Inchangé.
- **Supprimer une équipe qui a un réglage de période.** La fenêtre annonce « 1 réglage de période » ; annuler : rien ne part.
- **Saison passée** : lecture seule.

**Textes visibles qui changent** : aucun libellé. **Ce qui peut se voir** : (1) une équipe dont les séances ont changé dans la saison apparaît dans un plan de vacances commencé avec un réglage propre, et compte dans « N réglage(s) de période » de la fenêtre de suppression ; (2) un réglage saisi à la main mais égal à la valeur de saison ne compte plus comme réglage, ni dans cette fenêtre, ni dans l'écart au socle.

## 10. Décision du fondateur (2026-11-22) et information

**Décision (Q1 = A) : un plan de vacances déjà commencé garde ce qu'il avait à sa création.** Séances, plafond par jour et
règles de bien-être de ses plus anciens plans : tant que le gestionnaire ne les change pas dans ce plan, ils ne suivent
plus le planning de saison. À l'écran, les équipes dont le socle a changé depuis (exemple : les SM1) apparaissent dans le
plan avec un réglage propre. C'est le seul changement visible du lot hors information.

Exemple : les vacances de Noël ont commencé ; leur plan donne 2 séances aux SM1, faute de réglage particulier pour Noël.
Le gestionnaire rouvre le planning de saison, passe les SM1 à 3 séances, revalide, puis rouvre le plan de Noël et le
régénère. Avant le lot, il prenait 3 séances ; après, il garde 2 séances et les SM1 y figurent avec un réglage propre.
(Les plans de vacances pas encore commencés sont déjà détruits quand le planning de saison est rouvert.) Une règle ajoutée
au planning de saison (« pas le vendredi ») continue d'entrer dans les plans de vacances existants.

Raison : la règle s'applique déjà à la grille de gymnases d'un plan de vacances, qui ne bouge plus après sa création ; elle
retire de chaque plan la notion « pas réglé, donc comme la saison », que le lot devait supprimer. Aucun plan de vacances
n'est en cours aujourd'hui chez les clubs en production (à revérifier par la mesure 2, §6).

**Information (pas un choix) — La pré-sélection des équipes d'un plan de vacances se fait désormais à sa création.**
Aujourd'hui l'équipe fanion et les équipes importantes (à défaut, celles du meilleur rang présent) sont pré-cochées quand
le gestionnaire ouvre l'étape Équipes. Elles le seront dès la création du plan : mêmes coches. Un plan de vacances déjà
créé, jamais ouvert, reçoit la pré-sélection à la mise en production s'il n'a encore aucun planning généré ; s'il en a un,
il garde toutes ses équipes, pour ne pas changer un planning déjà fait. Une équipe ajoutée au club après la création d'un
plan de vacances y est cochée, comme aujourd'hui. Le découpage validé retire le mécanisme actuel.

## 11. Limites écrites

1. **Écart au découpage validé** : une contrainte reste une seule ligne, à la saison (E1).
2. L'API garde `schedulePlanId: null` pour le socle et les trois routes d'exception, jusqu'au lot 6 (E2).
3. Le constructeur unique garde une différence de fond : d'où viennent les verrous, selon la nature.
4. Pas de clé étrangère vers l'équipe, le gymnase ni la contrainte avant le lot 5 (E6).
5. Séances, plafond, grille, règles de bien-être sont figés à la naissance d'un plan ; les contraintes suivent la saison.
6. Le fil-piège ne voit que les chemins que les suites exercent ; le balayage ne voit que les formes qu'il nomme.
7. Si la base dédiée de la chaîne de conversion ne peut pas tourner en CI, elle se joue sur la stack d'épreuve avant chaque merge.
8. Un plan de période sans demande enregistrable n'est pas vu par la copie de production (mesure 10).
9. `/validate-assignments` n'est prouvé qu'en CI (références 14 et 15). La fenêtre de placement libre est sans risque dans ce lot.
10. Les photos de versions sont converties, pas supprimées (lot 5).

## 12. Checklist de cadrage

- **Zones** : `backend/`, `frontend/` (PR 3a seulement : `features/wizard/steps/PeriodTeams.tsx`, `periodSeedSelection.ts`, `wizard/queries.ts`, `cockpit/api.ts`, `cockpit/queries.ts`, leurs tests), `docs/`, `specs/`, `.github/workflows/ci.yml` (step bloquant). `engine/`, `landing/`, `system-pages/` : aucun fichier.
- **Dossiers interdits** : `engine/**`, `engine/CONTRACT_VERSION`, les trois `*ContractSchemaTest`, les références 1 à 10, `scripts/remote-deploy.sh`, `.github/workflows/deploy.yml`, `docs/ops/deploy.md`.
- **Aucun refactoring hors périmètre** : confirmé. Non touchés : « Charger » au-delà de son adaptation, les écrans de période hors le semis, `Constraint.isActive`, le nom de la colonne du pointeur, passerelles et règle de trajet, contraintes datées, les six clés existantes vers le plan.
- **Axes §7.1 et NR** : *chaîne de génération* (1, 3, 4, 12, 24, 29, 35) ; *sens des contraintes* (2, 30, 32) ; *cycle des plans* (6, 8, 10, 15, 16, 18, 26, 33, 36) ; *isolation* (11, 22, 27, 33) ; *contrat avec le moteur*, *périmètre engagé*, *auth et adhésions* : non touchés. Bloquants ajoutés à `ci.yml` et `blocking-tests.md` : `Lot4MigrationChainTest` (PR 1), `PlanCompositionTenantIsolationTest` (PR 3a).
- **`/security-review`** : PR 2, 3a, 3b, 4.
- **Contre-revue** : `smith`, deux passes.
- **Tests d'abus** : lignes 22, 27, 33.
- **Behat touchés** : `plan-de-periode-en-overlay.feature`, `le-socle-commande-les-plans.feature`, `une-fermeture-adapte-le-planning.feature`, `preparer-la-saison-suivante-reprend-la-structure.feature`, `supprimer-emporte-ce-qui-en-depend.feature` ; contextes qui écrivent ces tables en SQL brut : à relever par `tank` (non vérifié). **Playwright** : `closure-extend.spec.ts`, `redate-closure.spec.ts`, `mutualize.spec.ts`, `club-life.spec.ts`, à confirmer à la PR 3a.
- **Feature qui prouve la zone** : `generation-du-planning-de-saison.feature`, `plan-de-periode-en-overlay.feature`.
- **Plafonds de temps** : estimations non mesurées : backend +50 s (la chaîne sur base dédiée avec ses cas faussés, cinq références, tests d'intégration), Behat +40 s, front −1 s. Le lot 3 ajoute déjà +50 s à Behat sur 950 s : mesure dès la PR 1, puis à chaque PR. Si backend déborde : les cas faussés de la chaîne passent à un seul, en PR 3a. Si Behat déborde : la ligne 2 ne garde que les références 12 et 13, la ligne 14 un exemple.
- **Contrôle visuel** : voir « captures » au §7.
- **Documentation** : PR 3a : amendement de l'ADR-0002, `CLAUDE.md` §6, `frontend/docs/frontend-wizard.md`. PR 5 : fiche du lot 4 et §6 de `lot-conception-decoupage.md` (avec E1), `etat-des-lieux.md`, ADR-0005, `adr-index.md`, `backend/AGENTS.md`, `backend/docs/backend-inventory.md`, `backend/docs/TENANT.md`, `docs/security/rls.md`, `docs/glossary.md`, `test-coverage-map.md`. Au fil des PR : `lot-conception-filet.md`, `blocking-tests.md`, `openapi-snapshot.json`.
- **Retour au fondateur si** : une mesure hors attendu ; une référence qui devrait changer ; `diff -r` non vide ; un plan de période en cours ou à venir au moment de convertir.

## 13. Exécution

**Pas d'exécution** tant que le lot 3 n'est pas entièrement construit et que les mesures de `link` (§6) ne sont pas faites.
Le plan porte une décision (§10), une information (§10) et un écart au découpage validé (E1, §3.7), validés par le
fondateur le 2026-11-22.

**Laissé hors périmètre, volontairement** : la case « participe » du socle, la fusion des écrans de période, « Charger » unique et la suppression des photos, la recopie des contraintes par plan, les clés étrangères vers équipe, gymnase et contrainte, le rattachement des passerelles et de la règle de trajet, le renommage du pointeur.

## Changements depuis la version précédente

| Correction reçue | Section modifiée |
|---|---|
| N1 · le balayage ne voyait que trois écritures | §2 (formes vérifiées, signatures nullables, compte déclaré non fiable), §3.1 (trois gardes : balayage élargi, type, fil-piège), §4 PR 2 (sites nommés, « la sortie du balayage fait foi »), §5, §7 lignes 7 et 8, §8 (origine d'un verrou importé), limite 6 |
| N2 · six clés en cascade vers le plan | §2 (compte corrigé, `deletePeriodPlan`), §3.1 (cascade gardée et étendue, purge ORM d'abord), §5, §7 ligne 11, §8 (ligne « suppression d'un plan de période ») |
| N3 · le test de migration ne pouvait pas tourner | §2 (filet), §4 PR 1 (une chaîne sur base dédiée, choix justifié, repli), §5, §6 preuve, §7 lignes 3, 5, 13, 20, 24, 29 (les tests de conversion par PR deviennent des cas de la chaîne, tenue par `tank`), limite 7, §12 (bloquant, plafonds) |
| N4 · lignes manquantes | §2 (« qui crée quoi » : sept, cinq, sept sites), §3.3 (`ensureLines` qui ne touche aucune ligne existante ; erreur pour les trois familles), §3.4, §3.5, §5, §7 lignes 15, 21, 26, 33, §8 |
| N5 · changement de portée ; effet visible de l'option A | §2, §3.5 (réécriture des lignes restées au défaut), §5 (`onScopeChanged`), §7 lignes 16 et 32, §8, §9 (parcours « élargir une règle », « ce qui peut se voir ») |
| N6 · option B de la question 2 | Tranché : B retirée, la question devient une information (§10) ; §4 PR 3a et §7 ligne 20 (un plan jamais ouvert et sans version reçoit la pré-sélection ; avec version il garde tout) ; mesure 6 |
| m1 · amendement de l'ADR-0002 en PR 3a | §4 PR 3a, §12 documentation |
| m2 · contrats et test de mise à jour | §5 (`defaultTeamSelection`, `PlanTeamDefault` en PR 3a) ; §7 lignes 23, 28, 34 |
| m3 · compte de fichiers | §2, §3.1 |
| Question 1 réécrite | §1.4, §10 (rouvrir et régénérer le plan de Noël ; réglage propre visible ; seul changement visible ; « vos plus anciens plans de vacances ») |

**Décisions de la v2 retirées** : les clés vers le plan « sans cascade » (N2 : six cascades existent et `deletePeriodPlan` en dépend) ; les tests de conversion séparés par PR (N3 : infaisables sans `down()`) ; l'option B de la question 2 (N6 : contraire au découpage validé, jamais conçue) ; `PlanComposition::addTeam` et `addVenue` (remplacés par `ensureLines`, qui ne double pas une ligne existante) ; la règle « un plan existant jamais ouvert garde toujours toutes ses équipes » (affinée : seulement s'il a déjà une version).

**Points repris de `smith` sans relecture ligne à ligne** : le mécanisme `down()` des tests de migration existants ; `ConstraintStateProcessor.php:169` (une datée ne redevient pas permanente) ; `MemberWriteSweepTest`, `SeasonReadonlyTest`. **Non vérifié** : que la stack d'épreuve tourne dans un environnement où le fil-piège est actif pour Behat ; que le formulaire de contrainte laisse changer la portée.

## Fichiers à relire en premier

- `specs/evolution/lot-conception-decoupage.md`
- `specs/evolution/conception-cible.md`
- `specs/evolution/lot-conception-3-personnes-et-lieux.md`
- `specs/evolution/lot-conception-filet.md`
- `backend/src/Service/StructureRestorer.php`
- `backend/src/Service/StructureAnchor.php`
- `backend/src/Service/StructureSnapshotter.php`
- `backend/src/Service/ScheduleConstraintBuilder.php`
- `backend/src/Service/PeriodConstraintSelector.php`
- `backend/src/Service/SchedulePlanProvisioner.php`
- `backend/src/Service/ScheduleResultImporter.php`
- `backend/src/Service/MoveSlotService.php`
- `backend/src/Service/MutualizeSlotService.php`
- `backend/src/Service/GenerationBlockersGuard.php`
- `backend/src/Service/PeriodPlanTranscriber.php`
- `backend/src/Service/SocleDeviationCalculator.php`
- `backend/src/Service/OverlayManager.php`
- `backend/src/Service/ImplicitRuleResolver.php`
- `backend/src/Service/DefaultConstraintSeeder.php`
- `backend/src/Deletion/CascadePlan.php`
- `backend/src/State/Processor/TeamStateProcessor.php`
- `backend/src/State/Processor/ConstraintStateProcessor.php`
- `backend/src/State/Processor/VenueTrainingSlotStateProcessor.php`
- `backend/src/Controller/ReopenScheduleController.php`
- `backend/src/EventListener/CacheInvalidationListener.php`
- `backend/migrations/Version20260727120000.php`
- `backend/migrations/Version20260807120000.php`
- `backend/migrations/Version20261117130000.php`
- `backend/tests/EngineRequestDecorTrait.php`
- `backend/tests/Integration/EngineRequestReferenceTest.php`
- `backend/tests/Integration/Migration/VenueUnavailabilityDropMigrationTest.php`
- `backend/features/un-planning-de-periode-commence-se-valide-seul.feature`
- `frontend/src/features/wizard/steps/PeriodTeams.tsx`
- `frontend/src/features/wizard/steps/ConstraintsStep.tsx`
- `frontend/src/features/planning/lib/drift.ts`
- `test-time-ceiling.json`