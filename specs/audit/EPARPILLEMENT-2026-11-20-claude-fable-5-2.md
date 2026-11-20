# Relevé des informations éparpillées (2026-11-20)

Complément de l'audit de conception
([`CONCEPTION-2026-11-19-claude-fable-5-2.md`](CONCEPTION-2026-11-19-claude-fable-5-2.md)) et de la
cible ([`../evolution/conception-cible.md`](../evolution/conception-cible.md)).

**Règle du fondateur (2026-11-20)** : une même information vit à un seul endroit, sauf si la
conception impose autre chose, et alors la raison est écrite.

## 1. Méthode et limites

- Cinq lectures en parallèle, en lecture seule : données de plan ; plages horaires et règles ;
  envois, jetons et journaux ; personnes, liens entre équipes et matchs ; services, écran et miroirs
  entre zones.
- Colonne « Vérifié » : **Oui** = relu à la source par l'auteur du relevé ; **Agent** = constat d'une
  lecture, cité `fichier:ligne`, non relu.
- Non couvert : les migrations SQL, les tests, les seeders, le détail du moteur.
- Rien n'est corrigé. Ce relevé ne propose ni ordre ni découpage.

Chemins abrégés : `E/` = `backend/src/Entity/`, `S/` = `backend/src/Service/`,
`B/` = `backend/src/`, `F/` = `frontend/src/features/`.

## 2. En une page

Trois sortes de constats :

| Sorte | Ce que c'est | Combien |
|---|---|---|
| **A. Réglé par la conception décidée** | Disparaît dès que le plan porte ses données, que la personne a une identité, que la compétition est séparée de l'engagement | Le plus gros du relevé |
| **B. Nouveau, touche le modèle d'objets** | Une information rangée à deux endroits, que la conception décidée ne règle pas | 6 sujets |
| **C. Nouveau, rangement de code** | Une même mécanique réécrite plusieurs fois, sans changer les tables | 7 sujets |

S'y ajoutent **trois objets sans usage** (§6) et la liste des **séparations justifiées**, à ne pas
toucher (§7).

## 3. A. Ce que la conception décidée règle déjà

### A1. « Plan nul = socle » : la même donnée rangée de deux façons

Le socle lit ses données sur la saison ; un plan de période lit des copies ou des lignes
d'exception. Décision déjà prise : le socle devient un plan comme les autres.

| Information | Pour le socle | Pour un plan de période | Vérifié |
|---|---|---|---|
| Grille des créneaux | `VenueTrainingSlot`, plan nul (`E/VenueTrainingSlot.php:73`) | Copie complète à la naissance (`S/SchedulePlanProvisioner.php:1264-1286`) | Oui |
| Occupations de gymnase | `VenueOccupancy`, plan nul (`E/VenueOccupancy.php:68`) | Copie (`S/SchedulePlanProvisioner.php:1296-1308`) | Oui |
| Réservations | `Reservation`, plan nul (`E/Reservation.php:65`) | Plan renseigné, sans copie du socle | Oui |
| Groupes mutualisés | `SharedTrainingBlock`, plan nul (`E/SharedTrainingBlock.php:67`) | Copie pour les fermetures seulement (`S/SchedulePlanProvisioner.php:1238-1242`) | Oui |
| Réglages des règles du club | `ImplicitRuleSetting`, plan nul (`E/ImplicitRuleSetting.php:65`) | Copie totale, repli sur la saison pour un plan ancien | Oui |
| Séances par semaine, plafond par jour | Colonnes de l'équipe (`E/Team.php:67-76`) | Ligne d'exception, valeur nulle = valeur de saison (`E/TeamPeriodOverride.php:55-74`) | Oui |
| Contrainte appliquée ou non | `Constraint.isActive` | `ConstraintPeriodOverride`, et à défaut de ligne une valeur calculée dans le code (`S/PeriodConstraintSelector.php:114-128`) | Agent |
| Gymnase ouvert ou non | La grille | `VenuePeriodOverride` (mode + masque de jours) en plus de la grille copiée | Agent |
| Version chargée | Sur la saison (`E/Season.php:50-58`) | Sur le plan (`E/SchedulePlan.php:88-98`) | Oui |

Ce que ce patron impose aujourd'hui :

- deux constructeurs du payload du solveur (`S/ScheduleConstraintBuilder.php:251` et `:397`), choisis
  par cinq appelants ;
- cinq routines de copie de naissance (quatre socle vers période, une période vers période) ;
- la branche « plan nul, sinon égal au plan » répétée dans une douzaine de fichiers ;
- un drapeau qui n'existe que pour lui (`SchedulePlan.teamSelectionInitialized`,
  `E/SchedulePlan.php:134-146`) ;
- une photo de structure et sa restauration pour le socle seul (`S/StructureSnapshotter.php:52-63`,
  `S/StructureRestorer.php:93-130`).

Ce qui disparaît dans la cible : `TeamPeriodOverride`, `ConstraintPeriodOverride`, le mode de
`VenuePeriodOverride`, le drapeau, la seconde branche du constructeur, la branche « plan nul ».

### A2. Les listes de familles tenues à la main

| Liste | Exemplaires | Où | Gardée par un test |
|---|---|---|---|
| Ce qu'il faut effacer | 7 listes, 6 fichiers | `S/SeasonDataPurger.php:112`, `:133` ; `S/ErasedClubPurger.php:67` ; `B/Deletion/CascadePlan.php:47`, `:97`, `:138`, `:147` ; `S/StructureRestorer.php:154-214`, `:259-275` ; `S/OverlayManager.php:110`, `:215-234` ; `S/FutureHolidayCollectePurger.php:77` | Trois sur sept |
| Ce qu'il faut copier | 5 mécanismes | `S/SeasonTransitionService.php:180-556` (16 familles, champ par champ) ; `S/StructureSnapshotter.php:52` ; `S/PeriodPlanSiblingCopier.php:145`, `:171` ; `S/SchedulePlanProvisioner.php:1270`, `:1296`, `:1329` ; `S/PeriodPlanTranscriber.php` | Partiel |
| Ce qui appartient à un plan | 5 endroits | `S/OverlayManager.php:110` ; `S/PeriodPlanSiblingCopier.php:171-240` ; `S/StructureSnapshotter.php:52-63` ; `S/StructureRestorer.php:48-59` ; `S/StructureAnchor.php:49-52` | Non trouvé |

Conséquence mesurée : une colonne ajoutée à un créneau de gymnase se reporte à trois endroits
(`S/SeasonTransitionService.php:201-216`, `S/PeriodPlanSiblingCopier.php:185-190`,
`S/SchedulePlanProvisioner.php:1277`). Réglé par la déclaration unique des familles (principe 5).
Vérifié : Agent.

### A3. La personne n'a pas d'identité

| Constat | Preuve | Vérifié |
|---|---|---|
| Prénom, nom et mail existent sur le compte et sur le coach, sans lien entre les deux | `E/User.php:33-43`, `E/Coach.php:38-48` ; aucune colonne de compte sur `Coach` | Oui |
| Le coach est recopié chaque saison avec un nouvel identifiant ; la correspondance n'est pas gardée | `S/SeasonTransitionService.php:268-287` | Agent |
| Deux tables rattachent un coach à une équipe (il encadre, il joue), de même forme | `E/TeamCoach.php:41-52`, `E/CoachPlayerMembership.php:39-46` | Agent |
| Quatre autres endroits redisent « ce coach, cette équipe » | `E/CoachWishToken.php:59-67`, `E/ScheduleSlotTemplate.php:56-57`, `E/CoachWish.php:61-66`, `E/CoachWishMutualization.php:59-64` | Agent |

Réglé par la Personne avec rôles, au club.

### A4. La compétition et le match

| Constat | Preuve | Vérifié |
|---|---|---|
| Une compétition est une ligne par équipe engagée | `E/Competition.php:26`, `:44-51` | Oui |
| La nature d'une compétition est écrite à quatre endroits, par trois règles de déduction différentes plus la saisie | `S/FbiFixtureImporter.php:1733` ; `B/Controller/Basketball/FfbbEngagementsController.php:522`, `:535-546` ; `S/Basketball/FfbbRencontreReconciler.php:533` ; `B/State/Processor/CompetitionStateProcessor.php:37` | Oui |
| Le type stocké n'a presque aucun lecteur ; la nature est relue dans le libellé ailleurs | Lecteur : `FfbbEngagementsController.php:326` ; redéduction : `S/Basketball/FbiDivisionSignature.php:78-85`, `S/Basketball/FfbbRencontreReconciler.php:547-550` | Oui |
| L'amical a deux représentations : un match sans compétition, ou une compétition dont le libellé commence par « amical » | `S/FriendlyAutoValidator.php:25-26`, `S/Basketball/FbiDivisionSignature.php:79` | Oui |
| La catégorie d'âge est redéduite d'un libellé à cinq endroits | `S/TeamTagService.php:239`, `:444-455` ; `S/MatchDurationResolver.php:71` ; `S/Basketball/FbiDivisionSignature.php:102-115`, `:180-188` ; `S/Basketball/TeamCreationSuggester.php:63-68` | Agent |
| Le match porte trois sujets sur une ligne : le match, le placement, le suivi fédéral ; son statut mélange les deux derniers | `E/Fixture.php:68-210`, `:356-371` | Agent |
| L'écart avec la fédération vit en quatre porteurs | `E/Fixture.php:171-172`, `:196-197`, `:209-210` ; `E/FbiCorrection.php:70-84` | Agent |

Réglé par : compétition et engagement séparés, nature écrite une fois, suivi fédéral séparé du
match. **Reste à trancher** : un amical arrivé de la fédération sous une division « Amical … »
(voir §8).

## 4. B. Nouveau, et qui touche le modèle d'objets

| # | Sujet | Constat | Preuve | Vérifié |
|---|---|---|---|---|
| B1 | **Une règle s'écrit dans deux modèles** | La règle d'entraînement range tout en JSON validé par liste ; la règle de match range en colonnes typées. Portée, cible et intensité sont identiques. La séparation entraînement / match est voulue ; les deux formes ne sont pas motivées | `E/Constraint.php:37-86`, `E/MatchConstraint.php:64-88` | Agent |
| B2 | **L'indisponibilité d'un coach a trois saisies** | Pour l'entraînement, pour les matchs, dans la doléance | `S/ConstraintConfigValidator.php:93-98`, `E/MatchConstraint.php:78-85`, `E/CoachWish.php:89-90` | Agent |
| B3 | **Cinq vocabulaires pour dur / souple / éteint** | `HARD` et `MANDATORY` désignent tous deux le dur | `B/Enum/ConstraintRuleType.php`, `ImplicitRuleIntensity.php`, `VenueTravelRuleIntensity.php`, `TeamLinkIntensity.php`, `LockLevel.php` | Agent |
| B4 | **Le réglage de trajet a sa propre table** | Le moteur le lit dans le même bloc que les autres règles du club | `E/VenueTravelRuleSetting.php:50-77`, `engine/app/solver/constraints/parsing.py:68-73` | Agent |
| B5 | **Des règles d'équipe vivent en colonne et ailleurs** | Jour de match : colonne numérotée 0 à 6 et habitude numérotée 1 à 7, converties à l'émission. Gymnase imposé : colonne et contrainte | `E/Team.php:81-85`, `B/Dto/TeamInput.php:50-53`, `S/ScheduleConstraintBuilder.php:1441-1450`, `E/TeamMatchHabit.php:60-66` | Oui (jour de match) |
| B6 | **« Ce mail est déjà parti » existe sous cinq formes** | Trois tables, sept colonnes sur cinq entités, une table d'état, des seaux de limitation, une fenêtre de dates. Les règles divergent (noté dès un envoi réussi, ou seulement si tous ont réussi). Aucun endroit ne répond à « qu'a reçu ce club, et quand » | `B/Command/PeriodReminderCommand.php:205-209`, `B/Command/TransitionReminderCommand.php:185-190`, `E/CoachWishCampaign.php:67-77`, `E/CoachWishToken.php:52-54`, `E/Club.php:155-161`, `E/User.php:73-84` | Agent |

Autres doublons de même nature, plus petits (Agent) :

- deux tables de même forme pour les comités et les ligues de la fédération
  (`E/Basketball/FfbbCommittee.php:28-60`, `E/Basketball/FfbbLeague.php:29-58`) ;
- la tolérance de retard du coach recopiée de la réservation sur la séance
  (`E/ScheduleSlotTemplate.php:68-72`) ;
- la fenêtre et le nom d'un plan recopiés de la saison ou de l'entrée de calendrier, puis
  resynchronisés à la main (`S/SchedulePlanProvisioner.php:438-472`, `:1174-1175`, `:1222-1232`) ;
- le nom du produit dans deux variables d'environnement (`backend/config/services.yaml:42`, `:47`,
  `:144`, `:148`) ;
- les destinataires des alertes de la plateforme : la même requête écrite trois fois, plus une
  variable d'environnement (`B/Command/HealthAlertCommand.php:162`,
  `B/Command/DeploymentTickCommand.php:403`, `B/Command/ClubApprovalDigestCommand.php:214`,
  `B/Command/FeedbackDigestCommand.php:44-46`).

## 5. C. Nouveau, rangement de code (les tables ne changent pas)

| # | Sujet | Exemplaires | Preuve | Vérifié |
|---|---|---|---|---|
| C1 | **Arithmétique des horaires** : heure vers minutes, chevauchement, « finit dans sa journée » | 24 sites en 5 écritures ; 7 chevauchements ; 4 bornes de minuit | `S/VenueGridOverlap.php:68`, `S/MoveSlotService.php:1352`, `S/CoachDoubleBookingDetector.php:473`, `:509`, `S/TeamCaseOccupancyGuard.php:168`, `:217`, `S/Conflicts/ConflictMoments.php:40`, `S/Conflicts/PersonConflicts.php:82` | Agent |
| C2 | **Construction des mails** | 24 expéditions dans 20 classes ; le lien absolu recalculé à 22 sites | `S/MailFrom.php:20-49` (foyer de l'expéditeur, seul point commun) | Agent |
| C3 | **Mécanique des jetons** : secret, hachage, résolution, consommation | 3 fois ; la garde des pages publiques 3 fois ; la durée de vie recopiée en clair dans le texte des mails | `S/EmailVerifier.php:42-84`, `S/EmailChangeVerifier.php:41-81`, `S/ClubInvitationManager.php:42-94` | Agent |
| C4 | **Types et routes de l'écran recopiés par fonction** | Équipe, coach, lien coach-équipe, gymnase : 3 exemplaires chacun ; une dizaine de routes en 2 à 4 exemplaires | `F/wizard/api.ts:15`, `F/planning/api.ts:400`, `F/matches/api/teams.ts:7` | Agent |
| C5 | **Listes fermées recopiées entre zones** | 14 listes de l'écran hors de la garde de synchronisation ; les types de conflit sans liste côté backend (12 dans la documentation d'API, 14 à l'écran) | `backend/tests/CrossStack/TsUnionsMatchPhpEnumsTest.php:41-73`, `F/matches/api/conflicts.ts:72-93` | Agent |
| C6 | **Idiomes de requête réécrits** | « Est-il gestionnaire » 10 fois à côté d'une garde commune ; « la saison de la requête » 8 fois ; le verrou 4 fois | `S/ManagementAccessGuard.php:47`, `B/State/Processor/AbstractStateProcessor.php:170-172`, `S/ClubGenerationLock.php:55` | Agent |
| C7 | **Lectures de l'horloge système non motivées** | 6 | `B/Command/PurgeOverlaysCommand.php:73`, `B/Command/FeedbackDigestCommand.php:69`, `B/Command/ClubApprovalDigestCommand.php:93`, `S/RgpdExportService.php:100`, `:124`, `B/Controller/RgpdExportController.php:105` | Agent |

## 6. Objets sans usage (à verser dans P4-615)

| Objet | Constat | Preuve | Vérifié |
|---|---|---|---|
| Table des conflits entre contraintes | Aucun code ne l'écrit ; elle est seulement effacée et purgée | `E/ConstraintConflict.php`, `S/OverlayManager.php:231`, `S/SeasonDataPurger.php:114` | Oui (recherche sur `backend/src`) |
| Mémo de la fédération sur un match | Jamais écrit autrement qu'à vide | `E/Fixture.php:209-210`, `S/FbiFixtureImporter.php:1367` | Oui |
| Plancher de séances par équipe | Stocké, recopié à la saison suivante, jamais envoyé au moteur | `E/Team.php:78-79`, `S/ScheduleConstraintBuilder.php:1404-1406` | Agent |
| Sexe d'une catégorie | Aucun lecteur ni écrivain trouvé | `E/SportCategory.php:54-55` | À confirmer |

## 7. Séparations justifiées : à ne pas fusionner

La raison est écrite dans le code.

- Les tables de jetons (vérification de mail, changement de mail, mot de passe, invitation, lien de
  coach) : un jeton d'un usage ne doit jamais valoir pour un autre.
- Le journal d'audit des clubs et celui de la console d'administration : identités et régimes
  d'échec distincts.
- La réservation (intention durable) et la séance placée (résultat d'une version).
- L'entrée de calendrier (le fait) et le plan (la réponse).
- Le catalogue des fenêtres de ligue et la copie du club (copie modifiable).
- L'annuaire partagé des adversaires et les liens de salle d'un club (cloisonnement entre clubs).
- La boîte de démonstration des mails (sécurité).
- Les étiquettes d'équipe (données dérivées, resynchronisées).

## 8. Décisions

**Tri des sujets nouveaux (fondateur, 2026-11-20) :**

- les six sujets B entrent dans la conception, parce qu'ils changent les tables ;
- les sept sujets C se traitent au fil des domaines refondus, par la règle « un seul endroit par
  information » de la checklist de plan ;
- les objets sans usage (§6) sont versés dans P4-615.

**L'amical (fondateur, 2026-11-20)** : un match amical est un match d'une compétition de nature
« amical », qui ne porte aucune règle. Tout match a une équipe et une compétition. La nature est
écrite sur la compétition ; une compétition « Amicaux » existe d'office dans chaque saison ; une
division « Amical … » de la fédération devient une compétition amicale. Détail :
[`conception-cible.md`](../evolution/conception-cible.md) §18.
