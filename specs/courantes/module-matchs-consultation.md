# Module matchs (FFBB) — écrans Calendrier & Conflits

> Les deux écrans de consultation/traitement : le Calendrier (`/matchs`, place/échange/
> verrouille/saisit dans FBI) et Conflits (`/matchs/conflits`, lecture seule pivotée). Découpé
> mécaniquement de [`module-matchs.md`](module-matchs.md) (anciens §5-§6) — contenu déplacé
> VERBATIM, non réédité, plafond `DocSizeCeilingTest`.

## 5. Écran Calendrier (`/matchs`, route index)

L'écran unique du module : place/échange/verrouille/saisit dans FBI ET lit Semaine·Mois·Phase.
Importer garde sa maison propre (§6).

**Décision fondateur (2026-10-04) : tout le monde VOIT, seul le gestionnaire AGIT.** Tous les
boutons d'action de l'écran des matchs (placement automatique, édition/suppression d'un extérieur,
panneau de placement, validation en lot « Validé ligue ») sont masqués pour un non-gestionnaire —
un membre lit la grille, les todo, les filtres, sans aucun geste disponible ; l'état « en vide »
reste visible même sans bouton. `canManage`/`isManager` propagé en prop (`WeekWorkbench.tsx`,
`FbiEntryList.tsx`, `PhaseTable.tsx`), le backend reste seul juge (403 sur chaque écriture,
le masquage front n'est qu'un confort).

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
  gestionnaire, create ET update d'un domicile) refuse en 422 (0, P4-300) toute rencontre — amical
  compris — posée dans un gymnase couvert par une FERMETURE du calendrier (`venue_closed`) à sa
  date (« X est fermée du … au … — titre », même maison `PlanVenueClosures::closureIntervals`
  que le payload de placement et le radar) ; (1) toute rencontre — amical compris
  — posée dans un gymnase couvert par une `VenueUnavailability` à sa date ; (2) pour une rencontre
  de COMPÉTITION seulement, quand le club déclare ≥ 1 `VenueMatchWindow` : aucune fenêtre `(gymnase,
  jour)` ou coup d'envoi hors fenêtre (même prédicat que le diagnostic,
  `MatchConflictDetector::kickoffInsideWindow` — une seule maison). Jamais de refus sur l'enveloppe
  ligue (radar seul, décision D2, PERMISE côté client aussi depuis P4-272 ① — voir ci-dessous) ; un
  amical reste libre hors fenêtre d'accès. `/api/fixtures/place`
  (rail solveur, §3 — le solveur pose déjà les mêmes fenêtres/indispos en HARD) et la
  réconciliation FBI n'empruntent **pas** ce processor : intacts, hors du geste manuel gestionnaire
  que D2 vise. `PlacementPanel` lit QUATRE gardes du club (accès match, indisponibilités,
  fermetures du calendrier — P4-300 — et enveloppe ligue) via `readState` ; le GESTE de placement
  est SUSPENDU
  (`LoadErrorHint` + retry en échec, spinner en chargement) tant qu'elles ne sont pas `ready` — un
  échec de première lecture ne se lit jamais « aucune restriction ». Les mutations de placement/
  édition restituent le message 422 du serveur dans le toast (`errorMessage`) au lieu d'un
  générique. **Sélecteur de gymnase grisé (P4-300)** : tant que les gardes sont `ready`, l'option
  d'un gymnase FERMÉ (calendrier) ou INDISPONIBLE (déclaré) à la date du match est grisée et
  inerte, avec une sous-ligne nommant la cause et les bornes — miroir du refus serveur, jamais de
  grisage sur une lecture en cours ou en échec (`PlacementPanel::blockedSubFor`).
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

### Export des matchs pour les parents (bouton « Exporter » du Calendrier)

Le bouton « Exporter » de la barre d'actions du Calendrier (`CalendarPage.tsx`) ouvre
`ExportMatchesDialog` : **tout le club** ou **une équipe**, **du … au …** (un seul jour = deux
dates égales), puis « Télécharger le PDF » (`POST /api/fixtures/export-pdf`, rail synchrone,
`ExportMatchesPdfController`). **Tout membre exporte**, pas seulement le gestionnaire (parité avec
l'export du planning) ; l'export consomme un crédit selon les règles existantes (Découverte :
mention dans la fenêtre, bouton désactivé à zéro ; le crédit ne se décompte qu'au 200, donc jamais
sans fichier livré — `CreditBudgetSubscriber`) et passe par un limiteur dédié `fixtures_pdf_export`
(60 par heure et par club, distinct du limiteur de l'export du planning). Pré-remplissage de la
fenêtre : `lib/exportRange.ts` (ce qui est à l'écran — semaine, mois, phase —, sinon le mois en cours).

Le document est un PDF **portrait** (`MatchesPdfGenerator` → worker PDF partagé, fichier supprimé
dès lu) : en-tête (logo, nom du club, plage, date d'édition), **une partie par équipe** (tout le
club : les équipes ayant au moins un match dans la saison ; une équipe sans match dans la plage
imprime « Aucun match sur cette période. »), colonnes Date · Heure · Adversaire · Où · Lieu, sans
statut, conflit ni coach. Ce qui s'imprime est décidé par `MatchesExportDataProvider` :

- **Heure « à confirmer »** : heure absente, ou écart fédéral ouvert, ou domicile de championnat
  non validé (un amical, un extérieur et un terrain neutre n'ont rien à faire valider par le club).
  Un match **déjà joué** n'est jamais « à confirmer » (le passé prime), sauf sans heure ; un
  match joué dont l'écart reste ouvert s'imprime sans mention. À l'extérieur, l'heure s'imprime
  sans mention.
- **Lieu** : domicile = gymnase du match (nom — adresse) ; extérieur = adresse du gymnase
  **apparié** dans Adversaires seulement. Un lieu non certain (gymnase supposé, ville seule,
  domicile sans gymnase, terrain neutre) est masqué (« — ») : le renseigner est la responsabilité du
  gestionnaire.
- **Trajet** : à l'extérieur seulement, après l'adresse, avec une légende d'en-tête unique si au
  moins un trajet est imprimé ; un trajet non calculé n'apparaît pas.
- Crédit de marque en pied de page (même pied que l'export du planning).

Limites : `502` sans crédit si le rendu échoue ; les fichiers `matchs-*.pdf` orphelins sont
purgés au bout d'une heure (`PurgeExportsCommand`). Le format image (Instagram, Facebook) reste à
faire (roadmap).

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
- **Chips familles** (`ConflictType`, backend et frontend ALIGNÉS — la famille
  `TEAM_LINK_OVERLAP`/« Passerelle » n'est plus dans le contrat public ni dans le frontend
  (`conflictLabels.ts`/`api.ts`) ; un ancien lien profond qui la citerait est ignoré proprement,
  comme une famille fictive. Toutes cochées par défaut, compteur SAISON, à
  traiter seulement — une famille 100 % traitée garde sa chip, « · 0 » en sourdine.
- **Puces « Traitement »** : 4 puces HISTORIQUES toujours rendues même à zéro (À traiter ·
  Dérogation demandée · Réglé en interne · Sans solution pour l'instant), plus jusqu'à 3 puces
  CONDITIONNELLES propres à une famille (Importer les matchs manquants ·
  Erreur FBI · Match à déplacer), rendues seulement si au moins un conflit les porte (même patron
  que les chips familles, `treatmentChipKeys`). Plus le filtre domicile — `SegmentedControl` deux
  segments « Avec un match à domicile » / « Tous », compteurs via `hasHomeSide` (retours Nico BCCL
  2026-10-27, M4, remplace le `FilterToggle` « Seulement avec un match à domicile ») — **coché sur
  « domicile » par défaut** (`conflictsHomeOnly: true`, `store.ts`) : décision RENVERSÉE (c'est
  désormais « où le gestionnaire peut agir », pas un critère descriptif passif — « même moi je
  l'ai oubliée », fondateur). Ordre d'application : familles → traitement → domicile → pivot. Le
  radar de la vue Semaine (`ConflictRadar`, §5) partage le MÊME réglage de session
  (`conflictsHomeOnly`) via le même `SegmentedControl`.
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

