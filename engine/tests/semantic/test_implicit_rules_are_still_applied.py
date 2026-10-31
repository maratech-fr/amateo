"""NR — P4-55 / lot « règles implicites réglables ». L'écran ANNONCE six règles
structurelles : elles doivent rester appliquées. Les 4 règles « bien-être » réglables
(repos coach, distribution salariés, dos-à-dos, âge) restent APPELÉES quel que soit le
réglage — le cran décide seulement HARD vs PREFERRED, jamais le silence.

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
