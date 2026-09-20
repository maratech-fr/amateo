Last verified @ 2026-09-20 (adversaire — préfiltre code postal : `GET /api/opponents/travel` sert
`postalCode` par club adverse, à côté de `city` ; régénéré par `api:openapi:export`).
**206 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** net (champ additif
`postalCode`).
· SHA-256 `d20cfda482eebe58e7b757716a69c70b817910c5bb9962c8b3c83fa187a3703d`
(`sha256sum`, confirmé sur le fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **Adversaire — préfiltre par code postal, backend (2026-09-20)** : **+0 path** — `GET
  /api/opponents/travel` gagne un champ ADDITIF `postalCode` (`string`|null) par club adverse, à côté de
  `city`, depuis `opponent_directory.postal_code` — le front préremplit la recherche de gymnase FFBB avec.
  Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
- **Adversaire multi-gymnases (amendement) — appariement club-scoped, backend (2026-09-20)** : **+1 path**
  net — le gymnase d'un adversaire se rattache au CLUB adverse et au LIBELLÉ de salle (`opponent_venue_link`,
  club-scoped sans saison), plus au trajet (une CONSTANTE servie depuis `club_travel_cache`). `GET
  /api/opponents/travel` change de FORME : groupé PAR CLUB adverse → `{code, name, city, precision, hasLogo,
  fixtureCount, venues:[{id, label, externalRef, source, travelMinutes, travelStatus, approximated,
  fixtureCount, fallbackVenueName}], unmatchedLabels:[{label, fixtureCount}]}` + `clubGeolocated` au sommet.
  Nouveaux gestes management : `POST /api/opponents/{code}/venues` (ajouter un gymnase), `POST
  /api/opponents/{code}/venue-links` (apparier un libellé orphelin), `PUT`/`DELETE
  /api/opponents/venue-links/{id}` (ré-apparier/fusionner, retirer). `POST /api/opponents/travel/{manual,auto}`
  SUPPRIMÉES (grain équipe disparu). `POST /api/opponents/travel/resolve` conservée (dispatche le calcul des
  paires manquantes). **`FixtureResource` gagne un champ additif `awayTravel`** (le trajet DÉRIVÉ de la
  rencontre extérieure : `{venueLabel, city, precision, oneWayMinutes, approximated, basis}` où `basis` =
  `linked`|`most_frequent`|`city`, null pour un domicile), calculé EN BATCH par le provider de collection
  (zéro N+1) — le chip de trajet du calendrier ne dépend plus de l'endpoint adversaires. Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, `matches[].roundTripMinutes` de forme identique,
  aucun appel moteur).
- **Sécurité H — dispatchers de trajets honnêtes si un calcul tourne déjà, backend (2026-09-19)** :
  **+0 path** — les trois routes qui dispatchent un calcul de trajets ne mentent plus quand le verrou
  `travel_compute:{clubId}` est tenu (un second message finirait en `failed`). Elles rendent alors
  `{queued:false, alreadyRunning:true}` sans rien dispatcher : champ ADDITIF `alreadyRunning` (booléen) sur
  `POST /api/opponents/travel/resolve`, `POST /api/venue-travel-times/autofill`, et sur le bloc `travel` de
  `POST /api/opponents/refresh` (dont les passes codes/gymnases restent jouées). Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
