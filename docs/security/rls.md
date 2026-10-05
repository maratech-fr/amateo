# PostgreSQL RLS — architecture effective

> Status: **ACTIVE** since migration `Version20260703120000` (audit SEC-03, série sécurité PR-C).
> Design détaillé : `backend/docs/RLS.md` · couche applicative : `backend/docs/TENANT.md`.

## Ce qui tourne

- **Connexion runtime = `amateo_app`** (`DATABASE_URL`) : NOSUPERUSER, DML only. **Toute table portant une colonne `club_id`** porte `ENABLE` + `FORCE ROW LEVEL SECURITY` et une policy `tenant_isolation FOR ALL TO amateo_app` (pas de compte figé ici — chaque nouvelle table tenant hérite du motif via la migration ; un décompte périmerait) :
  `USING/WITH CHECK (club_id = NULLIF(current_setting('app.club_id', true), '')::uuid)` — GUC absent → **0 ligne, pas d'erreur** (fail-closed).
- **GUC `app.club_id`** posé par `App\Service\TenantConnectionContext` via `SELECT set_config('app.club_id', ?, false)` (session-scoped, paramétré). Le `SET LOCAL` historique hors transaction était un no-op — ne pas y revenir.
- **Qui pose le GUC** :
  | Contexte | Où |
  |---|---|
  | Requête HTTP | `TenantFilterListener` (clear en début de requête, set après résolution du club) |
  | Register (anonyme) | `AuthController` dans les closures `wrapInTransaction`, dès que le club est connu ; `clear()` en `finally` |
  | Worker messenger | `GenerateScheduleHandler` / `ExportPdfHandler` : `setClubId($message->getClubId())` en 1re instruction, `clear()` en `finally` (le message porte le `clubId`) |
  | Seed dev/démo | `BcclSeeder` (`app:bccl:seed`/`app:demo:seed`, garde superuser explicite — tournent en admin de toute façon, pas de fixtures Doctrine) |
  | **Page publique doléances (anonyme, #10)** | `PublicCoachWishController` : `setClubId()` depuis le `club_id` porté par le `CoachWishToken`, dès qu'il est résolu ; `clear()` en `finally`. Route `PUBLIC_ACCESS` — **aucun JWT**, le token EST le porteur du tenant |
  | **Page publique invitation (anonyme, P4-299)** | `PublicInvitationController` : `setClubId()` depuis le `club_id` porté par la `ClubInvitation` résolue par jeton (hashé), relâché en `finally`. Route `PUBLIC_ACCESS` — **aucun JWT**, le jeton EST le porteur du tenant ; l'acceptation CONNECTÉE (`/api/invitations/{token}/accept`) résout le jeton via `runWithoutTenant` même sous le GUC d'un autre club |

## Exceptions au modèle — les quatre tables qui ne suivent pas le motif

Le motif normal est une policy unique `tenant_isolation FOR ALL`. Quatre tables s'en écartent, **chacune pour une raison structurelle**. Toute nouvelle exception doit être justifiée ICI.

- **`club_user` — `SELECT` HYBRIDE** (SEC-12 soldé, `Version20260804120000`) : `club_user_read USING (NULLIF(current_setting('app.club_id', true), '') IS NULL OR club_id = NULLIF(...)::uuid)` — **ouvert seulement hors contexte tenant** (GUC absent ou vidé par `clear()`), scopé au canon dès qu'il est posé. Le tenant est **bootstrappé** depuis les memberships (listener, register, `/api/me`) avant qu'aucun club ne soit connu → la branche ouverte couvre ce moment-là, et rien d'autre. ⚠ Le prédicat passe par `NULLIF`, pas par un `IS NULL` nu : `TenantConnectionContext::clear()` pose la **chaîne vide**, jamais NULL — un `current_setting(...) IS NULL` serait faux après tout `clear()` et `''::uuid` planterait en 22P02. Écritures tenant-scopées. Les lectures cross-tenant **légitimes** faites GUC posé (liste des clubs d'un user multi-club `ClubStateProvider`, export RGPD, effacement de compte) passent par **`TenantConnectionContext::runWithoutTenant()`** — clear, requête, restauration en `finally` : l'ouverture est un geste explicite et greppable, plus un état permanent.
- **`coach_wish_token` — RLS HYBRIDE** (#10, `Version20260726100000` ; SELECT scopé par `Version20260804120000`, même prédicat hybride que `club_user`) : `SELECT` ouvert **hors contexte tenant seulement**, écritures tenant (`tenant_isolation_{insert,update,delete}`). Raison de la branche ouverte : la page publique `/api/coach-wishes/public/{token}` n'a **pas de JWT** — il faut lire le token pour découvrir le `club_id` qui posera le GUC ; à cet instant le GUC est vide (le listener a fait `clear()`), la branche ouverte s'applique, puis le contrôleur pose le GUC et la table redevient étanche pour le reste de la requête. Défenses complémentaires : la campagne qui expose les tokens est **management-only** (SEC-07) et sous RLS tenant complète ; le token est un secret de 32 octets, sans endpoint de listing, avec un **404 byte-identique** pour l'inconnu comme le malformé.
- **`club_invitation` — RLS HYBRIDE** (P4-299, `Version20261006120000` ; même prédicat hybride que `club_user`/`coach_wish_token`) : `SELECT` ouvert **hors contexte tenant seulement** (`club_invitation_read`), écritures tenant (`tenant_isolation_{insert,update,delete}`). Raison de la branche ouverte : la page publique `/api/invitations/public/{token}` n'a **pas de JWT** — il faut lire le jeton pour découvrir le `club_id` qui posera le GUC ; l'acceptation CONNECTÉE lit aussi le jeton **sous le GUC d'un AUTRE club** (le compte invité peut être membre ailleurs) via `runWithoutTenant`. Seul le **sha256** du jeton vit en base (patron `EmailVerifier`), secret de 32 octets sans endpoint de listing, **404 byte-identique** pour l'inconnu / malformé / expiré / révoqué. Le listing management (`GET /api/invitations`) est sous RLS tenant complète (GUC posé). Rôle `amateo_read` : SELECT colonne par colonne **sauf `token_hash`** (patron `coach_wish_token.token`).
- **`audit_log` — policies scindées** : `SELECT` tenant, `INSERT WITH CHECK (club_id IS NULL OR <tenant>)` (les actions hors club sont journalisables), et **aucune policy `UPDATE` ni `DELETE`**. L'immuabilité du journal est tenue par la **base**, pas par le code. La purge à 12 mois passe par la connexion `admin`.

> **La PORTÉE des policies est gardée** (SEC-12, `RlsIsolationTest::testEveryPolicyOnClubIdTablesIsTenantScoped`, phase1). En plus de `rls_enabled`/`rls_forced`/`policies > 0`, chaque policy **permissive** des tables **portant une colonne `club_id`** est comparée par **égalité stricte** au prédicat canonique lu à l'exécution sur `team_tag.tenant_isolation` (pas de chaîne en dur — PostgreSQL reformate les prédicats). Un `USING (true)` posé par erreur (le chemin probable : copier-coller d'une vieille migration) **fait rougir le gate bloquant** en nommant `table.policy (cmd)`. La garde suit exactement l'énumération par colonne `club_id` : `team_tag_assignment` porte le sien (BCK-11) et entre donc dans son champ ; il ne reste que `constraint_conflict` (résiduel assumé, cf. §Caveats) hors de portée. Les seuls écarts tolérés sont **des dérogations de FORME composées du canon runtime**, indexées sur `table.policyname.cmd` (une policy au nom inattendu échoue même sur une paire connue) : le SELECT hybride de `club_user`/`coach_wish_token`/`club_invitation` (`(NULLIF(...) IS NULL) OR <canon>` — le sous-prédicat `NULLIF` est **extrait du canon**, pas réécrit) et l'INSERT d'`audit_log` (`club_id IS NULL OR <canon>`). La liste est **bidirectionnelle** : une dérogation devenue inutile fait elle aussi rougir (« périmée — retirer l'entrée »). Les policies destinées au rôle `{amateo_owner}` prennent une branche **par rôle** (P5-7), avant le canon : elles doivent être exactement `admin_all` `ALL/true/true` permissive, et chaque table FORCE RLS doit en porter **exactement une** (présence imposée post-boucle — une migration future qui l'oublie rougit en nommant la table).
>
> **Les deux couches tiennent partout (SEC-12).** GUC posé, `club_user` et `coach_wish_token` sont étanches au niveau base comme les autres : une requête native ou un `createQuery` filtre désactivé ne fuiterait plus que dans la fenêtre pré-GUC (où il n'y a par construction aucun tenant à protéger — et où le code filtre par `user_id`/token). Comportement gardé par `testClubUserSelectIsTenantScopedOnceGucIsSet`, `testRunWithoutTenantSeesAcrossClubsThenRestoresGuc`, `testFindActiveClubIdsStaysCrossClubWithGucSet` et `testClubUserRemainsReadableWithoutGuc` (le bootstrap pré-GUC, inchangé). Ce que le test de portée n'assure pas : la justesse *sémantique* du canon (portée par les tests comportementaux) ni un prédicat équivalent écrit autrement (échoue volontairement — fail-noisy).

## Porte superadmin (supervision développeur)

La porte admin (P5-7, `Version20260813130000`) est **portée par des policies, plus par le
statut superuser** : chaque table `FORCE ROW LEVEL SECURITY` porte une policy
`admin_all FOR ALL TO amateo_owner USING (true) WITH CHECK (true)`. En local `amateo_owner` est
superuser et bypasse de toute façon (les policies y sont inertes) ; sur un **Postgres managé** —
où aucun rôle n'a jamais `BYPASSRLS` — le même rôle, simple propriétaire non-superuser, traverse
par ces policies. Le mode de défaillance managé est vécu en local par le test
(`RlsIsolationTest::testAdminDoorLetsOwnerCrossClubsWhileAppUserStaysScoped` : rôle jetable
`NOSUPERUSER` membre de `amateo_owner` — voit et écrit cross-club pendant qu'`amateo_app` reste
scopé). `admin_all` n'élargit **pas** `amateo_app` : une policy permissive ne s'applique qu'aux
rôles listés et à leurs membres (`pg_has_role`). Conséquence assumée : le rôle admin a
UPDATE/DELETE sur `audit_log` (comportement identique à l'ancien bypass superuser — l'immuabilité
du journal reste tenue contre `amateo_app`). Supervision totale via
- `psql -U amateo_owner`,
- `php bin/console dbal:run-sql --connection admin "…"`,
- le futur dashboard super-admin (P2) devra utiliser cette connexion.

`DATABASE_ADMIN_URL` alimente la connexion Doctrine `admin` — utilisée par les **migrations** (`doctrine_migrations.connection: admin`, donc aussi `make migration-migrate` et `make bootstrap`), `db-init`/`db-init-test`/`db-empty*` et les commandes de seed `app:bccl:seed`/`app:demo:seed` (le purge DELETE d'`app:demo:seed` serait silencieusement partiel sous RLS sans elle). **Ne jamais pointer `DATABASE_URL` runtime dessus** — `RlsIsolationTest::testConnectionUserIsNotSuperuser` le garde.

## Rôle de lecture seule pour l'exploration opérateur (`amateo_read`, P5-20)

Un **troisième** rôle, créé par la migration `Version20260930090000` (idempotente) : `SELECT`
seulement, **jamais** de porte `admin_all` — il reste scopé RLS comme `amateo_app`, ce n'est **pas**
un bypass. Destiné à l'exploration courante d'un poste (au lieu d'ouvrir un client graphique en
`amateo_owner`, qui rapatrie les données personnelles de **tous** les clubs). Naît `LOGIN` mais
**sans mot de passe** (aucun secret en git) — inutilisable tant que l'opérateur n'en pose pas un le
jour J (`docs/ops/deploy.md` §1.8).

- **LISTE BLANCHE stricte, jamais `GRANT SELECT ON ALL TABLES`** : un tel grant exposerait
  `super_admin` (mot de passe + secret TOTP), les quatre tables de tokens
  (`club_creation_request`, `reset_password_request`, `email_change_token`,
  `email_verification_token`), `app_user.password_hash`/`pending_email`, le journal admin. La
  migration énumère donc explicitement : **toutes les tables `club_id`** (patron catalogue,
  robuste aux tables futures) — table entière, sauf `coach_wish_token` dont la colonne `token`
  (secret de la page publique) est exclue par un `GRANT` colonne par colonne — plus une **liste
  blanche globale explicite** de référentiels non sensibles + `club`, et `app_user` en colonnes
  sans `password_hash` ni `pending_email`. **Toute table hors de ces deux listes est en LISTE
  NOIRE implicite** — non accordée, pas d'exception silencieuse.
- **Aucun `ALTER DEFAULT PRIVILEGES`** : une table **future** n'est PAS lisible tant qu'une
  migration ne l'a pas classée — défaut fermé, décision délibérée (pas un oubli).
- **`ALTER ROLE` étroit** re-posé explicitement après le `CREATE ROLE` idempotent
  (`NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION`) — un rôle `amateo_read`
  préexistant aux droits plus larges (créé à la main, ou hérité) serait sinon conservé tel quel.
- **Policy `readonly_tenant FOR SELECT TO amateo_read`** sur **chaque** table `club_id`, portant le
  **même prédicat canonique** que `tenant_isolation` (comparé par égalité stricte à l'exécution,
  cf. ci-dessus) — sans elle, `FORCE ROW LEVEL SECURITY` rendrait 0 ligne à l'opérateur (pas
  d'erreur, juste une table qui semble vide). **Aucune** policy `admin_all` pour `amateo_read` : il
  ne traverse jamais la frontière tenant.
- **Écriture refusée au niveau PRIVILÈGE** : aucun `GRANT` DML (`INSERT`/`UPDATE`/`DELETE`) —
  toute tentative échoue avant même d'atteindre une policy.
- ⚠ **Le scoping par club (`SET app.club_id`) est une AIDE, pas une frontière** — contrairement à
  `TenantFilterListener` côté application, rien ne borne l'opérateur à un club particulier : c'est
  lui qui pose le GUC, et il peut le reposer sur un autre club à volonté. La vraie garantie tient à
  ce que le rôle **ne voie aucun secret** et **ne puisse rien écrire** ; `amateo_read` est un rôle
  **de confiance** (l'opérateur qui détient son mot de passe), pas un rôle contraint comme
  `amateo_app`.
- **Garde du futur, NR bloquant `Security/ReadOnlyRoleTest`** (`docs/testing/blocking-tests.md`) :
  classification EXHAUSTIVE — chaque table du schéma `public` doit être soit lisible (table
  `club_id` ∪ liste blanche globale), soit dans une **liste noire explicite**
  (`super_admin`, `admin_audit_log`, `admin_alert_state`, `admin_job_run`, les quatre tables de
  tokens, `period_reminder_log`, `transition_reminder_log`, `doctrine_migration_versions`,
  `constraint_conflict`) — une table non classée fait rougir le test, jamais un `GRANT ON ALL`
  silencieux. Le test porte aussi un **filet regex** sur les noms de colonnes qui sentent le secret
  (`token|secret|hash|password|passwd|otp|totp|salt|api_?key|private`) sur TOUTE colonne lisible
  par `amateo_read`, exceptions nommées une par une (`schedule.snapshot_hash` = empreinte de
  contenu, pas un secret d'authentification). **Toute nouvelle table doit être classée — lisible ou
  interdite — dans ce test au moment où elle naît**, pas après coup.
- Écrire (corriger une donnée en prod) reste un geste `amateo_owner`, en SSH direct sur la VM,
  jamais depuis un poste avec `amateo_read` : procédure `docs/ops/deploy.md` §1.9.

## Fonction `SECURITY DEFINER` — l'exception au modèle RLS

**Une seule fonction du dépôt tourne en `SECURITY DEFINER`** : `league_window_suggestions(uuid)`
(P4-272 ②, `Version20260929120000`, durcie par `Version20261005110000` — BCK-36, lot robustesse
2026-10-03) — la tendance dominante des plages de match saisies par les AUTRES clubs de l'instance
fédérale (comité/ligue/fédération) du demandeur. C'est le SEUL endroit du produit qui **lit à
travers la frontière tenant** : `amateo_app` (RLS le borne à son club) ne peut pas agréger
`club_league_window` de tous les clubs pairs, il fallait un contexte qui voie tout.

- **BCK-36 — deux durcissements** (`Version20261005110000`) : (1) les clubs de DÉMONSTRATION
  n'entrent jamais dans les pairs agrégés (`AND NOT c.is_demo` dans la CTE `peers` — sans lui, un
  club vendeur comme ARA9999999 et ses clones comptaient dans la « tendance » servie à de vrais
  clubs, du bruit et une fuite de l'existence des démos) ; (2) `p_requesting_club` est LIÉ au
  tenant courant — la CTE `me` exige `id = NULLIF(current_setting('app.club_id', true), '')::uuid`,
  **exactement** le prédicat des policies RLS du dépôt (fail-closed) : un appel avec un club ≠ GUC
  (ou sans GUC posé) rend `me` vide → zéro ligne, la fonction `SECURITY DEFINER` ne peut plus
  agréger l'instance d'un club arbitraire, seulement celle du club du GUC posé par le listener
  tenant de la requête HTTP courante.

- **Exécutée comme son PROPRIÉTAIRE** (`amateo_owner`, qui porte la policy `admin_all` — bypasse
  la RLS, cf. « Porte superadmin » ci-dessus), pas comme l'appelant — c'est la définition même de
  `SECURITY DEFINER`.
- **Contrat de sortie strict : agrégats seulement, jamais une identité.** La fonction ne rend que
  `(category, level, gender, day_of_week, windows, club_count)` — un ENSEMBLE de plages et un
  COMPTE de clubs, jamais LESQUELS ; le SQL dérive lui-même `ligue`/`comité` depuis le
  `ffbb_club_code` du demandeur, **aucun paramètre de ligue fourni par l'appelant**.
  `LeagueWindowSuggestionService` (le seul appelant, `backend/src/Service/
  LeagueWindowSuggestionService.php`) ne sert au front que cette liste blanche.
- **Surface d'appel close** : `SET search_path = pg_catalog, public, pg_temp` figé — `pg_temp` en
  DERNIER (recommandation PostgreSQL pour un `SECURITY DEFINER` : sinon une table temporaire de
  session pourrait ombrer `club`/`season`/`club_league_window`), tables qualifiées `public.…` dans
  le corps de la requête, `REVOKE ALL … FROM PUBLIC` puis `GRANT EXECUTE` au seul rôle applicatif
  (`Version20260929120000.php:113-120`).
- **`STABLE`, en `LANGUAGE sql`** — lecture pure, aucune écriture possible depuis la fonction
  elle-même.
- **Gardée par un NR bloquant dédié** : `Security/LeagueWindowSuggestionShareTest`
  (`docs/testing/blocking-tests.md`) — falsifie, entre autres, `search_path` figé, `EXECUTE` limité
  au rôle applicatif, le seuil (≥3 ET majorité), le groupement par instance, le demandeur exclu, et
  l'absence de toute donnée club-identifiante dans la réponse.

Toute nouvelle fonction `SECURITY DEFINER` doit justifier ICI pourquoi une lecture cross-tenant
est nécessaire, et respecter le même contrat (agrégat seul, `search_path` figé, `EXECUTE` restreint
au rôle applicatif, NR de partage dédié).

## Caveats

- **pgbouncer transaction-pooling incompatible** avec le GUC session-scoped (fuite cross-tenant). À reconcevoir avant d'introduire un pooler (GUC transactionnel + transaction par requête).
- `dbal:run-sql` sans `--connection admin` = app_user sans GUC → 0 ligne sur les tables tenant. C'est le comportement attendu, pas un bug.
- Tables **sans `club_id`** = hors RLS : `club`/`app_user` (protégés au niveau API, SEC-01/02) ; les **tables de référence GLOBALES** enrichies par l'usage, sans donnée club (`public_holiday`, `school_holiday_period`, `league_match_window`, `shared_competition_deadline` — le défaut communautaire d'échéance ligue/comité, keyé sur l'id FFBB de compétition : **aucune donnée club-identifiante**, gardé par `EntryDeadlineShareTest` ; `opponent_directory` — l'annuaire fédéral d'un adversaire
  (P2-54 RMM-9, où il joue), keyé sur le code organisme fédéral public (unique), **aucune colonne
  club-identifiante** ; son champ `name` ne retombe **jamais** sur une donnée locale — le libellé
  de l'adversaire tel qu'UN club l'a orthographié dans son propre fichier FBI serait alors vu par
  TOUS les autres — mais sur le **CODE FÉDÉRAL** quand le hit fédéral ne porte pas de `nom` (BCK-26,
  `OpponentLocationResolver::locateCity`, précédent maison `FfbbClubPopulator`), gardé par
  `OpponentDirectoryShareTest` + `OpponentLocationResolverTest` ; `opponent_venue_suggestion` — le catalogue fédéral des gymnases connus d'un club adverse (P2-54 : chaque club y accumule ses choix sans en dupliquer le contenu), keyé sur le code organisme fédéral public, portant un COMPTE de choix mais **jamais lesquels** : « un compte, jamais un qui », **et des données FÉDÉRALES SEULES** — un choix `MANUAL` est RE-RÉSOLU côté serveur contre l'index FFBB (`Service/Basketball/FfbbSalleResolver`, `_geoRadius` + égalité stricte du `numero`), le libellé/ville/CP/coordonnées écrits viennent du hit fédéral, **jamais du corps client** ; une réf non résolue ou FFBB muet laisse le choix tenant seul, rien n'est écrit au partagé ; **comptabilité IDEMPOTENTE et SYMÉTRIQUE par `(club, code organisme, ref)`** (deux failles Medium corrigées : un incrément non idempotent gonflait le compteur au fil de plusieurs libellés du même club vers le même gymnase, un décrément non symétrique pouvait décrémenter le compte d'AUTRES clubs sur une ref jamais réellement créditée — `OpponentVenueLinkManager`/`OpponentVenueLinkRepository::countManualByRef`, `venueExternalRef` persisté sur le lien tenant SEULEMENT s'il résout fédéralement, donc un ref présent implique toujours un crédit passé), gardé par `OpponentVenueSuggestionShareTest`) ; les **journaux d'idempotence** keyés sur un uuid globalement unique (`period_reminder_log`, `transition_reminder_log` — **SEC-09 : résiduel assumé**, aucune API de lecture, pas de `club_id`, écrits par le cron ; un `calendar_entry_id` non devinable ne fuit rien sans endpoint) ; le **catalogue de facturation** (`subscription_plan`, global) ; les tables **SA0/SA3** hors tenant (`super_admin`, `admin_audit_log`, `admin_job_run`, `admin_alert_state` — identité et exploitation globales, jamais rattachées à un club) ; `email_verification_token` (seul le sha256 du token est stocké, lié au `User`) ; `constraint_conflict` (porte un `schedule_id`, donc de la donnée tenant, **sans `club_id`** — DERNIER résiduel assumé, `team_tag_assignment` ayant rejoint le régime tenant (BCK-11) : son parent `schedule` est sous RLS et il part par cascade, cf. `SeasonDataPurger`/`OverlayManager`) ; l'infra Doctrine/Symfony (`sport`, `priority_tier`, `reset_password_request`, `messenger_*`, `doctrine_migration_versions`). Règle : une table est hors RLS **ssi** elle ne porte pas de `club_id` — cf. `RlsIsolationTest` (énumération dynamique) et `TenantOwnedInterfaceCompletenessTest`.
- Prod : remplacer les mots de passe `app_user_password` / dev par des secrets réels (env), et rejouer la migration sur la base cible (idempotente côté rôle/grants).

## Tests de non-régression (phase1)

`tests/Security/RlsIsolationTest.php` — SQL brut sur la connexion runtime : isolation SELECT/UPDATE/DELETE, WITH CHECK rejette un `club_id` ≠ GUC, fail-closed sans GUC, bootstrap `club_user`, garde anti-superuser.
`tests/Security/ReadOnlyRoleTest.php` — le rôle `amateo_read` (§ ci-dessus) : classification exhaustive de toutes les tables du schéma `public` (lisible ou liste noire), filet regex sur les colonnes secrètes, policy `readonly_tenant` scopée au prédicat canonique sur chaque table `club_id`, aucune porte `admin_all`, écriture refusée au niveau privilège.
`tests/MessageHandler/ExportPdfHandlerRlsTest.php` — un handler worker pose son propre GUC (GenerateScheduleHandler : même pattern, couvert e2e par la feature Behat `generation-du-planning-de-saison.feature`, `make -C backend behat`).
Les suites Tenant* (HTTP, JWT réel) et `AuthFlowTest` (register) tournent intégralement sous RLS.
