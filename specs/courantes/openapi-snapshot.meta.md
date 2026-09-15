Last verified @ 2026-09-15 (lot « une personne = ses équipes coachées + ses équipes où elle joue » côté
backend, régénéré par le coder après `cache:pool:clear --all` + `api:openapi:export`). **199 paths**
(`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** : le radar des conflits gagne des
champs additifs (rôle par côté + rôle agrégé étendu au joueur), aucune route nouvelle.
· SHA-256 `917e17709508515b05b16b39a247eac46713b3c2ce1a6e50a502d9e049625a39`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **« Une personne = ses équipes coachées + ses équipes où elle joue », backend (2026-09-15)** : **+0 path** —
  le radar `GET /api/fixtures/conflicts` unit les coachs (`team_coach`) et les joueurs (`CoachPlayerMembership`
  actifs) dans une même carte personne→équipes. Champs ADDITIFS : `coachRole` gagne la valeur `PLAYER` (agrégat
  MAIN si tous MAIN, ASSISTANT dès qu'un côté ASSISTANT, PLAYER sinon) ; chaque côté d'un conflit personne porte
  son `role` (`MAIN`|`ASSISTANT`|`PLAYER`) — `left.role`/`right.role` sur MATCH_MATCH, `fixture.role`/`training.role`
  sur MATCH_TRAINING ; l'enum `type` du conflit est recalé sur ses 10 familles réelles (VENUE_OVERLAP,
  LEAGUE_WINDOW_VIOLATION, MATCH_MATCH, MATCH_TRAINING, VENUE_UNAVAILABLE, ACCESS_WINDOW_LOST, TEAM_LINK_OVERLAP,
  COMPETITION_INCOMPLETE, AWAY_NO_FOOTPRINT, FRIENDLY_ON_MATCH_SLOT). Aucune empreinte de conflit ne change
  (le rôle est hors identité). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun
  appel moteur, aucun payload solveur ne lit les adhésions joueur).
- **PR-2 « adversaire multi-gymnases », backend (2026-09-15)** : **+1 path** — les SUGGESTIONS partagées de
  gymnases par club adverse. `GET /api/opponents/{code}/venue-suggestions` (management, A6) rend les gymnases
  connus d'un adversaire — vus dans le calendrier fédéral (`FFBB_API`) ou choisis par des clubs (`MANUAL`) —
  avec un `chosenByCount` (« un compte, jamais un qui »), FFBB_API d'abord puis MANUAL par compte décroissant ;
  422 si le code n'est pas un adversaire AWAY de la saison. 198 → **199 paths**. Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun appel moteur, aucun payload solveur ne lit le
  partagé).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
