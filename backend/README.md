# Amateo — Backend

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

## API

Toutes les routes sont exposées sous `/api` via **API Platform** (CRUD auto-généré + OpenAPI docs),
plus une série de contrôleurs custom (génération/exports de planning, cockpit temporel, module
matchs, import FFBB, transition de saison, console superadmin). **Ce README ne les recopie pas** —
source de vérité exhaustive :

- `http://localhost:8080/api/docs` (Swagger UI) / `.../api/docs.json` (OpenAPI JSON)
- [`docs/backend-inventory.md`](docs/backend-inventory.md) — inventaire ressource par ressource et contrôleur par contrôleur
- [`specs/courantes/openapi-snapshot.json`](../specs/courantes/openapi-snapshot.json) — snapshot figé consommé par le frontend

Quelques repères pour s'orienter avant d'aller lire l'inventaire :
- URIs API Platform en **snake_case** (`/api/venue_training_slots`, `/api/sport_categories`…), **jamais** en kebab-case.
- `SchedulePlan` (pivot **ADR-0002**) : le plan *pointé* **est** le calendrier de la saison ou de la période — `docs/architecture/adr-0002-pattern-plan.md`.
- `/api/admin/**` : console superadmin SA0, **firewall séparé** (session + TOTP), jamais atteignable avec un JWT club.
- La seule route `/api/*` sans JWT : `GET|POST /api/coach-wishes/public/{token}` (le token EST l'identité) — voir `AGENTS.md` gotcha 18.

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

> ⚠ Commandes backend = **dans Docker** (le Makefile enveloppe `docker compose exec`). Elles échouent sur l'hôte. La suite de tests a besoin de la base de test → `make db-init-test` d'abord.

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
│   └── DataFixtures/         # assets (logos…) — pas de classes de fixtures ; les jeux de données réels viennent de `Seed/` (`make seed-bccl`/`make seed-demo`)
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
| [`features/`](features/) | **Tests fonctionnels Behat** (Gherkin FR) — une feature par promesse métier, jouées contre l'API réelle. `make behat` les joue toutes ; détail de chacune : [`docs/testing/test-coverage-map.md`](../docs/testing/test-coverage-map.md) §5. |
| [`docs/TENANT.md`](docs/TENANT.md) | **Isolation multi-tenant** (cœur sécurité) — `TenantFilter` + `TenantFilterListener` (priorité 7, après le firewall) + résolution du club depuis le JWT. |
| [`docs/RLS.md`](docs/RLS.md) | PostgreSQL Row-Level Security : rôles DB, policies, activation sur une nouvelle table. |
| [`docs/commands.md`](docs/commands.md) | **Référence complète des commandes** — cibles make, console `app:*`, pièges RLS (`dbal:run-sql`), scripts. |
| [`docs/ffbb-api.md`](docs/ffbb-api.md) | **Intégration FFBB** — les routes des API publiques FFBB utilisées (Meilisearch + api.ffbb.com), confinement SSRF, cache. |
| [`docs/geo-api.md`](docs/geo-api.md) | **Intégration géo** — BAN (géocodage adresse) + IGN Géoplateforme (itinéraires), confinement SSRF, l'autofill de la matrice de temps de trajet. |
| [`docs/constraint-coverage.md`](docs/constraint-coverage.md) | Couverture des besoins gestionnaire par le système de contraintes (✅/🟡/❌). |
| [`docs/error-copy.md`](docs/error-copy.md) | **Copie des messages d'erreur** — la règle de langue (français dès qu'un gestionnaire peut lire ; anglais toléré = défense pure/API-only/admin/≥500), codes machine et 404 à parité intouchables. |
| [`docs/constraints.md`](docs/constraints.md) · [`docs/generation-flow.md`](docs/generation-flow.md) · [`docs/schedule-generation-guide.md`](docs/schedule-generation-guide.md) | Docs pédagogiques : contraintes métier, pipeline de génération, guide pas-à-pas. |
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
