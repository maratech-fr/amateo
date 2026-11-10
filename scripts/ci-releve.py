"""Relevé des jobs CI rouges : POURQUOI chaque job rouge de `ci.yml` l'a été, sans ouvrir un log.

Usage : `make ci-releve [DAYS=14] [ARGS="--since AAAA-MM-JJ --until AAAA-MM-JJ --branch B --json F --no-cache"]`
(= `python3 -I scripts/ci-releve.py --days N …`). Lecture seule de l'API GitHub par le `gh` du poste ;
stdlib seule ; Python 3.10 ou plus. Markdown sur stdout ; code retour 0 (même à zéro rouge), 2 si `gh`
est absent ou non authentifié, ou si Python est trop ancien.

Garde-fous : aucune valeur de la ligne de commande n'entre dans une commande `gh` (les chemins d'API
sont des constantes, le slug lu de `gh repo view`, les ids lus des réponses JSON ; fenêtre et branche
filtrées ici) ; jamais d'écriture vers GitHub ; toute ligne de log porteuse d'un mot sensible est
supprimée (`_SKIP`) ; le cache ne garde que le verdict, jamais le log. Les règles de classement :
docstring de `classify` (maison unique). Self-test : `scripts/ci-releve-test.py`.
"""

from __future__ import annotations

import argparse
import json
import re
import shutil
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor
from dataclasses import asdict, dataclass, replace
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORKFLOW_FILE = "ci.yml"
MAX_DETAIL = 160
CACHE_VERSION = 1
PAGE_SIZE = 100

VRAI_BUG = "vrai bug"
INSTABLE = "instable"
INFRA = "infra"
CVE = "externe-CVE"
DEPENDABOT = "Dependabot"
INCONNU = "inconnu"
CAUSES = (VRAI_BUG, INSTABLE, INFRA, CVE, DEPENDABOT, INCONNU)

UNAUTHENTICATED = "gh non authentifié : lance « gh auth login » puis relance."
EXPIRED_SUFFIX = "(log expiré, > 90 j)"

# Familles de steps (préfixe du nom tel que `steps[]` le rend → famille). Le plus long préfixe gagne.
# Chaque préfixe, hors pseudo-steps nommés par GitHub, désigne un `- name:` de ci.yml (T7 du self-test).
OUTIL = "outil"
APPLI = "appli"
EXTERNE = "externe"
INFRA_STEP = "infra"
STEP_FAMILIES: dict[str, str] = {
    # OUTIL : un rouge ici est un constat de l'outil sur le code.
    "Refuse pull requests from forks": OUTIL,
    "Lint Docker Compose": OUTIL,
    "Lint Makefile": OUTIL,
    "Stack resolve bench": OUTIL,
    "ci-releve self-test": OUTIL,
    "Lint": OUTIL,
    "Dependency check (knip)": OUTIL,
    "Build (tsc -b + vite)": OUTIL,
    "Unit tests + coverage (Vitest": OUTIL,
    "Run gitleaks": OUTIL,
    "Run semgrep": OUTIL,
    "Run PHPStan": OUTIL,
    "Run PHP-CS-Fixer": OUTIL,
    "Run composer-unused": OUTIL,
    "Run Rector": OUTIL,
    "Blocking suite": OUTIL,
    "ParcImportCorrectionTenantNR": OUTIL,
    "Run full suite + coverage": OUTIL,
    "Run pytest": OUTIL,
    "Run ruff": OUTIL,
    "Check ruff formatting": OUTIL,
    "Run mypy": OUTIL,
    "Run bandit": OUTIL,
    "Run deptry": OUTIL,
    "Run perf gate": OUTIL,
    "Run Playwright suite": OUTIL,
    "A17 security headers": OUTIL,
    "Constraint keys": OUTIL,
    "Functional tests (Behat": OUTIL,
    # APPLI : l'application elle-même (migrations, seed, worker) casse.
    "Generate JWT keys": APPLI,
    "Init test database": APPLI,
    "Migrate dev database": APPLI,
    "Seed dev data": APPLI,
    "Seed the e2e super-admin": APPLI,
    "Start messenger worker": APPLI,
    "Install PHP deps": APPLI,
    # EXTERNE : avis de sécurité publiés sur une dépendance.
    "Composer audit": EXTERNE,
    "npm audit": EXTERNE,
    "pip-audit": EXTERNE,
    "Trivy scan": EXTERNE,
    # INFRA : préparation de l'exécutant, des images, de la pile ; envois et nettoyage.
    "Set up job": INFRA_STEP,
    "Set up runner": INFRA_STEP,
    "Complete runner": INFRA_STEP,
    "Complete job": INFRA_STEP,
    "Post ": INFRA_STEP,
    "Run actions/": INFRA_STEP,
    "Run shivammathur/setup-php@": INFRA_STEP,
    "Isolate the Docker credentials": INFRA_STEP,
    "Prepare .env": INFRA_STEP,
    "Login to Docker Hub": INFRA_STEP,
    "Login to ghcr.io": INFRA_STEP,
    "Pre-build php image": INFRA_STEP,
    "Pre-pull third-party images": INFRA_STEP,
    "Build the engine image": INFRA_STEP,
    "Build engine": INFRA_STEP,
    "Start services": INFRA_STEP,
    "Hand the e2e node_modules volume": INFRA_STEP,
    "Start Vite dev server": INFRA_STEP,
    "Build the nginx frontend image": INFRA_STEP,
    "Build all images": INFRA_STEP,
    "Build prod images": INFRA_STEP,
    "Restore trivy vulnerability DB cache": INFRA_STEP,
    "Install deps": INFRA_STEP,
    "Engine changed?": INFRA_STEP,
    "Skipped, engine untouched": INFRA_STEP,
    "Upload ": INFRA_STEP,
    "Dump ": INFRA_STEP,
    "Classify failure": INFRA_STEP,
    "E2E — flaky tests": INFRA_STEP,
    "Tear down": INFRA_STEP,
}

# Ligne supprimée entière si elle porte l'un de ces mots (insensible à la casse).
_SKIP = re.compile(
    r"totp|password|passwd|mot de passe|secret|token|authorization|bearer|credential|setup key|otpauth"
    r'|"body"|Secret:|Fingerprint:',
    re.IGNORECASE,
)
_TS = re.compile(r"(\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d)(?:\.\d+)?Z(?: (.*))?\Z", re.DOTALL)
_ANSI = re.compile(r"\x1b\[[0-?]*[ -/]*[@-~]|\x1b\][^\x07\x1b]*(?:\x07|\x1b\\)|\x1b[@-Z\\-_]")
_CONTROLS = re.compile(r"[\x00-\x08\x0a-\x1f\x7f]")
_PIPE = re.compile(r"(?<!\\)\|")
_EXIT_CODE = re.compile(r"exit code:? ?(\d+)")

# Règle 0 : signatures de l'exécutant, cherchées sur la ligne `##[error]` seule.
_RUNNER = re.compile(r"exit code 137\b|received a shutdown signal|lost communication with the server")
_TIMEOUT = "exceeded the maximum execution time"
# Règle 1 : réseau / disque, cherchées dans la section du step.
_NETWORK = re.compile(
    "|".join(
        re.escape(s)
        for s in (
            "toomanyrequests:",
            "pull access denied",
            "manifest unknown",
            "TLS handshake timeout",
            "i/o timeout",
            "connection reset by peer",
            "Temporary failure in name resolution",
            "No space left on device",
            "Cannot connect to the Docker daemon",
            "failed to download vulnerability DB",
            "audit-retry: endpoint indisponible après",
        )
    )
)
_BUILD_BROKEN = re.compile(r'process "(.*?)" did not complete successfully: exit code:? ?(\d+)')
_ADVISORY = re.compile(r"\b(GHSA(?:-[0-9a-z]{4}){3}|CVE-\d{4}-\d{4,}|PYSEC-\d{4}-\d+)\b")


@dataclass
class Verdict:
    date: str
    branch: str
    pr: int | None
    job: str
    run_id: int
    job_id: int
    head_sha: str
    attempt: int
    attempts_red: int
    cause: str
    detail: str
    detail_key: str
    step: str
    green_later: bool


# ---------------------------------------------------------------------------------------------
# Assainissement
# ---------------------------------------------------------------------------------------------
def _strip_timestamp(line: str) -> str:
    line = line.lstrip("\ufeff").rstrip("\r\n")
    m = _TS.match(line)
    return (m.group(2) or "") if m else line


def _clean(text: str) -> str:
    text = _ANSI.sub("", text).replace("\t", " ")
    return _CONTROLS.sub("", text)


def _escape(text: str) -> str:
    return _PIPE.sub(r"\\|", _clean(text)).replace("##[", "#[")


def _cap(text: str, limit: int = MAX_DETAIL) -> str:
    if len(text) <= limit:
        return text
    return text[: limit - 1].rstrip("\\ ") + "…"


def sanitize(line: str) -> str | None:
    """Une ligne de log prête à afficher, ou None si elle porte un mot sensible."""
    text = _strip_timestamp(line)
    if _SKIP.search(text):
        return None
    text = _clean(text)
    if _SKIP.search(text):
        return None
    return _cap(_escape(text).strip())


def _fit(main: str, suffix: str = "") -> str:
    """Tient en MAX_DETAIL sans jamais perdre `suffix` (compteur d'essais, fichier:ligne)."""
    if len(main) + len(suffix) <= MAX_DETAIL:
        return main + suffix
    room = MAX_DETAIL - len(suffix) - 1
    if room < 10:
        return _cap(main + suffix)
    return main[:room].rstrip("\\ ") + "…" + suffix


def _compose(items: list[tuple[str, str, str]], prefix: str = "") -> str:
    """Items (tête gardée, texte facultatif, suffixe gardé) joints par « ; », condensés à MAX_DETAIL :
    textes retirés d'abord, puis items en surnombre comptés « (+n) »."""
    full = prefix + " ; ".join(f"{h} › {t}{s}" if t else f"{h}{s}" for h, t, s in items)
    if len(full) <= MAX_DETAIL:
        return full
    if len(items) == 1:
        h, t, s = items[0]
        return _fit(prefix + (f"{h} › {t}" if t else h), s)
    short = [f"{h}{s}" for h, _, s in items]
    kept: list[str] = []
    for part in short:
        rest = len(short) - len(kept) - 1
        tail = f" (+{rest})" if rest else ""
        if len(prefix + " ; ".join([*kept, part]) + tail) > MAX_DETAIL:
            break
        kept.append(part)
    if not kept:
        return _fit(prefix + short[0], f" (+{len(short) - 1})")
    hidden = len(short) - len(kept)
    return prefix + " ; ".join(kept) + (f" (+{hidden})" if hidden else "")


# ---------------------------------------------------------------------------------------------
# Section du step échoué
# ---------------------------------------------------------------------------------------------
def _parse(log_lines: list[str]) -> list[tuple[str, str]]:
    """(seconde, texte) par ligne ; BOM retiré ; une ligne sans horodatage hérite du précédent."""
    parsed: list[tuple[str, str]] = []
    last = ""
    for raw in log_lines:
        line = raw.lstrip("\ufeff").rstrip("\r\n")
        m = _TS.match(line)
        if m:
            last = m.group(1)
            parsed.append((last, m.group(2) or ""))
        else:
            parsed.append((last, line))
    return parsed


def step_section(log_lines: list[str], step: dict) -> list[str]:
    """Texte des lignes du step, après le corps recopié de son `##[group]Run`, jusqu'à la première
    ligne `##[error]` INCLUSE.

    `started_at`/`completed_at` sont tronqués à la seconde, le log est horodaté à 7 décimales : la
    fenêtre va du début de la seconde de départ à la fin de la seconde de fin, et ne sert que de
    contrôle. Le début est ancré sur le dernier `##[group]Run …` (ou `##[start-action …`) qui
    précède le premier `##[error]` de la fenêtre ; le bloc `##[group]Run … ##[endgroup]` (corps du
    `run:` recopié par GitHub) est exclu.
    """
    parsed = _parse(log_lines)
    start = str(step.get("started_at") or "")[:19]
    end = str(step.get("completed_at") or "")[:19]
    lo = next((i for i, (ts, _) in enumerate(parsed) if ts >= start), len(parsed))
    hi = len(parsed) - 1
    if end:
        while hi >= lo and parsed[hi][0] > end:
            hi -= 1
    stop = next((i for i in range(lo, hi + 1) if parsed[i][1].startswith("##[error]")), hi)
    begin = lo
    for i in range(stop, lo - 1, -1):
        text = parsed[i][1]
        if text.startswith("##[group]Run ") and i != stop:
            close = next((k for k in range(i + 1, stop + 1) if parsed[k][1].startswith("##[endgroup]")), None)
            begin = close + 1 if close is not None else i + 1
            break
        if text.startswith("##[start-action") and i != stop:
            begin = i + 1
            break
    return [text for _, text in parsed[begin : stop + 1]]


# ---------------------------------------------------------------------------------------------
# Détail par outil : chaque extracteur lit des lignes nettoyées, échappées, sans mot sensible,
# et rend (détail, clé) ou None.
# ---------------------------------------------------------------------------------------------
_DURATION = re.compile(r"\s*\((?:\d+(?:\.\d+)?m?s|\d+(?:\.\d+)?m)\)\s*$")
_RETRY = re.compile(r"\s*\(retry #\d+\)\s*$")
_PW_RED = re.compile(r"✘\s+\d+\s+(?:\[[^\]]+\]\s+›\s+)?(\S+?):(\d+):\d+\s+›\s+(.*)$")
_PW_LISTED = re.compile(r"^\s*(?:\[[^\]]+\]\s+›\s+)?(\S+?\.(?:spec|test)\.\w+):(\d+):\d+\s+›\s+(.*)$")


def _pw_title(title: str) -> str:
    previous = None
    while previous != title:
        previous = title
        title = _RETRY.sub("", _DURATION.sub("", title))
    return title.strip()


def _playwright(lines: list[str]) -> tuple[str, str] | None:
    counts: dict[str, int] = {}
    titles: dict[str, str] = {}
    for line in lines:
        m = _PW_RED.search(line)
        if m:
            loc = f"{m.group(1)}:{m.group(2)}"
            counts[loc] = counts.get(loc, 0) + 1
            titles.setdefault(loc, _pw_title(m.group(3)))
    listed: list[str] = []
    in_list = False
    for line in lines:
        if re.match(r"^\s*\d+ failed\s*$", line):
            in_list = True
            continue
        if in_list:
            m = _PW_LISTED.match(line)
            if not m:
                in_list = False
                continue
            loc = f"{m.group(1)}:{m.group(2)}"
            if loc not in listed:
                listed.append(loc)
                titles.setdefault(loc, _pw_title(m.group(3)))
    locs = listed or list(counts)
    if not locs:
        return None
    items = []
    for loc in locs:
        k = counts.get(loc, 1)
        items.append((loc, titles.get(loc, ""), f" × {k} essais" if k > 1 else ""))
    return _compose(items), " ".join(sorted(locs))


def _behat(lines: list[str]) -> tuple[str, str] | None:
    found: list[str] = []
    after = False
    for line in lines:
        if "--- Failed scenarios:" in line:
            after = True
            continue
        if after:
            m = re.search(r"(features/\S+?\.feature:\d+)", line)
            if m and m.group(1) not in found:
                found.append(m.group(1))
    if not found:
        return None
    return _compose([(f, "", "") for f in found], "Behat : "), " ".join(sorted(found))


_FATAL = re.compile(r"(?:PHP )?Fatal error: +(.*?) in (/?\S+?\.php)(?: on line |:)(\d+)")


def _php_fatal(lines: list[str]) -> tuple[str, str] | None:
    for line in lines:
        m = _FATAL.search(line)
        if m:
            message = re.sub(r"\s*\(tried to allocate [^)]*\)", "", m.group(1)).strip()
            path = re.sub(r"^.*?/(?=(?:tests|src|config|bin|vendor)/)", "", m.group(2))
            return _fit(f"Fatal error: {message}", f" — {path}:{m.group(3)}"), f"Fatal error {path}:{m.group(3)}"
    return None


def _phpunit(lines: list[str]) -> tuple[str, str] | None:
    found: list[str] = []
    for line in lines:
        m = re.match(r"^\s*\d+\) ([A-Za-z_][\w\\]*::\w+)", line)
        if m and m.group(1) not in found:
            found.append(m.group(1))
    if not found:
        return None
    return _compose([(f, "", "") for f in found]), " ".join(sorted(found))


def _coverage_floor(lines: list[str]) -> tuple[str, str] | None:
    for line in lines:
        if "Couverture SOUS le plancher" in line:
            return _cap(line.strip()), "Couverture SOUS le plancher"
    return None


def _pytest(lines: list[str]) -> tuple[str, str] | None:
    found: list[tuple[str, str]] = []
    for line in lines:
        m = re.match(r"^(FAILED|ERROR) (\S+)", line.strip())
        if m and (m.group(1), m.group(2)) not in found:
            found.append((m.group(1), m.group(2)))
    if not found:
        return None
    return _compose([(f"{kind} {node}", "", "") for kind, node in found]), " ".join(sorted(n for _, n in found))


def _vitest(lines: list[str]) -> tuple[str, str] | None:
    found: list[str] = []
    for line in lines:
        m = re.search(r"\bFAIL\s+(\S+\.(?:test|spec)\.[cm]?[jt]sx?)", line)
        if m and m.group(1) not in found:
            found.append(m.group(1))
    if not found:
        return None
    return _compose([(f"FAIL {f}", "", "") for f in found]), " ".join(sorted(found))


def _tsc(lines: list[str]) -> tuple[str, str] | None:
    found: list[tuple[str, str]] = []
    for line in lines:
        m = re.search(r"(\S+?\(\d+,\d+\)): (error TS\d+): ?(.*)$", line)
        if m:
            found.append((f"{m.group(1)}: {m.group(2)}", m.group(3)))
        elif "error TS" in line:
            found.append((line.strip(), ""))
    if not found:
        return None
    return _compose([(h, t, "") for h, t in found]), " ".join(sorted({h for h, _ in found}))


def _php_paths(lines: list[str]) -> list[str]:
    found: list[str] = []
    for line in lines:
        m = re.match(r"^\s*\d+\) (\S+?\.php)\b", line)
        if m and m.group(1) not in found:
            found.append(m.group(1))
    return found


def _rector(lines: list[str]) -> tuple[str, str] | None:
    for line in lines:
        m = re.search(r"\[OK\] (\d+ files? would have been changed)", line)
        if m:
            files = _php_paths(lines)
            main = f"Rector : {m.group(1)} (dry-run)"
            if files:
                main = _compose([(f, "", "") for f in files], main + " — ")
            return _cap(main), "Rector " + (" ".join(sorted(files)) or m.group(1))
    return None


def _cs_fixer(lines: list[str]) -> tuple[str, str] | None:
    files = _php_paths(lines)
    if files:
        return _compose([(f, "", "") for f in files], "CS-Fixer : "), "CS-Fixer " + " ".join(sorted(files))
    found = next((m for m in (re.search(r"Found (\d+) of \d+ files", x) for x in lines) if m), None)
    return (f"CS-Fixer : {found.group(1)} fichier(s) à corriger", "CS-Fixer") if found else None


def _phpstan(lines: list[str]) -> tuple[str, str] | None:
    found = next((m for m in (re.search(r"\[ERROR\] (Found \d+ errors?)", x) for x in lines) if m), None)
    if not found:
        return None
    files: list[str] = []
    for line in lines:
        m = re.match(r"^\s*Line\s+(\S+\.php)", line)
        if m and m.group(1) not in files:
            files.append(m.group(1))
    main = f"PHPStan : {found.group(1)}"
    if files:
        main = _compose([(f, "", "") for f in files], main + " — ")
    return _cap(main), "PHPStan " + (" ".join(sorted(files)) or found.group(1))


_SEMGREP_RULE = re.compile(r"[❯❱]+\s+([\w.-]+\.[\w-]+)\s*$")


def _semgrep(lines: list[str]) -> tuple[str, str] | None:
    found: list[str] = []
    path = ""
    for line in lines:
        m = re.match(r"^\s{2,}(\S+\.\w+)\s*$", line)
        if m and not _SEMGREP_RULE.search(line):
            path = m.group(1)
            continue
        m = _SEMGREP_RULE.search(line)
        if m:
            item = f"{m.group(1).rsplit('.', 1)[-1]} — {path}" if path else m.group(1).rsplit(".", 1)[-1]
            if item not in found:
                found.append(item)
    if not found:
        return None
    return _compose([(f, "", "") for f in found], "Semgrep : "), " ".join(sorted(found))


def _gitleaks(lines: list[str]) -> tuple[str, str] | None:
    for line in lines:
        m = re.search(r"leaks found: (\d+)", line)
        if m:
            return f"gitleaks : {m.group(1)} fuite(s) — empreinte non recopiée", "gitleaks leaks"
    return None


def _python_lint(lines: list[str]) -> tuple[str, str] | None:
    found: list[str] = []
    for line in lines:
        m = re.match(r"^\s*(?:Would reformat: )?(\S+\.py)(?::(\d+)(?::\d+)?:? ?(.*))?$", line)
        if m and (m.group(2) or line.strip().startswith("Would reformat")):
            item = f"{m.group(1)}:{m.group(2)} {m.group(3) or ''}".strip() if m.group(2) else f"à reformater {m.group(1)}"
            if item not in found:
                found.append(item)
        elif re.search(r"\bDEP\d{3}\b|>> Issue: ", line):
            found.append(line.strip())
    if not found:
        return None
    return _compose([(f, "", "") for f in found]), " ".join(sorted(found))


_PHPUNIT_CHAIN = (_php_fatal, _phpunit, _coverage_floor)
TOOL_EXTRACTORS = {
    "Run Playwright suite": (_playwright,),
    "A17 security headers": (_playwright,),
    "Functional tests (Behat": (_behat, _php_fatal),
    "Run full suite + coverage": _PHPUNIT_CHAIN,
    "Blocking suite": _PHPUNIT_CHAIN,
    "ParcImportCorrectionTenantNR": _PHPUNIT_CHAIN,
    "Constraint keys": _PHPUNIT_CHAIN,
    "Run pytest": (_pytest,),
    "Run perf gate": (_pytest,),
    "Unit tests + coverage (Vitest": (_vitest,),
    "Build (tsc -b + vite)": (_tsc,),
    "Run Rector": (_rector,),
    "Run PHP-CS-Fixer": (_cs_fixer,),
    "Run PHPStan": (_phpstan, _php_fatal),
    "Run semgrep": (_semgrep,),
    "Run gitleaks": (_gitleaks,),
    "Run mypy": (_python_lint,),
    "Run ruff": (_python_lint,),
    "Check ruff formatting": (_python_lint,),
    "Run bandit": (_python_lint,),
    "Run deptry": (_python_lint,),
}


def _longest_prefix(name: str, table: dict) -> str | None:
    best = None
    for prefix in table:
        if name.startswith(prefix) and (best is None or len(prefix) > len(best)):
            best = prefix
    return best


def step_family(name: str) -> str | None:
    prefix = _longest_prefix(name, STEP_FAMILIES)
    return STEP_FAMILIES[prefix] if prefix else None


# ---------------------------------------------------------------------------------------------
# Classement
# ---------------------------------------------------------------------------------------------
def classify(job: dict, log_lines: list[str] | None, branch: str) -> Verdict:
    """Classe UN job rouge (temps 1) ; le temps 2 (instable, Dependabot) est `apply_history`.

    Lecture : uniquement la section du step échoué (`step_section`) — jamais le step voisin, ni
    le corps recopié du `run:`, ni ce qui suit la première ligne `##[error]`. Premier qui matche :

    - délai dépassé (`exceeded the maximum execution time` sur une ligne `##[error]`, testé AVANT
      « aucun step en échec ») → inconnu, `job interrompu par délai — step « <step en cours> »` ;
    - aucun step en `failure` → infra, « exécutant non obtenu » ; plusieurs → le premier par number ;
    - log expiré (`log_lines` None) → classé par la famille du step, suffixe `(log expiré, > 90 j)` ;
    0. signature de l'EXÉCUTANT sur la ligne `##[error]` seule (`exit code 137`, `received a
       shutdown signal`, `lost communication with the server`) → infra ;
    1. signature RÉSEAU/DISQUE dans la section (`_NETWORK` : toomanyrequests:, pull access denied,
       manifest unknown, TLS handshake timeout, i/o timeout, connection reset by peer, Temporary
       failure in name resolution, No space left on device, Cannot connect to the Docker daemon,
       failed to download vulnerability DB, audit-retry: endpoint indisponible après) → infra ;
       elle l'emporte sur la règle 2. `Unable to find image` (écrit avant tout téléchargement,
       réussi compris) et `Allowed memory size` (déterministe) n'en sont PAS ;
    2. `did not complete successfully: exit code` (Dockerfile, npm ci, pip --require-hashes) →
       vrai bug, quelle que soit la famille du step (EXTERNE et INFRA compris) ;
    3. famille OUTIL → vrai bug ; détail par outil (`TOOL_EXTRACTORS`), sinon
       `exit code N (identifiant non lu)` ;
    4. famille APPLI → vrai bug ; `Install PHP deps` : composer insoluble → vrai bug, sinon inconnu
       (le réseau est déjà pris par la règle 1) ;
    5. famille EXTERNE → externe-CVE, ≤ 3 identifiants GHSA/CVE/PYSEC ou `audit rouge, identifiant
       non lu` ;
    6. famille INFRA → infra ;
    7. step hors table (`STEP_FAMILIES`) → inconnu, `step « <nom> » ; <ligne ##[error]>`.

    Limites : « instable » = même head_sha (un rebase ou un commit vide change le sha) ; sur une
    PR le head_sha est celui de la branche, le run teste le commit de fusion ; un step INFRA
    cassé par un YAML de CI fautif reste « infra » ; les signatures des règles 0 et 1 ne sont
    prouvées que par des cas synthétiques.
    """
    name = str(job.get("name") or "")
    steps = sorted(job.get("steps") or [], key=lambda s: int(s.get("number") or 0))
    failed = next((s for s in steps if s.get("conclusion") == "failure"), None)
    step_name = str(failed.get("name") or "") if failed else ""
    when = str(job.get("started_at") or job.get("completed_at") or "")[:10]

    def verdict(cause: str, detail: str, key: str | None = None, step: str = step_name) -> Verdict:
        detail = _cap(_escape(detail).strip())
        return Verdict(
            date=when,
            branch=branch,
            pr=None,
            job=name,
            run_id=int(job.get("run_id") or 0),
            job_id=int(job.get("id") or 0),
            head_sha=str(job.get("head_sha") or ""),
            attempt=int(job.get("run_attempt") or 1),
            attempts_red=1,
            cause=cause,
            detail=detail,
            detail_key=detail if key is None else key,
            step=step,
            green_later=False,
        )

    if log_lines:
        for raw in log_lines:
            if _TIMEOUT in raw and "##[error]" in raw:
                running = next((s for s in steps if s.get("conclusion") == "cancelled"), None) or failed
                running = running or next((s for s in reversed(steps) if s.get("conclusion") not in ("success", "skipped")), None)
                running_name = str(running.get("name") or "") if running else "?"
                return verdict(INCONNU, f"job interrompu par délai — step « {running_name} »", f"délai {running_name}", running_name)

    if failed is None:
        return verdict(INFRA, "exécutant non obtenu (aucun step en échec)", "exécutant non obtenu")

    family = step_family(step_name)
    label = f"step « {step_name} »"
    if log_lines is None:
        cause = {OUTIL: VRAI_BUG, APPLI: VRAI_BUG, EXTERNE: CVE, INFRA_STEP: INFRA}.get(family or "", INCONNU)
        return verdict(cause, _fit(label, " " + EXPIRED_SUFFIX), f"expiré {step_name}")

    section = step_section(log_lines, failed)
    error = ""
    if section and section[-1].startswith("##[error]"):
        error = _clean(section[-1][len("##[error]") :]).strip()
        section = section[:-1]
    cleaned = [_clean(x) for x in section]
    visible = [_escape(x) for x in cleaned if not _SKIP.search(x)]
    shown_error = "" if _SKIP.search(error) else _escape(error)
    code = _EXIT_CODE.search(error)
    exit_text = f"exit code {code.group(1)}" if code else "code de sortie non lu"

    def last_line() -> str:
        return next((x.strip() for x in reversed(visible) if x.strip()), shown_error)

    # Règle 0 : l'exécutant.
    if _RUNNER.search(error):
        return verdict(INFRA, f"exécutant : {shown_error}", f"exécutant {shown_error}")

    # Règle 1 : réseau / disque.
    for text in cleaned:
        m = _NETWORK.search(text)
        if m:
            line = "" if _SKIP.search(text) else _escape(text).strip()
            return verdict(INFRA, _fit(line or m.group(0), f" — {label}"), f"réseau {m.group(0)}")

    # Règle 2 : une construction qui casse est du code.
    for i, text in enumerate(cleaned):
        m = _BUILD_BROKEN.search(text)
        if m:
            command = re.sub(r"\s+", " ", re.sub(r"^/bin/(?:ba)?sh -c ", "", m.group(1))).strip()
            if _SKIP.search(command):
                command = "commande masquée"
            cause_line = next(
                (
                    re.sub(r"^#\d+ (?:\d+\.\d+ )?", "", x).strip()
                    for x in reversed(cleaned[:i])
                    if re.search(r"\berror\b", x, re.IGNORECASE) and "did not complete" not in x and not _SKIP.search(x)
                ),
                "",
            )
            main = f"build cassé : {_escape(command)} (exit code {m.group(2)})"
            if cause_line:
                main += " ; " + _escape(cause_line)
            return verdict(VRAI_BUG, _fit(main), f"build {command}")

    if family == OUTIL:
        prefix = _longest_prefix(step_name, TOOL_EXTRACTORS)
        for extract in TOOL_EXTRACTORS.get(prefix, ()) if prefix else ():
            found = extract(visible)
            if found:
                return verdict(VRAI_BUG, found[0], found[1])
        return verdict(VRAI_BUG, _fit(label, f" ; {exit_text} (identifiant non lu)"), "")

    if family == APPLI:
        if step_name.startswith("Install PHP deps"):
            if any("could not be resolved to an installable set" in x for x in cleaned):
                problem = next((x.strip() for x in visible if "Problem" in x or "requires" in x), "")
                return verdict(VRAI_BUG, _fit("composer : dépendances insolubles" + (f" ; {problem}" if problem else "")), "composer insoluble")
            return verdict(INCONNU, _fit(f"{label} ; {last_line()}"), f"inconnu {step_name}")
        return verdict(VRAI_BUG, _fit(f"{label} ; {last_line()}"), f"{step_name} {last_line()}")

    if family == EXTERNE:
        ids: list[str] = []
        for text in visible:
            for advisory in _ADVISORY.findall(text):
                if advisory not in ids:
                    ids.append(advisory)
        if not ids:
            return verdict(CVE, "audit rouge, identifiant non lu", f"audit {step_name}")
        more = f" (+{len(ids) - 3})" if len(ids) > 3 else ""
        return verdict(CVE, _fit(", ".join(ids[:3]), more), " ".join(sorted(ids)))

    if family == INFRA_STEP:
        return verdict(INFRA, _fit(f"{label} ; {last_line()}"), f"infra {step_name}")

    return verdict(INCONNU, _fit(f"{label} ; {shown_error or last_line()}"), f"inconnu {step_name}")


# ---------------------------------------------------------------------------------------------
# Temps 2, dédoublonnage, récurrences
# ---------------------------------------------------------------------------------------------
def apply_history(verdicts: list[Verdict], greens: set[tuple[str, str]]) -> list[Verdict]:
    """Instable si le même job a conclu `success` sur le MÊME head_sha (autre tentative ou autre
    run) ; puis Dependabot sur une branche `dependabot/`. Ni l'un ni l'autre ne remplace que
    « vrai bug » et « inconnu » : jamais infra ni externe-CVE."""
    out = []
    for v in verdicts:
        green = (v.head_sha, v.job) in greens
        cause = v.cause
        if green and cause in (VRAI_BUG, INCONNU):
            cause = INSTABLE
        if v.branch.startswith("dependabot/") and cause in (VRAI_BUG, INCONNU):
            cause = DEPENDABOT
        out.append(replace(v, cause=cause, green_later=green))
    return out


def dedupe_attempts(verdicts: list[Verdict]) -> list[Verdict]:
    """Une ligne par (head_sha, job) : la tentative rouge la plus récente, avec le nombre de rouges."""
    groups: dict[tuple[str, str], list[Verdict]] = {}
    for v in verdicts:
        groups.setdefault((v.head_sha, v.job), []).append(v)
    out = []
    for group in groups.values():
        latest = max(group, key=lambda v: (v.date, v.run_id, v.attempt))
        out.append(replace(latest, attempts_red=sum(v.attempts_red for v in group)))
    return out


def recurrences(verdicts: list[Verdict]) -> list[dict]:
    """Même job, même clé de détail, sur au moins deux head_sha distincts ; causes ventilées."""
    groups: dict[tuple[str, str], list[Verdict]] = {}
    for v in verdicts:
        if v.detail_key:
            groups.setdefault((v.job, v.detail_key), []).append(v)
    found = []
    for (job_name, key), group in groups.items():
        shas = {v.head_sha for v in group}
        if len(shas) < 2:
            continue
        causes: dict[str, int] = {}
        for v in group:
            causes[v.cause] = causes.get(v.cause, 0) + 1
        found.append({"job": job_name, "detail_key": key, "detail": group[0].detail, "shas": len(shas), "causes": causes})
    found.sort(key=lambda r: (-r["shas"], r["job"]))
    return found


# ---------------------------------------------------------------------------------------------
# Rendu
# ---------------------------------------------------------------------------------------------
def _plural(n: int, singular: str, plural: str) -> str:
    return f"{n} {singular if n == 1 else plural}"


def _cell(text: object) -> str:
    return _cap(_escape(str(text)).strip()) or "—"


def render_markdown(verdicts: list[Verdict], days: int, since: date, until: date) -> str:
    span = _plural(days, "jour", "jours")
    if not verdicts:
        return f"## Jobs CI rouges — {span} (du {since.isoformat()} au {until.isoformat()})\n\nAucun job rouge sur {span}.\n"
    lines = [
        f"## Jobs CI rouges — {span} (du {since.isoformat()} au {until.isoformat()}) — "
        f"{_plural(len(verdicts), 'job rouge', 'jobs rouges')}",
        "",
        "| Date | Branche | PR | Job | Tent. | Cause | Détail | Vert ensuite ? |",
        "|---|---|---|---|---|---|---|---|",
    ]
    for v in sorted(verdicts, key=lambda v: (v.date, v.run_id, v.job), reverse=True):
        branch = "main ⚠" if v.branch == "main" else _cell(v.branch)
        pr = f"#{int(v.pr)}" if v.pr else "—"
        attempt = f"{v.attempt}" if v.attempts_red <= 1 else f"{v.attempt} ({v.attempts_red} rouges)"
        green = "oui" if v.green_later else "non"
        lines.append(f"| {v.date} | {branch} | {pr} | {_cell(v.job)} | {attempt} | {v.cause} | {_cell(v.detail)} | {green} |")
    lines += ["", "### Par cause", ""]
    for cause in CAUSES:
        n = sum(1 for v in verdicts if v.cause == cause)
        if n:
            lines.append(f"- {cause} : {n}")
    lines += ["", "### Récurrences (≥ 2 sha)", ""]
    found = recurrences(verdicts)
    if not found:
        lines.append("Aucune récurrence.")
    for r in found:
        split = ", ".join(f"{c} {n}" for c, n in sorted(r["causes"].items(), key=lambda kv: -kv[1]))
        lines.append(f"- {_cell(r['job'])} — {_cell(r['detail'])} : {r['shas']} sha ({split})")
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------------------------
# Lecture de l'API (gh)
# ---------------------------------------------------------------------------------------------
class GhError(RuntimeError):
    def __init__(self, message: str, stderr: str = "") -> None:
        super().__init__(message)
        self.stderr = stderr


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
            raise GhError("gh repo view", done.stderr)
        slug = str(json.loads(done.stdout).get("nameWithOwner") or "")
        if not re.fullmatch(r"[\w.-]+/[\w.-]+", slug):
            raise GhError("slug illisible")
        _SLUG.append(slug)
    return _SLUG[0]


def _api(path: str) -> subprocess.CompletedProcess:
    return _gh(["api", f"repos/{_slug()}/{path}"])


def gh_json(path: str) -> dict:
    """GET `repos/<slug>/<path>` ; `path` est fait de constantes et d'ids lus des réponses."""
    done = _api(path)
    if done.returncode != 0:
        raise GhError(path, done.stderr)
    return json.loads(done.stdout)


def gh_log(job_id: int) -> tuple[list[str] | None, str]:
    """Lignes du log du job, ou (None, raison) : « expiré » sur 404/410, « illisible » sinon."""
    done = _api(f"actions/jobs/{int(job_id)}/logs")
    if done.returncode == 0:
        return done.stdout.splitlines(), ""
    if re.search(r"HTTP 404|HTTP 410", done.stderr):
        return None, "expiré"
    return None, "illisible"


def fetch_runs(since: date, fetch=gh_json) -> list[dict]:
    """Runs de ci.yml créés depuis `since` (inclus), du plus récent au plus ancien. Pagination
    manuelle par 100 ; arrêt à la première page qui franchit `since` ou qui est incomplète. Aucune
    donnée de la ligne de commande dans le chemin : la date est filtrée ici."""
    floor = since.isoformat()
    runs: list[dict] = []
    page = 1
    while True:
        data = fetch(f"actions/workflows/{WORKFLOW_FILE}/runs?per_page={PAGE_SIZE}&page={page}")
        batch = list(data.get("workflow_runs") or [])
        crossed = False
        for run in batch:
            created = str(run.get("created_at") or "")
            if created[:10] < floor:
                crossed = True
                continue
            if str(run.get("path") or "").split("@")[0].endswith(f"workflows/{WORKFLOW_FILE}"):
                runs.append(run)
        if crossed or len(batch) < PAGE_SIZE:
            return runs
        page += 1


def _jobs_of(run: dict, attempt: int) -> list[dict]:
    jobs: list[dict] = []
    page = 1
    while True:
        data = gh_json(f"actions/runs/{int(run['id'])}/attempts/{int(attempt)}/jobs?per_page={PAGE_SIZE}&page={page}")
        batch = list(data.get("jobs") or [])
        jobs += batch
        if len(batch) < PAGE_SIZE or len(jobs) >= int(data.get("total_count") or 0):
            return jobs
        page += 1


def _cache_dir() -> Path:
    return Path.home() / ".cache" / "amateo" / "ci-releve" / "jobs"


def _cache_load(job_id: int) -> Verdict | None:
    try:
        data = json.loads((_cache_dir() / f"{int(job_id)}.json").read_text(encoding="utf-8"))
        if data.get("version") != CACHE_VERSION:
            return None
        return Verdict(**data["verdict"])
    except (OSError, ValueError, KeyError, TypeError):
        return None


def _cache_save(v: Verdict) -> None:
    try:
        folder = _cache_dir()
        folder.mkdir(parents=True, exist_ok=True)
        payload = {"version": CACHE_VERSION, "verdict": asdict(v)}
        (folder / f"{int(v.job_id)}.json").write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    except OSError:
        pass


def _parse_args(argv: list[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(prog="ci-releve", description="Relevé des jobs CI rouges (lecture seule).")
    parser.add_argument("--days", type=int, default=14, help="fenêtre en jours, aujourd'hui compris (défaut 14)")
    parser.add_argument("--since", type=date.fromisoformat, help="AAAA-MM-JJ, prime sur --days")
    parser.add_argument("--until", type=date.fromisoformat, help="AAAA-MM-JJ, borne haute incluse (défaut aujourd'hui)")
    parser.add_argument("--branch", help="ne garder que les rouges de cette branche")
    parser.add_argument("--json", dest="json_path", help="écrit aussi les verdicts dans ce fichier JSON")
    parser.add_argument("--no-cache", action="store_true", help="ignore le cache des verdicts")
    return parser.parse_args(argv)


def _run_date(run: dict) -> str:
    return str(run.get("created_at") or run.get("run_started_at") or "")[:10]


def _red_verdict(run: dict, job: dict, use_cache: bool) -> Verdict:
    cached = _cache_load(int(job["id"])) if use_cache else None
    if cached is None:
        lines, reason = gh_log(int(job["id"]))
        verdict = classify(job, lines, str(run.get("head_branch") or ""))
        if lines is None:
            if reason == "expiré":
                print(f"Log indisponible pour le job {int(job['id'])} (expiré, > 90 j) : classé d'après le step.", file=sys.stderr)
            else:
                verdict = replace(verdict, detail=verdict.detail.replace(EXPIRED_SUFFIX, "(log illisible)"))
                print(f"Log illisible pour le job {int(job['id'])} : classé d'après le step.", file=sys.stderr)
        elif use_cache:
            _cache_save(verdict)
    else:
        verdict = cached
    prs = run.get("pull_requests") or []
    pr = int(prs[0]["number"]) if prs and prs[0].get("number") else None
    return replace(
        verdict,
        date=_run_date(run),
        branch=str(run.get("head_branch") or ""),
        pr=pr,
        run_id=int(run["id"]),
        head_sha=str(run.get("head_sha") or verdict.head_sha),
    )


def main(argv: list[str] | None = None) -> int:
    if sys.version_info < (3, 10):
        found = ".".join(str(x) for x in tuple(sys.version_info)[:3])
        print(f"Python 3.10 ou plus requis (trouvé {found}).", file=sys.stderr)
        return 2
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure:
            reconfigure(encoding="utf-8", errors="replace")
    args = _parse_args(argv)

    if shutil.which("gh") is None:
        print("gh introuvable : installe GitHub CLI, lance « gh auth login » puis relance.", file=sys.stderr)
        return 2
    if _gh(["auth", "status"]).returncode != 0:
        print(UNAUTHENTICATED, file=sys.stderr)
        return 2
    try:
        _slug()
    except (GhError, ValueError):
        print(UNAUTHENTICATED, file=sys.stderr)
        return 2

    until = args.until or datetime.now(timezone.utc).date()
    since = args.since or until - timedelta(days=max(args.days, 1) - 1)
    days = (until - since).days + 1
    ceiling = until.isoformat()

    runs = [r for r in fetch_runs(since) if r.get("status") == "completed"]
    pairs = [(r, a) for r in runs if r.get("conclusion") != "success" or int(r.get("run_attempt") or 1) > 1 for a in range(1, int(r.get("run_attempt") or 1) + 1)]
    with ThreadPoolExecutor(max_workers=6) as pool:
        listed = list(pool.map(lambda pa: (pa[0], _jobs_of(pa[0], pa[1])), pairs))

    greens: set[tuple[str, str]] = set()
    reds: list[tuple[dict, dict]] = []
    for run, jobs in listed:
        for job in jobs:
            sha = str(job.get("head_sha") or run.get("head_sha") or "")
            if job.get("conclusion") == "success":
                greens.add((sha, str(job.get("name") or "")))
            elif job.get("conclusion") in ("failure", "timed_out"):
                if _run_date(run) > ceiling:
                    continue
                if args.branch is not None and run.get("head_branch") != args.branch:
                    continue
                reds.append((run, job))

    red_shas = {str(job.get("head_sha") or run.get("head_sha") or "") for run, job in reds}
    fetched = {int(r["id"]) for r, _ in pairs}
    extra = [r for r in runs if int(r["id"]) not in fetched and r.get("head_sha") in red_shas]
    with ThreadPoolExecutor(max_workers=6) as pool:
        for run, jobs in pool.map(lambda r: (r, _jobs_of(r, int(r.get("run_attempt") or 1))), extra):
            for job in jobs:
                if job.get("conclusion") == "success":
                    greens.add((str(job.get("head_sha") or run.get("head_sha") or ""), str(job.get("name") or "")))

    with ThreadPoolExecutor(max_workers=6) as pool:
        verdicts = list(pool.map(lambda rj: _red_verdict(rj[0], rj[1], not args.no_cache), reds))
    verdicts = dedupe_attempts(apply_history(verdicts, greens))

    sys.stdout.write(render_markdown(verdicts, days, since, until))
    if args.json_path:
        Path(args.json_path).write_text(json.dumps([asdict(v) for v in verdicts], ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
