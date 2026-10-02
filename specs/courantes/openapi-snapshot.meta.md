Last verified @ 2026-10-02 (L'horloge simulée ne vit que pour un compte de démonstration :
la route superadmin `POST /api/admin/clubs/{clubId}/clock` est SUPPRIMÉE ; une nouvelle route
tenant `POST /api/club/clock` (widget d'en-tête du compte démo, gestionnaire, 403 pour un vrai
club) décrite par `ClubClockPaths` la remplace. **+0 path**).

**222 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`dfa55b5416f03de78965e145c8d3f6292868e3b7c7d02732cb753b660a361bd7` (`sha256sum` sur le fichier).

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
