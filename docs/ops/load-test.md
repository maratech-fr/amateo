# Mesure de charge multi-club — procédure

> Livré 2026-08-13. Le harness OBSERVE, il ne tune rien : `max_concurrent_solves`, tiers de
> workers CP-SAT (1/8, contractuels pour les golden fixtures) et budgets solveur sont
> intouchables par ce rail. ⚠ **Un run LOCAL est indicatif** (courbe de forme, murs mémoire) —
> c'est le re-run sur le VPS de prod qui dimensionne (« une génération < 30 s en dev ne
> dimensionne rien », étude d'hébergement).

## Lancer

```bash
bash backend/scripts/load-test/run-load-test.sh --clubs 5          # défaut : limites mémoire de PROD
bash backend/scripts/load-test/run-load-test.sh --clubs 5 --no-limits
bash backend/scripts/load-test/run-load-test.sh --clubs 3 --rounds 2
```

Prérequis : stack dev up (`make start`), `DATABASE_ADMIN_URL` disponible (`.env`). Le script :
applique l'overlay `docker-compose.load.yml` (les 7 `mem_limit` de `docker-compose.prod.yml`),
seed N clubs jetables (`app:load-test:seed-clubs`, commande DEV-ONLY — inexistante hors env dev,
garde runtime en plus), tire N générations en rafale via `generate-schedule.sh`, échantillonne
`docker stats` + la file Redis toutes les 5 s, et écrit rapport + CSV dans **`var/load-test/`**
(non versionné).

## Lire le rapport

- **Wait = bout-en-bout − wall solveur** : c'est la file, produite par les DEUX sérialiseurs
  volontaires de la stack — un seul `messenger-worker` ET `max_concurrent_solves=1` global côté
  engine. Une attente qui croît linéairement avec la position dans la file est le comportement
  NOMINAL, pas une anomalie.
- **Peak RAM vs limite + OOMKilled** : la moitié « murs mémoire » — n'a de sens que si le host
  supporte cgroup memory (le rapport le dit ; caveat WSL2 possible).
- Statut ≠ COMPLETED ou OOMKilled=true → investiguer avant toute conclusion de capacité.

## Teardown

```bash
docker compose -f docker-compose.yml up -d     # retire l'overlay de limites
# Effacer les clubs jetables « Club Charge N » : aucune purge dédiée. ⚠ `make db-empty` vide la base
# ACTUELLEMENT VISÉE — en mode play c'est la base du fondateur (amateo_local). Ne le lancer que
# sous le bac à sable : backend/scripts/with-sandbox.sh make -C backend db-empty
```

## Résultats

Maison unique de la synthèse datée des runs (l'étude d'hébergement qui la portait a quitté le
repo pour `business/`, dossier local du fondateur). Bruts :
`var/load-test/<horodatage>/` (local).

### Mesures — run local du 2026-08-13

5 clubs taille BCCL en rafale, **limites mémoire de PROD appliquées** (cgroup actif), machine
dev WSL2 — INDICATIF (le VPS dimensionnera, P5-4b) :

- **5/5 COMPLETED**, lot entier en **11 s**, débit observé ~1 636 générations/h — mais chaque
  solve n'a pris que **0,2 s de wall solveur** sur cette machine : le débit ne se transpose PAS
  au pic réel (worst-case 600 s/solve → le même couple de sérialiseurs — worker unique ×
  `max_concurrent_solves=1` — donnerait ~6 gén./h).
- **Attente en file = le comportement nominal mesuré** : 5,8 s → 10,8 s selon la position
  (bout-en-bout − wall), linéaire — les deux sérialiseurs font exactement ce qu'ils annoncent.
- **Murs mémoire : AUCUN à cette taille** — pics vs limites prod : engine **190/512 MiB**,
  php-fpm 67/1024, worker 54/384, postgres 29/512 ; zéro OOMKilled. ⚠ Le pic engine d'un solve
  DENSE de 600 s reste à mesurer sur VPS — un solve de 0,2 s ne stresse pas la mémoire comme
  600 s de branch-and-bound.
- File Redis : pic à 5 (attendu). Reste ouvert : re-run sur le VPS (P5-4b).
- ⚠ **Recadrage fondateur (2026-08-13) qui change la lecture des budgets** : les tiers
  60/180/600 s ont été posés **arbitrairement, sans cas réel vérifié**. Or BCCL (49 équipes)
  est un **TOP-30 français** et se résout en ~2 s à 8 workers (ADR-0001) — le coût réel d'un
  solve pour l'immense majorité des clubs est de l'ordre de secondes, pas de minutes. **Le
  critère de dimensionnement est « ça passe avec BCCL » — et ça passe, avec une marge de
  300×.** Le p95 réel par taille de club sortira de `solver_metrics` en prod (lot métriques de
  capacité, roadmap) ; les scénarios catastrophe à 6 gén./h supposent des clubs PLUS gros que
  BCCL au comportement pathologique — ~30 candidats en France, identifiables un à un.

> **Ne pas re-benchmarker `num_search_workers`** : le choix est déjà le résultat d'une mesure
> (ADR-0001, amendé 2026-07-07 — 1 worker stalle 612 s sur BCCL là où le portefeuille 8 workers
> prouve l'optimum en ~2 s). Les tiers actuels (`_adaptive_workers` : ≤200 → 1, sinon 8) sont
> **contractuels pour les golden fixtures**, qui dépendent du déterminisme à 1 worker.
