Last verified @ 2026-10-10 (lot 9 F3, nommer les séances emportées) : `SharedTrainingBlock` expose
`sessions` (tableau des séances placées liées au bloc — nouveau schéma `SharedTrainingBlockSession` :
`teamId`/`teamName`/`dayOfWeek`/`startTime`/`endTime`/`venueName`, vide pour un bloc socle ou sans
séance) ; aucun nouveau path. Acquis antérieurs : `ScheduleSlotTemplate.sharedTrainingBlockId` +
`TeamPeriodOverride.source` (lot 9 F3), route `POST /api/schedule-slots/{id}/mutualize` +
`SharedTrainingBlock.label` (lot 9 B1), `CoachWish.keepSeasonSlots` (P2-63 PR 3).

**239 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`28dfb0f8b83c81cc1fe2d3bdc472e14af16297c52f710055b7c787ac5b8fb6e6` (`sha256sum` sur le fichier).

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
