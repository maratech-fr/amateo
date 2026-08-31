# Documentation métier du système de contraintes

Last verified @ 2026-08-31 (rotation `documentation-update`, zone non touchée par cette PR —
contrôle de fraîcheur. Re-confronté au code : tags système présents dans `TeamTagService`
(`EMB`, `PRE_REGION`, `LOISIR_JEUNE`, `HONNEUR`, `PROMOTION`, `MIXTE`) ✓ · `FACILITY_CAPACITY`
retirée des trois couches, ne subsiste qu'en commentaires historiques
(`ScheduleConstraintBuilder.php:1327`, `ValidateConstraintsController.php:254`,
`engine/app/main.py:486`) ✓ · `ConstraintValidationService` toujours appelé par le SEUL
`ValidateConstraintsController` (grep) ✓. Rien de faux trouvé cette passe.)

> ClubScheduler — Symfony 7 + API Platform. Contexte : BCCL (B CHARPENNES CROIX LUIZET, code FFBB ARA0069036, ligue ARA).

---

## 1. Introduction — Qu'est-ce qu'une contrainte ?

Une **contrainte** est une règle métier qui façonne le planning d'entraînement du club. Elle dit au solveur ce qui est autorisé, ce qui est interdit, et ce qui serait préférable.

On distingue deux catégories :

- **Règles implicites** : appliquées automatiquement par le moteur, sans que l'utilisateur ait rien à saisir. Par exemple, un entraîneur ne peut pas être sur deux terrains en même temps, ou une salle ne peut accueillir qu'une seule équipe par créneau. Les invariants structurels (non-chevauchement, capacité) sont codés en dur ; les règles de **bien-être**, elles, sont désormais **réglables** via le bloc `implicitRules` du payload (intensité `HARD`/`PREFERRED`, seuils — et `maxConsecutiveDays`, P2-42, naît ÉTEINTE). Détail : `engine/docs/business.md` §Contraintes implicites.
- **Contraintes utilisateur** : créées explicitement par l'administrateur du club via l'interface d'administration ou l'API. C'est ce document qui les décrit.

Prenons un exemple concret au BCCL : l'équipe première masculine (SM1) s'entraîne le mardi et jeudi soir. Cette préférence n'est pas une règle universelle du basket, c'est une décision du club. C'est donc une contrainte utilisateur de type `DAY` + `PREFERRED`.

---

## 2. Anatomie d'une contrainte

L'entité `Constraint` possède quatre dimensions clés qui déterminent son comportement.

### 2.1 Scope — À qui s'applique-t-elle ?

Le champ `scope` (enum `ConstraintScope`) définit la cible de la contrainte.

| Valeur | Cible | Exemple BCCL |
|--------|-------|--------------|
| `CLUB` | Toutes les équipes du club (filtrables par tag via `targetTag`) | "Toutes les équipes jeunes finissent avant 19h30" |
| `TEAM` | Une équipe spécifique (via `scopeTargetId` = UUID de l'équipe) | "SM3 ne s'entraîne que le mercredi" |
| `COACH` | Un entraîneur spécifique (via `scopeTargetId` = UUID du coach) | "Enzo n'est pas disponible le vendredi" |
| `FACILITY` | Une salle spécifique (via `scopeTargetId` = UUID du lieu) | "Le gymnase ADN est fermé du 20 au 27 octobre" (fermeture datée, cf. §3.3 — l'ancien exemple « N équipes simultanées max » décrivait `FACILITY_CAPACITY`, retirée le 2026-08-08) |

### 2.2 Family — Quel type de règle ?

Le champ `family` (enum `ConstraintFamily`) définit la famille de la contrainte. Chaque famille attend des clés spécifiques dans le champ JSON `config`.

#### `TIME` — Fenêtre horaire

Restreint la fenêtre horaire de l'entraînement. La validation exige **au moins une** de ces trois clés.

| Clé `config` | Type | Description | Exemple |
|--------------|------|-------------|---------|
| `maxStartTime` | string (HH:MM) | Heure max de début | `"19:30"` |
| `minStartTime` | string (HH:MM) | Heure min de début | `"20:00"` |
| `maxEndTime` | string (HH:MM) | Heure max de **fin** (mode « fini avant ») — l'engine calcule fin = début + durée du créneau. **Exige une règle `HARD`/`LOCK`** : le chemin souple l'ignore, la validation le refuse donc en `PREFERRED`/`BONUS` | `"20:30"` |

> Exemple : `{maxStartTime: "19:30"}` signifie "l'entraînement doit commencer au plus tard à 19h30". Si la séance dure 1h30, elle finira donc à 21h00 au plus tard.

#### `DAY` — Jours d'entraînement

Définit les jours autorisés, à éviter ou imposés pour l'entraînement. La validation exige **au moins une** de ces trois clés :

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `allowedDays` | int[] (1-7) | Whitelist **dure** : seuls ces jours sont permis, l'engine interdit tous les autres (mode « uniquement ») |
| `forbiddenDays` | int[] (1-7) | Jours à éviter : interdiction dure en `HARD`, pénalité (évitement) en règle souple |
| `forcedDays` | int[] (1-7) | Au moins une séance sur chacun de ces jours (vocabulaire engine, non émis par le wizard) |

Numérotation des jours : `1=Lundi`, `2=Mardi`, `3=Mercredi`, `4=Jeudi`, `5=Vendredi`, `6=Samedi`, `7=Dimanche`.

> Exemple : `{allowedDays: [3]}` force l'entraînement le mercredi uniquement (tous les autres jours sont interdits). `{forbiddenDays: [6, 7]}` évite le week-end.

À noter : l'engine reconnaît aussi une clé `preferredDays` (bonus **SOFT** sur les jours cités), mais elle n'est **jamais émise par le wizard** et ne suffit pas à elle seule — une contrainte `DAY` dont la config ne contient que `preferredDays` est **rejetée** par la validation (`allowedDays`, `forbiddenDays` ou `forcedDays` requis).

#### `FACILITY` — Affectation de salle

Oriente ou bloque l'utilisation d'une salle spécifique. La validation exige **au moins une** de ces quatre clés :

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `forcedVenueId` | UUID | Salle imposée (toutes les séances y ont lieu) |
| `forbiddenVenueId` | UUID | Salle interdite |
| `preferredVenueId` | UUID | Salle préférée (soft ; forcée si la règle est `HARD`) |
| `minAtVenueId` | UUID | Au moins N séances dans cette salle (N = `minAtVenueCount`, défaut 1) — exige `HARD`/`LOCK` et scope `TEAM` |

> Exemple : `{forbiddenVenueId: "uuid-jean-vilar"}` empêche toute équipe concernée d'aller au gymnase Jean Vilar.

Il n'existe **pas** de clé `closedDay` ni `onlyDay`. Une fermeture de salle **datée** ne passe pas par une contrainte saisie à la main : elle se déclare dans le **cockpit** (entrée calendrier `venue_closed`) et **ne devient jamais une contrainte** — depuis P2-5 5b le backend retire purement et simplement les créneaux du gymnase du payload sur ses jours fermés (voir §3.3).

> ⚠ **La forme du `config` est validée à l'écriture depuis SEC-13** (422 sur clé
> inconnue ou valeur aberrante). La table des clés acceptées, leur type et leur
> lecteur : [`constraint-config-keys.md`](constraint-config-keys.md).

#### `COACH_AVAILABILITY` — Disponibilité d'entraîneur

Déclare les jours où un entraîneur est indisponible (ou, à l'inverse, les seuls jours où il est disponible).

⚠ **La cible est le SCOPE, pas le config** (SEC-13, 2026-08-07). `scopeTargetId` porte le
coach ; `config.coachId` a été supprimé — il valait exactement la même valeur (6 lignes sur 6,
mesuré) et le solveur n'a jamais lu que le scope (`constraints/parsing.py` : `scope_target_id`). Deux
endroits pour une même vérité finissent par diverger. `targetTag` reste une cible légitime :
il désigne un GROUPE, ce que le scope ne sait pas exprimer.

| Clé `config` | Type | Description |
|--------------|------|-------------|
| `unavailableDays` | int[] (1-7) | Jours où le coach est indisponible |
| `availableDays` | int[] (1-7) | Whitelist : le coach n'est disponible QUE ces jours-là |
| `fromTime` / `untilTime` | string (HH:MM) | Fenêtre horaire optionnelle de l'indisponibilité (Lot C) ; absente = journée entière |

> Exemple : `scopeTargetId: "uuid-enzo"` avec `config: {unavailableDays: [5]}` signifie que l'entraîneur n'est jamais disponible le vendredi. Avec `fromTime: "18:00"` et `untilTime: "20:00"`, l'indisponibilité ne couvre que ce créneau.

> ⚠ **`FACILITY_CAPACITY` a été RETIRÉE le 2026-08-08.** Elle limitait le nombre d'équipes simultanées dans une salle entière (`min(capacité du créneau, maxTeams)`). Aucun chemin UI ne la créait — zéro ligne en base, zéro créateur côté serveur — et la capacité se règle déjà **par créneau** (`VenueTrainingSlot.capacity`, borné à 1 quand le gymnase n'est pas divisible), qui est le geste réel du gestionnaire. Ce qu'on perd : le raccourci « caper tout un gymnase en une règle ». Ce qui reste : éditer la capacité des créneaux concernés.

### 2.3 Rule Type — Quelle sévérité ?

Le champ `ruleType` (enum `ConstraintRuleType`) définit comment le solveur traite la contrainte.

| Valeur | Comportement | Analogie |
|--------|--------------|----------|
| `HARD` | Doit être respectée. Si elle est violée, le planning est infaisable. | "C'est non négociable." |
| `PREFERRED` | Devrait être respectée. Une violation est pénalisée dans le score, mais autorisée. | "C'est préférable, mais on peut déroger si nécessaire." |
| `BONUS` | Récompense si respectée. Aucune pénalité si violée. | "C'est un plus, pas une obligation." |
| `LOCK` | Figé. Le créneau est verrouillé, le solveur ne peut pas le déplacer. | "Ne touchez pas à ce créneau." |

### 2.4 Tag targeting (pour le scope `CLUB`)

Quand le scope est `CLUB`, la contrainte s'applique par défaut à **toutes** les équipes du club. Pour cibler un sous-ensemble, on utilise la clé `config.targetTag`.

Une contrainte `CLUB` avec `config.targetTag = "JEUNE"` s'applique uniquement aux équipes portant le tag `JEUNE`. Ces tags sont générés automatiquement par `TeamTagService` à partir des caractéristiques de l'équipe (âge, genre, niveau).

**Tags système disponibles :**

| Catégorie | Tags |
|-----------|------|
| Âge | `JEUNE`, `SENIOR`, `EMB` |
| Catégorie jeunes | `U9`, `U11`, `U13`, `U15`, `U18`, `U21` |
| Genre | `FEMININE`, `MASCULINE`, `MIXTE` |
| Niveau | `ELITE`, `REGIONAL`, `NATIONAL`, `DEPARTEMENTAL`, `LOISIR_ADULTE`, `LOISIR_JEUNE`, `HONNEUR`, `PROMOTION`, `PRE_REGION` |

> Exemple : `targetTag: "U11"` cible toutes les équipes U11 du club (garçons et filles confondus). Pour cibler uniquement les U11 filles : `targetTags: ["U11", "FEMININE"]` — l'**intersection de tags est livrée** (P2-29, lot tags 2026-08-15), avec `excludeTags` en soustraction ; `targetTag` (singulier) reste la forme historique, équivalente à une liste d'un élément, et **mélanger les deux formes rend 422**. La sémantique exacte et les refus : [`constraint-config-keys.md`](constraint-config-keys.md) (foyer `TeamTagResolver`).

---

## 3. Exemples concrets BCCL

Voici huit contraintes réelles ou plausibles pour le BCCL, présentées sous forme de tableau récapitulatif.

| Nom | Scope | Family | Type | Config | Effet |
|-----|-------|--------|------|--------|-------|
| Jeunes — fin à 19h30 | `CLUB` | `TIME` | `HARD` | `{maxStartTime:"19:30", targetTag:"JEUNE"}` | Toutes les équipes jeunes terminent avant 19h30 |
| U11/U13 — début à 17h30 | `CLUB` | `TIME` | `PREFERRED` | `{minStartTime:"17:30", targetTag:"U11"}` | Les U11 commencent de préférence après 17h30 |
| Jean Vilar — sans filles | `CLUB` | `FACILITY` | `HARD` | `{forbiddenVenueId:"uuid-jean-vilar", targetTag:"FEMININE"}` | Aucune équipe féminine ne peut aller à Jean Vilar |
| Matéo — préféré régional | `CLUB` | `FACILITY` | `PREFERRED` | `{preferredVenueId:"uuid-mateo", targetTag:"REGIONAL"}` | Les équipes régionales vont de préférence à Matéo |
| SM3 — mercredi seulement | `TEAM` | `DAY` | `HARD` | `{allowedDays:[3]}` | SM3 ne s'entraîne que le mercredi |
| SM3 — à partir de 20h | `TEAM` | `TIME` | `HARD` | `{minStartTime:"20:00"}` | SM3 commence au plus tôt à 20h |
| ADN — fermé (période datée) | — (cockpit) | — (aucune contrainte) | — | — | Fermeture saisie dans le cockpit (`venue_closed`) : le backend **retire les créneaux d'ADN du payload** sur ses jours fermés, sans produire aucune contrainte (§3.3) |
| Coach Enzo — indisponible vendredi | `COACH` | `COACH_AVAILABILITY` | `HARD` | `{coachId:"uuid-enzo", unavailableDays:[5]}` | Enzo n'est disponible aucun vendredi |

### 3.1 Exemple détaillé : "Jeunes — fin à 19h30"

Cette contrainte utilise le scope `CLUB` combiné au tag `JEUNE`. Dans la base de données, elle est stockée comme une seule ligne dans la table `Constraint` :

```json
{
  "scope": "CLUB",
  "family": "TIME",
  "ruleType": "HARD",
  "config": {
    "maxStartTime": "19:30",
    "targetTag": "JEUNE"
  }
}
```

Mais avant d'envoyer le payload au moteur de calcul, le backend résout ce tag. `ScheduleConstraintBuilder` interroge les tables `TeamTag` et `TeamTagAssignment` pour trouver toutes les équipes taguées `JEUNE` dans la saison active (par exemple : U9M, U9F, U11M, U11F, U13M1, U13M2, U13F, U15M, U15F). Pour chacune de ces équipes, il génère une contrainte individuelle de scope `TEAM`.

Le moteur ne voit jamais `targetTag`. Il reçoit huit contraintes `TEAM` distinctes, chacune avec `maxStartTime: "19:30"`. Cette résolution garantit que le solveur travaille avec des entités concrètes, pas avec des abstractions de tag.

### 3.2 Exemple détaillé : "SM3 — mercredi seulement"

Cette contrainte cible directement l'équipe SM3 (Senior Masculin 3) via le scope `TEAM`. Son `scopeTargetId` pointe vers l'UUID de l'entité `Team` SM3.

```json
{
  "scope": "TEAM",
  "scopeTargetId": "uuid-sm3",
  "family": "DAY",
  "ruleType": "HARD",
  "config": {
    "allowedDays": [3]
  }
}
```

Pour le solveur, cette contrainte `HARD` signifie que seuls les créneaux du mercredi sont disponibles pour SM3. Les créneaux mardi, jeudi, vendredi, etc. sont invisibles pour cette équipe. Si le mercredi est déjà saturé par d'autres équipes, le solveur peut être contraint de déclarer le planning infaisable, ou de violer d'autres contraintes `HARD` (ce qui produit un diagnostic de conflit).

C'est une contrainte très forte. Dans la pratique, le BCCL pourrait la déclarer `PREFERRED` plutôt que `HARD` pour laisser une marge de manoeuvre au solveur en cas de saturation des salles le mercredi.

### 3.3 Exemple détaillé : fermeture datée du gymnase ADN

Une fermeture de salle ne se saisit **pas** comme une contrainte à la main (il n'existe pas de clé `closedDay` : une telle config serait rejetée par la validation, qui exige `forcedVenueId`, `forbiddenVenueId`, `preferredVenueId` ou `minAtVenueId`). Elle se déclare dans le **cockpit**, via une entrée de calendrier de type `venue_closed` portant la salle et la période concernées.

> ⚠️ **Ce mécanisme a changé (P2-5 5b, #263).** Le backend développait autrefois cette fermeture en une contrainte `FACILITY` `HARD` `{forbiddenVenueId: "uuid-adn"}` **par équipe**. Cette expansion est **supprimée** : l'interdiction d'affectation de l'engine est *day-blind*, elle fermait donc le gymnase **toute la semaine** même quand l'incident n'en couvrait qu'une partie.

Aujourd'hui, au moment de construire le payload d'overlay, `ScheduleConstraintBuilder::buildForOverlay` calcule (via `VenueClosureDays`) les **jours de semaine où chaque gymnase est réellement fermé** — l'intersection de l'incident et de la fenêtre du plan — puis **retire du payload les créneaux du gymnase sur ces jours-là**. Aucune contrainte n'est produite.

Le raisonnement est celui du solveur : **pas de créneau ⇒ pas de variable ⇒ le solveur ne peut rien y placer** ce jour-là, mais il conserve toute liberté **les autres jours**. La sémantique « salle fermée » est portée par la structure du payload, plus par une contrainte.

Effet de bord assumé : si un même jour de semaine se répète dans le bloc fermé, la fermeture porte sur tout le bloc — le mécanisme **sur-ferme** au pire, il ne **sous-ferme** jamais.

Comme l'ancienne expansion, cette fermeture réduit l'offre de créneaux pour **toutes** les équipes. Si ADN est la seule salle disponible le lundi soir pendant la période fermée, alors aucune équipe ne pourra s'entraîner ce soir-là.

---

## 4. Résolution des tags

Le mécanisme de résolution des tags est un passage obligé entre la saisie utilisateur (abstraite) et le calcul du solveur (concret).

### 4.1 Le processus en 4 étapes

**Étape 1 — Création par l'administrateur**

L'administrateur du BCCL crée une contrainte `CLUB` avec `targetTag = "JEUNE"`. Il n'a pas besoin de lister manuellement les 8 équipes jeunes. Le tag fait le travail.

**Étape 2 — Génération automatique des tags**

`TeamTagService` s'exécute régulièrement (ou à la création/modification d'une équipe) pour maintenir les tags à jour. Il inspecte chaque équipe et lui attribue des tags système :

- Une équipe U13F se voit attribuer `JEUNE`, `U13`, `FEMININE`.
- Une équipe SM1 se voit attribuer `SENIOR`, `MASCULINE` (plus un tag niveau si défini).

Ces assignations sont stockées dans `TeamTagAssignment`, liant `Team` + `TeamTag` + `Season`.

**Étape 3 — Résolution au moment de la génération**

Quand `GenerateScheduleHandler` déclenche la construction du payload, `ScheduleConstraintBuilder` parcourt toutes les contraintes `CLUB` avec un `targetTag`. Pour chacune :

1. Il lit la valeur de `targetTag` (ex: `"JEUNE"`).
2. Il cherche le `TeamTag` correspondant pour le club.
3. Il récupère toutes les `TeamTagAssignment` actives pour ce tag et la saison en cours.
4. Pour chaque équipe trouvée, il crée une contrainte `TEAM` individuelle avec les mêmes `family`, `ruleType` et `config` (sans le `targetTag`).

**Étape 4 — Payload envoyé au moteur**

Le JSON POSTé vers `http://engine:8000/generate` contient uniquement des contraintes résolues. Le champ `constraints[]` ne contient plus de `targetTag`, seulement des `scope: "TEAM"` + `scopeTargetId` concrets.

### 4.2 Pourquoi cette résolution ?

Le solveur CP-SAT (OR-Tools) raisonne sur des variables binaires du type "l'équipe E s'entraîne le jour D à l'heure H dans la salle S". Il a besoin de savoir, pour chaque équipe concrète, quelles sont ses restrictions. Les tags sont une commodité pour l'utilisateur humain, pas pour un solveur mathématique.

---

## 5. Contraintes implicites vs contraintes utilisateur

| | Implicites | Utilisateur |
|--|-----------|------------|
| **Gérées par** | Le moteur Python, automatiquement | L'administrateur du club via l'API ou l'interface |
| **Configurables** | Invariants structurels : non. Règles de bien-être : intensité/seuils via `implicitRules` (P2-42) | Oui (CRUD complet) |
| **Stockage** | Code de l'engine | Table `Constraint` en base de données |
| **Exemples** | Un entraîneur = une équipe à la fois. Une salle = une équipe à la fois. | Les jeunes doivent finir avant 19h30. SM3 préfère le mercredi. |
| **Visibilité API** | Endpoint `POST /implicit-constraints` de l'**engine** (aucune route backend) — consommé par la commande `app:constraint:export-implicit` | Endpoint `/api/constraints` (CRUD complet) |
| **Impact sur le score** | `HARD` par défaut ; les règles de bien-être peuvent être assouplies en `PREFERRED` (pénalité au lieu d'invalidité), et `maxConsecutiveDays` naît OFF | Variable (`HARD`, `PREFERRED`, `BONUS`, `LOCK`) |

Les contraintes implicites sont les fondations du système. Sans elles, le solveur pourrait placer le coach Enzo sur deux terrains simultanément, ou assigner SM1 et SF3 dans la même salle à la même heure. Les contraintes utilisateur viennent affiner ce comportement de base pour refléter les réalités du BCCL : horaires des bus scolaires, disponibilités des salles municipales, préférences des entraîneurs bénévoles.

---

## 6. Référence rapide des combinaisons valides

Toutes les combinaisons scope + family ne sont pas logiques. Voici les combinaisons qui ont du sens métier (guide indicatif — voir la note sous le tableau sur ce qui est réellement vérifié) :

| Scope | Family | Valide | Exemple |
|-------|--------|--------|---------|
| `CLUB` | `TIME` | Oui | Toutes les équipes jeunes avant 19h30 |
| `CLUB` | `DAY` | Oui | Toutes les équipes seniors préfèrent le mardi |
| `CLUB` | `FACILITY` | Oui | Aucune équipe féminine à Jean Vilar |
| `CLUB` | `COACH_AVAILABILITY` | Non | Pas de sens (pas de coach cible) |
| `TEAM` | `TIME` | Oui | SM3 après 20h |
| `TEAM` | `DAY` | Oui | SF3 uniquement le mardi |
| `TEAM` | `FACILITY` | Oui | SM1 préfère Matéo |
| `TEAM` | `COACH_AVAILABILITY` | Non | Pas de sens (c'est le coach qui est indisponible, pas l'équipe) |
| `COACH` | `TIME` | Non | Pas de sens (c'est l'équipe qui a un horaire, pas le coach seul) |
| `COACH` | `DAY` | Non | Pas de sens |
| `COACH` | `FACILITY` | Non | Pas de sens |
| `COACH` | `COACH_AVAILABILITY` | Oui | Enzo indisponible vendredi |
| `FACILITY` | `TIME` | Non | Pas de sens |
| `FACILITY` | `DAY` | Non | Pas de sens |
| `FACILITY` | `FACILITY` | Oui | ADN fermé le lundi |

Attention, ce tableau est un **guide métier**, pas une règle appliquée par le code : `ConstraintValidationService` n'implémente **aucune matrice scope × family**. Ce qu'il vérifie réellement :

- la cohérence `scope` / `scopeTargetId` (un scope autre que `CLUB` exige une cible ; `CLUB` n'en admet pas) ;
- la présence des clés de `config` attendues par chaque famille (voir §2.2), plus quelques règles de cohérence (ex. `maxEndTime` et `minAtVenueId` exigent `HARD`/`LOCK`) ;
- `LOCK` réservé aux familles `TIME` et `DAY`.

Et il n'est **pas appelé à la création ni à la modification** d'une contrainte : il n'est exécuté que par `POST /api/constraints/validate` (gate consultatif pré-solve, déclenché avant une génération). Une combinaison « illogique » du tableau ci-dessus peut donc être enregistrée en base — elle sera au pire ignorée par l'engine.
