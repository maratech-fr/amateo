Last verified @ 2026-09-28 (snapshot régénéré depuis le backend vivant : la ressource
`ClubLeagueWindow` (P4-272 ①) expose le CRUD gestionnaire de la copie club de l'enveloppe ligue —
`GET/POST /api/club_league_windows` et `GET/PUT/DELETE /api/club_league_windows/{id}` ; chaque item
porte un `badge` (« modified »/« added ») calculé serveur ; `GET /api/league-match-windows` sert
cette copie, même surface de route ; `GET /api/training/placed-conflicts` (P4-269) expose le radar
« une personne à deux endroits » du planning d'entraînement en vigueur).

**211 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`7672e6a11adc24c627cb0fa57dfaf45cdc77cbc97aba7183d4d2b48894f6f653` (`sha256sum` sur le fichier).

Règle (skill `documentation-update`) : régénérer ce snapshot à chaque changement d'API (resource,
controller custom, DTO exposé) et bumper ce stamp. **Le compte et l'empreinte annoncés en tête ne
sont pas une promesse sur l'honneur** : `OpenApiSnapshotMetaMatchesSnapshotTest`
(`backend/tests/Unit/Documentation/`) les recalcule contre le snapshot réel à chaque run et rougit
si l'un des deux ment — non bloquant, `phase1`, `unit-tests` seul ; le bumper à la main reste
nécessaire (le test ne régénère rien, il compare).

Piège : une route custom n'apparaît dans l'export que si elle est déclarée dans le
`CustomPathContributor` de son domaine (`backend/src/OpenApi/PathContributor/`), composé par
`CustomRoutesOpenApiFactory` — ajouter une entrée directement à la factory ne fait rien, elle ne
fait que composer les contributeurs dans un ordre significatif
(`backend/docs/backend-inventory.md` §OpenAPI). Régénérer seul ne suffit donc pas non plus : une
route custom oubliée de son contributeur reste invisible même après régénération.

L'historique des changements d'API vit dans git (`git log -p --follow
specs/courantes/openapi-snapshot.meta.md`) et les traces datées dans `etat-des-lieux.md` §3 —
jamais dans ce fichier.
