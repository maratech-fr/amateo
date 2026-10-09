import React from "react";
import { AbsoluteFill, Img, interpolate, useCurrentFrame } from "remotion";
import { COLORS } from "../brand";
import { captureFile } from "../media";
import { focalTransform, type Rect } from "./Still";

// Plan 8 (0:36-0:38, <= 2 s) — RESPIRATION, AUCUN texte ni sous-titre (regle 5bis/11). Zoom rapide
// sur l'icone de theme (en-tete), « clic », bascule clair -> sombre, puis on respire sur l'ecran
// sombre. La vraie bascule vient des captures P08-clair.png / P08-sombre.png (pas une maquette).
//
// Cote audio : la musique coupe NET a 0:36 et reprend NET a 0:38 (regle 7bis) ; seul son du plan =
// le clic de la bascule (donnee dans src/audio-levels.ts, SFX_CUES P08) — pose a l'etape 4.

// Icone de theme dans l'en-tete (coords composition ~ CSS du viewport 1920x1080). Lune en haut a
// droite, a gauche du menu (reperee sur P06-grille-finale/P08-clair).
const TOGGLE: Rect = { x: 1686, y: 6, w: 96, h: 44 };

export const ThemeSwitch: React.FC<{ durationInFrames: number }> = ({ durationInFrames }) => {
  const frame = useCurrentFrame();
  const d = durationInFrames;
  // Phases (fractions du plan) : zoom-in (0 -> .32), clic (.32 -> .42), bascule+dezoom (.42 -> .72), respire (.72 -> 1).
  const zoomIn = interpolate(frame, [0, d * 0.32], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const zoomOut = interpolate(frame, [d * 0.46, d * 0.74], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  // Avancement du zoom : on va vers l'icone, puis on revient au plein cadre (voir tout le sombre).
  const p = zoomIn - zoomOut * zoomIn; // monte a 1 puis redescend
  const transform = focalTransform(TOGGLE, 3.2, p);
  // Crossfade clair -> sombre pendant la bascule.
  const dark = interpolate(frame, [d * 0.44, d * 0.6], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  // Clic : anneau teal qui se contracte sur l'icone au moment du clic.
  const click = interpolate(frame, [d * 0.3, d * 0.44], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const ringR = (1 - click) * 60 + 18;
  const tcx = TOGGLE.x + TOGGLE.w / 2;
  const tcy = TOGGLE.y + TOGGLE.h / 2;
  return (
    <AbsoluteFill style={{ backgroundColor: COLORS.paper, overflow: "hidden" }}>
      <div style={{ position: "absolute", width: 1920, height: 1080, transform, transformOrigin: "0 0" }}>
        <Img src={captureFile("P08-clair.png")} style={{ position: "absolute", width: 1920, height: 1080 }} />
        <Img src={captureFile("P08-sombre.png")} style={{ position: "absolute", width: 1920, height: 1080, opacity: dark }} />
        {click > 0 && click < 1 ? (
          <svg style={{ position: "absolute", left: 0, top: 0, overflow: "visible" }} width={1920} height={1080}>
            <circle cx={tcx} cy={tcy} r={ringR} fill="none" stroke={COLORS.teal} strokeWidth={4} opacity={1 - click} />
          </svg>
        ) : null}
      </div>
    </AbsoluteFill>
  );
};
