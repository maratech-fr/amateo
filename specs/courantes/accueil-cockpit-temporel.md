# Accueil « cockpit temporel »

Last verified @ 2026-09-26 (`documentation-update`) contre le code : `stalenessMessage`
importée/utilisée dans `frontend/src/features/planning/PlanningPage.tsx:45,634` ✓,
`App\Service\CalendarEntryRedatability::isRedatable` sert bien le champ `redatable`
(`backend/src/Service/CalendarEntryRedatability.php:32`) ✓. Historique de ce fichier :
`git log -p --follow` dessus.

> **Statut** : livré — cf. [`etat-des-lieux.md`](etat-des-lieux.md) §1.2. Ce document fixe le
> modèle d'UX + d'architecture de l'accueil cockpit et la fondation des **calendriers
> secondaires**. **Vision d'origine** : `initiales/ClubScheduler_v3.md` §3.5, §3.6, §8 — ce doc
> s'en écarte là où elle prévoyait une matérialisation lourde (§3).
> **Modèle métier des 3 types de planning** (socle / overlay / reprise — déclenchement,
> manipulation, règle « semaine = unité hors socle ») : [`types-de-planning.md`](types-de-planning.md).

---

## 1. Le problème

`/` = `PlanningPage` : l'accueil **est** le planning hebdomadaire, une **semaine type** répétée à
l'infini — sans dates réelles, sans vacances, sans plan alternatif. Le gestionnaire n'a aucun
endroit pour **voir venir** et **anticiper**.

Le cockpit répond avec 3 zones : un **bandeau** (la semaine type, le planning de base), un
**calendrier** (dates réelles, la vie de la saison) et un **panneau radar** (ce qui demande
attention : vacances proches, planning à (re)générer, événements du club). Cliquer une date crée
un événement ou signale un souci ; cliquer un souci crée un **calendrier secondaire** — c'est le
travail préliminaire des calendriers secondaires.

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

## 2. Le vrai enjeu : un glissement de modèle mental

Le cockpit matérialise un **changement de modèle** :

> **De** « une semaine type » **vers** « une **semaine type de base** + une **timeline
> éparse d'exceptions**, chacune portant éventuellement un **plan secondaire borné** ».

La **semaine type** reste la **source de vérité**, **derrière le bandeau** — pas redessinée sur
le calendrier. Le **calendrier** est la **couche d'exceptions** sur des dates réelles : il montre
**ce qui sort de l'ordinaire** (événements, indispos, périodes, vacances), pas les séances de
base — un jour vide = la base tourne normalement. Les **vraies séances d'une date** se
**projettent à la demande**, jamais stockées (§9ter). Un **calendrier secondaire** = un **plan
adapté, borné à une période**, qui **surcharge** la base uniquement sur ces dates.

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

> **Portée bornée — ce n'est PAS « tous les secondaires »** (ADR-0002 inv. 14) : seules partent
> les périodes **entièrement à venir**. Le pivot est la date de **début** — « rien du passé, rien
> de ce qui est en cours ». Une période déjà commencée **survit** à la réouverture du socle. Sont
> concernées les périodes qui **portent un plan**, **validé ou non** (une période « Adaptée » mais
> jamais générée compte). Référence normative :
> [`planning-lifecycle-validated.md`](planning-lifecycle-validated.md) §6.

> **Le gel est donc DE FACTO, pas une serrure.** On ne verrouille pas la base par un état ; on
> **arrête d'y toucher parce que ça coûterait les overlays** — pas parce que c'est interdit.

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

## 3. Modèle delta : projection + occurrences éparses, pas de matérialisation

La vision d'origine (`initiales/ClubScheduler_v3.md` §3.5) prévoyait `schedule_slot_occurrences` :
matérialiser chaque occurrence de chaque créneau sur une fenêtre glissante J+14. **Ce modèle n'est
pas retenu** : matérialiser toutes les occurrences écrirait des milliers de lignes quasi
identiques au template (40 semaines × N créneaux), à garder synchronisées avec la base à chaque
régénération — un coût énorme pour une valeur quasi nulle sur les créneaux qui ne dérogent jamais.

**Le modèle retenu — occurrences éparses (deltas), pas matérialisation :**

> Une occurrence n'existe en base **que là où la réalité diverge du template** : créneau annulé,
> déplacé, salle changée, ou appartenant à une **période** (plan secondaire). Partout ailleurs, le
> calendrier **projette** le template à la volée. La matérialisation est paresseuse, pilotée par
> les exceptions — jamais une fenêtre J+14 qui matérialise tout d'avance.

Conséquence : le cockpit, le calendrier réel et les événements sont livrables **sans** la
machinerie templates→occurrences de la vision d'origine — la matérialisation n'arrive que
quand/où une exception l'exige.

> **Ex.** 40 semaines × 60 créneaux = 2 400 occurrences à matérialiser puis resynchroniser à
> chaque régénération, pour un club qui ne déroge jamais. En delta : 0 ligne tant que rien ne
> bouge ; une fermeture de gym la semaine du 4 mai n'écrit que les quelques overrides de cette
> fenêtre. Le calendrier de mars, avril, juin… reste une projection gratuite du template.

---

### 3bis. Vocabulaire : template, occurrence, modèle delta

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
- **Modèle retenu** : rien n'est matérialisé d'avance. Le template se **projette** sur les dates
  à l'affichage ; une occurrence n'est créée **que quand une date déroge** — elle est alors un
  **override** (un delta), pas une copie. → « occurrences éparses ».

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

Un **événement club** est un post-it daté (tournoi, AG, stage) ; marqué **perturbant** (au choix du
gestionnaire, événement par événement), il devient une mini-indisponibilité et alimente le radar.
Une **indisponibilité** (« gym Barros indispo la semaine du 4 mai ») est une contrainte **datée**
qui n'a de sens que sur sa fenêtre. Une **période** est une plage nommée avec **son propre plan**
(le calendrier secondaire).

### 4bis. Vacances scolaires — dérivées du code FFBB, jamais géocodées au runtime

La **zone scolaire** du club (`Club.schoolZone`) se dérive du `Club.ffbbClubCode` (département →
zone), jamais d'une API Géo depuis l'adresse. Les **périodes de vacances** vivent en base
(`SchoolHolidayPeriod`), importées depuis la source officielle par une commande périodique — pas
d'appel réseau au runtime. Le cockpit lit les vacances **de la zone du club**, les affiche sur le
calendrier et les remonte au radar (« Vacances Toussaint dans 24 j »). Détail du modèle, des zones
et de l'alimentation : [`vacances-scolaires-jours-feries.md`](vacances-scolaires-jours-feries.md).

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

**Bandeau** = l'état du plan principal **d'un coup d'œil** : **validé** · N diagnostics — **le
score du solveur ne s'affiche nulle part** (décision fermée, `etat-des-lieux.md` §2).
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
a un CTA.** C'est la version généralisée des alertes J-14 de la vision d'origine (§8.2). Les
vacances y sont **proposées** comme période à adapter (CTA « Générer le plan »/« Adapter »),
**jamais auto-appliquées** — c'est le gestionnaire qui déclenche.

### 5.1 Le radar ne montre que l'AVENIR ACTIONNABLE

Une to-do n'est pas un inventaire. Trois règles :

- **Horizon des vacances : 30 jours** (`SCHOOL_HOLIDAY_HORIZON_DAYS`) — une vacance n'apparaît au
  radar que 30 j avant son début, aligné sur l'horizon des jours fériés. ⚠ L'horizon ne masque
  que les vacances **intactes** : dès qu'un plan existe, la période devient une carte « en cours »
  qui y échappe — cacher un travail commencé serait pire que le bruit corrigé.
- **Les semaines RÉVOLUES sont écartées** — de la couverture d'une période découpée, des semaines
  offertes à la création, et des semaines proposées à la sollicitation des coachs : « on gère
  l'avenir, pas le présent ». La règle vit en un seul endroit, `isActionableWeek` /
  `actionableWeeks` (`features/cockpit/lib/date.ts`), à côté de `periodAdjustWeeks` — source
  unique des semaines qu'une période offre, lue à la fois par le radar et la campagne coachs.
  ⚠ **RÉVOLUE, pas « commencée »** — le critère est `endDate >= today` (même test que le radar
  applique déjà aux périodes) : une seule notion de « c'est derrière », à deux échelles. Le lundi
  dit QUELLE semaine c'est (clé stable), la fin dit s'il reste à y faire — un critère sur le début
  de semaine rendait implanifiable, ou privait de toute collecte, une fermeture ou une vacance
  démarrant en cours de semaine dès le lundi suivant. **La règle vaut à TOUS ses sites**
  (`periodWeeksToAdjust` : radar, modale du jour, picker) — les avoir laissés diverger produisait
  un plan de semaine révolue filtré ensuite partout, un artefact sans carte ni retour possible.
  Même critère au niveau **période** (`h.endDate >= today`) : une vacance commencée dont des jours
  restent devant garde son point d'entrée.
- **La carte de couverture est repliée par défaut** ; les autres gardent leur action visible —
  tout replier mettait chaque geste du radar à deux clics sans raccourcir ce qui est réellement
  long (les N puces de semaine). L'en-tête (titre, dates, compteur « x/y couvertes ») reste
  toujours lisible.

⚠ **L'horizon masque aussi les doléances** — `RadarCoachWishAction` n'est rendu nulle part
ailleurs dans l'application : « on ne les sollicite pas au-delà de l'horizon, en général ça se
fait 3 semaines avant les vacances » (décision fermée, [`etat-des-lieux.md`](etat-des-lieux.md)
§2 — aucun second point d'entrée). Une vacance qui porte **déjà** une campagne garde sa carte
quelle que soit sa distance — on ne fait jamais disparaître un travail engagé ; pour la même
raison, « Doléances » et « Solliciter les coachs » restent **hors du repli** de la carte de
couverture.

**Chargement** : tant que les plans, les versions, les campagnes, les impacts de fermeture ou la
zone scolaire sont en vol, le radar affiche un **squelette** (région live pour les lecteurs
d'écran). ⚠ **Charger n'est pas échouer** — le squelette s'appuie sur `readLoading`, pas sur
« pas de donnée » : un chargement en échec le **dit** (« Impossible de charger les éléments à
traiter ») plutôt que de rester « Chargement… » pour toujours. Le squelette et « Rien à
l'horizon. Tout roule. » ne coexistent jamais : `isEmpty` exige que toutes ces lectures soient
résolues.

**Horloge de démo** : `shared/lib/clock.ts` est le point de passage unique du « aujourd'hui » du
front. En **dev uniquement** (`import.meta.env.DEV`), `?today=2026-12-20` le décale pour rejouer
une situation datée — vérifiée comme une date RÉELLE, pas seulement dans sa forme, sinon une date
invalide trie après toute date et vide le radar en affirmant « Tout roule ». Côté **serveur**,
`DemoAwareClock` décale déjà la date pour un **club de démonstration** (`Club.demo_today`, posé/
relâché par `app:demo:clock`) — tout consommateur de l'horloge la voit mentir pour ce club ; un
club réel garde l'heure vraie.

---

## 5bis. Interaction : cliquer une date (modale légère vs écran)

> **Règle : annoter = modale ; générer / travailler un plan = écran.**

- **Date vide** → petit **popover** : `[ Événement ]` `[ Indispo salle ]` `[ Période… ]`
  - **Événement / Indispo / Coupure** → **mini-formulaire dans le popover** (titre + **plage
    `Du … Jusqu'au …`** ; l'**Événement** ajoute le toggle informatif/perturbant, l'**Indispo**
    le gymnase) → enregistrer, on **reste sur le cockpit**. Le **jour cliqué n'est qu'un défaut**
    pour les deux bornes : début **et** fin sont éditables (`aujourd'hui ≤ début ≤ fin`).
  - **Période…** → **navigue vers l'écran dédié** (atelier du calendrier secondaire).
- **Jour férié / vacances** → la modale affiche un **bandeau info** en tête (« Jour férié — … » /
  « Vacances — … »). Les **vacances** portent en plus un **« Adapter »** direct dans la modale
  (crée la période si absente puis ouvre le wizard en mode période ; « Voir le planning » si
  l'overlay existe déjà) — pas besoin de passer par le radar.
  - Si la période couvre **plusieurs semaines calendaires**, un **choix des SEGMENTS**
    (`WeekPickerDialog`) s'interpose avant le wizard : chaque **segment** coché devient une entrée
    **enfant** (`parentEntryId`) avec **son propre plan** ; une seule semaine → wizard direct,
    même règle partout où le geste existe. Un **segment** est un bloc de semaines calendaires
    pleines et contiguës (lundi→dimanche, clamp saison), la semaine simple étant le segment de
    taille 1 ([ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md)). Les segments sont
    précochés aux ruptures géométriques de l'offre (`segmentsFromOffer`, `lib/date.ts`), avec deux
    gestes : **scinder** (déplier un segment en semaines) et **fusionner** (assembler deux
    segments adjacents — le serveur ne borne que contiguïté + enveloppe). Un segment
    multi-semaines porte une phrase pédagogique sur le sur-ferme du solveur si ses semaines
    diffèrent. La carte de couverture (radar, `DayDialog`) regroupe les créneaux par enfant
    (`groupCoverageSlots`) — une puce par segment ; la puce « + créer » d'une semaine manquante
    reste à la semaine. Le libellé omet l'année dans la saison affichée (`segmentLabel`,
    `lib/date.ts`) — le nom SERVEUR du plan n'est pas touché, seul cet affichage.
  - **Le choix d'ouvrir ce picker** vit dans une seule fonction pure, `decideWeekAdapt`
    (`lib/useWeekAdapt.ts`), partagée par le radar et le `DayDialog`. Elle couvre : une seule
    semaine calendaire ou une mère déjà découpée (dont la carte de couverture gouverne) → bloc
    direct ; plans/plannings/enfants pas encore résolus → le picker s'**ouvre et le dit**, plutôt
    que de partir en bloc en silence ; le plan de bloc porte déjà une version → le picker le
    **nomme**, garde « Continuer d'un bloc », et — si ce bloc n'est **pas validé** — propose une
    découpe destructive confirmée qui nomme sa portée (versions supprimées, réglages qui repartent
    de la saison) ; un bloc **validé** n'offre pas ce bouton et renvoie vers Rouvrir→Supprimer ; le
    bouton est désactivé, avec sa raison, pendant une génération en vol. Les deux surfaces qui
    ouvrent ce picker (« Ajuster »/« Adapter » une fermeture depuis la liste du jour, la carte
    d'indisponibilité du gymnase du radar) matérialisent l'entrée SEULEMENT à la confirmation —
    annuler ne laisse aucun fantôme ; « Ajuster » (liste du jour) est **asynchrone** (POST du plan
    de période idempotent avant de naviguer).
  - **Le découpage d'une FERMETURE n'est pas libre** — une closure se décompose en au plus trois
    segments IMPOSÉS : **début** (semaine entamée de tête), **milieu** (semaines pleines lun→dim
    contiguës, UN SEUL plan — un trou de vacances ou une fenêtre déjà planifiée coupe le milieu en
    deux runs) et **fin** (semaine entamée de queue) — jamais une semaine complète isolée. Les
    VACANCES gardent scinder/fusionner librement. Le calcul est PUR et GÉOMÉTRIQUE
    (`cockpit/lib/weekSegmentation.ts::weekSegments`, miroir mécanique de
    `App\Service\WeekSegmentationRule::segments`, gardé par un test de parité). **« Adapter toute
    la période d'un bloc » se DÉSACTIVE avec sa raison** dès qu'une fermeture compte plus d'un
    segment : « Cette indisponibilité a une semaine entamée — adaptez-la par début, milieu, fin. »
    Le serveur GARDE la règle aux deux portes qui comptent (`SchedulePlanStateProcessor::
    processPost`, `CalendarEntryStateProcessor::assertValidWeekChild`) — le front n'affiche que la
    conséquence. Re-dater une racine CLOSURE d'un bloc refuse une nouvelle fenêtre qui se
    décomposerait en plus d'un segment ; une mère déjà découpée en enfants n'est, elle, pas
    re-datable par ce mécanisme. Détail : [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md).
  - **Le picker EXCLUT les semaines gouvernées par des vacances** : quand une indisponibilité de
    gymnase (`closure`) chevauche des vacances, `WeekPickerDialog` n'offre plus ces semaines-là —
    **exclues, pas grisées** — avec une ligne d'info renvoyant au planning de vacances. Une
    semaine est exclue **ssi** son lundi est dans une fenêtre de vacances **et** que la vacance
    couvre TOUT son lundi→vendredi (`holidayCoversWorkweek`, `lib/holidayWorkweek.ts` ; le
    week-end ne compte pas, un jour hors saison compte comme couvert). Dès qu'une exclusion
    existe, le picker s'ouvre **toujours** (jamais de bloc direct) et le chemin « adapter d'un
    bloc » disparaît — un plan de bloc gouvernerait la fenêtre des vacances, ce que la garde
    d'unicité de fenêtre refuse par ailleurs. Cas **100 % sous vacances** (aucune semaine offerte) :
    ligne d'info seule ; si l'indispo n'est pas encore en base, un bouton **« Consigner
    l'indisponibilité »** crée le FAIT sans plan ni navigation (sinon le rappel promis par la
    ligne d'info n'existerait nulle part). Cette règle d'offre vit côté FRONT sur les données
    servies, en **miroir déclaré** d'un calcul backend réel (`App\Service\HolidayWorkweekRule::
    covers`), gardé par un test de parité mécanique. Par API directe, une semaine peut toujours
    naître sous des vacances SANS plan — le filet reste la garde d'unicité de fenêtre (409
    `window_already_planned`) dès qu'un PLAN existe.
  - **La carte de couverture d'une fermeture applique la même exclusion** : le dénominateur ne
    porte que les semaines **AJUSTABLES**, et une semaine gouvernée par des vacances s'affiche
    **grisée** avec sa raison, jamais cliquable — un enfant déjà créé sur cette semaine reste,
    lui, ajustable. La carte ne s'affiche plus pour une semaine sous vacances non couverte — le
    rappel vit dans le planning des vacances (§5.1).
  - **L'été s'adapte comme les autres vacances** : les dates sont clampées à la saison. Seul cas
    restant sans « Adapter » : une fenêtre entièrement hors de la saison de travail — la modale
    l'explique au lieu d'afficher un bouton mort.
- **Date avec entrée(s)** → le popover **liste** ce qui est là (voir / éditer / supprimer). Une
  indispo/période porte un **« Adapter → »** qui ouvre l'**écran dédié**.
  - **« Modifier les dates de … »** re-date une fermeture d'un bloc **sans la reconstruire** —
    bouton dédié à gauche de Supprimer dans la liste du jour (`DayDialog.tsx`), **rendu SEULEMENT
    si le serveur sert `entry.redatable`** (prédicat unique côté backend,
    `App\Service\CalendarEntryRedatability::isRedatable`), jamais recalculé côté front. Le mode
    `redate` réutilise les mêmes champs de plage que les créations, avec un plancher
    `min(aujourd'hui, début déjà servi)` — une fermeture déjà commencée peut bouger sa fin sans
    bouger son début — et un plafond fin-de-saison ; ces bornes sont de la présentation, le 422
    serveur reste le juge. « Enregistrer » est désactivé tant qu'aucune date n'a changé ou que la
    fin précède le début. Un conflit de fenêtre (409) s'affiche **à l'endroit du geste**
    (`WindowAlreadyPlannedNotice`, valeurs conservées, focus sur « Ouvrir le planning en place ») ;
    tout autre refus part au filet global. Un succès invalide les lectures dérivées de la fenêtre,
    ferme le dialogue et annonce « Fermeture re-datée du … au … — planning à régénérer » (jamais
    de mention du pivot socle dans ce toast, décision fermée `etat-des-lieux.md` §2) : la version
    pointée survit ; la bannière de `/planning` la signale comme obsolète (`stalenessMessage`,
    `PlanningPage.tsx:634`).
  - **Le cockpit affiche lui-même la péremption d'une version** : `SchedulePlanResource.staleness`
    sert la péremption de la version **POINTÉE** par le plan — `null` tant que rien n'est pointé,
    ou dès que la fenêtre du plan est révolue (horloge serveur : pas de faux « à régénérer » sur
    du passé). Le front **affiche sans redériver** (`StalenessPill`) sur quatre surfaces : la
    carte Saison, le radar, la modale « Tous les plannings » et la ligne du jour. Pastille **non
    cliquable** (chaque surface porte déjà son CTA).
  - **Sur une indisponibilité déjà découpée** (mère `closure` segmentée en début/milieu/fin,
    exclusif de `redatable`), « Modifier les dates de … » passe par un **aperçu puis
    confirmation** : le bouton s'intitule « Voir les effets » tant qu'aucun aperçu n'est chargé,
    puis rend la liste des effets servie (une ligne par enfant + par plan de vacances recoupé),
    dans une région annoncée aux lecteurs d'écran. Dès qu'un effet supprime un planning, la liste
    est encadrée d'un avertissement et le bouton devient « Confirmer ». Toute retouche de date
    après coup **périme l'aperçu** (retour à « Voir les effets ») ; un jeton expiré redemande
    l'aperçu automatiquement, la confirmation restant **manuelle**. Succès → même toast
    « … — planning à régénérer » (variante « plans de période ajustés » si l'aperçu portait une
    suppression). Détail : [ADR-0002](../../docs/architecture/adr-0002-pattern-plan.md) ·
    [`types-de-planning.md`](types-de-planning.md) §2.
- **L'écran dédié « calendrier secondaire »** = le wizard réutilisé en « mode période » (§6bis) :
  les mêmes 6 étapes, mais le roster/les gymnases restent **hérités** (non ré-éditables comme
  entités) — on les **surcharge pour la fenêtre** (équipe on/off + séances) via un DIFF ancré au
  **plan** de la période, la grille de gymnases étant, elle, **copiée puis possédée** par la
  période, en plus des contraintes + la génération. Une modale serait trop à l'étroit ; réutiliser
  le wizard évite tout réapprentissage.

Le geste unique « cliquer une date » ouvre donc le bon niveau selon le besoin : une note rapide
(modale) ou l'atelier de génération (le wizard en mode période).

**La prévention du même refus.** `WeekPickerDialog` ne propose pas une semaine dont la création
serait refusée : le serveur sert le verdict (`GET /api/planned-windows`), l'écran l'affiche. Les
semaines gouvernées par un autre plan quittent les cases à cocher et sont nommées au-dessus de la
liste (`WindowAlreadyPlannedNotice`, reprend la phrase servie telle quelle + raccourci « Ouvrir le
planning en place ») — une ligne désactivée aurait obligé le front à inventer une frontière de
segment (l'unité de la liste est le SEGMENT, pas la semaine). « Adapter toute la période d'un
bloc » reste désactivé avec sa raison, jamais caché, y compris pour le cas vacances. Sur une
erreur de lecture, le front est **fail-open** (offre tout, le pire cas redevient le 409 normal) —
un fail-closed bloquerait un geste légitime sur une panne transitoire. Portée : la modale seule —
les autres chemins de naissance gardent le 409 comme unique traitement.

**Refus de chevauchement sur « Adapter ».** Le geste « Adapter » — popover d'une date,
`WeekPickerDialog`, carte du radar — est sous la garde serveur « une seule planification par
fenêtre » (`PeriodWindowUniquenessGuard`) : un 409 `window_already_planned` s'affiche **à
l'endroit du geste**, dans le dialogue qui l'a déclenché (`WindowAlreadyPlannedNotice`), reprenant
le message serveur **tel quel** (il nomme la période en place, sa fenêtre et les trois issues :
modifier / supprimer ce planning / découper la période en semaines) — le front n'en redérive rien
(règle d'or, `frontend/AGENTS.md`). Le bloc n'offre qu'un raccourci, « Ouvrir le planning en
place » : **aucun bouton de suppression**, le geste destructif garde sa maison
(`DeletePlanningButton`). Le hook partagé (`useWeekAdapt`) tait ce refus typé pour laisser le
dialogue l'afficher, et laisse le filet global toaster tout vrai échec transport.

> **Ex.** « AG le 12 mai » → clic, popover, titre + toggle informatif, enregistré, je reste sur le
> cockpit. « Gym Barros fermé la semaine du 4 » que je veux résoudre → clic, « Adapter → » →
> plein écran = wizard mode période, structure surchargeable pour la fenêtre, contraintes ouvertes.

## 6. Le calendrier secondaire = un overlay borné, pas une alternative plein-saison

Clic sur une indispo/période → **« Adapter cette période »** → on **régénère uniquement la
fenêtre** concernée, avec l'exception appliquée (gym fermé / template vacances), produisant
un **plan secondaire** qui **surcharge la base sur ces dates seulement**.

> Un calendrier secondaire n'est **pas** un deuxième planning de saison complet. C'est un
> **delta de période** : la base partout, le secondaire là où la période l'écrase. Le
> calendrier affiche la base, et « bascule » sur le secondaire sur la fenêtre.

Ça évite l'explosion combinatoire des « plans alternatifs » et colle au geste réel :
« pour ces 2 semaines, c'est différent ».

**État intermédiaire — indispo signalée mais pas encore adaptée.** Tant qu'aucun plan secondaire
n'est généré, la base continue de « vouloir » placer les séances dans le gym fermé. Ces séances
en conflit sont **affichées en alerte** (« à replacer — salle indispo »), et le **radar** propose
**[ Adapter ]**. **Rien ne bouge tout seul** : c'est un **problème visible non résolu**, pas une
erreur silencieuse — résolu en générant l'overlay.

> **La forme de la réponse `/conflicts`.** `GET /api/calendar-entries/{id}/conflicts` rend
> `{ entryId, venueIds, conflicts, closures, seasonPlanChosen }`. `closures` liste chaque
> fermeture recoupant la fenêtre de l'entrée — servie sur **toutes** les sorties, y compris
> `seasonPlanChosen=false` : une fermeture est un fait déclaré, indépendant de l'existence d'un
> calendrier à comparer, contrairement à `conflicts` (séances à replacer) qui, lui, dépend du
> plan choisi. Surfacée dans les trois écrans concernés — créneau BARRÉ + libellé « Indispo du X
> au Y — titre » au grain JOUR — sur les grilles Gymnases/Réserver, `PeriodVenues` et le récap.
> Détail : [`frontend-wizard.md`](../../frontend/docs/frontend-wizard.md).

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
> (copie des créneaux de saison à la naissance du plan — voir la ligne Gymnases ci-dessous).
> Modèle : [`../../docs/architecture/adr-0002-pattern-plan.md`](../../docs/architecture/adr-0002-pattern-plan.md) inv. 5.

| Étape wizard | En mode période |
|---|---|
| Équipes | Roster **hérité** (non ré-éditable), mais **activable/désactivable** pour la période + **séances** surchargeables (champ 1–7, toggle = 0 séance). **Défaut conscient du type de période** : **reprise** (`holiday`) = **Fanion + importantes** (les 2 premiers rangs, S+A) pré-cochées, avec repli sur le meilleur rang réellement présent si le club n'a ni S ni A — la reprise n'est jamais vide ; **fermeture** (`closure`) = **tout le club actif** (structure verrouillée, les équipes loisir se décochent à la main). |
| Gymnases | **La période possède SA grille** : les créneaux de saison y sont **copiés** à la naissance du plan, puis modifiables sans jamais toucher au planning principal — aucune union entre le socle et la période. Fermetures datées marquées au **grain JOUR** (`frontend-wizard.md` pour le détail). Un **sélecteur de gymnase** (une grille à la fois) montre la grille servie à la période, éditable créneau par créneau (clic = poser, clic sur un créneau = modale jour/heure/durée/capacité + suppression confirmée) ; chaque option porte, en sous-ligne sous son nom, l'état effectif — « désactivé » / « indisponible toute la période » / « fermé {jours} » (masque manuel OU indispo déclarée, priorité désactivé > fermé total > fermé partiel) ou rien s'il est ouvert. La modale d'édition d'un créneau dit si le jour est fermé (cause nommée) — poser reste **permis**, l'éditeur se contente de le dire. Les deux lisent l'état effectif SERVI, jamais recomposé côté front. Par gymnase : un état actif/désactivé qui ne touche jamais la grille, et deux actions destructives atomiques (« reprendre la grille du planning principal », « vider »), chacune confirmée en nommant les réservations emportées ; un gymnase désactivé a sa grille **gelée** (créneaux non éditables) — réactiver la rend telle quelle. Sous le capot : réglage épars `VenuePeriodOverride` — `mode` NULLABLE (DISABLED/BLANK/hériter) et un **masque manuel** jour ISO 1..7 → OPEN\|CLOSED (`dayOverrides`), qui s'ajoute au défaut jour par jour. Une **fermeture datée est INFORMATIVE** : elle PRÉ-REMPLIT le défaut, que le masque du plan peut contredire (jour rouvert `OPEN`, jour décoché `CLOSED`) — la composition (incident × masque) vit dans la maison unique `PlanVenueClosures::effectiveStateForPlan/Entry`, partagée par TOUS les consommateurs (gate, payload, `OrphanPinGuard`, réservations, radar). Un épinglage qui ne retombe sur aucun créneau **bloque la génération** en nommant le gymnase, le jour et l'équipe — sauf sur un gymnase désactivé ou **effectivement fermé-total**, où il devient inerte ; un jour effectivement fermé d'un gymnase par ailleurs ouvert reste bloquant, un jour rouvert par le masque ne l'est plus. Réactiver un gymnase entièrement fermé (POST/PUT ou DELETE de son override) est **accepté** : DELETE purge mode ET masque, retour complet au défaut. **Écran** : sous le sélecteur, une **rangée de 7 coches jour** lit l'état EFFECTIF servi (`effectiveClosedWeekdays`) — cochée = ouvert, décochée = effectivement fermé — avec sa **provenance** en info-bulle (indisponibilité déclarée / décoché manuellement / réactivé malgré l'indisponibilité) ; cliquer une coche écrit l'opposé de l'état effectif dans le masque, ou retire l'entrée existante (retour au défaut), jamais de recomposition côté front. L'interrupteur Désactiver/Réactiver reste visible même sur un gymnase entièrement fermé (le serveur accepte le geste) — la raison s'affiche en badge d'information, à côté de l'interrupteur, jamais à sa place. Sous la rangée de coches : « Réactiver malgré l'indisponibilité » (pose `dayOverrides` = OPEN×7, mode préservé) et « Revenir au défaut » (DELETE, purge mode ET masque). Sous **DISABLED**, la rangée est gelée (coches conservées, réactiver ne perd pas le masque manuel posé avant). **Grain semaine-type** : décocher/cocher un jour vaut pour **toutes les semaines de la période**, pas une date précise. Un bandeau au-dessus du sélecteur liste les gymnases indisponibles (déclaré) ET ceux portant des jours décochés à la main. Le **récap** lit le même état effectif pour les réservations non servies et le motif affiché, plus les fermetures brutes pour le titre. ⚠ **Point contre-intuitif, vérifié au code** : la grille d'un gymnase entièrement fermé reste **modifiable** (créneaux, Reprendre/Vider) — seule la réservation est refusée en 422 au grain jour effectif, jamais un geste de grille ; le front serait sinon plus strict que le serveur (règle d'or). Point tranché, `etat-des-lieux.md` §2. |
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

## 9. Tranché vs ouvert

**Tranché** : le modèle décrit dans ce document (§2 à §6ter, §9ter) est l'architecture livrée —
aucun point n'est rouvert. Une décision qui n'est pas déjà l'état du code ci-dessus (un abandon,
un arbitrage tranché contre une option qui paraissait évidente) vit dans
[`etat-des-lieux.md`](etat-des-lieux.md) §2, jamais recopiée ici.

**Ouvert** — aucun point d'architecture ne reste à trancher ici ; les évolutions produit encore
ouvertes (radar, collecte coach, calendriers secondaires) vivent dans
[`specs/evolution/roadmap.md`](../evolution/roadmap.md).

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
  parentEntryId     uuid?        -- semaine ENFANT d'une période mère
  createdBy, createdAt, updatedAt
```

> **L'entrée ne porte pas de pointeur d'overlay.** Le plan secondaire est un `SchedulePlan` ancré
> à la `CalendarEntry`, et c'est **lui** qui pointe sa version (`chosenScheduleId`) — une seule
> maison pour « quelle version est active ». Lecture : `SchedulePlanProvisioner::chosenOfPeriodPlan`,
> ou `chosenByPeriodPlans` pour résoudre N entrées en une requête. Un plan de période qui ne pointe
> rien = **aucun overlay applicable**, pas un overlay vide.

### c. La réutilisation maligne (≈ zéro nouvelle table à part `CalendarEntry`)

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

### e. L'arbitrage « additive vs remplaçante »

Ni l'une ni l'autre : **la période est PROPRIÉTAIRE.**

- **Créneaux** : la période possède sa grille — copie du modèle de saison prise à la naissance du
  plan (ancre `schedulePlanId`), **jamais unie** au socle. Le build d'overlay ne lit que les
  créneaux du plan. Détail des réglages par gymnase (`VenuePeriodOverride`, indisponibilité
  informative) : §6bis, ligne Gymnases.
- **Contraintes permanentes** : c'est là, et seulement là, que `periodType` porte encore une
  sémantique d'héritage. `closure` → toutes gardées (le gestionnaire décoche) ; `holiday` →
  défaut intelligent qui suit la sélection d'équipes (club/coach gardées, équipe gardée si
  l'équipe reprend, gymnase décochée). Diff épars `ConstraintPeriodOverride` : une ligne
  n'existe que pour une **déviation** du défaut.
- **Contraintes datées** : portées par la `CalendarEntry` — le **fait** (« Barros fermé ») par
  la mère, la **genèse** d'une semaine par son entrée-enfant (modèle FAIT/GENÈSE, §c) ; jamais
  décochables par plan.
- `cutoff` et `mutualisation` ne portent pas de plan de période.

Corollaire : un épinglage HARD qui ne retombe sur aucun créneau de la grille de la période
**bloque la génération** (422 nommant le gymnase, le jour et l'équipe, `OrphanPinGuard`), sauf sur
un gymnase désactivé ou effectivement fermé-total — dans un modèle additif il aurait
silencieusement retrouvé un créneau de saison.

## 10. En une phrase

L'accueil devient le **cockpit temporel** du club : la **semaine type** reste la base (derrière
le bandeau), le **calendrier** montre **la timeline des exceptions** (événements, indispos,
périodes, vacances) — un jour vide = tout roule — et un **radar** dit ce qui arrive. Chaque souci
daté devient, en un clic, une **période** adaptée dans **le même wizard, en mode allégé**, qui
produit un **overlay borné**. On ne matérialise jamais l'ennuyeux ; on ne crée que là où la
réalité diverge. Côté données : **`CalendarEntry` + 2 FK** sur l'existant, presque rien de neuf.
