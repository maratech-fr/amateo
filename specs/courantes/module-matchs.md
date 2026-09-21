# Module matchs (FFBB) — état courant

Last verified @ 2026-09-21 (`documentation-update`, lot M « l'échauffement sort de l'empreinte des
conflits de PERSONNE » + correctif `e881d748` « le conflit passerelle disparu quitte le contrat
public et l'empreinte »). Confronté au code cette passe : `MatchFootprint::personConflictOccupancy`/
`personConflictOccupancyAt` (occupation moins l'échauffement, trajet AWAY conservé) ;
`MatchConflictDetector` — `MATCH_MATCH` et le côté match de `MATCH_TRAINING` testent désormais ce
chevauchement (`conflictWindow`, plus `effectiveMatchWindows`/`sameHomeVenue`, supprimées comme code
mort) ; le chargement de `TeamLink` retiré de `ConflictRadarLoader`/`MatchConflictDetector`
(paramètre `teamLinks` disparu de la signature) ; le solveur `match_placement.py` — les trois
fenêtres de personne (`fixed_windows_by_coach`, poids TO_PLACE, `_overlap_pairs` coach+passerelle)
ne retranchent plus `warmupMinutes`, seul le trajet AWAY reste ; `CONTRACT_VERSION` inchangé
(**2.23**, `warmupMinutes` reste au schéma, simplement plus lu par le solveur). **`e881d748`
(même jour) referme le volet contrat** : `TEAM_LINK_OVERLAP` retiré de l'énumération OpenAPI
`conflicts[].type` (`SeasonAndFixturePaths`, snapshot régénéré, 207 routes inchangées, 9 valeurs
restantes) et de `ConflictFingerprinter` (branche `match` devenue morte) — vérifié : zéro hit
`TEAM_LINK_OVERLAP` dans `specs/courantes/openapi-snapshot.json` ni dans `ConflictFingerprinter.php`.
**Reste ouvert, VOLONTAIREMENT** : la part FRONTEND de cette dérive — `conflictLabels.ts`/`api.ts`
gardent leurs 10 `ConflictType` (dont `TEAM_LINK_OVERLAP`), la chip « Passerelle » de l'onglet
Conflits reste affichée et cochable alors qu'elle ne peut plus se peupler (§2 « Chips familles »
ci-dessous) — un lot séparé reprend cet écran juste après ce lot, décision de ne pas l'entamer ici.
Le reste du fichier (Validé ligue, Écran Adversaires, delta de visite…) n'a pas bougé sous ce lot —
historique des passes précédentes :
`git log -p --follow specs/courantes/module-matchs.md`.

> **Règle de forme (refonte 2026-09-18, AUD-DOC-38)** : ce fichier décrit **l'état courant, par
> écran** — jamais une section datée d'une PR. Le JOURNAL (qui a livré quoi, quand, sous quel id)
> vit dans [`etat-des-lieux.md`](etat-des-lieux.md) §1.5 (carte) et §3 (traces datées) ; les
> **décisions fermées** (tranchées contre une option évidente) vivent dans son §2 — ce fichier n'en
> reproduit que le résultat, jamais le débat. But de taille : décrire l'état courant sans historiser
> — si une phrase commence par une date ou un id de PR, elle appartient à l'état des lieux, pas ici.

Le module vit dans `frontend/src/features/matches/` (nav `MatchesLayout`, sous `/matchs` :
`index` = Calendrier, `conflits`, `importer`, `configuration`, `adversaires` (§ Écran Adversaires,
sorti de Configuration le 2026-09-19), `semaine-type`, `consulter` en redirection permanente vers
l'index, `reconciliation` accessible seulement depuis le canal API,
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
  `CHAMPIONSHIP`/`CUP`/`BRASSAGE`, `entryDeadline` nullable, réfs FFBB — voir §4) — N par équipe.
- **`Fixture`** (table `fixture`) : `teamId`, `competitionId` nullable = amical, `matchDate`,
  `homeAway`, `opponentLabel`, `opponentOrganismeCode`/`opponentTeamKey` (résolus serveur),
  `status` (`FixtureStatus` : `UNPLACED → PLACED → SUBMITTED → VALIDATED`), `venueId`/`kickoffTime`
  nullables, `fbiVenueLabel` (salle brute source, HOME et AWAY), `placementSource`
  (`MANUAL`/`SOLVER`/null legacy), `unplacedReason` (raison persistante, ex. `venue_lost`),
  `keptVenueLabel` (idempotence d'arbitrage salle), `reviewState`/`reviewedAt`/`pendingDeviations`
  (workflow de traitement, §5). L'import FBI crée **tout** en `UNPLACED`, domicile et extérieur —
  seul un geste du gestionnaire pose un autre statut (`FixtureStatus` docblock). `awayTravel`
  (champ additif en LECTURE seulement, `FixtureResource`, jamais persisté sur l'entité) : le trajet
  d'une rencontre EXTÉRIEURE, DÉRIVÉ de la rencontre elle-même — voir « Table TENANT
  `OpponentVenueLink` » ci-dessous.
- **`TeamMatchHabit`** : jour ISO + heure-point + gymnase optionnel, une par jour et par équipe.
- **`TeamLink`** (couple symétrique `teamAId < teamBId`, cap `MAX_TEAM_LINKS = 50`) : côté MATCHS
  `TeamLinkType` `NOT_SIMULTANEOUS`/`BACK_TO_BACK` (**depuis le lot M, 2026-09-21** : rail SOFT
  **placement seul** — le radar de conflits a cessé de charger `TeamLink`, décision fondateur « ça
  fait plus de bruit qu'autre chose », § « Détecteur de conflits » ci-dessous) ; côté ENTRAÎNEMENT
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
  libellé du fichier, §5.2) ne compte jamais. **Comptabilité IDEMPOTENTE et SYMÉTRIQUE par `(club,
  code organisme, ref)` — revue sécurité 2026-09-20** : `OpponentVenueLinkManager` ne crédite un
  gymnase que si le club ne le porte pas DÉJÀ (plusieurs libellés du même club vers le même gymnase
  ne créditent qu'une fois — `OpponentVenueLinkRepository::countManualByRef`) et ne débite qu'au
  retrait du DERNIER lien du club sur ce gymnase. `venueExternalRef` n'est **persisté sur un lien
  que s'il résout fédéralement** (`OpponentTravelResolver::resolveFederalVenue` — sinon un lien par
  coordonnées seules, `ref` null, tenant seul) : un ref présent implique donc toujours un crédit
  passé, le débit est symétrique **par construction**, jamais une inférence à part. `GET
  /api/opponents/{code}/venue-suggestions` sert `chosenByCount` (des CHOIX par club×gymnase, pas des
  clubs distincts) et `lastChosenAt` au JOUR seul. **Plafond `MAX_VENUES_PER_OPPONENT = 20`** par
  `(club, adversaire)` — l'ajout d'une NOUVELLE clé de libellé au-delà rend 422 « Trop de gymnases
  pour cet adversaire », l'actualisation d'un libellé déjà apparié reste libre ; empêche l'inflation
  du compteur communautaire par des libellés forgés. `POST /api/opponents/{code}/venues` ne valide
  **jamais** le libellé contre les rencontres de la saison (à dessein — ajouter un gymnase AVANT
  toute rencontre reste possible, cas playoff/poule pas encore tirée) ; seul `POST
  /api/opponents/{code}/venue-links` (apparier un libellé ORPHELIN) l'exige.
- **`SharedCompetitionDeadline`** : le défaut communautaire d'échéance de saisie, keyé
  `ffbbCompetitionId` — voir §4.

### Table TENANT `OpponentVenueLink` (amendement PR I, 2026-09-20)

L'appariement d'un LIBELLÉ de salle FBI vers un GYMNASE fédéral, POUR UN CLUB — remplace
`OpponentTravel` (supprimée, migration `Version20260920140000`). Un adversaire joue dans une salle
donnée quels que soient l'équipe ou la saison (**prouvé en base** : la même équipe adverse joue
dans deux salles ; ≥ 8 adversaires sur 75 ont 2 gymnases) : le gymnase se rattache au CLUB adverse
et au LIBELLÉ vu dans le fichier, jamais à l'équipe ni à la saison (« SALLE TOLA VOLOGE = ce
gymnase de Bron » ne dépend d'aucune saison, exactement comme les distances de
`ClubTravelCache`). Grain **`(club, code organisme adverse, libellé FBI normalisé)`** — un lien
par couple (unique) ; le libellé normalisé (`VenueLabelNormalizer::normalize`) est la clé qu'une
rencontre AWAY retrouve par sa propre salle (`Fixture::getFbiVenueLabel()`). Chaque lien porte le
SNAPSHOT fédéral du gymnase (`venueLabel` + `latitude`/`longitude`, jamais le texte brut du
fichier) + `venueExternalRef` nullable (numéro de salle fédéral, null pour un gymnase choisi par
coordonnées seules) ; `source` AUTO|MANUAL — un MANUAL n'est jamais écrasé par une passe AUTO.
**Le trajet n'y est PAS** : c'est une CONSTANTE lue depuis `ClubTravelCache` (siège du club →
gymnase, § « Cache de trajets et calcul asynchrone » ci-dessous), jamais dupliquée par lien.
**Purge** : club-scoped SANS saison → une purge de SAISON ne le touche jamais
(`SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE`), sa seule porte de sortie est l'effacement RGPD du
club (`ErasedClubPurger::PURGED_BY_CLUB`, qui décrémente d'abord le compteur partagé des liens
MANUAL).

**Auto-localisation depuis le libellé du fichier** (`OpponentVenueAutoLocator`, égalité STRICTE
libellé du fichier ↔ salle fédérale candidate) pose un `OpponentVenueLink` `AUTO` par `(code,
libellé normalisé)` DISTINCT des fixtures AWAY — 0/≥2 hits n'écrivent rien (le libellé reste « à
apparier ») ; n'écrit **jamais** le partagé (une localisation devinée n'est pas un choix) ; un lien
MANUAL existant reste souverain. Ne calcule plus de trajet ici (le calcul est asynchrone, §
ci-dessous). **Bouton unique « Mettre à jour les adversaires »** → `POST /api/opponents/refresh`
(`OpponentRefreshController`, cap `MAX_DISTINCT = 200` avant tout réseau, limiteur
`opponent_refresh` 10/h) enchaîne trois passes best-effort indépendantes : rattrapage des codes
fédéraux, auto-localisation (pose des liens), dispatch du calcul des trajets manquants. Budget
global `REFRESH_BUDGET_SECONDS = 45` threadé aux trois passes (au-delà : réponse partielle
`unresolved`/`skipped`). Best-effort intégral à chaque étage — une panne réseau rend « non
localisé », jamais un import bloqué. L'écriture de l'annuaire (`OpponentDirectoryEntryRepository::
upsert`) est un `INSERT … ON CONFLICT (ffbb_organisme_code) DO UPDATE` natif (retours de tests
2026-09-19) : deux noms d'observation différents qui résolvent le MÊME code fédéral dans un seul
lot fusionnent en une ligne au lieu d'un double `persist` applicatif qui violait l'unicité et
**fermait l'`EntityManager`** (les passes/imports suivants du même appel se retrouvaient
silencieusement avalés, réponse 200 vide). Chaque passe qui lève est isolée
(`OpponentRefreshController::step`) et s'inscrit dans un champ additif `failedSteps` — le front dit
franchement « mise à jour interrompue à l'étape … » plutôt qu'un succès mensonger ; volontairement
**pas** de `resetManager()` (dette assumée : `roadmap.md` P4-247). **Les deux AUTRES hooks d'appel**
(import xlsx `ImportFixturesController`, apply du canal API `FfbbRencontresController`, § « Écran
Importer ») appelaient `locate()` **sans aucune borne** jusqu'au correctif de revue `93f36c29`
(2026-09-21) : le repli par nom (lot K, ci-dessus) double un fan-out sortant déjà non borné à ces
deux hooks. Ils calculent désormais leur propre `deadline` (`VENUE_AUTOLOCATE_BUDGET_SECONDS = 30`
secondes, même patron best-effort que l'orchestrateur — dépassement = arrêt propre des libellés
restants, jamais un import ou un apply cassé).

**Gestes management** (`OpponentTravelController`, foyer d'écriture unique
`OpponentVenueLinkManager` — écriture du lien + comptabilité du partagé + chauffage synchrone du
cache de trajet du nouveau gymnase, un seul itinéraire IGN) : `POST /api/opponents/{code}/venues`
(ajouter un gymnase au club, ref fédérale ou coordonnées choisies) ; `POST
/api/opponents/{code}/venue-links` (apparier un libellé orphelin — 422 si le libellé n'est celui
d'aucune rencontre AWAY réelle de cet adversaire) ; `PUT /api/opponents/venue-links/{id}`
(ré-apparier/fusionner — re-pointe le lien vers un autre gymnase, la réponse porte le compte
RÉSULTANT de la cible, « en portera N ») ; `DELETE /api/opponents/venue-links/{id}` (retire
l'appariement LOCAL, jamais le catalogue fédéral — décrémente le partagé si MANUAL). Les trois
gestes d'écriture partagent le limiteur SEC-19 `opponent_travel_manual` 30/h par utilisateur ; 404
byte-identique cross-club (un lien d'un autre club est invisible, RLS). `GET /api/opponents/travel`
groupe désormais **par CLUB adverse** (§ Écran Adversaires ci-dessous) et sert par gymnase
`travelStatus` (`done`/`pending`/`unavailable`, calculé serveur — § « Cache et calcul asynchrone »
ci-dessous) et `hasLogo` (§ Logo fédéral, `backend/docs/ffbb-api.md` §3bis).

**Prérequis du trajet AUTO — le siège du club doit être localisé.** Sans coordonnées sur `Club`, aucun
trajet ne se calcule (`OpponentTravelResolver::resolve` rend tout en `unresolved`). Le siège se pose
désormais depuis la fiche club (`PATCH /api/club/siege`, hors module matchs — voir
`backend/docs/geo-api.md` §1 et `frontend/docs/frontend-spec.md`) ; `GET /api/opponents/travel`
sert un champ additif `clubGeolocated` (booléen) que l'écran (`OpponentsPage`, `/matchs/adversaires`
depuis le 2026-09-19) lit via `useClubGeolocated()` pour afficher un bandeau « Trajets indisponibles :
l'adresse du siège du club n'est pas localisée. » avec un lien direct vers `/club?section=informations`
— « Mettre à jour les adversaires » reste utilisable (il localise quand même les gymnases adverses).

### Cache de trajets et calcul asynchrone (C4/C6, 2026-09-19)

Un trajet routier est une **CONSTANTE** (deux coordonnées + un profil) : `ClubTravelCache` (tenant,
RLS, club-scoped SANS saison — un trajet ne dépend d'aucune saison) le met en cache et ne le
recalcule **jamais**. Les 4 consommateurs IGN (résolveur de trajet adverse, auto-localisation,
matrice de gymnases, résolution simple) passent d'abord par ce cache. Détail modèle/seed/RGPD :
`backend/docs/geo-api.md` § Cache de trajets club-scoped, `docs/security/rgpd.md` §2.

Le calcul lui-même (rafale IGN pacée ~1 req/s) quitte le rail synchrone : `POST
/api/opponents/travel/resolve` **dispatche** au worker (réponse `{queued: true}`, plus
`{resolved: N}`) et répond immédiatement — la progression se lit via `travelStatus` (rafraîchi par
Mercure, topic `club:{clubId}:travel`, `docs/security/mercure.md`), jamais un spinner par ligne.
`resolve()` ne route plus que les PAIRES siège→gymnase MANQUANTES du cache (`OpponentTravelResolver::
pairsToRoute`, amendement PR I 2026-09-20 : un point par lien apparié + un point VILLE pour un code
sans lien — plus de notion d'équipe ni de ligne saison) : une paire déjà résolue n'est plus jamais
retouchée, « Réessayer les manquants » (§ Écran Adversaires) est littéralement ce même appel.
**Correctif de revue (`93f36c29`, 2026-09-21)** : le filtre amont ne portait que sur les codes
fédéraux (`distinctOpponentCodes`) — un gymnase épinglé sur un adversaire SANS code (clé sentinelle,
§ Écran Adversaires) restait donc exclu de ce recalcul, son trajet ne tenant que le chauffage
synchrone posé à l'épinglage (perdu sans rattrapage si l'IGN répond 429). `OpponentTravelResolver::
awayPairingKeys` (méthode SŒUR, code fédéral OU clé sentinelle — même maison que la projection et
le contrôleur) alimente désormais aussi ce filtre ; `distinctOpponentCodes` garde son contrat
« codes fédéraux seuls » pour ses autres appelants ; le repli VILLE (2) reste réservé aux codes
fédéraux (un sans-code n'a pas d'entrée d'annuaire fédérale à router en repli). Détail
worker/verrou/topic : `backend/docs/geo-api.md` § Calcul asynchrone.

### Logo fédéral d'un adversaire (C7, 2026-09-19)

`OpponentDirectoryEntry.logoId` (table GLOBALE) est posé sans coût réseau supplémentaire depuis les
hits organismes déjà résolus (canal `directVenue`, sans hit organisme, n'en pose pas — dette
`roadmap.md` P4-250) ; `GET /api/opponents/{code}/logo` (route MEMBRE, jamais publique) le
re-héberge paresseusement au premier accès. `hasLogo` (booléen additif de `GET
/api/opponents/travel`) pilote `shared/components/ui/opponent-logo.tsx` (rond, 16/24 px, repli
initiales) dans `AwayList` — **pas dans `ConflictLine`** (le côté d'un conflit ne porte pas le code
adverse) **ni dans la grille**. Détail : `backend/docs/ffbb-api.md` §3bis.

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

**La suppression d'une rencontre supprime ses entrées** (ouvertes ET fermées) : `fbi_correction` ne
porte aucune FK sur `fixture_id`, donc `FixtureStateProcessor::cascadeBeforeDelete` appelle
`FbiCorrectionLedger::removeForFixture` (cascade APPLICATIVE, pas base) avant le `remove()` du
parent — sinon une rencontre supprimée laisserait des lignes orphelines qui gonfleraient
`fbiTodo.toCorrect` sans plus jamais apparaître dans la liste. Suppression PURE (pas une fermeture) :
une rencontre disparue n'a plus rien à corriger. **Invariant d'appel** de `FbiCorrectionLedger::
open()` : au plus un appel par (rencontre, champ) et par cycle de flush — deux appels avant flush ne
se verraient pas l'un l'autre via `findOpen` (requête base) et violeraient l'index partiel unique.
Tenu par la dédup EN AMONT des deux canaux (import xlsx : garde `$seenInFile` par `team|ref` ;
canal API : garde `$consumed` par fixtureId), jamais par le ledger lui-même.

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
- `MATCH_MATCH` — deux fixtures d'équipes partageant une personne dont les fenêtres de **conflit**
  se chevauchent (`MatchFootprint::personConflictOccupancy`/`personConflictOccupancyAt`, § D1
  ci-dessous — échauffement retranché, trajet conservé). Gravité `pairSeverity` : dur (MAIN×MAIN,
  MAIN×PLAYER, PLAYER×PLAYER) = 3 ; un côté ASSISTANT = 5.
- `MATCH_TRAINING` — une fixture chevauchant un entraînement (lu dans le planning **effectif à la
  date du match** : overlay ACTIVE sinon version choisie du plan SEASON, `EffectiveScheduleResolver`)
  sur la MÊME fenêtre de conflit côté match. Gravité `trainingSeverity` (asymétrique) : match joué ×
  entraînement joué/coaché MAIN = 3 ; match coaché MAIN × entraînement où elle ne fait que jouer = 5 ;
  tout côté ASSISTANT = 5. L'entraînement de l'équipe qui joue CE match n'est **jamais** un conflit
  avec lui (ni coachs ni joueurs), quel que soit le gymnase — une équipe sœur reste couverte
  normalement.

**Chevauchement demi-ouvert** (créneaux jointifs = pas de conflit). Une empreinte qui passe minuit
est vérifiée sur les deux jours. Résolution déterministe par la période la plus ÉTROITE en cas de
chevauchement de périodes.

**D1 (échauffement salle seule) + lot M (échauffement hors des conflits de PERSONNE,
2026-09-21)** : la fenêtre SALLE (sans échauffement) sert `VENUE_OVERLAP`/`MATCH_SLOT_WINDOW` —
deux matchs enchaînés à 2 h d'écart ne collisionnent plus. **Fenêtre de conflit PERSONNE**
(`MatchFootprint::personConflictOccupancy`/`personConflictOccupancyAt`, occupation MOINS
l'échauffement, trajet AWAY conservé) : une personne engagée deux fois — joueuse OU coach, sans
distinction de rôle — n'est en conflit QUE si son arrivée dépasse le coup d'envoi du second
engagement, quel que soit le gymnase ; arrivée pile au coup d'envoi = pas de conflit (chevauchement
demi-ouvert). `MATCH_MATCH` et le côté match de `MATCH_TRAINING` testent le chevauchement sur cette
fenêtre ; les bornes SERVIES par côté (`windowStart`/`windowEnd`) restent la fenêtre PERSONNE
complète (échauffement inclus, § « Détail par côté » ci-dessous) — seul le TEST de chevauchement a
changé. ⚠ **Remplace l'exception « même gymnase » de septembre** (`effectiveMatchWindows`/
`sameHomeVenue`, supprimées avec leur code) : la règle générale la SUBSUME — à domicile il n'y a pas
de trajet, la fenêtre de conflit y vaut donc exactement l'ancienne fenêtre effective, preuve que les
six tests same-gym restaient verts sans une seule modification. Cas fondateur : une personne coache
à l'extérieur (retour estimé 19h17) et joue à domicile (coup d'envoi 19h30) — arrivée avant le coup
d'envoi, aucun conflit, même règle que pour un coach.

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
aucune) · 5 clash adouci +
`FRIENDLY_ON_MATCH_SLOT` (amical HOME placé sur un créneau de match — `reasons`:
`MATCH_SLOT_WINDOW`/`MATCH_WEEKEND`, samedi = clé du week-end, le vendredi ne compte jamais) · 6
`COMPETITION_INCOMPLETE` (compétitions APPARIÉES sous leur attendu, `expectedMatchdays` — jamais
pour une `CUP`) · 7 `AWAY_NO_FOOTPRINT` (angle mort nommé : extérieur sans heure ni habitude du bon
jour). Réponse : bornes datées en heure MURALE du club (jamais un offset).

**Détail par côté (`MATCH_MATCH`/`MATCH_TRAINING` seulement)** : `MatchConflictDetector::fixtureView`
sert quatre champs additifs par côté (`estimatedKickoffTime`, `travelOneWayMinutes` — `null` =
trajet non modélisé, `matchDurationMinutes`, `opponentLabel`) ; `opponentPlace` (ville de
l'adversaire, jamais un gymnase) est décoré EN AVAL par `FixtureConflictsController::
decorateOpponentPlace` sur les côtés AWAY seulement, via `OpponentPlaceResolver` (batch,
re-pointé sur le lien par l'amendement PR I 2026-09-20) : (1) le lien de la rencontre `(code,
libellé FBI normalisé)` → sa référence de salle fédérale → ville de la suggestion fédérale
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
le coup d'envoi, moitié après le match (**depuis le lot M, 2026-09-21** : plus d'échauffement dans
cette fenêtre, réplique exacte de `MatchFootprint::personConflictOccupancy`) — pour le protéger
pendant son déplacement. Absent/0 (adversaire sans trajet connu) ⇒ aucune extension. **SOFT
(golden-épinglés)** : conflit coach MAIN −60 · passerelle `NOT_SIMULTANEOUS` violée
−40 (⚠ **asymétrie délibérée depuis le lot M** : le radar §2 a cessé de signaler cette famille,
le solveur GARDE cette préférence souple — sens sûr, une pénalité SOFT ne bloque jamais rien, à ne
pas « aligner » en la retirant) · habitude heure +15/gymnase +5 · fenêtre habituelle protégée −25 ·
`BACK_TO_BACK` enchaîné +15 · coach ASSISTANT −10 · stabilité re-solve +8 · compactage −1/15 min de
trou. Les fenêtres coach et passerelle sont **toutes deux** sans échauffement depuis le lot M — à
domicile (candidats TO_PLACE, aucun trajet) la fenêtre personne vaut la fenêtre salle. La rotation A/B
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
- **Deep-link `match=<fixtureId>`** (patron « absent = défaut », `lib/urlState.ts`
  `decodeMatchParam`/`applyMatchToParams`) : au seed, la fixture visée est sélectionnée dans le
  store, sa semaine posée (si absente de l'URL), son masque levé (`revealPlan`) si elle est
  filtrée, puis focus + scroll sur sa cellule (`focusFixtureCell`) — la sélection (`ring-accent`)
  EST la mise en évidence, pas d'anneau temporisé ni de `role="status"`. Le paramètre est retiré en
  `replace` après consommation (one-shot). Posé par « Voir la semaine » depuis Conflits (§6).
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
- **Chips familles** (10 `ConflictType` **côté frontend**, toutes cochées par défaut) : compteur
  SAISON, à traiter seulement — une famille 100 % traitée garde sa chip, « · 0 » en sourdine. ⚠
  **Dérive partiellement corrigée** : le backend a cessé d'émettre `TEAM_LINK_OVERLAP` (lot M,
  2026-09-21, § « Détecteur de conflits » ci-dessus) ; le contrat public l'a suivi le même jour
  (`e881d748` — retiré de l'énumération OpenAPI `conflicts[].type` ET de `ConflictFingerprinter`,
  qui ne compte plus que 9 types possibles). **Reste ouvert, volontairement** : le frontend garde
  encore ses 10 clés `ConflictType` (`conflictLabels.ts`) — la chip « Passerelle » de l'onglet
  Conflits reste affichée et cochable alors qu'elle ne peut plus jamais se peupler ; un lot séparé
  reprend cet écran juste après ce lot, décision de ne pas l'entamer ici.
- **Puces « Traitement »** (À traiter · Dérogation demandée · Réglé en interne · Sans solution pour
  l'instant) et filtre « Seulement avec un match à domicile » (critère descriptif, pas « où je peux
  agir » — décision fermée). Ordre d'application : familles → traitement → domicile → pivot.
- **Accordéon par entrée** (une seule ouverte) rend `ConflictSeverityGroups`/`ConflictLine` — même
  maison que le radar du Calendrier, gravité 7 repliée derrière un compte, conflits triés par date
  croissante dans un groupe de gravité. Bouton « Voir la semaine » sur un conflit daté : sélectionne
  le côté GAUCHE par défaut, ou LE domicile si un seul des deux côtés joue à domicile (viser une
  case pleine de la grille plutôt qu'un extérieur masqué), navigue vers le Calendrier avec `match=`
  (§5). `ConflictLine` ne porte pas le logo de l'adversaire (§1 « Logo fédéral d'un adversaire ») —
  le côté d'un conflit ne porte pas son code organisme, décision de scope C7.

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
insensible à une réouverture externe du fichier entre analyse et import. Chaque libellé de salle
AWAY importé est auto-localisé (`OpponentVenueAutoLocator`, un des trois hooks d'appel, § « Table
TENANT `OpponentVenueLink` ») s'il matche UNE salle fédérale exacte ; sinon il reste « à apparier »
(§9 « Écran Adversaires ») — jamais deviné à l'import.

### « Validé ligue » en lot — démarrage en cours de saison (lot L, `LeagueValidatedFixturesController`)

Un club qui démarre l'application EN COURS de saison importe un fichier FBI dont les échéances sont
déjà passées : date, heure et gymnase sont déjà enregistrés côté fédération. Confirmer chaque
placement à la main n'a pas de sens — un geste SÉPARÉ, chiffré et confirmé les bascule d'un coup.
**Exception consentie**, pas une contradiction, à « une rencontre naît AVEC son gymnase mais jamais
placée d'office » (`FbiFixtureImporter::attachConfirmedVenue`) : le chemin de création de l'import ne
change pas, continue de créer en `UNPLACED` — le statut n'est **jamais** posé pendant l'import (le
compte ne peut pas être annoncé avant d'écrire, et le fondateur veut une confirmation chiffrée). C'est
CE geste, distinct, qui valide.

Deux routes `GET`/`POST /api/fixtures/league-validation` (management + saison écrivable + socle
pointé), même prédicat d'éligibilité maison unique (`isEligible`) : un domicile `UNPLACED` qui porte
une heure ET un `venueId` (gymnase identifié, jamais le libellé brut) et n'a aucun écart en attente.
**Pas de condition de date** — un domicile futur portant heure + gymnase est tout autant enregistré
côté fédération. GET rend le compte (bandeau + rapport, ci-dessous) ; POST applique et rend le nombre
réellement basculé. Application : statut `VALIDATED` (horodaté) + `placementSource` `MANUAL` — le
`MANUAL` n'est pas cosmétique, la formule du cadenas de la grille et l'ancre `FIXED` du solveur de
placement l'exigent (§3), sinon les rencontres basculées s'afficheraient déverrouillées alors que le
solveur les traite déjà en ancres. Rejouable : le prédicat exclut `VALIDATED`, une seconde application
ne trouve plus rien.

⚠ **Divergence ASSUMÉE avec le contrôle d'accès du geste unitaire** (revue `d60b3fc0`) : le placement
manuel d'un domicile refuse en 422 hors des créneaux d'accès match déclarés du gymnase
(`FixtureStateProcessor::assertVenueAccessAllowed`, §3) — `isEligible` ne fait PAS ce contrôle,
volontairement. La fédération a déjà enregistré cette réalité (date, heure, gymnase joués ou
programmés côté FBI) ; l'application la reflète au lieu de la nier. L'incohérence n'est pas tue :
le radar de conflits la signale (`ACCESS_WINDOW_LOST`, §2) — une rencontre basculée hors créneau
reste `VALIDATED` (le lot ne bloque jamais une réalité fédérale), le radar alerte. Figé par un NR
(`LeagueValidatedFixturesControllerTest`, cas hors créneau) : une passe future qui « corrigerait »
cet écart en y ajoutant le contrôle unitaire romprait le geste — c'est exactement le type de
divergence qu'un futur agent pourrait refermer par accident en croyant boucher un oubli.

**Isolation de saison, défense en profondeur (revue `d60b3fc0`)** : les deux routes refusent
désormais **explicitement en 409** si aucune saison ne se résout (`_season_id` absent), au lieu de
lire/écrire sur TOUTES les saisons du club via un `findBy([])` non scopé si le filtre Doctrine
venait à ne pas s'activer — aucun chemin d'exploitation trouvé en revue, mais le comportement est
maintenant explicite et visible côté API (409, pas un silence qui élargirait le périmètre). Le POST
refuse aussi en 409, message actionnable en français, sur une collision d'écriture simultanée
(verrou optimiste `Fixture`, deux onglets ou un double-clic) — aucun verrou ajouté, l'écriture reste
idempotente.

Deux points d'ancrage écran, même confirmation chiffrée partagée (`LeagueValidationConfirmDialog`) —
refuser n'écrit rien : un bandeau de rattrapage sur l'onglet Importer (`LeagueValidationBanner`,
muet à 0 — couvre donc aussi les rencontres déjà en base sans re-déposer de fichier) et une section du
rapport de fin d'import (`LeagueValidationReportEntry`, même règle). Le front n'a aucun prédicat
d'éligibilité : il affiche le compte servi par le backend. Ajout du fondateur : un renvoi permanent
vers les « Échéances de saisie » (`EntryDeadlinesLink`, deep-link `?section=echeances`) sur l'onglet
Importer, pour les renseigner sans détour. Vocabulaire : la pastille de statut ne change pas (`VALIDATED`
reste « Attesté FBI », §7 « Workflow de traitement ») — seule cette confirmation emploie les mots du
fondateur, « validé ligue ».

**`VALIDATED` n'est PLUS un cul-de-sac (décision fondateur, revue `d60b3fc0`)** — corrige une
affirmation fausse portée par le code d'origine (commentaire « VALIDATED is fully read-only — the
league owns it. ») et par le texte affiché (« Attesté par FBI : … »), qui ne décrivait que le
chemin de réconciliation D9 et non celui du lot L. `PlacementPanel` offre désormais **« Corriger —
repasser en Placé »** sur `VALIDATED` comme sur `SUBMITTED` — quel que soit le CHEMIN qui a posé
`VALIDATED` (réconciliation D9 OU bascule en lot du lot L). Raison : une bascule en lot peut porter
sur des centaines de rencontres d'un coup, dont le gymnase peut venir d'un appariement AUTOMATIQUE
(§1, `OpponentVenueAutoLocator`) — sans sortie, un mauvais appariement les aurait toutes figées, la
seule échappatoire étant de supprimer le gymnase. Le texte affiché sous la pastille a changé en
conséquence, générique aux deux chemins (« Ce match est ancré sur les date, heure et salle
enregistrées côté ligue. Corrigez-le si l'une d'elles doit changer… »), sans plus attribuer
l'ancrage au seul fichier fédéral.

### Canal API FFBB (à la demande, `FfbbRencontreReconciler`)

FBI (xlsx) fait foi, l'API est un confort — bandeau d'honnêteté à chaque ouverture. Appariement à 3
étages + tier-0 d'idempotence (id national déjà connu) : compétition appariée pour UNE seule équipe
→ suggère l'équipe ; date exacte ; adversaire normalisé. Une coupe non appariée devient une vraie
`Competition` `CUP` (le libellé fédéral tranche, jamais l'absence d'appariement) — seul le token
`amical` laisse `competitionId` null. Les rencontres publiées sans fixture correspondante sont
**proposées, jamais imposées** (`TeamSelect` par ligne, rien créé si vide) via la vue dédiée
`/matchs/reconciliation` (zéro état serveur, payload en mémoire, renvoi propre sans payload), triées
côté front **date croissante · heure croissante (une heure absente en dernier de son jour) ·
adversaire en collation française** (`lib/creatableSort.ts`, appliqué une fois à la source — la
liste ET la dérivation `creations` héritent du même ordre) plutôt que l'ordre brut du backend.
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

Réglages de saison RARES, quatre `AccordionSection` contrôlées (une seule ouverte, défaut **tout
replié**), ancrées `?section=<clé>` : Échéances de saisie (§4) · Durée des matchs (`sport_category`
par famille, table partagée) · Accès match (fenêtres `VenueMatchWindow`, modale par gymnase — même
éditeur que le wizard) · Libellés FFBB des gymnases (écran d'appariement, ci-dessous). Un résumé
discret dans le nom accessible de chaque bouton (`configSummaries.ts`, fonctions pures — comptent ce
que le backend a déjà calculé, jamais une règle métier recalculée) ; une lecture en échec rend
`null`, jamais un « 0 » fabriqué. **Les adversaires ont leur propre onglet depuis le 2026-09-19**
(§ Écran Adversaires, `/matchs/adversaires`) — l'ancien deep-link `?section=adversaires` de cet
écran redirige.

**Écran d'appariement des libellés** (`VenueLabelsSection`) : une ligne par LIBELLÉ de l'inventaire
(`GET /api/venues/fbi-labels`), `VenueSelect` dont la valeur effective est le gymnase confirmé sinon
la suggestion « d'après les rencontres » pré-sélectionnée. Trois gestes : **Confirmer** (additif,
sans confirmation) ; **Réaffecter** (`reassign: true` — corrige un alias posé sur le mauvais
gymnase en un geste, re-pointe les non placés, épargne toujours les placés, `ConfirmDialog` nommant
ce qui bouge) ; **Retirer** (ne touche aucune rencontre déjà rattachée). Un signal partagé
(`UnpairedVenueLabelsBanner`) renvoie vers cet écran unique depuis Importer et le Calendrier —
décision fermée (une seule maison d'appariement, jamais une modale sur une modale).

## 9. Écran Adversaires (`/matchs/adversaires`, C8 2026-09-19, refondu au grain GYMNASE par PR I 2026-09-20)

Localisation + trajets vers les adversaires, page sœur dédiée entre Configuration et Semaine type
dans la nav (`MatchesLayout.tsx`). `OpponentsPage` rend la liste LUI-MÊME (`OpponentTravelCard` a
disparu, absorbée) en `Table` : un `<tbody>` PAR CLUB adverse — ligne club (`<th scope="row">`,
son nom, puis « Ajouter un gymnase » ; un club sans aucun lien affiche « Aucun gymnase connu »),
puis une ligne par GYMNASE apparié (`OpponentVenueLink`, `SourceBadge` AUTO/MANUEL), puis les
libellés de fichier encore « à apparier » dans le MÊME rowgroup que leur club (bouton
« Apparier »). Colonnes ≥ `@md` (container query, repli sous 360 px — Trajet et Rencontres se
replient en sous-ligne) : logo (`OpponentLogo`, § Logo fédéral d'un adversaire, sur la ligne club
seulement) · gymnase (label + `StatusPill` « ville seule » si un club sans lien n'a qu'une
précision `CITY`) · trajet (`TravelMinutes`, fragment extrait d'`AwayTravelChip`, « en cours… »/
« indisponible » selon `travelStatus`) · rencontres (`fixtureCount` servi par ligne — le compte
RÉEL de rencontres qui résolvent vers CE gymnase, jamais re-dérivé) · action. Tri : les clubs SANS
gymnase d'abord, puis alphabétique (fr). **Rang gelé au montage** (lot K, décision fondateur
2026-09-20) : le front fige cet ordre backend à la première liste non vide (`OpponentsPage.tsx`,
un `setState` posé PENDANT le rendu — patron React « stocker une info des rendus précédents »,
pas un effet) pour qu'un club tout juste apparié ne saute plus de place sous le curseur ; quitter
l'onglet et y revenir recalcule (assumé). Un club apparu après le gel s'ajoute en fin, rang
« infini ».

**Gestes d'appariement** passent par le menu APG partagé sur chaque ligne gymnase (« Fusionner
dans « X » » — vers un AUTRE gymnase du MÊME club, `PUT /api/opponents/venue-links/{id}` ;
« Retirer ce gymnase » — `DELETE`) et une `ConfirmDialog` dont le texte vient de la donnée SERVIE
(`fallbackVenueName`, `fixtureCount` — le front n'invente aucune règle métier, §
`.claude/rules/frontend.md`). « Ajouter un gymnase »/« Apparier » (désormais **inconditionnels**,
lot K — voir ci-dessous) ouvrent `LocateOpponentModal` (recherche `/api/ffbb/salles`, écrit via
`POST /{code}/venues` ou `POST /{code}/venue-links`).
**PR J (2026-09-20)** : la recherche part **préfiltrée par le code postal fédéral de
l'adversaire** (`OpponentClub.postalCode`, additif servi par `GET /api/opponents/travel`) — le
gestionnaire n'a plus à ressaisir un CP déjà connu ; un sous-titre situe le club (« · Brignais
69530 »), jamais inventé quand ville/CP sont absents de l'annuaire fédéral.

**Adversaires SANS code fédéral (lot K, 2026-09-20/21).** Un adversaire sans code (amical saisi à
la main, coupe non appariée) n'avait jusque-là aucune clé d'appariement — ses rencontres
restaient hors du trajet et les deux boutons ci-dessus étaient absents (`club.code !== null`
gardait le geste). `App\Service\OpponentPairingKey` (maison unique) dérive une clé SENTINELLE
stable de son libellé normalisé (`'X' + sha256(libellé)[0:40]`, 41 caractères alphanumériques —
passe la borne `[A-Za-z0-9]+` des routes `/api/opponents/{code}/…`, tient dans le `VARCHAR(64)`
existant, **zéro migration**) quand le code fédéral est absent. `GET /api/opponents/travel` sert
cette clé (`pairingKey`) que le front réutilise TELLE QUELLE dans les routes d'écriture — il ne la
redérive jamais (🔴 `.claude/rules/frontend.md`). La sentinelle reste strictement LOCALE au club :
la garde qui neutralise toute ref fédérale pour une clé sentinelle vit ENTIÈREMENT dans la MAISON
UNIQUE `OpponentVenueLinkManager::writeGym` (AVANT toute résolution fédérale, `resolveFederalVenue`
jamais appelé pour une sentinelle) — le contrôleur ne porte **aucun** filtre propre, POST comme PUT.
Défaut trouvé en revue de sécurité (`93f36c29`, 2026-09-21) : la garde initiale ne couvrait que le
POST d'ajout, laissant le PUT de ré-appariement/fusion passer la ref brute du client et créditer le
catalogue partagé sous un code organisme inexistant. Le contrôleur a d'abord gagné un filtre
IDENTIQUE en défense en profondeur, puis ce filtre a été **retiré** (`5bfe2f92`, même jour) : une
garde dupliquée sur le même chemin d'écriture court-circuitait le manager AVANT qu'il soit atteint,
rendant sa propre garde intestable par ce chemin — le NR de sécurité restait vert les DEUX gardes
désactivées, parce que la référence de test envoyée ne résolvait fédéralement dans AUCUN des deux
cas (payload qui ne credite jamais, garde ou pas). Corrigé en deux temps : une référence
RÉELLEMENT servie par le stub FFBB remplace celle qui ne résolvait jamais, et le contrôleur cède
toute la garde au manager — seul foyer testable sur POST comme PUT. L'appariement reste tenant
seul, jamais crédité au catalogue partagé `opponent_venue_suggestion` (falsifié par
`OpponentVenueSuggestionShareTest`, rouge sur la garde du manager désactivée — POST et PUT).
`LocateOpponentModal` remplace alors la section
« Gymnases connus » (le partagé fédéral n'a rien à proposer pour une sentinelle) par une phrase
d'explication : le choix restera propre au club.

**La modale enchaîne les libellés orphelins (lot K, 2026-09-20).** Après un appariement réussi,
`LocateOpponentModal` retire le libellé apparié d'une file LOCALE (figée à l'ouverture — le
libellé cliqué en tête puis les autres orphelins du même club) et avance au suivant, code postal
et liste de salles déjà chargée restent en place ; footer « Terminer » remplace « Fermer ». File
vidée → fermeture, comme avant. Le mode « Ajouter un gymnase » (pas de file, `fbiLabel` null)
garde la fermeture immédiate au succès.

**Recherche par nom (lot K, 2026-09-20).** `GET /api/ffbb/salles` accepte `q` (≥ 3 caractères) en
alternative à `postalCode` — plein-texte fédéral sur l'index `ffbbserver_salles`
(`FfbbApiClient::searchSallesByName`, détail + sonde réseau réelle :
[`../../backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) § « Salles d'une commune »). La
modale gagne un champ « Nom du gymnase » à côté du code postal, débounced (300 ms) ; une recherche
par nom active prend la main sur le code postal. Même repli côté **auto-appariement** :
`OpponentVenueAutoLocator` retente par NOM (égalité stricte, un seul hit retenu) quand la voie
commune/rayon ne rend pas un match unique — le comptage `ambiguous`/`unmatched` de la voie commune
ne bouge pas pour ce repli.

**Filtre segmenté** `role="group"` `aria-pressed` — **Sans gymnase · À apparier · Tous** — état URL
`?filtre=` (`lib/urlState.ts`), deux unités de compte distinctes (clubs pour « Sans gymnase »,
salles pour « À apparier », nommées par l'`aria-label`, jamais par le seul chiffre visible).
Recherche instantanée (club, gymnase, libellé non apparié) combinée au filtre. **Progression** :
région `aria-live="polite"` à deux phrases stables + compteur chiffré frère, dérivée de
`travelStatus` (rafraîchi par Mercure via `useTravelStream`, § Cache de trajets et calcul
asynchrone) ; bouton « Mettre à jour » désactivé pendant le calcul, aucun spinner par ligne.
**Échec partiel** : `WarningPanel` « n trajets n'ont pas pu être calculés » + bouton « Réessayer
les manquants » (`POST /api/opponents/travel/resolve`, qui ne route déjà que les paires siège→
gymnase manquantes). Bandeau siège (`useClubGeolocated`, § Prérequis du trajet AUTO) réutilisé tel
quel. **Revue sécurité H (2026-09-19)** : si un calcul de trajets tourne déjà pour le club (verrou
tenu), « Réessayer les manquants »/« Mettre à jour » ne redispatche rien — toast « Un calcul de
trajets est déjà en cours. » (`alreadyRunning` servi par la route, § Cache de trajets et calcul
asynchrone).

## 10. Écran Semaine type (`/matchs/semaine-type`)

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

## 11. Le périmètre engagé (`TeamEngagementGuard`)

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

## 12. Tests & gardes (pointeurs)

NR tenant isolation bloquant : `MatchTenantIsolationTest` (Competition/Fixture/fenêtres/indispos/
habitudes/passerelles/`opponent_venue_link`/`club_travel_cache`/`ConflictResolution` — étendu à chaque
table tenant du module). **NR tenant GATANT (C6, step nommé `blocking-tests` de `ci.yml` +
`docs/testing/blocking-tests.md`)** : `MessageHandler/ComputeTravelTimesHandlerTest` (GUC posé/
clear, verrou, jamais la ligne d'un autre club). Cache de trajets : `TravelTimeCacheTest` (clé
arrondie, jamais recalculé, profils séparés), `ClubTravelCacheSeedTest` (rejoue le seed de la
migration verbatim, zéro dérive). Client IGN : `IgnRoutingClientTest` (pacing 1/s, 429 réessayé,
journalisé). Progression Mercure : `TravelProgressPublisherTest`. Logo fédéral :
`OpponentLogoApiTest` (401 anonyme, 404, MIME, Cache-Control, code invalide). Contrats cross-stack
(groupe `contract`) : `MatchPlacementContractSchemaTest`,
`ValidateAssignmentsContractSchemaTest`, `SlotRotationPayloadParityTest`,
`MatchVisitDeltaParityTest`. Tables partagées : un `*ShareTest` par table (`OpponentDirectoryShareTest`
— whitelist `logo_id` compris —, `OpponentVenueSuggestionShareTest`, `EntryDeadlineShareTest`).
Périmètre engagé : `EngagedTeamGuardTest`,
`DeletionImpactParityTest`. Détecteur/radar : `MatchConflictDetectorTest`,
`FixtureConflictsApiTest` + feature Behat `les-conflits-d-un-match-disent-la-verite.feature`
(`ConflictTruthContext`, D1/D1 étendu, statuts joue/coache). Solveur : `test_match_placement*.py` (unit, sémantique, golden épinglé)
+ feature Behat `backend/features/placement-des-matchs.feature`. Import/réconciliation :
`FbiFixtureImporterTest`, `FfbbRencontresApiTest`, `FixtureReviewApiTest` + features Behat dédiées
(`un-domicile-importe-retrouve-son-gymnase`, `le-gymnase-du-fichier-localise-l-adversaire`,
`les-gymnases-d-un-adversaire-se-partagent-en-suggestions`, `un-conflit-traite-reste-visible-mais-decompte`,
`une-rencontre-importee-dit-si-elle-est-traitee`). « Validé ligue » en lot (lot L) :
`LeagueValidatedFixturesControllerTest` (prédicat, idempotence, guards, verrou optimiste,
`testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt` — la divergence ASSUMÉE avec
le contrôle d'accès unitaire, figée), `MatchTenantIsolationTest` (étendu — club B éligible, club A
lit 0 et bascule 0) + feature Behat `un-club-en-cours-de-saison-valide-ses-matchs-en-lot.feature`
(`LeagueValidationContext`). Registre « à corriger dans FBI » :
`FbiCorrectionApiTest` (lecture/close/reopen, 404 cross-club, 403 membre sur close),
`MatchTenantIsolationTest` (étendu) + feature Behat `ce-que-fbi-doit-refleter.feature`
(`FbiCorrectionContext`, suite `fbi-a-corriger`). Front : suites Vitest sous
`frontend/src/features/matches/` (`lib/*.test.ts` pour chaque dérivation pure, `*.test.tsx` par
écran) + e2e `frontend/tests/e2e/matches*.spec.ts`.
