Last verified @ 2026-09-19 (retours de tests — une NOUVELLE route `PATCH /api/club/siege` (le serveur
re-géocode l'adresse via la BAN et écrit adresse/CP/ville + coordonnées depuis son hit, jamais le
client) et un champ ADDITIF `clubGeolocated` sur `GET /api/opponents/travel` ; plus trois ajouts
additifs des passes précédentes (`failedSteps`, `windows`, deux valeurs d'enum de résolution) ;
régénéré par `api:openapi:export`).
**201 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+1 path** :
`PATCH /api/club/siege` apparaît ; le reste est additif (`clubGeolocated`).
· SHA-256 `bfa260deaf86fc84fdb35700ef7d2a6281b7a45f6e7a880db643722ad4504e17`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **Retours de tests — siège du club géocodé côté serveur, backend (2026-09-19)** : **+1 path** —
  `PATCH /api/club/siege` (management) : le corps ne porte QUE du texte d'adresse ; le serveur
  RE-géocode via la BAN et écrit adresse/CP/ville + lat/lon depuis SON hit (réponse
  `{address, postalCode, city, geolocated}`) — une latitude forgée est ignorée (patron SEC-15) ; 422
  « adresse introuvable », 502 BAN muet. `GET /api/opponents/travel` gagne un booléen ADDITIF
  `clubGeolocated` (le siège est-il localisé ? — jamais les coordonnées brutes). 200 → **201 paths**.
  Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **Retours de tests — statuts « joue/coache » sur un conflit, backend (2026-09-19)** : **+0 path** — le
  statut de résolution d'un conflit gagne deux valeurs d'enum ADDITIVES `COACHES_NOT_PLAYING` et
  `PLAYS_NOT_COACHING` (`PUT /api/fixtures/conflicts/{fingerprint}/resolution`, requête + réponse, et le
  champ `resolution.status` du radar `GET /api/fixtures/conflicts`). Elles ne sont acceptées que si un
  côté servi du conflit porte le rôle PLAYER (sinon 422 parlant) ; colonne `length: 30`, aucune migration.
  Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **Retours de tests — `windows` sur ACCESS_WINDOW_LOST, backend (2026-09-19)** : **+0 path** — un conflit
  `ACCESS_WINDOW_LOST` du radar `GET /api/fixtures/conflicts` porte désormais un champ ADDITIF `windows`
  (`array<{dayOfWeek, startTime, endTime}>`) : les accès match DU GYMNASE de la fixture, jour du match
  d'abord, pour que l'écran dise « placé hors des accès match de {Gymnase} (samedi 14:00–18:00, …) ». Champ
  hors identité (l'empreinte reste `TYPE:fixtureId`). Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **Retours de tests — `failedSteps` sur la mise à jour des adversaires, backend (2026-09-19)** : **+0 path** —
  la réponse 200 de `POST /api/opponents/refresh` gagne un champ ADDITIF `failedSteps` (`array<'codes'|'auto-locate'|'travel'>`) :
  les passes best-effort qui ont levé et sont retombées sur leur résultat neutre. Vide en régime nominal ; non-vide,
  le front signale une mise à jour PARTIELLE (au lieu d'un succès mensonger) et invite à relancer. La forme des trois
  blocs (`codes`/`autoLocated`/`travel`) est inchangée. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **Audit 2026-09-18 — bornes des trajets adverses + longueurs de DTO, backend** : **+0 path** — trois
  ajustements sans nouvelle route : (SEC-19) `POST /api/opponents/travel/manual` déclare une réponse `429`
  (limiteur PAR UTILISATEUR `opponent_travel_manual`, 30/h) ; (BCK-32) la description de
  `POST /api/opponents/refresh` gagne la mention du budget de mur (au-delà, réponse PARTIELLE : adversaires
  restants en `unresolved`/`skipped`, relancer pour continuer) — la FORME de la réponse est inchangée ;
  (BCK-27) 26 propriétés texte des DTO d'entrée gagnent un `maxLength` égal à la longueur de leur colonne
  (`Fixture.opponentLabel`, `Club`/`Coach`/`Constraint`/`Venue`/`User`/`Season`/`Team`/… ) → un dépassement
  rend un 422 parlant au lieu d'un 500 SQL. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **Montée Dependabot — API Platform 4.4 / OpenAPI 3.2.0 (2026-09-17)** : **+0 path** — la montée
  `api-platform/*` 4.3.17 → 4.4.0 fait passer l'export de `openapi: 3.1.0` à `3.2.0`. La 3.2 autorise
  une `description` en frère d'un `$ref` (interdit en 3.1, API Platform la supprimait) : 3 propriétés
  typées par référence publient donc désormais leur docblock — `Schedule.capabilities` (→ `ScheduleCapabilities`),
  `ScheduleDiagnostic.causes` (→ liste de `DiagnosticCause`), `SchedulePlan.staleness` (→ `SchedulePlanStaleness`).
  Les docblocks de `capabilities` et `causes` ont été RÉÉCRITS dans la même passe (la référence interne
  part en commentaire `//`, la phrase publique reste — garde `PublicTextIsFreeOfInternalIdentifiersTest`).
  Aucune route, aucun schéma, aucune propriété ne change ; contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.21, aucun appel moteur).
- **VILLE de l'adversaire extérieur (au lieu du gymnase), backend (2026-09-17)** : **+0 path** — la
  description du champ `opponentPlace` (côtés `left`/`right` de MATCH_MATCH, `fixture` de MATCH_TRAINING sur
  le radar `GET /api/fixtures/conflicts`) est recalée : ligne d'override effective (équipe puis club) → VILLE
  de la salle CHOISIE (`opponent_venue_suggestion` par (code, venueExternalRef)) → VILLE de l'annuaire fédéral
  → null ; le libellé de gymnase d'override et le libellé FBI ne sont PLUS servis. Aucune forme de schéma ne
  change (seule la description). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21,
  aucun appel moteur). Le faux positif d'échauffement du même radar est corrigé dans la MÊME PR côté détecteur
  (`MatchConflictDetector`) — aucun impact OpenAPI, hors de ce snapshot.
- **« Détail par côté » d'un conflit de personne, backend (2026-09-17)** : **+0 path** — le radar
  `GET /api/fixtures/conflicts` gagne cinq champs ADDITIFS PAR CÔTÉ sur les familles PERSONNE (les côtés
  `left`/`right` de MATCH_MATCH, `fixture` de MATCH_TRAINING) pour rendre une ligne par côté : `estimatedKickoffTime`
  (heure estimée `HH:MM`, non-null seulement quand le coup d'envoi est estimé), `travelOneWayMinutes` (trajet aller
  simple ; null = non modélisé, toujours null en domicile), `matchDurationMinutes` (durée de match du côté),
  `opponentLabel` (le libellé adverse), et `opponentPlace` (où joue l'adversaire — décoré côté AWAY seulement :
  override manuel équipe > club > annuaire fédéral > libellé FBI > null). Aucune empreinte de conflit ne change
  (ces champs sont hors identité). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.21, aucun
  appel moteur, aucun payload solveur ne lit ces champs).
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
