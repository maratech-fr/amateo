"""P4-132 — registre de parité de la couche HARD entre `/generate` et le verdict.

Le verdict (`POST /validate-assignments`) reconstruit sa couche HARD règle par règle,
dans `validate_assignments._apply_hard`. Quand une famille HARD naît côté `/generate`
sans son miroir côté verdict, un déplacement manuel qui la casse est jugé **valide** en
silence — c'était exactement ENG-36 (le trajet), trouvé par un audit et corrigé en PR #779.

Ce garde ne corrige pas une asymétrie : il rend **impossible qu'une nouvelle passe inaperçue.**
Il diffe deux sources de vérité — les familles HARD posées sur le chemin `/generate` et celles
posées par `_apply_hard` — et échoue en NOMMANT toute famille présente d'un côté, absente de
l'autre, sauf si elle figure dans ``DECLARED_ASYMMETRIES`` avec une raison (obligatoire, gardée).
Patron maison : ``BlockingTestsListMatchesCiTest`` (diff bidirectionnel + exceptions nommées) et
``TsFieldsMatchOpenApiSchemaTest`` (la raison d'une exemption est gardée par une assertion).

── Comment on recense chaque côté ────────────────────────────────────────────────────────────

Lire des appels dans un fichier source est fragile ; on le rend honnête plutôt que malin.

1. **AST, pas regex.** On parse `app/main.py` et `validate_assignments.py` avec ``ast`` et on
   collecte les ``Call`` par nom de fonction (résistant au reformatage, aux retours à la ligne).
2. **Ancre = l'agrégateur.** ``add_level_1_hard_constraints`` EST la couche HARD ; les deux
   chemins l'appellent. Le côté `/generate` est la fonction UNIQUE de `main.py` qui le compose
   (aujourd'hui ``_solve``) ; le côté verdict est ``_apply_hard``, dont on vérifie qu'il compose
   toujours l'agrégateur. Si l'agrégateur est renommé ou déplacé, ces ancres échouent **fort**
   (0 ou plusieurs fonctions composantes, ou ``_apply_hard`` qui ne l'appelle plus) au lieu de
   rendre un diff vide et mensonger.
3. **Convention de nommage d'une famille HARD.** ``add_<...>_constraints``. Les termes SOFT
   finissent en ``_penalty`` / ``_bonus`` (exclus), les diagnostics commencent par ``diagnose_``
   (exclus). Un nouveau bloc HARD qui suit la convention et est appelé sur un seul des deux
   chemins est donc vu et nommé.
4. **Plancher sentinelle.** ``KNOWN_GENERATE_FAMILIES`` est le socle connu côté `/generate`. Si le
   scanner en voit MOINS, c'est soit une régression du scanner, soit une suppression réelle : on
   échoue **fort** plutôt que de laisser passer une famille en silence — un registre qui rate une
   famille sans le dire est pire que pas de registre.

Où ça peut casser (fragilité assumée, documentée) : un bloc HARD posé (a) hors de la fonction qui
compose l'agrégateur — dans une autre fonction du chemin `/generate` —, (b) via un appel indirect
(wrapper, ``getattr``), ou (c) sous un nom hors convention (``enforce_curfew`` plutôt que
``add_curfew_constraints``) échapperait au recensement. Les cas (a)/(c) qui touchent l'UNE des
familles connues sont attrapés par la sentinelle ; le reste est le prix d'un recensement statique,
et c'est pour ça que l'ancre échoue fort au moindre glissement de l'agrégateur.

── État des asymétries ─────────────────────────────────────────────────────────────────────

``DECLARED_ASYMMETRIES`` est VIDE : aucune famille HARD n'est aujourd'hui posée d'un seul côté.
``add_venue_minimum_constraints`` (le plancher de gymnase) était la dernière asymétrie déclarée ;
P4-152 l'a fermée — le verdict la pose désormais dans ``_apply_hard`` et NOMME via
``_venue_minimum_move_violation`` le déplacement qui casse un plancher, sur le patron d'ENG-36.
"""

from __future__ import annotations

import ast
import pathlib
import re
from collections.abc import Iterator

ENGINE_ROOT = pathlib.Path(__file__).resolve().parents[1]
MAIN_PY = ENGINE_ROOT / "app" / "main.py"
VALIDATE_PY = ENGINE_ROOT / "app" / "solver" / "validate_assignments.py"

FuncDef = ast.FunctionDef | ast.AsyncFunctionDef

# L'agrégateur EST la couche HARD : c'est lui qui pose room/coach/forbidden/… en un bloc. Les deux
# chemins l'appellent, c'est donc une ancre stable pour trouver « la fonction qui pose le HARD ».
AGGREGATOR = "add_level_1_hard_constraints"

# La fonction interne du verdict qui reconstruit la couche HARD (docstring + docblock du fichier).
VERDICT_HARD_FUNCTION = "_apply_hard"

# Convention d'un poseur de famille HARD : add_<...>_constraints. Les SOFT finissent en
# _penalty/_bonus, les diagnostics commencent par diagnose_ — exclus SCIEMMENT.
HARD_FAMILY = re.compile(r"^add_.*_constraints$")

# Plancher sentinelle côté /generate : les familles qu'on SAIT posées là (main.py, vérifié). Si le
# scanner en voit moins, il ment ou le code a changé — dans les deux cas, échouer fort, pas se taire.
KNOWN_GENERATE_FAMILIES = frozenset(
    {
        "add_level_1_hard_constraints",
        "add_time_window_constraints",
        "add_venue_minimum_constraints",
    }
)

# Asymétries DÉCLARÉES : famille présente d'un côté, absente de l'autre, ACCEPTÉE avec sa raison.
# La raison est OBLIGATOIRE (gardée par test_every_declared_asymmetry_carries_a_reason) et doit
# décrire une asymétrie RÉELLE (gardée par test_declared_asymmetries_are_real) — jamais une
# exemption fantôme qui ne masque rien.
#
# P4-152 — ``add_venue_minimum_constraints`` a QUITTÉ cette carte : le verdict la pose désormais
# dans ``_apply_hard`` (parité rétablie), et le refus d'un déplacement qui casse un plancher de
# gymnase est NOMMÉ par le miroir déterministe ``_venue_minimum_move_violation``. L'asymétrie est
# fermée — la laisser ici désarmerait le registre (``test_declared_asymmetries_are_real`` l'exige).
DECLARED_ASYMMETRIES: dict[str, str] = {}


def _module(path: pathlib.Path) -> ast.Module:
    return ast.parse(path.read_text(encoding="utf-8"), filename=str(path))


def _iter_call_names(node: ast.AST) -> Iterator[str]:
    """Tous les noms de fonctions APPELÉES dans le sous-arbre (Name direct ou méthode)."""
    for child in ast.walk(node):
        if not isinstance(child, ast.Call):
            continue
        func = child.func
        if isinstance(func, ast.Name):
            yield func.id
        elif isinstance(func, ast.Attribute):
            yield func.attr


def _calls(func: FuncDef, name: str) -> bool:
    return name in set(_iter_call_names(func))


def _hard_families_called(func: FuncDef) -> set[str]:
    return {name for name in _iter_call_names(func) if HARD_FAMILY.match(name)}


def _top_level_functions(module: ast.Module) -> list[FuncDef]:
    return [node for node in module.body if isinstance(node, ast.FunctionDef | ast.AsyncFunctionDef)]


def _functions_named(module: ast.Module, name: str) -> list[FuncDef]:
    return [
        node
        for node in ast.walk(module)
        if isinstance(node, ast.FunctionDef | ast.AsyncFunctionDef) and node.name == name
    ]


def _generate_hard_families() -> set[str]:
    """Les familles HARD posées sur le chemin /generate : celles appelées par l'unique fonction
    de main.py qui compose l'agrégateur."""
    module = _module(MAIN_PY)
    composers = [func for func in _top_level_functions(module) if _calls(func, AGGREGATOR)]

    assert len(composers) == 1, (
        f"Attendu EXACTEMENT une fonction de app/main.py composant `{AGGREGATOR}`, trouvé "
        f"{len(composers)} ({[f.name for f in composers]}). L'ancre du registre a glissé : "
        f"l'agrégateur a-t-il été renommé, ou la couche HARD éclatée sur plusieurs fonctions ? "
        f"Ré-ancrer ce garde AVANT de continuer — un diff calculé sur la mauvaise fonction ment."
    )

    families = _hard_families_called(composers[0])

    missing = KNOWN_GENERATE_FAMILIES - families
    assert not missing, (
        f"Le scanner ne voit plus, côté /generate, des familles HARD qu'il DOIT voir : "
        f"{sorted(missing)}. Soit le code les a retirées, soit le recensement (AST / convention "
        f"`add_*_constraints`) a régressé. Échouer fort ici est voulu : un registre qui rate une "
        f"famille en silence est pire que pas de registre. Corriger le code OU le scanner, jamais "
        f"affaiblir cette sentinelle."
    )
    return families


def _verdict_hard_families() -> set[str]:
    """Les familles HARD posées par le verdict : celles appelées par `_apply_hard`."""
    module = _module(VALIDATE_PY)
    functions = _functions_named(module, VERDICT_HARD_FUNCTION)

    assert len(functions) == 1, (
        f"Attendu EXACTEMENT une fonction `{VERDICT_HARD_FUNCTION}` dans "
        f"app/solver/validate_assignments.py, trouvé {len(functions)}. La couche HARD du verdict "
        f"a été renommée ou dupliquée : ré-ancrer ce garde plutôt que de le laisser diffuser sur "
        f"une fonction fantôme."
    )
    verdict = functions[0]

    assert _calls(verdict, AGGREGATOR), (
        f"`{VERDICT_HARD_FUNCTION}` ne compose plus `{AGGREGATOR}` : la couche HARD du verdict "
        f"ne se construit plus sur l'agrégateur. Le registre ne peut plus garantir la parité — "
        f"ré-ancrer avant de continuer."
    )
    return _hard_families_called(verdict)


def test_no_undeclared_hard_asymmetry_between_generate_and_verdict() -> None:
    """Aucune famille HARD posée d'un seul côté sans être déclarée. C'est le cœur du registre."""
    generate = _generate_hard_families()
    verdict = _verdict_hard_families()

    offenders: list[str] = []
    for family in sorted(generate - verdict):
        if family in DECLARED_ASYMMETRIES:
            continue
        offenders.append(f"`{family}` : posée sur /generate (main.py), ABSENTE du verdict (`_apply_hard`)")
    for family in sorted(verdict - generate):
        if family in DECLARED_ASYMMETRIES:
            continue
        offenders.append(f"`{family}` : posée par le verdict (`_apply_hard`), ABSENTE de /generate (main.py)")

    assert not offenders, (
        "Asymétrie(s) HARD entre /generate et le verdict, non déclarée(s) :\n  - "
        + "\n  - ".join(offenders)
        + "\n\nC'est exactement ENG-36 : une règle HARD posée à la génération mais pas au verdict "
        "rend VALIDE un déplacement manuel qui la casse. Deux issues, jamais le silence : "
        "(a) poser le miroir de la famille dans l'autre chemin ; (b) si l'asymétrie est un choix "
        "assumé, la déclarer dans DECLARED_ASYMMETRIES AVEC sa raison (changement de comportement "
        "sur l'axe backend↔engine §7.1 → décision fondateur)."
    )


def test_every_declared_asymmetry_carries_a_reason() -> None:
    """Une asymétrie se déclare AVEC son pourquoi, ou pas du tout (patron TsFields…)."""
    # Assertion toujours exécutée : le test reste MEANINGFUL même carte vide.
    assert all(isinstance(reason, str) for reason in DECLARED_ASYMMETRIES.values())

    for family, reason in DECLARED_ASYMMETRIES.items():
        assert reason.strip() != "", (
            f"L'asymétrie déclarée `{family}` n'a pas de raison. Un écart se déclare AVEC son "
            f"pourquoi (asymétrie réelle, non corrigée, tracée pour arbitrage), ou pas du tout."
        )


def test_declared_asymmetries_are_real() -> None:
    """Une exemption doit masquer une VRAIE asymétrie, jamais un fantôme. Si la famille déclarée
    est en réalité présente des deux côtés (asymétrie corrigée depuis), l'exemption est périmée et
    doit être retirée — sans quoi elle désarmerait le registre pour une famille désormais gardée."""
    generate = _generate_hard_families()
    verdict = _verdict_hard_families()
    truly_asymmetric = generate.symmetric_difference(verdict)

    stale = sorted(set(DECLARED_ASYMMETRIES) - truly_asymmetric)
    assert not stale, (
        f"Ces asymétries déclarées ne sont plus des asymétries — la famille est posée des DEUX "
        f"côtés : {stale}. L'exemption est périmée : la retirer de DECLARED_ASYMMETRIES pour que "
        f"le registre garde à nouveau cette famille."
    )
