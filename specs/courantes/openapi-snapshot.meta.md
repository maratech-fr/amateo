Last verified @ 2026-10-09 (D2 PR B rebasée sur main = D1 + D2 PR A : rail gestionnaire des
demandes de mutualisation `/api/coach_wish_mutualizations` (collection + item) + aperçu coach par
coach `GET /api/coach_wish_campaigns/{id}/preview` ; le GET public de doléance coach porte désormais
`partnerTeams`/`teamLinks`/`mutualizations` ; le snapshot régénéré intègre aussi l'aperçu d'e-mail
D1 `GET /api/coach_wish_campaigns/{id}/email-preview`).

**238 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`f0cb26e25486c49e3249ee75e1879569384c8f8c1343ea9ae72dab29a5e1f2e8` (`sha256sum` sur le fichier).

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
(`backend/docs/backend-controllers.md` §OpenAPI). Régénérer seul ne suffit donc pas non plus : une
route custom oubliée de son contributeur reste invisible même après régénération.

L'historique des changements d'API vit dans git (`git log -p --follow
specs/courantes/openapi-snapshot.meta.md`) et les traces datées dans `etat-des-lieux.md` §3 —
jamais dans ce fichier.
