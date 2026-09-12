Last verified @ 2026-09-12 (P4-200 C1 — le pont xlsx → Engagements FFBB, régénéré par l'orchestrateur après
`docker compose restart php-fpm` + `cache:clear` + `api:openapi:export`. **196 paths**
(`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** : les deux routes
`GET /api/ffbb/engagements` et `POST /api/ffbb/engagements/confirm` existaient. Ce qui change : la réponse de
`list` gagne `suggestionSource` (enum `pairing` · `canonical` · `fbi`, nullable) et le corps de `confirm`
est désormais documenté avec un `competitionId` optionnel par pairing — **+40 lignes**, portées par
`FfbbEngagementPaths` (contributeur du domaine).
· SHA-256 `5c300691dbbc32284434988764e5dcda37d28f6095165a7461f93e994b7abf4d`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **P4-200 C1 — le pont xlsx → Engagements FFBB (2026-09-12)** : **+0 path** — `GET /api/ffbb/engagements`
  répond `suggestionSource` (`pairing` | `canonical` | `fbi` | null) à côté de `suggestedTeamId` /
  `suggestedCompetitionId` ; `POST /api/ffbb/engagements/confirm` accepte un `competitionId` optionnel par
  pairing (les réfs FFBB se posent SUR la compétition xlsx de l'équipe choisie). 196 → **196 paths**.
- **P4-187b — l'écran « Rattacher » de l'onglet Importer (2026-09-09)** : **+0 path** — pur frontend, mais le
  snapshot gagne les DEUX propriétés que P4-187a avait décrites sans les faire atterrir dans l'export : le
  schéma read `Venue` gagne `externalLabels` (list<string>, lecture seule — jamais writable par le PUT) et
  `Fixture` gagne `suggestedVenueId` (proposition floue de gymnase pour un domicile importé sans salle,
  lecture). Le manque venait d'un export P4-187a lancé contre un cache de métadonnées API Platform périmé
  (le code portait bien les `#[Groups(['read'])]`) ; `cache:clear` + ré-export les ajoute (3 variantes read
  chacune). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.20, aucun appel moteur).
- **P4-187a — un domicile importé retrouve son gymnase depuis le libellé FBI/FFBB (2026-09-09)** :
  **+2 paths** — deux routes de rattachement (`VenueExternalLabelController`, management + saison écrivable) :
  `POST /api/venues/{id}/external-labels` (corps `{label}` — ajoute l'alias normalisé, idempotent, puis
  backfille les domiciles du club encore sans salle au même libellé ; réponse `{venueId, label, attached}` ;
  422 libellé vide ou déjà porté par un autre gymnase) et `DELETE /api/venues/{id}/external-labels/{label}`
  (retire l'alias, 204, ne dépointe aucune rencontre). Le schéma read `Venue` gagne `externalLabels`
  (list<string>, lecture seule — jamais writable par le PUT) et `Fixture` gagne `suggestedVenueId`
  (proposition floue en lecture pour un domicile importé sans salle). 194 → **196 paths**. Backend PUR,
  contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.20, aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
