Last verified @ 2026-10-06 (P4-299 — un gestionnaire invite une adresse e-mail à rejoindre son club :
6 routes custom (`InvitationPaths`) — gestion `GET`/`POST /api/invitations`, `POST /api/invitations/{id}/resend`,
`DELETE /api/invitations/{id}`, acceptation connectée `POST /api/invitations/{token}/accept`, et la page
publique à jeton `GET`/`POST /api/invitations/public/{token}[/accept]`. +6 paths).

**231 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`e9b901515c23b31289ff8e9c2250dd3c36b4e2afd8b626455bbd94666ab0a60a` (`sha256sum` sur le fichier).

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
