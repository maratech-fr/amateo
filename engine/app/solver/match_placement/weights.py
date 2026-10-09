from __future__ import annotations

# ── Objective weights (ADR-0003 — fixed, documented, golden-pinned) ──────────
# Placement dominates every SOFT combination of one match: the solver never
# sacrifices a placement for comfort.
W_PLACE = 10_000
W_COACH_MAIN = 60
# P4-272 ⑤ — a coach of the match's team is UNAVAILABLE at a candidate's kickoff (a declared
# window on a covered day). SOFT (validé fondateur : même niveau que les autres pénalités
# coach — W_CLUB_RULE 30 < NOT_SIMULTANEOUS 40 < coach 60) : it steers the placement out of
# the window when an alternative exists, it NEVER blocks (a match is never left unplaced for
# a coach unavailability — no domain pruning, no unplaced reason).
W_COACH_UNAVAILABLE = 60
W_LINK_NOT_SIMULTANEOUS = 40
# P4-272 ③ — a PREFERRED club rule violated by a candidate (validé fondateur : > une
# habitude 15+5, < NOT_SIMULTANEOUS 40 < coach 60). A HARD club rule never reaches
# the objective: it prunes the domain (see _candidate_kickoffs), it is not a penalty.
W_CLUB_RULE = 30
W_HABIT_TIME = 15  # on top of the implicit day match (constant per candidate set)
W_HABIT_VENUE = 5
W_PROTECT_HABIT = 25
W_BACK_TO_BACK = 15
W_COACH_ASSISTANT = 10
W_STABILITY = 8
W_GAP_PER_STEP = 1
