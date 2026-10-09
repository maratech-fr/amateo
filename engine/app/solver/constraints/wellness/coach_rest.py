"""Contrainte de bien-être — jour de repos du coach (sous-paquet P4-295 C6).

Extrait verbatim de l'ancien ``constraints/wellness.py`` (découpe pure). N'importe que les externes
``...compromise`` / ``..common`` ; aucune arête vers un autre sous-module. L'orchestrateur
``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ...compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ..common import (
    COACH_REST_VIOLATION_WEIGHT,
    HARD,
    PREFERRED,
    AssignmentVariable,
    BoolVarLike,
    _dedupe_variables,
    _get,
    _locked_person_day_intervals,
    _scalar_id,
)


def add_coach_rest_day_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    intensity: str = HARD,
    min_rest_days: int = 1,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3b: every coach must keep at least ``min_rest_days`` rest days Mon-Fri.

    For each coach, creates ``is_working[coach, day]`` BoolVars for days 1-5
    using reification, then (``intensity=HARD``) enforces
    ``sum(is_working) <= 5 - min_rest_days`` (at most ``5 - min_rest_days`` working
    days among Mon-Fri). The historical bound of 4 is exactly ``min_rest_days=1``.

    When ``intensity=PREFERRED`` the hard bound is NOT posted; instead ONE aggregated
    violation literal per coach — reifying ``sum(is_working) > 5 - min_rest_days`` — is
    appended to ``soft_terms_out`` for the objective to penalise.

    Both coaching assignments (via ``team_coach_map``) and coach-player playing
    assignments (via ``team_player_map``) count as working days. Falls back to
    assignment attributes when maps are not provided or team is not found.

    P4-97 — a HARD-locked session of the coach's team (coached OR played) makes that day a
    CONSTANT working day. Locked days leave the reification and CREDIT the bound: HARD caps
    the FREE days at ``5 - min_rest_days - locked_working_days``. This tightens the bound that
    used to be too lax (a coach half-locked no longer reads as over-resting) AND the
    aggregated PREFERRED literal now counts real violations instead of phantoms. ⚑ ALIGN-07 —
    a lock is sovereign: when the locks ALONE exceed the cap (``free_cap < 0``) the HARD bound
    is NOT posted, so a fully-locked coach never turns generation INFEASIBLE — the violation is
    left to the post-solve diagnostic (same discipline as 3c and the fully-locked 3d chain).
    PREFERRED still lights the literal in that case: the penalty is deserved, not phantom.
    """

    # Build coach_id -> max_days_override map
    coach_max_days: dict[str, int | None] = {}
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is None:
            continue
        coach_id_str = str(coach_id)
        max_days = _get(coach, "max_days_override", "maxDaysOverride", default=None)
        coach_max_days[coach_id_str] = int(max_days) if max_days is not None else None

    if not coach_max_days:
        return 0

    # Group assignment variables by (person_id, day) for days 1-5.
    # A person is "working" on a day if they coach or play on that day.
    person_day_vars: dict[tuple[str, int], list[BoolVarLike]] = defaultdict(list)

    for assignment in assignments:
        slot_id = assignment.slot_id
        if slot_id is None:
            continue
        day_str = str(slot_id).split(":")[0]
        try:
            day = int(day_str)
        except (TypeError, ValueError):
            continue
        if day < 1 or day > 5:
            continue

        team_id = assignment.team_id
        team_id_str = str(team_id) if team_id is not None else None

        # Coaching assignments — look up from team_coach_map
        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in coach_max_days:
                    person_day_vars[(coach_id, day)].append(assignment.var)
        else:
            coach_id = assignment.coach_id
            if coach_id is not None:
                coach_id_str = str(coach_id)
                if coach_id_str in coach_max_days:
                    person_day_vars[(coach_id_str, day)].append(assignment.var)

        # Playing assignments (coach as player) — look up from team_player_map
        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in coach_max_days:
                    person_day_vars[(player_id, day)].append(assignment.var)
        else:
            for player_id in assignment.player_ids:
                player_id_str = str(player_id)
                if player_id_str in coach_max_days:
                    person_day_vars[(player_id_str, day)].append(assignment.var)

    locked_person_days = _locked_person_day_intervals(model, team_coach_map, team_player_map)

    added = 0
    for coach_id_str in coach_max_days:
        # P4-51 — le skip « override ≤ 4 ⇒ repos déjà garanti » est MORT. Il reposait sur
        # une hypothèse fausse : le plafond n'était appliqué nulle part (il ne servait
        # qu'au diagnostic post-solve), donc régler « max 3 jours » RETIRAIT la garantie
        # de repos sans rien plafonner — l'inverse du libellé. Le plafond est désormais
        # un terme soft de l'objectif (`add_coach_day_cap_penalty`) ; la garantie d'un
        # jour de repos lun-ven, elle, vaut pour TOUS les coachs, sans exemption.

        # P4-97 — jours où une séance VERROUILLÉE de ce coach tombe : le coach travaille,
        # c'est une CONSTANTE. Ces jours sortent de la réification et entrent dans la borne
        # comme un nombre : la borne sur les jours LIBRES est créditée d'autant, et le compte
        # PREFERRED inclut les verrous. ⚑ ALIGN-07 (verrou souverain) : si les seuls verrous
        # dépassent déjà le plafond, on ne pose RIEN en HARD — la génération n'échoue pas, la
        # violation est laissée au diagnostic post-solve (même discipline que la 3d « chaîne
        # entièrement verrouillée » et la 3c). PREFERRED, lui, allume le littéral (violation
        # RÉELLE, pénalité méritée).
        locked_working_days = len(locked_person_days.get(coach_id_str, {}))
        locked_days_for_coach = set(locked_person_days.get(coach_id_str, {}))

        # Create is_working BoolVars for the FREE days 1-5 using reification (locked days are
        # constants, not variables).
        free_is_working_vars: list[BoolVarLike] = []
        for day in range(1, 6):
            if day in locked_days_for_coach:
                continue
            day_vars = _dedupe_variables(person_day_vars.get((coach_id_str, day), []))
            is_working = cast(Any, model).NewBoolVar(f"coach_rest_day_is_working_{coach_id_str}_day{day}")
            free_is_working_vars.append(is_working)
            # P4-99 — HORS mesure de cause : `is_working` est une var de réification (canal
            # `OnlyEnforceIf`), pas un candidat de séance ; rien n'est fermé inconditionnellement.
            # L'effet d'un plafond de repos non tenu tombe dans la famille « resté ouvert ».
            if not day_vars:
                # No assignments on this day => coach is definitely not working
                cast(Any, model).Add(is_working == 0)
            else:
                day_sum = sum(cast(Any, v) for v in day_vars)
                cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(is_working)
                cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(is_working.Not())

        working_cap = 5 - min_rest_days
        free_cap = working_cap - locked_working_days
        if intensity == PREFERRED:
            # Un littéral de violation AGRÉGÉ par coach : « travaille plus que le plafond »,
            # verrous inclus. free_cap < 0 ⇒ les verrous seuls dépassent ⇒ over forcé vrai.
            over = cast(Any, model).NewBoolVar(f"coach_rest_over_{coach_id_str}")
            cast(Any, model).Add(sum(free_is_working_vars) >= free_cap + 1).OnlyEnforceIf(over)
            cast(Any, model).Add(sum(free_is_working_vars) <= free_cap).OnlyEnforceIf(over.Not())
            if soft_terms_out is not None:
                soft_terms_out.append((over, COACH_REST_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=over,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "coach_rest", coach_id_str),
                        coach_id=coach_id_str,
                        detail="coach_rest",
                    )
                )
        elif free_cap >= 0:
            # HARD : au plus ``free_cap`` jours LIBRES travaillés (le reste après crédit des
            # verrous). free_cap < 0 → rien à poser (verrou souverain, cf. commentaire ci-dessus).
            cast(Any, model).Add(sum(free_is_working_vars) <= free_cap)
        added += 1

    return added
