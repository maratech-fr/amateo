# Guide de génération de planning — ClubScheduler

Last verified @ 2026-08-30 (rotation `documentation-update`) — re-confronté au code : `CONTRACT_VERSION`
**2.17** (`ScheduleConstraintBuilder.php:64`) ✓ ; les dix `container_name` de `docker-compose.yml`
sont tous `amateo-*` ✓ — **drift trouvé et corrigé** : ce guide citait encore `clubscheduler-nginx`/
`clubscheduler-engine`/`clubscheduler-mercure`/`clubscheduler-mailpit` (2 lignes, § « Vérifier que
tout tourne » et § logs), restes de l'ère pré-P4-142 que cette passe avait manqués ; port 8080 =
`NGINX_PORT` (`.env:6`) ✓ ; rôle applicatif `amateo_app` (`docker/postgres/init/02-users.sh`,
« Named amateo_app directly (no transitional legacy name) ») ✓ ; `pngExportUrl` toujours absent —
`grep` vide sur `backend/src` et `frontend/src` ✓.

> Ce guide explique, étape par étape, comment générer un planning de matchs pour un club de basket dans le backend ClubScheduler. Il s'adresse aux développeurs juniors qui découvrent le projet.

---

## 1. Pré-requis (Avant de commencer)

Avant de créer un planning, tu dois démarrer la stack Docker et charger les données de test.

### Démarrer la stack

```bash
make start
```

Cette commande lance `docker compose up -d --wait` et démarre tous les services définis dans le fichier `.env`.

### Vérifier que les conteneurs tournent

```bash
docker ps
```

Tu dois voir apparaître : `amateo-php-fpm`, `amateo-nginx`, `amateo-postgres`, `amateo-redis`, `amateo-engine`, `amateo-messenger-worker`, `amateo-mercure`, et éventuellement `amateo-mailpit`.

### Charger les fixtures

```bash
cd backend && make fixtures
```

> ⚠️ **Ne lance JAMAIS `doctrine:fixtures:load` à la main.** Sous `amateo_app`, la phase de purge est silencieusement filtrée par RLS (elle supprime zéro ligne sur les tables tenant) et le rechargement collisionne alors avec les données survivantes — base à moitié purgée. La fixture s'en protège désormais et **lève une exception** si la connexion n'est pas celle du superutilisateur. Passe toujours par `make fixtures`, qui injecte la connexion `admin`.

### Vérifier la santé du backend

```bash
curl http://localhost:8080/api/health
```

Tu dois recevoir une réponse JSON avec `"status": "ok"`.

### Ce que les fixtures créent

Les fixtures injectent un jeu de données complet pour tester la génération :

| Entité | Détail |
|--------|--------|
| Club | **BCCL** (B CHARPENNES CROIX LUIZET), code FFBB `ARA0069036` — l'id est **généré** ; les fixtures retrouvent le club par son `ffbbClubCode`, il n'y a pas d'UUID fixe |
| Saison | **2025-2026** (marquée comme active) |
| Équipes | U9 à U21, seniors (SM…, SF…), Loisir, Vétérans et groupes CEC — avec tags et tiers de priorité (`$newTeamsData` dans `BasketballInit`) |
| Coachs | Liés aux équipes via `TeamCoach` ; certains sont aussi joueurs (`CoachPlayerMembership`) |
| Salles | Armand, ADN, Debarros, Annexe, Jean Vilar, Tonkin, JDR, Matéo, Camus (JDR et Matéo divisibles) |
| Contraintes | Disponibilités coachs, exclusions, préférences (regroupées par famille TIME · DAY · FACILITY · COACH) |
| Réservations | Créneau réservé fixe (pin `HARD` pour le solveur) |

Ces données sont suffisantes pour lancer une première génération sans rien configurer toi-même.

---

## 2. Créer un Schedule (planning)

Un **Schedule** est l'entité centrale qui représente un planning d'**entraînements** pour une saison donnée (les matchs ont leur propre rail synchrone — ADR-0003).

### Requête

```bash
curl -X POST http://localhost:8080/api/schedules \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <ton-jwt>" \
  -d '{"name": "Planning BCCL 2025-2026", "status": "DRAFT"}'
```

### Corps de la requête

```json
{
  "name": "Planning BCCL 2025-2026",
  "status": "DRAFT"
}
```

### Headers obligatoires

| Header | Valeur | Rôle |
|--------|--------|------|
| `Content-Type` | `application/json` | Format du body |
| `Authorization` | `Bearer <jwt>` | **Toute** route `/api/*` exige un JWT ; le club est dérivé du membership de l'utilisateur authentifié |

Le header `X-Club-Id` n'est **pas** obligatoire (le frontend ne l'envoie jamais) : le backend résout le club depuis le JWT. Un `X-Club-Id` pointant vers un club étranger est rejeté en 403. Attention aussi aux rôles : les **écritures** (création de schedule, génération, export) exigent le rôle **management** dans le club (SEC-07).

### Réponse

```json
{
  "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "Planning BCCL 2025-2026",
  "status": "DRAFT",
  "clubId": "<uuid-du-club-généré-par-les-fixtures>",
  "seasonId": "...",
  "createdAt": "2026-06-15T10:00:00+00:00"
}
```

### Points importants

- Tu n'as pas besoin d'envoyer `clubId` dans le JSON. Le backend le dérive du **JWT** (membership `ClubUser` actif de l'utilisateur authentifié).
- Tu n'as pas besoin d'envoyer `seasonId`. Le backend résout automatiquement la saison active (ici, 2025-2026).
- Le champ `id` retourné est un UUID. Conserve-le, tu en auras besoin pour les étapes suivantes.

---

## 3. Déclencher la génération

La génération du planning est asynchrone. Tu demandes le lancement, le backend accepte immédiatement, et un worker traite la demande en arrière-plan.

### Requête

```bash
curl -X POST http://localhost:8080/api/schedules/a1b2c3d4-e5f6-7890-abcd-ef1234567890/generate \
  -H "Authorization: Bearer <ton-jwt>"
```

Remplace `a1b2c3d4-...` par l'UUID retourné à l'étape précédente.

### Ce que fait le contrôleur

1. Il vérifie que le schedule existe bien en base.
2. Il vérifie que l'utilisateur connecté a accès au club associé.
3. Il passe le statut à `PENDING`.
4. Il dispatche un message `GenerateScheduleMessage` sur le bus asynchrone Redis.
5. Il retourne immédiatement un **202 Accepted**.

### Ce qui peut être refusé tout de suite

Plusieurs gardes s'arrêtent **avant** la mise en file : rien n'est créé, aucun diagnostic n'est écrit, tu reçois l'erreur dans la réponse. Les trois du tableau sont les plus fréquents en dev ; s'y ajoutent les **caps métier par club** (offres/quotas/crédits — gardés par `Security/ClubQuotaTest` et `Security/PlanEntitlementsTest`) et, pour une génération de PÉRIODE, `SocleGuard` (pas de solve de période sans socle EN VIGUEUR — ADR-0002).

| Code | Cause | Que faire |
|------|-------|-----------|
| **409** | La version est celle que son plan **pointe** — c'est le planning en vigueur | La rouvrir d'abord : `POST /api/schedules/{id}/reopen` |
| **422** | `GenerationComplexityGuard` : le problème dépasse les plafonds anti-DoS (A10) | Réduire le périmètre. Les plafonds valent ~10× un gros club FFBB : les franchir signale une donnée aberrante |
| **422** | `OrphanPinGuard` : un verrou ou une réservation ne correspond plus à aucun créneau de la grille de période | Le message nomme le gymnase et le jour — redéfinis les créneaux, ou retire l'épinglage |

### Réponse

```http
HTTP/1.1 202 Accepted
```

Le body est vide (ou contient un message minimal). Le traitement n'est pas encore terminé.

### Ce que le frontend doit afficher

Dès que le backend répond 202, le frontend doit afficher un indicateur visuel :

> **Génération en cours...**

L'utilisateur ne doit pas pouvoir relancer une génération tant que le statut n'est pas revenu à `DRAFT`, `COMPLETED` ou `FAILED`.

---

## 4. Suivre le traitement (3 méthodes)

Tu as trois façons de savoir si la génération est terminée.

### Méthode A — Polling avec cURL

La plus simple pour déboguer sans frontend.

```bash
# Vérifier le statut (toute route /api exige le JWT)
curl -H "Authorization: Bearer <ton-jwt>" \
  http://localhost:8080/api/schedules/a1b2c3d4-e5f6-7890-abcd-ef1234567890

# Si le statut est COMPLETED, lister les créneaux générés
curl -H "Authorization: Bearer <ton-jwt>" \
  "http://localhost:8080/api/schedule_slot_templates?scheduleId=a1b2c3d4-e5f6-7890-abcd-ef1234567890"

# Si le statut est FAILED, lire les diagnostics
curl -H "Authorization: Bearer <ton-jwt>" \
  "http://localhost:8080/api/schedule_diagnostics?scheduleId=a1b2c3d4-e5f6-7890-abcd-ef1234567890"
```

Répète la première commande toutes les 2-3 secondes jusqu'à obtenir `COMPLETED` ou `FAILED`.

### Méthode B — SQL direct dans le conteneur

Utile quand tu veux voir des détails techniques (score, temps de résolution, diagnostics).

```bash
cd backend && make exec
```

Puis dans le shell du conteneur PHP :

```bash
# Statut général du schedule
php bin/console dbal:run-sql "SELECT id, status, score, solver_wall_time_ms FROM schedule WHERE id = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'"

# Diagnostics d'erreur
php bin/console dbal:run-sql "SELECT type, severity, message FROM schedule_diagnostic WHERE schedule_id = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'"
```

### Méthode C — Mercure SSE (temps réel)

Le backend publie un événement sur le hub Mercure dès que le statut change. C'est la méthode la plus fluide pour le frontend.

```javascript
const eventSource = new EventSource(
  `/.well-known/mercure?topic=club:${clubId}:schedule:a1b2c3d4-e5f6-7890-abcd-ef1234567890`
);

eventSource.onmessage = (event) => {
  const data = JSON.parse(event.data);

  if (data.status === 'COMPLETED') {
    // Recharger le calendrier avec les nouveaux créneaux
    reloadCalendar();
  } else if (data.status === 'FAILED') {
    // Afficher le message d'erreur à l'utilisateur
    showError(data.diagnostics);
  }
};
```

Le topic suit le format exact :

```
club:{clubId}:schedule:{scheduleId}
```

---

### Méthode D — Script d'automatisation

Pour aller plus vite, tu peux tout lancer avec un seul script :

```bash
cd backend/scripts
./generate-schedule.sh --name "Planning BCCL 2025-2026"
```

Le script fait automatiquement :
1. Création du schedule
2. Déclenchement de la génération
3. Polling toutes les 5 secondes
4. Affichage du résultat avec `printf`

**Exemple de sortie (succès)** :
```text
Création du schedule: Planning BCCL 2025-2026
Schedule créé: a1b2c3d4-e5f6-7890-abcd-ef1234567890
Déclenchement de la génération
Génération lancée
[1] Status: PENDING
[2] Status: GENERATING
[3] Status: COMPLETED | Score: 117679
PLANNING COMPLETÉ
Slots créés
Équipe                   Jour       Heure    Durée      Salle
SM1                      Mardi      20:30    90 min     Matéo
SM3                      Mercredi   20:00    120 min    ADN
```

**Exemple de sortie (échec)** :
```text
Création du schedule: Planning BCCL 2025-2026
Schedule créé: a1b2c3d4-e5f6-7890-abcd-ef1234567890
Déclenchement de la génération
Génération lancée
[1] Status: FAILED
PLANNING ÉCHOUÉ
Diagnostics
  - [ERROR] engine_timeout: Schedule generation timed out.
  - [WARNING] unplaced: SM3 ne peut pas être placée
```

Si le backend est indisponible, le script s'arrête avec un message clair du type :

```text
Erreur: Backend unreachable while calling POST http://localhost:8080/api/schedules: ...
```

---

## 5. Cycle de vie des statuts

```
DRAFT ──► PENDING ──► GENERATING ──► COMPLETED
                          │
                          ▼
                        FAILED
```

Le cycle compte **cinq** statuts :

| Statut | Signification | Qui le positionne |
|--------|---------------|-------------------|
| **DRAFT** | Planning créé, jamais généré | API Platform (lors du POST `/api/schedules`) |
| **PENDING** | Génération mise en file d'attente dans Redis | `GenerateScheduleController` (ou le worker si le verrou club est tenu, pour retry) |
| **GENERATING** | Le worker est en train de traiter la demande | `GenerateScheduleHandler` |
| **COMPLETED** | Le moteur a retourné un planning valide | `ScheduleResultImporter` |
| **FAILED** | Une erreur est survenue à n'importe quelle étape | `GenerateScheduleHandler` ou `ScheduleResultImporter` |

**« Validé » n'est pas un statut** (ADR-0002 inv. 1) : c'est le **plan** (`schedule_plan`) qui **pointe** la version faisant foi (`chosen_schedule_id`) — une version pointée reste `COMPLETED`. Il n'y a pas d'archive non plus : valider **supprime** les versions sœurs.

### Règles de transition

- `DRAFT` peut repasser à `PENDING` si tu relances une génération.
- `COMPLETED` peut repasser à `PENDING` si tu demandes une nouvelle génération (les anciens créneaux non verrouillés sont remplacés).
- `FAILED` peut repasser à `PENDING` après correction des contraintes.
- Seul le worker peut écrire `GENERATING`, `COMPLETED` ou `FAILED`.
- La version que son plan **pointe** est le planning en vigueur : elle **bloque `POST /generate` en 409** — il faut d'abord la **rouvrir** (dépointer, `POST /api/schedules/{id}/reopen`).

---

## 6. Diagnostic complet des blocages

Voici chaque panne possible, avec son symptôme, sa cause, sa vérification, sa correction et sa prévention.

### Cas 1 : le statut reste bloqué en PENDING

| | Détail |
|---|---|
| **Symptôme** | Le statut ne bouge pas de `PENDING`, même après plusieurs minutes. |
| **Cause** | Le conteneur `messenger-worker` n'est pas démarré. Il n'y a personne pour consommer la file Redis. |
| **Vérification** | `docker ps \| grep messenger` — si aucune ligne ne s'affiche, le worker est arrêté. |
| **Correction** | `docker compose up -d messenger-worker` |
| **Prévention** | Inclus toujours `messenger-worker` dans ton `docker-compose.yml` ou ton script de démarrage. |

### Cas 2 : le statut retombe en PENDING (verrou club tenu)

| | Détail |
|---|---|
| **Symptôme** | Le statut repasse (ou reste) en `PENDING` alors qu'une génération a été demandée. Il n'y a **pas** de diagnostic `engine_busy` — ce type n'existe plus. |
| **Cause** | Une autre génération est en cours pour le même club : le worker n'a pas pu prendre le verrou Redis `schedule_generation:club:{clubId}`, il a remis le statut en `PENDING` et levé une `RecoverableMessageHandlingException` (retry Messenger). |
| **Vérification** | `docker exec amateo-redis redis-cli GET schedule_generation:club:<club-uuid>` — si ça retourne un token, le verrou est actif. |
| **Correction** | Normalement rien : le message est rejoué automatiquement. Si le verrou est orphelin (worker crashé), `docker exec amateo-redis redis-cli DEL schedule_generation:club:<club-uuid>`. |
| **Prévention** | Le verrou expire automatiquement après `timeoutSeconds + 60` secondes (**≈ 710 s** avec le timeout par défaut de 650 s). Surveille les logs du worker pour détecter les crashes récurrents. |

### Cas 3 : FAILED + diagnostic "engine_timeout"

| | Détail |
|---|---|
| **Symptôme** | Le statut passe à `FAILED`. Le diagnostic indique que le moteur a dépassé le temps imparti. |
| **Cause** | Le problème est trop complexe pour le solveur CP-SAT. Le budget est **adaptatif** selon la taille du problème (`n_teams × n_venues`) : 60 s (≤ 50), 180 s (≤ 200), 600 s au-delà — plafonné par `solverTimeoutSeconds` (650). Trop d'équipes, trop de contraintes dures, pas assez de salles. |
| **Vérification** | `make logs SERVICE=engine` — tu verras le budget retenu et un abandon. |
| **Correction** | Réduis le nombre d'équipes dans le planning, assouplis des contraintes `HARD` en `PREFERRED`, ou ajoute des salles disponibles. |
| **Prévention** | Surveille les métriques du moteur. Pour les clubs très complexes, envisage de scinder le planning en plusieurs sous-planning. |

### Cas 4 : FAILED + diagnostic "engine_error"

| | Détail |
|---|---|
| **Symptôme** | Le statut passe à `FAILED`. Le diagnostic indique que le moteur est injoignable. |
| **Cause** | Le conteneur `engine` est arrêté ou a crashé. |
| **Vérification** | `docker ps \| grep engine` puis `make logs SERVICE=engine` |
| **Correction** | `docker compose up -d engine`, puis relis les logs pour comprendre le crash. |
| **Prévention** | Configure un healthcheck Docker sur le conteneur engine. |

### Cas 5 : FAILED + diagnostic "conflict"

| | Détail |
|---|---|
| **Symptôme** | Le statut passe à `FAILED`. Le diagnostic liste des équipes "non placées" (`unplacedTeams`). |
| **Cause** | Des contraintes dures mutuellement exclusives. Exemple : "SM3 uniquement le mercredi" + "SM3 après 20h", mais le mercredi soir est déjà plein. |
| **Vérification** | Lis la table `schedule_diagnostic` et regarde le champ `unplacedTeams`. |
| **Correction** | Change une contrainte `HARD` en `PREFERRED`, ou ajoute une ressource (salle, coach) pour débloquer le créneau. |
| **Prévention** | À terme, une validation automatique des contraintes avant envoi au moteur est prévue. |

### Cas 6 : FAILED + diagnostic "engine_failed" (réponse anormale du moteur)

| | Détail |
|---|---|
| **Symptôme** | Le statut passe à `FAILED` avec un diagnostic `engine_failed`. Il n'existe **pas** de type `engine_validation_error`. |
| **Cause** | Le moteur a retourné une réponse JSON **sans clé `status`** (par exemple un corps d'erreur 422 de Pydantic). `EngineClient` lit la réponse avec `toArray(false)` (aucune exception sur un statut HTTP d'erreur) et le handler traite toute réponse sans `status` comme `failed`. Un 422 est improbable en pratique : le payload est construit par `ScheduleConstraintBuilder`, pas saisi à la main. |
| **Vérification** | `make logs SERVICE=engine` + inspecte le champ `snapshot_data` de la table `schedule` (payload exact envoyé). |
| **Correction** | Compare le `snapshot_data` au schéma du contrat engine (v2.17, `extra="forbid"`) ; corrige la donnée source incriminée. |
| **Prévention** | `ContractSchemaTest` garde la synchronisation backend ⇄ engine ; le gate `POST /api/constraints/validate` attrape les configs incohérentes avant le solve. |

### Cas 7 : COMPLETED mais 0 créneau généré

| | Détail |
|---|---|
| **Symptôme** | Le statut est `COMPLETED`, le `score` vaut 0, et la table `schedule_slot_template` est vide. |
| **Cause** | Le moteur a retourné un résultat vide : aucune solution n'est réalisable avec les contraintes actuelles, même en relâchant les préférences. |
| **Vérification** | Vérifie `schedule.score` et compte les lignes dans `schedule_slot_template`. |
| **Correction** | Assouplis les contraintes, réduis le nombre d'équipes, ou ajoute des créneaux horaires disponibles. |
| **Prévention** | Même sur un statut `COMPLETED`, vérifie toujours le score et le nombre de créneaux. Un score de 0 est un signal d'alerte. |

### Cas 8 : Mercure SSE ne reçoit aucun événement

| | Détail |
|---|---|
| **Symptôme** | Le frontend ne se met pas à jour automatiquement quand la génération finit. |
| **Cause** | Le hub Mercure n'est pas démarré, ou le topic dans le JavaScript ne correspond pas exactement à celui publié par le backend. |
| **Vérification** | `docker ps \| grep mercure` + onglet Network des outils de développement du navigateur. |
| **Correction** | Relis les logs du conteneur Mercure et vérifie que le topic JS respecte le format `club:{clubId}:schedule:{scheduleId}`. |
| **Prévention** | Ajoute un healthcheck Mercure dans le `docker-compose.yml`. |

---

## 7. Itérer jusqu'au planning complet

La génération de planning est rarement parfaite du premier coup. Tu dois t'attendre à un cycle d'essai / correction.

### Boucle d'itération

```
1. Lancer la génération  →  POST /generate
2. Vérifier le statut     →  GET /schedules/{id}
3. Si COMPLETED :
   - Vérifier les créneaux
   - Vérifier le score
   - Si un créneau ne convient pas, ajuster une contrainte
4. Si FAILED :
   - Lire les diagnostics
   - Corriger la contrainte incriminée
5. Recommencer jusqu'à satisfaction
```

### Exemple concret d'itération

**Itération 1**

- Action : lancer la génération.
- Résultat : `FAILED` — diagnostic "conflict", l'équipe SM3 n'a pas pu être placée.
- Diagnostic : SM3 a une contrainte dure "uniquement le mercredi", mais le mercredi soir est saturé.
- Correction : passer la contrainte SM3 "uniquement le mercredi" de `HARD` à `PREFERRED`.

**Itération 2**

- Action : relancer la génération.
- Résultat : `COMPLETED` — score = 117 679, 7 créneaux générés.
- Vérification : l'équipe U15F2 est placée dans une salle trop éloignée.
- Correction : ajouter une contrainte `PREFERRED` pour forcer U15F2 dans la salle du centre-ville.

**Itération 3**

- Action : relancer la génération.
- Résultat : `COMPLETED` — score = 119 234, 7 créneaux, meilleure répartition géographique.
- Décision : le planning est acceptable. On passe à la validation et au PDF.

### Quand s'arrêter ?

- Toutes les équipes sont placées.
- Le score est stable (il ne grimpe plus significativement d'une itération à l'autre).
- Les contraintes dures sont toutes respectées.
- Les contraintes préférées sont respectées dans une proportion acceptable (80-90 %).

---

## 8. Générer le PDF

Une fois le planning validé, tu peux demander l'export PDF.

### Requête

```bash
curl -X POST http://localhost:8080/api/schedules/a1b2c3d4-e5f6-7890-abcd-ef1234567890/export-pdf \
  -H "Authorization: Bearer <ton-jwt>"
```

### Comportement

- Le backend répond **202 Accepted** (traitement asynchrone, comme pour la génération).
- Le champ `pdfExportStatus` de l'entité Schedule passe à `pending`.
- Le worker Messenger traite la demande (`ExportPdfHandler`).
- Le statut évolue : `pending` → `generating` → `completed` (ou `failed`).
- En statut `completed`, le champ `pdfExportUrl` contient l'URL du fichier.

### Téléchargement

Il n'existe **pas** de route de téléchargement dédiée : `pdfExportUrl` pointe directement vers un **fichier statique** servi sous `/exports/…` (répertoire `backend/public/exports`).

```bash
curl -O "http://localhost:8080<pdfExportUrl>"   # ex. /exports/schedule-a1b2c3d4-….pdf
```

### Note importante

La génération produit **un seul fichier** : `schedule-{id}.pdf` (A4 imprimable). ⚠ L'export **PNG a quitté totalement le projet le 2026-08-21** (décision fondateur : aucune plus-value) — `pngExportUrl` n'existe plus nulle part dans `src/`, et `app:exports:purge` ne connaît plus que le motif `.pdf`. Si un document mentionne encore le PNG, il est périmé.

## 8.5 Rapports de diagnostic (dev)

> **Environnement : uniquement `APP_ENV=dev`**  
> Ce service n'existe pas en production.

À chaque génération de planning en mode développement, le backend écrit automatiquement un lot de fichiers de diagnostic dans `backend/var/generate/schedule-{id}/{lot}/`. Le numéro de lot (`001`, `002`, etc.) est incrémental et horodaté (`001-2026_06_16-21_38`).

### Ce que le lot contient

| Fichier | Contenu |
|---------|---------|
| `payload.json` | Snapshot JSON complet envoyé au moteur (équipes, salles, contraintes) |
| `payload-summary.txt` | Résumé lisible : nombre d'équipes, de salles, de contraintes (HARD vs PREFERRED), de coachs |
| `slots-by-team.txt` *(si génération réussie)* | Créneaux groupés par équipe, avec entraîneurs, horaires et salles |
| `slots-by-venue.txt` *(si génération réussie)* | Mêmes créneaux, groupés par salle |
| `diagnostics.txt` *(si diagnostics présents)* | Statut solveur, score, temps d'exécution, et liste des erreurs/avertissements |

### Comment consulter les rapports

Depuis le conteneur PHP :

```bash
cd backend && make exec

# Lister tous les lots d'un schedule
ls var/generate/schedule-a1b2c3d4-e5f6-7890-abcd-ef1234567890

# Lire le résumé du dernier lot
LATEST=$(ls -d var/generate/schedule-a1b2c3d4-*/001-* | sort | tail -1)
cat "$LATEST/payload-summary.txt"

# Lire les créneaux par équipe
cat "$LATEST/slots-by-team.txt"

# Lire les diagnostics
cat "$LATEST/diagnostics.txt"
```

### À quoi ça sert

- **Comprendre le payload** : `payload.json` est la trace exacte envoyée au solveur. Utile pour reproduire un bug côté Python.
- **Vérifier les créneaux** : `slots-by-team.txt` permet de vérifier rapidement si les créneaux générés correspondent aux attentes, sans ouvrir le frontend.
- **Diagnostiquer un échec** : quand la génération échoue (`FAILED`), `payload-summary.txt` reste écrit (hook 1 s'exécute avant l'appel au moteur). Le hook 2 (résultats) ne s'écrit que si la génération réussit.

### Nettoyage

Le répertoire `var/generate/` est gitigné. Tu peux le nettoyer à tout moment sans risque :

```bash
rm -rf var/generate/
```

---

## 9. Commandes de debug essentielles

Garde cette section sous la main. Elle te fera gagner des heures lors d'une panne.

### Vue d'ensemble des conteneurs

```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

### Logs du worker Messenger

```bash
docker logs -f amateo-messenger-worker --tail 50
```

### Logs du moteur Python

```bash
docker logs -f amateo-engine --tail 50
```

### File Redis

Le transport Messenger utilise les **Redis Streams** (stream `messages`) — pas une liste, donc `LRANGE messenger_messages` ne montre rien.

```bash
# Compter les messages en attente
docker exec amateo-redis redis-cli XLEN messages

# Inspecter les messages
docker exec amateo-redis redis-cli XRANGE messages - + COUNT 5

# Vérifier le verrou de génération
docker exec amateo-redis redis-cli GET schedule_generation:club:<club-uuid>
```

### Forcer la consommation d'un message (mode debug)

```bash
cd backend && make exec
php bin/console messenger:consume async --limit=1 -vv
```

Cela exécute un seul message en mode très verbeux. Utile quand tu veux voir le déroulement étape par étape sans attendre le worker en arrière-plan.

### Requêtes SQL rapides

```bash
cd backend && make exec

# Tous les schedules, du plus récent au plus ancien
php bin/console dbal:run-sql "SELECT id, name, status, score FROM schedule ORDER BY created_at DESC"

# Les 10 derniers diagnostics
php bin/console dbal:run-sql "SELECT type, severity, message FROM schedule_diagnostic ORDER BY created_at DESC LIMIT 10"

# Créneaux d'un schedule donné
php bin/console dbal:run-sql "SELECT team_id, venue_id, day_of_week, start_time FROM schedule_slot_template WHERE schedule_id = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890'"
```

### Routes API liées aux schedules

```bash
cd backend && make exec
php bin/console debug:router | grep schedule
```

### Tester le moteur directement

Le hostname `engine` n'existe que **sur le réseau Docker** (depuis un autre conteneur). Depuis l'hôte, le port est publié sur `127.0.0.1:${ENGINE_PORT}` (8000 par défaut).

```bash
# Depuis l'hôte
curl -X POST http://127.0.0.1:8000/generate \
  -H "Content-Type: application/json" \
  -d '{"version":"2.1","clubId":"test","seasonId":"test","venues":[],"teams":[],"coaches":[],"constraints":[],"slotTemplates":[]}'

# Ou depuis un conteneur de la stack
docker compose exec php-fpm curl -X POST http://engine:8000/generate \
  -H "Content-Type: application/json" \
  -d '{"version":"2.1","clubId":"test","seasonId":"test","venues":[],"teams":[],"coaches":[],"constraints":[],"slotTemplates":[]}'
```

Cela envoie un payload minimal au moteur pour vérifier qu'il répond bien. Tu dois recevoir une réponse JSON (même vide) et non une erreur 502 ou un timeout. Attention : le schéma est `extra="forbid"`, toute clé inconnue (ex. `priorityTiers`) ferait rejeter le payload.

### Health check global

```bash
curl http://localhost:8080/api/health
```

---

## 10. Architecture rapide (pour comprendre)

Voici le flux complet, de la requête frontend jusqu'à la notification temps réel.

```
Frontend (React)          Backend (Symfony)           Engine (Python)
     |                         |                             |
     | POST /api/schedules     |                             |
     |------------------------>|                             |
     |                         | Crée l'entité Schedule      |
     |                         | (status = DRAFT)            |
     |                         |                             |
     | POST /api/schedules/{id}/generate                    |
     |------------------------>|                             |
     |                         | Passe le statut à PENDING   |
     |                         | Publie un message sur Redis   |
     | 202 Accepted            |                             |
     |<------------------------|                             |
     |                         |                             |
     |                         | Worker Messenger            |
     |                         | (conteneur async)           |
     |                         |                             |
     |                         | 1. Construit le payload     |
     |                         | 2. POST engine:8000/generate|
     |                         |---------------------------->|
     |                         |                             |
     |                         | 3. Importe le résultat      |
     |                         | 4. Publie un SSE Mercure    |
     |                         |                             |
     | EventSource             |                             |
     |<------------------------|                             |
     | { status: COMPLETED }   |                             |
```

### Rôles de chaque service

| Service | Technologie | Rôle |
|---------|-------------|------|
| **Frontend** | React 19 + Vite | Interface utilisateur, calendrier, formulaires de contraintes |
| **Backend** | Symfony 7 + API Platform | API REST, authentification, orchestration, persistence |
| **Engine** | Python 3.12 + FastAPI + OR-Tools | Solveur CP-SAT qui calcule le planning optimal |
| **Messenger Worker** | PHP CLI + Symfony Messenger | Consommateur de file Redis, appelle l'engine et importe le résultat |
| **Mercure** | Go (hub SSE) | Diffusion temps réel des changements de statut |
| **Redis** | Redis 7 | File d'attente des messages + verrous distribués |
| **PostgreSQL** | PostgreSQL 16 | Stockage des entités (schedules, créneaux, diagnostics) |

### Injection du placement précédent (après le hash de snapshot)

Entre l'étape « 1. Construit le payload » et l'appel au moteur, `GenerateScheduleHandler` greffe
un bloc `previousAssignments` (terme de **stabilité** moteur, contrat 2.11 : à score égal, le
solveur garde une équipe sur son créneau précédent plutôt que d'en tirer un autre au hasard) —
**après** avoir figé `snapshotData`/`snapshotHash`, jamais avant :

1. Le payload est construit et **caché** par club+saison (`ScheduleConstraintBuilder::buildForClubSeason`/`buildForPeriodPlan`) et son hash (`snapshotHash`, comparé à `currentStructureHash` pour griser « Régénérer ») est calculé.
2. **Ensuite seulement**, `GenerateScheduleHandler::resolvePreviousAssignmentSlots()` retrouve la source : la version explicitement **regardée** (`GenerateScheduleMessage::sourceScheduleId`, posé par `RegenerateController` — « Régénérer » depuis une version précise) sinon la dernière version `COMPLETED` du **même** plan (`/generate` initial, qui ne connaît pas de source), sinon rien (première génération). La source est toujours de la même lignée que `schedule.schedulePlanId` (ADR-0002 — jamais le socle sous un overlay).
3. `ScheduleConstraintBuilder::withPreviousAssignments()` sérialise les créneaux de la source (`{teamId, venueId, dayOfWeek, startTime}`, HARD compris — un créneau HARD n'a pas de variable côté solveur, le terme de stabilité le saute sans double paiement) et les ajoute au payload **déjà construit**, sans toucher `snapshotData`/`snapshotHash`.

Pourquoi cet ordre est **la seule option correcte** : le précédent est une préférence de
**convergence**, pas une donnée de **structure**. L'inclure dans `snapshotHash` (recalculé par
`SchedulePlanProvisioner` comme `currentStructureHash` — SANS le précédent) le ferait diverger à
chaque régénération, dé-grisant le bouton « Régénérer » en permanence et affichant une fausse
« structure modifiée ». Une liste de placements source vide n'ajoute pas la clé au payload
(chemin byte-identique à l'historique).

---

## 11. Récapitulatif des endpoints

| Action | Méthode | URL | Body / Headers |
|--------|---------|-----|----------------|
| Créer un planning | POST | `/api/schedules` | `{"name":"...","status":"DRAFT"}` + header `Authorization: Bearer <jwt>` |
| Lancer la génération | POST | `/api/schedules/{id}/generate` | Header `Authorization: Bearer <jwt>` |
| Vérifier le statut | GET | `/api/schedules/{id}` | — |
| Lister les créneaux | GET | `/api/schedule_slot_templates?scheduleId={id}` | — |
| Lister les diagnostics | GET | `/api/schedule_diagnostics?scheduleId={id}` | — |
| Exporter le PDF | POST | `/api/schedules/{id}/export-pdf` | Header `Authorization: Bearer <jwt>` |
| Exporter le tableur | POST | `/api/schedules/{id}/export-xlsx` | Header `Authorization: Bearer <jwt>` |
| Rouvrir (dépointer) une version | POST | `/api/schedules/{id}/reopen` | Header `Authorization: Bearer <jwt>` — **obligatoire avant de régénérer une version pointée** |

### Conventions

- `{id}` est toujours l'UUID du schedule (ex. `a1b2c3d4-e5f6-7890-abcd-ef1234567890`).
- Toutes les routes sous `/api/*` passent par API Platform, sauf `/generate` et `/export-pdf` qui sont des contrôleurs personnalisés.
- Le header `Authorization: Bearer <jwt>` est obligatoire sur **toutes** les routes `/api/*` (le club est dérivé du JWT — `X-Club-Id` n'est pas requis). Les écritures exigent le rôle management (SEC-07).

---

## 12. Checklist pré-vol

Avant chaque génération, parcours cette liste pour éviter les pannes évidentes.

- [ ] La stack Docker est démarrée (`make start`)
- [ ] Les fixtures sont chargées (`make fixtures`)
- [ ] Le worker Messenger tourne (`docker ps \| grep messenger`)
- [ ] Le moteur Python tourne (`docker ps \| grep engine`)
- [ ] Le hub Mercure tourne (`docker ps \| grep mercure`)
- [ ] Le verrou Redis est libéré (`redis-cli GET schedule_generation:club:<club-uuid>` retourne `(nil)`)
- [ ] Aucun planning précédent en statut `FAILED` n'a laissé de verrou ou de diagnostic bloquant pour le même club

Si tous les items sont cochés, tu peux lancer la génération en toute confiance.

---

## Ressources complémentaires

- **Makefile backend** : `backend/Makefile` — toutes les commandes utiles (test, lint, migration, shell)
- **CI** : `.github/workflows/ci.yml` — ordre exact des tests bloquants
- **AGENTS.md** (racine du repo) : architecture globale, conventions, pièges courants
