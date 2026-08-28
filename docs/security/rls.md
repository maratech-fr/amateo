# PostgreSQL RLS — architecture effective

> Status: **ACTIVE** since migration `Version20260703120000` (audit SEC-03, série sécurité PR-C).
> Design détaillé : `backend/docs/RLS.md` · couche applicative : `backend/docs/TENANT.md`.

## Ce qui tourne

- **Connexion runtime = `app_user`** (`DATABASE_URL`) : NOSUPERUSER, DML only. **Toute table portant une colonne `club_id`** porte `ENABLE` + `FORCE ROW LEVEL SECURITY` et une policy `tenant_isolation FOR ALL TO app_user` (pas de compte figé ici — chaque nouvelle table tenant hérite du motif via la migration ; un décompte périmerait) :
  `USING/WITH CHECK (club_id = NULLIF(current_setting('app.club_id', true), '')::uuid)` — GUC absent → **0 ligne, pas d'erreur** (fail-closed).
- **GUC `app.club_id`** posé par `App\Service\TenantConnectionContext` via `SELECT set_config('app.club_id', ?, false)` (session-scoped, paramétré). Le `SET LOCAL` historique hors transaction était un no-op — ne pas y revenir.
- **Qui pose le GUC** :
  | Contexte | Où |
  |---|---|
  | Requête HTTP | `TenantFilterListener` (clear en début de requête, set après résolution du club) |
  | Register (anonyme) | `AuthController` dans les closures `wrapInTransaction`, dès que le club est connu ; `clear()` en `finally` |
  | Worker messenger | `GenerateScheduleHandler` / `ExportPdfHandler` : `setClubId($message->getClubId())` en 1re instruction, `clear()` en `finally` (le message porte le `clubId`) |
  | Fixtures | `BasketballInit` (tournent en admin de toute façon) |
  | **Page publique doléances (anonyme, #10)** | `PublicCoachWishController` : `setClubId()` depuis le `club_id` porté par le `CoachWishToken`, dès qu'il est résolu ; `clear()` en `finally`. Route `PUBLIC_ACCESS` — **aucun JWT**, le token EST le porteur du tenant |

## Exceptions au modèle — les trois tables qui ne suivent pas le motif

Le motif normal est une policy unique `tenant_isolation FOR ALL`. Trois tables s'en écartent, **chacune pour une raison structurelle**. Toute nouvelle exception doit être justifiée ICI.

- **`club_user` — `SELECT` HYBRIDE** (SEC-12 soldé, `Version20260804120000`) : `club_user_read USING (NULLIF(current_setting('app.club_id', true), '') IS NULL OR club_id = NULLIF(...)::uuid)` — **ouvert seulement hors contexte tenant** (GUC absent ou vidé par `clear()`), scopé au canon dès qu'il est posé. Le tenant est **bootstrappé** depuis les memberships (listener, register, `/api/me`) avant qu'aucun club ne soit connu → la branche ouverte couvre ce moment-là, et rien d'autre. ⚠ Le prédicat passe par `NULLIF`, pas par un `IS NULL` nu : `TenantConnectionContext::clear()` pose la **chaîne vide**, jamais NULL — un `current_setting(...) IS NULL` serait faux après tout `clear()` et `''::uuid` planterait en 22P02. Écritures tenant-scopées. Les lectures cross-tenant **légitimes** faites GUC posé (liste des clubs d'un user multi-club `ClubStateProvider`, export RGPD, effacement de compte) passent par **`TenantConnectionContext::runWithoutTenant()`** — clear, requête, restauration en `finally` : l'ouverture est un geste explicite et greppable, plus un état permanent.
- **`coach_wish_token` — RLS HYBRIDE** (#10, `Version20260726100000` ; SELECT scopé par `Version20260804120000`, même prédicat hybride que `club_user`) : `SELECT` ouvert **hors contexte tenant seulement**, écritures tenant (`tenant_isolation_{insert,update,delete}`). Raison de la branche ouverte : la page publique `/api/coach-wishes/public/{token}` n'a **pas de JWT** — il faut lire le token pour découvrir le `club_id` qui posera le GUC ; à cet instant le GUC est vide (le listener a fait `clear()`), la branche ouverte s'applique, puis le contrôleur pose le GUC et la table redevient étanche pour le reste de la requête. Défenses complémentaires : la campagne qui expose les tokens est **management-only** (SEC-07) et sous RLS tenant complète ; le token est un secret de 32 octets, sans endpoint de listing, avec un **404 byte-identique** pour l'inconnu comme le malformé.
- **`audit_log` — policies scindées** : `SELECT` tenant, `INSERT WITH CHECK (club_id IS NULL OR <tenant>)` (les actions hors club sont journalisables), et **aucune policy `UPDATE` ni `DELETE`**. L'immuabilité du journal est tenue par la **base**, pas par le code. La purge à 12 mois passe par la connexion `admin`.

> **La PORTÉE des policies est gardée** (SEC-12, `RlsIsolationTest::testEveryPolicyOnClubIdTablesIsTenantScoped`, phase1). En plus de `rls_enabled`/`rls_forced`/`policies > 0`, chaque policy **permissive** des tables **portant une colonne `club_id`** est comparée par **égalité stricte** au prédicat canonique lu à l'exécution sur `team_tag.tenant_isolation` (pas de chaîne en dur — PostgreSQL reformate les prédicats). Un `USING (true)` posé par erreur (le chemin probable : copier-coller d'une vieille migration) **fait rougir le gate bloquant** en nommant `table.policy (cmd)`. La garde suit exactement l'énumération par colonne `club_id` : depuis **BCK-11 (2026-08-07) `team_tag_assignment` porte le sien** et entre donc dans son champ ; il ne reste que `constraint_conflict` (résiduel assumé, cf. §Caveats) hors de portée. Les seuls écarts tolérés sont **des dérogations de FORME composées du canon runtime**, indexées sur `table.policyname.cmd` (une policy au nom inattendu échoue même sur une paire connue) : le SELECT hybride de `club_user`/`coach_wish_token` (`(NULLIF(...) IS NULL) OR <canon>` — le sous-prédicat `NULLIF` est **extrait du canon**, pas réécrit) et l'INSERT d'`audit_log` (`club_id IS NULL OR <canon>`). La liste est **bidirectionnelle** : une dérogation devenue inutile fait elle aussi rougir (« périmée — retirer l'entrée »). Depuis P5-7, les policies destinées au rôle `{amateo_owner}` prennent une branche **par rôle**, avant le canon : elles doivent être exactement `admin_all` `ALL/true/true` permissive, et chaque table FORCE RLS doit en porter **exactement une** (présence imposée post-boucle — une migration future qui l'oublie rougit en nommant la table).
>
> **Depuis le 2026-08-04 (SEC-12 résiduel soldé), les deux couches tiennent partout.** GUC posé, `club_user` et `coach_wish_token` sont étanches au niveau base comme les autres : une requête native ou un `createQuery` filtre désactivé ne fuiterait plus que dans la fenêtre pré-GUC (où il n'y a par construction aucun tenant à protéger — et où le code filtre par `user_id`/token). Comportement gardé par `testClubUserSelectIsTenantScopedOnceGucIsSet`, `testRunWithoutTenantSeesAcrossClubsThenRestoresGuc`, `testFindActiveClubIdsStaysCrossClubWithGucSet` et `testClubUserRemainsReadableWithoutGuc` (le bootstrap pré-GUC, inchangé). Ce que le test de portée n'assure pas : la justesse *sémantique* du canon (portée par les tests comportementaux) ni un prédicat équivalent écrit autrement (échoue volontairement — fail-noisy).

## Porte superadmin (supervision développeur)

Depuis P5-7 (`Version20260813130000`), la porte admin est **portée par des policies, plus par le
statut superuser** : chaque table `FORCE ROW LEVEL SECURITY` porte une policy
`admin_all FOR ALL TO amateo_owner USING (true) WITH CHECK (true)`. En local `amateo_owner` est
superuser et bypasse de toute façon (les policies y sont inertes) ; sur un **Postgres managé** —
où aucun rôle n'a jamais `BYPASSRLS` — le même rôle, simple propriétaire non-superuser, traverse
par ces policies. Le mode de défaillance managé est vécu en local par le test
(`RlsIsolationTest::testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped` : rôle jetable
`NOSUPERUSER` membre de `amateo_owner` — voit et écrit cross-club pendant qu'`app_user` reste
scopé). `admin_all` n'élargit **pas** `app_user` : une policy permissive ne s'applique qu'aux
rôles listés et à leurs membres (`pg_has_role`). Conséquence assumée : le rôle admin a
UPDATE/DELETE sur `audit_log` (comportement identique à l'ancien bypass superuser — l'immuabilité
du journal reste tenue contre `app_user`). Supervision totale via
- `psql -U amateo_owner`,
- `php bin/console dbal:run-sql --connection admin "…"`,
- le futur dashboard super-admin (P2) devra utiliser cette connexion.

`DATABASE_ADMIN_URL` alimente la connexion Doctrine `admin` — utilisée par les **migrations** (`doctrine_migrations.connection: admin`, donc aussi `make migration-migrate` et `make bootstrap`), `db-init`/`db-init-test`/`db-reset*` et `make fixtures` (le purge DELETE serait silencieusement partiel sous RLS). **Ne jamais pointer `DATABASE_URL` runtime dessus** — `RlsIsolationTest::testConnectionUserIsNotSuperuser` le garde.

## Caveats

- **pgbouncer transaction-pooling incompatible** avec le GUC session-scoped (fuite cross-tenant). À reconcevoir avant d'introduire un pooler (GUC transactionnel + transaction par requête).
- `dbal:run-sql` sans `--connection admin` = app_user sans GUC → 0 ligne sur les tables tenant. C'est le comportement attendu, pas un bug.
- Tables **sans `club_id`** = hors RLS : `club`/`app_user` (protégés au niveau API, SEC-01/02) ; les **tables de référence GLOBALES** enrichies par l'usage, sans donnée club (`public_holiday`, `school_holiday_period`, `league_match_window`, `shared_competition_deadline` — le défaut communautaire d'échéance ligue/comité, keyé sur l'id FFBB de compétition : **aucune donnée club-identifiante**, gardé par `EntryDeadlineShareTest`) ; les **journaux d'idempotence** keyés sur un uuid globalement unique (`period_reminder_log`, `transition_reminder_log` — **SEC-09 : résiduel assumé**, aucune API de lecture, pas de `club_id`, écrits par le cron ; un `calendar_entry_id` non devinable ne fuit rien sans endpoint) ; le **catalogue de facturation** (`subscription_plan`, global) ; les tables **SA0/SA3** hors tenant (`super_admin`, `admin_audit_log`, `admin_job_run`, `admin_alert_state` — identité et exploitation globales, jamais rattachées à un club) ; `email_verification_token` (seul le sha256 du token est stocké, lié au `User`) ; `constraint_conflict` (porte un `schedule_id`, donc de la donnée tenant, **sans `club_id`** — DERNIER résiduel assumé depuis que `team_tag_assignment` a rejoint le régime tenant (BCK-11) : son parent `schedule` est sous RLS et il part par cascade, cf. `SeasonDataPurger`/`OverlayManager`) ; l'infra Doctrine/Symfony (`sport`, `priority_tier`, `reset_password_request`, `messenger_*`, `doctrine_migration_versions`). Règle : une table est hors RLS **ssi** elle ne porte pas de `club_id` — cf. `RlsIsolationTest` (énumération dynamique) et `TenantOwnedInterfaceCompletenessTest`.
- Prod : remplacer les mots de passe `app_user_password` / dev par des secrets réels (env), et rejouer la migration sur la base cible (idempotente côté rôle/grants).

## Tests de non-régression (phase1)

`tests/Security/RlsIsolationTest.php` — SQL brut sur la connexion runtime : isolation SELECT/UPDATE/DELETE, WITH CHECK rejette un `club_id` ≠ GUC, fail-closed sans GUC, bootstrap `club_user`, garde anti-superuser.
`tests/MessageHandler/ExportPdfHandlerRlsTest.php` — un handler worker pose son propre GUC (GenerateScheduleHandler : même pattern, couvert e2e par `smoke-solver.sh`).
Les suites Tenant* (HTTP, JWT réel) et `AuthFlowTest` (register) tournent intégralement sous RLS.
