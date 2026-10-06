# Frontend — Work-loop du planning

> La boucle de travail sur un planning : retouche manuelle (mode cible, éviction, dérive, verrou — rail read-only + verdict moteur) et transcription depuis le socle (bouton, panneau « à replacer », comparaison). Découpé mécaniquement de `frontend-spec.md` (DOC-59).

Last verified @ 2026-10-06 (découpage thématique DOC-59 — contenu déplacé TEL QUEL depuis `frontend-spec.md`, sans réécriture de fond ; la fraîcheur du contenu est celle de la passe du même jour sur `frontend-spec.md`, l'historique de vérification vit dans `git log -p --follow` ce fichier).

### 6.7 Retouche manuelle — mode cible, éviction, dérive, verrouiller (rail read-only + verdict moteur, 2026-08-16)

`schedule_slot_templates` est **read-only côté API** (`GetCollection`/`Get` seulement — POST/PUT/
DELETE et leur processor/DTO d'entrée ont disparu). Toute écriture passe par un rail dédié, jamais
par un CRUD brut sur la ressource :

- **Déplacer — mode cible click-click (P2-30 PR B, 2026-08-16, lot SOLDÉ)** : « Déplacer » sur le
  panneau `SlotDetail` (`onArmMove`) **ARME** le mode cible au lieu d'ouvrir un formulaire — **la
  décision fondateur D11 supprime le formulaire jour/heure/gymnase** (jamais utilisé, la grille
  EST l'éditeur). Armée, la grille (`WeekGrid`) marque la SOURCE (anneau + pulsation), transforme
  chaque case **vide** en un **vrai bouton focusable** « Placer ici — \<gymnase\>, \<jour\>
  \<début\>–\<fin\> » (`aria-label`), et rend chaque carte **occupée** cliquable comme cible ; un
  créneau **verrouillé** reste une cible refusée (tooltip « déverrouillez-le d'abord »), sauf la
  source elle-même. **Échap** ou un re-clic sur la source sort du mode sans rien toucher (le focus
  y revient). Le clic sur une case est routé par `WeekGrid.onPickTarget` — la PAGE décide (annuler,
  déplacer, évincer, placer) : la grille ne fait que router.
  - **Case d'un gymnase FERMÉ (P2-43 volet v, 2026-08-19)** : une fenêtre (gymnase, jour)
    effectivement fermée sur la version de PÉRIODE affichée est **marquée** « Fermé — … » (inerte,
    jamais un vrai bouton) au lieu d'être offerte comme cible — l'ancien régime affichait 100 % des
    créneaux vides d'un gymnase fermé comme boutons « Placer ici » que le serveur refusait ensuite
    (`slot_unavailable`), un aller-retour moteur perdu par clic. L'état vient de
    `useEntryConflicts` (jamais recomposé côté front — `computeClosedWindows`,
    `frontend/src/features/planning/lib/closedWindows.ts`) : l'OFFRE (armement du mode cible, filtre
    de `onPickTarget`) est **fail-closed**, l'AFFICHAGE reste **fail-open** (rien de masqué tant que
    l'état n'est pas résolu — doctrine « on annonce, on ne cache pas »). `entryId` vient en prop
    depuis `GenerateStep` en embarqué, sinon dérivé du plan de la version affichée (jamais le
    socle).
  - **L'armement suit son ancre, pas l'écran entier (P4-119 d, 2026-08-19)** : un DÉPLACEMENT tombe
    dès que le panneau du créneau SOURCE se ferme, qu'on change de vue ou de version affichée
    (`selectedSlotId` quitte la source) ; un PLACEMENT porte le contexte (version + vue) où il fut
    armé et tombe si l'un change ou si son équipe cesse de dériver. Avant ce correctif, l'armement
    survivait à la fermeture de son panneau — chaque clic suivant sur un créneau devenait une
    nouvelle tentative de déplacement non voulue. Échap reste préservé.
  - **Priorités visuelles** : surlignage **conflit** > **mode cible** > **lentille verrous** — la
    lentille se tait tant qu'un conflit règne OU que le mode cible est armé (elle ne doit jamais
    brouiller ni le rouge du conflit, ni la cible en cours de choix).
  - ⚠ **Le signal du surlignage n'est PAS une bordure posée sur la case ciblée** : `WeekGrid`
    ESTOMPE (`grayscale`) toutes les AUTRES cases de la grille pendant qu'un surlignage est actif —
    la ou les cases visées sont simplement les SEULES qui gardent leurs couleurs. Une case **VIDE**
    ciblée gagne en plus un anneau ambre (`border-warning ring-2 ring-warning`) ; une case
    **OCCUPÉE**, elle, n'en gagne AUCUN (elle serait confondue avec un anneau de sélection, de
    lentille verrous ou d'écart au socle) — c'est l'estompe des autres qui la désigne, constaté en
    posant le filet de `PlanningPage.test.tsx` avant P4-255 PR 2 (`WeekGrid.tsx`, variables locales
    `dimmed`/`flagged`).
  - **Case libre** : `POST /api/schedule-slots/{id}/move` (`useMoveSlot`, sans `evictSlotId`).
    **Pas d'optimistic update** — la grille attend le verdict du moteur (`MoveFeedback` : `pending`
    pendant l'appel, ~500 ms).
    - **Accepté (200)** : `slots`/`schedules`/`diagnostics` sont invalidés (le moteur a rejugé la
      légalité, les diagnostics peuvent bouger) et un toast succès (« Créneau déplacé. ») confirme
      le geste — sans lui, un déplacement accepté était indistinguable d'un refus silencieux.
    - **Refusé (422)** : rien n'est écrit ; `moveState` passe `rejected` avec les règles violées
      NOMMÉES (`SlotDetail`, déjà documenté §6.2 F2b) — le **mode cible reste armé** pour
      réessayer. Chaque violation porte aussi les ids de l'entité fautive
      (`teamId`/`coachId`/`venueId`/`dayOfWeek`/`startTime`/`conflictingTeamId`, null-safe —
      miroir de `AssignmentViolationSchema`) : la grille **surligne** le créneau de
      l'équipe déjà en place que le moteur a nommée (`violationHighlightSlotIds` — présentation
      pure, aucune redérivation de règle ; une équipe absente du cache affiché n'ajoute aucun
      surlignage fantôme). Le surlignage s'efface au retour à `idle`/`pending` (nouveau créneau
      sélectionné, nouvel essai) sans jamais écraser un surlignage venu d'un diagnostic.
    - Génération en cours (409) / moteur injoignable (502) : `blocked`/`error`, déjà documenté
      §6.2 — toastés sans rester en panneau (409/502 nomment un contexte transitoire, pas une
      règle à corriger). **Moteur trop lent (504 `engine_timeout`, incident terrain 2026-08-17)** :
      DISTINCT du 502 — le moteur travaillait, il a juste dépassé le délai transport (20 s,
      `MoveSlotService::VALIDATE_HTTP_TIMEOUT_SECONDS`) ; rien n'est écrit. Sur un move/place
      DIRECT (case libre, sans essai préalable), `EngineTimeoutError` suit le même traitement que
      `TargetLockedError`/`SlotEditError` : toast NOMMÉ (message serveur, jamais un numéro nu), le
      mode cible reste armé pour réessayer. Sur l'ESSAI d'éviction (case occupée), voir l'état
      `failed` ci-dessous — la modale reste ouverte au lieu de se fermer en silence. **Timeout
      CLIENT (P4-119 a, incident terrain 2026-08-19)** : le rail move/place/dry-run attend
      désormais **45 s** (`MOVE_VERDICT_TIMEOUT_MS`, `api.ts`) — snapshot + budget transport moteur
      20 s + marge bout-en-bout mesurée > 30 s sur un club dense — au lieu du timeout par défaut de
      ky (~10 s), qui abandonnait la requête (nginx 499) AVANT que le moteur ait tranché : les logs
      du fondateur montraient un `valid=True` rendu UNE seconde après l'abandon client.
  - **Case occupée → éviction, remplie par un ESSAI (P2-32, décision fermée D6+D8, 2026-08-16)** :
    un clic sur une carte occupée arme `evictDialog` en **`checking`** et lance IMMÉDIATEMENT un
    **dry-run** — `POST /api/schedule-slots/{id}/move` avec `evictSlotId` = l'occupant **et
    `dryRun: true`** (`useMoveDryRun`) — le moteur juge SANS RIEN ÉCRIRE. `EvictConfirmDialog`
    (`frontend/src/features/planning/EvictConfirmDialog.tsx`) porte quatre états :
    - **`checking`** : « Vérification… » (spinner), aucun bouton de confirmation ;
    - **`accepted`** (dry-run `valid: true`) : « Ce créneau est occupé par \<équipe\>. […] » **+
      les compromis NOMMÉS du candidat** (`CompromiseList` — pastille « Concession » pour un
      `effect: "broken"`, « Gain » pour `"gained"`, broken listés en premier ; liste vide →
      « Aucun compromis détecté. ») **+** le bouton **« Déplacer et évincer »** qui déclenche
      cette fois le move **RÉEL** (sans `dryRun`) ;
    - **`refused`** (dry-run `valid: false`, un 200 — **pas** un 422) : les motifs **NOMMÉS**
      (`violations`), **PAS de bouton de confirmation** (« Fermer » seul), et la grille
      **surligne** le conflit nommé (même chemin que le refus d'un move réel) ; le mode cible
      reste armé pour réessayer ailleurs.
    - Un dry-run **refusé pour une raison MÉTIER** au transport (verrou `target_locked` posé
      entre-temps, génération en cours 409) ferme la modale (`onError`, pas `onSuccess`) et
      toaste le motif — le mode cible reste armé.
    - **`failed`** (incident terrain 2026-08-17, affiné P4-119 b le 2026-08-19) : l'essai lui-même
      **N'A PAS ABOUTI** — DISTINCT d'un `refused` : rien n'est tranché, donc la modale **RESTE
      OUVERTE** au lieu de se fermer en silence. **Trois causes NOMMÉES, jamais confondues**
      (`EvictFailureKind`, `EvictConfirmDialog.tsx`) : `timeout` — le SERVEUR a tranché « moteur
      trop lent » (504 `engine_timeout`) → « La vérification n'a pas abouti — le moteur n'a pas
      répondu à temps. » ; `unreachable` — une vraie panne réseau/5xx du backend → « … le moteur
      est indisponible. » ; `interrupted` — l'attente a été coupée CÔTÉ CLIENT avant la réponse
      (timeout `MOVE_VERDICT_TIMEOUT_MS` atteint ou navigation) : **aucune preuve de panne** (le
      moteur répondait peut-être `valid` juste après), donc surtout PAS « indisponible » → « La
      vérification a été interrompue avant la réponse — réessayez. » C'est cette 3ᵉ cause qui
      corrige le bug fondateur du 2026-08-19 : un déplacement parfaitement LÉGAL affichait
      jusque-là « le moteur est indisponible », un diagnostic FAUX puisque le moteur avait
      répondu `valid=True` une seconde après l'abandon client. Chaque état propose
      **« Réessayer »**, qui rejoue le dry-run sur la MÊME cible (repasse par `checking`). Demande
      fondateur explicite : ne jamais laisser un échec de vérification silencieux.

    Confirmé (état `accepted`) → `/move` réel avec `evictSlotId`, sans `dryRun`. 200 → toast
    nommé, suffixé **« — N compromis »** si le geste réel en a produit (« \<source\> déplacée —
    \<évincée\> est à replacer — N compromis. ») et une **barre d'éviction+compromis** apparaît
    sous la toolbar (voir bandeau combiné ci-dessous) ; elle disparaît au geste suivant ou au
    changement de version. Une carte **verrouillée** n'est jamais une cible d'éviction (D3,
    verrou souverain) — désactivée avec un tooltip.
  - **Case libre → aucun dry-run (décision fermée D8)** : un clic sur une case **vide**, en mode
    cible move OU place, écrit **directement** (`doMove`/`doPlace`, sans essai préalable) — « un
    clic = écrit, l'undo (geste 4) est le filet » ; pas de bouton « Essayer » séparé (différé,
    hors scope P2-32). Seule la case **occupée** passe par l'essai de la modale d'éviction.
  - **Compromis nommés (P2-32)** — un compromis (type `Compromise`, miroir de `CompromiseSchema`) est une préférence **SOUPLE** que le geste accepté **casse** (`effect:
    "broken"`) ou **rétablit** (`effect: "gained"`), déjà `message` HUMAIN (le moteur y nomme
    équipe/coach/gymnase, aucun id interne). `CompromiseList`
    (`frontend/src/features/planning/CompromiseList.tsx`) l'affiche en **présentation pure** :
    tri broken-d'abord puis gained, pastille sobre par effet (jamais de rouge destructif — ce
    sont des compromis LÉGAUX, pas des erreurs), un effet inconnu dégrade en pastille neutre
    plutôt que de planter. **Après tout geste ÉCRIT accepté** (move simple, move+éviction, place,
    raccourci « remettre l'évincée ») portant `compromises.length > 0` : le toast se suffixe
    (« — N compromis ») et un **bandeau combiné** apparaît sous la toolbar — en tête le
    raccourci d'éviction (déjà documenté, geste 2) s'il y en a un, dessous la `CompromiseList` du
    geste ; dismissible (bouton « Ignorer », ferme les deux) ; **purgé** au geste suivant, à
    l'undo (geste 4) et au changement de version.
  - **Placer une séance à la dérive (geste 3)** : le bandeau « Séances à replacer » (voir
    ci-dessous) arme le mode cible en **placement** — `POST /api/schedules/{id}/place-slot`
    (`usePlaceSlot`). **Décision fondateur (option a) : placer pose sur du LIBRE, jamais
    d'éviction au placement** — une case pleine se libère d'abord par un `/move` ; sur une case
    **occupée**, `doPlace` appelle `place-slot` **directement** (le rail ne porte pas
    `evictSlotId`) et laisse le **moteur trancher la capacité** (une case partagée type CEC peut
    accepter à côté). *Option b (évincer au placement) reste délibérément hors scope — on
    n'itère que si le terrain le réclame, décision fondateur.* Un refus toast la première
    violation et surligne le conflit ; le mode placement reste armé.
- **Déplacer le GROUPE — même mode cible, rail dédié (P2-51 PR-6, 2026-08-31, D11)** : sur une
  séance de bloc de mutualisation (tous ses membres co-localisés sur la même case), le bouton
  « Déplacer » de `SlotDetail` devient **« Déplacer le groupe »** (compte de membres annoncé, note
  de conséquence « Déplace les N équipes du groupe ensemble. ») — pas de déplacement individuel
  proposé, le verdict le refuserait (`shared_block_broken`). **L'appartenance à un bloc n'est
  PORTÉE PAR AUCUN champ du `Slot`** (le backend n'en expose pas) : elle est **dérivée FAIL-SAFE**
  côté front (`frontend/src/features/planning/lib/blockSession.ts::blocksForSlot` — un bloc
  « siège » sur une case quand TOUS ses membres y ont un créneau), le serveur restant seul juge —
  la dérivation ne décide QUE de PROPOSER le geste, jamais de l'accepter (une case source devenue
  fausse répond `slot_unavailable`, affiché tel quel). Même mode cible click-click que le
  déplacement simple (armé par `armMoveGroup`, `targetMode.kind === "move-group"`) ; case cible
  libre OU occupée envoyée telle quelle au rail — **aucune éviction** sur ce rail (le moteur
  tranche, violations affichées telles quelles). `POST /api/schedule-slots/move-group`
  (`useMoveGroup`, MÊME paquet d'invalidation que `useMoveSlot`) : le serveur résout LUI-MÊME les
  créneaux membres depuis la case source (jamais de slotIds client). Accepté → toast (suffixé
  « — N compromis » si `compromises.length > 0`) ; refusé (422) → violations NOMMÉES dans le
  panneau + surlignage du conflit (même dérivation que `moveState`, présentation
  `moveGroupState`) ; 409/502/504 toastés, mode cible reste armé. **Pas d'undo** (geste 4) pour un
  déplacement de groupe — il faudrait rejouer `move-group`, hors scope de cette PR ; un éventuel
  undo d'un geste simple précédent est invalidé au succès.
- **Bandeau « Séances à replacer » (`DriftBanner`, geste 3)** — présentation pure
  (`lib/drift.ts`, module PUR) : les équipes qui ont **moins** de séances placées que le seuil
  attendu, sur un planning **`COMPLETED`** seulement (hors génération). Un bouton par équipe
  arme le mode placement pour elle. **Règle de seuil, selon la COUCHE affichée (ADR-0002)** :
  sur le plan **SAISON**, le seuil est `Team.sessionsPerWeek` ; sur un plan de **PÉRIODE**, il
  vient de l'override du plan — équipe **désactivée** pour la période → jamais en dérive (elle ne
  joue pas la période), `sessionsPerWeek` overridé (non nul) → c'est LUI le seuil, sinon repli sur
  le seuil de saison. **FAIL-CLOSED** : sur une période dont les overrides ne sont pas encore lus,
  aucune dérive n'est affichée (pas de dérive fantôme devinée).
- **Annuler le dernier geste (geste 4, profondeur 1, session)** — bouton dans la barre compacte
  (à côté de « Diagnostics du système » / « Verrous manuels »), visible tant qu'un geste est
  annulable. Un `move` simple s'annule par le move inverse ; un `move` avec éviction s'annule par
  le move inverse **puis** un replacement de l'évincée (deux verdicts moteur) — un échec du
  second est **dit honnêtement** (toast : « \<source\> est revenue, \<évincée\> reste à
  replacer. »), jamais maquillé en succès. Le raccourci d'éviction et l'undo se réinitialisent au
  changement de version affichée.
- **Le hook POSSÈDE son feedback métier** (`useMoveSlot`/`usePlaceSlot`/**`useMoveDryRun`**) : un
  `onError` de NIVEAU HOOK **tait** les refus métier (`MoveRejectedError`/`TargetLockedError`/
  `SlotEditError`/`GenerationInProgressError`/`EngineTimeoutError` — la page les affiche dans son
  contexte) et ne parle que d'un vrai échec transport imprévu. Sans lui, le filet global
  `MutationCache.onError` (qui ne toaste QUE les mutations SANS `onError` de niveau hook) doublait
  un refus 422 en **« Problème de connexion. Vérifiez votre réseau. »** — mensonger, le réseau
  allait bien. `useMoveDryRun` (P2-32) suit la MÊME règle mais un dry-run **refusé n'est jamais
  une erreur** — c'est un 200 `{valid:false}` qui **résout** (`onSuccess`), pas une exception ;
  son `onError` voit `TargetLockedError`/`SlotEditError`/`GenerationInProgressError` (fermeture de
  la modale, refus MÉTIER) **et** `EngineTimeoutError`/toute autre panne transport (la modale
  passe en `failed` au lieu de se fermer — incident 2026-08-17, voir ci-dessus), jamais
  `MoveRejectedError`. `moveSlot`/`placeSlot` normalisent `compromises` à `[]` au parsing — jamais
  `undefined` à la lecture côté page.
- **Verrouiller/déverrouiller** : deux points d'entrée partagent désormais le même geste
  (**P2-31 PR 2, 2026-08-16**). Le panneau `SlotDetail.onToggleLock` **et** un **cadenas en un
  clic directement sur la carte de la grille** (`WeekGrid` : la carte devient un `<div>` wrapper,
  le cadenas est un **bouton FRÈRE** — jamais imbriqué dans le bouton de sélection, HTML
  invalide sinon — permanent si le créneau est verrouillé, révélé au survol/focus sinon ;
  `aria-label`/`title` « Verrouiller/Déverrouiller \<équipe\> » ; **absent en lecture seule**
  (planning validé ou `FAILED`), où le cadenas redevient un simple indicateur passif, non
  actionnable). Les deux appellent le même point d'entrée `requestToggleLock`
  (`PlanningPage.tsx`) → `POST /api/schedule-slots/{id}/manual-edit/lock` (`useLockSlot`). Un
  échec (moteur/réseau) pose un toast d'erreur avec le motif serveur — avant, le cadenas restait
  muet en cas d'échec. **Déverrouiller un verrou `RESERVATION`** ouvre d'abord `ConfirmDialog`
  (« Déverrouiller ce créneau réservé ? ») : c'est un engagement pris hors de l'app (réservation
  de gymnase), à ne pas relâcher par inadvertance — verrouiller, et déverrouiller un
  `MANUAL`/`UNKNOWN`, mutent directement sans confirmation. La confirmation retient **le créneau
  visé** (`pendingUnlockSlotId`), pas le créneau sélectionné : le cadenas de la grille peut viser
  un créneau différent de celui affiché dans le panneau de détail.
- **La garantie « les verrous survivent à la régénération » se DIT désormais à l'écran** (P2-31
  PR 2, 2026-08-16) — jusque-là elle ne vivait qu'en commentaire de code (`model.py` :
  `lockLevel == "HARD"` force la variable). Phrase discrète contre le bouton, dans le bloc
  non-`isChosen` de `PlanningToolbar` : « Vos créneaux verrouillés sont conservés à la
  régénération. ». L'intro du panneau Réserver du wizard (`ReservationPanel`) porte la même
  garantie : « Cliquez un créneau pour y fixer une équipe (verrou HARD, conservé à chaque
  régénération). »
- **Panneau latéral des verrous manuels + lentille** (**P2-31 PR 3, 2026-08-16 — lot SOLDÉ**) :
  la barre compacte de la grille (celle qui porte « Diagnostics du système ») gagne un bouton
  FRÈRE **« Verrous manuels (n) »** (icône `Lock`) comptant les créneaux `lockOrigin === "MANUAL"`
  **seulement** — ni les `RESERVATION` ni les `UNKNOWN`, c'est le travail du gestionnaire à
  rendre visible. Visible seulement quand le panneau est fermé **et** n > 0 (masqué à n = 0) ;
  **absent de la toolbar**. Même affordance que Diagnostics : un clic ouvre `LocksPanel.tsx`
  (nouveau, patron `SlotDetail`/`DiagnosticsPanel`) dans l'aside ; son repli (`PanelRightClose`)
  referme le panneau **et ÉTEINT la lentille** (pas d'état fantôme) ; le bouton de la barre
  revient.
- `LocksPanel` liste les créneaux verrouillés à la main, triés **jour puis heure** ; un clic
  sélectionne et fait défiler jusqu'au créneau (`onSelectSlot`, même mécanisme qu'un clic
  diagnostic). En-tête : « n verrou(s) posé(s) à la main ».
- **La lentille verrous** — toggle « Voir sur la grille » / « Masquer sur la grille »
  (`aria-pressed`) dans le panneau. Active : les créneaux SANS verrou s'estompent
  (`opacity-40`), les verrouillés portent un anneau **+ une icône** par origine — MANUAL =
  accent (`Lock`), RESERVATION = warning/ambre (`CalendarClock`), UNKNOWN = muted
  (`ShieldQuestion`) — **jamais la couleur seule** (WCAG 1.4.1) : une légende (pastille + icône +
  libellé par catégorie) s'affiche dans le panneau tant que la lentille est active. Le
  rouge/`destructive` du conflit reste réservé au conflit : quand `highlightSlotIds` surligne un
  conflit, la lentille se tait le temps du conflit (elle ne brouille jamais le rouge/l'ambre du
  conflit). `lib/lockLens.ts` est la MAISON UNIQUE du mapping origine→icône/couleur/libellé,
  partagée par `WeekGrid` (anneau + icône sur la cellule) et `LocksPanel` (légende) ; `GridCell`
  et `GridCellMember` portent chacun `lockOrigin: LockOrigin | null` (présentation pure — aucune
  règle métier n'y est re-dérivée, cf. `.claude/rules/frontend.md`).
- Sur une carte **fusionnée** (CEC), la lentille agit **par membre** : si tous les membres
  verrouillés d'une carte partagent la même origine, elle porte **un seul picto + un anneau au
  niveau de la carte** (comme une carte simple) ; sinon (origines mixtes) chaque **rangée
  verrouillée** porte son propre picto **EN LIGNE devant le nom d'équipe** et son propre anneau —
  les rangées non verrouillées de la même carte restent muettes.
- **Polissage cadenas/badge (2026-08-16)** : le cadenas de carte (éditable ou passif) passe en
  **bas-droit**, le badge de lentille (icône d'origine) en **bas-gauche** — les deux alignés au
  pixel sur le même axe horizontal (retour fondateur : le haut-gauche du badge empiétait sur le
  nom d'équipe).

### 6.7 bis Transcription depuis le socle — bouton, panneau « à replacer », comparaison (P2-44 PR-2/PR-4, ADR-0004)

Sur un plan de PÉRIODE **vierge** (aucune version), l'étape Génération du wizard
(`wizard/steps/GenerateStep.tsx`) propose une alternative au solve complet : la V1 peut naître
d'une **transcription sans solveur** du socle pointé (backend livré en PR-1,
[ADR-0004](../../docs/architecture/adr-0004-period-plan-birth-as-socle-copy.md)). Aucune API
n'est touchée par ce lot — le front consomme la route `POST
/api/schedule_plans/{id}/transcribe-from-socle` déjà en place.

- **Auto-déclenchement sur une FERMETURE (P2-44 PR-4, 2026-08-20).** Sur un plan de période dont
  le type est FERMETURE (`"closure" === periodType`) et qui n'a **aucune** version, la
  transcription part **automatiquement** à l'arrivée sur l'étape — le gestionnaire n'a plus à
  cliquer le bouton manuel : le planning de saison amputé des contraintes de la période est déjà
  à l'écran, prêt au déplacement manuel. Implémentation : un `useEffect` dans `GenerateStep.tsx`
  gardé par une **ref one-shot par plan** (`autoTranscribedPlan`, StrictMode/remontage/second
  onglet ne rejouent pas le déclenchement une fois la ref posée) et par le **rôle de gestion**
  (`isManagementRole(me?.role)`, miroir d'AFFICHAGE — le serveur reste seul juge, la parité est
  tenue par `ManagementRolesMatchBackendTest`) — c'est une **mutation FRONT**, jamais un GET qui
  écrit. Le 409 « plan déjà versionné » d'un double appel concurrent est traité comme **bénin** :
  le serveur relit sa garde sous verrou, le front invalide `["schedules"]` et réconcilie la liste
  sans bandeau rouge (seuls les autres échecs restent rendus via `transcribeReason`, comme le
  geste manuel). Les VACANCES (type HOLIDAY) gardent le comportement PR-2 à l'octet près — décision
  de sens fondateur (« un planning tout nouveau, pas de copie du socle ») **et** raison technique :
  une reprise dont la grille est réécrite (créneaux déplacés en journée) verrait les séances du
  soir du socle copiées en verrous HARD **hors grille** — `OrphanPinGuard::firstOrphanMessage`
  (backend, appelé par `GenerateScheduleController` ET `FillPeriodPlanController`) refuserait
  alors **422 « Régénérer » ET « Combler »**, enfermant le gestionnaire au lieu de l'aider.
- **Bouton « Partir du planning de saison »** (`CopyPlus`, variante `outline`, à côté du bouton de
  génération) : rendu **seulement** quand le plan de période n'a **aucune** version
  (`0 === periodPlanVersions.length` — la même garde que le backend, jamais une redérivation
  d'une règle différente). **Ni retiré ni relibellé par PR-4** — il disparaît de lui-même dès
  qu'une V1 existe (transcrite automatiquement ou non) et reste le SEUL chemin sur une reprise de
  vacances (et sur une fermeture, le geste de repli si l'auto-déclenchement a échoué en dehors du
  cas bénin ci-dessus). Le clic appelle `useTranscribeFromSocle` (`wizard/queries.ts`), qui
  invalide `["schedules"]` et `["calendar-entries"]` pour que l'écran embarqué atterrisse sur la
  V1 fraîchement créée (règle « embarqué = la version la plus récente », déjà en place). Un refus
  serveur (409 socle non pointé, 409 plan déjà versionné) est **affiché**, jamais muet
  (`errorMessage`, même patron que le reste du wizard) — pas de bouton « Générer » qui échoue en
  silence.
- **Liste « Séances à replacer »** (`ToReplaceList.tsx`) : les entrées de la réponse
  (`PeriodTranscriptionResult.toReplace` — équipe/jour/heure/gymnase/raison) sont **SERVIES**, le
  front ne redérive rien ; seul le libellé de la raison (`venue_closed`/`venue_disabled`/
  `team_reduced` → « Fermeture du gymnase »/etc., `lib/toReplaceReason.ts`) est une PRÉSENTATION
  pure (régime autorisé, comme `matches/lib/diagnostic.ts`), pas une redérivation de règle. Portée
  de vie **délibérée** : cette liste est un état de la SESSION D'ÉCRAN (passée en prop de
  `GenerateStep` à `PlanningPage`, `toReplace`) — la réponse ne peut pas être re-servie (la route
  crée la V1 une seule fois ; la rappeler sur un plan déjà versionné rendrait 409), donc après une
  navigation c'est le `DriftBanner` déjà existant qui prend le relais (il redit, par nature, les
  équipes sous leur quota) plutôt qu'une redérivation ad hoc ici. Le panneau et le bouton
  « Comparer avec la saison » ne s'affichent que sur l'écran de génération d'une période
  (`embedded && scoped` dans `PlanningPage.tsx`), jamais en page autonome (`/planning`).
- **Vides mis en évidence** (`WeekGrid` prop `emphasizeEmpty`) : quand la liste « à replacer »
  n'est pas vide, les créneaux VIDES de la grille passent en style « repérable » (bordure pleine
  accent, fond teinté, `aria-label` nommé « Créneau vide à combler… ») pour que le gestionnaire
  voie où recaser les séances non reprises. Cède le pas au surlignage **conflit** (`flagged`,
  jamais les deux à la fois) et **ne s'applique jamais à une case FERMÉE** — la case fermée sort
  avant cette branche (§6.7, marquage P2-43 volet v).
- **Modale « Comparer avec la saison »** (`SeasonComparisonModal.tsx`) : une CONSULTATION en
  lecture seule de la version de saison POINTÉE (le socle transcrit), réutilisant `buildGrid` +
  `WeekGrid` avec `onSelectSlot` inerte — aucun geste d'écriture, aucun mode cible. A11y modale via
  le composant `Modal` partagé (`shared/components/ui/modal`). Le bouton qui l'ouvre apparaît dès
  qu'un socle est consultable (une version de saison pointée existe), indépendamment de la liste
  « à replacer ».

**Comblement — bouton « Combler automatiquement » (P2-44 PR-3, ADR-0004, 2026-08-20)** : sur
l'écran embarqué (`PlanningPage`), dès que la dérive `driftEntries` (le prédicat SERVI, jamais
recomposé — même donnée que le bandeau existant) est non vide, un bouton `outline` (icône
`Sparkles`) apparaît à côté des « séances à replacer » : « Combler automatiquement ». Le clic
appelle `useFillSchedule` (`queries.ts`), miroir strict de `useRegenerate` — POST
`schedules/{id}/fill`, invalide `["schedules"]`, sélectionne la V+1 créée en `onSuccess`. Un refus
serveur (409 non-période/version choisie/génération en cours, 422 complexité/épinglage orphelin,
429 quota club) est **affiché** via `errorMessage`/toast, jamais muet. Désactivé pendant une
génération en cours ou sans version valide sélectionnée. **Outil d'appoint** : « Régénérer » (solve
complet) reste dans la barre d'outils à côté — le comblement ne le remplace pas, il évite un solve
complet pour le cas courant (un gymnase a fermé, une équipe a repris son volume).

**Les écarts NOMMÉS — panneau « Écarts avec le planning de saison » (P2-44 PR-5, ADR-0004,
2026-08-20)** : sur l'écran embarqué (`transcriptionSurface`, donc `embedded && scoped`) d'une
période de type **FERMETURE** portant une version `COMPLETED`, `SocleDeviationPanel.tsx` affiche
l'agrégat (« N séances déplacées, M à replacer ») puis le détail ligne à ligne — une déplacée se lit
`U13F1 · Mar 18h30 Matéo → Jeu 19h00 JDR`. La donnée est **SERVIE** par `GET
/api/schedules/{id}/socle-deviation` (`useSocleDeviation`, `queries.ts`) : le front ne compare
**rien** — il ne redérive ni l'appariement ni la raison, il met en forme. Une raison `null` (la
sélection de période n'explique pas l'absence) se rend **sans étiquette**, jamais avec une cause
inventée. Le hook est invalidé aux **trois** sites qui invalident déjà `["slots"]` (`useLockSlot`,
`useMoveSlot`, `usePlaceSlot`) pour que le diff suive un déplacement.

Contrairement à `ToReplaceList`, ce panneau **survit à la navigation** (route de lecture
re-appelable, pas une réponse de POST). Il **s'ajoute** à `ToReplaceList` sans le remplacer —
arbitrage fondateur « les deux affichés pour le moment » — d'où deux titres délibérément
distincts : « **Écarts avec le planning de saison** » (le neuf, qui porte en plus les **déplacées**)
vs « Séances non reprises du planning de saison » (l'existant, session d'écran). Sur une **vacance**
la route n'est **jamais appelée** : le comportement PR-2 reste intact à l'octet.

**Le symbole ⇄ dans la grille, panneau cliquable, compteur de carence (P2-44 PR-4, lot « overlay =
la saison au moindre effort », 2026-09-02)** :

- **Marquage DANS la grille.** `WeekGrid` reçoit `deviatedSlots?: Map<slotId, libellé d'origine>`
  (`frontend/src/features/planning/lib/socleDeviationCells.ts`, maison unique partagée avec
  `SocleDeviationPanel` pour le libellé « Mar 18h30 Matéo »). Une carte dont le `slotId` est dans la
  map porte, **avant le nom de l'équipe**, une pastille `ArrowLeftRight` (fond `bg-diff`,
  `text-diff-foreground`) — **jamais le mot « déplacée » à l'écran** (le symbole est auto-explicite) ;
  le sens accessible vit en `sr-only` (« déplacée — en saison : {origine} ») et en suffixe du
  `title` de la carte. Un anneau `ring-1 ring-diff` habille la carte, mais **cède le pas** à la
  sélection (`ring-accent`), à l'anneau de la lentille verrous et au surlignage conflit — une carte
  occupée surlignée par un conflit ne porte **jamais** d'anneau, qu'elle soit déviée ou non ; le
  symbole, lui, reste dans tous les cas. Une carte FUSIONNÉE (bloc) porte le symbole **par membre
  dévié**, pas globalement sur la cellule. Armé seulement sur une **FERMETURE** (`isClosurePeriod`)
  avec une version `COMPLETED` — jamais de légende ni de bascule d'affichage.
- **Token `--diff`** (`frontend/src/index.css`) : rôle sémantique à part entière (violet,
  `oklch(0.52 0.19 305)` clair / `oklch(0.78 0.16 305)` sombre), hors des 4 rôles existants
  (accent/warning/destructive/success) — « ceci diffère du socle », jamais une erreur. Exposé
  `--color-diff`/`--color-diff-foreground`. `tests/e2e/a11y-contrast.spec.ts` mesure ses paires
  non-texte (pastille sur carte/fond, foreground sur pastille) à ≥ 3:1 dans les deux thèmes (WCAG
  1.4.11) — le seuil non-texte, pas le 4.5:1 du texte.
- **Lignes du panneau cliquables (D6-d, seule sous-décision étiquetée dans le code).** Chaque ligne « déplacée » de `SocleDeviationPanel` est un
  `<button>` (prop `onSelectSlot`, câblée à `openSlot` de `PlanningPage`) qui vise directement la
  carte dans la grille — même recette que `LocksPanel`. Nécessite `entry.to.slotId` sur la réponse
  backend (`SocleDeviationCalculator`/`SocleDeviationResult`, le `slotId` du créneau de PÉRIODE que
  la grille rend). Les lignes « à replacer » restent du texte (aucune carte à viser).
- **Compteur de carence.** Sur une FERMETURE seulement, `PlanningPage` affiche une phrase
  factuelle et neutre (jamais une alarme, pas d'`aria-live`) au-dessus de la grille —
  `capacityShortfallSentence` (`lib/capacityShortfall.ts`) : « N séances demandées pour M places
  disponibles — il manque K places. » (ou sans la dernière proposition si la demande tient dans
  l'offre). Les nombres viennent de `ValidateResult.capacity {demand, offer}`, une clé ADDITIVE de
  `POST /api/constraints/validate` — présentation pure, le calcul reste serveur (`demand` est
  bloc-aware : une séance de bloc de mutualisation réunit N membres sur UNE place, cf.
  `PayloadCapacityMirror::demand`). Rien sur une vacance (`capacityArmed` exclut HOLIDAY) ou sans
  payload.

