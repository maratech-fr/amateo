"""NR — P4-55 / lot « règles implicites réglables ». L'écran ANNONCE six règles
structurelles : elles doivent rester appliquées. Les 4 règles « bien-être » réglables
(repos coach, distribution salariés, dos-à-dos, âge) restent APPELÉES quel que soit le
réglage. Le silence ne s'obtient jamais par l'absence (bloc absent = HARD) : seulement par un
``OFF`` explicite, le cran « Ignorée » choisi par le gestionnaire (PR 1b, contrat 1.11), qui ne pose
alors ni contrainte ni terme.

Le wizard porte un encart « ce que le système applique déjà, sans que vous le saisissiez »
(`frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx`). Il affirme six comportements
du solveur que le gestionnaire ne saisit nulle part.

⚠ **Le risque couvert est celui d'une AFFIRMATION devenue fausse**, pas d'un solveur cassé.
Si l'une de ces règles disparaît du moteur — retirée, ou passée derrière une condition — rien
ne rougirait côté frontend : son gel Vitest continuerait de figer un texte que plus personne
n'honore. C'est le motif « contrainte saisie ≠ contrainte honorée » (ENG-10 à ENG-13), ici
retourné du côté de l'implicite.

On vérifie donc que `add_level_1_hard_constraints` appelle les six, **sans condition**, dans
son appel par défaut, et que le repos coach est **appelé selon le réglage** : HARD par défaut
(bloc absent), PREFERRED quand le gestionnaire l'a explicitement demandé. Le mock est
délibéré : la sémantique de chaque règle a ses propres tests (`test_coach_rest_day.py`, la
matrice sémantique, `test_implicit_rules_adjustable.py`…). Ce qu'AUCUN d'eux ne garde, c'est
que la règle soit toujours BRANCHÉE — une fonction parfaitement testée mais plus appelée passe
tous les tests du monde.

⚠ Les trois règles délibérément TUES par l'encart (`age_ascending`, `salarie_distribution`,
`max_consecutive_sessions`) ne sont pas listées dans `ANNOUNCED_BY_THE_UI` : ce bloc garde
l'accord entre l'écran et le moteur, pas l'inventaire du moteur.
"""

from __future__ import annotations

from typing import Any
from unittest.mock import DEFAULT, patch

import pytest

from app.solver import constraints as C
from app.solver.constraints import ResolvedImplicitRules, resolve_implicit_rules
from app.solver.model import build_model
from tests.support.pipeline import make_payload, make_team, make_venue

# Les six fonctions correspondant, dans l'ordre, aux six entrées de `IMPLICIT_RULES`.
# Le libellé est celui de l'encart : il rend la panne lisible sans ouvrir le frontend.
ANNOUNCED_BY_THE_UI = [
    ("add_room_at_most_one", "Un gymnase ne dépasse jamais sa capacité"),
    ("add_coach_at_most_one", "Un coach n'est jamais dans deux gymnases à la fois"),
    ("add_coach_player_non_overlap", "Une personne ne peut pas encadrer et jouer en même temps"),
    ("add_team_no_overlap", "Une équipe n'a jamais deux séances en même temps"),
    ("add_one_session_per_day_constraints", "Au plus une séance par jour et par équipe"),
    ("add_coach_rest_day_constraints", "Chaque coach garde un jour de repos"),
]


@pytest.mark.parametrize(("function_name", "ui_label"), ANNOUNCED_BY_THE_UI)
def test_the_rule_the_ui_announces_is_still_wired(function_name: str, ui_label: str) -> None:
    with patch.object(C, function_name) as spied:
        C.add_level_1_hard_constraints(object(), assignments=[])

    assert spied.called, (
        f"`{function_name}` n'est plus appelée par add_level_1_hard_constraints, "
        f"alors que le wizard affiche toujours « {ui_label} ». "
        "Soit on la remet, soit on retire la ligne de IMPLICIT_RULES "
        "(frontend/src/features/wizard/steps/ImplicitRulesPanel.tsx) — jamais le silence."
    )


def test_the_six_are_wired_together_not_one_by_one() -> None:
    """Le filet d'ensemble : un seul appel par défaut doit les déclencher TOUTES.

    Les cas paramétrés ci-dessus mockent une fonction à la fois. Si quelqu'un rendait
    l'appel de l'une conditionnel à l'absence d'une autre, chaque cas isolé resterait vert.
    """
    patches: dict[str, Any] = {}
    with patch.multiple(C, **{name: DEFAULT for name, _ in ANNOUNCED_BY_THE_UI}) as mocks:
        patches = mocks
        C.add_level_1_hard_constraints(object(), assignments=[])

    silent = sorted(name for name, _ in ANNOUNCED_BY_THE_UI if not patches[name].called)
    assert silent == [], f"ces règles annoncées par le wizard n'ont pas été appliquées : {silent}"


def test_default_resolution_is_all_hard() -> None:
    """Absence totale du bloc `implicitRules` = défauts partout : tout HARD, seuils historiques.

    C'est ce qui garantit qu'un payload sans le bloc est byte-identique à l'ancien contrat.
    """
    default = resolve_implicit_rules(None)
    assert default == ResolvedImplicitRules()
    assert default.coach_rest_day_intensity == "HARD"
    assert default.salarie_distribution_intensity == "HARD"
    assert default.max_consecutive_sessions_intensity == "HARD"
    assert default.age_ascending_intensity == "HARD"
    assert default.min_rest_days == 1
    assert default.max_consecutive == 3


def test_the_four_adjustable_rules_are_called_according_to_the_setting() -> None:
    """Les 4 règles réglables restent APPELÉES quel que soit le cran — HARD par défaut,
    PREFERRED quand demandé. On ne les DÉBRANCHE jamais : PREFERRED n'est pas une relaxation
    de secours (ADR-0001, single-pass sans fallback) mais un réglage explicite du gestionnaire.

    Ce test prouve le BRANCHEMENT, pas l'effet : pour la présence d'un salarié, le cran reçu est
    sans effet (objectif aux deux crans, ``test_salarie_rule_is_an_objective.py``).
    """
    adjustable = {
        "add_coach_rest_day_constraints": "coach_rest_day_intensity",
        "add_salarie_distribution_constraints": "salarie_distribution_intensity",
        "add_max_consecutive_sessions_constraints": "max_consecutive_sessions_intensity",
        "add_age_ascending_constraints": "age_ascending_intensity",
    }

    # Défaut (bloc absent) : chaque règle est appelée avec intensity="HARD".
    for function_name in adjustable:
        with patch.object(C, function_name) as spied:
            C.add_level_1_hard_constraints(object(), assignments=[])
        assert spied.called, f"`{function_name}` n'est plus appelée par défaut"
        assert spied.call_args.kwargs.get("intensity") == "HARD", (
            f"`{function_name}` doit être appelée en HARD par défaut (bloc absent), "
            f"pas {spied.call_args.kwargs.get('intensity')!r}"
        )

    # Réglage PREFERRED : la MÊME fonction est toujours appelée, avec intensity="PREFERRED".
    preferred_block = {
        "coachRestDay": {"intensity": "PREFERRED"},
        "salarieDistribution": {"intensity": "PREFERRED"},
        "maxConsecutiveSessions": {"intensity": "PREFERRED"},
        "ageAscending": {"intensity": "PREFERRED"},
    }
    resolved = resolve_implicit_rules(preferred_block)
    for function_name in adjustable:
        with patch.object(C, function_name) as spied:
            C.add_level_1_hard_constraints(object(), assignments=[], implicit_rules=resolved)
        assert spied.called, f"`{function_name}` doit rester appelée même en PREFERRED"
        assert spied.call_args.kwargs.get("intensity") == "PREFERRED", (
            f"`{function_name}` doit passer intensity='PREFERRED' quand le gestionnaire l'a réglé"
        )


# ===========================================================================
# PR 1b — « Ignorée » (OFF) : ni contrainte, ni terme, règle par règle
# ===========================================================================

# (clé du contrat, compteur de pose dans ``HardConstraintStats``, sous-genre du terme d'objectif)
WELLNESS_RULES = [
    ("coachRestDay", "coach_rest_day", "coach_rest"),
    ("salarieDistribution", "salarie_distribution", "salarie"),
    ("maxConsecutiveSessions", "max_consecutive_sessions", "chain"),
    ("ageAscending", "age_ascending", "age"),
]


def _pose(rule_key: str, intensity: str | None) -> C.HardConstraintStats:
    """Pose le niveau 1 sur un décor où les quatre règles ont matière à mordre : un coach salarié (sur
    deux au club) encadre trois équipes d'âges différents, trois créneaux dos-à-dos chaque soir."""
    payload = make_payload(
        teams=[{**make_team(f"t-{age}"), "ageMin": age} for age in (10, 13, 16)],
        venues=[make_venue("v", [(day, start) for day in range(1, 6) for start in ("17:00", "18:30", "20:00")])],
        coaches=[{"id": cid, "firstName": cid, "lastName": "X", "isEmployee": True} for cid in ("c1", "c2")],
    )
    model = build_model(payload)
    # Même forme que l'appel du pipeline (``main.py``) : horaires en minutes, coach principal.
    assignments = []
    for (team_id, venue_id, day, start), var in model.x.items():
        hours, minutes = (int(part) for part in str(start).split(":"))
        assignments.append(
            {
                "var": var,
                "team_id": team_id,
                "venue_id": venue_id,
                "slot_id": f"{day}:{start}",
                "start": hours * 60 + minutes,
                "end": hours * 60 + minutes + 90,
                "coach_id": "c1",
            }
        )
    block = {} if intensity is None else {rule_key: {"intensity": intensity}}
    return C.add_level_1_hard_constraints(
        model,
        assignments,
        teams=payload["teams"],
        coaches=payload["coaches"],
        implicit_rules=resolve_implicit_rules(block),
        team_coach_map={team["id"]: ["c1"] for team in payload["teams"]},
    )


def _terms_of(stats: C.HardConstraintStats, detail: str) -> list[Any]:
    return [info for info in stats.implicit_soft_info if info.detail == detail]


@pytest.mark.parametrize(("rule_key", "counter", "detail"), WELLNESS_RULES)
def test_an_ignored_rule_poses_neither_a_constraint_nor_a_term(rule_key: str, counter: str, detail: str) -> None:
    """Une règle réglée sur « Ignorée » ne pose rien : ni borne dure, ni terme d'objectif. Témoin : le
    même décor, la règle au défaut (bloc absent = HARD), pose bien quelque chose."""
    default = _pose(rule_key, None)
    assert getattr(default, counter) > 0 or _terms_of(default, detail), (
        f"témoin creux : {rule_key} ne pose rien au défaut"
    )

    ignored = _pose(rule_key, "OFF")
    assert getattr(ignored, counter) == 0, f"{rule_key} ignorée pose encore {getattr(ignored, counter)} contrainte(s)"
    assert _terms_of(ignored, detail) == [], f"{rule_key} ignorée pose encore un terme d'objectif"


def test_the_absent_block_still_resolves_to_hard_and_off_only_when_written() -> None:
    """« OFF » écrit éteint la règle ; l'absence vaut toujours Obligatoire (défaut HARD inchangé)."""
    written = resolve_implicit_rules({key: {"intensity": "OFF"} for key, _c, _d in WELLNESS_RULES})
    assert written.coach_rest_day_intensity == "OFF"
    assert written.salarie_distribution_intensity == "OFF"
    assert written.max_consecutive_sessions_intensity == "OFF"
    assert written.age_ascending_intensity == "OFF"
    assert resolve_implicit_rules({}) == ResolvedImplicitRules()
