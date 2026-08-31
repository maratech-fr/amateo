from __future__ import annotations

from collections.abc import Iterable, Mapping
from datetime import datetime, time
from typing import Any, cast

from ortools.sat.python import cp_model

SLOT_MINUTES = 15  # solver granularity — do not change
DEFAULT_SESSION_MINUTES = 90  # default session duration when not specified in input
HARD_LOCK_LEVEL = "HARD"

SlotKey = tuple[str, str, int, str]
VenueSlotKey = tuple[str, int, str]
VariableMap = dict[SlotKey, cp_model.IntVar]


class ScheduleCpModel(cp_model.CpModel):
    def __init__(self) -> None:
        super().__init__()
        self.x: VariableMap = {}
        self.available_slots: tuple[VenueSlotKey, ...] = ()
        self.locked_slots: tuple[dict[str, Any], ...] = ()
        self.hard_slot_keys: frozenset[SlotKey] = frozenset()
        self.blocked_venue_slots: frozenset[VenueSlotKey] = frozenset()
        self.slot_durations: dict[VenueSlotKey, int] = {}
        # ENG-17 — équipe → coachs MAIN, posée par `_solve` depuis
        # `parse_v2_constraints`. Le builder de résultat la lit pour nommer le coach
        # des séances GÉNÉRÉES (mêmes données que celles modélisées : exclusivité
        # coach, bonus de chaînage). Même idiome que `locked_slots` ci-dessus.
        self.team_coach_map: dict[str, list[str]] = {}
        # Idem team_coach_map : équipe → joueurs (dont coach-joueurs), posée par `_solve`.
        # Le builder de résultat diagnostique les règles implicites au MÊME grain que la pose.
        self.team_player_map: dict[str, list[str]] = {}
        # Réglage effectif des règles implicites (``ResolvedImplicitRules``), posé par `_solve`.
        # Typé ``Any`` ici pour éviter un cycle d'import avec ``constraints`` (qui importe model).
        self.implicit_rules: Any = None
        self.slot_capacities: dict[VenueSlotKey, int] = {}
        # P2-51 — mutualisation par BLOC. ``add_shared_block_constraints`` (posé AVANT la
        # capacité gymnase dans l'agrégateur) remplit ``shared_block_room_relief`` —
        # ``[(b_var, n_free-1)]`` indexé par la case ``(venue_id, slot_id)`` (slot_id ==
        # "day:HH:MM") : une séance de bloc occupe la case pour UN, pas pour ses N membres libres ;
        # ``add_room_at_most_one`` dé-compte ``(n_free-1)·b`` de la somme pour que la co-présence
        # tienne dans une case capacité 1. Bloc ``sharedBlocks`` absent/vide ⇒ carte vide ⇒ chemin
        # byte-identique (goldens inchangés).
        self.shared_block_room_relief: dict[tuple[str, str], list[tuple[Any, int]]] = {}
        # P4-99 — la cause MESURÉE d'un candidat fermé, indexée par la variable OR-Tools
        # (``var.Index()``, entier stable). Remplie par les sites de pose de `constraints.py`
        # au moment EXACT où ils forcent un candidat à 0 (décision B : jamais reconstituée
        # après coup). Même idiome `locked_slots`/`team_coach_map` : un attribut posé sur le
        # modèle, lu une seule fois par `result_builder`.
        self.candidate_closures: dict[int, list[dict[str, Any]]] = {}
        # Candidats SANS variable : `build_model` retire (jamais de `NewBoolVar`) le créneau
        # libre qu'un verrou d'une AUTRE équipe bloque. Clé = SlotKey (pas d'`Index()` possible).
        self.lock_removed_candidates: dict[SlotKey, dict[str, Any]] = {}
        # Sources de contraintes posées par `_solve` depuis `parse_v2_constraints`, pour que
        # les fermetures gymnase-forcé / indispo-coach nomment leur contrainte SANS re-parser
        # (aucune signature publique de `constraints.py` ne change — le canal est le modèle).
        self.forced_venue_sources: dict[str, dict[str, Any]] = {}
        self.coach_unavailability_sources: dict[str, list[dict[str, Any]]] = {}
        # P3-21 — score RAPPORTÉ recalculé aux poids d'ORIGINE (placement + chaînage naturel,
        # stabilité EXCLUE) quand la phase 2 applique le terme de stabilité, posé par
        # `main._solve`. None (défaut) ⇒ `result_builder` lit `ObjectiveValue()` tel quel :
        # sans previousAssignments le chemin reste byte-identique.
        self.reported_score_override: int | None = None

    def NumVariables(self) -> int:
        return len(self.Proto().variables)

    def num_variables(self) -> int:
        return self.NumVariables()


def build_model(data: Mapping[str, Any] | Any) -> ScheduleCpModel:
    model = ScheduleCpModel()
    teams = _team_ids(data)
    available_slots = _derive_available_slots(data)
    locked_slots, hard_slot_keys, blocked_venue_slots = _extract_hard_locks(data)

    model.available_slots = available_slots
    model.locked_slots = locked_slots
    model.hard_slot_keys = hard_slot_keys
    model.blocked_venue_slots = blocked_venue_slots

    for venue in _collection(data, "venues"):
        venue_id = str(_required(venue, "id"))
        for ts in _collection(venue, "training_slots", "trainingSlots"):
            day_of_week = int(_required(ts, "day_of_week", "dayOfWeek"))
            start_time = _format_time(_time_to_minutes(_required(ts, "start_time", "startTime")))
            duration = int(_value(ts, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))
            capacity = int(_value(ts, "capacity", default=1))
            vsk: VenueSlotKey = (venue_id, day_of_week, start_time)
            model.slot_durations[vsk] = duration
            model.slot_capacities[vsk] = capacity

    for team_id in teams:
        for venue_id, day_of_week, slot_start in available_slots:
            slot_key = (team_id, venue_id, day_of_week, slot_start)
            venue_slot_key = (venue_id, day_of_week, slot_start)
            # P4-99 — deux cas dans cette branche, à ne PAS confondre :
            #   * `slot_key in hard_slot_keys` = ce créneau EST la séance verrouillée de CETTE
            #     équipe : elle l'a, rien à diagnostiquer.
            #   * `venue_slot_key in blocked_venue_slots` (sans être son propre verrou) = un
            #     verrou d'une AUTRE équipe occupe la place : candidat retiré → cause hard_lock.
            if slot_key in hard_slot_keys:
                continue
            if venue_slot_key in blocked_venue_slots:
                model.lock_removed_candidates[slot_key] = {"kind": "hard_lock"}
                continue

            model.x[slot_key] = cast(Any, model).NewBoolVar(_variable_name(slot_key))

    return model


def _team_ids(data: Mapping[str, Any] | Any) -> tuple[str, ...]:
    return tuple(str(_required(team, "id")) for team in _collection(data, "teams"))


DEFAULT_START_MINUTES = 8 * 60  # 08:00
DEFAULT_END_MINUTES = 22 * 60  # 22:00
DEFAULT_DAYS_OF_WEEK = (1, 2, 3, 4, 5)  # Mon-Fri


def _derive_available_slots(data: Mapping[str, Any] | Any) -> tuple[VenueSlotKey, ...]:
    slots: set[VenueSlotKey] = set()

    for venue in _collection(data, "venues"):
        venue_id = str(_required(venue, "id"))
        for ts in _collection(venue, "training_slots", "trainingSlots"):
            day_of_week = int(_required(ts, "day_of_week", "dayOfWeek"))
            start_time = _format_time(_time_to_minutes(_required(ts, "start_time", "startTime")))
            slots.add((venue_id, day_of_week, start_time))

    return tuple(sorted(slots, key=_sort_venue_slot))


def _extract_hard_locks(
    data: Mapping[str, Any] | Any,
) -> tuple[tuple[dict[str, Any], ...], frozenset[SlotKey], frozenset[VenueSlotKey]]:
    locked_slots: list[dict[str, Any]] = []
    hard_slot_keys: set[SlotKey] = set()
    blocked_venue_slots: set[VenueSlotKey] = set()
    seen_locks: set[tuple[str, str, int, str, int]] = set()

    for slot in _slot_templates(data):
        lock_level = str(_value(slot, "lock_level", "lockLevel", default="")).upper()
        if lock_level != HARD_LOCK_LEVEL:
            continue

        team_id = str(_required(slot, "team_id", "teamId"))
        venue_id = str(_required(slot, "venue_id", "venueId"))
        day_of_week = int(_required(slot, "day_of_week", "dayOfWeek"))
        start_minutes = _time_to_minutes(_required(slot, "start_time", "startTime"))
        duration_minutes = int(_value(slot, "duration_minutes", "durationMinutes", default=DEFAULT_SESSION_MINUTES))

        if duration_minutes <= 0:
            raise ValueError(f"HARD slot for team {team_id} has a non-positive duration")

        # Deduplicate identical HARD templates: two rows for the same
        # (team, venue, day, start, duration) must yield ONE locked slot, else
        # build_result emits it twice and _diagnose_conflicts reports a fake
        # over-capacity conflict ("SM3, SM3" — audit ENG-09). Duration is part of
        # the key: two rows that differ in duration are NOT duplicates — dropping
        # the longer one would leave its extra minutes unblocked (audit review).
        lock_key = (team_id, venue_id, day_of_week, _format_time(start_minutes), duration_minutes)
        if lock_key in seen_locks:
            continue
        seen_locks.add(lock_key)

        normalized_slot = dict(slot) if isinstance(slot, Mapping) else {}
        normalized_slot.update(
            {
                "team_id": team_id,
                "venue_id": venue_id,
                "day_of_week": day_of_week,
                "start_time": _format_time(start_minutes),
                "duration_minutes": duration_minutes,
                "lock_level": HARD_LOCK_LEVEL,
            }
        )
        locked_slots.append(normalized_slot)

        for slot_start in _duration_slot_starts(start_minutes, duration_minutes):
            normalized_start = _format_time(slot_start)
            hard_slot_keys.add((team_id, venue_id, day_of_week, normalized_start))
            blocked_venue_slots.add((venue_id, day_of_week, normalized_start))

    return tuple(locked_slots), frozenset(hard_slot_keys), frozenset(blocked_venue_slots)


def _slot_templates(data: Mapping[str, Any] | Any) -> Iterable[Mapping[str, Any] | Any]:
    names = (
        "schedule_slot_templates",
        "scheduleSlotTemplates",
        "slot_templates",
        "slotTemplates",
        "locked_slots",
        "lockedSlots",
        "slots",
    )
    for name in names:
        yield from _collection(data, name)


def _collection(source: Mapping[str, Any] | Any, *names: str) -> Iterable[Any]:
    for name in names:
        values = _value(source, name, default=None)
        if values is None:
            continue
        if isinstance(values, Iterable) and not isinstance(values, (str, bytes, Mapping)):
            return values
        raise TypeError(f"{name} must be a list-like collection")
    return ()


def _required(source: Mapping[str, Any] | Any, *names: str) -> Any:
    value = _value(source, *names, default=None)
    if value is None:
        joined_names = "/".join(names)
        raise ValueError(f"missing required field: {joined_names}")
    return value


def _value(source: Mapping[str, Any] | Any, *names: str, default: Any = None) -> Any:
    for name in names:
        if isinstance(source, Mapping):
            if name in source and source[name] is not None:
                return source[name]
            continue

        value = getattr(source, name, None)
        if value is not None:
            return value

    return default


def _time_to_minutes(value: Any) -> int:
    if isinstance(value, datetime):
        return value.hour * 60 + value.minute
    if isinstance(value, time):
        return value.hour * 60 + value.minute
    if isinstance(value, int):
        return value

    text = str(value).strip()
    if "T" in text:
        text = text.split("T", 1)[1]
    elif " " in text:
        text = text.split(" ", 1)[1]

    text = text.removesuffix("Z").split("+", 1)[0]
    if "-" in text[1:]:
        text = text.rsplit("-", 1)[0]

    parts = text.split(":")
    if len(parts) < 2:
        raise ValueError(f"invalid time value: {value!r}")

    return int(parts[0]) * 60 + int(parts[1])


def _duration_slot_starts(start_minutes: int, duration_minutes: int) -> Iterable[int]:
    slot_count = (duration_minutes + SLOT_MINUTES - 1) // SLOT_MINUTES
    for offset in range(slot_count):
        yield start_minutes + offset * SLOT_MINUTES


def _format_time(minutes: int) -> str:
    hours, remainder = divmod(minutes, 60)
    return f"{hours:02d}:{remainder:02d}"


def _sort_venue_slot(slot: VenueSlotKey) -> tuple[str, int, int]:
    venue_id, day_of_week, slot_start = slot
    return venue_id, day_of_week, _time_to_minutes(slot_start)


def _variable_name(slot_key: SlotKey) -> str:
    team_id, venue_id, day_of_week, slot_start = slot_key
    return f"x[{team_id},{venue_id},{day_of_week},{slot_start}]"
