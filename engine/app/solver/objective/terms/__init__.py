"""Level-2 objective — constructeurs de termes SOFT ``add_*`` + stabilité (paquet ENG-39).

Ce ``__init__`` est le point d'entrée du paquet : il porte cette docstring, les deux alias de type
``AssignmentLike`` / ``BoolVarLike`` et les ré-exports ``from .x import y as y`` de chaque sous-module,
de sorte que la surface d'import reste byte-identique au module ``objective/terms.py`` d'avant la
découpe (noms publics ET privés — ``objective/__init__`` consomme les 14 constructeurs depuis ici).

Extrait tel quel de l'ancien monolithe ``objective/terms.py`` (déplacement pur, P4-295 C4). Les
sous-modules dépendent de ``weights`` (barèmes), ``normalise`` (lecture des assignments) et des
modules solveur ``compromise`` / ``constraints`` / ``model`` ; ils NE dépendent PAS de cet agrégateur
et n'ont AUCUNE arête entre eux. Découpe par famille de terme : ``stability`` (stabilité P3-21 +
référence socle) · ``venue`` (gymnase préféré/à éviter) · ``coach_cap`` (plafond de jours coach) ·
``sessions`` (malus de séance manquante) · ``team_links`` (malus passerelle PREFERRED) · ``preferred``
(bonus jour/heure + constructeur partagé) · ``rhythm`` (repos après match + espacement) · ``chaining``
(enchaînement même gymnase).
"""

from __future__ import annotations

from typing import Any

from .chaining import (
    add_chaining_bonus as add_chaining_bonus,
)
from .coach_cap import (
    add_coach_day_cap_penalty as add_coach_day_cap_penalty,
)
from .preferred import (
    _add_preferred_bonus as _add_preferred_bonus,
)
from .preferred import (
    _group_team_slots as _group_team_slots,
)
from .preferred import (
    _safe_minutes as _safe_minutes,
)
from .preferred import (
    add_preferred_day_bonus as add_preferred_day_bonus,
)
from .preferred import (
    add_preferred_time_bonus as add_preferred_time_bonus,
)
from .rhythm import (
    add_match_day_rest_bonus as add_match_day_rest_bonus,
)
from .rhythm import (
    add_spacing_penalty as add_spacing_penalty,
)
from .sessions import (
    add_missing_session_penalty as add_missing_session_penalty,
)
from .stability import (
    add_socle_reference_bonus as add_socle_reference_bonus,
)
from .stability import (
    build_stability_terms as build_stability_terms,
)
from .team_links import (
    add_team_link_penalty as add_team_link_penalty,
)
from .venue import (
    add_venue_preference_bonus as add_venue_preference_bonus,
)

AssignmentLike = Any
BoolVarLike = Any
