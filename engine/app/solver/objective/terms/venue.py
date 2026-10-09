"""Level-2 objective — bonus/malus « gymnase préféré / à éviter » (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``compromise`` (métadonnée de nommage des compromis). Aucune arête vers un autre sous-module de
``terms`` ; l'agrégateur ``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from ...compromise import FAMILY_VENUE, CompromiseTermInfo

BoolVarLike = Any


def add_venue_preference_bonus(
    x: Mapping[Any, BoolVarLike],
    parsed: Mapping[str, Any],
    *,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, str]]:
    """Termes soft « gymnase préféré / à éviter » — maison unique génération ⇄ évaluation (D-6).

    Extrait tel quel de l'assemblage inline de ``main.build_schedule`` : le bonus ``preferred``
    tombe sur tout créneau d'un gymnase préféré de l'équipe, le MALUS ``avoided_venue`` sur tout
    créneau d'un gymnase à éviter (un vrai malus, pas un bonus-complément qui biaiserait
    l'allocation inter-équipes). ``info_out`` (défaut None → chemin /generate byte-identique)
    récolte la métadonnée de nommage des compromis.
    """
    preferred_venues: dict[str, set[str]] = parsed.get("preferred_venues", {}) or {}
    avoided_by_team: dict[str, set[str]] = {}
    for avoided in parsed.get("avoided_venues", []) or []:
        avoided_by_team.setdefault(avoided["scope_target_id"], set()).add(avoided["venue_id"])

    soft_terms: list[tuple[BoolVarLike, str]] = []
    for slot_key, var in x.items():
        team_id = str(slot_key[0])
        venue_id = str(slot_key[1])
        preferred_set = preferred_venues.get(team_id)
        if preferred_set is not None and venue_id in preferred_set:
            soft_terms.append((var, "preferred"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=True,
                        key=(FAMILY_VENUE, team_id, venue_id, "preferred"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="preferred",
                    )
                )
        avoided_set = avoided_by_team.get(team_id)
        if avoided_set is not None and venue_id in avoided_set:
            soft_terms.append((var, "avoided_venue"))
            if info_out is not None:
                info_out.append(
                    CompromiseTermInfo(
                        var=var,
                        family=FAMILY_VENUE,
                        honored_when_active=False,
                        key=(FAMILY_VENUE, team_id, venue_id, "avoided"),
                        team_id=team_id,
                        venue_id=venue_id,
                        detail="avoided",
                    )
                )

    return soft_terms
