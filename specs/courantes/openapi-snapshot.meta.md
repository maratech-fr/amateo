Last verified @ 2026-09-19 (C7 — `logo_id` sur l'annuaire fédéral : route MEMBRE
`GET /api/opponents/{code}/logo` + booléen `hasLogo` sur `GET /api/opponents/travel` ; régénéré par
`api:openapi:export`).
**205 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+1 path** (la route logo adversaire).
· SHA-256 `67f6a2586a1685134028b24c4260e148c63b45e0196d7a7665f730401c705ee9`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **C7 — logo fédéral de l'adversaire, backend (2026-09-19)** : **+1 path** — nouvelle route MEMBRE
  `GET /api/opponents/{code}/logo` (jamais publique : le logo d'un adversaire de club est une donnée du
  module matchs) qui re-héberge PARESSEUSEMENT le logo fédéral au premier GET (uuid `logo_id` que le
  résolveur a enregistré depuis les hits organismes qu'il tient déjà, zéro appel réseau de plus ; 404 sans
  logo, `Cache-Control: private, max-age=86400`). `GET /api/opponents/travel` gagne un booléen ADDITIF
  `hasLogo` par entrée (jamais l'uuid brut). Colonne `opponent_directory.logo_id` (table GLOBALE partagée,
  whitelist `OpponentDirectoryShareTest` +1). Backend PUR, contrat backend⇄engine **inchangé**
  (`CONTRACT_VERSION` 2.23, aucun appel moteur).
- **C6 — calcul des trajets ASYNCHRONE, backend (2026-09-19)** : **+0 path** — le calcul des trajets
  quitte le rail synchrone (rafale IGN pacée > plafond HTTP). `POST /api/opponents/travel/resolve` et
  `POST /api/venue-travel-times/autofill` rendent désormais `{queued: true}` (au lieu du résultat
  synchrone) ; la passe (c) « travel » de `POST /api/opponents/refresh` rend `{queued, pending}` (au lieu
  de `{resolved, unresolved, skippedManual}`). Le cap dur reste vérifié SYNCHRONEMENT (422). `GET
  /api/mercure/auth` gagne un champ ADDITIF `travelTopic` (`club:{clubId}:travel`, topic FIXE joint au
  claim `subscribe`) : la progression et le verdict (`{filled, unresolved}` pour la matrice) sont poussés
  par Mercure sur ce topic. Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23,
  aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
