# Lot 5 · Le socle froid : plan d'exécution

**Statut : version finale (v4 sur 4, après trois passes de `smith`). Plan validé par le fondateur le 2026-11-24 avec ses décisions 1 à 8 (§10) et les écarts E1 à E6. PR 1 à PR 6 livrées (« Écarts au plan, tels que livrés », §4). L'exécution suit l'ordre du §4 : PR 1 à 3, puis le lot 7, puis PR 4 à 7.** Voie **Full**. Branche cible `lot/conception`, une PR ouverte à la fois, « Lot Gate » seul ; avant chaque merge : `make -C backend tests-complete` et les features et specs de la zone sur la stack d'épreuve. Exécutant de chaque PR : `neo`, après les rouges de `tank`.

**Limite à dire d'entrée** : la PR 5 concentre la porte sur la copie, « Retravailler », l'écran, la propagation des lignes de période et environ 49 fichiers de test, alors que le plafond Behat (950 s) est déjà dépassé en local. **Un dépassement est probable** ; relever le plafond exige la ligne datée du fondateur dans le corps de la PR.

**Cadrage** : la conception validée en tient lieu ([`lot-conception-decoupage.md`](lot-conception-decoupage.md) §5, décision 3). La fiche du lot est dans [`lot-conception-decoupage.md`](lot-conception-decoupage.md) § Lot 5 ; ce fichier dit **comment**. Cible : [`conception-cible.md`](conception-cible.md) §4, §5, §12 à §17, §19, §28.

**Ce que ce plan suppose** : lot 4 livré sur `lot/conception` (tête `2c1251fbf` à l'écriture, qui portait aussi la PR 1 du lot 7 ; la base a avancé depuis). Les `fichier:ligne` datent du 2026-11-24, **à relire à l'ouverture de chaque PR**.

**Écarts à la cible et aux plans voisins, validés avec ce plan** :
- E1 : contraintes permanentes, passerelles, règle de trajet et coach principal restent rangés à la saison, déclarés « froid du socle » (lot 9 pour les déplacer).
- E2 : les clés étrangères vers équipe et gymnase, promises « lot 5 » par le lot 4 (son E6) et par le lot 7 (son E3, `lot-conception-7-les-matchs.md:135`), passent au lot 6. Aucune clé vers `constraint` tant que E1 dure.
- E3 : deux mesures, tenues par une garde « une clé de la demande, une source » (§3.3).
- E4 : les plans de période gardent « Rouvrir » dans ce lot (décision 4).
- E5 : « Charger » remet aussi le froid porté par les équipes et les gymnases existants, ce qui revient sur la cible §15. Écart assumé, validé par le fondateur le 2026-11-24 (décision 8).
- **E6 : le geste « Valider ces modifications » est le seul qui écrit la version en vigueur, et seulement trois choses : le coach de ses séances, son entrée figée et son empreinte (§3.6). C'est une exception nommée à l'invariant « seule la version chargée se modifie » de `CLAUDE.md` §6.**

---

## 1. Besoin reformulé

1. Le socle a un **état de travail** et une **version en vigueur**, que lisent matchs, radar et plans de période. Retravailler ne détruit rien.
2. Une version garde une **copie complète du froid** de son plan. « Charger » la remet, sans créer ni supprimer équipe, gymnase, coach ou catégorie.
3. Valider affiche ce qui change. **Seul le froid supprime les plans de période pas commencés** ; une séance déplacée ou verrouillée n'en supprime jamais. Doléances et campagne restent.
4. Un changement fait hors du planning (le coach principal, par exemple) se valide sans régénérer ; le système dit les incohérences qu'il crée avec le planning en vigueur.
5. Une seule porte pour lire l'en vigueur.
6. « Préparer la saison suivante » reprend le socle en vigueur, par la déclaration des familles.
7. L'écran dit quand des modifications attendent la validation.
8. La demande au moteur ne change pas, club par club. Aucun changement de contrat.

**Ambiguïtés** : aucune ne reste ouverte ; ce qui n'a pas pu être vérifié est écrit en limite (§11).

## 2. Constats vérifiés dans le code

**La photo et « Charger » du socle**
- Dix familles listées à la main, catégories du club comprises : `Service/StructureSnapshotter.php:53-65`, `:121-125`.
- Seul un plan de saison reçoit une photo (`MessageHandler/GenerateScheduleHandler.php:284-287`) ; son écriture n'échoue jamais la génération (`:388-396`).
- « Charger » efface puis réinsère : `Service/StructureRestorer.php:425-440`, dont catégories (`:439`), équipes (`:431`), gymnases (`:437`), coachs (`:438`).
- Non restaurés : passerelles, règle de trajet, bien-être, mutualisations, occupations.
- Après réinsertion : élagage des lignes de période orphelines (`:313-380`), `ensureLines` (`:144`), jetons de collecte recréés (`:148`).
- Route `Controller/RegenerateFromVersionController.php:59` ; refus `:79-81`, `:90-92`, `:100-102`, `:122`.
- Équipe engagée absente de la photo : refus (`StructureRestorer.php:416-422`, `Service/RestoreFeasibility.php`) ; le plan du lot 7 s'y appuyait (sa Q10, §13).
- `Structure/PersonEraser.php:269-273` réécrit les photos.

**La seconde définition**
- Période : pointeur des verrous (`Controller/LoadLocksController.php:106`), refusé sur un plan validé (`:95-97`), trace `LOCK_CHANGED` agrégée (`:114-121`).

**Valider, rouvrir**
- Valider pointe (`Service/SchedulePlanProvisioner.php:150`) et supprime les autres versions (`Controller/ValidateScheduleController.php:247-249`).
- Valider une autre version détruit tous les plans de période pas commencés (`:183-213`, `Service/OverlayManager.php:57-60`) et purge la collecte (`:217-219`, `Service/FutureHolidayCollectePurger.php:85`).
- Rouvrir dépointe (`Controller/ReopenScheduleController.php:124`) ; sa branche socle détruit et purge (`:88-121`).
- Socle pointé, aucune autre version du socle ne peut naître : cinq refus (`GenerateScheduleController.php:161`, `RegenerateController.php:171`, `RegenerateFromVersionController.php:122`, `State/Processor/ScheduleStateProcessor.php:155`, `Service/ParcImport/ParcImportGate.php:74`).
- La version en vigueur ne se modifie pas : `Service/VersionEditability.php:34-41`.
- `overlaysDroppedOnValidate` : `Service/ScheduleCapabilityResolver.php:169-179`, `:203` ; côté écran, le type seul (`frontend/src/features/planning/api.ts:90`, `:98`).
- `SocleGuard` : trois processeurs (`FixtureStateProcessor.php:91`, `ScheduleStateProcessor.php:111`, `:155`, `TeamStateProcessor.php:185`). Sur une écriture de structure, socle validé, seul le plafond par jour est refusé ; le reste est un verrou d'écran (`frontend/src/features/wizard/WizardLayout.tsx:204-211`, `:573`, `steps/TeamsStep.tsx:437`, `steps/VenuesStep.tsx:290`).
- « Modifier les données du club » : `frontend/src/features/cockpit/SeasonPlanBanner.tsx:80-89`. Import d'équipes permis à tout moment : `Service/TeamImportGate.php:29-31`.
- Une retouche de planning supprime aussi des lignes du plan : `Service/MutualizeSlotService.php:494-498`, `Service/ReservationRemoval.php:48-51`.
- La route d'écart au planning de saison ne sert que les fermetures (`Controller/SocleDeviationController.php:95-96`).
- Une période de vacances sans version vaut « aucun entraînement » pour ses dates (`Service/EffectiveScheduleResolver.php:57-69`).

**Ce qui existe pour vérifier un planning** (réutilisé par la décision 2 b)
- Le moteur rend un verdict sur des séances candidates, le reste du planning figé, et nomme les règles cassées : `app/main.py::validate_assignments`, appelé par `Service/EngineClient.php:83`. Trois issues : valide, refusé avec règles nommées, indéterminé (délai atteint).
- **Ce verdict admet au plus 50 séances candidates par demande et un budget d'au plus 10 s** (`engine/app/schemas/validate_input_schema.py:38`, `:42`) ; au-delà, la demande est refusée 422, ce n'est pas un verdict.
- La demande est assemblée à un seul endroit : `Service/MoveSlotService.php:1407-1427` (budget de 2 s, `:71`). Le rail juge N candidats sous un seul verdict (`:439`, `:540`) et a un mode « à blanc » (`:127`, `:243`).
- Les personnes à deux endroits sur le planning en vigueur sont déjà calculées et affichées : `Service/PlacedSessionPersonConflictDetector.php:99-101`.
- Le dialogue de validation sait lister les séances manquantes par équipe (`frontend/src/features/planning/ValidateDialog.tsx`, propriété `missingSessions`).
- `Controller/ValidateConstraintsController.php:73` ne regarde pas des séances placées ; il n'est pas réutilisé ici.

**Qui tient une séance**
- Chaque séance d'une version porte son coach (`Entity/ScheduleSlotTemplate.php:64-66`), écrit à l'import du résultat (`Service/ScheduleResultImporter.php:151`) et à chaque déplacement, par une maison unique (`Service/MoveSlotService.php:1003`, `CoachDoubleBookingDetector::storedCoachFor`).
- Les lecteurs comptent **le coach nommé par la séance** dès qu'il n'est pas adjoint de l'équipe, même sans lien avec elle ; à défaut, les coachs principaux de l'équipe : `Service/PlacedSessionPersonConflictDetector.php:281-296`, `:439-442` ; `Service/Conflicts/DayHoldings.php:339-345`.
- Conséquence : sans réécrire ce champ, une séance qui nomme l'ancien coach principal resterait tenue par lui après le changement.

**L'empreinte, l'entrée figée et ce qui part au moteur**
- Recette de l'empreinte : `Service/ScheduleConstraintBuilder.php:188` ; elle ignore le coach des séances.
- Chaque version garde l'entrée figée de sa génération et son empreinte (`Entity/Schedule.php:97-102`, `engineInput()` `:388`). Une version peut naître sans solve, avec une entrée et une empreinte calculées sur l'état du jour : c'est déjà le cas de la transcription (`Service/PeriodPlanTranscriber.php:234-238`).
- Deux routines **recalculent l'empreinte depuis l'entrée figée** : la commande de réalignement (`Command/PruneUnreadSnapshotFieldsCommand.php:218-225`) et l'effacement d'une personne (`Structure/PersonEraser.php:223-228`).
- Hors des familles du plan, la demande porte : nom des équipes et âge de leur catégorie (`ScheduleConstraintBuilder.php:1432`, `:1448`) ; jour de match tiré des habitudes (`:1477-1494`) ; nom d'une contrainte (`:1723`) ; jours maximum et véhiculé d'un coach (`:1509`, `:1516`) ; coach-joueurs (`:363`, `:794`, `:957`).
- Libellés sur des lignes du plan : `Entity/SharedTrainingBlock.php:81`, `Reservation.php:84`, `VenueTrainingSlot.php:70`.
- Une équipe désigne sa catégorie et son gymnase imposé par identifiant nu (`Entity/Team.php:52`, `:83`).

**Qui lit l'en vigueur**
- Planning à une date : `Service/TrainingCalendarContext.php:51`, `:60` ; jours rouverts des périodes pointées lus en direct (`:63-84`).
- Sept lectures directes du pointeur : `PlacedSessionPersonConflictDetector.php:101`, `Controller/CalendarEntryConflictsController.php:74`, `MatchModuleDeltaComputer.php:52`, `EngineRequestComposer.php:209`, `PeriodPlanTranscriber.php:123`, `Controller/SocleDeviationController.php:103`, `EngineRequestRecorder.php:150`.
- Radar : liens de coach (`Service/ConflictRadarLoader.php:79`), coach-joueurs (`:84`), équipes (`:113`) ; plus de passerelles (`:104-107`).
- Placement : équipes (`MatchPlacementPayloadBuilder.php:123`), passerelles (`:133`), liens de coach (`:136`).
- Demande d'une période : équipes, passerelles, contraintes permanentes, coach-joueurs en direct (`ScheduleConstraintBuilder.php:857` et suivantes, `:957`, `:988`).
- `PlanComposition::constraintsOf` lève sur une contrainte permanente sans ligne (`Plans/PlanComposition.php:207-228`).

**Mise en production** : manuelle, site fermé (découpage §1, règle 5). Les étapes d'après-déploiement de `scripts/remote-deploy.sh` (`:127-128`, `:137-138`) sont en `|| echo` et tournent site ouvert.

**Saison suivante** : copie pas à pas depuis les lignes vivantes (`Service/SeasonTransitionService.php:175`), sans réservations ni mutualisations (`:8-24`).

**Déclaration** : `Structure/Family.php:23-31` ne dit ni froid, ni libre, ni saison suivante.

**Filet existant** : références 1 à 15 du lot 4 ; 16 à 19 captées par la PR 1 du lot 7 ; 20 et 21 par la PR 1 du lot 5 ; chaîne `tests/Integration/Migration/Lot4MigrationChainTest.php:75` ; 49 fichiers de test pointent une version validée à la main (23 contextes Behat, `tests/ChoosesPlanVersionTrait.php`) ; plafonds 1000, 950, 635, 230 s.

## 3. Conception : cinq réponses par objet

### 3.1 La déclaration froid, libre, à chaud
1. **Le plus simple** : quatre paramètres de plus sur `#[Family]`. Le plan s'y tient.
2. **Supprime** : `StructureSnapshotter::FAMILIES`, `StructureRestorer::FAMILY_CLASS`, `StructureAnchor`.
3. **Un seul endroit** : `Structure/Family.php:23-31`, étendu.
4. **Suppose en dur** : froid par champ ; paramètre obligatoire aux niveaux saison et plan.
5. **Deuxième usage** : variante de semaine, planning d'essai.

| Information | Classe | Source |
|---|---|---|
| `PlanTeam`, `VenueTrainingSlot`, `Reservation`, `ImplicitRuleSetting`, `SharedTrainingBlock` et ses équipes, `PlanVenue`, `PlanConstraint` | Froid, du plan | Cible §3 |
| Contrainte permanente, passerelle, règle de trajet, coach principal, gymnase imposé | Froid, du socle (E1) | Cible §3, §12 |
| Coach-joueur | Froid, du socle | Décision 7 |
| Catégorie, niveau, sexe, rang d'une équipe ; « divisible », « actif » d'un gymnase | Froid, structure | Cible §3, §13 |
| Noms, contacts, adjoints, case salarié, temps de trajet, occupations | Libre | Cible §3, §5.1, §13.3 |
| Libellés d'un groupe, d'une réservation, d'un créneau ; nom d'une contrainte | Libre | Cible §4 |
| Véhiculé, jours maximum d'un coach | Libre | Décision 6 |
| Âge d'une catégorie, jour de match des habitudes, faits du calendrier, rangs de la plateforme | À chaud | Cible §12, §17 |
| La place d'une séance, un verrou posé sur une séance | Hors du froid : ils appartiennent à la version | Décision 1 |

### 3.2 La copie d'état d'une version : `schedule_state`
1. **Le plus simple** : un document JSON par version. Le plan s'y tient.
2. **Supprime** (PR 3) : la table de photos, `StructureSnapshotter`, `StructureRestorer`, `RestoreFeasibility`.
3. **Un seul endroit** : `Entity/ScheduleStructureSnapshot.php:24` est le semblable ; table neuve en PR 2, ancienne supprimée en PR 3.
4. **Suppose en dur** : une copie par version terminée.
5. **Deuxième usage** : comparer deux versions à l'écran. Non construit.

- Écrire la copie est bloquant : dans la transaction du résultat ; un échec fait échouer la génération, sans crédit.
- Une retouche de planning qui écrit un réglage du plan synchronise dans la copie de la version chargée les seules lignes touchées : ajoutées, modifiées ou retirées.
- La copie de la version en vigueur est remplacée par l'état de travail dans un seul cas : le geste « Valider ces modifications » (§3.6).

### 3.3 Deux mesures, une garde
- « Périmé ? » : la recette actuelle sans les clés libres ; froid et à-chaud la font bouger.
- « Qu'est-ce qui change, faut-il supprimer, quelque chose attend-il ? » : la différence de deux copies sur les seuls champs froids. C'est la seule chose qui supprime des plans de période.
- Une séance déplacée figure dans la liste à titre d'information seulement : sa ligne porte `destroys: false`, toujours.
- Un verrou n'entre pas dans la liste et ne supprime rien.
- Garde bloquante : chaque clé de la demande a une source, libre, froide ou à chaud. Libre : ni empreinte ni copie. Froide : les deux. À chaud : l'empreinte seule. Une clé sans source fait échouer le test.

### 3.4 « Charger », une définition
- Remet l'état de travail du plan : retire les lignes absentes de la copie, met à jour les présentes, insère les manquantes ; purge `cache.schedule` du club ; déplace l'étoile ; écrit la trace `LOCK_CHANGED`, socle comme période.
- Ne crée ni ne supprime équipe, gymnase, coach, catégorie. Ne touche aucun nom, contact, adjoint, libellé.
- Remet le froid des équipes et gymnases existants : rang, catégorie, niveau, « divisible », gymnase imposé (E5). Refuse, sans rien écrire, si le niveau d'une équipe engagée changeait.
- Références disparues : une ligne dont l'équipe, le gymnase ou le coach n'existe plus est ignorée ; un champ d'équipe qui désigne un gymnase ou une catégorie supprimés depuis n'est pas remis et ne compte pas dans la différence de copies.
- Si des réglages ont été changés sans être générés, le dialogue prévient qu'ils seront perdus, puis charge (décision 3).
- N'écrit dans aucun autre plan. Dans la PR 3 seulement, une contrainte ramenée sans ligne de période en reçoit une au défaut ; à partir de la PR 5, les lignes de période naissent et meurent à la validation du socle.

### 3.5 La porte `InForce`
1. **Le plus simple** : lire les lignes vivantes. Le plan fait plus : rien ne doit valoir avant validation.
2. **Supprime** : les sept lectures directes du pointeur.
3. **Un seul endroit** : `TrainingCalendarContext.php:51` reste la maison du planning à une date.
4. **Suppose en dur** : couvre le socle seul (E4).
5. **Deuxième usage** : la même porte pour une période.

Règles de lecture :
- Aucun socle validé : les lignes de travail.
- Familles du plan et du socle : les lignes de la copie, moins celles dont l'équipe, le gymnase ou le coach a été supprimé.
- Structure : lignes vivantes, champs froids pris dans la copie ; une équipe ou un gymnase créé depuis la validation est lu en direct, en entier.
- Libre et à chaud : en direct.
- Les lignes de règle des périodes suivent le socle en vigueur.

### 3.6 Retravailler, valider, valider sans régénérer
- **Retravailler** (décision 2 a) copie la version en vigueur en version de travail, avec son entrée figée et son empreinte, et y pose l'étoile. Le pointeur ne bouge pas : la copie n'a aucun effet tant qu'elle n'est pas validée. Il ne remet pas l'état de travail : des modifications déjà en attente sont gardées.
- **Valider une version** compare sa copie à celle de l'en vigueur. Aucun froid changé : aucun plan de période supprimé, même si des séances ont bougé. Sans version en vigueur et avec des plans de période : suppression, comme aujourd'hui. La confirmation porte un jeton de la liste vue.
- **« Valider ces modifications », sans régénérer** (décision 2 b) :
  1. **Le plus simple** : obliger à régénérer. Le fondateur l'écarte : régénérer peut déplacer pour rien des séances non verrouillées.
  2. **Supprime** : rien.
  3. **Un seul endroit** : le verdict existe (`EngineClient.php:83`), sa demande aussi (`MoveSlotService.php:1407-1427`), la règle de tenue d'une séance aussi (`MoveSlotService.php:1003`) ; on ajoute une entrée de lecture sur ce rail, pas un second contrôle.
  4. **Suppose en dur** : plan de saison, version en vigueur présente, au moins une modification froide en attente.
  5. **Deuxième usage** : le même contrôle avant de valider une version périmée. Non construit.

  **Ce que le geste écrit, et rien d'autre (E6)** :
  - la **copie** de la version en vigueur devient l'état de travail du jour ;
  - **le coach des séances** de la version en vigueur, pour les seules équipes dont le coach principal a changé : une séance qui nommait l'ancien coach principal nomme le nouveau, par la maison existante de la tenue. Sans cela la séance resterait tenue par l'ancien coach (§2), et la décision « le changement s'applique au nom du coach principal » ne serait pas tenue ;
  - **l'entrée figée et l'empreinte** de la version en vigueur, recalculées sur l'état qu'elle vient d'adopter, comme le fait déjà la transcription. Sans cela une copie de travail née juste après s'afficherait « périmée » sans rien en attente, et le réalignement comme l'effacement d'une personne ramèneraient l'ancienne empreinte ;
  - les lignes de règle des périodes, `cold_changed_at`, et la suppression des plans de période pas commencés, puisque du froid a changé (un changement de coach principal en est un : cible §5.2).
  - **Il n'écrit jamais** : la place, le jour, l'heure, le gymnase ou le verrou d'une séance ; il n'ajoute ni ne retire aucune séance ; aucune version ne naît.
  - C'est **la seule exception** à « seule la version chargée se modifie » ; les neuf guichets de retouche restent refusés sur la version en vigueur.

  **Ce que voient les matchs et le radar après le geste** : le nouveau coach principal est compté sur les séances de son équipe, l'ancien ne l'est plus ; un conflit de personne créé par le changement apparaît par l'affichage existant.

  **Le contrôle d'incohérences, avant confirmation** : les séances touchées par les modifications sont soumises, à leur place actuelle, au verdict du moteur avec le froid modifié, le reste du planning figé. Il rend :
  - **séance impossible ou contrainte violée** : chaque règle cassée, nommée, avec la séance ;
  - **séances manquantes ou en trop** par équipe, quand le nombre de séances a changé ;
  - **contrôle non concluant**, dit comme tel.

  **Quelles séances sont « touchées »** :

  | Modification | Séances soumises au contrôle |
  |---|---|
  | Coach principal, coach-joueur | Celles de l'équipe, et celles de toute équipe à laquelle l'ancienne ou la nouvelle personne est liée |
  | Règle d'une équipe, d'un coach, d'un gymnase | Celles de l'équipe ; des équipes du coach ; celles placées dans le gymnase |
  | Règle de club, règle de trajet, réglage de bien-être | Toutes |
  | Créneau de grille ajouté, retiré ou modifié | Celles placées dans ce gymnase ce jour-là et qui chevauchent le créneau, avant ou après modification |
  | Réservation ajoutée, retirée ou modifiée | Celles de l'équipe réservée, et celles placées dans ce gymnase ce jour-là qui chevauchent l'horaire |
  | Participation ou nombre de séances d'une équipe | Celles de l'équipe (plus le compte des manquantes) |
  | Passerelle, mutualisation | Celles des équipes liées ou membres |
  | Catégorie, rang, gymnase imposé d'une équipe ; « divisible » d'un gymnase | Celles de l'équipe ; celles placées dans le gymnase |

  **La taille** : le verdict admet 50 séances par demande. Le contrôle **découpe** : des demandes de 50 séances au plus, six demandes au plus (300 séances). Chaque demande fige le reste du planning et épingle ses candidates. Au-delà de 300 séances touchées, ou si une demande est refusée, échoue ou dépasse son délai, le contrôle répond **« non concluant »**.

  **Ce qu'il fait d'une incohérence ou d'un contrôle non concluant** : il avertit, il ne bloque pas.

  **Ce qu'il ne voit pas** : les préférences souples et la qualité du planning.
- `schedule_plan.cold_changed_at` date la dernière validation qui a changé du froid.

### 3.7 Saison suivante
Routines par famille gardées ; liste lue sur le catalogue ; balayage qui refuse une famille sans déclaration ou sans routine. Source : la copie en vigueur, ou l'état de travail si rien n'a été validé.

## 4. Découpage : sept PR

Ordre d'exécution décidé (décision 5) : **PR 1 à 3, puis le lot 7 (ses PR 1 et 2, le filet et la liste des types de conflit, sont déjà livrées ; ses sept autres PR), puis PR 4 à 7.**

### PR 1 · « Le filet du lot 5 » (aucun comportement ne change)
- Références captées sur le code d'avant : `socle-20-saison-suivante-socle-complet.json`, `placement-21-equipe-creee-apres-validation.json`.
- Feature neuve `charger-une-version-ramene-son-etat.feature`, un scénario sur ce qui revient déjà.
- Mesure des plafonds.
- Tests pour `tank` : lignes 1 à 4. `/security-review` : non.

**Écarts au plan, tels que livrés (PR 1)**
- (a) Références 20 et 21 (`tests/EngineRequestSocleNetDecorTrait.php`, `EngineRequestReferenceTest`, bloquant d'office), captées sur le code d'avant, vertes, falsifiées dans les deux sens. 20 `socle-20-saison-suivante-socle-complet` : le vrai `SeasonTransitionService::transition` au 2026-05-04, saison 2026 réglée en beta. 21 `placement-21-equipe-creee-apres-validation` : une équipe U17 créée après la validation, avec son coach principal, un match à domicile à placer et un extérieur. Les numéros 20 et 21 étaient libres (le lot 7 a pris 16 à 19).
- (b) Outillage : `fixTransitionIds` (dans le décor) remplace les identifiants aléatoires nés de la bascule (`random_bytes`) par des identifiants fixes, puis reclasse la passerelle (l'invariant « équipe A < équipe B » dépendait des identifiants : la référence passait une fois sur deux). **Limite** : ce remplacement ne tient que parce qu'aucune clé étrangère ne vise une équipe, un gymnase, un coach ou une contrainte. Le lot 6 en posera ; le remplacement échouera alors bruyamment et devra être revu.
- (c) Feature neuve `charger-une-version-ramene-son-etat.feature` (suite Behat `charger-une-version`, `LoadVersionContext`) : charger la version 1 rend la grille, les réservations, les règles **et les séances par équipe** (étape ajoutée par rapport à l'énoncé du plan). L'étape « je charge la version 1 » appelle aujourd'hui `POST /api/schedules/{id}/regenerate-from` ; la PR 3 la passe sur `POST /api/schedules/{id}/load` (adaptation mécanique).
- (d) Ligne 4 du tableau (toutes les demandes après toutes les migrations) : `Lot4MigrationChainTest` reste inchangé et ne couvre PAS 20 et 21 : leur décor ne s'écrit pas dans le schéma d'avant le lot 4, comme 16 à 19 au lot 7.
- (e) À venir. Version pointée à la main (PR 5) : 20 et 21 en dépendent (`seedSocleVersion`/`chooseAndLoadSocle`, puis `seedSocleInForce`) et recevront une copie comme les 49 fichiers de la limite 11. PR 7 : la référence 20 change de ses seules réservations (elles entreront en `slotTemplates` de la saison suivante). Tests `CrossStack` : `EngineFailureTypeMirrorTest` (groupe `contract`) peut rougir en PR 2 si la copie fait échouer la génération sous un type nouveau ; `ManualPlacementOutsideLiftedRuleTest` (`contract`) casse en PR 5 (`ChoosesPlanVersionTrait`) ; `SocleReferencePayloadParityTest:36,318` et `PreviousAssignmentsPayloadParityTest:34,320` (`phase1`) instancient `StructureSnapshotter` et casseront en PR 3.
- (f) Repères du plan décalés, corrigés : `GenerateScheduleHandler.php:284-287` (et non 285-287) ; « tête `2c1251fbf` » du préambule : la base a avancé.
- (g) Plafonds : la PR ajoute environ 23 s en Behat et moins de 2 s en backend. Médianes CI relevées : backend 560 s sur 1000, Behat 774 s sur 950, e2e 492 s sur 635, frontend 135 s sur 230, engine 30 s sur 60. Le quota de minutes hébergées dépasse le seuil de réserve : le geste `CI_RUNS_ON_VERDICT` est au fondateur, jamais à un agent ; mesure à relire par `make ci-mesure`.

### PR 2 · « Le froid se déclare, une version copie tout le froid »
- Migration : table `schedule_state`, RLS, clé vers `schedule` en cascade.
- Code : paramètres de `#[Family]`, `PlanStates::capture` et `store` à chaque naissance de version, écriture bloquante, empreinte sans les clés libres.
- Conversion : commande `app:plans:capture-version-states` et son `--check`. Rien n'est ajouté à `scripts/remote-deploy.sh`.
- Tests : lignes 5 à 10. `/security-review` : oui.

**Écarts au plan, tels que livrés (PR 2)**
- (a) Migration `Version20261201120000`, **irréversible** (retour arrière = la sauvegarde d'avant migration). Table `schedule_state` : `id`, `created_at`, `updated_at`, `club_id`, `season_id`, `schedule_id`, `data` JSON ; pas de colonne `version` ; unique sur `schedule_id` ; clé simple vers `schedule` `ON DELETE CASCADE` ; RLS forcée avec `tenant_isolation`, `admin_all`, `readonly_tenant`. La migration ne copie rien : la capture est la commande (d).
- (b) `#[Family]` : `cold` (liste de champs, `Family::ALL`, ou `[]` pour « libre ») et `copiedToNextSeason` sont **obligatoires** aux niveaux saison et plan ; `coldRows` filtre les lignes froides d'une famille ; `ofSocle` marque le froid du socle. Les familles froides du niveau plan sont déclarées. `FamilyCatalog::coldOf()` et `FamilyCatalog::copiedToNextSeason()` les lisent.
- (c) `App\Plans\PlanStates` (capture en SQL direct lisant le catalogue ; `store` bloquant ; `of()` lève si la copie manque), `PlanState`, `PlanChanges`. Dans `GenerateScheduleHandler`, la copie s'écrit dans la transaction du résultat, avant le crédit : un échec fait passer la version FAILED avec le diagnostic `internal_error` (aucun type d'échec neuf), sans crédit ni fuite SQL. `PeriodPlanTranscriber` et `PeriodPlanSiblingCopier` capturent et stockent dans leur transaction ; `BcclSeeder` et `LoadTestClubSeeder` écrivent aussi une copie (testés).
- (d) Commande `app:plans:capture-version-states [--check]` (codes 0 et 1). « Née avant la migration » = `created_at` antérieur à `executed_at` de la migration ; « gardée » = `chosen_schedule_id` ou `lock_source_schedule_id` d'un plan. Aucune réparation après coup d'une version née après la migration. **Rien n'est ajouté à `scripts/remote-deploy.sh` ni à `deploy.yml`** : la capture se joue UNE fois dans la procédure manuelle du lot (dernière PR) et `--check` bloque la réouverture du site.
- (e) Empreinte : `ScheduleConstraintBuilder::fingerprint(payload, ?règle de trajet)` est le **point d'entrée unique** : `structureHash` sans les clés libres (noms d'équipe, de gymnase et de contrainte ; prénom, nom, salarié, véhiculé et jours maximum des coachs ; lignes d'adjoints ; matrice de trajets) plus la règle de trajet (`VenueTravelRuleSetting`, froide, déclarée sans `ofSocle` : copiée avec toute version de tout type de plan), lue directement. Le temps de trajet est libre : le saisir ne périme pas. À valeurs par défaut de la règle (PREFERRED, 20, 20) ou règle inconnue, l'empreinte égale l'ancien `structureHash` : les empreintes anciennes restent valides. Points d'appel : génération, transcription, copie sœur, graines, `PruneUnreadSnapshotFieldsCommand`, `PersonEraser`, `NormalizeTeamCoachSnapshotsCommand`, `DedupeSnapshotPinsCommand`. Une version SANS copie (ancienne ni validée ni chargée, ou FAILED) compte la règle par défaut (`travelRuleOfVersion` rend null) : elle n'est jamais en vigueur et ne se charge pas ; les versions gardées sont couvertes par `--check`. **Écart au §3.1** : la règle de trajet entre dans l'empreinte (lue directement) ; la présence du bloc `implicitRules.travelTime` n'y entre pas.
- (f) Le contrat backend⇄engine est inchangé : `CONTRACT_VERSION` identique, toutes les références de demande vertes à l'octet.
- (g) Tests : `EngineRequestKeySourcesTest` (le garde « une clé de la demande, une source », bloquant, ajouté au step `Blocking suite` et à `blocking-tests.md`), `FamilyDeclarationSweepTest` étendu, `PlanStatesTest`, `CaptureVersionStatesCommandTest`, `TravelRuleFingerprintTest` (bloquant, ajouté au step `Blocking suite` et à `blocking-tests.md`), tests des graines, tests des quatre routines de réalignement avec une règle non par défaut, `RlsIsolationTest` et `PurgeCompletenessTest` pour `schedule_state`, `Lot4MigrationChainTest` étendu (le décor 12 seulement : le décor 11 n'a aucune version gardée).
- (h) Correctif de test : `GenerateScheduleFailureTest` (`DROP TRIGGER` avec la transaction DAMA ouverte).
- (i) RGPD : la copie du froid porte des identifiants de coach (`TeamCoach.coachId`, `CoachPlayerMembership.coachId` et `position`) et **aucun nom libre**. L'effacement d'une personne ne les retire pas dans la PR 2 (ligne 20 du plan, PR 3). La revue de sécurité a conclu : acceptable, puisque rien n'est déployé entre deux PR. `position` (texte libre, 120 caractères) part déjà dans la demande au moteur et dans l'entrée figée de la version : la copie n'ajoute pas de classe de donnée.
- (j) **Limites** : `ClubActivityTraceTest` dépend de l'heure (échec observé une fois en franchissant minuit, heure de Paris) ; le Behat complet, mesuré en local, dépasse le plafond de 950 s : à mesurer en CI.

### PR 3 · « Charger : une seule définition »
- Route `POST /api/schedules/{id}/load` ; `regenerate-from` et `load-locks` retirées. Les refus « version en vigueur » et « plan de période validé » restent jusqu'à la PR 5.
- Migration irréversible : suppression de `schedule_structure_snapshot`.
- Supprime `StructureRestorer`, `StructureSnapshotter`, `StructureAnchor`, `RestoreFeasibility`, `ScheduleStructureSnapshot`, les capacités `canRegenerateFrom`, `canLoadLocks`, `regenerateFromBlockedReason`.
- Écran : `PlanningToolbar.tsx`, `PlanningPage.tsx`, `planning/api.ts`, `queries.ts`, `texts.ts`.
- Document : le plan du lot 7 est déjà amendé (sa Q10 sans objet, ses PR 3b et 4a ne touchent plus `StructureRestorer` ni `RestoreFeasibility`, son E3 pointe le lot 6) ; la PR vérifie qu'aucun texte du lot 7 ne cite encore ces deux classes.
- Tests : lignes 11 à 21. `/security-review` : oui.

**Livrée le 2026-11-26 (PR 3), sur `lot/conception`, non déployée.** Écarts au plan, tels que livrés :
- (a) `canLoad` est **faux sur la version déjà chargée** du plan (le geste ne changerait rien) ; il est vrai pour tout plan non validé, version terminée, aucune génération en cours dans la saison, version chargeable (`App\Plans\LoadFeasibility`, qui remplace `RestoreFeasibility` : copie présente, niveau des équipes engagées intact). `loadBlockedReason` rend le même texte que le 409 `load_blocked`.
- (b) Quand « Charger » change un lien de coach principal, les destinataires des collectes ouvertes sont **resynchronisés** (`CoachWishCampaignTokenSync::syncSeason`), les jetons ne sont pas recréés.
- (c) La clé étrangère du groupe n'est **pas** passée en `SET NULL` : le lien de groupe est vidé par `PlanStates::restore` sur les séances de **toutes** les versions de la saison (la séance d'un groupe retiré reste à sa place, sans son lien).
- (d) Les noms des règles réinsérées sont ceux de `PlanStates::RULE_NAMES`.
- (e) Le dialogue « Charger » ne nomme plus la version (`texts.ts`, `loadVersionDescription`, `loadVersionUnsavedLost`).
- (f) Migration `Version20261202120000`, irréversible (retour arrière = la sauvegarde d'avant migration) ; `LoadVersionTest` et `PlanStateTenantIsolationTest` sont bloquants (`blocking-tests.md`). L'effacement d'une personne (`PersonEraser`) retire ses identifiants des copies du froid (limite (i) de la PR 2 levée).
- (g) Limites relevées par la revue de sécurité, faible gravité : le contrôle « génération en cours » de `LoadVersionController` est lu hors du verrou de saison (fenêtre théorique) ; `NoNullPlanBranchTest` scrute encore `StructureAnchor`, supprimée ; plafond frontend `act-warnings-ceiling.json` à 23 pour 27.

### PR 4 · « La porte, lecture inchangée » (aucun comportement ne change)
- `App\Plans\InForce` existe et rend les lignes de travail. Passent par elle : `ConflictRadarLoader`, `MatchPlacementPayloadBuilder`, `PlacedSessionPersonConflictDetector`, la demande d'une période, `PeriodConstraintSelector`, `PlanBirth`, `PlanComposition::ensureLines`, les sept lectures du pointeur.
- Tests : lignes 22 et 23. `/security-review` : non.

**Livrée le 2026-11-27 (PR 4), sur `lot/conception`, non déployée.** Écarts au plan, tels que livrés :
- (a) `App\Plans\InForce` : `socleVersionId(?string)` (null sans saison, null sans version validée) et `rows(seasonId, familyClass)` (SQL natif hydraté en entités gérées, saison en clause explicite, la RLS borne le club ; niveau saison ou plan, tout autre niveau lève ; tri par identifiant, sauf `TeamCoach` en `created_at, id`). Les entités rendues sont **gérées, pas détachées** : le « détaché, jamais persisté » du contrat arrive en PR 5.
- (b) **Écart au « rien ne change »** : le placement des matchs (worker et `EngineRequestRecorder`) lisait toutes les saisons du club, il ne lit plus que la saison demandée. Effet correctif, prouvé par `tests/Integration/Plans/InForceTest.php` ; les références 1 à 21 restent identiques à l'octet.
- (c) Les lecteurs qui n'avaient pas d'`ORDER BY` lisent dans l'ordre des identifiants, plus dans l'ordre physique.
- (d) Naissance d'un plan : les règles de bien-être s'écrivent par `ON CONFLICT … DO NOTHING` règle par règle, au lieu d'un `NOT EXISTS` sur le plan entier (identique pour un plan neuf, seul cas atteignable) ; la copie de grille s'écrit en `INSERT … VALUES` après lecture par la porte (`PeriodSlotCopyKeepsEveryColumnTest` adapté mécaniquement).
- (e) La requête du pointeur existe deux fois (`InForce::socleVersionId` et `SchedulePlanProvisioner::chosenOfSeasonPlan`) : la porte ne peut dépendre du provisionneur (boucle via `PlanBirth`).
- (f) Garde : `Architecture/InForceDoorTest` (step `Blocking suite` de `ci.yml` et `blocking-tests.md`). **Limites** : le départage « le premier lien équipe-coach créé tient la séance » n'est tenu par aucun test (le décor des références ne distingue pas `created_at` de `id`), trou antérieur à la PR ; les cas du socle des références ne passent pas par la porte (`buildForPlan` n'est pas un lecteur nommé).

### PR 5 · « Retravailler ne détruit rien, la porte lit la copie »
- La porte lit la copie. Route `rework`. La branche socle de « Rouvrir » est retirée (`ReopenScheduleController.php:88-121`) : 409 pour un plan de saison, inchangée pour une période. `assertSeasonPlanNotChosen` supprimé (cinq sites) ; route `pending-changes` ; refus de `TeamStateProcessor.php:185` retiré ; lignes de règle des périodes propagées à la validation.
- Écran : barre et page du planning, `WizardLayout.tsx`, `TeamsStep.tsx`, `VenuesStep.tsx`, `SeasonPlanBanner.tsx`, textes.
- Entre la PR 5 et la PR 6 (jamais déployé) : retravailler ne détruit rien ; valider une version de travail supprime encore tous les plans de période pas commencés et la collecte ; une modification faite hors du planning ne se fait valoir qu'en régénérant puis en validant.
- Tests : lignes 24 à 38. `/security-review` : oui.

**Livrée le 2026-11-28 (PR 5), sur `lot/conception`, non déployée.** Écarts au plan, tels que livrés :
- (a) La porte `InForce` lit la copie de la version en vigueur et rend des objets **détachés**. Une ligne supprimée depuis la validation renaît de la copie ; une ligne née depuis est ignorée, sauf si elle désigne une équipe ou un gymnase nés depuis ; les lignes hors filtre froid (adjoint, contrainte datée) sont lues en direct. Une version en vigueur sans copie fait lever la porte (limite 8), prouvé par `InForceTest::testAVersionInForceWithoutACopyMakesTheDoorRaise` ; les décors de test qui pointent une version à la main reçoivent leur copie (`tests/SeasonPlanOf.php`, limite 11).
- (b) `PlanComposition::propagateSocleChanges(string $seasonId, ?PlanState $replaced, PlanState $inForce)` remplace la forme `(…, PlanChanges $changes)` : suivre la portée d'une règle exige l'ancienne portée de chacune. La propagation à la validation touche aussi les périodes déjà commencées (comportement d'avant, le différé à la validation de la PR 6 reste).
- (c) Routes `POST /api/schedules/{id}/rework` (gestionnaire) et `GET /api/schedule_plans/{id}/pending-changes` (lisible par un Membre ; `{pending, hasWorkVersion, changes:[{family,count,destroys}]}`). « En attente » = froid modifié OU version de travail (ligne 33). L'empreinte de la copie de travail est celle du plan quand rien n'attend, sinon celle de la version en vigueur.
- (d) « Rouvrir » un plan de saison répond 409 (`ReopenScheduleController::SEASON_PLAN_MESSAGE`) ; « Charger » accepte la version en vigueur elle-même ; `canLoad` ne refuse « plan validé » que pour une période (`ScheduleCapabilityResolver.php`).
- (e) Décision du fondateur (2026-11-28) : après « Retravailler », l'écran s'ouvre sur la version de travail ; sur la version en vigueur, tant qu'une version de travail existe, le bandeau dit « Vous regardez le planning en vigueur — vos modifications sont dans la version de travail. » avec « Ouvrir la version de travail ».
- (f) Entre la PR 5 et la PR 6 (jamais déployé) : une règle ajoutée après la naissance d'une période ne l'atteint qu'en régénérant puis en validant le socle. L'exemple « vacances / tout le club » de `plan-de-periode-en-overlay.feature` passe à `ignore` ; la promesse n'est plus jouée en Behat dans cette PR, elle est renvoyée à `ConstraintScopeChangeTest` (bloquant).
- (g) Tests obsolètes retirés, retournés ou réécrits, chacun avec son filet (liste : `git log` des commits de test) : `ReopenScheduleTest`, `LoadVersionGuardsTest`, `ParcImportAnalyzeTest`, `SchedulePlanLifecycleTest`, `ValidateScheduleTest`, `VenueOccupancyLifecycleTest`, `ClosureWindowExtendTest`, `PlanningPage.test.tsx`, `WizardPage.test.tsx`, `TeamDailyCapPanel.test.tsx`. Code mort retiré : dialogue « Rouvrir et supprimer », `dailyCapReadOnly`, `kindLocked`, `slotsLocked`.
- (h) **Limites** : le contrôle « génération en cours » de `rework` se fait hors du verrou de saison (même fenêtre théorique que `LoadVersionController`, relevée par la revue de sécurité, sans enjeu de droits) ; une écriture faite en console (graine, commande) laisse `cache.schedule` périmé (`CacheInvalidationListener` ne purge qu'à `kernel.terminate` ou en fin de message) ; le Behat complet, mesuré en local (25 min 42 s), dépasse le plafond CI de 950 s : le relèvement est une ligne datée du fondateur dans la PR finale ; `member-read-only.spec.ts` est complété mais l'e2e n'a pas été jouée ; le témoin « version antérieure » n'a pas pu être rendu à l'écran.

### PR 6 · « Valider dit ce qui change, avec ou sans régénérer »
- `validate-impact` rend la liste ; `validate` répond 409 `changes_to_confirm` ; suppression par le froid seul ; `FutureHolidayCollectePurger` supprimé ; `overlaysDroppedOnValidate` supprimée ; migration `schedule_plan.cold_changed_at`.
- Geste « Valider ces modifications » : route `apply-pending-changes` ; contrôle d'incohérences par le verdict existant, découpé ; coach des séances, entrée figée et empreinte de la version en vigueur mis à jour (E6) ; référence `validation-22-controle-en-vigueur.json`.
- Document, dans cette PR : l'exception E6 dans l'invariant « version chargée » de `CLAUDE.md` §6.
- Écran : `ValidateDialog.tsx`, `PlanningPage.tsx`, `SeasonPlanBanner.tsx`, `coach-wishes/WishesTab.tsx`, textes.
- Tests : lignes 39 à 49. `/security-review` : oui.

**Livrée le 2026-11-28 (PR 6), sur `lot/conception`, non déployée.** Écarts au plan, tels que livrés (décisions de l'orchestrateur, que le fondateur peut contester) :
- (a) **Le jeton de `validate`** n'est exigé que si des plannings de période sont supprimés (le §5 disait « si une ligne détruit »). `apply-pending-changes` l'exige toujours. Les deux relisent le jeton sous le verrou de la saison.
- (b) **La mention « Réponse donnée avant la modification du planning de saison »** compare `updatedAt` de la doléance à `schedule_plan.cold_changed_at` (migration `Version20261206120000`, posée par `validate` et par `apply-pending-changes` quand du froid change) : une doléance retouchée après la validation perd la mention.
- (c) **`coachChanges`** s'ajoute au contrat du §5, sur `validate-impact` comme sur `pending-impact` : `[{teamId, teamName, coachId, coachName}]`, `coachId` nul quand l'équipe n'a plus de coach principal. Deux phrases : « Les séances de {équipe} sont désormais tenues par {coach}. » et « Les séances de {équipe} n'ont plus de coach principal. »
- (d) **Les boutons du bandeau** (« Valider ces modifications », régénérer) ne s'affichent que si du froid attend (ligne 167 du plan) : une version de travail seule n'en offre pas.
- (e) **Le contrôle de cohérence de `pending-impact`** ne tourne que pour un gestionnaire (`checkPerformed`). Un Membre lit l'annonce sans que le moteur travaille.
- (f) **Clés gardées** : `orphanedFixtures` et `declaredOrphanedFixtures` (celles que `validate-impact` servait déjà) à la place de `orphanCount` et `declaredCount` du §5. `PlanStates::replace` n'est pas créé : `store` remplace déjà.
- (g) **Textes ajoutés hors du plan** : `noMainCoachAnyMore`, `checkingCoherence`, `checkFailed`, `retry`, `changesToConfirmAgain`, `validateReadOnlyNote`.
- (h) `FutureHolidayCollectePurger` et la capacité `overlaysDroppedOnValidate` sont supprimés ; le 409 `overlays_exist` de `validate` devient `changes_to_confirm`. Gardes bloquantes ajoutées : `InForceCoherenceTest`, `ApplyPendingChangesGuardsTest` (step `Blocking suite`, `blocking-tests.md`) ; feature Behat `valider-des-modifications-sans-regenerer.feature`, référence `validation-22-controle-en-vigueur.json`.
- (i) **Limites** : valider une version de travail non régénérée annonce « Rien ne change » et le froid reste en attente, le dialogue ne le dit pas ; le focus initial du dialogue reste sur le panneau, pas sur « Annuler » (comportement de `Modal`) ; la liste des incohérences n'est pas plafonnée à l'écran ; la relecture « génération en cours » sous le verrou d'`apply-pending-changes` n'est pas testée (fenêtre de concurrence) ; aucun test PHPUnit ne prouve « plannings gardés sans froid changé », seul le Behat le prouve ; le `coachChanges` de `validate-impact` n'est pas prouvé par un test (même maison que `pending-impact`) ; le code front mort `OverlaysExistError` est laissé en place ; le plafond Behat, déjà dépassé, reste à relever par le fondateur dans la PR finale.

### PR 7 · « La saison suivante reprend le socle en vigueur », et les documents
- `SeasonTransitionService` lu sur le catalogue ; réservations du socle reprises.
- ADR-0007 à la place de l'ADR-0002, le reste de `CLAUDE.md` §6 et §7.1, état des lieux, fiche du lot (dont les étapes de conversion du §6).
- Tests : lignes 50 à 52. `/security-review` : non.

## 5. Contrats nouveaux

```php
// PR 2
#[Family(Level $level, ?string $keptAtSeasonPurge = null, ?string $keptAtErasure = null, ?bool $copiedAtPlanBirth = null,
         ?array $cold = null,            // OBLIGATOIRE aux niveaux saison et plan : [] = libre, Family::ALL, ou liste de champs
         array $coldRows = [],           // ex. ['role' => 'main'], ['calendarEntryId' => null]
         bool $ofSocle = false,
         ?bool $copiedToNextSeason = null)]   // OBLIGATOIRE aux niveaux saison et plan
FamilyCatalog::coldOf(SchedulePlanType $type): list<class-string>
FamilyCatalog::copiedToNextSeason(): list<class-string>
App\Plans\PlanState                      // changesTo(PlanState $other): PlanChanges ; withoutDeadReferences(): PlanState (lignes ET champs)
App\Plans\PlanChanges                    // isEmpty(): bool ; destroys(): bool (vrai dès qu'un champ froid diffère) ; token(): string
                                         // lines(): list<array{family: string, count: int, destroys: bool}>
                                         //   la ligne « séances déplacées » porte toujours destroys: false
App\Plans\PlanStates::capture(string $planId): PlanState
App\Plans\PlanStates::store(Schedule $version, PlanState $state): void      // lève en cas d'échec
App\Plans\PlanStates::of(string $scheduleId): PlanState                     // lève si la version n'a pas de copie
App\Plans\PlanStates::syncRows(Schedule $loaded, string $familyClass, array $rowIds): void
//   ligne vivante -> écrite dans la copie ; ligne disparue -> retirée de la copie
App\Entity\ScheduleState implements TenantOwnedInterface                    // #[Family(Level::VERSION)]
// schedule_state : id, created_at, updated_at, club_id, season_id, schedule_id UNIQUE, data json
//   FK (schedule_id) -> schedule (id) ON DELETE CASCADE ; RLS forcée
// bin/console app:plans:capture-version-states
//   conversion du lot, jouée UNE fois par la procédure ; ne copie que les versions validées ou chargées
//   nées AVANT la migration qui crée schedule_state
// bin/console app:plans:capture-version-states --check
//   n'écrit rien ; code 1 si une version validée ou chargée n'a pas de copie

// PR 3
// POST /api/schedules/{id}/load -> 200 {id, status} ; 404 identique (inconnue ou autre club) ;
//   409 (non terminée, génération en cours, {code: 'load_blocked', error} si une équipe engagée changerait de niveau)
App\Plans\PlanStates::restore(string $planId, PlanState $state): void
App\Plans\PlanStates::forget(string $clubId, string $familyClass, string $rowId): void
// ScheduleCapabilities : canLoad: bool, loadBlockedReason: ?string

// PR 4
App\Plans\InForce::socleVersionId(?string $seasonId): ?string
App\Plans\InForce::rows(string $seasonId, string $familyClass): list<object>   // objets détachés, jamais persistés

// PR 5
// POST /api/schedules/{id}/rework -> 201 {id} ; 409 si la version n'est pas celle en vigueur d'un plan de saison, ou si une génération court
//   la copie de travail reprend séances, copie d'état, entrée figée et empreinte de la version en vigueur
// POST /api/schedules/{id}/reopen -> 409 pour un plan de saison ; inchangée pour une période
// GET /api/schedule_plans/{id}/pending-changes -> { pending: bool, hasWorkVersion: bool, changes: list<{family, count, destroys}> }
App\Plans\PlanComposition::propagateSocleChanges(string $seasonId, PlanChanges $changes): void

// PR 6
// GET /api/schedules/{id}/validate-impact -> { orphanCount, declaredCount, changes: list<{family, count, destroys}>, changesToken,
//     destroyedPeriodPlans: list<{entryId, title}>, keptPeriodPlans: int, keptWishes: int }
// POST /api/schedules/{id}/validate  body {confirmChanges?: string}
//     -> 409 {code: 'changes_to_confirm', ...} si une ligne détruit et que le jeton manque ou diffère
// GET /api/schedule_plans/{id}/pending-impact -> même forme que validate-impact, plus
//     incoherences: list<{slotId, teamId, teamName, dayOfWeek, startTime, rule, label}>,
//     missingSessions: list<{teamId, teamName, count}>, checkInconclusive: bool
// POST /api/schedule_plans/{id}/apply-pending-changes  body {confirmChanges?: string}
//     -> 200 {id: <version en vigueur>} ; 409 {code: 'changes_to_confirm', ...même forme que pending-impact} sans jeton ou jeton périmé ;
//     409 si le plan n'est pas un plan de saison, s'il n'a pas de version en vigueur, si rien n'attend, si une génération court
App\Plans\PendingChanges::apply(string $planId): void
//   SEUL écrivain de la version en vigueur : copie d'état (PlanStates::replace), coach des séances des équipes
//   dont le coach principal a changé, snapshotData + snapshotHash recalculés, payloadGraft remis à null
App\Plans\PlanStates::replace(Schedule $inForce, PlanState $state): void
App\Plans\InForceCoherence::touchedSlotIds(string $planId, PlanChanges $changes): list<string>   // table du §3.6
App\Plans\InForceCoherence::check(string $planId): array{incoherences: list<array>, missingSessions: list<array>, inconclusive: bool}
//   InForceCoherence::BATCH_SIZE = 50 ; InForceCoherence::MAX_BATCHES = 6 ; au-delà, ou sur un refus ou une erreur du moteur : inconclusive
MoveSlotService::verdictFor(Schedule $version, array $candidateSlotIds): array{valid: bool, indeterminate: bool, violations: list<array>}
//   lecture seule, 50 séances au plus : la demande de buildBasePayloadExcluding, les candidates à leur place actuelle, aucun écrit
// schedule_plan.cold_changed_at datetimetz NULL
// GET /api/coach_wishes : answeredBeforeSocleChange: bool
// ScheduleCapabilities.overlaysDroppedOnValidate : supprimée
```
`engine/CONTRACT_VERSION` : inchangé (1.12). `/validate-assignments` est utilisé tel qu'il existe, dans ses bornes.

## 6. Conversion, irréversibilité, mesures

- **Règle de conversion** : la copie de chaque version validée ou chargée est l'état vivant du jour. Après conversion, aucun plan n'est « en attente ».
- **La capture a lieu une seule fois, dans la procédure manuelle du lot**, site fermé. Étapes à porter dans `docs/ops/deploy.md` par la dernière PR du lot conception ; ce plan les consigne, et la PR 7 les reporte dans la fiche du lot 5 du découpage :
  1. migrations ;
  2. `app:plans:capture-version-states` ;
  3. `app:plans:capture-version-states --check` : code 0 exigé, sinon le site ne rouvre pas ;
  4. `app:schedule:prune-unread-snapshot-fields` ;
  5. réouverture.
- Rien d'automatique ne capture. Une version née après la migration et sans copie n'est jamais réparée.
- L'échec se joue : `CaptureVersionStatesCommandTest` et une étape de la répétition sur la copie de production n° 2 retirent une copie, vérifient que `--check` rend 1 et que la procédure s'arrête.
- **Migration irréversible** : suppression de `schedule_structure_snapshot` (PR 3). Aucun `down()`. Les migrations du lot entrent dans `LOT_MIGRATIONS`.
- **Preuve** : références 1 à 15 à l'octet à chaque PR, 20 et 21 selon le §7 ; chaîne ; copie de production n° 2 : `diff -r` vide, et nombre de versions validées ou chargées = nombre de copies.

**Mesures à demander à `link`** (lecture seule, `club.is_demo = false`, comptes seulement ; le fondateur ouvre le tunnel) :

| # | Requête | Attendu |
|---|---|---|
| 1 | `SELECT p.type, (p.chosen_schedule_id IS NOT NULL) AS valide, count(*) FROM schedule_plan p JOIN club c ON c.id = p.club_id WHERE NOT c.is_demo GROUP BY 1, 2` | 4 plans de saison, 6 de période ; le nombre de validés donne le compte de copies attendu |
| 2 | Plans de saison sans version validée, avec au moins un plan de période dans la saison | 0, sinon retour |
| 3 | Plans portant plus d'une version terminée | 0 |
| 4 | Plans dont `lock_source_schedule_id` et `chosen_schedule_id` sont non nuls et différents | 0 |
| 5 | `SELECT count(*) FROM schedule_structure_snapshot` | 2 |
| 6 | Versions `PENDING` ou `GENERATING`, placements en cours | 0 |
| 7 | Plans de période pas commencés ; en cours | 0 et 0, sinon retour |
| 8 | Campagnes, doléances, demandes de mutualisation sur des vacances à venir | à relever |
| 9 | Par plan de saison : réservations, mutualisations, passerelles, règle de trajet | à relever |
| 10 | Coachs véhiculés ; coachs avec un nombre de jours maximum ; liens de coach-joueur | à relever |
| 11 | Équipes avec un gymnase imposé ; lignes de coach principal par équipe supérieures à 1 | à relever |
| 12 | Groupes mutualisés, réservations et créneaux portant un libellé | à relever |
| 13 | Séances par version validée de plan de saison (maximum par club) ; séances qui nomment un coach | à relever ; au-dessus de 300 séances, le contrôle d'une règle de club sera « non concluant » |

## 7. Tableau comportement → scénario (brief de `tank`)

| # | PR | Comportement | Preuve, rouge avant `neo` |
|---|---|---|---|
| 1 | 1 | La demande du socle d'une saison suivante est figée | `EngineRequestReferenceTest`, cas 20 (bloquant) |
| 2 | 1 | La demande de placement avec une équipe créée après la validation est figée | Même test, cas 21 |
| 3 | 1 | Charger rend grille, réservations, règles, séances par équipe | `charger-une-version-ramene-son-etat.feature` : « Étant donné un planning de saison en version 2 » / « Quand je charge la version 1 » / « Alors la grille, les réservations et les règles sont celles de la version 1 » |
| 4 | 1, puis chaque PR | Toutes les demandes après toutes les migrations | `Lot4MigrationChainTest` (bloquant) |
| 5 | 2 | Une famille de niveau saison ou plan qui ne dit pas froid ou libre, ou pas « saison suivante », fait échouer le balayage | `FamilyDeclarationSweepTest` (bloquant), deux cas |
| 6 | 2 | Toute version terminée porte sa copie ; si la copie ne s'écrit pas, la génération échoue, sans crédit | `tests/Integration/Plans/PlanStatesTest.php`, cinq cas ; `GenerateScheduleFailureTest`, un cas |
| 7 | 2 | Renommer une équipe, une règle ou un groupe, ajouter un adjoint, cocher salarié ou véhiculé, changer les jours maximum d'un coach, corriger un temps de trajet : à jour ; changer les séances, la grille ou un coach-joueur : périmé | `la-boucle-de-travail-du-gestionnaire.feature`, plan de scénario « Quand je <geste> » / « Alors le planning est <état> », huit exemples |
| 8 | 2 | Chaque clé de la demande a une seule source, qui se comporte comme écrit au §3.3 | `tests/Integration/EngineRequestKeySourcesTest.php`, décors 11, 12, 13, 5 ; ajouté au step `Blocking suite` et à `blocking-tests.md` |
| 9 | 2 | Conversion : copie égale à l'état vivant, aucun plan « en attente » ; capture interrompue : `--check` rend 1 ; version née après la migration et sans copie : jamais réparée | `Lot4MigrationChainTest`, comptes ; `tests/Integration/Command/CaptureVersionStatesCommandTest.php`, trois cas ; `PruneUnreadSnapshotFieldsCommandTest`, un cas |
| 10 | 2 | La copie d'un club est invisible d'un autre ; elle part avec la saison et le club | `RlsIsolationTest`, `PurgeCompletenessTest` (bloquants) |
| 11 | 3 | Charger la version 1 depuis la 3 rend passerelles, contraintes, mutualisations, bien-être, règle de trajet, coach principal, coach-joueur, gymnase imposé | Feature de la ligne 3, scénario neuf. Exemples : séance réservée, libre, d'un groupe mutualisé |
| 12 | 3 | Charger ne crée ni ne supprime équipe, gymnase, coach, catégorie ; noms, adjoints, libellés, durée de match inchangés | Même feature : « Étant donné une durée de match modifiée et une catégorie créée après la version 1 » / « Quand je charge la version 1 » / « Alors la durée et la catégorie sont inchangées » |
| 13 | 3 | Charger remet rang, catégorie, niveau d'une équipe existante et « divisible » d'un gymnase ; refusé sans rien écrire si une équipe engagée changerait de niveau ; un gymnase imposé ou une catégorie supprimés depuis ne reviennent pas et ne laissent rien « en attente » | `tests/Integration/Plans/LoadVersionTest.php`, quatre cas ; ajouté au step bloquant |
| 14 | 3 | Charger une version de vacances rend équipes écartées, séances propres, grille, verrous | `plan-de-periode-en-overlay.feature`, scénario neuf ; `un-verrou-de-periode-survit-a-la-regeneration.feature:17`, étape réécrite |
| 15 | 3 | Une version ne touche que son plan : charger le socle laisse les lignes de règle et d'équipe d'une période commencée ; une règle désactivée dans le plan de Noël le reste après « charger la version 1, puis revenir » ; charger une période laisse le socle | `LoadVersionTest`, trois cas |
| 16 | 3 | Une équipe supprimée depuis ne revient pas ; une équipe créée depuis garde sa ligne ; charger avec des réglages non générés prévient puis charge | `LoadVersionTest`, deux cas ; `au-dessus-du-palier-plus-de-sorties.feature:49` réécrit ; `PlanningPage.test.tsx`, un cas |
| 17 | 3 | Abus : version d'un autre club, 404 identique ; Membre, 403 ; saison archivée, refus ; version non terminée ou génération en cours, 409 ; une copie citant l'équipe d'un autre club n'écrit rien | `tests/Api/LoadVersionGuardsTest.php` ; `tests/Security/PlanStateTenantIsolationTest.php` (bloquant) ; `MemberWriteSweepTest`, `SeasonReadonlyTest` |
| 18 | 3 | Charger écrit une trace `LOCK_CHANGED` agrégée, socle comme période | `tests/Integration/Api/LoadVersionAuditTest.php` |
| 19 | 3 | Les deux anciennes routes et la table de photos n'existent plus | `LoadVersionGuardsTest`, deux cas ; chaîne |
| 20 | 3 | Effacer une personne la retire des copies | `effacer-une-personne-efface-tout.feature`, étape ; `PersonErasureLeavesNoTraceTest` |
| 21 | 3 | Une retouche de planning met la copie à jour pour ses seules lignes : un groupe créé y entre ; un groupe dissous depuis le planning en sort et « Charger » ne le ramène pas ; une réservation retirée en sort ; une réservation saisie au Wizard et jamais générée n'y entre pas | `LoadVersionTest`, quatre cas |
| 22 | 4 | Les demandes passent par la porte sans changer | Références 1 à 15, 20, 21 à l'octet |
| 23 | 4 | Les lecteurs nommés ne lisent ni le pointeur ni une famille froide hors de la porte | `tests/Architecture/InForceDoorTest.php`, ajouté au step bloquant |
| 24 | 5 | Retravailler garde l'en vigueur, les matchs ouverts, les plans de période, les doléances ; une copie de travail jamais validée ne change rien ; née sans rien en attente, elle est à jour | `le-socle-commande-les-plans.feature:15-19` réécrit : « Quand je retravaille le planning de saison » / « Alors les plannings de période subsistent et les matchs restent ouverts » / « Et la copie de travail est à jour » |
| 25 | 5 | Retravailler alors que des modifications attendent déjà : elles sont gardées | Même feature : « Étant donné un coach principal changé après la validation » / « Quand je retravaille le planning de saison » / « Alors le changement de coach est toujours en attente » |
| 26 | 5 | Chaque champ froid, modifié tour à tour sans valider, laisse inchangées les demandes 12, 13, 5, 6, 21 | `EngineRequestKeySourcesTest`, cas « non validé » (bloquant) |
| 27 | 5 | Changer le coach principal ou un coach-joueur ne change pas le radar tant que rien n'est validé ; ajouter un adjoint vaut tout de suite | `les-conflits-d-un-match-disent-la-verite.feature`, trois exemples |
| 28 | 5 | Une équipe créée après la validation, qui porte un match, est lue en direct ; un coach principal de la copie supprimé depuis est ignoré | Référence 21 ; `tests/Integration/Plans/InForceTest.php`, deux cas |
| 29 | 5 | Des vacances créées pendant des modifications naissent sur la grille en vigueur | `le-socle-commande-les-plans.feature:27`, étape |
| 30 | 5 | Supprimer, élargir ou dater une règle du socle ne touche les lignes des périodes qu'à la validation | `tests/Api/ConstraintScopeChangeTest.php` (bloquant), réécrit |
| 31 | 5 | Générer, régénérer, charger, importer le parc sont permis avec un socle en vigueur ; « Rouvrir » un plan de saison est refusé | `tests/Security/SeasonPlanInForceTest.php` (bloquant) réécrit ; `l-import-du-parc-en-un-geste.feature`, étape |
| 32 | 5 | La version en vigueur ne se modifie par aucun des neuf guichets | `LoadedVersionIsTheOnlyEditableTest` (bloquant) |
| 33 | 5 | « En attente » : vrai après un froid modifié ou une version de travail ; faux après validation | Feature de la ligne 24, scénario neuf ; `PlanningPage.test.tsx`, `SeasonPlanBanner.test.tsx` |
| 34 | 5 | Revenir au planning en vigueur remet l'état de travail et éteint « en attente » | Feature de la ligne 24, étape inverse |
| 35 | 5 | Le plafond par jour se modifie socle validé | `tests/Api/TeamSessionsUnderValidatedSocleTest.php` réécrit |
| 36 | 5 | Sans socle jamais validé, périodes et matchs restent refusés | `le-socle-commande-les-plans.feature:34-38`, décor réécrit ; `les-matchs-attendent-le-planning-de-saison.feature` vert |
| 37 | 5 | La porte ne rend jamais l'état d'un autre club | `PlanStateTenantIsolationTest`, un cas |
| 38 | 5 | Une période validée se rouvre comme aujourd'hui | `un-planning-de-periode-commence-se-valide-seul.feature`, vert sans modification |
| 39 | 6 | Valider annonce ce qui change, famille par famille | `le-socle-commande-les-plans.feature`, scénario neuf : « Quand je demande ce que change la validation » / « Alors j'apprends que 1 règle et 2 créneaux changent » |
| 40 | 6 | Seul le froid supprime les plans de période pas commencés ; une séance déplacée s'affiche et ne supprime rien ; un verrou ne s'affiche pas et ne supprime rien | Même feature, `:9-13` en plan de scénario « Quand je <geste> puis je valide » / « Alors la liste annonce <liste> » / « Et les plannings de période à venir sont <sort> ». Exemples : renommer une équipe, rien, gardés ; renommer un groupe mutualisé, rien, gardés ; verrouiller une séance sans la déplacer, rien, gardés ; régénérer sans rien changer avec une séance qui bouge, « 1 séance déplacée », gardés ; déplacer une séance à la main, « 1 séance déplacée », gardés ; déplacer une séance et la verrouiller, « 1 séance déplacée », gardés ; ajouter une règle, « 1 règle », supprimés |
| 41 | 6 | Un plan de période commencé reste | Même feature, exemple conservé |
| 42 | 6 | Campagne et doléances restent ; une doléance d'avant porte la mention | Même feature, `:21-25` réécrit ; `voeux-des-coachs.feature`, étape |
| 43 | 6 | Une liste changée depuis l'annonce redemande confirmation | `tests/Api/ValidateChangesConfirmationTest.php`, deux cas |
| 44 | 6 | Les matchs d'un gymnase disparu repassent « à placer » | Tests de `OrphanedFixtureFinder`, verts sans modification |
| 45 | 6 | Le compte de plans supprimés n'a qu'une source | `ScheduleCapabilityParityTest`, adapté |
| 46 | 6 | Valider ces modifications sans régénérer : aucune séance ne change de place, de jour, d'heure, de gymnase ni de verrou ; aucune version ne naît ; les séances des U13 que tenait l'ancien coach principal sont tenues par le nouveau, que le radar compte ; **« Retravailler » juste après donne une copie de travail à jour** ; c'est le seul guichet qui écrit la version en vigueur, et seulement le coach de ses séances, son entrée figée et son empreinte | Feature neuve `valider-des-modifications-sans-regenerer.feature` : « Étant donné un planning de saison en vigueur et le coach principal des U13 changé » / « Quand je valide ces modifications » / « Alors les séances du planning en vigueur sont à la même place » / « Et le radar des matchs compte le nouveau coach des U13 » / « Quand je retravaille le planning de saison » / « Alors la copie de travail est à jour ». `LoadedVersionIsTheOnlyEditableTest` (bloquant), cas d'exception : avant et après le geste, seuls le coach des séances, l'entrée figée et l'empreinte diffèrent ; `PruneUnreadSnapshotFieldsCommandTest`, un cas : le réalignement après le geste ne périme rien |
| 47 | 6 | Le contrôle dit les incohérences et ne bloque pas ; il ne soumet que les séances touchées ; il découpe au-delà de 50 séances | Même feature, plan de scénario « Alors le contrôle annonce <annonce> » / « Et la validation reste possible ». Exemples : nouveau coach libre, aucune incohérence ; nouveau coach déjà en séance au même moment, 1 incohérence qui nomme la séance et la règle ; équipe passée à 3 séances, 1 séance manquante ; créneau du mardi retiré au Gymnase Nord, 1 incohérence sur la séance qui y était placée ; moteur hors délai, contrôle non concluant. `tests/Integration/Plans/InForceCoherenceTest.php` : règle de club sur 120 séances, trois demandes de 50 au plus ; 301 séances touchées, non concluant sans appel ; refus 422 du moteur, non concluant ; les séances touchées de chaque ligne de la table du §3.6 |
| 48 | 6 | Valider un changement de coach supprime les plans de période pas commencés, annoncés avant ; inverse : « Revenir au planning en vigueur » abandonne les modifications | Même feature, deux étapes |
| 49 | 6 | La demande du contrôle est figée ; abus : Membre 403, autre club 404 identique, jeton périmé 409, plan de période ou rien en attente 409 | `EngineRequestReferenceTest`, cas 22 (bloquant) ; `tests/Api/ApplyPendingChangesGuardsTest.php` ; `ValidateAssignmentsContractSchemaTest` vert sans modification |
| 50 | 7 | La saison suivante reprend réservations, passerelles, règles, bien-être, trajet, grille, composition ; ni mutualisations ni entente | `preparer-la-saison-suivante-reprend-la-structure.feature`, étapes ; la référence 20 change des seules réservations |
| 51 | 7 | Des modifications en attente ne suivent pas ; un club jamais validé part de son état de travail | Même feature, deux exemples |
| 52 | 7 | Une famille déclarée reprise sans routine fait échouer le balayage | `FamilyDeclarationSweepTest`, un cas |

**Retirés par `tank`, avec leur filet** : `StructureRestorerTest`, `StructureSnapshotterTest`, `StructureFamiliesStayInSyncTest`, `RegenerateFromVersionTest`, `LoadLocksTest` (filet : lignes 11 à 19, `LoadVersionTest` à la place de `blocking-tests.md:226`) ; `voeux-des-coachs.feature:47-53` et `RgpdExportTest::testLoadingAVersionWithoutACoachRemovesHimFromTheClubExport` (`tests/Security/RgpdExportTest.php:133`) : la règle « charger retire un coach » disparaît ; l'export sans un coach supprimé reste prouvé par le test qui précède dans le même fichier.

**Preuve = captures (`switch`)** : dialogue de validation (rien ne change, liste, liste avec suppression, séance déplacée seule) ; dialogue « Valider ces modifications » (aucune incohérence, incohérences, contrôle non concluant) ; barre du planning en vigueur, avec et sans modifications en attente ; Wizard du socle validé ; bandeau du cockpit ; dialogues « Retravailler », « Charger », « Revenir », refus de chargement ; onglet des doléances avec la mention.

## 8. Carte des conséquences

| Comportement | Qui écrit | Qui lit | Garde avant | Garde après |
|---|---|---|---|---|
| Copie d'une version | `GenerateScheduleHandler.php:287`, `:390` | `StructureRestorer.php:81-90` | Photo, socle seul, échec avalé | `PlanStates`, tout plan, échec bloquant |
| Copie après une retouche de planning | `MutualizeSlotService.php:494-498`, `ReservationRemoval.php:48-51`, `MoveSlotService` | « Charger » | Aucune | Lignes touchées synchronisées, retraits compris |
| Copie de la version en vigueur | — | La porte | Sans objet | Remplacée par « Valider ces modifications » seulement |
| Version en vigueur | Aucun guichet (`VersionEditability.php:34-41`) | Planning, matchs, radar | Jamais écrite | Un seul écrivain, « Valider ces modifications » : coach des séances, entrée figée, empreinte (E6) |
| Coach qui tient une séance | `ScheduleResultImporter.php:151`, `MoveSlotService.php:1003` | `PlacedSessionPersonConflictDetector.php:281-296`, `:439-442`, `Conflicts/DayHoldings.php:339-345` | Posé à la génération et au déplacement | Aussi réécrit par le geste, pour les équipes dont le coach principal a changé |
| Entrée figée et empreinte d'une version | `GenerateScheduleHandler.php:261-262`, `PeriodPlanTranscriber.php:237-238` | Bandeau périmé ; `PruneUnreadSnapshotFieldsCommand.php:218-225`, `PersonEraser.php:223-228` | Celles de la génération | Recalculées par le geste, avec la copie |
| « Charger » | `RegenerateFromVersionController.php:116-126`, `LoadLocksController.php:106` | `PlanningToolbar.tsx:270-288` | Deux routes | Une route |
| Trace d'audit du chargement | `LoadLocksController.php:114-121` | Journal d'audit | Période seule | Tout plan |
| Équipe engagée et « Charger » | `StructureRestorer.php:416-422` | `capabilities`, ancienne Q10 du lot 7 | Refus si l'équipe disparaîtrait | Refus si son niveau changerait |
| Champ d'équipe vers un gymnase ou une catégorie disparus | — | « Charger », différence de copies | Sans objet | Non remis, non compté |
| Jetons de collecte | `StructureRestorer.php:148` | Liens des coachs | Recréés | Intacts |
| Lignes de règle d'une période | `ConstraintStateProcessor`, `Deletion/ScopedConstraintStep`, `ensureLines` | `PlanComposition.php:207-228`, `PeriodConstraintSelector` | Au geste | À la validation du socle, avec ou sans régénération |
| Suppression des plans de période à la validation | `ValidateScheduleController.php:208-213` | Dialogue | Toute version différente | Un champ froid qui diffère, rien d'autre |
| Verrou posé sur une séance | Planning | Prochaine génération | Compte dans l'empreinte | Inchangé ; hors de la liste ; ne supprime rien |
| Séance déplacée | `MoveSlotService.php:127`, régénération | Dialogue de validation | Non annoncée ; la version différente supprimait tout | Annoncée pour information ; ne supprime rien |
| Compte de plans supprimés | `ScheduleCapabilityResolver.php:169-179` | `api.ts:98` | Ancienne règle | Supprimé |
| Collecte et doléances | `ValidateScheduleController.php:217-219`, `ReopenScheduleController.php:119` | `WishesTab.tsx` | Purgées | Conservées, mention |
| « Rouvrir » un plan de saison | `ReopenScheduleController.php:88-124` | Barre du planning | Dépointe, détruit, purge | 409 ; branche retirée (PR 5) |
| Pointeur du socle | `SchedulePlanProvisioner.php:150`, `:541` | `SocleGuard`, `FixtureStateProcessor.php:91`, `socle.ts:18-21` | Rouvrir le vide | Jamais vidé après la première validation |
| Génération socle validé | Cinq sites de `assertSeasonPlanNotChosen` | Wizard | Refus 409 | Permis |
| Empreinte | `ScheduleConstraintBuilder.php:188` | Bandeau périmé | Tout ce qui part au moteur | Sans les clés libres |
| Coach principal, coach-joueur | Wizard, immédiat | `ConflictRadarLoader.php:79`, `:84`, `MatchPlacementPayloadBuilder.php:136` | Lignes vivantes | Copie en vigueur ; fait valoir par « Valider ces modifications » |
| Verdict du moteur sur des séances | `MoveSlotService.php:1407-1427`, `EngineClient.php:83` | Déplacement d'une séance | Avant chaque déplacement, 50 candidates au plus (`validate_input_schema.py:38`) | Aussi avant « Valider ces modifications », en lecture seule, découpé par 50 |
| Passerelle | Wizard, immédiat | `MatchPlacementPayloadBuilder.php:133`, demande d'une période `:988` | Lignes vivantes | Copie en vigueur |
| Équipe créée après validation | `TeamImportGate.php:29-31`, Wizard | Radar, placement | Lignes vivantes | Lignes vivantes |
| Jours rouverts d'une période | Wizard de période | `TrainingCalendarContext.php:63-84` | Lignes vivantes | Inchangé (E4) |
| Naissance d'un plan de période | `PlanBirth::copy` | Wizard de période | Lignes du socle | Copie en vigueur |
| Saison suivante | `SeasonTransitionService.php:175` | Nouvelle saison | Lignes vivantes, sans réservations | Copie en vigueur, avec réservations |
| Conversion | Procédure manuelle du lot | La porte | — | Capture unique, `--check` bloquant |
| Demande au moteur | `EngineRequestComposer` | Moteur | — | Identique ; des demandes de verdict de plus, même contrat |

## 9. Parcours de bout en bout

- **Changer le coach principal, planning validé.** Cockpit, « Modifier les données du club », étape Coachs, changer le coach principal des U13. Le bandeau dit « en attente ». Bouton « Valider ces modifications » : le dialogue liste ce qui change, les plans de période supprimés, les incohérences. « Valider » : aucune séance ne bouge ; les séances des U13 affichent le nouveau coach ; le radar le compte. Inverse : « Revenir au planning en vigueur ». Variantes : nouveau coach déjà en séance au même moment (annoncé, permis) ; séance réservée des U13 (reste à sa place) ; équipe passée à 3 séances (séance manquante annoncée) ; règle de club modifiée dans un grand club (contrôle en plusieurs demandes, ou « non concluant ») ; préférer régénérer (bouton « Régénérer », puis valider la nouvelle version) ; « Retravailler » juste après : copie de travail à jour.
- **Retravailler.** Bouton à la place de « Rouvrir » sur la version en vigueur. Une version de travail apparaît, chargée ; rien d'autre ne change tant qu'elle n'est pas validée. Inverse : « Revenir au planning en vigueur ». Variantes : séance réservée, libre ; modifications déjà en attente (gardées) ; période : « Rouvrir » inchangé.
- **Déplacer ou verrouiller une séance, puis valider.** Retravailler, déplacer la séance des U13 du mardi au jeudi, valider la copie : le dialogue annonce « 1 séance déplacée » et « Aucun planning de période n'est supprimé ». Variantes : la verrouiller en plus (même annonce) ; verrouiller une séance sans la déplacer (rien n'est annoncé) ; plan de période pas commencé (gardé dans les trois cas).
- **Valider après avoir changé du froid.** Une règle ajoutée puis une régénération : la liste et les plans supprimés s'affichent. Inverse : « Annuler ».
- **Charger.** Sélecteur de version, « Charger cette version ». Inverse : charger l'autre. Variante période : même bouton.
- **Préparer la saison suivante.** Geste inchangé ; atteignable à partir du 1er mai.
- **Saison passée, Membre** : lecture seule.

**Textes qui changent, mot pour mot**

| Où | Texte |
|---|---|
| Bouton (`texts.ts:106`), plan de saison | « Retravailler » |
| Dialogue, titre | « Retravailler « {plan} » ? » |
| Dialogue, corps | « Une copie de travail est créée. Le planning validé reste en vigueur, et rien ne change tant que vous ne validez pas cette copie. » |
| Dialogue, ajout si des modifications attendent | « Vos modifications en attente sont gardées. » |
| Phrase à saisir (`:90`) | Supprimée pour le plan de saison |
| Bandeau « en attente » | « Des modifications attendent la validation. » |
| Boutons du bandeau | « Valider ces modifications » ; « Régénérer » |
| Dialogue, titre | « Valider ces modifications ? » |
| Dialogue, corps | « Aucune séance ne change de place : rien n'est régénéré. Les modifications valent ensuite pour les matchs et les plannings de période. » |
| Dialogue, ajout si un coach principal change | « Les séances de {équipe} sont désormais tenues par {coach}. » |
| Aucune incohérence | « Aucune incohérence avec le planning en vigueur. » |
| Incohérences, en-tête | « 1 incohérence avec le planning en vigueur : » / « {n} incohérences avec le planning en vigueur : » |
| Incohérence, ligne | « {équipe}, {jour} {heure} : {règle} » |
| Incohérences, suite | « Vous pouvez valider quand même, ou régénérer pour que le système replace ces séances. » |
| Séances manquantes | Le texte existant `validateMissingSessions` |
| Contrôle non concluant | « Le contrôle n'a pas pu conclure. Vous pouvez valider, ou régénérer pour vérifier. » |
| Bouton de retour | « Revenir au planning en vigueur » |
| Dialogue de retour | « Revenir au planning en vigueur ? » puis « Les réglages du planning reviennent à ceux de la version en vigueur. Vos modifications en attente sont perdues. Les versions de travail restent dans la liste. » |
| « Charger », corps (`:49`) | « Les réglages du planning reviennent à ceux de cette version, et son planning s'affiche. Aucune équipe, aucun gymnase, aucun coach n'est créé ni supprimé ; leurs noms et leurs contacts ne changent pas. » |
| « Charger », ajout si réglages non générés | « Vos modifications non générées seront perdues. » |
| Refus de chargement | « Cette version donne à {équipe} un autre niveau, et l'équipe est engagée en compétition : elle ne peut pas être chargée. » |
| Bulle (`:113`) | « Remet les réglages du planning comme à la génération de cette version, sans régénérer » |
| Bulle `loadLocksHint` (`:114`) | Supprimée |
| Validation, rien ne change | « Rien ne change par rapport au planning en vigueur. » |
| Validation, en-tête | « Ce qui change par rapport au planning en vigueur : » |
| Lignes | « 1 équipe dont la participation ou les séances changent » / « {n} équipes dont la participation ou les séances changent » ; « 1 créneau de gymnase ajouté, retiré ou modifié » / « {n} créneaux de gymnase ajoutés, retirés ou modifiés » ; « 1 règle ajoutée, retirée ou modifiée » / « {n} règles ajoutées, retirées ou modifiées » ; même forme pour « réservation », « mutualisation », « passerelle » ; « 1 équipe change de coach principal » / « {n} équipes changent de coach principal » ; « 1 coach-joueur ajouté ou retiré » / « {n} coach-joueurs ajoutés ou retirés » ; « 1 équipe change de catégorie, de niveau, de rang ou de gymnase imposé » / « {n} équipes changent de catégorie, de niveau, de rang ou de gymnase imposé » ; « La règle de trajet change » ; « 1 séance déplacée » / « {n} séances déplacées » |
| Validation, seules des séances ont bougé | « Aucun planning de période n'est supprimé. » |
| Validation, suppression | « 1 planning de période pas encore commencé sera supprimé et devra être refait : {titre}. » / « {n} plannings de période pas encore commencés seront supprimés et devront être refaits : {titres}. » |
| Validation, doléances | « La doléance déjà reçue pour les vacances à venir est conservée. » / « Les {n} doléances déjà reçues pour les vacances à venir sont conservées. » |
| Confirmation avec suppression | « Valider et supprimer » |
| Doléance d'avant | « Réponse donnée avant la modification du planning de saison » |
| Bandeau du Wizard (`WizardLayout.tsx:473`) | « Le planning de saison validé reste en vigueur. Ce que vous modifiez ici ne vaut pour les matchs et les plannings de période qu'après validation. » |
| Pastille du cockpit | « Modifications en attente de validation » |
| Refus serveur (`VersionEditability.php:27`), plan de saison | « Ce planning est en vigueur (lecture seule) : retravaillez-le pour le modifier. » |
| Refus de « Rouvrir » sur un plan de saison | « Le planning de saison ne se rouvre plus : retravaillez-le. » |
| `wizard/texts.ts:207`, `:214`, `texts/teams.ts:117` | Supprimés |
| `cockpit/texts.ts:131` | « Des plannings de période pas encore commencés seraient supprimés si vous validez un planning de saison modifié. » |

## 10. Décisions du fondateur (2026-11-24)

1. **Déplacer une séance du socle ou la verrouiller ne supprime jamais les plans de période à venir : cela n'affecte pas le socle froid.** Seul le froid (règles, réservations et le reste du tableau du §3.1) les supprime. Une séance déplacée figure dans la liste « ce qui change » du dialogue de validation, à titre d'information seulement. Un verrou posé sur une séance qui ne bouge pas n'y figure pas.
2. **a.** « Retravailler » est un bouton qui copie la version en vigueur ; la copie de travail n'a d'effet que si on la valide. **b.** Changer le coach principal, planning validé, ne demande pas de régénérer : un geste « Valider ces modifications » applique le changement au nom du coach principal sur la version en vigueur, sans déplacer aucune séance, et le système dit les incohérences créées (séance impossible, contrainte violée) sans bloquer.
3. Charger avec des réglages non générés : le dialogue prévient qu'ils seront perdus, puis charge.
4. Les plans de période gardent « Rouvrir » dans ce lot ; la règle du socle leur viendra avec le Wizard du lot 6 (E4).
5. Ordre : PR 1 à 3 de ce lot, puis le lot 7, puis PR 4 à 7. Le plan du lot 7 change : sa décision Q10 est sans objet, ses PR 3b et 4a ne touchent plus `StructureRestorer` ni `RestoreFeasibility`, son E3 pointe le lot 6.
6. Véhiculé et nombre de jours maximum d'un coach sont libres, comme la case salarié.
7. Un coach qui joue aussi dans une équipe est froid, comme le coach principal.
8. Charger remet rang, catégorie, niveau, « divisible » et gymnase imposé, sans créer ni supprimer d'équipe et sans toucher aux noms ; un gymnase supprimé entre-temps ne revient pas ; une version qui changerait le niveau d'une équipe engagée ne se charge pas (E5).

**Ce que le gestionnaire voit changer, à connaître**
- Valider un changement de coach principal supprime les plans de vacances pas commencés (cible §5.2) ; le dialogue l'annonce avant.
- Régénérer sans rien changer, déplacer une séance ou la verrouiller ne supprime rien (décision 1, cible §14).
- Renommer un groupe mutualisé, une réservation ou une règle est libre (cible §4).
- Charger ne ramène plus une équipe supprimée depuis.
- L'import du parc devient possible avec un planning validé.
- La saison suivante reprend les réservations du planning de saison.
- Changer l'âge d'une catégorie ou un jour de match ne met rien en attente.
- Aucun écran ne montre l'écart d'un plan de vacances à son planning de saison ; hors lot.

## 11. Limites écrites

1. E1 à E6.
2. **La PR 5 est la plus grosse et dépassera probablement le plafond Behat** ; la PR 6 grossit du geste « Valider ces modifications ».
3. **Le contrôle d'incohérences** ne juge que les séances touchées (table du §3.6), par demandes de 50, six au plus, chacune avec un budget moteur de 2 s (`MoveSlotService.php:71`). Au-delà de 300 séances touchées, ou sur un refus, une erreur ou un délai du moteur, il répond « non concluant » et laisse valider. Chaque demande ne juge que ses candidates, le reste figé : une incohérence entre deux séances figées dans la même demande est vue quand l'une des deux est candidate.
4. **Le coach réécrit sur une séance** passe par la maison existante (`CoachDoubleBookingDetector::storedCoachFor`, appelée en `MoveSlotService.php:1003`), non lue. À relire à l'ouverture de la PR 6 ; si elle ne peut pas servir hors d'un déplacement, la PR se limite à remplacer l'ancien coach principal par le nouveau sur les séances qui le nomment, et le dit.
5. **L'entrée figée de la version en vigueur, après le geste, ne décrit plus la demande de sa génération** mais l'état qu'elle a adopté ; un outil de relecture d'une génération passée lit donc un état recalculé pour cette version.
6. Le contrôle ne voit ni les préférences souples ni la qualité du planning.
7. Un plan de période pas commencé gardé après un déplacement de séance reste préparé sur l'ancienne place de cette séance ; rien ne l'affiche pour des vacances. Conséquence de la décision 1, hors lot.
8. La conversion passe par une commande jouée une fois par la procédure manuelle ; `--check` bloque la réouverture. Une copie manquante après coup fait lever la porte pour ce plan.
9. Une version d'avant le lot, ni validée ni chargée, n'a pas de copie et ne se charge pas.
10. Entre la PR 3 et la PR 5, une règle ramenée par un chargement et sans ligne de période en reçoit une au défaut.
11. Les 49 fichiers de test qui pointent une version à la main doivent lui donner une copie : adaptation mécanique, PR 5.
12. L'API garde `schedulePlanId: null` pour le socle (lot 6).
13. Les retouches de planning qui écrivent un réglage du plan sont à relever par balayage ; connues : `MutualizeSlotService`, `MoveSlotService`, `ReservationRemoval`.
14. Le traitement d'une équipe désactivée dans un plan de période : non vérifié.
15. Plusieurs fichiers n'ont été lus que sur les plages citées : `MoveSlotService`, `MutualizeSlotService`, `ScheduleResultImporter`, `PlanComposition`, `PlanBirth`, `PeriodConstraintSelector`, `CoachDoubleBookingDetector`, `GenerateScheduleHandler`, `PlanningPage.tsx`, `frontend/src/features/coach-wishes/`, l'ADR-0002. La PR qui les touche les relit en entier.

## 12. Checklist de cadrage

- **Zones** : `backend/`, `frontend/` (PR 3, 5, 6), `docs/`, `specs/`, `.github/workflows/ci.yml`, `CLAUDE.md` (PR 6 pour l'exception E6, PR 7 pour le reste). `engine/`, `landing/`, `system-pages/` : aucun fichier.
- **Interdits** : `engine/**`, `engine/CONTRACT_VERSION`, les trois `*ContractSchemaTest`, les références 1 à 19, `tests/Fixtures/lot-4/`, `scripts/remote-deploy.sh`, `.github/workflows/deploy.yml`, `docs/ops/deploy.md`.
- **Aucun refactoring hors périmètre** : confirmé. `MoveSlotService` ne reçoit qu'une entrée de lecture.
- **Axes §7.1 et NR** : *cycle des plans* (11 à 16, 21, 24, 25, 29 à 36, 39 à 42, 46 à 48) ; *chaîne de génération* (1, 2, 4, 6, 9, 22, 26, 31, 49) ; *sens des contraintes* (7, 8, 26, 30, 47) ; *isolation* (10, 17, 37, 49) ; *périmètre engagé* (13, 16) ; *contrat avec le moteur* : non modifié, des demandes de plus prouvées par la référence 22 ; *auth et adhésions* : non touché. Ajoutés au step `Blocking suite` et à `blocking-tests.md` : `EngineRequestKeySourcesTest` (PR 2), `LoadVersionTest` et `PlanStateTenantIsolationTest` (PR 3), `InForceDoorTest` (PR 4). `LoadedVersionIsTheOnlyEditableTest`, déjà bloquant, reçoit le cas d'exception (PR 6).
- **Tests d'abus** : pas de section « Signaux » ; chemins nommés ici : lignes 17, 37, 43, 49.
- **`/security-review`** : PR 2, 3, 5, 6.
- **Contre-revue** : `smith`, trois passes faites.
- **Tests existants qui citent une classe ou une route supprimée** (recherche du 2026-11-24, contenu non lu) : `Architecture/NoNullPlanBranchTest`, `CrossStack/PreviousAssignmentsPayloadParityTest`, `CrossStack/SocleReferencePayloadParityTest`, `EngineRequestRunnerTrait`, `Integration/Api/GridLockAuditTest`, `Integration/Command/EngineRequestsRecordCommandTest`, `Integration/Command/PurgeSeasonsCommandTest` (`:90`), `Integration/LoadedVersionLocksOnlyTest`, `Integration/Migration/{PersonConversion,PlaceConversion,TeamMatchDayIso}MigrationTest`, `Integration/ReservationPinsNotDoubledTest`, `Integration/Service/CoachWishCampaignTokenSyncTest`, `Integration/Service/LoadedVersionPointerTest`, `Integration/Structure/PersonEraserTest`, `Integration/VenueOccupancyLifecycleTest`, `MessageHandler/{GenerateScheduleFailure,OverlayGeneration}Test`, `PersonErasureDecorTrait`, `Security/{ClubQuota,LoadedVersionIsTheOnlyEditable,MemberWriteSweep,PersonErasureLeavesNoTrace,PlanEntitlements,RgpdExport,ScheduleCapabilityParity,SeasonPlanInForce}Test`, contextes Behat `ClosureStraddle`, `CoachWishes`, `PeriodAutoValidation`, `PeriodLockSmallClub`, `TeamCap`. Adaptés mécaniquement ou retirés avec leur filet, PR 3.
- **Behat touchés** : `le-socle-commande-les-plans`, `voeux-des-coachs`, `un-verrou-de-periode-survit-a-la-regeneration`, `au-dessus-du-palier-plus-de-sorties`, `la-boucle-de-travail-du-gestionnaire`, `plan-de-periode-en-overlay`, `preparer-la-saison-suivante-reprend-la-structure`, `effacer-une-personne-efface-tout`, `les-conflits-d-un-match-disent-la-verite`, `l-import-du-parc-en-un-geste`, `supprimer-emporte-ce-qui-en-depend` (commentaires `:24`, `:33`), et deux features neuves : `charger-une-version-ramene-son-etat`, `valider-des-modifications-sans-regenerer`.
- **Playwright touchés** : `journey.spec.ts`, `closure-extend.spec.ts`, `matches.spec.ts`, `listbox.spec.ts`, `member-read-only.spec.ts` (deux gestes d'écriture neufs à y ajouter), `season-transition.spec.ts`, `support.ts` ; `visual-reference.spec.ts` si l'apparence de référence change.
- **Feature qui prouve la zone** : `le-socle-commande-les-plans.feature`, `generation-du-planning-de-saison.feature`.
- **Plafonds**, estimations non mesurées : backend +65 s, Behat +50 s net (dont la feature de la PR 6, qui appelle le moteur), front +4 s, e2e +10 s. Behat part déjà au-dessus du plafond. Allègements prévus : la ligne 7 garde quatre exemples, la ligne 40 cinq, la ligne 47 trois en Behat (le reste est en test d'intégration), la ligne 25 rejoint le scénario de la ligne 24 ; au-delà, ligne datée du fondateur. Si backend déborde : la ligne 8 se limite aux décors 11 et 5, la ligne 26 à un champ par famille.
- **Documentation** : ADR-0007 et statut de l'ADR-0002, `adr-index.md`, `CLAUDE.md` §6 et §7.1, `specs/courantes/planning-lifecycle-validated.md`, `types-de-planning.md`, `etat-des-lieux.md`, fiche et §6 du découpage (étapes de conversion comprises), `lot-conception-filet.md`, `backend/AGENTS.md`, `backend/docs/TENANT.md`, `backend/docs/commands.md`, `docs/security/rls.md`, `docs/glossary.md`, `frontend/docs/frontend-wizard.md`, `test-coverage-map.md`, `blocking-tests.md`, `openapi-snapshot.json`.
- **Retour au fondateur si** : une mesure hors attendu (2, 3, 6, 7) ; une référence 1 à 19 ou la 21 qui devrait changer ; la 20 qui change d'autre chose que des réservations ; une clé de la demande sans source évidente ; `diff -r` non vide ; le plafond Behat ; la limite 4 qui se révèle fausse.

## 13. Exécution

**Prête pour l'exécution après les mesures de `link` (§6).** Aucune décision n'attend le fondateur.

**Les PR dans l'ordre d'exécution**

| Ordre | PR | Ce qu'elle livre | Ce qui la rend rouge |
|---|---|---|---|
| 1 | PR 1 · Le filet | Références 20 et 21, un scénario de chargement, mesure des plafonds | Une référence 1 à 15 qui bouge ; les cas 20 et 21 non figés |
| 2 | PR 2 · Le froid se déclare | Déclaration froid, libre, saison suivante ; copie d'état à chaque version ; empreinte sans le libre ; commande de conversion | Une famille non déclarée ; une clé de la demande sans source ; une version terminée sans copie ; une génération qui réussit alors que la copie échoue ; `--check` à 0 avec une copie manquante |
| 3 | PR 3 · Charger, une définition | Une route de chargement pour tout plan ; photo, restauration et seconde définition supprimées ; plan du lot 7 repointé | Une équipe, un gymnase, un coach ou une catégorie créé ou supprimé par un chargement ; une ligne d'un autre plan écrite ; un groupe dissous qui revient ; le niveau d'une équipe engagée changé ; la trace d'audit absente ; une ancienne route encore servie |
| — | Lot 7 (PR 1 et 2 livrées, sept autres) | Les matchs | Hors de ce plan |
| 4 | PR 4 · La porte, lecture inchangée | Tous les lecteurs de l'en vigueur passent par `InForce` | Une demande qui change d'un octet ; un lecteur nommé qui lit encore le pointeur ou une famille froide en direct |
| 5 | PR 5 · Retravailler, la porte lit la copie | « Retravailler » à la place de « Rouvrir » pour le socle ; Wizard déverrouillé ; état « en attente » ; lignes de période propagées à la validation | Un champ froid modifié sans valider qui change une demande de période ou de placement ; un plan de période ou une doléance supprimés par « Retravailler » ; la version en vigueur modifiable ; une copie de travail née périmée sans rien en attente ; « Rouvrir » encore accepté sur un plan de saison ; le plafond Behat |
| 6 | PR 6 · Valider dit ce qui change | Liste de ce qui change ; suppression par le froid seul ; doléances conservées ; « Valider ces modifications » sans régénérer, avec contrôle d'incohérences | Un plan de période supprimé alors qu'aucun froid n'a changé ; un verrou seul annoncé comme changement ; une séance déplacée absente de la liste ; une doléance purgée ; une incohérence tue, ou qui bloque ; une séance qui change de place, de jour, d'heure, de gymnase ou de verrou par le geste ; le geste qui écrit autre chose que le coach des séances, l'entrée figée et l'empreinte ; une copie de travail périmée juste après le geste ; une demande de contrôle de plus de 50 séances ; un refus du moteur qui remonte en erreur ; la référence 22 non figée ; `ValidateAssignmentsContractSchemaTest` rouge |
| 7 | PR 7 · Saison suivante et documents | Reprise du socle en vigueur par la déclaration, réservations comprises ; ADR-0007 ; `CLAUDE.md` | La référence 20 qui change d'autre chose que des réservations ; une famille reprise sans routine ; une garde de documentation |

**Laissé hors périmètre, volontairement** : la règle « retravailler » pour les périodes, la case « participe » du socle, l'écran de structure, la suppression forte, les clés étrangères, le déplacement des contraintes vers le plan, l'affichage de l'écart d'un plan de vacances à son planning de saison, le contrôle d'incohérences avant de valider une version périmée.

## Fichiers à relire en premier

- `specs/evolution/lot-conception-decoupage.md`
- `specs/evolution/conception-cible.md`
- `specs/evolution/lot-conception-4-le-plan-porte-ses-donnees.md`
- `specs/evolution/lot-conception-7-les-matchs.md`
- `backend/src/Structure/Family.php`
- `backend/src/Structure/FamilyCatalog.php`
- `backend/src/Service/StructureSnapshotter.php`
- `backend/src/Service/StructureRestorer.php`
- `backend/src/Service/RestoreFeasibility.php`
- `backend/src/Controller/RegenerateFromVersionController.php`
- `backend/src/Controller/LoadLocksController.php`
- `backend/src/Controller/ValidateScheduleController.php`
- `backend/src/Controller/ReopenScheduleController.php`
- `backend/src/Controller/SocleDeviationController.php`
- `backend/src/Service/FutureHolidayCollectePurger.php`
- `backend/src/Service/SocleGuard.php`
- `backend/src/Service/VersionEditability.php`
- `backend/src/Service/SchedulePlanProvisioner.php`
- `backend/src/Service/ScheduleCapabilityResolver.php`
- `backend/src/Service/ScheduleConstraintBuilder.php`
- `backend/src/Service/MoveSlotService.php`
- `backend/src/Service/CoachDoubleBookingDetector.php`
- `backend/src/Service/EngineClient.php`
- `backend/src/Service/ScheduleResultImporter.php`
- `backend/src/Service/PlacedSessionPersonConflictDetector.php`
- `backend/src/Service/Conflicts/DayHoldings.php`
- `backend/src/Service/PeriodPlanTranscriber.php`
- `backend/src/Service/TrainingCalendarContext.php`
- `backend/src/Service/EffectiveScheduleResolver.php`
- `backend/src/Service/ConflictRadarLoader.php`
- `backend/src/Service/MatchPlacementPayloadBuilder.php`
- `backend/src/Service/MutualizeSlotService.php`
- `backend/src/Service/ReservationRemoval.php`
- `backend/src/Service/TeamImportGate.php`
- `backend/src/Service/SeasonTransitionService.php`
- `backend/src/MessageHandler/GenerateScheduleHandler.php`
- `backend/src/Command/PruneUnreadSnapshotFieldsCommand.php`
- `backend/src/Plans/PlanComposition.php`
- `backend/src/Plans/PlanBirth.php`
- `backend/src/Structure/PersonEraser.php`
- `backend/src/Entity/Team.php`
- `backend/src/Entity/Schedule.php`
- `backend/src/Entity/ScheduleSlotTemplate.php`
- `engine/app/main.py`
- `engine/app/schemas/validate_input_schema.py`
- `backend/tests/ChoosesPlanVersionTrait.php`
- `backend/tests/Security/RgpdExportTest.php`
- `backend/tests/Security/LoadedVersionIsTheOnlyEditableTest.php`
- `backend/tests/Integration/Migration/Lot4MigrationChainTest.php`
- `backend/features/le-socle-commande-les-plans.feature`
- `frontend/src/features/planning/texts.ts`
- `frontend/src/features/planning/api.ts`
- `frontend/src/features/planning/ValidateDialog.tsx`
- `frontend/src/features/wizard/WizardLayout.tsx`
- `frontend/src/features/cockpit/SeasonPlanBanner.tsx`
- `scripts/remote-deploy.sh`
- `test-time-ceiling.json`
