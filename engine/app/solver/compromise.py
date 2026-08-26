"""Compromis nommés (P2-32 PR A) — le DELTA de confort d'un déplacement ACCEPTÉ.

Un « compromis » est un terme SOFT de l'objectif T24 (une préférence de confort ou une
règle de bien-être réglée PREFERRED). Quand le moteur ACCEPTE un candidat, on veut dire au
gestionnaire ce que ce déplacement change en confort : quelle préférence il CASSE (``broken``)
et laquelle il GAGNE (``gained``). Le verdict booléen ne bouge pas — ceci n'est qu'une lecture
post-acceptation, calculée en comparant deux états FIGÉS (« avant » et « après ») évalués par
le solveur avec le MÊME objectif que ``/generate``.

Ce module ne touche NI le modèle NI le solveur : il ne fait que
  1. porter la métadonnée d'un terme soft (``CompromiseTermInfo``), remplie par les builders
     d'objectif/contraintes quand — et seulement quand — un ``info_out`` leur est passé ;
  2. calculer le delta entre deux relevés (``compute_compromises``) et NOMMER chaque écart en
     français, sans le moindre identifiant interne.

Familles v1 (liste FERMÉE — étendre = décision fondateur). ``missing_session``, les tiers et
tout poids en sont volontairement EXCLUS.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

FAMILY_CHAINING = "chaining"
FAMILY_VENUE = "venue_preference"
FAMILY_DAY = "day_preference"
FAMILY_TIME = "time_preference"
FAMILY_MATCH_REST = "match_rest"
FAMILY_SPACING = "spacing"
FAMILY_COACH_DAY_CAP = "coach_day_cap"
FAMILY_IMPLICIT = "implicit_rule"
# Lot PASSERELLES PR-2 — le chevauchement d'une passerelle PREFERRED créé par un déplacement
# accepté (arbitrage n°4). Extension de la liste FERMÉE décidée par le fondateur pour ce lot.
FAMILY_TEAM_LINK = "team_link"
# P2-53 RMM-8 PR-2 — un BATTEMENT de trajet trop court concédé (règle `travelTime` PREFERRED) :
# deux séances enchaînées à des gymnases différents dont l'écart est plus court que le barème du
# coach. Extension de la liste FERMÉE décidée par le fondateur pour ce lot.
FAMILY_TRAVEL = "travel_time"

COMPROMISE_FAMILIES = frozenset(
    {
        FAMILY_CHAINING,
        FAMILY_VENUE,
        FAMILY_DAY,
        FAMILY_TIME,
        FAMILY_MATCH_REST,
        FAMILY_SPACING,
        FAMILY_COACH_DAY_CAP,
        FAMILY_IMPLICIT,
        FAMILY_TEAM_LINK,
        FAMILY_TRAVEL,
    }
)

_DAY_NAMES = {
    1: "lundi",
    2: "mardi",
    3: "mercredi",
    4: "jeudi",
    5: "vendredi",
    6: "samedi",
    7: "dimanche",
}


@dataclass
class CompromiseTermInfo:
    """Métadonnée d'UN terme soft, posée par le builder qui l'émet.

    ``var`` est la BoolVar CP-SAT du terme ; ``value`` est remplie par l'appelant après le
    solve (``solver.Value(var)``). ``honored_when_active`` dit dans quel sens lire la var :
    True pour un BONUS (var active = préférence honorée), False pour un MALUS (var active =
    règle violée). ``key`` est la clé LOGIQUE, identique entre l'état « avant » et « après »
    (le modèle a la même structure), qui apparie les termes des deux relevés.
    """

    var: Any
    family: str
    honored_when_active: bool
    key: tuple[Any, ...]
    team_id: str | None = None
    coach_id: str | None = None
    venue_id: str | None = None
    day_of_week: int | None = None
    start_time: str | None = None
    detail: str | None = None
    value: int = field(default=0)

    def honored(self) -> bool:
        """Le confort est-il dans son BON état ? (bonus honoré / malus non déclenché)."""
        return (self.value == 1) == self.honored_when_active


def _day_name(day: int | None) -> str:
    return _DAY_NAMES.get(day, "") if day is not None else ""


def _message(family: str, effect: str, rep: CompromiseTermInfo, names: dict[str, dict[str, str]]) -> str:
    """La phrase française prête à afficher — AUCUN identifiant interne, jamais.

    Les noms viennent des tables d'entités du club (équipes/coachs/gymnases) : ce sont SES
    propres libellés, pas des identifiants moteur.
    """
    # Lookup manqué → libellé générique, JAMAIS l'id brut (un person_id de coach-joueur
    # peut manquer à la table des coachs — revue sécurité P2-32).
    team = names["teams"].get(rep.team_id or "", "une équipe")
    coach = names["coaches"].get(rep.coach_id or "", "un coach")
    venue = names["venues"].get(rep.venue_id or "", "un gymnase")
    day = _day_name(rep.day_of_week)
    broken = effect == "broken"

    if family == FAMILY_VENUE:
        if rep.detail == "avoided":
            return (
                f"{team} se retrouve dans le gymnase à éviter ({venue})."
                if broken
                else f"{team} n'est plus dans le gymnase à éviter ({venue})."
            )
        return (
            f"{team} ne s'entraîne plus dans son gymnase préféré ({venue})."
            if broken
            else f"{team} s'entraîne désormais dans son gymnase préféré ({venue})."
        )
    if family == FAMILY_DAY:
        return (
            f"{team} ne s'entraîne plus un jour qui lui convient."
            if broken
            else f"{team} s'entraîne désormais un jour qui lui convient."
        )
    if family == FAMILY_TIME:
        return (
            f"{team} ne s'entraîne plus à un horaire qui lui convient."
            if broken
            else f"{team} s'entraîne désormais à un horaire qui lui convient."
        )
    if family == FAMILY_MATCH_REST:
        return (
            f"{team} n'a plus son jour de repos après match."
            if broken
            else f"{team} retrouve son jour de repos après match."
        )
    if family == FAMILY_SPACING:
        return (
            f"{team} s'entraîne désormais deux jours de suite."
            if broken
            else f"{team} ne s'entraîne plus deux jours de suite."
        )
    if family == FAMILY_COACH_DAY_CAP:
        return (
            f"{coach} dépasse désormais son plafond de jours d'entraînement."
            if broken
            else f"{coach} ne dépasse plus son plafond de jours d'entraînement."
        )
    if family == FAMILY_TEAM_LINK:
        return (
            f"{team} chevauche désormais une équipe avec laquelle elle partage des joueurs."
            if broken
            else f"{team} ne chevauche plus l'équipe avec laquelle elle partage des joueurs."
        )
    if family == FAMILY_TRAVEL:
        return (
            f"{coach} n'a désormais plus le temps de rejoindre son gymnase suivant."
            if broken
            else f"{coach} a de nouveau le temps de rejoindre son gymnase suivant."
        )
    if family == FAMILY_CHAINING:
        where = f"au {venue}" if venue else ""
        when = f"le {day}" if day else ""
        tail = " ".join(part for part in (where, when) if part).strip()
        return (
            f"Deux séances qui s'enchaînaient {tail} ne s'enchaînent plus.".replace("  ", " ")
            if broken
            else f"Deux séances s'enchaînent désormais {tail}.".replace("  ", " ")
        )
    # FAMILY_IMPLICIT — sous-genre porté par ``detail`` (jamais exposé tel quel).
    if rep.detail == "salarie":
        return (
            f"Le {day}, plus aucun entraîneur salarié n'est présent."
            if broken
            else f"Le {day}, un entraîneur salarié est de nouveau présent."
        )
    if rep.detail == "chain":
        return (
            f"{coach} enchaîne désormais trop de séances le {day}."
            if broken
            else f"{coach} n'enchaîne plus trop de séances le {day}."
        )
    if rep.detail == "age":
        return (
            f"Au {venue} le {day}, une équipe plus jeune passe après une plus âgée."
            if broken
            else f"Au {venue} le {day}, l'ordre des âges est de nouveau respecté."
        )
    # coach_rest (défaut implicite)
    return f"{coach} n'a plus assez de jours de repos." if broken else f"{coach} retrouve ses jours de repos."


def compute_compromises(
    before: list[CompromiseTermInfo],
    after: list[CompromiseTermInfo],
    names: dict[str, dict[str, str]],
) -> list[dict[str, Any]]:
    """Le DELTA « avant » → « après », une entrée par écart, triée pour un rendu stable.

    Pour chaque clé logique on compte les termes HONORÉS avant et après (les familles per-slot
    — gymnase/jour/heure — s'agrègent ainsi par équipe, ce qui évite le double compte d'un
    déplacement au sein des créneaux préférés). Le compte baisse → ``broken`` ; il monte →
    ``gained`` ; égal → silence.
    """

    def fold(infos: list[CompromiseTermInfo]) -> dict[tuple[Any, ...], tuple[int, CompromiseTermInfo]]:
        folded: dict[tuple[Any, ...], tuple[int, CompromiseTermInfo]] = {}
        for info in infos:
            count, rep = folded.get(info.key, (0, info))
            folded[info.key] = (count + (1 if info.honored() else 0), rep)
        return folded

    before_by_key = fold(before)
    after_by_key = fold(after)

    results: list[dict[str, Any]] = []
    for key in set(before_by_key) | set(after_by_key):
        honored_before = before_by_key.get(key, (0, None))[0]
        honored_after, rep_after = after_by_key.get(key, (0, None))
        rep = rep_after or before_by_key[key][1]
        if honored_after < honored_before:
            effect = "broken"
        elif honored_after > honored_before:
            effect = "gained"
        else:
            continue
        results.append(
            {
                "family": rep.family,
                "effect": effect,
                "message": _message(rep.family, effect, rep, names),
                "teamId": rep.team_id,
                "coachId": rep.coach_id,
                "venueId": rep.venue_id,
                "dayOfWeek": rep.day_of_week,
                "startTime": rep.start_time,
            }
        )

    # Ordre déterministe (le solve l'est déjà ; on ne laisse pas l'ordre d'itération d'un set
    # décider du rendu) : famille, puis effet, puis les entités.
    results.sort(key=lambda c: (c["family"], c["effect"], str(c["teamId"]), str(c["coachId"]), str(c["venueId"])))
    return results


__all__ = [
    "COMPROMISE_FAMILIES",
    "FAMILY_CHAINING",
    "FAMILY_COACH_DAY_CAP",
    "FAMILY_DAY",
    "FAMILY_IMPLICIT",
    "FAMILY_MATCH_REST",
    "FAMILY_SPACING",
    "FAMILY_TEAM_LINK",
    "FAMILY_TIME",
    "FAMILY_TRAVEL",
    "FAMILY_VENUE",
    "CompromiseTermInfo",
    "compute_compromises",
]
