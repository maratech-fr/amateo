Last verified @ 2026-09-28 (snapshot régénéré : `POST /api/fixtures/place` accepte désormais un
corps OPTIONNEL `{from, to}` (dates AAAA-MM-JJ) qui restreint le placement à cette semaine
(« Placer ce week-end », P4-240 ④) — sans corps, tout le club, inchangé ; sa réponse gagne un `422`
(fenêtre invalide : une borne n'est pas une date, ou from après to) et sa `403` couvre en outre le
régime crédit Découverte, où le placement automatique doit se faire week-end par week-end ; aucun
chemin ajouté, compte inchangé, empreinte recalculée).

**208 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`70a91a119729c97a11afb9e6f83fc1483122f86218a05e601b6ad0aedb0023a4` (`sha256sum` sur le fichier).

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
