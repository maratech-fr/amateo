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


# ════════════════════════════════════════════════════════════════════════════════════════════
# EXTENSION — parité des ARGUMENTS passés à l'agrégateur (pas seulement des familles appelées)
# ════════════════════════════════════════════════════════════════════════════════════════════
#
# L'angle mort du diff de familles : il voit une famille ABSENTE, jamais une famille PRÉSENTE des
# deux côtés mais NOURRIE DE VIDE. Si le verdict passait un jour ``shared_trainings=[]`` là où
# ``/generate`` passe ``data.get("sharedTrainings", [])``, la règle deviendrait muette, le verdict
# mentirait — et le diff de familles resterait VERT (``add_level_1_hard_constraints`` est appelée
# des deux côtés). Même classe de défaut qu'ENG-36 (le trajet) et P4-152 (le plancher de gymnase),
# trouvée deux fois en trois jours par un audit, jamais par un garde.
#
# On compare donc, argument par argument, l'EXPRESSION SOURCE qui alimente l'unique appel à
# l'agrégateur des deux côtés (``ast.unparse``). Comparer les seuls NOMS de mots-clés ne verrait
# pas ``data.get("sharedTrainings", [])`` remplacé par ``[]`` : c'est précisément le défaut visé.
#
# Transparence des alias (étroite, DOCUMENTÉE) : ``/generate`` lie ``resolved_implicit_rules =
# resolve_implicit_rules(data.get("implicitRules"))`` (main.py) puis passe la variable, tandis que
# le verdict inline la même expression. Un alias sur UNE ligne, en ``x = <expr>`` PLAIN (jamais
# annoté), est une indirection transparente : on le résout avant de comparer. On NE résout PAS les
# locales annotées ni les paramètres — ``team_coach_map`` / ``team_player_map`` / ``model`` /
# ``assignments`` sont des locales ANNOTÉES côté ``/generate`` mais des PARAMÈTRES côté verdict
# (assemblés par l'appelant) : les résoudre fabriquerait de fausses divergences. La résolution
# plain-``Assign`` seule ne touche donc que ``implicit_rules``, et le garde naît VERT.
#
# Fragilité assumée, qui échoue FORT (jamais en silence) : si un jour ``resolved_implicit_rules``
# était annoté (``resolved_implicit_rules: X = …``), la résolution cesserait et ``implicit_rules``
# ressortirait comme divergence NOMMÉE avec ses deux expressions — à inliner ou à déclarer. C'est
# une chute bruyante, pas un trou muet.


def _single_assign_aliases(func: FuncDef) -> dict[str, ast.expr]:
    """Locales liées par EXACTEMENT UN ``x = <expr>`` plain (``ast.Assign``, cible ``Name`` unique).

    Les affectations ANNOTÉES (``x: T = …``) et tout nom lié plus d'une fois sont EXCLUS : une
    valeur que le verdict reçoit en PARAMÈTRE mais que ``/generate`` reconstruit en locale annotée
    paraîtrait divergente alors que c'est le même flux. Un alias plain d'une ligne, lui, est une
    indirection transparente (``resolved_implicit_rules = resolve_implicit_rules(...)``)."""
    counts: dict[str, int] = {}
    rhs: dict[str, ast.expr] = {}
    for node in ast.walk(func):
        if isinstance(node, ast.AnnAssign):
            if isinstance(node.target, ast.Name):
                counts[node.target.id] = counts.get(node.target.id, 0) + 1
        elif isinstance(node, ast.Assign):
            for target in node.targets:
                if isinstance(target, ast.Name):
                    counts[target.id] = counts.get(target.id, 0) + 1
                    rhs[target.id] = node.value
    return {name: expr for name, expr in rhs.items() if counts.get(name) == 1}


def _resolve_top(expr: ast.expr, aliases: dict[str, ast.expr]) -> ast.expr:
    """Résout UNIQUEMENT une valeur d'argument qui est un ``Name`` nu vers son alias plain d'une
    ligne. On ne descend pas dans les sous-expressions : ``adjusted_min_by_team or None`` (un
    ``BoolOp``) reste tel quel — c'est l'exception ``min_sessions_by_team``, pas un alias."""
    if isinstance(expr, ast.Name) and expr.id in aliases:
        return aliases[expr.id]
    return expr


def _aggregator_call(func: FuncDef) -> ast.Call:
    """L'unique appel à l'agrégateur dans ``func``. Échoue FORT si 0 ou >1 (ancre glissée)."""
    calls = [
        node
        for node in ast.walk(func)
        if isinstance(node, ast.Call)
        and (
            (isinstance(node.func, ast.Name) and node.func.id == AGGREGATOR)
            or (isinstance(node.func, ast.Attribute) and node.func.attr == AGGREGATOR)
        )
    ]
    assert len(calls) == 1, (
        f"Attendu EXACTEMENT un appel à `{AGGREGATOR}` dans `{func.name}`, trouvé {len(calls)}. "
        f"L'ancre du registre d'arguments a glissé : ré-ancrer AVANT de continuer — un diff "
        f"d'arguments calculé sur le mauvais appel (ou aucun) ment."
    )
    return calls[0]


def _aggregator_arguments(func: FuncDef) -> dict[str, str]:
    """`{nom_argument: expression source unparsée}` pour l'appel à l'agrégateur dans ``func``.

    Les positionnels sont clés ``#0``, ``#1``… ; les mots-clés par leur nom. Les valeurs qui sont
    un ``Name`` nu passent par la transparence d'alias (``_resolve_top``) ; tout le reste
    (``[]``, ``data.get(...)``, ``x or None``…) est comparé VERBATIM — c'est le cœur : attraper
    ``data.get("sharedTrainings", [])`` remplacé par ``[]``.

    Échoue FORT si l'énumération est impossible : un splat ``*args`` / ``**kwargs`` masque les
    alimentations individuelles, et un « aucune divergence » silencieux serait un mensonge."""
    call = _aggregator_call(func)
    aliases = _single_assign_aliases(func)
    arguments: dict[str, str] = {}
    for index, value in enumerate(call.args):
        assert not isinstance(value, ast.Starred), (
            f"Argument positionnel `*` (splat) dans l'appel à `{AGGREGATOR}` de `{func.name}` : le "
            f"registre ne peut plus énumérer les alimentations argument par argument. Ré-ancrer / "
            f"dé-splatter avant de continuer — mieux vaut échouer fort que diffuser un faux vert."
        )
        arguments[f"#{index}"] = ast.unparse(_resolve_top(value, aliases))
    for keyword in call.keywords:
        assert keyword.arg is not None, (
            f"Argument `**` (splat) dans l'appel à `{AGGREGATOR}` de `{func.name}` : les mots-clés "
            f"individuels sont masqués. Le registre ne peut plus comparer leurs expressions source "
            f"— échouer fort plutôt que rendre un diff vide et mensonger."
        )
        arguments[keyword.arg] = ast.unparse(_resolve_top(keyword.value, aliases))
    return arguments


def _generate_aggregator_call_owner() -> FuncDef:
    """L'unique fonction de main.py qui compose l'agrégateur (aujourd'hui ``_solve``)."""
    module = _module(MAIN_PY)
    composers = [func for func in _top_level_functions(module) if _calls(func, AGGREGATOR)]
    assert len(composers) == 1, (
        f"Attendu EXACTEMENT une fonction de app/main.py composant `{AGGREGATOR}`, trouvé "
        f"{len(composers)} ({[f.name for f in composers]}). L'ancre du registre d'arguments a "
        f"glissé — ré-ancrer AVANT de continuer."
    )
    return composers[0]


def _verdict_aggregator_call_owner() -> FuncDef:
    """La fonction du verdict qui compose l'agrégateur : ``_apply_hard``."""
    module = _module(VALIDATE_PY)
    functions = _functions_named(module, VERDICT_HARD_FUNCTION)
    assert len(functions) == 1, (
        f"Attendu EXACTEMENT une fonction `{VERDICT_HARD_FUNCTION}` dans "
        f"app/solver/validate_assignments.py, trouvé {len(functions)}. Ré-ancrer le registre "
        f"d'arguments plutôt que de le laisser diffuser sur une fonction fantôme."
    )
    verdict = functions[0]
    assert _calls(verdict, AGGREGATOR), (
        f"`{VERDICT_HARD_FUNCTION}` ne compose plus `{AGGREGATOR}` : ré-ancrer avant de continuer."
    )
    return verdict


# Divergences d'ARGUMENTS déclarées : même agrégateur, même mot-clé des deux côtés, mais expression
# source différente, ACCEPTÉE avec sa raison. La raison est OBLIGATOIRE (gardée par
# test_every_declared_arg_divergence_carries_a_reason) et doit décrire une divergence RÉELLE
# (gardée par test_declared_arg_divergences_are_real) — jamais une exemption fantôme.
DECLARED_ARG_DIVERGENCES: dict[str, str] = {
    "min_sessions_by_team": (
        "Cible SOUPLE, pas un plancher dur — seule exception du registre. /generate passe "
        "`adjusted_min_by_team or None` (les minimums réels par équipe) parce que l'OBJECTIF du "
        "solveur les porte ; le verdict passe `min_by_team or None`, un dict de ZÉROS, parce qu'il "
        "n'a PAS d'objectif : c'est un test de faisabilité seul (docblock de `_apply_hard` : « la "
        "couche HARD de la génération, MOINS l'objectif et les plafonds de séances »). Lui donner "
        "ces planchers en dur rendrait le verdict PLUS STRICT que la génération elle-même. "
        "Divergence délibérée et légitime."
    ),
}


def _is_divergent(generate_expr: str | None, verdict_expr: str | None) -> bool:
    """Divergent = présent d'un seul côté, OU présent des deux avec des expressions différentes.
    Absent des deux (les deux ``None``) = pas divergent → une exception qui le déclare est périmée."""
    if (generate_expr is None) != (verdict_expr is None):
        return True
    return generate_expr != verdict_expr


def test_no_undeclared_arg_divergence_in_aggregator_call() -> None:
    """Aucun argument de l'agrégateur nourri différemment des deux côtés sans être déclaré.
    C'est le cœur de l'extension : elle voit le VIDE là où le diff de familles est aveugle."""
    generate = _aggregator_arguments(_generate_aggregator_call_owner())
    verdict = _aggregator_arguments(_verdict_aggregator_call_owner())

    offenders: list[str] = []
    for name in sorted(set(generate) | set(verdict)):
        if name in DECLARED_ARG_DIVERGENCES:
            continue
        gen_expr = generate.get(name)
        ver_expr = verdict.get(name)
        if not _is_divergent(gen_expr, ver_expr):
            continue
        if gen_expr is None:
            offenders.append(f"`{name}` : passé au verdict (`{ver_expr}`), ABSENT côté /generate")
        elif ver_expr is None:
            offenders.append(f"`{name}` : passé côté /generate (`{gen_expr}`), ABSENT du verdict")
        else:
            offenders.append(f"`{name}` : /generate le nourrit de `{gen_expr}`, le verdict de `{ver_expr}`")

    assert not offenders, (
        "Argument(s) de l'agrégateur HARD nourri(s) différemment entre /generate et le verdict, "
        "non déclaré(s) :\n  - "
        + "\n  - ".join(offenders)
        + "\n\nC'est l'angle mort d'ENG-36 / P4-152 vu au niveau de l'ARGUMENT : la famille est "
        "appelée des deux côtés, mais nourrie de VIDE d'un côté (ex. `shared_trainings=[]` au lieu "
        "de `data.get('sharedTrainings', [])`) — la règle devient muette, le verdict ment. Deux "
        "issues, jamais le silence : (a) rétablir la même expression source des deux côtés ; "
        "(b) si l'écart est un choix assumé, le déclarer dans DECLARED_ARG_DIVERGENCES AVEC sa "
        "raison (changement sur l'axe backend↔engine §7.1 → décision fondateur)."
    )


def test_every_declared_arg_divergence_carries_a_reason() -> None:
    """Une divergence d'argument se déclare AVEC son pourquoi, ou pas du tout (patron TsFields…)."""
    assert all(isinstance(reason, str) for reason in DECLARED_ARG_DIVERGENCES.values())

    for name, reason in DECLARED_ARG_DIVERGENCES.items():
        assert reason.strip() != "", (
            f"La divergence d'argument déclarée `{name}` n'a pas de raison. Un écart se déclare "
            f"AVEC son pourquoi (divergence réelle, légitime, tracée pour arbitrage), ou pas du tout."
        )


def test_declared_arg_divergences_are_real() -> None:
    """Une exemption d'argument doit masquer une VRAIE divergence, jamais un fantôme. Si l'argument
    déclaré est en réalité nourri de la même expression des deux côtés (écart résorbé depuis),
    l'exemption est périmée et désarmerait le registre pour un argument désormais gardé."""
    generate = _aggregator_arguments(_generate_aggregator_call_owner())
    verdict = _aggregator_arguments(_verdict_aggregator_call_owner())

    stale = sorted(
        name for name in DECLARED_ARG_DIVERGENCES if not _is_divergent(generate.get(name), verdict.get(name))
    )
    assert not stale, (
        f"Ces divergences d'argument déclarées n'en sont plus — l'argument est nourri de la MÊME "
        f"expression des deux côtés (ou a disparu) : {stale}. L'exemption est périmée : la retirer "
        f"de DECLARED_ARG_DIVERGENCES pour que le registre garde à nouveau cet argument."
    )
