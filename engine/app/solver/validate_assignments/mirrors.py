from __future__ import annotations

from typing import Any

from app.solver.constraints import MANDATORY, ResolvedImplicitRules, build_travel_matrix, team_share_declared_pairs
from app.solver.constraints.travel import TravelPlacement, iter_travel_pairs_from_placements, required_gap
from app.solver.model import _format_time

# Libellés FR des jours (1 = lundi … 7 = dimanche), pour NOMMER la case rompue d'un bloc dans le
# message du verdict sans dépendre du paquet ``result_builder`` (qui a son propre ``_day_label``).
_DAY_LABELS_FR = {1: "lundi", 2: "mardi", 3: "mercredi", 4: "jeudi", 5: "vendredi", 6: "samedi", 7: "dimanche"}


def _shared_block_move_violation(
    shared_blocks: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    ref_cases_by_team: dict[str, set[tuple[str, int, str]]],
    team_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-51 (D11) — DEUX refus NOMMÉS, miroir déterministe de ``Σb == commonSessions`` dans SES DEUX
    sens (patron ``_venue_minimum_move_violation``, anti-enfermement inclus) :

      * ``shared_block_broken`` — un déplacement RETIRE une équipe d'une séance de BLOC jusque-là
        honorée (l'état FINAL a MOINS de séances communes que ``commonSessions``) ;
      * ``shared_block_overformed`` — un déplacement FORME une séance commune de TROP (l'état FINAL
        en a PLUS que ``commonSessions``). Sans ce maillon, la case tombait sur le message
        passe-partout : le pré-check de capacité fond le bloc en UN occupant (il ne crie donc pas
        ``venue_capacity``), mais le solveur, à court de budget ``b`` (``Σb == commonSessions``), est
        contraint de poser ``b = 1`` sur la case en trop pour tenir la capacité → INFEASIBLE que
        ``diagnose_candidate_conflicts`` ne savait pas attribuer → ``unknown_hard_conflict``. Ce
        miroir le NOMME. Symétrique du plafond (c) côté écriture (``ReservationGroupOccupancy``).

    Le HARD posé dans ``_apply_hard`` (``add_shared_block_constraints``) NE SUFFIT PAS pour le refus
    de RUPTURE : les variables de l'ancienne case restent LIBRES → le solveur réinvente la séance de
    bloc ailleurs pour tenir ``Σb == commonSessions`` et conclut « valide » à tort (même faille
    qu'ENG-36 / la mutualisation / le plancher de gymnase). On juge donc l'ÉTAT FINAL proposé, de
    façon déterministe : c'est le miroir de la contrainte.

    ⚠ N déplacements jugés ENSEMBLE, sur l'état FINAL (P2-51 PR-5b) — c'est le CŒUR du rail
    « déplacer le bloc ». « avant » = baseline gelée (elle EXCLUT déjà les N sources) + chaque
    source ré-ajoutée à SA case d'origine (``ref_cases_by_team``) ; « après » = baseline + les N
    candidats à leurs cases cibles. Déplacer les 2 membres d'un bloc vers la MÊME case le laisse
    HONORÉ (le bloc s'y reconstitue) — le refus séquentiel (juger t1 seul verrait le bloc rompu)
    est précisément ce qu'il faut ÉVITER. En retirer UN SEUL le casse → refus.

    ⚠ Une équipe peut être déplacée PLUSIEURS fois dans le MÊME lot (ses deux séances bougent) :
    on raisonne donc en ENSEMBLES de cases par équipe — TOUTES ses références ré-ajoutées pour
    « avant », TOUS ses candidats pour « après ». Un ``dict`` « une case par équipe » (dernière
    gagne) perdait ses autres cases et déclarait le bloc rompu à tort (le candidat co-localisé
    avec le partenaire disparaissait). C'est le bug corrigé ici.

    ⚠ GARDE ANTI-ENFERMEMENT (leçon P4-152) : un bloc DÉJÀ cassé dans la baseline ne bloque pas
    les déplacements. On ne refuse QUE si le bloc était HONORÉ avant (≥ commonSessions cases
    communes) et CESSE de l'être après. On n'évalue QUE les blocs dont AU MOINS une équipe
    DÉPLACÉE est membre.

    Message français nommant les équipes du bloc, le nombre de séances communes exigé, la case
    rompue ; aucun identifiant interne. ``None`` si rien à dire."""
    if not shared_blocks:
        return None

    moved_teams = {str(m["team_id"]) for m in moved}
    # ENSEMBLES de cases (toutes les cibles d'une équipe déplacée N fois), pas une seule case.
    cand_cases_by_team: dict[str, set[tuple[str, int, str]]] = {}
    for m in moved:
        cand_cases_by_team.setdefault(str(m["team_id"]), set()).add(
            (str(m["venue_id"]), int(m["day"]), str(m["start_time"]))
        )

    # équipe -> cases (gymnase, jour, heure) occupées dans la baseline GELÉE (sources exclues).
    base_occupancy: dict[str, set[tuple[str, int, str]]] = {}
    for slot in baseline_slots:
        base_occupancy.setdefault(str(slot["team_id"]), set()).add(
            (str(slot["venue_id"]), int(slot["day"]), str(slot["start_time"]))
        )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    def _venue_name(venue_id: str) -> str:
        return venue_names.get(venue_id) or venue_id

    def _common(members: list[str], *, use_reference: bool) -> set[tuple[str, int, str]]:
        # Cases où TOUS les membres sont ensemble ; pour CHAQUE équipe déplacée, TOUTES ses cases
        # (références ré-ajoutées pour « avant », candidats pour « après ») sont AJOUTÉES à sa
        # baseline gelée. Un membre déplacé qui REJOINT une séance baseline non déplacée de son
        # partenaire reforme ainsi la commune — l'intersection baseline+candidat la compte.
        sets: list[set[tuple[str, int, str]]] = []
        for member in members:
            occ = set(base_occupancy.get(member, set()))
            if member in moved_teams:
                cases = ref_cases_by_team.get(member) if use_reference else cand_cases_by_team.get(member)
                occ |= cases or set()
            sets.append(occ)
        return set.intersection(*sets) if sets else set()

    for block in shared_blocks:
        members = [str(t) for t in (block.get("teamIds") or block.get("team_ids") or [])]
        if len(members) < 2 or not (moved_teams & set(members)):
            continue
        common_sessions = int(block.get("commonSessions") or block.get("common_sessions") or 0)
        before = _common(members, use_reference=True)
        after = _common(members, use_reference=False)
        if len(before) >= common_sessions and len(after) < common_sessions:
            offender = next(m for m in moved if str(m["team_id"]) in members)
            named = ", ".join(_team_name(member) for member in members)
            broken = sorted(before - after)
            where = ""
            if broken:
                b_venue, b_day, b_start = broken[0]
                where = f" (séance commune du {_DAY_LABELS_FR[b_day] if 1 <= b_day <= 7 else f'jour {b_day}'} à {str(b_start)[:5]} au gymnase {_venue_name(b_venue)})"
            return {
                "rule": "shared_block_broken",
                "message": (
                    f"Ce déplacement casse le bloc de mutualisation : les équipes {named} doivent "
                    f"partager {common_sessions} séance(s) commune(s) en bloc{where}, or retirer "
                    f"{_team_name(str(offender['team_id']))} de sa séance n'en laisserait plus que {len(after)}."
                ),
                "team_id": str(offender["team_id"]),
                "venue_id": str(offender["venue_id"]),
                "day_of_week": int(offender["day"]),
                "start_time": str(offender["start_time"]),
            }
        # SUR-FORMATION — l'état FINAL formerait PLUS de séances communes que déclaré. Anti-enfermement
        # symétrique : on n'accuse QUE si le bloc n'était pas DÉJÀ au-dessus (``before <= commonSessions``).
        if len(before) <= common_sessions and len(after) > common_sessions:
            offender = next(m for m in moved if str(m["team_id"]) in members)
            named = ", ".join(_team_name(member) for member in members)
            formed = sorted(after - before)
            where = ""
            if formed:
                f_venue, f_day, f_start = formed[0]
                where = f" (nouvelle séance commune du {_DAY_LABELS_FR[f_day] if 1 <= f_day <= 7 else f'jour {f_day}'} à {str(f_start)[:5]} au gymnase {_venue_name(f_venue)})"
            return {
                "rule": "shared_block_overformed",
                "message": (
                    f"Ce déplacement formerait une séance commune de trop : les équipes {named} doivent "
                    f"partager {common_sessions} séance(s) commune(s) en bloc, or ce placement en "
                    f"formerait {len(after)}{where}."
                ),
                "team_id": str(offender["team_id"]),
                "venue_id": str(offender["venue_id"]),
                "day_of_week": int(offender["day"]),
                "start_time": str(offender["start_time"]),
            }
    return None


def _team_link_move_violation(
    team_links: list[dict[str, Any]],
    shared_blocks: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    team_names: dict[str, str],
) -> dict[str, Any] | None:
    """Lot PASSERELLES PR-2 — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement CRÉE un
    chevauchement sur une passerelle ``MANDATORY`` (patron ``_shared_block_move_violation``).

    On juge l'ÉTAT FINAL proposé (baseline gelée + les N candidats) de façon déterministe : deux
    séances de deux équipes passerelées MANDATORY se chevauchent-elles (même jour, intervalles
    intersectés, cross-gymnase compris — doctrine n°2) ? On ne refuse QUE si le chevauchement
    IMPLIQUE au moins un candidat (créé/aggravé par le déplacement) — deux séances déjà présentes
    dans la baseline ne sont jamais imputées au geste (anti-enfermement). ⚠ N déplacements jugés
    ENSEMBLE (P2-51 PR-5b) : un chevauchement créé entre les séances de DEUX équipes déplacées est
    vu, pas seulement candidat-contre-baseline. EXEMPTION : même case (gymnase, jour, heure) ET les
    deux équipes partagent un groupe/bloc déclaré. Le HARD posé dans ``_apply_hard`` rendrait bien
    le solve INFEASIBLE, mais ``diagnose_candidate_conflicts`` ne saurait pas l'attribuer — ce
    miroir le NOMME avant le solve.

    Message français nommant les deux équipes ; aucun identifiant interne. ``None`` si rien à dire.
    """
    mandatory = [link for link in team_links if str(link.get("intensity") or "PREFERRED") == MANDATORY]
    if not mandatory:
        return None

    moved_teams = {str(m["team_id"]) for m in moved}
    share_pairs = team_share_declared_pairs(shared_blocks)

    # état FINAL par équipe : baseline gelée + candidats. Le 5e champ marque une séance CANDIDATE
    # (créée par le déplacement) — un chevauchement n'est imputé au geste que s'il en implique une.
    by_team: dict[str, list[tuple[int, int, int, str, bool]]] = {}
    for slot in baseline_slots:
        by_team.setdefault(str(slot["team_id"]), []).append(
            (int(slot["start"]), int(slot["end"]), int(slot["day"]), str(slot["venue_id"]), False)
        )
    for m in moved:
        by_team.setdefault(str(m["team_id"]), []).append(
            (int(m["start"]), int(m["end"]), int(m["day"]), str(m["venue_id"]), True)
        )

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    for link in mandatory:
        team_a = str(link.get("teamAId") or link.get("team_a_id") or "")
        team_b = str(link.get("teamBId") or link.get("team_b_id") or "")
        if team_a == team_b or not team_a or not team_b or not (moved_teams & {team_a, team_b}):
            continue
        share_declared = frozenset({team_a, team_b}) in share_pairs
        for a_start, a_end, a_day, a_venue, a_cand in by_team.get(team_a, []):
            for b_start, b_end, b_day, b_venue, b_cand in by_team.get(team_b, []):
                if a_day != b_day or not (a_start < b_end and b_start < a_end):
                    continue
                if a_venue == b_venue and a_start == b_start and share_declared:
                    continue  # séance mutualisée déclarée : chevauchement volontaire, autorisé.
                if not (a_cand or b_cand):
                    continue  # chevauchement PRÉEXISTANT (baseline seule) : jamais imputé au geste.
                # Nommer le côté DÉPLACÉ (celui dont le candidat crée le conflit) en premier.
                culprit = team_a if a_cand else team_b
                other = team_b if a_cand else team_a
                offender = next(m for m in moved if str(m["team_id"]) == culprit)
                return {
                    "rule": "team_link_broken",
                    "message": (
                        f"Ce déplacement fait chevaucher {_team_name(culprit)} et {_team_name(other)}, "
                        "déclarées en passerelle obligatoire : elles partagent des joueurs et ne peuvent "
                        "pas s'entraîner en même temps."
                    ),
                    "team_id": culprit,
                    "venue_id": str(offender["venue_id"]),
                    "day_of_week": int(offender["day"]),
                    "start_time": str(offender["start_time"]),
                }
    return None


def _travel_time_move_violation(
    venue_travel_times: list[dict[str, Any]],
    resolved_rules: ResolvedImplicitRules,
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    coaches: list[dict[str, Any]],
    team_coach_map: dict[str, list[str]],
    coach_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P2-55 (ENG-36) — refus NOMMÉ (MIROIR MANDATORY) quand un déplacement crée un enchaînement au
    battement trop court pour le coach (patron ``_team_link_move_violation``).

    Ne s'arme que sous ``travelTime`` MANDATORY, matrice présente. On juge l'ÉTAT FINAL (baseline
    gelée + les N candidats) de façon déterministe, en RÉUTILISANT le prédicat géométrique de
    ``travel.py`` (``iter_travel_pairs_from_placements`` — gap/barème JAMAIS recalculés ici, résorbe
    ENG-37 côté verdict) : un enchaînement cross-gymnase du MÊME coach dont l'écart est plus court
    que le barème (voiture/à pied selon ``isVehicled``) et qui IMPLIQUE au moins un candidat → refus.
    ⚠ N déplacements jugés ENSEMBLE (P2-51 PR-5b) : un enchaînement trop serré créé par le
    déplacement de DEUX équipes DIFFÉRENTES d'un même coach est vu (les deux séances sont
    candidates). Le HARD posé dans ``_apply_hard`` rendrait bien le solve INFEASIBLE, mais
    ``diagnose_candidate_conflicts`` ne saurait pas l'attribuer — ce miroir le NOMME (motif
    ``travel_time_infeasible``, aligné sur le diagnostic du rail ``/generate``).

    Message français nommant le coach + les deux gymnases/heures ; aucun identifiant interne dans le
    texte. ``None`` si rien à dire."""
    if not (resolved_rules.travel_time_active and resolved_rules.travel_time_intensity == MANDATORY):
        return None
    matrix = build_travel_matrix(venue_travel_times)
    if not matrix:
        return None

    placements_by_team: dict[str, list[TravelPlacement]] = {}
    for slot in baseline_slots:
        placements_by_team.setdefault(str(slot["team_id"]), []).append(
            (int(slot["start"]), int(slot["end"]), int(slot["day"]), str(slot["venue_id"]), None)
        )
    # Chaque candidat pose SA séance ; on garde l'identité (``id``) pour distinguer, dans les paires
    # énumérées, celles qui IMPLIQUENT un déplacement de celles déjà présentes dans la baseline.
    placement_by_moved_index: list[TravelPlacement] = []
    for m in moved:
        placement: TravelPlacement = (
            int(m["start"]),
            int(m["end"]),
            int(m["day"]),
            str(m["venue_id"]),
            None,
        )
        placements_by_team.setdefault(str(m["team_id"]), []).append(placement)
        placement_by_moved_index.append(placement)
    candidate_ids = {id(p) for p in placement_by_moved_index}
    moved_by_placement_id = {id(p): moved[i] for i, p in enumerate(placement_by_moved_index)}

    for traveler_key, gap, barometer, pa, pb in iter_travel_pairs_from_placements(
        placements_by_team,
        coaches=coaches,
        team_links=(),  # miroir cadré au voyageur COACH (arbitrage P2-55) : passerelle hors champ.
        team_coach_map=team_coach_map,
        matrix=matrix,
        default_minutes=resolved_rules.travel_time_default_minutes,
    ):
        if gap >= required_gap(barometer, resolved_rules.travel_time_tolerance_minutes):
            continue  # battement suffisant (toléré compris) : la pose ne poserait rien ici non plus.
        if id(pa) not in candidate_ids and id(pb) not in candidate_ids:
            continue  # enchaînement PRÉEXISTANT (baseline seule) : jamais imputé au déplacement.
        coach_id = traveler_key.split(":", 1)[1]
        first, second = (pa, pb) if pa[0] <= pb[0] else (pb, pa)
        # Attribuer à un candidat impliqué (le déplacé) : c'est lui que l'UI surligne.
        culprit = pa if id(pa) in candidate_ids else pb
        offender = moved_by_placement_id[id(culprit)]
        coach_label = coach_names.get(coach_id) or "Le coach"
        first_venue = venue_names.get(first[3]) or first[3]
        second_venue = venue_names.get(second[3]) or second[3]
        return {
            "rule": "travel_time_infeasible",
            "message": (
                f"{coach_label} enchaînerait {first_venue} à {_format_time(first[0])} puis "
                f"{second_venue} à {_format_time(second[0])} : le battement est trop court pour "
                "rejoindre le gymnase suivant."
            ),
            "coach_id": coach_id,
            "team_id": str(offender["team_id"]),
            "venue_id": str(offender["venue_id"]),
            "day_of_week": int(offender["day"]),
            "start_time": str(offender["start_time"]),
        }
    return None


def _venue_minimum_move_violation(
    venue_minimums: list[dict[str, Any]],
    baseline_slots: list[dict[str, Any]],
    moved: list[dict[str, Any]],
    ref_cases_by_team: dict[str, set[tuple[str, int, str]]],
    team_names: dict[str, str],
    venue_names: dict[str, str],
) -> dict[str, Any] | None:
    """P4-152 — refus NOMMÉ (MIROIR DÉTERMINISTE) quand un déplacement fait passer une équipe SOUS
    son plancher « au moins N séances au gymnase V » (patron ``_travel_time_move_violation``).

    Le HARD posé dans ``_apply_hard`` ne peut PAS refuser ce déplacement à lui seul : les autres
    créneaux du modèle restent libres, le solveur place une séance fantôme ailleurs à V pour tenir
    ``sum >= N`` et conclut « valide » à tort (même faille que la mutualisation). On juge donc
    l'ÉTAT CONCRET, de façon déterministe.

    ⚠ N déplacements (P2-51 PR-5b) : le plancher d'une équipe ne dépend QUE de ses propres séances,
    on évalue donc CHAQUE équipe déplacée indépendamment sur la baseline gelée (qui exclut déjà les
    N sources). L'état « avant » d'une équipe déplacée = baseline + TOUTES ses sources ré-ajoutées
    (``ref_cases_by_team``) ; « après » = baseline + TOUS ses candidats. On raisonne en ENSEMBLES de
    cases (comme ``_shared_block_move_violation``) : une équipe peut être déplacée PLUSIEURS fois dans
    le MÊME lot (ses deux séances bougent), et un ``dict`` « une case par équipe » (dernière gagne)
    lui faisait PERDRE une case — le compte à V, avant comme après, était faux d'une unité.

    ⚠ LE PLANNING DÉJÀ EN INFRACTION : on ne refuse QUE si le plancher était SATISFAIT AVANT le
    déplacement et cesse de l'être APRÈS. Si le plancher était DÉJÀ cassé (``current < N``), le
    déplacement n'en est pas la cause : on laisse passer — sans quoi le gestionnaire serait ENFERMÉ,
    incapable de corriger un planning généré avant la contrainte ou amputé d'un créneau (condition
    d'arrêt fondateur : jamais un blocage total).

    Message français nommant le gymnase, l'équipe, le plancher exigé et l'état résultant ; aucun
    identifiant interne. ``None`` si rien à dire."""
    if not venue_minimums:
        return None

    # ENSEMBLES de cases candidates par équipe (toutes les cibles d'une équipe déplacée N fois) +
    # un représentant par équipe pour NOMMER le geste (coordonnées du refus). Le COMPTE, lui, se
    # fait sur TOUTES les cases — jamais une seule.
    cand_cases_by_team: dict[str, set[tuple[str, int, str]]] = {}
    moved_repr: dict[str, dict[str, Any]] = {}
    for m in moved:
        team = str(m["team_id"])
        cand_cases_by_team.setdefault(team, set()).add((str(m["venue_id"]), int(m["day"]), str(m["start_time"])))
        moved_repr.setdefault(team, m)

    # Nombre de séances de chaque (équipe, gymnase) dans la baseline GELÉE — elle exclut déjà les
    # sources des déplacements (MoveSlotService.baselineWithoutSiblings). Les séances HARD-verrouillées
    # sont comptées : elles créditent le plancher (parité ``add_venue_minimum_constraints``).
    base_count: dict[tuple[str, str], int] = {}
    for slot in baseline_slots:
        key = (str(slot["team_id"]), str(slot["venue_id"]))
        base_count[key] = base_count.get(key, 0) + 1

    def _team_name(team_id: str) -> str:
        return team_names.get(team_id) or team_id

    def _venue_name(venue_id: str) -> str:
        return venue_names.get(venue_id) or venue_id

    for rule in venue_minimums:
        team_id = str(rule.get("scope_target_id"))
        venue_id = str(rule.get("venue_id"))
        minimum = int(rule.get("min") or 1)
        moved_slot = moved_repr.get(team_id)
        if moved_slot is None:
            continue  # un déplacement ne touche que les comptes des équipes déplacées.

        base_at_venue = base_count.get((team_id, venue_id), 0)
        # « avant » = baseline + TOUTES les cases d'origine de l'équipe à ce gymnase ; « après » =
        # baseline + TOUS ses candidats à ce gymnase. Une équipe déplacée deux fois compte ses deux.
        current_at_venue = base_at_venue + sum(1 for c in ref_cases_by_team.get(team_id) or () if c[0] == venue_id)
        final_at_venue = base_at_venue + sum(1 for c in cand_cases_by_team.get(team_id) or () if c[0] == venue_id)
        if current_at_venue >= minimum and final_at_venue < minimum:
            return {
                "rule": "venue_minimum_infeasible",
                "message": (
                    f"Ce déplacement ferait passer {_team_name(team_id)} sous son minimum de séances "
                    f"à {_venue_name(venue_id)} : {minimum} séance(s) y sont exigée(s), or ce placement "
                    f"n'en laisserait plus que {final_at_venue}."
                ),
                "team_id": team_id,
                "venue_id": venue_id,
                "day_of_week": int(moved_slot["day"]),
                "start_time": str(moved_slot["start_time"]),
            }
    return None
