# Erreurs et diagnostics du solveur

Last verified @ 2026-08-31 (P2-51 PR-3, `documentation-update`). Nouvelle ligne `shared_block_not_honored`
ajoutée (le bloc de mutualisation est désormais CONSOMMÉ, PR-3) — confrontée à `_diagnose_shared_blocks`
(`app/solver/result_builder/diagnostics.py:836-916`), patron exact de `shared_training_not_honored`.
⚠ **Écart connu, hors périmètre de cette passe** : `team_link_not_honored` et `travel_time_infeasible`
sont des types de diagnostic du contrat (`output_schema.py` §Literal, au même titre que
`shared_training_not_honored`/`shared_block_not_honored`) mais n'ont **jamais** eu leur ligne dans
ce tableau — signalé, pas corrigé ici (voir roadmap P4). Reste du document non re-parcouru ligne à
ligne cette passe.

> Ce document recense toutes les erreurs que le moteur peut produire, avec leurs causes et les actions correctives. Destine aux developpeurs et aux utilisateurs avances du club.

---

## Erreurs au niveau HTTP

Ces erreurs sont retournees directement par l'API FastAPI, avant meme que le solveur ne soit lance.

### `422 Unprocessable Entity`

**Cause** : le payload JSON ne respecte pas le schema `ScheduleInputSchema`. C'est une erreur de validation Pydantic v2.

**Exemples concrets** :
- Champ `sessionsPerWeek` manquant pour l'equipe SM1
- `sessionsPerWeek: "trois"` au lieu d'un entier
- Champ `sportCategoryId` manquant sur une equipe (requis)
- Cle inconnue dans le payload (les schemas sont `extra=forbid`)
- `version: "1.0"` alors que le moteur parle le **MAJOR 2** du contrat `2.19` (`"2.0"` comme `"2.1"` passent)

**Attention — deux pieges qui ne provoquent PAS de 422** : `lockLevel` est une **chaine libre**, pas un enum (un `"FORT"` est accepte et simplement traite comme non-`HARD`), et le `dayOfWeek` d'un creneau de gymnase (`VenueTrainingSlotSchema`) est un entier **sans borne** — un `8` passe la validation (d'autres schemas du meme payload, eux, sont bornes `ge=1, le=7` : la tolerance n'est pas une regle generale).

**Que faire** : corriger le payload. Le detail de l'erreur 422 indique exactement quel champ est en cause et pourquoi.

### `409 Conflict` (sur `/implicit-constraints`)

**Cause** : les regles implicites du backend et du moteur sont desynchronisees. Le backend s'attend a ce que le moteur applique certaines contraintes implicites (ex. `VENUE_AT_MOST_ONE`), mais le moteur ne les reconnait pas.

**Que faire** : verifier que les versions du backend et du moteur sont compatibles. Le endpoint `/implicit-constraints` retourne la liste des contraintes implicites connues du moteur. Le backend la compare a sa propre liste. Si elles different, cela signifie qu'un deploiement partiel a eu lieu.

### Generations concurrentes : pas de `503`

Le moteur maintient un verrou asyncio par `clubId`, mais une seconde requete pour un club deja en cours de generation n'est **pas** rejetee : elle est **mise en attente** sur le verrou et s'execute des que la premiere se termine. Les generations d'un meme club sont donc simplement serialisees — aucune erreur HTTP n'est retournee pour ce cas.

### `500 Internal Server Error`

**Cause** : une exception non prevue s'est produite dans le moteur. Cela peut etre un bug dans le code Python, une erreur de memoire, ou un probleme avec la bibliotheque OR-Tools.

**Que faire** : consulter les logs du conteneur `engine`. L'exception complete est tracee. Signaler le bug avec le `clubId`, le `seasonId` et l'heure de l'incident.

---

## Statuts du solveur

Ces valeurs apparaissent dans le champ `status` de la reponse `ScheduleOutputSchema`.

| Statut | Signification |
|--------|---------------|
| `completed` | Le solveur a trouve une solution optimale ou faisable. L'emploi du temps est genere. |
| `failed` | Le solveur a retourne `INFEASIBLE` ou `UNKNOWN`. Aucun emploi du temps n'a pu etre genere. |
| `queued` | Etat transitoire defini par le **backend**, pas par le moteur. La demande est en file d'attente dans le bus Messenger (Redis). |
| `generating` | Etat transitoire defini par le **backend**. Le message a ete consomme par le worker et la generation est en cours. |

---

## Types de diagnostics

Les diagnostics apparaissent dans le tableau `diagnostics[]` de la reponse. Ils decrivent les problemes rencontres pendant la resolution, meme quand le statut est `completed`.

| Type | Severite | Signification | Causes courantes | Action corrective |
|------|----------|---------------|------------------|-------------------|
| `unplaced` | ERROR | Une equipe n'a recu aucune seance | Aucun creneau disponible ne correspond aux contraintes de l'equipe. Tous les slots des salles sont pris. Les contraintes HARD sont trop restrictives. | Ajouter des disponibilites de salle. Alleger les contraintes HARD (ex. lever l'interdiction du vendredi). Ajouter une nouvelle salle. |
| `soft_lock_moved` | WARNING | Un creneau verrouille en SOFT a ete deplace | Le solveur a trouve une meilleure solution globale en deplacant ce creneau. Ou bien le creneau entrait en conflit avec une autre equipe de priorite superieure. | Accepter le deplacement, ou passer le verrou en HARD si le creneau doit absolument rester a cette place. |
| `coach_overload` | WARNING | Un entraineur intervient sur plus de **jours** que son maximum recommande (`maxDaysOverride`) — deux seances le meme jour comptent pour **un** jour, pas deux | Plusieurs equipes partagent le meme entraineur principal. Le seuil `maxDaysOverride` de l'entraineur est trop bas. | Assigner un entraineur supplementaire a certaines equipes. Augmenter le seuil de l'entraineur. Repartir le coaching (entraineur principal + adjoint). |
| `conflict` | ERROR | Deux contraintes HARD se contredisent, ou le solveur n'a pas abouti | Instance INFEASIBLE, budget de temps epuise sans solution (UNKNOWN), modele invalide, ou double-reservation constatee apres coup (gymnase au-dela de sa capacite, coach sur deux equipes en meme temps). | Revoir les contraintes en conflit. Supprimer l'une des deux regles contradictoires. |
| `session_below_effective_min` | ERROR / WARNING | Une equipe recoit moins de seances que demande | **Lire `causes[]` et `openCandidates`, ne PAS deviner** (contrat 2.8, P4-99) : chaque cause est MESUREE au moment ou le solveur a ferme un creneau candidat — `kind` (verrou, gymnase interdit, indispo coach, fenetre horaire, jour interdit, gymnase impose ailleurs), `constraintId`/`label` de la regle en cause, `count` de creneaux fermes. `openCandidates` compte les creneaux restes **ouverts** : la place existait, l'arbitrage global a place autre chose. `causes: []` + `openCandidates` absent = aucune cause mesurable, le message reste neutre. | Corriger la regle nommee par la cause. ⚠ Avant le contrat 2.8 (P4-99), ce diagnostic affirmait « creneaux de gymnase insuffisants » — une cause **devinee, et fausse** : sous le score V10 une seance peut manquer alors que des creneaux etaient libres. Ne jamais reintroduire de cause non mesuree ici. |
| `unused_slot` | WARNING | Un creneau declare n'a recu aucune equipe | Le creneau est incompatible avec les contraintes des equipes restantes (jour interdit, fenetre horaire, gymnase interdit), ou plus aucune equipe n'a de seance a placer. | Reaffecter le creneau a une equipe compatible, ou le retirer des disponibilites du gymnase. |
| `implicit_rule_not_honored` | INFO / WARNING | Une regle implicite de bien-etre n'est pas honoree | `ruleKey` nomme la regle (repos coach, distribution salaries, enchainements, age croissant). **INFO** : la regle a ete assouplie par le gestionnaire (reglee en PREFERRED) — c'est sa decision, pas une erreur. **WARNING** : le solveur n'a pas pu l'honorer malgre le reglage HARD, un verrou etant en cause. | INFO : rien a faire, ou durcir la regle. WARNING : lever le verrou en cause. |
| `unplaced_match` | ERROR | Un match n'a pas pu etre place | Emis par `/place-matches` (rail synchrone, ADR-0003), pas par le solve hebdomadaire. | Ouvrir un creneau compatible, ou revoir la fenetre de la journee. |
| `day_constraint_conflict` | ERROR | Les regles de jours d'une equipe se contredisent | Un jour est a la fois impose (`forcedDays`) et interdit (`forbiddenDays`), ou tous les jours de la liste blanche (`allowedDays`) sont interdits. L'equipe est alors forcee a 0 seance. | Retirer le recouvrement entre la regle "uniquement / impose" et la regle "evite". |
| `venue_minimum_unreachable` | ERROR | Un plancher "au moins N seances dans ce gymnase" est inatteignable | Le gymnase offre a l'equipe moins de **jours distincts** que N (elle joue au plus une seance par jour). | Baisser N, ou ouvrir des creneaux sur d'autres jours dans ce gymnase. |
| `shared_training_not_honored` | ERROR | Un groupe de mutualisation (K seances communes) n'est pas honore | Deux regimes, un seul code : sur INFEASIBLE, cause **certaine** — aucune fenetre de gymnase n'a une capacite ≥ la taille du groupe (le moteur ne l'affirme que quand la capacite l'exclut de facon prouvee) ; sur un solve abouti, defense en profondeur — le nombre reel de seances communes differe du K declare. | Ouvrir un creneau assez capacitaire pour le groupe, ou reduire K / la taille du groupe. |
| `shared_block_not_honored` | ERROR | Un BLOC de mutualisation (P2-51 PR-3 — un ensemble d'equipes qui se comporte comme UNE equipe) n'a pas pu placer ses `commonSessions` seances communes | Meme patron que `shared_training_not_honored`, applique au bloc : sur INFEASIBLE, cause **certaine** — moins de cases (gymnase, jour, heure) communes candidates que de seances demandees ; sur un solve abouti, defense en profondeur — le compte reel de seances communes du bloc diverge du declare. **Distinct du verdict** `shared_block_broken` (`/validate-assignments`, refus d'un DEPLACEMENT qui casserait un bloc deja honore) — celui-ci n'est pas un diagnostic de generation. | Ouvrir un creneau commun aux equipes du bloc, ou reduire son nombre de seances communes. |
| `constraint_not_honored` | INFO / WARNING | Une contrainte saisie n'a pas pu etre appliquee | **INFO** : un verrou HARD l'a ecrasee (P2-9) — indisponibilite coach, fenetre horaire, jour exclu, gymnase interdit. Le verrou prime, la contrainte devient inatteignable. **WARNING** : la contrainte est arrivee sans equipe cible et n'a donc pu etre appliquee a personne. | INFO : retirer le verrou, ou assumer qu'il prime — c'est une decision de gestionnaire, pas une erreur. WARNING : verifier le ciblage de la regle cote backend. |

---

## Scenarios d'infaisabilite courants

Ces situations expliquent pourquoi le statut retourne parfois `failed` (INFEASIBLE).

### 1. Fenetre horaire trop restrictive

**Probleme** : l'equipe U15F1 demande 3 seances par semaine, mais le Gymnase B (sa salle forcee) ne declare que 4 `trainingSlots` par semaine (mardi 18h00 et 19h30, jeudi 18h00 et 19h30). Les candidats du solveur sont **exactement** ces `trainingSlots` explicites — il n'y a pas de decoupage d'une fenetre horaire en departs multiples. Si le SM1 (S) et l'U20M (A) prennent 2 creneaux chacun, il n'en reste que 0 pour l'U15F1.

**Correction** : ajouter des disponibilites au Gymnase B (ouvrir le mercredi soir). Ou reduire `sessionsPerWeek` de l'U15F1 a 2. Ou lever la contrainte de salle forcee.

### 2. Goulot d'etranglement entraineur

**Probleme** : Maxime Dupont est l'entraineur principal (`MAIN`) du SM1, de l'U20M et de l'U15M1. Toutes ces equipes veulent s'entrainer le lundi et le mardi a 19h00. Maxime ne peut pas etre a trois endroits en meme temps. Si les trois equipes ont une contrainte HARD "entraineur = Maxime", le solveur ne peut pas satisfaire tout le monde.

**Correction** : assigner un second entraineur a l'une des equipes. Ou repartir les seances sur differents jours (ex. SM1 le lundi, U20M le mardi, U15M1 le mercredi). Ou definir Maxime comme entraineur adjoint (`ASSISTANT`) pour certaines equipes, ce qui le rend optionnel.

### 3. Monopole de salle

**Probleme** : le SM1 a une contrainte HARD `FORCED_VENUE` sur le Gymnase A. Le SM1 demande 3 seances. Le Gymnase A n'a que 3 creneaux disponibles par semaine (lundi, mardi, mercredi 19h00-20h30). Tous sont absorbes par le SM1. Aucune autre equipe ne peut utiliser le Gymnase A. Si le club n'a qu'une seule salle, les 15 autres equipes restent sans creneau.

**Correction** : ajouter une deuxieme salle (Gymnase B, Salle des Fetes). Ou alleger la contrainte : passer le `FORCED_VENUE` en `PREFERRED` (souhaitable mais pas obligatoire). Ou reduire les seances du SM1 a 2.

### 4. Contraintes cycliques impossibles

**Probleme** : l'equipe U13F1 a trois contraintes HARD :
- "pas le lundi" (contrainte DAY)
- "pas le mardi" (contrainte DAY)
- "pas le mercredi" (contrainte DAY)
- "pas le jeudi" (contrainte DAY)
- "pas le vendredi" (contrainte DAY)

Il ne reste que le week-end. Mais le club n'a pas de salle ouverte le samedi ou le dimanche.

**Correction** : supprimer au moins une des interdictions de jour. Ou ouvrir une salle le samedi matin.

### 5. Trop de verrous HARD

**Probleme** : l'utilisateur a verrouille en HARD 20 creneaux repartis sur toute la semaine. Ces creneaux occupent toutes les places disponibles aux heures de pointe (19h00-20h30). Il reste 5 equipes a placer, mais seuls des creneaux a 08h00 ou 21h00 sont libres. Si ces equipes ont une contrainte HARD "pas avant 18h00", le solveur ne peut pas les placer.

**Correction** : convertir certains verrous HARD en SOFT. Ou les supprimer completement pour laisser le moteur optimiser. Ou ajouter des disponibilites de salle en soiree (21h00-22h30).

> **A lire avant de conclure a un bug de contrainte.** Un creneau verrouille en HARD est pre-place **hors du solveur** : sa variable de decision n'existe pas, donc aucune contrainte saisie ne peut s'y appliquer (le verrou ne "gagne" pas contre la contrainte, il la rend inatteignable). Depuis P2-9, chaque verrou qui ecrase ainsi une contrainte produit un diagnostic `constraint_not_honored` de severite **INFO** nommant la regle, l'equipe, le gymnase, le jour, l'heure et la duree — y compris quand le statut final est `completed`. Si un gestionnaire signale "ma contrainte n'est pas respectee", ces INFO sont le premier endroit ou regarder.

---

## Interpretation du score

Le score est un nombre entier qui reflete la qualite globale de la solution.

| Score | Interpretation |
|-------|----------------|
| 0 | Le solveur a tourne mais n'a place aucune seance. L'instance est quasi-infaisable. Verifier les contraintes HARD. |
| Faible par rapport a l'attendu | Beaucoup d'equipes non placees, ou des equipes de bas tier placees au detriment des equipes de haut tier. Signe de ressources insuffisantes. |
| Eleve | Les equipes de haut tier (S, A) sont bien placees. Les ressources sont suffisantes ou bien reparties. |

### Formule du score

La version actuelle de la formule est :

```
SCORE_FORMULA_VERSION = "T24_LEVEL_2_FIXED_WEIGHTS_V12"
```

⚠ Cette constante bouge plus vite que la prose : en cas de doute, `app/solver/objective/weights.py` fait foi — c'est elle qu'il faut lire, pas ce fichier.

Ce code est incremente chaque fois que les poids de l'objectif changent. Cela permet de comparer des scores entre generations ayant la meme version de formule. Ne pas comparer un score genere avec `T24_LEVEL_2_FIXED_WEIGHTS_V7` a un score genere avec une version anterieure.

### Exemple de calcul

Supposons un club avec :
- SM1 (S, 3 seances) : 3 seances placees = 3 x 10 000 = 30 000
- SF1 (S, 3 seances) : 3 seances placees = 3 x 10 000 = 30 000
- U20M (A, 3 seances) : 2 seances placees = 2 x 1 000 = 2 000
- U15F1 (B, 2 seances) : 2 seances placees = 2 x 100 = 200
- U13M2 (C, 2 seances) : 1 seance placee = 1 x 10 = 10
- Ecole de basket (D, 1 seance) : 0 seance placee = 0

Score de base = 62 210. S'y ajoutent ensuite les termes soft **reels** de l'objectif (poids `LEVEL_2_OBJECTIVE_WEIGHTS`, `app/solver/objective/weights.py`) : `session_count` (+20 par seance placee), `preferred` / `avoided_venue` (+10 / -10 sur le gymnase prefere ou evite), `preferred_day` et `preferred_time` (+5), `rest` (+3, lendemain de match libre), `spacing` (-2, deux seances sur des jours consecutifs), le bonus de chainage (au plus 8, `CHAINING_TIER_WEIGHTS`) et la penalite `UNPLACED_PENALTY` (-100 000 par equipe totalement non placee). Depuis V10 (arbitrage fondateur 2026-08-15 : « le remplissage prime sur le confort »), tous les poids de confort sont recales **sous** la valeur d'une seance nue (tier D 1 + session_count 20 = 21) : le confort departage des solutions qui placent le MEME nombre de seances, jamais une seance contre un confort.

L'objectif ne contient **ni** bonus de preservation des verrous `SOFT` **ni** penalite de surcharge d'entraineur : `soft_lock_moved` et `coach_overload` sont des **diagnostics post-solve**, le solveur les constate apres coup au lieu de les optimiser.

---

## Comment debugger un echec

1. **Verifier les contraintes HARD** : sont-elles toutes realistes ensemble ? Enlever temporairement les contraintes les plus restrictives et relancer.
2. **Verifier les verrous** : combien de creneaux sont en HARD ? Occupent-ils toutes les places aux heures de pointe ?
3. **Verifier les ressources** : nombre de salles, heures d'ouverture, nombre d'entraineurs. Suffisants pour le nombre d'equipes et de seances demandees ?
4. **Verifier les diagnostics** : le moteur retourne-t-il des diagnostics `conflict` ou `unplaced` ? Ils indiquent souvent directement le probleme.
5. **Consulter les metriques** : `nb_variables` et `nb_constraints` donnent la taille du probleme. `wall_time_ms` se compare au **budget adaptatif** du solveur — 60/180/600 s selon la taille du probleme (`n_teams × n_venues` ≤ 50 / ≤ 200 / au-dela), plafonne par `solverTimeoutSeconds` (defaut 650) : s'il est proche du budget, la solution est FEASIBLE mais peut-etre pas OPTIMAL.
