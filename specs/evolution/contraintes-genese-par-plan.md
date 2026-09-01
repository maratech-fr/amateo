# Contraintes de genèse par plan — cadrage ouvert (P2-59)

> Détail de la ligne roadmap **P2-59**. Ouvert le 2026-09-01, pendant l'exercice solveur de la
> reprise du 17 août (programme [`plannings-bccl-2026-08-31.md`](plannings-bccl-2026-08-31.md)).
> Statut : **arbitré le 2026-09-01** (faits = incidents seulement ; zéro migration, reset+fixtures ;
> pas de décochage des datées ; racine inchangée) — **PR-1 backend LIVRÉE** (lecture union
> genèses∪faits, garde 422 sur le décochage d'une datée, les 3 genèses du 17 au seed pendues à
> l'enfant). Reste PR-2 frontend (création→semaine, liste faits badgés lecture-seule + genèses
> éditables) puis graduation en courantes.

## 1. Le constat (vérifié au code le 2026-09-01)

- Toute contrainte datée s'accroche à la **MÈRE** de la période :
  `CalendarEntry::datedConstraintSourceId()` retourne `parentEntryId ?? id`
  (`backend/src/Entity/CalendarEntry.php:246`, décision P2-5 E1 — « le venue_closed décrit
  l'incident, pas la réponse »). Le wizard le MIROITE : créer une contrainte depuis l'écran
  Contraintes d'une SEMAINE l'attache en réalité à sa mère
  (`frontend/src/features/wizard/steps/ConstraintsStep.tsx:96-101`, décision D5/P2-22 ;
  même miroir `RecapStep.tsx:116-120`).
- Conséquence : deux semaines de reprise sous la même mère (« Vacances d'été ») **partagent
  toutes leurs contraintes datées**. Impossible d'en poser une sur UNE seule semaine.
- ⚠ **Bug latent** : le décochage par plan (`ConstraintPeriodOverride`) d'une datée est
  **ignoré du sélecteur** — la carte d'overrides ne filtre que les permanentes
  (`backend/src/Service/PeriodConstraintSelector.php:113-118`) ; la boucle qui garde les datées
  (`:124-183`) ne la consulte jamais. La case décochée à l'écran ment.

## 2. Le modèle du fondateur (verbatim, 2026-09-01)

> « Dans ma tête un plan normalement est indépendant, il peut avoir des contraintes liées à sa
> genèse. Plan type overlay répond à une indisponibilité mais on veut qu'il ressemble le plus
> possible au plan de saison pour ne pas casser la routine. Pour les vacances c'est un traitement
> par semaine qui répond à des doléances. Mais chaque plan est indépendant — si je voulais les
> mêmes règles j'aurais décidé de couvrir la zone directement. »

Exemple concret : « je peux avoir un type d'indisponibilité la semaine 1 car je suis en vacances
et je coache que la semaine 2 ».

## 3. Le besoin reformulé (à valider)

Les contraintes d'une période sont de **deux natures** :

| nature | exemple | attachement | portée |
|---|---|---|---|
| **FAIT** de la période | « gymnase Matéo fermé (travaux) » | la mère (l'incident) | toutes ses semaines — modèle actuel, reste juste |
| **GENÈSE** d'un plan | « Nico indispo lundi/vendredi CETTE semaine » | le plan/la semaine | ce plan seul — n'existe pas aujourd'hui |

Créer une contrainte depuis le wizard d'une semaine doit l'attacher à **la semaine**. Le solveur
d'un plan lit **ses règles propres + les faits de sa mère**. Une semaine sœur ne voit jamais les
règles d'une autre. Vouloir une règle commune = couvrir la zone d'un seul plan.

## 4. Questions en attente d'arbitrage

1. Reste-t-il un écran pour poser un FAIT sur la mère, ou les faits ne naissent-ils que des
   incidents (`venue_closed`) comme aujourd'hui ? *(penchant orchestrateur : aucun nouvel écran)*
2. Les datées EXISTANTES (toutes sur mères) restent lues comme faits — lecture élargie
   mère + semaine, aucune migration. OK ?
3. Le décochage par plan d'une datée : inutile (chaque semaine n'a que les siennes) ou faut-il
   pouvoir décocher un FAIT de la mère sur une semaine ? *(penchant : inutile — un fait est un
   fait ; s'il ne vaut pas pour une semaine, c'est l'incident qu'on ajuste)*
4. Overlay non découpé : entrée racine = elle-même, inchangé. OK ?

## 5. Ce qui attend ce chantier

Les 2 contraintes construites pendant l'exercice reprise-17 (validées par le fondateur, PAS
encore seedées) : « Séniors masculins mutualisés · pas avant 20:30 » (TEAM×2, TIME, HARD,
`minStartTime 20:30`) et « Nicolas Barilleau · indispo lundi, vendredi » (COACH,
COACH_AVAILABILITY, HARD, `unavailableDays [1,5]`) — à attacher à la semaine du 17 dès que le
modèle de genèse existe. Le reste du lot seed (réservations fanion, capacités occupant-unique,
décochages de permanentes) est livré sans attendre.

## 6. Chemins écartés pendant l'exploration (pour ne pas les re-proposer)

- **Mère + décochage sur la semaine sœur** : mort-né — le décochage des datées est ignoré du
  sélecteur (§1), et même corrigé (« D′ ») ce serait un pansement contraire au modèle
  d'indépendance des plans.
- **Une mère par semaine de reprise** : casse l'ancrage vacances scolaires (mère unique ↔ pas de
  doublon « Vacances d'été » au cockpit).
- **Permanent + décochage partout ailleurs** : contamine la saison.
