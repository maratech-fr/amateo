# `config` d'une contrainte — la liste blanche (SEC-13)

Last verified @ 2026-09-26 (`documentation-update`, passe « le présent » zone backend).
Re-confronté à `ConstraintConfigValidator::SPEC`
(`backend/src/Service/ConstraintConfigValidator.php:59-95`) : les 4 familles et leurs clés/types
correspondent trait pour trait à la table du fichier ✓. `App\Enum\ConstraintRuleType` ne compte
que HARD/PREFERRED/LOCK ✓ — `BONUS` n'existe nulle part dans l'engine comme cran de `ruleType`
(`rtk grep -rn BONUS engine/app/solver/constraints engine/app/schemas` vide), la mention de sa
normalisation passée est retirée. `TeamTagResolver::resolveConstraintTeamIds`
(`backend/src/Service/TeamTagResolver.php:278`) existe toujours à cette ligne exacte ✓. La
migration `Version20260807190000` est confirmée en place ✓. Non re-sondé cette passe : les deux
gardes `PeriodGatePayloadParityTest`/`ConstraintKeysAreHonouredByEngineTest` (déjà vérifiées la
passe précédente). Historique : `git log -p --follow`. Un stamp REMPLACE, il ne s'empile pas.

> Source de vérité du code : `App\Service\ConstraintConfigValidator`.
> Cette page explique le POURQUOI ; la liste qui fait foi est dans la classe.

Le `config` était le seul champ du formulaire de contrainte sans aucune
validation. Mesuré sur l'API réelle le 2026-08-07 : `{"maxStartTme": "19:00"}`
— une lettre en moins — rendait **201**, la contrainte s'affichait « Rien après
19h · HARD · active », et le solveur plaçait la séance à **20:00**. Le
gestionnaire distribue un planning en croyant une règle appliquée ; elle n'existe
pas. Depuis SEC-13, toute clé hors liste est refusée **à l'écriture** en 422,
avec le nom de la clé et les réglages acceptés pour la famille.

## La table

| Famille | Clé | Type attendu | Lue par |
|---|---|---|---|
| **TIME** | `minStartTime` `maxStartTime` `maxEndTime` | `HH:MM` | moteur (`constraints/` — paquet) |
| **DAY** | `preferredDays` `forbiddenDays` `forcedDays` `allowedDays` | liste d'entiers 1-7 (lundi = 1) | moteur (`constraints/`, `objective.py`) |
| **FACILITY** | `forcedVenueId` `forbiddenVenueId` `preferredVenueId` `minAtVenueId` | UUID de gymnase | moteur (`constraints/` — paquet) |
| **FACILITY** | `minAtVenueCount` | entier ≥ 1 | moteur |
| **FACILITY** | `type` (`venue_closed`) · `startDate` · `endDate` | constante · `AAAA-MM-JJ` | **backend seul** (`VenueClosureDays`) — une fermeture datée DÉRIVE un défaut de jours fermés (jamais stockée telle quelle) ; le réglage du plan (`VenuePeriodOverride.mode`/`dayOverrides`) peut le contredire jour par jour — la composition des deux vit dans `PlanVenueClosures::effectiveStateForPlan/Entry` (décision fondateur 2026-08-18 : l'indisponibilité déclarée est INFORMATIVE), et c'est l'état EFFECTIF qui ne produit aucune ligne de payload pour les jours fermés. **Suit le re-datage d'une racine CLOSURE (D3 v1, 2026-09-04)** : quand `startDate`/`endDate` de cette contrainte valent EXACTEMENT l'ancienne fenêtre de l'entrée, ils sont recalés sur la nouvelle (`CalendarEntryStateProcessor::redateEntryPairedConstraints`) — une fermeture datée plus finement par le gestionnaire (dates différentes) reste intouchée |
| **COACH_AVAILABILITY** | `unavailableDays` `availableDays` | liste d'entiers 1-7 | moteur (`constraints/` — paquet) |
| **COACH_AVAILABILITY** | `fromTime` `untilTime` | `HH:MM` | moteur — bornent l'indisponibilité dans la journée |
| **toutes** | `targetTag` | libellé de groupe non vide | **backend seul** — éclaté en N contraintes par équipe, puis RETIRÉ du payload (`ScheduleConstraintBuilder`). **Forme HISTORIQUE, toujours lue** : équivaut à `targetTags: [x]` |
| **toutes** | `targetTags` | liste de tags | **INTERSECTION** — l'équipe doit porter TOUS ces tags (ex. `["SENIOR","COMPETITION"]`). Mélanger avec `targetTag` → **422** (jamais d'ambiguïté silencieuse) |
| **toutes** | `excludeTags` | liste de tags | **UNION soustraite** de la cible (ex. `targetTags:["ADULTE"], excludeTags:["LOISIR_ADULTE"]` → les adultes en compétition, sans le loisir adulte). **Sans `targetTags`** : la base est TOUTE la saison, moins les exclus |


> ⚑ **Résolution des cibles par tag — foyer UNIQUE** (`TeamTagResolver::resolveConstraintTeamIds`,
> lot tags PR 2, 2026-08-15) : « (∩ `targetTags`) − (∪ `excludeTags`) », tri contractuel des
> teamIds conservé. Le **payload solveur** (`ScheduleConstraintBuilder`) ET le **verdict du gate de
> période** (`PeriodConstraintSelector::clubTagVerdict`) passent par ce foyer — leur parité est un
> step bloquant (`PeriodGatePayloadParityTest`). **Le contrat moteur ne bouge pas** : les 3 clés de
> tag sont retirées à la sérialisation, le moteur ne reçoit que des contraintes d'ÉQUIPE résolues.
> Refus à l'écriture (422) : tag inconnu du club · `targetTags ∩ excludeTags` non vide · mélange
> `targetTag`+`targetTags` · résolution VIDE sur la saison courante. Le no-op+warning du builder
> reste en backstop (une résolution peut se vider APRÈS coup — équipes désactivées).

## Quelle INTENSITÉ pour quelle clé — la matrice muette (ALIGN-14, 2026-09-22)

Une clé de la liste blanche n'est pas honorée à tous les crans. Le moteur range les règles par
`ruleType` **avant** de les appliquer : le chemin dur ne lit que HARD/LOCK
(`engine/app/solver/constraints/targeting.py`), le chemin souple filtre `ruleType == "PREFERRED"`
strictement et ne connaît qu'une poignée de clés (`engine/app/solver/objective/terms.py`). Une clé
posée au mauvais cran tombe donc entre les deux : **elle s'affiche comme active et ne fait rien**.

| Clé | Cran refusé | Pourquoi elle serait muette |
|---|---|---|
| `maxEndTime` | hors HARD/LOCK | le chemin souple ne lit que `minStartTime`/`maxStartTime` |
| `forcedDays` | hors HARD/LOCK | les règles DAY dures ne sont collectées que pour HARD/LOCK ; le souple ne lit que `preferredDays` |
| `allowedDays` | hors HARD/LOCK | rangée en fenêtre de temps côté dur (sautée par le filtre de cran), jamais lue côté souple |
| `forcedVenueId` | hors HARD/LOCK | la carte des gymnases imposés n'est nourrie qu'en HARD/LOCK |
| `preferredDays` | **en HARD/LOCK** | symétrique : le chemin dur ne lit pas cette clé, et le souple exige PREFERRED. Une préférence ne peut pas être obligatoire par nature (décision fondateur 2026-09-22) |
| `preferredVenueId` | **en HARD/LOCK** | refusé plus tôt, **à l'écriture** (422) — seule cellule gardée par le write-path |

⚠ **Ces refus ne vivent PAS dans le chemin d'écriture** (sauf `preferredVenueId`) : ils sont rendus
par `App\Service\ConstraintValidationService`, lue par le **récap pré-génération**
(`ValidateConstraintsController`). Une écriture directe par API passe donc toujours ; la règle est
nommée avant la génération, pas au moment de la saisie. C'est le patron historique, pas un oubli —
le déplacer vers le 422 serait une décision à prendre, pas un correctif.

⚑ **La preuve vit ailleurs** : `ConstraintKeysAreHonouredByEngineTest` (testsuite `Contract`, jouée
par le required check `engine-semantics`) envoie chaque cellule au VRAI moteur et vérifie qu'elle
change ce qu'il fait. Une cellule souple s'y prouve par le **choix** — une grille à deux issues de
coût identique où seul le terme souple les départage — jamais par un score : un score bouge aussi
quand un bonus est accroché à la mauvaise condition.

⚑ **`BONUS` n'existe pas comme cran de `ruleType`** : l'enum `ConstraintRuleType` ne compte que
HARD/PREFERRED/LOCK — la table ci-dessus n'a donc que trois crans à connaître, jamais quatre.

## Trois règles pour maintenir cette liste

**1. Une clé entre par son LECTEUR, jamais par la donnée.** Un premier inventaire
tiré de la base a manqué `type`/`startDate`/`endDate` : aucune ligne ne les
portait ce jour-là, mais le cockpit en crée à chaque fermeture de gymnase
(`frontend/src/features/cockpit/queries.ts`). Livrer la liste déduite des données
aurait cassé ce geste au premier usage.

**2. Une clé moteur doit PROUVER qu'elle change le résultat.**
`ConstraintKeysAreHonouredByEngineTest` (job CI « Engine semantics ») construit,
pour chaque clé, un payload où elle est décisive, l'envoie au **vrai moteur**, et
exige que le résultat diffère de celui obtenu sans elle. Ajouter une clé sans son
scénario fait rougir la CI : **on ne peut pas déclarer sans prouver**.

**3. Une seule orthographe.** Le moteur acceptait des alias snake_case
(`forbidden_days`, `preferred_days`) que personne n'émettait ; ils ont été retirés
du moteur en même temps que l'API a cessé de les accepter. Deux façons d'écrire
une règle, c'est deux endroits où la chercher le jour où elle ne s'applique pas.

## Ce que la liste ne contient pas, et pourquoi

- **`coachId`** — doublon exact de `scope_target_id` (6 lignes sur 6, mesuré),
  supprimé par `Version20260807190000`. La cible d'une contrainte de
  disponibilité est le SCOPE.
- **`dateStart` / `dateEnd`** — recopiées vers un payload que le moteur ignore
  (`extra="ignore"`), lues par personne, zéro ligne en base. Autoriser une date
  sans effet, ce serait fabriquer le mensonge qu'on corrige.

## Où le contrôle s'applique

- **À l'écriture** (`POST`/`PUT /api/constraints`) → **422**, la donnée fautive
  n'entre pas. C'est la barrière principale.
- **Au pré-solve** (`/api/constraints/validate`, le récap du wizard) → le MÊME
  validateur, pour la forme. Il rattrape ce qui est entré hors API (fixtures,
  imports, SQL direct). Le reste du pré-solve — contradictions entre contraintes,
  coach doublé, capacité, gymnase fermé — n'est pas concerné : l'écriture ne peut
  pas voir ces choses-là.
