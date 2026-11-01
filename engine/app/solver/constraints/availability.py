"""Disponibilité des coachs sur une case — maison UNIQUE de la question « un coach suffit » (Q4B).

« Cette case ``(jour, début)`` a-t-elle AU MOINS un coach requis disponible pour cet ENSEMBLE
d'équipes ? » Un seul coach disponible suffit à servir la case (arbitrage fondateur Q4B,
2026-10-10) : une équipe SEULE honore ses séances dès qu'UN de ses coachs requis est libre, et une
séance de BLOC dès qu'un coach de l'UNION de ses membres l'est. Les deux appelants — la fermeture
d'indisponibilité (``structural.closures.add_coach_unavailability_constraints``) et, plus tard, le
diagnostic de bloc (PR B) — passent par ICI, jamais par une seconde logique.

Deux rails, une seule maison. POSE LIBRE (``end`` donné) : le coach doit tenir toute la séance, un
intervalle ferme dès qu'il chevauche ``[début, fin[`` (demi-ouvert, sans marge). EXISTANT (``end=None``,
verrous, base figée) : la règle du DÉBUT, celle des miroirs PHP/TS, pour qu'un planning en vigueur ne
devienne ni infaisable ni accusé.

Base de la DAG du paquet : n'importe que ``..model`` (comme ``common``), aucune arête vers un
sous-module frère — closures/targeting importent CE module, jamais l'inverse."""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass
from typing import Any

from ..model import _time_to_minutes


def _coach_available_at(coach_id: str, day: int, start: int, blocked: Mapping[str, Any]) -> bool:
    """Un coach est DISPONIBLE à ``(jour, minute de début)`` quand AUCUN de ses intervalles
    d'indisponibilité ``(jour, de, à)`` ne couvre ce moment (``de <= début < à``, fenêtre fondée sur
    le DÉBUT, comme la pose historique et les fenêtres d'équipe)."""
    return not any(
        iv_day == day and iv_from <= start < iv_to for iv_day, iv_from, iv_to in (blocked.get(str(coach_id)) or ())
    )


def interval_bites(iv_from: int, iv_to: int, start: int, end: int | None = None) -> bool:
    """L'intervalle ``[de, à[`` ferme-t-il la séance ? ``end`` donné : il la chevauche (``de < fin et
    début < à``), une plage qui ne fait que la toucher ne ferme rien. ``end=None`` : il couvre son début."""
    return iv_from <= start < iv_to if end is None else iv_from < end and start < iv_to


def _coach_available_during(coach_id: str, day: int, start: int, end: int, blocked: Mapping[str, Any]) -> bool:
    """Un coach tient-il TOUTE la séance ``[début, fin[`` ? Aucun de ses intervalles ne la chevauche."""
    return not any(
        iv_day == day and interval_bites(iv_from, iv_to, start, end)
        for iv_day, iv_from, iv_to in (blocked.get(str(coach_id)) or ())
    )


def available_coach_exists(
    coach_ids: Iterable[str], day: int, start: int, coach_unavailability: Any, end: int | None = None
) -> bool:
    """AU MOINS un des coachs REQUIS donnés est-il disponible à ``(jour, début)`` ? ``end=None`` : au
    début (rail de l'existant). ``end`` donné : sur toute la séance (pose libre).

    Un ensemble VIDE de coachs ⇒ ``True`` : la case n'exige aucun coach, l'indisponibilité ne la
    ferme pas (patron historique « sans coach connu, aucune fermeture »). C'est le CŒUR de Q4B : on
    ne ferme que si TOUS les coachs requis sont bloqués (``not available_coach_exists``)."""
    rules: Mapping[str, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    ids = [str(c) for c in coach_ids]
    if not ids:
        return True
    if end is None:
        return any(_coach_available_at(cid, day, start, rules) for cid in ids)
    return any(_coach_available_during(cid, day, start, end, rules) for cid in ids)


def required_coaches_of(team_ids: Iterable[str], team_coach_map: Mapping[str, Any] | None) -> list[str]:
    """Union ORDONNÉE et dédupliquée des coachs requis (MAIN, non-ASSISTANT — ``team_coach_map``)
    des équipes données. Déterministe : l'ordre d'apparition est conservé (le solveur et les
    goldens restent reproductibles)."""
    coach_map = team_coach_map or {}
    union: list[str] = []
    seen: set[str] = set()
    for team_id in team_ids:
        for coach_id in coach_map.get(str(team_id), ()) or ():
            key = str(coach_id)
            if key not in seen:
                seen.add(key)
                union.append(key)
    return union


def case_has_available_coach(
    team_ids: Iterable[str],
    day: int,
    start: int,
    coach_unavailability: Any,
    team_coach_map: Mapping[str, Any] | None,
    end: int | None = None,
) -> bool:
    """LA question Q4B : la case ``(jour, début)`` a-t-elle au moins un coach requis disponible pour
    CET ENSEMBLE d'équipes ? On prend l'UNION des coachs requis des équipes puis on applique
    ``available_coach_exists``. Union vide ⇒ ``True`` (aucune exigence de coach sur cette case)."""
    return available_coach_exists(required_coaches_of(team_ids, team_coach_map), day, start, coach_unavailability, end)


@dataclass(frozen=True)
class HoldSession:
    """Une séance à tenir : assez pour dire si deux tenues d'un même coach sont incompatibles."""

    id: str
    team_id: str
    venue_id: str
    day: int
    start: int
    end: int


def holds_collide(a: HoldSession, b: HoldSession, *, same_team_collides: bool = False) -> bool:
    """Un même coach ne tient pas ces deux séances : elles se chevauchent dans deux gymnases (D-14,
    intervalles demi-ouverts). La même équipe deux fois est un autre sujet, sauf pour la pose, qui
    oppose ces deux variables comme n'importe quelles autres (``same_team_collides``)."""
    if a.day != b.day or a.venue_id == b.venue_id or not (a.start < b.end and b.start < a.end):
        return False
    return same_team_collides or a.team_id != b.team_id


def assign_holders(
    sessions: Sequence[HoldSession],
    candidates: Mapping[str, Sequence[str]],
    *,
    same_team_collides: bool = False,
) -> dict[str, str] | None:
    """Séance → coach qui la tient, ou ``None`` si au moins une séance n'a personne. Séances dans
    l'ordre donné, candidats dans l'ordre des listes, retour arrière : la PREMIÈRE solution, donc une
    réponse déterministe. Même algorithme que ``holdersFor`` (détecteur PHP et miroir TS)."""
    assigned: dict[str, str] = {}

    def _assign(group: Sequence[HoldSession], index: int) -> bool:
        if index == len(group):
            return True
        session = group[index]
        for coach_id in candidates.get(session.id, ()):
            if any(
                assigned.get(other.id) == coach_id
                and holds_collide(session, other, same_team_collides=same_team_collides)
                for other in group[:index]
            ):
                continue
            assigned[session.id] = coach_id
            if _assign(group, index + 1):
                return True
            del assigned[session.id]
        return False

    # Grappe par grappe : le retour arrière ne repart que dans les séances qui peuvent se gêner, et la
    # première solution reste la même que sur la liste entière.
    pending = list(sessions)
    while pending:
        group = [pending.pop(0)]
        for session in group:
            linked = [other for other in pending if holds_collide(session, other, same_team_collides=True)]
            group.extend(linked)
            pending = [other for other in pending if other not in linked]
        group.sort(key=sessions.index)
        if not _assign(group, 0):
            return None
    return assigned


def case_day_start(slot_id: str | None) -> tuple[int | None, int | None]:
    """``(jour, minute de début)`` d'une clé de case ``"jour:HH:MM"`` (ex. ``"3:18:00"`` → ``(3,
    1080)``), ou ``(None, None)`` si illisible. Même découpe que ``common._assignment_day_start``,
    exposée ici pour les cases de BLOC dont la clé porte le ``slot_id`` sans objet d'affectation."""
    if not isinstance(slot_id, str) or ":" not in slot_id:
        return None, None
    head, _, rest = slot_id.partition(":")
    try:
        day: int | None = int(head)
    except ValueError:
        day = None
    try:
        start: int | None = _time_to_minutes(rest)
    except ValueError, TypeError:
        start = None
    return day, start
