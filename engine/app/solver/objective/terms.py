"""Level-2 objective — constructeurs de termes SOFT ``add_*`` + stabilité (paquet ENG-39).

Extrait tel quel de l'ancien monolithe ``objective.py`` (déplacement pur, ENG-39). Dépend de
``weights`` (barèmes), ``normalise`` (lecture des assignments), et des modules solveur
``compromise`` / ``constraints`` / ``model``. L'agrégateur ``__init__`` consomme ces
constructeurs ; ce module NE dépend PAS de l'agrégateur.
"""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from typing import Any, cast

from ..compromise import (
    FAMILY_COACH_DAY_CAP,
    FAMILY_DAY,
    FAMILY_MATCH_REST,
    FAMILY_SPACING,
    FAMILY_TEAM_LINK,
    FAMILY_TIME,
    FAMILY_VENUE,
    CompromiseTermInfo,
)
from ..constraints import (
    MANDATORY,
    PREFERRED,
    iter_team_link_overlaps,
    team_link_placements_by_team,
    team_share_declared_pairs,
)
from ..model import _format_time, _time_to_minutes
from .normalise import (
    _assignment_key,
    _get,
    _get_slot_id,
    _get_venue_id,
    _higher_tier,
    _normalise_assignments,
    _parse_time_minutes,
    _person_ids_for,
    _priority_tier_name,
    _scalar_id,
    _teams_by_id,
    _var,
)
from .weights import CHAINING_TIER_WEIGHTS, STABILITY_TERM_WEIGHT, TEAM_LINK_TIER_WEIGHTS

AssignmentLike = Any
BoolVarLike = Any


def build_stability_terms(
    x: Mapping[Any, BoolVarLike],
    previous_assignments: Iterable[Mapping[str, Any]] | None,
) -> list[tuple[BoolVarLike, int]]:
    """P3-21 — termes de stabilité : +STABILITY_TERM_WEIGHT par variable dont la clé
    ``(team_id, venue_id, day_of_week, start)`` figure dans ``previous_assignments``.

    La clé est normalisée EXACTEMENT comme ``model.x`` (start passé par
    ``_format_time(_time_to_minutes(...))``). Un créneau HARD est absent de ``x`` (pas de
    variable) → jamais compté : pas de double paiement d'un pin. Dédup par clé. Champ
    vide/None → ``[]`` (aucun terme) : l'appelant garde alors le chemin phase-2 historique."""
    terms: list[tuple[BoolVarLike, int]] = []
    if not previous_assignments:
        return terms

    seen: set[tuple[str, str, int, str]] = set()
    for prev in previous_assignments:
        team_id = _scalar_id(_get(prev, "teamId", "team_id", default=None))
        venue_id = _scalar_id(_get(prev, "venueId", "venue_id", default=None))
        day = _get(prev, "dayOfWeek", "day_of_week", default=None)
        start = _get(prev, "startTime", "start_time", default=None)
        if team_id is None or venue_id is None or day is None or start is None:
            continue
        try:
            slot_key = (str(team_id), str(venue_id), int(_scalar_id(day)), _format_time(_time_to_minutes(start)))
        except (TypeError, ValueError):
            continue
        if slot_key in seen:
            continue
        seen.add(slot_key)
        var = x.get(slot_key)
        if var is not None:
            terms.append((var, STABILITY_TERM_WEIGHT))

    return terms


def add_venue_preference_bonus(
    x: Mapping[Any, BoolVarLike],
    parsed: Mapping[str, Any],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Termes soft « gymnase préféré / à éviter » — maison unique génération ⇄ évaluation (D-6).

    Extrait tel quel de l'assemblage inline de ``main.build_schedule`` : le bonus ``preferred``
    tombe sur tout créneau d'un gymnase préféré de l'équipe, le MALUS ``avoided_venue`` sur tout
    créneau d'un gymnase à éviter (un vrai malus, pas un bonus-complément qui biaiserait
    l'allocation inter-équipes). ``info_out`` (défaut None → chemin /generate byte-identique)
    récolte la métadonnée de nommage des compromis.
    """
    preferred_venues: dict[str, set[str]] = parsed.get("preferred_venues", {}) or {}
    avoided_by_team: dict[str, set[str]] = {}
    for avoided in parsed.get("avoided_venues", []) or []:
        avoided_by_team.setdefault(avoided["scope_target_id"], set()).add(avoided["venue_id"])

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for slot_key, var in x.items():
        team_id = str(slot_key[0])
        venue_id = str(slot_key[1])
        preferred_set = preferred_venues.get(team_id)
        if preferred_set is not None and venue_id in preferred_set:
            soft_terms.append((var, "preferred"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=True,
                        key=(FAMILY_VENUE, team_id, venue_id, "preferred"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="preferred",
                    )
                )
        avoided_set = avoided_by_team.get(team_id)
        if avoided_set is not None and venue_id in avoided_set:
            soft_terms.append((var, "avoided_venue"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=False,
                        key=(FAMILY_VENUE, team_id, venue_id, "avoided"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="avoided",
                    )
                )

    return soft_terms


def add_coach_day_cap_penalty(
    model: Any,
    x: Mapping[Any, BoolVarLike],
    coaches: Iterable[Any],
    team_coach_map: Mapping[str, list[str]] | None,
    weights: Mapping[str, int],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """P4-51 — le plafond de jours d'un coach, en termes SOFT (arbitrage : préféré, pas dur).

    ⚑ Avant cette fonction, ``maxDaysOverride`` n'était appliqué NULLE PART. Pire : la
    contrainte du jour de repos SAUTAIT les coachs dont l'override est ≤ 4, au motif —
    faux — que « le plafond garantit déjà le repos ». Régler « max 3 jours » sur un coach
    RETIRAIT donc sa garantie de repos sans rien plafonner : l'inverse exact du libellé.
    Le skip est mort (`add_coach_rest_day_constraints`), et le plafond agit ici.

    Un littéral par jour au-delà du plafond : ``over_d`` est vrai ssi le coach travaille
    au moins *d* jours (d > plafond). Chaque littéral actif coûte ``overload_day``. Un
    dépassement de 2 jours coûte donc 2 × 15 — proportionnel, et purement booléen.

    Les jours comptés vont de 1 à 7 : le diagnostic ``coach_overload`` (ENG-24) compte
    tous les jours travaillés, samedi compris — l'objectif doit compter comme lui, sinon
    le solveur optimise une définition et le récap en juge une autre.
    """

    terms: list[tuple[BoolVarLike, str]] = []
    if not team_coach_map:
        return terms

    caps: dict[str, int] = {}
    for coach in coaches:
        raw = coach.get("maxDaysOverride") if isinstance(coach, Mapping) else getattr(coach, "max_days_override", None)
        if raw is None and isinstance(coach, Mapping):
            raw = coach.get("max_days_override")
        cid = coach.get("id") if isinstance(coach, Mapping) else getattr(coach, "id", None)
        if cid is not None and raw is not None and 0 < int(raw) < 7:
            caps[str(cid)] = int(raw)

    if not caps:
        return terms

    day_vars: dict[tuple[str, int], list[BoolVarLike]] = {}
    for key, var in x.items():
        team_id, _venue_id, day_of_week, _start = key
        for coach_id in team_coach_map.get(str(team_id), []):
            if str(coach_id) in caps:
                day_vars.setdefault((str(coach_id), int(day_of_week)), []).append(var)

    for coach_id, cap in caps.items():
        is_working: list[BoolVarLike] = []
        for day in range(1, 8):
            vars_of_day = day_vars.get((coach_id, day), [])
            if not vars_of_day:
                continue
            working = cast(Any, model).NewBoolVar(f"cap_is_working_{coach_id}_d{day}")
            day_sum = sum(cast(Any, v) for v in vars_of_day)
            cast(Any, model).Add(day_sum >= 1).OnlyEnforceIf(working)
            cast(Any, model).Add(day_sum == 0).OnlyEnforceIf(working.Not())
            is_working.append(working)

        total = sum(cast(Any, w) for w in is_working)
        for over in range(cap + 1, len(is_working) + 1):
            literal = cast(Any, model).NewBoolVar(f"cap_over_{coach_id}_{over}")
            cast(Any, model).Add(total >= over).OnlyEnforceIf(literal)
            cast(Any, model).Add(total <= over - 1).OnlyEnforceIf(literal.Not())
            terms.append((literal, "overload_day"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=literal,
                        family=FAMILY_COACH_DAY_CAP,
                        honored_when_active=False,
                        key=(FAMILY_COACH_DAY_CAP, coach_id),
                        coach_id=coach_id,
                    )
                )

    return terms


def add_missing_session_penalty(
    model: Any,
    assignments_by_team: Mapping[Any, Sequence[BoolVarLike]],
    remaining_by_team: Mapping[str, int],
    weights: Mapping[str, int],
    *,
    hard_satisfied_team_ids: set[str] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """V10 — LE REMPLISSAGE PRIME SUR LE CONFORT : un malus PAR séance sous le quota.

    Pour chaque équipe ayant des variables candidates, ``remaining`` = le nombre de séances
    encore à placer après crédit des verrous HARD (``max(0, spw − verrous HARD)``), fourni
    par ``remaining_by_team`` — la MÊME source que la borne ``sum(vars) <= remaining`` posée
    dans ``build_schedule`` (une seule définition de « combien reste-t-il à placer »).

    Pour ``m`` de 1 à ``remaining``, un littéral ``miss_m`` est vrai ssi ``sum(vars) <=
    remaining − m`` : il compte « au moins m séances manquent ». Chaque littéral actif coûte
    ``missing_session`` (−1000) : 1 manquante → −1000, 2 → −2000, monotone. Une équipe
    satisfaite par des verrous HARD (``remaining`` ≤ 0, ou ``hard_satisfied_team_ids``) ou
    sans variable candidate n'émet AUCUN littéral (ni malus indu, ni terme mort).

    Complète ``UNPLACED_PENALTY`` sans le remplacer : une équipe à zéro paie les deux
    (100000 + spw × 1000). Voir la preuve d'empilement (P3/P4/P5) sur ``missing_session``
    dans ``LEVEL_2_OBJECTIVE_WEIGHTS``.
    """

    if "missing_session" not in weights:
        raise KeyError("missing_session")

    terms: list[tuple[BoolVarLike, str]] = []
    for team_id, team_vars in assignments_by_team.items():
        if not team_vars:
            continue
        if hard_satisfied_team_ids is not None and str(team_id) in hard_satisfied_team_ids:
            continue
        remaining = int(remaining_by_team.get(str(team_id), 0))
        if remaining <= 0:
            continue
        team_sum = sum(cast(Any, v) for v in team_vars)
        for m in range(1, remaining + 1):
            miss = cast(Any, model).NewBoolVar(f"miss_{team_id}_{m}")
            cast(Any, model).Add(team_sum <= remaining - m).OnlyEnforceIf(miss)
            cast(Any, model).Add(team_sum >= remaining - m + 1).OnlyEnforceIf(miss.Not())
            terms.append((miss, "missing_session"))

    return terms


def add_team_link_penalty(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    team_links: Iterable[Any] = (),
    shared_trainings: Iterable[Any] = (),
    teams: Iterable[Any] = (),
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Lot PASSERELLES PR-2 — MALUS d'objectif par chevauchement d'une passerelle ``PREFERRED``.

    Pour chaque passerelle PREFERRED (deux équipes partageant des joueurs) et chaque paire de
    placements CHEVAUCHANTS non exemptée (``constraints.iter_team_link_overlaps`` — même géométrie
    et même exemption doctrinale que la pose HARD), un malus ``−TEAM_LINK_TIER_WEIGHTS[tier]`` est
    posé, ``tier`` = la PLUS HAUTE des deux équipes. Le maximiseur pousse alors les deux séances à
    ne PAS coïncider quand c'est possible, sans jamais SUPPRIMER une séance (preuve d'empilement
    sur ``TEAM_LINK_TIER_WEIGHTS``).

    Le littéral pénalisé « les deux séances sont posées » :
      * libre ⇔ libre : un ``ov`` réifié par ``ov >= var_a + var_b − 1`` (le maximiseur, malus
        négatif, le maintient à ``max(0, var_a+var_b−1)``) ;
      * libre ⇔ verrouillé : la séance verrouillée est TOUJOURS là, donc le littéral EST la
        variable libre — pénaliser ``var`` décourage de la poser en chevauchement ;
      * verrou ⇔ verrou : les deux constantes, aucune variable — rien à pénaliser (le
        chevauchement, s'il subsiste, est ANNONCÉ par ``result_builder._diagnose_team_links``).

    ``info_out`` (chemin ``/validate-assignments``) reçoit un ``CompromiseTermInfo`` par littéral
    (MALUS : ``honored_when_active=False``) pour que le rail des compromis (P2-32) NOMME le
    chevauchement créé par un déplacement accepté (arbitrage n°4). ``team_links`` vide/tout
    MANDATORY ⇒ ``[]`` (chemin byte-identique, goldens inchangés)."""
    preferred = [link for link in (team_links or ()) if str(_get(link, "intensity", default=PREFERRED)) != MANDATORY]
    if not preferred:
        return []

    placements = team_link_placements_by_team(assignments, getattr(model, "locked_slots", ()) or ())
    share_pairs = team_share_declared_pairs(shared_trainings)
    teams_by_id = _teams_by_id(teams)

    def _tier_of(team_id: str) -> str:
        return _priority_tier_name({"team_id": team_id}, teams_by_id)

    terms: list[tuple[BoolVarLike, int]] = []
    for link in preferred:
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        link_id = str(_get(link, "id", default=f"{team_a}_{team_b}"))
        share_declared = frozenset({team_a, team_b}) in share_pairs
        try:
            weight = TEAM_LINK_TIER_WEIGHTS.get(_higher_tier(_tier_of(team_a), _tier_of(team_b)), 0)
        except ValueError:
            # Une équipe sans tier exploitable : on ne fabrique pas de poids, on n'oriente pas.
            continue
        if weight == 0:
            continue

        pair_index = 0
        for (a_start, _a_end, a_day, a_venue, a_var), (_bs, _be, _bd, _bv, b_var) in iter_team_link_overlaps(
            placements.get(team_a, []), placements.get(team_b, []), share_declared=share_declared
        ):
            if a_var is not None and b_var is not None:
                overlap = model.NewBoolVar(f"team_link_{link_id}_{pair_index}".replace(":", "_"))
                model.Add(overlap >= a_var + b_var - 1)
                penalized: BoolVarLike = overlap
            elif a_var is not None:  # b verrouillé, toujours présent → chevauchement ssi a posée.
                penalized = a_var
            elif b_var is not None:
                penalized = b_var
            else:
                continue  # deux verrous : constante, rien à orienter (diagnostiqué post-solve).
            pair_index += 1
            terms.append((penalized, -int(weight)))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=penalized,
                        family=FAMILY_TEAM_LINK,
                        honored_when_active=False,
                        key=(FAMILY_TEAM_LINK, link_id),
                        team_id=team_a,
                        venue_id=a_venue,
                        day_of_week=a_day,
                        start_time=_format_time(a_start),
                    )
                )
    return terms


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
        except (TypeError, ValueError):
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
        except (TypeError, ValueError):
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
        except (TypeError, ValueError):
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


def add_chaining_bonus(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    teams: Iterable[Any] = (),
    team_player_map: Mapping[str, list[str]] | None = None,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Build SOFT bonus terms for same-venue back-to-back sessions.

    For each pair of consecutive slots (A, B) in the same venue on the same
    day where A.end == B.start, and for each PERSON present at both slots —
    a coach of the session OR a player of its team (see *team_player_map*) —
    create a ``chained`` BoolVar that is true when both sessions are placed.
    The bonus weight is ``CHAINING_TIER_WEIGHTS[tier]`` where the tier is the
    highest-tier team across the two sessions.

    *team_player_map* maps ``str(team_id) -> [person_id, ...]`` (built from the
    coach/player links). With it None, only coaches count and the result is
    byte-identical to the coach-only behaviour.

    Returns a list of ``(chained_var, weight)`` terms. The caller MUST fold
    these into its single ``model.Maximize(...)`` — this function must not call
    Maximize itself, or CP-SAT's single-objective model would drop them.
    """

    assignment_list = _normalise_assignments(assignments)
    if len(assignment_list) < 2:
        return []

    teams_by_id = _teams_by_id(teams)

    slot_lookup: dict[tuple[str, str, int], list[dict[str, Any]]] = {}

    for assignment in assignment_list:
        venue_id = _get_venue_id(assignment)
        slot_id = _get_slot_id(assignment)
        if venue_id is None or slot_id is None:
            continue

        slot_id_str = str(slot_id)
        parts = slot_id_str.split(":", 1)
        if len(parts) != 2:
            continue

        day = parts[0]
        start_minutes = _parse_time_minutes(parts[1])
        if start_minutes is None:
            continue

        start_val = _get(assignment, "start", "start_minute", "starts_at", default=None)
        end_val = _get(assignment, "end", "end_minute", "ends_at", default=None)

        start_min = int(start_val) if start_val is not None else start_minutes
        end_min = int(end_val) if end_val is not None else None

        key = (str(venue_id), day, start_min)
        slot_lookup.setdefault(key, []).append(
            {
                "assignment": assignment,
                "start": start_min,
                "end": end_min,
            }
        )

    chaining_pairs: list[tuple[BoolVarLike, int]] = []
    seen_pairs: set[tuple[str, str]] = set()

    for key, entries in slot_lookup.items():
        venue_id, day, _start_min = key
        for entry in entries:
            end_min = entry["end"]
            if end_min is None:
                continue

            next_key = (venue_id, day, end_min)
            next_entries = slot_lookup.get(next_key)
            if next_entries is None:
                continue

            for next_entry in next_entries:
                pair_id_a = str(_assignment_key(entry["assignment"], _var(entry["assignment"])))
                pair_id_b = str(_assignment_key(next_entry["assignment"], _var(next_entry["assignment"])))
                pair_key = (pair_id_a, pair_id_b)
                if pair_key in seen_pairs:
                    continue
                seen_pairs.add(pair_key)

                persons_a = _person_ids_for(entry["assignment"], team_player_map)
                persons_b = _person_ids_for(next_entry["assignment"], team_player_map)
                common_persons = persons_a & persons_b

                for person_id in common_persons:
                    tier_a = _priority_tier_name(entry["assignment"], teams_by_id)
                    tier_b = _priority_tier_name(next_entry["assignment"], teams_by_id)
                    highest_tier = _higher_tier(tier_a, tier_b)
                    weight = CHAINING_TIER_WEIGHTS.get(highest_tier, 0)
                    if weight == 0:
                        continue

                    var_a = _var(entry["assignment"])
                    var_b = _var(next_entry["assignment"])
                    # Cheap encoding: `chained` only ever appears in the objective
                    # with a positive weight, so two linear upper bounds suffice —
                    # the maximiser pushes it to min(var_a, var_b) = "both placed".
                    # Avoids the reified AddBoolAnd/AddBoolOr + OnlyEnforceIf, which
                    # blow up the model on real datasets (BCCL solve > 30 s).
                    chained = model.NewBoolVar(f"chained_{person_id}_{pair_id_a}_{pair_id_b}")
                    model.Add(chained <= var_a)
                    model.Add(chained <= var_b)

                    chaining_pairs.append((chained, int(weight)))
                    if info_out is not None:
                        try:
                            day_int: int | None = int(day)
                        except (TypeError, ValueError):
                            day_int = None
                        info_out.append(
                            CompromiseTermInfo(
                                var=chained,
                                family="chaining",
                                honored_when_active=True,
                                key=("chaining", str(person_id), venue_id, day, pair_id_a, pair_id_b),
                                venue_id=venue_id,
                                day_of_week=day_int,
                            )
                        )

    return chaining_pairs
