"""P3-21 PR A — le terme de stabilité (convergence moteur), volet comportemental.

Principe : ``previousAssignments`` fait CONVERGER une régénération vers son placement
précédent SANS jamais supprimer une séance, et reste INERTE quand le champ est vide/absent
(chemin byte-identique — patron ``implicitRules``).

Fixtures volontairement à faible complexité (``teams×venues`` ≤ 200 → 1 worker déterministe,
comme les goldens) : deux créneaux SYMÉTRIQUES (même gymnase/durée/tier, jours NON adjacents
donc ni spacing ni match-rest ne les départage, séance unique donc rien d'autre ne les
distingue). Leur score de placement est identique quel que soit celui choisi : ``solverSeed``
42 en fixe un de façon déterministe, et la stabilité peut épingler l'AUTRE.
"""

from __future__ import annotations

from typing import Any

from tests.support import make_payload, make_team, make_venue, solve_payload, team_constraint

TEAM = "team-d"
VENUE = "venue-1"
SLOT_A = (1, "18:00")  # lundi
SLOT_B = (3, "18:00")  # mercredi (non adjacent à lundi)

# P2-61 — fixture « source sous l'optimum » : lundi 18:00 (le précédent) et mercredi 20:00 (que
# la PREFERRED TIME favorise à +5). L'écart saisi (5) est SOUS le poids de proximité (9).
PREF_SLOTS = [(1, "18:00"), (3, "20:00")]


def _payload(previous: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=[make_venue(VENUE, [SLOT_A, SLOT_B])],
    )
    if previous is not None:
        payload["previousAssignments"] = previous
    return payload


def _prev(day: int, start: str = "18:00") -> dict[str, Any]:
    return {"teamId": TEAM, "venueId": VENUE, "dayOfWeek": day, "startTime": start}


def _placed_days(result: dict[str, Any]) -> list[int]:
    return sorted(int(s["dayOfWeek"]) for s in result["slots"] if str(s["teamId"]) == TEAM)


def test_stability_restitutes_the_alternative_optimum_exactly() -> None:
    """Solve n°1 (sans le champ) fixe un optimum ; épingler l'AUTRE optimum via
    ``previousAssignments`` le restitue EXACTEMENT au solve n°2."""
    baseline = solve_payload(_payload(), timeout=10)
    assert baseline["status"] == "completed"
    chosen = _placed_days(baseline)
    assert len(chosen) == 1, f"exactement une séance placée, obtenu {chosen}"
    chosen_day = chosen[0]
    alt_day = SLOT_B[0] if chosen_day == SLOT_A[0] else SLOT_A[0]
    assert alt_day != chosen_day, "les deux créneaux doivent être des optima distincts"

    pinned = solve_payload(_payload([_prev(alt_day)]), timeout=10)
    assert pinned["status"] == "completed"
    assert _placed_days(pinned) == [alt_day], (
        f"la stabilité doit restituer l'optimum alternatif {alt_day} exactement, obtenu {_placed_days(pinned)}"
    )


def test_stability_never_drops_a_session() -> None:
    """La stabilité ne supprime AUCUNE séance (le placement reste ≥ optimum)."""
    chosen_day = _placed_days(solve_payload(_payload(), timeout=10))[0]
    alt_day = SLOT_B[0] if chosen_day == SLOT_A[0] else SLOT_A[0]
    pinned = solve_payload(_payload([_prev(alt_day)]), timeout=10)
    assert len(_placed_days(pinned)) == 1, "aucune séance supprimée pour la stabilité"


def test_empty_previous_assignments_is_identical_to_absent() -> None:
    """Champ vide == champ absent == comportement actuel (chemin inerte)."""
    absent = solve_payload(_payload(), timeout=10)
    empty = solve_payload(_payload([]), timeout=10)
    assert absent["slots"] == empty["slots"]
    assert absent["score"] == empty["score"]


# --- P2-61 : proximité de PLACEMENT (phase 1, poids 9) ---------------------------------


def _pref_payload(previous: list[dict[str, Any]] | None = None) -> dict[str, Any]:
    """Fixture « source sous l'optimum » : tier D, lundi 18:00 (précédent) vs mercredi 20:00
    favorisé par une PREFERRED TIME (+5, sous le poids de proximité 9)."""
    payload = make_payload(
        teams=[make_team(TEAM, sessions_per_week=1, priority_tier_id=5)],
        venues=[make_venue(VENUE, PREF_SLOTS)],
        constraints=[
            team_constraint(
                constraint_id="prefer-late",
                team_id=TEAM,
                family="TIME",
                rule_type="PREFERRED",
                config={"minStartTime": "19:00"},
            )
        ],
    )
    if previous is not None:
        payload["previousAssignments"] = previous
    return payload


def test_proximity_never_drops_a_session() -> None:
    """P2-61 — patron « jamais de suppression » rejoué sous proximité (fixture source-sous-optimum) :
    la séance reste PLACÉE (1 séance), la proximité ne fait que la ramener au lundi précédent."""
    pinned = solve_payload(_pref_payload([_prev(1, "18:00")]), timeout=10)
    assert pinned["status"] == "completed"
    assert len(_placed_days(pinned)) == 1, "aucune séance supprimée pour la proximité"
    assert _placed_days(pinned) == [1], "la proximité restitue le lundi précédent"


def test_reported_score_excludes_the_proximity_bonus() -> None:
    """P2-61 — score RAPPORTÉ aux poids d'ORIGINE, bonus de proximité EXCLU. Sans previous →
    mercredi 20:00 (+5 preferred_time) ; avec previous=lundi → lundi 18:00 (pas de préférence). Le
    score AVEC proximité vaut EXACTEMENT celui SANS, moins les 5 de préférence perdus : la
    proximité (9) n'entre PAS dans le score rapporté."""
    without = solve_payload(_pref_payload(), timeout=10)
    with_prev = solve_payload(_pref_payload([_prev(1, "18:00")]), timeout=10)
    assert without["status"] == with_prev["status"] == "completed"
    assert _placed_days(without) == [3], "sans previous, la préférence (+5) fixe mercredi"
    assert _placed_days(with_prev) == [1], "avec previous, la proximité fixe lundi"
    assert with_prev["score"] == without["score"] - 5, (
        f"le score doit exclure la proximité : {with_prev['score']} != {without['score']} - 5"
    )


# --- « une contrainte ajoutée ne bouge QUE ce qui doit » -------------------------------

TEAMS = ("t1", "t2", "t3")
SPREAD_VENUE = "venue-spread"
SPREAD_SLOTS = [(1, "18:00"), (2, "18:00"), (3, "18:00"), (4, "18:00"), (5, "18:00")]


def _spread_payload(
    previous: list[dict[str, Any]] | None = None, constraints: list[dict[str, Any]] | None = None
) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team(t, sessions_per_week=1, priority_tier_id=5) for t in TEAMS],
        venues=[make_venue(SPREAD_VENUE, SPREAD_SLOTS)],
        constraints=constraints,
    )
    if previous is not None:
        payload["previousAssignments"] = previous
    return payload


def _placement_by_team(result: dict[str, Any]) -> dict[str, tuple[int, str]]:
    out: dict[str, tuple[int, str]] = {}
    for s in result["slots"]:
        out[str(s["teamId"])] = (int(s["dayOfWeek"]), str(s["startTime"])[:5])
    return out


def test_added_hard_moves_only_the_touched_team() -> None:
    """Solve, épingle TOUT le monde, interdit (HARD) le créneau d'UNE équipe, re-solve
    AVEC ``previousAssignments`` : seule l'équipe touchée (+ cascade éventuelle) bouge.

    Mesure : le nombre de séances déplacées doit valoir 1 (l'équipe interdite migre vers un
    créneau libre ; les deux autres restent épinglées par la stabilité)."""
    baseline = solve_payload(_spread_payload(), timeout=15)
    assert baseline["status"] == "completed"
    placed = _placement_by_team(baseline)
    assert len(placed) == len(TEAMS), f"chaque équipe placée, obtenu {placed}"

    previous = [
        {"teamId": t, "venueId": SPREAD_VENUE, "dayOfWeek": d, "startTime": st} for t, (d, st) in placed.items()
    ]
    touched = TEAMS[0]
    forbidden_day = placed[touched][0]
    constraints = [
        team_constraint(
            constraint_id="forbid-touched",
            team_id=touched,
            family="DAY",
            rule_type="HARD",
            config={"forbiddenDays": [forbidden_day]},
        )
    ]
    resolved = solve_payload(_spread_payload(previous=previous, constraints=constraints), timeout=15)
    assert resolved["status"] == "completed"
    after = _placement_by_team(resolved)

    moved = [t for t in TEAMS if placed.get(t) != after.get(t)]
    assert moved == [touched], f"seule l'équipe touchée doit bouger ; déplacées={moved} (avant={placed}, après={after})"
    # Falsification : sans la contrainte, la stabilité restitue TOUT à l'identique.
    unchanged = solve_payload(_spread_payload(previous=previous), timeout=15)
    assert _placement_by_team(unchanged) == placed, "sans obstacle, la stabilité fige tout le monde"
