import React from "react";
import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { COLORS } from "../brand";

// Plan 11 (motion design, fond papier) — « confiance données France ». Decision fondateur
// 2026-10-09 : silhouette de la France au trait teal + un repere sur Paris ; AUCUN label, logo ni
// mention officielle (pas de « certifié RGPD »). Le texte vient de la couche de sous-titres.
//
// PROVENANCE DU TRACE : contour METROPOLITAIN simplifie, saisi A LA MAIN depuis des points
// geographiques PUBLICS (longitude/latitude de reperes cotiers et frontaliers connus — un contour
// geographique factuel n'est pas protegeable). Ce n'est PAS un SVG tiers recupere au hasard (regle
// du brief) ; equivalent a un derive simplifie de Natural Earth (domaine public). Projection
// equirectangulaire avec correction cos(latitude) pour des proportions justes.

// Points (lon, lat), sens horaire depuis Dunkerque — ~30 reperes cotiers/frontaliers publics.
const LONLAT: ReadonlyArray<readonly [number, number]> = [
  [2.37, 51.03], [4.17, 50.28], [5.98, 49.46], [8.23, 48.97], [7.59, 47.59],
  [6.86, 46.42], [7.04, 45.92], [6.63, 44.56], [7.52, 43.78], [6.24, 43.12],
  [5.37, 43.29], [4.05, 43.56], [3.03, 42.98], [3.04, 42.47], [1.44, 42.60],
  [-0.75, 42.96], [-1.46, 43.35], [-1.2, 44.67], [-1.03, 45.57], [-1.15, 46.15],
  [-2.16, 47.28], [-4.33, 47.8], [-4.79, 48.04], [-4.56, 48.68], [-3.1, 48.87],
  [-1.84, 48.63], [-1.61, 49.72], [0.23, 49.7], [1.63, 50.12],
];
const PARIS: readonly [number, number] = [2.35, 48.85];

// Projection + normalisation vers une boite (viewBox 0..VB) avec marge.
const VB = 1000;
const PAD = 70;
const COS = Math.cos((46.8 * Math.PI) / 180); // correction longitude a la latitude moyenne
const project = ([lon, lat]: readonly [number, number]): [number, number] => [lon * COS, -lat];
const PROJ = LONLAT.map(project);
const xs = PROJ.map((p) => p[0]);
const ys = PROJ.map((p) => p[1]);
const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
const span = Math.max(maxX - minX, maxY - minY);
const scale = (VB - 2 * PAD) / span;
const offX = PAD + ((VB - 2 * PAD) - (maxX - minX) * scale) / 2;
const offY = PAD + ((VB - 2 * PAD) - (maxY - minY) * scale) / 2;
const toVB = ([px, py]: [number, number]): [number, number] => [offX + (px - minX) * scale, offY + (py - minY) * scale];

const PTS = PROJ.map(toVB);
const PATH = PTS.map((p, i) => `${i === 0 ? "M" : "L"} ${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join(" ") + " Z";
const PARIS_VB = toVB(project(PARIS));
// Longueur approx du trace, pour le reveal au stroke-dashoffset.
const PERIM = PTS.reduce((acc, p, i) => (i === 0 ? 0 : acc + Math.hypot(p[0] - PTS[i - 1][0], p[1] - PTS[i - 1][1])), 0) + 200;

export const FranceMap: React.FC<{ durationInFrames: number }> = ({ durationInFrames }) => {
  const frame = useCurrentFrame();
  const draw = interpolate(frame, [8, durationInFrames * 0.55], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const fillOp = interpolate(frame, [durationInFrames * 0.4, durationInFrames * 0.7], [0, 0.1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const markerIn = interpolate(frame, [durationInFrames * 0.5, durationInFrames * 0.6], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const ring = (frame % 50) / 50; // pulse du repere Paris
  return (
    <AbsoluteFill style={{ backgroundColor: COLORS.paper, alignItems: "center", justifyContent: "center" }}>
      <svg width={760} height={760} viewBox={`0 0 ${VB} ${VB}`} style={{ overflow: "visible" }}>
        <path d={PATH} fill={COLORS.teal} fillOpacity={fillOp} stroke="none" />
        <path
          d={PATH}
          fill="none"
          stroke={COLORS.tealInk}
          strokeWidth={6}
          strokeLinejoin="round"
          strokeLinecap="round"
          strokeDasharray={PERIM}
          strokeDashoffset={PERIM * (1 - draw)}
        />
        {/* repere Paris : anneau pulsant + point plein teal */}
        <circle cx={PARIS_VB[0]} cy={PARIS_VB[1]} r={8 + ring * 26} fill="none" stroke={COLORS.teal} strokeWidth={3} opacity={(1 - ring) * markerIn} />
        <circle cx={PARIS_VB[0]} cy={PARIS_VB[1]} r={11 * markerIn} fill={COLORS.tealInk} />
      </svg>
    </AbsoluteFill>
  );
};
