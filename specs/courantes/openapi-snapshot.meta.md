Last verified @ 2026-10-10 (« Charger » pour les périodes, V1bis) : nouveau path
`POST /api/schedules/{id}/load-locks` (fait des verrous d'une version de PÉRIODE les données en
cours de son plan — pose le pointeur, ne génère pas) et `ScheduleCapabilities.canLoadLocks` (booléen
— le geste est-il offert : période terminée, plan non validé, rien en cours, pas déjà la source).
Acquis V1 : `Schedule.isLockSource` (booléen — cette version est la SOURCE des verrous de son plan,
d'où « Régénérer » repart) et `Schedule.hardLockCount` (entier — nombre de verrous HARD de la
version, pour le « (N verrous) » de la confirmation de régénération). Acquis antérieurs :
`SharedTrainingBlock.sessions` (schéma `SharedTrainingBlockSession` :
`teamId`/`teamName`/`dayOfWeek`/`startTime`/`endTime`/`venueName`, lot 9 F3),
`ScheduleSlotTemplate.sharedTrainingBlockId` + `TeamPeriodOverride.source` (lot 9 F3), route
`POST /api/schedule-slots/{id}/mutualize` + `SharedTrainingBlock.label` (lot 9 B1),
`CoachWish.keepSeasonSlots` (P2-63 PR 3).

**240 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`6214c41a1f147a3ed9b133bdc9639e84873c3573482249a91ac384c6b69f0192` (`sha256sum` sur le fichier).

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
