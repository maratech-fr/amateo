# Mutualisation — un ensemble d'équipes qui se comporte comme UNE équipe

Last verified @ 2026-10-14 (§2.3 recalé contre `MoveSlotService::moveGroup` : éviction optionnelle
d'un occupant ÉQUIPE non verrouillé, `evictSlotId`+`dryRun`, P4-329 — §5 mis à jour en conséquence).
Reste du fichier re-vérifié le 2026-10-13 (`SharedTrainingBlock` : entité, service de mutualisation
depuis le planning, processeur API, provisioning, prune de suppression d'équipe, panneau du
wizard, planning (`SlotDetail`/`MutualizeDialog`/`PlanningPage`) et sémantique moteur).

> **But** : permettre à des équipes de s'entraîner ENSEMBLE sur une même case de gymnase, sans que
> le solveur les compte comme deux occupations séparées (ce qui créerait un faux conflit de
> capacité) et sans dupliquer la notion de disponibilité coach. **Source de vérité : le code.**

## 1. Le modèle — `SharedTrainingBlock` (« groupe » à l'écran)

Un bloc est un ensemble de **2 à 10 équipes** qui se déclare comme UNE équipe à part entière, avec
son propre nombre de séances communes par semaine (`commonSessions`, ≥ 1) et un libellé optionnel
(`App\Entity\SharedTrainingBlock`, `backend/src/Entity/SharedTrainingBlock.php:62-72`). Vocabulaire
écran : l'application dit toujours **« groupe »**, jamais « bloc » — « bloc » n'est qu'un nom de
fichier/classe interne (`frontend/src/features/wizard/steps/SharedTrainingBlockPanel.tsx:21-29`).

- **Portée** (`schedulePlanId`, `backend/src/Entity/SharedTrainingBlock.php:62-64`) : `NULL` = le
  planning de SAISON (socle) ; un UUID = un plan de période. Au socle, c'est la PORTÉE ENTIÈRE du
  bloc qui vit à `NULL` — la grille de gymnase qu'il scope, le budget Σ, l'unicité de son ensemble
  d'équipes — exactement comme les blocs de saison existants.
- **Copie socle → période** : un plan de **FERMETURE** (`CLOSURE`) hérite, À SA NAISSANCE, de tous
  les blocs du socle (`SchedulePlanProvisioner::copySocleSharedBlocks`,
  `backend/src/Service/SchedulePlanProvisioner.php:1031-1035`). Un plan de **VACANCES** (`HOLIDAY`)
  ne copie **jamais** rien — le gestionnaire mutualise une vacance à la main si besoin.
- **Multi-appartenance** : une équipe peut être membre de **plusieurs** blocs à la fois (pas
  d'unicité un-bloc-par-équipe) — la garde qui borne le cumul n'est PAS un refus « déjà dans un
  groupe », c'est une somme (`backend/src/State/Processor/SharedTrainingBlockStateProcessor.php:28-30`) :
  pour chaque équipe, la **Σ** des `commonSessions` de tous ses blocs de MÊME portée (le bloc écrit
  compris) ne doit jamais dépasser son nombre de séances hebdomadaires EFFECTIVES
  (`EffectiveTeamSessions`, override de période inclus) — gardé par
  `SharedTrainingBlockStateProcessor::assertBlockValid`,
  `backend/src/State/Processor/SharedTrainingBlockStateProcessor.php:192-220`.
- **Mort du bloc** : supprimer une équipe membre détruit le bloc **ENTIER** (toutes ses lignes
  `SharedTrainingBlockTeam`, pas seulement celle de l'équipe supprimée) —
  `App\Deletion\SharedTrainingBlockPruneStep`, `backend/src/Deletion/SharedTrainingBlockPruneStep.php:11-23,61-78`.
- **Un bloc = une séance à l'écran.** Le gestionnaire ne voit jamais le découpage interne (N lignes
  `ScheduleSlotTemplate`, une par membre, liées par `sharedTrainingBlockId`) — c'est un détail de
  stockage.

## 2. Les gestes

### 2.1 Déclarer un groupe depuis la saisie (modale « Liens » du wizard)

`SharedTrainingBlockPanel` (`frontend/src/features/wizard/steps/SharedTrainingBlockPanel.tsx`),
section « Mutualisation » de la modale **Liens** : créer/modifier/supprimer un groupe en choisissant
ses équipes et son nombre de séances communes (liste déroulante bornée, jamais une saisie libre).
Rail API Platform standard sur la ressource `SharedTrainingBlockResource`
(`SharedTrainingBlockStateProcessor`), même portée socle/période que ci-dessus. Le serveur reste
seul juge ; tout ce que l'écran calcule (borne de la liste, repère « déjà dans N groupes ») est
FAIL-SAFE — un refus 422 est affiché tel quel.

### 2.2 « Mutualiser… » depuis une séance du planning (fiche d'une case)

Depuis la fiche d'une séance déjà placée (la case d'une équipe SOURCE), le gestionnaire déclare un
groupe **ANCRÉ à cette case** : `MutualizeSlotService::mutualize()`
(`backend/src/Service/MutualizeSlotService.php`), route
`POST /api/schedule-slots/{id}/mutualize` (`backend/src/Controller/ManualEditController.php:599`).

**Disponible au planning de SAISON (socle) comme sur un plan de PÉRIODE** — le socle se comporte
comme la saison (décision fondateur 2026-10-13, §4). Front : `PlanningPage` fournit le geste dès
qu'un planning est affiché (`mutualizeAvailable = null !== selectedSchedule`,
`frontend/src/features/planning/PlanningPage.tsx:598-603`) ; `SlotDetail` l'affiche systématiquement
quand la page le fournit — désactivé AVEC MOTIF (découvrable au clavier) si le planning est validé
(lecture seule) ou si la séance fait déjà partie d'un groupe
(`frontend/src/features/planning/SlotDetail.tsx:362-374`).

**Ce que le geste écrit, en UNE transaction (tout-ou-rien)** — docblock de référence
`backend/src/Service/MutualizeSlotService.php:21-56` :
- un `SharedTrainingBlock` de portée adaptée à l'écran (`NULL` au socle, le plan sinon),
  `commonSessions = 1`, nom optionnel ;
- la séance de la SOURCE verrouillée **HARD/MANUAL** sur place ;
- pour chaque équipe rejoignante **DÉJÀ placée** : la séance qu'elle désigne (`replacedSlotIds` —
  choix conscient du gestionnaire s'il y en a plusieurs) est DÉPLACÉE sur la case et verrouillée
  HARD/MANUAL ;
- pour chaque équipe rejoignante **à 0 séance** dans le plan : un override de période l'ACTIVE
  (`TeamPeriodOverride.isActive=true`, `sessionsPerWeek=1`, `source='mutualisation'`) et une séance
  NEUVE naît sur la case, verrouillée ;
- toutes les séances liées au bloc (`ScheduleSlotTemplate.sharedTrainingBlockId`, FK CASCADE).

**Seule restriction propre au socle** : l'ACTIVATION d'une équipe à 0 séance reste propre aux plans
de PÉRIODE. Au socle, il n'y a pas d'override de période pour donner une séance à une équipe qui
n'en a aucune : le geste est **refusé 422**, rien n'est écrit — message exact
(`backend/src/Service/MutualizeSlotService.php:348-356`) :

> « Au planning de saison, on ne peut pas mutualiser une équipe qui n'a aucune séance : donnez-lui
> d'abord des séances dans la saisie. »

Côté écran, l'UI anticipe ce refus : au socle, `MutualizeDialog` ne propose PAS les équipes à 0
séance dans le sélecteur d'ajout (`isSocle`,
`frontend/src/features/planning/MutualizeDialog.tsx:80-83`) — le refus serveur ci-dessus reste le
filet.

**Autres refus 422 NOMMÉS** (transaction annulée, rien écrit), docblock
`backend/src/Service/MutualizeSlotService.php:49-54` : équipe inactive, équipe inconnue, ensemble
déjà déclaré pour cette portée, case déjà occupée au-delà de sa capacité (le groupe compte pour UNE
occupation), Σ des séances communes dépassant le volume hebdomadaire effectif d'une équipe, équipe
déjà placée sans séance désignée, équipe rejoignante siégeant déjà à la case d'ancrage par une
AUTRE séance (une équipe ne siège jamais deux fois à la même case — `TeamCaseOccupancyGuard`).

### 2.3 Déplacer / retirer / défaire un groupe déjà ancré

- **Déplacer TOUT le groupe** : `POST /api/schedule-slots/move-group`
  (`backend/src/Controller/ManualEditController.php:390`, `MoveSlotService::moveGroup`) — déplace
  les N créneaux des membres d'un coup, sous un seul verdict moteur, en une transaction. Le corps
  ne porte QUE le bloc et les deux cases (source/cible), jamais de `slotIds` client ; le serveur
  résout lui-même les membres. **Éviction optionnelle (P4-329)** : déposer le groupe sur une case
  occupée par une séance d'ÉQUIPE non verrouillée l'évince dans la MÊME transaction (`evictSlotId`,
  patron de `move()`) — l'occupant disparaît et passe « à replacer », nommé dans la réponse
  (`evicted`) ; un `dryRun` rend le même verdict (+ l'état qui SERAIT évincé) sans rien écrire.
  Refus 422 NOMMÉS avant le moteur : cible d'éviction introuvable/d'un autre planning/source du
  groupe ou hors case cible (`evict_target_mismatch`), occupant verrouillé (`target_locked`,
  D3 souverain), occupant lui-même une séance de GROUPE (`evict_target_is_group` — l'échange de
  deux groupes reste à livrer, §5) ; un membre déplacé siégeant DÉJÀ à la case cible par une AUTRE
  séance que l'éviction ne libère pas reste un refus `team_already_at_case`, aucun des N créneaux
  ne bouge.
- **Retirer la séance de la case courante** : `POST /api/schedule-slots/remove-group` — retire la
  séance de groupe SANS toucher au bloc ni à ses adhésions (distinct de la suppression du bloc).
- **Défaire le groupe** : `DELETE /api/shared_training_blocks/{id}` (CRUD API Platform standard)
  détruit le bloc ET ses séances de groupe.

Détail des réponses/codes d'erreur de ces trois rails : [`backend-controllers.md`](../../backend/docs/backend-controllers.md)
(table des contrôleurs, lignes `/move-group`, `/remove-group`, `/mutualize`).

## 3. Le moteur — « un coach suffit »

Côté solveur, une séance de groupe (`sharedBlocks[]` dans le contrat backend⇄engine) tient avec
l'UNION des coachs de ses membres disponibles sur la case : un membre sans coach propre disponible
n'y est présent QUE via le groupe, et un groupe dont AUCUN coach de l'union n'est disponible sur une
case en est fermé. Cette règle moteur (`engine/app/solver/constraints/availability.py`,
`engine/app/solver/constraints/structural/closures.py`) et le reste de l'algèbre d'un groupe
(dé-comptage de capacité, diagnostics post-solve, parité `/validate-assignments`) vivent en détail,
sans duplication, dans [`engine/docs/constraint-vocabulary.md`](../../engine/docs/constraint-vocabulary.md)
§« Bloc de mutualisation (`sharedBlocks`) ».

## 4. Décisions (fondateur, 2026-10-13)

- **Un groupe EST une équipe** : une séance de groupe compte pour UNE séance à l'écran, et **tout
  geste offert à une séance d'équipe doit l'être pour une séance de groupe**, au planning de SAISON
  comme de PÉRIODE. **Seule exception** : activer une équipe à 0 séance via « Mutualiser… », qui
  reste propre à la PÉRIODE — au socle c'est le refus nommé cité au §2.2.
- **Why** : « Amateo propose les outils que le gestionnaire utilise pour arriver à ses fins » —
  vitesse, pas d'allers-retours entre socle et période pour un même geste.

## 5. À venir (décidé, pas encore livré)

Ces décisions sont tranchées par le fondateur (2026-10-13) mais **le code ne les fait pas encore** —
elles ne doivent pas être lues comme un comportement actuel :

- **Menu à 3 choix au dépôt sur une case occupée** : déposer une séance (équipe ou groupe) sur une
  case déjà occupée proposera **Prendre sa place / Échanger / Mutualiser**, au lieu du seul choix
  actuel. Livré : `move()` ET `moveGroup()` savent évincer un occupant ÉQUIPE non verrouillé
  (§2.3, P4-329). Restent à livrer : il n'existe toujours pas d'endpoint d'échange atomique, et un
  occupant de GROUPE reste un refus nommé sur les deux rails (point suivant).
- **Équipe sur groupe ⇒ rejoindre ; groupe sur groupe ⇒ fusionner.** Dans les deux cas, Amateo
  AVERTIT des risques et CONTRÔLE la faisabilité (≤ 10 équipes, Σ des séances communes, capacité de
  la case) — jamais un refus de principe. Le gestionnaire assume le geste.
- **Un groupe occupant sera évinçable/échangeable comme une équipe** : son ancrage (verrou posé par
  le geste de mutualisation lui-même) n'est pas un verrou gestionnaire souverain. Évincé, le groupe
  passera « à replacer ». Seul un verrou POSÉ explicitement par le gestionnaire restera souverain.
- **Règle de fusion « on répartit, on ne crée rien »** : fusionner deux groupes (ou une équipe et un
  groupe) sur UNE case ne créera jamais de séance supplémentaire — chaque équipe garde son nombre
  total de séances initial, seuls les groupes se répartissent leurs séances communes. Exemple
  cadré : un groupe A {U11M1, U11M2} à 2 séances communes + un groupe B {SF1, SF2} à 1 séance ⇒ un
  groupe C {U11M1, U11M2, SF1, SF2} à 1 séance commune ; A garde 1 séance commune (2 − 1) ; B, tombé
  à 0, disparaît. Si l'ensemble d'équipes obtenu est identique à un groupe déjà existant, c'est CE
  groupe qui gagne +1 séance commune (jamais un doublon).

L'état d'avancement de ces chantiers (ce qui reste ouvert, ce qui est en cours) se lit dans
[`specs/evolution/roadmap.md`](../evolution/roadmap.md), jamais ici — ce fichier décrit le présent.
