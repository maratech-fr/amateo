# Mesure de charge multi-club — procédure

> Livré 2026-08-13. Le harness OBSERVE, il ne tune rien : `max_concurrent_solves`, tiers de
> workers CP-SAT (1/8, contractuels pour les golden fixtures) et budgets solveur sont
> intouchables par ce rail. ⚠ **Un run LOCAL est indicatif** (courbe de forme, murs mémoire) —
> c'est le re-run sur le VPS de prod qui dimensionne (« une génération < 30 s en dev ne
> dimensionne rien », étude d'hébergement).

## Lancer

```bash
bash backend/scripts/load-test/run-load-test.sh --clubs 5          # défaut : --mode generation, limites mémoire de PROD
bash backend/scripts/load-test/run-load-test.sh --clubs 5 --no-limits
bash backend/scripts/load-test/run-load-test.sh --clubs 3 --rounds 2
bash backend/scripts/load-test/run-load-test.sh --mode placement --clubs 6   # rail PLACEMENT (2026-10-04)
```

Prérequis : stack dev up (`make start`), `DATABASE_ADMIN_URL` disponible (`.env`). Le script :
applique l'overlay `docker-compose.load.yml` (les `mem_limit` de `docker-compose.prod.yml`),
seed N clubs jetables, tire une rafale en rafale, échantillonne `docker stats` + la file Redis
toutes les 5 s, et écrit rapport + CSV dans **`var/load-test/`** (non versionné).

**Deux modes** (`--mode generation|placement`) :

- **`generation`** (défaut) : `app:load-test:seed-clubs` (commande DEV-ONLY) + rafale de
  générations via `generate-schedule.sh`.
- **`placement`** (`place-matches.sh`, ADR-0003) : clubs 100 % fictifs bâtis comme le BCCL
  (`LoadTestClubSeeder`, isolé de `BcclSeeder`), mélange de tailles réalistes petit/moyen/grand
  et un calendrier de championnat (`LoadTestMatchPlan`, phases, alternance domicile/extérieur,
  ~17 % déjà fixés). Chaque club place sa fenêtre de phase ENTIÈRE deux fois : la **passe 1**
  place le pic, puis **30 % des placements SOLVER de la passe 1 sont gelés en MANUAL** (ancre
  FIXED — un gestionnaire qui garde une partie à la main, ADR-0003 §5) avant que la **passe 2**
  replace le reste autour de ces ancres (ajustement, pas un rejeu à l'identique). Le rail étant
  ASYNCHRONE (202 + `runId`, `ADR-0003` §2), le harnais **sonde** `GET
  /api/fixtures/placement-run` jusqu'au statut terminal plutôt que de lire une réponse
  synchrone — la colonne `e2e (s)` du rapport est donc, comme en mode génération, file + solve.

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

### Mesures — run local du 2026-10-03

5 clubs taille BCCL en rafale, **limites mémoire de PROD appliquées** (cgroup actif), machine
dev WSL2 — INDICATIF :

- **5/5 COMPLETED**, lot entier en **17 s**, ~1 059 générations/h observées — chaque solve n'a
  pris que **0,6-0,8 s de wall solveur** : le débit ne se transpose PAS au pic réel (worst-case
  600 s/solve → le même couple de sérialiseurs donnerait ~6 gén./h).
- **Attente en file = le comportement nominal mesuré** : 6,4 s → 16,3 s selon la position
  (bout-en-bout − wall), linéaire.
- **Murs mémoire : AUCUN à cette taille** — pics vs limites prod : engine **324/512 MiB** (contre
  190 au run du 2026-08-13 : la marge du moteur a fondu), php-fpm 110/1024, worker 61/384,
  postgres 40/512 ; zéro OOMKilled. ⚠ Ce run (mode génération) ne couvre pas le placement des
  matchs — son propre run `--mode placement` est détaillé plus bas (2026-10-04, après ENG-49/50).
- File Redis : pic à 5 (attendu). Reste ouvert : re-run sur le VPS de prod.
- Le harnais se connecte avec les comptes `charge-N@<MANAGER_EMAIL_DOMAIN>` dérivés de
  `BcclSeedProfile::loadTest()` ; un login refusé sort en statut `LOGIN_FAILED` nommé dans le
  rapport (il sortait en silence avant le 2026-10-03).
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

### Mesures — run local `--mode placement` du 2026-10-04 (après ENG-49/ENG-50)

6 clubs fictifs tailles mixtes (10/21/49 équipes), **limites mémoire de PROD appliquées**
(`mem_limit` engine 1g, mercure 512m — §2 de ce run), machine dev WSL2 — INDICATIF :
`var/load-test/2026-10-04_12-38-22/`.

- **12/12 placements COMPLETED** (6 clubs × 2 passes × 1 round), **0 échec, 0 signal de
  capacité** (409/429/502) — lot entier en 191 s.
- **Murs mémoire** (pic vs limite prod) : engine **480/1024 MiB** (contre 324/512 au run
  génération du 2026-10-03 — le pic du PLACEMENT dépasse celui de la génération sur ce lot),
  retombé à **106 MiB au repos** entre deux appels (le processus fils jetable + `malloc_trim`
  rendent la mémoire, ENG-49) ; php-fpm 89/1024, messenger-worker 57/384, postgres 35/512,
  mercure **12,6/512 MiB** (3ᵉ topic club ajouté, toujours loin du plancher) ; zéro OOMKilled.
- File Messenger : pic à 7 (le placement partage le transport `async` avec la génération).

⚠ Ce run mesure la **mécanique** (rail async, découpage semaine, mémoire) sur des clubs
fictifs — il ne remplace pas une mesure sur un lot réel volumineux (`engine/tests/perf/
test_perf_place_matches_real.py`, fixture BCCL 141 domiciles).
