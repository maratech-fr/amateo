Last verified @ 2026-09-08 (PR-3a — espace « Importer », `coder` — snapshot régénéré dans le même commit.
**194 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+2 paths** (les routes de
traitement `POST /api/fixtures/review` et `POST /api/fixtures/review/deviations`) et le schéma read
`Fixture` gagne `reviewState`, `reviewedAt`, `pendingDeviations` et `ffbbRencontreId`.
· SHA-256 `96b627e177679cb93f22f5033985f3f5909c4baa6bf16747104d027463696ba4`
(`sha256sum`, confirmé sur le fichier régénéré, diff purement additif +294 lignes). Reste du journal non
re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **PR-3a — l'espace « Importer » : traiter les rencontres (2026-09-08)** : **+2 paths** — deux routes de
  traitement (`ReviewFixturesController` + `ReviewFixtureDeviationController`, management + saison écrivable
  + socle pointé) : `POST /api/fixtures/review` (corps `{fixtureIds?}` geste ligne — écarts vidés, REVIEWED —
  ou `{teamId?}` geste masse — les rencontres à écarts pendants sautées et nommées ; réponse
  `{reviewed, skipped[{fixtureId, reason}]}`) et `POST /api/fixtures/review/deviations` (corps
  `{fixtureId, field: date|kickoff|venue, choice: keep_app|take_source}` — tranche UN écart, dernier retiré →
  REVIEWED). Le schéma read `Fixture` gagne `reviewState` (NEW|OUT_OF_SYNC|REVIEWED), `reviewedAt`,
  `pendingDeviations` (liste des écarts source⇄app ouverts) et `ffbbRencontreId`. 192 → **194 paths**. Backend
  PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.20, aucun appel moteur). La trace des écarts
  a MIGRÉ du dépôt (`fbi_ingestion.pending_deviations` supprimée) vers la rencontre (D7).
- **P4-174 D3 v2 — l'aperçu de re-datage d'une indisponibilité découpée (2026-09-05)** : **+1 path** —
  nouvelle route de LECTURE `POST /api/calendar_entries/{id}/redate-preview` (`RedatePreviewController`,
  management, aucune écriture) qui rend les EFFETS d'un re-datage de mère découpée (keep/shift/absorb/
  vanish/birth/holiday_takes_over, chaque ligne avec son `label` français, dates en clair, aucun
  identifiant interne) + un `token` d'état. Le PUT `CalendarEntry` gagne `previewToken` (corps write) :
  une mère découpée sans jeton → 422 « demandez l'aperçu », jeton périmé → 409. Le schéma read
  `CalendarEntry` gagne `redateNeedsPreview` (booléen, exclusif de `redatable`) sur les 3 variantes read.
  Foyer unique `SplitMotherRedatePlanner` (aperçu ET apply). 191 → **192 paths**. Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.20, aucun appel moteur).
- **P4-173 — le bloc servi `staleness` sur `SchedulePlan` (2026-09-05)** : **+0 path** — le schéma
  lecture `SchedulePlan` gagne une propriété `staleness` (nouveau schéma `SchedulePlanStaleness` :
  `manuallyEdited`/`constraintsChanged`/`resourcesChanged`), ou `null`. Elle porte la péremption de
  la version POINTÉE par le plan (les 3 drapeaux de `Schedule`), pour que le cockpit dise « à
  régénérer » sans redériver la règle. `null` quand le plan ne pointe aucune version, ou que sa
  fenêtre est révolue (`endDate` < aujourd'hui). Renseignée en batch (une requête `id IN (versions
  pointées du club)`, mémoïsée par requête — `SchedulePlanStalenessResolver`, patron `redatable`) sur
  les 3 variantes read (jsonld, collection). Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.20, aucun appel moteur).
- **D3 v1 PR-1 complément — le champ servi `redatable` sur `CalendarEntry` (2026-09-04)** : **+0 path** —
  la ressource lecture `CalendarEntry` gagne une propriété booléenne `redatable` : vraie ssi l'entrée
  est une racine de FERMETURE portant un plan « d'un bloc » (sans mère, sans semaines-enfants) — le
  seul cas où le front peut proposer de déplacer les dates, les autres périodes gardant leur fenêtre
  figée (règle d'or : le backend dit, le front affiche). Servie sur les 3 variantes du schéma read
  (jsonld, collection). Prédicat UNIQUE (`CalendarEntryRedatability`) partagé avec le dégel de fenêtre
  au PUT. Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.20, aucun appel moteur).
- **P2-60 PR-1 — le budget solo en lecture (`GET /api/team_solo_budgets`) (2026-09-03)** : **+1 path** —
  ressource LECTURE SEULE `TeamSoloBudget` (GetCollection uniquement, pas d'item) : le budget de
  réservation individuelle de chaque équipe par portée — `teamId`, `schedulePlanId`, `effectiveSessions`
  (S), `blockSessions` (B), `residual` (R = S − B), `individualUsed`, `inBlock`. Filtrable par
  `?schedulePlanId=` (absent/NULL = socle, UUID = plan de période ; malformé → 400, inexistant/étranger
  → 422). Provider dédié `TeamSoloBudgetStateProvider` (délègue à `SoloReservationBudget`, maison unique
  de R), pagination désactivée. 190 → **191 paths**. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.20, aucun appel moteur — garde d'écriture à la source).
- **P2-51 PR-7 — retrait de `SharedTrainingGroup` (2026-08-31)** : **−2 paths** — le modèle groupe
  {équipes, K} est retiré entièrement (backend/contrat/moteur/écran/seeder), `SharedTrainingBlock`
  devient la SEULE mutualisation. `GET/POST /api/shared_training_groups` et
  `GET/PUT/DELETE /api/shared_training_groups/{id}` disparaissent du snapshot. 192 → **190 paths**.
  Contrat backend⇄engine bumpé **2.19** (retrait de `sharedTrainings`/`SharedTrainingGroupSchema`
  des deux endpoints qui les portaient).
- **P2-51 PR-5b — `POST /api/schedule-slots/move-group` (2026-08-31)** : **+1 path** — le rail de
  DÉPLACEMENT de bloc atomique (D11) : déplace la séance d'un bloc (tous ses créneaux membres à la
  case source) vers une case cible, sous UN verdict et en une transaction (tout-ou-nothing). Corps
  `{scheduleId, blockId, source{venueId,dayOfWeek,startTime}, target{…}}` — le serveur résout
  lui-même les créneaux membres (jamais de slotIds clients). 200 (`movedSlotIds`) / 422 refus nommé
  (`shared_block_broken` si le geste casse le bloc) ou `slot_unavailable` / 409 génération ou plan
  choisi. Déclaré dans `PathContributor/ManualEditPaths.php`. 191 → **192 paths**. Backend + contrat
  backend⇄engine **bumpé 2.17 → 2.18** : `/validate-assignments` juge désormais N déplacements sous
  UN verdict (`candidates`/`references` LISTES remplacent le singulier — le déplacement de bloc les
  émet à N, le rail simple à 1).
- **P2-51 PR-5 — `POST /api/reservations/group` se ré-ancre sur le bloc (2026-08-31)** : **+0 path** —
  le corps du POST existant gagne `sharedTrainingBlockId` (résolu EN PREMIER, `SharedTrainingBlock`),
  `sharedTrainingGroupId` devient le repli legacy (transitoire jusqu'à la PR-6 frontend/PR-7
  nettoyage) ; aucun des deux n'est plus `required` isolément (au moins un doit être fourni, sinon
  400). Déclaré dans `PathContributor/UncoveredCustomPaths.php`. 191 → **191 paths**. Backend PUR,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.17, ce rail n'appelle pas le moteur).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
