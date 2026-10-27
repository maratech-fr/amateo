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

from typing import Any

from app.main import read_contract_version
from app.schemas.match_input_schema import MatchPlacementInputSchema
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
