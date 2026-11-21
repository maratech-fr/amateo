# Lot 2 · Droits, modules, crédits : plan d'exécution (tel que livré)

**Statut : version finale (v4 sur 4) ; plafond atteint. Plan validé par le fondateur le 2026-11-21 avec ses seize
décisions (§13). PR 1, 2, 3a, 3b et 4 livrées (#1423, #1425, #1426, #1427, #1428) ; il reste la PR 5.**
Lane **Full**. Branche cible : `lot/conception` ; une PR vers elle ne joue que « Lot Gate », qui rejoue le step
`Blocking suite` (`docs/ops/ci-runner.md` § Branche de lot) : ni Behat, ni e2e, ni `backend-tests`. Avant chaque
merge : `make -C backend tests-complete` et les features/specs de la zone sur la stack d'épreuve.
La fiche du lot est dans [`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 2 ; ce fichier dit
**comment**.

**Cadrage** : pas d'`oracle`, par décision du fondateur (fiche du découpage, §5). Trois passes de `smith` ; la
troisième ne remet pas l'architecture en cause. Ce qui restait ouvert est devenu les décisions du §13 ou les limites
du §14.

Les `fichier:ligne` ci-dessous datent du plan ; **les sections des PR 1 à 4 décrivent ce qui a été décidé et livré,
et leurs lignes ont bougé avec le code livré** (à relire avant de s'en servir). Les consignes de la PR 5 (§5, fin) ont
été vérifiées dans le code au 2026-11-21.

---

## 1. Besoin reformulé

1. Le comblement consomme un crédit ; un crédit se prend quand la sortie est obtenue.
2. Deux rôles seulement, colonne fermée, anciennes valeurs converties.
3. « Préparer la saison suivante » est refusé par le serveur avant le 1er mai.
4. Chaque fonction déclare une fois son module, son public, sa sortie payante, son envoi à un tiers ; un seul contrôle
   les lit ; le module porte « socle validé ».
5. Le régime de démonstration est déclaré une fois.

## 2. Ce que le lot 2 emprunte au lot 0

| Livrable du lot 0 | Usage par le lot 2 | Bloquant ? |
|---|---|---|
| Outil de preuve de la demande moteur | Ceinture, avant/après PR 4 et PR 5 | Non |
| Inventaire des scénarios par domaine | Fait ici pour le domaine « droits » (§3) | Non |
| Mesure du rendu PDF | Lot 10 | Non |

Vérifié : `ScheduleConstraintBuilder.php` et `MatchPlacementPayloadBuilder.php` ne lisent ni rôle, ni offre, ni
crédit, ni démonstration. **Le lot 2 avance sans le lot 0** (la fiche a été recalée, décision Q15). Preuve de repli :
`ContractSchemaTest`, `MatchPlacementContractSchemaTest`, `PayloadVersionMatchesContractVersionTest`,
`FillPreservesCopiesAndFillsGapsTest`. Seul couplage : les fichiers partagés (§12).

## 3. Constats vérifiés dans le code (au moment du plan)

**Crédits**
- Deux listes de routes : `CreditBudgetSubscriber.php:46-55`, `:65-71` ; `api_schedule_fill` absent des deux. La fuite
  existait. *(Livré en PR 4 : le subscriber est supprimé.)*
- Le refus « crédits épuisés » tirait sur `kernel.request` priorité 5, donc **avant** le contrôle de rôle des
  contrôleurs.
- Liste anti-abus : `ClubQuotaSubscriber.php`, même priorité 5. Le cap est compté avant le contrôleur, refus 409
  compris (docblock `:37-39`).
- Génération et régénération décomptaient au 202.
- Génération : `applyEngineResult` (`GenerateScheduleHandler.php`) appelle l'import, qui supprime et insère les
  créneaux puis fait son propre `flush` (`ScheduleResultImporter.php:81`), avant le `flush` du statut. Photo de
  structure après, non fatale ; Mercure ensuite.
- Toute erreur du handler est rattrapée : version « échouée », message acquitté, pas de nouvelle tentative. Si
  l'EntityManager est fermé, le handler sort sans rien écrire et la version reste « en cours » jusqu'à
  `app:schedules:reconcile-stuck`. Une redélivrance qui lit `COMPLETED` acquitte.
- Placement : transaction tenue sur la connexion (`PlaceMatchesHandler.php`) ; crédit décompté après le commit ;
  toute erreur → run « échoué », acquitté.
- Registre : `OutputCreditLedger`, un `UPDATE` par identifiant sur la connexion par défaut.
- PDF : nom de fichier fixe `schedule-<id>-<périmètre>[-coaches][-occupations].pdf` (`PdfGenerator.php:101-105`) ; c'est
  le worker de rendu qui écrit le fichier ; l'état vit sur le planning (`ExportPdfHandler.php`). L'écran attend 60 s
  au plus (`planning/queries.ts`). Le fichier se télécharge par son nom (`ExportPdfDownloadController.php`). PHP
  supprime déjà des rendus dans ce répertoire (`PurgeExportsCommand.php:226`).
- Un seul consommateur de file : aucune réplique déclarée (`docker-compose.yml:202`, `docker-compose.prod.yml:192`).
- Génération en vol : compte sans verrou, par saison (`RegenerateController.php:111-119`). Plafonds : 5 solves et 10
  PDF par heure et par club (`rate_limiter.yaml`). Pool Découverte : 10 (`migrations/Version20260810120000.php:31`).
- Écran : solde borné à 0 (`useCredits.ts:33`) ; la console affiche le compteur seul (`AdminDashboardPage.tsx:739`).
- Solde relu seulement en mode saison du wizard (`GenerateStep.tsx`) ; pas en mode période, ni après « Régénérer » ou
  « Combler », ni après un PDF, ni à l'événement terminal (`scheduleStream.ts`).

**Rôle**
- 71 appels `assertManager()` dans 51 fichiers (comptés au plan). 110 fichiers de contrôleur : 98 à routes, 12 sans
  route servis par 14 opérations d'API (`ClubResource.php`, `FixtureResource.php`, `CoachWishCampaignResource.php`,
  `VenuePeriodOverrideResource.php`, `ScheduleResource.php`).
- Trois tris de données par le rôle, les trois seuls `catch (AccessDeniedHttpException)` de `src` :
  `Basketball/OpponentLogoController.php`, `State/Provider/CoachWishCampaignStateProvider.php`,
  `EventListener/ClubQuotaSubscriber.php`. Un quatrième lit le rôle sans la garde : `EntryDeadlineOutlook.php`.
- Lectures réservées au gestionnaire : `GeocodeController.php`, `FfbbSallesController.php`,
  `CoachWishEmailPreviewController.php`, `FfbbRencontresController.php` ; substance écrite dans
  `MemberReadsAreSideEffectFreeTest.php:44-55`.
- Écritures ouvertes au Membre : `MemberWriteSweepTest.php:71-83`. Ce test ne balaie que les écritures (`:20-21`) ; il
  est dans le step bloquant.
- Ordre réel sur `kernel.request` : routeur 32 ; `PublicApiCredentialNeutralizer` 9 ; pare-feu 8 ;
  `TenantFilterListener` 7, qui lit l'adhésion et les saisons et pose `_club_id` ; `ApiRateLimitSubscriber` 6 ;
  `AdminCsrfListener` 6 ; `ClubQuotaSubscriber` 5 ; `CreditBudgetSubscriber` 5 (avant PR 4).
- `use_symfony_listeners` n'est pas activé (`config/packages/api_platform.yaml`) : la lecture d'une ressource se fait
  dans le contrôleur, et toute opération sans contrôleur reçoit `api_platform.symfony.main_controller`
  (`vendor/api-platform/metadata/Resource/Factory/MainControllerResourceMetadataCollectionFactory.php:44-50`).
- Routes compilées (artefact de dev `backend/var/cache/dev/url_matching_routes.php`) : la plupart portent
  `_api_resource_class` avec `main_controller` ; quatorze ont un contrôleur de l'application ; deux sont
  `api_platform.action.not_exposed` (`VenueClosureResource`, `TeamSoloBudgetResource`) ; aucune
  `api_platform.action.placeholder`. Dix routes appartiennent à API Platform : `api_doc`, `api_genid`,
  `api_validation_errors`, `api_entrypoint`, `api_jsonld_context` (sans classe de ressource) ; `_api_errors` et
  `_api_validation_errors_{problem,hydra,jsonapi,xml}` (classe de ressource sous `ApiPlatform\`).
- API Platform rend une erreur par une sous-requête sur le même chemin
  (`vendor/api-platform/symfony/EventListener/ErrorListener.php:81-116`).
- Routes publiques : `PublicApiRouteCatalog.php`. `/api/mercure/auth` est une route de club.
- Les contrôleurs `SeasonScopedWriteInterface` : leur garde tire sur `kernel.controller`
  (`SeasonReadonlyGuardListener.php`).
- Variantes sur le club de la session : `SeasonTransitionController`, `ResetSeasonController`, `MembershipController`,
  `ClubInvitationController`, `RgpdExportController`. Sur le club de l'URL : `ClubStateProcessor`, `TeamImportGate`,
  `ParcImport/ParcImportGate`, `FixtureImportGate`.
- L'écran lit un refus par `error ?? message ?? detail` (`frontend/src/shared/lib/errorMessage.ts`).

**Socle** : 21 appels, 18 fichiers (comptés au plan). Huit sont une condition du module matchs posée dans un
contrôleur : `FfbbEngagementsController.php:231`, `FfbbRencontresController.php:99,123`,
`ConflictCoachMailController.php:208`, `LeagueValidatedFixturesController.php:93`, `PlaceMatchesController.php:116`,
`ReviewFixtureDeviationController.php:67`, `ReviewFixturesController.php:61` *(à relire : ces fichiers ont reçu leurs
déclarations en PR 3a/3b)*. Une seule lecture est gardée (`FfbbRencontresController.php:99`).
`FixtureStateProcessor.php:86-92` laisse passer DELETE ; `EntryDeadlineOutlook.php` lit le socle comme donnée.
L'écran masque l'onglet (`AppLayout.tsx:143`).

**Rôles en base** : colonne texte (`ClubUser.php`, `ClubInvitation.php`) ; `owner` lu comme gestion
(`ClubUserRepository.php`, `ClubApprovalService.php`, `AdminClubRequestController.php`,
`frontend/src/shared/lib/roles.ts`). Tous les écrivains passent par l'enum. Migrations sur la connexion `admin`
(`docs/security/rls.md`, § Porte superadmin).

**1er mai** : fenêtre à l'écran seulement (`frontend/src/app/seasonTransition.ts`). Serveur : saison courante,
paiement, successeur. Gel d'horloge de dev global (`Clock/DevClockStore.php:23-29`) ; e2e en série
(`frontend/playwright.config.ts:10,13`).

**Démonstration** : une trentaine de fichiers de `backend/src` (hors entités) mentionnent le drapeau. Sur une fonction
HTTP : `ConflictCoachMailController.php:202-204`, `CoachWishCampaignActionController.php:92-94` (club) ;
`ClubInvitationController.php:79,145` (compte) ; `DeleteAccountController.php:49`, `AuthController.php` (compte).
Ailleurs : `PlanEntitlements.php`, `SeasonTransitionService.php`, `ManagerRoleRequestService.php`.
*(Lignes de `ClubInvitationController` et `DeleteAccountController` revérifiées le 2026-11-21 : `:79`, `:145`, `:49`.)*

**Bac à sable Behat** : le petit club est inscrit par l'API (`tests/Behat/SmallClubFixtureTrait.php:85-105`), donc non
démo et en Découverte ; il génère et comble déjà (`PeriodLockSmallClubContext.php`,
`VenueClosureSmallClubContext.php`).

**Filet existant** : `MemberWriteSweepTest`, `PlanEntitlementsTest`, `ClubQuotaTest`,
`MemberReadsAreSideEffectFreeTest`, `MemberRoleTest` (`docs/testing/blocking-tests.md`).

## 4. Zones et dossiers

- **Zones** : backend, frontend. Engine : rien.
- **Autorisés** : `backend/src/{Access (nouveau),Controller,EventListener,MessageHandler,Service,State,Enum,Entity,Repository,ApiResource,OpenApi}`,
  `backend/migrations`, `backend/tests`, `backend/features`, `frontend/src/{app,shared,features/planning,features/wizard/steps,features/club,features/matches,features/profile}`,
  `frontend/tests/e2e/season-transition.spec.ts`, docs de §11.
- **Interdits** : `engine/`, `ScheduleConstraintBuilder.php`, `MatchPlacementPayloadBuilder.php`, `EngineClient.php`,
  `ScheduleResultImporter.php`, `TenantFilterListener.php`, `security.yaml`, `PublicApiRouteCatalog.php`,
  `docker/pdf-worker/`, `landing/`, `system-pages/`.

## 5. Découpage : 6 PR, 5 chantiers

Ordre de merge : PR 1, PR 2, PR 3a, PR 3b, PR 4, PR 5. Le comblement est devenu payant à la PR 4, cinquième dans cet
ordre (décision Q14). La déclaration est scindée : 3a pose les déclarations sans rien changer, 3b active le contrôle
et retire les gardes. `tank` écrit les rouges, puis `neo` implémente. `/security-review` sur les six.

### PR 1 · Deux rôles, colonne fermée — LIVRÉE (#1423)

**Change**
- Migration (`Version20261120120000`) : `owner` → `admin` ; `editor`, `viewer` → `member` ;
  `CHECK (role IN ('admin','member'))` sur `club_user` et `club_invitation`. Elle compte les lignes converties et
  échoue sur toute autre valeur, invitations en attente comprises. Connexion `admin`.
- **`down()` retire les contraintes et ne restaure pas les valeurs.** L'ancien code redéployé reste sûr : il n'écrit
  que `admin` et `member`.
- `ClubUser::getRole()` rend un `ClubRole`. `MANAGEMENT_ROLES` disparaît des deux côtés, avec les littéraux de
  `ClubApprovalService` et `AdminClubRequestController`.

**Contrats** : `ClubRole::isManager(): bool`. Aucun champ d'API ne change. **Textes** : aucun.
**Preuve** : `MemberRoleTest`, bloquant : un ancien `owner` gère ; un ancien `editor` lit sans modifier ; la base
refuse `owner` ; l'API refuse une valeur hors liste (422) ; une invitation en attente convertie s'accepte.

### PR 2 · Le 1er mai côté serveur — LIVRÉE (#1425)

**Change**
- `SeasonTransitionService::transition` refuse (409) avant le 1er mai de l'année du pivot à venir : `(année de saison
  d'aujourd'hui + 1)-05-01`. Date par `ClockInterface`, simulée pour un club démo (décision Q6).
- Le serveur n'impose que la borne basse. `/api/me` sert cette date.
- À l'écran, `seasonPrepWindow` reste. Le menu reçoit la date servie. **Le bandeau garde son 15 mai**, calculé à
  l'écran à partir de la date servie (même mois, jour 15) : seuil d'affichage. **La borne haute reste à l'écran
  seulement.** Le littéral « 05-01 » disparaît.
- `/api/me` ne rend aucun verdict « ouvert » : paiement et offre restent deux refus distincts.
- Un club qui n'a pas préparé avant le 15 juillet attend le 1er mai suivant (décision Q7 : voulu).

**Contrats**
- `GET /api/me` → `seasonPreparationOpensOn: string (Y-m-d) | null`.
- `POST /api/seasons/{id}/transition` avant la date → `409 {"error": "La saison suivante se prépare à partir du 1er mai."}`.
  Ordre : rôle, saison courante, date, paiement, successeur.
- `SeasonTransitionService::preparationOpensOn(DateTimeImmutable $today): DateTimeImmutable`.
- `seasonPrepWindow(todayIso: string, seasons: SeasonBounds[], opensAt: string)`.

**Preuve** : feature `backend/features/la-saison-suivante-se-prepare-a-partir-du-1er-mai.feature`, Exemples : club réel
payé, club démo sous date simulée, club en retard après le 15 juillet. Les tests qui passent par l'horloge réelle
reçoivent une date fixée dans la fenêtre ; e2e `season-transition.spec.ts` fige l'horloge serveur
(`POST /api/dev/clock`) et la remet à zéro en fin de spec.

### PR 3a · Chaque fonction se déclare (aucun comportement ne change) — LIVRÉE (#1426)

**Contenu** : des attributs, un lecteur, un balayage. Pas d'écouteur.

**Six familles**, chaque route `^/api/` dans exactement une :
1. console (`/api/admin/`) ;
2. dev (`/api/dev/`) ;
3. publique (`PublicApiRouteCatalog::matches`, pages à jeton comprises) ;
4. cadre d'API Platform, **dix routes** : les cinq sans classe de ressource, nommées (`api_doc`, `api_genid`,
   `api_validation_errors`, `api_entrypoint`, `api_jsonld_context`), et toute route dont `_api_resource_class` est
   sous `ApiPlatform\` ;
5. compte : `#[AccountFunction]` ;
6. club : `#[ClubFunction]` ou `#[ClubResource]`, public obligatoire, aucun défaut tiré de la méthode HTTP.

**Laquelle l'emporte** (route portant `_api_resource_class` sous `App\`) :
- `_controller` = `api_platform.symfony.main_controller` → la déclaration de la ressource, lecture ou écriture selon
  la méthode ;
- `_controller` = `api_platform.action.not_exposed` → la déclaration de la ressource aussi (ces opérations répondent
  404 à tout le monde ; le balayage les nomme et ne les joue pas) ;
- tout autre `_controller` → la déclaration du contrôleur maison, obligatoire, jamais celle de la ressource. Les
  contrôleurs concernés : `ImportController`, `ImportTeamsAnalyzeController`, `ParcImportAnalyzeController`,
  `ParcImportExecuteController`, `ImportFixturesAnalyzeController`, `ImportFixturesController`,
  `CoachWishCampaignActionController`, `CoachWishRecipientController`, `VenuePeriodGridActionController`,
  `ExportPdfController`, `ExportXlsxController`, `GenerateScheduleController`.

**Contrats** (`backend/src/Access/`)
- `enum Module: string { CLUB = 'club'; PLANNING = 'planning'; MATCHES = 'matches' }` (décision Q10).
- `enum Audience { MEMBER; MANAGER }`.
- `#[ClubFunction(module: Module, audience: Audience)]`, classe ou méthode ; la méthode l'emporte.
- `#[ClubResource(module: Module, read: Audience, write: Audience)]`, classe de `ApiResource/`.
- `#[AccountFunction]`, classe ou méthode.
- `AccessDeclarationResolver::for(Request $request): ClubFunction|ClubResource|AccountFunction|null`,
  `::FRAMEWORK_ROUTES`, `::isFrameworkRoute(Request $request): bool`.

**Preuve** : `backend/tests/Security/AccessDeclarationSweepTest.php` (bloquant, axe auth & memberships), contre les
gardes à la main puis, après la 3b, contre le contrôle central. Décor obligatoire : club **payant, non démo, socle en
vigueur**, seaux de débit remis à zéro avant chaque appel (patron `MemberWriteSweepTest`). Oracles des écritures et des
lectures **écrits à la main** (`MANAGER_ONLY_READS`, partie de `MemberReadsAreSideEffectFreeTest.php:44-55`), jamais
dérivés des déclarations. **Aucun oracle de modules n'existe** : le balayage ne contrôle que l'audience (voir les
consignes de la PR 5, point a).

### PR 3b · Le contrôle s'active, les gardes à la main partent — LIVRÉE (#1427)

**Change**
- `ClubAccessListener` sur `kernel.request`, **priorité 4**, requête principale seulement. Il passe après le routeur,
  le pare-feu, le locataire (qui a déjà lu l'adhésion et la saison), le débit et les caps ; **avant tout contrôleur,
  donc avant la lecture de la ressource visée et avant la garde « saison archivée »**. Les sous-requêtes de rendu
  d'erreur sont ignorées.
- Il refuse en 403 une fonction « gestionnaire » appelée par un Membre, et une route hors des six familles.
- Les appels `assertManager()` disparaissent. Les trois tris restent et passent à `isManager()`. Le défaut de
  `AbstractStateProcessor` et ses surcharges disparaissent. Les variantes sur le club de la session migrent ; celles
  sur le club de l'URL restent.

**Contrats** : `ClubAccessListener` ; `ManagementAccessGuard::isManager(): bool`. Refus : « Cette action est réservée
aux gestionnaires. »

**Écarts de comportement, tous atteignables par un Membre en appel direct seulement**

| # | Où | Avant | Après |
|---|---|---|---|
| E1 | `SeasonTransitionController`, saison inconnue | 404 | 403 |
| E2 | Les contrôleurs `SeasonScopedWriteInterface` et `ConflictCoachMailController`, saison archivée | 409 | 403 |
| E3 | Écriture d'API sur identifiant inconnu ou corps invalide | 404 ou 422 | 403 |
| E4 | `MembershipController`, `ClubInvitationController`, `RgpdExportController`, et les 6 imports passant par `TeamImportGate`, `FixtureImportGate`, `ParcImportGate` | corps `{"error": "Accès refusé."}` ou `"Forbidden."` | corps `detail` : « Cette action est réservée aux gestionnaires. » |
| E5 | `RgpdExportController` | la tentative entame le seau `rgpd_export` | elle ne l'entame plus |

L'écran lit `error ?? message ?? detail` : même toast. Décision et écarts : `specs/courantes/etat-des-lieux.md` §2.

### PR 4 · Les crédits : sortie déclarée, prise à l'obtention, comblement compris — LIVRÉE (#1428)

**Change**
- La déclaration dit la sortie payante. `CreditBudgetSubscriber` est supprimé. **Le refus « crédits épuisés » passe
  dans `ClubAccessListener` (priorité 4), après le rôle** ; le décompte des sorties synchrones se fait à la réponse,
  par le même écouteur.
- Le comblement est une sortie : refus 403 à pool vide, avant mise en file (décision Q14).
- **Solves** : une transaction tenue sur la connexion (patron `PlaceMatchesHandler`, pas `wrapInTransaction`) s'ouvre
  avant `applyEngineResult` et se ferme après le décompte. Elle contient l'import des créneaux, le statut et le
  crédit. **La photo de structure et Mercure restent après le commit, hors transaction : voulu.**
- **Si le décompte échoue** (erreur SQL du registre) : tout est annulé, l'EntityManager reste ouvert, la version passe
  « échouée » avec « Schedule generation failed unexpectedly. Please regenerate. », le message est acquitté. Aucune
  nouvelle tentative, aucun crédit pris, un résultat valide du moteur est perdu ; le club relance (décision Q16 (a) :
  atomique). Si c'est un `flush` de l'ORM qui échoue, l'EntityManager se ferme et la version reste « en cours »
  jusqu'au rattrapage : comportement d'aujourd'hui, inchangé.
- **Placement : le décompte change de place** : il entre dans la transaction, avant le commit. Échec du décompte : run
  « échoué », acquitté.
- **PDF** : le rendu s'écrit sous un **nom temporaire** (le nom final suivi de `-tmp<32 caractères hexadécimaux
  aléatoires>`, accepté par le motif de purge et impossible à deviner). Puis une transaction : décompte strict,
  renommage vers le nom final, statut « prêt », URL. Le fichier final n'est remplacé qu'après un décompte réussi. Si
  le pool s'est vidé entre-temps : le fichier temporaire est supprimé, **le fichier final et l'URL ne sont pas
  touchés** ; le statut revient à « prêt » si une URL livrée existe, à « échec » sinon.
- Tableur et PDF des matchs : inchangés (décompte à la réponse).
- « Charger une version » sort du plafond de générations (décision Q13) : `ClubQuotaSubscriber` lit la sortie déclarée,
  ses constantes de noms de routes disparaissent.
- Écran : solde sur le bouton de comblement ; solde relu à chaque fin de sortie. Infobulle de la pastille : nomme le
  comblement (décision Q4). Message d'attente d'un PDF refusé faute de crédit : le toast actuel est gardé (décision Q3).

**Dépassement maximal du pool** (pool 10, compteur à 9), assumé (décision Q2 (a)) :

| Sortie | Dépassement possible | Borne |
|---|---|---|
| Génération, régénération, comblement | +1 par solve accepté pendant qu'une autre sortie est en vol | compteur à 13 au pire (5 solves par heure) |
| Placement | +1, existe aujourd'hui | — |
| PDF du planning | 0 | — |
| Tableur, PDF des matchs | +1 par paire simultanée, existe aujourd'hui | — |

**Écarts d'ordre**

| # | Où | Avant | Après |
|---|---|---|---|
| E9 | Club Découverte à sec et plafond horaire atteint | 403 ou 429, ordre non garanti (deux écouteurs en priorité 5) | 429 puis 403 |
| E10 | Membre d'un club Découverte à sec, sortie réservée au gestionnaire (générer, combler, placer) | 403, message des crédits | 403, message du rôle |

**Inchangé, écrit pour qu'on ne le prenne pas pour un écart** : le cap anti-abus (priorité 5) est entamé par un
gestionnaire avant tout refus du contrôle central (priorité 4) ; c'est déjà le cas aujourd'hui pour un refus 409
(`ClubQuotaSubscriber.php:37-39`).

**Contrats**
- `enum Output { SOLVE; MATCH_PLACEMENT; PLANNING_PDF; PLANNING_XLSX; MATCHES_PDF }`, `settledAtResponse(): bool`
  (vrai pour les deux derniers).
- `ClubFunction` gagne `output: ?Output = null`.
- `OutputCreditLedger::settle(string $clubId, ?string $seasonId, bool $strict = false): bool`, à appeler dans une
  transaction ouverte. Club non bridé → `true` sans écrire ; sinon `UPDATE club SET output_credits_used =
  output_credits_used + 1 WHERE id = :id`, avec en mode strict `AND output_credits_used < :max` ; zéro ligne touchée →
  `false`. `consume()` disparaît.
- `PdfGenerator::renderPending(...)`, `::deliver(string $pending, string $filename): void` (renommage),
  `::discard(string $pending): void`.
- Aucun champ de message, de payload, ni route.

**Textes** : « Combler automatiquement (N) » ; à 0, désactivé, raison « Vos crédits gratuits sont épuisés. » Refus
serveur : texte existant, déplacé tel quel.

**Preuve** : feature `backend/features/un-credit-se-prend-quand-la-sortie-est-obtenue.feature` (petit club de
`SmallClubFixtureTrait`) : le comblement réussi coûte 1 crédit ; pool vide → refus et rien en file ; échec ou
blocage → 0 crédit (Q1 (a)) ; PDF à l'obtention, échec → 0 ; deux PDF pour un crédit → un seul produit, le livré
reste ; offre payante, bêta, démo : jamais. NR de la perte provoqué **par le registre de crédits** (double qui lève à
`settle`) pour la génération et pour le placement ; `PlanEntitlementsTest`, `ClubQuotaTest` réécrits ou étendus.

### PR 5 · Le module matchs porte le socle ; la démonstration est déclarée — À FAIRE

**Change**
- Le module matchs dit sa condition ; une fonction dit si elle est refusée module fermé (explicite, faux par défaut).
  Les 8 appels des contrôleurs disparaissent. **Restent 13** : `FixtureStateProcessor.php:91`,
  `FixtureImportGate.php:66`, et les 11 du cycle de vie d'un plan. Aucune lecture nouvelle n'est fermée (décision
  Q9 (a) : les 8 fonctions).
- Une fonction dit si elle envoie à un tiers ou modifie le compte ; le contrôle refuse en démonstration. Disparaissent :
  `ConflictCoachMailController.php:202-204`, `CoachWishCampaignActionController.php:92-94`,
  `ClubInvitationController.php:79` et `:145`, `DeleteAccountController.php:49`, `AuthController.php` (les lectures
  du drapeau de compte) : 8 endroits, 5 fichiers. La distinction **club / compte est gardée** (décision Q11) : le
  club décide de ce qui sort vers un tiers, le compte garde ses deux protections. Les **trois textes** de refus en
  démonstration sont gardés (décision Q12).
- Passent par un objet unique : `PlanEntitlements` (4), `SeasonTransitionService`, `ManagerRoleRequestService`.
- **Restent** : traitements de nuit, console, cycle de vie de la démo, horloge.
- Ordre dans `ClubAccessListener` : rôle (403), démonstration (403), module fermé (409), crédit (403).
- Un Membre consomme un crédit du club (décision Q5, inchangé).

**Écarts d'ordre, atteignables en appel direct seulement**

| # | Où | Avant | Après |
|---|---|---|---|
| E6 | `ConflictCoachMailController`, gestionnaire, saison gelée à la main, club démo ou sans socle | 409 « saison archivée » | 403 démo, ou 409 socle (corps `detail`) |
| E7 | `FfbbRencontresController`, club sans code FFBB et sans socle | 422 | 409 socle |
| E8 | Les 8 fonctions, saison archivée et sans socle | 409 archivée | 409 socle |

`LeagueValidatedFixturesController` porte dix lignes entre rôle et socle : à lire par `tank`, écart éventuel à
ajouter.

**Contrats** : `ClubFunction` gagne `thirdParty: bool = false`, `refusedWhenModuleClosed: bool = false` ;
`AccountFunction(protectedInDemo: bool = false)` ; `Module::requiresSocleInForce(): bool` ;
`DemoRegime::{isUnlimited(Club), forbidsThirdPartySend(Club, ?User), protectsAccount(User)}: bool`. Refus : messages
actuels, 409 de `SocleGuard`.

**Comportement → scénario**

| Décision | Scénario |
|---|---|
| Module matchs fermé sans socle | étape ajoutée à `le-socle-commande-les-plans.feature`, Exemples : les 8 fonctions |
| En démonstration rien ne part vers un tiers | **à relire** : le plan nomme une feature neuve `en-demonstration-rien-ne-part-vers-un-tiers.feature`, mais `le-club-de-demonstration-n-envoie-rien-a-un-tiers.feature` existe déjà (lot 0, comportement actuel) ; l'étendre plutôt que dupliquer. Exemples : mail des conflits, liens, relance, invitation |

**Section tests pour `tank`** : extension du balayage (fonction qui envoie un mail à un tiers → `thirdParty`) ; abus
(club démo, chaque fonction `thirdParty` → 403, aucun mail en file ; socle rouvert, les 8 fonctions → 409) ; un cas par
écart ; `DemoWindowTest`, `ClubInvitationTest`, `AccountErasureTest` rejoués.

#### Consignes issues des revues (vérifiées dans le code le 2026-11-21)

a. **Une table de modules écrite à la main dans le balayage.** Aucun oracle de modules n'existe : `AccessDeclarationSweepTest`
   ne contrôle que l'audience (il ne manipule `Module` que pour fixer la liste des trois valeurs, vers `:268`).
   Écrire, comme pour les écritures et les lectures, une table (route ou fonction → module) à la main, jamais dérivée des
   attributs, sinon la PR 5 ne prouve pas qu'elle a classé juste.
b. **Reclasser sous le module Matchs les fonctions qui servent des matchs à déplacer, aujourd'hui sous Planning** :
   `VenueClosureImpactController` et `DeletionImpactController` sont déclarés `Module::PLANNING` ; leur réponse porte
   `placedFixtures` et `declaredFixtures` (`DeletionImpactController.php:137-138`). La ressource `VenueClosureResource`
   (déclarée `Module::PLANNING`, opération non exposée) est à examiner avec eux. Décision de classement à faire avant de
   brancher le module fermé : un module Matchs fermé ne doit pas rendre ces réponses incohérentes (**à relire** : lire
   leur comportement sans socle).
c. **Cohérence des imports d'équipes et de parc avec les ressources.** `ImportController`,
   `ImportTeamsAnalyzeController`, `ParcImportAnalyzeController`, `ParcImportExecuteController` sont déclarés
   `Module::CLUB` ; `TeamResource` et `VenueResource` (qu'ils alimentent) sont `Module::PLANNING`. Aligner les deux, ou
   écrire pourquoi ils diffèrent.
d. **`POST /api/matches/module-visit`** (`MatchModuleVisitController.php:68`) est déclarée `ClubFunction(module: MATCHES,
   audience: MEMBER)` (`:51`) : une fois « module fermé » actif, elle pourrait renvoyer 403. **Le front doit tolérer ce
   403** (c'est un envoi de télémétrie sans effet visible) : vérifier l'appelant côté `frontend/src/features/matches/`
   (**à relire**) et prévoir un test.
e. **Le catalogue public est un miroir de `security.yaml`**, déjà gardé par
   `tests/Security/PublicRouteCatalogMatchesAccessControlTest.php` : ne pas y toucher dans cette PR (`security.yaml` et
   `PublicApiRouteCatalog.php` sont interdits, §4).
f. **`FixtureImportGate.php:60` renvoie encore `{"error":"Forbidden."}`** (vérifié). L'écart E4 de la PR 3b ne l'a pas
   couvert pour ce chemin ; à reprendre ou à écrire comme limite quand l'import des matchs passe par le module fermé.

## 6. Carte des conséquences

| Comportement changé | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Rôles fermés | Migration ; les écrivains de rôle | `ManagementAccessGuard`, variantes, tris, `/api/me.role`, `roles.ts`, `MembersSection.tsx`, `ManagerRoleRequestService`, `AdminClubRequestController`, `ClubApprovalService` | Validation de l'enum | Contrainte de base |
| 1er mai | `SeasonTransitionService` | `SeasonTransitionController`, `SeasonSelector.tsx`, `SeasonTransitionBanner.tsx`, rappel par mail (non modifié) | Rôle, saison courante | Paiement, successeur |
| Rôle au contrôle unique | Rien | Toutes les fonctions de club | Routeur (32), pare-feu (8), locataire (7), débit (6), caps (5), crédit (5, avant la PR 4) | Saison archivée (`kernel.controller`), puis le contrôleur |
| Crédit du solve à l'obtention | `GenerateScheduleHandler`, par le registre, dans la transaction du résultat | `/api/me` → `useCredits.ts` → pastille, bandeau, « Régénérer (N) », « Combler (N) », menu d'export ; console (`AdminMonitoringService`) | Cap anti-abus (5), puis rôle et crédit (4) | Commit, photo de structure, Mercure, relecture de `["me"]` |
| Crédit du placement : après le commit → dans la transaction | `PlaceMatchesHandler` | Idem ; `PlaceMatchesController` (mode crédit) | Cap `match_placement`, verrou de placement | Commit, bascule Mercure, mail de fin |
| PDF : nom temporaire, renommage après décompte | `ExportPdfController`, `ExportPdfHandler`, `PdfGenerator` | Attente de l'écran, téléchargement par nom (`ExportPdfDownloadController`), purge (`PurgeExportsCommand`) | Cap 10 par heure | Publication du statut |
| Socle au module | Rien | 7 contrôleurs ; `AppLayout.tsx:143` | Cap, rôle, démonstration | Gardes du contrôleur |
| Démonstration déclarée | Rien | 5 fichiers ; `CoachMailBar.tsx`, `ProfilePage.tsx` | Cap, rôle | Module, crédit |

À dire à `neo` : redémarrer `messenger-worker` après tout changement de handler, `nginx` en dernier ;
`make -C backend rector` avant push ; rebâtir `frontend-tooling` avant les tests d'écran.

## 7. Parcours de bout en bout

1. **Comblement, Découverte à 3 crédits.** `/planning`, plan de période né de « Partir du planning de saison » (0
   crédit) → « Combler automatiquement (3) » → attente → V+1 → « (2) ». Inverse : supprimer la V+1 ne rend pas le
   crédit. Socle : refus. Blocage : bandeau, 0 crédit. Échec : 0 crédit.
2. **PDF, 1 crédit.** « Exporter », PDF → téléchargement → « (0) ». Rendu en échec : « Export impossible — réessayez. »,
   solde intact. Membre : il exporte, le crédit du club baisse. Deux exports en même temps (deux onglets) : périmètres
   différents, le premier se télécharge, le second attend 60 s puis affiche ce même toast (faux pour un refus de crédit,
   gardé : Q3) ; même périmètre, les deux écrans reçoivent le même fichier pour un crédit.
3. **1er mai.** Avant la date, l'entrée « Préparer la saison suivante » n'est pas affichée : refus atteignable par appel
   direct ou écran resté ouvert. Démo : horloge au 15 mai, l'entrée apparaît. Pas de geste inverse.
4. **Rôles.** `/club`, Membres : deux valeurs ; une ancienne ligne `owner` s'affiche déjà « gestionnaire ». Données
   anciennes seulement.
5. **Démonstration.** Conflits, barre d'envoi : bouton désactivé avec sa raison ; 403 au serveur. Inchangé.

## 8. Conception

| Objet | Le plus simple | Supprime | Existant semblable | Suppose en dur | Deuxième usage |
|---|---|---|---|---|---|
| Déclarations + écouteur | Une table centrale par nom de route tiendrait en un fichier ; écartée : la déclaration vit sur la fonction, et la table de test existe déjà | Appels `assertManager()`, défaut du processeur et surcharges, 2 listes de crédits, 8 appels au socle, 8 exceptions démo, `CreditBudgetSubscriber` | `SeasonScopedWriteInterface` ; `PublicApiRouteCatalog` (autre information, reste) ; `MemberWriteSweepTest::ROUTES` et la liste des lectures (oracles de test, restent) | Un club par requête ; une fonction, un module ; public à deux valeurs | Lot 8 : une branche qui lit `output`. Module salariés : une valeur. Offre qui ferme un module : place faite, non construite |
| `OutputCreditLedger::settle` | Une méthode dans la maison existante | Décompte au 202 ; `consume()` ; copie privée du placement | `OutputCreditLedger` | Un compteur par club ; 1 crédit par sortie | Lot 8 : compteur par ligne d'abonnement |
| Rendu PDF en deux temps | Trois méthodes sur le générateur existant | L'écriture directe sous le nom final | `PdfGenerator` | Un seul consommateur de file ; un fichier par planning et périmètre | Lot 10, export unique |
| Colonne `role` | Contrainte et type | `MANAGEMENT_ROLES` ×2, deux littéraux SQL | `ClubRole.php` | Deux valeurs | Troisième rôle : exclu ; une migration |
| Date d'ouverture | Une méthode et un champ | Le littéral « 05-01 » de l'écran | Préconditions de `SeasonTransitionService` | 1er mai, pivot du 15 juillet | Date par sport : une constante |
| `DemoRegime` | Une classe, trois questions | Les lectures directes sur 8 fichiers | `PlanEntitlements` ; `ClubClock.php` (reste) | Deux drapeaux, club et compte | Planning d'essai : autre régime |

## 9. Axes, abus, sécurité, tests impactés

- **Axes §7.1** : auth & memberships (PR 1, 3a, 3b, 5) ; generation pipeline (PR 4 : la transaction du résultat,
  génération et placement).
- **Abus** (tirés du code), tous avec un test nommé : comblement gratuit, sorties multiples sur un crédit, fichier livré
  remplacé par un rendu refusé, rendu refusé téléchargé par son nom, Membre qui déclenche une sortie ou un appel
  sortant, lecture réservée ouverte ou lecture ouverte fermée par erreur, bascule hors fenêtre, rôle hors liste, route
  hors familles, envoi à un tiers en démonstration.
- **`/security-review`** : obligatoire sur les six PR (accès, démonstration, horloge simulée, appel sortant vers la
  fédération en PR 3b, données d'un club écrites par la génération).
- **Behat de la zone** : trois features neuves (PR 2, 4, 5) ; rejouées : `le-socle-commande-les-plans`,
  `plan-de-periode-en-overlay`, `l-export-du-planning`, `placement-des-matchs`, `les-conflits-partent-aux-coachs`,
  `voeux-des-coachs`, `une-invitation-fait-entrer-dans-le-club`, `un-petit-club-se-genere-par-l-api`.
- **e2e** : `season-transition.spec.ts` modifié (PR 2) ; `member-read-only.spec.ts`, `journey.spec.ts`,
  `matches.spec.ts` rejoués.

## 10. Temps et contrôle visuel

- Plafonds (`test-time-ceiling.json`) : à lire dans le fichier ; mesure par `make ci-mesure PART=origin/lot/conception` à
  chaque PR. Les estimations du plan (non mesurées) : Behat +15 s pour la PR 5 ; frontend nul.
- **Contrôle visuel (`switch`)** : fait pour la PR 4 (bouton de comblement). PR 5 : aucun écran nommé.

## 11. Documentation

`specs/courantes/etat-des-lieux.md` ; `docs/testing/blocking-tests.md` ; `docs/testing/test-coverage-map.md` ;
`backend/docs/{generation-flow,schedule-generation-guide,backend-controllers,backend-inventory,backend-demo}.md` ;
`backend/AGENTS.md` ; `backend/docs/TENANT.md` si l'ordre des écouteurs y figure ; `specs/courantes/module-matchs-*.md`
qui citent `SocleGuard` ; `frontend/docs/frontend-features.md` ; `.claude/rules/frontend.md` §3 ;
`specs/evolution/lot-conception-decoupage.md` (fiche du lot 2, §6). `CLAUDE.md` et ADR : à confirmer au
`documentation-update` de la PR 5.

## 12. Fichiers probablement touchés par la PR 5

Recouvrement possible avec le lot 1 : `.github/workflows/ci.yml`, `docs/testing/blocking-tests.md`,
`docs/testing/test-coverage-map.md`, `specs/courantes/etat-des-lieux.md`, `specs/evolution/lot-conception-decoupage.md`,
`backend/behat.dist.php`.

Neufs : `Access/DemoRegime.php`, la feature et son contexte. Modifiés : `Access/{ClubFunction,AccountFunction,Module,ClubAccessListener}.php` ;
`Basketball/{FfbbEngagementsController,FfbbRencontresController}.php`, `ConflictCoachMailController.php`,
`LeagueValidatedFixturesController.php`, `PlaceMatchesController.php`, `ReviewFixtureDeviationController.php`,
`ReviewFixturesController.php` ; `CoachWishCampaignActionController.php`, `ClubInvitationController.php`,
`DeleteAccountController.php`, `AuthController.php` ; `Service/{ManagerRoleRequestService,SeasonTransitionService,PlanEntitlements}.php` ;
`features/le-socle-commande-les-plans.feature`. Aucun fichier de `engine/`.

## 13. Décisions du fondateur (2026-11-21)

| # | Question | Retenu |
|---|---|---|
| Q1 | Une génération qui échoue coûte-t-elle un crédit ? | **(a)** Seule une génération aboutie coûte |
| Q2 | Plusieurs sorties sur un seul crédit | **Accepté** (dépassement borné, comme le placement) |
| Q3 | Message d'un PDF refusé faute de crédit | **Toast actuel gardé** |
| Q4 | Infobulle de la pastille | **Nomme le comblement** |
| Q5 | Un Membre qui exporte | **Consomme un crédit du club** (inchangé) |
| Q6 | 1er mai et démonstration | **La date vaut pour la démo** (horloge simulée) |
| Q7 | Club en retard après le 15 juillet | **Voulu** : attend le 1er mai suivant |
| Q8 | Conversion des rôles | **`owner` → gestionnaire, `editor`/`viewer` → Membre**, sans retour |
| Q9 | Module matchs fermé sans socle | **(a)** les 8 fonctions |
| Q10 | Modules | **Trois** : Club, Planning (doléances comprises), Matchs |
| Q11 | « Rien ne sort vers un tiers » | **Distinction club / compte gardée** |
| Q12 | Messages en démonstration | **Trois textes gardés** |
| Q13 | « Charger une version » dans le plafond de générations | **Hors plafond** |
| Q14 | Échéance du comblement payant | **Fermé à la PR 4**, sans correctif immédiat |
| Q15 | Fiche du lot | **Recalée** (ne dépend plus du lot 0 ; 8 exceptions supprimées, 6 regroupées ; 8 appels du socle sur 21) |
| Q16 | Crédit et résultat dans la même opération | **(a)** atomique |

## 14. Limites écrites

- **Un seul consommateur de file.** La remise du statut du PDF (« prêt si une URL livrée existe ») lit puis écrit sans
  verrou, et le renommage suppose qu'aucun autre rendu du même nom final n'est en cours : sûr tant qu'il n'y a qu'un
  `messenger-worker`. Ajouter une réplique oblige à reprendre ce point.
- **Commit en échec après le renommage** (panne de base à cet instant) : le fichier final est remplacé sans crédit
  compté. Accepté : même périmètre, même planning, déjà payé une fois.
- **Dépassements du pool** : le tableau de la PR 4.
- **Échec d'un `flush` de l'ORM** pendant la transaction du résultat : la version reste « en cours » jusqu'au
  rattrapage, comme aujourd'hui.
- **Comptes de routes** : lus dans le cache de dev, un artefact. Le balayage de la PR 3a est la vérité.
- **Non vérifié** : nombre de lignes de rôle anciennes en production au moment de la migration ; durées de test.
- **Hors périmètre, volontairement** : 13 contrôles du socle, 4 contrôles de rôle sur le club de l'URL, les exclusions
  de démonstration des traitements de nuit.

## 15. Conditions de retour, hors scope

**Retour au fondateur si** (PR 5) : une ligne de `MemberWriteSweepTest` change de code hors écarts E1 à E8 ; la
caractérisation trouve une route dont le comportement ne se dit pas par « membre » ou « gestionnaire » ; le plafond
Behat ne tient pas ; quoi que ce soit touche un constructeur de la demande moteur.

**Aucun refactoring hors scope** : pas de déplacement des classes existantes, pas de reprise des gardes d'import (lot 10),
pas de cycle de vie des plans (lots 4 et 5), pas d'abonnement (lot 8), pas de `features`/`maxVenues` (P4-615).
