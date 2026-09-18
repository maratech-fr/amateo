"""DOC-45 — un poids d'arbitrage produit annoncé par un doc ne peut plus mentir sur sa valeur.

`objective/weights.py:53,61` donne `"preferred": 10` / `"avoided_venue": -10` depuis le
rebalancement V10 (« le remplissage prime sur le confort », arbitrage fondateur 2026-08-15).
Or DEUX documents — `engine/docs/constraint-vocabulary.md` et
`frontend/docs/constraint-emission.md` — ont annoncé ce même couple à **+60/−60** pendant
**34 jours** (du rebalancement V10 le 2026-08-15 au correctif du 2026-09-18, lot correctif de
l'audit 0918). Ces poids sont l'ARBITRAGE PRODUIT (« un gymnase préféré vaut-il plus ou moins
qu'une séance placée ? ») : un doc qui les annonce faux fait raisonner un agent — ou un humain —
sur une hiérarchie qui n'existe pas dans le code.

Corriger la valeur ne corrige que le CAS ; ce test corrige la RÈGLE : la valeur d'un poids
« d'arbitrage produit » est lue DEPUIS le module (import, jamais un parse de la prose du code),
et tout doc qui la CITE doit citer celle du code. Même patron que `test_contract_version_doc_sync`
(D-37) : volontairement bête, on ne juge pas la prose, seulement le chiffre à côté du symbole.

Portée choisie (lue dans le code, pas devinée) : le couple gymnase préféré/évité au cœur de
DOC-45, plus les autres poids d'arbitrage que ces trois docs CITENT AVEC UNE VALEUR — espacement,
paliers de priorité S..D, bonus socle, malus passerelle, proximité au précédent — et un poids de
placement de match (`W_PROTECT_HABIT`) cité avec sa valeur. `W_COACH_MAIN` (le brief l'a nommé)
vit dans `app/solver/match_placement.py`, PAS dans `weights.py`, et n'est cité AVEC SA VALEUR que
par l'instantané d'audit daté (`specs/audit/…`, gelé par construction) et le symbole nu de la
roadmap : rien de vivant à garder, il est donc importé et NOMMÉ dans le registre (preuve que la
valeur = 60) sans ligne de citation.

RED prouvé en injectant une valeur factice dans le registre (voir le rapport de la PR).
Si un doc cite ENCORE une valeur fausse hors des citations gardées (p. ex. un exemple oublié à
« +60 »), ce test reste VERT et le résidu est REMONTÉ au docs-writer, pas corrigé ici.
"""

from __future__ import annotations

import pathlib
import re

from app.solver import match_placement as mp
from app.solver.objective import weights as w

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]

VOCAB = REPO_ROOT / "engine" / "docs" / "constraint-vocabulary.md"
EMISSION = REPO_ROOT / "frontend" / "docs" / "constraint-emission.md"
COVERAGE = REPO_ROOT / "backend" / "docs" / "constraint-coverage.md"
MODULE_MATCHS = REPO_ROOT / "specs" / "courantes" / "module-matchs.md"

# AUD-DOC-45 (bis) — un STAMP n'est pas du CONTENU. La ligne « Last verified @ … » et son
# paragraphe de continuation sont de la MÉTADONNÉE de fraîcheur, réécrite à chaque passe
# `documentation-update` (règle du dépôt). Un garde qui s'y ancre ment : le regex socle s'était
# accroché à une tournure `SOCLE_REFERENCE_TIER_WEIGHTS (S=…)` qui n'existait QUE dans le stamp,
# et la passe doc l'a fait disparaître. On retire donc le paragraphe de stamp AVANT de matcher —
# aucune citation gardée ne doit venir d'un stamp.
_STAMP_START = re.compile(r"^\*{0,2}Last verified\b")


def _body_without_stamp(doc: pathlib.Path) -> str:
    """Le CORPS du doc, paragraphe de stamp d'en-tête retiré (ligne « Last verified » + ses
    lignes de continuation, jusqu'à la ligne vide suivante)."""
    lines = doc.read_text(encoding="utf-8").splitlines()
    out: list[str] = []
    i = 0
    while i < len(lines):
        if _STAMP_START.match(lines[i]):
            # saute tout le paragraphe de stamp jusqu'à la prochaine ligne vide (exclue)
            while i < len(lines) and lines[i].strip():
                i += 1
            continue
        out.append(lines[i])
        i += 1
    return "\n".join(out)


# Le registre nommé des poids « d'arbitrage produit ». La valeur vient du CODE (import du
# module), jamais recopiée ici : c'est tout l'intérêt du garde. On compare la MAGNITUDE
# (`abs`), le signe étant écrit à part dans les docs (« +10 » / « −10 »).
PRODUCT_ARBITRATION_WEIGHTS: dict[str, int] = {
    # Cœur DOC-45 — gymnase préféré / évité.
    "preferred": w.LEVEL_2_OBJECTIVE_WEIGHTS["preferred"],
    "avoided_venue": w.LEVEL_2_OBJECTIVE_WEIGHTS["avoided_venue"],
    # Nudge d'espacement implicite.
    "spacing": w.LEVEL_2_OBJECTIVE_WEIGHTS["spacing"],
    # Paliers de priorité S..D (les extrêmes suffisent : ce sont eux que les docs citent).
    "tier_S": w.LEVEL_2_OBJECTIVE_WEIGHTS["S"],
    "tier_D": w.LEVEL_2_OBJECTIVE_WEIGHTS["D"],
    # Proximité au placement précédent (phase 1).
    "placement_proximity": w.PLACEMENT_PROXIMITY_WEIGHT,
    # Tables par tier — les docs citent le tier S (le plus haut).
    "socle_ref_S": w.SOCLE_REFERENCE_TIER_WEIGHTS["S"],
    "team_link_S": w.TEAM_LINK_TIER_WEIGHTS["S"],
    # Placement de match (hors weights.py) — protection de la fenêtre du créneau partagé.
    "W_PROTECT_HABIT": mp.W_PROTECT_HABIT,
    # Nommé par le brief DOC-45, gardé pour preuve d'import. AUCUNE ligne de citation vivante
    # (voir le module docstring) : présent ici, absent de CITATIONS — c'est un cas documenté.
    "W_COACH_MAIN": mp.W_COACH_MAIN,
}

# Symboles présents dans le registre pour preuve d'import mais SANS citation vivante à garder.
_UNCITED_BY_DESIGN = frozenset({"W_COACH_MAIN"})

# Chaque entrée : (symbole, doc, regex à UN groupe capturant la valeur citée). Le regex ancre
# sur le VOISINAGE du symbole (« bonus objectif **+NN** », « poids `−NN` », …) pour ne pas
# ferrer un nombre voisin — et pour laisser hors filet les citations relâchées type « (soft, +60) »
# d'un exemple, remontées au docs-writer plutôt que corrigées ici.
CITATIONS: tuple[tuple[str, pathlib.Path, str], ...] = (
    # preferred = 10
    ("preferred", VOCAB, r"bonus objectif \*\*\+(\d+)\*\*"),
    ("preferred", EMISSION, r"`preferredVenueId`[^\n]*?\+(\d+) soft"),
    ("preferred", COVERAGE, r"`preferredVenueId` \(PREFERRED, \+(\d+)"),
    # avoided_venue = 10 (signe « − » U+2212, tel qu'écrit dans les docs)
    ("avoided_venue", VOCAB, r"malus objectif \*\*−(\d+)\*\*"),
    ("avoided_venue", EMISSION, r"`forbiddenVenueId`[^\n]*?−(\d+) soft"),
    # spacing = 2
    ("spacing", VOCAB, r"`add_spacing_penalty`, poids `−(\d+)`"),
    ("spacing", COVERAGE, r"`spacing` \(poids −(\d+)"),
    # placement_proximity = 9
    ("placement_proximity", VOCAB, r"poids (\d+), jamais co-émise"),
    # socle_ref_S = 20 — ancré sur la citation du CORPS (« Poids par tier (`weights.py`) : `S=20 …` »),
    # jamais sur le stamp (AUD-DOC-45 bis : l'ancienne forme n'existait que dans « Last verified »).
    ("socle_ref_S", VOCAB, r"Poids par tier \(`weights\.py`\) : `S=(\d+)"),
    # team_link_S = 8
    ("team_link_S", VOCAB, r"PLUS HAUTE des deux équipes \(S (\d+)"),
    # paliers de priorité S=10000 / D=1
    ("tier_S", VOCAB, r"les poids S=(\d+)"),
    ("tier_D", VOCAB, r"· D=(\d+) sont"),
    ("tier_S", COVERAGE, r"tiers S=(\d+)…"),
    ("tier_D", COVERAGE, r"…D=(\d+), poids"),
    # W_PROTECT_HABIT = 25 (placement de match). Robuste à une refonte de prose : le regex ne
    # ferre QUE le littéral « W_PROTECT_HABIT=NN » — absent → pas d'assertion, présent → doit
    # citer la valeur du code.
    ("W_PROTECT_HABIT", MODULE_MATCHS, r"W_PROTECT_HABIT=(\d+)"),
)


def test_docs_cite_the_code_value_of_each_product_weight() -> None:
    """DOC-45 — tout doc qui cite un poids d'arbitrage produit cite la valeur du CODE.

    Volontairement bête (patron D-37) : le regex capture le chiffre voisin du symbole, on le
    compare à la magnitude importée du module. Un doc laissé sur l'ancienne valeur (+60/−60)
    rougit ici, dans le même commit que le prochain rebalancement de poids.
    """
    stale = []
    for name, doc, pattern in CITATIONS:
        expected = abs(PRODUCT_ARBITRATION_WEIGHTS[name])
        text = _body_without_stamp(doc)
        found = re.findall(pattern, text)
        assert found, (
            f"le garde DOC-45 attend une citation de `{name}` dans "
            f"{doc.relative_to(REPO_ROOT)} (regex {pattern!r}) — la prose a bougé sous le "
            "filet : recaler le regex, jamais le supprimer en silence."
        )
        for value in found:
            if int(value) != expected:
                stale.append(f"{doc.relative_to(REPO_ROOT)} cite `{name}`={value}, code={expected}")

    assert not stale, (
        "ces documents annoncent un poids d'arbitrage produit qui n'est plus celui du code : "
        f"{stale}. Un poids préféré/évité a menti à +60/−60 pendant 34 jours (DOC-45) — mettre "
        "à jour le doc dans le MÊME commit que le rebalancement du poids."
    )


def test_every_named_weight_is_guarded_or_documented_as_uncited() -> None:
    """Le registre reste vivant : un symbole ajouté SANS citation ni exemption est une faute.

    Empêche le registre de gonfler avec des poids qu'aucun doc ne garde. `W_COACH_MAIN` est
    l'exemption assumée (aucune citation vivante — voir le module docstring)."""
    cited = {name for name, _, _ in CITATIONS}
    orphans = set(PRODUCT_ARBITRATION_WEIGHTS) - cited - set(_UNCITED_BY_DESIGN)

    assert not orphans, (
        f"ces poids du registre ne sont gardés par aucune citation : {sorted(orphans)}. "
        "Soit ajouter une entrée dans CITATIONS, soit les sortir du registre, soit les "
        "documenter dans `_UNCITED_BY_DESIGN` avec la raison."
    )


def test_no_citation_is_anchored_on_a_stamp_line() -> None:
    """AUD-DOC-45 (bis) — aucune citation gardée ne doit venir d'un stamp de fraîcheur.

    Un stamp (« Last verified @ … ») est réécrit à chaque passe `documentation-update` : s'y
    ancrer, c'est garder une tournure jetable (c'est ce qui a fait rougir `socle_ref_S` quand la
    PR E a réécrit l'en-tête). On vérifie (a) que le stripper retire bien tout stamp, et (b) que
    CHAQUE regex matche encore APRÈS suppression du stamp — donc sur du vrai corps."""
    docs = {doc for _, doc, _ in CITATIONS}
    for doc in docs:
        body = _body_without_stamp(doc)
        assert "Last verified" not in body, (
            f"un stamp survit dans le corps de {doc.relative_to(REPO_ROOT)} — le stripper doit "
            "retirer la ligne « Last verified » et son paragraphe."
        )

    for name, doc, pattern in CITATIONS:
        body = _body_without_stamp(doc)
        assert re.search(pattern, body), (
            f"la citation `{name}` ({doc.relative_to(REPO_ROOT)}, regex {pattern!r}) ne matche "
            "QUE dans le stamp : la recaler sur le CORPS du document."
        )
