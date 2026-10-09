"""Level-2 objective — bonus PREFERRED jour/heure et son constructeur partagé (sous-module ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise``, ``normalise`` et ``model`` (``_time_to_minutes`` via ``_safe_minutes``). Les
fonctions de ce module s'appellent entre elles (``_add_preferred_bonus`` → ``_group_team_slots`` →
``_safe_minutes``) ; aucune arête vers un autre sous-module de ``terms`` ; l'agrégateur
``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ...compromise import FAMILY_DAY, FAMILY_TIME, CompromiseTermInfo
from ...model import _time_to_minutes
from ..normalise import _get, _scalar_id

BoolVarLike = Any


def _group_team_slots(
    x: Mapping[Any, BoolVarLike],
) -> dict[str, list[tuple[Any, int | None, int | None, BoolVarLike]]]:
    """Group vars by team once (O(slots)): {team: [(slot_key, day, start_min, var)]}."""
    grouped: dict[str, list[tuple[Any, int | None, int | None, BoolVarLike]]] = {}
    for slot_key, variable in x.items():
        if not isinstance(slot_key, tuple) or len(slot_key) < 4:
            continue
        try:
            day: int | None = int(_scalar_id(slot_key[2]))
        except (TypeError, ValueError):
            day = None
        grouped.setdefault(str(_scalar_id(slot_key[0])), []).append(
            (slot_key, day, _safe_minutes(slot_key[3]), variable)
        )
    return grouped


def _add_preferred_bonus(
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    family: str,
    weight_name: str,
    criterion: Any,
    matches: Any,
    info_out: list[CompromiseTermInfo] | None = None,
    compromise_family: str | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Shared soft-bonus builder for PREFERRED+<family> windows.

    ``criterion(config)`` extracts a per-window value (or None to skip the
    window); ``matches(day, start_min, crit)`` decides whether a slot earns the
    bonus. This factors add_preferred_day_bonus / add_preferred_time_bonus into
    one place (audit review F3) — a fix to the slot-matching/dedup logic now
    lives once.

    ``info_out``/``compromise_family`` (défaut None → chemin /generate byte-identique)
    récoltent la métadonnée de nommage des compromis, AGRÉGÉE par équipe (une entrée par
    (famille, équipe) : deux créneaux préférés d'une même équipe ne comptent qu'une préférence).
    """
    if weight_name not in weights:
        raise KeyError(weight_name)

    grouped = _group_team_slots(x)
    soft_terms: list[tuple[BoolVarLike, str]] = []
    seen_keys: set[Any] = set()

    for time_window in time_windows:
        if _get(time_window, "ruleType", "rule_type", default=None) != "PREFERRED":
            continue
        if _get(time_window, "family", default=None) != family:
            continue
        team_id = _scalar_id(_get(time_window, "scope_target_id", "scopeTargetId", "team_id", "teamId", default=None))
        if team_id is None:
            continue

        crit = criterion(_get(time_window, "config", default={}) or {})
        if crit is None:
            continue

        for slot_key, day, start_min, variable in grouped.get(str(team_id), []):
            if slot_key in seen_keys:
                continue
            if matches(day, start_min, crit):
                soft_terms.append((variable, weight_name))
                seen_keys.add(slot_key)
                if info_out is not None and compromise_family is not None:
                    info_out.append(
                        CompromiseTermInfo(
                            var=variable,
                            family=compromise_family,
                            honored_when_active=True,
                            key=(compromise_family, str(team_id)),
                            team_id=str(team_id),
                        )
                    )

    return soft_terms


def add_preferred_day_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms for preferred-day windows.

    Two config shapes are honored (ENG-10 — the wizard only ever emits
    ``forbiddenDays`` whatever the ruleType, so a PREFERRED day rule used to be
    a silent placebo when only ``preferredDays`` was read):
    - ``preferredDays``: bonus on those days;
    - ``forbiddenDays`` on a PREFERRED rule: bonus on every day OUTSIDE the set
      — the positive complement of "avoid these days" (equivalent malus, keeps
      all objective coefficients positive). ``preferredDays`` wins when both
      are present.
    """
    del model

    def day_set(config: Mapping[str, Any], key: str) -> set[int]:
        """SEC-13 — UNE seule orthographe : le camelCase du contrat.

        Cette fonction acceptait aussi un alias snake_case (`preferred_days`,
        `forbidden_days`). Personne ne l'a jamais émis, et depuis SEC-13 l'API
        REFUSE les clés hors liste blanche : garder l'alias, c'était garder deux
        façons d'écrire la même règle — donc deux façons de la chercher le jour
        où elle ne s'applique pas. La liste blanche du backend et ce que lit le
        moteur sont désormais le MÊME ensemble, et le job CI « Engine semantics »
        le vérifie clé par clé, par le comportement.
        """
        days: set[int] = set()
        for value in config.get(key) or ():
            try:
                days.add(int(_scalar_id(value)))
            except (TypeError, ValueError):
                continue
        return days

    # AGGREGATE the PREFERRED DAY windows per team FIRST: two independent
    # "avoid Monday" + "avoid Wednesday" complements would otherwise cancel
    # each other through the shared per-slot dedup (each window bonusing the
    # other's avoided day → flat objective, both preferences ignored). One
    # synthetic window per team, avoided = union, preferred = union.
    preferred_by_team: dict[str, set[int]] = {}
    avoided_by_team: dict[str, set[int]] = {}
    for time_window in time_windows:
        if _get(time_window, "ruleType", "rule_type", default=None) != "PREFERRED":
            continue
        if _get(time_window, "family", default=None) != "DAY":
            continue
        team_id = _scalar_id(_get(time_window, "scope_target_id", "scopeTargetId", "team_id", "teamId", default=None))
        if team_id is None:
            continue
        config = _get(time_window, "config", default={}) or {}
        preferred_by_team.setdefault(str(team_id), set()).update(day_set(config, "preferredDays"))
        avoided_by_team.setdefault(str(team_id), set()).update(day_set(config, "forbiddenDays"))

    synthetic_windows: list[dict[str, Any]] = []
    # ENG-25 — `sorted`, et pas seulement pour la forme : ces clés sont des `str`,
    # dont le hash est randomisé par processus (PYTHONHASHSEED). Sans tri, l'ordre
    # des fenêtres synthétiques changeait d'un PROCESSUS à l'autre, donc l'ordre
    # d'ajout des termes à l'objectif, donc le chemin de recherche de CP-SAT : deux
    # runs du MÊME payload avec le MÊME `solverSeed` pouvaient rendre deux
    # affectations différentes (de valeur d'objectif identique — mais un
    # gestionnaire qui régénère à l'identique voyait son planning bouger).
    # ⚠ On NE fige PAS `PYTHONHASHSEED` : ce serait traiter le symptôme, et le
    # figer désarme la protection contre les collisions de hash. L'ordre se
    # décide là où il compte.
    for team_id in sorted(preferred_by_team.keys() | avoided_by_team.keys()):
        preferred = preferred_by_team.get(team_id) or set()
        avoided = avoided_by_team.get(team_id) or set()
        if not preferred and not avoided:
            continue
        synthetic_windows.append(
            {
                "ruleType": "PREFERRED",
                "family": "DAY",
                "scope_target_id": team_id,
                "config": {"preferredDays": sorted(preferred), "forbiddenDays": sorted(avoided)},
            }
        )

    def criterion(config: Mapping[str, Any]) -> tuple[set[int], set[int]] | None:
        preferred = day_set(config, "preferredDays")
        avoided = day_set(config, "forbiddenDays")
        if not preferred and not avoided:
            return None
        return (preferred, avoided)

    def matches(day: int | None, _start: Any, crit: tuple[set[int], set[int]]) -> bool:
        if day is None:
            return False
        preferred, avoided = crit
        if preferred:
            # Explicit preferred days win; a day both preferred and avoided is
            # contradictory — preferred keeps it.
            return day in preferred
        return day not in avoided

    return _add_preferred_bonus(
        x,
        synthetic_windows,
        weights,
        family="DAY",
        weight_name="preferred_day",
        criterion=criterion,
        matches=matches,
        info_out=info_out,
        compromise_family=FAMILY_DAY,
    )


def add_preferred_time_bonus(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    time_windows: Iterable[Any],
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Return soft objective terms for PREFERRED TIME windows.

    A PREFERRED+TIME constraint rewards a team's sessions starting inside
    [minStartTime, maxStartTime] (either bound absent = unconstrained on that
    side). Soft only — never a hard window. A malformed bound is ignored, not a
    500 (audit review).
    """
    del model

    def criterion(config: Mapping[str, Any]) -> tuple[int | None, int | None] | None:
        lo = _safe_minutes(config.get("minStartTime"))
        hi = _safe_minutes(config.get("maxStartTime"))
        return None if lo is None and hi is None else (lo, hi)

    def matches(_day: int | None, start_min: int | None, bounds: tuple[int | None, int | None]) -> bool:
        lo, hi = bounds
        if start_min is None:
            return False
        if lo is not None and start_min < lo:
            return False
        return not (hi is not None and start_min > hi)

    return _add_preferred_bonus(
        x,
        time_windows,
        weights,
        family="TIME",
        weight_name="preferred_time",
        criterion=criterion,
        matches=matches,
        info_out=info_out,
        compromise_family=FAMILY_TIME,
    )


def _safe_minutes(value: Any) -> int | None:
    if value is None:
        return None
    try:
        return _time_to_minutes(value)
    except (TypeError, ValueError):
        return None
