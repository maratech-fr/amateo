import type { ReactNode } from "react";

import "./system-scene.css";

/**
 * P5-22 — la SCÈNE commune des écrans système (404, 403, 500, hors-ligne pleine page). Un
 * demi-terrain vu de dessus où des cartes d'équipes flottent en apesanteur. Purement décorative :
 * SVG + CSS, ZÉRO hook applicatif — elle rend SANS aucun provider (elle sert sous l'ErrorBoundary
 * React, monté HORS providers, tout comme `SystemScreen`). Les couleurs lisent les tokens de
 * `index.css` ; l'accent du club vient de `var(--accent)`.
 *
 * ⚠ Elle porte la FORME commune à TOUS les écrans système, pas une variante par écran : aucune
 * prop, aucun `switch`. C'est le pendant visuel de `SystemScreen` (« la forme et rien d'autre »).
 * `aria-hidden` : le SENS vit dans le titre et le corps de l'écran — la scène est muette pour le
 * lecteur d'écran (comme la scène de génération).
 *
 * ⚠ « À l'arrêt veut dire à l'arrêt » : la scène flotte sans jamais se remplir ni progresser —
 * aucun balayage, aucune accumulation. C'est ce qui la rend compatible avec des écrans d'erreur.
 * Sous `prefers-reduced-motion`, les cartes restent posées, visibles, immobiles (voir
 * system-scene.css).
 */
export function SystemScene(): ReactNode {
  return (
    <div data-testid="system-scene" aria-hidden="true" className="pointer-events-none w-full max-w-md">
      <svg viewBox="0 0 320 158" className="h-auto w-full" preserveAspectRatio="xMidYMid meet">
        {/* Demi-terrain en filigrane (vu de dessus) : ligne de fond en bas, raquette + cercle de
            lancer franc, arc à 3 points, demi-cercle central au bord haut. Tracé `muted-foreground`
            à faible opacité — présent mais en retrait des cartes qui flottent au-dessus. */}
        <g fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity="0.28">
          <rect x="8" y="8" width="304" height="142" rx="8" />
          <rect x="128" y="104" width="64" height="46" />
          <circle cx="160" cy="104" r="20" />
          <path d="M60 150V120a100 100 0 0 1 200 0v30" />
          <path d="M136 8a24 24 0 0 1 48 0" />
        </g>

        {/* Ombres au sol — au repos elles restent posées (opacity de base 0.16), et pulsent en
            phase avec la flottaison des cartes (elles rétrécissent quand la carte s'élève). Rendues
            SOUS les cartes : la carte s'en détache en montant, d'où l'apesanteur. `#000` à faible
            opacité = une ombre discrète (cf. scène de génération) : nette en thème CLAIR, ténue en
            thème sombre — là, c'est le déplacement de la carte contre le terrain fixe qui porte la
            flottaison. Durées/délais DÉLIBÉRÉMENT non harmoniques (5.2 / 4.4 / 5.8 s, délais
            0 / 0.5 / 0.9 s) pour que les trois cartes ne se resynchronisent jamais en un « pouls »
            collectif qui suggérerait un système en marche. */}
        <ellipse className="ss-anim" cx="67" cy="120" rx="30" ry="5" fill="#000" opacity="0.16" style={{ transformBox: "fill-box", transformOrigin: "center", animation: "ss-shadow 5.2s ease-in-out infinite" }} />
        <ellipse className="ss-anim" cx="159" cy="112" rx="30" ry="5" fill="#000" opacity="0.16" style={{ transformBox: "fill-box", transformOrigin: "center", animation: "ss-shadow 4.4s ease-in-out 0.5s infinite" }} />
        <ellipse className="ss-anim" cx="247" cy="124" rx="28" ry="5" fill="#000" opacity="0.16" style={{ transformBox: "fill-box", transformOrigin: "center", animation: "ss-shadow 5.8s ease-in-out 0.9s infinite" }} />

        {/* Carte GAUCHE — « créneau libre · à replacer » : contour pointillé (pas de contenu),
            l'accent en filigrane. Le pointillé est STATIQUE (aucun stroke-dashoffset animé — ce
            serait un balayage). */}
        <g className="ss-anim" style={{ animation: "ss-float 5.2s ease-in-out infinite" }}>
          <g className="ss-anim" style={{ transformBox: "fill-box", transformOrigin: "bottom center", animation: "ss-squash 5.2s ease-in-out infinite" }}>
            <rect x="34" y="70" width="66" height="40" rx="8" fill="var(--card)" stroke="var(--muted-foreground)" strokeWidth="1.5" strokeDasharray="5 4" opacity="0.85" />
            <rect x="42" y="78" width="12" height="12" rx="3" fill="none" stroke="var(--accent)" strokeWidth="1.5" strokeDasharray="3 2.5" />
            <rect x="60" y="80" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity="0.4" />
            <rect x="60" y="90" width="20" height="4" rx="2" fill="var(--muted-foreground)" opacity="0.28" />
          </g>
        </g>

        {/* Carte CENTRE — carte d'équipe posée : pastille d'accent (couleur du club) + lignes de
            contenu en squelette (aucun texte littéral — ni nom produit, ni email). L'accent est
            présent (identité du club) mais VOLONTAIREMENT tempéré (opacity 0.6) : c'est un décor en
            mouvement au-dessus du geste de reprise, lui aussi en accent — la couleur pleine reste
            réservée au bouton, qui doit rester le point focal (passe de design). */}
        <g className="ss-anim" style={{ animation: "ss-float 4.4s ease-in-out 0.5s infinite" }}>
          <g className="ss-anim" style={{ transformBox: "fill-box", transformOrigin: "bottom center", animation: "ss-squash 4.4s ease-in-out 0.5s infinite" }}>
            <rect x="126" y="58" width="66" height="40" rx="8" fill="var(--card)" stroke="var(--border)" strokeWidth="1.5" />
            <rect x="134" y="66" width="12" height="12" rx="3" fill="var(--accent)" opacity="0.6" />
            <rect x="152" y="68" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity="0.8" />
            <rect x="152" y="78" width="20" height="4" rx="2" fill="var(--muted-foreground)" opacity="0.45" />
            <rect x="134" y="86" width="48" height="5" rx="2.5" fill="var(--muted)" />
          </g>
        </g>

        {/* Carte DROITE — seconde carte d'équipe posée, décalée (flottaison désynchronisée). */}
        <g className="ss-anim" style={{ animation: "ss-float 5.8s ease-in-out 0.9s infinite" }}>
          <g className="ss-anim" style={{ transformBox: "fill-box", transformOrigin: "bottom center", animation: "ss-squash 5.8s ease-in-out 0.9s infinite" }}>
            <rect x="214" y="76" width="66" height="40" rx="8" fill="var(--card)" stroke="var(--border)" strokeWidth="1.5" />
            <rect x="222" y="84" width="12" height="12" rx="3" fill="var(--accent)" opacity="0.5" />
            <rect x="240" y="86" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity="0.8" />
            <rect x="240" y="96" width="20" height="4" rx="2" fill="var(--muted-foreground)" opacity="0.45" />
            <rect x="222" y="104" width="48" height="5" rx="2.5" fill="var(--muted)" />
          </g>
        </g>
      </svg>
    </div>
  );
}
