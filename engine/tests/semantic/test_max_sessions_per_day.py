"""NR — plafond de séances par jour et par équipe, non-chevauchement dur, écart de 2 h souple,
exemption des paires de saison (PR 1a, contrat 1.10, barème V15).

Axe §7.1 *constraint semantics* : une équipe autorisée à N séances par jour (``maxSessionsPerDay``,
émis par le backend seulement au-delà de 1) peut en recevoir jusqu'à N le même jour, jamais deux
qui se chevauchent ; le solveur cherche à laisser au moins 2 h entre deux séances du même jour
(−50 par séance trop proche de sa voisine, −10 par séance au-delà de la première du jour) et le
signale quand il ne le fait pas (``implicit_rule_not_honored`` sans ``ruleKey``). Deux créneaux
repris du planning de saison par une équipe qui garde ses créneaux (références ``keep: true``) ne
coûtent rien : ils restent gardés, même collés, et sont annoncés « gardés du planning de saison ».

Tous les cas tournent sur de petits modèles prouvés optimaux (quelques créneaux, une ou deux
équipes) : ils ne disent rien d'un solve à budget épuisé. Les coûts se lisent sur le ``score``
rapporté (placement aux poids d'origine) entre deux payloads qui ne diffèrent que par l'écart :
aucune équipe n'y a de coach, donc aucun chaînage ne s'ajoute au score.

Jumeaux voulus (moteur = sensible) : le seuil du Récap côté backend
(``backend/tests/Integration/Api/RecapRefusesASecondSessionSameDayTest.php``), le verdict d'un geste
(``tests/semantic/test_validate_assignments_semantics.py``), l'annonce lue directement
(``tests/test_result_builder.py::SameDayGapDiagnosticTest``), le scénario Behat
``backend/features/une-equipe-s-entraine-deux-fois-le-meme-jour.feature``.
"""

from __future__ import annotations

from collections import Counter
from typing import Any

from tests.support.pipeline import make_payload, make_team, make_venue, solve_payload, team_coach, team_constraint

MONDAY = 1
TUESDAY = 2
WEDNESDAY = 3
THURSDAY = 4
FRIDAY = 5

TIER_S = 1
TIER_B = 3
TIER_C = 4
TIER_D = 5

GAP_COST = 50
SAME_DAY_COST = 10
SEASON_SUFFIX = ", gardées du planning de saison."


# --- Fabriques ---------------------------------------------------------------------------


def _team(
    team_id: str,
    *,
    spw: int,
    cap: int | None,
    tier: int = TIER_B,
    age_min: int | None = None,
) -> dict[str, Any]:
    """Une équipe ; ``cap`` None = champ absent (forme d'un club resté à 1, contrat 1.10)."""
    team = make_team(team_id, sessions_per_week=spw, priority_tier_id=tier)
    if cap is not None:
        team["maxSessionsPerDay"] = cap
    if age_min is not None:
        team["ageMin"] = age_min
    return team


def _venue(venue_id: str, slots: list[tuple[int, str, int]]) -> dict[str, Any]:
    """Un gymnase aux créneaux de durées propres : ``(jour, "HH:MM", minutes)``."""
    return {
        "id": venue_id,
        "name": venue_id,
        "trainingSlots": [
            {"dayOfWeek": day, "startTime": start, "durationMinutes": minutes, "capacity": 1}
            for day, start, minutes in slots
        ],
    }


def _lock(team_id: str, venue_id: str, day: int, start: str, *, minutes: int = 90) -> dict[str, Any]:
    """Un verrou HARD (réservation, épingle ou « Charger » : le moteur n'en connaît qu'un état)."""
    return {
        "id": f"lock-{team_id}-{venue_id}-{day}-{start}",
        "teamId": team_id,
        "venueId": venue_id,
        "dayOfWeek": day,
        "startTime": start,
        "durationMinutes": minutes,
        "lockLevel": "HARD",
    }


def _keep(team_id: str, day: int, start: str, venue_id: str) -> dict[str, Any]:
    """Référence « garder ses créneaux de saison », heure au format du backend (« 17:00:00 »)."""
    return {"teamId": team_id, "dayOfWeek": day, "startTime": f"{start}:00", "venueId": venue_id, "keep": True}


def _fill_ref(team_id: str, day: int, start: str) -> dict[str, Any]:
    """Référence de COMBLEMENT : sans ``keep``, sans gymnase (heure au format du backend)."""
    return {"teamId": team_id, "dayOfWeek": day, "startTime": f"{start}:00"}


def _comfort_on(team_id: str, venue_id: str, day: int, min_start: str) -> list[dict[str, Any]]:
    """Confort maximal (gymnase 10 + jour 5 + heure 5 = 20) sur UN créneau désigné."""
    return [
        team_constraint(
            constraint_id=f"pv-{team_id}",
            team_id=team_id,
            family="FACILITY",
            rule_type="PREFERRED",
            config={"preferredVenueId": venue_id},
        ),
        team_constraint(
            constraint_id=f"pd-{team_id}",
            team_id=team_id,
            family="DAY",
            rule_type="PREFERRED",
            config={"preferredDays": [day]},
        ),
        team_constraint(
            constraint_id=f"pt-{team_id}",
            team_id=team_id,
            family="TIME",
            rule_type="PREFERRED",
            config={"minStartTime": min_start},
        ),
    ]


def _placed(result: dict[str, Any], team_id: str) -> list[tuple[int, str, str]]:
    """(jour, « HH:MM », gymnase) des séances de l'équipe, verrous compris, triées."""
    return sorted(
        (int(s["dayOfWeek"]), str(s["startTime"])[:5], str(s["venueId"]))
        for s in result["slots"]
        if s["teamId"] == team_id
    )


def _starts_on(result: dict[str, Any], team_id: str, day: int) -> list[str]:
    return [start for d, start, _v in _placed(result, team_id) if d == day]


def _gap_diags(result: dict[str, Any], team_id: str) -> list[dict[str, Any]]:
    return [
        d
        for d in result["diagnostics"]
        if d["type"] == "implicit_rule_not_honored"
        and d.get("teamId") == team_id
        and "à moins de 2 h d'écart" in str(d.get("message"))
    ]


def _ranges(result: dict[str, Any], team_id: str, day: int) -> list[str]:
    """« HH:MM-HH:MM » des séances de l'équipe ce jour-là, triées."""
    out = []
    for s in result["slots"]:
        if s["teamId"] != team_id or int(s["dayOfWeek"]) != day:
            continue
        start = str(s["startTime"])[:5]
        hours, minutes = (int(p) for p in start.split(":"))
        end = hours * 60 + minutes + int(s["durationMinutes"])
        out.append(f"{start}-{end // 60:02d}:{end % 60:02d}")
    return sorted(out)


def _t_ecart(team: str, ranges: list[str], *, season: bool = False, day_label: str = "lundi") -> str:
    """Texte T-écart / T-écart-saison mot pour mot (plan 1a.7)."""
    if len(ranges) == 2:
        body = f"{team} : deux séances le {day_label} à moins de 2 h d'écart ({ranges[0]} et {ranges[1]})"
    else:
        body = f"{team} : des séances du {day_label} à moins de 2 h d'écart ({', '.join(ranges[:-1])} et {ranges[-1]})"
    return body + (SEASON_SUFFIX if season else ".")


def _solve(**kwargs: Any) -> dict[str, Any]:
    extra = {k: kwargs.pop(k) for k in ("socleReferenceAssignments", "previousAssignments") if k in kwargs}
    payload = make_payload(timeout=10, **kwargs)
    payload.update(extra)
    result = solve_payload(payload)
    assert result["status"] == "completed", result.get("diagnostics")
    return result


# --- A7 — le plafond lui-même -------------------------------------------------------------


def test_cap_one_written_or_absent_builds_the_same_model() -> None:
    """Un plafond de 1, écrit ou absent, donne le même modèle et le même planning qu'aujourd'hui."""
    venues = [make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00"), (WEDNESDAY, "18:00")])]
    absent = _solve(teams=[_team("T", spw=2, cap=None)], venues=venues)
    written = _solve(teams=[_team("T", spw=2, cap=1)], venues=venues)

    assert _placed(written, "T") == _placed(absent, "T")
    assert written["score"] == absent["score"]
    assert written["metrics"]["nb_variables"] == absent["metrics"]["nb_variables"]
    assert written["metrics"]["nb_constraints"] == absent["metrics"]["nb_constraints"]


def test_cap_two_places_two_sessions_on_the_only_open_day() -> None:
    """Une équipe à 2 séances par jour reçoit ses deux séances le seul jour ouvert."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00")])],
    )
    assert _starts_on(result, "T", MONDAY) == ["09:00", "18:00"]


def test_cap_two_adds_a_free_session_beside_a_lock_the_same_day() -> None:
    """À plafond 2, un verrou du lundi laisse place à une séance libre ce même lundi."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00")])],
        slot_templates=[_lock("T", "A", MONDAY, "09:00")],
    )
    assert _starts_on(result, "T", MONDAY) == ["09:00", "18:00"]


def test_cap_two_never_places_a_third_session_that_day() -> None:
    """À plafond 2, jamais trois séances le même jour, même si la demande en réclame trois."""
    result = _solve(
        teams=[_team("T", spw=3, cap=2)],
        venues=[make_venue("A", [(MONDAY, "08:00"), (MONDAY, "12:00"), (MONDAY, "16:00")])],
    )
    assert len(_starts_on(result, "T", MONDAY)) == 2


def test_cap_three_places_three_spaced_sessions_on_one_day() -> None:
    """À plafond 3, trois créneaux espacés d'un seul jour reçoivent les trois séances."""
    result = _solve(
        teams=[_team("T", spw=3, cap=3)],
        venues=[make_venue("A", [(MONDAY, "08:00"), (MONDAY, "12:00"), (MONDAY, "16:00")])],
    )
    assert _starts_on(result, "T", MONDAY) == ["08:00", "12:00", "16:00"]
    assert _gap_diags(result, "T") == []


# --- A7bis — soirs seulement --------------------------------------------------------------


def _evenings(days: list[int], starts: tuple[str, ...] = ("17:00", "18:30", "20:00")) -> list[dict[str, Any]]:
    return [make_venue("A", [(day, start) for day in days for start in starts])]


def test_evenings_only_one_day_places_both_sessions_and_announces_the_gap() -> None:
    """Soirs seulement, un jour : les 2 séances sont placées l'une près de l'autre et T-écart le dit."""
    result = _solve(teams=[_team("U15", spw=2, cap=2)], venues=_evenings([MONDAY]))

    assert len(_starts_on(result, "U15", MONDAY)) == 2
    assert not [d for d in result["diagnostics"] if d["type"] == "session_below_effective_min"]
    diags = _gap_diags(result, "U15")
    assert len(diags) == 1, result["diagnostics"]
    assert diags[0]["message"] == _t_ecart("U15", _ranges(result, "U15", MONDAY))


def test_evenings_two_days_open_one_session_each_day_without_announcement() -> None:
    """Soirs seulement, deux jours ouverts : une séance par jour, rien à signaler."""
    result = _solve(teams=[_team("U15", spw=2, cap=2)], venues=_evenings([MONDAY, WEDNESDAY]))

    assert len(_starts_on(result, "U15", MONDAY)) == 1
    assert len(_starts_on(result, "U15", WEDNESDAY)) == 1
    assert _gap_diags(result, "U15") == []


def test_evenings_with_a_late_slot_takes_17_00_and_20_30_without_announcement() -> None:
    """Soirs seulement avec un 20:30 ouvert : 17:00 et 20:30 (2 h pile), rien à signaler."""
    result = _solve(teams=[_team("U15", spw=2, cap=2)], venues=_evenings([MONDAY], ("17:00", "18:30", "20:30")))

    assert _starts_on(result, "U15", MONDAY) == ["17:00", "20:30"]
    assert _gap_diags(result, "U15") == []


# --- A8 — non-chevauchement DUR ------------------------------------------------------------


def test_two_free_sessions_of_a_team_never_overlap_even_in_two_venues() -> None:
    """Deux séances libres d'une équipe ne se chevauchent jamais : une séance manque plutôt."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[_venue("A", [(MONDAY, "18:00", 90)]), _venue("B", [(MONDAY, "18:30", 90)])],
    )
    assert len(_placed(result, "T")) == 1, _placed(result, "T")


def test_a_free_slot_overlapping_a_lock_of_the_team_is_closed_by_the_lock() -> None:
    """Un créneau libre qui chevauche un verrou de l'équipe (autre gymnase) est fermé, cause hard_lock."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[_venue("A", [(MONDAY, "18:00", 90)]), _venue("B", [(MONDAY, "18:30", 90)])],
        slot_templates=[_lock("T", "A", MONDAY, "18:00")],
    )
    assert _placed(result, "T") == [(MONDAY, "18:00", "A")]
    causes = {c["kind"] for d in result["diagnostics"] if d.get("teamId") == "T" for c in d.get("causes", [])}
    assert "hard_lock" in causes, result["diagnostics"]


# --- A8bis — écart SOUPLE -------------------------------------------------------------------


def _two_on_monday(first: tuple[str, int], second: tuple[str, int]) -> dict[str, Any]:
    return _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[_venue("A", [(MONDAY, first[0], first[1]), (MONDAY, second[0], second[1])])],
    )


def test_a_gap_of_exactly_two_hours_costs_nothing_and_ninety_minutes_costs_50() -> None:
    """Bornes : 120 min entre deux séances ne coûte rien, 90 min coûte 50 et est annoncé."""
    two_hours = _two_on_monday(("17:00", 90), ("20:30", 90))
    ninety = _two_on_monday(("17:00", 90), ("20:00", 90))
    glued = _two_on_monday(("17:00", 90), ("18:30", 90))

    assert two_hours["score"] - ninety["score"] == GAP_COST
    assert ninety["score"] == glued["score"], "le coût est le même à 0 ou à 90 minutes"
    assert _gap_diags(two_hours, "T") == []
    assert len(_gap_diags(ninety, "T")) == 1


def _example_one(*, tier: int, coaches: list[dict[str, Any]], constraints: list[dict[str, Any]]) -> dict[str, Any]:
    return _solve(
        teams=[_team("T", spw=2, cap=2, tier=tier)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "17:00"), (MONDAY, "18:30")])],
        coaches=coaches,
        constraints=constraints,
    )


def test_a_spaced_place_wins_over_a_glued_pair_without_common_person() -> None:
    """Une place espacée existe le même jour : jamais collé (équipe sans personne commune)."""
    result = _example_one(tier=TIER_B, coaches=[], constraints=[])
    assert "09:00" in _starts_on(result, "T", MONDAY)
    assert _gap_diags(result, "T") == []


def test_a_spaced_place_wins_over_a_glued_pair_with_one_counted_person() -> None:
    """Une place espacée existe : jamais collé, même avec un coach que l'enchaînement compterait."""
    result = _example_one(
        tier=TIER_B,
        coaches=[{"id": "C", "firstName": "Jean", "lastName": "Coach"}],
        constraints=[team_coach("tc", "T", "C")],
    )
    assert "09:00" in _starts_on(result, "T", MONDAY)
    assert _gap_diags(result, "T") == []


def test_a_spaced_place_wins_over_a_glued_pair_for_tier_s_with_two_counted_people() -> None:
    """Exemple 1 : tier S, coach + coach-joueur (2 × 8 en phase 2) — la phase 1 exclut le collé."""
    player_link = {
        "id": "cp-T-P",
        "type": "COACH_PLAYER_UNAVAILABILITY",
        "isActive": True,
        "metadata": {"teamId": "T", "coachId": "P"},
    }
    result = _example_one(
        tier=TIER_S,
        coaches=[
            {"id": "C", "firstName": "Jean", "lastName": "Coach"},
            {"id": "P", "firstName": "Paul", "lastName": "Joueur"},
        ],
        constraints=[team_coach("tc", "T", "C"), player_link],
    )
    assert "09:00" in _starts_on(result, "T", MONDAY)
    assert _gap_diags(result, "T") == []


def _free_beside_lock(lock_start: str, free: list[str]) -> dict[str, Any]:
    starts = sorted({lock_start, *free})
    return _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, s) for s in starts])],
        slot_templates=[_lock("T", "A", MONDAY, lock_start)],
    )


def test_a_free_session_after_a_lock_goes_far_when_it_can_and_costs_50_when_close() -> None:
    """Libre APRÈS un verrou : placée loin si possible ; collée sinon, à 50, et annoncée."""
    both = _free_beside_lock("17:00", ["18:30", "20:30"])
    assert _starts_on(both, "T", MONDAY) == ["17:00", "20:30"]

    far = _free_beside_lock("17:00", ["20:30"])
    near = _free_beside_lock("17:00", ["18:30"])
    assert _starts_on(near, "T", MONDAY) == ["17:00", "18:30"]
    assert far["score"] - near["score"] == GAP_COST
    diags = _gap_diags(near, "T")
    assert len(diags) == 1
    assert diags[0]["message"] == _t_ecart("T", ["17:00-18:30", "18:30-20:00"])


def test_a_free_session_before_a_lock_goes_far_when_it_can_and_costs_50_when_close() -> None:
    """Libre AVANT un verrou : placée loin si possible ; collée sinon, à 50, et annoncée."""
    both = _free_beside_lock("20:00", ["16:30", "18:30"])
    assert _starts_on(both, "T", MONDAY) == ["16:30", "20:00"]

    far = _free_beside_lock("20:00", ["16:30"])
    near = _free_beside_lock("20:00", ["18:30"])
    assert _starts_on(near, "T", MONDAY) == ["18:30", "20:00"]
    assert far["score"] - near["score"] == GAP_COST
    diags = _gap_diags(near, "T")
    assert len(diags) == 1
    assert diags[0]["message"] == _t_ecart("T", ["18:30-20:00", "20:00-21:30"])


def _one_day(cap: int, starts: list[str]) -> dict[str, Any]:
    return _solve(
        teams=[_team("U15", spw=len(starts), cap=cap)],
        venues=[make_venue("A", [(MONDAY, s) for s in starts])],
    )


def test_cap_three_three_sessions_in_a_row_cost_two_gaps_and_one_announcement() -> None:
    """Plafond 3 : trois séances qui se suivent = 2 écarts payés, une seule annonce au pluriel."""
    in_a_row = _one_day(3, ["17:00", "18:30", "20:00"])
    spaced = _one_day(3, ["08:00", "11:30", "15:00"])

    assert len(_starts_on(in_a_row, "U15", MONDAY)) == 3
    assert spaced["score"] - in_a_row["score"] == 2 * GAP_COST
    diags = _gap_diags(in_a_row, "U15")
    assert len(diags) == 1, "une annonce par (équipe, jour)"
    assert diags[0]["message"] == _t_ecart("U15", ["17:00-18:30", "18:30-20:00", "20:00-21:30"])
    assert diags[0]["severity"] == "WARNING"
    assert diags[0].get("ruleKey") is None
    assert diags[0]["suggestions"] == []
    assert diags[0]["dayOfWeek"] == MONDAY


def test_cap_four_four_sessions_in_a_row_cost_three_gaps() -> None:
    """Plafond 4 : même code — quatre séances qui se suivent = 3 écarts payés."""
    in_a_row = _one_day(4, ["15:30", "17:00", "18:30", "20:00"])
    spaced = _one_day(4, ["08:00", "11:30", "15:00", "18:30"])

    assert len(_starts_on(in_a_row, "U15", MONDAY)) == 4
    assert spaced["score"] - in_a_row["score"] == 3 * GAP_COST


def _pair_with_an_unused_slot_after(team_starts: tuple[str, str]) -> dict[str, Any]:
    """T (plafond 2, 2 séances) sur ``team_starts`` ; le créneau 17:00 reste candidat pour T mais O le prend.

    O (HARD « pas avant 17:00 ») n'a que 17:00 : la capacité 1 en ferme l'usage à T sans retirer la
    variable de T à 17:00, que l'encodage de l'écart lit donc à D = 0.
    """
    return _solve(
        teams=[_team("T", spw=2, cap=2), _team("O", spw=1, cap=None)],
        venues=[make_venue("A", [(MONDAY, team_starts[0]), (MONDAY, team_starts[1]), (MONDAY, "17:00")])],
        constraints=[
            team_constraint(
                constraint_id="o-late",
                team_id="O",
                family="TIME",
                rule_type="HARD",
                config={"minStartTime": "17:00"},
            )
        ],
    )


def test_a_glued_pair_costs_50_once_even_with_an_empty_slot_right_after() -> None:
    """Cas (a) de l'encodage : 14:00 et 15:30 pris, 17:00 vide coûtent UN compromis (50), jamais 100.

    Fige le grand M = nombre de voisines (``rhythm.py``) : avec M = P − 1 = 1, à h = 17:00 on a
    D = 0 et V = 2 > 1, ce qui forcerait un second littéral pour une séance qui n'existe pas.
    """
    glued = _pair_with_an_unused_slot_after(("14:00", "15:30"))
    spaced = _pair_with_an_unused_slot_after(("10:00", "14:00"))

    assert _starts_on(glued, "T", MONDAY) == ["14:00", "15:30"], _placed(glued, "T")
    assert _starts_on(glued, "O", MONDAY) == ["17:00"], _placed(glued, "O")
    assert _starts_on(spaced, "T", MONDAY) == ["10:00", "14:00"], _placed(spaced, "T")
    assert spaced["score"] - glued["score"] == GAP_COST


def test_two_locks_at_cap_beside_a_free_candidate_keep_the_model_feasible() -> None:
    """Cas (b) de l'encodage : deux verrous à 15:30 et 16:30 (plafond 2) et un 17:30 libre candidat : planning produit."""
    result = _solve(
        teams=[_team("T", spw=3, cap=2)],
        venues=[
            _venue("A", [(MONDAY, "15:30", 60), (MONDAY, "16:30", 60), (MONDAY, "17:30", 90), (WEDNESDAY, "18:00", 90)])
        ],
        slot_templates=[_lock("T", "A", MONDAY, "15:30", minutes=60), _lock("T", "A", MONDAY, "16:30", minutes=60)],
    )
    assert _placed(result, "T") == [(MONDAY, "15:30", "A"), (MONDAY, "16:30", "A"), (WEDNESDAY, "18:00", "A")]


def test_two_glued_locks_cost_nothing_and_are_never_announced() -> None:
    """Verrou × verrou (réservations 17:00-19:00 et 19:00-21:00) : coût 0, aucun diagnostic."""

    def run(first: str, second: str) -> dict[str, Any]:
        return _solve(
            teams=[_team("T", spw=2, cap=2)],
            venues=[_venue("A", [(MONDAY, first, 120), (MONDAY, second, 120)])],
            slot_templates=[
                _lock("T", "A", MONDAY, first, minutes=120),
                _lock("T", "A", MONDAY, second, minutes=120),
            ],
        )

    glued = run("17:00", "19:00")
    apart = run("09:00", "19:00")
    assert glued["score"] == apart["score"]
    assert [d for d in glued["diagnostics"] if d.get("teamId") == "T"] == [], glued["diagnostics"]


def test_two_glued_locks_of_a_team_at_cap_one_are_never_announced_as_a_gap() -> None:
    """Témoin plafond 1 : deux verrous collés ne portent jamais l'annonce d'écart."""
    result = _solve(
        teams=[_team("T", spw=2, cap=None)],
        venues=[_venue("A", [(MONDAY, "17:00", 120), (MONDAY, "19:00", 120)])],
        slot_templates=[
            _lock("T", "A", MONDAY, "17:00", minutes=120),
            _lock("T", "A", MONDAY, "19:00", minutes=120),
        ],
    )
    assert _gap_diags(result, "T") == []


# --- A10 — « même jour », −10 par rang ------------------------------------------------------


def test_same_day_costs_10_per_session_beyond_the_first() -> None:
    """Rangs : trois séances le même jour coûtent 2 × 10 de plus que trois jours distincts."""
    same_day = _one_day(3, ["08:00", "12:00", "16:00"])
    spread = _solve(
        teams=[_team("U15", spw=3, cap=3)],
        venues=[make_venue("A", [(MONDAY, "08:00"), (WEDNESDAY, "12:00"), (FRIDAY, "16:00")])],
    )
    assert spread["score"] - same_day["score"] == 2 * SAME_DAY_COST


def test_a_coach_day_cap_groups_into_one_spaced_double_on_three_days() -> None:
    """Tableau 1a.4 : coach plafonné à 3 jours, 4 séances, plafond 2 → 3 jours, un doublé espacé."""
    days = [MONDAY, TUESDAY, WEDNESDAY, THURSDAY, FRIDAY]
    result = _solve(
        teams=[_team("T", spw=4, cap=2)],
        venues=[make_venue("A", [(d, s) for d in days for s in ("09:00", "18:00")])],
        coaches=[{"id": "C", "firstName": "Jean", "lastName": "Coach", "maxDaysOverride": 3}],
        constraints=[team_coach("tc", "T", "C")],
    )
    per_day = Counter(d for d, _s, _v in _placed(result, "T"))
    assert sum(per_day.values()) == 4
    assert len(per_day) == 3, per_day
    assert sorted(per_day.values()) == [1, 1, 2]
    assert _gap_diags(result, "T") == []


def test_a_single_preferred_wellness_rule_does_not_group_two_sessions() -> None:
    """Une seule règle Souhaitée (6) + l'écart d'un jour (2) < 10 : pas de regroupement."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00"), (TUESDAY, "18:00")])],
        implicit_rules={"maxConsecutiveDays": {"intensity": "PREFERRED", "maxConsecutiveDays": 2}},
    )
    assert {d for d, _s, _v in _placed(result, "T")} == {MONDAY, TUESDAY}


def test_a_fully_locked_double_scores_as_at_cap_one() -> None:
    """Doublé entièrement verrouillé : le score est celui d'aujourd'hui (aucun terme neuf payé)."""
    venues = [make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00")])]
    locks = [_lock("T", "A", MONDAY, "09:00"), _lock("T", "A", MONDAY, "18:00")]
    today = _solve(teams=[_team("T", spw=2, cap=None)], venues=venues, slot_templates=locks)
    capped = _solve(teams=[_team("T", spw=2, cap=2)], venues=venues, slot_templates=locks)
    assert capped["score"] == today["score"]


def _anchor_case(refs: list[dict[str, Any]]) -> dict[str, Any]:
    """Lundi seul, 17:00 et 20:30 (2 h pile : aucun écart payé), tier D : isole « même jour »."""
    return _solve(
        teams=[_team("T", spw=2, cap=2, tier=TIER_D)],
        venues=[make_venue("W", [(MONDAY, "17:00"), (MONDAY, "20:30")])],
        socleReferenceAssignments=refs,
    )


def test_the_same_day_anchor_two_season_slots_cost_nothing() -> None:
    """Ancre : deux créneaux de saison (références ``keep``) le même jour ne paient pas « même jour »."""
    base = _anchor_case([])
    kept = _anchor_case([_keep("T", MONDAY, "17:00", "W"), _keep("T", MONDAY, "20:30", "W")])
    assert kept["score"] - base["score"] == 2 * 65 + SAME_DAY_COST


def test_the_same_day_anchor_one_season_slot_and_a_new_one_cost_10() -> None:
    """Ancre : un créneau de saison + une séance nouvelle le même jour paient 10."""
    base = _anchor_case([])
    kept = _anchor_case([_keep("T", MONDAY, "17:00", "W")])
    assert kept["score"] - base["score"] == 65


def test_the_same_day_anchor_ignores_references_without_keep() -> None:
    """Ancre : deux références de comblement (sans ``keep``) n'exemptent rien, « même jour » paie 10."""
    base = _anchor_case([])
    filled = _anchor_case([_fill_ref("T", MONDAY, "17:00"), _fill_ref("T", MONDAY, "20:30")])
    assert filled["score"] - base["score"] == 2 * 12


# --- A10bis — ordre des âges avec un doublé libre -------------------------------------------


def test_a_young_double_keeps_older_teams_out_between_its_two_sessions() -> None:
    """Doublé libre matin + soir d'une jeune : aucune plus âgée entre les deux, aucune séance perdue.

    L'aînée préfère le gymnase A (+10) : sans l'ordre des âges elle y prendrait 12:00."""
    result = _solve(
        teams=[_team("Y", spw=2, cap=2, age_min=11), _team("O", spw=1, cap=None, age_min=15)],
        venues=[
            make_venue("A", [(MONDAY, "09:00"), (MONDAY, "12:00"), (MONDAY, "18:00")]),
            make_venue("B", [(MONDAY, "12:00")]),
        ],
        constraints=[
            team_constraint(
                constraint_id="o-pref-a",
                team_id="O",
                family="FACILITY",
                rule_type="PREFERRED",
                config={"preferredVenueId": "A"},
            )
        ],
    )
    assert len(_starts_on(result, "Y", MONDAY)) == 2
    assert _placed(result, "O") == [(MONDAY, "12:00", "B")], "l'aînée passe dans l'autre gymnase"


def test_a_young_team_with_a_lock_is_exempt_from_the_age_order() -> None:
    """Une jeune qui a un verrou est exemptée : l'aînée peut s'entraîner entre ses deux séances.

    L'aînée préfère 12:00 (+5) ; sans l'exemption, ni 09:00 ni 12:00 ne lui seraient permis."""
    result = _solve(
        teams=[_team("Y", spw=2, cap=2, age_min=11), _team("O", spw=1, cap=None, age_min=15)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "12:00"), (MONDAY, "18:00")])],
        slot_templates=[_lock("Y", "A", MONDAY, "18:00")],
        constraints=[
            team_constraint(
                constraint_id="o-pref-noon",
                team_id="O",
                family="TIME",
                rule_type="PREFERRED",
                config={"minStartTime": "11:00"},
            )
        ],
    )
    assert _starts_on(result, "Y", MONDAY) == ["09:00", "18:00"]
    assert _placed(result, "O") == [(MONDAY, "12:00", "A")]


# --- A10ter — Q1 = A, bornée par D1, cas E sous sa vraie forme ------------------------------

SEASON_VENUE = "W"  # gymnase du planning de saison
OTHER_VENUE = "X"  # même horaire, autre gymnase (barème partiel)
COMFORT_VENUE = "P"  # le mercredi confortable


def _season_pair_decor(
    *,
    tier: int,
    monday_venue: str,
    refs: list[dict[str, Any]],
    previous: list[dict[str, Any]] | None = None,
    monday: tuple[tuple[str, int], ...] = (("17:00", 90), ("18:30", 90)),
    locks: list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """Équipe T à plafond 2, 2 séances ; lundi aux horaires de saison ; mercredi 20:00 au confort 20."""
    extra: dict[str, Any] = {"socleReferenceAssignments": refs}
    if previous is not None:
        extra["previousAssignments"] = previous
    return _solve(
        teams=[_team("T", spw=2, cap=2, tier=tier)],
        venues=[
            _venue(monday_venue, [(MONDAY, start, minutes) for start, minutes in monday]),
            _venue(COMFORT_VENUE, [(WEDNESDAY, "20:00", 90)]),
        ],
        constraints=_comfort_on("T", COMFORT_VENUE, WEDNESDAY, "19:30"),
        slot_templates=locks or [],
        **extra,
    )


def _season_refs(*starts: str) -> list[dict[str, Any]]:
    return [_keep("T", MONDAY, start, SEASON_VENUE) for start in starts]


def _assert_season_pair_kept(result: dict[str, Any], ranges: list[str]) -> None:
    assert _starts_on(result, "T", MONDAY) == [r[:5] for r in ranges], _placed(result, "T")
    assert _starts_on(result, "T", WEDNESDAY) == []
    diags = _gap_diags(result, "T")
    assert len(diags) == 1, result["diagnostics"]
    assert diags[0]["message"] == _t_ecart("T", ranges, season=True)


def test_season_pair_tier_d_other_venue_is_kept_and_announced_as_season() -> None:
    """(a)+(c) Tier D, même horaire autre gymnase (44) : les deux créneaux de saison sont gardés."""
    result = _season_pair_decor(tier=TIER_D, monday_venue=OTHER_VENUE, refs=_season_refs("17:00", "18:30"))
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_season_pair_tier_d_same_venue_is_kept_and_announced_as_season() -> None:
    """(b)+(c) Tier D, même gymnase (65) : la paire de saison est gardée."""
    result = _season_pair_decor(tier=TIER_D, monday_venue=SEASON_VENUE, refs=_season_refs("17:00", "18:30"))
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_season_pair_tier_s_other_venue_is_kept_and_announced_as_season() -> None:
    """(b)+(c) Tier S, autre gymnase (60) : la paire de saison est gardée."""
    result = _season_pair_decor(tier=TIER_S, monday_venue=OTHER_VENUE, refs=_season_refs("17:00", "18:30"))
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_season_pair_tier_s_same_venue_is_kept_and_announced_as_season() -> None:
    """(b)+(c) Tier S, même gymnase (85) : la paire de saison est gardée."""
    result = _season_pair_decor(tier=TIER_S, monday_venue=SEASON_VENUE, refs=_season_refs("17:00", "18:30"))
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_season_pair_is_kept_on_regeneration_with_previous_placement() -> None:
    """(d) Régénération : placement précédent + références « garder », la paire reste gardée."""
    previous = [
        {"teamId": "T", "venueId": OTHER_VENUE, "dayOfWeek": MONDAY, "startTime": "17:00"},
        {"teamId": "T", "venueId": OTHER_VENUE, "dayOfWeek": MONDAY, "startTime": "18:30"},
    ]
    result = _season_pair_decor(
        tier=TIER_D, monday_venue=OTHER_VENUE, refs=_season_refs("17:00", "18:30"), previous=previous
    )
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_without_season_references_the_pair_is_split_monday_and_wednesday() -> None:
    """(e) Témoin : même décor SANS références → lundi + mercredi, aucune annonce d'écart."""
    result = _season_pair_decor(tier=TIER_D, monday_venue=OTHER_VENUE, refs=[])
    assert len(_starts_on(result, "T", MONDAY)) == 1
    assert _starts_on(result, "T", WEDNESDAY) == ["20:00"]
    assert _gap_diags(result, "T") == []


def test_one_season_slot_left_keeps_it_and_takes_another_day() -> None:
    """(f) Une seule référence ``keep`` (17:00), tier D partiel, un mercredi libre : 17:00 + mercredi."""
    result = _season_pair_decor(tier=TIER_D, monday_venue=OTHER_VENUE, refs=_season_refs("17:00"))
    assert _starts_on(result, "T", MONDAY) == ["17:00"]
    assert _starts_on(result, "T", WEDNESDAY) == ["20:00"]
    assert _gap_diags(result, "T") == []


def test_one_season_slot_left_monday_only_takes_17_00_and_20_30() -> None:
    """(f) Variante lundi seul avec 20:30 : 17:00 + 20:30, aucune annonce d'écart."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2, tier=TIER_D)],
        venues=[make_venue(OTHER_VENUE, [(MONDAY, "17:00"), (MONDAY, "18:30"), (MONDAY, "20:30")])],
        socleReferenceAssignments=_season_refs("17:00"),
    )
    assert _starts_on(result, "T", MONDAY) == ["17:00", "20:30"]
    assert _gap_diags(result, "T") == []


def test_a_lock_at_a_season_time_counts_as_a_season_slot_lock_first() -> None:
    """(g) Verrou à 17:00 (horaire de saison), libre à 18:30 (saison), tier D autre gymnase : gardé."""
    result = _season_pair_decor(
        tier=TIER_D,
        monday_venue=OTHER_VENUE,
        refs=_season_refs("17:00", "18:30"),
        locks=[_lock("T", OTHER_VENUE, MONDAY, "17:00")],
    )
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_a_lock_at_a_season_time_counts_as_a_season_slot_lock_second() -> None:
    """(g) Ordre inverse : libre à 17:00 (saison), verrou à 18:30 (saison) : gardé, T-écart-saison."""
    result = _season_pair_decor(
        tier=TIER_D,
        monday_venue=OTHER_VENUE,
        refs=_season_refs("17:00", "18:30"),
        locks=[_lock("T", OTHER_VENUE, MONDAY, "18:30")],
    )
    _assert_season_pair_kept(result, ["17:00-18:30", "18:30-20:00"])


def test_a_lock_without_a_season_reference_at_its_time_sends_the_free_session_away() -> None:
    """(g) Contre-témoin : verrou à 17:00 sans référence à son heure → la séance libre va au mercredi."""
    result = _season_pair_decor(
        tier=TIER_D,
        monday_venue=OTHER_VENUE,
        refs=_season_refs("18:30"),
        locks=[_lock("T", OTHER_VENUE, MONDAY, "17:00")],
    )
    assert _starts_on(result, "T", MONDAY) == ["17:00"]
    assert _starts_on(result, "T", WEDNESDAY) == ["20:00"]


def test_a_coach_over_his_day_cap_does_not_glue_two_sessions() -> None:
    """(h) Hors saison : un coach au-delà de son plafond (15) ne fait pas coller (autre jour retenu)."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "17:00"), (MONDAY, "18:30"), (WEDNESDAY, "18:00")])],
        coaches=[{"id": "C", "firstName": "Jean", "lastName": "Coach", "maxDaysOverride": 1}],
        constraints=[team_coach("tc", "T", "C")],
    )
    assert {d for d, _s, _v in _placed(result, "T")} == {MONDAY, WEDNESDAY}


def test_fill_references_without_keep_never_exempt_two_glued_holes() -> None:
    """(i) D1 : deux trous de comblement aux horaires de saison collés + une place espacée → séparés."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2, tier=TIER_S)],
        venues=[make_venue("A", [(MONDAY, "17:00"), (MONDAY, "18:30"), (WEDNESDAY, "18:00")])],
        socleReferenceAssignments=[_fill_ref("T", MONDAY, "17:00"), _fill_ref("T", MONDAY, "18:30")],
    )
    assert len(_starts_on(result, "T", MONDAY)) == 1
    assert _starts_on(result, "T", WEDNESDAY) == ["18:00"]


def _filled_beside_pinned(free: list[tuple[int, str]], refs: list[dict[str, Any]]) -> dict[str, Any]:
    return _solve(
        teams=[_team("T", spw=2, cap=2, tier=TIER_S)],
        venues=[make_venue("A", [(MONDAY, "17:00"), *free])],
        slot_templates=[_lock("T", "A", MONDAY, "17:00")],
        socleReferenceAssignments=refs,
    )


def test_a_hole_glued_to_a_pinned_session_is_filled_elsewhere_when_it_can() -> None:
    """(i) Épinglé à 17:00 + trou d'horaire de saison 18:30 + une autre place → pas comblé à 18:30."""
    result = _filled_beside_pinned([(MONDAY, "18:30"), (WEDNESDAY, "18:00")], [_fill_ref("T", MONDAY, "18:30")])
    assert _starts_on(result, "T", MONDAY) == ["17:00"]
    assert _starts_on(result, "T", WEDNESDAY) == ["18:00"]


def test_a_hole_glued_to_a_pinned_session_without_other_place_costs_60_and_says_so() -> None:
    """(i) Aucune autre place → comblé à 18:30, coût 60, T-écart SANS mention de saison."""
    glued = _filled_beside_pinned([(MONDAY, "18:30")], [_fill_ref("T", MONDAY, "18:30")])
    other_day = _filled_beside_pinned([(WEDNESDAY, "18:30")], [_fill_ref("T", WEDNESDAY, "18:30")])

    assert _starts_on(glued, "T", MONDAY) == ["17:00", "18:30"]
    assert other_day["score"] - glued["score"] == GAP_COST + SAME_DAY_COST
    diags = _gap_diags(glued, "T")
    assert len(diags) == 1
    assert diags[0]["message"] == _t_ecart("T", ["17:00-18:30", "18:30-20:00"])


def test_season_reference_keys_keep_only_keep_references() -> None:
    """(i) ``season_reference_keys`` ne retient que les références ``keep``, clés normalisées."""
    from app.solver.objective.terms.stability import season_reference_keys

    assert season_reference_keys(None) == frozenset()
    assert season_reference_keys([_fill_ref("T", MONDAY, "17:00")]) == frozenset()
    assert season_reference_keys([_keep("T", MONDAY, "17:00", "W"), _fill_ref("T", MONDAY, "18:30")]) == frozenset(
        {("T", MONDAY, "17:00")}
    )


def _case_e(*, tier: int, slot_venue: str) -> dict[str, Any]:
    """Lundi seul : 14:00, 17:00 (seule référence ``keep``), 18:30 ; aucun confort saisi."""
    return _solve(
        teams=[_team("T", spw=2, cap=2, tier=tier)],
        venues=[make_venue(slot_venue, [(MONDAY, "14:00"), (MONDAY, "17:00"), (MONDAY, "18:30")])],
        socleReferenceAssignments=_season_refs("17:00"),
    )


def _assert_case_e_keeps(result: dict[str, Any]) -> None:
    assert "17:00" in _starts_on(result, "T", MONDAY), _placed(result, "T")
    diags = _gap_diags(result, "T")
    assert len(diags) == 1, result["diagnostics"]
    assert not diags[0]["message"].endswith(SEASON_SUFFIX)
    assert diags[0]["message"] == _t_ecart("T", _ranges(result, "T", MONDAY))


def _assert_case_e_lets_go(result: dict[str, Any]) -> None:
    assert _starts_on(result, "T", MONDAY) == ["14:00", "18:30"], _placed(result, "T")
    assert _gap_diags(result, "T") == []
    kept = [d for d in result["diagnostics"] if d["type"] == "season_slots_kept" and d.get("teamId") == "T"]
    assert len(kept) == 1, result["diagnostics"]
    assert [c["kind"] for c in kept[0]["causes"]] == ["displaced"]


def test_case_e_tier_d_same_venue_keeps_its_season_slot() -> None:
    """(j) Cas E, tier D plein (65 > 50) : garde 17:00 et l'annonce T-écart."""
    _assert_case_e_keeps(_case_e(tier=TIER_D, slot_venue=SEASON_VENUE))


def test_case_e_tier_b_other_venue_keeps_its_season_slot() -> None:
    """(j) Cas E, tier B partiel (52 > 50) : garde 17:00 et l'annonce T-écart."""
    _assert_case_e_keeps(_case_e(tier=TIER_B, slot_venue=OTHER_VENUE))


def test_case_e_tier_c_other_venue_lets_its_season_slot_go() -> None:
    """(j) Cas E, tier C partiel (48 < 50) : 14:00 + 18:30, le créneau de saison est nommé « déplacé »."""
    _assert_case_e_lets_go(_case_e(tier=TIER_C, slot_venue=OTHER_VENUE))


def test_case_e_tier_d_other_venue_lets_its_season_slot_go() -> None:
    """(j) Cas E, tier D partiel (44 < 50) : 14:00 + 18:30, le créneau de saison est nommé « déplacé »."""
    _assert_case_e_lets_go(_case_e(tier=TIER_D, slot_venue=OTHER_VENUE))


def test_season_exemption_reads_the_start_time_not_the_period_duration() -> None:
    """(k) Saison 17:00 et 20:30 ; en période le 17:00 dure 2 h : gardées, annoncées avec les heures de période."""
    result = _season_pair_decor(
        tier=TIER_D,
        monday_venue=SEASON_VENUE,
        refs=_season_refs("17:00", "20:30"),
        monday=(("17:00", 120), ("20:30", 90)),
    )
    _assert_season_pair_kept(result, ["17:00-19:00", "20:30-22:00"])


# --- A14 — plancher de gymnase à plafond 2 --------------------------------------------------


def _floor(team_id: str, venue_id: str, count: int) -> dict[str, Any]:
    return team_constraint(
        constraint_id=f"floor-{team_id}-{venue_id}",
        team_id=team_id,
        family="FACILITY",
        rule_type="HARD",
        config={"minAtVenueId": venue_id, "minAtVenueCount": count},
    )


def _floor_diags(result: dict[str, Any], team_id: str) -> list[dict[str, Any]]:
    return [d for d in result["diagnostics"] if d["type"] == "venue_minimum_unreachable" and d.get("teamId") == team_id]


def test_floor_two_disjoint_locks_the_same_day_satisfy_it_silently() -> None:
    """Plancher 2 au gymnase A, deux verrous disjoints le même lundi à plafond 2 : silence."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "09:00"), (MONDAY, "18:00")])],
        constraints=[_floor("T", "A", 2)],
        slot_templates=[_lock("T", "A", MONDAY, "09:00"), _lock("T", "A", MONDAY, "18:00")],
    )
    assert _floor_diags(result, "T") == []


def test_floor_three_evening_slots_in_a_row_hold_it_without_floor_message() -> None:
    """Plancher 2, trois créneaux du soir qui se suivent à A : 2 séances à A, aucun T-gym, T-écart."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "17:00"), (MONDAY, "18:30"), (MONDAY, "20:00")])],
        constraints=[_floor("T", "A", 2)],
    )
    assert len(_starts_on(result, "T", MONDAY)) == 2
    assert _floor_diags(result, "T") == []
    assert len(_gap_diags(result, "T")) == 1


def test_floor_one_slot_at_the_venue_names_t_gym_without_failing() -> None:
    """Plancher 2, un seul créneau à A : T-gym (« à 2 séances par jour au plus »), jamais INFEASIBLE."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[make_venue("A", [(MONDAY, "17:00")]), make_venue("B", [(TUESDAY, "18:00")])],
        constraints=[_floor("T", "A", 2)],
    )
    diags = _floor_diags(result, "T")
    assert len(diags) == 1
    assert diags[0]["message"] == (
        "T demande au moins 2 séance(s) à A, mais ce gymnase n'offre que 1 séance(s) libre(s) en plus de "
        "ses 0 séance(s) réservée(s), à 2 séances par jour au plus."
    )


def test_floor_two_overlapping_slots_count_for_one() -> None:
    """Plancher 2 : deux créneaux de A qui se chevauchent le même jour ne comptent que pour un."""
    result = _solve(
        teams=[_team("T", spw=2, cap=2)],
        venues=[_venue("A", [(MONDAY, "17:00", 90), (MONDAY, "17:45", 90)]), make_venue("B", [(TUESDAY, "18:00")])],
        constraints=[_floor("T", "A", 2)],
    )
    assert len(_floor_diags(result, "T")) == 1


def test_floor_reachable_only_through_another_teams_lock_is_still_posed() -> None:
    """Plancher atteignable sans le verrou d'une autre équipe : posé quand même, aucun T-gym."""
    result = solve_payload(
        make_payload(
            teams=[_team("T", spw=2, cap=2), _team("O", spw=1, cap=None)],
            venues=[make_venue("A", [(MONDAY, "17:00"), (MONDAY, "20:00")]), make_venue("B", [(TUESDAY, "18:00")])],
            constraints=[_floor("T", "A", 2)],
            slot_templates=[_lock("O", "A", MONDAY, "20:00")],
            timeout=10,
        )
    )
    assert result["status"] == "completed", result["diagnostics"]
    assert _floor_diags(result, "T") == []
