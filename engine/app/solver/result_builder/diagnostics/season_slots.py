"""Diagnostic « garder ses créneaux de saison » — N/M créneaux de socle conservés par équipe.

PR 6 (lot 8, Q3bis) — quand une équipe porte la contrainte ``SEASON_SLOTS``, le backend émet ses
placements de socle en ``socleReferenceAssignments`` avec ``keep: true`` (génération PLEINE d'un
plan de période). Ce diagnostic COMPTE, par équipe concernée, combien de ces créneaux ont été
CONSERVÉS — le jour+heure de socle retrouvé dans le planning final, QUEL QUE SOIT le gymnase (garder
le jour+heure est l'essentiel, D-d) — et NOMME ceux qui ne l'ont pas été, avec leur cause. « Placer
les séances reste prioritaire, mais on alerte que SM1 2/3 créneaux sont conservés » (mots du
fondateur, Q3bis).

Deux causes pour un créneau NON conservé (portées par ``DiagnosticCauseSchema.kind``) :
- ``slot_absent`` : AUCUNE fenêtre de gymnase de la période ne couvre ce jour+heure (gymnase fermé
  aux vacances p.ex.) — le créneau de socle n'avait pas de place où renaître ;
- ``displaced`` : une fenêtre existe à ce jour+heure mais l'équipe n'y est pas — déplacée pour le
  bien du planning, ou le jour est fermé par une indisponibilité (tous les coachs requis
  indisponibles, règle « un coach suffit » 3b A).

Un créneau de socle tenu par un VERROU HARD est dans le planning final (``slots`` l'inclut avant
tout placement solveur) → compté CONSERVÉ, sans condition (cohérence avec le lot verrous V1, qui
greffe les HARD de la source).

Émis UNIQUEMENT quand au moins un créneau de saison n'a pas été conservé (``kept < total``) : c'est
une ALERTE, pas un accusé de réception. ``extra="forbid"`` sur ``DiagnosticSchema`` interdit tout
champ hors contrat — le N/M et le détail des pertes vivent donc dans ``message`` (lisible tel quel
par le panneau) et dans ``causes`` (structuré, une entrée par créneau perdu).
"""

from __future__ import annotations

from collections.abc import Mapping
from typing import Any

from ...model import _time_to_minutes
from ..helpers import _collection, _day_label, _get, _label, _team_name_map

_REASON_FR = {
    "displaced": "déplacé",
    "slot_absent": "créneau absent de la période",
}


def _hhmm(start_minutes: int) -> str:
    return f"{start_minutes // 60:02d}:{start_minutes % 60:02d}"


def _diagnose_season_slots(
    model_data: Mapping[str, Any] | Any,
    slots: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Par équipe « garder ses créneaux de saison », combien de créneaux de socle conservés et,
    pour les perdus, pourquoi (``slot_absent`` / ``displaced``)."""
    references = _collection(model_data, "socleReferenceAssignments", "socle_reference_assignments")
    # Seules les références KEEP relèvent de « garder ses créneaux » ; le comblement (keep absent)
    # n'est pas une promesse de conservation et n'est jamais diagnostiqué ici.
    keep_refs = [ref for ref in references if bool(_get(ref, "keep", default=False))]
    if not keep_refs:
        return []

    # Créneaux FINAUX par équipe : (jour, minute de début). Un verrou HARD est déjà dans ``slots``
    # (préfixés par build_result avant le placement solveur) → naturellement compté conservé.
    placed_by_team: dict[str, set[tuple[int, int]]] = {}
    for slot in slots:
        team_id = str(slot["teamId"])
        try:
            key = (int(slot["dayOfWeek"]), _time_to_minutes(str(slot["startTime"])))
        except KeyError, TypeError, ValueError:
            continue
        placed_by_team.setdefault(team_id, set()).add(key)

    # Fenêtres de gymnase de la PÉRIODE, agrégées (gymnase indifférent) : (jour) → liste
    # d'intervalles [début, fin). Sert à distinguer « créneau absent » d'« équipe déplacée ».
    windows_by_day: dict[int, list[tuple[int, int]]] = {}
    for venue in _collection(model_data, "venues"):
        for window in _collection(venue, "training_slots", "trainingSlots"):
            day = _get(window, "dayOfWeek", "day_of_week", default=None)
            start = _get(window, "startTime", "start_time", default=None)
            duration = _get(window, "durationMinutes", "duration_minutes", default=None)
            if day is None or start is None:
                continue
            try:
                start_min = _time_to_minutes(str(start))
                day_int = int(day)
            except TypeError, ValueError:
                continue
            end_min = start_min + (int(duration) if duration is not None else 0)
            windows_by_day.setdefault(day_int, []).append((start_min, end_min))

    def _window_covers(day: int, start_min: int) -> bool:
        return any(win_start <= start_min < win_end for win_start, win_end in windows_by_day.get(day, ()))

    # Références KEEP groupées par équipe, dédupliquées par (jour, minute) — le socle peut porter le
    # même horaire sur deux gymnases, c'est UN créneau de saison à conserver.
    refs_by_team: dict[str, dict[tuple[int, int], None]] = {}
    for ref in keep_refs:
        team_id = _get(ref, "teamId", "team_id", default=None)
        day = _get(ref, "dayOfWeek", "day_of_week", default=None)
        start = _get(ref, "startTime", "start_time", default=None)
        if team_id is None or day is None or start is None:
            continue
        try:
            key = (int(day), _time_to_minutes(str(start)))
        except TypeError, ValueError:
            continue
        refs_by_team.setdefault(str(team_id), {})[key] = None

    team_names = _team_name_map(model_data)
    diagnostics: list[dict[str, Any]] = []

    for team_id, keys in refs_by_team.items():
        total = len(keys)
        placed = placed_by_team.get(team_id, set())
        lost: list[tuple[int, int, str]] = []  # (jour, minute, cause)
        for day, start_min in sorted(keys):
            if (day, start_min) in placed:
                continue  # conservé (y compris par un verrou HARD)
            cause = "displaced" if _window_covers(day, start_min) else "slot_absent"
            lost.append((day, start_min, cause))

        if not lost:
            continue  # tous conservés : pas d'alerte

        kept = total - len(lost)
        team_label = _label(team_id, team_names)
        lost_phrases = [f"{_day_label(day)} {_hhmm(start_min)} ({_REASON_FR[cause]})" for day, start_min, cause in lost]
        message = (
            f"{team_label} : {kept}/{total} créneau{'x' if total > 1 else ''} de saison conservé"
            f"{'s' if kept != 1 else ''}. Non conservé{'s' if len(lost) > 1 else ''} : "
            f"{', '.join(lost_phrases)}."
        )
        causes = [
            {
                "kind": cause,
                "constraintId": None,
                "label": f"{_day_label(day)} {_hhmm(start_min)}",
                "count": 1,
            }
            for day, start_min, cause in lost
        ]
        diagnostics.append(
            {
                "id": f"diag-season-slots-{team_id}",
                "type": "season_slots_kept",
                "severity": "WARNING",
                "teamId": team_id,
                "message": message,
                "causes": causes,
            }
        )

    return diagnostics
