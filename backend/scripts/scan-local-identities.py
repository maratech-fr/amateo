#!/usr/bin/env python3
"""Balayage LOCAL des identités réelles du club pilote dans le dépôt suivi.

Usage (depuis la racine du dépôt) :

    python3 -I backend/scripts/scan-local-identities.py [--identities <chemin>]

Lit le fichier d'identités local gitignoré (par défaut
``backend/config/seed/bccl.identities.local.json``, forme : docblock de
``App\\Seed\\BcclSeedIdentities``), en tire les jetons — ``firstName``,
``lastName`` non vide et ``email`` du gestionnaire, des co-gestionnaires et des
coachs, 3 caractères au moins — et liste chaque emplacement du dépôt SUIVI qui
en porte un, mot entier, insensible à la casse :

    fichier:ligne<TAB>jeton

Jamais le contenu de la ligne, aucun verdict, code retour 0 — sauf si ``git grep``
lui-même échoue (pas un dépôt git, git absent) : message générique sur stderr,
sans aucune valeur ni jeton, et code retour non nul, pour que l'échec ne passe
jamais pour « rien trouvé ».
Ne voit ni les images ni l'historique git. Hors suite de tests, hors CI.

LANCÉ PAR LE FONDATEUR SEUL : un agent ne le lance JAMAIS sur le chemin
canonique — sa sortie entrerait dans une transcription, puis peut-être dans une
PR. Une preuve de fonctionnement passe par ``--identities <fichier synthétique
hors dépôt>``.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import subprocess
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
DEFAULT_IDENTITIES = "backend/config/seed/bccl.identities.local.json"
MIN_TOKEN_LENGTH = 3


def _tokens_of(person: object) -> list[str]:
    if not isinstance(person, dict):
        return []
    tokens = []
    for field in ("firstName", "lastName", "email"):
        value = person.get(field)
        if isinstance(value, str) and len(value.strip()) >= MIN_TOKEN_LENGTH:
            tokens.append(value.strip())
    return tokens


def _collect_tokens(data: object) -> list[str]:
    if not isinstance(data, dict):
        return []
    people: list[object] = [data.get("manager")]
    for key in ("additionalManagers", "coaches"):
        entries = data.get(key)
        if isinstance(entries, list):
            people.extend(entries)
    unique: dict[str, str] = {}
    for person in people:
        for token in _tokens_of(person):
            unique.setdefault(token.casefold(), token)
    return list(unique.values())


def main() -> int:
    parser = argparse.ArgumentParser(description="Liste fichier:ligne des identités locales présentes dans le dépôt suivi.")
    parser.add_argument("--identities", default=DEFAULT_IDENTITIES, help=f"fichier d'identités (défaut : {DEFAULT_IDENTITIES})")
    args = parser.parse_args()

    path = pathlib.Path(args.identities)
    if not path.is_absolute():
        path = REPO_ROOT / path
    if not path.is_file():
        print(f"Aucun fichier d'identités local ({args.identities}) : rien à balayer.")
        return 0

    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        print(f"Fichier d'identités illisible ou JSON invalide ({args.identities}) : rien balayé.", file=sys.stderr)
        return 0

    tokens = _collect_tokens(data)
    if not tokens:
        print(f"Aucun jeton d'au moins {MIN_TOKEN_LENGTH} caractères dans {args.identities} : rien à balayer.")
        return 0

    command = ["git", "-C", str(REPO_ROOT), "grep", "-z", "-o", "-I", "-n", "-i", "-w", "-F"]
    for token in tokens:
        command += ["-e", token]
    # S603 : liste d'arguments fixe, aucun shell — les jetons ne sont jamais interprétés.
    try:
        completed = subprocess.run(command, capture_output=True, check=False)  # noqa: S603
    except OSError:
        print("Balayage impossible : git introuvable.", file=sys.stderr)
        return 2
    # git grep : 0 = trouvé, 1 = rien trouvé ; tout autre code = échec du balayage. Son stderr
    # n'est jamais relayé (il pourrait citer un jeton) — message générique seulement.
    if completed.returncode not in (0, 1):
        print(f"Balayage impossible : git grep a échoué (code {completed.returncode}).", file=sys.stderr)
        return 2

    by_folded = {token.casefold(): token for token in tokens}
    seen: set[tuple[str, str, str]] = set()
    for raw in completed.stdout.decode("utf-8", errors="replace").splitlines():
        parts = raw.split("\0")
        if len(parts) < 3:
            continue
        file_name, line_number, matched = parts[0], parts[1], parts[2]
        token = by_folded.get(matched.casefold())
        if token is None:
            continue
        key = (file_name, line_number, token)
        if key in seen:
            continue
        seen.add(key)
        print(f"{file_name}:{line_number}\t{token}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
