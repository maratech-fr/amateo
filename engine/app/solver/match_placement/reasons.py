from __future__ import annotations

from datetime import date

REASON_MESSAGES = {
    "venue_unavailable": "Tous les gymnases de match sont indisponibles à cette date.",
    "no_access_window": "Aucune fenêtre d'accès match ne contient la durée du match ce jour-là.",
    "no_league_intersection": "Les fenêtres de la ligue ne croisent aucune fenêtre d'accès ce jour-là.",
    # venue_full ≠ not_selected : venue_full = plus AUCUN créneau licite libre ce jour-là
    # (le gymnase est réellement saturé) ; not_selected = un créneau licite restait libre mais
    # le solveur ne l'a pas retenu dans le temps imparti — la reclassification post-solve tranche.
    "venue_full": "Tous les créneaux licites sont déjà occupés par d'autres matchs.",
    "not_selected": "Le solveur n'a pas retenu de créneau dans le temps imparti — relancez le placement.",
    # P4-272 ③ — a HARD club rule empties the domain: a legal (access ∩ league) slot
    # existed but every one is refused by a club rule. The manager must relax the rule
    # or place the match by hand (manual placement outside a HARD rule stays PERMITTED,
    # only the radar signals it).
    "club_rule_no_slot": "Aucun créneau compatible avec les règles du club.",
    # P4-272 ④ — the team is FORBIDDEN from every venue that would otherwise have held
    # the match: a legal slot existed, but only on a forbidden venue. Same remedy as a
    # club rule — lift the ban or place by hand (a manual placement in a forbidden venue
    # stays PERMITTED, only the radar signals it). Founder wording (2026-09-29).
    "team_venue_forbidden": "Gymnase interdit pour cette équipe.",
}


def _remaining_reason(
    slots: list[tuple[str, int]],
    match_date: date,
    match_min: int,
    busy: dict[tuple[str, date], list[tuple[int, int]]],
) -> str:
    """Reason for a match the solve left unplaced, given the FINAL occupancy.

    ``venue_full`` when EVERY legal slot of the match overlaps an occupied window
    at its date (the venue is genuinely saturated); ``not_selected`` when at least
    one legal slot stays free — the solver simply did not retain it within the
    budget (« relancez le placement »). Pure so both branches are falsifiable
    without a full solve (not_selected is otherwise a budget-exhaustion artefact,
    impossible to force deterministically on a tiny problem)."""
    for venue_id, kickoff in slots:
        occupied = busy.get((venue_id, match_date), [])
        if not any(kickoff < b_end and b_start < kickoff + match_min for b_start, b_end in occupied):
            return "not_selected"
    return "venue_full"
