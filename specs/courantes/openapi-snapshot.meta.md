Last verified @ 2026-09-30 (Trajets — le levier `VenueTravelRuleSetting` (`travelTime`) gagne deux
propriétés lecture+écriture : `toleranceMinutes` (battement toléré) et `defaultMinutes` (temps par
défaut d'un couple sans temps) ; son intensité accepte désormais `OFF` en plus de
`PREFERRED`/`MANDATORY`. Descriptions de `Coach.isVehicled`/`CoachInput.isVehicled` et de
`VenueTravelTimeInput.walkingMinutes` reformulées « à vélo » (mode non véhiculé = vélo/trottinette,
le nom technique `walking*` reste). Aucun path ajouté ni retiré. Régénéré à froid depuis le backend
vivant).

**215 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`4258a4fded87fed24659c56a779fd9118f554af1dd706776218b76c7174b822d` (`sha256sum` sur le fichier).

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
