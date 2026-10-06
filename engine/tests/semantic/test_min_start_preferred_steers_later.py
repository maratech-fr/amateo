"""NR — `minStartTime` PREFERRED oriente RÉELLEMENT vers plus tard (axe §7.1 constraint semantics).

Finding ALIGN-14 : une clé honorée en obligatoire pouvait n'être qu'un placebo muet en PREFERRED.
Le chemin soft existe (`add_preferred_time_bonus` lit `minStartTime` comme borne basse, poids
`preferred_time` = 5), mais la cellule générée `TIME-PREFERRED-minStartTime-TEAM`
(`test_constraint_matrix`) le prouve MAL : sur une grille d'heures le solveur place spontanément
TARD (mesuré), or `minStartTime` récompense justement le tard — le scénario passerait au vert sans
qu'aucune préférence n'ait rien orienté (« prouve la clé, pas la cellule »).

Ce test fournit le TÉMOIN qui manque. On penche d'abord le choix spontané vers le TÔT par un levier
d'un cran plus FAIBLE que `preferred_time` (5) : le bonus implicite de repos
(`add_match_day_rest_bonus`, poids 3), en faisant jouer l'équipe le lundi (son jour de repos est
alors le mardi). Grille : lundi 17:00 / mardi 20:00. Sans la règle, le solveur fuit le mardi et
prend 17:00 (mesuré le 2026-10-06, stable sur tous les seeds) ; « au plus tôt 19:00 » (poids 5 > 3)
renverse vers 20:00, l'heure que le solveur ne prend pas seul. Et une préférence ne bloque jamais :
seule l'option tôt disponible, l'équipe reste placée.
"""

from __future__ import annotations

from typing import Any

from tests.support import make_payload, make_team, make_venue, solve_payload, team_constraint

VENUE = "venue-rest"
TEAM = "t"
EARLY = "17:00"  # lundi, sous le plancher 19:00
LATE = "20:00"  # mardi, au-dessus du plancher


def _venues() -> list[dict[str, Any]]:
    # Lundi 17:00 (tôt) et mardi 20:00 (tard), capacité 1 chacun.
    return [make_venue(VENUE, [(1, EARLY), (2, LATE)])]


def _team() -> list[dict[str, Any]]:
    # L'équipe joue le lundi → son jour de repos est le mardi (matchDay % 7 + 1), ce qui
    # pénalise (poids `rest` 3) le créneau de 20:00 et rend le créneau de 17:00 spontané.
    return [make_team(TEAM, match_day=1)]


def _min_start_pref() -> dict[str, Any]:
    return team_constraint(
        constraint_id="min-start-19",
        team_id=TEAM,
        family="TIME",
        rule_type="PREFERRED",
        config={"minStartTime": "19:00"},
    )


def _placed_start(result: dict[str, Any]) -> str:
    starts = [str(s["startTime"])[:5] for s in result["slots"] if s["teamId"] == TEAM]
    assert len(starts) == 1, f"attendu exactement une séance, obtenu {starts}"
    return starts[0]


def test_witness_without_the_rule_the_solver_places_early() -> None:
    """TÉMOIN — sans `minStartTime`, le repos du mardi fait choisir le créneau du lundi 17:00.

    C'est ce que la cellule générée NE vérifie pas : la grille doit être spontanément TÔT, sinon
    « orienter vers le tard » ne prouve rien (le solveur y allait déjà seul)."""
    result = solve_payload(make_payload(teams=_team(), venues=_venues()), timeout=15)

    assert result["status"] == "completed"
    assert _placed_start(result) == EARLY, "la grille doit être spontanément tôt pour que la preuve tienne"


def test_min_start_preferred_steers_to_the_later_slot() -> None:
    """La préférence « au plus tôt 19:00 » (poids 5 > repos 3) renverse le choix vers 20:00."""
    result = solve_payload(
        make_payload(teams=_team(), venues=_venues(), constraints=[_min_start_pref()]),
        timeout=15,
    )

    assert result["status"] == "completed"
    assert _placed_start(result) == LATE, "minStartTime PREFERRED doit pencher vers le créneau ≥ 19:00"


def test_min_start_preferred_never_blocks_feasibility() -> None:
    """Une préférence n'interdit rien : seule l'option TÔT (sous le plancher) disponible, l'équipe
    reste placée — l'assertion qui attraperait une escalade en dur."""
    venues = [make_venue(VENUE, [(1, EARLY)])]
    result = solve_payload(
        make_payload(teams=_team(), venues=venues, constraints=[_min_start_pref()]),
        timeout=15,
    )

    assert result["status"] == "completed"
    placed = [s for s in result["slots"] if s["teamId"] == TEAM]
    assert placed, "une préférence horaire ne doit jamais empêcher le placement"
