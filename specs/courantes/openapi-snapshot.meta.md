Last verified @ 2026-09-30 (Gymnases — nouvelle route `GET /api/venues/geo-check` (tag `Venue`,
management-only, tenant du JWT) : contrôle de cohérence LECTURE SEULE de la position stockée d'un
gymnase rattaché à une salle FFBB, renvoie un tableau d'alertes `{venueId, reason:
OTHER_STREET|FAR_FROM_ADDRESS, ffbbAddress, pointStreet|null, distanceM|null}`. +1 path. Régénéré à
froid depuis le backend vivant).

**216 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`72ce5eef7c27a9fbda91b73cc7dadeba819471250d3c1b7170203a285d92b8c4` (`sha256sum` sur le fichier).

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
