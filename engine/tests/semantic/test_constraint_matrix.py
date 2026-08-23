"""Constraint-matrix semantic tests — GENERATED from ``constraint_matrix.MATRIX``.

Non-regression for the §7.1 "constraint semantics" axis (audit P0.1,
ENG-10/11/12/13): every combination the wizard offers is either honored (hard
or soft, asserted on the real production pipeline), rejected with an explicit
diagnostic, or not offered at all (locked by the wizard's own Vitest test).

Scenario vocabulary (mirrors the matrix docstring): two venues ``good``/``bad``,
two days (1 = good Monday, 3 = bad Wednesday), two starts (17:00 bad, 20:00
good for min-start rules). Each cell solves a MIXED scenario (both options
available) and asserts:
- HONORED_HARD → the placement never violates the rule; additionally a
  representative "only the forbidden option exists" scenario must NOT place in
  violation.
- HONORED_SOFT → the objective picks the preferred option; additionally the
  solver STILL PLACES when only the dispreferred option exists (the assertion
  that would have caught ENG-11's escalation to hard).
- WARNING → ``diagnostics`` carries a ``constraint_not_honored`` entry.
"""

from __future__ import annotations

from typing import Any

import pytest

from tests.semantic import test_implicit_rules_adjustable as impl
from tests.semantic.constraint_matrix import (
    IMPLICIT_RULE_MATRIX,
    MATRIX,
    Expectation,
    ImplicitRuleCell,
    LockSilence,
    MatrixCell,
)
from tests.support import make_payload, make_venue, solve_payload, team_coach

GOOD_VENUE = "venue-good"
BAD_VENUE = "venue-bad"
GOOD_DAY = 1
BAD_DAY = 3
_FR_DAYS = {1: "lundi", 2: "mardi", 3: "mercredi", 4: "jeudi", 5: "vendredi", 6: "samedi", 7: "dimanche"}


def _team(team_id: str = "t", sessions: int = 1) -> dict[str, Any]:
    return {
        "id": team_id,
        "sportCategoryId": "cat",
        "priorityTierId": 3,
        "name": team_id,
        "sessionsPerWeek": sessions,
        "isActive": True,
    }


def _fill(config: dict[str, Any]) -> dict[str, Any]:
    """Resolve the {good_venue}/{bad_venue} placeholders of a matrix config."""
    filled: dict[str, Any] = {}
    for key, value in config.items():
        if value == "{good_venue}":
            filled[key] = GOOD_VENUE
        elif value == "{bad_venue}":
            filled[key] = BAD_VENUE
        else:
            filled[key] = value
    return filled


def _constraint(cell: MatrixCell, config: dict[str, Any]) -> dict[str, Any]:
    scope_target = {"TEAM": "t", "COACH": "coach-1", "CLUB": None}[cell.scope]
    return {
        "id": f"mx-{cell.case_id}",
        "scope": cell.scope,
        "scopeTargetId": scope_target,
        "family": cell.family,
        "ruleType": cell.rule_type,
        "name": cell.case_id,
        "config": ({**config, "coachId": "coach-1"} if cell.scope == "COACH" else config),
        "sortOrder": 0,
        "isActive": True,
    }


def _mixed_payload(cell: MatrixCell) -> dict[str, Any]:
    """Both the good and the bad option exist — the rule decides the outcome."""
    config = _fill(cell.config)
    constraints = [_constraint(cell, config)]
    if cell.scope == "COACH":
        constraints.append(team_coach("tc", "t", "coach-1"))
    if cell.family == "TIME":
        venues = [make_venue(GOOD_VENUE, [(GOOD_DAY, "17:00"), (GOOD_DAY, "20:00")])]
    else:
        venues = [
            make_venue(GOOD_VENUE, [(GOOD_DAY, "18:00")]),
            make_venue(BAD_VENUE, [(BAD_DAY, "18:00")]),
        ]
    return make_payload(teams=[_team()], venues=venues, constraints=constraints)


def _only_bad_payload(cell: MatrixCell) -> dict[str, Any]:
    """Only the dispreferred/forbidden option exists."""
    config = _fill(cell.config)
    constraints = [_constraint(cell, config)]
    if cell.scope == "COACH":
        constraints.append(team_coach("tc", "t", "coach-1"))
    if cell.family == "TIME":
        # The lone slot must VIOLATE the cell's own rule: 17:00 is too early for a
        # minStartTime floor; 20:00 ends too late (21:30) for a maxEndTime bound.
        bad_start = "20:00" if cell.config_key == "maxEndTime" else "17:00"
        venues = [make_venue(GOOD_VENUE, [(GOOD_DAY, bad_start)])]
    else:
        venues = [make_venue(BAD_VENUE, [(BAD_DAY, "18:00")])]
    return make_payload(teams=[_team()], venues=venues, constraints=constraints)


def _slots(result: dict[str, Any]) -> list[dict[str, Any]]:
    return [s for s in result["slots"] if s["teamId"] == "t"]


def _violates(cell: MatrixCell, slot: dict[str, Any]) -> bool:
    """Does a placed slot violate the cell's rule? Thresholds are DERIVED from
    the cell's own config so editing a matrix cell can never desynchronize the
    predicate from the scenario."""
    key = cell.config_key
    config = _fill(cell.config)
    if key == "minStartTime":
        return str(slot["startTime"])[:5] < str(config["minStartTime"])
    if key == "maxEndTime":
        # Matrix TIME slots run the default 90 min; the END must fall by the bound.
        def _mins(hhmm: str) -> int:
            h, m = hhmm[:5].split(":")
            return int(h) * 60 + int(m)

        return _mins(str(slot["startTime"])) + 90 > _mins(str(config["maxEndTime"]))
    if key == "minAtVenueId":
        # 1-session team: the floor of "≥1 at venue" coincides with "is at venue".
        return slot["venueId"] != config["minAtVenueId"]
    if key in ("fromTime", "untilTime"):
        # P3-17 — la fenêtre ne vaut QUE sur les jours listés : hors d'eux, rien n'est
        # bloqué. `fromTime` bloque [from, 24:00), `untilTime` bloque [00:00, until).
        if int(slot["dayOfWeek"]) not in set(config["unavailableDays"]):
            return False
        start = str(slot["startTime"])[:5]
        return start >= str(config["fromTime"]) if key == "fromTime" else start < str(config["untilTime"])
    if key in ("forbiddenDays", "unavailableDays"):
        return int(slot["dayOfWeek"]) in set(config[key])
    if key == "availableDays":
        return int(slot["dayOfWeek"]) not in set(config["availableDays"])
    if key in ("preferredVenueId", "forcedVenueId"):
        return slot["venueId"] != config[key]
    if key == "forbiddenVenueId":
        return slot["venueId"] == config["forbiddenVenueId"]
    if key == "allowedDays":
        return int(slot["dayOfWeek"]) not in set(config["allowedDays"])
    if key == "forcedDays":
        # A 1-session team: "at least one session on ONE of the forced days" reduces
        # to "the single session is on a forced day" — same shape as the whitelist.
        return int(slot["dayOfWeek"]) not in set(config["forcedDays"])
    raise AssertionError(f"no violation predicate for {key}")


OFFERED = [c for c in MATRIX if c.expected in (Expectation.HONORED_HARD, Expectation.HONORED_SOFT)]
WARNED = [c for c in MATRIX if c.expected is Expectation.WARNING]
NOT_OFFERED = [c for c in MATRIX if c.expected is Expectation.NOT_OFFERED]


@pytest.mark.parametrize("cell", OFFERED, ids=lambda c: c.case_id)
def test_mixed_scenario_rule_steers_the_placement(cell: MatrixCell) -> None:
    """Hard AND soft: with both options available, the rule decides."""
    result = solve_payload(_mixed_payload(cell))

    assert result["status"] == "completed"
    placed = _slots(result)
    assert placed, f"{cell.case_id}: team not placed in a mixed scenario"
    for slot in placed:
        assert not _violates(cell, slot), f"{cell.case_id}: rule not honored on {slot}"


@pytest.mark.parametrize(
    "cell",
    [c for c in OFFERED if c.expected is Expectation.HONORED_SOFT],
    ids=lambda c: c.case_id,
)
def test_soft_rule_never_blocks_feasibility(cell: MatrixCell) -> None:
    """A preference must yield when only the dispreferred option exists
    (the assertion that would have caught ENG-11's hard escalation)."""
    result = solve_payload(_only_bad_payload(cell))

    assert result["status"] == "completed"
    assert _slots(result), f"{cell.case_id}: a soft rule blocked the placement"


@pytest.mark.parametrize(
    "cell",
    [c for c in OFFERED if c.expected is Expectation.HONORED_HARD and c.hard_only_bad_unplaced],
    ids=lambda c: c.case_id,
)
def test_hard_rule_never_places_in_violation(cell: MatrixCell) -> None:
    """When only the forbidden option exists, a HARD rule leaves the team
    unplaced (with diagnostics) — never placed in violation."""
    result = solve_payload(_only_bad_payload(cell))

    for slot in _slots(result):
        assert not _violates(cell, slot), f"{cell.case_id}: hard rule violated on {slot}"


@pytest.mark.parametrize("cell", WARNED, ids=lambda c: c.case_id)
def test_unhonorable_rule_emits_an_explicit_diagnostic(cell: MatrixCell) -> None:
    result = solve_payload(_mixed_payload(cell))

    entries = [d for d in result.get("diagnostics", []) if d.get("type") == "constraint_not_honored"]
    assert entries, f"{cell.case_id}: expected a constraint_not_honored diagnostic"


@pytest.mark.parametrize("cell", NOT_OFFERED, ids=lambda c: c.case_id)
def test_not_offered_cells_are_locked_by_the_ui_test(cell: MatrixCell) -> None:
    """Documented as not offered — the wizard Vitest test freezes the UI offer;
    nothing to assert engine-side."""
    assert not cell.offered_by_ui


# --- Lock-silence dimension: a HARD lock that bypasses a DIAGNOSED rule speaks --
#
# A HARD lock is pre-placed OUTSIDE the solver (the locked slot has no variable
# to force to 0), so it can annul a rule the solver would otherwise enforce.
# `forced_venues` fell exactly through that hole — placed elsewhere, `completed`,
# not a word. This axis pins, per cell, what the engine PROMISES then:
#   DIAGNOSED    → the bypass must emit a constraint_not_honored naming the rule;
#   UNBYPASSABLE → cannot drift in silence (reason on the cell);
#   SOFT         → soft family, promises nothing.
# The DIAGNOSED test below solves a real lock-vs-rule scenario per cell — so a
# family wrongly tagged DIAGNOSED (e.g. venue_minimums, which emits only an ERROR)
# turns the suite red instead of passing on the label alone.

DIAGNOSED = [c for c in MATRIX if c.lock_silence is LockSilence.DIAGNOSED]


def _violating_lock(cell: MatrixCell) -> tuple[str, int, str]:
    """(venue, day, start) for a HARD lock that VIOLATES the cell's rule — DERIVED
    from the cell's own config so editing a cell can never desync it."""
    config = _fill(cell.config)
    key = cell.config_key
    venue, day, start = GOOD_VENUE, GOOD_DAY, "18:00"
    if key == "minStartTime":
        start = "17:00"  # before the 19:00 floor
    elif key == "maxEndTime":
        start = "20:00"  # 20:00 + 90 min = 21:30, past the 18:30 bound
    elif key in ("forbiddenDays", "unavailableDays"):
        day = int(config[key][0])  # lock ON the forbidden/unavailable day
    elif key in ("allowedDays", "availableDays", "forcedDays"):
        day = BAD_DAY  # a day outside the whitelist / off the forced day
    elif key == "forbiddenVenueId":
        venue = config["forbiddenVenueId"]  # lock AT the banned venue
    elif key in ("preferredVenueId", "forcedVenueId"):
        venue = BAD_VENUE  # lock at a venue that is NOT the imposed one
    elif key in ("fromTime", "untilTime"):
        # Lock on the blocked day, 18:00 — inside BOTH sample windows
        # ([17:00, 24:00) for fromTime="17:00" and [00:00, 19:00) for
        # untilTime="19:00") : a genuinely violating lock. The window
        # SEMANTICS (in refused / out taken) live in test_coach_window.py —
        # here we only pin the DIAGNOSED promise on the bypass.
        day = int(config["unavailableDays"][0])
    else:
        raise AssertionError(f"no violating-lock recipe for {key}")
    return venue, day, start


def _lock_vs_rule_payload(cell: MatrixCell) -> dict[str, Any]:
    config = _fill(cell.config)
    constraints = [_constraint(cell, config)]
    if cell.scope == "COACH":
        constraints.append(team_coach("tc", "t", "coach-1"))
    venue, day, start = _violating_lock(cell)
    # Both venues must exist so venue_names resolve; the lock itself needs no
    # matching trainingSlot (it is pre-placed) — its single session satisfies the
    # team, so the free solver adds nothing that could honor the rule instead.
    venues = [make_venue(GOOD_VENUE, [(GOOD_DAY, "18:00")]), make_venue(BAD_VENUE, [(BAD_DAY, "18:00")])]
    lock = {
        "id": f"lock-{cell.case_id}",
        "teamId": "t",
        "venueId": venue,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }
    return make_payload(teams=[_team()], venues=venues, constraints=constraints, slot_templates=[lock])


def _rule_fingerprint(cell: MatrixCell) -> str:
    """A token the lock diagnostic MUST carry so the manager can find the rule —
    proving the announcement NAMES it, not merely that some diagnostic fired."""
    config = _fill(cell.config)
    key = cell.config_key
    if cell.family == "COACH_AVAILABILITY":
        return "coach-1"  # the coach is named
    if key == "forbiddenVenueId":
        return config["forbiddenVenueId"]  # the banned venue is named
    if key in ("preferredVenueId", "forcedVenueId"):
        return config[key]  # the imposed venue is named
    if key == "forcedDays":
        return _FR_DAYS[int(config["forcedDays"][0])]  # the forced day is named
    return cell.case_id  # TIME/DAY windows carry the REAL constraint name


@pytest.mark.parametrize("cell", DIAGNOSED, ids=lambda c: c.case_id)
def test_a_lock_bypassing_a_diagnosed_rule_is_announced(cell: MatrixCell) -> None:
    """The lock stays sovereign (ALIGN-07) but the silence ends: every DIAGNOSED
    family emits a constraint_not_honored INFO that names the bypassed rule.

    Sovereignty is asserted on the PLACEMENT (the pinned slot is kept), not on the
    status: a forced-day lock legitimately fails the solve (the forced session
    becomes infeasible) yet is still kept and still announced — the diagnostic is
    precisely what explains that INFEASIBLE."""
    result = solve_payload(_lock_vs_rule_payload(cell))

    venue, day, start = _violating_lock(cell)
    assert any(
        s["venueId"] == venue and int(s["dayOfWeek"]) == day and str(s["startTime"])[:5] == start
        for s in _slots(result)
    ), f"{cell.case_id}: the sovereign lock must be kept where it was pinned"

    entries = [d for d in result.get("diagnostics", []) if d.get("type") == "constraint_not_honored"]
    assert entries, f"{cell.case_id}: a lock bypassing a diagnosed rule must NOT be silent"
    assert all(d.get("severity") == "INFO" for d in entries), f"{cell.case_id}: lock warnings are INFO, never errors"

    fingerprint = _rule_fingerprint(cell)
    assert any(fingerprint in str(d.get("message", "")) for d in entries), (
        f"{cell.case_id}: the diagnostic must NAME the rule ({fingerprint!r}), not fire hollow"
    )


def test_every_cell_declares_a_lock_silence_and_reasons_where_required() -> None:
    """The dimension is MANDATORY — a cell without ``lock_silence`` fails at
    construction (keyword-only, no default), so MATRIX would not even import.
    Here we additionally require an UNBYPASSABLE cell to STATE why it cannot drift,
    and forbid a stray reason elsewhere (the reason lives where it earns its keep)."""
    for cell in MATRIX:
        assert isinstance(cell.lock_silence, LockSilence), cell.case_id
        needs_reason = cell.lock_silence is LockSilence.UNBYPASSABLE
        assert bool(cell.lock_silence_reason.strip()) == needs_reason, (
            f"{cell.case_id}: only UNBYPASSABLE carries a reason, and it MUST ({cell.lock_silence})"
        )


# --- Règles implicites réglables : générées depuis IMPLICIT_RULE_MATRIX ---------
#
# Chaque cellule = (règle, cran). Le scénario canonique VIOLANT de chaque règle vit dans
# ``test_implicit_rules_adjustable`` (source unique) ; ici on génère l'assertion du contrat de
# cran : HARD → honorée (aucun assouplissement) ; PREFERRED → complète + warning nommé.

_IMPLICIT_SCENARIOS = {
    ("coachRestDay", "HARD"): lambda: impl._rest_day_payload(intensity="HARD", min_rest_days=None),
    ("coachRestDay", "PREFERRED"): lambda: impl._rest_day_payload(intensity="PREFERRED", min_rest_days=None),
    ("maxConsecutiveSessions", "HARD"): lambda: impl._chain_payload(intensity="HARD", max_consecutive=None, n_slots=3),
    ("maxConsecutiveSessions", "PREFERRED"): lambda: impl._chain_payload(
        intensity="PREFERRED", max_consecutive=None, n_slots=3
    ),
    ("salarieDistribution", "HARD"): lambda: impl._salarie_payload(intensity="HARD", friday_salarie=True),
    ("salarieDistribution", "PREFERRED"): lambda: impl._salarie_payload(intensity="PREFERRED", friday_salarie=False),
    ("ageAscending", "HARD"): lambda: impl._age_payload(intensity="HARD"),
    ("ageAscending", "PREFERRED"): lambda: impl._age_payload(intensity="PREFERRED"),
}


def test_every_implicit_cell_has_a_scenario() -> None:
    """Source de vérité : toute cellule déclarée dans IMPLICIT_RULE_MATRIX doit avoir un
    scénario canonique — ajouter une règle/un cran force son scénario à exister."""
    missing = [c.case_id for c in IMPLICIT_RULE_MATRIX if (c.rule_key, c.intensity) not in _IMPLICIT_SCENARIOS]
    assert missing == [], f"cellules de règle implicite sans scénario : {missing}"


@pytest.mark.parametrize("cell", IMPLICIT_RULE_MATRIX, ids=lambda c: c.case_id)
def test_implicit_rule_cell_honours_its_intensity(cell: ImplicitRuleCell) -> None:
    result = solve_payload(_IMPLICIT_SCENARIOS[(cell.rule_key, cell.intensity)]())
    warnings = [
        d
        for d in result.get("diagnostics", [])
        if d.get("type") == "implicit_rule_not_honored" and d.get("ruleKey") == cell.rule_key
    ]
    if cell.expected is Expectation.HONORED_HARD:
        # HARD : la règle est posée dure. Le scénario canonique la respecte → aucun
        # assouplissement à signaler (les warnings « assouplie par vous » sont exclus).
        assert not any("assouplie par vous" in w["message"] for w in warnings), (
            f"{cell.case_id}: une règle HARD honorée ne doit pas être signalée assouplie"
        )
    else:
        # PREFERRED : oriente sans bloquer, et la violation est TOUJOURS diagnostiquée.
        assert result["status"] == "completed", f"{cell.case_id}: PREFERRED ne doit jamais bloquer"
        assert warnings, f"{cell.case_id}: PREFERRED non tenu doit émettre implicit_rule_not_honored"


# --- ENG-13 (dedicated): multiple coach constraints are UNIONed ----------------


def test_multiple_coach_constraints_union_not_last_wins() -> None:
    venues = [make_venue("v", [(1, "18:00"), (3, "18:00"), (5, "18:00")])]
    constraints = [
        team_coach("tc", "t", "coach-1"),
        {
            "id": "cu-1",
            "scope": "COACH",
            "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY",
            "ruleType": "HARD",
            "name": "indispo lundi",
            "config": {"coachId": "coach-1", "unavailableDays": [1]},
            "sortOrder": 0,
            "isActive": True,
        },
        {
            "id": "cu-2",
            "scope": "COACH",
            "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY",
            "ruleType": "HARD",
            "name": "indispo mercredi",
            "config": {"coachId": "coach-1", "unavailableDays": [3]},
            "sortOrder": 0,
            "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {5}, f"both Monday AND Wednesday must be blocked, got {days}"


# --- ENG-12 (dedicated): legacy BONUS rows are honored as PREFERRED ------------


def test_legacy_bonus_facility_behaves_as_preferred() -> None:
    venues = [make_venue(GOOD_VENUE, [(1, "18:00")]), make_venue(BAD_VENUE, [(3, "18:00")])]
    constraints = [
        {
            "id": "bonus-1",
            "scope": "TEAM",
            "scopeTargetId": "t",
            "family": "FACILITY",
            "ruleType": "BONUS",
            "name": "legacy bonus",
            "config": {"forbiddenVenueId": BAD_VENUE},
            "sortOrder": 0,
            "isActive": True,
        }
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    placed = _slots(result)
    assert placed and all(s["venueId"] == GOOD_VENUE for s in placed)


# --- Review NR: two soft avoid-day rules must BOTH steer (no mutual cancel) ----


def test_two_soft_avoid_day_rules_union_not_cancel() -> None:
    venues = [make_venue("v", [(1, "18:00"), (3, "18:00"), (5, "18:00")])]
    constraints = [
        {
            "id": "ad-1",
            "scope": "TEAM",
            "scopeTargetId": "t",
            "family": "DAY",
            "ruleType": "PREFERRED",
            "name": "éviter lundi",
            "config": {"forbiddenDays": [1]},
            "sortOrder": 0,
            "isActive": True,
        },
        {
            "id": "ad-2",
            "scope": "TEAM",
            "scopeTargetId": "t",
            "family": "DAY",
            "ruleType": "PREFERRED",
            "name": "éviter mercredi",
            "config": {"forbiddenDays": [3]},
            "sortOrder": 0,
            "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {5}, f"both avoided days must steer away, got {days}"


# --- Review NR: two availableDays whitelists INTERSECT (never block every day) -


def test_two_available_days_whitelists_intersect_not_block_everything() -> None:
    venues = [make_venue("v", [(1, "18:00"), (2, "18:00"), (5, "18:00")])]
    constraints = [
        team_coach("tc", "t", "coach-1"),
        {
            "id": "av-1",
            "scope": "COACH",
            "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY",
            "ruleType": "HARD",
            "name": "dispo lun+mar",
            "config": {"coachId": "coach-1", "availableDays": [1, 2]},
            "sortOrder": 0,
            "isActive": True,
        },
        {
            "id": "av-2",
            "scope": "COACH",
            "scopeTargetId": "coach-1",
            "family": "COACH_AVAILABILITY",
            "ruleType": "HARD",
            "name": "dispo mar+ven",
            "config": {"coachId": "coach-1", "availableDays": [2, 5]},
            "sortOrder": 0,
            "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    # Intersection = Tuesday only; the union-of-complements bug blocked EVERY
    # day (INFEASIBLE for a schedulable club).
    assert result["status"] == "completed"
    days = {int(s["dayOfWeek"]) for s in _slots(result)}
    assert days == {2}, f"whitelists must intersect to Tuesday, got {days}"


# --- Review NR: cockpit venue_closed rows never raise a false warning ---------


def test_cockpit_venue_closed_marker_raises_no_false_warning() -> None:
    venues = [make_venue("v", [(1, "18:00")])]
    constraints = [
        {
            "id": "vc-1",
            "scope": "FACILITY",
            "scopeTargetId": "v",
            "family": "FACILITY",
            "ruleType": "HARD",
            "name": "Gymnase fermé",
            "config": {"type": "venue_closed"},
            "sortOrder": 0,
            "isActive": True,
        }
    ]
    result = solve_payload(make_payload(teams=[_team()], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    warnings = [d for d in result.get("diagnostics", []) if d.get("type") == "constraint_not_honored"]
    assert warnings == [], "the closure marker is enforced via the backend expansion — no false alarm"


# --- Review NR: a soft avoid-venue must not displace another team's preference -


def test_soft_avoid_venue_does_not_outbid_an_explicit_preference() -> None:
    # One contested slot at venue Y: team B explicitly prefers Y, team A merely
    # avoids X. The complement-bonus bug tied both at the same weight.
    venues = [make_venue("venue-y", [(1, "18:00")]), make_venue("venue-x", [(3, "18:00")])]
    constraints = [
        {
            "id": "avoid-x",
            "scope": "TEAM",
            "scopeTargetId": "a",
            "family": "FACILITY",
            "ruleType": "PREFERRED",
            "name": "A évite X",
            "config": {"forbiddenVenueId": "venue-x"},
            "sortOrder": 0,
            "isActive": True,
        },
        {
            "id": "prefer-y",
            "scope": "TEAM",
            "scopeTargetId": "b",
            "family": "FACILITY",
            "ruleType": "PREFERRED",
            "name": "B préfère Y",
            "config": {"preferredVenueId": "venue-y"},
            "sortOrder": 0,
            "isActive": True,
        },
    ]
    result = solve_payload(make_payload(teams=[_team("a"), _team("b")], venues=venues, constraints=constraints))

    assert result["status"] == "completed"
    y_teams = {s["teamId"] for s in result["slots"] if s["venueId"] == "venue-y"}
    assert "b" in y_teams, "the explicit preference must win the contested slot"
