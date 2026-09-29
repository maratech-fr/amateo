# Module matchs (FFBB) — état courant

Last verified @ 2026-09-29 (`documentation-update`, P4-272 ④ — interdiction de gymnase par équipe :
`MatchConstraint` (`backend/src/Entity/MatchConstraint.php`, scope TEAM — `scopeTargetId`/`venueId`
obligatoires et DU CLUB, `ruleType` HARD seulement) ⇄ `teams[].forbiddenVenueIds` du payload
`/place-matches` (`match_input_schema.py:125`), domaine vidé par un gymnase interdit alors qu'un
créneau y était licite → raison `team_venue_forbidden` (`match_placement.py:284`), précédence sur
`club_rule_no_slot` ; radar `TEAM_VENUE_FORBIDDEN` sévérité 3, même gravité que
`CLUB_RULE_VIOLATION` (`MatchConflictDetector::teamVenueForbiddenConflicts`,
`MatchConflictDetector.php:753`) ; cascade suppression équipe/gymnase (`CascadePlan.php`) et
recopie N+1 avec remap équipe+gymnase (`SeasonTransitionService.php`) confrontées au code ✓. Reste
du contenu (P4-240 et antérieur) non réaudité cette passe. Historique :
`git log -p --follow specs/courantes/module-matchs.md`.

> **Règle de forme** : ce fichier décrit **l'état courant, par écran** — jamais une section datée
> d'une PR. Le JOURNAL (qui a livré quoi, quand, sous quel id) vit dans
> [`etat-des-lieux.md`](etat-des-lieux.md) §1.5 (carte) et §3 (traces datées) ; les **décisions
> fermées** (tranchées contre une option évidente) vivent dans son §2 — ce fichier n'en reproduit
> que le résultat, jamais le débat. But de taille : décrire l'état courant sans historiser — si une
> phrase commence par une date ou un id de PR, elle appartient à l'état des lieux, pas ici.

Le module vit dans `frontend/src/features/matches/` (nav `MatchesLayout`, sous `/matchs` :
`index` = **route d'atterrissage conditionnelle** (`MatchesLanding` → Calendrier, ou renvoi sur
Conflits s'il y a des conflits à traiter — § Écran Calendrier), `conflits`, `importer`,
`configuration`, `contraintes` (§ Écran Contraintes, section Ligue éditable — sections Club/
Équipes/Coachs à venir), `adversaires` (§ Écran Adversaires, onglet propre — plus un deep-link de
Configuration), `semaine-type`, `consulter` en redirection permanente vers l'index, `reconciliation` accessible
seulement depuis le canal API, `frontend/src/app/routes.tsx:141-186`) et dans les services backend `Match*`/`Fixture*`/
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
- **`ClubLeagueWindow`** (clé `(club, saison)`, colonnes métier identiques au catalogue GLOBAL
  `LeagueMatchWindow` ci-dessous) : la COPIE, propre au club, de l'enveloppe de fenêtres de coup
  d'envoi de la ligue — MAISON UNIQUE lue par le placement (`MatchPlacementPayloadBuilder`), le
  radar (`ConflictRadarLoader`) et `GET /api/league-match-windows` (interface commune
  `LeagueWindowInterface`, `LeagueEnvelopeResolver` inchangé). Seedée depuis la ligue EFFECTIVE du
  club à la naissance (`ClubProvisioner`), à la bascule de saison (`SeasonTransitionService` —
  recopie la copie de la saison SOURCE, ne retombe sur le catalogue que si cette source est vide)
  et par une migration de backfill pour les clubs déjà existants (`Version20260928140000`, saisons
  active/brouillon seulement). Éditable par le gestionnaire (CRUD `ClubLeagueWindowResource`,
  section Ligue de l'écran Contraintes, § « Écran Contraintes » ci-dessous) — badge `added`/
  `modified` calculé SERVEUR par clé naturelle vs le seed. **Copie VIDE = zéro règle fédérale** :
  aucun HARD ligue au placement (§3), un seul diagnostic INFO `league_envelope_empty` pour tout le
  club (jamais un par équipe) ; une pose manuelle hors fenêtre reste PERMISE et SIGNALÉE (§5), le
  radar continue de porter `LEAGUE_WINDOW_VIOLATION` sur une copie non vide.
- **`MatchConstraint`** (table `match_constraint`, P4-272 ③+④) : une RÈGLE DE MATCH — `scope`
  `ConstraintScope` tranche la FORME (`CLUB` et `TEAM` sont saisis ; `COACH`/`FACILITY` réservés à
  ⑤), `ruleType` `ConstraintRuleType`, `daysOfWeek` (ISO 1=lundi..7=dimanche) et une fourchette de
  coup d'envoi `kickoffMin`/`kickoffMax` (HH:MM). Deux formes :
  - **CLUB** (③) : `scopeTargetId`/`venueId` nuls, `daysOfWeek` + fourchette portent la règle
    (« pas après 21h le samedi ») — HARD (honorée par le solveur, le coup d'envoi doit tomber dans
    la fourchette les jours couverts) ou PREFERRED (pénalité `W_CLUB_RULE=30`, le solveur l'évite
    sans jamais bloquer). Le serveur refuse une règle sans AUCUNE des deux bornes ni sans AUCUN jour
    (`MatchConstraintStateProcessor::applyClubRule`).
  - **TEAM** (④, interdiction de gymnase) : `scopeTargetId` = l'équipe, `venueId` = le gymnase
    INTERDIT, tous deux OBLIGATOIRES et DU CLUB (lookup tenant-filtré `findOneBy`, jamais `find()`
    qui sert l'identity map et saute les filtres — étrangère/inconnue → 422) ; `ruleType` HARD
    SEULEMENT (PREFERRED refusé — la préférence de gymnase reste l'habitude de la semaine type,
    §10) ; `daysOfWeek`/fourchette NON PERTINENTS (l'interdiction vaut tous les jours à toute
    heure), refusés s'ils sont renseignés, forcés à vide/null
    (`MatchConstraintStateProcessor::applyTeamVenueBan`).

  Aucune unicité en base (plusieurs règles peuvent se recouvrir, une équipe peut s'interdire
  plusieurs gymnases ; ⑤ en aura besoin aussi). CRUD gestionnaire (`GET`/`POST`/`PUT`/
  `DELETE /api/match_constraints`), sections Club et Équipes de l'écran Contraintes (§8bis). Le
  moteur reçoit les règles CLUB VERBATIM dans le bloc top-level `clubRules`, les interdictions TEAM
  dans `teams[].forbiddenVenueIds` (liste triée, déterministe) du payload `/place-matches`
  (`CONTRACT_VERSION` 2.27) — un domaine vidé par les seules règles CLUB HARD ressort `club_rule_
  no_slot`, un domaine vidé par un gymnase interdit alors qu'un créneau licite y existait ressort
  `team_venue_forbidden` (précédence sur `club_rule_no_slot`, §3) ; les amicaux (`competitionId`
  nul) en sont exemptés structurellement, comme l'enveloppe ligue. Une pose MANUELLE hors d'une
  règle CLUB HARD ou dans un gymnase interdit à l'équipe reste PERMISE — le radar la SIGNALE
  (`CLUB_RULE_VIOLATION`/`TEAM_VENUE_FORBIDDEN`, §2), il ne la bloque pas. L'**alerte de cohérence**
  (lecture seule, rien stocké) croise chaque règle CLUB avec les créneaux idéaux (`TeamMatchHabit`)
  qu'elle heurte (`ClubRuleCoherenceChecker`, `GET /api/match-constraints/coherence`, section Club
  seulement — les interdictions TEAM n'y entrent pas), affichée sous la règle en section Club
  (§8bis) ET sous le créneau idéal concerné en Semaine type (§10) — ne bloque jamais rien, le
  gestionnaire décide. **Cascade suppression** : une équipe ou un gymnase supprimé emporte les
  interdictions TEAM qui le visent (`scopeTargetId`/`venueId`, `CascadePlan::forTeam`/`forVenue`),
  annoncée dans la modale d'impact (§ `deletion-impact`, `backend-inventory.md`) ; une règle CLUB ne
  porte ni l'un ni l'autre, jamais concernée par ces deux étapes.
- **`TeamLink`** (couple symétrique `teamAId < teamBId`, cap `MAX_TEAM_LINKS = 50`) : côté MATCHS
  `TeamLinkType` `NOT_SIMULTANEOUS`/`BACK_TO_BACK` — rail SOFT **placement seul** ; le radar de
  conflits ne charge jamais `TeamLink` (décision fermée, `etat-des-lieux.md` §2 : « ça fait plus de
  bruit qu'autre chose » côté radar) — § « Détecteur de conflits » ci-dessous ; côté ENTRAÎNEMENT
  `TeamLinkIntensity` `PREFERRED`/`MANDATORY` (honoré par le solveur d'entraînement — arbitrage :
  cette intensité ne gouverne jamais les matchs, `engine/docs/constraint-vocabulary.md` §Passerelles).
- **`TeamMatchHabit.week`** (`MatchWeek` `A`\|`B`\|`ALL`, P4-271) : le créneau idéal (ci-dessus)
  porte un tag de semaine d'alternance — **AIDE VISUELLE**, jamais une contrainte : il alimente la
  vue A/B de l'écran Semaine type (§10) côté frontend seulement, il **ne voyage jamais au moteur**
  (le solveur voit une habitude, sans étiquette de semaine). Remplace l'ex-`MatchSlotRotation` +
  `MatchSlotRotationTeam` (créneau physique partagé par N équipes en alternance ordonnée, retirés
  par P4-271 — deux équipes qui alternent sur le même créneau déclarent désormais chacune LEUR
  créneau idéal, tagué A ou B). L'habitude alimente aussi le solveur d'ENTRAÎNEMENT : `Team.matchDay`
  (`ScheduleConstraintBuilder::deriveMatchDay`, `POST /generate`) émet le DERNIER jour ISO de match
  de la semaine — le repos qui compte est celui d'après lui (`rest_day = match_day % 7 + 1`,
  `engine/app/solver/objective/terms.py`) — pour le bonus SOFT « jour de repos après un match ».
  Sans habitude, repli sur le champ déclaré `Team.matchDay` (0-based, converti en ISO à l'émission).
- **`VenueMatchWindow`** (jour ISO + plage horaire, gymnase = « de match » ssi ≥ 1 fenêtre — aucun
  booléen sur `Venue`) et **`VenueUnavailability`** (plage de dates + motif, toutes circonstances,
  alerte seulement — jamais recopiée en N+1).
- **`ConflictResolution`** (clé `(club, saison, fingerprint)`, l'empreinte STABLE d'un conflit) :
  statut de traitement — voir §6.
- **`MatchModuleVisit`** (clé `(club, saison, user)`) : référence de visite pour le delta — voir §8.
- **`FbiIngestion`** (`club+saison`, non personnelle) : fraîcheur + compteurs d'un dépôt, une par
  canal (`FBI_XLSX`/`FFBB_API`).
- `Venue.externalLabels` (JSON normalisé/dédupliqué) : alias FBI/FFBB confirmés — voir §5.3.

Recopie en N+1 (`SeasonTransitionService`) : habitudes (créneau idéal + tag `week` remap
équipe+gymnase), passerelles, fenêtres d'accès, la copie club de l'enveloppe ligue
(`ClubLeagueWindow`, verbatim depuis la saison source), les règles de match (`MatchConstraint`,
P4-272 ③+④) — CLUB : verbatim (ni gymnase ni équipe à remapper, `scopeTargetId`/`venueId` restent
nuls) ; TEAM (interdiction de gymnase) : remap de l'équipe ET du gymnase (mêmes tables de
correspondance que les habitudes) — une référence PENDANTE d'un des deux côtés (équipe ou gymnase
disparu en N+1) fait ABANDONNER la ligne, jamais un pointeur mort en base ; COACH/FACILITY (⑤) pas
encore émis, une ligne héritée d'un état antérieur ne se propage pas. Les indisponibilités et les
échéances **ne sont jamais recopiées**.

### Tables GLOBALES fédérales (hors tenant, hors RLS)

Patron commun : keyées sur un identifiant fédéral public, **aucune colonne club/user-identifiante**,
GRANT `SELECT/INSERT/UPDATE` **sans `DELETE`** (une ligne retombée à 0 reste). Gardées par un test
de schéma dédié par table (`*ShareTest`, liste blanche exacte + byte-identique quel que soit le
club lecteur).

- **`LeagueMatchWindow`** : le catalogue fédéral de référence, par `league × category × level ×
  gender` — seedé depuis `backend/data/league-match-windows.aura.json`, ligue dérivée du
  `ffbbClubCode` (`LeagueResolver`). Ne sert plus qu'à SEMER la copie club (`ClubLeagueWindow`
  ci-dessus) et le repli fédéral de la suggestion (§8bis) — le placement, le radar et
  `GET /api/league-match-windows` lisent tous la copie, plus jamais ce catalogue directement.
  **Chargement initial garanti par migration** (`Version20260929130000`, P4-272 ②) : là où le
  catalogue est resté vide (base neuve, dev jamais seedé), `doctrine:migrations:migrate` seul le
  charge depuis le JSON puis recopie chaque club×saison sans copie — avant cette migration, seul
  `make play`/la commande `app:league-windows:seed` le peuplaient, donc un déploiement ou une CI
  qui ne lance que les migrations héritait d'un catalogue et d'une copie VIDES. Anti-résurrection :
  ne joue que si le catalogue était vide AU DÉPART (une copie déjà vidée par un gestionnaire n'est
  jamais ressuscitée). La commande `app:league-windows:seed` reste le geste de RAFRAÎCHISSEMENT du
  catalogue (`backend/docs/commands.md`).
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
  code organisme, ref)`** : `OpponentVenueLinkManager` ne crédite un
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

### Table TENANT `OpponentVenueLink`

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
Trois colonnes NULLABLES additionnelles — `address`/`postalCode`/`city` — portent l'adresse
POSTALE d'AFFICHAGE du gymnase (fiche lecture seule d'un match à l'extérieur, `AwayFixtureCard`
ci-dessous) : posées à l'appariement (recherche FFBB), au choix d'une suggestion ou par
l'auto-localisation quand le hit fédéral les porte ; un lien EXISTANT avant leur introduction reste
à `NULL` (pas de rattrapage — la fiche montre alors le libellé seul, jamais une ligne vide) ; jamais
recopiées dans le catalogue PARTAGÉ `OpponentVenueSuggestion` (donnée propre au lien tenant).
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
upsert`) est un `INSERT … ON CONFLICT (ffbb_organisme_code) DO UPDATE` natif : deux noms
d'observation différents qui résolvent le MÊME code fédéral dans un seul lot fusionnent en une
ligne au lieu d'un double `persist` applicatif qui violerait l'unicité et **fermerait
l'`EntityManager`** (les passes/imports suivants du même appel se retrouveraient silencieusement
avalés, réponse 200 vide). Chaque passe qui lève est isolée
(`OpponentRefreshController::step`) et s'inscrit dans un champ additif `failedSteps` — le front dit
franchement « mise à jour interrompue à l'étape … » plutôt qu'un succès mensonger ; volontairement
**pas** de `resetManager()` (dette assumée : `roadmap.md` P4-247). **Les deux AUTRES hooks d'appel**
(import xlsx `ImportFixturesController`, apply du canal API `FfbbRencontresController`, § « Écran
Importer ») calculent chacun leur propre `deadline` (`VENUE_AUTOLOCATE_BUDGET_SECONDS = 30`
secondes, même patron best-effort que l'orchestrateur — dépassement = arrêt propre des libellés
restants, jamais un import ou un apply cassé) — le repli par nom (ci-dessus) double sinon un
fan-out sortant non borné à ces deux hooks.

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
groupe **par CLUB adverse** (§ Écran Adversaires ci-dessous) et sert par gymnase
`travelStatus` (`done`/`pending`/`unavailable`, calculé serveur — § « Cache et calcul asynchrone »
ci-dessous) et `hasLogo` (§ Logo fédéral, `backend/docs/ffbb-api.md` §3bis).

**Prérequis du trajet AUTO — le siège du club doit être localisé.** Sans coordonnées sur `Club`, aucun
trajet ne se calcule (`OpponentTravelResolver::resolve` rend tout en `unresolved`). Le siège se pose
depuis la fiche club (`PATCH /api/club/siege`, hors module matchs — voir
`backend/docs/geo-api.md` §1 et `frontend/docs/frontend-spec.md`) ; `GET /api/opponents/travel`
sert un champ additif `clubGeolocated` (booléen) que l'écran (`OpponentsPage`, `/matchs/adversaires`)
lit via `useClubGeolocated()` pour afficher un bandeau « Trajets indisponibles :
l'adresse du siège du club n'est pas localisée. » avec un lien direct vers `/club?section=informations`
— « Mettre à jour les adversaires » reste utilisable (il localise quand même les gymnases adverses).

### Cache de trajets et calcul asynchrone

Un trajet routier est une **CONSTANTE** (deux coordonnées + un profil) : `ClubTravelCache` (tenant,
RLS, club-scoped SANS saison — un trajet ne dépend d'aucune saison) le met en cache et ne le
recalcule **jamais**. Les 4 consommateurs IGN (résolveur de trajet adverse, auto-localisation,
matrice de gymnases, résolution simple) passent d'abord par ce cache. Détail modèle/seed/RGPD :
`backend/docs/geo-api.md` § Cache de trajets club-scoped, `docs/security/rgpd.md` §2.

Le calcul lui-même (rafale IGN pacée ~1 req/s) quitte le rail synchrone : `POST
/api/opponents/travel/resolve` **dispatche** au worker (réponse `{queued: true}`, plus
`{resolved: N}`) et répond immédiatement — la progression se lit via `travelStatus` (rafraîchi par
Mercure, topic `club:{clubId}:travel`, `docs/security/mercure.md`), jamais un spinner par ligne.
`resolve()` ne route que les PAIRES siège→gymnase MANQUANTES du cache (`OpponentTravelResolver::
pairsToRoute` : un point par lien apparié + un point VILLE pour un code sans lien — aucune notion
d'équipe ni de ligne saison) : une paire déjà résolue n'est plus jamais retouchée, « Réessayer les
manquants » (§ Écran Adversaires) est littéralement ce même appel. Le filtre amont
(`OpponentTravelResolver::awayPairingKeys`, méthode SŒUR de la projection et du contrôleur) route
sur code fédéral OU clé sentinelle — un gymnase épinglé sur un adversaire SANS code (clé sentinelle,
§ Écran Adversaires) entre donc dans ce recalcul, il ne tient pas que sur le chauffage synchrone posé
à l'épinglage (qui se perdrait sans rattrapage si l'IGN répond 429) ; `distinctOpponentCodes` garde
son contrat « codes fédéraux seuls » pour ses autres appelants ; le repli VILLE (2) reste réservé aux
codes fédéraux (un sans-code n'a pas d'entrée d'annuaire fédérale à router en repli). Détail
worker/verrou/topic : `backend/docs/geo-api.md` § Calcul asynchrone.

### Logo fédéral d'un adversaire

`OpponentDirectoryEntry.logoId` (table GLOBALE) est posé sans coût réseau supplémentaire depuis les
hits organismes déjà résolus ; quand c'est le canal `directVenue` qui gagne la localisation (pas de
hit organisme en propre), il est enrichi best-effort par un appel organisme de plus, par CODE
(P4-250, `OpponentLocationResolver::logoIdForCode`) — une panne n'y fait jamais perdre la
localisation. `GET /api/opponents/{code}/logo` (route MEMBRE, jamais publique) le
re-héberge paresseusement au premier accès. `shared/components/ui/opponent-logo.tsx` (rond, 16 px
`AwayList`/24 px `AwayFixtureCard`, repli initiales — `initials` calculées par
`lib/opponentInitials.ts`, hors du partagé) affiche l'image si un code organisme est connu et
bascule elle-même sur le repli au premier 404 (`onError`) : `AwayList` et `AwayFixtureCard`
n'ayant que la `Fixture` sous la main (pas le vrai booléen), ils lui passent une approximation
« un code existe » plutôt que le `hasLogo` réel — seul `OpponentsPage` (§ Écran Adversaires),
qui lit `GET /api/opponents/travel`, lui passe le booléen SERVEUR exact. **Pas dans `ConflictLine`**
(le côté d'un conflit ne porte pas le code adverse) **ni dans la grille**. Détail :
`backend/docs/ffbb-api.md` §3bis.

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
une rencontre déjà rattachée). Écran d'appariement : §5.3. La MÊME clause d'identité (`resolveConfirmed`,
égalité stricte) sert aussi aux deux détecteurs d'écart de salle d'une réconciliation (§7, domicile
placé et non placé) : un libellé dont l'alias confirmé pointe le gymnase déjà rattaché n'est pas un
écart, un alias vers un AUTRE gymnase en reste toujours un.

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
**taper** dans FBI (`appValue`) en face de ce que FBI **affiche encore** (`fbiValue`), avec la
graphie FBI BRUTE du gymnase de l'appli quand connue (`venueFbiLabel` — la graphie attestée par la
rencontre SŒUR la plus récemment mise à jour de même saison et même gymnase dont le libellé
normalisé est un alias confirmé, `FixtureRepository::findRawVenueLabelsBySeasonAndVenue` ; repli sur
le premier `Venue.externalLabels` — stocké NORMALISÉ, minuscules sans accents, donc moins lisible à
recopier — si aucune sœur n'atteste). Maison unique : `FbiCorrectionLedger`
(`open`/`refreshSeen`/`closeBySource`/`closeManually`, plus `declareFromConflict`), injectée dans
**trois** foyers d'écriture — le moteur de réconciliation partagé (`FbiFixtureImporter`, canaux
xlsx **et** API), l'arbitrage hors dépôt (`ReviewFixtureDeviationController`), et le statut
« Erreur FBI » d'un conflit de collision de gymnase (`FixtureConflictsController`, §
« Résolution des conflits » ci-dessous) — ce troisième foyer ouvre TOUJOURS l'entrée à **valeur
cible VIDE** (`appValue`/`fbiValue` tous deux `null` : l'app a importé l'erreur depuis FBI, elle ne
connaît pas la bonne valeur, inventer serait mentir ; l'écran affiche « à vérifier » pour les trois
champs — salle, date, heure —, jamais un tiret muet). Un « garder l'appli » ouvre (ou re-date) une
entrée OUVERTE ; « prendre le fichier »
n'en ouvre **jamais** (l'appli s'aligne sur FBI, rien à reporter). Cycle
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

**Une seule déclaration « erreur FBI » vivante par conflit.** L'entrée que le troisième foyer ouvre
porte l'EMPREINTE du conflit qui l'a ouverte (`FbiCorrection.conflictFingerprint`, colonne
NULLABLE — `null` pour les entrées nées des deux autres foyers, qui n'ont aucun conflit derrière
elles ; zéro index d'unicité dessus). Re-déclarer sur le **même conflit** — une autre rencontre, ou un
autre champ — **ferme** (`closed_by=redeclared`, nouveau motif `FbiCorrectionCloseSource::
REDECLARED`, fermeture DÉFINITIVE, pas de réouverture) la déclaration précédemment ouverte par CE
conflit avant d'ouvrir la nouvelle ; re-déclarer sur la **même** cible (même rencontre, même champ)
re-date sans dupliquer, comme avant. Motivé par un cas réel : une collision entre deux équipes,
le gestionnaire déclare « erreur d'heure » sur l'une, se ravise et déclare « erreur de salle » sur
l'autre — sans ce garde-fou, il pouvait accumuler jusqu'à six entrées, dont une fausse, pour une
seule collision. ⚠ **Ce que ça NE change PAS, décision fondateur explicite** : remettre le conflit
à « à traiter » (`DELETE .../resolution`) **ne ferme toujours pas** cette entrée —
`deleteResolution` n'appelle jamais `FbiCorrectionLedger`, aucun lien n'est persisté entre une
RÉSOLUTION de conflit et une entrée du registre (décision fermée, `etat-des-lieux.md` §2). La
symétrie complète (fermer aussi au retour à « à traiter ») a été écartée **volontairement**, pour
ne jamais perdre le rappel d'une erreur FBI réelle que le gestionnaire aurait déclassée par erreur
— seule une re-déclaration sur ce conflit, ou une fermeture depuis la liste, referme l'entrée.

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
105/30) — le solveur de placement (§3) partage la même géométrie.

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

**D1 (échauffement salle seule, hors des conflits de PERSONNE)** : la fenêtre SALLE (sans
échauffement) sert `VENUE_OVERLAP`/`MATCH_SLOT_WINDOW` — deux matchs enchaînés à 2 h d'écart ne
collisionnent pas. **Fenêtre de conflit PERSONNE**
(`MatchFootprint::personConflictOccupancy`/`personConflictOccupancyAt`) retranche l'échauffement à
DOMICILE seulement (aucun trajet, la fenêtre de conflit y vaut donc exactement la fenêtre salle) ; à
l'EXTÉRIEUR l'échauffement est COMPTÉ (P4-240 ③, décision C — la personne doit être échauffée au
gymnase adverse), la fenêtre de conflit y vaut donc la fenêtre effective d'occupation complète
(échauffement + trajet aller avant, trajet retour après). Une personne engagée deux fois — joueuse
OU coach, sans distinction de rôle — n'est en conflit QUE si son arrivée dépasse le coup d'envoi du
second engagement, quel que soit le gymnase ; arrivée pile au coup d'envoi = pas de conflit
(chevauchement demi-ouvert). `MATCH_MATCH` et le côté match de `MATCH_TRAINING` testent le
chevauchement sur cette fenêtre ; les bornes SERVIES par côté (`windowStart`/`windowEnd`) restent la
fenêtre PERSONNE complète (échauffement inclus, § « Détail par côté » ci-dessous) — seul le TEST de
chevauchement en diffère à domicile. Cas fondateur : une personne coache à l'extérieur (retour
estimé 19h17) et joue à domicile (coup d'envoi 19h30) — arrivée avant le coup d'envoi, aucun
conflit, même règle que pour un coach.

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
`LEAGUE_WINDOW_VIOLATION` (équipe mappée seulement) · 3 clash dur `MATCH_MATCH`/`MATCH_TRAINING` +
`CLUB_RULE_VIOLATION` (P4-272 ③ — un domicile placé dont le coup d'envoi viole une règle CLUB
**HARD** couvrant son jour ; une règle PREFERRED ne fait jamais de violation, seulement un nudge
côté solveur — champ additif `rules`, les règles violées ; amicaux exemptés comme l'enveloppe
ligue) + `TEAM_VENUE_FORBIDDEN` (P4-272 ④, même gravité — décision fondateur : un domicile placé
dans un gymnase INTERDIT à son équipe, scope TEAM HARD, kickoff-indépendant — c'est le gymnase qui
viole, pas l'heure ; champ additif `venueId` ; amicaux exemptés de même) · 4 `VENUE_UNAVAILABLE` + `ACCESS_WINDOW_LOST` (« Hors accès match » — champ additif `windows`, les
accès du gymnase de la fixture triés jour du match d'abord, hors identité de l'empreinte
`TYPE:fixtureId` ; l'écran nomme le gymnase et ses fenêtres, « aucun accès match ce jour-là » sans
aucune) · 5 clash adouci +
`FRIENDLY_ON_MATCH_SLOT` (amical HOME placé sur un créneau de match — `reasons`:
`MATCH_SLOT_WINDOW`/`MATCH_WEEKEND`, samedi = clé du week-end, le vendredi ne compte jamais) · 6
`COMPETITION_INCOMPLETE` (compétitions APPARIÉES sous leur attendu, `expectedMatchdays` — jamais
pour une `CUP`) · 7 `AWAY_NO_FOOTPRINT` (angle mort nommé : extérieur sans heure ni habitude du bon
jour). Réponse : bornes datées en heure MURALE du club (jamais un offset).

**Détail par côté** : `MatchConflictDetector::fixtureView` sert quatre champs additifs par côté
(`estimatedKickoffTime`, `travelOneWayMinutes` — `null` = trajet non modélisé,
`matchDurationMinutes`, `opponentLabel`) ; `opponentPlace` (ville de l'adversaire, jamais un
gymnase) est décoré EN AVAL par `FixtureConflictsController::decorateOpponentPlace` sur les côtés
AWAY seulement, via `OpponentPlaceResolver` (batch, re-pointé sur le lien) : (1) le lien de la
rencontre `(code, libellé FBI normalisé)` → sa référence de salle fédérale → ville de la suggestion
fédérale correspondante ; (2) sinon la ville de l'annuaire fédéral global ; (3) `null`. Ces champs
sont servis pour TOUTE famille qui partage cette vue de rencontre (⚠ le commentaire de
`fixtureView`/`ConflictFixtureView` dit encore « le front les ignore pour la famille gymnase » —
faux aujourd'hui, la variante `venue` ci-dessous les consomme ; signalé aux mainteneurs du
fichier). Le front (`conflictSideLines.ts`, `ConflictLine.tsx`) choisit sa mise en page sur
`model.kind`, DEUX variantes :

- **`person`** (`MATCH_MATCH`/`MATCH_TRAINING`) : un vrai TABLEAU (`Table variant="inline"` —
  primitive partagée `shared/components/ui/table.tsx`, [`frontend-components.md`](../../frontend/docs/frontend-components.md) §3) à quatre
  colonnes horaires FIXES — Départ · Coup d'envoi (toujours colonne 2, quelle que soit la nature du
  côté) · Fin/retour · Durée (repliée sous 360 px par container query, la largeur du RADAR pas du
  viewport) — une ligne par côté puis une ligne de chevauchement ; un créneau absent rend « — »,
  jamais une cellule vide muette.
- **`venue`** (`VENUE_OVERLAP`) : une ligne par rencontre — équipe, « vs
  adversaire », le GYMNASE et la DATE (répétés sur CHAQUE ligne bien qu'identiques pour les deux
  côtés par construction — **forme imposée par le fondateur**, une factorisation en tête a été
  proposée et écartée), et le créneau coup d'envoi → fin (fin = coup d'envoi + durée, arithmétique
  d'affichage). Un `VENUE_OVERLAP` sans `venueId` connu (donnée dégradée) retombe sur la ligne grise
  existante. Rien ne change côté détecteur : tous les champs consommés par cette variante étaient
  déjà servis pour cette famille.

Présentation pure — aucune formule de gravité redérivée.

## 3. Solveur de placement (`POST /api/fixtures/place` → engine `/place-matches`)

Second problème solveur ([ADR-0003](../../docs/architecture/adr-0003-match-placement-solve.md)),
même `CONTRACT_VERSION` **2.27** que `/generate`/`/validate-assignments` (un seul contrat pour les
trois endpoints — voir §6 `CLAUDE.md`). **Rail
SYNCHRONE** (`PlaceMatchesController` — management + saison écrivable + socle pointé), anti-double-clic
PAR CLUB `MatchPlacementLock` (Redis dédié — ne protège pas deux clubs l'un de l'autre : ils partagent le
sémaphore GLOBAL `max_concurrent_placements=1` de l'engine, détail ADR-0003 §2). Best-effort à poids
dominant : `10 000 × Σ placés + SOFT` — **aucune contrainte HARD n'est jamais violée en sortie** ; un
match sans candidat licite sort NOMMÉ (`no_access_window` · `no_league_intersection` ·
`team_venue_forbidden` · `club_rule_no_slot` · `venue_unavailable` · `venue_full` · `not_selected`,
sept valeurs). `club_rule_no_slot` (P4-272 ③) : un créneau était licite (accès + ligue) mais TOUTES
les règles CLUB HARD couvrant le jour l'ont refusé (sémantique ET — chaque règle HARD du jour doit
accepter le coup d'envoi) ; une règle PREFERRED ne vide jamais un domaine, elle pénalise seulement
le candidat retenu (`W_CLUB_RULE=30`, §3 SOFT ci-dessous). `team_venue_forbidden` (P4-272 ④) : un
gymnase interdit à l'équipe (scope TEAM HARD) ne rejoint JAMAIS son domaine — un créneau y aurait
été légal (accès ∩ ligue ∩ règles CLUB) mais uniquement sur un gymnase banni ; **cette raison est
testée AVANT `club_rule_no_slot`** dans la même passe de résolution (un slot déjà refusé par une
règle CLUB n'entre jamais dans les candidats d'un gymnase, donc n'y contribue jamais) —
`_candidate_kickoffs`, `engine/app/solver/match_placement.py`. Les deux dernières raisons se
distinguent post-solve sur l'occupation finale : `venue_full` = plus aucun créneau licite libre à
sa date (gymnase saturé) ; `not_selected` = un créneau licite restait libre mais le solve ne l'a
pas retenu dans son budget — « relancez le placement » (ADR-0003 §3).

**Budget 60 s de bout en bout** (`solverTimeoutSeconds` du payload — 30 s avant P4-240), encadré par la
chaîne de timeouts `MatchPlacementLock` 120 s → HTTP contrôleur 90 s → nginx fastcgi/proxy 120 s → PHP
`max_execution_time` 120 s → client frontend `ky` 120 s sur cet appel seul (`frontend/src/features/
matches/api/fixtures.ts`). Avant le solve, un **warm-start glouton** déterministe (matchs triés
date/équipe, candidat préféré = créneau idéal, sinon placement SOLVER courant, sinon premier créneau
licite libre) pose un seul jeu de hints CP-SAT — il absorbe l'ancien hint de stabilité, jamais deux hints
contradictoires sur un même match (ADR-0003 §4).

**Fenêtre de placement optionnelle `{from, to}` (P4-240 ④)** : le corps JSON de la requête est
optionnel — deux dates AAAA-MM-JJ incluses (422 si invalide ou `from` postérieur à `to`), sinon
comportement inchangé, byte-identique à l'ancien payload (tout le club). Avec une fenêtre : DANS la
fenêtre, les candidats TO_PLACE partent normalement au solveur ; HORS fenêtre, un domicile déjà posé
(venue+kickoff, y compris par le solveur) devient une **ancre FIXED** — sa salle reste protégée, il ne
bouge jamais et le résultat ne le réécrit pas — un domicile non posé est absent du payload, et un
extérieur hors fenêtre est omis (depuis ③ le solveur ignore son empreinte personne, il ne portait plus
que sa date). En offre Découverte (mode restreint, `PlanEntitlements::outputBudget()['restricted']`,
club non démo, pool > 0), le placement automatique se fait UNIQUEMENT semaine par semaine : un appel
sans fenêtre, ou avec une fenêtre de plus de 7 jours calendaires, est refusé 403 — défense SERVEUR
derrière le bouton global désactivé (§5) ; 1 clic reste 1 crédit (`CreditBudgetSubscriber`, inchangé).

**HARD** : fenêtres d'accès match (le match SEUL dedans, D1 — `kickoff ≥ start`,
`kickoff+matchMinutes ≤ end`), indisponibilités gymnase, no-overlap `(gymnase, date)` sur la fenêtre
MATCH, fenêtre ligue résolue depuis la COPIE club (`ClubLeagueWindow`, §1) quand l'enveloppe est
résolue pour l'équipe — équipe non mappée = diagnostic INFO `league_envelope_unresolved` seul ;
copie VIDE = aucun HARD ligue pour tout le club, diagnostic INFO `league_envelope_empty` unique ;
règles de match CLUB HARD (`MatchConstraint`, §1, bloc top-level `clubRules` — {ruleType,
daysOfWeek, kickoffMin, kickoffMax} — chaque règle HARD couvrant le jour DOIT accepter le coup
d'envoi, sémantique ET ; un domaine vidé par elles seules sort `club_rule_no_slot` ci-dessus) ;
gymnases INTERDITS par équipe (`teams[].forbiddenVenueIds`, P4-272 ④ — un gymnase de cette liste
n'entre JAMAIS dans le domaine de l'équipe, quel que soit l'état de ses autres candidats). Durées
par équipe (`MatchDurationResolver`) portées par le contrat ; absentes côté engine ⇒ défauts
Pydantic 105/30.

**Personne = coach OU joueuse active (P4-240 ③, décision A)** : chaque équipe du contrat porte
`teams[].players` (ids `CoachPlayerMembership` actifs, additif — n'a pas nécessité de bump de
`CONTRACT_VERSION` à son introduction) en plus de `teams[].coaches` — le backend exclut déjà toute personne qui coache AUSSI
cette équipe (le rôle coach gagne, parité `MatchConflictDetector`, gardé par le NR bloquant
`PlayersPayloadParityTest`) ; le solveur applique la même exclusion en défense
(`_team_players`). Une joueuse pèse comme un coach MAIN (`W_COACH_MAIN`, SOFT) — les
`trainingOccupancies` projetées sont étendues de la même façon aux joueurs actifs de l'équipe du
créneau (`MatchPlacementPayloadBuilder::trainingOccupancies`).

**Le solveur IGNORE toute empreinte personne d'un match EXTÉRIEUR (P4-240 ③, décision B)** : la
boucle des fenêtres personne ne parcourt plus que les ancres FIXED (domicile, déjà posées) et les
entraînements projetés — un match AWAY ne bloque plus aucun coach ni joueuse côté solveur
(`roundTripMinutes` reste transporté par le contrat, plus consommé). « C'est la vie » (fondateur) :
le solveur ne peut de toute façon pas déplacer un match extérieur (l'heure est imposée par
l'adversaire) ; le RADAR (§2) reste la seule source qui signale une indisponibilité réelle liée à un
extérieur, le gestionnaire arbitre après coup. Un AWAY reste émis au contrat : il libère la
protection d'habitude de son équipe ce jour-là (`team_dates`). Conséquence : la fenêtre
personne du solveur ne provient plus JAMAIS d'un trajet ni d'un échauffement — elle vaut toujours la
fenêtre salle `[kickoff, kickoff+matchMinutes]` (lot M + décision B).

**SOFT (golden-épinglés)** : conflit personne (coach MAIN ou joueuse active) −60 · coach ASSISTANT
−10 · passerelle `NOT_SIMULTANEOUS` violée −40 (⚠ **asymétrie délibérée** : le radar §2 ne signale
jamais cette famille, le solveur GARDE cette préférence souple — sens sûr, une pénalité SOFT ne
bloque jamais rien, à ne pas « aligner » en la retirant) · règle de match CLUB PREFERRED violée −30
(`W_CLUB_RULE=30`, P4-272 ③, `match_placement.py:55` — arbitrage fondateur : plus qu'une habitude
+15+5, moins qu'une passerelle `NOT_SIMULTANEOUS` −40 ou qu'un conflit coach −60 ; une règle HARD
n'entre jamais dans l'objectif, elle élague le domaine, §3 ci-dessus) · habitude heure +15/gymnase +5 · fenêtre
habituelle protégée −25 (`W_PROTECT_HABIT=25`, `match_placement.py:58`) · `BACK_TO_BACK` enchaîné +15 ·
stabilité re-solve +8 · compactage −1/15 min de trou. **La protection ne s'applique JAMAIS au
créneau idéal PROPRE de l'équipe candidate** (`is_own_ideal`, P4-271) — sans cette exception, le
bonus +15+5 d'une équipe perdrait toujours face à la protection −25 dès qu'une AUTRE équipe déclare
son créneau idéal sur le même gymnase+jour+heure (l'ex-alternance A/B). Deux créneaux idéaux qui
coïncident physiquement (même gymnase+jour+heure) protègent la MÊME fenêtre sur une date sans
membre — dédupliquée par `(gymnase, date)` pour qu'un troisième candidat chevauchant ne soit jamais
pénalisé deux fois (`match_placement.py:433-439`).

**Ancres — `Fixture.placementSource`** : geste manuel API → `MANUAL` ; `MANUAL` + `SUBMITTED`/
`VALIDATED` = **FIXED**, ne bouge jamais ; `SOLVER` = re-plaçable. Un amical n'est **jamais**
proposé au solveur (« un amical n'est pas sur un créneau de match ») : placé+ancré → FIXED (gymnase
protégé), sinon simplement absent du payload — le contrat n'a pas bougé pour cette raison, la place
laissée libre est couverte par `FRIENDLY_ON_MATCH_SLOT` (§2), pas par une contrainte moteur.

Le backend PROJETTE (occupations d'entraînement datées, heure extérieure estimée, enveloppe ligue
résolue serveur), l'engine reste plat. UI : bouton « Placer automatiquement » sur le Calendrier
(spinner, toast « N placés · M non plaçables », raisons par match) — désactivé, avec une explication,
en offre Découverte ; bouton dédié « Placer ce week-end » dans l'établi Semaine (P4-240 ④, §5), même
rail (`runPlacement`), même toast, fenêtre posée sur la semaine lundi→dimanche affichée.

**Boucle manuelle** : chaque match cliquable ouvre `PlacementPanel` — Déplacer, Dé-placer,
Verrouiller/Rendre au solveur (`placementSource` écho — refusé en 422 si le placement bouge),
Échanger (salle+heure, jamais les dates, deux PUT séquentiels), Modifier (équipe figée, date
conservée sauf switch HOME↔AWAY qui libère), Supprimer. **Rien ne bloque** une collision créée à la
main (décision fermée) — le diagnostic gradué (§2) l'affiche en sévérité max ; côté engine, les
ancres FIXED élaguent les candidats plutôt que d'entrer au NoOverlap (deux ancres en collision ne
rendent plus tout le solve infaisable).

## 4. Le gardien à l'ouverture — delta de visite

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

### Échéances de saisie ligue/comité

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
`MatchModuleDeltaComputer` **sans stamper**) si une référence de visite existe déjà. Il sert aussi
`toConfirmCount` — le compte GLOBAL de domiciles « validé ligue » validables
(`LeagueValidationOutlook::compute`, § « Validé ligue » en lot) : un domicile validable est
`UNPLACED`, donc `toPlaceCount` (ci-dessous) le SOUSTRAIT pour ne jamais le compter deux fois (« à
confirmer », pas « à placer »). Cette route est aussi le point où le balayage des amicaux passés
(`FriendlyAutoValidator::sweep`, § « Validé ligue » en lot) se déclenche — SEULEMENT si l'appelant
est GESTIONNAIRE, jamais pour un Membre qui ouvre le même cockpit. Chaque fenêtre sert en plus DEUX
compteurs distincts (`EntryDeadlineOutlook::countHomeByCompetition`) : `toPlaceCount`
(domiciles UNPLACED restants — un geste de placement) et `toEnterCount` (domiciles PLACED, prêts à copier
dans FBI — aligné sur `fbiTodo.toEnter` et la liste FBI). La carte cockpit `FbiDeadlineCard` (`/`,
sous `SeasonPlanBanner`) fusionne le résumé du gardien dans la même carte (jamais un second bloc) et
distingue trois régimes : une échéance en fenêtre J-7 → ton accent (warning si dépassée) avec la
ligne globale au-dessus des échéances, chaque échéance segmentant « N match(s) à placer » et « N à
saisir dans FBI » ; hors fenêtre mais `fbiTodo` > 0 → ton NEUTRE, la seule ligne globale (« N FBI à
faire, dont M à corriger ») ; rien à faire, aucune fenêtre ET rien à confirmer « validé ligue » →
muette. Le bouton n'ouvre « Ouvrir la liste FBI » (`/matchs?fbi=1`) **que si** cette liste a du
contenu (`fbiTodo.toEnter + fbiTodo.toCorrect > 0`) ; sinon (tout reste à placer) il renvoie au
Calendrier (`/matchs`, « Placer les matchs ») — la liste FBI serait sinon une modale vide. Quand
`toConfirmCount > 0` ET l'utilisateur est gestionnaire, la carte ajoute une ligne dédiée « N à
confirmer « validé ligue » » + un bouton qui ouvre la confirmation chiffrée partagée (§ « Validé
ligue » en lot) — jamais affichée à un Membre.

## 5. Écran Calendrier (`/matchs`, route index)

L'écran unique du module : place/échange/verrouille/saisit dans FBI ET lit Semaine·Mois·Phase.
Importer garde sa maison propre (§6).

**Atterrissage conditionnel de l'index.** L'index `/matchs` n'est pas
inconditionnellement le Calendrier : une route d'atterrissage (`MatchesLanding.tsx`) tranche au
montage. S'il y a des conflits **à traiter** (`openConflictCount` sur le même cache `useConflicts`
que le badge « Conflits · N »), elle redirige vers `/matchs/conflits` ; sinon elle rend
`<CalendarPage/>`. Quatre règles fermées : (1) **c'est l'ISSUE qui est figée, pas seulement
l'entrée en décision** — un état local `outcome` (`null → "conflicts" | "calendar"`) est posé UNE
fois puis ne bouge plus (garde-fou : sans lui, un conflit né APRÈS un atterrissage sans conflit
rejouerait le renvoi et éjecterait l'utilisateur du Calendrier en plein travail), et ne se rejoue
qu'une fois par **session** SPA (booléen `landingDecided` de
`useMatchesStore`, non persisté, hors URL — un rechargement complet re-propose Conflits s'il y en
a). **Un conflit né ensuite se voit dans le badge « Conflits · N », il ne déplace jamais
l'utilisateur** ; (2) pendant l'attente du compte,
un `FullPageSpinner` — jamais le Calendrier avant de savoir ; (3) un compte **en erreur** ouvre le
Calendrier (« fail-open » — même doctrine que le badge, qui n'affiche jamais « Conflits · 0 » sur
données absentes) ; (4) une **URL avec paramètres** fait foi (lien profond : le cockpit `?fbi=1`,
« Voir la semaine », « Replacer ») et **saute** la décision. Toute entrée dans le module par un
autre onglet consomme aussi la règle (effet de montage de `MatchesLayout`) : revenir ensuite au
Calendrier ne renvoie jamais de force sur Conflits. La décision vit dans `MatchesLanding`, pas dans
le layout — la nav des onglets reste inchangée.

- **Chaîne de filtres pure** : filtre équipe/coach/gymnase (barre `MatchesFilterBar`, réutilise
  `ResourceFilter` de `features/planning`) → filtre type de compétition → scope temporel → familles
  de conflit. Coach = équipes coachées (principal+assistant) **+** équipes où il joue
  (`CoachPlayerMembership`). Filtre gymnase : le match doit y être POSÉ (les extérieurs en sortent).
- **Placement automatique — bouton global vs. « Placer ce week-end » (P4-240 ④)** : la barre
  d'actions du Calendrier porte le bouton global (tout le club, §3) ; en offre Découverte (crédits
  bridés, `useCredits` non nul), il est désactivé avec un message renvoyant vers l'établi Semaine,
  et seul le bouton dédié « Placer ce week-end » de `WeekWorkbench` reste ouvert — il pose la
  fenêtre lundi→dimanche de la semaine affichée (`lib/weekendGrid.ts::weekBounds`) et lance le MÊME
  rail (`runPlacement`, maison unique dans `CalendarPage`) : même rafraîchissement, même toast, même
  suffixe de crédits. Hors Découverte, les deux boutons coexistent librement.
- **`WeekWorkbench`** (temporalité Semaine, l'établi) : liste « À placer » couvrant TOUTES les
  semaines filtrées (pas seulement l'affichée) ; panneau de placement PERMANENT ; grille week-end
  (colonne « Extérieur » — dernière colonne du groupe de date qui porte ≥1 AWAY, blocs non
  enchaînés ; bande « sans heure » en tête pour un AWAY sans heure ni habitude) ; bande `AwayList`
  (détail : salle fichier, n° rencontre, rôle coach) ; radar `ConflictRadar` en dernier. Mode
  échange : Échap désarme, les candidates (autres domiciles PLACED) portent un anneau, les autres
  s'estompent. **Ouvrir un extérieur** (clic grille ou crayon de la bande) : un extérieur IMPORTÉ
  (FBI `externalRef` ou canal API `ffbbRencontreId`, `lib/fixtureOrigin.ts::isImportedFixture`)
  s'ouvre en LECTURE SEULE (`AwayFixtureCard` — logo fédéral de l'adversaire (repli initiales) +
  libellé, lieu, adresse postale du gymnase (rendue SEULEMENT si le lien apparié la porte — jamais
  une ligne vide), date, coup d'envoi, durée, trajet, statut, aucun bouton de modification, « la
  fédération en est la source ») ; un extérieur SAISI À
  LA MAIN reste éditable (`FixtureFormDialog`), même logique côté grille et côté bande — une seule
  maison (`openAway`).
- **Refus serveur du placement (D2)** : `FixtureStateProcessor::assertVenueAccessAllowed` (geste
  gestionnaire, create ET update d'un domicile) refuse en 422 (1) toute rencontre — amical compris
  — posée dans un gymnase couvert par une `VenueUnavailability` à sa date ; (2) pour une rencontre
  de COMPÉTITION seulement, quand le club déclare ≥ 1 `VenueMatchWindow` : aucune fenêtre `(gymnase,
  jour)` ou coup d'envoi hors fenêtre (même prédicat que le diagnostic,
  `MatchConflictDetector::kickoffInsideWindow` — une seule maison). Jamais de refus sur l'enveloppe
  ligue (radar seul, décision D2, PERMISE côté client aussi depuis P4-272 ① — voir ci-dessous) ; un
  amical reste libre hors fenêtre d'accès. `/api/fixtures/place`
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
  et son miroir DÉCLARÉ `matches/lib/envelope.ts::kickoffInsideLeagueWindow` (front) — gardés en
  parité par `leagueEnvelope.parity.json` + `LeagueEnvelopeMirrorParityTest`/
  `leagueEnvelope.parity.test.ts`. La fenêtre de ligue ne BLOQUE plus la pose manuelle (P4-272 ①) :
  `PlacementPanel` l'utilise seulement pour AVERTIR (`EnvelopeHint`, `isInEnvelope`), `canPlace` n'y
  regarde plus — ligue, club et équipe se comportent désormais pareil, seul un gymnase indisponible
  reste un refus dur côté client. Divergent PAR CONCEPTION : l'exemption amical (front `!isFriendly`
  vs backend saut des `competitionId` null) et la résolution équipe↔fenêtre (déjà serveur, servie
  depuis la COPIE club — §1).
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
- **Échauffement + trajet aller-retour d'un extérieur dessinés sur son bloc**
  (`lib/awayKickoff.ts::awayTimeline`, 🔴 miroir déclaré de
  `MatchFootprint::personConflictOccupancy` régime AWAY — gardé par `AwayTimelineMirrorParityTest`
  ⇄ `awayTimeline.parity.test.ts`, foyer unique partagé par la colonne de grille, `AwayFixtureCard`
  et la fiche lecture seule) : à coup d'envoi connu, le départ recule TOUJOURS de l'échauffement de
  l'équipe (P4-240 ③, décision C — on doit être échauffé au gymnase adverse) et, quand le trajet
  aller simple `awayTravel.oneWayMinutes` est connu, du trajet aller en plus (le bloc couvre
  `[coup d'envoi − échauffement − aller, coup d'envoi + match + aller]`) avec des repères
  départ/retour ; trajet inconnu ⇒ le départ reste `coup d'envoi − échauffement`, le retour se
  réduit à la fin du match. Légende conditionnelle « Trajet aller-retour » (`WeekendGridLegend`,
  hachures `muted`) affichée seulement quand un bloc du week-end porte un trajet dessiné.
- **Temporalités Mois/Phase** : Mois = table groupée par jour ; Phase = une `Competition`
  appariée, en-tête « N/M journées » (`expected: null` pour une `CUP`, pas de dénominateur).
  `MatchRowsTable` (ligne partagée) : date/heure, équipe+rôle, dom./ext., adversaire, gymnase
  résolu, statut, une pastille CLIQUABLE par famille de conflit présente sur le match — la famille
  PERSONNE (`MATCH_MATCH`) NOMME la personne en double (« Emerick en double » ; plusieurs coachs →
  « Emerick +1 », `pillLabel`), les autres gardent leur libellé de famille. Cliquer une pastille
  ouvre un panneau (détail par côté, chevauchement) avec un bouton « Voir la semaine » qui bascule
  en Semaine sur le week-end du match et FOCALISE le conflit (même mécanisme que ci-dessous).
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
- **Focus d'un conflit** (`conflit=<a>,<b>`, `lib/urlState.ts` `decodeConflictFocusParam`/
  `applyConflictFocusToParams`, distinct de `match=` qui SÉLECTIONNE) : met en évidence les DEUX
  rencontres d'un conflit sans ouvrir le panneau de placement — posé par le bouton « Voir » du
  radar (`ConflictRadar`, visible seulement si le conflit est focalisable,
  `lib/conflictFocus.ts::isFocusableConflict`) ou par « Voir la semaine » depuis l'onglet Conflits
  pour un conflit qui nomme un coach (§6). `ConflictFocusBanner` (bandeau en tête de la semaine)
  rappelle de quoi il s'agit (« Conflit {coach} : {équipe A} × {équipe B}, chevauchement … ») et
  offre « Quitter le focus » (retire le filtre coach et la surbrillance). Porté par l'URL — un lien
  focalisé est partageable.
- **Deep-link `match=<fixtureId>`** (patron « absent = défaut », `lib/urlState.ts`
  `decodeMatchParam`/`applyMatchToParams`) : au seed, la fixture visée est sélectionnée dans le
  store, sa semaine posée (si absente de l'URL), son masque levé (`revealPlan`) si elle est
  filtrée, puis focus + scroll sur sa cellule (`focusFixtureCell`, `block: "nearest"`) — la
  sélection (`ring-accent`) EST la mise en évidence, pas d'anneau temporisé ni de `role="status"`.
  Le paramètre est retiré en `replace` après consommation (one-shot). Posé par « Voir la semaine »
  depuis Conflits (§6). **À l'arrivée depuis `conflit=` ou `match=`, la grille se RECENTRE en plus**
  (`block: "center"`, un `requestAnimationFrame` de plus que `focusFixtureCell`, sur la première
  rencontre en focus qui porte une cellule) : le litige reste visible sans défiler, sans changer le
  filtre posé par l'URL.
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

- **Mémoire de session des filtres** : même mécanisme que le Calendrier (§5) — l'URL fait foi
  pour les quatre filtres (pivot, familles, traitement, domicile) **seulement si** elle porte au
  moins une des clés dédiées (`hasConflictsParams`, `?ouvert=` compris — le paramètre qui ouvre
  une entrée précise COMPTE comme une clé), sinon le store (`conflictsTreatments`/
  `conflictsHomeOnly` dans `useMatchesStore`, à côté de `conflictsPivot`/
  `conflictsFamilies`) est gardé et l'adresse se re-synchronise depuis lui.
- **Pivot** (`pivotConflicts`, pur — répartit en seaux les lignes déjà servies, ne recalcule rien) :
  coach (défaut) · équipe · gymnase · journée (week-end). Chaque axe porte une sentinelle pour les
  conflits sans ressource résolue (« Autres conflits », « Extérieur », « Sans date »), toujours en
  dernier. Un conflit à 2 équipes apparaît sous chacune en pivot équipe (assumé). Tri : compte
  décroissant puis alphabétique fr (chronologique pour la journée).
- **Chips familles et puces « Traitement »** rendues par la primitive partagée `FilterChip`
  (`shared/components/ui/filter-chip.tsx`, sœur de `FilterToggle`) — maison unique du motif puce
  `aria-pressed` bordée à compteur, aussi consommée par la chip « Familles » du Calendrier (§5).
- **Chips familles** (9 `ConflictType`, backend et frontend ALIGNÉS — la famille
  `TEAM_LINK_OVERLAP`/« Passerelle » n'est plus dans le contrat public ni dans le frontend
  (`conflictLabels.ts`/`api.ts`) ; un ancien lien profond qui la citerait est ignoré proprement,
  comme une famille fictive. Toutes cochées par défaut, compteur SAISON, à
  traiter seulement — une famille 100 % traitée garde sa chip, « · 0 » en sourdine.
- **Puces « Traitement »** : 4 puces HISTORIQUES toujours rendues même à zéro (À traiter ·
  Dérogation demandée · Réglé en interne · Sans solution pour l'instant), plus jusqu'à 3 puces
  CONDITIONNELLES propres à une famille (Importer les matchs manquants ·
  Erreur FBI · Match à déplacer), rendues seulement si au moins un conflit les porte (même patron
  que les chips familles, `treatmentChipKeys`). Plus le filtre « Seulement avec un match à
  domicile » (critère descriptif, pas « où je peux agir » — décision fermée). Ordre d'application :
  familles → traitement → domicile → pivot.
- **Accordéon par entrée** (une seule ouverte) rend `ConflictSeverityGroups`/`ConflictLine` — même
  maison que le radar du Calendrier, gravité 7 repliée derrière un compte, conflits triés par date
  croissante dans un groupe de gravité. Bouton « Voir la semaine » sur un conflit daté : un conflit
  qui NOMME un coach (`conflict.coachId` défini — typiquement un conflit de PERSONNE) navigue vers
  le Calendrier en FOCUS (`vue=coach&filtre=<coachId>&conflit=<a>,<b>`, `lib/urlState.ts`) — filtre
  posé sur ce coach, les deux rencontres du conflit surlignées, masques levés — plutôt que de
  sélectionner un match et ouvrir le panneau de placement sur une seule équipe ; un conflit SANS
  coach (collision de gymnase, hors accès…) filtre de la même façon mais sur ÉQUIPE — les `teamId`
  des deux côtés du conflit (`filtre=<teamIdA>,<teamIdB>&conflit=<a>,<b>`, mode équipe = défaut du
  filtre partagé, donc sans `vue=` dans l'URL, `lib/urlState.ts` ; `teamId` servi par le backend,
  jamais redérivé ; un conflit à un seul côté résolu filtre sur cette seule équipe), sans
  jamais sélectionner de match ni ouvrir le panneau de placement. Les deux chemins arrivent centrés
  sur la grille (§5, correctif de centrage). `ConflictLine` ne porte pas le logo de l'adversaire
  (§1 « Logo fédéral d'un adversaire ») — le côté d'un conflit ne porte pas son code organisme.

### Résolution des conflits (`ConflictResolution`)

Un conflit traité **reste toujours rendu** — poser un statut dit où en est la résolution, ne masque
jamais. **Huit cas stockables** (`ConflictResolutionStatus`) : les trois de BASE, proposés sur
toute famille — Dérogation demandée / Réglé en interne / Sans solution pour l'instant ; deux
réservés aux conflits de PERSONNE où un côté servi porte le rôle PLAYER — **« Coache, ne joue
pas »** (`COACHES_NOT_PLAYING`) / **« Joue, ne coache pas »** (`PLAYS_NOT_COACHING`), refusés en
422 sinon (`FixtureConflictsController::conflictHasPlayerSide`), rangés sous le filtre/chip « Réglé
en interne » (pas de chip propre, `treatmentOf`) ; trois **propres à une famille** — calendrier
incomplet (`COMPETITION_INCOMPLETE`) propose **« Importer les matchs
manquants »** (`IMPORT_MISSING_MATCHES`) ; collision de gymnase (`VENUE_OVERLAP`) propose
**« Erreur FBI »** (`FBI_ERROR`) et **« Match à déplacer »** (`MATCH_TO_MOVE`) — chacun avec sa
PROPRE chip de traitement (§ « Chips familles » ci-dessus), à la différence des deux statuts de
personne. **Le serveur est souverain** : `ConflictResolutionStatus::casesForFamily` donne, pour la
famille du conflit visé, les trois de base plus les statuts propres à cette famille ; un statut
hors de cette table est refusé en 422, quelle que soit la famille (le front ne fait que masquer le
geste voué au refus, `resolutionChoicesFor`, jamais une redérivation du refus lui-même). **Erreur
FBI** exige un complément `fbiCorrection` (`{fixtureId, field}`, § « Registre » ci-dessus) — seul
statut à ouvrir un dialogue (`FbiErrorDialog`, `statusNeedsFbiComplement`) avant d'écrire, qui
prévient que la déclaration laisse une trace ailleurs (indépendante, § « Registre »). **Un
`fbiCorrection` envoyé avec un AUTRE statut est refusé en 422** — il ne serait jamais lu, une
requête incohérente n'est jamais silencieusement ignorée. « À traiter »
= défaut = **absence de ligne**, pour les huit cas sans exception — poser N'IMPORTE LEQUEL des
trois statuts propres à une famille retire le conflit du compte « à traiter » exactement comme les
trois de base (décision fermée, `etat-des-lieux.md` §2 : le mécanisme des compteurs n'a PAS de cas
spécial pour eux, seule leur chip diffère). `GET /api/fixtures/conflicts` sert un champ additif
`resolution` (jointure serveur par empreinte, `ConflictRadarLoader`) ; `PUT`/`DELETE
/api/fixtures/conflicts/{fingerprint}/resolution` (gestionnaire seul, empreinte contrainte par la
route). **Pose concurrente rattrapée** : la pose est lecture-puis-insertion, sans verrou — deux
`PUT` simultanés sur la même empreinte violeraient l'unicité base `(club, saison, empreinte)` et
rendraient une 500 ; le contrôleur capture
`UniqueConstraintViolationException`, relit la ligne gagnante sur la connexion encore vivante
(scopée club par la RLS) et rend un **200 idempotent** — même patron déjà en place dans six autres
contrôleurs. **Orphelin** (empreinte disparue du flux) jamais nettoyé à la volée — purgé avec la
saison (`SeasonDataPurger`) ou l'effacement RGPD. Écran (`ConflictResolutionControl`) : pastille
`StatusPill` devenant, pour un gestionnaire, le déclencheur d'un menu APG (statuts de base + ceux
de la famille + note + « Remettre à traiter ») ; un membre simple la lit figée, rien n'affiche
« à traiter ». Note libre ≤ 500 car., éditée inline ; `ConfirmDialog` seulement si une note serait
perdue au retour à traiter. Tout compteur de l'app (badge de nav, `WeekCounters`, chips de
familles, pivot) ne compte plus que l'à traiter. Badge de nav « Conflits · N » — absent (jamais
« · 0 ») à zéro.

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

### « Validé ligue » en lot, piloté par l'échéance du championnat (`LeagueValidatedFixturesController`)

Un club qui démarre l'application EN COURS de saison importe un fichier FBI dont des domiciles sont
déjà datés côté fédération : date, heure et gymnase déjà enregistrés. Confirmer chaque placement à la
main n'a pas de sens — un geste SÉPARÉ, chiffré et confirmé les bascule d'un coup. **Exception
consentie**, pas une contradiction, à « une rencontre naît AVEC son gymnase mais jamais placée
d'office » (`FbiFixtureImporter::attachConfirmedVenue`) : le chemin de création de l'import ne change
pas, continue de créer en `UNPLACED` — le statut n'est **jamais** posé pendant l'import. C'est CE
geste, distinct, qui valide.

**Le déclencheur est que le championnat ait COMMENCÉ, jamais une propriété du domicile
lui-même** — décision fondateur : « la date d'échéance est de validation des dates pour un
championnat entier, donc toutes les dates du championnat peuvent être validées à partir de la date
d'échéance ». Un championnat est « commencé » de DEUX façons, l'une ou l'autre suffit : (1) son
échéance de SAISIE est passée (jour de l'échéance INCLUS, `CompetitionDeadlineResolver::resolve` +
comparaison `<= aujourd'hui`) ; (2) OU son PREMIER match est déjà joué (`min(matchDate)`
strictement dans le passé, tous domiciles/extérieurs confondus) — un championnat sans échéance
renseignée mais dont les dates sont déjà tombées n'est plus provisoire, il a démarré. Raisonner
rencontre par rencontre sans aucune de ces deux conditions serait **dangereux** : en octobre les
brassages se terminent, une nouvelle vague de rencontres jeunes arrive (nouvelle phase, nouvelle
poule, parfois un changement de niveau) avec des horaires **provisoires**, sans échéance passée ni
match déjà joué — les proposer à la validation les verrouillerait en ancres fixes pour le solveur
au moment précis où il faut encore pouvoir les déplacer. Tant qu'aucune des deux conditions n'est
remplie, **rien n'est proposé pour ce championnat**.

Toute la LECTURE et le prédicat vivent désormais dans la **maison unique**
`App\Service\LeagueValidationOutlook` (extraite du contrôleur pour être consommée aussi par le
cockpit, §4) : elle porte `compute()` (la lecture détaillée par championnat, avec `maturedBy`
`deadline`/`firstMatchPlayed` et `firstMatchDate` quand c'est le premier match qui a fait mûrir)
et `fixturesToConfirm()` (les domiciles à basculer au `POST`, recalculés au moment de
l'application). L'échéance effective d'un championnat suit la règle « le club gagne, sinon le
défaut communautaire » — maison unique `App\Service\CompetitionDeadlineResolver`, consommée par
`CompetitionResource::fromEntity` (la lecture des compétitions), `EntryDeadlineOutlook` (les
fenêtres J-7 du cockpit, §4) et `LeagueValidationOutlook` (la maturité « validé ligue »).

Deux routes `GET`/`POST /api/fixtures/league-validation` (management + saison écrivable + socle
pointé). **GET rend une lecture détaillée PAR CHAMPIONNAT**, pas un simple compte :
- `matured[]` — les championnats COMMENCÉS, chacun avec son nom, son échéance (nullable — un
  championnat mûri par son premier match joué n'en a pas forcément), sa provenance
  (`club`/`community`), **comment** il a mûri (`maturedBy` : `deadline`/`firstMatchPlayed`), la date
  de son premier match quand c'est elle qui l'a fait mûrir (`firstMatchDate`), et son compte de
  domiciles validables ;
- `toTreat[]` — les domiciles d'un championnat commencé qui NE seront PAS validés, **nommés**, jamais
  écartés en silence : équipe, date, adversaire, et la raison (`NO_KICKOFF`, `NO_VENUE`,
  `PENDING_DEVIATION`) ;
- `missingDeadline[]` — les championnats **sans échéance renseignée et pas encore commencés**
  (aucun premier match joué non plus) qui ont pourtant des rencontres prêtes (heure + gymnase) —
  signal qu'une échéance manque à saisir, jamais une validation proposée ;
- `totalValidatable` — le total tous championnats commencés confondus.

Le prédicat « validable » d'un candidat (`passesPredicate`, ex-`isEligible`) est inchangé dans sa
forme : un domicile `UNPLACED` rattaché à un championnat (`isCandidate` — **un amical est exclu**,
qu'il s'agisse d'un domicile SANS compétition ou d'une compétition dont le LIBELLÉ est un amical
(token exact « amical », maison unique `FbiDivisionSignature::isFriendlyCode` — couvre par exemple
« Amical PNF »/« Amical PNM » typées `CHAMPIONSHIP` côté import) portant une heure ET un `venueId`
(gymnase identifié, jamais le libellé brut), sans écart en attente. Il ne suffit pas à lui seul — il
faut EN PLUS que le championnat du candidat soit commencé.

**Les amicaux ne rejoignent jamais ce lot** — « validé ligue » n'a pas de sens sans championnat.
Un amical passé (domicile ET extérieur, même reconnaissance par libellé) se valide TOUT SEUL,
sans confirmation chiffrée : `App\Service\FriendlyAutoValidator::sweep` bascule `VALIDATED`
(source `MANUAL` posée sur le domicile seulement, l'extérieur garde sa source) tout amical
strictement passé, non déjà saisi (`SUBMITTED`/`VALIDATED` exclus) et sans écart en attente ;
idempotent, ne flushe que s'il y a eu un changement. Ce balayage est un effet de bord d'une
LECTURE, déclenché **uniquement quand un GESTIONNAIRE** charge la lecture « validé ligue »
(`guard()` de ce contrôleur) ou le cockpit (`EntryDeadlineOutlook`, §4) — un Membre, qui peut ouvrir
ces deux écrans, ne fait jamais écrire la base.

**`POST` reste SANS corps** : le serveur ne reçoit AUCUN championnat choisi par le client — il
**recalcule les championnats commencés au moment de l'application** (`fixturesToConfirm`, l'horloge
injectée + les échéances/premiers matchs du moment font foi, jamais ce que l'écran affichait à
l'ouverture). Bascule en lot chaque domicile `UNPLACED` d'un championnat commencé qui passe le
prédicat : statut `VALIDATED`
(horodaté) + `placementSource` `MANUAL` — le `MANUAL` n'est pas cosmétique, la formule du cadenas de
la grille et l'ancre `FIXED` du solveur de placement l'exigent (§3), sinon les rencontres basculées
s'afficheraient déverrouillées alors que le solveur les traite déjà en ancres. **On ne dévalide
jamais** : le prédicat ne regarde que les rencontres `UNPLACED`, donc rejouable sans effet sur une
rencontre déjà `VALIDATED` par ce geste ou par la réconciliation D9 : aucune de ces rencontres n'est
jamais reprise.

**Un seul geste, détaillé par championnat (décision fondateur)** — le front a écarté les cases à
cocher (choisir championnat par championnat) et les confirmations successives (une par championnat) :
la confirmation liste chaque championnat commencé avec son compte, puis une seule validation en
bascule tous les domiciles éligibles de tous les championnats commencés d'un coup.

⚠ **Divergence ASSUMÉE avec le contrôle d'accès du geste unitaire** : le placement
manuel d'un domicile refuse en 422 hors des créneaux d'accès match déclarés du gymnase
(`FixtureStateProcessor::assertVenueAccessAllowed`, §3) — le prédicat de ce lot ne fait PAS ce
contrôle, volontairement. La fédération a déjà enregistré cette réalité (date, heure, gymnase joués ou
programmés côté FBI) ; l'application la reflète au lieu de la nier. L'incohérence n'est pas tue :
le radar de conflits la signale (`ACCESS_WINDOW_LOST`, §2) — une rencontre basculée hors créneau
reste `VALIDATED` (le lot ne bloque jamais une réalité fédérale), le radar alerte. Figé par un NR
(`LeagueValidatedFixturesControllerTest`, cas hors créneau) : une passe future qui « corrigerait »
cet écart en y ajoutant le contrôle unitaire romprait le geste — c'est exactement le type de
divergence qu'un futur agent pourrait refermer par accident en croyant boucher un oubli.

**Isolation de saison, défense en profondeur** : les deux routes refusent
explicitement en 409 si aucune saison ne se résout (`_season_id` absent), au lieu de lire/écrire sur
TOUTES les saisons du club via un `findBy([])` non scopé si le filtre Doctrine venait à ne pas
s'activer — aucun chemin d'exploitation trouvé en revue, mais le comportement est maintenant explicite
et visible côté API (409, pas un silence qui élargirait le périmètre). Le POST refuse aussi en 409,
message actionnable en français, sur une collision d'écriture simultanée (verrou optimiste `Fixture`,
deux onglets ou un double-clic) — aucun verrou ajouté, l'écriture reste idempotente.

**Écran : proposé PARTOUT où le gestionnaire regarde (décision fondateur 2026-09-27, jamais
silencieux)**, même confirmation chiffrée partagée (`LeagueValidationConfirmDialog`, lib pure
`lib/leagueValidation.ts`) — refuser n'écrit rien. Quatre points d'ancrage :
- **Importer** (`LeagueValidationBanner`, sous la carte « Données de match ») : muet si les trois
  listes sont vides — couvre donc aussi les rencontres déjà en base sans re-déposer de fichier —,
  rendant TROIS blocs indépendants selon ce que le backend sert : les championnats commencés prêts
  (bouton de confirmation), les domiciles commencés à traiter (`LeagueToTreatNotice`, nommés, renvoi
  qui scrolle vers la file de traitement de la même page) et les championnats sans échéance
  (`MissingDeadlineNotice`, renvoi vers les « Échéances de saisie »). Ce MÊME bandeau se rend aussi
  sur le **Calendrier** (`CalendarPage`, gardé `canManage`) — un gestionnaire qui n'ouvre jamais
  l'onglet Importer le voit quand même.
- La section du rapport de fin d'import (`LeagueValidationReportEntry`) ouvre la même confirmation,
  muette si `totalValidatable` est nul.
- Le **cockpit** (`FbiDeadlineCard`, §4) porte une ligne dédiée « N à confirmer « validé ligue » »
  (compte `toConfirmCount`, servi par `EntryDeadlineOutlook`) + son propre bouton qui ouvre la MÊME
  confirmation — réservée au gestionnaire, jamais affichée à un Membre.

Le front n'a **aucune règle** : c'est le backend qui décide QUOI est proposé (championnat commencé),
le front affiche la lecture servie (`useLeagueValidationOutlook`). Vocabulaire : la pastille de
statut ne change pas (`VALIDATED` reste « Attesté FBI », §7 « Workflow de traitement ») — seule
cette confirmation emploie les mots du fondateur, « validé ligue ».

**Deux faits mesurés** : l'échéance d'un championnat est **saisie à la main** par le gestionnaire
(`EntryDeadlinesEditor`) — jamais reprise automatiquement du fichier fédéral, rien dans FBI ne la
porte. Sur la base réelle du fondateur, les compétitions de la saison ont TOUTES leur échéance
renseignée — une absence (`missingDeadline`) y serait donc une anomalie plutôt qu'un cas courant.

**`VALIDATED` n'est PAS un cul-de-sac (décision fondateur)** — un commentaire du code affirme
encore « VALIDATED is fully read-only — the league owns it. », c'est faux aujourd'hui (signalé aux
mainteneurs du fichier) : ce commentaire, comme le texte affiché (« Attesté par FBI : … »), ne
décrit que le chemin de réconciliation D9, pas la bascule en lot. `PlacementPanel` offre
**« Corriger — repasser en Placé »** sur `VALIDATED` comme sur `SUBMITTED` — quel que soit le
CHEMIN qui a posé `VALIDATED` (réconciliation D9 ou bascule en lot). Raison : une bascule en lot
peut porter sur des centaines de rencontres d'un coup, dont le gymnase peut venir d'un appariement
AUTOMATIQUE (§1, `OpponentVenueAutoLocator`) — sans sortie, un mauvais appariement les figerait
toutes, la seule échappatoire étant de supprimer le gymnase. Le texte affiché sous la pastille est
générique aux deux chemins (« Ce match est ancré sur les date, heure et salle enregistrées côté
ligue. Corrigez-le si l'une d'elles doit changer… »), sans attribuer l'ancrage au seul fichier
fédéral.

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
heure, mais son écart de SALLE a son propre détecteur (§1). Conséquence par champ :
date/salle **dé-placent** ; heure reste en place mais **rétrograde** un `SUBMITTED`/`VALIDATED` en
`PLACED`.

**Salle du périmètre PLACÉ, la MÊME clause alias que le non placé.** Un libellé fichier dont
l'alias confirmé pointe le gymnase où la rencontre est placée n'ouvre pas d'écart — sans cette
clause, un faux écart reviendrait à chaque dépôt quand la source nomme le gymnase par un alias que
l'appli connaît déjà, si seul le fuzzy nom↔libellé était lu ici. Identité STRICTE :
un alias qui désigne un AUTRE gymnase que celui du placement lève toujours l'écart. Comme la
source atteste alors les trois champs, la rencontre passe en `VALIDATED` (ci-dessus, D9) — ce
n'est pas un effet de bord, c'est l'attestation qui fonctionne enfin sur ce champ.

**Deux signaux complémentaires, DEUX chemins de code séparés — décision fondateur (`etat-des-lieux.md`
§2)** : `analyze()` (dry-run, ci-dessus — mêmes deux canaux xlsx et API) ne consulte JAMAIS le
registre et produit un enregistrement d'écart à CHAQUE dépôt tant que le fichier répète le libellé
divergent, même après un « garder l'appli » précédent — c'est la fonction, pas un défaut : le
gestionnaire doit continuer à être averti tant que FBI affiche la mauvaise valeur, jusqu'à ce qu'il
la corrige lui-même dans le portail fédéral. `import()`/`apply()`, eux, ne recréent PAS d'écart sur
la rencontre pour ce même cas (moteur partagé `processPerimeterFields`, mécanisme ledger) : ils
RE-DATENT seulement l'entrée du registre « à corriger dans FBI »
(`lastSeenInFbiAt`, ci-dessous) — le rappel persistant vit dans cette liste, pas dans un nouvel
écart de la rencontre. **Leçon** : analyser et appliquer ne partagent PAS ce filtre — les lire comme
un seul mécanisme fait conclure à tort que le dialogue s'est tu.

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
`null`, jamais un « 0 » fabriqué. **Les adversaires ont leur propre onglet**
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

## 8bis. Écran Contraintes (`/matchs/contraintes`)

L'écran UNIQUE des contraintes de match (P4-272 ①, demande fondateur « un endroit pour éditer les
contraintes de match, ligue et personnelles, prises en compte pour le placement automatique »), en
accordéon (`AccordionSection`, ancré `?section=<ligue|club|equipes|coachs>`, patron
`ConfigurationPage`). Sections **Ligue**, **Club** et **Équipes** livrées ; **Coachs** reste un
placeholder « Bientôt » qui pointe, en attendant, vers les écrans qui portent déjà ce réglage
(indisponibilités coach — pas encore d'écran dédié) — voir P4-272 ⑤ (`specs/evolution/roadmap.md`)
pour la suite.

**Section Ligue** : le CRUD gestionnaire de la copie club de l'enveloppe fédérale
(`ClubLeagueWindow`, §1) — un tableau éditable (catégorie, niveau, genre, jour, de/à),
ajout/suppression, badge `added`/`modified` calculé SERVEUR (affiché, jamais redérivé, § règle
frontend « le backend dit »). Bandeau si la copie est VIDE (« Aucune fenêtre ligue — le placement
n'applique plus de règle fédérale », §1). Le placement, le radar et cet écran lisent la MÊME copie
— une correction ici est immédiatement honorée par le placement automatique (§3) et le radar (§2).

**Bloc « Plages suggérées (estimation) » (P4-272 ②)** : sous le tableau, une aide FACULTATIVE
(`LeagueSuggestions.tsx`) qui propose la tendance dominante des plages saisies par les AUTRES
clubs de l'instance fédérale du demandeur — jamais une source de vérité, une estimation à
appliquer ou ignorer. Instance dérivée du `ffbbClubCode` du club (§ glossaire « Code club FFBB ») ;
son échelle dépend du NIVEAU de la combinaison (§ glossaire « Instance qui fixe les horaires de
match ») : `DEPARTEMENTAL` (et assimilés) groupe par **comité**, `REGIONAL` par **ligue**,
`NATIONAL`/`ELITE` par **fédération** entière. Une combinaison (catégorie, niveau, genre, jour)
n'est proposée que si l'ensemble de plages est saisi À L'IDENTIQUE par **≥ 3 clubs** de l'instance
**ET par plus de la moitié** des clubs de l'instance ayant saisi cette combinaison — sinon rien
pour elle (« une aide facultative, pas une vérité », pas de médiane). Seules les saisons `active`
des AUTRES clubs comptent, le demandeur est exclu. Sans tendance, repli sur le catalogue fédéral
de LA LIGUE DU DEMANDEUR SEULE (`ARA` → `AURA` aujourd'hui) — **jamais** la donnée d'une autre
ligue. Une combinaison déjà identique à la copie du club est MASQUÉE côté serveur (rien à
suggérer). Chaque ligne affiche « Estimation à partir de N clubs de votre comité / ligue — à
vérifier auprès de votre ligue et de votre comité » (un COMPTE, jamais lesquels) ou « données
fédérales » pour le repli. Geste **Appliquer** (par ligne) ou **Tout appliquer** : le serveur
RECALCULE la suggestion et REMPLACE la copie de la combinaison — aucune plage saisie par le client
n'est jamais écrite telle quelle. Réservé au gestionnaire (`GET`/`POST /api/league-window-
suggestions[/apply]`, `ManagementAccessGuard`). Le calcul cross-tenant vit dans la fonction SQL
`SECURITY DEFINER` `league_window_suggestions` (`docs/security/rls.md` § SECURITY DEFINER) —
gardée par le NR bloquant `Security/LeagueWindowSuggestionShareTest`
(`docs/testing/blocking-tests.md`).

**Section Club (P4-272 ③)** : le CRUD gestionnaire des règles de match du club
(`MatchConstraint`, §1, scope CLUB seulement — TEAM ④ vit dans la section Équipes ci-dessous, COACH
⑤ à venir). Chaque règle : un ou
plusieurs jours (`DayToggles`, bascule multi-sélection), un type — **Obligatoire** (HARD, honorée
par le placement) ou **Préférée** (PREFERRED, une préférence, `W_CLUB_RULE=30`, §3) — et une
fourchette de coup d'envoi « pas avant »/« pas après » (chaque borne facultative, au moins une
exigée par le serveur). Une ligne pointillée en bas ajoute une règle (POST) ; chaque règle existante
s'édite en place (PUT, bouton Enregistrer actif seulement si modifiée et complète) et se supprime
avec confirmation (`ConfirmDialog`, destructive). Liste vide → phrase neutre (« le placement ne
s'impose que les fenêtres de la ligue »), jamais un tableau vide muet.

Sous chaque règle, l'**alerte de cohérence** (`GET /api/match-constraints/coherence`,
`ClubRuleCoherenceChecker`, §1) affiche, en LECTURE SEULE, les créneaux idéaux d'équipe qu'elle
heurte (« Cette règle heurte le créneau idéal des <équipe> (semaine A/B) : <jour> <heure> ») — la
même donnée est affichée à l'ENVERS sous chaque créneau idéal concerné dans l'écran Semaine type
(§10, `IdealSlotsEditor`) : deux vues du même croisement serveur, jamais redérivées côté client. Ne
bloque ni n'empêche aucune écriture — le gestionnaire tranche (décision fondateur 2026-09-29 :
signaler, ne jamais bloquer).

**Section Équipes (P4-272 ④)** : le CRUD gestionnaire des INTERDICTIONS de gymnase par équipe
(`TeamsSection`/`TeamVenueBanRow`/`AddTeamVenueBanRow`, `MatchConstraint` scope TEAM, §1). Chaque
ligne : « **‹équipe›** ne joue jamais à **‹gymnase›** », toujours HARD (aucun choix de type à
l'écran — une interdiction n'est jamais une simple préférence, celle-ci vit dans l'habitude de la
semaine type, §10). Une ligne pointillée en bas ajoute une interdiction (deux `Select` équipe +
gymnase, POST) ; chaque interdiction existante se supprime avec confirmation (`ConfirmDialog`,
destructive) — pas d'édition en place (lever puis recréer). Liste vide → phrase neutre (« chaque
équipe peut jouer dans n'importe quel gymnase du club »), jamais un tableau vide muet. Un rappel
pointe vers la Semaine type pour la PRÉFÉRENCE de gymnase (l'inverse d'une interdiction). Pas
d'alerte de cohérence sur cette section — `ClubRuleCoherenceChecker` ne croise que les règles CLUB.

## 9. Écran Adversaires (`/matchs/adversaires`, au grain GYMNASE)

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
gymnase d'abord, puis alphabétique (fr). **Rang gelé au montage** (décision fondateur) : le front
fige cet ordre backend à la première liste non vide (`OpponentsPage.tsx`,
un `setState` posé PENDANT le rendu — patron React « stocker une info des rendus précédents »,
pas un effet) pour qu'un club tout juste apparié ne saute plus de place sous le curseur ; quitter
l'onglet et y revenir recalcule (assumé). Un club apparu après le gel s'ajoute en fin, rang
« infini ».

**Gestes d'appariement** passent par le menu APG partagé sur chaque ligne gymnase — **« Modifier le
gymnase » en premier** (ouvre `LocateOpponentModal` en mode remplacement, cf. ci-dessous), puis
« Fusionner dans « X » » par gymnase du MÊME club, puis « Retirer ce gymnase » (`DELETE`) — et une
`ConfirmDialog` dont le texte vient de la donnée SERVIE (`fallbackVenueName`, `fixtureCount` — le
front n'invente aucune règle métier, § `.claude/rules/frontend.md`). « Modifier le gymnase » et
« Fusionner dans « X » » appellent le **même** `PUT /api/opponents/venue-links/{id}` (re-pointage
du lien) : « Modifier » cherche le nouveau gymnase via `LocateOpponentModal` (recherche FFBB, cf.
ci-dessous), « Fusionner » pointe directement vers un gymnase déjà apparié au club — le libellé de
fichier reste reconnu dans les deux cas. « Ajouter un gymnase »/« Apparier » (**inconditionnels**, y
compris pour un adversaire sans code fédéral — voir ci-dessous) ouvrent `LocateOpponentModal`
(recherche `/api/ffbb/salles`, écrit via `POST /{code}/venues` ou `POST /{code}/venue-links`). La
recherche part **préfiltrée par le code postal fédéral de l'adversaire** (`OpponentClub.postalCode`,
additif servi par `GET /api/opponents/travel`) — le gestionnaire n'a pas à ressaisir un CP déjà
connu ; un sous-titre situe le club (« · Brignais 69530 »), jamais inventé quand ville/CP sont
absents de l'annuaire fédéral. Chaque résultat de recherche affiche « Nom · adresse · CP Ville »
(code postal fédéral de la salle, `commune.codePostal`, relayé par `GET /api/ffbb/salles` — détail
[`../../backend/docs/ffbb-api.md`](../../backend/docs/ffbb-api.md) § « Salles d'une commune »).

**Adversaires SANS code fédéral.** Un adversaire sans code (amical saisi à la main, coupe non
appariée) n'a par nature aucune clé d'appariement fédérale — sans traitement dédié, ses rencontres
resteraient hors du trajet et les deux boutons ci-dessus seraient absents (`club.code !== null`
garde sinon le geste). `App\Service\OpponentPairingKey` (maison unique) dérive une clé SENTINELLE
stable de son libellé normalisé (`'X' + sha256(libellé)[0:40]`, 41 caractères alphanumériques —
passe la borne `[A-Za-z0-9]+` des routes `/api/opponents/{code}/…`, tient dans le `VARCHAR(64)`
existant, **zéro migration**) quand le code fédéral est absent. `GET /api/opponents/travel` sert
cette clé (`pairingKey`) que le front réutilise TELLE QUELLE dans les routes d'écriture — il ne la
redérive jamais (🔴 `.claude/rules/frontend.md`). La sentinelle reste strictement LOCALE au club :
la garde qui neutralise toute ref fédérale pour une clé sentinelle vit ENTIÈREMENT dans la MAISON
UNIQUE `OpponentVenueLinkManager::writeGym` (AVANT toute résolution fédérale, `resolveFederalVenue`
jamais appelé pour une sentinelle) — le contrôleur ne porte **aucun** filtre propre, POST comme PUT
(une garde dupliquée sur le contrôleur court-circuiterait le manager AVANT qu'il soit atteint et
rendrait sa propre garde intestable par ce chemin). ⚠ **Piège de test** : un NR de sécurité qui
envoie une référence ne résolvant fédéralement dans AUCUN cas reste vert même la garde désactivée
(le payload ne crédite jamais, garde ou pas) — le stub FFBB doit servir une référence RÉELLEMENT
résolvable pour que le test soit probant. L'appariement reste tenant seul, jamais crédité au
catalogue partagé `opponent_venue_suggestion` (falsifié par `OpponentVenueSuggestionShareTest`,
rouge sur la garde du manager désactivée — POST et PUT). `LocateOpponentModal` remplace la section
« Gymnases connus » (le partagé fédéral n'a rien à proposer pour une sentinelle) par une phrase
d'explication : le choix restera propre au club.

**La modale enchaîne les libellés orphelins.** Après un appariement réussi,
`LocateOpponentModal` retire le libellé apparié d'une file LOCALE (figée à l'ouverture — le
libellé cliqué en tête puis les autres orphelins du même club) et avance au suivant, code postal
et liste de salles déjà chargée restent en place ; footer « Terminer » remplace « Fermer ». File
vidée → fermeture, comme avant. Le mode « Ajouter un gymnase » (pas de file, `fbiLabel` null)
garde la fermeture immédiate au succès.

**Recherche par nom.** `GET /api/ffbb/salles` accepte `q` (≥ 3 caractères) en
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
**Échec partiel** : `NoticeBanner` « n trajets n'ont pas pu être calculés » + bouton « Réessayer
les manquants » (`POST /api/opponents/travel/resolve`, qui ne route déjà que les paires siège→
gymnase manquantes). Bandeau siège (`useClubGeolocated`, § Prérequis du trajet AUTO) réutilisé tel
quel. **Garde anti-double-dispatch** : si un calcul de trajets tourne déjà pour le club (verrou
tenu), « Réessayer les manquants »/« Mettre à jour » ne redispatche rien — toast « Un calcul de
trajets est déjà en cours. » (`alreadyRunning` servi par la route, § Cache de trajets et calcul
asynchrone).

## 10. Écran Semaine type (`/matchs/semaine-type`)

Le MODÈLE sans dates que le placement respecte au maximum : le gabarit idéal (`TypicalWeekendGrid`
— créneaux idéaux Sam/Dim × gymnases, sans dates, collisions posées côte à côte) en vedette, et
l'éditeur **« Créneaux idéaux »** (`IdealSlotsEditor`, P4-271 — remplace l'ex-éditeur de rotations
« Créneaux partagés (alternance) ») : UNE ligne par équipe, tous les champs du créneau idéal
éditables EN PLACE (jour · heure · gymnase optionnel · semaine A/B/toutes) — une équipe porte UN
SEUL créneau idéal. Segmenté « Semaine A/Semaine B » sur le gabarit dès qu'un créneau idéal porte le
tag A ou B (sinon la grille reste identique à avant, aucun segmenté) — le tag est une AIDE VISUELLE,
jamais une contrainte, il ne voyage jamais au moteur (§3). Le bouton **« Passerelles »**
(`HabitsLinksDialog`, désormais dédiée aux seuls liens entre équipes — les créneaux idéaux ne s'y
saisissent plus) s'ouvre d'ici. Un signal « hors image » (écart entre placement réel et créneau
idéal du jour) et un signal « même week-end » (deux équipes dont le créneau idéal coïncide
physiquement — même gymnase+jour+heure — reçues à domicile le même week-end, contredit
l'alternance A/B) restent des SIGNAUX, jamais un blocage.

**Géométrie des blocs (P4-206, 2026-09-29)** : chaque bloc va du coup d'envoi à coup d'envoi + la
durée RÉELLE de match de la catégorie de l'équipe (`matchMinutesOf`, durées servies par
`GET /api/sport-categories` — override de club sinon défaut de famille), **exactement la même
géométrie que la grille datée du Calendrier** (§5) — aucun échauffement dessiné, aucun
enchaînement (la vue est un gabarit sans dates, pas un planning). `TypicalWeekPage` gate cette
lecture avec ses autres lectures de page : pas de repli silencieux sur une durée par défaut tant
que le serveur n'a pas répondu.

Sous un créneau idéal, l'**alerte de cohérence** (P4-272 ③, `GET /api/match-constraints/
coherence`, §1/§8bis) affiche les règles de match du CLUB qu'il heurte (« Heurte la règle du club «
<libellé> » ») — la même donnée serveur que la section Club de l'écran Contraintes, vue depuis le
créneau plutôt que depuis la règle ; LECTURE SEULE, ne bloque rien.

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
table tenant du module). **NR tenant GATANT (step nommé `blocking-tests` de `ci.yml` +
`docs/testing/blocking-tests.md`)** : `MessageHandler/ComputeTravelTimesHandlerTest` (GUC posé/
clear, verrou, jamais la ligne d'un autre club). Cache de trajets : `TravelTimeCacheTest` (clé
arrondie, jamais recalculé, profils séparés), `ClubTravelCacheSeedTest` (rejoue le seed de la
migration verbatim, zéro dérive). Client IGN : `IgnRoutingClientTest` (pacing 1/s, 429 réessayé,
journalisé). Progression Mercure : `TravelProgressPublisherTest`. Logo fédéral :
`OpponentLogoApiTest` (401 anonyme, 404, MIME, Cache-Control, code invalide). Contrats cross-stack
(groupe `contract`) : `MatchPlacementContractSchemaTest`,
`ValidateAssignmentsContractSchemaTest`, `HabitPayloadParityTest`,
`MatchVisitDeltaParityTest`, `ClubRulePayloadParityTest` (P4-272 ③, NR bloquant — `MatchConstraint`
scope CLUB ⇄ bloc `clubRules`, falsifié dans les deux sens, RLS), `ForbiddenVenuePayloadParityTest`
(P4-272 ④, NR bloquant — `MatchConstraint` scope TEAM HARD ⇄ `teams[].forbiddenVenueIds`, sur la
BONNE équipe, falsifié dans les deux sens, un scope CLUB ne fuit pas dans le bloc TEAM, RLS).
Alerte de cohérence règle ⇄
créneau idéal : `Unit/Service/ClubRuleCoherenceCheckerTest`. Sémantique solveur `club_rule_no_slot`/
`W_CLUB_RULE`/`team_venue_forbidden` : `engine/tests/semantic/test_match_placement_semantics.py` +
feature Behat `backend/features/placement-des-matchs.feature` (scénarios règle CLUB HARD/PREFERRED,
interdiction de gymnase honorée, seul gymnase ouvert interdit). Tables partagées : un `*ShareTest` par table (`OpponentDirectoryShareTest`
— whitelist `logo_id` compris —, `OpponentVenueSuggestionShareTest`, `EntryDeadlineShareTest`).
Périmètre engagé : `EngagedTeamGuardTest`,
`DeletionImpactParityTest`. Détecteur/radar : `MatchConflictDetectorTest`,
`FixtureConflictsApiTest` + feature Behat `les-conflits-d-un-match-disent-la-verite.feature`
(`ConflictTruthContext`, D1/D1 étendu, statuts joue/coache). Solveur : `test_match_placement*.py` (unit, sémantique, golden épinglé)
+ feature Behat `backend/features/placement-des-matchs.feature`. Import/réconciliation :
`FbiFixtureImporterTest`, `FfbbRencontresApiTest`, `FixtureReviewApiTest` + features Behat dédiées
(`un-domicile-importe-retrouve-son-gymnase`, `le-gymnase-du-fichier-localise-l-adversaire`,
`les-gymnases-d-un-adversaire-se-partagent-en-suggestions`, `un-conflit-traite-reste-visible-mais-decompte`,
`une-rencontre-importee-dit-si-elle-est-traitee`). « Validé ligue » en lot, piloté par l'échéance :
`LeagueValidatedFixturesControllerTest` (prédicat, idempotence, guards, verrou
optimiste, `testAnOutOfAccessWindowHomeFixtureStaysEligibleAndTheRadarSignalsIt` — la divergence
ASSUMÉE avec le contrôle d'accès unitaire, figée — et
`testAFutureDeadlineCompetitionIsProposedNowhere`/`testConfirmSkipsAFutureDeadlineCompetition`
(un championnat à échéance future n'apparaît ni au GET ni au POST) et
`testACompetitionWithoutDeadlineIsNamedNotValidated`), `MatchTenantIsolationTest` (étendu — club B
éligible, club A lit 0 et bascule 0) + feature Behat
`un-club-en-cours-de-saison-valide-ses-matchs-en-lot.feature` (`LeagueValidationContext`, deux
scénarios — échéance passée, échéance non passée). Registre « à corriger dans FBI » :
`FbiCorrectionApiTest` (lecture/close/reopen, 404 cross-club, 403 membre sur close),
`MatchTenantIsolationTest` (étendu) + feature Behat `ce-que-fbi-doit-refleter.feature`
(`FbiCorrectionContext`, suite `fbi-a-corriger`). Front : suites Vitest sous
`frontend/src/features/matches/` (`lib/*.test.ts` pour chaque dérivation pure, `*.test.tsx` par
écran) + e2e `frontend/tests/e2e/matches*.spec.ts`.
