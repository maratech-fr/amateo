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

## Se servir de la corrélation (support)

1. Le club donne la « réf. incident » affichée (8 chars) — ou le canal signalement la joint.
2. `docker compose logs backend engine | grep <ref>` : toute la traversée, solve compris.
3. Sentry actif : chercher le tag `request_id`.
