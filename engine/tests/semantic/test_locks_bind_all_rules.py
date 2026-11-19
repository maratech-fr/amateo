"""P4-97 bis — bout en bout : un créneau VERROUILLÉ contraint les placements LIBRES.

Les deux CAS RÉELS mesurés sur le club BCCL (planning ``completed`` sans aucun diagnostic) :

  (a) « Mara » COACHE une équipe LIBRE pendant qu'elle JOUE dans une équipe VERROUILLÉE à la même
      heure dans un AUTRE gymnase → le placement libre doit être interdit.
  (b) une équipe a un jeudi VERROUILLÉ et le solveur lui ajoutait une séance LIBRE ce même jeudi →
      pas de second créneau ce jour-là.

Plus le bord « verrous SEULS en conflit » : la génération sort quand même (``completed``, souveraineté)
mais le conflit est DIAGNOSTIQUÉ, jamais ``failed``.

Structuring axis ``constraint semantics`` (CLAUDE.md §7.1). Harnais = vraie pipeline de prod.
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import (
    coach_availability,
    make_payload,
    make_team,
    make_venue,
    solve_payload,
    team_constraint,
)
from tests.support.pipeline import team_coach as team_coach_link

TUESDAY = 2
WEDNESDAY = 3
THURSDAY = 4
MATEO = "mateo"
JDR = "jdr"


def _hard_lock(team_id: str, venue_id: str, day: int, start: str, *, duration: int = 90) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": duration,
        "lockLevel": "HARD",
    }


def _coach_player(team_id: str, coach_id: str) -> dict[str, Any]:
    """Lie une personne à une équipe comme JOUEUSE (``team_player_map``)."""
    return {
        "id": f"cp-{team_id}-{coach_id}",
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "isActive": True,
        "metadata": {"teamId": team_id, "coachId": coach_id},
    }


def _coaches() -> list[dict[str, Any]]:
    return [{"id": "mara", "firstName": "Mara", "lastName": "B"}]


def _slots_of(result: dict[str, Any], team_id: str) -> list[dict[str, Any]]:
    return [s for s in result["slots"] if s["teamId"] == team_id]


# --- (a) coach-joueur non simultané, deux gymnases ----------------------------------------


def test_control_free_coaching_lands_when_the_person_is_not_locked_elsewhere() -> None:
    """TÉMOIN : sans réservation qui occupe Mara ailleurs, SF2 prend bien son unique créneau jeudi."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=1), make_team("SM2", sessions_per_week=1)],
        venues=[make_venue(MATEO, [(THURSDAY, "19:00")]), make_venue(JDR, [(THURSDAY, "19:00")])],
        coaches=_coaches(),
        constraints=[team_coach_link("tc-sf2", "SF2", "mara")],
        slot_templates=[_hard_lock("SM2", JDR, THURSDAY, "19:00")],  # SM2 verrouillé, mais Mara n'y JOUE pas
    )

    result = solve_payload(payload)

    assert result["status"] == "completed"
    sf2 = _slots_of(result, "SF2")
    assert [(s["dayOfWeek"], str(s["startTime"])[:5]) for s in sf2] == [(THURSDAY, "19:00")], (
        "sans conflit de personne, le créneau jeudi 19:00 est plaçable"
    )


def test_free_coaching_is_refused_while_the_person_plays_a_locked_session_elsewhere() -> None:
    """LE CAS (a) : Mara JOUE dans SM2 (verrouillé, JDR, jeudi 19:00) → elle ne peut pas COACHER
    SF2 au même moment à Matéo. Le seul créneau de SF2 étant celui-là, SF2 reste non placée."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=1), make_team("SM2", sessions_per_week=1)],
        venues=[make_venue(MATEO, [(THURSDAY, "19:00")]), make_venue(JDR, [(THURSDAY, "19:00")])],
        coaches=_coaches(),
        constraints=[
            team_coach_link("tc-sf2", "SF2", "mara"),
            _coach_player("SM2", "mara"),  # Mara JOUE avec SM2
        ],
        slot_templates=[_hard_lock("SM2", JDR, THURSDAY, "19:00")],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed"
    sf2_free = [s for s in _slots_of(result, "SF2") if str(s["lockLevel"]).upper() != "HARD"]
    assert sf2_free == [], "le créneau libre de SF2 chevauchant la séance jouée verrouillée doit être refusé"


# --- (b) une séance par jour et par équipe ------------------------------------------------


def test_a_locked_thursday_forbids_a_second_free_session_that_thursday() -> None:
    """LE CAS (b) : SF2 a un jeudi VERROUILLÉ (20:30) ; sa 2ᵉ séance doit tomber un AUTRE jour,
    jamais un second créneau libre le jeudi."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=2)],
        venues=[make_venue(MATEO, [(THURSDAY, "19:00"), (TUESDAY, "19:00")]), make_venue(JDR, [(THURSDAY, "20:30")])],
        coaches=_coaches(),
        constraints=[team_coach_link("tc-sf2", "SF2", "mara")],
        slot_templates=[_hard_lock("SF2", JDR, THURSDAY, "20:30")],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed"
    sf2 = _slots_of(result, "SF2")
    thursday_slots = [s for s in sf2 if s["dayOfWeek"] == THURSDAY]
    assert len(thursday_slots) == 1, f"une seule séance le jeudi (le verrou), obtenu : {thursday_slots}"
    # Le nombre de séances ne régresse pas : le verrou + un créneau un AUTRE jour.
    assert len(sf2) == 2, f"la 2ᵉ séance est placée ailleurs, pas perdue : {sf2}"
    assert {s["dayOfWeek"] for s in sf2} == {THURSDAY, TUESDAY}


# --- bord « verrous seuls en conflit » → completed + diagnostic ---------------------------


def test_two_locks_same_team_same_day_complete_with_a_diagnostic() -> None:
    """Deux verrous d'une même équipe le même jour : souveraineté (``completed``) + diagnostic
    nommant l'équipe et le jour — jamais ``failed``."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=2)],
        venues=[make_venue(MATEO, [(THURSDAY, "19:00")]), make_venue(JDR, [(THURSDAY, "20:30")])],
        coaches=_coaches(),
        constraints=[team_coach_link("tc-sf2", "SF2", "mara")],
        slot_templates=[
            _hard_lock("SF2", MATEO, THURSDAY, "19:00"),
            _hard_lock("SF2", JDR, THURSDAY, "20:30"),
        ],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed", "un conflit entre verrous ne doit jamais faire échouer la génération"
    conflicts = [d for d in result["diagnostics"] if d.get("type") == "conflict"]
    messages = " || ".join(str(d.get("message", "")) for d in conflicts)
    assert conflicts, f"le conflit entre verrous doit être diagnostiqué, diagnostics : {result['diagnostics']}"
    assert "SF2" in messages and "jeudi" in messages, f"l'équipe et le jour doivent être nommés : {messages}"


def test_person_locked_in_two_venues_at_once_completes_with_a_diagnostic() -> None:
    """Deux verrous coachés par la MÊME personne, même heure, gymnases DIFFÉRENTS : ``completed``
    + diagnostic nommant la personne — jamais ``failed``."""
    payload = make_payload(
        teams=[make_team("A", sessions_per_week=1), make_team("B", sessions_per_week=1)],
        venues=[make_venue(MATEO, [(THURSDAY, "19:00")]), make_venue(JDR, [(THURSDAY, "19:00")])],
        coaches=_coaches(),
        constraints=[team_coach_link("tc-a", "A", "mara"), team_coach_link("tc-b", "B", "mara")],
        slot_templates=[
            _hard_lock("A", MATEO, THURSDAY, "19:00"),
            _hard_lock("B", JDR, THURSDAY, "19:00"),
        ],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed"
    conflicts = [d for d in result["diagnostics"] if d.get("type") == "rule_sacrificed"]
    messages = " || ".join(str(d.get("message", "")) for d in conflicts)
    assert conflicts, f"la personne dans deux gymnases doit être diagnostiquée : {result['diagnostics']}"
    assert "Mara" in messages, f"la personne doit être nommée : {messages}"


# --- ALIGN-16 — un jour imposé + un verrou ce jour-là --------------------------------------


def _forced_day(constraint_id: str, team_id: str, day: int) -> dict[str, Any]:
    return team_constraint(
        constraint_id=constraint_id,
        team_id=team_id,
        family="DAY",
        rule_type="HARD",
        config={"forcedDays": [day]},
    )


def test_forced_day_satisfied_by_a_locked_session_stays_feasible() -> None:
    """ALIGN-16 — l'équipe DOIT s'entraîner le mercredi (jour imposé) ET le gestionnaire a
    VERROUILLÉ sa séance du mercredi. Le verrou n'a pas de variable (``model.py`` le retire),
    donc le SEUL créneau mercredi disparaît de ``x`` : poser « au moins une séance mercredi » sur
    un ensemble VIDE rendait tout le modèle INFEASIBLE (``causes: []``) alors que la réservation
    honore la règle. Le verrou satisfait le jour imposé → la génération sort ``completed``."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=1)],
        venues=[make_venue(MATEO, [(WEDNESDAY, "18:00")])],
        constraints=[_forced_day("force-wed-sf2", "SF2", WEDNESDAY)],
        slot_templates=[_hard_lock("SF2", MATEO, WEDNESDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed", result.get("diagnostics")


def test_forced_day_emptied_by_coach_unavailability_names_the_cause() -> None:
    """ALIGN-16 — jour imposé mercredi, AUCUNE réservation, mais le coach de l'équipe est
    indisponible TOUT le mercredi : toutes les places du mercredi sont fermées. La règle du jour
    imposé reste insatisfiable (décision fondateur : la génération RESTE infaisable) MAIS sa cause
    est désormais NOMMÉE (``day_constraint_conflict``) au lieu d'un INFEASIBLE muet (``causes: []``)."""
    payload = make_payload(
        teams=[make_team("SF2", sessions_per_week=1)],
        venues=[make_venue(MATEO, [(WEDNESDAY, "18:00")])],
        coaches=[{"id": "mara", "firstName": "Mara", "lastName": "B"}],
        constraints=[
            _forced_day("force-wed-sf2", "SF2", WEDNESDAY),
            team_coach_link("tc-sf2", "SF2", "mara"),
            coach_availability("cav-mara", "mara", unavailable_days=[WEDNESDAY]),
        ],
    )

    result = solve_payload(payload)

    assert result["status"] == "failed", result.get("diagnostics")
    named = [d for d in result["diagnostics"] if d["type"] == "day_constraint_conflict" and d.get("teamId") == "SF2"]
    assert named, result["diagnostics"]


# --- PR 1a — diagnostic des verrous à plafond de séances par jour (A16) ----------------------
# À plafond 2, deux verrous le même jour ne sont plus un conflit : seuls le dépassement du plafond
# (T6) et le chevauchement (T6b) sont annoncés. Deux verrous collés : silence (Q2).


def _capped_locks(locks: list[dict[str, Any]], venues: list[dict[str, Any]]) -> dict[str, Any]:
    team = {**make_team("SF2", sessions_per_week=len(locks)), "maxSessionsPerDay": 2}
    result = solve_payload(make_payload(teams=[team], venues=venues, slot_templates=locks))
    assert result["status"] == "completed", result.get("diagnostics")
    return result


def _sf2_messages(result: dict[str, Any]) -> list[str]:
    return [str(d["message"]) for d in result["diagnostics"] if d.get("teamId") == "SF2"]


def test_cap_two_two_disjoint_locks_the_same_day_are_silent() -> None:
    """Plafond 2 : deux verrous disjoints le jeudi ne sont pas un conflit."""
    result = _capped_locks(
        [_hard_lock("SF2", MATEO, THURSDAY, "09:00"), _hard_lock("SF2", MATEO, THURSDAY, "18:00")],
        [make_venue(MATEO, [(THURSDAY, "09:00"), (THURSDAY, "18:00")])],
    )
    assert _sf2_messages(result) == []


def test_cap_two_two_glued_locks_the_same_day_are_silent() -> None:
    """Plafond 2 : deux verrous collés (18:00 puis 19:30) : ni conflit ni annonce d'écart."""
    result = _capped_locks(
        [_hard_lock("SF2", MATEO, THURSDAY, "18:00"), _hard_lock("SF2", MATEO, THURSDAY, "19:30")],
        [make_venue(MATEO, [(THURSDAY, "18:00"), (THURSDAY, "19:30")])],
    )
    assert _sf2_messages(result) == []


def test_cap_two_three_locks_the_same_day_name_the_excess() -> None:
    """Plafond 2 : trois verrous le jeudi → T6, les séances en trop restent à arbitrer."""
    result = _capped_locks(
        [
            _hard_lock("SF2", MATEO, THURSDAY, "09:00"),
            _hard_lock("SF2", MATEO, THURSDAY, "14:00"),
            _hard_lock("SF2", MATEO, THURSDAY, "18:00"),
        ],
        [make_venue(MATEO, [(THURSDAY, "09:00"), (THURSDAY, "14:00"), (THURSDAY, "18:00")])],
    )
    assert (
        "SF2 a 3 séances le même jour (jeudi à 09:00, 14:00, 18:00) alors que 2 par jour sont permises pour "
        "cette équipe : un créneau verrouillé prime, mais les séances en trop restent à arbitrer."
    ) in _sf2_messages(result)


def test_cap_two_two_overlapping_locks_name_the_overlap() -> None:
    """Plafond 2 : deux verrous qui se chevauchent (deux gymnases) → T6b."""
    result = _capped_locks(
        [_hard_lock("SF2", MATEO, THURSDAY, "18:00"), _hard_lock("SF2", JDR, THURSDAY, "18:30")],
        [make_venue(MATEO, [(THURSDAY, "18:00")]), make_venue(JDR, [(THURSDAY, "18:30")])],
    )
    assert (
        "SF2 a deux séances verrouillées qui se chevauchent le jeudi (18:00 et 18:30) : un créneau verrouillé "
        "prime, mais elle ne peut pas être aux deux."
    ) in _sf2_messages(result)
