"""Post-solve diagnostics: locked-slot violation and candidate-conflict reporting.

Imports ``..`` externals and ``.common`` (day labels/helpers, closure causes read
via ``model.candidate_closures``). Consumed by ``result_builder``/``main``."""

from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from typing import Any

from ..model import DEFAULT_SESSION_MINUTES, _time_to_minutes
from .common import (
    HARD,
    ResolvedImplicitRules,
    _day_int_set,
    _fold_case_occupant_identity,
    _get,
    _intervals_overlap,
    _not_honored_warning,
    _scalar_id,
)

_DAY_LABELS = ("lundi", "mardi", "mercredi", "jeudi", "vendredi", "samedi", "dimanche")


def _day_label(day: int) -> str:
    return _DAY_LABELS[day - 1] if 1 <= day <= 7 else f"jour {day}"


def _day_rules_union(
    windows: Iterable[Mapping[str, Any]],
) -> dict[str, tuple[dict[str, set[int]], list[Mapping[str, Any]]]]:
    """Les règles DAY UNIES par équipe, avec les contraintes qui les portent.

    L'union est la seule sémantique que le solveur applique : deux règles
    `allowedDays=[2]` et `allowedDays=[6]` autorisent mardi ET samedi une fois
    unies, alors qu'isolément chacune exclut le jour de l'autre. Les évaluer
    séparément accusait donc à tort.

    Mais l'union seule ne suffit pas à RENDRE COMPTE : le gestionnaire doit
    savoir QUELLE règle corriger. On garde donc les contraintes sources pour
    nommer, à l'émission, celles qui excluent effectivement le jour du verrou —
    un libellé synthétique « règles de jours de SM1 » ne correspond à rien dans
    son écran de contraintes.
    """
    union: dict[str, dict[str, set[int]]] = {}
    sources: dict[str, list[Mapping[str, Any]]] = {}
    for constraint in windows:
        if not _is_enforced_window(constraint):
            continue
        if str(constraint.get("family") or "").upper() != "DAY":
            continue
        team_id = str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "")
        if not team_id:
            continue
        config = constraint.get("config") or {}
        rules = union.setdefault(team_id, {"forbidden": set(), "allowed": set(), "forced": set()})
        rules["forbidden"].update(_day_int_set(config.get("forbiddenDays")))
        rules["allowed"].update(_day_int_set(config.get("allowedDays")))
        # `forcedDays` AUSSI : le solveur l'impose (`sum(forced_day_vars) >= 1`).
        # L'omettre laissait un verrou rendre cette exigence insatisfaisable sans
        # que ce diagnostic — dont c'est le rôle — n'en dise rien.
        rules["forced"].update(_day_int_set(config.get("forcedDays")))
        sources.setdefault(team_id, []).append(constraint)

    return {team_id: (rules, sources.get(team_id, [])) for team_id, rules in union.items()}


def _day_constraints_excluding(
    day: int, rules: Mapping[str, set[int]], sources: Iterable[Mapping[str, Any]]
) -> list[Mapping[str, Any]]:
    """Parmi les sources, celles qui excluent RÉELLEMENT ce jour.

    Une règle dont les `allowedDays` ne mentionnent pas le jour ne l'exclut que si
    aucune autre ne l'autorise — c'est l'union qui tranche. On ne nomme donc que
    les règles fautives une fois l'union appliquée : celles qui l'interdisent
    explicitement, et, si le jour est hors de la liste blanche unie, celles qui
    portent cette liste.
    """
    excluded_by_whitelist = bool(rules["allowed"]) and day not in rules["allowed"]
    guilty = []
    for constraint in sources:
        config = constraint.get("config") or {}
        if day in _day_int_set(config.get("forbiddenDays")) or (
            excluded_by_whitelist and _day_int_set(config.get("allowedDays"))
        ):
            guilty.append(constraint)

    return guilty


def _is_enforced_window(constraint: Mapping[str, Any]) -> bool:
    """La MÊME porte que `add_time_window_constraints`, au même endroit : avant tout
    parsing d'horaire. Sans elle on accuse une règle PREFERRED que le solveur
    n'applique jamais, et `_time_to_minutes` lève sur une valeur qu'aucun chemin
    d'exécution ne lit — transformant une génération qui passait en 500."""
    if not constraint.get("isActive", True):
        return False
    rule_type = constraint.get("ruleType") or constraint.get("rule_type")
    family = str(constraint.get("family") or "").upper()
    if rule_type == "PREFERRED" and family == "TIME":
        return False

    return rule_type in ("HARD", "LOCK") and family in ("TIME", "DAY")


def diagnose_locked_slot_violations(
    locked_slots: Iterable[Mapping[str, Any]],
    parsed: Mapping[str, Any],
    *,
    team_names: Mapping[str, str] | None = None,
    coach_names: Mapping[str, str] | None = None,
    venue_names: Mapping[str, str] | None = None,
) -> list[dict[str, Any]]:
    """Warn about the HARD constraints a HARD lock silently annuls (P2-9).

    A HARD lock is pre-placed OUTSIDE the solver: ``model.py`` never creates the
    ``x[team, venue, day, start]`` variable for it. Every constraint below works
    by forcing that variable to 0, so with no variable there is nothing to force
    — the lock doesn't *beat* the constraint, it makes it unreachable. Measured
    before this function existed: the same payload placed SM1 on Tuesday without
    a lock (coach off on Saturday, honoured) and on Saturday WITH one, with an
    empty ``diagnostics`` and a ``completed`` status.

    The lock stays sovereign — that is the founder's ALIGN-07 ruling, and it is
    not reopened here. What changes is the silence: the manager is told what his
    pin overrode, so he can decide. Hence INFO warnings, never errors.

    Scope is deliberately the constraints the manager ENTERED (coach
    availability, team time/day windows, forbidden AND forced venues). The
    structural rules a lock also bypasses — one coach in two gyms at once — are a
    different animal: they describe physical impossibility rather than a
    preference, so they block generation instead of warning, and land in their
    own change. ``venue_minimums`` is deliberately EXCLUDED: it is applied hard
    with only three outcomes (honored · INFEASIBLE→failed · unreachable→ERROR
    diagnostic), so it can never drift in silence — claiming to watch it here
    would be the very lie this docstring forbids.

    Mirrors the enforcement rules exactly (start-based interval match for
    coaches, min/max start for windows, team+venue pair for forbidden venues,
    team→imposed-venue mismatch for forced venues); any drift between the two
    would make this lie about what the solver did.
    """
    rules: Mapping[Any, Any] = parsed.get("coach_unavailability") or {}
    coach_map: Mapping[str, Any] = parsed.get("team_coach_map") or {}
    windows = parsed.get("time_windows") or ()
    forbidden = parsed.get("forbidden_assignments") or ()
    forced_venues: Mapping[str, Any] = parsed.get("forced_venues") or {}

    def _team(team_id: str) -> str:
        return (team_names or {}).get(team_id) or team_id

    def _coach(coach_id: str) -> str:
        return (coach_names or {}).get(coach_id) or coach_id

    def _venue(venue_id: str) -> str:
        return (venue_names or {}).get(venue_id) or venue_id

    day_union = _day_rules_union(windows)

    warnings: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str]] = set()

    def _emit(constraint: Mapping[str, Any], team_id: str, lock_id: str, message: str) -> None:
        # Clé (contrainte, équipe, VERROU) : deux verrous distincts qui violent la
        # même règle sont deux choses à corriger, et n'en montrer qu'une laisserait
        # le gestionnaire croire son planning réparé après un seul geste.
        # (La justification précédente — « un verrou couvre plusieurs départs de
        # 30 min » — était fausse : `locked_slots` porte UNE entrée par verrou,
        # la déduplication par `lock_key` ayant déjà eu lieu dans `model.py`.)
        key = (str(constraint.get("id")), team_id, lock_id)
        if key in seen:
            return
        seen.add(key)
        warning = _not_honored_warning(dict(constraint), "INFO", message)
        warning["teamId"] = team_id
        warnings.append(warning)

    for slot in locked_slots:
        team_id = str(slot.get("team_id") or slot.get("teamId") or "")
        venue_id = str(slot.get("venue_id") or slot.get("venueId") or "")
        day = slot.get("day_of_week")
        start_text = str(slot.get("start_time") or slot.get("startTime") or "")
        if not team_id or day is None or not start_text:
            continue
        day_int = int(day)
        start = _time_to_minutes(start_text)
        duration = int(slot.get("duration_minutes") or slot.get("durationMinutes") or DEFAULT_SESSION_MINUTES)
        # Le JOUR fait partie du message : sans lui, deux verrous même gymnase et
        # même heure des jours différents s'affichent à l'octet près identiques,
        # et le gestionnaire ne sait pas lequel il vient de corriger.
        # La durée fait partie du libellé : deux verrous mêmes gymnase/jour/heure et
        # durées différentes sont DISTINCTS (cf. `lock_id`), et sans elle leurs deux
        # avertissements s'affichaient à l'octet près identiques.
        where = f"{_venue(venue_id)} le {_day_label(day_int)} à {start_text} ({duration} min)"
        # La durée fait partie de la clé, comme dans `_extract_hard_locks` : deux
        # verrous identiques sauf la durée y sont DISTINCTS, les fusionner ici
        # ferait disparaître une violation.
        lock_id = f"{venue_id}|{day_int}|{start_text}|{duration}"

        # 1. Coach availability — every required coach of the team, like the solver.
        coach_ids = [str(c) for c in (coach_map.get(team_id) or [])]
        for coach_id in coach_ids:
            for iv_day, iv_from, iv_to in rules.get(coach_id) or ():
                if iv_day == day_int and iv_from <= start < iv_to:
                    _emit(
                        _constraint_of_coach(coach_id, _coach(coach_id)),
                        team_id,
                        lock_id,
                        f"Réservation maintenue pour {_team(team_id)} ({where}) alors que "
                        f"{_coach(coach_id)} est indisponible : le verrou prime, la contrainte est ignorée.",
                    )

        # 2. Team time/day windows.
        for constraint in windows:
            if str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "") != team_id:
                continue
            if not _is_enforced_window(constraint):
                continue
            family = str(constraint.get("family") or "").upper()
            config = constraint.get("config") or {}
            if family == "DAY":
                # Traitée via l'UNION, en dehors de cette boucle : une règle DAY
                # isolée exclut des jours qu'une autre autorise.
                continue
            min_start = config.get("minStartTime")
            max_start = config.get("maxStartTime")
            # `maxEndTime` porte sur la FIN de séance (début + durée), pas sur son
            # début — l'omettre laissait subsister le silence que cette détection
            # existe pour fermer.
            max_end = config.get("maxEndTime")
            # La durée du VERROU, pas celle du créneau de grille. Le round 2 avait
            # basculé sur `slot_durations` par souci de miroir — sur-correction : ce
            # qui déborde de la fenêtre, c'est la séance que le gestionnaire a
            # RÉSERVÉE. Un verrou de 120 min sur un créneau de 90 court jusqu'à
            # 20:00 ; mesurer 90 le déclarait dans les clous et taisait un vrai
            # dépassement. Les verrous de durée ≠ du créneau sont un cas supporté —
            # `_extract_hard_locks` clé justement sur la durée.
            slot_duration = duration
            if (
                (min_start is not None and start < _time_to_minutes(min_start))
                or (max_start is not None and start > _time_to_minutes(max_start))
                or (max_end is not None and start + slot_duration > _time_to_minutes(max_end))
            ):
                _emit(
                    constraint,
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) hors de sa fenêtre horaire.",
                )

        # 2 bis. Règles DAY, évaluées sur l'UNION de l'équipe — la seule sémantique
        # que le solveur applique (`forbidden ∪ complément de allowed`).
        # `day_rules` et non `rules` : ce dernier porte déjà les indisponibilités
        # coach, et le réutiliser ici l'écrasait dès le deuxième verrou.
        entry = day_union.get(team_id)
        if entry is not None:
            day_rules, day_sources = entry
            allowed = day_rules["allowed"]
            if day_int in day_rules["forbidden"] or (allowed and day_int not in allowed):
                # On nomme les contraintes RÉELLEMENT fautives, pas un libellé
                # synthétique : le gestionnaire doit retrouver la règle dans son
                # écran pour la corriger.
                for constraint in _day_constraints_excluding(day_int, day_rules, day_sources):
                    _emit(
                        constraint,
                        team_id,
                        lock_id,
                        f"Réservation maintenue pour {_team(team_id)} ({where}) un jour exclu par ses règles de jours.",
                    )

            # `forcedDays` : le solveur exige AU MOINS une séance ce jour-là. Un
            # verrou posé un AUTRE jour peut consommer le créneau qui l'aurait
            # satisfaite — d'où un INFEASIBLE que rien n'expliquait.
            forced = day_rules["forced"]
            if forced and day_int not in forced:
                _emit(
                    {"id": f"forced-days-{team_id}", "name": f"jours imposés de {_team(team_id)}"},
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) alors que son planning "
                    f"impose une séance {', '.join(_day_label(d) for d in sorted(forced))} : "
                    "le verrou peut rendre cette exigence insatisfaisable.",
                )

        # 3. Forbidden (team, venue) pairs — venue closures land here.
        for item in forbidden:
            if not isinstance(item, dict):
                continue
            tid = item.get("scope_target_id") or item.get("team_id")
            vid = item.get("venue_id") or item.get("room_id")
            if tid is not None and vid is not None and str(tid) == team_id and str(vid) == venue_id:
                # `parse_v2_constraints` aplatit ces règles en paires (équipe,
                # gymnase) sans `id` ni `name` : passer le dict brut rendait
                # « (contrainte « None ») » et fusionnait tous les gymnases
                # interdits d'une équipe en un seul avertissement.
                _emit(
                    {"id": f"forbidden-venue-{team_id}-{venue_id}", "name": f"gymnase interdit ({_venue(venue_id)})"},
                    team_id,
                    lock_id,
                    f"Réservation maintenue pour {_team(team_id)} ({where}) dans un gymnase qui lui est interdit.",
                )

        # 4. Forced venue — le miroir du gymnase interdit. `parse_v2_constraints`
        # aplatit la règle HARD/LOCK « impose ce gymnase » en team→gymnase unique
        # (`forced_venues`), sans `id` ni `name` : on nomme donc une contrainte
        # synthétique portant le gymnase imposé, comme pour les paires interdites.
        # Le créneau verrouillé n'ayant PAS de variable (`model.py`), le forçage
        # `var == 0` des autres gymnases ne peut pas l'atteindre : le verrou plaçait
        # hors du gymnase imposé, `completed`, sans un mot.
        target_venue = forced_venues.get(team_id)
        if target_venue is not None and venue_id and str(target_venue) != venue_id:
            _emit(
                {"id": f"forced-venue-{team_id}", "name": f"gymnase imposé ({_venue(str(target_venue))})"},
                team_id,
                lock_id,
                f"Réservation maintenue pour {_team(team_id)} ({where}) hors du gymnase imposé "
                f"{_venue(str(target_venue))} : le verrou prime, la contrainte est ignorée.",
            )

    return warnings


def diagnose_candidate_conflicts(
    *,
    candidate: Mapping[str, Any],
    baseline_slots: Sequence[Mapping[str, Any]],
    parsed: Mapping[str, Any],
    coaches: Sequence[Mapping[str, Any]] = (),
    slot_capacities: Mapping[tuple[str, int, str], int] | None = None,
    team_names: Mapping[str, str] | None = None,
    coach_names: Mapping[str, str] | None = None,
    venue_names: Mapping[str, str] | None = None,
    resolved_rules: ResolvedImplicitRules | None = None,
    shared_blocks: Sequence[Mapping[str, Any]] = (),
) -> list[dict[str, Any]]:
    """Name the HARD rules a move candidate would break (P2-2 F2a).

    The SOLVE is the sovereign verdict (baseline frozen via ``add_fixed_slots`` +
    candidate pinned): it says valid / invalid. This function only EXPLAINS an
    invalid verdict — « un non sans motif est inutilisable ». It mirrors the
    enforcement rules of ``add_level_1_hard_constraints`` exactly, so it does not
    lie about what the solver applies (same discipline as
    ``diagnose_locked_slot_violations``). Anything the solve rejects that no check
    here attributes falls back to a generic ``unknown_hard_conflict`` upstream.

    Coverage is deliberately the families the one-time rail silently ignored —
    capacity, windows, rest — plus the structural double-booking that is the
    founder's motivating example (« le coach Dupont a déjà les U15 à 20h dans un
    autre gymnase »).
    """
    coach_map: Mapping[str, Sequence[str]] = parsed.get("team_coach_map") or {}
    player_map: Mapping[str, Sequence[str]] = parsed.get("team_player_map") or {}
    unavailability: Mapping[str, Any] = parsed.get("coach_unavailability") or {}
    forced_venues: Mapping[str, str] = parsed.get("forced_venues") or {}
    forbidden = parsed.get("forbidden_assignments") or ()
    windows = parsed.get("time_windows") or ()
    caps = slot_capacities or {}

    def _team(team_id: str) -> str:
        return (team_names or {}).get(team_id) or team_id

    def _coach(coach_id: str) -> str:
        return (coach_names or {}).get(coach_id) or coach_id

    def _venue(venue_id: str) -> str:
        return (venue_names or {}).get(venue_id) or venue_id

    c_team = str(candidate["team_id"])
    c_venue = str(candidate["venue_id"])
    c_day = int(candidate["day"])
    c_start = int(candidate["start"])
    c_end = int(candidate["end"])
    c_start_text = str(candidate.get("start_time") or "")

    c_coaches = [str(cid) for cid in coach_map.get(c_team, ())]
    c_players = [str(pid) for pid in player_map.get(c_team, ())]
    c_persons_role: dict[str, str] = {}
    for cid in c_coaches:
        c_persons_role.setdefault(cid, "coach")
    for pid in c_players:
        c_persons_role[pid] = "player"

    coach_ids_in_payload = {
        str(_scalar_id(_get(coach, "id", "coach_id", default=None)))
        for coach in coaches
        if _get(coach, "id", "coach_id", default=None) is not None
    }

    violations: list[dict[str, Any]] = []

    def _emit(rule: str, message: str, **fields: Any) -> None:
        violations.append({"rule": rule, "message": message, **fields})

    # --- Structural rules, checked against the FROZEN baseline occupancy. ---
    coach_working_days: dict[str, set[int]] = {cid: set() for cid in c_coaches}
    baseline_days_same_team: set[int] = set()

    for slot in baseline_slots:
        s_team = str(slot["team_id"])
        s_venue = str(slot["venue_id"])
        s_day = int(slot["day"])
        s_start = int(slot["start"])
        s_end = int(slot["end"])
        overlaps = s_day == c_day and _intervals_overlap(c_start, c_end, s_start, s_end)

        s_persons_role: dict[str, str] = {}
        for cid in coach_map.get(s_team, ()):
            s_persons_role.setdefault(str(cid), "coach")
        for pid in player_map.get(s_team, ()):
            s_persons_role[str(pid)] = "player"

        # Rest day: which weekdays each candidate coach already works.
        if 1 <= s_day <= 5:
            for cid in c_coaches:
                if cid in s_persons_role:
                    coach_working_days[cid].add(s_day)

        # One session per day: same team already busy that day.
        if s_team == c_team and s_day == c_day:
            baseline_days_same_team.add(s_day)

        if s_team == c_team and overlaps:
            _emit(
                "team_no_overlap",
                f"{_team(c_team)} a déjà une séance qui chevauche ce créneau le {_day_label(c_day)}.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

        if not overlaps:
            continue

        # Coach / coach-player double-booking — venue-aware (D-14): the SAME gym is
        # allowed, a DIFFERENT gym is physically impossible.
        if s_venue != c_venue:
            shared = set(c_persons_role) & set(s_persons_role)
            for person_id in shared:
                is_coach_pair = c_persons_role[person_id] == "coach" and s_persons_role[person_id] == "coach"
                if is_coach_pair:
                    _emit(
                        "coach_no_overlap",
                        f"{_coach(person_id)} a déjà {_team(s_team)} le {_day_label(c_day)} à "
                        f"{c_start_text} dans un autre gymnase : un entraîneur ne peut pas être à deux endroits.",
                        coach_id=person_id,
                        team_id=c_team,
                        conflicting_team_id=s_team,
                        day_of_week=c_day,
                        start_time=c_start_text,
                    )
                else:
                    _emit(
                        "coach_player_no_overlap",
                        f"{_coach(person_id)} est déjà pris par {_team(s_team)} le {_day_label(c_day)} à "
                        f"{c_start_text} dans un autre gymnase (impossible de coacher et jouer en même temps).",
                        coach_id=person_id,
                        team_id=c_team,
                        conflicting_team_id=s_team,
                        day_of_week=c_day,
                        start_time=c_start_text,
                    )

    # Venue capacity: mirror add_room_at_most_one (grouped by venue + exact slot start), BLOC-AWARE.
    # P2-58 (C) — une séance de bloc = UN occupant (le solveur la dé-compte via ``add_room_at_most_one``,
    # la grille l'applique). Compter les membres d'un même bloc comme N occupants crierait faussement
    # à la sur-capacité : dans le rail « déplacer le bloc », les membres arrivent ENSEMBLE sur leur
    # case commune (candidat contre candidat via ``baseline_slots`` augmentée, ou candidat contre un
    # partenaire déjà en baseline). On REPLIE donc l'identité d'occupant PAR CASE avec le même
    # ``_fold_case_occupant_identity`` que le sur-solde post-solve (maison unique). Deux équipes SANS
    # bloc commun restent deux occupants → refus inchangé.
    present_here = [
        str(slot["team_id"])
        for slot in baseline_slots
        if str(slot["venue_id"]) == c_venue
        and int(slot["day"]) == c_day
        and str(slot.get("start_time")) == c_start_text
    ]
    present_here.append(c_team)
    # Équipes DISTINCTES (la même équipe deux fois est un artefact de créneau dupliqué, pas une
    # sur-capacité — parité avec le sur-solde post-solve).
    distinct_here = list(dict.fromkeys(present_here))
    blocks: list[tuple[str, frozenset[str]]] = []
    for block_index, block in enumerate(shared_blocks):
        members = frozenset(str(m) for m in (_get(block, "teamIds", "team_ids", default=[]) or []))
        if len(members) >= 2:
            blocks.append((f"__shared_block__{_get(block, 'id', default=block_index)}", members))
    if blocks:
        identity, _block_keys = _fold_case_occupant_identity(distinct_here, {}, blocks)
        occupant_count = len(set(identity.values()))
    else:
        occupant_count = len(distinct_here)
    capacity = int(caps.get((c_venue, c_day, c_start_text), 1))
    if occupant_count > capacity:
        _emit(
            "venue_capacity",
            f"{_venue(c_venue)} le {_day_label(c_day)} à {c_start_text} est déjà à sa capacité "
            f"({capacity}) : aucune place pour {_team(c_team)}.",
            team_id=c_team,
            venue_id=c_venue,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    # Coach rest day: mirror add_coach_rest_day — at most 4 working days Mon-Fri,
    # for every coach present in the payload (no override exemption since P4-51).
    # PARITÉ D'INTENSITÉ (verdict = génération) : ce n'est un INTERDIT DUR que sous
    # coachRestDay=HARD. En PREFERRED, la génération PLACE en payant le malus (−3/−6) et le
    # verdict doit ACCEPTER de même — la concession remonte alors comme COMPROMIS (famille
    # implicit_rule, chemin ``_compromises_for``) sur le candidat retenu, jamais comme violation
    # bloquante ici. Sans ce garde, le verdict était PLUS STRICT que la génération (rupture de
    # parité) : il refusait un 5ᵉ jour de coach que la génération concède. ``coachRestDay`` est la
    # SEULE des 5 règles implicites réglables à avoir un pré-check nommé dans ce fichier ; les
    # quatre autres (salarié, dos-à-dos, jours consécutifs, âge croissant) n'y miroitent rien.
    coach_rest_is_hard = (resolved_rules or ResolvedImplicitRules()).coach_rest_day_intensity == HARD
    if coach_rest_is_hard and 1 <= c_day <= 5:
        for cid in c_coaches:
            if cid not in coach_ids_in_payload:
                continue
            worked = coach_working_days[cid] | {c_day}
            if len(worked) >= 5:
                _emit(
                    "coach_no_rest_day",
                    f"placer {_team(c_team)} ici priverait {_coach(cid)} de son unique jour de "
                    "repos (il travaillerait du lundi au vendredi).",
                    coach_id=cid,
                    team_id=c_team,
                    day_of_week=c_day,
                    start_time=c_start_text,
                )

    # One session per day: mirror add_one_session_per_day (at most one per day).
    if c_day in baseline_days_same_team:
        _emit(
            "one_session_per_day",
            f"{_team(c_team)} s'entraîne déjà le {_day_label(c_day)} : une seule séance par jour.",
            team_id=c_team,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    # --- Entered constraints (the manager's own rules). ---
    for cid in c_coaches:
        for iv_day, iv_from, iv_to in unavailability.get(cid) or ():
            if int(iv_day) == c_day and int(iv_from) <= c_start < int(iv_to):
                _emit(
                    "coach_unavailable",
                    f"{_coach(cid)} est indisponible le {_day_label(c_day)} à {c_start_text}.",
                    coach_id=cid,
                    team_id=c_team,
                    day_of_week=c_day,
                    start_time=c_start_text,
                )

    for constraint in windows:
        target = str(constraint.get("scope_target_id") or constraint.get("scopeTargetId") or "")
        if target != c_team or not _is_enforced_window(constraint):
            continue
        if str(constraint.get("family") or "").upper() == "DAY":
            continue
        config = constraint.get("config") or {}
        min_start = config.get("minStartTime")
        max_start = config.get("maxStartTime")
        max_end = config.get("maxEndTime")
        if (
            (min_start is not None and c_start < _time_to_minutes(min_start))
            or (max_start is not None and c_start > _time_to_minutes(max_start))
            or (max_end is not None and c_end > _time_to_minutes(max_end))
        ):
            _emit(
                "time_window",
                f"{_team(c_team)} placé à {c_start_text} sort de sa fenêtre horaire autorisée.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    day_union = _day_rules_union(windows).get(c_team)
    if day_union is not None:
        day_rules, _sources = day_union
        allowed = day_rules["allowed"]
        if c_day in day_rules["forbidden"] or (allowed and c_day not in allowed):
            _emit(
                "day_rule",
                f"{_team(c_team)} ne peut pas s'entraîner le {_day_label(c_day)} d'après ses règles de jours.",
                team_id=c_team,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    forced = forced_venues.get(c_team)
    if forced is not None and str(forced) != c_venue:
        _emit(
            "forced_venue",
            f"{_team(c_team)} est forcée dans {_venue(str(forced))} : {_venue(c_venue)} lui est interdit.",
            team_id=c_team,
            venue_id=c_venue,
            day_of_week=c_day,
            start_time=c_start_text,
        )

    for item in forbidden:
        if not isinstance(item, dict):
            continue
        tid = item.get("scope_target_id") or item.get("team_id")
        vid = item.get("venue_id") or item.get("room_id")
        if tid is not None and vid is not None and str(tid) == c_team and str(vid) == c_venue:
            _emit(
                "forbidden_venue",
                f"{_venue(c_venue)} est interdit à {_team(c_team)}.",
                team_id=c_team,
                venue_id=c_venue,
                day_of_week=c_day,
                start_time=c_start_text,
            )

    return violations


def _constraint_of_coach(coach_id: str, label: str) -> dict[str, Any]:
    """The COACH_AVAILABILITY constraint behind a blocked interval, for naming.

    `coach_unavailability` is flattened to intervals at parse time, losing the
    source constraint. A synthetic entry keeps the warning shape valid; the real
    label rides in the message, which names the coach.
    """
    return {"id": f"coach-availability-{coach_id}", "name": f"indisponibilité de {label}"}
