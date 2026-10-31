"""Double-réservations gymnase/coach et message d'infaisabilité porté (``diag-infeasible``).

Extrait de l'ancien ``diagnostics.py`` (découpe P4-295 C1). Seule arête interne du paquet : importe
deux symboles de ``infeasibility`` et le diagnostic de ``shared_blocks``.
"""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Mapping
from datetime import UTC, datetime
from typing import Any

from ortools.sat.python import cp_model

from ...constraints.common import _fold_case_occupant_identity
from ...model import (
    ScheduleCpModel,
    _format_time,
    _time_to_minutes,
)
from ..helpers import (
    _coach_name_map,
    _collection,
    _day_label,
    _get,
    _label,
    _named_list,
    _occupant_list,
    _team_name_map,
    _time_range,
    _venue_name_map,
)
from .infeasibility import (
    _collect_infeasibility_causes,
    _infeasible_message,
)
from .shared_blocks import _diagnose_shared_blocks

_DEMAND_RULE_KINDS = frozenset({"day_forced", "session_floor", "shared_block"})
"""Les seules règles qui exigent une séance : tout INFEASIBLE en a au moins une dans son noyau, les
règles de bien-être et la capacité ne posant que des bornes hautes (ADR-0001)."""

_WELLNESS_LEAD_TAIL = (
    "sans doute à une règle de bien-être réglée « Obligatoire » (jour de repos, enchaînements, jeunes avant "
    "les grands) ou à un coach qui tient plusieurs équipes : passez cette règle de bien-être en « Objectif » "
    "pour le vérifier."
)


def _diagnose_conflicts(
    model_data: Mapping[str, Any] | Any,
    solver_status: int,
    slots: list[dict[str, Any]],
    *,
    slot_capacities: dict[Any, int] | None = None,
    model: ScheduleCpModel | Any | None = None,
    solver: cp_model.CpSolver | Any | None = None,
    diagnostic_model: ScheduleCpModel | Any | None = None,
    diagnostic_solver: cp_model.CpSolver | Any | None = None,
) -> list[dict[str, Any]]:
    """Report infeasibility or detected double-bookings — who, when, why.

    P4-96 — sur INFEASIBLE : ``diag-infeasible`` porte le NOYAU de règles en conflit (via
    ``_collect_infeasibility_causes`` lu sur le SECOND solve diagnostique instrumenté
    ``diagnostic_model``/``diagnostic_solver``), en message et en ``causes[]`` nommées. Le texte selon
    le cas (groupe, règles de demande, recherche non aboutie) : ``_infeasible_core_message``. Aucune
    ligne par équipe : le noyau nomme les règles, pas des équipes étrangères à la cause.

    ``slot_capacities`` maps ``(venue_id, day_of_week, start_time)`` to the
    maximum number of teams allowed simultaneously.  When provided, a venue
    booking is only flagged as a conflict when the number of teams exceeds the
    slot's declared capacity (supporting multi-team training slots with
    capacity > 1).  When absent, the legacy threshold of 1 is used.
    """
    diagnostics: list[dict[str, Any]] = []
    team_names = _team_name_map(model_data)
    venue_names = _venue_name_map(model_data)
    coach_names = _coach_name_map(model_data)

    if solver_status == cp_model.INFEASIBLE:
        core_causes: list[dict[str, Any]] = []
        core_labels: list[str] = []
        overbooked = False
        # Le noyau nommé vient du SECOND solve diagnostique (seul porteur des hypothèses). On retombe
        # sur ``model``/``solver`` pour les appels directs de test qui instrumentent le modèle passé.
        d_model = diagnostic_model if diagnostic_model is not None else model
        d_solver = diagnostic_solver if diagnostic_solver is not None else solver
        if d_model is not None and d_solver is not None:
            core_causes, core_labels = _collect_infeasibility_causes(d_model, d_solver)
            core_causes, core_labels, overbooked = _name_blocks_in_core(
                d_model, d_solver, model_data, core_causes, core_labels
            )

        # Le modèle nominal ne porte aucune hypothèse : sans modèle diagnostique, seul un noyau lu
        # prouve que la recherche a abouti.
        message, suggestions = _infeasible_core_message(
            core_causes,
            core_labels,
            model_data,
            diagnostic_ran=diagnostic_model is not None or bool(core_causes),
            overbooked=overbooked,
        )
        diagnostics.append(
            {
                "id": "diag-infeasible",
                "type": "conflict",
                "severity": "ERROR",
                "message": message,
                "suggestions": suggestions,
                "causes": core_causes,
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    if solver_status == cp_model.UNKNOWN:
        # ENG-22: the solver stopped WITHOUT a solution and WITHOUT proving infeasibility — the
        # time budget ran out on a hard instance. Say so, instead of a silent "failed".
        diagnostics.append(
            {
                "id": "diag-timeout",
                "type": "conflict",
                "severity": "ERROR",
                "message": (
                    "Le solveur n'a pas trouvé de planning dans le temps imparti (problème trop "
                    "complexe). Aucune infaisabilité prouvée — une solution existe peut-être avec "
                    "plus de temps ou moins de contraintes."
                ),
                "suggestions": [
                    "Réduisez la taille du problème (équipes / gymnases) ou le nombre de contraintes.",
                    "Relancez la génération : le solveur peut aboutir sur un nouvel essai.",
                ],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    if solver_status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
        # ENG-22: MODEL_INVALID (or any other non-solve status) is a construction bug, NOT a
        # time problem — "retry / shrink" would mislead. Surface it as an internal error.
        diagnostics.append(
            {
                "id": "diag-solver-error",
                "type": "conflict",
                "severity": "ERROR",
                "message": (
                    "Erreur interne du solveur (modèle invalide). Ce n'est pas un problème de "
                    "taille ni de temps — signalez-le au support."
                ),
                "suggestions": ["Contactez le support : la génération n'a pas pu être construite correctement."],
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        return diagnostics

    _caps: dict[Any, int] = slot_capacities or {}

    # P2-51 — mutualisation par BLOC : une séance de bloc = UNE occupation d'une case (pas N).
    # Une réservation de bloc s'éclate en N `Reservation` (une par membre, même case) → N verrous
    # HARD ; les compter comme N occupants distincts crierait faussement à la sur-capacité. Le
    # ``team_to_group`` (exemption du modèle groupe K) a disparu avec ce modèle : il reste une carte
    # VIDE que le repli des blocs ({@see _fold_case_occupant_identity}) consomme sans effet.
    team_to_group: dict[str, str] = {}

    # P2-51 — mutualisation par BLOC : une séance de bloc = UNE occupation de la case (pas N). La
    # multi-appartenance étant permise (une équipe dans plusieurs blocs), l'attribution se fait PAR
    # CASE (un bloc dont TOUS les membres siègent ICI se fond en un occupant), jamais « premier bloc
    # gagne » via une carte globale. Bloc `sharedBlocks` absent ⇒ liste vide ⇒ comptage == groupes
    # ⇒ chemin byte-identique (goldens inchangés).
    blocks: list[tuple[str, frozenset[str]]] = []
    for block_index, block in enumerate(_collection(model_data, "sharedBlocks", "shared_blocks")):
        block_members = frozenset(str(m) for m in (_get(block, "teamIds", "team_ids", default=[]) or []))
        if len(block_members) >= 2:
            blocks.append((f"__shared_block__{_get(block, 'id', default=block_index)}", block_members))

    # Post-solve safety check: venue over-capacity.
    venue_bookings: dict[tuple[str, int, str], list[str]] = defaultdict(list)
    venue_durations: dict[tuple[str, int, str], int] = {}
    for slot in slots:
        key = (slot["venueId"], slot["dayOfWeek"], slot["startTime"])
        venue_bookings[key].append(slot["teamId"])
        venue_durations[key] = max(venue_durations.get(key, 0), int(slot.get("durationMinutes") or 0))

    for (venue_id, day_of_week, start_time), booked in venue_bookings.items():
        # Distinct teams only: at a fixed (venue, day, start), the same team twice
        # is the duplicate-slot artifact, not over-capacity (audit ENG-09).
        team_ids = list(dict.fromkeys(booked))
        capacity = _caps.get((venue_id, day_of_week, start_time), 1)
        # P2-46 / P2-51 — chaque membre d'un groupe OU d'un bloc co-localisé se fond en UN occupant.
        # Groupes : carte globale (unicité). Blocs : fondus PAR CASE (multi-appartenance). Sans
        # groupe ni bloc, `occupants == team_ids` (chemin byte-identique).
        if blocks:
            identity, block_keys_here = _fold_case_occupant_identity(team_ids, team_to_group, blocks)
            occupants = set(identity.values())
            occupant_text = _occupant_list_with_blocks(team_ids, identity, block_keys_here, team_names)
        else:
            occupants = {team_to_group.get(team_id, team_id) for team_id in team_ids}
            occupant_text = _occupant_list(team_ids, team_to_group, team_names)
        if len(occupants) > capacity:
            when = f"{_day_label(day_of_week)} {_time_range(start_time, venue_durations.get((venue_id, day_of_week, start_time)))}"
            diagnostics.append(
                {
                    "id": f"diag-conflict-venue-{venue_id}-{day_of_week}-{start_time}",
                    "type": "conflict",
                    "severity": "ERROR",
                    "venueId": venue_id,
                    "dayOfWeek": day_of_week,
                    "startTime": str(start_time)[:5],
                    # P2-46 — le message COMPTE ce que la règle a compté : `occupants`, pas
                    # `team_ids`. Sinon il ment sur le remède — « 3 équipes / capacité 1 » avec
                    # un groupe de 2 + une étrangère ferait viser une capacité 3 quand 2 suffit,
                    # et « déplacez une séance » enverrait déplacer UN membre, ce qui ne libère
                    # rien (le groupe reste). Un groupe est nommé comme un seul occupant.
                    "message": (
                        f"Le gymnase {_label(venue_id, venue_names)} accueille {len(occupants)} "
                        f"{'occupant' if len(occupants) == 1 else 'occupants'} en même temps le {when} "
                        f"alors que sa capacité est de {capacity} : "
                        f"{occupant_text}."
                    ),
                    "suggestions": [
                        "Déplacez l'une des séances sur un autre horaire ou un autre gymnase.",
                    ],
                    "createdAt": datetime.now(UTC).isoformat(),
                }
            )

    # Post-solve safety check: coach double-booking.
    #
    # D-14 (2026-08-09) — deux corrections, faites ensemble parce qu'elles portaient sur la
    # MÊME question et se contredisaient :
    #
    #  1. La clé était `(coach, jour, heure de début EXACTE)`. Deux séances 17h00-18h30 et
    #     17h30-19h00 dédoublent pourtant bien le coach : elles tombaient dans deux clés
    #     distinctes et passaient inaperçues. La contrainte HARD du solveur, elle, teste
    #     l'intersection d'intervalles depuis toujours — ce filet était donc plus laxiste
    #     que le modèle qu'il est censé surveiller. On aligne sur les intervalles.
    #
    #  2. Le MÊME gymnase n'est PAS un conflit (arbitrage fondateur) : un coach peut tenir
    #     les SM1 et les SM2 côte à côte, il est présent une fois. Le diagnostic remontait
    #     une ERROR rouge sur un geste que le backend et l'UI offrent explicitement.
    #
    # Ce qui reste un conflit : gymnases DIFFÉRENTS et intervalles qui se chevauchent — y
    # compris pour la MÊME équipe (elle ne peut pas être à deux endroits non plus). Le
    # doublon même-équipe/même-gymnase (artefact de template dupliqué) reste, lui, muet.
    coach_slots: dict[tuple[str, int], list[tuple[int, int, str, str, str, bool]]] = defaultdict(list)
    for slot in slots:
        coach_id = slot.get("coachId")
        if not coach_id:
            continue
        start_minutes = _time_to_minutes(slot["startTime"])
        duration = int(slot.get("durationMinutes") or 0)
        coach_slots[(str(coach_id), slot["dayOfWeek"])].append(
            (
                start_minutes,
                start_minutes + duration,
                str(slot["teamId"]),
                str(slot["venueId"]),
                str(slot["startTime"]),
                str(slot.get("lockLevel") or "NONE").upper() == "HARD",
            ),
        )

    for (clash_coach, clash_day), clash_booked in sorted(coach_slots.items()):
        ordered = sorted(clash_booked)
        seen_pairs: set[tuple[str, str]] = set()
        for i, (a_start, a_end, a_team, a_venue, a_raw, a_hard) in enumerate(ordered):
            for b_start, b_end, b_team, b_venue, _b_raw, b_hard in ordered[i + 1 :]:
                if a_venue == b_venue:
                    continue  # même gymnase : le coach n'y est qu'une fois (D-14)
                if not (a_start < b_end and b_start < a_end):
                    continue  # intervalles demi-ouverts : se toucher n'est pas se chevaucher
                pair = (a_team, b_team) if a_team <= b_team else (b_team, a_team)
                if pair in seen_pairs:
                    continue
                seen_pairs.add(pair)
                when = f"{_day_label(clash_day)} {_time_range(a_raw, a_end - a_start)}"
                # Une réservation en cause : un seul message (décision fondateur 2026-10-30). Le
                # chemin sans verrou garde son signal historique.
                reservation_at_stake = a_hard or b_hard
                signal: dict[str, Any] = (
                    {
                        "type": "rule_sacrificed",
                        "severity": "WARNING",
                        "causes": [{"kind": "hard_lock", "constraintId": None, "label": None, "count": 2}],
                    }
                    if reservation_at_stake
                    else {"type": "conflict", "severity": "ERROR"}
                )
                diagnostics.append(
                    {
                        "id": f"diag-conflict-coach-{clash_coach}-{clash_day}-{a_raw}",
                        **signal,
                        "coachId": clash_coach,
                        "dayOfWeek": clash_day,
                        # P4-95 lot 8, décision D — même défaut latent que ``diag-locked-person-*`` :
                        # sur des débuts décalés, ``a_raw`` (début de la 1ʳᵉ séance) n'appartient qu'à
                        # UNE des deux cases → le front en ouvrirait une, au lieu de surligner les deux
                        # gymnases sans rien ouvrir. On émet le début du CHEVAUCHEMENT, contenu dans les
                        # deux par définition. L'``id`` (qui porte ``a_raw``) ne change pas.
                        "startTime": _format_time(max(a_start, b_start)),
                        "message": (
                            f"Le coach {_label(clash_coach, coach_names)} est affecté à plusieurs équipes "
                            f"en même temps le {when}, dans des gymnases différents : {_named_list(list(pair), team_names)}."
                            + (" Au moins une de ces séances est une séance fixée." if reservation_at_stake else "")
                        ),
                        "suggestions": [
                            "Séparez les séances ou affectez un autre coach à l'une des équipes.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    return diagnostics


def _name_blocks_in_core(
    model: ScheduleCpModel | Any,
    solver: cp_model.CpSolver | Any,
    model_data: Mapping[str, Any] | Any,
    causes: list[dict[str, Any]],
    labels: list[str],
) -> tuple[list[dict[str, Any]], list[str], bool]:
    """Nomme par ses équipes chaque groupe du noyau : ``_assume`` pose un bloc sans libellé, sa cause
    ``shared_block`` anonyme est remplacée par une cause « groupe SM1 + SM2 » par groupe en cause.

    Le noyau rendu par le solveur n'est pas minimal : il peut citer un groupe sans histoire. Si des
    groupes du noyau sont sur-épinglés par leurs propres verrous, seuls ceux-là sont nommés, et le
    troisième terme le dit : le message n'accuse une réservation en trop que dans ce cas."""
    registry: Mapping[str, Any] = getattr(model, "assumption_literals", None) or {}
    block_literals = {key.split(":", 1)[1]: lit for key, lit in registry.items() if key.startswith("shared_block:")}
    if not block_literals or not hasattr(solver, "SufficientAssumptionsForInfeasibility"):
        return causes, labels, False
    try:
        core_indices = {int(index) for index in solver.SufficientAssumptionsForInfeasibility()}
    except RuntimeError, AttributeError, TypeError, ValueError:
        return causes, labels, False
    core_block_ids = {block_id for block_id, lit in block_literals.items() if int(lit.Index()) in core_indices}
    if not core_block_ids:
        return causes, labels, False
    # Le motif ``shared-block-overpinned`` du diagnostic dédié dit quels groupes le sont, sans seconde logique.
    prefix = "shared-block-overpinned-"
    overpinned = {
        str(diagnostic["id"]).removeprefix(prefix)
        for diagnostic in _diagnose_shared_blocks(model_data, int(cp_model.INFEASIBLE), [])
        if str(diagnostic["id"]).startswith(prefix)
    }
    overbooked_ids = core_block_ids & overpinned
    core_block_ids = overbooked_ids or core_block_ids

    team_names = _team_name_map(model_data)
    block_causes: list[dict[str, Any]] = []
    for index, block in enumerate(_collection(model_data, "sharedBlocks", "shared_blocks")):
        # Même identifiant que la pose (``targeting.py``), donc que la clé de la littérale.
        if str(_get(block, "id", default=index) or index) not in core_block_ids:
            continue
        members = " + ".join(_label(str(team), team_names) for team in _get(block, "teamIds", "team_ids", default=[]))
        block_causes.append({"kind": "shared_block", "constraintId": None, "label": f"groupe {members}", "count": 1})
    if not block_causes:
        return causes, labels, False
    others = [cause for cause in causes if cause.get("kind") != "shared_block" or cause.get("label")]
    named = [str(cause["label"]) for cause in block_causes]
    return [*block_causes, *others], [*named, *labels], bool(overbooked_ids)


def _infeasible_core_message(
    core_causes: list[dict[str, Any]],
    core_labels: list[str],
    model_data: Mapping[str, Any] | Any,
    *,
    diagnostic_ran: bool,
    overbooked: bool = False,
) -> tuple[str, list[str]]:
    """Le message et les suggestions de ``diag-infeasible``, selon ce que le noyau a pu nommer."""
    if not diagnostic_ran:
        return (
            "Le planning n'a pas pu être généré, et la recherche de la cause n'a pas abouti dans le temps imparti.",
            [
                "Relancez la génération ; si l'échec persiste, désactivez vos dernières règles une à une "
                "pour trouver celle qui bloque."
            ],
        )

    groups = [str(c["label"]) for c in core_causes if c.get("kind") == "shared_block" and c.get("label")]
    if groups:
        return _group_message(groups, [label for label in core_labels if label not in groups], overbooked), []

    unnamed = sum(1 for cause in core_causes if not cause.get("label"))
    cited = len(core_labels) + unnamed
    suggestions: list[str] = []
    kinds = {cause.get("kind") for cause in core_causes}
    if kinds and kinds <= _DEMAND_RULE_KINDS:
        subject = (
            "Les règles en cause se heurtent"
            if not core_labels
            else "Elles se heurtent"
            if cited > 1
            else "Elle se heurte"
        )
        suggestions = [f"{subject} {_WELLNESS_LEAD_TAIL}"]
    elif core_labels:
        target = "l'une de ces règles" if cited > 1 else "cette règle"
        suggestions = [f"Assouplissez {target}, ou passez-la en « Objectif », puis relancez la génération."]

    if not core_labels:
        return _infeasible_message(model_data), suggestions
    quoted = ", ".join(f"« {label} »" for label in core_labels)
    if unnamed:
        quoted += " et une règle sans nom" if unnamed == 1 else f" et {unnamed} règles sans nom"
    if cited > 1:
        return f"Ces {cited} règles se contredisent : {quoted}.", suggestions
    return f"Cette règle ne peut pas être honorée : {quoted}.", suggestions


def _group_message(groups: list[str], others: list[str], overbooked: bool) -> str:
    """Une seule phrase directe, qui nomme le groupe et le remède. Une réservation en trop n'est citée
    que si le groupe est sur-épinglé ; sinon il manque des cases communes."""
    several = len(groups) > 1
    subject = f"Le {groups[0]}"
    if several:
        names = [group.removeprefix("groupe ") for group in groups]
        subject = f"Les groupes {', '.join(names[:-1])} et {names[-1]}"
    target = "d'un groupe" if several else "du groupe"
    sessions = "leurs séances communes" if several else "ses séances communes"
    if others:
        quoted = ", ".join(f"« {label} »" for label in others)
        rules = f"la règle {quoted}" if len(others) == 1 else f"les règles {quoted}"
        relax = "assouplissez cette règle" if len(others) == 1 else "assouplissez l'une de ces règles"
        first = f"retirez une réservation {target}" if overbooked else f"ajoutez une case commune aux équipes {target}"
        return f"{subject} et {rules} se contredisent : {first}, ajustez {sessions}, ou {relax}."
    if overbooked:
        state = (
            "sont réservés sur plus de cases que leurs séances communes, ou n'ont aucune case commune"
            if several
            else "est réservé sur plus de cases que ses séances communes, ou n'a aucune case commune"
        )
        return f"{subject} {state} : retirez une réservation {target} ou ajustez {sessions} dans l'assistant."
    state = (
        "n'ont pas assez de cases communes pour leurs séances communes"
        if several
        else "n'a pas assez de cases communes pour ses séances communes"
    )
    return f"{subject} {state} : ajoutez une case commune aux équipes {target} ou ajustez {sessions} dans l'assistant."


def _occupant_list_with_blocks(
    team_ids: list[str], identity: Mapping[str, str], block_keys: set[str], names: Mapping[str, str]
) -> str:
    """Miroir de ``_occupant_list`` étendu aux blocs : une clé de bloc fondue s'énonce « le bloc
    mutualisé (A, B) », une clé de groupe « le groupe mutualisé (…) », le reste équipe par équipe."""
    parts: list[str] = []
    seen: set[str] = set()
    for team_id in team_ids:
        key = identity.get(team_id, team_id)
        if key in seen:
            continue
        seen.add(key)
        if key in block_keys:
            members = [_label(other, names) for other in team_ids if identity.get(other) == key]
            parts.append(f"le bloc mutualisé ({', '.join(members)})")
        elif isinstance(key, str) and key.startswith("__shared_group__"):
            members = [_label(other, names) for other in team_ids if identity.get(other) == key]
            parts.append(f"le groupe mutualisé ({', '.join(members)})")
        else:
            parts.append(_label(team_id, names))
    return ", ".join(parts)
