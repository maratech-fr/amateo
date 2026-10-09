"""Level-2 objective — stabilité P3-21 et référence socle (sous-module du paquet ENG-39).

Extrait verbatim de l'ancien ``objective/terms.py`` (découpe pure, P4-295 C4). Dépend de
``weights`` (barèmes), ``normalise`` (lecture des assignments) et du module solveur ``model``.
Aucune arête vers un autre sous-module de ``terms`` ; l'agrégateur ``objective/__init__`` consomme."""

from __future__ import annotations

from collections.abc import Iterable, Mapping
from typing import Any

from ...model import _format_time, _time_to_minutes
from ..normalise import _get, _priority_tier_name, _scalar_id, _teams_by_id
from ..weights import SOCLE_REFERENCE_TIER_WEIGHTS, STABILITY_TERM_WEIGHT

BoolVarLike = Any


def build_stability_terms(
    x: Mapping[Any, BoolVarLike],
    previous_assignments: Iterable[Mapping[str, Any]] | None,
) -> list[tuple[BoolVarLike, int]]:
    """P3-21 — termes de stabilité : +STABILITY_TERM_WEIGHT par variable dont la clé
    ``(team_id, venue_id, day_of_week, start)`` figure dans ``previous_assignments``. P2-61 :
    l'appelant DÉRIVE de ces mêmes termes (même clé) la PROXIMITÉ de PLACEMENT (phase 1, poids 9,
    ``PLACEMENT_PROXIMITY_WEIGHT``) ; la stabilité renvoyée ici reste, elle, réservée à la SOUS-BANDE
    de PHASE 2 (placement déjà verrouillé → départage des ex æquo exacts, jamais un arbitrage).

    La clé est normalisée EXACTEMENT comme ``model.x`` (start passé par
    ``_format_time(_time_to_minutes(...))``). Un créneau HARD est absent de ``x`` (pas de
    variable) → jamais compté : pas de double paiement d'un pin. Dédup par clé. Champ
    vide/None → ``[]`` (aucun terme) : l'appelant n'installe alors NI proximité (phase 1) NI
    stabilité (sous-bande phase 2) — chemin byte-identique."""
    terms: list[tuple[BoolVarLike, int]] = []
    if not previous_assignments:
        return terms

    seen: set[tuple[str, str, int, str]] = set()
    for prev in previous_assignments:
        team_id = _scalar_id(_get(prev, "teamId", "team_id", default=None))
        venue_id = _scalar_id(_get(prev, "venueId", "venue_id", default=None))
        day = _get(prev, "dayOfWeek", "day_of_week", default=None)
        start = _get(prev, "startTime", "start_time", default=None)
        if team_id is None or venue_id is None or day is None or start is None:
            continue
        try:
            slot_key = (str(team_id), str(venue_id), int(_scalar_id(day)), _format_time(_time_to_minutes(start)))
        except (TypeError, ValueError):
            continue
        if slot_key in seen:
            continue
        seen.add(slot_key)
        var = x.get(slot_key)
        if var is not None:
            terms.append((var, STABILITY_TERM_WEIGHT))

    return terms


def add_socle_reference_bonus(
    x: Mapping[Any, BoolVarLike],
    socle_reference_assignments: Iterable[Mapping[str, Any]] | None,
    teams: Iterable[Any] = (),
) -> list[tuple[BoolVarLike, int]]:
    """PR-3 (comblement) — BONUS de référence socle : ``+SOCLE_REFERENCE_TIER_WEIGHTS[tier]`` par
    variable dont la clé ``(team_id, day_of_week, start)`` — GYMNASE IGNORÉ — figure dans
    ``socle_reference_assignments`` (les placements de la version pointée du socle).

    À la différence de ``build_stability_terms`` (qui matche AUSSI le ``venue_id``, phase 2), ce
    terme matche ``(team, day, start)`` SANS le gymnase : « garder le jour+heure de socle, quel
    que soit le gymnase ». Les poids sont PAR TIER (S>A>B>C>D) et entrent dans le PLACEMENT
    (phase 1, via ``extra_placement_terms``) — l'équipe fanion tient plus fort son horaire.

    La clé est normalisée EXACTEMENT comme ``model.x`` (day int, start passé par
    ``_format_time(_time_to_minutes(...))``). Un créneau HARD est absent de ``x`` (pas de variable)
    → naturellement ignoré : la référence n'oriente que des séances réellement à placer (les
    trous du comblement). Dédup par clé de référence. Une équipe sans tier exploitable est sautée
    (on ne fabrique pas de poids). Champ vide/None → ``[]`` : chemin byte-identique (le backend ne
    l'émet QU'en comblement — en génération pleine il est absent)."""
    terms: list[tuple[BoolVarLike, int]] = []
    if not socle_reference_assignments:
        return terms

    teams_by_id = _teams_by_id(teams)
    weight_by_ref: dict[tuple[str, int, str], int] = {}
    for ref in socle_reference_assignments:
        team_id = _scalar_id(_get(ref, "teamId", "team_id", default=None))
        day = _get(ref, "dayOfWeek", "day_of_week", default=None)
        start = _get(ref, "startTime", "start_time", default=None)
        if team_id is None or day is None or start is None:
            continue
        try:
            ref_key = (str(team_id), int(_scalar_id(day)), _format_time(_time_to_minutes(start)))
        except (TypeError, ValueError):
            continue
        if ref_key in weight_by_ref:
            continue
        try:
            tier = _priority_tier_name({"team_id": str(team_id)}, teams_by_id)
        except ValueError:
            continue  # équipe sans tier exploitable : on n'oriente pas
        weight = SOCLE_REFERENCE_TIER_WEIGHTS.get(tier, 0)
        if weight:
            weight_by_ref[ref_key] = int(weight)

    if not weight_by_ref:
        return terms

    for slot_key, var in x.items():
        # slot_key = (team_id, venue_id, day, start) ; le GYMNASE (slot_key[1]) est ignoré.
        matched = weight_by_ref.get((str(slot_key[0]), int(slot_key[2]), str(slot_key[3])))
        if matched is not None:
            terms.append((var, matched))

    return terms
