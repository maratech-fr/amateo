# Lot 1 · Les déclarations communes : plan d'exécution (version allégée)

**Statut : version finale (v4 sur 4) ; plafond atteint. Plan validé par le fondateur le 2026-11-21, dans sa version allégée (« tout reco »).**
Voie **Full**. Branche cible : `lot/conception`. Exécutant : `neo`, après les rouges de `tank`.
La fiche du lot (contenu, ce qu'il supprime, dépendances) est dans
[`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 1 ; ce fichier dit **comment**.
Besoin : décision fondateur du 2026-11-20 (même fiche, §5). Pas de section « Signaux » d'`oracle` :
les chemins d'abus sont ceux du §10.

L'**exécution commence par la PR 1**, une fois ses rouges écrits par `tank`.

## 0. Consignes d'exécution

1. **Une seule PR ouverte à la fois sur `lot/conception`.** Le lot 2 et le lot 1 modifient les mêmes
   fichiers de suivi (`lot-conception-decoupage.md`, `etat-des-lieux.md`) : avant d'ouvrir,
   `git diff --name-only origin/lot/conception...HEAD` contre `gh pr diff <n> --name-only` des PR
   ouvertes sur la même base.
2. **Le lot 1 est livré sur `lot/conception`** (non déployé : une seule mise en production, à la fin) :
   PR 1, 2, 3, 4a, 4b et 5 ; la PR 5 clôt le lot. Le lot 2 avait livré sa PR 5 (module matchs, régime de
   démonstration) avant l'ouverture des PR 1, 3 et 4a.
3. **Ce que les lots 0 et 2 ont déjà livré** (lu dans le dépôt le 2026-11-21) :
   - Lot 0 : `EngineRequestComposer` (un seul compositeur, lectures triées par id) ; six demandes de
     référence dans `backend/tests/Fixtures/engine-requests/` (socle 1 et 2, période 3 et 4,
     placement 5 et 6) ; `EngineRequestReferenceTest` existe mais **n'est ni dans le step
     `Blocking suite` de `ci.yml` ni dans `docs/testing/blocking-tests.md`** (la PR 1 l'y met) ;
     `app:engine-requests:record` (procédure de copie de production :
     [`lot-conception-filet.md`](lot-conception-filet.md)) ; sept features Behat de comportement
     actuel.
   - Lot 2 : rôles `admin`/`member` (colonne fermée) ; 1er mai côté serveur
     (`SeasonTransitionService::preparationOpensOn`, 409 « La saison suivante se prépare à partir du
     1er mai. », garde de date avant le test du successeur) ; déclarations d'accès
     (`backend/src/Access/`, `#[ClubFunction]`, `#[ClubResource]`, `#[AccountFunction]`) et
     `AccessDeclarationSweepTest` ; contrôle central `ClubAccessListener` ; crédits pris à
     l'obtention. **Le lot 1 pose donc `#[Family]` à côté de `#[ClubResource]`**, et toute route
     retirée ou ajoutée (`POST /api/seasons`, `GET /api/team_tag_assignments/{id}`) doit rester
     cohérente avec le balayage d'accès.
4. **Les `fichier:ligne` de ce plan** ont été revérifiés dans le code le 2026-11-21 pour les
   fichiers que les lots 0 et 2 ont modifiés ; les lignes corrigées le sont ci-dessous, celles qui
   n'ont pas pu l'être portent « à relire ». Un fichier non modifié depuis le plan garde ses
   numéros. Les comptes (« 25 sites », « 87 colonnes »…) sont des constats datés du plan : le
   balayage de la PR 4a et `grep` font foi, pas le chiffre.
5. **Tout ce qui bouge un contrat d'API** (`openapi-snapshot.json`) se régénère dans la PR concernée.

## 1. Besoin reformulé

Trois rangements qui ne doivent rien changer pour le gestionnaire, quatre constats du lot 0 à
trancher, une condition d'entrée :

1. **Familles d'objets** : ce qui doit suivre un objet se déclare une fois ; un test rougit sur toute
   famille non déclarée.
2. **Créneau de semaine** : un seul objet, jour de 1 à 7, un seul endroit pour le chevauchement et
   les conversions.
3. **Étiquettes** : calculées à la lecture ; les deux tables disparaissent à la première PR du lot 3, dans la
   **même mise en production** (§4).
4. **Constats** : gymnase supprimé et règle qui le nomme ; message anglais ; drapeau démo (fermé par
   le lot 2) ; `POST /api/seasons`.
5. **Condition d'entrée** : purge de `cache.schedule` automatisée au déploiement.

## 2. Constats vérifiés dans le code

### 2.0 Vérifications du 2026-11-21 qui motivent l'allègement

Le plan d'origine corrigeait aussi « Charger » (une version ne touche plus les catégories du club).
Cette partie est **retirée du lot 1** (elle retourne au lot 5) pour ces raisons, toutes lues dans le
code :

- **Les catégories naissent du catalogue à l'inscription** : douze lignes, `ClubProvisioner.php:127-138`
  boucle sur `CategoryCatalog::categories()` (`Service/Basketball/CategoryCatalog.php:37-48`), et de
  deux imports (parc d'équipes : `Service/ParcImport/ParcImportExecutor.php`, via
  `Service/SportCategoryResolver.php:52` ; FBI Excel : `Service/Basketball/FfbbExcelImporter.php`).
  **Aucun écran ne crée, ne renomme ni ne change l'âge d'une catégorie** : l'écran des durées de match
  ne change que les durées (`frontend/src/features/matches/api/teams.ts:89`) ; seul
  `PUT|POST /api/sport_categories` (`SportCategoryStateProcessor.php`) le permet, sans écran.
- **« Charger » ne se fait que dans sa propre saison** (`RegenerateFromVersionController.php:108`,
  `$seasonId = $source->getSeasonId()`), le contrôleur est un `SeasonScopedWriteInterface` (refus sur
  une saison archivée) et **la copie de saison ne copie aucune version**
  (`SeasonTransitionService.php`, docblock de classe : « never a generated plan »). La fenêtre où
  « Charger » en 2026-2027 réécrit des catégories vues par 2027-2028 existe donc, mais elle exige un
  import d'équipes dans la saison suivante entre la génération et le chargement.
- Mesure en production (§13) : aucune catégorie personnalisée portant un âge, aucune photo désignant
  une catégorie absente, aucun cas ancien à réparer.

### 2.1 Inventaire des listes tenues à la main, et leur sort

| # | Liste | Où | Familles | Sort après ce lot |
|---|---|---|---|---|
| E1 | Purge de saison, par le parent | `Service/SeasonDataPurger.php:112-124` | 5 | **Survit, lit la déclaration** |
| E2 | Purge de saison, par club+saison (+ `:76-79`, `:87-102`) | `SeasonDataPurger.php:133-215` | 37 | **Survit, lit la déclaration** |
| E3 | Effacement du club (+ `:55-58`) | `Service/ErasedClubPurger.php:67-98` | 9 | **Survit, lit la déclaration** |
| E4 | Suppression d'une équipe, d'un gymnase, d'un créneau, d'un coach | `Deletion/CascadePlan.php:47`, `:97`, `:138`, `:147` | 22 + 12 + 2 + 9 étapes | **Survit**, remodelée au lot 6 |
| E5 | Effacement avant « Charger » (+ `:48-59`, `:154-214`) | `Service/StructureRestorer.php:259-275` | 10 | **Meurt au lot 5** (`lot-conception-decoupage.md`, fiche du lot 5) ; **non modifiée dans ce lot** |
| E6 | Réglages d'un plan de période ; artefacts d'une version | `Service/OverlayManager.php:110` ; `:215-236` | 9 ; 4 | Réécrite au lot 4 |
| E7 | Collecte des vacances à venir | `Service/FutureHolidayCollectePurger.php:85` | 3 | **Meurt au lot 5** |
| C1 | Copie vers la saison suivante (code pas à pas) | `Service/SeasonTransitionService.php:166-620` | 16 | Survit, réécrite aux lots 3, 4, 5 |
| C2 | Photo d'une version | `Service/StructureSnapshotter.php:52-63` | 10 | **Meurt au lot 5** ; **non modifiée dans ce lot** |
| C3 | Copie d'un plan frère (code pas à pas) | `Service/PeriodPlanSiblingCopier.php:171-240` | 9 | Décidé au lot 4 |
| C4 | Copie à la naissance d'un plan | `Service/SchedulePlanProvisioner.php:1270`, `:1296`, `:1329` | 3 | Lot 4 |
| C5 | Transcription depuis le socle | `Service/PeriodPlanTranscriber.php:137` | non compté | Lot 4 |
| — | Ancre de période par entité | `Service/StructureAnchor.php:49-72` | 3 | **Meurt au lot 4** |

Les lignes de ce tableau hors `SeasonTransitionService.php` n'ont pas été touchées par les lots 0 et 2
(à relire en cas de doute). Divergence déjà visible : `groupLabel` d'un créneau est copié par C3
(`PeriodPlanSiblingCopier.php:189`) et pas par C1 (`SeasonTransitionService.php:232-240`, à relire).

**Clés étrangères réelles** : peu de clauses `REFERENCES` dans les migrations (certaines sont des
retours arrière). Aucune vers `sport_category`, aucune sur `team_tag_assignment`
(`migrations/Version20260615010708.php:25`). Le graphe a un cycle : un plan désigne une version
(`ON DELETE SET NULL`, `migrations/Version20261010170000.php:35`) et une version désigne son plan.

**Colonnes d'identifiant** : la grande majorité des colonnes `guid` sont `id`, `clubId`, `seasonId` ;
les autres désignent un autre objet (le balayage de la PR 4a les dénombre).

**Références cachées dans du JSON** : clés de gymnase d'une règle (`ConstraintConfigValidator.php:82-85`),
`CoachWishMutualization.partnerTeamIds` (`:74`), `ConflictMailing.recipientCoachIds` (`:50`),
`Season.transitionData` (écrit par `SeasonTransitionService.php:589-596`), la photo et la demande
figée d'une version.

### 2.2 Étiquettes et catégories

- **Écrivain unique des étiquettes** : `EventListener/TeamTagSyncListener.php:15-18` →
  `TeamTagService::syncTeamTags` (`:59-133`). Il entend la création et la modification d'une équipe, la
  modification d'une catégorie (`:17`), jamais sa création ni sa suppression.
- **La règle** : `TeamTagService::determineTagNames` (`:428-485`). Catalogue fixe de 24 noms
  (`:264-294`).
- **La frontière de saison est une règle écrite** : `TeamTagSyncListener.php:129-150` ;
  `Command/ResyncTeamTagsCommand.php:31-32`, `:98-110` (équipes existantes des saisons actives et en
  préparation seulement). La catégorie appartient au club, sans saison
  (`SeasonTransitionService.php:310-311`).
- **Qui modifie les catégories d'un club** :
  - **« Charger » une version (bouton)** : la photo prend toutes les catégories du club, sans saison
    (`StructureSnapshotter.php:118-124`) ; la restauration les efface toutes, sans condition
    (`StructureRestorer.php:274`), puis réinsère ce que la photo contient (`:104-118`). Catégories créées
    depuis : disparues. Catégories modifiées depuis : remises comme alors, durées de match comprises
    (`StructureSnapshotter.php:136-150`). Code lu, non joué. **Non corrigé dans ce lot** (limite 2, §14).
  - **L'import d'équipes (bouton)** crée une catégorie absente (`ParcImport/ParcImportExecutor.php:1185-1193`,
    `frontend/src/features/wizard/queries.ts:129-131`).
  - **L'écran des durées de match** ne change ni nom ni âges (`frontend/src/features/matches/api/teams.ts:87-90`).
  - **`PUT /api/sport_categories/{id}`** accepte nom et âges (`SportCategoryStateProcessor.php:76-87`),
    sans écran.
  - Aucune route ne supprime une catégorie (`SportCategoryResource.php:23`, « DELETE retiré »).
- **Une équipe peut désigner une catégorie absente** : `Team.sportCategoryId` est une simple colonne
  (`Entity/Team.php:46`) ; à la construction de la demande, la recherche rend alors rien
  (`ScheduleConstraintBuilder.php:1416`) et l'équipe n'a aucun groupe d'âge, sans message.
- **Une résolution vide est muette** : `TeamTagResolver.php:241-251` (liste vide, avertissement au
  journal), `ScheduleConstraintBuilder.php` (la règle est sautée, la génération aboutit : bloc « Tag
  targeting … resolves to no team », vers `:1651-1676`, à relire).
- **Un rattachement orphelin compte** : l'ancien `tagTeamIds` rend l'équipe de tout rattachement, sans
  vérifier qu'elle existe (`TeamTagResolver.php:253-256`), et le constructeur émet une ligne de règle
  pour elle.
- **Empreinte** : `ScheduleConstraintBuilder::structureHash` (`:197`), calculée sur la demande dont les
  règles de groupe sont éclatées par équipe. Un planning validé ne porte aucun bandeau
  (`frontend/src/features/planning/lib/staleness.ts:15-17`, `:29-31`) ; une version non validée
  affiche « …vos données ont changé : il est périmé… » (`:49`).
- **Lecteurs** : `TeamTagResolver::tagTeamIds` (`:235-260`) pour le constructeur, le sélecteur de période
  (`PeriodConstraintSelector.php:148`), la saisie d'une règle (`ConstraintStateProcessor.php:319-333`) ;
  `TeamTagService::isYouthTeam` (`FfbbEngagementsController.php:172`, `:338` : vérifiés) ; l'écran
  (`frontend/src/features/wizard/api.ts:790`, `:801`).
- **Dépendants** : photo et restauration (`StructureSnapshotter.php:62`, `StructureRestorer.php:58`,
  `:265`), purges (`SeasonDataPurger.php:251-256`, `ErasedClubPurger.php:76`), suppression d'équipe
  (`CascadePlan.php:78`, ligne affichée par `delete-confirm.tsx:89-91`), seed
  (`Seed/BcclSeeder.php:1141-1148` : vérifié), balayage d'accès
  (`tests/Security/AccessDeclarationSweepTest.php:233` et `:708` — **les anciens `:227` et `:646-650` ont
  bougé**), étalon RLS (`tests/Security/RlsIsolationTest.php:142-150` : vérifié), contrat d'API figé
  (`specs/courantes/openapi-snapshot.json`, `OpenApiSnapshotMatchesTheLiveContractTest`,
  `TsFieldsMatchOpenApiSchemaTest`).
- **Identifiant d'une étiquette** : clé de jointure côté écran (`shared/lib/tagTeamIds.ts:37-46`,
  `ConstraintsStep.tsx:234`) ; côté serveur, seul `AbstractStateProvider::provideItem` (`:141-148`) le
  passe à une colonne `guid`.

### 2.3 Créneau de semaine

- Triplet jour + début + durée dans `VenueTrainingSlot.php:41-47`, `Reservation.php:86-92`,
  `VenueOccupancy.php:56-62`, `ScheduleSlotTemplate.php:60-66`.
- **Heure vers minutes : environ 25 sites dans 18 fichiers** (compté le 2026-11-21). **Chevauchement : 8
  copies**, demi-ouvertes (`VenueGridOverlap.php:65-73`, `VenueWindowOccupancy.php:51-54`,
  `MoveSlotService.php:1352-1358`, `CoachDoubleBookingDetector.php:473-478`,
  `TeamCaseOccupancyGuard.php:166-171`, `SecondSessionSameDayDetector.php:166`,
  `ParcImportExecutor.php:1108`, `ParcImportAnalyzer.php:835`). **Minuit : 4**
  (`GroupReservationController.php:120` : vérifié, `VenueTrainingSlotStateProcessor.php:239`,
  `VenueOccupancyStateProcessor.php:121`, `ReservationStateProcessor.php:225` : vérifié).
- **Seule numérotation 0 à 6** : `Team.matchDay` (`Entity/Team.php:81-82`, `Dto/TeamInput.php:50-53` :
  vérifié), lue par le constructeur : `ScheduleConstraintBuilder.php:1437` (émission) et
  **`deriveMatchDay`, `:1464-1484`, repli `$declared + 1` en `:1481-1484`** (les anciennes lignes
  `:1443-1484` et `:1478-1483` ont bougé avec le lot 0). Ni l'écran ni un seed ne l'écrivent.
- **La photo copie cette colonne**, lue avant le calcul et enregistrée après
  (`StructureSnapshotter.php:32-38`, `:52-63`) ; « Charger » la réécrit telle quelle
  (`StructureRestorer.php:303-316`).
- **Tests qui figent la règle actuelle** : `tests/CrossStack/HabitPayloadParityTest.php:130-143`,
  `tests/Validator/TeamInputValidationTest.php:26`, `:46-50`, `tests/Security/EngagedTeamGuardTest.php:201`,
  `:229`, `tests/Integration/Command/PruneUnreadSnapshotFieldsCommandTest.php:146` (à relire).

### 2.4 Les quatre constats

- **Gymnase** : `ScopedConstraintStep.php:36-50` ne compare que portée et cible. Deux listes de clés de
  gymnase : `ScheduleConstraintBuilder::VENUE_CONFIG_KEYS` (`:50`, cinq clés dont `setVenueId`, lue en
  `:589`) et `ConstraintConfigValidator::uuidKeys()` (`:82-85`, `:200-213`, quatre clés) ; `setVenueId` est
  dit fantôme (`:193-194`). Le gymnase imposé sur la fiche de l'équipe est vidé à la suppression
  (`CascadePlan.php:117`). Effet d'une règle orpheline, écrit dans le code et non rejoué : l'équipe sort
  du planning (`ConstraintStateProcessor.php:337-349`).
- **Message anglais** : `SeasonAlreadyTransitionedException.php:19` (vérifié) ; l'écran affiche déjà
  « La saison suivante existe déjà — bascule dessus. » (`frontend/src/app/SeasonSelector.tsx:98-106`). Le
  contrôleur renvoie `error` = ce message et `existingSeasonId` (`SeasonTransitionController.php`).
- **Drapeau démo** : deux contrôles sur celui qui agit (`ClubInvitationController.php:79`, `:145` :
  vérifiés), un qui protège l'invité (`:108`), un qui protège le compte (`DeleteAccountController.php:49`) ;
  deux lisent le club (`ConflictCoachMailController.php:202`, `CoachWishCampaignActionController.php:92` :
  vérifiés). **Fermé par la PR 5 du lot 2** (régime de démonstration déclaré une fois) : rien à faire ici.
- **`POST /api/seasons`** : exposé (`SeasonResource.php:27`, vérifié), n'exige que deux dates
  (`SeasonStateProcessor.php:65-70`), appelé par aucun écran et par deux tests
  (`tests/Security/ManagementRoleTest.php:138`, `tests/Security/MemberWriteSweepTest.php:121`, vérifiés) ;
  le commentaire `SeasonResource.php:22-23` est faux. La garde de date du lot 2 est dans
  `SeasonTransitionService::transition` (`:78`) ; la route de création ne passe pas par elle.

### 2.5 Déploiement, cache et preuve

- `scripts/remote-deploy.sh` : migration `:79-81`, `up -d --wait` `:83-84`, sondes `:102-107`, version
  écrite `:109-117`, « Deploy … OK » `:119` ; les étapes d'après-succès finissent par `|| echo …`
  (`:128-129`, `:142-143`). Script non modifié par les lots 0 et 2.
- **Deux lecteurs du code retour** dans `.github/workflows/deploy.yml` : levée de la maintenance si
  succès (étape « Clear the maintenance window », `if: success()`, vers `:462`) ; recalage de `release`
  si succès (job `update-release-branch`, vers `:494`) — numéros à relire, le fichier est stable.
- Cache : Redis, 4 h (`ScheduleConstraintBuilder::CACHE_TTL_SECONDS`), invalidé par club à toute écriture
  ORM (`CacheInvalidationListener.php:90-125`). **La clé porte déjà la version du contrat** (`cacheKey`,
  `ScheduleConstraintBuilder.php:160-163`), pas une époque de données.
- **Les demandes de référence d'origine ne voient rien de ce que le lot déplace**
  (`tests/EngineRequestDecorTrait.php` : ni `targetTag`, ni `setMatchDay`, ni `TeamTag`).
  `EngineRequestReferenceTest` n'est pas bloquant ; « Lot Gate » ne joue que la suite bloquante.
- **Sur les clubs seedés, la comparaison avant/après ne prouve rien pour les PR 2 et 3.**
- **Déploiement unique** (décision du fondateur, 2026-11-21) : tout le lot conception part en **une seule mise en
  production**, à la fin, depuis `lot/conception`, sur GO du fondateur ; aucune release du lot 1 isolée. Mise en
  production manuelle, site fermé, une seule procédure pour tous les lots, écrite dans `docs/ops/deploy.md` par la
  dernière PR avant la PR finale ; retour arrière = restauration de la sauvegarde d'avant, correction en avant après
  réouverture (plan du lot 3, §6). On ne fait jamais un `down()` (`docs/ops/migration-rollback-drill.md:63`).
- **Lecture de la production** : un club par appel (`scripts/prod-read.sh:46-55`), clé déverrouillée par
  le fondateur (`:34-36`), sécurité par club sans porte globale (`docs/security/rls.md:78-82`).
- **« Charger » se joue déjà en Behat** sur un petit club avec une génération :
  `tests/Behat/CoachWishesContext.php:392-396`, `:445-451` (`POST /api/schedules/{id}/regenerate-from`).

## 3. Conception : les cinq réponses par objet

### 3.1 La déclaration d'une famille

1. **Le plus simple** : deux attributs PHP et un service de lecture. `#[Family]` dit le niveau et
   pourquoi une purge épargne la famille. `#[RefersTo]` se pose sur les colonnes qui désignent un autre
   objet. **On ne déclare que ce qu'une purge lit dans ce lot** ; le reste entre avec le lot qui le lit.
2. **Supprime** : les constantes de E1, E2, E3. `CascadePlan` (E4) reste la maison des libellés et de
   l'ordre ; un test exige que toute colonne déclarée vers une équipe, un gymnase, un coach ou un créneau
   y figure.
3. **Un seul endroit** : semblables existants : `TenantOwnedInterface`, constantes des purgeurs,
   `CascadePlan`, attributs d'accès du lot 2 (`backend/src/Access/`).
4. **Suppose en dur** : une entité = une famille ; cinq niveaux ; une référence = une colonne `guid` (les
   références en JSON y échappent ; l'ADR les liste) ; `clubId` et `seasonId` se déduisent du niveau.
5. **Deuxième usage** : chaque lot suivant ajoute un paramètre avec son lecteur ; `#[RefersTo]` est la
   matière des clés étrangères des lots 3 et 4.

**Ordre de purge** : l'enfant avant le parent, en ne suivant que les références « la ligne meurt avec sa
cible ». Une référence « vidée » n'impose aucun ordre : c'est ce qui rompt le cycle plan ↔ version. Un
cycle entre références « meurt avec » fait rougir le balayage. À égalité, ordre alphabétique. Prouvé
contre les clés réelles.

Geste « vraies clés étrangères » : sans objet dans ce lot. Geste « ranger par domaine » : classes
neuves dans `backend/src/Structure/`, aucun fichier déplacé.

### 3.2 Le créneau de semaine

1. **Le plus simple** : une classe de valeur `App\Support\WeeklySlot`, à côté de `Support/FrenchDate.php`.
2. **Supprime** : les conversions privées d'heure en minutes, les 8 chevauchements, les 4 bornes de minuit,
   le `+1` du repli de `deriveMatchDay`.
3. **Un seul endroit** : `VenueWindowOccupancy::overlaps` (`:51-54`) est absorbé.
4. **Suppose en dur** : un créneau tient dans une journée.
5. **Deuxième usage** : la variante de semaine ajoutera une clé à côté du jour.

Hors périmètre : `date → jour`, le « lundi de la semaine », `ConflictMoments::overlaps` (`:40`),
`MatchPlacementPayloadBuilder::clockTime` (`:609-612`, fichier modifié par le lot 0 : à relire), les copies
côté écran.

### 3.3 Les étiquettes

1. **Le plus simple** : les deux routes de lecture gardent leur forme, servies par le calcul ; aucun
   fichier de `frontend/`. `TeamTagResolver::teamIdsByTagName` et `TagTeamIdsMirrorParityTest` restent.
2. **Supprime dans ce lot** : l'écouteur, la commande de rattrapage, toute l'écriture
   (`TeamTagService.php:59-133`, `:245-423`), le bloc `Seed/BcclSeeder.php:1141-1148`.
   **Garde** : `ageBracketsFor`, `isYouthTeam`.
3. **Ne change pas dans ce lot** :
   - `StructureSnapshotter` et `StructureRestorer` **ne sont pas modifiés**. La photo continue d'écrire
     ses dix familles, catégories et rattachements compris ; « Charger » les efface et les réinsère
     comme aujourd'hui.
   - La suppression d'une équipe n'est pas modifiée ; l'étape de `CascadePlan` et sa ligne « N étiquettes »
     partent à la PR 1 du lot 3.
   - Les deux tables et leurs entités restent jusqu'à la première PR du lot 3 (§4), qui les supprime dans la
     même mise en production.
4. **Limite écrite** (décision du 2026-11-21) : « Jusqu'au lot 5, charger une version remet les
   catégories du club dans l'état de cette version : une durée de match modifiée depuis revient à sa
   valeur d'alors, et une catégorie créée depuis par un import disparaît. » Avec le calcul à la lecture,
   une équipe dont la catégorie a disparu n'a plus de groupe d'âge, sans message (§2.2). La PR 3 écrit
   cette limite dans `specs/courantes/etat-des-lieux.md` §2 et en condition d'entrée du lot 5
   (`lot-conception-decoupage.md`).
5. **Suppose en dur** : catalogue fermé de 24 noms ; une étiquette est une fonction de (catégorie, sexe,
   niveau) lus à l'instant de la lecture.
6. **Deuxième usage** : aucun.

**Une catégorie modifiée vaut pour toutes les saisons du club** (décision F0). Limite écrite : aucun
écran ne modifie une catégorie, seul l'API (`PUT /api/sport_categories/{id}`) le permet.

## 4. Découpage en PR

Ordre : 1 → 2 → *copie de production n° 1* → 3 → 4a → 4b → 5, sur `lot/conception`, sans mise en production à
la fin du lot : le lot 1 ne part qu'avec tous les autres (déploiement unique, décision du fondateur du 2026-11-21).
La première PR du lot 3 (les tables) suit sur la même branche. La *copie de production n° 2* (rejeu de toutes les
migrations des lots ensemble) précède la **PR finale `lot/conception` → `main`** (GO du fondateur), puis la mise en
production unique.

### PR 1 · « Le cache se purge seul, la référence voit ce que le lot déplace »

- **S'ouvre après le merge de la PR 5 du lot 2.**
- **Purge** : `cache:pool:clear cache.schedule` par `exec -T php-fpm`, dans les deux branches de
  `scripts/remote-deploy.sh`, après « Deploy … OK », en avertissement sans code d'échec (décision FN1 :
  **déploiement marqué réussi, avec alerte**) :
  « ==> ⚠ CACHE DES DEMANDES NON PURGÉ (sans effet sur le déploiement) : une demande au moteur peut dater
  d'avant la migration pendant 4 h au plus. Relancer à la main : docker exec amateo-php-fpm php bin/console
  cache:pool:clear cache.schedule »
- **Conséquence nommée** : l'alerte n'est visible que dans le journal du déploiement ; le cache peut rester
  périmé 4 h au plus. Dans ce lot, la seule valeur lue par le constructeur qu'une migration réécrit est le
  jour de match (PR 2) : **sans effet, la mesure du jour de match vaut 0** (§13). La fermeture complète
  (une époque dans la clé) est une ligne pour le lot 3.
- **Deux demandes de référence de plus, captées sur le code actuel**, avec leur propre décor :
  - `socle-7-regles-de-groupe-et-jour-de-match.json` : une règle `targetTags` à deux noms, une
    `excludeTags`, une `targetTag` ancienne forme ; une équipe sans habitude au jour de match enregistré 5.
  - `periode-8-regle-de-groupe.json` : un plan de période avec une règle de groupe.
- `EngineRequestReferenceTest` entre dans le step `Blocking suite` et dans `docs/testing/blocking-tests.md`.
- **Fichiers** : `scripts/remote-deploy.sh`, `.github/workflows/ci.yml`, `docs/testing/blocking-tests.md`,
  `docs/ops/deploy.md` (§ « Étape post-déploiement OBLIGATOIRE », `:851-866`),
  `specs/evolution/lot-conception-filet.md` §9, `lot-conception-decoupage.md`, deux fichiers neufs dans
  `backend/tests/Fixtures/engine-requests/`, un trait de décor neuf, `EngineRequestReferenceTest.php`,
  `EngineRequestsRecordCommandTest.php` s'il énumère les références.

### PR 2 · « Un créneau de semaine, un jour de 1 à 7 »

- **Contenu** : `WeeklySlot` ; les sites du §2.3 l'appellent ; `TeamInput` valide 1 à 7 ; le `+1` disparaît.
- **Migration, en une fois** (décision F9, mesure en production = 0 sur table ET photos) : `team.match_day
  + 1` là où il est renseigné, et la même conversion dans les photos (`schedule_structure_snapshot.data`,
  clé `Team[].matchDay`).
- **Deux cas, sans effet puisque la mesure vaut 0** : pendant la bascule, l'ancien code ajouterait
  `+1` à une valeur convertie ; une génération en cours pendant la migration enregistrerait une photo au jour
  non converti. (Un retour arrière restaure la sauvegarde d'avant : aucun ancien code ne relit des données
  converties.) Si une
  mesure refaite avant la migration n'est plus nulle : retour au fondateur.
- **Texte** (API seule) : « Le jour de match doit être compris entre 1 (lundi) et 7 (dimanche). »
- **Preuve** : le décor de la référence 7 passe de 5 à 6 ; le fichier de référence ne change pas.
- **Fichiers** : `backend/src/Support/WeeklySlot.php`, les fichiers du §2.3, `Dto/TeamInput.php`,
  `ScheduleConstraintBuilder.php` (`deriveMatchDay`), une migration (horodatage postérieur à
  `Version20261121120000`), `specs/courantes/openapi-snapshot.json`.
- **Risque nommé** : `CoachDoubleBookingDetector` exige deux chiffres d'heure (`:509`), `PersonConflicts` en
  accepte un (`:82`) ; les heures de règle sont validées `HH:MM` (`ConstraintConfigValidator.php:53`). Si un
  fichier de cas de miroir devait bouger, retour au fondateur.

### Copie de production n° 1 · avant le merge de la PR 3

Avec l'accord du fondateur (F11). Détail au §11. Seul un écart **attribué à la PR 3** arrête la PR 3.

### PR 3 · « Les étiquettes se calculent à la lecture »

- **S'ouvre après le merge de la PR 5 du lot 2.**
- **Étiquettes** : `TeamTagResolver` calcule « nom → équipes » ; les deux routes sont servies par le calcul ;
  l'écouteur et la commande de rattrapage disparaissent ; les deux tables et leurs entités restent. Une
  commande de contrôle jetable compare l'enregistré au calculé (§5).
- **« Charger » n'est pas modifié** (décision du 2026-11-21) : la PR écrit la limite du §3.3 point 4 dans
  `etat-des-lieux.md` §2 et en condition d'entrée du lot 5.
- **Fichiers** : `Service/TeamTagService.php`, `Service/TeamTagResolver.php`, `ApiResource/TeamTag*Resource.php`,
  `State/Provider/TeamTag*StateProvider.php` (sans `AbstractStateProvider::provideItem`),
  `ConstraintStateProcessor.php:319-323`, `Seed/BcclSeeder.php:1141-1148`,
  `Command/VerifyStoredTeamTagsCommand.php` (neuf), `EventListener/TeamTagMemoResetListener.php` (neuf),
  `config/services.yaml` (`kernel.reset` sur `TeamTagResolver`), `specs/courantes/openapi-snapshot.json` ;
  supprimés : `TeamTagSyncListener.php`, `ResyncTeamTagsCommand.php` ; commentaires à réécrire :
  `SeasonTransitionService.php:598-611` et son docblock de classe, `CacheInvalidationListener.php:99`.
  **Non modifiés : `StructureRestorer.php`, `StructureSnapshotter.php`, `CascadePlan.php`,
  `DeleteByFieldStep.php`, les dépôts, `FfbbEngagementsController.php`.** **Aucun fichier de `frontend/`.**
- **Tests** : §7. Fichiers : `TeamTagScopeTest`, `TeamTagAssignmentReadIsolationTest` (neuf),
  `TeamTagResolverMemoResetTest` (neuf), `VerifyStoredTeamTagsCommandTest` (neuf), `TeamTagServiceTest`,
  `FfbbLevelAlignmentTest`, `ScheduleConstraintBuilderTest` et ses variantes (`AgeFields`, `CtcNeutrality`,
  `Overlay`), `tests/Behat/CategoryRuleContext.php`, `une-regle-de-categorie-suit-ses-equipes.feature`,
  `AccessDeclarationSweepTest.php:233`, `:708`, `OpenApiSnapshotMatchesTheLiveContractTest`,
  `TsFieldsMatchOpenApiSchemaTest` (à relire), `SeasonTransitionServiceTest:187-191`, `:713-720` (à relire),
  `ResyncTeamTagsCommandTest` (supprimé), `PeriodGatePayloadParityTest`.

### PR 4a · « Chaque famille se déclare, aucun comportement ne change »

- **S'ouvre après le merge de la PR 5 du lot 2.**
- **Contenu** : `#[Family]` sur toutes les entités (deux interfaces exclues ; le balayage fait foi),
  `#[RefersTo]` sur les colonnes qui désignent un autre objet, `FamilyCatalog`. Aucune liste ne bouge.
- **Tests** : `tests/Security/FamilyDeclarationSweepTest.php` (bloquant) ;
  `tests/Integration/FamilyCatalogMatchesPurgeListsTest.php` (le catalogue rend les familles des constantes
  E1, E2, E3 et leurs raisons ; supprimé par la 4b avec les listes).

### PR 4b · « Les purges lisent la déclaration » (livrée)

- **Contenu** : E1, E2, E3 remplacées par des appels à `FamilyCatalog` ; ordre selon le §3.1 ; ADR
  [`adr-0005-family-declaration.md`](../../docs/architecture/adr-0005-family-declaration.md). Fichiers :
  `SeasonDataPurger.php`, `ErasedClubPurger.php`, `FamilyCatalog.php`, `OpponentVenueLink.php` (épargne déclarée),
  l'ADR, `adr-index.md`.
- **Garde du catalogue** : une famille de niveau club, saison, plan ou version qui n'entre dans aucune purge, ne
  déclare aucune épargne et n'est pas un cas nommé fait lever `LogicException` ; les cas nommés sont `Season`
  (`SEASON_PIVOT`), `Club` et `TransitionReminderLog` (`OUTSIDE_THE_PURGE_LOOPS`) et `SeasonDataPurger::HANDLED_APART`.
  `FamilyCatalogMatchesPurgeListsTest` est **supprimé** (il comparait le catalogue aux listes que la 4b retire).
- **Tests** : `PurgeCompletenessTest` (relit le catalogue, bloquant) ;
  `tests/Integration/PurgeOrderRespectsForeignKeysTest.php` (neuf, bloquant, lit `pg_constraint` via
  `tests/RealForeignKeysTrait.php`, relève les `DELETE` exécutés via `tests/CapturesDeletedTablesTrait.php`) ;
  `tests/Unit/Structure/FamilyCatalogRefusesAnUnpurgedTenantFamilyTest.php` (la garde du catalogue) ;
  `FamilyDeclarationSweepTest::testTheFamiliesPurgedThroughTheirOwnerAreTheFiveKnown` (les cinq propriétaires
  `owner: true`) ; `DeletionImpactParityTest::testEveryColumnPointingAtATeamVenueCoachOrSlotIsHandledByThePlan`.
- **Conditions tenues** : le test d'ordre exige l'enfant avant son parent contre les clés réelles (aucune n'est
  `RESTRICT` ni `NO ACTION`, une permutation ne lèverait pas d'erreur) ; les familles par propriétaire partent
  avant la boucle par club + saison ; `TransitionReminderLog` reste non purgée (comportement conservé, nommée) ;
  les raisons de `HANDLED_APART` restent dans la constante du purgeur ; `Season` reste hors de
  `purgedWithClub()` par `SEASON_PIVOT`.
- **Limites écrites** : une liste réintroduite sous un autre nom dans un purgeur n'est pas vue par construction ;
  la justesse d'une déclaration `DELETE` n'est gardée que par l'ordre réel contre les vraies clés.
- **Constat annexe (hors périmètre)** : `ConflictMailReceipt.coachId` et `CoachWishToken.coachId` sont effacés
  par cascade à la suppression d'un coach mais ne sont pas annoncés dans sa fenêtre de suppression.

### PR 5 · « Les constats tranchés » (livrée)

- **Gymnase** (décision F1 : **la règle part avec son gymnase, annoncée**) : étape
  `VenueConfigConstraintStep` dans `CascadePlan::forVenue()`. Une seule liste de clés :
  `VENUE_CONFIG_KEYS` remplacée par `ConstraintConfigValidator::uuidKeys()` au constructeur (`:589`) et au
  sélecteur de période (`PeriodConstraintSelector.php:273`). Sans effet sur une demande si aucune règle ne
  porte la clé fantôme : mesure en production = 0, références en CI.
- **Message** (F2) : « La saison suivante existe déjà. »
- **`POST /api/seasons`** (F4) : `new Post` retiré, avec le commentaire faux de `SeasonResource.php:22-23`.
  L'ordre des gardes de `transition` n'est pas modifié.
- **Textes** : « contrainte qui nomme ce gymnase » / « contraintes qui nomment ce gymnase ».
- **Fichiers** : `Deletion/VenueConfigConstraintStep.php` (neuf), `CascadePlan.php`,
  `ScheduleConstraintBuilder.php:50`, `:589`, `PeriodConstraintSelector.php:261-273`,
  `SeasonAlreadyTransitionedException.php`, `ApiResource/SeasonResource.php`,
  `State/Processor/SeasonStateProcessor.php`, `openapi-snapshot.json`.
- **Tests** : `DeletionImpactParityTest` (bloquant : la règle qui nomme le gymnase part, annoncée une fois, bascule
  de période comprise ; une règle qui nomme un autre gymnase, d'un autre club ou d'une autre saison reste) ;
  `supprimer-emporte-ce-qui-en-depend.feature` ; `ConstraintConfigValidatorTest` (la liste unique) ;
  `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature` (la création directe répond 405).
- **Limites écrites** : l'ordre bascules-puis-contraintes de `VenueConfigConstraintStep` n'est gardé par aucun test
  (aucune clé étrangère `constraint_period_override` → `constraint` ne le ferait rougir) ; la borne club de sa
  requête SQL n'est prouvée que par la RLS (ceinture), la borne saison l'est par le test d'isolation.
- **Constat annexe (hors périmètre, nettoyage ultérieur à proposer)** : `SeasonStateProcessor`
  (`createEntityFromInput`, `updateEntityFromInput`), l'`input: SeasonInput::class` de `SeasonResource` et
  `Dto/SeasonInput.php` ne servent plus à rien depuis le retrait de la route.

### Hors lot 1 · les deux tables d'étiquettes (première PR du lot 3)

- **Où elle vit** : première PR du lot 3, condition d'entrée écrite dans sa fiche. Elle supprime les tables dans
  **la même mise en production** que le lot 1 (décision du 2026-11-21 : déploiement unique ; la dérogation à
  `docs/ops/deploy.md` § « Règle d'écriture des migrations » est portée par la procédure unique, plan du lot 3, §6).
- **Contenu** : `DROP TABLE team_tag_assignment, team_tag` ; entités, dépôts (`Repository/TeamTag*`), entrées de
  purge, étape de `CascadePlan` et sa ligne « N étiquettes » (F10), `DeleteByFieldStep.php:14` (commentaire),
  `DeletionImpactParityTest`/`SILENT_STEPS`, rattachements dans la photo et la restauration, commande de
  contrôle : retirés ; à recaler : `ReadOnlyRoleTest.php:135-140` et `:287-325` (lisent `team_tag`),
  `scripts/prod-read.sh:54`, `docs/ops/deploy.md:474` (exemples sur `team_tag`),
  `PurgeSeasonsCommandTest.php:81`, `:206-213` ; cas de test neuf : « Charger une photo d'avant qui porte
  encore la clé `TeamTagAssignment` réussit après suppression de l'entité » (`StructureRestorer.php:104-105`
  ignore une clé inconnue) ;
  `RlsIsolationTest` prend `team` pour étalon. Migration déclarée irréversible.

### Retour arrière : une seule procédure pour tout le lot conception

Le lot 1 n'a pas de mise en production ni de retour arrière à lui. La procédure de mise en production du lot
conception tout entier (ordre des migrations de tous les lots, vérifications, critère de retour arrière =
restauration de la sauvegarde d'avant, correction en avant après réouverture) est écrite dans `docs/ops/deploy.md`
par la dernière PR avant la PR finale, relue par le fondateur (plan du lot 3, §6). Aucune PR du lot 1 n'y écrit.

## 5. Contrats nouveaux

```php
// PR 2 — App\Support\WeeklySlot (final readonly)
public const int MINUTES_PER_DAY = 1440;
public function __construct(public int $day, public int $startMinutes, public int $durationMinutes);
        // InvalidArgumentException si day ∉ 1..7, startMinutes ∉ 0..1439, durationMinutes < 1
public static function at(int $day, DateTimeInterface|string $start, int $durationMinutes): self;
public function endMinutes(): int;
public function overlaps(self $other): bool;      // même jour ET intervalles demi-ouverts
public function endsWithinDay(): bool;            // endMinutes() <= 1440
public static function minutesOf(DateTimeInterface|string $time): int;   // InvalidArgumentException si illisible
public static function tryMinutesOf(mixed $time): ?int;                  // null si illisible
public static function clock(int $minutes): string;                      // 'HH:MM'
public static function intervalsOverlap(int $aStart, int $aEnd, int $bStart, int $bEnd): bool;
```
`PUT|POST /api/teams` : `matchDay` de 1 à 7, sinon 422 ; `GET` rend 1 à 7.

```php
// PR 3
TeamTagService::catalog(): list<array{name: string, color: string, axis: TeamTagAxis}>   // le catalogue de 24 noms
TeamTagService::tagNamesOf(Team $team): list<string>
TeamTagResolver::teamIdsByTag(string $clubId, string $seasonId): array<string, list<string>> // ids triés, mémo par message
TeamTagResolver::tagTeamIds(string $targetTag, string $seasonId, string $clubId): list<string> // signature inchangée
TeamTagResolver::teamIdsByTagName(array $tags, array $assignments): array                  // inchangée
```
- `GET /api/team_tags` → 200, un objet par nom du catalogue `{id, name, color, isSystem: true, axis}` ; `id` =
  le nom (« U13 »), identique pour tous les clubs.
- `GET /api/team_tags/{id}` → 200 ou 404.
- `GET /api/team_tag_assignments?page=N` → 200, 30 par page, `{id: "<teamId>:<NOM>", teamId, tagId: "<NOM>",
  seasonId}`, triés par équipe puis nom, toutes les équipes de la saison de la requête.
- Retirés : `version`, `createdAt`, `updatedAt` ; la route `GET /api/team_tag_assignments/{id}`.
- `php bin/console app:team-tags:verify-stored [--club=<uuid>]…` : lecture seule, toutes saisons. Sortie 0 =
  identique ; 1 = écarts listés par identifiants seulement, plus : étiquette hors catalogue ou non système,
  couleur ou axe différents, rattachement hors catalogue, règle qui vise un nom hors catalogue, équipe dont
  la catégorie n'existe plus. Supprimée avec les tables.

```php
// PR 4a — App\Structure
enum Level { PLATFORM, CLUB, SEASON, PLAN, VERSION }
enum OnDelete { DELETE, CLEAR, CUSTOM, KEEP }
#[Attribute(TARGET_CLASS)]    Family(Level $level, ?string $keptAtSeasonPurge = null, ?string $keptAtErasure = null)
#[Attribute(TARGET_PROPERTY | IS_REPEATABLE)] RefersTo(string $family, OnDelete $onDelete, ?string $reason = null, bool $owner = false)
        // jamais sur id, clubId, seasonId ; $reason obligatoire pour KEEP et CUSTOM
FamilyCatalog::all(): list<class-string>
FamilyCatalog::of(string $entityClass): Family                    // LogicException si non déclarée
FamilyCatalog::purgedWithSeason(): list<class-string>             // enfants avant parents
FamilyCatalog::purgedViaOwner(): array<class-string, array{0: string, 1: class-string}>
FamilyCatalog::purgedWithClub(): list<class-string>
FamilyCatalog::referencesTo(string $entityClass): list<array{class: class-string, field: string, onDelete: OnDelete}>
```
PR 5 : `App\Deletion\VenueConfigConstraintStep implements CascadeStep` ; `POST /api/seasons` → 405 (le routeur
répond avant le contrôle d'accès ; prouvé par la feature du 1er mai) ; `POST /api/seasons/{id}/transition`
avec successeur → 409 `{error: "La saison suivante existe déjà.", existingSeasonId}`.

## 6. Table des familles

Déclaré dans ce lot : **niveau** et **effacée avec**. Les deux colonnes du milieu sont les valeurs
d'aujourd'hui, pour mémoire (décision F5 : on ne déclare que ce qu'une purge lit).

| Familles | Niveau | Saison suivante *(mémoire)* | Dans la photo *(mémoire)* | Effacée avec |
|---|---|---|---|---|
| `Team`, `Coach`, `Venue`, `TeamCoach`, `CoachPlayerMembership` | Saison | Recopiée | Oui | La saison ; liens : l'équipe ou le coach |
| `VenueTrainingSlot` | Saison / plan | Recopiée si sans plan | Oui si sans plan | La saison, le gymnase, son plan |
| `Constraint` | Saison | Recopiée si permanente | Oui si permanente | La saison ; sa cible |
| `Reservation` | Saison / plan | Non | Oui si sans plan | La saison, l'équipe, le gymnase, le créneau, son plan |
| `VenueOccupancy`, `ImplicitRuleSetting` | Saison / plan | Recopiée si sans plan | Non | La saison, son plan ; l'occupation : le gymnase |
| `VenueMatchWindow`, `TeamMatchHabit`, `TeamLink`, `ClubLeagueWindow`, `MatchConstraint`, `VenueTravelTime`, `VenueTravelRuleSetting` | Saison | Recopiée | Non | La saison ; l'équipe, le gymnase ou le coach visé |
| `SharedTrainingBlock`, `SharedTrainingBlockTeam` | Saison / plan | Non | Non | La saison, son plan ; le bloc dès qu'une équipe membre part |
| `TeamPeriodOverride`, `ConstraintPeriodOverride`, `VenuePeriodOverride` | Plan | Non | Non | Son plan, la saison ; l'équipe, la règle ou le gymnase |
| `SchedulePlan`, `CalendarEntry` | Saison | Non | Non | La saison |
| `Schedule` | Plan | Non | Non | La saison |
| `ScheduleSlotTemplate`, `ScheduleDiagnostic`, `ScheduleStructureSnapshot`, `ConstraintConflict` | Version | Non | Non | Sa version ; séance : l'équipe, le gymnase (coach vidé) |
| `CoachWish`, `CoachWishMutualization`, `CoachWishCampaign`, `PeriodReminderLog` | Saison, par l'entrée de calendrier | Non | Non | L'entrée de calendrier ; doléance : l'équipe (coach vidé) |
| `CoachWishToken` | Saison | Non | Non | Sa campagne et son coach (clés étrangères) |
| `Fixture`, `Competition`, `MatchModuleVisit`, `MatchPlacementRun`, `ConflictResolution`, `ConflictMailReceipt`, `ConflictMailing`, `FbiIngestion`, `FbiCorrection`, `HolidayReminderLog` | Saison | Non | Non | La saison ; match et engagement : l'équipe |
| `SportCategory` | Club | Reste | Écrite et relue (inchangé dans ce lot) | Le club |
| `TeamTag` | Club | Reste | Non | Le club ; supprimée au lot 3 |
| `TeamTagAssignment` | Saison | Non | Oui, jusqu'à la suppression des tables | La saison, l'équipe ; supprimée au lot 3 |
| `ClubUser`, `Feedback`, `SolverMetric`, `ClubTravelCache`, `OpponentVenueLink`, `ClubMailboxMessage`, `ClubInvitation` | Club | Reste | Non | Le club |
| `AuditLog` | Club | Reste | Non | Jamais par la purge (`ErasedClubPurger.php:56`) |
| `Season` | Club | Non | Non | Le club ; gardée par la remise à zéro |
| Les autres (comptes, jetons, calendrier scolaire, offres, fédération, console) | Plateforme | Reste | Non | Hors purge de club |

Classement de `OpponentDirectoryEntry`, `OpponentVenueSuggestion`, `SharedCompetitionDeadline`,
`FfbbLeague` : **non vérifié** ; le test de la PR 4a tranche contre les constantes actuelles.

## 7. Tableau comportement → scénario (brief de `tank`)

| # | PR | Comportement | Preuve (rouge avant `neo`) |
|---|---|---|---|
| 1 | 1 | Après « Deploy … OK », le cache est purgé dans les deux branches ; un échec affiche le message de la PR 1 et ne change pas le code de sortie | `tests/Unit/Scripts/RemoteDeployPurgesScheduleCacheTest.php` (patron `RemoteDeployRealignsSnapshotsTest`) |
| 2 | 1 | Deux demandes de plus figées à l'octet sur le code actuel | `EngineRequestReferenceTest`, deux cas neufs, bloquant |
| 3 | 2 | Deux créneaux jointifs ne se chevauchent pas ; jours différents non plus ; dépassement de minuit reconnu ; « 9:00 », « 09:00 », « 09:00:00 » valent 540 | `tests/Unit/Support/WeeklySlotTest.php` |
| 4 | 2 | Une équipe sans habitude dont le jour enregistré est 6 (samedi) envoie 6 | `HabitPayloadParityTest.php:130-143` **réécrit** ; décor de la référence 7 à 6, fichier inchangé |
| 5 | 2 | La migration convertit 5 en 6 dans `team` et dans les photos ; `NULL` reste `NULL` ; « Charger » une version d'avant rend samedi | `tests/Integration/Migration/TeamMatchDayIsoMigrationTest.php` |
| 6 | 2 | `matchDay` 0 et 8 refusés avec le texte de la PR 2 ; 1 et 7 acceptés | `tests/Validator/TeamInputValidationTest.php:26`, `:46-50`, étendu |
| 7 | 2 | Restent verts sans modification : `EngagedTeamGuardTest.php:201`, `:229` ; `PruneUnreadSnapshotFieldsCommandTest.php:146` | À relire par `tank` |
| 8 | 3 | Une équipe passée de U15 en U13 porte U13 et plus U15, sans écriture d'étiquette | `une-regle-de-categorie-suit-ses-equipes.feature`, étape existante, lue par les deux routes |
| 9 | 3 | La règle « les U13 ne commencent pas avant 19:30 » part pour les trois équipes U13 et aucune autre, et la génération l'honore | Même feature, étapes inchangées ; références 7 et 8 inchangées |
| 10 | 3 | Une catégorie modifiée change les groupes de toutes les saisons qui l'emploient ; une version non validée s'y dit périmée ; un planning validé ne dit rien | `TeamTagScopeTest`, cas neuf à deux saisons |
| 11 | 3 | Les étiquettes d'un club ne contiennent jamais une équipe d'un autre club, bien que l'identifiant « U13 » soit le même partout (abus) | `TeamTagScopeTest::testTagsNeverCarryAnotherClubsTeam` (bloquant) |
| 13 | 3 | Une règle qui cible un groupe inconnu est refusée : « Le groupe « X » n'existe pas dans ce club. » | Test existant du refus, à localiser (`ConstraintStateProcessor.php:319-323`) |
| 14 | 3 | Un jeune reste reconnu pour l'alignement de niveau FFBB | `tests/Security/FfbbLevelAlignmentTest.php`, relu sur le calculé |
| 15 | 3 | La commande de contrôle sort 0 sur une base saine et 1 en désignant une équipe dont l'enregistré diffère, saison archivée comprise | `tests/Integration/Command/VerifyStoredTeamTagsCommandTest.php` |
| 16 | 3 | Le contrat d'API figé suit : trois champs et une route en moins | `OpenApiSnapshotMatchesTheLiveContractTest` ; `TsFieldsMatchOpenApiSchemaTest` vert sans toucher l'écran |
| 17 | 4a | Toute entité porte `#[Family]` ; toute colonne `guid` hors `id`, `clubId`, `seasonId` porte `#[RefersTo]` ; aucun cycle « meurt avec » ; le catalogue rend les familles des trois constantes de purge | `tests/Security/FamilyDeclarationSweepTest.php` (bloquant) ; le test de concordance avec les constantes de purge, supprimé par la 4b |
| 18 | 4b | Toute donnée d'un club est purgée ou exclue avec sa raison ; aucune suppression ne heurte une clé réelle | `PurgeCompletenessTest` (bloquant) ; `tests/Integration/PurgeOrderRespectsForeignKeysTest.php` (exige l'enfant avant son parent pour chaque `CASCADE`, pas la seule absence d'erreur) ; `FamilyDeclarationSweepTest::testTheFamiliesPurgedThroughTheirOwnerAreTheFiveKnown` ; `tests/Unit/Structure/FamilyCatalogRefusesAnUnpurgedTenantFamilyTest.php` |
| 19 | 4b | Remettre la saison à zéro efface toute la structure et garde club, gestionnaire et saison | `supprimer-emporte-ce-qui-en-depend.feature`, deux dernières étapes, **inchangées** |
| 20 | 4b | Toute colonne qui désigne une équipe, un gymnase, un coach ou un créneau est dans `CascadePlan`, ou déclarée conservée avec sa raison | `DeletionImpactParityTest::testEveryColumnPointingAtATeamVenueCoachOrSlotIsHandledByThePlan` |
| 21 | 5 | Supprimer un gymnase emporte les contraintes qui le nomment, et l'annonce | `supprimer-emporte-ce-qui-en-depend.feature` : « Et l'équipe « U15M1 » a une règle qui lui impose le « Gymnase Nord » » ; « Alors la suppression du « Gymnase Nord » annonce 1 contrainte qui nomme ce gymnase » ; « Et plus aucune règle ne nomme le « Gymnase Nord » » ; parité dans `DeletionImpactParityTest` |
| 22 | 5 | Préparer une seconde fois : « La saison suivante existe déjà. », saison désignée | `preparer-la-saison-suivante-reprend-la-structure.feature`, ligne du message |
| 23 | 5 | Aucune saison ne naît hors de « préparer » (abus) | `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`, scénario neuf « Créer une saison par l'API est refusé » ; lignes retirées de `ManagementRoleTest:138` et `MemberWriteSweepTest:121` |
| 24 | lot 3 | Les deux tables d'étiquettes n'existent plus ; l'isolation reste prouvée ; photo et restauration perdent la famille ensemble ; supprimer une équipe n'annonce plus d'étiquettes et ne laisse aucun rattachement ; charger une photo d'avant qui porte encore la clé `TeamTagAssignment` réussit | `RlsIsolationTest` (bloquant), étalon `team` ; `StructureFamiliesStayInSyncTest` reste vert ; `DeletionImpactParityTest` (bloquant) ; cas de test « Charger » d'avant |

Restent verts sans modification, à chaque PR : `preparer-la-saison-suivante-reprend-la-structure.feature`
(tableau de comptes de copie existant), `OverlayManagerBlockPurgeTest`, les six références d'origine,
`CoachDoubleBookingMirrorParityTest`, `EmptySlotOverlapMirrorParityTest`, `TagTeamIdsMirrorParityTest`,
`voeux-des-coachs.feature` (il charge une version), `StructureFamiliesStayInSyncTest` (non modifié).

**Preuve = captures (`switch`)** : aucune. Les lignes de liste en moins ou en plus sont des données,
prouvées par les lignes 12 et 21.

## 8. Carte des conséquences

| Comportement | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Code de sortie de `remote-deploy.sh` | Le script | `deploy.yml` : levée de la maintenance ; recalage de `release` | Les étapes d'après-succès n'échouent jamais | Inchangé (déploiement marqué réussi, alerte au journal) |
| Photo d'une version | `StructureSnapshotter.php:74-85` | La restauration du code en place | 10 familles écrites, 10 relues | **Inchangé** |
| Étiquettes d'une équipe | Avant : `TeamTagSyncListener.php:65-124`. Après : personne | `TeamTagResolver.php:235-298` → constructeur, sélecteur de période, saisie de règle ; `FfbbEngagementsController.php:172`, `:338` ; écran | Filtre de club, RLS, filtre de saison à l'écriture | Filtre de club et RLS sur `team` et `sport_category` ; plus de frontière de saison ; tables tenues sans orphelin jusqu'à leur suppression (PR 1 du lot 3) |
| « Charger » → catégories du club → groupes de l'autre saison | `StructureRestorer.php:274`, `:104-118`, pour tout le club | Toutes les saisons, par les étiquettes ; le module matchs, par les durées | Le défaut est masqué par les étiquettes enregistrées | **Limite écrite (§14), levée au lot 5** : plus de masque, le groupe d'âge d'une catégorie disparue manque |
| Rattachements à la suppression d'une équipe | `CascadePlan.php:78` | `DeletionImpactCounter` → `delete-confirm.tsx:89-91` | Supprimés et annoncés | Inchangé dans le lot 1 ; étape et ligne « N étiquettes » retirées à la PR 1 du lot 3 (tables supprimées) |
| Empreinte de structure d'un plan | `SchedulePlanProvisioner::structureHashOfPlan` | `GET /api/schedule_plans/{id}/structure-hash` → `staleness.ts` | Bouge quand une étiquette est réécrite | Bouge dès qu'une catégorie change, pour toute saison qui a une règle de groupe |
| Cache de la demande | `ScheduleConstraintBuilder.php:260-268`, `:328-333` (à relire) | Les trois guichets | Invalidation par club | Inchangé |
| Suppression d'un gymnase | `EntityCascadeDeleter` | `DeletionImpactCounter` | Refus si un match y est placé (`VenueStateProcessor.php:58-60`) ; gymnase imposé de l'équipe vidé | Idem, puis les règles qui le nomment partent |
| Règles qui nomment un gymnase inactif | — | `ScheduleConstraintBuilder.php:577-598`, `PeriodConstraintSelector.php:273` | Cinq clés d'un côté, quatre de l'autre | Une liste, quatre clés |
| Purges de saison et de club | `SeasonDataPurger.php:225-310`, `ErasedClubPurger.php:125-206` | `ResetSeasonController`, `PurgeSeasonsCommand`, `app:clubs:purge-erased` | Constantes ordonnées à la main | Catalogue, ordre dérivé, test contre les clés réelles |
| Contrat d'API figé | `openapi-snapshot.json` | `OpenApiSnapshotMatchesTheLiveContractTest`, `TsFieldsMatchOpenApiSchemaTest` | — | Régénéré aux PR 2, 3, 5 |
| Jour de match | `TeamStateProcessor.php:103`, `:197-198` | `ScheduleConstraintBuilder.php:1437`, `deriveMatchDay` → moteur ; photo | 0 à 6, `+1` à l'émission | 1 à 7, aucune conversion |
| Naissance d'une saison | `SeasonTransitionService.php:183-193`, `ClubProvisioner`, `SeasonStateProcessor.php:48-59` | `SeasonResolver`, sélecteur de saison | Date et paiement sur « préparer » seulement | Une seule porte après l'inscription |

## 9. Parcours de bout en bout

- **Changer la catégorie d'une équipe** (bouton). Wizard, équipes : passer « U15 A » en U13. Le groupe U13
  compte une équipe de plus. Inverse : remettre U15. Variante période : mêmes routes. Variante réservé :
  sans effet.
- **« Charger » une ancienne version** (bouton), saison 2026-2027, alors que 2027-2028 est en préparation.
  Lu dans le code, non joué : (1) générer V en 2026-2027 ; (2) en 2027-2028, importer 2 équipes U7 : l'import
  crée la catégorie « U7 » ; (3) revenir en 2026-2027 et charger V.
  - *Aujourd'hui* : « U7 » disparaît pour tout le club ; les 2 équipes gardent leurs étiquettes enregistrées :
    rien ne se voit.
  - *Après ce lot* (limite écrite, §14) : elles perdent leur groupe d'âge ; « Groupe Baby · pas après 18:30 »
    ne les vise plus ; aucun message. Levé au lot 5, où une version ne touche plus que son plan.
- **Modifier le nom ou les âges d'une catégorie** : aucun écran, `PUT /api/sport_categories/{id}` ou support.
- **Supprimer une équipe.** Inchangé dans le lot 1 ; à la PR 1 du lot 3, la fenêtre liste séances, réservations,
  liens, sans « étiquettes ». Annuler : rien ne part.
- **Supprimer un gymnase nommé par une règle.** Wizard, contraintes : « U15M1 s'entraîne au Gymnase Nord »
  (permis : `ConstraintStateProcessor.php:354-372`). Wizard, gymnases : supprimer ; la fenêtre annonce « 1
  contrainte qui nomme ce gymnase ». Confirmer : la règle part. Annuler : rien. Variante période : la bascule de
  période de la règle part avec elle. Variante match : un gymnase qui porte un match reste refusé avant tout.
- **Préparer deux fois.** L'écran dit déjà « La saison suivante existe déjà — bascule dessus. ».
- **`POST /api/seasons`.** Aucun bouton : API seule.

## 10. Ce qui pourrait changer visiblement, et ce qui le détecte

| Changement possible | Détecté par |
|---|---|
| Une catégorie modifiée vaut pour toutes les saisons ; les versions non validées s'y disent périmées | Décision F0 ; ligne 10 |
| Une équipe aux étiquettes enregistrées périmées, ou sans catégorie, est lue autrement | `app:team-tags:verify-stored` sur la copie de production n° 1 |
| Couleur ou axe d'une étiquette différents du catalogue ; étiquette ou règle hors catalogue | Même commande ; mesures du §13 |
| La ligne « N étiquettes » quitte la fenêtre de suppression | Voulu (F10), à la PR 1 du lot 3 ; ligne 24 |
| Un club sans équipe qui crée une règle de groupe par l'API reçoit « Le ciblage « U13 » ne désigne aucune équipe cette saison… » au lieu de « Le groupe « U13 » n'existe pas dans ce club. » | Limite écrite (§14) |
| « Charger » une version ancienne rend un autre jour de match | Ligne 5 |
| Un écart de lecture d'heure après unification | Ligne 3 ; miroirs inchangés |
| L'ordre d'une purge change | Lignes 18 et 19 |

**Tests d'abus** : étiquettes d'un autre club (ligne 11) ; saison créée sans date ni paiement (ligne 23) ; un
Membre ne prépare pas la saison (`un-club-ne-voit-jamais-un-autre-club.feature:39`, inchangé).

## 11. La preuve « demande au moteur identique »

1. **En CI, à chaque PR** : `EngineRequestReferenceTest`, les six références d'origine plus les deux de la
   PR 1, à l'octet, bloquant depuis la PR 1. Aucune PR ne modifie un fichier de référence.
2. **Sur la stack d'épreuve** : la comparaison avant/après sur les clubs seedés ne prouve rien pour les PR 2
   et 3 ; jouée pour les PR 4b et 5 seulement.
3. **Copie de production n° 1, avant le merge de la PR 3**, accord du fondateur (l'une des deux copies de
   production de tout le lot conception, plan du lot 3, §6). Trois enregistrements sur
   la même copie, le même jour :
   - **A** : au commit de `lot/conception` qui précède la première PR du lot 1.
   - **B** : à la tête de `lot/conception` (elle contient la PR 2 et tout ce que le lot 2 a mergé entre-temps),
     migrations jouées.
   - **C** : sur la branche de la PR 3, non mergée, posée sur cette tête.
   - **`diff B C` est la preuve de la PR 3** : seul un écart ici l'arrête. **`diff A B` s'attribue par commit** :
     s'il n'est pas vide, on rejoue l'enregistrement commit par commit ; un écart né d'une PR du lot 2 revient
     au lot 2 et n'arrête pas la PR 3.
   - `app:team-tags:verify-stored` avec le code C : sortie 0 attendue, toutes saisons.
4. **Copie de production n° 2 = rejeu de TOUTES les migrations des lots ensemble, avant la PR finale
   `lot/conception` → `main`** (qui n'est plus la fin du lot 1 : elle ferme le lot conception entier), accord du
   fondateur : la preuve de l'ensemble, lot 1 compris ; elle ne figure pas parmi les étapes du lot 1.
5. **Ce que les trous du filet obligent à écrire** dans chaque compte rendu : la liste des plans de période
   sautés (`manifest.json`), dont la preuve est la référence 8 ; une requête prouvant qu'aucune version n'est
   en attente ou en cours ; pour `/validate-assignments` et la fenêtre de placement libre, non enregistrés, la
   preuve est `la-boucle-de-travail-du-gestionnaire.feature`, inchangée. Les fichiers d'empreintes s'effacent
   avec le dump.

## 12. Branches de décision restantes

Les décisions FN1, F0, F0b, FB1, F7… sont prises (§13). Restent les cas où le réel contredit l'attendu :

- **Une mesure refaite avant la PR 2 n'est plus nulle** (jour de match en table ou en photo) : la PR 2 ne
  convertit pas ; retour au fondateur avec le nombre d'équipes.
- **`app:team-tags:verify-stored` sort 1 sur la copie n° 1, écart attribué à la PR 3** : la PR 3 s'arrête, retour
  au fondateur.
- **Un fichier de référence ou de cas de miroir devrait changer** : retour au fondateur.
- **Un cycle « meurt avec » trouvé par le balayage de la PR 4a** : retour au fondateur.

## 13. Décisions du fondateur (2026-11-21, « tout reco » sur la version allégée) et mesures

| # | Décision | Retenu |
|---|---|---|
| FN1 | Vidage de cache raté à la fin d'une mise en production | **Déploiement marqué réussi, avec alerte** (la mesure du jour de match vaut 0) |
| F0 | Une catégorie modifiée vaut pour toutes les saisons du club | **Oui** ; limite écrite : aucun écran ne modifie une catégorie, seul l'API |
| F0b | « Charger » cesse de toucher aux catégories dès ce lot | **Non** : retourne au lot 5 ; limite écrite (§3.3, §14) |
| F0c, FB1 | Catégorie disparue dans une photo ; photo qui continue d'écrire ce qu'on ne relit plus | **Sans objet** (la photo et la restauration ne sont pas modifiées) |
| F1 | Supprimer un gymnase qu'une règle nomme | **La règle part avec son gymnase, annoncée** |
| F2 | Message anglais | « La saison suivante existe déjà. » |
| F3 | Drapeau démo | **Constat fermé par le lot 2** (PR 5), accepté explicitement |
| F4 | `POST /api/seasons` | **Route retirée** |
| F5 | Que déclare-t-on par famille | **Seulement ce qu'une purge lit** |
| F7 | Étiquettes en deux mises en production | **Levée le 2026-11-21 (déploiement unique)** : les tables partent à la première PR du lot 3, dans la même mise en production que le lot 1 |
| F8 | Quand `lot/conception` part vers `main` | **Levée le 2026-11-21** : une seule PR finale, à la fin du lot conception entier, après la copie n° 2 |
| F9 | Jour de match de l'équipe | **Converti en 1 à 7** (mesure = 0 sur table et photos) |
| F10 | Ligne « N étiquettes » | **Retirée** |
| F11 | Copies de la production | **Accord du fondateur à chaque restauration** : la copie n° 1 avant le merge de la PR 3, la n° 2 (rejeu de toutes les migrations) avant la PR finale du lot conception |

**Mesures en production, faites en lecture seule le 2026-11-21** (quatre clubs réels, un club de démonstration
écarté ; comptes et identifiants seulement). Tous à 0 comme attendu :

| Mesure | Résultat |
|---|---|
| Équipes avec `match_day` renseigné | 0 |
| Photos dont une équipe porte un `matchDay` | 0 |
| Photos désignant une catégorie absente | 0 |
| Équipes à catégorie orpheline | 0 |
| Étiquettes non système | 0 (96 étiquettes = 4 × 24) |
| Rattachements | 411, dont 0 hors catalogue ou non système |
| Étiquettes à couleur ou axe différents du catalogue | 0 |
| Règles citant une étiquette hors catalogue | 0 |
| Règles portant `setVenueId` | 0 |
| Clés de gymnase sans gymnase | 0 |
| Versions en attente ou en cours | 0 |
| Catégories personnalisées portant U9/U11/U13/U15/U18/U21 | 0 (une seule catégorie personnalisée, club B, sans âge) |
| Plans sans version chargée | 3, à titre d'information (club A : son plan de saison ; club C : un plan de vacances et un plan de fermeture) |
| Volumes (clubs A à D) | 13/16/51/14 équipes ; 5/5/84/6 règles ; 0/1/0/1 photos |

Pour mémoire, la procédure : clé déverrouillée par le fondateur (`ssh-add -t 1h`, `scripts/prod-read.sh:34-36`),
**un appel par club** (`--club`), sorties en comptes et identifiants seulement.

## 14. Limites écrites (assumées, non traitées)

1. **Entre le démarrage des nouveaux conteneurs et la purge du cache** (quelques secondes), ou pendant 4 h si
   la purge échoue : une génération peut lire une demande d'avant. Sans effet dans ce lot, la mesure du jour de
   match valant 0. Fermeture : lot 3.
2. **« Charger » (jusqu'au lot 5)** : « Jusqu'au lot 5, charger une version remet les catégories du club dans
   l'état de cette version : une durée de match modifiée depuis revient à sa valeur d'alors, et une catégorie
   créée depuis par un import disparaît. » Avec les étiquettes calculées, les équipes qui la portaient perdent
   leur groupe d'âge sans message.
3. **Aucun écran ne modifie une catégorie** : seul l'API (`PUT /api/sport_categories/{id}`) ; la décision « une
   catégorie modifiée vaut pour toutes les saisons » n'a donc pas de geste d'écran à surveiller.
4. **Retour arrière** : un seul geste, la restauration de la sauvegarde d'avant (plan du lot 3, §6).
6. **Les équipes déjà sans catégorie en base** sont mesurées (0 aujourd'hui), pas réparées.
7. **`/validate-assignments` et la fenêtre de placement libre** n'ont aucun avant/après enregistré.
8. **Un club de démonstration dont l'horloge est ramenée avant le 1er mai** après avoir préparé sa saison reçoit
   « La saison suivante se prépare à partir du 1er mai. » sans la saison désignée.
9. **Un club sans équipe qui crée une règle de groupe par l'API** reçoit un autre message de refus (§10).
10. **Les références rangées dans du JSON** ne sont pas vues par la déclaration.
11. **Le retour arrière de ce que les lots 0 et 2 ont mis dans la même mise en production** n'est pas décrit par ce
    plan : c'est celui de la procédure unique.

## 15. Checklist de cadrage

- **Zones** : `backend/`, `scripts/`, `.github/workflows/`, `docs/`, `specs/`. `engine/`, `frontend/`, `landing/` :
  aucun fichier.
- **Dossiers interdits** : `engine/**`, `frontend/src/**`, les six fichiers de référence d'origine,
  `engine/CONTRACT_VERSION`, les trois `*ContractSchemaTest`, `.github/workflows/deploy.yml`,
  `StructureRestorer.php`, `StructureSnapshotter.php`.
- **Aucun refactoring hors périmètre** : confirmé. Non touchés : conversions de date, « lundi de la semaine »,
  miroirs côté écran, E4 à E7, C1 à C5, `StructureAnchor`, `StructureSnapshotter`, `StructureRestorer`, l'ordre des
  gardes de `transition`, tout déplacement de fichier.
- **Axes §7.1 et NR** : *isolation* (lignes 11, 18, 24) ; *chaîne de génération* (lignes 1, 2) ; *sens des
  contraintes* (lignes 8 à 10) ; *cycle des plans* (ligne 5) ; *contrat avec le moteur* : non touché ; *périmètre
  engagé* : non touché ; *auth et adhésions* : une route d'écriture retirée (ligne 23).
- **`/security-review`** : **PR 3**, **PR 4b**, première PR du lot 3 (tables). Le drapeau démo est porté par la PR 5
  du lot 2.
- **Behat touchés** : `une-regle-de-categorie-suit-ses-equipes.feature` et `tests/Behat/CategoryRuleContext.php`,
  `supprimer-emporte-ce-qui-en-depend.feature`, `preparer-la-saison-suivante-reprend-la-structure.feature`,
  `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`. **Playwright : aucun.**
- **Feature qui prouve la zone** : `generation-du-planning-de-saison.feature`,
  `une-regle-de-categorie-suit-ses-equipes.feature`. Avant chaque merge : `make -C backend tests-complete` et ces
  features sur la stack d'épreuve.
- **Plafonds de temps** (`test-time-ceiling.json`) : estimations, non mesurées (`make ci-mesure PART=origin/lot/conception`
  à chaque PR). Aucune demande de relèvement ; le scénario de petit club avec génération n'est plus nécessaire
  (« Charger » n'est plus modifié).
- **Documentation** : `lot-conception-decoupage.md` (fiche du lot 1 ; conditions d'entrée des lots 3 et 5),
  `etat-des-lieux.md`, `lot-conception-filet.md` §9, `docs/ops/deploy.md` (purge seulement ; la procédure de mise en production unique est écrite par la dernière PR avant la PR finale),
  `blocking-tests.md`, `test-coverage-map.md`, `docs/security/rls.md`, `docs/security/rgpd.md:73`,
  `backend/docs/backend-inventory.md:112`, `backend/docs/constraints.md`,
  `backend/docs/constraint-config-keys.md`, `backend/docs/TENANT.md`, `docs/glossary.md`, `openapi-snapshot.json`,
  ADR neuf et `adr-index.md`, `backend/AGENTS.md`, `.claude/rules/backend.md`. (Numéros de ligne de cette liste :
  à relire.)
- **Recouvrements avec le lot 2** : voir §0.
- **Conditions de retour au fondateur** : §12.

## 16. Tranché par le plan

Attributs plutôt que table ou classe-liste ; `clubId` et `seasonId` hors de `#[RefersTo]` ; ordre de purge par les
seules références « meurt avec » ; `CascadePlan` gardé ; routes d'étiquettes conservées, écran intouché ;
identifiant d'étiquette = son nom ; commande de contrôle jetable ; « Charger », photo et restauration
inchangés dans ce lot ; rattachements d'étiquettes laissés tels quels dans la photo, la restauration et la
suppression jusqu'à la suppression des tables ; `team` comme étalon RLS ; `EngineRequestReferenceTest` bloquant ;
migration de suppression des tables irréversible ; classes neuves dans `backend/src/Structure/`.

**Laissé hors périmètre, volontairement** : déclarer ce qu'aucune purge ne lit ; brancher sur la déclaration les
listes que les lots 3 à 6 suppriment ou réécrivent ; réparer les équipes déjà sans catégorie ; le miroir des
étiquettes côté écran ; les conversions de date ; l'époque dans la clé de cache ; l'ordre des gardes de « préparer » ;
toute modification de « Charger ».
