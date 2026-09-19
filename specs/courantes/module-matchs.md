# Module matchs (FFBB) — état courant

Last verified @ 2026-09-19 (`documentation-update`, PR G « todo FBI unique »). Confronté au code
cette passe : `FbiCorrection`/`FbiCorrectionLedger`/`FbiCorrectionController` (registre « à
corriger dans FBI », §1) ; `Fixture::setStatus`/`fbiEcho` (mémo « FBI affiche … », §1) ;
`EntryDeadlineOutlook::compute` (`fbiTodo` global, §4) ; `FbiEntryList`/`WeekCounters`/
`CalendarPage` (écran « FBI — à faire », deep-link `?fbi=1`, §5) ; `FbiDeadlineCard` (carte cockpit
rendue aussi hors fenêtre J-7 quand du FBI reste à faire, §4). Reste confronté à la passe
précédente (2026-09-19, PR F) : `OpponentDirectoryEntryRepository::upsert` (upsert
natif `ON CONFLICT`, §1) ; `OpponentRefreshController::step`/`failedSteps` (§1) ;
`OpponentTravelResolver::resolve` (re-route d'une ligne MANUAL sans trajet depuis ses coordonnées
épinglées, §1) ; `ConflictResolutionStatus` (`COACHES_NOT_PLAYING`/`PLAYS_NOT_COACHING`, §6) ;
`MatchConflictDetector::accessWindowLostConflicts` (champ additif `windows`, §2). Reste confronté à
la passe d'avant (2026-09-18, D2/D3/FRT-32) : `FixtureStateProcessor::assertVenueAccessAllowed`
(D2, §5) ; `MatchConflictDetector::kickoffInsideLeagueWindow` +
`matches/lib/envelope.ts::kickoffInsideLeagueWindow` (FRT-32, miroir déclaré, §5) ;
`OpponentTravelProjection::roundTripByFixtureId` + `matches[].roundTripMinutes` (D3, §3). Reste
confronté à la refonte précédente : composants `frontend/src/features/matches/` (routes,
`CalendarPage`/`ConflictsPage`/`ImportPage`/`ConfigurationPage`/`TypicalWeekPage`) ·
`MatchConflictDetector` (D1/D1 étendu, échelle de sévérité) · `ConflictRadarLoader` (chargement
unique GET conflicts ⇄ delta de visite) · `FbiFixtureImporter`/`FfbbRencontreReconciler` (workflow
NEW/OUT_OF_SYNC/REVIEWED, moteur partagé) · `VenueAliasResolver`/`OpponentLocationResolver`/
`OpponentTravelResolver` (tables partagées et tenant) · `OpponentPlaceResolver` (détail par côté) ·
`engine/app/solver/match_placement.py` (HARD/SOFT, `W_PROTECT_HABIT=25`) ·
`engine/CONTRACT_VERSION` = **2.23**.

> **Règle de forme (refonte 2026-09-18, AUD-DOC-38)** : ce fichier décrit **l'état courant, par
> écran** — jamais une section datée d'une PR. Le JOURNAL (qui a livré quoi, quand, sous quel id)
> vit dans [`etat-des-lieux.md`](etat-des-lieux.md) §1.5 (carte) et §3 (traces datées) ; les
> **décisions fermées** (tranchées contre une option évidente) vivent dans son §2 — ce fichier n'en
> reproduit que le résultat, jamais le débat. But de taille : décrire l'état courant sans historiser
> — si une phrase commence par une date ou un id de PR, elle appartient à l'état des lieux, pas ici.

Le module vit dans `frontend/src/features/matches/` (nav `MatchesLayout`, 6 routes sous `/matchs` :
`index` = Calendrier, `conflits`, `importer`, `configuration`, `semaine-type`, `consulter` en
redirection permanente vers l'index, `reconciliation` accessible seulement depuis le canal API,
`frontend/src/app/routes.tsx:141-186`) et dans les services backend `Match*`/`Fixture*`/
`Opponent*`/`Ffbb*` (`backend/src/Service/`, `backend/src/Entity/`).

## 0. Portée et gating

Le module est autonome dans ses **données** (entités season-scoped propres), pas dans son
**ouverture** : créer un match (`FixtureStateProcessor`) ou importer un fichier FBI
(`ImportFixturesController`) appellent `App\Service\SocleGuard::assertSeasonPlanChosen`, qui rend
**409** tant que le plan SEASON ne pointe aucune version (ADR-0002 inv. 13 — voir
[`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) §0). Le front verrouille
l'entrée « matchs » sur le même critère (`chosenScheduleId`). Motif : un match se *place* dans un
calendrier, le radar de conflits compare la rencontre aux séances d'entraînement — sans socle en
vigueur, il n'a rien à comparer.

## 1. Modèle & données transverses

### Entités season-scoped (tenant, RLS)

- **`Competition`** (`teamId`, `name` = code/division FBI, `competitionType`
  `CHAMPIONSHIP`/`CUP`/`BRASSAGE`, `entryDeadline` nullable, réfs FFBB — voir §9) — N par équipe.
- **`Fixture`** (table `fixture`) : `teamId`, `competitionId` nullable = amical, `matchDate`,
  `homeAway`, `opponentLabel`, `opponentOrganismeCode`/`opponentTeamKey` (résolus serveur),
  `status` (`FixtureStatus` : `UNPLACED → PLACED → SUBMITTED → VALIDATED`), `venueId`/`kickoffTime`
  nullables, `fbiVenueLabel` (salle brute source, HOME et AWAY), `placementSource`
  (`MANUAL`/`SOLVER`/null legacy), `unplacedReason` (raison persistante, ex. `venue_lost`),
  `keptVenueLabel` (idempotence d'arbitrage salle), `reviewState`/`reviewedAt`/`pendingDeviations`
  (workflow de traitement, §5). L'import FBI crée **tout** en `UNPLACED`, domicile et extérieur —
  seul un geste du gestionnaire pose un autre statut (`FixtureStatus` docblock).
- **`TeamMatchHabit`** : jour ISO + heure-point + gymnase optionnel, une par jour et par équipe.
- **`TeamLink`** (couple symétrique `teamAId < teamBId`, cap `MAX_TEAM_LINKS = 50`) : côté MATCHS
  `TeamLinkType` `NOT_SIMULTANEOUS`/`BACK_TO_BACK` (rail SOFT radar/placement) ; côté ENTRAÎNEMENT
  `TeamLinkIntensity` `PREFERRED`/`MANDATORY` (honoré par le solveur d'entraînement — arbitrage :
  cette intensité ne gouverne jamais les matchs, `engine/docs/constraint-vocabulary.md` §Passerelles).
- **`MatchSlotRotation`** + **`MatchSlotRotationTeam`** (membres ORDONNÉS, `position` purement
  FICTIF — aucun ancrage calendaire) : un créneau physique (gymnase **NOT NULL** + jour + heure,
  unique) partagé par N équipes en alternance A/B/C (cas SM1/SM2 : pénurie de créneaux). Une
  rotation tombée sous 2 membres est supprimée, un gymnase supprimé emporte la rotation entière.
  L'image A/B (habitudes ∪ rotations) alimente aussi le solveur d'ENTRAÎNEMENT : `Team.matchDay`
  (`ScheduleConstraintBuilder::deriveMatchDay`, `POST /generate`) émet le DERNIER jour ISO de match
  de la semaine — le repos qui compte est celui d'après lui (`rest_day = match_day % 7 + 1`,
  `engine/app/solver/objective/terms.py`) — pour le bonus SOFT « jour de repos après un match ».
  Sans image (ni habitude ni rotation), repli sur le champ déclaré `Team.matchDay` (0-based,
  converti en ISO à l'émission).
- **`VenueMatchWindow`** (jour ISO + plage horaire, gymnase = « de match » ssi ≥ 1 fenêtre — aucun
  booléen sur `Venue`) et **`VenueUnavailability`** (plage de dates + motif, toutes circonstances,
  alerte seulement — jamais recopiée en N+1).
- **`ConflictResolution`** (clé `(club, saison, fingerprint)`, l'empreinte STABLE d'un conflit) :
  statut de traitement — voir §6.
- **`MatchModuleVisit`** (clé `(club, saison, user)`) : référence de visite pour le delta — voir §8.
- **`FbiIngestion`** (`club+saison`, non personnelle) : fraîcheur + compteurs d'un dépôt, une par
  canal (`FBI_XLSX`/`FFBB_API`).
- `Venue.externalLabels` (JSON normalisé/dédupliqué) : alias FBI/FFBB confirmés — voir §5.3.

Recopie en N+1 (`SeasonTransitionService`) : habitudes, passerelles, fenêtres d'accès, rotations
(remap équipe+gymnase) ; les indisponibilités et les échéances **ne sont jamais recopiées**.

### Tables GLOBALES fédérales (hors tenant, hors RLS)

Patron commun : keyées sur un identifiant fédéral public, **aucune colonne club/user-identifiante**,
GRANT `SELECT/INSERT/UPDATE` **sans `DELETE`** (une ligne retombée à 0 reste). Gardées par un test
de schéma dédié par table (`*ShareTest`, liste blanche exacte + byte-identique quel que soit le
club lecteur).

- **`LeagueMatchWindow`** : fenêtres de coup d'envoi imposées par la fédé, par `league × category ×
  level × gender` — seedée depuis `backend/data/league-match-windows.aura.json`, ligue dérivée du
  `ffbbClubCode` (`LeagueResolver`). `GET /api/league-match-windows` sert aussi
  `resolvedTeamWindows` (la jointure équipe→fenêtres, même moteur que le solveur et le diagnostic).
- **`OpponentDirectoryEntry`** : où joue un adversaire (`name`/`city`/`postalCode`/`lat`/`lng`/
  `precision` `VENUE`|`CITY`), résolu **automatiquement** par `OpponentLocationResolver` — salle
  exacte du hit rencontre API (`VENUE`, gratuit) sinon repli VILLE (`CITY`, géocodage). 🔴
  **`VENUE` est réservé au canal API** — un libellé xlsx est fourni par le club, l'accepter comme
  `VENUE` laisserait un club épingler un adversaire réel à un faux gymnase, permanent et lu par
  tous ; le canal xlsx plafonne à `CITY` (gardé par `OpponentDirectoryShareTest` +
  `OpponentLocationResolverTest`).
- **`OpponentVenueSuggestion`** : les gymnases connus d'un adversaire, un COMPTE de choix par
  source — `FFBB_API` (observé, jamais un choix, compte intact) ou `MANUAL` (choisi par un club via
  `/api/ffbb/salles`, `numero` fédéral **re-résolu serveur** — les coordonnées du corps client ne
  sont qu'une graine de recherche `_geoRadius`, jamais écrites telles quelles). Décision : « un
  COMPTE, jamais un QUI » — aucune colonne club/user/provenance, une ligne AUTO (posée depuis le
  libellé du fichier, §5.2) ne compte jamais. `GET /api/opponents/{code}/venue-suggestions` sert
  `chosenByCount` (des CHOIX, pas des clubs distincts) et `lastChosenAt` au JOUR seul.
- **`SharedCompetitionDeadline`** : le défaut communautaire d'échéance de saisie, keyé
  `ffbbCompetitionId` — voir §9.

### Table TENANT `OpponentTravel`

Le trajet aller simple (voiture, `IgnRoutingClient` depuis `Club.latitude/longitude`) vers le
gymnase d'un adversaire, résolu par `OpponentTravelResolver` (MANUAL jamais écrasé par AUTO). Grain
**ÉQUIPE** : `opponent_team_key` (libellé de rencontre normalisé) surcharge la ligne CLUB (défaut
historique) ; unicité `(club, saison, code, teamKey)` en `NULLS NOT DISTINCT`. Résolution en
cascade **équipe → club → annuaire global** (`OpponentTravelRepository::findEffective`). Une ligne
équipe naît toujours d'un choix manuel ; « rétablir l'automatique » sur une équipe **supprime** la
ligne (jamais un retour AUTO) — sur la ligne club, c'est un vrai recalcul AUTO. `GET
/api/opponents/travel` groupe les AWAY par `(code, libellé normalisé)`. L'écran (`OpponentTravelCard`,
`LocateOpponentModal`) est groupé par club, une ligne « toutes les équipes (défaut) » puis une par
équipe, chip précision/source, recherche instantanée insensible aux accents, orphelins (sans code
fédéral) repliés à part. **Auto-localisation depuis le libellé du fichier** (`OpponentVenueAutoLocator`,
égalité STRICTE nom du fichier ↔ salle fédérale candidate — 0/≥2 hits ou 2 libellés divergents
n'écrivent rien) pose une ligne équipe `AUTO` sans saisie ; n'écrit **jamais** le partagé (une
localisation devinée n'est pas un choix). **Bouton unique « Mettre à jour les adversaires »** →
`POST /api/opponents/refresh` (`OpponentRefreshController`, cap `MAX_DISTINCT = 200` avant tout
réseau, limiteur `opponent_refresh` 10/h) enchaîne trois passes best-effort indépendantes :
rattrapage des codes fédéraux, auto-localisation, recalcul des trajets AUTO. Budget global
`REFRESH_BUDGET_SECONDS = 45` threadé aux trois passes (au-delà : réponse partielle
`unresolved`/`skipped`). Best-effort intégral à chaque étage — une panne réseau rend « non localisé »,
jamais un import bloqué. L'écriture de l'annuaire (`OpponentDirectoryEntryRepository::upsert`) est un
`INSERT … ON CONFLICT (ffbb_organisme_code) DO UPDATE` natif (retours de tests 2026-09-19) : deux noms
d'observation différents qui résolvent le MÊME code fédéral dans un seul lot fusionnent en une ligne au
lieu d'un double `persist` applicatif qui violait l'unicité et **fermait l'`EntityManager`** (les
passes/imports suivants du même appel se retrouvaient silencieusement avalés, réponse 200 vide). Chaque
passe qui lève est isolée (`OpponentRefreshController::step`) et s'inscrit dans un champ additif
`failedSteps` — le front dit franchement « mise à jour interrompue à l'étape … » plutôt qu'un succès
mensonger ; volontairement **pas** de `resetManager()` (dette assumée : `roadmap.md` P4-247). Une ligne
`opponent_travel` **MANUAL sans trajet** (IGN muet au moment du choix) mais dont l'override porte des
coordonnées épinglées est re-routée par la passe AUTO (le trajet seul change, le gymnase choisi reste
souverain).

**Prérequis du trajet AUTO — le siège du club doit être localisé.** Sans coordonnées sur `Club`, aucun
trajet ne se calcule (`OpponentTravelResolver::resolve` rend tout en `unresolved`). Le siège se pose
désormais depuis la fiche club (`PATCH /api/club/siege`, hors module matchs — voir
`backend/docs/geo-api.md` §1 et `frontend/docs/frontend-spec.md`) ; `GET /api/opponents/travel`
sert un champ additif `clubGeolocated` (booléen) que l'écran (`OpponentTravelCard`, Configuration ›
Adversaires) lit via `useClubGeolocated()` pour afficher un bandeau « Trajets indisponibles : l'adresse
du siège du club n'est pas localisée. » avec un lien direct vers `/club?section=informations` — «
Mettre à jour les adversaires » reste utilisable (il localise quand même les gymnases adverses).

### Alias de gymnase (`Venue.externalLabels`, `VenueAliasResolver`)

Un domicile importé porte un libellé de salle fédéral (`fbiVenueLabel`) mais peut n'avoir aucun
`venueId` — invisible des familles `VENUE_OVERLAP`/`VENUE_UNAVAILABLE`. `VenueAliasResolver::
resolveConfirmed` (égalité STRICTE normalisée sur un alias confirmé) pose `venueId` automatiquement
aux DEUX canaux d'intégration (xlsx `FbiFixtureImporter::attachConfirmedVenue`, API
`FfbbRencontreReconciler::apply` — jamais un statut ni une date, jamais sur un AWAY) ;
`::suggest` (fuzzy, ambiguïté ≥ 2 candidats → `null`) nourrit `FixtureResource.suggestedVenueId`
en lecture seule. Normalisation : foyer unique `VenueLabelNormalizer` (translit ASCII, minuscule,
non-alphanumérique → espace). Routes (`VenueExternalLabelController`) : `GET
/api/venues/fbi-labels` (inventaire agrégé par libellé, tout membre) ; `POST
…/{id}/external-labels {label}` (ajoute l'alias + backfille les domiciles du club encore sans
salle) ; `POST …{label, reassign: true}` (réaffecte l'alias vers un AUTRE gymnase, re-pointe les
non placés, **épargne toujours les placés**) ; `DELETE …` (retire l'alias, **ne dépointe jamais**
une rencontre déjà rattachée). Écran d'appariement : §5.3.

### Écart de salle d'un domicile NON PLACÉ — jamais réparé en silence

Un domicile `UNPLACED` déjà rattaché à un gymnase (alias confirmé) dont un dépôt suivant nomme une
AUTRE salle n'est **jamais** réécrit en silence — décision fermée (`etat-des-lieux.md` §2) : l'appli
peut avoir raison, seul un arbitrage tranche. `FbiFixtureImporter::detectUnplacedVenueDeviation`
détecte (scope `['venue']` du moteur partagé `processPerimeterFields`, jamais « FBI fait foi » sur
ce champ) ; `Fixture.keptVenueLabel` mémorise le libellé « gardé » (idempotence — tant que la
source répète ce libellé, la question ne revient pas) ; « Prendre le fichier » suit l'alias
confirmé du nouveau libellé, sinon vide `venueId` (« à rattacher »). Le geste vit dans la file de
traitement de l'onglet Importer (§4), pas dans le rapport de dépôt.

### Registre « à corriger dans FBI » (`FbiCorrection`, `FbiCorrectionLedger`)

Angle INVERSE de l'écart de réconciliation (ci-dessus) : quand le gestionnaire tranche « garder
l'appli » sur un champ divergent (date/heure/salle), c'est **FBI qui est en retard** — il faut le
corriger à la main dans le portail fédéral. `FbiCorrection` (table tenant, RLS, une entrée par
`(club, saison, rencontre, champ)`, unicité PARTIELLE sur les ouvertes seulement) dit ce qu'il faut
**taper** dans FBI (`appValue`) en face de ce que FBI **affiche encore** (`fbiValue`), avec l'alias
FBI confirmé du gymnase de l'appli quand connu (`venueFbiLabel`, premier `Venue.externalLabels`).
Maison unique : `FbiCorrectionLedger` (`open`/`refreshSeen`/`closeBySource`/`closeManually`),
injectée dans les DEUX foyers d'arbitrage — le moteur de réconciliation partagé
(`FbiFixtureImporter`, canaux xlsx **et** API) et l'arbitrage hors dépôt
(`ReviewFixtureDeviationController`). Un « garder l'appli » ouvre (ou re-date) une entrée OUVERTE ;
« prendre le fichier » n'en ouvre **jamais** (l'appli s'aligne sur FBI, rien à reporter). Cycle
d'une entrée : un dépôt qui montre toujours l'ancienne valeur ne recrée pas d'écart, il re-date
« vu dans FBI » ; un dépôt qui montre la valeur appli la ferme (`closed_by=deposit`) ; un dépôt qui
montre une TROISIÈME valeur la ferme ET rouvre un écart normal à arbitrer ; le gestionnaire peut
aussi la cocher « Corrigé dans FBI » (`closed_by=manual`, réouvrable **< 24 h**, `POST
…/{id}/reopen`, 409 au-delà ou sur une fermeture non manuelle). Une entrée FERMÉE reste en base
(trace), jamais rendue par la liste — purgée avec la saison (`SeasonDataPurger::
PURGED_BY_CLUB_SEASON`). **Registre à ZÉRO au départ** : les « garder l'appli » passés n'ont laissé
aucune trace exploitable, il ne se peuple que par les arbitrages à VENIR (décision fermée,
`etat-des-lieux.md` §2). API : `GET /api/fixtures/fbi-corrections` (membre, entrées ouvertes du
club+saison) ; `POST …/{id}/close` (gestionnaire + saison écrivable) ; `POST …/{id}/reopen`.

`Fixture.fbiEcho` (colonne JSON nullable) : mémo `{field, value, at}` posé quand un « prendre le
fichier » sur l'HEURE rétrograde un domicile `SUBMITTED`/`VALIDATED` à `PLACED` (la coche FBI
portait une mauvaise heure) — dit ce que FBI affiche encore sur la ligne « à saisir » de la liste
(§5). Effacé dès que le statut repasse `SUBMITTED`/`VALIDATED` (`Fixture::setStatus`, maison
unique).

## 2. Détecteur de conflits (`MatchConflictDetector`, service pur)

Recalculé **à la volée** à chaque appel (`GET /api/fixtures/conflicts`, `FixtureConflictsController`,
`priority: 10`) — rien n'est persisté. Le chargement (fixtures, coachs/joueurs, périodes-overlay,
slots effectifs, profils de durée, trajet) vit dans la maison UNIQUE `App\Service\
ConflictRadarLoader::conflicts`, consommée par ce contrôleur ET par `MatchModuleDeltaComputer` (§8)
— une seule copie du chargement.

**Empreinte-temps** (`MatchFootprint`, service pur) : fenêtre d'occupation PERSONNE
(`occupancy`/`occupancyAt` = échauffement + match + trajet aller-retour) vs fenêtre SALLE seule
(`venueOccupancy`/`venueOccupancyAt` = `[kickoff, kickoff+matchMinutes]`, sans échauffement ni
trajet — sert `VENUE_OVERLAP` et `MATCH_SLOT_WINDOW`). Durées par équipe résolues par
`MatchDurationResolver` : override club par catégorie (`sport_category.match_minutes`/
`warmup_minutes`) sinon défaut de famille (U7-U11 75/30 · U13-U15 90/30 · U18-U21 105/30 · repli
105/30) — le solveur de placement (§3) partage la même géométrie depuis P4-203.

**Personne** : carte teamId → personId unionnant coachs (`TeamCoach`) ET joueurs actifs
(`CoachPlayerMembership`) — `ConflictPersonRole` MAIN/ASSISTANT/PLAYER, le rôle coach l'emportant
sur PLAYER pour la même équipe. Chaque côté d'un conflit porte son rôle PAR CÔTÉ
(`left.role`/`right.role`, `fixture.role`/`training.role`) ; un `coachRole` agrégé reste servi pour
compat, plus utilisé à l'affichage.

**Deux familles** :
- `MATCH_MATCH` — deux fixtures d'équipes partageant une personne, fenêtres qui se chevauchent.
  Gravité `pairSeverity` : dur (MAIN×MAIN, MAIN×PLAYER, PLAYER×PLAYER) = 3 ; un côté ASSISTANT = 5.
- `MATCH_TRAINING` — une fixture chevauchant un entraînement (lu dans le planning **effectif à la
  date du match** : overlay ACTIVE sinon version choisie du plan SEASON, `EffectiveScheduleResolver`).
  Gravité `trainingSeverity` (asymétrique) : match joué × entraînement joué/coaché MAIN = 3 ; match
  coaché MAIN × entraînement où elle ne fait que jouer = 5 ; tout côté ASSISTANT = 5. L'entraînement
  de l'équipe qui joue CE match n'est **jamais** un conflit avec lui (ni coachs ni joueurs), quel
  que soit le gymnase — une équipe sœur reste couverte normalement.

**Chevauchement demi-ouvert** (créneaux jointifs = pas de conflit). Une empreinte qui passe minuit
est vérifiée sur les deux jours. Résolution déterministe par la période la plus ÉTROITE en cas de
chevauchement de périodes.

**D1 (échauffement salle seule) + D1 étendu (« déjà sur place »)** : la fenêtre SALLE (sans
échauffement) sert `VENUE_OVERLAP`/`MATCH_SLOT_WINDOW` — deux matchs enchaînés à 2 h d'écart ne
collisionnent plus. Étendu : deux fixtures `MATCH_MATCH` **HOME** dans le **même** gymnase → le
côté au coup d'envoi le plus tardif perd son échauffement (`effectiveMatchWindows`/`sameHomeVenue`),
la personne étant déjà sur place ; un vrai recouvrement de jeu reste un conflit. Étendu à
`MATCH_TRAINING` sur le même principe. Inchangé si gymnases différents, gymnase inconnu, un côté
AWAY, coups d'envoi égaux.

**Amicaux** (`competitionId` null) : jamais comparés aux fenêtres ligue, jamais soumis aux
fenêtres/week-ends de match (ni solveur ni garde de placement manuel — juste un avertissement) ;
seule famille qui les concerne : `FRIENDLY_ON_MATCH_SLOT` (§ échelle ci-dessous). Une rencontre de
coupe porte une vraie `Competition` `CUP` (résolue au canal API/à l'appariement) et redevient un
match à part entière.

**Passé muet** : `detect()` reçoit la date civile du jour côté club (`ClubDay::todayFor`) et exclut
toute rencontre `matchDate` strictement passée de **toutes** les familles sauf
`COMPETITION_INCOMPLETE` et l'index des week-ends de match. Un match déjà joué ne porte ni ne reçoit
plus aucun conflit.

**Échelle de sévérité (1..7, émise par le serveur)** : 1 `VENUE_OVERLAP` · 2
`LEAGUE_WINDOW_VIOLATION` (équipe mappée seulement) · 3 clash dur `MATCH_MATCH`/`MATCH_TRAINING` ·
4 `VENUE_UNAVAILABLE` + `ACCESS_WINDOW_LOST` (« Hors accès match » — champ additif `windows`, les
accès du gymnase de la fixture triés jour du match d'abord, hors identité de l'empreinte
`TYPE:fixtureId` ; l'écran nomme le gymnase et ses fenêtres, « aucun accès match ce jour-là » sans
aucune) · 5 clash adouci + `TEAM_LINK_OVERLAP` +
`FRIENDLY_ON_MATCH_SLOT` (amical HOME placé sur un créneau de match — `reasons`:
`MATCH_SLOT_WINDOW`/`MATCH_WEEKEND`, samedi = clé du week-end, le vendredi ne compte jamais) · 6
`COMPETITION_INCOMPLETE` (compétitions APPARIÉES sous leur attendu, `expectedMatchdays` — jamais
pour une `CUP`) · 7 `AWAY_NO_FOOTPRINT` (angle mort nommé : extérieur sans heure ni habitude du bon
jour). Réponse : bornes datées en heure MURALE du club (jamais un offset).

**Détail par côté (`MATCH_MATCH`/`MATCH_TRAINING` seulement)** : `MatchConflictDetector::fixtureView`
sert quatre champs additifs par côté (`estimatedKickoffTime`, `travelOneWayMinutes` — `null` =
trajet non modélisé, `matchDurationMinutes`, `opponentLabel`) ; `opponentPlace` (ville de
l'adversaire, jamais un gymnase) est décoré EN AVAL par `FixtureConflictsController::
decorateOpponentPlace` sur les côtés AWAY seulement, via `OpponentPlaceResolver` (batch) : (1)
override effectif équipe/club portant une réf FFBB de salle → ville de la suggestion fédérale
correspondante ; (2) sinon la ville de l'annuaire fédéral global ; (3) `null`. Le front (`conflictSideLines.ts`,
`ConflictLine.tsx`) rend un vrai TABLEAU (`Table variant="inline"` — primitive partagée
`shared/components/ui/table.tsx`, `frontend/AGENTS.md` §Primitives) à quatre colonnes horaires
FIXES — Départ · Coup d'envoi (toujours colonne 2, quelle que soit la nature du côté) · Fin/retour ·
Durée (repliée sous 360 px par container query, la largeur du RADAR pas du viewport) — une ligne
par côté puis une ligne de chevauchement ; un créneau absent rend « — », jamais une cellule vide
muette. Présentation pure — aucune formule de gravité redérivée.

## 3. Solveur de placement (`POST /api/fixtures/place` → engine `/place-matches`)

Second problème solveur ([ADR-0003](../../docs/architecture/adr-0003-match-placement-solve.md)),
même `CONTRACT_VERSION` **2.23** que `/generate`/`/validate-assignments` (un seul contrat pour les
trois endpoints — voir §6 `CLAUDE.md`). **Rail
SYNCHRONE** (`PlaceMatchesController` — management + saison écrivable + socle pointé), anti-double-clic
`MatchPlacementLock` (Redis dédié). Best-effort à poids dominant : `10 000 × Σ placés + SOFT` —
**aucune contrainte HARD n'est jamais violée en sortie** ; un match sans candidat licite sort NOMMÉ
(`no_access_window` · `no_league_intersection` · `venue_unavailable` · `venue_full`).

**HARD** : fenêtres d'accès match (le match SEUL dedans, D1 — `kickoff ≥ start`,
`kickoff+matchMinutes ≤ end`), indisponibilités gymnase, no-overlap `(gymnase, date)` sur la fenêtre
MATCH, fenêtre ligue quand l'enveloppe est résolue (non résolue = diagnostic INFO seul). Durées par
équipe (`MatchDurationResolver`) portées par le contrat ; absentes côté engine ⇒ défauts Pydantic
105/30. **Trajet adversaire (D3, contrat 2.23)** : une ligne AWAY porte `roundTripMinutes` (2 ×
aller simple, projeté par la maison unique `App\Service\OpponentTravelProjection`, partagée avec
le radar §2) ; le solveur étend la fenêtre de blocage du coach de ce trajet — moitié avant
l'échauffement, moitié après le match, réplique exacte de `MatchFootprint` — pour le protéger
pendant son déplacement. Absent/0 (adversaire sans trajet connu) ⇒ aucune extension. **SOFT
(golden-épinglés)** : conflit coach MAIN −60 · passerelle `NOT_SIMULTANEOUS` violée
−40 · habitude heure +15/gymnase +5 · fenêtre habituelle protégée −25 · `BACK_TO_BACK` enchaîné +15
· coach ASSISTANT −10 · stabilité re-solve +8 · compactage −1/15 min de trou. La rotation A/B
(`slotRotations`, §1) ajoute une attraction équivalente (`W_ROTATION_TIME=15`/`W_ROTATION_VENUE=5`)
et une protection de fenêtre (`W_PROTECT_HABIT=25`) — une équipe ne porte jamais habitude ET
rotation le même jour (suppléance côté backend), les deux bonus ne s'additionnent donc jamais.

**Ancres — `Fixture.placementSource`** : geste manuel API → `MANUAL` ; `MANUAL` + `SUBMITTED`/
`VALIDATED` = **FIXED**, ne bouge jamais ; `SOLVER` = re-plaçable. Un amical n'est **jamais**
proposé au solveur (« un amical n'est pas sur un créneau de match ») : placé+ancré → FIXED (gymnase
protégé), sinon simplement absent du payload — le contrat n'a pas bougé pour cette raison, la place
laissée libre est couverte par `FRIENDLY_ON_MATCH_SLOT` (§2), pas par une contrainte moteur.

Le backend PROJETTE (occupations d'entraînement datées, heure extérieure estimée, enveloppe ligue
résolue serveur), l'engine reste plat. UI : bouton « Placer automatiquement » sur le Calendrier
(spinner, toast « N placés · M non plaçables », raisons par match).

**Boucle manuelle** : chaque match cliquable ouvre `PlacementPanel` — Déplacer, Dé-placer,
Verrouiller/Rendre au solveur (`placementSource` écho — refusé en 422 si le placement bouge),
Échanger (salle+heure, jamais les dates, deux PUT séquentiels), Modifier (équipe figée, date
conservée sauf switch HOME↔AWAY qui libère), Supprimer. **Rien ne bloque** une collision créée à la
main (décision fermée) — le diagnostic gradué (§2) l'affiche en sévérité max ; côté engine, les
ancres FIXED élaguent les candidats plutôt que d'entrer au NoOverlap (deux ancres en collision ne
rendent plus tout le solve infaisable).

## 4. Le gardien à l'ouverture — delta de visite (RMM-3)

Le radar est stateless : sans référence, aucun moyen de dire « ce qui a changé depuis ta dernière
visite ». **Empreinte STABLE d'un conflit** (`ConflictFingerprinter`, `TYPE:champs` d'identité
seuls, jamais `severity`/dates/compteurs — paires triées) : une empreinte glissante immunise contre
le bruit d'un ré-import. **`MatchModuleVisit`** fige un instantané (empreintes + dernière COMPLETED
du plan SEASON) par utilisateur à chaque visite. **`POST /api/matches/module-visit`** (ouvert au
Membre, un seul endpoint pour éviter une course lire/tourner) : première visite → référence figée
en silence ; hors fenêtre de grâce (`GRACE_MINUTES = 30`) → delta contre l'ANCIENNE référence puis
rotation ; dans la grâce → delta contre la référence NON tournée (idempotent, un F5 ne réédite pas
les badges). Trois signaux falsifiables : `newFixturesCount`, `newConflictFingerprints`,
`planningChanged` (comparaison d'IDS). Le calcul (`MatchModuleDeltaComputer`) charge son radar via
le même `ConflictRadarLoader` que le contrôleur de conflits (§2) — une seule maison.

Front : le POST part une fois au montage du module (`MatchesLayout`, garde socle), jamais à un
re-render ni à la navigation interne. `ModuleVisitBanner` (bandeau `role="status"`, ton accent, non
dismissible) sur le Calendrier ; chips « Nouveau » ornementales sur le radar.

### Échéances de saisie ligue/comité (RMM-6)

Une échéance PAR compétition (`Competition.entryDeadline`, jamais une date unique de club), posée
via l'endpoint bulk `POST /api/competitions/entry-deadlines` (hors CRUD, management), avec un
**défaut communautaire surchargeable** (`SharedCompetitionDeadline`, §1) — le club l'emporte
toujours, l'effacement de sa propre valeur ne touche jamais le partagé. `CompetitionResource` sert
`entryDeadline`/`effectiveEntryDeadline`/`deadlineSource` — la règle « club gagne » n'est jamais
recalculée au front. `GET /api/matches/deadline-outlook` (ouvert au Membre, `REMINDER_WINDOW_DAYS =
7`) sert les échéances dues sous J-7 (par compétition, fenêtre glissante) **et** `fbiTodo {toEnter,
toCorrect}` — le compte GLOBAL « à faire dans FBI » toutes semaines confondues (à saisir =
domiciles PLACED, à corriger = entrées OUVERTES du registre ci-dessus), pour que le cockpit ET la
barre de compteurs (§5) n'aient jamais à charger les fixtures —, et joint `guardianDelta` (réutilise
`MatchModuleDeltaComputer` **sans stamper**) si une référence de visite existe déjà. La carte
cockpit `FbiDeadlineCard` (`/`, sous `SeasonPlanBanner`) fusionne le résumé du gardien dans la même
carte (jamais un second bloc) et distingue trois régimes : une échéance en fenêtre J-7 → ton accent
(warning si dépassée) avec la ligne globale au-dessus des échéances ; hors fenêtre mais `fbiTodo` >
0 → ton NEUTRE, la seule ligne globale (« N FBI à faire, dont M à corriger ») ; rien à faire ET
aucune fenêtre → muette. Lien unique « Ouvrir la liste FBI » → `/matchs?fbi=1`.

## 5. Écran Calendrier (`/matchs`, route index)

L'écran unique du module : place/échange/verrouille/saisit dans FBI ET lit Semaine·Mois·Phase.
Importer garde sa maison propre (§6).

- **Chaîne de filtres pure** : filtre équipe/coach/gymnase (barre `MatchesFilterBar`, réutilise
  `ResourceFilter` de `features/planning`) → filtre type de compétition → scope temporel → familles
  de conflit. Coach = équipes coachées (principal+assistant) **+** équipes où il joue
  (`CoachPlayerMembership`). Filtre gymnase : le match doit y être POSÉ (les extérieurs en sortent).
- **`WeekWorkbench`** (temporalité Semaine, l'établi) : liste « À placer » couvrant TOUTES les
  semaines filtrées (pas seulement l'affichée) ; panneau de placement PERMANENT ; grille week-end
  (colonne « Extérieur » — dernière colonne du groupe de date qui porte ≥1 AWAY, blocs non
  enchaînés ; bande « sans heure » en tête pour un AWAY sans heure ni habitude) ; bande `AwayList`
  (détail : salle fichier, n° rencontre, rôle coach) ; radar `ConflictRadar` en dernier. Mode
  échange : Échap désarme, les candidates (autres domiciles PLACED) portent un anneau, les autres
  s'estompent.
- **Refus serveur du placement (D2)** : `FixtureStateProcessor::assertVenueAccessAllowed` (geste
  gestionnaire, create ET update d'un domicile) refuse en 422 (1) toute rencontre — amical compris
  — posée dans un gymnase couvert par une `VenueUnavailability` à sa date ; (2) pour une rencontre
  de COMPÉTITION seulement, quand le club déclare ≥ 1 `VenueMatchWindow` : aucune fenêtre `(gymnase,
  jour)` ou coup d'envoi hors fenêtre (même prédicat que le diagnostic,
  `MatchConflictDetector::kickoffInsideWindow` — une seule maison). Jamais de refus sur l'enveloppe
  ligue (radar seul, décision D2) ; un amical reste libre hors fenêtre d'accès. `/api/fixtures/place`
  (rail solveur, §3 — le solveur pose déjà les mêmes fenêtres/indispos en HARD) et la
  réconciliation FBI n'empruntent **pas** ce processor : intacts, hors du geste manuel gestionnaire
  que D2 vise. `PlacementPanel` lit trois gardes du club (accès match,
  indisponibilités, enveloppe ligue) via `readState` ; le GESTE de placement est SUSPENDU
  (`LoadErrorHint` + retry en échec, spinner en chargement) tant qu'elles ne sont pas `ready` — un
  échec de première lecture ne se lit jamais « aucune restriction ». Les mutations de placement/
  édition restituent le message 422 du serveur dans le toast (`errorMessage`) au lieu d'un
  générique.
- **Enveloppe ligue — miroir déclaré (FRT-32)** : le prédicat d'appartenance au coup d'envoi
  (intervalle FERMÉ `[kickoffMin, kickoffMax]`, filtré par jour) vit dans
  `MatchConflictDetector::kickoffInsideLeagueWindow` (backend, DIAGNOSTIQUE `LEAGUE_WINDOW_VIOLATION`)
  et son miroir DÉCLARÉ `matches/lib/envelope.ts::kickoffInsideLeagueWindow` (front, BLOQUE la pose
  via `isInEnvelope`) — gardés en parité par `leagueEnvelope.parity.json` +
  `LeagueEnvelopeMirrorParityTest`/`leagueEnvelope.parity.test.ts`. Divergent PAR CONCEPTION :
  l'exemption amical (front `!isFriendly` vs backend saut des `competitionId` null) et la
  résolution équipe↔fenêtre (déjà serveur).
- **Grille week-end** (`WeekendGrid`) : un match placé démarre au coup d'envoi et dure le match
  (`matchMinutes` résolu) ; il **s'enchaîne** avec un match suivant même gymnase/jour dont le coup
  d'envoi tombe ≤ 30 min après sa fin — au-delà, le trou reste visible. **Case « À confirmer »**
  (`WeekendCell.toConfirm` = `UNPLACED` déjà pré-rempli gymnase+heure par l'import) : rendu DÉDIÉ
  (fond hachuré, pastille warning « À confirmer »), jamais une case pleine ni un cadenas — le
  gestionnaire n'a encore rien décidé. `PlacementPanel` propose alors « Confirmer ce placement »
  (au lieu de « Placer ») tant que rien ne change. Cadenas resserré : `locked = status !==
  "UNPLACED" && placementSource === "MANUAL"` — un `UNPLACED` n'est jamais verrouillé ; `SOLVER`
  reste re-solvable. ⚠ **Dette signalée** : `PlacementPanel` garde sa propre formule de cadenas,
  non alignée (`specs/evolution/roadmap.md` P4-214).
- **Blocs fantômes** (habitude à gymnase sans match ce jour-là, `showGhosts` = interrupteur
  « Semaine type ») : bloc translucide pointillé, dissous par la réalité (tout match de l'équipe ce
  jour-là, extérieur compris). L'estimation d'heure d'un extérieur ne dépend jamais de cet
  interrupteur.
- **Temporalités Mois/Phase** : Mois = table groupée par jour ; Phase = une `Competition`
  appariée, en-tête « N/M journées » (`expected: null` pour une `CUP`, pas de dénominateur).
  `MatchRowsTable` (ligne partagée) : date/heure, équipe+rôle, dom./ext., adversaire, gymnase
  résolu, statut, pastilles de conflit.
- **Filtres d'affichage** (persistants en URL/store, défauts) : Types de compétition
  (championnat+coupe+brassage cochés, **amical décoché** par défaut), Extérieurs (**masqués** par
  défaut, sauf dans la colonne dédiée qui reste toujours visible), Semaine type. Un indice « N
  matchs masqués » signale ce que les filtres cachent sur la semaine affichée, avec un bouton pour
  lever exactement ce qu'il faut.
- **Mémoire de session des filtres** : l'URL fait foi pour les filtres Consulter **seulement si**
  elle porte au moins une des clés dédiées (`hasConsultParams`) — sinon le store (Zustand, mémoire
  de session non persistée) est gardé et l'adresse se re-synchronise depuis lui. Un lien qui
  allume ses propres filtres (« Voir la semaine » depuis Conflits, « Placer » depuis Importer)
  construit toujours une query qui porte une clé dédiée, donc reste dans le cas qui fait foi.
- **`WeekCounters`** (barre au-dessus de la grille) : deux compteurs BORNÉS à la semaine affichée
  dans un `role="group"` « Semaine affichée » (« N à placer » · « N conflits → », lien vers
  Conflits) ; le troisième — **« N FBI à faire »** — est GLOBAL (toutes semaines), vit HORS du
  groupe (frère `ml-auto`) pour ne jamais laisser croire qu'il compte la seule semaine affichée, et
  ouvre l'écran **« FBI — à faire »** (deep-link `?fbi=1`, ouvrable aussi depuis le cockpit,
  `lib/urlState.ts` `decodeFbiParam`/`applyFbiToParams`). `FbiEntryList` (maison unique de l'écran) :
  deux sections empilées, **« À corriger dans FBI » en premier** (l'appli fait foi, une ligne par
  match groupée par rencontre, gras = valeur Amateo à taper, FBI en sourdine jamais barré, « vu
  dans FBI le … » tant que rien n'a bougé) puis **« À saisir »** (domiciles PLACED, mention « FBI
  affiche … » depuis `Fixture.fbiEcho` sur une ligne contredite) ; une famille vide est masquée, les
  deux vides → « Rien à faire dans FBI. ». Cocher « Corrigé dans FBI » (`POST …/close`, sans
  confirmation) ou « saisi » grise la ligne et offre « Annuler » (`POST …/reopen`) jusqu'à la
  fermeture de la modale (`Set`/`Map` local, remis à zéro à l'unmount — patron d'undo sans toast) ;
  « Tout marquer saisi » reste borné aux lignes « à saisir » affichées. Filtre Équipe conservé, le
  filtre Date a disparu (la liste est globale, triée par date de match).

## 6. Écran Conflits (`/matchs/conflits`)

Lecture seule, en regard du Calendrier : « qu'est-ce qui cloche sur TOUTE la saison, regroupé par
qui ça touche ? ». Même flux (`GET /api/fixtures/conflicts`), **sans** le filtre équipe/coach/
gymnase partagé (décision fermée — il fausserait le compte saison de l'onglet).

- **Pivot** (`pivotConflicts`, pur — répartit en seaux les lignes déjà servies, ne recalcule rien) :
  coach (défaut) · équipe · gymnase · journée (week-end). Chaque axe porte une sentinelle pour les
  conflits sans ressource résolue (« Autres conflits », « Extérieur », « Sans date »), toujours en
  dernier. Un conflit à 2 équipes apparaît sous chacune en pivot équipe (assumé). Tri : compte
  décroissant puis alphabétique fr (chronologique pour la journée).
- **Chips familles** (10 `ConflictType`, toutes cochées par défaut) : compteur SAISON, à traiter
  seulement — une famille 100 % traitée garde sa chip, « · 0 » en sourdine.
- **Puces « Traitement »** (À traiter · Dérogation demandée · Réglé en interne · Sans solution pour
  l'instant) et filtre « Seulement avec un match à domicile » (critère descriptif, pas « où je peux
  agir » — décision fermée). Ordre d'application : familles → traitement → domicile → pivot.
- **Accordéon par entrée** (une seule ouverte) rend `ConflictSeverityGroups`/`ConflictLine` — même
  maison que le radar du Calendrier, gravité 7 repliée derrière un compte, conflits triés par date
  croissante dans un groupe de gravité. Bouton « Voir la semaine » sur un conflit daté.

### Résolution des conflits (`ConflictResolution`, P4-207)

Un conflit traité **reste toujours rendu** — poser un statut dit où en est la résolution, ne masque
jamais. Cinq cas stockables (`ConflictResolutionStatus`) : Dérogation demandée / Réglé en interne /
Sans solution pour l'instant, plus deux réservés aux conflits de PERSONNE où un côté servi porte le
rôle PLAYER — **« Coache, ne joue pas »** (`COACHES_NOT_PLAYING`) / **« Joue, ne coache pas »**
(`PLAYS_NOT_COACHING`), refusés en 422 sinon (`FixtureConflictsController::conflictHasPlayerSide`) ;
le front ne les PROPOSE que dans ce cas (`resolutionChoicesFor`, lecture des rôles servis, jamais une
redérivation) et les range sous le filtre/chip « Réglé en interne » (pas de chip propre,
`treatmentOf`). « À traiter » = défaut = **absence de ligne**. `GET /api/fixtures/conflicts` sert un champ additif
`resolution` (jointure serveur par empreinte, `ConflictRadarLoader`) ; `PUT`/`DELETE
/api/fixtures/conflicts/{fingerprint}/resolution` (gestionnaire seul, empreinte contrainte par la
route). **Orphelin** (empreinte disparue du flux) jamais nettoyé à la volée — purgé avec la saison
(`SeasonDataPurger`) ou l'effacement RGPD. Écran (`ConflictResolutionControl`) : pastille
`StatusPill` devenant, pour un gestionnaire, le déclencheur d'un menu APG (3 statuts + note +
« Remettre à traiter ») ; un membre simple la lit figée, rien n'affiche « à traiter ». Note libre
≤ 500 car., éditée inline ; `ConfirmDialog` seulement si une note serait perdue au retour à traiter.
Tout compteur de l'app (badge de nav, `WeekCounters`, chips de familles, pivot) ne compte plus que
l'à traiter. Badge de nav « Conflits · N » — absent (jamais « · 0 ») à zéro.

## 7. Écran Importer (`/matchs/importer`)

Carte « Données de match » (dépôt FBI, canal API, Engagements FFBB, fraîcheur) + file de traitement
par équipe (`ReviewQueue`, `AccordionSection` contrôlée, deep-link `?equipe=`).

### Import FBI (xlsx, une passe)

Fichier GLOBAL club (colonnes Division · N° de match · Équipes · Date · Heure · Salle). `analyze()`
(dry-run) résout les correspondances Division↔équipe (`Competition`, type inféré `BRASSAGE`/
`CHAMPIONSHIP` du nom, disambiguïsé par `fbiTeamLabel` si deux équipes club dans la même division) →
le gestionnaire complète → `import()` reçoit `{file, mappings}` et persiste tout en une passe. Diff
par `(team, externalRef)` : date/switch → **dé-placement** + warning ; heure réelle → mise à jour en
place ; `00:00` = sentinelle « heure non fixée », n'écrase jamais une heure posée ; `Exempt` sauté.
Onglets par famille (Brassage · Coupe ARA · Coupes CRM · Amicaux · Départemental · Régional ·
Autres — classification PURE présentationnelle, `divisionFamily.ts`, jamais un comportement métier)
avec compteur d'appariement ; recherche/`Listbox` avec champ de recherche au-delà de 8 options.
Garde-fou poule (`PR F2`) : adversaires du fichier confrontés à la liste des clubs de la poule
appariée — > 50 % d'inconnus = division refusée nommée et sautée, 1..50 % = warning
`POULE_MISMATCH`. Fichier lu en mémoire (snapshot `arrayBuffer`) une fois à la sélection —
insensible à une réouverture externe du fichier entre analyse et import.

### Canal API FFBB (à la demande, `FfbbRencontreReconciler`)

FBI (xlsx) fait foi, l'API est un confort — bandeau d'honnêteté à chaque ouverture. Appariement à 3
étages + tier-0 d'idempotence (id national déjà connu) : compétition appariée pour UNE seule équipe
→ suggère l'équipe ; date exacte ; adversaire normalisé. Une coupe non appariée devient une vraie
`Competition` `CUP` (le libellé fédéral tranche, jamais l'absence d'appariement) — seul le token
`amical` laisse `competitionId` null. Les rencontres publiées sans fixture correspondante sont
**proposées, jamais imposées** (`TeamSelect` par ligne, rien créé si vide) via la vue dédiée
`/matchs/reconciliation` (zéro état serveur, payload en mémoire, renvoi propre sans payload).
`POST /api/ffbb/rencontres/apply` re-fetche côté serveur (jamais les valeurs client), écrit sa propre
`FbiIngestion source=FFBB_API`.

### Workflow de traitement — NEW / OUT_OF_SYNC / REVIEWED

Deux axes distincts sur une `Fixture` : `status` (placement) et `reviewState` (a-t-elle été
EXAMINÉE). **`FixtureReviewState`** : `NEW` (jamais examinée) · `OUT_OF_SYNC` (déphasée, un écart
pendant subsiste) · `REVIEWED` (traitée). `pendingDeviations` (JSON) porte les écarts ouverts, un
par champ (`date`/`kickoff`/`venue`), `autoApplied` marque une valeur imposée hors périmètre. Maison
unique du statut : `Fixture::setStatus` (passer PLACÉ traite la rencontre, sauf écart pendant).

Règles de naissance : un extérieur, ou une rencontre passée/dans la semaine ISO en cours, naît déjà
`REVIEWED` — « un extérieur n'est jamais à traiter par le club », « un match imminent ou déjà joué
n'est pas nouveau » (`treatOnArrival`, foyer partagé aux deux canaux). Rattrapage
(`catchUpReview`/commande `app:fixtures:catch-up-review`) pour une rencontre `NEW` restée figée
avant ce prédicat. Un domicile PLACÉ/SUBMITTED que la source renvoie identique sur date+heure+salle
devient `VALIDATED` + traité (D9 — « attesté FBI », plus un geste du gestionnaire). Un champ
divergent sans décision → `OUT_OF_SYNC`, valeur app gardée — **sauf** si la fenêtre passé/semaine ISO
s'applique (app OU source), qui applique la source d'office (« pris en compte », `autoApplied`).
Une rencontre absente d'un dépôt n'est jamais touchée (import partiel légitime).

Périmètre de réconciliation par écart (choix explicite, jamais un écrasement) : seuls les domiciles
**déjà placés** peuvent diverger sur date/heure/salle — un `UNPLACED` reste hors périmètre sur date/
heure, mais son écart de SALLE a son propre détecteur depuis 2026-09-16 (§1). Conséquence par champ :
date/salle **dé-placent** ; heure reste en place mais **rétrograde** un `SUBMITTED`/`VALIDATED` en
`PLACED`.

Deux routes de traitement (management + saison écrivable + socle pointé) : `POST
/api/fixtures/review` (`{fixtureIds}` ligne, ou `{teamId}` masse — les rencontres à écart pendant
sont sautées et NOMMÉES, jamais tranchées en masse) ; `POST /api/fixtures/review/deviations`
(`{fixtureId, field, choice: keep_app|take_source}`, rejoue le moteur partagé depuis la valeur
PERSISTÉE). `ReviewQueueRow` : icône domicile/extérieur, heure ou « heure non publiée », salle
résolue ; bouton « Valider » (NEW sans écart), « Pris en compte » (alerte `autoApplied` seule — une
phrase UNIQUE, maison `lib/autoAppliedPhrase.ts` : date+heure reprogrammées fusionnent en « {source} a
déplacé ce match : {ancienne date} → {nouvelle date} à {heure} », un seul champ garde sa forme
« (champ) : ancien → nouveau », une combinaison rare liste chaque champ dans le même bloc — jamais des
dates ISO brutes à l'écran), tranche champ par champ sinon (deux colonnes Amateo/source) ; « Replacer »
seulement sur un domicile À VENIR.

## 8. Écran Configuration (`/matchs/configuration`)

Réglages de saison RARES, cinq `AccordionSection` contrôlées (une seule ouverte, défaut **tout
replié**), ancrées `?section=<clé>` : Échéances de saisie (§4) · Durée des matchs (`sport_category`
par famille, table partagée) · Adversaires à localiser (`OpponentTravelCard`, §1) · Accès match
(fenêtres `VenueMatchWindow`, modale par gymnase — même éditeur que le wizard) · Libellés FFBB des
gymnases (écran d'appariement, ci-dessous). Un résumé discret dans le nom accessible de chaque
bouton (`configSummaries.ts`, fonctions pures — comptent ce que le backend a déjà calculé, jamais
une règle métier recalculée) ; une lecture en échec rend `null`, jamais un « 0 » fabriqué.

**Écran d'appariement des libellés** (`VenueLabelsSection`) : une ligne par LIBELLÉ de l'inventaire
(`GET /api/venues/fbi-labels`), `VenueSelect` dont la valeur effective est le gymnase confirmé sinon
la suggestion « d'après les rencontres » pré-sélectionnée. Trois gestes : **Confirmer** (additif,
sans confirmation) ; **Réaffecter** (`reassign: true` — corrige un alias posé sur le mauvais
gymnase en un geste, re-pointe les non placés, épargne toujours les placés, `ConfirmDialog` nommant
ce qui bouge) ; **Retirer** (ne touche aucune rencontre déjà rattachée). Un signal partagé
(`UnpairedVenueLabelsBanner`) renvoie vers cet écran unique depuis Importer et le Calendrier —
décision fermée (une seule maison d'appariement, jamais une modale sur une modale).

## 9. Écran Semaine type (`/matchs/semaine-type`)

Le MODÈLE sans dates que le placement respecte au maximum : le gabarit idéal (`TypicalWeekendGrid`
— habitudes Sam/Dim × gymnases, sans dates, collisions posées côte à côte) en vedette, et l'éditeur
« Créneaux partagés (alternance) » (`MatchSlotRotationsEditor` — déclarer un créneau + ses équipes
membres dans l'ordre, réordonnancement par flèches, `position` explicitement dit FICTIF à l'écran).
Segmenté « Semaine A/B/… » sur le gabarit dès qu'une rotation ≥ 2 membres existe (sinon la grille
reste identique à avant, aucun segmenté). Le bouton **« Habitudes & passerelles »**
(`HabitsLinksDialog`, l'écran unique de ces deux réglages, ouvert aussi depuis le wizard) s'ouvre
d'ici. Un signal « hors image » (écart entre placement réel et modèle de référence — habitude ou
rotation du jour) et un signal « même week-end » (deux membres d'une même rotation reçus le même
week-end, contredit l'image A/B) restent des SIGNAUX, jamais un blocage.

## 10. Le périmètre engagé (`TeamEngagementGuard`)

Valider le planning valide aussi un périmètre : les équipes qui font de la compétition. **Engagée**
= porte au moins un `Fixture`, quel qu'en soit le statut (l'import crée tout en `UNPLACED` —
filtrer sur le statut serait inerte au moment précis où la garde doit mordre). Sur une équipe
engagée : suppression → **409** ; changement de `Team.level` → **409 sans exception** (le niveau
alimente la photo de structure d'une version validée) ; nom/créneaux/gymnase/`isActive`/
`priorityTierId` restent libres. La règle vit à un seul endroit (`TeamEngagementGuard`,
`TeamResource.isEngaged`) ; les purges de masse et le restore de structure la contournent par
construction — `StructureRestorer::assertRestoreKeepsEngagedTeams` refuse en 409 le chargement
d'une version qui ne contiendrait pas une équipe engagée (avec son niveau).

**La salle d'un match, elle, n'est délibérément PAS protégée** — un gymnase qui ferme dépointe le
match (y compris `SUBMITTED`/`VALIDATED`), **annoncé jamais refusé** (`DeletionImpactCounter`). Deux
gâchettes partagent le foyer unique `FixtureVenueLossMarker` (dépointage : `UNPLACED`, `venueId`
NULL, raison persistante `unplacedReason = venue_lost`, coup d'envoi conservé comme repère) : la
suppression de gymnase (`CascadePlan`), et la VALIDATION d'un planning (`GET
/api/schedules/{id}/validate-impact` annonce `{orphanedFixtures, declaredOrphanedFixtures}` avant
confirmation ; `POST …/validate` dépointe exactement ce que l'impact a annoncé — même prédicat
`OrphanedFixtureFinder`, parité par construction). L'exploration (« Charger cette version ») ne
dépointe **plus rien** — un match dont le gymnase est absent de la photo survit intact, nommant un
`venueId` transitoirement pendouillant. Le périmètre engagé résiste aux deux gâchettes (le match
existe toujours après dépointage, son équipe reste engagée).

## 11. Tests & gardes (pointeurs)

NR tenant isolation bloquant : `MatchTenantIsolationTest` (Competition/Fixture/fenêtres/indispos/
habitudes/passerelles/`opponent_travel`/`ConflictResolution` — étendu à chaque table tenant du
module). Contrats cross-stack (groupe `contract`) : `MatchPlacementContractSchemaTest`,
`ValidateAssignmentsContractSchemaTest`, `SlotRotationPayloadParityTest`,
`MatchVisitDeltaParityTest`. Tables partagées : un `*ShareTest` par table (`OpponentDirectoryShareTest`,
`OpponentVenueSuggestionShareTest`, `EntryDeadlineShareTest`). Périmètre engagé : `EngagedTeamGuardTest`,
`DeletionImpactParityTest`. Détecteur/radar : `MatchConflictDetectorTest`,
`FixtureConflictsApiTest` + feature Behat `les-conflits-d-un-match-disent-la-verite.feature`
(`ConflictTruthContext`, D1/D1 étendu, statuts joue/coache). Solveur : `test_match_placement*.py` (unit, sémantique, golden épinglé)
+ feature Behat `backend/features/placement-des-matchs.feature`. Import/réconciliation :
`FbiFixtureImporterTest`, `FfbbRencontresApiTest`, `FixtureReviewApiTest` + features Behat dédiées
(`un-domicile-importe-retrouve-son-gymnase`, `le-gymnase-du-fichier-localise-l-adversaire`,
`les-gymnases-d-un-adversaire-se-partagent-en-suggestions`, `un-conflit-traite-reste-visible-mais-decompte`,
`une-rencontre-importee-dit-si-elle-est-traitee`). Registre « à corriger dans FBI » :
`FbiCorrectionApiTest` (lecture/close/reopen, 404 cross-club, 403 membre sur close),
`MatchTenantIsolationTest` (étendu) + feature Behat `ce-que-fbi-doit-refleter.feature`
(`FbiCorrectionContext`, suite `fbi-a-corriger`). Front : suites Vitest sous
`frontend/src/features/matches/` (`lib/*.test.ts` pour chaque dérivation pure, `*.test.tsx` par
écran) + e2e `frontend/tests/e2e/matches*.spec.ts`.
