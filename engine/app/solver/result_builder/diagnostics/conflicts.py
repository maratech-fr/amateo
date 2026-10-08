"""Double-réservations gymnase/coach et message d'infaisabilité porté (``diag-infeasible``).

Extrait verbatim de l'ancien ``diagnostics.py`` (découpe pure, P4-295 C1). Seule arête interne du
paquet : importe quatre symboles de ``infeasibility``.
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
    _collect_locked_out_teams,
    _infeasible_message,
    _lock_summary_message,
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

    P4-96 — sur INFEASIBLE : ``diag-infeasible`` porte le NOYAU de règles en conflit (D1, via
    ``_collect_infeasibility_causes`` lu sur le SECOND solve diagnostique instrumenté
    ``diagnostic_model``/``diagnostic_solver``) — message « Ces N règles se contredisent : … » +
    ``causes[]`` nommées —, et chaque équipe dont tous les candidats sont fermés (D2,
    ``_collect_locked_out_teams`` sur le modèle NOMINAL ``model``) reçoit son agrégat. Sans
    diagnostique (non lancé, ou UNKNOWN/timeout), le message générique ``_infeasible_message`` est
    conservé et D2 reste rendu (il ne dépend pas du solveur). Appels directs anciens (ni ``model``
    ni diagnostique) ⇒ message générique, aucune cause.

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
        team_names = _team_name_map(model_data)
        core_causes: list[dict[str, Any]] = []
        core_labels: list[str] = []
        locked_out: list[dict[str, Any]] = []
        # D1 — le noyau nommé vient du SECOND solve diagnostique (seul porteur des hypothèses) ;
        # absent ⇒ pas de noyau (message générique). On retombe sur ``model``/``solver`` pour les
        # appels directs de test qui instrumentent eux-mêmes le modèle passé en ``model``.
        d_model = diagnostic_model if diagnostic_model is not None else model
        d_solver = diagnostic_solver if diagnostic_solver is not None else solver
        if d_model is not None and d_solver is not None:
            core_causes, core_labels = _collect_infeasibility_causes(d_model, d_solver)
        # D2 — agrégat des candidats fermés, lu du modèle NOMINAL (fermetures inconditionnelles).
        if model is not None:
            locked_out = _collect_locked_out_teams(model)

        # D1 — quand le noyau nomme des règles, le message les CITE comme dans l'écran de
        # contraintes ; sinon (aucune hypothèse, noyau vide, libellés absents) on garde le
        # message générique mesuré de `_infeasible_message` (capacité / saturation / générique).
        if core_labels:
            quoted = ", ".join(f"« {label} »" for label in core_labels)
            count = len(core_labels)
            message = (
                f"Ces {count} règles se contredisent : {quoted}."
                if count > 1
                else f"Cette règle ne peut pas être honorée : {quoted}."
            )
        else:
            message = _infeasible_message(model_data)

        diagnostics.append(
            {
                "id": "diag-infeasible",
                "type": "conflict",
                "severity": "ERROR",
                "message": message,
                "suggestions": [
                    "Assouplissez ou retirez une contrainte dure (jour/heure imposé, gymnase forcé).",
                    "Ajoutez de la disponibilité de gymnase ou un coach supplémentaire.",
                    "Vérifiez les créneaux verrouillés (LOCK) qui se chevauchent entre équipes.",
                ],
                # D1 — causes STRUCTURÉES du noyau (cliquables côté front en PR-2) ; vide sans noyau.
                "causes": core_causes,
                "createdAt": datetime.now(UTC).isoformat(),
            }
        )
        # D2 — une équipe dont TOUS les candidats sont fermés : qui + combien + par quoi.
        for entry in locked_out:
            team_id = str(entry["teamId"])
            diagnostics.append(
                {
                    "id": f"diag-infeasible-team-{team_id}",
                    "type": "conflict",
                    "severity": "ERROR",
                    "teamId": team_id,
                    "message": _lock_summary_message(_label(team_id, team_names), int(entry["total"]), entry["causes"]),
                    "suggestions": [
                        "Ouvrez un créneau à cette équipe ou retirez une des règles qui ferment ses candidats.",
                    ],
                    "causes": entry["causes"],
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
    coach_slots: dict[tuple[str, int], list[tuple[int, int, str, str, str]]] = defaultdict(list)
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
            ),
        )

    for (clash_coach, clash_day), clash_booked in sorted(coach_slots.items()):
        ordered = sorted(clash_booked)
        seen_pairs: set[tuple[str, str]] = set()
        for i, (a_start, a_end, a_team, a_venue, a_raw) in enumerate(ordered):
            for b_start, b_end, b_team, b_venue, _b_raw in ordered[i + 1 :]:
                if a_venue == b_venue:
                    continue  # même gymnase : le coach n'y est qu'une fois (D-14)
                if not (a_start < b_end and b_start < a_end):
                    continue  # intervalles demi-ouverts : se toucher n'est pas se chevaucher
                pair = (a_team, b_team) if a_team <= b_team else (b_team, a_team)
                if pair in seen_pairs:
                    continue
                seen_pairs.add(pair)
                when = f"{_day_label(clash_day)} {_time_range(a_raw, a_end - a_start)}"
                diagnostics.append(
                    {
                        "id": f"diag-conflict-coach-{clash_coach}-{clash_day}-{a_raw}",
                        "type": "conflict",
                        "severity": "ERROR",
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
                        ),
                        "suggestions": [
                            "Séparez les séances ou affectez un autre coach à l'une des équipes.",
                        ],
                        "createdAt": datetime.now(UTC).isoformat(),
                    }
                )

    return diagnostics


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
