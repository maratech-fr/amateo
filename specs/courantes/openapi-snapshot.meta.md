Last verified @ 2026-09-29 (P4-272 ③ — règles de match du club : la ressource `MatchConstraint`
apparaît [3 routes ajoutées : `GET/POST /api/match_constraints`, `GET/PUT/DELETE
/api/match_constraints/{id}`] avec son schéma [scope, ruleType HARD/PREFERRED, daysOfWeek,
kickoffMin/kickoffMax nullables] ; plus la route custom `GET /api/match-constraints/coherence`
[alerte de cohérence règles club ⇄ créneaux idéaux, `SeasonAndFixturePaths.php`] ; le radar gagne la
famille `CLUB_RULE_VIOLATION` et le placement le motif d'échec `club_rule_no_slot`
[`SeasonAndFixturePaths.php`]. Régénéré depuis le backend vivant. Antérieur P4-270 — description texte
de la réponse `GET /api/venues/{id}/deletion-impact` recalée à la main sur `UncoveredCustomPaths.php`
[champ additif `placedFixtures` aux côtés de `declaredFixtures`] ; antérieurement P4-271 — la
ressource `MatchSlotRotation` disparaît [les 2 routes
`GET/POST /api/match_slot_rotations` et `GET/PUT/DELETE /api/match_slot_rotations/{id}` retirées,
semaine type A/B désormais un tag `week` sur le créneau idéal] et `TeamMatchHabit` gagne le champ
`week` [A/B/ALL] en lecture comme en écriture ; la description de la ressource
[`TeamMatchHabitResource.php`] recalée sur « un par équipe » ; les routes de P4-272 ② [plages de
ligue suggérées] toujours présentes).

**214 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`a7c3942c9562e929f977822ed2ef9e743a6a215fee536447ce59a79218bcb1bd` (`sha256sum` sur le fichier).

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
