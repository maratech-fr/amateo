"""Un planning produit par le best-effort RESTE RETOUCHABLE (décision fondateur 2026-10-30, réservation
souveraine, contre-revue).

« Le verdict ne refuse jamais un planning issu du best-effort pour une règle déjà levée. » Quand une
réservation a privé une règle de sa case, ``/generate`` produit le planning sans cette règle, mais
``/validate-assignments`` repose toute la couche dure : sans précaution le planning figé n'est plus
faisable, et le gestionnaire ne peut plus rien déplacer (``baseline_infeasible``).

Promesses, sur les trois règles que le best-effort lève (jour imposé, plancher de gymnase, bloc) :
  * déplacer une équipe SANS RAPPORT est accepté ;
  * poser à la main la séance manquante est PERMIS même hors de sa règle levée, et SIGNALÉ ;
  * TÉMOIN : un déplacement qui viole une règle NON levée reste refusé et nommé. Sans lui, un verdict
    qui ne poserait plus aucune règle passerait tout ce fichier.

Chaque décor passe par le VRAI ``/generate`` : le planning figé est celui que le moteur a produit,
jamais une fabrication du test. Angle verdict de ``test_sovereign_reservations.py`` (angle génération).
"""

from __future__ import annotations

from typing import Any

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.support.pipeline import (
    as_validate_payload,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_constraint,
)

FRIDAY = 5
SATURDAY = 6


def _named(team_id: str, name: str, *, sessions_per_week: int = 1) -> dict[str, Any]:
    return {**make_team(team_id, sessions_per_week=sessions_per_week), "name": name}


def _pin(team_id: str, venue_id: str, day: int, start: str = "18:00") -> dict[str, Any]:
    return {
        "id": f"pin-{team_id}-{venue_id}-{day}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _rule(rule_id: str, team_id: str, family: str, config: dict[str, Any], name: str) -> dict[str, Any]:
    return team_constraint(
        constraint_id=rule_id, team_id=team_id, family=family, rule_type="HARD", config=config, name=name
    )


def _case(team_id: str, venue_id: str, day: int, start: str = "18:00") -> dict[str, Any]:
    return {"teamId": team_id, "venueId": venue_id, "dayOfWeek": day, "startTime": start, "durationMinutes": 90}


def _generated(payload: dict[str, Any]) -> dict[str, Any]:
    """Le planning du moteur, repris comme le fait le backend : verrous + séances placées en ``NONE``.
    Refuse un décor qui n'aurait pas réellement traversé le best-effort."""
    result = solve_payload(payload)
    assert result["status"] == "completed", f"décor : planning attendu, obtenu {result['status']}"
    assert [d for d in result["diagnostics"] if d.get("type") in {"rule_sacrificed", "blocked_by_reservation"}], (
        f"décor : le best-effort doit avoir levé une règle, diagnostics : {result['diagnostics']}"
    )
    locked = {(t["teamId"], t["venueId"], t["dayOfWeek"], t["startTime"][:5]) for t in payload["slotTemplates"]}
    placed = [
        {
            "id": f"placed-{index}",
            **_case(slot["teamId"], slot["venueId"], slot["dayOfWeek"], slot["startTime"][:5]),
            "lockLevel": "NONE",
        }
        for index, slot in enumerate(result["slots"])
        if (slot["teamId"], slot["venueId"], slot["dayOfWeek"], slot["startTime"][:5]) not in locked
    ]
    return {**payload, "slotTemplates": [*payload["slotTemplates"], *placed]}


def _verdict(baseline: dict[str, Any], candidate: dict[str, Any], reference: dict[str, Any] | None) -> dict[str, Any]:
    # Le verdict plafonne son budget à 10 s, la génération non : même décor, budget du rail verdict.
    payload = {**baseline, "solverTimeoutSeconds": 5, "candidate": candidate}
    if reference is not None:
        payload["reference"] = reference
    return validate_assignment(ValidateAssignmentsInputSchema.model_validate(as_validate_payload(payload)))


def _free_days(baseline: dict[str, Any], venue_id: str, days: tuple[int, ...]) -> list[int]:
    occupied = {t["dayOfWeek"] for t in baseline["slotTemplates"] if t["venueId"] == venue_id}
    return [day for day in days if day not in occupied]


def _forced_day_taken() -> dict[str, Any]:
    """T impose le vendredi, dont la seule case est réservée par U : T reste non placée. X, sans rapport,
    ne peut ni le mercredi ni le samedi, et reste donc libre entre lundi et mardi."""
    return make_payload(
        teams=[_named("T", "Seniors A"), _named("U", "Cadets"), _named("X", "Minimes")],
        venues=[make_venue("V", [(1, "18:00"), (2, "18:00"), (3, "18:00"), (FRIDAY, "18:00"), (SATURDAY, "18:00")])],
        constraints=[
            _rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé"),
            _rule("xd", "X", "DAY", {"forbiddenDays": [3, SATURDAY]}, "X jamais mercredi ni samedi"),
        ],
        slot_templates=[_pin("U", "V", FRIDAY)],
    )


def _floor_taken() -> dict[str, Any]:
    """T exige une séance à W, dont la seule case est réservée par U. X, sans rapport, joue à V."""
    return make_payload(
        teams=[_named("T", "Seniors A"), _named("U", "Cadets"), _named("X", "Minimes")],
        venues=[make_venue("V", [(1, "18:00"), (3, "18:00"), (4, "18:00")]), make_venue("W", [(2, "18:00")])],
        constraints=[
            _rule("f", "T", "FACILITY", {"minAtVenueId": "W", "minAtVenueCount": 1}, "Au moins 1 à W"),
            _rule("xd", "X", "DAY", {"forbiddenDays": [3]}, "X jamais le mercredi"),
        ],
        slot_templates=[_pin("U", "W", 2)],
    )


def _block_taken() -> dict[str, Any]:
    """Le groupe t1+t2 ne peut se réunir que sur la case de lundi, réservée par t3. X, sans rapport, a
    jeudi et samedi pour lui."""
    payload = make_payload(
        teams=[make_team("t1"), make_team("t2"), make_team("t3"), _named("X", "Minimes")],
        venues=[make_venue("V", [(1, "18:00"), (2, "18:00"), (3, "18:00"), (4, "18:00"), (SATURDAY, "18:00")])],
        constraints=[
            _rule("a", "t1", "DAY", {"forbiddenDays": [2, 4, SATURDAY]}, "t1 lundi ou mercredi"),
            _rule("b", "t2", "DAY", {"forbiddenDays": [3, 4, SATURDAY]}, "t2 lundi ou mardi"),
        ],
        slot_templates=[_pin("t3", "V", 1)],
    )
    payload["sharedBlocks"] = [{"id": "b", "teamIds": ["t1", "t2"], "commonSessions": 1}]
    return payload


def _move_x_to(baseline: dict[str, Any], day: int) -> dict[str, Any]:
    placed = next(t for t in baseline["slotTemplates"] if t["teamId"] == "X")
    reference = _case("X", placed["venueId"], placed["dayOfWeek"], placed["startTime"])
    # Comme le backend : la séance qui bouge sort du planning figé, sa case d'origine part en référence.
    others = [t for t in baseline["slotTemplates"] if t is not placed]
    return _verdict({**baseline, "slotTemplates": others}, _case("X", "V", day), reference)


def test_after_a_forced_day_lost_to_a_reservation_an_unrelated_team_can_still_move() -> None:
    baseline = _generated(_forced_day_taken())

    verdict = _move_x_to(baseline, _free_days(baseline, "V", (1, 2))[0])

    assert verdict["valid"] is True, f"déplacer une équipe sans rapport reste permis : {verdict['violations']}"
    assert verdict.get("indeterminate") is not True


def test_the_missing_session_of_a_forced_day_can_be_placed_by_hand_outside_its_rule_and_is_flagged() -> None:
    baseline = _generated(_forced_day_taken())

    verdict = _verdict(baseline, _case("T", "V", _free_days(baseline, "V", (1, 2, 3))[0]), None)

    assert verdict["valid"] is True, f"la pose manuelle hors de la règle levée est permise : {verdict['violations']}"
    _assert_flagged(verdict, "Vendredi imposé")


def test_after_a_venue_floor_lost_to_a_reservation_an_unrelated_team_can_still_move() -> None:
    baseline = _generated(_floor_taken())

    verdict = _move_x_to(baseline, _free_days(baseline, "V", (1, 4))[0])

    assert verdict["valid"] is True, f"déplacer une équipe sans rapport reste permis : {verdict['violations']}"


def test_the_session_of_a_lost_venue_floor_can_be_moved_by_hand_outside_it_and_is_flagged() -> None:
    """Décision 2026-10-30 : un plancher n'interdit pas les autres gymnases, T joue déjà à V. Déplacer cette
    séance reste permis, hors du plancher levé, et signalé."""
    baseline = _generated(_floor_taken())
    placed = next(t for t in baseline["slotTemplates"] if t["teamId"] == "T")
    others = [t for t in baseline["slotTemplates"] if t is not placed]
    reference = _case("T", placed["venueId"], placed["dayOfWeek"], placed["startTime"])

    verdict = _verdict(
        {**baseline, "slotTemplates": others}, _case("T", "V", _free_days(baseline, "V", (1, 3, 4))[0]), reference
    )

    assert verdict["valid"] is True, f"la pose manuelle hors de la règle levée est permise : {verdict['violations']}"
    _assert_flagged(verdict, "Au moins 1 à W")


def test_after_a_block_lost_to_a_reservation_an_unrelated_team_can_still_move() -> None:
    baseline = _generated(_block_taken())

    verdict = _move_x_to(baseline, _free_days(baseline, "V", (2, 3, 4, SATURDAY))[0])

    assert verdict["valid"] is True, f"déplacer une équipe sans rapport reste permis : {verdict['violations']}"


def test_a_move_breaking_a_rule_that_was_never_lifted_stays_refused_and_named() -> None:
    """TÉMOIN : dans le même planning, X a sa propre règle non levée. Le verdict la tient."""
    baseline = _generated(_forced_day_taken())

    verdict = _move_x_to(baseline, SATURDAY)

    assert verdict["valid"] is False, "une règle non levée reste une règle : le déplacement est refusé"
    assert [v["rule"] for v in verdict["violations"]] == ["day_rule"], (
        f"le refus nomme la vraie règle, jamais baseline_infeasible : {verdict['violations']}"
    )


def test_a_move_onto_a_case_occupied_stays_refused_on_a_best_effort_schedule() -> None:
    """TÉMOIN : la capacité reste tenue après un best-effort, seule la règle levée s'efface."""
    baseline = _generated(_floor_taken())
    occupied = next(t for t in baseline["slotTemplates"] if t["teamId"] == "X")

    verdict = _verdict(baseline, _case("T", occupied["venueId"], occupied["dayOfWeek"], occupied["startTime"]), None)

    assert verdict["valid"] is False, "une case pleine reste pleine"
    assert "baseline_infeasible" not in {v["rule"] for v in verdict["violations"]}


def _assert_flagged(verdict: dict[str, Any], rule_label: str) -> None:
    """Le signal d'une pose hors règle : une alerte du verdict accepté, qui nomme la règle levée."""
    flagged = [c for c in verdict["compromises"] if c["effect"] == "broken" and rule_label in c["message"]]
    assert flagged, f"la pose hors règle doit être signalée en nommant {rule_label!r} : {verdict['compromises']}"


# ── Hors best-effort : le moteur ne connaît pas l'origine du planning ────────────────────────────────
#
# Le verdict lève toute règle de demande que le planning figé ne tient pas, qu'elle ait été perdue au
# best-effort ou ajoutée après la génération. Ces tests fixent ce que voit alors le gestionnaire : le geste
# passe, la pose hors règle est signalée dans des termes vrais, et une règle tenue reste tenue.


def _normal_schedule(constraint: dict[str, Any]) -> dict[str, Any]:
    """Un planning de génération ordinaire, sans verrou : T lundi, X mardi. La règle arrive APRÈS."""
    return make_payload(
        teams=[_named("T", "Seniors A"), _named("X", "Minimes")],
        venues=[
            make_venue("V", [(1, "18:00"), (2, "18:00"), (3, "18:00"), (4, "18:00"), (FRIDAY, "18:00")]),
            make_venue("W", [(SATURDAY, "18:00")]),
        ],
        constraints=[constraint],
        slot_templates=[
            {"id": "t", **_case("T", "V", 1), "lockLevel": "NONE"},
            {"id": "x", **_case("X", "V", 2), "lockLevel": "NONE"},
        ],
    )


def _moved(baseline: dict[str, Any], team_id: str, day: int, venue_id: str = "V") -> dict[str, Any]:
    placed = next(t for t in baseline["slotTemplates"] if t["teamId"] == team_id)
    others = [t for t in baseline["slotTemplates"] if t is not placed]
    reference = _case(team_id, placed["venueId"], placed["dayOfWeek"], placed["startTime"])
    return _verdict({**baseline, "slotTemplates": others}, _case(team_id, venue_id, day), reference)


_FORCED_FRIDAY = _rule("late", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")
_FLOOR_AT_W = _rule("late", "T", "FACILITY", {"minAtVenueId": "W", "minAtVenueCount": 1}, "Au moins 1 à W")


def test_a_rule_added_after_generation_does_not_block_an_unrelated_move_and_says_nothing_about_it() -> None:
    """Une équipe sans rapport bouge, le verdict ne lui attribue pas la règle des autres."""
    verdict = _moved(_normal_schedule(_FORCED_FRIDAY), "X", 3)

    assert verdict["valid"] is True, verdict["violations"]
    assert [c for c in verdict["compromises"] if c["family"] == "lifted_rule"] == []


def test_a_team_already_outside_a_rule_added_later_can_move_and_the_message_says_so() -> None:
    """T ne tenait pas la règle ajoutée, elle reste dehors : permis, et la phrase n'affirme pas d'où vient l'écart."""
    verdict = _moved(_normal_schedule(_FORCED_FRIDAY), "T", 3)

    assert verdict["valid"] is True, verdict["violations"]
    assert [c["message"] for c in verdict["compromises"] if c["family"] == "lifted_rule"] == [
        "Cette séance est posée hors de la règle « Vendredi imposé » de Seniors A : le planning généré ne la "
        "tenait pas déjà, ou elle a été ajoutée depuis."
    ]


def test_a_session_placed_outside_a_lifted_group_names_the_group_in_good_french() -> None:
    """La séance d'un groupe levé, posée à la main hors du groupe : permis, et la phrase dit « hors du groupe »."""
    payload = _block_taken()
    names = {"t1": "U11 A", "t2": "U11 B"}
    payload["teams"] = [{**team, "name": names.get(team["id"], team["name"])} for team in payload["teams"]]

    verdict = _verdict(_generated(payload), _case("t1", "V", 3), None)

    assert verdict["valid"] is True, verdict["violations"]
    assert [c["message"] for c in verdict["compromises"] if c["family"] == "lifted_rule"] == [
        "Cette séance est posée hors du groupe U11 A, U11 B : le planning généré ne le tenait pas déjà, "
        "ou il a été formé depuis."
    ]


def test_a_team_moving_onto_a_rule_added_later_is_accepted_without_any_flag() -> None:
    """Le geste qui remet T dans la règle n'est pas signalé comme un écart."""
    verdict = _moved(_normal_schedule(_FORCED_FRIDAY), "T", FRIDAY)

    assert verdict["valid"] is True, verdict["violations"]
    assert [c for c in verdict["compromises"] if c["family"] == "lifted_rule"] == []


def test_a_venue_floor_added_later_behaves_the_same_way() -> None:
    verdict = _moved(_normal_schedule(_FLOOR_AT_W), "T", 3)

    assert verdict["valid"] is True, verdict["violations"]
    _assert_flagged(verdict, "Au moins 1 à W")


def test_a_rule_the_normal_schedule_holds_stays_enforced() -> None:
    """TÉMOIN : hors best-effort aussi, sortir d'une règle TENUE est refusé. C'est la limite de l'effet de bord."""
    baseline = _normal_schedule(_FORCED_FRIDAY)
    baseline["slotTemplates"][0] = {"id": "t", **_case("T", "V", FRIDAY), "lockLevel": "NONE"}

    verdict = _moved(baseline, "T", 3)

    assert verdict["valid"] is False, "T tient son vendredi : la déplacer ailleurs casse la règle"
    assert "baseline_infeasible" not in {v["rule"] for v in verdict["violations"]}


def test_removing_a_session_then_placing_it_outside_a_held_rule_is_accepted_and_flagged() -> None:
    """CONSTAT : retirer la séance puis la reposer contourne le refus du témoin ci-dessus. Sans référence, le
    planning figé n'a plus T : la règle compte pour « déjà levée ». Le geste passe, signalé."""
    baseline = _normal_schedule(_FORCED_FRIDAY)
    baseline["slotTemplates"] = [t for t in baseline["slotTemplates"] if t["teamId"] != "T"]

    verdict = _verdict(baseline, _case("T", "V", 3), None)

    assert verdict["valid"] is True, verdict["violations"]
    _assert_flagged(verdict, "Vendredi imposé")


# ── Geste relevé à l'écran : un refus nomme TOUJOURS sa règle ───────────────────────────────────────


def _seniors_before_younger() -> dict[str, Any]:
    """Le décor de l'écran : Seniors A a perdu son vendredi, la seule case libre est lundi 18:00, juste avant
    Minimes (19:30). Les âges rendent ce lundi contraire à « les plus jeunes d'abord », règle implicite dure."""
    return make_payload(
        teams=[
            {**_named("T", "Seniors A"), "ageMin": 35, "ageMax": 99},
            {**_named("U", "Cadets"), "ageMin": 15, "ageMax": 16},
            {**_named("X", "Minimes"), "ageMin": 13, "ageMax": 14},
        ],
        venues=[make_venue("V", [(1, "18:00"), (1, "19:30"), (FRIDAY, "18:00")])],
        constraints=[_rule("d", "T", "DAY", {"forcedDays": [FRIDAY]}, "Vendredi imposé")],
        slot_templates=[_pin("U", "V", FRIDAY)],
    )


def test_a_hand_placement_refused_by_the_age_order_names_that_rule_and_both_teams() -> None:
    """La règle levée n'y est pour rien : c'est l'ordre des âges qui refuse. Le refus le dit, avec les deux
    équipes. Aujourd'hui : ``unknown_hard_conflict``, « une règle qui n'a pas pu être nommée »."""
    baseline = _generated(_seniors_before_younger())
    assert [t["dayOfWeek"] for t in baseline["slotTemplates"] if t["teamId"] == "X"] == [1], "décor : Minimes lundi"
    baseline["slotTemplates"] = [
        {**t, "startTime": "19:30"} if t["teamId"] == "X" else t for t in baseline["slotTemplates"]
    ]

    verdict = _verdict(baseline, _case("T", "V", 1), None)

    assert verdict["valid"] is False
    assert "unknown_hard_conflict" not in {v["rule"] for v in verdict["violations"]}, verdict["violations"]
    message = " ".join(v["message"] for v in verdict["violations"])
    assert "Seniors A" in message and "Minimes" in message, message


def test_the_same_hand_placement_passes_and_is_flagged_when_the_age_order_is_only_preferred() -> None:
    """TÉMOIN : l'ordre des âges est bien la seule cause. Assoupli, la pose hors règle levée est permise et
    signalée, comme le fondateur l'a décidé."""
    payload = _seniors_before_younger()
    payload["implicitRules"] = {"ageAscending": {"intensity": "PREFERRED"}}
    baseline = _generated(payload)
    baseline["slotTemplates"] = [
        {**t, "startTime": "19:30"} if t["teamId"] == "X" else t for t in baseline["slotTemplates"]
    ]

    verdict = _verdict(baseline, _case("T", "V", 1), None)

    assert verdict["valid"] is True, verdict["violations"]
    _assert_flagged(verdict, "Vendredi imposé")
