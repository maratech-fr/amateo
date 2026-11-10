"""Promesse : `make ci-releve` dit, sans ouvrir un log, POURQUOI chaque job CI rouge l'a été
(vrai bug · instable · infra · externe-CVE · Dependabot · inconnu), sur une fenêtre cumulée,
sans jamais recopier un secret ni écrire quoi que ce soit dans le dépôt ou sur GitHub.

Lancé tel quel par le step `ci-releve self-test` du job `lint` : `python3 -I scripts/ci-releve-test.py`
(stdlib seule, aucun réseau, aucun Docker). `-I` retire le dossier du script de `sys.path` et
`ci-releve.py` porte un tiret : le module est chargé PAR SON CHEMIN (importlib), jamais par `import`.

Contrat lu ici (plan relevé CI v2 §Contrats, corrigé v3) :
- `classify(job, log_lines, branch) -> Verdict` : `job` = un élément de `jobs[]` de l'API GitHub
  (`id`, `run_id`, `name`, `run_attempt`, `head_sha`, `conclusion`, `steps[]` avec `name`,
  `number`, `conclusion`, `started_at`, `completed_at`) ; `log_lines` = lignes BRUTES du log
  (horodatées, BOM possible en tête), ou `None` si le log a expiré (404/410).
- `step_section(log_lines, step) -> list[str]` : texte (horodatage et BOM retirés) des lignes du
  step échoué, après le corps recopié de son `##[group]Run`, JUSQU'À la première ligne `##[error]`
  INCLUSE (dernier élément).
- `sanitize(line) -> str | None` ; `apply_history(verdicts, greens: set[(head_sha, job_name)])` ;
  `dedupe_attempts(verdicts)` ; `recurrences(verdicts)` (éléments lus comme dict OU attributs :
  `job`, `detail_key`, `shas` = nombre de sha distincts, `causes` = {cause: nombre}) ;
  `render_markdown(verdicts, days, since, until)` (dates `datetime.date`) ;
  `fetch_runs(since, fetch=gh_json)` : `since` = `datetime.date`, `fetch(chemin) -> JSON`, AUCUNE
  autre entrée-sortie (le slug passe par le chemin) ; `Verdict(**champs)` avec les 14 champs v2 ;
  `STEP_FAMILIES` : préfixes de noms de steps (dict préfixe → famille, ou paires).
- CLI : `--days N` · `--since` (prime sur `--days`) · `--until` · `--branch` · `--json` ·
  `--no-cache` ; exit 0 même à zéro rouge, 2 si `gh` absent/non authentifié ou Python < 3.10
  (contrôle AVANT tout le reste, version « trouvée » lue dans `sys.version_info`).
  Cache : `~/.cache/amateo/ci-releve/jobs/<job_id>.json` (via `Path.home()`), verdict seul.

Corpus : extraits de logs RÉELS (job id en commentaire), réduits à quelques lignes, logins et
corps sensibles retirés. Les signatures RUNNER (règle 0) et RÉSEAU/DISQUE (règle 1) ne sont
prouvées que par des cas synthétiques : aucun log réel de la fenêtre ne les porte.
"""

from __future__ import annotations

import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
import textwrap
import time
import unittest
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SCRIPT = ROOT / "scripts" / "ci-releve.py"
CI_YML = ROOT / ".github" / "workflows" / "ci.yml"


def _load():
    if not SCRIPT.is_file():
        return None, f"{SCRIPT} absent"
    spec = importlib.util.spec_from_file_location("ci_releve", SCRIPT)
    if spec is None or spec.loader is None:
        return None, f"{SCRIPT} illisible"
    module = importlib.util.module_from_spec(spec)
    sys.modules["ci_releve"] = module
    spec.loader.exec_module(module)
    return module, None


M, LOAD_ERROR = _load()

VRAI_BUG = "vrai bug"
INSTABLE = "instable"
INFRA = "infra"
CVE = "externe-CVE"
DEPENDABOT = "Dependabot"
INCONNU = "inconnu"

SHA_A = "a" * 40
SHA_B = "b" * 40


def log(text: str) -> list[str]:
    return textwrap.dedent(text).strip("\n").splitlines()


def step(name, started, completed, conclusion="failure", number=10):
    return {
        "name": name,
        "number": number,
        "conclusion": conclusion,
        "started_at": started,
        "completed_at": completed,
    }


def job(name, steps, *, job_id=1, run_id=10, attempt=1, sha=SHA_A, conclusion="failure", branch="feat/x"):
    return {
        "id": job_id,
        "run_id": run_id,
        "name": name,
        "run_attempt": attempt,
        "head_sha": sha,
        "head_branch": branch,
        "conclusion": conclusion,
        "started_at": steps[0]["started_at"] if steps else "2026-11-08T10:00:00Z",
        "completed_at": steps[-1]["completed_at"] if steps else "2026-11-08T10:00:00Z",
        "steps": steps,
    }


def synth(output, *, body=(), before=(), error="Process completed with exit code 1.", cmd="make check"):
    """Log à un step : un step voisin dans la même seconde, le `##[group]Run` du step échoué
    (corps recopié en ANSI comme GitHub l'écrit), sa sortie, puis la ligne `##[error]`."""
    t0 = "2026-11-08T10:00:00"
    lines = [f"{t0}.1000000Z ##[group]Run previous step", f"{t0}.1000001Z ##[endgroup]"]
    lines += [f"{t0}.2{i:06d}Z {line}" for i, line in enumerate(before)]
    lines += [f"{t0}.5000000Z ##[group]Run {cmd}", f"{t0}.5000001Z \x1b[36;1m{cmd}\x1b[0m"]
    lines += [f"{t0}.5{i + 10:06d}Z \x1b[36;1m{line}\x1b[0m" for i, line in enumerate(body)]
    lines += [f"{t0}.5999999Z ##[endgroup]"]
    lines += [f"2026-11-08T10:00:01.{i:07d}Z {line}" for i, line in enumerate(output)]
    lines += [f"2026-11-08T10:00:02.0000000Z ##[error]{error}", "2026-11-08T10:00:02.1000000Z Post job cleanup."]
    return lines


def synth_job(step_name, *, job_name="Some job", **kw):
    return job(job_name, [step(step_name, "2026-11-08T10:00:00Z", "2026-11-08T10:00:02Z")], **kw)


def verdict(**overrides):
    fields = {
        "date": "2026-11-08",
        "branch": "feat/x",
        "pr": None,
        "job": "Engine Tests",
        "run_id": 10,
        "job_id": 1,
        "head_sha": SHA_A,
        "attempt": 1,
        "attempts_red": 1,
        "cause": VRAI_BUG,
        "detail": "FAILED tests/test_a.py::test_b",
        "detail_key": "tests/test_a.py::test_b",
        "step": "Run pytest + coverage + cliquet",
        "green_later": False,
    }
    fields.update(overrides)
    return M.Verdict(**fields)


def field(item, key):
    return item[key] if isinstance(item, dict) else getattr(item, key)


# ---------------------------------------------------------------------------------------------
# Corpus d'extraits réels (assainis, réduits)
# ---------------------------------------------------------------------------------------------

# Job 125740930651 (Dependency Audit, branche dependabot/pip) : le rapport `npm audit` du step
# VOISIN (fini à 05:28:07) tombe dans la même seconde que le début de pip-audit ; pip-audit casse
# sur le build de l'image engine (`--require-hashes`), pas sur une CVE.
PIP_AUDIT_LOG = log("""
    ﻿2026-11-03T05:27:34.1629131Z ##[group]Runner Image Provisioner
    2026-11-03T05:27:34.1632513Z ##[endgroup]
    2026-11-03T05:27:43.2222141Z ##[group]Run ../.github/scripts/audit-retry.sh npm audit --audit-level=high
    2026-11-03T05:27:43.2224000Z \x1b[36;1m../.github/scripts/audit-retry.sh npm audit --audit-level=high\x1b[0m
    2026-11-03T05:27:43.2246139Z ##[endgroup]
    2026-11-03T05:28:07.5393792Z # npm audit report
    2026-11-03T05:28:07.5394095Z minimatch  <11.0.1
    2026-11-03T05:28:07.5394240Z Severity: moderate
    2026-11-03T05:28:07.5394625Z minimatch has potential denial of service via globparts amplification - https://github.com/advisories/GHSA-g3hv-75wg-m249
    2026-11-03T05:28:07.5404082Z 11 moderate severity vulnerabilities
    2026-11-03T05:28:07.5447773Z ##[group]Run actions/setup-python@5fda3b95a4ea91299a34e894583c3862153e4b97
    2026-11-03T05:28:07.5448006Z with:
    2026-11-03T05:28:07.5448118Z   python-version: 3.14
    2026-11-03T05:28:07.5450471Z ##[endgroup]
    2026-11-03T05:28:07.6247717Z ##[group]Installed versions
    2026-11-03T05:28:07.6305488Z Successfully set up CPython (3.14.9)
    2026-11-03T05:28:07.6305889Z ##[endgroup]
    2026-11-03T05:28:07.6388350Z ##[group]Run docker compose build engine
    2026-11-03T05:28:07.6388641Z \x1b[36;1mdocker compose build engine\x1b[0m
    2026-11-03T05:28:07.6389629Z \x1b[36;1m.github/scripts/audit-retry.sh pipx run pip-audit --requirement engine-deps.txt\x1b[0m
    2026-11-03T05:28:07.6420025Z shell: /usr/bin/bash -e {0}
    2026-11-03T05:28:07.6423499Z ##[endgroup]
    2026-11-03T05:28:08.1868487Z #1 [internal] load local bake definitions
    2026-11-03T05:28:20.7923720Z #14 4.993 Collecting tzdata (from python-dateutil==3.0.0->-r requirements.txt (line 503))
    2026-11-03T05:28:20.9431518Z #14 4.993 ERROR: In --require-hashes mode, all requirements must have their versions pinned with ==. These do not:
    2026-11-03T05:28:20.9997497Z #14 ERROR: process "/bin/sh -c pip install --upgrade pip     && pip install --require-hashes -r requirements.txt     && pip install --no-deps ." did not complete successfully: exit code: 1
    2026-11-03T05:28:21.0141678Z Dockerfile:26
    2026-11-03T05:28:21.0143950Z failed to solve: process "/bin/sh -c pip install --upgrade pip     && pip install --require-hashes -r requirements.txt     && pip install --no-deps ." did not complete successfully: exit code: 1
    2026-11-03T05:28:21.0205668Z ##[error]Process completed with exit code 1.
    2026-11-03T05:28:21.0288042Z Post job cleanup.
""")
PIP_AUDIT_JOB = job(
    "Dependency Audit",
    [
        step("npm audit", "2026-11-03T05:27:43Z", "2026-11-03T05:28:07Z", "success", 8),
        step("Run actions/setup-python@5fda3b95a4ea91299a34e894583c3862153e4b97", "2026-11-03T05:28:07Z", "2026-11-03T05:28:07Z", "success", 9),
        step("pip-audit", "2026-11-03T05:28:07Z", "2026-11-03T05:28:21Z", "failure", 10),
    ],
    job_id=125740930651,
    branch="dependabot/pip/engine/python-dateutil-3.0.0",
)

# Job 128678002080 (Rector) : toute la sortie tombe dans la DERNIÈRE seconde du step ; une fenêtre
# comparée telle quelle aux secondes tronquées de `steps[]` la perdait (893 lignes utiles).
RECTOR_HEAD = log("""
    2026-11-08T09:08:51.9379848Z Executing script cache:clear [OK]
    previous step continuation without timestamp
    2026-11-08T09:08:52.1280069Z ##[end-action id=__self_2.__run;outcome=success;conclusion=success;duration_ms=4286]
    2026-11-08T09:08:52.1494576Z ##[group]Run docker compose exec -T php-fpm composer rector -- --dry-run
    2026-11-08T09:08:52.1495011Z \x1b[36;1mdocker compose exec -T php-fpm composer rector -- --dry-run\x1b[0m
    2026-11-08T09:08:52.1512288Z shell: /usr/bin/bash -e {0}
    2026-11-08T09:08:52.1514324Z ##[endgroup]
""")
RECTOR_TAIL_USEFUL = log("""
    2026-11-08T09:09:17.9421173Z Applied rules:
    2026-11-08T09:09:17.9421360Z  * EventSubscriberToAttributeRector
    2026-11-08T09:09:17.9421682Z  [OK] 15 files would have been changed (dry-run) by Rector
    2026-11-08T09:09:17.9422189Z Script vendor/bin/rector process --config=rector.php handling the rector event returned with error code 2
""")
RECTOR_TAIL_AFTER = log("""
    2026-11-08T09:09:17.9517566Z ##[error]Process completed with exit code 2.
    2026-11-08T09:09:17.9748550Z Prepare all required actions
    2026-11-08T09:09:17.9928639Z ##[group]Run ./.github/actions/ci-stack-down
    2026-11-08T09:09:17.9931384Z ##[endgroup]
    2026-11-08T09:09:18.0000904Z ##[start-action display=Tear down the compose stack;id=__self_3.__run]
""")


def rector_body():
    """889 lignes de diff dans la dernière seconde, dont 2 sans horodatage (héritent du précédent)."""
    body = ["2026-11-08T09:08:53.0000000Z Rector 2.x"]
    for i in range(1, 887):
        body.append(f"2026-11-08T09:09:17.91{i:05d}Z {i}) src/Fixture/File{i}.php:{i}")
        if i in (300, 600):
            body.append(f"   continuation line {i}")
    return body


RECTOR_BODY = rector_body()
RECTOR_LOG = RECTOR_HEAD + RECTOR_BODY + RECTOR_TAIL_USEFUL + RECTOR_TAIL_AFTER
RECTOR_STEP = step("Run Rector (dry-run)", "2026-11-08T09:08:52Z", "2026-11-08T09:09:17Z")
RECTOR_JOB = job("Rector (style gate)", [RECTOR_STEP], job_id=128678002080)

# Job 128900536515 (E2E) : le corps recopié du step Vite (réussi) porte « Vite dev server did not
# come up » ; le vrai constat est un test Playwright rejoué deux fois.
E2E_LOG = log("""
    2026-11-08T23:55:56.9636211Z ##[group]Run docker compose --profile dev up -d frontend-dev
    2026-11-08T23:55:56.9636675Z \x1b[36;1mdocker compose --profile dev up -d frontend-dev\x1b[0m
    2026-11-08T23:55:56.9638511Z \x1b[36;1mecho "Vite dev server did not come up" && exit 1\x1b[0m
    2026-11-08T23:55:56.9664879Z shell: /usr/bin/bash -e {0}
    2026-11-08T23:55:56.9668876Z ##[endgroup]
    2026-11-08T23:55:57.3096851Z  Image ci-e2e-frontend-dev Building
    2026-11-08T23:56:51.2087433Z ##[group]Run set -o pipefail
    2026-11-08T23:56:51.2087734Z \x1b[36;1mset -o pipefail\x1b[0m
    2026-11-08T23:56:51.2114833Z shell: /usr/bin/bash -e {0}
    2026-11-08T23:56:51.2118857Z ##[endgroup]
    2026-11-08T23:56:51.6610837Z  Container ci-e2e-e2e-run-8cdb1776e4c6 Creating
    2026-11-09T00:02:41.5166768Z   ✓  36 [chromium] › tests/e2e/matches.spec.ts:936:1 › importer: onglet, badge absent, configuration allégée, file de traitement (9.2s)
    2026-11-09T00:03:27.2965840Z   ✘  39 [chromium] › tests/e2e/mutualize.spec.ts:139:1 › lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard) (14.2s)
    2026-11-09T00:03:42.2195318Z   ✘  40 [chromium] › tests/e2e/mutualize.spec.ts:139:1 › lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard) (retry #1) (14.1s)
    2026-11-09T00:03:57.1645206Z   ✘  41 [chromium] › tests/e2e/mutualize.spec.ts:139:1 › lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard) (retry #2) (14.3s)
    2026-11-09T00:04:36.6049631Z   -  45 [chromium] › tests/e2e/security-headers.spec.ts:48:1 › SPA ships the A17 security headers
    2026-11-09T00:05:52.0647234Z   1) [chromium] › tests/e2e/mutualize.spec.ts:139:1 › lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard)
    2026-11-09T00:05:52.0648021Z     Error: un plan de période non validé s'AJUSTE (jamais seulement se consulte)
    2026-11-09T00:05:52.0648379Z     expect(locator).toBeVisible() failed
    2026-11-09T00:05:52.0704395Z   1 failed
    2026-11-09T00:05:52.0704870Z     [chromium] › tests/e2e/mutualize.spec.ts:139:1 › lot 9 F3 — « Mutualiser… » est atteignable et actif sur un plan de période NON validé (wizard)
    2026-11-09T00:05:52.0705330Z   2 skipped
    2026-11-09T00:05:52.0705498Z   55 passed (7.9m)
    2026-11-09T00:05:52.4237350Z ##[error]Process completed with exit code 1.
    2026-11-09T00:05:52.4303041Z ##[group]Run {
""")
E2E_JOB = job(
    "E2E (Playwright)",
    [
        step("Start Vite dev server", "2026-11-08T23:55:56Z", "2026-11-08T23:56:42Z", "success", 14),
        step("Run Playwright suite", "2026-11-08T23:56:51Z", "2026-11-09T00:05:52Z", "failure", 19),
    ],
    job_id=128900536515,
)

# Job 128196309364 (E2E) : le serveur Vite n'est jamais venu (step d'infra).
VITE_LOG = log("""
    2026-11-06T22:41:14.4377735Z ##[group]Run docker compose --profile dev up -d frontend-dev
    2026-11-06T22:41:14.4380661Z \x1b[36;1mecho "Vite dev server did not come up" && exit 1\x1b[0m
    2026-11-06T22:41:14.4402858Z ##[endgroup]
    2026-11-06T22:42:34.2062627Z  Container ci-e2e-frontend-dev-1  Started
    2026-11-06T22:48:07.7472797Z frontend-dev-1  |   VITE v8.3.5  ready in 416 ms
    2026-11-06T22:48:07.7502224Z Vite dev server did not come up
    2026-11-06T22:48:07.7518876Z ##[error]Process completed with exit code 1.
""")
VITE_JOB = job(
    "E2E (Playwright)",
    [step("Start Vite dev server", "2026-11-06T22:41:14Z", "2026-11-06T22:48:07Z", "failure", 15)],
    job_id=128196309364,
)

# Job 121321368601 (gitleaks) : `Unable to find image` précède un téléchargement RÉUSSI ; le vrai
# constat est une fuite trouvée.
GITLEAKS_LOG = log("""
    2026-10-25T23:15:27.0834664Z Login Succeeded
    2026-10-25T23:15:27.0906747Z ##[group]Run docker run --rm -v "$PWD:/repo" ghcr.io/example-org/mirror-gitleaks:v8.30.0 git /repo --no-banner -c /repo/.gitleaks.toml
    2026-10-25T23:15:27.0976289Z shell: /usr/bin/bash -e {0}
    2026-10-25T23:15:27.0976617Z ##[endgroup]
    2026-10-25T23:15:27.1243922Z Unable to find image 'ghcr.io/example-org/mirror-gitleaks:v8.30.0' locally
    2026-10-25T23:15:27.6010370Z v8.30.0: Pulling from example-org/mirror-gitleaks
    2026-10-25T23:15:30.9569196Z 08e774557393: Pull complete
    2026-10-25T23:15:30.9631864Z Status: Downloaded newer image for ghcr.io/example-org/mirror-gitleaks:v8.30.0
    2026-10-25T23:15:48.7685132Z \x1b[90m11:15PM\x1b[0m \x1b[32mINF\x1b[0m \x1b[1m1520 commits scanned.\x1b[0m
    2026-10-25T23:15:48.8429930Z \x1b[90m11:15PM\x1b[0m \x1b[32mINF\x1b[0m \x1b[1mscanned ~54213005 bytes (54.21 MB) in 17.6s\x1b[0m
    2026-10-25T23:15:48.8430940Z \x1b[90m11:15PM\x1b[0m \x1b[33mWRN\x1b[0m \x1b[1mleaks found: 1\x1b[0m
    2026-10-25T23:15:48.8445054Z ##[error]Process completed with exit code 1.
    2026-10-25T23:15:48.8598780Z Post job cleanup.
""")
GITLEAKS_JOB = job(
    "Secrets Scan (gitleaks)",
    [
        step("Login to ghcr.io (mirror pull)", "2026-10-25T23:15:26Z", "2026-10-25T23:15:27Z", "success", 3),
        step("Run gitleaks (full history)", "2026-10-25T23:15:27Z", "2026-10-25T23:15:48Z", "failure", 4),
    ],
    job_id=121321368601,
)

# Job 127574716730 (Semgrep) : même `Unable to find image`, vrai constat d'une règle.
SEMGREP_LOG = log("""
    2026-11-05T22:26:31.3117564Z ##[group]Run docker run --rm -v "$PWD:/src" semgrep/semgrep:latest semgrep scan --config p/default --error --metrics=off
    2026-11-05T22:26:31.3147122Z shell: /usr/bin/bash -e {0}
    2026-11-05T22:26:31.3147745Z ##[endgroup]
    2026-11-05T22:26:31.4430107Z Unable to find image 'semgrep/semgrep:latest' locally
    2026-11-05T22:26:42.1922080Z Status: Downloaded newer image for semgrep/semgrep:latest
    2026-11-05T22:26:50.4557499Z   Scanning 2664 files tracked by git with 1120 Code rules:
    2026-11-05T22:27:46.4204281Z │ 1 Code Finding │
    2026-11-05T22:27:46.4205421Z     backend/scripts/scan-local-identities.py
    2026-11-05T22:27:46.4206746Z    ❯❯❱ python.lang.security.audit.dangerous-subprocess-use-tainted-env-args.dangerous-subprocess-use-tainted-env-args
    2026-11-05T22:27:46.4207893Z           ❰❰ Blocking ❱❱
    2026-11-05T22:27:46.4208355Z           Detected subprocess function 'run' with user controlled data. A malicious actor could leverage this
    2026-11-05T22:27:46.7143621Z  • Findings: 1 (1 blocking)
    2026-11-05T22:27:46.7146642Z Ran 600 rules on 2664 files: 1 finding.
    2026-11-05T22:27:47.2625249Z ##[error]Process completed with exit code 1.
""")
SEMGREP_JOB = job(
    "Semgrep (security gate)",
    [step("Run semgrep", "2026-11-05T22:26:31Z", "2026-11-05T22:27:47Z", "failure", 4)],
    job_id=127574716730,
)

# Job 125871651412 (Backend Tests) : mémoire épuisée, identique aux deux tentatives (déterministe).
MEMORY_LOG = log("""
    2026-11-03T10:52:09.8269181Z ##[group]Run docker compose exec -T -e APP_ENV=test php-fpm sh -c 'cd /app/backend && php -d pcov.enabled=1 vendor/bin/phpunit tests/ --exclude-group contract && php scripts/coverage-gate.php'
    2026-11-03T10:52:09.8302389Z shell: /usr/bin/bash -e {0}
    2026-11-03T10:52:09.8302679Z ##[endgroup]
    2026-11-03T10:52:11.0250751Z PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
    2026-11-03T10:52:26.0131781Z .............................................................   61 / 4493 (  1%)
    2026-11-03T11:05:07.7229743Z ...................................................Couverture backend : 80.00 % (plancher 79)
    2026-11-03T11:05:07.7236593Z Couverture SOUS le plancher : 78.90 % < 79 %
    2026-11-03T11:05:09.4778197Z .......................................................
    2026-11-03T11:05:09.4779236Z Fatal error: Allowed memory size of 536870912 bytes exhausted (tried to allocate 26218496 bytes) in /app/backend/tests/Unit/Service/XlsxUploadGuardTest.php on line 61
    2026-11-03T11:05:09.5569286Z ##[error]Process completed with exit code 255.
""")
MEMORY_STEP = step("Run full suite + coverage + cliquet", "2026-11-03T10:52:09Z", "2026-11-03T11:05:09Z")

# Job 120480644362 (PHPStan & CS-Fixer) : PHPStan (vert) et CS-Fixer partagent la seconde 14:51:55.
CS_FIXER_LOG = log("""
    2026-10-23T14:51:42.3907570Z ##[group]Run docker compose exec -T php-fpm composer phpstan
    2026-10-23T14:51:42.3979935Z ##[endgroup]
    2026-10-23T14:51:55.0684992Z  [OK] No errors
    2026-10-23T14:51:55.1044358Z ##[group]Run docker compose exec -T php-fpm composer cs-fix -- --dry-run --diff
    2026-10-23T14:51:55.1044998Z \x1b[36;1mdocker compose exec -T php-fpm composer cs-fix -- --dry-run --diff\x1b[0m
    2026-10-23T14:51:55.1114526Z ##[endgroup]
    2026-10-23T14:51:55.5616224Z PHP CS Fixer 3.95.27 Adalbertus by Fabien Potencier, Dariusz Ruminski and contributors.
    2026-10-23T14:52:44.6976108Z  1587/1587 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
    2026-10-23T14:52:44.6980596Z    1) tests/CrossStack/ParcPromptValidatorParityTest.php
    2026-10-23T14:52:44.6981207Z       ---------- begin diff ----------
    2026-10-23T14:52:44.6995137Z       ----------- end diff -----------
    2026-10-23T14:52:44.6995556Z Found 1 of 1587 files that can be fixed in 49.076 seconds, 128.00 MB memory used
    2026-10-23T14:52:44.7063780Z Script vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes handling the cs-fix event returned with error code 8
    2026-10-23T14:52:44.7195973Z ##[error]Process completed with exit code 8.
""")
CS_FIXER_JOB = job(
    "PHPStan & CS-Fixer",
    [
        step("Run PHPStan", "2026-10-23T14:51:42Z", "2026-10-23T14:51:55Z", "success", 8),
        step("Run PHP-CS-Fixer (dry-run)", "2026-10-23T14:51:55Z", "2026-10-23T14:52:44Z", "failure", 9),
    ],
    job_id=120480644362,
)

# Job 124857015585 (Behat) : le scénario rouge n'est nommé qu'après `--- Failed scenarios:`.
BEHAT_LOG = log("""
    2026-11-01T13:08:37.1121099Z ##[group]Run docker compose exec -T -e APP_ENV=dev php-fpm vendor/bin/behat --format=pretty --no-interaction
    2026-11-01T13:08:37.1141814Z ##[endgroup]
    2026-11-01T13:08:37.3682507Z Fonctionnalité: La génération du planning de saison aboutit
    2026-11-01T13:08:37.5707448Z   Scénario: Le club de démonstration génère son planning de saison            # features/generation-du-planning-de-saison.feature:7
    2026-11-01T13:19:35.9728480Z --- Failed scenarios:
    2026-11-01T13:19:35.9729311Z
    2026-11-01T13:19:35.9729757Z     features/placement-des-matchs.feature:21 (placement) (on line 33)
    2026-11-01T13:19:35.9730537Z
    2026-11-01T13:19:35.9730936Z 108 scenarios (107 passed, 1 failed)
    2026-11-01T13:19:35.9731631Z 628 steps (627 passed, 1 failed)
    2026-11-01T13:19:35.9933316Z ##[error]Process completed with exit code 1.
    2026-11-01T13:19:35.9986395Z ##[group]Run docker compose logs --tail 120 php-fpm engine messenger-worker
""")
BEHAT_JOB = job(
    "Functional Tests (Behat)",
    [step("Functional tests (Behat, Gherkin FR — API-only, real async rail)", "2026-11-01T13:08:37Z", "2026-11-01T13:19:35Z", "failure", 12)],
    job_id=124857015585,
)

# Job 129311027587 (Build Docker Images) : Trivy refuse deux CVE critiques.
TRIVY_LOG = log("""
    2026-11-09T17:03:20.5964993Z ##[group]Run SCAN="scan-42656958000-1"
    2026-11-09T17:03:20.5965783Z \x1b[36;1mSCAN="scan-42656958000-1"\x1b[0m
    2026-11-09T17:03:20.6029831Z shell: /usr/bin/bash -e {0}
    2026-11-09T17:03:20.6034372Z ##[endgroup]
    2026-11-09T17:04:35.6389538Z scan-42656958000-1/pdf-worker (debian 12.15)
    2026-11-09T17:04:35.6390132Z Total: 2 (CRITICAL: 2)
    2026-11-09T17:04:35.6391242Z │    Library     │  Vulnerability  │ Severity │ Status │ Installed Version │  Fixed Version  │                            Title                            │
    2026-11-09T17:04:35.6392688Z │ libbrotli1     │ CVE-2026-111497 │ CRITICAL │ fixed  │ 1.0.9-2+b6        │ 1.0.9-2+deb12u1 │ brotli: Out-of-Bounds Write in Google Brotli Decoder        │
    2026-11-09T17:04:35.6393439Z │                │                 │          │        │                   │                 │ https://avd.aquasec.com/nvd/cve-2026-111497                 │
    2026-11-09T17:04:35.6394694Z │ linux-libc-dev │ CVE-2026-98432  │          │        │ 6.1.187-1         │ 6.1.190-1       │ kernel: ipv4: free inet_opt and ireq_opt after an RCU grace │
    2026-11-09T17:04:35.8617545Z ##[error]Process completed with exit code 1.
""")
TRIVY_JOB = job(
    "Build Docker Images",
    [step("Trivy scan (prod images, CRITICAL gate)", "2026-11-09T17:03:20Z", "2026-11-09T17:04:35Z", "failure", 11)],
    job_id=129311027587,
)

# Job 128570147316 (Frontend) : sortie Vitest colorée (ANSI).
VITEST_LOG = log("""
    2026-11-08T00:09:39.8257455Z ##[group]Run npm run test:coverage
    2026-11-08T00:09:39.8257755Z \x1b[36;1mnpm run test:coverage\x1b[0m
    2026-11-08T00:09:39.8277750Z ##[endgroup]
    2026-11-08T00:09:39.9708384Z > scheduler-frontend@1.0.0 test:coverage
    2026-11-08T00:14:25.3950790Z \x1b[31m⎯⎯⎯⎯⎯⎯⎯\x1b[39m\x1b[1m\x1b[41m Failed Tests 2 \x1b[49m\x1b[22m\x1b[31m⎯⎯⎯⎯⎯⎯⎯\x1b[39m
    2026-11-08T00:14:25.3952607Z \x1b[41m\x1b[1m FAIL \x1b[22m\x1b[49m src/features/matches/ImportPage.test.tsx\x1b[2m > \x1b[22mImportPage — la file de traitement\x1b[2m > \x1b[22mReplacer pose le filtre équipe + week-end
    2026-11-08T00:14:25.3958589Z \x1b[31m\x1b[1mTestingLibraryElementError\x1b[22m\x1b[39m: Unable to find role="button" and name "Replacer"
    2026-11-08T00:14:25.4108337Z \x1b[41m\x1b[1m FAIL \x1b[22m\x1b[49m src/features/matches/ReviewQueueRow.test.tsx\x1b[2m > \x1b[22mReviewQueueRow — domicile/extérieur\x1b[2m > \x1b[22m« Replacer » présent sur un domicile
    2026-11-08T00:14:25.4192272Z \x1b[2m Test Files \x1b[22m \x1b[1m\x1b[31m2 failed\x1b[39m\x1b[22m\x1b[2m | \x1b[22m\x1b[1m\x1b[32m447 passed\x1b[39m\x1b[22m\x1b[90m (449)\x1b[39m
    2026-11-08T00:14:25.5535025Z ##[error]Process completed with exit code 1.
""")
VITEST_JOB = job(
    "Frontend (build + unit)",
    [step("Unit tests + coverage (Vitest, cliquet)", "2026-11-08T00:09:39Z", "2026-11-08T00:14:25Z", "failure", 10)],
    job_id=128570147316,
    branch="dependabot/npm_and_yarn/frontend/frontend-npm-d65614ae52",
)

# Job 128841827724 (Engine Tests, main) : un nodeid pytest.
ENGINE_LOG = log("""
    2026-11-08T19:49:11.7989504Z ##[group]Run set -euo pipefail
    2026-11-08T19:49:11.7989963Z \x1b[36;1mset -euo pipefail\x1b[0m
    2026-11-08T19:49:11.8010372Z shell: /usr/bin/bash -e {0}
    2026-11-08T19:49:11.8012378Z ##[endgroup]
    2026-11-08T19:49:11.8482537Z Plancher engine (coverage-floor.json) : 91%
    2026-11-08T19:49:53.6947645Z Required test coverage of 91% reached. Total coverage: 93.85%
    2026-11-08T19:49:53.6948492Z =========================== short test summary info ============================
    2026-11-08T19:49:53.6949281Z SKIPPED [1] tests/semantic/test_constraint_matrix.py:219: got empty parameter set for (cell)
    2026-11-08T19:49:53.6952262Z FAILED tests/test_generate_contract.py::TestGenerateContract::test_build_schedule_minimal_input_returns_valid_contract
    2026-11-08T19:49:53.6953607Z ===== 1 failed, 1064 passed, 2 skipped, 5 deselected, 1 warning in 39.93s ======
    2026-11-08T19:49:55.3054958Z ##[error]Process completed with exit code 1.
""")
ENGINE_STEP = step("Run pytest + coverage + cliquet", "2026-11-08T19:49:11Z", "2026-11-08T19:49:55Z", "failure", 8)
ENGINE_JOB = job("Engine Tests", [ENGINE_STEP], job_id=128841827724, branch="main")


class ReleveCase(unittest.TestCase):
    def setUp(self):
        if M is None:
            self.fail(f"scripts/ci-releve.py introuvable : {LOAD_ERROR}")

    def classify(self, job_, lines, branch="feat/x"):
        v = M.classify(job_, lines, branch)
        self.assertClean(v.detail)
        return v

    def assertClean(self, detail):
        self.assertIsInstance(detail, str)
        self.assertLessEqual(len(detail), 160, detail)
        self.assertNotIn("\x1b", detail)
        self.assertNotIn("##[", detail)


# ---------------------------------------------------------------------------------------------
# T1 — section du step échoué
# ---------------------------------------------------------------------------------------------
class T1StepSection(ReleveCase):
    """La cause se lit dans la section du step échoué seulement : ni le step voisin, ni le corps
    recopié du `run:`, ni ce qui suit la première ligne `##[error]`."""

    def test_rector_section_keeps_every_line_of_the_last_second(self):
        """Job 128678002080 : fenêtre à la seconde entière — les 893 lignes utiles restent."""
        section = M.step_section(RECTOR_LOG, RECTOR_STEP)

        self.assertTrue(section[-1].startswith("##[error]"), section[-1:])
        expected = [line.split("Z ", 1)[1] if "Z " in line[:30] else line for line in RECTOR_BODY + RECTOR_TAIL_USEFUL]
        self.assertEqual(893, len(expected))
        self.assertEqual([x.rstrip() for x in expected], [x.rstrip() for x in section[:-1]])

    def test_rector_section_excludes_previous_step_and_recopied_body(self):
        text = "\n".join(M.step_section(RECTOR_LOG, RECTOR_STEP))

        self.assertNotIn("Executing script cache:clear", text)
        self.assertNotIn("previous step continuation", text)
        self.assertNotIn("composer rector -- --dry-run", text)
        self.assertNotIn("Prepare all required actions", text)
        self.assertNotIn("ci-stack-down", text)

    def test_pip_audit_section_never_reads_the_npm_report_of_the_same_second(self):
        """Job 125740930651 : npm finit à 05:28:07.54, pip-audit commence à 05:28:07."""
        pip_step = PIP_AUDIT_JOB["steps"][-1]
        text = "\n".join(M.step_section(PIP_AUDIT_LOG, pip_step))

        self.assertNotIn("GHSA-g3hv-75wg-m249", text)
        self.assertNotIn("CPython", text)
        self.assertIn("did not complete successfully", text)

    def test_pip_audit_detail_never_cites_the_neighbour_ghsa(self):
        v = self.classify(PIP_AUDIT_JOB, PIP_AUDIT_LOG, PIP_AUDIT_JOB["head_branch"])

        self.assertNotIn("GHSA", v.detail)

    def test_bom_and_lines_without_timestamp(self):
        lines = [
            "﻿2026-11-08T10:00:00.1000000Z ##[group]Run make lint",
            "2026-11-08T10:00:00.1000001Z ##[endgroup]",
            "2026-11-08T10:00:00.2000000Z boom: first line",
            "  continuation without timestamp",
            "2026-11-08T10:00:00.3000000Z ##[error]Process completed with exit code 2.",
        ]
        section = M.step_section(lines, step("Lint Makefile", "2026-11-08T10:00:00Z", "2026-11-08T10:00:00Z"))

        self.assertEqual(["boom: first line", "continuation without timestamp"], [x.strip() for x in section[:-1]])
        self.assertTrue(all("﻿" not in x for x in section))

    def test_signature_in_recopied_body_is_not_read(self):
        """Le corps recopié du `run:` porte une signature d'infra ; la sortie, un vrai nodeid."""
        lines = synth(["FAILED tests/test_a.py::test_b"], body=['echo "No space left on device" && exit 1'])
        v = self.classify(synth_job("Run pytest + coverage + cliquet"), lines)

        self.assertEqual(VRAI_BUG, v.cause)

    def test_signature_in_neighbour_step_output_is_not_read(self):
        lines = synth(["FAILED tests/test_a.py::test_b"], before=["toomanyrequests: rate limit exceeded"])
        v = self.classify(synth_job("Run pytest + coverage + cliquet"), lines)

        self.assertEqual(VRAI_BUG, v.cause)

    def test_large_log_is_classified_in_under_two_seconds(self):
        output = [f"PASSED tests/test_{i}.py::test_x" for i in range(50_000)] + ["FAILED tests/test_z.py::test_y"]
        lines = synth(output)
        started = time.monotonic()
        v = self.classify(synth_job("Run pytest + coverage + cliquet"), lines)

        self.assertLess(time.monotonic() - started, 2.0)
        self.assertIn("tests/test_z.py::test_y", v.detail)


# ---------------------------------------------------------------------------------------------
# T2 — signatures RUNNER (ligne ##[error] seule) ; délai dépassé ; job sans step échoué
# ---------------------------------------------------------------------------------------------
class T2RunnerSignatures(ReleveCase):
    RUNNER_ERRORS = (
        "Process completed with exit code 137.",
        "The runner has received a shutdown signal. This can happen when the runner service is stopped.",
        "The self-hosted runner: runner-1 lost communication with the server. Verify the machine is running.",
    )

    def test_runner_signature_on_the_error_line_is_infra(self):
        for error in self.RUNNER_ERRORS:
            with self.subTest(error=error):
                v = self.classify(synth_job("Run full suite + coverage + cliquet"), synth(["1) App\\Tests\\FooTest::testBar"], error=error))
                self.assertEqual(INFRA, v.cause)

    def test_exit_137_printed_by_a_test_is_not_a_runner_signature(self):
        v = self.classify(
            synth_job("Run full suite + coverage + cliquet"),
            synth(["subprocess returned exit code 137 in a fixture", "1) App\\Tests\\FooTest::testBar"]),
        )

        self.assertEqual(VRAI_BUG, v.cause)

    def test_job_killed_by_timeout_is_unknown_with_the_running_step(self):
        """Q3 tranchée : délai dépassé → inconnu, testé AVANT « aucun step en échec »."""
        steps = [
            step("Install PHP deps (cached + retried)", "2026-11-08T10:00:00Z", "2026-11-08T10:00:05Z", "success", 5),
            step("Run Playwright suite", "2026-11-08T10:00:05Z", "2026-11-08T10:10:00Z", "cancelled", 6),
        ]
        lines = [
            "2026-11-08T10:00:05.1000000Z ##[group]Run set -o pipefail",
            "2026-11-08T10:00:05.1000001Z ##[endgroup]",
            "2026-11-08T10:05:00.0000000Z   ✓  1 [chromium] › tests/e2e/a.spec.ts:1:1 › a (1.0s)",
            "2026-11-08T10:10:00.5000000Z ##[error]The job running on runner runner-2 has exceeded the maximum execution time of 10 minutes.",
        ]
        v = self.classify(job("E2E (Playwright)", steps), lines)

        self.assertEqual(INCONNU, v.cause)
        self.assertIn("job interrompu par délai", v.detail)
        self.assertIn("Run Playwright suite", v.detail)

    def test_job_without_failed_step_is_infra_runner_not_obtained(self):
        v = self.classify(job("Lint", []), [])

        self.assertEqual(INFRA, v.cause)
        self.assertIn("exécutant non obtenu", v.detail)


# ---------------------------------------------------------------------------------------------
# T3 — réseau/disque dans la section → infra, même avec `did not complete successfully`
# ---------------------------------------------------------------------------------------------
class T3NetworkAndDisk(ReleveCase):
    SIGNATURES = (
        "toomanyrequests: You have reached your pull rate limit",
        "pull access denied for example/image, repository does not exist",
        "manifest unknown: manifest unknown",
        "net/http: TLS handshake timeout",
        "dial tcp 1.2.3.4:443: i/o timeout",
        "read: connection reset by peer",
        "Temporary failure in name resolution",
        "write /var/lib/docker/tmp: No space left on device",
        "Cannot connect to the Docker daemon at unix:///var/run/docker.sock. Is the docker daemon running?",
        "FATAL Fatal error: init error: DB error: failed to download vulnerability DB",
        "audit-retry: endpoint indisponible après 3 essais",
    )
    BROKEN_BUILD = 'ERROR: failed to solve: process "/bin/sh -c npm ci" did not complete successfully: exit code: 1'

    def test_each_signature_wins_over_did_not_complete(self):
        for signature in self.SIGNATURES:
            with self.subTest(signature=signature):
                v = self.classify(synth_job("Build engine"), synth([signature, self.BROKEN_BUILD]))
                self.assertEqual(INFRA, v.cause)

    def test_did_not_complete_without_signature_is_a_real_bug(self):
        v = self.classify(synth_job("Build engine"), synth(["npm error code ERESOLVE", self.BROKEN_BUILD]))

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("npm ci", v.detail)

    def test_did_not_complete_in_an_infra_step_is_still_a_real_bug(self):
        """Un Dockerfile cassé dans « Build the engine image » est du code, pas l'exécutant."""
        v = self.classify(synth_job("Build the engine image"), synth([self.BROKEN_BUILD]))

        self.assertEqual(VRAI_BUG, v.cause)


# ---------------------------------------------------------------------------------------------
# T4 / T5 — `Unable to find image` et `Allowed memory size` ne sont pas des motifs d'infra
# ---------------------------------------------------------------------------------------------
class T4T5NotInfraMotifs(ReleveCase):
    def test_gitleaks_real_leak_is_a_real_bug(self):
        """Job 121321368601."""
        v = self.classify(GITLEAKS_JOB, GITLEAKS_LOG)

        self.assertEqual(VRAI_BUG, v.cause)

    def test_semgrep_real_finding_is_a_real_bug(self):
        """Job 127574716730."""
        v = self.classify(SEMGREP_JOB, SEMGREP_LOG)

        self.assertEqual(VRAI_BUG, v.cause)

    def test_memory_exhaustion_is_a_real_bug(self):
        """Job 125871651412 : déterministe aux deux tentatives."""
        v = self.classify(job("Backend Tests (suite + couverture)", [MEMORY_STEP]), MEMORY_LOG)

        self.assertEqual(VRAI_BUG, v.cause)

    def test_memory_exhaustion_in_phpstan_is_a_real_bug(self):
        v = self.classify(synth_job("Run PHPStan"), synth(["PHP Fatal error:  Allowed memory size of 1073741824 bytes exhausted"]))

        self.assertEqual(VRAI_BUG, v.cause)


# ---------------------------------------------------------------------------------------------
# T6 — Install PHP deps : insoluble = code, réseau = infra, sinon inconnu
# ---------------------------------------------------------------------------------------------
class T6InstallPhpDeps(ReleveCase):
    STEP = "Install PHP deps (cached + retried)"

    def test_unresolvable_requirements_is_a_real_bug(self):
        v = self.classify(synth_job(self.STEP), synth(["Your requirements could not be resolved to an installable set of packages.", "  Problem 1"]))

        self.assertEqual(VRAI_BUG, v.cause)

    def test_four_network_failures_are_infra(self):
        output = []
        for attempt in range(1, 5):
            output += [
                f"composer install: attempt {attempt}/4 failed",
                'The "https://repo.packagist.org/packages.json" file could not be downloaded: php_network_getaddresses: getaddrinfo for repo.packagist.org failed: Temporary failure in name resolution',
            ]
        v = self.classify(synth_job(self.STEP), synth(output))

        self.assertEqual(INFRA, v.cause)

    def test_anything_else_is_unknown(self):
        v = self.classify(synth_job(self.STEP), synth(["Something unexpected happened"]))

        self.assertEqual(INCONNU, v.cause)

    def test_other_app_steps_are_real_bugs(self):
        for name in ("Init test database", "Generate JWT keys", "Migrate dev database", "Seed dev data"):
            with self.subTest(step=name):
                v = self.classify(synth_job(name), synth(["SQLSTATE[42P01]: Undefined table"]))
                self.assertEqual(VRAI_BUG, v.cause)


# ---------------------------------------------------------------------------------------------
# T7 — chaque préfixe de la table des familles existe dans ci.yml
# ---------------------------------------------------------------------------------------------
# Liste FERMÉE (plan v3 §2) des pseudo-steps que GitHub nomme lui-même : seuls exemptés.
PSEUDO_STEPS = ("Set up job", "Set up runner", "Complete runner", "Complete job", "Post ", "Run actions/", "Run shivammathur/setup-php@")


def ci_step_names(ci_text):
    return [m.group(1).strip().strip("'\"") for m in re.finditer(r"^\s*-\s+name:\s*(.+?)\s*$", ci_text, re.M)]


def missing_prefixes(prefixes, ci_text):
    names = ci_step_names(ci_text)
    return [p for p in prefixes if not p.startswith(PSEUDO_STEPS) and not any(n.startswith(p) for n in names)]


class T7FamiliesMatchCi(ReleveCase):
    def prefixes(self):
        families = M.STEP_FAMILIES
        return list(families.keys()) if isinstance(families, dict) else [p for p, _ in families]

    def test_every_prefix_names_a_step_of_ci_yml(self):
        """Un step renommé dans ci.yml sans la table rougit `lint` au lieu de tomber en « inconnu »."""
        prefixes = self.prefixes()
        self.assertGreaterEqual(len(prefixes), 40, "Témoin : table des familles quasi vide.")

        self.assertEqual([], missing_prefixes(prefixes, CI_YML.read_text(encoding="utf-8")))

    def test_the_guard_sees_a_phantom_prefix(self):
        """Falsification permanente : un préfixe fantôme est vu, un vrai nom et un pseudo-step non."""
        ci_text = CI_YML.read_text(encoding="utf-8")

        self.assertEqual(
            ["Restore trivy DB cache"],
            missing_prefixes(["Restore trivy DB cache", "Restore trivy vulnerability DB cache", "Run actions/checkout@", "Post Run x"], ci_text),
        )

    def test_names_added_by_v3_are_classified(self):
        cases = {
            "Install deps": INFRA,
            "Engine changed?": INFRA,
            "Skipped, engine untouched": INFRA,
            "Run actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1": INFRA,
            "ParcImportCorrectionTenantNR": VRAI_BUG,
            "ci-releve self-test": VRAI_BUG,
            "Constraint keys, capacity mirror, contract shape — against the REAL engine": VRAI_BUG,
        }
        for name, cause in cases.items():
            with self.subTest(step=name):
                self.assertEqual(cause, self.classify(synth_job(name), synth(["some output line"])).cause)

    def test_step_outside_the_table_is_unknown_with_its_name(self):
        """Ancien nom de step du lot des 71 (Blocking Tests, job 125032935526)."""
        v = self.classify(synth_job("PeriodPlanBirthTest"), synth(["some output line"]))

        self.assertEqual(INCONNU, v.cause)
        self.assertTrue(v.detail.startswith("step « PeriodPlanBirthTest »"), v.detail)
        self.assertIn("exit code 1", v.detail)


# ---------------------------------------------------------------------------------------------
# T8 — détail par outil
# ---------------------------------------------------------------------------------------------
class T8DetailPerTool(ReleveCase):
    def test_playwright_deduplicated_on_spec_line_with_attempt_count(self):
        """Job 128900536515 : un seul test, trois essais ; le corps Vite recopié n'est pas lu."""
        v = self.classify(E2E_JOB, E2E_LOG)

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertEqual(1, v.detail.count("mutualize.spec.ts:139"), v.detail)
        self.assertIn("× 3 essais", v.detail)
        self.assertNotIn("retry #", v.detail)
        self.assertNotIn("Vite", v.detail)

    def test_behat_failed_scenario(self):
        """Job 124857015585."""
        v = self.classify(BEHAT_JOB, BEHAT_LOG)

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("features/placement-des-matchs.feature:21", v.detail)
        self.assertNotIn("generation-du-planning-de-saison", v.detail)

    def test_phpunit_failing_method(self):
        v = self.classify(
            synth_job("Run full suite + coverage + cliquet"),
            synth(["There was 1 failure:", "", "1) App\\Tests\\Unit\\Service\\FooTest::testBar", "Failed asserting that false is true."]),
        )

        self.assertIn("App\\Tests\\Unit\\Service\\FooTest::testBar", v.detail)

    def test_phpunit_coverage_floor(self):
        v = self.classify(synth_job("Run full suite + coverage + cliquet"), synth(["OK (4493 tests)", "Couverture SOUS le plancher : 78.90 % < 79 %"]))

        self.assertIn("Couverture SOUS le plancher", v.detail)

    def test_phpunit_fatal_error_file_and_line(self):
        """Job 125871651412 : le Fatal error l'emporte sur une ligne de plancher imprimée par un test."""
        v = self.classify(job("Backend Tests (suite + couverture)", [MEMORY_STEP]), MEMORY_LOG)

        self.assertRegex(v.detail, r"XlsxUploadGuardTest\.php.*61")

    def test_pytest_nodeid(self):
        """Job 128841827724."""
        v = self.classify(ENGINE_JOB, ENGINE_LOG, "main")

        self.assertIn("tests/test_generate_contract.py::TestGenerateContract::test_build_schedule_minimal_input_returns_valid_contract", v.detail)

    def test_vitest_failing_file_without_ansi(self):
        """Job 128570147316."""
        v = self.classify(VITEST_JOB, VITEST_LOG, VITEST_JOB["head_branch"])

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("src/features/matches/ImportPage.test.tsx", v.detail)

    def test_tsc_error(self):
        v = self.classify(synth_job("Build (tsc -b + vite)"), synth(["src/a.ts(12,5): error TS2322: Type 'string' is not assignable to type 'number'."]))

        self.assertIn("error TS2322", v.detail)

    def test_rector_count(self):
        """Job 128678002080."""
        v = self.classify(RECTOR_JOB, RECTOR_LOG, "dependabot/composer/backend/backend-composer-1eb4de9f1c")

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("15 files would have been changed", v.detail)

    def test_cs_fixer_path_never_the_phpstan_neighbour(self):
        """Job 120480644362 : PHPStan « [OK] No errors » partage la seconde de début."""
        v = self.classify(CS_FIXER_JOB, CS_FIXER_LOG)

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("tests/CrossStack/ParcPromptValidatorParityTest.php", v.detail)
        self.assertNotIn("No errors", v.detail)

    def test_phpstan_error_count(self):
        v = self.classify(synth_job("Run PHPStan"), synth([" ------ -----", " [ERROR] Found 3 errors"]))

        self.assertIn("Found 3 errors", v.detail)

    def test_semgrep_rule_and_path(self):
        v = self.classify(SEMGREP_JOB, SEMGREP_LOG)

        self.assertIn("dangerous-subprocess-use-tainted-env-args", v.detail)
        self.assertIn("backend/scripts/scan-local-identities.py", v.detail)

    def test_gitleaks_count_without_fingerprint(self):
        v = self.classify(GITLEAKS_JOB, GITLEAKS_LOG)

        self.assertIn("1 fuite", v.detail)
        self.assertIn("empreinte non recopiée", v.detail)

    def test_gitleaks_verbose_report_never_copies_secret_or_fingerprint(self):
        output = [
            "Finding:     value = fixture",
            "Secret:      fixture",
            "RuleID:      generic-rule",
            "File:        docs/example.md",
            "Fingerprint: 0000000:docs/example.md:generic-rule:3",
            "WRN leaks found: 2",
        ]
        v = self.classify(synth_job("Run gitleaks (full history)"), synth(output))

        self.assertIn("2 fuite", v.detail)
        self.assertNotIn("Secret", v.detail)
        self.assertNotIn("Fingerprint", v.detail)
        self.assertNotIn("0000000", v.detail)

    def test_trivy_cves(self):
        """Job 129311027587."""
        v = self.classify(TRIVY_JOB, TRIVY_LOG)

        self.assertEqual(CVE, v.cause)
        self.assertIn("CVE-2026-111497", v.detail)
        self.assertIn("CVE-2026-98432", v.detail)

    def test_fallback_is_exit_code_unread(self):
        v = self.classify(synth_job("Run mypy"), synth([], error="Process completed with exit code 2."))

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("exit code 2", v.detail)
        self.assertIn("identifiant non lu", v.detail)

    def test_detail_never_carries_a_skipped_line(self):
        """Une ligne utile mais porteuse d'un mot-clé sensible tombe : le détail se replie."""
        v = self.classify(synth_job("Run pytest + coverage + cliquet"), synth(["FAILED tests/test_reset.py::test_password_reset_flow"]))

        self.assertNotIn("password", v.detail.lower())


# ---------------------------------------------------------------------------------------------
# T9 — externe-CVE, et ses deux pièges
# ---------------------------------------------------------------------------------------------
class T9External(ReleveCase):
    GHSA = ("GHSA-2c3f-4g5h-6j7m", "GHSA-3c4f-5g6h-7j8m", "GHSA-4c5f-6g7h-8j9m", "GHSA-5c6f-7g8h-9j2m", "GHSA-6c7f-8g9h-2j3m")

    def test_composer_audit_keeps_at_most_three_ids(self):
        output = ["Found 5 security vulnerability advisories affecting 2 packages:"] + [f"| Advisory ID       | {g} |" for g in self.GHSA]
        v = self.classify(synth_job("Composer audit", job_name="Dependency Audit"), synth(output))

        self.assertEqual(CVE, v.cause)
        ids = re.findall(r"GHSA-[\w-]+", v.detail)
        self.assertGreaterEqual(len(ids), 1)
        self.assertLessEqual(len(set(ids)), 3)

    def test_npm_and_pip_audit_are_external(self):
        for name, line in (("npm audit", "https://github.com/advisories/GHSA-2c3f-4g5h-6j7m"), ("pip-audit", "requests 2.0.0 PYSEC-2026-1 GHSA-3c4f-5g6h-7j8m 2.32.0")):
            with self.subTest(step=name):
                v = self.classify(synth_job(name, job_name="Dependency Audit"), synth([line]))
                self.assertEqual(CVE, v.cause)

    def test_audit_without_identifier(self):
        v = self.classify(synth_job("Composer audit", job_name="Dependency Audit"), synth(["audit failed"]))

        self.assertEqual(CVE, v.cause)
        self.assertIn("audit rouge, identifiant non lu", v.detail)

    def test_audit_endpoint_unavailable_is_infra(self):
        v = self.classify(synth_job("Composer audit", job_name="Dependency Audit"), synth(["audit-retry: endpoint indisponible après 3 essais"]))

        self.assertEqual(INFRA, v.cause)

    def test_pip_audit_with_broken_engine_build_is_a_real_bug(self):
        """Job 125740930651 : la règle 2 passe avant la famille EXTERNE."""
        v = self.classify(PIP_AUDIT_JOB, PIP_AUDIT_LOG, PIP_AUDIT_JOB["head_branch"])

        self.assertEqual(VRAI_BUG, v.cause)
        self.assertIn("pip install", v.detail)

    def test_expired_log_is_classified_by_step_family(self):
        cases = {"Run pytest + coverage + cliquet": VRAI_BUG, "Start services": INFRA, "Composer audit": CVE}
        for name, cause in cases.items():
            with self.subTest(step=name):
                v = self.classify(synth_job(name), None)
                self.assertEqual(cause, v.cause)
                self.assertTrue(v.detail.endswith("(log expiré, > 90 j)"), v.detail)


# ---------------------------------------------------------------------------------------------
# T10 — temps 2 : instable, puis Dependabot ; ni l'un ni l'autre ne remplace infra ni CVE
# ---------------------------------------------------------------------------------------------
class T10History(ReleveCase):
    def run_history(self, cause, *, branch="feat/x", greens=frozenset()):
        return M.apply_history([verdict(cause=cause, branch=branch)], set(greens))[0]

    def test_green_on_same_sha_and_job_makes_bug_and_unknown_flaky(self):
        for cause in (VRAI_BUG, INCONNU):
            with self.subTest(cause=cause):
                v = self.run_history(cause, greens={(SHA_A, "Engine Tests")})
                self.assertEqual(INSTABLE, v.cause)
                self.assertTrue(v.green_later)

    def test_green_never_replaces_infra_nor_cve(self):
        for cause in (INFRA, CVE):
            with self.subTest(cause=cause):
                v = self.run_history(cause, greens={(SHA_A, "Engine Tests")})
                self.assertEqual(cause, v.cause)
                self.assertTrue(v.green_later)

    def test_green_elsewhere_changes_nothing(self):
        for green in ((SHA_B, "Engine Tests"), (SHA_A, "Lint")):
            with self.subTest(green=green):
                v = self.run_history(VRAI_BUG, greens={green})
                self.assertEqual(VRAI_BUG, v.cause)
                self.assertFalse(v.green_later)

    def test_dependabot_branch_replaces_bug_and_unknown_only(self):
        branch = "dependabot/pip/engine/python-dateutil-3.0.0"
        for cause, expected in ((VRAI_BUG, DEPENDABOT), (INCONNU, DEPENDABOT), (INFRA, INFRA), (CVE, CVE)):
            with self.subTest(cause=cause):
                self.assertEqual(expected, self.run_history(cause, branch=branch).cause)

    def test_flaky_on_a_dependabot_branch_stays_flaky(self):
        v = self.run_history(VRAI_BUG, branch="dependabot/npm_and_yarn/frontend/x", greens={(SHA_A, "Engine Tests")})

        self.assertEqual(INSTABLE, v.cause)

    def test_pip_audit_real_case_ends_dependabot(self):
        """Job 125740930651 : classé vrai bug, puis Dependabot par sa branche."""
        v = M.classify(PIP_AUDIT_JOB, PIP_AUDIT_LOG, PIP_AUDIT_JOB["head_branch"])

        self.assertEqual(DEPENDABOT, M.apply_history([v], set())[0].cause)


# ---------------------------------------------------------------------------------------------
# T11 — dédoublonnage des tentatives et récurrences
# ---------------------------------------------------------------------------------------------
class T11DedupeAndRecurrences(ReleveCase):
    def test_two_red_attempts_of_one_job_on_one_sha_make_one_line(self):
        """Mémoire 125871651412 + 125879005658 : une ligne, deux tentatives."""
        lines = M.dedupe_attempts([verdict(attempt=1, job_id=1), verdict(attempt=2, job_id=2)])

        self.assertEqual(1, len(lines))
        self.assertEqual(2, lines[0].attempts_red)

    def test_different_sha_or_job_stay_apart(self):
        self.assertEqual(2, len(M.dedupe_attempts([verdict(job_id=1), verdict(job_id=2, head_sha=SHA_B)])))
        self.assertEqual(2, len(M.dedupe_attempts([verdict(job_id=1), verdict(job_id=2, job="Lint")])))

    def test_recurrence_needs_two_distinct_sha_and_splits_causes(self):
        found = M.recurrences([verdict(job_id=1), verdict(job_id=2, head_sha=SHA_B, cause=INSTABLE)])

        self.assertEqual(1, len(found))
        self.assertEqual("Engine Tests", field(found[0], "job"))
        self.assertEqual(2, field(found[0], "shas"))
        self.assertEqual({VRAI_BUG: 1, INSTABLE: 1}, dict(field(found[0], "causes")))

    def test_same_sha_twice_or_other_detail_is_no_recurrence(self):
        self.assertEqual([], list(M.recurrences([verdict(job_id=1), verdict(job_id=2, attempt=2)])))
        self.assertEqual([], list(M.recurrences([verdict(job_id=1), verdict(job_id=2, head_sha=SHA_B, detail_key="other")])))

    def test_same_failure_in_two_logs_has_one_detail_key(self):
        """Engine test_generate_contract rouge sur deux sha : une seule clé, quels que soient les compteurs."""
        a = synth(["FAILED tests/test_c.py::test_k", "===== 1 failed, 1064 passed in 39.93s ====="])
        b = synth(["FAILED tests/test_c.py::test_k", "===== 1 failed, 1070 passed in 41.02s ====="])
        c = synth(["FAILED tests/test_c.py::test_other"])
        name = "Run pytest + coverage + cliquet"

        ka = M.classify(synth_job(name), a, "main").detail_key
        self.assertEqual(ka, M.classify(synth_job(name, sha=SHA_B), b, "main").detail_key)
        self.assertNotEqual(ka, M.classify(synth_job(name), c, "main").detail_key)

    def test_playwright_key_ignores_retries_and_durations(self):
        one = synth(["  ✘  4 [chromium] › tests/e2e/a.spec.ts:10:1 › titre (3.1s)", "  1 failed"])
        three = synth([
            "  ✘  7 [chromium] › tests/e2e/a.spec.ts:10:1 › titre (2.0s)",
            "  ✘  8 [chromium] › tests/e2e/a.spec.ts:10:1 › titre (retry #1) (2.2s)",
            "  1 failed",
        ])
        name = "Run Playwright suite"

        self.assertEqual(M.classify(synth_job(name), one, "x").detail_key, M.classify(synth_job(name), three, "x").detail_key)


# ---------------------------------------------------------------------------------------------
# T12 — assainissement
# ---------------------------------------------------------------------------------------------
class T12Sanitize(ReleveCase):
    # Une entrée par mot-clé du motif SKIP (plan v2 §7.8), casse mélangée.
    SKIPPED = (
        "TOTP code accepted",
        "Password: hidden",
        "PassWD reset",
        "Mot De Passe requis",
        "SECRET_NAME is set",
        "Token refreshed",
        "Authorization: x",
        "using BEARER scheme",
        "Credential helper missing",
        "Setup Key: x",
        "OTPAUTH uri built",
        '{"body": "x"}',
        "Secret:      x",
        "Fingerprint: x",
    )

    def test_each_sensitive_keyword_drops_the_whole_line(self):
        for line in self.SKIPPED:
            with self.subTest(line=line):
                self.assertIsNone(M.sanitize(line))
                self.assertIsNone(M.sanitize("2026-11-08T10:00:01.0000000Z " + line))

    def test_neutral_line_is_kept_as_is(self):
        self.assertEqual("FAILED tests/test_a.py::test_b", M.sanitize("FAILED tests/test_a.py::test_b"))

    def test_timestamp_bom_ansi_and_controls_are_removed(self):
        self.assertEqual(
            "FAIL src/a.test.tsx",
            M.sanitize("﻿2026-11-08T00:14:25.3952607Z \x1b[41m\x1b[1mFAIL\x1b[22m\x1b[49m src/a.test.tsx\x07\r"),
        )

    def test_pipe_is_escaped_for_markdown(self):
        self.assertEqual("a \\| b", M.sanitize("a | b"))

    def test_workflow_commands_are_neutralised(self):
        for line in ("##[error]boom", "x ##[group]y", "::set-output name=x::y ##[warning]z"):
            with self.subTest(line=line):
                self.assertNotIn("##[", M.sanitize(line) or "")

    def test_length_is_capped_at_160(self):
        self.assertLessEqual(len(M.sanitize("x" * 500)), 160)
        self.assertLessEqual(len(M.sanitize("| " * 200)), 160)


# ---------------------------------------------------------------------------------------------
# T13 — pagination
# ---------------------------------------------------------------------------------------------
class T13Pagination(ReleveCase):
    def test_three_pages_of_100_stop_at_since(self):
        since = date(2026, 11, 1)
        pages = []
        for p in range(3):
            page = []
            for k in range(100):
                i = p * 100 + k
                hours = 240 - i  # i=0 : 2026-11-10T00:00Z ; une heure de moins par run
                day, hour = divmod(hours, 24)
                created = f"2026-11-{day:02d}T{hour:02d}:00:00Z" if day >= 1 else f"2026-10-{31 + day:02d}T{hour:02d}:00:00Z"
                deploy = i in (3, 7)
                page.append({
                    "id": 100000 + i,
                    "name": "Deploy" if deploy else "CI",
                    "path": ".github/workflows/deploy.yml" if deploy else ".github/workflows/ci.yml",
                    "created_at": created,
                    "run_started_at": created,
                    "head_sha": SHA_A,
                    "head_branch": "feat/x",
                    "run_attempt": 1,
                    "status": "completed",
                    "conclusion": "failure",
                    "event": "pull_request",
                    "pull_requests": [],
                })
            pages.append(page)
        calls = []

        def fake_fetch(path):
            calls.append(path)
            m = re.search(r"[?&]page=(\d+)", path)
            n = int(m.group(1)) if m else 1
            return {"total_count": 300, "workflow_runs": pages[n - 1] if n <= 3 else []}

        def no_subprocess(*_a, **_k):
            raise AssertionError("fetch_runs ne fait d'entrée-sortie que par `fetch`")

        saved = {name: getattr(M.subprocess, name) for name in ("run", "Popen", "check_output")} if hasattr(M, "subprocess") else {}
        for name in saved:
            setattr(M.subprocess, name, no_subprocess)
        try:
            runs = M.fetch_runs(since, fetch=fake_fetch)
        finally:
            for name, fn in saved.items():
                setattr(M.subprocess, name, fn)

        expected = {r["id"] for page in pages for r in page if r["name"] == "CI" and r["created_at"] >= "2026-11-01"}
        self.assertEqual(215, len(expected))  # i = 0..216 dans la fenêtre (page 3 la franchit), moins 2 runs Deploy
        self.assertEqual(expected, {r["id"] for r in runs})
        self.assertTrue(all("per_page=100" in c for c in calls), calls)
        self.assertFalse(any(re.search(r"[?&]page=4\b", c) for c in calls), calls)
        self.assertFalse(any("2026-11-01" in c or "created=" in c for c in calls), "aucune donnée CLI dans un chemin gh")


# ---------------------------------------------------------------------------------------------
# T14 — CLI de bout en bout sur un faux `gh` : exclusions, log expiré, codes retour
# ---------------------------------------------------------------------------------------------
FAKE_GH = """#!{python} -I
import json, re, sys
data = json.load(open({data!r}, encoding="utf-8"))
with open({calls!r}, "a", encoding="utf-8") as f:
    f.write(json.dumps(sys.argv[1:]) + "\\n")
args = sys.argv[1:]
if data.get("unauthenticated"):
    sys.stderr.write("To get started with GitHub CLI, please run:  gh auth login\\n")
    sys.exit(4)
if args[:2] == ["auth", "status"]:
    sys.exit(0)
if args[:2] == ["repo", "view"]:
    if any(a in args for a in ("-q", "--jq", "-t", "--template")):
        print("example-org/example-repo")
    else:
        print(json.dumps({{"nameWithOwner": "example-org/example-repo"}}))
    sys.exit(0)
if not args or args[0] != "api":
    sys.exit(1)
path = next((a for a in args[1:] if "actions/" in a), "")
m = re.search(r"actions/jobs/(\\d+)/logs", path)
if m:
    text = data["logs"].get(m.group(1))
    if text is None:
        sys.stderr.write("gh: Gone (HTTP 410)\\n")
        sys.exit(1)
    sys.stdout.write(text)
    sys.exit(0)
m = re.search(r"actions/runs/(\\d+)/attempts/(\\d+)/jobs", path)
if m:
    jobs = [j for j in data["jobs"] if j["run_id"] == int(m.group(1)) and j["run_attempt"] == int(m.group(2))]
    print(json.dumps({{"total_count": len(jobs), "jobs": jobs}}))
    sys.exit(0)
m = re.search(r"actions/runs/(\\d+)/jobs", path)
if m:
    jobs = [j for j in data["jobs"] if j["run_id"] == int(m.group(1))]
    print(json.dumps({{"total_count": len(jobs), "jobs": jobs}}))
    sys.exit(0)
m = re.search(r"actions/jobs/(\\d+)(?:\\?|$)", path)
if m:
    print(json.dumps(next(j for j in data["jobs"] if j["id"] == int(m.group(1)))))
    sys.exit(0)
if re.search(r"actions/(workflows/[^/]+/)?runs(\\?|$)", path):
    p = re.search(r"[?&]page=(\\d+)", path)
    runs = data["runs"] if not p or p.group(1) == "1" else []
    print(json.dumps({{"total_count": len(data["runs"]), "workflow_runs": runs}}))
    sys.exit(0)
m = re.search(r"actions/runs/(\\d+)(?:\\?|$)", path)
if m:
    print(json.dumps(next(r for r in data["runs"] if r["id"] == int(m.group(1)))))
    sys.exit(0)
sys.stderr.write("gh: Not Found (HTTP 404)\\n")
sys.exit(1)
"""


def gh_run(rid, *, sha, branch, created, attempts=1, conclusion="failure", workflow="ci", pr=None):
    return {
        "id": rid,
        "name": "CI" if workflow == "ci" else "Deploy",
        "path": f".github/workflows/{workflow}.yml",
        "head_sha": sha,
        "head_branch": branch,
        "run_attempt": attempts,
        "created_at": created,
        "run_started_at": created,
        "status": "completed",
        "conclusion": conclusion,
        "event": "pull_request" if pr else "push",
        "pull_requests": [{"number": pr}] if pr else [],
    }


def gh_job(jid, rid, name, *, attempt=1, sha, branch, conclusion="failure", failed=None, start="2026-11-08T10:00:00Z", end="2026-11-08T10:00:02Z"):
    steps = [step("Set up job", start, start, "success", 1)]
    if failed:
        steps.append(step(failed, start, end, conclusion, 2))
    j = job(name, steps, job_id=jid, run_id=rid, attempt=attempt, sha=sha, conclusion=conclusion, branch=branch)
    return j


SHA_C, SHA_D, SHA_E = "c" * 40, "d" * 40, "e" * 40
CLI_DATA = {
    "runs": [
        gh_run(9004, sha=SHA_D, branch="main", created="2026-11-08T13:00:00Z"),
        gh_run(9003, sha=SHA_C, branch="feat/releve-c", created="2026-11-08T12:00:00Z", pr=4343),
        gh_run(9002, sha=SHA_A, branch="feat/releve-a", created="2026-11-08T11:00:00Z", workflow="deploy"),
        gh_run(9001, sha=SHA_A, branch="feat/releve-a", created="2026-11-08T09:00:00Z", attempts=2, conclusion="success", pr=4242),
        gh_run(9005, sha=SHA_E, branch="feat/old", created="2026-10-20T09:00:00Z"),
    ],
    "jobs": [
        # 9001, tentative 1 : Rector rouge, puis vert à la tentative 2 → instable.
        gh_job(7001, 9001, "Rector (style gate)", sha=SHA_A, branch="feat/releve-a", failed="Run Rector (dry-run)", start="2026-11-08T09:08:52Z", end="2026-11-08T09:09:17Z"),
        gh_job(7002, 9001, "Lint", sha=SHA_A, branch="feat/releve-a", conclusion="cancelled", failed="Lint Makefile"),
        gh_job(7003, 9001, "Engine semantics", sha=SHA_A, branch="feat/releve-a", conclusion="skipped"),
        gh_job(7011, 9001, "Rector (style gate)", attempt=2, sha=SHA_A, branch="feat/releve-a", conclusion="success", failed="Run Rector (dry-run)"),
        # 9002 : autre workflow, exclu.
        gh_job(7020, 9002, "Deploy to prod", sha=SHA_A, branch="feat/releve-a", failed="Run deploy"),
        # 9003 : log expiré.
        gh_job(7030, 9003, "Engine Tests", sha=SHA_C, branch="feat/releve-c", failed="Run pytest + coverage + cliquet"),
        # 9004 : gitleaks sur main.
        gh_job(7040, 9004, "Secrets Scan (gitleaks)", sha=SHA_D, branch="main", failed="Run gitleaks (full history)", start="2026-11-08T23:15:27Z", end="2026-11-08T23:15:48Z"),
        # 9005 : hors fenêtre.
        gh_job(7050, 9005, "Old Job Outside Window", sha=SHA_E, branch="feat/old", failed="Lint Makefile", start="2026-10-20T09:00:00Z", end="2026-10-20T09:00:02Z"),
    ],
    "logs": {
        "7001": "\n".join(RECTOR_LOG) + "\n",
        "7002": "\n".join(synth(["cancelled"])) + "\n",
        "7020": "\n".join(synth(["deploy failed"])) + "\n",
        "7040": "\n".join(GITLEAKS_LOG).replace("2026-10-25T", "2026-11-08T") + "\n",
        "7050": "\n".join(synth(["old"])) + "\n",
    },
}


class T14Cli(ReleveCase):
    def run_cli(self, data, *args, with_gh=True):
        tmp = Path(self.tmp.name)
        bin_dir = tmp / "bin"
        bin_dir.mkdir(exist_ok=True)
        data_file = tmp / "gh-data.json"
        data_file.write_text(json.dumps(data), encoding="utf-8")
        calls = tmp / "gh-calls.jsonl"
        gh = bin_dir / "gh"
        if with_gh:
            gh.write_text(FAKE_GH.format(python=sys.executable, data=str(data_file), calls=str(calls)), encoding="utf-8")
            gh.chmod(0o700)
        elif gh.exists():
            gh.unlink()
        env = {"PATH": str(bin_dir), "HOME": str(tmp / "home"), "LANG": "C.UTF-8", "PYTHONIOENCODING": "utf-8"}
        (tmp / "home").mkdir(exist_ok=True)
        done = subprocess.run([sys.executable, "-I", str(SCRIPT), *args], capture_output=True, text=True, env=env, cwd=str(tmp), timeout=60, check=False)
        recorded = [json.loads(x) for x in calls.read_text(encoding="utf-8").splitlines()] if calls.exists() else []
        return done, recorded

    def setUp(self):
        super().setUp()
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def rows(self, stdout):
        """Lignes du tableau principal seulement (après `| Date |`, séparateur exclu)."""
        lines = stdout.splitlines()
        start = next((i for i, line in enumerate(lines) if line.startswith("| Date |")), None)
        if start is None:
            return []
        rows = []
        for line in lines[start + 1 :]:
            if not line.startswith("|"):
                break
            if not re.fullmatch(r"\|[\s:|-]+\|", line):
                rows.append(line)
        return rows

    def test_full_run_excludes_and_classifies(self):
        done, _ = self.run_cli(CLI_DATA, "--since", "2026-11-01", "--until", "2026-11-09", "--no-cache")

        self.assertEqual(0, done.returncode, done.stderr)
        rows = self.rows(done.stdout)
        self.assertIn("3 jobs rouges", done.stdout)
        self.assertEqual(3, len(rows), rows)
        rector = next(r for r in rows if "Rector (style gate)" in r)
        self.assertIn(INSTABLE, rector)
        self.assertIn("#4242", rector)
        engine = next(r for r in rows if "Engine Tests" in r)
        self.assertIn("(log expiré, > 90 j)", engine)
        self.assertIn("#4343", engine)
        gitleaks = next(r for r in rows if "Secrets Scan (gitleaks)" in r)
        self.assertIn("main ⚠", gitleaks)
        self.assertIn(VRAI_BUG, gitleaks)
        for absent in ("Deploy to prod", "Lint", "Engine semantics", "Old Job Outside Window"):
            self.assertFalse(any(absent in r for r in rows), absent)
        self.assertIn("Log indisponible pour le job 7030 (expiré, > 90 j) : classé d'après le step.", done.stderr)

    def test_cli_values_never_reach_a_gh_command(self):
        done, recorded = self.run_cli(CLI_DATA, "--since", "2026-11-01", "--until", "2026-11-09", "--branch", "feat/releve-c", "--no-cache")

        self.assertEqual(0, done.returncode, done.stderr)
        rows = self.rows(done.stdout)
        self.assertEqual(1, len(rows), rows)
        self.assertIn("Engine Tests", rows[0])
        self.assertTrue(recorded, "Témoin : le faux gh n'a rien reçu.")
        flat = " ".join(" ".join(a) for a in recorded)
        for value in ("2026-11-01", "2026-11-09", "feat/releve-c"):
            self.assertNotIn(value, flat)
        self.assertFalse(any(a and a[0] == "api" and ("-X" in a or "--method" in a) for a in recorded))

    def test_cache_holds_verdicts_never_logs(self):
        done, _ = self.run_cli(CLI_DATA, "--since", "2026-11-01", "--until", "2026-11-09")

        self.assertEqual(0, done.returncode, done.stderr)
        cache = Path(self.tmp.name) / "home" / ".cache" / "amateo" / "ci-releve" / "jobs"
        files = sorted(cache.glob("*.json"))
        self.assertTrue(files, "Témoin : aucun verdict en cache.")
        for f in files:
            text = f.read_text(encoding="utf-8")
            self.assertNotIn("EventSubscriberToAttributeRector", text)
            self.assertNotIn("Pull complete", text)

    def test_zero_red_exits_zero(self):
        done, _ = self.run_cli({"runs": [], "jobs": [], "logs": {}}, "--days", "14", "--no-cache")

        self.assertEqual(0, done.returncode, done.stderr)
        self.assertIn("Aucun job rouge sur 14 jours.", done.stdout)

    def test_unauthenticated_gh_exits_2_with_the_exact_message(self):
        done, _ = self.run_cli({"unauthenticated": True}, "--days", "14", "--no-cache")

        self.assertEqual(2, done.returncode)
        self.assertIn("gh non authentifié : lance « gh auth login » puis relance.", done.stderr)

    def test_missing_gh_exits_2(self):
        done, _ = self.run_cli({}, "--days", "14", "--no-cache", with_gh=False)

        self.assertEqual(2, done.returncode, done.stderr)
        self.assertIn("gh", done.stderr)

    def test_python_older_than_3_10_exits_2(self):
        code = (
            "import collections, runpy, sys\n"
            "VI = collections.namedtuple('VI', 'major minor micro releaselevel serial')\n"
            "sys.version_info = VI(3, 9, 18, 'final', 0)\n"
            f"sys.argv = [{str(SCRIPT)!r}, '--days', '1']\n"
            f"runpy.run_path({str(SCRIPT)!r}, run_name='__main__')\n"
        )
        done = subprocess.run([sys.executable, "-I", "-c", code], capture_output=True, text=True, timeout=60, check=False)

        self.assertEqual(2, done.returncode, done.stderr)
        self.assertIn("Python 3.10 ou plus requis (trouvé 3.9", done.stderr)


# ---------------------------------------------------------------------------------------------
# T18 — rendu Markdown
# ---------------------------------------------------------------------------------------------
class T18Render(ReleveCase):
    SINCE, UNTIL = date(2026, 10, 27), date(2026, 11, 9)

    def render(self, verdicts, days=14):
        return M.render_markdown(verdicts, days, self.SINCE, self.UNTIL)

    def row_of(self, md, needle):
        return next(line for line in md.splitlines() if line.startswith("|") and needle in line)

    def test_header_singular_and_plural(self):
        self.assertIn("## Jobs CI rouges — 14 jours (du 2026-10-27 au 2026-11-09) — 1 job rouge", self.render([verdict()]))
        self.assertIn("— 2 jobs rouges", self.render([verdict(job_id=1), verdict(job_id=2, head_sha=SHA_B)]))
        self.assertIn("## Jobs CI rouges — 1 jour (", self.render([verdict()], days=1))

    def test_zero_red(self):
        self.assertIn("Aucun job rouge sur 14 jours.", self.render([]))
        self.assertIn("Aucun job rouge sur 1 jour.", self.render([], days=1))
        self.assertNotIn("| Date |", self.render([]))

    def test_table_columns_main_flag_and_pr(self):
        md = self.render([verdict(branch="main", job="Engine Tests"), verdict(job_id=2, head_sha=SHA_B, job="Lint", pr=4242)])

        self.assertIn("| Date | Branche | PR | Job | Tent. | Cause | Détail | Vert ensuite ? |", md)
        self.assertIn("main ⚠", self.row_of(md, "Engine Tests"))
        self.assertIn("—", self.row_of(md, "Engine Tests"))
        self.assertIn("#4242", self.row_of(md, "Lint"))

    def test_sections_by_cause_and_recurrences(self):
        md = self.render([verdict(job_id=1), verdict(job_id=2, head_sha=SHA_B, cause=INSTABLE)])

        self.assertIn("### Par cause", md)
        self.assertIn("### Récurrences (≥ 2 sha)", md)
        self.assertIn("Engine Tests", md.split("### Récurrences (≥ 2 sha)", 1)[1])
        self.assertNotIn("Aucune récurrence.", md)
        self.assertIn("Aucune récurrence.", self.render([verdict()]))

    def test_branch_or_job_cannot_break_the_table(self):
        """Abus : un nom de branche ou de job porteur de `|` ou d'ANSI."""
        md = self.render([verdict(branch="feat/a|b\x1b[31m", job="Evil | Job", detail="x \\| y")])
        row = self.row_of(md, "Evil")

        self.assertEqual(9, len(re.findall(r"(?<!\\)\|", row)), row)
        self.assertNotIn("\x1b", md)


if __name__ == "__main__":
    unittest.main(verbosity=1)
