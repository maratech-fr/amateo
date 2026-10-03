Last verified @ 2026-10-03 (deux changements additifs du jour, régénéré depuis le backend courant :
`membershipStatus` de `POST /api/register/verify` gagne `club_pending` (reprise d'un club sans membre
par approbation) ; `indeterminate: boolean|null` sur les 3 réponses 200 de `ManualEditPaths` (ENG-51) ;
aucun path ajouté ni retiré).

**222 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`34ce49eae0c856b42a6e48f2c4bedeee2354bf756d6d52d2bb90aa3927119c96` (`sha256sum` sur le fichier).

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
