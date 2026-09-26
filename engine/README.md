# ClubScheduler — Engine

> Moteur d'optimisation Python (FastAPI + OR-Tools CP-SAT). Calcule les plannings optimaux.

## Rôle dans l'architecture

L'**engine** est un microservice Python qui reçoit un contexte complet (clubs, équipes, salles, entraîneurs, contraintes) et résout le problème d'optimisation via **CP-SAT** (OR-Tools). Il retourne un planning optimisé avec diagnostics.

```
┌─────────────┐     POST /generate      ┌─────────────┐
│   Backend   │ ───────────────────────▶ │   Engine    │
│  (Symfony)  │   ScheduleInputSchema    │  (FastAPI)  │
│             │                          │             │
│             │ ◀──────────────────────  │             │
│             │   ScheduleOutputSchema   │             │
└─────────────┘                          └─────────────┘
```

## Communication inter-services

### Engine → Backend
- L'engine **ne contacte jamais le backend directement**. Il est purement réactif.
- Le backend envoie un POST `/generate` avec tout le contexte nécessaire
- L'engine retourne le résultat et le backend met à jour ses entités

### Backend → Engine
- Le backend (via `GenerateScheduleHandler`) envoie un POST à `http://engine:8000/generate`
- Le payload contient toutes les données du club : `venues`, `teams`, `coaches`, `constraints`, `slotTemplates`
- L'engine utilise `clubId` et `seasonId` pour l'isolation des tenants

### Frontend → Engine
- Le frontend **ne contacte jamais l'engine directement**. Il passe toujours par le backend.
- L'engine n'est joignable QUE par le backend, via `http://engine:8000` (réseau Docker interne) —
  **aucun proxy `/engine` n'existe nulle part et ne doit jamais être (ré)introduit** (l'ancien
  exposait le solveur SANS authentification, `docker/frontend/nginx.conf:~96`,
  `docker/frontend/csp.conf:~4`). Pour déboguer directement : `docker compose exec engine …`.

## API Endpoints

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/` | GET | Health check + version du contrat |
| `/health` | GET | Health check simple |
| `/generate` | POST | **Principal** — résout le planning hebdomadaire et retourne les créneaux |
| `/place-matches` | POST | Placement daté des matchs (ADR-0003), rail séparé du `/generate` hebdomadaire |
| `/validate-assignments` | POST | Verdict du moteur sur un déplacement manuel (rejoue la couche HARD) |
| `/implicit-constraints` | POST | Sync règles implicites backend↔engine (200 synchronized / 409 desynchronized) |

### `POST /generate`

**Request** : `ScheduleInputSchema` (contrat `"2.23"`, fichier `engine/CONTRACT_VERSION` — seul le **MAJOR** est comparé, donc toute `"2.x"` passe)

```json
{
  "version": "2.23",
  "clubId": "uuid",
  "seasonId": "uuid",
  "scheduleName": "Saison 2026-2027",
  "solverSeed": 42,
  "venues": [...],
  "teams": [...],
  "coaches": [...],
  "constraints": [...],
  "slotTemplates": [...]
}
```

**Response** : `ScheduleOutputSchema`

```json
{
  "status": "completed",
  "score": 12345,
  "slots": [
    {
      "id": "slot-1",
      "teamId": "team-1",
      "venueId": "venue-1",
      "coachId": "coach-1",
      "dayOfWeek": 1,
      "startTime": "18:00",
      "durationMinutes": 120,
      "lockLevel": "NONE"
    }
  ],
  "diagnostics": [
    {
      "type": "unplaced",
      "severity": "high",
      "teamId": "team-2",
      "message": "Équipe non placée",
      "suggestions": [...]
    }
  ]
}
```

## Commandes principales

```bash
# Toutes les commandes s'exécutent DANS le conteneur engine
# Le Makefile les lance automatiquement dans le conteneur

make test             # ruff + mypy + bandit + pytest
make coverage         # pytest --cov=app + cliquet — plancher lu de ../coverage-floor.json
make lint             # ruff + mypy + bandit
make format           # ruff format
make exec             # Entrer dans le conteneur engine

# Dans le conteneur :
pytest tests/                    # Tests complets
pytest tests/golden/             # Solves complets sur fixtures de club (golden)
pytest tests/invariants/         # Invariants post-solve (pas d'overlap, capacité, locks)
```

> ⚠️ Commandes engine = **dans Docker** (le Makefile enveloppe `docker compose exec`). Elles échouent sur l'hôte.

## Architecture interne

```
engine/
├── app/
│   ├── main.py              # FastAPI entry point + endpoints
│   ├── schemas/
│   │   ├── input_schema.py / output_schema.py            # ScheduleInputSchema / ScheduleOutputSchema (/generate)
│   │   ├── match_input_schema.py / match_output_schema.py # /place-matches
│   │   └── validate_input_schema.py / validate_output_schema.py # /validate-assignments
│   └── solver/
│       ├── model.py           # Construction du modèle CP-SAT
│       ├── constraints/       # Contraintes hard Level-1 + parse_v2_constraints (paquet)
│       ├── objective/         # Fonction objectif Level 2 (paquet, `weights.py` = poids réels)
│       ├── result_builder/    # Transformation solution → output (paquet)
│       ├── match_placement.py # Solveur du rail /place-matches (ADR-0003)
│       └── validate_assignments.py # Solveur du rail /validate-assignments
├── tests/
│   ├── fixtures/            # Jeux de données "golden" (liste : ls tests/fixtures/)
│   ├── golden/ invariants/ perf/ semantic/  # suites (semantic = matrice contrainte P0.1)
│   └── ...
├── Dockerfile
└── Makefile
```

## Pipeline du solver

```
1. Reçoit POST /generate avec ScheduleInputSchema
2. model.py            Crée variables booléennes x[team, venue, day, slot]
3. constraints/        Applique les contraintes hard Level-1 (liste : `docs/engine-inventory.md` §4.4)
4. objective/           Maximise le score pondéré (Level 2) — poids réels dans
                        `LEVEL_2_OBJECTIVE_WEIGHTS` (`app/solver/objective/weights.py`) ;
                        **ne pas recopier les valeurs dans un doc** — une copie a déjà survécu à un
                        rebalancement et menti pendant des semaines (gotcha 3, `engine/AGENTS.md`).
                        Détail : `docs/engine-inventory.md` §5.
5. OR-Tools CP-SAT   Solve en 2 phases (placement puis chaînage borné 10s), warm-start.
                      timeout adaptatif (60/180/600s) plafonné par solver_timeout_seconds (défaut 650s)
                      workers adaptatifs : 1 si n_teams×n_venues ≤ 200, sinon 8 ; seed = solver_seed (42)
6. result_builder/     Transforme solution → ScheduleOutputSchema
                       + génère diagnostics (unplaced, soft_lock_moved, coach_overload,
                         session_below_effective_min, unused_slot, conflict) ; s'y ajoutent ceux
                         produits au parse / à la construction du modèle : day_constraint_conflict,
                         venue_minimum_unreachable, constraint_not_honored (dont les contraintes
                         écrasées par un verrou HARD, en INFO). Catalogue : docs/solver-errors.md
```

> **Pass unique, pas de fallback silencieux.** INFEASIBLE → `status="failed"` + diagnostics (décision : [`docs/architecture/adr-0001-single-pass-solve.md`](../docs/architecture/adr-0001-single-pass-solve.md)). Le timeout et le seed viennent du payload (`solver_timeout_seconds` / `solver_seed`), pas codés en dur.

## Contraintes CP-SAT

> Liste exhaustive et à jour : `app/solver/constraints/` (`add_level_1_hard_constraints` / `add_time_window_constraints`) et **`docs/engine-inventory.md` §4** (la seule vue maintenue — pas de décompte figé ici, il périmerait).

### Hard (Level 1) — Impératives
Salle at-most-one (capacité), coach at-most-one, coach-joueur non-overlap, repos coach / distribution salariés / max consécutifs, forbidden assignments, indispo coach, forced venues, planchers par gymnase (`minAtVenueId`, ALIGN-05), une session/jour, âge croissant. Détail : engine-inventory §4.4.

> Une fermeture de gymnase (`config.type=venue_closed`) ne produit **aucune contrainte** côté
> engine : le gymnase perd ses `trainingSlots` les jours fermés, côté backend
> (`ScheduleConstraintBuilder`) — sans créneau, aucune variable, donc rien à y interdire.

> ⚠ **`min_sessions` n'est PAS dur en production** (ENG-18) : `_solve` passe un plancher 0 pour chaque équipe. La cible est portée par le bonus objectif `session_count` et signalée par le diagnostic `session_below_effective_min`.
> ⚠ Un **verrou HARD** (`slotTemplates[].lockLevel`) est pré-placé **hors du solveur** : sa variable n'existe pas, donc aucune contrainte ci-dessus ne peut l'atteindre. Le verrou prime (ALIGN-07) ; le moteur émet un `constraint_not_honored` INFO pour chaque contrainte ainsi écrasée.

### Soft (Level 2) — Optimisées
Tiers S>A>B>C>D, `preferred`, `avoided_venue` (malus), `preferred_day`, `preferred_time`, `session_count`, `rest`. Poids réels : `app/solver/objective/weights.py` (`LEVEL_2_OBJECTIVE_WEIGHTS`) — voir la note ci-dessus, ne pas les recopier.

## Pour aller plus loin (docs structurantes)

Le métier du solveur vit dans `engine/docs/` — à lire avant de toucher au solveur :

| Doc | Contenu |
|-----|---------|
| [`docs/business.md`](docs/business.md) | **Cœur métier** — concepts (équipe, salle, coach, contrainte : scopes/familles/règles, tiers de priorité, contraintes implicites, niveaux de lock). |
| [`docs/constraint-vocabulary.md`](docs/constraint-vocabulary.md) | **Vocabulaire engine complet** — chaque clé de `config` que le solveur sait parser, son mécanisme (dur/soft), le `ruleType` qui l'active, et ce qu'un verrou HARD écrase (P2-9). |
| [`docs/nominal-flow.md`](docs/nominal-flow.md) | Flux nominal d'une requête de bout en bout — structure du payload, négociation de version (contrat `2.23`, MAJOR only), locks par club, étapes du pipeline, schéma de sortie. |
| [`docs/solver-errors.md`](docs/solver-errors.md) | Erreurs & diagnostics — erreurs HTTP, statuts solveur, types de diagnostics, scénarios d'infaisabilité, lecture du score, guide de debug. |
| [`AGENTS.md`](AGENTS.md) | Cheat-sheet agent (conventions ruff/mypy/pytest, gotchas, quick-reference). |

Contrat backend↔engine : version dans `engine/CONTRACT_VERSION`, synchronisé **à la main** (pas de codegen), gardé par `backend/tests/.../ContractSchemaTest`.

## Environnement

- **Python** : 3.12
- **Framework** : FastAPI
- **Solver** : Google OR-Tools CP-SAT
- **Port** : 8000 (exposé via docker-compose)
- **Isolation** : Per-club asyncio locks (pas de génération concurrente pour le même club)
