Last verified @ 2026-09-05 (P4-173, `coder` — snapshot régénéré dans le même commit.
**191 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** (aucune route
nouvelle : un bloc de LECTURE `staleness` s'ajoute au schéma `SchedulePlan` — 3 variantes read/collection,
plus le schéma `SchedulePlanStaleness`)
· SHA-256 `d197df291c4592446913c858441cd38389fe71b53fee2694a92070ea573c831b`
(`sha256sum`, confirmé sur le fichier régénéré, aucun diff local). Reste du journal non re-confronté
au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
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
- **P2-51 PR-1 — le modèle du bloc de mutualisation (2026-08-31)** : **+2 paths** — CRUD API Platform
  de la ressource `SharedTrainingBlock` : `GET/POST /api/shared_training_blocks` (liste **scope
  club+saison**, filtrable par `schedulePlanId` — NULL = socle saison, UUID = plan de période) +
  `GET/PUT/DELETE /api/shared_training_blocks/{id}`. Corps : `teamIds` (2..10), `commonSessions`,
  `schedulePlanId`. Écriture management (`SharedTrainingBlockStateProcessor`) — voir
  `backend-inventory.md` pour les gardes. 189 → **191 paths**. Backend PUR, **modèle seul** (PR-1
  d'un lot à 4 PR — PR-2 émettra le bloc au payload moteur) : contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.16, aucun appel moteur à ce stade).
- **BCK-22 — le budget global de l'autofill de trajet (2026-08-28)** : **+0 path** — pas de route ni
  de DTO nouveau, un **ENUM inline** existant se complète (déclaré dans
  `PathContributor/OpponentTravelPaths.php:56` depuis P4-138, 2026-08-30 ; ex-`CustomRoutesOpenApiFactory.php:841`
  avant l'éclatement par domaine) : `POST /api/venue-travel-times/autofill`
  peut désormais rendre `unresolved[].reason = "budget_exceeded"` (lot interrompu par
  `IgnRoutingClient::BATCH_BUDGET_SECONDS`, distinct de `missing_geo`/`routing_failed` — « relancez
  pour continuer », pas un échec). 189 → **189 paths**. Backend + frontend (`reasonLabel` devient une
  table exhaustive côté écran), contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.16, aucun
  appel moteur).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
