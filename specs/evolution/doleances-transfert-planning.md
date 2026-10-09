# Doléances → planning : jours disponibles, créneaux habituels, ajout manuel, « Transférer au planning » (P2-63)

> **Statut** : cadrage en cours de validation par le fondateur (questions tranchées le 2026-10-09, §5 ; plan en 7 PR §8 ; points D-a → D-g à trancher avant le code, §8.10) ; plan (`planner`) après
> validation ; **code APRÈS le chantier vidéo P4-297** (place dans la file fixée par le fondateur le
> 2026-10-09).
> **Origine** : besoin d'un gestionnaire de club, dicté par le fondateur le 2026-10-09 (mots exacts §0).
> Fichier de détail de la ligne **P2-63** de [`roadmap.md`](roadmap.md) — il quitte le dépôt quand l'item
> est livré (règle d'entretien de la roadmap). Toutes les citations `fichier:ligne` sont relatives à la
> racine du dépôt et datent du cadrage (2026-10-09, code de `main` après #1137).

## 0. Le besoin, mots du fondateur

> « Dans les doléances on veut faire une évolution. Déjà on parle de jour indisponible, chose qui est
> souvent mal comprise. Je pense qu'il faut parler de jour disponible et décocher les jours où on ne
> l'est pas.
> Pour les coachs il faudrait [une] case garder ses créneaux habituels, qui sont les créneaux de saison.
> Une option pour la transmettre au gestionnaire.
> Quand j'ajoute une équipe à la main, le sélecteur n'est pas celui des équipes, donc rien n'est trié et
> c'est pas beau.
> Le gestionnaire voit les doléances, les traite (donc modifie/supprime et les ajoute) puis il clique sur
> transférer au planning, qui convertit les doléances sélectionnées automatiquement. Les SM1 demandent 3
> créneaux donc dans équipe ça modifie le nombre de créneaux des SM1. Maxime n'est pas disponible jeudi
> et vendredi donc ça crée 2 contraintes coach pour le planning... etc. L'écran de doléances dans un
> planning est un facilitateur pour éviter de modifier tout à la main. Actuellement on a 25 doléances
> pour le BCCL, c'est 25 fois le nombre de contraintes à convertir, c'est pas possible. D'où l'évolution.
> Dans l'ajout de doléance manuelle, je dois pouvoir ajouter une doléance à une équipe qui n'en a pas,
> donc sélection de la semaine d'abord. Ça filtre ensuite la liste des équipes qui n'en ont pas sur la
> semaine, et le gestionnaire remplit la doléance à la main.
> Si de base le gestionnaire ajoute une équipe Vétéran qui n'est pas sélectionnée de base dans le
> planning, le fait de leur ajouter une doléance les inclut au planning, quitte à avoir un signe
> distinctif sur les choses qui ont été rajoutées via les doléances. Que l'on comprenne d'où ça vient. »

Six volets : **A** jours disponibles · **B** créneaux habituels · **C** sélecteur d'équipes ·
**D** ajout manuel semaine d'abord · **E** « Transférer au planning » · **F** équipe hors plan incluse
avec trace de provenance.

## 1. Besoin reformulé

Les doléances de vacances passent d'une todo-list purement informative à un outil qui PRODUIT le plan de
période. Côté coach (page publique à jeton) : parler « jours disponibles » (tout coché, on décoche) au
lieu de « jours indisponibles », et offrir une case « garder mes créneaux habituels » (ceux du planning
de saison) transmise au gestionnaire. Côté gestionnaire : l'ajout manuel utilise LE sélecteur d'équipes
de l'appli (groupé par rang, trié), et se saisit semaine d'abord, filtré aux équipes sans doléance cette
semaine-là. Enfin, un geste « Transférer au planning » convertit les doléances choisies en réglages du
plan (volume de séances de l'équipe, indisponibilités coach, jours souhaités), inclut au plan une équipe
hors périmètre si besoin, avec une trace visible « vient des doléances » — pour ne plus convertir 25
doléances à la main.

## 2. Constats sur l'existant (vérifiés dans le code)

**Modèle doléance.**
- `CoachWish` = 1 ligne par équipe × semaine, ancrée à l'entrée calendrier MÈRE des vacances +
  `weekStart` (lundi) — `backend/src/Entity/CoachWish.php:30` (unique `calendar_entry_id, team_id,
  week_start`), champs `slotsWanted` 0-7 (`:69-70`), `unavailableDays` ISO 1-7 (`:72-79`), `wishedDays`
  « purement INFORMATIF (P4-312) » (`:81-89`), `comment` (`:91-92`), `done` coche « traité » (`:94-96`).
- Le docblock fige la philosophie actuelle que le volet E renverse : « Ce n'est JAMAIS une contrainte :
  le solveur ne la lit pas, rien n'est pré-rempli ; c'est une aide, le gestionnaire arbitre »
  (`CoachWish.php:14-16`, décision fondateur 2026-07-25). Même posture pour la mutualisation
  (`backend/src/Entity/CoachWishMutualization.php:14-17` — une par équipe × PÉRIODE, `partnerTeamIds`
  sans intégrité référentielle `:26-28,66-74`, `sharedSlots` `:76-78`).
- Campagne par période : `weeks[]` (lundis) + `teamIds[]` + `deadline` —
  `backend/src/Entity/CoachWishCampaign.php:47-65`. Périmètre coach = TeamCoach ∩ teamIds de la
  campagne, foyer unique `backend/src/Service/CoachWishPerimeter.php:11-17,50-61`.
- Doublon (période, équipe, semaine) refusé 422 à la création côté gestionnaire :
  `backend/src/State/Processor/CoachWishStateProcessor.php:55-62` ; coachId REQUIS à la création
  (« au nom d'un coach », `:48-52`). Côté public, la re-soumission ÉCRASE et remet `done=false` (« la
  parole du coach prime ») : `backend/src/Service/CoachWishUpserter.php:19-21,77-78`.

**Page publique coach (volets A/B).**
- Route `/doleances/:token` (`frontend/src/app/routes.tsx:125`, aperçu gestionnaire `:135`). Saisie par
  équipe × semaine : « Séances souhaitées », DayMultiPicker « Jours souhaités » puis « Jours
  d'indisponibilité » — `frontend/src/features/coach-wishes/WishTeamStep.tsx:103-134` ; intro « jours
  souhaités et jours d'indisponibilité » `frontend/src/features/coach-wishes/PublicWishPage.tsx:321`.
  Envoi dirty-only (`wishSections.ts:42-61`) : une semaine non touchée n'écrit RIEN — compatible avec un
  défaut « tous disponibles » (`unavailableDays=[]` est déjà le défaut de l'entité).
- Défense du jeton : rate-limit PAR IP avant tout lookup, 404 byte-identique, 410 après deadline,
  écriture BORNÉE au périmètre du token, GUC `app.club_id` relâché en finally —
  `backend/src/Controller/PublicCoachWishController.php:38-47,68-77,105-110`.
- Le contexte public n'expose AUCUN créneau de saison ni rien d'approchant
  (`frontend/src/features/coach-wishes/publicApi.ts:40-59`) : la case « garder mes créneaux habituels »
  (B) exige d'étendre contexte et soumission publics.

**Fenêtre gestionnaire (chemins de C/D/E).**
- `CoachWishesHub` (#1137) : fenêtre unique à 3 onglets Doléances · Sollicitation · Réglages
  (`frontend/src/features/coach-wishes/CoachWishesHub.tsx:20-32,149-153`).
- Ajout manuel : bouton « Ajouter » → `CoachWishForm` (`frontend/src/features/coach-wishes/WishesTab.tsx:152-166,179-197`).
  Ordre actuel : équipe → coach → semaine → créneaux
  (`frontend/src/features/coach-wishes/CoachWishForm.tsx:108-165`). **Volet C mesuré** : le sélecteur
  « Équipe » est un `Select` natif plat dans l'ordre brut de l'API (`CoachWishForm.tsx:111-126`), idem
  `MutualizationForm.tsx:87-107` — alors que les FILTRES du même onglet utilisent déjà le regroupement par
  rang (`WishesTab.tsx:83`) et que la maison unique `TeamSelect` (groupes par rang S/A/B…, pastille
  couleur du rang, recherche au-delà de 8 options, options désactivées motivées) existe :
  `frontend/src/shared/components/ui/team-select.tsx:52-92`.
- Équipes offertes à la saisie = celles avec coach PRINCIPAL seulement — décision fondateur 2026-08-01 :
  « comment avoir une doléance de coach si une équipe n'a pas de coach ? ben c'est pas possible »
  (`WishesTab.tsx:85-93`). Aucun filtre « a déjà une doléance cette semaine » : on peut choisir une
  équipe déjà servie et prendre le 422 (volet D).

**Côté plan de période (cibles de E/F).**
- Volume de séances d'une équipe pour une période = `TeamPeriodOverride.sessionsPerWeek`, accroché au
  PLAN — pas à la semaine (`backend/src/Entity/TeamPeriodOverride.php:42-61`) ; foyer de lecture unique
  `backend/src/Service/EffectiveTeamSessions.php:24-35`. Inclusion = `TeamPeriodOverride.isActive` ;
  sans ligne, défaut actif (`frontend/src/features/wizard/steps/PeriodTeams.tsx:105`).
- **Défaut d'une période de reprise fraîche : seed « Fanion + importantes » (rangs S+A), le reste
  DÉSACTIVÉ** (`PeriodTeams.tsx:42-47,65-68,130-132`) — c'est exactement le « Vétéran pas sélectionnée de
  base » du fondateur. Le seed est désarmé par la PREMIÈRE écriture d'override
  (`backend/src/State/Processor/TeamPeriodOverrideStateProcessor.php:40-64`,
  `markPlanTeamSelectionInitialized`) : un transfert qui écrirait un override AVANT la première ouverture
  du wizard désarmerait le seed en silence (tout le club resterait actif par défaut). Aucun verrou de
  génération sur ces écritures (seule la course à la suppression du plan est gardée, `:53-58`).
- Indispo coach = `Constraint` scope COACH, famille COACH_AVAILABILITY, config
  `unavailableDays`/`availableDays` (+`fromTime`/`untilTime`) — enums `backend/src/Enum/ConstraintScope.php`
  / `ConstraintFamily.php`, liste blanche `backend/src/Service/ConstraintConfigValidator.php:93-95` ;
  honorée par le moteur seulement pour un coach PRINCIPAL (ALIGN-19, `backend/docs/constraint-config-keys.md`).
  Jours souhaités = famille DAY `preferredDays` (`ConstraintConfigValidator.php:71-72`), REFUSÉ en HARD
  (nommé au récap pré-génération, `backend/src/Service/ConstraintValidationService.php:89-92`) — le
  véhicule PREFERRED existe donc déjà.
- Contrainte DATÉE : `Constraint.calendarEntryId` la sort du socle (`backend/src/Entity/Constraint.php:75-81`) ;
  un plan lit ses genèses (entrée-ENFANT = la semaine) ∪ les faits de sa MÈRE
  (`backend/src/Service/PeriodConstraintSelector.php:70-77` ; `backend/src/Entity/CalendarEntry.php:253-256`).
  Le wizard période crée déjà des datées sur l'entrée courante
  (`frontend/src/features/wizard/steps/ConstraintsStep.tsx:465`). Provenance : `Constraint.source`
  (ex. `'onboarding_seed'`, `backend/src/Service/DefaultConstraintSeeder.php:41`) et
  `sourceOccurrenceId` (`Constraint.php:70-73`) existent ; **`TeamPeriodOverride` n'a AUCUN champ de
  provenance**.
- **Grain** : un plan = UNE grille HEBDOMADAIRE (`backend/src/Entity/ScheduleSlotTemplate.php:56-59`,
  dayOfWeek) et un plan peut couvrir PLUSIEURS semaines — « plan de bloc »
  (`frontend/src/features/coach-wishes/wishesTarget.ts:14-17`), segment MILIEU = toutes les semaines
  pleines en UN plan (`frontend/src/features/cockpit/lib/weekSegmentation.ts:19-22`). Une doléance est
  PAR SEMAINE : la collision est structurelle (Q1). Une semaine de doléance peut aussi n'avoir AUCUN plan
  (semaines non matérialisées — naissance par WeekPicker + `SchedulePlanProvisioner::provisionPeriodPlan`,
  `backend/src/Service/SchedulePlanProvisioner.php:441`, sous `lockClubWindows` `:393`).
- Volet B, matière existante : « créneaux habituels » = les `ScheduleSlotTemplate` de la version pointée
  du plan SAISON (`backend/src/Entity/SchedulePlan.php:11-36`) ; le rail « Partir du planning de saison »
  (`backend/src/Controller/TranscribePeriodPlanController.php`) + « Combler » (placements source épinglés
  HARD) existe au niveau du PLAN ENTIER (`backend/src/Controller/FillPeriodPlanController.php:29-46`) ; un
  épinglage orphelin (ex. gymnase fermé aux vacances) BLOQUE la génération
  (`backend/src/Service/OrphanPinGuard.php:17-30`).

## 3. Le scénario existe-t-il ?

- **Coach** : oui — mail de sollicitation → `/doleances/:token` (`routes.tsx:125`) → intro → une étape
  par équipe (WishTeamStep) → récap → envoi unique. C'est là que A et B s'insèrent.
- **Gestionnaire, consultation/ajout** : oui, deux portes réelles — cockpit → panneau « À traiter » →
  bouton « Doléances » sur la carte des vacances (`frontend/src/features/cockpit/RadarPanel.tsx:551-555,624-629,815`) ;
  wizard de période → bandeau d'en-tête, bouton « Doléances » filtré sur la semaine du plan courant
  (`frontend/src/features/wizard/WizardLayout.tsx:107-117,488-493,629-631`). L'ajout manuel (C/D) vit
  dans l'onglet Doléances (`WishesTab.tsx:152-197`).
- **« Transférer au planning » (E/F) : n'existe NULLE PART** (grep `transf*` backend + frontend vide,
  contrôle positif du motif fait). Le geste manuel équivalent existe et c'est lui la douleur : étape
  Équipes du wizard de période (toggle + nombre de séances, `PeriodTeams.tsx:105-128`) et étape
  Contraintes (création d'une datée, `ConstraintsStep.tsx:465`), à répéter doléance par doléance
  (« 25 doléances pour le BCCL »).

## 3bis. Chemins d'abus (page publique à jeton touchée par A/B ; E consomme des données saisies via ce jeton)

1. Quelqu'un d'hostile qui détient un jeton de coach (lien transféré, boîte mail compromise) soumet des
   doléances extrêmes (7 créneaux partout, tous jours indisponibles, « garder mes créneaux ») qui, après un
   « Transférer » de masse par le gestionnaire, écrivent volumes et contraintes HARD dans le vrai plan —
   amplification d'une saisie non authentifiée en objets de plan.
2. Une re-soumission répétée via le jeton remet `done=false` (`CoachWishUpserter.php:77-78`) : si un
   re-transfert resynchronise, un coach hostile fait osciller les contraintes du plan à distance ; le
   transfert doit rester un geste gestionnaire explicite, idempotent, jamais automatique sur re-soumission.
3. Si le futur flag « garder mes créneaux » ou l'inclusion d'équipe (F) était écrit par la page publique
   hors du périmètre du token, un coach inclurait au plan une équipe qu'il n'encadre pas — l'écriture doit
   rester bornée à (ce coach, ses équipes ∩ campagne, les semaines de la campagne) comme aujourd'hui
   (`PublicCoachWishController.php:44-46`).
4. Si B affiche les créneaux habituels sur la page publique, le GET à jeton expose davantage de la grille
   du club qu'avant : un jeton volé/énuméré fuit des horaires de gymnases — le rate-limit IP et le 404
   byte-identique existants doivent couvrir le nouveau contenu, et l'exposition se limiter aux équipes du
   token.
5. L'endpoint de transfert côté gestionnaire écrit en masse overrides + contraintes : sans
   `ManagementAccessGuard::assertManager` (SEC-07) un membre non-gestionnaire pousserait des contraintes
   dans le plan de son club.

Chaque chemin devient un test d'abus NOMMÉ au plan (fichier + suite).

## 4. Décisions de conception proposées (décision → exemple concret → alternative écartée)

1. **A = inversion de PRÉSENTATION, donnée inchangée** (`unavailableDays` reste le stockage et le
   payload). → Le coach voit « Jours disponibles » avec Lun…Dim tous pressés ; il dépresse Jeu et Ven ;
   l'envoi reste `unavailableDays:[4,5]` ; une réponse déjà saisie (`unavailableDays:[4]`) se réaffiche
   avec 6 jours pressés — zéro migration. → Écarté : basculer la donnée en `availableDays` (migration +
   API publique + formulaire gestionnaire + mails à réécrire, pour le même pixel).
2. **L'inversion s'applique aux deux SAISIES (page coach + formulaire gestionnaire), la LECTURE
   synthétique reste en creux.** → `WishTeamStep` et `CoachWishForm` affichent « Jours disponibles » ; la
   ligne de la todo continue d'afficher « indispo : jeu, ven » (`WishesTab.tsx:219`), l'info d'arbitrage
   compacte. → Écarté : inverser aussi la lecture (« dispo : lun, mar, mer, sam, dim », plus long et moins
   actionnable).
3. **B = nouveau champ booléen sur `CoachWish` (« garder les créneaux habituels »), transmis et AFFICHÉ,
   pas auto-converti en épinglages (v1)** — sous réserve Q3/Q4. → Maxime coche la case sur SM1 semaine 1 ;
   la ligne SM1 de la todo porte une pastille « souhaite garder ses créneaux » (StatusPill) ; au transfert,
   rien de plus n'est écrit — le gestionnaire dispose déjà de « Partir du planning de saison ». → Écarté :
   épingler automatiquement les créneaux de saison en HARD — un gymnase fermé aux vacances rendrait
   l'épingle orpheline et BLOQUERAIT la génération (`OrphanPinGuard.php:17-30`), loin de sa cause.
4. **C = `TeamSelect` partout dans les formulaires doléances** (CoachWishForm + MutualizationForm ; les
   rangs sont déjà chargés, `WishesTab.tsx:43`). → Le sélecteur « Équipe » montre les groupes par rang avec
   la pastille couleur et la recherche au-delà de 8 équipes — identique au sélecteur de l'étape Réserver.
   → Écarté : trier le `<select>` natif à la main (réinvente une maison unique).
5. **D = ordre semaine → équipe, équipes déjà servies DÉSACTIVÉES avec motif, jamais masquées.** → Le
   gestionnaire choisit « Semaine du 27/04 » ; dans TeamSelect, « SM1 — a déjà une doléance cette semaine »
   est grisée (`optionMeta.disabled`, `team-select.tsx:10-19`), « Vétérans » est sélectionnable ; il remplit
   créneaux/jours/commentaire. → Écarté : masquer les équipes servies (un état invisible est un état faux)
   ; écarté aussi : garder l'ordre équipe-d'abord et laisser le 422 conclure.
6. **E = le transfert écrit les objets STANDARDS du plan, datés à la SEMAINE.** `slotsWanted` →
   `TeamPeriodOverride.sessionsPerWeek` du plan couvrant la semaine ; `unavailableDays` → UNE contrainte
   datée (scope COACH, famille COACH_AVAILABILITY, `config.unavailableDays`) pendue à l'entrée-ENFANT de la
   semaine (seule cette semaine la lit, `PeriodConstraintSelector.php:70-77`) ; `wishedDays` → contrainte
   datée TEAM/DAY `preferredDays` en PREFERRED ; `comment` → `description` de la contrainte. → Exemple :
   doléance « SM1 · Maxime · semaine du 20/04 · 3 créneaux · indispo jeu, ven · souhaité lun » + clic
   « Transférer » ⇒ l'étape Équipes du plan de cette semaine affiche SM1 à 3 séances, l'étape Contraintes
   montre « Indisponibilité de Maxime (doléances) · Obligatoire » et « Jours à privilégier SM1 : lundi ·
   Préféré ». → Écarté : un objet « doléance transférée » lu par le moteur (toucherait CONTRACT_VERSION et
   dupliquerait la sémantique des contraintes) ; écarté aussi : N contraintes par jour (« 2 contraintes
   coach » dans les mots du fondateur) — une seule contrainte à 2 jours fait le même travail et garde le
   radar lisible.
7. **Idempotence par `sourceOccurrenceId` = id de la doléance.** → Re-transférer après une re-soumission
   du coach MET À JOUR l'override et LA contrainte existante (retrouvée par source `coach_wish` +
   sourceOccurrenceId), jamais une deuxième ligne. → Écarté : empiler une contrainte à chaque transfert.
8. **Modifier/supprimer une doléance déjà transférée ne touche PAS le plan.** → Le gestionnaire supprime
   la doléance SM1 : l'override à 3 séances et la contrainte restent (éditables au wizard) ; seul un
   nouveau transfert recale. → Écarté : cascade doléance→plan (supprimer une ligne de todo changerait le
   planning en silence).
9. **F = le transfert (geste GESTIONNAIRE, jamais la page publique) réactive l'équipe (`isActive=true`) et
   la trace de provenance est PERSISTÉE.** → Vétérans désactivés par le seed S+A ; sa doléance « 1
   créneau » transférée ⇒ la ligne Vétérans de l'étape Équipes passe active à 1 séance avec une pastille
   « via doléances » (même pastille sur la contrainte et dans la todo). Nécessite un champ de provenance
   côté `TeamPeriodOverride` (forme exacte au plan). → Écarté : marqueur d'UI non persisté (perdu au
   rechargement et pour les autres membres).
10. **Semaine sans plan : le transfert n'y écrit rien et le DIT ; il ne fait jamais naître un plan.** →
    Depuis le cockpit, la semaine du 20/04 (plan existant) est convertie ; la semaine du 27/04 affiche
    « adaptez d'abord cette semaine » (geste Adapter/WeekPicker existant). → Écarté : auto-provisionner le
    plan au transfert — geste composé caché, et la première écriture d'override désarmerait le seed
    « Fanion + importantes » en silence (`TeamPeriodOverrideStateProcessor.php:51-64`).
11. **Sélection explicite, « traité » automatique après transfert.** → Chaque ligne de la todo porte une
    case de sélection (présélection = les non-traitées) ; « Transférer au planning (12) » convertit, coche
    `done` sur les converties et récapitule « 12 converties · 3 ignorées (semaine sans plan) ». → Écarté :
    transférer tout le visible sans sélection (« il les traite… puis il clique » — l'arbitrage précède).
12. **`slotsWanted=0` → `isActive=false` sur le plan de la semaine.** → Doléance « Aucun créneau
    souhaité » U11 ⇒ la ligne U11 de l'étape Équipes passe décochée (`PeriodTeams.tsx:105,185`). →
    Écarté : `sessionsPerWeek=0` (sémantique non prévue par l'étape Équipes).
13. **Les mutualisations ne sont PAS converties (v1).** → « SF2 souhaite mutualiser 1 séance avec SF1 ou
    SF3 » reste un item de todo : le choix du partenaire (« ou ») est un arbitrage humain ; le véhicule
    existe (`backend/src/Entity/SharedTrainingBlock.php:12-22`) mais créer le bloc avec TOUS les
    partenaires pressentis fausserait le volume commun. → Écarté : conversion automatique du bloc.

## 5. Questions au fondateur (par ordre d'importance, avec la recommandation)

### Décisions du fondateur (2026-10-09)

- **Q1 — grain semaine vs planning d'un bloc : la question tombe, la doléance SUIT le découpage du
  planning.** Mots du fondateur : « si le planning de vacances se fait en un bloc, ça veut dire que l'on
  propose une semaine que l'on va dupliquer. Il est complètement stupide de proposer deux semaines
  distinctes alors que le planning n'est qu'un seul gros bloc. » ⇒ planning d'un bloc = le coach remplit
  UNE semaine type pour le bloc ; planning scindé = une doléance par semaine ; aucun arbitrage
  max/min/signalement au transfert. Le découpage est fixé par le PLANNING : la collecte ne se lance
  qu'après sa naissance (Q8). **P2-64** (plannings de vacances scindés par défaut, fusion à la demande)
  est née de cette question.
- **Q2 — indisponibilité coach transférée = OBLIGATOIRE (HARD).** Mots du fondateur : « il dit qu'il
  n'est pas dispo, et pas il préfère ne pas être dispo ». Les jours souhaités restent en Préféré
  (décision 6).

- **Q3/Q4 — « garder mes créneaux habituels » = une CONTRAINTE NOUVELLE côté gestionnaire, adossée à
  un mécanisme moteur EXISTANT (reco suivie).** Une ligne par équipe, « Garder ses créneaux de
  saison » (scope TEAM, toujours Préférée, marque « via doléances », visible et supprimable à l'étape
  Contraintes du planning) ; le transfert la crée pour chaque équipe dont le coach a coché la case ; la
  case suit le grain de Q1 (une par doléance). À la génération, le backend émet la référence de saison
  (`socleReferenceAssignments`, aujourd'hui émise par « Combler » seul, `ScheduleConstraintBuilder.php:691`)
  pour CES équipes seulement ; le moteur favorise gymnase + jour + heure, puis jour + heure (le niveau
  gymnase est À AJOUTER : `add_socle_reference_bonus`, `engine/app/solver/objective/terms/stability.py:60`,
  ignore le gymnase) ; force modulée par le rang. Écarté : un LOT de contraintes (jours préférés + plage
  horaire + gymnase préféré) — perd le couplage jour∧heure∧gymnase d'un créneau, multiplie les objets,
  fige une photo du socle. **Force = préférence TRÈS FORTE, pas un bonus de départage.** Mots du
  fondateur : « ce n'est pas un bonus, c'est une préférence TRÈS FORTE. Un coach senior qui a demandé
  son créneau garde son créneau un maximum, il n'y a pas de raison pour que cela change. Les seniors sont
  des adultes, donc n'ont pas de réelles vacances, on ne peut pas chambouler leur planning facilement. »
  ⇒ les poids actuels du bonus de référence (S 20 → D 12, `weights.py:289`, échelle de départage) ne
  suffisent pas : calibrage à arbitrer (question ouverte ci-dessous). Touche le contrat
  backend⇄engine (gymnase dans la référence) et la sémantique des contraintes (NR).
- **Q3bis — calibrage de « très forte » : placer les séances reste prioritaire, et on ALERTE.**
  « Garder ses créneaux de saison » passe avant TOUTES les autres préférences (jours souhaités, gymnase
  préféré, enchaînements…) mais jamais avant le placement d'une séance demandée : si les U13 n'ont
  AUCUNE autre place que le créneau de saison des SM1, les SM1 bougent ; s'ils en ont une autre, même
  moins bonne, les U13 bougent. Mots du fondateur : « placer les séances reste prioritaire, mais on
  alerte que SM1 2/3 créneaux sont conservés pour le bien du planning ». ⇒ diagnostic de fin de
  génération par équipe concernée : « SM1 : 2/3 créneaux de saison conservés », avec la raison quand
  un créneau n'a pas pu l'être. Écartés : b (le créneau avant le placement des autres) et c (selon les
  rangs).

- **Q5 — coach OBLIGATOIRE par le canal coach, FACULTATIF en saisie manuelle.** Mots du fondateur :
  « une équipe sans coach ne peut pas avoir de doléance via mail de coach. Si le gestionnaire veut gérer
  en manuel les vétérans, il a le droit, on ne doit pas le bloquer. Le coach est obligatoire à l'envoi
  des créneaux mais il est facultatif si on passe en mode manuel. » ⇒ la collecte (mail + page publique)
  reste bornée aux équipes à coach principal ; l'ajout manuel du gestionnaire accepte TOUTE équipe, coach
  facultatif — le `coachId` de `CoachWish` devient nullable pour une saisie manuelle
  (`CoachWishStateProcessor.php:48-52` exige aujourd'hui un coach), et le filtre de l'ajout manuel
  (`WishesTab.tsx:85-93`, équipes à coach principal seulement) s'ouvre à toutes les équipes.
  **Conséquence (validée)** : une doléance SANS coach qui porte des jours indisponibles devient, au
  transfert, « l'équipe ne s'entraîne pas ces jours-là », OBLIGATOIRE comme l'indisponibilité coach (Q2).
- **Q6 — « Transférer au planning » vit dans l'onglet Doléances, aux deux portes (reco suivie).**
  Ouverte depuis le cockpit, la fenêtre transfère toutes les semaines (ou blocs) qui ont déjà un
  planning ; ouverte depuis le wizard du planning en cours, elle transfère la semaine ou le bloc de ce
  planning. Écarté : le wizard seul.

- **Q7 — seules les doléances COCHÉES partent au transfert, et une doléance transférée est TRAITÉE.**
  Mots du fondateur : « dans ma tête seules les doléances qui sont cochées partent dans transférer et
  comme elles sont transférées, elles sont donc traitées ». ⇒ le transfert coche `done` sur les
  converties (décochable à la main ; une re-soumission du coach la remet « à traiter », comportement
  existant). Point de conception pour le plan : la todo porte DÉJÀ une case « traité » (`done`) — la
  case de SÉLECTION pour le transfert et l'état « traité » ne doivent pas se confondre à l'écran
  (à trancher au plan, passe ui-ux, captures au fondateur).

- **Q8 — la collecte de doléances n'existe QU'APRÈS la naissance du (des) planning(s) de vacances.**
  Proposition du fondateur (« la demande de doléance est quelque chose qui doit se faire avec un
  planning en cours car les deux sont liés »), reco suivie entre ses deux options : sur une carte de
  vacances SANS planning, « Doléances » ouvre la fenêtre qui explique « Créez d'abord le planning des
  vacances » et mène au geste « Adapter » ; les semaines de la collecte ne se cochent plus dans
  Réglages, elles DÉRIVENT des segments des plannings (une semaine par planning scindé, une semaine type
  par planning d'un bloc). Ferme le point resté ouvert en Q1 (qui fixe le découpage : le planning) et
  supprime le cas « semaine sans planning » au transfert ; le transfert reste aux deux portes (Q6).
  Écarté : le transfert limité à l'édition du planning (ne règle pas le découpage d'une collecte
  lancée avant le planning).
- **Q8bis — valider/rouvrir la saison supprime AUSSI la collecte et les doléances des vacances à
  venir.** Proposition du fondateur, avec deux précisions validées : (1) la confirmation de validation
  de la saison, qui annonce déjà la suppression des plannings de période futurs (ADR-0002), annonce
  aussi, chiffres à l'appui, les doléances reçues qui partiront (« … et les 25 doléances déjà reçues
  pour la Toussaint ; il faudra relancer la collecte ») ; (2) la CAMPAGNE (liens envoyés aux coachs)
  part avec, pour qu'aucun coach ne remplisse un formulaire dont les semaines ne correspondent plus.
  Même critère que la suppression des plans : la DATE (`startDate > today`). Le coût assumé : les coachs
  répondent une seconde fois.

**Toutes les questions sont tranchées (2026-10-09).** Le plan (`planner`) suit.


1. **Grain semaine vs plan multi-semaines.** Un plan peut couvrir 2+ semaines avec UNE seule grille
   hebdo ; or les doléances divergent par semaine (ex. 2 créneaux en S1, 1 en S2). Que fait le transfert
   sur un tel plan ? — Reco : convertir seulement ce qui est identique sur toutes les semaines du plan, et
   NOMMER les divergences sans écrire (« SM1 : 2 créneaux S1 / 1 créneau S2 — ce plan couvre les deux
   semaines, départagez ou découpez la période »). Choix : signaler sans écrire / prendre le max / prendre
   le min.
2. **Intensité de l'indispo coach transférée : Obligatoire (HARD) ou Préféré ?** — Reco : HARD (c'est le
   sens d'« indisponible » ; les jours SOUHAITÉS partent en Préféré, le HARD y est refusé par le moteur).
3. **« Garder mes créneaux habituels » : info seule ou action au transfert ?** — Reco : info seule en v1
   (pastille côté gestionnaire) ; l'épinglage automatique des créneaux de saison peut bloquer la
   génération si un gymnase ferme aux vacances, et « Partir du planning de saison » couvre déjà le cas.
4. **Grain de cette case : par équipe × semaine ou une fois par équipe pour la période ? Cochée, doit-elle
   neutraliser la saisie de la semaine ?** — Reco : par équipe × semaine, et oui, elle remplace la saisie
   de la semaine.
5. **Équipes sans coach principal.** La règle du 2026-08-01 (« pas de doléance sans coach ») limite
   l'ajout manuel aux équipes à coach principal ; inclure les Vétérans suppose-t-il d'ouvrir aux équipes
   SANS coach principal (doléance d'équipe, coach facultatif) ? — Reco : garder l'exigence en v1 (le
   modèle refuse une création sans coach) ; sinon second changement de modèle à assumer.
6. **Où vit « Transférer au planning » ?** — Reco : dans l'onglet Doléances de la fenêtre, aux deux
   portes : depuis le cockpit il traite toutes les semaines AYANT un plan ; depuis le wizard, la semaine
   courante.
7. **Après transfert, la doléance passe-t-elle automatiquement « traitée » ?** — Reco : oui, la coche
   reste décochable à la main.
8. **Le transfert ne doit JAMAIS créer le plan manquant (renvoi au geste « Adapter ») ?** — Reco :
   confirmé jamais (naissance de plan = geste assumé, verrous, seed du périmètre).

## 6. Hors périmètre (v1)

- Aucun changement moteur ni de contrat backend⇄engine (CONTRACT_VERSION 1.4 intact) : le transfert
  n'écrit que des objets déjà lus par le payload (overrides, contraintes datées).
- Pas de conversion automatique des mutualisations ni des commentaires en objets de plan ; pas de
  création de `SharedTrainingBlock`.
- Pas de naissance de plan, pas de génération déclenchée, pas de bascule de version par le transfert.
- Aucune écriture dans le SOCLE saison : ni `Team.sessionsPerWeek`, ni contraintes permanentes — tout
  reste au grain période.
- Pas de migration des réponses déjà saisies (volet A = présentation).
- Pas de refonte des onglets Sollicitation/Réglages, des mails de campagne, ni de notification coach
  (ligne roadmap « Notifications coach » séparée).
- Pas de synchronisation continue doléance↔plan (le re-transfert est le seul recalage).

## 7. Axes et revues

- **Axes §7.1 touchés** : *constraint semantics* (le transfert CRÉE des contraintes qui doivent être
  honorées : COACH_AVAILABILITY HARD, DAY `preferredDays` PREFERRED — NR smoke sémantique, même PR) ;
  *planning lifecycle* (écritures d'objets de plan, interaction avec le seed de périmètre, versions/verrous
  ADR-0002 — NR). Non touchés : tenant isolation, generation pipeline, périmètre engagé, contrat
  backend⇄engine, auth & memberships.
- Zones : `backend/` + `frontend/` (pas d'engine, pas de landing) ⇒ `make -C backend behat` au plan.
- **`/security-review` systématique** : page publique à jeton touchée (A/B modifient la page et le
  contrat public GET/POST) ; les 5 chemins du §3bis deviennent des tests d'abus nommés.

## 8. Plan d'implémentation (agent `planner`, 2026-10-09)

> Plan produit après la validation de toutes les questions (§5). Il PRIME sur §6/§7 du cadrage là où
> les décisions du fondateur les ont rendus caducs (notamment « aucun changement moteur » : faux depuis
> Q3/Q4). Le code se fera après le chantier vidéo P4-297. **Les points D-a → D-g (§8.10) restent à
> trancher par le fondateur AVANT le code.** Lane Full, 7 PR.

### 8.1 Précisions de code relevées par le plan (vs cadrage)

- Le seed « Fanion + importantes » d'un plan de reprise vit CÔTÉ FRONTEND
  (`frontend/src/features/wizard/steps/PeriodTeams.tsx:163-175`, garde `teamSelectionInitialized`) : un
  transfert avant la première ouverture du wizard le désarmerait en silence (point D-e).
- La cascade « valider/rouvrir la saison détruit les plans futurs » ne vit PAS dans `SocleGuard` mais
  dans `backend/src/Controller/ValidateScheduleController.php:174-197` et
  `ReopenScheduleController.php:91-109` (409 `overlays_exist` puis
  `OverlayManager::deletePeriodPlanForEntry`), critère `CalendarEntryRepository::findWithPlanNotStarted`
  (`startDate > :today`, `:57-70`) ; annonce front `frontend/src/features/planning/PlanningPage.tsx:1181-1183`.
- Contrat de la référence de saison : `engine/app/schemas/input_schema.py:227-239`
  (`SocleReferenceAssignmentSchema`, sans gymnase) ; émise en comblement seul
  (`backend/src/MessageHandler/GenerateScheduleHandler.php:475-497`).
- Barème moteur : `engine/app/solver/objective/weights.py:31` (`SCORE_FORMULA_VERSION` V13), `:33-120`
  (S 10000 / A 1000 / B 100 / C 10 / D 1, session_count 20, preferred 10, preferred_day 5,
  preferred_time 5, rest 3, spacing −2, avoided_venue −10, overload_day −15, bien-être −6,
  missing_session −1000), `:291-299` référence de saison S 20 → D 12.

### 8.2 Zones et périmètre

- Zones : `backend/` + `engine/` + `frontend/`.
- Autorisés : `backend/{src,migrations,tests,features,docs}`, `engine/{app,tests,CONTRACT_VERSION}`,
  `frontend/{src,tests/e2e}`, `specs/`, `docs/testing/`, `.github/workflows/ci.yml` (steps NR seulement).
- Interdits : `landing/`, `system-pages/`, console superadmin, Mercure, auth/JWT,
  `engine/app/solver/match_placement/**`, TenantFilter/RLS, `_adaptive_workers`, socle SAISON
  (`Team.sessionsPerWeek`, contraintes permanentes).
- Hors refactor : le seed S+A n'est PAS déplacé au backend (répliqué au transfert en miroir déclaré +
  test de parité) ; mails de campagne, onglet Sollicitation, mutualisations, radar : intouchés.

### 8.3 Découpage en PR (ordre ; 1 et 2 parallélisables ; 3 et 4 indépendantes ; 5 dépend de 2+3+4 ; 6 de 5 ; 7 indépendante dès 3)

**PR 1 — A + C (frontend pur).** « Jours disponibles » (tout pressé, on dépresse ; payload
`unavailableDays` inchangé) dans `WishTeamStep.tsx`, `CoachWishForm.tsx`, intro `PublicWishPage.tsx` ;
lecture todo en creux inchangée (`WishesTab.tsx:219`) ; `TeamSelect` dans `CoachWishForm.tsx` et
`MutualizationForm.tsx`. Gardes : `dayPickerGuard`, passe `ui-ux-pro-max` (polarité du picker inversé).
Validation : image tooling rebâtie, `make -C frontend lint` + `test`. `/security-review` : oui (libellés
de la page publique).

**PR 2 — D + Q5.** `CoachWishStateProcessor` : coach FACULTATIF en création gestionnaire (canal public
inchangé ; colonne déjà nullable, aucune migration). `CoachWishForm` : semaine → équipe ; TOUTES les
équipes ; équipes déjà servies sur la semaine désactivées avec motif (jamais masquées) ; coach
« (aucun) ». Tests : `backend/tests/Integration/Api/CoachWishApiTest.php`, vitest du formulaire.
Validation : `tests-complete` + `rector` + Behat (`voeux-des-coachs.feature`,
`generation-du-planning-de-saison.feature`) + frontend.

**PR 3 — B : champ « garder ses créneaux de saison ».** Migration à la main `CoachWish.keepSeasonSlots`
(bool, défaut false) ; GET/POST publics étendus, bornés au périmètre du jeton, AUCUNE exposition des
créneaux de saison (booléen nu) ; case dans `CoachWishForm`, pastille StatusPill dans la todo.
Tests : `PublicCoachWishTest.php`, `CoachWishUpserterTest.php`, vitest. `/security-review` : OUI.

**PR 4 — Q8 : collecte seulement après la naissance des plannings.** Semaines de campagne DÉRIVÉES des
segments des plannings de la période (une par planning scindé, une semaine type par planning d'un bloc
— D-c) ; `CampaignSettingsTab`/`useCampaignSettings` affichent, une garde BACKEND décide (semaines ⊆
semaines portées par un plan, refus 422 via `refuse()`) ; carte de vacances sans planning : la fenêtre
explique « Créez d'abord le planning » et mène à « Adapter ». Campagnes existantes : D-f.
Tests : `CoachWishCampaignApiTest.php`, vitest, scénario Behat « la collecte attend le planning ».

**PR 5 — E + F : « Transférer au planning ».** `POST /api/coach-wishes/transfer` (patron
`FillPeriodPlanController`), management-only (`ManagementAccessGuard::assertManager`), sous
`SchedulePlanProvisioner::lockPlanScope`. Conversions : `slotsWanted>0` → `TeamPeriodOverride.sessionsPerWeek`
du plan de la semaine, `=0` → `isActive=false` ; `unavailableDays` + coach → UNE datée COACH
COACH_AVAILABILITY **HARD** (enfant de la semaine, mère si bloc), commentaire en description ; sans coach
→ TEAM DAY `forbiddenDays` **HARD** (D-a) ; `wishedDays` → TEAM DAY `preferredDays` PREFERRED ;
idempotence `source='coach_wish'` + `sourceOccurrenceId` = id de la doléance ; modifier/supprimer une
doléance ne touche jamais le plan ; F : `isActive=true` + nouveau champ de provenance
`TeamPeriodOverride.source` (migration) + pastille « via doléances » (PeriodTeams, contrainte, todo) ;
seed S+A matérialisé côté serveur si le plan n'est pas initialisé (D-e, miroir + parité) ; `done=true`
sur les converties, jamais de re-transfert automatique. Front : case de sélection par ligne
(présélection = non traitées), « Transférer au planning (N) », récap « N converties · M ignorées
(motif) », distinction sélection/traité (D-b, passe ui-ux + captures). NR planning lifecycle :
`CoachWishTransferTest::testTransferNeverBirthsAPlanNorTouchesValidatedSocle` + scénario Behat ; gate =
step `ci.yml` ET ligne `docs/testing/blocking-tests.md`, même PR. `/security-review` : OUI.

**PR 6 — Q3/Q4/Q3bis : contrainte « Garder ses créneaux de saison ».** Famille
`ConstraintFamily::SEASON_SLOTS` (TEAM, toujours Préférée, HARD refusé), visible/supprimable à l'étape
Contraintes, créée par le transfert (idempotente) ; en génération PLEINE d'un plan de période, le
backend émet pour CES équipes les placements de la version pointée du socle **avec `venueId` et
`keep: true`** (le comblement reste byte-identique — D-d). Contrat : `SocleReferenceAssignmentSchema`
gagne `venue_id` et `keep` → **CONTRACT_VERSION 1.4 → 1.5** (les 3 `*ContractSchemaTest`, balayage des
citations « 1.4 », pytest relancé). Moteur : deux niveaux de bonus pour `keep` — plein
(gymnase+jour+heure) et partiel (jour+heure). Calibrage (preuve d'empilement, patron `weights.py`) :
plein S 85 / A 80 / B 75 / C 70 / D 65 ; partiel S 60 / A 56 / B 52 / C 48 / D 44 — sous 90 (écart
contesté B−C : au-delà, une équipe B non placée sauterait au profit d'un keep C/D) et ≪ 1021 (une
séance perdue), au-dessus de l'empilement réaliste des autres préférences (~35-40) ; résiduel
pathologique (~68) documenté (D-g). `SCORE_FORMULA_VERSION` → V14 ; nouvelle golden + invariant.
Diagnostic « N/M créneaux de saison conservés » : `engine/app/solver/result_builder/diagnostics/season_slots.py`,
code `season-slots-kept`, causes `slot_absent` / `displaced`, traduit par `DiagnosticMessageBuilder.php`,
affiché par `DiagnosticsPanel.tsx`, gardé par `DiagnosticCodesExistUpstreamTest`. NR constraint
semantics : `backend/tests/CrossStack/SeasonSlotKeepSemanticsGateTest.php` (vrai moteur, témoin
falsifiable + contre-témoin Q3bis) + `SocleReferencePayloadParityTest` à jour. Validation :
`make -C engine test` (goldens), restart engine, `tests-complete` + `rector` + Behat + frontend.

**PR 7 — Q8bis : valider/rouvrir la saison emporte collecte + doléances futures.**
`ValidateScheduleController` + `ReopenScheduleController` suppriment aussi `CoachWishCampaign` (jetons
par FK cascade), `CoachWish`, `CoachWishMutualization` des MÈRES de vacances `startDate > today`
(nouvelle requête, `ClockInterface`) ; le 409 `overlays_exist` gagne les compteurs, l'annonce dit « … et
les 25 doléances déjà reçues pour la Toussaint ; il faudra relancer la collecte ». NR : scénario Behat
dans `le-socle-commande-les-plans.feature` + test de 404 byte-identique du jeton purgé.

### 8.4 Tests d'abus (§3bis)

1. `CoachWishTransferTest::testTransferConvertsOnlyExplicitlySelectedWishes` +
   `testPublicSubmissionAloneNeverWritesPlanObjects`.
2. `CoachWishTransferTest::testResubmissionResetsDoneButNeverRetransfers` +
   `testRetransferIsIdempotentBySourceOccurrenceId`.
3. `PublicCoachWishTest::testKeepSeasonSlotsIsBoundedToTokenPerimeter` +
   `testPublicPostCannotIncludeATeamInThePlan`.
4. `PublicCoachWishTest::testPublicContextExposesNoSeasonSlots`.
5. `CoachWishTransferTest::testTransferRequiresManagementRole` (garde FALSIFIÉE, pas seulement lue).

### 8.5 Axes, revues, Behat/e2e

- constraint semantics : `SeasonSlotKeepSemanticsGateTest` (groupe `contract`, job `engine-semantics`) +
  golden/invariant + scénario Behat « transférer ». planning lifecycle : `CoachWishTransferTest` +
  scénario Q8bis. Contrat backend⇄engine : 1.5 + les 3 `*ContractSchemaTest` +
  `SocleReferencePayloadParityTest`. Non touchés : tenant isolation, pipeline de génération, périmètre
  engagé, auth & memberships.
- `/security-review` : PR 1, 3 et 5 (page publique à jeton).
- Behat : `voeux-des-coachs.feature` (PR 2-5), `le-socle-commande-les-plans.feature` (PR 7),
  `generation-du-planning-de-saison.feature` à chaque PR backend/engine. E2E : seul
  `visual-reference.spec.ts` peut demander une re-baseline (écran wizard contraintes, PR 6).
- Doc : ce fichier + roadmap, `specs/courantes/{accueil-cockpit-temporel,types-de-planning,openapi-snapshot.meta}.md`,
  `backend/docs/constraint-config-keys.md`, `docs/testing/blocking-tests.md` (dont la ligne
  `SocleReferencePayloadParityTest` « sans venueId » et la citation « contrat 1.4 »), CLAUDE.md §6
  (contrat 1.5), état des lieux à la livraison.

### 8.10 Points à trancher par le fondateur AVANT le code (reco + exemple)

- **D-a — « l'équipe ne s'entraîne pas ces jours-là »** : TEAM DAY `forbiddenDays` HARD (clé existante).
  Ex. : Vétérans sans coach, indispo jeu+ven → « Jours interdits Vétérans : jeudi, vendredi · Obligatoire ».
- **D-b — sélection vs « traité »** : case de sélection à gauche, l'état « traité » reste à droite ;
  présélection = non traitées ; après transfert les lignes passent barrées. Passe ui-ux + captures.
- **D-c — semaine type d'un planning d'un bloc** : `weekStart` = lundi de la première semaine du bloc,
  affiché « Semaine type du bloc (20/04 → 01/05) » (zéro migration).
- **D-d — le comblement gagne-t-il le niveau gymnase ?** Non en v1 : « Combler » reste au point près ;
  seule la contrainte active le barème fort et le gymnase.
- **D-e — seed S+A au transfert avant la première ouverture du wizard** : le transfert matérialise le seed
  côté serveur puis convertit (Vétérans actifs via doléances, U13/U15 loisir désactivés comme si le wizard
  avait semé).
- **D-f — campagnes existantes à la bascule Q8** : compat lecture seule ; seule la création/édition passe
  au régime dérivé.
- **D-g — calibrage** : « très forte » reste sous l'écart B−C (90) pour ne pas casser l'ordre de placement
  des rangs : SM1 garde son créneau même contre gymnase + jour + heure préférés ailleurs (20 < 85), mais
  bouge si les U13 n'ont aucune autre place (85 ≪ 1021), avec « SM1 : 2/3 conservés ».

### 8.11 Conditions de retour en validation

Redemander avant d'implémenter si : un point D-a → D-g est tranché autrement ; l'empilement réaliste
mesuré sur les données BCCL dépasse 90 ; la purge Q8bis devait toucher une période déjà commencée ; un
verrou type `ClubGenerationLock` s'avérait nécessaire au transfert ; une migration devait reformater des
doléances existantes ; il fallait étendre la liste des axes §7.1.
