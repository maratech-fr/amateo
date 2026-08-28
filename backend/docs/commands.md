# Commandes backend — référence complète

Last verified @ 2026-08-28 (P4-141 — nouvelle section « Les 3 bases locales » en tête : modèle
`amateo_dev`/`amateo_local`/`amateo_test` (+`amateo` prod), cibles `make play`/`make sandbox`,
garde-fou `backend/scripts/lib/sandbox-guard.sh` et sa limite assumée — vérifié contre le code
(la lib existe, allowlist `amateo_dev`/`*_test`, fail-closed sur base non résolue ; les 8 scripts
mutateurs la sourcent). Passe précédente (2026-08-27) : la liste canonique des blocking-tests vit
dans `docs/testing/blocking-tests.md`, la ligne `make phpunit` la cite à sa nouvelle adresse ✓.
Re-confronté au code : les scripts de
`backend/scripts/*.sh` toujours tous listés (`ls` ✓, aucun ajout/retrait depuis la dernière passe) ;
cibles Make de la table toujours toutes présentes dans `backend/Makefile` (`test`, `tests-complete`,
`phpunit`, `db-init`/`db-init-test`, `db-reset`/`db-reset-test`, `fixtures`, `phpstan`/`cs`/`cs-fix`/
`rector`, `lint`, `migration-diff`/`migration-migrate`, `fix-perms`, `exec`, `jwt-keys`, `install`
✓) ; `app:schedules:reconcile-stuck` confronté à `ReconcileStuckSchedulesCommand.php` (existe) ✓.
Tout juste, rien à corriger. Non re-sondé cette passe : horaires exacts du catalogue de jobs,
pièges RLS des commandes Doctrine, le motif `.pdf` seul d'`app:exports:purge` — un stamp REMPLACE,
l'historique vit dans git : `git log -p --follow backend/docs/commands.md`.

> **Tout se lance dans le container** (`docker compose exec php-fpm …`) — les cibles `make`
> le font pour toi. PHPUnit exige `APP_ENV=test` (sinon `test.service_container` introuvable).
> La base : `make help` affiche cette liste côté Makefile.

## Les 3 bases locales — et les deux commandes qui basculent (P4-141, 2026-08-28)

Une stack pointe **une base à la fois**. Le défaut committé est le **bac à sable**, jamais la base de jeu.

| Base | Rôle | Qui l'écrit |
|---|---|---|
| `amateo_dev` | **bac à sable** — seed/purge à volonté | l'IA : smokes, e2e, démos, parcours navigateur (**défaut committé**) |
| `amateo_local` | **base de jeu du fondateur** | le fondateur seulement — mes scripts la REFUSENT |
| `amateo_test` | tests unitaires (DAMA, transactionnelle) | phpunit — **même en mode play** (`.env.test` garde la main dans l'ordre dotenv) |
| `amateo` | base de PROD | rien en local |

- **`make play`** — bascule sur la base de jeu : crée `amateo_local` si absente, migre, `app:demo:seed-bccl`, écrit `backend/.env.local` (gitignoré) et redémarre `messenger-worker`+`cron-runner` (ils tiennent la config en mémoire).
- **`make sandbox`** — retour au bac à sable : supprime `backend/.env.local` + même redémarrage.
- La bascule vit **au niveau dotenv, jamais dans compose** : injecter `DATABASE_URL` par compose écraserait `.env.test` (env réel > dotenv) et enverrait phpunit sur la base de dev.

🔴 **Le garde-fou (`backend/scripts/lib/sandbox-guard.sh`)** est sourcé par **tous** les scripts mutateurs. Il résout la base RÉELLEMENT visée (`SELECT current_database()` via php-fpm, donc il respecte toute la précédence dotenv) et **meurt** (`exit 1`) sauf si la cible est `amateo_dev` ou `*_test` — il refuse `amateo_local`, `amateo`, un nom inconnu, **et une base non résolue** (fail-closed). Lancer un smoke en mode play échoue bruyamment **sans rien écrire**. ⚠ Limite assumée : `SANDBOX_GUARD_LOADED=1` court-circuite la vérification (variable interne, héritée parent→enfant par conception) — le garde protège des ACCIDENTS, pas d'un contournement délibéré.

`make -C backend db-drop-legacy` (optionnelle) supprime les anciennes `clubscheduler`/`clubscheduler_test` restées inertes dans le volume.

## Cibles Make (`backend/Makefile`)

| Cible | Effet |
|-------|-------|
| `make install` | `composer install` dans le container |
| `make test` | PHPStan + CS-Fixer + PHPUnit **`--testsuite Unit`** (⚠️ PAS le gate bloquant : ni `--group phase1`, ni `tests/` entier) |
| `make tests-complete` | PHPStan + CS-Fixer + **`phpunit tests/`** (le DOSSIER entier — miroir EXACT du job CI `Unit Tests` ; seule cible qui voit Api/Command/Double/EventListener/MessageHandler/OpenApi/Validator) |
| `make phpunit` | PHPUnit **`--group phase1`** seul (`APP_ENV=test` injecté) — ⚠ **ce n'est pas « le gate »** : le groupe compte plusieurs fois plus de fichiers que le job CI `blocking-tests` n'a de steps nommés (les décomptes exacts pourrissent en jours — `ci.yml` fait foi). La cible **couvre** le gate mais ne s'y réduit pas (liste : `docs/testing/blocking-tests.md`) |
| `make tests-engine-semantics` | PHPUnit **`--group contract`** — les tests qui interrogent le **VRAI moteur** (job CI dédié et bloquant « Engine semantics ») : chaque clé de la liste blanche `config` doit **CHANGER** le résultat du solveur, le miroir de capacité doit rendre le même verdict que lui, le payload doit rester recevable. ⚠ `tests-complete` les **exclut** (`--exclude-group contract`), exactement comme `unit-tests` en CI : sans cette cible, ils ne tournent jamais en local |
| `make db-init` | Crée + migre la base de **dev** — idempotent, ne détruit rien |
| `make db-init-test` | Crée + migre la base de test (**pré-requis de toute suite**) |
| `make jwt-keys` | Génère le keypair JWT s'il est absent (`config/jwt/*.pem`, gitignoré) — idempotent |
| `make db-reset` / `make db-reset-test` | Drop + recreate + migrate (dev / test) |
| `make fixtures` | Fixtures dev + seed jours fériés/vacances — **injecte l'URL admin** (RLS : ne JAMAIS lancer `doctrine:fixtures:load` à la main, le purge silencieux casse) |
| `make phpstan` / `make cs` / `make cs-fix` / `make rector` | Analyses (cs/rector en dry-run, `cs-fix` applique) |
| `make lint` | PHPStan + CS + Rector (tout en dry-run) |
| `make migration-diff` / `make migration-migrate` | Diff / applique les migrations (connexion **admin**) |
| `make fix-perms` | Répare les droits de `var/generate` (rapports lisibles côté host) |
| `make exec` | Shell dans le container php-fpm |

## Commandes console custom (`php bin/console app:…`)

Toutes manuelles sauf mention. Détail : `ls backend/src/Command/`.

| Commande | Effet |
|----------|-------|
| `app:superadmin:create <email>` | Crée une identité superadmin séparée ; demande le mot de passe interactivement et affiche une seule fois la clé/URI TOTP |
| `app:jobs:run <clé> [--source=cli\|scheduled] [--scheduled-for=<ISO-8601>]` | Exécute exclusivement un job du catalogue opérationnel fermé, empêche le chevauchement et persiste statut/durée/code de sortie dans `admin_job_run` ; `--scheduled-for` est interne et obligatoire avec `--source=scheduled` |
| `app:jobs:run-due` | Tick du `cron-runner` chaque minute : calcule les créneaux dus en `Europe/Paris`, rattrape le dernier créneau manqué après redémarrage et garantit au plus une exécution par `(job, créneau)` |
| `app:schedules:reconcile-stuck` | Passe en FAILED les plannings bloqués PENDING/GENERATING (crash worker / message perdu) — **auto, toutes les 10 min** (avec `--older-than=60`) |
| `app:constraint:export-implicit` | Exporte la config des contraintes implicites en JSON (versionnée avec le contrat) |
| `app:overlays:purge` | Supprime les versions overlay des périodes échues — manuel, jamais auto |
| `app:seasons:purge` | Supprime les saisons < N-1 (rétention : courante + précédente + futures) — **auto, quotidien à 03:00 (Europe/Paris)** |
| `app:users:purge-inactive` | RGPD rétention : préavis email à 23 mois d'inactivité, anonymisation à 24 mois (préavis ≥ 1 mois exigé) — **auto, quotidien à 02:30** |
| `app:audit:purge` | RGPD : purge le journal d'audit > 12 mois — **connexion admin** (append-only : le rôle runtime n'a pas de policy DELETE) — **auto, quotidien à 03:30** |
| `app:exports:purge` | Supprime les rendus PDF **orphelins** (planning disparu) et ceux de **plus de 90 jours** — le PNG a quitté TOTALEMENT le projet le 2026-08-21, le motif de fichier n'accepte plus que `.pdf` (`PurgeExportsCommand.php:61`) — **sauf** l'export que pointe `Season.exportPdfUrl` — cet épinglage-là remplace la colonne `is_pinned` du croquis v3, qui aurait supposé un geste qu'aucun écran n'offre. **Connexion admin** (purge transverse : les policies RLS étant en `FORCE`, sans contexte de club une requête ne rend AUCUNE ligne) — **auto, quotidien à 03:45** ; `--dry-run` / `--days` |
| `app:purge-orphans` | Nettoie les orphelins logiques pré-cascade (réservations orphelines, liens pendants) — manuel |
| `app:users:purge-unverified` | Supprime les comptes non vérifiés > 7 j — **auto, quotidien à 02:00** |
| `app:clubs:purge-erased` | RGPD : purge le workspace des clubs dont le délai de grâce d'effacement (30 j) est échu — l'identité publique FFBB survit — **auto, quotidien à 02:15** |
| `app:coach-wishes:digest` | Digest quotidien des doléances (#10 C3) aux gestionnaires : email **seulement si nouvelle réponse depuis la veille** (silence = rien) + récap **une fois** le lendemain de la deadline, quel que soit l'état — **auto, quotidien à 07:00** ; `--dry-run` / `--date` |
| `app:periods:remind` | Emails J-14/J-7/J-3 aux gestionnaires : période sans plan overlay — n'agit jamais seul — **auto, quotidien à 08:00** |
| `app:seasons:remind-transition` | Emails J-61/J-30/J-14 avant le pivot du 15 juillet : saison N+1 non préparée — **auto, quotidien à 08:00** |
| `app:public-holidays:seed` / `app:public-holidays:import` | Jours fériés : seed offline (JSON embarqué) / import API etalab — idempotents ; import **auto trimestriel (1er janv./avr./juil./oct. à 04:30)** |
| `app:school-holidays:seed` / `app:school-holidays:import` | Vacances scolaires : seed offline / import API Éducation nationale — idempotents ; import **auto trimestriel (1er janv./avr./juil./oct. à 04:00)** |
| `app:league-windows:seed` | Catalogue des fenêtres de matchs par ligue (JSON AURA) — idempotent |
| `app:clubs:backfill-school-zone` | Déduit `Club.schoolZone` du code FFBB (dry-run sans `--apply`) |
| `app:club-approvals:digest` | P3-4 PR B : relance les demandes de création de club (3 j restants + jour J) et expire les échues (la console superadmin garde la main) ; `--dry-run`, `--date` — **auto, quotidien à 08:30** |
| `app:clubs:ffbb-resync` | SA4/P2-18 : ré-importe l'identité FFBB de `--club=<id>` (FfbbClubPopulator refresh — nom, coordonnées, logo, comité/ligue) ; échec franc si organisme introuvable — action support, aussi déclenchable depuis la console admin |
| `app:demo:seed-bccl` | P2-4 : (re)crée le club de DÉMONSTRATION permanent « Démo Basket Club » — la structure terrain du BCCL sous identités FICTIVES (club, gestionnaire `--email` défaut demo-bccl@amateo.fr, autant d'identités fictives que de coachs du seed dev — anonymisation STRICTE, liste courte = refus). Reset = même geste : purge du workspace (`ErasedClubPurger`) + re-seed, retour exact à l'état de base. `--password` (min 12) requis à la PREMIÈRE création seulement. ⚠ Connexion ADMIN requise comme `make fixtures` (`DATABASE_URL=$DATABASE_ADMIN_URL`) — le garde superuser du seeder refuse sinon |
| `app:load-test:seed-clubs` | Mesure de charge : seed `--count=N` (1..99) clubs JETABLES taille BCCL (`club-charge-N`, codes `ARA99990NN` hors plage réelle, coachs fictifs, offre Bêta posée par le seeder). **DEV-ONLY par construction** : non enregistrée hors env dev (services_dev.yaml) + garde runtime + garde superuser du seeder (connexion ADMIN requise). Consommée par `backend/scripts/load-test/run-load-test.sh` — procédure : `docs/ops/load-test.md` |
| `app:demo:create` | P2-4 : crée un club de DÉMONSTRATION depuis `--ffbb=<code>` (`--name` requis) et y REPOINTE le compte animateur (`--animator-email`, défaut demo@amateo.fr ; `--animator-password` requis au premier passage) — adhésions précédentes supprimées (une seule active), populate FFBB synchrone best-effort **+ import des équipes engagées** (même étage que le vrai register — hors saison des poules : 0 équipe, no-op naturel), club non onboardé (le wizard guidé EST la démo). Le geste (`materialize()`) vit désormais dans `DemoClubMaterializer`, extrait pour être partagé avec la route dev `POST /api/dev/demo-register` (le raccourci démo du register, même compte animateur — détail : [`backend-inventory.md`](backend-inventory.md) §Module démo) : **deux** chemins posent `is_demo` depuis le 2026-08-20, plus un seul. Le flag exempte aussi la bascule de saison du gate paiement P1-5 (« abonnement illimité »). CLI seulement (arguments libres — hors catalogue console) |
| `app:demo:clock` | P4-16/P2-4 : pose (`--date=YYYY-MM-DD`) ou relâche (`--clear`) l'« aujourd'hui » simulé de `--club=<id>` — serveur (DemoAwareClock) ET front (`/api/me` → clock.ts) vivent à cette date ; réservé aux clubs de démonstration (`is_demo`) — action support **CLI seulement** (le catalogue console n'injecte que `--club`, jamais de date) |
| `app:clubs:mark-next-season-paid` | SA4/P1-5 : marque la saison SUIVANTE de `--club=<id>` comme payée (abonnement par saison — ouvre le gate de bascule) ; idempotent, le marqueur ne recule jamais — action support, aussi déclenchable depuis la console admin. Un club de démonstration épinglé (`is_demo` + `demo_today`) pivote sur sa date SIMULÉE, pas sur l'horloge réelle (D6 — sinon la démo de bascule ment) |
| `app:clubs:set-plan` | P1-3 / A3 : attribue l'offre `--plan=<code>` (`decouverte`/`essentiel`/`club`/`grand-club`/`sans-limite`/`beta`) à `--club=<id>` — SEULE porte d'attribution (v1 = virement + geste superadmin ; l'offre Bêta n'a pas d'autre chemin par construction). Option `--paid-season=<current\|next>` : pose l'offre ET marque la saison encaissée (`paid_season_year = GREATEST(…)`, monotone) dans la MÊME transaction — pivot sur `demo_today` pour un club démo épinglé (D6). ⚠ **Une offre payante n'est EFFECTIVE qu'avec une saison réglée** (Bêta comprise, sinon elle naît expirée → Découverte) : c'est le rôle de `--paid-season`. **Interdit avec `decouverte`** (rien à encaisser). Sans l'option, l'offre est posée seule (voie CLI directe). Une SEULE entrée console « Offre » à schéma fermé (`plan` + `paidSeason` conditionnel) |
| `app:clubs:reset-credits` | P1-3 PR A : remet `outputCreditsUsed` à 0 pour `--club=<id>` (ré-ouvre le pool de 10 crédits de sortie du plan Découverte — cas particuliers) — action support, aussi déclenchable depuis la console admin |
| `app:clubs:reset-quota` | SA4 : remet `generationCountSeason` à 0 pour `--club=<id>` (déblocage quota Découverte) — action support, aussi déclenchable depuis la console admin |
| `app:clubs:reset-season` | SA4 : vide la SAISON COURANTE de `--club=<id>` (ligne Season et club gardés — retour au wizard) ; `--dry-run` annonce la saison résolue — miroir CLI de `ResetSeasonController` |
| `app:health:alert` | Sondes santé + fraîcheur des référentiels → email aux superadmins actifs sur transition rouge/verte (anti-spam `admin_alert_state`) — **auto, toutes les 10 min** |
| `app:db:backup` | `pg_dump -Fc` PILOTÉ PAR L'ACTIVITÉ (skip si rien n'a bougé), rétention 14, hook off-site `BACKUP_SYNC_COMMAND` ; `--force` avant toute migration risquée — **auto, quotidien à 01:00** (runbook `docs/ops/backup-restore.md`) |
| `app:db:restore-check` | Restaure le dernier dump dans une base JETABLE et la vérifie (≥ 20 tables) — la preuve qu'un backup existe ; `--file` pour cibler |

## Commandes Doctrine utiles (rappels RLS)

| Commande | Piège |
|----------|-------|
| `dbal:run-sql "…"` | Connexion `default` = `app_user` **sous RLS sans GUC → 0 ligne sur les tables tenant**. Ops/debug : `--connection admin`. *(doctrine-bundle 3 a supprimé l'ancien alias `doctrine:query:sql`.)* |
| `doctrine:migrations:migrate` | Toujours via la connexion **admin** (les cibles make le font) |
| `doctrine:fixtures:load` | **Interdit à la main** — garde applicatif (BasketballInit) : passe par `make fixtures` |

## Scripts (`backend/scripts/`)

| Script | Effet |
|--------|-------|
| `smoke-solver.sh` | **Garde-fou solveur** : create → generate → poll, exige `COMPLETED`. Obligatoire quand engine/backend est touché (§7 CLAUDE.md) |
| `generate-schedule.sh` | Guide pratique : pilote une génération via l'API (debug du flux) |
| `generate-schedule-test.sh` | Auto-test de `generate-schedule.sh` (PASS/FAIL sur son propre comportement) |
| `onboarding-smoke.sh` | Flux club neuf : register → données minimales → generate → `COMPLETED` |
| `smoke-overlay.sh` | Smoke sémantique de l'overlay de période (ADR-0002) : fermeture → plan né de l'Adapter → version → build overlay (grille propre à la période, jamais l'union avec la saison) → `COMPLETED` |
| `smoke-place-matches.sh` | Smoke sémantique du solveur de placement matchs (P1-4 PR D, `POST /api/fixtures/place`) : un domicile dans sa fenêtre d'accès revient `PLACED` dans l'empreinte-temps, un domicile sans fenêtre revient `UNPLACED` avec la raison nommée `no_access_window` |
| `smoke-coach-wishes.sh` | Smoke sémantique du rail de sollicitation coach (#10) — le seul chemin `/api` non authentifié : campagne → token → page publique pré-remplie → `CoachWish` persisté |
