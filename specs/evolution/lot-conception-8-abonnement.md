# Lot 8 · L'abonnement : plan d'exécution

**Statut : version finale (v2 sur 4, validée par `smith`). Plan validé par le fondateur le 2026-11-22 avec ses six
décisions (§12). PR 1 et PR 2 livrées sur `lot/conception` (non déployées) ; PR 3 à faire.** Voie **Full**. Branche cible : `lot/conception` ; une PR
vers elle ne joue que « Lot Gate » (`docs/ops/ci-runner.md` § Branche de lot) : ni Behat, ni e2e, ni `backend-tests`. Avant
chaque merge : `make -C backend tests-complete` et les features/specs de la zone sur la stack d'épreuve. Exécutant :
`neo`, après les rouges de `tank`. Pas d'`oracle` (décision du fondateur, [`lot-conception-decoupage.md`](lot-conception-decoupage.md) §5.3).
La fiche du lot est dans [`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 8 ; ce fichier dit
**comment**.

Les `fichier:ligne` ont été relus à l'écriture du plan (2026-11-22) sur `lot/conception`. **Tous les `fichier:ligne` de
ce plan sont à relire à l'ouverture de la PR concernée** : le lot 3 réécrit encore des fichiers cités ici
(`SeasonTransitionService.php`, `ErasedClubPurger.php`). Les comptes (fichiers, lignes de test) sont des constats
datés : le balayage et `grep` font foi, pas le chiffre.

## 0. Ce qui est livré et ce qui ne l'est pas

- **Lots 0, 1 et 2 livrés** sur `lot/conception` (non déployés) ; le lot 8 ne dépend que du lot 2.
- **Lot 3** : PR 1, 2a, 2b et 3 livrées sur `lot/conception` ; PR 4 et 5 à faire. Le lot 3 modifie aussi `SeasonTransitionService.php`,
  `ErasedClubPurger.php`, `etat-des-lieux.md` et la fiche du découpage : **le lot 8 s'exécute après la PR 1 du lot 3**,
  pour qu'une seule PR soit ouverte à la fois sur ces fichiers.
- **Déploiement unique** : tout le lot conception part en une seule mise en production, à la fin, depuis
  `lot/conception`, sur GO du fondateur ([`lot-conception-decoupage.md`](lot-conception-decoupage.md) §6).

## 1. Besoin reformulé

1. L'abonnement devient une ligne par club et par saison (offre, palier, réglé ou non, date, origine). Les deux champs du club disparaissent.
2. Une seule offre payante à trois paliers (20, 34, illimité), avec une place pour le prix, vide. Découverte et Bêta restent à part.
3. Les actions de support écrivent cette ligne.
4. Un club au-dessus de son palier n'a plus de sorties (génération, comblement, placement, exports). La recopie de saison n'est pas bloquée.

## 2. Constats vérifiés dans le code

| # | Constat | Où |
|---|---|---|
| C1 | L'abonnement tient en deux champs du club : `planId` et `paidSeasonYear` | `backend/src/Entity/Club.php:60-62`, `:123-124` |
| C2 | Offre effective : offre stockée si l'année réglée couvre la saison LUE, sinon Découverte | `backend/src/Service/PlanEntitlements.php:48-57`, `:207-225` |
| C3 | La saison lue est celle de la requête : en-tête validé contre le club, sinon saison courante | `backend/src/EventListener/TenantFilterListener.php:143-179` ; `AuthController.php:259-277` ; `Access/ClubAccessListener.php:242-257` |
| C4 | Régler une année couvre toutes les précédentes, et l'offre vaut pour tout le club | `Command/SetClubPlanCommand.php:132-135` |
| C5 | Écrivains des deux champs : deux commandes de support, l'inscription, la route de dev, deux seeders, l'effacement du club | `SetClubPlanCommand.php:98-101`, `:132-135` ; `MarkNextSeasonPaidCommand.php:80-83` ; `ClubProvisioner.php:95` ; `DevSeasonPaymentController.php:53-55` ; `BcclSeeder.php:188-197` ; `LoadTestClubSeeder.php:194-195` ; `ErasedClubPurger.php:134` |
| C6 | Lecteurs : `PlanEntitlements`, la bascule de saison, la console, la ressource club | `SeasonTransitionService.php:102` ; `AdminMonitoringService.php:242`, `:273`, `:486-497` ; `ApiResource/ClubResource.php:145` |
| C7 | L'inscription pose l'année réglée sur tout club, Découverte compris | `ClubProvisioner.php:93-95` |
| C8 | « Marquer la saison suivante payée » ne lit pas l'offre, et la bascule ne lit que l'année. Un club en Découverte ainsi marqué peut donc basculer aujourd'hui. L'écran ne lit pas `entitlements.seasonTransition` | `MarkNextSeasonPaidCommand.php:76-83` ; `SeasonTransitionService.php:102` ; `frontend/src/shared/session/api.ts:57` (seule occurrence hors tests) |
| C9 | « Saison en cours » et « suivante » des commandes se calculent sur l'horloge, pas sur les saisons du club. La bascule vise `max(saison source + 1, année d'horloge + 1)` | `SetClubPlanCommand.php:128-129` ; `MarkNextSeasonPaidCommand.php:73-77` ; `SeasonTransitionService.php:93-96` |
| C10 | Un club qui ne bascule pas garde pour saison courante sa dernière saison commencée, et l'offre se juge sur l'année de cette saison : il garde son offre sans limite de durée | `Service/SeasonResolver.php:58-63` ; `PlanEntitlements.php:219-220` |
| C11 | Les commandes de support écrivent par la connexion par défaut, sans locataire. Ça marche parce que `club` n'a pas de `club_id` | `SetClubPlanCommand.php:35-37`, `:143-149` |
| C12 | La sortie d'une commande de support est jetée : un refus s'affiche « Support action failed. » sans raison | `AdminJob/ConsoleAdminJobExecutor.php:31` ; `AdminClubActionController.php:183-185` |
| C13 | Les sélecteurs de la console se dessinent depuis le schéma d'arguments, une condition par argument | `AdminClubActionController.php:55-80` ; `frontend/src/features/admin/AdminDashboardPage.tsx:759-771` |
| C14 | Cinq offres en base, dont trois payantes qui ne diffèrent que par le plafond d'équipes | `migrations/Version20260810120000.php:29-37`, `Version20261111120000.php:41-47` ; `PlanEntitlements.php:33` |
| C15 | Le plafond n'est lu qu'aux portes de création ; les équipes inactives y comptent | `TeamStateProcessor.php:274-277` ; `ParcImportExecutor.php:780-803` ; `FfbbExcelImporter.php:173-181` ; `FfbbTeamImporter.php:113-121` ; `PlanEntitlements.php:189-194` |
| C16 | Une équipe qui porte au moins un match ne se supprime pas (409) ; `isActive` reste libre | `TeamStateProcessor.php:57-66` ; `Service/TeamEngagementGuard.php` (`isEngaged`) |
| C17 | Désactiver une équipe ne la retire pas du planning : `isActive` est un attribut d'archivage sans effet sur le moteur | `ScheduleConstraintBuilder.php:1416-1419` (aucun filtre d'équipe sur `isActive` dans ce fichier ; chargement amont non lu en entier) |
| C18 | Trois gestes créent des équipes sans lire le plafond : « Préparer la saison suivante », « Charger une version » (supprime puis recrée les équipes de la photo), le changement d'offre | `SeasonTransitionService.php:49-57` ; `RegenerateFromVersionController.php:28-35`, `StructureRestorer.php:50`, `:268` ; `SetClubPlanCommand.php` |
| C19 | Sept fonctions déclarent une sortie payante ; le refus se juge au contrôle central, sur la saison de la requête | `GenerateScheduleController.php:38`, `RegenerateController.php:44`, `FillPeriodPlanController.php:57`, `PlaceMatchesController.php:66`, `ExportPdfController.php:22`, `ExportXlsxController.php:28`, `ExportMatchesPdfController.php:34` ; `ClubAccessListener.php:151-156` |
| C20 | Le filtre de saison cache le planning d'une autre saison (404) ; le worker prend le crédit sur la saison du planning ou du run | `Doctrine/Filter/SeasonFilter.php:27-35` ; `ExportPdfController.php:36-40` ; `GenerateScheduleHandler.php:375-377` ; `PlaceMatchesHandler.php:227` |
| C21 | Placement et PDF des matchs prennent la saison de la requête | `PlaceMatchesController.php:165` ; `ExportMatchesPdfController.php:53` |
| C22 | Les écrans ne grisent une sortie que par un hook commun, qui rend `null` pour une offre payante ; chaque écran porte sa propre raison affichée | `frontend/src/shared/credits/useCredits.ts:26-29` ; `PlanningPage.tsx:1152` |
| C23 | L'app nomme Essentiel, Club, Grand club. La vitrine titre « Une seule offre. », nomme sa carte « Amateo, en entier » et ses paliers « Palier 1, 2, 3 » | `ClubPage.tsx:722-733` ; `AdminActionCatalog.php:52-58` ; `ClubAccessListener.php:154` ; `landing/offres.html:52`, `:118`, `:121-123` |
| C24 | La console affiche le nombre d'équipes ACTIVES, pas celui que le plafond compte | `AdminMonitoringService.php:285` |
| C25 | Toute table à `club_id` exige trois policies (`tenant_isolation`, `admin_all`, `readonly_tenant`) et un `GRANT SELECT` explicite au rôle de lecture ; toute table neuve se classe dans `ReadOnlyRoleTest` | `docs/security/rls.md:8-9`, `:34-52`, `:54-100` ; `tests/Security/ReadOnlyRoleTest.php:74-84` ; `migrations/Version20260930090000.php:96-110` |
| C26 | Le rôle applicatif reçoit par défaut toutes les écritures sur toute table neuve ; un retrait ciblé a un précédent | `migrations/Version20260703120000.php:88` ; `Version20260828170000.php:35-40` |
| C27 | Une famille de niveau club qui porte `clubId` part avec l'effacement du club, par la connexion par défaut | `Structure/FamilyCatalog.php:116-121` ; `ErasedClubPurger.php:80` |
| C28 | Champs d'abonnement morts sur le club : `billingCycle`, `planExpiresAt` | `Club.php:70-74` ; `ErasedClubPurger.php:135-136` |
| C29 | Une vingtaine de fichiers de test posent l'offre par accesseur ou en SQL | ex. `tests/Behat/CreditsContext.php:536`, `tests/Security/PlanEntitlementsTest.php:533-534` |

**Non vérifié** : la durée actuelle de chaque suite ; si la section « Offre » de `/club` entre dans la capture `club.png` (`frontend/tests/e2e/visual-reference.spec.ts:181-185`) ; que toutes les lectures du placement et du PDF des matchs se bornent à la saison de la requête jusqu'au bout du constructeur.

## 3. Conception : les cinq réponses par objet

**Ce qui décide de la clé** : la saison suivante se règle avant d'exister (C9). La ligne est clé par (club, année de saison).

**Les deux années d'un geste de support**, calculées comme leurs lecteurs :
- « en cours » = l'année de la saison courante du club (celle de `/api/me`), ou l'année d'horloge s'il n'a pas de saison ;
- « suivante » = l'année que la bascule créera, `max(année en cours + 1, année d'horloge + 1)`.

L'horloge est celle du club (simulée pour une démonstration), lue par `ClockInterface`.

| Objet | Le plus simple | Supprime | Existant semblable | Suppose en dur | Deuxième usage |
|---|---|---|---|---|---|
| `ClubSubscription` (table `club_subscription`) | Une ligne par (club, année), écrite seulement par un geste. Aucune ligne = Découverte ; rien à l'inscription | `club.plan_id`, `club.paid_season_year`, leurs accesseurs, `ClubResource::$planId`, la voie « offre seule » de la commande | Les deux champs du club (C1) ; `admin_job_run` garde déjà qui a lancé le geste, donc pas d'acteur sur la ligne | Un abonnement par club et par saison ; dernier état seulement | Paiement en ligne : une origine de plus. Facture : le prix payé s'ajoute à la ligne |
| `SubscriptionPlanTier` (table `subscription_plan_tier`) | Trois lignes pour l'offre payante. Une énumération PHP serait plus simple ; écartée : les offres sont déjà des lignes (C14), et le prix doit pouvoir changer sans livraison de code. Aucun écran ni commande n'écrit ce prix dans ce lot : il se pose par une écriture SQL du propriétaire en production | Deux offres payantes sur trois, `subscription_plan.max_teams`, `PAID_LADDER`, le message « Contactez-nous » (inatteignable) | `SubscriptionPlan` | Un seul prix courant par palier, en centimes (Q6) | Palier qui ouvre un module : une colonne |
| Refus « au-dessus du palier » | Une branche du contrôle central, qui lit `output` | Rien | Le refus « crédits épuisés » (C19) | Jugé à la demande, sur la saison de la requête | Autre limite par palier : même branche |

**Accès en base à `club_subscription`**
- Trois policies du motif, plus `GRANT SELECT` au rôle de lecture (C25).
- **Un seul écrivain au sens de la base** : `INSERT` et `UPDATE` sont retirés au rôle applicatif (précédent C26). Seule la connexion propriétaire écrit une ligne. Le rôle applicatif lit, et supprime à l'effacement du club (C27).
- `ClubSubscriptionWriter` prend toujours la connexion `admin` ; aucun appelant ne choisit.
- Pourquoi la connexion propriétaire et pas la connexion par défaut avec le locataire posé : la session de la console ne pose jamais le locataire (`CLAUDE.md` §6) ; `reset-season` en est la seule exception écrite (`AdminClubActionController.php:175-181`) et le plan n'en ajoute pas. La console lit déjà par cette connexion (`AdminMonitoringService.php:499-505`, `AdminClubActionController.php:145`).
- `subscription_plan_tier` : pas de `club_id`, donc pas de RLS ; classée en liste blanche du rôle de lecture, à côté de `subscription_plan`.

## 4. Découpage : 3 PR, 3 chantiers

Ordre : PR 1, PR 2, PR 3. La scission est réelle : la PR 1 change où vit l'abonnement sans toucher un écran ; la PR 2 change le contrat d'API et deux écrans ; la PR 3 ajoute un refus. Une seule PR ouverte à la fois sur `lot/conception` : le lot 3 touche aussi `SeasonTransitionService.php`, `ErasedClubPurger.php`, `etat-des-lieux.md` et la fiche du découpage.

### PR 1 · « L'abonnement est une ligne par saison » (`neo`, backend seul)

**Livrée sur `lot/conception`, avec ces écarts au plan** :
- La conversion garde la saison en cours et la suivante seulement : un club réglé plus de deux saisons d'avance perd l'année au-delà de « suivante ». La migration écrit ces clubs (identifiants) dans son décompte, pour un rejeu à la main par `app:clubs:set-plan`.
- La date de règlement d'une ligne convertie est celle de la conversion.
- `ClubSubscriptionRepository::forSeasonYear` est une requête native, bornée par la clause `club_id` et par la RLS.
- Pour les tests, `config/services_test.yaml` substitue à l'écrivain le double `tests/Double/SameTransactionClubSubscriptionWriter` (`docs/testing/testing-strategy.md`).
- Noms de colonnes de §3 inchangés.

**Change**
- Migration (connexion `admin`) : crée la table, ses policies et ses droits ; convertit ; supprime les deux colonnes. Irréversible.
- Conversion : pour chaque club dont l'offre stockée n'est pas Découverte, une ligne réglée par saison existante couverte par l'année réglée, plus une ligne pour l'année « suivante » si elle est réglée. Origine « conversion ». Un club en Découverte n'a pas de ligne.
- La migration ne s'arrête sur aucune donnée. Elle écrit son décompte : clubs, lignes, clubs en Découverte dont une année à venir était marquée réglée.
- `PlanEntitlements` lit la ligne de l'année de la saison lue. Ligne absente ou Découverte = Découverte.
- Bascule : exige pour l'année cible une ligne réglée d'une offre autre que Découverte. **Une ligne Découverte n'ouvre jamais la bascule.** Texte du refus inchangé.
- `app:clubs:set-plan` : écrit la ligne de la saison nommée ; `--paid-season` obligatoire hors Découverte ; Découverte s'écrit sur la saison en cours, non réglée.
- `app:clubs:mark-next-season-paid` est **retirée** (décision Q4, §12) : la commande, l'action `mark-next-season-paid` du
  catalogue de la console (`AdminActionCatalog.php`) et leurs tests partent ; le geste « Offre » fait offre, palier et
  saison.
- Inscription : n'écrit plus rien (C7). Seeders et route de dev passent par l'écrivain.
- Console : mêmes clés servies (`plan`, `paidSeasonYear` = dernière année réglée, `effectivePlan`) ; aucun fichier d'écran.

**Écart de comportement** : un club en Découverte marqué « saison suivante payée » pouvait basculer (C8) ; il ne le peut plus. Conforme à la conception §7 ; à mesurer (§8).

**Contrats**
- `App\Entity\ClubSubscription` : `id`, `clubId`, `seasonYear: int`, `planId`, `paidAt: ?DateTimeImmutable`, `origin: SubscriptionOrigin`, dates ; unique (`club_id`, `season_year`) ; clés étrangères vers `club` (cascade) et `subscription_plan` (restreinte).
- `enum App\Enum\SubscriptionOrigin: string { SUPPORT = 'support'; CONVERSION = 'conversion'; SEED = 'seed' }`, fermée par un CHECK.
- `ClubSubscriptionRepository::forSeasonYear(string $clubId, int $seasonYear): ?ClubSubscription`.
- `ClubSubscriptionWriter::record(string $clubId, int $seasonYear, string $planCode, SubscriptionOrigin $origin): void` : insertion ou mise à jour ; réglée à l'instant, sauf Découverte.
- `ClubSubscriptionWriter::seasonYears(string $clubId, DateTimeImmutable $today): array{current: int, next: int}`.
- `PlanEntitlements::effectivePlanCode(?string $lineCode, bool $paid): string`.
- `GET /api/clubs/{id}` perd `planId`. `POST /api/dev/mark-season-paid` rend toujours `{paidSeasonYear: int}`.

**Texte** (description de l'action « Offre ») : « Attribue une offre au club pour une saison, en cours ou suivante, et la marque réglée. Découverte se pose seule, sur la saison en cours. »

**Section tests pour `tank`** (rouges avant `neo`)
- Promesse : l'offre d'une saison ne dépend que de sa ligne. Feature neuve `backend/features/l-abonnement-se-regle-par-saison.feature`, offre posée par la vraie commande (`tests/Behat/BaseContext.php:121`).
- Promesse : la conversion ne change l'offre effective d'aucun club, sauf l'écart écrit. `tests/Integration/Migration/SubscriptionLineMigrationTest.php`, cas : Bêta réglée ; Découverte ; offre posée sans année ; année réglée deux ans en avance ; Découverte marquée payée.
- Promesse : les deux années d'un geste sont celles des lecteurs. Un cas : club resté sur sa saison passée.
- NR isolation (bloquants, ils prennent la table d'office) : `RlsIsolationTest`, `ReadOnlyRoleTest`, `PurgeCompletenessTest`, `FamilyDeclarationSweepTest`. Un cas nommé dans `PlanEntitlementsTest` : la ligne d'un club ne donne rien à un autre.
- Abus : le rôle applicatif ne peut ni créer ni modifier une ligne, locataire posé compris (cas dans `RlsIsolationTest`) ; `ClubBillingNotClientWritableTest` adapté.
- NR accès : `AccessDeclarationSweepTest` vert sans nouvelle route.
- Un trait `tests/Support/SubscribesClub.php` pose une ligne par l'écrivain ; `neo` y bascule mécaniquement les fichiers de C29.

### PR 2 · « Une offre payante, trois paliers » (`neo`, backend et écrans)

**Livrée sur `lot/conception`, avec ces écarts au plan** :
- Défaut FERMÉ du plafond : une ligne d'une offre à paliers dont le palier est absent ou n'est pas de cette offre se lit Découverte (`PlanEntitlements::readLine`), jamais « sans plafond ».
- Le rôle applicatif perd `INSERT`, `UPDATE`, `DELETE` et `TRUNCATE` sur `subscription_plan` ET `subscription_plan_tier` (la garde de `RlsIsolationTest` vaut pour toute table de plateforme) ; `priority_tier`, dont aucune migration ne sème les rangs, y reste une exclusion nommée.
- Intégrité en base : clé composite `(tier_id, plan_id)` et déclencheur « offre à paliers ⇒ palier » sur `club_subscription`.
- Des migrations antérieures appelaient `throwIrreversibleMigration(` (inexistante) au lieu de `throwIrreversibleMigrationException(` : corrigées. `migrations/` est hors du périmètre de PHPStan ; `MigrationsCallOnlyExistingMethodsTest` en tient lieu.
- Limite : pour un club de démonstration à date simulée, la console calcule « aujourd'hui » sur l'horloge réelle, donc `nextSubscription` peut différer de l'année que `set-plan --paid-season next` écrit (`specs/courantes/superadmin-auth.md`).
- Le double de test porte le palier (fonction à cinq arguments, l'ancienne supprimée : `docs/testing/testing-strategy.md`).

**Change**
- Migration : la ligne Essentiel devient l'offre payante unique ; trois paliers créés, lisibles par le rôle de lecture ; les lignes Club et Grand club passent à cette offre avec leur palier ; les deux offres partent ; `max_teams` quitte l'offre.
- Le plafond se lit sur le palier de la ligne. Découverte et Bêta n'ont pas de palier.
- Action « Offre » : un sélecteur « Palier », exigé pour l'offre payante, interdit sinon (porté par le schéma, donc refus 400 lisible, C12 et C13).
- `/club`, section Offre : une carte Découverte, une carte de l'offre payante avec ses trois paliers.
- Console, ligne d'un club : offre, palier, date et origine du règlement, **nombre d'équipes que le plafond compte** (C24) ; la saison suivante si elle a une ligne. Le texte « posée — saison non réglée » disparaît.

**Contrats**
- `App\Entity\SubscriptionPlanTier` : `id`, `planId`, `code` (unique), `position`, `maxTeams` (0 = illimité), `priceCents: ?int` (jamais servi par l'API).
- `ClubSubscription` gagne `tierId: ?string` ; `ClubSubscriptionWriter::record` gagne `?string $tierCode` après `$planCode`.
- Codes : offre `complete` ; paliers `palier-1`, `palier-2`, `palier-3`.
- `GET /api/subscription_plans` : chaque offre gagne `tiers: list<{code: string, position: int, maxTeams: int}>`, perd `maxTeams`.
- `/api/me`, `entitlements` gagne `tierCode: string | null`. Le plafond reste servi par le seul `maxTeams` existant.
- `GET /api/admin/monitoring/clubs`, par club : `subscription` et `nextSubscription`, chacun `{seasonYear: int, planCode: string, planName: string, tierCode: string|null, paidAt: string|null, origin: string} | null`, et `teamsCounted: int` ; `plan` et `paidSeasonYear` partent.
- `app:clubs:set-plan --plan decouverte|complete|beta --tier palier-1|palier-2|palier-3 --paid-season current|next`.

**Textes** (les noms sont ceux de la décision Q1, §12)

| Cas | Texte |
|---|---|
| Carte « Votre offre », payant | « Offre complète · Palier 2 » puis « 28/34 équipes » |
| Carte de l'offre, trois rangées | « Palier 1 · Jusqu'à 20 équipes », « Palier 2 · De 21 à 34 équipes », « Palier 3 · 35 équipes et plus », chacune « Sur demande » |
| 21e équipe au palier 1 | « Votre palier permet 20 équipes — le palier suivant monte à 34. » |
| 35e équipe au palier 2 | « Votre palier permet 34 équipes — le palier suivant retire la limite. » |
| Import tableur au-delà | « N équipe(s) à créer dans le fichier, N place(s) restante(s) sur votre palier. Retirez des lignes ou passez au palier supérieur. » |
| Crédits épuisés | « Les 10 générations gratuites de votre club sont utilisées — l'offre complète les rend illimitées. » |
| Console, sélecteur Offre | « Découverte », « Offre complète », « Bêta » |
| Console, sélecteur Palier | « Palier 1 (jusqu'à 20 équipes) », « Palier 2 (21 à 34 équipes) », « Palier 3 (35 équipes et plus) » |
| Console, ligne du club | « Offre complète · Palier 2 · 28 équipes comptées », « réglée le 12/05/2027 (support) » ; « 2027-2028 : Offre complète · Palier 1, réglée » |

**Section tests pour `tank`**
- Promesse : chaque club garde son plafond après la fusion. `OfferTiersMigrationTest` réécrit.
- Promesse : le plafond de création suit le palier. Les cas de plafond de `PlanEntitlementsTest` (`:341-447`) réécrits, pas multipliés.
- Abus : palier sur Découverte ou Bêta, palier absent sur l'offre payante. `AdminClubActionTest::testSetPlanValidatesTheClosedArgumentSchemaFailClosed` étendu.
- `SubscriptionPlanAttributionTest` : trois offres, bêta cachée, aucun prix servi. `ReadOnlyRoleTest` : la table des paliers classée.
- Écran : `ClubPage.test.tsx` et `AdminDashboardPage.test.tsx` adaptés (donnée affichée, geste « Offre » avec palier).

**Contrôle visuel (`switch`)** : `/club` section Offre pour un club en Découverte, au palier 1, en Bêta ; console, onglet Clubs et dialogue « Offre ». Si la section entre dans `club.png` : re-baseline (`docs/testing/testing-strategy.md` §1).

### PR 3 · « Au-dessus du palier, plus de sorties » (`neo`, backend et un bandeau)

**Change**
- Le contrôle central refuse 403 toute fonction à sortie déclarée quand la saison jugée compte plus d'équipes que son palier. Ordre : rôle, démonstration, module fermé, palier, crédit.
- **La saison qui juge est celle de la requête** (C3), une seule règle pour les sept fonctions.
  - Fonctions qui nomment un planning : le filtre de saison rend 404 un planning d'une autre saison (C20). La saison de la requête est donc celle du planning.
  - Placement et PDF des matchs : la sortie produite est celle de la saison de la requête (C21), non vérifié jusqu'au bout.
  - Le worker ne rejuge pas : il ne fait que prendre le crédit, sur la saison du planning (C20).
- `/api/me` sert le message du dépassement. Un bandeau permanent dans `AppLayout`, pour tous les membres, avec « Voir les offres ». **Les boutons ne sont pas grisés** (décision Q5, §12) : au clic sur « Générer » ou « Exporter »,
  le message de refus s'affiche ; `canGenerate`, `canPlaceMatches` et `canExportPdf` ne passent pas à faux au-dessus du palier.
- Jamais concernés : Découverte, Bêta, palier 3, démonstration.

**Contrats**
- `PlanEntitlements::teamCapMessage(Club $club, Season $season): ?string` (null = pas de dépassement), calculé depuis `teamCap()` et `teamsUsed()`.
- `entitlements.teamCapMessage: string | null`. Les nombres restent `teamsUsed` et `maxTeams`, déjà servis.
- Refus : `403`, corps `detail` = le message.
- `TeamCapBanner()` (`frontend/src/shared/credits/TeamCapBanner.tsx`, sans prop).

**Texte** (toujours au pluriel, le plus petit plafond est 20 ; toutes les équipes comptent, décision Q2) : « Votre club compte 23 équipes, votre palier en permet 20. Les générations, le placement des matchs et les exports sont suspendus : supprimez des équipes qui n'ont pas de match, ou changez de palier. »

**Section tests pour `tank`**
- Promesse : au-dessus du palier aucune sortie, et tout revient dès le retour sous le plafond. Feature neuve `backend/features/au-dessus-du-palier-plus-de-sorties.feature`. Décor : un club de 21 équipes posé en une insertion SQL.
- NR chaîne de génération : génération refusée = rien en file ; les features de génération existantes rejouées inchangées.
- NR accès, dans `PlanEntitlementsTest` (bloquant) : les 7 fonctions refusées ; un Membre reçoit le refus du rôle ; « Charger une version » et le téléchargement d'un PDF déjà produit restent ouverts.
- **Abus de saison**, dans `PlanEntitlementsTest` : club à deux saisons, l'ancienne en Bêta, la nouvelle au palier 1 avec 21 équipes. Pour chacune des 7 fonctions, l'en-tête de l'ancienne saison ne sert jamais une sortie de la nouvelle : 404 pour un planning nommé ; pour le placement et le PDF des matchs, la sortie ne contient que l'ancienne saison. Ce test lève le « non vérifié ».
- Écran : un test Vitest, le bandeau apparaît quand le serveur sert le message.

**Contrôle visuel (`switch`)** : le bandeau sur `/planning` et `/matchs`, gestionnaire et Membre.

## 5. Tableau comportement → scénario (brief de `tank`)

| # | PR | Décision | Scénario |
|---|---|---|---|
| 1 | 1 | Sans ligne, le club est en Découverte (10 crédits) | `l-abonnement-se-regle-par-saison.feature`, Exemples |
| 2 | 1 | Ligne Bêta réglée pour la saison en cours : sorties illimitées | même feature, Exemples |
| 3 | 1 | Ligne réglée pour la saison suivante seulement : la saison en cours reste en Découverte (chaque saison a son offre, Q3) | même feature, Exemples |
| 4 | 1 | Club de démonstration sans ligne : droits pleins | même feature, Exemples |
| 5 | 1 | Repasser en Découverte : la saison en cours retombe, la ligne de la saison suivante reste (geste inverse) | même feature, scénario |
| 6 | 1 | La bascule exige la ligne réglée de l'année cible ; une ligne Découverte ne l'ouvre pas | `la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`, Exemples ; décor adapté (`SeasonPreparationDateContext.php:65`) |
| 7 | 1 | Le renouvellement se fait par le geste « Offre » de la console : offre, palier et saison en un geste (le bouton « Marquer la saison suivante payée » est retiré, Q4) | même feature que 1, scénario |
| 8 | 2 | Le plafond de création suit le palier | `PlanEntitlementsTest` (logique à cas) |
| 9 | 3 | Au palier 1 avec 21 équipes : générer, régénérer, combler, placer, tableur, PDF du planning, PDF des matchs refusés avec le message | `au-dessus-du-palier-plus-de-sorties.feature`, 7 lignes d'Exemples |
| 10 | 3 | Consulter, charger une version, télécharger un PDF déjà produit restent ouverts | même feature, étape |
| 11 | 3 | Supprimer une équipe sans match (20) rouvre les sorties ; passer au palier 2 aussi (gestes inverses) | même feature, deux scénarios |
| 12 | 3 | Une équipe qui a un match ne se supprime pas (409) : seul le palier rouvre | même feature, scénario |
| 13 | 3 | Bêta, Découverte, démonstration avec 21 équipes : jamais refusés | même feature, Exemples |
| 14 | 3 | Les équipes désactivées comptent pour le palier (Q2) | même feature, Exemples |
| 15 | 3 | « Charger » une version de 21 équipes au palier 1 est accepté, puis les sorties sont refusées | même feature, scénario |

**Preuve = captures, sans test** : cartes de `/club`, ligne et dialogue de la console, bandeau.

## 6. Carte des conséquences

| Comportement changé | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| L'offre vit sur la ligne | `ClubSubscriptionWriter`, connexion propriétaire (commandes, route de dev, seeders, migration) | `PlanEntitlements` puis `/api/me`, le contrôle central, `OutputCreditLedger.php:55`, `PlaceMatchesController.php:167`, les 4 portes de création, `FeedbackContextEnricher.php:144`, `SeasonTransitionService`, `AdminMonitoringService` | Pare-feu de la console et jeton anti-rejeu (`AdminClubActionController.php:110-117`), forme et existence du club (`:138-148`) | Droits et policies de la table |
| Une saison réglée ne couvre plus les autres | Idem | Idem ; `useCredits.ts`, `CreditBadge`, `CreditsBanner`, `BetaBadge.tsx:42` | — | — |
| Une ligne Découverte n'ouvre pas la bascule | Idem | `SeasonTransitionService.php:102` | Rôle, saison courante, 1er mai | Successeur |
| Offres 5 → 3, plafond sur le palier | Migration | `SubscriptionPlanStateProvider`, `ClubPage.tsx:672-740`, `AdminActionCatalog` | — | — |
| Refus au-dessus du palier | Rien | Les 7 fonctions à sortie ; le bandeau | Plafond horaire (priorité 5), rôle, démonstration, module | Crédit, puis le contrôleur et le filtre de saison |

**Les chemins qui font naître un dépassement** (aucun n'est bloqué) :
- la baisse de palier par le support ;
- « Préparer la saison suivante » ;
- « Charger une version » (C18).

Dans une saison recopiée, l'import des matchs engage ensuite les équipes : elles ne se suppriment plus (C16), seul le palier rouvre les sorties.

À dire à `neo` : `make -C backend rector` avant push ; redémarrer `messenger-worker` puis `nginx` en dernier ; rebâtir `frontend-tooling` avant les tests d'écran ; exporter `specs/courantes/openapi-snapshot.json`.

## 7. Parcours de bout en bout

Les exemples de dépassement supposent un club à un palier payant : aucun club réel n'y était le 2026-11-20 (3 en Bêta, 1 en Découverte).

1. **Régler la saison suivante.** Console, onglet Clubs, « Actions », « Offre » : Offre complète, Palier 2, Saison suivante. La ligne 2027-2028 est écrite ; la saison 2026-2027 ne bouge pas. Dès que le club prépare 2027-2028 (à partir du 1er mai) et y travaille, il est au palier 2. Inverse : « Offre », Découverte, ne touche que la saison en cours ; aucun geste ne retire la ligne de la saison suivante.
2. **Baisser un palier en cours de saison.** « Offre », Offre complète, Palier 1, Saison en cours, pour un club de 23 équipes : accepté. À l'instant, bandeau et sorties refusées.
3. **Le gestionnaire au-dessus du palier.** `/planning`, « Régénérer » : refus et message. Il supprime 3 équipes sans match : le bandeau part. Une équipe qui a un match répond « Cette équipe joue en compétition… Elle ne peut plus être supprimée. » : il lui reste à changer de palier. Désactiver ne change rien (Q2).
4. **Nouvelle saison.** 30 équipes en Bêta, saison suivante réglée au palier 1. « Préparer la saison suivante » recopie les 30 équipes. Dans la nouvelle saison : bandeau « 30 équipes, votre palier en permet 20 ». Tant que les matchs ne sont pas importés, il peut supprimer ; après, non. L'ancienne saison garde ses sorties.
5. **Charger.** Palier 1, 20 équipes ; « Charger » une version qui en avait 23 : accepté ; bandeau ; « Régénérer » refusé.
6. **Création.** Palier 1, 20 équipes, « Ajouter une équipe » : refus 422 avec le texte de la PR 2.

## 8. Mesures à refaire par `link` (lecture seule, démonstrations exclues)

Le « 4 lignes » de la conception date du 2026-11-20. Le fondateur débloque la clé de lecture ; aucune tentative d'accès n'a été faite. La mesure se fait **deux fois** : maintenant, puis juste avant la mise en production, avec la copie de production n° 2 (rejeu de toutes les migrations), dont le décompte de la migration doit donner les mêmes nombres. Par club réel :
- le code de l'offre stockée et la dernière année réglée ;
- ses saisons (date de début) ;
- le nombre d'équipes par saison : total, actives, et celles qui ont au moins un match ;
- tout club sur Essentiel, Club ou Grand club ;
- tout club dont l'offre est posée sans année réglée ;
- **tout club en Découverte dont l'année réglée dépasse sa saison en cours** (il perdrait le droit de basculer, C8) ;
- tout club dont l'année réglée dépasse l'année « suivante ».

Attendu : 3 lignes Bêta, le club en Découverte sans ligne.

## 9. Checklist de cadrage

- **Zones** : backend, frontend. Engine, landing, system-pages : aucun fichier.
- **Autorisés** : `backend/src/{Entity,Enum,Repository,Service,Command,AdminJob,Access,ApiResource,State/Provider,Controller,Seed,Structure,OpenApi}`, `backend/migrations`, `backend/tests`, `backend/features`, `frontend/src/{features/club,features/admin,shared/credits,shared/session,app}`.
- **Interdits** : `engine/`, `ScheduleConstraintBuilder.php`, `MatchPlacementPayloadBuilder.php`, `EngineClient.php`, `TenantFilterListener.php`, `Doctrine/Filter/`, `security.yaml`, `landing/`.
- **Axes §7.1** : isolation entre clubs (PR 1), auth et memberships (PR 1 à 3), chaîne de génération (PR 3). `smith` est passé sur la v1.
- **Gate** : les cas neufs vivent dans des tests déjà au step bloquant (`PlanEntitlementsTest`, `RlsIsolationTest`, `ReadOnlyRoleTest`). Aucun step neuf.
- **`/security-review`** : systématique sur les trois PR. Sujets : isolation, auth, démonstration, horloge simulée lue par les commandes, commande lancée depuis la console sur la connexion propriétaire, rôle de lecture.
- **Behat de la zone** : deux features neuves ; rejouées `en-decouverte-une-sortie-coute-un-credit`, `un-credit-se-prend-quand-la-sortie-est-obtenue`, `la-saison-suivante-se-prepare-a-partir-du-1er-mai`, `preparer-la-saison-suivante-reprend-la-structure`, `un-petit-club-se-genere-par-l-api`.
- **e2e** : `season-transition.spec.ts` rejoué ; `visual-reference.spec.ts` selon le contrôle visuel. Aucun spec ne nomme une offre.
- **Plafonds de temps** : aucune estimation chiffrée, rien n'est mesuré. Mesure par `make ci-mesure PART=origin/lot/conception` à chaque PR. Leviers : décor en une insertion, Exemples d'un même scénario. Repli si Behat ne tient pas : les lignes 1 à 4 rejoignent `en-decouverte-une-sortie-coute-un-credit.feature`.
- **Documentation** : fiche du lot 8 et §6 du découpage ; `etat-des-lieux.md` §1.11 et §2 ; `superadmin-auth.md` ; `backend/docs/{commands,backend-controllers,backend-inventory}.md` ; `docs/testing/{test-coverage-map,blocking-tests}.md` ; `docs/security/rls.md` (table à écritures retirées, commandes sur la connexion propriétaire, paliers lisibles) ; `import-du-parc.md` et `module-matchs-radar-et-placement.md` (textes de plafond) ; `docs/ops/deploy.md` (ordre des migrations et décompte attendu, par la dernière PR du lot conception).
- **Retour au fondateur si** : la mesure trouve un club réel sur une offre payante, au-dessus d'un palier, ou en Découverte avec une année à venir réglée ; une ligne d'`AccessDeclarationSweepTest` change de code ; un plafond de suite ne tient pas ; le test d'abus de saison trouve une sortie servie ; un constructeur de la demande au moteur est touché.
- **Aucun refactoring hors scope.** Laissés de côté : `billingCycle`, `planExpiresAt`, `maxVenues`, `features` (ligne P4-615) ; le compteur de crédits reste par club ; pas d'écran d'historique ni de paiement.

## 10. Limites écrites

- **Un club qui ne renouvelle pas garde son offre sans limite de durée** sur sa dernière saison (C10). La conception §7 dit qu'il retombe en Découverte : ni le code d'aujourd'hui ni ce lot ne le font.
- Une sortie déjà en file quand le palier baisse aboutit : le worker ne rejuge pas.
- Un club au-dessus du palier qui clique « Générer » entame son plafond horaire avant le refus, comme pour les crédits.
- Baisser un palier sous le nombre d'équipes d'un club est accepté sans avertissement : la console montre le nombre compté, elle ne peut pas afficher une raison de refus (C12).
- « Charger » n'avertit pas qu'il mènera au-dessus du palier.
- Une ligne par saison garde le dernier état ; la suite des gestes se lit dans l'historique des actions.
- La date de règlement des lignes converties est celle de la conversion.
- Retirer une ligne écrite par erreur pour la saison suivante n'a pas de geste.
- Le prix d'un palier ne se pose que par une écriture SQL en production.

## 11. Tranché par le plan (contestable)

- La conception §27 annonce « 4 lignes » à créer ; le plan en crée 3 : le club en Découverte n'a pas de ligne.
- La clé de la ligne est l'année de saison.
- « En cours » et « suivante » suivent les saisons du club, plus l'horloge seule.
- Seule la connexion propriétaire écrit une ligne.
- Refus de dépassement en 403, jugé sur la saison de la requête.
- La console montre la saison en cours et la suivante, pas d'historique.
- Migrations irréversibles, qui ne s'arrêtent sur aucune donnée.

## 12. Décisions du fondateur (2026-11-22)

Les six questions du plan v2 sont tranchées ; la recommandation du plan a été retenue à chaque fois (réponse « A »).

1. **Q1 · Les noms dans l'application.** Les paliers s'appellent « Palier 1 · jusqu'à 20 équipes », « Palier 2 · de 21 à
   34 » et « Palier 3 · 35 et plus » ; l'offre payante unique s'appelle « Offre complète » (proposition acceptée : ce n'est
   pas un mot de la vitrine, qui nomme sa carte « Amateo, en entier »). Un nom de marque éventuel passerait par
   `App\Service\ProductIdentity` / `shared/lib/product.ts`, jamais en littéral.
2. **Q2 · Quelles équipes comptent.** **Toutes** comptent pour le palier, désactivées comprises, comme aujourd'hui pour
   créer une équipe. Raison : une équipe qui a un match ne se supprime pas (C16) et une équipe désactivée reste planifiée
   (C17) ; ne compter que les actives permettrait de rester sous un palier en désactivant des équipes tout en les
   planifiant. Exemple chiffré : un club au palier 2 (34 équipes) avec 36 équipes, dont 3 désactivées, toutes avec des
   matchs importés, est bloqué ; sa seule issue est le palier 3.
3. **Q3 · Chaque saison a son offre.** Régler la saison suivante ne change pas la saison en cours : la saison 2026-2027
   garde son offre jusqu'au bout, et 2027-2028 prend la nouvelle dès qu'elle est préparée. Pour offrir la fin de saison à
   un club en Découverte, un second geste « saison en cours ». Ce même geste sur un club en Bêta peut le mettre au-dessus
   de son palier sans autre issue que le changement de palier (Q2). Un club qui ne renouvelle pas garde son offre sur sa
   dernière saison, sans date de fin ; ce lot ne le change pas (§10).
4. **Q4 · Le bouton « Marquer la saison suivante payée » est retiré.** Il ne disait ni l'offre ni le palier, et il
   marchait sur tout club, Découverte compris (C8). Le geste « Offre » fait offre, palier et saison.
5. **Q5 · L'écran d'un club au-dessus de son palier.** Un bandeau permanent ; au clic sur « Générer » ou « Exporter », le
   message de refus. Les boutons ne sont pas grisés.
6. **Q6 · La place du prix.** Un seul prix par palier, que le fondateur change d'une année à l'autre ; le prix payé par un
   club sera noté sur sa ligne de saison le jour où la facture existe. Aucun écran n'écrit ni n'affiche le prix dans ce
   lot : l'écran dit « Sur demande ». Le poser demande une écriture en base à la main.

## 13. Fichiers clés

- `backend/src/Service/PlanEntitlements.php`
- `backend/src/Access/ClubAccessListener.php`
- `backend/src/Entity/Club.php`
- `backend/src/Entity/SubscriptionPlan.php`
- `backend/src/Command/SetClubPlanCommand.php`
- `backend/src/Command/MarkNextSeasonPaidCommand.php`
- `backend/src/AdminJob/AdminActionCatalog.php`
- `backend/src/AdminJob/ConsoleAdminJobExecutor.php`
- `backend/src/Service/AdminMonitoringService.php`
- `backend/src/Service/SeasonTransitionService.php`
- `backend/src/Service/SeasonResolver.php`
- `backend/src/Service/TeamEngagementGuard.php`
- `backend/src/State/Processor/TeamStateProcessor.php`
- `backend/src/Controller/RegenerateFromVersionController.php`
- `backend/src/Doctrine/Filter/SeasonFilter.php`
- `backend/src/Structure/FamilyCatalog.php`
- `backend/tests/Security/ReadOnlyRoleTest.php`
- `docs/security/rls.md`
- `frontend/src/features/club/ClubPage.tsx`
- `frontend/src/shared/credits/useCredits.ts`
- `landing/offres.html`
- `specs/evolution/conception-cible.md` (§7, §24, §27)

## 14. Changements depuis la version précédente

| Correction reçue | Section modifiée |
|---|---|
| B1 · une équipe engagée ne se supprime pas ; autres chemins de dépassement | C16, C17, C18 ; §6 (chemins de dépassement, « Charger ») ; §5 lignes 11, 12, 15 ; parcours 3, 4, 5 ; texte de la PR 3 ; Q2 réécrite ; §10 |
| M1 · Découverte et « saison suivante payée » | C7, C8, C9 ; §3 (définition des deux années) ; PR 1 (bascule, écart de comportement, contrat `seasonYears`, cas de conversion) ; §5 ligne 6 ; §8 ; Q4 |
| M2 · RLS incomplète | C25, C26 ; §3 « Accès en base » ; PR 1 et PR 2 (tests, `ReadOnlyRoleTest`) ; contrat de l'écrivain |
| M3 · quelle saison juge | C3, C20, C21 ; PR 3 (règle unique, test d'abus de saison) ; « non vérifié » borné au placement et au PDF des matchs |
| M4 · un club qui ne renouvelle pas garde son offre | C10 ; §10 ; une phrase dans Q3 |
| M5 · « Offre complète » ne vient pas de la vitrine | C23 ; Q1 |
| M6 · le prix « par saison » n'existait pas dans le contrat | §3 (tableau, un seul prix courant) ; §10 ; Q6 reformulée |
| « 4 lignes » contre 3 | §11 |
| 6 sorties nommées pour 7 fonctions | C19 ; §5 ligne 9 |
| Même information servie trois fois | Contrats PR 2 (`tierCode` seul) et PR 3 (`teamCapMessage` seul) |
| Q3 inexacte sur « jusqu'à l'été » et sur le second geste | Q3 ; parcours 1 |
| Q4 : « il échoue » alors qu'il réussit aujourd'hui | Q4 |
| Q5 : « six écrans » exagéré | C22 ; Q5 |
| +30 s Behat optimiste | §9 (aucun chiffre, mesure à chaque PR, leviers) ; décor de la PR 3 |
| « s'arrête sur un cas imprévu » | PR 1 (ne s'arrête pas, écrit son décompte) ; §8 (deux mesures, copie n° 2) ; §11 |
| Revue de sécurité : deux sujets | §9 |

| Décision retirée | Raison |
|---|---|
| `ClubSubscriptionWriter::record(Connection …)` | L'appelant choisissait la connexion : pas un écrivain unique au sens de la base |
| Le paramètre `bool $paid` de l'écrivain | Toute ligne écrite est réglée, sauf Découverte ; la colonne `paidAt` garde la place |
| `entitlements.tier.maxTeams` et l'objet `teamCapOverrun` | Doublons de `maxTeams` et `teamsUsed` |
| `canGenerate`, `canPlaceMatches`, `canExportPdf` à faux au-dessus du palier | Aucun écran ne les lit pour une offre payante (C22) ; décision Q5 : les boutons ne sont pas grisés |
| Refus de « saison suivante payée » dans la commande | Le refus serait muet à l'écran (C12) ; remplacé par la décision Q4 (le bouton est retiré) |
| Estimation « Behat +30 s » | Non mesurée |

**Correction non appliquée telle quelle** : `smith` cite `ClubAccessListener.php:262-275` ; le fichier lu fait 258 lignes, la lecture de la saison y est en `:242-257`.

**Laissé hors périmètre, volontairement** : champs d'abonnement morts du club, compteur de crédits par saison, écran d'historique, paiement et facture, fin d'offre d'un club qui ne renouvelle pas.