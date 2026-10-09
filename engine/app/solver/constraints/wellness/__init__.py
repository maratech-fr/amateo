"""Well-being constraint families: coach rest, salarié spread, back-to-back caps, age order (paquet).

Ce ``__init__`` est le point d'entrée du sous-paquet : il porte cette docstring et les ré-exports
``from .x import y as y`` de CHAQUE symbole (publics ET privés), de sorte que la surface d'import
reste byte-identique au module ``constraints/wellness.py`` d'avant la découpe — le paquet parent
``constraints/__init__`` résout ses ``from .wellness import …`` (7 ré-exports, dont
``_find_consecutive_chains``) contre ces ré-exports, et l'ORDRE d'ajout des contraintes CP-SAT comme
la couture ``patch.object`` des tests restent intacts.

Extrait tel quel de l'ancien monolithe (déplacement pur, P4-295 C6). Les sous-modules forment un DAG
sans arête interne, chacun assis sur les externes ``...compromise`` / ``...model`` / ``..common`` :
``coach_rest`` (jour de repos) · ``salarie`` (présence salarié) · ``chains`` (créneaux dos-à-dos
d'une PERSONNE + le détecteur ``_find_consecutive_chains``) · ``consecutive_days`` (jours de suite
d'une ÉQUIPE — séparé de ``chains`` à dessein, avertissement ALIGN-08) · ``daily_budget`` (au plus
une séance par jour) · ``age_order`` (les plus jeunes plus tôt). L'orchestrateur
``add_level_1_hard_constraints`` qui les pilote vit dans ``constraints/__init__`` (contrainte
test-seam — voir sa docstring), PAS ici ; il atteint ces familles via les ré-exports du paquet.
"""

from __future__ import annotations

from .age_order import (
    add_age_ascending_constraints as add_age_ascending_constraints,
)
from .chains import (
    _find_consecutive_chains as _find_consecutive_chains,
)
from .chains import (
    add_max_consecutive_sessions_constraints as add_max_consecutive_sessions_constraints,
)
from .coach_rest import (
    add_coach_rest_day_constraints as add_coach_rest_day_constraints,
)
from .consecutive_days import (
    add_max_consecutive_days_constraints as add_max_consecutive_days_constraints,
)
from .daily_budget import (
    add_one_session_per_day_constraints as add_one_session_per_day_constraints,
)
from .salarie import (
    add_salarie_distribution_constraints as add_salarie_distribution_constraints,
)
