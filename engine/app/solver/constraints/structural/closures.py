"""Contraintes structurelles — créneaux figés, interdits et indisponibilités coach (sous-paquet P4-295 C5).

Extrait verbatim de l'ancien ``constraints/structural.py`` (découpe pure). N'importe QUE les
externes ``..common`` ; aucune arête vers un autre sous-module. L'orchestrateur
``add_level_1_hard_constraints`` du paquet ``constraints/__init__`` consomme via les ré-exports."""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from typing import Any

from ...model import _format_time
from ..availability import (
    available_coach_exists,
    case_day_start,
    case_has_available_coach,
    interval_bites,
)
from ..common import (
    AssignmentVariable,
    RuleCollection,
    _assignment_day_start,
    _assume,
    _enforce_closure,
    _record_closure,
    _source_key,
)


def add_fixed_slots(model: Any, assignments: Sequence[AssignmentVariable]) -> int:
    """Constraint 5: pre-placed slots are fixed to 1.

    ⚑ AUD-ENG-31 (2026-08-09) — cette contrainte n'ajoute RIEN en production, et c'est
    voulu : les verrous HARD sont pré-placés **hors** du solveur (P2-9 PR B), donc leur
    variable n'existe pas et aucun constructeur de production ne pose ``fixed=True``.

    Ce qui a été RETIRÉ, c'est la seconde entrée : une liste d'identifiants
    ``fixed_assignments`` alimentée par ``parsed["fixed_slots"]``. Cette clé était
    initialisée à ``[]`` et **plus personne ne l'écrivait** depuis que le chemin UUID des
    contraintes verrouillées (cran retiré) a été supprimé (il ne matchait jamais). Elle restait pourtant câblée
    jusqu'au solveur : du code qui annonce « le payload peut épingler des créneaux » alors
    qu'aucun payload ne le peut.

    L'attribut ``fixed`` reste, lui : il est le chemin naturel si les verrous devenaient un
    jour des variables du modèle, et il est testé.
    """
    added = 0
    for assignment in assignments:
        if assignment.fixed:
            model.Add(assignment.var == 1)
            added += 1
    return added


def add_forbidden_assignments(
    model: Any, assignments: Sequence[AssignmentVariable], forbidden_assignments: Iterable[Any] = ()
) -> int:
    """Constraint 6: forbidden assignment variables are fixed to 0.

    ``forbidden_assignments`` may contain either:
    - plain string/hashable IDs matched against the assignment's ``id`` field, OR
    - dicts with ``scope_target_id`` (team) and ``venue_id`` keys — every variable
      for that (team, venue) pair is forced to 0 regardless of day/slot.
    """

    forbidden_ids: set[Any] = set()
    # P4-99 — la paire (équipe, gymnase) porte AUSSI l'id/le libellé de la contrainte source
    # (enrichis dans `parse_v2_constraints`), pour rendre la cause cliquable côté front.
    forbidden_pairs: dict[tuple[str, str], tuple[str | None, str | None]] = {}

    for item in forbidden_assignments or ():
        if isinstance(item, dict):
            tid = item.get("scope_target_id") or item.get("team_id")
            vid = item.get("venue_id") or item.get("room_id")
            if tid is not None and vid is not None:
                forbidden_pairs[(str(tid), str(vid))] = (item.get("constraint_id"), item.get("label"))
        else:
            forbidden_ids.add(item)

    added = 0
    for assignment in assignments:
        assignment_id = assignment.id
        team_id = assignment.team_id
        venue_id = assignment.venue_id
        pair: tuple[str, str] | None = (
            (str(team_id), str(venue_id)) if team_id is not None and venue_id is not None else None
        )
        pair_match = pair is not None and pair in forbidden_pairs
        if assignment.forbidden or (assignment_id is not None and assignment_id in forbidden_ids) or pair_match:
            cause: dict[str, Any] = {"kind": "venue_forbidden"}
            source_id: Any = None
            if pair_match and pair is not None:
                constraint_id, label = forbidden_pairs[pair]
                cause["constraintId"] = constraint_id
                cause["label"] = label
                source_id = constraint_id
            # P4-96 — gymnase interdit saisi : UNE littérale par (équipe, gymnase), partagée par ses
            # fermetures. Un interdit intrinsèque / par id SANS paire reste inconditionnel (literal None).
            literal = (
                _assume(model, _source_key("venue_forbidden", source_id, pair), cause) if pair is not None else None
            )
            _enforce_closure(model, assignment.var, cause, literal)
            added += 1
    return added


def add_coach_unavailability_constraints(
    model: Any,
    assignments: Sequence[AssignmentVariable],
    coach_unavailability: RuleCollection = (),
    *,
    team_coach_map: dict[str, list[str]] | None = None,
) -> int:
    """Constraint 7: close an assignment variable when the team has NO required coach available.

    ``coach_unavailability`` maps a coach id to a set of blocked ``(weekday,
    from_minute, to_minute)`` intervals. A free slot is blocked when its day matches
    and ``[from, to)`` overlaps the session (half-open, no margin). A ``fixed`` slot (the frozen
    baseline of a verdict) stays judged at its start: the schedule in force never becomes
    infeasible because of this rule. A whole-day block is ``(day, 0, 1440)`` — the legacy day-level
    behaviour (Lot C added the time dimension; ENG-01 fixed the old no-match bug).

    ⚑ LOT 3B — « UN COACH SUFFIT » (Q4B, décision fondateur 2026-10-10). Une équipe peut avoir
    PLUSIEURS coachs requis (non-ASSISTANT ; l'assignment n'en porte que le premier). La règle
    d'AUDIT antérieure — « EVERY coach of the team is checked ; a co-head-coach's unavailability
    must block the slot too » — est **INVERSÉE ici** : la case n'est fermée par l'indisponibilité
    QUE si AUCUN coach requis n'est disponible (TOUS bloqués). Prod Toussaint BCCL : un bloc U11 de
    4 équipes aux coachs mono-jour incompatibles sortait INFEASIBLE alors qu'un coach de l'union
    couvre chaque jour. Le test ``test_coach_unavailable_day_enforced_for_second_coach`` qui épinglait
    l'ancien sens est inversé **en le disant**. On ne relâche QUE l'indisponibilité (Q3) :
    l'anti-chevauchement coach (``add_coach_at_most_one`` / ``add_coach_player_non_overlap``) garde
    sa règle, intact.

    SÉANCE DE BLOC. Les blocs sont posés AVANT cette passe (``constraints/__init__`` step 0), donc
    ``model.shared_block_case_bvars`` (``(venue, "jour:HH:MM") -> [(membres, b)]``) EXISTE ici :
      * un membre sans coach PROPRE disponible, mais dont l'UNION du bloc a un coach disponible sur
        la case, n'y est QUE via le bloc → ``x <= Σ b`` (``b`` des blocs du membre sur cette case) au
        lieu d'une fermeture dure (le bloc emprunte le coach libre d'un autre membre) ;
      * un bloc dont l'UNION n'a AUCUN coach disponible sur la case → ``b == 0`` (une séance de bloc
        sans coach n'existe pas), posé avec une littérale assumable pour que le noyau
        d'infaisabilité nomme la cause (patron ``_assume``/``_record_closure``).
    Hors bloc (relais vide) : la case reste fermée, comme une équipe seule sans aucun coach libre.

    La question « cette case a-t-elle un coach requis disponible pour cet ensemble d'équipes ? » a
    une seule maison (``..availability``) — réutilisée par le diagnostic de bloc (PR B).
    """
    rules: Mapping[Any, Any] = coach_unavailability if isinstance(coach_unavailability, Mapping) else {}
    coach_map = team_coach_map or {}
    # P4-99 — coach → [{constraint_id, label, intervals}] (posé sur le modèle par `_solve`).
    # La cause est MESURÉE : on retient QUEL intervalle ferme le créneau et on remonte à SA
    # contrainte — jamais la « première venue ». L'arité `(day, from, to)` de `rules` (union
    # consommée par validate_assignments) NE change pas ; `intervals` vit dans la carte parallèle.
    sources: Mapping[str, Any] = getattr(model, "coach_unavailability_sources", {}) or {}
    # Cases de bloc posées au step 0 (modèle nu des tests de pose ⇒ absente ⇒ {} ⇒ chemin solo).
    case_bvars: Mapping[tuple[str, str], list[tuple[frozenset[str], Any]]] = (
        getattr(model, "shared_block_case_bvars", {}) or {}
    )
    added = 0
    for assignment in assignments:
        intrinsic = assignment.coach_unavailable
        day, start = _assignment_day_start(assignment)
        end = None if assignment.fixed else assignment.end
        # (coach_id, source) de chaque contrainte dont un intervalle ferme cette séance :
        # ce sont TOUTES celles qui ferment réellement ce créneau (même règle que day_forbidden).
        matched_sources: list[tuple[str, dict[str, Any]]] = []
        first_matched_coach: str | None = None
        # Q4B — la case n'est fermée par l'indispo QUE si AUCUN coach requis n'est libre (TOUS
        # bloqués). `available_coach_exists` répond « un coach suffit » (liste vide ⇒ aucune
        # fermeture, patron historique « sans coach connu »).
        all_required_blocked = False
        if not intrinsic and rules and day is not None and start is not None:
            coach_ids = coach_map.get(str(assignment.team_id))
            if not coach_ids:
                single = assignment.coach_id
                coach_ids = [str(single)] if single is not None else []
            all_required_blocked = bool(coach_ids) and not available_coach_exists(coach_ids, day, start, rules, end)
            if all_required_blocked:
                # Tous bloqués : CHAQUE coach ferme le créneau → tous sont des causes mesurées.
                for cid in coach_ids:
                    cid_str = str(cid)
                    if first_matched_coach is None:
                        first_matched_coach = cid_str
                    for src in sources.get(cid_str) or []:
                        if any(
                            iv_day == day and interval_bites(iv_from, iv_to, start, end)
                            for iv_day, iv_from, iv_to in (src.get("intervals") or ())
                        ):
                            matched_sources.append((cid_str, src))
        if intrinsic or all_required_blocked:
            # SÉANCE DE BLOC — un membre sans coach propre dispo n'y est QUE via le bloc : x <= Σb
            # (le bloc emprunte le coach libre d'un autre membre). Pas de fermeture dure ; si aucun
            # bloc n'est actif sur la case, Σb = 0 ⇒ x = 0 (équivalent fermeture). Jamais pour une
            # indispo intrinsèque (drapeau legacy per-assignment, byte-identique).
            relay_bvars = [] if intrinsic else _relay_block_vars(assignment, case_bvars)
            if relay_bvars:
                model.Add(assignment.var <= sum(relay_bvars))
                added += 1
                continue
            # P4-96 — hypothèque la fermeture sur la littérale de la (première) règle d'indispo qui
            # la provoque ; toutes les fermetures d'une même règle PARTAGENT sa littérale, son nom
            # rejoint donc le noyau d'infaisabilité (cœur du besoin « coach indispo vendredi »). Une
            # indispo intrinsèque / sans source identifiée reste inconditionnelle (literal None).
            unavail_literal = None
            if matched_sources:
                primary_coach, primary_src = matched_sources[0]
                unavail_literal = _assume(
                    model,
                    _source_key("coach_unavail", primary_src.get("constraint_id"), primary_coach),
                    {
                        "kind": "coach_unavailability",
                        "coachId": primary_coach,
                        "constraintId": primary_src.get("constraint_id"),
                        "label": primary_src.get("label"),
                    },
                )
            closure = model.Add(assignment.var == 0)
            if unavail_literal is not None:
                closure.OnlyEnforceIf(unavail_literal)
            added += 1
            if matched_sources:
                # Une cause PAR contrainte qui ferme le créneau — plusieurs sont vraies quand
                # deux règles couvrent le même moment (mesuré, jamais deviné).
                for cid_str, src in matched_sources:
                    _record_closure(
                        model,
                        assignment.var,
                        {
                            "kind": "coach_unavailability",
                            "coachId": cid_str,
                            "constraintId": src.get("constraint_id"),
                            "label": src.get("label"),
                            **_measured_cut(src, day, start, end),
                        },
                    )
            else:
                # Bloqué mais aucune source identifiable (indispo intrinsèque, ou carte absente) :
                # `constraintId` null honnête + le coach s'il est connu — jamais un id faux.
                cause: dict[str, Any] = {"kind": "coach_unavailability"}
                if first_matched_coach is not None:
                    cause["coachId"] = first_matched_coach
                _record_closure(model, assignment.var, cause)
    # SÉANCE DE BLOC — un bloc dont l'UNION n'a aucun coach dispo sur la case ne peut y tenir séance.
    free_seats = {
        (str(a.team_id), str(a.venue_id), str(a.slot_id)) for a in assignments if not a.fixed and a.slot_id is not None
    }
    added += _close_coachless_block_cases(model, case_bvars, rules, coach_map, free_seats)
    return added


def _measured_cut(source: Mapping[str, Any], day: int | None, start: int | None, end: int | None) -> dict[str, Any]:
    """L'intervalle de cette source qui ferme la séance, et la case, pour le diagnostic post-solve.
    Interne : ``_collect_session_causes`` ne sérialise jamais ces deux clés. Celui qui commence PENDANT la
    séance passe d'abord : c'est lui qu'une saisie trop tôt peut corriger."""
    if day is None or start is None or end is None:
        return {}
    biting = sorted(
        (int(iv_from), int(iv_to))
        for iv_day, iv_from, iv_to in (source.get("intervals") or ())
        if iv_day == day and interval_bites(iv_from, iv_to, start, end)
    )
    if not biting:
        return {}
    interval = next((iv for iv in biting if start < iv[0]), biting[0])
    return {"interval": interval, "case": (start, end)}


def _relay_block_vars(
    assignment: AssignmentVariable,
    case_bvars: Mapping[tuple[str, str], list[tuple[frozenset[str], Any]]],
) -> list[Any]:
    """Les variables de décision ``b`` des blocs qui CONTIENNENT l'équipe de ``assignment`` sur SA
    case ``(venue, slot_id)``. Vide si l'équipe n'est membre d'aucun bloc présent sur cette case
    (chemin solo, byte-identique)."""
    if not case_bvars:
        return []
    key = (str(assignment.venue_id), str(assignment.slot_id))
    team = str(assignment.team_id)
    return [b for members, b in case_bvars.get(key, ()) if team in members]


def _close_coachless_block_cases(
    model: Any,
    case_bvars: Mapping[tuple[str, str], list[tuple[frozenset[str], Any]]],
    rules: Mapping[Any, Any],
    coach_map: dict[str, list[str]],
    free_seats: set[tuple[str, str, str]],
) -> int:
    """Force ``b == 0`` pour tout bloc dont l'UNION des coachs requis n'a AUCUN coach disponible sur
    sa case : une séance de bloc sans coach n'existe pas. La fermeture est hypothéquée sur une
    littérale assumable (patron ``_assume``) pour que le noyau d'infaisabilité NOMME la cause, et
    enregistrée (``_record_closure``) — neutre sur le solve nominal (``_assume`` renvoie ``None`` ⇒
    ``b == 0`` inconditionnel). Carte vide ⇒ aucune pose (chemin byte-identique, goldens).

    Case à pourvoir (un membre y a une variable libre) : jugée au chevauchement, comme la pose. Case où
    tous les membres sont déjà là (verrous, base figée) : jugée au début, sinon ``Σb >= 1`` du comblement
    contredirait ``b == 0`` et un groupe réservé rendrait la génération infaisable."""
    durations: Mapping[Any, int] = getattr(model, "slot_durations", {}) or {}
    added = 0
    for (venue_id, slot_id), entries in case_bvars.items():
        day, start = case_day_start(slot_id)
        if day is None or start is None:
            continue
        for members, b in entries:
            duration = durations.get((venue_id, day, _format_time(start)))
            has_free_seat = any((team, venue_id, slot_id) in free_seats for team in members)
            end = start + duration if duration is not None and has_free_seat else None
            if case_has_available_coach(members, day, start, rules, coach_map, end):
                continue
            cause: dict[str, Any] = {"kind": "coach_unavailability"}
            literal = _assume(model, _source_key("coach_unavail_block", None, f"{venue_id}:{slot_id}"), cause)
            closure = model.Add(b == 0)
            if literal is not None:
                closure.OnlyEnforceIf(literal)
            _record_closure(model, b, dict(cause))
            added += 1
    return added
