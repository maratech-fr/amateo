"""Mesure de la CI : minutes facturées contre le quota, plafonds de temps, relevé hebdomadaire, part de test.

Usage : `make ci-mesure [DAYS=30] [PART=origin/<base>] [RUN=<id>] [CYCLE_DAY=N]`
(= `python3 -I scripts/ci-mesure.py --cycle-day N --days N [--part REF] [--run ID]`). Lecture seule
de l'API GitHub par le `gh` du poste ; stdlib seule ; Python 3.10 ou plus.

Six sections, sur stdout :
1. minutes facturées depuis le jour de renouvellement (`--cycle-day`), ligne « Actions Linux » de
   `organizations/<owner>/settings/billing/usage`, contre 3 000 ; le stockage (« Actions
   storage ») ; le rappel des variables de réserve encore posées sous le seuil ;
2. médiane et 9ᵉ décile des minutes facturées du job « Lot Gate » sur `--days` jours ;
3. par suite de `test-time-ceiling.json` : médianes des 10 derniers runs sur la VM et des 10
   précédents, tendance, plafond, marge, plafond proposé (le plus petit de l'ancien et de la
   médiane récente plus la marge) ;
4. `--run ID` : le diagnostic de chaque suite de ce run au-dessus de son plafond ;
5. le dernier relevé hebdomadaire (`quality-weekly.yml`), et la ligne de roadmap s'il est rouge ;
6. `--part REF` : la part de test du diff `REF...HEAD` (lignes ajoutées).

Codes : 0 ; 2 si `gh` est absent ou non authentifié, ou si la facturation ne se lit pas (aucun
compte affiché, jamais une somme recalculée) ; 3 à 75 % du quota ; 4 à 90 %. Les sections 2 à 6
ne changent jamais le code retour.

Garde-fous : aucune valeur de la ligne de commande n'entre dans une commande `gh` (chemins
d'API constants, slug lu de `gh repo view`, ids lus des réponses ; `--run` converti en entier) ;
jamais d'écriture vers GitHub ; aucune sortie de `gh` recopiée telle quelle. Self-test :
`scripts/ci-mesure-test.py`.
"""

from __future__ import annotations

import argparse
import json
import math
import re
import shutil
import statistics
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
QUOTA = 3000
LINUX_SKU = "Actions Linux"
STORAGE_SKU = "Actions storage"
CEILING_FILE = "test-time-ceiling.json"
WEEKLY_WORKFLOW = "quality-weekly.yml"
LOT_GATE_JOB = "Lot Gate"
RESERVE_VARIABLES = ("CI_RUNS_ON_LOT", "CI_RUNS_ON_VERDICT")
HISTORY = 10
MAX_RUNS = 100

# Suite → (nom du job, préfixe du step de la suite) dans ci.yml.
SUITE_STEPS = {
    "backend": ("Backend Tests (suite + couverture)", "Run full suite"),
    "behat": ("Functional Tests (Behat)", "Functional tests (Behat"),
    "e2e": ("E2E (Playwright)", "Run Playwright suite"),
    "frontend": ("Frontend (build + unit)", "Unit tests (Vitest"),
    "engine": ("Engine Tests", "Run pytest"),
}

_TEST_PREFIXES = ("backend/tests/", "backend/features/", "frontend/tests/", "engine/tests/")
_PRODUCT_PREFIXES = ("backend/src/", "frontend/src/", "engine/app/")
_DAY = re.compile(r"\d{4}-\d{2}-\d{2}")


# ---------------------------------------------------------------------------------------------
# Formats
# ---------------------------------------------------------------------------------------------
def _n(value: int) -> str:
    """Milliers séparés par une espace ordinaire : 2 310."""
    return f"{value:,}".replace(",", " ")


def _signed(value: float, digits: int) -> str:
    """« + 0,5 % », « - 3 % » : signe, espace, virgule décimale, espace avant %."""
    sign = "-" if value < 0 else "+"
    return f"{sign} {abs(value):.{digits}f} %".replace(".", ",")


def _seconds(value: float) -> str:
    return str(round(value))


# ---------------------------------------------------------------------------------------------
# Quota
# ---------------------------------------------------------------------------------------------
def cycle_start(today: date, cycle_day: int) -> date:
    """Dernier jour de renouvellement (`cycle_day` du mois) <= today."""
    if today.day >= cycle_day:
        return today.replace(day=cycle_day)
    previous = today.replace(day=1) - timedelta(days=1)
    return previous.replace(day=min(cycle_day, previous.day))


def _items(usage: dict) -> list:
    items = usage.get("usageItems") if isinstance(usage, dict) else None
    if not isinstance(items, list):
        raise ValueError("réponse de facturation sans usageItems")
    return items


def _sum_since(usage: dict, since: date, sku: str) -> float:
    floor = since.isoformat()
    total = 0.0
    for entry in _items(usage):
        if not isinstance(entry, dict) or entry.get("sku") != sku:
            continue
        day = str(entry.get("date") or "")[:10]
        if _DAY.fullmatch(day) and day >= floor:
            total += float(entry.get("quantity") or 0)
    return total


def billed_minutes(usage: dict, since: date) -> int:
    """Minutes « Actions Linux » facturées depuis `since` (inclus). ValueError sans `usageItems`."""
    return int(round(_sum_since(usage, since, LINUX_SKU)))


def quota_verdict(minutes: int, since: date, quota: int = QUOTA) -> tuple[int, str]:
    line = f"Minutes facturées depuis le {since.isoformat()} : {_n(minutes)} sur {_n(quota)} ({minutes * 100 // quota} %)."
    if minutes * 10 >= quota * 9:
        return 4, line + " Seuil de réserve franchi : demander au fondateur gh variable set CI_RUNS_ON_VERDICT --body amateo."
    if minutes * 4 >= quota * 3:
        return 3, line + " Seuil de réserve franchi : demander au fondateur gh variable set CI_RUNS_ON_LOT --body amateo."
    return 0, line


# ---------------------------------------------------------------------------------------------
# Plafonds de temps
# ---------------------------------------------------------------------------------------------
def diagnose(run_id: int, suite: str, seconds: int, recent: list[int], older: list[int], margin: int) -> str:
    """Lequel des trois cas du plafond rouge : base dérivée, plafond atteint par accumulation, bruit ou PR."""
    recent_median = statistics.median(recent)
    older_median = statistics.median(older)
    trend = (recent_median / older_median - 1) * 100
    excess = (seconds / recent_median - 1) * 100
    head = (
        f"Run {run_id}, suite {suite} : {seconds} s ; médiane des {HISTORY} derniers runs {_seconds(recent_median)} s ; "
        f"des {HISTORY} précédents {_seconds(older_median)} s. "
        f"Tendance {_signed(trend, 1)}, excès {_signed(excess, 0)} pour une marge de {margin} % : "
    )
    if trend > 5:
        return head + "la base a dérivé ; accélère ou fusionne."
    if excess <= margin:
        return head + "la suite est au plafond par accumulation ; accélère ou fusionne."
    return head + "relance une fois ; encore rouge, c'est cette PR."


def proposed_ceiling(ceiling: int, recent: list[int], margin: int) -> int:
    """Au bilan de lot : le plus petit de l'ancien plafond et de la médiane récente plus la marge."""
    return min(ceiling, math.ceil(statistics.median(recent) * (100 + margin) / 100))


# ---------------------------------------------------------------------------------------------
# Part de test
# ---------------------------------------------------------------------------------------------
def classify_path(path: str) -> str | None:
    if path.endswith(".md"):
        return None
    name = path.rsplit("/", 1)[-1]
    if path.startswith(_TEST_PREFIXES) or re.search(r"\.test\.tsx?$", name) or (path.startswith("scripts/") and name.endswith("-test.py")):
        return "test"
    if path.startswith(_PRODUCT_PREFIXES):
        return "produit"
    return None


def part_line(test_lines: int, product_lines: int) -> str:
    total = test_lines + product_lines
    share = round(test_lines * 100 / total) if total else 0
    return f"Part de test : {_n(test_lines)} lignes de test pour {_n(product_lines)} de produit ({share} %)."


# ---------------------------------------------------------------------------------------------
# GitHub (lecture seule)
# ---------------------------------------------------------------------------------------------
class GhError(RuntimeError):
    pass


_SLUG: list[str] = []


def _gh(arguments: list[str]) -> subprocess.CompletedProcess:
    return subprocess.run(
        ["gh", *arguments],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        cwd=str(ROOT),
        timeout=300,
        check=False,
    )


def _slug() -> str:
    if not _SLUG:
        done = _gh(["repo", "view", "--json", "nameWithOwner"])
        if done.returncode != 0:
            raise GhError("gh repo view")
        slug = str(json.loads(done.stdout).get("nameWithOwner") or "")
        if not re.fullmatch(r"[\w.-]+/[\w.-]+", slug):
            raise GhError("slug illisible")
        _SLUG.append(slug)
    return _SLUG[0]


def _api(path: str) -> object:
    """GET `path` ; `path` est fait de constantes, du slug et d'ids entiers."""
    done = _gh(["api", path])
    if done.returncode != 0:
        raise GhError(path)
    return json.loads(done.stdout)


def _billing(since: date, today: date) -> dict:
    """Usage facturé de l'organisation, années du cycle comprises (un cycle peut chevaucher le 1ᵉʳ janvier)."""
    owner = _slug().split("/")[0]
    items: list = []
    for year in sorted({since.year, today.year}):
        answer = _api(f"organizations/{owner}/settings/billing/usage?year={int(year)}")
        items += _items(answer if isinstance(answer, dict) else {})
    return {"usageItems": items}


def _runs(days: int) -> list[dict]:
    floor = (datetime.now(timezone.utc).date() - timedelta(days=max(days, 1) - 1)).isoformat()
    answer = _api(f"repos/{_slug()}/actions/workflows/ci.yml/runs?status=completed&per_page={MAX_RUNS}&created=%3E%3D{floor}")
    runs = answer.get("workflow_runs") if isinstance(answer, dict) else None
    return [run for run in runs or [] if isinstance(run, dict) and isinstance(run.get("id"), int)]


def _jobs(run_id: int) -> list[dict]:
    answer = _api(f"repos/{_slug()}/actions/runs/{int(run_id)}/jobs?per_page=100")
    jobs = answer.get("jobs") if isinstance(answer, dict) else None
    return [job for job in jobs or [] if isinstance(job, dict)]


def _stamp(value: object) -> datetime | None:
    try:
        return datetime.fromisoformat(str(value).replace("Z", "+00:00"))
    except ValueError:
        return None


def _duration(item: dict) -> float | None:
    start, end = _stamp(item.get("started_at")), _stamp(item.get("completed_at"))
    if start is None or end is None or end < start:
        return None
    return (end - start).total_seconds()


def _on_the_vm(job: dict) -> bool:
    """Un runner hébergé s'appelle « GitHub Actions N » ; la VM, non."""
    name = str(job.get("runner_name") or "")
    return bool(name) and not name.startswith("GitHub Actions")


def suite_seconds(jobs: list[dict]) -> dict[str, int]:
    """Durée du step de chaque suite réussie, jobs sur la VM seulement."""
    found: dict[str, int] = {}
    for suite, (job_name, prefix) in SUITE_STEPS.items():
        for job in jobs:
            if job.get("name") != job_name or not _on_the_vm(job):
                continue
            for step in job.get("steps") or []:
                if isinstance(step, dict) and str(step.get("name") or "").startswith(prefix) and step.get("conclusion") in ("success", "failure"):
                    seconds = _duration(step)
                    if seconds is not None:
                        found[suite] = round(seconds)
    return found


# ---------------------------------------------------------------------------------------------
# Sections
# ---------------------------------------------------------------------------------------------
def _section_storage(usage: dict, since: date) -> None:
    storage = _sum_since(usage, since, STORAGE_SKU)
    if storage:
        amount = f"{storage:.1f}".replace(".", ",")
        print(f"Actions storage depuis le {since.isoformat()} : {amount} Go-heures facturés.")


def _section_reserve_variables(minutes: int) -> None:
    if minutes * 4 >= QUOTA * 3:
        return
    done = _gh(["variable", "list", "--json", "name"])
    if done.returncode != 0:
        return
    try:
        names = {str(entry.get("name")) for entry in json.loads(done.stdout) if isinstance(entry, dict)}
    except (ValueError, TypeError, AttributeError):
        return
    for variable in RESERVE_VARIABLES:
        if variable in names:
            print(f"Variable {variable} encore posée sous le seuil de réserve : au renouvellement, demander au fondateur gh variable delete {variable}.")


def _section_lot_gate(history: list[tuple[dict, list[dict]]]) -> None:
    minutes = []
    for _, jobs in history:
        for job in jobs:
            if job.get("name") == LOT_GATE_JOB and job.get("conclusion") in ("success", "failure"):
                seconds = _duration(job)
                if seconds is not None:
                    minutes.append(max(1, math.ceil(seconds / 60)))
    if not minutes:
        print(f"« {LOT_GATE_JOB} » : aucun run sur la fenêtre.")
        return
    decile = statistics.quantiles(minutes, n=10)[-1] if len(minutes) >= 2 else minutes[0]
    print(f"« {LOT_GATE_JOB} » sur {len(minutes)} runs : médiane {_n(round(statistics.median(minutes)))} min, 9ᵉ décile {_n(round(decile))} min facturées.")


def _section_suites(history: list[tuple[dict, list[dict]]], ceilings: dict) -> dict[str, list[tuple[int, int]]]:
    """(run, durée) par suite, du plus récent au plus ancien ; imprime médianes, tendance, plafond proposé."""
    series: dict[str, list[tuple[int, int]]] = {suite: [] for suite in SUITE_STEPS}
    for run, jobs in history:
        for suite, seconds in suite_seconds(jobs).items():
            series[suite].append((int(run["id"]), seconds))
    for suite in SUITE_STEPS:
        entry = ceilings.get(suite) or {}
        ceiling, margin = int(entry.get("ceiling") or 0), int(entry.get("margin") or 0)
        values = [seconds for _, seconds in series[suite]]
        recent, older = values[:HISTORY], values[HISTORY : 2 * HISTORY]
        if not recent:
            print(f"Suite {suite} : aucun run sur la VM ; plafond {ceiling} s, marge {margin} %.")
            continue
        line = f"Suite {suite} : médiane des {len(recent)} derniers runs {_seconds(statistics.median(recent))} s"
        if older:
            line += f" ; des {len(older)} précédents {_seconds(statistics.median(older))} s, tendance {_signed((statistics.median(recent) / statistics.median(older) - 1) * 100, 1)}"
        line += f" ; plafond {ceiling} s, marge {margin} %, plafond proposé {proposed_ceiling(ceiling, recent, margin)} s."
        print(line)
    return series


def _section_run(run_id: int, series: dict[str, list[tuple[int, int]]], ceilings: dict) -> None:
    measured = suite_seconds(_jobs(run_id))
    if not measured:
        print(f"Run {run_id} : aucune suite mesurée sur la VM.")
        return
    for suite, seconds in measured.items():
        entry = ceilings.get(suite) or {}
        ceiling, margin = int(entry.get("ceiling") or 0), int(entry.get("margin") or 0)
        if seconds <= ceiling:
            print(f"Run {run_id}, suite {suite} : {seconds} s, sous le plafond de {ceiling} s.")
            continue
        # Le run diagnostiqué n'entre pas dans les médianes qui le jugent.
        values = [value for other, value in series[suite] if other != run_id]
        recent, older = values[:HISTORY], values[HISTORY : 2 * HISTORY]
        if not recent or not older:
            print(f"Run {run_id}, suite {suite} : {seconds} s pour un plafond de {ceiling} s ; historique insuffisant pour trancher.")
            continue
        print(diagnose(run_id, suite, seconds, recent, older, margin))


def _section_weekly() -> None:
    done = _gh(["run", "list", "--workflow", WEEKLY_WORKFLOW, "--status", "completed", "--limit", "1", "--json", "conclusion,createdAt"])
    try:
        runs = json.loads(done.stdout) if done.returncode == 0 else None
    except ValueError:
        runs = None
    if not isinstance(runs, list):
        print("Relevé hebdomadaire illisible.")
        return
    if not runs:
        print("Relevé hebdomadaire : aucun relevé hebdomadaire encore joué.")
        return
    last = runs[0] if isinstance(runs[0], dict) else {}
    day = str(last.get("createdAt") or "")[:10]
    day = day if _DAY.fullmatch(day) else "?"
    if last.get("conclusion") == "failure":
        print(f"Relevé hebdomadaire du {day} : rouge, un plancher de couverture est franchi. Ligne de roadmap à ouvrir dans la prochaine PR.")
    elif last.get("conclusion") == "success":
        print(f"Relevé hebdomadaire du {day} : vert, planchers de couverture tenus.")
    else:
        print(f"Relevé hebdomadaire du {day} : non conclu, à relancer (gh workflow run {WEEKLY_WORKFLOW}).")


def _section_part(ref: str) -> None:
    if ref.startswith("-") or not re.fullmatch(r"[\w./-]+", ref):
        print("Part de test : référence refusée.")
        return
    done = subprocess.run(["git", "-C", str(ROOT), "diff", "--numstat", f"{ref}...HEAD"], capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=120, check=False)
    if done.returncode != 0:
        print("Part de test : diff illisible (référence absente ?).")
        return
    counts = {"test": 0, "produit": 0}
    for line in done.stdout.splitlines():
        added, _, rest = line.partition("\t")
        path = rest.partition("\t")[2]
        kind = classify_path(path)
        if kind and added.isdigit():
            counts[kind] += int(added)
    print(part_line(counts["test"], counts["produit"]))


# ---------------------------------------------------------------------------------------------
def _parse_args(argv: list[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="ci-mesure", description="Mesure de la CI (lecture seule).")
    parser.add_argument("--cycle-day", type=int, default=1, help="jour de renouvellement du quota (1-28)")
    parser.add_argument("--days", type=int, default=30, help="fenêtre des runs, en jours (défaut 30)")
    parser.add_argument("--part", help="réf git de la base : part de test du diff base...HEAD")
    parser.add_argument("--run", type=int, help="id d'un run rouge sur son plafond : le diagnostic")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    if sys.version_info < (3, 10):
        print("Python 3.10 ou plus requis.", file=sys.stderr)
        return 2
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure:
            reconfigure(encoding="utf-8", errors="replace")
    args = _parse_args(argv)
    if not 1 <= args.cycle_day <= 28:
        print("CYCLE_DAY doit être entre 1 et 28.", file=sys.stderr)
        return 2

    if shutil.which("gh") is None:
        print("gh introuvable : installe GitHub CLI, lance « gh auth login » puis relance.", file=sys.stderr)
        return 2
    if _gh(["auth", "status"]).returncode != 0:
        print("gh non authentifié : lance « gh auth login » puis relance.", file=sys.stderr)
        return 2

    today = datetime.now(timezone.utc).date()
    since = cycle_start(today, args.cycle_day)
    try:
        usage = _billing(since, today)
        minutes = billed_minutes(usage, since)
    except (GhError, ValueError, TypeError):
        print("Facturation illisible : le jeton gh doit lire la facturation de l'organisation. Aucun compte affiché.", file=sys.stderr)
        return 2

    code, message = quota_verdict(minutes, since)
    print(message)
    _section_storage(usage, since)
    _section_reserve_variables(minutes)

    try:
        ceilings = json.loads((ROOT / CEILING_FILE).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        ceilings = {}
    try:
        runs = _runs(args.days)[:MAX_RUNS]
        with ThreadPoolExecutor(max_workers=6) as pool:
            history = list(zip(runs, pool.map(lambda run: _jobs(int(run["id"])), runs), strict=True))
    except (GhError, ValueError):
        print("Runs de ci.yml illisibles : sections Lot Gate et plafonds omises.")
        history = []
    _section_lot_gate(history)
    series = _section_suites(history, ceilings)
    if args.run is not None:
        try:
            _section_run(args.run, series, ceilings)
        except (GhError, ValueError):
            print(f"Run {args.run} illisible.")
    _section_weekly()
    if args.part:
        _section_part(args.part)
    return code


if __name__ == "__main__":
    sys.exit(main())
