# Documentation technique du flux de génération de planning

Last verified @ 2026-08-26 (rotation `documentation-update`, P2-53 PR-3 — zone non touchée par
cette PR, contrôle de fraîcheur). **Drift trouvé et corrigé** : les trois occurrences de
`version: "2.15"` (exemple de payload §3/§4) et « contrat 2.15 » (tableau des pannes) étaient
périmées — `ScheduleConstraintBuilder::CONTRACT_VERSION` vaut **`2.16`** depuis P2-53 RMM-8 PR-2
(`ScheduleConstraintBuilder.php:62`), le bump 2.15→2.16 n'avait jamais été répercuté ici. Reste
vérifié sans écart : `toArray(false)` sans exception HTTP (`EngineClient.php:39,58`) ✓ ·
`engine_timeout`/`engine_error` émis par le handler (`GenerateScheduleHandler.php:290,298`),
`engine_failed` par `ScheduleDiagnosticsRecorder` (`:65,75`) ✓ · verrou Redis `nx`/`ex`
(`ClubGenerationLock.php:26`) ✓. Le payload SSE et son transport avaient dérivé depuis
FRT-04/P4-123 (2026-08-22, remontée de `shared/` vers `features/planning/`) — le front ne
s'abonne plus planning par planning mais à un **sélecteur TEMPLATE de club**
(`club:{clubId}:schedule:{id}`, `GET /api/mercure/auth`), et le payload publié porte désormais
**`scheduleId`** en plus des quatre champs déjà documentés (`ScheduleProgressPublisher.php:41-44`)
— sans lui l'événement ne dirait pas de quel planning il parle ; §6 recalé en conséquence.

> ClubScheduler — Symfony 7 + API Platform + Messenger Redis + Mercure SSE. Contexte : BCCL (B CHARPENNES CROIX LUIZET, code FFBB ARA0069036, ligue ARA).

---

## 1. Vue d'ensemble du flux

La génération d'un planning au BCCL suit un pipeline asynchrone en cinq étapes. L'utilisateur clique sur "Générer", le backend délègue le travail lourd à un worker, appelle le moteur de calcul Python, importe le résultat, et notifie le frontend en temps réel.

```
Utilisateur (interface React)
    ↓ POST /api/schedules/{id}/generate
GenerateScheduleController
    ↓ dispatch(GenerateScheduleMessage)
Messenger Bus (async, transport Redis)
    ↓
GenerateScheduleHandler (conteneur messenger-worker)
    ├─ 1. Verrou Redis (ClubGenerationLock)
    ├─ 2. Validation + construction payload (ScheduleConstraintBuilder)
    ├─ 3. POST http://engine:8000/generate
    ├─ 4. Traitement réponse (ScheduleResultImporter)
    └─ 5. Notification temps réel (Mercure SSE)
         ↓
    Frontend (EventSource)
    ↓ Rafraîchissement automatique du calendrier
```

Ce flux est **non bloquant** pour l'utilisateur. La requête HTTP initiale retourne immédiatement. Tout le travail se fait en arrière-plan, dans le conteneur `messenger-worker`.

---

## 2. Étape 1 — Déclenchement

### 2.1 Action utilisateur

Dans l'interface du BCCL, l'administrateur du club ouvre le planning de la saison 2025-2026, vérifie que les équipes (SM1, SM2, SM3, SF1, SF2, SF3, U9M, U9F, U11M, U11F, U13M1, U13M2, U13F, U15M, U15F, U18M, U18F) et les salles (ADN, Jean Vilar, Matéo, Gymnase Nord) sont bien configurées, puis clique sur le bouton **"Générer le planning"**.

### 2.2 Requête HTTP

Le frontend envoie :

```http
POST /api/schedules/550e8400-e29b-41d4-a716-446655440000/generate
Authorization: Bearer <token>
```

### 2.3 Traitement par GenerateScheduleController

Le contrôleur `GenerateScheduleController` commence par **trois refus synchrones** — aucun ne crée de planning `PENDING` ni de diagnostic, l'appelant reçoit l'erreur immédiatement :

1. **409 Conflict** si la version est celle que son plan **pointe** : c'est le planning en vigueur, il faut d'abord le **rouvrir** (`POST /api/schedules/{id}/reopen`).
2. **422 Unprocessable Entity** si `GenerationComplexityGuard` juge le problème hors bornes (garde anti-DoS A10 « generation bomb » : plafonds généreux, ~10× un gros club FFBB ; les contraintes y sont comptées **permanentes only**, comme dans le payload).
3. **422 Unprocessable Entity** si `OrphanPinGuard` trouve un **épinglage orphelin** — un verrou ou une réservation qui ne correspond plus à aucun créneau après une refonte de la grille de période. Le message nomme le gymnase et le jour ; c'est au gestionnaire de trancher (planning de période uniquement).

Ces gardes passées, il exécute trois actions :

1. **Vérification des droits** : l'utilisateur appartient-il au club propriétaire du planning ?
2. **Mise à jour du statut** : `Schedule.status` passe de `DRAFT` à `PENDING`.
3. **Dispatch du message** : un objet `GenerateScheduleMessage` est envoyé sur le bus Messenger async (transport Redis).

Le contrôleur retourne immédiatement :

```json
{
  "message": "Schedule generation queued"
}
```

Code HTTP : `202 Accepted`. L'utilisateur peut fermer son navigateur, la génération continuera.

---

## 3. Étape 2 — Traitement asynchrone (GenerateScheduleHandler)

Le handler `GenerateScheduleHandler` s'exécute dans le conteneur Docker `messenger-worker`. Il consomme les messages de la file Redis `async` un par un.

### 3a — Verrou Redis (ClubGenerationLock)

Avant tout traitement, le handler tente d'acquérir un verrou Redis :

```
SET schedule_generation:club:{clubId} <token> NX EX {timeoutSeconds + 60}
```

- La **valeur** est un token aléatoire (16 octets hex) : seul le détenteur du token peut libérer le verrou (compare-and-delete atomique en Lua).
- `NX` : uniquement si la clé n'existe pas encore.
- `EX` : expiration automatique après `timeoutSeconds + 60` secondes, soit **710 s** avec le timeout par défaut de 650 s (safety net en cas de crash du worker).

**Si le verrou est déjà tenu** (une autre génération pour le même club est en cours), le handler :
1. Remet `Schedule.status` → `PENDING`.
2. Lève une `RecoverableMessageHandlingException` : Messenger **réessaiera** le message plus tard.

Il n'y a donc **pas d'échec** pour l'utilisateur, et le diagnostic `engine_busy` n'existe plus : la seconde demande attend simplement son tour.

> Exemple concret : si l'administrateur du BCCL clique deux fois rapidement sur "Générer", la seconde génération reste en `PENDING` et sera rejouée par le worker une fois la première terminée. Cela évite de surcharger le moteur et de corrompre les données, sans faire échouer la demande.

### 3b — Construction du payload (ScheduleConstraintBuilder)

Le verrou acquis, `ScheduleConstraintBuilder` construit le payload JSON destiné au moteur.

> ⚠️ **Deux branches.** Ce qui suit décrit `buildForClubSeason` (plan de base). Si le `Schedule` est l'**overlay d'une période** (`calendarEntryId` renseigné), le handler bascule sur `buildForOverlay`, qui **contourne le cache d'entrées** et lit **la grille de la période et elle seule** — les `VenueTrainingSlot` ancrés à son `schedulePlanId`, **jamais d'union** avec les créneaux de saison (feature #8). Détail dans `backend/AGENTS.md`.

Voici ce que fait la branche de base, dans l'ordre :

1. **Salles actives** (`venues[]`) : récupère toutes les salles du club pour la saison active. Il n'y a **pas de fenêtres par défaut** : chaque salle est sérialisée avec ses créneaux d'ouverture réels (`VenueTrainingSlot`) dans une clé `trainingSlots` — liste éventuellement **vide** si aucun créneau n'a été saisi (la salle est alors inutilisable par le solveur).

   > Exemple : ADN expose `trainingSlots: [{dayOfWeek: 2, startTime: "17:00", durationMinutes: 300, capacity: 1}, …]` — uniquement les créneaux effectivement déclarés dans le wizard.

2. **Équipes actives** (`teams[]`) : récupère toutes les équipes avec leurs tags (générés par `TeamTagService`), leur niveau, leur genre, et leur nombre de séances hebdomadaires (`sessionsPerWeek`).

   > Exemple : SM3 a `sessionsPerWeek: 2`, tags `["SENIOR", "MASCULINE"]`, niveau `HONNEUR`.

3. **Entraîneurs actifs** (`coaches[]`) : récupère tous les entraîneurs, sérialisés avec leur **identité et métadonnées seules** (nom, email, `isEmployee`, `maxDaysOverride`, etc.). Leurs indisponibilités ne sont **pas** portées par l'objet coach : ce sont des contraintes `COACH_AVAILABILITY` dans `constraints[]`. Les liens d'encadrement (`TeamCoach`) sont eux aussi sérialisés en contraintes dédiées.

   > Exemple : l'objet coach d'Enzo ne contient que son identité. Son indisponibilité du vendredi voyage dans `constraints[]` (`COACH_AVAILABILITY`, `scopeTargetId` = Enzo, `config: {unavailableDays: [5]}` — la cible est le scope, SEC-13), et ses encadrements SM1 (MAIN) / SM2 (ASSISTANT) en contraintes issues de `TeamCoach`.

4. **Contraintes utilisateur** (`constraints[]`) : `ConstraintRepository::findPermanentByClubSeason` — **uniquement les contraintes permanentes** (`calendarEntryId IS NULL`), jamais `findByClubSeason`. Une contrainte **datée** appartient à une période du cockpit et ne doit JAMAIS alimenter la génération du plan de base. Résout ensuite les tags `CLUB` en contraintes `TEAM` individuelles (voir [constraints.md](./constraints.md) section 4). Sérialise au format v2.

5. **Créneaux existants** (`slotTemplates[]`) : récupère les `ScheduleSlotTemplate` existants avec leur `lockLevel` (enum `NONE` | `SOFT` | `HARD` — il n'existe pas de valeur `LOCK`). Les créneaux `HARD` sont figés, le solveur ne peut pas les déplacer (en pratique seuls `NONE` et `HARD` atteignent le payload : le `SOFT` est rejeté à l'écriture). Les réservations (`Reservation`) sont fusionnées dans la même liste, comme des pins `HARD`.

   > Exemple : le créneau du SM1 le mardi 20h00-22h00 à Matéo est verrouillé (`lockLevel: "HARD"`) par l'administrateur. Le solveur doit le conserver tel quel.

6. **Niveaux de priorité** : il n'y a **pas** de clé `priorityTiers` top-level dans le payload. Les `PriorityTier` du club (S, A, B, C, D) sont sérialisés comme des **contraintes** de type `PRIORITY_TIER` dans `constraints[]`. Leurs poids ne sont pas envoyés (`orToolsWeight` volontairement omis) : le solveur applique des poids **codés en dur** côté engine — S=10000, A=1000, B=100, C=10, D=1 — un poids par tier serait accepté puis ignoré.

7. **Métadonnées** : ajoute `version: "2.16"` (`ScheduleConstraintBuilder::CONTRACT_VERSION`, alignée sur `engine/CONTRACT_VERSION`), `clubId`, `seasonId`, `solverSeed` et `solverTimeoutSeconds`.

Le payload complet pèse généralement entre 50 et 200 Ko de JSON selon la taille du club.

### 3c — Snapshot SHA-256

Le payload construit est hashé en SHA-256. Le hash est stocké sur l'entité `Schedule` dans le champ `snapshotHash`, et le payload lui-même est conservé dans `snapshotData`.

**À quoi ça sert ?**

- **Audit** : on sait exactement quelles données ont été envoyées au moteur pour une génération donnée.
- **Debug** : si un utilisateur dit "la génération d'hier donnait un meilleur résultat", on peut comparer les hash pour voir si les données d'entrée ont changé (nouvelle équipe, nouvelle contrainte, nouvel entraîneur).
- **Détection de changement** : une future optimisation pourrait éviter de regénérer si le hash n'a pas changé.

---

## 4. Étape 3 — Appel au moteur de calcul

### 4.1 Requête HTTP

Le handler envoie un POST synchrone (depuis le point de vue du worker) vers l'engine :

```http
POST http://engine:8000/generate
Content-Type: application/json

{
  "version": "2.16",
  "clubId": "bccl-uuid",
  "seasonId": "2025-2026-uuid",
  "solverSeed": 42,
  "solverTimeoutSeconds": 650,
  "venues": [
    {
      "id": "uuid-adn",
      "name": "Gymnase ADN",
      "trainingSlots": [
        {"dayOfWeek": 2, "startTime": "17:00", "durationMinutes": 300, "capacity": 1},
        {"dayOfWeek": 3, "startTime": "14:00", "durationMinutes": 480, "capacity": 1},
        {"dayOfWeek": 5, "startTime": "17:00", "durationMinutes": 300, "capacity": 1}
      ]
    }
  ],
  "teams": [
    {
      "id": "uuid-sm3",
      "name": "SM3",
      "sessionsPerWeek": 2,
      "tags": ["SENIOR", "MASCULINE", "HONNEUR"],
      "priorityTier": "C"
    }
  ],
  "coaches": [
    {
      "id": "uuid-enzo",
      "firstName": "Enzo",
      "lastName": "Martin",
      "isActive": true,
      "isEmployee": false
    }
  ],
  "constraints": [
    {
      "scope": "TEAM",
      "scopeTargetId": "uuid-sm3",
      "family": "DAY",
      "ruleType": "HARD",
      "config": {"allowedDays": [3]}
    },
    {
      "scope": "TEAM",
      "scopeTargetId": "uuid-sm3",
      "family": "TIME",
      "ruleType": "HARD",
      "config": {"minStartTime": "20:00"}
    },
    {
      "scope": "COACH",
      "scopeTargetId": "uuid-enzo",
      "family": "COACH_AVAILABILITY",
      "ruleType": "HARD",
      "config": {"coachId": "uuid-enzo", "unavailableDays": [5]}
    }
  ],
  "slotTemplates": [
    {
      "teamId": "uuid-sm1",
      "venueId": "uuid-mateo",
      "coachId": "uuid-enzo",
      "dayOfWeek": 2,
      "startTime": "20:00",
      "durationMinutes": 120,
      "lockLevel": "HARD"
    }
  ]
}
```

Points d'attention sur ce format :

- les salles portent leurs créneaux réels dans `trainingSlots` (`{dayOfWeek, startTime, durationMinutes, capacity}`) — pas de `availabilityWindows` ni de `closedDays` ;
- les coachs sont réduits à leur **identité** : leurs indisponibilités voyagent en contraintes `COACH_AVAILABILITY`, leurs encadrements en contraintes issues de `TeamCoach` ;
- il n'y a **pas** de clé `priorityTiers` top-level (les tiers sont des contraintes `PRIORITY_TIER` dans `constraints[]`) ;
- le schéma Pydantic de l'engine est **`extra="forbid"`** : toute clé inconnue fait rejeter le payload.

### 4.2 Timeout et gestion d'erreur

Le timeout de l'appel HTTP côté backend est de **650 secondes** par défaut (`GenerateScheduleMessage.timeoutSeconds`). Côté engine, le budget du solveur CP-SAT est **adaptatif** selon la taille du problème (`n_teams × n_venues`) : 60 s (≤ 50), 180 s (≤ 200) ou 600 s au-delà — le `solverTimeoutSeconds` du payload (650 par défaut) n'est qu'un **plafond**, jamais le budget effectif.

Note importante sur les réponses HTTP : `EngineClient` lit la réponse avec `toArray(false)` — il ne lève **pas** d'exception sur un statut HTTP d'erreur. Toute réponse JSON sans clé `status` (par exemple un corps d'erreur 422 de Pydantic) est traitée comme `status: "failed"`, donc planning `FAILED` avec un diagnostic `engine_failed`. Il n'existe **pas** de diagnostic `engine_validation_error`.

| Réponse engine | Traitement backend | Diagnostic créé |
|----------------|-------------------|-----------------|
| `200 OK` + `status: "completed"` | Import des créneaux | Aucun (ou diagnostics métier) |
| `200 OK` + `status: "failed"` | Import diagnostics, statut `FAILED` | `conflict` + liste équipes non placées |
| `422 Unprocessable Entity` (corps sans `status`) | Traité comme `failed`, statut `FAILED` | `engine_failed` |
| `500 Internal Server Error` (corps sans `status`) | Traité comme `failed`, statut `FAILED` | `engine_failed` |
| Timeout HTTP (> 650 s) | Statut `FAILED` | `engine_timeout` |
| Host unreachable | Statut `FAILED` | `engine_error` |

> Exemple concret : si le BCCL ajoute une contrainte `HARD` "SM3 uniquement le mercredi après 20h" et que le mercredi soir est déjà saturé par SM1, SM2, SF1 et SF2, le solveur peut déclarer le problème infaisable (INFEASIBLE côté CP-SAT). Il retourne alors `status: "failed"` avec un diagnostic listant SM3 comme équipe non placée.

---

## 5. Étape 4 — Traitement de la réponse (ScheduleResultImporter)

### 5.1 Cas : statut "completed"

Le moteur a trouvé un planning valide. `ScheduleResultImporter` exécute les opérations suivantes dans une transaction Doctrine :

1. **Suppression des anciens créneaux libres non ré-émis** : les `ScheduleSlotTemplate` existants dont `lockLevel = "NONE"` et que l'engine n'a **pas** ré-émis dans sa réponse sont supprimés. Tous les créneaux `lockLevel != "NONE"` (verrouillés) sont préservés.

2. **Import des nouveaux créneaux** : pour chaque `slot` dans la réponse engine, création (ou mise à jour) d'un `ScheduleSlotTemplate` :
   - `teamId`, `venueId`, `coachId`, `dayOfWeek`, `startTime`, `durationMinutes`
   - `lockLevel` repris de la réponse (défaut `"NONE"` : les nouveaux créneaux ne sont pas verrouillés)

   Il n'y a **pas** de champ `source` sur `ScheduleSlotTemplate`.

   > Exemple : le moteur place SM3 le mercredi 20h00-22h00 à ADN. Un nouveau `ScheduleSlotTemplate` est créé avec ces valeurs.

3. **Import des diagnostics** : les `diagnostics[]` retournés par l'engine (avertissements sur des contraintes `PREFERRED` violées, par exemple) sont transformés en entités `ScheduleDiagnostic`.

4. **Mise à jour du planning** :
   - `Schedule.status` → `COMPLETED`
   - `Schedule.score` → valeur du score objectif (ex: `117679`)
   - `Schedule.solverNbVariables` / `solverNbConstraints` / `solverNbConflicts` / `solverWallTimeMs` → métriques brutes du solveur. **Il n'existe pas de champ `solverMetrics`** : ce sont quatre colonnes distinctes, et l'historique par génération vit dans l'entité `SolverMetric` (alimentée par `SolverMetricsRecorder`).

### 5.2 Cas : statut "failed"

⚠ Il n'existe **pas** de statut `"infeasible"` sur le fil : le schéma de sortie du moteur est un `Literal["queued", "generating", "completed", "failed"]` (`output_schema.py:137`) — une instance INFEASIBLE arrive en `status: "failed"` avec ses diagnostics.

Le moteur n'a pas pu produire de planning complet.

1. **Import des diagnostics** : les diagnostics incluent obligatoirement la liste des équipes `unplaced` (non placées) et les contraintes `HARD` en conflit.

   > Exemple : `{"type": "conflict", "severity": "ERROR", "message": "SM3 cannot be placed: no available slot matches HARD constraints DAY+TIME", "unplacedTeams": ["uuid-sm3"]}`

2. **Mise à jour du planning** :
   - `Schedule.status` → `FAILED`
   - `Schedule.score` → `null`

3. **Préservation des créneaux** : contrairement au cas `completed`, **aucun créneau existant n'est supprimé**. L'administrateur conserve son ancien planning et peut l'ajuster pour résoudre le conflit.

### 5.3 Cas : engine inaccessible

Si l'engine ne répond pas du tout (conteneur arrêté, réseau coupé), le handler attrape l'exception `TransportException` et :

1. Crée un `ScheduleDiagnostic` de type `engine_error` (ou `engine_timeout` si c'est un timeout).
2. Met à jour `Schedule.status` → `FAILED`.
3. Libère le verrou Redis.
4. Publie l'échec via Mercure.

---

## 6. Étape 5 — Notification temps réel (Mercure SSE)

Quel que soit le résultat (succès ou échec), le handler publie un événement Mercure à la fin du traitement.

### 6.1 Topic

Le publieur émet toujours sur le topic **exact** d'un planning (`MercureTopic::for`, foyer unique — D-10) :

```
club:{clubId}:schedule:{scheduleId}
```

> Exemple pour le BCCL : `club:bccl-uuid:schedule:550e8400-e29b-41d4-a716-446655440000`

Le frontend, lui, ne s'abonne **plus** planning par planning depuis FRT-04/P4-123 : il ouvre **UN
seul** `EventSource` par session, abonné au **sélecteur TEMPLATE du club**
(`club:{clubId}:schedule:{id}`, le joker Mercure `{id}` — `MercureTopic::selectorForClub`) obtenu
via `GET /api/mercure/auth` (champ `topicTemplate`) — toutes les générations du club arrivent sur
la même connexion, sans en connaître les ids à l'avance (`frontend/src/features/planning/lib/scheduleStream.ts`).

### 6.2 Payload SSE

L'update Mercure est **privé** (réservé aux abonnés autorisés du topic club) et porte **cinq**
champs : `scheduleId`, `status`, `score`, `unplaced`, `warnings` (`ScheduleProgressPublisher::publish`).
`scheduleId` est nécessaire depuis que l'abonnement est un TEMPLATE partagé par tous les plannings
du club (§6.1) — sans lui, l'événement ne dirait pas de quel planning il parle. Il n'y a ni
`timestamp`, ni liste de `diagnostics` (elles se consultent via l'API).

**Cas succès :**

```json
{
  "scheduleId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "COMPLETED",
  "score": 117679,
  "unplaced": 0,
  "warnings": []
}
```

**Cas échec :**

```json
{
  "scheduleId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "FAILED",
  "score": null,
  "unplaced": 2,
  "warnings": ["Schedule generation timed out."]
}
```

### 6.3 Comportement frontend

Le frontend maintient la connexion `EventSource` unique décrite en §6.1. À réception d'un
événement, il ne recopie **jamais** son payload dans un cache — le serveur reste la source de
vérité — il **invalide** des clés react-query (`invalidationKeysFor`, `scheduleStream.ts`) :

- toujours `["schedules"]` et `["wizard", "schedule_status", scheduleId]` (le statut suivi par le wizard) ;
- en plus, si `status` est **terminal** (`COMPLETED`/`FAILED`, `isTerminalStatus`) : `["slots", scheduleId]` et `["diagnostics", scheduleId]`, ce qui déclenche le refetch des créneaux et diagnostics du planning et rafraîchit la grille (React maison, pas de FullCalendar) et le badge de statut (§8.2).

Best-effort : si le flux Mercure est indisponible ou se coupe, le polling react-query prend le
relais (accéléré) au lieu de rester figé — décrit par `isScheduleStreamConnected`.

L'utilisateur n'a pas besoin d'actualiser la page manuellement.

---

## 7. Cas d'erreur et diagnostic

Voici un tableau récapitulatif de tous les cas d'erreur possibles, avec leur cause, leur statut final, et le diagnostic créé.

| Cas | Cause | Statut résultant | Diagnostic | Action recommandée |
|-----|-------|-----------------|------------|-------------------|
| **Version en vigueur** | La version est celle que son plan **pointe** (ADR-0002 inv. 1) | — (refus synchrone **409**, rien n'est mis en file) | Aucun | La rouvrir d'abord : `POST /api/schedules/{id}/reopen` |
| **Problème hors bornes** | `GenerationComplexityGuard` (A10) : le club/saison dépasse les plafonds anti-« generation bomb » | — (refus synchrone **422**) | Aucun | Réduire le périmètre. Les plafonds sont ~10× un gros club FFBB : les franchir signale une donnée aberrante, pas un usage normal |
| **Épinglage orphelin** | `OrphanPinGuard` (#8) : un verrou ou une réservation ne correspond plus à aucun créneau de la grille de période | — (refus synchrone **422**) | Aucun | Le message nomme le gymnase et le jour : redéfinir les créneaux, ou retirer l'épinglage |
| **Club déjà en génération** | Verrou Redis `schedule_generation:club:{clubId}` tenu par un autre worker | `PENDING` (retry Messenger via `RecoverableMessageHandlingException`) | Aucun | Rien à faire : la demande sera rejouée automatiquement à la fin de la génération en cours |
| **Timeout HTTP (> 650 s)** | Problème trop complexe pour le solveur CP-SAT (budget adaptatif 60/180/600 s dépassé côté engine) | `FAILED` | `engine_timeout` | Simplifier les contraintes `HARD`, augmenter le nombre de salles, ou réduire le nombre d'équipes |
| **Payload invalide (422)** | Réponse engine sans clé `status` (corps d'erreur Pydantic) — improbable car le payload est construit par `ScheduleConstraintBuilder` | `FAILED` | `engine_failed` | Comparer le `snapshotData` au schéma engine (contrat 2.16, `engine/CONTRACT_VERSION`) |
| **Engine inaccessible** | Conteneur `engine` arrêté ou crash | `FAILED` | `engine_error` | Vérifier l'état des conteneurs Docker (`make logs SERVICE=engine`) |
| **Planning infaisable** | Contraintes `HARD` mutuellement exclusives | `FAILED` | `conflict` + liste équipes non placées | Relâcher une contrainte `HARD` en `PREFERRED`, ou ajouter des ressources (salle, coach) |
| **Partiellement résolu** | Ressources insuffisantes pour toutes les équipes | `COMPLETED` (score bas) | `unplaced` diagnostics | Accepter le planning incomplet, ou ajouter des créneaux/salles |
| **Verrou Redis expiré** | Worker crashé en cours de génération | — (la génération suivante peut acquérir le verrou) | Aucun | Le verrou s'auto-expire après `timeoutSeconds + 60` s (≈ 710 s par défaut) |

> Exemple concret au BCCL : l'administrateur ajoute une contrainte `HARD` "Aucune équipe féminine à Jean Vilar" et une autre `HARD` "SF3 doit s'entraîner à Jean Vilar". Ces deux contraintes sont contradictoires. Le solveur retourne `infeasible` avec un diagnostic `conflict` indiquant que SF3 est `unplaced`. L'administrateur doit alors choisir : supprimer la contrainte sur Jean Vilar pour SF3, ou changer la règle globale sur les équipes féminines.

---

## 8. Cycle de vie du statut d'un planning

Le champ `Schedule.status` suit un cycle de vie strict à **cinq** états.

```
DRAFT ──► PENDING ──► GENERATING ──► COMPLETED
                          │
                          └──► FAILED
```

| Statut | Signification | Qui le définit |
|--------|---------------|----------------|
| `DRAFT` | Planning créé, jamais généré | API Platform (création de l'entité) |
| `PENDING` | Génération demandée, message en file d'attente Redis | `GenerateScheduleController` (ou le handler si le verrou club est tenu) |
| `GENERATING` | Le worker `GenerateScheduleHandler` est en cours d'exécution | `GenerateScheduleHandler` (début du traitement) |
| `COMPLETED` | Le moteur a retourné un planning valide, les créneaux sont importés | `ScheduleResultImporter` (cas succès) |
| `FAILED` | Erreur à n'importe quelle étape (timeout, infaisabilité, engine down, etc.) | `GenerateScheduleHandler` ou `ScheduleResultImporter` (cas échec) |

**« Validé » n'est pas un statut** (ADR-0002 inv. 1) : c'est le **plan** (`schedule_plan`) qui **pointe** la version faisant foi (`chosen_schedule_id`), et une version pointée reste `COMPLETED`. Il n'y a pas non plus d'archive : valider **supprime** les versions sœurs.

La version que son plan pointe **bloque `POST /generate`** : la requête est refusée en `409 Conflict` — il faut d'abord la **rouvrir** (dépointer, `POST /api/schedules/{id}/reopen`).

### 8.1 Transitions possibles

- `DRAFT` → `PENDING` : l'utilisateur clique sur "Générer".
- `PENDING` → `GENERATING` : le worker consomme le message Redis.
- `GENERATING` → `COMPLETED` : le moteur retourne un planning valide.
- `GENERATING` → `FAILED` : n'importe quelle erreur (verrou, timeout, infaisabilité, engine down).
- `COMPLETED` → `PENDING` : l'utilisateur reclique sur "Générer" pour regénérer.
- `FAILED` → `PENDING` : l'utilisateur reclique sur "Générer" après avoir corrigé le problème.

> Note : il n'y a pas de transition directe `COMPLETED` → `DRAFT`. Une fois qu'un planning a été généré, il reste au minimum en `FAILED` s'il est réinitialisé.

### 8.2 Affichage frontend

Le frontend utilise ce statut pour afficher des indicateurs visuels :

- `DRAFT` : badge gris "Brouillon".
- `PENDING` : badge jaune "En attente" avec spinner.
- `GENERATING` : badge orange "Génération en cours..." avec barre de progression indéterminée.
- `COMPLETED` : badge vert "Planning généré" avec score affiché.
- `FAILED` : badge rouge "Échec" avec bouton "Voir les détails du conflit".

---

## 9. Référence des entités et services impliqués

| Classe | Rôle | Conteneur |
|--------|------|-----------|
| `GenerateScheduleController` | Point d'entrée HTTP, dispatch du message | `php-fpm` |
| `GenerateScheduleMessage` | DTO du message async | — |
| `GenerateScheduleHandler` | Worker qui orchestre les 5 étapes | `messenger-worker` |
| `ClubGenerationLock` | Verrou Redis par club | `messenger-worker` |
| `ScheduleConstraintBuilder` | Construction du payload engine | `messenger-worker` |
| `ScheduleResultImporter` | Import des résultats du solveur | `messenger-worker` |
| `Schedule` | Entité planning (statut, score, hash) | — |
| `ScheduleSlotTemplate` | Entité créneau (jour, heure, salle, équipe, coach) | — |
| `ScheduleDiagnostic` | Entité diagnostic (type, sévérité, message) | — |
| `MercureHub` | Publication SSE | `messenger-worker` |

---

## 10. Commandes utiles pour le debug

> ⚠️ `redis-cli` n'est **pas installé** dans l'image `php-fpm` : les commandes Redis se lancent depuis le conteneur `redis` lui-même (`docker compose exec redis redis-cli …`). Par ailleurs le transport Messenger utilise les **Redis Streams** (stream `messages`), pas une liste — `LRANGE messenger_messages` ne retourne rien.

### Voir les messages en attente dans Redis

```bash
docker compose exec redis redis-cli XLEN messages
docker compose exec redis redis-cli XRANGE messages - + COUNT 10
```

### Forcer la consommation d'un message

```bash
cd backend && make exec
# Dans le conteneur php-fpm :
php bin/console messenger:consume async --limit=1
```

### Vérifier l'état du verrou Redis

```bash
docker compose exec redis redis-cli GET schedule_generation:club:{clubId}
```

### Supprimer manuellement un verrou bloqué

```bash
docker compose exec redis redis-cli DEL schedule_generation:club:{clubId}
```

### Voir les logs du worker

```bash
make logs SERVICE=messenger-worker
```

### Voir les logs de l'engine

```bash
make logs SERVICE=engine
```

### Rejouer une génération avec le même payload

```bash
cd backend && make exec
# Dans le conteneur, extraire le hash et reconstruire le payload :
php bin/console debug:container ScheduleConstraintBuilder
# Puis appeler manuellement l'engine avec curl pour isoler un problème.
```
