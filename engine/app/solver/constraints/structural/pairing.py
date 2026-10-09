"""Contraintes structurelles — briques « au plus un » et exemption de séance de bloc (sous-paquet P4-295 C5).

Extrait verbatim de l'ancien ``constraints/structural.py`` (découpe pure). Feuille du DAG du
sous-paquet : n'importe QUE les externes ``...model`` / ``..common`` (aucune arête vers un autre
sous-module). ``overlap`` s'appuie sur ces briques ; l'orchestrateur ``add_level_1_hard_constraints``
du paquet ``constraints/__init__`` atteint les posers via les ré-exports."""

from __future__ import annotations

from collections import defaultdict
from collections.abc import Iterable, Mapping
from typing import Any

from ...model import _format_time
from ..common import BoolVarLike, _dedupe_variables, _intervals_overlap, _record_closure

# Exemption coach-joueur sur la SÉANCE DE BLOC — carte ``(venue_id, slot_id)`` (slot_id ==
# "day:HH:MM") → ``[(frozenset(membres du bloc), b)]``, remplie par ``add_shared_block_constraints``
# et portée par le modèle (``ScheduleCpModel.shared_block_case_bvars``). ``None`` (modèle nu des
# tests de pose) ou vide (aucun bloc) ⇒ aucune exemption, borne stricte partout (byte-identique).
CaseBvars = Mapping[tuple[str, str], list[tuple[frozenset[str], BoolVarLike]]]


def _block_case_exemption_bvars(
    case_bvars: CaseBvars | None,
    team_a: str | None,
    team_b: str | None,
    venue: str | None,
    slot_id: str,
) -> list[BoolVarLike]:
    """Les ``b`` des blocs qui, sur la case ``(venue, slot_id)``, réunissent À LA FOIS ``team_a`` et
    ``team_b``. L'appelant relâche alors l'anti-chevauchement de la paire en ``≤ 1 + Σb`` : la borne
    s'efface QUAND la séance de bloc est active (b=1), et RESTE stricte sinon (b=0). La garde de
    distinctness ``Σb ≤ 1`` par membre-et-case (``add_shared_block_constraints``) borne cette somme à
    1 pour une paire donnée. Carte absente/vide, gymnase inconnu (None) ou paire hors de tout bloc de
    la case ⇒ liste vide ⇒ borne stricte inchangée."""
    if not case_bvars or team_a is None or team_b is None or venue is None:
        return []
    pair = {team_a, team_b}
    return [b for members, b in case_bvars.get((venue, slot_id), ()) if pair <= members]


def _add_coach_player_time_key_pairs(
    model: Any,
    coach_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]],
    player_groups: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None, str | None]]],
    case_bvars: CaseBvars,
) -> int:
    """Version PAIRE À PAIRE de l'at-most-one clé-temps de la contrainte 3, avec exemption bloc.

    N'est empruntée QUE lorsqu'un bloc existe (``case_bvars`` non vide). La clé de groupe reste
    ``(personne, slot_id)`` SANS gymnase (le porter dans la clé serait le piège documenté de
    ``_add_cross_venue_at_most_one``) ; ``slot_id`` == le ``time_key`` de la clé (== "day:HH:MM").
    Chaque paire de la MÊME case (même gymnase non-None, même slot_id) dont les deux équipes
    partagent un bloc de la case voit sa borne passer à ``≤ 1 + Σb`` (elle s'efface quand la séance
    de bloc est active) ; toute autre paire garde ``≤ 1`` — équivalent strict de l'at-most-one."""
    added = 0
    for key in coach_groups.keys() & player_groups.keys():
        _person, time_key = key
        entries = _dedupe_meta_entries(coach_groups[key] + player_groups[key])
        for i in range(len(entries)):
            var_a, venue_a, team_a = entries[i]
            for j in range(i + 1, len(entries)):
                var_b, venue_b, team_b = entries[j]
                if var_a is var_b:
                    continue
                bs = (
                    _block_case_exemption_bvars(case_bvars, team_a, team_b, venue_a, str(time_key))
                    if venue_a is not None and venue_a == venue_b
                    else []
                )
                if bs:
                    model.Add(var_a + var_b <= 1 + sum(bs))
                else:
                    model.Add(var_a + var_b <= 1)
                added += 1
    return added


def _dedupe_meta_entries(
    entries: list[tuple[BoolVarLike, str | None, str | None]],
) -> list[tuple[BoolVarLike, str | None, str | None]]:
    """Comme ``_dedupe_variables`` mais sur des entrées ``(var, gymnase, équipe)`` : garde la première
    occurrence de chaque variable (une personne coach ET joueuse de la MÊME séance y apparaît deux
    fois)."""
    seen: set[Any] = set()
    unique: list[tuple[BoolVarLike, str | None, str | None]] = []
    for entry in entries:
        var = entry[0]
        marker = var.Index() if hasattr(var, "Index") else id(var)
        if marker in seen:
            continue
        seen.add(marker)
        unique.append(entry)
    return unique


def _add_free_vs_locked_interval_conflicts(
    model: Any,
    free_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]],
    locked_occupations: dict[str, dict[int, list[tuple[int, int, str | None, str, str]]]],
    *,
    case_bvars: CaseBvars | None = None,
) -> int:
    """Force à 0 tout créneau LIBRE d'une personne qui chevauche une de ses occupations
    VERROUILLÉES, sous l'exemption D-14 et l'exemption SÉANCE DE BLOC (P4-97 bis).

    ``free_entries`` : ``person -> [(start, end, var, day, venue, role, team)]`` (le ``day`` est une
    chaîne, comme le produit ``_extract_interval``). ``locked_occupations`` :
    ``person -> weekday(int) -> [(start, end, venue, role, team)]`` (cf.
    ``_locked_person_day_occupations``).

    D-14 (arbitrage fondateur) : deux occupations **coach-coach dans le MÊME gymnase** ne
    s'opposent pas (le coach surveille deux groupes, présent une fois) ; tout le reste —
    gymnases différents, ou l'un des deux rôles ``player`` — est une impossibilité physique.
    Le verrou est souverain : on ne touche QUE le créneau libre, jamais le verrou.

    Exemption bloc : si le verrou et le créneau libre sont la MÊME case (même gymnase + même début)
    et que leurs deux équipes partagent un bloc de la case, la séance libre tient QUAND la séance de
    bloc est active — ``var ≤ Σb`` remplace ``var == 0``. Ce chemin réifié ne pose AUCUNE fermeture
    (le rail P4-99 ne porte que les fermetures inconditionnelles) ; le chemin inconditionnel garde
    sa cause ``hard_lock``.
    """
    added = 0
    for person, entries in free_entries.items():
        locked_days = locked_occupations.get(person)
        if not locked_days:
            continue
        for start, end, var, day, venue, role, team in entries:
            try:
                day_int = int(day)
            except (TypeError, ValueError):
                continue
            for l_start, l_end, l_venue, l_role, l_team in locked_days.get(day_int, ()):
                if not _intervals_overlap(start, end, l_start, l_end):
                    continue
                both_coaching = role == "coach" and l_role == "coach"
                if both_coaching and venue is not None and venue == l_venue:
                    continue
                bs = (
                    _block_case_exemption_bvars(case_bvars, team, l_team, venue, f"{day_int}:{_format_time(start)}")
                    if venue is not None and venue == l_venue and start == l_start
                    else []
                )
                if bs:
                    # Case de bloc RÉIFIÉE : la séance libre tient si la séance de bloc est active.
                    # PAS de fermeture ici (le rail P4-99 ne porte que l'inconditionnel).
                    model.Add(var <= sum(bs))
                    added += 1
                    continue
                model.Add(var == 0)
                added += 1
                # P4-99 — une occupation VERROUILLÉE de la personne rend ce créneau libre
                # impossible : cause hard_lock.
                _record_closure(model, var, {"kind": "hard_lock"})
                break
    return added


def _add_at_most_one_groups(model: Any, groups: Iterable[Iterable[BoolVarLike]]) -> int:
    added = 0
    for group in groups:
        variables = _dedupe_variables(group)
        if len(variables) < 2:
            continue
        if hasattr(model, "add_at_most_one"):
            model.add_at_most_one(variables)
        else:
            model.AddAtMostOne(variables)
        added += 1
    return added


def _add_cross_venue_at_most_one(
    model: Any,
    keyed_entries: dict[tuple[Any, Any], list[tuple[BoolVarLike, str | None]]],
) -> int:
    """``varA + varB <= 1`` pour toute paire de MÊME clé posée dans des gymnases DIFFÉRENTS.

    D-14 — remplace un `_add_at_most_one_groups` sur la clé `(coach, temps)`. Ajouter
    simplement le gymnase à cette clé serait le réflexe évident, et il est FAUX : deux
    gymnases différents tomberaient alors dans deux groupes séparés, chacun réduit à une
    variable, et plus rien ne les opposerait — on aurait autorisé le même gymnase en
    autorisant AUSSI ce qu'on voulait interdire. C'est
    `test_coach_on_two_venues_at_same_time_is_impossible` qui l'a rattrapé.

    D'où le passage en paires explicites : le gymnase reste hors de la clé, et c'est la
    COMPARAISON entre les deux membres qui décide. Un gymnase inconnu (None) ne vaut pas
    « même gymnase » — sans preuve de co-localisation, on garde la règle stricte.
    """
    added = 0
    for entries in keyed_entries.values():
        for i in range(len(entries)):
            var_a, venue_a = entries[i]
            for j in range(i + 1, len(entries)):
                var_b, venue_b = entries[j]
                if var_a is var_b:
                    continue
                if venue_a is not None and venue_a == venue_b:
                    continue
                model.Add(var_a + var_b <= 1)
                added += 1
    return added


def _add_interval_at_most_one(
    model: Any,
    person_entries: dict[str, list[tuple[int, int, BoolVarLike, str, str | None, str, str | None]]],
    *,
    same_venue_allowed: bool = False,
    case_bvars: CaseBvars | None = None,
) -> int:
    """Add pairwise ``varA + varB <= 1`` for overlapping intervals per person per day.

    Args:
        model: CP-SAT model.
        person_entries: ``dict[person_id, list[(start, end, var, day, venue, role, team)]]`` où
            ``role`` vaut ``"coach"`` ou ``"player"``.
        same_venue_allowed: quand True, deux intervalles qui se chevauchent **dans le même
            gymnase** ne sont PAS opposés — mais UNIQUEMENT si les deux entrées sont des
            rôles ``"coach"``. Voir D-14 ci-dessous.
        case_bvars: carte des séances de bloc par case. Quand deux intervalles partagent la MÊME
            case (même gymnase + même début) et que leurs équipes partagent un bloc de la case, la
            borne passe à ``≤ 1 + Σb`` : elle s'efface quand la séance de bloc est active. ``None`` /
            vide (chemin coach de la contrainte 2, ou aucun bloc) ⇒ borne stricte, byte-identique.

    Returns: number of pairwise constraints added.

    D-14 (arbitrage fondateur, 2026-08-09) — un coach PEUT tenir deux équipes en même temps
    dans le MÊME gymnase. « Matthieu coache les SM1 et les SM2, et le gestionnaire peut
    vouloir que les deux séances aient lieu simultanément. C'est rare mais c'est possible
    dans les petites structures. » Il est présent une fois et surveille deux groupes.

    ⚠ **L'exemption est réservée aux paires coach-coach**, et c'est pour cela que le rôle
    voyage avec l'entrée. Coacher et JOUER sont deux rôles, pas deux groupes surveillés :
    une même personne ne peut pas les tenir simultanément, même à trois mètres d'écart.

    ⚑ C'est le piège qui a failli passer. ``add_coach_player_non_overlap`` teste lui aussi
    TOUTES les combinaisons de rôles pour une même personne, **coach-coach comprise** : sa
    copie venue-blind continuait de rendre INFEASIBLE le cas Matthieu alors que la
    contrainte 2 l'avait dûment relâché. Relâcher un seul des deux sites ne relâche rien —
    seule la falsification l'a montré, la suite restait verte.

    Deux gymnases différents restent interdits dans tous les cas : impossibilité physique,
    pas choix de gestion.
    """
    added = 0
    for entries in person_entries.values():
        by_day: dict[str, list[tuple[int, int, BoolVarLike, str | None, str, str | None]]] = defaultdict(list)
        for start, end, var, day, venue, role, team in entries:
            by_day[day].append((start, end, var, venue, role, team))

        for day, day_entries in by_day.items():
            for i in range(len(day_entries)):
                a_start, a_end, var_a, a_venue, a_role, a_team = day_entries[i]
                for j in range(i + 1, len(day_entries)):
                    b_start, b_end, var_b, b_venue, b_role, b_team = day_entries[j]
                    if var_a is var_b:
                        continue
                    both_coaching = a_role == "coach" and b_role == "coach"
                    if same_venue_allowed and both_coaching and a_venue is not None and a_venue == b_venue:
                        continue
                    if not _intervals_overlap(a_start, a_end, b_start, b_end):
                        continue
                    bs = (
                        _block_case_exemption_bvars(
                            case_bvars, a_team, b_team, a_venue, f"{day}:{_format_time(a_start)}"
                        )
                        if a_venue is not None and a_venue == b_venue and a_start == b_start
                        else []
                    )
                    if bs:
                        model.Add(var_a + var_b <= 1 + sum(bs))
                    else:
                        model.Add(var_a + var_b <= 1)
                    added += 1
    return added
