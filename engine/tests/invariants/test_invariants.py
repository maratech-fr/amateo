from __future__ import annotations

import pathlib
from typing import Any

import pytest
from hypothesis import given, settings
from hypothesis import strategies as st

from app.main import read_contract_version
from app.solver.constraints import parse_v2_constraints
from tests.support.pipeline import make_payload, make_venue, solve_payload, team_coach

CONTRACT_VERSION = read_contract_version()

FIXTURES_DIR = pathlib.Path(__file__).resolve().parents[1] / "fixtures"


def _team_age_min_by_id(data: dict[str, Any]) -> dict[str, int | None]:
    return {team["id"]: team.get("ageMin") for team in data.get("teams", []) if team.get("id")}


def _assert_venue_capacity_respected(data: dict[str, Any], result: dict[str, Any]) -> None:
    """L'invariant de capacité, en UN endroit — partagé par le test hypothesis et le cas figé.

    ⚑ P4-81 : les deux tests doivent juger sur la MÊME règle. Recopier l'assertion dans le cas
    déterministe l'aurait laissée diverger, et c'est justement la divergence entre ce qu'on
    croit garder et ce qu'on garde vraiment qui a produit ce défaut.
    """

    # Le payload écrit "17:00", la réponse JSON rend "17:00:00" (le `mode="json"` du harnais,
    # AUD-ENG-28). Sans normalisation, AUCUNE capacité ne serait retrouvée : le test
    # retomberait silencieusement sur le défaut de 1 — vert, mais pour une mauvaise raison.
    def hhmm(value: object) -> str:
        return ":".join(str(value).split(":")[:2])

    capacities: dict[tuple[str, int, str], int] = {}
    for venue in data.get("venues", []):
        for ts in venue.get("trainingSlots", []):
            capacities[(venue["id"], ts["dayOfWeek"], hhmm(ts["startTime"]))] = int(ts.get("capacity", 1))

    venue_bookings: dict[tuple[str, int, str], list[dict[str, Any]]] = {}
    for slot in result["slots"]:
        key = (slot["venueId"], slot["dayOfWeek"], hhmm(slot["startTime"]))
        venue_bookings.setdefault(key, []).append(slot)

    for key, slots in venue_bookings.items():
        pinned = [s for s in slots if s.get("lockLevel") == "HARD"]

        if pinned:
            intruders = [s["teamId"] for s in slots if s.get("lockLevel") != "HARD"]
            assert not intruders, (
                f"Créneau VERROUILLÉ {key} : le solveur y a placé {intruders} en plus des épingles. "
                "`blocked_venue_slots` (model.py:67) doit retirer ce créneau du solveur — ALIGN-07."
            )
            continue

        teams = [s["teamId"] for s in slots]
        capacity = capacities.get(key, 1)
        assert len(teams) <= capacity, (
            f"Gymnase sur-rempli par le SOLVEUR en {key} : {teams} pour une capacité de {capacity}"
        )


def _hard_locked_team_ids(data: dict[str, Any]) -> set[str]:
    return {
        tpl["teamId"] for tpl in data.get("slotTemplates", []) if tpl.get("teamId") and tpl.get("lockLevel") == "HARD"
    }


# ---------------------------------------------------------------------------
# Hypothesis strategies
# ---------------------------------------------------------------------------


def _time_str(minutes: int) -> str:
    return f"{minutes // 60:02d}:{minutes % 60:02d}"


slot_start_st = st.sampled_from([17 * 60, 18 * 60, 19 * 60, 20 * 60])
duration_st = st.sampled_from([60, 90, 120])
day_st = st.sampled_from([1, 2, 3, 4, 5])
venue_id_st = st.sampled_from(["gym-a", "gym-b", "court-1"])
coach_id_st = st.sampled_from(["coach-1", "coach-2", "coach-3"])
team_id_st = st.sampled_from(["team-s", "team-a", "team-b", "team-c", "team-d"])
tier_st = st.sampled_from([1, 2, 3, 4, 5])


@st.composite
def random_fixture(draw: st.DrawFn) -> dict[str, Any]:
    num_venues = draw(st.integers(min_value=1, max_value=3))
    num_teams = draw(st.integers(min_value=1, max_value=5))
    num_coaches = draw(st.integers(min_value=1, max_value=3))

    venues = []
    for _ in range(num_venues):
        vid = draw(venue_id_st)
        if not any(v["id"] == vid for v in venues):
            venues.append({"id": vid, "name": f"Venue {vid}"})

    coaches = []
    for i in range(num_coaches):
        cid = draw(coach_id_st)
        if not any(c["id"] == cid for c in coaches):
            coaches.append({"id": cid, "firstName": f"Coach{i}", "lastName": "X"})

    teams = []
    for _ in range(num_teams):
        tid = draw(team_id_st)
        tier = draw(tier_st)
        if not any(t["id"] == tid for t in teams):
            teams.append(
                {
                    "id": tid,
                    "sportCategoryId": "sc-1",
                    "priorityTierId": tier,
                    "name": f"Team {tid}",
                    "sessionsPerWeek": draw(st.integers(min_value=1, max_value=2)),
                }
            )

    venue_avail_map: dict[str, list[dict[str, Any]]] = {v["id"]: [] for v in venues}
    for v in venues:
        for d in draw(st.lists(day_st, min_size=1, max_size=3, unique=True)):
            start = draw(slot_start_st)
            duration = draw(st.sampled_from([60, 120, 180]))
            venue_avail_map[v["id"]].append(
                {"dayOfWeek": d, "startTime": _time_str(start), "durationMinutes": duration, "capacity": 1}
            )

    templates = []
    seen_template_keys: set[tuple[str, str, int, str]] = set()
    seen_team_ids: set[str] = set()
    hard_locks = draw(st.lists(st.booleans(), min_size=0, max_size=2))
    for i, is_hard in enumerate(hard_locks):
        t = draw(st.sampled_from(teams)) if teams else None
        v = draw(st.sampled_from(venues)) if venues else None
        c = draw(st.sampled_from(coaches)) if coaches else None
        if t and v:
            # Limit each team to at most one template so result_builder
            # coach assignment is unambiguous.
            if t["id"] in seen_team_ids:
                continue
            seen_team_ids.add(t["id"])
            d = draw(day_st)
            start = draw(slot_start_st)
            tpl_key = (t["id"], v["id"], d, _time_str(start))
            if tpl_key in seen_template_keys:
                continue
            seen_template_keys.add(tpl_key)
            templates.append(
                {
                    "id": f"tpl-{i}",
                    "teamId": t["id"],
                    "venueId": v["id"],
                    "coachId": c["id"] if c else None,
                    "dayOfWeek": d,
                    "startTime": _time_str(start),
                    "durationMinutes": draw(duration_st),
                    "lockLevel": "HARD" if is_hard else "NONE",
                }
            )

    # Inject training slots into venue objects
    for v in venues:
        v["trainingSlots"] = venue_avail_map.get(v["id"], [])

    # AUD-ENG-28 — le coach voyage par une contrainte TEAM_COACH, comme en production.
    #
    # Le harnais local lisait `slotTemplates[].coachId` ; la prod, elle, construit son
    # `team_coach_map` depuis `parse_v2_constraints`. Migrer les invariants sur le vrai
    # pipeline SANS émettre ces contraintes aurait vidé les invariants coach de leur
    # substance : `team_coach_map` serait resté vide, aucune contrainte coach n'aurait été
    # posée, et « aucun coach n'est dédoublé » serait devenu vrai par absence de coach.
    #
    # C'est le piège que le commentaire « deferred to E1 » signalait. On ne l'évite pas en
    # gardant un pipeline parallèle : on l'évite en faisant porter aux fixtures la forme
    # que le backend sérialise vraiment.
    # CHAQUE équipe porte un coach MAIN, comme en production — pas seulement celles qui
    # ont un template. Limiter les coachs aux équipes templatées rendait
    # `test_no_coach_double_booking` VIDE : au plus deux équipes en avaient un, et
    # neutraliser les deux contraintes coach du solveur laissait l'invariant vert.
    coach_by_team = {t["id"]: draw(st.sampled_from(coaches))["id"] for t in teams} if coaches else {}
    constraints = [team_coach(f"tc-{i}", tid, cid) for i, (tid, cid) in enumerate(coach_by_team.items())]

    # Le coach du template suit la même carte : deux vérités sur « qui coache cette
    # équipe » se contrediraient, et c'est le template que `result_builder` affiche.
    for tpl in templates:
        if tpl["teamId"] in coach_by_team:
            tpl["coachId"] = coach_by_team[tpl["teamId"]]

    return {
        "clubId": "club-hypothesis",
        "seasonId": "season-2024",
        "version": CONTRACT_VERSION,
        "solverSeed": 42,
        "venues": venues,
        "teams": teams,
        "coaches": coaches,
        "slotTemplates": templates,
        "constraints": constraints,
    }


# ---------------------------------------------------------------------------
# Invariant tests
# ---------------------------------------------------------------------------


def test_the_fixtures_really_exercise_coaches() -> None:
    """AUD-ENG-28 — le garde qui empêche les invariants coach de devenir vides.

    Les invariants tournent sur le vrai pipeline, qui lit les coachs des contraintes
    TEAM_COACH (`parse_v2_constraints`) et de nulle part ailleurs. Si les fixtures cessaient
    d'en émettre — par exemple en revenant au `slotTemplates[].coachId` d'avant —
    `team_coach_map` redeviendrait vide, aucune contrainte coach ne serait posée, et
    `test_no_coach_double_booking` passerait **sans rien vérifier**.

    C'est le mode de panne le plus dangereux d'une suite de tests : elle reste verte en
    ayant cessé de prouver quoi que ce soit.
    """
    payloads = [random_fixture().example() for _ in range(30)]
    with_coach = [p for p in payloads if parse_v2_constraints(p["constraints"]).get("team_coach_map")]

    assert with_coach, (
        "Aucune des 30 fixtures générées ne produit de team_coach_map : les invariants coach "
        "ne vérifient plus rien. Les fixtures doivent émettre des contraintes TEAM_COACH — "
        "c'est de là, et de là seulement, que la production tire ses coachs."
    )

    # Et le lien doit être FIDÈLE : chaque coach épinglé sur un template atteint la carte
    # que le solveur consomme.
    for payload in with_coach:
        mapped = parse_v2_constraints(payload["constraints"])["team_coach_map"]
        for tpl in payload["slotTemplates"]:
            if tpl.get("coachId"):
                assert tpl["coachId"] in mapped.get(tpl["teamId"], []), (
                    f"le coach {tpl['coachId']} de l'équipe {tpl['teamId']} n'atteint pas le solveur"
                )


class TestInvariants:
    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_no_venue_double_booking(self, data: dict[str, Any]) -> None:
        """Un gymnase n'accueille jamais plus d'équipes que sa CAPACITÉ — côté solveur.

        ⚑ P4-81. L'assertion était `len(team_ids) <= 1` **en dur**, et elle ignorait deux
        faits du modèle. Elle rougissait donc **au hasard des tirages hypothesis**, sur un
        required check, bloquant des PR qui ne touchaient même pas le moteur (constaté le
        2026-08-11 sur la PR Dependabot #504).

        (1) **La capacité n'est pas toujours 1.** `model.py:58` lit `capacity` du créneau et
        `add_room_at_most_one` la fait respecter — un terrain divisible en 2 ou 3 accueille
        légitimement autant d'équipes. Le générateur n'émet aujourd'hui que `capacity: 1`
        (`:176`), donc l'ancienne assertion PASSAIT par coïncidence ; elle serait devenue
        fausse au premier créneau divisible tiré.

        (2) **Un verrou HARD est SOUVERAIN** (ALIGN-07). `model.py:67` saute la création de
        la variable quand le créneau est dans `blocked_venue_slots` : le solveur ne peut
        placer personne dessus. Or la dédup du générateur porte sur
        `(teamId, venueId, jour, heure)` (`:194`) — **deux ÉQUIPES différentes** peuvent donc
        être co-épinglées sur le même créneau, au-delà de sa capacité. Le moteur honore les
        épingles ; l'invariant, lui, criait à l'erreur.

        Même doctrine que `test_no_coach_double_booking` juste dessous : **l'invariant porte
        sur ce que le MOTEUR décide**, le seul périmètre dont il répond. Ce que le
        gestionnaire épingle lui-même relève de sa décision, pas d'une garantie du solveur
        (décision fondateur 2026-08-11 : l'encart des règles implicites ne nuance PAS son
        énoncé pour autant — cf. état des lieux §2).

        ⚑ Et le test GAGNE une garantie qu'il n'avait pas : sur un créneau verrouillé, tous
        les occupants doivent être des épingles. Si le solveur en plaçait une de plus, il
        aurait contourné `blocked_venue_slots` — exactement le trou qu'ALIGN-07 a fermé, et
        que rien ne gardait ici.
        """
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        _assert_venue_capacity_respected(data, result)

    def test_the_exact_case_that_reddened_ci_at_random(self) -> None:
        """NR P4-81 — le contre-exemple RÉEL, rejoué à l'identique et sans hasard.

        Recopié du log CI du 2026-08-11 (PR Dependabot #504, job `Engine Tests`), qui rendait
        `Venue double-booking at ('gym-a', 1, '17:00:00'): ['team-s', 'team-a']`. Un gymnase,
        UN créneau de capacité 1, et DEUX épingles HARD dessus.

        ⚠ Sans ce cas figé, la garde reposerait sur un tirage : hypothesis ne produit cette
        forme qu'occasionnellement — c'est précisément ce qui rendait la panne intermittente
        et le diagnostic coûteux. Un test qui ne rougit qu'un jour sur trois ne garde rien.

        Ce qu'il prouve : le moteur honore les deux épingles (le verrou est souverain), et
        l'invariant ne le lui reproche plus — tout en vérifiant qu'aucune équipe NON épinglée
        ne s'est ajoutée.
        """
        data: dict[str, Any] = {
            "clubId": "club-hypothesis",
            "seasonId": "season-2024",
            "version": CONTRACT_VERSION,
            "solverSeed": 42,
            "venues": [
                {
                    "id": "gym-a",
                    "name": "Venue gym-a",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "17:00", "durationMinutes": 60, "capacity": 1}],
                }
            ],
            "teams": [
                {
                    "id": tid,
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": f"Team {tid}",
                    "sessionsPerWeek": 1,
                }
                for tid in ("team-s", "team-a")
            ],
            "coaches": [{"id": "coach-1", "firstName": "Coach0", "lastName": "X"}],
            "slotTemplates": [
                {
                    "id": f"tpl-{i}",
                    "teamId": tid,
                    "venueId": "gym-a",
                    "coachId": "coach-1",
                    "dayOfWeek": 1,
                    "startTime": "17:00",
                    "durationMinutes": 60,
                    "lockLevel": "HARD",
                }
                for i, tid in enumerate(("team-s", "team-a"))
            ],
            "constraints": [team_coach(f"tc-{i}", tid, "coach-1") for i, tid in enumerate(("team-s", "team-a"))],
        }

        result = solve_payload(data, timeout=5)
        assert result["status"] == "completed", f"le solve doit aboutir, statut={result['status']}"

        on_the_slot = [s for s in result["slots"] if s["venueId"] == "gym-a" and s["dayOfWeek"] == 1]
        assert {s["teamId"] for s in on_the_slot} == {"team-s", "team-a"}, (
            "les DEUX épingles doivent sortir : le verrou est souverain (ALIGN-07), "
            "le moteur ne doit pas en écarter une pour tenir la capacité"
        )
        assert all(s.get("lockLevel") == "HARD" for s in on_the_slot), (
            "toutes les occupations de ce créneau viennent des épingles — "
            "le solveur n'a pas le droit d'en ajouter une (`blocked_venue_slots`, model.py:67)"
        )

        # Et l'invariant lui-même — la MÊME règle que le test hypothesis — ne doit plus
        # crier au loup sur ce payload.
        _assert_venue_capacity_respected(data, result)

    def test_the_solver_never_sneaks_a_team_onto_a_locked_slot(self) -> None:
        """NR P4-81 — le filet « un créneau verrouillé ne contient QUE des épingles ».

        ⚠ Ce cas existe parce que les fixtures aléatoires ne l'atteignent PAS : mesuré en
        désarmant `blocked_venue_slots` dans `model.py` — les deux tests de capacité
        restaient VERTS. Un filet qu'aucun scénario ne met à l'épreuve ne garde rien, et
        c'est le genre de vide qui ne se voit jamais tant qu'on ne le cherche pas.

        Le montage force la main du solveur : UN seul créneau dans tout le club, déjà
        verrouillé pour `team-pinned`, et une seconde équipe `team-greedy` qui n'a aucune
        autre place où aller. Si `blocked_venue_slots` (model.py:67) cessait de retirer ce
        créneau du modèle, le solveur y poserait `team-greedy` — sur-remplissant un créneau
        de capacité 1 que le gestionnaire croyait réservé à son épingle.

        Vérifié dans les deux sens : désarmer `blocked_venue_slots` fait rougir ce test.
        """
        data: dict[str, Any] = {
            "clubId": "club-lock",
            "seasonId": "season-2024",
            "version": CONTRACT_VERSION,
            "solverSeed": 42,
            "venues": [
                {
                    "id": "gym-a",
                    "name": "Venue gym-a",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 60, "capacity": 1}],
                }
            ],
            "teams": [
                {
                    "id": tid,
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": f"Team {tid}",
                    "sessionsPerWeek": 1,
                }
                for tid in ("team-pinned", "team-greedy")
            ],
            "coaches": [
                {"id": "coach-1", "firstName": "A", "lastName": "X"},
                {"id": "coach-2", "firstName": "B", "lastName": "X"},
            ],
            "slotTemplates": [
                {
                    "id": "tpl-0",
                    "teamId": "team-pinned",
                    "venueId": "gym-a",
                    "coachId": "coach-1",
                    "dayOfWeek": 1,
                    "startTime": "18:00",
                    "durationMinutes": 60,
                    "lockLevel": "HARD",
                }
            ],
            # Coachs DISTINCTS : sans ça, l'exclusivité coach suffirait à écarter
            # `team-greedy` et le test passerait sans rien devoir à `blocked_venue_slots`.
            "constraints": [
                team_coach("tc-0", "team-pinned", "coach-1"),
                team_coach("tc-1", "team-greedy", "coach-2"),
            ],
        }

        result = solve_payload(data, timeout=5)
        assert result["status"] == "completed", f"le solve doit aboutir, statut={result['status']}"

        occupants = {s["teamId"] for s in result["slots"] if s["venueId"] == "gym-a" and s["dayOfWeek"] == 1}
        assert occupants == {"team-pinned"}, (
            f"le créneau verrouillé doit rester à la seule épingle, occupants={sorted(occupants)} — "
            "`team-greedy` n'a nulle part où aller, et c'est très bien : elle reste non placée"
        )

        _assert_venue_capacity_respected(data, result)

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_no_coach_double_booking(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        # ⚑ D-14 (2026-08-09) — la clé porte le GYMNASE, et ce n'est pas un détail.
        #
        # Cet invariant épinglait « un coach, une équipe à la fois », gymnase ignoré. C'est
        # la règle que D-14 a RETIRÉE : un coach peut tenir les SM1 et les SM2 au même
        # moment dans le MÊME gymnase — il y est présent une fois, il surveille deux
        # groupes. Seuls deux gymnases DIFFÉRENTS restent une impossibilité physique.
        #
        # ⚠ Il a survécu à D-14 parce qu'il était VIDE : jusqu'à AUD-ENG-28, seules les
        # équipes portant un template avaient un coach, et le cas ne se produisait jamais.
        # Rendre l'invariant vivant a réveillé une règle périmée — il échouait 2 fois sur 8,
        # avec deux équipes du même gymnase pour « preuve ».
        #
        # La leçon vaut d'être gardée : un test qu'on croit inutile parce qu'il ne casse
        # jamais peut simplement ne rien vérifier, et sa règle continue de vieillir.
        # ⚑ Les créneaux VERROUILLÉS sont hors sujet ici, et c'est structurel : un verrou
        # HARD est pré-placé HORS du solveur (P2-9 PR B), sa variable n'existe pas, donc
        # aucune contrainte du moteur ne peut le voir — encore moins refuser qu'il dédouble
        # un coach. C'est le BACKEND qui l'interdit, en amont, au récap
        # (`CoachDoubleBookingDetector` bloque la génération).
        #
        # Mesuré, pas supposé : les cas qui faisaient rougir cet invariant opposaient
        # systématiquement un créneau `lockLevel: HARD` à un créneau placé par le solveur.
        # L'invariant porte donc sur ce que le MOTEUR décide — le seul périmètre dont il
        # répond. Y inclure les verrous ferait échouer le moteur pour une garantie qu'il
        # n'a jamais eue, et qui est tenue ailleurs.
        coach_bookings: dict[tuple[str, int, str, str], set[str]] = {}
        for slot in result["slots"]:
            coach_id = slot.get("coachId")
            if not coach_id or slot.get("lockLevel") == "HARD":
                continue
            key = (coach_id, slot["dayOfWeek"], slot["startTime"], slot["venueId"])
            coach_bookings.setdefault(key, set()).add(slot["teamId"])

        # Deux gymnases DIFFÉRENTS au même instant : impossibilité physique.
        by_moment: dict[tuple[str, int, str], set[str]] = {}
        for (coach_id, day, start, venue), _team_ids in coach_bookings.items():
            by_moment.setdefault((coach_id, day, start), set()).add(venue)

        for moment, venues in by_moment.items():
            assert len(venues) <= 1, f"Coach dans {len(venues)} gymnases à la fois — {moment} : {sorted(venues)}"

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_age_order_per_venue_day(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        age_min_by_team = _team_age_min_by_id(data)
        hard_locked_team_ids = _hard_locked_team_ids(data)

        slots_by_group: dict[tuple[str, int], list[dict[str, Any]]] = {}
        for slot in result["slots"]:
            key = (slot["venueId"], slot["dayOfWeek"])
            slots_by_group.setdefault(key, []).append(slot)

        for key, slots in slots_by_group.items():
            for i, slot_a in enumerate(slots):
                age_a = age_min_by_team.get(slot_a["teamId"])
                if age_a is None or slot_a["teamId"] in hard_locked_team_ids:
                    continue
                for slot_b in slots[i + 1 :]:
                    age_b = age_min_by_team.get(slot_b["teamId"])
                    if age_b is None or slot_b["teamId"] in hard_locked_team_ids:
                        continue
                    if age_a < age_b:
                        assert slot_a["startTime"] <= slot_b["startTime"], (
                            f"Age order violated at {key}: {slot_a['teamId']} ({age_a}) at {slot_a['startTime']} "
                            f"must start at or before {slot_b['teamId']} ({age_b}) at {slot_b['startTime']}"
                        )
                    elif age_b < age_a:
                        assert slot_b["startTime"] <= slot_a["startTime"], (
                            f"Age order violated at {key}: {slot_b['teamId']} ({age_b}) at {slot_b['startTime']} "
                            f"must start at or before {slot_a['teamId']} ({age_a}) at {slot_a['startTime']}"
                        )

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_coach_consistency(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)
        if result["status"] != "completed":
            pytest.skip("Solver did not find a feasible solution")

        # Build map of expected coaches per (team, venue, day, time) from templates.
        expected_coaches: dict[tuple[str, str, int, str], str] = {}
        for tpl in data.get("slotTemplates", []):
            tid = tpl.get("teamId")
            cid = tpl.get("coachId")
            vid = tpl.get("venueId")
            dow = tpl.get("dayOfWeek")
            stime = tpl.get("startTime")
            if tid and cid and vid and dow is not None and stime:
                expected_coaches[(tid, vid, dow, stime)] = cid

        for slot in result["slots"]:
            tid = slot["teamId"]
            cid = slot.get("coachId")
            key = (tid, slot["venueId"], slot["dayOfWeek"], slot["startTime"])
            if key in expected_coaches and cid is not None:
                assert cid == expected_coaches[key], (
                    f"Slot for {tid} at {key} has coach {cid}, expected {expected_coaches[key]}"
                )

    @staticmethod
    def _five_weekday_workload(*, coach_rest_intensity: str | None) -> dict[str, Any]:
        """5 équipes, 1 séance chacune, chacune verrouillée sur SON jour (lun-ven) via
        ``allowedDays`` HARD, toutes encadrées par le MÊME coach — le placer toutes = coach
        présent les 5 jours. ``coach_rest_intensity`` règle la 3b (None = bloc absent = HARD)."""
        constraints: list[dict[str, Any]] = []
        for d in range(1, 6):
            constraints.append(team_coach(f"tc-{d}", f"team-{d}", "coach-1"))
            constraints.append(
                {
                    "id": f"day-{d}",
                    "scope": "TEAM",
                    "scopeTargetId": f"team-{d}",
                    "family": "DAY",
                    "ruleType": "HARD",
                    "name": "jour imposé",
                    "config": {"allowedDays": [d]},
                    "sortOrder": 0,
                    "isActive": True,
                }
            )
        payload = make_payload(
            teams=[
                {
                    "id": f"team-{d}",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": f"Team {d}",
                    "sessionsPerWeek": 1,
                }
                for d in range(1, 6)
            ],
            venues=[make_venue("venue-1", [(d, "18:00") for d in range(1, 6)], duration_minutes=60)],
            coaches=[{"id": "coach-1", "firstName": "Coach", "lastName": "One"}],
            constraints=constraints,
            timeout=10,
        )
        if coach_rest_intensity is not None:
            payload["implicitRules"] = {"coachRestDay": {"intensity": coach_rest_intensity}}
        return payload

    @staticmethod
    def _coach_weekdays(result: dict[str, Any], coach_id: str) -> set[int]:
        return {
            int(slot["dayOfWeek"])
            for slot in result["slots"]
            if slot.get("coachId") == coach_id
            and slot.get("dayOfWeek") is not None
            and 1 <= int(slot["dayOfWeek"]) <= 5
        }

    def test_coach_rest_day_hard_default_keeps_a_rest_day(self) -> None:
        """ADR-0001 : single-pass, aucun fallback. En HARD (défaut, bloc absent), le solveur
        garde le jour de repos plutôt que de le relaxer — le coach ne travaille jamais 5 jours."""
        result = solve_payload(self._five_weekday_workload(coach_rest_intensity=None))
        assert result["status"] == "completed"
        assert len(self._coach_weekdays(result, "coach-1")) <= 4, (
            "en HARD le coach doit garder un jour de repos (au plus 4 jours travaillés)"
        )

    def test_coach_rest_day_preferred_completes_with_warning(self) -> None:
        """En PREFERRED, la 3b n'est plus dure : le solveur place les 5 séances (coach présent
        les 5 jours) ET signale la règle non tenue via ``implicit_rule_not_honored``."""
        result = solve_payload(self._five_weekday_workload(coach_rest_intensity="PREFERRED"))
        assert result["status"] == "completed"
        assert self._coach_weekdays(result, "coach-1") == {1, 2, 3, 4, 5}, (
            "en PREFERRED le solveur doit pouvoir placer les 5 séances"
        )
        warnings_by_coach = {
            str(diag.get("coachId"))
            for diag in result["diagnostics"]
            if diag.get("type") == "implicit_rule_not_honored"
            and diag.get("ruleKey") == "coachRestDay"
            and diag.get("severity") == "WARNING"
        }
        assert "coach-1" in warnings_by_coach, (
            "coach-1 travaille les 5 jours en PREFERRED, un warning implicit_rule_not_honored doit sortir"
        )

    @settings(max_examples=20, deadline=None)
    @given(data=random_fixture())
    def test_hard_locked_slots_preserved(self, data: dict[str, Any]) -> None:
        result = solve_payload(data, timeout=5)

        hard_templates = [t for t in data.get("slotTemplates", []) if t.get("lockLevel") == "HARD"]
        hard_slots = [s for s in result["slots"] if s.get("lockLevel") == "HARD"]

        assert len(hard_slots) == len(hard_templates), (
            f"Expected {len(hard_templates)} HARD slots, found {len(hard_slots)}"
        )

        for tpl in hard_templates:
            found = any(
                s["teamId"] == tpl["teamId"]
                and s["venueId"] == tpl["venueId"]
                and s["dayOfWeek"] == tpl["dayOfWeek"]
                # L'API rend une heure CANONIQUE ("18:00:00") ; la fixture, comme le
                # backend, écrit "18:00". On compare donc sur HH:MM. Le harnais local
                # renvoyait la forme d'entrée telle quelle, ce qui masquait cet écart —
                # et faisait croire que les deux bouts parlaient le même format.
                and str(s["startTime"])[:5] == tpl["startTime"][:5]
                for s in hard_slots
            )
            assert found, f"HARD slot for team {tpl['teamId']} not preserved"

    def test_tier_s_wins_over_tier_d_in_direct_conflict(self) -> None:
        """When only one slot exists and both S and D teams want it, S must be placed."""
        data = {
            "clubId": "club-priority",
            "seasonId": "season-2024",
            "version": "2.0",
            "solverSeed": 42,
            "venues": [
                {
                    "id": "gym-a",
                    "name": "Gym A",
                    "trainingSlots": [{"dayOfWeek": 1, "startTime": "18:00", "durationMinutes": 15, "capacity": 1}],
                }
            ],
            "teams": [
                {
                    "id": "team-s",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 1,
                    "name": "Team S",
                    "sessionsPerWeek": 1,
                },
                {
                    "id": "team-d",
                    "sportCategoryId": "sc-1",
                    "priorityTierId": 5,
                    "name": "Team D",
                    "sessionsPerWeek": 0,
                },
            ],
            "coaches": [],
            "slotTemplates": [],
            "constraints": [],
        }
        result = solve_payload(data, timeout=5)

        assert result["status"] == "completed"
        placed_teams = {s["teamId"] for s in result["slots"]}
        assert "team-s" in placed_teams, "S-tier team must be placed in a direct conflict"
        assert "team-d" not in placed_teams, "D-tier team must be sacrificed in a direct conflict"
