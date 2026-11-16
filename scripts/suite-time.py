"""Plafond de temps par suite de tests : une suite ne grossit plus en silence.

Usage :
- `python3 -I scripts/suite-time.py --suite <nom> --seconds <entier>` : dernier acte de chaque
  suite dans `ci.yml`. Lit `test-time-ceiling.json` à la racine du dépôt (quel que soit le dossier
  courant). Verdict sur la VM seulement (`RUNNER_ENVIRONMENT=self-hosted`) : code 1 au-dessus du
  plafond ; sur un runner hébergé, la durée est indicative (code 0). Suite absente du fichier :
  code 2, jamais un vert sans mesure.
- `python3 -I scripts/suite-time.py --never-rises <réf git>` : dans `lint` et `lot-gate`. Refuse
  (code 1) une suite retirée du fichier, un plafond ou une marge qui monte, sauf la ligne exacte
  du fondateur, datée, dans le corps de la PR (`PR_BODY`) :
  « Plafond <suite> remonté de A à B s : décision du fondateur du AAAA-MM-JJ. »
  « Marge <suite> remontée de A à B % : décision du fondateur du AAAA-MM-JJ. »
  Fichier absent à la réf : rien à comparer, code 0.

Stdlib seule, lecture seule. Self-test : `scripts/suite-time-test.py`.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
FILE = "test-time-ceiling.json"
_DATE = r"\d{4}-\d{2}-\d{2}"


def verdict(suite: str, seconds: int, ceiling: int, enforce: bool) -> tuple[bool, str]:
    head = f"Plafond de temps : la suite {suite} a duré {seconds} s"
    if not enforce:
        return True, f"{head} sur un runner hébergé : indicatif, aucun verdict."
    if seconds > ceiling:
        return False, (
            f"{head} (plafond {ceiling} s, + {seconds - ceiling} s). "
            "Lance make ci-mesure RUN=<id> : il dit si la base a dérivé, si la suite est au plafond, ou s'il faut relancer une fois. "
            "Un plafond ne remonte que sur décision du fondateur, écrite et datée dans le corps de la PR."
        )
    if seconds * 4 < ceiling * 3:
        return True, f"{head} (plafond {ceiling} s). La durée est sous les trois quarts du plafond : abaisse-le au prochain bilan de lot."
    return True, f"{head} (plafond {ceiling} s)."


def _has_line(body: str, pattern: str) -> bool:
    return any(re.fullmatch(pattern, line.strip()) for line in body.splitlines())


def never_rises(before: dict, after: dict, body: str) -> list[str]:
    refused: list[str] = []
    for suite, old in before.items():
        if suite not in after:
            refused.append(f"{FILE} : la suite {suite} a disparu du fichier.")
            continue
        new = after[suite]
        name = re.escape(suite)
        a, b = int(old["ceiling"]), int(new["ceiling"])
        if b > a and not _has_line(body, rf"Plafond {name} remonté de {a} à {b} s : décision du fondateur du {_DATE}\."):
            refused.append(f"{FILE} : le plafond {suite} passe de {a} à {b} s sans la ligne de décision datée dans le corps de la PR.")
        a, b = int(old["margin"]), int(new["margin"])
        if b > a and not _has_line(body, rf"Marge {name} remontée de {a} à {b} % : décision du fondateur du {_DATE}\."):
            refused.append(f"{FILE} : la marge {suite} passe de {a} à {b} % sans la ligne de décision datée dans le corps de la PR.")
    return refused


def _never_rises_cli(ref: str, current: dict) -> int:
    if subprocess.run(["git", "-C", str(ROOT), "rev-parse", "--verify", "--quiet", f"{ref}^{{commit}}"], capture_output=True, check=False).returncode != 0:
        print(f"{FILE} : la référence {ref} est introuvable (checkout complet requis).", file=sys.stderr)
        return 2
    shown = subprocess.run(["git", "-C", str(ROOT), "show", f"{ref}:{FILE}"], capture_output=True, text=True, encoding="utf-8", check=False)
    if shown.returncode != 0:
        print(f"{FILE} : absent de {ref}, rien à comparer.")
        return 0
    refused = never_rises(json.loads(shown.stdout), current, os.environ.get("PR_BODY", ""))
    for message in refused:
        print(message)
    return 1 if refused else 0


def main(argv: list[str] | None = None) -> int:
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure:
            reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(prog="suite-time", description="Plafond de temps par suite de tests.")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--suite", help="nom de la suite, clé de test-time-ceiling.json")
    group.add_argument("--never-rises", metavar="REF", help="réf git de la base (ex. origin/main)")
    parser.add_argument("--seconds", type=int, help="durée de la suite, en secondes")
    args = parser.parse_args(argv)

    current = json.loads((ROOT / FILE).read_text(encoding="utf-8"))
    if args.never_rises:
        return _never_rises_cli(args.never_rises, current)
    if args.seconds is None:
        parser.error("--suite exige --seconds")
    if args.suite not in current:
        print(f"{FILE} : suite {args.suite} inconnue, aucune mesure.", file=sys.stderr)
        return 2
    ok, message = verdict(args.suite, args.seconds, int(current[args.suite]["ceiling"]), os.environ.get("RUNNER_ENVIRONMENT") == "self-hosted")
    print(message)
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
