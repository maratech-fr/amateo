# Modèle de données : `CalendarEntry`

> La taxonomie à 2 `kind`, l'entité `CalendarEntry`, la réutilisation de l'existant (contrainte
> datée, overlay) et les deux lectures distinctes (cockpit vs projection) qui portent le cockpit
> temporel. Découpé mécaniquement de `accueil-cockpit-temporel.md` §9ter (plafond de 800 lignes,
> `DocSizeCeilingTest`) — contenu déplacé VERBATIM, non réédité.

Last verified @ 2026-10-11 (découpe mécanique depuis `accueil-cockpit-temporel.md` §9ter —
la dernière vérification de fond de ce contenu est celle portée par le stamp de ce fichier avant
le déplacement, P2-63 PR 5 : modèle FAIT/GENÈSE, overlay propriétaire de sa grille, `OrphanPinGuard`
— rien de ce qui suit n'a été réédité par cette passe).

---

En creusant, la taxonomie à 3 objets (§4 de
[`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md)) **se réduit en fait à 2 sur le plan
données** — et surtout, **on réutilise l'existant au lieu d'ajouter des tables.**

## a. Deux `kind`, pas trois

- **`event`** — un **marqueur** sur le calendrier (AG, tournoi, stage). Informatif. Peut être
  marqué **`isDisruptive`** (« pas d'entraînement ce jour »).
- **`period`** — une **fenêtre qui altère le plan** (fermeture de salle, vacances, coupure,
  mutualisation). Porte des **contraintes datées** + un **plan secondaire** (overlay).

> **« Signaler une indispo » n'est pas un 3ᵉ type : c'est un raccourci pour créer une `period`**
> dont la contrainte « gym X fermé » est pré-remplie. De même, un événement `isDisruptive`
> devient/produit une mini-période « pas d'entraînement ce jour ». Fermeture / vacances / coupure
> / mutualisation = un `periodType`, pas des entités séparées.

## b. L'entité

```
CalendarEntry
  id                uuid
  clubId, seasonId
  kind              event | period
  title             "AG du club" · "Vacances Toussaint" · "Gym Barros fermé"
  startDate, endDate            -- un jour : endDate == startDate
  -- event
  isDisruptive      bool         -- bloque les entraînements ce jour
  -- period
  periodType        closure | holiday | cutoff | mutualisation | custom
  schoolHolidayId   uuid?        -- si dérivée d'une période de vacances (zone du club)
  status            proposed | active | ignored   -- « proposed » = vacances suggérées par le radar
  parentEntryId     uuid?        -- semaine ENFANT d'une période mère
  createdBy, createdAt, updatedAt
```

> **L'entrée ne porte pas de pointeur d'overlay.** Le plan secondaire est un `SchedulePlan` ancré
> à la `CalendarEntry`, et c'est **lui** qui pointe sa version (`chosenScheduleId`) — une seule
> maison pour « quelle version est active ». Lecture : `SchedulePlanProvisioner::chosenOfPeriodPlan`,
> ou `chosenByPeriodPlans` pour résoudre N entrées en une requête. Un plan de période qui ne pointe
> rien = **aucun overlay applicable**, pas un overlay vide.

## c. La réutilisation maligne (≈ zéro nouvelle table à part `CalendarEntry`)

- **Contrainte datée = la `Constraint` existante + un FK nullable `calendarEntryId`.**
  Une contrainte est soit **permanente** (`calendarEntryId = null`, le plan de base), soit
  **de période** (`calendarEntryId` renseigné). Une « fermeture de salle » = une `Constraint`
  `family=FACILITY` rattachée à l'entrée. **On ne réinvente pas les contraintes.**
  **Deux natures de datées (modèle FAIT/GENÈSE)** : le **FAIT**
  décrit l'incident et pend à la **mère** — il s'impose à toutes ses semaines ; la **GENÈSE**
  répond aux doléances d'UNE semaine et pend à l'entrée-**enfant** — elle n'existe que pour ce
  plan (« chaque plan est indépendant ; si je voulais les mêmes règles j'aurais couvert la zone
  d'un seul plan »). La lecture est l'**union** : un plan lit ses genèses + les faits de sa mère
  (`CalendarEntry::datedConstraintSourceIds()` — `[id]` racine, `[id, parentEntryId]` enfant),
  côté payload solveur, gate pré-solve ET radar. Le wizard d'une semaine **crée en genèse**
  (l'entrée du plan) et liste les deux : genèses éditables, **faits en lecture seule** badgés
  « Toutes les semaines de {mère} » (un fait se modifie à sa source — l'incident — jamais depuis
  une semaine, qui muterait ses sœurs en silence). Une datée **ne se décoche pas** par plan
  (422 dans les deux sens : poser un override sur une datée, ou dater une contrainte qui a des
  overrides) — elle se modifie ou se supprime.
- **Overlay = le `Schedule` existant + un lien vers la `CalendarEntry` + la fenêtre.** Ses slots
  sont des `ScheduleSlotTemplate` bornés à la fenêtre. **On ne réinvente pas le planning.**
- **Pas de table `schedule_slot_occurrences` per-date au départ.** L'override se fait au grain
  **période** (la fenêtre bascule sur l'overlay). ⚠ Le grain fin « juste ce mardi-là est annulé »
  **n'existe pas** : `ManualEditController` ne porte plus de route one-time et le contrat
  backend⇄engine ne transporte plus de verrou temporaire — une vraie table d'occurrences éparses
  repartirait de zéro, ne s'ajoute **que si** le besoin fin le justifie.

## d. Deux lectures distinctes — ne pas les confondre

**Le calendrier cockpit ne dessine QUE les `CalendarEntry`** (events, jalons de period, vacances).
Il **ne projette pas** la semaine type ; un jour sans entrée est **vide** (la base tourne,
implicite). C'est une **couche d'exceptions**, pas une grille de séances.

**La projection ne sert qu'ailleurs** — quand on a besoin des **vraies séances d'une date**
(ouvrir l'atelier overlay d'une période, un export PDF daté, un « détail du jour »). Là, pour
une date `d` :
1. `d` dans la fenêtre d'une `period` **active** avec overlay ? → **les slots de l'overlay**.
2. Sinon → **projeter** la semaine type : les `ScheduleSlotTemplate` du plan principal dont le
   `dayOfWeek` correspond à `d`, contraintes **permanentes** (`calendarEntryId = null`).

Supprimer une `period` → son overlay + ses contraintes datées partent → l'étape 1 ne matche plus
→ la base **re-projette** naturellement (cohérent avec « overlay supprimable, principal non »).

> **La distinction** : le **cockpit** répond à « qu'est-ce qui sort de l'ordinaire ? » (les
> entrées). La **projection** répond à « qu'y a-t-il concrètement le mardi 6 mai ? » (à la
> demande, jamais matérialisée d'avance).

## e. L'arbitrage « additive vs remplaçante »

Ni l'une ni l'autre : **la période est PROPRIÉTAIRE.**

- **Créneaux** : la période possède sa grille — copie du modèle de saison prise à la naissance du
  plan (ancre `schedulePlanId`), **jamais unie** au socle. Le build d'overlay ne lit que les
  créneaux du plan. Détail des réglages par gymnase (`VenuePeriodOverride`, indisponibilité
  informative) : [`accueil-cockpit-temporel.md`](accueil-cockpit-temporel.md) §6bis, ligne
  Gymnases.
- **Contraintes permanentes** : c'est là, et seulement là, que `periodType` porte encore une
  sémantique d'héritage. `closure` → toutes gardées (le gestionnaire décoche) ; `holiday` →
  seul le scope CLUB reste hérité par défaut (TEAM/COACH/FACILITY désactivées, décision
  fondateur 2026-10-10, `PeriodConstraintSelector::selectForPeriodPlan`). Diff épars
  `ConstraintPeriodOverride` : une ligne n'existe que pour une **déviation** du défaut.
- **Contraintes datées** : portées par la `CalendarEntry` — le **fait** (« Barros fermé ») par
  la mère, la **genèse** d'une semaine par son entrée-enfant (modèle FAIT/GENÈSE, §c) ; jamais
  décochables par plan.
- `cutoff` et `mutualisation` ne portent pas de plan de période.

Corollaire : un épinglage HARD qui ne retombe sur aucun créneau de la grille de la période
**bloque la génération** (422 nommant le gymnase, le jour et l'équipe, `OrphanPinGuard`), sauf sur
un gymnase désactivé ou effectivement fermé-total — dans un modèle additif il aurait
silencieusement retrouvé un créneau de saison.
