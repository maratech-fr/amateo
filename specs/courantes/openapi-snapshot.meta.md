Last verified @ 2026-10-02 (Le niveau d'une équipe jeune suit son engagement FFBB — chaque ligne de
`GET /api/ffbb/engagements` (`FfbbEngagementPaths`) porte désormais `deducedLevel`
(`DEPARTEMENTAL`/`REGIONAL`/`NATIONAL`, le niveau qu'implique une catégorie U9–U18 en championnat/
brassage) et `alignment` (`MISSING`/`MISMATCH`) face à l'équipe suggérée. **±0 path**).

**221 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`dc9ddb41a92a8306d24c9f50277395864a0fb375277aea1fdcff78282df38b30` (`sha256sum` sur le fichier).

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
