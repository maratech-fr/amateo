Last verified @ 2026-10-02 (Horloge simulée générique par club : nouvelle route superadmin
`POST /api/admin/clubs/{clubId}/clock` (pose/relâche l'horloge de n'importe quel club, `confirmName`
exigé pour un club réel daté) décrite par `AdminDemoPaths` ; l'ancienne `POST /api/admin/demos/bccl/clock`
devient `POST /api/admin/demos/{target}/clock` (bccl ou prospect, même path). **+1 path**).

**224 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`dc1b80ff6997b6a95637e07c8d8b56600c1b8e99d1a2b4b05897a7d52aa48753` (`sha256sum` sur le fichier).

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
