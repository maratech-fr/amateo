"""P2-53 RMM-8 PR-2 — le trajet entre gymnases (règle implicite ``travelTime``).

Deux séances qu'une même personne enchaîne le même jour à des gymnases DIFFÉRENTS imposent un
trajet. La matrice ``venueTravelTimes`` donne, par couple de gymnases, le barème acceptable en
VOITURE et À PIED ; un couple non arbitré retombe sur ``travelTime.default_minutes`` (20). Deux
« voyageurs » relient des séances :

  * un COACH commun aux deux séances — son barème est VOITURE s'il est véhiculé, À PIED sinon
    (arbitrage fondateur : « si véhiculé le barème voiture, sinon la limite à pied ») ;
  * une PASSERELLE (``teamLinks``) — des joueurs partagés, jamais modélisés individuellement, donc
    barème À PIED d'office (« des jeunes ne conduisent pas »).

Deux termes en découlent (arbitrage fondateur 2026-08-26) :

  1. **DÉPARTAGE « moindre trajet »** (``add_travel_departage_penalty``) — un MALUS FAIBLE,
     proportionnel-paliéré au barème de chaque enchaînement cross-gymnase RÉALISÉ. C'est « un PLUS,
     préférable en cas d'ÉGALITÉ, jamais dominant » : il vit dans la PHASE 2 du solve, SOUS le
     placement (verrouillé à son optimum de phase 1) et SOUS le chaînage (×4096). Il ne départage
     donc QUE des solutions à placement ET chaînage égaux — jamais il ne renverse une famille
     majeure (tiers, quota, gymnase/jour/heure préférés). S'applique quel que soit le cran.
  2. **BATTEMENT insuffisant** (écart entre fin de A et début de B plus court que le barème) —
     ``MANDATORY`` : INTERDIT DUR (``add_travel_time_hard_constraints``, patron passerelle
     MANDATORY, diagnostic ``travel_time_infeasible`` post-solve) ; ``PREFERRED`` : violation SOFT
     nommée dans les compromis (``add_travel_time_penalty``, famille ``FAMILY_TRAVEL``).

Le MÊME gymnase n'est JAMAIS concerné (le prédicat exige des gymnases différents) : l'exemption
coach-coach même-gymnase (D-14, ``structural``) reste intacte par construction. Un bloc
``venueTravelTimes`` absent/vide OU une règle inactive ⇒ AUCUNE variable posée : chemin
byte-identique, goldens inchangés (patron ``teamLinks``/``sharedTrainings``).

⚠ Ce module est NEUF ; il ne touche NI ``_adaptive_workers`` NI les stubs ``common`` (jamais
posés). Son compteur dédié est ``HardConstraintStats.travel_time``.
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Iterator, Mapping, Sequence
from typing import Any

from ..compromise import FAMILY_TRAVEL, CompromiseTermInfo
from ..model import _format_time
from .common import (
    AssignmentInput,
    AssignmentVariable,
    BoolVarLike,
    _get,
    _intervals_overlap,
    _record_closure,
)
from .targeting import team_link_placements_by_team

# Un emplacement candidat : (début, fin, jour, gymnase, var|None) — même forme que
# ``TeamLinkPlacement`` (une séance VERROUILLÉE porte ``var=None``, elle est constante).
TravelPlacement = tuple[int, int, int, str, BoolVarLike | None]

# P2-53 RMM-8 PR-2 — poids du DÉPARTAGE. Classe DÉPARTAGE : ``1`` est le grain de l'objectif T24
# (= ``STABILITY_TERM_WEIGHT``, = le tier D). Le malus d'un enchaînement = ``1 × palier(barème)``,
# palier borné à 3 (``_departage_bucket``) : au plus −3 par enchaînement. Il n'entre PAS dans
# ``LEVEL_2_OBJECTIVE_WEIGHTS`` (donc AUCUN bump de ``SCORE_FORMULA_VERSION``) et n'est fondu QUE
# dans la PHASE 2, sous le placement (verrouillé) et sous le chaînage (×4096) : il ne peut donc
# renverser AUCUNE famille de placement — il n'ordonne que des ex æquo exacts. Le « moindre
# trajet » vient du palier croissant : un enchaînement court coûte 1, un long coûte 3, le
# maximiseur préfère donc le gymnase suivant le plus proche À placement égal.
TRAVEL_DEPARTAGE_WEIGHT = 1
# Bornes des paliers de barème (minutes) → malus 1/2/3. Choisies pour que le départage distingue
# un trajet court d'un long sans jamais qu'UN enchaînement (≤3) ne pèse une habitude (5) : il
# reste un pur départage, prouvé par le test de non-domination.
_DEPARTAGE_SHORT_MAX = 15
_DEPARTAGE_MEDIUM_MAX = 40
# P2-53 RMM-8 PR-2 — malus d'un BATTEMENT insuffisant CONCÉDÉ (règle PREFERRED). Même masse que
# les violations de règles de bien-être PREFERRED (−6, cf. ``LEVEL_2_OBJECTIVE_WEIGHTS``) : le
# solveur écarte les séances quand il peut, mais −6 < 21 (valeur minimale d'une séance placée)
# garantit qu'il ne SUPPRIME jamais une séance pour éviter un battement serré. Constante locale
# (hors ``LEVEL_2_OBJECTIVE_WEIGHTS``) : inerte par défaut, donc aucun bump de formule.
TRAVEL_BATTEMENT_VIOLATION_WEIGHT = 6


def _departage_bucket(minutes: int) -> int:
    """Palier de départage d'un barème (minutes) : 1 (court) / 2 (moyen) / 3 (long)."""
    if minutes <= _DEPARTAGE_SHORT_MAX:
        return 1
    if minutes <= _DEPARTAGE_MEDIUM_MAX:
        return 2
    return 3


def build_travel_matrix(venue_travel_times: Iterable[Any]) -> dict[frozenset[str], tuple[int | None, int | None]]:
    """La matrice indexée par couple NON ordonné de gymnases → ``(voiture, à pied)`` (minutes,
    nullables). Symétrique par construction (frozenset) : A–B ≡ B–A."""
    matrix: dict[frozenset[str], tuple[int | None, int | None]] = {}
    for row in venue_travel_times or ():
        va = _get(row, "venueAId", "venue_a_id", default=None)
        vb = _get(row, "venueBId", "venue_b_id", default=None)
        if va is None or vb is None or str(va) == str(vb):
            continue
        driving = _get(row, "drivingMinutes", "driving_minutes", default=None)
        walking = _get(row, "walkingMinutes", "walking_minutes", default=None)
        matrix[frozenset({str(va), str(vb)})] = (
            int(driving) if driving is not None else None,
            int(walking) if walking is not None else None,
        )
    return matrix


def _barometer(
    matrix: Mapping[frozenset[str], tuple[int | None, int | None]],
    venue_a: str,
    venue_b: str,
    *,
    driving: bool,
    default_minutes: int,
) -> int:
    """Le barème applicable entre deux gymnases : colonne VOITURE si ``driving`` sinon À PIED ;
    ``default_minutes`` (20) si la colonne est nulle ou le couple absent (« paire jamais
    arbitrée »)."""
    pair = matrix.get(frozenset({venue_a, venue_b}))
    if pair is None:
        return default_minutes
    value = pair[0] if driving else pair[1]
    return int(value) if value is not None else default_minutes


def _sorted_placements(placements: Iterable[TravelPlacement]) -> list[TravelPlacement]:
    """Ordre STABLE (jour, début, fin, gymnase) — le solve est déterministe, on ne laisse pas
    l'ordre d'itération décider de la pose."""
    return sorted(placements, key=lambda p: (p[2], p[0], p[1], p[3]))


def _cross_venue_gap(pa: TravelPlacement, pb: TravelPlacement) -> int | None:
    """L'écart (minutes) entre les deux séances si elles sont le MÊME jour, à des gymnases
    DIFFÉRENTS et NON chevauchantes (l'enchaînement réel) ; ``None`` sinon (même gymnase,
    autre jour, ou chevauchement — ce dernier est déjà régi ailleurs)."""
    a_start, a_end, a_day, a_venue, _ = pa
    b_start, b_end, b_day, b_venue, _ = pb
    if a_day != b_day or a_venue == b_venue:
        return None
    if _intervals_overlap(a_start, a_end, b_start, b_end):
        return None
    return (b_start - a_end) if a_start <= b_start else (a_start - b_end)


def _coach_teams(team_coach_map: Mapping[str, list[str]] | None) -> dict[str, list[str]]:
    """Inverse ``team → [coach]`` en ``coach → [team]`` (ordre stable des équipes)."""
    by_coach: dict[str, list[str]] = defaultdict(list)
    for team_id, coach_ids in (team_coach_map or {}).items():
        for coach_id in coach_ids or ():
            by_coach[str(coach_id)].append(str(team_id))
    return by_coach


def _vehicled_by_coach(coaches: Iterable[Any]) -> dict[str, bool]:
    result: dict[str, bool] = {}
    for coach in coaches or ():
        coach_id = _get(coach, "id", default=None)
        if coach_id is not None:
            result[str(coach_id)] = bool(_get(coach, "is_vehicled", "isVehicled", default=False))
    return result


def iter_travel_pairs_from_placements(
    placements_by_team: Mapping[str, list[TravelPlacement]],
    *,
    coaches: Iterable[Any],
    team_links: Iterable[Any],
    team_coach_map: Mapping[str, list[str]] | None,
    matrix: Mapping[frozenset[str], tuple[int | None, int | None]],
    default_minutes: int,
) -> Iterator[tuple[str, int, int, TravelPlacement, TravelPlacement]]:
    """Le CŒUR géométrique de ``_iter_travel_pairs``, à partir de placements DÉJÀ résolus
    (``team → [TravelPlacement]``). SOURCE UNIQUE du prédicat « battement / barème » (résorbe
    ENG-37 côté verdict) : la pose du solveur ET le miroir déterministe de ``/validate-assignments``
    l'appellent — donc ils jugent EXACTEMENT la même géométrie, sans jamais recalculer gap/barème à
    la main. Énumère ``(traveler_key, gap, barometer, pa, pb)`` pour chaque enchaînement
    cross-gymnase, même jour, non chevauchant, d'un voyageur (coach véhiculé/à pied, ou passerelle à
    pied)."""
    # Voyageur COACH : toutes les séances de SES équipes, barème voiture/à pied selon véhiculé.
    vehicled = _vehicled_by_coach(coaches)
    for coach_id, team_ids in _coach_teams(team_coach_map).items():
        gathered: list[TravelPlacement] = []
        for team_id in team_ids:
            gathered.extend(placements_by_team.get(team_id, []))
        ordered = _sorted_placements(gathered)
        driving = vehicled.get(coach_id, False)
        for i in range(len(ordered)):
            for j in range(i + 1, len(ordered)):
                gap = _cross_venue_gap(ordered[i], ordered[j])
                if gap is None:
                    continue
                barometer = _barometer(
                    matrix, ordered[i][3], ordered[j][3], driving=driving, default_minutes=default_minutes
                )
                yield f"coach:{coach_id}", gap, barometer, ordered[i], ordered[j]

    # Voyageur PASSERELLE : les séances de team A face à celles de team B, barème À PIED d'office.
    for link in team_links or ():
        team_a = str(_get(link, "teamAId", "team_a_id", default=""))
        team_b = str(_get(link, "teamBId", "team_b_id", default=""))
        if not team_a or not team_b or team_a == team_b:
            continue
        link_id = str(_get(link, "id", default=f"{team_a}_{team_b}"))
        placements_a = _sorted_placements(placements_by_team.get(team_a, []))
        placements_b = _sorted_placements(placements_by_team.get(team_b, []))
        for pa in placements_a:
            for pb in placements_b:
                gap = _cross_venue_gap(pa, pb)
                if gap is None:
                    continue
                barometer = _barometer(matrix, pa[3], pb[3], driving=False, default_minutes=default_minutes)
                yield f"link:{link_id}", gap, barometer, pa, pb


def _iter_travel_pairs(
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike] | None,
    locked_slots: Iterable[Any],
    *,
    coaches: Iterable[Any],
    team_links: Iterable[Any],
    team_coach_map: Mapping[str, list[str]] | None,
    matrix: Mapping[frozenset[str], tuple[int | None, int | None]],
    default_minutes: int,
) -> Iterator[tuple[str, int, int, TravelPlacement, TravelPlacement]]:
    """Résout les placements depuis les variables du modèle (``assignments`` + ``locked_slots``)
    puis délègue au CŒUR géométrique ``iter_travel_pairs_from_placements``. Enveloppe consommée par
    les trois termes de la pose (hard MANDATORY, soft PREFERRED, départage)."""
    placements_by_team = team_link_placements_by_team(assignments, locked_slots)
    yield from iter_travel_pairs_from_placements(
        placements_by_team,
        coaches=coaches,
        team_links=team_links,
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=default_minutes,
    )


def _both_placed_literal(model: Any, pa: TravelPlacement, pb: TravelPlacement, name: str) -> BoolVarLike | None:
    """Le littéral « les deux séances sont posées » (patron ``add_team_link_penalty``) :
    libre⇔libre → un ``ov`` réifié à ``max(0, a+b−1)`` ; libre⇔verrou → la variable libre (le
    verrou est toujours là) ; verrou⇔verrou → ``None`` (constante, rien à orienter)."""
    a_var, b_var = pa[4], pb[4]
    if a_var is not None and b_var is not None:
        ov = model.NewBoolVar(name.replace(":", "_"))
        model.Add(ov >= a_var + b_var - 1)
        return ov
    if a_var is not None:
        return a_var
    if b_var is not None:
        return b_var
    return None


def add_travel_time_hard_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    *,
    coaches: Iterable[Any] = (),
    team_links: Iterable[Any] = (),
    team_coach_map: Mapping[str, list[str]] | None = None,
    venue_travel_times: Iterable[Any] = (),
    default_minutes: int = 20,
) -> int:
    """Règle ``travelTime`` MANDATORY — INTERDIT DUR les enchaînements au battement trop court.

    Pour chaque enchaînement cross-gymnase dont ``gap < barometer`` (le voyageur n'a pas le temps
    de rejoindre le gymnase suivant), même patron que la passerelle MANDATORY :
      * libre⇔libre : ``a + b <= 1`` (les deux ne coïncident pas dans cet enchaînement serré) ;
      * libre⇔verrou : la libre s'efface (== 0) et ``_record_closure`` nomme la cause
        (``kind="travel_time"``) pour le rail P4-99 ;
      * verrou⇔verrou : DEUX actes du gestionnaire qui se contredisent — rien posé (jamais un
        INFEASIBLE muet), la violation est ANNONCÉE post-solve (``travel_time_infeasible``).

    Retour immédiat (0) si la matrice est vide : chemin byte-identique."""
    matrix = build_travel_matrix(venue_travel_times)
    if not matrix:
        return 0

    added = 0
    for _traveler_key, gap, barometer, pa, pb in _iter_travel_pairs(
        assignments,
        getattr(model, "locked_slots", ()) or (),
        coaches=coaches,
        team_links=team_links,
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=default_minutes,
    ):
        if gap >= barometer:
            continue
        a_var, b_var = pa[4], pb[4]
        cause = {"kind": "travel_time", "constraintId": None, "label": None}
        if a_var is not None and b_var is not None:
            model.Add(a_var + b_var <= 1)
            added += 1
        elif a_var is not None:
            model.Add(a_var == 0)
            _record_closure(model, a_var, cause)
            added += 1
        elif b_var is not None:
            model.Add(b_var == 0)
            _record_closure(model, b_var, cause)
            added += 1
        # else : deux verrous → rien (diagnostic post-solve).
    return added


def add_travel_time_penalty(
    model: Any,
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike],
    *,
    coaches: Iterable[Any] = (),
    team_links: Iterable[Any] = (),
    team_coach_map: Mapping[str, list[str]] | None = None,
    venue_travel_times: Iterable[Any] = (),
    default_minutes: int = 20,
    info_out: list[CompromiseTermInfo] | None = None,
) -> list[tuple[BoolVarLike, int]]:
    """Règle ``travelTime`` PREFERRED — MALUS SOFT (−6) par battement trop court CONCÉDÉ.

    Chaque enchaînement ``gap < barometer`` dont les deux séances sont posées porte
    ``−TRAVEL_BATTEMENT_VIOLATION_WEIGHT``. Le maximiseur écarte donc les enchaînements serrés
    quand il peut, sans jamais supprimer une séance (−6 < 21). ``info_out`` (chemin
    ``/validate-assignments``) reçoit un ``CompromiseTermInfo`` par littéral (MALUS →
    ``honored_when_active=False``). Vide (matrice absente) ⇒ ``[]`` byte-identique."""
    matrix = build_travel_matrix(venue_travel_times)
    if not matrix:
        return []

    terms: list[tuple[BoolVarLike, int]] = []
    index = 0
    for traveler_key, gap, barometer, pa, pb in _iter_travel_pairs(
        assignments,
        getattr(model, "locked_slots", ()) or (),
        coaches=coaches,
        team_links=team_links,
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=default_minutes,
    ):
        if gap >= barometer:
            continue
        literal = _both_placed_literal(model, pa, pb, f"travel_batt_{traveler_key}_{index}")
        if literal is None:
            continue
        index += 1
        terms.append((literal, -TRAVEL_BATTEMENT_VIOLATION_WEIGHT))
        if info_out is not None:
            coach_id = traveler_key.split(":", 1)[1] if traveler_key.startswith("coach:") else None
            info_out.append(
                CompromiseTermInfo(
                    var=literal,
                    family=FAMILY_TRAVEL,
                    honored_when_active=False,
                    key=(FAMILY_TRAVEL, traveler_key, pa[2], pa[3], pb[3]),
                    coach_id=coach_id,
                    venue_id=pb[3],
                    day_of_week=pa[2],
                    start_time=_format_time(pb[0]),
                )
            )
    return terms


def add_travel_departage_penalty(
    model: Any,
    assignments: Iterable[AssignmentInput] | Mapping[Any, BoolVarLike],
    *,
    coaches: Iterable[Any] = (),
    team_links: Iterable[Any] = (),
    team_coach_map: Mapping[str, list[str]] | None = None,
    venue_travel_times: Iterable[Any] = (),
    default_minutes: int = 20,
) -> list[tuple[BoolVarLike, int]]:
    """DÉPARTAGE « moindre trajet » — un MALUS FAIBLE ``−(1 × palier(barème))`` par enchaînement
    cross-gymnase RÉALISÉ (les deux séances posées), quel que soit le cran de la règle.

    Le maximiseur, à placement ET chaînage égaux (le départage vit dans la sous-bande de PHASE 2,
    voir ``main._solve``), préfère les enchaînements au barème le plus court — donc le gymnase
    suivant le plus proche (arbitrage fondateur U13M3 : De Barros plutôt que Camus car le coach
    enchaîne à Matéo). Vide (matrice absente) ⇒ ``[]`` byte-identique."""
    matrix = build_travel_matrix(venue_travel_times)
    if not matrix:
        return []

    terms: list[tuple[BoolVarLike, int]] = []
    index = 0
    for traveler_key, _gap, barometer, pa, pb in _iter_travel_pairs(
        assignments,
        getattr(model, "locked_slots", ()) or (),
        coaches=coaches,
        team_links=team_links,
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=default_minutes,
    ):
        literal = _both_placed_literal(model, pa, pb, f"travel_dep_{traveler_key}_{index}")
        if literal is None:
            continue
        index += 1
        terms.append((literal, -TRAVEL_DEPARTAGE_WEIGHT * _departage_bucket(barometer)))
    return terms


__all__ = [
    "TRAVEL_BATTEMENT_VIOLATION_WEIGHT",
    "TRAVEL_DEPARTAGE_WEIGHT",
    "TravelPlacement",
    "add_travel_departage_penalty",
    "add_travel_time_hard_constraints",
    "add_travel_time_penalty",
    "build_travel_matrix",
    "iter_travel_pairs_from_placements",
]
