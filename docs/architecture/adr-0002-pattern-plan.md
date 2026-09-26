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

   **Tant que le plan SEASON pointe une version, aucune AUTRE version de saison n'est créée ni
   résolue.** À la création : `ScheduleStateProcessor::processPost` refuse en 409 tout POST
   « de saison » (sans `schedulePlanId`, ou visant explicitement le plan SEASON) tant que
   `SchedulePlanProvisioner::chosenOfSeasonPlan` répond non-null, sous le verrou de plan-scope
   de la saison et dans la transaction de création (NR `Security/SeasonVersionUniquenessTest`).
   Sur les trois portes agissant sur une version de saison déjà existante — `generate`,
   `regenerate`, `regenerate-from` — `SocleGuard::assertSeasonPlanNotChosen` (miroir de
   `assertSeasonPlanChosen`, inv. 13) pose la même garde en défense en profondeur, sous le même
   verrou et la même transaction ; `Security/SeasonPlanInForceTest` épingle la propriété qui la
   rend redondante en pratique (`ValidateScheduleController` supprime déjà toutes les sœurs à la
   validation, donc un plan SEASON pointé n'a jamais de sœur à viser) — si cette propriété
   cessait d'être vraie, ce test tomberait avant que les trois portes ne se rouvrent. **Ce que
   l'invariant NE dit PAS** : la structure du club (équipes, gymnases, coachs, contraintes
   permanentes) reste modifiable toute l'année — c'est l'objet de la comparaison
   `snapshotHash`/`currentStructureHash`, pas de son gel.
2. **Pointeur NULL = espace de travail.** On (re)travaille ⇒ pointeur remis à null, on
   génère des versions (V4, V5…), on choisira. **Aucun pointage automatique** (l'auto-baseline
   au 1er COMPLETED disparaît) — seul le gestionnaire pointe.
3. **1 Plan de type SEASON par saison.** Créé avec la saison (onboarding / transition N+1).
4. **Deux plans ne se chevauchent jamais** (hors plan SEASON, qui couvre tout par nature) —
   la famille d'une même mère (racine, semaines/segments enfants) exclue de la règle. Tenu à
   la naissance : `App\Service\PeriodWindowUniquenessGuard::assertWindowFree` refuse en 409
   (`window_already_planned`) aux deux seuls sites de naissance — le geste « Adapter »
   (`SchedulePlanStateProcessor::processPost`) et la création d'une entrée-semaine/segment qui
   naît avec son plan (`CalendarEntryStateProcessor::processPost`) —, dans les deux sens, sous
   `SchedulePlanProvisioner::lockClubWindows` (grain club+saison) pris **avant**
   `lockPlanScope`. Le refus nomme le plan déjà en place (titre + fenêtre) et s'affiche à
   l'endroit du geste (`WindowAlreadyPlannedNotice`), reprenant le message serveur tel quel.
   Une fermeture de gymnase datée s'applique à TOUT plan dont la fenêtre la recoupe, quelle que
   soit l'entrée qui la porte — priorité au **périmètre le plus fin** : la reprise gouverne,
   l'incident y entre. Le **FAIT reste libre** : déclarer une fermeture par-dessus une période
   déjà planifiée n'est jamais refusé — seul le **PLAN** d'adaptation est borné.
5. **Les réglages de période s'accrochent au Plan** (pas au déclencheur calendrier) :
   coches équipes (`TeamPeriodOverride`), contraintes gardées/enlevées
   (`ConstraintPeriodOverride`), sa grille de gymnases (`VenueTrainingSlot` scopé période —
   une COPIE du modèle de saison, cf. plus bas dans ce même invariant, #8) et ses modes de
   gymnase (`VenuePeriodOverride`),
   réservations, flag de seed (`teamSelectionInitialized`) →
   **re-keyés `calendarEntryId` → `planId`**. Chaque plan-semaine a SES réglages.

   **Exception : les contraintes datées restent sur `CalendarEntry`.** Elles décrivent le
   **FAIT** (« Barros fermé du 20 au 26 »), pas la réponse qu'on lui apporte, et le **radar de
   conflits les lit par l'entrée** (`CalendarEntryConflictsController`) pour annoncer
   « cette fermeture gêne 3 séances » — c'est ce qui **déclenche** le geste « ajuster ». Les
   accrocher au plan les rendrait illisibles tant qu'aucun plan n'existe, or le plan naît
   justement de ce geste (voir la section « Rôle de `CalendarEntry` » ci-dessous).

   **Une période POSSÈDE sa grille de gymnases et ses 4 règles de bien-être — copie, jamais
   union.** Le `VenueTrainingSlot` scopé période n'est pas une liste de « créneaux prêtés » qui
   s'ajoutent à ceux de la saison : à la naissance du plan, TOUTE la grille de saison de chaque
   gymnase est **COPIÉE** en créneaux du plan (`SchedulePlanProvisioner::copySeasonalSlots`) —
   `ScheduleConstraintBuilder::buildForOverlay` ne lit QUE les créneaux du plan, jamais d'union
   saison ∪ période, un gymnase n'a jamais deux jeux de créneaux dans une même période. Un
   réglage sparse par (plan, gymnase), `VenuePeriodOverride` (`mode` `DISABLED`\|`BLANK`\|hériter,
   pas de ligne = hériter), agit sur cette copie : DÉSACTIVÉ conserve la grille mais sort le
   gymnase du payload engine ; VIERGE la vide ; `DELETE` (retour à hériter) la revide puis la
   RECOPIE depuis le modèle de saison. Un épinglage (verrou HARD ou réservation) qui ne retombe
   plus sur aucun créneau de cette grille **bloque la génération en 422**, nommant le gymnase et
   le jour (`OrphanPinGuard`, appelé par `GenerateScheduleController`) — jamais filtré en
   silence. Même patron pour `ImplicitRuleSetting` (les 4 règles réglables du contrat moteur —
   repos coach, distribution salariés, enchaînements, âge croissant,
   `docs/architecture/constraint-matrix.md`) : l'ancre `schedulePlanId` (NULL = saison) porte,
   à la naissance du plan, une **copie totale** des 4 lignes (valeur saison si déviée, sinon
   défaut) — jamais sparse, pour qu'un plan « tout au défaut » reste distinguable d'un plan
   **legacy** (né avant cette fonctionnalité, zéro ligne, qui **retombe sur la saison**). Une
   fois copiée, la ligne du plan **vit sa vie** : une modification ultérieure de la saison ne
   redescend plus. Le `DELETE` en portée plan **re-copie la valeur saison courante** au lieu de
   supprimer la ligne — l'invariant « une période porte SES 4 règles » ne se brise jamais. NR :
   `Security/PeriodPlanBirthTest`, `CrossStack/ImplicitRulePayloadParityTest` (les deux
   **steps de `blocking-tests`**).

   **Pourquoi copie et pas héritage vivant** : le planning de saison du BCCL a été construit
   hors de l'application, donc ses règles de bien-être n'y sont pas appliquées ; un héritage
   vivant (la période SUIT la saison) aurait fait fuir cet artifice de transcription (seed
   `BcclSeeder`) dans chaque période de reprise sans que le gestionnaire l'ait choisi. La copie
   l'isole : une reprise démarre de la valeur saison du jour, puis devient LA SIENNE. Écran :
   en mode période, l'onglet Bien-être du wizard règle et affiche la COPIE du plan, derrière le
   même ancrage que « Réserver » (`PeriodAnchorGate`), sans bouton « revenir à la saison » —
   détail : `frontend/docs/frontend-wizard.md`.
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
| **Réglages de période** | Coches équipes/contraintes + grille de gymnases (copie, #8) + modes de gymnase d'un plan CLOSURE/HOLIDAY + copie des 4 règles bien-être (inv. 5). |
| **Termes bannis** | *baseline*, *planningName*, *overlayScheduleId*, *liveContext*, statuts *VALIDATED/ARCHIVED*. |

### Repères legacy cités dans le code (à ne pas re-nommer)

Le code (docblocks, commentaires) cite encore ces noms courts posés lors de la construction du
modèle — ils ne correspondent plus à une section de ce document (l'historique des lots vit dans
`git log -p --follow` de ce fichier), mais restent des ancres stables à comprendre en le lisant :

| Repère | Renvoie à |
|---|---|
| **A** (`ADR-0002 lot A`) | La création de `SchedulePlan` et son provisioning automatique (`SchedulePlanProvisioner`) — la fondation du modèle. |
| **B1** (`ADR-0002 lot B1`) | Le compteur de version monotone du plan (`SchedulePlan.lastVersionNumber`, jamais réattribué). |
| **C** (`ADR-0002 lot C`, « le plan naît du geste ») | Le plan CLOSURE/HOLIDAY naît au geste explicite d'« Adapter », jamais à sa première version — `CalendarEntryStateProcessor`/`SchedulePlanStateProcessor`, voir « Rôle de `CalendarEntry` » ci-dessus. |
| **C2/C3** (`ADR-0002 lot C2/C3`) | Les réglages de période (coches équipes/contraintes, créneaux, flag de seed) sont re-keyés `calendarEntryId` → `planId` (inv. 5). |
| **C4** (`ADR-0002 C4`) | `plan.type === SEASON` est l'unique façon de décider « est-ce le socle ? » — `Schedule.calendarEntryId` a disparu. |
| **D-b** (`ADR-0002 lot D-b`) | `CalendarEntry.overlayScheduleId` a disparu — « une période a une version en vigueur » se lit exclusivement via `SchedulePlanProvisioner::chosenOfPeriodPlan`. |
| **#8** | Une période possède SA grille de gymnases et ses 4 règles de bien-être — copie, jamais union (inv. 5). |

### Règles inter-plans & consommateurs (complétées après sweep exhaustif des ~320 usages)

13. **Créer un plan CLOSURE/HOLIDAY exige que le plan SEASON soit pointé** (version
    choisie non-null) — reprend la règle actuelle « les plans secondaires attendent la
    validation du socle ».
14. **Toucher au socle quand des plans secondaires existent = destruction confirmée** :
    remettre le pointeur SEASON à null (re-travailler) ou le changer, alors qu'une période
    **pas encore commencée** (`startDate > aujourd'hui`) **PORTE un plan, validé ou non**
    (`CalendarEntryRepository::findWithPlanNotStarted`) → avertissement proportionné
    (« supprime N plannings ») puis **suppression totale** : les versions, le **PLAN
    lui-même**, et tous les réglages qui s'y ancrent (grille copiée, réservations, modes
    gymnase `VenuePeriodOverride`, overrides d'équipes et de contraintes) —
    `OverlayManager::deletePeriodPlanForEntry`. L'**entrée de calendrier survit** : la
    période retombe « à traiter » au radar, à refaire. Une période **déjà commencée** (passée
    ou en cours) n'est **jamais** détruite — annoncée aux coachs et à moitié jouée, elle finit
    sur l'ancien socle. S'applique aux deux portes (`ValidateScheduleController` choisissant
    une autre version, `ReopenScheduleController` dépointant le socle) via le même 409
    `overlays_exist` + `confirmDeleteOverlays: true` — « le premier plan secondaire fige le
    socle » reste vrai.
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

## Mapping legacy → modèle

Le legacy de la colonne de gauche **n'existe plus**. Le tableau se lit comme une table de
correspondance pour relire du code ou des specs antérieurs.

| Legacy (supprimé) | Ce qui le remplace |
|---|---|
| Regroupement implicite `(seasonId, calendarEntryId)` | `Schedule.schedulePlanId` |
| `Season.baselineScheduleId` + auto-assignation 1er COMPLETED | `Plan(SEASON).chosenScheduleId`, posé par validation seule |
| `Season.planningName` | `Plan.name` |
| `CalendarEntry.overlayScheduleId` (1:1) | `Plan.chosenScheduleId` (N plans par entry via `Plan.calendarEntryId`) |
| `Schedule.status VALIDATED` + `ARCHIVED` (validation archive les frères) | supprimés — valider = pointer + **supprimer** les autres versions |
| `Season.liveContextScheduleId` | ★ conservée (version dont la photo est chargée) — hors du Plan |
| `Season.socleValidatedAt` (gate cockpit, sticky) | dérivé : plan SEASON a ≥ 1 version terminée |
| Réglages sur `calendarEntryId` (overrides, créneaux, seed flag) | re-keyés sur `planId` (**C2/C3** dans le code) ; les contraintes datées du FAIT restent au calendrier (inv. 5) |
| « V3 » dérivé de l'ordre de création | `Schedule.versionNumber` stocké côté serveur (les libellés du front dérivent encore de l'ordre de création — voir Questions ouvertes) |
| `GenerateScheduleHandler` branche sur `calendarEntryId` | branche sur `plan.type` (payload engine **inchangé** — zéro engine, **C4** dans le code) ; `Schedule.calendarEntryId` **supprimé** (colonne droppée) |

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

## Note de nommage (résolution de collision)

Le concept est « le Plan » dans tout ce document, mais l'entité technique s'appelle
**`SchedulePlan`** (table `schedule_plan`). Le nom `Plan`/`plan` était déjà pris par le
**catalogue de facturation** (tiers d'abonnement : `maxTeams`, prix, features) ; ce catalogue
a été renommé **`SubscriptionPlan`** (table `subscription_plan`, route `/api/subscription_plans`)
dans le même lot, pour qu'aucun des deux sens de « plan » ne soit ambigu dans le code.
