"""P2-9 — a HARD lock stays sovereign, but no longer annuls constraints in SILENCE.

A HARD lock is pre-placed OUTSIDE the solver: `model.py` never creates the
`x[team, venue, day, start]` variable for it (`model.py`, the `continue` on
`hard_slot_keys`). Every constraint works by forcing that variable to 0, so with
no variable there is nothing to force — the constraint is not *beaten*, it is
unreachable.

Measured before this behaviour existed, on the very payload of `test_control`
below: without the lock SM1 landed on Tuesday (the coach is off on Saturday, and
the constraint was honoured); WITH the lock it landed on Saturday, `diagnostics`
empty, `warnings` None, status `completed`. The product asserted it had honoured
a constraint it had silently dropped.

Founder ruling (2026-07-27): the lock REMAINS sovereign — that is ALIGN-07 and it
is not reopened here. What must change is the silence, so the manager can see
what his pin overrode and decide. Hence INFO warnings, not errors and not a
placement change.

Structuring axis `constraint semantics` (CLAUDE.md §7.1).
"""

from __future__ import annotations

from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload

SATURDAY = 6
TUESDAY = 2


def _coach_link(team_id: str, coach_id: str) -> dict[str, Any]:
    """A MAIN team↔coach link. `teamId` rides at the ROOT — the parser reads it there."""
    return {
        "id": f"tc-{team_id}-{coach_id}",
        "isActive": True,
        "type": "TEAM_COACH",
        "teamId": team_id,
        "config": {"coachId": coach_id},
    }


def _coach_off(coach_id: str, day: int) -> dict[str, Any]:
    return {
        "id": f"off-{coach_id}-{day}",
        "isActive": True,
        "family": "COACH_AVAILABILITY",
        "scopeTargetId": coach_id,
        "config": {"unavailableDays": [day]},
    }


def _hard_lock(team_id: str, venue_id: str, day: int, start: str) -> dict[str, Any]:
    return {
        "id": f"lock-{team_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": 90,
        "lockLevel": "HARD",
    }


def _days_of(result: dict[str, Any], team_id: str) -> list[int]:
    return [s["dayOfWeek"] for s in result["slots"] if s["teamId"] == team_id]


def _violation_messages(result: dict[str, Any]) -> list[str]:
    return [
        str(d.get("message", "")) for d in result.get("diagnostics") or [] if d.get("type") == "constraint_not_honored"
    ]


def _payload(*, locked: bool) -> dict[str, Any]:
    """Two slots, one coach off on Saturday. The ONLY variable is the lock."""
    return make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand"}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")] if locked else [],
    )


def test_control_the_coach_constraint_really_works_without_a_lock() -> None:
    """TÉMOIN — indispensable : sans lui, le test suivant ne prouverait rien.

    Si la contrainte coach ne mordait pas non plus SANS verrou, constater que SM1
    joue le samedi AVEC le verrou n'accuserait pas le verrou. Ce témoin établit
    que la contrainte est bien active dans ce payload.
    """
    result = solve_payload(_payload(locked=False))

    assert result["status"] == "completed"
    assert _days_of(result, "SM1") == [TUESDAY], "sans verrou, la contrainte coach doit écarter le samedi"
    assert _violation_messages(result) == [], "rien n'est violé, donc rien à signaler"


def test_a_lock_on_the_coach_day_off_is_kept_but_announced() -> None:
    """LE CAS — le verrou gagne (souveraineté), et le solveur le DIT."""
    result = solve_payload(_payload(locked=True))

    # 1. Souveraineté : le placement du gestionnaire est respecté.
    assert _days_of(result, "SM1") == [SATURDAY], "le verrou reste souverain — ALIGN-07"

    # 2. Fin du silence : c'est CE point qui échouait avant P2-9.
    messages = _violation_messages(result)
    assert len(messages) == 1, f"une violation attendue, obtenu : {messages}"

    # Le message doit permettre d'AGIR : quelle équipe, quel coach, où et quand.
    # Assertions séparées — un seul `in` global serait satisfait par n'importe
    # quelle partie du texte, y compris le libellé de contrainte ajouté en
    # suffixe (ce qui a laissé passer une mutation lors de la mise au point).
    phrase = messages[0].split("(contrainte")[0]
    for expected, why in (
        ("SM1", "l'équipe"),
        ("Maxime", "le coach"),
        ("gym", "le gymnase"),
        ("18:00", "l'heure"),
    ):
        assert expected in phrase, f"{why} doit être nommé dans la phrase : {phrase!r}"

    # 3. Un avertissement, jamais une erreur : le gestionnaire tranche.
    severities = {d.get("severity") for d in result["diagnostics"] if d.get("type") == "constraint_not_honored"}
    assert severities == {"INFO"}


def test_a_lock_that_violates_nothing_stays_quiet() -> None:
    """L'autre bord : ne pas crier au loup.

    Un avertissement permanent devient du papier peint et cesse d'être lu — donc
    un verrou posé un jour où le coach est disponible ne doit RIEN produire.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand"}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[_hard_lock("SM1", "gym", TUESDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert _days_of(result, "SM1") == [TUESDAY]
    assert _violation_messages(result) == []


def _time_window(team_id: str, *, rule_type: str, config: dict[str, Any]) -> dict[str, Any]:
    return {
        "id": f"win-{team_id}-{rule_type}",
        "isActive": True,
        "family": "TIME",
        "ruleType": rule_type,
        "scopeTargetId": team_id,
        "config": config,
    }


def test_a_soft_window_is_never_reported_and_never_parsed() -> None:
    """Revue #317 — la porte `ruleType` doit précéder tout parsing d'horaire.

    `add_time_window_constraints` saute les fenêtres PREFERRED AVANT d'appeler
    `_time_to_minutes`. Une détection sans ce filtre faisait deux dégâts : elle
    accusait le verrou d'avoir écrasé une règle que le solveur n'applique jamais
    (le diagnostic mentait), et elle parsait un horaire malformé que personne ne
    lit — `_time_to_minutes` lève sur une valeur sans « : », donc une génération
    qui passait renvoyait un 500.

    L'horaire volontairement invalide ci-dessous est le cœur du test : il ne doit
    JAMAIS être parsé.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[_time_window("SM1", rule_type="PREFERRED", config={"minStartTime": "18h"})],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert result["status"] == "completed", "une règle souple malformée ne doit pas faire échouer la génération"
    assert _violation_messages(result) == [], (
        "une contrainte PREFERRED n'est pas appliquée — l'accuser serait un mensonge"
    )


def test_a_lock_overflowing_the_end_window_is_reported() -> None:
    """Revue #317 — `maxEndTime` porte sur la FIN de séance, et était ignoré.

    La vraie contrainte force la variable à 0 quand `début + durée > maxEndTime`.
    Ne tester que min/maxStartTime laissait ce cas dans le silence exact que
    cette détection existe pour fermer : ici le verrou démarre DANS la fenêtre
    (18:00 <= 20:00) mais la séance de 90 min finit à 19:30, après la limite.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[_time_window("SM1", rule_type="HARD", config={"maxEndTime": "19:00"})],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 1, f"le dépassement de fin de fenêtre doit être signalé, obtenu : {messages}"
    assert "fenêtre horaire" in messages[0]


def test_two_distinct_locks_violating_the_same_rule_are_both_reported() -> None:
    """Revue #317 — la déduplication fusionnait des violations réellement distinctes.

    La clé était `(contrainte, équipe)`, justifiée par « un verrou couvre
    plusieurs départs de 30 min ». C'était faux : `locked_slots` porte UNE entrée
    par verrou. Deux verrous violant la même règle sont deux corrections à faire ;
    n'en montrer qu'une laisse croire le planning réparé après un seul geste.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=2)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (SATURDAY, "20:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand"}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[
            _hard_lock("SM1", "gym", SATURDAY, "18:00"),
            _hard_lock("SM1", "gym", SATURDAY, "20:00"),
        ],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 2, f"les DEUX verrous doivent être signalés, obtenu : {messages}"
    assert any("18:00" in m for m in messages) and any("20:00" in m for m in messages)


def _day_rule(
    team_id: str, cid: str, *, allowed: list[int] | None = None, forbidden: list[int] | None = None
) -> dict[str, Any]:
    config: dict[str, Any] = {}
    if allowed is not None:
        config["allowedDays"] = allowed
    if forbidden is not None:
        config["forbiddenDays"] = forbidden
    return {
        "id": cid,
        "isActive": True,
        "family": "DAY",
        "ruleType": "HARD",
        "scopeTargetId": team_id,
        "config": config,
    }


def test_day_rules_are_evaluated_UNITED_not_one_by_one() -> None:
    """Revue #317 round 2 — deux règles DAY s'unissent, elles ne s'excluent pas.

    `add_time_window_constraints` fusionne les `allowedDays` de toutes les règles
    d'une équipe : `[2]` et `[6]` autorisent mardi ET samedi. Les évaluer isolément
    faisait accuser le verrou d'avoir écrasé une règle qui, unie, permet ce jour —
    le « papier peint » que `stays_quiet` existe pour empêcher.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[_day_rule("SM1", "c-mardi", allowed=[TUESDAY]), _day_rule("SM1", "c-samedi", allowed=[SATURDAY])],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert _violation_messages(result) == [], "samedi est autorisé par l'UNION — l'accuser serait un faux positif"


def test_a_day_truly_excluded_by_the_union_is_reported() -> None:
    """Le bord opposé : l'union ne doit pas non plus tout absoudre."""
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[_day_rule("SM1", "c-mardi", allowed=[TUESDAY])],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 1, f"samedi est hors de la seule règle existante, obtenu : {messages}"
    assert "samedi" in messages[0], "le JOUR doit être nommé — sans lui, deux verrous s'affichent à l'identique"


def test_a_rule_that_is_neither_hard_nor_lock_is_never_examined() -> None:
    """Revue #317 round 2 — la ligne `rule_type not in (HARD, LOCK)` n'était pas exercée.

    Mon premier test de cette porte utilisait PREFERRED + TIME, intercepté par la
    branche ANTÉRIEURE : la condition ajoutée n'était jamais atteinte. Ici la
    famille est DAY et le type SOFT — seule la seconde ligne peut l'écarter.
    """
    rule = _day_rule("SM1", "c-soft", allowed=[TUESDAY])
    rule["ruleType"] = "SOFT"

    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[rule],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert _violation_messages(result) == [], "une règle SOFT n'est pas appliquée — l'accuser serait un mensonge"


def test_two_locks_differing_only_by_duration_are_both_reported() -> None:
    """Revue #317 round 2 — la clé de dédup omettait la durée.

    `_extract_hard_locks` clé les verrous sur (équipe, gymnase, jour, début, DURÉE)
    et son commentaire explique que les confondre laisserait des minutes non
    bloquées. Ma clé les fusionnait à nouveau, faisant disparaître une violation.
    """
    long_lock = _hard_lock("SM1", "gym", SATURDAY, "18:00")
    long_lock["durationMinutes"] = 120
    long_lock["id"] = "lock-long"

    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=2)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        coaches=[{"id": "c1", "firstName": "Maxime", "lastName": "Durand"}],
        constraints=[_coach_link("SM1", "c1"), _coach_off("c1", SATURDAY)],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00"), long_lock],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 2, "deux durées = deux verrous distincts pour model.py, donc deux violations"
    # Revue #317 round 3 : compter ne suffit pas. Deux messages IDENTIQUES ne
    # servent à rien — le gestionnaire ne sait pas lequel il vient de corriger.
    assert messages[0] != messages[1], "les deux avertissements doivent être distinguables"
    assert any("120 min" in m for m in messages) and any("90 min" in m for m in messages)


def test_a_lock_overrunning_the_window_with_ITS_OWN_duration_is_reported() -> None:
    """Revue #317 round 3 — c'est la RÉSERVATION qui déborde, pas le créneau.

    Le round 2 avait basculé la mesure sur la durée du créneau de grille par souci
    de miroir. Sur-correction : un verrou de 120 min posé sur un créneau de 90
    court jusqu'à 20:00 et viole une fenêtre `maxEndTime = 19:30`, mais mesurer 90
    donnait 19:30 pile — pas de dépassement, silence. Faux négatif dans le
    détecteur même que cette PR ajoute.
    """
    long_lock = _hard_lock("SM1", "gym", SATURDAY, "18:00")
    long_lock["durationMinutes"] = 120

    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        # Le créneau de grille fait 90 min : c'est l'écart avec le verrou qui compte.
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")], duration_minutes=90)],
        constraints=[_time_window("SM1", rule_type="HARD", config={"maxEndTime": "19:30"})],
        slot_templates=[long_lock],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 1, f"18:00 + 120 min = 20:00 dépasse 19:30, obtenu : {messages}"
    assert "fenêtre horaire" in messages[0]


def test_a_forced_day_left_unserved_by_a_lock_is_reported() -> None:
    """Revue #317 round 3 — `forcedDays` était absent de l'union.

    Le solveur impose au moins une séance les jours forcés
    (`sum(forced_day_vars) >= 1`). Un verrou posé AILLEURS peut consommer le
    créneau qui l'aurait satisfaite : le gestionnaire recevait un INFEASIBLE que
    rien n'expliquait, alors que nommer le verrou est précisément le rôle de ce
    diagnostic.
    """
    rule = _day_rule("SM1", "c-force", forbidden=None)
    rule["config"] = {"forcedDays": [TUESDAY]}

    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[rule],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 1, f"un jour imposé non servi doit être signalé, obtenu : {messages}"
    assert "mardi" in messages[0], "le jour imposé doit être nommé"


def _forced_venue(team_id: str, venue_id: str, *, rule_type: str = "HARD") -> dict[str, Any]:
    return {
        "id": f"force-{team_id}-{venue_id}",
        "isActive": True,
        "family": "FACILITY",
        "ruleType": rule_type,
        "scope": "TEAM",
        "scopeTargetId": team_id,
        "name": "SM1 doit jouer à GYMA",
        "config": {"forcedVenueId": venue_id},
    }


def test_a_lock_outside_the_forced_venue_is_kept_but_announced() -> None:
    """LE CAS forced_venues — le second angle mort du silence (fix ce lot).

    `add_forced_venue_constraints` force à 0 la variable de tout gymnase ≠ imposé.
    Le créneau verrouillé n'ayant PAS de variable (`model.py`), ce forçage ne peut
    pas l'atteindre : mesuré, l'équipe atterrissait à GYMB alors qu'une règle HARD
    l'impose à GYMA, `completed`, `diagnostics` vide. Le verrou reste souverain
    (ALIGN-07) ; ce qui change, c'est le silence.
    """
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("GYMA", [(TUESDAY, "18:00")]), make_venue("GYMB", [(TUESDAY, "18:00")])],
        constraints=[_forced_venue("SM1", "GYMA")],
        slot_templates=[_hard_lock("SM1", "GYMB", TUESDAY, "18:00")],
    )

    result = solve_payload(payload)

    # 1. Souveraineté : le placement du gestionnaire (GYMB) est respecté.
    assert [s["venueId"] for s in result["slots"] if s["teamId"] == "SM1"] == ["GYMB"], "le verrou reste souverain"

    # 2. Fin du silence : un INFO qui NOMME l'équipe, le gymnase imposé ET le gymnase utilisé.
    messages = _violation_messages(result)
    assert len(messages) == 1, f"une violation attendue, obtenu : {messages}"
    phrase = messages[0].split("(contrainte")[0]
    for expected, why in (
        ("SM1", "l'équipe"),
        ("GYMA", "le gymnase imposé"),
        ("GYMB", "le gymnase réellement utilisé"),
    ):
        assert expected in phrase, f"{why} doit être nommé dans la phrase : {phrase!r}"

    severities = {d.get("severity") for d in result["diagnostics"] if d.get("type") == "constraint_not_honored"}
    assert severities == {"INFO"}, "un avertissement, jamais une erreur : le gestionnaire tranche"


def test_a_lock_inside_the_forced_venue_stays_quiet() -> None:
    """L'autre bord : le verrou pose l'équipe DANS le gymnase imposé — rien à signaler."""
    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("GYMA", [(TUESDAY, "18:00")]), make_venue("GYMB", [(TUESDAY, "18:00")])],
        constraints=[_forced_venue("SM1", "GYMA")],
        slot_templates=[_hard_lock("SM1", "GYMA", TUESDAY, "18:00")],
    )

    result = solve_payload(payload)

    assert [s["venueId"] for s in result["slots"] if s["teamId"] == "SM1"] == ["GYMA"]
    assert _violation_messages(result) == [], "le verrou honore le gymnase imposé — l'accuser serait un faux positif"


def test_the_day_warning_names_the_REAL_constraint_not_a_synthetic_one() -> None:
    """Revue #317 round 3 — l'union avait fait perdre la contrainte fautive.

    Passer à l'union est la bonne sémantique d'ÉVALUATION, mais l'émission
    nommait un `day-rules-<équipe>` synthétique que le gestionnaire ne retrouve
    nulle part dans son écran de contraintes — il savait qu'une règle avait été
    écrasée, pas laquelle corriger.
    """
    rule = _day_rule("SM1", "regle-du-mardi", allowed=[TUESDAY])
    rule["name"] = "SM1 uniquement le mardi"

    payload = make_payload(
        teams=[make_team("SM1", sessions_per_week=1)],
        venues=[make_venue("gym", [(SATURDAY, "18:00"), (TUESDAY, "18:00")])],
        constraints=[rule],
        slot_templates=[_hard_lock("SM1", "gym", SATURDAY, "18:00")],
    )

    result = solve_payload(payload)

    messages = _violation_messages(result)
    assert len(messages) == 1
    assert "SM1 uniquement le mardi" in messages[0], "la VRAIE règle doit être nommée, pas un libellé fabriqué"
