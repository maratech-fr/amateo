# Module matchs (FFBB) — état courant

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
jamais un import bloqué.

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
4 `VENUE_UNAVAILABLE` + `ACCESS_WINDOW_LOST` · 5 clash adouci + `TEAM_LINK_OVERLAP` +
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
correspondante ; (2) sinon la ville de l'annuaire fédéral global ; (3) `null`. Le front
(`conflictSideLines.ts`, `ConflictLine.tsx`) rend une ligne par côté + une ligne de chevauchement,
présentation pure — aucune formule de gravité redérivée.

## 3. Solveur de placement (`POST /api/fixtures/place` → engine `/place-matches`)

Second problème solveur ([ADR-0003](../../docs/architecture/adr-0003-match-placement-solve.md)),
même `CONTRACT_VERSION` **2.22** que `/generate`/`/validate-assignments` (un seul contrat pour les
trois endpoints — voir §6 `CLAUDE.md`). **Rail
SYNCHRONE** (`PlaceMatchesController` — management + saison écrivable + socle pointé), anti-double-clic
`MatchPlacementLock` (Redis dédié). Best-effort à poids dominant : `10 000 × Σ placés + SOFT` —
**aucune contrainte HARD n'est jamais violée en sortie** ; un match sans candidat licite sort NOMMÉ
(`no_access_window` · `no_league_intersection` · `venue_unavailable` · `venue_full`).

**HARD** : fenêtres d'accès match (le match SEUL dedans, D1 — `kickoff ≥ start`,
`kickoff+matchMinutes ≤ end`), indisponibilités gymnase, no-overlap `(gymnase, date)` sur la fenêtre
MATCH, fenêtre ligue quand l'enveloppe est résolue (non résolue = diagnostic INFO seul). Durées par
équipe (`MatchDurationResolver`) portées par le contrat ; absentes côté engine ⇒ défauts Pydantic
105/30. **SOFT (golden-épinglés)** : conflit coach MAIN −60 · passerelle `NOT_SIMULTANEOUS` violée
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
7`) sert les échéances dues sous J-7 + le compte de domiciles restant à saisir, et joint
`guardianDelta` (réutilise `MatchModuleDeltaComputer` **sans stamper**) si une référence de visite
existe déjà. La carte cockpit `FbiDeadlineCard` (`/`, sous `SeasonPlanBanner`) est muette hors
fenêtre, fusionne le résumé du gardien dans la même carte (jamais un second bloc), reste affichée
(ton warning) même dépassée.

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
- **`WeekCounters`** (barre au-dessus de la grille) : trois compteurs purs — « N à placer » · « N
  conflits → » (lien vers Conflits) · « N à saisir dans FBI » (ouvre la modale `FbiEntryList`,
  groupée par équipe, filtrable équipe/date, « Tout marquer saisi » borné à l'affiché).

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
jamais. Trois cas stockables (Dérogation demandée / Réglé en interne / Sans solution pour l'instant) ;
« À traiter » = défaut = **absence de ligne**. `GET /api/fixtures/conflicts` sert un champ additif
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
résolue ; bouton « Valider » (NEW sans écart), « Pris en compte » (alerte `autoApplied` seule),
tranche champ par champ sinon (deux colonnes Amateo/source) ; « Replacer » seulement sur un domicile
À VENIR.

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
`FixtureConflictsApiTest`. Solveur : `test_match_placement*.py` (unit, sémantique, golden épinglé)
+ feature Behat `backend/features/placement-des-matchs.feature`. Import/réconciliation :
`FbiFixtureImporterTest`, `FfbbRencontresApiTest`, `FixtureReviewApiTest` + features Behat dédiées
(`un-domicile-importe-retrouve-son-gymnase`, `le-gymnase-du-fichier-localise-l-adversaire`,
`les-gymnases-d-un-adversaire-se-partagent-en-suggestions`, `un-conflit-traite-reste-visible-mais-decompte`,
`une-rencontre-importee-dit-si-elle-est-traitee`). Front : suites Vitest sous
`frontend/src/features/matches/` (`lib/*.test.ts` pour chaque dérivation pure, `*.test.tsx` par
écran) + e2e `frontend/tests/e2e/matches*.spec.ts`.
