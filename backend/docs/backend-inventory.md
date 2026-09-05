# Backend Inventory

> Backward inventory of the existing backend (Symfony 7.4 + API Platform). This document
> describes what exists in the codebase at the time of verification — it is not a roadmap.

Last verified @ 2026-09-05 (P4-173). Entrée `SchedulePlan` (11bis) recalée : champ `staleness`
confronté à `App\ApiResource\SchedulePlanResource::$staleness`/`fromEntity`,
`App\Dto\SchedulePlanStaleness`, `App\Service\SchedulePlanStalenessResolver::stalenessFor`
(pointeur nul ou fenêtre révolue → `null`, mémoïsation par requête HTTP) et
`App\State\Provider\SchedulePlanStateProvider::mapEntityToOutput`. Reste du fichier non
re-vérifié cette passe — historique des recalages précédents (découpage début·milieu·fin,
etc.) : `git log -p --follow` ce fichier.
⚠ Vérification volontairement ÉTROITE au-delà de ce point : le reste de l'inventaire n'a pas été
reconfronté au code ce jour — historique des passes : `git log -p --follow backend/docs/backend-inventory.md`.
Un stamp REMPLACE, l'historique vit dans git.

---

## 1. Architecture Backend

### Stack

| Composant | Version / Détail |
|-----------|------------------|
| Langage | PHP 8.4 (`declare(strict_types=1)` dans tous les fichiers) |
| Framework | Symfony 7.4 (LTS ; `symfony/framework-bundle` verrouillé via `extra.symfony.require`, cf. `CLAUDE.md` §5) |
| API | API Platform ^4.3 (auto-génération CRUD + OpenAPI sous `/api/*`) |
| ORM | Doctrine (migrations dans `backend/migrations/`) |
| Auth | LexikJWTAuthenticationBundle (JWT stateless) |
| Real-time | Mercure (SSE) |
| Message bus | Symfony Messenger (transport Redis, worker dédié) |
| DB | PostgreSQL 16 |
| Cache / Lock | Redis 7 (appendonly) |

### Structure des dossiers

```
backend/
├── src/
│   ├── ApiResource/          # Ressources API Platform (DTOs + metadata) — liste : ls backend/src/ApiResource/
│   ├── Entity/               # Entités Doctrine (UUID string) — liste : ls backend/src/Entity/
│   ├── Controller/           # Contrôleurs custom — liste : ls backend/src/Controller/ (détail §3)
│   ├── MessageHandler/       # GenerateScheduleHandler, ExportPdfHandler
│   ├── Service/              # ScheduleConstraintBuilder, ScheduleResultImporter, ClubGenerationLock, ManualEditService, FfbbExcelImporter, ConstraintValidationService, ... — liste : ls backend/src/Service/
│   ├── State/Provider/       # State providers API Platform (par ressource)
│   ├── State/Processor/      # State processors API Platform (par ressource)
│   ├── EventListener/        # TenantFilterListener (résolution tenant : attribut / header / JWT)
│   ├── Doctrine/Filter/      # TenantFilter (Doctrine filter SQL)
│   ├── Enum/                 # ScheduleStatus, LockLevel, ...
│   ├── Dto/                  # Input DTOs (ClubInput, ScheduleInput, ...)
│   ├── Repository/           # Repositories Doctrine
│   ├── Command/              # Commandes CLI (imports holidays, seed league windows, purge/rappels saison, module démo) — liste : ls backend/src/Command/
│   ├── Storage/              # LogoStorage (interface) + LocalLogoStorage
│   ├── Security/             # JwtCookieFactory (SEC-16), AdminSessionCsrf/SuperAdminProvider/TotpService (SA0), UserChecker
│   ├── Message/ · MessageHandler/  # GenerateSchedule(Message|Handler), ExportPdf(Message|Handler)
│   ├── Mercure/              # ClubTopicUpdate (payload publié sur le topic club:{clubId}:schedule:{id})
│   ├── AdminJob/             # Catalogue + exécution des jobs planifiés de la console superadmin (SA3)
│   ├── Clock/                # DevClockStore (Redis) + SimulatedClock — horloge dev globale, distincte de Club::$demoToday (§3 Module démo)
│   ├── Seed/                 # BcclSeeder + BcclSeedProfile (club de démo permanent)
│   ├── Export/                # ScheduleExportData(Provider) — table plate consommée par l'export Excel/PDF
│   ├── OpenApi/               # CustomRoutesOpenApiFactory (composeur) + PathContributor/ (un par domaine, §3)
│   └── DataFixtures/         # Jeux de données de test
├── config/
│   ├── packages/security.yaml
│   ├── packages/api_platform.yaml
│   ├── packages/mercure.yaml
│   ├── packages/rate_limiter.yaml
│   └── routes.yaml
├── migrations/
├── tests/
└── public/index.php
```

### Config API Platform (`config/packages/api_platform.yaml`)

- Titre : `ClubScheduler API`, version `1.0.0` — ⚠ **résidu non renommé** : P5-15 (le produit s'appelle Amateo) a routé le nom produit à travers `ProductIdentity`/`shared/lib/product.ts` partout où c'est du texte LU PAR UN HUMAIN, mais ce titre vit dans `config/` — hors du périmètre balayé par les deux tests de garde (`src/` PHP, `src/` TS) — et n'a pas été touché ; reste ouvert (`specs/evolution/roadmap.md`).
- Formats supportés : `jsonld` (`application/ld+json`), `json` (`application/json`), `html` (`text/html`).
- Docs formats : OpenAPI (`application/vnd.openapi+json`), JSON-LD, HTML.
- `defaults.stateless: true` — toutes les opérations sont stateless.
- `cache_headers.vary` inclut `Content-Type`, `Authorization`, `Origin`.
- `normalization_context.skip_null_values: false` — une clé à `null` reste **présente** en
  `application/json` (le défaut d'API Platform l'omet ; `jsonld` l'incluait déjà). Le frontend
  compare en strict (`null === x`) : une clé absente arrive `undefined` et casse la lecture
  (`chosenScheduleId` null lu comme « validé », `parentEntryId` null → mère prise pour racine).
  Gardé par `JsonNullKeysTest` (phase1).

---

## 2. Resources API Platform

Les ressources sont définies dans `backend/src/ApiResource/` (liste exhaustive : `ls backend/src/ApiResource/`). Chaque ressource est un DTO
avec attributs `#[ApiResource]` déclarant les opérations CRUD standard
(`GetCollection`, `Get`, `Post`, `Put`, `Delete`), un `provider` et un `processor` personnalisés,
et une pagination explicite (détail et exceptions au défaut 30/page : §6). Les entités
Doctrine correspondantes vivent dans `backend/src/Entity/` et utilisent des UUID string.

| # | Resource (shortName) | Endpoint | Description | Notes |
|---|----------------------|---------|-------------|-------|
| 1 | Club | `/api/clubs` | Clubs / organisations | Opération custom `POST /clubs/{id}/import-teams` |
| 2 | Season | `/api/seasons` | Saisons sportives | |
| 3 | Team | `/api/teams` | Équipes (catégorie, priorité, créneaux) | |
| 4 | Venue | `/api/venues` | Salles / lieux de pratique | `address` (P2-53 RMM-8, nullable) — l'adresse saisie qu'on géocode en `latitude`/`longitude` via `GET /api/geocode` (§3) |
| 5 | Coach | `/api/coaches` | Entraîneurs | `isVehicled` (P2-53 RMM-8, bool, défaut false) — véhiculé → barème voiture d'une paire de gymnases, sinon barème à pied ; consommé par le solveur en PR-2 |
| 6 | User | `/api/users` | Utilisateurs | |
| 7 | ClubUser | *(plus d'API)* | Membres du club (rôles) — la ressource générique a été **RETIRÉE le 2026-08-20 (P4-103)** : lecture seule, elle listait `userId`/`role`/`isActive` **sans aucun consommateur**, le front passant par `/api/memberships/*`. Surface retirée, garantie conservée par `MemberRoleTest` | |
| 8 | Sport | `/api/sports` | Types de sports | |
| 9 | SportCategory | `/api/sport-categories` | Catégories d'âge | Depuis P2-54 PR-1 : `matchMinutes`/`warmupMinutes` nullables (null = défaut de famille, servi en lecture via `defaultMatchMinutes`/`defaultWarmupMinutes` — `MatchDurationResolver`) ; pilote l'empreinte du radar matchs |
| 10 | PriorityTier | `/api/priority-tiers` | Niveaux de priorité (S/A/B/C/D) | |
| 11 | SubscriptionPlan | `/api/subscription_plans` | Plans d'abonnement (facturation ; renommé depuis `Plan`/`/api/plans` — ADR-0002 lot A, le nom « plan » revient au domaine planning) | |
| 11bis | SchedulePlan | `/api/schedule_plans` | Conteneur nommé des versions d'une saison/période (ADR-0002) — filtres `calendarEntryId`, `type`. **POST** = le geste **« Adapter »** (`{calendarEntryId}`, `SchedulePlanStateProcessor`) : idempotent si la période a déjà son plan, 422 sur cutoff/mutualisation et sur une mère découpée, **409 `window_already_planned`** (P2-38 PR2, 2026-08-18, `App\Service\PeriodWindowUniquenessGuard`) si un AUTRE plan de période gouverne déjà tout ou partie de sa fenêtre — pris DANS le verrou de scope, jamais de destruction automatique, la famille (ancêtre racine `COALESCE(parent_entry_id, id)`) exclue. Ce verrou de scope est désormais DOUBLE (P4-172, 2026-09-04) : `SchedulePlanProvisioner::lockClubWindows(clubId, seasonId)` d'abord (grain club+saison — la garde compare des fenêtres du club ENTIER, un verrou par entrée seul ne sérialisait pas deux écrivains sur deux entrées différentes du même club), `lockPlanScope` ensuite. **« Adapter » d'un bloc une FERMETURE refuse en 422 si sa fenêtre se décompose en plus d'UN segment début·milieu·fin** (décision fondateur, 2026-09-05) : `App\Service\ClosureSegmentation::segments` (actionnable, tolère les semaines révolues en tête) sur `App\Service\WeekSegmentationRule::segments` — « Cette indisponibilité a une semaine entamée : adaptez-la par début, milieu, fin » ; les VACANCES ne sont pas concernées, `count(...) > 1` n'y est jamais évalué. **PUT** renomme (le nom vit sur le plan, inv. 12). **`staleness`** (P4-173, 2026-09-05) — `{manuallyEdited, constraintsChanged, resourcesChanged} \| null` : la péremption de la version **POINTÉE** (`chosenScheduleId`), servie pour que le cockpit dise « à régénérer » sans redériver la règle. `null` sans pointeur ou si `endDate` < aujourd'hui (horloge serveur, `ClockInterface`) — jamais de faux appel à l'action sur une fenêtre révolue. Calculé par `App\Service\SchedulePlanStalenessResolver` (`stalenessFor`) : une requête DQL `id IN (versions pointées du club)` mémoïsée par requête HTTP (clé = l'objet `Request`, patron `CalendarEntryRedatability`), servie sur item ET collection — anti-N+1. | |
| 12 | Schedule | `/api/schedules` | Générations de planning | ⚑ **TROIS marqueurs de PÉREMPTION** (le planning n'est pas faux, il décrit un état antérieur) : `manuallyEditedSinceGeneration` (F2b — un créneau déplacé à la main) et **`constraintsChangedSinceGeneration`** (2026-08-12 — une contrainte a changé depuis la génération). Le troisième, **`resourcesChangedSinceGeneration`** (P4-87, 2026-08-13), est posé par `ResourceChangeStaleScheduleListener` — venue/coach/team/tags → club+saison ; créneaux/réservations/overrides → **le plan que dit leur `schedule_plan_id`** (NULL = plan SEASON — la grille d'une période est une COPIE, ADR-0002, donc la grille saison ne périme JAMAIS un plan de période, gardé par test) ; `priority_tier` délibérément NON écouté (référentiel global immuable au runtime). Le second est posé par `ConstraintChangeStaleScheduleListener`, un **listener d'entité** sur `Constraint` (`postPersist`/`postUpdate`/`postRemove` + `postFlush`) : les contraintes s'écrivent depuis l'API, les entrées de calendrier datées et d'éventuelles commandes — **marquer par appelant garantissait d'en oublier un**. Portée : les plannings **COMPLETED** du club+saison de la contrainte, **plans validés INCLUS**. ⚠ **Le cas validé a été MESURÉ, pas supposé** (`ConstraintWriteOnValidatedPlanTest`) : valider → 200, puis écrire une contrainte → **201**, et le plan **reste validé** — rien ne lie l'écriture des contraintes à l'état du plan. Un planning validé périmé est le plus grave : c'est celui qu'on distribue aux coachs. Les deux marqueurs sont remis à `false` par tout import solveur (`ScheduleResultImporter`, foyer unique). NR `ConstraintChangeStaleScheduleTest`, **step de `blocking-tests`**.  `mercure: true` ; opérations custom `generate`, `export-pdf`, `export-xlsx` ; filtres `isActive` (booléen) et `seasonId` (exact). Les routes de cycle de vie (`validate`/`reopen`/`regenerate`/`regenerate-from`) sont des routes Symfony hors API Platform (§3). |
| 13 | ScheduleSlotTemplate | `/api/schedule_slot_templates` | Créneaux générés | `GET`/`GetCollection` **seulement** (2026-08-16) — POST/PUT/DELETE retirés, plus de processor ni de DTO d'entrée : le déplacement passe par `POST /api/schedule-slots/{id}/move` (sous verdict moteur), les verrous/contraintes par `manual-edit/*`. **`lockOrigin` depuis P2-2/F1 (2026-08-12)** — `RESERVATION` \| `MANUAL` \| `UNKNOWN`, **nullable** (`NULL` = pas de verrou : les 3 valeurs ne portent que sur un verrou RÉEL). **Server-authoritative** : aucune route ne le pose directement. Écrit aux 3 points d'origine — import du résultat solveur (`ScheduleResultImporter`), épinglage work-loop (`ManualEditService::applyLock`), pseudo-créneaux de réservation côté front (affichage seul, jamais persisté). ⚠ **`UNKNOWN` dit une IGNORANCE, pas une absence de verrou** — un verrou HARD sans réservation appariée reste indécidable et n'est **jamais deviné** (gardé par `LockOriginProvenanceTest`, **step de `blocking-tests`**). Le backfill de migration respecte **quel plan chaque réservation alimente** (base `NULL` → SEASON, overlay → son plan) pour ne pas fabriquer de faux `RESERVATION`. |
| 14 | ScheduleDiagnostic | `/api/schedule-diagnostics` | Erreurs / avertissements | |
| 15 | Constraint | `/api/constraints` | Contraintes permanentes | |
| 16 | TeamCoach | `/api/team-coaches` | Assignations entraîneur-équipe | |
| 17 | CoachPlayerMembership | `/api/coach-player-memberships` | Entraîneurs aussi joueurs | |
| 18 | TeamTag | `/api/team-tags` | Étiquettes d'équipe | |
| 19 | TeamTagAssignment | `/api/team-tag-assignments` | Assignations d'étiquettes | Sous RLS FORCE depuis `backend/migrations/Version20260807170000.php` (BCK-11) : colonne `club_id` (backfillée depuis `team.club_id`) + policy `tenant_isolation` — c'était la seule table liée à un tenant sans backstop base de données, l'isolation reposait sur le seul filtre Doctrine |
| 20 | VenueTrainingSlot | `/api/venue_training_slots` | Créneaux d'entraînement de salle — saisonniers (`schedulePlanId` null) ou d'un plan de période (copie du modèle de saison faite à la naissance du plan, #8 : jamais d'union entre les deux couches, l'anti-chevauchement est borné à une même couche) | |
| — | Reservation | `/api/reservations` | Réservation d'un créneau de salle pour une équipe (mutualisation : 2 équipes sur un créneau à capacité 2 ; matérialise le verrou pour l'overlay). GetCollection/Get/Post/Delete (pas de PUT — on supprime/recrée) ; ancrable à un plan de période (`schedulePlanId`). **P2-37 (2026-08-18), grain jour EFFECTIF depuis la même date (soir)** : POST refusé en 422 (`ReservationStateProcessor::assertVenueOpen`) si le gymnase est effectivement fermé-total sur la fenêtre ou si le jour visé est effectivement fermé (état composé par `PlanVenueClosures::effectiveStateForPlan` — incident déclaré × masque manuel du plan) — le message distingue indisponibilité DÉCLARÉE (fermeture nommée) de décochage MANUEL (masque `CLOSED` sans incident dessous, invite à rouvrir). Une réservation DÉJÀ posée n'est jamais supprimée ni déplacée par une fermeture ultérieure (décision fondateur : alerter, pas modifier passivement) ; l'alerte vit dans le prédicat partagé `OrphanPinGuard::unservedReservationIds` (miroir front `unservedReservationIds`, câblé au récapitulatif du wizard depuis P2-37 PR2, 2026-08-18 — détail `frontend-wizard.md` §5). **P2-60 PR-1 (2026-09-03) — règle (f) BUDGET SOLO, POSE seulement** : POST individuel refusé en 422 si le résidu solo de l'équipe R(T) = S(T) − B(T) est nul (`ReservationGroupOccupancy::assertSoloBudgetAllows`, `backend/src/Service/ReservationGroupOccupancy.php:200`, appelée par `assertIndividualReservationAllowed:159`) ou si ses réservations individuelles existantes atteignent déjà R(T) — une réservation qui COMPLÈTE une case bloc (`reservedSetMatchesABlock`) n'est jamais individuelle, donc jamais opposée au résidu. R(T) est calculé par la MAISON UNIQUE `SoloReservationBudget` (`backend/src/Service/SoloReservationBudget.php:33`, valeur `SoloBudget`) — mêmes B(T)/portée que la garde Σ du bloc ci-dessous. Lecture : voir ressource `TeamSoloBudget` plus bas. **P2-62 (2026-09-04) — troisième porte : SUPPRESSION, cascade au lieu d'une garde** — décision fondateur « on ne retire jamais une équipe d'un groupe, on supprime le groupe » : DELETE d'une réservation posée sur une case « bloc-complète » (`ReservationGroupOccupancy::blockCompleteCaseSiblings`, `backend/src/Service/ReservationGroupOccupancy.php:200`, discernement de la MAISON UNIQUE `reservationsOnGroupCompleteCases`, même portée socle/période) emporte TOUTES les réservations de cette case + leurs verrous HARD matérialisés (`ReservationStateProcessor::processDelete`), fermant l'angle mort ouvert par P2-60 PR-1 (l'ancien résidu qui aurait pu passer sous zéro pour les membres restants ne peut plus survenir, la case disparaît d'un bloc). Une réservation individuelle (case non bloc-complète) se supprime seule. Pas de route DELETE de groupe : une sœur déjà emportée par le même appel répond 404 (comportement d'API Platform standard sur une entité absente) — les boucles front le tolèrent (`frontend/docs/frontend-wizard.md`). Une trace RGPD `ENTITY_DELETED` est émise par réservation effectivement supprimée. | |
| — | SharedTrainingBlock | `/api/shared_training_blocks` | **P2-51 — LE BLOC est la SEULE notion de mutualisation** (le modèle groupe {équipes, K} `SharedTrainingGroup`/`/api/shared_training_groups` — P2-27 — est **retiré entièrement par PR-7, 2026-08-31** : entité, ressource, provider, processor supprimés, une migration convertit chaque groupe existant en bloc à l'identique avant DROP des tables `shared_training_group`/`shared_training_group_team`). Un ensemble d'équipes (2..10) qui se comporte comme **UNE équipe à part entière**, avec son propre `commonSessions` — les séances lui APPARTIENNENT, le solveur les PLACE (il ne les déduit pas d'une co-présence). GetCollection/Get/Post/Put/Delete. `schedulePlanId` nullable (NULL = socle saison, non-null = plan de période — ADR-0002 inv. 5, un plan de période **naît sans déclaration**, patron `Reservation`) — fait de PLAN, PAS de saison ; **copie socle→période à la NAISSANCE d'un plan de FERMETURE seulement** (affinage fondateur 2026-09-02, D10bis — instantané comme la grille, `SchedulePlanProvisioner::copySocleSharedBlocks`, gardé par `PeriodPlanBirthTest`), un plan de VACANCES naît toujours sans déclaration. **Multi-appartenance PERMISE** (pas d'unicité un-bloc-par-équipe — c'était la limite du groupe K) : la garde porte sur `(block_id, team_id)`, jamais sur `team_id` seul. 422 métier (processor `SharedTrainingBlockStateProcessor`) : équipe inconnue du club+saison ou inactive, ensemble d'équipes déjà déclaré (identique) dans la même portée, **garde centrale Σ** — pour chaque équipe, la somme des `commonSessions` de ses blocs de MÊME portée (celui écrit compris) ne dépasse pas ses séances/semaine EFFECTIVES (calculé par `SoloReservationBudget::forTeamsWithBlockSubstituted`, `backend/src/Service/SoloReservationBudget.php:33`, depuis P2-60 PR-1 — remplace l'ancien calcul local `EffectiveTeamSessions` en place, même formule). **P2-60 PR-1 (2026-09-03) — règle (f) BUDGET SOLO, porte DÉCLARATION/ÉDITION** : 422 si le nouveau B(T) — évalué sur l'état POST-changement de la portée (bloc édité substitué, `excludeBlockId` retiré) — ferait passer des réservations individuelles EXISTANTES au-dessus du résidu R(T) (`SharedTrainingBlockStateProcessor.php:167-188`, message nommant les équipes) ; sinon l'infaisabilité entrerait par l'autre porte (POST réservation, ci-dessus). Un bloc meurt **ENTIER** quand une équipe membre est supprimée (`SharedTrainingBlockPruneStep`) ; les réservations groupées « bloc-complètes » des AUTRES membres partent avec lui (`SharedBlockReservationPruneStep`, câblée AVANT `team_reservation` dans `CascadePlan::forTeam` — une réservation individuelle SURVIT) ; purgé avec le plan (`OverlayManager`) et avec la saison (`SeasonDataPurger`) ; toute écriture marque les plannings `COMPLETED` du bon périmètre périmés (`ResourceChangeStaleScheduleListener`). Deux tables sous RLS FORCE (`shared_training_block`/`shared_training_block_team`, migration `Version20260831120000`). **Payload backend⇄engine** : bloc `sharedBlocks` émis par `ScheduleConstraintBuilder::serializeSharedBlocks` (`{id, teamIds, commonSessions}`, filtré au roster), **consommé par le solveur** (modélisation LIAGE) et par `/validate-assignments` (miroir `_shared_block_move_violation`, refus nommé `shared_block_broken`) ; `CONTRACT_VERSION` **2.20**. | |
| — | TeamSoloBudget | `/api/team_solo_budgets` | **P2-60 PR-1 (2026-09-03) — lecture SEULE du budget solo**, `GetCollection` uniquement (`backend/src/ApiResource/TeamSoloBudgetResource.php:24`, pagination désactivée). Le budget de réservation INDIVIDUELLE de chaque équipe du club+saison, sur la portée `?schedulePlanId=` (absent/NULL = socle, UUID = plan de période, jamais d'union) : `effectiveSessions` (S(T)), `blockSessions` (B(T)), `residual` (R(T) = S(T) − B(T)), `individualUsed`, `inBlock`. Calcul délégué à `SoloReservationBudget::forScope` (`TeamSoloBudgetStateProvider.php:32`, MAISON UNIQUE de R, même que les deux gardes ci-dessus). `schedulePlanId` malformé → 400 ; plan inexistant ou d'un AUTRE club → 422 (même logique que `AssertsSchedulePlanExistsTrait`, ne révèle pas qu'il existe ailleurs). Backend pur, aucun appel moteur. **Consommé par le sélecteur de Réservation du wizard depuis P2-60 PR-2 (2026-09-03)** — `useTeamSoloBudgets`, détail `frontend/docs/frontend-wizard.md` §1 (item 4). | |
| — | VenuePeriodOverride | `/api/venue_period_overrides` (+ actions atomiques `POST /reset-grid` « reprendre la grille du planning principal » et `POST /clear-grid` « vider la grille » pour un gymnase — SEC-07, 422 si visées sur le plan de saison, 404 hors club) | Réglage sparse par (`schedulePlanId`, `venueId`) : deux réglages INDÉPENDANTS, chacun facultatif — `mode` NULLABLE (`DISABLED`\|`BLANK`\|hériter) et un masque manuel `dayOverrides` (jour ISO 1..7 → `OPEN`\|`CLOSED`, tri-état sparse) — pas de ligne = hériter la grille de saison. DÉSACTIVÉ conserve la grille mais sort le gymnase du payload engine ; VIERGE la vide ; DELETE (retour à hériter) purge mode ET masque puis recopie. **Indisponibilité INFORMATIVE (décision fondateur 2026-08-18, SUPPLANTE le régime P2-37 du matin)** : une fermeture datée (indisponibilité DÉRIVÉE, jamais stockée) PRÉ-REMPLIT un défaut que le masque contredit jour par jour — composition dans la maison unique `PlanVenueClosures::effectiveStateForPlan/Entry`, partagée par le gate/payload/`OrphanPinGuard`/réservations/radar. POST/PUT/DELETE sont de nouveau ACCEPTÉS sur un gymnase entièrement fermé (l'ancien refus 422, non réversible, est révisé). | |
| — | TeamPeriodOverride | `/api/team_period_overrides` | Surcharge d'une équipe pour une période (#8) : sparse par (`schedulePlanId`, `teamId`), `isActive` (équipe hors de l'overlay sans toucher son plan de base) + `sessionsPerWeek` nullable (volume réduit ; null = garder le saisonnier). Pas de ligne = hériter la saison. Le build overlay les lit ; le plan de base n'est jamais modifié |
| — | ConstraintPeriodOverride | `/api/constraint_period_overrides` | Activation d'une contrainte pour une période (#8) : sparse par (`schedulePlanId`, `constraintId`), `isActive`. Une ligne est une déviation EXPLICITE (elle l'emporte) ; sans ligne, la contrainte suit son propre `isActive`. Le build overlay OMET du payload les contraintes désactivées (`ScheduleConstraintBuilder`, simple filtre — zéro engine) ; ni le socle ni le `isActive` de la `Constraint` ne sont touchés |
| — | ImplicitRuleSetting | `/api/implicit_rule_settings/{ruleKey}` (identifiant = `ruleKey`, pas un uuid ; `?schedulePlanId=` cible un plan de période) | Réglage d'UNE des 4 règles implicites « bien-être » (intensité HARD/PREFERRED + seuil, contrat moteur 2.7) — RÉGLABLE PAR PORTÉE (`schedulePlanId` nullable, ADR-0002 inv. 5) : NULL = la saison (base — absence de ligne = défaut, zéro seeding) ; un UUID = un plan de période, qui reçoit à sa NAISSANCE une **copie matérialisée** de ses 4 lignes (`SchedulePlanProvisioner::materializeForPlan`, jamais sparse — patron de la copie de grille, #8) et n'est plus jamais recouché par une modification ultérieure de la saison. Un plan **legacy** (né avant cette fonctionnalité, zéro ligne) retombe sur la portée saison (repli vivant). GET **résolu** (toujours 4 entrées) ; PUT upserte (portée dans le corps, matérialise au premier réglage de plan) ; DELETE réinitialise — portée saison : supprime la ligne ; portée plan : **re-copie** la valeur saison courante (l'invariant 4 lignes du plan ne se brise jamais). Purgée avec le plan (`OverlayManager::purgePlanAnchoredSettings`) ; la transition de saison ne recopie que la portée saison. API stable depuis PR1 ; le front l'exerce par portée (saison ou plan de période) depuis PR2 — comportement écran : [`frontend-wizard.md`](../../frontend/docs/frontend-wizard.md) §4. | |
| — | CoachWish | `/api/coach_wishes` | Doléance coach pour une semaine de vacances (#10 C1) : par (équipe × semaine), nb de créneaux souhaités / jours indisponibles / commentaire / coche « traité ». **Souhait, jamais une contrainte** (zéro effet solveur). Ancrée à l'entrée MÈRE (`calendarEntryId`) + `weekStart` (lundi). Writes SEC-07 ; 422 hors période holiday / sur une semaine enfant / semaine non-lundi ou hors fenêtre / doublon (équipe, semaine). `coachId` nullable (suppression du coach → dé-attribution). Cascades : suppression de la mère, purge saison, suppression d'équipe (delete) / de coach (dé-attribution). |
| — | CoachWishCampaign | `/api/coach_wish_campaigns` (+ actions `POST /{id}/send-links` et `POST /{id}/remind` — SEC-07, #10 C3) | Campagne de collecte (#10 C2) : une par période de vacances (`calendarEntryId` UNIQUE), modifiable (semaines / équipes / deadline). Writes SEC-07 ; 422 doublon d'entrée / ancre non-holiday / semaine hors fenêtre. Sortie enrichie : compteurs radar (`totalCoachCount`/`respondedCoachCount`/`openWishCount`) + `lastReminderAt` + `coaches[{token, respondedAt, email, sentAt}]` (périmètre COURANT). Au POST/PUT, **sync des tokens** (un par coach des équipes retenues, jamais supprimé). DELETE emporte les tokens (FK) mais **laisse les `CoachWish`** (la todo-list C1 survit). Cascades : suppression de la mère, purge saison. **Actions C3** : `send-links` (corps `{coachIds?}`) envoie le lien par email aux coachs à email PAS ENCORE servis, ou aux `coachIds` ciblés (ajout tardif) — stampe `token.sentAt`, best-effort, filtre `FILTER_VALIDATE_EMAIL` ; `remind` relance les silencieux à email, **1×/jour Europe/Paris → 422 sinon**. |
| — | CoachWishToken | *(pas de ressource API)* | Lien personnel d'un coach (#10 C2) : `token` VARCHAR(64) **EN CLAIR** (`bin2hex(random_bytes(32))` — décision fondateur : « copier le lien » doit re-fonctionner ; privilège minuscule, borné au périmètre du token). `TenantOwnedInterface` (porte `club_id` pour poser le GUC RLS sur le chemin public sans JWT). RLS **hybride** : SELECT ouvert (lookup pré-GUC), écritures tenant. Consommé par le contrôleur public ci-dessous. |
| — | *(contrôleur public)* | `GET\|POST /api/coach-wishes/public/{token}` | Page publique de collecte SANS login (#10 C2) — `PublicCoachWishController`, route PUBLIC_ACCESS. Rate-limit PAR IP avant tout lookup (429 — l'IP réelle dépend de `trusted_proxies`, repli `private_ranges` ; derrière un proxy non déclaré le compartiment devient global, ce qui borne toujours l'abus) ; forme `^[0-9a-f]{64}$` sinon **404 byte-identique** (inconnu = malformé, anti-énumération) ; **jamais 401** ; GUC `app.club_id` posé depuis le token, **toujours relâché en `finally`** ; deadline passée → **410** (l'extension ranime le lien) ; saison en lecture seule → **410** aussi, via `CoachWishSeasonGuard` — le read-only est **dérivé du calendrier** (`SeasonResolver::isReadonlyAmong`, pivot 15-juillet) et pas du seul statut, qui n'est pas posé au roulement ; foyer UNIQUE partagé avec les actions management (409), après divergence en revue sécurité. GET rend le contexte pré-rempli (prénom, ses équipes ∩ campagne, semaines, doléances existantes) ; POST upsert borné au **périmètre du token** (ce coach, ses équipes, les semaines de la campagne) — une violation → 422 et **rien d'écrit** —, **cardinalité plafonnée** (`MAX_SUBMISSIONS` = 200, anti-abus O(N)) ; réponse = écrase + `done=false` (« à retraiter ») + `respondedAt` sur le token. |
| — | CalendarEntry | `/api/calendar-entries` | Cockpit temporel : périodes/événements (kind PERIOD/EVENT ; `parentEntryId` = enfant-**SEGMENT** d'une mère découpée). L'entrée porte le **FAIT** ; la RÉPONSE vit sur le plan — `overlayScheduleId` a été **supprimé** (ADR-0002 lot D-b), « période → version active » se dérive de `SchedulePlanProvisioner::chosenOfPeriodPlan`. **Un enfant-SEGMENT naît AVEC son plan** — un SEGMENT (P2-41, 2026-08-18) est un bloc de semaines calendaires PLEINES lun→dim CONTIGUËS (clamp saison admis aux deux bords), inclus dans les semaines qui couvrent la mère ; la semaine simple est le segment de taille 1 (`CalendarEntryStateProcessor::assertValidWeekChild`). **Une semaine-enfant d'une mère VACANCES doit COUVRIR son lundi→vendredi (D4, 2026-09-04)** : `assertValidWeekChild` délègue à `App\Service\HolidayWorkweekRule::covers` (service pur, statique) pour chaque semaine du segment — 422 nommé sinon ; le filtre ne porte que sur `CalendarEntryPeriodType::HOLIDAY`. Le week-end ne compte pas, un jour hors saison compte comme couvert (tolérance clamp des vacances d'été). **Miroir déclaré côté front** : `frontend/src/features/cockpit/lib/holidayWorkweek.ts::holidayCoversWorkweek` (remplace `dropFirst`), parité mécanique `holidayWorkweek.parity.json` gardée par `HolidayWorkweekMirrorParityTest` (groupe `contract`) — le front qualifie l'OFFRE (reprise vs fermeture/overlay), ce rail garde le POST. **Une semaine-enfant d'une mère FERMETURE doit être EXACTEMENT un segment début·milieu·fin (décision fondateur, 2026-09-05, remplace « enveloppe libre, semaines partielles admises » de P2-41 pour ce type seul)** : `assertValidWeekChild` délègue à `App\Service\ClosureSegmentation::childWindowIsValidSegment` — le découpage lui-même vient de `App\Service\WeekSegmentationRule::segments` (statique pure, algorithme GÉOMÉTRIQUE aux ruptures seulement, aucune règle solveur redérivée : une semaine que la mère ne couvre pas entièrement lun→dim est un bout `start`/`end`, un run de semaines pleines contiguës est un `middle` — un trou de vacances ou une fenêtre déjà planifiée par un AUTRE plan coupe le run en deux) — 422 nommé sinon (« ni une semaine complète isolée, ni un milieu tronqué »). Tolère les semaines RÉVOLUES en tête (la géométrie PLEINE ou l'offre ROGNÉE par l'horloge sont TOUTES DEUX acceptées — une naissant d'une période passée/du seed, l'autre d'une création au cockpit). **Miroir déclaré côté front** : `cockpit/lib/weekSegmentation.ts::weekSegments`, parité mécanique `WeekSegmentationMirrorParityTest` (groupe `contract`) sur `weekSegmentation.parity.json`, module au registre `FrontRederivationRegistryTest`. Les VACANCES ne sont pas concernées, elles gardent l'enveloppe libre P2-41 (scinder/fusionner à la main). Soumis à la même garde `PeriodWindowUniquenessGuard` que le POST `schedule_plans` (P2-38 PR2, 2026-08-18) : 409 `window_already_planned` si sa fenêtre recoupe déjà un AUTRE plan de période, sous le même verrou double club+saison puis entrée (P4-172, 2026-09-04 — club/saison lus sur la MÈRE). Déclarer une entrée RACINE (le FAIT — même une fermeture par-dessus une période déjà planifiée) reste, elle, toujours libre : la garde ne s'applique jamais à ce site. **`schoolHolidayId` auto-résolu** (fix terrain 2026-08-19, défaut 4) : une entrée `period`/`holiday` racine créée **sans lien explicite** reçoit celui de la vacance scolaire de la **zone du club** dont la fenêtre chevauche la sienne (`CalendarEntryStateProcessor::autoLinkedHolidayId`) — un lien explicite dans le body reste respecté tel quel ; sans zone club, sans chevauchement, ou hors kind holiday racine → `NULL`, comportement inchangé. Une seule vérité serveur : le radar dédoublonne sur ce champ (`RadarPanel`), le front n'arbitre rien. **Enums : absent → le défaut, PRÉSENT mais inconnu → 422** (AUD-BCK-14, 2026-08-21) — `kind`, `periodType` et `status` sont facultatifs et ont un défaut documenté (ÉVÉNEMENT · pas de période · ACTIF), mais une valeur inconnue ne se replie plus en silence : elle lève un 422 nommé (`AbstractStateProcessor::unknownEnumValue`, maison unique du libellé, partagée avec `ConstraintStateProcessor`). Filet inatteignable tant que l'`Assert\Choice` du DTO tient — c'est le propre d'un filet ; le jour où il saute, une PÉRIODE ne devient plus un ÉVÉNEMENT sans un mot (ADR-0002). | Opération custom conflits (§3) |
| — | Competition | `/api/competitions` | Compétitions FFBB (championnat/coupe/brassage) — module matchs palier A. **RMM-6 (2026-08-25)** : `entryDeadline` (date club, hors CRUD, écrite par le seul `POST /api/competitions/entry-deadlines` §3) + lecture additive résolue serveur `effectiveEntryDeadline`/`deadlineSource` (`club` prime, sinon le défaut communautaire `shared_competition_deadline` — joint par `ffbbCompetitionId`, une compétition non appariée n'a jamais de `community`) | season-scoped |
| — | Fixture | `/api/fixtures` | Rencontres (HOME/AWAY, placement domicile, `externalRef` = n° FBI). Champ additif en lecture `unplacedReason` (`venue_lost`\|`null`, RMM-10) — pourquoi un match dépointé est retourné « à placer », distinct de la raison volatile d'auto-placement ; posé par `FixtureVenueLossMarker` (foyer unique), effacé par `setVenueId` dès qu'une salle non-null est reposée | Ops custom conflits + import FBI (§3) |
| — | TeamLink | `/api/team_links` | Pont déclaré entre deux équipes — pas d'entité joueur, le gestionnaire déclare le lien (`teamAId < teamBId` normalisé par le processor) : `NOT_SIMULTANEOUS` (double projet, jamais en même temps) ou `BACK_TO_BACK` (enchaînées, implique `NOT_SIMULTANEOUS`), plus une `trainingIntensity` (`PREFERRED`\|`MANDATORY`) dédiée côté entraînement. Consommé par le module matchs (`MatchPlacementPayloadBuilder`, `MatchConflictDetector`) **et par le solveur d'entraînement** depuis le lot passerelles PR-2 (#707) : `ScheduleConstraintBuilder::serializeTeamLinks` émet le bloc `teamLinks` du payload (roster-filtré), `MANDATORY` devient un anti-chevauchement DUR entre les deux équipes (`engine/app/solver/constraints/targeting.py::add_team_link_constraints`), `PREFERRED` un malus SOFT dérivé du tier dans l'objectif (`objective/terms.py::add_team_link_penalty`) — un verrou HARD contre un verrou HARD ne pose aucune contrainte (INFEASIBLE muet évité) mais est diagnostiqué post-solve (`result_builder._diagnose_team_links`) | |
| — | TeamMatchHabit | `/api/team_match_habits` | Créneau de match habituel d'une équipe (un par jour de semaine, gymnase optionnel) — consommé par le module matchs (`MatchPlacementPayloadBuilder`, `AwayKickoffEstimator`) pour estimer les coups d'envoi à l'extérieur, **et par le solve hebdo** (`ScheduleConstraintBuilder::deriveMatchDay`, RMM-5 PR-3) pour dériver le `matchDay` (jour de repos) de l'équipe — toute écriture marque les plannings `COMPLETED` du club+saison périmés (`ResourceChangeStaleScheduleListener`) | |
| — | MatchSlotRotation | `/api/match_slot_rotations` | **RMM-5 : PR-1 (modèle, 2026-08-25) → PR-2 (SOFT `/place-matches`, même jour) → PR-3 (dérive le `matchDay` du solve hebdo, même jour).** Créneau de match PARTAGÉ (gymnase **NOT NULL** + jour ISO + heure, unicité `(club_id, season_id, venue_id, day_of_week, kickoff_time)`) occupé en alternance par 2..10 équipes ordonnées (`MatchSlotRotationTeam`, `position` purement FICTIF — aucun calendrier). Écriture par remplacement transactionnel des membres ; 409 sur course d'unicité de créneau. Toute écriture (rotation ou membre) marque les plannings `COMPLETED` du club+saison périmés (`ResourceChangeStaleScheduleListener`). Détail : [`module-matchs.md`](../../specs/courantes/module-matchs.md) § Rotation A/B. | |
| — | VenueTravelTime | `/api/venue_travel_times` (filtre `seasonId` exact) | **P2-53 RMM-8 PR-1 (2026-08-26).** Un barème de trajet ENTRE DEUX GYMNASES du club+saison : `drivingMinutes`/`walkingMinutes` (nullables, chacun indépendant) + `drivingSource`/`walkingSource` (`AUTO`\|`MANUAL`, ne portent une valeur que si la minute correspondante est renseignée). **Symétrique** : le processor normalise `venueAId < venueBId` (ordre lexical uuid) — un couple = une ligne (unique `club_id, season_id, venue_a_id, venue_b_id`). Toute valeur écrite par le CRUD est `MANUAL` — **jamais écrasée** par l'autofill (`POST /api/venue-travel-times/autofill`, §3 · `backend/docs/geo-api.md`). GetCollection/Get/Post/Put/Delete, écriture management (SEC-07), lecture ouverte au Membre. Cascade : suppr. d'un gymnase emporte ses barèmes (l'une ou l'autre colonne, patron `TeamLink`) ; purgée avec la saison ; suit la transition de saison (remap gymnase, minutes ET sources transcrites) ; STRUCTURE de club+saison — pas de `schedulePlanId`, la matrice nourrit tous les plans (patron `TeamLink`) ; toute écriture marque les plannings `COMPLETED` du club+saison périmés (`ResourceChangeStaleScheduleListener`). **Le solveur la lit** depuis PR-2 (bloc `venueTravelTimes`, contrat 2.16) — voir la ligne `VenueTravelRuleSetting` ci-dessous pour l'intensité. | |
| — | VenueTravelRuleSetting | `/api/venue_travel_rule_settings/{ruleKey}` (`ruleKey` FIXE `travelTime`) | **P2-53 RMM-8 PR-4, DERNIÈRE (2026-08-26).** Le levier d'intensité de la règle implicite `travelTime` — SINGLETON club+saison (`Entity/VenueTravelRuleSetting.php`, contrainte d'unicité `club_id`+`season_id`), vocabulaire **PREFERRED\|MANDATORY** des passerelles (`TeamLinkIntensity`), store DÉDIÉ plutôt qu'une 6ᵉ clé `ImplicitRuleSetting` (décision fermée : `etat-des-lieux.md` §2 — cette dernière est typée `ImplicitRuleIntensity` HARD/PREFERRED/OFF, vocabulaire bien-être). `Get`/`Put` seulement, une clé de chemin ≠ `travelTime` rend **404** des deux côtés (revue sécurité). GET résout (stocké ou défaut PREFERRED, `isDefault` servi), PUT upserte — écriture management (SEC-07), 409 saison archivée, 422 sur un vocabulaire bien-être. Recopie N+1 + purge suivent le patron de `VenueTravelTime`. Lu par `ScheduleConstraintBuilder::resolveTravelRuleIntensity`, émis dans `implicitRules.travelTime.intensity` du payload moteur. | |

> La numérotation n'est **pas** un décompte — liste exhaustive et à jour : `ls backend/src/ApiResource/`. Les tables globales de référence (`PublicHoliday`, `SchoolHolidayPeriod`, `LeagueMatchWindow`) sont exposées en **lecture seule via contrôleurs invokables** (§3), pas comme ressources CRUD.

Chaque ressource déclare sa pagination au niveau de l'attribut `#[ApiResource]` — le détail et
les exceptions au défaut `paginationEnabled: true, paginationItemsPerPage: 30` sont en §6. Les
réponses collections suivent le format JSON-LD (`hydra:member`, `hydra:totalItems`, `hydra:view`).

---

## 3. Custom Controllers

Les contrôleurs personnalisés vivent dans `backend/src/Controller/`. Certains sont déclarés
comme opérations custom API Platform (sur la ressource), d'autres comme routes Symfony
classiques avec `#[Route]`.

### OpenAPI des routes custom (`src/OpenApi/`, P4-138)

Une route `#[Route]` classique (pas une opération API Platform) est **invisible** de `/api/docs`
et du snapshot tant qu'elle n'est pas déclarée manuellement — `EveryCustomRouteIsDocumentedTest`
confronte le contrat au ROUTEUR dans les deux sens et rougit sur tout écart. Depuis P4-138
(2026-08-30), cette déclaration est éclatée **par domaine** :

- `CustomRoutesOpenApiFactory` (86 lignes) n'est plus qu'un **composeur** : il instancie
  `OpenApiSchemas` puis appelle `contribute(Paths $paths)` sur 16 `CustomPathContributor`, dans
  un ORDRE fixe et significatif (`Paths::addPath()` est un append, `openapi-snapshot.json` fige
  l'ordre exact des chemins).
- Chaque domaine cohérent a sa classe dans `backend/src/OpenApi/PathContributor/`
  (implémente `CustomPathContributor::contribute()`) : session/compte, admin (auth,
  supervision, jobs, support, journal, modération), FFBB (proxy, engagement), vacances/fériés,
  édition manuelle, trajet adverse, pages publiques à token, notes de version/feedback,
  saison/matchs, et un fourre-tout `UncoveredCustomPaths` pour ce qui n'a pas encore de domaine
  nommé.
- `OpenApiSchemas` est le **foyer unique** de `jsonBody()`/`jsonResponse()`, injecté dans chaque
  contributeur — aucun helper dupliqué. Trois helpers ne servent qu'**un seul** domaine
  (`coachWishSchema` dans `PublicTokenPaths`, `paginationSchema` dans `AdminJournalPaths`,
  `healthProbeSchema` dans `AdminMonitoringPaths`) : restés privés là, pas de foyer partagé pour
  un seul consommateur.
- **Deux contributeurs dépassent 300 lignes** (`AccountSessionPaths` 363, `UncoveredCustomPaths`
  444) et **le restent délibérément** : leurs routes s'entrelacent dans l'ordre exact du contrat,
  les découper davantage réordonnerait des chemins et casserait le snapshot. Décision fermée :
  [`etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §2.
- **Instruction opérationnelle** : une nouvelle route `#[Route]` custom s'ajoute à son
  `CustomPathContributor` de domaine (ou à `UncoveredCustomPaths` si aucun domaine n'existe
  encore) — **plus jamais à `CustomRoutesOpenApiFactory`**, qui ne fait que composer.

### Authentification (`AuthController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/login` | POST | Connexion — firewall `json_login` de Symfony (username `email`, password `password`), succès/échec délégués à LexikJWT. **SEC-16 : rend un `204` SANS CORPS** — le JWT est posé en cookie httpOnly `BEARER` (`path=/api`, `SameSite=Strict`), jamais rendu au client ([`jwt-cookie.md`](../../docs/security/jwt-cookie.md)). Route déclarée dans `config/routes.yaml`. |
| `/api/logout` | POST | **SEC-16, `PUBLIC_ACCESS`** — efface le cookie d'authentification. Seul le serveur le peut (httpOnly) : sans cette route, « Se déconnecter » laisserait la session vivre jusqu'à expiration. Idempotent, ne révèle rien ; public pour rester utilisable sur une session déjà expirée. |
| `/api/register` | POST | Inscription **différée, sans auto-login** (anti-énumération A3, #153 — rate-limité par IP, `auth_register` : 5/15 min). Exige `consent:true` (RGPD, 400 sinon — validation payload-only, enumeration-safe) et stocke la preuve (`termsAcceptedAt`+`termsVersion`). Crée un `User` **non vérifié** (`emailVerifiedAt=null`) + un `EmailVerificationToken` portant l'intention club `{ara, clubName}`, envoie un mail de vérification, et renvoie un **202 générique identique** dans tous les cas (email neuf ou déjà inscrit) — **aucun token émis**. Email déjà connu → aucune création, mail « tu as déjà un compte » (compte non vérifié → renvoie un nouveau lien). **Le club n'est PAS créé ici.** Validation : email, mot de passe (`PasswordPolicy` : ≥12 car. + majuscule + spécial), ARA 3-20 alphanumérique majuscule, `club_name` requis si ARA nouveau. Le login rejette un compte non vérifié (`UserChecker`, message identique à un mauvais mot de passe). |
| `/api/register/verify` | POST | Body `{ token }`. Consomme le token de vérification (verrou pessimiste `PESSIMISTIC_WRITE` anti-double-verify), passe `emailVerifiedAt`, **matérialise le club** sous GUC RLS (ARA nouveau → `Club` + `Season` + `Sport` + 12 `SportCategory` (`Service\Basketball\CategoryCatalog`) + `ClubUser` actif `admin`, `membershipStatus:"active"` ; ARA existant → `ClubUser` **inactif** pending), puis **émet le JWT** (login effectif) — **SEC-16 : posé en cookie httpOnly via `JwtCookieFactory`, plus dans le corps** ; la réponse est `{ membershipStatus, user }`. 400 token invalide/expiré ; 409 si le club à rejoindre a disparu. Purge des comptes non vérifiés > 7j : `app:users:purge-unverified` (cron-runner quotidien à 02:00). |
| `/api/me` | GET | Profil courant — retourne `id`, `email`, `firstName`, `lastName`, `membershipStatus` (`none`/`pending`/`active`), `role`, `club` (id, name, `onboardingCompleted`, `logoUrl`, `accentColor`, `accentPalette`), **`seasonPlan`** (`{id, name, chosenScheduleId, hasFinishedVersion, currentStructureHash}` — LE plan de la saison sélectionnée, ADR-0002 : `chosenScheduleId` = la version choisie, `null` = espace de travail ; `hasFinishedVersion` = le plan porte ≥1 version terminée, ce qui débloque le cockpit ; `currentStructureHash` = hash du payload solver actuel pour comparer la version affichée et griser « Régénérer » quand elle est déjà identique), `hasGenerated` (booléen : `generationCountSeason > 0`), `seasons`. |
| `/api/me` | DELETE | **RGPD droit à l'effacement** (self-only, `DeleteAccountController`). Ré-authentification : body `{ password }` (mot de passe courant, 400 sinon — un JWT volé ne suffit pas). Anonymisation IMMÉDIATE (email → `deleted-{id}@anonymized.invalid`, hash aléatoire, memberships désactivés, transactionnel) ; plus aucun membre actif → `Club.erasureScheduledAt = +30 j` (purge du workspace par `app:clubs:purge-erased`, auto-annulée si un membre revient ; l'identité publique FFBB survit). Réponse `{ message, clubPurgeScheduled, gracePeriodDays }`. NR : `AccountErasureTest`. |
| `/api/me/export` | GET | **RGPD portabilité** (self-only, `RgpdExportController`) : compte + adhésions + preuve de consentement + lastLoginAt, JAMAIS le hash. JSON en téléchargement (`Content-Disposition`). Rate-limité `rgpd_export` (10/h par user). NR : `RgpdExportTest`. |
| `/api/club/export` | GET | **RGPD portabilité club** (management SEC-07, tenant du JWT — pas d'id de chemin ; 404 sans membership actif, 403 non-management) : workspace complet en lignes brutes, une clé par table (liste dans `RgpdExportService::CLUB_TABLES`, `schedule` traité à part hors colonnes lourdes), tenant-scoped garanti par RLS. Rate-limité `rgpd_export`. NR : `RgpdExportTest`. |

### Télémétrie de génération (`SolverMetric.php`)

`solver_metrics` conserve une ligne par tentative de génération (`schedule_id`, `club_id`,
`status`, `wallTimeMs`, `nbVariables`/`nbConstraints`/`nbConflicts`, `score`, `solverVersion`,
`planType`, `nbTeams`/`nbVenues`, `createdAt` — `src/Entity/SolverMetric.php`). La table est
sous RLS `FORCE` et le rôle runtime ne voit que le club courant. `Club.lastActivityAt` est mis
à jour **à la mise en file d'une génération** seulement (`GenerateScheduleController::__invoke`,
`src/Controller/GenerateScheduleController.php:125`) — pas au login. La rétention et le
partitionnement sont différés aux jobs SA3.

### Authentification superadmin (`AdminAuthController.php`)

Identité, provider et firewall stateful séparés de `User`/`ClubUser` et du JWT club. Le
parcours mot de passe + TOTP, la session, le CSRF et l'audit fail-closed sont spécifiés dans
[`superadmin-auth.md`](../../specs/courantes/superadmin-auth.md). Routes : `POST /api/admin/auth/password`,
`POST /api/admin/auth/totp`, `GET /api/admin/auth/me`, `POST /api/admin/auth/logout`.

Le reste de la console (supervision parc/solveur, jobs planifiés, journaux read-only, actions
de support, demandes de création de club) vit derrière le même firewall `/api/admin` dans
`AdminMonitoringController`, `AdminJobController`, `AdminAuditLogController`,
`AdminMessengerFailedController`, `AdminSystemErrorsController`, `AdminClubActionController`
et `AdminClubRequestController` (`backend/src/Controller/Admin*.php`) — catalogue de routes
exhaustif et à jour dans [`superadmin-auth.md`](../../specs/courantes/superadmin-auth.md), pas dupliqué ici.
Deux mécanismes transverses à toute la console : le `TenantFilterListener` **retourne
immédiatement** sur `/api/admin/**` (SEC-17, `src/EventListener/TenantFilterListener.php:70` —
la console n'a pas de tenant, et poser `app.club_id` pour une identité admin violerait le
contrat SA0) ; et `AdminCsrfListener` (SEC-18, `src/EventListener/AdminCsrfListener.php`,
priorité 6) exige le jeton CSRF sur **toute méthode non sûre** sous `/api/admin` — opt-out
par exemption explicite (`auth/password`, `auth/totp`, les deux portes de connexion), plus
opt-in par contrôleur.

> **RGPD — mécanismes transverses** (rétention comptes inactifs 24 mois, purges cron, journal
> d'audit append-only, consentement) : registre des traitements et pointeurs code dans
> [`docs/security/rgpd.md`](../../docs/security/rgpd.md).

### Mots de passe (`PasswordController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/password/forgot` | POST | Demande de réinitialisation (SymfonyCasts ResetPassword). Rate-limité par IP (`auth_password_forgot` : 5/15 min). Envoie un email avec lien `/reset-password/{token}` (expiration 1 h). Répond **toujours** 200 `{status:"sent"}` — pas d'énumération d'emails. |
| `/api/password/reset` | POST | Body `{ token, password }` (politique `PasswordPolicy` : ≥12 car. + majuscule + spécial). Valide le token, consomme la demande, re-hash le mot de passe. 400 si token invalide/expiré. Entité support : `ResetPasswordRequest`. |

### Génération de planning

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/generate` | POST | `GenerateScheduleController` | Lance la génération asynchrone. Gate management (`assertManager`, SEC-07). Vérifie l'appartenance du schedule au club courant, **borne de complexité A10 pré-dispatch** (`GenerationComplexityGuard` : teams ≤200 · venues ≤50 · slots ≤3000 · contraintes permanentes ≤500 · teams×venues ≤2000 → **422** avant toute mise en queue, statut inchangé, #156), **épinglage orphelin sur un planning de période** (`OrphanPinGuard`, #8) : un verrou HARD ou une réservation qui ne retombe plus sur aucun créneau de la grille de la période (grille refaite : page blanche, recopie) → **422** nommant le gymnase, le jour et l'équipe (nom d'équipe ajouté par P2-37, 2026-08-18). ⚠ **Un gymnase DÉSACTIVÉ (P3-20, 2026-08-06) ou EFFECTIVEMENT fermé-total sur la fenêtre en est exclu** : l'état EFFECTIF composé par `PlanVenueClosures::effectiveStateForPlan/Entry` (indisponibilité déclarée × masque manuel du plan, décision fondateur 2026-08-18 — l'incident PRÉ-REMPLIT un défaut que le masque contredit jour par jour) détermine désormais ce fermé-total, jamais la seule dérivation brute des dates fermées. Son épinglage est inerte (le solveur ne le verra jamais) et revient intact à la réactivation — refuser enfermait le gestionnaire sur un épinglage devenu invisible. **Un jour EFFECTIVEMENT fermé (par le défaut ou par un masque `CLOSED`) d'un gymnase par ailleurs ouvert reste bloquant** : là, la séance serait replacée ailleurs en silence, ce que ce garde existe pour empêcher ; un jour rouvert par le masque (`OPEN`) n'est plus une cause de blocage. Passe le statut à `PENDING`, marque `onboardingCompleted=true` à la première génération, dispatche `GenerateScheduleMessage`. Retourne 202. |

### Cycle de vie du planning (pointeur du plan — ADR-0002)

`ScheduleStatus` (enum) : `DRAFT`, `PENDING`, `GENERATING`, `COMPLETED`, `FAILED`. **« Validé » n'est pas un statut** : c'est le **plan** (`schedule_plan`) qui **pointe** la version faisant foi (`chosen_schedule_id`) — une version pointée reste `COMPLETED`, et le champ de lecture `Schedule.isChosen` l'expose. Le plan de type **SEASON** et sa version choisie **sont** le calendrier de la saison. Générer ne pointe **jamais** (inv. 2) : seul le gestionnaire choisit.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/validate` | POST | `ValidateScheduleController` | **Pointe** la version sur son plan **et supprime les versions sœurs** du même périmètre (inv. 1 — plus d'archivage). Gate management (SEC-07) + contrôle club courant (403 sinon). 409 si le statut n'est pas `COMPLETED`, si une sœur est `PENDING`/`GENERATING`, ou (`overlays_exist`) si choisir une **autre** version de saison détruirait des plans secondaires — confirmer par `{"confirmDeleteOverlays": true}` ; portée et destruction : voir `/reopen`. **Effet de bord RMM-10 (P2-52)** — dans la MÊME transaction que le pointage, dépointe tout match domicile dont le gymnase a disparu du club+saison (`OrphanedFixtureFinder::orphanedFixtures`, MÊME prédicat que `/validate-impact` ci-dessous) via `FixtureVenueLossMarker` : `UNPLACED` + `unplacedReason=venue_lost`, heure conservée. N=0 → aucune écriture, comportement byte-identique à avant. |
| `/api/schedules/{id}/validate-impact` | GET | `ValidateImpactController` | **RMM-10 (P2-52, 2026-08-26)** — ce que valider CETTE version va faire perdre : `{orphanedFixtures, declaredOrphanedFixtures}`, lu **avant** confirmation par `ValidateDialog` (N=0 → aucun bruit, N>0 → annonce). Lecture ouverte au Membre, aucune écriture — MÊME prédicat que la gâchette (`OrphanedFixtureFinder`), donc l'annonce ne peut pas mentir sur ce que `/validate` détruira. Contexte club résolu **fail-closed** (400 si irrésolu — revue sécurité 2026-08-26) ; 403 club étranger ; 404 inconnu. |
| `/api/schedules/{id}/reopen` | POST | `ReopenScheduleController` | Inverse : le plan **dépointe** la version, qui survit et redevient éditable (inv. 2). Gate management (SEC-07). 409 si la version n'est pas celle que pointe son plan ; 409 `overlays_exist` si le socle porte des plans de période **pas encore commencées** (validés ou non, décision fondateur 2026-07-24, ADR-0002 inv. 14) — confirmés, ils sont détruits **de bout en bout** (versions + plan + grille copiée + réglages) ; l'entrée de calendrier survit, « à traiter » de nouveau. |
| `/api/schedules/{id}/regenerate` | POST | `RegenerateController` | « Régénérer » : crée une **nouvelle version linéaire** (V2, V3…) du **même plan** avec la structure club COURANTE — jamais une régénération en place. Gate management (SEC-07) + club courant + borne A10 (`GenerationComplexityGuard`) avant dispatch. Plans SAISON uniquement (409 sinon — un overlay se régénère depuis le cockpit) ; source doit être `COMPLETED`/`FAILED` et non la version **choisie** (409 « rouvrez-le avant ») ; 409 si une génération est déjà en cours pour la saison. Défense en profondeur du socle en vigueur SOUS verrou de plan-scope (`SocleGuard::assertSeasonPlanNotChosen`, miroir de `processPost`). Aucune copie de créneaux : le payload de génération répingle déjà les verrous HARD des versions de base. **`GenerateScheduleMessage` porte `sourceScheduleId: $source->getId()`** (P3-21 PR B) — la version que le gestionnaire **regarde** en régénérant, jamais la dernière du plan (si le gestionnaire regarde V2 pendant que V5 existe, c'est V2 qui sert de précédent). |
| `/api/schedule_plans/{id}` | PUT | `SchedulePlanStateProcessor` | Renomme le plan — le **nom appartient au plan** (inv. 12). Gate management (SEC-07). |
| `/api/schedules/{id}/regenerate-from` | POST | `RegenerateFromVersionController` | « Charger cette version » : **restaure** la photo de structure (`ScheduleStructureSnapshot`, `StructureRestorer`) d'une version `COMPLETED` dans la structure vivante du club et la marque comme contexte chargé de la saison (`Season.liveContextScheduleId`) — **sans lancer de solve**. Plans SAISON uniquement, source `COMPLETED`, ni choisie ni en cours de génération ; restauration destructive faite sous le même verrou de plan-scope + `assertSeasonPlanNotChosen` **avant** l'écrasement. 409 si aucune photo n'existe (version antérieure à la fonctionnalité). |
| `/api/schedule_plans/{id}/transcribe-from-socle` | POST | `TranscribePeriodPlanController` | **« L'adaptation naît comme une COPIE du socle »** (P2-44 PR-1, [ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md)) : sur un plan de PÉRIODE **sans aucune version**, transcrit **sans solveur** la version POINTÉE du plan SEASON (`SocleGuard::assertSeasonPlanChosen`) en V1 `COMPLETED`, filtrée par la sélection de période EXISTANTE (`PeriodConstraintSelector`/`PlanVenueClosures` — le même filtre que le gate/payload). Équipe désactivée → séance omise (rien à replacer) ; gymnase désactivé / jour effectivement fermé / équipe réduite (retrait DÉTERMINISTE des dernières séances de la semaine, tri jour puis heure décroissants) → omise et répertoriée dans `toReplace` (équipe/jour/heure/gymnase/raison `venue_disabled`\|`venue_closed`\|`team_reduced`, servi tel quel — le front ne redérive rien). Séances copiées **verrouillées HARD**, origine VRAIE (`RESERVATION` si une `Reservation` du plan coïncide, sinon `MANUAL` — épinglage de gestion révocable, jamais deviné, `LockOriginProvenanceTest`). `Schedule.solverVersion` porte le marqueur produit `PeriodPlanTranscriber::TRANSCRIPTION_MARKER` (`'socle-transcription'`, zéro migration). **Pas de pointage automatique** — le gestionnaire valide via la route existante. Atomique sous le verrou de portée du plan (garde « plan vierge » relue SOUS le verrou, anti-double-submit). 409 si le plan porte déjà une version ou si le socle n'est pas pointé ; 422 sur un plan SEASON. Gate management (SEC-07) + tenant + saison non archivée + `SocleGuard`. Zéro appel engine, contrat inchangé. NR bloquant `Security/PeriodCopyBirthTest`. |
| `/api/schedules/{id}/fill` | POST | `FillPeriodPlanController` | **Le COMBLEMENT** (P2-44 PR-3, [ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md)) — miroir PÉRIODE de `/regenerate`, mais un solve **PARTIEL** : crée une V+1 (savepoint, jamais in-place) du **même plan de PÉRIODE** et dispatch le rail de génération async **existant** (`GenerateScheduleMessage::fillSourceScheduleId`) — `ScheduleConstraintBuilder::withPinnedAssignments` greffe, **dans le payload de ce solve seul** (rien n'est persisté en base), les placements de la version SOURCE en épingles `lockLevel: HARD` (dédupliquées contre celles déjà posées par `buildForOverlay`, filtrées au roster équipes/gymnases de la sélection de période) ; un HARD n'a pas de variable côté moteur, donc le solveur ne place QUE les trous (équipes sous leur `sessionsPerWeek`). Le handler saute `withPreviousAssignments` en mode fill (le terme de stabilité serait un no-op sur du déjà-épinglé) et le remplace par `withSocleReferenceAssignments` (PR-3, P2-58) : les placements `{teamId, dayOfWeek, startTime}` — SANS `venueId` — de la version **pointée** du plan SEASON (le socle, cross-plan) sont émis au moteur comme référence de comblement, injectés APRÈS le hash de snapshot comme `previousAssignments` (préférence de convergence, jamais une donnée de structure). Le socle est GARANTI en vigueur en fill (`SocleGuard` ci-dessous) : « pas de version pointée » est un cas IMPOSSIBLE côté handler, qui échoue bruyamment (`LogicException`, plan `FAILED`) plutôt qu'un repli silencieux — `GenerateScheduleHandler::socleReferenceSlots()`. Gardes : plan de PÉRIODE seulement (409 sur un plan SEASON — `SchedulePlanProvisioner::isSeasonSchedule`), source `COMPLETED`, non la version **choisie** (409 « rouvrez-la »), aucune génération en cours pour la saison (409), socle en vigueur (`SocleGuard::assertSeasonPlanChosen`), borne A10 (`GenerationComplexityGuard`, 422), épinglage de la source non orphelin (`OrphanPinGuard`, 422). Gate management (SEC-07) + tenant + cap **PAR CLUB** (`ClubQuotaSubscriber`, 4e route de solve). Zéro copie de créneaux côté moteur (le comblement place, il ne rejoue rien), mais le contrat backend⇄engine **bascule vers 2.20** pour ce bloc (§ ci-dessous). NR sémantique `CrossStack/FillPreservesCopiesAndFillsGapsTest` (groupe `contract`, job `engine-semantics`) — falsifie que les placements copiés restent intacts et que les orphelines sont placées, avec un vrai solveur ; NR bloquant `CrossStack/SocleReferencePayloadParityTest` — falsifie le bloc `socleReferenceAssignments` dans les deux sens. |
| `/api/planned-windows` | GET | `PlannedWindowsController` | **Les fenêtres qu'un autre plan de période gouverne DÉJÀ** dans `[start, end]` — route de **LECTURE pure** qui SERT le verdict que la garde d'écriture applique, pour que l'écran cesse d'offrir une semaine dont la création serait refusée en 409. ⚑ **Foyer unique par construction** : `PeriodWindowUniquenessGuard::`governingWindows()` porte LE SQL (un seul `SELECT` dans le fichier) et `assertWindowFree()` est écrite par-dessus — la lecture et le refus ne PEUVENT pas diverger. Le nommage aussi est partagé (`nameConflict()`), sinon le gestionnaire lirait deux noms pour un seul planning ; la phrase servie omet l'invitation à « découper en semaines » que porte le 409, puisque celui qui la lit est déjà dans l'écran de découpe. **Deux chemins d'appel** : `entryId` (mère matérialisée — saison et famille racine tirées de l'entrée, parent↔enfant et sœurs jamais rapportés) ou `seasonId` (mère PAS ENCORE créée — rien d'exclu). Refus : **422** dates absentes/malformées (`Y-m-d` strict, aller-retour) ou ni `entryId` ni `seasonId` · **404** identifiant inconnu OU d'un autre club (jamais un oracle d'existence) · **400** sans club en contexte. **Pas de gate management** — lecture ouverte au Membre (patron `CalendarEntryConflictsController`) ; **pas** de `SeasonScopedWriteInterface` (rien n'est écrit). La garde d'écriture RESTE appelée aux deux sites de naissance : la prévention ne remplace jamais le refus (course entre gestionnaires, cache périmé). NR bloquant `Security/PlannedWindowsParityTest`, falsifié dans les deux sens. |
| `/api/schedules/{id}/socle-deviation` | GET | `SocleDeviationController` | **Les écarts NOMMÉS vs le socle** (P2-44 PR-5, [ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md)) — route de **LECTURE pure** (aucun `persist`, aucun `flush`) qui compare la version ciblée d'un plan de **PÉRIODE de type FERMETURE** à la version POINTÉE du plan SEASON (`SchedulePlanProvisioner::chosenOfSeasonPlan`) et rend deux listes : **`moved`** (équipe + placement d'origine → placement actuel — `to` porte en plus le `slotId` du créneau de PÉRIODE affiché par la grille, ajouté PR-4 2026-09-02 pour que le front sache quelle carte marquer/viser ; `from`, non affiché, n'en a pas) et **`unplaced`** (présente au socle, absente de la période, avec sa raison). Ni les séances **nouvelles** ni les **inchangées** ne sont rapportées (décision fondateur). **La cible est la VERSION, pas le plan** — une V1 transcrite et une V+1 comblée n'ont pas le même diff ; miroir de `/fill`, et « plan sans version » devient impossible par construction. **Appariement chronologique déterministe** : par équipe, les clés de placement identiques (`team:venue:day:HH:MM`, la clé EXISTANTE de l'import/du seed) sortent comme inchangées, le reste est trié (jour, heure, gymnase) croissant des deux côtés et apparié positionnellement, le reliquat du socle est « non replacé » — une équipe réduite laisse donc ses **dernières séances de la semaine**, le même déterminisme que `PeriodPlanTranscriber` (le diff redit ce que la transcription avait annoncé, il n'arbitre pas autrement). Deux séances échangées en croix sont appariées dans l'ordre de la semaine : rien n'est deviné. **Raison DÉRIVÉE** de la sélection de période (`PeriodConstraintSelector`), même précédence que le transcriber (`team_reduced` > `venue_disabled` > `venue_closed`), et **`null`** quand la sélection n'explique pas l'absence (suppression manuelle, solve qui n'a pas replacé) — jamais une cause fabriquée. Les équipes désactivées pour la période sont exclues d'entrée (elles ne jouent pas : aucun écart). Refus : **422** plan SEASON ou `periodType != CLOSURE` (une vacance réécrit sa grille — comparer dirait « tout a bougé ») ; **409** version non `COMPLETED` ou socle non pointé — ce dernier reste ATTEIGNABLE malgré `SocleGuard`, puisque rouvrir le socle ne détruit que les plans **futurs** et qu'une période déjà commencée survit ; **404/403** tenant. **Pas de gate management** — c'est une lecture, ouverte au Membre (patron `CalendarEntryConflictsController`) ; **pas** de `SeasonScopedWriteInterface` (rien n'est écrit). Zéro appel engine, contrat inchangé, aucune migration. NR bloquant `Security/SocleDeviationParityTest`. |

**Placement précédent émis au moteur (P3-21 PR B, contrat 2.11 — terme de stabilité)** :
`GenerateScheduleHandler::resolvePreviousAssignmentSlots()` retrouve la source — `sourceScheduleId`
explicite s'il est posé (regénération d'une version précise) et de la même lignée
(`schedulePlanId`, sinon ignoré — jamais le socle sous un overlay, ADR-0002), sinon repli sur la
dernière version `COMPLETED` du même plan (hors la version en cours), sinon aucun précédent
(première génération). `ScheduleConstraintBuilder::withPreviousAssignments()` sérialise ses
créneaux (`{teamId, venueId, dayOfWeek, startTime}`, HARD compris) dans le payload sous
`previousAssignments` (clé absente si la liste est vide — chemin byte-identique à l'historique).
⚠ **L'injection se fait APRÈS `setSnapshotData`/`setSnapshotHash`, jamais avant** : `snapshotHash`
est le jumeau de `currentStructureHash` (`SchedulePlanProvisioner`, recalculé SANS le précédent) —
l'y inclure le ferait diverger à chaque régénération, dé-grisant en permanence le bouton
« Régénérer » et affichant une fausse « structure modifiée ». Le précédent est une préférence de
**convergence**, jamais une donnée de **structure** — piège à ne pas réintroduire. Gardé par
`CrossStack/PreviousAssignmentsPayloadParityTest` (bloc émis == placements source en base, dans
les deux sens ; `snapshotHash` reste structure-only).

**Référence socle du comblement (PR-3, P2-58, contrat 2.20)** : en mode **fill seulement**
(`GenerateScheduleHandler`, branche `else` du `if (!$isFill)` qui pose `previousAssignments`),
`socleReferenceSlots()` lit les `ScheduleSlotTemplate` de la version **pointée** du plan SEASON
(`SchedulePlanProvisioner::chosenOfSeasonPlan`) et `ScheduleConstraintBuilder::withSocleReferenceAssignments()`
les sérialise, **filtrés au roster de la sélection de période** et **dédupliqués** par
`(teamId, dayOfWeek, startTime normalisée H:i)`, sous `socleReferenceAssignments`
(`{teamId, dayOfWeek, startTime}` — **sans** `venueId` : le gymnase est libre, seul le
jour+heure du socle compte). Comme `previousAssignments`, greffé **après** le hash de snapshot
(préférence de convergence, jamais une donnée de structure). Côté moteur, le bloc produit un
**bonus d'objectif en phase 1** (placement) par tier de priorité — `S=20, A=18, B=16, C=14, D=12`
(`SOCLE_REFERENCE_TIER_WEIGHTS`, `engine/app/solver/objective/weights.py`), appliqué par
`add_socle_reference_bonus` (`engine/app/solver/objective/terms.py`) sur toute variable dont
`(team, day, start)` matche une entrée de référence — le gymnase de la variable est ignoré. Champ
absent/vide ⇒ payload byte-identique à l'historique (chemin inerte, comme `previousAssignments`) ;
`engine/CONTRACT_VERSION` / `ScheduleConstraintBuilder::CONTRACT_VERSION` = **2.20** pour ce bloc,
`SCORE_FORMULA_VERSION` = **V13**. Gardé par `CrossStack/SocleReferencePayloadParityTest`
(falsifié dans les deux sens, dont un jour propre à la source de période qui ne fuit pas dans la
référence socle).

### Réordonnancement des équipes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/teams/reorder` | POST | `ReorderTeamsController` | Bulk atomique : body `{ items: [{ id, priorityTierId, tierOrder }] }` (ou liste nue), applique `(priorityTierId, tierOrder)` sur chaque équipe en une transaction (un seul flush). Remplace les N `PUT /api/teams/{id}` concurrents du mode tri (course sur le lock optimiste). 403 si une équipe n'appartient pas au club courant. Retourne `{ updated }`. |

### Réservation groupée (`GroupReservationController.php`)

Rail d'ÉCRITURE BATCH d'une mutualisation posée sur UNE case : N réservations (une par membre) en
UN SEUL flush — l'atomicité rend la règle d'exclusivité (case entièrement libre ou entièrement au
groupe/bloc) vérifiable, une écriture semi-faite ne serait ni « libre » ni « complète ». Né P2-46
(2026-08-23, `ReservationGroupOccupancy`, 5 gardes : exclusivité, réciproque, plafond `commonSessions`,
plafond membre, capacité).

**P2-51 PR-5 (2026-08-31) — RÉ-ANCRAGE sur le bloc**, puis **PR-7 (2026-08-31) — le repli retiré** :
le corps ne connaît plus que `sharedTrainingBlockId` (le repli `sharedTrainingGroupId`, transitoire
depuis PR-5, est supprimé de `GroupReservationController.php` avec le modèle groupe K lui-même).
Gardé par les MÊMES 5 règles qu'avant P2-51, appliquées au bloc à l'identique
(`ReservationGroupOccupancy::assertBlockReservationAllowed` — exclusivité, plafond `commonSessions`
du bloc, plafond membre ; règles réciproque/capacité comptent un bloc complet comme UN occupant,
patron `occupantCount`/`reservedSetMatchesABlock`).

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/reservations/group` | POST | Body `{ sharedTrainingBlockId, venueId, dayOfWeek, startTime, durationMinutes?, schedulePlanId? }` — `sharedTrainingBlockId` obligatoire, 400 sinon. UUID pré-validé en forme (jamais un id malformé jusqu'à Postgres — un `WHERE id = 'abc'` sur colonne `uuid` lève un 500, classe de défaut documentée ailleurs dans le dépôt). 404 si le bloc ne résout pas. 422 : portée du bloc ≠ portée demandée (socle vs plan de période), gymnase fermé, l'une des 5 gardes d'occupation. 409 saison archivée. Retourne `{ ids, count }`. |

**Constat sémantique du même lot (pas un trou)** : une réservation de bloc s'éclate en N verrous
HARD sur une case ; `add_room_at_most_one` (moteur) laisse une case toute-verrouillée non
contrainte, et le diagnostic de sur-capacité replie déjà les blocs PAR CASE (PR-3, folding déjà
câblé côté `result_builder/diagnostics.py`) — aucun crédit manquant côté moteur pour cette
réservation.

**Rail de DÉPLACEMENT groupé — livré par PR-5b (contrat 2.18)** : `POST /api/schedule-slots/move-group`
déplace le bloc entier sous UN verdict à N candidats (`candidates`/`references` en LISTES sur
`/validate-assignments`) — détail §« Édition manuelle » ci-dessous.

### Approbation des membres (`MembershipController.php`)

Réservé à un admin **actif** du club (403 sinon) ; cible toujours restreinte au club de l'admin (404 cross-tenant).

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/memberships/pending` | GET | Liste les `ClubUser` inactifs (`isActive=false`) du club de l'admin, avec `id`, `userId`, `email`, `firstName`, `lastName`. |
| `/api/memberships/{id}/approve` | POST | Active la membership (`isActive=true`). |
| `/api/memberships/{id}/reject` | POST | Supprime la membership. Retourne 204. |

### Approbation de club par token public (P3-4, `ClubApprovalController`)

Page publique SANS login, ouverte depuis le mail institutionnel FFBB — même patron que
`PublicCoachWishController` : le token EST l'identité, 404 byte-identique, rate-limit par IP
AVANT toute résolution (`club_approval_public`, 20/15 min, `config/packages/rate_limiter.yaml`).
Support entité `ClubCreationRequest` (**hors RLS, pas de `club_id`** — `src/Entity/ClubCreationRequest.php:19`,
le club n'existe pas encore au moment de la demande) via `ClubCreationRequestRepository::findPendingByToken`.

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/club-approvals/{token}` | GET | Résout le token (forme `^[0-9a-f]{64}$`, sinon 404), 410 si expiré. Rend `clubName`, `ara`, `requesterName`, `expiresAt`. |
| `/api/club-approvals/{token}` | POST | Body `{decision: "approve"\|"refuse"}` (422 sinon). Décision **unique** : la demande passe hors statut PENDING, un second appel revoit 404. `approve` délègue à `ClubApprovalService::approve` — verrou consultatif Postgres `pg_advisory_xact_lock(hashtext('club-approval:'.ara))` (anti-double-club sur deux demandes concurrentes pour le même ARA), club déjà né entre-temps → la demande devient une adhésion `pending` (jamais un second club), sinon `ClubProvisioner::createClub`. |

### Validation des contraintes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/constraints/validate` | POST | `ValidateConstraintsController` | Gate pré-solve. En mode période, le jeu validé vient de **`PeriodConstraintSelector`** (P2-14) — LA source unique partagée avec `buildForPeriodPlan`, qui aligne le récap sur ce que le solveur recevra (parité gardée par `PeriodGatePayloadParityTest`, phase1). Retourne `errors` par contrainte + `conflits` + `warnings` (drops pour gymnase désactivé, tag inerte, **capacité dans les deux sens** — P2-9 PR A : demande = **`PayloadCapacityMirror::demand`** (Σ `sessionsPerWeek` du payload, MOINS le repli des blocs de mutualisation — une séance de bloc réunit N membres sur UNE place, donc (n_membres−1)×`commonSessions` sortent par bloc, plancher 0 ; PR-4 du lot overlay, 2026-09-02, miroir littéral de `engine/app/solver/result_builder/diagnostics.py` — la version brute Σ `sessionsPerWeek` seule datait), offre = Σ capacités des créneaux, sous-capacité en « au moins X », surplus dès 1 créneau en trop ; nombres lus du payload `buildForClubSeason`/`buildForPeriodPlan`, jamais recalculés ; **coach indisponible × réservation en dur** — PR A 2026-08-06, miroir du parse moteur dans `CoachDoubleBookingDetector::detectUnavailabilityClashes`, avertit AVANT au lieu de l'INFO post-solve) + `blockers` (coach dédoublé, P2-9 PR B ; **saturation des « au moins » par gymnase** — PR A 2026-08-06 : demande = Σ des minimums (un pin n'y compte pas, sa variable n'existe pas), offre = places des triplets NON verrouillés — demande > offre = INFEASIBLE certain ; **surplus de réservations d'une équipe** — P3-20 2026-08-06 : plus de réservations que de `sessionsPerWeek` est une INCOHÉRENCE gestionnaire, pas une préférence (un verrou est pré-placé hors modèle, les trois s'imposeraient) ; la règle vivait côté client en simple avertissement, elle est revenue au serveur) + **`capacity` (clé ADDITIVE, PR-4 2026-09-02)** — `{demand, offer}` chiffré, MÊME lecture que les avertissements ci-dessus, `null` sans payload (période non génératrice / build en échec) ; sert le compteur de carence de l'écran de FERMETURE (`frontend-spec.md` §6.7 bis), sans dupliquer le calcul côté front. **L'algèbre des lectures de payload vit dans `PayloadCapacityMirror`** (P3-19, 2026-08-07 — source unique offre/saturation/grille/demande, parité épinglée contre le VRAI moteur par `CapacityMirrorParityTest`, groupe `contract`). **P4-57 (2026-08-19)** — quatre PRÉVENTIONS de plus, calculées par `PreSolvePreventionWarnings` depuis LE payload (donc depuis ce que le solveur recevra, jamais depuis une lecture parallèle) : gymnase déclaré sans aucun créneau · équipe qu'aucun créneau ne peut accueillir (gymnase imposé vide, ou jours autorisés sans intersection) · coach dont la charge dépasse ses jours disponibles · contrainte visant une équipe absente du périmètre. Chacune ne se voyait qu'APRÈS génération, en diagnostic. Elles AVERTISSENT, elles ne bloquent jamais |

### Écriture des contraintes — liste blanche `config` (SEC-13)

`ConstraintConfigValidator` (`src/Service/ConstraintConfigValidator.php`) est LA liste blanche
noms+types du champ `config` d'une contrainte — branchée dans `ConstraintStateProcessor`
(création ET PUT), seul champ du formulaire qui n'avait auparavant aucune validation. Une clé
mal orthographiée (`maxStartTme`) rendait 201, s'affichait comme une règle active, et le
solveur l'ignorait silencieusement (déclaré ≠ effectif). Violation → **422** nommant la clé.
Quatre familles (`enum ConstraintFamily` : `TIME`, `DAY`, `FACILITY`, `COACH_AVAILABILITY` —
`FACILITY_CAPACITY` a été retirée, plus personne ne pouvait la créer) + `targetTag`, lisible
par toutes. `config.coachId` a été supprimé (doublon exact du `scope`, la cible est déjà le
scope). Chaque clé de la liste cite qui la lit ; pour les clés lues par le moteur, la preuve
qu'elles changent le résultat du solveur est portée par le job CI dédié `engine-semantics`
(groupe `contract`).

### Calendriers — vacances scolaires & jours fériés

Référentiels globaux display-only (jamais consommés par le solveur). Détail complet (modèle, zones, commandes d'import, règles) : [`vacances-scolaires-jours-feries.md`](../../specs/courantes/vacances-scolaires-jours-feries.md).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/school-holidays` | GET | `SchoolHolidaysController` | Vacances scolaires de la zone du club (`Club.schoolZone`) dans la fenêtre `from`/`to` (défaut : saison active). Zone null → `items: []`. |
| `/api/public-holidays` | GET | `PublicHolidaysController` | Jours fériés `NATIONAL` ∪ extras du territoire du club, même fenêtre. Zone null → NATIONAL quand même. |

### Statistiques d'utilisation des gymnases (P3-22)

Lecture pure destinée à la NÉGOCIATION avec la mairie (« le lundi, on a 8 h, dont 3 h de
régional ») : heures ventilées **par jour de la semaine**, par gymnase ET par niveau, en
distinguant le déjà **Réalisé** (dates ≤ aujourd'hui, `ClubDay::todayFor`) du **À venir**.
Contrôleur `VenueUsageStatsController` (patron `SchoolHolidaysController`) + service pur
`App\Service\VenueUsage\VenueUsageCalculator` (DTOs `UsageSlot`/`PeriodOverlay`/`HolidayRange`,
aucun accès base — le contrôleur charge, le service agrège).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/venue-usage-stats` | GET | `VenueUsageStatsController` | `from`/`to` en query (`YYYY-MM-DD` strict — `2026-02-30` ou une chaîne malformée → 400, patron `SchoolHolidaysController`) ; absents → fenêtre = la saison sélectionnée/courante en entier. Calcul **jour par jour** sur `[from, to]` (règle gratuitement les semaines partielles). |

**Résolution de la grille applicable, un jour donné (`VenueUsageCalculator::resolveScheduleForDate`)**,
dans cet ordre :

1. une **période `ACTIVE`** dont le plan porte une **version validée** (`chosenScheduleId`) et
   qui couvre la date → ses créneaux. À couverture égale, la fenêtre la plus **étroite** gagne
   (la semaine-enfant prime sa période mère) ; à largeur égale, l'enfant (`parentEntryId` non
   nul) tranche.
2. sinon un jour de **vacances scolaires** de la zone du club (`Club.schoolZone`, zone nulle =
   aucune neutralisation) → **0 h**. ⚑ **Décision fondateur (2026-08-17, basculable en une seule
   ligne dans `resolveScheduleForDate`, commentée à l'endroit exact)** : un jour de vacances vaut
   0 h **même en Réalisé** — compter des heures « faites » sur une semaine où personne ne s'est
   entraîné serait faux. La variante « seulement les vacances À VENIR » (vacances passées
   retombant sur la grille de saison) est documentée en commentaire, non retenue.
3. sinon la grille **pointée du plan `SEASON`** (`SchedulePlan::chosenScheduleId` ; aucun
   pointeur → 0 h — un club fraîchement onboardé sans version validée n'a rien à montrer).

**Ventilation par niveau : une ligne par `TeamLevel` réellement utilisé sur la plage**, aucune
table de regroupement (décision fondateur) — libellé servi par `TeamLevel::label()` (nouveau,
`backend/src/Enum/TeamLevel.php`, docblock : « le back le fournit pour que le front n'invente
aucun vocabulaire métier », doit rester aligné avec le mirror front `wizard/lib/labels.ts`
`LEVEL_LABEL` — duplication de présentation assumée, pas de test de parité). Une équipe sans
niveau renseigné tombe dans une ligne à part (« Non renseigné »).

Réponse : `range` (fenêtre effectivement appliquée + `today`), `zone`, `venues` (une ligne par
gymnase, triée par heures décroissantes), `totalByDay` (la ligne TOTAL du front), `byLevel`,
`grandTotal`. Heures arrondies à 2 décimales, `total` dérivé de la somme des bruts (pas de dérive
d'arrondi entre `real + projected` et `total`).

⚠ **Divergence assumée avec ADR-0002, réservée aux STATS** : l'invariant « une période possède sa
grille » suppose une version validée pour en tirer des créneaux. Une période **`ACTIVE` sans
version validée** (workspace non encore validé) ne fournit donc RIEN à l'étape 1 ci-dessus et le
calcul **retombe sur la grille de saison** pour ces dates — alors que côté génération/édition, une
période capture ses dates dès sa création (ADR-0002), validée ou non. C'est une approximation de
LECTURE (il n'existe rien d'autre à montrer tant que rien n'est validé), pas une réécriture de
l'invariant : le solveur et le rail d'édition n'en sont pas affectés, seul cet encart de stats lit
au travers.

### Géo — géocodage & autofill de la matrice de trajet (P2-53 RMM-8, SOLDÉ — 4 PR)

Deux clients externes SSRF-safe (hosts codés en dur, patron `FfbbApiClient`), routes et logique
détaillées dans [`docs/geo-api.md`](geo-api.md). Le levier d'intensité (PR-4) est une ressource
API Platform normale — voir la ligne `VenueTravelRuleSetting` du tableau §2, pas ici.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/geocode` | GET | `GeocodeController` | Proxy vers la Base Adresse Nationale (query `q`, 3 à 200 caractères). Management (SEC-07) ; 422 requête invalide, 502 service indisponible. Rend `{candidates: [{label, latitude, longitude, score}]}`, jamais le hit brut. |
| `/api/venue-travel-times/autofill` | POST | `VenueTravelTimeAutofillController` | Remplit AUTO les minutes voiture/à pied de chaque paire de gymnases géolocalisés du club+saison, via l'itinéraire IGN. Management + saison écrivable (409 archivée) + rate-limit dédié PAR UTILISATEUR (`venue_travel_time_autofill`, 10/h) ; 422 au-delà du cap de 120 paires ; 409 sur course d'écriture concurrente. Une valeur `MANUAL` n'est jamais écrasée. Rend `{filled, unresolved[], skippedManual}`. |

### Export PDF / Excel

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/schedules/{id}/export-pdf` | POST | `ExportPdfController` | Lance l'export PDF asynchrone (auth requise sur le déclencheur — tenant résolu depuis le JWT comme les autres routes schedule, 401 sans session). Passe `pdfExportStatus` à `pending`, dispatche `ExportPdfMessage` ; `ExportPdfHandler` (Messenger, `messenger-worker`) appelle `PdfGenerator`, qui poste le HTML au conteneur **`pdf-worker`** (Puppeteer, `docker/pdf-worker/`, `http://pdf-worker:3000/generate`) et écrit le PDF en fichier statique sous `public/exports/` (`PdfGenerator::OUTPUT_DIR`) servi ensuite en `/exports/…`. Retourne 202. **Deux sections depuis P2-23 (2026-08-11)** : page 1 = la grille gestionnaire, page 2+ = la matrice **Équipes × jours** — lignes groupées par **rang** (S→A→B→C→D, sous-titres, `break-inside: avoid` pour qu'un groupe ne soit jamais coupé), colonnes = les seuls jours occupés, cellule = `gymnase · HH:MM` **sans coach**. Même route, **1 crédit inchangé**. ⚑ **Depuis le 2026-08-21 : plus d'export PNG, et la 2ᵉ section est INCONDITIONNELLE** (décision fondateur). Elle n'existait qu'en périmètre multi-gymnases (`hasMatrix`, supprimé) au motif qu'elle « lève l'ambiguïté sur le gymnase » — justification d'un DÉCLENCHEUR prise pour la raison d'être de la vue : la grille dit *qui occupe quel gymnase ce jour-là*, la matrice dit *quand s'entraîne CETTE équipe*, et le second besoin existe aussi avec un seul gymnase. ⚠ Les LIGNES de la matrice dépendent, elles, de la PORTÉE — tous gymnases : toutes les équipes (une équipe sans séance est un trou à voir) ; un seul gymnase : uniquement celles qui y ont une séance, sinon une équipe s'entraînant ailleurs passerait pour une équipe sans entraînement. ⚠ Le worker (`frontend/worker.js`) garde son chemin mono-section **intact** (aucune ligne retirée) ; le multi-section ajoute le drapeau `multiSection: true` au payload et ajuste la grille par CSS `zoom` — **pas** `transform`, qui ne reflue pas et laissait déborder la grille sur la page 2. **Passe esthétique (2026-08-16)** : page 1 n'est plus « inchangée » depuis P2-23 — la pause méridienne 12:00-14:00 est teintée sur les cases vides/la gouttière d'heures avec un trait marqué à ses deux bornes, une bande verticale noire marque la frontière entre jours (en-tête et corps), les cellules occupées portent une bordure 2 px quasi-noire (contre 1 px gris pour le reste de la grille), et chaque cellule occupée affiche l'**heure épinglée en haut** puis les équipes empilées **centrées** (`vertical-align: middle` sur `td.filled`, fix du même jour — le contenu s'empilait au sommet et une séance longue semblait vide en bas) ; le **coach est retiré de la grille** (il ne survit que dans le panneau `SlotDetail` côté écran). Section 2 (matrice) : la cellule pleine devient un **bloc de la couleur du gymnase** occupant toute la case, texte centré, contraste auto — remplace la pastille (`chip`) d'avant cette date ; le contenu textuel (`gymnase · HH:MM`, sans coach) est inchangé. NR : `backend/tests/Unit/Service/PdfTeamDayMatrixTest.php`. |
| `/api/schedules/{id}/export-xlsx` | POST | `ExportXlsxController` | Export Excel **synchrone** (`PhpSpreadsheet`, pas de tête sans écran à attendre) : flux `.xlsx` en téléchargement direct, filtrable par gymnase. Contrôle club courant (403). Nom de fichier = le nom **vivant** du plan (`SchedulePlanProvisioner::displayNameOf`, pas la photo `Schedule.name`) ; `/`/`\` remplacés avant `makeDisposition` (sinon 500 générique — Symfony lève sur un séparateur de chemin dans un nom de fichier).  **Deux feuilles depuis P2-23 (2026-08-11)** : « Planning » (le tableau plat triable, une ligne par créneau ET par fenêtre vide) et **« Équipes × jours »** — matrice lignes = **toutes** les équipes de la saison (une équipe sans séance garde sa ligne vide : le trou est l'information), colonnes = **`Rang`** (P4-84, 2026-08-12) puis les seuls jours occupés, cellule = `gymnase · HH:MM` **sans le coach**, ⚠ la colonne `Rang` vaut `01 · S`, `02 · A`… — **le préfixe numérique zéro-paddé EST le sujet** : un tri Excel sur les libellés bruts « S, A, B, C, D » rendrait A, B, C, D, S, donc PAS l'ordre du PDF. Ainsi préfixée elle trie **exactement** comme le PDF (même `tierRank`), et le padding tient au-delà de 9 paliers (gardé par test) ; équipe sans rang → cellule vide, donc en fin de tri. deux séances le même jour empilées dans la cellule. ⚠ La 2ᵉ feuille n'est **pas créée** quand les placements ne couvrent qu'un seul gymnase (export scopé ou club mono-gymnase) : elle n'aurait rien à désambiguïser. Même route, même fichier, **1 crédit inchangé**. Les deux feuilles projettent leurs colonnes **par nom** (D-18) — gardé par `SpreadsheetColumnsAreProjectedByNameTest` et `SpreadsheetTeamDayMatrixTest`.|

### Édition manuelle (`ManualEditController.php`)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/schedule-slots/{id}/move` | POST | **Déplace un créneau SOUS LE VERDICT DU MOTEUR** (P2-2 F2b, 2026-08-12) — `MoveSlotService` : (1) **409** `generation_in_progress` si `ClubGenerationLock::isGenerating()`, (2) baseline construite **sans la source ET sans les créneaux des versions sœurs** (le placement d'origine de la source est aussi porté en `reference` du payload moteur, P2-32 — sert au delta de compromis, jamais au verdict booléen), (3) `POST /validate-assignments` sur l'engine (timeout HTTP **20 s** — `MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`, maison unique partagée par `move()` ET `place()`, calé sur mesure (9-9,6 s constatés sur le club dense de référence) ; un candidat accepté déclenche jusqu'à 3 solves moteur côté engine — verdict + les deux états figés du delta de compromis, P2-32), (4) refus → **422** `{valid:false, violations:[{rule, message, teamId, coachId, venueId, dayOfWeek, startTime, conflictingTeamId}]}` (messages déjà humains, ids null-safe pour le surlignage front) et **rien n'est écrit**, (5) accord → écriture + marqueur `manuallyEditedSinceGeneration` + publication Mercure, réponse `{valid:true, compromises, evicted?}` — **`compromises` (P2-32)** est le delta de confort nommé que ce déplacement casse/apporte (`family`/`effect` `broken`\|`gained`/`message` déjà humain sans id brut/ids d'entité pour le surlignage), jamais un poids ni une note (P5-14b) ; liste vide sur refus. Moteur injoignable/cassé → **502**, rien écrit. **Moteur trop lent (délai transport dépassé) → 504 `{code:"engine_timeout"}`, DISTINCT du 502** (`EngineTimeoutException` — incident terrain 2026-08-17, voir `etat-des-lieux.md`) : le service traduit un `TimeoutExceptionInterface` du client HTTP, rien n'est écrit, le front NOMME la cause au lieu d'un 502 muet. Planning validé → 409 (lecture seule). ⚠ **La re-validation a lieu AU MOMENT D'ÉCRIRE** — le verdict n'est jamais un cache. **Éviction OPTIONNELLE depuis P2-30 PR A (2026-08-16)** : le body gagne `evictSlotId` — retirer l'occupant de la cible visée. Validé **AVANT tout appel moteur** (D3 : un verrou est souverain) : occupant introuvable / d'un autre planning / égal à la source / ne siégeant pas à la cible → **422** `code=evict_target_mismatch` ; occupant verrouillé (`lockLevel` ≠ NONE) → **422** `code=target_locked`, le moteur n'est jamais consulté. Accepté → l'occupant évincé est **retiré de la baseline** envoyée au moteur, puis **supprimé dans la même transaction** que le déplacement ; le 200 porte un bloc `evicted` (état de l'occupant AVANT suppression : `slotId`/`teamId`/`dayOfWeek`/`startTime`/`venueId`/`durationMinutes`) que le front peut proposer de replacer. Pas de swap atomique (décision fondateur) : un échange vécu reste deux `/move` successifs. **`dryRun` (P2-32, body `dryRun:true`)** : un ESSAI — même chemin JUSQU'AU VERDICT INCLUS (toutes les gardes ci-dessus, le verrou souverain refuse l'essai comme le geste réel), puis retour AVANT toute écriture (ni déplacement, ni suppression de l'occupant, ni marqueur, ni Mercure). Réponse toujours **200** `{valid, dryRun:true, violations, compromises, evicted?}` — **même quand `valid=false`** : un essai RAPPORTE, il ne peut pas échouer au sens HTTP, donc jamais 422 sur ce chemin ; `evicted` y décrit l'état qui SERAIT évincé, sans le supprimer. NR `SlotMoveVerdictTest` (couvre éviction + `reference`/`compromises` + `dryRun` accepté/refusé/avec éviction), **step de `blocking-tests`**. |
| `/api/schedules/{id}/place-slot` | POST | **PLACE une séance À LA DÉRIVE — surnuméraire ou rattrapage — SOUS LE VERDICT DU MOTEUR** (P2-30 PR A, 2026-08-16) — même service `MoveSlotService::place()`, mêmes gardes que `/move` (management, tenant, **409** `generation_in_progress` ou version choisie lecture seule, **502** moteur injoignable/cassé, **504** `{code:"engine_timeout"}` moteur trop lent — même `EngineTimeoutException`, même timeout HTTP **20 s** partagé avec `/move`). Pas de source à retirer : la baseline reste **complète** (moins les créneaux des versions sœurs), et il n'y a pas de `reference` (création à la dérive → le delta de compromis se lit contre la baseline nue). Aucune garde de comptage — le verdict moteur est seul juge (capacité, fenêtre, repos coach…). Refus → **422** `{valid:false, violations:[…]}` (même forme que `/move`) et rien créé ; accord → **200** `{valid:true, slotId, compromises}`, une ligne `ScheduleSlotTemplate` **déverrouillée** (`lockLevel` NONE, `lockOrigin` null, `coachId` null), marqueur `manuallyEditedSinceGeneration` + Mercure — **`compromises` (P2-32)**, même forme et même règle que `/move` (jamais un poids, liste vide sur refus). ⚠ **La durée ne vient JAMAIS du client** : `durationMinutes` du body est **optionnel** et n'est qu'une **assertion** — la durée persistée est TOUJOURS celle de la fenêtre de gymnase visée (`venueId`+`dayOfWeek`+`startTime`), lue dans le **même payload** que celui envoyé au moteur (même source que `slot_durations` côté solveur). Aucune fenêtre à ce triplet → **422** `code=slot_unavailable` ; fenêtre trouvée mais `durationMinutes` fourni la contredit → **422** `code=duration_mismatch` — les deux AVANT tout appel moteur, rien écrit (correctif d'un finding de revue sécurité : une durée client non validée aurait écrit une occupation jamais jugée par le moteur). `teamId` d'une équipe hors club/saison du planning → **422** (équipe inconnue). **`dryRun` (P2-32, body `dryRun:true`)** : même patron que `/move` — verdict jusqu'au bout (résolution de durée/fenêtre comprise), zéro écriture, **200** `{valid, dryRun:true, violations, compromises}` y compris sur un candidat refusé (jamais 422 sur ce chemin). NR `SlotPlacementVerdictTest` (couvre durée menteuse + `compromises` + `dryRun`), **step de `blocking-tests`**. |
| `/api/schedule-slots/{id}/manual-edit/lock` | POST | Applique un verrou sur un créneau. Body : `lockLevel` (enum `LockLevel`). Retourne 200. |
| `/api/schedule-slots/move-group` | POST | **Déplace TOUTE la séance d'un bloc de mutualisation (D11, P2-51 PR-5b, contrat 2.18)** — `MoveSlotService::moveGroup()` : le corps ne porte QUE `{scheduleId, blockId, source{venueId,dayOfWeek,startTime}, target{…}}`, **jamais de slotIds client** — le serveur résout lui-même les créneaux membres (1) les teamIds membres du `SharedTrainingBlock`, (2) les `ScheduleSlotTemplate` de CE planning qui siègent EXACTEMENT à la case source parmi ces équipes. Aucun membre à la source → **422** `code=slot_unavailable`. Baseline figée **sans les N sources** (sinon chaque membre entrerait en conflit avec lui-même), durée résolue depuis la fenêtre CIBLE (pas de fenêtre → **422** `slot_unavailable`). **UN verdict à N candidats** : `POST /validate-assignments` reçoit `candidates`/`references` en LISTES (une entrée par membre, appariées par index — la référence = la case d'origine de ce membre), sous le même timeout HTTP **20 s** que `/move`/`place-slot`. Refus → **422** `{valid:false, violations:[…]}`, **AUCUN des N créneaux ne bouge** (atomicité par le refus — le rail simple `_shared_block_move_violation` etc. nomme `shared_block_broken` si le geste casserait le bloc) ; accord → les N créneaux mutés puis **UN SEUL flush** (transaction Doctrine tout-ou-rien), marqueur `manuallyEditedSinceGeneration` + Mercure, réponse **200** `{valid:true, compromises, movedSlotIds}`. Gardes communes : management (SEC-07), **409** `generation_in_progress` ou plan choisi (lecture seule), **502**/**504** `engine_timeout` moteur injoignable/trop lent, tenant/season-scopé (repository Doctrine filtré). Pas de swap ni d'éviction sur ce rail. NR `SlotMoveGroupVerdictTest` (atomicité, refus nommé, payload N, 409). |

### Import équipes

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/clubs/{id}/import-teams` | POST | `ImportController` | Importe un fichier `.xlsx` (Excel) pour un club et une saison donnés. Body multipart : `file` (.xlsx), `seasonId`. Délègue à `FfbbExcelImporter`. Retourne 200 avec `created`, `skipped`, `errors`. |

### Reset saison

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/reset-season` | DELETE | `ResetSeasonController` | Supprime toutes les données d'une saison pour un club. Résout `clubId` et `seasonId` depuis `_club_id` / `X-Club-Id` et `_season_id` / `X-Season-Id`. Supprime en cascade : `ScheduleDiagnostic`, `ScheduleSlotTemplate`, `Constraint`, `TeamCoach`, `CoachPlayerMembership`, `Schedule`, `Team`, `Coach`, `Venue`. Retourne 200 avec `deleted`. |

### Identité du club (accent + logo)

Champs `Club` : `accentColor` (hex), `accentPalette` (json ≤3 hex), `logoUrl` — exposés en lecture (ClubResource, `/api/me`).

| Route | Méthode | Contrôleur | Description |
|-------|---------|-----------|-------------|
| `/api/club/appearance` | PATCH | `ClubAppearanceController` | MAJ partielle de l'accent (`accentColor`, `accentPalette`) du club courant (résolu depuis `_club_id`/JWT), validation hex. |
| `/api/club/logo` | POST · DELETE | `ClubLogoController` | Upload (multipart `file`, raster PNG/JPEG/WebP ≤ 500 Ko) / suppression du logo du club courant. Octets stockés via l'abstraction `App\Storage\LogoStorage` (`LocalLogoStorage` en dev ; alias `services.yaml` swappable pour du stockage objet en prod). |
| `/api/clubs/{clubId}/logo` | GET | `ClubLogoController` | Sert le logo (public, stream + mime via finfo). |

### Module démo

Deux mécanismes distincts, à ne pas confondre :

1. **Horloge simulée PAR CLUB** (`Club::$isDemo`/`$demoToday`, `src/Entity/Club.php:102,110`) —
   `DemoAwareClock` (`src/Service/DemoAwareClock.php`) décore l'horloge réelle : si le club
   résolu par le tenant (`_club_id`, posé par `TenantFilterListener` APRÈS le firewall) est
   `isDemo` et porte un `demoToday`, `now()` rend la **date simulée** à l'**heure réelle** dans
   le fuseau réel ; sinon l'horloge est vraie. **Aucune route HTTP n'écrit `demoToday`** — seule
   la commande CLI `app:demo:clock` (`src/Command/DemoClockCommand.php`, options `--club`,
   `--date`, `--clear`) le fait, une action de support (SA4).
2. **`DevClockController`** (`/api/dev/clock`, GET/POST) est un mécanisme **global**, sans
   rapport avec `demoToday` : il pin/relâche l'horloge de TOUTE l'app dans Redis
   (`DevClockStore`), lue par `SimulatedClock` (alias de `ClockInterface` en dev). Gardé par
   `%kernel.debug%` — 404 en environnement non-debug (donc en prod).

Le club de démonstration permanent (BCCL) est créé/réinitialisé par `app:demo:seed`
(`src/Command/DemoSeedCommand.php`, renommée le 2026-09-03, connexion `admin`, options
`--password`/`--email`/`--if-absent`) via
`BcclSeeder` + `BcclSeedProfile` (`src/Seed/`, autant d'identités fictives que de coachs du
seed dev, substituées de façon positionnelle et déterministe — liste courte = refus,
`BcclSeedProfile::FICTIONAL_COACHES`). Le profil **dev** porte, en plus de la structure (équipes,
gymnases, créneaux, coachs, contraintes) et de la transcription du planning réel (§ci-dessous),
les **liens réels du club** (`BcclSeeder::seedTeamLinksAndSharedBlocks`, données fondateur
posées à la lettre) : **10 `TeamLink`** de type `NOT_SIMULTANEOUS` (équipes qui partagent des
joueurs, intensité entraînement au défaut `PREFERRED`) et **8 `SharedTrainingBlock` de SOCLE**
(`schedulePlanId` NULL, `commonSessions=1` chacun — les 3 CEC du mercredi + 5 paires jeunes,
purgés et recréés à chaque run, idempotent). Les créneaux `VenueTrainingSlot` des 8 cases
partagées portent une **capacité de 1** (pas de palliatif de capacité 2/3 — un bloc complet
compte pour UN occupant, `ReservationGroupOccupancy` §SharedTrainingBlock ci-dessus) et les
réservations socle posées sur ces cases sont EXACTEMENT les membres du bloc correspondant.

Le profil **dev** SEUL porte aussi, depuis le 2026-09-03 (section 13bis de `BcclSeeder`,
`BcclSeedProfile::seedWeekendMatchLayout`, `false` en démo et en charge), la **répartition WE
réelle des matchs** du club (données fondateur, xlsx importé le 2026-09-02) : 4
`VenueMatchWindow` (fenêtres d'accès match des gymnases), 32 `TeamMatchHabit` (jour + coup d'envoi
+ gymnase exacts par équipe qui reçoit le week-end) et 8 `MatchSlotRotation` + 16
`MatchSlotRotationTeam` (créneaux physiquement partagés d'Armand et Debarros, alternance
semaine A/B) — zéro `Fixture` (les équipes ne sont pas engagées tant que le calendrier FFBB n'est
pas importé). Idempotent (purge+recréation des fenêtres et des rotations, find-or-create des
habitudes). Détail complet : [`module-matchs.md`](../../specs/courantes/module-matchs.md)
§ « Seed BCCL dev — répartition WE des matchs ». Un club de démonstration **prospect** (à partir d'un code
FFBB réel) se crée par `app:demo:create` (`src/Command/DemoCreateCommand.php`, options
`--ffbb`, `--name`, `--animator-email`, `--animator-password`), dont le cœur (déplacement de
l'animateur, provisioning, populate FFBB + import des équipes engagées, best-effort synchrone)
vit dans `DemoClubMaterializer::materialize()` (`src/Service/DemoClubMaterializer.php`). Trois
contrôleurs dev-only relaient ces gestes en environnement e2e/test/démo (même garde
`%kernel.debug%`, 404 en prod) : `POST /api/dev/approve-club-request` (`DevClubApprovalController`,
approuve la demande PENDING de l'appelant), `POST /api/dev/mark-season-paid`
(`DevSeasonPaymentController`, marque payée la saison SUIVANTE du club courant — respecte
l'horloge simulée), et depuis le 2026-08-20 **`POST /api/dev/demo-register`**
(`DevDemoRegisterController`) — le raccourci « effet waouw » : appelé par `RegisterPage` juste
APRÈS le 202 neutre du vrai register (rail register/verify byte-intact), il fait naître le club
DU PROSPECT depuis le formulaire réel plutôt qu'un terminal, pour l'adresse démo fixe SEULE
(`app.demo_animator_email`, MAISON UNIQUE = `DemoCreateCommand::DEFAULT_ANIMATOR_EMAIL`,
`demo@amateo.fr` — toute autre adresse : 422 sans effet). Ordre des gardes AVANT toute écriture :
mot de passe d'un compte existant **VÉRIFIÉ, jamais écrasé** (401 sans effet) ; code FFBB visé
remplaçable seulement s'il porte la propre démo ISOLÉE de l'animateur — un club réel, la démo
d'un autre animateur ou une démo partagée refusent en 409 ; démontage du club démo précédent de
l'animateur VALIDÉ intégralement avant la moindre destruction (purge du workspace + suppression
de la ligne `club`, pour libérer son code FFBB — `DemoClubMaterializer::teardownPreviousDemo()`,
`DemoTeardownRefusedException` en 409 sinon rien détruit). Une trace d'audit **globale**
(`AuditAction::DEMO_SHORTCUT`, hors périmètre club — elle doit survivre à la destruction de la
ligne club) est posée. La route est exposée au front SEULEMENT en debug par
`GET /api/register/config` (champs additifs `demoShortcut`/`demoEmail`, tous deux
`false`/`null` en prod). `ProdSecretGuard::assertForEnvironment()` (`src/Security/ProdSecretGuard.php`,
invoqué depuis `Kernel::boot()`) refuse désormais de démarrer en environnement `prod` avec
`APP_DEBUG` résolu à `1`/`true` — un verrou qui couvre cette route ET les deux précédentes d'un
seul coup, indépendamment d'un oubli de garde individuelle.

Distinct du club de démonstration : `app:bccl:seed` (`src/Command/BcclSeedCommand.php`, renommée le
2026-09-03, ex `app:seed:bccl-dev`) seede le club **dev BCCL RÉEL** (identités réelles,
`mara.mb@bccl.fr`, code FFBB ARA0069036) via le même `BcclSeeder` + `BcclSeedProfile::dev()`.
**CREATE-ONLY** — à l'inverse d'`app:demo:seed` (créer OU RESET, purge le workspace à chaque appel
sauf `--if-absent`) : cette commande ne fait RIEN (SUCCESS, aucune écriture) si le club existe déjà ;
le reset délibéré passe par `make db-empty` (ou `make reset`, racine) sur une base jetable — les
fixtures Doctrine qui portaient ce rôle avant le 2026-09-03 sont supprimées. Exclue de
l'auto-enregistrement (`services.yaml:96-99`), déclarée seulement dans
`services_dev.yaml`/`services_test.yaml` (jamais dans le conteneur de prod) et gardée en runtime
(refuse hors `dev`/`test`) : invisible en prod par construction — **seule** commande démo/seed à
porter cette restriction, `app:demo:seed` n'en a aucune. Connexion admin requise, comme
`app:demo:seed`. Appelée par `make play` (`backend/docs/commands.md`).

### Cockpit temporel (overlays période/événement)

Détail : [`accueil-cockpit-temporel.md`](../../specs/courantes/accueil-cockpit-temporel.md). `CalendarEntry` (kind PERIOD/EVENT) est le **déclencheur daté** ; le planning de période est un `SchedulePlan` ancré à l'entrée, et c'est **le plan** qui pointe sa version (`chosenScheduleId`). Le pointeur inverse `overlayScheduleId` a été supprimé par ADR-0002 lot D-b.

**Re-dater une racine CLOSURE « d'un bloc » (D3 v1 SOLDÉE ENTIÈRE, ex-P2-57, 2026-09-04, PR-1 backend)** — une période qui porte un plan a normalement son identité GELÉE en écriture (`CalendarEntryStateProcessor::updateEntityFromInput`, 422 « Supprimez la période… ») ; ce cas précis se dégèle : `CalendarEntryPeriodType::CLOSURE`, `parentEntryId === null`, zéro semaine-enfant (`hasWeekChildren`). `PUT` change alors `startDate`/`endDate` dans les deux sens, sous le verrou de scope de `processPut` — `lockClubWindows(clubId, seasonId)` puis `lockPlanScope` (P4-172, 2026-09-04) : (1) `PeriodWindowUniquenessGuard::assertWindowFree` tranche AVANT toute mutation (409 franc, famille exclue) ; (2) le parent applique le PUT ; (3) `SchedulePlanProvisioner::resyncPeriodPlanWindow` (SQL direct, `start_date`/`end_date`/`version+1`) déplace la fenêtre du plan — le plan reste un gabarit hebdo SANS dates ; (4) les contraintes `venue_closed` dont `config.startDate`/`endDate` == l'ANCIENNE fenêtre EXACTEMENT suivent (une fermeture datée plus finement par le gestionnaire ne bouge pas) ; (5) `SchedulePlanProvisioner::renamePeriodPlanIfStillNamed` recale le nom du plan si le titre de l'entrée portait encore l'ancien libellé (inv. 12 : un renommage manuel reste souverain). Rien de neuf à écrire pour la péremption : `ResourceChangeStaleScheduleListener` écoutait déjà le `postUpdate` de `CalendarEntry`. **Tous les autres cas restent gelés** (message 422 distinct) : une racine `holiday` (liée au référentiel des vacances scolaires), une mère découpée, une semaine-enfant, et — même sur une racine CLOSURE redatable — `kind`/`periodType`/`schoolHolidayId`. NR : `Security/PeriodRedateTest`. Le geste d'édition à l'écran (cockpit, PR-2) est livré le même jour — `specs/courantes/accueil-cockpit-temporel.md` §5bis. Complément (même PR) : le prédicat « re-datable » vit UNE fois dans `App\Service\CalendarEntryRedatability::isRedatable()` (racine `closure`, sans mère, avec plan, sans semaines-enfants), consommé par le processor et par la sortie API — `CalendarEntryResource.redatable` (bool servi, une seule requête `EXISTS` par collection, mémoïsée par requête HTTP) ; le re-datage refuse en 422 une fenêtre hors saison (`assertWindowWithinSeason`) et début > fin (déjà porté par `CalendarEntryInput::validateShape`, POST et PUT). **Depuis le 2026-09-05, le re-datage refuse aussi en 422 une nouvelle fenêtre qui se décomposerait en plus d'un segment début·milieu·fin** — `processPut` appelle `App\Service\ClosureSegmentation::fullSegments` (géométrie PLEINE, indépendante de l'horloge, sur la NOUVELLE fenêtre) : `count(...) > 1` ⇒ « Cette indisponibilité aurait une semaine entamée : re-datez-la sur des semaines complètes, ou adaptez-la par début, milieu, fin » — sans cette garde, D3 contournerait le découpage imposé au POST. Une mère déjà découpée en semaines-enfants reste, elle, hors du périmètre `isRedatable` (roadmap **P4-174**, D3 v2 : recalculer les segments d'une mère à enfants au re-datage).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/calendar-entries/{id}/conflicts` | GET | `CalendarEntryConflictsController` | Conflits d'un overlay période vs le planning socle (créneaux impactés). Sert aussi `closures` (fermetures datées : gymnase, titre, bornes, jours fermés) et, depuis P2-37 (2026-08-18), `fullyClosedVenueIds` — les gymnases ENTIÈREMENT fermés sur la fenêtre (niveau GYMNASE et non par fermeture : deux fermetures qui se relaient ne s'exprimeraient pas par un drapeau par fermeture). **P2-38 (2026-08-18)** : pour une entrée qui PORTE un plan, ces trois sorties sont désormais TRANSVERSALES (`PlanVenueClosures::forEntry` — toutes les fermetures du club+saison, chacune bornée à sa propre entrée porteuse ∩ la fenêtre de l'entrée), pas seulement les datées de cette entrée ; une entrée SANS plan (jamais adaptée) garde le périmètre par-entrée historique. **Indispo informative (2026-08-18 soir)** : pour une entrée qui porte un PLAN DE PÉRIODE, la route gagne deux clés — `disabledVenueIds` (gymnases hors service, désactivés OU effectivement fermés-total) et `effectiveClosedWeekdays` (venueId → jour ISO → provenance `default-incident`\|`manual`) — toutes deux dérivées de l'état EFFECTIF (`PlanVenueClosures::effectiveStateForPlan`, incident × masque manuel) ; le front les lit telles quelles, il ne redérive jamais la composition. |

### Module matchs (palier A — FFBB)

Détail : [`module-matchs.md`](../../specs/courantes/module-matchs.md). Placement des rencontres domicile + radar de conflits coach/joueur ; catalogue-ligue global `LeagueMatchWindow` (hors tenant) ; **RMM-6 (2026-08-25)** ajoute la seconde table hors tenant du module, `shared_competition_deadline` (défaut communautaire d'échéance, keyée sur l'id FFBB de compétition, aucune colonne club-identifiante).

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/league-match-windows` | GET | `LeagueMatchWindowsController` | Fenêtres de match héritées de la ligue du club (`Club.league`, fallback fédé AURA). Catalogue global partagé. |
| `/api/venue_training_slots/{id}/deletion-impact` | GET | `DeletionImpactController` | **2026-08-18** — même contrat que les trois routes ci-dessous, pour un CRÉNEAU de disponibilité. Ses enfants ne citent jamais son id : réservations et verrous `HARD` matérialisés s'y rattachent par le **triplet** (gymnase, jour, heure) **et par la COUCHE** — les comptes sont donc bornés à la couche du créneau (grille de saison vs copie de période, invariant fondateur n°1). Les placements SOFT/NONE choisis par le solveur ne sont jamais visés : ce sont des RÉSULTATS. `blocked` toujours faux, `slotsInForce`/`declaredFixtures` toujours 0 (un créneau n'a ni séance en vigueur propre ni match). |
| `/api/venues/{id}/deletion-impact` · `/api/teams/{id}/deletion-impact` · `/api/coaches/{id}/deletion-impact` | GET | `DeletionImpactController` | **P3-16 (2026-08-18)** — ce qu'une suppression VA détruire, calculé par le serveur : `{blocked, reason, lines[{key,count,one,many}], slotsInForce, declaredFixtures}`. Les lignes sont comptées en parcourant `App\Deletion\CascadePlan`, **la même liste** qu'`EntityCascadeDeleter` exécute (maison unique — ajouter une destruction sans son annonce est impossible, NR `DeletionImpactParityTest`). **Les libellés viennent du serveur** : gardés côté écran, une famille ajoutée à la cascade aurait disparu de la modale faute de traduction. `blocked` porte le refus du périmètre engagé (l'écran n'offre plus un geste qui rendrait 409) ; `slotsInForce` = séances touchées vivant dans une version POINTÉE (ADR-0002) ; `declaredFixtures` = les matchs `SUBMITTED`/`VALIDATED` qui perdront leur salle — annoncés, jamais bloquants ; le dépointage lui-même (`Fixture` visé par `venues/{id}/deletion-impact`) délègue à `FixtureVenueLossStep` → `FixtureVenueLossMarker` (RMM-10, P2-52), le MÊME foyer que la gâchette de validation (`/api/schedules/{id}/validate-impact` ci-dessus) — même état final « à placer » + raison `venue_lost`. Lecture seule ; frontière tenant explicite en plus de RLS. Déclarées dans `CustomRoutesOpenApiFactory` et présentes au **snapshot OpenAPI** — `EveryCustomRouteIsDocumentedTest` l'exige de TOUTE route custom (« une route absente du contrat n'existe pour personne »). |
| `/api/fixtures/conflicts` | GET | `FixtureConflictsController` | Radar : conflits d'empreinte-temps coach/joueur entre rencontres et entraînements. Depuis RMM-3 (2026-08-24), chaque item porte un champ additif `fingerprint` (`ConflictFingerprinter`, maison unique) — l'identité stable du conflit, indépendante des champs gradués (severity, segment horaire, compteurs). |
| `/api/matches/module-visit` | POST | `MatchModuleVisitController` | **RMM-3 (2026-08-24)** — le gardien à l'ouverture : stampe une référence de visite PAR (club, saison, utilisateur) et rend le delta « depuis ta dernière visite » (`newFixturesCount`, `newConflictFingerprints`, `planningChanged`). Un seul endpoint POST (pas de GET séparé, pour ne pas ouvrir de course F5 entre lire et tourner la référence) ; première visite muette ; fenêtre de grâce glissante de 30 min (rejoue les mêmes badges sans les éteindre) ; calcul du delta délégué à `MatchModuleDeltaComputer`, coupé de la rotation pour que RMM-6 puisse un jour lire sans stamper. Ouvert au Membre (aucune garde management), écrit même saison archivée (bookkeeping utilisateur, pas une mutation du planning). |
| `/api/venue_match_windows` · `/api/venue_unavailabilities` | CRUD | API Platform (5-fichiers) | **Capacité (P1-4 PR B)** : fenêtres d'accès match (jour+plage, `start<end` même jour) et indisponibilités toutes-circonstances (dates incluses + motif, écriture management-gated). `venueId` étranger invisible → 422. |
| `/api/venue-unavailability-impact` | GET | `VenueUnavailabilityImpactController` | Flux d'alerte cockpit : par indispo, matchs placés touchés + séances d'entraînement des plannings **effectifs** (ADR-0002, `EffectiveScheduleResolver`). Lecture seule, rien persisté. |
| `/api/fixtures/import/analyze` | POST | `ImportFixturesAnalyzeController` | **Dry-run** de l'export FBI global club : table des divisions résolue contre la correspondance persistée (`Competition`), zéro écriture. **RMM-4 (2026-08-24)** : rend aussi `deviations` — les écarts de réconciliation (date/heure/salle) des domiciles déjà **placés**, état app VS état fichier, à décider à l'import. |
| `/api/fixtures/import` | POST | `ImportFixturesController` | Import FBI **une passe** (fichier global + `mappings` JSON) : persiste les correspondances puis crée/**met à jour** par diff `(team, n° FBI)`. Rapport `created`/`updated`/`unchanged`/`exempted`/`warnings`/`unmappedDivisions`/`errors`. Remplace `/api/teams/{id}/fixtures/import` (P1-4 PR A, 2026-08-02). **RMM-4 (2026-08-24)** : accepte en plus `decisions` JSON (`{fixtureId, field: date\|kickoff\|venue, choice: keep_app\|take_file}`, `FbiFixtureImporter::import`) — un écart d'un domicile déjà placé **sans décision n'est plus écrasé en silence**, il reste intact et remonte dans `unresolvedDeviations` du rapport (avec `depositedAt`). `take_file` sur `date`/`venue` dé-place la rencontre (comme un HOME↔AWAY switch) ; sur `kickoff` la rencontre reste placée mais un `SUBMITTED`/`VALIDATED` retombe `PLACED`. Chaque dépôt écrit une `FbiIngestion` (club+saison, `source=FBI_XLSX`, compteurs + `pendingDeviations` — la trace « garder l'app » relue au dépôt suivant : re-divergente → reportée, réconciliée ou fixture disparu → éteinte en silence) ; salle comparée en **fuzzy containment** (`FbiFixtureImporter::venueMatches`), pas égalité stricte. La saison de l'ingestion est **celle de la requête** (`SeasonResolver::selectedOrCurrent`), jamais devinée depuis les lignes importées (revue sécurité 2026-08-24). |
| `/api/fbi-ingestions/latest` | GET | `FbiIngestionFreshnessController` | **RMM-4 (2026-08-24)** — fraîcheur : le dernier dépôt xlsx (`source=FBI_XLSX`) du club+saison courants (`FbiIngestionRepository::latestXlsx`), `null` si aucun. Lecture ouverte au Membre (même patron que `LeagueMatchWindowsController`), tenant+saison résolus côté serveur. |
| `/api/fixtures/place` | POST | `PlaceMatchesController` | « Placer automatiquement » (P1-4 PR D, ADR-0003). Rail **SYNCHRONE** — pas de Messenger/Mercure, verrou Redis dédié `MatchPlacementLock` (TTL 90 s, anti-double-clic). Ordre des gardes : SEC-07 (management) → saison inscriptible → `SocleGuard::assertSeasonPlanChosen` (409 si pas de socle en vigueur). Construit le payload (`MatchPlacementPayloadBuilder`, y compris `TeamLink`/`TeamMatchHabit`), appelle `POST /place-matches` sur l'engine (timeout 60 s, `BAD_GATEWAY` si l'appel échoue — rien n'est écrit avant l'application du résultat), applique les placements (`MatchPlacementResultApplier`). Un match non plaçable n'est **jamais une erreur** : il revient nommé dans `unplaced` avec sa raison. |
| `/api/ffbb/rencontres` | GET | `FfbbRencontresController` | **RMM-4 PR-3 (2026-08-24)** — le canal API FFBB de réconciliation : récupère à la demande les rencontres publiées du club (`FfbbApiClient::searchRencontres`, filtre STRICT serveur sur le code club, index `ffbbserver_rencontres`), les croise avec l'app (`FfbbRencontreReconciler`, appariement 3 étages + tier-0 idempotence sur `Fixture.ffbbRencontreId`) et rend `{deviations[], creatable[], fetchedAt}` — `deviations` réutilise VERBATIM le moteur `FbiFixtureImporter` (même périmètre : domiciles déjà placés) ; `creatable` = les rencontres publiées sans fixture correspondante (mesuré : uniquement des amicaux), proposées à la création, jamais imposées. SEC-07 + `SocleGuard` + tenant ; 422 club sans code FFBB ; 502 FFBB injoignable. |
| `/api/ffbb/rencontres/apply` | POST | `FfbbRencontresController` | **RMM-4 PR-3 (2026-08-24)** — RE-FETCHE côté serveur (jamais les valeurs du client), applique les décisions par écart (mêmes `{fixtureId, field, choice}` que l'import xlsx) et crée les rencontres choisies (`{rencontreId, teamId}`, idempotent sur l'index unique partiel `uniq_fixture_ffbb_rencontre`). Écrit une `FbiIngestion` datée `source=FFBB_API` (compteurs seuls, `pendingDeviations: []`) — ne touche JAMAIS la fraîcheur xlsx (`fbi-ingestions/latest` ne lit que `FBI_XLSX`) ni sa trace. SEC-07 + saison écrivable + `SocleGuard` + tenant ; 409 doublon concurrent (collision sur l'index unique). |
| `/api/competitions/entry-deadlines` | POST | `CompetitionEntryDeadlinesController` | **RMM-6 PR-1 (2026-08-25)** — saisie bulk `{competitionIds[], deadline}` : pose (ou efface, `deadline: null` **explicite**, clé absente → 422) UNE échéance sur un lot de compétitions du club+saison en une transaction ; un id inconnu/étranger → 422, rien écrit. Pour chaque compétition **appariée** (`ffbbCompetitionId` non null) et une date posée (jamais un effacement), upserte aussi `shared_competition_deadline` (dernière écriture gagne, un seul upsert même si deux compétitions du lot partagent le même id fédéral). SEC-07 (management) tire avant tout lookup ; saison archivée → 409. |
| `/api/matches/deadline-outlook` | GET | `EntryDeadlineOutlookController` | **RMM-6 PR-1 (2026-08-25)** — l'outlook cockpit, lecture seule, ouvert au Membre : pour chaque échéance EFFECTIVE (club sinon défaut communautaire) encore due, ses compétitions, le nombre de domiciles restant à saisir et si la fenêtre J-7 (`EntryDeadlineOutlook::REMINDER_WINDOW_DAYS`) est ouverte. Une fenêtre ouverte ET une référence de visite existante joignent le delta gardien (`MatchModuleDeltaComputer`, réutilisé) **sans stamper** — maison unique du J-7, le front ne recalcule rien. |

### Transition de saison (P1/P2)

Détail : [`vacances-scolaires-jours-feries.md`] et roadmap. Bascule de saison au pivot 15 juillet (`SeasonResolver`), re-datation des événements, purge et rappels.

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/seasons/{id}/transition` | POST | `SeasonTransitionController` | Déclenche la bascule vers une nouvelle saison (recap + re-datation, `SeasonTransitionService`). |

### Health check

| Route | Méthode | Contrôleur | Description |
|-------|---------|------------|-------------|
| `/api/health` | GET | `HealthController` | Retourne `{"status":"ok"}`. Public (pas d'auth requise). |

---

## 4. Security / Auth

### JWT (LexikJWTAuthenticationBundle)

- Firewall `login` (`^/api/login`) : `stateless: true`, `json_login` avec `check_path: /api/login`,
  `username_path: email`, `password_path: password`. Succès/échec gérés par Lexik.
- **SEC-16 — le jeton voyage en cookie httpOnly** : `set_cookies.BEARER` + `token_extractors.cookie`
  (`config/packages/lexik_jwt_authentication.yaml`). L'extracteur `authorization_header` reste
  ACTIF : scripts d'ops, contexts Behat (`backend/tests/Behat/BaseContext::mintToken`) et helpers
  e2e ne sont pas des navigateurs et continuent en `Bearer`. `Secure` piloté par
  `JWT_COOKIE_SECURE` (défaut `true`, fail-closed).
  Contrat + pièges : [`jwt-cookie.md`](../../docs/security/jwt-cookie.md).
- Firewall `api` (`^/api`) : `stateless: true`, `provider: app_user_provider`, `jwt: ~`.
- Provider : `app_user_provider` (entity `App\Entity\User`, property `email`).
- Password hasher : `auto` (config `security.yaml`).

### Access control

| Path | Méthode | Rôle |
|------|---------|------|
| `^/api/admin/auth/password$` | — | `PUBLIC_ACCESS` (porte de connexion admin) |
| `^/api/admin/auth/totp$` | — | `PUBLIC_ACCESS` (porte de connexion admin) |
| `^/api/admin` | — | `ROLE_SUPER_ADMIN` (firewall stateful `admin` séparé — §3 Authentification superadmin) |
| `^/api/login` | — | `PUBLIC_ACCESS` |
| `^/api/logout$` | — | `PUBLIC_ACCESS` |
| `^/api/register` | — | `PUBLIC_ACCESS` |
| `^/api/password` | — | `PUBLIC_ACCESS` |
| `^/api/health` | — | `PUBLIC_ACCESS` |
| `^/api/docs` | — | `PUBLIC_ACCESS` |
| `^/api/clubs/[^/]+/logo$` | GET | `PUBLIC_ACCESS` (image de marque publique, SEC-10) |
| `^/api/ffbb-logos/` | GET | `PUBLIC_ACCESS` (logos ligue/comité rehébergés, même motif SEC-10) |
| `^/api/coach-wishes/public/` | GET, POST | `PUBLIC_ACCESS` (le token porte l'identité — #10 C2) |
| `^/api/club-approvals/` | GET, POST | `PUBLIC_ACCESS` (le token porte l'identité — P3-4) |
| `^/api` | — | `IS_AUTHENTICATED_FULLY` |

Seule la première règle correspondante s'applique. Tout le reste de `/api/*` requiert un JWT
valide (ou, sous `/api/admin`, une session superadmin séparée — jamais un JWT club, §3).
Le firewall `login` applique en plus `login_throttling` (`max_attempts: 5`) ; `/api/register` et
`/api/password/forgot` sont rate-limités par IP (`config/packages/rate_limiter.yaml`, sliding window 5/15 min).
**SEC-11** : tout `^/api` **authentifié** est en plus limité **par utilisateur** (limiteur `api`,
sliding window 300/min) via `ApiRateLimitSubscriber` (priorité 6, après firewall + tenant) → 429
au-delà ; les endpoints publics (sans `User`) gardent leur limiteur par IP.

### Résolution du tenant (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, **priorité 7 — APRÈS le firewall
de sécurité (priorité 8)**, pour que l'utilisateur JWT soit déjà authentifié) implémente
l'isolation multi-tenant au niveau de chaque requête. Il **retourne immédiatement** sur
`^/api/admin` (SEC-17, `src/EventListener/TenantFilterListener.php:70`) — la console
superadmin n'a pas de tenant, §3 :

1. **Résolution du clubId** : attribut de requête `_club_id`, sinon header `X-Club-Id`,
   sinon **la membership `ClubUser` active de l'utilisateur JWT** (le frontend n'envoie
   aucun header tenant — c'est le chemin nominal).
2. **Résolution du seasonId** : attribut `_season_id`, sinon header `X-Season-Id` (validé →
   403 si étranger/inconnu), sinon la **saison courante dérivée du calendrier** via
   `SeasonResolver::currentAmong` (pivot 15 juillet — remplace l'ancien lookup unique
   `status='active'`). Le listener pose aussi `_season_readonly` de la saison SÉLECTIONNÉE
   (saison archivée → écriture 409, cf. `SeasonReadonlyTest`) et active le filtre Doctrine
   **`season_filter`** (frontière de correction intra-club, en plus du `TenantFilter` club_id).
   ⚠ **SEC-13** : `SeasonAccessGuard` ne se fie plus au seul header — il prend la **plus stricte**
   de la saison sélectionnée ET de la saison de la RESSOURCE écrite (résolue hors filtres par
   `WriteTargetSeasonResolver`, chaque contrôleur `SeasonScopedWriteInterface` répondant
   `writeTargetSeasonId()`). Sans header, écrire sur un plan/planning d'une saison archivée
   (`clear-grid`/`transcribe`/`/fill`) est désormais refusé 409 au lieu de détruire en silence.
3. **Validation d'appartenance** : si un `clubId` est résolu et un utilisateur est authentifié,
   le listener vérifie qu'un `ClubUser` **actif** existe pour `(userId, clubId)`. Sinon → 403
   (bloque un header `X-Club-Id` spoofé ; une membership `pending` n'a accès à rien).
4. **Filtre Doctrine** : active le filtre `tenant_filter` avec le paramètre `club_id` (UUID).
   Toutes les requêtes Doctrine sur les entités à `club_id` sont automatiquement filtrées.
5. **GUC PostgreSQL** : `TenantConnectionContext::setClubId()` pose `app.club_id` via
   `set_config(..., false)` (session-scoped ; l'ancien `SET LOCAL` hors transaction était un
   no-op). **RLS PostgreSQL ACTIF** (migration `Version20260703120000`, SEC-03) : policies
   `tenant_isolation` FORCE sur toutes les tables à `club_id`, runtime = `amateo_app`. 3 couches :
   filtre Doctrine + RLS + scoping provider/processor pour Club/User (sans `club_id`). Migrations
   et ops via la connexion `admin` (`amateo_owner`, superuser, bypass RLS = porte superadmin).
   Détail : `backend/docs/TENANT.md`, `docs/security/rls.md`.

**Accès API (SEC-01/02/04)** : `Club` GetCollection/Get/Put scopés aux memberships actifs
(Post/Delete retirés) ; `User` self-only (Get/Put ; pas de collection ni Delete) ;
`import-teams` requiert un membership admin sur le club du path. Gardé par
`ClubAccessTest`/`UserSelfOnlyTest`/`ImportAuthorizationTest`/`RlsIsolationTest` (blocking-tests).

---

## 5. Mercure SSE

### Configuration (`config/packages/mercure.yaml`)

- Hub `default` : URL depuis `MERCURE_URL`, public URL depuis `MERCURE_PUBLIC_URL`
  (dérivée du port publié via compose).
- JWT secret depuis `MERCURE_JWT_SECRET` (**dédié, distinct de `JWT_PASSPHRASE`** — SEC-06),
  permission publisher `publish: '*'`. Hub durci (SEC-05) : pas d'abonné `anonymous`,
  `cors_origins` restreint aux frontends dev, pas de `publish_origins *`. Gardé par
  `MercureHardeningTest`.

### Souscription frontend (`MercureAuthController`, FRT-04)

`GET /api/mercure/auth` signe un JWT hub subscriber (même secret `MERCURE_JWT_SECRET` que le
publieur) dont l'autorisation `subscribe` est un **URI template borné au club résolu par le
tenant** — `club:{clubId}:schedule:{id}`, où seul `{id}` varie ; `clubId` revalidé en forme
UUID canonique (défense en profondeur : le sélecteur EST la frontière de sécurité). Le jeton
part en **cookie httpOnly** `mercureAuthorization` (`path: /.well-known/mercure`, `SameSite=Strict`,
`secure` piloté par la MÊME variable `JWT_COOKIE_SECURE` que le cookie JWT applicatif — jamais
`$request->isSecure()`, TTL 3600 s), jamais rendu au JS (même raisonnement que SEC-16 : pas de
second jeton lisible en plus du JWT applicatif). Le frontend consomme
(`frontend/src/features/planning/lib/scheduleStream.ts`) : un seul `EventSource` par session sur
`/.well-known/mercure?topic={topicTemplate}`, reçoit ainsi les mises à jour de TOUTES ses
générations.

### Topic et publication

Le topic Mercure suit le format :

```
club:{clubId}:schedule:{scheduleId}
```

La publication est effectuée par les handlers asynchrones, toujours via l'enveloppe
`App\Mercure\ClubTopicUpdate::private()` (topic privé, publisher `publish: '*'` mais
consommateur borné par le sélecteur JWT ci-dessus) :

- **`GenerateScheduleHandler`** délègue à `ScheduleProgressPublisher` (BCK-04, extraction du
  handler — `src/Service/ScheduleProgressPublisher.php`) : `publish()`/`publishSafely()` (le
  second avale une panne Mercure — best-effort, le front rattrape par polling) publient
  `{scheduleId, status, score, unplaced, warnings}` — à l'entrée en `GENERATING` et à chaque
  état terminal (succès, échec, timeout), pas seulement après import.
- **`ExportPdfHandler`** publie directement sur le hub (`{pdfExportStatus, pdfExportUrl,
  }`) après génération du PDF, et une fois sur échec (planning devenu invisible
  sous RLS — pour ne pas laisser le front tourner en boucle sur `pdfExportStatus`).

La ressource `ScheduleResource` déclare `mercure: true` au niveau de l'attribut `#[ApiResource]`,
ce qui active la diffusion Mercure pour les opérations CRUD standard sur les schedules. La
souscription frontend (cookie, template, `EventSource`) est décrite ci-dessus.

---

## 6. Pagination

Chaque ressource API Platform déclare explicitement `paginationEnabled` (et
`paginationItemsPerPage` quand activée) au niveau de l'attribut `#[ApiResource]`. La majorité
suit le défaut `paginationEnabled: true, paginationItemsPerPage: 30`, mais ce n'est **plus
universel** — vérifier au besoin `grep paginationItemsPerPage backend/src/ApiResource/*.php` :

- **Désactivée** (`paginationEnabled: false` — listes petites, sparse, ou consommées entières
  par un écran) : `SchedulePlan`, `TeamPeriodOverride`, `ConstraintPeriodOverride`,
  `VenuePeriodOverride`, `CoachWish`, `CoachWishCampaign`.
- **Surchargée à 50 ou 100** (listes qui grossissent plus vite que le défaut 30 ne le tolère) :
  `TeamLink`, `TeamMatchHabit`, `VenueUnavailability`, `Competition` (50) ; `Reservation`,
  `Fixture` (100).

Les collections sont servies au format JSON-LD :

- `hydra:member` : tableau des items de la page courante.
- `hydra:totalItems` : nombre total d'items.
- `hydra:view` : liens de navigation (`hydra:first`, `hydra:next`, `hydra:last`, `hydra:previous`).
- Paramètres de requête : `page` (numéro de page), `itemsPerPage` (surchargeable via
  `pagination_client_items_per_page` si activé).
