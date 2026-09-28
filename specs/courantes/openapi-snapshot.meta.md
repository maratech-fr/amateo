Last verified @ 2026-09-28 (snapshot régénéré : la réponse de `POST /api/fixtures/place` porte la
raison d'échec `not_selected` dans l'énumération `unplaced[].reason` — distincte de `venue_full` :
un créneau licite restait libre mais le solveur ne l'a pas retenu dans le temps imparti (P4-240) ;
la propriété `awayTravel` de `Fixture` (lecture) mentionne dans sa DESCRIPTION les champs
`address`/`postalCode` de l'adresse du gymnase apparié (P4-267) — objet à `additionalProperties`,
seule la description bouge ; aucun chemin ajouté, compte inchangé, empreinte recalculée).

**208 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`2268084d603c7210e9b1bedae8a595a5728fbcc55fccdc83c76d1f8faf4d2434` (`sha256sum` sur le fichier).

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
