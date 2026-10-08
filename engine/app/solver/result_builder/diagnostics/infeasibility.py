"""Noyau nommé (D1), agrégat des candidats fermés (D2) et message d'infaisabilité.

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1).
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from typing import Any

from ortools.sat.python import cp_model

from ...model import ScheduleCpModel
from ..helpers import _collection, _get

_CAUSE_KIND_FR = {
    "hard_lock": "un créneau verrouillé",
    "venue_forbidden": "un gymnase interdit",
    "coach_unavailability": "une indisponibilité de coach",
    "time_window": "une fenêtre horaire",
    "day_conflict": "des règles de jour contradictoires",
    "day_forced": "un jour imposé",
    "day_forbidden": "un jour interdit",
    "forced_venue_elsewhere": "un gymnase imposé",
    "session_floor": "un minimum de séances dans un gymnase",
    "shared_block": "un bloc mutualisé",
    "team_link": "une passerelle",
    "travel_time": "un temps de trajet",
}


def _closure_var_index(var: Any) -> int | None:
    """Index OR-Tools stable d'une variable, ou None pour un double de test sans ``.Index()``."""
    try:
        return int(var.Index())
    except (AttributeError, TypeError):
        return None


def _collect_infeasibility_causes(
    model: ScheduleCpModel | Any,
    solver: cp_model.CpSolver | Any,
) -> tuple[list[dict[str, Any]], list[str]]:
    """P4-96 (D1) — NOMME le noyau de contraintes SOURCE en conflit sur INFEASIBLE.

    Lit ``solver.SufficientAssumptionsForInfeasibility()`` (index de littérales d'hypothèse) et les
    traduit via ``model.assumption_sources`` en causes ``{kind, constraintId, label, count}`` (forme
    ``DiagnosticCauseSchema``, kinds EXISTANTS) + la liste ordonnée des LIBELLÉS pour le message.
    Ne RE-TESTE aucune règle : les hypothèses ont été posées À LA POSE (``_assume``). Aucune
    hypothèse / solveur sans la méthode (doubles de test) / noyau vide ⇒ ``([], [])`` (message
    générique conservé, défensif comme le reste du rail)."""
    sources: Mapping[int, dict[str, Any]] = getattr(model, "assumption_sources", None) or {}
    if not sources or not hasattr(solver, "SufficientAssumptionsForInfeasibility"):
        return [], []
    try:
        core_indices = list(solver.SufficientAssumptionsForInfeasibility())
    except (RuntimeError, AttributeError, TypeError, ValueError):
        return [], []

    causes: list[dict[str, Any]] = []
    labels: list[str] = []
    seen: set[tuple[Any, Any, Any]] = set()
    for index in core_indices:
        source = sources.get(int(index))
        if source is None:
            continue
        key = (source.get("kind"), source.get("constraintId"), source.get("label"))
        if key in seen:
            continue
        seen.add(key)
        causes.append(
            {
                "kind": source.get("kind"),
                "constraintId": source.get("constraintId"),
                "label": source.get("label"),
                "count": 1,
            }
        )
        if source.get("label"):
            labels.append(str(source.get("label")))
    return causes, labels


def _collect_locked_out_teams(model: ScheduleCpModel | Any) -> list[dict[str, Any]]:
    """P4-96 (D2) — agrège, PAR ÉQUIPE, les candidats FERMÉS sur INFEASIBLE, SANS ``solver.Value``.

    Un verrou HARD n'a pas de variable : impossible de l'interroger via le solveur. On lit donc les
    fermetures MESURÉES à la pose — par variable dans ``model.candidate_closures`` et, pour les
    candidats sans variable (créneau retiré par le verrou d'une autre équipe), dans
    ``model.lock_removed_candidates``. Une équipe dont TOUS les candidats sont fermés (0 ouvert) est
    rapportée avec le décompte par cause (« U21M1 : 20 candidats, tous fermés — 12 par …, 8 par … »).
    Renvoie une liste de ``{teamId, total, causes}`` ; ``causes`` est au format ``DiagnosticCauseSchema``.
    """
    closures: Mapping[int, list[dict[str, Any]]] = getattr(model, "candidate_closures", None) or {}
    lock_removed: Mapping[Any, dict[str, Any]] = getattr(model, "lock_removed_candidates", None) or {}

    team_total: dict[str, int] = defaultdict(int)
    team_closed: dict[str, int] = defaultdict(int)
    team_cause_counts: dict[str, dict[tuple[Any, Any, Any], int]] = defaultdict(lambda: defaultdict(int))

    for slot_key, var in getattr(model, "x", {}).items():
        team_id = str(slot_key[0])
        team_total[team_id] += 1
        index = _closure_var_index(var)
        var_closures = closures.get(index) if index is not None else None
        if var_closures:
            team_closed[team_id] += 1
            for cause in var_closures:
                key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
                team_cause_counts[team_id][key] += 1

    for slot_key, cause in lock_removed.items():
        team_id = str(slot_key[0])
        team_total[team_id] += 1
        team_closed[team_id] += 1
        key = (cause.get("kind"), cause.get("constraintId"), cause.get("label"))
        team_cause_counts[team_id][key] += 1

    locked_out: list[dict[str, Any]] = []
    for team_id in sorted(team_total):
        total = team_total[team_id]
        if total == 0 or total - team_closed[team_id] > 0:
            continue  # il reste au moins un candidat OUVERT : l'équipe n'est pas verrouillée
        causes = [
            {"kind": kind, "constraintId": constraint_id, "label": label, "count": count}
            for (kind, constraint_id, label), count in team_cause_counts[team_id].items()
        ]
        locked_out.append({"teamId": team_id, "total": total, "causes": causes})
    return locked_out


def _lock_summary_message(team_label: str, total: int, causes: list[dict[str, Any]]) -> str:
    """Message FR d'un agrégat D2 : « U21M1 : 20 candidats, tous fermés — 12 par …, 8 par … »."""
    parts: list[str] = []
    for cause in sorted(causes, key=lambda c: int(c.get("count") or 0), reverse=True):
        label = cause.get("label")
        descriptor = f"« {label} »" if label else _CAUSE_KIND_FR.get(str(cause.get("kind")), "une contrainte")
        parts.append(f"{cause.get('count')} par {descriptor}")
    breakdown = f" — {', '.join(parts)}" if parts else ""
    plural = "candidat" if total == 1 else "candidats"
    return f"{team_label} : {total} {plural}, tous fermés{breakdown}."


def _slot_capacity_by_key(model_data: Mapping[str, Any] | Any) -> dict[tuple[str, str, str], int]:
    """PLACES, not slots — mirrors ``model.slot_capacities``: a dict keyed on
    (venue, day, start), so duplicate triplets overwrite instead of adding, and a
    2-team slot counts 2. Counting ``len(slots)`` claimed « capacité insuffisante »
    on a club that had 87 places for 84 sessions (BCCL, 2026-08-06)."""
    capacities: dict[tuple[str, str, str], int] = {}
    for venue in _collection(model_data, "venues"):
        venue_id = str(_get(venue, "id", default=""))
        for slot in _collection(venue, "training_slots", "trainingSlots"):
            day = str(_get(slot, "day_of_week", "dayOfWeek", default=""))
            start = str(_get(slot, "start_time", "startTime", default=""))[:5]
            raw_capacity = _get(slot, "capacity", default=1)
            try:
                capacity = int(raw_capacity) if raw_capacity is not None else 1
            except (TypeError, ValueError):
                capacity = 1
            capacities[(venue_id, day, start)] = max(1, capacity)
    return capacities


def _saturated_venue_minimum(
    model_data: Mapping[str, Any] | Any,
    capacities: Mapping[tuple[str, str, str], int],
) -> tuple[str, int, int] | None:
    """Name the venue whose « au moins N ici » minimums outgrow its FREE places.

    The model enforces each minimum on its VARIABLES (sum >= N), and a HARD-locked
    triplet has no variable for anyone (``model.py:62-63``) — so the places a pin
    consumes are gone for every minimum. Demand = Σ of per-team minimums (max per
    team×venue: the model posts one ``>=`` per rule, the max dominates); free =
    Σ capacities of unpinned triplets. demand > free ⇒ provably INFEASIBLE."""
    min_by_venue_team: dict[str, dict[str, int]] = {}
    for row in _collection(model_data, "constraints"):
        config = _get(row, "config", default=None)
        config = config if isinstance(config, Mapping) else {}
        if (
            _get(row, "family", default=None) != "FACILITY"
            or not config.get("minAtVenueId")
            or _get(row, "ruleType", "rule_type", default=None) != "HARD"
            or _get(row, "scope", default=None) != "TEAM"
            or _get(row, "isActive", "is_active", default=True) is False
        ):
            continue
        team_id = _get(row, "scopeTargetId", "scope_target_id", default=None)
        if not team_id:
            continue
        raw_count = config.get("minAtVenueCount")
        minimum = max(1, int(raw_count) if raw_count is not None else 1)
        per_team = min_by_venue_team.setdefault(str(config["minAtVenueId"]), {})
        per_team[str(team_id)] = max(per_team.get(str(team_id), 0), minimum)

    if not min_by_venue_team:
        return None

    pinned: set[tuple[str, str, str]] = set()
    for pin in _collection(model_data, "slotTemplates", "slot_templates"):
        if _get(pin, "lockLevel", "lock_level", default=None) != "HARD":
            continue
        pinned.add(
            (
                str(_get(pin, "venueId", "venue_id", default="")),
                str(_get(pin, "dayOfWeek", "day_of_week", default="")),
                str(_get(pin, "startTime", "start_time", default=""))[:5],
            )
        )

    venue_names = {
        str(_get(venue, "id", default="")): str(_get(venue, "name", default="") or _get(venue, "id", default=""))
        for venue in _collection(model_data, "venues")
    }
    for venue_id, min_by_team in min_by_venue_team.items():
        demand = sum(min_by_team.values())
        free = sum(capacity for key, capacity in capacities.items() if key[0] == venue_id and key not in pinned)
        if demand > free:
            return venue_names.get(venue_id, venue_id), demand, free
    return None


def _infeasible_message(model_data: Mapping[str, Any] | Any) -> str:
    """Explain infeasibility in manager terms, hinting at capacity shortfall."""
    demand = 0
    for team in _collection(model_data, "teams"):
        spw = _get(team, "sessions_per_week", "sessionsPerWeek", default=None)
        try:
            demand += int(spw) if spw is not None else 0
        except (TypeError, ValueError):
            continue
    # P2-51 — une séance de bloc réunit N membres sur UNE place de gymnase : les N séances des
    # membres se replient en 1 occupation. La DEMANDE de places retranche donc (n_membres − 1) ×
    # commonSessions par bloc — sinon le message crie « 90 séances pour 76 places » alors que les
    # blocs rendent le compte faisable. Bloc absent ⇒ demande inchangée (chemin byte-identique).
    for block in _collection(model_data, "sharedBlocks", "shared_blocks"):
        member_ids = _get(block, "teamIds", "team_ids", default=[]) or []
        common_sessions = int(_get(block, "commonSessions", "common_sessions", default=0) or 0)
        if len(member_ids) >= 2 and common_sessions > 0:
            demand -= (len(member_ids) - 1) * common_sessions
    demand = max(demand, 0)
    capacities = _slot_capacity_by_key(model_data)
    supply = sum(capacities.values())

    base = (
        "Le planning n'a pas pu être généré : les contraintes actuelles sont impossibles à satisfaire toutes ensemble."
    )
    if demand and supply and demand > supply:
        return (
            f"{base} Il faut placer {demand} séance(s) par semaine pour seulement "
            f"{supply} place(s) de créneau déclarée(s) (capacités comprises) : "
            "la capacité est insuffisante."
        )
    saturated = _saturated_venue_minimum(model_data, capacities)
    if saturated is not None:
        venue_name, min_demand, free = saturated
        return (
            f"{base} Vos contraintes « au moins » réclament {min_demand} place(s) au "
            f"gymnase {venue_name}, qui n'en a que {free} de libre(s) une fois les "
            "créneaux réservés déduits : ce gymnase est saturé."
        )
    return (
        f"{base} Aucune affectation valide n'existe — cherchez des contraintes dures "
        "qui se contredisent (jour/heure imposés, gymnase forcé, créneaux verrouillés)."
    )
