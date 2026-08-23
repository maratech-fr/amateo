"""Well-being constraint families: coach rest, salarié spread, back-to-back caps, age order.

Imports ``..`` externals and ``.common``. Called by ``structural``'s
``add_level_1_hard_constraints`` orchestrator; imports no other sibling."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Sequence
from typing import Any, cast

from ..compromise import FAMILY_IMPLICIT, CompromiseTermInfo
from ..model import _time_to_minutes
from .common import (
    AGE_VIOLATION_WEIGHT,
    CHAIN_VIOLATION_WEIGHT,
    COACH_REST_VIOLATION_WEIGHT,
    CONSECUTIVE_DAYS_VIOLATION_WEIGHT,
    HARD,
    OFF,
    PREFERRED,
    SALARIE_VIOLATION_WEIGHT,
    AssignmentVariable,
    BoolVarLike,
    _dedupe_variables,
    _get,
    _locked_person_day_intervals,
    _locked_team_days,
    _record_closure,
    _scalar_id,
    _to_day_int,
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


def add_salarie_distribution_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_coach_map: dict[str, list[str]] | None = None,
    team_player_map: dict[str, list[str]] | None = None,
    intensity: str = HARD,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3c: at least one salarié coach must be present each Mon-Fri day.

    A salarié is a coach with ``isEmployee=True``. For each day 1-5 (Mon-Fri),
    creates a ``day_has_salarie[d]`` BoolVar with reification and (``intensity=HARD``)
    enforces ``day_has_salarie[d] == 1``. When ``intensity=PREFERRED`` the ``== 1`` is
    NOT posted; instead ONE aggregated violation literal per working day (Mon-Fri) —
    ``day_has_salarie[d] == 0`` — is appended to ``soft_terms_out`` for the objective.

    Both coaching assignments (via ``team_coach_map``) and coach-player playing
    assignments (via ``team_player_map``) count as being present. Falls back to
    assignment attributes when maps are not provided.

    P4-97 — a day on which a salarié has a HARD-locked session (coached OR played) is a
    CONSTANT « salarié present » day: ``day_has_salarie[d]`` is forced to 1. In HARD this
    removes the phantom INFEASIBLE of a schedule whose salarié sessions are all locked; in
    PREFERRED it removes the phantom violation literals (a day truly without any salarié
    still lights its literal).

    Skipped if there are fewer than 2 salarié coaches.
    """

    salarie_ids: set[str] = set()
    for coach in coaches:
        coach_id = _scalar_id(_get(coach, "id", "coach_id", default=None))
        if coach_id is None:
            continue
        is_employee = _get(coach, "isEmployee", "is_employee", default=False)
        if is_employee:
            salarie_ids.add(str(coach_id))

    if len(salarie_ids) < 2:
        return 0

    day_vars: dict[int, list[BoolVarLike]] = defaultdict(list)

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

        if team_coach_map is not None and team_id_str is not None and team_id_str in team_coach_map:
            for coach_id in team_coach_map[team_id_str]:
                if coach_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            coach_id = assignment.coach_id
            if coach_id is not None and str(coach_id) in salarie_ids:
                day_vars[day].append(assignment.var)

        if team_player_map is not None and team_id_str is not None and team_id_str in team_player_map:
            for player_id in team_player_map[team_id_str]:
                if player_id in salarie_ids:
                    day_vars[day].append(assignment.var)
        else:
            for player_id in assignment.player_ids:
                if str(player_id) in salarie_ids:
                    day_vars[day].append(assignment.var)

    # P4-97 — jours ouvrés où un salarié a une séance VERROUILLÉE : présence constante.
    locked_person_days = _locked_person_day_intervals(model, team_coach_map, team_player_map)
    locked_salarie_days: set[int] = set()
    for salarie_id in salarie_ids:
        locked_salarie_days.update(locked_person_days.get(salarie_id, {}))

    added = 0
    for day in range(1, 6):
        day_has_salarie = cast(Any, model).NewBoolVar(f"day_has_salarie_day{day}")

        # P4-99 — HORS mesure de cause : `day_has_salarie` est une var de réification agrégée
        # (canal `OnlyEnforceIf`), pas un candidat de séance ; aucune fermeture inconditionnelle.
        if day in locked_salarie_days:
            # Un salarié encadre/joue ce jour-là par un verrou → présence constante.
            cast(Any, model).Add(day_has_salarie == 1)
        else:
            day_assignments = _dedupe_variables(day_vars.get(day, []))
            if not day_assignments:
                cast(Any, model).Add(day_has_salarie == 0)
            else:
                day_sum = sum(cast(Any, v) for v in day_assignments)
                cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(day_has_salarie)
                cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(day_has_salarie.Not())

        if intensity == PREFERRED:
            # Un littéral de violation par jour ouvré : « aucun salarié ce jour-là ».
            if soft_terms_out is not None:
                soft_terms_out.append((day_has_salarie.Not(), SALARIE_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=day_has_salarie.Not(),
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "salarie", day),
                        day_of_week=day,
                        detail="salarie",
                    )
                )
        else:
            cast(Any, model).Add(day_has_salarie == 1)
        added += 1

    return added


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


def add_max_consecutive_days_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    intensity: str = HARD,
    max_consecutive_days: int = 3,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Constraint 3e (P2-42): a TEAM never trains ``max_consecutive_days`` days in a row.

    ⚠ Ne pas confondre avec :func:`add_max_consecutive_sessions_constraints`, dont le nom
    est presque le même : celle-là interdit à une PERSONNE d'enchaîner des créneaux
    dos-à-dos DANS UNE JOURNÉE ; celle-ci interdit à une ÉQUIPE de s'entraîner N JOURS de
    suite. L'audit ALIGN-08 a montré qu'on pouvait croire ce besoin couvert en lisant le
    seul nom de l'autre — d'où cet avertissement aux deux endroits.

    Un littéral ``trains[team][day]`` est réifié comme le OR des affectations de l'équipe
    ce jour-là (une équipe peut avoir plusieurs séances le même jour : c'est UN jour
    d'entraînement, pas deux). Puis, pour chaque fenêtre de ``max_consecutive_days`` jours
    consécutifs :

    * ``HARD`` : ``sum(fenêtre) <= max_consecutive_days - 1`` — la suite est impossible ;
    * ``PREFERRED`` : un littéral de violation par fenêtre (le ET de ses jours) part en
      ``soft_terms_out`` à −6, comme ses quatre sœurs.

    **Absente du payload, la règle ne s'applique PAS** (``intensity=OFF``) — au contraire de
    ses quatre sœurs, qui retombent sur HARD par héritage historique. Elle est neuve : la
    faire naître dure changerait le planning de tous les clubs existants sans qu'ils aient
    rien demandé.

    **La semaine ne boucle pas** : dimanche→lundi n'est pas une suite. Le planning est
    hebdomadaire et se relit semaine par semaine ; faire boucler produirait des refus que
    personne ne saurait expliquer. Le week-end, lui, compte comme n'importe quel jour.
    """
    if intensity == OFF or max_consecutive_days < 2:
        return 0

    team_days: dict[str, dict[int, list[BoolVarLike]]] = {}
    for assignment in assignments:
        team_id = assignment.team_id
        slot_id = assignment.slot_id
        if team_id is None or slot_id is None:
            continue
        parts = str(slot_id).split(":", 1)
        if len(parts) < 2:
            continue
        day = _to_day_int(parts[0])
        if day is None:
            continue
        team_days.setdefault(str(team_id), {}).setdefault(day, []).append(assignment.var)

    added = 0
    for team_id, by_day in team_days.items():
        trains: dict[int, BoolVarLike] = {}
        for day, day_vars in by_day.items():
            if len(day_vars) == 1:
                trains[day] = day_vars[0]
                continue
            # Plusieurs séances le même jour = UN jour d'entraînement : OR réifié.
            flag = cast(Any, model).NewBoolVar(f"trains_{team_id}_{day}")
            cast(Any, model).AddMaxEquality(flag, day_vars)
            trains[day] = flag

        for first_day in sorted(trains):
            window = [trains[d] for d in range(first_day, first_day + max_consecutive_days) if d in trains]
            if len(window) < max_consecutive_days:
                continue  # la fenêtre n'est pas entièrement candidate : rien à interdire
            if intensity == HARD:
                cast(Any, model).Add(sum(window) <= max_consecutive_days - 1)
                added += 1
                continue

            violated = cast(Any, model).NewBoolVar(f"consecutive_days_{team_id}_{first_day}")
            # ET : la fenêtre n'est en violation que si TOUS ses jours sont retenus.
            cast(Any, model).AddMinEquality(violated, window)
            if soft_terms_out is not None:
                soft_terms_out.append((violated, CONSECUTIVE_DAYS_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=violated,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "consecutive_days", team_id, str(first_day)),
                        team_id=team_id,
                        day_of_week=first_day,
                        detail="consecutive_days",
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


def add_one_session_per_day_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
) -> int:
    """Implicit rule 11: a team can have at most one training session per day."""

    groups: dict[tuple[str, str], list[BoolVarLike]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        slot_id = assignment.slot_id
        if team_id is None or slot_id is None:
            continue
        day = str(slot_id).split(":")[0]
        groups[(str(team_id), day)].append(assignment.var)

    sessions_per_week: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        spw = _get(team, "sessionsPerWeek", "sessions_per_week", default=1)
        sessions_per_week[str(tid)] = max(1, int(spw))

    days_by_team: dict[str, list[tuple[str, list[BoolVarLike]]]] = defaultdict(list)
    for (team_id, day), vars_list in groups.items():
        days_by_team[team_id].append((day, vars_list))

    # P4-97 bis — le second CAS RÉEL (BCCL) : une équipe a un jeudi VERROUILLÉ et le solveur
    # lui ajoutait une séance LIBRE ce même jeudi. Le jour verrouillé EST déjà la séance du
    # jour ; il crédite le budget hebdomadaire et interdit tout créneau libre ce jour-là.
    locked_team_days = _locked_team_days(model)

    added = 0
    for team_id, day_entries in days_by_team.items():
        spw = sessions_per_week.get(team_id, 1)
        locked_days_for_team = locked_team_days.get(team_id, {})

        if not locked_days_for_team:
            # Chemin historique — byte-identique en l'absence de verrou.
            if len(day_entries) <= 1:
                continue
            day_active_vars: list[BoolVarLike] = []
            for _day, vars_list in day_entries:
                day_active = cast(Any, model).NewBoolVar(f"day_active_{team_id}_{_day}")
                day_active_vars.append(day_active)
                slot_sum = sum(cast(Any, v) for v in vars_list)
                cast(Any, model).Add(slot_sum >= 1).OnlyEnforceIf(day_active)
                cast(Any, model).Add(slot_sum == 0).OnlyEnforceIf(day_active.Not())
            cast(Any, model).Add(sum(day_active_vars) <= spw)
            added += 1
            continue

        # Jours verrouillés = jours travaillés CONSTANTS : le budget hebdomadaire libre est
        # ``spw - nb_jours_verrouillés`` (plancher 0 — verrou souverain, jamais d'infaisable).
        free_day_entries = [(d, v) for (d, v) in day_entries if _to_day_int(d) not in locked_days_for_team]
        free_day_active_vars: list[BoolVarLike] = []
        for _day, vars_list in free_day_entries:
            day_active = cast(Any, model).NewBoolVar(f"day_active_{team_id}_{_day}")
            free_day_active_vars.append(day_active)
            slot_sum = sum(cast(Any, v) for v in vars_list)
            cast(Any, model).Add(slot_sum >= 1).OnlyEnforceIf(day_active)
            cast(Any, model).Add(slot_sum == 0).OnlyEnforceIf(day_active.Not())
        if free_day_active_vars:
            cast(Any, model).Add(sum(free_day_active_vars) <= max(0, spw - len(locked_days_for_team)))
            added += 1

    for (team_id, day), vars_list in groups.items():
        deduped = _dedupe_variables(vars_list)
        locked_count = 0
        team_locks = locked_team_days.get(team_id)
        if team_locks:
            try:
                locked_count = team_locks.get(int(day), 0)
            except (TypeError, ValueError):
                locked_count = 0
        if locked_count >= 1:
            # Un verrou occupe déjà l'unique séance du jour → aucun créneau libre ce jour-là.
            # ``<= 0`` (et non ``<= 1 - locked_count``) : deux verrous le même jour sont un
            # conflit ENTRE verrous, laissé au diagnostic — jamais une contrainte infaisable.
            if deduped:
                cast(Any, model).Add(sum(deduped) <= 0)
                added += 1
                # P4-99 — le jour est déjà pris par un verrou : chaque créneau libre fermé ici a
                # pour cause ce verrou. (Les `day_active`/`OnlyEnforceIf` du budget hebdomadaire
                # ci-dessus sont des canaux de réification, PAS des fermetures — hors mesure.)
                for locked_out_var in deduped:
                    _record_closure(model, locked_out_var, {"kind": "hard_lock"})
        elif len(deduped) > 1:
            cast(Any, model).Add(sum(deduped) <= 1)
            added += 1

    return added


def add_age_ascending_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    teams: Iterable[Any] = (),
    intensity: str = HARD,
    soft_terms_out: list[tuple[BoolVarLike, str]] | None = None,
    soft_term_info_out: list[CompromiseTermInfo] | None = None,
) -> int:
    """Implicit rule 12: younger teams train earlier than older teams
    in the same venue on the same day.

    For each pair (A, B) where A.ageMin < B.ageMin (both not None, neither
    HARD-locked), and for each venue+day, if slot_A starts later than slot_B,
    (``intensity=HARD``) prevent both from being selected simultaneously:
    ``x[A, venue, day, slot_A] + x[B, venue, day, slot_B] <= 1``.

    When ``intensity=PREFERRED`` the hard bound is NOT posted; instead ONE aggregated
    violation literal per ``(venue, day)`` — the OR of that venue-day's inverted pairs
    being both selected — is appended to ``soft_terms_out`` for the objective.

    Teams with ``ageMin=None`` (Loisir, Baby) and HARD-locked teams are exempt.
    No constraint is added between teams sharing the same ``ageMin``.
    """

    team_age_min: dict[str, int] = {}
    for team in teams:
        tid = _scalar_id(_get(team, "id", "team_id", "teamId", default=None))
        if tid is None:
            continue
        age_min = _get(team, "ageMin", "age_min", default=None)
        if age_min is None:
            continue
        team_age_min[str(tid)] = int(age_min)

    if len(team_age_min) < 2:
        return 0

    hard_locked_teams: set[str] = set()
    hard_slot_keys: frozenset[tuple[str, str, int, str]] = getattr(model, "hard_slot_keys", frozenset())
    for slot_key in hard_slot_keys:
        hard_locked_teams.add(str(slot_key[0]))

    locked_slots = getattr(model, "locked_slots", ())
    for locked in locked_slots:
        tid = _scalar_id(_get(locked, "team_id", "teamId", default=None))
        if tid is not None:
            hard_locked_teams.add(str(tid))

    groups: dict[tuple[str, str], list[tuple[str, int, BoolVarLike]]] = defaultdict(list)
    for assignment in assignments:
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        slot_id = assignment.slot_id
        if team_id is None or venue_id is None or slot_id is None:
            continue
        team_id_str = str(team_id)
        if team_id_str not in team_age_min or team_id_str in hard_locked_teams:
            continue
        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue
        day = parts[0]
        start_minutes = _time_to_minutes(parts[1])
        groups[(str(venue_id), day)].append((team_id_str, start_minutes, assignment.var))

    added = 0
    for (venue_id_str, day), _entries in groups.items():
        by_team: dict[str, list[tuple[int, BoolVarLike]]] = defaultdict(list)
        for team_id_str, start_minutes, var in _entries:
            by_team[team_id_str].append((start_minutes, var))

        team_ids_here = [t for t in by_team if t in team_age_min]
        team_ids_here.sort(key=lambda t: team_age_min[t])

        pair_active_literals: list[BoolVarLike] = []
        for i in range(len(team_ids_here)):
            for j in range(i + 1, len(team_ids_here)):
                team_a = team_ids_here[i]
                team_b = team_ids_here[j]
                if team_age_min[team_a] == team_age_min[team_b]:
                    continue
                for start_a, var_a in by_team[team_a]:
                    for start_b, var_b in by_team[team_b]:
                        if start_a > start_b:
                            if intensity == PREFERRED:
                                active = cast(Any, model).NewBoolVar(
                                    f"age_pair_{venue_id_str}_{day}_{len(pair_active_literals)}"
                                )
                                cast(Any, model).Add(var_a + var_b >= 2).OnlyEnforceIf(active)
                                cast(Any, model).Add(var_a + var_b <= 1).OnlyEnforceIf(active.Not())
                                pair_active_literals.append(active)
                            else:
                                model.Add(var_a + var_b <= 1)
                                added += 1

        if intensity == PREFERRED and pair_active_literals:
            gv_violated = cast(Any, model).NewBoolVar(f"age_violated_{venue_id_str}_{day}")
            for active in pair_active_literals:
                cast(Any, model).Add(gv_violated >= active)
            cast(Any, model).Add(gv_violated <= sum(pair_active_literals))
            if soft_terms_out is not None:
                soft_terms_out.append((gv_violated, AGE_VIOLATION_WEIGHT))
            if soft_term_info_out is not None:
                soft_term_info_out.append(
                    CompromiseTermInfo(
                        var=gv_violated,
                        family=FAMILY_IMPLICIT,
                        honored_when_active=False,
                        key=(FAMILY_IMPLICIT, "age", venue_id_str, day),
                        venue_id=venue_id_str,
                        day_of_week=_to_day_int(day),
                        detail="age",
                    )
                )
            added += 1

    return added
