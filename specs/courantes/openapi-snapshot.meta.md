Last verified @ 2026-09-15 (PR-1 « adversaire multi-gymnases » côté backend, régénéré par le coder après
`cache:clear` + `api:openapi:export`). **198 paths**
(`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** : que des champs ADDITIFS — le
trajet adverse passe au grain ÉQUIPE (`opponentTeamKey`/`scope`, + `city`/`postalCode` sur la vue liste ;
`opponentTeamKey`/`scope` sur les corps `manual`/`auto`) et le schéma `Fixture` gagne `opponentOrganismeCode`
+ `opponentTeamKey`.
· SHA-256 `b08a6613f99e3305e9ba1da2d5778f3f2ce17a2e2a88fdad9e338aae944f0b6e`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **PR-1 « adversaire multi-gymnases », backend (2026-09-15)** : **+0 path** — le trajet adverse gagne le
  grain ÉQUIPE. `GET /api/opponents/travel` : une entrée par (code, équipe) au lieu d'une par code, avec les
  champs additifs `opponentTeamKey`, `scope` (`TEAM`|`CLUB`|null), `city`, `postalCode`. Les corps
  `POST /api/opponents/travel/manual` et `/auto` acceptent `opponentTeamKey` (+ `scope` optionnel sur
  `manual`) ; leurs réponses gagnent `opponentTeamKey`/`scope`. Le schéma read `Fixture` gagne
  `opponentOrganismeCode` + `opponentTeamKey` (libellé adverse normalisé, servi pour joindre le trajet par
  équipe sans re-dériver). 198 → **198 paths**. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur, aucun payload solveur ne lit `opponent_travel`).
- **B1 — P4-207 « Résolution des conflits », backend (2026-09-15)** : **+1 path** — `PUT`/`DELETE`
  `/api/fixtures/conflicts/{fingerprint}/resolution` (poser/remplacer ou retirer le statut de traitement d'un
  conflit — management-only ; « à traiter » = absence de ligne = `DELETE` idempotent) ; le radar
  `GET /api/fixtures/conflicts` gagne le champ additif `resolution` (objet `status`/`note`/`updatedAt`,
  nullable = « à traiter »). 197 → **198 paths**. Backend PUR, contrat backend⇄engine **inchangé** (aucun appel moteur).
- **E1 — l'appariement des salles, backend (2026-09-14)** : **+1 path** — `GET /api/venues/fbi-labels`
  (par libellé normalisé : gymnase confirmé, gymnase suggéré d'après les rencontres, domiciles / placés /
  non placés) ; `POST /api/venues/{id}/external-labels` accepte `reassign` (l'alias change de porteur, les
  domiciles NON placés au même libellé basculent) et répond `kept` + `previousVenueId`. 196 → **197 paths**.
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
