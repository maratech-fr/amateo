"""Verdict de `main` : un commit de `main` n'est vert que si la PR mergée qui le porte a joué le
gate COMPLET.

Un job sauté vaut succès pour un check requis : une PR vers `main` dont le run n'a joué qu'une
partie du gate (run léger d'une tête partagée avec un lot, run annulé puis remplacé) passerait la
protection de `main`. Ce script, lancé par le job `main-verdict` sur chaque push `main`, ferme ce
trou après le merge : il retrouve la PR mergée vers `main` dont le commit de merge est ce commit,
lit le run `ci.yml` le plus récent de sa tête, et exige que chaque job hors `EXEMPT_JOBS` ait
réussi. `isCiGreen` (console de déploiement) lit la conclusion de ce run.

Entrée : `GITHUB_REPOSITORY` et `GITHUB_SHA` (environnement du job), jeton `GH_TOKEN` lu par `gh`.
Codes : 0 preuve trouvée ; 1 preuve absente ; 2 GitHub injoignable ou entrée mal formée.
Bibliothèque standard seule ; test : `scripts/ci-verdict-test.py`.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import time
from collections.abc import Callable

EXEMPT_JOBS = ("Lot Gate", "Main Verdict")
NETWORK_TRIES = 3
NETWORK_WAIT_SECONDS = 5

_REPO = re.compile(r"[\w.-]+/[\w.-]+")
_SHA = re.compile(r"[0-9a-f]{40}")

_STATES = {
    "skipped": "sauté",
    "cancelled": "annulé",
    None: "non conclu",
}

MSG_INVALID = "Entrée invalide : GITHUB_REPOSITORY ou GITHUB_SHA absent ou mal formé."
MSG_UNREACHABLE = f"GitHub injoignable après {NETWORK_TRIES} essais : relance ce job."
MSG_NO_PR = (
    "Aucune PR mergée vers main ne porte ce commit après 2 minutes d'attente : le gate complet n'est pas prouvé. "
    "Si la PR vient d'être mergée, relance ce job (« Re-run jobs ») : le run garde son identifiant."
)


class Unreachable(Exception):
    """GitHub n'a pas répondu après `NETWORK_TRIES` essais."""


def _state(conclusion: object) -> str:
    return _STATES.get(conclusion if isinstance(conclusion, str) else None, "en échec")


def _read(gh: Callable[[str], object], path: str, sleep: Callable[[float], None]) -> object:
    """Une lecture ; une exception levée par `gh` est un échec réseau, réessayé."""
    for attempt in range(NETWORK_TRIES):
        try:
            return gh(path)
        except Exception:  # noqa: BLE001 — tout échec de `gh` compte comme réseau
            if attempt + 1 < NETWORK_TRIES:
                sleep(NETWORK_WAIT_SECONDS)
    raise Unreachable(path)


def _merged_pull(repo: str, sha: str, gh, sleep, attempts: int, wait_seconds: int) -> dict | None:
    """La PR mergée vers `main` dont le commit de merge est `sha` ; liste vide réessayée."""
    for attempt in range(attempts):
        pulls = _read(gh, f"repos/{repo}/commits/{sha}/pulls?per_page=100", sleep)
        for pull in pulls if isinstance(pulls, list) else []:
            if (
                isinstance(pull, dict)
                and pull.get("merged_at")
                and (pull.get("base") or {}).get("ref") == "main"
                and pull.get("merge_commit_sha") == sha
            ):
                return pull
        if attempt + 1 < attempts:
            sleep(wait_seconds)
    return None


def _latest_run(repo: str, head: str, gh, sleep, attempts: int, wait_seconds: int) -> dict | None:
    """Le run `ci.yml` de PR le plus récent sur `head` — par date puis identifiant, jamais par position."""
    for attempt in range(attempts):
        answer = _read(gh, f"repos/{repo}/actions/workflows/ci.yml/runs?head_sha={head}&event=pull_request&per_page=100", sleep)
        runs = [r for r in (answer.get("workflow_runs") if isinstance(answer, dict) else None) or [] if isinstance(r, dict)]
        if runs:
            return max(runs, key=lambda r: (str(r.get("created_at") or ""), int(r.get("id") or 0)))
        if attempt + 1 < attempts:
            sleep(wait_seconds)
    return None


def verdict(
    repo: str,
    sha: str,
    gh: Callable[[str], object],
    sleep: Callable[[float], None] = time.sleep,
    attempts: int = 6,
    wait_seconds: int = 20,
) -> tuple[int, str]:
    """(code, message) pour le commit `sha` de `main` du dépôt `repo`."""
    if not _REPO.fullmatch(repo or "") or not _SHA.fullmatch(sha or ""):
        return 2, MSG_INVALID
    try:
        pull = _merged_pull(repo, sha, gh, sleep, attempts, wait_seconds)
        if pull is None:
            return 1, MSG_NO_PR
        number = pull.get("number")
        head = str((pull.get("head") or {}).get("sha") or "")

        run = _latest_run(repo, head, gh, sleep, attempts, wait_seconds)
        if run is None:
            return 1, (
                f"La PR #{number} n'a aucun run ci.yml sur sa tête {head[:7]} après 2 minutes d'attente : "
                "le gate complet n'est pas prouvé. Relance ce job."
            )
        run_id = int(run.get("id") or 0)

        answer = _read(gh, f"repos/{repo}/actions/runs/{run_id}/jobs?filter=latest&per_page=100", sleep)
    except Unreachable:
        return 2, MSG_UNREACHABLE

    listed = (answer.get("jobs") if isinstance(answer, dict) else None) or []
    gate = [j for j in listed if isinstance(j, dict) and j.get("name") not in EXEMPT_JOBS]
    if not gate:
        return 1, f"La PR #{number} n'a joué aucun job du gate complet : le gate complet n'est pas prouvé (run {run_id})."

    failed = sorted((j for j in gate if j.get("conclusion") != "success"), key=lambda j: str(j.get("name")))
    if not failed:
        return 0, f"Verdict : la PR #{number} a joué le gate complet (run {run_id}, {len(gate)} jobs réussis)."
    if len(failed) == 1:
        job = failed[0]
        return 1, (
            f"La PR #{number} n'a pas joué le gate complet : « {job.get('name')} » est {_state(job.get('conclusion'))} (run {run_id})."
        )
    named = ", ".join(f"« {j.get('name')} » {_state(j.get('conclusion'))}" for j in failed)
    return 1, f"La PR #{number} n'a pas joué le gate complet : {len(failed)} jobs ne sont pas réussis ({named}) (run {run_id})."


def gh_api(path: str) -> object:
    """GET `path` par `gh api` (jeton `GH_TOKEN`) ; lève sur tout échec."""
    done = subprocess.run(
        ["gh", "api", path],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=60,
        check=False,
    )
    if done.returncode != 0:
        raise RuntimeError(done.stderr.strip() or f"gh api {path} : code {done.returncode}")
    return json.loads(done.stdout)


def main() -> int:
    code, message = verdict(os.environ.get("GITHUB_REPOSITORY", ""), os.environ.get("GITHUB_SHA", ""), gh_api)
    print(message if code == 0 else f"::error title=Verdict de main::{message}")
    return code


if __name__ == "__main__":
    sys.exit(main())
