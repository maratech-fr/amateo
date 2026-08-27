# P1-4 — Cadrage du module matchs, tranché sur pièces

> ✅ **LOT LIVRÉ (2026-08-03, 8 PR — cadrage + A→F2).** Ce fichier reste comme TRACE du cadrage (les
> décisions fondateur y sont datées et l'ADR-0003 + l'état des lieux y renvoient) — il n'est plus un
> fichier de détail ACTIF de la roadmap. Le comportement livré vit dans
> [`../courantes/module-matchs.md`](../../specs/courantes/module-matchs.md) ; « est-ce fait ? » se répond dans
> [`../courantes/etat-des-lieux.md`](../../specs/courantes/etat-des-lieux.md) §3.

> **Cadrage fondateur, 2026-08-02.** Posé en réponse à la ligne roadmap P1-4 (« besoin à valider et à
> re-spécifier AVANT tout plan »), au fil d'un échange de challenge, **avec un vrai export FBI sur la
> table** (`specs/initiales/rechercherRencontre.xlsx`, 124 rencontres, saison 2026-27 de BCCL).
> Les §2 à §8 sont des **décisions** — ne pas les re-poser sans fait nouveau. Le §12 reste ouvert.
>
> **Ce document AMENDE** [`gestion-matchs-ffbb.md`](../../specs/evolution/gestion-matchs-ffbb.md) (besoin initial 2026-07-06,
> toujours valable pour le fond : empreinte-temps, catalogue-ligue, annuaire adverse) **et s'appuie sur**
> [`ffbb-appariement-source-de-verite.md`](../../specs/evolution/ffbb-appariement-source-de-verite.md) (appariement, poule
> garde-fou, gymnases de match). Le livré est dans
> [`module-matchs.md`](../../specs/courantes/module-matchs.md) (palier A, PR-1 à PR-4).
> **Pas un plan** — le phasage §11 découpe le lot, chaque PR aura son plan (Full lane).

---

## 1. L'objectif produit, chiffré par le fondateur

> **« C'est une tâche de 3 jours pleins qui doit passer à 3 heures avec notre outil.
> L'arrivée d'une nouvelle poule ne doit pas être un état de stress pour le gestionnaire. »**

Et la finalité de l'anticipation :

> **« Plus on est au courant des soucis que l'on va avoir, plus on peut anticiper et demander des
> dérogations. »**

Le module aide le gestionnaire à **prévoir ses créneaux et placer les matchs de la saison** : la liste des
rencontres est imposée (ligue/comité), seul le **domicile** se place (heure + salle), l'extérieur **compte**
(il occupe les personnes). La valeur = placer vite, et **voir les problèmes tôt**.

---

## 2. Décision centrale : le solveur place, le gestionnaire ajuste

> Fondateur : « je pensais réutiliser le solveur pour placer les matchs avec les contraintes que l'on a
> déjà. Puis manuellement on peut bouger les créneaux, les échanger, etc. »

**Renverse** `gestion-matchs-ffbb.md` §2/§12 (« pas un problème solveur, placement manuel »). Le patron
retenu est **celui du planning** : générer → boucle de travail manuelle (bouger, échanger, verrouiller).

- Le solveur reçoit **tout l'horizon connu** d'un coup (cohérence des habitudes entre week-ends), les
  matchs **verrouillés ou placés à la main sont respectés**, re-solve possible après chaque import/phase.
- « Le solveur donne un horaire qui respecte les contraintes » = axe **constraint semantics** (§7.1
  CLAUDE.md) → **NR sémantique obligatoire** + smoke-solver.
- La boucle manuelle comble les trous UI actuels du même geste : éditer, supprimer, dé-placer, déplacer un
  match — aujourd'hui un `Fixture` créé est irrattrapable depuis l'UI (`api.ts` n'expose ni delete ni
  update hors placement, alors que le backend expose le CRUD complet).

**Pas de « versions ».** Envisagées puis écartées : il n'y a **rien à comparer** — la réalité de la ligue
arrive par phases et l'état se **précise** (prévision → réel). La réalité **gagne toujours** ; quand elle
casse une prévision (switch domicile↔extérieur), on **alerte**, on ne archive pas d'alternative.

---

## 3. Le format FBI réel — mesuré, le « format supposé » est mort

Échantillon : export FBI « Saisie des résultats » de BCCL, **fichier GLOBAL club** (pas par équipe),
124 rencontres, 14 divisions, du 19/09/2026 au 10/04/2027.

Colonnes : `Division · N° de match · Equipe 1 · Equipe 2 · Date de rencontre · Heure · Salle ·
e-Marque V2 · Score 1 · Forfait 1 · Score 2 · Forfait 2`.

Faits mesurés qui dictent l'import (chacun vérifié dans le fichier) :

| # | Fait | Conséquence d'implémentation |
|---|---|---|
| F1 | Fichier **global** : toutes les divisions mêlées (DF2, PRM, PNM, PNF, RM2, RF3, DM2, RMU21, 6 brassages jeunes) | L'import « un fichier par équipe + équipe choisie à l'upload » (PR-4) est à jeter ; l'upload devient club-wide |
| F2 | **`Heure = 00:00` = heure non fixée** (tout le départemental : DF2, DM2, PRM) ; le régional/PN arrive horodaté (15:30, 20:30) | `00:00` → `kickoffTime = null` (statut `UNPLACED`). Le stocker comme minuit fabriquerait des faux placements |
| F3 | `Salle` renseignée même sans heure, **et pour les matchs extérieur** (salle de l'adversaire) | **Stocker le libellé salle** (aujourd'hui « lue mais non stockée ») : salle par défaut au placement domicile, matière trajet/empreinte pour l'extérieur — gratuite |
| F4 | Brassages **sans colonne Salle** + libellés `B CHARPENNES CROIX LUIZET (10)` (suffixe de poule), format ≠ championnats (`- 3`) | Parseur tolérant aux deux formats de libellé ; salle absente = normal |
| F5 | **`Exempt`** comme Équipe 1 ou 2 (journées de repos DM2) | À **sauter proprement** (ligne rapportée « exempt », ni créée ni en erreur) |
| F6 | `N° de match` **non unique au fichier** (le « 26 » existe en RMU18 Brassage ET en DF2) | Idempotence scopée **par division→équipe**, jamais globale — l'unique partiel actuel `(club, season, team, external_ref)` tient si la division résout l'équipe d'abord |
| F7 | Le suffixe seul n'identifie pas l'équipe : `- 3` = DF2 (féminine) **et** PRM (masculine) | La clé = **correspondance Division ↔ équipe app, persistée** (roadmap point 2). Elle existe déjà sous forme de `Competition` (name = division, `teamId`) — le ré-import la retrouve, il ne la redemande pas |
| F8 | Division avec **espace traînant** (`RFU13 Brassage `) ; tri du fichier insignifiant (jour-du-mois décroissant) | Normalisation des libellés ; aucune hypothèse d'ordre |
| F9 | `e-Marque V2`, scores, forfaits présents | Ignorés au placement (le forfait général est un sujet ouvert, `ffbb-appariement…` §8 — « réel, pas prioritaire ») |

**Ré-import = mise à jour, pas seulement idempotence.** La ligue **re-programme** (date déplacée) et peut
**forcer un switch domicile↔extérieur**. Le ré-import diffe par `externalRef` : date/heure/salle/homeAway
changés → mise à jour + **signalement** (« le 3 mars SM1 devient extérieur — créneau libéré, comblable ou
non »). Un match `PLACED` dont la date bouge redevient à placer, avec alerte. PR-4 skippait les connus :
c'était le contraire du besoin.

**Sources par morceaux.** Ce fichier ne couvre que **la ligue** ; « le comité n'a pas encore répondu, donc
on n'a pas encore les matchs des plus jeunes et des équipes 2 et 3 » (fondateur). L'import est
**multi-fichiers, incrémental**, chaque fichier complète — même format FBI attendu (à vérifier à réception,
§12).

---

## 4. Architecture — cinq couches

```
┌─ 1. SOURCES ──────────────────────────────────────────────┐
│ API FFBB (engagements → compétitions/poules/adversaires,  │
│ ré-appariement 1 clic par phase) × import FBI GLOBAL club │
│ Garde-fous : poule (mauvais fichier) + complétude         │
│ (journées attendues vs importées)                         │
├─ 2. CAPACITÉ ─────────────────────────────────────────────┤
│ Gymnases de match (sous-ensemble) + fenêtres d'accès      │
│ mairie week-end · indisponibilités/événements ·           │
│ habitudes par équipe (protègent les week-ends futurs)     │
├─ 3. PLACEMENT (solveur → boucle manuelle) ────────────────┤
│ Domicile : salle+heure sous enveloppe ligue HARD +        │
│ collisions (autre match, entraînement, événement)         │
├─ 4. DIAGNOSTIC ───────────────────────────────────────────┤
│ Conflits personnes (coach MAIN > ASSISTANT, passerelles)  │
│ + collisions + prévisions cassées + complétude → TÔT      │
├─ 5. ACTION (hors P1-4) ───────────────────────────────────┤
│ Dérogation : le gestionnaire la fait LUI-MÊME, alerté tôt │
│ · vision sportive (rivaux, journées thématiques)          │
└───────────────────────────────────────────────────────────┘
```

Le « wizard matchs » (forme pressentie, roadmap point 6) = le parcours qui traverse ces couches, **ré-entrant
à chaque phase** : nouvelle poule → apparier (1 clic) → importer → générer → ajuster → diagnostic. La page
n'arrive jamais vierge (engagements FFBB pré-remplissent).

---

## 5. Capacité : accès mairie, gymnases de match, indisponibilités

Trois décisions fondateur, 2026-08-02 :

1. **Les accès week-end ≠ les accès semaine.** « La mairie donne des créneaux et des accès au club
   différents de la semaine. On n'a plus accès à l'annexe et à De Barros que pour la charge
   d'entraînement. » → nouvelle donnée : **fenêtres d'accès match par gymnase** (jour week-end + plage),
   saisies par le club. Un gymnase sans fenêtre match = gymnase d'entraînement seulement. Ceci **règle la
   collision wizard** relevée en `ffbb-appariement…` §7 (le wizard exige ≥ 1 créneau d'entraînement par
   gymnase — un gymnase de match seul n'en a pas : les fenêtres match sont une donnée distincte, le wizard
   n'exige rien d'elles).
2. **Indisponibilité gymnase = toutes circonstances.** « Si le gymnase est indisponible du 4 au 28 février,
   c'est pour toute circonstance et on ALERTE le gestionnaire que cela affecte les matchs prévus qu'il va
   devoir repositionner. » Posée **au calendrier** (par gymnase, plage de dates), elle touche **les
   entraînements ET les matchs** — dans ce sens-là : une seule saisie, deux surfaces d'alerte. Aucune
   entité de ce type n'existe aujourd'hui (vérifié : seuls `Venue`, `VenuePeriodOverride`,
   `VenueTrainingSlot`).
3. **Habitudes par équipe.** « Nous sommes des êtres d'habitude » : SF3 = dimanche 17h30 ; « les SF1 et PNM
   ont le créneau du samedi soir : soit un après l'autre, soit 20h30 — c'est la volonté du club. » →
   **fenêtre habituelle par équipe** (jour, heure, gymnase), déclarée par le gestionnaire et **pré-remplie
   par inférence** depuis les matchs connus (PNM = 6× samedi 15:30 à Mateo dans l'échantillon : l'inférence
   est triviale). Elle sert trois fois : préférence SOFT du solveur, **protection des week-ends futurs**
   (l'équipe dont le calendrier de mars n'existe pas encore garde sa fenêtre — personne ne la mange), et
   estimation d'heure pour l'extérieur. Remplace le `Team.preferredMatchWindow` de P3-1 (absorbé).

La **prévision protège, la réalité gagne** : si la ligue place SM1 à l'extérieur le 3 mars, la fenêtre
habituelle de SM1 ce week-end-là se libère — signalé, comblable. Confort additionnel : le **compactage**
(« dimanche on n'a que 2 matchs : 12h et 15h, c'est plus agréable qu'un 9h et un 17h30 avec un énorme trou »)
= préférence SOFT du solveur, jamais une règle.

---

## 6. Passerelles : un lien d'équipes déclaré, cross-module

**Fait vérifié qui contraint le design : les joueurs n'existent pas dans les données.** Aucune entité
joueur — seulement `Coach` et `CoachPlayerMembership` (le coach qui joue). Les « double projets » SM1/SM2 ne
sont pas déductibles. La passerelle v3 (`team_links`, stubs engine `required_bridge`) n'a jamais été
implémentée (roadmap, ligne Vision).

Décision : la règle « SM1 et SM2 pas en même temps » = **lien entre équipes déclaré par le gestionnaire**
(« ces deux équipes partagent des joueurs »), **SOFT** — le solveur évite, le diagnostic signale si violé.
Pas de gestion de joueurs individuels.

> Fondateur : « la passerelle est aussi une notion que j'aimerais introduire pour les entraînements, pour
> éviter que les SF1 et SF2 ne s'entraînent en même temps si possible. »

→ La notion se modélise **cross-module** (une entité de lien d'équipes, consommée d'abord par le placement
des matchs ; le volet entraînement = lot ultérieur, ligne Vision roadmap « matrice trajet + passerelles »
— on ne le code pas ici, on ne le rend juste pas impossible). L'exemple « SF1 et PNM enchaînés ou 20h30 »
(§5.3) suggère un second type de lien (enchaînement souhaité) — design fin au plan de la PR concernée.

---

## 7. Le solveur de placement (engine)

Nouveau problème pour l'engine — distinct du planning hebdo (ici des **dates réelles**) :

- **Variables** : (heure de coup d'envoi, gymnase) par match domicile non verrouillé.
- **HARD** : fenêtre ligue quand l'équipe est mappée (`LeagueMatchWindow`, patron 3 couches existant) ·
  fenêtres d'accès match du gymnase · non-chevauchement des matchs dans un gymnase (empreinte 2h15) ·
  indisponibilités gymnase · verrous manuels.
- **SOFT** (poids à fixer au plan) : fenêtre habituelle de l'équipe · conflits personnes (coach **MAIN**
  lourd, **ASSISTANT** léger — `TeamCoachRole` existe ; « si l'assistant n'est pas dispo c'est moins
  grave ») · conflit match↔entraînement (planning effectif à la date, logique `MatchConflictDetector`
  existante) · passerelle (non-simultanéité) · enchaînement souhaité · compactage de journée · protection
  des fenêtres habituelles des équipes sans calendrier.
- Un problème **infaisable ne bloque pas** : les matchs plaçables se placent, les autres remontent en
  diagnostic (le gestionnaire demandera sa dérogation) — à cadrer finement au plan (ADR-0001 interdit la
  relaxation silencieuse ; ici le « non-placé expliqué » est le livrable, pas un échec).

Mécanique : nouveau contrat backend↔engine (payload + `CONTRACT_VERSION` bump + `ContractSchemaTest`),
**ADR** (décision structurante : le solveur sort du périmètre hebdo), NR **constraint semantics**
(une contrainte saisie → honorée dans le placement), smoke-solver obligatoire.

---

## 8. Le diagnostic (le « radar » devient un état des lieux gradué)

> Fondateur : « je dis le radar mais je parle du diagnostic : quelque chose qui permet au gestionnaire de
> voir les problèmes si la grille de match est comme ça. »

Findings, par sévérité décroissante (liste de cadrage, à figer au plan) :

1. Collision de salle (deux matchs, ou match×événement) — HARD violé, ne devrait pas survivre au solveur,
   mais la boucle manuelle peut la créer → bloquant à confirmer au plan.
2. Hors fenêtre ligue (équipe mappée) — aujourd'hui garde HARD au placement manuel, conservée.
3. Conflit coach **MAIN** (match×match, match×entraînement — moteur existant).
4. Prévision cassée (switch/re-programmation au ré-import) + match placé dont le gymnase devient indispo.
5. Conflit coach **ASSISTANT** · passerelle violée.
6. Complétude (journées attendues vs importées, par compétition — quand l'appariement FFBB donne la poule).
7. Extérieur sans heure estimée = **pas d'empreinte** (comportement PR-2 conservé) — signalé comme angle
   mort, l'estimation par fenêtre habituelle (§5.3) le résorbe.

**Dérogations : l'outil alerte tôt, le gestionnaire agit.** « C'est le gestionnaire qui le fait, mais il
est alerté par notre outil. » **Pas de tracker** dans P1-4 (le mini-tracker de `gestion-matchs-ffbb.md` §8
reste une évolution ultérieure).

---

## 9. Modèle de données (delta sur le livré)

| Objet | Sort |
|---|---|
| `Fixture` | Évolue : `kickoffTime` null si FBI `00:00` · + libellé salle FBI (domicile ET extérieur, F3) · update au ré-import (diff par `externalRef`) |
| `Competition` | **EST** la correspondance Division↔équipe persistée (F7) · + réf FFBB (`idCompetition`/`idPoule`) posée à l'appariement · + attendus de complétude |
| Fenêtre habituelle d'équipe | **Nouveau** (jour, heure, gymnase — remplace `Team.preferredMatchWindow` de P3-1) |
| Lien d'équipes (passerelle) | **Nouveau**, cross-module, SOFT (§6) |
| Fenêtres d'accès match par gymnase | **Nouveau** (§5.1) — règle aussi « gymnase utilisé en match » (Parking roadmap, prérequis P1-4 : le flag devient « a ≥ 1 fenêtre match ») |
| Indisponibilité gymnase | **Nouveau** (§5.2), plage de dates, toutes circonstances, consommée par matchs ET entraînements (alerte) |
| `LeagueMatchWindow` | Inchangée ; auto-clé par `codeLigue`/`codeComite` de l'engagement = amélioration prévue (`ffbb-appariement…` §6.4), pas un préalable |
| Annuaire adverse global | **Pas dans P1-4** (`opponentLabel` + salle FBI suffisent) — reste palier ultérieur avec ses garde-fous sécu (§5bis du besoin initial) |

Toutes les nouvelles entités tenant-owned suivent le rail : RLS + filtres + NR tenant.

---

## 10. Périmètre go/no-go prod

> Fondateur : « **Tout sauf la vision sportive** ; et la dérogation, c'est le gestionnaire qui la fait,
> mais il est alerté par notre outil. »

**Dans** P1-4 : import FBI réel multi-fichiers avec update/diff · appariement FFBB (poule garde-fou +
complétude) · capacité (accès match, indisponibilités, habitudes) · solveur de placement + boucle manuelle
complète (éditer/supprimer/déplacer/échanger/verrouiller) · diagnostic gradué (coachs MAIN/ASSISTANT,
passerelles, collisions, prévisions cassées).

**Hors** P1-4 (roadmap, pas perdu) : vision sportive (rivaux définis, journée 100 % féminine — bonus
assumé) · tracker dérogations · matrice de trajet réelle (lat/long déjà sur `Venue`, la salle extérieure
F3 prépare le terrain — l'empreinte trajet reste à 0 comme aujourd'hui) · volet joueurs individuels ·
passerelle côté solveur d'entraînement · annuaire adverse global · forfait général.

---

## 11. Phasage pressenti (chaque PR = Full lane, ordre indicatif)

| PR | Contenu | Axes §7.1 touchés |
|---|---|---|
| A | **Import FBI réel** : reprise complète (fichier global, F1-F9), diff/update + signalements, multi-fichiers | périmètre engagé (l'import EST l'engagement), tenant |
| B | **Capacité** : fenêtres d'accès match, indisponibilités gymnase (+ alerte côté planning), gymnases de match | tenant ; planning (surface d'alerte) |
| C | **Habitudes + passerelles** : déclaration + inférence, liens d'équipes | tenant |
| D | **Solveur de placement** : contrat engine, génération, NR sémantique, smoke | contrat backend↔engine, constraint semantics |
| E | **Boucle manuelle + diagnostic** : éditer/supprimer/déplacer/échanger/verrouiller, diagnostic gradué UI, extérieur visible | — |
| F | **Appariement FFBB** : engagements → réfs sur `Competition`, poule garde-fou, complétude | tenant (données FFBB par club) |

A est le socle de tout ; B/C sont parallélisables ; D dépend de B+C ; E affine D ; F enrichit A (garde-fou
poule inopérant sans lui — l'import marche sans, en moins sûr). Découpage fin au premier `/plan`.

---

## 12. Ouverts (non bloquants)

- **Format de l'export comité** : supposé identique FBI — à vérifier à réception (le comité n'a pas
  répondu). Si différent : même patron, second parseur.
- **Coupes** : `CompetitionType::CUP` existe, aucune dans l'échantillon — le format FBI d'une coupe reste à
  voir passer.
- **U9/U11 & plateaux** : hors échantillon (comité) — probablement des plateaux, pas des rencontres 1v1 ; à
  qualifier quand les fichiers arrivent.
- **Sévérité fine** passerelle vs coach ASSISTANT ; caractère bloquant ou non de la collision de salle en
  boucle manuelle.
- **Amicaux** : la saisie existe (competition null) ; vérifier au plan de la PR E que le geste couvre
  domicile ET extérieur (aujourd'hui un extérieur créé devient invisible dans l'UI).
- Re-tester `ffbbserver_rencontres` (une requête) au moment d'attaquer le lot — sans rien y construire
  (consigne roadmap).

---

## 13. En une phrase

Le calendrier tombe par morceaux, l'outil l'avale (import + appariement), le solveur pose les matchs
domicile dans les créneaux que la mairie et la ligue laissent, et le gestionnaire — alerté tôt et
précisément — ajuste, échange, verrouille, et demande ses dérogations à temps : trois jours de travail
deviennent trois heures.
