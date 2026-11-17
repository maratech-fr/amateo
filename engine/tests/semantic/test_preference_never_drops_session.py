"""NR — « aucune préférence ne doit jamais coûter une séance » (axe §7.1 constraint semantics).

Le principe métier (fondateur, 2026-08-15) : LE REMPLISSAGE PRIME SUR LE CONFORT. Une séance
placée — même gymnase/jour/heure non préférés — vaut TOUJOURS mieux qu'un trou. Le confort ne
départage que des solutions plaçant le MÊME nombre de séances.

Le trou vécu (U15F3) : sous V9, une préférence empilée sur un créneau (jusqu'à 120) l'emportait
sur une séance nue (21), si bien qu'une équipe pouvait perdre sa 2ᵉ séance — pas tomber à zéro
(l'équipe à zéro était déjà protégée par UNPLACED_PENALTY), mais passer de 2 à 1 — pour laisser
une autre équipe obtenir son créneau préféré.

Scénario commun (2 équipes tier D, séance nue = tier 1 + session_count 20 = 21) :
  - s1 = venue-p / jour 2 / 18:00 (cap 1, CONTESTÉ) — le créneau que Y préfère.
  - s2 = venue-x / jour 4 / 18:00 — la 2ᵉ séance possible de X.
  - s3 = venue-a / jour 6 / 20:00 — l'alternative de Y.
  X (spw=2) ne peut utiliser QUE {s1, s2} (jour 6 interdit) : sans s1, X plafonne à 1 séance.
  Y (spw=1) ne peut utiliser QUE {s1, s3} (jour 4 interdit) et EMPILE tout son confort sur s1
  (gymnase + jour + heure préférés). Si Y rafle s1, X tombe de 2 à 1.

Deux couches de défense, isolées par les deux tests :
  - NR-1 (swing confort = 10+5+5 = 20 < 21) : le RECALAGE DES POIDS suffit (P1). Vérifié à la
    main : avec ``missing_session`` neutralisé à 0, X garde 2 (63 > 62) ; en remontant
    ``preferred`` à 60 (swing 70 > 21), X tombe à 1 (112 > 63). Cette borne de poids est en
    outre gardée, falsifiable, par ``test_comfort_weights_never_outrank_a_bare_session``
    (tests/test_objective.py) : ``preferred`` à 60 y casse ``preferred+preferred_day+
    preferred_time < 21``. En pipeline V10 complet, ``missing_session`` (−1000) sur-garde ce
    cas — défense en profondeur assumée.
  - NR-2 (swing confort = 20 + avoided_venue 10 = 30 > 21, le swing de P3) : le poids seul NE
    suffit PLUS. Seul ``missing_session`` ferme le trou. Vérifié à la main : ``missing_session``
    ramené à 0 fait tomber X à 1 (62 > 53) ; à −1000, X garde 2.

Dans les deux cas le scénario est un drop 2→1 (jamais 1→0).
"""

from __future__ import annotations

from collections import Counter
from typing import Any

from tests.support import make_payload, make_team, make_venue, solve_payload, team_constraint

VENUE_PREFERRED = "venue-p"  # s1 lives here (Y's preferred venue)
VENUE_X = "venue-x"  # s2 lives here (X's second session)
VENUE_AVOIDED = "venue-a"  # s3 lives here (Y's alternative; avoided in NR-2)
X = "team-x"
Y = "team-y"


def _venues() -> list[dict[str, Any]]:
    return [
        make_venue(VENUE_PREFERRED, [(2, "18:00")]),  # s1
        make_venue(VENUE_X, [(4, "18:00")]),  # s2
        make_venue(VENUE_AVOIDED, [(6, "20:00")]),  # s3
    ]


def _teams() -> list[dict[str, Any]]:
    # Tier D both: a placed session is worth exactly 1 + 20 = 21 (the cheapest), so the
    # comfort-vs-session arithmetic of P1/P3 is exercised at its tightest.
    return [
        make_team(X, sessions_per_week=2, priority_tier_id=5),
        make_team(Y, sessions_per_week=1, priority_tier_id=5),
    ]


def _base_constraints() -> list[dict[str, Any]]:
    return [
        # X: forbid day 6 → X can only reach {s1(day2), s2(day4)}; losing s1 caps X at 1.
        team_constraint(
            constraint_id="x-no-day6", team_id=X, family="DAY", rule_type="HARD", config={"forbiddenDays": [6]}
        ),
        # Y: forbid day 4 → Y can only reach {s1(day2), s3(day6)}.
        team_constraint(
            constraint_id="y-no-day4", team_id=Y, family="DAY", rule_type="HARD", config={"forbiddenDays": [4]}
        ),
        # Full comfort stack for Y, ALL concentrated on s1: preferred venue (venue-p),
        # preferred day (2), preferred time (start ≤ 18:30 → s1's 18:00 qualifies, s3's 20:00 does not).
        team_constraint(
            constraint_id="y-pref-venue",
            team_id=Y,
            family="FACILITY",
            rule_type="PREFERRED",
            config={"preferredVenueId": VENUE_PREFERRED},
        ),
        team_constraint(
            constraint_id="y-pref-day", team_id=Y, family="DAY", rule_type="PREFERRED", config={"preferredDays": [2]}
        ),
        team_constraint(
            constraint_id="y-pref-time",
            team_id=Y,
            family="TIME",
            rule_type="PREFERRED",
            config={"maxStartTime": "18:30"},
        ),
    ]


def _placed_by_team(result: dict[str, Any]) -> Counter[str]:
    return Counter(str(s["teamId"]) for s in result["slots"])


def test_nr1_preference_stack_never_drops_a_session() -> None:
    """NR-1 — Y's full comfort stack on the contested slot must not cost X a session.

    X (2 requested, only {s1,s2} reachable) keeps BOTH sessions; Y takes its alternative s3.
    """
    result = solve_payload(
        make_payload(teams=_teams(), venues=_venues(), constraints=_base_constraints()),
        timeout=15,
    )
    assert result["status"] == "completed"
    placed = _placed_by_team(result)
    assert placed[X] == 2, f"X must keep both sessions despite Y's preference on s1; got {placed[X]}"
    assert placed[Y] == 1, f"Y still placed (on its alternative s3); got {placed[Y]}"


def test_nr2_missing_session_beats_comfort_plus_avoided_venue() -> None:
    """NR-2 — the swing-30 case (P3): comfort 20 + avoided-venue relief 10 > a bare session 21.

    Y additionally AVOIDS venue-a (s3), so grabbing s1 both earns the full stack AND escapes the
    −10 malus (net comfort swing 30 > 21). The weight recalibration alone would let X drop here;
    only ``missing_session`` (−1000) keeps X's two sessions. (Manually falsified: missing_session
    = 0 → X drops to 1.)
    """
    constraints = [
        *_base_constraints(),
        team_constraint(
            constraint_id="y-avoid-venue",
            team_id=Y,
            family="FACILITY",
            rule_type="PREFERRED",
            config={"forbiddenVenueId": VENUE_AVOIDED},
        ),
    ]
    result = solve_payload(
        make_payload(teams=_teams(), venues=_venues(), constraints=constraints),
        timeout=15,
    )
    assert result["status"] == "completed"
    placed = _placed_by_team(result)
    assert placed[X] == 2, f"X must keep both sessions; missing_session must beat comfort+avoided; got {placed[X]}"
    assert placed[Y] == 1, f"Y still placed; got {placed[Y]}"


def test_same_day_and_gap_maluses_never_drop_the_middle_of_three_sessions() -> None:
    """PR 1a — ni « même jour » (−10) ni l'écart (−50) ne coûtent une séance : pire cas, la séance
    au milieu de trois qui se suivent (tier D, plafond 3, un seul jour ouvert) reste placée.

    La retirer soulagerait au plus 2 écarts (100) + 10, bien sous ``missing_session`` (≥ 1021)."""
    team = {**make_team(X, sessions_per_week=3, priority_tier_id=5), "maxSessionsPerDay": 3}
    result = solve_payload(
        make_payload(teams=[team], venues=[make_venue("v", [(1, "17:00"), (1, "18:30"), (1, "20:00")])]),
        timeout=15,
    )
    assert result["status"] == "completed"
    assert _placed_by_team(result)[X] == 3, "une séance a été sacrifiée pour un malus de même jour ou d'écart"
