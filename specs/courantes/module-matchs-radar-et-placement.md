# Module matchs (FFBB) — détecteur de conflits & solveur de placement

> Le radar de conflits (`MatchConflictDetector`), le solveur de placement (ADR-0003) et le
> gardien à l'ouverture (delta de visite + échéances de saisie). Découpé mécaniquement de
> [`module-matchs.md`](module-matchs.md) (anciens §2-§4) — contenu déplacé VERBATIM, non
> réédité, plafond `DocSizeCeilingTest`.

## 2. Détecteur de conflits (`MatchConflictDetector`, service pur)

Façade unique (`detect()`, point d'entrée appelé par `ConflictRadarLoader` ; les 4 prédicats
statiques `kickoffInsideWindow`/`kickoffInsideLeagueWindow`/`kickoffSatisfiesClubRule`/
`dateInsideClosure` y restent publics). Depuis BCK-19 partie 2, le corps est découpé VERBATIM,
iso-comportement, par famille dans `backend/src/Service/Conflicts/` : `VenueConflicts` (gymnase —
chevauchements, indisponibilités/fermetures, gymnase interdit, fenêtre d'accès perdue),
`RuleWindowConflicts` (fenêtres ligue, règles club), `PersonConflicts` (personnes — match×match,
match×entraînement, sévérités), `ConflictMoments` (helpers purs partagés — chevauchement,
bornes min/max, `fixtureView`). Aucun comportement ni signature publique de `detect()` n'a changé.

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

**Trois familles** :
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
- `MATCH_COACH_UNAVAILABLE` (chantier H, relais de l'adjoint) — le coup d'envoi d'un match tombe
  dans l'indisponibilité DÉCLARÉE d'un coach PRINCIPAL de l'équipe (`MatchConstraint` scope COACH) ;
  l'indisponibilité d'un coach SEULEMENT adjoint ne lève JAMAIS cette famille — le principal reste
  l'entité surveillée. Sévérité (`PersonConflicts::coachUnavailableConflicts`) : coup d'envoi DANS
  l'indispo → 3, à domicile comme à l'extérieur ; indispo qui ne touche que la DURÉE du
  match (coup d'envoi libre) → 5 à domicile, 3 à l'extérieur (le coach doit être sur
  place à l'heure). Une entrée par (coach, rencontre), la plus grave retenue. ⚠ 3/4/5 partagent le
  MÊME ton visuel `warning` (`toneOf`, `lib/diagnostic.ts` — seuls 1-2 sont `destructive` et 6-7
  `muted`) : ce ne sont PAS trois couleurs distinctes, la gradation se lit au NUMÉRO de sévérité et,
  pour le relief, au tag dédié ci-dessous — jamais à la teinte.

**Adoucissement « relief » (chantier H, les trois familles ci-dessus)** : un conflit DUR (sévérité
3) où la personne est MAIN sur au moins un côté retombe d'un cran (4 ou 5 selon la famille) si un
AUTRE coach de la même équipe — adjoint OU autre principal — est DISPONIBLE au même coup d'envoi
pour tenir ce côté (`PersonConflicts::reliefAvailable`/`coverAvailable` ; un joueur ne couvre jamais
un coach, un côté JOUEUR n'est jamais adouci). `MATCH_COACH_UNAVAILABLE` retombe de 3 à 4 (jamais à
5, déjà la sévérité la plus clémente de la famille) ; `MATCH_MATCH`/`MATCH_TRAINING` retombent de 3
à 5. Le relief est porté par le champ additif `relief` (bool) — jamais déduit du seul changement de
sévérité (3-5 partagent le même ton), l'écran affiche un TAG dédié (icône + texte « Un adjoint peut
couvrir », `ConflictLine.tsx`) en plus du ton, jamais
la couleur seule.

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

**Cohérence de complétude d'une compétition APPARIÉE** (décision fondateur 2026-10-01 : FBI — les
matchs importés — fait FOI, l'appariement FFBB ne fait que VÉRIFIER ; la FFBB n'expose pas le
format aller simple/aller-retour, aucun nombre attendu n'est deviné en amont). Une phase sort
TOUJOURS en entier : le nombre `n` de rencontres rattachées à la compétition doit valoir `adv`
(aller simple) ou `2×adv` (aller-retour), où `adv` = adversaires RÉELS de la poule (taille de poule
− 1 pour le club − exempts, un exempt n'étant jamais un adversaire). Pas d'appariement
(`expectedMatchdays` null, coupe comprise) ou `n ∈ {0, adv, 2×adv}` ⇒ silence, rien à signaler.
`n > 2×adv` ⇒ ALERTE `OVER` (appariement ou rattachement à vérifier). `0 < n < 2×adv` avec `n ≠ adv`
⇒ avant l'échéance de saisie effective de la poule (ou en son absence) : INFO discrète `PENDING`
(« la phase n'est pas encore entièrement sortie ») ; après l'échéance : ALERTE `INCOHERENT`. Servi
par `MatchConflictDetector::competitionIncompleteItems` (type `COMPETITION_INCOMPLETE`, champ
`reason` OVER/INCOHERENT/PENDING, `expected` = la cible aller-retour `2×adv`) ;
`ConflictRadarLoader` joint l'échéance effective de chaque compétition (club gagne, sinon le défaut
communautaire — § « Échéances de saisie ligue/comité » ci-dessous).

**Équipe sans niveau** (`TEAM_LEVEL_MISSING`, P4-288, décision fondateur 2026-10-16) : une équipe
ABSENTE de la table niveau (`levelByTeam`) ayant ≥ 1 rencontre RETENUE (même filtre « matchs
actifs », non jouée, que les autres familles) lève une info UNIQUE — **une entrée par ÉQUIPE,
jamais par rencontre** (`MatchConflictDetector::teamLevelMissingItems`), jeune OU senior
(`Team.level` nullable pour toutes). Sans niveau, `LeagueEnvelopeResolver::matches` ne rattache
aucun créneau de ligue : ses matchs sont posés sans fenêtre de division, aussi bien au radar qu'au
solveur de placement (§3, diagnostic `league_envelope_unresolved` déjà existant côté engine). Une
équipe qui A un niveau ne la lève jamais, fenêtre de ligue déclarée ou pas. Empreinte
`TEAM_LEVEL_MISSING:{teamId}` (`ConflictFingerprinter`).

**Échelle de sévérité (1..7, émise par le serveur)** : 1 `VENUE_OVERLAP` · 2
`LEAGUE_WINDOW_VIOLATION` (équipe mappée seulement) · 3 clash dur `MATCH_MATCH`/`MATCH_TRAINING` +
`CLUB_RULE_VIOLATION` (P4-272 ③ — un domicile placé dont le coup d'envoi viole une règle CLUB
**HARD** couvrant son jour ; une règle PREFERRED ne fait jamais de violation, seulement un nudge
côté solveur — champ additif `rules`, les règles violées ; amicaux exemptés comme l'enveloppe
ligue) + `TEAM_VENUE_FORBIDDEN` (P4-272 ④, même gravité — décision fondateur : un domicile placé
dans un gymnase INTERDIT à son équipe, scope TEAM HARD, kickoff-indépendant — c'est le gymnase qui
viole, pas l'heure ; champ additif `venueId` ; amicaux exemptés de même) + `MATCH_COACH_UNAVAILABLE`
(chantier H — coup d'envoi DANS l'indisponibilité déclarée d'un coach PRINCIPAL) · 4
`VENUE_UNAVAILABLE` + `ACCESS_WINDOW_LOST` (« Hors accès match » — champ additif `windows`, les
accès du gymnase de la fixture triés jour du match d'abord, hors identité de l'empreinte
`TYPE:fixtureId` ; l'écran nomme l'ÉQUIPE, la DATE (avec son jour de semaine) et le gymnase, ses
fenêtres ou « aucun accès match ce jour-là » sans aucune — retours Nico BCCL 2026-10-27, M6 + M11 :
avant, seuls le gymnase et les fenêtres étaient nommés — et porte un bouton **« Configurer les
accès »** vers `/matchs/configuration?acces=<venueId>` (deep-link d'état dans l'URL, `ConfigurationPage`
ouvre directement l'éditeur d'accès de ce gymnase, § `module-matchs-reglages.md`)) +
`MATCH_COACH_UNAVAILABLE` ADOUCI (chantier H — un adjoint ou un autre principal
disponible peut couvrir, tag dédié « Un adjoint peut couvrir », § ci-dessus) · 5 clash adouci
(`MATCH_MATCH`/`MATCH_TRAINING` avec relief, § ci-dessus) + `MATCH_COACH_UNAVAILABLE` quand
l'indisponibilité ne touche que la DURÉE du match à DOMICILE (coup d'envoi libre, § ci-dessus) +
`FRIENDLY_ON_MATCH_SLOT` (amical HOME placé sur un créneau de match — `reasons`:
`MATCH_SLOT_WINDOW`/`MATCH_WEEKEND`, samedi = clé du week-end, le vendredi ne compte jamais) · 6
`COMPETITION_INCOMPLETE` reason `OVER`/`INCOHERENT` (alerte, § « Cohérence de complétude »
ci-dessus) · 7 `COMPETITION_INCOMPLETE` reason `PENDING` (info, repliée) + `AWAY_NO_FOOTPRINT`
(angle mort nommé : extérieur sans heure ni habitude du bon jour — JAMAIS émis pour une équipe
LOISIR_ADULTE/LOISIR_JEUNE, qui n'a ni calendrier ligue ni habitude à déclarer) + `TEAM_LEVEL_MISSING`
(§ « Équipe sans niveau » ci-dessus, info repliée, ajoutée EN FIN de liste pour ne jamais changer
l'item en tête des familles plus graves). Réponse : bornes datées en heure MURALE du club (jamais un
offset).

**Détail par côté** : `ConflictMoments::fixtureView` sert quatre champs additifs par côté
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
même `CONTRACT_VERSION` **1.7** que `/generate`/`/validate-assignments` (un seul contrat pour les
trois endpoints — voir §6 `CLAUDE.md`). **Rail ASYNCHRONE** (amendement ADR-0003 2026-10-04, patron
exact de la génération) : `PlaceMatchesController` garde toutes ses gardes (management + saison
écrivable + socle pointé), prend le verrou, ENFILE un `PlaceMatchesMessage` et répond **202**
`{runId, status}` ; `PlaceMatchesHandler` (transport `async` partagé avec la génération) solve,
applique, décompte le crédit Découverte au SUCCÈS et publie la bascule terminale (COMPLETED/FAILED)
sur le 3ᵉ topic Mercure fixe `/clubs/{clubId}/placement`. `GET /api/fixtures/placement-run` rend le
dernier run du club+saison (lecture seule, ouvert à tout membre). Anti-double-clic PAR CLUB
`MatchPlacementLock` (Redis dédié — pris par le contrôleur, relâché par le worker ; ne protège pas
deux clubs l'un de l'autre : ils partagent le sémaphore GLOBAL `max_concurrent_placements=1` de
l'engine, détail ADR-0003 §2). Best-effort à poids
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
`_candidate_kickoffs`, `engine/app/solver/match_placement/candidates.py`. Les deux dernières raisons se
distinguent post-solve sur l'occupation finale : `venue_full` = plus aucun créneau licite libre à
sa date (gymnase saturé) ; `not_selected` = un créneau licite restait libre mais le solve ne l'a
pas retenu dans son budget — « relancez le placement » (ADR-0003 §3).

**Budget PAR SEMAINE ISO, pas de bout en bout** (amendement 2026-10-04, ENG-50) : l'engine découpe le
lot en semaines ISO distinctes et solve chacune isolément, puis concatène (placements/raisons/
diagnostics en ordre stable, métriques sommées). `solverTimeoutSeconds` du payload
(`MatchPlacementPayloadBuilder::WEEK_BUDGET_SECONDS = 35`) est désormais le budget **de chaque
semaine** — un lot de N semaines consomme jusqu'à `N × 35 s` de solve. Chaîne de timeouts dimensionnée
sur ce total : TTL `MatchPlacementLock` = `N × (35 + 15) s + 60 s` de marge
(`PlaceMatchesMessage::budgetSecondsFor`, `15 s` = marge par semaine côté engine, `60 s` = marge de
queue/dispatch) — pris par le contrôleur à l'enfilage, relâché par le worker en fin de run ; le run
tourne sur le rail Messenger (`messenger-worker`), plus aucun timeout HTTP/nginx/PHP/`ky` côté
navigateur ne l'encadre (le gestionnaire peut quitter l'écran, §5 ci-dessous). Le solve lui-même vit
dans un **processus fils jetable** côté engine (ENG-49) : sa mémoire native est rendue au système à
chaque placement, pas seulement en fin de requête. Avant le solve de chaque semaine, un **warm-start
glouton** déterministe (matchs triés date/équipe, candidat préféré = créneau idéal, sinon placement
SOLVER courant, sinon premier créneau licite libre) pose un seul jeu de hints CP-SAT — il absorbe
l'ancien hint de stabilité, jamais deux hints contradictoires sur un même match (ADR-0003 §4).

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
(`roundTripMinutes` n'est plus transporté depuis la version 1.3 — le radar le
recalcule depuis l'entité, hors payload). « C'est la vie » (fondateur) :
le solveur ne peut de toute façon pas déplacer un match extérieur (l'heure est imposée par
l'adversaire) ; le RADAR (§2) reste la seule source qui signale une indisponibilité réelle liée à un
extérieur, le gestionnaire arbitre après coup. Un AWAY reste émis au contrat : il libère la
protection d'habitude de son équipe ce jour-là (`team_dates`). Conséquence : la fenêtre
personne du solveur ne provient plus JAMAIS d'un trajet ni d'un échauffement — elle vaut toujours la
fenêtre salle `[kickoff, kickoff+matchMinutes]` (lot M + décision B).

**SOFT (golden-épinglés)** : conflit personne (coach MAIN ou joueuse active) −60 · indisponibilité
de coach violée −60 (`W_COACH_UNAVAILABLE=60`, P4-272 ⑤, `match_placement/weights.py` — MÊME poids qu'un
conflit personne ; le coup d'envoi du candidat retenu tombe dans une plage d'un coach de l'équipe,
sur un jour couvert par cette plage ; jamais d'élagage de domaine, jamais de raison `unplaced` — le
placement l'évite quand une alternative existe, sans jamais rendre un match impossible) — **rôle-aware
depuis le chantier H** : ce poids ne s'applique qu'à un coach PRINCIPAL indisponible, un ADJOINT
indisponible ne pèse que `W_COACH_ASSISTANT=10` (`(role, unav)` porté par `team_coach_unavailabilities`,
`engine/app/solver/match_placement/__init__.py`) ; le radar (§2, `MATCH_COACH_UNAVAILABLE`) lit
désormais la même donnée (indisponibilités COACH) pour SIGNALER au gestionnaire, le solveur de
placement continue de simplement pénaliser sans jamais bloquer · coach ASSISTANT −10 · passerelle `NOT_SIMULTANEOUS` violée −40 (⚠ **asymétrie
délibérée** : le radar §2 ne signale jamais cette famille, le solveur GARDE cette préférence
souple — sens sûr, une pénalité SOFT ne bloque jamais rien, à ne pas « aligner » en la retirant) ·
règle de match CLUB PREFERRED violée −30 (`W_CLUB_RULE=30`, P4-272 ③, `match_placement/weights.py` —
arbitrage fondateur : plus qu'une habitude +15+5, moins qu'une passerelle `NOT_SIMULTANEOUS` −40 ou
qu'un conflit coach −60 ; une règle HARD n'entre jamais dans l'objectif, elle élague le domaine, §3
ci-dessus) · habitude heure +15/gymnase +5 · fenêtre
habituelle protégée −25 (`W_PROTECT_HABIT=25`, `match_placement/weights.py`) · `BACK_TO_BACK` enchaîné +15 ·
stabilité re-solve +8 · compactage −1/15 min de trou. **La protection ne s'applique JAMAIS au
créneau idéal PROPRE de l'équipe candidate** (`is_own_ideal`, P4-271) — sans cette exception, le
bonus +15+5 d'une équipe perdrait toujours face à la protection −25 dès qu'une AUTRE équipe déclare
son créneau idéal sur le même gymnase+jour+heure (l'ex-alternance A/B). Deux créneaux idéaux qui
coïncident physiquement (même gymnase+jour+heure) protègent la MÊME fenêtre sur une date sans
membre — dédupliquée par `(gymnase, date)` pour qu'un troisième candidat chevauchant ne soit jamais
pénalisé deux fois (`_place_matches`, `match_placement/__init__.py`).

**Ancres — `Fixture.placementSource`** : geste manuel API → `MANUAL` ; `MANUAL` + `SUBMITTED`/
`VALIDATED` = **FIXED**, ne bouge jamais ; `SOLVER` = re-plaçable. Un amical n'est **jamais**
proposé au solveur (« un amical n'est pas sur un créneau de match ») : placé+ancré → FIXED (gymnase
protégé), sinon simplement absent du payload — le contrat n'a pas bougé pour cette raison, la place
laissée libre est couverte par `FRIENDLY_ON_MATCH_SLOT` (§2), pas par une contrainte moteur.

Le backend PROJETTE (occupations d'entraînement datées, heure extérieure estimée, enveloppe ligue
résolue serveur), l'engine reste plat. UI (rail asynchrone, amendement 2026-10-04) : bouton « Placer
automatiquement » sur le Calendrier — geste réservé au gestionnaire, désactivé pendant un run ouvert,
désactivé avec une explication en offre Découverte ; bouton dédié « Placer ce week-end » dans
l'établi Semaine (P4-240 ④, §5), même rail (`runPlacement`, maison unique), fenêtre posée sur la
semaine lundi→dimanche affichée. L'**état** « en cours » est un bandeau visible de TOUS les membres
(un autre onglet, un autre membre voit aussi « en cours ») — relu au GET `/api/fixtures/placement-run`
et rafraîchi par la bascule Mercure (`/clubs/{clubId}/placement`) ; à 2 min, le bandeau invite à quitter
la page (« vous recevrez un e-mail à la fin ») puisque le run tourne sur le worker Messenger, pas sur
la requête du navigateur. Fin de run : toast « N placés · M non plaçables » (raisons par match) + le
GET relu derrière l'invalidation.

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
faire, dont M à corriger ») ; rien à faire, aucune fenêtre ET rien à confirmer « déjà dans FBI » →
muette. Le bouton n'ouvre « Ouvrir la liste FBI » (`/matchs?fbi=1`) **que si** cette liste a du
contenu (`fbiTodo.toEnter + fbiTodo.toCorrect > 0`) ; sinon (tout reste à placer) il renvoie au
Calendrier (`/matchs`, « Placer les matchs ») — la liste FBI serait sinon une modale vide. Quand
`toConfirmCount > 0` ET l'utilisateur est gestionnaire, la carte ajoute une ligne dédiée « N matchs
déjà dans FBI à confirmer » (plus « N à confirmer « validé ligue » », retours Nico BCCL 2026-10-27
M3) + un bouton qui ouvre la confirmation chiffrée partagée (§ « Validé ligue » en lot) — jamais
affichée à un Membre.

