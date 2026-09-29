Last verified @ 2026-09-29 (P4-239 — le champ mort `ffbbTeamId` disparaît du schéma d'écriture de
`Team` : la propriété était déclarée sur `TeamInput` mais aucun processor ne la lisait ; le champ
moteur `ffbb_team_id` reste optionnel côté engine et n'est jamais envoyé, donc pas de bump de
contrat. Régénéré à froid depuis le backend vivant. Correction incidente au passage : `scopeTargetId`
et `venueId` de `MatchConstraint` gagnent `format: uuid` + `externalDocs` schema.org/identifier —
tous deux portent `#[Assert\Uuid]`, l'export précédent [P4-272 ③] les avait ratés sur un cache de
métadonnées api-platform tiède ; aucun nom de route ni de propriété ne change, la surface du contrat
est identique. Aucune route ajoutée ni retirée [214 paths inchangé]).

**214 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) · SHA-256
`18c11db41b451f307be002a6f1b50437f1d950d59576e7f3688c881bfac55a07` (`sha256sum` sur le fichier).

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
