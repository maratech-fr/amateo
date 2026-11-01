"""Placement d'un match un JOUR FÉRIÉ (PR2 « jours fériés », axes §7.1 « backend↔engine
contract » + « constraint semantics »).

Réponse fondateur 2026-10-27 (refus explicite de « le solveur ignore la ligne férié ») : un
match fixé par la ligue un jour férié n'est PAS un week-end libre. Pour une date fériée le
domaine de placement doit, contre le VRAI schéma de fil :
  1. utiliser la ligne « Jours fériés » du gymnase (dayOfWeek nul + appliesOnPublicHolidays),
     JAMAIS la fenêtre du jour de semaine ;
  2. ignorer les fenêtres de LIGUE indexées sur le jour de semaine (la ligue a posé ce jour
     exprès — la fenêtre « mercredi » n'a pas à s'appliquer un mercredi férié).

ROUGE attendu aujourd'hui : le schéma `MatchPlacementInputSchema` (extra="forbid",
`MatchAccessWindowSchema.day_of_week` requis 1..7) ne peut pas EXPRIMER une ligne férié ni la
liste `publicHolidays` → `model_validate` lève ; et même exprimée, `candidates.py:_candidate_kickoffs`
filtre les fenêtres sur `window.day_of_week == day` (jour de semaine) et les fenêtres de ligue sur
le même jour. Feature absente ⇒ ROUGE pour la bonne raison. NEO rend VERT (dayOfWeek nullable +
appliesOnPublicHolidays + publicHolidays, contrat 1.6→1.7).

Le smoke sémantique de bout en bout (VRAI moteur) vit dans
`backend/tests/CrossStack/MatchPlacementSemanticsGateTest.php` (étape bloquante de `ci.yml`) ;
ce fichier-ci est le filet UNITAIRE le plus bas, jumeau du décor de `test_match_placement.py`.
"""

from __future__ import annotations

from datetime import date
from typing import Any

import pytest
from pydantic import ValidationError

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchAccessWindowSchema, MatchPlacementInputSchema
from app.solver.match_placement import solve_match_placement

# Mercredi 11 novembre 2026 (Armistice) = jour férié national, ISO weekday 3.
HOLIDAY = "2026-11-11"
HOLIDAY_ISO_DAY = 3


def _solve(**over: Any) -> dict[str, Any]:
    base: dict[str, Any] = {
        "version": read_contract_version(),
        "clubId": "club-holiday",
        "seasonId": "season-holiday",
        "matches": [],
        "venues": [],
        "teams": [],
        # Dates fériées du territoire du club, transmises par le backend (table public_holiday).
        "publicHolidays": [HOLIDAY],
    }
    base.update(over)
    return solve_match_placement(MatchPlacementInputSchema.model_validate(base))


def _kickoff(result: dict[str, Any], match_id: str) -> str:
    placement = next(p for p in result["placements"] if p["matchId"] == match_id)
    return str(placement["kickoff"].strftime("%H:%M"))


def test_holiday_match_uses_the_holiday_line_not_the_weekday_window() -> None:
    # Le gymnase a une fenêtre MERCREDI 18:00-22:00 (jour de semaine) ET une ligne « Jours
    # fériés » 10:00-18:00. Le match tombe un mercredi FÉRIÉ : il doit atterrir dans
    # 10:00-18:00 (ligne férié), jamais à 20:00 (fenêtre du mercredi ordinaire).
    result = _solve(
        matches=[{"id": "m1", "teamId": "t1", "date": HOLIDAY, "kind": "TO_PLACE"}],
        venues=[
            {
                "id": "v1",
                "name": "V1",
                "matchWindows": [
                    {"dayOfWeek": HOLIDAY_ISO_DAY, "start": "18:00", "end": "22:00"},
                    {"appliesOnPublicHolidays": True, "start": "10:00", "end": "18:00"},
                ],
                "unavailabilities": [],
            }
        ],
        teams=[{"id": "t1", "name": "T1", "leagueWindows": [], "habits": [], "coaches": []}],
    )

    assert result["status"] == "completed"
    assert result["unplaced"] == [], "un match plaçable dans la ligne férié ne reste pas non placé"
    kickoff = _kickoff(result, "m1")
    # Match 105 min dans 10:00-18:00 → dernier coup d'envoi 16:15.
    assert "10:00" <= kickoff <= "16:15", f"le match férié atterrit dans la ligne férié, pas à {kickoff}"


def test_holiday_match_ignores_the_weekday_league_window() -> None:
    # Fenêtre de ligue indexée MERCREDI 18:00-20:00 : un mercredi ORDINAIRE elle cadrerait le
    # coup d'envoi. Un mercredi FÉRIÉ, la ligne férié 10:00-18:00 vaut et la fenêtre de ligue du
    # jour de semaine est ignorée → le match se pose avant 18:00 (sinon la ligue l'aurait forcé
    # ≥ 18:00, hors de la ligne férié : contradiction insoluble).
    result = _solve(
        matches=[{"id": "m1", "teamId": "t1", "date": HOLIDAY, "kind": "TO_PLACE"}],
        venues=[
            {
                "id": "v1",
                "name": "V1",
                "matchWindows": [{"appliesOnPublicHolidays": True, "start": "10:00", "end": "18:00"}],
                "unavailabilities": [],
            }
        ],
        teams=[
            {
                "id": "t1",
                "name": "T1",
                "leagueWindows": [{"dayOfWeek": HOLIDAY_ISO_DAY, "kickoffMin": "18:00", "kickoffMax": "20:00"}],
                "habits": [],
                "coaches": [],
            }
        ],
    )

    assert result["status"] == "completed"
    assert result["unplaced"] == [], (
        "la fenêtre de ligue du jour de semaine ne doit pas rendre le match férié infaisable"
    )
    kickoff = _kickoff(result, "m1")
    assert "10:00" <= kickoff <= "16:15", (
        f"le coup d'envoi férié tient dans la ligne férié, pas forcé par la ligue à {kickoff}"
    )


def test_ordinary_wednesday_still_honours_the_weekday_window_and_league() -> None:
    # TÉMOIN (falsification dans l'autre sens) : le MÊME décor un mercredi NON férié
    # (2026-11-18) place le match dans la fenêtre du mercredi 18:00-22:00 ET dans la fenêtre de
    # ligue 18:00-20:00 — la ligne férié ne doit PAS fuiter sur un jour ordinaire. Si NEO
    # neutralisait les fenêtres du jour de semaine en général, CE test rougirait.
    ordinary_wednesday = "2026-11-18"
    result = _solve(
        publicHolidays=[HOLIDAY],  # 18/11 n'en fait pas partie
        matches=[{"id": "m1", "teamId": "t1", "date": ordinary_wednesday, "kind": "TO_PLACE"}],
        venues=[
            {
                "id": "v1",
                "name": "V1",
                "matchWindows": [
                    {"dayOfWeek": HOLIDAY_ISO_DAY, "start": "18:00", "end": "22:00"},
                    {"appliesOnPublicHolidays": True, "start": "10:00", "end": "18:00"},
                ],
                "unavailabilities": [],
            }
        ],
        teams=[
            {
                "id": "t1",
                "name": "T1",
                "leagueWindows": [{"dayOfWeek": HOLIDAY_ISO_DAY, "kickoffMin": "18:00", "kickoffMax": "20:00"}],
                "habits": [],
                "coaches": [],
            }
        ],
    )

    assert result["status"] == "completed"
    assert result["unplaced"] == []
    kickoff = _kickoff(result, "m1")
    assert "18:00" <= kickoff <= "20:00", (
        f"un mercredi ordinaire reste cadré par la fenêtre du jour et la ligue, pas {kickoff}"
    )


# ── Ouverture datée d'un gymnase (contrat 1.9) ───────────────────────────────────────────────
# Promesse : « ce gymnase, ce jour-là, de telle heure à telle heure, accueille des matchs » s'AJOUTE
# aux plages habituelles de cette date, et n'ouvre rien d'autre. Rouge tant que le schéma
# (`extra="forbid"`) ne connaît pas `openingDate` et que `candidates.py` ne lit pas cette ligne.
# Angle voulu (point sensible matchs) : le jumeau de bout en bout est
# `backend/tests/CrossStack/MatchAccessEngineParityTest.php`, qui rejoue les cas partagés avec le front.

ORDINARY_WEDNESDAY = "2026-11-18"
ORDINARY_SATURDAY = "2026-11-21"


def _opening(day: str, start: str = "14:00", end: str = "20:00") -> dict[str, Any]:
    return {"openingDate": day, "start": start, "end": end}


def _venue(
    windows: list[dict[str, Any]], venue_id: str = "v1", unavailabilities: list[dict[str, Any]] | None = None
) -> dict[str, Any]:
    return {"id": venue_id, "name": venue_id, "matchWindows": windows, "unavailabilities": unavailabilities or []}


def _team(**over: Any) -> dict[str, Any]:
    return {"id": "t1", "name": "T1", "leagueWindows": [], "habits": [], "coaches": [], **over}


def _match(match_date: str = HOLIDAY, match_id: str = "m1") -> dict[str, Any]:
    return {"id": match_id, "teamId": "t1", "date": match_date, "kind": "TO_PLACE"}


def _reason(result: dict[str, Any], match_id: str = "m1") -> str:
    return str(next(u for u in result["unplaced"] if u["matchId"] == match_id)["reason"])


def test_opening_alone_places_the_match_of_that_date_and_leaves_the_ordinary_saturday_without_access() -> None:
    # A3 : le moteur reste strict. Club sans aucune ligne hebdo + 1 ouverture le 11 nov. : le match
    # du 11 nov. est posé sur l'ouverture ; celui du samedi reste `no_access_window` (le front et le
    # serveur, eux, le laissent libre à la main : l'adoption ne compte que les lignes non datées).
    result = _solve(
        matches=[_match(HOLIDAY, "m-holiday"), _match(ORDINARY_SATURDAY, "m-saturday")],
        venues=[_venue([_opening(HOLIDAY)])],
        teams=[_team()],
    )
    assert [p["matchId"] for p in result["placements"]] == ["m-holiday"]
    assert "14:00" <= _kickoff(result, "m-holiday") <= "18:15"
    assert _reason(result, "m-saturday") == "no_access_window"


def test_opening_adds_to_the_holiday_line_without_replacing_it() -> None:
    # D2 : plages du 11 nov. = ligne férié 10:00-12:00 ∪ ouverture 14:00-20:00. L'habitude choisit
    # une heure dans chacune : les deux doivent être atteignables.
    windows = [{"appliesOnPublicHolidays": True, "start": "10:00", "end": "12:00"}, _opening(HOLIDAY)]
    for habit_kickoff in ("10:00", "15:00"):
        result = _solve(
            matches=[_match()],
            venues=[_venue(windows)],
            teams=[_team(habits=[{"dayOfWeek": HOLIDAY_ISO_DAY, "kickoff": habit_kickoff, "venueId": "v1"}])],
        )
        assert _kickoff(result, "m1") == habit_kickoff


def test_opening_adds_to_the_weekday_line_on_an_ordinary_day() -> None:
    windows = [
        {"dayOfWeek": HOLIDAY_ISO_DAY, "start": "18:00", "end": "22:00"},
        _opening(ORDINARY_WEDNESDAY, "09:00", "12:00"),
    ]
    for habit_kickoff in ("09:30", "18:30"):
        result = _solve(
            matches=[_match(ORDINARY_WEDNESDAY)],
            venues=[_venue(windows)],
            teams=[_team(habits=[{"dayOfWeek": HOLIDAY_ISO_DAY, "kickoff": habit_kickoff, "venueId": "v1"}])],
        )
        assert _kickoff(result, "m1") == habit_kickoff


def test_opening_never_leaks_to_another_date_nor_to_another_venue() -> None:
    # Le 11 nov. n'ouvre pas le 18 nov. (même jour de semaine), ni le gymnase voisin.
    result = _solve(
        matches=[_match(ORDINARY_WEDNESDAY)],
        venues=[_venue([_opening(HOLIDAY)], "v1"), _venue([], "v2")],
        teams=[_team()],
    )
    assert result["placements"] == []
    assert _reason(result) == "no_access_window"

    placed = _solve(
        matches=[_match()],
        venues=[_venue([_opening(HOLIDAY)], "v1"), _venue([], "v2")],
        teams=[_team()],
    )
    assert placed["placements"][0]["venueId"] == "v1"


def test_opening_too_short_for_the_whole_match_does_not_place_it() -> None:
    # D4 : le moteur garde sa sécurité, le match ENTIER tient (105 min), pas seulement le coup d'envoi.
    result = _solve(matches=[_match()], venues=[_venue([_opening(HOLIDAY, "14:00", "15:00")])], teams=[_team()])
    assert result["placements"] == []
    assert _reason(result) == "no_access_window"


def test_a_closure_still_beats_an_opening() -> None:
    # « Les fermetures ne changent pas » : une ouverture ne rouvre jamais un gymnase fermé ce jour-là.
    closed = [{"startDate": HOLIDAY, "endDate": HOLIDAY}]
    result = _solve(matches=[_match()], venues=[_venue([_opening(HOLIDAY)], unavailabilities=closed)], teams=[_team()])
    assert result["placements"] == []
    assert _reason(result) == "venue_unavailable"


@pytest.mark.parametrize(
    "window",
    [
        {"openingDate": HOLIDAY, "dayOfWeek": 3, "start": "14:00", "end": "20:00"},
        {"openingDate": HOLIDAY, "appliesOnPublicHolidays": True, "start": "14:00", "end": "20:00"},
        {"dayOfWeek": 3, "appliesOnPublicHolidays": True, "start": "14:00", "end": "20:00"},
        {"start": "14:00", "end": "20:00"},
    ],
)
def test_a_window_targets_exactly_one_of_weekday_holidays_or_date(window: dict[str, Any]) -> None:
    with pytest.raises(ValidationError):
        MatchAccessWindowSchema.model_validate(window)


def test_a_dated_window_is_accepted_and_reads_its_date() -> None:
    assert MatchAccessWindowSchema.model_validate(_opening(HOLIDAY)).opening_date == date(2026, 11, 11)
