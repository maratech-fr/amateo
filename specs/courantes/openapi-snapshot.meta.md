Last verified @ 2026-09-27 (snapshot régénéré : `/api/matches/deadline-outlook` gagne un champ
GLOBAL `toConfirmCount` — domiciles « validé ligue » prêts — et le `toPlaceCount` de chaque fenêtre
SOUSTRAIT désormais les validables ; `/api/fixtures/league-validation` : `matured[]` gagne
`maturedBy` (`deadline`/`firstMatchPlayed`) + `firstMatchDate`, `deadline` devient nullable
(critère élargi : un championnat est proposé si l'échéance est passée OU si le premier match est
joué) ; aucun chemin ajouté, compte inchangé, empreinte bumpée).

**208 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`bfff6ac37d40eea6a3857fdb4cf06448fac16e44fc2ceac9b854cf8cd497ae9f` (`sha256sum` sur le fichier).

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
