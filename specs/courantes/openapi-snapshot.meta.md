Last verified @ 2026-09-21 (`e881d748` — « le conflit passerelle disparu quitte le contrat public et
l'empreinte » : `TEAM_LINK_OVERLAP` retiré de l'énumération `conflicts[].type`,
`SeasonAndFixturePaths` ; régénéré par `api:openapi:export`, aucune route touchée).
**207 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** (une valeur
d'enum retirée, aucune route ajoutée/supprimée) · **9 valeurs** dans l'énumération `conflicts[].type`
(`VENUE_OVERLAP`, `LEAGUE_WINDOW_VIOLATION`, `MATCH_MATCH`, `MATCH_TRAINING`, `VENUE_UNAVAILABLE`,
`ACCESS_WINDOW_LOST`, `COMPETITION_INCOMPLETE`, `AWAY_NO_FOOTPRINT`, `FRIENDLY_ON_MATCH_SLOT` —
zéro hit `TEAM_LINK_OVERLAP` dans le fichier ✓) · SHA-256
`59d2ec08af75fb0a5a22464aeee4cca4da38121d159c0e1829d288ab83b388a7` (`sha256sum`, confirmé sur le
fichier régénéré. Reste du journal non re-confronté au code cette passe.)

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **Le conflit passerelle disparu quitte le contrat public, backend (2026-09-21, `e881d748`)** :
  **+0 path** — la valeur `TEAM_LINK_OVERLAP` quitte l'énumération `conflicts[].type` (contributeur
  `SeasonAndFixturePaths`) : lot M a retiré la famille TEAM_LINK du détecteur de conflits
  (`MatchConflictDetector`/`ConflictRadarLoader` ne chargent plus les liens d'équipes), le contrat
  public l'annonçait encore alors qu'elle ne peut plus jamais être émise. L'inatteignabilité est
  démontrée, pas supposée : le détecteur n'émet plus que neuf types. `ConflictFingerprinter` perd sa
  branche `match` devenue morte. **9 valeurs restantes** dans l'énumération. Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
- **Lot L — « validé ligue » en lot, backend (2026-09-21, amendé par `d60b3fc0` le même jour)** :
  **+1 path** — nouvelle route `GET`/`POST /api/fixtures/league-validation` (management + saison
  écrivable + socle pointé). GET rend le compte des domiciles UNPLACED éligibles (heure + venueId
  identifié, sans écart en attente, aucune condition de date — délibérément AUCUN contrôle des
  créneaux d'accès match, divergence assumée avec le geste unitaire, signalée par le radar via
  `ACCESS_WINDOW_LOST`) ; POST les bascule en lot (statut VALIDATED + `placementSource` MANUAL —
  l'ancre que le cadenas de grille et le solveur de placement exigent). Idempotent (le prédicat
  exclut VALIDATED). Geste séparé du chemin de création de l'import (qui continue de créer en
  UNPLACED). **`d60b3fc0` (revue) élargit la SÉMANTIQUE du 409 des deux routes** (le nombre de
  routes ne bouge pas) : GET refuse désormais aussi quand AUCUNE saison ne se résout (défense en
  profondeur, ne dépend plus de la seule activation du filtre Doctrine) ; POST refuse en plus sur
  une collision d'écriture simultanée (verrou optimiste `Fixture`, message actionnable au lieu
  d'une 500). Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun
  appel moteur).
- **Adversaire — UX d'appariement des gymnases, backend (2026-09-21)** : **+0 path** — deux champs / un
  paramètre ADDITIFS. `GET /api/opponents/travel` sert `pairingKey` par adversaire : son code fédéral, ou une
  clé SENTINELLE (`X` + sha-256 du libellé) pour un adversaire SANS code, que le front repasse tel quel aux
  routes d'écriture `POST /api/opponents/{code}/venues` et `.../venue-links` — elles acceptent désormais la
  sentinelle, un sans-code étant apparié LOCALEMENT (ref neutralisée, jamais de crédit au partagé fédéral) ;
  la projection de trajet indexe par cette clé, ce qui débloque le trajet des rencontres sans code. `GET
  /api/ffbb/salles` gagne le paramètre de requête `q` (recherche de salle par NOM, plein-texte, ≥ 3
  caractères, alternative au `postalCode` — `postalCode` null dans la réponse). Backend PUR, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
