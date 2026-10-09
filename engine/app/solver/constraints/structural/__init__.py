"""Structural hard constraints: venue/coach/player/team no-overlap, fixed/forbidden slots, min-sessions (paquet).

Ce ``__init__`` est le point d'entrée du sous-paquet : il porte cette docstring et les ré-exports
``from .x import y as y`` de CHAQUE symbole (publics ET privés), de sorte que la surface d'import
reste byte-identique au module ``constraints/structural.py`` d'avant la découpe — le paquet parent
``constraints/__init__`` résout ses ``from .structural import …`` (14 posers) contre ces ré-exports,
et l'ORDRE d'ajout des contraintes CP-SAT comme la couture ``patch.object`` des tests restent intacts.

Extrait tel quel de l'ancien monolithe (déplacement pur, P4-295 C5). DAG du sous-paquet, assis sur
les externes ``...model`` / ``..common`` : ``pairing`` (briques « au plus un » + exemption de séance
de bloc, feuille) · ``capacity`` (« au plus une équipe par case de gymnase ») · ``overlap``
(non-chevauchement coach / coach-joueur / équipe — SEULE arête interne : importe ``.pairing``) ·
``closures`` (créneaux figés, interdits, indisponibilités coach) · ``min_sessions`` (plancher « au
moins N séances » + calcul effectif). L'orchestrateur ``add_level_1_hard_constraints`` qui les pilote
vit dans ``constraints/__init__`` (contrainte test-seam — voir sa docstring), PAS ici ; il atteint
ces posers via les ré-exports du paquet.
"""

from __future__ import annotations

from .capacity import (
    add_room_at_most_one as add_room_at_most_one,
)
from .closures import (
    add_coach_unavailability_constraints as add_coach_unavailability_constraints,
)
from .closures import (
    add_fixed_slots as add_fixed_slots,
)
from .closures import (
    add_forbidden_assignments as add_forbidden_assignments,
)
from .min_sessions import (
    _compute_effective_min_sessions as _compute_effective_min_sessions,
)
from .min_sessions import (
    _effective_min_sessions_by_team as _effective_min_sessions_by_team,
)
from .min_sessions import (
    add_min_sessions_constraints as add_min_sessions_constraints,
)
from .overlap import (
    add_coach_at_most_one as add_coach_at_most_one,
)
from .overlap import (
    add_coach_player_non_overlap as add_coach_player_non_overlap,
)
from .overlap import (
    add_team_no_overlap as add_team_no_overlap,
)
from .pairing import (
    CaseBvars as CaseBvars,
)
from .pairing import (
    _add_at_most_one_groups as _add_at_most_one_groups,
)
from .pairing import (
    _add_coach_player_time_key_pairs as _add_coach_player_time_key_pairs,
)
from .pairing import (
    _add_cross_venue_at_most_one as _add_cross_venue_at_most_one,
)
from .pairing import (
    _add_free_vs_locked_interval_conflicts as _add_free_vs_locked_interval_conflicts,
)
from .pairing import (
    _add_interval_at_most_one as _add_interval_at_most_one,
)
from .pairing import (
    _block_case_exemption_bvars as _block_case_exemption_bvars,
)
from .pairing import (
    _dedupe_meta_entries as _dedupe_meta_entries,
)
