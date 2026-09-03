# Accueil « cockpit temporel » — mise au clair (préliminaire calendriers secondaires)

Last verified @ 2026-09-03 (rotation fraîcheur, `documentation-update`). Re-confronté au code : les
symboles cités du modèle FAIT/GENÈSE existent toujours — `CalendarEntry::datedConstraintSourceIds()`
(`backend/src/Entity/CalendarEntry.php:253`), `ConstraintsStep.tsx`
(`frontend/src/features/wizard/steps/`), `ConstraintPeriodOverrideStateProcessor` et
`ConstraintStateProcessor` (`backend/src/State/Processor/`) ✓. Reste du fichier non re-vérifié cette
passe — historique : `git log -p --follow` ce fichier.

> **Statut** : **approche arrêtée** (décisions tranchées §9) — **livrée** ; cf. [`etat-des-lieux.md`](etat-des-lieux.md) §1.2.
> **Pas un plan** — pas de tâches, pas d'effort chiffré ; l'exécution se planifiera palier par palier (§8).
> **Nature** : ce document fixe une **idée claire et maligne d'UX + d'architecture** pour
> remplacer l'écran d'accueil, et pose la fondation des **calendriers secondaires**.
> **Statut** : livré (cf. [`etat-des-lieux.md`](etat-des-lieux.md) §1.2) ; les restes ouverts sont en roadmap (**P3-3**, **P3-2**, **P3-13**). **Vision d'origine** : `initiales/ClubScheduler_v3.md` §3.5, §3.6, §8.
> **Ce doc challenge la vision d'origine** là où elle est trop lourde (voir §3).
> **Modèle métier des 3 types de planning** (socle / overlay / reprise — déclenchement,
> manipulation, règle « semaine = unité hors socle ») : [`types-de-planning.md`](types-de-planning.md).

---

## 1. Le problème

Aujourd'hui `/` = `PlanningPage` : l'accueil **est** le planning hebdomadaire. C'est le
seul modèle que l'appli connaît : **une semaine type**, répétée à l'infini. Il n'y a ni
dates réelles, ni vacances, ni « le gymnase est fermé la semaine du 4 mai », ni plan
alternatif. Le gestionnaire n'a aucun endroit pour **voir venir** et **anticiper**.

Besoin exprimé : l'accueil devient un **cockpit** à 3 zones —
1. un **bandeau** qui renvoie à la semaine type (le planning de base) ;
2. un **calendrier** (dates réelles) pour voir la vie de la saison ;
3. un **panneau radar** des prochains événements qui demandent attention (vacances dans un
   mois, planning à (re)générer, événements du club).

Et surtout : cliquer une date → **créer un événement / signaler un souci** ; cliquer un souci
→ **créer un calendrier secondaire**. C'est le **travail préliminaire des calendriers
secondaires**.

---

## 1bis. Le cycle de vie de l'application (la boucle qu'on a validée)

```
  Création de compte
        │
        ▼
   ┌──────────────┐   générer → ajuster (work-loop)
   │    WIZARD     │◀───────────────┐
   │   (guidé)     │                │  (socle pas encore bon)
   └──────┬───────┘                 │
          │  GÉNÉRER  ──────────────┘
          ▼
   ┌───────────────────────────────────────────────────┐
   │   COCKPIT (accueil) — débloqué par la génération    │
   │   bandeau (socle) · calendrier d'exceptions · radar  │
   └──────┬───────────────────────────────┬──────────────┘
          │ clic date / « Adapter »         │ « Modifier » le socle
          ▼                                 ▼
   ┌──────────────────────┐      ┌──────────────────────────────┐
   │  WIZARD mode PÉRIODE   │      │  WIZARD mode LIBRE            │
   │  → overlay (2ndaire)   │      │  ⚠ 1er 2ndaire = fige le socle│
   │  structure verrouillée │      │  ⚠ modifier = détruit les 2nd │
   └──────────┬───────────┘      └──────────────┬───────────────┘
              │ valider                          │ valider
              ▼                                  ▼
        ┌────────────────────────────────────────────┐
        │   CONSULTATION (grille R/O — principal &     │
        │   secondaires) ──「 Accueil 」→ COCKPIT       │
        └────────────────────────────────────────────┘
```

> **La boucle est validée** : un compte → un socle (wizard) → génération → cockpit → choix de la
> version qui fait foi → la vie de la
> saison se joue en **exceptions / overlays**, jamais en retouchant la base. On ne quitte jamais
> les **2 familles d'écrans** (consultation / wizard).

**À faire (doc)** : ce **cycle de vie de l'application** doit être **documenté dans la doc
adéquate** — `docs/project-map.md` (section parcours) ou une doc dédiée (`docs/technique/app-
lifecycle.md`). Il **dépasse cette spec** : il structure toute l'appli (auth → onboarding →
validation → cockpit → périodes). À porter au moment de l'implémentation.

## 2. Le vrai enjeu : un glissement de modèle mental

Le cockpit n'est pas un écran de plus. Il matérialise un **changement de modèle** :

> **De** « une semaine type » **vers** « une **semaine type de base** + une **timeline
> éparse d'exceptions**, chacune portant éventuellement un **plan secondaire borné** ».

- La **semaine type** reste la **source de vérité** (le planning principal actuel), **derrière
  le bandeau** — pas redessinée sur le calendrier.
- Le **calendrier** est la **couche d'exceptions** sur des dates réelles : il montre **ce qui
  sort de l'ordinaire** (événements, indispos, périodes, vacances), **pas** les séances de base.
  Un jour vide = la base tourne normalement.
- Les **vraies séances d'une date** se **projettent à la demande** (jamais stockées) — voir §9ter.
- Les **exceptions** (événement, indispo salle, vacances) sont des **annotations rares** sur
  des dates précises.
- Un **calendrier secondaire** = un **plan adapté, borné à une période**, qui **surcharge**
  la base uniquement sur ces dates.

Tout l'écran d'accueil est la **vue timeline** de ce modèle.

---

## 2bis. Invariant : le plan principal est un socle FIGÉ

> **Le plan principal (la semaine type) est le socle.** Tous les calendriers secondaires
> (overlays) sont **construits par-dessus** : ils héritent de sa structure (équipes / salles /
> coachs) et de ses contraintes permanentes.

**Conséquence : modifier le socle invalide ce qui repose dessus.** Les overlays ont été calculés
contre le socle ; si on le change, ils ne valent plus.

**Mais le coût est PROGRESSIF — pas un gel brutal à la validation :**
- **Tant qu'aucun calendrier secondaire n'existe** (typiquement en **début de saison** — les
  contraintes coach arrivent encore le 12 septembre), on **remanie le socle librement**, sans
  friction : **rien ne dépend encore de lui**. Le figer de force serait absurde.
- **Dès que des secondaires existent**, « Modifier » devient **coûteux** : ça **supprime les
  calendriers secondaires concernés** → **confirmation proportionnée**, qui les **nomme**. Zéro
  concerné = zéro confirmation ; sinon avertissement à la hauteur.

> **Portée bornée — ce n'est PAS « tous les secondaires »** (ADR-0002 inv. 14, amendé fondateur
> 2026-07-24) : seules partent les périodes **entièrement à venir**. Le pivot est la date de
> **début** — « rien du passé, rien de ce qui est en cours ». Une période déjà commencée
> **survit** à la réouverture du socle. Sont concernées les périodes qui **portent un plan**,
> **validé ou non** (une période « Adaptée » mais jamais générée compte). Référence normative :
> [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) §6.

> **Le gel est donc DE FACTO, pas une serrure.** On ne verrouille pas la base par un état ; on
> **arrête d'y toucher parce que ça coûterait les overlays**. En routine (mars, saison lancée),
> on n'y touche plus — non pas parce que c'est interdit, mais parce que **ce n'est pas logique**
> et que le coût est réel. En septembre, on la triture sans scrupule.

**Deux avertissements symétriques matérialisent ce coût — les seuls garde-fous nécessaires :**
1. **À la création du PREMIER calendrier secondaire** → ⚠ « Ceci **fige** ton planning principal :
   à partir de maintenant, le modifier supprimera tes calendriers secondaires. Ton socle est-il
   prêt ? » — c'est le **moment de bascule** où la base devient **porteuse**. On le **rend visible**
   (sinon la bascule serait silencieuse).
2. **À la modification du socle quand des secondaires à venir existent** → ⚠ « Ceci **supprime**
   ces calendriers secondaires (à refaire) », la liste à l'appui. Ceux déjà commencés n'y sont pas.

Le premier **annonce** le gel au bon moment ; le second **protège** contre la perte. C'est tout —
pas d'état « verrouillé » à gérer, juste ces deux confirmations.

> **Ex.** 8 sept, **0 overlay** : le coach U15 se libère le jeudi → « Modifier » le socle, **aucune
> friction**, on régénère. 3 mars, **4 overlays** (Toussaint, Noël, 2 fermetures) : « Modifier »
> avertit « **ceci supprime 4 calendriers secondaires** » → en pratique on n'y touche plus.

C'est cohérent avec la vraie vie du club : la semaine type se **stabilise** en début de saison
(quelques itérations), puis tient toute l'année ; ce sont les **exceptions** (vacances,
fermetures, événements) qui bougent ensuite — plus la base.

**Ça réutilise le cycle de vie existant** (`planning-lifecycle-validated.md`) : **« Modifier » =
`reopen`**. La seule chose à ajouter : la réouverture **liste et supprime les plans de période
non commencés** — **silencieuse s'il n'y en a pas**, avec confirmation sévère sinon (409
`overlays_exist` puis rejeu avec `confirmDeleteOverlays: true`). La destruction va **de bout en
bout** : versions, plan, et tous les réglages ancrés au plan (grille copiée, réservations, modes
gymnase, overrides d'équipes et de contraintes) — **l'entrée de calendrier survit** et retombe
« à traiter » au radar. Le work-loop « générer → ajuster → régénérer » vit surtout **avant** que
des secondaires existent.

## 2ter. Le socle débloque le cockpit (le plancher d'abord)

> **Le plan principal est le plancher.** Tant qu'il n'a **rien généré**, le cockpit n'a **aucun
> sens** — à quoi bon des périodes, des overlays, un calendrier d'exceptions, si la base n'existe
> pas ? → **le reste est verrouillé.** Et tant qu'aucune version n'est **choisie**, ce qui se
> **bâtit sur** ce socle (matchs, calendriers secondaires) reste bloqué.

D'où le parcours, en **deux seuils** (ADR-0002 — `/api/me` les expose ensemble dans `seasonPlan`) :
- **Compte créé / jamais généré → l'accueil EST le wizard.** C'est pour ça qu'on part **direct
  dans le wizard** à l'inscription : il est la **base de connaissance et de travail**. On y reste
  jusqu'à ce que le plan de saison porte **au moins une version terminée**
  (`seasonPlan.hasFinishedVersion`, inv. 8/16).
- **Seuil 1 — le plan a généré → l'accueil devient le cockpit.** Le critère est **dérivé** des
  versions du plan et **indépendant du pointeur** : rouvrir un planning ne re-verrouille donc
  **jamais** le cockpit et ne renvoie personne à l'onboarding.
- **Seuil 2 — le plan pointe une version** (`seasonPlan.chosenScheduleId ≠ null`, inv. 13) → les
  fonctions qui se **bâtissent sur le calendrier de base** se débloquent : **matchs** et
  **calendriers secondaires** (`SocleGuard` → **409** tant qu'aucune version n'est choisie).
  Créer des événements, signaler des indispos et le radar relèvent du seuil 1.

**Choisir une version est le jalon qui ouvre ce qui se construit dessus.** « Si le socle n'est pas
bon, ça ne sert à rien de faire le reste. » Le flag legacy `club.onboardingCompleted` n'est plus lu
pour le routage.

> **Ex.** Club inscrit le 1ᵉʳ sept : il tombe **direct sur le wizard**, pas sur un cockpit vide et
> inutile. Il saisit équipes/salles/coachs, génère, ajuste, **valide** → l'accueil bascule en
> **cockpit** et le radar affiche « Vacances Toussaint dans 55 j ». Tant qu'il n'a pas validé,
> « créer un événement » ou « adapter une période » **n'existent pas** dans son UI.

## 3. La décision d'architecture maligne (et le challenge de la vision d'origine)

La vision d'origine (v3 §3.5) prévoit `schedule_slot_occurrences` : **matérialiser chaque
occurrence** de chaque créneau sur une **fenêtre glissante J+14**. C'est la brique 🔴 dont
« tout dépend » dans la roadmap.

**Je challenge ça.** Matérialiser toutes les occurrences, c'est écrire des **milliers de
lignes** quasi identiques au template (40 semaines × N créneaux), qu'il faut ensuite
**garder synchronisées** avec la base à chaque régénération. Coût énorme, valeur quasi nulle
pour les 99 % de créneaux qui ne dérogent jamais.

**Proposition — occurrences éparses (deltas), pas matérialisation :**

> On ne stocke **une occurrence que là où la réalité diverge du template** : créneau annulé,
> déplacé, salle changée, ou appartenant à une **période** (plan secondaire). Partout ailleurs,
> le calendrier **projette** le template à la volée. **La matérialisation est paresseuse, et
> pilotée par les exceptions** — pas une fenêtre J+14 qui matérialise tout d'avance.

> ✅ **Décision structurante actée : modèle delta / override.** La matérialisation J+14 de la
> vision d'origine est **abandonnée**. Le calendrier est une **projection** ; une occurrence
> n'existe en base que comme **override** d'une date qui déroge. C'est cette décision qui
> débloque toute la §2 en incréments.

Conséquence : **le cockpit + le calendrier réel + les événements sont livrables SANS
construire la grosse machinerie templates→occurrences d'abord.** La matérialisation
n'arrive que quand/où une exception l'exige. Ça transforme un monolithe 🔴 en incréments.

> **Ex.** 40 semaines × 60 créneaux = **2 400 occurrences** à matérialiser puis resynchroniser à
> chaque régénération, pour un club qui ne déroge jamais. En delta : **0 ligne** tant que rien ne
> bouge ; une fermeture de gym la semaine du 4 mai n'écrit **que** les quelques overrides de cette
> fenêtre. Le calendrier de mars, avril, juin… reste une **projection** gratuite du template.

---

### 3bis. C'est quoi une « occurrence » ? (et pourquoi je propose de s'en passer)

- Un **slot_template** = un créneau **récurrent** du planning de base : « U13M1, **mardi**
  18h-19h30, gym Barros, coach Cyril, **toutes les semaines** ». **Pas de date.** C'est ce que
  le solveur produit et ce que tu vois dans la grille hebdo aujourd'hui.
- Une **occurrence** (`schedule_slot_occurrences`) = **une instance concrète de ce créneau à
  une date réelle** : « U13M1, le **mardi 6 mai 2026**, 18h-19h30, statut = prévu ». **Une ligne
  par (créneau × date réelle).**
- **À quoi ça sert ?** À gérer les cas où **la réalité diverge du template un jour précis** :
  « le 6 mai c'est **annulé** », « le 13 mai **déplacé** à 19h », « le 20 mai **gym changé** »,
  « coach **remplacé** ». Impossible à exprimer sur un template hebdo (ça annulerait **tous** les
  mardis). Il faut un objet **par date** → l'occurrence, avec son `status`
  (scheduled/cancelled/moved/venue_changed/coach_replaced/added/merged).
- **La vision d'origine** : matérialiser **toutes** les occurrences sur une fenêtre glissante
  **J+14** (générer d'avance les 2 prochaines semaines de dates concrètes).
- **Le hic** : 99 % des occurrences sont **identiques** au template (aucune divergence) → des
  milliers de lignes redondantes à **resynchroniser** à chaque régénération de la base.
- **Ma proposition (modèle delta)** : ne **rien** matérialiser d'avance. **Projeter** le
  template sur les dates à l'affichage, et ne créer une occurrence **que quand une date déroge**.
  Une occurrence devient alors un **override** (un delta), pas une copie. → « occurrences éparses ».

## 4. Taxonomie : 3 objets, une seule entité

Pour éviter 4 tables (`period_templates`, `period_template_slots`, `period_assignments`,
`period_coach_responses`) dès le départ, on unifie autour d'une **entrée de calendrier**
(`CalendarEntry`) avec un `kind` et une **plage de dates** :

| Objet | `kind` | Impact planning | Porte un plan secondaire ? |
|---|---|---|---|
| **Événement club** | `event` | **Au choix du gestionnaire** : informatif (défaut) **ou** perturbant | Non (mais un événement perturbant peut mener à une adaptation) |
| **Indisponibilité datée** | `period` + `periodType=closure` | Oui, sur la fenêtre : la ressource est indispo | Optionnel (le plan naît du geste « Adapter ») |
| **Période** | `period` (`periodType` : vacances / coupure / mutualisation) | Oui, la période possède sa propre grille sur la fenêtre | Oui (son calendrier secondaire) |

> **Deux `kind`, pas trois** (`CalendarEntryKind` = `event` \| `period`) : « signaler une
> indispo » n'est pas un type d'objet, c'est un **raccourci de saisie** vers une `period`
> `closure` dont la contrainte datée est pré-remplie. Voir §9ter.a.

- **Un événement club** = un post-it daté (tournoi, AG, stage). **Informatif par défaut**
  (n'affecte pas la semaine type). Mais le gestionnaire peut le marquer **perturbant**
  (« pas d'entraînement ce jour » / la salle est prise) → il devient une mini-indisponibilité
  et alimente le radar. **C'est lui qui tranche, événement par événement.**
- **Une indisponibilité** = « gym Barros indispo la semaine du 4 mai » → une contrainte **datée**
  qui n'a de sens que sur cette fenêtre.
- **Une période** = une plage nommée avec **son propre plan** (le calendrier secondaire).

### 4bis. Vacances scolaires — dérivées du code FFBB, stockées en base

Décision : **on ne géocode rien au runtime.** Le `Club.ffbbClubCode` encode déjà le
**département** (ex. `…0069…` → 69 = Rhône). Le département → **zone scolaire** (A / B / C)
est une table fixe. Et le **calendrier des vacances est officiel, publié ~1 an à l'avance**
(open data Éducation nationale).

Donc :
1. La **zone** du club est **dérivée une fois** du `ffbbClubCode` (département → zone) et
   stockée sur le club (le champ **`school_zone`** déjà prévu roadmap §8 — mais alimenté par
   le code FFBB, **pas** par une API Géo depuis l'adresse : plus simple, la donnée est déjà là).
2. Les **périodes de vacances** vivent en base (`school_holiday_periods` : zone · type
   [Toussaint/Noël/Hiver/Printemps/Été] · début · fin · année scolaire), **seedées une fois par
   an** depuis la source officielle (commande d'import, pas d'appel réseau au runtime).
3. Le cockpit lit simplement les vacances **de la zone du club** → les affiche sur le calendrier
   et les remonte au radar (« Vacances Toussaint dans 24 j »).

**Ça simplifie la roadmap §8** : la « dérivation fuseau/zone depuis l'adresse (API Géo) » 🔴
devient une **dérivation triviale depuis le code FFBB** 🟢.

Le clic « signaler un souci » crée une `period` `periodType=closure` (avec sa contrainte datée
pré-remplie) ; c'est ensuite le geste **« Adapter »** qui lui donne un plan, donc un calendrier
secondaire.

---

## 5. L'écran d'accueil (les 3 zones)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  BANDEAU · Planning principal — Validé                [ Ouvrir ▸ ] [ Modifier… ] │
│  (Ouvrir = grille en lecture seule · Modifier = rouvre le wizard, ⚠ détruit les secondaires) │
├──────────────────────────────────────────────┬────────────────────────────┤
│  CALENDRIER (mois entier · jour courant ⭕ · navigable) │  RADAR — à traiter │
│  (montre les ÉVÉNEMENTS, pas la semaine type)  │                             │
│   L   M   M   J   V   S   D                    │  ⏳ Vacances Toussaint       │
│  ..  ..  ..  ..  ..  ..  ..                     │     dans 24 j · pas de plan  │
│  ..  ⛔  ..  ..  ..  ..  ..   ⛔ Barros fermé   │     [ Générer le plan ]     │
│  🎉  ..  ..  ..  ..  ..  🏖   🎉 AG · 🏖 vac.   │  ⛔ Gym Barros — sem. du 4    │
│  ..  ..  ⭕  ..  ..  ..  ..   (aujourd'hui)     │     plan secondaire absent   │
│                                               │     [ Adapter la semaine ]  │
│   clic sur un jour → popover :                │  🎉 AG le 12 mai            │
│     • Événement club                          │                             │
│     • Signaler une indisponibilité            │  (le socle ne se « régénère »│
│     • (Créer une période)                     │   pas — il est figé, §2bis)  │
└──────────────────────────────────────────────┴────────────────────────────┘
```

**Bandeau** = l'état du plan principal **d'un coup d'œil** : **validé** · N diagnostics. ⚠ **Amendé
2026-08-01 (P4-39)** : le score du solveur, montré dans le croquis d'origine ci-dessus, ne s'affiche
plus nulle part — décision fondateur (« ça ne sert à rien pour le gestionnaire ») ; voir
`etat-des-lieux.md` §2.
- **« Ouvrir »** → l'**écran de consultation** (grille lecture seule) — **le même écran** qui
  sert aussi à consulter les calendriers secondaires (§6ter). Pas de zones d'édition ; les entités
  sont visibles, non modifiables.
- **« Modifier »** → rouvre le **wizard (mode libre)** pour changer le socle. **Libre tant qu'il
  n'y a pas encore de calendrier secondaire** (début de saison) ; sinon **destructeur** — ça
  supprime les secondaires **encore à venir** (les périodes déjà commencées survivent, §2bis) →
  **confirmation proportionnée** (cf. coût progressif §2bis). En routine (saison lancée), on n'y
  touche plus.

**Calendrier** = **la couche des événements / exceptions**, **PAS la semaine type** (elle est la
base, accessible derrière le bandeau — inutile de la redessiner). Il montre **uniquement ce qui
sort de l'ordinaire** : événements club, indispos, périodes, vacances. **Un jour vide = tout
roule comme la semaine type.** Clic sur un jour → **création rapide** (événement / indispo /
période). Le jour courant est **entouré**.

**Radar** = la **to-do du gestionnaire**, triée par urgence : ce qui approche (J-24/J-7/J-3)
et ce qui manque (plan de période non généré, planning modifié non régénéré). **Chaque item
a un CTA.** C'est la version généralisée des alertes J-14 de la vision d'origine (§8.2).

### 5.1 Le radar ne montre que l'AVENIR ACTIONNABLE (P3-13, retour terrain 2026-07-31)

Une to-do n'est pas un inventaire. Trois règles, décidées par le fondateur le 2026-08-01 :

- **Horizon des vacances : 30 jours** (`SCHOOL_HOLIDAY_HORIZON_DAYS`, réduit de 60 à 30 le
  2026-08-19 — décision fondateur B5 : une vacance n'apparaît au radar que 30 j avant son
  début). Sans lui, en été, la Toussaint et Noël s'affichaient : « c'est TROP loin pour que
  je m'en occupe de suite ». Les jours fériés avaient déjà le leur (30 j — les deux horizons
  sont désormais alignés). ⚠ L'horizon ne masque que les vacances **intactes** : dès qu'un
  plan existe, la période devient une carte « en cours » qui y échappe — cacher un travail
  commencé serait bien pire que le bruit corrigé.
- **Les semaines RÉVOLUES sont écartées** — de la couverture d'une période découpée
  (« 0/7 couvertes » alors que 3 étaient derrière), des semaines offertes à la création, et
  des semaines proposées à la sollicitation des coachs. « On gère l'avenir, pas le
  présent. » La règle vit en un seul endroit, `isActionableWeek` / `actionableWeeks`
  (`features/cockpit/lib/date.ts`), à côté de `periodAdjustWeeks` qui est déjà la source
  unique des semaines qu'une période offre — radar et campagne coachs la lisent tous deux,
  il ne peut donc pas y en avoir deux versions.
  ⚠ **RÉVOLUE, pas « commencée »** — le critère est `endDate >= today`, exactement le test
  que le radar applique déjà aux périodes (`e.endDate >= today`) : une seule notion de
  « c'est derrière », à deux échelles. Le premier jet lisait « la semaine n'a pas commencé »
  (`monday > today`) et la revue #344 l'a démonté : une fermeture démarrant le **mercredi**
  devenait implanifiable dès le lundi (sa puce « + créer » disparaissait, et la modale du
  jour ne reproduit ces puces que pour les vacances) ; une vacance démarrant un **samedi**
  ne pouvait plus faire l'objet d'aucune collecte dès le lundi suivant ; et une semaine
  rognée par un début de saison un mardi était déclarée commencée le lundi d'avant.
  Le lundi dit QUELLE semaine c'est (clé stable) ; la fin dit s'il reste à y faire.
  ⚠ **La règle vaut à TOUS ses sites** : la liste des semaines OFFERTES passe par
  `periodWeeksToAdjust` — radar, modale du jour et picker compris. Les avoir laissés
  diverger (revue #344 round 2) faisait cocher une semaine révolue au picker, dont la
  création produisait un plan de semaine que le radar filtrait ensuite partout : un
  artefact sans carte, sans puce et sans retour possible. Même critère au niveau
  **période** (`h.endDate >= today` sur les vacances) : une vacance commencée dont les
  jours restent devant garde son point d'entrée.
- **La carte de couverture est repliée par défaut** ; les autres gardent leur action
  visible. Arbitrage pris à l'implémentation : tout replier mettait **chaque** geste du
  radar à deux clics sans raccourcir ce qui est réellement long (les N puces de semaine).
  L'en-tête — titre, dates, compteur « x/y couvertes » — reste toujours lisible.

⚠ **L'horizon masque aussi les doléances** — `RadarCoachWishAction` n'est rendu nulle part
ailleurs dans l'application. Soulevé par la revue #344, **tranché par le fondateur le
2026-08-01** : « on ne les sollicite pas au-delà de 60 j, en général ça se fait 3 semaines
avant les vacances » — l'horizon vaut aujourd'hui **30 j** (B5, 2026-08-19), la décision de
ne pas créer de second point d'entrée n'en est pas changée. Le cas n'existe pas dans l'usage
réel, **aucun second point d'entrée n'est à créer** (décision fermée —
[`etat-des-lieux.md`](etat-des-lieux.md) §2).
Demeure un filet : une vacance qui porte **déjà** une campagne garde sa carte quelle que
soit sa distance, son badge « x à traiter » n'ayant pas d'autre surface — on ne fait jamais
disparaître un travail engagé. Pour la même raison, « Doléances » et « Solliciter les
coachs » restent **hors du repli** de la carte de couverture : un compteur dont la raison
d'être est d'être lu d'un coup d'œil ne peut pas vivre derrière un clic.

**Chargement** (P3-11) : tant que les plans, les versions, les campagnes, les impacts de
fermeture ou la zone scolaire sont en vol, le radar affiche un **squelette** (avec un texte
lu par les lecteurs d'écran : une région live annonce son contenu, pas son `aria-label`).
⚠ **Charger n'est pas échouer** — le squelette s'appuie sur `readLoading`, pas sur
« pas de donnée » : bâti sur le second, un premier chargement en échec restait « Chargement… »
pour toujours, l'écran affirmant qu'il travaillait alors qu'il avait renoncé. Une lecture
ratée le **dit** (« Impossible de charger les éléments à traiter »), et la lecture des
campagnes entre dans `isEmpty` puisque l'exemption d'horizon fait dépendre d'elle
l'existence d'une carte. Il restait nu — et un cadre
« À traiter » vide se lit comme « rien à faire ». Le squelette et « Rien à l'horizon. Tout
roule. » ne coexistent jamais : `isEmpty` exige que ces mêmes lectures soient résolues.

**Horloge de démo** (amorce de P4-16, côté front) : `shared/lib/clock.ts` est le point de
passage unique du « aujourd'hui » du front. En **dev uniquement**, `?today=2026-12-20` le
décale, ce qui permet de rejouer une situation datée — la valeur est vérifiée comme une
date RÉELLE, pas seulement dans sa forme (`2026-13-01` triait après toute date et vidait le
radar en affirmant « Tout roule ») — sans quoi ces règles ne seraient
observables qu'en attendant la bonne date. La lecture de l'URL est derrière
`import.meta.env.DEV` : le bundle de production ne contient aucun chemin capable de décaler
l'horloge. Le **serveur** garde l'heure réelle (P4-16 reste ouverte pour lui).

---

## 5bis. Interaction : cliquer une date (modale légère vs écran)

> **Règle : annoter = modale ; générer / travailler un plan = écran.**

- **Date vide** → petit **popover** : `[ Événement ]` `[ Indispo salle ]` `[ Période… ]`
  - **Événement / Indispo / Coupure** → **mini-formulaire dans le popover** (titre + **plage
    `Du … Jusqu'au …`** ; l'**Événement** ajoute le toggle informatif/perturbant, l'**Indispo**
    le gymnase) → enregistrer, on **reste sur le cockpit**. Le **jour cliqué n'est qu'un défaut**
    pour les deux bornes : début **et** fin sont éditables (`aujourd'hui ≤ début ≤ fin`). Geste de 2 secondes.
  - **Période…** → **navigue vers l'écran dédié** (atelier du calendrier secondaire).
- **Jour férié / vacances** → la modale affiche un **bandeau info** en tête (« Jour férié — … »
  pour un férié public ; « Vacances — … » pour des vacances scolaires). Les **vacances** portent
  en plus un **« Adapter »** directement dans la modale (même action que le radar : crée la période
  de vacances si absente puis ouvre le wizard en mode période ; « Voir le planning » si l'overlay
  existe déjà) — pas besoin de passer par le radar.
  - Si la période couvre **plusieurs semaines calendaires**, un **choix des SEGMENTS**
    (`WeekPickerDialog`) s'interpose avant le wizard : chaque **segment** coché devient une entrée
    **enfant** (`parentEntryId`) avec **son propre plan** (P2-5 E1, P2-41) ; une seule semaine →
    wizard direct. Même règle partout où le geste existe, pour ne pas offrir deux comportements au
    même geste. **Le SERVEUR et l'ÉCRAN sont alignés depuis P2-41 PR-C (2026-08-19)** :
    un segment est un bloc de semaines calendaires pleines et contiguës (lundi→dimanche, clamp
    saison), la semaine simple restant le segment de taille 1
    ([ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)). `WeekPickerDialog` propose des
    segments **précochés**, découpés aux ruptures GÉOMÉTRIQUES de l'offre (`segmentsFromOffer`,
    `lib/date.ts` — semaine d'entame/fin partielle de l'événement, discontinuité de l'offre :
    exclusion vacances P2-40 ou filtre temporel), avec deux gestes nommés : **scinder** (déplier un
    segment en semaines) et **fusionner** (assembler deux segments adjacents dans l'offre, y
    compris par-dessus une rupture — le serveur ne borne que contiguïté + enveloppe). Un segment
    multi-semaines porte une phrase pédagogique (présentation, pas décision) sur le sur-ferme du
    solveur si ses semaines diffèrent. La carte de couverture (radar, `DayDialog`) regroupe les
    créneaux par enfant (`groupCoverageSlots`) — une puce par segment plutôt qu'une par semaine ; la
    puce « + créer » d'une semaine manquante reste, elle, à la semaine. **Le libellé omet l'année
    quand la fenêtre est dans la saison affichée** (A2, 2026-08-19) : `segmentLabel` (`lib/date.ts`)
    reçoit la saison (`WeekPickerDialog`/`DayDialog` la transmettent) et n'écrit l'année que si la
    fenêtre déborde de la saison — ou que la saison est inconnue, pour ne pas désambiguïser à tort.
    Le nom SERVEUR du plan n'est pas touché, seul cet affichage. Filet CSS (`min-w-0` + `truncate`,
    texte complet en `title`) pour que le libellé long ne déborde plus de son item.
  - **La décision d'ouvrir ce picker (P2-36, 2026-08-18)** vit dans une seule fonction pure,
    `decideWeekAdapt` (`lib/useWeekAdapt.ts`) — le radar et le DayDialog la dupliquaient avec des
    entrées différentes, corriger un seul côté garantissait un écart, et le défaut qu'elle ferme :
    quand la condition tombait, l'écran **basculait en bloc sans un mot** (le serveur, lui,
    refusait déjà le 422 avec sa raison). Cinq branches NOMMÉES, deux issues : `single-week` et
    `already-split` (bloc direct, comportement conservé — une seule semaine calendaire, ou une
    mère déjà découpée dont la carte de couverture gouverne) ; `loading` (plans/plannings/enfants
    pas encore résolus — le picker s'**ouvre et le dit**, plutôt que de partir en bloc en
    silence) ; `block-generated` (le plan de bloc porte déjà ≥ 1 version — le picker **nomme** le
    fait, garde « Continuer d'un bloc », et — si ce bloc n'est **pas validé** — propose une
    découpe destructive confirmée qui **nomme sa portée** : N versions supprimées, réglages qui
    repartent de la saison ; un bloc **validé** n'offre pas ce bouton et renvoie vers
    Rouvrir→Supprimer ; le bouton est désactivé, avec sa raison, pendant une génération en vol) ;
    `weeks` (cas nominal, l'existant). Les **deux surfaces qui ne consultaient jamais ce picker**
    y passent désormais : « Ajuster »/« Adapter » une fermeture depuis la liste du jour
    (`DayDialog`) et la carte d'indisponibilité du gymnase du radar (`RadarPanel`) — leur chemin
    « entrée pas encore née » (matérialiser SEULEMENT à la confirmation, annuler ne laisse aucun
    fantôme) est généralisé de `PendingHoliday` à `PendingMother`, portant un `create()` fourni
    par la surface (vacance → `createHolidayPeriod`, indisponibilité de gymnase →
    `createVenueClosure`) — la carte du radar n'écrit **rien** tant que le gestionnaire n'a pas
    tranché. Écart déclaré : « Ajuster » (liste du jour) devient **asynchrone** — un POST
    `createPeriodPlan` idempotent avant de naviguer. Aucune règle serveur ne change (le 409
    `window_already_planned` de P2-38 et le refus 422 de découper une mère déjà générée restent
    ceux du serveur) — ce lot rend un **choix déjà permis** visible côté UI, il ne déplace aucune
    frontière métier.
  - **Le picker EXCLUT les semaines gouvernées par des vacances (P2-40, 2026-08-18).** Décision
    fondateur (3 cas validés sur exemples) : quand une **indisponibilité de gymnase** (`closure`)
    chevauche des vacances, `WeekPickerDialog` n'offre plus ces semaines-là — **exclues, pas
    grisées** — avec une ligne d'info renvoyant au planning de vacances (« Semaines du X au Y
    couvertes par [vacances] — le rappel vous attend dans son planning »). Deux fonctions pures
    dans `lib/date.ts` portent la règle : `holidayWindows` (union du feed vacances scolaires ∪ des
    entrées calendrier `holiday` non ignorées, clampée à la saison) et `closureWeeksOffer` — foyer
    UNIQUE de l'offre d'une fermeture, une semaine est exclue **ssi son lundi est offert par**
    `periodAdjustWeeks(fenêtre vacances, "holiday")` (la règle dropFirst Ven/Sam/Dim continue de
    jouer : des vacances démarrant vendredi n'excluent pas leur semaine d'entame, qui reste offerte
    par la fermeture). `decideWeekAdapt` (P2-36) gagne le fait `holidayCovered` : dès qu'une
    exclusion existe, le picker s'ouvre **toujours** (jamais `single-week`/`already-split` en
    bloc direct) et **le chemin « adapter d'un bloc » disparaît** — un plan de bloc gouvernerait
    la fenêtre des vacances, ce que P2-38 refuse par ailleurs. Sans chevauchement : comportement
    strictement inchangé. Cas **100 % sous vacances** (aucune semaine offerte) : ligne d'info
    seule ; sur le chemin `pendingMother` (l'indispo n'est pas encore en base), un bouton
    **« Consigner l'indisponibilité »** crée le FAIT sans plan ni navigation — nécessaire, sinon
    le rappel promis par la ligne d'info n'existerait nulle part ; une entrée **déjà en base**
    n'a rien à consigner, le bouton n'apparaît pas. **Écart assumé** (§2 de `etat-des-lieux.md`) :
    la règle vit côté FRONT, sur les données SERVIES (`useSchoolHolidays` + `useCalendarEntries`)
    — c'est une règle d'**OFFRE** de présentation, pas un miroir d'un calcul backend (aucun calcul
    serveur « semaines offertes » n'existe à mirorer, `FrontRederivationRegistryTest` reste vert).
    Par API directe, une semaine peut donc encore naître sous des vacances SANS plan — le filet
    reste la garde P2-38 (409 `window_already_planned`, dans les deux sens) dès qu'un PLAN existe ;
    la garde serveur n'est **pas** étendue (sa doctrine : « on ne borne que le PLAN, jamais le
    FAIT »).
  - **La carte de couverture d'une fermeture prolonge la même exclusion (A3, 2026-08-19).**
    P2-40 ne bornait que le PICKER ; la carte de couverture du radar/`DayDialog`
    (`motherWeekSlots`, `RadarPanel.tsx`) comptait encore TOUTES les semaines de la fenêtre, y
    compris celles déjà exclues de l'offre — un « 0/7 couvertes » qui incluait 3 semaines que le
    gestionnaire ne pouvait de toute façon pas cocher. Elle lit désormais le même foyer UNIQUE
    (`offerFor`, exposé par `useWeekAdapt`) : le dénominateur ne porte plus que les semaines
    **AJUSTABLES** (« 0/4 »), et une semaine gouvernée par des vacances s'affiche **grisée** avec
    sa raison (« sem. du X · gérée par [vacances] »), jamais cliquable ; un enfant déjà créé sur
    cette semaine reste, lui, ajustable. La carte elle-même ne s'affiche plus pour une semaine
    sous vacances non couverte — ce n'est pas un travail restant, le rappel vit dans le planning
    des vacances (§5.1). Aucune re-dérivation : `motherWeekSlots` lit la sortie d'`offerFor`,
    il ne recalcule rien.
  - **L'été s'adapte comme les autres vacances** (E2, 2026-07-18) : l'exclusion `ete` a été
    **levée** (`isAdaptableHoliday` supprimé) et les dates sont **clampées à la saison**. Seul cas
    restant sans « Adapter » : une fenêtre **entièrement hors** de la saison de travail — la
    modale l'**explique** au lieu d'afficher un bouton mort.
- **Date avec entrée(s)** → le popover **liste** ce qui est là ; chaque entrée porte ses actions
  (voir / éditer / supprimer). Une indispo/période porte un **« Adapter → »** qui ouvre
  l'**écran dédié**.
- **L'écran dédié « calendrier secondaire » = le wizard réutilisé en « mode période »**
  (voir §6bis). Pas un nouvel écran à apprendre : **les mêmes 6 étapes**, mais le roster/les
  gymnases restent **hérités** (non ré-éditables comme entités) — on les **surcharge pour la
  fenêtre** (équipe on/off + séances) via un DIFF ancré au **plan** de la période, la grille de
  gymnases étant, elle, **copiée puis possédée** par la période (#8), en plus des
  **contraintes + la génération**.
  → **Une modale serait trop à l'étroit ; et surtout, réutiliser le wizard = zéro réapprentissage.**

Le geste unique « cliquer une date » ouvre donc **le bon niveau selon le besoin** : une note
rapide (modale) ou l'atelier de génération (le wizard en mode période). Pas deux entrées à retenir.

**La PRÉVENTION du même refus (2026-08-22).** Le refus ci-dessous reste le filet ; il ne devrait
plus surprendre. `WeekPickerDialog` ne propose plus une semaine dont la création serait refusée :
le serveur SERT le verdict (`GET /api/planned-windows`, `backend-inventory.md`), l'écran l'affiche.
- **Ce que voit le gestionnaire** : les semaines gouvernées par un autre plan QUITTENT les cases à
  cocher et sont NOMMÉES au-dessus de la liste — un `WindowAlreadyPlannedNotice` par fenêtre,
  portant la phrase SERVIE telle quelle et le raccourci « Ouvrir le planning en place ». Une ligne
  désactivée aurait obligé le front à inventer une frontière de segment (l'unité de la liste est le
  SEGMENT, pas la semaine) — donc à dériver un objet métier.
- **« Adapter toute la période d'un bloc » est DÉSACTIVÉ avec sa raison, jamais caché** — et le cas
  VACANCES est aligné dessus au passage (il masquait ce bouton depuis P2-40) : cacher l'action
  cache aussi le LEVIER qui la rendrait possible. Patron déjà en place pour `generationInFlight`.
- **Le verdict tardif ne rétracte rien** : `plannedWindowsResolved` entre dans le `resolved` de la
  décision, donc la modale s'ouvre en « chargement » — sans quoi la resynchro sur signature aurait
  effacé le scindage manuel du gestionnaire sous ses yeux.
- **Fail-open sur ERREUR de lecture** (assumé) : on offre tout, et le pire cas redevient exactement
  l'existant — le 409 ci-dessous. Un fail-closed bloquerait un geste légitime sur une panne
  transitoire. La prévention est un confort, le refus est la garde.
- ⚠ **Portée : la modale SEULE.** Les autres chemins de naissance (mère mono-semaine, chip
  « + créer » d'une semaine de couverture) gardent le 409 comme unique traitement.
- ⚠ La phrase du cas VACANCES reste composée côté front : décision fermée P2-40 (aucun calcul
  serveur de couverture vacances n'existe à mirorer). Reposée le 2026-08-22 par la passe de design,
  retranchée dans le même sens.

**Refus de chevauchement sur « Adapter » (P2-38 PR3, 2026-08-18).** Le geste « Adapter » — mini
popover d'une date, `WeekPickerDialog`, carte du radar — est sous la garde serveur (« une seule
planification par fenêtre », backend-inventory.md `PeriodWindowUniquenessGuard`) : un 409
`window_already_planned` (un AUTRE plan de période gouverne déjà tout ou partie de la fenêtre)
s'affiche désormais **à l'endroit du geste**, dans le dialogue qui l'a déclenché
(`WindowAlreadyPlannedNotice`), au lieu d'être avalé et remplacé par un toast générique du filet
global (« problème de connexion » — qui accusait le réseau alors que le serveur avait répondu
précisément). Le message du serveur est repris **tel quel** : il nomme déjà la période en place,
sa fenêtre et les trois issues (modifier / supprimer ce planning / découper la période en
semaines) — le front n'en redérive rien (règle d'or, `frontend/AGENTS.md`). Le bloc n'offre qu'un
raccourci, « Ouvrir le planning en place » (navigue vers la période en conflit via son `entryId`) :
**aucun bouton de suppression** — le geste destructif garde sa maison (`DeletePlanningButton`, sa
confirmation, son avertissement de portée). Le hook `useWeekAdapt` (partagé par le radar et le
bloc vacances du `DayDialog`) **possède son feedback** (patron `ownSlotEditFeedback` du rail de
retouche) : il tait ce refus typé — le dialogue l'affiche — et laisse le filet global toaster tout
vrai échec transport ; taire l'erreur sans afficher le bloc aurait transformé un mauvais toast en
échec **silencieux**, d'où le partage assumé du même hook par les deux surfaces.

> **Ex.** « AG le 12 mai » → clic sur le 12, popover, titre + toggle informatif, **enregistré, je
> reste sur le cockpit** (2 s). « Gym Barros fermé la semaine du 4 » que je veux résoudre → clic,
> « Adapter → » → **plein écran** = wizard mode période, structure surchargeable pour la fenêtre, contraintes ouvertes.

## 6. Le calendrier secondaire = un overlay borné, pas une alternative plein-saison

Clic sur une indispo/période → **« Adapter cette période »** → on **régénère uniquement la
fenêtre** concernée, avec l'exception appliquée (gym fermé / template vacances), produisant
un **plan secondaire** qui **surcharge la base sur ces dates seulement**.

> Un calendrier secondaire n'est **pas** un deuxième planning de saison complet. C'est un
> **delta de période** : la base partout, le secondaire là où la période l'écrase. Le
> calendrier affiche la base, et « bascule » sur le secondaire sur la fenêtre.

Ça évite l'explosion combinatoire des « plans alternatifs » et colle au geste réel :
« pour ces 2 semaines, c'est différent ».

**État intermédiaire — indispo signalée mais pas encore adaptée (palier A).** Tant qu'aucun
plan secondaire n'est généré, la base continue de « vouloir » placer les séances dans le gym
fermé. Ces séances en conflit sont **affichées en alerte** (« à replacer — salle indispo »),
et le **radar** propose **[ Adapter ]**. **Rien ne bouge tout seul** : c'est un **problème
visible non résolu**, pas une erreur silencieuse. L'adaptation (palier B) le résout en
générant l'overlay. → C'est **ça**, « une indispo sans plan secondaire » : un souci **posé et
signalé**, en attente d'être adapté.

> **La forme de la réponse `/conflicts` (P2-22, lot clos 2026-08-14).**
> `GET /api/calendar-entries/{id}/conflicts` rend `{ entryId, venueIds, conflicts, closures,
> seasonPlanChosen }`. `closures` liste chaque fermeture recoupant la fenêtre de l'entrée —
> `{ constraintId, venueId, title, startDate, endDate, weekdays }`, `weekdays` = jours ISO
> fermés ∩ fenêtre — servie sur **toutes** les sorties, y compris `seasonPlanChosen=false` :
> une fermeture est un fait déclaré, indépendant de l'existence d'un calendrier à comparer,
> contrairement à `conflicts` (séances à replacer) qui, lui, dépend du plan choisi. La **donnée**
> (PR 1) est reprise par le **surfaçage** (PR 2) dans les trois écrans concernés — créneau BARRÉ
> + libellé « Indispo du X au Y — titre » au grain JOUR (pas de bande de remplacement), sur les
> grilles Gymnases/Réserver, `PeriodStructure` et le récap. Détail : [`frontend-wizard.md`](../../frontend/docs/frontend-wizard.md).

---

## 6bis. L'atelier de calendrier secondaire = le wizard en « mode période »

Décision UX forte : générer le plan d'une période **ne réinvente aucun écran**. On **réutilise
le wizard** avec un **3ᵉ mode** (`période`, à côté de `guidé` et `libre`) et des **accès
différents** :

> **Structure éditable PAR PÉRIODE (F1) :** le mode période n'est plus « lecture seule ». Le
> roster/les gymnases restent **hérités** (non ré-éditables comme entités), mais on peut
> **surcharger la participation pour la fenêtre** — équipe **on/off** + **séances** — via un DIFF
> sparse scopé **`schedulePlanId`** (`TeamPeriodOverride`, `ConstraintPeriodOverride` ; le socle
> n'est jamais touché). Les **créneaux** ne sont **pas** un diff : la période **possède sa grille**
> (copie des créneaux de saison à la naissance du plan, #8 — voir la ligne Gymnases ci-dessous).
> Modèle : [`../../docs/architecture/adr-0002-pattern-plan.md`](../../docs/architecture/adr-0002-pattern-plan.md) inv. 5 (amendé #8).

| Étape wizard | En mode période |
|---|---|
| Équipes | Roster **hérité** (non ré-éditable), mais **activable/désactivable** pour la période + **séances** surchargeables (champ 1–7, toggle = 0 séance). **Défaut conscient du type de période** (E3, 2026-07-19) : **reprise** (`holiday`) = **Fanion + importantes** (les 2 premiers rangs, S+A) pré-cochées, avec repli sur le meilleur rang réellement présent si le club n'a ni S ni A — la reprise n'est jamais vide ; **fermeture** (`closure`) = **tout le club actif** (structure verrouillée, les équipes loisir se décochent à la main). |
| Gymnases | **La période possède SA grille** (#8, 2026-07-24) : les créneaux de saison y sont **copiés** à la naissance du plan, puis modifiables sans jamais toucher au planning principal. Plus rien d'additif — le socle et la période ne sont **jamais** unis. Fermetures datées marquées au **grain JOUR** (« Indispo ven–dim du X au Y — titre », P2-22 PR 2, `frontend-wizard.md` pour le détail). À l'écran, un **sélecteur de gymnase** (une grille à la fois, comme l'éditeur de saison) montre la grille servie à la période, éditable créneau par créneau (clic = poser, clic sur un créneau = modale jour/heure/durée/capacité + suppression confirmée). **Le sélecteur lui-même annonce l'état effectif de chaque gymnase (P2-43 volet ii, 2026-08-19)** : chaque option porte, en suffixe de son nom, « désactivé » / « indisponible toute la période » / « fermé {jours} » (masque manuel OU indispo déclarée, priorité désactivé > fermé total > fermé partiel) ou rien s'il est ouvert — avant ce correctif, seule la fermeture TOTALE était annotée (« Indispo cette période ») et un gymnase DÉSACTIVÉ, ou fermé seulement certains jours (cas nommé : un gymnase « ADN » désactivé), restait invisible au choix. **La modale d'édition d'un créneau dit si le jour est fermé (P2-43 volet i, 2026-08-19)** : posé sur un jour effectivement fermé, l'éditeur affiche « Créneau inactif — le {jour} est fermé ({cause}). Le poser reste possible ; il ne servira pas tant que ce jour reste fermé. » (cause = masque manuel ou indisponibilité déclarée) — décision fondateur (a) : poser reste PERMIS, l'éditeur se contente de le DIRE ; avant ce correctif, `isDisabled` ne lisait que le mode gymnase (`DISABLED`), jamais le masque jour, et un créneau posé sur un jour décoché s'éditait sans aucun indice. Les deux lisent l'état effectif SERVI (`conflicts?.disabledVenueIds`/`effectiveClosedWeekdays`), jamais recomposé côté front. Par gymnase : un **état** actif/désactivé qui ne touche jamais la grille — désactiver n'a donc aucun coût, réactiver la rend telle quelle — et deux **actions** destructives atomiques, « reprendre la grille du planning principal » et « vider », chacune confirmée en annonçant les réservations emportées. Un gymnase désactivé a sa grille **gelée** dans un `<fieldset disabled>` (inerte souris ET clavier) : la table ne stocke qu'un mode par gymnase, donc vider écraserait l'état désactivé. Sous le capot, réglage épars `VenuePeriodOverride` — pas de ligne = hériter, le défaut ; deux
réglages indépendants, chacun facultatif : `mode` (NULLABLE — DISABLED/BLANK/hériter) et un
**masque manuel** jour ISO 1..7 → OPEN|CLOSED (`dayOverrides`), qui s'ajoute au défaut JOUR PAR
JOUR. **Indisponibilité INFORMATIVE (décision fondateur 2026-08-18, remplace le régime ci-dessous)** :
une fermeture datée `venue_closed` ne verrouille plus le réglage — elle PRÉ-REMPLIT un défaut
vivant que le masque du plan peut contredire (jour rouvert `OPEN`, jour décoché `CLOSED`). La
composition (incident × masque) vit dans la maison unique
`PlanVenueClosures::effectiveStateForPlan/Entry`, partagée par TOUS les consommateurs (gate,
payload, `OrphanPinGuard`, réservations, radar) — aucun ne la redérive. Un épinglage qui ne
retombe sur aucun créneau **bloque la génération** en nommant le gymnase, le jour et l'équipe —
**sauf sur un gymnase EFFECTIVEMENT fermé-total** (union des jours fermés après composition
couvrant toute la fenêtre) : là, comme un gymnase désactivé, l'épinglage est inerte et ne bloque
plus ; un jour **effectivement** fermé d'un gymnase par ailleurs ouvert (par le défaut OU par un
masque `CLOSED`) reste bloquant ; un jour rouvert par le masque (`OPEN`) n'est plus une cause de
blocage. **Réactiver un gymnase entièrement fermé — POST/PUT ou DELETE de son
`VenuePeriodOverride` — N'EST PLUS REFUSÉ** : le verrou P2-37 D2 (« refusé en 422, non réversible »)
est SUPPLANTÉ — le serveur accepte de nouveau le geste, DELETE purge mode ET masque (retour complet
au défaut). **Écran (P2-37 PR2, 2026-08-18 soir — lot SOLDÉ)** : sous le sélecteur de gymnase, une
**rangée de 7 coches jour** (« Jours ouverts cette période ») lit l'état EFFECTIF servi
(`effectiveClosedWeekdays`) — une coche cochée = jour ouvert, décochée = jour effectivement fermé,
avec sa **provenance** en info-bulle (« fermé — indisponibilité déclarée (du…au…) » /
« fermé — décoché manuellement » / « ouvert — réactivé malgré l'indisponibilité »). Cliquer une
coche écrit l'OPPOSÉ de l'état effectif dans le masque (`dayOverrides`) si le jour n'y a pas déjà
d'entrée, ou RETIRE l'entrée existante (retour au défaut de l'incident) — jamais de recomposition
côté front (`wizard/lib/venueDays.ts`, helpers purs, pas de miroir déclaré : la composition reste
100 % serveur). **L'interrupteur Désactiver/Réactiver REVIENT sur un gymnase entièrement fermé** —
le surfaçage du 2026-08-18 matin qui le cachait (remplacé par la raison en clair) est PÉRIMÉ : le
serveur accepte le geste, donc l'écran ne le cache plus ; la raison reste affichée en badge
d'information, à côté de l'interrupteur, jamais à sa place. **Gestes gymnase entier**, sous la
rangée de coches : « Réactiver malgré l'indisponibilité » (visible si une indisponibilité déclarée
existe — pose `dayOverrides` = OPEN×7, mode préservé) et « Revenir au défaut » (visible si une ligne
`VenuePeriodOverride` existe — DELETE, purge mode ET masque). Sous **DISABLED**, la rangée est gelée
(`<fieldset disabled>`, coches conservées, mention « Grille et coches conservées — réactivez le
gymnase pour les retrouver ») — réactiver ne perd pas le masque manuel posé avant la désactivation.
**Grain semaine-type** : décocher/cocher un jour vaut pour **toutes les semaines de la période**
(mention à l'écran), pas une date précise. **Bandeau d'ensemble** (au-dessus du sélecteur) : liste
maintenant aussi bien les gymnases indisponibles (déclaré) que ceux portant des jours décochés À LA
MAIN (`manual`), pour qu'un gestionnaire les voie sans sélectionner chaque gymnase. **Récap**
(`RecapStep.tsx`) : les réservations non servies et le motif affiché lisent l'état EFFECTIF servi
(`effectiveClosedWeekdays` + `disabledVenueIds`), plus les fermetures brutes pour le TITRE — un jour
rouvert par le masque n'est plus annoncé fermé. Témoins : `PeriodStructure.test.tsx` (rangée de
coches, provenance, gestes gymnase entier, gel sous DISABLED, bandeau), `venueDays.test.ts` (règle
d'écriture de la coche), `RecapStep.test.tsx` (état effectif). ⚠ **Point contre-intuitif, vérifié
au code, pas un oubli** : la grille d'un gymnase entièrement fermé reste **modifiable** (créneaux,
Reprendre/Vider) — le `<fieldset disabled>` de la grille ne gèle que sur le mode `DISABLED` de
l'override, jamais sur une fermeture (`PeriodStructure.tsx`). Geler la grille aurait inventé une
restriction que le SERVEUR n'impose pas (seule la réservation reste refusée en 422, au grain jour
EFFECTIF — le mode override, lui, est accepté depuis la décision fondateur du 2026-08-18 ; jamais un
geste de grille) — le front serait devenu plus strict que le serveur, ce que la règle d'or interdit.
L'écran le dit : « Vous pouvez rouvrir des jours ci-dessous, ou le préparer pour la suite. » ⚑ **Ce
point est TRANCHÉ, pas ouvert** : décision fondateur du 2026-08-18 — la grille reste modifiable, ne pas
rouvrir sans besoin terrain (`etat-des-lieux.md` §2). |
| Coachs | **Hérités, lecture seule** (lien équipe↔coach préservé) |
| **Contraintes** | **Active.** Pré-remplie avec **l'exception** (ex. De Barros indispo sur la fenêtre) ; le gestionnaire **ajoute les contraintes propres à la période** (« du coup U13 passe le mercredi ») et **hérite les contraintes permanentes du socle**, chacune **cochable/décochable** pour la fenêtre. DIFF `ConstraintPeriodOverride` épars : une ligne n'existe que pour une **déviation** du défaut (le socle et le `isActive` propre de la contrainte ne sont **jamais** touchés). **Défaut selon le type de période :** <br>• **Fermeture** (closure) → **tout gardé** (on décoche ce qui gêne). <br>• **Reprise** (holiday) → défaut **intelligent qui suit les équipes** : contrainte **club/coach** gardée, contrainte **d'équipe** gardée seulement si l'équipe reprend (décochée si l'équipe est en pause), contrainte **de gymnase** décochée (pas de créneaux socle en reprise). Calculé (pas de seed persisté), miroir back/front. |
| Récap | Résumé de la **période** (fenêtre + exceptions + contraintes) |
| **Génération** | Génère l'**overlay** borné à la fenêtre (le calendrier secondaire) |

> **On ne re-saisit jamais la structure du club** (équipes / salles / coachs) pour une exception
> de 2 semaines. On **hérite**, et on ne touche qu'aux **contraintes** de la période. Le
> gestionnaire retrouve **exactement les mêmes écrans** → il n'est **jamais perdu**.

**Enchaînement concret** (« De Barros impossible cette semaine → nouvelle organisation ») :
1. Clic sur la date → « Signaler une indispo » → gym De Barros, fenêtre = la semaine.
2. L'indispo **crée une période** et **pré-remplit** sa contrainte (De Barros fermé sur la fenêtre).
3. « Adapter → » ouvre le **wizard en mode période** : Équipes/Salles/Coachs en lecture seule,
   **Contraintes** ouvertes (l'exception est déjà là, on affine), **Génération** → overlay.
4. L'overlay **surcharge** la base sur la fenêtre ; ailleurs, la base est intacte.

C'est le lien direct entre **« créer un événement contraignant »** et **« générer le plan de
cette période »** : le même wizard, en plus léger, focalisé sur ce qui change.

## 6ter. Cohérence : DEUX familles d'écrans, réutilisées partout

Le principe qui tient toute l'ergonomie : le gestionnaire ne rencontre que **deux types
d'écran**, quel que soit le planning (principal ou secondaire). Il navigue entre **trois
endroits** seulement.

**Écran de consultation** (grille, lecture seule)
- Le **même écran** sert à consulter le **planning principal** **ET**, à terme, **chaque
  calendrier secondaire**. Une seule UI de consultation.

**Enchaînement wizard** (les 6 étapes)
- Le **même flux** sert à l'**onboarding**, à l'**édition du socle** (mode libre) **ET** à la
  **génération/édition d'un secondaire** (mode période — §6bis). Un seul flux d'édition, avec des
  **accès selon le mode**.

**Navigation :**
```
        ┌──────────────┐   Ouvrir / clic un planning    ┌────────────────┐
        │   ACCUEIL    │ ─────────────────────────────▸ │  CONSULTATION  │
        │  (cockpit)   │ ◀───────── « Accueil » ─────── │ (grille R/O)   │
        └──────┬───────┘                                 └───────┬────────┘
               │  Modifier / Adapter / Créer période             │ Modifier
               ▼                                                  ▼
        ┌────────────────────────────────────────────────────────────────┐
        │      WIZARD  (onboarding · libre · période — mêmes 6 écrans)     │
        │      … validation → repart sur la CONSULTATION                   │
        └────────────────────────────────────────────────────────────────┘
```
- **Valider un planning** → on arrive sur la **consultation**. Pour revenir → **« Accueil »**.
- **Éditer un planning** → on entre dans l'**enchaînement wizard** (avec ses spécificités de mode).

> **Bénéfice** : le gestionnaire n'a **que 2 types d'écran à apprendre** — consulter, éditer.
> Principal ou secondaire, onboarding ou ajustement de période : **mêmes repères**. Il se
> concentre sur **son travail**, pas sur « où suis-je / comment ça marche ici ». Zéro
> désorientation.

> **Ex.** Je consulte le planning principal (grille R/O), « Accueil », je clique la période
> Toussaint → **la même grille R/O** pour son overlay. Je clique « Modifier » → **le même
> enchaînement wizard** qu'à l'inscription (en mode période). **Aucun écran neuf** dans tout le
> parcours.

## 7. Ce que ça **simplifie / remplace** dans la roadmap §2

| Roadmap §2 (aujourd'hui) | Ce que ce doc propose |
|---|---|
| `schedule_slot_occurrences` + matérialisation J+14 (🔴 « tout dépend de ça ») | **Projection** + **occurrences éparses (deltas)** — la fenêtre J+14 **n'est plus un prérequis** |
| 4 tables `period_*` | **1 entité `CalendarEntry`** (kind + plage), le reste vient après si besoin |
| Plans secondaires « alternatifs » plein-saison | **Overlays de période bornés** |
| Vue calendrier annuel (dépend de tout) | **Devient l'accueil**, livrable tôt en mode projection |
| Scheduler quotidien J-14/J-7/J-3 | **Le panneau radar** (même logique, rendue visible et actionnable) |

**Le pari** : en inversant « matérialiser d'abord » → « projeter + ne matérialiser que les
exceptions », toute la §2 devient **incrémentale** au lieu d'un mur 🔴.

---

## 8. Ce que ça donne, par paliers de valeur (ordre, pas un plan)

- **Palier A — le cockpit sans génération de période.** Accueil 3 zones. Le calendrier
  **projette** la semaine type. `CalendarEntry` (événements + indispos datées). Feed vacances
  scolaires. Clic-date = ajout rapide. Une indispo apparaît en ⛔ et **alimente le radar**
  (« à adapter »), sans encore générer quoi que ce soit. **→ Déjà énorme en valeur : le
  gestionnaire voit venir.**
- **Palier B — les calendriers secondaires.** Clic sur une indispo/période → génération
  **bornée** → plan secondaire en overlay. Occurrences éparses persistées seulement là.
- **Palier C — le différenciateur.** Collecte des dispos coach **par lien sans login** pour
  une période (questionnaire email) → alimente la génération du plan secondaire. + alertes
  automatiques (cron) qui remplissent le radar tout seul.

---

## 9. Tranché vs ouvert

**Tranché :**
- L'accueil **n'est plus** le planning — c'est le cockpit ; le planning reste **derrière le
  bandeau**.
- **Projection, pas matérialisation** : occurrences uniquement en delta d'exception.
- **1 entité `CalendarEntry`**, à **2 `kind`** (`event` / `period` — l'indispo est une `period`
  `closure`, pas un 3ᵉ type) plutôt que 4 tables d'emblée.
- **Calendrier secondaire = overlay de période borné**, pas une alternative plein-saison.
- Le **radar** est la to-do actionnable (généralise les alertes J-14).
- **Calendrier = vue par mois entier, jour courant entouré** (plus lisible qu'une fenêtre
  glissante).
- **Événement club = informatif par défaut, marquable « perturbant » au choix du gestionnaire.**
- **Vacances = zone dérivée du code FFBB (département → zone), périodes stockées en base et
  seedées une fois par an** (pas d'API au runtime, pas de géocodage d'adresse).
- **Modèle delta / override acté** : pas de matérialisation J+14 ;
  `schedule_slot_occurrences` (si conservée comme table) ne stocke **que les overrides**. Le
  PDF daté / les stats se calculent par **projection + deltas** au moment du besoin.

- **Vacances = proposées comme période à adapter** (le radar dit « adapter les vacances ? »),
  **jamais auto-appliquées** — le gestionnaire déclenche.
- **Suppression** : un **plan secondaire (overlay) est supprimable** → le calendrier
  **re-projette la base** sur la fenêtre. **Seul le planning principal n'est jamais supprimable.**
- **Cliquer une date** : annotation légère = **modale/popover** ; générer/travailler un plan =
  **écran dédié** = **le wizard en mode période** (§5bis, §6bis).
- **Le calendrier affiche les événements/exceptions, pas la semaine type** (jour vide = base
  normale). La projection ne sert qu'à la demande (overlay, PDF daté) — pas au rendu du mois.
- **Une période ne s'AJOUTE jamais à la base — tranché par #8 (2026-07-24).** Elle **possède sa
  grille de créneaux** : copie du modèle de saison prise à la naissance du plan, ancrée
  `schedulePlanId`, **jamais unie** aux créneaux de saison au build. Ce que `periodType` porte
  encore, c'est l'**héritage des contraintes permanentes** : `closure` → toutes gardées (on
  décoche ce qui gêne) ; `holiday` → défaut intelligent (club/coach gardées, équipe selon la
  sélection, gymnase décochée). `cutoff` et `mutualisation` ne portent pas de plan.
  *(Cette ligne remplace l'ancienne formulation « additive vs remplaçante », et clôt l'arbitrage
  que §9ter.e laissait ouvert.)*
- **`CalendarEntry` à 2 `kind`** (event / period) + réutilisation de `Constraint` (FK nullable
  `calendarEntryId`) et de `Schedule` (overlay). Quasi aucune nouvelle table.
- **Plan principal = socle à COÛT PROGRESSIF (§2bis)** : **librement remaniable tant qu'aucun
  overlay n'existe** (début de saison, contraintes coach encore mouvantes) ; « Modifier » devient
  **destructeur dès qu'il y a des secondaires à venir** (les supprime — les périodes déjà
  commencées survivent ; confirmation proportionnée qui les nomme).
  **Gel de facto, pas une serrure.** Grille en lecture seule ; l'édition = « Modifier » → wizard
  (= `reopen`). Le quotidien passe par les périodes/overlays.
- **Le socle DÉBLOQUE le cockpit, en DEUX seuils (§2ter)** : tant que le plan n'a **rien généré**,
  l'accueil **est** le wizard (le plancher d'abord) ; dès qu'il porte une **version terminée**
  (`hasFinishedVersion`), l'accueil devient le cockpit et les fonctions temporelles s'ouvrent ;
  dès qu'il **pointe** une version (`chosenScheduleId`), ce qui se **bâtit dessus** s'ouvre à son
  tour (matchs, calendriers secondaires). Le premier seuil est **indépendant du pointeur** :
  rouvrir ne re-verrouille pas le cockpit.
- **DEUX familles d'écrans réutilisées partout (§6ter)** : **consultation** (grille R/O — principal
  ET secondaires) et **wizard** (onboarding · libre · période). Navigation à 3 endroits : Accueil ↔
  Consultation ↔ Wizard. Valider → consultation ; « Accueil » → cockpit ; éditer → wizard.
  **Le gestionnaire n'a que 2 types d'écran à connaître.**

**Ouvert — plus rien de bloquant.** Les détails restants (libellés exacts, statut d'une séance
en conflit non résolue, forme du questionnaire coach du palier C) se tranchent à l'implémentation.

---

## 9ter. Modèle de données : `CalendarEntry` (poussé)

En creusant, la taxonomie à 3 objets (§4) **se réduit en fait à 2 sur le plan données** — et
surtout, **on réutilise l'existant au lieu d'ajouter des tables.**

### a. Deux `kind`, pas trois

- **`event`** — un **marqueur** sur le calendrier (AG, tournoi, stage). Informatif. Peut être
  marqué **`isDisruptive`** (« pas d'entraînement ce jour »).
- **`period`** — une **fenêtre qui altère le plan** (fermeture de salle, vacances, coupure,
  mutualisation). Porte des **contraintes datées** + un **plan secondaire** (overlay).

> **« Signaler une indispo » n'est pas un 3ᵉ type : c'est un raccourci pour créer une `period`**
> dont la contrainte « gym X fermé » est pré-remplie. De même, un événement `isDisruptive`
> devient/produit une mini-période « pas d'entraînement ce jour ». Fermeture / vacances / coupure
> / mutualisation = un `periodType`, pas des entités séparées.

### b. L'entité

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
  parentEntryId     uuid?        -- semaine ENFANT d'une période mère (P2-5 E1)
  createdBy, createdAt, updatedAt
```

> **Plus de pointeur d'overlay sur l'entrée.** Le champ `overlayScheduleId` a bien existé
> (livré au palier A le 2026-07-04) puis a été **supprimé** par ADR-0002 lot D-b (2026-07-18) :
> il posait la même question que le pointeur du plan, à deux endroits. Désormais le plan
> secondaire est un `SchedulePlan` ancré à la `CalendarEntry`, et c'est **lui** qui pointe sa
> version (`chosenScheduleId`). Lecture : `SchedulePlanProvisioner::chosenOfPeriodPlan`, ou
> `chosenByPeriodPlans` pour résoudre N entrées en une requête. Un plan de période qui ne pointe
> rien = **aucun overlay applicable**, pas un overlay vide.

### c. La réutilisation maligne (≈ zéro nouvelle table à part `CalendarEntry`)

- **Contrainte datée = la `Constraint` existante + un FK nullable `calendarEntryId`.**
  Une contrainte est soit **permanente** (`calendarEntryId = null`, le plan de base), soit
  **de période** (`calendarEntryId` renseigné). Une « fermeture de salle » = une `Constraint`
  `family=FACILITY` rattachée à l'entrée. **On ne réinvente pas les contraintes.**
  **Deux natures de datées (modèle FAIT/GENÈSE, arbitrage fondateur 2026-09-01)** : le **FAIT**
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
  **n'existe plus** : `ManualEditController` a perdu `/manual-edit/one-time` (P4-86, 2026-08-12)
  et le champ `temporaryLock` a été retiré de bout en bout (contrat 2.9, 2026-08-16, jamais lu
  par le solveur) — une vraie table d'occurrences éparses repartirait de zéro, ne s'ajoute
  **que si** le besoin fin le justifie (palier B/C).

### d. Deux lectures distinctes — ne pas les confondre

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

### e. L'arbitrage « additive vs remplaçante » — TRANCHÉ par #8 (2026-07-24)

Cette section posait la dernière question ouverte du modèle : « une `period` est-elle
**additive** (base + exception) ou **remplaçante** ? ». **La réponse livrée est : ni l'une ni
l'autre — la période est PROPRIÉTAIRE.**

- **Créneaux** : la période **possède sa grille**. Les `VenueTrainingSlot` de la saison sont
  **copiés** dans le plan à sa naissance (ancre `schedulePlanId`), puis vivent leur vie. Le
  build d'overlay ne lit **que** les créneaux du plan — **aucune union**, aucune résolution
  « saisonnier→période ». Modifier la grille d'une période ne touche donc jamais le socle, et
  réciproquement. Par gymnase, un réglage **épars** `VenuePeriodOverride` — `mode` NULLABLE
  (`DISABLED` / `BLANK` / hériter) **et/ou** un masque manuel jour ISO 1..7 → `OPEN`/`CLOSED`
  (`dayOverrides`), les deux facultatifs — et deux actions destructives atomiques (« reprendre la
  grille du planning principal », « vider »). **Indisponibilité INFORMATIVE (décision fondateur
  2026-08-18)** : une fermeture datée pré-remplit un défaut que le masque contredit jour par jour ;
  composition dans la maison unique `PlanVenueClosures::effectiveStateForPlan/Entry`.
- **Contraintes permanentes** : c'est là, et seulement là, que `periodType` porte encore une
  sémantique d'héritage. `closure` → toutes gardées (le gestionnaire décoche) ; `holiday` →
  défaut intelligent qui suit la sélection d'équipes (club/coach gardées, équipe gardée si
  l'équipe reprend, gymnase décochée). Diff épars `ConstraintPeriodOverride` : une ligne
  n'existe que pour une **déviation** du défaut.
- **Contraintes datées** : portées par la `CalendarEntry` — le **fait** (« Barros fermé ») par
  la mère, la **genèse** d'une semaine par son entrée-enfant (modèle FAIT/GENÈSE, §c) ; jamais
  décochables par plan, un réglage de plan n'est pas leur affaire.
- `cutoff` et `mutualisation` ne portent pas de plan de période.

Corollaire opérationnel : un épinglage HARD qui ne retombe sur aucun créneau de la grille de la
période **bloque la génération** (422 nommant le gymnase, le jour et l'équipe, `OrphanPinGuard` — sauf sur un gymnase DÉSACTIVÉ (exclu depuis P3-20) **ou EFFECTIVEMENT fermé-total sur la fenêtre** — état EFFECTIF de `PlanVenueClosures` (incident × masque manuel du plan, décision fondateur 2026-08-18) ; un jour effectivement fermé (par le défaut ou par le masque) d'un gymnase par ailleurs ouvert reste bloquant, un jour rouvert par le masque ne l'est plus) — dans un
modèle additif il aurait silencieusement retrouvé un créneau de saison.

## 10. En une phrase

L'accueil devient le **cockpit temporel** du club : la **semaine type** reste la base (derrière
le bandeau), le **calendrier** montre **la timeline des exceptions** (événements, indispos,
périodes, vacances) — un jour vide = tout roule — et un **radar** dit ce qui arrive. Chaque souci
daté devient, en un clic, une **période** adaptée dans **le même wizard, en mode allégé**, qui
produit un **overlay borné**. On ne matérialise jamais l'ennuyeux ; on ne crée que là où la
réalité diverge. Côté données : **`CalendarEntry` + 2 FK** sur l'existant, presque rien de neuf.
