# Module matchs (FFBB) — modèle & données transverses

> Entités season-scoped, tables fédérales GLOBALES, `OpponentVenueLink`, cache de trajets,
> alias de gymnase, écarts de salle et registre « à corriger dans FBI ». Découpé mécaniquement
> de [`module-matchs.md`](module-matchs.md) (anciens §0-§1) — contenu déplacé VERBATIM, non
> réédité, plafond `DocSizeCeilingTest`.

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
  `externalDivision` (nullable, interne — jamais servi par `FixtureResource` — le libellé de
  division FBI DU FICHIER d'origine, posé à la création à côté d'`externalRef`, non nul SSI lui l'est
  ; backfillé depuis `competition.name` pour les rencontres FBI déjà en base, migration
  `Version20261028120000`) : avec `externalRef`, il identifie une rencontre importée — équipe +
  division + numéro (D1/M15, 2026-10-28), index unique `uniq_fixture_external_ref` élargi à
  `(club_id, season_id, team_id, external_division, external_ref)` — jamais `competition.name` SEUL,
  qui peut être réutilisé/renommé au fil des dépôts ; au 2ᵉ tour une équipe change de poule/division
  et FBI réutilise ses numéros, sans la division dans la clé le n°X du 2ᵉ tour réécrivait le n°X de
  la 1ʳᵉ phase (bug M15, détail et procédure de réparation :
  [`module-matchs-import-fbi.md`](module-matchs-import-fbi.md) §7),
  `status` (`FixtureStatus` : `UNPLACED → PLACED → SUBMITTED → VALIDATED`), `venueId`/`kickoffTime`
  nullables, `fbiVenueLabel` (salle brute source, HOME et AWAY), `placementSource`
  (`MANUAL`/`SOLVER`/null legacy), `unplacedReason` (raison persistante : `venue_lost` — le gymnase
  n'est plus affilié au club — ou `NEUTRAL_GROUND` — domicile joué sur terrain neutre, § « Terrain
  neutre » ci-dessous), `keptVenueLabel` (idempotence d'arbitrage salle),
  `reviewState`/`reviewedAt`/`pendingDeviations` (workflow de traitement, §5). L'import FBI crée
  **tout** en `UNPLACED` à la CRÉATION, domicile et extérieur — mais le MÊME dépôt enchaîne, après le
  flush, une passe séparée qui bascule « validé ligue » les domiciles validables des championnats
  déjà COMMENCÉS (chantier A, décision fondateur 2026-10-22, § « Validé ligue » en lot ci-dessous) ;
  hors ce cas, seul un geste du gestionnaire pose un autre statut (`FixtureStatus` docblock).
  `neutralGround` (champ additif en LECTURE seulement, `FixtureResource`, dérivé de
  `unplacedReason === NEUTRAL_GROUND`) : le front l'affiche, jamais une seconde copie de la règle.
  `awayTravel`
  (champ additif en LECTURE seulement, `FixtureResource`, jamais persisté sur l'entité) : le trajet
  d'une rencontre EXTÉRIEURE, DÉRIVÉ de la rencontre elle-même — voir « Table TENANT
  `OpponentVenueLink` » ci-dessous.
- **`TeamMatchHabit`** : jour ISO + heure-point + gymnase optionnel — **UN par équipe** (unique
  `club_id, season_id, team_id`, P4-271 ; l'ancienne unicité par jour a disparu avec les rotations).
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
- **`MatchConstraint`** (table `match_constraint`, P4-272 ③+④+⑤) : une RÈGLE DE MATCH — `scope`
  `ConstraintScope` tranche la FORME (`CLUB`, `TEAM` et `COACH` sont saisis ; `FACILITY` seul reste
  refusé — une règle inerte serait illisible), `ruleType` `ConstraintRuleType`, `daysOfWeek` (ISO
  1=lundi..7=dimanche) et une fourchette de coup d'envoi `kickoffMin`/`kickoffMax` (HH:MM). Trois
  formes :
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
  - **COACH** (⑤, indisponibilité d'entraîneur) : `scopeTargetId` = le coach, OBLIGATOIRE et DU
    CLUB (même lookup tenant-filtré qu'④, étranger/inconnu → 422) ; `daysOfWeek` + fourchette
    portent la plage comme une règle CLUB (au moins un jour, au moins une borne, min ≤ max si les
    deux) — PLUSIEURS plages par coach ET par jour sont légitimes (aucune unicité) ; TOUJOURS
    PREFERRED (HARD refusé — une indisponibilité pèse au solveur, elle ne bloque JAMAIS un match) ;
    `venueId` NON PERTINENT, refusé s'il est renseigné (`MatchConstraintStateProcessor::
    applyCoachUnavailability`).

  Aucune unicité en base (plusieurs règles peuvent se recouvrir, une équipe peut s'interdire
  plusieurs gymnases, un coach cumuler plusieurs plages). CRUD gestionnaire (`GET`/`POST`/`PUT`/
  `DELETE /api/match_constraints`), sections Club, Équipes et Coachs de l'écran Contraintes (§8bis).
  Le moteur reçoit les règles CLUB VERBATIM dans le bloc top-level `clubRules`, les interdictions
  TEAM dans `teams[].forbiddenVenueIds` (liste triée, déterministe) et les indisponibilités COACH
  dans le bloc top-level `coachUnavailabilities` (verbatim {coachId, daysOfWeek, kickoffMin,
  kickoffMax}) du payload `/place-matches` (`CONTRACT_VERSION` 1.9) — un domaine vidé par les
  seules règles CLUB HARD ressort `club_rule_no_slot`, un domaine vidé par un gymnase interdit
  alors qu'un créneau licite y existait ressort `team_venue_forbidden` (précédence sur
  `club_rule_no_slot`, §3) ; une indisponibilité COACH ne vide JAMAIS de domaine — elle pénalise
  seulement le candidat retenu (`W_COACH_UNAVAILABLE=60`, §3 SOFT) ; les amicaux (`competitionId`
  nul) en sont exemptés structurellement, comme l'enveloppe ligue. Une pose MANUELLE hors d'une
  règle CLUB HARD ou dans un gymnase interdit à l'équipe reste PERMISE — le radar la SIGNALE
  (`CLUB_RULE_VIOLATION`/`TEAM_VENUE_FORBIDDEN`, §2), il ne la bloque pas ; **une indisponibilité
  COACH, elle, ne porte AUCUN radar** — une pénalité SOFT n'est pas un conflit à traiter (décision
  fermée, `etat-des-lieux.md` §2). L'**alerte de cohérence** (lecture seule, rien stocké) croise
  chaque règle CLUB avec les créneaux idéaux (`TeamMatchHabit`) qu'elle heurte
  (`ClubRuleCoherenceChecker`, `GET /api/match-constraints/coherence`, section Club seulement — les
  interdictions TEAM et les indisponibilités COACH n'y entrent pas), affichée sous la règle en
  section Club (§8bis) ET sous le créneau idéal concerné en Semaine type (§10) — ne bloque jamais
  rien, le gestionnaire décide. **Cascade suppression** : une équipe ou un gymnase supprimé emporte
  les interdictions TEAM qui le visent (`scopeTargetId`/`venueId`, `CascadePlan::forTeam`/
  `forVenue`), un coach supprimé emporte ses indisponibilités (`CascadePlan::forCoach`), annoncées
  dans la modale d'impact (§ `deletion-impact`, `backend-controllers.md`) ; une règle CLUB ne porte
  aucun des trois, jamais concernée par ces étapes.
- **`TeamLink`** (couple symétrique `teamAId < teamBId`, cap `MAX_TEAM_LINKS = 50`) : côté MATCHS
  `TeamLinkType` `NOT_SIMULTANEOUS`/`BACK_TO_BACK` — rail SOFT **placement seul** ; le radar de
  conflits ne charge jamais `TeamLink` (décision fermée, `etat-des-lieux.md` §2 : « ça fait plus de
  bruit qu'autre chose » côté radar) — § « Détecteur de conflits » ci-dessous ; côté ENTRAÎNEMENT
  `TeamLinkIntensity` `PREFERRED`/`MANDATORY` (honoré par le solveur d'entraînement — arbitrage :
  cette intensité ne gouverne jamais les matchs, `engine/docs/constraint-vocabulary.md` §Passerelles).
- **`TeamMatchHabit.week`** (`MatchWeek` `A`\|`B`, P4-271) : le créneau idéal (ci-dessus)
  porte un tag de semaine d'alternance — **AIDE VISUELLE**, jamais une contrainte : il alimente la
  vue A/B de l'écran Semaine type (§10) côté frontend seulement, il **ne voyage jamais au moteur**
  (le solveur voit une habitude, sans étiquette de semaine). La segmentation A/B de cet écran n'est
  affichée que si le club le déclare (`Club.weekendAlternates`, réglage gestionnaire — § « Écran
  Semaine type » ci-dessous) ; défaut `A` pour un club sans alternance (`ALL` supprimé, migration
  `Version20260930100000`). Remplace l'ex-`MatchSlotRotation` +
  `MatchSlotRotationTeam` (créneau physique partagé par N équipes en alternance ordonnée, retirés
  par P4-271 — deux équipes qui alternent sur le même créneau déclarent désormais chacune LEUR
  créneau idéal, tagué A ou B). L'habitude alimente aussi le solveur d'ENTRAÎNEMENT : `Team.matchDay`
  (`ScheduleConstraintBuilder::deriveMatchDay`, `POST /generate`) émet le DERNIER jour ISO de match
  de la semaine — le repos qui compte est celui d'après lui (`rest_day = match_day % 7 + 1`,
  `engine/app/solver/objective/terms/rhythm.py`) — pour le bonus SOFT « jour de repos après un match ».
  Sans habitude, repli sur le champ déclaré `Team.matchDay` (0-based, converti en ISO à l'émission).
- **`VenueMatchWindow`** (jour ISO + plage horaire, gymnase = « de match » ssi ≥ 1 fenêtre — aucun
  booléen sur `Venue`) et **`VenueUnavailability`** (plage de dates + motif, toutes circonstances,
  alerte seulement — jamais recopiée en N+1). **8ᵉ ligne « Jours fériés » (PR2, Q1 option c,
  décision fondateur 2026-10-27, migration `Version20261027120000`)** : `dayOfWeek` devient
  NULLABLE et la colonne `appliesOnPublicHolidays` (booléen, défaut `false`) porte la ligne férié
  — **exactement l'un des deux est posé** (ligne de jour de semaine : `dayOfWeek` + drapeau
  `false` ; ligne férié : `dayOfWeek` NULL + drapeau `true`), refusé en 422 sinon
  (`VenueMatchWindowStateProcessor`). Une ligne férié s'applique à tout jour férié officiel du
  territoire du club (national ∪ zone, maison UNIQUE `App\Service\ClubPublicHolidays`, partagée
  avec le radar et le placement — § « Jours fériés » de `module-matchs-radar-et-placement.md`),
  jamais à un jour de semaine particulier. **Ouverture datée** (lot « ouverture datée », migration
  `Version20261101120000`) : 3ᵉ cible, `openingDate` (date) + `label` (motif libre ≤ 120, écran
  seulement, jamais envoyé au moteur) — **exactement l'une des trois cibles** (jour, férié, date),
  422 sinon. Une ouverture S'AJOUTE aux lignes de sa date (férié ou non) et ne vaut que pour elle ;
  elle vaut pour TOUT match ce jour-là (décision fondateur, pas de ciblage par compétition) ; une
  date hors saison est acceptée (l'écran avertit, ne bloque pas) ; jamais recopiée au passage de
  saison (`SeasonTransitionService`, fait d'un jour comme `VenueUnavailability`). **Le club n'« adopte »
  les accès match qu'avec ≥ 1 ligne NON datée** (`MatchConflictDetector::hasWeeklyAccess`) : une
  ouverture seule ne bascule pas le club en « accès obligatoire ». **Fermeture du calendrier** (`venue_closed`,
  P4-300) : pas une entité propre — une contrainte `Constraint`/FACILITY datée, portée par une
  entrée de calendrier (§ « Gymnases » de `accueil-cockpit-temporel.md`). Elle s'applique AUSSI
  aux matchs : maison unique `PlanVenueClosures::closureIntervals` (le FAIT brut — dates du
  `config`, repli legacy = fenêtre de l'entrée porteuse, **jamais** la composition
  `VenuePeriodOverride` d'un plan d'entraînement) consommée par `MatchPlacementPayloadBuilder`
  (fusionnée dans `venues[].unavailabilities` du payload `/place-matches`, sans bump de contrat),
  `ConflictRadarLoader` (fusionnée avec `VenueUnavailability` dans la même famille radar), et
  `FixtureStateProcessor::assertVenueAccessAllowed` (refus 422, placement manuel).
- **`ConflictResolution`** (clé `(club, saison, fingerprint)`, l'empreinte STABLE d'un conflit) :
  statut de traitement — voir §6.
- **`MatchModuleVisit`** (clé `(club, saison, user)`) : référence de visite pour le delta — voir §8.
- **`FbiIngestion`** (`club+saison`, non personnelle) : fraîcheur + compteurs d'un dépôt, une par
  canal (`FBI_XLSX`/`FFBB_API`).
- `Venue.externalLabels` (JSON normalisé/dédupliqué) : alias FBI/FFBB confirmés — voir §5.3.

Recopie en N+1 (`SeasonTransitionService`) : habitudes (créneau idéal + tag `week` remap
équipe+gymnase), passerelles, fenêtres d'accès, la copie club de l'enveloppe ligue
(`ClubLeagueWindow`, verbatim depuis la saison source), les règles de match (`MatchConstraint`,
P4-272 ③+④+⑤) — CLUB : verbatim (ni gymnase ni équipe à remapper, `scopeTargetId`/`venueId` restent
nuls) ; TEAM (interdiction de gymnase) : remap de l'équipe ET du gymnase (mêmes tables de
correspondance que les habitudes) ; COACH (indisponibilité d'entraîneur) : remap du coach seul
(jours + fourchette conservés) — dans les trois cas une référence PENDANTE (équipe, gymnase ou
coach disparu en N+1) fait ABANDONNER la ligne, jamais un pointeur mort en base. Les
indisponibilités de GYMNASE (`VenueUnavailability` — travaux, fermeture ponctuelle) et les
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
Mercure, topic `/clubs/{clubId}/travel`, `docs/security/mercure.md`), jamais un spinner par ligne.
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

**Pool de 60 paires NON calculées par run (D6/D7, retours Nico BCCL 2026-10-27, précisé fondateur).**
`resolve()` partitionne d'abord le cache PUIS applique le cap `OpponentTravelResolver::MAX_OPPONENTS`
(60) sur les seules paires MANQUANTES — jamais sur le total. Une paire déjà en cache ne consomme
jamais de place (le cas BCCL : 129 adversaires dont 100 en cache + 29 manquants calcule les 29 d'un
coup, zéro « en attente de relance ») ; au-delà de 60 manques, le run route le pool de 60, laisse le
reste `awaiting_retry`, et une relance reprend le pool suivant jusqu'au bout. Le cap borne aussi les
appels IGN sortants (anti-monopole : un club gonflé ne dépasse jamais 60 sorties par run).
**Plus de 422-cap côté contrôleur** (l'ancien « réduisez le nombre de rencontres » a disparu) : la
relance n'est jamais refusée au-delà de 60 — restent le limiteur d'abus `opponent_travel_resolve`
(10/h/utilisateur → 429), le verrou anti-double-calcul et le dispatch. **Pas d'auto-chaînage** : le
worker ne ré-enfile RIEN après un run incomplet (figé par `ComputeTravelTimesHandlerTest`), c'est le
gestionnaire qui relance.

**M14 (retours Nico, BCCL 2026-10-27) — la cause du DERNIER échec, par gymnase.** Un trajet non
résolu l'est pour deux raisons bien distinctes, invisibles l'une de l'autre côté écran avant ce lot :
le budget worker s'est épuisé AVANT d'atteindre la paire (une relance suffit) ou IGN est resté muet
sur elle (pas d'itinéraire / gymnase mal géolocalisé — une relance seule n'y changera rien).
`App\Service\Geo\OpponentTravelFailureStore` mémorise cette carte (clé d'appariement adversaire →
`awaiting_retry`\|`unroutable`) dans **Redis** (même connexion que `TravelComputeLock`, clé
`opponent_travel_failures:{clubId}:{seasonId}`, TTL 30 jours) — état VOLATILE, pas une donnée
métier, écrasée à chaque run de `OpponentTravelResolver::resolve` (elle reflète le DERNIER calcul,
jamais un cumul ; un code résolu depuis en disparaît). `GET /api/opponents/travel` sert
`travelFailureReason` par ligne de gymnase (`null` si calculé, en cours, ou jamais tenté) — le
bandeau d'échec partiel de l'écran Adversaires l'agrège et nomme les gymnases concernés (§ Écran
Adversaires). Détail client Redis/TTL/clé : `backend/docs/geo-api.md` §2.

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

Un domicile importé porte un libellé de salle fédéral (`fbiVenueLabel`). Côté canal API
(`FfbbRencontreReconciler::apply`) ou pour une rencontre déjà existante, il peut encore n'avoir
aucun `venueId` — invisible des familles `VENUE_OVERLAP`/`VENUE_UNAVAILABLE` — tant que son libellé
n'a pas d'alias confirmé ; côté canal xlsx, depuis « pas de salles appariées, pas d'import »
(chantier A PR 2, § « Import FBI » ci-dessous), un domicile NOUVELLEMENT CRÉÉ ne peut plus naître
sans gymnase (sauf terrain neutre, qui n'en veut pas). `VenueAliasResolver::resolveConfirmed`
(égalité STRICTE normalisée sur un alias confirmé) pose `venueId` automatiquement aux DEUX canaux
d'intégration (xlsx `FbiFixtureImporter::attachConfirmedVenue`, API `FfbbRencontreReconciler::apply`
— jamais un statut ni une date, jamais sur un AWAY) ; `::suggest` (fuzzy, ambiguïté ≥ 2 candidats →
`null`) nourrit `FixtureResource.suggestedVenueId` en lecture seule. Normalisation : foyer unique
`VenueLabelNormalizer` (translit ASCII, minuscule, non-alphanumérique → espace). Le GESTE de
rattachement (gardes vide/longueur/nombre d'alias, unicité, backfill) vit dans la MAISON UNIQUE
`App\Service\Basketball\VenueExternalLabelAttacher` (extraite de `VenueExternalLabelController`,
chantier A PR 2) — partagée par ce contrôleur (chemin manuel, §8) et par l'import xlsx, qui
l'appelle pour chaque `venueMapping`/`venueChoice` reçu AVANT la vérification de couverture : un
même geste d'appariement, jamais deux copies. Routes (`VenueExternalLabelController`) : `GET
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
source répète ce libellé, la question ne revient pas) ; « Prendre le fichier » ne vide plus jamais
`venueId` (chantier A PR 4, § ci-dessous « Arbitrage d'un écart de salle »). Le geste vit dans la
file de traitement de l'onglet Importer (§4), pas dans le rapport de dépôt.

### Arbitrage d'un écart de salle : appariement INLINE, jamais un `venueId` null (chantier A PR 4)

**Décision fondateur 2026-10-23** (retours Rillieux, chantier A, PR 4/5, Option A) : un domicile ne
perd **jamais** son gymnase à l'arbitrage d'un écart de SALLE — ni le détecteur NON PLACÉ ci-dessus,
ni la fenêtre « FBI fait foi » d'un domicile déjà PLACÉ (§7 « Workflow de traitement »). « Prendre le
fichier »/« Prendre la source » porte désormais un `venueId` OPTIONNEL sur une décision de champ
`venue`, aux **deux** canaux hors-dépôt (`ReviewFixtureDeviationController`) et API
(`FfbbRencontresController` / `FfbbRencontreReconciler`). Foyer partagé
`FbiDeviationService::applyVenueTakeFile` décide la cible AVANT toute écriture :
- **uuid d'un gymnase du club+saison** : rattache le libellé source à ce gymnase (MAISON
  `VenueExternalLabelAttacher`, mêmes gardes — longueur ≤ 120, plafond 30 alias, libellé déjà alias
  d'un AUTRE gymnase → 422) et pose `venueId` — apparier ne place JAMAIS (le statut ne change pas) ;
  un gymnase d'un AUTRE club ou d'une AUTRE saison est invisible (filtres tenant/saison) → **404**,
  avant toute mutation (`ownedVenueOrRefuse`) ;
- **sentinelle `FbiFixtureImporter::NEUTRAL`** : pose `unplacedReason = NEUTRAL_GROUND` — refusée
  (422) si le libellé source est DÉJÀ un alias confirmé d'un gymnase du club (ce n'est alors pas
  neutre) ;
- **sans `venueId`** : l'alias confirmé du libellé source repose la salle seul (comportement
  antérieur, inchangé — « les 20 cas ») ;
- **faute des trois** : RIEN n'est écrit — 422 nommé côté arbitrage hors dépôt, l'écart simplement
  RETENU côté import/API (`heldBackEcart`, le domicile reste déphasé `OUT_OF_SYNC`), jamais un
  dé-placement vers `venueId` null.

Front (`ReviewQueueRow`) : sur un écart `venue` seulement, le bouton direct « Prendre … » est
remplacé par un `VenueSelect` (gymnases du club + option « Terrain neutre (pas chez nous) »,
sentinelle `'NEUTRAL'`) suivi du bouton, qui reste **désactivé** (`disabledReason`, A11Y-30) tant
qu'aucun choix n'est fait. Les champs date/heure gardent le bouton direct, inchangé.

**Hors périmètre** : la CRÉATION de rencontres par le canal API
(`FfbbRencontreReconciler::newFixture`) n'est pas concernée — décision fondateur en attente.

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

