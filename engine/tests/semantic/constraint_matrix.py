"""Machine representation of the UI↔engine constraint matrix (audit P0.1).

Single source of truth for WHAT the wizard offers and HOW the engine must
treat each combination. ``test_constraint_matrix.py`` is GENERATED from this
table (one parametrized case per cell) — any wizard evolution (new family,
ruleType or config key) must update this matrix first, which forces the
matching semantic test to exist. The human-readable twin lives in
``docs/architecture/constraint-matrix.md``.

Expectations:
- HONORED_HARD  — the solver never violates the rule (a violating placement is
                  impossible; over-constrained → unplaced/diagnostic, never a
                  silent violation).
- HONORED_SOFT  — the rule steers the objective (preferred option wins in a
                  mixed scenario) but NEVER blocks feasibility (the solver
                  still places when only the dispreferred option exists).
- WARNING       — the engine cannot honor the rule and says so through a
                  ``constraint_not_honored`` diagnostics entry.
- NOT_OFFERED   — the UI does not offer the combination (locked by the wizard
                  Vitest test); the engine may still normalize legacy rows.

Lock-silence dimension (``lock_silence``, MANDATORY — no default):
A HARD lock is pre-placed OUTSIDE the solver, so it can bypass a rule the solver
would otherwise enforce (the locked slot has no variable to force to 0). This
axis records what the engine promises when that happens — the exact hole
``forced_venues`` fell through:
- DIAGNOSED    — a lock CAN bypass the rule, and the bypass MUST emit a
                 ``constraint_not_honored`` diagnostic that names the rule
                 (``diagnose_locked_slot_violations``). Every DIAGNOSED cell is
                 backed by a lock-vs-rule scenario in the generated test.
- UNBYPASSABLE — the family cannot be bypassed IN SILENCE; ``lock_silence_reason``
                 says why (e.g. applied hard with only honored/failed/ERROR
                 outcomes, or already surfaced at parse time).
- SOFT         — a soft family: it steers the objective and promises nothing, so
                 "a lock bypassed it" is meaningless.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Any


class Expectation(Enum):
    HONORED_HARD = "honored_hard"
    HONORED_SOFT = "honored_soft"
    WARNING = "warning"
    NOT_OFFERED = "not_offered"


class LockSilence(Enum):
    DIAGNOSED = "diagnosed"
    UNBYPASSABLE = "unbypassable"
    SOFT = "soft"


@dataclass(frozen=True)
class MatrixCell:
    family: str
    rule_type: str
    config_key: str
    scope: str
    expected: Expectation
    offered_by_ui: bool
    note: str = ""
    # HONORED_HARD default: when only the forbidden option exists the team stays
    # unplaced. minAtVenueId breaks this — an unreachable floor fails SOFT (an
    # ERROR diagnostic, team still placed) instead of INFEASIBLE, so it opts out
    # of the only-bad assertion (its fail-soft path has a dedicated test).
    hard_only_bad_unplaced: bool = True
    # Sample config the wizard would emit for this cell; "{good}"/"{bad}"
    # placeholders are filled by the test scenario builder.
    config: dict[str, Any] = field(default_factory=dict)
    # Lock-silence classification (see module docstring) — MANDATORY, keyword-only
    # with NO default so a new cell that forgets it fails at construction rather
    # than defaulting silently into a passing bucket.
    lock_silence: LockSilence = field(kw_only=True)
    # Required (non-empty) iff lock_silence is UNBYPASSABLE — enforced by the
    # generated structural test.
    lock_silence_reason: str = field(default="", kw_only=True)

    @property
    def case_id(self) -> str:
        return f"{self.family}-{self.rule_type}-{self.config_key}-{self.scope}"


# Scenario vocabulary shared with the test builder: two venues (good/bad), two
# days (1=good Monday, 3=bad Wednesday), two start times (17:00 good, 20:00 bad).
MATRIX: tuple[MatrixCell, ...] = (
    # --- TIME minStartTime/maxStartTime ---------------------------------------
    MatrixCell(
        "TIME",
        "HARD",
        "minStartTime",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        config={"minStartTime": "19:00"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "TIME",
        "LOCK",
        "minStartTime",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="LOCK on a time rule = fixed window, enforced like HARD",
        config={"minStartTime": "19:00"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "TIME",
        "PREFERRED",
        "minStartTime",
        "TEAM",
        Expectation.HONORED_SOFT,
        True,
        config={"minStartTime": "19:00"},
        lock_silence=LockSilence.SOFT,
    ),
    # --- TIME maxEndTime (ALIGN-04, "finir avant") -----------------------------
    # HARD-only offer: the soft time path (add_preferred_time_bonus) reads only
    # min/maxStartTime, so a PREFERRED end-bound would be a placebo — the wizard
    # pins "Fini avant" HARD. 90-min sessions: 17:00 ends 18:30 (ok), 20:00 ends
    # 21:30 (late for the 18:30 bound).
    MatrixCell(
        "TIME",
        "HARD",
        "maxEndTime",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'Fini avant' = session END must fall by the bound, always hard",
        config={"maxEndTime": "18:30"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # --- DAY forbiddenDays ------------------------------------------------------
    MatrixCell(
        "DAY",
        "HARD",
        "forbiddenDays",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        config={"forbiddenDays": [3]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "DAY",
        "LOCK",
        "forbiddenDays",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        config={"forbiddenDays": [3]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "DAY",
        "PREFERRED",
        "forbiddenDays",
        "TEAM",
        Expectation.HONORED_SOFT,
        True,
        note="ENG-10 fix: soft 'avoid these days' (was a silent placebo)",
        config={"forbiddenDays": [3]},
        lock_silence=LockSilence.SOFT,
    ),
    # --- FACILITY preferredVenueId ---------------------------------------------
    MatrixCell(
        "FACILITY",
        "HARD",
        "preferredVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="HARD 'preferred' = forced venue",
        config={"preferredVenueId": "{good_venue}"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "FACILITY",
        "LOCK",
        "preferredVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="ENG-12 fix: LOCK venue = fixed venue (was dead)",
        config={"preferredVenueId": "{good_venue}"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "FACILITY",
        "PREFERRED",
        "preferredVenueId",
        "TEAM",
        Expectation.HONORED_SOFT,
        True,
        config={"preferredVenueId": "{good_venue}"},
        lock_silence=LockSilence.SOFT,
    ),
    # --- FACILITY forbiddenVenueId ---------------------------------------------
    MatrixCell(
        "FACILITY",
        "HARD",
        "forbiddenVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        config={"forbiddenVenueId": "{bad_venue}"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "FACILITY",
        "LOCK",
        "forbiddenVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        config={"forbiddenVenueId": "{bad_venue}"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "FACILITY",
        "PREFERRED",
        "forbiddenVenueId",
        "TEAM",
        Expectation.HONORED_SOFT,
        True,
        note="ENG-11 fix: soft 'avoid this venue' (was escalated to a hard ban)",
        config={"forbiddenVenueId": "{bad_venue}"},
        lock_silence=LockSilence.SOFT,
    ),
    # --- COACH_AVAILABILITY (UI forces HARD) -----------------------------------
    MatrixCell(
        "COACH_AVAILABILITY",
        "HARD",
        "unavailableDays",
        "COACH",
        Expectation.HONORED_HARD,
        True,
        note="ENG-13 fix: multiple constraints on one coach are UNIONed",
        config={"unavailableDays": [3]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "COACH_AVAILABILITY",
        "PREFERRED",
        "unavailableDays",
        "COACH",
        Expectation.WARNING,
        False,
        note="UI forces HARD; a legacy soft row is enforced hard + INFO diagnostic",
        config={"unavailableDays": [3]},
        lock_silence=LockSilence.UNBYPASSABLE,
        lock_silence_reason=(
            "coach availability is enforced HARD whatever the ruleType, and a legacy soft row is "
            "ALREADY flagged at parse time (constraint_not_honored) — never silently applied"
        ),
    ),
    MatrixCell(
        "COACH_AVAILABILITY",
        "HARD",
        "availableDays",
        "COACH",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'coach disponible uniquement' = whitelist (INTERSECTION per coach)",
        config={"availableDays": [1]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # P4-93 (repris de la PR #407, 2026-08-14) — la FENÊTRE HORAIRE de l'indisponibilité,
    # livrée au lot C (#195, bump 2.0→2.1) mais jamais entrée dans la matrice : l'offre du
    # wizard avait bougé sans que son verrou suive. Ces deux cellules figent les DEUX
    # bornes — une indispo « le mercredi À PARTIR DE 17h » ne doit pas se comporter comme
    # « le mercredi ».
    MatrixCell(
        "COACH_AVAILABILITY",
        "HARD",
        "fromTime",
        "COACH",
        Expectation.HONORED_HARD,
        True,
        note="lot C #195: window lower bound — blocked interval is [fromTime, 24:00) on those days",
        config={"unavailableDays": [3], "fromTime": "17:00"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    MatrixCell(
        "COACH_AVAILABILITY",
        "HARD",
        "untilTime",
        "COACH",
        Expectation.HONORED_HARD,
        True,
        note="lot C #195: window upper bound — blocked interval is [00:00, untilTime) on those days",
        config={"unavailableDays": [3], "untilTime": "19:00"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # --- Legacy / guard cells ---------------------------------------------------
    MatrixCell(
        "DAY",
        "BONUS",
        "forbiddenDays",
        "TEAM",
        Expectation.HONORED_SOFT,
        False,
        note="ENG-12: BONUS removed from the UI; legacy rows normalize to PREFERRED",
        config={"forbiddenDays": [3]},
        lock_silence=LockSilence.SOFT,
    ),
    MatrixCell(
        "FACILITY",
        "BONUS",
        "forbiddenVenueId",
        "TEAM",
        Expectation.HONORED_SOFT,
        False,
        note="legacy BONUS → PREFERRED (soft avoid)",
        config={"forbiddenVenueId": "{bad_venue}"},
        lock_silence=LockSilence.SOFT,
    ),
    MatrixCell(
        "DAY",
        "HARD",
        "forbiddenDays",
        "CLUB",
        Expectation.WARNING,
        False,
        note="target-less scope: backend expands CLUB→teams; a stray one warns",
        config={"forbiddenDays": [3]},
        lock_silence=LockSilence.UNBYPASSABLE,
        lock_silence_reason=(
            "target-less scope: never bound to a team, so there is no per-team enforcement for a "
            "lock to bypass — a stray row is surfaced at parse time (constraint_not_honored)"
        ),
    ),
    MatrixCell(
        "FACILITY",
        "PREFERRED",
        "preferredVenueId",
        "CLUB",
        Expectation.WARNING,
        False,
        note="target-less facility rule cannot be applied — explicit warning",
        config={"preferredVenueId": "{good_venue}"},
        lock_silence=LockSilence.UNBYPASSABLE,
        lock_silence_reason=(
            "target-less scope: never bound to a team, so there is no per-team enforcement for a "
            "lock to bypass — surfaced at parse time (constraint_not_honored)"
        ),
    ),
    # --- FACILITY forcedVenueId / DAY allowedDays (wizard "impose"/"uniquement") --
    # The wizard edit form offers these as always-hard modes so seeded "must play
    # here" / "this day only" rules (SM4→Jean Vilar, Veterans vendredi) round-trip
    # faithfully instead of being downgraded to a soft preference.
    MatrixCell(
        "FACILITY",
        "HARD",
        "forcedVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'impose' = forced venue (must play here), always hard",
        config={"forcedVenueId": "{good_venue}"},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # ALIGN-05: wizard "au moins N" = a FLOOR count at a venue (minAtVenueId +
    # minAtVenueCount), always hard. For a 1-session team it coincides with
    # forced-venue in the mixed scenario; when unreachable it fails SOFT (dedicated
    # test), hence hard_only_bad_unplaced=False.
    MatrixCell(
        "FACILITY",
        "HARD",
        "minAtVenueId",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'au moins N' = min sessions at venue (floor count), always hard",
        config={"minAtVenueId": "{good_venue}", "minAtVenueCount": 1},
        hard_only_bad_unplaced=False,
        lock_silence=LockSilence.UNBYPASSABLE,
        lock_silence_reason=(
            "applied hard with only three outcomes — honored, INFEASIBLE→failed, or unreachable→"
            "venue_minimum_unreachable ERROR diagnostic; it can never drift in silence, so listing "
            "it in the lock diagnostic would be the very lie diagnose_locked_slot_violations forbids"
        ),
    ),
    # ENG-16: the wizard "uniquement" maps to allowedDays (a WHITELIST: the engine
    # forbids every non-listed day) — NOT forcedDays, which only means "at least one
    # session on these days" and leaves the other days open (silently violating
    # "uniquement" for a multi-session team).
    MatrixCell(
        "DAY",
        "HARD",
        "allowedDays",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'uniquement' = whitelist, only these days allowed, always hard",
        config={"allowedDays": [1]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # ALIGN-09: the wizard "au moins une" maps to forcedDays — "at least one session
    # on ONE of these days" (an aggregate sum over the union, ≠ "only"). Enforced hard
    # and diagnosed when a lock leaves the forced day unserved (section 2bis of
    # diagnose_locked_slot_violations) — hence DIAGNOSED, with a config the lock test drives.
    MatrixCell(
        "DAY",
        "HARD",
        "forcedDays",
        "TEAM",
        Expectation.HONORED_HARD,
        True,
        note="wizard 'au moins une' = at least one session on ONE of these days (≠ 'only'); always hard",
        config={"forcedDays": [1]},
        lock_silence=LockSilence.DIAGNOSED,
    ),
    # --- Understood by the engine but never emitted by the wizard ---------------
    MatrixCell(
        "DAY",
        "PREFERRED",
        "preferredDays",
        "TEAM",
        Expectation.NOT_OFFERED,
        False,
        note="objective reads it, wizard never emits it (ENG-10 root)",
        lock_silence=LockSilence.SOFT,
    ),
)


# ---------------------------------------------------------------------------
# Matrice des RÈGLES IMPLICITES réglables (lot « règles implicites réglables »).
#
# Les 4 règles « bien-être » ne vivent pas dans ``constraints[]`` mais dans le bloc
# ``implicitRules`` du payload : elles n'entrent donc PAS dans ``MATRIX`` (dont le
# vocabulaire family/ruleType/config décrit les contraintes saisies). Chacune a 2 crans —
# HARD (posée dure, honorée) et PREFERRED (retirée du dur, oriente sans bloquer + toujours
# diagnostiquée si non tenue) — offerts par le wizard. Cette table est la source unique de ce
# que le wizard offre ; ``test_constraint_matrix.py`` en génère un cas par cellule.
# ---------------------------------------------------------------------------


@dataclass(frozen=True)
class ImplicitRuleCell:
    rule_key: str
    intensity: str  # HARD | PREFERRED
    expected: Expectation  # HONORED_HARD (HARD) | HONORED_SOFT (PREFERRED)
    note: str = ""

    @property
    def case_id(self) -> str:
        return f"{self.rule_key}-{self.intensity}"


IMPLICIT_RULE_MATRIX: tuple[ImplicitRuleCell, ...] = tuple(
    ImplicitRuleCell(rule_key, intensity, expected, note)
    for rule_key in ("coachRestDay", "salarieDistribution", "maxConsecutiveSessions", "ageAscending")
    for intensity, expected, note in (
        ("HARD", Expectation.HONORED_HARD, "cran dur (défaut) : le solveur ne place jamais en violation"),
        ("PREFERRED", Expectation.HONORED_SOFT, "cran préféré : oriente sans bloquer + warning si non tenu"),
    )
)
