# Module matchs (FFBB) — état livré

Last verified @ 2026-09-09 (P4-188 + P4-189 + P4-191, `documentation-update`). § « Détection —
`MatchConflictDetector` » recalée sur `EffectiveScheduleResolver::resolve` (`EffectiveScheduleResolver.php:38-54`,
lu) : la période la plus ÉTROITE gagne (`end − start` minimal, égalité → première), un enfant plus étroit sans
version pointée gagne quand même — remplace l'ancienne « première période de la liste » (faux négatif
`MATCH_TRAINING` sur une fermeture découpée, P4-188). Règle coach (`MatchConflictDetector::pairRole`,
`MatchConflictDetector.php:724-733`, lu) inversée : MAIN seulement si principal des DEUX équipes, sinon
ASSISTANT — libellé front « (assistant d'un côté) » vérifié dans `ConflictRadar.tsx:45` (P4-189). Bornes de
conflit (`start`/`end`/`windowStart`/`windowEnd`) en heure murale sans offset, `MatchConflictDetector::
WALL_CLOCK_FORMAT = 'Y-m-d\TH:i:s'` (`MatchConflictDetector.php:90`, lu) — `ConflictFingerprinter.php` confirmé
sans re-lecture de ces champs (P4-191). Reste du fichier (Espace Importer, canal API, réconciliation,
Configuration, P4-185/187) non re-sondé cette passe.
> ⚠ **Le module est autonome dans ses DONNÉES, pas dans son OUVERTURE.** Décision fondateur du
> 2026-07-31 (arbitrage DOC-1) : le couplage livré fait foi, la spec d'évolution a été alignée
> dessus — **le gating reste**. Créer un match (`FixtureStateProcessor`) comme importer un fichier
> FBI (`ImportFixturesController`) appellent `App\Service\SocleGuard::assertSeasonPlanChosen`, qui
> rend **409** tant que le plan SEASON ne **pointe** aucune version (ADR-0002 inv. 13, seuil 2 du
> cockpit — voir [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) §0). Le front
> verrouille l'entrée « matchs » sur le même critère (`chosenScheduleId`).
> **Le motif** : un match se *place* dans un calendrier — le radar de conflits compare la rencontre
> aux séances d'entraînement. Sans socle en vigueur, il n'a rien à comparer. L'autonomie promise en
> évolution porte sur le **modèle** (entités séparées, rien dans le payload solveur) et sur l'**UI**
> (workspace « Compétition » distinct), pas sur la porte d'entrée.

## Palier A — PR-1 (socle backend, 2026-07-06)

### Modèle (entités season-scoped, tenant-owned)

- **`Competition`** (`competition`) — phase/championnat d'une équipe : `teamId`, `name`, `competitionType`
  (`CHAMPIONSHIP`/`CUP`/`BRASSAGE`), `startDate`/`endDate` nullables. N par équipe.
- **`Fixture`** (`fixture` — `match` est un mot-clé PHP) : `teamId`, `competitionId` **nullable = amical**,
  `matchDate`, `homeAway` (`HOME`/`AWAY`), `opponentLabel` (label libre ; l'annuaire adverse global = palier B),
  `status` (`UNPLACED → PLACED → SUBMITTED → VALIDATED`, cf. workflow 2-temps — **VALIDATED n'est
  plus un geste depuis PR-3a** : posé par l'import quand la source atteste le match placé, D9 ci-dessous),
  `venueId`/`kickoffTime` nullables (domicile posé, extérieur estimé).
- API Platform 5-fichiers pour chaque (Resource/Input/Processor/Provider) → CRUD `/api/competitions`,
  `/api/fixtures`, filtrage tenant+season **automatique** (filtres SQL) + garde readonly-saison héritée (409).

### Empreinte-temps — `MatchFootprint` (durées PAR CATÉGORIE depuis P2-54 PR-1, 2026-08-28)

Service pur (spec §4bis) : fenêtre d'occupation d'une personne pour un match. **Les durées ne sont
plus des constantes** : un profil `{matchMinutes, warmupMinutes}` (`MatchDurationProfile`) est passé
par l'appelant, résolu par **`MatchDurationResolver`** — colonnes nullables `sport_category.match_minutes`
/`warmup_minutes` (override club par catégorie, écran « Durée des matchs » du SET-UP), sinon **défaut de
famille** : U7-U11 75/30 · U13-U15 90/30 · U18-U21 105/30 · repli (Seniors, noms custom) 105/30 — le
Resource **sert** `defaultMatchMinutes`/`defaultWarmupMinutes` résolus, le front n'en code aucun
(anti-redérivation). Domicile = `kickoff−échauffement → kickoff+durée_match`. Extérieur = + **trajet
aller-retour** (injecté, 0 jusqu'à la PR-3) — **la douche et le battement SONT SORTIS de l'empreinte**
(décision fondateur 2026-08-28 : négligeables, le coach enchaîne en mordant sur l'échauffement suivant ;
les anciennes constantes 30+15 sont supprimées). ⚠ **Divergence ASSUMÉE** : le solveur de placement
(`engine/app/solver/match_placement.py`, `AFTER_KICKOFF_MIN = 105`) garde son empreinte figée — le radar
est par catégorie, le placement moteur non ; à réconcilier si un club le constate, pas avant.

### Catalogue-ligue — `LeagueMatchWindow` (table GLOBALE)

Fenêtres de coup d'envoi imposées par la fédé (jour + `kickoffMin`/`kickoffMax`) par `league × category ×
level × gender`. **Hors tenant** (pas de club_id/season_id, pas de RLS — patron `public_holiday`), seedé via
`app:league-windows:seed` depuis `backend/data/league-match-windows.aura.json`. **Seed AURA = base par défaut
de TOUT club** (couche 1 des 3 couches). Ligue dérivée du `ffbbClubCode` par **`LeagueResolver`** (préfixe
3 lettres) → `Club.league` (posé au register). `GET /api/league-match-windows` → l'envelope héritée, fallback
AURA si la ligue n'est pas cataloguée. Rejoué par la cible `make play`/`make reset` (racine) depuis
le 2026-09-03 (`backend/Makefile` cible `seed-league`, idempotent — upsert par clé naturelle,
`app:league-windows:seed`) : avant, la commande existait mais n'était appelée par aucune chaîne
automatisée, la table restait vide tant que personne ne la lançait à la main.

### Annuaire adverse — `OpponentDirectoryEntry` (table GLOBALE, P2-54 RMM-9 PR-2, 2026-08-28)

Où joue un adversaire, résolu **automatiquement** (le gestionnaire ne rattache RIEN). Table `opponent_directory`
**hors tenant** (patron `shared_competition_deadline`/`public_holiday` : pas de club_id, pas de RLS,
**GRANT SELECT/INSERT/UPDATE sans DELETE** — `REVOKE DELETE` explicite dans la migration car un
`ALTER DEFAULT PRIVILEGES` antérieur conférait DELETE à `app_user`, cf. `Version20260827150000`), keyée sur le
**code organisme fédéral public**, colonnes `name`/`city`/`postalCode`/`latitude`/`longitude`/`precision`
(`VENUE`|`CITY`)/`venueLabel`. **Public fédéral SEULEMENT** — le docblock d'entité porte le corollaire
opposable : n'y jamais ajouter de provenance/compteur/donnée club sans repasser la revue sécurité.

**`OpponentLocationResolver`** — échelle, best-effort à chaque étage (une panne réseau → adversaire « non
localisé », JAMAIS un import bloqué) :
1. **Salle exacte du hit rencontre API** (`FfbbRencontreReader::readAwayOpponents` — l'objet `salle` porte
   `cartographie.coordonnees.coordinates`, coords fédérales autoritatives, sondé le 2026-08-27) → précision
   **`VENUE`**, gratuit.
2. **Repli VILLE** : code organisme adverse → index `ffbbserver_organismes` (`_geo`, sinon commune/CP géocodés
   `BanGeocodingClient`) → précision **`CITY`**. C'est le cas nominal du canal xlsx et du rattrapage
   (décision fondateur : la ville suffit, cf. `gestion-matchs-ffbb.md` §5bis amendé).
3. Rien ne résout → pas de ligne, l'adversaire reste non localisé.
Upsert par code organisme, `VENUE` > `CITY` jamais l'inverse ; un adversaire déjà en `VENUE` est laissé tel
quel (zéro appel réseau).

🔴 **Invariant de sécurité (revue 2026-08-28) : la précision `VENUE` est RÉSERVÉE au canal API.** Un libellé
de salle issu d'un **xlsx est fourni par le CLUB** — l'accepter comme `VENUE` laisserait un club épingler un
adversaire réel à un faux gymnase, écrit dans la table partagée et lu byte-identique par tous les autres
clubs, de façon **permanente** (premier-VENUE-gagne). L'étage « appariement franc par nom de salle » a donc
été **retiré** ; le canal xlsx plafonne à `CITY`. Deux gardes **bloquants** : `OpponentDirectoryShareTest`
(schéma partagé — zéro colonne club, byte-identique, pas de DELETE) et `OpponentLocationResolverTest`
(comportement — xlsx `directVenue=null` → CITY jamais VENUE ; API `directVenue` peuplé → VENUE ; falsifiés
dans les deux sens).

⚠ **Portée de l'invariant, nuancée par P4-187a (2026-09-09)** : cette réserve vise la table
**partagée** `opponent_directory` (localisation d'un ADVERSAIRE, hors tenant, lue byte-identique
par tous les clubs). Elle ne dit rien du gymnase **propre** d'un club, qui vit sur sa propre
`Venue` (tenantée, RLS). Un libellé xlsx peut donc résoudre le gymnase du CLUB lui-même — jamais un
adversaire — à condition de passer par un alias **confirmé explicitement par le gestionnaire du
club** (§ « Gymnase depuis le libellé », ci-dessus), pas par une devinette de nom sur un dépôt
xlsx brut. La devinette floue (`suggestedVenueId`) reste, elle, un affichage seul, jamais posée
automatiquement.

**Alimentation** : hook post-import xlsx (`ImportFixturesController`), hook post-apply canal API
(`FfbbRencontresController`), et rattrapage `POST /api/opponents/resolve` (management SEC-07, cap dur avant
réseau + rate-limit `opponent_resolve` par user). L'annuaire est CONSOMMÉ par le trajet (§ suivante, PR-3).

### Trajet AWAY & radar spatial — `OpponentTravel` (table TENANT, P2-54 RMM-9 PR-3, 2026-08-28)

Le radar de conflits devient **SPATIAL** : un coach qui joue à l'extérieur est vu occupé le temps du trajet.
- **Clé de jointure** : `Fixture.opponent_organisme_code` (colonne nullable, ajoutée PR-3) — le code fédéral de
  l'adversaire, **stampé best-effort par `OpponentLocationResolver`** aux trois hooks (import xlsx, apply API,
  rattrapage) au moment où il résout déjà ce code. Une AWAY sans code résolu reste « non localisée ».
- **Table TENANT `opponent_travel`** (`TenantOwnedInterface`, RLS FORCE — patron `venue_travel_time`) :
  club+saison+code adverse → `travelMinutes` (aller simple voiture, nullable), `source` `AUTO`|`MANUAL`, surcharge
  de gymnase (`overrideVenue*`). ⚠ **Le trajet dépend du siège d'un club précis → donnée CLUB-spécifique, elle
  vit ICI (tenant), JAMAIS dans `opponent_directory` (public).** Le MANUAL n'est jamais écrasé par l'AUTO.
- **Calcul** (`OpponentTravelResolver`) : lieu = surcharge MANUAL si présente, sinon lat/long de
  `opponent_directory` ; `IgnRoutingClient` voiture depuis `Club.latitude/longitude`. Best-effort (IGN en panne →
  `travelMinutes` null, jamais une erreur bloquante). Route `POST /api/opponents/travel/resolve` (management,
  cap + rate-limit `opponent_travel_resolve`). Correction MANUAL via `/api/ffbb/salles` + retour-à-l'AUTO.
- **Injection radar** : `FixtureConflictsController` passe `roundTripTravelMinutes = 2 × OpponentTravel.travelMinutes`
  à `MatchFootprint::occupancy(...)` par AWAY ; sans code/sans trajet → 0 (comportement actuel, pas de conflit
  spatial — dit franchement). Détecteur toujours PUR.
- **Écran** (`AwayTravelChip`, `OpponentTravelCard`, `LocateOpponentModal`) : chip trajet + précision par
  icône+mot sur `AwayList` (VENUE « Gymnase · 22 min » / CITY « ville de X · ~35 min · approché »), carte
  « Adversaires à localiser » au SET-UP, correction en modale per-adversaire (badge Auto/Manuel). La précision,
  les minutes et le flag « approché » sont **servis par le backend** (le front ne les re-dérive pas).
- NR isolation : `MatchTenantIsolationTest` étendu (`opponent_travel` scopé, un MANUAL de A ne fuit pas à B —
  bloquant, ce fichier est déjà un step de `blocking-tests`). `/security-review` de la PR : **zéro finding**
  (RLS FORCE complet, aucune écriture au global, coords MANUAL range-validées et self-scoped).
- ⚠ **Divergences ASSUMÉES** : (1) le SOLVEUR de placement garde 105 min fixe (`match_placement.py`, contrat
  2.16 inchangé) — le trajet nourrit le radar préventif, pas l'optimisation moteur ; (2) le dessin de la grille
  week-end reste 2h15 fixe (`weekendGrid.ts:4-6`, présentation pure — le radar serveur est la source de vérité).

## Palier A — PR-2 (moteur de conflits, à la volée, coach seul, 2026-07-07)

### Détection — `MatchConflictDetector` (service pur)

Croise l'empreinte-temps `MatchFootprint` d'un `Fixture` avec les autres occupations d'un **même coach**
(périmètre coach seul ; les joueurs = plus tard). Dans un club amateur match et entraînement ne peuvent
**jamais** se superposer → la valeur est de le voir dès la saisie. Deux types :

- **`MATCH_MATCH`** : deux `Fixture` d'équipes partageant un coach (via `TeamCoach.coachId`) dont les fenêtres
  d'occupation se chevauchent.
- **`MATCH_TRAINING`** : un `Fixture` chevauchant un entraînement d'une équipe du coach, lu dans le **planning
  effectif à la date du match**. Une période ACTIVE **capture** les dates qu'elle couvre : à l'intérieur le
  planning de base ne s'applique pas — son **overlay**, c'est-à-dire la **version choisie du plan de la
  période** (`SchedulePlanProvisioner::chosenByPeriodPlans`, ADR-0002 lot D-b du 2026-07-18 ; le champ
  `CalendarEntry.overlayScheduleId` a été **supprimé** à cette occasion, et un plan de période qui ne pointe
  rien = **aucun overlay**), s'il existe,
  **sinon aucun entraînement** (une coupure = « pas d'entraînement », donc aucun conflit fantôme). Hors période = la
  **version choisie du plan SEASON** (`SchedulePlanProvisioner::chosenOfSeasonPlan`, ADR-0002). Le créneau hebdo (`ScheduleSlotTemplate`, `dayOfWeek`+`startTime`+`durationMinutes`)
  est **projeté sur la date**, puis chevauché. Le coach en conflit = le `coachId` **assigné au créneau** s'il
  existe, sinon les coachs de l'équipe du créneau (pas de faux positif sur un co-coach qui ne tient pas la séance).

Chevauchement demi-ouvert (créneaux jointifs = pas de conflit). Une empreinte qui **passe minuit** (coup d'envoi
tardif) est vérifiée sur les **deux jours** qu'elle couvre. Périodes qui se chevauchent (une fermeture racine
découpée partage sa date de départ avec son enfant « milieu » — une racine qui part un lundi n'a pas de « début ») → résolution **déterministe
par la plus ÉTROITE** (`EffectiveScheduleResolver::resolve`, `end − start` minimal, égalité → la première de la
liste ; un enfant plus étroit qui ne pointe RIEN gagne quand même — la coupure suspend l'entraînement, jamais
un repli silencieux sur la racine sans plan) : l'ordre de chargement (`CalendarEntryRepository::
findActivePeriodsOrdered`, `ORDER BY startDate, id` — des UUIDv4 aléatoires) ne peut pas garantir qu'un enfant
précède sa racine, la largeur si (P4-188 — corrige un faux négatif MATCH_TRAINING mesuré sur une fermeture
découpée, incident du 3 sept. 2026). Un `Fixture` AWAY sans `kickoffTime` n'a pas d'empreinte (trajet = palier
B) → il ne génère aucun conflit — voulu.

**Amicaux (P4-190, 2026-09-08)** : une rencontre sans compétition (`competitionId` null) n'est jamais
comparée aux fenêtres ligue de son équipe — `leagueWindowViolations` la saute ; elle reste soumise aux
autres familles. Le solveur de placement, lui, applique encore les fenêtres par équipe (roadmap P4-193).

### Endpoint — `GET /api/fixtures/conflicts`

Contrôleur invokable `FixtureConflictsController` (route `priority: 10` pour passer avant `/api/fixtures/{id}`
d'API Platform). Recalcul **à la volée** à chaque appel, **rien n'est persisté**. Charge fixtures + `TeamCoach`
+ périodes-overlay actives + slots du planning effectif via les repos (scope club+saison **automatique**).
Réponse : `{ clubId, seasonId, conflicts: [{ type, coachId, start, end (segment de chevauchement),
left/right | fixture/training }] }`. **Toutes les bornes datées** (`start`/`end`, `windowStart`/`windowEnd`
du bloc `training`, `left.windowStart`/`right.windowEnd`…) sont l'heure MURALE du club, sans décalage
(`Y-m-d\TH:i:s`, ni `Z` ni offset — constante `MatchConflictDetector::WALL_CLOCK_FORMAT`, P4-191) : un `+00:00`
ATOM aurait laissé un navigateur d'un autre fuseau décaler l'heure affichée, `ConflictRadar.tsx` les
parse et les rend en local sans conversion. `ConflictFingerprinter` exclut ces bornes de son empreinte
(le fingerprint ne bouge pas avec le format d'affichage) — pas de vague de « Nouveau » causée par ce
correctif.

## Palier A — PR-3 (grille week-end UI, 2026-07-07)

Feature frontend `frontend/src/features/matches/` (route `/matchs`, entrée nav). **Frontend seul**, consomme
les endpoints PR-1/PR-2 — aucun ajout backend.

- **Grille week-end** (`WeekendGrid` + `lib/weekendGrid.ts`) : calendrier daté week-end-centrique (colonnes =
  date × gymnase, lignes = créneaux), distinct du canevas lun-sam de l'entraînement. Chaque match placé =
  bloc d'une **empreinte VISUELLE fixe 2h15** (constantes locales `weekendGrid.ts:5-6` — résidu assumé
  depuis P2-54 PR-1 : le radar calcule par catégorie, le DESSIN de la grille non ; présentation pure, à
  réconcilier quand l'empreinte voyagera à l'écran — PR-3), libellé au coup d'envoi. Navigation ‹ › entre
  week-ends. Les matchs non placés / AWAY-sans-heure vivent dans la liste « À placer ».
- **Pose domicile** (`PlacementPanel`) : clic sur un match à placer → panneau (salle + heure) →
  `PUT /api/fixtures/{id}` (full-replace, statut `PLACED`, corps reconstruit pour ne pas effacer opponent/
  competition). **Envelope-ligue** : garde **HARD** (bouton désactivé hors fenêtre) quand l'équipe mappe une
  fenêtre du catalogue ; **dégradation en repère indicatif** (non bloquant) quand le mapping catégorie/niveau
  ne résout pas de façon fiable (`lib/envelope.ts`). Le radar serveur reste la vérité dure.
- **Saisie manuelle** (`FixtureFormDialog`) : `POST /api/fixtures` (équipe, date, HOME/AWAY, adversaire,
  compétition optionnelle = amical) — complément de l'import FBI (amicaux, manquants).
- **Radar affiché** (`ConflictRadar`) : `GET /api/fixtures/conflicts` en direct (invalidé à chaque mutation).
- Tests : Vitest `lib/{weekendGrid,envelope}.test.ts`, `PlacementPanel`/`FixtureFormDialog`/`MatchesPage`
  (.test.tsx) ; e2e Playwright `tests/e2e/matches.spec.ts` (login → créer → placer / garde hors-fenêtre).
  ⚠ L'API omet les props null → `getFixtures` re-normalise `venueId`/`kickoffTime`/`competitionId` en `null`.

## Import FBI réel — une passe (P1-4 PR A, 2026-08-02, remplace le PR-4 du 2026-07-07)

> **Format MESURÉ** sur un vrai export (« Saisie des résultats pour tout le club », gelé en fixture de test
> `backend/tests/Fixtures/fbi/rechercherRencontre.xlsx`, 124 rencontres BCCL 2026-27 — faits F1-F9 du
> [cadrage](../../docs/archive/p1-4-cadrage-module-matchs.md) §3) : fichier **GLOBAL club**, colonnes
> `Division · N° de match · Equipe 1 · Equipe 2 · Date de rencontre · Heure · Salle · e-Marque V2 ·
> Scores/Forfaits (ignorés)`. L'import « un fichier par équipe » de PR-4 est supprimé.

- **Flux une passe** (décision fondateur 2026-08-02) : `analyze()` (dry-run, ZÉRO écriture) rend la table
  des groupes de divisions résolus contre la correspondance persistée → le gestionnaire complète les
  nouvelles dans le dialog → `import()` reçoit le MÊME fichier + les `mappings` et fait tout : persiste les
  correspondances (`Competition`) puis crée/met à jour chaque rencontre.
- **Correspondance Division↔équipe = `Competition`** (name = division, `teamId`), créée par l'appariement,
  **jamais** par find-or-create aveugle. Type inféré (`BRASSAGE` si le nom contient « Brassage », sinon
  `CHAMPIONSHIP`). **Deux équipes du club dans la même division** : le libellé FBI côté club
  (« BCCL - 2 ») désambiguïse — stocké dans `Competition.fbiTeamLabel`, une entrée d'appariement par
  libellé ; nominal (une équipe par division) → label null, robuste au drift de libellé.
- **Diff/update par `(team, externalRef)`** — re-upload ≠ skip :
  - date changée ou switch HOME↔AWAY → mise à jour + **dé-placement** (`UNPLACED`, `venueId` effacé) +
    warning `RESCHEDULED`/`SWITCHED` (« la ligue a re-décidé ») ;
  - heure réelle changée → mise à jour **en place** (la salle reste le choix du club) + warning si placé ;
  - **`00:00` = sentinelle « heure non fixée »** (F2) → `kickoffTime` null à la création, et n'écrase
    JAMAIS une heure posée par le club ;
  - salle/adversaire dérivés → mise à jour silencieuse ; rien → `unchanged`.
- **`Exempt`** (journée de repos) → sauté, compté `exempted`, jamais une erreur (F5). **Salle stockée**
  domicile ET extérieur dans `Fixture.fbiVenueLabel` (F3 — matière trajet, jamais une référence `Venue`).
- **Multi-fichiers incrémental** : la ligue d'abord, le comité quand il répond — chaque fichier complète
  (créations) et corrige (diff) ; divisions inconnues → `unmappedDivisions`, ni créées ni en erreur.
- Gardes de ligne conservées de PR-4 : HOME/AWAY par needle mot-entier du nom du club (derby → erreur de
  ligne), dates/heures `jj/mm/aaaa`/`HH:MM` + serials Excel, n° > 64 car. → erreur de ligne, reader épinglé
  XLSX. En-têtes tolérants (« N° de match » avec espace traînant, « Numéro » legacy accepté).
- **Endpoints** (opérations API Platform sur `FixtureResource`, gate partagée `FixtureImportGate` —
  refus byte-identiques) : `POST /api/fixtures/import/analyze` (multipart `file`) et
  `POST /api/fixtures/import` (multipart `file` + `mappings` JSON + `decisions` JSON optionnel — la
  réconciliation, RMM-4 ci-dessous). Séquence : pas de club/adhésion → 404,
  membre non-management → 403, saison archivée → 409, **socle non validé → 409 (`SocleGuard`)**,
  fichier/mappings invalides → 400. Un `teamId` étranger dans `mappings` est invisible (filtres tenant) →
  400, aucune écriture cross-club.
- **Rapport** `{created, updated, unchanged, exempted, errors[], warnings[{type, division, externalRef,
  message}], unmappedDivisions[{name, fbiTeamLabel, rowCount}], completeness[…], unresolvedDeviations[…],
  depositedAt}` — les deux derniers champs sont la réconciliation (RMM-4 ci-dessous).
- **UI** : « Importer FBI » dans `/matchs` → `ImportFbiDialog` **une passe** : fichier choisi → analyse
  auto → table des correspondances (connues en texte, nouvelles en `TeamSelect`). Si l'analyse ne rend
  AUCUN écart domicile, « Importer » envoie fichier + nouveaux mappings → rapport affiché en place, flux
  inchangé depuis PR A. Si elle en rend (RMM-4), le bouton devient « Examiner l'écart »/« Examiner les N
  écarts » et bascule vers la vue dédiée `/matchs/reconciliation` au lieu d'importer directement — détail
  ci-dessous. Invalidation `fixtures` + `wizard/teams` (engagement) + `competitions` (+ `fbi-ingestions`
  côté réconciliation).

## Couche capacité (P1-4 PR B, 2026-08-03)

- **Fenêtres d'accès match** (`VenueMatchWindow`, tenant+saison, RLS) : jour ISO 1-7 + plage `start<end`
  même jour (famille P4-61) — les créneaux accordés LES JOURS DE MATCH, distincts des créneaux
  d'entraînement. ⚠ Les libellés **ne nomment pas le propriétaire des lieux** (« la mairie ») : c'est le cas
  du BCCL, pas de tous les clubs — conseil départemental, lycée, salle privée (2026-08-04).
  **Gymnase de match = dérivé** (≥ 1 fenêtre), aucun booléen sur `Venue`. **Recopiées à la
  bascule de saison** (`SeasonTransitionService` — la convention se renouvelle). Saisie à DEUX
  endroits (décision fondateur) : section « Accès match » de l'étape Gymnases du wizard, et dialog
  « Accès match » de `/matchs` — même composant `MatchWindowsEditor`, une seule vérité.
- **Règle wizard assouplie** : « gymnase sans créneau » devient « sans créneau d'entraînement NI fenêtre
  match » — un gymnase loué pour les matchs seulement ne bloque plus la validation (gate
  `useStepValidation` + bandeau `VenuesStep`, les deux sites ensemble ; en échec de lecture des fenêtres,
  AUCUNE exemption — plus strict, jamais moins).
- **Indisponibilité gymnase** (`VenueUnavailability`, tenant+saison, RLS) : plage de dates INCLUSES + motif,
  **toutes circonstances** — posée au cockpit (carte « Indisponibilités gymnase », écriture
  management-gated SEC-07), **jamais recopiée** en N+1 (fait daté). **Alerte seulement**, ne bloque ni ne
  régénère rien :
  - carte cockpit : « N créneau(x) d'entraînement/sem. (M séances) · K match(s) placé(s) à repositionner »,
    servie par `GET /api/venue-unavailability-impact` (`VenueUnavailabilityImpact`, pur) ;
  - radar matchs : finding **`VENUE_UNAVAILABLE`** (match posé sur un gymnase fermé à sa date — le cas que
    la garde de placement ne peut pas attraper : l'indispo posée APRÈS le placement).
- **Garde de placement** (`PlacementPanel` + `lib/matchAccess.ts`, HARD sans dégradation — donnée du club,
  pas de mapping incertain) : sélecteur restreint aux gymnases de match (repli : club sans AUCUNE fenêtre →
  liste complète, donnée non adoptée) ; bloqué si jour sans fenêtre, heure hors plage, ou gymnase indispo à
  la date. Pas de verrou serveur sur le geste MANUEL (décision fondateur) — le solveur (PR D), lui, ne
  sort jamais du HARD ; le verrou serveur du geste manuel reste une dette PR E (roadmap, dette (i)).
- **Source unique ADR-0002** : la règle « quel planning s'applique à telle date » est extraite en
  `EffectiveScheduleResolver` (pur) + `TrainingCalendarContext` (chargement scopé), consommés par le
  radar ET l'impact — deux copies auraient divergé.

## Gymnase depuis le libellé — alias de salle FBI/FFBB (P4-187, backend P4-187a + écran P4-187b, LIVRÉ EN ENTIER, 2026-09-09)

> Mesuré 2026-09-08 sur le canal API (`POST /api/ffbb/rencontres/apply`, roadmap P4-187) : un
> domicile importé porte un libellé de salle fédéral (`Fixture.fbiVenueLabel`) mais aucun
> `venueId` — invisible de la collision de gymnase (`VENUE_OVERLAP`) et de la fermeture
> (`VENUE_UNAVAILABLE`). P4-187a a livré le backend seul (résolution automatique + routes) ;
> **P4-187b** livre le geste « Rattacher » dans l'écran Importer (ci-dessous, § Écran).

- **`Venue.externalLabels`** (`backend/src/Entity/Venue.php:74`, JSON `default '[]'`, liste
  NORMALISÉE et dédupliquée — `setExternalLabels`/`addExternalLabel`/`removeExternalLabel`
  idempotents, `Venue.php:283-322`) : les libellés FBI/FFBB **confirmés par le gestionnaire** qui
  désignent ce gymnase. Migration `Version20260909120000`. Recopiés au rollover de saison comme
  une convention permanente du gymnase, au même titre que son nom
  (`SeasonTransitionService.php:190`).
- **Normalisation — foyer unique `VenueLabelNormalizer`**
  (`backend/src/Service/Basketball/VenueLabelNormalizer.php`) : translit ASCII, minuscule, tout
  non-alphanumérique → espace, espaces multiples réduits. « GYMNASE MATEO » et « Gymnase Matéo »
  normalisent à la même clé `gymnase mateo` ; « MATEO » seul reste une clé DIFFÉRENTE (jamais
  confondue avec un mot qu'il contient). `FbiFixtureImporter::normalizeLabel`/`containsClub`/
  `venueMatches` DÉLÈGUENT ici (plus de copie locale) — import xlsx et résolveur d'alias ne
  peuvent plus diverger sur ce qu'est « le même libellé ».
- **`VenueAliasResolver`** (`backend/src/Service/Basketball/VenueAliasResolver.php`), deux
  niveaux, jamais un placement :
  - `resolveConfirmed` — égalité STRICTE (normalisée) avec un alias CONFIRMÉ du gymnase. C'est ce
    qui pose `venueId` automatiquement à l'intégration.
  - `suggest` — proposition FLOUE (nom du gymnase OU alias, fuzzy) rendue en lecture
    (`FixtureResource.suggestedVenueId`). **Ambiguïté ≥ 2 gymnases candidats → `null`** : jamais un
    pari entre deux salles.
  - Gymnases INACTIFS ignorés des deux côtés ; liste des gymnases actifs du club mémoïsée PAR
    REQUÊTE (`ResetInterface::reset` vide la mémo entre deux requêtes d'un runtime long — worker,
    tests — jamais les gymnases d'un autre club en cache).
- **Résolution automatique à l'intégration, les DEUX canaux** — jamais un placement, le
  `reviewState` reste intact :
  - xlsx : `FbiFixtureImporter::attachConfirmedVenue` (`FbiFixtureImporter.php:734`), appelé à la
    création d'un domicile et sur une rencontre existante toujours sans gymnase.
  - Canal API : `FfbbRencontreReconciler::apply` (`FfbbRencontreReconciler.php:139` création,
    `:263` mise à jour) appelle le MÊME `attachConfirmedVenue` — exception ÉTROITE, documentée
    dans le code, au principe « l'API n'auto-applique jamais » : seul le `venueId` est posé, jamais
    un statut, jamais une date, jamais sur un AWAY ni sur une rencontre qui a déjà un gymnase.
- **Routes** (management SEC-07 + saison écrivable → 409 archivée + tenant → 404, contributeur
  OpenAPI `VenueAliasPaths`, `backend/src/Controller/VenueExternalLabelController.php`) :
  - `POST /api/venues/{id}/external-labels` `{label}` → `{venueId, label, attached}`. Ajoute
    l'alias (normalisé, idempotent) PUIS backfille les domiciles du club encore SANS salle dont le
    libellé égale l'alias (`attached` = nombre nouvellement rattaché, 0 sur un re-POST). Libellé
    déjà porté par un AUTRE gymnase du club → 422 nommé (« retirez-le d'abord ») ; libellé vide
    après normalisation → 422.
  - `DELETE /api/venues/{id}/external-labels/{label}` → 204, idempotent, ne touche AUCUNE
    rencontre déjà rattachée (le lien reste posé, seul l'alias qui l'a produit part).
- **`VenueResource.externalLabels`** (lecture seule, jamais écrit par PUT) et
  `FixtureResource.suggestedVenueId` (lecture seule, seulement pour un HOME sans `venueId` avec un
  `fbiVenueLabel`) exposent les deux niveaux à l'écran.
- **Effet immédiat sur le détecteur** : `MatchConflictDetector` compare sur `venueId` seul, jamais
  le statut de placement (`venueOverlapConflicts` — `MatchConflictDetector.php:267`,
  `venueUnavailableConflicts` — `:507`) — un domicile rattaché par alias reste UNPLACED mais
  devient visible de `VENUE_OVERLAP` et `VENUE_UNAVAILABLE` dès que son `venueId` est posé.
- **Tests** : `VenueLabelNormalizerTest` (normalisation), `MatchConflictDetectorTest` (+2 — un
  UNPLACED à `venueId` voit la collision et la fermeture), `FbiFixtureImporterTest`,
  `FfbbRencontresApiTest`, `VenueExternalLabelApiTest` (les deux routes), NR
  `MatchTenantIsolationTest` (rattacher/backfiller n'échappe jamais le club — gymnase étranger →
  404, un rattachement légitime ne backfille aucune rencontre d'un autre club) + `ManagementRoleTest`
  (SEC-07 des deux routes) — les deux fichiers sont déjà des steps de `blocking-tests`, aucun
  changement `ci.yml`. Behat `un-domicile-importe-retrouve-son-gymnase.feature`
  (`VenueAliasContext`) : dépôt sans gymnase → rattachement → visible sans placement → re-dépôt au
  même libellé rattaché d'office → fermeture détectée.

### Écran — le geste « Rattacher » dans Importer (P4-187b, front, 2026-09-09)

- **File de traitement** (`ReviewQueue.tsx`, `ReviewQueueRow.tsx`) : un domicile `isUnattachedHome`
  (`lib/reviewQueue.ts` — `HOME` sans `venueId` avec un `fbiVenueLabel`, ouvertes ET traitées)
  affiche un sous-bloc neutre (jamais un warning — c'est une réparation, pas une alerte) : replié,
  le libellé brut + « `<libellé>` · non rattaché » + bouton **Rattacher** ; déplié, un
  `VenueSelect` (pré-sélectionné sur `Fixture.suggestedVenueId` s'il figure dans la liste des
  gymnases actifs du club, sinon placeholder « Choisir un gymnase ») + **Confirmer**/**Annuler**.
  Confirmer envoie le libellé BRUT (`fbiVenueLabel`, jamais normalisé côté client — le serveur
  normalise) à `POST /api/venues/{id}/external-labels` (`api.ts` `attachVenueLabel`,
  `AttachVenueLabelInput`) ; un 422 serveur s'affiche tel quel et le bloc reste ouvert.
- **`useAttachVenueLabel`** (`queries.ts`) invalide `["fixtures"]` (le `suggestedVenueId` et le
  `venueId` backfillé se recalculent, la file et le radar se mettent à jour) **et** `["venues"]`
  (le gymnase gagne son `externalLabels`). Toast composé par l'appelant (`ReviewQueue.tsx`
  `onAttach`) sur `AttachVenueLabelResult.attached` : « N domicile(s) rattaché(s) à `<gymnase>`
  (toutes équipes) » si `attached > 0`, sinon « Libellé confirmé — aucun nouveau domicile à
  rattacher » (l'alias existait déjà, ou plus aucun domicile à backfiller).
- **En-tête d'équipe** (`ReviewQueue.tsx`) gagne un troisième segment « N sans gymnase »
  (`TeamQueue.unattachedCount`, `lib/reviewQueue.ts`) quand au moins un domicile de l'équipe est
  sans gymnase — badge de l'onglet Importer (`pendingReviewCount`) inchangé, ce compteur ne compte
  QUE `NEW`/`OUT_OF_SYNC`.
- **Consulter** (`MatchRowsTable.tsx`) recale son libellé pour renvoyer vers le geste : un domicile
  sans gymnase s'affiche « `<libellé>` · à rattacher dans Importer » (était « non rattaché »).
- **Pas d'écran de retrait d'alias** : le `DELETE /api/venues/{id}/external-labels/{label}` (livré
  en P4-187a) n'a aucun consommateur dans `frontend/src` — un alias mal rattaché ne se corrige
  qu'en API. Roadmap **P4-196**.
- **Pas de couverture e2e** : `POST /api/fixtures` (endpoint de création directe) n'accepte pas
  `fbiVenueLabel` (`backend/src/Dto/FixtureInput.php`) — le scénario ne se rejoue pas par cette
  voie. Couverture vitest : `ReviewQueueRow.test.tsx`, `ImportPage.test.tsx`,
  `lib/reviewQueue.test.ts`, `queries.test.tsx`, `api.test.ts`.

## Habitudes + passerelles (P1-4 PR C, 2026-08-03)

- **Habitude d'équipe** (`TeamMatchHabit`, tenant+saison, RLS) : jour ISO + **heure-point** (pas une plage)
  + gymnase optionnel — « SF3 = dimanche 17h30 à Coubertin ». **Une par jour et par équipe** (unique DB,
  422 lisible avant), N par équipe. **Recopiée à la bascule** (remap équipe+gymnase ; gymnase pendu →
  l'habitude survit en jour+heure). Solde le `Team.preferredMatchWindow` de P3-1.
- **Passerelle** (`TeamLink`, nom NEUTRE — cross-module par conception) : couple d'équipes **symétrique**
  (normalisation `teamAId < teamBId` → SM1–SM2 ≡ SM2–SM1, unique DB). **DEUX impacts distincts depuis le
  lot PASSERELLES** : (1) **côté MATCHS**, `TeamLinkType` — **`NOT_SIMULTANEOUS`** (« joueurs partagés » —
  aucune entité joueur n'existe, le gestionnaire DÉCLARE le pont) et **`BACK_TO_BACK`** (« l'un après
  l'autre », implique la non-simultanéité) — rail SOFT du radar/placement matchs, inchangé ; (2) **côté
  ENTRAÎNEMENT**, `TeamLinkIntensity` **`PREFERRED`** (défaut, migration `DEFAULT 'PREFERRED'`) /
  **`MANDATORY`** — honoré par le solveur d'entraînement (arbitrage fondateur n°1 : cette intensité ne
  gouverne QUE l'entraînement, jamais les matchs ; détail moteur `engine/docs/constraint-vocabulary.md`
  §Passerelles). Équipe liée à elle-même → 422 ; équipe étrangère invisible → 422 sans écriture ; **51ᵉ
  passerelle → 422 nommé** (cap `MAX_TEAM_LINKS = 50` miroité du moteur). Cascade : suppression d'équipe
  purge habitudes + liens (les DEUX colonnes). Recopiée en N+1 re-normalisée. Solde le « volet joueur » de
  P3-1 **par décision** : pas de joueurs individuels, le lien déclaré porte le besoin.
- **Effets immédiats, sans solveur** :
  - **Estimation d'heure extérieure** (résorbe l'angle mort PR-2) : un AWAY sans heure emprunte l'heure
    habituelle de son équipe **du même jour de semaine** → l'empreinte naît (`MatchFootprint::occupancyAt`),
    les conflits deviennent visibles, marqués **`estimatedKickoff: true`** (« heure estimée » dans le
    radar). **Rien n'est persisté** (écrire l'estimation dans `kickoffTime` la ferait passer pour une heure
    réelle et polluerait le diff de ré-import F2). Pas d'habitude ce jour-là → pas d'empreinte, mais
    l'angle mort est NOMMÉ au diagnostic (`AWAY_NO_FOOTPRINT`, PR E2).
    Une heure RÉELLE n'est jamais supplantée. Un HOME non placé n'est pas estimé (son heure est le prochain
    geste du gestionnaire).
  - **Finding `TEAM_LINK_OVERLAP`** : deux matchs d'équipes liées `NOT_SIMULTANEOUS` dont les empreintes
    (réelles ou estimées) se chevauchent (demi-ouvert — enchaînés dos-à-dos = pas de conflit). Indépendant
    des coachs. `BACK_TO_BACK` ne produit AUCUN finding (préférence du solveur de placement, pas une règle).
  - **Pré-remplissage au placement** : `PlacementPanel` initialise gymnase+heure depuis l'habitude du jour
    du match (champs vides seulement) + ligne « Habitude : samedi 15:30 · Mateo ». **Les gardes restent
    souveraines** (enveloppe, accès, indispo — l'habitude pré-remplit, ne débloque jamais).
  - **Blocs fantômes** (`WeekendGrid`) : une habitude À GYMNASE dont l'équipe n'a AUCUN match ce jour-là
    projette un bloc translucide pointillé « Habitude SF3 · fenêtre protégée » (empreinte 2h15, lanes
    partagées avec les vrais matchs — un placement manuel atterrit À CÔTÉ, pas dessus). **La réalité
    dissout le fantôme** : tout match de l'équipe à cette date — extérieur compris, la fenêtre se LIBÈRE.
    Habitude sans gymnase → pas de fantôme (grille en colonnes gymnase).
  - **Inférence** (`lib/habitInference.ts`, pure, front) : suggère l'habitude majoritaire quand **≥ 3
    matchs horodatés ET ≥ 50 %** concordent (seuils fondateur) ; gymnase joint si ≥ 50 % des HOME placés du
    groupe le partagent (le libellé texte `fbiVenueLabel` n'est JAMAIS résolu — décision PR A). Suggestion
    = bouton « Accepter », jamais une écriture ; un jour déjà déclaré n'est pas re-suggéré.
- **Saisie** : `HabitsLinksDialog`, **l'écran unique** des habitudes et passerelles. Ouvert depuis `/matchs`
  (bouton « Habitudes & passerelles ») ET, depuis le lot PASSERELLES, **depuis le wizard** (étape
  Mutualisation, bouton « Gérer les passerelles » → `HabitsLinksButton`, qui charge ses propres données
  matchs ; le dialog reste dans `features/matches`). Chaque passerelle y porte le choix **« Préféré » /
  « Obligatoire »** (intensité d'entraînement — `<Select>` labellisé par équipe, copie qui sépare l'effet
  matchs de l'effet entraînement, création + édition `useUpdateTeamLink`). L'inférence d'habitude exige des
  matchs importés (rien au wizard). Écritures **management-gated** (`AbstractStateProcessor::requiresManagementRole()`
  par défaut `true`, aucun override chez `VenueMatchWindowStateProcessor`/`TeamMatchHabitStateProcessor`/
  `TeamLinkStateProcessor` — 403 au non-management), derrière le verrou socle de la page `/matchs`. Habitudes
  et passerelles sont consommées par le solveur de placement
  matchs depuis la PR D (§ suivant) ; l'intensité d'entraînement, elle, par le solveur d'ENTRAÎNEMENT
  (lot PASSERELLES — `engine/docs/constraint-vocabulary.md` §Passerelles).

## Rotation A/B — RMM-5, le MODÈLE, le SOFT, le REPOS DÉRIVÉ puis le SET-UP (2026-08-25, P2-49) — LIVRÉ EN ENTIER

**RMM-5 est LIVRÉ EN ENTIER (les 4 PR) — P2-49 clôt** ([`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md)
§8-§9, cas SM1/SM2 sur le 20h30 : pénurie de créneaux → alternance semaine A/semaine B sur le
MÊME créneau physique). PR-1 livre le modèle et son CRUD seuls — rien ne le consomme encore. PR-2
branche le bloc au payload `/place-matches` et l'attraction SOFT côté solveur — aucun MOVE. PR-3
fait suivre le repos d'entraînement du solve hebdo à la même image. PR-4 (ci-dessous) livre l'écran
SET-UP qui déclare les créneaux partagés et le signal « hors image » associé — pur frontend, zéro
fichier backend/engine, contrat inchangé.

- **`MatchSlotRotation`** (`match_slot_rotation`, tenant+saison, RLS FORCE, patron
  `TeamMatchHabit`/`VenueMatchWindow` — hors des plans de période, pas de `schedulePlanId`) : le
  créneau physique = gymnase **NOT NULL** (à la différence d'une habitude, une rotation sans
  gymnase n'est pas un créneau) + jour ISO + heure de coup d'envoi. Unicité `(club_id, season_id,
  venue_id, day_of_week, kickoff_time)` — un créneau physique ne porte qu'UNE rotation.
- **`MatchSlotRotationTeam`** — les membres ORDONNÉS (patron `SharedTrainingGroupTeam`,
  club/saison dénormalisés, unicité `(rotation_id, team_id)`). `position` est **purement
  FICTIF** (décision fondateur n°4 du §8) : il ne pilote AUCUN calendrier, il sert seulement à
  un affichage A/B/C stable.
- **CRUD `/api/match_slot_rotations`** (5-fichiers API Platform) : lecture ouverte au Membre,
  écriture au régime **management par défaut du rail** (`AbstractStateProcessor::requiresManagementRole()`
  = `true`, aucun override — même patron que `VenueMatchWindow`/`TeamMatchHabit`/`TeamLink`
  ci-dessus). Écriture des membres par **REMPLACEMENT transactionnel** (delete+recreate dans une
  seule transaction — la course entre deux écritures concurrentes du même créneau physique rend
  **409** nommé, `UniqueConstraintViolationException` capturée, plutôt qu'un 500, correctif de
  revue sécurité 2026-08-25). 422 : < 2 équipes, doublon d'équipe, formats (DTO,
  `MatchSlotRotationInput`) ; gymnase/équipe étrangers au club+saison, créneau physique déjà pris
  (processor, contre la base — la contrainte d'unicité DB reste le filet).
- **Cascades** (`CascadePlan`) : une équipe supprimée quitte ses rotations, celles tombées
  sous 2 membres sont supprimées et ANNONCÉES (`MatchSlotRotationTeamPruneStep`) ; un gymnase
  supprimé emporte la rotation ENTIÈRE — parent et membres (`MatchSlotRotationVenuePruneStep`) —
  la rotation EST le créneau, contrairement à une habitude qui survit sans gymnase.
- **Bascule de saison** (`SeasonTransitionService`) : recopiée en N+1 comme les habitudes/
  fenêtres, gymnase ET membres remappés — un gymnase non remappé laisse la rotation sans
  ancrage (non recopiée) ; une rotation tombée sous 2 membres après remap perd son sens (non
  recopiée) ; les positions survivantes se recompactent (déterministe).
- **RGPD / reset saison** (`SeasonDataPurger`) : les deux tables purgées avec la saison (membres
  avant le parent, patron `SharedTrainingBlock`).

### Le SOFT de placement — RMM-5 PR-2 (2026-08-25)

Bump de contrat **2.14 → 2.15** (les 4 constantes `CONTRACT_VERSION` — `ScheduleConstraintBuilder`,
`MoveSlotService`, `MatchPlacementPayloadBuilder`, `engine/CONTRACT_VERSION` — un seul contrat pour
les 3 endpoints). Aucun MOVE ici : l'image A/B n'est qu'un **bonus SOFT** sur `/place-matches`, à
parité stricte du mécanisme d'habitude.

- **Le bloc `slotRotations`** (`MatchPlacementPayloadBuilder::slotRotations()`) sérialise chaque
  rotation ≥ 2 membres en `{venueId, dayOfWeek, kickoff, teamIds}` — l'ordre `position` (fictif) ne
  VOYAGE PAS, mais l'ordre de sérialisation EST déterministe (rotations triées venue→jour→heure,
  `teamIds` triés) : ni un UUID de rotation ni un UUID de membre ne fait varier le payload (patron
  du tri des tags). Une rotation tombée sous 2 membres n'est pas émise (miroir de
  `sharedTrainings`). Absent/vide ⇒ payload byte-identique.
- **La suppléance** (backend, à l'assemblage `habitsByTeam`) : l'habitude d'une équipe le **même
  jour ISO** qu'une de ses rotations est **retirée** du bloc `habits` émis — une équipe reçoit
  rotation OU habitude ce jour-là, jamais les deux bonus cumulés ; les autres jours de l'équipe
  restent intacts.
- **Côté solveur** (`match_placement.py`, SOFT uniquement, aucun HARD nouveau) :
  - **Attraction** — le domicile d'un membre, son jour de rotation, gagne `W_ROTATION_TIME=15` si
    le candidat tombe sur l'heure de la rotation et `W_ROTATION_VENUE=5` s'il tombe sur son
    gymnase — les mêmes poids que `W_HABIT_TIME`/`W_HABIT_VENUE`, à parité stricte ; comme la
    suppléance backend garantit qu'un membre ne porte jamais habitude ET rotation le même jour, ces
    deux bonus ne s'additionnent jamais sur le même candidat.
  - **Protection de fenêtre** — sur une date où **aucun membre** de la rotation n'a de match, le
    créneau (gymnase + fenêtre 2h15 autour du coup d'envoi) est défendu contre les AUTRES équipes
    au même malus que la protection d'habitude, **`W_PROTECT_HABIT=25`** (mécanisme `protected`
    partagé, pas une nouvelle constante).
- **Scénario sémantique** (`test_ab_rotation_image_is_honoured_across_two_weekends`) : deux week-ends
  fédéraux successifs, SM1 reçoit le week-end A et SM2 le week-end B sur le MÊME créneau — les deux
  domiciles atterrissent sur le créneau partagé, sans violation HARD.
- **Feature Behat** (`backend/features/placement-des-matchs.feature`) : une rotation Samedi 15h30
  déclarée sur le gymnase jetable du scénario (équipe de home + une seconde équipe), **sans
  habitude seedée** — le placement du samedi doit atterrir SUR le créneau (gymnase + 15:30),
  preuve bout-en-bout que l'attraction SOFT tire vraiment, pas seulement « une heure légale
  quelconque ».
- **Garde bloquante** : `CrossStack/SlotRotationPayloadParityTest` (STOCKÉ == ÉMIS + la
  suppléance même-jour, falsifiés dans les deux sens — liste canonique `docs/testing/blocking-tests.md`).

### Le repos d'entraînement dérivé — RMM-5 PR-3 (2026-08-25)

3ᵉ décision fondateur honorée ([`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md)
§8) : **le jour de repos d'entraînement suit l'image A/B**, jamais une déclaration à part. Touche le
solve HEBDO (`POST /generate`, `ScheduleConstraintBuilder`) — **le solveur de placement des matchs
lui-même n'est pas concerné**, il consomme déjà `slotRotations`/`habits` (PR-2 ci-dessus) ; ce volet
change ce que reçoit le solveur d'ENTRAÎNEMENT pour son bonus « jour de repos après un match »
(`add_match_day_rest_bonus`, [`../../backend/docs/constraint-coverage.md`](../../backend/docs/constraint-coverage.md)).

- **Formule** (`ScheduleConstraintBuilder::deriveMatchDay`) : `matchDay = max(jours ISO des
  habitudes de l'équipe ∪ jours ISO des rotations dont elle est membre)` — le DERNIER jour de match
  de la semaine, car le repos qui compte est celui d'après lui (un `max`, pas un `min` : une équipe
  samedi+dimanche a son repos après le dimanche, pas après le samedi).
- **Repli sans image** (ni habitude ni rotation) : le champ déclaré `Team.matchDay`, stocké
  **0-based** (0 = lundi, `TeamInput.php` `Range(0,6)`, jamais exposé à l'écran), **converti en ISO
  (+1)** à l'émission pour alimenter la MÊME formule moteur que la valeur dérivée
  (`rest_day = match_day % 7 + 1`, juste en ISO uniquement — `engine/app/solver/objective/terms.py`,
  `add_match_day_rest_bonus`).
  **Corrige le bug dormant D-11** ([`../evolution/duplications-de-verite.md`](../evolution/duplications-de-verite.md)) :
  sans cette conversion, un `matchDay` déclaré samedi (5, 0-based) produisait un repos calculé
  samedi au lieu de dimanche — dormant faute d'écran écrivant le champ, jamais observé en
  production. Sans image ET sans champ déclaré → `null`, inchangé.
- **Le champ déclaré n'est pas supprimé** : repli legacy, zéro migration, conversion à l'émission
  SEULE (la colonne reste 0-based en base).
- **Péremption** (`ResourceChangeStaleScheduleListener`) : `TeamMatchHabit`, `MatchSlotRotation` et
  `MatchSlotRotationTeam` marquent désormais les plannings COMPLETED du club+saison comme périmés
  à la création/modification/suppression (patron STRUCTURE, `teamTouched`) — leur contenu entre
  dans le `matchDay` émis, donc dans le payload `/generate` hashé ; un import démarque.
- **Garde bloquante** : volet dérivation de `CrossStack/SlotRotationPayloadParityTest` (habitude
  seule → max ISO ; rotation seule et union habitude+rotation → max ISO ; repli champ déclaré
  converti 0-based→ISO ; ni image ni champ déclaré → `null` inchangé) + volet
  `Integration/Service/ResourceChangeStaleScheduleTest` (habitude/rotation périment le club+saison,
  frontière saison tenue, import démarque).

### Le SET-UP A/B et le signal — RMM-5 PR-4 (2026-08-25)

Dernière PR de RMM-5, **pur frontend** (zéro fichier backend/engine dans le diff) : elle donne un
écran au modèle livré PR-1 et un signal cohérent à la boucle. **Deux décisions fondateur du §8
honorées** ici (les décisions 1 « SOFT jamais bloquant » et 4 « FICTIF, aucun ancrage calendaire »
— voir aussi PR-1/PR-3 ci-dessus pour les décisions 2 et 3).

- **L'éditeur « Créneaux partagés (alternance) »** (`MatchSlotRotationsEditor.tsx`), sur
  `/matchs/configuration`, frère de `MatchWindowsEditor`/`TeamLinksSection` : déclarer un créneau
  (gymnase + jour + heure) et ses N équipes membres, **dans l'ordre** — badge A/B/C… par position,
  réordonnancement par **flèches ↑/↓** (jamais de drag : sans clavier ni cible tactile fiable pour
  un gestionnaire de 50 ans, patron déjà posé ailleurs dans le module). **Depuis P4-185**, chaque
  rotation est une **ligne-bouton compacte** (« Samedi 20:30 · Armand · U9F2 ⇄ U9M1 »), une seule
  dépliée à la fois (état local `expandedId`, indépendant de l'accordéon de section qui l'englobe) ;
  le bouton Supprimer est le FRÈRE du bouton de dépliage (jamais imbriqué, patron `ReviewQueue`) ;
  le formulaire de création vit derrière « Ajouter un créneau partagé » et se referme à la création
  réussie. La phrase « l'ordre dessine
  seulement l'alternance A/B/C ; il ne commande aucun calendrier » est **dite à l'écran**, jamais
  tu — honore la décision fondateur n°4 (`position` purement fictif, § PR-1 ci-dessus). Retrait
  d'un membre désactivé sous 2 équipes (un créneau partagé en compte au moins deux). Route CRUD
  déjà livrée PR-1 (`/api/match_slot_rotations`) — **PR-4 écrit son premier client** (`api.ts` +
  `queries.ts` : `useMatchSlotRotations`, `useCreateMatchSlotRotation`,
  `useUpdateMatchSlotRotation`, `useDeleteMatchSlotRotation`, restés sans consommateur depuis
  PR-1) ; écriture des membres en **PUT plein remplacement** (le slot + le roster ordonné entier
  re-envoyés, le backend réécrit — patron déjà documenté § PR-1). Erreur 422 affichée **sur le
  formulaire** (`role="alert"`) en plus du toast global.
- **`TypicalWeekendGrid` gagne les semaines A/B** (`buildTypicalWeekend(habits, rotations,
  weekIndex)`, modèle resté PUR) : `weekCountOf(rotations)` = la plus grande rotation ≥ 2 membres,
  **1 sans rotation déclarée** — dans ce cas **aucun segmenté ne s'affiche**, la grille est
  EXACTEMENT celle d'avant (le composant appelle le modèle avec `rotations=[]` par défaut,
  anti-régression vraie par construction, `TypicalWeekendGrid.test.tsx`). Avec rotation(s), un
  segmenté `Tabs` « Semaine A / Semaine B / … » (réutilisé, pas neuf) choisit l'index prévisualisé
  — **jamais une semaine calendaire réelle** : la grille reste date-less, aucun ancrage n'est
  déclaré (décision fondateur n°4). La semaine `k` dessine, pour chaque rotation week-end, le
  membre `position k mod N`, dans la même colonne (jour+gymnase) qu'une habitude — collision posée
  côte à côte comme pour les habitudes (une collision de gabarit doit se VOIR). Un créneau partagé
  déclaré un jour **hors week-end** est listé à part (« Hors week-end : … », la grille reste
  Sam/Dim only) plutôt que silencieusement absent.
- **Le signal « hors image » s'étend aux membres de rotation** (`isOffModel`,
  `lib/loopSteps.ts`) : pour un membre de rotation, la référence du jour du créneau **devient le
  créneau lui-même** (jour/heure/gymnase), jamais son habitude — miroir exact de la suppléance
  backend (PR-2 : l'habitude même-jour d'un membre est retirée du payload de placement). La
  rotation du même jour **PRIME** donc sur l'habitude ; sans habitude NI rotation sur le jour, pas
  de modèle de référence donc pas d'écart.
- **Nouveau signal « même week-end »** (`sameWeekendRotationCount`) : compte les créneaux partagés
  où **au moins deux membres distincts** reçoivent à domicile le même week-end affiché — l'image
  A/B dit qu'un seul membre reçoit par week-end sur le créneau, deux domiciles la contredisent.
  Rendu en **pilule NEUTRE** (icône `Info`, `bg-muted`) juste sous l'écart au modèle, sur l'étape
  **« placés au modèle »** de `MatchesPage` — jamais une erreur, jamais dans un `done` du rail : honore la
  décision fondateur n°1 (« l'image A/B est un IDÉAL SOFT, jamais un HARD… le radar peut signaler,
  il ne bloque pas »), au même titre que l'écart au modèle existant.
- **Zéro backend/API/contrat touché** : les mutations ajoutent un toast d'erreur en `onError`
  (patron des autres mutations du module) ; aucune route ni schéma neufs, tout consomme le CRUD
  livré PR-1.

### Seed BCCL dev — répartition WE des matchs (2026-09-03)

Le seed du club dev réel (`app:bccl:seed`, profil `dev` SEULEMENT — drapeau
`BcclSeedProfile::seedWeekendMatchLayout`, `false` en démo et en charge) porte désormais l'état
terrain complet du week-end du club, données fondateur (xlsx importé le 2026-09-02),
`BcclSeeder::seedWeekendMatchLayout` :

- **4 `VenueMatchWindow`** : Matéo samedi 13:00→22:30 et dimanche 09:00→18:30, Armand samedi
  10:45→21:00, Debarros samedi 13:00→18:30.
- **32 `TeamMatchHabit`** — une par équipe qui reçoit le week-end, jour + coup d'envoi + gymnase
  exacts du gestionnaire (aucun arrondi).
- **8 `MatchSlotRotation` + 16 `MatchSlotRotationTeam`** sur les créneaux physiquement partagés
  d'Armand (×5) et Debarros (×3) — position 0 = équipe semaine A, 1 = semaine B. **Matéo ne porte
  aucune rotation** (ses heures diffèrent d'une semaine à l'autre, donc aucun créneau physique
  n'est réellement partagé).
- **Zéro `Fixture`** : les équipes ne sont pas encore engagées tant que le calendrier FFBB n'est
  pas importé — le seed ne crée aucun match.

Idempotent (patron des créneaux d'entraînement) : purge+recréation des fenêtres et des rotations
(+ leurs membres), find-or-create des habitudes sur `(club, saison, équipe, jour)`. Gardé par
`BcclSeederIdempotenceTest::testDevSeedCarriesWeekendMatchLayout` (chaque fenêtre/habitude/paire
de rotation épinglée par sa valeur exacte) + l'extension du test démo (les 4 tables restent à zéro
hors profil dev).

**Conséquences** : `TypicalWeekendGrid` affiche les onglets Semaine A/Semaine B dès qu'une rotation
existe (§ « Le SET-UP A/B et le signal » ci-dessus) — les habitudes Matéo apparaissent dans les
deux semaines, sans onglet propre puisqu'aucune rotation n'y siège. Côté génération
d'entraînement, `ScheduleConstraintBuilder::deriveMatchDay` dérive l'image A/B des habitudes et
rotations : les 32 équipes du club dev émettent désormais un `matchDay` au moteur (règle implicite
SOFT « repos après jour de match »).

## Solveur de placement — P1-4 PR D (2026-08-03, [ADR-0003](../../docs/architecture/adr-0003-match-placement-solve.md))

- **Second problème solveur engine** : `POST /place-matches` (`engine/app/solver/match_placement.py`,
  schémas `match_input_schema.py`/`match_output_schema.py`), **un seul** `CONTRACT_VERSION` pour les deux
  endpoints → bump **2.2**. Le solve hebdo est intouché (mêmes fichiers, mêmes golden).
- **Rail SYNCHRONE** : `POST /api/fixtures/place` (`PlaceMatchesController` — management + saison
  écrivable + socle pointé) répond dans la requête, sans Messenger ni Mercure. Anti-double-clic par
  `MatchPlacementLock` (Redis, préfixe dédié — PAS le verrou de génération). Seuil de bascule async ~20 s
  (ADR-0003 §2).
- **Best-effort à poids dominant** : l'objectif maximise `10 000 × Σ placés + SOFT` — **aucune contrainte
  HARD n'est jamais violée en sortie**. Un match sans candidat licite sort **NOMMÉ**
  (`no_access_window` · `no_league_intersection` · `venue_unavailable` · `venue_full`), la raison affichée
  sur sa ligne « À placer ». Ce n'est pas la relaxation qu'interdit ADR-0001 : rien n'est relâché,
  l'impossible est épelé (le signal dérogation-tôt EST le produit).
- **HARD** : fenêtres d'accès match (l'empreinte 2h15 entière dedans — l'échauffement occupe la salle),
  indisponibilités gymnase, no-overlap par (gymnase, date), fenêtre ligue quand l'enveloppe est résolue
  (`LeagueEnvelopeResolver`, portage serveur de la jointure tolérante d'`envelope.ts` — non résolue =
  aucun HARD + diagnostic INFO `league_envelope_unresolved`). **SOFT** (golden-épinglés — en changer un =
  changer le PRODUIT) : conflit coach MAIN −60 · passerelle `NOT_SIMULTANEOUS` violée −40 · habitude
  heure +15 / gymnase +5 · fenêtre habituelle protégée −25 · `BACK_TO_BACK` enchaîné +15 · coach
  ASSISTANT −10 · stabilité re-solve +8 (+ hint) · compactage −1 par pas de 15 min de trou. Candidats au
  pas de 15 min, 30 s de budget, 1 worker + seed (bit-stable).
- **Ancres — `Fixture.placementSource`** (`MANUAL`/`SOLVER`, null legacy = manuel) : tout geste API du
  gestionnaire stampe MANUAL ; MANUAL + `SUBMITTED`/`VALIDATED` = **FIXED**, consomment leur créneau et ne
  bougent JAMAIS (un déposé qui a perdu sa salle est ignoré du payload — ni ancre ni plaçable). SOLVER =
  re-plaçable (bonus stabilité). Écriture directe en `PLACED` (patron du planning) ; l'applier recharge
  chaque fixture et n'écrit que si le solveur y est encore autorisé — un geste manuel pendant le solve
  gagne toujours.
- **Le backend PROJETTE, l'engine reste plat** (`MatchPlacementPayloadBuilder`) : occupations
  d'entraînement **datées** via `TrainingCalendarContext` + `EffectiveScheduleResolver` (ADR-0002 jamais
  ré-implémenté côté engine), heure extérieure estimée par le MÊME `AwayKickoffEstimator` que le radar,
  enveloppe ligue résolue côté serveur.
- **UI** : bouton « Placer automatiquement » sur `/matchs` (spinner pendant le solve, toast « N placés ·
  M non plaçables », raisons par match dans la liste À placer).

## Boucle manuelle — P1-4 PR E1 (2026-08-03)

- **Chaque match de la grille est cliquable** (badge cadenas = ancre manuelle) et ouvre le panneau,
  étendu aux matchs placés : **Déplacer** (salle/heure, gardes du placement initial souveraines),
  **Dé-placer** (retour « À placer », marqueur effacé), **Verrouiller / Rendre au solveur**,
  **Échanger avec…** (mode swap : bandeau + 2e clic sur un autre match placé), **Modifier**
  (`FixtureFormDialog` en édition), **Supprimer** (confirmation). Un match `SUBMITTED`/`VALIDATED`
  est en lecture seule — déposé à la fédération.
- **Verrou = `placementSource`** (aucun nouveau champ) : cadenas = PUT écho (le stamp MANUAL existant
  fait le travail) ; « rendre au solveur » = PUT écho + `placementSource: "SOLVER"`, accepté par le
  serveur **seulement à placement (salle/heure/date) inchangé et statut PLACED** — 422 sinon (on ne
  peut pas étiqueter SOLVER un placement choisi à la main ; `FixtureStateProcessor`). À la création,
  `SOLVER` est refusé (422). `null` legacy = manuel (cadenas affiché).
- **Éditer** : équipe figée (une autre équipe = un autre engagement — supprimer + recréer) ; **changer
  la date à la main CONSERVE le placement** (le gestionnaire EST la décision, à l'inverse du ré-import
  FBI qui dé-place) — le radar signale ce que la nouvelle date casse. **Exception** : basculer
  domicile → extérieur libère le créneau (même règle que le switch du ré-import ; règle pure
  `editFixtureBody`, testée unitairement).
- **Échanger** = swap (salle + heure), **jamais les dates** (elles appartiennent à la ligue). Deux PUT
  séquentiels côté client, pas d'endpoint transactionnel : rien n'étant bloquant, un échec réseau au
  milieu laisse un état visible et rattrapable (invalidation en `onSettled`, toast d'alerte).
- **Rien ne bloque** : une collision de salle créée à la main passe (décision fondateur 2026-08-03) —
  le diagnostic gradué (PR E2) l'affichera en sévérité max. ⚠ Conséquence engine (bug attrapé par le
  smoke) : les ancres FIXED **élaguent les candidats** au lieu d'entrer au NoOverlap — deux ancres en
  collision ne rendent plus le solve entier infaisable (ADR-0003 §5, amendé).
- Suppression d'un match : DELETE direct (la garde socle ne s'applique qu'aux écritures) ;
  l'engagement étant **dérivé**, supprimer le dernier match d'une équipe la rend à nouveau supprimable
  (aucune garde ne survit à tort — NR `EngagedTeamGuardTest`).

## Diagnostic gradué + extérieur visible + week-end type — P1-4 PR E2 (2026-08-03)

- **La sévérité est émise par le SERVEUR** (`MatchConflictDetector`, `severity` 1..7 + `coachRole`
  MAIN/ASSISTANT — MAIN seulement si le coach est principal sur les DEUX équipes impliquées, sinon
  ASSISTANT : « (assistant d'un côté) » à l'écran, ex. assistant SM1 / principal U21M1 = à
  surveiller, pas un conflit bloquant. Règle fondateur 2026-09-07, **inverse** l'ancienne (P4-189 —
  avant, principal sur N'IMPORTE laquelle des deux équipes suffisait à gagner) ; `rolesByTeam`
  (MAIN+ASSISTANT sur la MÊME équipe = MAIN) inchangé) ; l'UI groupe et libelle
  (`lib/diagnostic.ts`, pur), elle ne re-dérive JAMAIS la gravité. Groupes triés pire-d'abord, tons
  1-2 rouges / 3-5 warning / 7 neutre, **groupe 7 replié avec compteur** (40 extérieurs aveugles =
  une ligne, pas 40 cartes).
- **Échelle (cadrage §8)** : 1 `VENUE_OVERLAP` (deux matchs même gymnase qui se chevauchent — la
  boucle manuelle ne bloque jamais, le diagnostic crie) · 2 `LEAGUE_WINDOW_VIOLATION` (domicile placé
  d'une équipe MAPPÉE hors de toute fenêtre ligue — non mappée = silencieuse, même tolérance que le
  solveur) · 3 coach **MAIN** (`MATCH_MATCH`/`MATCH_TRAINING`) · 4 `VENUE_UNAVAILABLE` +
  **`ACCESS_WINDOW_LOST`** (dette (ii) soldée : placé dont la fenêtre d'accès a changé APRÈS — règle du
  PANNEAU mirrorée : heure-point, demi-ouvert, club sans aucune fenêtre = rien à faire respecter, PAS
  la règle empreinte du solveur : un match que le panneau vient d'autoriser ne doit pas alerter) ·
  5 coach ASSISTANT + `TEAM_LINK_OVERLAP` · 7 **`AWAY_NO_FOOTPRINT`** (dette (v) : l'angle mort —
  extérieur sans heure ni habitude du bon jour — est NOMMÉ, plus un silence pris pour de la santé).
  La sévérité 6 (`COMPETITION_INCOMPLETE`, PR F2) juge les compétitions APPARIÉES sous leur attendu.
- **Enveloppe fiable côté UI (dette (iv) soldée)** : `GET /api/league-match-windows` porte
  `resolvedTeamWindows` (teamId → ids de fenêtres), calculé par le MÊME `LeagueEnvelopeResolver` que
  le solveur et le diagnostic — la jointure n'existe qu'à UN endroit ; `lib/envelope.ts` devient un
  simple lookup (la jointure tolérante CLIENTE est supprimée — deux implémentations avaient déjà
  commencé à diverger). Non résolue = indicatif, jamais bloquant (inchangé).
- **Extérieur visible** : bande « À l'extérieur ce week-end » sous la grille (`AwayList`) — équipe,
  date, adversaire + salle FBI (`fbiVenueLabel`), heure réelle sinon habituelle taguée « heure
  estimée » (même règle que le radar), sinon « heure inconnue » ; Modifier/Supprimer (confirmation).
- **Vue « week-end type »** (reformulation fondateur de « semaine type ») : bascule sur `/matchs` —
  le gabarit IDÉAL du gestionnaire, toutes les habitudes Sam/Dim × gymnases, sans dates, empreintes
  2h15, chevauchements posés côte à côte (une collision de gabarit doit se VOIR). Lecture seule
  (`lib/typicalWeekend.ts` pur + `TypicalWeekendGrid`) — l'édition reste dans « Habitudes &
  passerelles » ; habitudes sans gymnase listées à part.

## Appariement FFBB — P1-4 PR F1 (2026-08-03)

- **La FFBB fait autorité sur le PÉRIMÈTRE** (équipes engagées, poules, adversaires), l'export FBI sur
  le calendrier. Deux appels Meilisearch de plus (`FfbbApiClient` — mêmes hosts SSRF, à la demande,
  zéro cache/cron : décision juridique fermée), joints par `FfbbEngagementReader` (saison filtrée par
  `FfbbSeasonCode`, double encodage réparé à l'entrée). Détail des sondes et des pièges (champ non
  filtrable, id non filtrable) : `backend/docs/ffbb-api.md`.
- **Dialog « Engagements FFBB » sur `/matchs`** (`FfbbEngagementsDialog`, fetch à l'ouverture seulement) :
  chaque engagement (compétition · poule · N clubs · catégorie/niveau/sexe) se rattache à une équipe,
  **confirmation en bloc**. Aux phases suivantes tout est pré-rempli (réf déjà connue, sinon égalité
  normalisée stricte du nom canonique) — « on ré-apparie à chaque phase, assumé : 1 clic ». Ligne vide =
  non rattachée, RIEN modélisé (l'absence de lien EST l'état). Mention obligatoire : « Données de la
  ligue — un écart se corrige auprès d'elle. »
- **Le confirm écrit sur la `Competition` de l'équipe** (réutilisée par nom canonique, sinon créée) :
  `ffbbCompetitionId`/`ffbbPouleId`/`ffbbPouleName`/`ffbbCompetitionName`, **`expectedMatchdays` =
  2×(N−1) figé à l'appariement**, et **`ffbbPouleOpponents`** (la liste des clubs de la poule, copiée —
  le garde-fou d'import restera hors-réseau, PR F2). Taille et adversaires viennent d'un **re-fetch
  serveur** — un client forgé ne peut pas éteindre la complétude. Un engagement = une équipe : ré-apparier
  ailleurs efface les réfs de l'ancienne ligne (ses fixtures survivent). Champs exposés en LECTURE sur
  `CompetitionResource`, jamais écrits par le CRUD.
- **Garde-fou poule (PR F2, 6.1)** : à l'analyze ET à l'import, pour une division résolue vers une
  compétition APPARIÉE, les adversaires DISTINCTS du fichier sont confrontés à la liste des clubs de
  la poule (containment mot-entier normalisé, l'idiome `containsClub` — « FIRMINY … - 1 » matche le
  club de poule « FIRMINY … »). **> 50 % d'inconnus → division refusée NOMMÉE et SAUTÉE** (« mauvais
  fichier, mauvaise équipe ou mauvaise phase ? ») — les autres divisions passent ; **1..50 % → warning
  `POULE_MISMATCH`** listant les inconnus ; division sans appariement = jamais contrôlée. **Hors-réseau
  par construction** (la liste fut copiée à l'appariement).
- **Complétude (PR F2, 6.2)** : au rapport d'import (« 9/22 journées — fichier partiel ou phase pas
  encore sortie », compté sur les `Fixture` PERSISTÉS) ET en **sévérité 6** du diagnostic
  (`COMPETITION_INCOMPLETE`, groupe « Calendriers incomplets » replié) — seules les compétitions à
  `expectedMatchdays` sont jugées.
- **Pré-remplissage de l'analyze (PR F2, 6.3)** : division NON mappée dont le libellé égale (normalisé)
  le **nom canonique FFBB** d'une compétition appariée → `suggestedTeamId` + `suggestedCompetitionId`
  (badge « proposé par la FFBB ») — une suggestion, jamais une résolution ; **jamais pour une division
  multi-labels** (le canonique ne sait pas dire laquelle des deux équipes — même refus que le
  résolveur) ; deux canoniques normalisés identiques = ambigu = aucune suggestion. Ce que le sélecteur
  AFFICHE est ce qui s'importe — une suggestion dont l'équipe n'est plus offrable n'est ni affichée ni
  envoyée. **La suggestion acceptée voyage AVEC son `competitionId`** : la compétition appariée est
  RÉUTILISÉE (renommée vers le libellé FBI — la clé du résolveur —, canonique/réfs/attendus/poule
  conservés), jamais dupliquée.
- **Le garde-fou précède l'écriture** (revue F2 round 1) : un mapping dont la division est refusée par
  le garde-fou poule n'est PAS persisté (erreur nommée, division ni importée ni re-signalée « à
  mapper ») — le dialog n'a pas de geste de re-mapping, une écriture fautive collerait. Deux mappings
  (équipe, division) identiques dans un même lot = une seule `Competition` (dedupe en mémoire, le
  lookup DB ne voit pas les frères non flushés).

## Lecture des fondations — `readState` sur `MatchesPage` (P4-133, 2026-08-30)

- **Trois lectures FONDATRICES** (`fixtures`, `teams`, `venues` — sans elles tout le reste de
  l'écran dérive d'un `data ?? []`) passent par la doctrine partagée `shared/lib/readState.ts`
  (`frontend/AGENTS.md` §readState) : `readLoading` sur l'une des trois → `FullPageSpinner`
  (chargement de PAGE, jamais un vide dérivé du premier chargement) ; `readFailed` (échec ET rien
  en cache — le seul cas où l'écran cède la place) → `LoadErrorHint` avec un `onRetry` qui refetch
  les trois d'un coup. Un refetch d'arrière-plan raté sur cache intact reste `ready` : l'écran qui
  fonctionne n'est pas détruit. **Avant ce lot**, seules ces trois lectures n'étaient PAS gardées
  (`data ?? []` direct) — un échec fixtures/teams/venues tombait dans l'`EmptyState` « Aucun match
  importé » de l'étape « batch », confondant échec et vide, et pouvait pousser à ré-importer
  par-dessus des données existantes.
- **`conflicts.isError` reste BRUT, volontairement PAS passé sur `readFailed`** (bloc
  `conflictErrorBlock`, message « Les conflits n'ont pas pu être vérifiés — rechargez la page avant
  de placer un match ») : c'est un avertissement de **sûreté** avant un geste d'écriture (placer un
  match contre une image des conflits potentiellement PÉRIMÉE), pas un état de chargement de page —
  décision fermée, [`etat-des-lieux.md`](etat-des-lieux.md) §2.
- Tests : `MatchesPage.test.tsx` (rencontres en échec, gymnases en échec → `LoadErrorHint`).

## Vérifs / gardes

- NR bloquant (phase1, CI) : `MatchTenantIsolationTest` (Competition/Fixture scopés club+saison, POST stampe,
  écriture saison archivée → 409 ; **+ PR B** : fenêtres/indispos scopées et stampées, `venueId` étranger →
  422 sans écriture cross-club, indispo management-gated 403 + saison archivée 409). PR B aussi :
  `VenueUnavailabilityImpactTest` (unit — sémantique ADR-0002 épinglée : période sans overlay = zéro alerte
  fantôme, overlay = SES créneaux), `VenueCapacityApiTest` (CRUD + validations + flux d'impact),
  `MatchConflictDetectorTest` étendu (VENUE_UNAVAILABLE, bornes incluses, sans-kickoff affecté),
  `SeasonTransitionServiceTest` (fenêtres copiées, indispos non), `useStepValidation.test` (exemption
  fenêtre match socle + période), `PlacementPanel.test` (filtre sélecteur, gardes jour/heure/indispo, repli). Le catalogue global reste hors tenant : garanti par `RlsIsolationTest` +
  `TenantOwnedInterfaceCompletenessTest` (il n'a pas de club_id) + `LeagueMatchWindowsApiTest` (partagé,
  aucune donnée club).
- PR-2 : `FixtureConflictsApiTest` (phase1) — structure du radar **+ isolation club** (un club ne voit jamais
  les conflits d'un autre). `MatchConflictDetectorTest` (unit) — match↔match, match↔entraînement, projection
  jour de semaine, overlay > base, demi-ouvert, away-sans-kickoff ignoré.
- Import (PR A P1-4) : `ImportFixturesAuthorizationTest` (phase1, §7.1 tenant) — non-management 403 (import
  ET analyze), saison archivée 409, **mapping vers une équipe étrangère → 400 sans écriture cross-club**.
  `FbiFixtureImporterTest` — le nominal tourne sur la **fixture réelle gelée** (124 lignes : 14 divisions,
  2 exempts, DF2=22/PNM=10, `00:00`→null, salle stockée) + diff/update (re-programmation, switch, heure en
  place, `00:00` n'écrase pas), deux-équipes-une-division par label, brassage inféré, et les successeurs de
  chaque garde PR-4 (derby, club absent, dates, colonnes, reader épinglé, en-tête legacy).
  `ImportFixturesApiTest` (HTTP bout-en-bout : analyze → import une passe → re-import diff + **NR périmètre
  engagé** : DELETE équipe → 409 après import). `ImportErrorMessageLeakTest` (P4-5 sur la nouvelle route).
  `ImportFbiDialog.test.tsx` (analyse au choix du fichier, mappings pré-remplis vs sélecteurs, rapport V2).
- Préférences (PR C) : `MatchTenantIsolationTest` +2 (phase1 — habitude scopée+stampée+unique/jour,
  passerelle symétrique/unique/anti-self, équipe étrangère → 422 sans écriture cross-club).
  `MatchConflictDetectorTest` +5 (estimation même-jour seulement + flag, heure réelle jamais supplantée,
  **NR : AWAY sans habitude reste invisible**, TEAM_LINK_OVERLAP sans coach, BACK_TO_BACK silencieux +
  demi-ouvert). `SeasonTransitionServiceTest` (habitude+lien recopiés remappés, normalisation conservée).
  Front : `habitInference.test` (seuils, non-horodatés ignorés, jour déclaré non re-suggéré, gymnase ≥ 50 %),
  `weekendGrid.test` (fantôme rendu/dissous par la réalité/lanes partagées), `PlacementPanel.test`
  (pré-remplissage + gardes souveraines).
- Diagnostic gradué (PR E2) : `MatchConflictDetectorTest` +4 (unit — VENUE_OVERLAP sévérité 1,
  LEAGUE_WINDOW_VIOLATION mappée-seulement, ACCESS_WINDOW_LOST parité panneau — dedans/demi-ouvert/
  club-sans-fenêtre, coachRole 3 vs 5 (règle inversée depuis, P4-189 — voir « Diagnostic gradué… »
  ci-dessus) ; + l'ancien NR « AWAY sans habitude
  invisible » amendé : plus de conflit horaire mais AWAY_NO_FOOTPRINT nommé),
  `FixtureConflictsApiTest` (severity+coachRole au contrat HTTP, **phase1**),
  `LeagueMatchWindowsApiTest` +1 (**phase1** — `resolvedTeamWindows` : U13 mappée → ids du
  catalogue, « Loisir » → []). Front : `envelope.test` réécrit (lookup serveur : [], absente, id
  fantôme), `diagnostic.test` (tri, tons, repli sév. 7, legacy sans severity → 5),
  `typicalWeekend.test` (empreinte, hors week-end exclu, lanes, sans-gymnase à part),
  `AwayList.test` (salle FBI + heure estimée/réelle/inconnue, suppression confirmée),
  `MatchesPage.test` +1 (bande extérieur + bascule week-end type).
- Boucle manuelle (PR E1) : `FixtureApiTest` +6 (**phase1** — déverrou accepté sur écho seul, 422 si
  le placement bouge ou si le statut quitte PLACED, écho → MANUAL, UNPLACED → null, SOLVER refusé au
  POST), `MatchPlacementContractSchemaTest` +1 (**phase1, NR contrat** — verrou/déverrou bascule les
  kinds FIXED/TO_PLACE du payload), `EngagedTeamGuardTest` +1 (**phase1, NR périmètre engagé** —
  DELETE fixture ≠ DELETE équipe, engagement dérivé relâché au dernier match), engine
  `test_colliding_fixed_anchors_never_sink_the_whole_solve` + `…pruned_not_infeasible` (NR — ancres
  en collision n'évincent plus tout le solve). Front : `editFixtureBody.test` (date conservée,
  HOME→AWAY libère), `weekendGrid.test` (badge verrou, null = manuel), `PlacementPanel.test` +6
  (Déplacer désactivé sans changement, actions, bascule verrou, confirmation de suppression,
  SUBMITTED lecture seule), `FixtureFormDialog.test` +2 (édition pré-remplie équipe figée, warning
  bascule extérieur), `MatchesPage.test` +1 (clic grille → panneau boucle manuelle). E2e : verrou
  aller-retour + dé-placer sur la vraie stack.
- Placement (PR D) : `MatchPlacementContractSchemaTest` (**phase1** : forme du payload au contrat backend⇄engine ; groupe
  `contract` : POST au VRAI engine, kickoff rendu DANS la fenêtre — sémantique, pas un 200),
  `PlaceMatchesControllerTest` (gardes 403/409, samedi placé dans [14:30,16:15] + dimanche
  `no_access_window`, **ancre manuelle jamais réécrite**, 502 si engine down sans écriture),
  `LeagueEnvelopeResolverTest` (jointure tolérante). Engine : `test_match_placement.py` (unit),
  `test_match_placement_semantics.py` (week-end réaliste + invariant `assert_no_hard_violation`),
  golden épinglé (`test_match_placement_golden.py` — chaîne BACK_TO_BACK sans trou sur ancre 20:30).
- Unit : `MatchFootprintTest`, `LeagueResolverTest`. Command : `SeedLeagueWindowsCommandTest`. Api :
  `FixtureApiTest`.
- Feature Behat : `backend/features/placement-des-matchs.feature`, context
  `MatchPlacementContext` (bout-en-bout SÉMANTIQUE : samedi placé SOLVER dans la fenêtre d'accès,
  dimanche non plaçable NOMMÉ) — remplace `smoke-place-matches.sh` (SUPPRIMÉ, P4-165), même
  parité assertion par assertion. **Auto-suffisant** : crée ses propres équipes jetables + son
  propre gymnase jetable (le seed dev porte désormais la répartition WE réelle du club — un vrai
  gymnase/équipe pourrait offrir une habitude/rotation concurrente qui casse le déterminisme des
  assertions), neutralise le temps du placement les fenêtres dominicales du club
  (`no_access_window` est CLUB-WIDE, `engine/app/solver/match_placement.py:113-118`) puis les
  restaure en `@AfterScenario` — no-op sur un club sans donnée WE. Nettoyage en cascade : matchs →
  rotation → fenêtre → équipes → gymnase. **Et** la feature `generation-du-planning-de-saison.feature`
  atteste `COMPLETED` (le pipeline hebdo survit au bump 2.2 ; payload hebdo inchangé).

## Le périmètre engagé — `TeamEngagementGuard` (2026-07-16)

**Réalité du terrain** : valider le planning de la saison valide aussi un **périmètre**, les équipes qui
font de la compétition. Une fois les matchs envoyés à la fédération, on n'y revient plus — « une équipe qui
joue ne peut pas être supprimée, ni avoir son niveau modifié ; elle peut être déplacée ou changer de
créneau ». Le planning de saison, lui, ne change **quasiment jamais** ; il s'ajuste dans de rares cas.

**Engagée** = elle porte **au moins un `Fixture`**, quel qu'en soit le statut. Décision fondateur : « si
import FBI pour les matchs, l'équipe est engagée d'office. Une correspondance pour les matchs implique que
l'équipe est engagée pour la fédération. Même si le statut est `UNPLACED`, même si le match n'est pas placé. »

⚠️ **Ne PAS filtrer sur le statut.** `FbiFixtureImporter` crée TOUT en `UNPLACED` — domicile **et** extérieur
(« Status is always UNPLACED : placing requires a CLUB venue + an explicit manager action »). Seul un geste
du gestionnaire (`FixtureStateProcessor`) pose un autre statut. Une garde exigeant `PLACED` serait donc
**inerte au moment précis où elle doit mordre** : juste après l'import, quand la fédération connaît déjà les
rencontres. *(Le docblock de `FixtureStatus` a longtemps prétendu qu'un match extérieur naissait `PLACED` —
c'était faux, et ça a coûté une règle bâtie sur du vide.)*

Conséquence assumée : **dès l'import FBI**, toutes les équipes de la compétition sont figées.

| Sur une équipe engagée | |
|---|---|
| Suppression (`DELETE /api/teams/{id}`) | **409** — sans ça, `EntityCascadeDeleter::purgeChildrenOfTeam` emporterait ses `Fixture`, y compris ceux déjà connus de la fédé |
| Changement de `Team.level` | **409**, sans exception — c'est sous ce niveau qu'elle est inscrite, et il se saisit AVANT de générer (il alimente le tag NIVEAU, donc les contraintes, donc la photo de structure de la version). Le laisser bouger après ferait diverger la photo — qui l'a figé — et la base, puis « Charger cette version » ramènerait l'ancienne valeur en silence. Vaut aussi pour un niveau jamais renseigné (`null` → REGIONAL refusé) et pour un effacement. Seule tolérance, qui n'est pas une exception : un PUT qui ré-écho le MÊME niveau passe — le front renvoie le payload complet, refuser l'écho casserait un renommage sans rien protéger. Le jour où l'import FFBB devra changer un niveau, ce cas sera traité **avec** la photo |
| `priorityTierId` / `tierOrder` | **libres** — perception interne du club |
| `isActive` | **libre** — sert aux plannings de période, pas au périmètre de la saison |
| Nom, créneaux, gymnase | **libres** |

**La salle d'un match, elle, n'est PAS protégée — et c'est délibéré (soldé le 2026-08-18, étendu par P2-52/RMM-10 le 2026-08-26).** Supprimer un gymnase dépointe le match, y compris un `SUBMITTED`/`VALIDATED` déjà déclaré à la ligue. Le geste **n'est pas refusé** (un gymnase qui ferme, ça arrive, et le match redevient visiblement « à placer », donc récupérable) : il est **annoncé**. La modale de suppression compte ces matchs à part (`DeletionImpactCounter::declaredFixturesOnVenue`) et dit qu'ils devront être re-soumis à la fédération. Le match SURVIT à la suppression, il perd sa salle — gardé par `DeletionImpactParityTest`. Détail du dépointage lui-même (foyer unique, raison persistante, seconde gâchette « validation ») : § « P2-52 — un match déclaré ne perd plus sa salle en silence » ci-dessous.

La règle vit à **un seul endroit** (`TeamEngagementGuard`) : la garde d'écriture et le contrat de lecture
(`TeamResource.isEngaged`, rempli en lot par `TeamStateProvider`) la consultent. Le front grise « Supprimer »
et le sélecteur de niveau à partir de ce champ — il ne re-dérive rien, sinon un second endroit répondrait
« engagée ? » et finirait par contredire le serveur.

Les purges de masse (`SeasonDataPurger`, `ErasedClubPurger`) ne passent pas par la garde : la saison entière
part, matchs compris.

**Le restore aussi** : `StructureRestorer::wipeStructure` (« Charger cette version ») supprime les `Team` de
la saison en DQL de **masse**, donc sans passer par le processor où vit la garde. `Fixture` n'est ni dans ce
wipe ni dans la photo (`StructureSnapshotter::FAMILIES`) : une équipe engagée que la photo ignore serait
supprimée et ses matchs survivraient en nommant un `team_id` mort (aucune FK ne l'arrête). L'invariant serait
alors vrai par l'API et faux ici — donc faux, et contournable en un clic.

`StructureRestorer::assertRestoreKeepsEngagedTeams` refuse le chargement (**409**) quand la photo ne contient
pas une équipe engagée. On refuse plutôt que d'épargner l'équipe : la garder hors de la photo rendrait la
structure incohérente avec la version qu'on prétend recharger. Il refuse **aussi** quand la photo porte un
**autre niveau** pour une équipe engagée : `level` est un champ mappé, donc la photo le réinsère tel quel, et le
gel du niveau (voir plus haut) serait contourné par le restore — le club se retrouverait inscrit sous un niveau
que l'API refuse ensuite de corriger. Une équipe engagée présente dans la photo **avec son niveau** ne bloque rien.

## P2-52 — un match déclaré ne perd plus sa salle en silence (RMM-10, 2026-08-26) — LIVRÉ, DERNIER lot de code du module matchs

Recadré deux fois par le fondateur avant implémentation (roadmap `P2-52`) : l'ancien comportement
dépointait un match **immédiatement** pendant une exploration « Charger cette version » (résidu
auto-avoué à la livraison de DOC-2), ce qui rendait l'exploration destructive — revenir à la bonne
version ne rendait pas la salle. Décisions retenues :

- **L'exploration ne dépointe plus rien.** `StructureRestorer::wipeStructure` a perdu son UPDATE DQL
  de masse sur `Fixture.venueId` : un match dont le gymnase est absent de la photo restaurée
  **survit intact**, en nommant un `venueId` **transitoirement pendouillant** (résidu assumé —
  recharger une version qui porte à nouveau ce gymnase rend le pointeur valide de nouveau sans
  aucun geste). Le radar peut afficher `ACCESS_WINDOW_LOST` sur ce match entre-temps : signal
  VRAI et transitoire, pas un bug. Gardé par `StructureRestorerTest` +
  `RegenerateFromVersionTest::testLoadVersionLeavesAMatchWhoseVenueDisappearedUntouched`.
- **La VALIDATION d'un planning devient la gâchette principale.** `GET
  /api/schedules/{id}/validate-impact` (lecture ouverte au Membre) sert l'impact AVANT que le
  gestionnaire confirme : `{orphanedFixtures, declaredOrphanedFixtures}` — combien de matchs
  domicile perdront leur salle (gymnase disparu du club+saison) et, parmi eux, combien sont déjà
  `SUBMITTED`/`VALIDATED`. `ValidateDialog` n'affiche l'annonce (« N matchs [dont X déjà déclarés]
  perdront leur salle ») QUE si N>0 (aucun bruit préventif — verbatim fondateur : « quel intérêt de
  faire du bruit préventif ») et désactive « Valider » tant que l'impact n'est pas connu (en vol ou
  en échec — jamais un impact inconnu présenté comme vide). `POST /api/schedules/{id}/validate`
  dépointe alors, dans la MÊME transaction que le choix de version, exactement les matchs que la
  route d'impact a annoncés — **parité par construction** : les deux consommateurs appellent le
  même prédicat `OrphanedFixtureFinder::orphanedFixtures` (« `venueId` posé mais aucun `Venue` de ce
  club+saison ne le porte »), donc l'annonce ne peut jamais mentir sur ce que la validation détruit.
- **Foyer unique du dépointage : `FixtureVenueLossMarker`.** Un match dépointé repasse `UNPLACED`,
  `venueId` NULL, `placementSource` NULL (il redevient plaçable), et porte la raison PERSISTANTE
  `Fixture.unplacedReason = venue_lost` (« Le gymnase n'est plus affilié au club ») — distincte de
  la raison volatile d'auto-placement qui ne vit que dans l'UI. La date/heure de coup d'envoi est
  **conservée** comme repère modifiable, jamais une salle réelle. La raison s'efface d'elle-même dès
  qu'une salle non-null est reposée (`Fixture::setVenueId`, maison unique de l'effacement).
- **La suppression de gymnase (DOC-2, 2026-08-18) gagne la même bascule.** `CascadePlan` remplace le
  `ClearFieldStep` générique sur `Fixture.venueId` par `FixtureVenueLossStep`, qui délègue au même
  `FixtureVenueLossMarker` — même état final que la validation, `count()` (donc l'annonce
  `venue_fixture` de la modale de suppression) inchangé.
- **Le périmètre engagé résiste aux deux gâchettes.** L'engagement se dérive de l'EXISTENCE d'un
  match (§ ci-dessus) : un match dépointé « salle perdue » EXISTE toujours, donc son équipe reste
  engagée — `DELETE /api/teams/{id}` → 409 dans les deux cas (`EngagedTeamGuardTest` étendu).
- **Contrat backend⇄engine inchangé** (`CONTRACT_VERSION` 2.15) : `unplacedReason` ne voyage jamais
  au moteur, c'est un champ de lecture pur (`FixtureResource.unplacedReason`).

NR : `Security/EngagedTeamGuardTest` (extension) · `Integration/Service/DeletionImpactParityTest`
(extension, volet validation) · `Integration/Service/StructureRestorerTest` +
`Integration/Api/RegenerateFromVersionTest` (l'exploration ne détruit plus). Revue sécurité du
2026-08-26 : `ValidateImpactController` durci **fail-closed** sur un contexte club irrésolu (patron
`DeletionImpactController`), et la borne club+saison du prédicat `OrphanedFixtureFinder` posée
**explicitement dans la sous-requête** (pas seulement promise au docblock) — sans elle, hors
contexte de requête (worker, CLI), un gymnase de n'importe quel club aurait pu « sauver » un
fixture du marquage.

## Filtres par équipe / coach / gymnase sur la vue Semaine (PR-1 « se rendre compte », 2026-09-08)

Besoin fondateur n°1 du 2026-09-08 (mesure du module sur ses 18 rencontres FFBB réelles) : **filtrer pour se rendre
compte** — « qu'est-ce que Thomas / SF2 / le JDR a comme matchs, et lesquels posent problème ? ». Pas un onglet à
part : une barre de filtres sur la vue Semaine, même patron que `/planning`.

- **Barre** (`MatchesFilterBar.tsx`) : segmenté « Par équipe · Par coach · Par gymnase » + la puce `ResourceFilter`
  de `features/planning` réutilisée telle quelle. Changer d'axe vide la sélection.
- **Coach** = ses équipes coachées (principal ET assistant, rôle affiché en pastille sur les listes « À placer » et
  « À l'extérieur ») **+** les équipes où il est joueur actif (`CoachPlayerMembership`, badge « joueur ») — même
  règle que la vue coach du planning. Ex. Mara → SF2 (coach) + SM2 (joueuse) ; Thomas Francon → U15M1, U21M1
  (principal) + SM1 (assistant). La grille ne porte pas le rôle (le modèle de cellule ne le connaît pas — repli assumé).
- **Appartenance** (`lib/matchFilter.ts`, dérivation pure testée) : un match est dans un filtre équipe/coach ssi son
  équipe est dans l'ensemble ; dans un filtre gymnase ssi il y est POSÉ (les extérieurs, sans gymnase, en sortent —
  assumé : la question est « que se passe-t-il au JDR ? »). Un conflit est retenu dès qu'**un acteur** est dans le
  filtre : équipe d'un `left/right/fixture/training`, `teamId` d'un calendrier incomplet, `coachId` (vue coach),
  `venueId`/`training.venueId` ou la fixture référencée posée au gymnase (vue gymnase).
- **Application en amont** : `filteredFixtures`/`filteredConflicts` nourrissent la grille, `UnplacedList`, `AwayList`,
  le radar ET le rail (`deriveLoopSteps` inchangé — les compteurs deviennent ceux du filtre). Sans filtre :
  pass-through des mêmes références, vue Semaine byte-identique.
- **Semaines** : le navigateur ‹ › parcourt les week-ends de la personne filtrée ; atterrissage
  `resolveActiveWeekend` (`lib/weekendGrid.ts`) = sélection si encore listée, sinon première semaine ≥ semaine courante,
  sinon la dernière. Empty state « Aucun match pour {libellé} cette semaine ».
- **URL** : `?vue=equipe|coach|gymnase&filtre=id,id` (`lib/urlState.ts`) — lu une fois au montage (patron deep-link du
  wizard), écrit en `replace` à chaque changement ; ids inconnus ignorés. Premier état de filtre du dépôt porté par
  l'URL (`/planning` reste sur son store seul).
- **Lecture seule** : aucun geste nouveau. Suite : l'onglet Consulter (PR-2a, ci-dessous) puis Mois · Phase (PR-2b).
- Dette relevée en chemin, hors périmètre : `Échap` ne ferme pas la puce `ResourceFilter` (fond de fermeture plein
  écran qui intercepte les clics) — roadmap **P4-184**.

## Onglet « Consulter » — le module sépare Importer · Placer · Consulter (PR-2a, 2026-09-08)

Décision fondateur (2026-09-08, après la mesure sur ses rencontres réelles) : **importer** (faire entrer les rencontres,
FBI xlsx ou API FFBB), **placer** (la boucle Semaine) et **consulter** (« voir les matchs placés
et les bugs, c'est une fonctionnalité entière ») sont trois espaces. Nav `MatchesLayout` : **Semaine · Consulter ·
Importer · Configuration** ; l'onglet Importer a livré sa page en PR-3b, détail § « Espace Importer » plus bas.

`/matchs/consulter` (`ConsultPage.tsx`) — **lecture seule** : aucune mutation, ni rail, ni panneau de placement.

- **Filtre PR-1 partagé** avec Semaine (même `filterMode/filterIds`, même URL `?vue=&filtre=`) : « Thomas » suit le
  gestionnaire d'un onglet à l'autre.
- **Type de compétition** (chips multi, défaut tout) : amical = `competitionId` null ; championnat / coupe / brassage =
  `Competition.competitionType`. ⚠ Une rencontre FFBB d'une compétition non appariée (coupes jeunes…) arrive sans
  `competitionId` et se range donc sous « Amical » — roadmap P4-194. Un conflit suit ses rencontres référencées ; un calendrier incomplet suit sa
  compétition ; un conflit sans rencontre ni compétition reste visible tant que « tout » est coché.
- **Semaine type** (interrupteur, affichée par défaut) : la grille avec ou sans les cases « Habitude … ».
- **Familles de conflits** (chips avec compteur, défaut tout coché) — les 9 `ConflictType`, libellés en table
  (`lib/conflictLabels.ts`) : collision de gymnase, hors fenêtre ligue, coach en double, match × entraînement,
  passerelle (info), placement fragilisé, calendrier incomplet, gymnase indisponible, extérieur sans heure. Le
  **compteur porte sur la temporalité affichée** (la semaine lundi→dimanche du week-end actif ; un conflit sans
  date est toujours compté) — le radar de Placer compte « tous ceux de la personne », son rail « ceux de la
  semaine » : Consulter tranche. Une chip décochée garde son compteur (compté avant le filtre de famille).
- **Chaîne pure** (`lib/consultFilter.ts`) : `applyMatchFilter` → `applyKindFilter` → `scopeConflictsToWeek` →
  `countByFamily` → `applyFamilyFilter`, pass-through des mêmes références quand tout est coché.
- **Grille + extérieurs en lecture** (`WeekendGrid`, `AwayList` sans ses actions), radar nourri des conflits filtrés ;
  clic sur un match → Placer sur son week-end. Navigateur ‹ › et `resolveActiveWeekend` comme Semaine.
- **URL** : `type=amical,championnat,coupe,brassage`, `conflits=<familles>`, `type_semaine=0|1` (absent = défaut).
- **Temporalités Semaine · Mois · Phase** (PR-2b, 2026-09-08 — contrôle segmenté à côté du navigateur, Semaine par
  défaut et byte-identique) :
  - **Mois** : navigateur ‹ mois › (`resolveActiveMonth` : sélection si listée, sinon premier mois ≥ courant, sinon
    dernier), table groupée par jour (`lib/monthView.ts`), compteurs de familles sur le mois, empty state « Aucun
    match pour {libellé} en {mois} ».
  - **Phase** = une compétition FFBB appariée (`Competition`) : `<select>` natif, en-tête « {importées} / {attendues}
    journées » (⚠ sur une coupe le dénominateur 2×(N−1) n'a pas de sens — roadmap P4-195) (le conflit `COMPETITION_INCOMPLETE` de la compétition s'il existe, sinon `count/expectedMatchdays` —
    comptage de présentation), journées groupées par week-end (`lib/phaseView.ts`), empty state « Aucune compétition
    appariée » → Engagements FFBB.
  - **Ligne de match** (`MatchRowsTable.tsx`, primitive partagée `table.tsx` née ici) : date + heure ou « heure non
    publiée », équipe (+ rôle en vue coach), dom./ext., adversaire, gymnase résolu sinon `fbiVenueLabel` « non
    rattaché », statut, une pastille par famille de conflit présente ; clic → Placer sur le week-end du match.
  - URL : `temps=semaine|mois|phase`, `mois=YYYY-MM`, `phase=<competitionId>`.
- Livré depuis, hors PR-2a : l'onglet Importer (PR-3a/PR-3b, § « Espace Importer » plus bas — file de
  traitement par équipe, compteurs « N à valider »/« N écart(s) »). P4-192 reste ouvert (l'atterrissage de Placer).

## Refonte UX — RMM-1 (P2-26, 4 PR entre 2026-08-23 et 2026-08-24)

> Cadrage : [`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md) §6quater
> (lignes L1-L9). **RMM-1 est livré en entier** (PR1 « geste manquant », PR2 « deux espaces », PR3
> « rail dérivé », PR4 « poste de travail ») ; RMM-2 (extraction de la primitive `step-rail`) et
> RMM-0 (lisibilité des modales) sont des lots FRÈRES déjà livrés séparément, réutilisés ici.
> **Zéro comportement moteur, zéro API** : la refonte réorganise l'écran, le solveur de placement et
> le radar (sections ci-dessus) sont inchangés.

- **Deux espaces, deux routes** : `/matchs` (la boucle hebdo) et `/matchs/configuration` (le SET-UP
  rare) sont deux vraies routes sous `MatchesLayout.tsx` — nav en onglets (deep-link + bouton retour
  du navigateur), garde socle **commun** (`useSocleValidated`, même condition que `SocleGuard`
  côté serveur) posé une seule fois autour de l'`Outlet`. `ConfigurationPage.tsx` héberge Accès
  match, Habitudes & passerelles et l'**image A/B en écran de plein droit** (`TypicalWeekendGrid` —
  le toggle `typicalView` a disparu de la boucle) — Engagements FFBB, lui, a depuis quitté cette
  page pour l'onglet Importer (P4-186, § « Espace Importer » plus bas) ; depuis P4-185 (§
  « Configuration — P4-185 » plus bas), ces six gestes sont des `AccordionSection` CONTRÔLÉES, une
  seule ouverte à la fois. Import FBI reste accessible des deux côtés (même dialogue, deux entrées).
- **Le rail à 5 étapes DÉRIVÉES** (`features/matches/lib/loopSteps.ts`, `deriveLoopSteps`, fonction
  **pure** — zéro état stocké, zéro backend) : *batch importé* (des fixtures existent sur la
  semaine) → *placés au modèle* (0 domicile `UNPLACED` dont l'équipe a une `TeamMatchHabit`) →
  *litiges* (compte du radar rattaché aux fixtures de la semaine, `weekConflictCount`) → *domiciles
  posés* (0 `HOME` `UNPLACED`) → *saisi FBI* (`SUBMITTED`+`VALIDATED` == tous les `HOME` de la
  semaine). Consomme la primitive partagée `shared/components/ui/step-rail.tsx` (second
  consommateur après le wizard). Store `railStep` (`null` = auto = **premier trou** — la première
  étape non faite, `defaultLoopStep` ; tout fait → la dernière étape, état « veille » entre deux
  rafales de matchs) ; changer de semaine (`setSelectedWeekend`) remet le rail à `null`.
  Deux formules VALIDÉES fondateur, à ne pas re-discuter : (1) un **écart au modèle** (`isOffModel`
  — jour/heure/gymnase divergeant de l'habitude déclarée) est un **SIGNAL affiché, jamais un
  `done` bloquant** (verbatim : « c'est un signal, c'est pas bloquant ») — sans habitude sur
  l'équipe il n'y a pas de modèle de référence, donc pas d'écart possible ; (2) un **conflit SANS
  fixture référencé** (ex. `COMPETITION_INCOMPLETE`, `datelessConflicts`) sort du compte hebdo de
  l'étape « Conflits » et s'affiche en **bandeau global** au-dessus du rail, jamais rattaché à une
  semaine précise.
- **Le geste manquant : « Marquer saisi dans FBI »** — `PlacementPanel.tsx` porte
  `onSubmit`/`onReopen` sur un match `PLACED`/`SUBMITTED` (PUT `status`,
  `FixtureStateProcessor.php:74`, tout écrit stampe `MANUAL`) — **réversible** : un `SUBMITTED`
  redevient `PLACED` par le même panel, ce qui en fait **le chemin de réparation** quand le
  gymnase d'un match déjà saisi meurt (suppression/indisponibilité, cf. § capacité ci-dessus).
  Vocabulaire FR unique (`lib/fixtureStatusLabel.ts`, table jamais un ternaire) : Importé · Placé ·
  **Saisi dans FBI** · **Attesté FBI** (« Validé ligue » jusqu'à PR-3a/D9, 2026-09-08 — la ligue ne
  « valide » rien, c'est la source qui répète la même valeur) — jamais les codes d'enum affichés.
- **Hiérarchie d'actions** : l'action primaire est celle de l'étape courante du rail (Importer FBI /
  Placer automatiquement / rien en Conflits-fbiEntry) ; les gestes rares (Engagements FFBB, Accès
  match, Habitudes & passerelles, image A/B) ont quitté la barre plate pour `/matchs/configuration` ;
  « Nouveau match » reste secondaire (`variant="outline"`) dans la barre utilitaire.
- **La semaine devient l'axe primaire** : label « Semaine du {lundi} au {dimanche} » au-dessus du
  rail (`weekLabel`, `lib/weekendGrid.ts`) — le bucket reste le week-end (Lun→Dim), seules les
  bornes affichées changent. Le **n° de rencontre** (`externalRef`) s'affiche dans la grille
  (`WeekendGrid.tsx`) et les listes (`AwayList.tsx`, `UnplacedList.tsx`, `FbiEntryList.tsx`) — un
  repère discret, **jamais une clé** (l'unicité reste composite club+saison+équipe+`externalRef`,
  fait #2 du cadrage).
- **Contexte stable** : le slot du panneau de placement est **PERMANENT** — un état vide
  (« Sélectionnez un match ») occupe la colonne quand rien n'est sélectionné (fin du saut de
  colonne qui faisait apparaître/disparaître `PlacementPanel`). Le **mode échange se VOIT sur la
  grille** : les candidates (les autres domiciles `PLACED` de la semaine, jamais la source)
  portent un anneau (`data-swap-candidate`), les autres cellules placées s'estompent
  (`opacity-40`, sans animation — pas de clignotement) ; **Échap désarme le mode** (WCAG 2.1.2
  escape route, listener `keydown` dans `MatchesPage.tsx`). Les **raisons de non-placement** du
  dernier auto-placement (store `unplacedReasons`, par fixtureId) restent affichées tant que la
  semaine reste à l'écran — un autre geste ne les efface plus (avant cette PR elles vivaient dans
  un state React local, perdu au moindre refresh) — et sont **purgées au changement de semaine**
  (`setSelectedWeekend`) : une raison d'une autre semaine ne fuit jamais.
- **La vue de saisie FBI** (`FbiEntryList.tsx`) — **une PROJECTION pure des fixtures existants,
  zéro backend**, pensée pour que la recopie dans FBI soit bête (coller à l'écran FBI, préconisation
  fondateur). Ne montre que les domiciles **recopiables** (`PLACED`/`SUBMITTED`/`VALIDATED` — un
  `UNPLACED` n'a rien à saisir) ; **groupée par équipe** (section = équipe, tri alpha), et dans
  chaque groupe les lignes encore à saisir sont en tête, les rangées déjà saisies en dessous. Une
  ligne porte date + heure (ce que FBI demande pour un domicile), l'adversaire, la salle, et le n°
  de rencontre en repère. **Cocher une ligne** = le geste ci-dessus (`onSubmit`) ; une ligne saisie
  garde un « Corriger » discret (`onReopen`, réversible) ; une `VALIDATED` est en lecture pure
  (« Attesté FBI », `FIXTURE_STATUS_LABEL.VALIDATED` — plus « Validé ligue » depuis D9). Filtrable **équipe** et **date**, indépendamment de la navigation
  semaine. « Tout marquer saisi » soumet en lot **UNIQUEMENT les lignes actuellement AFFICHÉES et
  encore `PLACED`** (le filtre en cours borne le lot — décision fondateur, plus sûr qu'un lot
  global), sous confirmation nommant le compte exact.
- **Front** — tests : `MatchesLayout.test.tsx` (deux routes, garde socle), `ConfigurationPage.test.tsx`,
  `lib/loopSteps.test.ts` (les 5 dérivations, écart-signal, conflit sans date), `store.test.ts`
  (`railStep`, `unplacedReasons` purgés à la semaine), `WeekendGrid.test.tsx` (candidates d'échange
  visibles, source jamais candidate), `FbiEntryList.test.tsx` (groupage équipe, filtres deux sens,
  lot borné au filtre affiché, état vide), `MatchesPage.test.tsx` (panneau permanent, Échap sort du
  mode échange). E2e : `tests/e2e/matches.spec.ts` inchangé dans son scénario (login → créer →
  placer), la refonte ne change aucune assertion de comportement moteur.

**Le point d'insertion annoncé ici est CONSOMMÉ** — RMM-6 est livré en entier (3 PR, détail
§ « Échéances ligue/comité — RMM-6 » plus bas) : le rappel cockpit et l'escalade cockpit/login sont
la carte `FbiDeadlineCard` (RMM-6 PR-3, 2026-08-25) sur `/`, qui réutilise `MatchModuleDeltaComputer`
via l'outlook (§ « Le gardien » ci-dessous) précisément comme le préparait la décision fondateur du
2026-08-24 — le calcul du delta était resté séparé de la rotation de référence pour cette lecture
future.

## Le gardien à l'ouverture (RMM-3, 2 PR — backend puis front, 2026-08-24)

> Cadrage : [`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md) §7 et
> §9 (RMM-3). Besoin d'origine : le radar de conflits est **stateless** (recalculé à chaque appel),
> il ne peut donc jamais dire au gestionnaire ce qui a **changé depuis sa dernière visite** — il
> découvre les nouveaux litiges « après coup ».

- **L'empreinte d'un conflit — l'identité STABLE d'un litige.** Maison unique
  `App\Service\ConflictFingerprinter` : une chaîne `TYPE:champs` dérivée des champs d'IDENTITÉ
  seuls (les fixtures/coach/gymnase/compétition en cause, paires TRIÉES pour effacer l'artefact
  gauche/droite du détecteur) — **jamais** `severity`, `start`/`end`, ni un compteur
  (`imported`/`expected`). Deux fixtures dont l'heure estimée glisse restent le MÊME
  `MATCH_MATCH` ; une `COMPETITION_INCOMPLETE` qui passe de 9/22 à 15/22 reste le MÊME conflit — un
  ré-import ne le re-badge pas « Nouveau ». Une nature qui change réellement (le match A passe d'un
  conflit avec B à un conflit avec C) produit une empreinte NEUVE, donc un badge. Consommée par
  `FixtureConflictsController` (champ additif `fingerprint` sur `Conflict`) et par le calcul de
  delta ci-dessous — la même empreinte des deux côtés, par construction.
- **La référence de visite — persistance PAR utilisateur.** `App\Entity\MatchModuleVisit`
  (table `match_module_visit`, contrainte d'unicité club+saison+`user_id`) fige un instantané
  (empreintes de conflits + `chosenScheduleId`/dernière COMPLETED du plan SEASON) à chaque visite.
  Le scoping utilisateur est APPLICATIF (patron `Feedback` — le contrôleur filtre sur `user_id`,
  tenant+saison restent sous `TenantFilter`/RLS) : deux gestionnaires du même club ont chacun leur
  propre « dernière visite ». Donnée PERSONNELLE (horodatages) : supprimée à l'effacement de
  compte, exportée en portabilité RGPD.
- **`POST /api/matches/module-visit`** (`MatchModuleVisitController`, aucune garde management —
  ouvert au Membre, patron `FeedbackController` ; écrit même saison archivée, c'est un bookkeeping
  utilisateur, pas une mutation du planning) — **UN SEUL endpoint**, pas de GET séparé : deux appels
  ouvriraient une course F5 entre lire le delta et tourner la référence. Trois cas :
  - **Première visite** : référence figée en SILENCE, `firstVisit: true`, tous les comptes à zéro —
    rien à comparer.
  - **Hors fenêtre de grâce** (`lastOpenedAt` > 30 min, `GRACE_MINUTES`) : NOUVELLE visite — le
    delta est calculé contre l'ANCIENNE référence, PUIS la référence tourne sur l'état courant.
  - **Dans la grâce** (fenêtre glissante) : MÊME visite — le delta est recalculé contre la
    référence NON tournée, seul `lastOpenedAt` avance. Un F5 rejoue donc les mêmes badges
    (idempotent), il ne les éteint jamais.
  Le calcul lui-même vit dans `App\Service\MatchModuleDeltaComputer`, tenu SÉPARÉ de la rotation
  (le contrôleur seul stampe) — trois signaux, tous falsifiables dans les deux sens
  (`MatchVisitDeltaParityTest`, liste canonique `docs/testing/blocking-tests.md`) : `newFixturesCount` (fixtures nées APRÈS la
  référence, `createdAt > takenAt`) · `newConflictFingerprints` (empreintes courantes ABSENTES du
  snapshot — un conflit disparu ne produit rien, seul le neuf est signalé) · `planningChanged` (la
  version choisie OU la dernière COMPLETED du plan SEASON diffère du snapshot, comparaison
  d'**IDS**, jamais d'`updatedAt`).
- **Front — le POST part au montage du module, une fois.** `MatchesLayout.tsx` appelle
  `useModuleVisit(socleValidated)` (`features/matches/queries.ts`) : `enabled` piloté par la même
  garde socle que le verrou du module — **aucune visite n'est stampée sur un module verrouillé** ;
  `staleTime`/`gcTime: Infinity`, `retry: false` — un seul POST par ouverture, jamais à un
  re-render, jamais à la navigation boucle⇄configuration (même montage de layout, seul l'`Outlet`
  change). `ModuleVisitBanner.tsx` et `ConflictRadar.tsx` lisent le MÊME cache react-query (une
  seule requête nourrit les deux affichages).
- **`ModuleVisitBanner`** — un bandeau `role="status"` (pas `alert`), ton ACCENT (heads-up amical,
  distinct du warning des conflits sans date et du destructive socle), au-dessus du rail dans
  `MatchesPage.tsx`. Muet si `firstVisit` ou delta vide (aucun des trois segments non nul). Les
  segments NON NULS s'affichent dans l'ordre du geste — matchs arrivés → nouveaux conflits →
  planning changé — singuliers propres (« 1 match arrivé », « 1 nouveau conflit »), ex. « Depuis
  votre dernière visite : 12 matchs arrivés · 3 nouveaux conflits · le planning de saison a
  changé ». **Non dismissible** (décision passe design) : le delta ne revient pas dans la session
  (grâce serveur + cache infini côté client), un contrôle de fermeture ajouterait de l'état pour
  rien — le bandeau ne paraît QUE quand il y a du neuf.
- **Chips « Nouveau » sur le radar** — `ConflictRadar` reçoit une prop optionnelle
  `newFingerprints` (`ReadonlySet<string>`) ; un conflit dont `fingerprint ∈ newFingerprints` porte
  une chip. **Ornement PUR** : absente (prop non fournie ou empreinte non présente) → radar
  intact ; rien de la sévérité, du tri, des libellés ni des étapes du rail n'en dépend — vérifié en
  non-régression (`MatchesPage.test.tsx` : un delta plein affiche le bandeau sans toucher au moindre
  libellé du rail).

## Réconciliation FBI (RMM-4, 3 PR — backend, front, canal API, 2026-08-24 — LIVRÉ EN ENTIER)

> Cadrage : [`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md) §4
> fait 1, §7 et §9 (RMM-4). Besoin d'origine : un ré-import FBI qui re-décide une date/heure/salle
> d'un domicile **déjà placé** mettait à jour en silence — aucune alerte, la divergence app⇄FBI
> passait inaperçue. **Le lot est LIVRÉ EN ENTIER** (P2-48, quitte la roadmap) : PR-1/PR-2 ont posé
> l'écran de choix par écart sur le dépôt xlsx ; **PR-3 rebranche `ReconciliationPanel` sur un
> second canal** — la vérification à la demande via l'API FFBB (`ReconciliationPanel.tsx:27` était
> délibérément agnostique du canal qui l'alimente, exactement pour ce rebranchement).

- **Le périmètre de réconciliation (D1/D3)** : seuls les domiciles **déjà placés** peuvent diverger
  — un extérieur, ou un domicile encore `UNPLACED`, ou une ligne qui change de côté, sort du
  périmètre. Trois champs seulement deviennent un CHOIX : **date, heure (kickoff), salle**
  (`FbiFixtureImporter::DEVIATION_FIELDS`) ; adversaire et libellé de salle brut restent une mise à
  jour silencieuse (jamais un écart présenté).
- **`analyze()` détecte, `import()` tranche.** L'analyse (dry-run, zéro écriture) recalcule les
  écarts et les rend groupés par fixture (`groupDeviations`) ; l'import reçoit en plus un champ
  multipart `decisions` (liste `{fixtureId, field, choice: keep_app|take_file}`) — **un champ SANS
  décision n'est jamais écrit par défaut** et atterrit dans `unresolvedDeviations` du rapport
  (`FbiFixtureImporter::applyDeviationMode`). Le diff est RECALCULÉ à l'import (la base a pu bouger
  depuis l'analyse) — une décision porte sur un champ qui a cessé de diverger est simplement ignorée.
- **La conséquence par champ, écrite dans le moteur d'import ET redite au front** (jamais
  redérivée — `frontend/.../lib/deviationConsequence.ts` l'énonce, ne la recalcule pas) :
  **date** ou **salle** prises du fichier **dé-placent** le match (`UNPLACED`, salle effacée, à
  replacer) — la ligue a re-décidé ; **heure** prise du fichier reste **en place** (le gymnase
  n'est jamais remis en cause) mais **rétrograde** un `SUBMITTED`/`VALIDATED` en `PLACED`
  (`demoteSubmitted`) — la case FBI était cochée sur une mauvaise heure, à re-saisir. « Garder
  l'app » n'écrit jamais rien.
- **La trace — vit sur la rencontre depuis PR-3a (2026-09-08, D7), plus sur le dépôt.** Chaque
  dépôt écrit toujours une `FbiIngestion` datée (`club_id`+`season_id`, PAS personnelle —
  RGPD/purges génériques), mais elle ne porte plus que la FRAÎCHEUR et les COMPTEURS : les écarts
  eux-mêmes vivent dans `Fixture.pendingDeviations` (un par champ, `seenAt` = première apparition
  conservée tant qu'il diverge). Un écart encore présent au dépôt suivant porte `persisting: true`
  (badge « Écart persistant », dérivé de la présence d'une entrée pendante) ; un « prendre le
  fichier » RÉSOUT l'écart (retiré de `pendingDeviations`) ; un fichier revenu à la valeur app
  l'éteint aussi ; un fixture disparu emporte sa trace avec lui (elle n'existe plus ailleurs). Détail
  complet du workflow de traitement (NEW/OUT_OF_SYNC/REVIEWED, gestes de traitement, D9) :
  § « Espace Importer — workflow de traitement (PR-3a) » plus bas.
- **La fraîcheur — `GET /api/fbi-ingestions/latest`** (`FbiIngestionFreshnessController`, lecture
  ouverte au Membre, aucune garde management) : le dernier dépôt du club+saison (`null` = aucun).
  Front : `useLatestFbiIngestion` (invalidée après chaque import) alimente une carte dans
  `ConfigurationPage` (« Dernier dépôt FBI : aujourd'hui/hier/il y a N jours », escalade en warning
  au-delà de `STALE_DAYS = 30` ou en l'absence totale de dépôt) et un rappel discret (`text-xs`
  muted, sans bordure ni icône) près du rail semaine de `MatchesPage` — « aujourd'hui » toujours lu
  du front (`todayISO`, ancrage démo compris), jamais du serveur.
- **La vue dédiée `/matchs/reconciliation`** (décision fondateur 2026-08-24, passe de conception
  `ui-ux-pro-max` : l'écran de choix ne tient pas dans la modale d'import). Enfant de
  `MatchesLayout` — garde socle héritée, aucune route propre. **Zéro état serveur** : elle vit du
  payload porté EN MÉMOIRE par le store (`useMatchesStore().reconciliation`). Arriver ici sans
  payload (accès direct, refresh, F5) est un **renvoi propre** vers l'onglet Importer (`EmptyState`
  + retour) — rien n'est écrit.
  > ⚠ **Recalé par PR-3b (2026-09-08) — le paragraphe ci-dessus décrit l'état RMM-4 d'origine,
  > périmé sur deux points.** (1) `ImportFbiDialog` n'y bascule plus jamais : le dépôt xlsx envoie
  > toujours `{file, mappings}` sans détour, les écarts sont désormais CONSIGNÉS sur les rencontres
  > et traités dans la file de l'onglet Importer (§ « Espace Importer » plus bas). (2) La vue ne
  > porte plus que le canal API et seulement ses rencontres **créables** (`ReconciliationPayload`
  > réduit à `{channel: "api", creatable, fetchedAt}`) — plus aucun écart, xlsx ou API, n'y transite.
- **`ReconciliationPanel` — SUPPRIMÉ en PR-3b.** Il portait la carte par écart (toggle segmenté
  sans défaut, bande destructive sur un match déjà déposé, gestes de masse) décrite ci-dessus pour
  RMM-4 ; ce rôle a disparu avec le détour « Examiner… ». Ce que `/matchs/reconciliation` fait
  aujourd'hui — proposer à la création les rencontres FFBB créables, un `TeamSelect` par ligne — vit
  dans `ReconciliationView.tsx` seul, détaillé § « Espace Importer ».
- **Front** — tests (état courant, PR-3b) : `lib/deviationConsequence.test.ts`, `lib/fbiFreshness.test.ts`,
  `ReconciliationView.test.tsx` (renvoi propre sans payload, abandon, création des rencontres
  créables), `ImportFbiDialog.test.tsx` (flux `{file, mappings}` sans décisions, rapport « N écart(s)
  consigné(s) dans Importer »), `ImportPage.test.tsx`/`lib/reviewQueue.test.ts` (carte « Données de
  match », fraîcheur, `checkViaApi` en trois issues, file de traitement), `ConfigurationPage.test.tsx`
  (réglages de saison seuls — plus de carte de fraîcheur ni de bouton API depuis P4-186).

### Le canal API FFBB (RMM-4 PR-3, 2026-08-24)

- **Une seule porte reste vers l'écran d'intégration, depuis PR-3b (2026-09-08).** Jusqu'à RMM-4
  PR-3, le dépôt xlsx et le canal API partageaient `/matchs/reconciliation` sous deux variantes
  `channel: "xlsx"|"api"` d'un même `ReconciliationPanel`. **Le dépôt xlsx ne bascule plus jamais
  vers cette vue** : ses écarts sont consignés directement sur les rencontres (`Fixture.pendingDeviations`)
  et traités dans la file de l'onglet Importer. Seul le bouton « Vérifier via l'API FFBB »
  (`ImportPage`, `useFfbbRencontres`, à la demande seulement, aucun cache ni cron) peut encore
  ouvrir `/matchs/reconciliation` — et seulement quand il y a des rencontres **créables** ;
  `ReconciliationPayload` n'a plus qu'un variant, `{channel: "api", creatable, fetchedAt}`. Badge
  fixe « Source : API FFBB ».
- **La hiérarchie ne bouge pas : FBI (le xlsx) fait foi, l'API est un confort.** Un bandeau
  d'honnêteté `role="status"` rappelle à chaque ouverture du canal API « ce que la FFBB publie à
  cet instant » et que la couverture fédérale n'est **jamais garantie** — une équipe absente du
  résultat peut avoir des matchs, l'import FBI reste la référence. `FfbbApiClient::searchRencontres`
  applique un filtre STRICT côté serveur (le code club doit apparaître sur `idOrganismeEquipe1`
  OU `idOrganismeEquipe2` du hit) après une recherche plein texte bruyante (bruit mesuré :
  un hit « AMICAL PNM » ne concernant pas le club).
- **Appariement 3 étages, plus un tier-0 d'idempotence** (`FfbbRencontreReconciler::matchRow`) :
  0. une fixture portant déjà l'id national de la rencontre (`Fixture.ffbbRencontreId`) — une
     re-vérification ne re-propose jamais un match déjà créé ; 1. la compétition APPARIÉE résout
     l'équipe (une compétition non appariée = un amical → équipe non résolue → proposée à la
     création) ; 2. parmi les fixtures de cette équipe, une date EXACTE ; 3. à défaut, un
     adversaire normalisé (rattrape une date déplacée). Les écarts détectés sur un match APPARIÉ
     réutilisent VERBATIM `FbiFixtureImporter::detectFieldDeviations`/`groupDeviations` — même
     périmètre (domiciles déjà placés), mêmes trois champs, même moteur de décision que le xlsx,
     jamais une seconde copie.
- **Les créations proposées, jamais imposées** — la valeur ajoutée réelle de ce canal : les
  rencontres publiées sans fixture correspondante (mesuré sur un vrai club : uniquement des
  AMICAUX, zéro championnat — le calendrier officiel continue de passer par FBI). Chaque ligne
  « Présents à la FFBB, absents de l'app » porte un `TeamSelect` — la compétition appariée
  pré-remplit une suggestion, **rien n'est créé pour une ligne dont le select reste vide** («
  Ne pas créer »). Un match AWAY créé depuis ce canal reste purement INFORMATIF (aucune salle
  club à poser, aucun geste de placement requis) — même statut qu'une saisie manuelle extérieure.
- **`POST /api/ffbb/rencontres/apply` RE-FETCHE côté serveur** — jamais les valeurs portées par
  le client — puis ré-applique le même appariement et les décisions avant d'écrire ; une création
  choisie deux fois (double-clic, onglets concurrents) est absorbée par l'idempotence tier-0, et
  une collision vraiment concurrente heurte l'index unique partiel
  (`uniq_fixture_ffbb_rencontre` — club+saison+équipe+`ffbbRencontreId`, NULL exclus) pour un
  409 propre plutôt qu'un 500.
- **Ce que le canal API n'affecte JAMAIS** : la fraîcheur xlsx (`GET /api/fbi-ingestions/latest`
  ne lit que les dépôts `FBI_XLSX`). Chaque apply écrit sa propre `FbiIngestion` datée
  `source=FFBB_API`, compteurs seuls (la colonne `pendingDeviations` a disparu de `FbiIngestion`
  aux deux canaux, PR-3a D7). **Depuis PR-3a, `apply` POSE le traitement** de la rencontre au même
  titre que le xlsx (`reviewState`/`pendingDeviations` sur `Fixture`, moteur partagé
  `processPerimeterFields`/`reconcileNoDivergence` — jamais une seconde copie) ; `GET
  /api/ffbb/rencontres`, lui, reste un pur dry-run (rien écrit). Détail : § « Espace Importer »
  ci-dessous.
- **Robustesse (revue sécurité 2026-08-24, aucune vulnérabilité trouvée)** : les chaînes externes
  (libellés, id de rencontre) sont de la donnée FFBB non bornée, les colonnes le sont — clampées
  à la frontière du reader (`FfbbRencontreReader`) : labels tronqués à 180, une ligne dont l'id
  dépasse 64 caractères est ÉCARTÉE (une clé d'idempotence ne se tronque jamais), plutôt que de
  faire échouer l'apply entier en 502 au flush.
- **Back** — tests : `FfbbRencontresApiTest.php` (les deux routes, gardes SEC-07/socle/tenant,
  re-fetch serveur, 409 doublon), `FfbbRencontreReaderTest.php` (mapping, filtre saison, clamp),
  `FfbbApiClientTest.php` (filtre strict serveur).

## Espace Importer — workflow de traitement (PR-3a backend + PR-3b frontend, 2026-09-08 — LIVRÉ EN ENTIER)

> Besoin d'origine : RMM-4 posait le CHOIX par écart (garder l'app / prendre le fichier) mais
> aucune vue ne disait, rencontre par rencontre, « celle-là je l'ai déjà traitée, celle-là est
> nouvelle, celle-là a re-divergé ». PR-3a a ajouté cet axe — le TRAITEMENT — côté backend ; **PR-3b
> (même jour) branche l'onglet Importer dessus** : la file de traitement par équipe, la
> Configuration allégée (P4-186, qui quitte la roadmap) et le vocabulaire D9 (« Attesté FBI »).
> Deux axes désormais distincts sur une `Fixture` : `status` (placement — où en est le match dans le
> cycle FBI) et `reviewState` (a-t-il été EXAMINÉ par le gestionnaire).

- **`FixtureReviewState`** (`App\Enum\FixtureReviewState`) — trois valeurs : **NEW** (jamais
  examinée), **OUT_OF_SYNC** (déphasée — au moins un écart pendant subsiste), **REVIEWED**
  (traitée — aucun écart pendant). `Fixture.reviewedAt` horodate le dernier traitement (`null` =
  jamais). `Fixture.pendingDeviations` (JSON) porte les écarts encore ouverts, un par champ
  (`date`/`kickoff`/`venue`) : `appValue`, `sourceValue`, `channel` (`FBI_XLSX`\|`FFBB_API`),
  `seenAt` (première apparition, conservé tant que ça diverge), `autoApplied` (une valeur imposée
  hors périmètre pendant que le match était traité — voir la ligne « absente/hors périmètre »
  ci-dessous).
- **Maison unique du statut — `Fixture::setStatus(FixtureStatus, DateTimeImmutable $now)`** (D6,
  « placer = traiter ») : passer à un statut PLACÉ (`PLACED`/`SUBMITTED`/`VALIDATED`) traite la
  rencontre — `REVIEWED` + horodatée — sauf s'il lui reste un écart pendant (elle reste
  `OUT_OF_SYNC`). `UNPLACED` ne touche jamais le traitement. L'horloge est TOUJOURS passée par
  l'appelant (jamais un `new DateTimeImmutable` nu) — le mode démo/play a une horloge simulée.
- **Le tableau cas → effet** (identique aux deux canaux, xlsx et API — le moteur est le MÊME,
  `FbiFixtureImporter::processPerimeterFields`/`reconcileNoDivergence`) :
  - rencontre créée (nouvelle, ou hors périmètre — extérieur, ou domicile encore `UNPLACED`) → `NEW`.
  - source identique à l'app, rien à traiter → rien ne bouge.
  - **D9** — domicile `PLACED`/`SUBMITTED` que la source (xlsx OU API) renvoie identique sur
    **date + heure + salle** (les trois présents : une heure réelle, pas la sentinelle FBI 00:00 ;
    une salle nommée ET connue de l'app) → `VALIDATED` + traité (`sourceAttestsPlacement`,
    `reconcileNoDivergence`). **VALIDATED n'est plus un geste du gestionnaire** — rien côté club
    ne l'atteste ; c'est désormais un EFFET de l'import (décision fondateur 2026-09-08, revoit le
    « la ligue possède ce statut » de RMM-1).
  - un champ diverge sans décision → `OUT_OF_SYNC`, l'écart persisté (`pendingDeviations`), la
    valeur app GARDÉE (jamais écrasée par défaut).
  - une décision arrive (`keep_app` retire l'écart sans écrire, `take_file`/`take_source` adopte
    la source et retire l'écart) → dernier écart retiré ⇒ `REVIEWED` + horodatée (D5).
  - un champ HORS périmètre (`RESCHEDULED`/`SWITCHED`/heure auto-appliquée) change en silence sur
    une rencontre DÉJÀ traitée → retombe `OUT_OF_SYNC`, avec une entrée `pendingDeviations`
    `autoApplied: true` par champ touché (`FbiFixtureImporter::recordAutoApplied`) — une rencontre
    `NEW` (jamais examinée) n'a rien à être « de nouveau » en désaccord, elle reste `NEW`.
  - **une rencontre absente du dépôt n'est jamais touchée** (import partiel légitime, décision
    fondateur — un fichier qui n'exporte qu'une poule ne doit pas faire disparaître ou geler les
    autres) : ni son `status`, ni son `reviewState`, ni ses écarts ne bougent.
  - création manuelle (POST `/api/fixtures`) → `REVIEWED` (le geste de saisie EST le traitement).
- **Deux routes de traitement, gate management + saison écrivable + socle pointé (patron
  `PlaceMatchesController`)** :
  - **`POST /api/fixtures/review`** (`ReviewFixturesController`) — **exactement un** des deux :
    `{fixtureIds}` (geste LIGNE : chaque rencontre listée est traitée, ses écarts pendants VIDÉS —
    « garder l'app » implicite, D4) OU `{teamId}` (geste MASSE : toutes les rencontres de l'équipe
    traitées, SAUF celles à écart pendant — sautées et nommées dans `skipped[{fixtureId, reason:
    "pending_deviations"}]`, jamais tranchées en masse). Rend `{reviewed, skipped}`.
  - **`POST /api/fixtures/review/deviations`** (`ReviewFixtureDeviationController`) — tranche UN
    écart : `{fixtureId, field: date|kickoff|venue, choice: keep_app|take_source}`. `take_source`
    REJOUE le moteur partagé (`FbiFixtureImporter::applyFieldTakeFile`) à partir de la valeur
    **PERSISTÉE** de l'écart, jamais d'une valeur envoyée par le client — la conséquence par champ
    est celle de RMM-4 (date/salle dé-placent, heure reste en place mais rétrograde un
    `SUBMITTED`/`VALIDATED` en `PLACED`). Dernier écart retiré → `REVIEWED` + horodatée.
- **La trace a changé de maison (D7)** : elle vivait sur le DÉPÔT (`FbiIngestion.pendingDeviations`,
  un pense-bête relu au dépôt suivant) ; elle vit désormais **sur la rencontre**
  (`Fixture.pendingDeviations`) — migration `Version20260908120000` supprime la colonne côté
  `FbiIngestion` (backfill D2 : une rencontre déjà placée au moment de la migration, `status <>
  UNPLACED`, est réputée traitée → `REVIEWED` ; `reviewedAt` reste `null`, jamais d'horodatage
  rétroactif fabriqué). `FbiIngestion` reste la maison de la FRAÎCHEUR (`GET
  /api/fbi-ingestions/latest`) et des compteurs (`created`/`updated`/`unchanged`) — les deux canaux
  continuent d'écrire un dépôt daté à chaque passage.
- **`FixtureResource`** expose `reviewState`, `reviewedAt`, `pendingDeviations`, `ffbbRencontreId`
  en lecture ; `FixtureInput` (le corps du PUT) est INCHANGÉ — un PUT ne peut jamais écrire
  `reviewState` directement, seul un geste de traitement le fait. Les compteurs par équipe (« N à
  traiter ») sont **dérivés côté client** — pas d'endpoint agrégé (décision, pas un manque).
- **PR-3b (frontend, livré le même jour)** — l'onglet Importer branché sur l'axe traitement :
  - `/matchs/importer` (`ImportPage.tsx`) : carte « Données de match » (Importer FBI, Vérifier via
    l'API FFBB, Engagements FFBB, fraîcheur `useLatestFbiIngestion`) + la file (`ReviewQueue.tsx`,
    `lib/reviewQueue.ts` `buildReviewQueue`/`pendingReviewCount`, dérivée côté client du même cache
    `useFixtures` — pas d'endpoint agrégé, décision assumée ci-dessus). Un accordéon PAR équipe
    (`AccordionSection` en mode contrôlé, ouverture dans `?equipe=`), tri `compareTeamsByRank` ;
    en-tête « SM1 · N à valider · N écart(s) » ; « Tout valider » (`POST /api/fixtures/review
    {teamId}`, les rencontres à écart sautées sont NOMMÉES en toast, jamais tranchées en masse) ;
    interrupteur « Afficher les traitées » (`?traitees=1`, masquées par défaut — une équipe sans
    rencontre ouverte disparaît de la file tant qu'il n'est pas activé).
  - `ReviewQueueRow.tsx` : une rencontre `NEW` (ou à seuls écarts `autoApplied`) se valide en un
    clic (`onValidateLine`, « garder l'app » implicite) ; une rencontre à écart ARBITRABLE se
    tranche champ par champ, deux colonnes Amateo / source (FBI ou API FFBB), boutons « Garder
    Amateo » / « Prendre {source} » avec la conséquence de `lib/deviationConsequence.ts` toujours
    visible (texte `keep_app` recalé : « la rencontre est traitée avec la valeur d'Amateo ») ;
    un écart `autoApplied` (hors périmètre, imposé par la source pendant que le match était traité)
    porte son propre bandeau (« La source a déplacé ce match … au … ») ; bouton « Placer » qui
    pose le filtre équipe et le week-end du match puis renvoie à Semaine.
  - **Le flux xlsx a perdu son détour.** `ImportFbiDialog` n'affiche plus « Examiner les écarts » :
    « Importer » envoie toujours `{file, mappings}` sans décisions ; le rapport affiche « N
    écart(s) consigné(s) dans Importer » + un bouton « Ouvrir la file » (au lieu de basculer vers
    `/matchs/reconciliation`, dont le rôle a changé — voir § « Réconciliation FBI » ci-dessus). Le
    canal API (`checkViaApi` dans `ImportPage`) route en trois issues : des rencontres créables →
    la vue de réconciliation ; pas de créable mais des écarts → `apply` direct (toast « N écart(s)
    consigné(s) dans la file ») ; rien → toast « Tout est en phase avec ce que la FFBB publie »
    (aucun `apply`).
  - **Configuration allégée — P4-186 SOLDÉ.** Le dépôt FBI, le canal API et les Engagements FFBB
    ont quitté `ConfigurationPage.tsx` pour l'onglet Importer (décision fondateur 2026-09-07 : « du
    RUN, pas de la configuration ») ; `ConfigurationPage` ne porte plus que des réglages de saison
    (gabarit A/B, créneaux partagés, échéances, durées, adversaires, accès match, habitudes &
    passerelles). Le repli visuel des sections restantes est livré séparément — **P4-185, § ci-dessous.**
  - **Vocabulaire D9 recalé.** `lib/fixtureStatusLabel.ts` : `VALIDATED` se dit « Attesté FBI »
    (plus « Validé ligue ») ; `PlacementPanel.tsx` sur un match `VALIDATED` dit désormais « Attesté
    par FBI : date, heure et salle renvoyées identiques. Un futur import qui diverge le signalera
    dans Importer » — l'ancien texte (« La ligue a validé ce match… ») était devenu inexact depuis
    D9 (un import peut poser `VALIDATED`, et un écart peut l'en faire retomber).
  - `AccordionSection` (`shared/components/ui/accordion.tsx`) gagne un mode CONTRÔLÉ optionnel
    (`open`/`onToggle`, rétro-compatible — omis, elle reste non-contrôlée) : premier consommateur,
    `ReviewQueue`, pour refléter l'équipe ouverte dans l'URL.
  - **Front** — tests : `lib/reviewQueue.test.ts`, `ImportPage.test.tsx`, `MatchesLayout.test.tsx`
    (badge `Importer · N`, jamais `· 0`), `ReconciliationView.test.tsx`/`ImportFbiDialog.test.tsx`/
    `ConfigurationPage.test.tsx`/`PlacementPanel.test.tsx`/`accordion.test.tsx` recalés ; e2e
    `tests/e2e/matches-importer.spec.ts` (onglet, badge = compte serveur, Configuration allégée,
    témoin : une rencontre créée à la main est déjà traitée, visible seulement sous « Afficher les
    traitées »).
- **Tests (backend, PR-3a)** : `FixtureReviewApiTest.php` (les deux routes de traitement) ;
  `FbiFixtureImporterTest` et `FfbbRencontresApiTest` étendus (D9, `autoApplied`, moteur partagé) ;
  `MatchTenantIsolationTest` étendu (NR tenant isolation, déjà bloquant — aucun changement
  `ci.yml`) ; `ManagementRoleTest` étendu (SEC-07 des deux nouvelles routes) ; Behat
  `une-rencontre-importee-dit-si-elle-est-traitee.feature` (`FixtureReviewContext`) — le parcours
  complet dépôt → traitement → re-dépôt → `VALIDATED` → déphasage → arbitrage. Le pas Gherkin dit
  désormais « la rencontre est « attestée FBI » » (`FixtureReviewContext::laRencontreEstAttesteeFbi`,
  toujours `status === VALIDATED`) — le drift cosmétique signalé lors de PR-3a (vocabulaire pré-D9
  laissé dans le pas) a été corrigé au passage par P4-187a.

## Configuration — repli visuel (P4-185, 2026-09-09) — LIVRÉ EN ENTIER

> Besoin : mesurée à 5 078 px de haut avant P4-186 (fondateur 2026-09-07) ; P4-186 (ci-dessus) a
> retiré le dépôt FBI/canal API/Engagements FFBB de la page, sans changer sa PRÉSENTATION — six
> cartes toujours dépliées en pile (dont 8 rotations et 12 lignes de durées ouvertes en dur).
> P4-185 règle la présentation : « une section = un écran ». **Zéro backend/engine, zéro
> comportement métier, zéro API** — pur repli visuel.

- **Les six sections deviennent des `AccordionSection` CONTRÔLÉES** (`ConfigurationPage.tsx`) : le
  gabarit A/B, Créneaux partagés (alternance), Échéances de saisie, Durée des matchs, Adversaires à
  localiser, Réglages de saison (Accès match + Habitudes & passerelles) — **une seule ouverte à la
  fois**, l'ouverture d'une autre referme la précédente (`sectionProps` pose `open`/`onToggle` par
  clé). Ancrée dans l'URL par `?section=<clé>` (`ConfigSection` = `gabarit|creneaux|echeances|
  durees|adversaires|reglages`, `decodeSectionParam`/`applySectionToParams` dans
  `features/matches/lib/urlState.ts`) : absent ou une valeur inconnue ⇒ `gabarit` ouvert par défaut
  (le param est alors ABSENT de l'URL — écrire le défaut ne pollue pas le lien) ; `section=aucune`
  ⇒ tout replié (encodage explicite : sans lui, replier le gabarit — param absent — le rouvrirait
  au décodage suivant). Mêmes conventions que `?vue=`/`?temps=` des autres onglets du module.
- **Un résumé discret dans le nom accessible du bouton** (`features/matches/lib/configSummaries.ts`,
  fonctions PURES, comptent ce que le backend a déjà calculé — zéro règle métier côté front) :
  « N rotation(s) » (`rotationsSummary`), « N sur M compétition(s) renseignée(s) » sur l'échéance
  **effective** (club ou proposée par la communauté, jamais recalculée — `Competition.
  effectiveEntryDeadline`) (`deadlinesSummary`), « défauts par catégorie » ou « N personnalisée(s) »
  — une catégorie est personnalisée dès qu'une des deux durées a une valeur propre (`durationsSummary`)
  —, « N à localiser sur M » (`opponentsSummary`). Une entrée `undefined` (chargement ou échec de
  lecture) rend `null` ⇒ **aucun compte affiché**, jamais un « 0 » fabriqué qui laisserait croire à
  un vide. Le résumé « Adversaires à localiser » réutilise la MÊME clé react-query que
  `OpponentTravelCard` (`useOpponentTravel`, appelée une fois au niveau page) — zéro requête
  supplémentaire.
- **Repli = démontage** (`AccordionSection` non gardée en vie fermée) : un brouillon local perdu au
  changement de section — cases cochées d'une échéance en cours d'édition, nouveau créneau partagé
  pas encore créé — assumé (décision, pas un manque). Une durée en cours de frappe est déjà
  sauvegardée au blur (`MatchDurationsEditor`), donc jamais perdue par un repli.
- **`MatchSlotRotationsEditor.tsx`** : chaque rotation devient une ligne-bouton compacte au lieu
  d'une carte toujours dépliée (détail § « Le SET-UP A/B et le signal — RMM-5 PR-4 » ci-dessus,
  recalé) ; mutations CRUD inchangées.
- **`MatchDurationsEditor.tsx`** : les catégories partageant le même défaut de famille SERVI
  (`defaultMatchMinutes`/`defaultWarmupMinutes`) forment désormais une seule `Table` partagée
  (`shared/components/ui/table.tsx`) au lieu d'une carte par catégorie — légende (`TableCaption`) =
  la phrase de défaut du groupe, colonnes Catégorie · Match · Échauffement · Défaut. Mêmes routes
  PUT (`useUpdateSportCategoryDuration`), même modèle éditeur non contrôlé re-semé par `key`.
- **Front** — tests : `ConfigurationPage.test.tsx` (une section ouverte à la fois, ancrage
  `?section=`, résumés d'en-tête), `MatchSlotRotationsEditor.test.tsx` (ligne compacte, dépliage
  unique), `MatchDurationsEditor.test.tsx` (tableau par groupe), `lib/urlState.test.ts`,
  `lib/configSummaries.test.ts` ; e2e `tests/e2e/matches-importer.spec.ts` recalé
  (`?section=reglages`, une seule section — le gabarit — ouverte à l'arrivée sans paramètre).

## Échéances ligue/comité — RMM-6 (3 PR, 2026-08-25) — LIVRÉ EN ENTIER

> Cadrage : [`../../docs/archive/refonte-module-matchs.md`](../../docs/archive/refonte-module-matchs.md) §9
> (RMM-6, P2-50) et le point d'insertion posé en RMM-1 ci-dessus (`FbiEntryList.tsx` L9) et en RMM-3
> (§ « Le gardien à l'ouverture », `MatchModuleDeltaComputer` tenu séparé pour cette lecture).
> **RMM-6 est LIVRÉ EN ENTIER** — PR-1 (backend) et PR-2 (front) posent le champ, le défaut
> communautaire et l'éditeur du SET-UP ; **PR-3 (front, ci-dessous) livre le rappel cockpit et
> l'escalade cockpit/login** — RMM-6 CLÔT P2-50, qui quitte la roadmap. Besoin d'origine : la
> ligue/le comité envoie plusieurs échéances CONCURRENTES par mail, par groupe d'équipes (région le
> 2 sept, département le 10, autres le 15…) — la granularité est la **compétition**, jamais une
> date unique de club.

- **Le champ, hors CRUD.** `Competition.entryDeadline` (`entry_deadline` DATE nullable) — écrit
  par le SEUL endpoint bulk ci-dessous, jamais par le CRUD `PUT /api/competitions/{id}` (même
  patron que les refs d'appariement FFBB). `POST /api/competitions/entry-deadlines`
  (`CompetitionEntryDeadlinesController`, management SEC-07) reçoit `{competitionIds[], deadline}`
  et pose (ou efface) **UNE** échéance sur un lot en une transaction : un id inconnu/étranger au
  club+saison → 422, **rien** écrit ; l'effacement est un geste **explicite**
  (`"deadline": null`) — une clé `deadline` absente rend 422 plutôt que d'essuyer les échéances du
  club en silence (revue sécurité 2026-08-25, F-3).
- **Le défaut communautaire — PREMIÈRE table partagée entre clubs du dépôt.**
  `App\Entity\SharedCompetitionDeadline` (table `shared_competition_deadline`) : keyée sur l'id
  FFBB de compétition (`ffbbCompetitionId`, UNIQUE), **aucune colonne club-identifiante** (pas de
  `club_id`, pas de `user_id`, pas de compteur — par conception, assertionné sur le catalogue
  Postgres par `EntryDeadlineShareTest`). Hors `TenantOwnedInterface`, donc hors RLS et hors filtre
  saison (patron `league_match_window` — un id FFBB est déjà scopé saison côté fédération) ; GRANT
  explicite `SELECT, INSERT, UPDATE` à `app_user` (**pas de DELETE** — aucun chemin de code
  n'efface une ligne partagée, revue sécurité 2026-08-25 F-1) posé par la migration
  `Version20260825140000`. Une compétition **appariée** (`ffbbCompetitionId` non null) et une date
  **posée** (jamais un effacement) upserte la ligne partagée — **dernière écriture gagne** : un
  second club écrasant la proposition ne touche PAS la valeur `entryDeadline` (souveraine) du
  premier, et effacer sa propre valeur club n'efface jamais le partagé. Deux compétitions du même
  lot bulk partageant le même id fédéral ne produisent qu'**un seul** upsert (revue sécurité F-4).
  ⚠ **Risque résiduel ASSUMÉ et opposable** (docblock de l'entité, F-2) : « apparié » repose sur
  `Club.ffbbClubCode`, un code fédéral PUBLIC auto-déclaré — un club pourrait revendiquer le code
  d'un autre pour s'apparier et écrire ce défaut. Borné par conception (la donnée est une DATE,
  toujours « proposée », toujours surchargeable, et les clubs légitimement appariés s'écrasent déjà
  entre eux) — **ne jamais enrichir cette table** (compteurs, provenance, texte libre) sans
  re-passer la revue.
- **La lecture — la règle « club gagne » servie par le backend, jamais recalculée au front.**
  `CompetitionResource` porte trois champs additifs en lecture : `entryDeadline` (la valeur club
  brute), `effectiveEntryDeadline` (club **??** défaut communautaire) et `deadlineSource`
  (`'club'`\|`'community'`\|`null`). Le join au partagé se fait par `ffbbCompetitionId` — une
  compétition non appariée n'a jamais de `deadlineSource: 'community'`. La réponse servie est
  **byte-identique** quel que soit le club auteur du défaut (zéro oracle sur « qui a posé la
  date »).
- **L'outlook J-7 — `GET /api/matches/deadline-outlook`** (`EntryDeadlineOutlookController` →
  `App\Service\EntryDeadlineOutlook`, **la maison unique** de la règle J-7,
  `REMINDER_WINDOW_DAYS = 7`). Lecture seule, **ouvert au Membre** (patron
  `MatchModuleVisitController`) : pour chaque échéance EFFECTIVE encore due (au moins un domicile
  `HOME` pas `SUBMITTED`/`VALIDATED` dans sa compétition), sert ses compétitions
  (`competitionNames`), le nombre de domiciles restant à saisir (`toEnterCount`) et si la fenêtre
  J-7 est ouverte (`withinWindow`, une échéance dépassée reste ouverte). Groupé par
  (date, source) — une échéance région et une échéance département distinctes restent deux
  fenêtres. Quand au moins une fenêtre est ouverte **et** que l'utilisateur a déjà une référence de
  visite (§ Le gardien à l'ouverture), le bloc `guardianDelta` est joint en réutilisant
  `MatchModuleDeltaComputer` **sans stamper** — c'est précisément pourquoi le calcul du delta a été
  tenu séparé de la rotation dès RMM-3 (note déjà posée ci-dessus, honorée ici). Aucune référence de
  visite → le bloc est simplement absent, jamais calculé.
- **L'éditeur — `EntryDeadlinesEditor.tsx`, une nouvelle carte du SET-UP `/matchs/configuration`.**
  Multi-sélection (case par ligne + « tout cocher/décocher ») + **une date** → `POST
  /api/competitions/entry-deadlines` en un seul lot (`useSetEntryDeadlines`, invalide la query
  `competitions`). Trois provenances distinguées **icône + texte, jamais la couleur seule** : CLUB
  (`CalendarCheck`, date pleine), PROPOSÉE (`Info` + « proposée » — le défaut communautaire
  pré-rempli, une info, pas une alarme), AUCUNE (« aucune échéance »). Une compétition **appariée**
  (`ffbbCompetitionId` non null) porte le badge « partagée avec les autres clubs » ; une non
  appariée ne le porte pas. L'effacement est un geste **explicite** (bouton « Effacer l'échéance »,
  jamais un champ vidé) — miroir front du refus 422 backend sur une clé `deadline` absente (§ PR-1
  ci-dessus). 🔴 Le front n'invente rien : `effectiveEntryDeadline`/`deadlineSource` sont
  **affichés**, jamais recalculés (`.claude/rules/frontend.md`, régime 1).
- **La vue de saisie — `FbiEntryList.tsx` L9, livré.** Chaque ligne de match porte, sous
  adversaire/salle, l'échéance **effective** de sa compétition (`deadlineDisplay`, lib pure
  `lib/deadlineLabel.ts` — formate une date déjà servie, ne redérive aucune règle) : à venir
  « avant le 10 sept. (J-3) », le jour même « (aujourd'hui) », dépassée « échéance dépassée
  (J+2) ». **Dépassée = un avertissement visuel (icône + ton warning), JAMAIS un blocage** — la
  case « Marquer saisi » reste cochable, falsifié par test. Un match **amical** (`competitionId`
  null) ou une compétition sans échéance servie n'affiche rien. Provenance proposée signalée
  (« · proposée »).
- **Back** — tests : `EntryDeadlineShareTest.php` (les huit volets ci-dessus, NR bloquant CLAUDE.md
  §4), `ManagementRoleTest.php` (le bulk rejoint la matrice management-only).
- **Front (PR-2)** — tests : `EntryDeadlinesEditor.test.tsx` (les trois provenances distinctes,
  badge d'appariement, multi-sélection → un seul POST avec exactement les ids cochés, effacement
  avec `deadline: null` explicite, erreur 422/409 lisible en `role=alert`, état vide, boutons
  inertes sans sélection), `lib/deadlineLabel.test.ts` (formatage pur : à venir/aujourd'hui/
  dépassée), `FbiEntryList.test.tsx` (échéance par ligne dans ses trois états, dépassée non
  bloquante — falsifié en cliquant « Marquer saisi » sous échéance dépassée —, amical et
  compétition sans échéance servie = rien affiché), `ConfigurationPage.test.tsx` (la carte
  « Échéances de saisie », son état vide sans compétition).

### La carte cockpit + l'escalade login — RMM-6 PR-3 (front, 2026-08-25)

- **La tuile `FbiDeadlineCard`** — première incursion des matchs sur le cockpit d'accueil (`/`, la
  route qui suit le login). Pleine largeur, insérée sous `SeasonPlanBanner` et au-dessus de la
  grille du cockpit (`CockpitPage.tsx`) — jamais un remplacement des zones existantes (§ « Accueil
  cockpit temporel » reste la maison du bandeau/calendrier/radar, inchangé par cette carte).
  Consomme le nouveau hook `useDeadlineOutlook` (`GET /api/matches/deadline-outlook`, staleTime
  30 s, ouvert au Membre — même endpoint que le SET-UP).
- **Muette par défaut.** La carte ne rend **rien** (`null`) tant qu'aucune fenêtre outlook ne porte
  `withinWindow: true` — zéro encombrement du cockpit hors période d'échéance, falsifié
  (`FbiDeadlineCard.test.tsx`). Une fenêtre ouverte affiche « N match(s) à saisir avant le
  [date] » + les noms de compétition, « · proposée » si la fenêtre vient du défaut communautaire
  (`source: "community"`).
- **Dépassée = un avertissement qui RESTE, jamais destructif.** Ton `accent` par défaut, escaladé
  en `warning` (icône + bordure) dès qu'au moins une fenêtre affichée est dépassée
  (`daysUntilDeadline < 0`, même lib pure `deadlineLabel.ts` que `FbiEntryList`) — la carte continue
  d'afficher le compte de matchs non saisis, elle ne disparaît ni ne bloque rien.
- **Le résumé du gardien fusionne DANS la carte.** Quand l'outlook joint `guardianDelta` (§ « Le
  gardien à l'ouverture » — fenêtre ouverte ET référence de visite existante), la carte ajoute une
  note subordonnée « Depuis votre dernière visite : … » sous un filet, **jamais un second bloc
  concurrent** — honore la décision fondateur (« c'est dès le login car le placement est une
  urgence »). Absent → aucun trou visuel, rien n'est calculé côté front.
- **`visitDeltaSegments` — la formulation partagée, extraite.** Les trois segments non nuls du
  delta de visite (matchs arrivés · nouveaux conflits · planning de saison changé, dans cet ordre)
  vivent désormais dans `features/matches/lib/visitDeltaSegments.ts` (fonction pure, sans le voile
  `firstVisit` propre au bandeau). `moduleVisitSummary` (déplacée de `ModuleVisitBanner.tsx` vers
  `features/matches/lib/moduleVisitSummary.ts` — FRT-29, résorption d'un warning HMR) et
  `FbiDeadlineCard` la consomment TOUS LES DEUX — une seule maison pour la tournure française et
  les singuliers/pluriels, comportement du bandeau **inchangé** (tests intacts).
- **`RadarPanel` intouché** — le radar cockpit (vacances, indispos, plannings à régénérer) n'est
  pas concerné par ce lot ; les deux to-do (RadarPanel, matchs) restent deux surfaces distinctes.
- Tests : `FbiDeadlineCard.test.tsx` (mute hors fenêtre/data non résolue/liste vide, contenu en
  fenêtre, provenance communautaire, dépassée reste affichée en ton warning, guardianDelta joint ou
  absent), `visitDeltaSegments.test.ts` (les trois segments, singulier/pluriel) ; `CockpitPage.test.tsx`
  mocke `useDeadlineOutlook` en `undefined` (carte muette) pour garder ses propres scénarios
  intacts — la couverture de la carte elle-même vit dans son propre test dédié.

## Reste palier A (à venir)

**Joueurs** dans le moteur de conflits : décision fermée — pas d'entité joueur, la passerelle déclarée
(`TeamLink`, PR C) couvre le besoin. Paliers B (dérogation + trajet + annuaire adverse global) / C (effet
réseau) plus tard. ⚠ Envelope strictement HARD & fiable côté UI = clé de jointure normalisée
équipe↔fenêtre (dette (iv), PR E — côté solveur la jointure tolérante `LeagueEnvelopeResolver` est
livrée). *(`Team.preferredMatchWindow` → devenue `TeamMatchHabit` (P3-1 soldée en PR C) ; format FBI
validé sur vrai export + diff de ré-import → livrés en PR A.)*
