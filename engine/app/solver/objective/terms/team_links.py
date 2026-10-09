"""Level-2 objective — malus de chevauchement d'une passerelle PREFERRED (sous-module ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise``, ``constraints`` (géométrie des passerelles), ``normalise`` (tiers), ``weights`` et
``model`` (``_format_time``). Aucune arête vers un autre sous-module de ``terms`` ; l'agrégateur
``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ...compromise import FAMILY_TEAM_LINK, CompromiseTermInfo
from ...constraints import (
    MANDATORY,
    PREFERRED,
    iter_team_link_overlaps,
    team_link_placements_by_team,
    team_share_declared_pairs,
)
from ...model import _format_time
from ..normalise import _get, _higher_tier, _priority_tier_name, _teams_by_id
from ..weights import TEAM_LINK_TIER_WEIGHTS

AssignmentLike = Any
BoolVarLike = Any


def add_team_link_penalty(
    model: Any,
    assignments: Iterable[AssignmentLike] | Mapping[Any, BoolVarLike],
    *,
    team_links: Iterable[Any] = (),
    shared_blocks: Iterable[Any] = (),
    teams: Iterable[Any] = (),
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Lot PASSERELLES PR-2 — MALUS d'objectif par chevauchement d'une passerelle ``PREFERRED``.

    Pour chaque passerelle PREFERRED (deux équipes partageant des joueurs) et chaque paire de
    placements CHEVAUCHANTS non exemptée (``constraints.iter_team_link_overlaps`` — même géométrie
    et même exemption doctrinale que la pose HARD), un malus ``−TEAM_LINK_TIER_WEIGHTS[tier]`` est
    posé, ``tier`` = la PLUS HAUTE des deux équipes. Le maximiseur pousse alors les deux séances à
    ne PAS coïncider quand c'est possible, sans jamais SUPPRIMER une séance (preuve d'empilement
    sur ``TEAM_LINK_TIER_WEIGHTS``).

    Le littéral pénalisé « les deux séances sont posées » :
      * libre ⇔ libre : un ``ov`` réifié par ``ov >= var_a + var_b − 1`` (le maximiseur, malus
        négatif, le maintient à ``max(0, var_a+var_b−1)``) ;
      * libre ⇔ verrouillé : la séance verrouillée est TOUJOURS là, donc le littéral EST la
        variable libre — pénaliser ``var`` décourage de la poser en chevauchement ;
      * verrou ⇔ verrou : les deux constantes, aucune variable — rien à pénaliser (le
        chevauchement, s'il subsiste, est ANNONCÉ par ``result_builder._diagnose_team_links``).

    ``info_out`` (chemin ``/validate-assignments``) reçoit un ``CompromiseTermInfo`` par littéral
    (MALUS : ``honored_when_active=False``) pour que le rail des compromis (P2-32) NOMME le
    chevauchement créé par un déplacement accepté (arbitrage n°4). ``team_links`` vide/tout
    MANDATORY ⇒ ``[]`` (chemin byte-identique, goldens inchangés)."""
    preferred = [link for link in (team_links or ()) if str(_get(link, "intensity", default=PREFERRED)) != MANDATORY]
    if not preferred:
        return []

    placements = team_link_placements_by_team(assignments, getattr(model, "locked_slots", ()) or ())
    share_pairs = team_share_declared_pairs(shared_blocks)
    teams_by_id = _teams_by_id(teams)

    def _tier_of(team_id: str) -> str:
        return _priority_tier_name({"team_id": team_id}, teams_by_id)

    terms: list[tuple[BoolVarLike, int]] = []
    for link in preferred:
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        link_id = str(_get(link, "id", default=f"{team_a}_{team_b}"))
        share_declared = frozenset({team_a, team_b}) in share_pairs
        try:
            weight = TEAM_LINK_TIER_WEIGHTS.get(_higher_tier(_tier_of(team_a), _tier_of(team_b)), 0)
        except ValueError:
            # Une équipe sans tier exploitable : on ne fabrique pas de poids, on n'oriente pas.
            continue
        if weight == 0:
            continue

        pair_index = 0
        for (a_start, _a_end, a_day, a_venue, a_var), (_bs, _be, _bd, _bv, b_var) in iter_team_link_overlaps(
            placements.get(team_a, []), placements.get(team_b, []), share_declared=share_declared
        ):
            if a_var is not None and b_var is not None:
                overlap = model.NewBoolVar(f"team_link_{link_id}_{pair_index}".replace(":", "_"))
                model.Add(overlap >= a_var + b_var - 1)
                penalized: BoolVarLike = overlap
            elif a_var is not None:  # b verrouillé, toujours présent → chevauchement ssi a posée.
                penalized = a_var
            elif b_var is not None:
                penalized = b_var
            else:
                continue  # deux verrous : constante, rien à orienter (diagnostiqué post-solve).
            pair_index += 1
            terms.append((penalized, -int(weight)))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=penalized,
                        family=FAMILY_TEAM_LINK,
                        honored_when_active=False,
                        key=(FAMILY_TEAM_LINK, link_id),
                        team_id=team_a,
                        venue_id=a_venue,
                        day_of_week=a_day,
                        start_time=_format_time(a_start),
                    )
                )
    return terms
