import type { ReactNode } from "react";

import "./GenerationWaiting.css";

// Mini-grille centrale : cases (1-indexées, grille 4×4) qui se remplissent à l'accent,
// avec les positions et délais échelonnés du design (drop 6 s en boucle).
const FILLED_CELL_DELAYS: Record<number, string> = { 1: ".2s", 3: "1.1s", 6: "1.9s", 8: "3.4s", 9: "2.6s", 15: "4.2s" };

/**
 * Décor PRÉSENTATIONNEL partagé de la scène de génération (design fondateur) : le cadre, les
 * deux bandes SVG latérales, la mini-grille centrale. Le centre est apporté par `children`
 * (les textes de l'attente, ou la copie + gestes de l'écran « service injoignable »).
 *
 * ⚠ `halted` n'enfreint PAS la règle « pas de prop `variant` » de `SystemScreen` : celle-ci
 * proscrit un fourre-tout d'écrans derrière un aiguillage. Ici `GenerationScene` est UN décor à
 * DEUX ÉTATS (il tourne / il est à l'arrêt), pas un routeur d'écrans — la même scène, vivante ou
 * figée. L'état arrêté (`halted`) pose la classe `gw-halted` : les animations sont neutralisées,
 * les cases restent VIDES, le chrono est barré et le seul mouvement est le ballon posé au sol qui
 * roule doucement (`GenerationWaiting.css`).
 *
 * Une seule scène pour les deux thèmes : les couleurs lisent les tokens de `index.css`
 * (var(--background|card|muted|border|muted-foreground)) et l'accent du club via `var(--accent)`.
 * Voir `GenerationWaiting.tsx` pour l'histoire de la composition (deux bandes ancrées, centre qui
 * s'étire, cap de largeur retiré le 2026-08-18).
 */
export function GenerationScene({ halted = false, children }: { halted?: boolean; children: ReactNode }) {
  return (
    // Fond NU (P5-16) : `bg-background` couvre le fond d'écran commun derrière l'attente de
    // génération ET l'écran « moteur injoignable » — un seul point pour les deux états de la scène.
    <div className="flex justify-center bg-background py-8">
      {/* Cadre : surface distincte du fond de page (bg-card), bordure + arrondi, overflow
          masqué. Pleine largeur, hauteur BORNÉE (le décor s'ancre, il ne s'agrandit plus). */}
      <div
        data-testid="gen-scene-frame"
        className={`relative h-[22rem] w-full overflow-hidden rounded-[14px] border border-border bg-card sm:h-[26rem]${halted ? " gw-halted" : ""}`}
      >
        {/* Bande GAUCHE — purement décorative pour le lecteur d'écran : une seule image
            pour toute la scène, le sens vit dans les textes du centre. C'est elle qui
            porte le `role="img"` (la bande droite est muette : deux images feraient lire
            la scène deux fois). */}
        <svg
          data-testid="gen-band-left"
          viewBox="0 0 230 500"
          preserveAspectRatio="xMinYMid meet"
          className="absolute inset-y-0 left-0 hidden h-full w-auto max-w-[26%] sm:block"
          role="img"
          aria-label="Des créneaux se placent un à un sur la grille du planning"
        >
        {/* Terrain en filigrane. Tracé en `muted-foreground` (et non `border`, trop proche
            de la surface pour se lire) à faible opacité : un filigrane VISIBLE mais en
            retrait des créneaux qui se remplissent. Le tracé est celui du dessin ENTIER
            (il court d'un bord à l'autre) — la viewBox de la bande le cadre, plus besoin
            du clipPath qui découpait les deux fenêtres dans un svg unique. */}
        <g fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity=".4">
          <rect x="16" y="16" width="768" height="468" rx="3" />
          <path d="M16 90h124a94 94 0 0 1 0 320H16" />
          <path d="M16 160h56v180H16z" />
          <circle cx="72" cy="250" r="34" />
        </g>

        {/* Indicateur circulaire (accent du club sur le balayage + l'aiguille) — le « chrono ».
            À l'arrêt, ses éléments animés sont figés (gw-halted) et une barre le raye. */}
        <g>
          <circle cx="44" cy="52" r="14" fill="none" stroke="var(--border)" strokeWidth="3" />
          <circle
            className="gw-anim"
            cx="44"
            cy="52"
            r="14"
            fill="none"
            stroke="var(--accent)"
            strokeWidth="3"
            strokeLinecap="round"
            strokeDasharray="88"
            strokeDashoffset="26"
            transform="rotate(-90 44 52)"
            style={{ animation: "gw-sweep 8s linear infinite" }}
          />
          <path
            className="gw-anim"
            d="M44 52v-8"
            fill="none"
            stroke="var(--accent)"
            strokeWidth="2.5"
            strokeLinecap="round"
            style={{ animation: "gw-spin 4s linear infinite", transformOrigin: "44px 52px" }}
          />
          <rect x="70" y="46" width="86" height="4" rx="2" fill="var(--muted-foreground)" opacity=".28" />
          <rect x="70" y="56" width="52" height="4" rx="2" fill="var(--muted-foreground)" opacity=".16" />
          {/* Chrono BARRÉ (arrêt seulement) : le service ne compte plus. */}
          {halted ? (
            <line data-testid="gen-timer-strike" x1="30" y1="66" x2="58" y2="38" stroke="var(--muted-foreground)" strokeWidth="2.5" strokeLinecap="round" />
          ) : null}
        </g>

        {/* Grille de créneaux vides */}
        <g fill="var(--card)" stroke="var(--border)" strokeWidth="1.5">
          <rect x="28" y="98" width="92" height="82" rx="8" />
          <rect x="134" y="98" width="92" height="82" rx="8" />
          <rect x="28" y="192" width="92" height="82" rx="8" />
          <rect x="134" y="192" width="92" height="82" rx="8" />
          <rect x="28" y="286" width="92" height="82" rx="8" />
          <rect x="134" y="286" width="92" height="82" rx="8" />
          <rect x="28" y="380" width="92" height="82" rx="8" />
          <rect x="134" y="380" width="92" height="82" rx="8" />
        </g>

        {/* Créneaux gauche qui se remplissent (coche = accent club). À l'arrêt : opacity 0
            (cases VIDES) via `.gw-halted .gw-anim`. */}
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .56s infinite" }}>
          <rect x="28" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="42" y="118" width="46" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="42" y="132" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M42 156l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2s infinite" }}>
          <rect x="134" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="148" y="212" width="52" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="148" y="226" width="26" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M148 250l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3.6s infinite" }}>
          <rect x="28" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="42" y="306" width="40" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="42" y="320" width="34" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M42 344l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 5.2s infinite" }}>
          <rect x="134" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="148" y="400" width="48" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="148" y="414" width="28" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <path d="M148 438l7 7 13-14" fill="none" stroke="var(--accent)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </g>

        {/* Nuages à l'atterrissage du ballon (accent) */}
        <circle className="gw-anim gw-puffring" cx="74" cy="139" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear .56s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="180" cy="233" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 2s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="74" cy="327" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 3.6s infinite", transformBox: "fill-box", transformOrigin: "center" }} />
        <circle className="gw-anim gw-puffring" cx="180" cy="421" r="26" fill="none" stroke="var(--accent)" strokeWidth="2" opacity="0" style={{ animation: "gw-landPuff 8s linear 5.2s infinite", transformBox: "fill-box", transformOrigin: "center" }} />

        {halted ? (
          // Ballon POSÉ AU SOL qui roule doucement (arrêt). Rendu HORS `gw-anim` (donc épargné
          // par l'opacity 0 de `.gw-halted .gw-anim`) : une unique keyframe lente, ease-in-out,
          // aller-retour, qui ne va NULLE PART (il ne « remplit » rien) — « en vie mais à
          // l'arrêt », jamais « ça calcule » (passe de design P5-14). L'ombre reste au sol.
          <g transform="translate(96 452)">
            <ellipse cx="0" cy="18" rx="16" ry="4" fill="#000" opacity=".18" />
            <g data-testid="gen-rest-ball" className="gw-restball">
              <circle cx="0" cy="0" r="15" fill="var(--muted)" stroke="var(--muted-foreground)" strokeWidth="2" />
              <path d="M-15 0h30M0-15v30M-11-11A21 21 0 0 0-11 11M11-11A21 21 0 0 1 11 11" fill="none" stroke="var(--muted-foreground)" strokeWidth="1.5" />
            </g>
          </g>
        ) : (
          // Ballon qui rebondit de case en case (translations en unités utilisateur : elles
          // suivent l'échelle de la bande, quelle que soit la largeur du cadre).
          <g className="gw-anim gw-bx" style={{ animation: "gw-hopX 8s linear infinite" }}>
            <g className="gw-anim gw-by" style={{ animation: "gw-hopY 8s linear infinite" }}>
              <g className="gw-anim" style={{ animation: "gw-squash 8s linear infinite", transformBox: "fill-box", transformOrigin: "bottom center" }}>
                <g className="gw-anim" style={{ animation: "gw-ballFade 8s linear infinite" }}>
                  <ellipse cx="0" cy="3" rx="15" ry="4" fill="#000" opacity=".35" />
                  <circle cx="0" cy="-15" r="15" fill="var(--muted)" stroke="var(--muted-foreground)" strokeWidth="2" />
                  <path d="M-15-15h30M0-30v30M-11-26A21 21 0 0 0-11-4M11-26A21 21 0 0 1 11-4" fill="none" stroke="var(--muted-foreground)" strokeWidth="1.5" />
                </g>
              </g>
            </g>
          </g>
        )}
        </svg>

        {/* Bande DROITE — même dessin, cadré sur sa moitié par sa seule viewBox. Muette
            pour le lecteur d'écran (la bande gauche porte déjà l'image de la scène). */}
        <svg
          data-testid="gen-band-right"
          viewBox="570 0 230 500"
          preserveAspectRatio="xMaxYMid meet"
          className="absolute inset-y-0 right-0 hidden h-full w-auto max-w-[26%] sm:block"
          aria-hidden="true"
        >
        <g fill="none" stroke="var(--muted-foreground)" strokeWidth="2" opacity=".4">
          <rect x="16" y="16" width="768" height="468" rx="3" />
          <path d="M784 90H660a94 94 0 0 0 0 320h124" />
          <path d="M784 160h-56v180h56z" />
          <circle cx="728" cy="250" r="34" />
        </g>

        {/* Grille de créneaux vides */}
        <g fill="var(--card)" stroke="var(--border)" strokeWidth="1.5">
          <rect x="574" y="98" width="92" height="82" rx="8" />
          <rect x="680" y="98" width="92" height="82" rx="8" />
          <rect x="574" y="192" width="92" height="82" rx="8" />
          <rect x="680" y="192" width="92" height="82" rx="8" />
          <rect x="574" y="286" width="92" height="82" rx="8" />
          <rect x="680" y="286" width="92" height="82" rx="8" />
          <rect x="574" y="380" width="92" height="82" rx="8" />
          <rect x="680" y="380" width="92" height="82" rx="8" />
        </g>

        {/* Créneaux droite qui se remplissent (barre de contenu) */}
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .3s infinite" }}>
          <rect x="574" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="118" width="50" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="132" width="28" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="152" width="64" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear .75s infinite" }}>
          <rect x="680" y="98" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="118" width="42" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="132" width="32" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="152" width="56" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 1.2s infinite" }}>
          <rect x="574" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="212" width="44" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="226" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="246" width="60" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 1.65s infinite" }}>
          <rect x="680" y="192" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="212" width="52" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="226" width="24" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="246" width="48" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2.1s infinite" }}>
          <rect x="574" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="306" width="48" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="320" width="26" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="340" width="58" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 2.55s infinite" }}>
          <rect x="680" y="286" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="306" width="38" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="320" width="34" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="340" width="62" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3s infinite" }}>
          <rect x="574" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="588" y="400" width="54" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="588" y="414" width="22" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="588" y="434" width="50" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        <g className="gw-anim" style={{ animation: "gw-settle 8s linear 3.45s infinite" }}>
          <rect x="680" y="380" width="92" height="82" rx="8" fill="var(--muted)" stroke="var(--border)" strokeWidth="1.5" />
          <rect x="694" y="400" width="46" height="6" rx="3" fill="var(--muted-foreground)" opacity=".85" />
          <rect x="694" y="414" width="30" height="5" rx="2.5" fill="var(--muted-foreground)" opacity=".45" />
          <rect x="694" y="434" width="54" height="12" rx="4" fill="var(--muted-foreground)" opacity=".18" />
        </g>
        </svg>

        {/* CENTRE de la scène, entre les deux bandes : mini-grille 4×4 qui se remplit + ligne de
            balayage + le contenu apporté (`children`). Overlay HTML (pas dans le SVG) : lisible par
            lecteur d'écran, contrairement au décor `role="img"`. Ses insets réservent aux bandes leur
            26 % — le contenu ne peut donc jamais les chevaucher ; sous `sm` (bandes masquées) il prend
            toute la largeur. Le décor est `pointer-events-none` (la mini-grille), mais le CONTENU
            reste interactif (les gestes de l'écran « service injoignable »). */}
        <div className="absolute inset-y-0 inset-x-4 flex flex-col items-center justify-center gap-6 text-center sm:inset-x-[27%]">
          {/* Mini-grille animée — décorative (le sens est porté par le contenu ci-dessous). */}
          <div aria-hidden="true" data-testid="gen-minigrid" className="pointer-events-none relative" style={{ width: 200, height: 140 }}>
            <div className="absolute inset-0 grid grid-cols-4 grid-rows-4 gap-[6px]">
              {Array.from({ length: 16 }, (_, k) => (
                <span key={k} className="rounded-[5px] border border-border bg-muted" />
              ))}
            </div>
            <div className="absolute inset-0 grid grid-cols-4 grid-rows-4 gap-[6px]">
              {Array.from({ length: 16 }, (_, k) => {
                // Cases remplies à l'accent du club (positions et délais du design). À l'arrêt :
                // opacity 0 (cases VIDES) via `.gw-halted .gw-anim` — jamais une grille pleine.
                const delay = FILLED_CELL_DELAYS[k + 1];
                return void 0 !== delay ? (
                  <span
                    key={k}
                    className="gw-anim rounded-[5px]"
                    style={{
                      background: "color-mix(in oklch, var(--accent) 22%, transparent)",
                      border: "1px solid color-mix(in oklch, var(--accent) 65%, transparent)",
                      opacity: 0,
                      animation: `gw-drop 6s ease-out ${delay} infinite`,
                    }}
                  />
                ) : (
                  <span key={k} />
                );
              })}
            </div>
            <div
              className="gw-anim absolute inset-x-0 top-0 h-[2px]"
              style={{
                background: "linear-gradient(90deg, transparent, var(--accent), transparent)",
                animation: "gw-scanline 6s linear infinite",
              }}
            />
          </div>

          {children}
        </div>
      </div>
    </div>
  );
}
