# ADR-0002 — Pattern « Plan » : un plan nommé, des versions, un pointeur

- **Status**: accepted — Date: 2026-07-12
- **Décidé avec le fondateur** point par point (session 2026-07-12) ; référence produit :
  [`specs/courantes/types-de-planning.md`](../../specs/courantes/types-de-planning.md).

## Contexte

Le « planning » n'existe pas comme objet de premier ordre : c'est un regroupement implicite
de lignes `Schedule` par `(seasonId, calendarEntryId)`. Conséquences constatées :

- **Le nom n'a pas de maison.** Tentative E6 (nom sur `Schedule.name`) : 2 rounds de revue,
  16 findings, abandonnée (PR #214) — `Schedule` est une *version*, le nom du plan divergeait
  entre baseline, dernière version et liste.
- **Trois pointeurs à sémantique croisée** : `Season.baselineScheduleId`,
  `CalendarEntry.overlayScheduleId`, `Season.liveContextScheduleId` (★) — plus deux statuts
  de cycle de vie (`VALIDATED`, `ARCHIVED`) qui re-disent la même chose que les pointeurs.
- **Vocabulaire flou** (baseline / socle / overlay / version / planning) : le fondateur et le
  code ne parlaient pas de la même chose.
- **Le futur immédiat casse le modèle** : le découpage hebdomadaire (types-de-planning E1 —
  N plannings par vacances, réglages différents par semaine) est impossible tant que les
  réglages de période sont accrochés à la `CalendarEntry` (partagés par toute la fenêtre).

Contexte V0 : hors socle (flux saison), tout peut être reconstruit proprement — décision
explicite du fondateur : « définir un pattern simple identifiable », pas corriger un bug.

## Décision

### Le modèle : Plan → Versions → Pointeur

```
Plan                                    ← l'objet de premier ordre (nouveau)
  id, clubId, seasonId
  type              SEASON | CLOSURE | HOLIDAY
  name              le nom PUBLIC, éditable (pinceau) — « Planning de la saison 2025-2026 »,
                    le TITRE de son entrée de calendrier (depuis le 2026-08-23 — une seule identité ; avant : gabarits « Ajustement {gymnase} — {repère} », « {label} — {repère} »)
  startDate/endDate SA période d'application (SEASON = la saison ; sinon la semaine)
  calendarEntryId?  le DÉCLENCHEUR (l'indispo/les vacances du calendrier) — N plans possibles
                    par déclencheur (2 semaines ⇒ 2 plans) ; null pour SEASON
  chosenScheduleId? le POINTEUR : la version choisie (validée) ; NULL = espace de travail

Schedule (= Version)                    ← existant, recentré
  planId            rattachement au Plan (remplace la dérivation par calendarEntryId)
  versionNumber     STOCKÉ, compteur monotone par plan — V3 reste V3, le suivant est V4
  status            cycle SOLVEUR uniquement : DRAFT → PENDING → GENERATING → COMPLETED | FAILED
  name              devient un label technique auto « V{n} - {date} » (jamais édité)
  + snapshot figé, score, diagnostics, slots, photo de structure (inchangés)
```

### Les invariants (décisions fondateur, verbatim où possible)

1. **Valider = pointer.** Choisir une version ⇒ `Plan.chosenScheduleId = version` **et les
   autres versions du plan sont supprimées**. Pas de statut `VALIDATED`, pas d'`ARCHIVED` :
   « validé » se **dérive** du pointeur (une seule vérité).

   ⚠️ **Amendement P2-7 (décision fondateur, 2026-07-30)** : l'invariant tenait implicitement
   qu'« une version pointée est la seule version de saison » — faux du code jusqu'ici,
   `POST /api/schedules` rendait 201 même avec un socle en vigueur (defect prouvé,
   `specs/evolution/reprise-perimetre-engage.md`). **Aucune version de saison ne naît
   tant que le plan SEASON en pointe une** : `ScheduleStateProcessor::processPost` refuse en
   409 tout POST « de saison » (sans `schedulePlanId`, ou avec le plan SEASON explicite) tant
   que `chosenOfSeasonPlan` répond non-null, sous le verrou de plan-scope de la saison et
   dans la transaction de création. NR : `Security/SeasonVersionUniquenessTest` (phase1).

   ⚠️ **Amendement P2-9bis (décision fondateur, livré 2026-07-31)** : le cadrage initial
   (ci-dessus) soupçonnait un defect sur les chemins agissant sur une version de saison **déjà
   existante** — `regenerate`/`generate` ne refusant que si la version *source* était la
   version pointée, `regenerate-from` restaurant une structure ancienne sans consulter le
   pointeur du tout, sur des données héritées d'avant P2-7 (une version pointée PLUS une
   sœur). **Ce defect ne s'est jamais confirmé en le construisant** : `ValidateScheduleController`
   supprime déjà toutes les sœurs à la validation, et P2-7 a fermé le POST direct — quand le
   plan SEASON pointe, il n'existe donc **qu'une seule** version de saison, et ces trois
   portes n'ont aucune sœur à viser. L'invariant était déjà tenu, mais par une **propriété
   émergente** de deux mécanismes distincts, pas par une règle explicite. Le lot livré pose
   `SocleGuard::assertSeasonPlanNotChosen` (miroir de `assertSeasonPlanChosen`) sur les trois
   portes en **défense en profondeur**, sous le verrou de plan-scope de la saison et dans une
   transaction (`GenerateScheduleController` n'avait ni l'une ni l'autre avant ce lot), et
   `Security/SeasonPlanInForceTest` épingle la propriété émergente elle-même — si une future
   modification de la validation cessait de supprimer les sœurs, ce test tomberait et
   préviendrait que les trois portes se rouvrent. Le rattrapage des données héritées est
   **écarté** (V0, pas de migration, fixtures repartues de zéro). **Invariant tenu, formulé
   juste** : tant que le plan de saison est en vigueur, aucune AUTRE version de saison n'est
   créée ni résolue, et la structure du club n'est pas écrasée par une photo ancienne — **pas**
   « rien ne touche au calendrier de saison ni à ses entrées » (la structure du club — équipes,
   gymnases, coachs, contraintes permanentes — reste modifiable toute l'année ; c'est l'objet
   de la comparaison `snapshotHash`/`currentStructureHash`, pas de son gel). **Ce que
   l'historique de cadrage garde comme enseignement** : la tentative de fermer `regenerate`
   seul avait été essayée et retirée, parce qu'elle transformait « Charger cette version » en
   cul-de-sac destructif (la structure vivante écrasée, puis « Régénérer » qui refuse) — revue
   #326 round 2 ; c'est pour cette raison que le lot final pose la même garde sur les trois
   portes ensemble plutôt que porte par porte. Le volet « supprimer les matchs `UNPLACED` à la
   réouverture », envisagé dans le même cadrage, reste **abandonné** (décision fondateur) : le
   module matchs est déjà inaccessible sans version pointée (`SocleGuard::assertSeasonPlanChosen`,
   inv. 13), rien à en tirer en le vidant. Les affordances frontend (`regenerateDisabled`/
   `canRegenerateFrom`) restent hors scope : aucune UI n'atteint ces chemins en présence d'un
   socle en vigueur.
2. **Pointeur NULL = espace de travail.** On (re)travaille ⇒ pointeur remis à null, on
   génère des versions (V4, V5…), on choisira. **Aucun pointage automatique** (l'auto-baseline
   au 1er COMPLETED disparaît) — seul le gestionnaire pointe.
3. **1 Plan de type SEASON par saison.** Créé avec la saison (onboarding / transition N+1).
4. **Deux plans ne se chevauchent jamais** (dates d'application), hors plan SEASON qui
   couvre tout par nature.

   ⚠ **Amendement P2-38 (constat fondateur, 2026-08-18)** : jusqu'à la PR1, cet invariant
   n'était **pas tenu par le code** entre deux périodes RACINES — `CalendarEntryInput` ne
   valide que la FORME des dates (`Assert\Date`), aucune garde de chevauchement n'existait à
   la création d'une entrée ou d'un plan. La **PR1** (livrée, ci-dessous) a fermé la
   conséquence la plus dangereuse d'un tel chevauchement — une fermeture de gymnase datée
   s'applique désormais à TOUT plan dont la fenêtre la recoupe, quelle que soit l'entrée qui
   la porte (priorité au **périmètre le plus fin** : la reprise gouverne, l'incident y entre).

   **La PR2 (livrée 2026-08-18) tient désormais l'invariant, à la NAISSANCE** :
   `App\Service\PeriodWindowUniquenessGuard` refuse en 409 (`window_already_planned`) tout
   plan de période dont la fenêtre recoupe celle d'un AUTRE plan de période, aux deux seuls
   sites de naissance (le geste « Adapter » et la création d'une entrée-semaine qui naît avec
   son plan), dans les deux sens. **La PR3 (front, livrée 2026-08-18) ferme le lot** : le refus
   409 s'affiche à l'endroit du geste (`WindowAlreadyPlannedNotice`, reprenant tel quel le
   message serveur) au lieu du toast générique du filet global — détail : lot P2-38 ci-dessous,
   **P2-38 intégralement livré (3 PR), l'item a quitté `specs/evolution/roadmap.md`**.
5. **Les réglages de période s'accrochent au Plan** (pas au déclencheur calendrier) :
   coches équipes (`TeamPeriodOverride`), contraintes gardées/enlevées
   (`ConstraintPeriodOverride`), sa grille de gymnases (`VenueTrainingSlot` scopé période —
   depuis #8 une COPIE du modèle de saison, cf. l'amendement ci-dessous) et ses modes de
   gymnase (`VenuePeriodOverride`),
   réservations, flag de seed (`teamSelectionInitialized`) →
   **re-keyés `calendarEntryId` → `planId`**. Chaque plan-semaine a SES réglages.

   ⚠️ **Correction (décision fondateur, 2026-07-17)** : les **contraintes datées** sortent
   de cette liste — elles **restent sur `CalendarEntry`**. Elles décrivent le **FAIT**
   (« Barros fermé du 20 au 26 »), pas la réponse qu'on lui apporte, et le **radar de
   conflits les lit par l'entrée** (`CalendarEntryConflictsController`) pour annoncer
   « cette fermeture gêne 3 séances » — c'est ce qui **déclenche** le geste « ajuster ».
   Les accrocher au plan les rendrait illisibles tant qu'aucun plan n'existe… or le plan
   naît justement de ce geste : le radar ne pourrait plus jamais le provoquer. Cela
   inverserait l'invariant fondateur « l'indisponibilité est déclarée d'abord, puis le
   gestionnaire décide ». La section « Rôle de `CalendarEntry` » ci-dessous disait déjà
   qu'elle **garde** les contraintes datées du fait : c'est l'invariant 5 qui était trop
   large, et les deux se contredisaient depuis la rédaction.

   ⚠️ **Amendement #8 (décision fondateur, 2026-07-24) — une période POSSÈDE sa grille** :
   le `VenueTrainingSlot` scopé période n'est plus une liste de « créneaux prêtés » qui
   s'ajoutent à ceux de la saison — à la naissance du plan, TOUTE la grille de saison de
   chaque gymnase est **COPIÉE** en créneaux du plan (`SchedulePlanProvisioner::copySeasonalSlots`).
   Il n'y a donc plus jamais d'union saison ∪ période : `ScheduleConstraintBuilder::buildForOverlay`
   ne lit QUE les créneaux du plan — un gymnase n'a jamais deux jeux de créneaux dans une
   même période, rien ne peut se chevaucher entre couches. Un nouveau réglage sparse par
   (plan, gymnase), `VenuePeriodOverride` (table `venue_period_override`, valeurs
   `DISABLED`/`BLANK` ; pas de ligne = hériter), agit sur cette copie : DÉSACTIVÉ conserve
   la grille mais sort le gymnase du payload envoyé à l'engine ; VIERGE la vide ; le retour
   à « hériter » (`DELETE`) la revide puis la RECOPIE depuis le modèle de saison. Un
   épinglage (verrou HARD ou réservation) qui ne retombe plus sur aucun créneau de cette
   grille **bloque la génération en 422** avec un message nommant le gymnase et le jour
   (`OrphanPinGuard`, appelé par `GenerateScheduleController`) — jamais filtré en silence.

   ⚠️ **Amendement bien-être-par-période (décision fondateur, 2026-08-18) — une période
   POSSÈDE aussi ses 4 règles de bien-être** : `ImplicitRuleSetting` (les 4 règles réglables du
   contrat moteur — repos coach, distribution salariés, enchaînements, âge croissant, §backend
   `docs/architecture/constraint-matrix.md`) était jusque-là **club+saison seul**. Même patron
   que la grille (#8, copie totale plutôt qu'union) : à la naissance du plan, `ImplicitRuleSetting`
   gagne l'ancre `schedulePlanId` (NULL = saison ; un UUID = un plan de période) et
   `SchedulePlanProvisioner` matérialise, à la naissance, une **copie totale** des 4 lignes
   (valeur saison si déviée, sinon défaut) — jamais sparse, pour qu'un plan « tout au défaut »
   reste distinguable d'un plan **legacy** (né avant cette fonctionnalité, zéro ligne, qui
   **retombe sur la saison** — repli vivant, comportement d'avant au bit près, aucune migration
   de données). Une fois copiée, la ligne du plan **vit sa vie** : une modification ultérieure de
   la saison ne redescend plus. Le DELETE en portée plan **re-copie la valeur saison courante**
   au lieu de supprimer la ligne (l'invariant « une période porte SES 4 règles » ne se brise
   jamais). NR : `Security/PeriodPlanBirthTest` (naître = porter ses 4 lignes copiées),
   `CrossStack/ImplicitRulePayloadParityTest` (stocké par portée == payload de sa portée, le
   repli legacy compris) — les deux **steps de `blocking-tests`**.

   **Le raisonnement du fondateur, verbatim (pourquoi copie et pas héritage vivant)** : le
   planning de saison du BCCL a été construit **hors de l'application**, donc ses règles de
   bien-être n'y sont **pas appliquées** — mais pour une période de reprise, le gestionnaire
   **peut enfin** appliquer ses règles ; pourquoi lui faire payer de ne pas avoir pu le faire en
   début de saison ? Preuve à l'appui côté seed : les 4 règles sont posées en `PREFERRED` au
   niveau SAISON pour le club BCCL (`backend/src/Seed/BcclSeeder.php`, section « Les 4 règles de
   bien-être en PRÉFÉRÉ ») **uniquement pour rendre transcriptible** un planning réel construit en
   violation de ces règles — un **artifice de transcription**, pas une politique de club. Avec un
   héritage vivant (la période SUIT la saison), cet artifice aurait **fui** dans chaque période de
   reprise sans que le gestionnaire l'ait choisi. La copie l'isole : une reprise démarre de la
   valeur saison du jour, puis devient LA SIENNE.

   **PR1 (backend) livre le modèle et l'API** (portée dans le corps/la query `schedulePlanId`,
   GET résolu par portée, PUT/DELETE par portée). **PR2 (front, même jour) rend l'onglet Bien-être
   du wizard conscient de la période** : en mode période, le panneau règle et affiche la COPIE du
   plan, derrière le même ancrage que « Réserver »/« Mutualisation » (`PeriodAnchorGate`) ; la
   portée voyage dans les trois appels (GET query, PUT corps, DELETE query) et dans la clé de
   cache react-query, pour que saison et période ne partagent jamais leurs valeurs affichées. Une
   phrase de portée et, sous chaque règle, la valeur de SAISON en repère accompagnent l'écran —
   décision fondateur : en période, exactement le même choix qu'en saison (dur par défaut ou
   soft), sans bouton « revenir à la saison » ni indicateur calculé serveur (proposés puis
   explicitement écartés). Un gestionnaire peut désormais régler une reprise depuis l'écran.
6. **La structure (équipes/gymnases/coachs/contraintes permanentes) reste PARTAGÉE**
   (état vivant du club par saison) — **pas de duplication par version**. L'indépendance
   des versions passe par la **photo** (JSON, D2, existante) : chaque version COMPLETED
   garde la photo de ses conditions ; « charger » une photo restaure ces conditions (D3).
7. **L'étoile ★ = la version dont la photo est chargée dans le wizard** (l'espace de
   travail). Ce n'est PAS un pointeur de plan. Avant un chargement qui écraserait des
   données jamais photographiées (générées nulle part) → **avertissement explicite**
   (perte assumée si confirmée).
8. **Déblocage du cockpit** : le plan SEASON possède **≥ 1 version terminée**
   (COMPLETED ou FAILED). *Changement assumé vs aujourd'hui (c'était la validation).*
9. **Périodes sans plan** : `cutoff`/`mutualisation` restent des rappels calendrier —
   seuls SEASON/CLOSURE/HOLIDAY portent des plans.
10. **Suppressions** : les vacances (référentiel) ne se suppriment pas ; supprimer une
    **indisponibilité** supprime ses plans et leurs versions (cascade complète :
    slots, diagnostics, photos, réglages).
11. **Exports** : nom de fichier = **nom du Plan**.
12. **Défauts de nom** (à la création du plan, `SchedulePlanProvisioner` — source unique) :
    SEASON `Planning de la saison {saison}` · CLOSURE et HOLIDAY : **le titre de l'entrée** (2026-08-23 ; anciens gabarits supprimés, plans anciens non migrés) ·
    HOLIDAY `{label vacances} — {repère}`. Le **repère** se lit en clair : une fenêtre couvrant
    exactement une semaine calendaire (lundi→dimanche) donne `Semaine du 17 août 2026`, toute
    autre garde ses deux bornes `du 20 octobre 2025 au 2 novembre 2025`. Le nom du plan est
    ce que TOUT lit (titre, liste des plannings, export, renommage) — jamais celui d'une
    version, qui n'a pas d'identité produit (le sélecteur l'étiquette « V2 — 20 oct. 14:32 »).
    **Conséquence sur l'API** : `POST /api/schedules` n'exige pas de `name` — omis, le serveur
    nomme la version d'après son plan. Un client qui en invente un rouvre exactement le défaut
    que cet invariant ferme (trois le faisaient, et « Version de période » ressortait dans la
    liste des plannings et le nom du PDF).

### Rôle de `CalendarEntry` (conservée, amincie)

`CalendarEntry` = **le calendrier**, pas le planning. Elle porte le **FAIT** ; le Plan porte
la **RÉPONSE**. Découle de l'invariant fondateur « l'indisponibilité est déclarée d'abord,
puis le gestionnaire décide » : le fait existe avant tout plan, et parfois sans plan
(indispo ignorée, semaine blanche, event, cutoff).

- **Garde** : les événements (`kind=event`, marqueurs AG/tournoi) ; la déclaration des
  périodes (closure/holiday/cutoff/mutualisation) ; l'affichage cockpit + radar ; le lien
  vacances scolaires (`schoolHolidayId`, zone) et les relances ; les contraintes **datées
  du fait** (« Barros fermé ») qui alimentent le radar de conflits.
- **Perd** (part au Plan) : `overlayScheduleId` (pointeur), `teamSelectionInitialized`
  (seed), la relation 1:1 avec un planning (→ 0..N plans par entry), et l'accroche des
  réglages de période.

### Vocabulaire (fait foi — à reporter dans `docs/glossary.md` à l'implémentation)

| Terme | Définition |
|---|---|
| **Plan** | LE planning nommé (type + période + nom + pointeur). |
| **Version (Vn)** | Une résolution du solveur (`Schedule`) : « V3 - 10 juil. ». Jamais nommée par l'humain. |
| **Version choisie** | Celle que pointe le plan (= validée). |
| **Espace de travail** | Plan au pointeur null : on génère/compare des versions. |
| **★ / photo chargée** | La version dont la photo de structure est chargée dans le wizard. |
| **Réglages de période** | Coches équipes/contraintes + grille de gymnases (copie, #8) + modes de gymnase d'un plan CLOSURE/HOLIDAY + copie des 4 règles bien-être (lot bien-être-par-période, ci-dessous). |
| **Termes bannis** | *baseline*, *planningName*, *overlayScheduleId*, *liveContext*, statuts *VALIDATED/ARCHIVED*. |

### Règles inter-plans & consommateurs (complétées après sweep exhaustif des ~320 usages)

13. **Créer un plan CLOSURE/HOLIDAY exige que le plan SEASON soit pointé** (version
    choisie non-null) — reprend la règle actuelle « les plans secondaires attendent la
    validation du socle ».
14. **Toucher au socle quand des plans secondaires existent = destruction confirmée** :
    remettre le pointeur SEASON à null (re-travailler) ou le changer, alors que des plans
    CLOSURE/HOLIDAY existent → avertissement proportionné (« supprime N plannings ») puis
    **suppression de ces plans et de leurs versions** (reprend les règles 409+confirm
    reopen/validate actuelles ; « le premier plan secondaire fige le socle » reste vrai).

    ⚠️ **Amendement #8 (décision fondateur, 2026-07-24)**, verbatim : « le planning de
    saison est notre base donc on supprime TOUS les planning overlay ou holidays qui sont
    à venir. Il faudra les recommencer. Je supprime les plannings et donc les versions
    liées. » La portée a changé deux fois par rapport au D-b initial (ci-dessous) :
    (1) c'est désormais **toute période qui PORTE un plan, validé ou non**
    (`CalendarEntryRepository::findWithPlanNotStarted`, qui remplace `findWithOverlayByClubSeason` —
    lequel ne voyait que les plans **validés** et laissait vivre, sans le dire, la grille
    copiée d'une période adaptée (« Adapter ») mais jamais générée) ; (2) **seulement les
    périodes ENTIÈREMENT À VENIR** — pivot = la date de DÉBUT, « rien du passé, rien de ce
    qui est en cours » (décision fondateur 2026-07-16) :
    une période commencée est déjà annoncée aux coachs et à moitié jouée, la détruire au
    milieu coûterait plus que de la laisser finir sur l'ancien socle. Ce filtre de date
    solde la dette que cette spec signalait (`findWithOverlayByClubSeason` n'en avait aucun,
    donc rouvrir en mars détruisait l'overlay de Toussaint, une période passée). La destruction est totale : les versions, le **PLAN lui-même**, et tous les
    réglages qui s'y ancrent (grille copiée, réservations, modes gymnase
    `VenuePeriodOverride`, overrides d'équipes et de contraintes) —
    `OverlayManager::deletePeriodPlanForEntry`. L'**entrée de calendrier survit** : la
    période retombe « à traiter » au radar, à refaire. S'applique aux deux portes
    (`ValidateScheduleController` choisissant une autre version, `ReopenScheduleController`
    dépointant le socle) via le même 409 `overlays_exist` + `confirmDeleteOverlays: true`.
15. **Module matchs & radars de conflits** : ils **lisent le plan SEASON** (sa version
    choisie). Le comportement en espace de travail (pointeur null) sera **confirmé au
    cadrage du module matchs**. Consommateurs recensés : `MatchConflictDetector`,
    `FixtureConflictsController`, `CalendarEntryConflictsController`.
16. **Onboarding / mode guidé du wizard** : même règle dérivée que le cockpit (inv. 8) —
    le mode guidé s'arrête quand le plan SEASON a ≥ 1 version terminée (aujourd'hui gaté
    sur la baseline auto, qui disparaît).
17. **L'auto-★ reste, l'auto-pointeur meurt** : chaque génération COMPLETED du socle
    continue de pointer la ★ (sa photo EST la structure chargée) ; c'est l'ancrage
    automatique du **pointeur de plan** qui disparaît (inv. 2).
18. **Supprimés avec la reconstruction** (« on ne parle plus de baseline ») :
    `SetBaselineController` (désigner une référence sans valider n'a plus de sens —
    valider = pointer) ; le champ `Season.exportPdfUrl` (orphelin) — **l'export « du
    plan » = l'export de sa version choisie** (le fichier porte le **nom du Plan**,
    inv. 11 ; le lien d'export vit sur la version, comme aujourd'hui) ;
    `PurgeOverlaysCommand` → devient une purge de **plans** échus ; l'erreur 409
    `overlays_exist` renvoie des **plans**, plus des schedules.
19. **RGPD** : la table `plan` entre dans l'export club (`RgpdExportService`) et dans les
    purges (`SeasonDataPurger`, cascades) comme toute donnée club.
20. **Validation pré-solve** (`ValidateConstraintsController`) : filtre par **plan**
    (ses réglages + datées du fait), plus par entry — absorbe la dette P4-13.

## Mapping legacy → modèle (livré par la bascule du 2026-07-16)

Le legacy de la colonne de gauche **n'existe plus** : la bascule a déplacé tous ses
lecteurs et l'a supprimé dans le même commit. Le tableau se lit désormais comme une
table de correspondance pour relire du code ou des specs antérieurs.

| Legacy (supprimé) | Ce qui le remplace |
|---|---|
| Regroupement implicite `(seasonId, calendarEntryId)` | `Schedule.schedulePlanId` |
| `Season.baselineScheduleId` + auto-assignation 1er COMPLETED | `Plan(SEASON).chosenScheduleId`, posé par validation seule |
| `Season.planningName` | `Plan.name` |
| `CalendarEntry.overlayScheduleId` (1:1) | `Plan.chosenScheduleId` (N plans par entry via `Plan.calendarEntryId`) |
| `Schedule.status VALIDATED` + `ARCHIVED` (validation archive les frères) | supprimés — valider = pointer + **supprimer** les autres versions |
| `Season.liveContextScheduleId` | ★ conservée (version dont la photo est chargée) — hors du Plan |
| `Season.socleValidatedAt` (gate cockpit, sticky) | dérivé : plan SEASON a ≥ 1 version terminée |
| Réglages sur `calendarEntryId` (overrides, créneaux, seed flag) | re-keyés sur `planId` — **livré (C2/C3)** ; les contraintes datées du FAIT restent au calendrier (inv. 5 corrigé) |
| « V3 » dérivé de l'ordre de création | `Schedule.versionNumber` stocké (côté serveur ; les libellés du front dérivent encore de l'ordre de création — voir Questions ouvertes) |
| `GenerateScheduleHandler` branche sur `calendarEntryId` | branche sur `plan.type` (payload engine **inchangé** — zéro engine) — **livré (C4 PR1 lecture, PR2 write-path)** ; `Schedule.calendarEntryId` **supprimé** (colonne droppée, PR2) |

## Conséquences

- **Refactor structurant** (axe §7.1 planning lifecycle) : ValidateSchedule/Reopen/Regenerate,
  guards read-only, cockpit (radar, DayDialog, SeasonSchedulesModal), écran planning
  (sélecteur de versions, pinceau), GenerateStep, `/api/me`, exports, purges/cascades — à
  découper **en lots** avec NR phase1 à chaque lot.
- **Table `plan`** : club_id → TenantOwnedInterface + policy RLS FORCE (couvert
  automatiquement par `RlsIsolationTest`/`TenantOwnedInterfaceCompletenessTest`).
- **Migration V0** : liberté de reconstruction hors socle. Le flux SEASON est migré
  fidèlement (Plan créé par saison, pointeur depuis l'ex-baseline validée, nom depuis
  l'ex-planningName) ; les overlays existants peuvent être reconstruits.
- **Tests & fixtures à adapter** : `ValidateScheduleTest`, `ScheduleLifecycleGuardTest`,
  `ScheduleReadOnlyGuardTest`, `RegenerateTest`, `RegenerateFromVersionTest`, les fixtures
  d'import/démo (seed d'un Plan par saison) et le smoke-solveur (create → generate → poll
  passe par le Plan) ; blocking-tests inchangés sur le fond (tenant/season isolation).
- **Engine : zéro impact** — le contrat backend↔engine (payload, `CONTRACT_VERSION`) ne
  change pas ; seul l'endroit d'où le backend dérive le type de build (plan.type au lieu
  de calendarEntryId) bouge.
- **Ce que ça exclut** : plus AUCUN nom/état de plan porté par une version ; plus de
  pointage implicite ; plus d'`ARCHIVED` (une version non choisie est supprimée à la
  validation, point).

## Alternatives considérées

1. **Nom du plan sur `Schedule.name`** (tentée, PR #214) : rejetée — une version n'est pas
   le plan ; divergences baseline/dernière-version, 16 findings en 2 rounds.
2. **Conteneurs implicites nettoyés** (nom sur Season + CalendarEntry, pas d'entité) :
   rejetée par le fondateur — pattern non identifiable, 3 types traités différemment.
3. **Duplication de la structure par version** (chaque version possède SES équipes…) :
   rejetée — explosion de données, ambiguïté « quelle version j'édite », et la photo D2
   donne déjà l'indépendance recherchée pour ~35 ko/version. Le scénario fondateur
   (V3 = 25 équipes, V4 = 29, recharger V3 → 25, revenir V4 → 29) est couvert par les photos.

## Questions ouvertes (non bloquantes, à trancher au cadrage des lots)

1. ~~Chevauchement CLOSURE × HOLIDAY~~ **Tranché (fondateur, 2026-07-12)** : rien de
   spécial — l'invariant 4 tient (jamais deux plans qui se chevauchent). Un gym
   indisponible pendant une semaine de reprise se gère **dans le plan de reprise
   lui-même** (on y redéfinit les créneaux / une contrainte) — pas de plan CLOSURE
   concurrent, pas de mécanisme dédié.
2. **Découpage hebdomadaire (E1)** — précisé (fondateur, 2026-07-12), livré dans un lot
   ultérieur mais le modèle le porte dès maintenant :
   - un découpage qui implique 2 semaines **crée les 2 plans automatiquement** ; le
     gestionnaire traite le premier ;
   - le système voit alors que le fait X a « un planning validé + un planning en cours »
     et **notifie l'action restante** (radar) ;
   - **un fait est « ajusté »** quand le/les plans qui couvrent le changement sont tous
     créés **et pointés** — état **dérivé** sur le fait (aucun flag stocké : on compte
     les plans du fait dont le pointeur est null).
3. ~~Vieilles versions supprimées à la validation~~ **Tranché (fondateur, 2026-07-12)** :
   aucun besoin de comparaison post-validation — la suppression des versions non choisies
   à la validation est confirmée (la photo de la version choisie suffit).

## Découpage & avancement de la reconstruction

La reconstruction se livre en **4 lots (A→D)**, dans l'ordre, un PR par lot, chacun avec
validation du besoin → plan → code → NR phase1 → code-review → go utilisateur.

- **Lot A — Fondations (livré 2026-07-12)** : entité `SchedulePlan` + `Schedule.schedulePlanId`
  / `Schedule.versionNumber` (nullable pendant la transition), migration RLS `FORCE` + backfill
  des données existantes, **provisioning automatique** à la création (saison → plan SEASON ;
  schedule → lien plan + numéro de version) via `SchedulePlanProvisioner` — *le plan de
  période, lui, naissait alors de la première version ; le **lot C** l'a avancé au geste
  (voir la note ci-dessous), et `linkSchedule` ne fait plus que le chercher*, API **lecture**
  (`/api/schedule_plans`, `Schedule` expose `schedulePlanId`/`versionNumber`). **Strictement
  additif** : rien de l'ancien monde n'est retiré — `baselineScheduleId`, `overlayScheduleId`,
  les statuts `VALIDATED/ARCHIVED` et `planningName` **font toujours foi**. `chosenScheduleId`
  est backfillé au snapshot mais pas encore vivant. NR : `SchedulePlanProvisionerTest`
  (+ `RlsIsolationTest` / `TenantOwnedInterfaceCompletenessTest` qui couvrent la nouvelle table).
- **Lot B1 — fondations du pointeur (ADDITIF, livré 2026-07-16)** : le pointeur est
  **maintenu** (valider le pose, rouvrir le relâche, supprimer une version le libère) et
  la **numérotation devient monotone** (`SchedulePlan.lastVersionNumber` — une version
  supprimée ne rend jamais son numéro : déféré du lot A). **Rien ne lit encore le
  pointeur pour décider** : aucun comportement ne change (aucun test existant n'a bougé).
  `/api/me` expose le plan de saison (voir le point suivant).
  **Correction d'un vrai bug du lot A (déjà sur main)** : le backfill avait seedé
  `chosenScheduleId` depuis `baselineScheduleId`, or cette baseline est posée
  **automatiquement** à la 1re génération COMPLETED — ce n'est pas une validation. La
  migration **répare** le pointeur (`chosen` := la version `VALIDATED`, sinon `null`) et
  ne supprime **rien**.

- **Modèle de lecture du plan (livré 2026-07-16, ADDITIF)** : `/api/me` expose
  `seasonPlan { id, name, chosenScheduleId, hasFinishedVersion }` — LE calendrier de base
  de la saison. **Lecture seule.** Rien ne bascule : le legacy reste exposé et reste la
  vérité. But : que la bascule n'ait plus qu'à **déplacer des lecteurs**, pas à inventer
  son contrat en même temps.
  **Le renommage part AVEC la bascule** (pas avant) : tant que `Season.planningName`
  possède le nom et le pousse sur le plan (`syncSeasonPlan` à chaque édition de saison),
  un `PUT` sur le plan serait un **second écrivain** — rename non durable, gate SEC-07
  contournable par le `PUT /api/seasons` qui écrit le même champ, et contraintes
  divergentes (varchar 180 vs 120). C'est la demi-migration en miniature. Le nom devient
  éditable sur le plan dans le commit qui **supprime** `planningName` (et qui devra
  backfiller `schedule_plan.name` depuis `planningName` une dernière fois).

- **Limites assumées du lot B1** (revues, tracées, à reprendre au lot de bascule) :
  - `SetBaselineController` déplace encore la baseline **sans toucher au pointeur** →
    les deux peuvent diverger. Sans effet aujourd'hui (**zéro appelant** : ni le
    frontend ni aucun service ne l'appelle) ; la bascule le supprime (inv. 18).
  - Si le plan disparaît en cours de requête (reset de saison concurrent), la version
    est laissée **non liée** (`schedulePlanId` null) plutôt que de lever : lever
    fermerait l'EntityManager et transformerait une création qui marchait en 500. Le
    lien est nullable pendant toute la transition ; le lot D le rendra NOT NULL et
    devra backfiller ces rares orphelins.
  - Le seed du compteur part du MAX des versions **survivantes** : les numéros des
    versions supprimées AVANT la migration sont irrécupérables (une réutilisation
    résiduelle possible, une seule fois, puis plus jamais).
  - `app:purge-overlays` attrape-et-continue : une transaction en échec ferme
    l'EntityManager et fait échouer le reste du passage. Pré-existant (le flush était
    déjà dans la boucle) ; à traiter avec la commande, hors scope B1.

- **Lot B-bascule — LA LEÇON, puis la bascule (livrée 2026-07-16)** :
  une **demi-migration est structurellement impossible**. Le legacy n'était PAS un miroir
  passif : `baselineScheduleId`/`socleValidatedAt` étaient **lus pour décider** par ~16
  fichiers — radar de conflits matchs (`FixtureConflictsController`,
  `CalendarEntryConflictsController`, `MatchConflictDetector`), routing et mode guidé
  (`AuthGuard`, `CockpitPage`, `WizardLayout`), bannière et atterrissage
  (`BaselineBanner`, `PlanningPage`, `PlanningToolbar`, `seasonPlannings`),
  `GenerateScheduleHandler` (auto-baseline). Une tentative de basculer les seules gardes
  backend en gardant le legacy vivant a produit **deux vérités divergentes** et ~15
  défauts confirmés en 4 rounds de revue (gardes destructives désarmées, baseline
  pendante ⇒ zéro conflit match détecté, V1 indélébile…). La bascule a donc déplacé
  **tous** les consommateurs et **supprimé** le legacy dans le même commit — une seule
  vérité, par construction.

  Ce qu'elle a emporté : valider = pointer **+ supprimer les autres versions** (inv. 1) ;
  rouvrir = dépointer (inv. 2) ; aucun pointage automatique (inv. 2) ; gate des plans
  secondaires et du module matchs sur le pointeur (inv. 13) ; destruction confirmée
  (inv. 14) ; déblocage cockpit sur « ≥ 1 version terminée », donc insensible au pointeur
  (inv. 8/16) ; le nom sur le plan, renommé par `PUT /api/schedule_plans/{id}` (inv. 12) ;
  suppression de `SetBaselineController` (inv. 18) ; disparition de `Season.baselineScheduleId`,
  `socleValidatedAt`, `planningName` et des statuts `VALIDATED`/`ARCHIVED`.

  **Deux gardes s'appuyaient en silence sur le statut `VALIDATED`** pour refuser d'écraser
  le planning en vigueur (`/regenerate`, `/regenerate-from`), plus le bouton « Valider » du
  front : supprimer le statut les désarmait. Elles ont été restaurées sur le pointeur dans
  le même lot — c'est exactement la classe de défaut que l'atomicité sert à rendre visible,
  et ce sont les tests qui l'ont attrapée.

  **Effet de bord assumé** : créer un plan secondaire sans socle en vigueur rend **409**
  (avant : 422 sans baseline, 409 sans socle). Les deux conditions legacy fusionnent en une
  seule, donc un seul code — celui du module matchs, même garde et même message actionnable.

  Nouveau champ de lecture **`Schedule.isChosen`** (batché par `ScheduleStateProvider`) : le
  statut ne pouvait pas répondre « cette version-ci est en vigueur » pour un overlay, dont le
  pointeur vit sur le plan de sa période et n'est pas visible depuis `/api/me`.

- **Lot C** — réglages de période & génération pilotés par plan. **C1 livré (2026-07-17)** :
  **LE PLAN NAÎT DU GESTE** — *décision fondateur, à lire comme un invariant de plein droit* :
  **un plan naît en réponse à un événement du calendrier**. Le plan SEASON naît avec la saison
  (inv. 3) ; le plan CLOSURE/HOLIDAY naît au geste « ajuster une période de vacances / un souci
  du calendrier » — c'est-à-dire à la **création de la `CalendarEntry`** — et c'est la **seule**
  façon de créer les deux autres types. Le lot A le faisait apparaître à la **première version** :
  trop tard, puisque les réglages de la période (inv. 5) se saisissent **avant** toute génération
  et doivent s'accrocher à un plan existant. Trois conséquences structurelles :
  - **`linkSchedule` ne crée plus jamais un plan de période, il le cherche.** Un second site de
    naissance laisserait passer inaperçu un plan manquant — et masquerait le vrai défaut.
  - **La naissance est atomique avec celle de l'entrée** (une transaction englobe les deux dans
    `CalendarEntryStateProcessor`). C'est la contrepartie obligatoire du point précédent : le
    self-heal de `choose()` ne peut plus réparer une période sans plan, donc une période sans
    plan ne doit pas pouvoir exister. En cas d'échec, on préfère ne pas créer la période.
  - **L'identité d'une période qui porte un plan est GELÉE** (422) : ni type, ni fenêtre, ni
    `kind`. Une closure/holiday ayant toujours un plan depuis ce lot, cela revient à dire que
    **le type et les dates se choisissent à la création** et se corrigent en supprimant la
    période puis en la recréant — ce que l'UI impose déjà : elle n'expose **que POST et DELETE**
    sur les périodes, jamais PUT (chaque type naît de son propre geste dédié). Ce choix rend
    inatteignables **par construction** deux défauts qu'une machinerie de synchronisation avait
    tenté de réparer sans y parvenir (3 rounds de code-review) : la rétrogradation qui détruit
    un plan sous ses versions, et la fenêtre du plan qui se périme quand on corrige les dates de
    sa période. Une période **sans** plan (cutoff/mutualisation) reste librement promouvable —
    la promotion est un geste, elle crée le plan.
  - **Supprimer la période supprime son plan et ses versions** (cascade existante,
    `deleteEntryAndCascade`) : l'incident est annulé, la réponse qu'on lui avait apportée n'a
    plus d'objet. La destruction passe par une **confirmation explicite** côté cockpit — c'est
    le seul chemin destructeur, et il est voulu.

  Le flag de seed `teamSelectionInitialized` a suivi les réglages sur le plan (inv. 5).
  **C2/C3 livrés** (re-keyage `calendarEntryId` → `planId` des réglages et calques ; les
  contraintes datées du FAIT restent au calendrier).

  **Amendement 2026-07-24 — LE PLAN NAÎT DU GESTE D'ADAPTER** (*décision fondateur, durcit
  C1*) : « geste » signifie **geste EXPLICITE d'adaptation**, pas la simple existence d'une
  période. Matérialiser une vacance (ancrage pour la découpe) ou signaler une indisponibilité
  ne crée **rien** — le radar lit l'impact par les contraintes datées, sans plan. Les gestes,
  limitativement :
  - **cocher une semaine au picker** → l'entrée-SEMAINE naît avec son plan (rail 1 entrée = 1
    plan, atomique, inchangé) ;
  - **« Adapter » un bloc ou une fermeture** → `POST /api/schedule_plans {calendarEntryId}`
    (management-gated SEC-07, idempotent — la période a déjà son plan → il est rendu tel
    quel ; 422 sur cutoff/mutualisation et sur une mère découpée).
  Conséquences structurelles :
  - **une période closure/holiday peut exister SANS plan** (jamais adaptée) — elle n'apparaît
    ni dans « Tous les plannings » ni « en cours » au radar : c'est une carte « à traiter » ;
  - **la découpe supprime le plan-bloc de la mère** (0 version garanti — découper une mère
    générée reste refusé) **avec ses réglages ancrés** : chaque semaine repart de la structure
    saison. Idempotent au 2ᵉ enfant ;
  - **on ne bascule jamais bloc↔semaines automatiquement** (symétrie fondateur) : revenir au
    bloc = supprimer soi-même chaque semaine (cascade : son plan part avec), puis re-Adapter —
    la mère n'ayant plus d'enfants, le geste bloc redevient accepté ;
  - **anti-résurrection** : un PUT ne provisionne plus jamais (la « promotion » cutoff→holiday
    ne mint plus rien — le scénario n'existe pas dans l'UI, ruling fondateur : « une coupure ne
    devient pas des vacances ») ; le gel d'identité s'étend aux mères découpées (**un plan OU
    des semaines-enfants** gèlent type/fenêtre/kind).
  Gardé par `PeriodPlanBirthTest` + `WeekChildEntryTest` (phase1, réécrits avec l'amendement).

  ⚠️ **Amendement D3 v1 (décision fondateur, 2026-09-04) — le gel de FENÊTRE se lève pour UNE
  racine CLOSURE sans semaines-enfants** : jusqu'ici, « l'identité d'une période qui porte un plan
  est GELÉE » couvrait le type, le `kind` ET la fenêtre en bloc — se tromper de dates imposait de
  supprimer la période (son plan et ses versions partent avec) puis de tout redéclarer. Retour
  terrain (P2-57, gymnase indisponible « jusqu'aux vacances », travaux finis plus tôt) : c'était
  disproportionné pour un geste qui ne change QUE deux dates. Le modèle mental qui déverrouille ce
  cas précisément : **un plan est un gabarit hebdomadaire SANS dates + une fenêtre** — la fenêtre
  coupe le gabarit à la construction (`TrainingCalendarContext`, `VenueClosureDays` recalculés à
  chaque `build`), rien n'est ancré aux dates elles-mêmes. Déplacer la fenêtre d'un plan qui n'a
  ni mère ni enfants n'orpheline donc STRUCTURELLEMENT rien — contrairement à « supprimer puis
  recréer », qui détruisait le plan et ses versions pour un simple déplacement de bornes.
  Précisément : `CalendarEntryPeriodType::CLOSURE`, `parentEntryId === null`, zéro semaine-enfant
  (`hasWeekChildren`) — **PUT re-date dans les deux sens** (`CalendarEntryStateProcessor::
  updateEntityFromInput` dégèle `windowFrozen` pour ce seul cas ; `processPut` orchestre
  `prepareClosureRootRedate`/`applyClosureRootRedate`). Le geste, sous le MÊME verrou de scope que
  la naissance d'un plan : (1) la garde d'unicité de fenêtre (`PeriodWindowUniquenessGuard::
  assertWindowFree`, famille exclue) tranche AVANT toute mutation — 409 franc, jamais de re-datage
  à moitié fait ; (2) le parent applique le PUT (dates comprises) ; (3) `SchedulePlanProvisioner::
  resyncPeriodPlanWindow` déplace la fenêtre du plan (SQL direct, `start_date`/`end_date`,
  `version + 1`) ; (4) les contraintes `venue_closed` NÉES du même geste (config `startDate`/
  `endDate` == l'ANCIENNE fenêtre exactement) suivent — une fermeture datée plus finement par le
  gestionnaire ne bouge pas ; (5) le suffixe « — du … au … » du titre se recale s'il y était, puis
  `renamePeriodPlanIfStillNamed` recale le nom du plan s'il valait encore l'ancien titre (inv. 12
  intact : un renommage manuel reste souverain). La péremption des versions `COMPLETED` n'a rien
  de neuf à écrire : `ResourceChangeStaleScheduleListener` écoutait déjà le `postUpdate` de
  `CalendarEntry`. **Tout le reste de l'identité reste gelé, pour TOUS les autres cas** : une
  racine HOLIDAY (liée au référentiel des vacances scolaires), une mère découpée, une
  semaine-enfant, et — même pour une racine CLOSURE redatable — `kind`/`periodType`/
  `schoolHolidayId` restent 422. Message 422 distinct pour ce cas (« ses dates, elles, restent
  modifiables ») vs le message générique des autres identités gelées
  (`CalendarEntryStateProcessor.php:108-131`). NR : `Security/PeriodRedateTest` (8 cas) +
  `CalendarEntryApiTest::testPeriodWithOverlayCannotMutateIdentity` (le cas fenêtre est passé de
  422 à 200) + scénario Behat `plan-de-periode-en-overlay.feature` (« Je re-date l'incident »).
  **PR-1 backend seul livré ce jour** ; le geste d'édition à l'écran (PR-2, avec passe design)
  reste à faire — voir `specs/evolution/plannings-bccl-2026-08-31.md` D3.
- **Lot C4** — LE SOCLE SE LIT DU PLAN, `Schedule.calendarEntryId` disparaît. Le champ était
  redondant avec `plan.calendarEntryId` (doublon d'ancre nullable — la classe de bug de C2/C3).
  Découpé en **3 PR**. **PR1 livré (2026-07-17)** : `plan.type === SEASON` remplace
  `null === Schedule.calendarEntryId` sur **tous les lecteurs backend** — décision « socle ? »
  (`GenerateScheduleHandler`, `Generate`/`Regenerate`/`RegenerateFromVersion`/`ValidateScheduleController`,
  `ScheduleStateProcessor`) ET navigation entrée↔versions (`OverlayManager`, `PurgeOverlaysCommand`),
  via les helpers `SchedulePlanProvisioner::{isSeasonSchedule,periodEntryIdOf,planIsSeason,planScopeOf}` ;
  un champ **`planType`** dérivé + batché apparaît sur `ScheduleResource`. L'absence de plan est
  un **3e état qui LÈVE** (ruling fondateur 2026-07-17 : *une version sans plan n'existe pas* ;
  si elle existe, on la **purge**, jamais on ne la traite en socle — sinon on générerait la
  saison avec les contraintes d'une période, en silence). NR : `ScheduleSocleFromPlanTest`
  (chemin de prod, vérifié en cassant le code — P4-21).
  **PR2 livré (2026-07-17)** : `Schedule.calendarEntryId` **supprimé** (colonne + champ de
  sortie API + entité). Le **contrat de création** passe de « pour cette période » à « SOUS ce
  plan » — `ScheduleInput.schedulePlanId` (omis ⇒ plan SEASON), le back valide que le plan est
  du club ; `linkSchedule` **ne résout plus le plan, il NUMÉROTE** (le plan est posé par le POST
  ou par le regenerate) ; le self-heal de `choose()` **disparaît** (dette B1 soldée : une version
  sans plan ne se répare pas, elle n'existe pas). Le **front** dérive `isOverlay` de `planType`
  et regroupe les versions par `schedulePlanId` (planning + cockpit + wizard) ; la création
  overlay envoie le `planId` (résolu via `usePeriodAnchor`, déjà en main depuis C2/C3). **Restent**
  au **lot D** (PR3) : `NOT NULL` sur `schedule_plan_id`/`version_number`, purge des orphelins,
  drop `CalendarEntry.overlayScheduleId`.
- **Lot D** — nettoyage résiduel, découpé en **2 PR** (D-a puis D-b).
  - **D-a livré (PR3, 2026-07-17)** : `schedule.schedule_plan_id` + `version_number` deviennent
    **NOT NULL** — l'invariant « une version sans plan n'existe pas » est désormais **SCELLÉ EN
    BASE** (précédé d'une purge défensive des orphelins ; 0 en base — filet). Le choix est
    **non-nullable STRICT** : les propriétés PHP passent à `string`/`int` (une version orpheline
    est **inreprésentable**), toutes les gardes null mortes tombent (`requirePlanRow`, `choose`,
    `linkSchedule`, `buildForOverlay`), et le NR teste le sceau (schéma) + le plan-disparu qui
    lève. `linkSchedule` numérote AVANT le flush-INSERT (`version_number` sentinelle 0 en mémoire,
    ≥ 1 en base) et **lève** si le plan disparaît (au lieu de laisser un orphelin). Coût assumé :
    ~26 fichiers de seeds re-passés en **link-avant-persist** (le `NOT NULL` refuse un INSERT sans
    plan, indépendamment du type PHP).
  - **D-b livré (PR4, 2026-07-18) — CLÔT L'ADR-0002** : `CalendarEntry.overlayScheduleId` **supprimé**
    (champ + colonne droppée). **Décision fondateur (2026-07-18)** : ce pointeur était un **vestige du
    palier B** (avant le Plan) redondant avec le modèle ; « période → version active » se dérive
    désormais de son plan, **binaire** : plan **validé** (`chosenScheduleId` non-null) → on **MONTRE**
    la version choisie (consultation) ; plan **non validé** → on **AJUSTE** (wizard), on ne montre
    **jamais** une version non validée. Changement de comportement cockpit assumé (avant, le pointeur
    était posé dès la création — une version non validée s'affichait). Source unique côté back :
    `SchedulePlanProvisioner::chosenOfPeriodPlan(entryId)` (miroir période de `chosenOfSeasonPlan`).
    `OverlayManager` **simplifié** (plus de clear/promote/pointeur inverse). Le confirm-delete
    (`findWithOverlayByClubSeason`) ne compte que les plans secondaires **validés** (réels) —
    *périmé par l'amendement #8 (2026-07-24) ci-dessus : la méthode a été remplacée par
    `findWithPlanNotStarted`, dont la portée est plan validé OU non, périodes non commencées*. Cross-stack :
    entité + migration + 4 controllers + OverlayManager + repo + resource + processor ; cockpit
    (RadarPanel/DayDialog dérivent de `chosenScheduleId`) + wizard (GenerateStep dérive la version à
    reprendre de `schedulePlanId`). NR (`ScheduleSocleFromPlanTest`) : période validée expose la
    version choisie / non validée n'expose rien ; colonne absente du schéma.
  - La ★ (`liveContextScheduleId`) **reste par décision** (inv. 17) — c'est l'auto-pointeur qui est
    mort, pas la ★.
  - **Dette C4-PR1 soldée en D-a** : la purge des orphelins du lot D-a (`NOT NULL`) emporte tout overlay
    legacy non lié (`schedulePlanId` null) ; `deleteOverlayForEntry` et l'aperçu de `PurgeOverlaysCommand`
    collectent exclusivement par `schedulePlanId`, redevenus exhaustifs sans lecteur `calendarEntryId`.
- **Lot #8 — une période possède sa grille (livré 2026-07-24)**, hors de la numérotation A→D (post-ADR) :
  bascule du modèle union (saison ∪ créneaux prêtés de la période) vers un modèle **copie** — voir
  l'amendement de l'inv. 5 ci-dessus (`copySeasonalSlots`, table `venue_period_override`,
  `OrphanPinGuard` 422). Emporte aussi l'amendement de l'inv. 14 : la reprise/re-validation du socle
  détruit désormais **tout plan de période pas encore commencée** (validé ou non), pas seulement les plans validés
  — combler un trou où une période « Adaptée » mais jamais générée survivait silencieusement à un
  changement de socle avec une grille copiée périmée. NR : `VenueTrainingSlotApiTest` (chevauchement
  borné à une couche), `CascadeDeleteApiTest` / `ValidateScheduleTest` / `ReopenScheduleTest` (portée
  élargie de la destruction, survie des périodes en cours et passées).
- **Lot bien-être-par-période — une période possède ses 4 règles (LOT SOLDÉ 2026-08-18, PR1
  backend + PR2 front)**, hors de la numérotation A→D (post-ADR), même patron que le Lot #8
  (copie plutôt qu'union) — voir l'amendement de l'inv. 5 ci-dessus (`ImplicitRuleSetting.schedulePlanId`,
  `SchedulePlanProvisioner::materializeForPlan`, DELETE en portée plan = re-copie). **PR2 (front)**
  rend l'onglet Bien-être du wizard conscient de la période (`PeriodAnchorGate`, portée dans les
  trois appels + la clé de cache react-query) : un gestionnaire règle désormais SES règles de
  reprise depuis l'écran, en mode socle comme en mode période. NR : `Security/PeriodPlanBirthTest`,
  `CrossStack/ImplicitRulePayloadParityTest` (invariant RETOURNÉ — l'un et l'autre gardaient
  jusque-là l'ancien invariant « même bloc season-scopé », desormais falsifié dans les deux sens
  sur la copie ET le repli legacy), `Integration/Service/ResourceChangeStaleScheduleTest` (péremption
  resserrée : un réglage de PLAN ne périme que SON plan ; un réglage de SAISON garde club+saison
  tant que le repli legacy existe quelque part — surmarquage conservateur assumé, dette notée en
  roadmap).
- **Lot P2-37 — l'indisponibilité totale d'un gymnase est DÉRIVÉE, jamais réglable (PR1 backend +
  miroir livrée 2026-08-18 ; PR2 front — UI période + récapitulatif — reste ouverte, roadmap)**,
  hors de la numérotation A→D (post-ADR). Retour terrain BCCL (gymnase fermé pour travaux) :
  l'amendement #8 posait qu'un épinglage qui ne retombe sur aucun créneau de la grille de la
  période bloque la génération en 422 — mais une fermeture **datée** (`Constraint` `venue_closed`
  portée par la `CalendarEntry`, inv. 5) couvrant TOUTE la fenêtre du plan n'était traitée par
  `OrphanPinGuard` que jour par jour, comme n'importe quelle fermeture partielle : un épinglage
  restait bloquant sur un gymnase pourtant inerte de bout en bout, et rien n'empêchait de
  « réactiver » ce même gymnase via `VenuePeriodOverride`. Nouvelle maison unique
  `App\Service\PlanVenueClosures` (ce que les fermetures datées font à un plan — résout l'entrée
  du plan, semaines-enfant comprises via `datedConstraintSourceId`) + `VenueClosureDays::fullyClosedVenueIds` :
  un gymnase est ENTIÈREMENT fermé ssi l'UNION de ses dates fermées (toutes fermetures confondues)
  couvre la fenêtre entière — **calculé, jamais stocké** : deux fermetures qui se RELAIENT sans
  qu'aucune ne couvre seule la fenêtre ferment quand même le gymnase, et une fermeture éditée
  après coup se répercute seule, sans synchronisation ni état en base. Trois conséquences :
  (1) `OrphanPinGuard` traite désormais un gymnase entièrement fermé comme un gymnase DÉSACTIVÉ
  (même doctrine que P3-20) — non bloquant, son épinglage est inerte ; une fermeture **PARTIELLE**
  (un seul jour fermé d'un gymnase par ailleurs ouvert) **reste bloquante** — là, la séance
  serait sinon replacée ailleurs en silence, précisément ce que l'amendement #8 existe pour
  empêcher ; son message nomme désormais aussi l'ÉQUIPE épinglée ; (2) `VenuePeriodOverrideStateProcessor`
  refuse en 422 tout POST/PUT **et DELETE** sur un gymnase entièrement fermé — le DELETE compte :
  sans lui, supprimer un override manuel antérieur rouvrirait le gymnase par la petite porte ;
  (3) `ReservationStateProcessor` refuse en 422 toute nouvelle réservation sur un gymnase
  entièrement fermé ou un jour fermé, en nommant la cause — les réservations DÉJÀ posées ne sont
  **ni supprimées ni déplacées** (décision fondateur : « on ne fait pas de modification passive,
  on alerte »). L'alerte elle-même est un nouveau prédicat partagé « réservation non servie »
  (`OrphanPinGuard::unservedReservationIds` ⇄ miroir `orphanReservations.ts::unservedReservationIds`,
  parité gardée par `OrphanReservationsMirrorParityTest`) — il existe des deux côtés mais **n'est
  câblé à aucun écran** : c'est tout l'objet de la PR2. `/api/calendar-entries/{id}/conflicts`
  sert en plus `fullyClosedVenueIds` (niveau GYMNASE, pas un drapeau par fermeture — un drapeau ne
  pourrait pas exprimer le cas des deux fermetures relayées). Refus serveur en
  `UnprocessableEntityHttpException` (pas `ValidationException` d'API Platform, qui ne remonte pas
  son message dans le corps de la réponse — même patron que `AssertsSchedulePlanExistsTrait`,
  déjà en vigueur). Zéro migration, zéro moteur, `CONTRACT_VERSION` inchangé.
- ⚠ **Le refus 422 de réactivation ci-dessus (point 2) est SUPPLANTÉ le 2026-08-18 (soir) — lot
  « indispo gymnase informative » (PR1 backend, hors de la numérotation A→D, post-ADR)**, décision
  fondateur : une fermeture datée ne verrouille plus le réglage, elle **PRÉ-REMPLIT** un défaut que
  la coche du plan peut contredire jour par jour. `VenuePeriodOverride.mode` devient NULLABLE
  (une ligne peut n'exister que pour son masque) et gagne `dayOverrides` — masque manuel tri-état
  SPARSE, jour ISO 1..7 → nouvel enum `VenueDayState` (OPEN\|CLOSED). La composition (incident ×
  masque) gagne une méthode dans la même maison unique, `PlanVenueClosures::effectiveStateForPlan/Entry`
  (rend `{disabledVenueIds, effectiveClosedWeekdaysByVenue, defaultClosedWeekdaysByVenue,
  manualClosedWeekdaysByVenue, fullyClosedVenueIds, summaries}`), partagée par `PeriodConstraintSelector`
  (gate == payload par construction), `ScheduleConstraintBuilder`, `OrphanPinGuard` (jour rouvert
  OPEN non bloquant, jour décoché CLOSED bloquant, fermé-total effectif toujours inerte — doctrine
  P2-37 point 1 / P3-20 préservée), `ReservationStateProcessor` (422 au grain jour effectif) et
  `CalendarEntryConflictsController` (sert l'état effectif avec provenance `default-incident`\|`manual`).
  **POST/PUT/DELETE `VenuePeriodOverride` sont de nouveau ACCEPTÉS** sur un gymnase entièrement
  fermé — DELETE purge mode ET masque. Migration `Version20260818120000`, à la main, additive.
  Zéro moteur, `CONTRACT_VERSION` inchangé. PR2 (front — coches jour, bandeau, gestes) reste
  ouverte, `specs/evolution/roadmap.md` (P2-37).
- **Lot P2-38 — une fermeture de gymnase est un FAIT TRANSVERSAL (PR1 + PR2 backend + PR3 front,
  LOT SOLDÉ, les 3 PR livrées 2026-08-18 — l'item a quitté `specs/evolution/roadmap.md`)**, hors de
  la numérotation A→D (post-ADR), une couche au-dessus de P2-37 : P2-37 dérivait correctement le
  fermé-total d'un plan à partir des SEULES datées de SA PROPRE entrée porteuse
  (`CalendarEntry::datedConstraintSourceId`) — insuffisant dès qu'une fermeture est déclarée sur
  une AUTRE entrée dont la fenêtre recoupe le plan. Cas terrain BCCL : « Matéo en travaux »,
  porté par la période racine (17/08→30/09), ne fermait pas la semaine de reprise du 17/08 dont
  la mère est « Vacances d'Été » — le solveur pouvait placer des séances dans un gymnase en
  travaux, en silence. `PlanVenueClosures::forEntry` lit désormais TOUTES les fermetures datées
  (`venue_closed`) du club+saison (`ConstraintRepository::findDatedFacilityByClubSeason`),
  chacune bornée à la fenêtre de SA PROPRE entrée porteuse pour le repli legacy — jamais à la
  fenêtre du plan consommateur, sinon un `config` sans dates fermerait TOUT ce plan — puis
  croisée avec la fenêtre du plan construit. `ScheduleConstraintBuilder::buildForPeriodPlan` et
  `CalendarEntryConflictsController` dérivent désormais leurs jours fermés de cette MAISON
  UNIQUE plutôt que de leur propre sélection de période — deux lecteurs qui divergeraient sur les
  mêmes fermetures, c'est un planning que le payload autorise et que le garde refuse. Quatre
  décisions fondateur : **(R1) le socle n'a pas de dates** — un plan SEASON n'est jamais touché
  par une fermeture ; **(R4) la transversalité est bornée aux FERMETURES** (`venue_closed`) —
  les autres contraintes datées gardent le périmètre par-entrée actuel, « on verra si un cas réel
  se présente » ; **priorité au périmètre le plus fin** — la reprise gouverne, l'incident y entre
  (pas l'inverse) ; **le marqueur de fraîcheur peut se déclencher même quand rien n'est
  réellement impacté** — surmarquage conservateur assumé : une datée portée par l'entrée A périme
  les COMPLETED du club+saison, y compris ceux d'autres entrées, épinglé par
  `ConstraintChangeStaleScheduleTest`. NR : `Integration/ScheduleConstraintBuilderOverlayTest`
  (le payload — cas réel + falsification hors-fenêtre), `Integration/Service/PlanVenueClosuresTest`
  (la maison directement), `Integration/Service/OrphanPinGuardTest` (fermé-total transversal
  inerte, fermeture partielle transversale toujours bloquante), `Integration/Service/ConstraintChangeStaleScheduleTest`
  (le surmarquage). Zéro migration, `engine/**` non touché, `CONTRACT_VERSION` inchangé. **Ce que
  cette PR1 ne fait PAS** : elle ne pose aucune garde à la CRÉATION d'un plan/d'une entrée —
  c'est l'objet de la PR2 ci-dessous.

  **PR3 — le même invariant devient aussi une LECTURE (2026-08-22).** Un invariant qui ne sait que
  REFUSER se découvre trop tard : l'écran proposait des semaines dont la création serait rejetée.
  `GET /api/planned-windows` sert désormais les fenêtres déjà gouvernées, **depuis le SQL de la
  garde elle-même** (`governingWindows()`, dont `assertWindowFree()` n'est plus qu'un cas
  particulier — un seul `SELECT` dans le service). L'invariant a donc deux faces qui ne peuvent pas
  diverger : il refuse, et il dit à l'avance ce qu'il refuserait. ⚠ La face lecture ne REMPLACE
  jamais la face refus — deux gestionnaires en course, un cache périmé, un appel d'API direct
  retombent sur le 409. Gardé dans les deux sens par `Security/PlannedWindowsParityTest`.

  **PR2 — une seule planification par fenêtre (livrée 2026-08-18)** tient enfin l'invariant 4 à
  la NAISSANCE d'un plan de période : `App\Service\PeriodWindowUniquenessGuard::assertWindowFree`,
  appelée aux **deux seuls sites de naissance** — le geste « Adapter »
  (`SchedulePlanStateProcessor::processPost`) et la création d'une entrée-semaine qui naît avec
  son plan (`CalendarEntryStateProcessor::processPost`) — refuse en **409**
  (`App\Exception\WindowAlreadyPlannedException`, code machine `window_already_planned`) tout
  plan dont la fenêtre recoupe (inclusion OU chevauchement partiel) celle d'un AUTRE plan de
  période du club+saison, **dans les deux sens** (peu importe lequel des deux gestes arrive en
  second). Règle du fondateur, verbatim : « un overlay d'incident ne touche JAMAIS une semaine
  de vacances ». La garde est prise **dans le verrou de scope** déjà posé par les deux
  processors (`lockPlanScope`) : deux gestes concurrents sur des fenêtres qui se recoupent ne
  peuvent pas passer tous deux devant un contrôle vide. **Rien n'est supprimé ni rétréci
  automatiquement** — le refus NOMME le plan déjà en place (le TITRE que le gestionnaire a
  écrit sur son `CalendarEntry`, plus sa fenêtre en clair via
  `SchedulePlanProvisioner::windowLabel`) et invite à modifier ce planning, à le supprimer, ou à
  découper la période en semaines (geste qui EXISTE déjà, P2-36) — le geste destructif reste au
  gestionnaire. Les deux chevauchements **légitimes** sont exclus par un seul prédicat, l'ancêtre
  racine `COALESCE(parent_entry_id, id)` : une semaine vit DANS sa mère, et deux semaines sœurs
  partagent leur mère (leur non-chevauchement mutuel reste gardé par
  `CalendarEntryStateProcessor::assertValidWeekChild`, non dupliqué ici). Le **FAIT reste libre** :
  déclarer une fermeture (créer l'entrée/la contrainte datée) par-dessus une période déjà
  planifiée n'est **jamais** refusé — c'est le PLAN d'adaptation qu'on borne, pas la vérité sur
  le gymnase (et depuis la PR1, cette fermeture s'applique quand même au plan qui recoupe ses
  dates). Le 409 porte sa charge structurée (`code`, `error`, `entryId` du plan en conflit) via
  un `kernel.exception` listener (`WindowAlreadyPlannedListener`, priorité 256, avant Sentry et
  l'ExceptionListener d'API Platform) — un State Processor seul ne rend que `{detail, status}`.
  NR : `Security/PeriodPlanBirthTest` (step bloquant existant, §4 CLAUDE.md) — refus dans les
  deux sens, recouvrement PARTIEL nommé, et trois témoins (semaine dans sa mère jamais refusée,
  deux périodes disjointes s'adaptent toutes les deux, déclarer une fermeture par-dessus une
  période planifiée reste libre). Zéro migration, `engine/**` non touché, `CONTRACT_VERSION`
  inchangé.

  **PR3 — front (livrée 2026-08-18) ferme le lot.** Le refus 409 s'affiche désormais **à
  l'endroit du geste** — `WindowAlreadyPlannedNotice`, monté dans `DayDialog` (racine sans plan,
  bloc vacances hors picker), `RadarPanel` (même hook partagé) et `WeekPickerDialog` (dans le
  picker) — au lieu d'être avalé par le filet global des mutations et remplacé par un toast
  générique. `cockpit/api.ts` traduit le 409 structuré en `WindowAlreadyPlannedError` typée ; le
  **message serveur est repris tel quel** (il nomme déjà la période en place, sa fenêtre, les
  trois issues) — le front n'en redérive rien (règle d'or, `frontend/AGENTS.md`). Un raccourci
  « Ouvrir le planning en place » navigue vers l'`entryId` reçu ; **aucun bouton de suppression**
  — le geste destructif garde sa maison (`DeletePlanningButton`). Le hook `useWeekAdapt`
  (et `useCreatePeriodPlan`/`useCreateWeekChildren`) possède son feedback (patron
  `ownSlotEditFeedback`, `planning/queries.ts`) : il tait ce refus typé, tout autre échec
  continue de remplacer le toast du filet global. Détail : `specs/courantes/etat-des-lieux.md`
  (trace datée), `specs/courantes/accueil-cockpit-temporel.md` §5bis. **P2-38 est intégralement
  livré (3 PR), l'item a quitté `specs/evolution/roadmap.md`.**

- **Lot P2-41 — le SEGMENT devient l'unité hors socle (PR-B backend livrée 2026-08-18, l'item
  RESTE ouvert pour le picker)**, amendement de l'invariant 5/du geste « cocher une semaine au
  picker » ci-dessus : « cocher une semaine » naissait toujours UN enfant d'UNE semaine
  (`assertValidWeekChild` plafonnait sa fenêtre à ≤ 7 jours) — besoin routine constaté : N
  semaines identiques (même structure/contraintes) obligeaient à répéter le même geste semaine
  par semaine, avec le risque que le solveur (8 workers, non déterministe) rende N résultats
  divergents pour une même intention. **Décision : le SEGMENT — un bloc de semaines calendaires
  PLEINES et CONTIGUËS (lundi→dimanche, clamp saison admis aux deux bords) — devient l'unité hors
  socle ; la semaine simple est le segment de taille 1 (rétro-compatible sans migration, aucun
  nouveau concept : même rail 1 entrée = 1 plan, mêmes gardes)**.
  `CalendarEntryStateProcessor::assertValidWeekChild` remplace le plafond ≤ 7 jours par une garde
  à deux étages : (1) une **borne d'enveloppe** — la fenêtre reste incluse dans les semaines qui
  COUVRENT la mère (du lundi de la semaine de son début au dimanche de la semaine de sa fin,
  clampé à la saison lue par SQL paramétré sur `season_id`, RLS-scopé) — cette borne **REMPLACE**
  l'ancien « toucher la mère », strictement plus forte que lui : sans elle, retirer le plafond
  laisserait un segment déborder largement la mère et hériter ses contraintes datées date-blind
  hors de sa portée ; (2) des **bornes pleines** — début un lundi, fin un dimanche, sauf clamp
  saison (un segment peut commencer/finir au premier/dernier jour de saison même hors lun/dim,
  comme la semaine simple avant P2-41). L'anti-chevauchement entre semaines/segments d'une même
  mère et la garde 409 `window_already_planned` (P2-38) valent **tels quels**, sans modification :
  ce sont des comparaisons de fenêtres DATE, indifférentes à la largeur du segment qui les porte.
  **Bonus non cherché** : sur un segment dont toutes les semaines sont EFFECTIVEMENT identiques
  (même profil de fermetures), la réduction semaine-type de l'engine (`App\Service\VenueClosureDays`
  — l'engine planifie en semaine-type, donc un jour fermé sur une seule semaine d'un bloc multi-
  semaines ferme ce jour sur TOUT le bloc, « sur-ferme », documenté depuis 5b) devient de facto
  **EXACTE** : le sur-ferme ne diverge de l'exact que si les semaines du segment divergent entre
  elles — sur des semaines identiques, il n'y a rien à sur-fermer. **Assumé** : un segment
  hétérogène (des semaines aux réglages différents regroupées dans le même bloc) reste **permis**
  côté API — le sur-ferme s'applique alors normalement (comme n'importe quel bloc avant P2-41),
  et l'avertissement pédagogique à l'écran est un geste **PR-C (frontend, pas encore livré)**, pas
  une garde serveur. NR (axe planning lifecycle) : `Security/PeriodPlanBirthTest` (step bloquant)
  étendu — segment 3 semaines naît avec son plan sur la fenêtre entière, deux segments frères qui
  se chevauchent → 422, un segment débordant les semaines couvrant la mère → 422 (borne
  d'enveloppe), un segment dans une fenêtre déjà gouvernée par un plan étranger → 409 dans les
  deux sens, la semaine simple (taille 1) reste acceptée, un segment clampé au bord de saison est
  accepté — et `Security/WeekChildEntryTest` (bornes non-lundi/non-dimanche hors clamp → 422).
  Zéro migration (aucune contrainte DB de durée), `engine/**` non touché, `CONTRACT_VERSION`
  inchangé (schéma date-blind), aucun champ de ressource API ne bouge (garde et messages
  seulement). **Ce que cette PR-B ne fait PAS** : le picker (`WeekPickerDialog`) ne PROPOSE
  toujours QUE des semaines individuelles — l'API accepte les segments, l'écran ne les offre pas
  encore (PR-C : proposition aux ruptures géométriques, précochage, fusion/scission, pédagogie
  sur-ferme). Détail : `specs/courantes/etat-des-lieux.md` §3 (trace datée),
  `specs/courantes/types-de-planning.md` (règle transverse recalée), `specs/evolution/roadmap.md`
  (P2-41 resserré sur le picker).

  **PR-C livrée (2026-08-19), le lot P2-41 se solde.** `WeekPickerDialog` propose désormais les
  segments aux ruptures géométriques, précochés, avec scission et fusion (y compris par-dessus une
  rupture), et la phrase pédagogique sur le sur-ferme décrite ci-dessus — le geste front annoncé
  « pas encore livré » ci-dessus est livré. L'item a quitté `specs/evolution/roadmap.md`. Détail :
  `specs/courantes/etat-des-lieux.md` §3 (trace datée).

### Note de nommage (résolution de collision)

Le concept est « le Plan » dans tout ce document, mais l'entité technique s'appelle
**`SchedulePlan`** (table `schedule_plan`). Le nom `Plan`/`plan` était déjà pris par le
**catalogue de facturation** (tiers d'abonnement : `maxTeams`, prix, features) ; ce catalogue
a été renommé **`SubscriptionPlan`** (table `subscription_plan`, route `/api/subscription_plans`)
dans le même lot, pour qu'aucun des deux sens de « plan » ne soit ambigu dans le code.
