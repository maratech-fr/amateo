# Documentation metier du moteur de generation

Last verified @ 2026-08-24 (recalé ENG-32 : le monolithe `constraints.py` est devenu le paquet `constraints/` — les références de ce fichier pointent désormais fichier+fonction, stables au refactor. Vérification précédente toujours valable : P4-120 — premiere verification stampee de ce fichier, contre le code : poids de tiers S 10000/A 1000/B 100/C 10/D 1 fixes dans `app/solver/objective.py` ✓ · budget adaptatif 60/180/600 s plafonne par le payload (`_adaptive_timeout`, `app/main.py:410-425`) ✓ · capacite de creneau derivee backend `canSplit ? capacity : 1` (`ScheduleConstraintBuilder.php:822`) ✓ · `soft_lock_moved` et `constraint_not_honored` existent (`result_builder.py`, `constraints.py`) ✓ · MIN_SESSIONS cible soft ENG-18 (docblock d'`add_level_1_hard_constraints`, `constraints/__init__.py`) ✓. **Trois faits corriges dans la meme passe** : `orToolsWeight` n'a jamais quitte le payload (requis et ignore, `input_schema.py:63`) ; les implicites ne sont plus « toujours actives non configurables » — cinq sont reglables via `implicitRules` et `MAX_CONSECUTIVE_DAYS` (P2-42) nait ETEINTE ; les trois paliers du budget sont nommes ; et une 4e correction posee dans la foulee, attrapee en verifiant le doc VOISIN `constraint-coverage.md` : la famille `FACILITY_CAPACITY` etait encore documentee VIVANTE alors qu'elle est retiree depuis le 2026-08-08 — la premiere passe de verification de ce fichier l'avait manquee, preuve que verifier les docs UN PAR UN ne suffit pas, il faut aussi les confronter entre eux)

> Ce document explique le domaine de la planification sportive et ce que le moteur `engine` resout. Destine aux nouveaux developpeurs rejoignant le projet ClubScheduler.

---

## Contexte

Un club sportif, par exemple un club de basket comme le **BCCL** (Bourges Centre Cher Ligue), gere entre 10 et 40 equipes. Chaque equipe doit s'entrainer plusieurs fois par semaine. Le probleme : il faut assigner chaque seance a un creneau horaire, une salle (gymnase), et idealement un entraineur. Les ressources sont limitees, les contraintes sont nombreuses, et faire cela a la main prend des heures. Le moteur resout ce probleme d'optimisation combinatoire automatiquement.

---

## Concepts cles

### Equipe (`Team`)

Une equipe represente un groupe d'age et de genre. Exemples concrets au BCCL :

- `SM1` : Seniors Masculins 1, equipe premiere
- `SF1` : Seniors Feminines 1
- `U15F` : U15 Feminines
- `U13M2` : U13 Masculins 2

Chaque equipe possede :

- `sessionsPerWeek` : nombre de seances souhaitees par semaine (ex. 3 pour le SM1, 2 pour l'U13M2)
- `priorityTier` : niveau de priorite (S, A, B, C, D). Le SM1 est generalement en S, une equipe loisir en D
- `tags` : etiquettes comme `JEUNE`, `FEMININE`, `REGIONAL`, `SENIOR`, `MASCULINE`. Servent a cibler les contraintes

### Salle (`Venue`)

Une salle est un gymnase ou un terrain. Exemples :

- `Gymnase A` : disponible du lundi au samedi, 08h00-22h00
- `Gymnase B` : disponible uniquement mardi et jeudi, 18h00-21h00
- `Salle des Fetes` : disponible vendredi soir uniquement

Chaque salle declare des **creneaux d'entrainement** (`trainingSlots`), et chaque creneau porte une **capacite** : le nombre d'equipes qu'il peut accueillir simultanement (le backend la derive du gymnase : `canSplit ? capacity : 1`). Un gymnase **non divisible** (capacite 1) ne peut accueillir qu'une seule equipe a la fois : si le SM1 occupe le Gymnase A le lundi a 19h00, aucune autre equipe ne peut y etre placee au meme moment. Un gymnase **divisible** (ex. 3 terrains) accueille plusieurs equipes en parallele — le moteur contraint la somme des equipes placees sur le creneau a `min(capacite, maxTeams)`.

### Entraineur (`Coach`)

Un adulte qui dirige les seances. Exemple : **Maxime Dupont** est l'entraineur principal du SM1. Un entraineur ne peut diriger qu'une seule equipe a la fois. Un cas particulier existe : l'**entraineur-joueur** (`coach-player`), qui est entraineur d'une equipe et joueur dans une autre. Il ne peut pas etre a deux endroits simultanement.

Un entraineur peut etre **principal (`MAIN`)** ou **assistant (`ASSISTANT`)** d'une equipe. Le principal est **indispensable** : une equipe ne s'entraine jamais sans lui, il est donc considere present a **chacune** de ses seances (contrainte dure de non-chevauchement). L'assistant est **optionnel** : il ne bloque pas le placement — l'equipe peut etre planifiee meme si l'assistant est occupe ailleurs.

### Contrainte (`Constraint`)

Une regle metier qui faconne l'emploi du temps. Chaque contrainte a :

- **Portee (`scope`)** :
  - `CLUB` : s'applique a toutes les equipes (ou a celles filtreees par tag)
  - `TEAM` : s'applique a une equipe specifique (ex. le SM1)
  - `COACH` : s'applique a un entraineur specifique (ex. Maxime Dupont ne peut pas le mercredi)
  - `FACILITY` : s'applique a une salle specifique (ex. Gymnase A ferme pendant les vacances)

- **Famille (`family`)** :
  - `TIME` : heure minimale ou maximale de debut (ex. "pas avant 18h00", "pas apres 20h00")
  - `DAY` : jours preferes ou interdits (ex. "pas le vendredi", "preferer le mardi")
  - `FACILITY` : assignation de salle (ex. "le SM1 doit etre au Gymnase A")
  - `COACH_AVAILABILITY` : indisponibilite d'un entraineur (ex. "Maxime Dupont indisponible le mercredi")
  - ~~`FACILITY_CAPACITY`~~ : famille **RETIRÉE le 2026-08-08** (`app/main.py:483-484` — aucun chemin UI ne la creait). Le plafond d'equipes simultanees vit desormais **par creneau** : `VenueTrainingSlot.capacity`, derive cote backend (`canSplit ? capacity : 1`). Quant aux fermetures temporaires : depuis 5b (#263) elles **retirent les creneaux** du payload les jours fermes (`VenueClosureDays`), l'ancienne expansion en `forbiddenVenueId` est supprimee aussi

- **Type de regle (`ruleType`)** :
  - `HARD` : doit absolument etre respectee. Si ce n'est pas possible, le solveur declare l'instance infaisable
  - `PREFERRED` : souhaitable, mais pas obligatoire. Penalisee si non respectee
  - `BONUS` : recompensee si respectee (ex. bonus pour placer une equipe sur son jour prefere)
  - `LOCK` : fige un creneau. Toujours applique **en dur** par le moteur — le ruleType `LOCK` n'a pas de variantes SOFT/HARD. Ne pas confondre avec le `lockLevel` des `slotTemplates` (valeurs `NONE`/`SOFT`/`HARD`), qui est un autre mecanisme (voir plus bas)

- **Ciblage par tag** : une contrainte `CLUB` avec `targetTag=JEUNE` s'applique automatiquement a toutes les equipes portant le tag `JEUNE`. Cela evite de creer 15 contraintes identiques pour les 15 equipes jeunes.

### Contraintes implicites

Regles actives **par defaut**, sans que l'utilisateur ait rien a saisir. Nuance importante depuis
les regles implicites « bien-etre » : cinq d'entre elles sont desormais **reglables** via le bloc
`implicitRules` du payload (`resolve_implicit_rules`, `app/solver/constraints.py`) — intensite
`HARD`/`PREFERRED` et seuils (`minRestDays`, `maxConsecutive`, `maxConsecutiveDays`). Sans bloc :
defauts historiques, tout `HARD`. Une seule **nait ETEINTE** : `MAX_CONSECUTIVE_DAYS` (P2-42,
« pas N jours d'entrainement d'affilee » pour une EQUIPE — a ne pas confondre avec
`MAX_CONSECUTIVE_SESSIONS`, qui borne les creneaux d'une PERSONNE dans une journee) : absente du
payload, elle est `OFF`.

| Contrainte | Description |
|------------|-------------|
| `VENUE_AT_MOST_ONE` | Un creneau de gymnase accueille au plus sa **capacite** d'equipes : 1 pour un gymnase non divisible (deux equipes ne peuvent pas le partager au meme moment), N pour un gymnase divisible a N terrains |
| `COACH_NO_OVERLAP` | Un entraineur **principal** = une equipe a la fois. Maxime Dupont ne peut pas diriger le SM1 et l'U15M1 en meme temps. Seul le coach `MAIN` est concerne (l'assistant est optionnel et ne bloque pas) |
| `COACH_PLAYER_NO_OVERLAP` | Un entraineur-joueur ne peut pas etre a deux endroits simultanement. S'il entraine le SM1 a 19h00, il ne peut pas jouer avec les Seniors 2 a la meme heure |
| `TEAM_NO_OVERLAP` | Une equipe ne peut pas avoir deux seances en meme temps. Le SM1 ne peut pas s'entrainer a 19h00 au Gymnase A et a 19h00 au Gymnase B simultanement |
| `MIN_SESSIONS` | Chaque equipe **vise** son nombre de seances : c'est une **cible soft** (bonus dans l'objectif, audit ENG-18), jamais une garantie dure — le plancher dur est 0 en production. Si le SM1 demande 3 seances, le moteur est fortement incite a les placer, mais peut en placer moins quand les ressources manquent |
| `COACH_REST_DAY` | Chaque entraineur garde au moins un jour de repos du lundi au vendredi (au plus 4 jours travailles). Ignore pour un entraineur dont le maximum de jours declare est deja inferieur ou egal a 4 |
| `SALARIE_DISTRIBUTION` | Au moins un entraineur **salarie** est present chaque jour du lundi au vendredi. La regle ne s'active que si le club compte au moins 2 salaries |
| `MAX_CONSECUTIVE_SESSIONS` | Une meme personne n'enchaine jamais 3 creneaux consecutifs le meme jour (A puis B puis C), meme en changeant de gymnase |
| `ONE_SESSION_PER_DAY` | Une equipe n'a qu'une seance par jour, sauf si elle est explicitement autorisee a en avoir plusieurs |
| `AGE_ASCENDING` | Dans un meme gymnase le meme jour, une equipe plus jeune ne s'entraine pas apres une plus agee — les petits passent en premier. Sans effet pour les equipes sans tranche d'age (Loisir, Baby) ou verrouillees en HARD |

### Creneau (`ScheduleSlotTemplate`)

Un creneau est le resultat d'une seance assignee : equipe + salle + entraineur + jour + heure. Exemple :

```
SM1 + Gymnase A + Maxime Dupont + Lundi + 19h00-20h30
```

Chaque creneau a un niveau de verrouillage (`lockLevel`) :

- `NONE` : libre, le moteur peut le deplacer
- `SOFT` : purement indicatif — le moteur l'**ignore au moment du solve** (aucun bonus de preservation dans l'objectif). Si le creneau ressort ailleurs, un diagnostic `soft_lock_moved` (severite `WARNING`) est emis **a posteriori** pour signaler le deplacement
- `HARD` : fige, le moteur ne peut absolument pas le deplacer

Un creneau `HARD` est pose **hors du solveur** : le moteur ne cree meme pas la variable de decision correspondante. Consequence directe, et il faut la connaitre : **aucune contrainte saisie ne s'applique a un creneau verrouille** (indisponibilite d'entraineur, fenetre horaire, jour interdit, gymnase interdit). Le verrou n'est pas "plus fort" que la contrainte, il la rend inatteignable. Le verrou prime — c'est un choix assume : le gestionnaire qui epingle une seance sait pourquoi il le fait. Mais depuis P2-9, le moteur ne le tait plus : il emet un diagnostic `constraint_not_honored` de severite **INFO** qui nomme la contrainte ecrasee, l'equipe, l'entraineur ou le gymnase concerne, le jour, l'heure et la duree. Le gestionnaire voit ce que son epingle a annule, et decide.

Un verrou `HARD` occupe aussi le creneau **en entier**, meme dans un gymnase divisible : les autres equipes en sont exclues. Pour partager un creneau divisible, il faut epingler explicitement chacune des equipes.

### Niveaux de priorite

Les equipes sont classees par tiers. Quand les ressources sont rares, les equipes de haut niveau sont servies en premier :

| Tier | Poids (fixe) | Exemple d'equipe |
|------|--------------|------------------|
| S | 10 000 | SM1, SF1 (equipes premieres) |
| A | 1 000 | U20M, U18F (formation elite) |
| B | 100 | U15M1, U15F1 (competition) |
| C | 10 | U13M2, U11F2 (loisir competitif) |
| D | 1 | Baby basket, ecole de basket (initiation) |

Le poids determine combien de points rapporte chaque seance placee. Placer une seance du SM1 (S) rapporte 10 000 points. Placer une seance d'une equipe D rapporte 1 point. Ainsi, si le Gymnase A n'a qu'un seul creneau libre le lundi a 19h00, le moteur le donnera au SM1 plutot qu'a l'ecole de basket.

Ces poids sont **fixes et codes en dur** dans le solveur (`app/solver/objective.py`, garantie de priorite stricte S ≫ A ≫ B ≫ C ≫ D). Ils ne sont **pas** parametrables par club : le champ `orToolsWeight` est toujours **present et requis** dans le payload (`app/schemas/input_schema.py`, `PriorityTierSchema`) mais **aucun code ne le lit** — accepte puis ignore. Le retirer du schema serait un changement de contrat (version a bumper), pas un nettoyage silencieux.

---

## Ce que le moteur produit

Le moteur retourne trois choses :

1. **Un emploi du temps optimise** : la meilleure repartition possible des seances, en maximisant le score total (somme des poids des seances placees)
2. **Des diagnostics** : alertes en **langage gestionnaire** — chaque alerte dit **qui, quand et pourquoi**. Un conflit de gymnase nomme le gymnase, les equipes concernees, le jour + la plage horaire et la capacite depassee ; une equipe non placee est nommee avec la raison (aucun creneau declare, gymnase impose sans creneau, creneaux deja pris…) ; une infaisabilite pointe la sous-capacite (seances demandees vs creneaux disponibles) quand c'est identifiable
3. **Des equipes non placees** : liste des equipes pour lesquelles aucune seance n'a pu etre assignee, avec la raison

Le moteur ne garantit pas que toutes les equipes auront toutes leurs seances. Si le club a 40 equipes et seulement 2 gymnases, certaines equipes de faible priorite risquent de rester sans creneau. C'est un choix explicite : il vaut mieux un emploi du temps partiel mais realiste, qu'un emploi du temps complet mais impossible a tenir.

Le **temps de calcul** s'adapte a la taille du probleme (`_adaptive_timeout`, `app/main.py` : complexite = equipes × gymnases, paliers ≤50 → 60 s · ≤200 → 180 s · au-dela → 600 s), toujours plafonne par le budget du payload (`solverTimeoutSeconds`) : le gestionnaire n'attend jamais plus que ce qu'il a demande. Inutile d'attendre 10 min pour un club a 8 equipes.
