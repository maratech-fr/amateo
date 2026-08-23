"""v2 constraints[] payload -> ParsedConstraints, plus implicit-rule resolution.

Imports ``..`` externals and ``.common`` (types/intensities/day coercion). The
solve orchestrator (``main``) and ``add_level_1_hard_constraints`` consume its
output; no sibling imports it back."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from ..model import _time_to_minutes
from .common import (
    HARD,
    OFF,
    PREFERRED,
    ParsedConstraints,
    ResolvedImplicitRules,
    _day_int_set,
    _not_honored_warning,
    logger,
)

# Recognised constraint discriminators (a v2 unified `family` or a v1 `type`).
# Used to warn ONLY on genuine contract drift, not on recognised families whose
# specific config variant is intentionally a no-op.
_KNOWN_FAMILIES = frozenset({"TIME", "DAY", "FACILITY", "COACH_AVAILABILITY"})
_KNOWN_TYPES = frozenset({"TEAM_COACH", "COACH_PLAYER_UNAVAILABILITY", "PRIORITY_TIER"})


def _rule_block(raw: Mapping[str, Any], *names: str) -> Mapping[str, Any] | None:
    for name in names:
        block = raw.get(name)
        if isinstance(block, Mapping):
            return block
    return None


def _intensity(block: Mapping[str, Any] | None) -> str:
    if block is None:
        return HARD
    value = str(block.get("intensity") or HARD).upper()
    return PREFERRED if value == PREFERRED else HARD


def resolve_implicit_rules(raw: Mapping[str, Any] | None) -> ResolvedImplicitRules:
    """Normalise le bloc ``implicitRules`` (aliases camelCase du dump ``by_alias``, ou
    snake_case défensif) en réglage effectif. ``None`` ou bloc vide = défauts historiques.
    Les bornes sont déjà validées par Pydantic ; ici on se contente de lire avec repli."""
    if not isinstance(raw, Mapping):
        return ResolvedImplicitRules()

    rest = _rule_block(raw, "coachRestDay", "coach_rest_day")
    salarie = _rule_block(raw, "salarieDistribution", "salarie_distribution")
    chain = _rule_block(raw, "maxConsecutiveSessions", "max_consecutive_sessions")
    days = _rule_block(raw, "maxConsecutiveDays", "max_consecutive_days")
    age = _rule_block(raw, "ageAscending", "age_ascending")

    def _int(block: Mapping[str, Any] | None, default: int, *names: str) -> int:
        if block is None:
            return default
        for name in names:
            value = block.get(name)
            if value is not None:
                try:
                    return int(value)
                except (TypeError, ValueError):
                    return default
        return default

    return ResolvedImplicitRules(
        coach_rest_day_intensity=_intensity(rest),
        min_rest_days=_int(rest, 1, "minRestDays", "min_rest_days"),
        salarie_distribution_intensity=_intensity(salarie),
        max_consecutive_sessions_intensity=_intensity(chain),
        max_consecutive=_int(chain, 3, "maxConsecutive", "max_consecutive"),
        max_consecutive_days_intensity=OFF if days is None else _intensity(days),
        max_consecutive_days=_int(days, 3, "maxConsecutiveDays", "max_consecutive_days"),
        age_ascending_intensity=_intensity(age),
    )


def _coach_window_minutes(config: Mapping[str, Any]) -> tuple[int, int]:
    """Blocked-window bounds (minutes) for a COACH_AVAILABILITY config (Lot C).

    Defensive on purpose (audit review): the backend HH:MM / from<until check
    runs ONLY on the advisory /api/constraints/validate gate — the write,
    generate and regenerate paths reach the solver ungated. So a MALFORMED time
    (e.g. "9h", "25:00") or an INVERTED window (from >= to, e.g. an overnight
    "20:00–08:00" the flat model can't wrap) must not crash the whole solve nor
    silently drop the rule: both fall back to the whole day (0, 1440), which
    HONORS the coach's declared unavailability conservatively. A missing bound
    defaults to whole-day (0 / 1440) = legacy day-level behaviour.
    """

    def _bound(key: str, default: int) -> int | None:
        raw = config.get(key)
        if not raw:
            return default
        try:
            return _time_to_minutes(raw)
        except (ValueError, TypeError):
            return None

    from_min = _bound("fromTime", 0)
    to_min = _bound("untilTime", 1440)
    if from_min is None or to_min is None or from_min >= to_min:
        return 0, 1440
    return from_min, to_min


def _set_venue_rule(
    rules: dict[str, str],
    team_id: str,
    venue_id: str,
    constraint: dict[str, Any],
    warnings: list[dict[str, Any]],
    sources: dict[str, dict[str, Any]] | None = None,
) -> None:
    """Single-venue-per-team rule maps are last-wins by structure — surface a
    conflicting overwrite instead of silently dropping the earlier rule (the
    same silent-overwrite class as ENG-13). Since PR B this only guards the
    HARD map (`forced_venues`) — soft preferences accumulate into a set.

    P4-99 — `sources` (facultatif) reçoit, en last-wins comme `rules`, la contrainte qui
    force ce gymnase, pour nommer la cause `forced_venue_elsewhere`."""
    existing = rules.get(team_id)
    if existing is not None and existing != venue_id:
        warnings.append(
            _not_honored_warning(
                constraint,
                "INFO",
                "Plusieurs règles de gymnase pour la même équipe — la dernière remplace la précédente.",
            )
        )
    rules[team_id] = venue_id
    if sources is not None:
        sources[team_id] = {"constraint_id": constraint.get("id"), "label": constraint.get("name")}


def parse_v2_constraints(constraints: list[dict[str, Any]]) -> ParsedConstraints:
    """Parse v2 constraints[] array into typed, solver-ready rule collections."""

    result: ParsedConstraints = {
        "forbidden_assignments": [],
        "coach_unavailability": {},
        "coach_unavailability_sources": {},
        "forced_venues": {},
        "forced_venue_sources": {},
        "preferred_venues": {},
        "avoided_venues": [],
        "venue_minimums": [],
        "time_windows": [],
        "priority_tiers": {},
        "team_coach_map": {},
        "team_player_map": {},
        "parse_warnings": [],
    }

    # Per-coach availability accumulators (merged after the loop — see the
    # COACH_AVAILABILITY branch — accumulate blocked (day, from, to) intervals with
    # UNION semantics. By De Morgan this expresses both the blacklist UNION and the
    # whitelist INTERSECTION (complement of an available window = blocked parts), so
    # no separate merge step is needed (ENG-13 algebra preserved, now with time).
    coach_blocked_intervals: dict[str, set[tuple[int, int, int]]] = {}

    for c in constraints:
        if not c.get("isActive", True):
            continue
        rule_type = c.get("ruleType") or c.get("rule_type")
        c_type = c.get("type")
        family = c.get("family")
        scope = c.get("scope")
        scope_target_id = c.get("scopeTargetId") or c.get("scope_target_id")
        config = c.get("config") or {}
        metadata = c.get("metadata") or {}

        # BONUS never had a distinct semantic anywhere (no weight, no branch) —
        # the UI no longer offers it; legacy rows are honored as PREFERRED
        # (soft), which is more honest than silently dropping them (ENG-12).
        if rule_type == "BONUS":
            rule_type = "PREFERRED"
            c = {**c, "ruleType": "PREFERRED"}

        if rule_type == "LOCK" and family in ("TIME", "DAY"):
            # A LOCK on a time/day rule means "keep this window fixed" — same
            # effect as HARD for the solver. Route it through time_windows;
            # add_time_window_constraints treats LOCK as HARD.
            result["time_windows"].append(c)

        elif c_type == "TEAM_COACH":
            team_id = c.get("teamId") or c.get("team_id") or scope_target_id
            coach_id = (
                metadata.get("coachId")
                or metadata.get("coach_id")
                or c.get("value")
                or config.get("coachId")
                or config.get("coach_id")
            )
            # Only the MAIN coach is a HARD no-overlap resource: a team never
            # trains without its head coach, so the head coach is implicitly
            # present at every session. An ASSISTANT is optional and must NOT
            # block placement (e.g. a team can be scheduled while the assistant
            # is busy elsewhere). Missing role → treated as MAIN (legacy-safe).
            role = str(metadata.get("role") or "MAIN").strip().upper()
            if team_id and coach_id and role != "ASSISTANT":
                team_id_str = str(team_id)
                coach_id_str = str(coach_id)
                result["team_coach_map"].setdefault(team_id_str, []).append(coach_id_str)

        elif c_type == "COACH_PLAYER_UNAVAILABILITY":
            team_id = (
                metadata.get("teamId")
                or metadata.get("team_id")
                or c.get("teamId")
                or c.get("team_id")
                or scope_target_id
            )
            coach_id = metadata.get("coachId") or metadata.get("coach_id") or c.get("value")
            if team_id and coach_id:
                team_id_str = str(team_id)
                coach_id_str = str(coach_id)
                result["team_player_map"].setdefault(team_id_str, []).append(coach_id_str)

        elif family == "COACH_AVAILABILITY" and scope_target_id:
            # Days are weekday numbers (ints, as the wizard sends them). Store a
            # set of unavailable days; a non-empty availableDays whitelist is the
            # complement. An empty/absent availableDays adds no restriction (an
            # empty whitelist is treated as "unconfigured", never "blocked every
            # day" — which would force the team to zero sessions).
            # Multiple constraints on one coach combine as a UNION of blocked
            # (day, from, to) intervals accumulated inline in coach_blocked_intervals
            # (ENG-13 — assignment used to be last-wins). By De Morgan this single
            # representation covers BOTH the UNION of unavailable rules AND the
            # INTERSECTION of "available only" whitelists (whose blocked complements
            # union up), so no separate post-loop merge is needed.
            coach_key = str(scope_target_id)
            # Optional time window (Lot C): absent → whole day (0..1440), i.e. the
            # legacy day-level behaviour, so old configs stay byte-identical.
            from_min, to_min = _coach_window_minutes(config)
            available_set = _day_int_set(config.get("availableDays"))
            # P4-99 — les intervalles que CETTE contrainte déclare, calculés UNE fois et versés
            # à la fois dans l'union `coach_blocked_intervals` (consommée par
            # validate_assignments — structure/arité/valeurs INCHANGÉES) ET dans la carte de
            # sources, pour rattacher PLUS TARD un créneau fermé à SA contrainte exacte (pas la
            # première venue). L'arité `(day, from, to)` ne bouge pas : c'est une carte parallèle.
            constraint_intervals: list[tuple[int, int, int]] = []
            for day in _day_int_set(config.get("unavailableDays")):
                constraint_intervals.append((day, from_min, to_min))
            if available_set:
                # Available ONLY on these days within [from, to] → block the
                # complement: every other day whole, plus the out-of-window parts
                # of the available days.
                for day in range(0, 8):
                    if day not in available_set:
                        constraint_intervals.append((day, 0, 1440))
                        continue
                    if from_min > 0:
                        constraint_intervals.append((day, 0, from_min))
                    if to_min < 1440:
                        constraint_intervals.append((day, to_min, 1440))
            intervals = coach_blocked_intervals.setdefault(coach_key, set())
            intervals.update(constraint_intervals)
            # Source enregistrée seulement si elle ferme réellement quelque chose (au moins un
            # intervalle) : une « dispo » couvrant toute la semaine ne nomme aucune cause.
            if constraint_intervals:
                result["coach_unavailability_sources"].setdefault(coach_key, []).append(
                    {"constraint_id": c.get("id"), "label": c.get("name"), "intervals": constraint_intervals}
                )
            # Coach availability is always enforced HARD (a person cannot be in
            # two places); the UI now forces HARD — surface legacy soft rows.
            if rule_type not in (None, "HARD", "LOCK"):
                result["parse_warnings"].append(
                    _not_honored_warning(
                        c,
                        "INFO",
                        "Une disponibilité de coach est toujours appliquée comme obligatoire "
                        f"(ruleType {rule_type} reçu).",
                    )
                )

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            # LOCK on a venue rule = "keep this venue fixed" — dur, like
            # LOCK TIME/DAY (was dead end-to-end, ENG-12).
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            _set_venue_rule(
                result["forced_venues"],
                scope_target_id,
                config["preferredVenueId"],
                c,
                result["parse_warnings"],
                result["forced_venue_sources"],
            )

        elif (
            family == "FACILITY"
            and config.get("forcedVenueId")
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            _set_venue_rule(
                result["forced_venues"],
                scope_target_id,
                config["forcedVenueId"],
                c,
                result["parse_warnings"],
                result["forced_venue_sources"],
            )

        elif (
            family == "FACILITY"
            and config.get("minAtVenueId")
            and rule_type in ("HARD", "LOCK")
            and scope == "TEAM"
            and scope_target_id
        ):
            # "au moins N séances dans ce gymnase" — un compte, PAS un forçage de
            # toutes les séances (≠ forcedVenueId). Défaut N=1 (cas courant).
            raw_count = config.get("minAtVenueCount")
            min_count = int(raw_count) if raw_count is not None else 1
            result["venue_minimums"].append(
                {
                    "scope_target_id": str(scope_target_id),
                    "venue_id": str(config["minAtVenueId"]),
                    "min": max(1, min_count),
                }
            )

        elif (
            family == "FACILITY"
            and config.get("preferredVenueId")
            and rule_type == "PREFERRED"
            and scope == "TEAM"
            and scope_target_id
        ):
            # PR B — les préférences SOFT se CUMULENT (un club vit sur 3-4 gymnases
            # « à privilégier ») : ensemble par équipe, bonus si la séance tombe dans
            # L'UN d'eux. Le last-wins + warning ne vaut plus que pour les règles
            # DURES (`forced_venues`), où deux gymnases sont une vraie contradiction.
            result["preferred_venues"].setdefault(str(scope_target_id), set()).add(str(config["preferredVenueId"]))

        elif family == "FACILITY" and config.get("forbiddenVenueId"):
            # rule_type decides HOW hard "avoid this venue" is (ENG-11 — this
            # branch used to escalate every ruleType into a hard interdiction,
            # making INFEASIBLE possible on a mere preference).
            if rule_type in ("HARD", "LOCK", None):
                # P4-99 — l'id/le libellé de la contrainte accompagnent la paire, pour que la
                # cause `venue_forbidden` soit cliquable. Consommé par `.get` en aval — un dict
                # sans ces clés (tests hérités) reste valide, la cause dégrade au kind seul.
                result["forbidden_assignments"].append(
                    {
                        "scope_target_id": scope_target_id,
                        "venue_id": config["forbiddenVenueId"],
                        "constraint_id": c.get("id"),
                        "label": c.get("name"),
                    }
                )
            elif scope_target_id:
                # PREFERRED (incl. normalized BONUS): soft "avoid" — an
                # objective malus, never a feasibility constraint.
                result["avoided_venues"].append(
                    {"scope_target_id": str(scope_target_id), "venue_id": str(config["forbiddenVenueId"])}
                )
            else:
                # Soft avoid without a target cannot be applied — say so (the
                # sibling hard/target-less variants warn too, never a silent drop).
                result["parse_warnings"].append(
                    _not_honored_warning(
                        c,
                        "WARNING",
                        "Contrainte de gymnase sans équipe cible — non appliquée.",
                    )
                )

        elif c.get("type") == "PRIORITY_TIER":
            metadata = c.get("metadata") or {}
            tier_id = metadata.get("id")
            default_min = metadata.get("defaultMinSessions")
            if tier_id is not None and default_min is not None:
                result["priority_tiers"][int(tier_id)] = int(default_min)

        elif family in ("TIME", "DAY"):
            if scope_target_id is None:
                # The backend expands club-wide constraints into per-team ones;
                # a target-less window reaching the engine would be silently
                # skipped downstream (add_time_window_constraints requires a
                # team) — surface it instead of a silent no-op.
                result["parse_warnings"].append(
                    _not_honored_warning(
                        c,
                        "WARNING",
                        "Contrainte sans équipe cible — non appliquée (le backend doit l'étendre par équipe).",
                    )
                )
            else:
                result["time_windows"].append(c)

        elif family == "FACILITY" and (config.get("preferredVenueId") or config.get("forcedVenueId")):
            # A wizard-emitted venue rule that matched no branch (target-less
            # scope) — an explicit warning, never a silent drop. Other FACILITY
            # variants (e.g. the cockpit venue_closed marker, enforced via the
            # backend expandClosedVenues expansion) are deliberate no-ops here
            # and must NOT raise a false "not applied" alarm.
            result["parse_warnings"].append(
                _not_honored_warning(
                    c,
                    "WARNING",
                    "Contrainte de gymnase sans équipe cible — non appliquée.",
                )
            )

        elif family not in _KNOWN_FAMILIES and c_type not in _KNOWN_TYPES and rule_type != "LOCK":
            # Only warn when neither the family NOR the type is recognised — a
            # genuine contract drift. A recognised family whose specific
            # config/scope variant isn't handled (e.g. a CLUB-scope FACILITY) is
            # a deliberate no-op, not drift, and must not spam warnings (review).
            logger.warning(
                "unrecognised constraint dropped: id=%s type=%s family=%s ruleType=%s",
                c.get("id"),
                c_type,
                family,
                rule_type,
            )

    # The blocked-interval accumulation IS the coach-availability algebra (union of
    # every constraint's blocked intervals — see coach_blocked_intervals above).
    result["coach_unavailability"] = {k: v for k, v in coach_blocked_intervals.items() if v}

    return result
