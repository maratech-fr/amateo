"""Preuves mécaniques d'une PR de réduction de tests : titres disparus, couverture perdue.

Usage :
- `python3 -I scripts/tests-retires.py --titres <avant> <après>` : un titre par ligne, tel que le
  rend son lanceur (`npx vitest list`, `vendor/bin/phpunit --list-tests`, `npx playwright test
  --list`, lignes « Scénario » des features). Imprime chaque titre disparu ; code 1 si l'un d'eux
  est du sensible, 0 sinon.
- `python3 -I scripts/tests-retires.py --couverture <avant> <après>` : lcov ou clover ; une ligne
  par fichier qui perd des lignes couvertes, avec leurs numéros. Code 0, 2 sur un fichier illisible.

Sensible : un mot de `SENSITIVE_WORDS` (casse ignorée, sur le titre complet, bloc englobant
compris), un test de `backend/tests/Security/`, un test du moteur (identifiant pytest
`fichier.py::test`). La position `fichier:ligne:colonne` de `playwright --list` est neutralisée :
retirer un test décale les suivants sans les faire disparaître.

Stdlib seule, lecture seule, aucun réseau. Self-test : `scripts/tests-retires-test.py`.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

SENSITIVE_WORDS = (
    "membre", "member", "gestionnaire", "consultation", "lecture seule", "read-only", "session",
    "parité", "parity", "isolation", "tenant", "autre club", "abus", "abuse", "403",
)  # fmt: skip

_POSITION = re.compile(r"(\.[cm]?[jt]sx?):\d+:\d+\b")
_SECURITY = re.compile(r"Tests\\Security\\|tests/Security/")
_ENGINE = re.compile(r"\.py::")
_TAG = re.compile(r"</?(?:file|line)\b[^>]*>")


def _titles(path: str) -> set[str]:
    text = Path(path).read_text(encoding="utf-8")
    titles = set()
    for line in text.splitlines():
        title = _POSITION.sub(r"\1", line.strip())
        if title:
            titles.add(title)
    return titles


def is_sensitive(title: str) -> bool:
    lowered = title.lower()
    if any(word in lowered for word in SENSITIVE_WORDS):
        return True
    return bool(_SECURITY.search(title) or _ENGINE.search(title))


def removed_titles(before: str, after: str) -> int:
    gone = sorted(_titles(before) - _titles(after))
    sensitive = [title for title in gone if is_sensitive(title)]
    for title in gone:
        print(title)
    if sensitive:
        print(f"{len(sensitive)} titre(s) du sensible disparu(s) : la PR ne s'ouvre pas sans eux.", file=sys.stderr)
        return 1
    return 0


def _lcov(text: str) -> dict[str, set[int]]:
    covered: dict[str, set[int]] = {}
    current = None
    for raw in text.splitlines():
        line = raw.strip()
        if line.startswith("SF:"):
            current = line[3:]
            covered.setdefault(current, set())
        elif line.startswith("DA:") and current is not None:
            number, _, hits = line[3:].partition(",")
            if int(hits.split(",")[0]) > 0:
                covered[current].add(int(number))
        elif line == "end_of_record":
            current = None
    return covered


def _attribute(tag: str, name: str) -> str:
    found = re.search(rf'\b{name}="([^"]*)"', tag)
    return found.group(1) if found else ""


def _clover(text: str) -> dict[str, set[int]]:
    """Balises `<file path="…">` et `<line num="n" count="c"/>`, lues à plat (aucun parseur XML)."""
    covered: dict[str, set[int]] = {}
    lines: set[int] | None = None
    for tag in _TAG.findall(text):
        if tag.startswith("<file "):
            lines = covered.setdefault(_attribute(tag, "path") or _attribute(tag, "name"), set())
        elif tag.startswith("</file"):
            lines = None
        elif tag.startswith("<line ") and lines is not None:
            count, num = _attribute(tag, "count"), _attribute(tag, "num")
            if count.isdigit() and num.isdigit() and int(count) > 0:
                lines.add(int(num))
    return covered


def _coverage(path: str) -> dict[str, set[int]]:
    text = Path(path).read_text(encoding="utf-8")
    return _clover(text) if text.lstrip().startswith("<") else _lcov(text)


def lost_coverage(before: str, after: str) -> int:
    old, new = _coverage(before), _coverage(after)
    for name in sorted(old):
        lost = sorted(old[name] - new.get(name, set()))
        if lost:
            print(f"{name} : {', '.join(str(n) for n in lost)}")
    return 0


def main(argv: list[str] | None = None) -> int:
    for stream in (sys.stdout, sys.stderr):
        reconfigure = getattr(stream, "reconfigure", None)
        if reconfigure:
            reconfigure(encoding="utf-8", errors="replace")
    parser = argparse.ArgumentParser(prog="tests-retires", description="Titres de tests disparus, couverture perdue.")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--titres", nargs=2, metavar=("AVANT", "APRES"))
    group.add_argument("--couverture", nargs=2, metavar=("AVANT", "APRES"))
    args = parser.parse_args(argv)
    try:
        if args.titres:
            return removed_titles(*args.titres)
        return lost_coverage(*args.couverture)
    except (OSError, ValueError) as error:
        print(f"Fichier illisible : {type(error).__name__}.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
