# Gestion des matchs (FFBB) — besoin spécifié

> **Statut** : **besoin spécifié** (exploration terrain 2026-07-06) — **référence de la ligne roadmap P1-4 (FF#21)**.
> **Pas un plan** — pas de tâches, pas d'effort chiffré ; l'exécution se planifiera palier par palier (§9).
> **Nature** : ce document cadre un **module produit à part entière** (placement des matchs, radar de
> conflits, demandes de dérogation), distinct du module d'entraînement, et **challenge** le classement
> d'origine `🔴 lourd / V2` de la roadmap : le cœur n'est **pas** un solveur.
> **Rattachement roadmap** : **P1-4** (FF#21 « Planification des matchs »,
> FF#19 « Import calendrier de matchs FFBB »). **Vision d'origine** : `initiales/ClubScheduler_v3.md` §1.4.
>
> ⚠ **AMENDÉ le 2026-08-02 par [`p1-4-cadrage-module-matchs.md`](p1-4-cadrage-module-matchs.md)** (cadrage
> tranché sur un vrai export FBI). Points renversés : **le solveur PLACE les matchs domicile** puis le
> gestionnaire ajuste (le §2/§12 « pas un solveur / placement manuel » ne fait plus foi) · l'import FBI est
> un **fichier GLOBAL club** (pas par équipe — §12 « export FBI par équipe » ne fait plus foi) · le tracker
> dérogation (§8) sort de P1-4 (l'outil alerte, le gestionnaire agit). Le reste (empreinte-temps §4bis,
> catalogue-ligue §6bis, annuaire adverse §5bis, trajet §7) reste la référence.

---

## 1. Le problème (terrain)

La FFBB — via la **ligue** (compétitions régionales) ou le **comité** (départementales) — impose à chaque
club un calendrier de matchs par équipe. Le gestionnaire ne **place** que ses matchs **à domicile** : leur
donner une **heure + un gymnase** sur la date (journée) imposée, puis **répondre à la ligue**. Les matchs à
l'extérieur, il ne les place pas — mais ils **comptent** (une personne est prise ce jour-là).

Ce n'est **pas un problème d'optimisation** : la date est imposée, le degré de liberté est petit (heure +
salle sur une date fixée). Le gestionnaire **place à la main** ; la valeur est ailleurs :

> **Voir les conflits tôt → préparer/suivre les demandes de dérogation → négocier à l'amiable, avec du
> temps devant.**

**Ex. terrain.** SM2 placé à 16h le dimanche, mais RF3 joue à l'extérieur à 15h30. La même personne est
coach/joueur des deux → **on ne peut pas assurer les deux**. La date est imposée : on ne re-place pas
librement, on fait une **demande de dérogation** (processus ligue) et on négocie. La plus-value : on l'a vu
**des semaines avant**, on a le temps de réagir.

---

## 2. Le reframe central (challenge du `🔴` d'origine)

> ⚠ **Renversé le 2026-08-02** (`p1-4-cadrage-module-matchs.md` §2) : le solveur place, le gestionnaire
> ajuste — le tableau ci-dessous reste utile pour la différence de nature (dates réelles vs semaine type).

> **Les matchs ne sont PAS un problème solveur. C'est un module « placement daté + radar de conflits +
> workflow dérogation ».**

| | Entraînements (livré) | Matchs (ce besoin) |
|---|---|---|
| Dates | semaine type récurrente, **sans date** | **dates réelles imposées** par la FFBB |
| Placement | le **solveur CP-SAT** optimise | **le gestionnaire, à la main** (DOF = heure + salle) |
| Valeur | optimiser un objectif mou | **détecter des conflits + agir tôt** |
| Objet technique | `ScheduleSlotTemplate` (hebdo, sans date) | événement **daté** (nouvelle entité) |

Conséquence : le coût réel n'est **pas** un nouveau moteur, mais **(a)** un modèle de données matchs,
**(b)** un moteur de règles/conflits, **(c)** une grille datée par gymnase. Les dépendances lourdes (import
FFBB, matrice trajet) sont des **enrichissements progressifs**, pas des prérequis bloquants. → **Le `🔴`
monolithique se livre en paliers (§9).**

**Le solveur garde un rôle optionnel V2** : « auto-place-moi ce week-end au mieux » (K matchs, G gyms, sous
contraintes jour + personne + trajet) **est** un petit CP-SAT réutilisant le moteur. **Hors cœur** — la
valeur ressentie est le radar, pas l'auto-génération.

---

## 3. La colonne vertébrale de valeur

Le placement n'est que **l'entrée**. Trois couches :

| Couche | Rôle |
|---|---|
| **Entrée** | placement des matchs domicile (+ amicaux) sur la grille datée |
| **Moteur** | **détection de conflits** (le radar) |
| **Sortie / action** | **dérogation** : brouillon + suivi d'état + deadline ligue |

Le calendrier n'est que la **surface**. Le **moteur de conflits + le workflow dérogation** = ce qui se vend.

---

## 4. Le moteur de conflits = no-overlap PERSONNE (réutilise l'existant)

Le conflit atomique n'est **pas** « deux matchs à la même heure ». Deux matchs simultanés d'équipes qui **ne
partagent rien** ne sont **pas** un problème. Le conflit naît d'une **ressource partagée** — et sur le
terrain, cette ressource est **la dualité coach/joueur** :

> **Une personne ne peut pas coacher et jouer en même temps, ni coacher/jouer deux équipes en même temps.**

C'est **exactement** `COACH_NO_OVERLAP` + `COACH_PLAYER_NO_OVERLAP` (`backend/src/Enum/ImplicitConstraint.php`),
déjà codées pour l'entraînement, gardées par l'invariant Hypothesis « coach-joueur cohérent » (roadmap §7).

> **Un match n'est qu'un nouveau TYPE D'ÉVÉNEMENT sur la même timeline de disponibilité des personnes.**
> Le moteur de conflits matchs **réutilise** le no-overlap personne. Aucune nouvelle sémantique de conflit
> à inventer — on étend la timeline aux événements datés.

**Le seul ajout vs l'entraînement = la dimension SPATIALE.** L'entraînement est tout au club (temps pur).
Les matchs traversent des villes → le no-overlap devient **« pas de chevauchement + trajet faisable entre
deux événements consécutifs »**. Ex. : U13 domicile 14h puis SM1 extérieur à 40 min de route → **infaisable**
même sans chevauchement horaire strict. C'est là qu'entre la **matrice trajet** (§7).

### 4bis. L'empreinte-temps d'un match (tranché — le calcul du moteur)

> **Décision terrain (2026-07-06) : un match occupe une personne plus longtemps que sa durée de jeu.**
> C'est l'atome que le moteur de conflits chevauche entre coachs/joueurs.

- **Match = 1h45 (105 min) de jeu + 30 min d'échauffement** avant le coup d'envoi.
- **Domicile** : empreinte = échauffement (30) + match (105) = **2h15**, de `kickoff−30` à `kickoff+105`.
- **Extérieur** : en plus, **trajet aller-retour** + **30 min de douche** + **15 min de battement** (se
  changer). L'empreinte = `kickoff − (trajet_aller + 30)` → `kickoff + (105 + 30 + 15 + trajet_retour)`.
  Le trajet vient de la **matrice (§7, palier B)** ; d'ici là il est **paramétré (0/estimé)**, les parts
  fixes (échauffement/match/douche/battement) étant calculées dès le palier A.
- Livré palier A PR-1 : service **`MatchFootprint`** (constantes ci-dessus, trajet injecté), testé
  unitairement. Le chevauchement inter-personnes (le radar) = **PR-2**.

> ✅ **Point de branchement identifié** : l'entité **`CoachPlayerMembership`** (`backend/src/Entity/
> CoachPlayerMembership.php` — `coachId` + `teamId` + `position`, tenant-owned) modélise déjà « cette
> personne (coach) joue dans cette équipe ». Le no-overlap personne des matchs **branche dessus** : une
> personne prise par un match de son équipe **coachée** (via `TeamCoach`) **ET** un match de son équipe
> **jouée** (via `CoachPlayerMembership`) sur des créneaux qui se chevauchent (ou trajet infaisable) = conflit.
> Le concept **et** le modèle sont déjà en base.

> ✅ **Livré palier A PR-2 (2026-07-07) — périmètre coach seul, à la volée.** Service pur
> **`MatchConflictDetector`** + endpoint `GET /api/fixtures/conflicts` (recalcul live, rien persisté ;
> gradué dans [`../courantes/module-matchs.md`](../courantes/module-matchs.md)). Croise via **`TeamCoach`** :
> **`MATCH_MATCH`** (deux matchs d'un même coach qui se chevauchent) et **`MATCH_TRAINING`** (match ↔
> entraînement d'une équipe du coach, lu dans le **planning effectif à la date** — overlay de la période
> ACTIVE si elle couvre la date, sinon la **version choisie du plan SEASON** — créneau hebdo projeté sur le jour du match). Le volet
> **joueur** (`CoachPlayerMembership`) et l'**envelope HARD** (jour/coup d'envoi imposé §6) restent à venir ;
> le **trajet** (dimension spatiale) est palier B → un match AWAY sans coup d'envoi estimé ne produit pas
> encore de conflit.

**Autres conflits (non-personne), à couvrir aussi :**
- **Gymnase** : deux matchs domicile sur le même terrain qui se chevauchent (le `VENUE_AT_MOST_ONE` existe).
- **Jour imposé** : un match placé un autre jour que celui imposé par la ligue (§6).
- **Officiels/bénévoles** : *hors périmètre V1* — non retenu comme ressource de conflit (le terrain a
  tranché : le conflit structurant est la dualité coach/joueur). À rouvrir si un club pilote le demande.

---

## 5. Deux besoins de données à NE PAS confondre + effet réseau

1. **La LISTE des rencontres** (qui joue qui, quelle journée, domicile/extérieur, par équipe et par phase).
   Vient de la **ligue/FFBB**, jamais des autres clubs. Sans elle, on ne sait même pas qu'une équipe reçoit.
   **Forme concrète (tranchée)** : un **export FBI par équipe** (fourni par le gestionnaire), **ajouté un à
   un** — **même format** à chaque fois → un seul parseur, appelé par équipe. Pas d'export club global à
   attendre. Dé-risque l'import (patron `FfbbExcelImporter` + format unique connu).
   > ✅ **Livré palier A PR-4 (2026-07-07) — sur FORMAT SUPPOSÉ** (aucun export réel disponible ; colonnes
   > actées : Division/Numéro/Équipe 1/Équipe 2/Date de rencontre/Heure/Salle — **à valider contre un vrai
   > fichier avant fiabilisation**). `FbiFixtureImporter` + `POST /api/teams/{id}/fixtures/import` + dialog
   > d'upload, équipe choisie à l'upload. Gradué dans
   > [`../courantes/module-matchs.md`](../courantes/module-matchs.md).
2. **L'HEURE + la LOCALISATION des matchs extérieurs** (le « 15h30 » et « au gymnase du Clar »). Vient : (a)
   **de la plateforme** si l'adversaire est client et a saisi, (b) **estimée** par tendance sinon, (c) FFBB.

> Le cross-club auto-remplit **l'heure et la position**, jamais **l'existence** de la rencontre.
> « Une fois le championnat saisi par tous » résout les heures/positions, **pas** la liste (toujours FFBB).

### 5bis. L'annuaire adverse = table GLOBALE, enrichie par tous les clubs

« Plus on rencontre d'adversaires, plus on connaît leur position. » Le club de Meyzieu a saisi qu'il reçoit
au gymnase du Clar → **l'app connaît la position du Clar** → quand un autre club joue contre le Clar, elle la
donne **sans travail**. Trois enrichissements, **un seul annuaire** :

1. **Localisation** adverse (pour le trajet) — **on stocke directement le gymnase précis** (tranché : plus
   simple à terme que ville-puis-affinage). Le trajet reste tolérant (« < 15 min entre gymnase A et B, on
   s'en fiche »), mais la donnée de base est le lieu exact, pas une approximation ville à raffiner ensuite.
2. **Tendances** horaires adverses (« le Clar joue le samedi soir »).
3. **Heures extérieures précises** (si l'adversaire est client et a saisi sa rencontre).

> **Architecture : annuaire adverse = table GLOBALE (hors tenant), enrichie par l'usage**, exactement comme
> `school_holidays` / `public_holidays` déjà en place (données de référence globales, seedées + enrichies).

> ⚠ **Garde-fou sécurité (critique vu l'isolation tenant du projet, cf. `docs/security/rls.md`)** : cet
> annuaire **traverse délibérément le tenant** → il ne doit contenir que du **public** (adresse/ville d'un
> gymnase FFBB, déjà publiée ; heure d'un match publiée). **Jamais** de donnée privée club (dispos internes,
> créneaux d'entraînement, contraintes). Précédent propre : les tables fériés/vacances sont déjà globales et
> ne portent aucune donnée club. On calque ce patron. **Un test d'isolation dédié devra le garder.**

### 5ter. Dynamique d'adoption (bonne nouvelle)

Valeur **solo dès le départ** (conflits domicile-vs-domicile + jour imposé + heures extérieur **estimées**)
→ valeur **réseau croissante** (heures/positions extérieures précises à mesure que des clubs rejoignent).
Chaque club gagne **seul** + bonus collectif → **pas de chicken-egg bloquant**. Mais **la liste des
rencontres reste à importer** quel que soit le réseau.

---

## 6. Contraintes & préférences (réutilisent le vocabulaire existant)

- **Jour + fenêtre horaire imposés par catégorie/niveau** (« U18 Région = dimanche 10h–16h30 ») = contrainte
  **HARD** imposée par la ligue, **pré-seedée** via le catalogue-ligue (§6bis) — pas saisie à la main.
  Réutilise le vocabulaire `Constraint` (family `DAY` + `TIME`, ruleType `HARD`) mais **appliqué au placement
  manuel** (l'UI bloque / le radar signale la violation hors envelope), **pas** au solveur. Le gestionnaire
  « fait sa magie » = arbitre sous contraintes, comme il l'a fait sur l'entraînement.
  ⚠ **Tension canevas** : la grille d'entraînement a **abandonné le dimanche** (décision roadmap §10). Les
  matchs le **réintroduisent** — le module match a un **calendrier week-end-centrique** (samedi/dimanche au
  centre), distinct du canevas lun-sam de l'entraînement.
- **Fenêtre-type de match par équipe** (« SM1 samedi soir », « U13 très tôt pour libérer le coach senior »)
  = **`PREFERRED TIME` par équipe, champ 1re classe** (tranché). Sert **deux fois** : (a) suggérer/valider le
  placement domicile, (b) **estimer l'heure d'un match extérieur** non contrôlé (pour le radar personne).
  Cheap, haute valeur.

---

### 6bis. Le catalogue-ligue = seed des fenêtres autorisées (patron « vacances scolaires »)

Chaque ligue/comité publie ses **fenêtres autorisées par catégorie × niveau** (jour(s) + plage de coup
d'envoi). C'est une **contrainte HARD imposée** : le gestionnaire place **dedans**. Décision produit :

> **On seede ces fenêtres comme un catalogue de référence** (comme `school_holidays` / `public_holidays` /
> `Service\Basketball\CategoryCatalog`). Le club **hérite** de la base de sa ligue à la création → **base de travail
> pré-remplie, éditable** : le gestionnaire narrow/préfère pour son club (« nos U15 toujours le samedi »),
> ce qui alimente le `PREFERRED TIME` par équipe (§6). **Il ne saisit jamais les règles ligue à la main.**

**Modèle** : table `LeagueMatchWindow` (ou catalogue) **globale**, clé = **ligue × catégorie × niveau
(× genre parfois)** → liste de `(jour, coup d'envoi min, coup d'envoi max)`. La **ligue du club** se dérive
du `ffbbClubCode` (département → ligue), même patron que `SchoolZoneResolver` (dép. → zone scolaire).
L'**envelope HARD** = union des `(jour, fenêtre)` du catalogue ; le **choix club** = un sous-ensemble
préféré. Le radar signale tout placement **hors envelope**.

**Seed initial — Ligue AURA (Auvergne-Rhône-Alpes)**, fourni par le gestionnaire 2026-07-06 (à préserver) :

*Niveau départemental :*

| Catégorie | Jours & fenêtres de coup d'envoi |
|---|---|
| U9–U11 | samedi 9h30–17h |
| U13 | samedi 13h–18h |
| U15 | samedi 13h–18h **ou** dimanche 8h30–16h |
| U18 | samedi 13h30–19h **ou** dimanche 8h30–16h30 |
| U21 | vendredi 20h–20h30 · samedi 13h–20h30 · dimanche 8h30–17h30 |
| Senior | vendredi 20h–21h · samedi 17h–21h · dimanche 8h–17h30 |

*Niveau région :*

| Catégorie | Jours & fenêtres de coup d'envoi |
|---|---|
| U13 Région | samedi 13h–18h |
| U15 Région | samedi 13h–18h30 **et** dimanche 10h–16h30 |
| U18 Région | dimanche 10h–16h30 |
| U18 Région **Garçon** | dimanche 10h–17h30 |
| U21 Région | dimanche 10h–17h30 |
| Senior Région | samedi 17h30–21h **et** dimanche 10h–17h30 |

**À noter (détails d'implémentation, non bloquants) :**
- « et » / « ou » = même sens pour l'**envelope** (union des jours autorisés) — une équipe joue **un** jour,
  le catalogue liste les jours **possibles** ; le gestionnaire tranche lequel.
- La fenêtre est un **coup d'envoi** (« vendredi entre 20h–20h30 » = tip-off dans cette plage), pas une durée
  de match — à distinguer d'une plage large type « samedi 13h–20h30 » (créneaux d'envoi étalés sur la
  journée). Le grain exact (coup d'envoi vs « match tient dans la plage ») se tranche au schéma.
- Le **genre** entre dans la clé pour certaines lignes (U18 Région Garçon). La clé du catalogue doit
  l'accepter (nullable = tous genres).
- **Le seed AURA est la base par défaut de TOUT club, pas seulement des clubs AURA.** Ces fenêtres sont
  des règles fédérales dans l'esprit : un club de Strasbourg (Grand Est) part de **cette base**, corrige
  les horaires si sa ligue diffère, puis ajoute **ses règles club**. **Jamais de page blanche.** Trois
  couches : (1) base fédé seedée (référence, non éditée) → (2) correction ligue par le gestionnaire si
  besoin → (3) règles propres au club (dont le `PREFERRED TIME` §6). Quand d'autres ligues seront
  cataloguées, la couche 1 devient plus précise (par ligue) — le patron ne change pas.

---

## 7. Le trajet = infra partagée avec l'entraînement (une pierre, deux coups)

Un adversaire = une **ville** → **temps de trajet siège club ↔ ville adverse**. C'est la même matrice trajet
que le module d'entraînement voulait déjà (FF#5, `venue_travel_times`) : elle sert **l'entraînement**
(gym→gym) **ET** les matchs (siège→ville adverse). **Un seul investissement, deux features** → priorité
relevée.

On stocke le **gymnase précis** de l'adversaire (tranché §5bis) ; le calcul de trajet reste tolérant (± 15 min
sans importance), mais pas d'approximation ville à raffiner.

---

## 8. Le workflow dérogation = mini-tracker daté (nouvelle brique)

> ⚠ **FERMÉ SANS CODE le 2026-08-25 (fondateur)** — « la dérogation n'est pas un objet de l'app,
> c'est juste la réponse à un conflit à résoudre avec le club adverse ; rien de plus chez nous ».
> Pas de deadline, pas d'états : le radar signale, le gestionnaire traite (déplacement manuel ou
> la ligue modifie), le ré-import/la vérification API constate, le conflit disparaît seul. Cette
> section reste en l'état comme trace du cadrage ; la décision vit au registre
> (`../courantes/etat-des-lieux.md` §2). Le volet « échéances de SAISIE », lui, a été livré (RMM-6).

Un conflit détecté → le gestionnaire tranche :
- **re-placer** (si domicile + marge disponible), ou
- **dérogation** : brouillon (quel match, créneau actuel, changement demandé, motif) → **envoyée** → **en
  attente** → **acceptée / refusée** → calendrier mis à jour.

État + **deadline ligue** (les dérogations ont une date limite) → alimente un **radar matchs** :
« 3 conflits · 2 dérogations à envoyer · deadline J-6 ». C'est le radar cockpit appliqué aux matchs : **voir
venir**.

> **Périmètre (challenge assumé)** : l'outil **prépare + suit** la dérogation. Il ne la **soumet pas** à la
> FFBB (pas d'intégration/API ligue ; la négociation amiable se fait par mail/téléphone, hors outil). C'est
> un **tracker + rédacteur de demande**, **pas** un connecteur ligue. Ne rien promettre de plus.

---

## 9. Modèle de données qui se dessine

**Nouvelles entités** (aucune n'existe aujourd'hui — `Team.matchDay` smallint est le seul vestige, il sert
au bonus « repos après match » du solveur et sera **superseded** par ce module) :

| Entité | Rôle |
|---|---|
| **Competition / Phase** | équipe + nom + début + fin + type (championnat / coupe / brassage / amical). **N par équipe** (une équipe peut gérer 1 à 3 championnats + coupe, fenêtres distinctes) |
| **Match / Fixture** | équipe + phase + date (journée) + **domicile\|extérieur** + adversaire + statut de placement + créneau (gym + heure, si domicile) |
| **Adversaire** | entité **globale** légère (nom club + **gymnase précis + coords**, enrichie par l'usage) → trajet + tendances |
| **Amical** | un Match sans phase FFBB, date + créneau **100 % au choix** du gestionnaire → réserve le slot gym |
| **Derogation** | match + créneau actuel + demande + motif + statut + deadline |
| **LeagueMatchWindow** (catalogue) | **globale**, clé ligue × catégorie × niveau (× genre) → `(jour, coup d'envoi min/max)`. Seed AURA (§6bis), dérivée `ffbbClubCode`→ligue. Envelope HARD héritée par le club, éditable |

**Réutilisé de l'existant** (le gros de la valeur vient du réemploi) :
- le **no-overlap personne** (`COACH_NO_OVERLAP` / `COACH_PLAYER_NO_OVERLAP`) → moteur de conflits (§4) ;
- le **vocabulaire `Constraint`** (jour HARD, fenêtre PREFERRED) (§6) ;
- la **surface calendrier** dates réelles + la **projection** de la semaine type (pour détecter un conflit
  match ↔ entraînement du même jour) — cf. `accueil-cockpit-temporel.md` ;
- la **grille interactive** (« créneaux vides + clic = placer ») — **3e usage** du même primitif après la
  grille de réservation pré-génération et la boucle d'ajustement (roadmap §4) ;
- la **matrice trajet** (§7) ;
- le patron **table globale + seed** des fériés/vacances → l'annuaire adverse (§5bis) **et** le
  catalogue-ligue des fenêtres (§6bis) ; le patron **dérivation `ffbbClubCode`** (`SchoolZoneResolver`) →
  dép. → ligue.

---

## 10. Positionnement produit : module AUTONOME en données, ouvert APRÈS le socle

Signal marché fort (terrain 2026-07-06) : un gestionnaire **peu intéressé par l'entraînement** (peu de
gymnases, tous les créneaux tiennent sans solveur) a eu **« les yeux qui brillent » pour les matchs**. → Les
matchs sont peut-être le **meilleur wedge de vente**.

> **Deux modules indépendants sur les mêmes entités club.** Un club peut vouloir la gestion des matchs sans
> se soucier du solveur d'entraînement, **et inversement**. Indépendance de **modèle** (entités séparées,
> rien de ce module n'entre dans le payload solveur) et d'**UI** (workspace « Compétition » distinct).

⚠ **Mais l'ouverture du module reste gatée sur le socle** — arbitrage fondateur du 2026-07-31 (DOC-1) en
faveur du code livré. Créer ou importer un match exige que le plan SEASON **pointe** une version
(`SocleGuard::assertSeasonPlanChosen` → **409** sinon ; le front verrouille l'entrée sur le même critère).
**Le motif** : un match se *place* dans un calendrier, et le radar de conflits (§8) le compare aux séances
d'entraînement — sans socle en vigueur, il n'a rien à comparer. Un club matchs-only génère donc **une fois**
son planning d'entraînement, même trivial, avant d'ouvrir la compétition. Cadrage vérifié :
[`../courantes/module-matchs.md`](../courantes/module-matchs.md).

⚠ **« Découplé » ≠ zéro setup (piège à éviter)** : un club matchs-only a **quand même** besoin d'équipes,
coachs, **liens coach↔équipe** (sinon pas de détection de conflit personne) et gymnases. C'est-à-dire **les
étapes structure du wizard (1-4)** ; seule la **génération d'entraînement** est sautable. « Module séparé »
= **workspace séparé sur les mêmes entités**, pas une appli à part.

**UI** : vue dédiée **« Compétition »** (calendrier week-end-centrique, bandes par phase/équipe), même
surface de dates que le cockpit dessous, mais **lentille différente**. Le cockpit montre les *exceptions à
l'entraînement* ; le calendrier compétition montre *la vie des championnats*.

---

## 10bis. Workflow réel en 2 temps (précisé terrain 2026-07-06)

1. **Temps 1 — résolution + réponse ligue.** Le gestionnaire **résout** ses matchs domicile (heure + salle)
   puis les **saisit un à un dans FBI** pour répondre à la ligue. Statut de placement du match :
   `UNPLACED → PLACED → SUBMITTED (saisi dans FBI) → VALIDATED (ligue confirme)`.
2. **Temps 2 — confrontation des soucis.** Une fois la ligue ayant **validé**, le championnat est complet →
   **confrontation des conflits coach/joueur** (le moteur, §4/§4bis).

> Nuance importante : le moteur **détecte dès la saisie** (domicile placés + extérieurs estimés), pas
> seulement après validation ligue — c'est **l'anticipation** (§1) qui est la valeur. Le jalon « ligue
> valide » **verrouille** le championnat ; il ne conditionne pas l'apparition des conflits.

## 11. Paliers de valeur (ordre, pas un plan)

- **Palier A — le placement + le radar solo.** Import FFBB de la liste des rencontres (+ ajout manuel).
  Modèle Competition/Phase/Match. **Catalogue-ligue seedé (AURA)** → envelope HARD pré-remplie, éditable par
  le club. Grille datée par gymnase (réemploi du primitif grille). Détection de conflits **personne**
  (domicile-vs-domicile, hors-envelope HARD, match ↔ entraînement projeté) sur données **du club**. Heures
  extérieures **estimées** par tendance. **→ Déjà énorme : le gestionnaire voit les soucis.**
- **Palier B — la dérogation + le trajet.** Workflow dérogation (brouillon + suivi + deadline + radar).
  Matrice trajet siège↔ville → conflits **spatiaux** (temps + trajet). Annuaire adverse global amorcé.
- **Palier C — l'effet réseau.** Auto-remplissage des heures/positions extérieures par le cross-club
  (annuaire enrichi par l'usage). (Option V2 : auto-placement CP-SAT d'un week-end.)

---

## 12. Tranché vs ouvert

> ⚠ Trois lignes de ce « tranché » sont **renversées ou amendées** par le cadrage 2026-08-02
> (`p1-4-cadrage-module-matchs.md`) : le solveur place (§2 y est renversé), l'import FBI est **global club**
> (pas par équipe), la dérogation reste tracker-free dans P1-4.

**Tranché :**
- Cœur = **placement manuel + radar de conflits + dérogation**, **pas** un solveur (solveur = assist V2).
- Conflit atomique = **dualité coach/joueur** (`COACH_(PLAYER_)NO_OVERLAP` réutilisés), branché sur
  **`CoachPlayerMembership`** (déjà en base) ; officiels/bénévoles **hors périmètre V1**.
- Ajout vs entraînement = **dimension spatiale** (trajet entre événements consécutifs).
- **Import FFBB dès V1** = **export FBI par équipe**, ajouté un à un, **même format** (patron
  `FfbbExcelImporter`) **+ ajout manuel** (amicaux, manquants).
- **Annuaire adverse = table GLOBALE hors tenant, publique-seulement**, enrichie par l'usage (patron
  fériés/vacances) — **garde-fou sécu + test d'isolation dédié obligatoires**. On stocke le **gymnase
  précis** de l'adversaire (pas d'approximation ville).
- **Fenêtre-type de match par équipe = `PREFERRED TIME`, champ 1re classe** (placement + estimation extérieur).
- Deux besoins de données distincts : **liste des rencontres** (FFBB) vs **heures/positions extérieures**
  (réseau/estimées) — ne pas confondre.
- **Jour + fenêtre imposés par catégorie×niveau = HARD**, **pré-seedés via un catalogue-ligue** (patron
  vacances/fériés) — le club **hérite et édite**, ne saisit jamais les règles. **Seed AURA capturé (§6bis)
  = base par défaut de TOUT club** (règles fédérales dans l'esprit ; un club hors AURA corrige, jamais de
  page blanche). 3 couches : base fédé → correction ligue → règles club. Ligue dérivée du `ffbbClubCode`.
  Genre dans la clé pour certaines lignes (U18 Région Garçon).
- Calendrier match = **week-end-centrique** (réintroduit le dimanche, distinct du canevas entraînement).
- **Module autonome en modèle et en UI**, mais **gaté sur la validation du socle** (arbitrage 2026-07-31,
  §10) ; il **réutilise la saisie structure** (un club matchs-only fait quand même les étapes
  équipes/coachs/gymnases, puis génère une fois son planning).
- Dérogation = **tracker + rédacteur**, **pas** un connecteur ligue (aucune soumission FFBB automatique).
- Le **trajet** est une infra **partagée** avec l'entraînement (FF#5) — une pierre, deux coups.

**Ouvert (non bloquant) :**
- Plus rien de structurant. Reste un **spike format d'export FBI par équipe** (colonnes exactes) au moment
  de coder l'import du palier A — dé-risqué (format unique, patron `FfbbExcelImporter` existant). Les détails
  fins (libellés, statuts de dérogation exacts, forme du radar matchs) se tranchent à l'implémentation.

---

## 13. En une phrase

La FFBB impose les dates ; le gestionnaire ne place que ses **matchs à domicile** (heure + salle) pour
**répondre à la ligue**. La valeur n'est pas d'optimiser mais de **voir les conflits tôt** — la même
**dualité coach/joueur** que l'entraînement, plus le **trajet** — pour **préparer les dérogations** avec du
temps devant. Un **module autonome** sur les entités club existantes, une **grille datée** réutilisant le
primitif de réservation, un **annuaire adverse global** qui s'enrichit à chaque club rejoint : plus on
avance, plus le calcul va loin.
