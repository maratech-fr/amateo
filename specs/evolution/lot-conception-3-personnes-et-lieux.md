# Lot 3 · Personnes et lieux au club : plan d'exécution

**Statut : version finale (v4 sur 4) ; plafond atteint. Plan validé par le fondateur le 2026-11-21 avec ses sept
décisions (§11). PR 1, 2a, 2b, 3 et 4 livrées ; la PR 5 reste à faire ; elle s'exécute dans l'ordre du §4, rouges de `tank` d'abord.**
Voie **Full**. Branche cible : `lot/conception` (aucune branche
`lot/personnes-lieux`) ; une PR vers elle ne joue que « Lot Gate » (`docs/ops/ci-runner.md` § Branche de lot) : ni
Behat, ni e2e, ni `backend-tests`. Avant chaque merge : `make -C backend tests-complete` et les features/specs de la
zone sur la stack d'épreuve. Exécutant : `neo`, après les rouges de `tank`.
La fiche du lot est dans [`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 3 ; ce fichier dit
**comment**.

**Cadrage** : la conception validée tient lieu de cadrage (cible §3, §9, §13, §15, §27 de
[`conception-cible.md`](conception-cible.md)) : pas de section « Signaux » d'`oracle`, les chemins d'abus sont au §9.
Trois passes de `smith` ; la troisième ne trouve rien de bloquant. Ce qui restait ouvert est devenu les décisions du
§11 ou les limites du §13.

Les `fichier:ligne` ont été relus le 2026-11-21 sur `lot/conception` (tête `d0c0d61e7`) ; depuis, le lot 1 a mergé ses
PR 1 et 2 (`ScheduleConstraintBuilder`, `scripts/remote-deploy.sh`) et réécrira encore `CascadePlan` et les purgeurs.
**Tous les `fichier:ligne` de ce plan sont à relire à l'ouverture de la PR concernée**, et les comptes (fichiers,
endroits, lignes de test) sont des constats datés du 2026-11-21 : le balayage et `grep` font foi, pas le chiffre.

## 0. Ce qui est livré et ce qui ne l'est pas

- **Lot 0 livré** : `EngineRequestComposer`, six références, `app:engine-requests:record`.
- **Lot 1** : ses PR 1 (purge du cache au déploiement, références 7 et 8) et 2 (créneau de semaine, jour de 1 à 7)
  sont mergées. Le reste n'est pas livré : `backend/src/Structure/` n'existe pas, les tables d'étiquettes sont en
  place. Ce plan suppose le lot 1 livré tel que son plan l'écrit (`FamilyCatalog`, `#[Family]`, `#[RefersTo]`,
  étiquettes calculées).
- **Lot 2** : PR 1 à 4 mergées ; PR 5 à faire.
- **Déploiement unique** (décision du fondateur, 2026-11-21) : tout le lot conception part en **une seule mise en
  production**, à la fin, depuis `lot/conception`, sur GO du fondateur. Il n'y a donc **aucune attente d'une release
  du lot 1 en production** : le lot 3 s'ouvre quand le lot 1 est entièrement mergé sur `lot/conception`, et ses PR
  s'y empilent une à la fois. Les tables d'étiquettes partent dans la PR 1 du lot 3, dans la même mise en production
  que le lot 1.

## 1. Besoin reformulé

1. Le nom, le mail et le téléphone d'un coach vivent **une fois, au club** (la personne). Le coach d'une saison devient le **rôle** de cette personne cette saison-là.
2. L'adresse et la position d'un gymnase vivent **une fois, au club** (le lieu). Le gymnase d'une saison devient l'**usage** de ce lieu ; nom, couleur, « divisible », numéro fédéral restent à la saison.
3. Le temps de trajet entre deux lieux vit **une fois, au club**.
4. « Préparer la saison suivante » recopie toujours les rôles et les gymnases de saison (cible §15), plus l'identité ni les trajets.
5. Effacer une personne efface **tout ce qui vient d'elle**.
6. Conditions d'entrée : suppression des deux tables d'étiquettes, dans la PR 1 du lot 3 (même mise en production que le lot 1) ; une époque dans la clé du cache des demandes.

**Ce que le gestionnaire voit changer** : corriger le téléphone d'un coach, l'adresse d'un gymnase ou un temps de trajet dans une saison le corrige dans toutes, saisons passées comprises (décision 7). **Aucun fichier de `frontend/`**, sauf si la décision 2 avait retenu l'option C (elle ne l'a pas été).

## 2. Constats vérifiés dans le code

**Identité et ses lecteurs** (appels sur l'objet, SQL brut, DQL, critères de recherche, JSON)
- Coach : saison et identité sur la même ligne (`backend/src/Entity/Coach.php:35-48`, genre `:72-73`) ; options de planning `:50-51`, `:56-57` (salarié), `:64-65` (véhiculé).
- **18 fichiers de `src/` lisent l'identité par l'objet** : `ApiResource/CoachResource.php`, `Command/CoachWishDigestCommand.php`, `Controller/CoachWishCampaignActionController.php`, `Controller/ConflictCoachMailController.php`, `Export/ScheduleExportDataProvider.php`, `Seed/BcclSeeder.php`, `Service/CoachDoubleBookingDetector.php`, `CoachWishCampaignPresenter.php`, `CoachWishFormPresenter.php`, `CoachWishPerimeter.php`, `CoachWishTransferService.php`, `ConflictCoachMailBuilder.php`, `ConflictCoachMailing.php`, `DevScheduleReportWriter.php`, `PlacedSessionPersonConflictDetector.php`, `ScheduleConstraintBuilder.php`, `ScheduleDiagnosticsRecorder.php`, `SeasonTransitionService.php`. À relire à l'ouverture : `Controller/CoachWishEmailPreviewController.php`, `Controller/PublicCoachWishController.php`.
- **Un lecteur en SQL brut** : `EventListener/ClockedClubMailInterceptor.php:173` (`SELECT email FROM coach WHERE club_id = ?`), qui décide quels mails un club à horloge simulée garde en boîte.
- **Deux recherches par prénom** : `Seed/LoadTestClubSeeder.php:458`, `Seed/BcclSeeder.php:785`.
- **Une copie générique de toutes les colonnes** : `StructureSnapshotter.php:136-150`.
- Non touchés, vérifiés : SQL brut sans colonne d'identité (`AdminMonitoringService.php:124`, `:286-287`, `WriteTargetSeasonResolver.php:49`, `SchedulePlanProvisioner.php:1301`) ; DQL par identifiant (`StructureRestorer.php:143`, `:162-163`) ; lecteurs de la demande au moteur, qui ne change pas (`PreSolvePreventionWarnings.php:939`).
- Gymnase : adresse et position `Entity/Venue.php:47-55`, lues par l'objet dans 7 fichiers (`VenueResource`, `VenueGeoCheck`, `VenueTravelTimeAutofillService`, `MatchesExportDataProvider`, `VenueStateProcessor`, `SeasonTransitionService`, `BcclSeeder`), aucun SQL brut.
- Barème de trajet par saison : `Entity/VenueTravelTime.php:35`, `:59-60` ; couple rangé « plus petit identifiant de gymnase d'abord » (`:24`), repris à la copie (`SeasonTransitionService.php:497-499`), à l'émission (`ScheduleConstraintBuilder.php:1264-1267`), attendu par l'écran (`frontend/src/features/wizard/api.ts:660`).
- Créations dans `src/` : coach à 4 endroits (`CoachStateProcessor.php:65`, `SeasonTransitionService.php:286`, `BcclSeeder.php:801`, `LoadTestClubSeeder.php:460`) ; gymnase à 5 (`VenueStateProcessor.php:70`, `SeasonTransitionService.php:197`, `Service/ParcImport/ParcImportExecutor.php:258`, `BcclSeeder.php:420`, `LoadTestClubSeeder.php:352`). Dans les tests : 79 et 143 endroits.
- L'import du parc crée un gymnase **sans adresse** (`ParcImportExecutor.php:41`, `:258-264` ; texte `frontend/src/features/wizard/parcImport/texts.ts:30`).
- Convention de zone : colonnes de clé simples (`backend/AGENTS.md:16`) ; une clé posée à la main est invisible de Doctrine (`backend/AGENTS.md:31`).

**Ce qui part au moteur**
- Coach : `id`, prénom, nom, plafond de jours, salarié, véhiculé (`ScheduleConstraintBuilder.php:1487-1505`). Gymnase : `id`, nom, créneaux (`:1340-1344`). Trajets lus à deux endroits (`:320`, `:539`). Le nom d'une règle part aussi (`:1710`, `:1735`).
- Les références 1 à 8 ne contiennent aucun temps de trajet.

**Où vit ce qui vient d'une personne** (relevé pour l'effacement)
| Donnée | Où | Constat |
|---|---|---|
| Nom, mail, téléphone, genre | `coach` | `Coach.php:38-48`, `:72-73` |
| Commentaire libre d'une doléance | `coach_wish.comment` | `CoachWish.php:100-101` ; survit à la suppression du coach (`CascadePlan.php:163`) |
| Jours d'absence d'un membre de l'encadrement | `coach_wish.staff_unavailability` | `CoachWish.php:81-89` ; retirés par `CoachWishStaffLineStep` |
| Nom dans le nom d'une règle | `constraint.name` | « Paul Martin · indispo lundi » (`ConstraintsStep.tsx:410`) |
| Nom dans le texte d'un diagnostic | `schedule_diagnostic.message` | `ScheduleDiagnosticsRecorder.php:274-276`, `:85` ; ceux qui portent l'identifiant du coach partent avec lui (`CascadePlan.php:152`) |
| Demande figée d'une version | `schedule.snapshot_data` | `Schedule.php:96` : prénom, nom, noms de règles |
| Signalement | `feedback.context` | Embarque toute la demande au moteur et les diagnostics du planning nommé (`FeedbackController.php:213-216`, enregistré `:224-232`), et d'office ceux de la dernière génération en échec (`FeedbackContextEnricher.php:385-387`). Hors de toute purge de saison ; seule sortie : l'effacement du club (`ErasedClubPurger.php:75`) et la purge 30 jours après « traité » |
| Photo d'une version | `schedule_structure_snapshot.data` | Toutes les colonnes du coach et les règles (`StructureSnapshotter.php:52-63`) |
| Boîte d'un club à horloge simulée | `club_mailbox_message` | Adresse du destinataire et corps du mail (`ClubMailboxMessage.php:48-58`). Le récapitulatif de fin de collecte, adressé aux gestionnaires par tâche planifiée, y liste les coachs par leur nom composé (`CoachWishDigestCommand.php:217`, `:248`). **Clubs de démonstration seulement** : un vrai club ne peut pas porter d'horloge simulée (`Clock/ClubClock.php:114-118`, contrainte `migrations/Version20261004140000.php:34`) |
| Conflits entre règles | `constraint_conflict` | Texte libre, sans colonne de club, seulement `scheduleId` (`ConstraintConflict.php:31-32`). Aucun écrivain dans `src/` : seules deux purges la nomment (`SeasonDataPurger.php:114`, `OverlayManager.php:231`) |
| Liens de collecte, reçus de mails | `coach_wish_token`, `conflict_mail_receipt` | Partent par clé étrangère avec la ligne du coach |
| PDF du planning déjà fabriqués | Fichiers, et leur adresse sur la version et la saison | `ExportPdfHandler.php:123-125`, `ScheduleResource.php:235-236`, `Season.php:48` ; purge à 90 jours, sauf celui que la saison épingle |

**« Charger », suppression, dissociation, purge**
- « Charger » efface tous les coachs et gymnases de la saison puis réinsère ceux de la photo (`StructureRestorer.php:272-273`, `:104-118`), filtre de club désactivé (`:95`) : un coach supprimé depuis revient, un coach ajouté depuis disparaît.
- Supprimer un coach resynchronise les liens des collectes ouvertes (`CoachStateProcessor.php:39-51`).
- « Dissocier de la salle fédérale » (`VenuesStep.tsx:521-525`) vide adresse et position (`VenueStateProcessor.php:116-120`) puis les temps de trajet du gymnase, saisies à la main comprises (`:151-153`, `:219-225`). « Annuler » renvoie numéro, adresse et position d'avant (`VenuesStep.tsx:510-513`) ; ce vrai changement de position relance le calcul des temps (`VenueStateProcessor.php:143-148`), les temps saisis à la main ne reviennent pas (texte `texts.ts:191`).
- Rétention : saison en cours et précédente (`docs/security/rgpd.md:22`). Saison passée en lecture seule : `SeasonAccessGuard.php:44-66`.
- L'export des données lit `SELECT *` par table (`RgpdExportService.php:134`) ; le contrôle des purges parcourt les entités (`tests/Security/PurgeCompletenessTest.php:146`).

**Tests et déploiement**
- En Behat, la connexion par défaut est soumise à la sécurité par club ; seule la connexion d'administration la traverse (`tests/Behat/BaseContext.php:78-88`).
- Sauvegarde `scripts/remote-deploy.sh:70-75`, migration `:80-82`, redémarrage de tous les services, traitement en file et tâches planifiées compris (`:84-85`).
- La page de maintenance posée par le workflow est retirée seule dès le succès (`.github/workflows/deploy.yml:388`, `:462`).
- Règle : une migration reste compatible une release en arrière (`docs/ops/deploy.md`, § « Règle d'écriture des migrations »).
- Les migrations sont nommées par leur date d'écriture (`backend/migrations/`) : la plus récente en numéro n'est pas toujours la dernière arrivée.

## 3. Conception : les cinq réponses par objet

### 3.1 La personne (`person`) et le rôle coach (`coach`, conservée)
1. **Le plus simple** : une table `person` (club, prénom, nom, mail, téléphone, genre). `coach` garde ses identifiants, devient le rôle d'une saison, gagne `person_id`, perd ses colonnes d'identité dans la même migration.
2. **Supprime** : les colonnes d'identité de `coach`, la recopie de l'identité (`SeasonTransitionService.php:289-292`, `:299`), les accesseurs d'identité de `Coach`.
3. **Un seul endroit** : semblable existant : `Coach`. On l'étend.
4. **Suppose en dur** : une personne appartient à un club ; au plus un rôle coach par personne et par saison ; clé composée `(person_id, club_id)`, posée à la main, ajoutée à la liste de `backend/AGENTS.md:31`.
5. **Deuxième usage** : le salarié non coach (P4-613) : une table de rôle qui pointe `person`. Le lien vers un compte : une colonne.

**Forme** : colonne nue `personId` ; une personne est obligatoire pour créer un coach. Toute lecture d'identité passe par `PersonDirectory`, toujours borné par le club, SQL brut compris.

### 3.2 Le lieu (`place`) et le gymnase de saison (`venue`, conservée)
1. **Le plus simple** : `place` (club, adresse, latitude, longitude) ; `venue` gagne `place_id` et perd ces trois colonnes dans la même migration. Colonne nue, lieu obligatoire, lecture par `PlaceDirectory`.
2. **Supprime** : trois colonnes de `venue`, la recopie de l'adresse (`SeasonTransitionService.php:203-205`).
3. **Un seul endroit** : numéro fédéral et libellés FBI restent sur le gymnase de saison.
4. **Suppose en dur** : un lieu sans type ; un seul gymnase par lieu et par saison.
5. **Deuxième usage** : un bureau, une salle de réunion : un lieu de plus.

### 3.3 Le temps de trajet au club (`place_travel_time`)
1. **Le plus simple** : mêmes colonnes que `venue_travel_time`, deux lieux, sans saison ; `venue_travel_time` supprimée dans la même migration.
2. **Supprime** : la table et son entité, la recopie (`SeasonTransitionService.php:488-511`), deux étapes de cascade (`CascadePlan.php:111-112`), l'entrée de purge de saison.
3. **Un seul endroit** : `ClubTravelCache` garde un calcul par coordonnées, sans saisie manuelle : autre information.
4. **Suppose en dur** : couple non ordonné, rangé « plus petit identifiant de lieu d'abord » en base ; à la sortie (moteur, API), rangé selon les identifiants de gymnases de la saison demandée. Clés composées avec le club, comme `coach` et `venue`.
5. **Deuxième usage** : aucun.

### 3.4 L'époque du cache
1. **Le plus simple** : la clé porte l'empreinte de la liste des noms de fichiers de `backend/migrations/` dans l'image, calculée une fois par processus. Toute migration ajoutée change la clé, quelle que soit sa date ; rien à tenir à la main.
2. **Supprime** : la limite 1 du lot 1 et le repli de l'ancienne clé dans `CacheInvalidationListener.php`.
3. à 5. Semblable : la version du contrat, déjà dans la clé (`ScheduleConstraintBuilder.php:160-163`). Suppose le dossier présent dans l'image (c'est elle qui migre).

### 3.5 Écarts à la cible, dits comme tels
| Écart | Cible | Ce plan | Suite |
|---|---|---|---|
| Tables neuves | Personnes, lieux, rôles (§26) | Deux ; `coach` tient lieu de table de rôle | Tranché |
| Statut salarié | « Option de la personne » (§5.1) et « rôle à la saison » (§3) | Reste sur le rôle de saison | **Décision 3** |
| Photos de versions | Non converties puis supprimées (§26, §27) | Nettoyées de l'identité, gardées jusqu'au lot 5 | Lot 5 |
| Reconnaître une même personne entre saisons | Règle du §27.1 | Non codée ; mesure attendue à zéro | Mesure 1 |
| Mention dans la saison passée | « personne supprimée » (§9.4) | Séances sans coach | **Décision 5** |
| Migration compatible une release en arrière | `deploy.md` | Dérogation, site fermé, mise en production unique du lot conception | **Décision 6** |

## 4. Découpage : six PR

Ordre : 1 → 2a → 2b → 3 → 4 → 5, sur `lot/conception`, une PR ouverte à la fois, avant les lots suivants. Le lot 3 n'a ni copie de production ni mise en production à lui : la **copie de production n° 2** (rejeu de toutes les migrations des lots ensemble) vient avant la PR finale `lot/conception` → `main` (GO du fondateur), puis la **mise en production unique**, site fermé (§6, §10). Exécutant : `neo`, après les rouges de `tank`.

### PR 1 · « L'entrée du lot » — LIVRÉE (2026-11-22)
- **Livré** : `Support/SchemaEpoch.php` (neuf), `migrations/Version20261122120000.php` (supprime `team_tag_assignment` puis `team_tag`, irréversible), clé du cache `…contract.<c>.epoch.<e>.schedule_input` (`ScheduleConstraintBuilder::cacheKey`), deux références moteur (`socle-9-trajets-saison-suivante.json`, `periode-10-trajets.json`) et leur décor (`EngineRequestTravelDecorTrait`, `EngineRequestReferenceTest`), tests de `tank` : `Unit/Service/ScheduleCacheKeyEpochTest` (bloquant, `docs/testing/blocking-tests.md`), recalage de `TeamTagScopeTest`, `ReadOnlyRoleTest`, `RlsIsolationTest`, `PurgeSeasonsCommandTest`, `StructureRestorerTest`, `CategoryRuleContext`. Doc : `rgpd.md`, `rls.md`, `TENANT.md`, `backend-inventory.md`, `scripts/prod-read.sh`, `deploy.md`.
- **Limites écrites** :
  - L'époque se calcule sur les NOMS de fichiers : une migration corrigée sans être renommée ne change pas l'époque (cohérent avec « migrations immuables une fois livrées »). Elle ne couvre pas un changement de code sans migration : `CONTRACT_VERSION` et le TTL de 4 h le couvrent.
  - La migration supprime des tables que la production lit encore : **déploiement unique, site fermé, obligatoire** (§6) ; sans retour arrière par `down()` (sauvegarde d'avant migration).
  - L'étalon `team` de `RlsIsolationTest` n'est pas falsifiable sans DDL (désactiver sa policy exige de modifier le schéma).
  - `Version20261108120000::down()` lit encore `team_tag` (prédicat de référence) mais est inatteignable : la migration suivante est irréversible.
  - `DeleteByFieldStep::$clubScoped` (`DeleteByFieldStep.php:26`) n'a plus d'appelant qui le passe à `false` : nettoyage ultérieur à proposer.
- **Plan d'origine** (le détail livré ci-dessus fait foi) :
- Supprime `team_tag` et `team_tag_assignment`, leurs entités, leurs dépôts (`Repository/TeamTag*`), entrées de purge, étape de `CascadePlan` et sa ligne « N étiquettes » (F10, `CascadePlan.php:78`, commentaire de `DeleteByFieldStep.php:14`), `DeletionImpactParityTest` et `SILENT_STEPS` (recalés), famille dans la photo et la restauration, commande `app:team-tags:verify-stored` et `VerifyStoredTeamTagsCommandTest`. Recale les tests et scripts qui lisent les deux tables : `TeamTagAssignmentReadIsolationTest`, `TeamTagScopeTest`, `CategoryRuleContext` (« aucune étiquette n'est enregistrée »), `ReadOnlyRoleTest.php:135-140` et `:287-325`, `PurgeSeasonsCommandTest.php:81`, `:206-213`, `scripts/prod-read.sh:54`, `docs/ops/deploy.md:474`. Cas de test neuf : « Charger une photo d'avant qui porte encore la clé `TeamTagAssignment` réussit après suppression de l'entité » (`StructureRestorer.php:104-105` ignore une clé inconnue). Condition d'entrée : **dans la même mise en production que le lot 1**, aucune release d'écart (décision du 2026-11-21 ; l'ancienne règle « deux releases » du plan du lot 1 est levée). `RlsIsolationTest` prend `team` pour étalon ; `VenueUnavailabilityDropMigrationTest` lit le même étalon : retiré si sa migration est en production, sinon recalé.
- Époque dans la clé du cache.
- **Deux références captées sur le code d'avant** :
  - `socle-9-trajets-saison-suivante.json` : la demande de la saison suivante d'un club à deux saisons. La saison suivante du décor est construite à la main, identifiants fixés (le geste « Préparer » tire des identifiants au hasard, `SeasonTransitionService.php:197`). Trois gymnases, trois couples dont un saisi à la main et un sans minutes ; un coach salarié véhiculé, un non véhiculé ; un couple est rangé à l'envers d'une saison à l'autre.
  - `periode-10-trajets.json`.
- **Fichiers** : une migration, `Entity/TeamTag*.php`, `StructureSnapshotter.php`, `StructureRestorer.php`, `CascadePlan.php`, les purgeurs ou leur catalogue, `ScheduleConstraintBuilder.php:160-163`, `Support/SchemaEpoch.php` (neuf), `CacheInvalidationListener.php`, deux références et leur décor.
- **Tests** : lignes 1 à 3. `/security-review` : oui.

### PR 2a · « Une seule porte pour lire l'identité, aucun comportement ne change » — LIVRÉE (2026-11-22)
- **Livré** : `Structure/PersonDirectory.php` (`of`, `forCoaches`, `emailsOfClub`) et `Structure/PersonIdentity.php` (neufs) ; les 18 lecteurs du §2 routés par la porte (ils lisent une `PersonIdentity`, plus les accesseurs de `Coach`) ; `ClockedClubMailInterceptor` lit les adresses par `PersonDirectory::emailsOfClub` (plus de SQL brut sur `coach` dans ce fichier) ; règle de lecture dans `backend/AGENTS.md` (gotcha 3c). Aucun test neuf ni modifié : la ligne 4 du §7 reste verte telle quelle.
- **Limites écrites** :
  - La connexion de `PersonDirectory` est **optionnelle** pour cinq classes construites à la main dans des tests unitaires (`CoachWishDigestCommand`, `ConflictCoachMailBuilder`, `ConflictCoachMailing`, `ScheduleConstraintBuilder`, `ScheduleDiagnosticsRecorder` : `new PersonDirectory` en valeur par défaut) : à revoir en 2b, quand `of()` lira la table `person`. `emailsOfClub` sans connexion lève une `LogicException`.
  - Le nom de famille peut être **vide** (mesure de production du 2026-11-22, §12) : `PersonIdentity::fullName()` fait un `trim`.
  - **Aucun test d'architecture n'interdit de contourner la porte** avant la 2b : c'est la disparition des accesseurs de `Coach` en 2b qui la rend obligatoire.
- **Plan d'origine** (le détail livré ci-dessus fait foi) :
- `PersonDirectory` et `PersonIdentity`, qui lisent encore les colonnes actuelles de `coach`. Les 18 lecteurs, les deux fichiers à relire et `ClockedClubMailInterceptor.php:173` passent par eux. Aucune migration.
- **Raison de la scission** : une PR unique mêlait la réécriture de vingt lecteurs à une conversion de données ; 2a se relit et se prouve seule, 2b ne porte plus que le schéma.
- **Fichiers** : `Structure/PersonDirectory.php`, `Structure/PersonIdentity.php` (neufs), les fichiers du relevé du §2.
- **Tests** : ligne 4. `/security-review` : oui (horloge simulée).

### PR 2b · « La personne au club » — LIVRÉE (2026-11-22)
- **Livré** : `Entity/Person.php` et `Structure/UnusedIdentitySweeper.php` (neufs), `migrations/Version20261122140000.php` (irréversible ; clé composée `(person_id, club_id)` `RESTRICT`, `UNIQUE (id, club_id)`, `UNIQUE (season_id, person_id)`, RLS sur le patron de `Version20261110120000`), `Coach` sans accesseur d'identité (`personId`), `PersonDirectory` qui lit `person` (`personOf` rend la fiche à écrire), `CoachStateProcessor` (écrit la fiche, touche la ligne), copie de saison (réutilise la fiche), `StructureRestorer` (une ligne de photo sans fiche dans le club n'est pas réinsérée, ni ses liens, ni ses règles), les deux seeds, `ErasedClubPurger`, les trois appelants du balayage. Bloquant ajouté : `Security/PersonPlaceTenantIsolationTest` (`ci.yml` et `blocking-tests.md`). Doc : ADR-0006, `rgpd.md`, `rls.md`, `TENANT.md`, `backend/AGENTS.md` (3c, 16), `glossary.md`, `backend-inventory.md`, `test-coverage-map.md`.
- **Écarts au plan, écrits** :
  - `UnusedIdentitySweeper` n'est PAS appelé par `SeasonDataPurger` mais par ses trois appelants : `ResetSeasonController`, `ResetClubSeasonCommand`, `PurgeSeasonsCommand` (hors `--dry-run`, seulement si une saison a été purgée).
  - `rowsDeleted` d'une remise à zéro exclut les fiches balayées ; celui de l'effacement du club les compte (famille de niveau club).
  - Les défauts `new PersonDirectory` des cinq classes construites à la main sont gardés : l'EntityManager de `PersonDirectory` est nullable, et un coach relu de la base y lève une `LogicException` (un coach construit avec sa fiche, `new Coach($person)`, se lit sans base).
  - `ErasedClubPurger` efface désormais les lignes de saison dont la saison n'existe plus (`season_id` sans clé étrangère), enfant avant parent, avant les familles de club : sans cela un coach orphelin retiendrait sa fiche (`RESTRICT`). Les familles `purgedViaOwner()` d'une saison disparue ne sont pas traitées.
  - Les 20 coachs de production sans nom de famille sont acceptés (nom de famille vide permis).
- **Contrainte de livraison** : la 2b et la PR 3 (`app:people:erase`) partent en production **ensemble** (déploiement unique) ; avant la PR 3, aucune commande n'efface une personne, et `schedule.snapshot_data` garde prénom et nom.
- **Plan d'origine** (le détail livré ci-dessus fait foi) :
- `person`, `coach.person_id`, conversion une personne par ligne de coach, sans fusion, identifiants de `coach` inchangés ; colonnes d'identité supprimées ; la migration s'annule si les comptes diffèrent. `PersonDirectory` lit `person`.
- Photos existantes nettoyées : identité retirée, identifiant de personne ajouté ; une ligne de coach qui n'existe plus est retirée.
- `PUT /api/coaches/{id}` écrit la personne et touche la ligne. La saison suivante réutilise la personne.
- Fiche sans rôle (décision 1, A) : gardée jusqu'à la purge de la saison, balayée à la remise à zéro, à la purge de rétention et à l'effacement du club.
- « Charger » : une ligne de photo dont la personne n'existe plus n'est pas réinsérée, ni ses liens d'équipe, ni ses règles ; recherche bornée par le club.
- **Fichiers** : `Entity/Person.php`, `Structure/UnusedIdentitySweeper.php` (neufs), `Entity/Coach.php`, une migration, `CoachStateProcessor.php`, `Structure/PersonDirectory.php`, `SeasonTransitionService.php:284-303`, `StructureRestorer.php`, `SeasonDataPurger.php`, `ErasedClubPurger.php`, `ResetSeasonController.php`, `Command/ResetClubSeasonCommand.php`, `Seed/BcclSeeder.php:785-801`, `Seed/LoadTestClubSeeder.php:458-463`, `backend/AGENTS.md` ; 51 fichiers de test adaptés mécaniquement.
- **Tests** : lignes 5 à 15. `/security-review` : oui.

### PR 3 · « Effacer une personne » — LIVRÉE (2026-11-22)
- **Livré** : `Structure/PersonEraser.php`, `Structure/PersonErasureBlockedException.php`, `Command/ErasePersonCommand.php` (neufs), `PdfGenerator::deleteRendersOf`, `AuditAction::PERSON_ERASED` ; suite Behat `effacement-personne`. Bloquant ajouté : `Security/PersonErasureLeavesNoTraceTest` (`ci.yml` et `blocking-tests.md`). Doc : `rgpd.md`, `commands.md`, `backend-demo.md`, `test-coverage-map.md`, `validation-runner`. `StructureRestorer.php` et `CoachWishCampaignTokenSync.php` n'ont pas changé.
- **Écarts au plan, écrits** :
  - Remplacement du nom COMPOSÉ dans les textes libres (mot entier, casse ignorée) ; un nom d'un seul mot n'est jamais remplacé par texte (limite 7).
  - Ses règles sont relevées PAR IDENTIFIANT avant la cascade : leur nom exact est remplacé dans les diagnostics et les signalements même sans son nom composé.
  - Option `--sweep-files` (`--club` seul) : reprise de la suppression des PDF après un arrêt entre la validation et les fichiers.
  - Verrous de génération et de placement du club pris pendant l'effacement ; refus (code 1, rien ne bouge) s'ils sont tenus.
  - L'identifiant de la demande PDF livrée est remis à vide avec l'adresse (sinon la demande relue serait tenue pour déjà rendue).
  - Audit sans acteur (commande de support).
- **Plan d'origine** (le détail livré ci-dessus fait foi) :
`PersonEraser`, dans une transaction, pour chaque saison du club, lecture seule comprise (décision 5) :
1. vide le commentaire des doléances dont elle est l'auteur ;
2. joue la cascade de suppression du coach et supprime la ligne ; liens de collecte et reçus partent par clé étrangère ;
3. resynchronise les liens des collectes ouvertes (patron `CoachStateProcessor.php:39-51`) ;
4. **une seule règle de remplacement**, par « Personne supprimée » : la fiche du coach et le nom de ses règles par identifiant, son nom composé dans les textes. Elle s'applique aux demandes figées (empreinte réalignée), aux diagnostics qui restent, et au contexte des signalements du club (demande et diagnostics embarqués, branche « génération en échec » comprise). Les diagnostics qui portent l'identifiant du coach partent par la cascade, règle actuelle inchangée ;
5. dans les photos, retire sa ligne, ses liens d'équipe et ses règles ;
6. dans la boîte de démonstration : supprime les messages qui lui sont adressés et applique la même règle de remplacement au sujet et au corps des autres (le récapitulatif de fin de collecte la cite). Choix : la règle existe déjà à l'étape 4, une table de plus ne coûte presque rien, et une limite aurait laissé une trace connue ;
7. supprime les PDF stockés des versions du club et remet à vide leur adresse sur la version et sur la saison (ils se refabriquent à la demande) ; vide le cache des demandes du club ;
8. supprime la fiche ; trace d'audit en identifiants seuls.
- `constraint_conflict` n'est pas traitée : rien ne l'écrit, elle n'a pas de colonne de club ; la mesure 7 doit valoir zéro.
- Geste : commande de support (décision 4, A). Aucune route, aucun écran ; le bouton vient au lot 6.
- **Fichiers** : `Structure/PersonEraser.php`, `Command/ErasePersonCommand.php` (neufs), `StructureRestorer.php`, `Service/CoachWishCampaignTokenSync.php`, le service de stockage des rendus PDF, catalogue d'audit.
- **Tests** : lignes 16 à 19. `/security-review` : oui (RGPD, démonstration).

### PR 4 · « Le lieu au club » — LIVRÉE (2026-11-23)
- **Livré** : `Entity/Place.php`, `Structure/PlaceDirectory.php` (neufs), `migrations/Version20261125120000.php` (irréversible ; clé composée `fk_venue_place_club` `RESTRICT`, `UNIQUE (id, club_id)`, `UNIQUE (season_id, place_id)`, RLS sur le patron de `person`), `Entity/Venue.php` (`placeId`, sans accesseur d'adresse), `VenueStateProcessor`, `VenueResource`/`VenueStateProvider`, `VenueGeoCheck`, `VenueTravelTimeAutofillService`, `MatchesExportDataProvider`, `ParcImportExecutor`, `SeasonTransitionService`, `StructureRestorer`, `UnusedIdentitySweeper` (les lieux), les deux seeds. Bloquant étendu : `Security/PersonPlaceTenantIsolationTest` (`ci.yml` et `blocking-tests.md`). Doc : ADR-0006, `rgpd.md`, `rls.md`, `TENANT.md`, `backend/AGENTS.md` (3d, 16), `glossary.md`, `backend-inventory.md`, `geo-api.md`, `test-coverage-map.md`.
- **Écarts au plan, écrits** :
  - Le recalcul des trajets après un déplacement part pour CHAQUE saison où le lieu a un gymnase et qui a déjà une matrice (lecture SQL hors `season_filter`, club en clause explicite).
  - La migration crée, pour la ligne de photo d'un gymnase qui n'existe plus, un lieu depuis l'adresse et la position de la photo.
  - « Charger » sur un lieu balayé recrée le gymnase sur un lieu NEUF sans adresse : jamais un gymnase perdu, mais l'adresse est à ressaisir (limite).
  - `ClubTravelCacheSeedTest` est retiré avec le filet `VenueTravelTimeAutofillCacheTest` (il rejouait les colonnes de `venue` qui n'existent plus) ; le commentaire de `Version20260920120000`, livrée, n'est pas modifié.
  - La saison passée archivée suit la correction d'adresse (décision 7A, aucune garde « saison close » sur le lieu).
- **Plan d'origine** (le détail livré ci-dessus fait foi) :
- `place`, `venue.place_id`, conversion un lieu par gymnase, sans fusion ; trois colonnes supprimées ; vérification de compte. Photos : identifiant de lieu ajouté, adresse retirée.
- `PUT /api/venues/{id}` écrit le lieu ; la saison suivante le réutilise. Tout gymnase créé reçoit un lieu neuf : avec l'adresse fournie depuis l'étape Gymnases, sans adresse depuis l'import du parc, comme aujourd'hui.
- **Dissocier** (décision 2, A) : le gymnase de cette saison passe sur un lieu neuf sans adresse ; la saison en cours garde tout.
- Un lieu sans gymnase part à la remise à zéro, à la purge de rétention et à l'effacement du club.
- Non scindée : 7 lecteurs seulement ; 117 fichiers de test adaptés mécaniquement.
- **Fichiers** : `Entity/Place.php`, `Structure/PlaceDirectory.php` (neufs), `Entity/Venue.php`, une migration, `VenueStateProcessor.php:70-84`, `:112-153`, `ApiResource/VenueResource.php`, `Service/Geo/VenueGeoCheck.php`, `Export/MatchesExportDataProvider.php:208`, `Service/ParcImport/ParcImportExecutor.php:258`, `SeasonTransitionService.php:195-214`, `StructureRestorer.php`, purgeurs, les deux seeds.
- **Tests** : lignes 20 à 25. `/security-review` : oui.

### PR 5 · « Les temps de trajet au club »
- `place_travel_time`, conversion ligne à ligne, `venue_travel_time` supprimée.
- Le constructeur émet, pour la saison demandée, les couples dont les deux lieux ont un gymnase cette saison, rangés selon les identifiants de gymnases de cette saison, triés comme aujourd'hui. L'API range de même.
- Écriture refusée si la saison de la requête est en lecture seule. Une valeur saisie à la main n'est jamais écrasée par le calcul.
- Supprimer un gymnase n'annonce plus et ne supprime plus de barème.
- **Fichiers** : `Entity/PlaceTravelTime.php`, `Repository/PlaceTravelTimeRepository.php` (neufs), `Entity/VenueTravelTime.php`, `Repository/VenueTravelTimeRepository.php` (supprimés), une migration, `ScheduleConstraintBuilder.php:320`, `:539`, `:1252-1270`, `ApiResource/VenueTravelTimeResource.php`, `State/Processor/VenueTravelTimeStateProcessor.php`, `State/Provider/VenueTravelTimeStateProvider.php`, `Controller/VenueTravelTimeAutofillController.php`, `Service/Geo/VenueTravelTimeAutofillService.php`, le traitement en file du calcul des trajets, `VenueStateProcessor.php:219-225`, `SeasonTransitionService.php:488-511`, `CascadePlan.php:111-112`, `Service/SeasonDataPurger.php`, `CoachDoubleBookingDetector.php`, `PlacedSessionPersonConflictDetector.php`, `Seed/LoadTestClubSeeder.php`.
- **Tests** : lignes 26 à 32. `/security-review` : oui (isolation, appel sortant IGN).

**Raison du découpage** : la PR d'entrée est imposée par le lot 1 ; la personne tient en deux PR (2a, 2b) parce qu'une seule serait trop grosse à relire ; l'effacement est un chantier à lui ; lieu et trajets, une PR chacun.

## 5. Contrats nouveaux

```php
// PR 1
App\Support\SchemaEpoch::current(): string          // 12 premiers caractères du sha256 de la liste triée des noms de fichiers de migrations
ScheduleConstraintBuilder::cacheKey(...)            // signature inchangée ; clé : …contract.%s.epoch.%s.schedule_input

// PR 2a
final readonly class App\Structure\PersonIdentity { string $firstName; string $lastName; ?string $email; ?string $phone; CoachGender $gender; fullName(): string }
App\Structure\PersonDirectory::of(Coach $coach): PersonIdentity
App\Structure\PersonDirectory::forCoaches(array $coaches): array<string, PersonIdentity>   // clé = id du coach ; borné par le club
App\Structure\PersonDirectory::emailsOfClub(string $clubId): list<string>                  // remplace le SQL de ClockedClubMailInterceptor:173

// PR 2b — table person : id, version, created_at, updated_at, club_id, first_name(120), last_name(120),
//        email(180, null), phone(40, null), gender(12) ; RLS sur le patron de Version20261110120000
App\Entity\Person implements TenantOwnedInterface
Coach::__construct(Person $person) ; Coach::getPersonId(): string       // plus d'accesseur d'identité sur Coach
// coach.person_id NOT NULL ; (person_id, club_id) → person (id, club_id) ON DELETE RESTRICT ; UNIQUE (season_id, person_id)
App\Structure\UnusedIdentitySweeper::sweep(string $clubId): array{people: int, places: int}   // « places » vaut 0 jusqu'à la PR 4
// /api/coaches : mêmes routes, mêmes champs
App\Tests\CreatesPeopleAndPlacesTrait::newCoach(string $clubId, string $seasonId, string $firstName = 'Test', string $lastName = 'Coach'): Coach
App\Tests\CreatesPeopleAndPlacesTrait::newVenue(string $clubId, string $seasonId, string $name): Venue   // PR 4

// PR 3
App\Structure\PersonEraser::erase(string $clubId, string $personId): array{removedCoachIds: list<string>, blankedWishes: int, rewrittenSchedules: int, rewrittenFeedbacks: int, rewrittenMailboxMessages: int}
App\Structure\PersonEraser::PLACEHOLDER = 'Personne supprimée'
php bin/console app:people:erase --club=<uuid> --coach=<uuid> [--dry-run]    // 0 = effacée, 1 = introuvable ; identifiants et comptes seulement
// audit PERSON_ERASED {personId, removedCoachIds}

// PR 4 — table place : id, version, created_at, updated_at, club_id, address(255, null), latitude, longitude
App\Entity\Place implements TenantOwnedInterface
Venue::__construct(Place $place) ; Venue::getPlaceId(): string
// venue.place_id NOT NULL ; (place_id, club_id) → place (id, club_id) ON DELETE RESTRICT ; UNIQUE (season_id, place_id)
App\Structure\PlaceDirectory::of(Venue $venue): Place ; ::forVenues(array $venues): array<string, Place>
// /api/venues : mêmes routes, mêmes champs

// PR 5 — table place_travel_time : id, version, created_at, updated_at, club_id, place_a_id, place_b_id (a < b),
//        driving_minutes, walking_minutes, driving_source, walking_source ; UNIQUE (club_id, place_a_id, place_b_id) ;
//        (place_a_id, club_id) et (place_b_id, club_id) → place (id, club_id) ON DELETE CASCADE
App\Entity\PlaceTravelTime implements TenantOwnedInterface
PlaceTravelTimeRepository::forSeason(string $clubId, string $seasonId): list<array{row: PlaceTravelTime, venueAId: string, venueBId: string}>   // venueAId < venueBId
// /api/venue_travel_times : {id, version, createdAt, updatedAt, venueAId, venueBId, drivingMinutes, walkingMinutes, drivingSource, walkingSource}
```
Chaque famille neuve porte `#[Family(Level::CLUB)]` et ses `#[RefersTo]`.

## 6. Conversion, mise en production, retour arrière

- **Conversion** : 52 coachs deviennent 52 personnes, 18 gymnases 18 lieux, chaque barème un temps entre deux lieux (relevé du 2026-11-20, à refaire, §12). Aucune fusion. Chaque migration s'annule si ses comptes ne tombent pas juste.
- **Déploiement unique** (décision du fondateur, 2026-11-21) : les migrations de ce lot ne partent **pas** seules en production ; elles partent avec celles de tous les lots du lot conception, en une seule mise en production, depuis `lot/conception`, sur GO du fondateur. Les deux tables d'étiquettes (lot 1) sont supprimées dans la même mise en production (PR 1).
- **Dérogation écrite** à la règle « compatible une release en arrière » : les migrations suppriment ce que lit la release précédente (les tables d'étiquettes, les colonnes d'identité de `coach`, les colonnes d'adresse de `venue`, `venue_travel_time`). Raison : aucune copie morte de données personnelles, export et purge justes sans exception. Condition : la procédure ci-dessous. **Jamais par la mise en production programmée.**
- **Une seule procédure de mise en production**, manuelle, site fermé, pour tout le lot conception. Elle est écrite dans `docs/ops/deploy.md` par **la dernière PR avant la PR finale**, relue par le fondateur ; ce plan ne l'écrit pas, il dit ce qu'elle doit contenir au titre du lot 3. Elle fixe l'ordre des migrations de tous les lots, les vérifications et le critère de retour arrière. Étapes attendues :
  1. La veille : mesures du §12.
  2. Fermer : poser le témoin de maintenance à la main (`deploy.md`, § « Maintenance planifiée »), vérifier la page.
  3. Arrêter les tâches planifiées (`cron-runner`), attendre la file vide et aucune génération ni placement en cours, arrêter le traitement en file (`messenger-worker`).
  4. Déployer sans `maintenance_until` : le workflow ne touche pas au témoin (`deploy.yml:388`, `:462`). Sauvegarde, migration, puis tous les services redémarrent sur le nouveau code, traitement en file et tâches planifiées compris (`remote-deploy.sh:84-85`).
  5. Vérifier, site fermé : sondes de santé, comptes de conversion relus par `link`, journaux sans erreur. Choisir une heure où aucune tâche planifiée n'est due.
  6. Rouvrir : retirer le témoin.
- **Ce que la fermeture ne retient pas** : entre 4 et 6, les tâches planifiées et la file tournent ; un rappel par mail peut partir.
- **Retour arrière** (décision 6, A) : tant que le site est fermé, restaurer la sauvegarde d'avant migration puis redéployer le tag précédent. Aucune saisie de club n'est perdue ; les traces des tâches planifiées de ces minutes le sont (un rappel déjà parti peut repartir). Après réouverture : **correction en avant seulement**.
- **Deux copies de production pour tout le lot conception** : la n° 1 avant le merge de la PR 3 du lot 1 ; la n° 2 = rejeu de **toutes** les migrations ensemble, avant la PR finale. Chacune après accord du fondateur (§10).
- **Risques nommés** : une demande au moteur différente (références en CI, puis copie de production n° 2) ; un coach ou un gymnase sans fiche (compte vérifié par la migration) ; l'ordre d'un couple de trajet (référence 9) ; un lecteur d'identité oublié (après la PR 2b il ne compile plus, ou sa requête échoue : ligne 13).

## 7. Tableau comportement → scénario (brief de `tank`)

| # | PR | Comportement | Preuve, rouge avant `neo` |
|---|---|---|---|
| 1 | 1 | Les deux tables d'étiquettes n'existent plus ; l'isolation reste prouvée | `RlsIsolationTest` (bloquant), étalon `team` ; `StructureFamiliesStayInSyncTest` |
| 2 | 1 | Une demande mise en cache par un code qui n'a pas les mêmes migrations n'est jamais resservie, même si la migration ajoutée porte une date ancienne | `tests/Unit/Service/ScheduleCacheKeyEpochTest.php` ; `TenantCacheIsolationTest` reste vert |
| 3 | 1 | Deux demandes de plus figées à l'octet, dont celle d'une saison suivante au couple rangé à l'envers | `EngineRequestReferenceTest`, cas 9 et 10 (bloquant) |
| 4 | 2a | Rien ne change : mêmes demandes, mêmes mails, mêmes exports, même boîte d'un club à horloge simulée | Aucun test neuf. Restent verts sans modification : les dix références, `ClockedClubMailInterceptTest`, `voeux-des-coachs.feature`, `les-conflits-partent-aux-coachs.feature`, `l-export-du-planning.feature`, `generation-du-planning-de-saison.feature` |
| 5 | 2b | La saison suivante retrouve les mêmes personnes | `preparer-la-saison-suivante-reprend-la-structure.feature` : « Et les 4 coachs de la saison suivante sont les mêmes personnes que ceux de la saison en cours » |
| 6 | 2b | Corriger un téléphone dans une saison le corrige dans l'autre | Même feature : « Quand je corrige le téléphone de « Camille » dans la saison suivante » / « Alors « Camille » a ce téléphone dans la saison en cours » |
| 7 | 2b | Retirer un coach de la saison suivante ne le retire pas de la saison en cours | Même feature : « Quand je supprime le coach « Camille » de la saison suivante » / « Alors « Camille » entraîne toujours ses équipes dans la saison en cours » |
| 8 | 2b | Fiche sans rôle (décision 1, A) : gardée après suppression du coach, partie après remise à zéro | `supprimer-emporte-ce-qui-en-depend.feature` : « Et la fiche de « Camille » existe toujours dans le club » ; après la remise à zéro : « Et le club n'a plus aucune fiche de personne » |
| 9 | 2b | « Charger » : coach supprimé depuis, restauré sur sa fiche ; coach ajouté depuis, retiré de la saison ; personne disparue, non réinsérée, ni ses liens, ni ses règles | `tests/Integration/Service/StructureRestorerTest.php`, trois cas ; `voeux-des-coachs.feature` reste vert |
| 10 | 2b | La conversion crée une personne par coach, garde les identifiants, supprime les colonnes, nettoie les photos, s'annule si les comptes diffèrent | `tests/Integration/Migration/PersonConversionMigrationTest.php` |
| 11 | 2b | La demande au moteur est identique | `EngineRequestReferenceTest`, dix fichiers inchangés (bloquant) |
| 12 | 2b | Un club ne lit ni ne modifie la fiche d'un autre ; un coach ne peut pas désigner la personne d'un autre club ; la clé composée existe (abus) | `tests/Security/PersonPlaceTenantIsolationTest.php`, à ajouter au step `Blocking suite` et à `blocking-tests.md` |
| 13 | 2b | Un mail adressé à un coach d'un club à horloge simulée reste dans la boîte, jamais envoyé ; celui d'un autre club n'y entre pas | `tests/Integration/Mail/ClockedClubMailInterceptTest.php`, cas neuf sur une adresse de fiche |
| 14 | 2b | La fiche sort dans l'export des données, part à l'effacement du club et à la purge de rétention | `RgpdExportCompletenessTest`, `PurgeCompletenessTest` (bloquant), `PurgeSeasonsCommandTest`, un cas |
| 15 | 2b | Tests à réécrire, non mécaniques : `SeasonTransitionServiceTest.php:178`, `:229`, `:354`, `:406`, `:444` (recherche d'un coach par son prénom) ; contextes Behat qui écrivent `coach` en SQL brut (`SoclePlansContext`, `CoachLateRelayContext`, `PlacedPersonConflictContext` ; à relire : `ConflictResolutionContext`, `MemberReadOnlyContext`, `TenantIsolationContext`) | Travail de `tank`, avant `neo` |
| 16 | 3 | Après effacement, ni le nom, ni le mail, ni le téléphone, ni le commentaire de la personne ne figurent dans aucune colonne de la base | `backend/features/effacer-une-personne-efface-tout.feature` (neuf), décor fait par les vrais gestes : générer le planning, poser une indisponibilité de coach, répondre à une collecte avec un commentaire, signaler le planning ; puis « Quand le support efface la personne « Camille Durand » » / « Alors plus aucune colonne de la base ne contient son nom, son mail, son téléphone ni son commentaire » / « Et ses séances restent, sans coach » / « Et la collecte ouverte désigne le coach suivant ». Exemples : club réel ; club de démonstration sous horloge simulée, collecte envoyée. **Consigne : le balayage lit toutes les colonnes texte et JSON du schéma par la connexion d'administration** (`BaseContext::dbalScalar(…, admin: true)`, `BaseContext.php:82-88`) ; par la connexion par défaut, soumise à la sécurité par club, il serait vert sans rien vérifier |
| 17 | 3 | Même promesse, là où aucun geste ne mène vite : signalement né d'une génération en échec ; récapitulatif de fin de collecte dans la boîte de démonstration (parti par tâche planifiée) ; « Charger » une version d'avant l'effacement ne ramène ni le nom ni le coach (abus) | `tests/Security/PersonErasureLeavesNoTraceTest.php` (bloquant). Son décor vient du relevé du §2 : il ne prouve que le relevé ; la ligne 16 prouve par les gestes |
| 18 | 3 | Les PDF stockés du club sont supprimés, leur adresse remise à vide, et ils se refabriquent ; l'empreinte des versions réécrites reste alignée ; un écart d'une autre équipe qui cite son nom reste lisible, nom remplacé (ceux qui portent l'identifiant du coach partent par la cascade, `CascadePlan.php:152`) | `tests/Integration/Structure/PersonEraserTest.php` |
| 19 | 3 | Trace d'audit en identifiants seuls ; un coach d'un autre club est « introuvable » ; le signalement d'un autre club n'est pas touché (abus) | `tests/Integration/Command/ErasePersonCommandTest.php` |
| 20 | 4 | La saison suivante retrouve les mêmes lieux ; corriger une adresse vaut pour les deux saisons | Feature de la saison suivante : « Et les 3 gymnases de la saison suivante sont aux mêmes lieux » ; « Quand je corrige l'adresse du « Gymnase Nord » dans la saison suivante » / « Alors le « Gymnase Nord » de la saison en cours a cette adresse » |
| 21 | 4 | Dissocier un gymnase de sa salle fédérale dans la saison suivante ne touche pas la saison en cours (décision 2, A) | Même feature : « Quand je dissocie le « Gymnase Nord » de sa salle fédérale dans la saison suivante » / « Alors le « Gymnase Nord » de la saison en cours garde son adresse et ses temps de trajet » |
| 22 | 4 | « Charger » ramène un gymnase supprimé avec son adresse | `StructureRestorerTest`, un cas |
| 23 | 4 | La conversion crée un lieu par gymnase, adresse et position égales ; colonnes supprimées | `tests/Integration/Migration/PlaceConversionMigrationTest.php` |
| 24 | 4 | Isolation et purge des lieux ; l'import du parc crée un gymnase avec son lieu, sans adresse ; export des matchs inchangé | `PersonPlaceTenantIsolationTest`, cas « lieu » ; `PurgeCompletenessTest` ; `l-import-du-parc-en-un-geste.feature` et `l-export-des-matchs-pour-les-parents.feature` inchangés |
| 25 | 4 | Tests à réécrire, non mécaniques : contextes Behat qui écrivent `venue` en SQL brut (à relire par `tank`) | Travail de `tank` |
| 26 | 5 | La demande au moteur est identique, ordre des couples compris | Références 9 et 10 inchangées (bloquant) ; `VenueTravelTimePayloadParityTest` |
| 27 | 5 | L'API rend chaque couple rangé selon les gymnases de la saison demandée | `tests/Api/VenueTravelTimeApiTest.php`, cas à deux saisons au couple inversé |
| 28 | 5 | Un temps de trajet vaut pour toutes les saisons, sans recopie | Feature de la saison suivante : « Et le temps de trajet entre « Gymnase Nord » et « Gymnase Sud » est le même dans les deux saisons » ; « Quand je le corrige dans la saison suivante » / « Alors il est corrigé dans la saison en cours » |
| 29 | 5 | Supprimer un gymnase n'annonce plus de barème et n'en supprime plus | `supprimer-emporte-ce-qui-en-depend.feature`, une étape ; `DeletionImpactParityTest` (bloquant) |
| 30 | 5 | Une valeur saisie à la main survit au calcul automatique ; pas d'écriture depuis une saison en lecture seule ni vers le gymnase d'un autre club ; un temps ne peut pas relier les lieux de deux clubs (abus) | `VenueTravelTimeAutofillTest`, `VenueTravelTimeApiTest`, `PersonPlaceTenantIsolationTest` |
| 31 | 5 | La conversion garde chaque couple, ses minutes et leur origine ; l'ancienne table n'existe plus | `tests/Integration/Migration/TravelTimeConversionMigrationTest.php` |
| 32 | 5 | Tests à réécrire, non mécaniques : `PlacedPersonConflictContext.php:334-345` et `CoachLateRelayContext.php:95-106` (trajets en SQL brut) ; `SeasonTransitionServiceTest.php:160-167`, `:583-590` | Travail de `tank` ; `une-personne-est-signalee-a-deux-endroits.feature` et `le-relais-de-l-adjoint-tolere-un-retard.feature` restent verts |

Restent verts sans modification : les trois `*ContractSchemaTest`, `une-contrainte-saisie-est-honoree.feature`.
**Preuve = captures (`switch`)** : aucune.

## 8. Carte des conséquences

| Comportement | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Identité d'un coach | `CoachStateProcessor.php:63-134`, seeds, copie de saison, restauration | 18 fichiers par l'objet, `ClockedClubMailInterceptor.php:173` en SQL, la copie générique de la photo | Une copie par saison | Une fiche au club, lue par `PersonDirectory` seul ; RLS et clé composée ; saisons passées comprises (décision 7) |
| Boîte d'un club à horloge simulée | `ClockedClubMailInterceptor.php:192-200` ; récapitulatif `CoachWishDigestCommand.php:217` | Les membres du club de démonstration | Liste des adresses lue dans `coach` | Lue dans `person` ; à l'effacement, messages adressés à la personne supprimés, nom remplacé dans les autres |
| Version servie par `/api/coaches` | `AbstractStateProcessor.php:353-371` | Écran (`If-Match`) | Version de la ligne | Idem dans une saison. Entre deux saisons : le dernier écrit gagne (limite 5) |
| Empreinte d'un plan | `ScheduleConstraintBuilder::structureHash` `:197` | Bandeau « périmé » | Une correction périme les versions non validées de sa saison | De toutes les saisons du club, pour un nom, une adresse ou un temps de trajet (cible §4, §13.3) |
| Cache des demandes | Constructeur `:260-268`, `:325-336` | Les trois guichets | Purge par club | Idem, clé par époque |
| Photo | `StructureSnapshotter.php:136-150` | « Charger » | Identité complète copiée | Identifiant de personne et de lieu seulement |
| « Charger » | `StructureRestorer.php:93-130`, `:272-273` | Le gestionnaire | Ramène un coach supprimé ; supprime un coach ajouté depuis | Idem ; la fiche du coach retiré reste (décision 1) ; une personne effacée ne revient pas |
| Suppression d'un coach | `CoachStateProcessor.php:39-51`, `CascadePlan.php:147-168` | `DeletionImpactCounter.php:80-96`, écran | Aucune | Idem ; la fiche suit la décision 1 |
| Effacement d'une personne | `PersonEraser` (neuf) | Export des données, écrans des deux saisons, console des signalements | N'existe pas | Tout le relevé du §2 sauf `constraint_conflict` ; lignes 16 et 17 |
| Signalement | `FeedbackController.php:224-232`, `FeedbackContextEnricher.php:385-387` | Console d'administration, export des données | Garde la demande au moteur, noms compris, jusqu'à 30 jours après « traité » | Idem ; nom remplacé à l'effacement |
| Demande figée d'une version | `GenerateScheduleHandler` | `ScheduleResource.php:237` (équipes), les signalements | Garde prénom, nom, noms de règles | Idem ; nom remplacé à l'effacement, empreinte réalignée |
| PDF stockés | `ExportPdfHandler.php:123-125` | Téléchargement authentifié, `ScheduleResource.php:235-236` | Purge à 90 jours, sauf l'épinglé | Supprimés à l'effacement, adresses remises à vide |
| Purge de saison, remise à zéro, effacement du club | `SeasonDataPurger.php:203`, `:213-214` ; `ErasedClubPurger.php:67-98` | `PurgeCompletenessTest` | Tout part avec la saison | Rôles et gymnases avec la saison ; fiches et lieux sans usage balayés |
| Adresse et position | `VenueStateProcessor.php:70-84`, `:112-147` | 7 fichiers, écran | Par saison | Un lieu au club ; un changement de position recalcule pour toutes les saisons |
| Dissocier, puis « Annuler » | `VenueStateProcessor.php:116-120`, `:143-153` ; `VenuesStep.tsx:510-525` | Écran (`texts.ts:191`) | Vide tout pour la saison ; « Annuler » rend numéro, adresse, position, et relance le calcul des temps | Décision 2 (A) |
| Temps de trajet | Processeur, calcul automatique, copie de saison | Constructeur `:320`, `:539` ; deux détecteurs de conflit de personne ; écran | Par saison ; part avec le gymnase | Au club ; part avec le lieu ; rangé à la sortie selon les gymnases de la saison |
| Fenêtre de suppression d'un gymnase | `CascadePlan.php:97-125` | `delete-confirm.tsx` | Annonce « N barèmes de trajet » | Ligne absente |
| Export des données du club | `RgpdExportService.php:134` | Le gestionnaire | Identité dans `coach` | Identité dans `person` ; aucune colonne morte |

## 9. Parcours de bout en bout

- **Corriger un téléphone.** Wizard, étape Coachs, 2027-2028 : modifier le téléphone de Paul. En 2026-2027, Paul a le nouveau numéro, et dans une saison passée où il figure aussi (décision 7). Inverse : remettre l'ancien. Saison passée : jamais modifiable directement, refus existant « Cette saison est archivée — elle est en lecture seule. ».
- **Retirer un coach de la saison suivante.** Corbeille de Paul en 2027-2028, confirmer : il reste en 2026-2027. Annuler : rien ne part.
- **Supprimer un coach puis charger.** Paul n'entraîne qu'en 2026-2027 ; corbeille ; puis « Charger » sur une version générée quand il était là : il revient sur sa fiche (décision 1, A). Variante « rôle dans l'autre saison » : il revient quelle que soit la décision. Variante « coach ajouté après la version » : « Charger » le retire de la saison, comme aujourd'hui.
- **Recréer un coach retiré.** « Ajouter un coach », mêmes prénom et nom : fiche neuve, distincte (limite 2).
- **Effacer une personne.** Aucun écran. Le support lance `app:people:erase` : Camille quitte toutes les saisons, ses séances restent sans coach, le commentaire de sa doléance disparaît, les signalements gardent leur contenu avec « Personne supprimée » à la place de son nom. Inverse : aucun.
- **Corriger une adresse.** Wizard, étape Gymnases, 2027-2028 : position et temps calculés suivent pour les deux saisons.
- **Dissocier, puis Annuler** (décision 2, A). Carte du Gymnase Nord, 2027-2028, « Dissocier « Gymnase Nord » de sa salle FFBB », confirmer : en 2027-2028 il n'a plus ni numéro, ni adresse, ni temps de trajet ; en 2026-2027 rien ne change. « Annuler » dans le message : numéro, adresse et position reviennent en 2027-2028, sur un lieu désormais distinct de celui de 2026-2027 ; les temps calculés reviennent (un vrai changement de position relance le calcul, `VenueStateProcessor.php:143-148`), les temps saisis à la main sont à refaire (`texts.ts:191`).
- **Corriger un temps de trajet.** Fenêtre des trajets, 2027-2028 : Nord–Sud à 15 min, valable aussi en 2026-2027. Saison passée : refusé.
- **Supprimer un gymnase.** La fenêtre n'annonce plus de barème. Variante match : un gymnase qui accueille un match reste refusé avant tout (`VenueStateProcessor.php:58-60`).

**Textes visibles qui changent** : la ligne « barème de trajet » / « barèmes de trajet » quitte la fenêtre de suppression d'un gymnase ; « Personne supprimée » remplace le nom d'une personne effacée dans les diagnostics des plannings déjà générés, dans les signalements et dans la boîte d'un club de démonstration. Aucun autre.

## 10. Preuve « demande au moteur identique »

1. **En CI, à chaque PR** : les dix références à l'octet, bloquantes. Aucune PR ne modifie un fichier de référence.
2. **Copie de production n° 2, avant la PR finale `lot/conception` → `main`** (procédure `lot-conception-filet.md` §7) : enregistrement avant, **toutes les migrations des lots rejouées ensemble**, enregistrement après le même jour, `diff -r` vide. La migration du lot 3 y est jouée à sa place dans l'ordre fixé par la procédure unique (§6). L'accord du fondateur est demandé le moment venu, comme pour le lot 1.
3. **Ce que le filet ne voit pas** : `/validate-assignments` et la fenêtre de placement libre ; preuve : `la-boucle-de-travail-du-gestionnaire.feature`, inchangée.

## 11. Décisions du fondateur (2026-11-21)

Les décisions 1 à 6 reprennent les questions posées au fondateur ; la 7 a été ajoutée à la validation. Le fondateur a retenu l'option A partout.

| # | Question, avec exemple | Choix |
|---|---|---|
| 1 | Paul n'entraîne qu'en 2026-2027. Vous le supprimez par erreur à l'étape Coachs, puis vous chargez une version du planning d'avant. Aujourd'hui Paul revient, nom et téléphone compris : c'est le seul moyen d'annuler. | **A.** Sa fiche reste au club, sans être visible, environ deux ans, jusqu'à la purge de cette saison ; « Charger » le ramène. Pour l'ôter avant : l'effacement à la demande. (Écarté : **B**, fiche supprimée tout de suite, « Charger » ramène ses équipes sans lui, il faut le ressaisir.) Raison : même garde qu'aujourd'hui, même règle que pour un gymnase |
| 2 | Vous préparez 2027-2028. Sur le Gymnase Nord vous cliquez « Dissocier de sa salle FFBB », puis, vous ravisant, « Annuler ». Aujourd'hui dissocier efface numéro fédéral, adresse, position et temps de trajet ; annuler rend numéro, adresse et position, les temps calculés reviennent, les temps saisis à la main sont à refaire. | **A.** Dissocier ne vaut que pour 2027-2028 ; 2026-2027 garde tout. Après « Annuler », le Gymnase Nord de 2027-2028 retrouve numéro, adresse et temps calculés, mais Amateo le tient pour un lieu distinct de celui de 2026-2027 : corriger l'adresse ou un temps de trajet dans une saison ne corrige plus l'autre, jusqu'à la purge de l'ancienne saison (limite 10). (Écartés : **B**, dissocier vaut partout, la saison en cours perd aussi adresse et temps de trajet ; **C**, dissocier ne retire que le numéro fédéral, ce qui aurait demandé une modification de l'écran Gymnases.) Raison : rien ne change à l'écran, la saison en cours est protégée |
| 3 | Paul est salarié en 2026-2027. En préparant 2027-2028 vous décochez « salarié » : il redevient bénévole. | **A.** La case vaut par saison : 2026-2027 ne bouge pas. (Écarté : **B**, la case suit la personne.) Raison : comportement actuel |
| 4 | Un ancien coach demande l'effacement de ses données. Aucun écran ne le permet, ni aujourd'hui ni dans cette livraison. À savoir : la fiche d'un coach qui n'apparaît plus que dans la saison passée ne se corrige pas non plus, elle s'efface seulement. | **A.** Vous nous le demandez, nous le faisons pour vous (commande de support) ; le bouton arrive avec l'écran « structure du club », au lot 6. (Écarté : **B**, un écran dès cette livraison.) |
| 5 | Camille est effacée ; elle entraînait les U13 en 2025-2026, saison passée. La conception prévoyait d'y laisser ses séances avec la mention « personne supprimée ». | **A.** Ses séances restent, affichées sans coach, comme pour un coach supprimé. (Écarté : **B**, la mention, qui aurait demandé de garder une trace anonyme de Camille dans la base et de nettoyer une à une ses règles, ses doléances et ses diagnostics, avec plus de risque d'en oublier.) |
| 6 | La mise en production se fait un soir, site fermé quelques minutes (durée à mesurer sur la copie de production), à la main, **une seule fois pour tout le lot conception**. Pendant la vérification, un rappel automatique par mail peut partir. Problème vu avant la réouverture : retour à l'état d'avant, aucune saisie perdue. Problème vu après : on corrige en avant, car revenir ferait perdre ce que les clubs ont saisi depuis. | **A.** Accepter : mise en production à la main, site fermé, correction en avant après réouverture. (Écarté : **B**, garder pendant une mise à jour une copie des anciens noms et téléphones pour pouvoir revenir en arrière plus tard ; une étape de plus, et ces données en double pendant ce temps.) |
| 7 | Camille change de numéro : le gestionnaire corrige son téléphone, ou l'adresse d'un gymnase, dans la saison en cours. Elle figure aussi dans une saison passée, archivée. | **A.** Une correction d'identité (nom d'un coach, adresse d'un gymnase) faite dans la saison en cours se voit aussi dans les saisons passées. Une saison passée n'est jamais modifiable directement : lecture seule, comme aujourd'hui, le module des matchs y est inactif. L'effacement RGPD prime : il retire le nom de l'archive (décision 5). |

## 12. Mesures de production à demander à `link`

Lecture seule, clubs de démonstration exclus, comptes et identifiants seulement. Avant la PR 2b, et la veille de la mise en production.

1. Nombre de saisons par club (attendu 1). **S'il n'est plus 1 : retour au fondateur.**
2. Coachs : total (attendu 52) ; mêmes prénom et nom dans un même club ; coachs sans nom de famille (limite 7).
3. Gymnases : total (attendu 18) ; mêmes nom et adresse dans un même club ; gymnases sans position.
4. Barèmes de trajet : total par club ; lignes dont un gymnase n'existe plus ; couples en double ou mal rangés.
5. Les 2 photos de versions : lignes de coach ou de gymnase dont l'identifiant n'existe plus. **Si non nul : retour au fondateur.**
6. Versions en attente ou en cours ; placements en cours (attendu 0).
7. Lignes de `constraint_conflict` (attendu 0). **Si non nul : retour au fondateur**, l'effacement ne traite pas cette table.

**Relevé du 2026-11-22** (`link`, lecture seule, démonstrations exclues) : 4 clubs réels ; 52 coachs ; 18 gymnases ; 1 saison par club ; 0 homonyme de coach ; 0 gymnase de même nom et même adresse ; 1 gymnase sans position ; 9 barèmes de trajet, aucun orphelin ni mal rangé ; photos : aucun identifiant disparu ; aucune version ni placement en cours ; `constraint_conflict` **non mesurable** (le rôle de lecture n'a pas de `GRANT` sur la table : traitée comme vide). **Hors attendu : 20 coachs sur 52 sans nom de famille.** Conséquence pour la PR 3 : l'effacement ne supprime ni ne remplace jamais par texte un nom réduit au prénom (limite 7).

## 13. Limites écrites

1. **Clubs de démonstration à deux saisons** : chaque coach y a deux fiches jusqu'à la prochaine remise à zéro de la démonstration.
2. **Recréer une personne retirée crée une seconde fiche** ; choisir une personne connue viendra avec l'écran « structure du club ».
3. **Aucun écran ne liste les personnes ni n'efface** avant le lot 6.
4. **Ce que l'effacement ne nettoie pas** : un texte libre saisi par le gestionnaire ailleurs que sur la fiche (une règle d'équipe nommée à la main, le message d'un signalement) ; la capture d'écran d'un signalement (purgée à 90 jours) ; les mails déjà partis aux gestionnaires et aux coachs (récapitulatifs de collecte, mails de conflits), qui sont hors de la base et ne se rappellent pas ; les sauvegardes jusqu'à leur rotation (`docs/security/rgpd.md` §4).
5. **Deux gestionnaires qui corrigent la même fiche depuis deux saisons** : le dernier écrit gagne.
6. **Un nom, une adresse ou un temps de trajet corrigés font paraître « périmées » les versions non validées de toutes les saisons du club.** Levé au lot 5.
7. **Un nom réduit à un seul mot** (« Paul », sans nom de famille) n'est JAMAIS remplacé par texte (il se retrouverait dans le nom d'une autre personne) : le remplacement par identifiant (ligne de ses rôles, nom de ses règles) vaut, le prénom seul reste dans les textes libres, les diagnostics, les signalements et la boîte de démonstration.
8. **`constraint_conflict` n'est pas traitée par l'effacement** : rien ne l'écrit (§2), la mesure 7 le vérifie.
9. **La preuve de l'effacement** : la ligne 16 prouve par les gestes ; la ligne 17 ne prouve que le relevé du §2, et deux cas ne sont joués que là : le signalement né d'une génération en échec, le récapitulatif de fin de collecte.
10. **Après « Dissocier » puis « Annuler »** (décision 2, option A) : deux lieux à la même adresse, sans geste pour les réunir, jusqu'à la purge de la saison la plus ancienne.
11. **Un gymnase supprimé puis recréé à la main** repart d'un lieu neuf, sans temps de trajet.
12. **Retour arrière** : décision 6. **`/validate-assignments` et la fenêtre de placement libre** n'ont aucun avant/après enregistré.
13. **Une correction d'identité vaut pour les saisons passées** (décision 7) : le nom d'un coach ou l'adresse d'un gymnase corrigés dans la saison en cours se lisent aussi dans l'archive ; l'archive n'est pas figée sur les valeurs d'alors, et aucune saison passée ne se modifie directement. L'effacement d'une personne retire quand même son nom de l'archive (décision 5, limite 4 pour ce qu'il ne nettoie pas).

## 14. Checklist de cadrage

- **Zones** : `backend/`, `docs/`, `specs/`, `.github/workflows/ci.yml` (step bloquant). `engine/`, `landing/` : aucun fichier. `frontend/` : aucun (l'option C de la décision 2 n'est pas retenue).
- **Dossiers interdits** : `engine/**`, `engine/CONTRACT_VERSION`, les trois `*ContractSchemaTest`, les fichiers de référence existants, `scripts/remote-deploy.sh`, `.github/workflows/deploy.yml`.
- **Aucun refactoring hors périmètre** : confirmé. Non touchés : table de rôles, numéro fédéral et libellés FBI, réglage de la règle de trajet, « Charger » au-delà de la règle « personne disparue », la cascade de suppression d'un coach, tout écran.
- **Axes §7.1 et NR** : *isolation* (1, 12, 13, 19, 24, 30) ; *chaîne de génération* (2, 3, 4, 11, 26) ; *sens des contraintes* (26) ; *cycle des plans* (9, 17, 22) ; *contrat avec le moteur* : non touché, 1.12 ; *périmètre engagé*, *auth et adhésions* : non touchés. Bloquants ajoutés à `ci.yml` et à `blocking-tests.md` dans la même PR : `PersonPlaceTenantIsolationTest` (PR 2b), `PersonErasureLeavesNoTraceTest` (PR 3). La ligne 16 est un scénario Behat : jouée sur la stack d'épreuve avant le merge vers la branche de lot, gardée par `functional-tests` à la PR finale.
- **`/security-review`** : les six PR.
- **Contre-revue** : `smith` passé trois fois ; rien de bloquant sur la v3.
- **Tests d'abus** : lignes 12, 13, 16, 17, 19, 30. Un Membre qui modifie un coach : `un-membre-lit-le-gestionnaire-ecrit.feature`, inchangé.
- **Behat touchés** : `preparer-la-saison-suivante-reprend-la-structure.feature`, `supprimer-emporte-ce-qui-en-depend.feature`, `effacer-une-personne-efface-tout.feature` (neuf), et les contextes des lignes 15, 25, 32. **Playwright : aucun.**
- **Feature qui prouve la zone** : `generation-du-planning-de-saison.feature`, `preparer-la-saison-suivante-reprend-la-structure.feature`.
- **Plafonds de temps** (`test-time-ceiling.json`, valeurs du 2026-11-21 : backend 1000 s, Behat 950 s) : estimations non mesurées, Behat +50 s (le scénario d'effacement joue une vraie génération, deux exemples), backend +35 s ; mesure par `make ci-mesure PART=origin/lot/conception` à chaque PR. Aucun relèvement demandé ; si Behat déborde, l'effacement devient des étapes en fin de `supprimer-emporte-ce-qui-en-depend.feature`, qui a déjà un planning généré, et l'exemple « démonstration » passe à la ligne 17.
- **Contrôle visuel** : aucun.
- **Documentation** : fiche du lot 3 (`lot-conception-decoupage.md`), `etat-des-lieux.md`, ADR neuf « Personne, rôle et lieu au club » et `adr-index.md`, `docs/security/rgpd.md` (registre, effacement d'une personne, signalements), `docs/security/rls.md`, `backend/docs/TENANT.md`, `backend/docs/backend-inventory.md`, `backend/docs/commands.md`, `backend/docs/geo-api.md`, `backend/docs/backend-demo.md`, `docs/glossary.md`, `lot-conception-filet.md`, `blocking-tests.md`, `test-coverage-map.md`, `backend/AGENTS.md` (points 3, 11 et 16). `openapi-snapshot.json` : la PR 1 seule. **`docs/ops/deploy.md` : aucune PR du lot 3** — la procédure unique de mise en production (ordre des migrations de tous les lots, vérifications, retour arrière, dérogation à la règle d'écriture des migrations) est écrite par la dernière PR avant la PR finale, relue par le fondateur ; le lot 3 lui fournit ses étapes (§6).
- **Conditions de retour au fondateur** : une mesure du §12 hors attendu ; un fichier de référence qui devrait changer ; un `diff -r` non vide sur la copie de production n° 2 ; la ligne 16 qui trouve une donnée hors du relevé du §2.

## 15. Tranché par le plan

`coach` reste et devient le rôle ; pas de table de rôles ; colonnes nues, personne et lieu obligatoires à la création ; une seule porte de lecture par objet, SQL brut compris ; la personne en deux PR (lecteurs, puis schéma) ; clés composées avec le club sur `coach`, `venue` et `place_travel_time` ; conversion sans fusion ; anciennes colonnes et ancienne table supprimées dans le lot ; « véhiculé » et le plafond de jours restent sur le rôle, le genre va à la personne ; numéro fédéral à la saison ; couple de trajet rangé par lieu en base et par gymnase à la sortie ; époque du cache = empreinte de la liste des migrations ; à l'effacement, une seule règle : remplacer par « Personne supprimée », boîte de démonstration comprise ; `constraint_conflict` non traitée ; PDF stockés supprimés et adresses remises à vide ; recréer une personne donne une fiche neuve ; accord pour la copie de production n° 2 demandé le moment venu ; **déploiement unique** : tout reste sur `lot/conception`, pas de branche `lot/personnes-lieux`, une seule mise en production à la fin ; une correction d'identité faite en saison en cours vaut aussi pour les saisons passées.

**Laissé hors périmètre, volontairement** : la règle de reconnaissance d'une même personne entre saisons, l'écran des personnes, le bouton d'effacement, le salarié non coach, le déplacement du numéro fédéral.

## Changements depuis v3

| Correction reçue | Section modifiée |
|---|---|
| X1 · le récapitulatif de fin de collecte cite les coachs dans la boîte du club | Retenu : traité, pas mis en limite. §2 (ligne « Boîte » du relevé), §4 PR 3 étape 6 (remplacement dans le sujet et le corps, avec la raison du choix), §5 (`rewrittenMailboxMessages`), §7 ligne 17 (cas neuf), §8, §9 (textes visibles), §13.7 et §13.9 |
| X2 · le balayage doit lire par la connexion d'administration | §2 (tests), §7 ligne 16 (consigne à `tank`) |
| X3 · « Dissocier puis Annuler » : les temps calculés reviennent, les temps saisis à la main sont à refaire | §2, §8, §9, décision 2 |
| X4 · ligne 18 | §7 ligne 18 : « un écart d'une autre équipe qui cite son nom reste lisible, nom remplacé » |
| X5 · mails déjà partis | §13.4 |
| Reformulations du §11 | Décision 2 (option C : « une modification de l'écran Gymnases en plus » ; recommandation A : « quand 2026-2027 sera purgée »), décision 4 (« nous le faisons pour vous », « cette livraison »), décision 5 option B (« garder une trace anonyme de Camille dans la base »), décision 6 option B (texte fourni) ; plus aucun mot de jargon dans les six décisions de la v3 |

Aucune décision de la v3 retirée. Aucun autre point rouvert. Aucune correction laissée sans suite.

**Après la v4, à la validation du fondateur (2026-11-21)** : les six décisions retenues en A ; une septième (une correction d'identité vaut aussi pour les saisons passées, §11 et §13.13) ; le **déploiement unique** du lot conception (§0, §4, §6, §10, §14, §15), qui supprime la branche `lot/personnes-lieux`, l'attente d'une release du lot 1 et la procédure de mise en production propre au lot 3.

## Fichiers à relire en premier

- `specs/evolution/lot-conception-decoupage.md`
- `specs/evolution/conception-cible.md`
- `backend/AGENTS.md`
- `backend/src/Entity/Coach.php`
- `backend/src/Entity/Venue.php`
- `backend/src/Entity/VenueTravelTime.php`
- `backend/src/Entity/CoachWish.php`
- `backend/src/Entity/ConstraintConflict.php`
- `backend/src/Entity/ClubMailboxMessage.php`
- `backend/src/EventListener/ClockedClubMailInterceptor.php`
- `backend/src/Command/CoachWishDigestCommand.php`
- `backend/src/Controller/FeedbackController.php`
- `backend/src/Service/FeedbackContextEnricher.php`
- `backend/src/Service/SeasonTransitionService.php`
- `backend/src/Service/ScheduleConstraintBuilder.php`
- `backend/src/Service/StructureRestorer.php`
- `backend/src/Service/RgpdExportService.php`
- `backend/src/State/Processor/VenueStateProcessor.php`
- `backend/src/Deletion/CascadePlan.php`
- `backend/tests/Behat/BaseContext.php`
- `frontend/src/features/wizard/steps/VenuesStep.tsx`
- `scripts/remote-deploy.sh`
- `.github/workflows/deploy.yml`
- `docs/ops/deploy.md`
