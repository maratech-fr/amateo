"""Contrainte de bien-être — pas de créneaux dos-à-dos (chaînes) pour une PERSONNE (sous-paquet P4-295 C6).

⚠ ALIGN-08 — voisine par le nom de ``consecutive_days.add_max_consecutive_days_constraints``,
étrangère par le sujet : celle-ci interdit à une PERSONNE d'enchaîner des CRÉNEAUX dos-à-dos dans
une journée, l'autre à une ÉQUIPE de s'entraîner N JOURS de suite.

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``...compromise`` / ``..common`` ; le détecteur ``_find_consecutive_chains`` est son propre voisin
de module (aucune arête vers un autre sous-module). L'orchestrateur ``add_level_1_hard_constraints``
du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ...compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ..common import (
    CHAIN_VIOLATION_WEIGHT,
    HARD,
    PREFERRED,
    AssignmentVariable,
    BoolVarLike,
    _dedupe_variables,
    _get,
    _locked_person_day_intervals,
    _scalar_id,
    _to_day_int,
)


def add_max_consecutive_sessions_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    intensity: str = HARD,
    max_consecutive: int = 3,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3d: a person may not be in ``max_consecutive`` back-to-back slots.

    Uses a single **cross-venue** grouping strategy: for each
    ``(person_id, day)``, collects all assignments across all venues where
    the person appears (coach via ``team_coach_map`` or player via
    ``team_player_map``).  Detects back-to-back chains of length ``max_consecutive``
    (each slot's end == the next slot's start) and (``intensity=HARD``) adds
    ``sum(chain) <= max_consecutive - 1`` for each chain. The default ``max_consecutive=3``
    reproduces the historical rule (« jamais 3 dos-à-dos », ``sum(triple) <= 2``); a
    value of 4 permits the triple but forbids the quadruple.

    When ``intensity=PREFERRED`` the hard bound is NOT posted; instead ONE aggregated
    violation literal per ``(person, day)`` — the OR of that day's forbidden chains being
    fully selected — is appended to ``soft_terms_out`` for the objective.

    Cross-venue grouping is sufficient on its own: a same-venue triple is
    just a cross-venue triple where all three slots happen to share a
    venue, so it is already detected by the ``(person_id, day)`` grouping.
    The previous same-venue ``(venue_id, day)`` loop was redundant and is
    removed for performance — on the BCCL payload (~2793 assignments,
    ~196 entries per venue-day) the O(n^3) triple search per venue-day
    made constraint building exceed the 30s test timeout.

    Coaches and players are looked up from ``team_coach_map`` and
    ``team_player_map`` when available, falling back to assignment attributes.

    P4-97 — HARD-locked sessions of the person enter the chain search as CONSTANT
    intervals (no model variable). A chain of ``max_consecutive`` slots with ``k`` locked
    and ``N-k`` free yields ``sum(free) <= max_consecutive - 1 - k`` (HARD): dropping one
    free slot is enough to break it. A fully-locked chain (``k == max_consecutive``) posts
    nothing — the post-solve detection already diagnoses it.
    """

    coach_ids: set[str] = set()
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is not None:
            coach_ids.add(str(coach_id))

    if not coach_ids:
        return 0

    # Deduplicate by variable so a person who is both coach and player on the
    # same team does not get duplicate entries that could mask real triples.
    # A ``None`` assignment marks a HARD-locked constant slot (no variable, P4-97).
    person_day_entries: dict[tuple[str, str], dict[Any, tuple[int, int, AssignmentVariable | None]]] = defaultdict(dict)

    for assignment in assignments:
        slot_id = assignment.slot_id
        if slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) < 2:
            continue
        day = parts[0]

        start = assignment.start
        end = assignment.end
        if start is None or end is None:
            continue

        start_minutes = int(start) if not isinstance(start, int) else start
        end_minutes = int(end) if not isinstance(end, int) else end

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        person_ids: set[str] = set()
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for cid in team_coach_map[team_id_str]:
                if cid in coach_ids:
                    person_ids.add(cid)
        else:
            single_cid = assignment.coach_id
            if single_cid is not None and str(single_cid) in coach_ids:
                person_ids.add(str(single_cid))

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for pid in team_player_map[team_id_str]:
                if pid in coach_ids:
                    person_ids.add(pid)
        else:
            for pid in assignment.player_ids:
                if str(pid) in coach_ids:
                    person_ids.add(str(pid))

        var = assignment.var
        var_key = var.Index() if hasattr(var, "Index") else id(var)
        for person_id in person_ids:
            person_day_entries[(person_id, day)][var_key] = (start_minutes, end_minutes, assignment)

    # P4-97 — séances VERROUILLÉES en intervalles CONSTANTS (aucune variable) : elles
    # entrent dans la recherche de chaînes avec ``None`` en 3ᵉ position.
    locked_person_days = _locked_person_day_intervals(model, team_coach_map, team_player_map)
    for person_id, day_intervals in locked_person_days.items():
        if person_id not in coach_ids:
            continue
        for day_int, intervals in day_intervals.items():
            day = str(day_int)
            for start_m, end_m in intervals:
                person_day_entries[(person_id, day)][f"locked:{start_m}:{end_m}"] = (start_m, end_m, None)

    added = 0

    # --- Cross-venue grouping by (person_id, day) — BUG-3 fix ---
    for (person_id, day), entries_dict in person_day_entries.items():
        slot_entries = list(entries_dict.values())
        chain_active_literals: list[BoolVarLike] = []
        for chain in _find_consecutive_chains(slot_entries, max_consecutive):
            deduped = _dedupe_variables([entry[2].var for entry in chain if entry[2] is not None])
            locked_count = len(chain) - len(deduped)
            if len(deduped) + locked_count < max_consecutive:
                continue
            if locked_count >= max_consecutive:
                # Chaîne entièrement verrouillée : rien à décider — la détection post-solve
                # la diagnostique déjà.
                continue
            if intensity == PREFERRED:
                # Réifie « tous les créneaux LIBRES de la chaîne sont sélectionnés » (les
                # verrouillés sont présents par construction) ; l'OR des chaînes du jour
                # devient le littéral de violation agrégé (person, day).
                free_count = len(deduped)
                active = cast(Any, model).NewBoolVar(f"chain_active_{person_id}_{day}_{len(chain_active_literals)}")
                cast(Any, model).Add(sum(deduped) >= free_count).OnlyEnforceIf(active)
                cast(Any, model).Add(sum(deduped) <= free_count - 1).OnlyEnforceIf(active.Not())
                chain_active_literals.append(active)
            else:
                cast(Any, model).Add(sum(deduped) <= max_consecutive - 1 - locked_count)
                added += 1

        if intensity == PREFERRED and chain_active_literals:
            day_violated = cast(Any, model).NewBoolVar(f"chain_violated_{person_id}_{day}")
            # OR : le jour est en violation dès qu'une chaîne interdite est complète.
            for active in chain_active_literals:
                cast(Any, model).Add(day_violated >= active)
            cast(Any, model).Add(day_violated <= sum(chain_active_literals))
            if soft_terms_out is not None:
                soft_terms_out.append((day_violated, CHAIN_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=day_violated,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "chain", str(person_id), day),
                        coach_id=str(person_id),
                        day_of_week=_to_day_int(day),
                        detail="chain",
                    )
                )
            added += 1

    return added


def _find_consecutive_chains(
    entries: list[tuple[int, int, AssignmentVariable | None]],
    length: int,
) -> list[tuple[tuple[int, int, AssignmentVariable | None], ...]]:
    """Find back-to-back chains of exactly ``length`` slots where each slot's end
    equals the next slot's start (A.end == B.start == …).

    Uses a start-time index so that multiple entries sharing the same start
    (e.g. the same slot at different venues) are all considered as candidates.
    ``length=3`` reproduces the historical triple search. ``length<2`` yields nothing
    meaningful, so a floor of 2 is applied.

    An entry's 3ʳᵈ element is the assignment variable, or ``None`` for a HARD-locked
    constant slot (P4-97): chain detection cares only about the ``(start, end)`` pair, so
    locked and free slots chain identically.
    """
    length = max(2, length)
    by_start: dict[int, list[tuple[int, int, AssignmentVariable | None]]] = defaultdict(list)
    for entry in entries:
        by_start[entry[0]].append(entry)

    chains: list[tuple[tuple[int, int, AssignmentVariable | None], ...]] = []

    def _extend(chain: tuple[tuple[int, int, AssignmentVariable | None], ...]) -> None:
        if len(chain) == length:
            chains.append(chain)
            return
        last = chain[-1]
        for nxt in by_start.get(last[1], []):
            if any(nxt is member for member in chain):
                continue
            _extend((*chain, nxt))

    for start in entries:
        _extend((start,))
    return chains
