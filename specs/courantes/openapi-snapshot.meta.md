Last verified @ 2026-09-23 (retrait du cran `BONUS` du produit : l'énumération
`components.schemas.Constraint.ConstraintInput.properties.ruleType.enum` passe de
`["HARD","PREFERRED","BONUS","LOCK"]` à `["HARD","PREFERRED","LOCK"]` — `BONUS` n'avait de
sémantique propre nulle part (ni poids, ni branche moteur) et disparaît de la valeur d'enum ; une
écriture `ruleType: "BONUS"` est désormais refusée en 422 (Assert\Choice dérivé de `values()`) au
lieu d'être acceptée puis normalisée en silence. Régénéré par `api:openapi:export`, **aucune route
ajoutée/supprimée** (207, inchangé) et l'empreinte recalculée contre le fichier réel —
`OpenApiSnapshotMetaMatchesSnapshotTest` compare compte et empreinte aux mêmes deux fichiers et les
deux concordent. Reste du journal non re-confronté au code cette passe.)
**207 paths** (`grep -c '"/api/' specs/courantes/openapi-snapshot.json`) ✓, **+0 path** (une valeur
quitte une énumération, aucune route ajoutée/supprimée) · SHA-256
`f2e6becbb391dbdee6bb3e9eb241c7ef54bfe36eb4266423dff647c7239c15af` (`sha256sum`, confirmé sur le
fichier régénéré).

Changements récents (**les 8 dernières entrées seulement** — en ajouter une = supprimer la plus ancienne) :
- **Retrait du cran `BONUS` du produit (2026-09-23)** : **+0 path** — l'énumération
  `Constraint.ConstraintInput.ruleType` perd la valeur `BONUS` (`["HARD","PREFERRED","LOCK"]`
  restantes). Le cran n'avait jamais eu de sémantique propre (ni poids, ni branche moteur) : le
  moteur le normalisait en `PREFERRED` dès le parse et le wizard ne l'offrait plus ; zéro ligne en
  base. La valeur d'enum, la normalisation moteur et les libellés front partent ensemble. Une
  écriture `ruleType: "BONUS"` est refusée à la source en 422 (`Assert\Choice` dérivé de
  `values()`), plus jamais acceptée puis transformée en silence. Backend + moteur + front, contrat
  backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23 — `rule_type` reste une chaîne libre côté
  Pydantic, la forme du payload ne bouge pas).
- **Lot O — « validé ligue » piloté par l'échéance du championnat, backend (2026-09-21)** : **+0
  path** — la réponse du `GET /api/fixtures/league-validation` (`SeasonAndFixturePaths`) passe du
  `{count: int}` à une lecture détaillée `{matured, toTreat, missingDeadline, totalValidatable}`.
  Seuls les championnats DONT L'ÉCHÉANCE DE SAISIE EST PASSÉE (jour inclus) sont proposés — un
  championnat à échéance future (nouvelle vague d'octobre, dates provisoires) n'est plus jamais
  proposé, il resterait mobile. Chaque championnat échu porte nom/échéance/provenance/compte de
  validables ; les domiciles échus non validables sont NOMMÉS (`reason`
  `NO_KICKOFF`|`NO_VENUE`|`PENDING_DEVIATION`) ; les championnats sans échéance ayant des
  rencontres prêtes sont signalés. Le `POST` reste SANS corps (le serveur recalcule les échus au
  moment de l'application). La règle d'échéance effective (« club sinon communautaire ») est
  extraite en maison unique (`CompetitionDeadlineResolver`), consommée par ses trois appelants.
  Backend PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
- **Vocabulaire de traitement par famille + « erreur FBI » alimente le registre, backend
  (2026-09-21, `13810b0e`)** : **+0 path** — `resolution.status` (`GET`/`PUT
  /api/fixtures/{fingerprint}/conflict-resolution`, `SeasonAndFixturePaths`) passe de cinq à huit
  valeurs : `IMPORT_MISSING_MATCHES` (famille `COMPETITION_INCOMPLETE`) et `FBI_ERROR`/
  `MATCH_TO_MOVE` (famille `VENUE_OVERLAP`) rejoignent les trois statuts de base, chaque famille de
  conflit ayant désormais sa propre table de statuts autorisés (`ConflictResolutionStatus::
  casesForFamily`) — un statut hors de cette table est refusé en 422 côté serveur. Le corps du `PUT`
  gagne un champ ADDITIF nullable `fbiCorrection` (`{fixtureId, field: date|kickoff|venue}`),
  `FBI_ERROR` seulement : il ouvre, atomiquement avec la résolution, une entrée du registre « à
  corriger dans FBI » (`FbiCorrectionLedger`, maison unique déjà existante — troisième foyer
  d'appel, § `module-matchs.md` §1) pour le côté fautif du conflit et le champ concerné ; la valeur
  cible reste VIDE (l'app a importé l'erreur, elle ne la connaît pas), idempotent (un double appel
  ne duplique pas l'entrée, prouvé par un test). Description 422 étendue en conséquence. Backend
  PUR, contrat backend⇄engine **inchangé** (`CONTRACT_VERSION` 2.23, aucun appel moteur).
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
Règle (skill documentation-update) : régénérer ce snapshot à chaque changement d'API
(resource, controller custom, DTO exposé) et bumper ce stamp. **Le compte et l'empreinte annoncés
en tête ne sont plus une promesse sur l'honneur** : `OpenApiSnapshotMetaMatchesSnapshotTest`
(`backend/tests/Unit/Documentation/`) les recalcule contre le snapshot réel à chaque run et rougit
si l'un des deux ment — non bloquant, `phase1`, `unit-tests` seul ; le bumper à la main reste
nécessaire (le test ne régénère rien, il compare). Une route custom n'apparaît
dans l'export que si elle est déclarée dans le `CustomPathContributor` de son domaine
(`backend/src/OpenApi/PathContributor/`), composé par `CustomRoutesOpenApiFactory` — depuis
P4-138 (2026-08-30), **ajouter une entrée directement à la factory ne fait plus rien** : elle
ne fait que composer les contributeurs dans un ordre significatif (`backend/docs/backend-inventory.md`
§OpenAPI). Le journal ci-dessus est BORNÉ à 8 entrées (audit DOC-34, 2026-08-27) : chaque ajout
retire la plus ancienne — l'historique vit dans git, jamais ici.
