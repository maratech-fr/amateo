import { useEffect, useState } from "react";

import { GenerationScene } from "./GenerationScene";

const PHRASES = [
  "Placement des équipes prioritaires…",
  "Respect des disponibilités des gymnases…",
  "Vérification des créneaux des coachs…",
  "Application de vos contraintes…",
  "Optimisation de la répartition sur la semaine…",
  "Recherche du meilleur planning possible…",
];

/**
 * Écran d'attente affiché pendant une génération (premier run et régénérations).
 *
 * Consommateur du décor présentationnel `GenerationScene` (design fondateur 2026-08-17 : la scène
 * EST le contenu, AUCUN logo de club par-dessus). Ce module n'apporte que le CENTRE : les trois
 * niveaux de texte (titre stable, phrase tournante, note d'attente). Toute la géométrie du décor
 * — cadre, deux bandes SVG ancrées, mini-grille, keyframes, `prefers-reduced-motion` — vit dans
 * `GenerationScene` / `GenerationWaiting.css`. L'écran « service injoignable » (P5-14 PR-2) réutilise
 * la MÊME scène à l'arrêt (`GenerationScene halted`) — d'où l'extraction, pas une duplication.
 */
export function GenerationWaiting() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const t = setInterval(() => setI((n) => (n + 1) % PHRASES.length), 3000);
    return () => clearInterval(t);
  }, []);
  return (
    <GenerationScene>
      {/* Titre + phrase tournante. Le texte habille sur 2-3 lignes dans le couloir laissé
          libre entre les bandes.
          ⚑ AUD-FRT-23/24 — SEUL le titre STABLE est dans la région live. La phrase tourne
          toutes les 3 s : laissée dans le live, elle faisait annoncer une nouvelle phrase
          au lecteur d'écran toutes les 3 secondes pendant TOUTE la génération (1 à 3 min
          couramment, jusqu'à 10 min de budget solveur) — un martèlement qui couvre le reste
          de la page. Ces phrases sont du décor : elles font patienter l'œil, elles
          n'apportent aucune information d'état. D'où aria-hidden. */}
      <div role="status" aria-live="polite" className="w-full max-w-md">
        <p className="text-lg font-medium text-foreground">Génération du planning…</p>
      </div>
      <p key={i} aria-hidden="true" className="gw-phrase w-full max-w-md text-sm text-muted-foreground">
        {PHRASES[i]}
      </p>
      <p className="w-full max-w-md text-xs leading-relaxed text-muted-foreground">La génération peut prendre 1 à 3 min selon la taille du club. Vous pouvez laisser cet écran ouvert.</p>
    </GenerationScene>
  );
}
