# Flux nominal : de l'appel backend a la reponse du moteur

Last verified @ 2026-08-31 (P2-51 PR-2 — mentions du contrat recalées 2.16→2.17, confrontées à `engine/CONTRACT_VERSION` et `ScheduleConstraintBuilder.php` ; rotation `documentation-update`, passe UXC-10 — fichier hors sujet de
la PR, contrôle de fraîcheur ciblé sur le résidu de nom trouvé au balayage `clubscheduler-*`).
**Drift trouvé et corrigé** : le hub Mercure était encore nommé `clubscheduler-mercure` — nom mort
depuis le renommage d'infrastructure P4-142 (2026-08-28) ; corrigé en `amateo-mercure` (conteneur
réel, `docker-compose.yml:325`). Reste vérifié sans écart cette passe : `engine/CONTRACT_VERSION`
= **2.18** ✓ ; verrou asyncio par club `_club_locks`/`_club_locks_guard` en `app/main.py:130-131` ✓ ;
`_adaptive_workers` (paliers 1/8 au seuil 200) en `app/main.py:445` ✓ ; `lockLevel` chaîne libre en
`app/schemas/input_schema.py:294` ✓ ; `solverTimeoutSeconds` défaut 650 en
`app/schemas/input_schema.py:307` ✓ ; module objectif toujours le paquet `app/solver/objective/`
(`__init__.py`/`normalise.py`/`terms.py`/`weights.py`, pas un fichier plat) ✓ ; module modèle
`app/solver/model.py` non éclaté ✓ ; `venue_minimum_unreachable` en
`app/solver/constraints/targeting.py:320` ✓.

> Ce document decrit le chemin complet d'une requete de generation d'emploi du temps, du moment ou le backend construit le payload jusqu'a la notification en temps reel du frontend. Destine aux developpeurs travaillant sur l'integration backend/engine.

---

## 1. Le backend construit le payload (contrat 2.18)

Quand un utilisateur clique sur "Generer l'emploi du temps" dans le frontend, le backend assemble un objet JSON conforme au schema `ScheduleInputSchema` (version de contrat **2.18**, fichier `engine/CONTRACT_VERSION`). Voici la structure complete, avec des explications inline.

```json
{
  "version": "2.18",
  "clubId": "550e8400-e29b-41d4-a716-446655440000",
  "seasonId": "660e8400-e29b-41d4-a716-446655440001",

  "venues": [
    {
      "id": "v1",
      "name": "Gymnase A",
      "isActive": true,
      "trainingSlots": [
        {"dayOfWeek": 1, "startTime": "19:00", "durationMinutes": 90, "capacity": 1},
        {"dayOfWeek": 2, "startTime": "19:00", "durationMinutes": 90, "capacity": 1},
        {"dayOfWeek": 3, "startTime": "20:30", "durationMinutes": 90, "capacity": 1},
        {"dayOfWeek": 6, "startTime": "10:00", "durationMinutes": 90, "capacity": 1}
      ]
    },
    {
      "id": "v2",
      "name": "Gymnase B",
      "isActive": true,
      "trainingSlots": [
        {"dayOfWeek": 2, "startTime": "18:00", "durationMinutes": 90, "capacity": 2},
        {"dayOfWeek": 4, "startTime": "18:00", "durationMinutes": 90, "capacity": 2}
      ]
    }
  ],

  "teams": [
    {
      "id": "t-sm1",
      "name": "SM1",
      "sportCategoryId": "cat-seniors",
      "priorityTierId": 1,
      "sessionsPerWeek": 3,
      "tags": ["REGIONAL", "SENIOR", "MASCULINE"],
      "level": "REGIONAL",
      "gender": "M",
      "isActive": true
    },
    {
      "id": "t-u15f1",
      "name": "U15F1",
      "sportCategoryId": "cat-u15",
      "priorityTierId": 3,
      "sessionsPerWeek": 2,
      "tags": ["JEUNE", "FEMININE", "DEPARTEMENTAL"],
      "level": "DEPARTEMENTAL",
      "gender": "F",
      "isActive": true
    }
  ],

  "coaches": [
    {
      "id": "c-maxime",
      "firstName": "Maxime",
      "lastName": "Dupont",
      "isActive": true
    },
    {
      "id": "c-sophie",
      "firstName": "Sophie",
      "lastName": "Martin",
      "isActive": true
    }
  ],

  "constraints": [
    {
      "id": "ctr-1",
      "scope": "TEAM",
      "scopeTargetId": "t-sm1",
      "family": "TIME",
      "ruleType": "HARD",
      "name": "Pas apres 20h",
      "config": {"maxStartTime": "20:00"},
      "isActive": true
    },
    {
      "id": "ctr-2",
      "scope": "TEAM",
      "scopeTargetId": "t-u15f1",
      "family": "DAY",
      "ruleType": "PREFERRED",
      "name": "Preferer le mardi",
      "config": {"preferredDays": [2]},
      "isActive": true
    },
    {
      "id": "team-coach:t-sm1",
      "teamId": "t-sm1",
      "type": "TEAM_COACH",
      "severity": "HARD",
      "value": "c-maxime",
      "metadata": {"coachId": "c-maxime", "role": "MAIN"}
    }
  ],

  "slotTemplates": [
    {
      "id": "st-1",
      "teamId": "t-sm1",
      "venueId": "v1",
      "coachId": "c-maxime",
      "dayOfWeek": 1,
      "startTime": "19:00",
      "durationMinutes": 90,
      "lockLevel": "HARD"
    }
  ]
}
```

### Explications par section

- **`version`** : version du contrat (actuellement `2.18`). Le moteur ne compare que le **MAJOR** : `"2.0"` et `"2.1"` passent tous les deux ; un payload `1.x` ou `3.x` est refuse.
- **`clubId` / `seasonId`** : identifiants du club et de la saison en cours. Le moteur ne les utilise pas pour le calcul, mais les inclut dans les logs et les diagnostics.
- **`venues`** : liste des salles. Chaque salle porte ses **creneaux d'entrainement** explicites dans la cle `trainingSlots` : `{dayOfWeek, startTime, durationMinutes, capacity}`. Il n'existe **ni** cle `availability` **ni** champ `endTime` (la fin se deduit de `startTime + durationMinutes`) — les schemas Pydantic sont `extra=forbid`, donc une cle inconnue provoque un `422`. La `capacity` indique combien d'equipes peuvent occuper le creneau simultanement (gymnase divisible : le backend envoie `canSplit ? capacity : 1`).
- **`teams`** : liste des equipes. Le champ `sportCategoryId` est **requis** (son absence provoque un `422`). Le `priorityTierId` identifie le rang de priorite (1 = S ... 5 = D), dont le poids est code en dur cote moteur.
- **`coaches`** : liste des entraineurs. Leur disponibilite propre est exprimee via des contraintes `COACH_AVAILABILITY`.
- **`constraints`** : deux formats coexistent :
  - **Format unifie v2** (`scope`, `family`, `ruleType`, `config`) : le nouveau format, plus flexible. Exemple : une contrainte `TIME` avec `maxStartTime: "20:00"`.
  - **Format legacy type** (`teamId`, `type`, `severity`, `value`) : conserve pour la retrocompatibilite, notamment pour les liens equipe-entraineur (`TEAM_COACH`). Exemple : `team-coach:t-sm1` lie le SM1 a Maxime Dupont comme entraineur principal.
- **`slotTemplates`** : creneaux pre-existants. Un creneau `HARD` est fige. Un creneau `SOFT` est une suggestion. Un creneau `NONE` n'a pas lieu d'etre dans cette liste (il serait genere par le moteur). Cette cle transporte **deux sources** cote backend : les verrous du planning (`ScheduleSlotTemplate`) **et** les reservations durables (`Reservation`), que le backend fusionne dans la meme liste sous forme d'epingles `HARD`. Le moteur ne fait pas la difference.
- **Pas de section `priorityTiers`** : le backend n'envoie **pas** cette cle (le schema l'accepte, avec une liste vide par defaut). Les poids de priorite que le solveur maximise sont **codes en dur** cote moteur (`LEVEL_2_OBJECTIVE_WEIGHTS`) — un `orToolsWeight` recu dans le payload serait ignore.

### Resolution des tags

Les contraintes `CLUB` avec `targetTag` ont deja ete "explosees" par le backend. Cela signifie que si le club a une regle "pas d'entrainement apres 20h pour les jeunes", le backend a deja cree N contraintes `TEAM` individuelles (une par equipe taggee `JEUNE`). Le moteur ne voit donc jamais de contraintes `CLUB` avec `targetTag`. Cette simplification reduit la complexite cote moteur.

---

## 2. Le moteur recoit et valide

Le backend envoie le payload au moteur via une requete HTTP POST sur `http://engine:8000/generate`.

### Validation Pydantic v2

FastAPI valide automatiquement le JSON contre le schema `ScheduleInputSchema`. Si un champ manque, si un type est incorrect (par exemple `sessionsPerWeek: "trois"` au lieu de `3`), ou si une cle inconnue est presente (les schemas sont `extra=forbid`), FastAPI retourne immediatement une erreur `422 Unprocessable Entity` avec le detail des champs en erreur. Attention : `lockLevel` est une **chaine libre**, pas un enum — un `lockLevel: "FORT"` ne provoque **pas** de 422, il est simplement traite comme non-`HARD`.

### Verrou asyncio par club

Avant de lancer le solveur, le moteur acquiert un verrou asyncio specifique au `clubId`. Cela empeche deux generations simultanees pour le meme club. Si un second utilisateur demande une generation pendant que la premiere est en cours, la seconde requete n'est **pas** rejetee : elle **attend** la liberation du verrou puis s'execute a son tour. Les generations d'un meme club sont donc serialisees — jamais de `503`.

### Verification de version

Le moteur verifie que le **MAJOR** de `version` correspond au MAJOR de son contrat (`2` pour le contrat `2.18`) : `"2.0"` comme `"2.18"` sont acceptes — le MINOR est ignore. C'est pourquoi la version que le PAYLOAD s'attribue (constante PHP du builder) DOIT valoir exactement `engine/CONTRACT_VERSION` et non « un `2.x` quelconque » : sinon un changement de forme du payload sans bump de MAJOR passerait inapercu des deux cotes. Cette egalite stricte est gardee par `PayloadVersionMatchesContractVersionTest`. Si le MAJOR differe, le moteur retourne une erreur indiquant la version attendue et la version recue.

---

## 3. Pipeline du solveur (4 etapes)

### Etape 1 — `build_model()`

Le moteur genere les **variables de decision booleennes** :

```
x[team_id, venue_id, day_of_week, slot_start]
```

Chaque variable signifie : "l'equipe T s'entraine-t-elle a la salle V le jour D a l'heure S ?" (1 = oui, 0 = non).

Les creneaux candidats sont **exactement les `trainingSlots` declares par les salles** : chaque `trainingSlot` (salle, jour, `startTime`) constitue **un seul depart candidat**. Il n'y a **pas** de discretisation d'une fenetre horaire en pas de 15 minutes — si le Gymnase A declare un creneau le lundi a 19h00, le seul depart possible ce jour-la est 19h00. La constante `SLOT_MINUTES = 15` ne sert qu'a une chose : bloquer la **duree** des verrous `HARD` (occupation du creneau sur toute la duree de la seance).

Les creneaux `HARD`-verrouilles sont **pre-places hors du modele** : **aucune variable `x[...]` n'est creee** pour eux (`model.py` saute les cles presentes dans `hard_slot_keys`), et le `(gymnase, jour, heure)` est retire pour **toutes** les equipes sur toute la duree du verrou — meme sur un creneau divisible (ALIGN-07).

Consequence a connaitre : **aucune contrainte ne peut atteindre un creneau verrouille**. Une contrainte s'applique en forcant une variable a 0 ; s'il n'y a pas de variable, il n'y a rien a forcer. Le verrou n'est donc pas « plus fort » que la contrainte, il la rend inatteignable. Le verrou reste souverain (decision fondateur ALIGN-07), mais depuis P2-9 le moteur ne se tait plus : `diagnose_locked_slot_violations` emet un diagnostic `constraint_not_honored` de severite **INFO** pour chaque contrainte saisie ainsi ecrasee. Les slots verrouilles sont reinjectes tels quels dans la reponse par `build_result`.

### Etape 2 — `add_level_1_hard_constraints()`

Ces contraintes doivent etre satisfaites pour que la solution soit **faisable**. Si l'une d'elles ne peut pas l'etre, le solveur retournera `INFEASIBLE`.

1. **VENUE_AT_MOST_ONE** : pour chaque salle, jour et slot, la somme des variables est inferieure ou egale a la **capacite declaree du creneau** (1 pour un gymnase non divisible). Deux equipes ne peuvent pas partager le Gymnase A le lundi a 19h00 s'il n'est pas divisible ; un gymnase a 3 terrains en accueille 3.
2. **COACH_NO_OVERLAP** : pour chaque entraineur, jour et slot, la somme des equipes qu'il entraine est inferieure ou egale a 1. Maxime Dupont ne peut pas diriger deux equipes en meme temps.
3. **COACH_PLAYER_NO_OVERLAP** : pour chaque entraineur-joueur, la somme de ses seances d'entrainement et de ses seances de joueur est inferieure ou egale a 1.
4. **TEAM_NO_OVERLAP** : pour chaque equipe, jour et slot, la somme est inferieure ou egale a 1. Le SM1 ne peut pas avoir deux seances a 19h00.
5. **FIXED_SLOTS** : chemin residuel. La collection `fixed_slots` n'est alimentee par aucune branche de `parse_v2_constraints` aujourd'hui, donc cette contrainte ne pose rien en production. Les verrous `HARD` ne passent **pas** par la : ils sont pre-places hors du modele (voir etape 1).
6. **FORBIDDEN_ASSIGNMENTS** : pour chaque contrainte `HARD` de type interdiction, la variable vaut 0. Exemple : si le SM1 a une contrainte "pas le vendredi", toutes les variables `x[t-sm1, *, 5, *]` valent 0.
7. **COACH_UNAVAILABILITY** : pour chaque contrainte `COACH_AVAILABILITY`, les variables correspondantes valent 0.
8. **FACILITY_CAPACITY** : pour chaque contrainte `FACILITY_CAPACITY`, le nombre d'equipes **simultanees** sur un creneau de la salle est plafonne a `min(capacite du creneau, maxTeams)`. Ce n'est **pas** une fermeture de salle : les fermetures temporaires (`venue_closed`) sont expansees **cote backend** en contraintes `forbiddenVenueId` par equipe avant l'envoi.
9. **MIN_SESSIONS** : attention, ce n'est **pas** une contrainte dure — c'est une **cible soft** (audit ENG-18). Le nombre de seances souhaite (`sessionsPerWeek`) est encourage via l'objectif, jamais impose (plancher dur 0 en production) : une equipe peut recevoir moins de seances que demande sans rendre l'instance infaisable.
10. **FORCED_VENUES** : si une equipe a une contrainte `FACILITY` `HARD` l'obligeant a une salle specifique, toutes les variables `x[team, autre_salle, *, *]` valent 0.
11. **COACH_REST_DAY** : chaque coach a au moins un jour de repos du lundi au vendredi (au plus 4 jours travailles). Ignore pour un coach dont le `maxDaysOverride` est deja inferieur ou egal a 4.
12. **SALARIE_DISTRIBUTION** : au moins un coach salarie (`isEmployee`) est present chaque jour du lundi au vendredi. Inactif si le club compte moins de 2 salaries.
13. **MAX_CONSECUTIVE_SESSIONS** : une meme personne n'est jamais sur les 3 creneaux d'un enchainement A -> B -> C le meme jour, tous gymnases confondus.
14. **ONE_SESSION_PER_DAY** : au plus une seance par jour et par equipe, sauf si l'equipe porte `allowMultipleSessionsPerDay`.
15. **AGE_ASCENDING** : a gymnase et jour egaux, une equipe plus jeune ne passe pas apres une plus agee. Exempt si `ageMin` est absent (Loisir, Baby) ou si l'equipe est verrouillee en HARD.
16. **VENUE_MINIMUMS** (`minAtVenueId`) : au moins N seances de l'equipe dans ce gymnase — un plancher, pas un forcage. Si N est prouvablement inatteignable, l'engine n'ajoute pas la contrainte et emet un diagnostic `venue_minimum_unreachable` plutot qu'un INFEASIBLE.

### Etape 3 — `add_level_2_objective()`

Le solveur maximise la fonction objectif suivante :

```
maximiser  Σ weight(tier) × x[team, venue, day, slot]
         + bonus session_count (chaque seance placee)
         + bonus preferred / preferred_day / preferred_time (preferences soft)
         - malus avoided_venue (salle a eviter, soft)
         + bonus rest (jour de repos apres un match)
         - malus spacing (deux seances sur des jours consecutifs)
```

Les poids des tiers sont fixes :

| Tier | Poids |
|------|-------|
| S | 10 000 |
| A | 1 000 |
| B | 100 |
| C | 10 |
| D | 1 |

Ainsi, placer une seance du SM1 (S) rapporte 10 000 points. Placer une seance de l'U15F1 (B) rapporte 100 points. Si une seule place est disponible au Gymnase A le lundi a 19h00, le solveur la donnera au SM1.

Les bonus et malus sont des termes secondaires. Ils ne changent pas l'ordre de grandeur du score, mais affinent la solution. Les termes reels de l'objectif (poids `LEVEL_2_OBJECTIVE_WEIGHTS`) sont : les **tiers** (S/A/B/C/D), `session_count`, `preferred`, `avoided_venue`, `preferred_day`, `preferred_time`, `rest` et `spacing`. L'objectif ne contient **ni** bonus de preservation des verrous `SOFT` **ni** penalite de surcharge d'entraineur : `soft_lock_moved` et `coach_overload` sont des **diagnostics post-solve** (le solveur ne les optimise pas, il les constate apres coup).

### Etape 4 — `CpSolver.Solve()`

Le solveur OR-Tools CP-SAT est lance avec un budget de temps **adaptatif** selon la taille du probleme (`n_teams × n_venues`) : **60 s** si ≤ 50, **180 s** si ≤ 200, **600 s** sinon. Le `solverTimeoutSeconds` du payload (defaut 650) n'est qu'un **plafond** — jamais le budget reel. La resolution se fait en **deux phases lexicographiques** (placement d'abord, puis chainage) ; les **10 secondes** souvent citees sont le cap de la **phase 2 (chaining) uniquement**.

Le nombre de workers CP-SAT suit les **memes paliers** (`_adaptive_workers`) : **1** worker si `n_teams × n_venues` ≤ 200 (resolution deterministe, dont dependent les fixtures golden), **8** au-dela (le worker unique trouve l'optimum en quelques secondes sur les problemes denses riches en preferences soft mais n'arrive pas a le **prouver** ; le portfolio a 8 workers ferme la preuve rapidement, pour la meme valeur d'objectif). Au-dela du seuil, la **valeur** du score reste reproductible, mais pas l'arrangement exact des seances.

Trois resultats possibles :

- `OPTIMAL` : la meilleure solution possible a ete trouvee. Le score est le maximum theorique.
- `FEASIBLE` : une solution valide a ete trouvee, mais pas necessairement la meilleure (le budget de temps a ete epuise). Le score est bon, mais peut-etre pas optimal.
- `INFEASIBLE` : aucune solution ne satisfait toutes les contraintes `HARD`. L'instance est impossible. Le moteur retournera `status: failed`.

---

## 4. Le moteur retourne `ScheduleOutputSchema`

Une fois le solveur termine, le moteur construit la reponse JSON :

```json
{
  "status": "completed",
  "score": 117679,
  "slots": [
    {
      "id": "slot-1",
      "teamId": "t-sm1",
      "venueId": "v1",
      "coachId": "c-maxime",
      "dayOfWeek": 1,
      "startTime": "19:00",
      "durationMinutes": 90,
      "lockLevel": "NONE"
    },
    {
      "id": "slot-2",
      "teamId": "t-u15f1",
      "venueId": "v2",
      "coachId": "c-sophie",
      "dayOfWeek": 2,
      "startTime": "18:00",
      "durationMinutes": 90,
      "lockLevel": "NONE"
    }
  ],
  "unplaced": [],
  "diagnostics": [
    {
      "id": "diag-1",
      "type": "soft_lock_moved",
      "severity": "WARNING",
      "message": "Le creneau SOFT du SM1 (lundi 19h) a ete deplace vers mardi 19h pour optimiser le score global",
      "teamId": "t-sm1"
    },
    {
      "id": "constraint_not_honored-off-c-maxime-6",
      "type": "constraint_not_honored",
      "severity": "INFO",
      "teamId": "t-sm1",
      "message": "Réservation maintenue pour SM1 (Gymnase A le samedi à 10:00 (90 min)) alors que Maxime Dupont est indisponible : le verrou prime, la contrainte est ignorée. (contrainte « indisponibilité de Maxime Dupont »)"
    }
  ],
  "metrics": {
    "solver_version": "9.11.0",
    "nb_variables": 5432,
    "nb_constraints": 12847,
    "wall_time_ms": 8240
  }
}
```

### Champs de la reponse

- **`status`** : `completed` (succes) ou `failed` (echec, voir `solver-errors.md`)
- **`score`** : score total de la solution. 0 signifie que rien n'a ete place
- **`slots`** : liste des creneaux assignes. Chaque creneau a un `lockLevel` (`NONE` pour les nouveaux, `HARD`/`SOFT` pour les pre-existants preserves)
- **`unplaced`** : liste des `teamId` pour lesquels aucune seance n'a ete placee
- **`diagnostics`** : alertes detaillees (voir `solver-errors.md` pour le catalogue complet). Les severites reelles sont `ERROR` / `WARNING` / `INFO` (ex. `soft_lock_moved` = `WARNING`, `constraint_not_honored` sur verrou = `INFO`). Le `DiagnosticSchema` exige un champ `id` et ne connait **pas** de champ `slotId` (`extra=forbid`)
- **`metrics`** : metriques techniques du solveur. `nb_variables` et `nb_constraints` donnent une idee de la taille du probleme. `wall_time_ms` indique le temps reel de resolution

---

## 5. Le backend traite la reponse

### Import des creneaux

Pour chaque element de `slots[]`, le backend cree ou met a jour une entite `ScheduleSlotTemplate` en base de donnees. Les creneaux existants avec `lockLevel=HARD` sont conserves. Les nouveaux creneaux sont crees avec `lockLevel=NONE`.

### Import des diagnostics

Pour chaque element de `diagnostics[]`, le backend cree une entite `ScheduleDiagnostic`. Ces diagnostics sont affiches dans le frontend pour aider l'utilisateur a comprendre les choix du moteur.

### Mise a jour du statut

Le backend met a jour le statut du `Schedule` :

- `status = COMPLETED` si `engine.status == "completed"`
- `status = FAILED` si `engine.status == "failed"`

### Notification Mercure SSE

Le backend publie un evenement sur le hub Mercure (conteneur `amateo-mercure`) sur le topic :

```
club:{clubId}:schedule:{scheduleId}
```

Le frontend ecoute ce topic via `EventSource`. Des que l'evenement arrive, le frontend rafraichit l'affichage de l'emploi du temps. L'utilisateur voit le resultat apparaitre en temps reel, sans avoir a recharger la page.

---

## Resume du flux en 5 etapes

1. **Backend** : construit le payload (contrat 2.18) a partir des entites du club (equipes, salles, entraineurs, contraintes)
2. **Moteur** : valide le payload, acquiert le verrou club, verifie le MAJOR de la version
3. **Solveur** : construit le modele, ajoute les contraintes HARD, definit l'objectif, resout dans le budget adaptatif (60/180/600 s selon la taille du probleme)
4. **Moteur** : retourne `ScheduleOutputSchema` avec creneaux, diagnostics, metriques
5. **Backend** : importe les resultats en base, met a jour le statut, notifie le frontend via Mercure
