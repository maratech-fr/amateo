"""P2-53 RMM-8 PR-2 — un trajet DÉCLARÉ est HONORÉ par le solveur (pipeline réel).

Axe §7.1 « constraint semantics » : la règle SAISIE (matrice de trajet + cran) doit changer le
PLANNING, pas seulement rendre COMPLETED. On prouve les deux termes (arbitrages fondateur) :

  * BATTEMENT — ``MANDATORY`` INTERDIT DUR un enchaînement au battement trop court (le coach n'a
    pas le temps du trajet) ; ``PREFERRED`` le TOLÈRE (soft) ; la règle ABSENTE (matrice pourtant
    présente) est INERTE — l'opt-in au premier geste ;
  * DÉPARTAGE — « moindre trajet » : à placement ÉGAL, le solveur préfère le gymnase suivant le
    plus proche (l'exemple fondateur U13M3 : De Barros plutôt que Camus). MAIS il ne renverse
    JAMAIS un choix qui coûte sur une famille majeure (un gymnase PRÉFÉRÉ l'emporte sur le trajet).

Aucune violation dure : sous MANDATORY, aucun enchaînement cross-gymnase de séances LIBRES ne
reste plus serré que le barème (invariant).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach, team_constraint

# toleranceMinutes: 0 — la plupart de ces cas prouvent le barème STRICT (le battement toléré, défaut
# 20, décision fondateur 2026-09-30, est prouvé à part par les cas « tolérance » plus bas). Sans ce
# pin, tolérance 20 masquerait un battement de 10 min et rendrait les cas MANDATORY inertes.
MATRIX_RULE = {"travelTime": {"intensity": "PREFERRED", "toleranceMinutes": 0}}
MANDATORY_RULE = {"travelTime": {"intensity": "MANDATORY", "toleranceMinutes": 0}}


def _coach(coach_id: str, *, vehicled: bool = False) -> dict[str, Any]:
    return {"id": coach_id, "firstName": "C", "lastName": coach_id, "isVehicled": vehicled}


def _row(a: str, b: str, driving: int | None, walking: int | None) -> dict[str, Any]:
    return {"venueAId": a, "venueBId": b, "drivingMinutes": driving, "walkingMinutes": walking}


def _forced_venue(cid: str, team: str, venue: str) -> dict[str, Any]:
    return team_constraint(
        constraint_id=cid, team_id=team, family="FACILITY", rule_type="HARD", config={"forcedVenueId": venue}
    )


def _hard_lock(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _placed_count(result: dict[str, Any], *team_ids: str) -> int:
    return sum(1 for s in result["slots"] if str(s["teamId"]) in team_ids and s.get("lockLevel") != "HARD")


def _venue_of(result: dict[str, Any], team_id: str) -> str | None:
    for s in result["slots"]:
        if str(s["teamId"]) == team_id and s.get("lockLevel") != "HARD":
            return str(s["venueId"])
    return None


# --- BATTEMENT : MANDATORY interdit, PREFERRED tolère, absent = inerte --------------------------


def _tight_pair_payload(rule: dict[str, Any] | None) -> dict[str, Any]:
    """Deux équipes d'un même coach, forcées à deux gymnases distincts dont le seul créneau
    possible laisse un battement de 10 min pour un barème à pied de 30."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("V1", [(1, "18:20")]), make_venue("V2", [(1, "20:00")])],
        coaches=[_coach("c1")],
        constraints=[
            team_coach("tc1", "t1", "c1"),
            team_coach("tc2", "t2", "c1"),
            _forced_venue("f1", "t1", "V1"),
            _forced_venue("f2", "t2", "V2"),
        ],
        implicit_rules=rule,
    )
    payload["venueTravelTimes"] = [_row("V1", "V2", 5, 30)]
    return payload


def test_mandatory_forbids_a_tight_enchainement() -> None:
    result = solve_payload(_tight_pair_payload(MANDATORY_RULE))
    # Le coach ne peut pas faire V1(fin 19:50) → V2(début 20:00) en 10 min : une seule des deux
    # séances est plaçable.
    assert _placed_count(result, "t1", "t2") == 1


def test_preferred_tolerates_a_tight_enchainement() -> None:
    result = solve_payload(_tight_pair_payload(MATRIX_RULE))
    # PREFERRED : le battement serré est CONCÉDÉ (malus soft), jamais une séance supprimée.
    assert _placed_count(result, "t1", "t2") == 2


def test_rule_absent_is_inert_even_with_a_matrix() -> None:
    # Matrice présente mais AUCUNE règle `travelTime` : opt-in non déclenché ⇒ les deux séances
    # sont placées comme avant la fonctionnalité (jamais un changement silencieux).
    result = solve_payload(_tight_pair_payload(None))
    assert _placed_count(result, "t1", "t2") == 2


# --- BATTEMENT TOLÉRÉ (décision fondateur 2026-09-30) : écart exigé = max(0, barème − tolérance) ---


def _colle_pair_payload(*, walking: int, intensity: str, tolerance: int) -> dict[str, Any]:
    """Enchaînement COLLÉ (écart 0) : le coach NON VÉHICULÉ termine V1 pile à l'heure où V2 commence
    (le cas Mara du BCCL : SM2 jusqu'à 20:00 puis Debarros à 20:00). ``walking`` = barème à vélo
    (colonne non véhiculée), ``tolerance`` = battement toléré retranché du barème."""
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        # V1 18:30 + 90 min = fin 20:00 ; V2 démarre 20:00 → écart 0 (collé).
        venues=[make_venue("V1", [(1, "18:30")]), make_venue("V2", [(1, "20:00")])],
        coaches=[_coach("c1")],  # non véhiculé → barème à vélo (colonne walkingMinutes)
        constraints=[
            team_coach("tc1", "t1", "c1"),
            team_coach("tc2", "t2", "c1"),
            _forced_venue("f1", "t1", "V1"),
            _forced_venue("f2", "t2", "V2"),
        ],
        implicit_rules={"travelTime": {"intensity": intensity, "toleranceMinutes": tolerance}},
    )
    payload["venueTravelTimes"] = [_row("V1", "V2", 5, walking)]
    return payload


def test_zero_gap_accepted_with_tolerance_20_bike() -> None:
    # Vélo 12 min, tolérance 20 : écart exigé max(0, 12−20) = 0 ⇒ l'enchaînement collé PASSE en
    # MANDATORY (le club absorbe le trajet — cas Mara BCCL). Les deux séances tiennent.
    result = solve_payload(_colle_pair_payload(walking=12, intensity="MANDATORY", tolerance=20))
    assert _placed_count(result, "t1", "t2") == 2


def test_zero_gap_refused_without_tolerance_bike() -> None:
    # FALSIFICATION du cas ci-dessus : même enchaînement collé, tolérance 0 ⇒ écart exigé 12 > 0 ⇒
    # MANDATORY en interdit une. Si la tolérance était ignorée (écart exigé = barème toujours), le
    # test précédent tomberait ici (une seule séance) — d'où la preuve que la tolérance est LUE.
    result = solve_payload(_colle_pair_payload(walking=12, intensity="MANDATORY", tolerance=0))
    assert _placed_count(result, "t1", "t2") == 1


def test_partial_tolerance_still_requires_a_gap() -> None:
    # Barème 27 min, tolérance 20 ⇒ écart exigé 27 − 20 = 7 > 0 ; l'enchaînement collé (écart 0) est
    # REFUSÉ. Prouve que la tolérance est RETRANCHÉE du barème, pas un interrupteur tout-ou-rien.
    result = solve_payload(_colle_pair_payload(walking=27, intensity="MANDATORY", tolerance=20))
    assert _placed_count(result, "t1", "t2") == 1


# --- DÉPARTAGE : le gymnase suivant le plus proche, sans jamais dominer --------------------------


def _u13m3_payload(*, prefer_camus: bool) -> dict[str, Any]:
    """L'exemple fondateur en miniature : U13M3 (t1) peut aller à De Barros ou Camus (même
    créneau, mêmes autres critères) ; le coach enchaîne ENSUITE une séance VERROUILLÉE à Matéo.
    De Barros–Matéo est plus court que Camus–Matéo. ``prefer_camus`` ajoute un gymnase PRÉFÉRÉ
    sur Camus (le PLUS LOIN) pour la falsification de non-domination."""
    constraints: list[dict[str, Any]] = [team_coach("tc1", "t1", "c1"), team_coach("tcL", "tLock", "c1")]
    if prefer_camus:
        constraints.append(
            team_constraint(
                constraint_id="pref",
                team_id="t1",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "Camus"},
            )
        )
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("tLock", sessions_per_week=1)],
        venues=[
            make_venue("DeBarros", [(1, "18:00")]),
            make_venue("Camus", [(1, "18:00")]),
            make_venue("Mateo", []),
        ],
        coaches=[_coach("c1")],
        constraints=constraints,
        slot_templates=[_hard_lock("tLock", "Mateo", 1, "20:00")],
        implicit_rules=MATRIX_RULE,
    )
    # Les deux barèmes laissent un battement CONFORTABLE (écart 30 ≥ 5 et ≥ 25) : seul le
    # DÉPARTAGE distingue les deux gymnases, jamais le battement.
    payload["venueTravelTimes"] = [_row("DeBarros", "Mateo", 5, 5), _row("Camus", "Mateo", 25, 25)]
    return payload


def test_departage_picks_the_closer_next_venue() -> None:
    result = solve_payload(_u13m3_payload(prefer_camus=False))
    # À placement ÉGAL, le coach préfère De Barros (trajet plus court vers Matéo) : c'est le PLUS.
    assert _venue_of(result, "t1") == "DeBarros"


def test_departage_never_overrides_a_preferred_venue() -> None:
    result = solve_payload(_u13m3_payload(prefer_camus=True))
    # Camus est PRÉFÉRÉ (famille majeure) bien que plus LOIN : le départage ne le renverse pas.
    assert _venue_of(result, "t1") == "Camus"


# --- Invariant : sous MANDATORY, aucun battement dur violé entre séances LIBRES -----------------


def test_mandatory_solve_leaves_no_tight_free_enchainement() -> None:
    # Un créneau tôt (17:00 → fin 18:30, écart 90) OU tard (18:20 → fin 19:50, écart 10) à V1 ;
    # V2 à 20:00. Sous MANDATORY, le coach DOIT prendre le créneau tôt pour tenir le trajet.
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("V1", [(1, "17:00"), (1, "18:20")]), make_venue("V2", [(1, "20:00")])],
        coaches=[_coach("c1")],
        constraints=[
            team_coach("tc1", "t1", "c1"),
            team_coach("tc2", "t2", "c1"),
            _forced_venue("f1", "t1", "V1"),
            _forced_venue("f2", "t2", "V2"),
        ],
        implicit_rules=MANDATORY_RULE,
    )
    payload["venueTravelTimes"] = [_row("V1", "V2", 30, 30)]
    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert _placed_count(result, "t1", "t2") == 2  # les deux tiennent, en écartant le battement
    # Le coach franchit V1 → V2 avec au moins 30 min : la séance de t1 démarre à 17:00, pas 18:20.
    t1_slot = next(s for s in result["slots"] if str(s["teamId"]) == "t1")
    assert str(t1_slot["startTime"]).startswith("17:00")


def test_two_locks_that_contradict_travel_are_announced_not_muted() -> None:
    # Deux séances VERROUILLÉES du même coach à des gymnases différents, battement 10 < 30 sous
    # MANDATORY : le solveur ne peut rien y faire (deux actes du gestionnaire), mais il l'ANNONCE
    # (jamais un INFEASIBLE muet — CLAUDE.md §6).
    payload = make_payload(
        teams=[make_team("t1", sessions_per_week=1), make_team("t2", sessions_per_week=1)],
        venues=[make_venue("V1", []), make_venue("V2", [])],
        coaches=[_coach("c1")],
        constraints=[team_coach("tc1", "t1", "c1"), team_coach("tc2", "t2", "c1")],
        slot_templates=[_hard_lock("t1", "V1", 1, "18:20"), _hard_lock("t2", "V2", 1, "20:00")],
        implicit_rules=MANDATORY_RULE,
    )
    payload["venueTravelTimes"] = [_row("V1", "V2", 30, 30)]
    result = solve_payload(payload)

    assert result["status"] == "completed"
    assert any(d["type"] == "travel_time_infeasible" for d in result["diagnostics"])


# --- P4-361 : le relais de l'adjoint (chantier H) EXEMPTE le trajet MANDATORY d'une paire tolérée --
#
# Promesse (axe « constraint semantics ») : quand la séance la plus TARDIVE d'un enchaînement
# cross-gymnase est VERROUILLÉE avec une tolérance d'arrivée > 0 ET qu'un adjoint DISPONIBLE peut
# l'ouvrir, le coach PRINCIPAL est autorisé à la rejoindre en retard — le battement trop court n'est
# plus interdit DUR, TANT QUE le retard (barème BRUT − battement, borné à 0) ne dépasse pas la
# tolérance. Miroir de `CoachDoubleBookingDetector::lateArrivalTolerated` côté backend ; la tolérance
# de RÈGLE (`toleranceMinutes`) ne se cumule pas (ici fixée à 0 pour isoler le relais).
# Second angle du NR BLOQUANT `backend/tests/CrossStack/CoachLateToleranceSemanticsGateTest` (point
# sensible « solveur » — doublon VOULU) : ici on éprouve l'EXEMPTION DE TRAJET (enchaînement NON
# chevauchant), là-bas l'occupation verrouillée décalée (chevauchement).


def _relay_pair_payload(*, tolerance: int, with_adjoint: bool, walking: int) -> dict[str, Any]:
    """U21 (LIBRE) finit 20:00 au gymnase A ; SENIORS est VERROUILLÉE 20:05 au gymnase B avec une
    tolérance d'arrivée ``tolerance``. Coach c1 (non véhiculé) tient les deux, barème A↔B à vélo =
    ``walking`` ; un adjoint ASSISTANT ouvre SENIORS si ``with_adjoint``. MANDATORY, battement 5 min
    (20:05 − 20:00) : plus court que tout barème ≥ 6 → interdit DUR hors exemption."""
    constraints: list[dict[str, Any]] = [
        team_coach("tc-u21", "u21", "c1"),
        team_coach("tc-sen", "sen", "c1"),
        _forced_venue("fv-u21", "u21", "A"),
    ]
    coaches = [_coach("c1")]
    if with_adjoint:
        constraints.append(team_coach("tc-adj", "sen", "adj1", role="ASSISTANT"))
        coaches.append(_coach("adj1"))
    sen_lock = _hard_lock("sen", "B", 1, "20:05")
    sen_lock["coachLateToleranceMinutes"] = tolerance
    payload = make_payload(
        teams=[make_team("u21", sessions_per_week=1), make_team("sen", sessions_per_week=1)],
        venues=[make_venue("A", [(1, "18:30")]), make_venue("B", [])],
        coaches=coaches,
        constraints=constraints,
        slot_templates=[sen_lock],
        implicit_rules=MANDATORY_RULE,
    )
    payload["venueTravelTimes"] = [_row("A", "B", 5, walking)]
    return payload


def test_relay_tolerated_travel_pair_lets_the_free_session_be_placed() -> None:
    # NR — barème 15, retard = max(0, 15 − 5) = 10 ≤ tolérance 30, adjoint disponible : le relais
    # EXEMPTE le trajet MANDATORY ⇒ U21 EST placée malgré le battement de 5 min.
    result = solve_payload(_relay_pair_payload(tolerance=30, with_adjoint=True, walking=15))
    assert result["status"] == "completed"
    assert _placed_count(result, "u21") == 1


def test_relay_without_tolerance_still_forbids_the_tight_pair() -> None:
    # TÉMOIN (falsification) — MÊME décor, tolérance 0 sur la séance verrouillée : aucun relais, le
    # battement 5 < 15 reste INTERDIT DUR ⇒ U21 n'est PAS placée. Prouve que c'est bien la tolérance
    # LUE qui exempte le cas ci-dessus, pas un décor trop permissif.
    result = solve_payload(_relay_pair_payload(tolerance=0, with_adjoint=True, walking=15))
    assert _placed_count(result, "u21") == 0


def test_relay_without_an_available_adjoint_still_forbids_the_tight_pair() -> None:
    # TÉMOIN — tolérance 30 mais AUCUN adjoint pour ouvrir SENIORS : le relais ne s'ouvre pas, le
    # trajet reste dur ⇒ U21 non placée. La tolérance seule ne suffit jamais (parité chantier H).
    result = solve_payload(_relay_pair_payload(tolerance=30, with_adjoint=False, walking=15))
    assert _placed_count(result, "u21") == 0


def test_relay_does_not_exempt_a_travel_longer_than_the_tolerance() -> None:
    # TÉMOIN — barème 50, retard = max(0, 50 − 5) = 45 > tolérance 30 : le retard DÉPASSE la
    # tolérance, l'exemption ne s'applique pas ⇒ U21 non placée. L'exemption est BORNÉE par t.
    result = solve_payload(_relay_pair_payload(tolerance=30, with_adjoint=True, walking=50))
    assert _placed_count(result, "u21") == 0


def _two_lock_relay_payload(*, tolerance: int, with_adjoint: bool, walking: int) -> dict[str, Any]:
    """Deux séances VERROUILLÉES du même coach : U21 18:30 gym A (fin 20:00), SENIORS 20:05 gym B
    (tolérance ``tolerance``). Enchaînement cross-gymnase au battement 5 min — le seul cas qui
    SURVIT au HARD (deux verrous), régi par le diagnostic post-solve."""
    constraints: list[dict[str, Any]] = [team_coach("tc-u21", "u21", "c1"), team_coach("tc-sen", "sen", "c1")]
    coaches = [_coach("c1")]
    if with_adjoint:
        constraints.append(team_coach("tc-adj", "sen", "adj1", role="ASSISTANT"))
        coaches.append(_coach("adj1"))
    sen_lock = _hard_lock("sen", "B", 1, "20:05")
    sen_lock["coachLateToleranceMinutes"] = tolerance
    payload = make_payload(
        teams=[make_team("u21", sessions_per_week=1), make_team("sen", sessions_per_week=1)],
        venues=[make_venue("A", []), make_venue("B", [])],
        coaches=coaches,
        constraints=constraints,
        slot_templates=[_hard_lock("u21", "A", 1, "18:30"), sen_lock],
        implicit_rules=MANDATORY_RULE,
    )
    payload["venueTravelTimes"] = [_row("A", "B", 5, walking)]
    return payload


def test_two_relay_tolerated_locks_raise_no_travel_diagnostic() -> None:
    # Deux verrous enchaînés trop serré (battement 5 < barème 15) MAIS la plus tardive tolère 30 min
    # avec un adjoint : le relais rend l'enchaînement VIVABLE ⇒ AUCUN `travel_time_infeasible`. Sans
    # l'exemption, ce cas serait faussement annoncé infaisable.
    result = solve_payload(_two_lock_relay_payload(tolerance=30, with_adjoint=True, walking=15))
    assert result["status"] == "completed"
    assert not any(d["type"] == "travel_time_infeasible" for d in result["diagnostics"])


def test_two_locks_beyond_tolerance_still_announce_the_travel_conflict() -> None:
    # TÉMOIN — barème 50 ⇒ retard 45 > 30 : l'exemption ne joue pas, le conflit DUR entre deux
    # verrous est ANNONCÉ (jamais muet). Prouve que l'absence de diagnostic ci-dessus vient de
    # l'exemption, pas d'un diagnostic éteint.
    result = solve_payload(_two_lock_relay_payload(tolerance=30, with_adjoint=True, walking=50))
    assert any(d["type"] == "travel_time_infeasible" for d in result["diagnostics"])
