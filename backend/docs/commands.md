# Commandes backend — référence complète

Last verified @ 2026-09-04 (P4-165 palier 1 — ajout de la cible `make behat`, retrait de
`scripts/smoke-solver.sh` (SUPPRIMÉ, migré en feature Behat)). Re-confronté au code :
`backend/Makefile` cible `behat` (garde sandbox, `restart messenger-worker`, `vendor/bin/behat
--format=pretty --no-interaction`, `APP_ENV=dev`) ✓ ; `backend/behat.dist.php` (suite `generation`,
`SeasonGenerationContext`) ✓ ; `backend/features/generation-du-planning-de-saison.feature` existe,
`backend/scripts/smoke-solver.sh` n'existe plus ✓. Non re-sondé cette passe : `backend/scripts/
smoke-place-matches.sh`, les commandes `BcclSeedCommand`/`DemoSeedCommand`,
`MutationTargetsAreGuardedTest`/`PlayTargetIsNonDestructiveTest`, horaires du catalogue de jobs,
pièges RLS Doctrine — un stamp REMPLACE, l'historique vit dans git.

> **Tout se lance dans le container** (`docker compose exec php-fpm …`) — les cibles `make`
> le font pour toi. PHPUnit exige `APP_ENV=test` (sinon `test.service_container` introuvable).
> La base : `make help` affiche cette liste côté Makefile.

## Une situation, une commande

| Je veux… | Je lance |
|---|---|
| Une base de jeu complète (BCCL réel + démo + vacances + catalogue ligue), sans rien détruire | `make play` (racine) |
| Repartir de zéro puis retrouver une base de jeu complète | `make reset` (racine) — composition littérale : `db-empty` + `play` |
| Juste vider la base ACTUELLEMENT visée (aucun seed) | `make db-empty` (racine) ou `make -C backend db-empty` |
| Remettre le club de démo à neuf (démonstration prospect) — purge + re-seed | `make -C backend seed-demo` |
| Poser le club de démo SANS toucher un workspace démo existant | `make -C backend IF_ABSENT=1 seed-demo` |
| Poser le club dev BCCL réel (no-op s'il existe déjà) | `make -C backend seed-bccl` |
| Bac à sable IA (`amateo_dev`), sans toucher la base de jeu du fondateur | `make sandbox` ; wrapper ponctuel : `backend/scripts/with-sandbox.sh <commande…>` |
| CI / Behat (`functional-tests`) / smokes restants | appellent `app:bccl:seed --no-interaction` directement (idempotent — voir §CI plus bas) |
| Base de test phpunit | `make -C backend db-init-test` (idempotent) / `make -C backend db-empty-test` (vide) |
| Rejouer seulement les référentiels vacances/fériés (globaux) | `make -C backend seed-holidays` |
| Rejouer seulement le catalogue des fenêtres de matchs de la ligue (global) | `make -C backend seed-league` |

## Les 3 bases locales — et les deux commandes qui basculent (P4-141, 2026-08-28)

Une stack pointe **une base à la fois**. Le défaut committé est le **bac à sable**, jamais la base de jeu.

| Base | Rôle | Qui l'écrit |
|---|---|---|
| `amateo_dev` | **bac à sable** — seed/purge à volonté | l'IA : smokes, e2e, démos, parcours navigateur (**défaut committé**) |
| `amateo_local` | **base de jeu du fondateur** | le fondateur seulement — mes scripts la REFUSENT |
| `amateo_test` | tests unitaires (DAMA, transactionnelle) | phpunit — **même en mode play** (`.env.test` garde la main dans l'ordre dotenv) |
| `amateo` | base de PROD | rien en local |

- **`make play`** — bascule sur la base de jeu : écrit `backend/.env.local` (gitignoré), crée `amateo_local` si absente, migre, **pose le club dev BCCL RÉEL (ARA0069036, avec ses plannings) via `seed-bccl` (create-only) ET le club de démo (ARA9999999) via `IF_ABSENT=1 seed-demo` (seed uniquement s'il est absent)**, rejoue les référentiels vacances scolaires/jours fériés (`seed-holidays`) **et le catalogue des fenêtres de matchs de la ligue** (`seed-league`, depuis 2026-09-03), et redémarre `messenger-worker`+`cron-runner` (ils tiennent la config en mémoire). 🔴 **NON DESTRUCTEUR — à relancer autant qu'on veut** : si un club existe, **aucune donnée n'est touchée** (le message le dit à l'écran). C'était un défaut du premier jet (avant P4-141) : `make play` appelait l'ancêtre « créer OU RESET » sans garde — relancer `play` effaçait le travail fait sur la démo.
  - Le club BCCL RÉEL naît via `make -C backend seed-bccl` (`app:bccl:seed`) — **CREATE-ONLY**, ne fait RIEN (SUCCESS) si le club existe déjà.
  - **Pour remettre le club de démo à neuf** (geste de démonstration prospect) : `make -C backend seed-demo` — sémantique « créer **ou RESET** » : purge le workspace (`ErasedClubPurger`, la fiche club survit) puis re-seed.
  - **Pour repartir de zéro** : `make reset` (racine) — vide la base actuellement visée (`db-empty`) puis relance `make play`.
- **`make sandbox`** — retour au bac à sable : supprime `backend/.env.local` + même redémarrage.
- La bascule vit **au niveau dotenv, jamais dans compose** : injecter `DATABASE_URL` par compose écraserait `.env.test` (env réel > dotenv) et enverrait phpunit sur la base de dev.

🔴 **Le garde-fou (`backend/scripts/lib/sandbox-guard.sh`)** est sourcé par **tous** les scripts mutateurs. Il résout la base RÉELLEMENT visée (`SELECT current_database()` via php-fpm, donc il respecte toute la précédence dotenv) et **meurt** (`exit 1`) sauf si la cible est `amateo_dev` ou `*_test` — il refuse `amateo_local`, `amateo`, un nom inconnu, **et une base non résolue** (fail-closed). Lancer un smoke en mode play échoue bruyamment **sans rien écrire**. ⚠ Limite assumée : `SANDBOX_GUARD_LOADED=1` court-circuite la vérification (variable interne, héritée parent→enfant par conception) — le garde protège des ACCIDENTS, pas d'un contournement délibéré.

🔴 **Les cibles Make destructrices sont GARDÉES aussi** (`backend/scripts/lib/mutation-confirm.sh`, sourcée par `db-empty`/`seed-demo`/`seed-bccl`) — le garde des scripts ne les couvrait pas, et un `seed-demo` nu en mode play aurait purgé la démo de la base de jeu. **Trois comportements**, pas un refus uniforme : bac à sable ou `*_test` → **passe en silence** · base de PROD → **refus sec** · `amateo_local` → **CONFIRMATION** nommant la base et ce qui va être détruit (`CONFIRM=yes` pour l'automatisation — c'est ce que `make play`/`make reset` injectent pour `seed-bccl`/`seed-demo`, puisque leurs chemins create-only/if-absent sont non destructeurs par construction ; sans terminal et sans cette variable → refus, rien touché). ⚑ `db-init`/`db-init-test`/`seed-holidays` ne sont **pas** gardés — non destructeurs (create-if-not-exists + migrate / référentiel global idempotent), rien à confirmer.

**Le wrapper `backend/scripts/with-sandbox.sh <commande…>`** (opt-in) : bascule en bac à sable, exécute, puis **RESTAURE le mode play à la sortie — succès, échec ou Ctrl-C** (`trap EXIT INT TERM`), en remettant le `.env.local` **byte-identique** (sauvegardé, jamais régénéré depuis le template). C'est ce que l'IA utilise pour ne jamais laisser le fondateur en bac à sable. ⚠ L'opt-in est le fait d'invoquer le wrapper : un script mutateur lancé SANS lui continue de **mourir** (le fail-closed du garde reste la règle — on ne bascule jamais la base de quelqu'un dans son dos).

`make -C backend db-drop-legacy` (optionnelle) supprime les anciennes `clubscheduler`/`clubscheduler_test` restées inertes dans le volume.

## Cibles Make (`backend/Makefile`)

| Cible | Effet |
|-------|-------|
| `make install` | `composer install` dans le container |
| `make test` | PHPStan + CS-Fixer + PHPUnit **`--testsuite Unit`** (⚠️ PAS le gate bloquant : ni `--group phase1`, ni `tests/` entier) |
| `make tests-complete` | PHPStan + CS-Fixer + **`phpunit tests/`** (le DOSSIER entier — miroir EXACT du job CI `Unit Tests` ; seule cible qui joue aussi les testsuites `Integration` et `Contract`, cf. `phpunit.xml.dist`) |
| `make phpunit` | PHPUnit **`--group phase1`** seul (`APP_ENV=test` injecté) — ⚠ **ce n'est pas « le gate »** : le groupe compte plusieurs fois plus de fichiers que le job CI `blocking-tests` n'a de steps nommés (les décomptes exacts pourrissent en jours — `ci.yml` fait foi). La cible **couvre** le gate mais ne s'y réduit pas (liste : `docs/testing/blocking-tests.md`) |
| `make tests-engine-semantics` | PHPUnit **`--group contract`** — les tests qui interrogent le **VRAI moteur** (job CI dédié et bloquant « Engine semantics ») : chaque clé de la liste blanche `config` doit **CHANGER** le résultat du solveur, le miroir de capacité doit rendre le même verdict que lui, le payload doit rester recevable. ⚠ `tests-complete` les **exclut** (`--exclude-group contract`), exactement comme `unit-tests` en CI : sans cette cible, ils ne tournent jamais en local |
| `make behat` | Tests fonctionnels **Behat** (Gherkin français, `APP_ENV=dev`, `features/`) — scénarios métier joués contre l'API réelle (aucun navigateur, aucun noyau in-process), redémarre `messenger-worker` avant, gardé par la sandbox (`scripts/lib/sandbox-guard.sh`). En mode play : `backend/scripts/with-sandbox.sh make -C backend behat`. Miroir du job CI `functional-tests` |
| `make coverage` | Couverture backend (`phpunit tests/ --exclude-group contract`, driver `pcov`, `-d pcov.enabled=1`) + cliquet `scripts/coverage-gate.php` (plancher `backend` de `coverage-floor.json` racine — PHPUnit 11 n'a pas de seuil natif). Séparée de `tests-complete` (pcov ralentit) ; miroir du job CI `backend-coverage` (`needs: blocking-tests`, hors des `needs` de `build-docker`) |
| `make db-init` | Crée + migre la base de **dev** — idempotent, ne détruit rien |
| `make db-init-test` | Crée + migre la base de test (**pré-requis de toute suite**), pose `idle_in_transaction_session_timeout = 60s` sur `amateo_test` (purge les transactions DAMA zombies d'un phpunit tué) |
| `make jwt-keys` | Génère le keypair JWT s'il est absent (`config/jwt/*.pem`, gitignoré) — idempotent |
| `make db-empty` | Drop + recreate + migrate la base de **dev ACTUELLEMENT VISÉE** (aucun seed) — gardé par `mutation-confirm.sh` |
| `make db-empty-test` | Drop + recreate + migrate la base de **test** (aucun seed) — non gardé (base de test, jamais la base de jeu) |
| `make seed-bccl` | Seed le club dev BCCL RÉEL (ARA0069036, `app:bccl:seed`) — **CREATE-ONLY**, ne fait RIEN si le club existe déjà — connexion admin, gardé par `mutation-confirm.sh` |
| `make seed-demo` | Seed/reset le club de DÉMONSTRATION permanent (ARA9999999, `app:demo:seed`) — **créer OU RESET** par défaut (purge le workspace puis re-seed) ; `IF_ABSENT=1 make seed-demo` ajoute `--if-absent` (no-op si présent) — connexion admin, gardé par `mutation-confirm.sh`. `DEMO_BCCL_PASSWORD` (défaut `DemoBccl!2026`, non secret) est passé en `--password` |
| `make seed-holidays` | Rejoue les référentiels vacances scolaires + jours fériés (globaux, non-tenant, idempotents) — pas de connexion admin, non gardé |
| `make seed-league` | Rejoue le catalogue des fenêtres de matchs de la ligue (global, non-tenant, `app:league-windows:seed`, idempotent — upsert par clé naturelle) — pas de connexion admin, non gardé |
| `make phpstan` / `make cs` / `make cs-fix` / `make rector` | Analyses (cs/rector en dry-run, `cs-fix` applique). ⚠ PHPStan (`phpstan.neon`) a `paths: [src]` **seul** — `scripts/` (dont `coverage-gate.php`) n'est PAS analysé |
| `make lint` | PHPStan + CS + Rector (tout en dry-run) |
| `make migration-diff` / `make migration-migrate` | Diff / applique les migrations (connexion **admin**) |
| `make fix-perms` | Répare les droits de `var/generate` (rapports lisibles côté host) |
| `make exec` | Shell dans le container php-fpm |

## Cibles Make (racine, `Makefile`)

| Cible | Effet |
|-------|-------|
| `make play` | Bascule vers `amateo_local` (base de jeu du fondateur) : `play-env` → `db-init` → `seed-bccl` → `IF_ABSENT=1 seed-demo` → `seed-holidays` → `seed-league` → redémarre `messenger-worker`/`cron-runner`. **Non destructeur**, rejouable à volonté |
| `make sandbox` | Retire `backend/.env.local` → retour à `amateo_dev` (bac à sable) + même redémarrage |
| `make db-empty` | Vide (drop+create+migrate) la base **actuellement visée** — `amateo_local` en mode play, `amateo_dev` en bac à sable — aucun seed |
| `make reset` | Composition littérale : `db-empty` puis `play` — repart de zéro et retrouve une base de jeu complète |

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
| `app:league-windows:seed` | Catalogue des fenêtres de matchs par ligue (JSON AURA) — idempotent. Appelée par `make -C backend seed-league`, rejouée par `make play`/`make reset` (racine) depuis 2026-09-03 |
| `app:clubs:backfill-school-zone` | Déduit `Club.schoolZone` du code FFBB (dry-run sans `--apply`) |
| `app:club-approvals:digest` | P3-4 PR B : relance les demandes de création de club (3 j restants + jour J) et expire les échues (la console superadmin garde la main) ; `--dry-run`, `--date` — **auto, quotidien à 08:30** |
| `app:clubs:ffbb-resync` | SA4/P2-18 : ré-importe l'identité FFBB de `--club=<id>` (FfbbClubPopulator refresh — nom, coordonnées, logo, comité/ligue) ; échec franc si organisme introuvable — action support, aussi déclenchable depuis la console admin |
| `app:demo:seed` | P2-4, renommée le 2026-09-03 (ex `app:demo:seed-bccl`) : (re)crée le club de DÉMONSTRATION permanent « Démo Basket Club » — la structure terrain du BCCL sous identités FICTIVES (club, gestionnaire `--email` défaut demo-bccl@amateo.fr, autant d'identités fictives que de coachs du seed dev — anonymisation STRICTE, liste courte = refus). **Créer OU RESET par défaut** : purge du workspace (`ErasedClubPurger`) + re-seed, retour exact à l'état de base. **`--if-absent`** neutralise le reset : club déjà présent → SUCCESS « The demo club is already present — nothing touched (--if-absent). », zéro écriture — c'est le chemin qu'emprunte `make play`. `--password` (min 12) requis à la PREMIÈRE création seulement. ⚠ Connexion ADMIN requise (`DATABASE_URL=$DATABASE_ADMIN_URL`) — le garde superuser du seeder refuse sinon. Aucune restriction d'environnement (disponible en prod, comme son ancêtre) |
| `app:bccl:seed` | Renommée le 2026-09-03 (ex `app:seed:bccl-dev`) : seed le club **dev BCCL RÉEL** (identités réelles, `mara.mb@bccl.fr`, ARA0069036) — **CREATE-ONLY** : club déjà présent → SUCCESS « The BCCL dev club is already present — nothing touched (create-only, never resets). », zéro écriture — à l'inverse d'`app:demo:seed` (créer OU RESET). Appelée par `make play`/`make -C backend seed-bccl`. **DEV/TEST-ONLY** : exclue de `services.yaml`, déclarée dans `services_dev.yaml`/`services_test.yaml` seulement + garde runtime (refuse hors `dev`/`test`). ⚠ Connexion ADMIN requise. Détail : [`backend-inventory.md`](backend-inventory.md) §Module démo |
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
| `dbal:run-sql "…"` | Connexion `default` = `amateo_app` **sous RLS sans GUC → 0 ligne sur les tables tenant**. Ops/debug : `--connection admin`. *(doctrine-bundle 3 a supprimé l'ancien alias `doctrine:query:sql`.)* |
| `doctrine:migrations:migrate` | Toujours via la connexion **admin** (les cibles make le font) |
| `doctrine:fixtures:load` | **Plus aucun appelant depuis le 2026-09-03** — les fixtures Doctrine (`BasketballInit`, `HolidayReferenceFixtures`) sont supprimées, `make fixtures` avec elles ; un seul chemin de remplissage reste : `app:bccl:seed`/`app:demo:seed`/`app:*-holidays:seed` ci-dessus. Le bundle `doctrine/doctrine-fixtures-bundle` reste installé (`composer.json`) mais n'est plus câblé à rien — retrait tracé roadmap P4-163 |

## Scripts (`backend/scripts/`)

> ⚠ `smoke-solver.sh` (garde-fou solveur : create → generate → poll → `COMPLETED`) a **migré** en
> feature Behat, `features/generation-du-planning-de-saison.feature` (`make behat` ci-dessus) —
> P4-165 palier 1, 2026-09-04. Le `.sh` est supprimé.

| Script | Effet |
|--------|-------|
| `generate-schedule.sh` | Guide pratique : pilote une génération via l'API (debug du flux) |
| `generate-schedule-test.sh` | Auto-test de `generate-schedule.sh` (PASS/FAIL sur son propre comportement) |
| `onboarding-smoke.sh` | Flux club neuf : register → données minimales → generate → `COMPLETED` |
| `smoke-overlay.sh` | Smoke sémantique de l'overlay de période (ADR-0002) : fermeture → plan né de l'Adapter → version → build overlay (grille propre à la période, jamais l'union avec la saison) → `COMPLETED` |
| `smoke-place-matches.sh` | Smoke sémantique du solveur de placement matchs (P1-4 PR D, `POST /api/fixtures/place`) : un domicile dans sa fenêtre d'accès revient `PLACED` dans l'empreinte-temps, un domicile sans fenêtre revient `UNPLACED` avec la raison nommée `no_access_window`. **Auto-suffisant** (2026-09-03) : crée ses propres équipes + gymnase jetables (le club dev porte la répartition WE réelle depuis le seed, § `module-matchs.md` § « Seed BCCL dev »), neutralise puis restaure les fenêtres dominicales du club le temps du placement (`no_access_window` est club-wide) |
| `smoke-coach-wishes.sh` | Smoke sémantique du rail de sollicitation coach (#10) — le seul chemin `/api` non authentifié : campagne → token → page publique pré-remplie → `CoachWish` persisté |
