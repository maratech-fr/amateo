import React from "react";
import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { COLORS, FONTS } from "../brand";

// Plans 1-2 (motion design, fond papier) — la « charge mentale ». ZERO interface de l'app : seuls
// l'univers graphique du logo (papier, encre, teintes des arcs) et des post-it de CONTRAINTES.
// Les libelles sont des abreviations d'exemples de la vitrine (landing/index.html:315-320) et des
// familles de contraintes de la demo — jamais une promesse inventee.
//
//  - « accumulate » (plan 1, 6 s) : les post-it se posent un par un, sur les temps, autour d'une
//    grille vide dessinee ; leger rebond ; lent zoom avant.
//  - « collapse » (plan 2, 4 s) : un post-it tombe, la grille se rature en cascade ; riser a la fin.

const PAPER = COLORS.paper;
const INK = COLORS.ink;
const LINE = COLORS.line;

// Post-it : {position en composition, rotation, teinte d'un arc, texte}. Ordre = ordre d'apparition.
const NOTES: ReadonlyArray<{ x: number; y: number; r: number; c: string; t: string }> = [
  { x: 250, y: 150, r: -6, c: COLORS.magenta, t: "Coach U13 : pas le mardi" },
  { x: 1380, y: 130, r: 5, c: COLORS.orange, t: "Gymnase fermé aux vacances" },
  { x: 130, y: 460, r: 4, c: COLORS.teal, t: "U9 : fin avant 19h" },
  { x: 1490, y: 450, r: -5, c: COLORS.magenta, t: "Séniors : pas avant 20h" },
  { x: 180, y: 690, r: 6, c: COLORS.teal, t: "EMB : fin 17h30" },
  { x: 1440, y: 700, r: -4, c: COLORS.orange, t: "Vétérans : pas avant 20h" },
];

const GRID = { x: 580, y: 300, w: 760, h: 500 };

// overshoot (ressort amorti simple) pour la pose d'un post-it — leger rebond, jamais epileptique.
const pop = (f: number, start: number): number => {
  const t = interpolate(f, [start, start + 16], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  return 1 - Math.pow(1 - t, 3) * Math.cos(t * Math.PI * 1.1);
};

const EmptyGrid: React.FC<{ scribble: number }> = ({ scribble }) => {
  const cols = 6;
  const rows = 5;
  const cw = GRID.w / cols;
  const rh = GRID.h / rows;
  const days = ["Lun", "Mar", "Mer", "Jeu", "Ven", "Sam"];
  return (
    <div
      style={{
        position: "absolute",
        left: GRID.x,
        top: GRID.y,
        width: GRID.w,
        height: GRID.h,
        transform: "rotate(-1.2deg)",
        background: "#ffffff",
        border: `2px solid ${LINE}`,
        borderRadius: 14,
        boxShadow: "0 30px 70px -40px rgba(25,23,20,0.45)",
        overflow: "hidden",
      }}
    >
      <div style={{ display: "flex", height: 44, borderBottom: `2px solid ${LINE}`, background: PAPER }}>
        {days.map((d) => (
          <div key={d} style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center", fontFamily: FONTS.body, fontSize: 20, color: "#8a857b", borderRight: `1px solid ${LINE}` }}>
            {d}
          </div>
        ))}
      </div>
      <svg width={GRID.w} height={GRID.h - 44} style={{ display: "block" }}>
        {Array.from({ length: cols - 1 }, (_, i) => (
          <line key={`c${i}`} x1={(i + 1) * cw} y1={0} x2={(i + 1) * cw} y2={GRID.h} stroke={LINE} strokeWidth={1} />
        ))}
        {Array.from({ length: rows }, (_, i) => (
          <line key={`r${i}`} x1={0} y1={i * rh} x2={GRID.w} y2={i * rh} stroke={LINE} strokeWidth={1} />
        ))}
        {/* ratures en cascade (plan 2) : des gribouillis d'encre rouge barrent les cases, revelés
            par stroke-dashoffset, decales dans le temps (scribble 0..1 = avancement global). */}
        {scribble > 0
          ? Array.from({ length: 10 }, (_, i) => {
              const gx = (i % 5) * cw + 14;
              const gy = Math.floor(i / 5) * rh * 1.6 + 30;
              const local = interpolate(scribble, [i * 0.07, i * 0.07 + 0.25], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
              const len = 260;
              return (
                <path
                  key={`s${i}`}
                  d={`M ${gx} ${gy} q 40 -22 ${cw - 28} 14 q -36 20 ${cw - 40} -8`}
                  fill="none"
                  stroke="#c62a2a"
                  strokeWidth={5}
                  strokeLinecap="round"
                  strokeDasharray={len}
                  strokeDashoffset={len * (1 - local)}
                  opacity={0.85}
                />
              );
            })
          : null}
      </svg>
    </div>
  );
};

const PostIt: React.FC<{ n: (typeof NOTES)[number]; scale: number; falling?: number }> = ({ n, scale, falling = 0 }) => {
  const fallY = falling * 520;
  const fallRot = falling * 28;
  const fallOp = 1 - falling * 0.9;
  return (
    <div
      style={{
        position: "absolute",
        left: n.x,
        top: n.y,
        width: 300,
        height: 200,
        transformOrigin: "center",
        transform: `translateY(${fallY}px) rotate(${n.r + fallRot}deg) scale(${scale})`,
        opacity: scale <= 0 ? 0 : fallOp,
        background: `color-mix(in srgb, ${n.c} 16%, #ffffff)`,
        borderTop: `7px solid ${n.c}`,
        borderRadius: 10,
        boxShadow: "0 18px 40px -20px rgba(25,23,20,0.5)",
        padding: "26px 24px",
        fontFamily: FONTS.body,
        fontSize: 32,
        fontWeight: 600,
        lineHeight: 1.25,
        color: INK,
      }}
    >
      {n.t}
    </div>
  );
};

export const MotionCharge: React.FC<{ variant: "accumulate" | "collapse"; durationInFrames: number }> = ({ variant, durationInFrames }) => {
  const frame = useCurrentFrame();
  if (variant === "accumulate") {
    // Lent zoom avant sur 6 s.
    const zoom = interpolate(frame, [0, durationInFrames], [1, 1.06], { extrapolateRight: "clamp" });
    return (
      <AbsoluteFill style={{ backgroundColor: PAPER, overflow: "hidden" }}>
        <div style={{ position: "absolute", inset: 0, transform: `scale(${zoom})`, transformOrigin: "center" }}>
          <EmptyGrid scribble={0} />
          {NOTES.map((n, i) => (
            <PostIt key={i} n={n} scale={pop(frame, 18 + i * 30)} />
          ))}
        </div>
      </AbsoluteFill>
    );
  }
  // collapse (plan 2) : grille + post-it deja poses ; un post-it tombe ; ratures en cascade ; riser.
  const scribble = interpolate(frame, [20, durationInFrames - 10], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const fall = interpolate(frame, [6, 44], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  // Riser dans les 2 dernieres secondes : leger resserrement + vignette qui monte.
  const riser = interpolate(frame, [durationInFrames - 120, durationInFrames], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
  const shake = riser > 0 ? Math.sin(frame * 1.3) * riser * 3 : 0;
  return (
    <AbsoluteFill style={{ backgroundColor: PAPER, overflow: "hidden" }}>
      <div style={{ position: "absolute", inset: 0, transform: `translateX(${shake}px) scale(${1 + riser * 0.03})`, transformOrigin: "center" }}>
        <EmptyGrid scribble={scribble} />
        {NOTES.map((n, i) => (
          <PostIt key={i} n={n} scale={1} falling={i === 0 ? fall : 0} />
        ))}
      </div>
      <AbsoluteFill style={{ background: `radial-gradient(circle at 50% 45%, transparent 55%, rgba(120,20,20,${riser * 0.22}))`, pointerEvents: "none" }} />
    </AbsoluteFill>
  );
};
