"""Shared types, constants, locked-slot readers and assignment normalisation.

Base of the ``constraints`` package DAG: imports only ``..compromise``,
``..helpers`` and ``..model`` — never a sibling. The normalisation family lives
here because both ``structural`` and ``targeting`` consume it; placing it in
either would create an import cycle. ``_record_closure`` lives here because the
eight constraint posers call it and the diagnostics re-read its causes through
``model.candidate_closures`` (never by import)."""

from __future__ import annotations

import logging
from collections import defaultdict
from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any, TypedDict

from ..compromise import CompromiseTermInfo
from ..helpers import MISSING, assignment_team_id, assignment_var, get_field, scalar_id
from ..model import DEFAULT_SESSION_MINUTES, SLOT_MINUTES, _time_to_minutes

logger = logging.getLogger("engine.constraints")


class ParsedConstraints(TypedDict):
    """Typed result of parse_v2_constraints — the backend→engine boundary where
    the ENG-01/02 format bugs lived (an untyped dict let a set-of-days be
    compared to a slot string with no type error). Now mypy checks every
    producer and consumer of these collections."""

    forbidden_assignments: list[dict[str, str | None]]
    # coach id → set of blocked (weekday, from_minute, to_minute) intervals. A
    # whole-day block is (day, 0, 1440). Union semantics — a slot is blocked if it
    # falls in ANY interval (Lot C: coach unavailability with time windows).
    coach_unavailability: dict[str, set[tuple[int, int, int]]]
    forced_venues: dict[str, str]
    # P4-99 — équipe → {constraint_id, label} de la règle de gymnase forcé (last-wins, aligné
    # sur `forced_venues`) ; source cliquable de la cause `forced_venue_elsewhere`.
    forced_venue_sources: dict[str, dict[str, Any]]
    # P4-99 — coach → [{constraint_id, label}] des contraintes d'indispo (grain COACH, pas
    # l'intervalle : l'arité de `coach_unavailability` NE change pas). Cause `coach_unavailability`.
    coach_unavailability_sources: dict[str, list[dict[str, Any]]]
    # PR B (2026-08-06) — un ENSEMBLE par équipe : les règles PREFERRED se cumulent
    # (« privilégier Vilar ET Tonkin »), elles ne s'écrasent plus en last-wins.
    preferred_venues: dict[str, set[str]]
    avoided_venues: list[dict[str, str]]
    venue_minimums: list[dict[str, Any]]
    time_windows: list[dict[str, Any]]
    priority_tiers: dict[int, int]
    team_coach_map: dict[str, list[str]]
    team_player_map: dict[str, list[str]]
    parse_warnings: list[dict[str, Any]]


# Intentional aliases (ENG-05 Scope A leaves these as-is, out of scope):
#   BoolVarLike     — a CP-SAT BoolVar/literal; ortools exposes no stable public
#                     type to annotate against, so `Any` is deliberate.
#   RuleCollection  — a loosely-shaped rule container (mapping/sequence) whose
#                     concrete shape varies per caller; kept `Any` on purpose.
BoolVarLike = Any
RuleCollection = Any

_MISSING = MISSING


@dataclass(frozen=True)
class AssignmentVariable:
    """Candidate assignment variable used by the hard constraint builder.

    The fields intentionally mirror the domain dimensions used by level-1
    constraints. Future T22 model objects can also be passed directly: all
    public functions read attributes or mapping keys with the same names.
    """

    var: BoolVarLike
    team_id: str | None = None
    slot_id: str | None = None
    venue_id: str | None = None
    coach_id: str | None = None
    player_ids: Sequence[str] = ()
    session_id: str | None = None
    start: int | None = None
    end: int | None = None
    fixed: bool = False
    forbidden: bool = False
    coach_unavailable: bool = False
    forced_venue_id: str | None = None
    id: str | None = None


# Loose input accepted by the public constraint entry points: already-typed
# AssignmentVariable objects, or plain mappings (production sends list[dict]).
# _normalise_assignments converts every element to AssignmentVariable so the
# internal builder operates on a single, real type (ENG-05: kills the
# AssignmentLike=Any duck-typing that let a mistyped field silently return None).
AssignmentInput = AssignmentVariable | Mapping[str, Any]


# Nom de poids d'objectif du littéral de violation de chaque règle implicite passée PREFERRED
# (la clé de contrat de la règle == alias camelCase du schéma d'entrée, réutilisée telle quelle
# comme ``ruleKey`` de diagnostic côté result_builder).
COACH_REST_VIOLATION_WEIGHT = "coach_rest_violation"
SALARIE_VIOLATION_WEIGHT = "salarie_violation"
CHAIN_VIOLATION_WEIGHT = "chain_violation"
CONSECUTIVE_DAYS_VIOLATION_WEIGHT = "consecutive_days_violation"
# P2-42 — intensité « règle absente donc NON APPLIQUÉE ». Les quatre règles implicites
# historiques retombent sur HARD quand leur bloc manque : c'est leur comportement d'origine,
# antérieur au réglage. Celle-ci est NEUVE : la faire naître HARD changerait en silence le
# planning de tous les clubs existants — et le test `forced_two_days_sessions_per_week_4`
# l'a prouvé sur-le-champ (une équipe à 4 séances/semaine n'en obtenait plus que 3, ses
# jours forcés devenant une suite interdite). Un club l'active, sinon rien ne change.
OFF = "OFF"
AGE_VIOLATION_WEIGHT = "age_violation"

HARD = "HARD"
PREFERRED = "PREFERRED"
# Lot PASSERELLES — l'intensité d'une passerelle (``teamLinks[].intensity``). ``MANDATORY`` pose
# l'anti-chevauchement DUR ; ``PREFERRED`` est un terme d'objectif (``objective.add_team_link_penalty``).
MANDATORY = "MANDATORY"


def _record_closure(model: Any, var: BoolVarLike, cause: dict[str, Any]) -> None:
    """Enregistre la cause MESURÉE d'une fermeture de candidat (P4-99, décision B).

    Indexée par la variable OR-Tools (``var.Index()``, entier stable) dans
    ``model.candidate_closures``. DÉFENSIF par conception : les tests unitaires de
    ``constraints.py`` passent des ``cp_model.CpModel`` NUS, sans attribut custom — un modèle
    nu = aucun enregistrement, pose strictement inchangée (sinon la moitié de
    ``test_constraints.py`` casse). Appelé APRÈS le ``model.Add(var == 0)`` du site : le
    recueil est passif, il n'ajoute ni variable ni contrainte (les golden ne bougent pas)."""
    closures = getattr(model, "candidate_closures", None)
    if closures is None:
        return
    try:
        index = int(var.Index())
    except (AttributeError, TypeError):
        return
    closures.setdefault(index, []).append(cause)


@dataclass(frozen=True)
class ResolvedImplicitRules:
    """Réglage EFFECTIF des 4 règles implicites, seuils inclus, défauts appliqués.

    Construit par ``resolve_implicit_rules`` depuis le bloc ``implicitRules`` du payload
    (ou depuis ``None`` = tout HARD, seuils historiques). Ce type est l'unique vocabulaire
    partagé par la pose (``add_level_1_hard_constraints``) et le diagnostic post-solve
    (``result_builder``) : le même réglage décide de la contrainte ET du texte du warning.
    """

    coach_rest_day_intensity: str = HARD
    min_rest_days: int = 1
    salarie_distribution_intensity: str = HARD
    max_consecutive_sessions_intensity: str = HARD
    max_consecutive: int = 3
    age_ascending_intensity: str = HARD
    # P2-42 — l'ÉQUIPE et les JOURS, pas la personne et les créneaux (cf. add_max_consecutive_days).
    max_consecutive_days_intensity: str = OFF
    max_consecutive_days: int = 3
    # P2-53 RMM-8 PR-2 — trajet entre gymnases. ``travel_time_active`` naît du bloc `travelTime`
    # du payload (absent = inactive, ni départage ni battement — l'opt-in au premier geste sur la
    # matrice). ``travel_time_intensity`` gouverne le BATTEMENT (PREFERRED = soft compromis,
    # MANDATORY = interdit dur) ; le DÉPARTAGE « moindre trajet » s'applique dès que la règle est
    # active, quel que soit le cran. ``travel_time_default_minutes`` = barème d'un couple non
    # arbitré (20). Le vocabulaire d'intensité est PREFERRED/MANDATORY (patron passerelle), PAS
    # HARD/PREFERRED comme les 5 règles de bien-être : le trajet suggère ou interdit, il ne « durcit »
    # pas une préférence de confort.
    travel_time_active: bool = False
    travel_time_intensity: str = PREFERRED
    travel_time_default_minutes: int = 20


@dataclass
class HardConstraintStats:
    """Counts of level-1 hard constraints added to the CP-SAT model."""

    room_at_most_one: int = 0
    coach_at_most_one: int = 0
    coach_player_non_overlap: int = 0
    team_no_overlap: int = 0
    travel_feasibility_stub: int = 0
    fixed_slots: int = 0
    forbidden_assignments: int = 0
    coach_unavailability: int = 0
    required_bridge_stub: int = 0
    min_sessions: int = 0
    forced_venues: int = 0
    one_session_per_day: int = 0
    age_ascending: int = 0
    coach_rest_day: int = 0
    salarie_distribution: int = 0
    max_consecutive_sessions: int = 0
    max_consecutive_days: int = 0
    # P2-51 — contraintes de mutualisation par BLOC (liage ``x >= b`` par membre + ``Σb ==
    # commonSessions`` + garde de distinctness inter-blocs) posées. 0 quand le bloc
    # ``sharedBlocks`` est absent/vide (chemin byte-identique, goldens inchangés).
    shared_block: int = 0
    # Lot PASSERELLES — contraintes d'anti-chevauchement DUR des passerelles MANDATORY posées. 0
    # quand le bloc ``teamLinks`` est absent/vide ou tout PREFERRED (chemin byte-identique).
    team_link: int = 0
    # P2-53 RMM-8 PR-2 — battements de trajet INTERDITS DUR (règle `travelTime` MANDATORY : deux
    # séances enchaînées à des gymnases différents dont l'écart est plus court que le barème). 0
    # quand la règle est inactive, PREFERRED, ou sans couple concerné (chemin byte-identique). À NE
    # PAS confondre avec le stub ``travel_feasibility_stub`` (jamais posé, référencé par 3 suites).
    travel_time: int = 0
    # Littéraux de violation AGRÉGÉS des règles implicites passées en PREFERRED, prêts pour
    # l'objectif : ``(literal, weight_name)``. Vide quand tout est HARD (défaut). Hors du
    # total : ce sont des termes d'objectif, pas des contraintes dures.
    implicit_soft_terms: list[tuple[BoolVarLike, str]] = field(default_factory=list)
    # Métadonnée de nommage des compromis (P2-32), parallèle à ``implicit_soft_terms`` : une
    # entrée par littéral de violation implicite quand un ``soft_term_info_out`` a été passé
    # (chemin /generate : liste vide, aucun effet sur le solve).
    implicit_soft_info: list[CompromiseTermInfo] = field(default_factory=list)

    @property
    def total_constraints_added(self) -> int:
        """Return the number of concrete CP-SAT constraints added to the model."""

        return (
            self.room_at_most_one
            + self.coach_at_most_one
            + self.coach_player_non_overlap
            + self.team_no_overlap
            + self.travel_feasibility_stub
            + self.fixed_slots
            + self.forbidden_assignments
            + self.coach_unavailability
            + self.required_bridge_stub
            + self.min_sessions
            + self.forced_venues
            + self.one_session_per_day
            + self.age_ascending
            + self.coach_rest_day
            + self.salarie_distribution
            + self.max_consecutive_sessions
            + self.shared_block
            + self.team_link
            + self.travel_time
        )


def _locked_person_day_intervals(
    model: Any,
    team_coach_map: dict[str, list[str]] | None,
    team_player_map: dict[str, list[str]] | None,
) -> dict[str, dict[int, list[tuple[int, int]]]]:
    """Map each person (coach or player) to the HARD-locked sessions they own, by weekday.

    P4-97 — a HARD-locked session carries NO model variable (``model.py`` drops the
    ``x[team, venue, day, start]`` var), so the wellbeing rules 3b/3c/3d never saw it: a
    coach whose only sessions are locked read as « never working », a day served solely by
    a locked salarié read as « no salarié present », and a mixed locked+free back-to-back
    chain was invisible to the POSE. This translates each locked session back to the people
    it involves via ``team_coach_map`` (MAIN coaches) and ``team_player_map`` (players,
    coach-players included) — NEVER ``slot.coachId`` — so those rules can pin the matching
    literals as constants.

    Returns ``person_id -> weekday(int) -> [(start_minute, end_minute), …]``. Days are not
    filtered to Mon-Fri here; each consumer applies its own day window.
    """
    result: dict[str, dict[int, list[tuple[int, int]]]] = defaultdict(lambda: defaultdict(list))
    coach_map = team_coach_map or {}
    player_map = team_player_map or {}
    for locked in getattr(model, "locked_slots", ()) or ():
        team_id = str(_get(locked, "team_id", "teamId", default="") or "")
        if not team_id:
            continue
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_raw = _get(locked, "start_time", "startTime", default=None)
        if day_raw is None or start_raw is None:
            continue
        try:
            day = int(day_raw)
        except (TypeError, ValueError):
            continue
        start = _time_to_minutes(start_raw)
        duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        end = start + duration
        persons: set[str] = set()
        for coach_id in coach_map.get(team_id, ()):
            persons.add(str(coach_id))
        for player_id in player_map.get(team_id, ()):
            persons.add(str(player_id))
        for person in persons:
            result[person][day].append((start, end))
    return result


def _locked_person_day_occupations(
    model: Any,
    team_coach_map: dict[str, list[str]] | None,
    team_player_map: dict[str, list[str]] | None,
) -> dict[str, dict[int, list[tuple[int, int, str | None, str, str]]]]:
    """Comme ``_locked_person_day_intervals`` mais AVEC le gymnase, le RÔLE et l'ÉQUIPE de chaque
    occupation verrouillée — de quoi opposer un placement LIBRE à un verrou sous la règle
    D-14 (P4-97 bis), et exempter la paire quand elle siège sur une case de bloc active.

    Retourne ``person_id -> weekday(int) -> [(start, end, venue, role, team), …]`` où ``role`` vaut
    ``"coach"`` (via ``team_coach_map``, ASSISTANT déjà filtré) ou ``"player"`` (via
    ``team_player_map`` — le joueur l'emporte, on ne joue pas en coachant), et ``team`` est l'équipe
    verrouillée (nécessaire à l'exemption coach-joueur de bloc). Source unique :
    ``model.locked_slots`` × les cartes — JAMAIS ``slot.coachId``.
    """
    result: dict[str, dict[int, list[tuple[int, int, str | None, str, str]]]] = defaultdict(lambda: defaultdict(list))
    coach_map = team_coach_map or {}
    player_map = team_player_map or {}
    for locked in getattr(model, "locked_slots", ()) or ():
        team_id = str(_get(locked, "team_id", "teamId", default="") or "")
        if not team_id:
            continue
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_raw = _get(locked, "start_time", "startTime", default=None)
        if day_raw is None or start_raw is None:
            continue
        try:
            day = int(day_raw)
        except (TypeError, ValueError):
            continue
        start = _time_to_minutes(start_raw)
        duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        end = start + duration
        venue = str(_get(locked, "venue_id", "venueId", default="") or "") or None
        roles: dict[str, str] = {}
        for coach_id in coach_map.get(team_id, ()):
            roles.setdefault(str(coach_id), "coach")
        for player_id in player_map.get(team_id, ()):
            roles[str(player_id)] = "player"  # jouer l'emporte sur coacher (D-14)
        for person, role in roles.items():
            result[person][day].append((start, end, venue, role, team_id))
    return result


def _locked_team_days(model: Any) -> dict[str, dict[int, int]]:
    """``team_id -> weekday(int) -> nombre de séances verrouillées ce jour-là`` (P4-97 bis).

    Une séance verrouillée EST déjà la séance du jour de son équipe : le placement LIBRE d'un
    second créneau le même jour doit en tenir compte (règle 11 « une séance par jour »)."""
    result: dict[str, dict[int, int]] = defaultdict(lambda: defaultdict(int))
    for locked in getattr(model, "locked_slots", ()) or ():
        team_id = str(_get(locked, "team_id", "teamId", default="") or "")
        if not team_id:
            continue
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        if day_raw is None:
            continue
        try:
            day = int(day_raw)
        except (TypeError, ValueError):
            continue
        result[team_id][day] += 1
    return result


def _locked_venue_substart_counts(model: Any) -> dict[tuple[str, int, int], int]:
    """``(venue, weekday, sub-slot start minute) -> nombre de séances verrouillées`` (P4-97 bis).

    Chaque verrou occupe UNE place de la capacité du gymnase sur chacun de ses sous-créneaux de
    15 min : un placement LIBRE ne peut s'y ajouter que s'il reste de la place (règle 1)."""
    counts: dict[tuple[str, int, int], int] = defaultdict(int)
    for locked in getattr(model, "locked_slots", ()) or ():
        venue = str(_get(locked, "venue_id", "venueId", default="") or "")
        if not venue:
            continue
        day_raw = _get(locked, "day_of_week", "dayOfWeek", default=None)
        start_raw = _get(locked, "start_time", "startTime", default=None)
        if day_raw is None or start_raw is None:
            continue
        try:
            day = int(day_raw)
        except (TypeError, ValueError):
            continue
        start = _time_to_minutes(start_raw)
        duration = int(_get(locked, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
        minute = start
        while minute < start + duration:
            counts[(venue, day, minute)] += 1
            minute += SLOT_MINUTES
    return counts


def _assignment_day_start(assignment: AssignmentVariable) -> tuple[int | None, int | None]:
    """Weekday + start-of-day minutes from ONE slot_id parse ("3:18:00" → (3, 1080)).

    Combined so the coach-unavailability hot loop splits each slot_id once, not
    twice (Lot C review). Either component is ``None`` when it cannot be parsed.
    """
    slot_id = _assignment_time_key(assignment)
    if not isinstance(slot_id, str) or ":" not in slot_id:
        return None, None
    head, _, rest = slot_id.partition(":")
    try:
        day: int | None = int(head)
    except ValueError:
        day = None
    try:
        start: int | None = _time_to_minutes(rest)
    except (ValueError, TypeError):
        start = None
    return day, start


def _normalise_assignments(
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None,
) -> list[AssignmentVariable]:
    """Convert any accepted input into a homogeneous ``list[AssignmentVariable]``.

    Three input shapes are supported:
      * a Mapping of ``key -> BoolVar`` (the T22 ``model.x``) — built via
        ``_assignment_from_mapping_item``;
      * an iterable of mappings/dicts (production sends ``list[dict]``);
      * an iterable of objects already exposing the assignment attributes
        (including ``AssignmentVariable`` itself, returned unchanged).

    After this call every downstream constraint builder reads real, typed
    attributes instead of duck-typing over ``Any`` (ENG-05).
    """
    if assignments is None:
        return []
    if isinstance(assignments, Mapping):
        return [_assignment_from_mapping_item(key, value) for key, value in assignments.items()]
    return [_as_assignment_variable(item) for item in assignments]


def _coerce_id(value: Any) -> Any:
    """Reproduce the removed accessors' id-normalisation EXACTLY: ``scalar_id``
    (unwrap a nested id/uuid/name), and nothing more.

    Deliberately NOT wrapped in ``str()``: the old ``_team_id``/``_venue_id``/…
    accessors returned the bare ``scalar_id`` result, so this keeps the object
    path byte-identical to before on every input — including hypothetical
    non-string ids, where an added ``str()`` would have desynced the group keys
    here from the un-stringified ``min_sessions``/``forced_venues`` lookup keys.
    All real inputs are strings, so this is a no-op on them.
    """
    return scalar_id(value)


def _as_assignment_variable(obj: AssignmentInput) -> AssignmentVariable:
    """Convert a single mapping/object element to an ``AssignmentVariable``.

    Already-typed ``AssignmentVariable`` instances are returned unchanged. For
    mappings and generic objects the canonical fields are read through the same
    alias lists the removed ``_``-accessors used, so behaviour is identical.
    """
    if isinstance(obj, AssignmentVariable):
        return obj
    return AssignmentVariable(
        var=assignment_var(obj, skip_none=False),
        team_id=_coerce_id(assignment_team_id(obj, skip_none=False)),
        slot_id=_coerce_id(get_field(obj, "slot_id", "time_slot_id", "timeslot_id", "slot", "time_slot", default=None)),
        venue_id=_coerce_id(
            get_field(obj, "venue_id", "room_id", "location_id", "venue", "room", "location", default=None)
        ),
        coach_id=_coerce_id(get_field(obj, "coach_id", "trainer_id", "coach", "trainer", default=None)),
        session_id=_coerce_id(
            get_field(obj, "session_id", "lesson_id", "event_id", "session", "lesson", "event", default=None)
        ),
        player_ids=_raw_player_ids(obj),
        start=get_field(obj, "start", "start_minute", "start_time", "starts_at", default=None),
        end=get_field(obj, "end", "end_minute", "end_time", "ends_at", default=None),
        fixed=_bool_field(obj, "fixed", "is_fixed", "pre_placed", "preplaced", "is_pre_placed"),
        forbidden=_bool_field(obj, "forbidden", "is_forbidden"),
        coach_unavailable=_bool_field(obj, "coach_unavailable", "is_coach_unavailable"),
        forced_venue_id=_coerce_id(
            get_field(obj, "forced_venue_id", "forced_room_id", "forced_venue", "forced_room", default=None)
        ),
        id=_coerce_id(get_field(obj, "id", "assignment_id", "key", default=None)),
    )


def _raw_player_ids(obj: AssignmentInput) -> Sequence[str]:
    """Read the player-id sequence off a raw mapping/object during conversion,
    honouring the historical alias list. Element coercion happens later in
    ``_player_ids`` (kept identical to the old read-time behaviour)."""
    players = get_field(
        obj,
        "player_ids",
        "participant_ids",
        "athlete_ids",
        "players",
        "participants",
        "athletes",
        default=(),
    )
    if players is None:
        return ()
    if isinstance(players, (str, bytes)):
        return [scalar_id(players)]
    return [scalar_id(player) for player in players]


def _assignment_from_mapping_item(key: Any, var: BoolVarLike) -> AssignmentVariable:
    if isinstance(key, tuple):
        values = list(key)
        assignment_id = ":".join(str(value) for value in values)

        if _looks_like_schedule_slot_key(values):
            day_of_week = values[2]
            slot_start = values[3]
            return AssignmentVariable(
                var=var,
                team_id=str(values[0]) if values[0] is not None else None,
                venue_id=str(values[1]) if values[1] is not None else None,
                slot_id=f"{day_of_week}:{slot_start}",
                id=assignment_id,
            )

        return AssignmentVariable(
            var=var,
            team_id=str(values[0]) if len(values) > 0 and values[0] is not None else None,
            slot_id=str(values[1]) if len(values) > 1 and values[1] is not None else None,
            venue_id=str(values[2]) if len(values) > 2 and values[2] is not None else None,
            coach_id=str(values[3]) if len(values) > 3 and values[3] is not None else None,
            session_id=str(values[4]) if len(values) > 4 and values[4] is not None else None,
            id=assignment_id,
        )
    return AssignmentVariable(var=var, id=str(key))


def _looks_like_schedule_slot_key(values: Sequence[Any]) -> bool:
    if len(values) != 4:
        return False

    day_of_week = values[2]
    slot_start = values[3]
    return _looks_like_day_of_week(day_of_week) and _looks_like_slot_start(slot_start)


def _looks_like_day_of_week(value: Any) -> bool:
    if isinstance(value, int):
        return 0 <= value <= 7
    if isinstance(value, str) and value.isdigit():
        return 0 <= int(value) <= 7
    return False


def _looks_like_slot_start(value: Any) -> bool:
    if isinstance(value, int):
        return 0 <= value < 24 * 60
    if not isinstance(value, str):
        return False
    parts = value.split(":")
    return len(parts) >= 2 and all(part.isdigit() for part in parts[:2])


def _dedupe_variables(variables: Iterable[BoolVarLike]) -> list[BoolVarLike]:
    unique: list[BoolVarLike] = []
    seen: set[Any] = set()
    for variable in variables:
        key = variable.Index() if hasattr(variable, "Index") else id(variable)
        if key in seen:
            continue
        seen.add(key)
        unique.append(variable)
    return unique


def _get(source: Any, *names: str, default: Any = None) -> Any:
    return get_field(source, *names, default=default, skip_none=False)


def _scalar_id(value: Any) -> Any:
    return scalar_id(value)


def _assignment_time_key(assignment: AssignmentVariable) -> Any:
    """Grouping key for "same time" collision detection.

    ``slot_id`` when present (a ``"day:HH:MM"`` string), else the
    ``(start, end)`` minute pair when both are present, else ``None``. This
    reproduces the old ``_time_key`` accessor exactly; the legacy
    ``time_key``/``time`` alias branch is dropped because the dataclass has no
    such field and no input ever supplied one.
    """
    if assignment.slot_id is not None:
        return assignment.slot_id
    if assignment.start is not None and assignment.end is not None:
        return (assignment.start, assignment.end)
    return None


def _intervals_overlap(a_start: Any, a_end: Any, b_start: Any, b_end: Any) -> bool:
    return bool(a_start < b_end and b_start < a_end)


def _interval_key(person_id: Any, day: Any, pair_index: Any) -> str:
    return f"{person_id}:{day}:{pair_index}"


def _extract_interval(assignment: AssignmentVariable) -> tuple[int | None, int | None, str | None]:
    """Extract (start_minutes, end_minutes, day) from an assignment.

    Returns (None, None, None) when start/end or slot_id are missing so callers
    can fall back to ``_assignment_time_key`` grouping.
    """
    start = assignment.start
    end = assignment.end
    if start is None or end is None:
        return None, None, None

    start_minutes = int(start) if not isinstance(start, int) else start
    end_minutes = int(end) if not isinstance(end, int) else end

    slot_id = assignment.slot_id
    if slot_id is None:
        return start_minutes, end_minutes, None
    day = str(slot_id).split(":")[0]
    return start_minutes, end_minutes, day


def _bool_field(obj: AssignmentInput, *names: str) -> bool:
    """Read a boolean flag off a raw mapping/object at conversion time, honouring
    the historical alias list (e.g. ``fixed`` / ``is_fixed``)."""
    return any(bool(_get(obj, name, default=False)) for name in names)


def _to_day_int(value: Any) -> int | None:
    """Best-effort weekday int. Legacy string day names (e.g. 'monday') and other
    non-numeric values are skipped, never crash the whole solve (audit review)."""
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _day_int_set(values: Any) -> set[int]:
    return {d for d in (_to_day_int(v) for v in (values or [])) if d is not None}


def _not_honored_warning(constraint: dict[str, Any], severity: str, message: str) -> dict[str, Any]:
    """A diagnostics entry for a constraint the solver cannot (fully) honor —
    same shape as the hard-conflict diagnostics merged by main.py."""
    constraint_id = constraint.get("id")

    # Shape must match DiagnosticSchema (no extra keys) — the source constraint
    # id rides in the diagnostic id; the manager-facing message uses the
    # constraint's human name when available.
    label = constraint.get("name") or constraint_id
    return {
        "id": f"constraint_not_honored-{constraint_id}",
        "type": "constraint_not_honored",
        "severity": severity,
        "teamId": None,
        "message": f"{message} (contrainte « {label} »)",
        "suggestions": [],
        "createdAt": datetime.now(UTC).isoformat(),
    }
