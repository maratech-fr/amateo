"""Derive the REAL match-placement fixture from ``specs/initiales/rechercherRencontre.xlsx``.

The workbook is an FFBB « rechercher une rencontre » export of a whole season for
the real BCCL club (names kept AS-IS — founder decision: fixture réelle telle
quelle). Every row where *Equipe 1* is a BCCL team is a HOME match the club must
host, i.e. a ``TO_PLACE`` match for ``/place-matches``.

By construction the fixture is 100 % placeable: the access window of each
(venue, ISO weekday) is the ENVELOPE of the kickoffs actually observed there that
weekday, ``[min kickoff, max kickoff + matchMinutes]``. Each observed match
therefore fits inside its own window, and the per-(venue, date) capacity is wide
enough (the envelope spans the whole competition day) to reshuffle same-day
matches onto the 15-min grid without overlap. No league windows / habits / coaches
are emitted: this fixture stresses the HARD placement core (windows + venue
no-overlap) on real volume, not the SOFT terms.

Run from the repo root:  ``python engine/tests/fixtures/derive_rechercher_rencontre.py``
It rewrites ``engine/tests/fixtures/bccl_home_matches_2026.json`` deterministically
(stable sort, no timestamps) so a re-run produces a byte-identical file.

Stdlib only (``zipfile`` + ``xml.etree``): no openpyxl/pandas dependency, so the
derivation is reproducible from any Python 3.12 without the engine venv.
"""

from __future__ import annotations

import json
import pathlib
import re
import xml.etree.ElementTree as ET  # nosemgrep: python.lang.security.use-defused-xml.use-defused-xml -- script de test jamais expédié : parse un .xlsx COMMITÉ de confiance (specs/initiales/rechercherRencontre.xlsx), aucune entrée externe ni utilisateur
import zipfile
from collections import defaultdict
from datetime import date, datetime

REPO_ROOT = pathlib.Path(__file__).resolve().parents[3]
XLSX = REPO_ROOT / "specs" / "initiales" / "rechercherRencontre.xlsx"
OUT = pathlib.Path(__file__).resolve().parent / "bccl_home_matches_2026.json"

CLUB_PREFIX = "B CHARPENNES CROIX LUIZET"
# Must match DEFAULT_MATCH_MIN in app.solver.match_placement — the envelope is
# built with the same duration the solver reserves, so every observed match fits.
MATCH_MIN = 105
_NS = {"s": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


def _slug(label: str) -> str:
    slug = re.sub(r"[^a-z0-9]+", "-", label.strip().lower()).strip("-")
    return slug or "x"


def _read_rows() -> list[dict[str, str]]:
    with zipfile.ZipFile(XLSX) as zf:
        shared = [
            "".join(t.text or "" for t in si.iter(f"{{{_NS['s']}}}t"))
            for si in ET.fromstring(zf.read("xl/sharedStrings.xml")).findall("s:si", _NS)
        ]
        sheet = ET.fromstring(zf.read("xl/worksheets/sheet1.xml"))
    rows: list[dict[str, str]] = []
    for row in sheet.find("s:sheetData", _NS).findall("s:row", _NS):
        cells: dict[str, str] = {}
        for cell in row.findall("s:c", _NS):
            col = re.match(r"[A-Z]+", cell.get("r")).group()
            value = cell.find("s:v", _NS)
            if value is None:
                cells[col] = ""
            elif cell.get("t") == "s":
                cells[col] = shared[int(value.text)]
            else:
                cells[col] = value.text
        rows.append(cells)
    return rows


def _iso_date(raw: str) -> date:
    return datetime.strptime(raw.strip(), "%d/%m/%Y").date()


def _minutes(raw: str) -> int:
    hour, minute = raw.strip().split(":")
    return int(hour) * 60 + int(minute)


def _hhmm(total: int) -> str:
    return f"{total // 60:02d}:{total % 60:02d}"


def build() -> dict[str, object]:
    home = [r for r in _read_rows()[1:] if r.get("C", "").strip().startswith(CLUB_PREFIX)]

    venue_names: dict[str, str] = {}
    team_names: dict[str, str] = {}
    envelope: dict[tuple[str, int], list[int]] = defaultdict(lambda: [24 * 60, 0])
    matches: list[dict[str, object]] = []

    for row in home:
        team_label = row["C"].strip()
        venue_label = row["G"].strip()
        team_id = _slug(team_label)
        venue_id = _slug(venue_label)
        team_names[team_id] = team_label
        venue_names[venue_id] = venue_label
        match_date = _iso_date(row["E"])
        kickoff = _minutes(row["F"])
        iso_day = match_date.isoweekday()
        env = envelope[(venue_id, iso_day)]
        env[0] = min(env[0], kickoff)
        env[1] = max(env[1], kickoff)
        matches.append(
            {
                "id": f"m-{_slug(row['A'])}-{row['B'].strip()}",
                "teamId": team_id,
                "date": match_date.isoformat(),
                "kind": "TO_PLACE",
            }
        )

    windows_by_venue: dict[str, list[dict[str, object]]] = defaultdict(list)
    for (venue_id, iso_day), (low, high) in envelope.items():
        windows_by_venue[venue_id].append({"dayOfWeek": iso_day, "start": _hhmm(low), "end": _hhmm(high + MATCH_MIN)})

    venues = [
        {
            "id": venue_id,
            "name": venue_names[venue_id],
            "matchWindows": sorted(windows_by_venue[venue_id], key=lambda w: w["dayOfWeek"]),
            "unavailabilities": [],
        }
        for venue_id in sorted(venue_names)
    ]
    teams = [
        {"id": team_id, "name": team_names[team_id], "leagueWindows": [], "habits": [], "coaches": []}
        for team_id in sorted(team_names)
    ]
    # Duplicate FFBB match numbers across divisions would collide on id — assert uniqueness.
    ids = [m["id"] for m in matches]
    assert len(ids) == len(set(ids)), "match id collision — the (division, number) key is not unique"
    matches.sort(key=lambda m: (m["date"], m["teamId"], m["id"]))

    return {
        "clubId": "bccl",
        "seasonId": "2026-2027",
        "solverSeed": 42,
        "solverTimeoutSeconds": 60,
        "matches": matches,
        "venues": venues,
        "teams": teams,
    }


if __name__ == "__main__":
    payload = build()
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    to_place = sum(1 for m in payload["matches"] if m["kind"] == "TO_PLACE")
    print(
        f"wrote {OUT.relative_to(REPO_ROOT)} — {to_place} TO_PLACE matches, {len(payload['venues'])} venues, {len(payload['teams'])} teams"
    )
