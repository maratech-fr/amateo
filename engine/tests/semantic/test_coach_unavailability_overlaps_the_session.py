"""Une pose LIBRE refuse un coach indisponible pendant une PARTIE de la séance, pas seulement à son début.

NR de l'axe §7.1 *constraint semantics*, à travers le vrai pipeline. Deux rails, à ne pas confondre :
  * **pose libre** (génération d'une séance non réservée, candidat d'un déplacement) : chevauchement
    demi-ouvert sans marge, ``de < fin et début < à``. Pour 18:00-19:30, une plage dès 19:00 ferme la
    case, une plage dès 19:30 ou jusqu'à 18:00 ne la ferme pas ;
  * **rail de l'existant** (verrous, séances déjà au planning) : la règle du DÉBUT, inchangée. Un
    planning en vigueur ne devient ni infaisable ni accusé à cause de cette règle.

Jumeaux VOULUS (point sensible solveur) : ``CrossStack/OneCoachHoldsTheSessionGateTest`` (contrat HTTP,
le step qui gate) ; ``Unit/Service/PreSolvePreventionWarningsTest`` (le Récap dit la même chose avant de
générer) ; ``test_coach_window.py`` (la fenêtre horaire, sur des créneaux qu'elle ne coupe pas).
"""

from __future__ import annotations

from typing import Any

import pytest

from app.schemas.validate_input_schema import ValidateAssignmentsInputSchema
from app.solver.validate_assignments import validate_assignment
from tests.semantic.test_sovereign_reservations import _only_sacrifice_of_kind
from tests.support.pipeline import (
    as_validate_payload,
    coach_availability,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_coach,
    team_constraint,
)

MONDAY = 1
TUESDAY = 2
WEDNESDAY = 3
THURSDAY = 4

U15_MISSING_FACT = (
    "L'équipe U15 : 1 séance(s) demandée(s) par semaine, seulement 0 placée(s) — en-dessous de son minimum cible de 1."
)
STATIC_SUGGESTIONS = [
    "Ajoutez de la disponibilité de gymnase ou un créneau supplémentaire pour cette équipe.",
    "Vérifiez le tier de priorité et le nombre de séances/semaine de l'équipe.",
]


def _coaches() -> list[dict[str, Any]]:
    return [
        {"id": "anna", "firstName": "Anna", "lastName": "Martin", "isEmployee": False},
        {"id": "ben", "firstName": "Ben", "lastName": "Durand", "isEmployee": False},
    ]


def _anna_off(*, from_time: str | None = None, until_time: str | None = None, coach_id: str = "anna") -> dict[str, Any]:
    """L'indisponibilité du lundi, telle que l'onglet Dispo coach l'écrit, avec un nom pour la nommer."""
    rule = coach_availability(
        f"off-{coach_id}", coach_id, unavailable_days=[MONDAY], from_time=from_time, until_time=until_time
    )
    return {**rule, "name": f"Indispo de {coach_id}"}


def _solve_u15(*off: dict[str, Any], coaches: tuple[str, ...] = ("anna",)) -> dict[str, Any]:
    """U15, une séance, l'unique case lundi 18:00-19:30 : la case tient ou ne tient pas."""
    links = [team_coach(f"tc-{coach}", "U15", coach) for coach in coaches]
    return solve_payload(
        make_payload(
            teams=[make_team("U15")],
            venues=[make_venue("V", [(MONDAY, "18:00")])],
            coaches=_coaches(),
            constraints=[*links, *off],
        )
    )


def _diagnostics_of(result: dict[str, Any], diagnostic_type: str) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d.get("type") == diagnostic_type]


def _case(team_id: str, venue_id: str, day: int) -> dict[str, Any]:
    return {"teamId": team_id, "venueId": venue_id, "dayOfWeek": day, "startTime": "18:00", "durationMinutes": 90}


def _frozen(team_id: str, venue_id: str, day: int) -> dict[str, Any]:
    """Une séance déjà au planning, comme le backend la renvoie au verdict (``NONE`` = figée)."""
    return {"id": f"frozen-{team_id}", **_case(team_id, venue_id, day), "lockLevel": "NONE"}


def _locked(team_id: str, venue_id: str, day: int) -> dict[str, Any]:
    return {"id": f"lock-{team_id}", **_case(team_id, venue_id, day), "lockLevel": "HARD"}


def _verdict(
    frozen: list[dict[str, Any]],
    moved: dict[str, Any],
    target_day: int,
    *off: dict[str, Any],
    coaches_of_u15: tuple[str, ...] = ("anna",),
) -> dict[str, Any]:
    """Déplace ``moved`` sur ``target_day`` à 18:00 dans le même gymnase. ``frozen`` = le RESTE du planning, comme
    le backend l'envoie : la séance déplacée n'y figure pas, elle voyage en ``reference``."""
    links = [
        *(team_coach(f"tc-u15-{coach}", "U15", coach) for coach in coaches_of_u15),
        team_coach("tc-u13", "U13", "ben"),
    ]
    payload = make_payload(
        teams=[make_team("U15"), make_team("U13")],
        venues=[
            make_venue("V", [(MONDAY, "18:00"), (TUESDAY, "18:00"), (WEDNESDAY, "18:00")]),
            make_venue("W", [(MONDAY, "18:00"), (TUESDAY, "18:00"), (WEDNESDAY, "18:00")]),
        ],
        coaches=_coaches(),
        constraints=[*links, *off],
        slot_templates=frozen,
        timeout=5,
    )
    reference = _case(moved["teamId"], moved["venueId"], moved["dayOfWeek"])
    candidate = _case(moved["teamId"], moved["venueId"], target_day)
    return validate_assignment(
        ValidateAssignmentsInputSchema.model_validate(
            as_validate_payload({**payload, "candidate": candidate, "reference": reference})
        )
    )


# ── 2.1 Pose libre : le chevauchement ferme la case ──────────────────────────────────────────────────


def test_an_unavailability_starting_during_the_session_closes_the_case() -> None:
    """Dès 19:00 pour 18:00-19:30 : Anna partirait avant la fin, la séance n'est pas posée."""
    result = _solve_u15(_anna_off(from_time="19:00"))

    assert result["slots"] == [], "le coach seul part pendant la séance : la case est fermée"


@pytest.mark.parametrize(
    "window",
    [{"from_time": "19:30"}, {"from_time": "16:00", "until_time": "18:00"}],
    ids=["des-la-fin", "jusqu-au-debut"],
)
def test_an_unavailability_touching_the_session_leaves_the_case_open(window: dict[str, str]) -> None:
    """TÉMOIN sans marge : une plage qui ne fait que toucher la séance (dès 19:30, jusqu'à 18:00) ne ferme rien."""
    result = _solve_u15(_anna_off(**window))

    assert [slot["teamId"] for slot in result["slots"]] == ["U15"]


def test_the_other_coach_holds_the_session_the_first_one_would_leave() -> None:
    """Un coach suffit, au chevauchement aussi : Anna part à 19:00, Ben tient toute la séance et il est nommé."""
    result = _solve_u15(_anna_off(from_time="19:00"), coaches=("anna", "ben"))

    assert [(slot["teamId"], slot["coachId"]) for slot in result["slots"]] == [("U15", "ben")]


def test_a_coach_leaving_during_the_session_is_not_counted_to_hold_it() -> None:
    """U15 = Anna + Ben, U13 = Ben, deux gymnases, une seule case : Anna partant à 19:00, Ben ne se dédouble pas.

    Jumeau du gate HTTP ``OneCoachHoldsTheSessionGateTest`` : ici, le détail côté moteur.
    """
    result = solve_payload(
        make_payload(
            teams=[make_team("U15"), make_team("U13")],
            venues=[make_venue("V", [(MONDAY, "18:00")]), make_venue("W", [(MONDAY, "18:00")])],
            coaches=_coaches(),
            constraints=[
                team_coach("tc-u15-a", "U15", "anna"),
                team_coach("tc-u15-b", "U15", "ben"),
                team_coach("tc-u13", "U13", "ben"),
                _anna_off(from_time="19:00"),
            ],
        )
    )

    assert len(result["slots"]) == 1, f"Ben seul peut tenir, une seule séance est posée : {result['slots']}"


def _block_solve(*off: dict[str, Any]) -> dict[str, Any]:
    payload = make_payload(
        teams=[make_team("U11A"), make_team("U11B")],
        venues=[make_venue("V", [(MONDAY, "18:00")])],
        coaches=_coaches(),
        constraints=[team_coach("tc-a", "U11A", "anna"), team_coach("tc-b", "U11B", "ben"), *off],
    )
    payload["sharedBlocks"] = [{"id": "b", "teamIds": ["U11A", "U11B"], "commonSessions": 1}]
    return solve_payload(payload)


def test_a_block_whose_whole_union_leaves_during_the_session_is_closed_and_counts_its_causes() -> None:
    """Toute l'union du groupe part à 19:00 : la case ne sert pas le groupe, et le diagnostic compte la case
    fermée par chaque indisponibilité, mesurée au chevauchement comme la pose."""
    result = _block_solve(_anna_off(from_time="19:00"), _anna_off(from_time="19:00", coach_id="ben"))

    assert result["status"] == "failed", f"groupe sans coach sur son unique case : {result['status']}"
    diagnostics = _diagnostics_of(result, "shared_block_not_honored")
    assert diagnostics, f"le groupe est nommé : {result['diagnostics']}"
    causes = {(c["constraintId"], c["count"]) for c in diagnostics[0]["causes"]}
    assert causes == {("off-anna", 1), ("off-ben", 1)}


def test_a_block_keeps_its_case_while_one_coach_of_the_union_stays() -> None:
    """TÉMOIN : Ben reste toute la séance, le groupe s'entraîne ensemble."""
    result = _block_solve(_anna_off(from_time="19:00"))

    assert result["status"] == "completed"
    assert sorted(slot["teamId"] for slot in result["slots"]) == ["U11A", "U11B"]


def _reserved_block_solve(*locked_teams: str) -> dict[str, Any]:
    """Le groupe U11A + U11B, toute son union partant à 19:00, les membres donnés déjà réservés sur sa case."""
    payload = make_payload(
        teams=[make_team("U11A"), make_team("U11B")],
        venues=[make_venue("V", [(MONDAY, "18:00")], capacity=2)],
        coaches=_coaches(),
        constraints=[
            team_coach("tc-a", "U11A", "anna"),
            team_coach("tc-b", "U11B", "ben"),
            _anna_off(from_time="19:00"),
            _anna_off(from_time="19:00", coach_id="ben"),
        ],
        slot_templates=[_locked(team, "V", MONDAY) for team in locked_teams],
    )
    payload["sharedBlocks"] = [{"id": "b", "teamIds": ["U11A", "U11B"], "commonSessions": 1}]
    return solve_payload(payload)


def test_a_block_whose_members_are_all_reserved_stays_judged_at_its_start() -> None:
    """Rail de l'existant : un groupe entièrement réservé ne rend pas la génération infaisable, son union part à
    19:00 mais tient la séance à son début."""
    result = _reserved_block_solve("U11A", "U11B")

    assert result["status"] == "completed", f"le groupe réservé est gardé : {result['diagnostics']}"
    assert sorted(slot["teamId"] for slot in result["slots"]) == ["U11A", "U11B"]
    assert _diagnostics_of(result, "conflict") == []
    assert _diagnostics_of(result, "shared_block_not_honored") == []


def test_a_block_with_a_member_left_to_place_is_judged_on_the_overlap() -> None:
    """TÉMOIN : U11B reste à poser, c'est une pose libre. La case ne sert pas le groupe, U11B n'y rejoint pas U11A."""
    result = _reserved_block_solve("U11A")

    assert [slot["teamId"] for slot in result["slots"]] == ["U11A"], f"{result['status']} : {result['diagnostics']}"
    assert result["status"] == "failed", "un membre reste à poser sur une case sans coach : l'échec reste nommé"


# ── 2.1 bis Groupe réservé dont l'union est absente au début : la réservation gagne ──────────────────
# Jumeaux VOULUS (point sensible solveur, mutualisation) : ``CrossStack/SharedBlockPreSolveMirrorParityTest``
# (les deux faces, Récap et moteur, sur le même scénario) ; ``Unit/Service/PreSolvePreventionWarningsTest``.

GROUP_ALERT_SUGGESTION = "Déplacez la séance fixée ou rendez un coach du groupe disponible à 18:00."


def _nord_lock(team_id: str, duration: int = 90) -> dict[str, Any]:
    return {**_locked(team_id, "Nord", MONDAY), "durationMinutes": duration}


def _solve_group(
    *off: dict[str, Any],
    locks: list[dict[str, Any]],
    links: tuple[tuple[str, str], ...] = (("A", "anna"), ("B", "ben")),
    teams: list[dict[str, Any]] | None = None,
    blocks: list[dict[str, Any]] | None = None,
    venues: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """Le groupe A + B (par défaut), réservé sur Nord le lundi 18:00 par ``locks``."""
    payload = make_payload(
        teams=teams or [make_team("A"), make_team("B")],
        venues=venues or [make_venue("Nord", [(MONDAY, "18:00")])],
        coaches=[*_coaches(), {"id": "chloe", "firstName": "Chloé", "lastName": "Petit", "isEmployee": False}],
        constraints=[*(team_coach(f"tc-{team}-{coach}", team, coach) for team, coach in links), *off],
        slot_templates=locks,
    )
    payload["sharedBlocks"] = blocks or [{"id": "ab", "teamIds": ["A", "B"], "commonSessions": 1}]
    return solve_payload(payload)


def _nested_on_monday(*off: dict[str, Any]) -> dict[str, Any]:
    """{A,B} et {A,B,C} réservés ensemble lundi : une case ne porte qu'une séance commune (distinctness), le petit
    groupe tient donc la sienne le mardi, sur une case libre."""
    return _solve_group(
        *off,
        locks=[_nord_lock("A"), _nord_lock("B"), _nord_lock("C")],
        links=(("A", "anna"), ("B", "ben"), ("C", "chloe")),
        teams=[make_team("A", sessions_per_week=2), make_team("B", sessions_per_week=2), make_team("C")],
        blocks=[
            {"id": "ab", "teamIds": ["A", "B"], "commonSessions": 1},
            {"id": "abc", "teamIds": ["A", "B", "C"], "commonSessions": 1},
        ],
        venues=[make_venue("Nord", [(MONDAY, "18:00"), (TUESDAY, "18:00")], capacity=3)],
    )


def test_a_block_whose_members_are_all_reserved_keeps_its_case_when_the_union_is_absent_at_start() -> None:
    """Anna et Ben indisponibles tout le lundi, A et B réservés lundi 18:00 : le planning sort, les deux y sont."""
    result = _solve_group(_anna_off(), _anna_off(coach_id="ben"), locks=[_nord_lock("A"), _nord_lock("B")])

    assert result["status"] == "completed", f"la réservation gagne : {result['diagnostics']}"
    assert sorted(slot["teamId"] for slot in result["slots"]) == ["A", "B"]
    assert _diagnostics_of(result, "shared_block_not_honored") == []
    assert _diagnostics_of(result, "conflict") == []


def test_a_nested_reserved_block_with_one_coach_present_keeps_the_case_in_silence() -> None:
    """Groupes {A,B} et {A,B,C} réservés lundi, seule Chloé (C) présente : le plus large tient la case avec son
    coach, un coach de l'union suffit, rien n'est signalé sur A ni B."""
    result = _nested_on_monday(_anna_off(), _anna_off(coach_id="ben"))

    assert result["status"] == "completed", f"{result['diagnostics']}"
    assert _diagnostics_of(result, "rule_sacrificed") == [], "un coach du groupe le plus large est là : silence"
    assert len(_diagnostics_of(result, "shared_block_not_honored")) <= 1


@pytest.mark.parametrize(
    ("links", "off_coaches", "reason", "causes"),
    [
        (
            (("A", "anna"),),
            ("anna",),
            "alors que Anna Martin est indisponible.",
            [("off-anna", "Indispo de anna")],
        ),
        (
            (("A", "anna"), ("B", "ben")),
            ("anna", "ben"),
            "alors qu'aucun coach du groupe n'est disponible (Anna Martin, Ben Durand).",
            [("off-anna", "Indispo de anna"), ("off-ben", "Indispo de ben")],
        ),
    ],
    ids=["un-coach", "plusieurs-coachs"],
)
def test_the_reserved_block_alert_names_the_group_the_case_and_every_coach(
    links: tuple[tuple[str, str], ...],
    off_coaches: tuple[str, ...],
    reason: str,
    causes: list[tuple[str, str]],
) -> None:
    """Une seule alerte par case, sur le groupe : les alertes par équipe sont absorbées, la durée est la plus longue
    des verrous des membres, ``teamId`` est nul, et le panneau ouvre la case à partir de ses coordonnées."""
    result = _solve_group(
        *(_anna_off(coach_id=coach) for coach in off_coaches),
        locks=[_nord_lock("A", duration=60), _nord_lock("B")],
        links=links,
    )

    alert = _only_sacrifice_of_kind(result, "coach_unavailability")
    assert len(_diagnostics_of(result, "rule_sacrificed")) == 1, f"une seule alerte : {result['diagnostics']}"
    assert alert["id"] == "rule-sacrificed-block-Nord|1|18:00"
    assert alert["message"] == f"Séance fixée du groupe A, B maintenue (Nord, lundi 18:00, 1h30) {reason}"
    assert alert["teamId"] is None
    assert (alert["venueId"], alert["dayOfWeek"], alert["startTime"]) == ("Nord", MONDAY, "18:00")
    assert [(c["kind"], c["constraintId"], c["label"], c["count"]) for c in alert["causes"]] == [
        ("coach_unavailability", constraint_id, label, 1) for constraint_id, label in causes
    ]
    assert alert["suggestions"] == [GROUP_ALERT_SUGGESTION]


def test_the_reserved_block_alert_speaks_for_the_widest_group_of_the_case() -> None:
    """Toute l'union absente, {A,B} et {A,B,C} réservés sur la case : une alerte, au nom du groupe le plus large."""
    result = _nested_on_monday(_anna_off(), _anna_off(coach_id="ben"), _anna_off(coach_id="chloe"))

    alerts = _diagnostics_of(result, "rule_sacrificed")
    assert result["status"] == "completed", f"{result['diagnostics']}"
    assert [alert["message"] for alert in alerts] == [
        "Séance fixée du groupe A, B, C maintenue (Nord, lundi 18:00, 1h30) alors qu'aucun coach du groupe n'est "
        "disponible (Anna Martin, Ben Durand, Chloé Petit)."
    ]


def test_the_group_alert_is_dropped_when_no_schedule_comes_out() -> None:
    """Un autre motif fait échouer la génération (D : jour imposé où son coach est absent) : « maintenue » serait
    faux sans planning. L'alerte du groupe est écartée, pas rétrogradée, et celles des membres restent absorbées."""
    result = _solve_group(
        _anna_off(),
        _anna_off(coach_id="ben"),
        coach_availability("off-chloe", "chloe", unavailable_days=[THURSDAY]),
        team_constraint(
            constraint_id="forced-d",
            team_id="D",
            family="DAY",
            rule_type="HARD",
            config={"forcedDays": [THURSDAY]},
            name="Jeudi imposé",
        ),
        locks=[_nord_lock("A"), _nord_lock("B")],
        links=(("A", "anna"), ("B", "ben"), ("D", "chloe")),
        teams=[make_team("A"), make_team("B"), make_team("D")],
        venues=[make_venue("Nord", [(MONDAY, "18:00")]), make_venue("Sud", [(THURSDAY, "18:00")])],
    )

    assert result["status"] == "failed", f"le conflit de D reste un échec : {result['diagnostics']}"
    assert [d["message"] for d in result["diagnostics"] if "maintenue" in str(d.get("message"))] == []


def test_a_day_whose_every_slot_is_cut_for_the_employees_is_called_impossible() -> None:
    """La règle salarié suit la pose : leur plage dès 19:00 coupe l'unique 18:00-19:30 du jeudi, le jour est
    impossible, on ne le reproche pas au planning."""
    teams = [make_team("t-1"), make_team("t-4")]
    constraints = [
        team_coach("tc-1", "t-1", "e1"),
        team_coach("tc-4", "t-4", "vol"),
        *(
            team_constraint(
                constraint_id=f"day-{day}",
                team_id=f"t-{day}",
                family="DAY",
                rule_type="HARD",
                config={"forcedDays": [day]},
            )
            for day in (1, THURSDAY)
        ),
        *(
            coach_availability(f"off-{coach}", coach, unavailable_days=[THURSDAY], from_time="19:00")
            for coach in ("e1", "e2")
        ),
    ]
    result = solve_payload(
        make_payload(
            teams=teams,
            venues=[make_venue("V", [(1, "18:00"), (THURSDAY, "18:00")])],
            coaches=[
                {"id": "e1", "firstName": "e1", "lastName": "X", "isEmployee": True},
                {"id": "e2", "firstName": "e2", "lastName": "X", "isEmployee": True},
                {"id": "vol", "firstName": "vol", "lastName": "X", "isEmployee": False},
            ],
            constraints=constraints,
            implicit_rules={"salarieDistribution": {"intensity": "HARD"}},
        )
    )

    alerts = [d["message"] for d in result["diagnostics"] if d.get("ruleKey") == "salarieDistribution"]
    assert alerts == ["Le jeudi, aucun salarié n'était disponible."]


# ── 2.2 Rail de l'existant : la règle du début, inchangée ────────────────────────────────────────────


def test_moving_another_team_stays_accepted_on_a_schedule_whose_frozen_session_is_cut() -> None:
    """U15 figée lundi 18:00, Anna seule indisponible dès 19:00 : le planning en vigueur reste faisable, le
    gestionnaire peut toujours déplacer U13. Sans cette exception, chaque geste rendrait ``baseline_infeasible``."""
    verdict = _verdict(
        [_frozen("U15", "V", MONDAY)],
        _frozen("U13", "W", TUESDAY),
        WEDNESDAY,
        _anna_off(from_time="19:00"),
    )

    assert verdict["valid"] is True, f"déplacer une autre équipe reste permis : {verdict['violations']}"
    assert "baseline_infeasible" not in {v["rule"] for v in verdict["violations"]}
    assert verdict.get("indeterminate") is not True


def test_a_lock_cut_by_an_unavailability_is_kept_without_any_new_diagnostic() -> None:
    """Le verrou U15 reste jugé au début : même décor avec et sans la plage, mêmes diagnostics, séance posée."""

    def solve(*off: dict[str, Any]) -> dict[str, Any]:
        return solve_payload(
            make_payload(
                teams=[make_team("U15")],
                venues=[make_venue("V", [(MONDAY, "18:00")])],
                coaches=_coaches(),
                constraints=[team_coach("tc", "U15", "anna"), *off],
                slot_templates=[_locked("U15", "V", MONDAY)],
            )
        )

    with_rule = solve(_anna_off(from_time="19:00"))
    without_rule = solve()

    assert with_rule["status"] == "completed"
    assert [slot["teamId"] for slot in with_rule["slots"]] == ["U15"]
    assert {(d["id"], d["message"]) for d in with_rule["diagnostics"]} == {
        (d["id"], d["message"]) for d in without_rule["diagnostics"]
    }


def _verdict_on_absent_group(*at_monday: dict[str, Any]) -> dict[str, Any]:
    """C passe du mardi au mercredi, sur un planning où A et B sont ensemble lundi, Anna et Ben absents."""
    payload = make_payload(
        teams=[make_team("A"), make_team("B"), make_team("C")],
        venues=[make_venue("Nord", [(MONDAY, "18:00"), (TUESDAY, "18:00"), (WEDNESDAY, "18:00")])],
        coaches=_coaches(),
        constraints=[
            team_coach("tc-a", "A", "anna"),
            team_coach("tc-b", "B", "ben"),
            _anna_off(),
            _anna_off(coach_id="ben"),
        ],
        slot_templates=list(at_monday),
        timeout=5,
    )
    payload["sharedBlocks"] = [{"id": "ab", "teamIds": ["A", "B"], "commonSessions": 1}]
    return validate_assignment(
        ValidateAssignmentsInputSchema.model_validate(
            as_validate_payload(
                {**payload, "candidate": _case("C", "Nord", WEDNESDAY), "reference": _case("C", "Nord", TUESDAY)}
            )
        )
    )


def test_moving_another_team_stays_accepted_on_a_schedule_carrying_the_absent_reserved_group() -> None:
    """Après une génération avec alerte, le groupe réservé sans coach ne fige pas le planning : C se déplace."""
    verdict = _verdict_on_absent_group(_locked("A", "Nord", MONDAY), _locked("B", "Nord", MONDAY))

    assert verdict["valid"] is True, f"déplacer une autre équipe reste permis : {verdict['violations']}"
    assert "baseline_infeasible" not in {v["rule"] for v in verdict["violations"]}


def test_moving_another_team_stays_accepted_after_unlocking_the_absent_group() -> None:
    """Geste inverse : A et B déverrouillés restent là, figés, sans coach au début. La base reste faisable."""
    verdict = _verdict_on_absent_group(_frozen("A", "Nord", MONDAY), _frozen("B", "Nord", MONDAY))

    assert verdict["valid"] is True, f"déplacer une autre équipe reste permis : {verdict['violations']}"
    assert "baseline_infeasible" not in {v["rule"] for v in verdict["violations"]}


# ── 2.3 Candidat d'un déplacement : le refus dit quand le coach manque ───────────────────────────────


@pytest.mark.parametrize(
    ("window", "message"),
    [
        (
            {"from_time": "19:00", "until_time": "20:00"},
            "Anna Martin est indisponible le lundi de 19:00 à 20:00, pendant cette séance.",
        ),
        ({"from_time": "19:00"}, "Anna Martin est indisponible le lundi à partir de 19:00, pendant cette séance."),
        ({}, "Anna Martin est indisponible le lundi."),
    ],
    ids=["plage-bornee", "sans-fin", "journee"],
)
def test_moving_a_session_onto_a_cut_case_is_refused_and_says_when(window: dict[str, str], message: str) -> None:
    """Le déplacement est une pose libre : refusé au chevauchement, avec la phrase de l'indisponibilité."""
    verdict = _verdict([], _frozen("U15", "V", WEDNESDAY), MONDAY, _anna_off(**window))

    assert verdict["valid"] is False
    assert [v["message"] for v in verdict["violations"] if v["rule"] == "coach_unavailable"] == [message]


@pytest.mark.parametrize(
    ("target_day", "window"),
    [(TUESDAY, {"from_time": "19:00"}), (MONDAY, {"from_time": "19:30"})],
    ids=["autre-jour", "des-la-fin"],
)
def test_moving_a_session_where_the_coach_stays_is_accepted(target_day: int, window: dict[str, str]) -> None:
    """TÉMOIN : le mardi n'est pas concerné, et une plage dès 19:30 ne coupe pas 18:00-19:30."""
    verdict = _verdict([], _frozen("U15", "V", WEDNESDAY), target_day, _anna_off(**window))

    assert verdict["valid"] is True, verdict["violations"]


def test_moving_a_session_held_by_the_other_coach_is_accepted() -> None:
    """TÉMOIN : Anna part à 19:00 mais Ben tient la séance, le déplacement passe."""
    verdict = _verdict(
        [],
        _frozen("U15", "V", WEDNESDAY),
        MONDAY,
        _anna_off(from_time="19:00"),
        coaches_of_u15=("anna", "ben"),
    )

    assert verdict["valid"] is True, verdict["violations"]


# ── 2.4 Saisie imprécise : la conséquence et le geste qui la corrige ─────────────────────────────────


def test_a_forced_day_whose_only_slot_is_cut_fails_and_names_the_unavailability() -> None:
    """Jour imposé HARD, unique créneau coupé : échec nommé, jamais un repli de relaxation."""
    result = solve_payload(
        make_payload(
            teams=[make_team("U15")],
            venues=[make_venue("V", [(MONDAY, "18:00")])],
            coaches=_coaches(),
            constraints=[
                team_coach("tc", "U15", "anna"),
                team_constraint(
                    constraint_id="forced",
                    team_id="U15",
                    family="DAY",
                    rule_type="HARD",
                    config={"forcedDays": [MONDAY]},
                    name="Lundi imposé",
                ),
                _anna_off(from_time="19:00"),
            ],
        )
    )

    assert result["status"] == "failed", f"aucun repli : {result['status']}"
    infeasible = next(d for d in result["diagnostics"] if d["id"] == "diag-infeasible")
    assert "Indispo de anna" in infeasible["message"], infeasible["message"]
    assert "Lundi imposé" in infeasible["message"], infeasible["message"]


@pytest.mark.parametrize(
    ("window", "expected"),
    [
        (
            {"from_time": "19:00"},
            "L'indisponibilité de Anna Martin dès 19:00 coupe le créneau 18:00-19:30 : si elle commence en "
            "réalité à 19:30, corrigez-la (étape Contraintes, Dispo coach).",
        ),
        (
            {"from_time": "19:00", "until_time": "20:00"},
            "L'indisponibilité de Anna Martin de 19:00 à 20:00 coupe le créneau 18:00-19:30 : si elle commence en "
            "réalité à 19:30, corrigez-la (étape Contraintes, Dispo coach).",
        ),
    ],
    ids=["des", "bornee"],
)
def test_a_session_lost_to_a_partial_unavailability_says_what_to_correct(window: dict[str, str], expected: str) -> None:
    """Le minimum de séances est un objectif : la séance manque, et le panneau dit quoi corriger si la plage a été
    saisie trop tôt. La phrase vient après le fait, dans le ``message`` : c'est lui que ``DiagnosticsPanel`` affiche,
    pas les ``suggestions``, qui restent statiques. La cause reste celle du contrat."""
    result = _solve_u15(_anna_off(**window))

    assert result["slots"] == []
    diagnostic = _diagnostics_of(result, "session_below_effective_min")[0]
    assert diagnostic["message"] == f"{U15_MISSING_FACT} {expected}"
    assert diagnostic["suggestions"] == STATIC_SUGGESTIONS
    assert [cause["constraintId"] for cause in diagnostic["causes"]] == ["off-anna"]


def test_a_session_lost_to_a_whole_day_keeps_the_static_suggestions() -> None:
    """TÉMOIN : une journée entière n'est pas une saisie imprécise, rien à corriger d'une demi-heure."""
    result = _solve_u15(_anna_off())

    assert result["slots"] == []
    diagnostic = _diagnostics_of(result, "session_below_effective_min")[0]
    assert diagnostic["message"] == U15_MISSING_FACT
    assert diagnostic["suggestions"] == STATIC_SUGGESTIONS


def _u15_missing_message(
    venues: list[dict[str, Any]], *rules: dict[str, Any], coaches: tuple[str, ...] = ("anna",)
) -> str:
    """Le ``message`` de la séance manquante de U15, sur la grille et les règles données."""
    result = solve_payload(
        make_payload(
            teams=[make_team("U15")],
            venues=venues,
            coaches=_coaches(),
            constraints=[*(team_coach(f"tc-{coach}", "U15", coach) for coach in coaches), *rules],
        )
    )

    assert result["slots"] == [], "décor : la séance doit manquer"
    message: str = _diagnostics_of(result, "session_below_effective_min")[0]["message"]
    return message


def _correction(window: str, case: str, end: str, coach: str = "Anna Martin") -> str:
    return (
        f"L'indisponibilité de {coach} {window} coupe le créneau {case} : si elle commence en réalité à {end}, "
        "corrigez-la (étape Contraintes, Dispo coach)."
    )


def _off_on_days(days: list[int], from_time: str, coach_id: str = "anna") -> dict[str, Any]:
    return coach_availability(f"off-{coach_id}-{from_time}", coach_id, unavailable_days=days, from_time=from_time)


def test_several_slots_cut_by_one_range_make_one_sentence_citing_the_first_slot() -> None:
    """Une phrase par coach et par plage : deux gymnases et deux jours coupés par la même plage ne la répètent pas,
    elle cite le premier créneau trié (heure de début)."""
    message = _u15_missing_message(
        [make_venue("V", [(TUESDAY, "18:30")]), make_venue("W", [(MONDAY, "18:00")])],
        _off_on_days([MONDAY, TUESDAY], "19:00"),
    )

    assert message == f"{U15_MISSING_FACT} {_correction('dès 19:00', '18:00-19:30', '19:30')}"


def test_two_ranges_of_a_coach_make_two_sentences() -> None:
    """TÉMOIN : deux plages différentes, deux corrections possibles, deux phrases."""
    message = _u15_missing_message(
        [make_venue("V", [(MONDAY, "18:00"), (TUESDAY, "18:00")])],
        _off_on_days([MONDAY], "19:00"),
        _off_on_days([TUESDAY], "19:15"),
    )

    assert message == (
        f"{U15_MISSING_FACT} {_correction('dès 19:00', '18:00-19:30', '19:30')} "
        f"{_correction('dès 19:15', '18:00-19:30', '19:30')}"
    )


def test_each_coach_who_leaves_has_his_own_sentence() -> None:
    """Deux coachs coupés sur la même case : la fermeture a deux causes de coach, chacune reste une phrase. Le filtre
    des créneaux que l'équipe pouvait prendre n'écarte que les fermetures d'une autre famille."""
    message = _u15_missing_message(
        [make_venue("V", [(MONDAY, "18:00")])],
        _off_on_days([MONDAY], "19:00"),
        _off_on_days([MONDAY], "19:00", coach_id="ben"),
        coaches=("anna", "ben"),
    )

    assert _correction("dès 19:00", "18:00-19:30", "19:30") in message
    assert _correction("dès 19:00", "18:00-19:30", "19:30", coach="Ben Durand") in message


def test_a_slot_of_another_venue_than_the_forced_one_is_never_cited() -> None:
    """Gymnase imposé W : le créneau coupé de V, plus tôt, ne pouvait pas servir à l'équipe. Seul celui de W est cité."""
    message = _u15_missing_message(
        [make_venue("V", [(MONDAY, "18:00")]), make_venue("W", [(MONDAY, "18:30")])],
        team_constraint(
            constraint_id="forced",
            team_id="U15",
            family="FACILITY",
            rule_type="HARD",
            config={"forcedVenueId": "W"},
        ),
        _off_on_days([MONDAY], "19:00"),
    )

    assert message == f"{U15_MISSING_FACT} {_correction('dès 19:00', '18:30-20:00', '20:00')}"


def test_a_slot_on_a_day_the_team_refuses_is_never_cited() -> None:
    """Même règle pour une autre famille : l'équipe ne vient jamais le lundi, corriger la plage ne lui rendrait rien."""
    message = _u15_missing_message(
        [make_venue("V", [(MONDAY, "18:00")])],
        team_constraint(
            constraint_id="no-monday",
            team_id="U15",
            family="DAY",
            rule_type="HARD",
            config={"forbiddenDays": [MONDAY]},
        ),
        _off_on_days([MONDAY], "19:00"),
    )

    assert message == U15_MISSING_FACT


def test_a_range_covering_the_start_of_the_slot_gets_no_sentence() -> None:
    """Une plage 17:00-18:30 ferme déjà la case à son début : « si elle commence à 19:30 » n'aurait aucun sens."""
    message = _u15_missing_message(
        [make_venue("V", [(MONDAY, "18:00")])],
        _anna_off(from_time="17:00", until_time="18:30"),
    )

    assert message == U15_MISSING_FACT
