"""L'affectation d'UNE date : chaque besoin a UN tenant parmi ses candidats, ou personne.

Miroir de ``App\\Service\\Conflicts\\DatedHolding`` (backend), cas partagés dans
``backend/tests/Fixtures/datedHolding.parity.json`` : changer la règle d'un seul côté rougit ce côté-là.

Les candidats arrivent déjà filtrés par l'appelant, sans repli. Une personne ne tient pas deux besoins
qui se chevauchent, ni un besoin qui chevauche un besoin où elle joue. Intervalles demi-ouverts.

Ordre stable : les matchs par coup d'envoi puis id, puis les séances par début puis id ; les candidats
dans l'ordre donné, « personne » en dernier ; la PREMIÈRE solution qui tient le plus de besoins gagne.
Au-delà de ``BUDGET`` pas, un glouton dans le même ordre prend le relais.

Côté moteur, seuls les matchs FIGÉS sont affectés (``fixed_person_windows``) : le backend a déjà affecté
les séances et envoie leurs tenants dans ``trainingOccupancies``, tenus ici pour pris.
"""

from __future__ import annotations

from collections.abc import Iterable
from dataclasses import dataclass, field
from datetime import date

from app.schemas.match_input_schema import MatchPlacementInputSchema, MatchSchema, MatchTeamSchema

from .geometry import _durations, _iso_day, _kick_in_unavailability, _minutes, _team_players

BUDGET = 10_000

# Au-delà, la recherche récursive heurterait la pile de Python : le glouton décide directement.
# Une journée de club n'en approche pas ; seul un payload hors norme passe par là.
_MAX_SEARCH_NEEDS = 400

PersonWindows = dict[tuple[str, date], list[tuple[int, int]]]


@dataclass(frozen=True)
class Need:
    id: str
    kind: str
    team_id: str
    start_minutes: int
    end_minutes: int
    candidates: list[str]
    players: list[str]


@dataclass(frozen=True)
class Assignment:
    holders: dict[str, str]
    unheld: set[str]
    steps: int


@dataclass
class _Search:
    needs: list[Need]
    overlaps: list[list[int]]
    current: dict[int, str] = field(default_factory=dict)
    best: dict[int, str] = field(default_factory=dict)
    best_count: int = -1
    steps: int = 0

    def can_hold(self, index: int, person: str, holders: dict[int, str]) -> bool:
        return not any(
            holders.get(other) == person or person in self.needs[other].players for other in self.overlaps[index]
        )

    def run(self, index: int, held: int) -> None:
        count = len(self.needs)
        # Le budget est dépassé, ou la solution déjà trouvée tient autant que ce qui reste possible.
        if self.steps > BUDGET or self.best_count == count or held + count - index <= self.best_count:
            return
        if index == count:
            self.best_count = held
            self.best = dict(self.current)
            return
        for candidate in self.needs[index].candidates:
            if self.can_hold(index, candidate, self.current):
                self.steps += 1
                self.current[index] = candidate
                self.run(index + 1, held + 1)
                del self.current[index]
        self.steps += 1
        self.run(index + 1, held)

    def greedy(self) -> dict[int, str]:
        holders: dict[int, str] = {}
        for index, need in enumerate(self.needs):
            for candidate in need.candidates:
                if self.can_hold(index, candidate, holders):
                    holders[index] = candidate
                    break
        return holders


def assign(needs: Iterable[Need]) -> Assignment:
    ordered = sorted(needs, key=lambda n: (0 if n.kind == "match" else 1, n.start_minutes, n.id))
    overlaps = [
        [
            j
            for j, other in enumerate(ordered)
            if i != j and need.start_minutes < other.end_minutes and other.start_minutes < need.end_minutes
        ]
        for i, need in enumerate(ordered)
    ]
    search = _Search(ordered, overlaps)
    if len(ordered) > _MAX_SEARCH_NEEDS:
        search.best = search.greedy()
    else:
        search.run(0, 0)
        if search.steps > BUDGET:
            search.best = search.greedy()

    holders = {need.id: search.best[i] for i, need in enumerate(ordered) if i in search.best}
    unheld = {need.id for i, need in enumerate(ordered) if i not in search.best}
    return Assignment(holders, unheld, search.steps)


def _mains(team: MatchTeamSchema) -> list[str]:
    return [ref.coach_id for ref in team.coaches if ref.role == "MAIN"]


def _people_with_a_match_to_place(
    input_data: MatchPlacementInputSchema, teams_by_id: dict[str, MatchTeamSchema]
) -> dict[date, set[str]]:
    busy: dict[date, set[str]] = {}
    for match in input_data.matches:
        team = teams_by_id.get(match.team_id)
        if match.kind == "TO_PLACE" and team is not None:
            busy.setdefault(match.match_date, set()).update(ref.coach_id for ref in team.coaches)
            busy[match.match_date].update(_team_players(team))
    return busy


def _fixed_need(
    input_data: MatchPlacementInputSchema,
    match: MatchSchema,
    team: MatchTeamSchema,
    occupancies: PersonWindows,
    busy: set[str],
) -> Need:
    """Le besoin d'un match figé : ses principaux, moins ceux qu'une séance retient déjà ou dont une plage
    du module Matchs contient le coup d'envoi. Ceux sans match à placer ce jour-là passent devant."""
    assert match.kickoff is not None  # nosec B101 -- l'appelant ne garde que les matchs ancrés
    match_min, _ = _durations(team)
    start = _minutes(match.kickoff)
    end = start + match_min

    def is_free(coach_id: str) -> bool:
        occupied = any(
            start < o_end and o_start < end for o_start, o_end in occupancies.get((coach_id, match.match_date), [])
        )
        unavailable = any(
            unav.coach_id == coach_id
            and _iso_day(match.match_date) in unav.days_of_week
            and _kick_in_unavailability(start, unav)
            for unav in input_data.coach_unavailabilities
        )
        return not occupied and not unavailable

    free = [coach_id for coach_id in _mains(team) if is_free(coach_id)]
    return Need(
        id=match.id,
        kind="match",
        team_id=team.id,
        start_minutes=start,
        end_minutes=end,
        candidates=[c for c in free if c not in busy] + [c for c in free if c in busy],
        players=_team_players(team),
    )


def fixed_person_windows(
    input_data: MatchPlacementInputSchema, teams_by_id: dict[str, MatchTeamSchema]
) -> PersonWindows:
    """Qui est pris, à quelle heure : les occupations reçues du backend, puis les matchs figés.

    Un match figé retient ses joueurs et ses adjoints, et UN seul principal : son tenant du jour. Personne
    pour le tenir : tous ses principaux, par prudence. Un extérieur ne projette toujours rien.
    """
    windows: PersonWindows = {}
    for occupancy in input_data.training_occupancies:
        windows.setdefault((occupancy.coach_id, occupancy.occupancy_date), []).append(
            (_minutes(occupancy.start), _minutes(occupancy.end))
        )
    occupancies: PersonWindows = {key: list(value) for key, value in windows.items()}

    busy_by_date = _people_with_a_match_to_place(input_data, teams_by_id)
    fixed_by_date: dict[date, list[tuple[MatchSchema, MatchTeamSchema]]] = {}
    for match in input_data.matches:
        team = teams_by_id.get(match.team_id)
        # `kickoff` est garanti par le schéma pour un match figé.
        if match.kind == "FIXED" and match.kickoff is not None and team is not None:
            fixed_by_date.setdefault(match.match_date, []).append((match, team))

    for day, fixed in fixed_by_date.items():
        busy = busy_by_date.get(day, set())
        assignment = assign(_fixed_need(input_data, match, team, occupancies, busy) for match, team in fixed)
        for match, team in fixed:
            assert match.kickoff is not None  # nosec B101 -- filtré ci-dessus
            match_min, _ = _durations(team)
            start = _minutes(match.kickoff)
            holder = assignment.holders.get(match.id)
            mains = _mains(team)
            persons = [
                *(ref.coach_id for ref in team.coaches if ref.role != "MAIN"),
                *(mains if holder is None else [holder]),
                *_team_players(team),
            ]
            for person_id in persons:
                windows.setdefault((person_id, day), []).append((start, start + match_min))
    return windows
