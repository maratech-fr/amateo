#!/usr/bin/env bash
set -euo pipefail

# Garde-fou PreToolUse (matcher Bash) : toute boucle d'attente `while`/`until`
# doit être bornée par `timeout <durée>` avec une durée <= 4 h (14400 s).
# Contexte : deux boucles `until grep … && ! pgrep -f '<motif>'; do sleep …; done`
# ont tourné 9 h et 42 h — `pgrep -f` se trouvait lui-même, la condition ne
# devenait jamais vraie. Aucun traitement de ce projet n'a jamais dépassé 4 h.
#
# Lit le JSON du hook sur stdin (tool_input.command). Décision déléguée à
# python3 (présent dans l'environnement ; jq absent). stdin est capté par bash
# puis transmis via l'environnement — sinon le heredoc du script python
# consommerait lui-même stdin et le JSON n'arriverait jamais.
#   exit 0 -> laisse passer (pas de boucle, ou boucle bornée <= 4 h)
#   exit 2 -> bloque, message FR sur stderr

HOOK_INPUT="$(cat)"
export HOOK_INPUT

python3 - <<'PY'
import os, sys, re, json

MAX_SECONDS = 14400  # 4 h

raw = os.environ.get("HOOK_INPUT", "")
try:
    obj = json.loads(raw)
except Exception:
    # JSON illisible : on ne bloque jamais une commande faute de pouvoir l'analyser.
    sys.exit(0)

tool_input = obj.get("tool_input") or {}
cmd = tool_input.get("command") if isinstance(tool_input, dict) else None
if not isinstance(cmd, str) or not cmd:
    sys.exit(0)

# Détection d'une boucle shell `while`/`until` : mot-clé (précédé d'une frontière,
# pour éviter "meanwhile") suivi plus loin d'un `do` introduit par `;` ou un saut
# de ligne. Les `for … in … ; do` ne sont PAS concernés. Heuristique tolérante :
# un `while`/`do` piégé dans une chaîne de commit sans `; do` ne matche pas.
loop_re = re.compile(r'(?:^|[\s;&|(\x27\x22`])(?:while|until)\s.+?(?:;|\n)\s*do\b', re.DOTALL)
if not loop_re.search(cmd):
    sys.exit(0)

# Boucle présente : exiger un `timeout <durée>` avec durée <= MAX_SECONDS.
OPTS_WITH_ARG = {"-k", "--kill-after", "-s", "--signal"}
DUR_RE = re.compile(r'(\d+(?:\.\d+)?)([smhd]?)$')
MULT = {"": 1, "s": 1, "m": 60, "h": 3600, "d": 86400}


def timeout_seconds(command: str):
    """Renvoie la durée (en s) du premier `timeout` trouvé, ou None si absent/illisible."""
    toks = command.split()
    for i, tok in enumerate(toks):
        if tok.rsplit("/", 1)[-1] != "timeout":
            continue
        j = i + 1
        while j < len(toks):
            a = toks[j]
            if a.startswith("-"):
                # option ; certaines prennent une valeur en argument séparé.
                if a in OPTS_WITH_ARG and "=" not in a:
                    j += 2
                else:
                    j += 1
                continue
            m = DUR_RE.fullmatch(a)
            if not m:
                return None
            return float(m.group(1)) * MULT[m.group(2)]
        return None
    return None


secs = timeout_seconds(cmd)
if secs is not None and secs <= MAX_SECONDS:
    sys.exit(0)

sys.stderr.write(
    "Boucle non bornée refusée : préfixez par `timeout 4h` (maximum) — "
    "une attente de plus de 4 h n'a jamais existé dans ce projet. "
    "Attendez la fin d'un journal plutôt qu'un `pgrep -f` (il se trouve lui-même).\n"
)
sys.exit(2)
PY
