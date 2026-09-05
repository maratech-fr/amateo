# ClubScheduler — Backend

> Symfony 7 API + admin workflows. Cœur métier de la plateforme.

## Rôle dans l'architecture

Le **backend** est le point central du système. Il expose l'API REST, gère les données métier (clubs, équipes, entraîneurs, plannings), et orchestre la communication entre le frontend et le moteur de calcul.

```
┌─────────────┐         ┌─────────────┐         ┌─────────────┐
│   Frontend  │ ───────▶│   Backend   │ ───────▶│   Engine    │
│   (React)   │  /api/…  │  (Symfony)  │ POST /  │  (Python)   │
│             │ ◀─────── │             │ generate│             │
│             │  JSON    │             │ ◀────── │             │
└─────────────┘         └─────────────┘         └─────────────┘
         ▲                      │
         │ Mercure (SSE)        │
         └──────────────────────┘
```

## Communication inter-services

### Backend → Frontend
- **API REST** : Toutes les requêtes passent par `/api/*` via nginx (port 8080)
- **Mercure (SSE)** : Le backend publie des événements en temps réel sur le topic `club:{clubId}:schedule:{scheduleId}` pour notifier de l'avancement de la génération de planning

### Backend → Engine
- Le backend envoie un **POST** à `http://engine:8000/generate` avec le contexte complet du club (équipes, salles, entraîneurs, contraintes)
- L'engine résout le problème d'optimisation CP-SAT et retourne un planning optimisé
- Le backend importe le résultat et met à jour les entités `ScheduleSlotTemplate`

### Frontend → Backend
- Le frontend React appelle l'API via des URLs relatives (`/api/*`) qui sont proxyfiées par le nginx du frontend vers le backend nginx

## API Routes

Toutes les routes sont exposées sous `/api` via **API Platform** (auto-génération CRUD + OpenAPI docs).

> ⚠️ **URIs en `snake_case`** (`/api/team_coaches`, `/api/venue_training_slots`, `/api/sport_categories`, `/api/priority_tiers`, `/api/schedule_slot_templates`…), **pas** en kebab. La **source de vérité** est l'OpenAPI (`/api/docs`) et l'inventaire [`docs/backend-inventory.md`](docs/backend-inventory.md) ; le tableau ci-dessous est indicatif.

### Ressources métier (CRUD standard)

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `Club` | `/api/clubs` | Clubs/organisations |
| `Season` | `/api/seasons` | Saisons sportives |
| `Team` | `/api/teams` | Équipes (catégorie, priorité, créneaux) |
| `Venue` | `/api/venues` | Salles/lieux de pratique |
| `Coach` | `/api/coaches` | Entraîneurs |
| `User` | `/api/users/{id}` | Utilisateurs — **item seul** : pas de collection (énumération d'emails), pas de `Delete` (voir `DELETE /api/me`) |
| `ClubUser` | `/api/club_users` | Membres du club (rôles) |
| `Sport` | `/api/sports` | Types de sports |
| `SportCategory` | `/api/sport_categories` | Catégories d'âge |
| `PriorityTier` | `/api/priority_tiers` | Niveaux de priorité (S/A/B/C/D) |
| `SubscriptionPlan` | `/api/subscription_plans` | Plans d'abonnement |

### Ressources planning (CRUD standard)

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `SchedulePlan` | `/api/schedule_plans` | **Pivot ADR-0002** — le plan SEASON *pointé* EST le calendrier de la saison (`chosenScheduleId`) ; il porte le **nom** (renommage par `PUT`, SEC-07). « Validé » n'est pas un statut. |
| `Schedule` | `/api/schedules` | Versions de planning (générations) |
| `ScheduleSlotTemplate` | `/api/schedule_slot_templates` | Créneaux générés |
| `ScheduleDiagnostic` | `/api/schedule_diagnostics` | Erreurs/avertissements |
| `Reservation` | `/api/reservations` | Créneaux réservés (pins `HARD` durables) |

### Ressources contraintes & liens

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `Constraint` | `/api/constraints` | Contraintes **unifiées** (familles TIME/DAY/FACILITY/COACH_AVAILABILITY/FACILITY_CAPACITY · scope CLUB/TEAM/COACH/FACILITY · `config.targetTag` pour cibler un groupe) |
| `VenueTrainingSlot` | `/api/venue_training_slots` | Disponibilités hebdo des salles (jour, heure, durée, capacité 1/2) |
| `TeamCoach` | `/api/team_coaches` | Assignations entraîneur-équipe (MAIN/ASSISTANT) |
| `CoachPlayerMembership` | `/api/coach_player_memberships` | Entraîneurs aussi joueurs |

### Ressources cockpit temporel & matchs

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `CalendarEntry` | `/api/calendar_entries` | Périodes/événements du cockpit (kind PERIOD/EVENT). Le planning de période est un `SchedulePlan` ancré à l'entrée — le pointeur inverse `overlayScheduleId` a été supprimé (ADR-0002 lot D-b) |
| `Competition` | `/api/competitions` | Compétitions FFBB (championnat/coupe/brassage) — module matchs palier A |
| `Fixture` | `/api/fixtures` | Rencontres (HOME/AWAY, placement domicile, `externalRef` = n° FBI) |

### Ressources de période (#8 — la période possède sa grille)

Réglages **sparses** ancrés au **plan** (`schedulePlanId`) : pas de ligne = hériter du modèle de saison.

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `VenuePeriodOverride` | `/api/venue_period_overrides` (+ `/reset-grid`, `/clear-grid`) | Comportement d'un gymnase sur la période (`DISABLED` / `BLANK`) |
| `TeamPeriodOverride` | `/api/team_period_overrides` | Équipe activée/désactivée + `sessionsPerWeek` sur la période |
| `ConstraintPeriodOverride` | `/api/constraint_period_overrides` | Contrainte permanente activée/désactivée sur la période |

### Ressources doléances coachs (#10)

| Ressource | Endpoint | Description |
|-----------|----------|-------------|
| `CoachWishCampaign` | `/api/coach_wish_campaigns` (+ `/send-links`, `/remind`) | Campagne de collecte (périmètre, semaines, deadline) |
| `CoachWish` | `/api/coach_wishes` | Doléances déposées |
| — | `/api/coach-wishes/public/{token}` | **Route PUBLIQUE, sans JWT** (GET/POST) — voir `AGENTS.md` §18 |

### Opérations custom (au-delà du CRUD)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/login` | POST | Authentification JSON → JWT (`json_login`, `security.yaml`) |
| `/api/register` | POST | Inscription — compte non vérifié, **202 générique** (anti-énumération A3, aucun token) ; envoie un lien de vérification par email (`AuthController`) |
| `/api/register/verify` | POST | Consomme le token du lien email → vérifie le compte, crée/rejoint le club, **émet le JWT** (login effectif) |
| `/api/me` | GET/PATCH | Profil JWT + contexte club (`AuthController`) |
| `/api/me` | DELETE | **Effacement RGPD self-service** (`DeleteAccountController`) — self-only (aucun id en entrée), confirmé par **ré-authentification mot de passe** ; anonymisation immédiate, club orphelin purgé après 30 j de grâce |
| `/api/constraints/validate` | POST | Gate pré-solveur : valide les contraintes + détecte les conflits (200/422) |
| `/api/schedule-slots/{id}/manual-edit/{constraint,lock,one-time}` | POST | Ajustements manuels de créneau (boucle de travail) |

### Opérations custom

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/health` | GET | Health check (nginx → php-fpm) |
| `/api/schedules/{id}/generate` | POST | Lancer la génération de planning (async). ⚠️ **Trois refus synchrones possibles** : 409 si la version est celle que son plan pointe (rouvrir d'abord), 422 si `GenerationComplexityGuard` juge le problème hors bornes (A10), 422 si `OrphanPinGuard` trouve un épinglage orphelin |
| `/api/schedules/{id}/reopen` | POST | **Rouvrir** (dépointer) la version en vigueur — obligatoire avant de régénérer |
| `/api/schedules/{id}/regenerate` | POST | Relancer une génération sur la même version |
| `/api/schedules/{id}/regenerate-from` | POST | Restaurer la structure photographiée par une version (D3) puis regénérer |
| `/api/schedules/{id}/export-pdf` | POST | Exporter le planning en PDF (async) — produit aussi un PNG best-effort |
| `/api/schedules/{id}/export-xlsx` | POST | Exporter le planning en tableur |

### Opérations cockpit / matchs / transition / calendriers (invokables)

| Route | Méthode | Description |
|-------|---------|-------------|
| `/api/calendar-entries/{id}/conflicts` | GET | Conflits d'une période vs la version pointée du plan SEASON (cockpit) |
| `/api/league-match-windows` | GET | Fenêtres de match héritées de la ligue du club (catalogue global, fallback AURA) |
| `/api/fixtures/conflicts` | GET | Radar conflits coach/joueur des rencontres (module matchs) |
| `/api/teams/{id}/fixtures/import` | POST | Import FBI des rencontres (.xlsx par équipe) |
| `/api/season-transition` | GET/POST | Recap + bascule de saison (P1/P2) |
| `/api/school-holidays`, `/api/public-holidays` | GET | Vacances scolaires / jours fériés (tables globales) |
| `/api/club/ffbb-import` | POST | Ré-import des données institutionnelles depuis l'API FFBB (rôle management) |
| `/api/admin/**` | — | Console superadmin SA0 — **firewall séparé** (session + TOTP), jamais atteignable avec un JWT club |

> Source de vérité exhaustive = OpenAPI (`/api/docs`) + snapshot `specs/courantes/openapi-snapshot.json`. Le tableau reste indicatif (pas de décompte figé).

### Documentation OpenAPI
- `http://localhost:8080/api/docs` — Swagger UI
- `http://localhost:8080/api/docs.json` — OpenAPI JSON

## Commandes principales

```bash
# Toutes les commandes s'exécutent DANS le conteneur php-fpm
# Le Makefile les lance automatiquement dans le conteneur

make install          # composer install
make test             # PHPStan + CS-Fixer + PHPUnit --testsuite Unit
                      #   ⚠️ PAS le gate bloquant : ni --group phase1, ni tests/ entier
make tests-complete   # PHPStan + CS-Fixer + `phpunit tests/` (le DOSSIER entier)
                      #   miroir EXACT du job CI — à lancer AVANT de pousser
make lint             # CS-Fixer + PHPStan + Rector
make phpstan          # PHPStan seul (niveau 8)
make cs-fix           # CS-Fixer (auto-format)
make db-init-test     # crée + migre la base de TEST (requis avant `make phpunit`)
make phpunit          # PHPUnit --group phase1 (le gate bloquant)
make behat            # Toutes les features Gherkin FR (API réelle, quelques minutes, générations réelles) — with-sandbox.sh en mode play
                      #   une feature seule : vendor/bin/behat features/<x>.feature (dans php-fpm)
make coverage         # Couverture (pcov) + cliquet coverage-floor.json (commands.md)
make db-init          # crée + migre la base de dev — idempotent, ne détruit rien
make db-empty         # drop + recreate + migre la base de dev VISÉE (DESTRUCTIF, gardé — commands.md)
make seed-bccl        # club dev BCCL réel (create-only, no-op si présent — commands.md)
make seed-demo        # club de démo (créer OU reset — commands.md)
make jwt-keys         # génère le keypair JWT s'il est absent (config/jwt/*.pem, gitignoré)
make migration-diff   # génère une migration depuis le diff d'entités
make migration-migrate # applique les migrations en attente (suit APP_ENV)
make exec             # Entrer dans le conteneur php-fpm
```

> Les migrations passent par la connexion `admin` (`config/packages/doctrine_migrations.yaml`,
> `connection: admin`) : elles portent le DDL et les policies RLS, que le rôle applicatif
> `amateo_app` n'a pas le droit d'exécuter.

> ⚠️ Commandes backend = **dans Docker** (le Makefile enveloppe `docker compose exec`). Elles échouent sur l'hôte. La suite de tests a besoin de la base de test → `make db-init-test` d'abord.

## Architecture interne

```
backend/
├── src/
│   ├── ApiResource/          # ressources API Platform (liste : ls src/ApiResource/)
│   ├── Entity/               # entités Doctrine (liste : ls src/Entity/)
│   ├── Controller/           # Contrôleurs custom (liste : ls src/Controller/)
│   │   ├── HealthController.php
│   │   ├── GenerateScheduleController.php   # POST /api/schedules/{id}/generate
│   │   └── ExportPdfController.php         # POST /api/schedules/{id}/export-pdf
│   ├── MessageHandler/
│   │   └── GenerateScheduleHandler.php      # Appel HTTP → Engine
│   │   └── ExportPdfHandler.php
│   ├── Service/
│   │   ├── ScheduleConstraintBuilder.php    # Construction payload Engine
│   │   ├── ScheduleResultImporter.php       # Import résultat Engine
│   │   └── ClubGenerationLock.php           # Verrou Redis
│   ├── State/Provider/       # State providers API Platform
│   ├── State/Processor/      # State processors API Platform
│   └── DataFixtures/         # Jeux de données
├── config/
│   └── packages/mercure.yaml # Config Mercure hub
├── migrations/               # Migrations Doctrine
└── public/                   # Point d'entrée nginx
```

## Flux de génération de planning

```
1. Frontend        POST /api/schedules/{id}/generate
2. Backend         Crée Schedule + envoie GenerateScheduleMessage (bus async)
3. MessengerWorker Execute GenerateScheduleHandler
4. Handler         Build payload via ScheduleConstraintBuilder
5. Handler         POST http://engine:8000/generate
6. Engine          Résout CP-SAT + retourne slots
7. Handler         Importe résultat via ScheduleResultImporter
8. Handler         Publie Mercure: club:{clubId}:schedule:{scheduleId}
9. Frontend        Reçoit SSE → rafraîchit le calendrier
```

## Pour aller plus loin (docs structurantes)

| Doc / script | Contenu |
|--------------|---------|
| [`scripts/generate-schedule.sh`](scripts/generate-schedule.sh) | **Guide pratique** — pilote create → generate → poll une génération via l'API (vraie aide pour tester/déboguer le flux). |
| [`features/`](features/) | **Tests fonctionnels Behat** (Gherkin FR) — une feature par promesse métier, jouées contre l'API réelle. `make behat` les joue toutes ; détail de chacune : [`docs/testing/test-coverage-map.md`](../docs/testing/test-coverage-map.md) §5. Les 5 premières ont remplacé les smokes bash (`backend/scripts/*smoke*.sh`, supprimés — P4-165) ; les suivantes (P4-175) couvrent les règles qui détruisent/refusent/isolent. |
| [`docs/TENANT.md`](docs/TENANT.md) | **Isolation multi-tenant** (cœur sécurité) — `TenantFilter` + `TenantFilterListener` (priorité 7, après le firewall) + résolution du club depuis le JWT. |
| [`docs/RLS.md`](docs/RLS.md) | PostgreSQL Row-Level Security : rôles DB, policies, activation sur une nouvelle table. |
| [`docs/commands.md`](docs/commands.md) | **Référence complète des commandes** — cibles make, console `app:*`, pièges RLS (`dbal:run-sql`), scripts. |
| [`docs/ffbb-api.md`](docs/ffbb-api.md) | **Intégration FFBB** — les routes des API publiques FFBB utilisées (Meilisearch + api.ffbb.com), confinement SSRF, cache. |
| [`docs/geo-api.md`](docs/geo-api.md) | **Intégration géo** — BAN (géocodage adresse) + IGN Géoplateforme (itinéraires), confinement SSRF, l'autofill de la matrice de temps de trajet (P2-53). |
| [`docs/constraint-coverage.md`](docs/constraint-coverage.md) | Couverture des besoins gestionnaire par le système de contraintes (✅/🟡/❌). |
| [`docs/error-copy.md`](docs/error-copy.md) | **Copie des messages d'erreur** — la règle de langue (français dès qu'un gestionnaire peut lire ; anglais toléré = défense pure/API-only/admin/≥500), codes machine et 404 à parité intouchables. |
| [`docs/constraints.md`](docs/constraints.md) · [`docs/generation-flow.md`](docs/generation-flow.md) · [`docs/schedule-generation-guide.md`](docs/schedule-generation-guide.md) | Docs pédagogiques (contraintes métier, pipeline de génération, guide pas-à-pas) — ex-`doc/`, fusionné 2026-07-11. |
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent (conventions CS-Fixer/PHPStan/Rector, flux services, gotchas). |

**Contraintes = cœur métier.** Elles sont *persistées/exposées* ici (`Constraint` + `ScheduleConstraintBuilder` qui construit le payload solveur, dont `resolveTagToTeamIds` pour cibler un groupe) et *résolues* par l'engine — voir [`engine/docs/business.md`](../engine/docs/business.md).

## Environnement

- **PHP** : 8.4
- **Framework** : Symfony 7
- **API** : API Platform
- **DB** : PostgreSQL 16 (via `amateo-postgres`)
- **Cache** : Redis (via `amateo-redis`)
- **Message Bus** : Symfony Messenger + Redis
- **Real-time** : Mercure (SSE)
- **Port** : 9000 (php-fpm interne) — exposé via nginx 8080
