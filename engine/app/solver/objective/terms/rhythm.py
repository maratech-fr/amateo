"""Level-2 objective — repos après match et espacement des séances (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise`` et ``normalise``. Aucune arête vers un autre sous-module de ``terms`` ; l'agrégateur
``objective/__init__`` consomme."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Mapping
from typing import Any, cast

from ...compromise import FAMILY_MATCH_REST, FAMILY_SAME_DAY, FAMILY_SPACING, CompromiseTermInfo
from ...constraints.common import (
    TEAM_SAME_DAY_GAP_MINUTES,
    AssignmentInput,
    _extract_interval,
    _max_sessions_per_day_by_team,
    _normalise_assignments,
)
from ...model import DEFAULT_SESSION_MINUTES, _format_time, _time_to_minutes
from ..normalise import _get, _scalar_id

BoolVarLike = Any


def add_match_day_rest_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms rewarding a rest day AFTER a team's match day.

    Implicit rule (no UI constraint): for a team playing on match_day m, the day
    after (m mod 7 + 1) should be left free of training. Nominal case: matches
    Sat/Sun, training Mon-Fri — a Saturday match's rest day (Sunday) simply has
    no slots (no-op); a SUNDAY match makes Monday the rest day, gently avoided.

    Reified per team/week: rest_ok is true iff no session lands on the rest day.
    Safety: placing a session is worth its tier weight + session_count (20) — at
    least 21 — while the rest bonus is only ``rest`` (3), so the solver never
    drops or moves a real placement to collect it; it only breaks ties. No term
    is emitted when the team has no slot that day (avoids a constant bonus that
    would inflate the score).
    """

    if "rest" not in weights:
        raise KeyError("rest")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except TypeError, ValueError:
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        match_day = _get(team, "match_day", "matchDay", default=None)
        if team_id is None or match_day is None:
            continue
        try:
            match_day_int = int(_scalar_id(match_day))
        except TypeError, ValueError:
            continue

        rest_day = match_day_int % 7 + 1
        rest_day_vars = team_day_vars.get(str(team_id), {}).get(rest_day, [])
        if not rest_day_vars:
            continue

        rest_ok = model.NewBoolVar(f"rest_ok_{team_id}")
        model.Add(sum(rest_day_vars) == 0).OnlyEnforceIf(rest_ok)
        model.Add(sum(rest_day_vars) >= 1).OnlyEnforceIf(rest_ok.Not())
        soft_terms.append((rest_ok, "rest"))
        if info_out is not None:
            info_out.append(
                CompromiseTermInfo(
                    var=rest_ok,
                    family=FAMILY_MATCH_REST,
                    honored_when_active=True,
                    key=(FAMILY_MATCH_REST, str(team_id)),
                    team_id=str(team_id),
                )
            )

    return soft_terms


def add_spacing_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Implicit soft rule (ALIGN-06): gently discourage a team training on two
    CONSECUTIVE days (spacing). Malus only — never blocks feasibility; the low
    weight means it only breaks ties, never moves a real placement."""
    if "spacing" not in weights:
        raise KeyError("spacing")

    team_day_vars: dict[str, dict[int, list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = _scalar_id(slot_key[0])
        try:
            day = int(_scalar_id(slot_key[2]))
        except TypeError, ValueError:
            continue
        team_day_vars.setdefault(str(team_id), {}).setdefault(day, []).append(variable)

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for team in teams:
        team_id = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if team_id is None:
            continue
        days = team_day_vars.get(str(team_id), {})
        # One reified "team trains on day D" bool per day, reused across both
        # adjacent pairs (D is the right end of (D-1,D) and the left end of
        # (D,D+1)) — building it per-pair doubled the vars/constraints (C8).
        present: dict[int, BoolVarLike] = {}
        for day in sorted(days):
            if day + 1 not in days:
                continue
            for d in (day, day + 1):
                if d not in present:
                    has_d = model.NewBoolVar(f"has_{team_id}_{d}")
                    model.Add(sum(days[d]) >= 1).OnlyEnforceIf(has_d)
                    model.Add(sum(days[d]) == 0).OnlyEnforceIf(has_d.Not())
                    present[d] = has_d
            both = model.NewBoolVar(f"consec_{team_id}_{day}")
            model.AddBoolAnd([present[day], present[day + 1]]).OnlyEnforceIf(both)
            model.AddBoolOr([present[day].Not(), present[day + 1].Not()]).OnlyEnforceIf(both.Not())
            soft_terms.append((both, "spacing"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=both,
                        family=FAMILY_SPACING,
                        honored_when_active=False,
                        key=(FAMILY_SPACING, str(team_id)),
                        team_id=str(team_id),
                    )
                )

    return soft_terms


def _lock_entries(model: Any, capped: set[str]) -> dict[tuple[str, int], list[tuple[int, int]]]:
    """``(équipe, jour) -> [(début, fin)]`` des verrous HARD des équipes à plafond ≥ 2 (sans
    variable : réservation, épingle ou verrou posé par « Charger », un seul état pour le moteur)."""
    locks: dict[tuple[str, int], list[tuple[int, int]]] = defaultdict(list)
    for locked in getattr(model, "locked_slots", ()) or ():
        team_id = str(_get(locked, "team_id", "teamId", default="") or "")
        if team_id not in capped:
            continue
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_raw = _get(locked, "start_time", "startTime", default=None)
        if day_raw is None or start_raw is None:
            continue
        try:
            day = int(day_raw)
        except TypeError, ValueError:
            continue
        start = _time_to_minutes(start_raw)
        duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        locks[(team_id, day)].append((start, start + duration))
    return locks


def add_same_day_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    locked_team_days: Mapping[str, Mapping[int, int]],
    season_reference_keys: frozenset[tuple[str, int, str]] = frozenset(),
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """PR 1a — « même jour » : −10 par séance comptée au-delà de la première d'un jour, pour une
    équipe à plafond ≥ 2 (plafond 1 : aucun terme, modèle inchangé).

    Compte facturé d'un jour = (séances libres hors horaires de saison) − 1 + ancre, plancher 0.
    L'ancre vaut 1 si l'équipe a un verrou ce jour-là ; sinon, s'il existe des créneaux libres à des
    horaires de saison ce jour-là, un booléen ``ancre ≥ variable`` pour chacun d'eux ; sinon 0. Un
    verrou et un créneau de saison gardé situent donc le rang sans coûter : deux verrous 0, deux
    créneaux de saison 0, un créneau de saison + une séance nouvelle 10, deux séances libres 10.
    « Horaire de saison » = clé ``(équipe, jour, « HH:MM »)`` d'une référence ``keep``
    (``season_reference_keys``) ; une référence de comblement n'exempte rien.

    Un littéral par rang (patron ``add_coach_day_cap_penalty``), borné par le bas seulement : la
    maximisation le garde à 0 quand rien ne l'impose. Rangs 1 à plafond − 1 : la couche dure ne
    laisse jamais plus de ``plafond`` séances un même jour en génération."""
    if "same_day" not in weights:
        raise KeyError("same_day")

    caps = _max_sessions_per_day_by_team(teams)
    capped = {team_id for team_id, cap in caps.items() if cap >= 2}
    soft_terms: list[tuple[BoolVarLike, str]] = []
    if not capped:
        return soft_terms

    # (équipe, jour) -> ([variables hors horaire de saison], [variables à un horaire de saison])
    by_team_day: dict[tuple[str, int], tuple[list[BoolVarLike], list[BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        team_id = str(_scalar_id(slot_key[0]))
        if team_id not in capped:
            continue
        try:
            day = int(_scalar_id(slot_key[2]))
        except TypeError, ValueError:
            continue
        others, seasons = by_team_day.setdefault((team_id, day), ([], []))
        if (team_id, day, str(slot_key[3])) in season_reference_keys:
            seasons.append(variable)
        else:
            others.append(variable)

    for (team_id, day), (others, seasons) in sorted(by_team_day.items()):
        if not others:
            # Aucune séance nouvelle possible ce jour-là : rien ne peut être facturé.
            continue
        anchor: Any = 0
        if locked_team_days.get(team_id, {}).get(day, 0) >= 1:
            anchor = 1
        elif seasons:
            anchor_var = model.NewBoolVar(f"same_day_anchor_{team_id}_{day}")
            for variable in seasons:
                model.AddImplication(variable, anchor_var)
            anchor = anchor_var
        total = sum(cast(Any, v) for v in others) + anchor - 1
        max_rank = min(caps[team_id] - 1, len(others) - 1 + (0 if isinstance(anchor, int) and anchor == 0 else 1))
        for rank in range(1, max_rank + 1):
            literal = model.NewBoolVar(f"same_day_{team_id}_{day}_{rank}")
            model.Add(total <= rank - 1).OnlyEnforceIf(literal.Not())
            soft_terms.append((literal, "same_day"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=literal,
                        family=FAMILY_SAME_DAY,
                        honored_when_active=False,
                        key=(FAMILY_SAME_DAY, team_id, day),
                        team_id=team_id,
                        day_of_week=day,
                    )
                )

    return soft_terms


def add_same_day_gap_penalty(
    model: Any,
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike],
    teams: Iterable[Any],
    weights: Mapping[str, int],
    *,
    gap_minutes: int = TEAM_SAME_DAY_GAP_MINUTES,
    season_reference_keys: frozenset[tuple[str, int, str]] = frozenset(),
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """PR 1a — « moins de 2 h d'écart » : −50 par séance d'une équipe à plafond ≥ 2 commencée moins
    de ``gap_minutes`` après la fin d'une autre de ses séances du même jour (tous gymnases).

    Un littéral par (équipe, jour, heure de début h), h parcourant les débuts des créneaux libres
    ET des verrous de l'équipe ce jour-là. Le littéral est vrai dès que l'équipe commence une séance
    à h alors qu'une autre de ses séances du jour, commencée avant, a fini depuis moins de 2 h :
    ``M·D(h) + V(h) ≤ M·(1 + littéral)``, D(h) = séances qui commencent à h (variables libres, ou 1
    si un verrou y commence), V(h) = séances voisines, M = le plus grand V(h) possible (même logique
    que ``(P−1)·D + V ≤ (P−1)·(1 + littéral)``, sans infaisabilité quand des verrous dépassent le
    plafond). Borné par le bas seulement : la maximisation le garde à 0 quand rien ne l'impose.

    * h libre : V compte les variables libres voisines et les verrous voisins (constantes).
    * h = début d'un verrou : V ne compte que les variables libres voisines ; aucune ⇒ aucun
      littéral. Verrou × verrou n'est jamais compté : deux réservations collées sont honorées en
      silence. Une séance libre juste avant OU juste après un verrou coûte 50.
    * Exemption de saison : si (équipe, jour, h) est un horaire de saison (référence ``keep``),
      V ne compte pas les voisines, libres ou verrouillées, dont l'heure de début est aussi un
      horaire de saison — une paire gardée du planning de saison ne coûte rien. L'exemption lit
      l'heure de DÉBUT, pas la durée : en période, un créneau de saison allongé peut se coller à
      l'autre (17:00-19:00 et 20:30-22:00), il reste exempté. Un verrou à un horaire de saison
      compte comme un créneau de saison (même normalisation « HH:MM » que la clé de référence).
    * Le coût est le même à 0 ou à 90 minutes d'écart.

    Exception à la parité génération ⇄ geste, voulue : le calcul des compromis d'un geste ne reçoit
    pas les références de saison (``season_reference_keys`` vide) — rapprocher ou éloigner deux
    séances de saison y est nommé comme pour n'importe quelle paire. Jamais dans la couche dure : un
    déplacement à la main n'est jamais refusé pour l'écart."""
    if "same_day_gap" not in weights:
        raise KeyError("same_day_gap")

    caps = _max_sessions_per_day_by_team(teams)
    capped = {team_id for team_id, cap in caps.items() if cap >= 2}
    soft_terms: list[tuple[BoolVarLike, str]] = []
    if not capped:
        return soft_terms

    # (équipe, jour) -> [(début, fin, variable)] des créneaux libres.
    free: dict[tuple[str, int], list[tuple[int, int, BoolVarLike]]] = defaultdict(list)
    for assignment in _normalise_assignments(assignments):
        team_id = assignment.team_id
        if team_id is None or str(team_id) not in capped:
            continue
        start, end, raw_day = _extract_interval(assignment)
        if start is None or end is None or raw_day is None:
            continue
        try:
            day_int = int(raw_day)
        except TypeError, ValueError:
            continue
        free[(str(team_id), day_int)].append((start, end, assignment.var))
    locks = _lock_entries(model, capped)

    for team_day in sorted(set(free) | set(locks)):
        team_id, day = team_day
        free_entries = free.get(team_day, [])
        if not free_entries:
            # Verrou × verrou seulement : jamais compté.
            continue
        lock_entries = locks.get(team_day, [])

        def _season(start: int, team: str = team_id, weekday: int = day) -> bool:
            return (team, weekday, _format_time(start)) in season_reference_keys

        lock_starts = {start for start, _end in lock_entries}
        hours = sorted({start for start, _end, _var in free_entries} | lock_starts)
        for hour in hours:
            exempt = _season(hour)

            def _neighbour(start: int, end: int, h: int = hour, skip_season: bool = exempt) -> bool:
                if not (start < h and end > h - gap_minutes):
                    return False
                return not (skip_season and _season(start))

            neighbour_vars = [var for start, end, var in free_entries if _neighbour(start, end)]
            if hour in lock_starts:
                # h est le début d'un verrou : D(h) = 1, V(h) = les seules variables libres voisines.
                if not neighbour_vars:
                    continue
                starts_here: Any = 1
                neighbour_locks = 0
            else:
                starts_here = sum(cast(Any, var) for start, _end, var in free_entries if start == hour)
                neighbour_locks = sum(1 for start, end in lock_entries if _neighbour(start, end))
                if not neighbour_vars and neighbour_locks == 0:
                    continue
            big_m = len(neighbour_vars) + neighbour_locks
            literal = model.NewBoolVar(f"same_day_gap_{team_id}_{day}_{_format_time(hour)}")
            model.Add(
                big_m * starts_here + sum(cast(Any, var) for var in neighbour_vars) + neighbour_locks
                <= big_m + big_m * literal
            )
            soft_terms.append((literal, "same_day_gap"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=literal,
                        family=FAMILY_SAME_DAY,
                        honored_when_active=False,
                        key=(FAMILY_SAME_DAY, "gap", team_id, day),
                        team_id=team_id,
                        day_of_week=day,
                        detail="gap",
                    )
                )

    return soft_terms
