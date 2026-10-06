# Observabilité — request-id & logs structurés

> Livré 2026-08-13 (lot corrélation). Ce doc est la maison canonique de la chaîne.

## La chaîne du request-id

```
front (ky beforeRequest : crypto.randomUUID → X-Request-Id)
  → bord backend (RequestIdListener, priority 256 — AVANT le firewall :
    les 401/403 ont aussi leur référence ; VALIDE la forme UUID et
    RÉGÉNÈRE si absent/malformé — un header libre recopié dans des logs
    JSON serait une injection)
  → logs backend (Monolog, processor : extra.request_id / club_id / user_id)
  → bus Messenger (RequestIdStamp posé au dispatch ; le middleware le
    restaure dans le worker puis NETTOIE en finally — l'id d'une requête
    HTTP suit donc son solve async)
  → engine (header X-Request-Id sur les 3 POST ; middleware FastAPI →
    contextvar → présent dans les logs JSON du solve, y compris depuis le
    thread — asyncio.to_thread copie le contexte)
  → réponse HTTP : X-Request-Id renvoyé sur TOUTE réponse
  → UI : les erreurs ≥ 500 affichent « (réf. incident : xxxxxxxx) »
    (8 premiers caractères) — c'est ce que le support demande au club,
    et ce que le futur canal signalement joindra automatiquement.
```

## Formats de logs

- **Backend** : Monolog — prod = **JSON sur stderr** (`JsonFormatter`, stacktraces incluses),
  dev/test = format ligne lisible. Les processors s'appliquent aux deux (le contexte apparaît
  dans `extra`). **Ids seulement, jamais d'email ni de nom** (`docs/security/rgpd.md` §5).
- **Engine** : JSON stdlib partout (`app/core/logging.py` — zéro dépendance). Les logs
  `uvicorn.access` restent en texte : la corrélation vit dans les logs applicatifs `engine`.
- **Rétention inchangée** : Docker json-file 10m×3 par service, pas de shipping — la valeur
  pleine arrive avec Sentry (les tags `request_id` sont posés partout, inertes tant que les
  DSN sont vides) et un éventuel shipping futur.

## Sentry — filtre `before_send` backend (2026-10-03)

Un refus HTTP **client** (statut < 500 — `NotFoundHttpException`, `MethodNotAllowedHttpException`,
`BadRequestHttpException`, `AccessDeniedHttpException`, `UnauthorizedHttpException`…) n'est PLUS
envoyé à Sentry : `App\Sentry\BeforeSend` (`backend/config/packages/sentry.yaml`, `before_send`)
filtre sur le statut de toute `HttpExceptionInterface`, pas sur une liste fermée de classes — un
scan de robot (404/405/400/401/403 en rafale) n'inonde plus le projet de faux positifs. Les 5xx
(vraie panne serveur), les échecs Messenger et les erreurs de commande console passent tels quels
(aucun statut HTTP < 500 à filtrer). `Symfony\Component\Security\Core\Exception\AccessDeniedException`
(distincte de la `HttpException` du même nom) est écartée séparément via `ignore_exceptions` dans le
même fichier de config. Gardé par `backend/tests/Unit/Sentry/BeforeSendTest.php`.

## Sentry — identité minimale posée sur chaque événement (2026-10-06)

Pour rejouer un bug sans collecter de PII, chaque événement ENVOYÉ (après le filtre `before_send`)
porte deux valeurs, **explicitement posées, rien d'autre** — décision fondateur 2026-10-06 :

- l'**identifiant INTERNE** de l'utilisateur courant (`user.id` seul, un pseudonyme ; **jamais**
  email/nom/IP) ;
- le **code FFBB du club** courant en tag `club_ffbb` (ex. `ARA0069013`), club de démo compris
  (utile au diagnostic).

La collecte reste par ailleurs **minimale** : `send_default_pii: false` côté backend
(`backend/config/packages/sentry.yaml`) et `dataCollection` tout restrictif côté frontend
(`buildSentryOptions`, `frontend/src/app/sentry.ts`) — ni IP, ni cookies/en-têtes, ni corps de
requête, aucune collecte automatique d'utilisateur.

- **Backend** : `App\Sentry\BeforeSend` lit le token de sécurité (jamais un `SuperAdmin`, qui n'est
  pas un `User`) et l'attribut `_club_id` posé par `TenantFilterListener` — ABSENT sur
  `/api/admin/**` (le listener sort avant toute résolution de club), donc un superadmin ne pose
  jamais de tag club. La résolution du code FFBB (lecture du club) est best-effort (try/catch) :
  elle ne masque jamais l'erreur d'origine. Hors requête (Messenger, console), ni token ni
  `_club_id` → rien n'est posé. Gardé par `backend/tests/Unit/Sentry/BeforeSendTest.php`.
- **Frontend** : `useApplySentryIdentity` (monté par `AppLayout`, l'appli CLUB) pose
  `Sentry.setUser({ id })` et le tag `club_ffbb` depuis `/api/me`, et les RETIRE au logout
  (démontage). La console superadmin a son propre shell (`AdminShell`), hors `AppLayout` : pas de
  club, pas de tag. Gardé par `frontend/src/shared/hooks/useApplySentryIdentity.test.tsx`.

## Sentry — collecte minimale explicite côté frontend (v11, 2026-10-06)

Le SDK front (`@sentry/react` **v11**) capture les **ERREURS uniquement** (`tracesSampleRate: 0`,
pas d'APM/replay) et **sans aucune PII**. En v11, `sendDefaultPii` a disparu au profit de
`dataCollection`, et un `dataCollection` NON DÉFINI collecte TOUT par défaut (IP, utilisateur,
cookies, en-têtes, corps de requête/réponse) — l'INVERSE du défaut v10. Les options d'init sont
donc extraites en fonction pure `buildSentryOptions` (`frontend/src/app/sentry.ts`), qui fige
chaque catégorie au niveau le plus restrictif (`userInfo: false` — donc pas d'`ip_address` —,
`cookies/httpHeaders/urlQueryParams: false`, `httpBodies: []`, `genAI`/`graphQL`/`databaseQueryData`/
`queues`/`stackFrameVariables` coupés), verrouillé par `frontend/src/test/sentryOptions.test.ts`.
`frameContextLines` reste au défaut (5) : ce sont les lignes de NOTRE source autour de la trace,
pas une donnée de l'utilisateur (`docs/security/rgpd.md` §5). Activation = DOUBLE geste P4-65
(poser `VITE_SENTRY_DSN` au build **et** autoriser l'hôte d'ingestion dans `connect-src` —
`docker/frontend/csp.conf`, garde `frontend/tooling/sentryCspGuard.ts`) ; sans DSN le SDK est inerte.

## Se servir de la corrélation (support)

1. Le club donne la « réf. incident » affichée (8 chars) — ou le canal signalement la joint.
2. `docker compose logs backend engine | grep <ref>` : toute la traversée, solve compris.
3. Sentry actif : chercher le tag `request_id`.
