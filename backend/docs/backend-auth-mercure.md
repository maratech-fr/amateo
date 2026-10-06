# Backend — Sécurité/Auth & Mercure SSE

> La couche sécurité/authentification du backend (JWT, access control, résolution du tenant) et le canal temps réel Mercure (config, souscription frontend, topics & publication). Découpé mécaniquement de `backend-inventory.md` (DOC-59).

Last verified @ 2026-10-06 (découpage thématique DOC-59 — contenu déplacé TEL QUEL depuis `backend-inventory.md`, sans réécriture de fond ; la fraîcheur du contenu est celle de la passe du même jour sur `backend-inventory.md`, l'historique de vérification vit dans `git log -p --follow` ce fichier).

## 4. Security / Auth

### JWT (LexikJWTAuthenticationBundle)

- Firewall `login` (`^/api/login`) : `stateless: true`, `json_login` avec `check_path: /api/login`,
  `username_path: email`, `password_path: password`. Succès/échec gérés par Lexik — le refus parle
  français depuis P4-263 (socle `symfony/translation`, `default_locale: fr`) : voir
  [`error-copy.md`](error-copy.md) pour la règle de classification anglais/français.
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
| `^/api/club-approvals/` | GET, POST | `PUBLIC_ACCESS` (le token porte l'identité) |
| `^/api` | — | `IS_AUTHENTICATED_FULLY` |

Seule la première règle correspondante s'applique. Tout le reste de `/api/*` requiert un JWT
valide (ou, sous `/api/admin`, une session superadmin séparée — jamais un JWT club, §3).
Le firewall `login` applique en plus `login_throttling` (`max_attempts: 5`) ; `/api/register` et
`/api/password/forgot` sont rate-limités par IP (`config/packages/rate_limiter.yaml`, sliding window 5/15 min).
**SEC-11** : tout `^/api` **authentifié** est en plus limité **par utilisateur** (limiteur `api`,
sliding window 300/min en prod ; **3000/min en `when@dev`**, parce que la suite e2e joue tout le club
sous un seul utilisateur dans la même minute — `rate_limiter.yaml`) via `ApiRateLimitSubscriber` (priorité 6, après firewall + tenant) → 429
au-delà ; les endpoints publics (sans `User`) gardent leur limiteur par IP.
**SEC-22** : les quatre routes d'upload xlsx (`/api/fixtures/import`, `/api/fixtures/import/analyze`,
`/api/clubs/{id}/import-teams`, `/api/clubs/{id}/import-teams/analyze`) portent en plus le limiteur
`xlsx_import` **par utilisateur** — 30/h en prod, **300/h en `when@dev`** (Behat et e2e rejouent des
imports en rafale), 5/15 min en `when@test` (le 429 se prouve en 6 requêtes). Il est consommé
APRÈS l'auth : un 403/404/409 ne dépense pas le budget. Les quatre routes partagent aussi les
bornes d'upload de la maison unique `XlsxUploadGuard` — 2 Mo d'octets · 20 Mo cumulés une fois
DÉCOMPRESSÉ · 5 000 lignes, refus **413** nommant le chiffre dépassé. La borne n'accorde aucune
confiance aux en-têtes : elle inflate le zip avec un compteur borné et compte les `<row` du flux,
jamais les tailles déclarées ni le `<dimension ref>` (qui peut mentir). Un fichier qui n'est pas
un zip lisible n'est pas refusé ici (rien à inflater) : il part au parseur en aval, dont un filet
générique rend 422 sans fuite.

### Résolution du tenant (`TenantFilterListener`)

Le `TenantFilterListener` (event `KernelEvents::REQUEST`, **priorité 7 — APRÈS le firewall
de sécurité (priorité 8)**, pour que l'utilisateur JWT soit déjà authentifié) implémente
l'isolation multi-tenant au niveau de chaque requête. Il **retourne immédiatement** sur
`^/api/admin` (SEC-17, `src/EventListener/TenantFilterListener.php:70`) — la console
superadmin n'a pas de tenant, §3 :

1. **Résolution du clubId** : attribut de requête `_club_id` (posé par les contrôleurs publics
   à token), sinon **la membership `ClubUser` active la plus ancienne de l'utilisateur JWT**
   (AUD-BCK-10). **Il n'y a plus de surcharge côté client** : l'en-tête `X-Club-Id` a été retiré
   côté serveur (AUD-SEC-25, `TenantFilterListener::resolveClubId`) — un en-tête envoyé par un
   client quelconque est désormais totalement ignoré.
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
   (`clear-grid`/`transcribe`/`/fill`) est refusé 409 au lieu de détruire en silence.
3. **Validation d'appartenance** : si un `clubId` est résolu et un utilisateur est authentifié,
   le listener vérifie qu'un `ClubUser` **actif** existe pour `(userId, clubId)`. Sinon → 403
   (défense en profondeur maintenant que le seul poseur de `_club_id` côté authentifié est le
   listener lui-même ; une membership `pending` n'a accès à rien).
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
`import-teams`/`import-teams/analyze` requièrent un membership de gestion sur le club du path
(`ClubUserRepository::isManagementRole`, `TeamImportGate::gate`). Gardé par
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

