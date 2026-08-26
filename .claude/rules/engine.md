---
paths:
  - "engine/**"
---

# Engine — conventions & pièges (chargé quand engine/ est touché)

- **ruff** : line 120, py312, double quotes, LF. **`ruff format` fait convention et est GARDÉ**
  (`ruff format --check` dans `make lint`/`make test` et le job CI Engine Tests, P4-64) —
  `make format` est donc sans danger, il ne churne rien.
- **mypy `strict`** + plugin `pydantic.mypy` (`ortools.*` ignoré).
- **pytest** (`-ra`) + golden fixtures (`tests/golden/`, solves complets sur fixtures réelles) +
  invariants post-solve (`tests/invariants/`) + hypothesis ; `pytest-timeout` contre les solves
  fous. Les golden dépendent du **worker unique déterministe** (≤200 de complexité) — ne pas
  toucher `_adaptive_workers` sans les re-jouer.
- Le contrat backend⇄engine est **synchronisé À LA MAIN** (`engine/CONTRACT_VERSION`, 2.16, un seul
  contrat pour `/generate`, `/place-matches` ET `/validate-assignments`) : toute modif des schemas
  Pydantic doit garder verts `ContractSchemaTest` + `MatchPlacementContractSchemaTest` +
  `ValidateAssignmentsContractSchemaTest` côté backend.
- L'engine tourne en uvicorn **sans reload** : après une modif de code, `docker compose restart
  engine` avant tout test end-to-end.
