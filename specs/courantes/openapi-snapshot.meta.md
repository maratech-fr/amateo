Last verified @ 2026-09-29 (snapshot régénéré depuis le backend vivant : P4-271 — la ressource
`MatchSlotRotation` disparaît (les 2 routes `GET/POST /api/match_slot_rotations` et
`GET/PUT/DELETE /api/match_slot_rotations/{id}` retirées, semaine type A/B désormais un tag `week`
sur le créneau idéal) et `TeamMatchHabit` gagne le champ `week` (A/B/ALL) en lecture comme en
écriture).

**209 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`947841f5912d9d772ac33dd1be3c3db43869b1016b624539146b1a4f9278f71a` (`sha256sum` sur le fichier).

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
