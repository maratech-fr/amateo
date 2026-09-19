Last verified @ 2026-09-19 (C5 — champ ADDITIF `travelStatus` (`done`|`pending`|`unavailable`) par entrée
adversaire de `GET /api/opponents/travel` : le statut du trajet calculé SERVEUR ; régénéré par
`api:openapi:export`).
**204 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** (propriété ADDITIVE).
· SHA-256 `e1eb71cb966f9d76f7d50d553dff3514fea586b2ff28e090b2e83f0521fc32cc`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **C5 — `travelStatus` sur les trajets adverses, backend (2026-09-19)** : **+0 path** — chaque entrée
  adversaire de `GET /api/opponents/travel` gagne un champ ADDITIF `travelStatus` (`done`|`pending`|
  `unavailable`), statut du TRAJET calculé SERVEUR : `done` (minutes présentes), `pending` (un calcul est en
  cours pour ce club — clé Redis `travel_compute:{clubId}`, posée par le calcul asynchrone à venir),
  `unavailable` (tenté sans résultat, ou pas de lieu à router). En regard, la passe `resolve()` ne re-route
  plus QUE les trajets MANQUANTS (un trajet est une constante : jamais recalculé, jamais écrasé par un IGN
  muet). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
- **Registre « à corriger dans FBI », backend (2026-09-19)** : **+3 paths** — quand le gestionnaire garde
  l'appli sur un écart, FBI est en retard : `GET /api/fixtures/fbi-corrections` (lecture membre) sert les
  entrées OUVERTES du club+saison (`{id, fixtureId, field, appValue, fbiValue, venueFbiLabel, decidedAt,
  lastSeenInFbiAt}`) ; `POST …/{id}/close` (gestionnaire + saison écrivable) coche « corrigé dans FBI »
  (fermeture manuelle, 404 byte-identique cross-club) ; `POST …/{id}/reopen` annule un « corrigé » manuel de
  moins de 24 h (sinon 409). Deux champs ADDITIFS : `fbiEcho` (`{field, value, at}`|null) sur le schéma
  `Fixture` (mémo « FBI affiche … » d'un domicile rétrogradé « à saisir ») et `fbiTodo {toEnter, toCorrect}`
  sur `GET /api/matches/deadline-outlook` (le « à faire dans FBI » global, servi pour que le cockpit ne
  charge pas les fixtures). 201 → **204 paths**. Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.23, aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
