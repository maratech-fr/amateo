#!/usr/bin/env bash
# Genere out/noms-visibles.md : chaque nom de personne et adresse e-mail que la base de DEMO peut
# faire apparaitre a l'ecran (gestionnaire, coachs de la campagne, coach cree). Le fondateur valide
# AVANT montage (regle 2), en croisant avec out/contact-sheet.png. Lance par run.sh (arg : JWT).
set -uo pipefail
REPO=/home/marabou/projects/scheduler
OUT="$REPO/video/out"; TMP="$OUT/tmp"
API=http://localhost:8080/api
TOKEN="${1:-}"
H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/ld+json")
MD="$OUT/noms-visibles.md"

# extract_names FILE : imprime toutes les valeurs string sous une cle contenant name/email/coach.
extract_names() {
  python3 -I -c '
import sys, json
try:
    d = json.load(open(sys.argv[1], encoding="utf-8"))
except Exception:
    sys.exit(0)
seen = set()
def walk(o):
    if isinstance(o, dict):
        for k, v in o.items():
            if isinstance(v, str) and v.strip() and any(t in k.lower() for t in ("name", "email")):
                key = (k, v.strip())
                if key not in seen:
                    seen.add(key)
                    print(f"- {v.strip()}  ({k})")
            else:
                walk(v)
    elif isinstance(o, list):
        for x in o:
            walk(x)
walk(d)
' "$1" 2>/dev/null | sort -u
}

curl -sS "${H[@]}" "$API/me" > "$TMP/me.json" 2>/dev/null || true
curl -sS "${H[@]}" "$API/coaches?itemsPerPage=300" > "$TMP/coaches.json" 2>/dev/null || true

{
  echo "# Noms visibles — a valider AVANT montage (regle 2 du prompt maitre)"
  echo
  echo "> Club de **DEMONSTRATION** uniquement (« Demo Basket Club », identites FICTIVES, seed-demo)."
  echo "> Jamais le club BCCL reel. Relire CHAQUE vignette de \`out/contact-sheet.png\` et confirmer"
  echo "> qu'aucun nom REEL n'apparait. Au moindre doute : flouter au montage."
  echo
  echo "_Genere automatiquement depuis la base de demo (API) + la campagne creee par la capture._"
  echo
  echo "## Gestionnaire demo (compte de connexion)"
  echo "- Connexion : \`demo-bccl@amateo.fr\` (compte de demonstration)"
  extract_names "$TMP/me.json"
  echo
  echo "## Coach cree par la capture (pour le plan 5)"
  if [[ -f "$TMP/coach-email.txt" ]]; then
    echo "- Nom affiche : **Camille Durand** (fictif, exemple)"
    echo "- E-mail : \`$(cat "$TMP/coach-email.txt")\` (domaine d'exemple)"
  else
    echo "- (coach non cree — P05 cote coach saute)"
  fi
  echo
  echo "## Membre du bureau en lecture seule (pour le plan 10)"
  if [[ -f "$TMP/member.txt" ]]; then
    cat "$TMP/member.txt"
  else
    echo "- (compte membre non cree — P10 non capture, voir le rapport)"
  fi
  echo
  echo "## Coachs de la base demo (apparaissent dans la fenetre Doleances / page coach)"
  extract_names "$TMP/coaches.json"
  echo
  echo "## A verifier a l'oeil sur la planche contact"
  echo "- Plan 5a (e-mail) : nom de l'expediteur (gestionnaire demo) et du destinataire (coach)."
  echo "- Plan 5b (page coach) : nom du coach, equipes, bloc « Mutualiser avec »."
  echo "- Plan 5c (fenetre Doleances) : liste des coachs sollicites."
  echo "- Plan 9 (conflits) : nom du coach en conflit, s'il y en a un."
  echo "- Tous plans : l'en-tete porte le nom du club de demo, jamais un club reel."
} > "$MD"

echo "noms-visibles.md ecrit : $MD"
