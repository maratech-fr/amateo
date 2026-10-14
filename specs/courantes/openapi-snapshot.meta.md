Last verified @ 2026-10-14 (« Échange atomique de deux séances », P4-327) : nouveau path custom
`POST /api/schedule-slots/swap` (deux séances qui troquent leurs cases sous UN verdict apparié,
tout-ou-rien, RIEN supprimé ; corps `{slotId, otherSlotId, dryRun?}` ; retour `{valid, dryRun?,
indeterminate?, compromises, movedSlotIds}` ; 422 avec `code` nommé par refus pré-moteur ; schémas
inline, aucun nouveau composant), déclaré dans `ManualEditPaths`. Acquis 2026-10-12 (« Retirer une
séance placée ») : deux paths custom
`POST /api/schedule-slots/{id}/remove` et `POST /api/schedule-slots/remove-group` (retrait d'une
séance placée / d'une séance de groupe, SANS verdict moteur ; corps `{dryRun?}` et
`{scheduleId, blockId, source{venueId,dayOfWeek,startTime}, dryRun?}` ; retour `{removed, lockLevel,
unmetPresence, dryRun?}` / `{removedSlotIds, removed[], unmetPresence, dryRun?}` ; schémas inline),
déclarés dans `ManualEditPaths`. Acquis antérieur : nouveau path
`POST /api/coach-wishes/transfer` (geste gestionnaire ; corps `{wishIds}`, récap
`{converted, ignored, ignoredReasons}` ; schémas inline, aucun nouveau composant), déclaré dans
`CoachWishPaths`. Acquis V1bis : `POST /api/schedules/{id}/load-locks` (fait des verrous d'une
version de PÉRIODE les données en cours de son plan — pose le pointeur, ne génère pas) et
`ScheduleCapabilities.canLoadLocks`. Acquis V1 : `Schedule.isLockSource` et `Schedule.hardLockCount`.
Acquis antérieurs : `SharedTrainingBlock.sessions` (schéma `SharedTrainingBlockSession` :
`teamId`/`teamName`/`dayOfWeek`/`startTime`/`endTime`/`venueName`, lot 9 F3),
`ScheduleSlotTemplate.sharedTrainingBlockId` + `TeamPeriodOverride.source` (lot 9 F3), route
`POST /api/schedule-slots/{id}/mutualize` + `SharedTrainingBlock.label` (lot 9 B1),
`CoachWish.keepSeasonSlots` (P2-63 PR 3).

**244 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`b6523af1df4bbfe859c3f81bf5ca319b829e5dc8bbe32d0be79f92ff00c993b8` (`sha256sum` sur le fichier).

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
